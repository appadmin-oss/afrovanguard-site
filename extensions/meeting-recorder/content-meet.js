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
  var host = null, box = null, dismissed = false;

  function code() { var m = location.pathname.match(CODE); return m ? m[1] : ''; }

  /* In the call: people's tiles are on the page, or a "leave call" control is.
     The pre-join screen has neither. */
  function inCall() {
    if (!code()) return false;
    if (document.querySelector('[data-participant-id], [data-requested-participant-id]')) return true;
    var leave = document.querySelector('button[aria-label*="leave call" i], button[aria-label*="quitter l" i], [data-tooltip*="leave call" i]');
    return !!leave;
  }

  function report() {
    var now = inCall();
    if (now === last) return;
    last = now;
    try { chrome.runtime.sendMessage({ type: 'meet-state', code: code(), inCall: now, title: document.title }); } catch (e) { /* extension reloaded */ }
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
        + 'button{all:unset;cursor:pointer;color:#bbb;padding:0 6px;font-size:16px}button:focus-visible{outline:2px solid #fff;border-radius:4px}</style>'
        + '<div class="b" role="status"><span class="d"></span><span class="t"></span><button type="button" aria-label="Hide">×</button></div>';
      box = root.querySelector('.b');
      root.querySelector('button').addEventListener('click', function () { dismissed = true; host.style.display = 'none'; });
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
      ? 'Recording — the minutes will be written to this meeting. Let everyone know. Alt+Shift+M marks a moment.'
      : state === 'paused' ? 'Recording paused — Alt+Shift+P to resume.'
      : 'Record this meeting? Press Alt+Shift+R or click the recorder icon.';
    if (state === 'prompt') setTimeout(function () { if (host && box.className === 'b') host.style.display = 'none'; }, 20000);
  }

  chrome.runtime.onMessage.addListener(function (m) {
    if (!m || m.from !== 'av-recorder' || m.type !== 'banner') return;
    if (m.state === 'recording' || m.state === 'paused') dismissed = false;
    banner(m.state);
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
