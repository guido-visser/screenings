"""README screenshots of the demo screening, in headless Chrome.

Run demo-data.py first (it prints the ids and tokens this script reads).
Start Chrome with a throwaway profile, then:

  S=<scratchpad>
  "/c/Program Files/Google/Chrome/Application/chrome.exe" --headless=new --remote-debugging-port=9334 --user-data-dir="$S/chrome-shots" --window-size=1440,1000 --no-first-run --autoplay-policy=no-user-gesture-required --hide-scrollbars about:blank &
  python -I screenshots.py 9334 "$S/demo.json" docs/screenshots [name ...]

The portal is logged in as the demo account through an Authorization
header (DEMO_USER / DEMO_PASSWORD from dev/.env), so no password is typed
anywhere. Daan's session is kept "live" in the dev database for the shots.
The viewer pages are shot first; opening them records real viewing
sessions, so the headless browser's sessions (with their events, watched
ranges and page opens) are removed from the dev database before the portal
shots, which then show only the demo data.
"""
import base64, json, os, socket, struct, subprocess, sys, time, urllib.request

port, demo_file, outdir = int(sys.argv[1]), sys.argv[2], sys.argv[3]
only = set(sys.argv[4:])
DEMO = json.load(open(demo_file, encoding='utf-8'))
DOCKER = r'C:\Program Files\Docker\Docker\resources\bin\docker.exe'
BASE = 'http://localhost:8080/index.php/apps/screenings'


def env():
    values = {}
    for line in open('dev/.env', encoding='utf-8'):
        if '=' in line and not line.lstrip().startswith('#'):
            key, value = line.strip().split('=', 1)
            values[key] = value.strip('"')
    return values


ENV = env()


class WS:
    def __init__(self, ws_url):
        host_port, path = ws_url[len('ws://'):].split('/', 1)
        host, p = host_port.split(':')
        self.s = socket.create_connection((host, int(p)))
        key = base64.b64encode(os.urandom(16)).decode()
        self.s.sendall((f'GET /{path} HTTP/1.1\r\nHost: {host_port}\r\nUpgrade: websocket\r\n'
                        f'Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n').encode())
        resp = b''
        while b'\r\n\r\n' not in resp:
            resp += self.s.recv(4096)
        self.buf = resp.split(b'\r\n\r\n', 1)[1]
        self.id = 0

    def _recv_exact(self, n):
        while len(self.buf) < n:
            chunk = self.s.recv(1 << 20)
            if not chunk:
                raise EOFError
            self.buf += chunk
        out, self.buf = self.buf[:n], self.buf[n:]
        return out

    def recv(self):
        b1, b2 = self._recv_exact(2)
        n = b2 & 0x7f
        if n == 126:
            n = struct.unpack('>H', self._recv_exact(2))[0]
        elif n == 127:
            n = struct.unpack('>Q', self._recv_exact(8))[0]
        return json.loads(self._recv_exact(n))

    def send(self, method, **params):
        self.id += 1
        data = json.dumps({'id': self.id, 'method': method, 'params': params}).encode()
        mask = os.urandom(4)
        header = bytes([0x81])
        n = len(data)
        if n < 126:
            header += bytes([0x80 | n])
        elif n < 65536:
            header += bytes([0x80 | 126]) + struct.pack('>H', n)
        else:
            header += bytes([0x80 | 127]) + struct.pack('>Q', n)
        self.s.sendall(header + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(data)))
        while True:
            msg = self.recv()
            if msg.get('id') == self.id:
                if 'error' in msg:
                    raise RuntimeError(f'{method}: {msg["error"]}')
                return msg.get('result', {})


def js(ws, expression):
    result = ws.send('Runtime.evaluate', expression=f'(async () => {{ {expression} }})()', awaitPromise=True, returnByValue=True)
    if 'exceptionDetails' in result:
        raise RuntimeError(result['exceptionDetails'].get('exception', {}).get('description', result['exceptionDetails']))
    return result.get('result', {}).get('value')


def sql(statement):
    subprocess.run([DOCKER, 'compose', '-f', 'dev/docker-compose.yml', 'exec', '-T', 'db', 'sh', '-c',
                    'mariadb -unextcloud -p"$MARIADB_PASSWORD" nextcloud'], input=statement, text=True, check=True, capture_output=True)


def forget_headless_visits(screening_id):
    """Removes what this script's own visits to viewer links recorded."""
    headless = (f"SELECT s.id FROM oc_scr_sessions s JOIN oc_scr_invites i ON i.id = s.invite_id "
                f"WHERE i.screening_id = {screening_id} AND s.user_agent LIKE '%HeadlessChrome%'")
    sql(f"""
        SET @since = (SELECT MIN(s.started_at) * 1000 FROM oc_scr_sessions s JOIN oc_scr_invites i ON i.id = s.invite_id
                      WHERE i.screening_id = {screening_id} AND s.user_agent LIKE '%HeadlessChrome%');
        DELETE FROM oc_scr_events WHERE screening_id = {screening_id} AND session_id IS NULL AND type = 'page_open' AND server_ts_ms >= @since;
        DELETE FROM oc_scr_events WHERE session_id IN (SELECT id FROM ({headless}) AS h);
        DELETE FROM oc_scr_watch_ranges WHERE session_id IN (SELECT id FROM ({headless}) AS h);
        DELETE FROM oc_scr_sessions WHERE id IN (SELECT id FROM ({headless}) AS h);
    """)


def keep_daan_live(position):
    sql(f"UPDATE oc_scr_sessions SET last_heartbeat_at=UNIX_TIMESTAMP(), playing=1, ended_at=NULL, last_position_s={position} WHERE id={DEMO['daanSession']};")


