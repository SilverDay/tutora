// Server-side authorisation of whiteboard changes (spec: Whiteboard — explicit entity model).
//
// Document shape: Y.Map("entities"): entity_uuid -> plain JSON
//   { type, actor_id, session_block_id, data }
// Coordinates are normalised to [0, 1] relative to the board. Entities are immutable JSON
// values (an edit replaces the value), so every mutation is visible as a key change on the
// entities map and can be authorised before it is applied to the real document.
import * as Y from 'yjs';

export const LIMITS = {
  MAX_ENTITIES: 5000,
  MAX_ENTITIES_PER_ACTOR: 500,
  MAX_ENTITY_BYTES: 16 * 1024,
  MAX_STROKE_POINTS: 2000,
  MAX_TEXT: 500,
};

const KEY = /^[A-Za-z0-9_-]{8,64}$/;
const COLOR = /^#[0-9a-fA-F]{6}$/;
// control characters except newline/tab, and bidi overrides (visual spoofing)
const BAD_TEXT = /[\u0000-\u0008\u000B-\u001F\u007F‪-‮⁦-⁩]/;
const WHITEBOARD_TYPES = new Set(['stroke', 'line', 'arrow', 'rect', 'ellipse', 'text']);

export class Rejected extends Error {}

const unit = (v) => typeof v === 'number' && Number.isFinite(v) && v >= 0 && v <= 1;
const int = (v, lo, hi) => Number.isInteger(v) && v >= lo && v <= hi;
function exactKeys(obj, keys) {
  return obj && typeof obj === 'object' && !Array.isArray(obj)
    && Object.keys(obj).length === keys.length && keys.every((k) => Object.hasOwn(obj, k));
}

/** Validates the shape of one entity for the given token claims. Throws Rejected. */
export function validateEntity(value, claims) {
  if (value instanceof Y.AbstractType) throw new Rejected('nested shared types are not allowed');
  if (!exactKeys(value, ['type', 'actor_id', 'session_block_id', 'data'])) throw new Rejected('bad entity shape');
  if (JSON.stringify(value).length > LIMITS.MAX_ENTITY_BYTES) throw new Rejected('entity too large');
  if (value.session_block_id !== claims.bid) throw new Rejected('wrong block');
  if (typeof value.actor_id !== 'string') throw new Rejected('bad actor');
  const d = value.data;
  if (claims.kind === 'annotate') {
    if (value.type !== 'tag') throw new Rejected('annotate boards only accept tags');
    if (!exactKeys(d, ['x', 'y', 'tag_id']) || !unit(d.x) || !unit(d.y) || !claims.tags.includes(d.tag_id)) throw new Rejected('bad tag');
    return;
  }
  if (!WHITEBOARD_TYPES.has(value.type)) throw new Rejected('unknown entity type');
  switch (value.type) {
    case 'stroke':
      if (!exactKeys(d, ['points', 'color', 'width']) || !Array.isArray(d.points)
        || d.points.length < 4 || d.points.length % 2 !== 0 || d.points.length > LIMITS.MAX_STROKE_POINTS * 2
        || !d.points.every(unit) || !COLOR.test(d.color) || !int(d.width, 1, 40)) throw new Rejected('bad stroke');
      return;
    case 'line':
    case 'arrow':
      if (!exactKeys(d, ['x1', 'y1', 'x2', 'y2', 'color', 'width']) || ![d.x1, d.y1, d.x2, d.y2].every(unit)
        || !COLOR.test(d.color) || !int(d.width, 1, 40)) throw new Rejected('bad line');
      return;
    case 'rect':
    case 'ellipse':
      if (!exactKeys(d, ['x', 'y', 'w', 'h', 'color', 'width']) || ![d.x, d.y, d.w, d.h].every(unit)
        || d.x + d.w > 1.000001 || d.y + d.h > 1.000001 || !COLOR.test(d.color) || !int(d.width, 1, 40)) throw new Rejected('bad shape');
      return;
    case 'text':
      if (!exactKeys(d, ['x', 'y', 'text', 'color', 'size']) || !unit(d.x) || !unit(d.y)
        || typeof d.text !== 'string' || d.text.trim().length === 0 || [...d.text].length > LIMITS.MAX_TEXT
        || BAD_TEXT.test(d.text) || !COLOR.test(d.color) || !int(d.size, 8, 96)) throw new Rejected('bad text');
      return;
    default:
      throw new Rejected('unknown entity type');
  }
}

/**
 * Applies `update` to a throwaway copy of `doc` and authorises every resulting change.
 * Throws Rejected if anything is not allowed; the real document is never touched here.
 */
export function authoriseUpdate(doc, update, claims) {
  if (claims.role === 'spectator') throw new Rejected('read-only');
  const probe = new Y.Doc();
  Y.applyUpdate(probe, Y.encodeStateAsUpdate(doc));
  const entities = probe.getMap('entities');
  let changes = null;
  entities.observe((ev) => { changes = ev.changes.keys; });
  try {
    Y.applyUpdate(probe, update);
  } catch {
    throw new Rejected('malformed update');
  }
  // incomplete updates would integrate later without being checked
  if (probe.store.pendingStructs !== null || probe.store.pendingDs !== null) throw new Rejected('incomplete update');
  for (const name of probe.share.keys()) {
    if (name !== 'entities') throw new Rejected('unexpected shared type');
  }
  if (changes === null) return; // nothing changed (e.g. duplicate)
  const tutor = claims.role === 'tutor';
  let adds = 0;
  for (const [key, { action, oldValue }] of changes) {
    if (!KEY.test(key)) throw new Rejected('bad key');
    if (action === 'add') {
      const v = entities.get(key);
      validateEntity(v, claims);
      if (v.actor_id !== claims.actor) throw new Rejected('entities must be created as yourself');
      adds++;
    } else if (action === 'update') {
      const v = entities.get(key);
      validateEntity(v, claims);
      if (!tutor && oldValue?.actor_id !== claims.actor) throw new Rejected('not your entity');
      if (v.actor_id !== oldValue?.actor_id) throw new Rejected('ownership cannot change');
    } else if (action === 'delete') {
      if (!tutor && oldValue?.actor_id !== claims.actor) throw new Rejected('not your entity');
    }
  }
  if (adds > 0) {
    if (entities.size > LIMITS.MAX_ENTITIES) throw new Rejected('board is full');
    let mine = 0;
    for (const v of entities.values()) if (v?.actor_id === claims.actor) mine++;
    if (!tutor && mine > LIMITS.MAX_ENTITIES_PER_ACTOR) throw new Rejected('too many entities');
  }
}
