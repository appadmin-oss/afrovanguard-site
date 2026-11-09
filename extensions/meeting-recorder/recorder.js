/* recorder.js — mixes the meeting tab and the microphone into one Opus track
 * and records it in standalone ten-minute segments, every five-second slice
 * written to IndexedDB as it arrives. Used by offscreen.js; it takes streams,
 * not tab ids, so it can be exercised with any audio source.
 *
 * Why segments: each one is a complete WebM file, so the server can transcribe
 * it on its own (no ffmpeg on a shared host), every one stays far below the
 * transcription services' size limits, and a crash costs at most one slice.
 */
import * as store from './idb.js';

export class SegmentedRecorder {
  /**
   * @param {object} o
   * @param {string} o.rec          local recording id
   * @param {MediaStream} o.tab     the meeting's audio (may be null in tests)
   * @param {MediaStream} [o.mic]   the user's microphone, if allowed
   * @param {number} o.segmentSeconds
   * @param {number} o.sliceMs
   * @param {number} o.bits
   * @param {boolean} [o.playback]  route the tab back to the speakers (tab capture mutes it)
   * @param {(e:object)=>void} o.emit  events: slice, segment-closed, levels, silent, stopped, error
   */
  constructor(o) {
    this.o = o;
    this.seg = -1;
    this.mr = null;
    this.writes = Promise.resolve();
    this.stopping = false;
    this.startedAt = 0;
    this.silentSince = 0;
    this.paused = false;
    this.pausedAt = 0;
    this.pausedTotal = 0;
    this.tabDeadSince = 0;
    this.micDeadSince = 0;
  }

  async start() {
    const o = this.o;
    this.ctx = new AudioContext();
    this.dest = this.ctx.createMediaStreamDestination();
    this.levels = {};
    if (o.tab) {
      const src = this.ctx.createMediaStreamSource(o.tab);
      src.connect(this.dest);
      if (o.playback !== false) src.connect(this.ctx.destination);
      this.levels.tab = this.meter(src);
      o.tab.getAudioTracks().forEach((t) => { t.onended = () => this.stop('tab-ended'); });
    }
    if (o.mic) {
      const src = this.ctx.createMediaStreamSource(o.mic);
      src.connect(this.dest);
      this.levels.mic = this.meter(src);
    }
    this.mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm';
    this.startedAt = Date.now();
    this.silentSince = this.tabDeadSince = this.micDeadSince = Date.now();
    await this.nextSegment();
    this.levelTimer = setInterval(() => this.tickLevels(), 1000);
  }

  meter(src) {
    const a = this.ctx.createAnalyser();
    a.fftSize = 1024;
    src.connect(a);
    const buf = new Float32Array(a.fftSize);
    return () => { a.getFloatTimeDomainData(buf); let s = 0; for (const v of buf) s += v * v; return Math.sqrt(s / buf.length); };
  }

  /** Seconds recorded, not counting pauses. */
  elapsed(now = Date.now()) {
    return Math.round((now - this.startedAt - this.pausedTotal - (this.paused ? now - this.pausedAt : 0)) / 1000);
  }

  tickLevels() {
    const tab = this.levels.tab ? this.levels.tab() : 0;
    const mic = this.levels.mic ? this.levels.mic() : 0;
    const now = Date.now();
    if (this.paused) {
      this.silentSince = this.tabDeadSince = this.micDeadSince = now;
      this.o.emit({ type: 'levels', tab: 0, mic: 0, paused: true, elapsed: this.elapsed(now), silentFor: 0, tabDeadFor: 0, micDeadFor: 0 });
      return;
    }
    if (Math.max(tab, mic) > 0.01) this.silentSince = now;
    /* "Dead" is digital silence: no signal at all, not a quiet room. A live
       microphone always hears something; a muted or missing one hears zero.
       A meeting tab playing nothing at all is likely the wrong tab. */
    if (tab > 0.00001) this.tabDeadSince = now;
    if (mic > 0.00001) this.micDeadSince = now;
    this.o.emit({
      type: 'levels', tab, mic, paused: false, elapsed: this.elapsed(now),
      silentFor: Math.round((now - this.silentSince) / 1000),
      tabDeadFor: this.levels.tab ? Math.round((now - this.tabDeadSince) / 1000) : 0,
      micDeadFor: this.levels.mic ? Math.round((now - this.micDeadSince) / 1000) : 0,
    });
  }

