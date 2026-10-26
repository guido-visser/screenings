# Handoff: Screenings (Nextcloud app)

State as of 2026-10-26, after phase 8 (hardening). Repo: https://github.com/guido-visser/screenings

**Before doing anything, read the project skill `.claude/skills/screenings-dev/SKILL.md`.** It has the commands and the Windows/Docker pitfalls that cost a lot of time when rediscovered.

## What the app is

A Nextcloud 35 app for sending tracked private screenings of a film.
- **Owner side:** creates a *screening* (video + subtitles + settings) and *invites* (name + email + personal link, optionally emailed). There's also an optional *open link* where visitors confirm their email with a code.
- **Viewer side:** a standalone dark page with an hls.js player. Every action is tracked, and the owner sees a dashboard with live status, a heatmap of what was watched, and per-viewer timelines.

The original plan (8 phases) is in [PLAN.md](PLAN.md). Some details changed along the way; the decisions below and the README are current. All 8 phases are done; what's left is under "Open items".

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
| 8 | Hardening: retention job, rate limits, security fixes, packaging, unconfirmed visitors | (this commit) |

## Phase 8: what was done

- **Retention:** `BackgroundJob/RetentionJob` (daily) deletes sessions (by last heartbeat), their ranges and events, and screening events older than `retention_days`, plus expired OTPs. Logic in `TrackingCleanupService::deleteOlderThan()`.
- **Rate limits:** invitation mails 100/owner/hour (`InviteService::MAIL_LIMIT`, via `ILimiter`; the create-and-send path still creates the invite and returns `mailError`). `UserRateLimit` on create screening (30/h), reprocess (30/h), create invite (300/h). Full table in the README.
- **Security review** (done by hand, not with `/security-review`):
  - Fixed: unknown `/s/{token}` links now count for brute-force protection (`screenings_link`).
  - Fixed: deleting a Nextcloud user now deletes their screenings (`Listener/UserDeletedListener`). Before, packaged HLS stayed streamable through old invite links, and viewer data was kept.
  - Verified with a second account: every `/api/*` route answers 404 (admin routes 403); a screening can't reference another user's file.
  - Checked and fine: session/access/playback token HMACs with `hash_equals`, subtitle ownership in the stream path, HLS playlist paths (no URLs, no climbing out), mail templates escape names/messages, no `v-html`.
  - Accepted, low risk: anyone with an open link can make the server send code emails to any address (3 per address per 15 min, 10 per IP). An attacker could try to guess a code for someone else's address to watch under their name: 5 guesses per code, 3 codes per 15 min, plus brute-force throttling per IP. `authorizeTracking` accepts events for any session of the same invite (needed for `page_leave` from replaced tabs).
- **Packaging:** `build/package.sh` → `build/screenings-<version>.tar.gz`. Verified by installing it in a throwaway Nextcloud container: migrations, both jobs, MaxMind autoloader, portal and API all work.
- **Dashboard:** "Asked for a code, not confirmed" section (`DashboardService::pendingVisitors`). New code requests also log the visitor's name.
- **Heatmap** now follows OS light/dark switches live (the "default" theme changes no DOM attribute). Light theme checked for dashboard and settings.
- Screenings 1 and 3 re-probed, so they have durations now.

## After phase 8 (2026-10-26)

- **Running in production** on Guido's own Nextcloud (NC 35, no ffmpeg). It's updated by building with `build/package.sh`, replacing the app folder and running `occ upgrade`. Access details are not kept in this repo.
- **Fixed: Direct MP4 with the index at the end didn't play** for long films. Chrome reads the index from a 64 KB-aligned offset just before it, which the playhead window refused (`ahead`). `StreamService::indexReadEnd()` now allows a start up to 1 MB before the index and cuts the response off at its end. Short test files never showed this, because their end falls inside the window anyway.
- **Fixed: far seeks in a Direct MP4 broke playback.** The browser fetches the new position before the seek's heartbeat reaches the server, gets `ahead`, and a plain `<video>` never retries. The player now reports far seeks (> 60 s) first and then moves (`seekTo`), and if a range is refused anyway it reloads the source at the same spot (`onVideoError`, up to 3 times). To reproduce the race locally, delay `/heartbeat` fetches in the page by about 3 s.
- **Tracking notice per screening** (`tracking_notice_enabled`, `tracking_notice`; `Service/TrackingNotice`). Default text "Your viewing behaviour is tracked."; null means the default. Used on the viewer page and in the invitation email.

## Open items

1. **Browser testing not possible here:** Firefox, Safari (macOS), iPhone/iPad. The in-app browser is Chromium only. Points to watch: hls.js with ManagedMediaSource on iOS 17.1+, older iOS falling back to native HLS (which can't send the `X-Screening-Token` header, so it will fail), the SameSite=Strict cookie on Safari, and `sendBeacon` on page leave.
2. **App store:** signing (`occ integrity:sign-app`) needs a certificate from Nextcloud; `<bugs>` in info.xml is still a placeholder URL.
3. **Optional:** Playwright end-to-end tests, psalm, l10n extraction, a per-day cap on code emails per address.

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
  BackgroundJob/                 ProcessMediaJob (queued), GeoIpUpdateJob (weekly), RetentionJob (daily)
  Listener/                      Files app scripts; UserDeletedListener (deletes the owner's screenings)
  Command/ProcessMedia           occ screenings:process-media <id> [--force]
  Migration/                     Version1000 (all tables), Version1001 (media_token, media_warning)
src/
  portal/                        App.vue (hash router: router.js), components/ScreeningView (tabs), DashboardView, ViewerDetail, Heatmap, InvitesSection, SubtitlesSection, MediaStatus, HlsHelp
  viewer/                        App.vue (states), components/Player.vue, playback.js (Heartbeat, Tracker, RangeRecorder), viewer.css
  files/main.js                  Files app actions
  admin/                         AdminSettings.vue, GeoIpSettings.vue
build/package.sh                 release tarball
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
| Moov at end | 18 | 600 s MP4 with the index at the end (`/Films/moov-at-end.mp4`); invite token `vmo2oh1c9wcOeVrgKExWN2OJH2iC2QBl` |
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
