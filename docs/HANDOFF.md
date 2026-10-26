# Handoff: Screenings (Nextcloud app)

**State on 2026-10-26:** version **0.3.12** in `main`, deployed to Guido's Nextcloud (NC 35.0.2) on 2026-10-26. `main` is pushed and clean. Repo: https://github.com/guido-visser/screenings

**Start here:** read the project skill `.claude/skills/screenings-dev/SKILL.md` before building, testing or deploying. It has the commands, the deploy script, the production quirks and the Windows/Docker pitfalls that cost a lot of time when rediscovered. User-facing behaviour is documented in the [README](../README.md); the original 8-phase plan is in [PLAN.md](PLAN.md) (done, partly superseded).

## What the app is

A Nextcloud app for sending tracked private screenings of a film.

- **Owner:** creates a *screening* (a video in their Files, subtitles, settings) and *invites* (name, email, personal link, optionally emailed). An optional *open link* lets visitors in after confirming their email with a code. A dashboard shows live status, a heatmap of what was watched, per-viewer timelines, and open-link visitors who never confirmed.
- **Viewer:** a standalone dark page with an hls.js player. Every action is tracked. Media is only served to the open session (cookie, heartbeat, short-lived tokens, a playhead window and a speed budget).
- **Video:** *Auto* (default) streams browser-ready MP4/MOV directly and converts everything else with ffmpeg (repackage, audio only, or full H.264 in 1080/720/480p). Also *Direct MP4*, *Pre-packaged HLS* and *Server transcode*. All HLS is encrypted by the app.
- **Subtitles:** uploaded by hand, or picked up from files next to the video (`film.en.srt`, `film.nl.forced.srt`, …), automatically on create, on a video change, and whenever such a file is added, changed, copied or renamed.
- **Invitation email:** HTML the owner designs in the portal (a default plus optional per-screening designs), with `{{placeholders}}`, sections, live preview and test sends.
- **Privacy:** an optional, editable tracking notice per screening; data retention per screening (daily job); everything is deleted with its invite, screening or owner.

## Open items, in order

1. **Guido still has to confirm on his server** (none of these can be fully tested from here):
   - **Fullscreen controls** on Chrome 156/Windows (2560×1440). He reported them missing; they work in headless Chrome (`scripts/fullscreen-check.py`). Hardening shipped in 0.3.6: controls show on entering, stay while hovered, wake on any pointer. If still broken, ask whether moving the mouse brings them back.
   - **A real conversion** (e.g. ProRes or MKV) with the server's ffmpeg 4.2. That version supports every option the app uses (checked), but no full conversion has run there yet.
   - **An automatically added subtitle** next to Bloedtranen (`/RENDERS`), and **an invitation in his own design**.
2. **Browsers not testable here:** Firefox, Safari (macOS), iPhone/iPad. Watch hls.js with ManagedMediaSource on iOS 17.1+; older iOS falls back to native HLS, which can't send the `X-Screening-Token` header and will fail. Also watch the SameSite=Strict cookie on Safari, `sendBeacon` on page leave, and iPhone's native fullscreen (it gets native controls).
3. **App store:** signing (`occ integrity:sign-app`) needs a certificate from Nextcloud; `<bugs>` in info.xml is a placeholder.
4. **Optional:** a designable one-time-code email, Playwright end-to-end tests, psalm, l10n extraction, a per-day cap on code emails per address, pretty URLs on the server (hosting setting, see the skill).

## Architecture map

