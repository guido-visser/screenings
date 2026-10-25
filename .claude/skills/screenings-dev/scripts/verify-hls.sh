#!/bin/sh
# Usage: verify-hls.sh <screeningId> <token>
# Checks that segments are encrypted (unreadable on their own) and that the
# whole stream decodes once the key placeholder points at key.bin.
set -e
dir=$(ls -d /var/www/html/data/appdata_*/screenings/media/"$1-$2")
echo "== $dir"
ls "$dir" | head -20
echo "files: $(ls "$dir" | wc -l)"
echo "== master.m3u8"; cat "$dir/master.m3u8"
first=$(ls "$dir" | grep '\.ts$' | head -1)
echo "== raw segment $first without key (expect failure):"
ffprobe -v error -show_entries stream=codec_name -of csv=p=0 "$dir/$first" 2>&1 | head -2 || true
work=$(mktemp -d)
for p in "$dir"/*.m3u8; do
  sed "s#screenings-key#$dir/key.bin#; s#^\(v[0-9]*_[0-9]*\.ts\)#$dir/\1#; s#^\(v[0-9]*\.m3u8\)#$work/\1#" "$p" > "$work/$(basename "$p")"
done
echo "== decode every variant with the key:"
for v in "$work"/v*.m3u8; do
  printf "%s: " "$(basename "$v")"
  ffmpeg -v error -allowed_extensions ALL -protocol_whitelist file,crypto,data -i "$v" -f null - 2>&1 | head -3
  ffprobe -v error -allowed_extensions ALL -protocol_whitelist file,crypto,data -show_entries format=duration:stream=codec_name,height -of compact=p=0 "$v" 2>&1 | tr '\n' ' '
  echo
done
rm -rf "$work"
