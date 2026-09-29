import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { verifyToken } from '../src/token.js';
import { mint, KEY } from './helpers.js';

const v = JSON.parse(fs.readFileSync(new URL('./php_tokens.json', import.meta.url)));
const key = Buffer.from(v.key_hex, 'hex');

test('verifies a token minted by the PHP app', () => {
  const c = verifyToken(v.participant, key, v.now_unix);
  assert.deepEqual(c, { sid: 7, bid: 70, actor: '0123456789abcdef0123456789abcdef', role: 'participant', kind: 'annotate', tags: ['t1', 't2'], exp: v.now_unix + 60, iat: v.now_unix });
});

test('rejects expiry, other audience, wrong key, tampering', () => {
  assert.equal(verifyToken(v.participant, key, v.now_unix + 60), null);
  assert.equal(verifyToken(v.relay_audience, key, v.now_unix), null);
  assert.equal(verifyToken(v.participant, Buffer.alloc(32), v.now_unix), null);
  const [p, s] = v.participant.split('.');
  const forged = Buffer.from(Buffer.from(p, 'base64url').toString().replace('"participant"', '"tutor"')).toString('base64url');
  assert.equal(verifyToken(`${forged}.${s}`, key, v.now_unix), null);
  for (const g of ['', '.', 'a.b', 'x'.repeat(5000)]) assert.equal(verifyToken(g, key, v.now_unix), null);
});

test('rejects inconsistent role/actor and bad claims', () => {
  const bad = [
    { sid: 1, bid: 1, actor: 'tutor', role: 'participant' },
    { sid: 1, bid: 1, actor: 'a'.repeat(32), role: 'tutor' },
    { sid: 1, bid: 1, actor: 'a'.repeat(32), role: 'admin' },
    { sid: 1, bid: 0, actor: 'tutor', role: 'tutor' },
    { sid: 1, bid: 1, actor: 'tutor', role: 'tutor', kind: 'canvas' },
    { sid: 1, bid: 1, actor: 'tutor', role: 'tutor', tags: ['<script>'] },
  ];
  for (const c of bad) assert.equal(verifyToken(mint(c), KEY), null, JSON.stringify(c));
});
