# Deployment and operations

## Automated installation (Ubuntu 24.04 LTS)

Two scripts automate everything below; both are idempotent (re-run them to update) and take a
root-only `KEY=VALUE` configuration file that is parsed, never executed.

1. **Off-host restore machine first** — generates the backup key pair (private key stays there)
   and the SSH key of its pull account:
   ```sh
   install -m 0600 deploy/install-offhost.conf.example /root/tutora-offhost.conf   # edit
   sudo deploy/install-offhost.sh /root/tutora-offhost.conf
   ```
   It prints the two values production needs. **Export an offline copy of the private key**
   (command printed) — without it no backup can be restored.
2. **Production host** — from a checkout of the version to deploy:
   ```sh
   install -m 0600 deploy/install.conf.example /root/tutora-install.conf   # DOMAIN, SMTP, keys
   sudo deploy/install.sh /root/tutora-install.conf
   ```
   Installs packages, Go and Node (checksum-pinned official downloads, see `deploy/lib.sh`), users
   and groups, secrets (generated once, never rotated by the script), MariaDB database and accounts,
   a new release in `/var/www/tutora-releases/<UTC>-<commit>` (composer/npm/go build, migrations,
   atomic switch of the `/var/www/tutora` symlink, last 4 releases kept), rootless Podman and the
   converter image, PHP-FPM, Apache with a Let's Encrypt certificate (certbot, auto-renewal),
   all systemd units and timers, backups and the read-only pull account. It ends with checks:
   services active, internal health endpoints, `https://DOMAIN/login`, `/internal` blocked,
   cgroup controllers delegated for the sandbox limits, and a real sandboxed conversion as the
   converter user. A non-zero exit means a check failed (the release is deployed; see the output).
