// Verifies whiteboard connection tokens minted by PHP (Tutora\Security\HmacToken,
// aud=tutora-whiteboard): base64url(json claims) "." base64url(HMAC-SHA256), unpadded.
import { createHmac, timingSafeEqual } from 'node:crypto';

export const AUDIENCE = 'tutora-whiteboard';
const PARTICIPANT_ACTOR = /^[0-9a-f]{32}$/;
const TAG_ID = /^[A-Za-z0-9_-]{1,32}$/;

/**
 * @returns {{sid:number,bid:number,actor:string,role:'tutor'|'participant'|'spectator',kind:'whiteboard'|'annotate',tags:string[],exp:number,iat:number}|null}
 *   iat is 0 when the token carries none (such a token never passes a revocation check)
 */
export function verifyToken(token, key, nowSeconds = Math.floor(Date.now() / 1000)) {
  if (typeof token !== 'string' || token.length === 0 || token.length > 4096) return null;
  const parts = token.split('.');
  if (parts.length !== 2) return null;
  const [payload, sig] = parts;
  let given;
  try { given = Buffer.from(sig, 'base64url'); } catch { return null; }
  const expected = createHmac('sha256', key).update(payload).digest();
  if (given.length !== expected.length || !timingSafeEqual(given, expected)) return null;
  let c;
  try { c = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8')); } catch { return null; }
  if (!c || typeof c !== 'object') return null;
  const okRole = c.role === 'tutor' ? c.actor === 'tutor'
    : (c.role === 'participant' || c.role === 'spectator') && PARTICIPANT_ACTOR.test(c.actor ?? '');
  if (c.aud !== AUDIENCE || !Number.isInteger(c.exp) || c.exp <= nowSeconds
    || !Number.isInteger(c.sid) || c.sid <= 0 || !Number.isInteger(c.bid) || c.bid <= 0
    || !okRole || (c.kind !== 'whiteboard' && c.kind !== 'annotate')
    || !Array.isArray(c.tags) || c.tags.length > 50 || !c.tags.every((t) => typeof t === 'string' && TAG_ID.test(t))
    || (c.iat !== undefined && !Number.isInteger(c.iat))) {
    return null;
  }
  return { sid: c.sid, bid: c.bid, actor: c.actor, role: c.role, kind: c.kind, tags: c.tags, exp: c.exp, iat: c.iat ?? 0 };
}
