/* background.js — the recorder's coordinator (Manifest V3 service worker).
 *
 * Starts and stops recordings (popup button or Alt+Shift+R: Chrome lets a tab
 * be captured only after the user invokes the extension), keeps the offscreen
 * recorder, feeds the uploader, notices when a Meet call starts or ends, and
 * pairs the extension with the user's portal account.
 *
 * State that must survive the worker being put to sleep lives in
 * chrome.storage.session (the live recording) and IndexedDB (the audio). */
import * as store from './idb.js';
import { pump, prune, api } from './uploader.js';
import { SITE, CONNECT_URL, SILENCE_STOP_MIN } from './config.js';

const OFFSCREEN = 'offscreen.html';
const MEET_CODE = /^\/([a-z]{3}-[a-z]{4}-[a-z]{3})(?:$|[/?#])/;
let lastLevels = null;
let leftTimer = null;

/* ── helpers ───────────────────────────────────────────────────────── */

const session = {
  get: async () => (await chrome.storage.session.get('live')).live || null,
  set: (live) => chrome.storage.session.set({ live }),
  clear: () => chrome.storage.session.remove('live'),
};
const settings = async () => Object.assign({ mic: true, prompt: true }, (await chrome.storage.local.get('settings')).settings || {});

function meetCodeOf(url) {
  try { const u = new URL(url); return u.hostname === 'meet.google.com' ? ((u.pathname.match(MEET_CODE) || [])[1] || '') : ''; }
  catch (e) { return ''; }
}
function cleanTitle(t) { return String(t || '').replace(/^Meet\s*[-–]\s*/i, '').replace(/\s+/g, ' ').trim().slice(0, 160); }
function recordable(url) { return /^https?:\/\//.test(url || '') && !/^https:\/\/chrome\.google\.com\/webstore/.test(url); }

async function badge() {
  const live = await session.get();
  const { token, needsConnect } = await chrome.storage.local.get(['token', 'needsConnect']);
  let text = '', color = '#e4162b', title = 'Meeting Recorder (Alt+Shift+R)';
  if (live && live.paused) { text = 'II'; color = '#5d6470'; title = 'Paused — Alt+Shift+P to resume'; }
  else if (live) { text = 'REC'; title = 'Recording — Alt+Shift+M marks a moment, Alt+Shift+R stops'; }
  else if (!token || needsConnect) { text = '!'; color = '#b26b00'; title = 'Meeting Recorder — connect it to your account'; }
  else {
    const pending = (await store.all('recordings')).filter((r) => ['recording', 'stopped', 'uploaded'].includes(r.state));
    if (pending.length) { text = '↑'; color = '#5d6470'; title = 'Meeting Recorder — sending ' + pending.length + ' recording(s)'; }
  }
  await chrome.action.setBadgeText({ text });
  await chrome.action.setBadgeBackgroundColor({ color });
  await chrome.action.setTitle({ title });
}

async function hasOffscreen() {
  const ctx = await chrome.runtime.getContexts({ contextTypes: ['OFFSCREEN_DOCUMENT'], documentUrls: [chrome.runtime.getURL(OFFSCREEN)] });
  return ctx.length > 0;
}
async function ensureOffscreen() {
  if (await hasOffscreen()) return;
  await chrome.offscreen.createDocument({ url: OFFSCREEN, reasons: ['USER_MEDIA'], justification: 'Record the meeting tab’s sound and the microphone.' });
}
const toOffscreen = (msg) => chrome.runtime.sendMessage({ target: 'offscreen', ...msg });
/* Never throws: the tab may have closed, and Chrome rejects a bad id synchronously, before any promise. */
const toTab = (tabId, msg) => {
  try { return chrome.tabs.sendMessage(tabId, { from: 'av-recorder', ...msg }).catch(() => {}); } catch (e) { return Promise.resolve(); }
};
const toPopup = (msg) => chrome.runtime.sendMessage({ target: 'popup', ...msg }).catch(() => {});

/* ── start and stop ────────────────────────────────────────────────── */

async function start(tab) {
  const { token } = await chrome.storage.local.get('token');
  if (!token) { await chrome.tabs.create({ url: CONNECT_URL }); return { ok: false, error: 'Connect the recorder to your account first.' }; }
  if (await session.get()) return { ok: false, error: 'Already recording.' };
  if (!tab || !recordable(tab.url)) return { ok: false, error: 'This tab cannot be recorded. Open the meeting first.' };

  let streamId;
  try { streamId = await chrome.tabCapture.getMediaStreamId({ targetTabId: tab.id }); }
  catch (e) { return { ok: false, error: 'Chrome would not let the recorder hear this tab. Click the recorder icon on the meeting tab, then Record.' }; }

  const rec = crypto.randomUUID();
  const meetCode = meetCodeOf(tab.url);
  await store.put('recordings', {
    rec, state: 'recording', startedAt: Date.now(), meetCode, url: tab.url.split('#')[0].slice(0, 500),
    title: cleanTitle(tab.title) || (meetCode ? 'Meeting ' + meetCode : 'Recorded call'), segments: 0, consentShown: true,
  });
  await ensureOffscreen();
  const s = await settings();
  const res = await toOffscreen({ type: 'start', streamId, rec, mic: s.mic });
  if (!res || !res.ok) {
    await store.del('recordings', rec);
    return { ok: false, error: (res && res.error) || 'The recorder could not start.' };
  }
  await session.set({ rec, tabId: tab.id, startedAt: Date.now(), meetCode, title: cleanTitle(tab.title), mic: res.mic, paused: false, marks: 0, warn: {} });
  if (!res.mic && s.mic) await chrome.storage.local.set({ micBlocked: true }); else if (res.mic) await chrome.storage.local.set({ micBlocked: false });
  toTab(tab.id, { type: 'banner', state: 'recording' });
  await badge();
  pump().then(badge);
  return { ok: true, mic: res.mic };
}

let stopping = null;
function stop(reason = 'user') {
  if (!stopping) stopping = doStop(reason).finally(() => { stopping = null; });
  return stopping;
}
async function doStop(reason) {
  const live = await session.get();
  if (!live) return { ok: true, idle: true };
  clearTimeout(leftTimer);
  let segments = 0;
  try { const r = await toOffscreen({ type: 'stop', reason }); segments = (r && r.segments) || 0; } catch (e) { /* offscreen already gone */ }
  await closeRecording(live.rec, segments);
  await session.clear();
  lastLevels = null;
  try { if (await hasOffscreen()) await chrome.offscreen.closeDocument(); } catch (e) { /* closed */ }
  toTab(live.tabId, { type: 'banner', state: 'hidden' });
  if (reason !== 'user') {
    const why = { silence: 'Nobody has spoken for ' + SILENCE_STOP_MIN + ' minutes', left: 'You left the call', 'tab-ended': 'The meeting tab closed' }[reason] || 'The recording ended';
    chrome.notifications.create('stopped-' + live.rec, { type: 'basic', iconUrl: 'icons/128.png', title: 'Recording stopped', message: why + '. The minutes will follow.', priority: 1 });
  }
  await badge();
  pump().then(badge);
  return { ok: true };
}

/** Mark a recording stopped and every segment closed (also used to recover after a crash). */
async function closeRecording(rec, segments) {
  const segs = await store.range('segments', [rec]);
  for (const s of segs) if (!s.closed) await store.put('segments', Object.assign(s, { closed: true }));
  await store.update('recordings', rec, (r) => r && r.state === 'recording' && Object.assign(r, { state: 'stopped', segments: Math.max(segments, segs.length), stoppedAt: Date.now() }));
}

/** After Chrome restarts, a recording that was live is finished with what was saved. */
async function recover() {
  const live = await session.get();
  if (live && !(await hasOffscreen())) await session.clear();
  const still = await session.get();
  for (const r of await store.all('recordings')) {
    if (r.state === 'recording' && (!still || still.rec !== r.rec)) await closeRecording(r.rec, r.segments || 0);
  }
  await badge();
}

/* ── pause and mark ────────────────────────────────────────────────── */

async function setPaused(want) {
  const live = await session.get();
  if (!live) return { ok: false, error: 'Not recording.' };
  if (!!live.paused === want) return { ok: true, paused: want };
  const r = await toOffscreen({ type: want ? 'pause' : 'resume' }).catch(() => null);
  if (!r || !r.ok) return { ok: false, error: 'The recorder could not ' + (want ? 'pause' : 'resume') + '.' };
  await session.set(Object.assign(live, { paused: want }));
  toTab(live.tabId, { type: 'banner', state: want ? 'paused' : 'recording' });
  await chrome.action.setBadgeText({ text: want ? 'II' : 'REC' });
  toPopup({ type: 'refresh' });
  return { ok: true, paused: want };
}

/** Mark this moment as important: kept with the recording, noted in the minutes. */
async function mark() {
  const live = await session.get();
  if (!live) return { ok: false, error: 'Not recording.' };
  const at = Math.max(0, Math.round((Date.now() - live.startedAt) / 1000));
  const r = await store.update('recordings', live.rec, (x) => x && Object.assign(x, { marks: [...(x.marks || []), at] }));
  const count = r ? r.marks.length : 0;
  await session.set(Object.assign(live, { marks: count }));
  toTab(live.tabId, { type: 'banner', state: 'marked' });
  toPopup({ type: 'refresh' });
  return { ok: true, at, count };
}

/** Say once, early, when something means the recording will be poor. */
async function warnOnce(kind, message) {
  const live = await session.get();
  if (!live || (live.warn || {})[kind]) return;
  live.warn = Object.assign({}, live.warn, { [kind]: true });
  await session.set(live);
  chrome.notifications.create('warn-' + kind + '-' + live.rec, { type: 'basic', iconUrl: 'icons/128.png', title: 'Meeting Recorder', message, priority: 2 });
  toPopup({ type: 'refresh' });
}

/* ── pairing ───────────────────────────────────────────────────────── */

async function pair(code) {
  let res;
  try {
    const platform = (navigator.userAgentData && navigator.userAgentData.platform) || 'Computer';
    res = await api('pair', { json: { code, device: 'Chrome on ' + platform } });
  } catch (e) { return { ok: false, error: 'No connection to the portal. Try again.' }; }
  if (!res.ok) return { ok: false, error: res.error || 'The portal refused the connection.' };
  await chrome.storage.local.set({ token: res.token, user: res.user, needsConnect: false });
  await badge();
  pump().then(badge);
  return { ok: true, user: res.user };
}

/* ── what the popup shows ──────────────────────────────────────────── */

async function status() {
  const [live, local] = await Promise.all([session.get(), chrome.storage.local.get(['token', 'user', 'needsConnect', 'micBlocked', 'lastMinutesUrl'])]);
  const [tab] = await chrome.tabs.query({ active: true, lastFocusedWindow: true });
  const recs = (await store.all('recordings')).sort((a, b) => b.startedAt - a.startedAt).slice(0, 8);
  const queue = [];
  for (const r of recs) {
    const segs = await store.range('segments', [r.rec]);
    queue.push({
      rec: r.rec, title: r.meetingTitle || r.title, state: r.state, startedAt: r.startedAt, note: r.note || r.lastError || '',
      sent: segs.filter((s) => s.serverDone).length, total: Math.max(r.segments || 0, segs.length),
      notetakerActive: !!r.notetakerActive, hasTranscript: !!r.hasTranscript, othersRecording: r.othersRecording || 0,
      minutesUrl: r.minutesUrl || '', listenUrl: r.listenUrl || '',
    });
  }
  return {
    site: SITE, connected: !!local.token && !local.needsConnect, user: local.user || null,
    micBlocked: !!local.micBlocked, settings: await settings(), live, levels: lastLevels, queue,
    tab: tab ? { id: tab.id, title: cleanTitle(tab.title), meetCode: meetCodeOf(tab.url), recordable: recordable(tab.url) } : null,
  };
}

async function discard(rec) {
  const r = await store.get('recordings', rec);
  if (!r) return { ok: true };
  const live = await session.get();
  if (live && live.rec === rec) return { ok: false, error: 'Stop the recording first.' };
  if (r.serverId) { try { await api('cancel', { json: { rec: r.serverId } }); } catch (e) { return { ok: false, error: 'No connection — try again in a moment.' }; } }
  for (const s of ['slices', 'chunks', 'segments']) await store.delRange(s, [rec]);
  await store.update('recordings', rec, (x) => x && Object.assign(x, { state: 'cancelled', note: 'Discarded.' }));
  await badge();
  return { ok: true };
}

/* ── events ────────────────────────────────────────────────────────── */

chrome.runtime.onMessage.addListener((m, sender, reply) => {
  if (sender.id !== chrome.runtime.id) return false;
  // The extension's own pages (popup, set-up page) — in a tab or not. A content
  // script's sender.url is the web page's, so it never passes this.
  const fromExtensionPage = (sender.url || '').startsWith(chrome.runtime.getURL(''));

  if (m.target === 'background' && m.type === 'rec-event') { onRecEvent(m.event || {}); return false; }
  if (m.target === 'offscreen' || m.target === 'popup') return false;

  // Content script on the portal's connect page: the only place a code may come from.
  if (m.type === 'pair') {
    const okPage = sender.tab && (sender.url || '').startsWith(CONNECT_URL) && sender.origin === SITE;
    if (!okPage) { reply({ ok: false, error: 'Not from the portal.' }); return false; }
    pair(String(m.code || '')).then(reply);
    return true;
  }
  if (m.type === 'meet-state' && sender.tab) { onMeetState(sender.tab, m); return false; }

  // Everything below changes what is recorded: only the extension's own pages.
  if (!fromExtensionPage) return false;
  const run = {
    status: () => status(),
    start: async () => start(m.tabId ? await chrome.tabs.get(m.tabId).catch(() => null) : null),
    stop: () => stop('user'),
    pause: () => setPaused(true),
    resume: () => setPaused(false),
    mark: () => mark(),
    discard: () => discard(String(m.rec || '')),
    connect: async () => { await chrome.tabs.create({ url: CONNECT_URL }); return { ok: true }; },
    disconnect: async () => { await chrome.storage.local.remove(['token', 'user']); await badge(); return { ok: true }; },
    settings: async () => { const s = Object.assign(await settings(), m.settings || {}); await chrome.storage.local.set({ settings: s }); return { ok: true, settings: s }; },
    'mic-granted': async () => { await chrome.storage.local.set({ micBlocked: false }); return { ok: true }; },
    sync: async () => { const r = await pump(); await badge(); return { ok: true, ...r }; },
  }[m.type];
  if (!run) return false;
  run().then(reply, (e) => reply({ ok: false, error: e.message }));
  return true;
});

let pumpTimer = null;
function onRecEvent(ev) {
  if (ev.type === 'levels') {
    lastLevels = ev;
    toPopup({ type: 'levels', levels: ev });
    if (!ev.paused && ev.silentFor >= SILENCE_STOP_MIN * 60) stop('silence');
    if (ev.micDeadFor >= 60) warnOnce('mic', 'Your microphone is sending no sound at all. Check it is not muted on your computer, or that the right microphone is chosen — you are not being recorded.');
    if (ev.tabDeadFor >= 120 && ev.elapsed <= 240) warnOnce('tab', 'No sound has come from the meeting tab yet. If the meeting is in another tab, stop and start the recorder there.');
  } else if (ev.type === 'slice' || ev.type === 'segment-closed') {
    clearTimeout(pumpTimer);
    pumpTimer = setTimeout(() => pump().then(badge), 1500);
  } else if (ev.type === 'stopped' && ev.reason !== 'user') {
    stop(ev.reason);                     // the tab closed or its sound went away
  } else if (ev.type === 'mic-unavailable') {
    chrome.storage.local.set({ micBlocked: true });
  } else if (ev.type === 'error') {
    console.warn('[recorder]', ev.error);
  }
}

const prompted = new Set();
async function onMeetState(tab, m) {
  const live = await session.get();
  if (live && live.tabId === tab.id && m.people > 0) {
    // The most people Meet showed: a one-to-one call may name the other person, a bigger one never does.
    await store.update('recordings', live.rec, (r) => r && (r.peopleMax || 0) < m.people && Object.assign(r, { peopleMax: Math.min(500, m.people) }));
  }
  if (live && live.tabId === tab.id && m.inCall === undefined) return;
  if (live && live.tabId === tab.id) {
    // Left the call: give Meet a moment (it redraws while reconnecting) before stopping.
    clearTimeout(leftTimer);
    if (m.inCall === false) leftTimer = setTimeout(async () => { const l = await session.get(); if (l && l.tabId === tab.id) stop('left'); }, 20000);
    else toTab(tab.id, { type: 'banner', state: live.paused ? 'paused' : 'recording' });
    return;
  }
  if (!m.inCall || live) return;
  const s = await settings();
  const { token } = await chrome.storage.local.get('token');
  const key = tab.id + ':' + (m.code || '');
  if (!s.prompt || !token || prompted.has(key)) return;
  prompted.add(key);
  toTab(tab.id, { type: 'banner', state: 'prompt' });
}

chrome.commands.onCommand.addListener(async (command, tab) => {
  if (command === 'mark-moment') { await mark(); return; }
  if (command === 'pause-recording') { const l = await session.get(); if (l) await setPaused(!l.paused); return; }
  if (command !== 'toggle-recording') return;
  if (await session.get()) await stop('user');
  else {
    const r = await start(tab);
    if (!r.ok) chrome.notifications.create({ type: 'basic', iconUrl: 'icons/128.png', title: 'Meeting Recorder', message: r.error, priority: 1 });
  }
});

chrome.tabs.onRemoved.addListener(async (tabId) => { const l = await session.get(); if (l && l.tabId === tabId) stop('tab-ended'); });

chrome.alarms.onAlarm.addListener((a) => {
  if (a.name === 'pump') pump().then(badge);
  if (a.name === 'prune') prune();
});

chrome.runtime.onInstalled.addListener(async (d) => {
  chrome.alarms.create('pump', { periodInMinutes: 1 });
  chrome.alarms.create('prune', { periodInMinutes: 24 * 60 });
  if (d.reason === 'install') chrome.tabs.create({ url: chrome.runtime.getURL('welcome.html') });
  await recover();
});
chrome.runtime.onStartup.addListener(async () => {
  chrome.alarms.create('pump', { periodInMinutes: 1 });
  await recover();
  pump().then(badge);
});
chrome.notifications.onClicked.addListener(async (id) => {
  if (!id.startsWith('minutes-') && !id.startsWith('saved-')) return;
  const { lastMinutesUrl } = await chrome.storage.local.get('lastMinutesUrl');
  chrome.tabs.create({ url: lastMinutesUrl || SITE + '/portal/#meetings' });
});
