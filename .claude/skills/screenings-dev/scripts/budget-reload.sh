#!/usr/bin/env bash
# Scripted Direct MP4 download that fakes heartbeats and reloads the page
# whenever it is refused. Usage: budget-reload.sh <invite-token>
set -u
BASE=http://localhost:8080
INVITE=$1
UA="Mozilla/5.0 (BudgetTest) Chrome/140"
JAR=$(mktemp)

open_page() {
  rm -f "$JAR"
  local page state
  page=$(curl -s -c "$JAR" -A "$UA" "$BASE/apps/screenings/s/$INVITE")
  state=$(printf '%s' "$page" | grep -o 'id="initial-state-screenings-viewer" value="[^"]*"' | sed 's/.*value="//; s/"$//' | base64 -d)
  read -r SID SRC < <(printf '%s' "$state" | python -c "import sys,json; p=json.load(sys.stdin)['playback']; print(p['sessionId'], p['src'])" | tr -d '\r')
}

open_page
SIZE=$(curl -s -D - -o /dev/null -b "$JAR" -A "$UA" -H 'Range: bytes=0-1' "$BASE$SRC" | tr -d '\r' | awk -F/ '/^Content-Range/ {print $2}')
BPS=$((SIZE / 600))
# Ask for 2 MB; the server cuts each answer to at most ~20 s of film.
CHUNK=$((2 * 1024 * 1024))
echo "size $SIZE bytes, ~$BPS bytes/s"

offset=$((SIZE / 2))   # past the moov index at the start: counted against the budget
fetched=0
for reload in 0 1 2 3; do
  [ "$reload" -gt 0 ] && open_page
  echo "session $SID"
  while :; do
    pos=$((offset / BPS))
    curl -s -o /dev/null -b "$JAR" -A "$UA" -H 'Content-Type: application/json' -d "{\"position\": $pos, \"playing\": true}" "$BASE/apps/screenings/stream/$SID/heartbeat"
    read -r status got < <(curl -s -o /dev/null -w '%{http_code} %{size_download}' -b "$JAR" -A "$UA" -H "Range: bytes=$offset-$((offset + CHUNK - 1))" "$BASE$SRC")
    if [ "$status" != 206 ]; then
      echo "  refused with $status at ~${pos}s"
      break
    fi
    offset=$((offset + got)); fetched=$((fetched + got))
    [ "$offset" -ge "$SIZE" ] && { echo "  reached the end"; break 2; }
  done
done
echo "total fetched: ~$((fetched / BPS)) s of media"