  /** Pause: the same segment carries on afterwards, and its ten minutes wait too. */
  pause() {
    if (this.paused || this.stopping || !this.mr || this.mr.state !== 'recording') return false;
    this.mr.pause();
    clearTimeout(this.segTimer);
    this.segLeft = Math.max(1000, this.segDue - Date.now());
    this.paused = true;
    this.pausedAt = Date.now();
    this.o.emit({ type: 'paused' });
    return true;
  }

  resume() {
    if (!this.paused || this.stopping || !this.mr) return false;
    this.mr.resume();
    const now = Date.now();
    this.pausedTotal += now - this.pausedAt;
    this.paused = false;
    this.silentSince = this.tabDeadSince = this.micDeadSince = now;
    this.segDue = now + this.segLeft;
    this.segTimer = setTimeout(() => { if (!this.stopping) this.nextSegment(); }, this.segLeft);
    this.o.emit({ type: 'resumed' });
    return true;
  }

  /** Close the current segment (if any) and open the next, on the same mixed stream. */
  async nextSegment() {
    const prev = this.mr;
    const seg = ++this.seg;
    const offset = Math.round((Date.now() - this.startedAt) / 1000);
    await store.put('segments', { rec: this.o.rec, seg, offset, mime: this.mime, closed: false, slices: 0, nextSlice: 0, chunks: 0, serverDone: false });
    await store.update('recordings', this.o.rec, (r) => r && Object.assign(r, { segments: seg + 1 }));

    const mr = new MediaRecorder(this.dest.stream, { mimeType: this.mime, audioBitsPerSecond: this.o.bits });
    let idx = 0;
    mr.ondataavailable = (ev) => {
      if (!ev.data || !ev.data.size) return;
      const i = idx++;
      // Writes are chained so a segment is only closed after its last slice is safe.
      this.writes = this.writes.then(async () => {
        await store.put('slices', { rec: this.o.rec, seg, idx: i, blob: ev.data });
        await store.update('segments', [this.o.rec, seg], (s) => s && Object.assign(s, { slices: i + 1 }));
        this.o.emit({ type: 'slice', seg, idx: i, size: ev.data.size });
      }).catch((e) => this.o.emit({ type: 'error', error: 'Could not save audio: ' + (e && e.message) }));
    };
    mr.onstop = () => {
      this.writes = this.writes.then(async () => {
        await store.update('segments', [this.o.rec, seg], (s) => s && Object.assign(s, { closed: true }));
        this.o.emit({ type: 'segment-closed', seg });
      });
    };
    mr.onerror = (e) => this.o.emit({ type: 'error', error: 'The recorder stopped: ' + (e.error && e.error.name) });
    mr.start(this.o.sliceMs);
    this.mr = mr;
    if (prev && prev.state !== 'inactive') prev.stop();

    clearTimeout(this.segTimer);
    this.segDue = Date.now() + this.o.segmentSeconds * 1000;
    this.segTimer = setTimeout(() => { if (!this.stopping) this.nextSegment(); }, this.o.segmentSeconds * 1000);
  }

  async stop(reason = 'user') {
    if (this.stopping) return this.stopped;
    this.stopping = true;
    clearTimeout(this.segTimer);
    clearInterval(this.levelTimer);
    this.stopped = (async () => {
      if (this.mr && this.mr.state !== 'inactive') {
        await new Promise((res) => { this.mr.addEventListener('stop', res, { once: true }); this.mr.stop(); });
      }
      await this.writes;
      [this.o.tab, this.o.mic].forEach((s) => s && s.getTracks().forEach((t) => t.stop()));
      try { await this.ctx.close(); } catch (e) { /* already closed */ }
      const segments = this.seg + 1;
      this.o.emit({ type: 'stopped', reason, segments });
      return segments;
    })();
    return this.stopped;
  }
}
