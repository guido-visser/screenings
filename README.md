# Screenings

A Nextcloud app (NC 35) for sharing private film screenings with per-viewer tracking: opens, playback progress, pauses, seeks, subtitle choice, and when viewers leave.

## Layout

| Path | What |
|---|---|
| `appinfo/info.xml` | App metadata |
| `lib/` | PHP backend (`OCA\Screenings`); routes are declared with `#[FrontpageRoute]` attributes on controllers |
| `templates/` | `portal.php` (owner UI shell), `viewer.php` (public screening page shell) |
| `src/portal/` | Vue 3 + `@nextcloud/vue` portal, built to `js/screenings-portal.mjs` |
| `src/viewer/` | Public viewer page, built to `js/screenings-viewer.mjs` |
| `dev/` | Docker development stack |

## Development

The only thing you need installed is **Docker Desktop**. Node, Composer, PHP and ffmpeg all run in containers.

```bash
cd dev
cp .env.example .env
docker compose up -d --build
```

- Nextcloud: http://localhost:8080 (log in with the admin credentials from `dev/.env`)
- Mailpit, which catches every email the app sends: http://localhost:8025
- The `frontend` container watches `src/` and rebuilds `js/` on every change (it polls, because file events from a Windows or macOS host don't reach the container). Do a hard refresh (Ctrl+Shift+R) to see changes; the browser caches the app's scripts.
- PHP edits take effect on the next request.
- After adding a bundle to `vite.config.js`, restart the watcher: `docker compose restart frontend`.
- The first start takes a few minutes while Nextcloud installs. Follow progress with `docker compose logs -f nextcloud`.

Useful commands (run from `dev/`):

```bash
docker compose exec -u www-data nextcloud php occ app:list | grep screenings
docker compose exec -u www-data nextcloud php occ log:tail
docker compose run --rm composer install
```

To test a server **without ffmpeg**:

```bash
docker compose build --build-arg SCREENINGS_NO_FFMPEG=1 nextcloud cron && docker compose up -d
```

Pages:
- Portal: http://localhost:8080/index.php/apps/screenings/
- Viewer: http://localhost:8080/index.php/apps/screenings/s/<invite-token>

## Video delivery modes

Each screening picks one; new screenings use Auto. See [docs/prepare-hls.md](docs/prepare-hls.md) for exporting HLS.

| Mode | Server needs | What happens |
|---|---|---|
| **Auto** (default) | nothing for browser-ready MP4s; ffmpeg for the rest | MP4/MOV files every browser plays (H.264 8-bit, AV1 or VP9 with AAC, Opus or FLAC) stream directly, decided at once. Everything else is converted in the background with the cheapest plan that works: **repackage** (H.264 in another container, e.g. MKV), **audio only** (H.264 with PCM or Dolby sound), or **full** (ProRes, DNxHR/MXF, HEVC, 10-bit or 4:2:2 H.264, …). The portal shows what it chose. |
| Direct MP4 | nothing | The MP4's header is checked straight away (duration, browser-playable codecs). Not encrypted. |
| Pre-packaged HLS | nothing | A background job encrypts the uploaded HLS segments (AES-128, one IV per segment) into app data. |
| Server transcode | ffmpeg + ffprobe, enabled in *Administration settings → Screenings* | A background job converts the video with ffmpeg (several qualities, or remux-only), then encrypts it the same way. |

Background jobs run with Nextcloud's cron. To process a screening right away:

```bash
docker compose exec -u www-data nextcloud php occ screenings:process-media <screening-id>
```

Add `--force` to process media that is already ready or failed.

## Subtitles

Subtitle files next to the video are added automatically, the way media players pick them up: `.srt` or `.vtt` files named like the video, plus a language and optional flags. This happens when a screening is created or its video is changed, and whenever such a file is uploaded, changed, copied or renamed later (through the website, the desktop client or WebDAV; files copied straight onto the server's disk count once Nextcloud has scanned them). A changed file replaces its subtitle; viewers get it on their next page load. Deleting a file doesn't remove its subtitle, and subtitles uploaded by hand are never overwritten by a file.

| File next to `film.mp4` | Shows as |
|---|---|
| `film.en.srt`, `film.eng.srt`, `film.English.srt` | English |
| `film.nl.srt`, `film.Nederlands.srt` | Nederlands |
| `film.pt-BR.vtt` | Português (Brasil) |
| `film.en.sdh.srt` (also `.cc`, `.hi`) | English (SDH) |
| `film.nl.forced.srt` | Nederlands (forced) |
| `film.en.default.srt` | English, switched on by default |
| `film.srt` | Subtitles |

Files with any other word after the video's name are ignored (`film-trailer.en.srt` belongs to another video). For existing screenings, use **Add subtitles from the video's folder** in Settings; it only adds what isn't there yet. You can still upload subtitles by hand.

## Tracking and the dashboard

Each screening has a **Dashboard** tab that shows:
- **Totals:** invited, opened the link, started watching, watching now, average watched.
- **Heatmap:** which parts of the film viewers actually played.
- **Viewer table:** live status, how much each person watched, how far they got, how often they opened the link, and their device and location.
- **Unconfirmed visitors:** open-link visitors who asked for an email code but never entered it, with how many codes and wrong attempts.

**Tracking notice:** by default viewers see "Your viewing behaviour is tracked." on the screening page and in the invitation email. Per screening you can change the text or turn the notice off (Settings → Playback and data). Whether you may track without telling viewers depends on your local privacy law.

Click a viewer to see their sessions (device, screen, location, IP), a heatmap of how often they watched each part, and a filterable timeline of everything they did. Live data refreshes every 5 seconds.

**Viewer location** is optional (*Administration settings → Screenings*). It uses a MaxMind GeoLite2/GeoIP2 City database on the server. Behind a reverse proxy, set Nextcloud's [`trusted_proxies`](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/reverse_proxy_configuration.html) correctly, or every viewer shows the proxy's address. The IP-bound playback tokens need it too.

## How playback is protected

Every media request (`/apps/screenings/stream/<session>/…`) must have:

1. **The viewer session cookie**: HttpOnly and SameSite=Strict, set when the screening page opens. A new page load ends the invite's previous session, so one invite plays in one place at a time.
2. **A live page**: the player sends a heartbeat every 5 s. Without one for 30 s, media is refused.
3. **A playback token** (HLS) sent as the `X-Screening-Token` header. It lasts 60 s, is renewed by each heartbeat, and is bound to the session, IP address and User-Agent. Direct MP4 and subtitle URLs carry a signature bound to the session and User-Agent instead.
4. **The playhead window**: nothing more than 90 s past the position the player last reported. The exception is a Direct MP4's index (`moov`), which the player must read first: it may be read at any time, including a read that starts up to 1 MB before it, because browsers read in aligned blocks. That matters for exports with the index at the end, which is what most editors write. On top of that, a speed budget of 3 minutes plus 3× real time per session (needs Redis).

Revoking an invite or reaching the expiry date stops playback within one heartbeat (about 5 s). HLS segments are AES-128 encrypted, and the key is served under the same rules.

**What this does and doesn't stop.** Copying a playlist or video URL from devtools into ffmpeg or a download tool fails (403). So does copying the cookie and token to another machine. A script on the viewer's own machine that copies the session and fakes heartbeats can still pull the film, at about 3× real time at most. Screen recording can't be prevented without DRM. The optional watermark makes leaks traceable to a person.

## Data retention and privacy

- Each screening has a **retention period** (Settings, default 365 days). A daily background job deletes viewer sessions, watched ranges and events older than that; a session counts from its last heartbeat. Invites stay, so the guest list is kept. Expired email codes are deleted by the same job.
- Deleting an invite or a screening deletes its tracking data immediately, and deleting a screening also deletes its encrypted media copy.
- Deleting a Nextcloud user deletes all of their screenings, with invites, tracking data and media.

## Abuse limits

| What | Limit |
|---|---|
| Unknown `/s/<token>` links | Nextcloud brute-force protection per IP: after 10 misses in 30 minutes that IP gets 429 for a while (valid links included) |
| Screening password, email code | Brute-force protection per IP; an email code allows 5 attempts and expires after 10 minutes |
| Requesting email codes | 10 per IP per 15 minutes, and 3 per email address per screening per 15 minutes |
| Invitation emails | 100 per owner per hour |
| Creating screenings, "Process again" | 30 per owner per hour |
| Creating invites | 300 per owner per hour |
| Player events | 1500 per viewer session per hour |

## Building a release

```bash
sh build/package.sh
```

This writes `build/screenings-<version>.tar.gz`, ready to extract into a server's `custom_apps/`. It builds from a clean copy of the repository (tracked and untracked, not ignored files), using local `npm` and `composer` if they are installed and the `node:24-alpine` and `composer:2` Docker images otherwise. The package contains `appinfo/`, `lib/`, `templates/`, `img/`, the production `js/` build without source maps, and only `vendor/maxmind-db/reader` (loaded by the app's own autoloader). For the Nextcloud app store, sign the extracted folder with `occ integrity:sign-app`.
