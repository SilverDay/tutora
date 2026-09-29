# End-to-end browser tests

`slides.e2e.mjs` uploads a real PPTX in the browser and follows it through the conversion
daemon (sandboxed container), thumbnails, slide blocks and a live session until the
participant sees the rendered slide. It needs `bin/conversion-daemon.php` running with a
built `CONVERTER_IMAGE`.

`whiteboard.e2e.mjs` checks collaborative drawing in both directions, the own-entities-only
eraser, annotate tags, presenter mode over the relay, clear, and snapshots (manual and on
block exit) by sampling canvas pixels in the other browser. It needs the relay and the
whiteboard sidecar running.

`activities.e2e.mjs` drives the real UI in Chromium against a running stack: tutor signup
with TOTP, workshop authoring, a live session with two participants, every activity type,
both quiz modes, live updates over the relay, Write privacy, wall moderation, and it fails
on any console error or CSP violation.

Prerequisites: PHP app, relay and MariaDB running (see the root README) with
`MAIL_DRIVER=file` (the tests read verification links from `MAIL_OUTBOX`, default
`/var/lib/tutora/mail-outbox`), with
`HIBP_FAIL_OPEN=true` if the test machine cannot reach the HIBP API, and Playwright installed.

```sh
BASE_URL=http://127.0.0.1:8099 \
PLAYWRIGHT_MODULE=/path/to/node_modules/playwright/index.mjs \
CHROMIUM_PATH=/path/to/chrome \
node e2e/activities.e2e.mjs
```

Not run in CI yet (needs the full stack); planned together with the Phase 9 ops work.
