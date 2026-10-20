/* portal/todo-pop.js — the to-do pop-up.
 *
 * One script for both sites: the Afrovanguard portal (and its Volunteer
 * dashboard) and CACENTRE's consoles carry a copy. The server sends sections
 * of items, and each item carries the action its button posts, so this file
 * knows nothing about either site:
 *
 *   <div class="tdp" id="todoPop" data-src="/portal/collab.php" data-csrf="…"
 *        data-key="av" hidden></div>
 *   GET  {src}?action=todo  → {ok, enabled, role?, sections:[{key,title,ctx?,nudge?,items:[…]}], all}
 *   POST {src}?action={item.tick|take.action}  body: item.tick|take.body
 *   item.acts: [{action, label, body, url?}] — more buttons; `url` posts to
 *   another endpoint of the same site (an RSVP, a commitment done).
 *   item.kind picks its icon; item.badge {text, tone} its label; tick.undo
 *   says ticking it again puts it back (Undo is offered for a few seconds).
 *   section.group 'goals' (with sub and progress) files it under the Goals tab:
 *   a goal and the steps drawn from it. Tasks elsewhere name their goal (item.goal).
 *   section.ctx ('work'|'member'|'volunteer') puts the section that matches
 *   the screen somebody is on first; section.nudge says whether it is worth
 *   opening the pop-up for; data.role.label says who the list is for.
 *
 * When it opens by itself: something was assigned since the last look, or a
 * section worth opening for has not been seen today. Otherwise the To do
 * button waits in the corner. It never takes focus when it opens by itself
 * (a screen reader hears one polite sentence instead); opened by hand, focus
 * goes to its heading. Escape closes it; Alt+Shift+T toggles it anywhere.
 */
