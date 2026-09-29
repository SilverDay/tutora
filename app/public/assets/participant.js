// Tutora participant client (vanilla ES module, no build step).
// Output-encoding invariant: all server/user-supplied text is inserted via textContent
// (see dom.js), never innerHTML.
import { connectRealtime } from './realtime.js';
import { el } from './dom.js';
import { renderAggregate } from './results.js';

const RESUME_KEY = 'tutora.resume';
const PRESENCE_INTERVAL_MS = 15 * 60 * 1000;
// Fallback polling of the authoritative state endpoint while the relay is unreachable.
const POLL_INTERVAL_MS = 5000;

const state = {
  sessionId: null, credential: null, revision: -1, timers: [], rt: null, live: false,
  block: null, quizSig: null, countdown: null, slideUrl: null,
};

// ------------------------------------------------------------------ API

async function api(method, path, body, auth = true) {
  const headers = { 'Accept': 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (auth && state.credential) headers['Authorization'] = 'Bearer ' + state.credential;
  const res = await fetch(path, {
    method, headers, body: body === undefined ? undefined : JSON.stringify(body),
    credentials: 'omit', cache: 'no-store',
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

/** Authenticated call scoped to the current session; retries once after resume on 401. */
async function authed(method, suffix, body) {
  const path = `/api/participant/sessions/${encodeURIComponent(state.sessionId)}${suffix}`;
  let r = await api(method, path, body);
  if (r.status === 401 && await resume()) r = await api(method, path, body);
  if (r.status === 401) { showJoin('Your session has ended or expired.'); return null; }
  return r;
}

function blockPath(suffix) {
  return `/blocks/${encodeURIComponent(state.block.id)}${suffix}`;
}

// ------------------------------------------------------------------ feedback

function flash(message, ok = false) {
  const f = document.getElementById('feedback');
  f.textContent = message || '';
  f.className = ok ? 'notice' : 'alert';
  f.hidden = !message;
}

function errorText(r) {
  if (!r || !r.data) return 'Something went wrong.';
  return (r.data.errors && r.data.errors.join(' ')) || r.data.error || 'Something went wrong.';
}

async function send(suffix, body, okMessage) {
  const r = await authed('POST', suffix, body);
  if (!r) return false;
  if (r.status >= 200 && r.status < 300) { flash(okMessage || '', true); return true; }
  flash(errorText(r));
  return false;
}

// ------------------------------------------------------------------ block controls

const controls = {
  poll(c, mine) {
    const multi = (c.max_selections || 1) > 1;
    const chosen = new Set(mine?.selected ?? []);
    const inputs = c.options.map(o => el('label', { class: 'choice' },
      el('input', { type: multi ? 'checkbox' : 'radio', name: 'opt', value: o.id, checked: chosen.has(o.id) }), ' ', o.label));
    const form = el('form', { class: 'activity' }, el('p', { text: c.question }), ...inputs,
      el('button', { type: 'submit', text: mine ? 'Update answer' : 'Submit' }));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const selected = [...form.querySelectorAll('input:checked')].map(i => i.value);
      send(blockPath('/submission'), { payload: { selected } }, 'Answer saved.');
    });
    return form;
  },
  word(c, mine) {
    return controls.poll({ question: `Choose up to ${c.max_selections}`, options: c.items, max_selections: c.max_selections }, mine);
  },
  meter(c, mine) {
    const out = el('output', { text: String(mine?.value ?? c.min) });
    const range = el('input', { type: 'range', min: c.min, max: c.max, step: c.step, value: mine?.value ?? c.min,
      oninput: (e) => { out.textContent = e.target.value; } });
    const form = el('form', { class: 'activity' }, el('p', { text: c.prompt }),
      el('div', { class: 'row' }, c.labels?.min ? el('span', { class: 'muted', text: c.labels.min }) : null, range, out,
        c.labels?.max ? el('span', { class: 'muted', text: c.labels.max }) : null),
      el('button', { type: 'submit', text: mine ? 'Update' : 'Submit' }));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      send(blockPath('/submission'), { payload: { value: Number(range.value) } }, 'Saved.');
    });
    return form;
  },
  rate(c, mine) {
    const rows = c.items.map(item => {
      const sel = el('select', { name: item.id }, el('option', { value: '', text: '–' }),
        ...Array.from({ length: c.scale }, (_, i) => el('option', { value: i + 1, text: String(i + 1), selected: mine?.ratings?.[item.id] === i + 1 })));
      return el('label', { class: 'choice' }, item.label, ' ', sel);
    });
    const form = el('form', { class: 'activity' }, ...rows, el('button', { type: 'submit', text: mine ? 'Update' : 'Submit' }));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const ratings = {};
      for (const s of form.querySelectorAll('select')) if (s.value) ratings[s.name] = Number(s.value);
      send(blockPath('/submission'), { payload: { ratings } }, 'Ratings saved.');
    });
    return form;
  },
  rank(c, mine) {
    const byId = Object.fromEntries(c.items.map(i => [i.id, i]));
    let order = mine?.order ?? c.items.map(i => i.id);
    const list = el('ol', { class: 'rank' });
    const draw = () => {
      list.replaceChildren(...order.map((id, idx) => el('li', {},
        byId[id].label, ' ',
        el('button', { type: 'button', class: 'secondary', text: '↑', 'aria-label': 'Move up', disabled: idx === 0,
          onclick: () => { [order[idx - 1], order[idx]] = [order[idx], order[idx - 1]]; draw(); } }),
        el('button', { type: 'button', class: 'secondary', text: '↓', 'aria-label': 'Move down', disabled: idx === order.length - 1,
          onclick: () => { [order[idx + 1], order[idx]] = [order[idx], order[idx + 1]]; draw(); } }))));
    };
    draw();
    const form = el('form', { class: 'activity' }, list, el('button', { type: 'submit', text: mine ? 'Update ranking' : 'Submit ranking' }));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      send(blockPath('/submission'), { payload: { order } }, 'Ranking saved.');
    });
    return form;
  },
  plot(c, mine) {
    const prev = Object.fromEntries((mine?.points ?? []).map(p => [p.item_id, p]));
    const rows = c.items.map(item => el('fieldset', { class: 'row', 'data-item': item.id },
      el('legend', { text: item.label }),
      el('label', {}, c.x_axis.label, ' ', el('input', { type: 'number', name: 'x', min: c.x_axis.min, max: c.x_axis.max, step: 'any', value: prev[item.id]?.x ?? '' })),
      el('label', {}, c.y_axis.label, ' ', el('input', { type: 'number', name: 'y', min: c.y_axis.min, max: c.y_axis.max, step: 'any', value: prev[item.id]?.y ?? '' }))));
    const form = el('form', { class: 'activity' }, ...rows, el('button', { type: 'submit', text: mine ? 'Update' : 'Submit' }));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const points = [];
      for (const fs of form.querySelectorAll('fieldset')) {
        const x = fs.querySelector('[name=x]').value, y = fs.querySelector('[name=y]').value;
        if (x !== '' && y !== '') points.push({ item_id: fs.dataset.item, x: Number(x), y: Number(y) });
      }
      send(blockPath('/submission'), { payload: { points } }, 'Placement saved.');
    });
    return form;
  },
  word_cloud(c, mine) {
    const inputs = Array.from({ length: c.max_words_per_participant }, (_, i) =>
      el('input', { type: 'text', maxlength: 40, value: mine?.words?.[i] ?? '', 'aria-label': `Word ${i + 1}` }));
    const form = el('form', { class: 'activity' }, el('p', { text: c.prompt }), ...inputs,
      el('button', { type: 'submit', text: mine ? 'Update' : 'Submit' }));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const words = inputs.map(i => i.value.trim()).filter(Boolean);
      send(blockPath('/submission'), { payload: { words } }, 'Words saved.');
    });
    return form;
  },
  write(c, mine) {
    const ta = el('textarea', { rows: 5, maxlength: 2000 });
    ta.value = mine?.text ?? '';
    const form = el('form', { class: 'activity' }, el('p', { text: c.prompt }), ta,
      el('p', { class: 'muted', text: 'Only your tutor sees individual responses.' }),
      el('button', { type: 'submit', text: mine ? 'Update response' : 'Send' }));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      send(blockPath('/submission'), { payload: { text: ta.value } }, 'Response sent.');
    });
    return form;
  },
  wall(c) {
    const col = el('select', {}, c.columns.map(k => el('option', { value: k.id, text: k.label })));
    const text = el('textarea', { rows: 2, maxlength: 500 });
    const form = el('form', { class: 'activity' }, c.prompt ? el('p', { text: c.prompt }) : null,
      el('label', {}, 'Add a card ', text), el('label', {}, 'Column ', col), el('button', { type: 'submit', text: 'Add card' }));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (await send(blockPath('/wall/cards'), { text: text.value, column_id: col.value }, 'Card added.')) {
        text.value = '';
        refresh();
      }
    });
    return form;
  },
};