def shot(ws, name, url, setup='', height=1000, wait=3.5, light=True, owner=True):
    if only and name not in only:
        return
    keep_daan_live(96)
    ws.send('Emulation.setDeviceMetricsOverride', width=1440, height=height, deviceScaleFactor=1, mobile=False)
    ws.send('Emulation.setEmulatedMedia', features=[{'name': 'prefers-color-scheme', 'value': 'light' if light else 'dark'}])
    auth = base64.b64encode(f"{ENV['DEMO_USER']}:{ENV['DEMO_PASSWORD']}".encode()).decode()
    # With basic auth, Nextcloud only accepts the page's own API calls with OCS-APIREQUEST.
    ws.send('Network.setExtraHTTPHeaders', headers={'Authorization': f'Basic {auth}', 'OCS-APIREQUEST': 'true'} if owner else {})
    ws.send('Page.navigate', url=url)
    time.sleep(wait)
    # Whatever Nextcloud shows a new account first (welcome wizard), closed.
    js(ws, "document.querySelectorAll('.modal-container__close, .first-run-wizard button[aria-label=\"Close\"]').forEach(b => b.click()); return true")
    if setup:
        print(name, js(ws, setup))
        time.sleep(1.2)
    data = ws.send('Page.captureScreenshot', format='png')['data']
    path = os.path.join(outdir, name + '.png')
    with open(path, 'wb') as f:
        f.write(base64.b64decode(data))
    print('saved', path)


def main():
    os.makedirs(outdir, exist_ok=True)
    targets = json.load(urllib.request.urlopen(f'http://127.0.0.1:{port}/json'))
    page = next(t for t in targets if t['type'] == 'page')
    ws = WS(page['webSocketDebuggerUrl'])
    ws.send('Page.enable')
    ws.send('Network.enable')
    ws.send('Network.setCacheDisabled', cacheDisabled=True)
    sid = DEMO['screening']
    inv = DEMO['invites']
    anna_note = DEMO['notes']['Anna de Vries'][3]

    # The viewer's page: a viewer's own link, nobody logged in.
    shot(ws, 'viewer-note', f"{BASE}/s/{inv['Anna de Vries']['token']}", height=1000, wait=6, owner=False, light=False, setup="""
        const v = document.querySelector('video');
        v.muted = true;
        [...document.querySelectorAll('button')].find(b => b.textContent.trim() === 'Start over')?.click();
        const sub = document.querySelector('.player__select select');
        if (sub) { sub.value = sub.options[1].value; sub.dispatchEvent(new Event('change', { bubbles: true })); }
        await v.play().catch(() => {});
        const range = document.querySelector('.player__seek input');
        range.value = 245; range.dispatchEvent(new Event('input', { bubbles: true }));
        // Until the frame there is actually decoded and showing.
        for (let i = 0; i < 40 && !(v.currentTime > 244 && v.readyState >= 3); i++) {
            await new Promise(r => setTimeout(r, 250));
        }
        await new Promise(r => setTimeout(r, 1200));
        v.pause();
        await new Promise(r => setTimeout(r, 300));
        document.querySelector('.player').dispatchEvent(new KeyboardEvent('keydown', { key: 'n', bubbles: true }));
        await new Promise(r => setTimeout(r, 400));
        const ta = document.querySelector('#player-note-text');
        ta.value = 'His face is too dark here, I can\\'t tell who he is.';
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        window.scrollTo(0, 0);
        return { t: v.currentTime, box: !!ta };
    """)
    shot(ws, 'viewer-questions', f"{BASE}/s/{inv['Chloé Martin']['token']}", height=1000, wait=6, owner=False, light=False, setup="""
        const v = document.querySelector('video');
        v.muted = true;
        [...document.querySelectorAll('button')].find(b => b.textContent.trim() === 'Start over')?.click();
        await v.play().catch(() => {});
        const range = document.querySelector('.player__seek input');
        range.value = 590; range.dispatchEvent(new Event('input', { bubbles: true }));
        await new Promise(r => setTimeout(r, 2500));
        v.pause();
        document.querySelector('.feedback-bar__button').click();
        await new Promise(r => setTimeout(r, 1500));
        return { open: document.querySelector('.questions-dialog')?.open };
    """)

    forget_headless_visits(sid)


    shot(ws, 'dashboard', f'{BASE}/#/screenings/{sid}', height=900, wait=5)
    shot(ws, 'viewer-activity', f"{BASE}/#/screenings/{sid}/viewers/{inv['Anna de Vries']['id']}", height=1000, wait=5)
    shot(ws, 'feedback-notes', f'{BASE}/#/screenings/{sid}/notes/{anna_note}', height=900, wait=7, setup="""
        await new Promise(r => setTimeout(r, 1500));
        const v = document.querySelector('.review-player video');
        return { t: v?.currentTime, card: !!document.querySelector('.review-player__card'), live: document.querySelector('.notes-review__live')?.innerText };
    """)
    shot(ws, 'feedback-answers', f'{BASE}/#/screenings/{sid}/answers', height=1150, wait=5)
    shot(ws, 'invitation-email', f'{BASE}/#/screenings/{sid}/email', height=1000, wait=5, setup="""
        for (let i = 0; i < 40 && !document.querySelector('iframe')?.contentDocument?.body?.innerText; i++) {
            await new Promise(r => setTimeout(r, 250));
        }
        return !!document.querySelector('iframe');
    """)
    shot(ws, 'settings', f'{BASE}/#/screenings/{sid}/settings', height=1000, wait=5, setup="""
        document.querySelector('.questions')?.scrollIntoView({ block: 'start' });
        return true;
    """)


main()
