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

## Video delivery modes

Each screening picks one; see [docs/prepare-hls.md](docs/prepare-hls.md) for exporting HLS.

| Mode | Server needs | What happens |
|---|---|---|
| Direct MP4 | nothing | The MP4's header is checked straight away (fast start, duration, browser-playable codecs). Not encrypted. |
| Pre-packaged HLS | nothing | A background job encrypts the uploaded HLS segments (AES-128, one IV per segment) into app data. |
| Server transcode | ffmpeg + ffprobe, enabled in *Administration settings → Screenings* | A background job converts the video with ffmpeg (several qualities, or remux-only), then encrypts it the same way. |

Background jobs run with Nextcloud's cron. To process a screening right away:

```bash
docker compose exec -u www-data nextcloud php occ screenings:process-media <screening-id>
```

Add `--force` to process media that is already ready or failed.
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
- Viewer (stub): http://localhost:8080/index.php/apps/screenings/s/abcdefghijklmnop0123
