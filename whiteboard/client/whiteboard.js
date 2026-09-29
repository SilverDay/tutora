// Tutora whiteboard client (bundled with esbuild into app/public/assets/whiteboard.bundle.js).
//
// One canvas, three transports:
//   - collaborative whiteboard / annotate: Yjs over the sidecar (y-protocols sync, first-message auth)
//   - presenter whiteboard: tutor strokes over the relay, participants render read-only
// Entities: { type, actor_id, session_block_id, data } with coordinates normalised to [0, 1].
// Output-encoding invariant: text is drawn with fillText, never inserted as HTML.
import * as Y from 'yjs';
import * as syncProtocol from 'y-protocols/sync';
import * as encoding from 'lib0/encoding';
import * as decoding from 'lib0/decoding';

const COLORS = ['#111111', '#d32f2f', '#1976d2', '#388e3c', '#f57c00', '#7b1fa2'];
const WIDTHS = [2, 4, 8];
const MAX_POINTS = 2000;
const TAG_COLORS = ['#d32f2f', '#1976d2', '#388e3c', '#f57c00', '#7b1fa2', '#00838f'];

// ------------------------------------------------------------------ DOM helpers

function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'text') node.textContent = String(v);
    else if (k === 'class') node.className = v;
    else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
    else node.setAttribute(k, v === true ? '' : String(v));
  }
  for (const c of children.flat()) if (c !== null && c !== undefined) node.append(c);
  return node;
}

const finite01 = (v) => typeof v === 'number' && Number.isFinite(v) && v >= 0 && v <= 1;
const colorOk = (c) => typeof c === 'string' && /^#[0-9a-fA-F]{6}$/.test(c);

/** Time-ordered ids give every client the same z-order when sorted. */
function newId() {
  const rand = crypto.getRandomValues(new Uint8Array(6));
  return Date.now().toString(36).padStart(9, '0') + '-' + Array.from(rand, (b) => b.toString(16).padStart(2, '0')).join('');
}

// ------------------------------------------------------------------ rendering

function drawEntity(ctx, e, W, H, tagLabels) {
  const d = e?.data;
  if (!d || typeof d !== 'object') return;
  ctx.save();
  const color = colorOk(d.color) ? d.color : '#111111';
  ctx.strokeStyle = color;
  ctx.fillStyle = color;
  ctx.lineWidth = Number.isInteger(d.width) && d.width > 0 && d.width <= 40 ? d.width * (W / 1000) : 2;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  switch (e.type) {
    case 'stroke': {
      const p = Array.isArray(d.points) ? d.points : [];
      if (p.length < 4 || !p.every(finite01)) break;
      ctx.beginPath();
      ctx.moveTo(p[0] * W, p[1] * H);
      for (let i = 2; i < p.length; i += 2) ctx.lineTo(p[i] * W, p[i + 1] * H);
      ctx.stroke();
      break;
    }
    case 'line':
    case 'arrow': {
      if (![d.x1, d.y1, d.x2, d.y2].every(finite01)) break;
      const [x1, y1, x2, y2] = [d.x1 * W, d.y1 * H, d.x2 * W, d.y2 * H];
      ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
      if (e.type === 'arrow') {
        const a = Math.atan2(y2 - y1, x2 - x1);
        const len = Math.max(10, ctx.lineWidth * 4);
        ctx.beginPath();
        ctx.moveTo(x2, y2); ctx.lineTo(x2 - len * Math.cos(a - 0.4), y2 - len * Math.sin(a - 0.4));
        ctx.moveTo(x2, y2); ctx.lineTo(x2 - len * Math.cos(a + 0.4), y2 - len * Math.sin(a + 0.4));
        ctx.stroke();
      }
      break;
    }
    case 'rect':
    case 'ellipse': {
      if (![d.x, d.y, d.w, d.h].every(finite01)) break;
      ctx.beginPath();
      if (e.type === 'rect') ctx.rect(d.x * W, d.y * H, d.w * W, d.h * H);
      else ctx.ellipse((d.x + d.w / 2) * W, (d.y + d.h / 2) * H, (d.w / 2) * W, (d.h / 2) * H, 0, 0, Math.PI * 2);
      ctx.stroke();
      break;
    }
    case 'text': {
      if (!finite01(d.x) || !finite01(d.y) || typeof d.text !== 'string') break;
      const size = Number.isInteger(d.size) && d.size >= 8 && d.size <= 96 ? d.size : 16;
      ctx.font = `${size * (W / 1000)}px system-ui, sans-serif`;
      ctx.textBaseline = 'top';
      d.text.slice(0, 500).split('\n').forEach((line, i) => ctx.fillText(line, d.x * W, d.y * H + i * size * 1.2 * (W / 1000)));
      break;
    }
    case 'tag': {
      if (!finite01(d.x) || !finite01(d.y)) break;
      const label = tagLabels.get(d.tag_id);
      if (!label) break;
      const idx = [...tagLabels.keys()].indexOf(d.tag_id);
      const fs = Math.max(11, 14 * (W / 1000));
      ctx.font = `600 ${fs}px system-ui, sans-serif`;
      const w = ctx.measureText(label).width + fs;
      const x = d.x * W, y = d.y * H;
      ctx.fillStyle = TAG_COLORS[idx % TAG_COLORS.length];
      ctx.beginPath(); ctx.arc(x, y, fs * 0.35, 0, Math.PI * 2); ctx.fill();
      ctx.fillRect(x + fs * 0.5, y - fs * 0.75, w, fs * 1.5);
      ctx.fillStyle = '#ffffff';
      ctx.textBaseline = 'middle';
      ctx.fillText(label, x + fs, y);
      break;
    }
    default:
      break;
  }
  ctx.restore();
}

