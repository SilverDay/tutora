#!/usr/bin/env bash
# CI check of the backup routine (run inside the mariadb:10.11 image so client tools match the
# server). Uses the exact grants documented in deploy/README.md, then: backup + restore test
# (plain and GPG-encrypted), refusal of a dump containing USE, live database unchanged.
# Env: DB_HOST, DB_ADMIN_USER, DB_ADMIN_PASSWORD (to create the backup account), DB_NAME,
#      STORAGE_PATH (any directory to archive).
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
admin() { mysql --host="$DB_HOST" --user="$DB_ADMIN_USER" --password="$DB_ADMIN_PASSWORD" --batch --skip-column-names "$@"; }

pw=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
admin -e "DROP USER IF EXISTS 'tutora_backup'@'%';
  CREATE USER 'tutora_backup'@'%' IDENTIFIED BY '$pw';
  GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON \`$DB_NAME\`.* TO 'tutora_backup'@'%';
  GRANT ALL ON tutora_restore_test.* TO 'tutora_backup'@'%';"
printf '[client]\nuser=tutora_backup\npassword=%s\nhost=%s\n' "$pw" "$DB_HOST" > "$work/backup.cnf"
chmod 600 "$work/backup.cnf"
export BACKUP_DB_CNF="$work/backup.cnf" DB_NAME STORAGE_PATH
checksum() { admin -e "CHECKSUM TABLE \`$DB_NAME\`.tenants, \`$DB_NAME\`.sessions, \`$DB_NAME\`.schema_migrations"; }
before=$(checksum)

echo "== plain backup + restore test"
export BACKUP_DIR="$work/plain"
"$here/tutora-backup.sh"
"$here/tutora-restore-test.sh"

echo "== dump containing USE is refused"
db=$(ls -1 "$BACKUP_DIR"/tutora-db-*.sql.gz)
(echo "USE \`$DB_NAME\`;"; gunzip -c "$db") | gzip > "$db.tmp" && mv "$db.tmp" "$db"
if "$here/tutora-restore-test.sh" 2> "$work/err"; then echo "expected refusal"; exit 1; fi
grep 'refusing to import' "$work/err"

echo "== encrypted backup + restore test"
export GNUPGHOME="$work/gnupg" BACKUP_DIR="$work/enc" BACKUP_GPG_RECIPIENT=ci-backup@tutora.test
mkdir -m 700 "$GNUPGHOME"
gpg --batch --quiet --passphrase '' --quick-gen-key 'ci <ci-backup@tutora.test>' rsa3072 encrypt never
"$here/tutora-backup.sh"
if gunzip -t "$BACKUP_DIR"/tutora-db-*.gpg 2>/dev/null; then echo "backup not encrypted"; exit 1; fi
"$here/tutora-restore-test.sh"

[[ "$(checksum)" == "$before" ]] || { echo "live database changed"; exit 1; }
[[ -z "$(admin -e "SHOW DATABASES LIKE 'tutora_restore_test'")" ]] || { echo "scratch database left behind"; exit 1; }
echo "backup routine OK"
