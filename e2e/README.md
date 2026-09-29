# End-to-end browser tests

`activities.e2e.mjs` drives the real UI in Chromium against a running stack: tutor signup
with TOTP, workshop authoring, a live session with two participants, every activity type,
both quiz modes, live updates over the relay, Write privacy, wall moderation, and it fails
on any console error or CSP violation.

Prerequisites: PHP app, relay and MariaDB running (see the root README), with
`HIBP_FAIL_OPEN=true` if the test machine cannot reach the HIBP API, and Playwright installed.

```sh
BASE_URL=http://127.0.0.1:8099 \
PLAYWRIGHT_MODULE=/path/to/node_modules/playwright/index.mjs \
CHROMIUM_PATH=/path/to/chrome \
node e2e/activities.e2e.mjs
```

Not run in CI yet (needs the full stack); planned together with the Phase 9 ops work.
