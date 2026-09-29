# Tutora — Implementation Plan

Source of truth: *Tutora — Architecture Spec* (Sep 29, 2026). This plan orders the work,
records decisions taken where the spec was open, and lists deviations with rationale.
Every deviation is also marked in code comments where it matters.

## 1. Decisions taken for open points (confirmed with product owner)

| Topic | Decision |
| --- | --- |
| PHP version | **PHP 8.3** (no 8.4-only syntax/functions). `composer.json` pins `php: ~8.3.0` and `config.platform.php`. |
| PHP dependencies | **Zero runtime dependencies.** TOTP (RFC 6238), routing, CSRF, templating, HIBP client are in-house. Composer only for PSR-4 autoload + dev tooling (PHPUnit, PHPStan). |
| Breached-password check | **HIBP k-anonymity range API** (`api.pwnedpasswords.com/range/{5 hex}` with `Add-Padding: true`). Only the 5-char SHA-1 prefix leaves the host. **Fail-closed by default** when the API is unreachable (`HIBP_FAIL_OPEN=false`); configurable. |
| Session-data retention | **Configurable, default 30 days after session end**, overridable per tenant (`tenants.retention_days`). Purged by a daily job. Backup retention statement: see §6. |
| Frontend | **Vanilla JS ES modules, no build step**, server-rendered PHP templates. Only exception: the whiteboard client bundles Yjs with a small esbuild step. |

## 2. Decisions taken by implementation (spec left a choice; reviewable in PR)