```
lib/
  AppInfo/Application.php        listeners (Files scripts, user deleted, subtitle file events); MaxMind-only autoloader
  Controller/
    PageController               portal page (initial state: deliveryModes)
    ApiController                owner API: screenings, subtitles (+ import from the video's folder), invites, by-source, media/reprocess
    MailTemplateController       /api/mail-template (GET/PUT/DELETE, /preview, /test)
    DashboardController          /api/screenings/{id}/dashboard|live, /api/invites/{id}/activity
    AdminController              admin-only settings, GeoIP download
    PublicController             /s/{token}: states unavailable|revoked|expired|email|password|not_ready|ready; password; code; verify
    StreamController             /stream/{sid}/(master.m3u8|vN.m3u8|vN_NNNNN.ts|screenings-key|video.mp4|subtitles/{id}.vtt|heartbeat|events)
  Db/                            Screening, Subtitle, Invite, Otp, ViewerSession, MailTemplate + mappers (QBMapper has no generic find())
  Media/                         Mp4Probe (index, codecs, H.264 profile), HlsPlaylist, HlsPackager (all encryption), Transcoder (plan(): remux|audio|full),
                                 Ffmpeg (detection, also under open_basedir), ProcessRunner, MediaProcessor (token-guarded runs), MediaService (prepare/prepareAuto), MediaStorage
  Stream/                        StreamGuard, PlaybackToken, PlaybackBudget (90 s window; per-invite bucket of 180 s, refilled at 3x), StreamService (incl. indexReadEnd), RangeResponse
  Tracking/                      TrackingService, WatchRanges, UserAgentParser, GeoIpService, GeoIpUpdater
  Dashboard/                     DashboardService (incl. pendingVisitors), Heatmap
  Mail/                          TemplateRenderer (placeholders, sections, toText, clean), MailTemplateService (resolve: screening → default → built-in), default-invitation.html
  Service/                       Screening, Invite, Subtitle(+Converter, FileName, Importer), Otp, Mail, Session, Access(+Token), TrackingNotice,
                                 TrackingCleanup, DeliveryModes, AdminSettings, EventLogger, TokenGenerator
  BackgroundJob/                 ProcessMediaJob (queued), GeoIpUpdateJob (weekly), RetentionJob (daily)
  Listener/                      LoadFilesScripts, UserDeleted (deletes the owner's screenings and designs), SubtitleFile (file events)
  Command/ProcessMedia           occ screenings:process-media <id> [--force]
  Migration/                     1000 all tables · 1001 media_token/warning · 1002 tracking notice · 1003 media_mode · 1004 subtitle source_file_id · 1005 mail templates
src/
  portal/                        App.vue (hash router: router.js; #/email = default design), components/ScreeningView (tabs: Dashboard, Settings, Invitation email),
                                 DashboardView, ViewerDetail, Heatmap, InvitesSection, SubtitlesSection, MediaStatus, MailTemplateEditor, HlsHelp
  viewer/                        App.vue (states), components/Player.vue, PrivacyNotice.vue, playback.js (Heartbeat, Tracker, RangeRecorder), viewer.css
  files/main.js                  Files app action "Create / Go to screening" (videos → Auto, .m3u8 → HLS)
  admin/                         AdminSettings.vue, GeoIpSettings.vue
build/package.sh                 release tarball (runtime files only)
tests/Unit/                      88 tests: converter, probe, playlists, tokens, budget, index reads, ranges, UA, heatmap, subtitle names, templates, ffmpeg input formats (these need ffmpeg: run them in the nextcloud container)
```

## Decisions that aren't obvious from the code

