#!/usr/bin/env bash
# Tutora backup: database dump + storage archive, 14-day retention (owner decision 5).
# Run by deploy/systemd/tutora-backup.timer, followed by tutora-backup-verify.sh. The private
# key is kept off-host (owner decision 22): the full restore test runs on the off-host restore
# machine (tutora-offhost-restore-test.sh), which pulls these files.
#
# Configuration (environment, e.g. /etc/tutora/backup.env):
#   BACKUP_DIR            where backups are written (default /var/backups/tutora), not web-served
#   BACKUP_DB_CNF         MySQL option file with [client] user/password/host (mode 0600);
#                         credentials never appear on a command line (visible in ps)
#   DB_NAME               database name (default tutora)
#   STORAGE_PATH          app storage (default /var/lib/tutora)
#   BACKUP_RETENTION_DAYS default 14 — purged session data can exist in backups at most this long
#   BACKUP_GPG_RECIPIENT  REQUIRED (owner decision): both files are encrypted to this public key
#                         while being written; only the public key needs to be on this host
#   GNUPGHOME             keyring containing that public key
set -euo pipefail
# BACKUP_UMASK: 077 (default) or 027 when the off-host pull account (group tutora-backup) must read
# the files — they are encrypted, so the group sees ciphertext only
BACKUP_UMASK=${BACKUP_UMASK:-077}
[[ "$BACKUP_UMASK" == 077 || "$BACKUP_UMASK" == 027 ]] || { echo "tutora-backup: BACKUP_UMASK must be 077 or 027" >&2; exit 2; }
umask "$BACKUP_UMASK"

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
# encryption is mandatory: refuse to write any backup without a usable recipient key
[[ -n "$BACKUP_GPG_RECIPIENT" ]] || { log "BACKUP_GPG_RECIPIENT is required (backups must be encrypted)"; exit 2; }
gpg --batch --list-keys "$BACKUP_GPG_RECIPIENT" > /dev/null 2>&1 \
  || { log "no public key for BACKUP_GPG_RECIPIENT in the keyring"; exit 2; }
mkdir -p "$BACKUP_DIR"
if [[ "$BACKUP_UMASK" == 027 ]]; then chmod 750 "$BACKUP_DIR"; else chmod 700 "$BACKUP_DIR"; fi

stamp=$(date -u +%Y%m%dT%H%M%SZ)
db_file="$BACKUP_DIR/tutora-db-$stamp.sql.gz.gpg"
files_file="$BACKUP_DIR/tutora-files-$stamp.tar.gz.gpg"
tmp_db="$db_file.partial"
tmp_files="$files_file.partial"
trap 'rm -f "$tmp_db" "$tmp_files"' EXIT
[[ ! -e "$BACKUP_DIR/tutora-manifest-$stamp.sha256" ]] || { log "backup $stamp already exists"; exit 2; }

# encryption is streamed: no plaintext copy ever reaches the disk
seal() {
  gpg --batch --yes --trust-model always --recipient "$BACKUP_GPG_RECIPIENT" --encrypt
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
# integrity manifest (checked by tutora-backup-verify.sh here and by the off-host restore test)
(cd "$BACKUP_DIR" && sha256sum "$(basename "$db_file")" "$(basename "$files_file")") > "$BACKUP_DIR/tutora-manifest-$stamp.sha256"
log "wrote $(basename "$db_file"), $(basename "$files_file") and the manifest"

# retention: delete backups older than BACKUP_RETENTION_DAYS days
find "$BACKUP_DIR" -maxdepth 1 -type f -name 'tutora-*' -mmin +$((BACKUP_RETENTION_DAYS * 24 * 60)) -print -delete \
  | sed 's|.*/|tutora-backup: expired |' >&2