| Topic | Decision | Rationale |
| --- | --- | --- |
| WS token transport (Realtime §"Query-string tokens") | **Option (b): authenticate in the first WS message**, 5 s auth deadline. Same for the Yjs sidecar. | Removes the token from URLs entirely, so no log-redaction obligation on every proxy layer. |
| Rate limiting of entity mutations | **Per `actor_id`, across all connections.** | Spec's Whiteboard §Moderation says "per connection", Realtime §Multi-tab says "never per-socket". The Realtime rule is the later, explicit resolution; per-connection would allow bypass via multiple tabs. |
| `sessions.tenant_id` (added column) | Sessions carry a denormalised `tenant_id`; `workshop_id` becomes nullable `ON DELETE SET NULL`, plus `workshop_title_snapshot`. | Spec requires history/exports to survive template edits/deletion. Without `tenant_id` on the session, deleting a workshop would orphan the session from its tenant and break tenant-scoped access. |
| `moderation_actor_id` storage | Random opaque 128-bit value generated per participant per session, stored on `session_participants` and copied onto every submission/entity. | Spec lists it on submissions/entities but not where it originates. |
| Primary keys | `BIGINT UNSIGNED AUTO_INCREMENT` internally. Yjs entities use UUIDv4 keys. | Authorization never relies on ID unguessability (spec: bare IDs are never authorization). |
| FK deletion behaviour (Hardening §FK) | See §5. | Spec requires this to be explicit. |
| Token signing keys | **Separate HMAC key per audience** (relay, whiteboard, participant credential), each token also carries `aud`. | Spec requires `aud`; with symmetric HMAC every verifier can also mint, so separate keys stop a compromised sidecar from minting relay or participant tokens. |
| `sessions.expires_at` meaning | Interpreted as **purge-after timestamp** (`ended_at` + tenant retention). | Spec lists the column without defining it; the only expiry-related session concept in the spec is the TTL purge. |
| Join-code uniqueness among live sessions | `active_join_code` column (= `join_code` while not ended, `NULL` after) with a `UNIQUE` index. | Enforced by the DB rather than by an application check-then-insert race. |
| `sessions.current_session_block_id` FK | Single-column FK `ON DELETE SET NULL`; same-session check in the repository. | A composite FK forms a delete cycle with `session_blocks` CASCADE (verified). All other session-child tables use composite `(x_id, session_id)` FKs, so cross-session references are structurally impossible. |
| Navigation path (`block_change`) | **tutor → PHP (DB + `session_revision`) → `/internal/broadcast` → relay → room**; the relay rejects `block_change` sent by clients. | Spec's message table says "tutor → relay → room", but its recovery section makes HTTP authoritative. Navigating on the socket would let DB and relay disagree and lose position on a relay restart. |
| Relay limits | Per actor: 10 connections, 30 msg/s (burst 60) shared across connections; per room: 2000 connections; 64 KiB per message; 16 KiB per presenter stroke; 5000 buffered strokes per block. Ended sessions refuse reconnects for 5 min (> 60 s token TTL). | Spec requires explicit limits and per-actor (not per-socket) rate limiting; values are proposed defaults. |
| Tutor presence | Relay sends tutors a `presence` message with the **count** of distinct connected participants only. | Useful for the tutor, reveals no identity. |
| Which block accepts input | Only the session's **current** block accepts participant input (submissions, wall cards, quiz answers); checked under a shared lock on the session row so it serialises with navigation. | Spec is silent; otherwise answers could trickle into past/future blocks. |
| Live results for participants | Anonymous aggregates are shown to participants live (Poll, Meter, Rate, Rank, Word, Plot, Word Cloud). Write shows participants only the response count; raw text is tutor-only. | Spec: Write submissions visible only to the tutor. A per-block "hide results until tutor reveals" switch is a possible follow-up. |
| Wall permissions | Participants add (max 20 per wall) and edit/move/delete **only their own** cards; tutor can seed, move, delete any card and remove one participant's cards. Authorship is never shown to participants. | Conservative default against griefing; easy to relax to "anyone may move". |
| Moderation scope | "Remove this participant's contributions" deletes their generic submissions and wall cards (whiteboard entities in Phase 7). Quiz answers are kept. | Quiz answers are graded, not displayed content. |
| Quiz timing | One OPEN question per quiz block, each question runs once. Deadlines are server timestamps; expired runs are revealed lazily on the next read/answer (tutor console polls while a question is open). No speed bonus (leaderboard deferred to v2). | Avoids a cron for reveals; atomic reveal verified by a two-connection lock test. |
| Self-paced finish | A participant has finished when every question is answered or its own deadline has passed; only then are answers, key and score returned. | Spec: results held until the whole quiz is finished. |
| Referrer-Policy | `same-origin` (not `no-referrer`). | With `no-referrer` browsers send `Origin: null` on same-site form POSTs, breaking the CSRF Origin check — found by the browser E2E test. |
| Converter base image | `ubuntu:24.04` (not Debian) with LibreOffice Impress + poppler-utils. | Equally sound base; it is the image that could be built and tested end-to-end in the development sandbox (whose egress policy blocks Debian mirrors). |
| Rasterisation size | `pdftoppm -scale-to 1920` (longest side) instead of a fixed DPI. | Spec says "fixed DPI"; a fixed pixel bound gives uniform slide quality and caps memory/CPU for decks with oversized page boxes (a resource-exhaustion vector). Easy to switch. |
| Per-job container launch | The daemon starts one container per job via the container CLI, with the full sandbox profile applied per run and the timeout enforced outside; on timeout the container is force-removed by name. | Spec: job-specific mounts and an externally enforced wall-clock timeout imply per-job containers. **Trade-off:** the daemon user needs container-runtime access (docker group = root-equivalent on that host). Mitigations: dedicated service user, systemd hardening (see `deploy/systemd/`), rootless Docker/Podman supported via `CONVERTER_RUNTIME`. Needs owner sign-off. |
| Converter output trust | Output is untrusted: only regular `page-N.png` files (no symlinks), contiguous pages, PNG signature, ≤ 20 MB, ≤ 4096 px per side, ≤ `CONVERTER_MAX_PAGES`. | A compromised converter must not be able to make PHP read host files or store non-images. |
| LibreOffice hardening | Profile with macros disabled (`DisableMacrosExecution`, security level 3), Java off, update checks off; network is `none` anyway. | Defence in depth beyond the container boundary. |
| Slide images | Served only through authenticated endpoints (tutor: own tenant; participant: assets used by a block of their session), `Content-Security-Policy: default-src 'none'; sandbox`. Participants load them via `fetch` + `blob:` URL (bearer auth). | Nothing converted is placed under the web root. |
| Workshop deletion | Deletes the workshop's slide files and staged uploads; past sessions keep their snapshot but show "slide no longer available". | Avoids orphaned files; spec has no rule for assets of deleted templates. Alternative (reference-count across sessions) possible later. |
| Retry policy | Only runtime unavailability / internal errors are retried (max 3 attempts); converter failures (bad input, too many pages, timeout) fail immediately and keep the source ≤ 24 h. | Retrying a malicious or broken document is wasted work. |
| Whiteboard sidecar implementation (**approved by owner**) | Thin server on the **official Yjs primitives** (`yjs`, `y-protocols` sync, `lib0`, `ws`, exact versions pinned) instead of running the stock y-websocket server or Hocuspocus. | Spec requires per-entity authorisation (participants edit/delete only their own entities, spectators read-only) and token-bound documents. The reference server has no hook to inspect updates; Hocuspocus would require parsing its internal framing. The CRDT and sync protocol remain the official implementation; only the auth/validation layer is ours. |
| Update authorisation | Every update is applied to a throwaway copy first; only the `entities` map may change, values must be plain JSON, no incomplete updates, strict per-type schema (normalised coordinates), no ownership transfer, count/size limits. A violating connection is closed (4403) and the client discards its local state. | Spec: "remove this participant's contributions" must be a real, well-defined operation and permissions must not depend on anything the client asserts. |
| Whiteboard document binding | The sidecar derives the document from the token's `(sid, bid)` claims; clients never name a document. | Stricter form of the spec's "validate the requested document ID matches the signed claims". |
| Whiteboard roles | Participant role only on the **current** collaborative/annotate block; presenter-mode boards stay relay-only (participants read-only); tutor role on any board of their live session. Spectator role exists in the sidecar but is not issued by PHP in v1. | |
| Awareness (cursors) | Not relayed in v1. | Reduces surface; can be added with its own validation. |
| Whiteboard persistence | Raw Yjs bytes on disk under `STORAGE_PATH/whiteboard/s<session>/`: compacted snapshot + append-only log (atomic rename, torn-record tolerant), compaction every 200 updates and on idle unload. Session delete drops the directory via the internal API. | Spec leaves the location open; files keep the Node sidecar free of a DB driver and make session purge a directory removal. |
| Block-exit snapshot | The tutor console captures before submitting navigation away from a board; the relay `capture` event (sent by PHP on block exit) is honoured by other tabs showing the board, and ignored by the tab that already captured (no duplicates). | Spec: capture on block exit via relay event; the navigating tab would otherwise be gone before the event arrives. |
| Coordinates / z-order | Entity coordinates normalised to [0, 1]; entity keys are time-ordered so every client draws in the same order. | Resolution-independent rendering and captures. |
| `whiteboard_entities` table | **Removed** (owner decision). Board content lives in the sidecar's Yjs documents (collaborative/annotate) and the relay buffer (presenter). Migration `0002` drops it on databases created from earlier revisions. | The spec's entity model is the Yjs `entities` map; a parallel SQL table would be unused/duplicated state. |
| `config_version` | Column on `workshop_blocks` and `session_blocks` (not inside the JSON). | Queryable, enforces presence via `NOT NULL`. |

