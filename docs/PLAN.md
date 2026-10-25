# Plan: "Screenings": a Nextcloud app for tracked private film screenings

## Context
You want to send confidential screenings of a film project and see exactly how each recipient interacts with them: how often they open the link, how far they watch, what they skip or re-watch, which subtitle they use, and when they leave. No open-source tool does this. Building it as a Nextcloud app gives you storage, users, mail and a UI framework for free. The project folder is empty, so this starts from scratch.

Decisions made with you:
- **Structure:** a *Screening* (one film + subtitles + settings + optional password) holds many *Invites*. Each invite has a name, an email and a personal link, and can be emailed from the app. Each screening also has one **open link**. Anyone using it must enter a name and email and confirm the email with a one-time code (OTP), which creates an invite for them.
- **Video:** the source stays in your Nextcloud Files. Each screening picks a **delivery mode**, so ffmpeg on the server is optional (see *Media pipeline*). No paid DRM.
- **Protection:** no download, right-click blocked, short-lived signed URLs and keys tied to the viewer session, IP and a live heartbeat. Instant revoke. Optional name/email watermark overlay that drifts slowly at low opacity.
- **Link rules:** optional password, expiry date, instant revoke, email OTP for the open link.
- **Dashboard:** event log, watch heatmap/timeline, live "watching now" with current position and the subtitle selected, device/browser/location.
- **Privacy:** a short notice on the viewer page, with no acceptance step. Data is deleted after a retention period you set per screening.
- **Platform:** Nextcloud 35.0.2 on your own server (Docker/AIO).

**Honest limit:** without DRM, nobody can be fully stopped from capturing the film. Pasting a playlist URL from devtools into ffmpeg *will fail*, because tokens expire within seconds, rotate, and are bound to the session cookie, IP and heartbeat. Segments are also encrypted. But someone who records their screen, or writes a custom script that copies the player's whole session, can still get the film. The watermark exists so that any leak traces back to a person.

## Architecture

App id `screenings`, at `custom_apps/screenings/`. PHP backend using the Nextcloud OCP APIs (QBMapper entities, migrations, background jobs, IMailer, IHasher, IAppData). Vue 3 with `@nextcloud/vue` for the portal. A separate, lightweight viewer bundle uses hls.js and custom controls.

### Data model (`lib/Migration/Version1000Date…php`)
| Table | Key columns |
|---|---|
| `scr_screenings` | id, owner_uid, title, source_file_id, transcode_status/progress/error, duration_s, password_hash (nullable), expires_at, watermark (bool), open_link_token, open_link_enabled, retention_days, created_at |
| `scr_subtitles` | id, screening_id, lang, label, is_default, appdata path (.srt is converted to .vtt on upload) |
| `scr_invites` | id, screening_id, token (32+ random bytes, base62), name, email, source (`invited` / `open_link`), email_verified_at, invited_at, revoked_at, created_at |
| `scr_otp` | email, screening_id, code_hash, expires_at, attempts |
| `scr_sessions` | id, invite_id, session_secret_hash, started_at, last_heartbeat_at, ended_at, ip, user_agent, browser, os, device_type, screen_w/h, country, city, last_position_s, playing (bool), current_subtitle, current_quality |
| `scr_events` | id, session_id, type, position_s, payload (json), client_ts, server_ts |
| `scr_watch_ranges` | session_id, start_s, end_s (merged intervals of actually played time, used for the heatmaps) |

### Media pipeline: delivery modes (`lib/Service/Media/*`, one `MediaSource` interface)
A screening gets `delivery_mode` (a new column on `scr_screenings`). Admin settings has a **"Server transcoding" switch**: off by default, and it can only be turned on when an ffmpeg binary is detected at the configured path. The editor shows only the modes that are available on that server.

