/* ============================================================
   portal/notifications.js — the portal notifications bell.
   Polls the unread count, shows a dropdown inbox, marks read.
   ============================================================ */
(function () {
  'use strict';
  var wrap = document.getElementById('notifWrap');
  if (!wrap) return;
  var btn = document.getElementById('notifBtn'), badge = document.getElementById('notifBadge'),
      panel = document.getElementById('notifPanel'), list = document.getElementById('notifList'),
      readAll = document.getElementById('notifReadAll');
  var csrf = wrap.getAttribute('data-csrf') || '';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function get(a) { return fetch('/portal/notifications.php?action=' + a, { credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
  function post(a, b) {
    return fetch('/portal/notifications.php?action=' + a, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(b || {}) }).then(function (r) { return r.json(); });
  }
  function setBadge(n) {
    n = +n || 0;
    if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.hidden = false; }
    else { badge.hidden = true; }
  }
  var ICON = { reminder: '⏰', session: '◷', task: '✅', mention: '@', info: '•' };
  function itemHTML(it) {
    return '<a class="notif-item' + (it.read ? '' : ' is-unread') + '" href="' + esc(it.url || '#') + '" data-id="' + it.id + '">'
      + '<span class="notif-ic notif-ic--' + esc(it.kind) + '">' + (ICON[it.kind] || '•') + '</span>'
      + '<span class="notif-tx"><span class="notif-title">' + esc(it.title) + '</span>'
      + (it.body ? '<span class="notif-body">' + esc(it.body) + '</span>' : '')
      + '<span class="notif-ago">' + esc(it.ago) + '</span></span></a>';
  }
  function renderList(items) {
    list.innerHTML = items && items.length ? items.map(itemHTML).join('')
      : '<p class="notif-empty">You’re all caught up. 🎉</p>';
  }
  function refreshCount() { get('count').then(function (d) { if (d && d.ok) setBadge(d.unread); }).catch(function () {}); }
  function openPanel() {
    panel.hidden = false; btn.setAttribute('aria-expanded', 'true');
    list.innerHTML = '<p class="notif-empty">Loading…</p>';
    get('list').then(function (d) {
      if (!d || !d.ok) { list.innerHTML = '<p class="notif-empty">Could not load.</p>'; return; }
      renderList(d.items); setBadge(d.unread);
    }).catch(function () { list.innerHTML = '<p class="notif-empty">Could not load.</p>'; });
  }
  function closePanel() { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); }

  btn.addEventListener('click', function (e) { e.stopPropagation(); panel.hidden ? openPanel() : closePanel(); });
  document.addEventListener('click', function (e) { if (!panel.hidden && !wrap.contains(e.target)) closePanel(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) closePanel(); });

  list.addEventListener('click', function (e) {
    var a = e.target.closest('.notif-item'); if (!a) return;
    var id = +a.getAttribute('data-id');
    if (a.classList.contains('is-unread')) { post('read', { ids: [id] }).then(function (d) { if (d && d.ok) setBadge(d.unread); }); a.classList.remove('is-unread'); }
    // let the href navigate (in-portal hash links work; external open normally)
  });
  readAll.addEventListener('click', function () {
    post('read_all', {}).then(function () { setBadge(0); [].forEach.call(list.querySelectorAll('.notif-item'), function (a) { a.classList.remove('is-unread'); }); });
  });

  refreshCount();
  setInterval(refreshCount, 60000);
})();
