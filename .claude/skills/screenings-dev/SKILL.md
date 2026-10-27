---
name: screenings-dev
description: Working on the Screenings Nextcloud app in this repo - running the Docker dev stack, tests, builds, browser checks, Mailpit, DB queries, leak tests, deploying to production with one script, and the Windows/Docker pitfalls that waste time. Use before any build, test, debug, verification or deploy step in this project.
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
- **Backslashes in inline Python (`python - <<'EOF'`) get collapsed** in this environment: `\\'` arrives as `'`, `"\\n"` as a real newline, `\S`/`\I` make Python fail with "unicode error", and a failed script writes nothing. This cost several retries per session. Any edit whose text contains a backslash (PHP namespaces `OCA\…`, `\Throwable`, escaped quotes, `\n`, shell line continuations) goes through the **Edit tool**, or a `.py` file written with the **Write tool** using raw strings (`r'''…'''`) and run with `python -I file.py`. Inline heredoc Python only for backslash-free text.
- **Never leave a bare `cat > file`** (without a heredoc) in a command: it waits for stdin until the timeout.
- **`docker compose exec` inside a `while read` loop eats the loop's input**, so only the first row is processed: add `</dev/null` to the docker call.
- **Don't source a script to test one function** (`. deploy.sh` runs it). Extract the function with `sed -n '/^name() {/,/^}/p'` into a scratch file.
- **Paths for `docker compose -f`:** use the relative `dev/docker-compose.yml` from the repo root. An absolute `/c/...` path in a script turns into `C:\c\...` and fails.
- **Printing non-ASCII from host Python** (e.g. "→" in error messages) fails on the Windows console; set `PYTHONIOENCODING=utf-8`.
- **App config is cached briefly** in the web process: after `occ config:app:set`, a request a second later can still see the old value. Wait a few seconds before testing.

## Deploying to production (only when Guido asks)

Never deploy on your own initiative; an earlier "deploy" doesn't cover later changes, and the auto-mode classifier blocks a deploy the current request didn't ask for (it blocks the *whole* command, so never chain commit/push and deploy in one call). When asked, one command does everything, from the repo root, with a few lines of output:

```bash
sh .claude/skills/screenings-dev/scripts/deploy.sh --check              # state of the server; changes nothing
sh .claude/skills/screenings-dev/scripts/deploy.sh                      # build, upload, swap, occ upgrade, enable, check
sh .claude/skills/screenings-dev/scripts/deploy.sh --enable-transcode   # same, and allow video conversion
sh .claude/skills/screenings-dev/scripts/deploy.sh --rollback           # put the previous version back
```

