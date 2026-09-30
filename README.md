# Tutora

Tutora is a self-hosted platform for running live workshops and tutoring sessions. A tutor
builds a workshop from a sequence of blocks: slides, a collaborative whiteboard and interactive
activities. The tutor then runs it as a live session. Participants join from their own browser
with a 6-character join code and need no account. The tutor moves the session from block to
block, and answers, results and drawings update live for everyone.

The implementation follows the "Tutora — Architecture Spec". The phased plan, and every owner
decision taken on top of the spec, are in [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md).

## Features

**Block types**

| Block | Description |
| --- | --- |
| Slide | Pages of an uploaded PowerPoint (.pptx) or PDF file, rendered to images by a sandboxed converter |
| Whiteboard | Collaborative drawing (Yjs) with a presenter mode, clear and snapshots |
| Annotate | Collaborative drawing on top of a slide |
| Poll, Meter, Rate, Rank, Word, Plot, Word cloud | Anonymous input with a live aggregate. The tutor can hide the results until revealing them |
| Write | Free-text responses. Participants never see each other's raw answers. An optional AI summary is shown only after the tutor shares it, and only once there are at least 3 responses |
| Wall | Shared cards, moderated by the tutor |
| Quiz | Graded questions in two modes, with their own reveal step |

**For tutors**

- Workshop authoring and live session control
- CSV export of a session
- Configurable data retention (default 30 days). A session is ended automatically 24 h after it starts

**Public pages:** landing page, features, about and FAQ, plus placeholder legal pages (privacy policy, terms of use, cookies, imprint) in `app/templates/pages/`. Every `[TODO]` in the legal pages must be completed and legally reviewed before going live.

## Architecture

| Directory | Component |
| --- | --- |
| `app/` | PHP 8.3 application on Apache and MariaDB 10.11. No framework and no runtime Composer dependencies: PDO and server-rendered templates, with vanilla JS in the browser |
| `relay/` | Go WebSocket relay that fans out live session events. It has a separate internal interface for broadcasts and revocation |
| `whiteboard/` | Node.js Yjs (y-protocols) sidecar for the Whiteboard and Annotate blocks. The browser bundle is built with esbuild into `app/public/assets/whiteboard.bundle.js` |
| `converter/` | LibreOffice and poppler container image. `app/bin/conversion-daemon.php` starts one container per slide import |
| `deploy/` | Installers, Apache vhost, systemd units, and backup/restore scripts |
| `e2e/` | End-to-end browser tests (Playwright/Chromium) |

The CLI tools are in `app/bin/`:

- `migrate.php`: database migrations
- `conversion-daemon.php`: the slide conversion worker
- `purge.php`: hourly maintenance (auto-end after 24 h, retention purge)
- `admin-reset-mfa.php`: operator MFA reset, which requires a reason and is audited

## Security design

- **Tutor accounts:**
  - Argon2id passwords, checked against Have I Been Pwned via k-anonymity. The check fails closed.
  - TOTP MFA is mandatory, with recovery codes.
  - Email verification.
  - Changing the password, regenerating recovery codes or an MFA reset signs out every other session, including open relay and whiteboard connections.
- **Participants** are anonymous and session-scoped. Their credential is an HMAC token bound to the session. The resume token is stored only as a SHA-256 hash and rotated on every use.
- **Tenant isolation** is enforced in the data layer. Every tenant query must carry the tenant id, and composite foreign keys keep rows inside their tenant.
- **Realtime tokens** for the relay and the whiteboard sidecar are HMAC-signed and carry an audience and an expiry. The sidecars' internal interfaces are protected by shared secrets.
- **Browser hardening:**
  - Strict Content Security Policy with `script-src 'self'` and `frame-ancestors 'none'`
  - CSRF protection
  - Rate limiting
- **Slide conversion** runs untrusted office files in a throwaway container:
  - no network, non-root, all capabilities dropped, `no-new-privileges`
  - read-only root filesystem
  - memory, CPU and PID limits, and a timeout
  - rootless Podman in production
- **AI summaries** are disabled unless a provider is configured, and they are subject to tenant and per-session quotas.
  - Only an offline `stub` provider exists, for development and tests. Production refuses it.
  - Owner decision 6 lists the requirements a real provider must meet.
- **Backups** are always GPG-encrypted, streamed, and verified with a SHA-256 manifest.
  - The private key never touches the production host.
  - A separate off-host machine pulls the backups read-only and runs a daily full restore test.

## Development

```sh
cp .env.example .env   # fill in secrets, chmod 640
docker compose up -d
docker compose exec app php bin/migrate.php
```

The compose stack binds its ports to `127.0.0.1` only:

- app on port 8080
- relay on port 8090
- whiteboard sidecar on port 8091

It also contains MariaDB and the converter image. `MAIL_DRIVER=file` (development only) writes mail to a local outbox instead of sending it. Set `HIBP_FAIL_OPEN=true` only if the machine cannot reach the HIBP API.

## Tests

**PHP** (`app/`). The integration tests need a MariaDB test database, configured with the `TEST_DB_*` environment variables. The defaults are `127.0.0.1:3306`, database `tutora_test`, user `tutora` / `tutora_dev`.

```sh
cd app && composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse   # level 8, no baseline
```

**Relay** (`relay/`):

```sh
go vet ./... && go test -race ./...
```

**Whiteboard sidecar** (`whiteboard/`):

```sh
npm ci && npm test
```

**End-to-end:** the full stack in a real browser. See [`e2e/README.md`](e2e/README.md). These tests are not run in CI.

CI (`.github/workflows/ci.yml`) runs these jobs:

- PHP: lint, PHPStan and PHPUnit against MariaDB
- a backup/restore round trip
- deploy scripts: shellcheck and `systemd-analyze verify`
- relay tests
- the converter image build with its sandbox test
- whiteboard tests, plus a check that the committed bundle is up to date

## Deployment

The target is Ubuntu 24.04 LTS (x86_64). There are two installers, both idempotent and both driven by a `KEY=VALUE` file that is parsed, never executed:

1. `deploy/install-offhost.sh` (optional, recommended) sets up the off-host restore machine. It generates the backup key pair (the private key stays there) and the pull account's SSH key. Without it, production needs only a backup public key made elsewhere, but there is then no off-host copy and no automated restore test.
2. `deploy/install.sh` sets up the production host:
   - Apache with Let's Encrypt
   - PHP 8.3 and MariaDB
   - Go and Node from checksum-pinned official downloads
   - relay and whiteboard systemd units
   - the rootless Podman converter
   - an hourly maintenance timer (auto-end, retention purge) and a daily encrypted backup timer

Every step, and what each installer cannot do for you, is in [`deploy/README.md`](deploy/README.md).
