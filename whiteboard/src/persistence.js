// Operational persistence of Yjs documents as raw bytes (no rendering):
//   <root>/whiteboard/s<sid>/b<bid>.snap   compacted state (Y.encodeStateAsUpdate)
//   <root>/whiteboard/s<sid>/b<bid>.log    append-only updates, each prefixed by uint32 BE length
// A session directory can be removed as a whole by the retention purge.
import fs from 'node:fs';
import path from 'node:path';
import * as Y from 'yjs';

export class FilePersistence {
  constructor(storageRoot) {
    if (!path.isAbsolute(storageRoot)) throw new Error('STORAGE_PATH must be absolute');
    this.root = path.join(storageRoot, 'whiteboard');
    fs.mkdirSync(this.root, { recursive: true, mode: 0o750 });
  }

  sessionDir(sid) { return path.join(this.root, `s${sid}`); }
  paths(sid, bid) {
    const dir = this.sessionDir(sid);
    return { dir, snap: path.join(dir, `b${bid}.snap`), log: path.join(dir, `b${bid}.log`) };
  }

  /** Loads a document: snapshot, then every complete log record (a torn last record is ignored). */
  load(sid, bid) {
    const doc = new Y.Doc();
    const { snap, log } = this.paths(sid, bid);
    if (fs.existsSync(snap)) Y.applyUpdate(doc, fs.readFileSync(snap));
    if (fs.existsSync(log)) {
      const buf = fs.readFileSync(log);
      let off = 0;
      while (off + 4 <= buf.length) {
        const len = buf.readUInt32BE(off);
        if (off + 4 + len > buf.length) break;
        Y.applyUpdate(doc, buf.subarray(off + 4, off + 4 + len));
        off += 4 + len;
      }
    }
    return doc;
  }

  append(sid, bid, update) {
    const { dir, log } = this.paths(sid, bid);
    fs.mkdirSync(dir, { recursive: true, mode: 0o750 });
    const rec = Buffer.alloc(4 + update.length);
    rec.writeUInt32BE(update.length, 0);
    Buffer.from(update).copy(rec, 4);
    fs.appendFileSync(log, rec, { mode: 0o640 });
  }

  /** Writes a compacted snapshot atomically, then truncates the log. */
  compact(sid, bid, doc) {
    const { dir, snap, log } = this.paths(sid, bid);
    fs.mkdirSync(dir, { recursive: true, mode: 0o750 });
    const tmp = `${snap}.tmp-${process.pid}`;
    fs.writeFileSync(tmp, Y.encodeStateAsUpdate(doc), { mode: 0o640 });
    fs.renameSync(tmp, snap);
    if (fs.existsSync(log)) fs.truncateSync(log, 0);
  }

  /** Block ids that have persisted state in a session. */
  blocks(sid) {
    const dir = this.sessionDir(sid);
    if (!fs.existsSync(dir)) return [];
    const ids = new Set();
    for (const f of fs.readdirSync(dir)) {
      const m = /^b(\d+)\.(snap|log)$/.exec(f);
      if (m) ids.add(Number(m[1]));
    }
    return [...ids];
  }

  dropSession(sid) {
    fs.rmSync(this.sessionDir(sid), { recursive: true, force: true });
  }
}
