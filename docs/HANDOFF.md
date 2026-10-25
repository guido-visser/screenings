# Handoff: Screenings (Nextcloud app)

State as of 2026-10-25, `main` at `b53d172` (plus this handoff commit). Repo: https://github.com/guido-visser/screenings

**Before doing anything, read the project skill `.claude/skills/screenings-dev/SKILL.md`.** It has the commands and the Windows/Docker pitfalls that cost a lot of time when rediscovered.

## What the app is

A Nextcloud 35 app for sending tracked private screenings of a film.
- **Owner side:** creates a *screening* (video + subtitles + settings) and *invites* (name + email + personal link, optionally emailed). There's also an optional *open link* where visitors confirm their email with a code.
- **Viewer side:** a standalone dark page with an hls.js player. Every action is tracked, and the owner sees a dashboard with live status, a heatmap of what was watched, and per-viewer timelines.

The original plan (8 phases) is in [PLAN.md](PLAN.md). Some details changed along the way; the decisions below and the README are current. Phases 1–7 are done.

| Phase | What | Commit |
|---|---|---|
| 1 | Scaffold, Docker dev stack | a464b5f |
| 2 | Data model, portal CRUD (screenings, subtitles, invites) | 43744b6, d8f61d3 |
| — | Files app action "Create / Go to screening" | 7111af5, 2045875 |
| 3 | Media pipeline: Direct MP4 probe, Pre-packaged HLS encryption, optional ffmpeg transcode, admin settings | ab8cd44 |
| 4 | Viewer access: public page, password, open link + email code, invitation mails, sessions | b03c426 |
| 5 | Secure streaming (tokens, playhead window, budget, revoke) + player | 2917c17 |
| 6 | Tracking: events, watched ranges, UA parsing, optional GeoIP | 857fe8a |
| 7 | Dashboard: overview, heatmap, viewer table, viewer detail, live | b53d172 |

## Phase 8 (next): hardening

