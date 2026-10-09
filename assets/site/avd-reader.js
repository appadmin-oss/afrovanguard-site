/* The reading rail: which section you are in, how much is left, and text size.
   Companion to avd.js, which owns engagement and audio. No dependencies. */
(function () {
  'use strict';
  var rail = document.querySelector('[data-avd-rail]');
  var article = document.querySelector('article.avd-article');
  if (!article) return;

  /* ── Text size ───────────────────────────────────────────────────────────
     The buttons move --reading-scale, which the whole article is sized
     against and which the boot script restores from localStorage before first
     paint. Steps are whole pixels from 15 to 22 against a 19px default, so the
     control lands on sizes a reader can describe rather than on 1.1 of
     something. */
  var BASE = 19, MIN = 15, MAX = 22;
  var root = document.documentElement;
  function px() {
    var s = parseFloat(getComputedStyle(root).getPropertyValue('--reading-scale')) || 1;
    return Math.min(MAX, Math.max(MIN, Math.round(s * BASE)));
  }
  function setPx(v) {
    v = Math.min(MAX, Math.max(MIN, v));
    root.style.setProperty('--reading-scale', String(v / BASE));
    try { localStorage.setItem('av.scale', String(v / BASE)); } catch (e) {}
    sizeButtons();
  }
  var sizeBtns = rail ? [].slice.call(rail.querySelectorAll('[data-avd-size]')) : [];
  function sizeButtons() {
    var v = px();
    sizeBtns.forEach(function (b) {
      var step = +b.dataset.avdSize;
      b.disabled = (step < 0 && v <= MIN) || (step > 0 && v >= MAX);
      b.setAttribute('aria-label', (step < 0 ? 'Smaller text' : 'Larger text') + ', currently ' + v + ' pixels');
    });
  }
  sizeBtns.forEach(function (b) { b.addEventListener('click', function () { setPx(px() + +b.dataset.avdSize); }); });
  sizeButtons();

  if (!rail) return;

  /* ── Which section am I in ───────────────────────────────────────────────
     Driven by scroll position rather than by IntersectionObserver entries,
     because a heading that scrolls off the top stops intersecting while its
     section is still the one being read — which is how a table of contents
     ends up highlighting nothing through the longest section of an entry. */
  var links = [].slice.call(rail.querySelectorAll('.avd-toc a'));
  var current = rail.querySelector('[data-avd-current]');
  var left = rail.querySelector('[data-avd-left]');
  var fill = rail.querySelector('[data-avd-progress]');
  var minutes = +rail.dataset.minutes || 1;
  var targets = links.map(function (a) {
    return { link: a, el: document.getElementById(decodeURIComponent(a.hash.slice(1))) };
  }).filter(function (t) { return t.el; });

  var active = null;
  function spy() {
    var mark = window.scrollY + 140, found = targets.length ? targets[0] : null;
    for (var i = 0; i < targets.length; i++) {
      if (targets[i].el.getBoundingClientRect().top + window.scrollY <= mark) found = targets[i];
    }
    if (found && found !== active) {
      if (active) active.link.classList.remove('is-active');
      found.link.classList.add('is-active');
      if (current) current.textContent = found.link.textContent;
      active = found;
    }

    // How much is left, measured against the article's own box rather than the
    // page's: the footer and "Keep reading" are not reading time.
    var box = article.getBoundingClientRect();
    var read = Math.min(1, Math.max(0, (window.scrollY + window.innerHeight - (box.top + window.scrollY)) / Math.max(1, box.height)));
    if (fill) fill.style.width = (read * 100).toFixed(1) + '%';
    if (left) left.textContent = String(Math.max(0, Math.ceil(minutes * (1 - read))));
  }
  var ticking = false;
  window.addEventListener('scroll', function () {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () { ticking = false; spy(); });
  }, { passive: true });
  window.addEventListener('resize', spy, { passive: true });
  spy();

  /* ── The phone bar opens into the list ──────────────────────────────── */
  var toggle = rail.querySelector('.avd-rail-toggle');
  if (toggle) {
    toggle.addEventListener('click', function () {
      toggle.setAttribute('aria-expanded', toggle.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');
    });
    // Following a heading closes it: the list has done its job and the reader
    // wants the words, not a panel over them.
    links.forEach(function (a) { a.addEventListener('click', function () { toggle.setAttribute('aria-expanded', 'false'); }); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        toggle.setAttribute('aria-expanded', 'false'); toggle.focus();
      }
    });
  }
})();