## 3. Repository layout

```
app/                    PHP 8.3 application (Apache docroot = app/public)
  public/index.php      front controller
  src/                  PSR-4 namespace Tutora\
  templates/            server-rendered templates (auto-escaping helper)
  migrations/           ordered *.sql files
  bin/                  CLI: migrate, conversion daemon, purge job
  tests/                PHPUnit (unit + DB integration + cross-tenant tests)
relay/                  Go realtime relay
whiteboard/             Node y-websocket/Hocuspocus sidecar
converter/              Conversion worker image (LibreOffice + poppler) + scripts
deploy/                 Apache vhost, systemd units, backup/restore scripts
docker-compose.yml      dev environment (spec §Deployment)
```

## 4. Phases

Each phase ends with passing tests and is committed separately.

### Phase 0 — Scaffolding
- Repo layout, `.gitignore`, `.env.example`, `docker-compose.yml` (app, mariadb, relay, whiteboard, converter w/ `network_mode: none`).
- Composer setup (PSR-4, PHPUnit; PHPStan to be added once its dist download is reachable from the build environment), `go.mod`, `package.json`.
- CI workflow: PHP lint + PHPUnit against MariaDB service, `go test`, `node --test`.

### Phase 1 — Database schema & PHP core
- Full schema migration for every table in the spec (template side, live snapshot side, quiz, wall, whiteboard, slide import, conversion jobs, AI usage/quota, audit log, rate-limit buckets) with explicit FK `ON DELETE` rules and `UNIQUE` constraints.
- Migration runner (`bin/migrate.php`).
- Core: config/env loader, PDO factory (strict mode, `utf8mb4`, emulated prepares off), router, request/response, security headers (CSP, etc.), HTML output encoder, CSV formula-injection-safe writer.
- **Tenant-scoped data-access layer**: repositories for tutor-side data can only be constructed from a `TenantContext`; queries always bind `tenant_id`.
- Log redaction utility (no tokens/submissions/AI prompts in logs).

