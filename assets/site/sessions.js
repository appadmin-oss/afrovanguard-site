/* ============================================================
   assets/site/sessions.js — the programme session strip on the homepage.

   Data: GET /api.php?action=sessions (lib/ProgrammeSessions.php):
     current    the session running today: LIVE NOW, its week and theme
     countdown  otherwise the session the office featured, or the next
                one: days, hours and minutes to its first morning (Lagos)

   The strip stays hidden when there is nothing to show or the request
   fails; the page never waits for it.
   ============================================================ */
(function () {
  'use strict';
  var box = document.querySelector('[data-avh-sess]');
  if (!box || !window.fetch) return;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function plural(n, w) { return n + ' ' + w + (n === 1 ? '' : 's'); }

  function live(s) {
    box.classList.add('is-live');
    box.innerHTML = '<div class="avh-sess-in avh-pad">' +
      '<span class="avh-sess-tag"><span class="avh-sess-dot" aria-hidden="true"></span>Live now</span>' +
      '<span class="avh-sess-name">' + esc(s.label) + '</span>' +
      '<span class="avh-sess-meta">Week ' + s.week_of + ' of ' + s.total_weeks + ' · ' + esc(s.dates) + '</span>' +
      (s.theme ? '<span class="avh-sess-theme">“' + esc(s.theme) + '”</span>' : '') +
      '</div>';
    box.hidden = false;
  }

  function countdown(s) {
    var at = Date.parse(s.starts_at);
    if (!at) return;
    box.innerHTML = '<div class="avh-sess-in avh-pad">' +
      '<span class="avh-sess-tag">Next session</span>' +
      '<span class="avh-sess-name">' + esc(s.label) + '</span>' +
      '<span class="avh-sess-meta">' + esc(s.dates) + (s.theme ? ' · “' + esc(s.theme) + '”' : '') + '</span>' +
      '<span class="avh-sess-clock" role="timer" aria-label="Time until ' + esc(s.label) + ' starts"></span>' +
      '</div>';
    var clock = box.querySelector('.avh-sess-clock');
    function tick() {
      var ms = at - Date.now();
      if (ms <= 0) { clock.textContent = 'Starting today'; return false; }
      var m = Math.floor(ms / 60000), d = Math.floor(m / 1440), h = Math.floor((m % 1440) / 60);
      clock.innerHTML = '<b>' + d + '</b><small>' + (d === 1 ? 'day' : 'days') + '</small><b>' + h + '</b><small>' + (h === 1 ? 'hr' : 'hrs') + '</small><b>' + (m % 60) + '</b><small>min</small>';
      clock.setAttribute('aria-label', plural(d, 'day') + ', ' + plural(h, 'hour') + ' until ' + s.label + ' starts');
      return true;
    }
    if (tick()) { var t = setInterval(function () { if (!tick()) clearInterval(t); }, 30000); }
    box.hidden = false;
  }

  fetch('/api.php?action=sessions', { credentials: 'omit', headers: { Accept: 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) {
      if (!d || d.status !== 'ok') return;
      if (d.current) live(d.current); else if (d.countdown) countdown(d.countdown);
    })
    .catch(function () {});
})();
