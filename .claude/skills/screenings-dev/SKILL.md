---
name: screenings-dev
description: Working on the Screenings Nextcloud app in this repo - running the Docker dev stack, tests, builds, browser checks, Mailpit, DB queries, leak tests, and the Windows/Docker pitfalls that waste time. Use before any build, test, debug or verification step in this project.
---

# Screenings dev workflow

Project state and next steps: `docs/HANDOFF.md`. Read that first in a new chat.

## Shell basics (Windows host, Git Bash)

- **Docker in Bash:** `docker` is not on the Bash PATH. Always use
  `D="/c/Program Files/Docker/Docker/resources/bin/docker.exe"` and run `"$D" compose …` from `dev/` (or `-f dev/docker-compose.yml` from the repo root).
- **Container paths:** prefix the command with `export MSYS_NO_PATHCONV=1;` whenever you pass container paths like `/tmp/x` or `/var/www/...`. Otherwise Git Bash rewrites them into Windows paths.
- **Copying files into a container:** use `"$D" compose cp <file> nextcloud:/tmp/x`, or Bash `< file` redirection. Don't pipe a file through PowerShell: it adds a BOM and CRLFs, and `sh` then fails with `cd: not found`.
- **Python output:** host Python prints CRLF. Pipe values into shell variables through `| tr -d '\r'`, or headers break with Apache 400 errors.
- **Quoting:** complex `sh -c '…'` one-liners with `$( )` and quotes break under PowerShell. Write a script file in the scratchpad and run it with Bash instead.
- **Waiting:** foreground `sleep` alone is blocked. Poll inside a loop (`for i in $(seq 1 30); do sleep 3; … && break; done`).
- **Starting Docker:** if the engine isn't running, start "C:\Program Files\Docker\Docker\Docker Desktop.exe" and poll `docker info`.

## Everyday commands (from `dev/`)

```bash
D="/c/Program Files/Docker/Docker/resources/bin/docker.exe"
"$D" compose up -d                                   # stack: nextcloud, cron, db, redis, mailpit, frontend (watcher)
"$D" compose exec -T -u www-data nextcloud sh -c 'cd custom_apps/screenings && for f in $(find lib -name "*.php"); do php -l "$f" | grep -v "^No syntax"; done'   # lint
"$D" compose run --rm --entrypoint php composer vendor/bin/phpunit -c tests/phpunit.xml   # unit tests (repo mounted at /app)
"$D" compose exec -T -u www-data nextcloud cat data/nextcloud.log | python ../.claude/skills/screenings-dev/scripts/nc-errors.py 2026-10-25T21:00   # new server errors
"$D" compose exec -T -u www-data nextcloud php occ upgrade   # after bumping <version> in appinfo/info.xml (migrations, new background jobs)
"$D" compose exec -T -u www-data nextcloud php occ screenings:process-media <id> --force
"$D" compose exec -T -u www-data nextcloud sh -c 'for i in 1 2 3; do php -f cron.php; done'   # run queued jobs now (one pass may not reach a new job)
"$D" compose exec -T db sh -c 'mariadb -unextcloud -p"$MARIADB_PASSWORD" nextcloud -e "SELECT …"'   # tables are oc_scr_*
```

- **Mailpit:**
  - Newest message: `curl -s "http://localhost:8025/api/v1/messages?limit=1"`.
  - One-time codes are in the email subject, so extract them with `re.search(r'(\d{6})', subject)`.
  - Full text of a message: `/api/v1/message/<ID>`.
- **Composer packages:** `"$D" compose run --rm composer <cmd>`, then `composer dump-autoload` after editing `composer.json`. `nextcloud/ocp` (stubs) and `NCU` are mapped in `autoload-dev`, so PHPUnit can mock OCP interfaces.

## Frontend build: pitfalls that already cost time

