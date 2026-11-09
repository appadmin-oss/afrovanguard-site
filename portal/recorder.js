/* portal/recorder.js — the "Connect the recorder" page (portal/recorder.php).
 *
 * The extension's content script marks the page (<html data-av-recorder="1.0.0">)
 * and listens for one message: { type: 'av-recorder-pair', code }. It answers
 * with { type: 'av-recorder-paired', ok, error }. Nothing else crosses over. */
(function () {
  'use strict';
  var root = document.getElementById('avrec');
  if (!root) return;
  var csrf = root.getAttribute('data-csrf') || '';
  var btn = document.getElementById('avrec-connect');
  var state = document.getElementById('avrec-state');
  var missing = document.getElementById('avrec-missing');

  function say(text, kind) {
    if (!state) return;
    state.textContent = text;
    state.className = 'state' + (kind ? ' ' + kind : '');
  }
  function post(action, body) {
    return fetch('/portal/recorder.php?action=' + action, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server did not answer.' }; }); });
  }
  function installed() { return !!document.documentElement.getAttribute('data-av-recorder'); }

  /* The content script runs at document_idle; give it a moment before saying it is missing. */
  if (btn) {
    var tries = 0;
    (function check() {
      if (installed()) { btn.disabled = false; if (missing) missing.hidden = true; return; }
      if (++tries < 15) { setTimeout(check, 200); return; }
      if (missing) missing.hidden = false;
    })();

    btn.addEventListener('click', function () {
      btn.disabled = true;
      say('Connecting…');
      var timer = null;
      function onReply(ev) {
        if (ev.source !== window || ev.origin !== location.origin) return;
        var d = ev.data || {};
        if (d.type !== 'av-recorder-paired') return;
        window.removeEventListener('message', onReply);
        clearTimeout(timer);
        if (d.ok) { say('Connected. You can record meetings from this browser now.', 'ok'); setTimeout(function () { location.reload(); }, 1500); }
        else { say(d.error || 'The recorder could not connect. Try again.', 'err'); btn.disabled = false; }
      }
      post('pair').then(function (r) {
        if (!r.ok || !r.code) { say(r.error || 'Could not start connecting. Try again.', 'err'); btn.disabled = false; return; }
        window.addEventListener('message', onReply);
        timer = setTimeout(function () {
          window.removeEventListener('message', onReply);
          say('The recorder did not answer. Reload this page and try again.', 'err');
          btn.disabled = false;
        }, 15000);
        window.postMessage({ type: 'av-recorder-pair', code: r.code }, location.origin);
      }).catch(function () { say('No connection. Try again.', 'err'); btn.disabled = false; });
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-revoke]'), function (b) {
    b.addEventListener('click', function () {
      b.disabled = true;
      post('revoke', { id: Number(b.getAttribute('data-revoke')) }).then(function (r) {
        if (r.ok) { var li = b.closest('li'); if (li) li.remove(); }
        else { b.disabled = false; }
      });
    });
  });
})();
