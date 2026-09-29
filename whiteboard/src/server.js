// Tutora whiteboard sidecar: y-protocols sync over WebSocket with token authentication and
// per-entity authorisation of every update (see validate.js). Awareness is not relayed.
import http from 'node:http';
import { createHash, timingSafeEqual } from 'node:crypto';
import { WebSocketServer } from 'ws';
import * as Y from 'yjs';
import * as syncProtocol from 'y-protocols/sync';
import * as encoding from 'lib0/encoding';
import * as decoding from 'lib0/decoding';
import { verifyToken } from './token.js';
import { authoriseUpdate, Rejected } from './validate.js';
import { DocManager } from './docs.js';

const MESSAGE_SYNC = 0;
const MESSAGE_AWARENESS = 1;
const ENDED_TTL_MS = 5 * 60_000; // > 60 s token lifetime

export const CLOSE = { AUTH_TIMEOUT: 4408, AUTH_FAILED: 4401, FORBIDDEN: 4403, LIMIT: 4429, BAD: 4400, ENDED: 4410 };

export function createServers(cfg) {
  const limits = { authDeadlineMs: 5000, maxPayload: 64 * 1024, ratePerSec: 30, burst: 60, maxConnsPerActor: 10, ...cfg.limits };
  const now = cfg.now ?? (() => Date.now());
  const manager = new DocManager(cfg.persistence);
  const origins = new Set(cfg.allowedOrigins);
  const ended = new Map();
  const buckets = new Map(); // `${sid}:${actor}` -> { tokens, last, conns }
  const log = cfg.logger ?? ((m) => console.error(`whiteboard ${new Date().toISOString()} ${m}`));

  const wss = new WebSocketServer({ noServer: true, maxPayload: limits.maxPayload });
  const publicServer = http.createServer((req, res) => { res.writeHead(404).end(); });
  publicServer.on('upgrade', (req, socket, head) => {
    const url = new URL(req.url ?? '/', 'http://x');
    // exact-match Origin allowlist; the token is never accepted from the URL
    if (url.pathname !== '/wb' || !origins.has(req.headers.origin ?? '')) {
      socket.write('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
      socket.destroy();
      return;
    }
    wss.handleUpgrade(req, socket, head, (ws) => onConnection(ws));
  });

  function bucketFor(claims) {
    const k = `${claims.sid}:${claims.actor}`;
    let b = buckets.get(k);
    if (!b) { b = { tokens: limits.burst, last: now(), conns: 0 }; buckets.set(k, b); }
    return b;
  }

  function allow(b) {
    const t = now();
    b.tokens = Math.min(limits.burst, b.tokens + ((t - b.last) / 1000) * limits.ratePerSec);
    b.last = t;
    if (b.tokens < 1) return false;
    b.tokens -= 1;
    return true;
  }

  function onConnection(ws) {
    let claims = null;
    let entry = null;
    let bucket = null;
    const conn = {
      sendUpdate(update) {
        const enc = encoding.createEncoder();
        encoding.writeVarUint(enc, MESSAGE_SYNC);
        syncProtocol.writeUpdate(enc, update);
        send(encoding.toUint8Array(enc));
      },
      close(code, reason) { ws.close(code, reason); },
    };
    const send = (data) => { if (ws.readyState === ws.OPEN) ws.send(data); };
    const authTimer = setTimeout(() => ws.close(CLOSE.AUTH_TIMEOUT, 'authentication required'), limits.authDeadlineMs);

    ws.on('message', (data, isBinary) => {
      if (claims === null) {
        clearTimeout(authTimer);
        let msg;
        try { msg = isBinary ? null : JSON.parse(data.toString('utf8')); } catch { msg = null; }
        const c = msg?.type === 'auth' ? verifyToken(msg.token, cfg.tokenKey, Math.floor(now() / 1000)) : null;
        if (!c) { ws.close(CLOSE.AUTH_FAILED, 'authentication failed'); return; }
        const endedAt = ended.get(c.sid);
        if (endedAt !== undefined && now() - endedAt < ENDED_TTL_MS) { ws.close(CLOSE.ENDED, 'session ended'); return; }
        bucket = bucketFor(c);
        if (bucket.conns >= limits.maxConnsPerActor) { ws.close(CLOSE.LIMIT, 'too many connections'); return; }
        bucket.conns++;
        claims = c;
        entry = manager.get(c.sid, c.bid);
        entry.conns.add(conn);
        send(JSON.stringify({ type: 'auth_ok', role: c.role }));
        // start sync: our state vector, so the client sends what we are missing
        const enc = encoding.createEncoder();
        encoding.writeVarUint(enc, MESSAGE_SYNC);
        syncProtocol.writeSyncStep1(enc, entry.doc);
        send(encoding.toUint8Array(enc));
        log(`connect session=${c.sid} block=${c.bid} role=${c.role}`);
        return;
      }
      if (!isBinary) {
        if (data.toString('utf8') === '{"type":"ping"}') send('{"type":"pong"}');
        return;
      }
      try {
        handleBinary(new Uint8Array(data));
      } catch (e) {
        ws.close(e instanceof Rejected ? CLOSE.FORBIDDEN : CLOSE.BAD, e instanceof Rejected ? 'rejected' : 'bad message');
      }
    });

    function handleBinary(buf) {
      const dec = decoding.createDecoder(buf);
      const type = decoding.readVarUint(dec);
      if (type === MESSAGE_AWARENESS) return; // not relayed in v1
      if (type !== MESSAGE_SYNC) throw new Error('unsupported');
      const syncType = decoding.readVarUint(dec);
      if (syncType === syncProtocol.messageYjsSyncStep1) {
        const sv = decoding.readVarUint8Array(dec);
        const enc = encoding.createEncoder();
        encoding.writeVarUint(enc, MESSAGE_SYNC);
        syncProtocol.writeSyncStep2(enc, entry.doc, sv);
        send(encoding.toUint8Array(enc));
        return;
      }
      if (syncType !== syncProtocol.messageYjsSyncStep2 && syncType !== syncProtocol.messageYjsUpdate) throw new Error('unsupported');
      const update = decoding.readVarUint8Array(dec);
      if (!allow(bucket)) throw new Rejected('rate limited');
      authoriseUpdate(entry.doc, update, claims); // throws Rejected
      Y.applyUpdate(entry.doc, update, conn);
    }

    ws.on('close', () => {
      clearTimeout(authTimer);
      if (entry) manager.release(entry, conn);
      if (bucket) {
        bucket.conns--;
        if (bucket.conns <= 0) buckets.delete(`${claims.sid}:${claims.actor}`);
      }
    });
    ws.on('error', () => ws.terminate());
  }

  // ---------------------------------------------------------------- internal API
  const secretHash = createHash('sha256').update(cfg.internalSecret).digest();
  const internalServer = http.createServer((req, res) => {
    const reply = (status, body) => {
      res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }).end(JSON.stringify(body));
    };
    if (req.method === 'GET' && req.url === '/internal/health') {
      reply(200, { ok: true, docs: manager.docs.size });
      return;
    }
    if (req.method !== 'POST' || req.url !== '/internal/moderate') { reply(404, { error: 'not found' }); return; }
    const auth = req.headers.authorization ?? '';
    const given = createHash('sha256').update(auth.startsWith('Bearer ') ? auth.slice(7) : '').digest();
    if (!auth.startsWith('Bearer ') || !timingSafeEqual(given, secretHash)) { reply(401, { error: 'unauthorized' }); return; }
    let body = '';
    req.setEncoding('utf8');
    req.on('data', (chunk) => { body += chunk; if (body.length > 4096) req.destroy(); });
    req.on('end', () => {
      let m;
      try { m = JSON.parse(body); } catch { reply(400, { error: 'bad json' }); return; }
      const sid = m?.session_id;
      if (!Number.isInteger(sid) || sid <= 0) { reply(400, { error: 'bad session_id' }); return; }
      switch (m.action) {
        case 'clear': {
          if (!Number.isInteger(m.session_block_id) || m.session_block_id <= 0) { reply(400, { error: 'bad block' }); return; }
          reply(200, { deleted: manager.deleteWhere(sid, m.session_block_id, () => true) });
          return;
        }
        case 'remove_actor': {
          if (typeof m.actor_id !== 'string' || !/^[0-9a-f]{32}$/.test(m.actor_id)) { reply(400, { error: 'bad actor' }); return; }
          let deleted = 0;
          for (const bid of manager.blocksOfSession(sid)) deleted += manager.deleteWhere(sid, bid, (v) => v?.actor_id === m.actor_id);
          reply(200, { deleted });
          return;
        }
        case 'end_session':
          ended.set(sid, now());
          manager.closeSession(sid, CLOSE.ENDED, 'session ended');
          reply(200, { ok: true });
          return;
        case 'drop_session':
          ended.set(sid, now());
          manager.dropSession(sid);
          reply(200, { ok: true });
          return;
        default:
          reply(400, { error: 'unknown action' });
      }
    });
  });

  return { publicServer, internalServer, manager, wss };
}
