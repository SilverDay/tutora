#!/usr/bin/env bash
# Restore test for the newest backup (spec: required part of the backup routine — "a backup
# exists" is not "the system is recoverable"). Restores the database dump into a scratch
# database, compares its schema with a reference, runs CHECK TABLE on every table, verifies
# the storage archive, and removes the scratch copies. Exits non-zero on any failure.
#
# Owner decision 22: the private backup key is kept OFF-HOST, so this full test runs on the
# off-host restore machine (see tutora-offhost-restore-test.sh); the production host only runs
# tutora-backup-verify.sh.
#
# Configuration (environment):
#   BACKUP_DIR           directory with tutora-{db,files,manifest}-<UTC>.* files
#   BACKUP_DB_CNF        MySQL option file of an account allowed to create/drop the scratch DBs
#   RESTORE_TEST_DB      scratch database (default tutora_restore_test)
#   RESTORE_REFERENCE    what the restored schema is compared with:
#                          migrations (default) — a reference DB built from MIGRATIONS_DIR with
#                            exactly the migrations recorded in the backup (tables and columns)
#                          live — the live database DB_NAME (tables; for a restore test on the
#                            production host itself)
#   MIGRATIONS_DIR       app/migrations of the deployed version (reference = migrations)
#   RESTORE_REF_DB       reference scratch database (default tutora_restore_ref)
#   DB_NAME              live database (reference = live)
#   MAX_BACKUP_AGE_HOURS fail if the newest backup is older (default 26: daily backups)
#   REQUIRE_MANIFEST     1 (default): the SHA-256 manifest must exist and match
#   GNUPGHOME            keyring with the private key (encrypted backups)
set -euo pipefail
umask 077

BACKUP_DIR=${BACKUP_DIR:-/var/backups/tutora}
BACKUP_DB_CNF=${BACKUP_DB_CNF:-/etc/tutora/backup.cnf}
RESTORE_TEST_DB=${RESTORE_TEST_DB:-tutora_restore_test}
RESTORE_REF_DB=${RESTORE_REF_DB:-tutora_restore_ref}
RESTORE_REFERENCE=${RESTORE_REFERENCE:-migrations}
MIGRATIONS_DIR=${MIGRATIONS_DIR:-}
DB_NAME=${DB_NAME:-tutora}
MAX_BACKUP_AGE_HOURS=${MAX_BACKUP_AGE_HOURS:-26}
REQUIRE_MANIFEST=${REQUIRE_MANIFEST:-1}

log() { echo "tutora-restore-test: $*" >&2; }
fail() { log "FAILED: $*"; exit 1; }
for n in "$RESTORE_TEST_DB" "$RESTORE_REF_DB" "$DB_NAME"; do
  [[ "$n" =~ ^[A-Za-z0-9_]+$ ]] || fail "invalid database name"
done
[[ "$RESTORE_TEST_DB" != "$DB_NAME" && "$RESTORE_REF_DB" != "$DB_NAME" && "$RESTORE_TEST_DB" != "$RESTORE_REF_DB" ]] \
  || fail "scratch databases must differ from each other and from DB_NAME"
[[ "$MAX_BACKUP_AGE_HOURS" =~ ^[0-9]+$ ]] || fail "invalid MAX_BACKUP_AGE_HOURS"
case "$RESTORE_REFERENCE" in
  migrations) [[ -d "$MIGRATIONS_DIR" ]] || fail "MIGRATIONS_DIR is required for RESTORE_REFERENCE=migrations" ;;
  live) ;;
  *) fail "RESTORE_REFERENCE must be migrations or live" ;;
esac
sql() { mysql --defaults-extra-file="$BACKUP_DB_CNF" --batch --skip-column-names "$@"; }

