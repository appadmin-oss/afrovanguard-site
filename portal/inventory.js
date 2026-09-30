/* portal/inventory.js — the CACENTRE register, inside the portal.
 *
 * ── LAZY, AND ONLY ONCE ─────────────────────────────────────────────────────
 * The first time somebody opens the Inventory pane this asks the server,
 * which asks CACENTRE. Nothing happens before that: the portal dashboard is
 * the first thing a member sees and it must not wait on the other site for a
 * pane most visits never open.
 *
 * ── IT SAYS WHERE THE ANSWER CAME FROM ──────────────────────────────────────
 * Every failure gets a sentence naming what went wrong and what to do, and
 * the pane keeps the link to the console for the things that are not read-
 * only. An empty table with no explanation is the thing that teaches people
 * to stop trusting a register.
 */
(function () {
  'use strict';

  var pane = document.getElementById('view-inventory');
  if (!pane) return;

  var form    = document.getElementById('invForm');
  var qEl     = document.getElementById('invQ');
  var catEl   = document.getElementById('invCat');
  var siteEl  = document.getElementById('invSite');
  var statEl  = document.getElementById('invStatus');
  var tableEl = document.getElementById('invTable');
  var rowsEl  = document.getElementById('invRows');
  var msgEl   = document.getElementById('invMsg');
  var countEl = document.getElementById('invCount');
  var moreEl  = document.getElementById('invMore');
  var pageEl  = document.getElementById('invPage');
  var prevEl  = document.getElementById('invPrev');
  var nextEl  = document.getElementById('invNext');

  var page = 1, pages = 1, loaded = false, busy = false, facetsDone = false;

  function say(text, bad) {
    if (!msgEl) return;
    msgEl.hidden = text === '';
    msgEl.textContent = text;
    msgEl.style.color = bad ? 'var(--av-red, #b3261e)' : '';
  }

  /* Options are replaced, never appended: re-running this after a search must
     not leave the picker holding two of every category. */
  function fillPicker(el, map, anyLabel) {
    if (!el || !map) return;
    var keep = el.value;
    el.textContent = '';
    var any = document.createElement('option');
    any.value = ''; any.textContent = anyLabel;
    el.appendChild(any);
    Object.keys(map).forEach(function (k) {
      var o = document.createElement('option');
      o.value = k; o.textContent = map[k];
      el.appendChild(o);
    });
    el.value = keep;
  }

  function cell(row, text, cls) {
    var td = document.createElement('td');
    if (cls) td.className = cls;
    td.textContent = text;
    row.appendChild(td);
    return td;
  }

  function draw(d) {
    rowsEl.textContent = '';
    (d.items || []).forEach(function (it) {
      var tr = document.createElement('tr');

      /* Name and tag in one cell: the tag is how somebody finds a thing on a
         shelf and the name is how they recognise it, and they are read
         together. */
      var first = document.createElement('th');
      first.scope = 'row';
      var nm = document.createElement('span');
      nm.className = 'inv-name';
      nm.textContent = it.name;
      first.appendChild(nm);
      if (it.tag) {
        var tg = document.createElement('span');
        tg.className = 'inv-tag';
        tg.textContent = it.tag;
        first.appendChild(tg);
      }
      if (it.category) {
        var ct = document.createElement('span');
        ct.className = 'inv-sub';
        ct.textContent = it.category;
        first.appendChild(ct);
      }
      tr.appendChild(first);

      /* "Where" is the site and the room, and an item with neither says so
         rather than showing an empty cell somebody has to interpret. */
      var where = [it.site, it.location].filter(Boolean).join(' · ');
      cell(tr, where || 'Not recorded', where ? '' : 'inv-none');

      cell(tr, it.holder || 'Nobody', it.holder ? '' : 'inv-none');

      var st = document.createElement('td');
      var pill = document.createElement('span');
      pill.className = 'inv-pill' + (it.tone ? ' inv-pill--' + it.tone : '');
      pill.textContent = it.status || '—';
      st.appendChild(pill);
      /* Quantity only when there is more than one, and how many are out only
         when some are. A column of "1 of 1" is a column of noise. */
      if (it.quantity > 1) {
        var q = document.createElement('span');
        q.className = 'inv-sub';
        q.textContent = it.out > 0
          ? (it.quantity - it.out) + ' of ' + it.quantity + ' here'
          : it.quantity + (it.unit ? ' ' + it.unit : '');
        st.appendChild(q);
      }
      tr.appendChild(st);

      rowsEl.appendChild(tr);
    });

    var n = d.total || 0;
    tableEl.hidden = n === 0;
    if (countEl) {
      countEl.textContent = n === 0
        ? 'Read from CACENTRE, which is where these are kept and changed.'
        : n + (n === 1 ? ' item' : ' items') + ' in CACENTRE, which is where these are kept and changed.';
    }
    if (n === 0) say('Nothing matches that. Try a shorter word, or clear the filters.', false);
    else say('', false);

    page  = d.page  || 1;
    pages = d.pages || 1;
    moreEl.hidden = pages <= 1;
    if (pageEl) pageEl.textContent = 'Page ' + page + ' of ' + pages;
    prevEl.disabled = page <= 1;
    nextEl.disabled = page >= pages;
  }

  async function load() {
    if (busy) return;
    busy = true;
    say('Looking…', false);

    var qs = new URLSearchParams();
    if (qEl && qEl.value.trim()) qs.set('q', qEl.value.trim());
    if (catEl && catEl.value)    qs.set('category', catEl.value);
    if (siteEl && siteEl.value)  qs.set('site', siteEl.value);
    if (statEl && statEl.value)  qs.set('status', statEl.value);
    if (page > 1)                qs.set('page', String(page));

    var res, d;
    try {
      res = await fetch('/portal/cac-inventory.php?' + qs.toString(), { credentials: 'same-origin' });
      d = await res.json();
    } catch (e) {
      busy = false;
      tableEl.hidden = true; moreEl.hidden = true;
      say('Could not reach the register just now. Nothing is wrong with your search — try again in a moment.', true);
      return;
    }
    busy = false;

    if (!d || !d.ok) {
      tableEl.hidden = true; moreEl.hidden = true;
      say((d && d.error) || 'The register did not answer.', true);
      return;
    }

    /* The pickers come from CACENTRE so the portal never keeps its own copy
       of the centre's categories and drifts from them. Filled once: refilling
       on every search would reset a picker while somebody was using it. */
    if (!facetsDone && d.facets) {
      fillPicker(catEl,  d.facets.categories, 'Any category');
      fillPicker(siteEl, d.facets.sites,      'Anywhere');
      fillPicker(statEl, d.facets.statuses,   'Any status');
      facetsDone = true;
    }

    draw(d);
    loaded = true;
  }

  if (form) form.addEventListener('submit', function (e) { e.preventDefault(); page = 1; load(); });
  if (catEl)  catEl.addEventListener('change',  function () { page = 1; load(); });
  if (siteEl) siteEl.addEventListener('change', function () { page = 1; load(); });
  if (statEl) statEl.addEventListener('change', function () { page = 1; load(); });
  if (prevEl) prevEl.addEventListener('click', function () { if (page > 1)     { page--; load(); } });
  if (nextEl) nextEl.addEventListener('click', function () { if (page < pages) { page++; load(); } });

  /* The first look loads it; every look after that leaves it alone, so coming
     back to the pane does not throw away what somebody had searched for. */
  function maybeLoad(view) { if (view === 'inventory' && !loaded) load(); }
  document.addEventListener('portal:view', function (e) {
    maybeLoad(e && e.detail ? e.detail.view : '');
  });
  /* Arriving straight at /portal/#inventory, where no view event fires. */
  if (location.hash === '#inventory') maybeLoad('inventory');
})();
