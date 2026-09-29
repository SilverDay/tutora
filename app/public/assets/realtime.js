// Shared realtime client: fetch-then-subscribe against the Tutora relay.
// Authenticates in the first WebSocket message (the token never appears in the URL),
// reconnects with backoff and a fresh short-lived token, and reports state changes so
// the caller can re-fetch authoritative HTTP state.

const MAX_BACKOFF_MS = 30000;

export function realtimeUrl() {
  const meta = document.querySelector('meta[name="tutora-realtime"]');
  return meta ? meta.content : '';
}

/**
 * @param {object} opts
 * @param {() => Promise<string|null>} opts.getToken  fetches a fresh connection token
 * @param {(msg: object) => void} opts.onMessage
 * @param {(connected: boolean) => void} [opts.onStatus]
 * @param {(code: number) => boolean} [opts.isFinal] close codes that stop reconnecting
 */
export function connectRealtime({ getToken, onMessage, onStatus = () => {}, isFinal = () => false }) {
  const url = realtimeUrl();
  let ws = null;
  let backoff = 1000;
  let stopped = false;
  let timer = null;

  async function open() {
    if (stopped || !url) return;
    let token = null;
    try { token = await getToken(); } catch { token = null; }
    if (stopped) return;
    if (!token) { schedule(); return; }
    ws = new WebSocket(url);
    ws.addEventListener('open', () => ws.send(JSON.stringify({ type: 'auth', token })));
    ws.addEventListener('message', (ev) => {
      let msg;
      try { msg = JSON.parse(ev.data); } catch { return; }
      if (msg.type === 'auth_ok') { backoff = 1000; onStatus(true); }
      onMessage(msg);
    });
    ws.addEventListener('close', (ev) => {
      onStatus(false);
      ws = null;
      if (isFinal(ev.code)) { stopped = true; return; }
      schedule();
    });
  }

  function schedule() {
    if (stopped) return;
    clearTimeout(timer);
    timer = setTimeout(open, backoff + Math.floor(Math.random() * 500));
    backoff = Math.min(backoff * 2, MAX_BACKOFF_MS);
  }

  open();
  return {
    close() { stopped = true; clearTimeout(timer); if (ws) ws.close(1000); },
    /** Sends a JSON message if connected (e.g. presenter whiteboard strokes). */
    send(msg) { if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(msg)); },
  };
}
