/* people/people.js — search and filter for the People directory (/people/).
 * The page is complete without this file: every person is in the markup and the
 * toolbar is `hidden` until this runs. The chips are the constitutional groups
 * on the page; filtering hides whole cards with the `hidden` attribute, and a
 * section (or directorate, or institution) left with nobody in it is hidden too.
 * The result count is a polite live region, so a screen reader hears it settle. */
(function () {
  'use strict';
  var tools = document.getElementById('ppl-tools');
  if (!tools) return;
  var q = document.getElementById('ppl-q');
  var count = document.getElementById('ppl-count');
  var empty = document.getElementById('ppl-empty');
  var chips = [].slice.call(tools.querySelectorAll('.ppl-chip'));
  var people = [].slice.call(document.querySelectorAll('.ppl-dir [data-q]'));
  var sections = [].slice.call(document.querySelectorAll('[data-ppl-section]'));
  var ids = function (els) { var seen = {}; els.forEach(function (el) { seen[el.getAttribute('data-id')] = 1; }); return Object.keys(seen).length; };
  var total = ids(people);
  var group = 'all';
  var timer = 0;

  function norm(s) {
    s = String(s || '').toLowerCase();
    return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
  }
  people.forEach(function (el) { el.setAttribute('data-q', norm(el.getAttribute('data-q'))); });

  function apply(announce) {
    var words = norm(q ? q.value : '').trim().split(/\s+/).filter(Boolean);
    var visible = [];
    people.forEach(function (el) {
      var hay = el.getAttribute('data-q');
      var ok = (group === 'all' || el.getAttribute('data-group') === group) &&
               words.every(function (w) { return hay.indexOf(w) !== -1; });
      el.hidden = !ok;
      if (ok) visible.push(el);
    });
    var shown = ids(visible);
    // Innermost first, so a body section is settled before the group around it.
    sections.slice().reverse().forEach(function (sec) {
      var n = ids([].slice.call(sec.querySelectorAll('[data-q]:not([hidden])')));
      sec.hidden = n === 0;
      var badge = sec.querySelector(':scope > .ppl-wrap > .ppl-sec-h [data-ppl-n], :scope > .ppl-body-h [data-ppl-n]');
      if (badge) badge.textContent = n;
    });
    if (empty) empty.hidden = shown !== 0;
    if (count && announce !== false) {
      count.textContent = shown === total ? 'Showing all ' + total + (total === 1 ? ' person' : ' people')
        : shown === 0 ? 'No people match'
        : 'Showing ' + shown + ' of ' + total + ' people';
    }
  }

  function setGroup(g) {
    group = g;
    chips.forEach(function (c) { c.setAttribute('aria-pressed', c.getAttribute('data-group') === g ? 'true' : 'false'); });
    apply();
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
  if (reset) reset.addEventListener('click', function () {
    if (q) q.value = '';
    setGroup('all');
    if (q) q.focus();
  });

  tools.hidden = false;
  apply(false);
})();
