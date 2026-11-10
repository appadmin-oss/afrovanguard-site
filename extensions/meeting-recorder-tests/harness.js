/* harness.js — copied into a TEST build only (tests/recorder-e2e.mjs); never shipped.
 * Drives the real SegmentedRecorder and the real uploader with a generated tone
 * in place of a captured tab, which Chrome only grants after a real click. */
import { SegmentedRecorder } from './recorder.js';
import * as store from './idb.js';

/* A paused recorder holds its clock; a silent source reads as "dead". */
window.runPauseAndSilence = async function () {
  const ctx = new AudioContext();
  const silent = ctx.createConstantSource(); silent.offset.value = 0;
  const dest = ctx.createMediaStreamDestination(); silent.connect(dest); silent.start();
  const rec = crypto.randomUUID();
  await store.put('recordings', { rec, state: 'recording', startedAt: Date.now(), title: 'Pause test', segments: 0 });
  const levels = [], kinds = [];
  const r = new SegmentedRecorder({ rec, tab: dest.stream, mic: null, segmentSeconds: 60, sliceMs: 500, bits: 32000, playback: false,
    emit: (e) => { if (e.type === 'levels') levels.push(e); else kinds.push(e.type); } });
  await r.start();
  await new Promise((res) => setTimeout(res, 2200));
  const paused = r.pause();
  const atPause = r.elapsed();
  await new Promise((res) => setTimeout(res, 2500));
  const whilePaused = r.elapsed();
  const lastPausedLevel = levels[levels.length - 1];
  r.resume();
  await new Promise((res) => setTimeout(res, 3200));
  const after = r.elapsed();
  await r.stop('user');
  await ctx.close();
  // Not a recording to send: remove it so the uploader never sees it.
  for (const st of ['slices', 'chunks', 'segments']) await store.delRange(st, [rec]);
  await store.del('recordings', rec);
  const dead = levels.filter((l) => !l.paused).map((l) => l.tabDeadFor);
  return { paused, atPause, whilePaused, after, kinds: [...new Set(kinds)], pausedLevel: lastPausedLevel, maxDead: Math.max(...dead), lastDead: dead[dead.length - 1] };
};

window.runRecording = async function ({ seconds, segmentSeconds, sliceMs, marksAt = [], turns = null }) {
  const ctx = new AudioContext();
  const osc = ctx.createOscillator(); osc.frequency.value = 440;
  const tabGain = ctx.createGain();
  const dest = ctx.createMediaStreamDestination(); osc.connect(tabGain); tabGain.connect(dest); osc.start();
  /* turns: [[from, to, 'me'|'them'], …] seconds — "them" plays on the tab, "me" on a
     separate microphone source, the way a real call and a real microphone arrive. */
  let mic = null;
  if (turns) {
    const micOsc = ctx.createOscillator(); micOsc.frequency.value = 220;
    const micGain = ctx.createGain(); const micDest = ctx.createMediaStreamDestination();
    micOsc.connect(micGain); micGain.connect(micDest); micOsc.start();
    const t0 = ctx.currentTime + 0.05;
    tabGain.gain.setValueAtTime(0, ctx.currentTime); micGain.gain.setValueAtTime(0, ctx.currentTime);
    for (const [a, b, who] of turns) { const g = (who === 'me' ? micGain : tabGain).gain; g.setValueAtTime(0.5, t0 + a); g.setValueAtTime(0, t0 + b); }
    mic = micDest.stream;
  }
  const rec = crypto.randomUUID();
  await store.put('recordings', { rec, state: 'recording', startedAt: Date.now(), meetCode: 'abc-defg-hij', url: 'https://meet.google.com/abc-defg-hij', title: 'E2E standup', segments: 0, consentShown: true });
  const events = [];
  const r = new SegmentedRecorder({ rec, tab: dest.stream, mic, segmentSeconds, sliceMs, bits: 32000, playback: false, emit: (e) => { if (e.type !== 'levels') events.push(e.type); } });
  await r.start();
  // Marks go through the real background worker, as the shortcut does.
  await chrome.storage.session.set({ live: { rec, tabId: -1, startedAt: Date.now(), marks: 0, warn: {} } });
  const t0 = Date.now(); const marked = [];
  for (const at of marksAt) { await new Promise((res) => setTimeout(res, Math.max(0, t0 + at * 1000 - Date.now()))); marked.push(await chrome.runtime.sendMessage({ type: 'mark' })); }
  await new Promise((res) => setTimeout(res, Math.max(0, t0 + seconds * 1000 - Date.now())));
  const segments = await r.stop('user');
  await chrome.storage.session.remove('live');
  osc.stop(); await ctx.close();
  await store.update('recordings', rec, (x) => Object.assign(x, { state: 'stopped', segments }));
  const segs = await store.range('segments', [rec]);
  const slices = await store.range('slices', [rec]);
  return { rec, marked, marks: ((await store.get('recordings', rec)) || {}).marks || [], segments, closed: segs.filter((s) => s.closed).length, slices: slices.length, bytes: slices.reduce((n, s) => n + s.blob.size, 0), events: [...new Set(events)] };
};
window.sync = () => chrome.runtime.sendMessage({ type: 'sync' });
/* Waiting recordings are asked about every half hour; a test cannot wait that long. */
window.askNow = (rec) => store.update('recordings', rec, (x) => x && Object.assign(x, { checkedAt: 0 }));
window.local = async (rec) => ({ rec: await store.get('recordings', rec), slices: (await store.range('slices', [rec])).length, segs: await store.range('segments', [rec]) });
document.getElementById('out').textContent = 'ready';