// ------------------------------------------------------------------ dynamic regions

function renderWall(c, cards) {
  const root = document.getElementById('dynamic');
  const board = el('div', { class: 'wall' });
  for (const column of c.columns) {
    const colCards = cards.filter(k => k.column_id === column.id);
    board.append(el('section', { class: 'wall-col' }, el('h3', { text: column.label }),
      ...colCards.map(k => el('div', { class: k.mine ? 'wall-card mine' : 'wall-card' },
        el('p', { text: k.text }),
        k.mine ? el('div', { class: 'row' },
          el('select', { 'aria-label': 'Move to column', onchange: async (e) => {
            if (await send(blockPath(`/wall/cards/${k.id}/move`), { column_id: e.target.value, position: 0 })) refresh();
          } }, c.columns.map(x => el('option', { value: x.id, text: x.label, selected: x.id === k.column_id }))),
          el('button', { type: 'button', class: 'danger', text: 'Delete', onclick: async () => {
            if (await send(blockPath(`/wall/cards/${k.id}/delete`), {})) refresh();
          } })) : null))));
  }
  root.replaceChildren(board);
}

function optionLabel(q, v) {
  if (typeof v === 'boolean') return v ? 'True' : 'False';
  if (Array.isArray(v)) return v.map(x => optionLabel(q, x)).join(', ');
  const o = (q.options || []).find(x => x.id === v);
  return o ? o.label : String(v);
}

