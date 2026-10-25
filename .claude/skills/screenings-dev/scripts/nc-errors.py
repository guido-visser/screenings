"""Print Nextcloud log entries of level >= 3 (error), newest last, minus known noise.

Usage (from dev/):
  "$D" compose exec -T -u www-data nextcloud cat data/nextcloud.log | python ../.claude/skills/screenings-dev/scripts/nc-errors.py [since-iso-time]
"""
import json
import sys

since = sys.argv[1] if len(sys.argv) > 1 else ''
NOISE = ('Cannot write into "apps" directory',)

for line in sys.stdin:
    try:
        entry = json.loads(line)
    except ValueError:
        continue
    if entry.get('level', 0) < 3 or entry.get('time', '') < since:
        continue
    exc = entry.get('exception') or {}
    message = exc.get('Message') or entry.get('message', '')
    if any(n in message for n in NOISE):
        continue
    where = f" {exc.get('File')}:{exc.get('Line')}" if exc.get('File') else ''
    print(entry.get('time'), entry.get('url'), message[:300] + where)
