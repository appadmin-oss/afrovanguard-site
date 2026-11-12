/* offscreen.js — the page that holds the recording (Manifest V3 keeps media
 * out of the service worker). It turns the background's stream id into the
 * tab's audio, adds the microphone if the user allowed it, and runs the
 * SegmentedRecorder. Everything it learns goes back to the background. */
import { SegmentedRecorder } from './recorder.js';
import { SEGMENT_SECONDS, SLICE_MS, AUDIO_BITS } from './config.js';

let current = null;
const tell = (msg) => chrome.runtime.sendMessage({ target: 'background', ...msg }).catch(() => {});

async function micStream() {
  try {
    return await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } });
  } catch (e) {
    tell({ type: 'rec-event', event: { type: 'mic-unavailable', error: e.name } });
    return null;
  }
}

async function start(m) {
  if (current) return { ok: false, error: 'Already recording.' };
  let tab;
  try {
    tab = await navigator.mediaDevices.getUserMedia({
      audio: { mandatory: { chromeMediaSource: 'tab', chromeMediaSourceId: m.streamId } },
      video: false,
    });
  } catch (e) {
    return { ok: false, error: 'Chrome would not share this tab’s sound (' + e.name + ').' };
  }
  const mic = m.mic ? await micStream() : null;
  current = new SegmentedRecorder({
    rec: m.rec, tab, mic, segmentSeconds: m.segmentSeconds || SEGMENT_SECONDS, sliceMs: SLICE_MS, bits: AUDIO_BITS,
    emit: (event) => {
      tell({ type: 'rec-event', rec: m.rec, event });
      if (event.type === 'stopped') current = null;
    },
  });
  try { await current.start(); } catch (e) { current = null; return { ok: false, error: 'The recorder could not start: ' + e.message }; }
  return { ok: true, mic: !!mic };
}

chrome.runtime.onMessage.addListener((m, sender, reply) => {
  if (m.target !== 'offscreen' || sender.id !== chrome.runtime.id) return false;
  if (m.type === 'start') { start(m).then(reply); return true; }
  if (m.type === 'stop') {
    if (!current) { reply({ ok: true, segments: 0, idle: true }); return false; }
    current.stop(m.reason || 'user').then((segments) => reply({ ok: true, segments }));
    return true;
  }
  if (m.type === 'pause' || m.type === 'resume') {
    reply({ ok: !!current && current[m.type](), paused: !!(current && current.paused) });
    return false;
  }
  if (m.type === 'ping') { reply({ ok: true, recording: !!current }); return false; }
  return false;
});