Agreed scope, not started:
1. **Retention job** (daily `TimedJob`): for each screening, delete `scr_events`, `scr_watch_ranges` and `scr_sessions` older than `retention_days`. Also call `OtpMapper::deleteExpired()`. Register it in `appinfo/info.xml` `<background-jobs>`.
2. **Rate limits review:** owner API (no limits yet), heartbeat (none; it's cookie-bound), events (1500 per session per hour, in TrackingService), codes (`AnonRateLimit` 10/15 min per IP plus 3 per email in OtpService). Consider `UserRateLimit` on invite emails, so an owner's account can't be used to spam.
3. **Security review:** run `/security-review` or `/code-review` on the whole app. Focus on the PublicController/StreamController/StreamGuard paths, IDOR on every `/api/*` route (all go through `ScreeningService::get($id, $uid)`), and the CSRF-off stream routes (protected by the SameSite=Strict cookie).
4. **Production packaging:** a build script/Makefile that runs `npm ci && npm run build` and `composer install --no-dev`. Ship only `vendor/maxmind-db/reader`, and exclude `dev/`, `tests/`, `node_modules/`, `src/` and the source maps. The app loads only `vendor/maxmind-db/reader` through its own autoloader (`Application::registerVendorAutoloader`), never `vendor/autoload.php`.
5. **Browser testing still missing:** Firefox, Safari (macOS), iPhone/iPad (ManagedMediaSource via hls.js). Also check the portal in Nextcloud's light theme (the heatmap has light-mode ramp steps, not yet viewed).
6. **Known gaps worth closing:**
   - Open-link visitors who requested a code but never confirmed aren't shown anywhere. Events exist with `invite_id = NULL`, `type otp_requested`, and the email in the payload.
   - Screenings 1 and 3 have `duration_ms = NULL` because they predate the MP4 probe; "Process again" in Settings fixes them.
   - Optional: Playwright end-to-end tests, psalm, l10n extraction.

## Architecture map

```
lib/
  AppInfo/Application.php        Files listener; MaxMind-only autoloader
  Controller/
    PageController               portal page (initial state: deliveryModes)
    ApiController                owner JSON API: screenings, subtitles, invites (send/revoke), by-source, media/reprocess
    DashboardController          /api/screenings/{id}/dashboard|live, /api/invites/{id}/activity
    AdminController              admin-only settings, GeoIP download
    PublicController             /s/{token}: states unavailable|revoked|expired|email|password|not_ready|ready; password; code; verify
    StreamController             /stream/{sid}/(master.m3u8|vN.m3u8|vN_NNNNN.ts|screenings-key|video.mp4|subtitles/{id}.vtt|heartbeat|events)
  Db/                            Screening, Subtitle, Invite, Otp, ViewerSession + mappers (QBMapper; custom find() because QBMapper has none)
  Media/                         Mp4Probe, HlsPlaylist, HlsPackager (all encryption), Transcoder (ffmpeg → plain HLS), MediaProcessor (token-guarded runs), MediaService, MediaStorage (appdata media/<id>-<token>/)
  Stream/                        StreamGuard (cookie + heartbeat + token/signature), PlaybackToken, PlaybackBudget (90 s window, 180 s + 3x budget), StreamService, RangeResponse
  Tracking/                      TrackingService (events + ranges), WatchRanges, UserAgentParser, GeoIpService, GeoIpUpdater
  Dashboard/                     DashboardService, Heatmap
  Service/                       Screening/Invite/Subtitle/Otp/Mail/Session/Access/AdminSettings/EventLogger/DeliveryModes
  BackgroundJob/                 ProcessMediaJob (queued), GeoIpUpdateJob (weekly)
  Command/ProcessMedia           occ screenings:process-media <id> [--force]
  Migration/                     Version1000 (all tables), Version1001 (media_token, media_warning)
src/
  portal/                        App.vue (hash router: router.js), components/ScreeningView (tabs), DashboardView, ViewerDetail, Heatmap, InvitesSection, SubtitlesSection, MediaStatus, HlsHelp
  viewer/                        App.vue (states), components/Player.vue, playback.js (Heartbeat, Tracker, RangeRecorder), viewer.css
  files/main.js                  Files app actions
  admin/                         AdminSettings.vue, GeoIpSettings.vue
tests/Unit/                      63 tests (converter, probe, playlists, tokens, budget, ranges, UA, heatmap)
```

Key decisions that aren't obvious from the code:
- **Encryption:** every HLS segment is encrypted by `HlsPackager` with IV = media sequence. ffmpeg's own HLS encryption isn't used, because it reuses one IV for every segment.
- **Session ending:** `page_leave` does **not** set `ended_at`. `ended_at` only means "replaced by a newer session"; the dashboard decides "live" from heartbeats less than 20 s old.
- **One session cookie per invite:** `scr_sess_<inviteId>`. A second tab overwrites it, and the first tab then gets `replaced`.
- **Password memory:** the "password entered" cookie `scr_access_<inviteId>` is SameSite=Lax (it has to survive clicks from email clients). The session cookie is Strict.
- **Playback tokens** (60 s, header `X-Screening-Token`) are bound to IP + UA. MP4 and VTT URLs use session+UA signatures (`?sig=`) instead, because the browser fetches those itself.
- **GeoIP:** the app's downloaded database lives in `<datadirectory>/screenings-geoip/`, not in app data, because the reader needs a local file path.

## Dev data (local only)

Admin login: see `dev/.env` (user `admin`). Mailpit: http://localhost:8025. The GeoIP test database is configured (mode `file`, `/var/www/html/data/geoip-test/GeoIP2-City-Test.mmdb`). Server transcode is enabled (mode `full`).

| Screening | id | Notes |
|---|---|---|
| Rough cut v3 | 1 | MP4, open link on, watermark |
| test-clip | 3 | MP4, password `film-pass-1`, open link on |
| Multi HLS | 9 | 3-quality HLS, nl subtitle, watermark |
| Long transcode | 15 | 2-min transcoded HLS, used for dashboard/leak tests |
| Ten minutes MP4 | 16 | 600 s MP4 for byte-window tests |
| others (4–8, 10–14) | | probe/transcode test cases incl. error states |

| Invite (viewer link `/apps/screenings/s/<token>`) | screening | token |
|---|---|---|
| Viewer One | 3 | `OHkBPc30RnzuVSOyXaW5e4xLPhgL8hA0` |
| Open Visitor (open link) | 3 | `8cmHcHLMyTeOtsfoJxYhZZvqlkKO0ga0` |
| Player Tester | 9 | `h2iqKDeJeLOsqOlKFcnITPGDW2SNlQmd` |
| Leak Tester | 15 | `nUmTuTOqZvy2EOMeUy5ZfvX2jfJ4LhGO` |
| MP4 Leak | 16 | `T8i1fryhAcV903cATftOzmQfNJimA2pW` |

Test files are in the admin's Files under `/Films` (test-clip.mp4, slow-start.mp4, hevc.mp4, broken.mp4, long-1080p.mp4 (224 MB), ten-minutes.mp4, `Rough cut HLS/`, `Multi HLS/`).

## Working agreements with the user (Guido)

- **Phases:** work in the agreed phases, and test each one end to end in the in-app browser before committing.
- **Commits:** commit and push to `main` at the end of each phase, ending the message with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Reporting:** be explicit about what was tested, what wasn't, and any honest security limits (no DRM; screen recording and a scripted session copy remain possible).
- **Asking first:** ask before anything outward-facing (downloads from the internet, real emails, new external services).
