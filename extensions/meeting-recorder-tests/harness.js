/* harness.js — copied into a TEST build only (tests/recorder-e2e.mjs); never shipped.
 * Drives the real SegmentedRecorder and the real uploader with a generated tone
 * in place of a captured tab, which Chrome only grants after a real click. */
import { SegmentedRecorder } from './recorder.js';
import * as store from './idb.js';

window.runRecording = async function ({ seconds, segmentSeconds, sliceMs }) {
  const ctx = new AudioContext();
  const osc = ctx.createOscillator(); osc.frequency.value = 440;
  const dest = ctx.createMediaStreamDestination(); osc.connect(dest); osc.start();
  const rec = crypto.randomUUID();
  await store.put('recordings', { rec, state: 'recording', startedAt: Date.now(), meetCode: 'abc-defg-hij', url: 'https://meet.google.com/abc-defg-hij', title: 'E2E standup', segments: 0, consentShown: true });
  const events = [];
  const r = new SegmentedRecorder({ rec, tab: dest.stream, mic: null, segmentSeconds, sliceMs, bits: 32000, playback: false, emit: (e) => { if (e.type !== 'levels') events.push(e.type); } });
  await r.start();
  await new Promise((res) => setTimeout(res, seconds * 1000));
  const segments = await r.stop('user');
  osc.stop(); await ctx.close();
  await store.update('recordings', rec, (x) => Object.assign(x, { state: 'stopped', segments }));
  const segs = await store.range('segments', [rec]);
  const slices = await store.range('slices', [rec]);
  return { rec, segments, closed: segs.filter((s) => s.closed).length, slices: slices.length, bytes: slices.reduce((n, s) => n + s.blob.size, 0), events: [...new Set(events)] };
};
window.sync = () => chrome.runtime.sendMessage({ type: 'sync' });
window.local = async (rec) => ({ rec: await store.get('recordings', rec), slices: (await store.range('slices', [rec])).length, segs: await store.range('segments', [rec]) });
document.getElementById('out').textContent = 'ready';