3. **Off-host again** — set `PRODUCTION_HOST` and `PRODUCTION_SSH_HOST_KEY` (content of
   production's `/etc/ssh/ssh_host_ed25519_key.pub`, pinned, no trust-on-first-use) and re-run
   `install-offhost.sh`; this enables the daily pull + restore test.

Still manual (printed at the end): DNS, firewall (22/80/443), alerting via `OnFailure=` drop-ins,
a test signup to confirm mail delivery, and keeping `install-offhost.sh` in step with production
updates (its `/opt/tutora` migrations are the restore test's schema reference).

> Verification status: both scripts were run end to end in an Ubuntu 24.04 systemd container
> (`TLS_MODE=self-signed`): install, re-runs, a real PPTX conversion by the daemon through rootless
> Podman, maintenance and backup units, the real SSH pull through `rrsync -ro` (write, delete, shell
> and path escapes refused) and the off-host restore test. Not exercised there: Let's Encrypt
> issuance (needs public DNS) and cgroup v2 enforcement of the sandbox limits (the test host was
> cgroup v1 — the installer checks the delegation on the real host and fails if it is missing).

## Layout

The app in `/var/www/tutora` (a symlink to the current release; document root `app/public`), data
in `/var/lib/tutora` (`STORAGE_PATH`, never under the web root), secrets in `/etc/tutora`
(mode 0711: reachable, not listable): the app's `.env` is `/etc/tutora/tutora.env` (0640
root:tutora-app, linked from each release), each service gets its own file with only its values.

| File | Purpose |
| --- | --- |
| `install.sh`, `install-offhost.sh`, `lib.sh`, `*.conf.example` | the installers |
| `apache/tutora-vhost.conf` | vhost template: port 80 (ACME + redirect), TLS vhost, PHP-FPM; WebSocket reverse proxy `/ws` → relay and `/wb` → whiteboard sidecar (loopback); internal APIs never proxied |
| `systemd/tutora-relay.service` | Go realtime relay (loopback; binary at `/usr/local/bin/tutora-relay`) |
| `systemd/tutora-whiteboard.service` | Node whiteboard sidecar (loopback; `/usr/local/bin/node`) |
| `systemd/tutora-converter.service` | slide conversion daemon (rootless Podman) |
| `systemd/tutora-purge.{service,timer}` | hourly maintenance (`app/bin/purge.php`): auto-end sessions live > 24 h, retention purge |
| `systemd/tutora-backup.{service,timer}` | daily encrypted backup + on-host verification (public key only) |
| `offhost/tutora-offhost-restore.{service,timer}` | on the off-host machine: pull + full restore test (private key) |
| `backup/*.sh` | backup, verify, restore test, off-host wrapper, CI test |

## Service users and groups

| User | Needs |
| --- | --- |
| `www-data` (PHP-FPM) | read the app; read/write `/var/lib/tutora` (group `tutora`); app secrets (group `tutora-app`) |
| `tutora` | maintenance job: app secrets, read/write `/var/lib/tutora` |
| `tutora-converter` | its own rootless Podman; read/write `/var/lib/tutora`; app secrets; home `/var/lib/tutora-converter` |
| `tutora-backup` | read `/var/lib/tutora`, write `/var/backups/tutora`, a read-only DB account; public backup key only |
| `tutora-backup-pull` | SSH forced command `rrsync -ro /var/backups/tutora` for the off-host machine |
| `tutora-relay` | nothing on disk; `/etc/tutora/relay.env` only |
| `tutora-whiteboard` (group `tutora`) | read/write `/var/lib/tutora/whiteboard`; `/etc/tutora/whiteboard.env` only |

Group `tutora` (data) and group `tutora-app` (app secrets: DB password, keys) are separate, so the
whiteboard and backup users can read data but not the app's secrets. `/var/lib/tutora` is
`2770 root:tutora`; the systemd units set `UMask=0027` (0077 for relay and backups).

## Rootless container runtime (owner decision 7)

The converter is never in the `docker` group (a rootful Docker socket is root-equivalent). The
installer sets up **rootless Podman** for `tutora-converter`: subordinate uid/gid range, lingering
user session (`/run/user/<uid>`), `Delegate=cpu cpuset io memory pids` for `user@.service` (the
sandbox's `--cpus` needs the cpu controller), `fuse-overlayfs` as fallback storage, and builds the
image as that user. `DockerConverterRunner` adds `--userns keep-id:uid=65532,gid=65532` for Podman,
so the non-root container user maps onto `tutora-converter` and can write the job's 0700 output
directory. The per-job sandbox profile (network none, non-root, cap_drop ALL, read-only root,
noexec tmpfs, pids/memory/CPU limits, external timeout) is unchanged. Rootless Docker is not set
up by the installer.

## Timers

```sh
systemctl list-timers 'tutora-*'        # enabled by install.sh (backup only with a backup key)
```

## Backups (owner decisions 5, 19, 22)

Two machines, one key pair:

- **Production host** (`tutora-backup.timer`, daily ~02:30): `tutora-backup.sh` writes
  `tutora-db-<UTC>.sql.gz.gpg` (mysqldump `--single-transaction`, no `USE`/`CREATE DATABASE`),
  `tutora-files-<UTC>.tar.gz.gpg` (`STORAGE_PATH` without `staging/` and `mail-outbox/`) and
  `tutora-manifest-<UTC>.sha256` to `/var/backups/tutora` (0700/0600), deletes backups older than
  14 days, then `tutora-backup-verify.sh` checks the checksums and that both files are encrypted
  exactly to the backup key. **Encryption is mandatory** (the script refuses to run without a
  recipient key) and streamed (no plaintext on disk). The production host holds **only the public
  key** — it cannot read its own backups.
- **Off-host restore machine** (`deploy/offhost`, daily ~04:30): pulls new backup files read-only,
  keeps its copies 14 days, and runs the **full restore test** with the private key
  (`tutora-offhost-restore-test.sh` → `tutora-restore-test.sh`): manifest checksums, decryption,
  import into a scratch database, the restored schema compared column by column with a reference
  built from exactly the migrations recorded in the backup, `CHECK TABLE` on every table, storage
  archive readable, and the newest backup at most 26 h old (detects a stopped backup routine).
  The pull model means the production host has no credentials for this machine, so a compromised
  production host cannot delete or alter the off-host copies.

Add alerting to both units with an `OnFailure=` drop-in (e.g. a mail unit).

### Key pair (generated on the off-host machine)

```sh
sudo -u tutora-restore GNUPGHOME=/var/lib/tutora-restore/gnupg \
  gpg --quick-gen-key 'Tutora backups <backup@example.org>' rsa4096 encrypt never
sudo -u tutora-restore GNUPGHOME=/var/lib/tutora-restore/gnupg \
  gpg --armor --export backup@example.org > backup-public.asc     # copy this to production
# keep an offline copy of the private key (e.g. paper/hardware) — without it no backup can be read
```

### Production host

```sql
CREATE USER 'tutora_backup'@'localhost' IDENTIFIED BY '<random>';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON tutora.* TO 'tutora_backup'@'localhost';
```

```sh
# (done by install.sh; shown for reference)
install -d -m 0700 -o tutora-backup /var/backups/tutora /var/lib/tutora-backup /var/lib/tutora-backup/gnupg
install -m 0600 -o tutora-backup /dev/null /etc/tutora/backup.cnf   # [client] user/password/host
cat > /etc/tutora/backup.env <<'ENV'
BACKUP_DB_CNF=/etc/tutora/backup.cnf
DB_NAME=tutora
STORAGE_PATH=/var/lib/tutora
BACKUP_RETENTION_DAYS=14
BACKUP_GPG_RECIPIENT=backup@example.org
GNUPGHOME=/var/lib/tutora-backup/gnupg
BACKUP_UMASK=027
ENV
sudo -u tutora-backup GNUPGHOME=/var/lib/tutora-backup/gnupg gpg --import backup-public.asc
# pull account for the off-host machine: read-only rsync of the backup directory, nothing else
useradd --system --home-dir /var/lib/tutora-backup-pull --create-home --shell /bin/sh tutora-backup-pull
usermod -aG tutora-backup tutora-backup-pull     # and set BACKUP_UMASK=027 in backup.env
install -d -m 0700 -o tutora-backup-pull /var/lib/tutora-backup-pull/.ssh
echo 'command="/usr/bin/rrsync -ro /var/backups/tutora",restrict ssh-ed25519 AAAA… restore-machine' \
  > /var/lib/tutora-backup-pull/.ssh/authorized_keys
```

With `BACKUP_UMASK=027` the backup directory is 0750 and new files 0640, readable by the group
`tutora-backup`, whose only other member is the pull account. The files are encrypted, so the
pull account (and anything that compromises it) sees ciphertext only.

### Off-host restore machine

MariaDB (same major version as production), a checkout of the **deployed** Tutora version in
`/opt/tutora` (its `app/migrations` are the schema reference), `rsync`, `gpg`, and:

```sql
CREATE USER 'tutora_restore'@'localhost' IDENTIFIED BY '<random>';
GRANT ALL ON tutora_restore_test.* TO 'tutora_restore'@'localhost';
GRANT ALL ON tutora_restore_ref.* TO 'tutora_restore'@'localhost';
```

```sh
cat > /etc/tutora-restore/restore.env <<'ENV'
OFFHOST_SOURCE=tutora-backup-pull@tutora.app:
OFFHOST_SSH=ssh -i /var/lib/tutora-restore/pull_ed25519 -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes
BACKUP_DIR=/var/backups/tutora-offhost
BACKUP_DB_CNF=/etc/tutora-restore/restore.cnf
MIGRATIONS_DIR=/opt/tutora/app/migrations
GNUPGHOME=/var/lib/tutora-restore/gnupg
ENV
systemctl enable --now tutora-offhost-restore.timer
```

> Verification status: backup, verify, pull (local path), retention and the full restore test
> — including tampering, wrong key, `USE` injection, schema drift and stale-backup cases — run in
> CI (`backup` job, `deploy/backup/ci-backup-test.sh`). The SSH transport with `rrsync -ro` could
> not be exercised in the development environment (no SSH server); test the pull once manually.

**Retention statement (for the privacy policy).** Sessions end when the tutor ends them, or
automatically 24 hours after they started. Ended sessions are purged 30 days after the
session ended (per-tenant configurable). Backups are kept 14 days, so purged session data can
still exist in a backup for at most 14 days after the purge — i.e. at most 44 days after the
session ended with the default retention (at most 24 h + 30 d + 14 d after a session started). Backups are not edited retroactively.

The DB dump and the storage archive are taken seconds apart, not atomically; a file written in
between (e.g. a new snapshot) may be missing from, or extra in, the archive.

## Disaster recovery (manual restore)

The production host cannot decrypt its backups (owner decision 22). Decrypt on the off-host
restore machine (or wherever the offline private key is) and stream the plaintext over SSH, so it
is never stored unencrypted on either side:

```sh
# on production
systemctl stop apache2 tutora-converter tutora-relay tutora-whiteboard tutora-purge.timer tutora-backup.timer
mysql -e 'CREATE DATABASE IF NOT EXISTS tutora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'

# on the off-host machine (as tutora-restore, with the private key); <UTC> = chosen backup
gpg --decrypt /var/backups/tutora-offhost/tutora-db-<UTC>.sql.gz.gpg    | ssh admin@tutora.app 'zcat | sudo mysql tutora'
gpg --decrypt /var/backups/tutora-offhost/tutora-files-<UTC>.tar.gz.gpg | ssh admin@tutora.app 'sudo tar -C /var/lib/tutora -xzf -'

# on production
chgrp -R tutora /var/lib/tutora && chmod -R g+rwX,o-rwx /var/lib/tutora   # owners stay; or re-run install.sh
sudo -u tutora php8.3 /var/www/tutora/app/bin/migrate.php   # applies migrations newer than the backup
sudo -u tutora php8.3 /var/www/tutora/app/bin/purge.php     # re-purges data that expired meanwhile
systemctl start tutora-whiteboard tutora-relay tutora-converter apache2 tutora-purge.timer tutora-backup.timer
```

Data purged before the backup was taken does not come back; session data purged after it does
come back with an `expires_at` in the past, and the `purge.php` run above removes it again.
