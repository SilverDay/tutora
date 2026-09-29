// Tutor live-session console: shows the live participant count from the relay.
import { connectRealtime } from './realtime.js';

const el = document.getElementById('presence');
const sessionId = el ? el.dataset.sessionId : null;
const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function getToken() {
  const res = await fetch(`/api/tutor/sessions/${encodeURIComponent(sessionId)}/connection-token`, {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
    credentials: 'same-origin',
    cache: 'no-store',
  });
  if (!res.ok) return null;
  return (await res.json()).token;
}

if (el && sessionId) {
  connectRealtime({
    getToken,
    onStatus: (up) => { if (!up) el.textContent = 'realtime offline'; },
    onMessage: (msg) => {
      if (msg.type === 'presence') {
        el.textContent = `${msg.participants} participant${msg.participants === 1 ? '' : 's'} connected`;
      } else if (msg.type === 'session_ended') {
        el.textContent = 'session ended';
      }
    },
    isFinal: (code) => code === 4410,
  });
}