### Phase 2 — Tutor authentication
- Signup/login with Argon2id; HIBP range check at signup and password change.
- Mandatory TOTP MFA enrolment (RFC 6238, ±1 step, replay protection via last-used-step).
- Server-side sessions (`httponly`, `secure`, `SameSite=Strict`), session ID regeneration on login/MFA.
- CSRF synchroniser tokens on every state-changing tutor request.
- Login rate limiting: per-IP and per-account exponential backoff (no hard lockout).
- Audit events: login (success/failure), MFA changes, workshop deletion, exports, AI summary, session deletion.

### Phase 3 — Workshops, blocks, live sessions, participants
- Workshop + block CRUD (tenant-scoped), `config_version`, per-type config validation and size limits.
- Session start: snapshot `workshop_blocks` → `session_blocks` in one transaction; join code (6 chars, safe alphabet, unique among live sessions).
- Participant join (rate-limited), resume token (≥128-bit CSPRNG, stored as SHA-256 hash), presence renewal endpoint, expiry formula `min(max(last_activity_at, presence_renewed_at)+30min, session_end+15min)`.
- Participant credential (signed, session-bound); participant endpoints verify credential's session claim == requested session.
- `GET /session/{id}/state` (role-aware projection, `session_revision`).
- Connection-token minting (HMAC, `aud`, ~60 s).

### Phase 4 — Go relay
- WS server, Origin allowlist, first-message HMAC auth with deadline.
- Rooms keyed by `session_id`, clients keyed by `(actor_id, connection_id)`, fan-out per actor.
- `block_change` (tutor only), `whiteboard_stroke_broadcast` (tutor only), `ping/pong`, `capture`.
- `POST /internal/broadcast` (shared secret, private bind) for `activity_aggregate_update`, quiz events.
- Per-actor rate limiting, message size caps, revision numbers.

### Phase 5 — Activities
- Generic `block_submissions` with UPSERT: Poll, Meter, Rate, Rank (Borda), Word, Plot, Word Cloud, Write.
- Aggregators per type; push aggregates to relay.
- Wall cards (LWW position), moderation by `moderation_actor_id`.
- Quiz: tutor-paced state machine (`PENDING→OPEN→REVEALED`, atomic reveal), self-paced flow, answer-key stripping, server-side timing.

