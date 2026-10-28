/* portal/mentor-meet.js — moved verbatim from the inline script of portal/index.php v1
   (row 14 shell rebuild). Its logic is unchanged; it reads the same ids and data-* hooks. */
  /* Mentorship meetings — trigger the Meet link + auto-log start/end times. */
  (function () {
    var root = document.getElementById('mentorSchedule'); if (!root) return;
    var csrf = root.getAttribute('data-csrf') || '';
    function post(action, body) { return fetch('/mentorship/api.php?action=' + action, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body || {}) }).then(function (r) { return r.json(); }); }
    function parseUTC(s) { return Date.parse((s || '').replace(' ', 'T') + 'Z') || 0; }
    function hhmm(iso) { var t = parseUTC(iso); if (!t) return ''; return new Date(t).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }); }
    function fmtDur(m) { m = Math.max(0, m || 0); var h = Math.floor(m / 60), r = m % 60; return h ? (h + 'h' + (r ? ' ' + r + 'm' : '')) : (r + 'm'); }
    function srcLabel(s) {
      var v = s === 'meet' || s === 'reports';
      var t = s === 'meet' ? 'Verified by Google Meet' : (s === 'reports' ? 'Verified · Meet audit log' : 'Provisional · confirming with Google');
      return ' <span class="msi-src ' + (v ? 'msi-verified' : 'msi-provisional') + '">' + t + '</span>';
    }
    function doneMsg(d) { return '✓ Logged ' + fmtDur(d.duration_min) + ' · ' + hhmm(d.started_at) + '–' + hhmm(d.ended_at) + srcLabel(d.source || ''); }
    // Live elapsed as H:MM:SS (or M:SS under an hour).
    function fmtElapsed(sec) { sec = Math.max(0, sec | 0); var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60, p = function (n) { return (n < 10 ? '0' : '') + n; }; return h ? (h + ':' + p(m) + ':' + p(s)) : (m + ':' + p(s)); }
    var pingTimers = {};
    var liveHtml = '<span class="msi-live"><span class="dot-live"></span>Live · <span class="msi-timer">0:00</span></span>';
    function tickAll() {
      var now = Date.now();
      [].forEach.call(root.querySelectorAll('.msi[data-state="live"]'), function (li) {
        var t = parseUTC(li.getAttribute('data-started') || ''); var el = li.querySelector('.msi-timer');
        if (t && el) el.textContent = fmtElapsed((now - t) / 1000);
      });
    }
    setInterval(tickAll, 1000); tickAll();
    function setState(li, state, log) {
      li.setAttribute('data-state', state);
      var start = li.querySelector('.msi-start'), logEl = li.querySelector('.msi-log');
      if (state === 'live') { if (start) { start.hidden = false; start.textContent = 'Join meeting'; } }
      else if (state === 'done') { if (start) start.hidden = true; }
      else { if (start) { start.hidden = false; start.textContent = 'Start meeting'; } }
      if (log != null && logEl) logEl.innerHTML = log;
      if (state === 'live') tickAll();
    }
    // One beat every 60s keeps a live meeting fresh; the server closes it (and
    // logs the hours) automatically once the beats stop — nobody presses "end".
    function startPing(li, id) {
      stopPing(id);
      pingTimers[id] = setInterval(function () { if (!document.hidden) post('meet_ping', { session_id: id }); }, 60000);
    }
    function stopPing(id) { if (pingTimers[id]) { clearInterval(pingTimers[id]); delete pingTimers[id]; } }
    // Fire one last beat on leave so the auto-logged end time ≈ when they left.
    function beat(id) {
      var url = '/mentorship/api.php?action=meet_ping';
      var payload = JSON.stringify({ session_id: id });
      try {
        if (navigator.sendBeacon) { navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' })); return; }
      } catch (e) {}
      fetch(url, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: payload }).catch(function () {});
    }
    function farewell() {
      Object.keys(pingTimers).forEach(function (id) { beat(+id); });
    }
    document.addEventListener('visibilitychange', function () { if (document.hidden) farewell(); });
    window.addEventListener('pagehide', farewell);
    root.addEventListener('click', function (e) {
      if (!e.target.closest('.msi-start')) return;
      var li = e.target.closest('.msi'); if (!li) return;
      var id = +li.getAttribute('data-session');
      var meet = li.getAttribute('data-meet') || '';
      // Open a tab synchronously (user gesture → not blocked); we'll point it at
      // the room. If there's no link yet, meet_start generates one and returns it.
      var w = window.open(meet || '', '_blank');
      post('meet_start', { session_id: id }).then(function (d) {
        if (d && d.ok) {
          if (d.url) { li.setAttribute('data-meet', d.url); if (w) { try { w.location = d.url; } catch (e) {} } else window.open(d.url, '_blank'); }
          else if (w && !meet) { try { w.close(); } catch (e) {} }
          li.setAttribute('data-started', d.started_at || ''); setState(li, 'live', liveHtml); startPing(li, id);
        } else { if (w) { try { w.close(); } catch (e) {} } }
      }).catch(function () { if (w) { try { w.close(); } catch (e) {} } });
    });
    // Keep already-live rows pinging and reflect the meeting being auto-closed.
    [].forEach.call(root.querySelectorAll('.msi[data-state="live"]'), function (li) {
      var id = +li.getAttribute('data-session'); startPing(li, id);
      var poll = setInterval(function () {
        post('meet_state', { session_id: id }).then(function (d) {
          if (d && d.ok && !d.live && d.ended_at) { clearInterval(poll); stopPing(id); setState(li, 'done', doneMsg(d)); }
        });
      }, 30000);
    });
  })();

