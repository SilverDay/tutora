// In-memory registry of loaded documents and their connections.
import * as Y from 'yjs';

const COMPACT_EVERY = 200;
const UNLOAD_AFTER_MS = 60_000;

export class DocManager {
  constructor(persistence) {
    this.persistence = persistence;
    /** @type {Map<string,{doc:Y.Doc,sid:number,bid:number,conns:Set<object>,updates:number,idleTimer:any}>} */
    this.docs = new Map();
  }

  key(sid, bid) { return `${sid}:${bid}`; }

  get(sid, bid) {
    const k = this.key(sid, bid);
    let entry = this.docs.get(k);
    if (!entry) {
      const doc = this.persistence.load(sid, bid);
      entry = { doc, sid, bid, conns: new Set(), updates: 0, idleTimer: null };
      doc.on('update', (update, origin) => this.onUpdate(entry, update, origin));
      this.docs.set(k, entry);
    }
    clearTimeout(entry.idleTimer);
    entry.idleTimer = null;
    return entry;
  }

  onUpdate(entry, update, origin) {
    this.persistence.append(entry.sid, entry.bid, update);
    if (++entry.updates >= COMPACT_EVERY) {
      this.persistence.compact(entry.sid, entry.bid, entry.doc);
      entry.updates = 0;
    }
    for (const conn of entry.conns) {
      if (conn !== origin) conn.sendUpdate(update);
    }
  }

  release(entry, conn) {
    entry.conns.delete(conn);
    if (entry.conns.size === 0 && !entry.idleTimer) {
      entry.idleTimer = setTimeout(() => this.unload(entry), UNLOAD_AFTER_MS);
      entry.idleTimer.unref?.();
    }
  }

  unload(entry) {
    if (entry.conns.size > 0) return;
    this.persistence.compact(entry.sid, entry.bid, entry.doc);
    entry.doc.destroy();
    this.docs.delete(this.key(entry.sid, entry.bid));
  }

  /** Server-origin deletion of entities matching `predicate`; propagates to all clients. */
  deleteWhere(sid, bid, predicate) {
    const entry = this.get(sid, bid);
    const map = entry.doc.getMap('entities');
    let n = 0;
    entry.doc.transact(() => {
      for (const [k, v] of [...map.entries()]) {
        if (predicate(v)) { map.delete(k); n++; }
      }
    }, 'moderation');
    if (entry.conns.size === 0) this.unload(entry);
    return n;
  }

  blocksOfSession(sid) {
    const ids = new Set(this.persistence.blocks(sid));
    for (const e of this.docs.values()) if (e.sid === sid) ids.add(e.bid);
    return [...ids];
  }

  closeSession(sid, code, reason) {
    for (const e of this.docs.values()) {
      if (e.sid !== sid) continue;
      for (const c of [...e.conns]) c.close(code, reason);
    }
  }

  dropSession(sid) {
    this.closeSession(sid, 4410, 'session removed');
    for (const [k, e] of [...this.docs]) {
      if (e.sid === sid) { clearTimeout(e.idleTimer); e.doc.destroy(); this.docs.delete(k); }
    }
    this.persistence.dropSession(sid);
  }

  flushAll() {
    for (const e of this.docs.values()) this.persistence.compact(e.sid, e.bid, e.doc);
  }
}
