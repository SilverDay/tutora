// Tutora participant client (vanilla ES module, no build step).
// Output-encoding invariant: all server/user-supplied text is inserted via textContent,
// never innerHTML.
import { connectRealtime } from './realtime.js';

const RESUME_KEY = 'tutora.resume';
const PRESENCE_INTERVAL_MS = 15 * 60 * 1000;
// Fallback polling of the authoritative state endpoint while the relay is unreachable.
const POLL_INTERVAL_MS = 5000;

const state = { sessionId: null, credential: null, revision: -1, timers: [], rt: null, live: false };

function el(tag, text, className) {
  const node = document.createElement(tag);
  if (text !== undefined && text !== null) node.textContent = String(text);
  if (className) node.className = className;
  return node;
}

async function api(method, path, body, auth = true) {
  const headers = { 'Accept': 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (auth && state.credential) headers['Authorization'] = 'Bearer ' + state.credential;
  const res = await fetch(path, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
    credentials: 'omit',
    cache: 'no-store',
  });
  let data = null;
  try { data = await res.json(); } catch { /* empty body */ }
  return { status: res.status, data };
}

function storeResume(sessionId, token) {
  try { sessionStorage.setItem(RESUME_KEY, JSON.stringify({ sessionId, token })); } catch { /* storage unavailable */ }
}

function loadResume() {
  try { return JSON.parse(sessionStorage.getItem(RESUME_KEY) || 'null'); } catch { return null; }
}

function clearResume() {
  try { sessionStorage.removeItem(RESUME_KEY); } catch { /* ignore */ }
}

function adopt(result) {
  state.sessionId = result.session_id;
  state.credential = result.credential;
  if (result.resume_token) storeResume(result.session_id, result.resume_token);
}

async function resume() {
  const saved = loadResume();
  if (!saved || typeof saved.token !== 'string') return false;
  const { status, data } = await api('POST', '/api/participant/resume', { resume_token: saved.token }, false);
  if (status !== 200) { clearResume(); return false; }
  adopt(data);
  return true;
}

async function authed(method, pathSuffix) {
  const path = `/api/participant/sessions/${encodeURIComponent(state.sessionId)}${pathSuffix}`;
  let r = await api(method, path);
  if (r.status === 401 && await resume()) r = await api(method, path);
  if (r.status === 401) { showJoin('Your session has ended or expired.'); return null; }
  return r.status === 200 ? r.data : null;
}

function renderBlock(block) {
  const root = document.getElementById('block');
  root.replaceChildren();
  if (!block) { root.append(el('p', 'Waiting for the tutor…', 'muted')); return; }
  const c = block.config || {};
  root.append(el('h2', block.type.replace('_', ' ')));
  const prompt = c.prompt ?? c.question;
  if (prompt) root.append(el('p', prompt));
  const list = c.options ?? c.items ?? c.tags ?? c.columns;
  if (Array.isArray(list)) {
    const ul = el('ul');
    for (const o of list) ul.append(el('li', o.label));
    root.append(ul);
  }
  if (Array.isArray(c.questions)) {
    root.append(el('p', `${c.questions.length} question(s)`, 'muted'));
  }
  root.append(el('p', 'Interactive answering arrives with the activities phase.', 'muted'));
}

async function refresh() {
  const s = await authed('GET', '/state');
  if (!s) return;
  document.getElementById('session-title').textContent = s.title ?? 'Session';
  document.getElementById('session-status').textContent = s.status === 'live' ? 'Live' : 'Ended';
  if (s.session_revision !== state.revision) {
    state.revision = s.session_revision;
    renderBlock(s.current_block);
  }
}

async function renewPresence() {
  // only while the tab is in the foreground (spec: presence_renewed_at)
  if (document.visibilityState !== 'visible') return;
  const r = await authed('POST', '/presence');
  if (r && r.credential) state.credential = r.credential;
}

function stopTimers() {
  state.timers.forEach(clearInterval);
  state.timers = [];
  if (state.rt) { state.rt.close(); state.rt = null; }
}

function onRealtime(msg) {
  switch (msg.type) {
    case 'auth_ok':
      // (re)subscribed: fetch authoritative state to cover anything missed while offline
      refresh();
      break;
    case 'block_change':
    case 'session_ended':
      // any revision change or gap -> re-fetch HTTP state (authoritative)
      if (typeof msg.session_revision !== 'number' || msg.session_revision !== state.revision) refresh();
      break;
    default:
      break;
  }
}

function showSession() {
  document.getElementById('join-view').hidden = true;
  document.getElementById('session-view').hidden = false;
  stopTimers();
  refresh();
  // fallback polling only while the realtime connection is down
  state.timers.push(setInterval(() => { if (!state.live) refresh(); }, POLL_INTERVAL_MS));
  state.timers.push(setInterval(renewPresence, PRESENCE_INTERVAL_MS));
  state.rt = connectRealtime({
    getToken: async () => {
      const r = await authed('POST', '/connection-token');
      return r ? r.token : null;
    },
    onMessage: onRealtime,
    onStatus: (up) => { state.live = up; },
    isFinal: (code) => code === 4410,
  });
}

function showJoin(message) {
  stopTimers();
  state.credential = null;
  document.getElementById('session-view').hidden = true;
  document.getElementById('join-view').hidden = false;
  const err = document.getElementById('join-error');
  err.hidden = !message;
  err.textContent = message || '';
}

async function onJoin(ev) {
  ev.preventDefault();
  const form = ev.target;
  const body = { code: form.code.value, display_name: form.display_name.value || null };
  const { status, data } = await api('POST', '/api/participant/join', body, false);
  if (status !== 201) { showJoin((data && data.error) || 'Could not join.'); return; }
  adopt(data);
  showSession();
}

document.addEventListener('DOMContentLoaded', async () => {
  document.getElementById('join-form').addEventListener('submit', onJoin);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && state.credential) refresh(); });
  if (await resume()) showSession();
});
