// Entry point. Environment:
//   WHITEBOARD_PUBLIC_ADDR    host:port for the WebSocket endpoint (/wb), default 127.0.0.1:8091
//   WHITEBOARD_INTERNAL_ADDR  host:port for the internal API, default 127.0.0.1:8082 (private only)
//   WHITEBOARD_TOKEN_KEY      64 hex chars, same as the PHP app's WHITEBOARD_TOKEN_KEY
//   WHITEBOARD_INTERNAL_SECRET >= 32 chars, shared with the PHP app
//   ALLOWED_ORIGINS           comma-separated exact browser origins
//   STORAGE_PATH              absolute path; documents are kept under <STORAGE_PATH>/whiteboard
import { createServers } from './server.js';
import { FilePersistence } from './persistence.js';

function fail(msg) { console.error(`whiteboard configuration error: ${msg}`); process.exit(1); }
const key = process.env.WHITEBOARD_TOKEN_KEY ?? '';
if (!/^[0-9a-fA-F]{64}$/.test(key)) fail('WHITEBOARD_TOKEN_KEY must be 64 hex characters');
const secret = process.env.WHITEBOARD_INTERNAL_SECRET ?? '';
if (secret.length < 32) fail('WHITEBOARD_INTERNAL_SECRET must be at least 32 characters');
const origins = (process.env.ALLOWED_ORIGINS ?? '').split(',').map((s) => s.trim()).filter(Boolean);
if (origins.length === 0) fail('ALLOWED_ORIGINS must list at least one origin');
if (!process.env.STORAGE_PATH) fail('STORAGE_PATH is required');

const split = (addr, def) => { const [h, p] = (addr || def).split(':'); return [h, Number(p)]; };
const { publicServer, internalServer, manager } = createServers({
  tokenKey: Buffer.from(key, 'hex'),
  internalSecret: secret,
  allowedOrigins: origins,
  persistence: new FilePersistence(process.env.STORAGE_PATH),
});
const [ph, pp] = split(process.env.WHITEBOARD_PUBLIC_ADDR, '127.0.0.1:8091');
const [ih, ip] = split(process.env.WHITEBOARD_INTERNAL_ADDR, '127.0.0.1:8082');
publicServer.listen(pp, ph, () => console.error(`whiteboard listening on ${ph}:${pp}`));
internalServer.listen(ip, ih, () => console.error(`whiteboard internal API on ${ih}:${ip}`));

const shutdown = () => {
  manager.flushAll();
  publicServer.close();
  internalServer.close();
  setTimeout(() => process.exit(0), 500).unref();
};
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
