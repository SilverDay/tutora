# Tutora

Live workshop/tutoring session assistant. See `docs/IMPLEMENTATION_PLAN.md` for the
phased implementation plan and the decisions taken on top of the architecture spec.

## Components

| Directory | Component |
| --- | --- |
| `app/` | PHP 8.3 / Apache / MariaDB application (no framework, PDO, server-rendered templates) |
| `relay/` | Go realtime relay (Phase 4) |
| `whiteboard/` | Node.js Yjs sidecar (Phase 7) |
| `converter/` | Sandboxed LibreOffice/poppler conversion worker (Phase 6) |
| `deploy/` | Apache vhost, systemd units, backup/restore (Phase 9) |

## Development

```sh
cp .env.example .env   # fill in secrets, chmod 640
docker compose up -d
docker compose exec app php bin/migrate.php
```

## Tests (PHP)

Integration tests need a MariaDB test database (`TEST_DB_*` env vars, defaults:
`127.0.0.1:3306`, db `tutora_test`, user `tutora` / `tutora_dev`).

```sh
cd app && composer install && vendor/bin/phpunit
```
