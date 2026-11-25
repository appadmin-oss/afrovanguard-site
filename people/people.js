/* people/people.js — search and filter for the People directory (/people/), and the
 * way back to it from a profile.
 *
 * Directory. The page is complete without this file: every person is in the markup
 * and the search is `hidden` until this runs. The chips are the constitutional
 * groups on the page, each with its count. Without this file they are a <nav> of
 * links to the sections; this turns them into what they then do — a labelled group
 * of toggle buttons (aria-pressed) that filter — so a screen reader is never told
 * "link" for something that filters, or "button" for something that jumps.
 * Filtering hides whole cards with the `hidden` attribute, and a
 * section (or directorate, or institution) left with nobody in it is hidden too.
 * The result count is a polite live region, so a screen reader hears it settle.
 *   - The search folds accents, matches name, role, office, directorate, group and
 *     place, and forgives one wrong, missing or extra letter in a word of 5+ letters.
 *   - While searching, the matched text in names and roles is wrapped in <mark>.
 *   - The search and the chip are kept in the address (?q=, ?g=) with
 *     history.replaceState, and read back on load, so a filtered view can be shared,
 *     reloaded, or returned to with Back.
 *   - "Clear" appears whenever a search or a filter is on.
 *
 * Profile. "All our people" goes back to the directory as the visitor left it (the
 * same-origin referrer, else the last state this tab saw), never anywhere else.
 */