1. **Server transcode** (needs ffmpeg + cron; strongest and most convenient). When a screening is created or its source is changed, a `QueuedJob` runs ffmpeg. It produces 1080p/720p/480p renditions with 4-second segments and AES-128, using a random key per screening. There is an admin sub-option for **"remux only"** (`-c copy`, a single rendition), which uses almost no CPU on weak servers. Progress is parsed from `ffmpeg -progress` into the DB, so the portal shows a progress bar. For AIO, add `NEXTCLOUD_ADDITIONAL_APKS="imagemagick ffmpeg"`. The `occ screenings:transcode <id>` command handles re-runs.
2. **Pre-packaged HLS** (no ffmpeg on the server; the same protection as mode 1). You create HLS on your own computer with a ready-made ffmpeg command or script that the app shows and ships in `docs/`, then upload the folder to Files and pick its `.m3u8`. The app **encrypts the segments itself** with PHP `openssl_encrypt` (AES-128-CBC, the standard HLS `METHOD=AES-128`). It does this in a cheap background job without decoding anything, then writes an encrypted copy and a rewritten playlist.
3. **Direct MP4** (no ffmpeg, no processing; weaker). The MP4 from Files is streamed through a guarded range endpoint. It uses the same cookie, token, heartbeat and revoke checks, and the bytes-ahead-of-playhead window is estimated from the bitrate. There are no encrypted segments, so the portal shows a *lower protection* badge for these screenings. A "Faststart" warning appears if the moov atom is not at the start of the file.

All outputs (HLS copies and keys) live in **IAppData** (`appdata_<id>/screenings/<screeningId>/`), outside every user's Files, so they can never be shared or synced. Mode 3 reads the source directly through the owner's Files node.

### Viewer flow (`lib/Controller/PublicController.php`, `src/viewer/`)
1. `GET /s/{token}` (PublicPage, NoCSRFRequired) renders a minimal standalone page. A `page_open` event is logged for every visit, including before any password is entered.
2. For an open-link token, the viewer enters a name and email. An OTP is mailed via IMailer (rate-limited, 10-minute expiry, 5 attempts). Verifying it creates an `open_link` invite and redirects to that invite's personal URL.
3. If the screening has a password, a password form appears (IHasher, brute-force throttled with `#[BruteForceProtection]`).
4. On success, a `scr_session` is created and its secret is set as an HttpOnly, Secure, SameSite=Strict cookie scoped to `/apps/screenings/`. Only one session per invite can play at a time. A new session ends the old one.
5. The player page shows the privacy notice, the hls.js player, a subtitle menu and a quality menu. If the screening has the watermark enabled, an overlay shows the viewer's name and email.

### Secure streaming (`lib/Controller/StreamController.php`, `lib/Service/TokenService.php`)
- **Playback token:** an HMAC-SHA256 over (session_id, ip, ua_hash, exp), with a TTL of about 60 seconds. It is fetched and rotated through the heartbeat endpoint and kept only in JS memory.
- **Playlists:** `GET /stream/{sid}/master.m3u8` and `/stream/{sid}/{rendition}.m3u8` are generated per request. Every segment and key URI in them is rewritten with its own short-expiry signature. The playlists themselves need the cookie plus the playback token, which hls.js sends as a header through `xhrSetup`.
- **Key endpoint** (`/stream/{sid}/key`) is the strictest. It needs a valid cookie, a valid token, a matching IP, a heartbeat within the last 15 seconds, and a screening that is not revoked or expired. Without the key, the segments are useless.
- **Segment endpoint:** checks the signature, the session and the "ahead-of-playhead" window. A segment is refused if it is more than about 60 seconds ahead of the last reported position, which blocks bulk scraping at download speed.
- **Revoke and expiry** are checked on every request. The heartbeat response carries `revoked: true`, so the player stops and shows a message within about 5 seconds.
- Responses carry `Cache-Control: no-store` and `Content-Disposition: inline`. The `<video>` element gets `controlsList="nodownload"`, `disablePictureInPicture`, a blocked context menu, and no native controls.

### Tracking (`src/viewer/tracker.js`, `lib/Controller/TrackingController.php`)
- Events captured: `page_open`, `password_ok/fail`, `otp_requested/verified`, `player_ready`, `play`, `pause`, `seeked` {from,to}, `ended`, `ratechange`, `volumechange`/mute, `fullscreen_enter/exit`, `subtitle_change` {from,to}, `quality_change`, `buffering_start/end`, `error`, `visibility_hidden/visible`, `window_blur/focus`, `page_leave` (sent with `pagehide` and `navigator.sendBeacon`).
- **Heartbeat** every 5 seconds: position, playing state, subtitle, quality and volume. This updates the live fields on `scr_sessions`, extends the playback token, and appends to `scr_watch_ranges`. Gaps between heartbeats and seeks break the ranges, so the heatmap shows what was actually played.
- Events are queued client-side and sent in batches (POST `/track`, authenticated by the session cookie). If a send fails, it is retried.
- Device data is parsed server-side from the User-Agent and screen size. Location uses an optional local MaxMind GeoLite2 database: you add a free licence key in admin settings, and if there is none, the field shows "unknown".

