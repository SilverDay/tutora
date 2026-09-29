# Deployment and operations

Production layout: the app in `/var/www/tutora` (document root `app/public`), data in
`/var/lib/tutora` (`STORAGE_PATH`, never under the web root), configuration in
`/var/www/tutora/.env` (mode 0640, owner root, group `tutora`) and `/etc/tutora/*`.

| File | Purpose |
| --- | --- |
| `apache/tutora-vhost.conf` | TLS vhost; WebSocket reverse proxy `/ws` → relay and `/wb` → whiteboard sidecar (loopback); internal APIs never proxied |
| `systemd/tutora-relay.service` | Go realtime relay (loopback; binary at `/usr/local/bin/tutora-relay`) |
| `systemd/tutora-whiteboard.service` | Node whiteboard sidecar (loopback) |
| `systemd/tutora-converter.service` | slide conversion daemon (rootless container runtime) |
| `systemd/tutora-purge.{service,timer}` | daily retention purge (`app/bin/purge.php`) |
| `systemd/tutora-backup.{service,timer}` | daily backup **and** restore test |
| `backup/tutora-backup.sh`, `backup/tutora-restore-test.sh` | backup and restore-test scripts |

## Service users

| User | Needs |
| --- | --- |
| `www-data` (Apache/PHP) | read the app; read/write `/var/lib/tutora` (group `tutora`) |
| `tutora` | purge job: DB access via `.env`, read/write `/var/lib/tutora` |
| `tutora-converter` | its own rootless container runtime; read/write `/var/lib/tutora`; home `/var/lib/tutora-converter` |
| `tutora-backup` | read `/var/lib/tutora` (member of group `tutora`), write `/var/backups/tutora`, a DB account for backups only |
| `tutora-relay` | nothing on disk; secrets in `/etc/tutora/relay.env` (0640 root:tutora-relay) |
| `tutora-whiteboard` (group `tutora`) | read/write `/var/lib/tutora/whiteboard` (create it before the first start) |

The systemd units set `UMask=0027` (0077 for relay and backups); set PHP-FPM/Apache so that
`/var/lib/tutora` is not world-readable either (e.g. `chmod 2770` on the directory, group `tutora`).

## Rootless container runtime (owner decision 7)

The converter must not be in the `docker` group: access to a rootful Docker socket is
root-equivalent. Use one of:

**Rootless Podman** (no daemon): `CONVERTER_RUNTIME=podman` in `.env`.

```sh
useradd --system --home-dir /var/lib/tutora-converter --create-home --shell /usr/sbin/nologin tutora-converter
usermod --add-subuids 200000-265535 --add-subgids 200000-265535 tutora-converter
loginctl enable-linger tutora-converter        # creates /run/user/<uid> at boot
echo "XDG_RUNTIME_DIR=/run/user/$(id -u tutora-converter)" > /etc/tutora/converter.env
sudo -u tutora-converter XDG_RUNTIME_DIR=/run/user/$(id -u tutora-converter) \
  podman build -t tutora-converter:latest /var/www/tutora/converter
```

**Rootless Docker**: `CONVERTER_RUNTIME=docker` in `.env`.

```sh
# as above: system user with home /var/lib/tutora-converter, subuid/subgid ranges, linger
sudo -iu tutora-converter dockerd-rootless-setuptool.sh install
echo "DOCKER_HOST=unix:///run/user/$(id -u tutora-converter)/docker.sock" > /etc/tutora/converter.env
sudo -iu tutora-converter docker build -t tutora-converter:latest /var/www/tutora/converter
```

Then `systemctl enable --now tutora-converter`. The per-job sandbox profile (network none,
non-root, cap_drop ALL, read-only root, noexec tmpfs, pids/memory/CPU limits, external
timeout) is applied by `DockerConverterRunner` with either runtime.

