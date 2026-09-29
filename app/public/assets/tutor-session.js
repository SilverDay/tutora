// Tutor live-session console: live participant count, live results, Write responses,
// wall moderation and quiz stats. Text is inserted via textContent only.
import { connectRealtime } from './realtime.js';
import { el } from './dom.js';
import { renderAggregate } from './results.js';

const presence = document.getElementById('presence');
const results = document.getElementById('tutor-results');
const sessionId = presence?.dataset.sessionId ?? results?.dataset.sessionId ?? null;
const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
let current = null;
let quizPoll = null;

async function getToken() {
  const res = await fetch(`/api/tutor/sessions/${encodeURIComponent(sessionId)}/connection-token`, {
    method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
    credentials: 'same-origin', cache: 'no-store',
  });
  if (!res.ok) return null;
  return (await res.json()).token;
}

/** A POST form carrying the CSRF token (full page navigation, like the server-rendered forms). */
function postForm(action, fields, label, cls) {
  const f = el('form', { method: 'post', action, class: 'inline' },
    el('input', { type: 'hidden', name: '_csrf', value: csrf }));
  for (const [k, v] of Object.entries(fields)) f.append(el('input', { type: 'hidden', name: k, value: v }));
  f.append(el('button', { type: 'submit', class: cls || 'secondary', text: label }));
  return f;
}

function removeActorForm(actor) {
  // "Participant 3f2a…" is derived from the opaque per-session moderation id; it identifies
  // nobody outside this session
  return postForm(`/sessions/${encodeURIComponent(sessionId)}/moderation/remove-actor`, { actor }, 'Remove this participant’s contributions', 'danger');
}

function actorLabel(actor) {
  return actor === 'tutor' ? 'Tutor' : `Participant ${actor.slice(0, 4)}`;
}

function render(block) {
  if (!results) return;
  results.replaceChildren();
  if (!block || !block.state) return;
  const s = block.state;
  const agg = el('div');
  if (s.aggregate !== undefined) renderAggregate(agg, block.type, block.config, s.aggregate);
  results.append(agg);

  if (block.type === 'write' && s.responses) {
    results.append(el('h3', { text: 'Responses (visible to you only)' }),
      el('ul', { class: 'responses' }, s.responses.map(r => el('li', {},
        el('p', { class: 'response-text', text: r.text }),
        el('p', { class: 'muted' }, actorLabel(r.actor), ' ', removeActorForm(r.actor))))));
  }
  if (block.type === 'wall' && s.cards) {
    for (const col of block.config.columns) {
      results.append(el('h3', { text: col.label }), el('ul', { class: 'responses' },
        s.cards.filter(c => c.column_id === col.id).map(c => el('li', {},
          el('p', { class: 'response-text', text: c.text }),
          el('p', { class: 'muted' }, actorLabel(c.actor), ' ',
            postForm(`/sessions/${encodeURIComponent(sessionId)}/wall/cards/${c.id}/delete`, {}, 'Delete card', 'secondary'),
            c.actor !== 'tutor' ? removeActorForm(c.actor) : null)))));
    }
  }
  if (block.type === 'quiz' && s.quiz) {
    const qs = Object.fromEntries(block.config.questions.map(q => [q.id, q]));
    results.append(el('h3', { text: 'Quiz results' }), el('ul', {}, s.quiz.questions.map(q => el('li', {
      text: `${qs[q.id].prompt}: ${q.results.answers} answer(s), ${q.results.correct} correct${q.status ? ` · ${q.status}` : ''}`,
    }))));
    // while a question is open, poll so an expired timer is revealed promptly (lazy reveal)
    const open = s.quiz.questions.some(q => q.status === 'OPEN');
    clearInterval(quizPoll);
    if (open) quizPoll = setInterval(refresh, 3000);
  }
}

async function refresh() {
  const res = await fetch(`/api/tutor/sessions/${encodeURIComponent(sessionId)}/state`, { credentials: 'same-origin', cache: 'no-store' });
  if (!res.ok) return;
  const s = await res.json();
  if (current && s.current_session_block_id !== current.id) { location.reload(); return; }
  const before = current?.state?.quiz ? JSON.stringify(current.state.quiz.questions.map(q => q.status)) : null;
  current = s.current_block;
  const after = current?.state?.quiz ? JSON.stringify(current.state.quiz.questions.map(q => q.status)) : null;
  if (before !== null && before !== after) { location.reload(); return; } // quiz controls are server-rendered
  render(current);
}

if (sessionId) {
  refresh();
  const rt = connectRealtime({
    getToken,
    onStatus: (up) => { if (!up && presence) presence.textContent = 'realtime offline'; },
    onMessage: (msg) => {
      // let other modules (whiteboard) see relay messages
      document.dispatchEvent(new CustomEvent('tutora:realtime', { detail: msg }));
      if (msg.type === 'presence' && presence) {
        presence.textContent = `${msg.participants} participant${msg.participants === 1 ? '' : 's'} connected`;
      } else if (msg.type === 'session_ended' && presence) {
        presence.textContent = 'session ended';
      } else if (['activity_aggregate_update', 'wall_update', 'quiz_question_start', 'quiz_question_reveal', 'block_change'].includes(msg.type)) {
        refresh();
      }
    },
    isFinal: (code) => code === 4410,
  });
  window.tutoraRealtime = { send: (msg) => rt.send(msg) };
}
