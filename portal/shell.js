/*
 * portal/shell.js — the portal's navigation, Home v4 (portal/index.php, portal/shell.css).
 *
 *   groups  the sidebar's headings fold their sections away. Which are folded
 *           is a per-device convenience (localStorage); the group holding the
 *           section on screen is always open, so the member never loses their place.
 *   sheet   the phone's More: opens at half height from the tab bar; the
 *           handle (tap or drag) takes it to full and back; drag down, Esc or
 *           the scrim closes it; focus stays inside while it is open and
 *           returns to More after. A section picked in it closes it.
 *   edit    the eight shortcut tiles are the member's own: Edit, then tap a
 *           tile to take it off or a section to add it. Saved to Prefs
 *           (portal/prefs.php?action=set_shortcuts), so they follow the member.
 *   theme   Light / Dark / Auto, through avp.js's window.avpSetTheme.
 *
 * The view router (avp.js) does the navigating; this file only opens, closes
 * and remembers.
 */
(function () {
  'use strict';
  function run(name, fn) { try { fn(); } catch (e) { if (window.console) console.warn('shell ' + name, e); } }
  var body = document.body;

  /* ── groups ───────────────────────────────────────────────────────── */
  run('groups', function () {
    var KEY = 'avp-nav-folded';
    var folded = {};
    try { folded = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { folded = {}; }
    var heads = [].slice.call(document.querySelectorAll('.avn-gh'));
    function set(h, open, save) {
      var box = document.getElementById(h.getAttribute('aria-controls'));
      h.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (box) box.hidden = !open;
      if (save) {
        var g = h.parentNode.getAttribute('data-group');
        folded[g] = !open;
        try { localStorage.setItem(KEY, JSON.stringify(folded)); } catch (e) {}
      }
    }
    heads.forEach(function (h) {
      var g = h.parentNode.getAttribute('data-group');
      var closed = g in folded ? folded[g] : h.hasAttribute('data-default-closed');
      set(h, !closed, false);
      h.addEventListener('click', function () { set(h, h.getAttribute('aria-expanded') !== 'true', true); });
    });
    /* The section on screen keeps its group open. */
    document.addEventListener('portal:view', function (e) {
      var asked = e.detail && e.detail.asked;
      var link = asked && document.querySelector('.avn-link[data-view="' + asked + '"]');
      var grp = link && link.closest('.avn-group');
      var h = grp && grp.querySelector('.avn-gh');
      if (h && h.getAttribute('aria-expanded') !== 'true') set(h, true, false);
    });
  });

  /* Today's phone header sits on the hero's cream band; every other view keeps a plain bar. */
  run('today', function () {
    var on = function (v) { body.classList.toggle('avn-on-today', v === 'overview'); };
    on(((location.hash || '').replace('#', '')) || 'overview');
    document.addEventListener('portal:view', function (e) { on(e.detail && e.detail.view); });
  });

  /* ── the More sheet ───────────────────────────────────────────────── */
  run('sheet', function () {
    var wrap = document.getElementById('avpSheet'), more = document.getElementById('avpMore');
    if (!wrap || !more) return;
    var sheet = wrap.querySelector('.avm-sheet'), handle = wrap.querySelector('.avm-handle');
    var all = wrap.querySelector('.avm-all'), msg = wrap.querySelector('.avm-msg');
    var opener = null;

    function setFull(on) {
      wrap.classList.toggle('is-full', on);
      handle.setAttribute('aria-expanded', on ? 'true' : 'false');
      handle.setAttribute('aria-label', on ? 'Shrink to half' : 'Open fully');
    }
    function open() {
      opener = document.activeElement;
      wrap.hidden = false;
      setFull(false);
      /* Next frame, so the slide-up transition has a starting point. */
      requestAnimationFrame(function () { wrap.classList.add('is-open'); });
      more.setAttribute('aria-expanded', 'true');
      body.classList.add('avm-locked');
      sheet.focus({ preventScroll: true });
    }
    function close() {
      if (wrap.hidden) return;
      if (wrap.classList.contains('is-editing') && window.avmEndEdit) window.avmEndEdit();
      wrap.classList.remove('is-open', 'is-full', 'is-editing');
      more.setAttribute('aria-expanded', 'false');
      body.classList.remove('avm-locked');
      sheet.style.transform = '';
      var done = function () { wrap.hidden = true; };
      if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) done(); else setTimeout(done, 260);
      if (opener && opener.focus) opener.focus();
    }
    more.addEventListener('click', function () { wrap.hidden ? open() : close(); });
    wrap.addEventListener('click', function (e) {
      if (e.target.closest('[data-avm-close]')) { close(); return; }
      if (wrap.classList.contains('is-editing')) return;   /* edit mode handles its own taps */
      var go = e.target.closest('[data-goto], [data-cmdk]');
      if (go) setTimeout(close, 0);                        /* the router (avp.js) moves first */
      var out = e.target.closest('[data-logout]');
      if (out) close();
    });
    handle.addEventListener('click', function () { if (!dragged) setFull(!wrap.classList.contains('is-full')); });
    if (all) all.addEventListener('click', function () { setFull(true); });

    /* Drag the handle: up for full, down to half and then away. */
    var startY = 0, dy = 0, dragging = false, dragged = false;
    handle.addEventListener('pointerdown', function (e) { dragging = true; dragged = false; startY = e.clientY; dy = 0; handle.setPointerCapture && handle.setPointerCapture(e.pointerId); });
    handle.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      dy = e.clientY - startY;
      if (Math.abs(dy) > 6) dragged = true;
      if (dy > 0) sheet.style.transform = 'translateY(' + dy + 'px)';
    });
    var end = function () {
      if (!dragging) return;
      dragging = false; sheet.style.transform = '';
      if (!dragged) return;
      if (dy < -40) setFull(true);
      else if (dy > 90) { wrap.classList.contains('is-full') ? setFull(false) : close(); }
      setTimeout(function () { dragged = false; }, 0);
    };
    handle.addEventListener('pointerup', end); handle.addEventListener('pointercancel', end);

    /* Esc closes; Tab stays inside while it is open. */
    wrap.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.stopPropagation(); close(); return; }
      if (e.key !== 'Tab') return;
      var f = [].slice.call(sheet.querySelectorAll('a[href], button:not([disabled])')).filter(function (el) { return el.offsetParent !== null; });
      if (!f.length) return;
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    });
    /* Grown past a phone, the sheet has no tab bar to come from. */
    var MQ = window.matchMedia ? matchMedia('(min-width: 640px)') : null;
    if (MQ) { var onMq = function () { if (MQ.matches) close(); }; if (MQ.addEventListener) MQ.addEventListener('change', onMq); else if (MQ.addListener) MQ.addListener(onMq); }

    /* ── Appearance ── */
    var segs = wrap.querySelector('.avm-segs');
    if (segs) segs.addEventListener('click', function (e) {
      var b = e.target.closest('.avm-seg'); if (!b) return;
      var m = b.getAttribute('data-mode');
      segs.setAttribute('data-mode', m);
      [].forEach.call(segs.querySelectorAll('.avm-seg'), function (x) { x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
      if (window.avpSetTheme) window.avpSetTheme(m);
    });

    /* ── Edit the shortcuts ── */
    var tiles = document.getElementById('avmTiles'), edit = wrap.querySelector('.avm-edit'), note = wrap.querySelector('.avm-edit-note');
    var MAX = 8, csrf = wrap.getAttribute('data-csrf') || '';
    function say(t) { if (msg) msg.textContent = t; }
    function ids() { return [].map.call(tiles.querySelectorAll('.avm-tile'), function (t) { return t.getAttribute('data-id'); }); }
    function save() {
      fetch('/portal/prefs.php?action=set_shortcuts', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ids: ids() })
      }).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; })
        .then(function (r) { say(r && r.ok ? 'Shortcuts saved.' : 'Your shortcuts were not saved — try again.'); });
    }
    function tileFor(row) {
      /* A new tile from a section row: its icon and its name, nothing invented. */
      var id = row.getAttribute('data-id');
      var a = document.createElement('a');
      a.className = 'avm-tile avm-tile--you'; a.href = '#' + id;
      a.setAttribute('data-goto-off', id); a.setAttribute('data-id', id);   /* made while editing: parked */
      var i = document.createElement('span'); i.className = 'avm-tile-i';
      var svg = row.querySelector('.avm-ico svg'); if (svg) i.appendChild(svg.cloneNode(true));
      a.appendChild(i);
      a.appendChild(document.createTextNode(row.childNodes[1] ? row.childNodes[1].textContent.trim() : id));
      return a;
    }
    /* The router (avp.js) listens at the document, before anything here can
       stop a tap, so while editing the links are parked rather than blocked. */
    function park(on) {
      [].forEach.call(wrap.querySelectorAll(on ? '.avm-tile[data-goto], .avm-it[data-goto]' : '[data-goto-off]'), function (a) {
        var v = a.getAttribute(on ? 'data-goto' : 'data-goto-off');
        a.removeAttribute(on ? 'data-goto' : 'data-goto-off');
        a.setAttribute(on ? 'data-goto-off' : 'data-goto', v);
      });
    }
    function setEditing(on) {
      park(on);
      wrap.classList.toggle('is-editing', on);
      edit.setAttribute('aria-pressed', on ? 'true' : 'false');
      edit.textContent = on ? 'Done' : 'Edit';
      if (note) note.hidden = !on;
      if (on) setFull(true);
      say(on ? 'Editing shortcuts.' : '');
    }
    if (edit && tiles) {
      window.avmEndEdit = function () { setEditing(false); save(); };
      edit.addEventListener('click', function () {
        var was = wrap.classList.contains('is-editing');
        setEditing(!was);
        if (was) save();
      });
      wrap.addEventListener('click', function (e) {
        if (!wrap.classList.contains('is-editing')) return;
        var t = e.target.closest('.avm-tile'), row = e.target.closest('.avm-it[data-id]');
        if (!t && !row) return;
        e.preventDefault(); e.stopPropagation();
        if (t) { say((t.textContent || '').trim() + ' taken off.'); t.remove(); return; }
        var id = row.getAttribute('data-id');
        if (ids().indexOf(id) >= 0) { say('Already a shortcut.'); return; }
        if (ids().length >= MAX) { say('Eight is the most — take one off first.'); return; }
        tiles.appendChild(tileFor(row));
        say((row.childNodes[1] ? row.childNodes[1].textContent.trim() : id) + ' added.');
      }, true);
    }
  });
})();
