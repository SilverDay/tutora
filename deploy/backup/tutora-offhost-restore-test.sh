#!/usr/bin/env bash
# Off-host backup copy + full restore test (owner decision 22: the private backup key never
# lives on the production host). Runs on a separate restore machine that has the private key,
# a scratch MariaDB and a checkout of the deployed Tutora version:
#   1. pulls new backup files from the production host (read-only rsync; the production host has
#      no credentials for this machine, so a compromised host cannot delete these copies),
#   2. deletes local copies older than OFFHOST_RETENTION_DAYS (default 14, owner decision 5),
#   3. runs tutora-restore-test.sh against the newest backup (checksums, decryption, import into
#      a scratch DB, schema compared with the backup's own migrations, CHECK TABLE, archive,
#      freshness).
#
# Env: OFFHOST_SOURCE   rsync source, e.g. tutora-backup-pull@tutora.app:  (see deploy/README.md)
#      OFFHOST_SSH      ssh command, e.g. "ssh -i /etc/tutora-restore/pull_ed25519 -o IdentitiesOnly=yes"
#      BACKUP_DIR       local copy (default /var/backups/tutora-offhost)
#      OFFHOST_RETENTION_DAYS, and the tutora-restore-test.sh settings: BACKUP_DB_CNF,
#      MIGRATIONS_DIR, GNUPGHOME (with the private key), MAX_BACKUP_AGE_HOURS
set -euo pipefail
umask 077
here=$(cd "$(dirname "$0")" && pwd)
OFFHOST_SOURCE=${OFFHOST_SOURCE:-}
OFFHOST_SSH=${OFFHOST_SSH:-ssh}
export BACKUP_DIR=${BACKUP_DIR:-/var/backups/tutora-offhost}
OFFHOST_RETENTION_DAYS=${OFFHOST_RETENTION_DAYS:-14}
log() { echo "tutora-offhost: $*" >&2; }
[[ "$OFFHOST_RETENTION_DAYS" =~ ^[0-9]+$ ]] || { log "invalid OFFHOST_RETENTION_DAYS"; exit 2; }
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

if [[ -n "$OFFHOST_SOURCE" ]]; then
  # only backup files; never --delete (retention is decided here, not by the source host)
  # (no --protect-args/-s: rrsync on the source host disables it; the paths here are fixed)
  rsync --recursive --times --ignore-existing -e "$OFFHOST_SSH" \
    --include='tutora-db-*.sql.gz.gpg' --include='tutora-files-*.tar.gz.gpg' --include='tutora-manifest-*.sha256' \
    --exclude='*' "$OFFHOST_SOURCE" "$BACKUP_DIR/"
fi

# retention by the UTC stamp in the file name (independent of copied mtimes)
cutoff=$(date -u -d "-$OFFHOST_RETENTION_DAYS days" +%Y%m%dT%H%M%SZ)
for f in "$BACKUP_DIR"/tutora-*; do
  [[ -e "$f" ]] || continue
  s=$(basename "$f" | sed -nE 's/^tutora-(db|files|manifest)-([0-9]{8}T[0-9]{6}Z)\..*$/\2/p')
  if [[ -n "$s" && "$s" < "$cutoff" ]]; then
    rm -f -- "$f"
    log "expired $(basename "$f")"
  fi
done

export RESTORE_REFERENCE=migrations REQUIRE_MANIFEST=1
exec "$here/tutora-restore-test.sh"