- Server details live in `dev/deploy.env`, which is git-ignored (template: `dev/deploy.env.example`). **Never write host names, users or key paths into tracked files**: the repo is public on GitHub, and a commit containing them was blocked once.
- A deploy takes about 30 s: the build runs on Docker's own disk (volumes `screenings-pkg-build`, `screenings-pkg-npm-cache`) and reuses the last `npm ci` while `package-lock.json` and the Node version are unchanged (about 20 s; a new lockfile adds about 15 s). It used to take about 8 minutes, all of it in `npm ci` and the build over the Windows file share. Never point the package build at the dev stack's `node_modules` (its `npm install` can drift from the lockfile). A package of the same clean commit is reused outright; running `package.sh` by hand drops that stamp. To check a build change, compare checksums of every file against a known package (`find . -type f | sort | xargs sha256sum`): the build is reproducible, so they should be identical. Keep `timeout: 600000` anyway, for a first build after Docker images change.
- Expected output: `build`, `upload`, `install`, then `app: <new> (was <old>), enabled: yes, maintenance: false, conversion enabled: …`, and `check: viewer script 200, portal 303`. Anything else: look at `build/package.log` (build) or run `--check`.
- The previous app folder is kept as `~/screenings-backup` on the server (`--rollback` restores it). Data and settings live in the database and app data, so swapping folders keeps them.
- After deploying, check the result in the browser pane only if the change needs it (the owner's own invite link plays a film; it records a viewing session for him, so say so).
- **Production quirks** (no host names here; they're in `dev/deploy.env` and local memory):
  - Addresses without `/index.php/` reach Nextcloud only through the web server's error-page fallback: right content, status **404** (POST: 405). That's not an app bug. Checks must use `OC.generateUrl('/apps/screenings/api')`, which includes `/index.php`.
  - The *web* PHP runs under `open_basedir` (site folder and `/tmp` only); cron's CLI PHP doesn't. File checks on `/usr/bin/…` fail on the web side, but running programs works (not with `/dev/null` as stdin, though). Reproduce locally with `php -d open_basedir=/var/www/html:/tmp` (`scripts/ffmpeg-detect.php`).
  - It has intl, Redis, system cron (5 min), ffmpeg 4.2 at `/usr/bin`, and a PHP setting with spaces (`php -d memory_limit=512M`; deploy.sh quotes it).
- **Read-only checks on production** go through the signed-in browser pane tab: `fetch(OC.generateUrl('/apps/screenings/api') + '/…', {headers: {requesttoken: OC.requestToken}})`. Don't save, send or create anything there unless asked.
- To test changes to `deploy.sh` itself, point `DEPLOY_CONFIG` at a config with `DEPLOY_CONTAINER=<name>` of a throwaway container (see the package check under "Ready-made test scripts"), with `DEPLOY_NC_DIR="/var/www/html"`, `DEPLOY_APPS_DIR="custom_apps"`, `DEPLOY_PHP="php"`, `DEPLOY_URL="http://localhost:8090"`.

## Everyday commands (from `dev/`)

```bash
D="/c/Program Files/Docker/Docker/resources/bin/docker.exe"
"$D" compose up -d                                   # stack: nextcloud, cron, db, redis, mailpit, frontend (watcher)
"$D" compose exec -T -u www-data nextcloud sh -c 'cd custom_apps/screenings && for f in $(find lib -name "*.php"); do php -l "$f" | grep -v "^No syntax"; done'   # lint
"$D" compose run --rm --entrypoint php composer vendor/bin/phpunit -c tests/phpunit.xml   # unit tests (repo mounted at /app; no intl here)
"$D" compose exec -T -u www-data nextcloud sh -c 'cd custom_apps/screenings && php vendor/bin/phpunit -c tests/phpunit.xml --filter SubtitleFileName'   # tests that need intl
"$D" compose exec -T -u www-data nextcloud cat data/nextcloud.log | python ../.claude/skills/screenings-dev/scripts/nc-errors.py 2026-10-25T21:00   # new server errors
"$D" compose exec -T -u www-data nextcloud php occ upgrade   # after bumping <version> in appinfo/info.xml (migrations, new background jobs)
"$D" compose exec -T -u www-data nextcloud php occ screenings:process-media <id> --force
"$D" compose exec -T -u www-data nextcloud sh -c 'for i in 1 2 3; do php -f cron.php; done'   # run queued jobs now (one pass may not reach a new job)
"$D" compose exec -T db sh -c 'mariadb -unextcloud -p"$MARIADB_PASSWORD" nextcloud -e "SELECT …"'   # tables are oc_scr_*
```

- **Mailpit:**
  - Newest message: `curl -s "http://localhost:8025/api/v1/messages?limit=1"`.
  - One-time codes are in the email subject, so extract them with `re.search(r'(\d{6})', subject)`.
  - Full message, including `HTML` and `Text` parts: `/api/v1/message/<ID>`.
  - The dev admin's email is `admin@example.test` (needed for "Send a test to me").
- **Composer packages:** `"$D" compose run --rm composer <cmd>`, then `composer dump-autoload` after editing `composer.json`. `nextcloud/ocp` (stubs) and `NCU` are mapped in `autoload-dev`, so PHPUnit can mock OCP interfaces.

## Frontend build: pitfalls that already cost time

- **The watcher polls.** File events from Windows don't reach the container, so it polls (`CHOKIDAR_USEPOLLING`). Rebuilds take about 10 s. Confirm a build landed with `grep -l "<some new string>" js/*.mjs`, not by the file size. Code is split into chunks (`api-*.chunk.mjs`, `Player-*.chunk.mjs`, …), so grep **all** of `js/*.mjs`, not just the entry bundle. Check `"$D" compose logs --tail 5 frontend` for build errors.
- **Restart the watcher** (`"$D" compose restart frontend`) after adding an entry to `vite.config.js`, and after adding dependencies.
- **Missing new files:** if a component was imported before its file existed, the watcher reports "Could not resolve" once and then never notices the file. `touch` the importing file to trigger a build.
- **Browser cache:** Nextcloud serves `js/*.mjs` without a version suffix, so the browser keeps old code, chunks included. In the page, reload every loaded chunk, then the page: `for (const c of performance.getEntriesByType('resource').map(e => e.name).filter(n => n.includes('/js/'))) await fetch(c, {cache: 'reload'}); await fetch('/custom_apps/screenings/js/screenings-<bundle>.mjs', {cache: 'reload'}); location.reload()`. A new chunk name appearing in `performance` entries shows the new build is loaded.
- **Vue templates and `{{placeholders}}`:** a string containing `{{` or `}}` inside a template expression breaks Vue's parser. Build such strings in `<script setup>` (see `MailTemplateEditor.vue`, `OPEN`/`CLOSE`).
- **Nextcloud file picker:** without `setButtonFactory`/`addButton` it has no confirm button at all. `setFilter()` also receives folders, so let them through. `setMimeTypeFilter` accepts wildcards like `video/*`.
- **Viewer layout:** the public viewer page has to override Nextcloud's fixed `#body-public` / `#content.app-public` frame (already done in `viewer.css`). Remember this when anything there looks clipped or doesn't scroll.

## Backend pitfalls

- **QBMapper has no generic `find()`.** Each mapper defines its own `find()` / `findById()`.
- **Opcache** is set to revalidate on every request in the dev image, so PHP edits apply immediately. If they seem not to, check `php -i | grep revalidate_freq`.
- **Don't flush Redis.** `redis-cli FLUSHALL` also kills PHP sessions, which logs you out, and gives 412 CSRF errors until the page reloads. Only do it to reset `AnonRateLimit` or brute-force counters.
- **`occ` vs container paths:** app files are at `/var/www/html/custom_apps/screenings`, and data at `/var/www/html/data` (appdata is in `data/appdata_*/screenings/`).
- **Routes** are attributes (`#[FrontpageRoute]`), and route names are `screenings.<controller>.<method>`.
- **Remote IP:** the dev Nextcloud sees every request from `172.18.0.1`, because the host comes through the Docker gateway. To test GeoIP or IP-bound logic, temporarily run `occ config:system:set trusted_proxies 0 --value=172.18.0.0/16`, send `X-Forwarded-For`, then delete the setting.
- **Brute-force protection in NC 35 doesn't sleep.** It counts attempts and answers 429 once an IP passes 10 misses in 30 minutes, for every route of that action (valid links too). Testing it blocks your own host: reset with `occ security:bruteforce:reset 172.18.0.1`. Inspect with `occ security:bruteforce:attempts 172.18.0.1 <action>`.
- **Owner API from curl:** basic auth with the admin credentials from `dev/.env` plus the header `OCS-APIREQUEST: true` passes the CSRF check, so `curl -u "$NC_ADMIN_USER:$NC_ADMIN_PASSWORD" -H 'OCS-APIREQUEST: true' …/apps/screenings/api/…` works. Source `dev/.env` with `set -a; . dev/.env; set +a`.
- **Second accounts for IDOR tests:** `occ user:add --password-from-env` with `-e OC_PASS=…` on `compose exec`. Deleting such users afterwards leaves harmless "Backends provided no user object" errors from core's metadata job in the log.
- **Background jobs on demand:** `occ background-job:list --class '<FQCN>'` shows the id, `occ background-job:execute --force-execute <id>` runs it now.
- **Direct MP4 duration:** `occ screenings:process-media` skips Direct MP4. To re-probe one, use `POST /api/screenings/<id>/media/reprocess` ("Process again").
- **Light theme:** the admin account uses Nextcloud's "default" theme, which follows the OS. Test light/dark with the browser pane's `resize_window` `colorScheme`, not by changing the user's theming setting.
- **File events** (`NodeCreated/Written/Renamed/CopiedEvent`) fire for WebDAV and web uploads, not for files written straight to disk plus `files:scan`. Test listeners with curl WebDAV (`PUT -T -`, `MOVE`/`COPY` with a `Destination:` header) as in `scripts/subtitle-events-test.sh`. The app's own app-data files fire these events too, so filter on `/<user>/files/` paths.
- **Making test videos:** `ffmpeg -nostdin` (without it, ffmpeg waits for input) from `/Films/test-clip.mp4`; MXF needs `-ar 48000`. Then `occ files:scan --path=/admin/files/Films`. Nextcloud has no MIME type for `.mxf` (it's `application/octet-stream`).

## Browser checks (in-app browser pane)

- **Calling APIs:** owner API calls from the page console need the `requesttoken: OC.requestToken` header. Public viewer POSTs are CSRF-protected too, while stream and events routes aren't.
- **Clicking:** the pane is narrow (about 542×347). Use `find` refs and `scroll_to`, or JS `element.click()`, rather than coordinates. `resize_window` to 1280×900 renders scaled down, so it's only useful for whole-page overviews.
- **Video:** autoplay with sound may be refused. In tests, set `v.muted = true` before `v.play()`.
- **Two viewers:** open a second tab with `tabs_create`. Opening the same invite there ends the first tab's session (`replaced`); that's expected.
- **No real fullscreen in the pane:** `requestFullscreen` is refused ("Permissions check failed"). Use `scripts/fullscreen-check.py` with headless Chrome instead.
- **Sizes:** `resize_window` with a custom size renders tiny screenshots. Measure with JS (`getBoundingClientRect`, `scrollWidth` vs `clientWidth`) instead. The `mobile` preset is good for phone-width checks; reset with `desktop`.
- **Racing the server:** to reproduce a race (e.g. a seek before its heartbeat arrives), wrap `window.fetch` in the page to delay `/heartbeat` calls by a few seconds.

## Ready-made test scripts (`scripts/`)

- **`leaktest.sh <invite-token>`:** acts as a viewer with curl (cookie jar) and tries the misuse cases (no token, no cookie, other UA, scripted segment download, faked heartbeat). Expected: 403s, `ahead` at about 92 s. Its last output line prints the cookie, token, session ID and UA, for an ffmpeg test inside the container.
- **`budget-reload.sh <invite-token>`** (repo root): scripted Direct MP4 download with faked heartbeats that reloads the page on every refusal. Use a 600 s MP4 invite (`T8i1f…`, screening 16). Expected: about 180 s of media (plus 3× the seconds the run took), then 429 in every new session. Since 0.4.3 the server cuts each answer to at most 20 s of film; the script counts what it actually receives. The bucket refills in 60 s, so wait a minute before playing that invite in the browser.
- **`verify-hls.sh <screeningId> <mediaToken>`** (run inside the container after `compose cp` to `/tmp`): checks that segments are unreadable without the key and that every variant decodes with it.
- **`geoip-check.php`** (inside the container): GeoIpService status and lookups against MaxMind's test database.
- **`nc-errors.py`:** filters `nextcloud.log` down to real errors. "Cannot write into apps directory" after `occ upgrade` and "Backends provided no user object" are dev noise.
- **`subtitle-events-test.sh`** (repo root): automatic subtitles via real WebDAV events, 7 steps with expected results printed.
- **`fullscreen-check.py`**: real fullscreen of the player in headless Chrome (usage in its header; throwaway profile only).
- **`ffmpeg-detect.php`**: what the app's ffmpeg detection finds, optionally under a production-like `open_basedir`.
- **`demo-data.py` + `screenshots.py`** (repo root): the README demo ("Night Ferry", account `mira`, credentials `DEMO_USER`/`DEMO_PASSWORD` in `dev/.env`) and its screenshots in headless Chrome, written to `docs/screenshots/`. Usage in their headers. Things learned making them:
  - Basic auth on page loads works for the portal only with `OCS-APIREQUEST: true` on every request; without it the page's own API calls get 401.
  - Opening viewer links records real sessions; `screenshots.py` shoots the viewer pages first and then deletes the headless sessions, events, ranges and page opens.
  - Each invite has a playback budget: loading the same viewer link many times in a row gets 429s and "Playback stopped". Wait a minute between runs on one invite.
  - Before 0.4.3 a very low-bitrate MP4 (plain gradients, ~160 kbit/s) ran into the budget after one jump. The demo film is padded to 2.5 Mbit/s anyway, like a real screener; film grain at a fixed quality instead makes files of many GB.
- **Test fixtures in the dev admin's Files:** `/Films/auto-tests/` (safe.mp4, h264.mkv, pcm.mov, prores.mov, tenbit.mp4, dnxhr.mxf; Auto screenings 19–25), `ten-minutes.*.srt` next to `ten-minutes.mp4` (incl. a trailer and a broken file), `moov-at-end.mp4` (screening 18).
- **`build/package.sh`** (repo root, not in `scripts/`): builds the release tarball. To check a package, run the dev image standalone with SQLite (`docker run -d --name scr-pkgtest -e SQLITE_DATABASE=nc -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=… -e NEXTCLOUD_TRUSTED_DOMAINS=localhost -p 8090:80 screenings-dev-nextcloud`), extract the tarball into its `custom_apps/`, `occ app:enable screenings`, then remove the container.

To get a viewer page's initial state (including `playback`) with curl:
`grep -o 'id="initial-state-screenings-viewer" value="[^"]*"' | sed 's/.*value="//; s/"$//' | base64 -d`.

## Conventions

- **Code style:** match the existing code (tabs, `declare(strict_types=1)`, a small docblock explaining *why*). The frontend uses Vue 3 `<script setup>` with `@nextcloud/vue` 9 (`variant=` on NcButton, `v-model:open` on NcDialog). Don't use `v-if` on NcDialog with form submit; see the comment in `App.vue`.
- **Verifying and committing:** end each feature with an end-to-end check in the browser plus unit tests. Commit and push to `main`, with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` at the end of the message.
