#!/usr/bin/env bash
# Tutora backup: database dump + storage archive, 14-day retention (owner decision 5).
# Run by deploy/systemd/tutora-backup.timer, followed by tutora-restore-test.sh (spec: a
# restore test is a required part of the backup routine).
#
# Configuration (environment, e.g. /etc/tutora/backup.env):
#   BACKUP_DIR            where backups are written (default /var/backups/tutora), not web-served
#   BACKUP_DB_CNF         MySQL option file with [client] user/password/host (mode 0600);
#                         credentials never appear on a command line (visible in ps)
#   DB_NAME               database name (default tutora)
#   STORAGE_PATH          app storage (default /var/lib/tutora)
#   BACKUP_RETENTION_DAYS default 14 — purged session data can exist in backups at most this long
#   BACKUP_GPG_RECIPIENT  optional: encrypt both files to this public key (recommended for any
#                         copy that leaves the host)
set -euo pipefail
umask 077

BACKUP_DIR=${BACKUP_DIR:-/var/backups/tutora}
BACKUP_DB_CNF=${BACKUP_DB_CNF:-/etc/tutora/backup.cnf}
DB_NAME=${DB_NAME:-tutora}
STORAGE_PATH=${STORAGE_PATH:-/var/lib/tutora}
BACKUP_RETENTION_DAYS=${BACKUP_RETENTION_DAYS:-14}
BACKUP_GPG_RECIPIENT=${BACKUP_GPG_RECIPIENT:-}

log() { echo "tutora-backup: $*" >&2; }
[[ -r "$BACKUP_DB_CNF" ]] || { log "option file $BACKUP_DB_CNF not readable"; exit 2; }
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || { log "invalid DB_NAME"; exit 2; }
[[ "$BACKUP_RETENTION_DAYS" =~ ^[0-9]+$ ]] || { log "invalid BACKUP_RETENTION_DAYS"; exit 2; }
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

stamp=$(date -u +%Y%m%dT%H%M%SZ)
suffix=""
[[ -n "$BACKUP_GPG_RECIPIENT" ]] && suffix=".gpg"
db_file="$BACKUP_DIR/tutora-db-$stamp.sql.gz$suffix"
files_file="$BACKUP_DIR/tutora-files-$stamp.tar.gz$suffix"
tmp_db="$db_file.partial"
tmp_files="$files_file.partial"
trap 'rm -f "$tmp_db" "$tmp_files"' EXIT

# encryption is streamed: with a recipient set, no plaintext copy ever reaches the disk
seal() {
  if [[ -n "$BACKUP_GPG_RECIPIENT" ]]; then
    gpg --batch --yes --trust-model always --recipient "$BACKUP_GPG_RECIPIENT" --encrypt
  else
    cat
  fi
}

# consistent InnoDB snapshot without locking the app. Deliberately without --databases: the
# dump contains no CREATE DATABASE / USE, so a restore always goes to the database named on
# the command line (the restore test can never touch the live database by accident).
mysqldump --defaults-extra-file="$BACKUP_DB_CNF" --single-transaction --quick --routines --triggers \
  --hex-blob --default-character-set=utf8mb4 "$DB_NAME" | gzip -9 | seal > "$tmp_db"

# storage: converted slides, snapshots, whiteboard documents; not transient staging/outbox
tar -C "$STORAGE_PATH" --exclude=./staging --exclude=./mail-outbox -czf - . | seal > "$tmp_files"

mv "$tmp_db" "$db_file"
mv "$tmp_files" "$files_file"
log "wrote $(basename "$db_file") and $(basename "$files_file")"

# retention: delete backups older than BACKUP_RETENTION_DAYS days
find "$BACKUP_DIR" -maxdepth 1 -type f -name 'tutora-*' -mmin +$((BACKUP_RETENTION_DAYS * 24 * 60)) -print -delete \
  | sed 's|.*/|tutora-backup: expired |' >&2
