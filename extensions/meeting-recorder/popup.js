/* popup.js — the recorder's toolbar window. It only asks the background to act
 * and shows what the background reports; it holds no state of its own. */
const $ = (id) => document.getElementById(id);
const send = (type, extra = {}) => chrome.runtime.sendMessage({ type, ...extra });
let st = null, clock = null;

const STATE = {
  recording: 'Recording', stopped: 'Sending…', uploaded: 'Writing the minutes…', finishing: 'Sending…',
  done: 'Minutes ready', duplicate: 'Kept with the meeting', failed: 'No minutes', cancelled: 'Discarded',
};

function show(id, on) { $(id).hidden = !on; }
function error(msg) { $('error').textContent = msg || ''; show('error', !!msg); }
function fmt(sec) { sec = Math.max(0, Math.floor(sec)); const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60; return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0'); }

function render() {
  if (!st) return;
  show('connect', !st.connected);
  show('idle', st.connected && !st.live);
  show('live', !!st.live);
  $('who').textContent = st.user ? 'Connected as ' + (st.user.name || st.user.email) : '';
  $('set-mic').checked = !!st.settings.mic;
  $('set-prompt').checked = !!st.settings.prompt;

  if (st.connected && !st.live) {
    const t = st.tab;
    $('tab-name').textContent = t ? (t.meetCode ? (t.title || 'Google Meet') + ' · ' + t.meetCode : (t.title || 'This tab')) : '';
    $('btn-start').disabled = !t || !t.recordable;
    $('btn-start').textContent = t && t.meetCode ? 'Record this meeting' : 'Record this tab';
  }
  if (st.live) {
    const L = st.live;
    $('live-name').textContent = L.title || L.meetCode || '';
    show('mic-warn', st.settings.mic && L.mic === false);
    $('rec-word').textContent = L.paused ? 'Paused' : 'Recording';
    $('rec-line').classList.toggle('is-paused', !!L.paused);
    $('btn-pause').textContent = L.paused ? 'Resume' : 'Pause';
    $('marks').textContent = L.marks ? L.marks + (L.marks === 1 ? ' moment' : ' moments') + ' marked as important.' : '';
    const w = L.warn || {};
    const warn = w.mic ? 'Your microphone is sending no sound. Check it is not muted, or pick the right one.'
      : w.tab ? 'No sound from the meeting tab yet. Is the meeting in another tab?' : '';
    $('live-warn').textContent = warn; show('live-warn', !!warn);
    const q = st.queue.find((r) => r.rec === L.rec);
    const info = q && q.notetakerActive ? 'The notetaker is also in this meeting. Its minutes come first; yours are kept with the recording.'
      : q && q.othersRecording ? 'Someone else is recording this meeting too.'
      : q && q.hasTranscript ? 'This meeting already has minutes. Your transcript will be kept with the recording.' : '';
    $('live-info').textContent = info; show('live-info', !!info);
    clearInterval(clock);
    // The clock counts recorded time: it holds still while paused.
    const tick = () => { if (st.levels && typeof st.levels.elapsed === 'number') $('timer').textContent = fmt(st.levels.elapsed); else $('timer').textContent = fmt((Date.now() - L.startedAt) / 1000); };
    tick(); clock = setInterval(tick, 1000);
    if (st.levels) levels(st.levels);
  } else clearInterval(clock);

  const q = $('queue');
  q.textContent = '';
  for (const r of st.queue) {
    if (st.live && st.live.rec === r.rec) continue;
    const li = document.createElement('li');
    const t = document.createElement('span'); t.className = 't'; t.textContent = r.title || 'Recording';
    const s = document.createElement('span'); s.className = 's';
    let line = STATE[r.state] || r.state;
    if (['stopped', 'recording'].includes(r.state) && r.total) line += ' ' + r.sent + '/' + r.total;
    line += ' · ' + new Date(r.startedAt).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    s.textContent = line;
    li.append(t, s);
    const extra = r.notetakerActive && r.state !== 'done' ? 'The notetaker was also in this meeting; its minutes come first.' : r.note;
    if (extra) { const n = document.createElement('div'); n.className = 's'; n.textContent = extra; li.append(n); }
    if (r.state === 'done' && r.minutesUrl) {
      const a = document.createElement('a'); a.href = r.minutesUrl; a.target = '_blank'; a.textContent = 'Open the minutes'; a.className = 'small';
      li.append(document.createElement('br'), a);
    }
    if (['stopped', 'uploaded'].includes(r.state)) {
      const b = document.createElement('button'); b.className = 'btn btn--line'; b.textContent = 'Discard';
      // Two taps, not confirm(): dialogs are unreliable in an extension popup.
      b.onclick = async () => {
        if (!b.dataset.armed) { b.dataset.armed = '1'; b.textContent = 'Tap again to discard (it will not be transcribed)'; return; }
        const res = await send('discard', { rec: r.rec }); if (!res.ok) error(res.error); refresh();
      };
      li.append(document.createElement('br'), b);
    }
    q.append(li);
  }
  show('queue-wrap', q.children.length > 0);
}

function levels(l) {
  const pct = (v) => Math.min(100, Math.round(Math.sqrt(v || 0) * 160)) + '%';
  $('lvl-tab').style.width = pct(l.tab);
  $('lvl-mic').style.width = pct(l.mic);
}

async function refresh() { st = await send('status'); render(); }

$('btn-connect').onclick = () => send('connect').then(() => window.close());
$('btn-start').onclick = async () => {
  error('');
  $('btn-start').disabled = true;
  const r = await send('start', { tabId: st.tab && st.tab.id });
  if (!r.ok) error(r.error);
  refresh();
};
$('btn-mark').onclick = async () => {
  const r = await send('mark');
  if (!r.ok) { error(r.error); return; }
  $('btn-mark').textContent = '★ Marked at ' + fmt(r.at);
  setTimeout(() => { $('btn-mark').textContent = '★ Mark this moment'; }, 2500);
  refresh();
};
$('btn-pause').onclick = async () => { const r = await send(st.live && st.live.paused ? 'resume' : 'pause'); if (!r.ok) error(r.error); refresh(); };
$('btn-stop').onclick = async () => { $('btn-stop').disabled = true; await send('stop'); $('btn-stop').disabled = false; refresh(); };
$('set-mic').onchange = (e) => send('settings', { settings: { mic: e.target.checked } }).then(refresh);
$('set-prompt').onchange = (e) => send('settings', { settings: { prompt: e.target.checked } }).then(refresh);

chrome.runtime.onMessage.addListener((m) => {
  if (m.target !== 'popup') return;
  if (m.type === 'levels' && st && st.live) { st.levels = m.levels; levels(m.levels); }
  if (m.type === 'refresh') refresh();
});

refresh();
send('sync').then(refresh);