function answerWidget(q, onAnswer) {
  if (q.type === 'true_false') {
    return el('div', { class: 'row' },
      el('button', { type: 'button', text: 'True', onclick: () => onAnswer(true) }),
      el('button', { type: 'button', text: 'False', onclick: () => onAnswer(false) }));
  }
  if (q.type === 'numeric') {
    const input = el('input', { type: 'number', step: 'any', 'aria-label': 'Your answer' });
    return el('form', { class: 'row', onsubmit: (e) => { e.preventDefault(); onAnswer(Number(input.value)); } },
      input, el('button', { type: 'submit', text: 'Answer' }));
  }
  if (q.type === 'multi') {
    const boxes = q.options.map(o => el('label', { class: 'choice' }, el('input', { type: 'checkbox', value: o.id }), ' ', o.label));
    const form = el('form', { onsubmit: (e) => {
      e.preventDefault();
      onAnswer([...form.querySelectorAll('input:checked')].map(i => i.value));
    } }, ...boxes, el('button', { type: 'submit', text: 'Answer' }));
    return form;
  }
  return el('div', { class: 'row' }, q.options.map(o => el('button', { type: 'button', text: o.label, onclick: () => onAnswer(o.id) })));
}

function startCountdown(node, deadlineIso, serverIso) {
  clearInterval(state.countdown);
  if (!deadlineIso) return;
  // cosmetic only: the server enforces the deadline
  const skew = Date.parse(serverIso) - Date.now();
  const tick = () => {
    const left = Math.max(0, Math.round((Date.parse(deadlineIso) - (Date.now() + skew)) / 1000));
    node.textContent = `${left}s left`;
    if (left === 0) { clearInterval(state.countdown); setTimeout(refresh, 1000); }
  };
  tick();
  state.countdown = setInterval(tick, 1000);
}

