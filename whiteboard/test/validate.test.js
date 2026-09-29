import test from 'node:test';
import assert from 'node:assert/strict';
import * as Y from 'yjs';
import { authoriseUpdate, validateEntity, Rejected } from '../src/validate.js';
import { A, B, stroke } from './helpers.js';

const P = (actor = A, extra = {}) => ({ sid: 1, bid: 1, actor, role: 'participant', kind: 'whiteboard', tags: [], ...extra });
const T = { sid: 1, bid: 1, actor: 'tutor', role: 'tutor', kind: 'whiteboard', tags: [] };

/** Builds an update produced by a client doc that starts from `server` state. */
function clientUpdate(server, mutate) {
  const client = new Y.Doc();
  Y.applyUpdate(client, Y.encodeStateAsUpdate(server));
  const sv = Y.encodeStateVector(client);
  mutate(client);
  return Y.encodeStateAsUpdate(client, sv);
}

function serverWith(entities) {
  const doc = new Y.Doc();
  const m = doc.getMap('entities');
  for (const [k, v] of Object.entries(entities)) m.set(k, v);
  return doc;
}

test('participant may add own entity', () => {
  const s = serverWith({});
  authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('entities').set('e-000001', stroke(A))), P());
});

test('participant may not create entities as someone else or for another block', () => {
  const s = serverWith({});
  assert.throws(() => authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('entities').set('e-000001', stroke(B))), P()), Rejected);
  assert.throws(() => authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('entities').set('e-000001', stroke(A, 2))), P()), Rejected);
});

test('participant may edit/delete only own entities; ownership cannot change', () => {
  const s = serverWith({ 'mine-0001': stroke(A), 'theirs-01': stroke(B) });
  authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('entities').set('mine-0001', stroke(A, 1, { color: '#00ff00' }))), P());
  authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('entities').delete('mine-0001')), P());
  assert.throws(() => authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('entities').delete('theirs-01')), P()), Rejected);
  assert.throws(() => authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('entities').set('theirs-01', stroke(B, 1, { color: '#000000' }))), P()), Rejected);
  assert.throws(() => authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('entities').set('mine-0001', stroke(B))), P()), Rejected);
});

test('tutor may edit and delete any entity but not re-attribute it', () => {
  const s = serverWith({ 'theirs-01': stroke(B) });
  authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('entities').set('theirs-01', stroke(B, 1, { width: 9 }))), T);
  authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('entities').delete('theirs-01')), T);
  assert.throws(() => authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('entities').set('theirs-01', stroke('tutor'))), T), Rejected);
});

test('spectators cannot write at all', () => {
  const s = serverWith({});
  assert.throws(() => authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('entities').set('e-000001', stroke(A))), P(A, { role: 'spectator' })), Rejected);
});

test('no other shared types, no nested Y types, no incomplete updates', () => {
  const s = serverWith({});
  assert.throws(() => authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('other').set('x', 1)), P()), Rejected);
  assert.throws(() => authoriseUpdate(s, clientUpdate(s, (c) => c.getText('entities2').insert(0, 'x')), P()), Rejected);
  assert.throws(() => authoriseUpdate(s, clientUpdate(s, (c) => c.getMap('entities').set('e-000001', new Y.Map())), P()), Rejected);
  // an update that depends on state the server does not have
  const c = new Y.Doc();
  c.getMap('entities').set('e-000001', stroke(A));
  const sv = Y.encodeStateVector(c);
  c.getMap('entities').set('e-000002', stroke(A));
  assert.throws(() => authoriseUpdate(s, Y.encodeStateAsUpdate(c, sv), P()), Rejected);
  assert.throws(() => authoriseUpdate(s, new Uint8Array([1, 2, 3, 250, 250]), P()), Rejected);
});

test('entity schema', () => {
  const ok = [
    stroke(A),
    { type: 'line', actor_id: A, session_block_id: 1, data: { x1: 0, y1: 0, x2: 1, y2: 1, color: '#123456', width: 2 } },
    { type: 'rect', actor_id: A, session_block_id: 1, data: { x: 0.1, y: 0.1, w: 0.5, h: 0.5, color: '#123456', width: 2 } },
    { type: 'text', actor_id: A, session_block_id: 1, data: { x: 0.5, y: 0.5, text: 'Hello\nworld', color: '#000000', size: 16 } },
  ];
  for (const e of ok) validateEntity(e, P());
  const bad = [
    { ...stroke(A), extra: 1 },
    stroke(A, 1, { points: [0.1, 0.1, 2, 0.2] }),
    stroke(A, 1, { points: [0.1, 0.1] }),
    stroke(A, 1, { color: 'red' }),
    stroke(A, 1, { width: 100 }),
    stroke(A, 1, { points: new Array(4002).fill(0.5) }),
    { type: 'rect', actor_id: A, session_block_id: 1, data: { x: 0.8, y: 0, w: 0.5, h: 0.1, color: '#000000', width: 1 } },
    { type: 'text', actor_id: A, session_block_id: 1, data: { x: 0, y: 0, text: 'evil‮txt', color: '#000000', size: 16 } },
    { type: 'text', actor_id: A, session_block_id: 1, data: { x: 0, y: 0, text: 'x'.repeat(501), color: '#000000', size: 16 } },
    { type: 'image', actor_id: A, session_block_id: 1, data: {} },
    { type: 'tag', actor_id: A, session_block_id: 1, data: { x: 0, y: 0, tag_id: 't1' } },
  ];
  for (const e of bad) assert.throws(() => validateEntity(e, P()), Rejected, JSON.stringify(e).slice(0, 80));
});

test('annotate boards accept only configured tags', () => {
  const claims = P(A, { kind: 'annotate', tags: ['risk', 'asset'] });
  validateEntity({ type: 'tag', actor_id: A, session_block_id: 1, data: { x: 0.2, y: 0.3, tag_id: 'risk' } }, claims);
  assert.throws(() => validateEntity({ type: 'tag', actor_id: A, session_block_id: 1, data: { x: 0.2, y: 0.3, tag_id: 'other' } }, claims), Rejected);
  assert.throws(() => validateEntity(stroke(A), claims), Rejected);
});
