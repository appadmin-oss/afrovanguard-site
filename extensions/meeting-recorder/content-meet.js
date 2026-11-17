/* content-meet.js — on Google Meet: tells the recorder when a call starts and
 * ends, and shows a small banner (a reminder to record, or "Recording").
 *
 * It reads nothing from the call but whether you are in it. Meet's page is not
 * a public interface and changes without notice, so every check here is
 * best-effort: if it stops matching, recording still works and still stops
 * when the tab closes or the call goes quiet; only the reminder and the
 * stop-on-leave go quiet. */
(function () {
  'use strict';
  var CODE = /^\/([a-z]{3}-[a-z]{4}-[a-z]{3})(?:$|[/?#])/;
  var last = null;
  var host = null, box = null, dismissed = false, confirmed = false;

  function code() { var m = location.pathname.match(CODE); return m ? m[1] : ''; }

  /* In the call: people's tiles are on the page, or a "leave call" control is.
     The pre-join screen has neither. */
  function inCall() {
    if (!code()) return false;
    if (document.querySelector('[data-participant-id], [data-requested-participant-id]')) return true;
    var leave = document.querySelector('button[aria-label*="leave call" i], button[aria-label*="quitter l" i], [data-tooltip*="leave call" i]');
    return !!leave;
  }

  /* How many people the call shows (tiles carry a participant id). Only a
     count, never names: it lets the recorder name the other person in a
     one-to-one call, and stops it doing so when a third person joins. */
  var peopleMax = 0;
  function people() {
    var ids = {};
    var tiles = document.querySelectorAll('[data-participant-id]');
    for (var i = 0; i < tiles.length; i++) ids[tiles[i].getAttribute('data-participant-id')] = 1;
    return Object.keys(ids).length;
  }

  function report() {
    var now = inCall();
    var n = now ? people() : 0;
    var grew = n > peopleMax;
    if (grew) peopleMax = n;
    if (now === last && !grew) return;
    var changed = now !== last;
    last = now;
    try { chrome.runtime.sendMessage(changed ? { type: 'meet-state', code: code(), inCall: now, title: document.title, people: peopleMax } : { type: 'meet-state', code: code(), people: peopleMax }); } catch (e) { /* extension reloaded */ }
  }

  function banner(state) {
    if (state === 'hidden') { if (host) host.remove(); host = null; return; }
    if (state === 'prompt' && dismissed) return;
    if (!host) {
      host = document.createElement('div');
      host.setAttribute('data-av-recorder-banner', '');
      host.style.cssText = 'position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:2147483647';
      var root = host.attachShadow({ mode: 'closed' });
      root.innerHTML = '<style>.b{display:flex;align-items:center;gap:10px;font:500 14px/1.3 system-ui,sans-serif;background:#15120e;color:#fff;border-radius:999px;padding:8px 10px 8px 14px;box-shadow:0 4px 18px rgba(0,0,0,.25);max-width:92vw}'
        + '.d{width:9px;height:9px;border-radius:50%;background:#e4162b;flex:none}.b.rec .d{animation:p 1.4s ease-in-out infinite}@keyframes p{50%{opacity:.3}}'
        + '@media (prefers-reduced-motion:reduce){.b.rec .d{animation:none}}'
        + 'button{all:unset;cursor:pointer;color:#bbb;padding:0 6px;font-size:16px}button:focus-visible{outline:2px solid #fff;border-radius:4px}'
        + '.ok{color:#15120e;background:#fff;border-radius:999px;padding:4px 10px;font-size:13px;font-weight:600;white-space:nowrap}.ok[hidden]{display:none}</style>'
        + '<div class="b" role="status"><span class="d"></span><span class="t"></span><button type="button" class="ok" hidden>I\u2019ve told everyone</button><button type="button" class="x" aria-label="Hide">×</button></div>';
      box = root.querySelector('.b');
      root.querySelector('.x').addEventListener('click', function () { dismissed = true; host.style.display = 'none'; });
      // The person recording says they told the others. Only that is recorded as consent.
      root.querySelector('.ok').addEventListener('click', function () {
        try { chrome.runtime.sendMessage({ type: 'consent' }); } catch (e) { /* extension reloaded */ }
        confirmed = true; this.hidden = true;
      });
      document.documentElement.appendChild(host);
    }
    host.style.display = '';
    if (state === 'marked') {
      // A short confirmation, then back to the recording banner.
      var t = box.querySelector('.t'), before = t.textContent || 'Recording — the minutes will be written to this meeting. Let everyone know. Alt+Shift+M marks a moment.';
      box.className = 'b rec';
      t.textContent = '★ Moment marked — it will stand out in the minutes.';
      setTimeout(function () { if (t.textContent.charAt(0) === '★') t.textContent = before; }, 2500);
      return;
    }
    box.className = 'b' + (state === 'recording' ? ' rec' : '');
    box.querySelector('.t').textContent = state === 'recording'
      ? 'Recording. Tell everyone in the call — they are not told by the recorder.'
      : state === 'paused' ? 'Recording paused — Alt+Shift+P to resume.'
      : 'Record this meeting? Press Alt+Shift+R or click the recorder icon.';
    if (state === 'prompt') setTimeout(function () { if (host && box.className === 'b') host.style.display = 'none'; }, 20000);
  }

  chrome.runtime.onMessage.addListener(function (m, sender, reply) {
    if (!m || m.from !== 'av-recorder' || m.type !== 'banner') return;
    if (m.state === 'recording' && m.fresh) confirmed = false;   // a new recording asks again
    if (m.state === 'recording' || m.state === 'paused') dismissed = false;
    // Recording just started: say how many people the call has shown, even if that did not change.
    if (m.state === 'recording') {
      peopleMax = Math.max(peopleMax, people());
      try { chrome.runtime.sendMessage({ type: 'meet-state', code: code(), people: peopleMax }); } catch (e) { /* extension reloaded */ }
    }
    banner(m.state);
    if (box && m.state !== 'marked') { var ok = box.querySelector('.ok'); if (ok) ok.hidden = !(m.state === 'recording' && !confirmed && !m.confirmed); }
    // Tell the recorder the reminder really is on screen: that, and only that, is "reminded".
    reply({ shown: !!host && host.style.display !== 'none' && (m.state === 'recording' || m.state === 'paused') });
  });

  var queued = false;
  new MutationObserver(function () {
    if (queued) return;
    queued = true;
    setTimeout(function () { queued = false; report(); }, 1500);
  }).observe(document.documentElement, { childList: true, subtree: true });
  setInterval(report, 5000);
  report();
})();