function renderQuiz(c, view) {
  const root = document.getElementById('dynamic');
  const qById = Object.fromEntries(c.questions.map(q => [q.id, q]));
  const answer = async (qid, value) => {
    if (await send(blockPath(`/quiz/${encodeURIComponent(qid)}/answer`), { answer: value }, 'Answer submitted.')) refresh();
  };
  const parts = [];
  if (view.pacing === 'tutor') {
    const open = view.questions.find(q => q.status === 'OPEN');
    if (open) {
      const q = qById[open.id];
      const timer = el('p', { class: 'timer' });
      parts.push(el('div', { class: 'quiz-q' }, el('h3', { text: q.prompt }), timer,
        open.answered ? el('p', { class: 'muted', text: 'Answer received — wait for the reveal.' }) : answerWidget(q, v => answer(q.id, v))));
      startCountdown(timer, open.closes_at, view.server_time);
    } else {
      clearInterval(state.countdown);
      parts.push(el('p', { class: 'muted', text: 'Waiting for the next question…' }));
    }
    for (const r of view.questions.filter(x => x.status === 'REVEALED')) {
      const q = qById[r.id];
      parts.push(el('div', { class: 'quiz-result' }, el('strong', { text: q.prompt }),
        el('p', { text: `Correct answer: ${optionLabel(q, r.correct_answer)}` }),
        el('p', { class: r.my_correct ? 'notice' : 'alert', text: r.my_answer === null ? 'You did not answer.' : (r.my_correct ? 'You were right!' : `Your answer: ${optionLabel(q, r.my_answer)}`) })));
    }
  } else if (view.finished) {
    clearInterval(state.countdown);
    parts.push(el('p', { class: 'notice', text: `Finished — score ${view.score} / ${view.max_score}` }));
    for (const r of view.review) {
      const q = qById[r.id];
      parts.push(el('div', { class: 'quiz-result' }, el('strong', { text: q.prompt }),
        el('p', { text: `Correct: ${optionLabel(q, r.correct_answer)} · You: ${r.my_answer === null ? '—' : optionLabel(q, r.my_answer)}` })));
    }
  } else {
    const next = view.progress.find(p => !p.answered && !p.expired);
    if (next) {
      const q = qById[next.id];
      const done = view.progress.filter(p => p.answered || p.expired).length;
      parts.push(el('p', { class: 'muted', text: `Question ${done + 1} of ${view.progress.length}` }));
      if (!next.started_at) {
        parts.push(el('button', { type: 'button', text: 'Start question', onclick: async () => {
          if (await send(blockPath(`/quiz/${encodeURIComponent(q.id)}/open`), {})) refresh();
        } }));
      } else {
        const timer = el('p', { class: 'timer' });
        parts.push(el('div', { class: 'quiz-q' }, el('h3', { text: q.prompt }), timer, answerWidget(q, v => answer(q.id, v))));
        startCountdown(timer, next.deadline, view.server_time);
      }
    }
  }
  root.replaceChildren(...parts);
}

function updateDynamic(block) {
  const s = block.state;
  if (!s) return;
  if (s.aggregate !== undefined) {
    renderAggregate(document.getElementById('results'), block.type, block.config, s.aggregate);
  }
  if (block.type === 'wall') renderWall(block.config, s.cards);
  if (block.type === 'quiz') {
    const sig = JSON.stringify(s.quiz, (k, v) => (k === 'server_time' ? undefined : v));
    if (sig !== state.quizSig) { state.quizSig = sig; renderQuiz(block.config, s.quiz); }
  }
}