/** Distance-based hit test in normalised coordinates. */
function hits(e, x, y) {
  const d = e?.data ?? {};
  const near = 0.015;
  const segDist = (x1, y1, x2, y2) => {
    const dx = x2 - x1, dy = y2 - y1;
    const t = Math.max(0, Math.min(1, ((x - x1) * dx + (y - y1) * dy) / (dx * dx + dy * dy || 1)));
    return Math.hypot(x - (x1 + t * dx), y - (y1 + t * dy));
  };
  switch (e.type) {
    case 'stroke':
      for (let i = 0; i + 3 < (d.points?.length ?? 0); i += 2) if (segDist(d.points[i], d.points[i + 1], d.points[i + 2], d.points[i + 3]) < near) return true;
      return false;
    case 'line': case 'arrow': return segDist(d.x1, d.y1, d.x2, d.y2) < near;
    case 'rect': case 'ellipse': return x >= d.x - near && x <= d.x + d.w + near && y >= d.y - near && y <= d.y + d.h + near;
    case 'text': return x >= d.x - near && x <= d.x + 0.25 && y >= d.y - near && y <= d.y + 0.05;
    case 'tag': return Math.hypot(x - d.x, y - d.y) < 0.03;
    default: return false;
  }
}

// ------------------------------------------------------------------ collaborative transport

function collabTransport({ url, getToken, onStatus, onReset }) {
  let doc = new Y.Doc();
  let ws = null;
  let stopped = false;
  let backoff = 1000;
  let timer = null;
  const listeners = new Set();

  const bind = () => {
    doc.getMap('entities').observe(() => listeners.forEach((fn) => fn()));
    doc.on('update', (update, origin) => {
      if (origin === 'remote' || !ws || ws.readyState !== WebSocket.OPEN) return;
      const enc = encoding.createEncoder();
      encoding.writeVarUint(enc, 0);
      syncProtocol.writeUpdate(enc, update);
      ws.send(encoding.toUint8Array(enc));
    });
  };
  bind();

  async function open() {
    if (stopped) return;
    let token = null;
    try { token = await getToken(); } catch { token = null; }
    if (stopped) return;
    if (!token) { schedule(); return; }
    ws = new WebSocket(url);
    ws.binaryType = 'arraybuffer';
    ws.addEventListener('open', () => ws.send(JSON.stringify({ type: 'auth', token })));
    ws.addEventListener('message', (ev) => {
      if (typeof ev.data === 'string') {
        let m; try { m = JSON.parse(ev.data); } catch { return; }
        if (m.type === 'auth_ok') {
          backoff = 1000;
          onStatus(true);
          const enc = encoding.createEncoder();
          encoding.writeVarUint(enc, 0);
          syncProtocol.writeSyncStep1(enc, doc);
          ws.send(encoding.toUint8Array(enc));
        }
        return;
      }
      const dec = decoding.createDecoder(new Uint8Array(ev.data));
      if (decoding.readVarUint(dec) !== 0) return;
      const enc = encoding.createEncoder();
      encoding.writeVarUint(enc, 0);
      syncProtocol.readSyncMessage(dec, enc, doc, 'remote');
      if (encoding.length(enc) > 1) ws.send(encoding.toUint8Array(enc));
    });
    ws.addEventListener('close', (ev) => {
      onStatus(false);
      ws = null;
      if (ev.code === 4410) { stopped = true; return; }
      if (ev.code === 4403) {
        // the server rejected a local change: drop local state and resync from the server
        doc.destroy();
        doc = new Y.Doc();
        bind();
        onReset();
      }
      schedule();
    });
  }

  function schedule() {
    if (stopped) return;
    clearTimeout(timer);
    timer = setTimeout(open, backoff + Math.floor(Math.random() * 500));
    backoff = Math.min(backoff * 2, 30000);
  }

  open();
  return {
    entries: () => [...doc.getMap('entities').entries()],
    set: (id, e) => doc.transact(() => doc.getMap('entities').set(id, e)),
    delete: (id) => doc.transact(() => doc.getMap('entities').delete(id)),
    onChange: (fn) => listeners.add(fn),
    close() { stopped = true; clearTimeout(timer); if (ws) ws.close(1000); doc.destroy(); },
  };
}

