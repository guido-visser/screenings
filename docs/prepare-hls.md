# Preparing a film for "Pre-packaged HLS"

Use this delivery mode when your Nextcloud server can't (or shouldn't) run ffmpeg. Convert the film on your own computer, upload the result to Files, and the server only encrypts it, which takes little CPU.

## Requirements

The app accepts HLS that is:

- **VOD**: the playlist ends with `#EXT-X-ENDLIST`
- **MPEG-TS segments** (`.ts`). Fragmented MP4 (`#EXT-X-MAP`) is not supported yet.
- **Unencrypted**: no `#EXT-X-KEY`. The server encrypts every segment with its own AES-128 key.
- **One file per segment**: no byte ranges
- **Relative paths** in the playlists (file names or subfolders), not URLs
- **Audio muxed into the video segments**: no separate audio or subtitle playlists. Add subtitles to the screening instead.

## Single quality

The portal shows this command:

```bash
ffmpeg -i film.mov \
  -c:v libx264 -preset slow -crf 20 -pix_fmt yuv420p \
  -g 96 -keyint_min 96 -sc_threshold 0 \
  -c:a aac -b:a 160k -ac 2 \
  -f hls -hls_time 4 -hls_playlist_type vod -hls_segment_type mpegts \
  -hls_segment_filename "seg_%05d.ts" master.m3u8
```

Run it in an empty folder. `-g 96` assumes 24 fps (a keyframe every 4 seconds); for 25 fps use 100, for 30 fps use 120.

## Several qualities (adaptive streaming)

Viewers on slow connections automatically get a lower quality:

```bash
ffmpeg -i film.mov \
  -filter_complex "[0:v]split=3[a][b][c];[a]scale=-2:1080[v0];[b]scale=-2:720[v1];[c]scale=-2:480[v2]" \
  -map "[v0]" -map 0:a -map "[v1]" -map 0:a -map "[v2]" -map 0:a \
  -c:v libx264 -preset slow -pix_fmt yuv420p -g 96 -keyint_min 96 -sc_threshold 0 \
  -b:v:0 5000k -b:v:1 2800k -b:v:2 1400k \
  -c:a aac -b:a 160k -ac 2 \
  -f hls -hls_time 4 -hls_playlist_type vod -hls_segment_type mpegts \
  -var_stream_map "v:0,a:0 v:1,a:1 v:2,a:2" \
  -master_pl_name master.m3u8 \
  -hls_segment_filename "v%v/seg_%05d.ts" "v%v/index.m3u8"
```

This writes `master.m3u8` plus `v0/`, `v1/` and `v2/` folders.

## Upload and select

1. Upload the whole output folder to Files, keeping its structure.
2. In the screening, choose **Pre-packaged HLS** and pick `master.m3u8`. Alternatively, open the folder in Files and choose **Create screening** on `master.m3u8`.
3. The status changes to *Processing*, then *Ready*. The original upload in Files stays unencrypted. Keep it private, or delete it once the screening is ready.
