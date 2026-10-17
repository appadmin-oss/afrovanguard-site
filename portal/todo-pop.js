/* portal/todo-pop.js — the to-do pop-up.
 *
 * One script for both sites: the Afrovanguard portal (and its Volunteer
 * dashboard) and CACENTRE's consoles carry a copy. The server sends sections
 * of items, and each item carries the action its button posts, so this file
 * knows nothing about either site:
 *
 *   <div class="tdp" id="todoPop" data-src="/portal/collab.php" data-csrf="…"
 *        data-key="av" hidden></div>
 *   GET  {src}?action=todo  → {ok, enabled, sections:[{key,title,items:[…]}], all}
 *   POST {src}?action={item.tick|take.action}  body: item.tick|take.body
 *   item.acts: [{action, label, body, url?}] — more buttons; `url` posts to
 *   another endpoint of the same site (an RSVP, a commitment done).
 *   section.ctx ('work'|'member'|'volunteer') puts the section that matches
 *   the screen somebody is on first; section.nudge says whether it is worth
 *   opening the pop-up for; data.role.label says who the list is for.
 *
 * When it opens by itself: something was assigned since the last look, or
 * something is due and it has not been seen today. Otherwise a small "To do"
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

  function store(k, v) { try { if (v === undefined) return localStorage.getItem(key + k); localStorage.setItem(key + k, String(v)); } catch (e) { return null; } return null; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function today() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function say(t) { if (!live) return; live.textContent = ''; setTimeout(function () { live.textContent = t; }, 60); }

  /* What needs doing (the week's commitments are context, not a count). */
  function count() {
    var n = 0;
    (data.sections || []).forEach(function (s) { if (s.key !== 'week' && s.key !== 'suggested') n += s.items.length; });
    return n;
  }
  function newest() {
    var a = (data.sections || []).filter(function (s) { return s.key === 'assigned'; })[0];
    return a && a.items.length ? String(a.items[0].fresh || '') : '';
  }

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
    panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); hide(true); } });
    panel.addEventListener('click', act);
    document.addEventListener('keydown', function (e) {
      if (e.altKey && e.shiftKey && (e.key === 'T' || e.key === 't')) { e.preventDefault(); open ? hide(true) : show(true); }
    });
    root.hidden = false;
  }

  function item(it) {
    var btns = '';
    if (it.tick) btns += '<button type="button" class="tdp-tick" data-id="' + esc(it.id) + '" data-kind="tick">' + esc(it.tick.label || 'Done') + '<span class="tdp-sr">: ' + esc(it.title) + '</span></button>';
    if (it.take) btns += '<button type="button" class="tdp-take" data-id="' + esc(it.id) + '" data-kind="take">' + esc(it.take.label || 'Take it') + '<span class="tdp-sr">: ' + esc(it.title) + '</span></button>';
    (it.acts || []).forEach(function (a, i) {
      btns += '<button type="button" class="' + (i === 0 ? 'tdp-take' : 'tdp-tick') + '" data-id="' + esc(it.id) + '" data-kind="act" data-i="' + i + '">' + esc(a.label) + '<span class="tdp-sr">: ' + esc(it.title) + '</span></button>';
    });
    var title = it.href ? '<a href="' + esc(it.href) + '">' + esc(it.title) + '</a>' : esc(it.title);
    return '<li class="tdp-item' + (it.urgent ? ' is-urgent' : '') + '" data-id="' + esc(it.id) + '">'
      + '<div class="tdp-txt"><span class="tdp-title">' + title + '</span>'
      + (it.meta ? '<span class="tdp-meta">' + (it.urgent ? '<span class="tdp-sr">Urgent. </span>' : '') + esc(it.meta) + '</span>' : '')
      + (typeof it.progress === 'number' ? '<progress max="1" value="' + it.progress + '" aria-label="Progress: ' + Math.round(it.progress * 100) + '%"></progress>' : '')
      + '</div>' + (btns ? '<div class="tdp-acts">' + btns + '</div>' : '') + '</li>';
  }

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
    if (ctx === 'work') return list;
    return list.filter(function (s) { return s.ctx === ctx; }).concat(list.filter(function (s) { return s.ctx !== ctx; }));
  }
  function draw() {
    var n = count();
    launcher.innerHTML = '<span aria-hidden="true">✓</span> To do' + (n ? ' <span class="tdp-n">' + n + '</span>' : '') + '<span class="tdp-sr">: ' + n + ' item' + (n === 1 ? '' : 's') + ' (Alt+Shift+T)</span>';
    launcher.hidden = open || !(data.sections || []).length;
    var role = data.role && data.role.label ? data.role.label : '';
    panel.innerHTML = '<div class="tdp-head"><div><h2 id="tdpH" tabindex="-1">' + (data.role && data.role.first ? esc(data.role.first) + ', your to-do' : 'To do') + ' <span class="tdp-date">' + esc(new Date().toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })) + '</span></h2>'
      + (role ? '<p class="tdp-role">' + esc(role) + '</p>' : '') + '</div>'
      + '<button type="button" class="tdp-x" data-kind="close" aria-label="Close the to-do list">×</button></div>'
      + '<div class="tdp-body">' + ordered().map(function (s) {
          return '<section class="tdp-sec tdp-sec--' + esc(s.key) + '" aria-labelledby="tdpS' + esc(s.key) + '"><h3 id="tdpS' + esc(s.key) + '">' + esc(s.title) + ' <span class="tdp-c">' + s.items.length + '</span></h3><ul>' + s.items.map(item).join('') + '</ul></section>';
        }).join('') + ((data.sections || []).length ? '' : '<p class="tdp-empty">Nothing waiting. Well done.</p>') + '</div>'
      + '<div class="tdp-foot"><a href="' + esc(data.all || '#') + '" class="tdp-all">All tasks</a><button type="button" class="tdp-later" data-kind="later">Remind me later</button></div>';
  }

  function show(byHand) {
    open = true; panel.hidden = false; launcher.hidden = true;
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
    (data.sections || []).forEach(function (s) { s.items.forEach(function (it) { if (it.id === id) hit = { s: s, it: it }; }); });
    return hit;
  }
  function act(e) {
    var b = e.target.closest('button[data-kind]'); if (!b) return;
    var kind = b.getAttribute('data-kind');
    if (kind === 'close') { hide(true); return; }
    if (kind === 'later') { store('snooze', Date.now() + 3 * 3600 * 1000); hide(true); say('I will remind you in three hours.'); return; }
    var hit = find(b.getAttribute('data-id')); if (!hit) return;
    var spec = kind === 'take' ? hit.it.take : kind === 'act' ? (hit.it.acts || [])[+b.getAttribute('data-i')] : hit.it.tick;
    if (!spec) return;
    b.disabled = true;
    fetch((spec.url || src) + '?action=' + encodeURIComponent(spec.action), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(spec.body || {})
    }).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'The network did not answer.' }; }).then(function (r) {
      if (!r || !r.ok) { b.disabled = false; say((r && r.error) || 'That did not work.'); return; }
      hit.s.items = hit.s.items.filter(function (x) { return x !== hit.it; });
      data.sections = data.sections.filter(function (s) { return s.items.length; });
      draw(); panel.hidden = false;
      say(kind === 'take' ? 'Taken: ' + hit.it.title + '. It is on your list.' : kind === 'act' ? spec.label + ': ' + hit.it.title + '.' : 'Done: ' + hit.it.title + '.');
      var h = panel.querySelector('#tdpH'); if (h) h.focus();
      try { document.dispatchEvent(new CustomEvent('av:tasks-changed')); } catch (x) {}
    });
  }

  function load() {
    fetch(src + '?action=todo', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || !d.ok || d.enabled === false) return;
      data = d; if (!panel) build(); draw();
      if (!(d.sections || []).length) return;
      var snoozed = +(store('snooze') || 0) > Date.now();
      var fresh = newest() !== '' && newest() > String(store('seen') || '');
      var due = (d.sections || []).some(function (s) { return s.nudge === undefined ? (s.key === 'due' || s.key === 'cac' || s.key === 'assigned') : !!s.nudge; });
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
