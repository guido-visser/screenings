# Changelog

All notable changes to Screenings. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the Nextcloud App Store shows the section of each release.

## [0.5.0] - 2026-10-29

### Added
- **Public screenings:** a public link that anyone can watch without giving a name or email address. Every browser becomes an anonymous, numbered visitor ("Visitor 14") and is tracked like an invited viewer: device, location, what they watched and an activity timeline, all counted in the heatmap.
- Dashboard tiles for public views and visitors, and a list of public visitors (live first, then most recent).
- Block or unblock a single public visitor. Switching the public link off, or making a new one, stops everyone who came through it within seconds.
- For public visitors the watermark shows their visitor number and the date.
- New public visitors are limited to 20 per IP address per hour.

## [0.4.3] - 2026-10-27

### Fixed
- Low-bitrate MP4s no longer stop playing after a jump: each piece of film sent to a viewer is now at most 20 seconds long, so it never uses up the speed limit.

## [0.4.2] - 2026-10-26

### Added
- The Notes and Answers pages update live: new, edited and deleted notes and answers appear within seconds, and viewers who are watching show as pins on the timeline.

## [0.4.1] - 2026-10-26

### Added
- **Closing questions:** star ratings, multiple choice or free text, asked when the film ends or via "Give feedback", with answers summarised under Feedback → Answers and downloadable as CSV.

## [0.4.0] - 2026-10-26

### Added
- **Viewer notes:** viewers press N to leave a note at a moment in the film.
- The **Feedback** tab plays the film for the owner with every note as a marker on the timeline. Notes can be exported as CSV or SRT.
- A notification when viewers leave notes.

## [0.3.12] - 2026-10-26

### Changed
- Less tracking: window focus is no longer recorded, and tab switches only while the film plays.

## [0.3.11] - 2026-10-26

### Added
- "Welcome back": returning viewers continue where they left off, or start over.

## [0.3.10] - 2026-10-26

### Changed
- ffmpeg only opens plain media containers.
- The speed limit on streaming belongs to the invite, so reloading the page doesn't reset it.

## [0.3.8] - 2026-10-26

### Added
- Design your own invitation email, with placeholders, a live preview and test sends.

## [0.3.7] - 2026-10-26

### Added
- Subtitles next to the video follow their files: changed files update them automatically.

## [0.3.6] - 2026-10-26

### Added
- Subtitle files next to the video are picked up automatically.

## [0.3.0] - 2026-10-26

### Added
- First complete version:
  - screenings with personal invites, an open link, password and expiry
  - protected streaming (Direct MP4, encrypted HLS, server conversion and the Auto mode)
  - tracking of how each viewer watched, with a dashboard and heatmap
  - retention and abuse limits
