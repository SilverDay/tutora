#!/usr/bin/env bash
# On-host check of the newest backup WITHOUT the private key (owner decision 22: the private
# key is kept off-host). Verifies that the database dump and the storage archive
#   - exist, are non-empty and match the SHA-256 manifest, and
#   - are OpenPGP messages encrypted exactly to the configured recipient's encryption key
#     (so a misconfigured or swapped keyring cannot silently produce unusable backups).
# Whether a backup can actually be restored is proven by the off-host restore test.
#
# Env: BACKUP_DIR, BACKUP_GPG_RECIPIENT, GNUPGHOME (public key only), as for tutora-backup.sh.
set -euo pipefail
umask 077
BACKUP_DIR=${BACKUP_DIR:-/var/backups/tutora}
BACKUP_GPG_RECIPIENT=${BACKUP_GPG_RECIPIENT:-}
log() { echo "tutora-backup-verify: $*" >&2; }
fail() { log "FAILED: $*"; exit 1; }
[[ -n "$BACKUP_GPG_RECIPIENT" ]] || fail "BACKUP_GPG_RECIPIENT is required"

manifest=$(ls -1 "$BACKUP_DIR"/tutora-manifest-*.sha256 2>/dev/null | sort | tail -n1 || true)
[[ -n "$manifest" ]] || fail "no backup manifest in $BACKUP_DIR"
stamp=$(basename "$manifest" | sed -E 's/^tutora-manifest-([0-9]{8}T[0-9]{6}Z)\.sha256$/\1/')
db_file="$BACKUP_DIR/tutora-db-$stamp.sql.gz.gpg"
files_file="$BACKUP_DIR/tutora-files-$stamp.tar.gz.gpg"
for f in "$db_file" "$files_file"; do
  [[ -s "$f" ]] || fail "$(basename "$f") missing or empty"
done
(cd "$BACKUP_DIR" && sha256sum --quiet --strict -c "$(basename "$manifest")") || fail "checksum mismatch for $stamp"

# key ids of the recipient's encryption-capable (sub)keys
expected=$(gpg --batch --with-colons --list-keys "$BACKUP_GPG_RECIPIENT" 2>/dev/null \
  | awk -F: '($1 == "pub" || $1 == "sub") && $12 ~ /e/ { print $5 }' | sort -u)
[[ -n "$expected" ]] || fail "no encryption key for BACKUP_GPG_RECIPIENT in the keyring"
for f in "$db_file" "$files_file"; do
  # without the secret key gpg lists the packets it can see and then stops (non-zero exit)
  packets=$(gpg --batch --list-packets "$f" 2>&1 || true)
  ids=$(echo "$packets" | sed -nE 's/^:pubkey enc packet:.* keyid ([0-9A-Fa-f]{16}).*$/\1/p' | tr 'a-f' 'A-F' | sort -u)
  [[ -n "$ids" ]] || fail "$(basename "$f") is not an OpenPGP public-key encrypted message"
  for id in $ids; do  # gpg picks one of several encryption subkeys: each id must be the recipient's
    echo "$expected" | grep -Fx "$id" > /dev/null || fail "$(basename "$f") is encrypted to key $id, not to BACKUP_GPG_RECIPIENT"
  done
done
log "OK: $stamp — checksums match, both files encrypted to the recipient key"
