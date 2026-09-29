import { createHmac } from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import WebSocket from 'ws';
import * as Y from 'yjs';
import * as syncProtocol from 'y-protocols/sync';
import * as encoding from 'lib0/encoding';
import * as decoding from 'lib0/decoding';
import { createServers } from '../src/server.js';
import { FilePersistence } from '../src/persistence.js';

export const KEY = Buffer.alloc(32, 0x33);
export const SECRET = 'internal-secret-internal-secret-0123';
export const ORIGIN = 'https://tutora.test';
export const A = 'a'.repeat(32);
export const B = 'b'.repeat(32);

export function mint(claims, key = KEY, ttl = 60) {
  const payload = Buffer.from(JSON.stringify({ aud: 'tutora-whiteboard', exp: Math.floor(Date.now() / 1000) + ttl, kind: 'whiteboard', tags: [], ...claims })).toString('base64url');
  return `${payload}.${createHmac('sha256', key).update(payload).digest('base64url')}`;
}

export async function startEnv(opts = {}) {
  const storage = opts.storage ?? fs.mkdtempSync(path.join(os.tmpdir(), 'wb-'));
  const env = createServers({
    tokenKey: KEY, internalSecret: SECRET, allowedOrigins: [ORIGIN],
    persistence: new FilePersistence(storage), logger: () => {}, limits: opts.limits ?? {},
  });
  await new Promise((r) => env.publicServer.listen(0, '127.0.0.1', r));
  await new Promise((r) => env.internalServer.listen(0, '127.0.0.1', r));
  return {
    ...env, storage,
    wsUrl: `ws://127.0.0.1:${env.publicServer.address().port}/wb`,
    internalUrl: `http://127.0.0.1:${env.internalServer.address().port}`,
    async close() {
      for (const c of env.wss.clients) c.terminate();
      await new Promise((r) => env.publicServer.close(r));
      await new Promise((r) => env.internalServer.close(r));
    },
  };
}

/** Minimal sync client mirroring the browser client's protocol. */
export function connect(env, claims, { origin = ORIGIN, sendAuth = true, query = '' } = {}) {
  const doc = new Y.Doc();
  const ws = new WebSocket(env.wsUrl + query, { headers: { origin } });
  const client = { doc, ws, closed: null, authed: false, role: null };
  client.closedPromise = new Promise((resolve) => {
    ws.on('close', (code, reason) => { client.closed = { code, reason: reason.toString() }; resolve(client.closed); });
    ws.on('unexpected-response', (req, res) => { client.closed = { code: res.statusCode }; resolve(client.closed); });
    ws.on('error', () => {});
  });
  client.ready = new Promise((resolve) => {
    ws.on('open', () => { if (sendAuth) ws.send(JSON.stringify({ type: 'auth', token: mint(claims) })); });
    ws.on('message', (data, isBinary) => {
      if (!isBinary) {
        const m = JSON.parse(data.toString());
        if (m.type === 'auth_ok') {
          client.authed = true; client.role = m.role;
          const enc = encoding.createEncoder();
          encoding.writeVarUint(enc, 0);
          syncProtocol.writeSyncStep1(enc, doc);
          ws.send(encoding.toUint8Array(enc));
          resolve(client);
        }
        return;
      }
      const dec = decoding.createDecoder(new Uint8Array(data));
      const enc = encoding.createEncoder();
      if (decoding.readVarUint(dec) !== 0) return;
      encoding.writeVarUint(enc, 0);
      syncProtocol.readSyncMessage(dec, enc, doc, 'remote');
      if (encoding.length(enc) > 1) ws.send(encoding.toUint8Array(enc));
    });
  });
  doc.on('update', (update, origin) => {
    if (origin === 'remote' || ws.readyState !== WebSocket.OPEN) return;
    const enc = encoding.createEncoder();
    encoding.writeVarUint(enc, 0);
    syncProtocol.writeUpdate(enc, update);
    ws.send(encoding.toUint8Array(enc));
  });
  return client;
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function until(fn, ms = 2000) {
  const end = Date.now() + ms;
  while (Date.now() < end) { if (fn()) return true; await sleep(10); }
  return fn();
}

export function stroke(actor, bid = 1, extra = {}) {
  return { type: 'stroke', actor_id: actor, session_block_id: bid, data: { points: [0.1, 0.1, 0.2, 0.2], color: '#ff0000', width: 3, ...extra } };
}

export async function moderate(env, body, secret = SECRET) {
  const r = await fetch(`${env.internalUrl}/internal/moderate`, {
    method: 'POST', headers: { authorization: `Bearer ${secret}`, 'content-type': 'application/json' }, body: JSON.stringify(body),
  });
  return { status: r.status, body: await r.json() };
}