- **Encryption:** every HLS segment is encrypted by `HlsPackager` with IV = media sequence. ffmpeg's own HLS encryption isn't used (it reuses one IV).
- **Sessions:** one cookie per invite (`scr_sess_<inviteId>`, SameSite=Strict); a second tab replaces the first. `ended_at` only means "replaced"; "live" is a heartbeat under 20 s old. The "password entered" cookie is SameSite=Lax so it survives clicks from mail apps. The resume point is the `last_position_s` of the invite's most recently active session past 10 s (`SessionService::resumePosition`, read before the new session starts); a new session sitting at 0:00 under the dialog doesn't overwrite it.
- **Tokens:** HLS requests carry a 60 s token (header, bound to IP + UA); MP4 and VTT URLs carry a session+UA signature (`?sig=`), because the browser fetches them itself.
- **Direct MP4 index:** reads of the `moov` box are always allowed, including a start up to 1 MB before it (Chrome reads 64 KB-aligned). Without this, long films with the index at the end never start.
- **Far seeks in Direct MP4:** the player reports the target in a heartbeat *before* moving (seeks over 60 s), and reloads the source if a range is refused anyway. A plain `<video>` never retries a refused range.
- **Auto:** the decision is stored in `media_mode` (`direct` / `remux` / `audio` / `full`); streaming code uses `Screening::servedAs()`, never the raw delivery mode. Without conversion enabled, Auto still plays browser-ready files and explains the rest.
- **Subtitle files:** an imported subtitle remembers its file (`source_file_id`) and only that file updates it. Hand uploads are never overwritten, and deleting a file never removes a subtitle (so viewers don't lose subtitles to an accidental delete). The player selects tracks by id, so several tracks can share a language.
- **Email designs:** placeholder values are always escaped (`{{x}}`, no raw form); scripts and event handlers are stripped; `{{link}}` is required. The preview iframe is `sandbox=""`.
- **GeoIP:** the downloaded database lives in `<datadirectory>/screenings-geoip/`, because the reader needs a local file path.

## Security notes

Reviewed by hand in phase 8 (IDOR on every owner route, token HMACs, stream paths, playlist paths, mail escaping) and kept up since. Accepted, low-risk limits:
- Anyone with an open link can make the server send code emails to any address (3 per address per 15 min, 10 per IP).
- Guessing someone else's code is throttled (5 guesses per code, brute-force protection per IP).
- No DRM: screen recording, or a script on the viewer's own machine that copies the session, can still capture a film (at most about 3× real time); the watermark makes leaks traceable.

Fixed after the review of 2026-10-26:
- **ffmpeg input:** ffmpeg picks a format by content, and hls/concat/image2 read other files by path, so a ".mp4" that is really a playlist could pull in another user's files (ffmpeg 4.2 doesn't refuse this; 7.1 refuses HLS but still opens concat lists). `Ffmpeg::inputOptions()` (`-protocol_whitelist file -format_whitelist <INPUT_FORMATS>`) goes before every `-i`, for ffprobe and ffmpeg. A new container format must be added to `INPUT_FORMATS`.
- **Budget per invite:** it was per session, so a script reloading the page got a fresh 180 s burst each time (`scripts/budget-reload.sh` shows it). Now one bucket per invite, capped at 180 s.

Still open from that review (low risk on a server with few trusted accounts):
- A screening can use a file shared *to* the owner, even a share with downloads disabled.
- Any user can send designed HTML mail from the server's noreply address (100/hour); the sender name is their own display name. `TemplateRenderer::clean` is a weak regex (`<img/onerror=…>` passes), harmless while the design only renders in the sandboxed preview and mail clients.
- The code-attempt counter in `OtpService::verify` isn't atomic; parallel guesses can exceed 5 per code.
- Subtitle files placed next to a video in a shared folder by a collaborator are imported automatically; conversions have no timeout.

## History

| Version / commit | What |
|---|---|
| a464b5f … b53d172 | Phases 1–7: scaffold, data model and portal, media pipeline, viewer access, secure streaming and player, tracking, dashboard |
| fe026bf | Phase 8: retention job, rate limits, security fixes, packaging, unconfirmed visitors |
| d204f4e, 5c972e1 | Long MP4s with the index at the end; far seeks; tracking notice per screening |
| 7101d43 | Auto delivery mode |
| 264d313, 9a1548f | One-command deploy script (and its quoting fix) |
| a2c3e56 | 0.3.6: subtitles next to the video; fullscreen and control bar hardening |
| 2b86700 | 0.3.7: subtitles follow their files; ffmpeg detection under open_basedir |
| 514311e | 0.3.8: invitation email designer; the picker's Choose button |
| 8b34672 | Dev skill lessons and test scripts |
| d5998b4 | 0.3.10: ffmpeg opens only plain media containers; playback budget per invite |
| 7242901 | 0.3.11: "Welcome back" resume dialog in the viewer; playhead also saved when the page is hidden |
| 2a7c435 | 0.3.12: less tracking: no window focus events; tab switches only while the film plays |
| (this commit) | Package build on Docker's own disk with a lockfile-keyed npm ci: deploy ~8 min → ~30 s, identical package |

