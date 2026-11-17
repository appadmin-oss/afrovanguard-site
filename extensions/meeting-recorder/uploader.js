/* uploader.js — sends what the recorder saved, in chunks the host will take,
 * and keeps trying until the server has all of it. Runs in the background
 * worker: after each slice, on a one-minute alarm, and when Chrome starts.
 *
 * Every step is safe to repeat. A chunk is formed once (its slice range and
 * checksum are saved before it is sent) and resent unchanged until the server
 * acknowledges it, so a dropped connection, a killed browser or a restarted
 * worker never produces a different file. */
import * as store from './idb.js';
import { API } from './config.js';

const DEFAULT_CHUNK = 512 * 1024;
let running = null;

async function token() {
  const { token } = await chrome.storage.local.get('token');
  return token || '';
}

/** One API call. Throws {offline:true} on a network failure so the caller stops this round. */
export async function api(action, { method = 'POST', json, form, query = '' } = {}) {
  const t = await token();
  if (!t && action !== 'pair') { const e = new Error('Not connected'); e.reconnect = true; throw e; }
  const headers = t ? { 'X-AV-Recorder-Token': t } : {};
  let body;
  if (json) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(json); }
  if (form) body = form;
  let res;
  try {
    res = await fetch(API + '?action=' + action + query, { method, headers, body, credentials: 'omit', cache: 'no-store' });
  } catch (e) {
    const err = new Error('No connection'); err.offline = true; throw err;
  }
  let data = {};
  try { data = await res.json(); } catch (e) { data = { ok: false, error: 'The server sent something unexpected (' + res.status + ').' }; }
  data.httpStatus = res.status;
  if (res.status === 401 && data.reconnect) {
    await chrome.storage.local.set({ needsConnect: true });
    const e = new Error(data.error || 'Not connected'); e.reconnect = true; throw e;
  }
  // 507 is the person's recording space being full: an answer, not an outage.
  if ((res.status >= 500 && res.status !== 507) || res.status === 429) { const e = new Error(data.error || 'Server busy'); e.offline = true; throw e; }
  return data;
}

