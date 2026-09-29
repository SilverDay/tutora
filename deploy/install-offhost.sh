#!/usr/bin/env bash
# Off-host backup restore machine for Tutora (owner decision 22) on Ubuntu 24.04 LTS —
# automates "Off-host restore machine" and "Key pair" in deploy/README.md.
#
#   sudo deploy/install-offhost.sh /root/tutora-offhost.conf   # from a checkout of the DEPLOYED version
#
# Run it BEFORE the production installer: it generates the backup key pair (private key stays
# here) and the SSH key of the pull account, and prints the two values production needs
# (BACKUP_PUBLIC_KEY file and PULL_SSH_PUBLIC_KEY). Idempotent; re-run after each production
# update so /opt/tutora (the schema reference) matches the deployed version.
set -euo pipefail
umask 022
SRC=$(cd "$(dirname "$0")/.." && pwd)
# shellcheck source=deploy/lib.sh
. "$SRC/deploy/lib.sh"

CONF_FILE=${1:-}
[[ -n "$CONF_FILE" ]] || die "usage: $0 <offhost.conf>   (see deploy/install-offhost.conf.example)"
require_root
require_ubuntu_2404
read_conf "$CONF_FILE"
need_conf BACKUP_KEY_EMAIL
KEY_EMAIL=$(conf BACKUP_KEY_EMAIL)
PROD_HOST=$(conf PRODUCTION_HOST)
PROD_HOST_KEY=$(conf PRODUCTION_SSH_HOST_KEY)
HOME_DIR=/var/lib/tutora-restore
GNUPG=$HOME_DIR/gnupg
ETC=/etc/tutora-restore

log "Packages"
apt_install ca-certificates rsync gnupg openssh-client mariadb-server mariadb-client

log "User and directories"
ensure_user tutora-restore "$HOME_DIR" /usr/sbin/nologin yes
chmod 0750 "$HOME_DIR"
install -d -m 0700 -o tutora-restore -g tutora-restore "$GNUPG" /var/backups/tutora-offhost
install -d -m 0750 -o root -g tutora-restore "$ETC"

log "Schema reference and scripts (/opt/tutora = this checkout)"
install -d -m 0755 /opt/tutora
rsync -a --delete --include='/app/' --include='/app/migrations/***' --include='/deploy/***' --exclude='*' "$SRC/" /opt/tutora/
chown -R root:root /opt/tutora && chmod -R u=rwX,go=rX /opt/tutora && chmod 0755 /opt/tutora/deploy/backup/*.sh
git -C "$SRC" rev-parse --short=12 HEAD > /opt/tutora/REVISION 2> /dev/null || echo unknown > /opt/tutora/REVISION

as_restore() { runuser -u tutora-restore -- env GNUPGHOME="$GNUPG" "$@"; }

log "Backup key pair (private key stays on this machine)"
if ! as_restore gpg --batch --list-secret-keys "$KEY_EMAIL" > /dev/null 2>&1; then
  # no passphrase: the daily restore test runs unattended; protect this machine accordingly
  as_restore gpg --batch --quiet --passphrase '' --quick-gen-key "Tutora backups <$KEY_EMAIL>" rsa4096 encrypt never
  warn "NEW backup key generated: export an OFFLINE copy of the private key now, e.g. 'sudo -u tutora-restore GNUPGHOME=$GNUPG gpg --armor --export-secret-keys $KEY_EMAIL' to paper/hardware — without it no backup can ever be restored"
fi
FPR=$(as_restore gpg --batch --with-colons --list-secret-keys "$KEY_EMAIL" | awk -F: '$1=="fpr"{print $10; exit}')
as_restore gpg --batch --armor --export "$FPR" > "$HOME_DIR/backup-public.asc"
chmod 0644 "$HOME_DIR/backup-public.asc"

log "SSH key of the pull account"
if [[ ! -f "$HOME_DIR/pull_ed25519" ]]; then
  as_restore ssh-keygen -q -t ed25519 -N '' -C "tutora-restore@$(hostname -f 2>/dev/null || hostname)" -f "$HOME_DIR/pull_ed25519"
fi
PULL_PUB=$(cat "$HOME_DIR/pull_ed25519.pub")

log "Scratch database account"
systemctl enable --now mariadb > /dev/null
if [[ ! -f "$ETC/restore.cnf" ]]; then
  install -m 0640 -o root -g tutora-restore /dev/null "$ETC/restore.cnf"
  printf '[client]\nuser=tutora_restore\npassword=%s\nhost=localhost\n' "$(rand_hex 24)" > "$ETC/restore.cnf"
fi
rpw=$(sql_quote "$(sed -n 's/^password=//p' "$ETC/restore.cnf")")
sql_root <<SQL
CREATE USER IF NOT EXISTS 'tutora_restore'@'localhost' IDENTIFIED BY '$rpw';
ALTER USER 'tutora_restore'@'localhost' IDENTIFIED BY '$rpw';
GRANT ALL ON tutora_restore_test.* TO 'tutora_restore'@'localhost';
GRANT ALL ON tutora_restore_ref.* TO 'tutora_restore'@'localhost';
FLUSH PRIVILEGES;
SQL

log "Pull configuration"
install -m 0644 "$SRC"/deploy/offhost/tutora-offhost-restore.service "$SRC"/deploy/offhost/tutora-offhost-restore.timer /etc/systemd/system/
systemctl daemon-reload
if [[ -n "$PROD_HOST" && -n "$PROD_HOST_KEY" ]]; then
  # pinned production host key (no trust on first use)
  read -r kt kb _ <<< "$PROD_HOST_KEY"
  [[ "$kt" == ssh-* && -n "$kb" ]] || die "PRODUCTION_SSH_HOST_KEY must be the content of production's /etc/ssh/ssh_host_ed25519_key.pub"
  install -m 0644 -o tutora-restore -g tutora-restore /dev/null "$HOME_DIR/known_hosts"
  echo "$PROD_HOST $kt $kb" > "$HOME_DIR/known_hosts"
  install -m 0640 -o root -g tutora-restore /dev/null "$ETC/restore.env"
  printf '%s\n' "OFFHOST_SOURCE=tutora-backup-pull@$PROD_HOST:" \
    "OFFHOST_SSH=ssh -i $HOME_DIR/pull_ed25519 -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$HOME_DIR/known_hosts -o BatchMode=yes" \
    "BACKUP_DIR=/var/backups/tutora-offhost" "BACKUP_DB_CNF=$ETC/restore.cnf" \
    "MIGRATIONS_DIR=/opt/tutora/app/migrations" "GNUPGHOME=$GNUPG" > "$ETC/restore.env"
  systemctl enable -q --now tutora-offhost-restore.timer
  info "daily pull + restore test enabled (tutora-offhost-restore.timer)"
else
  systemctl disable -q --now tutora-offhost-restore.timer 2> /dev/null || true
  warn "PRODUCTION_HOST / PRODUCTION_SSH_HOST_KEY not set: pull + restore test not enabled yet (set both after the production install, then re-run)"
fi

printf '\n\033[1mValues for the production install.conf:\033[0m\n' >&2
printf '  BACKUP_PUBLIC_KEY=<copy of %s>   (fingerprint %s)\n' "$HOME_DIR/backup-public.asc" "$FPR" >&2
printf '  PULL_SSH_PUBLIC_KEY=%s\n' "$PULL_PUB" >&2
printf '\nAfter the first production backup: sudo systemctl start tutora-offhost-restore && journalctl -u tutora-offhost-restore\n' >&2
print_warnings
