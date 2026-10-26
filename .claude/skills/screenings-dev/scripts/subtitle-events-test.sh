#!/bin/sh
# End-to-end test of subtitles that follow their files (SubtitleFileListener).
# Uses WebDAV, so Nextcloud fires its real file events. Run from the repo root:
#   sh .claude/skills/screenings-dev/scripts/subtitle-events-test.sh
# Creates /Films/autosub with a copy of /Films/auto-tests/safe.mp4 and a new
# screening "Auto subtitles"; expected results are printed next to each step.
set -eu
D="/c/Program Files/Docker/Docker/resources/bin/docker.exe"
set -a; . dev/.env; set +a
A="$NC_ADMIN_USER:$NC_ADMIN_PASSWORD"
DAV="http://localhost:8080/remote.php/dav/files/$NC_ADMIN_USER/Films/autosub"
API=http://localhost:8080/apps/screenings/api
H='OCS-APIREQUEST: true'

dav() { curl -s -o /dev/null -w "%{http_code}" -u "$A" "$@"; }
srt() { printf '1\n00:00:01,000 --> 00:00:04,000\n%s\n' "$1"; }
subs() {
	curl -s -u "$A" -H "$H" "$API/screenings/$ID" | python -c "
import sys, json
print('   ', [(s['lang'], s['label'], 'file' if s['fromFile'] else 'upload') for s in json.load(sys.stdin)['subtitles']])"
}
# The stored (converted) text of every subtitle; docker reads stdin, hence </dev/null.
texts() {
	"$D" compose -f dev/docker-compose.yml exec -T db sh -c "mariadb -N -unextcloud -p\"\$MARIADB_PASSWORD\" nextcloud -e 'SELECT lang, file_name FROM oc_scr_subtitles WHERE screening_id=$ID'" </dev/null | tr -d '\r' |
	while read -r lang file; do
		printf '    %s: %s\n' "$lang" "$(MSYS_NO_PATHCONV=1 "$D" compose -f dev/docker-compose.yml exec -T -u www-data nextcloud sh -c "cat /var/www/html/data/appdata_*/screenings/subtitles/$file" </dev/null | grep -v -e '-->' -e WEBVTT -e '^[0-9]*$' | grep . | head -1)"
	done
}

dav -X DELETE "$DAV" >/dev/null || true
dav -X MKCOL "$DAV" >/dev/null
dav -X COPY -H "Destination: $DAV/movie.mp4" "http://localhost:8080/remote.php/dav/files/$NC_ADMIN_USER/Films/auto-tests/safe.mp4" >/dev/null
ID=$(curl -s -u "$A" -H "$H" -H 'Content-Type: application/json' -X POST "$API/screenings" -d '{"title":"Auto subtitles","sourcePath":"/Films/autosub/movie.mp4"}' | python -c "import sys,json;print(json.load(sys.stdin)['id'])" | tr -d '\r')
echo "screening $ID (expect no subtitles)"; subs
echo "1. upload movie.en.srt (expect English, file)"; srt 'First version' | dav -T - "$DAV/movie.en.srt" >/dev/null; subs
echo "2. upload a corrected movie.en.srt (expect the text 'Corrected')"; srt 'Corrected' | dav -T - "$DAV/movie.en.srt" >/dev/null; texts
echo "3. rename to movie.nl.srt (expect Nederlands instead of English)"; dav -X MOVE -H "Destination: $DAV/movie.nl.srt" "$DAV/movie.en.srt" >/dev/null; subs
echo "4. copy to movie.de.srt (expect Deutsch added)"; dav -X COPY -H "Destination: $DAV/movie.de.srt" "$DAV/movie.nl.srt" >/dev/null; subs
echo "5. delete movie.de.srt (expect Deutsch kept)"; dav -X DELETE "$DAV/movie.de.srt" >/dev/null; subs
echo "6. upload movie-trailer.en.srt (expect no change)"; srt 'Trailer' | dav -T - "$DAV/movie-trailer.en.srt" >/dev/null; subs
srt 'Hand made' > /tmp/hand.srt
curl -s -o /dev/null -u "$A" -H "$H" -F file=@/tmp/hand.srt -F lang=en -F label=English "$API/screenings/$ID/subtitles"
echo "7. hand upload English, then upload movie.en.srt (expect en text 'Hand made')"; srt 'From the file' | dav -T - "$DAV/movie.en.srt" >/dev/null; texts
echo "Clean up: delete screening $ID in the portal and /Films/autosub in Files."
