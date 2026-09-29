import test from 'node:test';
import assert from 'node:assert/strict';
import { startEnv, connect, until, sleep, stroke, moderate, mint, A, B, SECRET } from './helpers.js';
import { CLOSE } from '../src/server.js';

const P = (actor, bid = 1) => ({ sid: 1, bid, actor, role: 'participant' });
const TUTOR = { sid: 1, bid: 1, actor: 'tutor', role: 'tutor' };

test('origin allowlist and first-message authentication', async () => {
  const env = await startEnv({ limits: { authDeadlineMs: 200 } });
  try {
    const foreign = connect(env, P(A), { origin: 'https://evil.example' });
    assert.equal((await foreign.closedPromise).code, 403);
    const silent = connect(env, P(A), { sendAuth: false });
    assert.equal((await silent.closedPromise).code, CLOSE.AUTH_TIMEOUT);
    const urlToken = connect(env, P(A), { sendAuth: false, query: `?token=${mint(P(A))}` });
    assert.equal((await urlToken.closedPromise).code, CLOSE.AUTH_TIMEOUT, 'a token in the URL never authenticates');
  } finally { await env.close(); }
});

test('bad token is rejected', async () => {
  const env = await startEnv();
  try {
    const c = connect(env, P(A), { sendAuth: false });
    c.ws.on('open', () => c.ws.send(JSON.stringify({ type: 'auth', token: 'nope' })));
    assert.equal((await c.closedPromise).code, CLOSE.AUTH_FAILED);
  } finally { await env.close(); }
});

test('entities sync between participants; foreign deletion is rejected and not applied', async () => {
  const env = await startEnv();
  try {
    const a = await connect(env, P(A)).ready;
    const b = await connect(env, P(B)).ready;
    a.doc.getMap('entities').set('a-stroke-1', stroke(A));
    assert.ok(await until(() => b.doc.getMap('entities').has('a-stroke-1')), 'b receives a\'s stroke');

    b.doc.getMap('entities').delete('a-stroke-1');
    assert.equal((await b.closedPromise).code, CLOSE.FORBIDDEN);
    await sleep(50);
    assert.ok(env.manager.get(1, 1).doc.getMap('entities').has('a-stroke-1'), 'server doc unchanged');
    assert.ok(a.doc.getMap('entities').has('a-stroke-1'), 'other clients unaffected');
  } finally { await env.close(); }
});

test('spectator connections are read-only', async () => {
  const env = await startEnv();
  try {
    const t = await connect(env, TUTOR).ready;
    t.doc.getMap('entities').set('t-stroke-1', stroke('tutor'));
    const s = await connect(env, { sid: 1, bid: 1, actor: A, role: 'spectator' }).ready;
    assert.ok(await until(() => s.doc.getMap('entities').has('t-stroke-1')), 'spectator sees content');
    s.doc.getMap('entities').set('s-stroke-1', stroke(A));
    assert.equal((await s.closedPromise).code, CLOSE.FORBIDDEN);
  } finally { await env.close(); }
});

test('documents are isolated per (session, block) from the token', async () => {
  const env = await startEnv();
  try {
    const a = await connect(env, P(A, 1)).ready;
    const other = await connect(env, { sid: 2, bid: 1, actor: B, role: 'participant' }).ready;
    a.doc.getMap('entities').set('a-stroke-1', stroke(A));
    await sleep(100);
    assert.equal(other.doc.getMap('entities').size, 0);
  } finally { await env.close(); }
});

test('rate limit is per actor across connections', async () => {
  const env = await startEnv({ limits: { burst: 5, ratePerSec: 0.001 } });
  try {
    const a1 = await connect(env, P(A)).ready;
    const a2 = await connect(env, P(A)).ready;
    for (let i = 0; i < 3; i++) a1.doc.getMap('entities').set(`a1-str-${i}`, stroke(A));
    await sleep(100);
    for (let i = 0; i < 3; i++) a2.doc.getMap('entities').set(`a2-str-${i}`, stroke(A));
    const closed = await Promise.race([a2.closedPromise, sleep(1000).then(() => null)]);
    assert.equal(closed?.code, CLOSE.FORBIDDEN, 'second tab shares the actor budget');
  } finally { await env.close(); }
});

test('connection cap per actor', async () => {
  const env = await startEnv({ limits: { maxConnsPerActor: 2 } });
  try {
    await connect(env, P(A)).ready;
    await connect(env, P(A)).ready;
    const third = connect(env, P(A));
    assert.equal((await third.closedPromise).code, CLOSE.LIMIT);
  } finally { await env.close(); }
});

test('oversized messages close the connection', async () => {
  const env = await startEnv({ limits: { maxPayload: 1024 } });
  try {
    const a = await connect(env, P(A)).ready;
    a.ws.send(Buffer.alloc(4096));
    assert.equal((await a.closedPromise).code, 1009);
  } finally { await env.close(); }
});

test('state survives a restart (snapshot + log)', async () => {
  const env = await startEnv();
  const storage = env.storage;
  const a = await connect(env, P(A)).ready;
  a.doc.getMap('entities').set('persist-01', stroke(A));
  await until(() => env.manager.get(1, 1).doc.getMap('entities').has('persist-01'));
  await env.close(); // no graceful flush: relies on the append-only log

  const env2 = await startEnv({ storage });
  try {
    const b = await connect(env2, P(B)).ready;
    assert.ok(await until(() => b.doc.getMap('entities').has('persist-01')));
  } finally { await env2.close(); }
});

test('internal moderation: auth, remove actor across blocks, clear, end session', async () => {
  const env = await startEnv();
  try {
    const a1 = await connect(env, P(A, 1)).ready;
    const a2 = await connect(env, P(A, 2)).ready;
    const b1 = await connect(env, P(B, 1)).ready;
    a1.doc.getMap('entities').set('a-block1-1', stroke(A, 1));
    a2.doc.getMap('entities').set('a-block2-1', stroke(A, 2));
    b1.doc.getMap('entities').set('b-block1-1', stroke(B, 1));
    await until(() => env.manager.get(1, 1).doc.getMap('entities').size === 2 && env.manager.get(1, 2).doc.getMap('entities').size === 1);

    assert.equal((await moderate(env, { session_id: 1, action: 'remove_actor', actor_id: A }, 'wrong')).status, 401);
    const r = await moderate(env, { session_id: 1, action: 'remove_actor', actor_id: A });
    assert.deepEqual(r, { status: 200, body: { deleted: 2 } });
    assert.ok(await until(() => !b1.doc.getMap('entities').has('a-block1-1') && b1.doc.getMap('entities').has('b-block1-1')), 'deletion propagated');

    assert.equal((await moderate(env, { session_id: 1, action: 'clear', session_block_id: 1 })).body.deleted, 1);
    assert.ok(await until(() => b1.doc.getMap('entities').size === 0));

    await moderate(env, { session_id: 1, action: 'end_session' });
    assert.equal((await b1.closedPromise).code, CLOSE.ENDED);
    const late = connect(env, P(B, 1));
    assert.equal((await late.closedPromise).code, CLOSE.ENDED, 'no reconnect to an ended session');
    assert.equal((await moderate(env, { session_id: 1, action: 'nope' })).status, 400);
    void SECRET;
  } finally { await env.close(); }
});
