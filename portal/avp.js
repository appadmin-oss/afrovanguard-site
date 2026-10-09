/* portal/avp.js — the member portal shell (row 14, design: Afrovanguard Portal v2).
 *
 * One guarded init per feature, so a missing element never stops the rest:
 *   views    one view at a time, routed by the hash; [data-view] and [data-goto]
 *            links; the crumb; a `portal:view` event panes load themselves on
 *   drawer   the sidebar becomes a drawer under 960px: focus trap, Esc, focus
 *            returns to the button that opened it
 *   theme    light / dark, kept in the av_portal_theme cookie as before
 *   calendar Today's week strip and the picked day's agenda (portal/calendar.php)
 *   invite   copy the invite link
 *   pwa      the service worker
 * Layout decisions are CSS (media queries); script only asks matchMedia
 * whether the drawer is in use.
 */
(function () {
  'use strict';
  var root = document.documentElement;
  root.classList.remove('no-js');
  var body = document.body;
  var DRAWER_MQ = window.matchMedia ? window.matchMedia('(max-width: 959px)') : { matches: false };

  function run(name, fn) { try { fn(); } catch (e) { if (window.console) console.warn('avp ' + name, e); } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  /* ── drawer ─────────────────────────────────────────────────────────── */
  var drawer = { open: function () {}, close: function () {}, isOpen: function () { return false; } };
  run('drawer', function () {
    var side = document.getElementById('avpSide'), scrim = document.getElementById('avpScrim');
    var burger = document.getElementById('avpBurger'), more = document.getElementById('avpMore');
    if (!side) return;
    var opener = null;
    function setExpanded(on) {
      if (burger) burger.setAttribute('aria-expanded', on ? 'true' : 'false');
      if (more) more.setAttribute('aria-expanded', on ? 'true' : 'false');
    }
    function syncMode() {
      if (DRAWER_MQ.matches) {
        if (!body.classList.contains('avp-drawer-open')) side.setAttribute('aria-hidden', 'true');
        side.setAttribute('role', 'dialog'); side.setAttribute('aria-modal', 'true');
      } else {
        close(true);
        side.removeAttribute('aria-hidden'); side.removeAttribute('role'); side.removeAttribute('aria-modal');
      }
    }
    function focusables() {
      return [].slice.call(side.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'))
        .filter(function (el) { return el.offsetParent !== null; });
    }
    function open(from) {
      if (!DRAWER_MQ.matches) return;
      opener = from || document.activeElement;
      body.classList.add('avp-drawer-open');
      side.removeAttribute('aria-hidden');
      if (scrim) scrim.hidden = false;
      setExpanded(true);
      var f = side.querySelector('.avp-nav-link.is-active') || focusables()[0];
      if (f) f.focus();
    }
    function close(silent) {
      if (!body.classList.contains('avp-drawer-open')) return;
      body.classList.remove('avp-drawer-open');
      if (DRAWER_MQ.matches) side.setAttribute('aria-hidden', 'true');
      if (scrim) scrim.hidden = true;
      setExpanded(false);
      if (!silent && opener && opener.focus) opener.focus();
      opener = null;
    }
    drawer = { open: open, close: close, isOpen: function () { return body.classList.contains('avp-drawer-open'); } };
    if (burger) burger.addEventListener('click', function () { drawer.isOpen() ? close() : open(burger); });
    if (more) more.addEventListener('click', function () { drawer.isOpen() ? close() : open(more); });
    if (scrim) scrim.addEventListener('click', function () { close(); });
    side.addEventListener('keydown', function (e) {
      if (!drawer.isOpen()) return;
      if (e.key === 'Escape') { e.stopPropagation(); close(); return; }
      if (e.key !== 'Tab') return;
      var f = focusables(); if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    if (DRAWER_MQ.addEventListener) DRAWER_MQ.addEventListener('change', syncMode);
    else if (DRAWER_MQ.addListener) DRAWER_MQ.addListener(syncMode);
    syncMode();
  });

  /* ── views ──────────────────────────────────────────────────────────── */
  run('views', function () {
    var views = [].slice.call(document.querySelectorAll('.pview[data-view]'));
    if (!views.length) return;
    var navLinks = [].slice.call(document.querySelectorAll('.avp-nav-link[data-view], .avp-tab[data-view]'));
    var crumb = document.getElementById('crumbHere');
    var labelFor = {};
    navLinks.forEach(function (a) {
      var l = a.querySelector('.avp-nav-label') || a.querySelector('span');
      if (l && !labelFor[a.getAttribute('data-view')]) labelFor[a.getAttribute('data-view')] = l.textContent.trim();
    });
    labelFor.overview = 'Today';

    function showView(name, push) {
      var found = views.some(function (v) { return v.getAttribute('data-view') === name; });
      if (!found) name = 'overview';
      views.forEach(function (v) { v.hidden = v.getAttribute('data-view') !== name; });
      navLinks.forEach(function (l) {
        var on = l.getAttribute('data-view') === name;
        l.classList.toggle('is-active', on);
        if (on) l.setAttribute('aria-current', 'page'); else l.removeAttribute('aria-current');
      });
      if (crumb) crumb.textContent = labelFor[name] || 'Today';
      window.scrollTo(0, 0);
      if (push && ('#' + name) !== location.hash) {
        try { history.pushState(null, '', '#' + name); } catch (e) { location.hash = name; }
      }
      /* So a pane can load itself the first time somebody looks at it
         (portal/inventory.js, portal/leads.js). */
      try { document.dispatchEvent(new CustomEvent('portal:view', { detail: { view: name } })); } catch (e) {}
    }
    window.avpShowView = showView;

    document.addEventListener('click', function (e) {
      var a = e.target.closest('a[data-view], [data-goto]');
      if (!a || e.defaultPrevented) return;
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
      var name = a.getAttribute('data-goto') || a.getAttribute('data-view');
      if (a.matches('a[data-view]') && !a.closest('.avp-side, .avp-tabs')) return;
      e.preventDefault();
      showView(name, true);
      drawer.close(true);
      var main = document.getElementById('main-content');
      if (a.closest('.avp-side') && main) main.focus({ preventScroll: true });
    });
    window.addEventListener('hashchange', function () { showView((location.hash || '').replace('#', ''), false); });
    showView((location.hash || '').replace('#', '') || 'overview', false);
    /* Some views carry an element with the view's own name as its id (#tasks,
       #membership), and the browser's fragment jump lands on it after the
       first paint. A view opens at its top. */
    window.addEventListener('load', function () { if (location.hash) window.scrollTo(0, 0); });
  });

  /* ── theme ──────────────────────────────────────────────────────────── */
  run('theme', function () {
    var btn = document.getElementById('portalTheme');
    function sync() {
      var dark = body.classList.contains('is-dark');
      /* The embedded Community keys off <html data-theme>. */
      root.setAttribute('data-theme', dark ? 'dark' : 'light');
      if (btn) {
        btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
        btn.setAttribute('aria-label', dark ? 'Light mode' : 'Dark mode');
        btn.setAttribute('title', dark ? 'Light mode' : 'Dark mode');
      }
    }
    if (btn) btn.addEventListener('click', function () {
      var dark = body.classList.toggle('is-dark');
      document.cookie = 'av_portal_theme=' + (dark ? 'dark' : 'light') + ';path=/;max-age=31536000;samesite=Lax';
      sync();
    });
    sync();
  });

  /* ── Today: your calendar ───────────────────────────────────────────── */
  run('calendar', function () {
    var box = document.getElementById('calAgenda'); if (!box) return;
    var weekEl = document.getElementById('calWeek'), sumEl = document.getElementById('calSummary');
    var ITEMS = [], PICK = 0;
    var BAR = { meeting: 'indigo', session: 'green', event: 'gold', afg: 'gold', gcal: 'indigo', task: 'red', reminder: 'gold' };
    function ymd(off) { var d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() + (off || 0)); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
    var TODAY = ymd(0), from = TODAY, to = ymd(14);
    function timeLabel(t) { if (!t) return 'All day'; var p = t.split(':'); var h = +p[0]; return ((h % 12) || 12) + ':' + p[1] + (h < 12 ? 'am' : 'pm'); }
    function rowHtml(it) {
      var meta = [];
      if (it.location) meta.push(esc(it.location));
      if (it.who && it.who !== 'You') meta.push(esc(it.who));
      if (!meta.length && it.note) meta.push(esc(it.note));
      var isMeet = it.kind === 'meeting' || it.kind === 'session';
      var over = it.kind === 'task' && it.date < TODAY;
      var join = it.url ? '<a class="avp-join" href="' + esc(it.url) + '" target="_blank" rel="noopener">' + (isMeet ? 'Join' : 'Open') + '<span class="av-sr"> ' + esc(it.title) + '</span></a>' : '';
      return '<li class="avp-ag-row' + (over ? ' is-over' : '') + '">'
        + '<span class="avp-ag-time av-num">' + esc(timeLabel(it.time)) + '</span>'
        + '<span class="avp-ag-bar avp-bar--' + (BAR[it.kind] || 'gray') + '" aria-hidden="true"></span>'
        + '<span class="avp-ag-t"><span class="avp-ag-title">' + esc(it.title) + '</span>'
        + (meta.length ? '<span class="avp-ag-sub">' + meta.join(' · ') + '</span>' : '') + '</span>' + join + '</li>';
    }
    function renderWeek() {
      if (!weekEl) return;
      var cnt = {}; ITEMS.forEach(function (it) { cnt[it.date] = (cnt[it.date] || 0) + 1; });
      var html = '';
      for (var i = 0; i < 7; i++) {
        var d = ymd(i), dt = new Date(d + 'T00:00:00'), n = cnt[d] || 0;
        var long = dt.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
        html += '<button type="button" class="avp-day' + (i === PICK ? ' is-on' : '') + (n ? ' has-items' : '') + '" data-i="' + i + '" aria-pressed="' + (i === PICK ? 'true' : 'false') + '"'
          + ' aria-label="' + esc(long + (n ? ', ' + n + ' item' + (n === 1 ? '' : 's') : ', nothing scheduled')) + '">'
          + '<span class="avp-day-l" aria-hidden="true">' + esc('SMTWTFS'.charAt(dt.getDay())) + '</span>'
          + '<span class="avp-day-n av-num" aria-hidden="true">' + dt.getDate() + '</span>'
          + '<span class="avp-day-dot" aria-hidden="true"></span></button>';
      }
      weekEl.innerHTML = html;
    }
    function renderDay() {
      var d = ymd(PICK);
      var rows = ITEMS.filter(function (it) { return it.date === d; });
      box.innerHTML = rows.length ? '<ul class="avp-ag-list">' + rows.map(rowHtml).join('') + '</ul>'
        : '<p class="avp-empty">Nothing scheduled. A clear day.</p>';
    }
    function render(items) {
      ITEMS = (items || []).filter(function (it) { return !it.done; });
      var meetN = ITEMS.filter(function (it) { return it.kind === 'meeting' || it.kind === 'session'; }).length;
      var taskN = ITEMS.filter(function (it) { return it.kind === 'task'; }).length;
      if (sumEl) sumEl.textContent = ITEMS.length ? (meetN + ' meeting' + (meetN === 1 ? '' : 's') + ' · ' + taskN + ' task' + (taskN === 1 ? '' : 's') + ' · next 14 days') : 'Next 14 days';
      renderWeek(); renderDay();
    }
    function fail() {
      box.innerHTML = '<p class="avp-empty">Couldn’t load your calendar. <button type="button" class="avp-link-btn" data-avp-retry>Try again</button></p>';
    }
    function loadCal() {
      fetch('/portal/calendar.php?action=feed&from=' + from + '&to=' + to, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.ok) render(d.items); else fail(); })
        .catch(fail);
    }
    if (weekEl) weekEl.addEventListener('click', function (e) {
      var b = e.target.closest('.avp-day'); if (!b) return;
      PICK = +b.getAttribute('data-i') || 0; renderWeek(); renderDay();
      var on = weekEl.querySelector('.avp-day.is-on'); if (on) on.focus();
    });
    box.addEventListener('click', function (e) { if (e.target.closest('[data-avp-retry]')) loadCal(); });
    window.avReloadCalendar = loadCal;   // the meeting scheduler refreshes the agenda
    loadCal();
  });

  /* ── Today: copy the invite link ────────────────────────────────────── */
  run('invite', function () {
    var b = document.getElementById('copyInvite'); if (!b) return;
    var t = null;
    b.addEventListener('click', function () {
      var u = b.getAttribute('data-url') || '';
      function done() { b.textContent = 'Copied'; clearTimeout(t); t = setTimeout(function () { b.textContent = 'Copy'; }, 1800); }
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(u).then(done, done);
      else {
        var ta = document.createElement('textarea'); ta.value = u; ta.setAttribute('readonly', ''); ta.className = 'av-sr';
        body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} body.removeChild(ta); done();
      }
    });
  });

  /* ── PWA ────────────────────────────────────────────────────────────── */
  run('pwa', function () {
    if ('serviceWorker' in navigator) window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });
  });
})();
