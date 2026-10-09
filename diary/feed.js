/* ============================================================
   diary/feed.js — progressive loading + filtering for the Diary index.

   Owns filter state (year / month / category / search) and pagination.
   The first page is server-rendered into #journeyList (for SEO + no-JS);
   this controller fetches further pages from /diary/api.php?action=list,
   appends <li> markup identical to the SSR markup, and fires a
   `diary:changed` event so journey.js rebuilds the canvas map. The map
   asks for more by firing `diary:mapnearend` when you pan near the end.

   No dependencies. Degrades gracefully: with JS off, the SSR'd first page
   and the RSS feed still work.
   ============================================================ */
(function () {
  'use strict';
  var controls = document.getElementById('diaryControls');
  var list     = document.getElementById('journeyList');
  if (!controls || !list) return; // only on the diary index

  var PER   = parseInt(controls.getAttribute('data-per') || '12', 10) || 12;
  var TOTAL = parseInt(controls.getAttribute('data-total') || '0', 10) || 0;

  var facets = {};
  try { facets = JSON.parse((document.getElementById('diaryFacets') || {}).textContent || '{}'); } catch (e) {}

  var searchInput = document.getElementById('diarySearch');
  var yearSel  = document.getElementById('diaryYear');
  var monthSel = document.getElementById('diaryMonth');
  var chips    = [].slice.call(document.querySelectorAll('.diary-filters .chip'));
  var foot     = document.getElementById('diaryFeedFoot');
  var loadBtn  = document.getElementById('diaryLoadMore');
  var sentinel = document.getElementById('diarySentinel');
  var skeleton = document.getElementById('diarySkeleton');
  var countEl  = document.getElementById('journeyCount');
  var noRes    = document.querySelector('.no-results');

  var MONTHS = ['', 'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'];

  /* Initial filter state is read from the URL so a filtered view is
     shareable, bookmarkable, and survives back/forward. ?q= is also honoured
     by the server (it prefills the input), so we mirror it here. */
  function readUrlState() {
    var p = new URLSearchParams(location.search);
    var m = (p.get('month') || '').replace(/\D/g, '');
    if (m.length === 1) m = '0' + m;
    return {
      year:  (p.get('year') || '').replace(/\D/g, '').slice(0, 4),
      month: m.slice(0, 2),
      cat:   (p.get('cat') || 'all').replace(/[^a-z0-9\-]/gi, '').toLowerCase() || 'all',
      q:     (p.get('q') || (searchInput && searchInput.value.trim()) || ''),
      // The sort lives in the URL so the progressive pages that load after the
      // first are sorted the same way the first one was.
      sort:  ['latest', 'read', 'discussed'].indexOf(p.get('sort') || '') !== -1 ? p.get('sort') : 'latest'
    };
  }

  var state = readUrlState();
  var offset = 0, total = TOTAL, loading = false, done = false, savedMode = false;
  var syncingUrl = false;

  /* Push the current filter state to the URL (replaceState — no history spam
     while typing; pushState only on discrete control changes). */
  function writeUrlState(push) {
    if (syncingUrl) return;
    var p = new URLSearchParams();
    if (state.q)     p.set('q', state.q);
    if (state.year)  p.set('year', state.year);
    if (state.month) p.set('month', state.month);
    if (state.cat && state.cat !== 'all') p.set('cat', state.cat);
    var qs = p.toString();
    var url = location.pathname + (qs ? '?' + qs : '') + location.hash;
    try { history[push ? 'pushState' : 'replaceState'](null, '', url); } catch (e) {}
  }

  function savedSet() { try { return JSON.parse(localStorage.getItem('av.saved') || '[]'); } catch (e) { return []; } }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function hasFilter() { return !!(state.year || state.month || (state.cat && state.cat !== 'all' && state.cat !== 'saved') || state.q); }

  /* Build one <li> — MUST match the SSR markup in index.php so journey.js
     readNodes() and the list view both work identically. */
  /* "3.4k" above a thousand, the plain number below it — mirrors avd_compact()
     in diary/partials.php so a card rendered here reads like one rendered by
     the server. */
  function compact(n) {
    return n >= 1000 ? String(+(n / 1000).toFixed(1)).replace(/\.0$/, '') + 'k' : String(n);
  }
  /* The counts line, and the same three numbers as data for the map's card.
     Nothing is rendered when the server sent no counts: a card that cannot
     read its counts shows none, never a fabricated nought. */
  function countsHtml(c) {
    if (!c) return '';
    return '<p class="avd-counts av-num"><span>' + compact(c.views) + ' views</span>'
      + '<span>' + c.comments + ' comments</span>'
      + '<span>' + c.claps + ' <span aria-hidden="true">\uD83D\uDC4F</span><span class="av-sr">applause</span></span></p>';
  }
  function countsData(c) {
    return c ? ' data-views="' + c.views + '" data-comments="' + c.comments + '" data-claps="' + c.claps + '"' : '';
  }

  function liHtml(a, num, latest, counts) {
    var min = parseInt(a.read_minutes || 0, 10) || 0;
    var cat = a.category || '', cslug = a.category_slug || '', pub = a.published || '';
    var c = counts && counts[a.slug] ? counts[a.slug] : null;
    return '<li><a href="/diary/' + esc(a.slug) + '/" '
      + 'data-cat="' + esc(cslug) + '" data-slug="' + esc(a.slug) + '" '
      + 'data-search="' + esc((a.title + ' ' + cat).toLowerCase()) + '" '
      + 'data-title="' + esc(a.title) + '" data-cat-name="' + esc(cat) + '" '
      + 'data-published="' + esc(pub) + '" data-date="' + esc(a.published_at || '') + '" '
      + 'data-min="' + min + '" data-num="' + esc(String(num)) + '" data-latest="' + (latest ? '1' : '0') + '"'
      + countsData(c) + '>'
      + '<span class="je-num">' + esc(String(num)) + '</span>'
      + '<span class="je-main">'
      + '<span class="je-cat" data-c="' + esc(cslug) + '">' + esc(cat) + (latest ? ' · Latest' : '') + '</span>'
      + '<span class="je-title">' + esc(a.title) + '</span>'
      + '<span class="je-meta">' + esc(pub) + (min ? ' · ' + min + ' min read' : '') + '</span>'
      + '</span><span class="je-arrow" aria-hidden="true">→</span></a>' + countsHtml(c) + '</li>';
  }

  function apiUrl() {
    var p = ['action=list', 'limit=' + PER, 'offset=' + offset];
    if (state.sort && state.sort !== 'latest') p.push('sort=' + encodeURIComponent(state.sort));
    if (state.year)  p.push('year=' + encodeURIComponent(state.year));
    if (state.month) p.push('month=' + encodeURIComponent(state.month));
    if (state.cat && state.cat !== 'all' && state.cat !== 'saved') p.push('cat=' + encodeURIComponent(state.cat));
    if (state.q)     p.push('q=' + encodeURIComponent(state.q));
    return '/diary/api.php?' + p.join('&');
  }

  function setBusy(b) { loading = b; if (skeleton) skeleton.hidden = !b; if (loadBtn) loadBtn.disabled = b; }
  function updateFoot() { if (foot) foot.hidden = !(!done && !savedMode && offset < total); }
  function renderCount() {
    var shown = list.querySelectorAll('a').length;
    if (countEl) countEl.textContent = String(savedMode ? shown : total);
    if (noRes) noRes.style.display = shown === 0 ? 'block' : 'none';
  }
  function changed() { document.dispatchEvent(new CustomEvent('diary:changed')); }

  function load() {
    if (loading || done || savedMode) return;
    setBusy(true);
    fetch(apiUrl(), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { done = true; return; }
        total = d.total || 0;
        var start = offset, items = d.articles || [], frag = '';
        items.forEach(function (a, k) {
          var g = start + k;
          var latest = (!hasFilter() && g === 0);
          frag += liHtml(a, latest ? '★' : (total - g), latest, d.counts);
        });
        list.insertAdjacentHTML('beforeend', frag);
        offset += items.length;
        if (!d.hasMore || !items.length) done = true;
        renderCount(); updateFoot(); changed();
      })
      .catch(function () { done = true; })
      .finally(function () { setBusy(false); });
  }

  function applyFilters() {
    savedMode = (state.cat === 'saved');
    list.innerHTML = ''; offset = 0; done = false;

    if (savedMode) {
      // "Saved" is a per-device view: pull a recent window and keep saved slugs.
      setBusy(true);
      fetch('/diary/api.php?action=list&limit=48&offset=0', { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var saved = savedSet(), n = 0, frag = '';
          (d && d.articles || []).forEach(function (a) { if (saved.indexOf(a.slug) !== -1) { n++; frag += liHtml(a, n, false, d.counts); } });
          list.insertAdjacentHTML('beforeend', frag);
          done = true; renderCount(); updateFoot(); changed();
        })
        .catch(function () {})
        .finally(function () { setBusy(false); });
      return;
    }
    load();
  }

  /* ── wire the controls ─────────────────────────────────────────────── */
  var qT;
  if (searchInput) searchInput.addEventListener('input', function () {
    clearTimeout(qT); qT = setTimeout(function () { state.q = searchInput.value.trim(); applyFilters(); }, 280);
  });
  if (yearSel) yearSel.addEventListener('change', function () {
    state.year = yearSel.value;
    if (monthSel) {
      var ms = (facets.monthsByYear && facets.monthsByYear[state.year]) || [];
      if (!state.year || !ms.length) { monthSel.innerHTML = '<option value="">All months</option>'; monthSel.disabled = true; }
      else {
        monthSel.disabled = false;
        monthSel.innerHTML = '<option value="">All months</option>' +
          ms.map(function (m) { return '<option value="' + m + '">' + (MONTHS[parseInt(m, 10)] || m) + '</option>'; }).join('');
      }
      state.month = '';
    }
    applyFilters();
  });
  if (monthSel) monthSel.addEventListener('change', function () { state.month = monthSel.value; applyFilters(); });
  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      // aria-pressed, not just a class: the chips are toggle buttons in a
      // group now, and "active" is a colour a screen reader cannot read.
      chips.forEach(function (c) { c.classList.remove('active'); c.setAttribute('aria-pressed', 'false'); });
      chip.classList.add('active'); chip.setAttribute('aria-pressed', 'true');
      state.cat = chip.getAttribute('data-filter') || 'all';
      applyFilters();
    });
  });

  /* ── trigger loading: button, scroll sentinel, and map panning ─────── */
  if (loadBtn) loadBtn.addEventListener('click', load);
  if (sentinel && 'IntersectionObserver' in window) {
    new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) load(); });
    }, { rootMargin: '600px 0px' }).observe(sentinel);
  }
  document.addEventListener('diary:mapnearend', load);

  /* The first page is already in the DOM (SSR). Start counting from it. */
  offset = list.querySelectorAll('a').length;
  done = offset >= total;
  renderCount(); updateFoot();

  // Honour a server-side ?q= prefill by running it through the same path.
  if (state.q) applyFilters();
})();
