"""Demo data for README screenshots, in the dev stack only.

Builds "Night Ferry" for the demo account (DEMO_USER / DEMO_PASSWORD in
dev/.env): the video and subtitles uploaded by WebDAV, a screening with
closing questions, five fictional viewers, notes and answers through the
viewer routes, and a viewing history (sessions, watched ranges, events)
written straight into the dev database, because real playback would take
the film's length. Prints the ids and tokens the screenshot script needs.

  python -I demo-data.py <video.mp4>     (from the repo root)

The video: ten minutes of soft colour fields, made in the nextcloud
container at a fixed, screener-like 2.5 Mbit/s (192 MB). Plain gradients
compress to almost nothing, and a very low-bitrate MP4 runs into the
playback budget (see HANDOFF), hence the padded constant bitrate. Film
grain looks nice but makes files of many GB.

  ffmpeg -nostdin -f lavfi -i "gradients=s=1280x720:r=24:d=600:speed=0.004:c0=0x0b1d3a:c1=0x1f4e79:c2=0xd98c3a:c3=0x6a2c4f:n=4:type=radial"
    -f lavfi -i "sine=f=110:d=600:sample_rate=48000" -filter:a volume=0.05
    -c:v libx264 -preset veryfast -b:v 2500k -minrate 2500k -maxrate 2500k -bufsize 5000k
    -x264-params nal-hrd=cbr -pix_fmt yuv420p -c:a aac -b:a 96k -movflags +faststart night-ferry.mp4
  (one command; split here for reading)

Run it once on a fresh demo account; it doesn't clean up earlier runs.
"""
import base64, http.cookiejar, json, subprocess, sys, time, urllib.parse, urllib.request

BASE = 'http://localhost:8080/index.php'
DOCKER = r'C:\Program Files\Docker\Docker\resources\bin\docker.exe'
UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'


def env():
    values = {}
    for line in open('dev/.env', encoding='utf-8'):
        if '=' in line and not line.lstrip().startswith('#'):
            key, value = line.strip().split('=', 1)
            values[key] = value.strip('"')
    return values


ENV = env()
AUTH = 'Basic ' + base64.b64encode(f"{ENV['DEMO_USER']}:{ENV['DEMO_PASSWORD']}".encode()).decode()


