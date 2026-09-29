#!/usr/bin/env bash
# Restore test for the newest backup (spec: required part of the backup routine — "a backup
# exists" is not "the system is recoverable"). Restores the database dump into a scratch
# database, checks it against the live schema, verifies the storage archive, and removes the
# scratch copy. Exits non-zero on any failure, so the systemd unit fails (alerting via OnFailure=).
#
# Configuration: same environment as tutora-backup.sh, plus
#   RESTORE_TEST_DB  scratch database (default tutora_restore_test); the backup account needs
#                    CREATE/DROP/INSERT on it and SELECT on DB_NAME
#   GNUPGHOME        if backups are encrypted: a keyring with the private key
set -euo pipefail
umask 077

BACKUP_DIR=${BACKUP_DIR:-/var/backups/tutora}
BACKUP_DB_CNF=${BACKUP_DB_CNF:-/etc/tutora/backup.cnf}
DB_NAME=${DB_NAME:-tutora}
RESTORE_TEST_DB=${RESTORE_TEST_DB:-tutora_restore_test}

log() { echo "tutora-restore-test: $*" >&2; }
fail() { log "FAILED: $*"; exit 1; }
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$RESTORE_TEST_DB" =~ ^[A-Za-z0-9_]+$ ]] || fail "invalid database name"
[[ "$RESTORE_TEST_DB" != "$DB_NAME" ]] || fail "RESTORE_TEST_DB must differ from DB_NAME"
sql() { mysql --defaults-extra-file="$BACKUP_DB_CNF" --batch --skip-column-names "$@"; }

db_file=$(ls -1t "$BACKUP_DIR"/tutora-db-*.sql.gz* 2>/dev/null | head -n1 || true)
[[ -n "$db_file" ]] || fail "no database backup in $BACKUP_DIR"
stamp=$(basename "$db_file" | sed -E 's/^tutora-db-([0-9TZ]+)\.sql\.gz(\.gpg)?$/\1/')
files_file=$(ls -1 "$BACKUP_DIR"/tutora-files-"$stamp".tar.gz* 2>/dev/null | head -n1 || true)
[[ -n "$files_file" ]] || fail "no storage archive for $stamp"

decode() { if [[ "$1" == *.gpg ]]; then gpg --batch --quiet --decrypt "$1"; else cat "$1"; fi; }

cleanup() { sql -e "DROP DATABASE IF EXISTS \`$RESTORE_TEST_DB\`" || true; }
trap cleanup EXIT
cleanup
sql -e "CREATE DATABASE \`$RESTORE_TEST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# the dump has no USE/CREATE DATABASE (see tutora-backup.sh); refuse one that does, then import
# into the scratch database named explicitly
# (no grep -q in pipelines: with pipefail its early exit makes the pipeline fail via SIGPIPE)
if decode "$db_file" | gunzip | grep -iE '^(USE|CREATE DATABASE) ' > /dev/null; then
  fail "dump contains USE/CREATE DATABASE; refusing to import (could target another database)"
fi
decode "$db_file" | gunzip | sql "$RESTORE_TEST_DB" || fail "import of $(basename "$db_file") failed"

live_tables=$(sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$DB_NAME' ORDER BY 1")
restored_tables=$(sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$RESTORE_TEST_DB' ORDER BY 1")
[[ -n "$restored_tables" ]] || fail "restored database has no tables"
[[ "$live_tables" == "$restored_tables" ]] || fail "restored table set differs from the live schema"
live_migrations=$(sql -e "SELECT COUNT(*) FROM \`$DB_NAME\`.schema_migrations")
restored_migrations=$(sql -e "SELECT COUNT(*) FROM \`$RESTORE_TEST_DB\`.schema_migrations")
[[ "$restored_migrations" -ge 1 && "$restored_migrations" -le "$live_migrations" ]] || fail "unexpected migration state"
for t in $restored_tables; do
  sql -e "CHECK TABLE \`$RESTORE_TEST_DB\`.\`$t\`" | grep -iE '\s(OK|status\s+OK)$' > /dev/null || fail "CHECK TABLE $t"
done
tenants=$(sql -e "SELECT COUNT(*) FROM \`$RESTORE_TEST_DB\`.tenants")

decode "$files_file" | tar -tzf - > /dev/null || fail "storage archive $(basename "$files_file") unreadable"

log "OK: $(basename "$db_file") restored into $RESTORE_TEST_DB ($(echo "$restored_tables" | wc -l) tables, $restored_migrations migrations, $tenants tenants), storage archive readable"