(function () {
  'use strict';
  var KEY = 'ppl:dir';
  function store(v) { try { if (v === undefined) return sessionStorage.getItem(KEY) || ''; sessionStorage.setItem(KEY, v); } catch (e) {} return ''; }

  /* ── Profile: the way back ─────────────────────────────────────────── */
  var back = document.querySelector('[data-ppl-back]');
  if (back) {
    var search = '';
    try {
      var r = document.referrer ? new URL(document.referrer) : null;
      if (r && r.origin === location.origin && r.pathname === '/people/') search = r.search;
    } catch (e) {}
    if (!search) search = store();
    // Only our own two keys, rebuilt, so nothing else from the referrer reaches the link.
    var src = new URLSearchParams(search), out = new URLSearchParams();
    ['q', 'g'].forEach(function (k) { var v = (src.get(k) || '').slice(0, 80); if (v) out.set(k, v); });
    var qs = out.toString();
    if (qs) back.setAttribute('href', '/people/?' + qs);
  }

  /* ── Directory ─────────────────────────────────────────────────────── */
  var tools = document.getElementById('ppl-tools');
  if (!tools) return;
  var dir = document.querySelector('.ppl-dir');
  var q = document.getElementById('ppl-q');
  var count = document.getElementById('ppl-count');
  var empty = document.getElementById('ppl-empty');
  var clear = document.querySelector('[data-ppl-clear]');
  /* The chips: links → toggle buttons, inside a group named for what it does. Their nodes
     are moved across, never re-parsed from a string. */
  var nav = tools.querySelector('[data-ppl-chips]');
  if (nav) {
    var row = document.createElement('div');
    row.className = nav.className;
    row.setAttribute('role', 'group');
    row.setAttribute('aria-label', 'Show people from');
    [].slice.call(nav.querySelectorAll('a.ppl-chip')).forEach(function (a) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = a.className;
      b.setAttribute('data-group', a.getAttribute('data-group'));
      b.setAttribute('aria-pressed', 'false');
      while (a.firstChild) b.appendChild(a.firstChild);
      row.appendChild(b);
    });
    nav.parentNode.replaceChild(row, nav);
  }
  var chips = [].slice.call(tools.querySelectorAll('.ppl-chip'));
  var people = [].slice.call(document.querySelectorAll('.ppl-dir [data-q]'));
  var sections = [].slice.call(document.querySelectorAll('[data-ppl-section]'));
  var groups = chips.map(function (c) { return c.getAttribute('data-group'); });
  var ids = function (els) { var seen = {}; els.forEach(function (el) { seen[el.getAttribute('data-id')] = 1; }); return Object.keys(seen).length; };
  var total = ids(people);
  var group = 'all';
  var timer = 0;

  function norm(s) {
    s = String(s || '').toLowerCase();
    return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
  }

  /* Levenshtein distance, stopping early once it is past 1. */
  function within1(a, b) {
    if (a === b) return true;
    var la = a.length, lb = b.length;
    if (Math.abs(la - lb) > 1) return false;
    var i = 0, j = 0, edits = 0;
    while (i < la && j < lb) {
      if (a[i] === b[j]) { i++; j++; continue; }
      if (++edits > 1) return false;
      if (la > lb) i++; else if (lb > la) j++; else { i++; j++; }
    }
    return edits + (la - i) + (lb - j) <= 1;
  }
  /* A query word against one word of the text: the start of it, or (5+ letters) within one edit
     of it or of its start. Returns how many letters of the text word matched, or 0. */
  function wordHit(w, t) {
    if (t.indexOf(w) === 0) return w.length;
    if (w.length < 5) return 0;
    if (within1(w, t)) return t.length;
    for (var n = w.length - 1; n <= w.length + 1; n++) {
      if (n < t.length && within1(w, t.slice(0, n))) return n;
    }
    return 0;
  }
  function matches(hay, tokens, w) {
    if (hay.indexOf(w) !== -1) return true;
    if (w.length < 5) return false;
    for (var k = 0; k < tokens.length; k++) if (wordHit(w, tokens[k])) return true;
    return false;
  }

  people.forEach(function (el) {
    var h = norm(el.getAttribute('data-q'));
    el.setAttribute('data-q', h);
    el._tok = h.split(/[^a-z0-9]+/).filter(Boolean);
    el._marks = [].slice.call(el.querySelectorAll('.ppl-card-n a, .ppl-card-r, .ppl-card-d, .ppl-lead-n a, .ppl-lead-r'));
    el._marks.forEach(function (m) { m._text = m.textContent; });
  });

  /* Wrap what matched in <mark>. The text is folded one character at a time, so the
     positions found in the folded text map straight back to the original. */
  function highlight(node, words) {
    var text = node._text;
    if (!words.length) { if (node.firstElementChild) node.textContent = text; return; }
    var folded = '', at = [];
    for (var i = 0; i < text.length; i++) { var f = norm(text[i]); for (var k = 0; k < f.length; k++) { folded += f[k]; at.push(i); } }
    at.push(text.length);
    var ranges = [];
    words.forEach(function (w) {
      var p = folded.indexOf(w);
      while (p !== -1) { ranges.push([p, p + w.length]); p = folded.indexOf(w, p + w.length); }
      if (w.length >= 5) {
        var re = /[a-z0-9]+/g, m;
        while ((m = re.exec(folded))) { if (wordHit(w, m[0])) ranges.push([m.index, m.index + m[0].length]); }
      }
    });
    if (!ranges.length) { if (node.firstElementChild) node.textContent = text; return; }
    ranges.sort(function (a, b) { return a[0] - b[0]; });
    var merged = [ranges[0].slice()];
    ranges.slice(1).forEach(function (r) { var l = merged[merged.length - 1]; if (r[0] <= l[1]) l[1] = Math.max(l[1], r[1]); else merged.push(r.slice()); });
    var frag = document.createDocumentFragment(), last = 0;
    merged.forEach(function (r) {
      var s = at[r[0]], e = at[r[1]];
      if (s > last) frag.appendChild(document.createTextNode(text.slice(last, s)));
      var mk = document.createElement('mark'); mk.textContent = text.slice(s, e); frag.appendChild(mk);
      last = e;
    });
    if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
    node.textContent = ''; node.appendChild(frag);
  }

  function syncUrl(raw) {
    var p = new URLSearchParams(location.search);
    if (raw) p.set('q', raw); else p.delete('q');
    if (group !== 'all') p.set('g', group); else p.delete('g');
    var qs = p.toString();
    try { history.replaceState(history.state, '', location.pathname + (qs ? '?' + qs : '') + location.hash); } catch (e) {}
    var own = new URLSearchParams(); if (raw) own.set('q', raw); if (group !== 'all') own.set('g', group);
    store(own.toString() ? '?' + own.toString() : '');
  }

  function apply(announce) {
    var raw = q ? q.value.trim() : '';
    var words = norm(raw).split(/\s+/).filter(Boolean);
    var visible = [];
    people.forEach(function (el) {
      var hay = el.getAttribute('data-q');
      var ok = (group === 'all' || el.getAttribute('data-group') === group) &&
               words.every(function (w) { return matches(hay, el._tok, w); });
      el.hidden = !ok;
      if (ok) visible.push(el);
      el._marks.forEach(function (m) { highlight(m, ok ? words : []); });
    });
    var shown = ids(visible);
    // Innermost first, so a body section is settled before the group around it.
    sections.slice().reverse().forEach(function (sec) {
      var n = ids([].slice.call(sec.querySelectorAll('[data-q]:not([hidden])')));
      sec.hidden = n === 0;
    });
    // The first section still showing loses its top hairline.
    var first = null;
    sections.forEach(function (sec) {
      if (sec.classList.contains('ppl-sec')) { sec.classList.remove('is-first'); if (!first && !sec.hidden) first = sec; }
    });
    if (first) first.classList.add('is-first');
    if (dir) { if (words.length) dir.setAttribute('data-ppl-searching', ''); else dir.removeAttribute('data-ppl-searching'); }
    var active = words.length > 0 || group !== 'all';
    if (clear) clear.hidden = !active;
    if (empty) empty.hidden = shown !== 0;
    if (count && (announce !== false || active)) {
      count.textContent = shown === total ? 'Showing all ' + total + (total === 1 ? ' person' : ' people')
        : shown === 0 ? 'No people match'
        : 'Showing ' + shown + ' of ' + total + ' people';
    }
    syncUrl(raw);
  }

  function setGroup(g, quiet) {
    group = groups.indexOf(g) !== -1 ? g : 'all';
    chips.forEach(function (c) { c.setAttribute('aria-pressed', c.getAttribute('data-group') === group ? 'true' : 'false'); });
    if (!quiet) apply();
  }
  function resetAll(focus) {
    if (q) q.value = '';
    setGroup('all');
    if (focus && q) q.focus();
  }

  chips.forEach(function (c, i) {
    c.addEventListener('click', function () { setGroup(c.getAttribute('data-group')); });
    // Arrow keys move along the row (every chip stays in the Tab order too).
    c.addEventListener('keydown', function (e) {
      var k = e.key, j = -1;
      if (k === 'ArrowRight' || k === 'ArrowDown') j = (i + 1) % chips.length;
      else if (k === 'ArrowLeft' || k === 'ArrowUp') j = (i - 1 + chips.length) % chips.length;
      else if (k === 'Home') j = 0;
      else if (k === 'End') j = chips.length - 1;
      if (j < 0) return;
      e.preventDefault();
      chips[j].focus();
    });
  });
  if (q) {
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(apply, 180); });
    q.addEventListener('keydown', function (e) { if (e.key === 'Escape' && q.value) { q.value = ''; apply(); } });
  }
  var reset = document.querySelector('[data-ppl-reset]');
  if (reset) reset.addEventListener('click', function () { resetAll(true); });
  if (clear) clear.addEventListener('click', function () { resetAll(true); });

  /* Restore ?q= and ?g= from the address. */
  var params = new URLSearchParams(location.search);
  if (q && params.get('q')) q.value = params.get('q').slice(0, 80);
  setGroup(params.get('g') || 'all', true);

  var searchBox = tools.querySelector('[data-ppl-search]');
  if (searchBox) searchBox.hidden = false;
  apply(false);
})();