// ------------------------------------------------------------------ presenter transport (relay)

function presenterTransport({ blockId, canWrite, send }) {
  const entities = new Map();
  const listeners = new Set();
  const changed = () => listeners.forEach((fn) => fn());
  const onRealtime = (ev) => {
    const m = ev.detail;
    if (!m || m.session_block_id !== blockId) return;
    if (m.type === 'whiteboard_stroke_broadcast' && m.stroke && typeof m.stroke === 'object') {
      entities.set(m.stroke.id ?? newId(), m.stroke.entity ?? m.stroke);
      changed();
    } else if (m.type === 'whiteboard_clear') {
      entities.clear();
      changed();
    }
  };
  document.addEventListener('tutora:realtime', onRealtime);
  return {
    entries: () => [...entities.entries()],
    set: (id, e) => {
      if (!canWrite) return;
      entities.set(id, e);
      send({ type: 'whiteboard_stroke_broadcast', session_block_id: blockId, stroke: e });
      changed();
    },
    delete: () => {},
    clear: () => {
      if (!canWrite) return;
      entities.clear();
      send({ type: 'whiteboard_clear', session_block_id: blockId });
      changed();
    },
    onChange: (fn) => listeners.add(fn),
    close() { document.removeEventListener('tutora:realtime', onRealtime); },
  };
}

// ------------------------------------------------------------------ board

/**
 * @param {HTMLElement} root
 * @param {object} o
 *   kind: 'whiteboard'|'annotate', mode: 'collaborative'|'presenter', role: 'tutor'|'participant'|'spectator',
 *   actor, blockId, tags: [{id,label}], background: () => Promise<HTMLImageElement|null>,
 *   url, getToken: () => Promise<string|null>, send: (msg) => void (presenter tutor),
 *   onSnapshot: (blob) => Promise<void> (tutor)
 */
