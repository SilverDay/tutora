#!/usr/bin/env bash
# Tutora production installer/updater for Ubuntu 24.04 LTS — automates deploy/README.md.
#
#   sudo deploy/install.sh /root/tutora-install.conf          # from a checkout of the version to deploy
#
# Idempotent: safe to re-run for updates. Every run builds a new release in
# /var/www/tutora-releases/<UTC>-<commit>, runs migrations, then switches the /var/www/tutora
# symlink and restarts the services (previous 3 releases are kept for rollback). Secrets are
# generated on the first run and never regenerated.
#
# What stays manual (the script prints these at the end): DNS, firewall, alerting (OnFailure=),
# the off-host restore machine (deploy/install-offhost.sh), and the backup/pull keys if not
# configured yet.
set -euo pipefail
umask 022
SRC=$(cd "$(dirname "$0")/.." && pwd)
# shellcheck source=deploy/lib.sh
. "$SRC/deploy/lib.sh"

CONF_FILE=${1:-}
[[ -n "$CONF_FILE" ]] || die "usage: $0 <install.conf>   (see deploy/install.conf.example)"
require_root
require_ubuntu_2404
read_conf "$CONF_FILE"
for k in DOMAIN SMTP_HOST SMTP_USERNAME SMTP_PASSWORD SMTP_FROM; do need_conf "$k"; done
DOMAIN=$(conf DOMAIN)
[[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "DOMAIN must be a lower-case host name"
TLS_MODE=$(conf TLS_MODE letsencrypt)
[[ "$TLS_MODE" == letsencrypt || "$TLS_MODE" == self-signed ]] || die "TLS_MODE must be letsencrypt or self-signed"
[[ "$TLS_MODE" == self-signed ]] || need_conf LETSENCRYPT_EMAIL
[[ "$(conf SMTP_PORT 587)" == 587 ]] || warn "SMTP_PORT is not 587 (owner decision: submission with STARTTLS on 587)"

APP_ROOT=/var/www/tutora                 # symlink to the current release
RELEASES=/var/www/tutora-releases
DATA=/var/lib/tutora
ETC=/etc/tutora
APP_ENV_FILE=$ETC/tutora.env             # the app's .env (release/.env links here)
BUILD_CACHE=/var/cache/tutora-build

# --------------------------------------------------------------------------------------------
log "Packages"
apt_install ca-certificates curl unzip xz-utils rsync gnupg openssl git \
  apache2 php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-curl php8.3-xml composer \
  mariadb-server mariadb-client \
  podman uidmap slirp4netns fuse-overlayfs \
  certbot
php8.3 -m | grep -qx sodium || die "PHP sodium extension missing"
install_go
install_node

# --------------------------------------------------------------------------------------------
log "Users and groups"
ensure_group tutora        # data in /var/lib/tutora
ensure_group tutora-app    # may read the app secrets (tutora.env)
ensure_user tutora /nonexistent /usr/sbin/nologin
ensure_user tutora-converter /var/lib/tutora-converter /usr/sbin/nologin yes
ensure_user tutora-relay /nonexistent /usr/sbin/nologin
ensure_user tutora-whiteboard /nonexistent /usr/sbin/nologin
ensure_user tutora-backup /var/lib/tutora-backup /usr/sbin/nologin yes
for u in www-data tutora tutora-converter; do add_to_group "$u" tutora-app; done
for u in www-data tutora-converter tutora-whiteboard tutora-backup; do add_to_group "$u" tutora; done
chmod 0750 /var/lib/tutora-converter /var/lib/tutora-backup

log "Directories"
install -d -m 2770 -o root -g tutora "$DATA"
install -d -m 2770 -o tutora-whiteboard -g tutora "$DATA/whiteboard"
# 0711: service users can reach the file meant for them (each file has its own mode/group),
# but nobody except root can list the directory
install -d -m 0711 -o root -g root "$ETC"
install -d -m 0755 /var/www/letsencrypt "$RELEASES"
install -d -m 0700 "$BUILD_CACHE"

# --------------------------------------------------------------------------------------------
log "Configuration and secrets ($APP_ENV_FILE)"
if [[ ! -f "$APP_ENV_FILE" ]]; then
  install -m 0640 -o root -g tutora-app "$SRC/.env.example" "$APP_ENV_FILE"
fi
chown root:tutora-app "$APP_ENV_FILE"; chmod 0640 "$APP_ENV_FILE"
E=$APP_ENV_FILE
for k in DB_PASSWORD RELAY_TOKEN_KEY WHITEBOARD_TOKEN_KEY PARTICIPANT_CREDENTIAL_KEY TOTP_ENCRYPTION_KEY \
         RELAY_INTERNAL_SECRET WHITEBOARD_INTERNAL_SECRET; do
  [[ "$(env_get "$E" "$k")" == change-me ]] && env_set "$E" "$k" ""
  env_secret "$E" "$k"
done
env_set "$E" APP_ENV production
env_set "$E" APP_BASE_URL "https://$DOMAIN"
env_set "$E" ALLOWED_ORIGINS "https://$DOMAIN"
env_set "$E" DB_HOST localhost
env_set "$E" DB_PORT 3306
env_set "$E" DB_NAME tutora
env_set "$E" DB_USER tutora
env_set "$E" RELAY_INTERNAL_URL http://127.0.0.1:8081
env_set "$E" WHITEBOARD_INTERNAL_URL http://127.0.0.1:8082
env_set "$E" MAIL_DRIVER smtp
for k in SMTP_HOST SMTP_USERNAME SMTP_PASSWORD SMTP_FROM; do env_set "$E" "$k" "$(conf "$k")"; done
env_set "$E" SMTP_PORT "$(conf SMTP_PORT 587)"
env_set "$E" SMTP_FROM_NAME "$(conf SMTP_FROM_NAME Tutora)"
env_set "$E" STORAGE_PATH "$DATA"
env_set "$E" CONVERTER_RUNTIME podman
env_set "$E" CONVERTER_IMAGE tutora-converter:latest
env_set "$E" HIBP_FAIL_OPEN false
env_set "$E" AI_PROVIDER "$(env_get "$E" AI_PROVIDER)"   # stays disabled unless set by the owner
for k in RETENTION_DAYS_DEFAULT SESSION_MAX_LIVE_HOURS UPLOAD_MAX_BYTES; do
  [[ -z "$(conf "$k")" ]] || env_set "$E" "$k" "$(conf "$k")"
done

# per-service secret files: each service gets only what it needs
write_env() { # write_env FILE GROUP KEY=VALUE...
  local f=$1 g=$2; shift 2
  install -m 0640 -o root -g "$g" /dev/null "$f.new"
  printf '%s\n' "$@" > "$f.new"; mv "$f.new" "$f"
}
write_env "$ETC/relay.env" tutora-relay \
  "RELAY_TOKEN_KEY=$(env_get "$E" RELAY_TOKEN_KEY)" "RELAY_INTERNAL_SECRET=$(env_get "$E" RELAY_INTERNAL_SECRET)" \
  "ALLOWED_ORIGINS=https://$DOMAIN"
write_env "$ETC/whiteboard.env" tutora-whiteboard \
  "WHITEBOARD_TOKEN_KEY=$(env_get "$E" WHITEBOARD_TOKEN_KEY)" "WHITEBOARD_INTERNAL_SECRET=$(env_get "$E" WHITEBOARD_INTERNAL_SECRET)" \
  "ALLOWED_ORIGINS=https://$DOMAIN" "STORAGE_PATH=$DATA"

# --------------------------------------------------------------------------------------------
log "Database"
systemctl enable --now mariadb > /dev/null
db_pw=$(sql_quote "$(env_get "$E" DB_PASSWORD)")
sql_root <<SQL
DELETE FROM mysql.global_priv WHERE User = '';
DROP DATABASE IF EXISTS test;
CREATE DATABASE IF NOT EXISTS tutora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'tutora'@'localhost' IDENTIFIED BY '$db_pw';
ALTER USER 'tutora'@'localhost' IDENTIFIED BY '$db_pw';
GRANT ALL PRIVILEGES ON tutora.* TO 'tutora'@'localhost';
FLUSH PRIVILEGES;
SQL
grep -rqs '^bind-address\s*=\s*127.0.0.1' /etc/mysql/mariadb.conf.d/ || warn "MariaDB bind-address is not 127.0.0.1 — check /etc/mysql/mariadb.conf.d/50-server.cnf"

# --------------------------------------------------------------------------------------------
REV=$(git -C "$SRC" rev-parse --short=12 HEAD 2>/dev/null || echo unknown)
[[ -z "$(git -C "$SRC" status --porcelain 2>/dev/null)" ]] || { REV="$REV-dirty"; warn "deploying a checkout with uncommitted changes ($REV)"; }
RELEASE="$RELEASES/$(date -u +%Y%m%dT%H%M%SZ)-$REV"
log "Building release $RELEASE"
rsync -a --delete \
  --exclude .git --exclude .github --exclude e2e --exclude node_modules --exclude vendor \
  --exclude 'app/var' --exclude 'app/tests' --exclude 'whiteboard/test' --exclude 'whiteboard/client' \
  --exclude .env "$SRC/" "$RELEASE/"
chown -R root:root "$RELEASE"
chmod -R u=rwX,go=rX "$RELEASE"
chmod 0755 "$RELEASE"/app/bin/*.php "$RELEASE"/deploy/*.sh "$RELEASE"/deploy/backup/*.sh
ln -sfn "$APP_ENV_FILE" "$RELEASE/.env"
echo "$REV" > "$RELEASE/REVISION"

info "PHP dependencies (composer, no dev)"
(cd "$RELEASE/app" && COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME="$BUILD_CACHE/composer" \
  composer install --no-dev --no-interaction --no-progress --no-plugins --no-scripts --classmap-authoritative -q)
info "Whiteboard dependencies (npm ci, production only)"
(cd "$RELEASE/whiteboard" && PATH="$NODE_BIN:$PATH" npm_config_cache="$BUILD_CACHE/npm" \
  npm ci --omit=dev --ignore-scripts --no-audit --no-fund --no-update-notifier --loglevel=error)
info "Relay (go build; modules verified against go.sum and sum.golang.org)"
(cd "$RELEASE/relay" && GOTOOLCHAIN=local GOFLAGS=-mod=readonly CGO_ENABLED=0 \
  GOPATH="$BUILD_CACHE/go" GOCACHE="$BUILD_CACHE/go-build" \
  "$GO" build -trimpath -ldflags=-buildid= -o "$BUILD_CACHE/tutora-relay" ./cmd/relay)
install -m 0755 "$BUILD_CACHE/tutora-relay" /usr/local/bin/tutora-relay.new

log "Migrations"
(cd "$RELEASE/app" && runuser -u tutora -- php8.3 bin/migrate.php)

# --------------------------------------------------------------------------------------------
log "Rootless Podman for the converter (owner decision 7)"
CONV_UID=$(id -u tutora-converter)
if ! grep -q '^tutora-converter:' /etc/subuid; then
  start=$(awk -F: 'BEGIN{m=200000} {e=$2+$3; if (e>m) m=e} END{print m}' /etc/subuid /etc/subgid)
  usermod --add-subuids "$start-$((start + 65535))" --add-subgids "$start-$((start + 65535))" tutora-converter
fi
# cgroup v2 controllers for the sandbox limits (--cpus needs "cpu"; memory/pids are default)
install -d /etc/systemd/system/user@.service.d
printf '[Service]\nDelegate=cpu cpuset io memory pids\n' > /etc/systemd/system/user@.service.d/tutora-delegate.conf
systemctl daemon-reload
loginctl enable-linger tutora-converter
systemctl restart "user@$CONV_UID.service"
for _ in $(seq 30); do [[ -S "/run/user/$CONV_UID/bus" ]] && break; sleep 1; done
[[ -S "/run/user/$CONV_UID/bus" ]] || die "user session for tutora-converter did not start (/run/user/$CONV_UID/bus)"
install -m 0640 -o root -g tutora-converter /dev/null "$ETC/converter.env"
echo "XDG_RUNTIME_DIR=/run/user/$CONV_UID" > "$ETC/converter.env"
# rootless Podman is run inside the service user's own systemd session (proper cgroup scope and
# pause-process lifetime); a pause.pid left by an interrupted earlier run is removed first
as_converter() {
  systemd-run --machine=tutora-converter@.host --user --quiet --wait --pipe --collect \
    -p WorkingDirectory=/var/lib/tutora-converter "$@"
}
pause_pid=/run/user/$CONV_UID/libpod/tmp/pause.pid
if [[ -f "$pause_pid" ]] && ! kill -0 "$(cat "$pause_pid")" 2> /dev/null; then rm -f "$pause_pid"; fi
info "building the converter image as tutora-converter"
as_converter podman build --quiet -t tutora-converter:latest "$RELEASE/converter" > /dev/null

# --------------------------------------------------------------------------------------------
log "PHP-FPM and Apache"
cat > /etc/php/8.3/fpm/conf.d/90-tutora.ini <<INI
; Tutora (deploy/install.sh)
expose_php = Off
upload_max_filesize = 60M
post_max_size = 64M
memory_limit = 256M
INI
a2enmod -q ssl proxy proxy_http proxy_wstunnel proxy_fcgi setenvif headers rewrite > /dev/null
a2enconf -q php8.3-fpm > /dev/null
a2dissite -q 000-default > /dev/null 2>&1 || true
cat > /etc/apache2/conf-available/tutora-hardening.conf <<'CONF'
ServerTokens Prod
ServerSignature Off
TraceEnable Off
CONF
a2enconf -q tutora-hardening > /dev/null

render_vhost() { # render_vhost CERT KEY
  sed -e "s|@DOMAIN@|$DOMAIN|g" -e "s|@TLS_CERT@|$1|g" -e "s|@TLS_KEY@|$2|g" -e "s|@RELEASE_ROOT@|$APP_ROOT|g" \
    "$SRC/deploy/apache/tutora-vhost.conf" > /etc/apache2/sites-available/tutora.conf
}
if [[ "$TLS_MODE" == letsencrypt ]]; then
  CERT=/etc/letsencrypt/live/$DOMAIN/fullchain.pem KEY=/etc/letsencrypt/live/$DOMAIN/privkey.pem
  if [[ ! -f "$CERT" ]]; then
    info "requesting a Let's Encrypt certificate (HTTP-01 via /var/www/letsencrypt)"
    # bootstrap: port 80 only, so Apache can start before a certificate exists
    sed -n '1,/^<\/VirtualHost>/p' "$SRC/deploy/apache/tutora-vhost.conf" | sed -e "s|@DOMAIN@|$DOMAIN|g" \
      > /etc/apache2/sites-available/tutora.conf
    a2ensite -q tutora > /dev/null && apache2ctl configtest 2> /dev/null && systemctl reload-or-restart apache2
    certbot certonly --webroot -w /var/www/letsencrypt -d "$DOMAIN" -m "$(conf LETSENCRYPT_EMAIL)" \
      --agree-tos --non-interactive --keep-until-expiring || die "certbot failed (DNS for $DOMAIN must point here, port 80 reachable)"
  fi
  install -d /etc/letsencrypt/renewal-hooks/deploy
  printf '#!/bin/sh\nsystemctl reload apache2\n' > /etc/letsencrypt/renewal-hooks/deploy/tutora-reload-apache
  chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/tutora-reload-apache
else
  CERT=$ETC/tls/cert.pem KEY=$ETC/tls/key.pem
  install -d -m 0750 "$ETC/tls"
  if [[ ! -f "$CERT" ]] || ! openssl x509 -checkend 86400 -noout -in "$CERT" > /dev/null; then
    openssl req -x509 -newkey rsa:3072 -nodes -days 30 -subj "/CN=$DOMAIN" -addext "subjectAltName=DNS:$DOMAIN" \
      -keyout "$KEY" -out "$CERT" 2> /dev/null
    chmod 0600 "$KEY"
  fi
  warn "TLS_MODE=self-signed: 30-day self-signed certificate — staging/test only"
fi
render_vhost "$CERT" "$KEY"
a2ensite -q tutora > /dev/null

# --------------------------------------------------------------------------------------------
log "Switching to the new release and (re)starting services"
ln -sfn "$RELEASE" "$APP_ROOT.new" && mv -T "$APP_ROOT.new" "$APP_ROOT"
mv -f /usr/local/bin/tutora-relay.new /usr/local/bin/tutora-relay
install -m 0644 "$SRC"/deploy/systemd/tutora-*.service "$SRC"/deploy/systemd/tutora-*.timer /etc/systemd/system/
systemctl daemon-reload
apache2ctl configtest 2> /dev/null || die "apache2ctl configtest failed"
systemctl enable -q php8.3-fpm apache2 tutora-relay tutora-whiteboard tutora-converter tutora-purge.timer
systemctl restart php8.3-fpm tutora-relay tutora-whiteboard tutora-converter
systemctl reload-or-restart apache2
systemctl start tutora-purge.timer
# keep the current and the 3 previous releases
ls -1d "$RELEASES"/*/ 2> /dev/null | sort | head -n -4 | xargs -r rm -rf

# --------------------------------------------------------------------------------------------
log "Backups (owner decisions 5, 19, 22)"
install -d -m 0750 -o tutora-backup -g tutora-backup /var/backups/tutora
install -d -m 0700 -o tutora-backup -g tutora-backup /var/lib/tutora-backup/gnupg
bk_pw_file=$ETC/backup.cnf
if [[ ! -f "$bk_pw_file" ]]; then
  install -m 0600 -o tutora-backup -g tutora-backup /dev/null "$bk_pw_file"
  printf '[client]\nuser=tutora_backup\npassword=%s\nhost=localhost\n' "$(rand_hex 24)" > "$bk_pw_file"
fi
chown tutora-backup:tutora-backup "$bk_pw_file"; chmod 0600 "$bk_pw_file"
bk_pw=$(sql_quote "$(sed -n 's/^password=//p' "$bk_pw_file")")
sql_root <<SQL
CREATE USER IF NOT EXISTS 'tutora_backup'@'localhost' IDENTIFIED BY '$bk_pw';
ALTER USER 'tutora_backup'@'localhost' IDENTIFIED BY '$bk_pw';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON tutora.* TO 'tutora_backup'@'localhost';
FLUSH PRIVILEGES;
SQL
as_backup() { runuser -u tutora-backup -- env GNUPGHOME=/var/lib/tutora-backup/gnupg "$@"; }
key_file=$(conf BACKUP_PUBLIC_KEY)
if [[ -n "$key_file" && -r "$key_file" ]]; then
  # decision 22: the production host must never hold the private key
  if gpg --batch --with-colons --import-options show-only --import "$key_file" 2> /dev/null | grep -q '^sec'; then
    die "$key_file contains a PRIVATE key — only the public key may be on the production host"
  fi
  fpr=$(gpg --batch --with-colons --import-options show-only --import "$key_file" 2> /dev/null | awk -F: '$1=="fpr"{print $10; exit}')
  [[ "$fpr" =~ ^[0-9A-F]{40}$ ]] || die "no OpenPGP public key in $key_file"
  gpg --batch --with-colons --import-options show-only --import "$key_file" 2> /dev/null \
    | awk -F: '($1=="pub"||$1=="sub") && $12 ~ /[eE]/' | grep -q . || die "key $fpr cannot encrypt"
  as_backup gpg --batch --quiet --import < "$key_file"
  install -m 0640 -o root -g tutora-backup /dev/null "$ETC/backup.env"
  printf '%s\n' "BACKUP_DB_CNF=$bk_pw_file" "DB_NAME=tutora" "STORAGE_PATH=$DATA" "BACKUP_RETENTION_DAYS=14" \
    "BACKUP_GPG_RECIPIENT=$fpr" "GNUPGHOME=/var/lib/tutora-backup/gnupg" "BACKUP_UMASK=027" > "$ETC/backup.env"
  systemctl enable -q --now tutora-backup.timer
  info "backup timer enabled, encrypting to $fpr"
else
  systemctl disable -q --now tutora-backup.timer 2> /dev/null || true
  warn "no BACKUP_PUBLIC_KEY: backups are NOT running. Run deploy/install-offhost.sh on the restore machine, then set BACKUP_PUBLIC_KEY and re-run."
fi

pull_key=$(conf PULL_SSH_PUBLIC_KEY)
if [[ -n "$pull_key" ]]; then
  echo "$pull_key" | ssh-keygen -l -f - > /dev/null 2>&1 || die "PULL_SSH_PUBLIC_KEY is not a valid SSH public key"
  [[ "$pull_key" == ssh-ed25519\ * ]] || die "PULL_SSH_PUBLIC_KEY must be an ssh-ed25519 key"
  ensure_user tutora-backup-pull /var/lib/tutora-backup-pull /bin/sh yes
  add_to_group tutora-backup-pull tutora-backup
  install -d -m 0700 -o tutora-backup-pull -g tutora-backup-pull /var/lib/tutora-backup-pull/.ssh
  install -m 0600 -o tutora-backup-pull -g tutora-backup-pull /dev/null /var/lib/tutora-backup-pull/.ssh/authorized_keys
  echo "command=\"/usr/bin/rrsync -ro /var/backups/tutora\",restrict $pull_key" > /var/lib/tutora-backup-pull/.ssh/authorized_keys
  info "pull account tutora-backup-pull: read-only rsync of /var/backups/tutora"
else
  warn "no PULL_SSH_PUBLIC_KEY: the off-host restore machine cannot pull backups yet"
fi

# --------------------------------------------------------------------------------------------
log "Checks"
fail=0
for s in mariadb php8.3-fpm apache2 tutora-relay tutora-whiteboard tutora-converter; do
  systemctl is-active -q "$s" && info "$s active" || { warn "$s is not active (journalctl -u $s)"; fail=1; }
done
sleep 2
lcurl() { curl --noproxy '*' "$@"; }   # local checks never go through a proxy
lcurl -fsS -o /dev/null http://127.0.0.1:8081/internal/health && info "relay internal health OK" || { warn "relay health check failed"; fail=1; }
lcurl -fsS -o /dev/null http://127.0.0.1:8082/internal/health && info "whiteboard internal health OK" || { warn "whiteboard health check failed"; fail=1; }
code=$(lcurl -ks -o /dev/null -w '%{http_code}' --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/login" || true)
[[ "$code" == 200 ]] && info "https://$DOMAIN/login -> 200" || { warn "https://$DOMAIN/login returned $code"; fail=1; }
code=$(lcurl -ks -o /dev/null -w '%{http_code}' --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/internal/health" || true)
[[ "$code" == 403 || "$code" == 404 ]] && info "/internal not reachable from outside ($code)" || { warn "/internal answered $code"; fail=1; }

# the sandbox's --cpus/--memory/--pids-limit need these controllers in the user session (cgroup v2)
if [[ "$(stat -fc %T /sys/fs/cgroup)" == cgroup2fs ]]; then
  ctl=/sys/fs/cgroup/user.slice/user-$CONV_UID.slice/user@$CONV_UID.service/cgroup.controllers
  for c in cpu memory pids; do
    grep -qw "$c" "$ctl" 2> /dev/null && info "cgroup controller $c delegated to tutora-converter" \
      || { warn "cgroup controller $c NOT delegated to tutora-converter ($ctl) — sandbox limits would not apply"; fail=1; }
  done
else
  warn "cgroup v1 host: rootless Podman cannot enforce the sandbox's CPU/memory/pids limits (Ubuntu 24.04 default is cgroup v2)"; fail=1
fi

info "sandboxed test conversion as tutora-converter (rootless Podman, full sandbox profile)"
job=$(mktemp -d /var/lib/tutora-converter/selftest.XXXXXX)
chown tutora-converter:tutora-converter "$job"
cp "$SRC/app/tests/fixtures/sample.pdf" "$job/in.pdf" 2> /dev/null || warn "no sample.pdf in the checkout; conversion self-test skipped"
if [[ -f "$job/in.pdf" ]]; then
  chown tutora-converter:tutora-converter "$job/in.pdf"
  if as_converter php8.3 -r '
      require "'"$APP_ROOT"'/app/vendor/autoload.php";
      $j = $argv[1]; mkdir("$j/in", 0755); mkdir("$j/out", 0700);
      rename("$j/in.pdf", "$j/in/source.pdf"); chmod("$j/in/source.pdf", 0644);
      $r = (new Tutora\Slides\DockerConverterRunner("tutora-converter:latest", "podman"))->run("$j/in", "$j/out", 120, 50);
      $pages = glob("$j/out/page-*.png") ?: [];
      fwrite(STDERR, "    exit {$r->exitCode}, " . count($pages) . " page(s)\n");
      exit($r->exitCode === 0 && count($pages) > 0 ? 0 : 1);' "$job"; then
    info "conversion self-test OK"
  else
    warn "conversion self-test FAILED — rootless Podman/sandbox not working (see deploy/README.md)"; fail=1
  fi
fi
rm -rf "$job"

printf '\n\033[1mTutora %s deployed to %s (https://%s)\033[0m\n' "$REV" "$RELEASE" "$DOMAIN" >&2
warn "manual: firewall (allow 22, 80, 443 only), alerting via OnFailure= drop-ins for tutora-backup and tutora-converter, test mail delivery (sign up once)"
print_warnings
exit "$fail"