> Verification status: the runner and its sandbox are tested against rootful Docker in CI
> (`converter` job). The rootless setup above follows the Docker/Podman documentation but could
> not be exercised in the development environment (no rootless tooling there); run
> `DockerConverterRunnerTest` as the service user once after setting it up:
> `sudo -u tutora-converter TUTORA_TEST_CONVERTER_IMAGE=tutora-converter:latest php8.3 vendor/bin/phpunit --filter DockerConverterRunnerTest --fail-on-skipped`.

## Timers

```sh
systemctl enable --now tutora-purge.timer tutora-backup.timer
systemctl list-timers 'tutora-*'
```

## Backups (owner decision 5)

Daily at ~02:30: `tutora-backup.sh` writes
`tutora-db-<UTC>.sql.gz` (mysqldump `--single-transaction`, no `USE`/`CREATE DATABASE` in the
dump) and `tutora-files-<UTC>.tar.gz` (`STORAGE_PATH` without transient `staging/` and
`mail-outbox/`) to `/var/backups/tutora` (mode 0700, files 0600), and deletes backups older than
14 days. `tutora-restore-test.sh` then restores the newest dump into a scratch database, checks
the table set against the live schema, runs `CHECK TABLE` on every table, verifies the storage
archive, and drops the scratch database. Either failure fails the unit — add alerting with an
`OnFailure=` drop-in.

Setup:

```sql
CREATE USER 'tutora_backup'@'localhost' IDENTIFIED BY '<random>';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON tutora.* TO 'tutora_backup'@'localhost';
GRANT ALL ON tutora_restore_test.* TO 'tutora_backup'@'localhost';   -- scratch DB only
```

```sh
install -d -m 0700 -o tutora-backup /var/backups/tutora /var/lib/tutora-backup
install -m 0600 -o tutora-backup /dev/null /etc/tutora/backup.cnf   # [client] user/password/host
cat > /etc/tutora/backup.env <<'ENV'
BACKUP_DB_CNF=/etc/tutora/backup.cnf
DB_NAME=tutora
STORAGE_PATH=/var/lib/tutora
BACKUP_RETENTION_DAYS=14
# optional, recommended for any copy leaving the host (public key only on this host):
#BACKUP_GPG_RECIPIENT=backup@example.org
#GNUPGHOME=/var/lib/tutora-backup/gnupg
ENV
```

The backup account has read-only access to the live database; the restore test can only write
to the scratch database (and refuses dumps containing `USE`/`CREATE DATABASE`).

**Encryption.** With `BACKUP_GPG_RECIPIENT` set, both files are encrypted while being written
(no plaintext copy on disk). The restore test then needs the private key in `GNUPGHOME`;
keeping that key on the same host protects off-host copies only.

**Retention statement (for the privacy policy).** Ended sessions are purged 30 days after the
session ended (per-tenant configurable). Backups are kept 14 days, so purged session data can
still exist in a backup for at most 14 days after the purge — i.e. at most 44 days after the
session ended with the default retention. Backups are not edited retroactively.

The DB dump and the storage archive are taken seconds apart, not atomically; a file written in
between (e.g. a new snapshot) may be missing from, or extra in, the archive.

## Disaster recovery (manual restore)

```sh
systemctl stop apache2 tutora-converter tutora-relay tutora-whiteboard tutora-purge.timer tutora-backup.timer
mysql -e 'CREATE DATABASE tutora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'   # if missing
zcat tutora-db-<UTC>.sql.gz | mysql tutora          # gpg --decrypt ... | zcat | mysql tutora
tar -C /var/lib/tutora -xzf tutora-files-<UTC>.tar.gz
chown -R www-data:tutora /var/lib/tutora && chmod -R g+rwX,o-rwx /var/lib/tutora
sudo -u tutora php8.3 /var/www/tutora/app/bin/migrate.php   # applies migrations newer than the backup
systemctl start tutora-whiteboard tutora-relay tutora-converter apache2 tutora-purge.timer tutora-backup.timer
```

Data purged before the backup was taken does not come back; session data purged after it does
come back, and its `expires_at` is already past, so the next purge run removes it again. Run
`bin/purge.php` once right after a restore to do that immediately.
