/* assets/site/avsearch.js — the site search palette (styles: avsearch.css).
 *
 * Opened from the header's "Search…" control (SiteSearch::trigger() — a link
 * to /search.php, so it works without JavaScript), Ctrl+K / ⌘K from anywhere,
 * or "/" when the visitor is not typing in a field. A page with its own
 * Ctrl+K (the member portal's command palette) says so with
 * <html data-own-search-keys>, and keeps its keys; its header control still
 * opens this.
 *
 * The WAI-ARIA combobox pattern: the input is role=combobox and owns one
 * role=listbox; the options stay out of the tab order and the input keeps
 * focus, pointing at the active option with aria-activedescendant. ↑/↓ move,
 * Enter opens (with nothing chosen it submits the form: the full results
 * page), Esc closes, Tab goes round the dialog's own controls, and focus goes
 * back to whatever opened it. Result counts are announced politely.
 *
 * Results come from /search.php (lib/SiteSearch.php) as you type, 150ms after
 * the last key; a newer query aborts the one in flight. Recent searches live
 * in this browser only (localStorage av.search.recent) and can be cleared.
 * "Ask Chioma" is a row the visitor chooses, never automatic.
 * Every string from the server is escaped before it is drawn.
 */
(function () {
  'use strict';
  if (window.__avSearch) return; window.__avSearch = true;

  var RECENT = 'av.search.recent', MAX_RECENT = 6;
  var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
  var TYPES = [
    ['', 'All'], ['Page', 'Pages'], ['Academy', 'Academy'], ['Project', 'Projects'], ['Event', 'Events'],
    ['Diary', 'Diary'], ['People', 'People']
  ];
  var GROUP = { Page: 'Pages', Academy: 'Academy courses', Project: 'Projects', Event: 'Events', Diary: 'Diary', People: 'People', Constitution: 'Constitution' };
  var ORDER = ['Page', 'Academy', 'Project', 'Event', 'Diary', 'People', 'Constitution'];
  var POPULAR = [
    ['Academy', '/academy/', 'Free, hands-on programmes', 'Academy'],
    ['NextGen Vanguard', '/academy/ngv/', 'Our flagship learn-and-earn programme', 'Academy'],
    ['Projects', '/projects/', 'Our seven projects', 'Project'],
    ['Events', '/events/', 'What is coming up', 'Event'],
    ['The Diary', '/diary/', 'Field notes and stories', 'Diary'],
    ['Donate', '/donate.html', 'Give funds, materials, skills or time', 'Page']
  ];
  var BROWSE = [
    ['Browse the Diary', '/diary/', 'Diary'], ['Browse the Academy', '/academy/', 'Academy'],
    ['Browse projects', '/projects/', 'Project'], ['See upcoming events', '/events/', 'Event']
  ];

  var P = 'fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"';
  function svg(d, cls) { return '<svg class="' + (cls || 'avs-i') + '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g ' + P + '>' + d + '</g></svg>'; }
  var ICON = {
    Page: '<path d="M7 3.5h7l4 4V20a.5.5 0 0 1-.5.5h-10A.5.5 0 0 1 7 20z"/><path d="M14 3.5V8h4M9.5 12.5h5M9.5 16h5"/>',
    Academy: '<path d="M2.5 9 12 4.5 21.5 9 12 13.5z"/><path d="M6.5 11v4.5c1.5 1.5 3.5 2.2 5.5 2.2s4-.7 5.5-2.2V11M21.5 9v5"/>',
    Project: '<path d="m12 3.5 8.5 4.5-8.5 4.5L3.5 8z"/><path d="m3.5 12 8.5 4.5 8.5-4.5M3.5 16l8.5 4.5 8.5-4.5"/>',
    Event: '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    Diary: '<path d="M12 6.5c-2-1.5-4.5-2-8.5-2v14c4 0 6.5.5 8.5 2 2-1.5 4.5-2 8.5-2v-14c-4 0-6.5.5-8.5 2zM12 6.5v14"/>',
    People: '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20c.8-3.6 3.6-5.5 7-5.5s6.2 1.9 7 5.5"/>',
    Constitution: '<path d="M12 4v16M7 20h10M5 7h14M5 7l-2.5 6a2.5 2.5 0 0 0 5 0zM19 7l-2.5 6a2.5 2.5 0 0 0 5 0z"/>',
    recent: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    ask: '<path d="M12 3.5c.6 3.9 2.6 5.9 6.5 6.5-3.9.6-5.9 2.6-6.5 6.5-.6-3.9-2.6-5.9-6.5-6.5 3.9-.6 5.9-2.6 6.5-6.5z"/><path d="M18.5 15.5c.3 1.6 1 2.3 2.5 2.5-1.5.3-2.2 1-2.5 2.5-.3-1.5-1-2.2-2.5-2.5 1.5-.2 2.2-.9 2.5-2.5z"/>',
    clear: '<path d="M7 7l10 10M17 7 7 17"/>',
    search: '<circle cx="10.75" cy="10.75" r="6.25"/><path d="m15.5 15.5 4.75 4.75"/>',
    enter: '<path d="M9 10 5 14l4 4"/><path d="M5 14h11a3 3 0 0 0 3-3V6"/>',
    ext: '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>'
  };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fold(s) { s = String(s || '').toLowerCase(); try { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); } catch (e) {} return s; }
  function terms(q) { return fold(q).split(/[^\p{L}\p{N}]+/u).filter(function (t) { return t.length >= 2; }); }
  /* Mark the starts of words that match the query: the text is split, every
     piece escaped, and only the matching pieces wrapped. */
  function hilite(text, q) {
    text = String(text == null ? '' : text);
    var ts = terms(q); if (!ts.length) return esc(text);
    ts.sort(function (a, b) { return b.length - a.length; });
    var src = fold(text), out = '', i = 0;
    if (src.length !== text.length) return esc(text); // folding changed the length: draw it plain rather than mis-mark it
    var rx = new RegExp('(^|[^\\p{L}\\p{N}])(' + ts.map(function (t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('|') + ')', 'gu');
    var m;
    while ((m = rx.exec(src))) {
      var s = m.index + m[1].length, e = s + m[2].length;
      out += esc(text.slice(i, s)) + '<mark>' + esc(text.slice(s, e)) + '</mark>';
      i = e; if (m[0].length === 0) rx.lastIndex++;
    }
    return out + esc(text.slice(i));
  }
  function lsGet() { try { var v = JSON.parse(localStorage.getItem(RECENT) || '[]'); return Array.isArray(v) ? v.filter(function (x) { return typeof x === 'string' && x.length >= 2 && x.length <= 120; }).slice(0, MAX_RECENT) : []; } catch (e) { return []; } }
  function lsSet(list) { try { if (list.length) localStorage.setItem(RECENT, JSON.stringify(list)); else localStorage.removeItem(RECENT); } catch (e) {} }
  function remember(q) { q = String(q || '').trim().slice(0, 120); if (q.length < 2) return; var l = lsGet().filter(function (x) { return x.toLowerCase() !== q.toLowerCase(); }); l.unshift(q); lsSet(l.slice(0, MAX_RECENT)); }
  function external(u) { return /^https?:\/\//i.test(u) && u.indexOf(location.origin + '/') !== 0; }

  /* ── The dialog ───────────────────────────────────────────────────── */
  var dlg, form, input, list, chips, live, bar, foot, seeAll, aiBox, opener = null;
  var state = { q: '', type: '', active: -1, items: [], loading: false, seq: 0 };
  var ctrl = null, timer = null, cache = {};

  function build() {
    dlg = document.createElement('dialog');
    dlg.className = 'avs';
    dlg.id = 'av-search';
    dlg.setAttribute('aria-label', 'Search Afrovanguard');
    var chipHtml = TYPES.map(function (t, i) {
      return '<button type="button" class="avs-chip" data-type="' + t[0] + '" aria-pressed="' + (i ? 'false' : 'true') + '" tabindex="' + (i ? '-1' : '0') + '">' + t[1] + '</button>';
    }).join('');
    dlg.innerHTML =
      '<form class="avs-bar" role="search" action="/search.php" method="get">'
      + svg(ICON.search, 'avs-bar-i')
      + '<input class="avs-q" type="search" name="q" maxlength="120" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search"'
      + ' role="combobox" aria-expanded="false" aria-controls="avs-list" aria-autocomplete="list" aria-label="Search Afrovanguard" aria-describedby="avs-help" placeholder="Search pages, courses, projects, events…">'
      + '<input type="hidden" name="type" value="" disabled>'
      + '<button type="button" class="avs-clear" data-avs-clear aria-label="Clear the search" hidden>' + svg(ICON.clear) + '</button>'
      + '<button type="button" class="avs-x" data-avs-close><span class="avs-x-k" aria-hidden="true">Esc</span><span class="avs-x-t">Cancel</span><span class="av-sr"> search</span></button>'
      + '</form>'
      + '<div class="avs-progress" aria-hidden="true"></div>'
      + '<div class="avs-chips" role="toolbar" aria-label="Show only" aria-orientation="horizontal">' + chipHtml + '</div>'
      + '<div class="avs-body" tabindex="0" role="region" aria-label="Results">'
      + '<div class="avs-ai" data-avs-ai hidden></div>'
      + '<div class="avs-note" data-avs-note hidden></div>'
      + '<div class="avs-list" id="avs-list" role="listbox" aria-label="Search results"></div>'
      + '</div>'
      + '<div class="avs-foot"><p class="avs-keys" id="avs-help"><span><kbd>↑</kbd><kbd>↓</kbd> to move</span><span><kbd>↵</kbd> to open</span><span><kbd>Esc</kbd> to close</span></p>'
      + '<a class="avs-all" data-avs-all href="/search.php">Open the search page' + svg(ICON.arrow) + '</a></div>'
      + '<p class="av-sr" role="status" aria-live="polite" data-avs-live></p>';
    document.body.appendChild(dlg);
    form = dlg.querySelector('form'); input = dlg.querySelector('.avs-q'); list = dlg.querySelector('#avs-list');
    chips = dlg.querySelector('.avs-chips'); live = dlg.querySelector('[data-avs-live]'); bar = dlg.querySelector('.avs-progress');
    seeAll = dlg.querySelector('[data-avs-all]'); aiBox = dlg.querySelector('[data-avs-ai]'); foot = dlg.querySelector('.avs-foot');

    input.addEventListener('input', onInput);
    input.addEventListener('keydown', onKey);
    form.addEventListener('submit', function (e) {
      var q = input.value.trim();
      if (q.length < 2) { e.preventDefault(); return; }
      remember(q);
      var h = form.querySelector('input[name=type]'); h.value = state.type; h.disabled = !state.type;
    });
    dlg.querySelector('[data-avs-clear]').addEventListener('click', function () { input.value = ''; onInput(); input.focus(); });
    dlg.querySelector('[data-avs-close]').addEventListener('click', close);
    dlg.addEventListener('close', restore);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });   // the backdrop
    dlg.addEventListener('keydown', trap);
    chips.addEventListener('click', function (e) { var b = e.target.closest('.avs-chip'); if (b) pick(b); });
    chips.addEventListener('keydown', function (e) {
      var all = [].slice.call(chips.querySelectorAll('.avs-chip')), i = all.indexOf(document.activeElement), n = -1;
      if (e.key === 'ArrowRight') n = (i + 1) % all.length; else if (e.key === 'ArrowLeft') n = (i - 1 + all.length) % all.length;
      else if (e.key === 'Home') n = 0; else if (e.key === 'End') n = all.length - 1;
      if (n < 0) return;
      e.preventDefault(); all.forEach(function (b, j) { b.tabIndex = j === n ? 0 : -1; }); all[n].focus();
    });
    list.addEventListener('mousemove', function (e) { var o = e.target.closest('[role=option]'); if (o) setActive(+o.getAttribute('data-i'), false); });
    list.addEventListener('mousedown', function (e) { if (e.target.closest('[role=option]')) e.preventDefault(); }); // keep focus in the field
    list.addEventListener('click', function (e) {
      var o = e.target.closest('[role=option]'); if (!o) return;
      var it = state.items[+o.getAttribute('data-i')]; if (!it) return;
      if (it.href && !it.act && (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1)) { remember(state.q); return; } // a new tab: let the link do it
      e.preventDefault(); activate(it);
    });
  }

  /* Tab and Shift+Tab stay in the dialog: the field, its clear button, the
     filters (one stop; ←/→ inside), the page link, close. */
  function trap(e) {
    if (e.key !== 'Tab') return;
    var f = [].filter.call(dlg.querySelectorAll('input:not([type=hidden]),button,a[href],[tabindex="0"]'), function (x) {
      return !x.disabled && x.tabIndex >= 0 && x.getClientRects().length && !x.closest('[role=listbox]');
    });
    if (!f.length) return;
    var i = f.indexOf(document.activeElement), n = e.shiftKey ? (i <= 0 ? f.length - 1 : i - 1) : (i === f.length - 1 ? 0 : i + 1);
    e.preventDefault(); f[n].focus();
  }

  function pick(b) {
    state.type = b.getAttribute('data-type');
    [].forEach.call(chips.querySelectorAll('.avs-chip'), function (c) { var on = c === b; c.setAttribute('aria-pressed', on ? 'true' : 'false'); c.tabIndex = on ? 0 : -1; });
    if (state.q.length >= 2) run(true); else render();
    input.focus();
  }

  function onInput() {
    var q = input.value.replace(/\s+/g, ' ').trim().slice(0, 120);
    dlg.querySelector('[data-avs-clear]').hidden = !input.value;
    state.q = q;
    aiBox.hidden = true; aiBox.innerHTML = '';
    clearTimeout(timer);
    if (q.length < 2) { abort(); state.loading = false; loading(false); render(); return; }
    var key = state.type + '|' + q.toLowerCase();
    if (cache[key]) { show(cache[key]); return; }   // backspacing through what was typed: no wait
    timer = setTimeout(function () { run(false); }, 150);
  }
  function abort() { if (ctrl) { try { ctrl.abort(); } catch (e) {} ctrl = null; } }
  function loading(on) { state.loading = on; dlg.classList.toggle('is-loading', on); list.setAttribute('aria-busy', on ? 'true' : 'false'); }

  function run(now) {
    var q = state.q, type = state.type, key = type + '|' + q.toLowerCase();
    if (q.length < 2) return;
    if (cache[key]) { show(cache[key]); return; }
    abort();
    var seq = ++state.seq;
    ctrl = window.AbortController ? new AbortController() : null;
    loading(true);
    if (!state.items.length || now) render('loading');
    fetch('/search.php?format=json&q=' + encodeURIComponent(q) + (type ? '&type=' + encodeURIComponent(type) : ''), {
      credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: ctrl ? ctrl.signal : undefined
    }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) {
        if (seq !== state.seq) return;
        var res = { q: q, results: (d && Array.isArray(d.results)) ? d.results : [], suggest: d && typeof d.suggest === 'string' ? d.suggest : '' };
        cache[key] = res; var ks = Object.keys(cache); if (ks.length > 40) delete cache[ks[0]];
        loading(false); show(res);
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        if (seq !== state.seq) return;
        loading(false); render('error');
      });
  }
  function show(res) { if (res.q !== state.q) return; loading(false); render('results', res); }

  /* ── Drawing ──────────────────────────────────────────────────────── */
  function option(it, i, q) {
    var tile = '<span class="avs-tile avs-tile--' + esc(it.kind || 'Page') + '">' + svg(ICON[it.icon] || ICON.Page) + '</span>';
    var sub = '';
    if (it.crumb || it.excerpt) sub = '<span class="avs-sub">' + (it.crumb ? '<span class="avs-crumb">' + esc(it.crumb) + '</span>' : '')
      + (it.crumb && it.excerpt ? '<span class="avs-dot" aria-hidden="true">·</span>' : '') + (it.excerpt ? '<span class="avs-ex">' + hilite(it.excerpt, q) + '</span>' : '') + '</span>';
    var ext = it.href && external(it.href);
    var tag = it.href ? 'a' : 'div';
    return '<' + tag + ' class="avs-opt' + (it.quiet ? ' avs-opt--quiet' : '') + (it.kind === 'recent' ? ' avs-opt--recent' : '') + '" role="option" id="avs-o' + i + '" data-i="' + i + '" aria-selected="false" tabindex="-1"'
      + (it.href ? ' href="' + esc(it.href) + '"' + (ext ? ' target="_blank" rel="noopener"' : '') : '') + '>'
      + tile + '<span class="avs-txt"><span class="avs-title">' + (it.raw ? it.title : hilite(it.title, q)) + (ext ? '<span class="av-sr"> (opens in a new tab)</span>' : '') + '</span>' + sub + '</span>'
      + '<span class="avs-go" aria-hidden="true">' + svg(ext ? ICON.ext : ICON.enter) + '</span></' + tag + '>';
  }
  function group(id, label, items, q, start, action) {
    var h = '<div class="avs-group" role="group" aria-labelledby="avs-g-' + id + '"><div class="avs-gh" id="avs-g-' + id + '" role="presentation">' + esc(label) + '</div>';
    items.forEach(function (it, j) { h += option(it, start + j, q); });
    return h + '</div>';
  }

  function render(mode, res) {
    var q = state.q, h = '', items = [], note = dlg.querySelector('[data-avs-note]');
    note.hidden = true; note.innerHTML = '';
    function push(arr) { var s = items.length; items = items.concat(arr); return s; }
    if (q.length < 2) {
      var rec = lsGet();
      if (rec.length) {
        var r = rec.map(function (x) { return { title: x, icon: 'recent', kind: 'recent', q: x }; });
        r.push({ title: 'Clear recent searches', icon: 'clear', kind: 'recent', act: 'clear', quiet: true });
        h += group('recent', 'Recent searches', r, '', push(r));
      }
      var pop = POPULAR.filter(function (p) { return !state.type || p[3] === state.type; }).map(function (p) { return { title: p[0], href: p[1], excerpt: p[2], icon: p[3], kind: p[3] }; });
      if (!pop.length) pop = POPULAR.map(function (p) { return { title: p[0], href: p[1], excerpt: p[2], icon: p[3], kind: p[3] }; });
      h += group('popular', 'Popular', pop, '', push(pop));
      seeAll.innerHTML = 'Open the search page' + svg(ICON.arrow); seeAll.href = '/search.php';
      say('');
    } else if (mode === 'loading') {
      h = '<div class="avs-skel" aria-hidden="true"><span></span><span></span><span></span></div>';
      say('Searching…');
    } else if (mode === 'error') {
      note.hidden = false;
      note.innerHTML = '<p class="avs-note-h">Search is unavailable right now.</p><p>Press Enter to try the search page, or try again in a moment.</p>';
      say('Search is unavailable right now.');
    } else if (res) {
      var by = {};
      res.results.forEach(function (r) { (by[r.type] = by[r.type] || []).push(r); });
      ORDER.concat(Object.keys(by).filter(function (k) { return ORDER.indexOf(k) < 0; })).forEach(function (t) {
        if (!by[t]) return;
        var g = by[t].map(function (r) { return { title: r.title, href: r.url, excerpt: r.excerpt, crumb: r.crumb || GROUP[r.type] || r.type, icon: r.type, kind: r.type }; });
        h += group(t.toLowerCase(), GROUP[t] || t, g, q, push(g));
      });
      if (!res.results.length) {
        note.hidden = false;
        note.innerHTML = '<p class="avs-note-h">No results for “' + esc(q) + '”' + (state.type ? ' in ' + esc(GROUP[state.type] || state.type) : '') + '</p>'
          + (res.suggest ? '<p>Did you mean <button type="button" class="avs-sugg" data-q="' + esc(res.suggest) + '">' + esc(res.suggest) + '</button>?</p>' : '<p>Check the spelling, or try a broader word.</p>');
        var sug = note.querySelector('.avs-sugg');
        if (sug) sug.addEventListener('click', function () { input.value = sug.getAttribute('data-q'); onInput(); input.focus(); });
        var b = BROWSE.map(function (x) { return { title: x[0], href: x[1], icon: x[2], kind: x[2] }; });
        h += group('browse', 'Browse instead', b, '', push(b));
      }
      var ask = [{ title: 'Ask Chioma about “' + esc(q) + '”', raw: true, excerpt: 'Our assistant answers from what the site says', icon: 'ask', kind: 'ask', act: 'ask' }];
      h += group('ask', 'Ask', ask, '', push(ask));
      var n = res.results.length;
      say(n ? n + (n === 1 ? ' result' : ' results') + (n >= 12 ? ' shown. Press Enter for all results.' : '') : 'No results.' + (res.suggest ? ' Did you mean ' + res.suggest + '?' : ''));
      seeAll.innerHTML = 'See all results for “' + esc(q) + '”' + svg(ICON.arrow);
      seeAll.href = '/search.php?q=' + encodeURIComponent(q) + (state.type ? '&type=' + encodeURIComponent(state.type) : '');
    }
    state.items = items;
    list.innerHTML = h;
    list.hidden = !items.length;
    input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
    /* The first real result is ready for Enter as soon as it appears. */
    setActive(q.length >= 2 && res && res.results.length ? 0 : -1, false);
  }

  function setActive(i, scroll) {
    state.active = i;
    [].forEach.call(list.querySelectorAll('[role=option]'), function (o) {
      var on = +o.getAttribute('data-i') === i;
      o.setAttribute('aria-selected', on ? 'true' : 'false');
      o.classList.toggle('is-active', on);
      if (on && scroll !== false) { try { o.scrollIntoView({ block: 'nearest' }); } catch (e) {} }
    });
    if (i >= 0) input.setAttribute('aria-activedescendant', 'avs-o' + i); else input.removeAttribute('aria-activedescendant');
  }

  function onKey(e) {
    var n = state.items.length;
    /* A search field clears itself on Escape and keeps the dialog open; here
       Escape means close, as everywhere else in the palette. */
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!n) return;
      e.preventDefault();
      var d = e.key === 'ArrowDown' ? 1 : -1, i = state.active;
      i = i < 0 ? (d > 0 ? 0 : n - 1) : (i + d + n) % n;
      setActive(i, true);
    } else if (e.key === 'Enter' && !e.isComposing) {
      var it = state.items[state.active];
      if (it) { e.preventDefault(); activate(it, e.ctrlKey || e.metaKey); }
      /* nothing chosen: the form submits — the full results page */
    }
  }

  function activate(it, newTab) {
    if (it.act === 'clear') { lsSet([]); render(); say('Recent searches cleared.'); input.focus(); return; }
    if (it.act === 'ask') { ask(state.q); return; }
    if (it.q) { input.value = it.q; onInput(); input.focus(); return; }
    if (state.q.length >= 2) remember(state.q);
    if (!it.href) return;
    if (newTab || external(it.href)) { window.open(it.href, '_blank', 'noopener'); return; }
    location.href = it.href;
  }

  /* "Ask Chioma": the assistant's own panel when this page has it; otherwise a
     short answer here, from the same grounded endpoint. Only when chosen. */
  function ask(q) {
    if (q.length < 2) return;
    if (window.chioma && typeof window.chioma.open === 'function') { close(); window.chioma.open(q); return; }
    aiBox.hidden = false;
    aiBox.innerHTML = '<p class="avs-ai-h">' + svg(ICON.ask) + 'Chioma</p><p class="avs-ai-t" aria-live="polite">Thinking…</p>';
    fetch('/search.php?format=json&ai=1&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var t = aiBox.querySelector('.avs-ai-t'), ai = d && d.ai;
        if (ai && ai.ok && ai.text) t.textContent = ai.text;
        else if (ai && ai.limited) t.textContent = 'Chioma has answered a lot of questions just now. The results below still work, or try again in a few minutes.';
        else if (ai && ai.configured === false) t.textContent = 'Chioma is not switched on here yet. Try the results below, or contact us.';
        else t.textContent = 'Chioma could not answer that just now. Try the results below.';
      }).catch(function () { var t = aiBox.querySelector('.avs-ai-t'); if (t) t.textContent = 'Chioma could not answer that just now. Try the results below.'; });
  }

  function say(t) { if (!live) return; clearTimeout(say.t); say.t = setTimeout(function () { live.textContent = t; }, t ? 450 : 0); }

  /* On a phone the palette is the whole screen, and it follows the VISUAL
     viewport while open: iOS keeps the layout viewport full height under the
     on-screen keyboard, which would hide the results behind the keys. */
  var SHEET_MQ = window.matchMedia ? window.matchMedia('(max-width: 639px)') : null;
  var vv = window.visualViewport;
  function fit() {
    if (!dlg) return;
    if (!vv || !dlg.open || !SHEET_MQ || !SHEET_MQ.matches) { dlg.style.removeProperty('--avs-vv-h'); dlg.style.removeProperty('--avs-vv-top'); return; }
    dlg.style.setProperty('--avs-vv-h', Math.round(vv.height) + 'px');
    dlg.style.setProperty('--avs-vv-top', Math.round(vv.offsetTop) + 'px');
  }
  if (vv) { vv.addEventListener('resize', fit); vv.addEventListener('scroll', fit); }

  function open(from) {
    if (!dlg) build();
    if (dlg.open) { input.focus(); input.select(); return; }
    if (typeof dlg.showModal !== 'function') { location.href = '/search.php'; return; }
    opener = from || document.activeElement;
    dlg.showModal();
    fit();
    document.documentElement.classList.add('avs-open');
    onInput();
    input.focus(); input.select();
    document.querySelectorAll('[data-search-open][aria-expanded]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
  }
  function close() { if (dlg && dlg.open) dlg.close(); }
  function restore() {
    fit();
    abort(); clearTimeout(timer); loading(false);
    document.documentElement.classList.remove('avs-open');
    document.querySelectorAll('[data-search-open][aria-expanded]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    var o = opener; opener = null;
    if (o && o.isConnected && o.getClientRects && !o.getClientRects().length && o.hasAttribute && o.hasAttribute('data-search-open')) {
      o = [].filter.call(document.querySelectorAll('[data-search-open]'), function (b) { return b.getClientRects().length; })[0] || o;
    }
    if (o && o.focus && o.isConnected) { try { o.focus({ preventScroll: true }); } catch (e) {} }
  }

  /* ── The ways in ──────────────────────────────────────────────────── */
  function typing(t) {
    t = t || document.activeElement;
    return !!(t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)));
  }
  /* A page with its own Ctrl+K and "/" keeps them: <html data-own-search-keys>,
     or one of the palettes the site already has (portal, Studio, admin). */
  function ownKeys() {
    return document.documentElement.hasAttribute('data-own-search-keys')
      || !!document.querySelector('script[src*="/portal/palette.js"],script[src*="/studio/studio.js"],script[src*="/admin/app.js"]');
  }
  function upgrade() {
    var key = mac ? '⌘K' : 'Ctrl K';
    if (!document.querySelector('[data-search-open]')) {
      /* A page drawn without a shared header that has the control still gets one. */
      var host = document.querySelector('body > header, header[role="banner"], .avsi-head, main header, header');
      if (host) {
        var a = document.createElement('a');
        a.className = 'av-st av-st--icon av-st--added'; a.href = '/search.php'; a.setAttribute('data-search-open', '');
        a.innerHTML = '<svg class="av-st-ico" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><g ' + P + '>' + ICON.search + '</g></svg><span class="av-sr">Search Afrovanguard</span>';
        host.appendChild(a);
      }
    }
    var own = ownKeys();
    document.querySelectorAll('a[data-search-open]').forEach(function (a) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = a.className; b.setAttribute('data-search-open', '');
      b.setAttribute('aria-haspopup', 'dialog'); b.setAttribute('aria-expanded', 'false'); b.setAttribute('aria-controls', 'av-search');
      if (!own) b.setAttribute('aria-keyshortcuts', (mac ? 'Meta+K' : 'Control+K') + ' /');
      if (a.getAttribute('aria-label')) b.setAttribute('aria-label', a.getAttribute('aria-label'));
      if (a.id) b.id = a.id;
      b.innerHTML = a.innerHTML;
      a.parentNode.replaceChild(b, a);
    });
    document.querySelectorAll('.av-st-k').forEach(function (k) { if (own) k.remove(); else k.textContent = key; });
    /* The search page's "Ask Chioma" row: a button, so only with JavaScript. */
    document.querySelectorAll('[data-avs-ask-row]').forEach(function (r) { r.hidden = false; });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-avs-ask]') : null;
    if (!b) return;
    var q = (b.getAttribute('data-avs-ask') || '').slice(0, 120);
    if (window.chioma && typeof window.chioma.open === 'function') { window.chioma.open(q); return; }
    open(b); input.value = q; onInput(); ask(q);
  });
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-search-open]') : null;
    if (!t || e.defaultPrevented || e.button > 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault(); open(t);
  });
  document.addEventListener('keydown', function (e) {
    if (ownKeys()) return;
    var other = document.querySelector('dialog[open]:not(#av-search)');
    if ((e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey && (e.key === 'k' || e.key === 'K')) {
      if (other) return;
      e.preventDefault();
      if (dlg && dlg.open) close(); else open(null);
      return;
    }
    if (e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey && !typing(e.target) && !other && !(dlg && dlg.open)) {
      e.preventDefault(); open(null);
    }
  });

  window.AvSearch = { open: function () { open(null); }, close: close };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', upgrade); else upgrade();
})();
