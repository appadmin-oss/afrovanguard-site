/*
 * portal/home.js — Today, Home v4 (portal/views/today.php, portal/home.css).
 *
 *   composer   "What are you working on today?" — Enter adds a task due
 *              today, through the SAME endpoint the task list uses
 *              (portal/collab.php?action=task_add), so the rate limit, the
 *              CSRF check and the ownership rule live in one place, and
 *              CACENTRE reads it over the bridge it already has.
 *   your week  the calendar feed (portal/calendar.php), grouped Today / This
 *              week / Later. Events are drawn by the page with their buttons,
 *              so the feed's own copies of them are left out here.
 *   notice     the announcement can be dismissed; remembered per post, per browser.
 *   pulse      "Share my growth" opens the card first; nothing leaves the
 *              page until the member presses a share button.
 */
(function () {
  'use strict';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  /* ── composer ─────────────────────────────────────────────────────── */
  var form = document.getElementById('hvAsk');
  if (form) {
    var input = document.getElementById('hvAskTitle'), msg = document.getElementById('hvAskMsg');
    var csrf = form.getAttribute('data-csrf') || '', today = form.getAttribute('data-today') || '', busy = false;
    var say = function (t, bad) { msg.hidden = false; msg.textContent = t; msg.classList.toggle('is-bad', !!bad); };
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var title = (input.value || '').trim();
      if (!title) { say('Write the one thing first.', true); input.focus(); return; }
      if (busy) return;
      busy = true; say('Saving…');
      fetch('/portal/collab.php?action=task_add', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ title: title, due: today, priority: 'normal' })
      }).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'The network did not answer. Nothing was saved.' }; })
        .then(function (r) {
          busy = false;
          if (!r || !r.ok) { say((r && r.error) || 'It was not saved.', true); return; }
          input.value = '';
          say('Added to today — it is on your task list and in the centre’s console.');
          try { document.dispatchEvent(new CustomEvent('av:tasks-changed')); } catch (e) {}
          if (window.avReloadCalendar) window.avReloadCalendar();
        });
    });
  }

  /* ── your week ────────────────────────────────────────────────────── */
  var week = document.getElementById('hvWeek');
  if (week) {
    var TODAY = week.getAttribute('data-today') || '';
    var SKIP = { event: 1, site: 1, afg: 1, task: 1 };   /* events: the page draws them with their buttons; tasks: Needs you and the plan ring already say them */
    var addDays = function (ymd, n) { var d = new Date(ymd + 'T00:00:00'); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); };
    var WEEK_END = addDays(TODAY, 6), TO = addDays(TODAY, 21);
    var dayLabel = function (ymd) { var d = new Date(ymd + 'T00:00:00'); return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric' }); };
    var soon = function (it) {
      if (it.date !== TODAY || !it.time) return '';
      var p = it.time.split(':'), t = new Date(); var at = new Date(t.getFullYear(), t.getMonth(), t.getDate(), +p[0], +p[1]);
      var m = Math.round((at - t) / 60000);
      if (m < -60) return '';
      if (m <= 0) return 'now';
      return m <= 90 ? 'in ' + m + ' min' : '';
    };
    var row = function (it, first) {
      var isMeet = it.kind === 'meeting' || it.kind === 'session';
      var s = soon(it), when;
      if (it.date === TODAY) when = '<b>' + esc(it.time || 'All day') + '</b>' + (s ? '<span class="is-soon">' + esc(s) + '</span>' : '');
      else when = '<b>' + esc(dayLabel(it.date)) + '</b><span class="hv-small hv-muted">' + esc(it.time || 'all day') + '</span>';
      var meta = [];
      if (it.location) meta.push(esc(it.location)); if (it.who && it.who !== 'You') meta.push(esc(it.who));
      if (!meta.length && it.note) meta.push(esc(it.note));
      var okUrl = /^(https?:\/\/|\/)/i.test(it.url || '');
      var btn = okUrl ? '<span class="hv-ag-acts"><a class="hv-btn' + (s && isMeet ? ' hv-btn--ink' : '') + '" href="' + esc(it.url) + '" target="_blank" rel="noopener">' + (isMeet ? 'Join' : 'Open') + '<span class="hv-sr"> ' + esc(it.title) + '</span></a></span>' : '<span></span>';
      return '<li class="hv-ag"><span class="hv-ag-when' + (s ? ' is-live' : '') + '">' + when + '</span>'
        + '<span class="hv-ag-t"><b>' + esc(it.title) + '</b>' + (meta.length ? '<span class="hv-small hv-muted">' + meta.join(' · ') + '</span>' : '') + '</span>' + btn + '</li>';
    };
    var render = function (items) {
      items = (items || []).filter(function (it) { return !it.done && !SKIP[it.kind]; })
        .sort(function (a, b) { return (a.date + (a.time || '00:00')).localeCompare(b.date + (b.time || '00:00')); });
      var groups = [['Today · ' + dayLabel(TODAY), []], ['This week', []], ['Later', []]];
      items.forEach(function (it) { groups[it.date <= TODAY ? 0 : (it.date <= WEEK_END ? 1 : 2)][1].push(it); });
      var html = '';
      groups.forEach(function (g) { if (g[1].length) html += '<li class="hv-day">' + esc(g[0]) + '</li>' + g[1].slice(0, 6).map(row).join(''); });
      /* "A clear run" is about YOUR calendar, and it was being printed
         directly above the programme's events — the card said nothing was
         coming up and then listed something. When events follow, the feed
         says only what it knows, and when nothing follows at all it can
         still say the whole card is clear. */
      var hasEvents = week.getAttribute('data-has-events') === '1';
      week.innerHTML = html || '<li class="hv-empty">' + (hasEvents
        ? 'Nothing in your own calendar for the next three weeks — the events below are the programme\u2019s.'
        : 'Nothing in your calendar for the next three weeks. A clear run.') + '</li>';
    };
    var fail = function () { week.innerHTML = '<li class="hv-empty">Couldn’t load your calendar. <button type="button" class="hv-more" data-hv-retry>Try again</button></li>'; };
    var load = function () {
      fetch('/portal/calendar.php?action=feed&from=' + TODAY + '&to=' + TO, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); }).then(function (d) { if (d && d.ok) render(d.items); else fail(); }).catch(fail);
    };
    week.addEventListener('click', function (e) { if (e.target.closest('[data-hv-retry]')) load(); });
    window.avReloadCalendar = load;   /* the meeting scheduler refreshes the agenda */
    load();
  }

  /* ── the announcement ─────────────────────────────────────────────── */
  var notice = document.getElementById('hvNotice');
  if (notice) {
    var key = 'av-notice-gone-' + (notice.getAttribute('data-id') || '');
    try { if (localStorage.getItem(key)) notice.hidden = true; } catch (e) {}
    /* One line, always. When it does not fit it scrolls, at about 60px a
       second so it can be read, and the whole text is a page away. */
    var marq = notice.querySelector('.hv-marq'), box = notice.querySelector('.hv-notice-t');
    var fit = function () {
      if (!marq || !box || notice.hidden) return;
      notice.classList.remove('is-long');
      var w = marq.firstElementChild ? marq.firstElementChild.scrollWidth : 0;
      if (w > box.clientWidth + 2) {
        notice.classList.add('is-long');
        notice.style.setProperty('--hv-marq-s', Math.max(8, Math.round((w + 64) / 60)) + 's');
      }
    };
    fit();
    var t; window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(fit, 150); });
    notice.addEventListener('click', function (e) {
      if (!e.target.closest('[data-hv-dismiss]')) return;
      notice.hidden = true;
      try { localStorage.setItem(key, '1'); } catch (er) {}
    });
  }

  /* ── Vanguard Pulse ───────────────────────────────────────────────── */
  var dlg = document.getElementById('hvPulse');
  if (dlg) {
    var text = dlg.getAttribute('data-text') || '', url = dlg.getAttribute('data-url') || '';
    var pmsg = document.getElementById('hvPulseMsg'), share = document.getElementById('hvPulseShare');
    var opener = null;
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-hv-pulse]'); if (!b) return;
      opener = b; pmsg.textContent = '';
      if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
    });
    var close = function () { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); if (opener) opener.focus(); };
    document.getElementById('hvPulseClose').addEventListener('click', close);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    if (navigator.share) {
      share.hidden = false;
      share.addEventListener('click', function () { navigator.share({ title: 'Vanguard Pulse', text: text, url: url }).catch(function () {}); });
    }
    document.getElementById('hvPulseCopy').addEventListener('click', function () {
      var done = function () { pmsg.textContent = 'Copied. Paste it wherever you like.'; };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, function () { pmsg.textContent = 'Your browser would not copy it.'; });
      else pmsg.textContent = 'Your browser would not copy it.';
    });
  }
})();