(function () {
  'use strict';
  var root = document.getElementById('todoPop');
  if (!root || !window.fetch) return;
  var src = root.getAttribute('data-src') || '';
  var csrf = root.getAttribute('data-csrf') || '';
  var key = 'tdp.' + (root.getAttribute('data-key') || 'x') + '.';
  var data = null, open = false, launcher = null, panel = null, live = null;
  var tab = 'now', undoTimer = 0, lastDone = null;

  function store(k, v) { try { if (v === undefined) return localStorage.getItem(key + k); localStorage.setItem(key + k, String(v)); } catch (e) { return null; } return null; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function today() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function say(t) { if (!live) return; live.textContent = ''; setTimeout(function () { live.textContent = t; }, 60); }

  /* ── Icons: one stroke set, drawn with currentColor, hidden from readers ── */
  var ICON = {
    task: '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
    cac: '<path d="M4 21V6l8-3 8 3v15"/><path d="M9 21v-5h6v5M9 9h.01M15 9h.01M9 13h.01M15 13h.01"/>',
    goal: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
    spark: '<path d="M12 3l1.8 4.7 4.7 1.8-4.7 1.8L12 16l-1.8-4.7-4.7-1.8 4.7-1.8z"/><path d="M19 15l.7 1.8 1.8.7-1.8.7L19 20l-.7-1.8-1.8-.7 1.8-.7z"/>',
    rsvp: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    live: '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="m16 10 6-3v10l-6-3"/>',
    minutes: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
    shift: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    action: '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
    dues: '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
    ngv: '<path d="M4 4h6a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H4z"/><path d="M20 4h-6a3 3 0 0 0-3 3v13a2 2 0 0 1 2-2h7z"/>',
    gate: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M10 10l4 4M14 10l-4 4"/>',
    mentor: '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-6 7-6s7 2.5 7 6"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14c2.5.6 4 2.8 4 6"/>',
    list: '<path d="M10 6h11M10 12h11M10 18h11"/><path d="m3 6 1.5 1.5L7 5M3 12l1.5 1.5L7 11M3 18l1.5 1.5L7 17"/>',
    check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    close: '<path d="M6 6l12 12M18 6 6 18"/>',
    moon: '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>',
    arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>'
  };
  function icon(name, cls) { return '<svg class="' + (cls || 'tdp-ico') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + (ICON[name] || ICON.task) + '</svg>'; }

  /* ── What there is ─────────────────────────────────────────────────── */
  /* Where a section lives: Now (what needs doing), Goals (each goal and its
     steps, and the week's commitments), For you (suggestions). */
  function where(s) { return s.key === 'suggested' ? 'you' : (s.group === 'goals' || s.key === 'week') ? 'goals' : 'now'; }
  /* What needs doing — counted once, from Now. */
  function count() {
    var n = 0;
    (data.sections || []).forEach(function (s) { if (where(s) === 'now') n += s.items.length; });
    return n;
  }
  function urgentCount() {
    var n = 0;
    (data.sections || []).forEach(function (s) { if (where(s) === 'now') s.items.forEach(function (it) { if (it.urgent) n++; }); });
    return n;
  }
  function inTab(t) { return (data.sections || []).filter(function (s) { return where(s) === t; }); }
  function newest() {
    var a = (data.sections || []).filter(function (s) { return s.key === 'assigned'; })[0];
    return a && a.items.length ? String(a.items[0].fresh || '') : '';
  }
  function doneToday(add) {
    var d = store('doneday') === today() ? (+(store('done') || 0)) : 0;
    if (add) { d = Math.max(0, d + add); store('done', d); store('doneday', today()); }
    return d;
  }
  function has(k) { return (data.sections || []).some(function (s) { return s.key === k && s.items.length; }); }

  /* Which kind of screen this is: the matching section goes first. */
  var MEMBER_VIEWS = ['membership', 'ngv', 'ngv-account', 'attendance', 'mentorship', 'your-card', 'dues'];
  function viewCtx() {
    var h = (location.hash || '').replace(/^#/, '').split(/[/?]/)[0];
    if (h === 'volunteer') return 'volunteer';
    if (MEMBER_VIEWS.indexOf(h) >= 0) return 'member';
    return 'work';
  }
  function ordered() {
    var ctx = viewCtx(), list = (data.sections || []).slice();
    if (ctx !== 'work') list = list.filter(function (s) { return s.ctx === ctx; }).concat(list.filter(function (s) { return s.ctx !== ctx; }));
    return list.filter(function (s) { return where(s) === tab; });
  }

  /* ── Building ──────────────────────────────────────────────────────── */
  function build() {
    live = document.createElement('p');
    live.className = 'tdp-sr'; live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite');
    root.appendChild(live);
    launcher = document.createElement('button');
    launcher.type = 'button'; launcher.className = 'tdp-launch'; launcher.hidden = true;
    launcher.setAttribute('aria-haspopup', 'dialog'); launcher.setAttribute('aria-controls', 'tdpPanel');
    launcher.addEventListener('click', function () { show(true); });
    root.appendChild(launcher);
    panel = document.createElement('section');
    panel.id = 'tdpPanel'; panel.className = 'tdp-panel'; panel.hidden = true;
    panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-modal', 'false'); panel.setAttribute('aria-labelledby', 'tdpH');
    root.appendChild(panel);
    panel.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.stopPropagation();
        var menu = panel.querySelector('.tdp-snooze-menu:not([hidden])');
        if (menu) { menu.hidden = true; var sb = panel.querySelector('[data-kind=snooze]'); if (sb) { sb.setAttribute('aria-expanded', 'false'); sb.focus(); } return; }
        hide(true);
      }
    });
    panel.addEventListener('click', act);
    document.addEventListener('keydown', function (e) {
      if (e.altKey && e.shiftKey && (e.key === 'T' || e.key === 't')) { e.preventDefault(); open ? hide(true) : show(true); }
    });
    root.hidden = false;
  }

  function greeting() {
    var h = new Date().getHours();
    var g = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
    return data.role && data.role.first ? g + ', ' + data.role.first : 'To do';
  }

  function ring(done, left) {
    var total = done + left, pct = total ? done / total : 1, r = 15, c = 2 * Math.PI * r;
    return '<svg class="tdp-ring" viewBox="0 0 36 36" aria-hidden="true" focusable="false"><circle class="tdp-ring-bg" cx="18" cy="18" r="' + r + '"/>'
      + (pct > 0 ? '<circle class="tdp-ring-fg" cx="18" cy="18" r="' + r + '" stroke-dasharray="' + (c * pct).toFixed(1) + ' ' + c.toFixed(1) + '"/>' : '') + '</svg>'
      + '<span class="tdp-ring-n" aria-hidden="true">' + (left === 0 ? icon('check', 'tdp-ring-ok') : left) + '</span>';
  }

  function badge(b) {
    if (!b || !b.text) return '';
    return '<span class="tdp-badge tdp-badge--' + esc(b.tone || 'muted') + '">' + esc(b.text) + '</span>';
  }

  function item(it) {
    var btns = '';
    if (it.take) btns += '<button type="button" class="tdp-btn tdp-btn--primary" data-id="' + esc(it.id) + '" data-kind="take">' + esc(it.take.label || 'Take it') + '<span class="tdp-sr">: ' + esc(it.title) + '</span></button>';
    (it.acts || []).forEach(function (a, i) {
      btns += '<button type="button" class="tdp-btn' + (i === 0 ? ' tdp-btn--primary' : '') + '" data-id="' + esc(it.id) + '" data-kind="act" data-i="' + i + '">' + esc(a.label) + '<span class="tdp-sr">: ' + esc(it.title) + '</span></button>';
    });
    var lead = it.tick
      ? '<button type="button" class="tdp-check" data-id="' + esc(it.id) + '" data-kind="tick" aria-label="' + esc((it.tick.label || 'Done') + ': ' + it.title) + '">' + icon('check', 'tdp-check-i') + '</button>'
      : '<span class="tdp-kind tdp-kind--' + esc(it.kind || 'task') + '">' + icon(it.kind || 'task') + '</span>';
    var title = it.href ? '<a href="' + esc(it.href) + '">' + esc(it.title) + '</a>' : esc(it.title);
    return '<li class="tdp-item' + (it.urgent ? ' is-urgent' : '') + '" data-id="' + esc(it.id) + '">' + lead
      + '<div class="tdp-txt"><span class="tdp-title">' + title + '</span>'
      + '<span class="tdp-meta">' + badge(it.badge) + (it.goal ? '<span class="tdp-goal">' + icon('goal', 'tdp-goal-i') + '<span class="tdp-sr">Goal: </span>' + esc(it.goal) + '</span>' : '') + (it.urgent && !(it.badge && it.badge.tone === 'red') ? '<span class="tdp-sr">Urgent. </span>' : '') + (it.meta ? '<span>' + esc(it.meta) + '</span>' : '') + '</span>'
      + (typeof it.progress === 'number' ? '<span class="tdp-bar" role="img" aria-label="' + Math.round(it.progress * 100) + '% done"><span style="width:' + Math.round(it.progress * 100) + '%"></span></span>' : '')
      + (btns ? '<span class="tdp-acts">' + btns + '</span>' : '')
      + '</div></li>';
  }

  function tabs() {
    var t = [['now', 'Now', count()]];
    var goals = inTab('goals'), you = inTab('you');
    if (goals.length) t.push(['goals', 'Goals', goals.length]);
    if (you.length) t.push(['you', 'For you', you.reduce(function (n, s) { return n + s.items.length; }, 0)]);
    if (t.length === 1) { tab = 'now'; return ''; }
    if (!t.some(function (x) { return x[0] === tab; })) tab = 'now';
    return '<div class="tdp-tabs" role="group" aria-label="Show">' + t.map(function (x) {
      return '<button type="button" class="tdp-tab" data-kind="tab" data-tab="' + x[0] + '" aria-pressed="' + (tab === x[0]) + '">' + esc(x[1]) + ' <span class="tdp-tab-n">' + x[2] + '</span></button>';
    }).join('') + '</div>';
  }

  function draw() {
    var n = count(), u = urgentCount(), done = doneToday(0);
    launcher.innerHTML = icon('list', 'tdp-launch-i') + '<span class="tdp-launch-t">To do</span>'
      + (n ? '<span class="tdp-n">' + n + '</span>' : '') + (u ? '<span class="tdp-dot" aria-hidden="true"></span>' : '')
      + '<span class="tdp-sr">: ' + n + ' item' + (n === 1 ? '' : 's') + (u ? ', ' + u + ' urgent' : '') + ' (Alt+Shift+T)</span>';
    launcher.hidden = open || !(data.sections || []).length;
    launcher.classList.toggle('has-urgent', u > 0);

    var role = data.role && data.role.parts ? data.role.parts : (data.role && data.role.label ? [data.role.label] : []);
    var initial = data.role && data.role.first ? data.role.first.charAt(0).toUpperCase() : '';
    var summary = n === 0 ? 'All clear for now' : n + ' to do' + (u ? ' · <b>' + u + ' urgent</b>' : '');
    var secs = ordered();
    var empty = tab === 'now'
      ? '<div class="tdp-empty">' + icon('check', 'tdp-empty-i') + '<p class="tdp-empty-t">You’re all caught up</p><p class="tdp-empty-s">' + (has('suggested') ? 'Want something to do? Look under For you.' : 'New work will show up here the moment it lands.') + '</p></div>'
      : tab === 'goals'
        ? '<div class="tdp-empty">' + icon('goal', 'tdp-empty-i') + '<p class="tdp-empty-t">No goal steps for you yet</p><p class="tdp-empty-s">When a goal is set, its steps are drawn up and given to whoever fits them.</p></div>'
        : '<div class="tdp-empty"><p class="tdp-empty-s">Nothing here.</p></div>';
    panel.innerHTML =
      '<div class="tdp-grab" aria-hidden="true"></div>'
      + '<header class="tdp-head">'
      +   (initial ? '<span class="tdp-av" aria-hidden="true">' + esc(initial) + '</span>' : '<span class="tdp-av" aria-hidden="true">' + icon('list') + '</span>')
      +   '<div class="tdp-hi"><h2 id="tdpH" tabindex="-1">' + esc(greeting()) + '</h2>'
      +   (role.length ? '<p class="tdp-role">' + role.map(function (r) { return '<span class="tdp-chip">' + esc(r) + '</span>'; }).join('') + '</p>' : '')
      +   '</div>'
      +   '<button type="button" class="tdp-x" data-kind="close" aria-label="Close the to-do list">' + icon('close') + '</button>'
      + '</header>'
      + '<div class="tdp-sum"><span class="tdp-ringw">' + ring(done, n) + '</span>'
      +   '<p class="tdp-sum-t"><span class="tdp-sum-big">' + summary + '</span><span class="tdp-sum-s">' + esc(new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }))
      +   (done ? ' · ' + done + ' done today' : '') + '</span></p></div>'
      + tabs()
      + '<div class="tdp-body">' + (secs.length ? secs.map(function (s) {
          var sid = esc(s.id || s.key);
          if (s.group === 'goals') {
            var pct = Math.round((s.progress || 0) * 100);
            return '<section class="tdp-sec tdp-sec--goal" aria-labelledby="tdpS' + sid + '"><div class="tdp-goalhead">' + icon('goal', 'tdp-goalhead-i')
              + '<div class="tdp-goalhead-t"><h3 id="tdpS' + sid + '">' + esc(s.title) + '</h3>'
              + (s.sub ? '<span class="tdp-goalhead-s">' + esc(s.sub) + '</span>' : '')
              + (typeof s.progress === 'number' ? '<span class="tdp-bar" role="img" aria-label="Goal ' + pct + '% done"><span style="width:' + pct + '%"></span></span>' : '')
              + '</div><span class="tdp-goalhead-p" aria-hidden="true">' + (typeof s.progress === 'number' ? pct + '%' : '') + '</span></div>'
              + '<ul>' + s.items.map(item).join('') + '</ul></section>';
          }
          return '<section class="tdp-sec tdp-sec--' + esc(s.key) + '" aria-labelledby="tdpS' + sid + '"><h3 id="tdpS' + sid + '">' + esc(s.title) + ' <span class="tdp-c">' + s.items.length + '</span></h3><ul>' + s.items.map(item).join('') + '</ul></section>';
        }).join('') : empty) + '</div>'
      + '<div class="tdp-toast" hidden></div>'
      + '<footer class="tdp-foot"><a href="' + esc(data.all || '#') + '" class="tdp-all">All tasks' + icon('arrow', 'tdp-all-i') + '</a>'
      +   '<div class="tdp-snooze"><button type="button" class="tdp-btn tdp-btn--ghost" data-kind="snooze" aria-expanded="false" aria-controls="tdpSnooze">' + icon('moon', 'tdp-btn-i') + 'Snooze</button>'
      +   '<div class="tdp-snooze-menu" id="tdpSnooze" hidden>'
      +     '<button type="button" data-kind="later" data-h="1">For an hour</button><button type="button" data-kind="later" data-h="3">For 3 hours</button><button type="button" data-kind="later" data-h="tomorrow">Until tomorrow</button>'
      +   '</div></div></footer>';
    if (lastDone) toast(lastDone.it.title, true);
  }

  function toast(title, keep) {
    var t = panel.querySelector('.tdp-toast'); if (!t) return;
    t.innerHTML = '<span>' + icon('check', 'tdp-toast-i') + 'Done: ' + esc(title) + '</span>' + (lastDone && lastDone.undo ? '<button type="button" data-kind="undo">Undo</button>' : '');
    t.hidden = false;
    if (!keep) { clearTimeout(undoTimer); undoTimer = setTimeout(function () { lastDone = null; t.hidden = true; }, 6000); }
  }

  /* ── Opening and closing ───────────────────────────────────────────── */
  function show(byHand) {
    open = true; draw(); panel.hidden = false; launcher.hidden = true;
    store('day', today()); var nw = newest(); if (nw) store('seen', nw);
    if (byHand) { var h = panel.querySelector('#tdpH'); if (h) h.focus(); }
    else { var n = count(); say('To do: ' + n + ' thing' + (n === 1 ? '' : 's') + ' waiting. Press Alt+Shift+T to open or close the list.'); }
  }
  function hide(byHand) {
    open = false; panel.hidden = true; launcher.hidden = !(data.sections || []).length;
    if (byHand && !launcher.hidden) launcher.focus();
  }

  function find(id) {
    var hit = null;
    (data.sections || []).forEach(function (s) { s.items.forEach(function (it, i) { if (it.id === id) hit = { s: s, it: it, i: i }; }); });
    return hit;
  }
  function post(spec) {
    return fetch((spec.url || src) + '?action=' + encodeURIComponent(spec.action), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(spec.body || {})
    }).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'The network did not answer.' }; });
  }
  function focusAfter(sec, idx) {
    /* Somewhere sensible for the keyboard: the next row, else the heading. */
    var rows = panel.querySelectorAll('.tdp-item button, .tdp-item a');
    var target = rows[Math.min(idx, rows.length - 1)] || panel.querySelector('#tdpH');
    if (target) target.focus();
  }
  function act(e) {
    var b = e.target.closest('button[data-kind]'); if (!b) return;
    var kind = b.getAttribute('data-kind');
    if (kind === 'close') { hide(true); return; }
    if (kind === 'tab') { tab = b.getAttribute('data-tab'); draw(); var nb = panel.querySelector('[data-tab="' + tab + '"]'); if (nb) nb.focus(); return; }
    if (kind === 'snooze') {
      var m = panel.querySelector('#tdpSnooze'); m.hidden = !m.hidden; b.setAttribute('aria-expanded', String(!m.hidden));
      if (!m.hidden) { var f = m.querySelector('button'); if (f) f.focus(); }
      return;
    }
    if (kind === 'later') {
      var h = b.getAttribute('data-h'), until;
      if (h === 'tomorrow') { var d = new Date(); d.setDate(d.getDate() + 1); d.setHours(7, 0, 0, 0); until = d.getTime(); }
      else until = Date.now() + (+h || 3) * 3600 * 1000;
      store('snooze', until); hide(true);
      say(h === 'tomorrow' ? 'Snoozed until tomorrow morning.' : 'Snoozed for ' + (+h === 1 ? 'an hour' : h + ' hours') + '.');
      return;
    }
    if (kind === 'undo') {
      if (!lastDone) return;
      var ld = lastDone; lastDone = null; clearTimeout(undoTimer);
      post(ld.spec).then(function (r) {
        if (!r || !r.ok) { say((r && r.error) || 'That could not be undone.'); return; }
        var sec = (data.sections || []).filter(function (s) { return (s.id || s.key) === (ld.s.id || ld.s.key); })[0];
        if (!sec) { sec = ld.s; sec.items = []; data.sections.push(sec); }
        sec.items.splice(Math.min(ld.i, sec.items.length), 0, ld.it);
        doneToday(-1); draw(); say('Put back: ' + ld.it.title + '.');
        var row = panel.querySelector('.tdp-item[data-id="' + ld.it.id + '"] button'); if (row) row.focus();
      });
      return;
    }
    var hit = find(b.getAttribute('data-id')); if (!hit) return;
    var spec = kind === 'take' ? hit.it.take : kind === 'act' ? (hit.it.acts || [])[+b.getAttribute('data-i')] : hit.it.tick;
    if (!spec) return;
    var row = b.closest('.tdp-item');
    b.disabled = true;
    if (kind === 'tick' && row) row.classList.add('is-done');
    var rows = Array.prototype.slice.call(panel.querySelectorAll('.tdp-item')), at = rows.indexOf(row);
    post(spec).then(function (r) {
      if (!r || !r.ok) {
        b.disabled = false; if (row) row.classList.remove('is-done');
        say((r && r.error) || 'That did not work.'); return;
      }
      var finish = function () {
        hit.s.items = hit.s.items.filter(function (x) { return x !== hit.it; });
        /* Taken: it is theirs now, so it moves to their list instead of vanishing. */
        if (kind === 'take' && r.task) {
          var on = (data.sections || []).filter(function (s) { return s.key === 'open'; })[0];
          if (!on) { on = { key: 'open', ctx: 'work', nudge: false, title: 'On your list', items: [] }; data.sections.push(on); }
          on.items.unshift({ id: 'av' + r.task.id, title: r.task.title, kind: 'task', meta: r.task.due ? 'Due ' + r.task.due : 'No date', badge: { text: 'Taken', tone: 'accent' },
            tick: { action: 'task_toggle', body: { id: r.task.id }, label: 'Done', undo: true } });
        }
        data.sections = data.sections.filter(function (s) { return s.items.length; });
        if (kind === 'tick') { doneToday(1); lastDone = { it: hit.it, s: hit.s, i: hit.i, spec: spec, undo: !!spec.undo }; }
        draw(); panel.hidden = false;
        if (kind === 'tick') toast(hit.it.title, false);
        say(kind === 'take' ? 'Taken: ' + hit.it.title + '. It is on your list.' : kind === 'act' ? spec.label + ': ' + hit.it.title + '.' : 'Done: ' + hit.it.title + '.' + (spec.undo ? ' Undo is in the list for a few seconds.' : ''));
        focusAfter(hit.s, Math.max(0, at));
        try { document.dispatchEvent(new CustomEvent('av:tasks-changed')); } catch (x) {}
      };
      /* Let the tick be seen before the row goes; at once for reduced motion. */
      var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (kind === 'tick' && !calm) setTimeout(finish, 380); else finish();
    });
  }

  /* ── Loading ───────────────────────────────────────────────────────── */
  function load() {
    fetch(src + '?action=todo', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || !d.ok || d.enabled === false) return;
      data = d; if (!panel) build(); draw();
      if (open) { panel.hidden = false; return; }
      if (!(d.sections || []).length) return;
      var snoozed = +(store('snooze') || 0) > Date.now();
      var fresh = newest() !== '' && newest() > String(store('seen') || '');
      var due = (d.sections || []).some(function (s) { return s.nudge === undefined ? (s.key === 'due' || s.key === 'cac' || s.key === 'assigned') : !!s.nudge; });
      if (fresh) launcher.classList.add('is-new');
      if (!snoozed && (fresh || (due && store('day') !== today()))) show(false);
    }).catch(function () {});
  }

  /* After the page: a slow other site never holds up this one. */
  var go = function () { load(); };
  if ('requestIdleCallback' in window) window.requestIdleCallback(go, { timeout: 2500 }); else setTimeout(go, 800);
  document.addEventListener('av:tasks-changed', function () { if (panel && !open) setTimeout(load, 400); });
  /* Moving to another screen: the section that matches it moves up. */
  window.addEventListener('hashchange', function () { if (data && panel) { draw(); panel.hidden = !open; } });
  /* On a phone a link inside the sheet goes to its screen; the sheet gets out of the way. */
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('.tdp-title a');
    if (a && window.innerWidth < 600 && (a.getAttribute('href') || '').charAt(0) === '#') hide(false);
  });
})();
