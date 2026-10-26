"""Real fullscreen test of the viewer's player in headless Chrome.

The in-app browser pane refuses fullscreen ("Permissions check failed"), so
this drives Chrome through the DevTools protocol (stdlib only, no websocket
library needed). It opens a viewer link, clicks the player's fullscreen
button with a trusted mouse event, and prints the controls' state and saves
screenshots: just entered, after 4 s idle, after moving the mouse.

  S=<scratchpad>
  "/c/Program Files/Google/Chrome/Application/chrome.exe" --headless=new --remote-debugging-port=9333 --user-data-dir="$S/chrome-profile" --window-size=1707,960 --no-first-run --autoplay-policy=no-user-gesture-required about:blank &
  python -I fullscreen-check.py 9333 http://localhost:8080/apps/screenings/s/<token> "$S/shots"

Use a throwaway --user-data-dir, never the user's profile, and a new one per
code change: locally the app's scripts have no version suffix, so a reused
profile runs the old player from its cache. Stop it afterwards
(PowerShell: Stop-Process on chrome.exe whose CommandLine contains the port).
Headless fullscreen uses an 800x600 screen; --screen-info breaks fullscreen.
"""
import base64, json, os, socket, struct, sys, time, urllib.request

port, url, outdir = int(sys.argv[1]), sys.argv[2], sys.argv[3]


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
        if len(data) < 126:
            header += bytes([0x80 | len(data)])
        elif len(data) < 65536:
            header += bytes([0x80 | 126]) + struct.pack('>H', len(data))
        else:
            header += bytes([0x80 | 127]) + struct.pack('>Q', len(data))
        self.s.sendall(header + mask + bytes(c ^ mask[i % 4] for i, c in enumerate(data)))
        while True:
            msg = self.recv()
            if msg.get('id') == self.id:
                if 'error' in msg:
                    raise RuntimeError(msg['error'])
                return msg.get('result', {})


def js(ws, expr):
    r = ws.send('Runtime.evaluate', expression=expr, awaitPromise=True, returnByValue=True)
    return r.get('result', {}).get('value')


def shot(ws, name):
    data = ws.send('Page.captureScreenshot', format='png')['data']
    path = os.path.join(outdir, name)
    with open(path, 'wb') as f:
        f.write(base64.b64decode(data))
    return path


def mouse(ws, kind, x, y, **extra):
    ws.send('Input.dispatchMouseEvent', type=kind, x=x, y=y, **extra)


for _ in range(50):
    try:
        tabs = json.load(urllib.request.urlopen(f'http://127.0.0.1:{port}/json'))
        page = next(t for t in tabs if t['type'] == 'page')
        break
    except Exception:
        time.sleep(0.2)
ws = WS(page['webSocketDebuggerUrl'])
ws.send('Page.enable')
ws.send('Runtime.enable')
ws.send('Page.navigate', url=url)
time.sleep(5)

STATE = """JSON.stringify((() => {
  const p = document.querySelector('.player'), c = document.querySelector('.player__controls');
  const r = c ? c.getBoundingClientRect() : null, pr = p.getBoundingClientRect();
  return {fs: document.fullscreenElement ? document.fullscreenElement.className : null,
          player: [Math.round(pr.top), Math.round(pr.height), Math.round(pr.width)],
          controls: r ? [Math.round(r.top), Math.round(r.bottom), getComputedStyle(c).opacity] : null,
          viewport: [innerWidth, innerHeight], playerClass: p.className};
})())"""

print('page:', js(ws, STATE))
js(ws, "(async () => { const v = document.querySelector('video'); v.muted = true; await v.play().catch(() => {}); })()")
time.sleep(2)
# The control bar can sit below the fold on pages with a long header.
js(ws, "document.querySelector('.player').scrollIntoView({block: 'center'})")
time.sleep(0.5)
b = json.loads(js(ws, "JSON.stringify(document.querySelector('[aria-label=\"Full screen\"]').getBoundingClientRect())"))
x, y = b['x'] + b['width'] / 2, b['y'] + b['height'] / 2
mouse(ws, 'mouseMoved', x, y)
mouse(ws, 'mousePressed', x, y, button='left', clickCount=1)
mouse(ws, 'mouseReleased', x, y, button='left', clickCount=1)
time.sleep(1)
print('after fullscreen click:', js(ws, STATE))
print('shot', shot(ws, 'fs-1-entered.png'))
time.sleep(4)
print('after 4 s idle:', js(ws, STATE))
print('shot', shot(ws, 'fs-2-idle.png'))
w, h = json.loads(js(ws, 'JSON.stringify([innerWidth, innerHeight])'))
for i in range(5):
    mouse(ws, 'mouseMoved', w / 2 + i * 20, h / 2)
    time.sleep(0.1)
time.sleep(0.5)
print('after mouse move:', js(ws, STATE))
print('shot', shot(ws, 'fs-3-moved.png'))