def owner(method, path, body=None, raw=None, headers=None):
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    req = urllib.request.Request(BASE + path, data=data, method=method, headers={
        'Authorization': AUTH, 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json', **(headers or {})})
    with urllib.request.urlopen(req) as resp:
        text = resp.read().decode()
        return json.loads(text) if text.strip().startswith(('{', '[')) else text


def dav(method, path, data=None):
    url = f"{BASE.replace('/index.php', '')}/remote.php/dav/files/{ENV['DEMO_USER']}{urllib.parse.quote(path)}"
    req = urllib.request.Request(url, data=data, method=method, headers={'Authorization': AUTH})
    try:
        urllib.request.urlopen(req).read()
    except urllib.error.HTTPError as e:
        if e.code != 405:  # MKCOL on an existing folder
            raise


def sql(statement):
    out = subprocess.run([DOCKER, 'compose', '-f', 'dev/docker-compose.yml', 'exec', '-T', 'db', 'sh', '-c',
                          'mariadb -N -unextcloud -p"$MARIADB_PASSWORD" nextcloud'],
                         input=statement, capture_output=True, text=True, check=True)
    return out.stdout.strip()


class Viewer:
    """A browser opening an invite link: cookie jar, session id."""

    def __init__(self, token):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        html = self.opener.open(urllib.request.Request(f'{BASE}/apps/screenings/s/{token}', headers={'User-Agent': UA})).read().decode()
        marker = 'id="initial-state-screenings-viewer" value="'
        state = json.loads(base64.b64decode(html.split(marker, 1)[1].split('"', 1)[0]))
        self.playback = state['playback']
        self.session = self.playback['sessionId']

    def call(self, method, url, body):
        # Routes from the player config are server-relative (dev serves them without /index.php).
        req = urllib.request.Request('http://localhost:8080' + url, data=json.dumps(body).encode(), method=method,
                                     headers={'User-Agent': UA, 'Content-Type': 'application/json'})
        return json.loads(self.opener.open(req).read().decode())


def main(video):
    # --- Files: the film and two subtitle files next to it -------------------
    dav('MKCOL', '/Films')
    dav('MKCOL', '/Films/Night Ferry')
    with open(video, 'rb') as f:
        dav('PUT', '/Films/Night Ferry/night-ferry.mp4', f.read())
    cues = [
        (14, 18, 'The last ferry leaves at midnight.', 'De laatste veerboot vertrekt om middernacht.'),
        (95, 99, 'You came back.', 'Je bent teruggekomen.'),
        (248, 252, 'Who told you I would be here?', 'Wie heeft je verteld dat ik hier zou zijn?'),
        (512, 517, 'Some crossings only go one way.', 'Sommige overtochten gaan maar één kant op.'),
    ]
    for lang, index in (('en', 2), ('nl', 3)):
        srt = ''.join(f'{i + 1}\n00:{a // 60:02d}:{a % 60:02d},000 --> 00:{b // 60:02d}:{b % 60:02d},000\n{c[index]}\n\n'
                      for i, c in enumerate(cues) for a, b in [(c[0], c[1])])
        dav('PUT', f'/Films/Night Ferry/night-ferry.{lang}.srt', srt.encode())

    # --- The screening -----------------------------------------------------
    screening = owner('POST', '/apps/screenings/api/screenings', {
        'title': 'Night Ferry',
        'description': 'Rough cut v4, for the editing team and a few trusted first viewers. Please watch it in one go if you can, and leave a note whenever something catches your eye.',
        'deliveryMode': 'auto',
        'sourcePath': '/Films/Night Ferry/night-ferry.mp4',
    })
    sid = screening['id']
    owner('PUT', f'/apps/screenings/api/screenings/{sid}', {'watermark': True})
    owner('PUT', f'/apps/screenings/api/screenings/{sid}/questions', {'questions': [
        {'kind': 'rating', 'label': 'How did you like the film overall?', 'required': True},
        {'kind': 'choice', 'label': 'Was the ending clear?', 'options': ['Yes', 'Somewhat', 'No']},
        {'kind': 'text', 'label': 'What would you change first?'},
    ]})
    # A second, older screening so the list isn't a single item.
    owner('POST', '/apps/screenings/api/screenings', {
        'title': 'Night Ferry (festival trailer)', 'deliveryMode': 'auto', 'sourcePath': '/Films/Night Ferry/night-ferry.mp4'})

    people = [
        # name, email, notes, questions, history
        ('Anna de Vries', 'anna@example.test', True, True, 'full'),
        ('Bram Jansen', 'bram@example.test', True, True, 'partial'),
        ('Chloé Martin', 'chloe@example.test', True, True, 'full'),
        ('Daan Smit', 'daan@example.test', True, True, 'live'),
        ('Eva Kowalski', 'eva@example.test', False, False, None),
    ]
    invites = {}
    for name, email, notes, questions, _ in people:
        invite = owner('POST', f'/apps/screenings/api/screenings/{sid}/invites', {
            'name': name, 'email': email, 'sendEmail': True, 'allowNotes': notes, 'askQuestions': questions})
        invites[name] = invite

    viewers = {name: Viewer(invites[name]['token']) for name, *_ , history in people if history}

    # --- Notes and answers through the viewer routes ----------------------
    notes = {
        'Anna de Vries': [(12.5, 'Opening shot is beautiful. Could it hold two seconds longer?'),
                          (95.0, 'The cut to the deck feels abrupt.'),
                          (131.0, 'Love the sound design here.'),
                          (248.0, 'Who is the man at the railing? I lost track of him.'),
                          (512.0, 'The music swells too early in the ending.')],
        'Bram Jansen': [(97.5, 'Same as Anna: this cut is jarring.'),
                        (250.5, 'Colour shift between these two shots.'),
                        (402.0, 'Pacing drags from here on.')],
        'Chloé Martin': [(33.0, "Title card typo: 'Ferrry'?"),
                         (515.0, 'The last shot made me cry. Keep it exactly like this.'),
                         (588.0, 'Credits run too fast to read.')],
    }
    note_ids = {}
    for name, items in notes.items():
        viewer = viewers[name]
        for position, body in items:
            note = viewer.call('POST', viewer.playback['notesUrl'], {'position': position, 'body': body})
            note_ids.setdefault(name, []).append(note['id'])
    answers = {
        'Anna de Vries': {1: 4, 2: 'Somewhat', 3: 'Make the man at the railing easier to recognise. A close-up earlier on would do it.'},
        'Bram Jansen': {1: 3, 2: 'No', 3: 'Tighten the middle part, around minute 7.'},
        'Chloé Martin': {1: 5, 2: 'Yes', 3: 'Nothing big. Fix the title card and slow the credits down.'},
    }
    questions = owner('GET', f'/apps/screenings/api/screenings/{sid}/questions')
    for name, values in answers.items():
        viewer = viewers[name]
        mapped = {str(questions[i - 1]['id']): v for i, v in values.items()}
        viewer.call('PUT', viewer.playback['questions']['url'].replace('/questions', '/answers'), {'answers': mapped})

    # --- Viewing history, straight into the dev database --------------------
    now = int(time.time())
    history = {
        # name: (started minutes ago, ranges, last position, city, country, browser, os, device, screen)
        'Anna de Vries': (26 * 60, [(0, 600), (120, 185)], 600, 'Amsterdam', 'NL', 'Chrome', 'macOS', 'desktop', (2560, 1440)),
        'Bram Jansen': (5 * 60, [(0, 410)], 410, 'Utrecht', 'NL', 'Firefox', 'Windows', 'desktop', (1920, 1080)),
        'Chloé Martin': (3 * 60, [(0, 230), (300, 600)], 600, 'Lyon', 'FR', 'Safari', 'iOS', 'tablet', (1180, 820)),
        'Daan Smit': (2, [(0, 96)], 96, 'Rotterdam', 'NL', 'Edge', 'Windows', 'desktop', (1920, 1200)),
    }
    for n, (name, (ago_min, ranges, position, city, country, browser, os_name, device, (w, h))) in enumerate(history.items()):
        sess = viewers[name].session
        start = now - ago_min * 60
        live = name == 'Daan Smit'
        last = now if live else start + int(sum(b - a for a, b in ranges) * 1.1)
        sql(f"""UPDATE oc_scr_sessions SET started_at={start}, last_heartbeat_at={last}, last_position_s={position},
            playing={1 if live else 0}, ip='203.0.113.{20 + n}', country='{country}', city='{city}', browser='{browser}', os='{os_name}',
            device_type='{device}', screen_w={w}, screen_h={h} WHERE id={sess};
            DELETE FROM oc_scr_watch_ranges WHERE session_id={sess};""")
        sql(''.join(f'INSERT INTO oc_scr_watch_ranges (session_id, start_s, end_s) VALUES ({sess}, {a}, {b});' for a, b in ranges))
        # Move the events this run created to when the viewing happened.
        sql(f"UPDATE oc_scr_events SET server_ts_ms = server_ts_ms - {(now - start) * 1000 - 60000} WHERE session_id={sess} OR (invite_id={invites[name]['id']} AND session_id IS NULL);")
        events = [('player_ready', None, {'screen': [w, h], 'mode': 'mp4'}), ('play', 0, None), ('fullscreen_enter', 4, None),
                  ('subtitle_change', 10, {'from': None, 'to': 'en'})]
        if name == 'Anna de Vries':
            events += [('pause', 248, None), ('seeked', 185, {'from': 185, 'to': 120}), ('play', 120, None), ('ended', 600, None)]
        elif name == 'Bram Jansen':
            events += [('visibility_hidden', 300, None), ('visibility_visible', 330, None), ('pause', 410, None), ('page_leave', 410, None)]
        elif name == 'Chloé Martin':
            events += [('seeked', 230, {'from': 230, 'to': 300}), ('ended', 600, None)]
        for i, (etype, pos, payload) in enumerate(events):
            ts = (start + 70 + i * (60 if not live else 10)) * 1000
            sql("INSERT INTO oc_scr_events (session_id, invite_id, screening_id, type, position_s, payload, server_ts_ms) VALUES "
                f"({sess}, {invites[name]['id']}, {sid}, '{etype}', {'NULL' if pos is None else pos}, "
                f"{'NULL' if payload is None else repr(json.dumps(payload))}, {ts});")

    print(json.dumps({
        'screening': sid,
        'invites': {name: {'id': i['id'], 'token': i['token']} for name, i in invites.items()},
        'notes': note_ids,
        'daanSession': viewers['Daan Smit'].session,
    }, indent=1))


main(sys.argv[1])