export function mount(root, o) {
  const canWrite = o.role !== 'spectator';
  const tagLabels = new Map((o.tags ?? []).map((t) => [t.id, t.label]));
  const status = el('span', { class: 'muted wb-status', text: 'connecting…' });
  const canvas = el('canvas', { class: 'wb-canvas', role: 'img', 'aria-label': o.kind === 'annotate' ? 'Annotation board' : 'Whiteboard' });
  const toolbar = el('div', { class: 'wb-toolbar' });
  root.replaceChildren(toolbar, canvas, status);

  let bg = null;
  let aspect = 9 / 16;
  const state = { tool: o.kind === 'annotate' ? `tag:${o.tags?.[0]?.id ?? ''}` : 'pen', color: COLORS[0], width: WIDTHS[1], text: '' };
  let draft = null;

  const transport = o.mode === 'presenter'
    ? presenterTransport({ blockId: o.blockId, canWrite: o.role === 'tutor', send: o.send ?? (() => {}) })
    : collabTransport({ url: o.url, getToken: o.getToken, onStatus: (up) => { status.textContent = up ? '' : 'reconnecting…'; }, onReset: () => redraw() });
  if (o.mode === 'presenter') status.textContent = o.role === 'tutor' ? 'Presenter mode: participants watch.' : '';
  transport.onChange(() => redraw());

  // --- toolbar
  const writable = o.mode === 'presenter' ? o.role === 'tutor' : canWrite;
  if (writable) {
    const toolBtn = (id, label) => el('button', { type: 'button', class: 'secondary wb-tool', 'data-tool': id, 'aria-pressed': String(state.tool === id), text: label,
      onclick: () => { state.tool = id; toolbar.querySelectorAll('.wb-tool').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.tool === id))); } });
    if (o.kind === 'annotate') {
      for (const t of o.tags ?? []) toolbar.append(toolBtn(`tag:${t.id}`, t.label));
    } else {
      for (const [id, label] of [['pen', 'Pen'], ['line', 'Line'], ['arrow', 'Arrow'], ['rect', 'Box'], ['ellipse', 'Ellipse'], ['text', 'Text']]) toolbar.append(toolBtn(id, label));
      toolbar.append(el('select', { 'aria-label': 'Colour', onchange: (e) => { state.color = e.target.value; } }, COLORS.map((c, i) => el('option', { value: c, text: ['Black', 'Red', 'Blue', 'Green', 'Orange', 'Purple'][i] }))));
      toolbar.append(el('select', { 'aria-label': 'Width', onchange: (e) => { state.width = Number(e.target.value); } }, WIDTHS.map((w) => el('option', { value: w, text: `${w}px`, selected: w === state.width }))));
      toolbar.append(el('input', { type: 'text', maxlength: 500, placeholder: 'Text to place', 'aria-label': 'Text to place', oninput: (e) => { state.text = e.target.value; } }));
    }
    if (o.mode !== 'presenter') toolbar.append(toolBtn('eraser', o.role === 'tutor' ? 'Eraser' : 'Erase mine'));
    if (o.mode === 'presenter') toolbar.append(el('button', { type: 'button', class: 'danger', text: 'Clear', onclick: () => transport.clear() }));
  }
  if (o.onSnapshot) {
    toolbar.append(el('button', { type: 'button', class: 'secondary', text: 'Save snapshot', onclick: () => snapshot() }));
  }

  // --- sizing & drawing
  function resize() {
    const w = Math.max(200, root.clientWidth || 640);
    const dpr = window.devicePixelRatio || 1;
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(w * aspect * dpr);
    canvas.style.width = `${w}px`; // CSSOM property (not an inline style attribute): allowed by CSP
    canvas.style.height = `${w * aspect}px`;
    redraw();
  }

  function redraw() {
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, W, H);
    if (bg) ctx.drawImage(bg, 0, 0, W, H);
    const list = transport.entries().sort((a, b) => (a[0] < b[0] ? -1 : 1));
    for (const [, e] of list) drawEntity(ctx, e, W, H, tagLabels);
    if (draft) drawEntity(ctx, draft, W, H, tagLabels);
  }

  // --- input
  const pos = (ev) => {
    const r = canvas.getBoundingClientRect();
    return [Math.min(1, Math.max(0, (ev.clientX - r.left) / r.width)), Math.min(1, Math.max(0, (ev.clientY - r.top) / r.height))];
  };
  const round = (v) => Math.round(v * 10000) / 10000;
  const entity = (type, data) => ({ type, actor_id: o.actor, session_block_id: o.blockId, data });
  let start = null;

  if (writable) {
    canvas.addEventListener('pointerdown', (ev) => {
      const [x, y] = pos(ev);
      if (state.tool === 'eraser') {
        const list = transport.entries().sort((a, b) => (a[0] < b[0] ? 1 : -1));
        const hit = list.find(([, e]) => (o.role === 'tutor' || e.actor_id === o.actor) && hits(e, x, y));
        if (hit) transport.delete(hit[0]);
        return;
      }
      if (state.tool.startsWith('tag:')) {
        transport.set(newId(), entity('tag', { x: round(x), y: round(y), tag_id: state.tool.slice(4) }));
        return;
      }
      if (state.tool === 'text') {
        const text = state.text.trim();
        if (text) transport.set(newId(), entity('text', { x: round(x), y: round(y), text, color: state.color, size: 24 }));
        return;
      }
      canvas.setPointerCapture(ev.pointerId);
      start = [x, y];
      draft = state.tool === 'pen'
        ? entity('stroke', { points: [round(x), round(y)], color: state.color, width: state.width })
        : null;
    });
    canvas.addEventListener('pointermove', (ev) => {
      if (!start) return;
      const [x, y] = pos(ev);
      if (state.tool === 'pen') {
        if (draft.data.points.length < MAX_POINTS * 2) draft.data.points.push(round(x), round(y));
      } else {
        draft = shapeFrom(start, [x, y]);
      }
      redraw();
    });
    canvas.addEventListener('pointerup', (ev) => {
      if (!start) return;
      const [x, y] = pos(ev);
      const done = state.tool === 'pen' ? draft : shapeFrom(start, [x, y]);
      start = null;
      draft = null;
      if (done && done.type === 'stroke' && done.data.points.length === 2) done.data.points.push(done.data.points[0], done.data.points[1]);
      if (done) transport.set(newId(), done);
      redraw();
    });
  }

  function shapeFrom([x1, y1], [x2, y2]) {
    if (state.tool === 'line' || state.tool === 'arrow') {
      return entity(state.tool, { x1: round(x1), y1: round(y1), x2: round(x2), y2: round(y2), color: state.color, width: state.width });
    }
    if (state.tool === 'rect' || state.tool === 'ellipse') {
      const x = round(Math.min(x1, x2)), y = round(Math.min(y1, y2));
      return entity(state.tool, { x, y, w: round(Math.min(Math.abs(x2 - x1), 1 - x)), h: round(Math.min(Math.abs(y2 - y1), 1 - y)), color: state.color, width: state.width });
    }
    return null;
  }

  // --- snapshots (client-side capture; the sidecar never renders)
  async function snapshot() {
    if (!o.onSnapshot) return;
    redraw();
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
    if (blob) await o.onSnapshot(blob);
  }

  // --- background (slide image as base layer)
  Promise.resolve(o.background ? o.background() : null).then((img) => {
    if (img) {
      bg = img;
      if (img.naturalWidth > 0) aspect = img.naturalHeight / img.naturalWidth;
    }
    resize();
  });
  window.addEventListener('resize', resize);
  resize();

  return {
    snapshot,
    close() { transport.close(); window.removeEventListener('resize', resize); },
  };
}