- **The watcher polls.** File events from Windows don't reach the container, so it polls (`CHOKIDAR_USEPOLLING`). Rebuilds take about 10 s. Confirm a build landed with `grep -l "<some new string>" js/*.mjs`, not by the file size.
- **Restart the watcher** (`"$D" compose restart frontend`) after adding an entry to `vite.config.js`, and after adding dependencies.
- **Missing new files:** if a component was imported before its file existed, the watcher reports "Could not resolve" once and then never notices the file. `touch` the importing file to trigger a build.
- **Browser cache:** Nextcloud serves `js/*.mjs` without a version suffix, so the browser keeps old code. In the page, run `await fetch('/custom_apps/screenings/js/screenings-<bundle>.mjs', {cache:'reload'}); location.reload()`.
- **Viewer layout:** the public viewer page has to override Nextcloud's fixed `#body-public` / `#content.app-public` frame (already done in `viewer.css`). Remember this when anything there looks clipped or doesn't scroll.

## Backend pitfalls

- **QBMapper has no generic `find()`.** Each mapper defines its own `find()` / `findById()`.
- **Opcache** is set to revalidate on every request in the dev image, so PHP edits apply immediately. If they seem not to, check `php -i | grep revalidate_freq`.
- **Don't flush Redis.** `redis-cli FLUSHALL` also kills PHP sessions, which logs you out, and gives 412 CSRF errors until the page reloads. Only do it to reset `AnonRateLimit` or brute-force counters.
- **`occ` vs container paths:** app files are at `/var/www/html/custom_apps/screenings`, and data at `/var/www/html/data` (appdata is in `data/appdata_*/screenings/`).
- **Routes** are attributes (`#[FrontpageRoute]`), and route names are `screenings.<controller>.<method>`.
- **Remote IP:** the dev Nextcloud sees every request from `172.18.0.1`, because the host comes through the Docker gateway. To test GeoIP or IP-bound logic, temporarily run `occ config:system:set trusted_proxies 0 --value=172.18.0.0/16`, send `X-Forwarded-For`, then delete the setting.

## Browser checks (in-app browser pane)

- **Calling APIs:** owner API calls from the page console need the `requesttoken: OC.requestToken` header. Public viewer POSTs are CSRF-protected too, while stream and events routes aren't.
- **Clicking:** the pane is narrow (about 542×347). Use `find` refs and `scroll_to`, or JS `element.click()`, rather than coordinates. `resize_window` to 1280×900 renders scaled down, so it's only useful for whole-page overviews.
- **Video:** autoplay with sound may be refused. In tests, set `v.muted = true` before `v.play()`.
- **Two viewers:** open a second tab with `tabs_create`. Opening the same invite there ends the first tab's session (`replaced`); that's expected.

## Ready-made test scripts (`scripts/`)

- **`leaktest.sh <invite-token>`:** acts as a viewer with curl (cookie jar) and tries the misuse cases (no token, no cookie, other UA, scripted segment download, faked heartbeat). Expected: 403s, `ahead` at about 92 s. Its last output line prints the cookie, token, session ID and UA, for an ffmpeg test inside the container.
- **`verify-hls.sh <screeningId> <mediaToken>`** (run inside the container after `compose cp` to `/tmp`): checks that segments are unreadable without the key and that every variant decodes with it.
- **`geoip-check.php`** (inside the container): GeoIpService status and lookups against MaxMind's test database.
- **`nc-errors.py`:** filters `nextcloud.log` down to real errors.

To get a viewer page's initial state (including `playback`) with curl:
`grep -o 'id="initial-state-screenings-viewer" value="[^"]*"' | sed 's/.*value="//; s/"$//' | base64 -d`.

## Conventions

- **Code style:** match the existing code (tabs, `declare(strict_types=1)`, a small docblock explaining *why*). The frontend uses Vue 3 `<script setup>` with `@nextcloud/vue` 9 (`variant=` on NcButton, `v-model:open` on NcDialog). Don't use `v-if` on NcDialog with form submit; see the comment in `App.vue`.
- **Verifying and committing:** end each feature with an end-to-end check in the browser plus unit tests. Commit and push to `main`, with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` at the end of the message.