async function sha256(blob) {
  const buf = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
  return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function chunkBytes() {
  const { chunkBytes } = await chrome.storage.local.get('chunkBytes');
  return chunkBytes || DEFAULT_CHUNK;
}

/** Make sure the server knows about this recording; returns its id or 0 if it can't yet. */
async function ensureServer(r) {
  if (r.serverId) return r.serverId;
  const s = await api('start', { json: { meet_code: r.meetCode || '', title: r.title || '', url: r.url || '', meeting_id: r.meetingId || 0 } });
  if (!s.ok) {
    await store.update('recordings', r.rec, (x) => x && Object.assign(x, { lastError: s.error || 'The server would not start the recording.' }));
    return 0;
  }
  if (s.chunk_bytes) await chrome.storage.local.set({ chunkBytes: s.chunk_bytes });
  const upd = await store.update('recordings', r.rec, (x) => x && Object.assign(x, {
    serverId: s.recording_id, meetingId: s.meeting.id, meetingTitle: s.meeting.title, minutesUrl: s.meeting.url,
    notetakerActive: !!s.notetaker_active, hasTranscript: !!s.has_transcript, othersRecording: s.others_recording || 0, lastError: '',
  }));
  chrome.runtime.sendMessage({ target: 'popup', type: 'refresh' }).catch(() => {});
  return upd ? upd.serverId : s.recording_id;
}

/** Turn saved slices into chunks (≤ the host's limit). Only full chunks unless the segment is closed. */
async function formChunks(rec, s, limit) {
  const slices = (await store.range('slices', [rec, s.seg])).sort((a, b) => a.idx - b.idx);
  let from = s.nextSlice, made = 0;
  while (from < s.slices) {
    let size = 0, to = from;
    const parts = [];
    while (to < s.slices) {
      const sl = slices.find((x) => x.idx === to);
      if (!sl) break;
      if (parts.length && size + sl.blob.size > limit) break;
      parts.push(sl.blob); size += sl.blob.size; to++;
    }
    if (!parts.length) break;
    const full = to < s.slices || size >= limit * 0.75;
    if (!full && !s.closed) break;                     // wait for more audio
    const blob = new Blob(parts, { type: 'application/octet-stream' });
    await store.put('chunks', { rec, seg: s.seg, idx: s.chunks + made, from, to, sha: await sha256(blob), sent: false });
    made++; from = to;
  }
  if (made) s = await store.update('segments', [rec, s.seg], (x) => x && Object.assign(x, { nextSlice: from, chunks: x.chunks + made }));
  return s;
}

async function chunkBlob(rec, c) {
  const slices = (await store.range('slices', [rec, c.seg])).filter((x) => x.idx >= c.from && x.idx < c.to).sort((a, b) => a.idx - b.idx);
  if (slices.length !== c.to - c.from) return null;
  return new Blob(slices.map((x) => x.blob), { type: 'application/octet-stream' });
}

/** Send what can be sent for one segment. Returns true when the server has the whole segment. */
async function sendSegment(r, s) {
  const limit = await chunkBytes();
  s = await formChunks(r.rec, s, limit);
  const chunks = (await store.range('chunks', [r.rec, s.seg])).sort((a, b) => a.idx - b.idx);
  for (const c of chunks) {
    if (c.sent) continue;
    const blob = await chunkBlob(r.rec, c);
    if (!blob) continue;
    const f = new FormData();
    f.append('rec', String(r.serverId)); f.append('seg', String(c.seg)); f.append('idx', String(c.idx)); f.append('sha256', c.sha);
    f.append('data', blob, 'chunk.bin');
    const res = await api('chunk', { form: f });
    if (res.httpStatus === 507) {
      // Out of space: keep the audio here, say why, and ask again in an hour rather than every minute.
      await store.update('recordings', r.rec, (x) => x && Object.assign(x, { lastError: res.error || 'Your recording space is full.', quotaUntil: Date.now() + 3600 * 1000 }));
      return false;
    }
    if (res.httpStatus === 413 && limit > 64 * 1024) {
      // The host takes less than we thought: halve, and re-form every unsent chunk from here.
      await chrome.storage.local.set({ chunkBytes: Math.max(64 * 1024, Math.floor((res.chunk_bytes || limit) / 2)) });
      const unsent = chunks.filter((x) => !x.sent && x.idx >= c.idx);
      for (const u of unsent) await store.del('chunks', [r.rec, u.seg, u.idx]);
      await store.update('segments', [r.rec, s.seg], (x) => x && Object.assign(x, { nextSlice: c.from, chunks: c.idx }));
      return false;
    }
    if (!res.ok) {
      if (res.httpStatus === 409 || res.httpStatus === 404) { await markClosed(r, res.error); return false; }
      return false;                                    // checksum or other: try again next round
    }
    await store.put('chunks', Object.assign(c, { sent: true }));
  }
  if (!s.closed) return false;
  const fresh = await store.get('segments', [r.rec, s.seg]);
  if (fresh.nextSlice < fresh.slices) return false;    // more chunks to form next round
  const res = await api('segment', { json: { rec: r.serverId, seg: s.seg, chunks: fresh.chunks, offset: s.offset, mime: s.mime, talk: fresh.talk || [] } });
  if (!res.ok && Array.isArray(res.missing)) {
    for (const i of res.missing) await store.update('chunks', [r.rec, s.seg, i], (x) => x && Object.assign(x, { sent: false }));
    return false;
  }
  if (!res.ok && res.httpStatus === 409) { await markClosed(r, res.error); return false; }
  if (!res.ok) return false;
  // The server has it: free the disk.
  await store.put('segments', Object.assign(fresh, { serverDone: true }));
  await store.delRange('slices', [r.rec, s.seg]);
  await store.delRange('chunks', [r.rec, s.seg]);
  return true;
}

async function markClosed(r, why) {
  await store.update('recordings', r.rec, (x) => x && Object.assign(x, { state: 'cancelled', lastError: why || 'The server closed this recording.' }));
}

async function forget(rec) {
  await store.delRange('slices', [rec]);
  await store.delRange('chunks', [rec]);
  await store.delRange('segments', [rec]);
}

/** One pass over every recording that still has work. Safe to call often. */
export function pump() {
  if (running) return running;
  running = (async () => {
    const out = { sent: 0, pending: 0, error: '' };
    try {
      if (!(await token())) return out;
      const recs = (await store.all('recordings')).sort((a, b) => a.startedAt - b.startedAt);
      for (const r of recs) {
        if (['done', 'failed', 'duplicate', 'cancelled'].includes(r.state)) continue;
        if (r.quotaUntil && r.quotaUntil > Date.now()) { out.pending++; continue; }
        if (!(await ensureServer(r))) { out.pending++; continue; }
        const fresh = await store.get('recordings', r.rec);
        if (fresh.state === 'cancelled') continue;
        const segs = (await store.range('segments', [r.rec])).sort((a, b) => a.seg - b.seg);
        let all = true;
        for (const s of segs) {
          if (s.serverDone) continue;
          if (await sendSegment(fresh, s)) out.sent++; else all = false;
        }
        if (fresh.state === 'stopped' && all) {
          const res = await api('finish', { json: { rec: fresh.serverId, segments: fresh.segments || segs.length, marks: fresh.marks || [], people: fresh.peopleMax || 0, consent: fresh.consent || 'none', consent_at: fresh.consentAt || '' } });
          if (res.ok && (!res.missing_segments || !res.missing_segments.length)) {
            await store.update('recordings', r.rec, (x) => x && Object.assign(x, { state: 'uploaded', uploadedAt: Date.now() }));
          }
        } else if (fresh.state === 'uploaded' || fresh.state === 'saved') {
          await checkMinutes(fresh);
        }
        if (!all) out.pending++;
      }
    } catch (e) {
      out.error = e.reconnect ? 'reconnect' : e.offline ? 'offline' : (e.message || 'error');
    } finally {
      running = null;
    }
    return out;
  })();
  return running;
}

/** After upload, ask now and then whether the minutes are written; tell the user once. */
async function checkMinutes(r) {
  // Waiting for a transcriber can take days: ask every half hour, not every minute.
  if (r.checkedAt && Date.now() - r.checkedAt < (r.state === 'saved' ? 30 * 60000 : 60000)) return;
  const res = await api('status', { method: 'GET', query: '&rec=' + encodeURIComponent(r.serverId) });
  const st = res.ok ? res.recording.status : '';
  const patch = { checkedAt: Date.now() };
  if (res.ok && res.recording.listen_url) patch.listenUrl = res.recording.listen_url;
  if (['done', 'failed', 'duplicate', 'cancelled'].includes(st)) {
    patch.state = st; patch.note = res.recording.note || '';
    await forget(r.rec);
    const msg = st === 'done' ? 'The minutes for “' + (r.meetingTitle || r.title) + '” are ready.'
      : st === 'duplicate' ? 'The meeting already had minutes. Your recording’s transcript is kept with it.'
      : 'The recording could not be turned into minutes. ' + (res.recording.note || '');
    chrome.notifications.create('minutes-' + r.rec, { type: 'basic', iconUrl: 'icons/128.png', title: 'Meeting Recorder', message: msg, priority: 1 });
    await chrome.storage.local.set({ lastMinutesUrl: res.recording.minutes_url || r.minutesUrl || '' });
  } else if (res.ok && res.recording.waiting) {
    // Saved, but no transcriber on the site: say so, once, and where to listen.
    if (r.state !== 'saved') {
      patch.state = 'saved';
      patch.note = 'Saved. No transcriber is set up on the site yet, so there are no written minutes — listen to it from the meeting in the portal.';
      chrome.notifications.create('saved-' + r.rec, { type: 'basic', iconUrl: 'icons/128.png', title: 'Recording saved', message: '“' + (r.meetingTitle || r.title) + '” is saved. There are no written minutes yet (no transcriber is set up). Click to listen.', priority: 1 });
      await chrome.storage.local.set({ lastMinutesUrl: res.recording.listen_url || '' });
    }
  } else if (Date.now() - (r.uploadedAt || 0) > 3 * 3600 * 1000) {
    // Never claim minutes that are not there.
    patch.note = 'Uploaded. The minutes are taking longer than usual; they will appear on the meeting.';
  }
  await store.update('recordings', r.rec, (x) => x && Object.assign(x, patch));
}

/** Tidy the list: finished recordings are kept for a week so the popup can show them. */
export async function prune() {
  const week = 7 * 86400 * 1000;
  for (const r of await store.all('recordings')) {
    const old = Date.now() - r.startedAt;
    if ((['done', 'failed', 'duplicate', 'cancelled'].includes(r.state) && old > week) || (r.state === 'saved' && old > 30 * 86400 * 1000)) {
      await forget(r.rec);
      await store.del('recordings', r.rec);
    }
  }
}
