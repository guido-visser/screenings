#!/usr/bin/env bash
# Leak tests against the dev instance. Usage: leaktest.sh <invite-token>
# Opens the screening like a browser (curl + cookie jar), then misuses what a
# viewer could copy out of devtools.
set -u
BASE=http://localhost:8080
INVITE=$1
UA="Mozilla/5.0 (LeakTest) Chrome/140"
JAR=$(mktemp)

page=$(curl -s -c "$JAR" -A "$UA" "$BASE/apps/screenings/s/$INVITE")
state=$(printf '%s' "$page" | grep -o 'id="initial-state-screenings-viewer" value="[^"]*"' | sed 's/.*value="//; s/"$//' | base64 -d)
read -r SID TOKEN SRC < <(printf '%s' "$state" | python -c "import sys,json; p=json.load(sys.stdin)['playback']; print(p['sessionId'], p['token'], p['src'])")
COOKIE=$(awk '$6 ~ /^scr_sess_/ {print $6"="$7}' "$JAR")
STREAM="$BASE/apps/screenings/stream/$SID"
echo "session $SID, src $SRC"
echo

code() { curl -s -o /dev/null -w '%{http_code} ' "$@"; }
body() { curl -s "$@"; }
heartbeat() { curl -s -b "$JAR" -A "$UA" -H 'Content-Type: application/json' -d "{\"position\": $1, \"playing\": true}" "$STREAM/heartbeat"; }

echo "A. Copied playlist URL, nothing else (what ffmpeg -i <url> does):"
echo "   $(code -A "$UA" "$STREAM/master.m3u8")$(body -A "$UA" "$STREAM/master.m3u8")"
echo "B. With the session cookie but no token header:"
echo "   $(code -b "$JAR" -A "$UA" "$STREAM/master.m3u8")$(body -b "$JAR" -A "$UA" "$STREAM/master.m3u8")"
echo "C. With the token but no cookie:"
echo "   $(code -A "$UA" -H "X-Screening-Token: $TOKEN" "$STREAM/master.m3u8")"
echo "D. Cookie + token, different User-Agent:"
echo "   $(code -b "$JAR" -A "ffmpeg/7.1" -H "X-Screening-Token: $TOKEN" "$STREAM/master.m3u8")"
echo "E. Cookie + token, same User-Agent (the legitimate player request):"
echo "   $(code -b "$JAR" -A "$UA" -H "X-Screening-Token: $TOKEN" "$STREAM/master.m3u8")"
echo "F. The decryption key without a token:"
echo "   $(code -b "$JAR" -A "$UA" "$STREAM/screenings-key")"
echo

echo "G. Scripted download with cookie + token, same IP and UA, no heartbeats:"
got=0
for i in $(seq 0 40); do
  seg=$(printf 'v0_%05d.ts' "$i")
  status=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -A "$UA" -H "X-Screening-Token: $TOKEN" "$STREAM/$seg")
  if [ "$status" != 200 ]; then
    echo "   segment $i ($((i * 4))s): $status $(curl -s -b "$JAR" -A "$UA" -H "X-Screening-Token: $TOKEN" "$STREAM/$seg")"
    break
  fi
  got=$((got + 1))
done
echo "   downloaded $got segments (~$((got * 4))s of 120s) before being refused"
echo

echo "H. Faking a heartbeat at 100s to jump ahead, then fetching around 100s:"
hb=$(heartbeat 100); echo "   heartbeat: ${hb:0:60}"; TOKEN=$(printf "%s" "$hb" | python -c "import sys,json; print(json.load(sys.stdin).get('token', ''))")
echo "   segment 25 (100s): $(code -b "$JAR" -A "$UA" -H "X-Screening-Token: $TOKEN" "$STREAM/v0_00025.ts")"
echo

echo "I. ffmpeg inside the server container with the copied cookie AND token (different IP):"
echo "$COOKIE|$TOKEN|$SID|$UA"