### Portal (`src/portal/`, `lib/Controller/ApiController.php`, registered in the Nextcloud app navigation)
- **Screenings list:** title, transcode status, number of invites, number currently watching (live dot), total views.
- **Screening editor:** pick the source file with the Nextcloud FilePicker, upload or pick subtitles, and set the password, expiry, watermark toggle, open-link toggle with copy-link, and retention days.
- **Invites panel:** add invites (name + email), with optional *send invitation email* (IMailer with Nextcloud's EMailTemplate, including your message, link, and password hint). Copy link, resend, and **revoke** are available for each invite.
- **Screening dashboard:** a table of viewers (name, email, source, opens, sessions, % of the film watched, furthest point, last seen, live status). An aggregated heatmap shows which parts everyone watched or skipped.
- **Viewer detail:** a live card (watching now, current position on a timeline bar, current subtitle, playing or paused), a per-session list with device, browser and location, a per-viewer heatmap, and a full event timeline that can be filtered by type.
- **Live updates:** the portal polls a lightweight `/api/screenings/{id}/live` endpoint every 5 seconds while a dashboard is open. This avoids depending on notify_push. The heatmap is a custom SVG bar with no chart library.

### Housekeeping
- A `TimedJob` (daily) deletes events, ranges and sessions older than each screening's `retention_days`, and cleans up expired OTPs.
- A `TimedJob` (every minute) marks sessions as ended when no heartbeat arrives for 30 seconds.
- Deleting a screening deletes its HLS appdata and all of its tracking data.

## Build phases
1. **Scaffold and dev environment:** generate the skeleton (Nextcloud app generator, NC 35 compatible `info.xml`), use a Docker dev setup (`nextcloud:35` + MariaDB + Mailpit for catching mail) with the app mounted in `custom_apps`, and set up Vite builds for the two bundles (`portal`, `viewer`). Before writing code, check the NC 35 OCP API changes against the developer docs.
2. **Data model and portal CRUD:** migrations, entities and mappers, screenings and invites API, editor UI, FilePicker, subtitle upload and SRT→VTT conversion.
3. **Media pipeline:** the `MediaSource` interface, Direct MP4 mode first (it works on every server), then Pre-packaged HLS with PHP encryption, then the optional Server transcode mode. Admin settings: transcoding switch, ffmpeg path and detection, GeoIP key.
4. **Viewer access:** public page, password, open link with OTP, invite emails, session cookie.
5. **Secure streaming:** TokenService, rewritten playlists, key and segment endpoints, playhead window, revoke handling, player with hls.js and custom controls, watermark overlay.
6. **Tracking:** tracker.js, heartbeat, events and watch ranges, device and GeoIP parsing.
7. **Dashboard:** viewer table, heatmaps, event timeline, live polling.
8. **Hardening:** retention jobs, rate limits, tests, security review.

## Verification
- **PHPUnit:** TokenService (expiry, IP/UA binding, tampering), access checks (revoked, expired, wrong password, stale heartbeat), watch-range merging, SRT→VTT conversion, OTP throttling.
- **Playwright end-to-end tests against the Docker instance:** open an invite link, enter the password, play, seek, switch subtitles, pause and close the tab. Then confirm the dashboard shows the correct events, heatmap and live status. Run the open-link flow with an OTP read from Mailpit.
- **Delivery modes:** run each mode once on a server **without** ffmpeg (modes 2 and 3 must work and mode 1 must be hidden) and once with ffmpeg enabled.
- **Leak tests (manual and scripted):**
  - Copy the `.m3u8` URL from devtools into `ffmpeg -i …`. Expected: 401/403.
  - Replay with copied cookies after 60 seconds, or from another IP. Expected: refused.
  - Fetch segments far ahead of the playhead. Expected: refused.
  - Revoke during playback. Expected: playback stops within about 5 seconds.
- **Browsers:** Chrome, Firefox, Edge and Safari (macOS and iOS; hls.js on desktop, native HLS on iOS Safari, checking that header and token passing still works there, with a cookie plus query-token fallback).