## Production (details outside the repo)

- Deploy with `sh .claude/skills/screenings-dev/scripts/deploy.sh` (`--check`, `--enable-transcode`, `--rollback`). Server details live in the git-ignored `dev/deploy.env`; host names, users and key paths never go into tracked files (the repo is public).
- Video conversion is **on** there (full mode, ffmpeg 4.2). Bloedtranen (screening 1, Direct MP4, 3.1 GB, index at the end) is the real film; its invite for Guido records viewing sessions when used for tests, so say so.
- Quirks (the `/index.php` 404 fallback, `open_basedir` on the web PHP) are in the skill.

## Dev data (local only)

Admin login: see `dev/.env` (user `admin`, email `admin@example.test`). Mailpit: http://localhost:8025. GeoIP test database configured (mode `file`). Conversion enabled (mode `full`).

| Screening | id | Notes |
|---|---|---|
| Rough cut v3 | 1 | MP4, open link on, watermark |
| test-clip | 3 | MP4, password `film-pass-1`, open link on |
| Multi HLS | 9 | 3-quality HLS, nl subtitle, watermark |
| Long transcode | 15 | 2-min transcoded HLS (dashboard and leak tests) |
| Ten minutes MP4 | 16 | 600 s MP4, five subtitles imported from its folder |
| Moov at end | 18 | 600 s MP4 with the index at the end |
| Auto test clips | 19–25 | one per Auto case: direct (19, now on ten-minutes.mp4), remux (20, MKV), audio (21, PCM MOV), full (22 ProRes, 23 10-bit, 24 HEVC, 25 DNxHR MXF) |
| Subtitle tests | 29–31 | imported and file-event subtitles (`/Films/autosub`) |
| others (4–8, 10–14) | | probe/transcode test cases incl. error states |

| Invite (viewer link `/apps/screenings/s/<token>`) | screening | token |
|---|---|---|
| Viewer One | 3 | `OHkBPc30RnzuVSOyXaW5e4xLPhgL8hA0` |
| Open Visitor (open link) | 3 | `8cmHcHLMyTeOtsfoJxYhZZvqlkKO0ga0` |
| Player Tester | 9 | `h2iqKDeJeLOsqOlKFcnITPGDW2SNlQmd` |
| Leak Tester | 15 | `nUmTuTOqZvy2EOMeUy5ZfvX2jfJ4LhGO` |
| MP4 Leak | 16 | `T8i1fryhAcV903cATftOzmQfNJimA2pW` |
| Moov Tester | 18 | `vmo2oh1c9wcOeVrgKExWN2OJH2iC2QBl` |
| Subtitle Tester | 29 | `eZuvoZN5Y6Sw8DoRgDyIh9R63HzE5t5l` |

Test files are in the admin's Files under `/Films`: test-clip.mp4, slow-start.mp4, hevc.mp4, broken.mp4, long-1080p.mp4 (224 MB), ten-minutes.mp4 (+ `ten-minutes.*.srt`), moov-at-end.mp4, `auto-tests/`, `autosub/`, `Rough cut HLS/`, `Multi HLS/`.

## Working agreements with Guido

- **Testing:** test every change end to end (browser pane, Mailpit, curl) plus unit tests before committing. Report plainly what was tested, what wasn't, and honest security limits.
- **Commits:** commit and push to `main` when a piece of work is done, ending the message with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Deploying:** only when the current request asks for it; an earlier "deploy" doesn't carry over. Keep the deploy as its own command.
- **Asking first:** ask before anything outward-facing (internet downloads, real emails, new external services, changes on his server beyond a requested deploy).
- **His goal:** put a rendered film in Nextcloud as it is and share it. Re-exporting or converting it by hand is not an acceptable fix.