### Phase 6 — Slide import pipeline
- Upload (magic bytes, size cap, non-web staging), `slide_imports` + `conversion_jobs`.
- Supervised PHP daemon (systemd) polling at 1–2 s; runs converter container per job with the full sandbox profile and an external wall-clock timeout.
- `soffice --headless --convert-to pdf` → `pdftoppm` → `slide_assets`; cleanup after commit; failed sources purged after 24 h.

### Phase 7 — Whiteboard sidecar
- Node y-websocket/Hocuspocus with token auth (`aud=tutora-whiteboard`), doc-ID binding, role-based permissions enforced on the `entities` Y.Map, size limits, per-actor rate limit.
- Operational persistence (compacted snapshot + incremental updates).
- Client-side snapshot capture → `whiteboard_snapshots`.
- Moderation: clear board, remove actor's entities. Annotate mode.

### Phase 8 — AI (Write) summaries
- Provider interface `generateSummary(text[]) → string`, delimited prompt, output escaped.
- `ai_usage`, `ai_quota`, separate hard per-session/per-day call cap.

### Phase 9 — Exports, retention, ops
- Session export (union of `block_submissions` and `quiz_answers`) with CSV formula-injection protection.
- Retention purge job (30 d default, per tenant).
- Apache vhost (WS reverse proxy to 127.0.0.1), systemd units, backup + **restore test** script.

## 5. FK deletion rules

| Child → Parent | Rule | Why |
| --- | --- | --- |
| `workshops` → `tenants` | RESTRICT | Tenant deletion must be an explicit, audited process. |
| `workshop_blocks` → `workshops` | CASCADE | Template internals. |
| `slide_imports` → `workshops` | CASCADE | Template internals (asset files removed by app). |
| `workshop_blocks.slide_asset` / `slide_assets` → `slide_imports` | CASCADE / RESTRICT from blocks | A block can't point at a deleted asset; the app removes blocks first. |
| `sessions` → `tenants` | RESTRICT | See above. |
| `sessions.workshop_id` → `workshops` | SET NULL | History survives template deletion (snapshot model). |
| `session_blocks.source_workshop_block_id` → `workshop_blocks` | SET NULL | Snapshot model. |
| `session_blocks`, `session_participants`, `block_submissions`, `quiz_*`, `wall_cards`, `whiteboard_*` → `sessions` | CASCADE | Session purge (retention/tutor delete) removes all its data in one statement. |
| `block_submissions`, `quiz_*` → `session_participants` | CASCADE | Participant rows only exist within their session. |
| `ai_usage` → `sessions` | SET NULL | Usage/billing record must survive session purge. |
| `audit_events` → `tenants` | RESTRICT | Audit trail is not deleted with application data. |

## 6. Open items that need an owner decision later (not blocking)

- **Backup retention vs. purge**: purged session data persists in backups until they rotate. Proposed statement: backups retained 14 days, so purged data is gone from all copies ≤ 30 + 14 days after session end. Needs sign-off.
- **LLM provider / DPA / EU endpoint**: provider interface is built; no vendor wired until chosen.
- **Size limits**: proposed defaults (configurable): display name 40 chars, Wall card 500, Write response 2000, Word Cloud word 40, Yjs message 64 KiB, upload 50 MiB.
- **HIBP fail-open vs fail-closed** default is fail-closed; revisit if it causes signup friction.
- **TOTP enrolment QR code**: zero-runtime-deps rules out a server-side QR library. Enrolment currently shows the base32 setup key and the `otpauth://` URI. Options: vendor a small client-side QR script (no build step) or accept manual entry.
- **MFA recovery**: the spec mandates TOTP but defines no recovery path (lost device). Proposal: one-time recovery codes (hashed) generated at enrolment, plus an audited admin reset.
- **Signup account enumeration**: "email already registered" is revealed at signup because v1 has no email verification. Removing it requires an email-verification flow (would also need outbound mail).