# newest backup by its UTC stamp (not mtime, which copies may not preserve)
db_file=$(ls -1 "$BACKUP_DIR"/tutora-db-*.sql.gz* 2>/dev/null | sort | tail -n1 || true)
[[ -n "$db_file" ]] || fail "no database backup in $BACKUP_DIR"
stamp=$(basename "$db_file" | sed -E 's/^tutora-db-([0-9]{8}T[0-9]{6}Z)\.sql\.gz(\.gpg)?$/\1/')
[[ "$stamp" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || fail "unexpected backup file name $(basename "$db_file")"
files_file=$(ls -1 "$BACKUP_DIR"/tutora-files-"$stamp".tar.gz* 2>/dev/null | head -n1 || true)
[[ -n "$files_file" ]] || fail "no storage archive for $stamp"

# freshness: detects a backup routine that silently stopped
taken=$(date -u -d "$(echo "$stamp" | sed -E 's/^(....)(..)(..)T(..)(..)(..)Z$/\1-\2-\3 \4:\5:\6/')" +%s)
age_h=$(( ($(date -u +%s) - taken) / 3600 ))
(( age_h <= MAX_BACKUP_AGE_HOURS )) || fail "newest backup $stamp is ${age_h} h old (max ${MAX_BACKUP_AGE_HOURS} h)"

# integrity: the manifest written by tutora-backup.sh on the production host
manifest="$BACKUP_DIR/tutora-manifest-$stamp.sha256"
if [[ -f "$manifest" ]]; then
  (cd "$BACKUP_DIR" && sha256sum --quiet --strict -c "$(basename "$manifest")") || fail "checksum mismatch for $stamp"
elif [[ "$REQUIRE_MANIFEST" == 1 ]]; then
  fail "no manifest for $stamp"
fi

decode() { if [[ "$1" == *.gpg ]]; then gpg --batch --quiet --decrypt "$1"; else cat "$1"; fi; }

cleanup() { sql -e "DROP DATABASE IF EXISTS \`$RESTORE_TEST_DB\`; DROP DATABASE IF EXISTS \`$RESTORE_REF_DB\`" || true; }
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

restored_tables=$(sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$RESTORE_TEST_DB' ORDER BY 1")
[[ -n "$restored_tables" ]] || fail "restored database has no tables"
migrations=$(sql -e "SELECT name FROM \`$RESTORE_TEST_DB\`.schema_migrations ORDER BY name")
[[ -n "$migrations" ]] || fail "restored database records no migrations"

columns() { # table.column type nullability, for comparing schemas
  sql -e "SELECT CONCAT(table_name, '.', column_name, ' ', column_type, ' ', is_nullable)
          FROM information_schema.columns WHERE table_schema = '$1' ORDER BY table_name, ordinal_position"
}
if [[ "$RESTORE_REFERENCE" == migrations ]]; then
  # reference = exactly the migrations this backup recorded, applied to an empty database
  sql -e "CREATE DATABASE \`$RESTORE_REF_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  sql "$RESTORE_REF_DB" -e "CREATE TABLE schema_migrations (name VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at DATETIME(3) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
  for m in $migrations; do
    [[ "$m" =~ ^[0-9]{4}_[A-Za-z0-9_]+\.sql$ && -f "$MIGRATIONS_DIR/$m" ]] || fail "migration $m of the backup is not in MIGRATIONS_DIR"
    sql "$RESTORE_REF_DB" < "$MIGRATIONS_DIR/$m" || fail "reference migration $m failed"
  done
  [[ "$(columns "$RESTORE_TEST_DB")" == "$(columns "$RESTORE_REF_DB")" ]] \
    || fail "restored schema differs from the schema its migrations define"
  reference="migrations ($(echo "$migrations" | wc -l) applied)"
else
  live_tables=$(sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$DB_NAME' ORDER BY 1")
  [[ "$live_tables" == "$restored_tables" ]] || fail "restored table set differs from the live schema"
  reference="live schema"
fi

for t in $restored_tables; do
  sql -e "CHECK TABLE \`$RESTORE_TEST_DB\`.\`$t\`" | grep -iE '\s(OK|status\s+OK)$' > /dev/null || fail "CHECK TABLE $t"
done
tenants=$(sql -e "SELECT COUNT(*) FROM \`$RESTORE_TEST_DB\`.tenants")

decode "$files_file" | tar -tzf - > /dev/null || fail "storage archive $(basename "$files_file") unreadable"

log "OK: $stamp (${age_h} h old) restored into $RESTORE_TEST_DB, matches $reference; $(echo "$restored_tables" | wc -l) tables, $tenants tenants; storage archive readable"
