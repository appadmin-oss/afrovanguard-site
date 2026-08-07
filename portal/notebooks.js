/* ============================================================
   portal/notebooks.js — Notebooks, entry tabs and organisation.

   Three surfaces, one client, because they share one state object
   and splitting them would mean three fetches of the same list:

     · the NOTEBOOK RAIL   left of the diary — buckets, notebooks, tags
     · the TAB STRIP       inside an open entry (Google-Docs style)
     · the FILTER BAR      search + notebook/tag/kind filters + bulk move

   Everything talks to /portal/notebooks.php. Authorisation lives on the
   server (lib/DiaryNotebooks, lib/DiaryTabs, lib/DiaryOrganise); nothing
   here is a permission check — the disabled states below are courtesy,
   not security.
   ============================================================ */
(function () {
  'use strict';

  var rail = document.getElementById('nbRail');
  if (!rail) return;                              // diary view not on this page

  var API = '/portal/notebooks.php';
  // Writes go through av_require_write (same-origin + CSRF). The token is
  // stamped onto the rail element by the page; without it every write 403s with
  // "Bad token." — which is exactly the bug this reads it to fix.
  var CSRF = rail.getAttribute('data-csrf') || '';

  /* ── State ──────────────────────────────────────────────────────────── */
  var S = {
    notebooks: [],
    counts: {},
    tags: [],
    colours: [],
    filter: { bucket: 'all', notebook: null, tag: '', q: '', archived: false },
    entries: [],
    selected: {},                                 // entry id → true, for bulk move
    openEntry: 0,
    tabs: [],
    activeTab: 0
  };

  /* ── Plumbing ───────────────────────────────────────────────────────── */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function jget(action, params) {
    var q = new URLSearchParams(params || {});
    q.set('action', action);
    return fetch(API + '?' + q.toString(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'Network error.' }; });
  }
  function jpost(action, payload) {
    return fetch(API + '?action=' + action, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(payload || {})
    }).then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'Network error.' }; });
  }
  function say(msg, kind) {
    var el = document.getElementById('nbMsg');
    if (!el) return;
    el.textContent = msg || '';
    el.className = 'nb-msg' + (kind ? ' is-' + kind : '');
    if (msg) window.setTimeout(function () { if (el.textContent === msg) el.textContent = ''; }, 4000);
  }

  /* ── The rail ───────────────────────────────────────────────────────── */

  function renderRail() {
    var c = S.counts || {};
    var h = '';

    h += '<div class="nb-group">';
    h += '<p class="nb-grouptitle">Diary</p>';
    h += bucket('all', 'Everything', c.all || 0);
    h += bucket('pinned', 'Pinned', c.pinned || 0);
    h += bucket('unfiled', 'Not in a notebook', c.unfiled || 0);
    h += bucket('archived', 'Archived', c.archived || 0);
    h += '</div>';

    var mine = S.notebooks.filter(function (n) { return n.mine; });
    var theirs = S.notebooks.filter(function (n) { return !n.mine; });

    h += '<div class="nb-group">';
    h += '<p class="nb-grouptitle">Notebooks <button type="button" class="nb-add" id="nbNew" title="New notebook" aria-label="New notebook">+</button></p>';
    if (!mine.length) {
      h += '<p class="nb-empty">A notebook is a thread you keep pulling — “Mentoring”, a trip, a question you are working out. Entries can stay loose in the diary too.</p>';
    }
    mine.forEach(function (n) { h += nbRow(n); });
    h += '</div>';

    if (theirs.length) {
      h += '<div class="nb-group"><p class="nb-grouptitle">Shared with me</p>';
      theirs.forEach(function (n) { h += nbRow(n); });
      h += '</div>';
    }

    if (S.tags.length) {
      h += '<div class="nb-group"><p class="nb-grouptitle">Tags</p><div class="nb-tags">';
      S.tags.slice(0, 24).forEach(function (t) {
        h += '<button type="button" class="nb-tag' + (S.filter.tag === t.tag ? ' is-on' : '') +
             '" data-tag="' + esc(t.tag) + '">' + esc(t.tag) + '<span>' + t.n + '</span></button>';
      });
      h += '</div></div>';
    }

    rail.innerHTML = h;
  }

  function bucket(key, label, n) {
    var on = S.filter.bucket === key && S.filter.notebook === null;
    return '<button type="button" class="nb-item' + (on ? ' is-on' : '') + '" data-bucket="' + key + '">' +
           '<span class="nb-item__l">' + esc(label) + '</span>' +
           '<span class="nb-item__n">' + n + '</span></button>';
  }

  function nbRow(n) {
    var on = S.filter.notebook === n.id;
    return '<button type="button" class="nb-item nb-item--book' + (on ? ' is-on' : '') + '" data-nb="' + n.id + '">' +
           '<i class="nb-spine nb-spine--' + esc(n.colour) + '"></i>' +
           '<span class="nb-item__l">' + esc(n.name) +
             (n.mine ? '' : '<em class="nb-owner">' + esc(n.owner_name || '') + '</em>') +
           '</span>' +
           '<span class="nb-item__n">' + (n.entries || 0) + '</span></button>';
  }

  /* ── The entry list ─────────────────────────────────────────────────── */

  function renderList() {
    var list = document.getElementById('nbList');
    if (!list) return;
    if (!S.entries.length) {
      list.innerHTML = '<li class="pc-empty">Nothing here yet.</li>';
      renderBulk();
      return;
    }
    list.innerHTML = S.entries.map(function (e) {
      var tags = (e.tags || []).map(function (t) { return '<span class="nb-chip">' + esc(t) + '</span>'; }).join('');
      return '<li class="nb-entry' + (e.pinned ? ' is-pinned' : '') + '" data-id="' + e.id + '">' +
        '<label class="nb-pick"><input type="checkbox" class="nb-check" data-id="' + e.id + '"' +
          (S.selected[e.id] ? ' checked' : '') + ' aria-label="Select entry"></label>' +
        '<div class="nb-entry__main">' +
          '<div class="nb-entry__top">' +
            '<button type="button" class="nb-open" data-id="' + e.id + '">' +
              esc(e.title || '(untitled)') + '</button>' +
            (e.tab_count > 1 ? '<span class="nb-tabs-badge" title="' + e.tab_count + ' tabs">⧉ ' + e.tab_count + '</span>' : '') +
            (e.pinned ? '<span class="nb-pinned" title="Pinned">📌</span>' : '') +
          '</div>' +
          '<p class="nb-entry__ex">' + esc(e.excerpt || '') + '</p>' +
          '<div class="nb-entry__foot"><time>' + esc(e.entry_date) + '</time>' + tags + '</div>' +
        '</div>' +
        '<div class="nb-entry__acts">' +
          '<button type="button" class="nb-act" data-pin="' + e.id + '" title="' + (e.pinned ? 'Unpin' : 'Pin') + '">' + (e.pinned ? '📌' : '📍') + '</button>' +
          '<button type="button" class="nb-act" data-arch="' + e.id + '" title="' + (e.archived ? 'Restore' : 'Archive') + '">' + (e.archived ? '↩' : '🗄') + '</button>' +
        '</div>' +
      '</li>';
    }).join('');
    renderBulk();
  }

  function renderBulk() {
    var bar = document.getElementById('nbBulk');
    if (!bar) return;
    var ids = Object.keys(S.selected).filter(function (k) { return S.selected[k]; });
    if (!ids.length) { bar.hidden = true; return; }
    bar.hidden = false;
    var opts = '<option value="0">— the diary (no notebook) —</option>' +
      S.notebooks.filter(function (n) { return n.can_write; })
                 .map(function (n) { return '<option value="' + n.id + '">' + esc(n.name) + '</option>'; }).join('');
    bar.innerHTML = '<span>' + ids.length + ' selected</span>' +
      '<label class="nb-inline">Move to <select id="nbMoveTo">' + opts + '</select></label>' +
      '<button type="button" class="pbtn pbtn-soft pbtn-sm" id="nbMoveGo">Move</button>' +
      '<button type="button" class="pbtn pbtn-ghost pbtn-sm" id="nbClearSel">Clear</button>';
  }

  /* ── The tab strip ──────────────────────────────────────────────────── */

  function openEntry(id) {
    S.openEntry = id;
    jget('tabs', { entry: id }).then(function (r) {
      if (!r.ok) { say(r.error || 'Could not open that entry.', 'bad'); return; }
      S.tabs = r.tabs || [];
      S.activeTab = 0;
      renderTabs();
      var panel = document.getElementById('nbDoc');
      if (panel) { panel.hidden = false; panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
    });
  }

  function renderTabs() {
    var strip = document.getElementById('nbTabStrip');
    var body = document.getElementById('nbTabBody');
    var title = document.getElementById('nbTabTitle');
    if (!strip || !body) return;

    strip.innerHTML = S.tabs.map(function (t, i) {
      return '<button type="button" class="nb-tabbtn' + (i === S.activeTab ? ' is-on' : '') + '" data-i="' + i + '">' +
        esc(t.title) + (t.is_first ? '' : '<span class="nb-tabx" data-del="' + t.id + '" title="Delete tab">×</span>') +
      '</button>';
    }).join('') + '<button type="button" class="nb-tabadd" id="nbTabAdd" title="Add a tab">+</button>';

    var t = S.tabs[S.activeTab];
    if (!t) return;
    if (title) title.value = t.is_first && t.title === 'Entry' ? '' : t.title;
    body.value = stripHtml(t.body);
    var hint = document.getElementById('nbTabHint');
    if (hint) {
      hint.textContent = t.is_first
        ? 'This is the entry itself — the first tab is always the entry, and it cannot be deleted.'
        : 'A section of this entry. It is searchable and travels with the entry when you file or share it.';
    }
  }

  /** Tab bodies round-trip as plain text here; the rich composer owns HTML. */
  function stripHtml(s) {
    var d = document.createElement('div');
    d.innerHTML = String(s == null ? '' : s);
    return d.textContent || '';
  }

  function saveTab() {
    var t = S.tabs[S.activeTab];
    if (!t) return;
    var title = (document.getElementById('nbTabTitle') || {}).value || '';
    var body = (document.getElementById('nbTabBody') || {}).value || '';
    jpost('tab_save', { entry_id: S.openEntry, tab_id: t.id, title: title, body: body })
      .then(function (r) {
        if (!r.ok) { say(r.error || 'Could not save.', 'bad'); return; }
        t.title = title || (t.is_first ? 'Entry' : t.title);
        t.body = body;
        renderTabs();
        say('Saved.', 'good');
        load();
      });
  }

  /* ── Loading ────────────────────────────────────────────────────────── */

  function load() {
    return jget('list', S.filter.archived ? { archived: 1 } : {}).then(function (r) {
      if (!r.ok) return;
      S.notebooks = r.notebooks || [];
      S.counts = r.counts || {};
      S.tags = r.tags || [];
      S.colours = r.colours || [];
      renderRail();
      return search();
    });
  }

  function search() {
    var p = {};
    if (S.filter.q) p.q = S.filter.q;
    if (S.filter.tag) p.tag = S.filter.tag;
    if (S.filter.notebook !== null) p.notebook = S.filter.notebook;
    if (S.filter.bucket === 'unfiled') p.notebook = 0;
    if (S.filter.bucket === 'archived') p.archived = 1;
    return jget('search', p).then(function (r) {
      if (!r.ok) return;
      var rows = r.entries || [];
      if (S.filter.bucket === 'pinned') rows = rows.filter(function (e) { return e.pinned; });
      S.entries = rows;
      renderList();
    });
  }

  /* ── Events ─────────────────────────────────────────────────────────── */

  rail.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-bucket]');
    if (b) { S.filter.bucket = b.getAttribute('data-bucket'); S.filter.notebook = null;
             S.filter.archived = S.filter.bucket === 'archived'; load(); return; }

    var n = ev.target.closest('[data-nb]');
    if (n) { S.filter.notebook = parseInt(n.getAttribute('data-nb'), 10); S.filter.bucket = '';
             S.filter.archived = false; load(); return; }

    var t = ev.target.closest('[data-tag]');
    if (t) { var tag = t.getAttribute('data-tag');
             S.filter.tag = S.filter.tag === tag ? '' : tag; load(); return; }

    if (ev.target.id === 'nbNew') {
      var name = window.prompt('Name this notebook — what is it for?');
      if (!name) return;
      jpost('create', { name: name }).then(function (r) {
        if (!r.ok) { say(r.error || 'Could not create it.', 'bad'); return; }
        say('Notebook created.', 'good'); load();
      });
    }
  });

  var listEl = document.getElementById('nbList');
  if (listEl) listEl.addEventListener('click', function (ev) {
    var o = ev.target.closest('[data-id].nb-open');
    if (o) { openEntry(parseInt(o.getAttribute('data-id'), 10)); return; }

    var p = ev.target.closest('[data-pin]');
    if (p) {
      var id = parseInt(p.getAttribute('data-pin'), 10);
      var cur = (S.entries.find(function (e) { return e.id === id; }) || {}).pinned;
      jpost('pin', { entry_id: id, on: !cur }).then(function (r) {
        if (!r.ok) { say(r.error || 'Could not pin.', 'bad'); return; }
        load();
      });
      return;
    }
    var a = ev.target.closest('[data-arch]');
    if (a) {
      var aid = parseInt(a.getAttribute('data-arch'), 10);
      var was = (S.entries.find(function (e) { return e.id === aid; }) || {}).archived;
      jpost('archive', { entry_id: aid, on: !was }).then(function () { load(); });
    }
  });

  if (listEl) listEl.addEventListener('change', function (ev) {
    var c = ev.target.closest('.nb-check');
    if (!c) return;
    S.selected[c.getAttribute('data-id')] = c.checked;
    renderBulk();
  });

  var bulk = document.getElementById('nbBulk');
  if (bulk) bulk.addEventListener('click', function (ev) {
    if (ev.target.id === 'nbClearSel') { S.selected = {}; renderList(); return; }
    if (ev.target.id === 'nbMoveGo') {
      var to = parseInt((document.getElementById('nbMoveTo') || {}).value || '0', 10);
      var ids = Object.keys(S.selected).filter(function (k) { return S.selected[k]; }).map(Number);
      jpost('move', { entry_ids: ids, notebook_id: to }).then(function (r) {
        say((r.moved || 0) + ' moved.', 'good');
        S.selected = {}; load();
      });
    }
  });

  var q = document.getElementById('nbSearch');
  if (q) {
    var timer = null;
    q.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { S.filter.q = q.value.trim(); search(); }, 220);
    });
  }

  var strip = document.getElementById('nbTabStrip');
  if (strip) strip.addEventListener('click', function (ev) {
    var x = ev.target.closest('[data-del]');
    if (x) {
      ev.stopPropagation();
      var tid = parseInt(x.getAttribute('data-del'), 10);
      if (!window.confirm('Delete this tab? Its text goes with it.')) return;
      jpost('tab_delete', { entry_id: S.openEntry, tab_id: tid }).then(function (r) {
        if (!r.ok) { say('Could not delete that tab.', 'bad'); return; }
        S.activeTab = 0; openEntry(S.openEntry);
      });
      return;
    }
    if (ev.target.id === 'nbTabAdd') {
      var name = window.prompt('Name the tab (e.g. Tuesday, Decisions, Next steps)');
      if (name === null) return;
      jpost('tab_add', { entry_id: S.openEntry, title: name }).then(function (r) {
        if (!r.ok) { say(r.error || 'Could not add a tab.', 'bad'); return; }
        openEntry(S.openEntry);
      });
      return;
    }
    var b = ev.target.closest('[data-i]');
    if (b) { S.activeTab = parseInt(b.getAttribute('data-i'), 10); renderTabs(); }
  });

  var saveBtn = document.getElementById('nbTabSave');
  if (saveBtn) saveBtn.addEventListener('click', saveTab);

  var closeBtn = document.getElementById('nbDocClose');
  if (closeBtn) closeBtn.addEventListener('click', function () {
    var panel = document.getElementById('nbDoc');
    if (panel) panel.hidden = true;
    S.openEntry = 0;
  });

  load();
})();
