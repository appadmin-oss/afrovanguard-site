/* idb.js — the recorder's local store (IndexedDB), shared by the background
 * worker and the offscreen recorder. Audio is written here BEFORE it is sent,
 * so a crash, a closed laptop or a dropped connection loses nothing: the
 * uploader picks up where it stopped the next time Chrome runs.
 *
 *   recordings  { rec, serverId, state, meetCode, title, url, startedAt, segments, ... }
 *               state: recording → stopped → finishing → uploaded → done|failed|duplicate|cancelled
 *   segments    { rec, seg, offset, mime, closed, slices, nextSlice, chunks, serverDone }
 *   slices      { rec, seg, idx, blob }          5-second pieces from MediaRecorder
 *   chunks      { rec, seg, idx, from, to, sha, sent }   what is sent to the server
 */
const NAME = 'avrec';
const VERSION = 1;
let dbp = null;

export function db() {
  if (dbp) return dbp;
  dbp = new Promise((resolve, reject) => {
    const req = indexedDB.open(NAME, VERSION);
    req.onupgradeneeded = () => {
      const d = req.result;
      if (!d.objectStoreNames.contains('recordings')) d.createObjectStore('recordings', { keyPath: 'rec' });
      if (!d.objectStoreNames.contains('segments')) d.createObjectStore('segments', { keyPath: ['rec', 'seg'] });
      if (!d.objectStoreNames.contains('slices')) d.createObjectStore('slices', { keyPath: ['rec', 'seg', 'idx'] });
      if (!d.objectStoreNames.contains('chunks')) d.createObjectStore('chunks', { keyPath: ['rec', 'seg', 'idx'] });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => { dbp = null; reject(req.error); };
  });
  return dbp;
}

function done(tx) {
  return new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve();
    tx.onabort = tx.onerror = () => reject(tx.error || new Error('IndexedDB transaction failed'));
  });
}
function result(req) {
  return new Promise((resolve, reject) => { req.onsuccess = () => resolve(req.result); req.onerror = () => reject(req.error); });
}

export async function get(store, key) {
  const d = await db();
  return result(d.transaction(store).objectStore(store).get(key));
}
export async function put(store, value) {
  const d = await db();
  const tx = d.transaction(store, 'readwrite');
  tx.objectStore(store).put(value);
  return done(tx);
}
export async function del(store, key) {
  const d = await db();
  const tx = d.transaction(store, 'readwrite');
  tx.objectStore(store).delete(key);
  return done(tx);
}
export async function all(store) {
  const d = await db();
  return result(d.transaction(store).objectStore(store).getAll());
}
/** Everything in `store` whose key starts with `prefix` (an array). */
export async function range(store, prefix) {
  const d = await db();
  const r = IDBKeyRange.bound(prefix, [...prefix, []]);
  return result(d.transaction(store).objectStore(store).getAll(r));
}
export async function delRange(store, prefix) {
  const d = await db();
  const tx = d.transaction(store, 'readwrite');
  tx.objectStore(store).delete(IDBKeyRange.bound(prefix, [...prefix, []]));
  return done(tx);
}
/** Read-modify-write one record in a single transaction. */
export async function update(store, key, fn) {
  const d = await db();
  const tx = d.transaction(store, 'readwrite');
  const os = tx.objectStore(store);
  let out;
  const cur = await result(os.get(key));
  out = fn(cur);
  if (out) os.put(out);
  await done(tx);
  return out;
}