// ------------------------------------------------------------------ tutor console auto-mount

function autoMountTutor() {
  const root = document.getElementById('whiteboard');
  if (!root) return;
  const sid = root.dataset.sessionId;
  const bid = Number(root.dataset.blockId);
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  const url = document.querySelector('meta[name="tutora-whiteboard"]')?.content ?? '';
  let tags = [];
  try { tags = JSON.parse(root.dataset.tags || '[]'); } catch { tags = []; }
  const post = (path, body, type) => fetch(path, {
    method: 'POST', credentials: 'same-origin', cache: 'no-store',
    headers: { 'X-CSRF-Token': csrf, ...(type ? { 'Content-Type': type } : {}) }, body,
  });
  const board = mount(root, {
    kind: root.dataset.kind === 'annotate' ? 'annotate' : 'whiteboard',
    mode: root.dataset.kind === 'annotate' ? 'collaborative' : root.dataset.mode,
    role: 'tutor', actor: 'tutor', blockId: bid, tags, url,
    getToken: async () => {
      const r = await post(`/api/tutor/sessions/${encodeURIComponent(sid)}/blocks/${bid}/whiteboard-token`);
      return r.ok ? (await r.json()).token : null;
    },
    send: (msg) => window.tutoraRealtime?.send(msg),
    background: () => new Promise((resolve) => {
      if (!root.dataset.assetId) { resolve(null); return; }
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => resolve(null);
      img.src = `/slides/${encodeURIComponent(root.dataset.assetId)}`;
    }),
    onSnapshot: async (blob) => {
      const r = await post(`/api/tutor/sessions/${encodeURIComponent(sid)}/blocks/${bid}/snapshots`, blob, 'image/png');
      const note = root.querySelector('.wb-status');
      if (note) note.textContent = r.ok ? 'Snapshot saved.' : 'Snapshot failed.';
    },
  });
  // this tab captures before it navigates away; it then ignores the relay's capture
  // request for the same exit (other tabs showing this board still honour it)
  let exitCaptured = false;
  document.addEventListener('tutora:realtime', (ev) => {
    if (ev.detail?.type === 'capture' && ev.detail.session_block_id === bid && !exitCaptured) board.snapshot();
  });
  // snapshot before navigating away from the board (block exit)
  for (const form of document.querySelectorAll('form[action$="/navigate"], form[action$="/end"]')) {
    form.addEventListener('submit', (ev) => {
      if (form.dataset.captured) return;
      ev.preventDefault();
      form.dataset.captured = '1';
      exitCaptured = true;
      Promise.race([board.snapshot(), new Promise((r) => setTimeout(r, 3000))]).finally(() => form.requestSubmit(ev.submitter ?? undefined));
    });
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', autoMountTutor);
else autoMountTutor();
