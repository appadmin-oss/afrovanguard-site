/* portal/avdy.js — My Diary in the member portal (design "Afrovanguard Portal v3", Diary).
 *
 * A notes app over the diary backend:
 *   /portal/notebooks.php  notebooks, sharing, tabs, tags, pin, archive, search, move
 *   /diary/api.php         the entries (create, update, delete, share link, people)
 *
 * The editor saves as you write (600 ms after you stop). A new entry exists
 * only in the page until it has words — the server refuses an empty one — and
 * is then created where you started it (the open notebook, if you may write
 * there). Public entries and events start as drafts and go to review when you
 * press Submit for review; Withdraw brings one back.
 *
 * Read-only cases: an entry in a notebook someone shared with you that you did
 * not write, and an entry someone shared with you directly. Both say why.
 *
 * Every write shows its outcome. A refused or failed save keeps the words on
 * the page and says so in the save slot; nothing is ever silently dropped.
 */
(function () {
  'use strict';
  var root = document.getElementById('avdy');
  if (!root) return;

  var CSRF = root.getAttribute('data-csrf') || '';
  var TODAY = root.getAttribute('data-today') || new Date().toISOString().slice(0, 10);
  var ME = root.getAttribute('data-me') || '';
  var MY_EMAIL = root.getAttribute('data-email') || '';
  var NB = '/portal/notebooks.php', DY = '/diary/api.php';
  var MAX_TAGS = 12, MAX_TABS = 20;

  /* ── Icons (one stroke set, 24-grid) ───────────────────────────────── */
  var IC = {
    lock: 'M6 11h12v10H6zM8 11V7a4 4 0 0 1 8 0v4',
    globe: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9M12 3C9.5 5.7 8.2 8.7 8.2 12s1.3 6.3 3.8 9',
    cal: 'M4 5h16v16H4zM16 3v4M8 3v4M4 10h16',
    pin: 'M9 4h6l-1 5 3 3v2H7v-2l3-3zM12 14v6',
    all: 'M4 6h16M4 12h16M4 18h10',
    inbox: 'M3 13h5l2 3h4l2-3h5M5 5h14l2 8v6H3v-6z',
    archive: 'M3 4h18v4H3zM5 8v12h14V8M10 12h4',
    book: 'M4 4h12a3 3 0 0 1 3 3v13H7a3 3 0 0 1-3-3zM4 17a3 3 0 0 1 3-3h12',
    down: 'M12 4v11M7 10l5 5 5-5M5 20h14',
    trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
    plus: 'M12 5v14M5 12h14',
    link: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7L12 5M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7L12 19',
    more: 'M5 12h.01M12 12h.01M19 12h.01',
    chev: 'M6 9l6 6 6-6',
    x: 'M6 6l12 12M18 6 6 18',
    people: 'M16 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM22 20v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    pen: 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
    mail: 'M4 6h16v12H4zM4 7l8 6 8-6'
  };
  function svg(d) { return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + d + '"/></svg>'; }

  var FONTS = [
    ['default', 'Clean & readable'], ['caveat', 'Caveat'], ['kalam', 'Kalam'],
    ['patrick', 'Patrick Hand'], ['dancing', 'Dancing Script'], ['special', 'Special Elite']
  ];
  var FONT_CSS = 'https://fonts.googleapis.com/css2?family=Caveat:wght@500;700&family=Dancing+Script:wght@500;700&family=Kalam:wght@400;700&family=Patrick+Hand&family=Special+Elite&display=swap';
  function h2(s) { return s.replace(/<div>([^<]+)<\/div>/g, '<h2>$1</h2>'); }
  var TEMPLATES = [
    ['How today went', 'Wins, struggles, one thing for tomorrow', h2('<div>What went well</div><ul><li><br></li></ul><div>What was hard</div><ul><li><br></li></ul><div>One thing for tomorrow</div><ul><li><br></li></ul>')],
    ['Field note', 'Context, observation, meaning, next step', h2('<div>Context</div><div><br></div><div>What I observed</div><ul><li><br></li></ul><div>What it means</div><ul><li><br></li></ul><div>Next step</div><ul><li><br></li></ul>')],
    ['Project log', 'Progress, blockers, next up', h2('<div>Progress this session</div><ul><li><br></li></ul><div>Blockers</div><ul><li><br></li></ul><div>Next up</div><ul><li><br></li></ul>')],
    ['Meeting notes', 'Attendees, agenda, decisions, actions', '<div>Attendees: </div>' + h2('<div>Agenda</div><ul><li><br></li></ul><div>Decisions</div><ul><li><br></li></ul><div>Action items</div><ul><li><br></li></ul>')],
    ['Gratitude', 'Three things, and why they mattered', h2('<div>Three things I’m grateful for today</div>') + '<ol><li><br></li><li><br></li><li><br></li></ol>' + h2('<div>Why it mattered</div>')],
    ['Week in review', 'Wins, lessons, focus next week', h2('<div>Wins</div><ul><li><br></li></ul><div>Lessons</div><ul><li><br></li></ul><div>Focus next week</div><ul><li><br></li></ul>')],
    ['The idea', 'Why it matters, how it works, what you’d need', '<div><br></div>' + h2('<div>Why it matters</div><ul><li><br></li></ul><div>How it could work</div><ul><li><br></li></ul><div>What I’d need</div><ul><li><br></li></ul>')]
  ];
  var TOOLS = [
    ['B', 'Bold', 'bold', null, 'is-b'], ['I', 'Italic', 'italic', null, 'is-i'], ['U', 'Underline', 'underline', null, 'is-u'],
    ['H', 'Heading', 'formatBlock', 'h2', 'is-b'], ['•', 'Bulleted list', 'insertUnorderedList', null, 'is-b'],
    ['1.', 'Numbered list', 'insertOrderedList', null, ''], ['❝', 'Quote', 'formatBlock', 'blockquote', ''], ['¶', 'Normal text', 'formatBlock', 'div', '']
  ];
  var KIND = { private: ['Private', IC.lock], public: ['Public', IC.globe], event: ['Event', IC.cal] };

  /* ── State ─────────────────────────────────────────────────────────── */
  var S = {
    nbs: [], counts: {}, tags: [], scope: 'all', kind: 'all', q: '',
    list: [], loading: true, error: '', shared: [],
    entry: null, tabs: [], ti: 0, readOnly: false, roNote: '',
    menu: null, menuFrom: null, delArm: false, saving: false, saveErr: '',
    libOpen: false, mobileEdit: false, people: null, shareUrl: '', copied: ''
  };
  var pending = {};            // field → true, waiting for the debounce
  var saveT = null;

  /* ── Plumbing ──────────────────────────────────────────────────────── */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function json(r) {
    return r.text().then(function (t) {
      try { return JSON.parse(t); } catch (e) { return { ok: false, error: 'The server answered HTTP ' + r.status + '. Try again in a moment.' }; }
    });
  }
  function net() { return { ok: false, error: 'The connection dropped. Your words are still here — try again.' }; }
  function nbGet(action, params) {
    var q = new URLSearchParams(params || {}); q.set('action', action);
    return fetch(NB + '?' + q.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(json).catch(net);
  }
  function nbPost(action, payload) {
    return fetch(NB + '?action=' + action, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(payload || {}) }).then(json).catch(net);
  }
  function dyGet(action, params) {
    var q = new URLSearchParams(params || {}); q.set('action', action);
    return fetch(DY + '?' + q.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(json).catch(net);
  }
  function dyPost(action, payload) {
    return fetch(DY + '?action=' + action, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(payload || {}) }).then(json).catch(net);
  }
  function q(sel, el) { return (el || root).querySelector(sel); }
  function qa(sel, el) { return [].slice.call((el || root).querySelectorAll(sel)); }
  function text(html) { var d = document.createElement('div'); d.innerHTML = html || ''; return (d.textContent || '').replace(/\s+/g, ' ').trim(); }
  function words(html) { var t = text(html); return t ? t.split(' ').length : 0; }
  function fmtShort(iso) { var d = new Date(String(iso).slice(0, 10) + 'T00:00:00'); return isNaN(d) ? '' : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }); }
  function fmtLong(iso) { var d = new Date(String(iso).slice(0, 10) + 'T00:00:00'); return isNaN(d) ? iso : d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }); }
  function normTag(t) { return String(t || '').toLowerCase().trim().replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '').slice(0, 40); }
  function nbOf(id) { id = +id || 0; for (var i = 0; i < S.nbs.length; i++) if (S.nbs[i].id === id) return S.nbs[i]; return null; }
  function curNb() { return S.scope.indexOf('nb:') === 0 ? nbOf(S.scope.slice(3)) : null; }
  function first(name) { return String(name || '').split(' ')[0]; }
  function ini(name) { return String(name || '?').split(/\s+/).filter(Boolean).map(function (x) { return x[0]; }).join('').slice(0, 2).toUpperCase(); }
  function wide(n) { return root.getBoundingClientRect().width >= n; }

  /* The status an entry shows: [label, tone]. Private entries show none. */
  function stInfo(e) {
    if (!e || e.kind === 'private') return ['Private', 'gray'];
    return { draft: ['Draft', 'gray'], pending: ['In review', 'gold'], approved: ['Published', 'green'], rejected: ['Not approved', 'red'] }[e.status] || ['Draft', 'gray'];
  }

  function loadFonts() {
    if (document.getElementById('avdy-fonts')) return;
    var l = document.createElement('link'); l.id = 'avdy-fonts'; l.rel = 'stylesheet'; l.href = FONT_CSS; document.head.appendChild(l);
  }

  /* ── Loading ───────────────────────────────────────────────────────── */
  function loadNbs() {
    return nbGet('list').then(function (d) {
      if (!d.ok) { S.error = d.error || 'Your notebooks could not load.'; return; }
      S.nbs = d.notebooks || []; S.counts = d.counts || {}; S.tags = d.tags || [];
      renderLib(); renderHead();
    });
  }
  function loadShared() {
    return dyGet('mine.shared').then(function (d) { S.shared = d.ok ? (d.entries || []) : []; renderLib(); });
  }
  var listSeq = 0;
  function loadList(keepSel) {
    var my = ++listSeq, sc = S.scope, p;
    S.loading = true; S.error = ''; renderList();
    if (sc === 'shared') {
      p = Promise.resolve({ ok: true, entries: S.shared.map(function (e) {
        return { id: e.id, kind: e.kind, title: e.title, excerpt: e.excerpt, entry_date: e.entry_date, status: 'approved', tags: [], tab_count: 1, mine: false, author: e.author, direct: true };
      }) });
    } else if (sc.indexOf('nb:') === 0 && !(nbOf(sc.slice(3)) || {}).mine) {
      p = nbGet('entries', { id: sc.slice(3) });
    } else {
      var f = {};
      if (S.q) f.q = S.q;
      if (S.kind !== 'all') f.kind = S.kind;
      if (sc === 'unfiled') f.notebook = '0';
      if (sc === 'archive') f.archived = '1';
      if (sc.indexOf('nb:') === 0) f.notebook = sc.slice(3);
      if (sc.indexOf('tag:') === 0) f.tag = sc.slice(4);
      p = nbGet('search', f);
    }
    return p.then(function (d) {
      if (my !== listSeq) return;
      S.loading = false;
      if (!d.ok) { S.error = d.error || 'Your entries could not load.'; S.list = []; renderList(); return; }
      var rows = (d.entries || []).map(function (e) { if (e.mine === undefined) e.mine = true; return e; });
      if (sc === 'pinned') rows = rows.filter(function (e) { return e.pinned; });
      // Client-side filters for the lists the server does not filter.
      if (sc === 'shared' || (sc.indexOf('nb:') === 0 && !(nbOf(sc.slice(3)) || {}).mine)) {
        if (S.kind !== 'all') rows = rows.filter(function (e) { return e.kind === S.kind; });
        if (S.q) { var ql = S.q.toLowerCase(); rows = rows.filter(function (e) { return (e.title + ' ' + (e.excerpt || '') + ' ' + (e.tags || []).join(' ')).toLowerCase().indexOf(ql) !== -1; }); }
      }
      // A new, unsaved entry stays at the top of whatever list it was started in.
      if (S.entry && !S.entry.id) rows.unshift(S.entry);
      S.list = rows;
      renderList();
      if (keepSel && S.entry) {
        var still = rows.filter(function (e) { return e.id === S.entry.id; })[0];
        if (still) { if (S.entry.id) mergeRow(still); paintBar(); return; }
      }
      if (!S.entry || !keepSel) {
        if (rows.length && wide(712) && !S.mobileEdit) select(rows[0].id); else if (!rows.length) { S.entry = null; renderEditor(); }
      }
    });
  }
  function mergeRow(row) { ['status', 'pinned', 'notebook_id', 'tags', 'tab_count', 'review_note', 'kind'].forEach(function (k) { if (row[k] !== undefined) S.entry[k] = row[k]; }); }

  /* ── Library ───────────────────────────────────────────────────────── */
  function renderLib() {
    var c = S.counts || {};
    var buckets = [['all', 'All entries', IC.all, c.all], ['pinned', 'Pinned', IC.pin, c.pinned], ['unfiled', 'Unfiled', IC.inbox, c.unfiled], ['archive', 'Archive', IC.archive, c.archived]];
    q('[data-dy-buckets]').innerHTML = buckets.map(function (b) {
      return '<button type="button" class="avdy-li" data-dy-scope="' + b[0] + '"' + (S.scope === b[0] ? ' aria-current="true"' : '') + '>' + svg(b[2]) + '<span class="avdy-li-t">' + b[1] + '</span><span class="avdy-li-n av-num">' + (b[3] == null ? '' : b[3]) + '</span></button>';
    }).join('');
    var mine = S.nbs.filter(function (n) { return n.mine && !n.archived; });
    q('[data-dy-nbs]').innerHTML = mine.length ? mine.map(function (n) {
      return '<button type="button" class="avdy-li" data-dy-scope="nb:' + n.id + '"' + (S.scope === 'nb:' + n.id ? ' aria-current="true"' : '') + '><span class="avdy-spine" data-c="' + esc(n.colour) + '"></span><span class="avdy-li-t">' + esc(n.name) + '</span>'
        + (n.shared_link || n.members_n ? '<span class="avdy-li-shared" title="Shared">' + svg(IC.people) + '</span>' : '') + '<span class="avdy-li-n av-num">' + (n.entries || 0) + '</span></button>';
    }).join('') : '<button type="button" class="avdy-startnb" data-dy-nbnew>Start a notebook</button>';
    var shared = S.nbs.filter(function (n) { return !n.mine; });
    var sh = shared.map(function (n) {
      return '<button type="button" class="avdy-li" data-dy-scope="nb:' + n.id + '" title="Kept by ' + esc(n.owner_name) + (n.role === 'contributor' ? ' · you can add entries' : ' · read only') + '"' + (S.scope === 'nb:' + n.id ? ' aria-current="true"' : '') + '><span class="avdy-spine" data-c="' + esc(n.colour) + '"></span><span class="avdy-li-t">' + esc(n.name) + '</span><span class="avdy-li-o">' + esc(first(n.owner_name)) + '</span></button>';
    }).join('');
    if (S.shared.length) sh += '<button type="button" class="avdy-li" data-dy-scope="shared"' + (S.scope === 'shared' ? ' aria-current="true"' : '') + '>' + svg(IC.mail) + '<span class="avdy-li-t">Entries shared with you</span><span class="avdy-li-n av-num">' + S.shared.length + '</span></button>';
    q('[data-dy-shared]').innerHTML = sh; q('[data-dy-sharedgrp]').hidden = !sh;
    var tg = (S.tags || []).slice(0, 8);
    q('[data-dy-tags]').innerHTML = tg.map(function (t) {
      return '<button type="button" class="avdy-li avdy-li--tag" data-dy-scope="tag:' + esc(t.tag) + '"' + (S.scope === 'tag:' + t.tag ? ' aria-current="true"' : '') + '><span class="avdy-hash" aria-hidden="true">#</span><span class="avdy-li-t">' + esc(t.tag) + '</span><span class="avdy-li-n av-num">' + t.n + '</span></button>';
    }).join('');
    q('[data-dy-tagsgrp]').hidden = !tg.length;
  }

  function renderHead() {
    var nb = curNb(), title, sub = '', acts = false;
    if (nb) {
      title = nb.name;
      if (nb.mine) { acts = true; sub = (nb.entries || 0) + ' entries · ' + (nb.shared_link ? 'Link on' : 'Only you, unless you share it'); if (nb.description) sub = nb.description + ' · ' + sub; }
      else sub = 'Kept by ' + nb.owner_name + ' · ' + (nb.role === 'contributor' ? 'you can add entries' : 'you can read');
    } else if (S.scope.indexOf('tag:') === 0) { title = '#' + S.scope.slice(4); sub = 'Tag · across every notebook'; }
    else title = { all: 'All entries', pinned: 'Pinned', unfiled: 'Unfiled', archive: 'Archive', shared: 'Shared with you' }[S.scope] || 'All entries';
    if (!nb && S.scope !== 'all' && S.scope.indexOf('tag:') !== 0) sub = { pinned: 'Held at the top · up to 8', unfiled: 'Not in a notebook — they live in the diary itself', archive: 'Out of the way, not deleted', shared: 'Entries members shared with you directly' }[S.scope] || '';
    q('[data-dy-htitle]').textContent = title;
    var sp = q('[data-dy-hspine]'); sp.hidden = !nb; if (nb) sp.setAttribute('data-c', nb.colour);
    var s = q('[data-dy-hsub]'); s.hidden = !sub; s.textContent = sub;
    q('[data-dy-hacts]').hidden = !acts;
    q('[data-dy-q]').placeholder = nb ? 'Search ' + nb.name : 'Search titles, tabs and tags';
  }

  /* ── List ──────────────────────────────────────────────────────────── */
  function itemHtml(e) {
    var st = stInfo(e), nb = nbOf(e.notebook_id), showNb = nb && !curNb(), showSt = e.kind !== 'private';
    var meta = '';
    if (e.pinned) meta += '<span class="avdy-m-pin" title="Pinned">' + svg(IC.pin) + '</span>';
    if (showSt) meta += '<span class="avdy-m-st" data-tone="' + st[1] + '"><span class="avdy-dot"></span>' + (e.kind === 'event' && e.status === 'approved' ? 'Event' : st[0]) + '</span>';
    if (showNb) meta += '<span class="avdy-m-nb"><span class="avdy-spine avdy-spine--xs" data-c="' + esc(nb.colour) + '"></span><span>' + esc(nb.name) + '</span></span>';
    if ((e.tab_count || 1) > 1) meta += '<span>' + e.tab_count + ' tabs</span>';
    if (e.author) meta += '<span>' + esc(first(e.author)) + '</span>';
    var on = S.entry && S.entry.id === e.id;
    return '<li><button type="button" class="avdy-item" data-dy-pick="' + e.id + '"' + (on ? ' aria-current="true"' : '') + '>'
      + '<span class="avdy-item-top"><span class="avdy-item-t">' + esc(e.title || 'Untitled') + '</span><span class="avdy-item-d">' + esc(fmtShort(e.entry_date)) + '</span></span>'
      + '<span class="avdy-item-s">' + esc(e.excerpt || text(e.body) || 'Empty entry') + '</span>'
      + (meta ? '<span class="avdy-item-m">' + meta + '</span>' : '') + '</button></li>';
  }
  function renderList() {
    var box = q('[data-dy-list]');
    box.setAttribute('aria-busy', S.loading ? 'true' : 'false');
    if (S.loading) { box.innerHTML = '<div class="avdy-skel" aria-hidden="true"><span></span><span></span><span></span><span></span></div>'; return; }
    if (S.error) {
      box.innerHTML = '<div class="avdy-empty" role="alert"><strong>Your entries could not load</strong><span>' + esc(S.error) + '</span><button type="button" class="avdy-btn" data-dy-retry>Try again</button></div>';
      return;
    }
    var rows = S.list, pins = rows.filter(function (e) { return e.pinned; }), rest = rows.filter(function (e) { return !e.pinned; });
    var html = '';
    if (pins.length && rest.length && S.scope !== 'pinned') {
      html = '<p class="avdy-grp-l">Pinned</p><ul class="avdy-items">' + pins.map(itemHtml).join('') + '</ul><p class="avdy-grp-l">Entries</p><ul class="avdy-items">' + rest.map(itemHtml).join('') + '</ul>';
    } else if (rows.length) html = '<ul class="avdy-items">' + rows.map(itemHtml).join('') + '</ul>';
    else {
      var nb = curNb(), m;
      if (S.q || S.kind !== 'all') m = ['No entries match', 'Try another word, or clear the filter.', false];
      else if (nb) m = nb.mine || nb.role === 'contributor' ? ['This notebook is empty', 'New entries you start here are filed in it.', true] : ['Nothing here yet', first(nb.owner_name) + ' hasn’t added anything.', false];
      else m = { pinned: ['Nothing pinned', 'Pin up to 8 entries to hold them at the top.', false], archive: ['Archive is empty', 'Archived entries rest here, out of the way.', false], shared: ['Nothing shared with you', 'When a member shares an entry with you, it appears here.', false] }[S.scope] || ['No entries yet', 'Your first one will appear here.', true];
      html = '<div class="avdy-empty"><strong>' + m[0] + '</strong><span>' + m[1] + '</span>' + (m[2] ? '<button type="button" class="avdy-btn" data-dy-new>New entry</button>' : '') + '</div>';
    }
    box.innerHTML = html;
    var c = q('[data-dy-count]'); if (c) c.textContent = rows.length + (rows.length === 1 ? ' entry' : ' entries');
  }

  /* ── Selecting ─────────────────────────────────────────────────────── */
  function flushNow() { if (saveT) { clearTimeout(saveT); saveT = null; return save(); } return Promise.resolve(); }
  function select(id) {
    return flushNow().then(function () {
      var row = S.list.filter(function (e) { return e.id === id; })[0];
      if (!row) return;
      S.entry = row; S.ti = 0; S.menu = null; S.delArm = false; S.saveErr = ''; S.people = null; S.shareUrl = '';
      S.readOnly = row.mine === false || !!row.direct;
      var nb = nbOf(row.notebook_id);
      S.roNote = row.direct ? 'Shared with you by ' + row.author + '. You can read it; only ' + first(row.author) + ' can edit it.'
        : S.readOnly && nb ? 'Written by ' + row.author + ' in “' + nb.name + '”. ' + (nb.role === 'contributor' ? 'You can add your own entries here, but only ' + first(row.author) + ' can edit this one.' : 'You can read everything in this notebook, including entries added later.') : '';
      S.tabs = [{ id: 0, title: row.first_tab_title || 'Entry', body: row.body || '' }];
      if (row.font && row.font !== 'default') loadFonts();
      renderList(); renderEditor();
      if (row.direct) {
        dyGet('entry.read', { id: row.id }).then(function (d) { if (S.entry !== row) return; S.tabs[0].body = d.ok ? d.entry.body_html : '<p>' + esc(d.error || 'Not available.') + '</p>'; renderEditor(); });
      } else if (row.id && (row.tab_count || 1) > 1) {
        nbGet('tabs', { entry: row.id }).then(function (d) {
          if (S.entry !== row || !d.ok) return;
          S.tabs = (d.tabs || []).map(function (t) { return { id: t.id, title: t.title, body: t.body }; });
          renderEditor();
        });
      }
    });
  }
  function newEntry() {
    flushNow().then(function () {
      var nb = curNb(), nbId = nb && (nb.mine || nb.role === 'contributor') ? nb.id : 0;
      if (S.scope !== 'all' && !nbId && S.scope !== 'unfiled') { S.scope = 'all'; renderLib(); renderHead(); }
      S.list = S.list.filter(function (e) { return e.id; });
      S.entry = { id: 0, kind: 'private', status: 'logged', entry_date: TODAY, title: '', body: '', tags: [], font: 'default', notebook_id: nbId, pinned: false, tab_count: 1, mine: true };
      S.list.unshift(S.entry);
      S.tabs = [{ id: 0, title: 'Entry', body: '' }]; S.ti = 0; S.readOnly = false; S.roNote = ''; S.menu = null; S.saveErr = '';
      S.mobileEdit = true; closeLib();
      renderList(); renderEditor();
      var t = q('[data-dy-title]'); if (t) t.focus();
    });
  }

  /* ── Saving ────────────────────────────────────────────────────────── */
  function touch(field) {
    pending[field] = true; S.saving = true; S.saveErr = ''; paintBar();
    clearTimeout(saveT); saveT = setTimeout(function () { saveT = null; save(); }, 600);
  }
  var saving = Promise.resolve();
  function save() {
    saving = saving.then(doSave, doSave);
    return saving;
  }
  function doSave() {
    var E = S.entry; if (!E || S.readOnly) { pending = {}; S.saving = false; paintBar(); return; }
    var p = pending; pending = {};
    if (!Object.keys(p).length) { S.saving = false; paintBar(); return; }
    var body0 = S.tabs[0] ? S.tabs[0].body : '';
    var jobs = [];
    if (!E.id) {
      if (!text(body0) && !text(E.title)) { S.saving = false; paintBar(); return; }   // nothing to keep yet
      if (!text(body0)) { S.saving = false; S.saveErr = 'Write a line in the entry to keep it.'; paintBar(); return; }
      return dyPost('entry.create', { kind: E.kind, title: E.title, body: body0, entry_date: E.entry_date, font: E.font, draft: E.kind !== 'private' }).then(function (d) {
        if (!d.ok) { S.saving = false; S.saveErr = d.error || 'Not saved.'; Object.assign(pending, p); paintBar(); return; }
        E.id = d.id; E.status = d.status;
        var after = [];
        if (E.notebook_id) after.push(nbPost('move', { entry_id: E.id, notebook_id: E.notebook_id }));
        if (E.tags.length) after.push(nbPost('tags', { entry_id: E.id, tags: E.tags }));
        return Promise.all(after).then(function () {
          S.saving = false; paintBar(); renderList(); renderEditorBarOnly(); bumpCounts();
        });
      });
    }
    if (p.title || p.body || p.date || p.kind || p.font || p.submit || p.draft) {
      if (!text(body0)) { S.saving = false; S.saveErr = 'Not saved — the first tab needs at least a line.'; Object.assign(pending, p); paintBar(); return; }
      var pay = { id: E.id, title: E.title, body: body0, entry_date: E.entry_date, kind: E.kind, font: E.font };
      if (p.submit) pay.submit = true; else if (p.draft) pay.draft = true;
      jobs.push(dyPost('entry.update', pay).then(function (d) {
        if (!d.ok) throw d;
        E.status = d.status; E.kind = d.kind;
        E.excerpt = text(body0).slice(0, 180);
      }));
    }
    if (p.tabs || p.tabTitles) {
      S.tabs.forEach(function (t, i) {
        if (i === 0 && !t._titleDirty) return;
        if (t._dirty || t._titleDirty) {
          var payload = { entry_id: E.id, tab_id: t.id };
          if (t._dirty && i > 0) payload.body = t.body;
          if (t._titleDirty) payload.title = t.title;
          t._dirty = false; t._titleDirty = false;
          jobs.push(nbPost('tab_save', payload).then(function (d) { if (!d.ok) { t._dirty = true; throw d; } }));
        }
      });
    }
    return Promise.all(jobs).then(function () { S.saving = false; S.saveErr = ''; paintBar(); refreshItem(); },
      function (d) { S.saving = false; S.saveErr = (d && d.error) || 'Not saved — try again.'; Object.assign(pending, p); paintBar(); });
  }
  function bumpCounts() { nbGet('list').then(function (d) { if (d.ok) { S.nbs = d.notebooks || S.nbs; S.counts = d.counts || S.counts; S.tags = d.tags || S.tags; renderLib(); } }); }
  function refreshItem() {
    var E = S.entry; if (!E) return;
    var b = q('[data-dy-pick="' + E.id + '"]');
    if (b) { var li = b.parentNode, tmp = document.createElement('ul'); tmp.innerHTML = itemHtml(E); li.parentNode.replaceChild(tmp.firstChild, li); }
  }
  window.addEventListener('beforeunload', function (e) { if (saveT || S.saving) { flushNow(); e.preventDefault(); e.returnValue = ''; } });
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flushNow(); });

  /* ── Editor ────────────────────────────────────────────────────────── */
  function statusBtn(E) {
    if (S.readOnly || !E.id || E.kind === 'private') return null;
    if (E.status === 'draft') return ['Submit for review', 'submit', true];
    if (E.status === 'pending') return ['Withdraw', 'withdraw', false];
    if (E.status === 'rejected') return ['Resubmit', 'submit', true];
    return null;
  }
  function savedText() {
    if (S.readOnly) return '';
    if (S.saveErr) return S.saveErr;
    var w = S.tabs.reduce(function (n, t) { return n + words(t.body); }, 0);
    if (!S.entry.id && !w) return 'Not saved yet';
    return (S.saving ? 'Saving…' : 'Saved') + ' · ' + w + (w === 1 ? ' word' : ' words');
  }
  function paintBar() {
    var E = S.entry, s = q('[data-dy-saved]'); if (!E || !s) return;
    s.textContent = savedText(); s.classList.toggle('is-bad', !!S.saveErr);
    var st = stInfo(E), stEl = q('[data-dy-st]');
    if (stEl) { stEl.hidden = E.kind === 'private'; stEl.setAttribute('data-tone', st[1]); q('[data-dy-stl]').textContent = st[0]; }
    var sb = statusBtn(E), b = q('[data-dy-submit]');
    if (b) { b.hidden = !sb; if (sb) { b.textContent = sb[0]; b.setAttribute('data-dy-submit', sb[1]); b.classList.toggle('avdy-btn--ink', sb[2]); } }
    var rv = q('[data-dy-review]'); if (rv) rv.outerHTML = reviewHtml(E);
  }
  /* After the first save the pin / share buttons become usable; repaint the bar only, so the caret stays put. */
  function renderEditorBarOnly() {
    qa('[data-dy-pin], [data-dy-menu="eshare"]').forEach(function (b) { b.disabled = false; });
  }
  function reviewHtml(E) {
    if (S.readOnly || E.kind === 'private' || E.status === 'rejected') return '<div data-dy-review hidden></div>';
    var idx = { draft: 0, pending: 2, approved: 3 }[E.status]; if (idx === undefined) idx = 0;
    var steps = ['Draft', 'Submitted', 'In review', 'Published'];
    return '<div class="avdy-review" data-dy-review><span class="avdy-review-t">' + (E.kind === 'event' ? 'Events are reviewed before they join the Diary’s Events stream' : 'Public entries are reviewed before they join the Diary') + '</span>'
      + '<ol class="avdy-steps">' + steps.map(function (t, i) { return '<li' + (i <= idx ? ' class="is-on' + (idx === 3 ? ' is-done' : '') + '"' : '') + '>' + t + '</li>'; }).join('') + '</ol></div>';
  }
  function renderEditor() {
    var doc = q('[data-dy-doc]'), pick = q('[data-dy-placeholder]'), E = S.entry;
    root.classList.toggle('is-editing', !!E && S.mobileEdit);
    if (!E) { doc.hidden = true; pick.hidden = false; doc.innerHTML = ''; return; }
    pick.hidden = true; doc.hidden = false;
    var nb = nbOf(E.notebook_id), ed = !S.readOnly, tab = S.tabs[S.ti] || S.tabs[0], st = stInfo(E), sb = statusBtn(E), font = E.font || 'default';
    var h = '<div class="avdy-bar">'
      + '<button type="button" class="avdy-ico avdy-back" data-dy-back aria-label="All entries">‹</button>'
      + '<button type="button" class="avdy-nbbtn" data-dy-menu="move"' + (ed ? ' aria-haspopup="true" aria-expanded="' + (S.menu === 'move') + '"' : '') + ' title="' + (ed ? 'Move to a notebook' : '') + '"><span class="avdy-spine avdy-spine--s" data-c="' + esc(nb ? nb.colour : 'none') + '"></span><span class="avdy-nbbtn-t">' + esc(nb ? nb.name : 'No notebook') + '</span>' + (ed ? svg(IC.chev) : '') + '</button>'
      + '<span class="avdy-saved" data-dy-saved aria-live="polite">' + esc(savedText()) + '</span>'
      + '<span class="avdy-bar-r">'
      + '<span class="avdy-st" data-dy-st data-tone="' + st[1] + '"' + (E.kind === 'private' ? ' hidden' : '') + '><span class="avdy-dot"></span><span data-dy-stl>' + st[0] + '</span></span>'
      + '<button type="button" class="avdy-btn avdy-btn--sm' + (sb && sb[2] ? ' avdy-btn--ink' : '') + '" data-dy-submit="' + (sb ? sb[1] : '') + '"' + (sb ? '' : ' hidden') + '>' + (sb ? sb[0] : '') + '</button>'
      + (ed ? '<button type="button" class="avdy-ico' + (E.pinned ? ' is-pin' : '') + '" data-dy-pin aria-pressed="' + !!E.pinned + '" aria-label="' + (E.pinned ? 'Unpin' : 'Pin to top') + '" title="' + (E.pinned ? 'Unpin' : 'Pin to top') + '"' + (E.id ? '' : ' disabled') + '>' + svg(IC.pin) + '</button>'
        + '<button type="button" class="avdy-ico" data-dy-menu="eshare" aria-haspopup="true" aria-expanded="' + (S.menu === 'eshare') + '" aria-label="Share this entry" title="Share this entry"' + (E.id ? '' : ' disabled') + '>' + svg(IC.link) + '</button>'
        + '<button type="button" class="avdy-ico" data-dy-menu="more" aria-haspopup="true" aria-expanded="' + (S.menu === 'more' || S.menu === 'font') + '" aria-label="More" title="More">' + svg(IC.more) + '</button>'
        : '<span class="avdy-ro">Read only</span>')
      + '</span></div>'
      + '<div class="avdy-menuhost" data-dy-menuhost></div>';
    if (S.tabs.length > 1) {
      h += '<div class="avdy-tabs" role="tablist" aria-label="Tabs in this entry">' + S.tabs.map(function (t, i) {
        var on = i === S.ti;
        if (on && ed) return '<div class="avdy-tab is-on" role="presentation"><input class="avdy-tab-in" data-dy-tabname value="' + esc(t.title) + '" maxlength="80" aria-label="Tab name" size="' + Math.max(5, (t.title || '').length + 1) + '">' + (i > 0 ? '<button type="button" class="avdy-tab-x" data-dy-tabdel aria-label="Remove tab" title="Remove tab">' + svg(IC.x) + '</button>' : '') + '</div>';
        return '<div class="avdy-tab' + (on ? ' is-on' : '') + '" role="presentation"><button type="button" role="tab" aria-selected="' + on + '" data-dy-tab="' + i + '">' + esc(t.title) + '</button></div>';
      }).join('') + (ed ? '<button type="button" class="avdy-ico avdy-ico--sm" data-dy-tabadd aria-label="Add tab" title="Add tab">' + svg(IC.plus) + '</button>' : '') + '</div>';
    }
    h += '<div class="avdy-page"><div class="avdy-paper">';
    if (S.readOnly && S.roNote) h += '<p class="avdy-ronote">' + esc(S.roNote) + '</p>';
    if (ed && E.kind !== 'private' && E.status === 'rejected' && E.review_note) h += '<div class="avdy-rejected" role="note"><strong>Not approved</strong><span>' + esc(E.review_note) + '</span></div>';
    h += '<input class="avdy-title" data-dy-title value="' + esc(E.title) + '" placeholder="Untitled" aria-label="Entry title" maxlength="160"' + (ed ? '' : ' readonly') + '>';
    h += '<div class="avdy-meta">'
      + '<label class="avdy-date" title="' + (E.kind === 'event' ? 'When it happened — events can be backdated' : 'Entry date') + '"><span>' + esc(fmtLong(E.entry_date)) + '</span>' + (ed ? '<input type="date" data-dy-date value="' + esc(String(E.entry_date).slice(0, 10)) + '" max="' + TODAY + '" aria-label="Entry date">' : '') + '</label>'
      + '<span class="avdy-vishost"><button type="button" class="avdy-vis" data-dy-menu="vis"' + (ed ? ' aria-haspopup="true" aria-expanded="' + (S.menu === 'vis') + '"' : ' disabled') + '>' + svg(KIND[E.kind][1]) + KIND[E.kind][0] + (ed ? svg(IC.chev) : '') + '</button><span data-dy-vismenu></span></span>'
      + (E.tags || []).map(function (t) { return '<span class="avdy-tag">#' + esc(t) + (ed ? '<button type="button" data-dy-untag="' + esc(t) + '" aria-label="Remove tag ' + esc(t) + '">' + svg(IC.x) + '</button>' : '') + '</span>'; }).join('')
      + (ed && (E.tags || []).length < MAX_TAGS ? '<input class="avdy-tagin" data-dy-tagin list="avdy-vocab" placeholder="Add tag" aria-label="Add a tag" maxlength="40"><datalist id="avdy-vocab">' + (S.tags || []).map(function (t) { return '<option value="' + esc(t.tag) + '">'; }).join('') + '</datalist>' : '')
      + '</div>';
    if (ed && !text(tab.body)) {
      h += '<div class="avdy-tpl"><span>Start from a template, or just write</span><div>' + TEMPLATES.map(function (t, i) { return '<button type="button" data-dy-tpl="' + i + '" title="' + esc(t[1]) + '">' + esc(t[0]) + '</button>'; }).join('') + '</div></div>';
    }
    h += '<div class="avdy-body" data-dy-body data-font="' + esc(font) + '"' + (ed ? ' contenteditable="true" role="textbox" aria-multiline="true" aria-label="Entry text"' : '') + ' data-ph="Start writing…"></div>';
    h += reviewHtml(E);
    h += '</div></div>';
    if (ed) h += '<div class="avdy-fmt" data-dy-fmt role="toolbar" aria-label="Formatting" hidden>' + TOOLS.map(function (t, i) { return '<button type="button" class="' + t[4] + '" data-dy-tool="' + i + '" aria-label="' + t[1] + '" title="' + t[1] + '">' + t[0] + '</button>'; }).join('') + '</div>';
    doc.innerHTML = h;
    q('[data-dy-body]').innerHTML = tab.body || '';
    renderMenu();
  }

  /* ── Menus ─────────────────────────────────────────────────────────── */
  function renderMenu() {
    var host = q('[data-dy-menuhost]'), vhost = q('[data-dy-vismenu]'), E = S.entry;
    if (!host || !E) return;
    host.innerHTML = ''; if (vhost) vhost.innerHTML = '';
    if (!S.menu || S.readOnly) return;
    var h = '';
    if (S.menu === 'move') {
      var opts = [{ id: 0, name: 'No notebook', sub: 'Keep it loose in your diary', c: 'none' }].concat(S.nbs.filter(function (n) { return (n.mine && !n.archived) || n.role === 'contributor'; }).map(function (n) {
        return { id: n.id, name: n.name, sub: n.mine ? (n.shared_link ? 'Shared by link — they’ll see it' : 'Yours') : n.owner_name + '’s · you can add', c: n.colour };
      }));
      h = '<div class="avdy-menu avdy-menu--l" role="menu" aria-label="Move to notebook"><p class="avdy-menu-h">Move to notebook</p>' + opts.map(function (o) {
        return '<button type="button" role="menuitemradio" aria-checked="' + ((E.notebook_id || 0) === o.id) + '" data-dy-move="' + o.id + '"><span class="avdy-spine avdy-spine--l" data-c="' + esc(o.c) + '"></span><span class="avdy-menu-2"><span>' + esc(o.name) + '</span><small>' + esc(o.sub) + '</small></span><span class="avdy-check">' + ((E.notebook_id || 0) === o.id ? '✓' : '') + '</span></button>';
      }).join('') + '<hr><button type="button" role="menuitem" data-dy-nbnew data-dy-movenew>' + svg(IC.plus) + 'New notebook</button></div>';
    } else if (S.menu === 'more') {
      var fontName = (FONTS.filter(function (f) { return f[0] === (E.font || 'default'); })[0] || FONTS[0])[1];
      var items = [
        ['tabadd', 'Add a tab', IC.plus, S.tabs.length >= MAX_TABS ? 'Max ' + MAX_TABS : (E.id ? '' : 'Write first')],
        ['font', 'Handwriting', IC.pen, (E.font || 'default') === 'default' ? 'Default' : fontName],
        ['move', 'Move to notebook', IC.book, ''],
        ['archive', E.archived ? 'Restore' : 'Archive', IC.archive, ''],
        ['md', 'Export as Markdown', IC.down, ''],
        ['delete', S.delArm ? 'Click again to delete' : 'Delete', IC.trash, '']
      ];
      h = '<div class="avdy-menu avdy-menu--r" role="menu" aria-label="More">' + items.map(function (it) {
        var dis = (it[0] === 'tabadd' && (!E.id || S.tabs.length >= MAX_TABS)) || ((it[0] === 'archive' || it[0] === 'md' || it[0] === 'delete') && !E.id && it[0] !== 'delete');
        return '<button type="button" role="menuitem" data-dy-more="' + it[0] + '"' + (it[0] === 'delete' ? ' class="is-red"' : '') + (dis ? ' disabled' : '') + '>' + svg(it[2]) + '<span>' + it[1] + '</span><small>' + esc(it[3]) + '</small></button>';
      }).join('') + '</div>';
    } else if (S.menu === 'font') {
      loadFonts();
      h = '<div class="avdy-menu avdy-menu--r" role="menu" aria-label="Handwriting"><p class="avdy-menu-h">Handwriting — how your words look</p>' + FONTS.map(function (f) {
        var on = (E.font || 'default') === f[0];
        return '<button type="button" role="menuitemradio" aria-checked="' + on + '" data-dy-font="' + f[0] + '"><span class="avdy-fontname" data-font="' + f[0] + '">' + f[1] + '</span><span class="avdy-check">' + (on ? '✓' : '') + '</span></button>';
      }).join('') + '</div>';
    } else if (S.menu === 'eshare') {
      h = '<div class="avdy-menu avdy-menu--r avdy-menu--pad" role="dialog" aria-label="Share this entry"><strong>Share this entry</strong>'
        + '<span class="avdy-menu-p">Anyone with the link can read this one entry — not its notebook, and nothing else in your diary.</span>'
        + (S.shareUrl ? '<div class="avdy-copyrow"><input readonly value="' + esc(S.shareUrl) + '" aria-label="Link to this entry"><button type="button" class="avdy-btn avdy-btn--ink avdy-btn--sm" data-dy-copy="e">' + (S.copied === 'e' ? 'Copied ✓' : 'Copy') + '</button></div><button type="button" class="avdy-linkoff" data-dy-elinkoff>Turn off link</button>'
          : '<button type="button" class="avdy-btn avdy-btn--ink avdy-btn--sm avdy-self" data-dy-elink>Create link</button>')
        + '<hr><strong class="avdy-menu-s">Share with a member</strong>'
        + '<div class="avdy-copyrow"><input type="email" data-dy-pmail placeholder="Member’s email" aria-label="Member’s email"><button type="button" class="avdy-btn avdy-btn--sm" data-dy-padd>Share</button></div>'
        + '<span class="avdy-menu-err" data-dy-perr role="alert"></span>'
        + '<ul class="avdy-people">' + (S.people || []).map(function (p) { return '<li><span class="avdy-av" aria-hidden="true">' + esc(ini(p.name)) + '</span><span class="avdy-menu-2"><span>' + esc(p.name) + '</span><small>' + esc(p.email || '') + '</small></span><button type="button" class="avdy-ico avdy-ico--sm" data-dy-prm="' + p.id + '" aria-label="Stop sharing with ' + esc(p.name) + '">' + svg(IC.x) + '</button></li>'; }).join('') + '</ul></div>';
    } else if (S.menu === 'vis' && vhost) {
      var nb = nbOf(E.notebook_id);
      var vis = [['private', 'Private', IC.lock, nb ? 'Only you, and the people this notebook is shared with.' : 'Only you can see it. It never leaves your diary.'],
        ['public', 'Public', IC.globe, 'Reviewed by the team, then published on the Diary.'],
        ['event', 'Event', IC.cal, 'A public happening for the Events stream. Reviewed first; can be backdated.']];
      vhost.innerHTML = '<div class="avdy-menu avdy-menu--vis" role="menu" aria-label="Who can see this">' + vis.map(function (v) {
        return '<button type="button" role="menuitemradio" aria-checked="' + (E.kind === v[0]) + '" data-dy-kindset="' + v[0] + '">' + svg(v[2]) + '<span class="avdy-menu-2"><span>' + v[1] + '</span><small>' + v[3] + '</small></span><span class="avdy-check">' + (E.kind === v[0] ? '✓' : '') + '</span></button>';
      }).join('') + '</div>';
      focusMenu(vhost); return;
    }
    host.innerHTML = h; focusMenu(host);
  }
  function focusMenu(host) { var f = host.querySelector('.avdy-menu button:not([disabled]), .avdy-menu input'); if (f) setTimeout(function () { f.focus(); }, 0); }
  function openMenu(m, from) {
    if (S.readOnly) return;
    S.menu = S.menu === m ? null : m; S.menuFrom = from || null; S.delArm = false; S.copied = '';
    if (S.menu === 'eshare') loadEntryShare();
    renderMenu(); qa('[data-dy-menu]').forEach(function (b) { if (b.hasAttribute('aria-expanded')) b.setAttribute('aria-expanded', String(b.getAttribute('data-dy-menu') === S.menu)); });
  }
  function closeMenu(focus) {
    if (!S.menu) return; var from = S.menuFrom; S.menu = null; S.delArm = false; renderMenu();
    qa('[data-dy-menu]').forEach(function (b) { if (b.hasAttribute('aria-expanded')) b.setAttribute('aria-expanded', 'false'); });
    if (focus && from && document.body.contains(from)) from.focus();
  }
  function loadEntryShare() {
    var E = S.entry; if (!E || !E.id) return;
    dyGet('entry.people', { id: E.id }).then(function (d) { if (S.entry === E && d.ok) { S.people = d.people || []; if (S.menu === 'eshare') renderMenu(); } });
  }

  /* ── Modals: notebook settings, notebook sharing ───────────────────── */
  var modalFrom = null;
  function openModal(kind, nb, moveId) {
    modalFrom = document.activeElement; closeMenu(false); closeLib();
    var m = q('[data-dy-modal]'), h;
    if (kind === 'nb') {
      var F = { id: nb ? nb.id : 0, name: nb ? nb.name : '', desc: nb ? nb.description : '', colour: nb ? nb.colour : 'green', moveId: moveId || 0 };
      m._F = F;
      h = '<div class="avdy-dlg" role="dialog" aria-modal="true" aria-labelledby="avdy-dlg-h">'
        + '<div class="avdy-dlg-h"><h3 id="avdy-dlg-h">' + (F.id ? 'Notebook settings' : 'New notebook') + '</h3><button type="button" class="avdy-ico avdy-ico--sm" data-dy-close aria-label="Close">' + svg(IC.x) + '</button></div>'
        + '<div class="avdy-dlg-b">'
        + '<div class="avdy-nbprev"><span class="avdy-spine avdy-spine--xl" data-dy-fspine data-c="' + esc(F.colour) + '"></span><span class="avdy-menu-2"><strong data-dy-fprev>' + esc(F.name || 'Untitled notebook') + '</strong><small>' + (F.id ? (nb.entries || 0) + ' entries' : 'New · only you') + '</small></span></div>'
        + '<label class="avdy-fld">Name<input data-dy-fname value="' + esc(F.name) + '" placeholder="What is this notebook for?" maxlength="120"></label>'
        + '<label class="avdy-fld">Description <small>Optional — shown to anyone you share it with</small><textarea data-dy-fdesc rows="2" maxlength="400">' + esc(F.desc) + '</textarea></label>'
        + '<div class="avdy-fld" role="radiogroup" aria-label="Spine colour">Spine colour<div class="avdy-sw">' + ['ink', 'green', 'gold', 'clay', 'sky', 'plum'].map(function (c) { return '<button type="button" role="radio" aria-checked="' + (F.colour === c) + '" data-dy-sw="' + c + '" data-c="' + c + '" aria-label="' + c.charAt(0).toUpperCase() + c.slice(1) + '" title="' + c.charAt(0).toUpperCase() + c.slice(1) + '"></button>'; }).join('') + '</div></div>'
        + '<p class="avdy-dlg-note">Notebooks don’t nest. Use tags for anything that cuts across them.</p>'
        + '<p class="avdy-menu-err" data-dy-ferr role="alert"></p></div>'
        + '<div class="avdy-dlg-f">' + (F.id ? '<button type="button" class="avdy-btn avdy-btn--sm" data-dy-nbarchive>Archive</button><button type="button" class="avdy-btn avdy-btn--sm avdy-btn--red" data-dy-nbdel title="Entries return to your diary — nothing is deleted but the notebook">Delete</button>' : '')
        + '<span class="avdy-dlg-fr"><button type="button" class="avdy-btn avdy-btn--sm" data-dy-close>Cancel</button><button type="button" class="avdy-btn avdy-btn--sm avdy-btn--ink" data-dy-nbsave>' + (F.id ? 'Save' : 'Create notebook') + '</button></span></div></div>';
    } else {
      m._nb = nb;
      h = '<div class="avdy-dlg avdy-dlg--w" role="dialog" aria-modal="true" aria-labelledby="avdy-dlg-h">'
        + '<div class="avdy-dlg-h"><span class="avdy-spine avdy-spine--m" data-c="' + esc(nb.colour) + '"></span><h3 id="avdy-dlg-h">Share “' + esc(nb.name) + '”</h3><button type="button" class="avdy-ico avdy-ico--sm" data-dy-close aria-label="Close">' + svg(IC.x) + '</button></div>'
        + '<div class="avdy-dlg-b">'
        + '<div class="avdy-inv"><input type="email" data-dy-inv placeholder="Member’s email" aria-label="Member’s email"><select data-dy-invrole aria-label="Access"><option value="viewer">Can read</option><option value="contributor">Can add entries</option></select><button type="button" class="avdy-btn avdy-btn--sm avdy-btn--ink" data-dy-invite>Invite</button></div>'
        + '<p class="avdy-menu-err" data-dy-inverr role="alert"></p>'
        + '<p class="avdy-dlg-note">They’re told in the portal and by email. It appears in their diary under Shared with me.</p>'
        + '<ul class="avdy-mem" data-dy-members><li><span class="avdy-av avdy-av--gold" aria-hidden="true">' + esc(ini(ME)) + '</span><span class="avdy-menu-2"><span>' + esc(ME) + ' (you)</span><small>' + esc(MY_EMAIL) + '</small></span><span class="avdy-mem-r">Owner</span></li><li class="avdy-mem-load">Loading who has access…</li></ul>'
        + '<div class="avdy-linkbox"><div class="avdy-linkbox-h">' + svg(IC.link) + '<span class="avdy-menu-2"><span>Read-only link</span><small>For people without an account. Shows entries, never who else has access.</small></span>'
        + '<button type="button" class="avdy-switch" role="switch" aria-checked="' + !!nb.shared_link + '" aria-label="Read-only link" data-dy-nblink><span></span></button></div><div data-dy-nblinkrow></div></div>'
        + '<ul class="avdy-dlg-list"><li>Access follows the notebook, so it covers entries you add later.</li><li>Only you can rename, archive or delete this notebook.</li><li>A contributor’s entries stay theirs — only they can edit them.</li></ul>'
        + '</div></div>';
    }
    m.innerHTML = h; m.hidden = false;
    var f = m.querySelector('input, button'); if (f) f.focus();
    if (kind === 'share') { loadMembers(nb); if (nb.shared_link) linkRow(nb, true); }
  }
  function closeModal() { var m = q('[data-dy-modal]'); m.hidden = true; m.innerHTML = ''; if (modalFrom && document.body.contains(modalFrom)) modalFrom.focus(); }
  function loadMembers(nb) {
    nbGet('members', { id: nb.id }).then(function (d) {
      var ul = q('[data-dy-members]'); if (!ul) return;
      var load = ul.querySelector('.avdy-mem-load'); if (load) load.remove();
      qa('.avdy-mem-x', ul).forEach(function (li) { li.remove(); });
      if (!d.ok) { ul.insertAdjacentHTML('beforeend', '<li class="avdy-mem-load avdy-mem-x">' + esc(d.error || 'Could not load who has access.') + '</li>'); return; }
      (d.members || []).forEach(function (p) {
        ul.insertAdjacentHTML('beforeend', '<li class="avdy-mem-x"><span class="avdy-av" aria-hidden="true">' + esc(ini(p.name)) + '</span><span class="avdy-menu-2"><span>' + esc(p.name) + '</span><small>' + esc(p.email) + '</small></span>'
          + '<select data-dy-mrole="' + p.id + '" data-email="' + esc(p.email) + '" aria-label="Access for ' + esc(p.name) + '"><option value="viewer"' + (p.role === 'viewer' ? ' selected' : '') + '>Can read</option><option value="contributor"' + (p.role === 'contributor' ? ' selected' : '') + '>Can add entries</option></select>'
          + '<button type="button" class="avdy-ico avdy-ico--sm" data-dy-munshare="' + p.id + '" aria-label="Remove ' + esc(p.name) + '’s access">' + svg(IC.x) + '</button></li>');
      });
    });
  }
  function linkRow(nb, on) {
    var row = q('[data-dy-nblinkrow]'); if (!row) return;
    if (!on) { row.innerHTML = ''; return; }
    nbPost('link', { id: nb.id }).then(function (d) {
      if (!d.ok) { row.innerHTML = '<p class="avdy-menu-err">' + esc(d.error || 'The link could not be made.') + '</p>'; return; }
      nb.shared_link = true;
      row.innerHTML = '<div class="avdy-copyrow"><input readonly value="' + esc(d.url) + '" aria-label="Notebook link"><button type="button" class="avdy-btn avdy-btn--sm" data-dy-copy="nb" data-url="' + esc(d.url) + '">Copy</button></div>';
    });
  }

  /* ── Library drawer (narrow) ───────────────────────────────────────── */
  function openLib() { S.libOpen = true; root.classList.add('is-lib'); q('[data-dy-libscrim]').hidden = false; q('[data-dy-libopen]').setAttribute('aria-expanded', 'true'); var f = q('[data-dy-lib] button'); if (f) f.focus(); }
  function closeLib() { if (!S.libOpen) return; S.libOpen = false; root.classList.remove('is-lib'); q('[data-dy-libscrim]').hidden = true; var b = q('[data-dy-libopen]'); b.setAttribute('aria-expanded', 'false'); }

  function go(scope) {
    flushNow().then(function () {
      S.scope = scope; S.q = ''; S.kind = 'all'; S.mobileEdit = false;
      S.entry = null; q('[data-dy-q]').value = '';
      qa('[data-dy-kind]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-dy-kind') === 'all')); });
      closeLib(); renderLib(); renderHead(); renderEditor(); loadList(false);
    });
  }

  /* ── Events ────────────────────────────────────────────────────────── */
  root.addEventListener('click', function (ev) {
    var t = ev.target.closest('button, a, [data-dy-libscrim]'); if (!t || !root.contains(t)) { if (S.menu && !ev.target.closest('.avdy-menu')) closeMenu(false); return; }
    var E = S.entry, a;
    if (S.menu && !t.closest('.avdy-menu') && !t.hasAttribute('data-dy-menu')) closeMenu(false);
    if (t.hasAttribute('data-dy-libscrim')) return closeLib();
    if (t.hasAttribute('data-dy-new')) return newEntry();
    if ((a = t.getAttribute('data-dy-scope')) !== null) return go(a);
    if (t.hasAttribute('data-dy-libopen')) return S.libOpen ? closeLib() : openLib();
    if (t.hasAttribute('data-dy-retry')) return loadList(true);
    if ((a = t.getAttribute('data-dy-pick')) !== null) { S.mobileEdit = true; return select(+a); }
    if (t.hasAttribute('data-dy-back')) { flushNow(); S.mobileEdit = false; root.classList.remove('is-editing'); var b = q('[data-dy-pick="' + (E ? E.id : '') + '"]'); if (b) b.focus(); return; }
    if ((a = t.getAttribute('data-dy-kind')) !== null) { S.kind = a; qa('[data-dy-kind]').forEach(function (b) { b.setAttribute('aria-pressed', String(b === t)); }); return flushNow().then(function () { loadList(true); }); }
    if (t.hasAttribute('data-dy-nbnew')) return openModal('nb', null, t.hasAttribute('data-dy-movenew') && E ? E.id : 0);
    if (t.hasAttribute('data-dy-nbedit')) { var nb1 = curNb(); if (nb1) openModal('nb', nb1); return; }
    if (t.hasAttribute('data-dy-nbshare')) { var nb2 = curNb(); if (nb2) openModal('share', nb2); return; }
    if ((a = t.getAttribute('data-dy-menu')) !== null) { if (t.disabled) return; return openMenu(a, t); }
    if (!E) return;
    if ((a = t.getAttribute('data-dy-tab')) !== null) { flushNow(); S.ti = +a; renderEditor(); var tb = q('[data-dy-tabname]'); if (tb) tb.focus(); return; }
    if (t.hasAttribute('data-dy-tabadd')) return addTab();
    if (t.hasAttribute('data-dy-tabdel')) return delTab();
    if ((a = t.getAttribute('data-dy-tpl')) !== null) {
      var tp = TEMPLATES[+a]; S.tabs[S.ti].body = tp[2]; if (S.ti > 0) S.tabs[S.ti]._dirty = true;
      if (!E.title) { E.title = tp[0]; touch('title'); }
      touch(S.ti === 0 ? 'body' : 'tabs'); renderEditor(); var bd = q('[data-dy-body]'); if (bd) bd.focus(); return;
    }
    if ((a = t.getAttribute('data-dy-untag')) !== null) { setTags(E.tags.filter(function (x) { return x !== a; })); return; }
    if ((a = t.getAttribute('data-dy-submit')) !== null && a) {
      if (a === 'submit') { touch('submit'); flushNow().then(function () { renderEditor(); refreshItem(); }); }
      else { touch('draft'); flushNow().then(function () { renderEditor(); refreshItem(); }); }
      return;
    }
    if (t.hasAttribute('data-dy-pin')) {
      var on = !E.pinned;
      nbPost('pin', { entry_id: E.id, on: on }).then(function (d) {
        if (!d.ok) { S.saveErr = d.error || 'Could not pin.'; paintBar(); return; }
        E.pinned = on; S.saveErr = ''; renderEditor(); renderList(); bumpCounts();
      });
      return;
    }
    if ((a = t.getAttribute('data-dy-move')) !== null) {
      var to = +a; closeMenu(true);
      if (!E.id) { E.notebook_id = to; renderEditor(); return; }
      nbPost('move', { entry_id: E.id, notebook_id: to }).then(function (d) {
        if (!d.ok) { S.saveErr = d.error || 'Could not move it.'; paintBar(); return; }
        E.notebook_id = to; renderEditor(); bumpCounts();
        if (curNb() || S.scope === 'unfiled') loadList(true); else refreshItem();
      });
      return;
    }
    if ((a = t.getAttribute('data-dy-more')) !== null) {
      if (a === 'tabadd') { closeMenu(false); return addTab(); }
      if (a === 'font') { S.menu = 'font'; return renderMenu(); }
      if (a === 'move') { S.menu = 'move'; return renderMenu(); }
      if (a === 'md') { closeMenu(true); return exportMd(); }
      if (a === 'archive') {
        var arch = !E.archived; closeMenu(false);
        nbPost('archive', { entry_id: E.id, on: arch }).then(function (d) {
          if (!d.ok) { S.saveErr = d.error || 'Could not archive it.'; paintBar(); return; }
          S.entry = null; bumpCounts(); loadList(false);
        });
        return;
      }
      if (a === 'delete') {
        if (!S.delArm) { S.delArm = true; var bb = t.querySelector('span'); if (bb) bb.textContent = 'Click again to delete'; return; }
        closeMenu(false);
        if (!E.id) { S.list = S.list.filter(function (x) { return x !== E; }); S.entry = null; renderList(); renderEditor(); return; }
        dyPost('entry.delete', { id: E.id }).then(function (d) {
          if (!d.ok) { S.saveErr = d.error || 'Could not delete it.'; paintBar(); return; }
          clearTimeout(saveT); saveT = null; pending = {};
          S.entry = null; bumpCounts(); loadList(false);
        });
        return;
      }
    }
    if ((a = t.getAttribute('data-dy-font')) !== null) { E.font = a; if (a !== 'default') loadFonts(); closeMenu(true); var bd2 = q('[data-dy-body]'); if (bd2) bd2.setAttribute('data-font', a); touch('font'); return; }
    if ((a = t.getAttribute('data-dy-kindset')) !== null) {
      closeMenu(true);
      if (E.kind === a) return;
      var wasPriv = E.kind === 'private'; E.kind = a;
      if (a === 'private') E.status = 'logged'; else if (wasPriv) { E.status = 'draft'; pending.draft = true; }
      touch('kind'); renderEditor(); return;
    }
    if (t.hasAttribute('data-dy-elink')) { dyPost('entry.share', { id: E.id }).then(function (d) { if (d.ok) { S.shareUrl = d.url; renderMenu(); } else { var er = q('[data-dy-perr]'); if (er) er.textContent = d.error || 'Could not make a link.'; } }); return; }
    if (t.hasAttribute('data-dy-elinkoff')) { dyPost('entry.share', { id: E.id, revoke: 1 }).then(function (d) { if (d.ok) { S.shareUrl = ''; renderMenu(); } }); return; }
    if ((a = t.getAttribute('data-dy-copy')) !== null) {
      var url = a === 'e' ? S.shareUrl : t.getAttribute('data-url');
      try { navigator.clipboard.writeText(url); } catch (e) {}
      t.textContent = 'Copied ✓'; S.copied = a; return;
    }
    if (t.hasAttribute('data-dy-padd')) {
      var mail = q('[data-dy-pmail]'), err = q('[data-dy-perr]');
      dyPost('entry.share_add', { id: E.id, email: (mail.value || '').trim() }).then(function (d) {
        if (!d.ok) { err.textContent = d.error || 'Could not share it.'; return; }
        err.textContent = ''; mail.value = ''; loadEntryShare();
      });
      return;
    }
    if ((a = t.getAttribute('data-dy-prm')) !== null) { dyPost('entry.share_remove', { id: E.id, user_id: +a }).then(function () { loadEntryShare(); }); return; }
  });

  /* Modal clicks (the modal sits inside root, so they arrive here too — kept apart for clarity). */
  q('[data-dy-modal]').addEventListener('click', function (ev) {
    var m = this, t = ev.target.closest('button'); var F = m._F, nb = m._nb, a;
    if (ev.target === m) return closeModal();
    if (!t) return;
    ev.stopPropagation();
    if (t.hasAttribute('data-dy-close')) return closeModal();
    if ((a = t.getAttribute('data-dy-sw')) !== null) {
      F.colour = a; qa('[data-dy-sw]', m).forEach(function (b) { b.setAttribute('aria-checked', String(b === t)); });
      q('[data-dy-fspine]', m).setAttribute('data-c', a); return;
    }
    if (t.hasAttribute('data-dy-nbsave')) {
      var name = (q('[data-dy-fname]', m).value || '').trim(), desc = (q('[data-dy-fdesc]', m).value || '').trim(), err = q('[data-dy-ferr]', m);
      if (!name) { err.textContent = 'Give the notebook a name.'; q('[data-dy-fname]', m).focus(); return; }
      t.setAttribute('aria-busy', 'true');
      var p = F.id ? nbPost('update', { id: F.id, name: name, description: desc, colour: F.colour }) : nbPost('create', { name: name, description: desc, colour: F.colour });
      p.then(function (d) {
        t.removeAttribute('aria-busy');
        if (!d.ok) { err.textContent = d.error || 'The notebook could not be saved.'; return; }
        var newId = F.id || (d.id || (d.notebook && d.notebook.id));
        var then = F.moveId && newId ? nbPost('move', { entry_id: F.moveId, notebook_id: newId }) : Promise.resolve();
        then.then(function () {
          closeModal();
          loadNbs().then(function () { if (F.moveId && S.entry) S.entry.notebook_id = newId; if (!F.id && newId) go('nb:' + newId); else { renderHead(); renderEditor(); } });
        });
      });
      return;
    }
    if (t.hasAttribute('data-dy-nbarchive')) {
      nbPost('update', { id: F.id, archived: true }).then(function (d) { if (!d.ok) { q('[data-dy-ferr]', m).textContent = d.error || 'Could not archive it.'; return; } closeModal(); loadNbs().then(function () { go('all'); }); });
      return;
    }
    if (t.hasAttribute('data-dy-nbdel')) {
      if (!t._armed) { t._armed = true; t.textContent = 'Click again — entries stay'; return; }
      nbPost('delete', { id: F.id }).then(function (d) { if (!d.ok) { q('[data-dy-ferr]', m).textContent = d.error || 'Could not delete it.'; return; } closeModal(); loadNbs().then(function () { go('unfiled'); }); });
      return;
    }
    if (t.hasAttribute('data-dy-invite')) {
      var inv = q('[data-dy-inv]', m), role = q('[data-dy-invrole]', m).value, ie = q('[data-dy-inverr]', m), em = (inv.value || '').trim().toLowerCase();
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(em)) { ie.textContent = 'Enter a valid email.'; inv.focus(); return; }
      t.setAttribute('aria-busy', 'true');
      nbPost('share', { id: nb.id, email: em, role: role }).then(function (d) {
        t.removeAttribute('aria-busy');
        if (!d.ok) { ie.textContent = d.error || 'Could not share it.'; return; }
        ie.textContent = ''; inv.value = ''; loadMembers(nb); bumpCounts();
      });
      return;
    }
    if ((a = t.getAttribute('data-dy-munshare')) !== null) { nbPost('unshare', { id: nb.id, user_id: +a }).then(function () { loadMembers(nb); }); return; }
    if (t.hasAttribute('data-dy-nblink')) {
      var on = t.getAttribute('aria-checked') !== 'true';
      t.setAttribute('aria-checked', String(on));
      if (on) linkRow(nb, true); else nbPost('unlink', { id: nb.id }).then(function () { nb.shared_link = false; linkRow(nb, false); renderLib(); });
      return;
    }
    if ((a = t.getAttribute('data-dy-copy')) !== null) { try { navigator.clipboard.writeText(t.getAttribute('data-url')); } catch (e) {} t.textContent = 'Copied ✓'; }
  });
  q('[data-dy-modal]').addEventListener('change', function (ev) {
    var s = ev.target.closest('[data-dy-mrole]'); if (!s) return;
    var nb = this._nb;
    nbPost('share', { id: nb.id, email: s.getAttribute('data-email'), role: s.value }).then(function (d) { if (!d.ok) { var ie = q('[data-dy-inverr]'); if (ie) ie.textContent = d.error || 'Could not change access.'; } });
  });
  q('[data-dy-modal]').addEventListener('input', function (ev) {
    if (ev.target.hasAttribute('data-dy-fname')) { var p = q('[data-dy-fprev]', this); if (p) p.textContent = ev.target.value.trim() || 'Untitled notebook'; q('[data-dy-ferr]', this).textContent = ''; }
  });
  q('[data-dy-modal]').addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && ev.target.hasAttribute('data-dy-inv')) { ev.preventDefault(); var b = q('[data-dy-invite]', this); if (b) b.click(); }
    if (ev.key === 'Tab') {   // keep focus inside the dialog
      var f = qa('button:not([disabled]), input, select, textarea', this); if (!f.length) return;
      if (ev.shiftKey && document.activeElement === f[0]) { ev.preventDefault(); f[f.length - 1].focus(); }
      else if (!ev.shiftKey && document.activeElement === f[f.length - 1]) { ev.preventDefault(); f[0].focus(); }
    }
  });

  root.addEventListener('input', function (ev) {
    var t = ev.target, E = S.entry; if (!E) return;
    if (t.hasAttribute('data-dy-q')) { clearTimeout(t._qt); t._qt = setTimeout(function () { S.q = t.value.trim(); flushNow().then(function () { loadList(true); }); }, 280); return; }
    if (S.readOnly) return;
    if (t.hasAttribute('data-dy-title')) { E.title = t.value; touch('title'); var it = q('[data-dy-pick="' + E.id + '"] .avdy-item-t'); if (it) it.textContent = E.title || 'Untitled'; return; }
    if (t.hasAttribute('data-dy-body')) {
      var html = t.innerHTML; if (text(html) === '' && !/<(li|img)/i.test(html)) html = '';
      S.tabs[S.ti].body = html;
      if (S.ti === 0) touch('body'); else { S.tabs[S.ti]._dirty = true; touch('tabs'); }
      var tpl = q('.avdy-tpl'); if (tpl && text(html)) tpl.remove();
      return;
    }
    if (t.hasAttribute('data-dy-tabname')) { S.tabs[S.ti].title = t.value; S.tabs[S.ti]._titleDirty = true; t.size = Math.max(5, t.value.length + 1); touch('tabTitles'); return; }
  });
  root.addEventListener('change', function (ev) {
    var t = ev.target, E = S.entry; if (!E || S.readOnly) return;
    if (t.hasAttribute('data-dy-date') && t.value) {
      if (t.value > TODAY) { t.value = TODAY; }
      E.entry_date = t.value; touch('date'); var l = t.parentNode.querySelector('span'); if (l) l.textContent = fmtLong(E.entry_date); refreshItem();
    }
  });
  root.addEventListener('keydown', function (ev) {
    var t = ev.target;
    if (t.hasAttribute && t.hasAttribute('data-dy-tagin') && (ev.key === 'Enter' || ev.key === ',')) {
      ev.preventDefault(); var tag = normTag(t.value); t.value = '';
      if (tag && S.entry && S.entry.tags.indexOf(tag) === -1 && S.entry.tags.length < MAX_TAGS) setTags(S.entry.tags.concat([tag]), true);
      return;
    }
    if (S.menu && (ev.key === 'ArrowDown' || ev.key === 'ArrowUp')) {
      var items = qa('.avdy-menu button:not([disabled])'); var i = items.indexOf(document.activeElement);
      if (items.length) { ev.preventDefault(); items[(i + (ev.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length].focus(); }
      return;
    }
    if (t.hasAttribute && t.hasAttribute('data-dy-body') && (ev.metaKey || ev.ctrlKey) && /^[biu]$/i.test(ev.key)) {
      ev.preventDefault(); document.execCommand({ b: 'bold', i: 'italic', u: 'underline' }[ev.key.toLowerCase()]); t.dispatchEvent(new Event('input', { bubbles: true }));
    }
    if (t.hasAttribute && t.hasAttribute('data-dy-title') && ev.key === 'Enter') { ev.preventDefault(); var bd = q('[data-dy-body]'); if (bd) bd.focus(); }
  });
  /* The formatting bar shows while the body has focus and acts on mousedown so
     the selection survives. */
  root.addEventListener('focusin', function (ev) { if (ev.target.hasAttribute && ev.target.hasAttribute('data-dy-body')) { var f = q('[data-dy-fmt]'); if (f) f.hidden = false; } });
  root.addEventListener('focusout', function (ev) {
    if (!ev.target.hasAttribute || !ev.target.hasAttribute('data-dy-body')) return;
    setTimeout(function () { var f = q('[data-dy-fmt]'); if (f && !f.contains(document.activeElement) && document.activeElement !== q('[data-dy-body]')) f.hidden = true; }, 150);
  });
  root.addEventListener('mousedown', function (ev) {
    var b = ev.target.closest('[data-dy-tool]'); if (!b) return;
    ev.preventDefault(); var tl = TOOLS[+b.getAttribute('data-dy-tool')], bd = q('[data-dy-body]');
    if (!bd || S.readOnly) return;
    if (document.activeElement !== bd) bd.focus();
    document.execCommand(tl[2], false, tl[3]); bd.dispatchEvent(new Event('input', { bubbles: true }));
  });
  /* Keyboard users reach the bar with Tab from the body; Enter/Space press it. */
  root.addEventListener('keydown', function (ev) {
    var b = ev.target.closest && ev.target.closest('[data-dy-tool]'); if (!b || (ev.key !== 'Enter' && ev.key !== ' ')) return;
    ev.preventDefault(); var tl = TOOLS[+b.getAttribute('data-dy-tool')], bd = q('[data-dy-body]'); if (!bd) return;
    bd.focus(); document.execCommand(tl[2], false, tl[3]); bd.dispatchEvent(new Event('input', { bubbles: true }));
  });
  document.addEventListener('mousedown', function (ev) { if (S.menu && !root.contains(ev.target)) closeMenu(false); });
  /* Escape closes the top layer only — dialog, then menu, then the library drawer.
     On the document, because a button that was busy may have let focus fall to the body. */
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape' || !started) return;
    var view = root.closest('.pview'); if (view && view.hidden) return;
    if (!q('[data-dy-modal]').hidden) { ev.preventDefault(); return closeModal(); }
    if (S.menu) { ev.preventDefault(); return closeMenu(true); }
    if (S.libOpen) { ev.preventDefault(); closeLib(); q('[data-dy-libopen]').focus(); }
  });

  /* ── Small operations ──────────────────────────────────────────────── */
  function setTags(tags, refocus) {
    var E = S.entry; E.tags = tags; renderEditor(); if (refocus) { var ti = q('[data-dy-tagin]'); if (ti) ti.focus(); }
    if (!E.id) return;
    nbPost('tags', { entry_id: E.id, tags: tags }).then(function (d) {
      if (!d.ok) { S.saveErr = d.error || 'Tags not saved.'; paintBar(); return; }
      E.tags = d.tags || tags; refreshItem(); bumpCounts();
    });
  }
  function addTab() {
    var E = S.entry; if (!E.id) { S.saveErr = 'Write a line first — then add tabs.'; paintBar(); return; }
    if (S.tabs.length >= MAX_TABS) return;
    flushNow().then(function () {
      nbPost('tab_add', { entry_id: E.id, title: S.tabs.length === 1 && S.tabs[0].title === 'Entry' ? 'Tab 2' : 'Tab ' + (S.tabs.length + 1), body: '' }).then(function (d) {
        if (!d.ok) { S.saveErr = d.error || 'Could not add a tab.'; paintBar(); return; }
        S.tabs.push({ id: d.tab.id, title: d.tab.title, body: '' }); E.tab_count = S.tabs.length; S.ti = S.tabs.length - 1;
        renderEditor(); refreshItem(); var bd = q('[data-dy-body]'); if (bd) bd.focus();
      });
    });
  }
  function delTab() {
    var E = S.entry, t = S.tabs[S.ti]; if (!t || !t.id) return;
    nbPost('tab_delete', { entry_id: E.id, tab_id: t.id }).then(function (d) {
      if (!d.ok) { S.saveErr = d.error || 'Could not remove the tab.'; paintBar(); return; }
      S.tabs.splice(S.ti, 1); S.ti = Math.max(0, S.ti - 1); E.tab_count = S.tabs.length; renderEditor(); refreshItem();
    });
  }
  function exportMd() {
    var E = S.entry, lines = ['# ' + (E.title || 'Untitled'), '', '_' + fmtLong(E.entry_date) + '_', ''];
    S.tabs.forEach(function (t, i) {
      if (S.tabs.length > 1) lines.push('## ' + t.title, '');
      var d = document.createElement('div'); d.innerHTML = t.body || '';
      d.querySelectorAll('h1,h2,h3').forEach(function (n) { n.textContent = '\n### ' + n.textContent + '\n'; });
      d.querySelectorAll('li').forEach(function (n) { n.textContent = '\n- ' + n.textContent; });
      d.querySelectorAll('p,div,blockquote,br').forEach(function (n) { n.insertAdjacentText('afterend', '\n'); });
      lines.push(d.textContent.replace(/\n{3,}/g, '\n\n').trim(), '');
    });
    var blob = new Blob([lines.join('\n')], { type: 'text/markdown' }), a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = (E.title || 'diary-entry').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '.md';
    document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }

  /* ── Start when the Diary view first opens ─────────────────────────── */
  var started = false;
  function start() {
    if (started) return; started = true;
    loadNbs().then(function () { return loadShared(); }).then(function () { renderHead(); loadList(false); });
  }
  var view = root.closest('.pview');
  if (view && !view.hidden) start();
  document.addEventListener('portal:view', function (e) { if (e.detail && e.detail.view === 'diary') start(); });
  if (location.hash === '#diary') start();
})();