/** Loads a slide image with the participant credential (img tags cannot send headers). */
async function slideImage(assetId) {
  const r = await fetch(`/api/participant/sessions/${encodeURIComponent(state.sessionId)}/slides/${encodeURIComponent(assetId)}`, {
    headers: { 'Authorization': 'Bearer ' + state.credential }, credentials: 'omit', cache: 'no-store',
  });
  if (!r.ok) return el('p', { class: 'muted', text: 'Slide not available.' });
  const blob = await r.blob();
  if (blob.type !== 'image/png') return el('p', { class: 'muted', text: 'Slide not available.' });
  const url = URL.createObjectURL(blob);
  if (state.slideUrl) URL.revokeObjectURL(state.slideUrl);
  state.slideUrl = url;
  return el('img', { class: 'slide', src: url, alt: 'Slide' });
}

function renderBlock(block) {
  const root = document.getElementById('block');
  root.replaceChildren();
  flash('');
  clearInterval(state.countdown);
  state.quizSig = null;
  state.block = block;
  if (!block) { root.append(el('p', { class: 'muted', text: 'Waiting for the tutor…' })); return; }
  const c = block.config || {};
  if (block.slide_asset_id) {
    const holder = el('div', { class: 'slide-holder' });
    root.append(holder);
    slideImage(block.slide_asset_id).then((node) => { if (state.block === block) holder.replaceChildren(node); });
  }
  const make = controls[block.type];
  root.append(
    make ? make(c, block.state?.mine ?? null)
      : el('p', { class: 'muted', text: ['quiz', 'slide'].includes(block.type) ? '' : 'Follow along with your tutor.' }),
    el('div', { id: 'dynamic' }),
    el('div', { id: 'results', class: 'results' }),
  );
  updateDynamic(block);
}

// ------------------------------------------------------------------ session lifecycle

async function refresh() {
  const r = await authed('GET', '/state');
  if (!r || r.status !== 200) return;
  const s = r.data;
  document.getElementById('session-title').textContent = s.title ?? 'Session';
  document.getElementById('session-status').textContent = s.status === 'live' ? 'Live' : 'Ended';
  if (s.session_revision !== state.revision) {
    state.revision = s.session_revision;
    renderBlock(s.current_block);
  } else if (s.current_block) {
    state.block = s.current_block;
    updateDynamic(s.current_block);
  }
}

async function renewPresence() {
  // only while the tab is in the foreground (spec: presence_renewed_at)
  if (document.visibilityState !== 'visible') return;
  const r = await authed('POST', '/presence');
  if (r && r.data && r.data.credential) state.credential = r.data.credential;
}

function stopTimers() {
  state.timers.forEach(clearInterval);
  state.timers = [];
  clearInterval(state.countdown);
  if (state.rt) { state.rt.close(); state.rt = null; }
}

function onRealtime(msg) {
  switch (msg.type) {
    case 'auth_ok':
      refresh(); // (re)subscribed: cover anything missed while offline
      break;
    case 'block_change':
    case 'session_ended':
      if (typeof msg.session_revision !== 'number' || msg.session_revision !== state.revision) refresh();
      break;
    case 'activity_aggregate_update':
      if (state.block && msg.session_block_id === state.block.id && msg.aggregate) {
        renderAggregate(document.getElementById('results'), state.block.type, state.block.config, msg.aggregate);
      }
      break;
    case 'wall_update':
    case 'quiz_question_start':
    case 'quiz_question_reveal':
      if (state.block && msg.session_block_id === state.block.id) refresh();
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
  state.timers.push(setInterval(() => { if (!state.live) refresh(); }, POLL_INTERVAL_MS));
  state.timers.push(setInterval(renewPresence, PRESENCE_INTERVAL_MS));
  state.rt = connectRealtime({
    getToken: async () => {
      const r = await authed('POST', '/connection-token');
      return r && r.status === 200 ? r.data.token : null;
    },
    onMessage: onRealtime,
    onStatus: (up) => { state.live = up; },
    isFinal: (code) => code === 4410,
  });
}

function showJoin(message) {
  stopTimers();
  state.credential = null;
  state.revision = -1;
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
