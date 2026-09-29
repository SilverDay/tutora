#!/usr/bin/env bash
# CI check of the backup routine (run inside the mariadb:10.11 image so client tools match the
# server), with the key split of owner decision 22: the "production" keyring holds only the
# public key, the "off-host" keyring the private key. Uses the grants from deploy/README.md.
# Env: DB_HOST, DB_ADMIN_USER, DB_ADMIN_PASSWORD, DB_NAME, STORAGE_PATH, MIGRATIONS_DIR.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
admin() { mysql --host="$DB_HOST" --user="$DB_ADMIN_USER" --password="$DB_ADMIN_PASSWORD" --batch --skip-column-names "$@"; }
expect_fail() { # expect_fail <message fragment> <command...>
  local want=$1; shift
  if "$@" 2> "$work/err"; then echo "expected failure: $want"; exit 1; fi
  grep -F "$want" "$work/err" || { echo "wrong failure, wanted '$want':"; cat "$work/err"; exit 1; }
}

# production-side DB account (documented grants) and off-host scratch account
pw=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
admin -e "DROP USER IF EXISTS 'tutora_backup'@'%';
  CREATE USER 'tutora_backup'@'%' IDENTIFIED BY '$pw';
  GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON \`$DB_NAME\`.* TO 'tutora_backup'@'%';
  DROP USER IF EXISTS 'tutora_restore'@'%';
  CREATE USER 'tutora_restore'@'%' IDENTIFIED BY '$pw';
  GRANT ALL ON tutora_restore_test.* TO 'tutora_restore'@'%';
  GRANT ALL ON tutora_restore_ref.* TO 'tutora_restore'@'%';"
printf '[client]\nuser=tutora_backup\npassword=%s\nhost=%s\n' "$pw" "$DB_HOST" > "$work/backup.cnf"
printf '[client]\nuser=tutora_restore\npassword=%s\nhost=%s\n' "$pw" "$DB_HOST" > "$work/restore.cnf"
chmod 600 "$work"/*.cnf
checksum() { admin -e "CHECKSUM TABLE \`$DB_NAME\`.tenants, \`$DB_NAME\`.sessions, \`$DB_NAME\`.schema_migrations"; }
before=$(checksum)

# keys: generated "off-host", only the public key goes to the production keyring
mkdir -m 700 "$work/offhost-gpg" "$work/prod-gpg" "$work/other-gpg"
GNUPGHOME="$work/offhost-gpg" gpg --batch --quiet --passphrase '' --quick-gen-key 'backup <backup@tutora.test>' rsa3072 encrypt never
GNUPGHOME="$work/offhost-gpg" gpg --batch --armor --export backup@tutora.test > "$work/backup-public.asc"
GNUPGHOME="$work/prod-gpg" gpg --batch --quiet --import "$work/backup-public.asc"
[[ -z "$(GNUPGHOME="$work/prod-gpg" gpg --batch --list-secret-keys 2>/dev/null)" ]] || { echo "secret key on production"; exit 1; }

prod() { env GNUPGHOME="$work/prod-gpg" BACKUP_DIR="$work/prod" BACKUP_DB_CNF="$work/backup.cnf" \
  BACKUP_GPG_RECIPIENT=backup@tutora.test DB_NAME="$DB_NAME" STORAGE_PATH="$STORAGE_PATH" "$@"; }
offhost() { env GNUPGHOME="$work/offhost-gpg" BACKUP_DIR="$work/offhost" BACKUP_DB_CNF="$work/restore.cnf" \
  OFFHOST_SOURCE="$work/prod/" MIGRATIONS_DIR="$MIGRATIONS_DIR" "$@"; }

echo "== production: unencrypted backups are refused"
expect_fail 'BACKUP_GPG_RECIPIENT is required' prod env BACKUP_GPG_RECIPIENT= "$here/tutora-backup.sh"

echo "== production: backup + verify with the public key only"
prod "$here/tutora-backup.sh"
prod "$here/tutora-backup-verify.sh"
if gunzip -t "$work"/prod/tutora-db-*.gpg 2>/dev/null; then echo "backup not encrypted"; exit 1; fi
expect_fail 'decryption failed' env GNUPGHOME="$work/prod-gpg" gpg --batch --decrypt "$(ls "$work"/prod/tutora-db-*.gpg)"

echo "== production: modes (default 0700/0600; BACKUP_UMASK=027 for the pull account: 0750/0640)"
[[ "$(stat -c %a "$work/prod")" == 700 && "$(stat -c %a "$(ls "$work"/prod/tutora-db-*)")" == 600 ]] || { echo "bad default modes"; exit 1; }
prod env BACKUP_DIR="$work/prod-group" BACKUP_UMASK=027 "$here/tutora-backup.sh"
[[ "$(stat -c %a "$work/prod-group")" == 750 && "$(stat -c %a "$(ls "$work"/prod-group/tutora-db-*)")" == 640 ]] || { echo "bad group modes"; exit 1; }
expect_fail 'BACKUP_UMASK must be' prod env BACKUP_UMASK=022 "$here/tutora-backup.sh"

echo "== off-host: pull + full restore test (schema compared with the backup's migrations)"
offhost "$here/tutora-offhost-restore-test.sh"
pulled=("$work"/offhost/tutora-*)
[[ ${#pulled[@]} -eq 3 ]] || { echo "expected 3 pulled files, got ${#pulled[@]}"; exit 1; }

echo "== verify detects tampering and a wrong recipient key"
stamp=$(ls "$work/prod" | sed -nE 's/^tutora-manifest-(.*)\.sha256$/\1/p')
cp -a "$work/prod" "$work/prod.orig"
printf 'x' >> "$work/prod/tutora-files-$stamp.tar.gz.gpg"
expect_fail 'checksum mismatch' prod "$here/tutora-backup-verify.sh"
rm -rf "$work/prod" && cp -a "$work/prod.orig" "$work/prod"
GNUPGHOME="$work/other-gpg" gpg --batch --quiet --passphrase '' --quick-gen-key 'other <other@tutora.test>' rsa3072 encrypt never
GNUPGHOME="$work/other-gpg" gpg --batch --yes --trust-model always -r other@tutora.test --encrypt \
  < /dev/null > "$work/prod/tutora-db-$stamp.sql.gz.gpg"
(cd "$work/prod" && sha256sum "tutora-db-$stamp.sql.gz.gpg" "tutora-files-$stamp.tar.gz.gpg" > "tutora-manifest-$stamp.sha256")
expect_fail 'not to BACKUP_GPG_RECIPIENT' prod "$here/tutora-backup-verify.sh"
rm -rf "$work/prod" && cp -a "$work/prod.orig" "$work/prod"

restore() { offhost env RESTORE_REFERENCE=migrations "$here/tutora-restore-test.sh"; }
reencrypt() { GNUPGHOME="$work/offhost-gpg" gpg --batch --yes --trust-model always -r backup@tutora.test --encrypt; }
reseal() { # reseal <file>: refresh the off-host manifest after a deliberate modification
  (cd "$work/offhost" && sha256sum "tutora-db-$stamp.sql.gz.gpg" "tutora-files-$stamp.tar.gz.gpg" > "tutora-manifest-$stamp.sha256")
}
db="$work/offhost/tutora-db-$stamp.sql.gz.gpg"
cp "$db" "$work/db.orig"
plain() { GNUPGHOME="$work/offhost-gpg" gpg --batch --quiet --decrypt "$work/db.orig" | gunzip; }

echo "== off-host restore test detects tampering, USE, schema drift and a stale backup"
printf 'x' >> "$db"
expect_fail 'checksum mismatch' restore
(echo "USE \`$DB_NAME\`;"; plain) | gzip | reencrypt > "$db"; reseal
expect_fail 'refusing to import' restore
plain | sed -E 's/`display_name` varchar\(40\)/`display_name` varchar(41)/' | gzip | reencrypt > "$db"; reseal
expect_fail 'restored schema differs' restore
cp "$work/db.orig" "$db"; reseal
restore
old=$(date -u -d '-2 days' +%Y%m%dT%H%M%SZ)
mkdir "$work/stale"
for kind in db files; do
  f=$(ls "$work/offhost"/tutora-$kind-"$stamp".*)
  cp "$f" "$work/stale/$(basename "$f" | sed "s/$stamp/$old/")"
done
(cd "$work/stale" && sha256sum tutora-db-"$old".* tutora-files-"$old".* > "tutora-manifest-$old.sha256")
expect_fail 'h old (max 26 h)' offhost env BACKUP_DIR="$work/stale" RESTORE_REFERENCE=migrations "$here/tutora-restore-test.sh"

echo "== off-host retention (by UTC stamp)"
touch "$work/offhost/tutora-db-20000101T000000Z.sql.gz.gpg"
offhost env OFFHOST_SOURCE= "$here/tutora-offhost-restore-test.sh" 2> "$work/err"
grep -F 'expired tutora-db-20000101T000000Z' "$work/err"

[[ "$(checksum)" == "$before" ]] || { echo "live database changed"; exit 1; }
[[ -z "$(admin -e "SHOW DATABASES LIKE 'tutora_restore%'")" ]] || { echo "scratch database left behind"; exit 1; }
echo "backup routine OK"
