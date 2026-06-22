/* ============================================================
   assets/site/nav.js — mega menu (a11y) + mobile drawer.
   Loaded site-wide. Desktop mega panels open via CSS hover/focus;
   this adds keyboard/aria support and drives the mobile drawer.
   ============================================================ */
(function () {
  'use strict';
  var root = document.documentElement;
  var burger = document.getElementById('avBurger');
  var drawer = document.getElementById('avDrawer');
  var scrim = document.querySelector('.scrim');

  /* ---- mobile drawer ---- */
  function setDrawer(open) {
    if (!drawer) return;
    drawer.classList.toggle('open', open);
    if (scrim) scrim.classList.toggle('open', open);
    if (burger) burger.setAttribute('aria-expanded', String(open));
    if (open) { drawer.removeAttribute('inert'); } else { drawer.setAttribute('inert', ''); }
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) {
      var first = drawer.querySelector('.avd-close');
      if (first) first.focus();
    } else if (burger) { burger.focus(); }
  }
  if (burger && drawer) {
    burger.addEventListener('click', function () { setDrawer(!drawer.classList.contains('open')); });
    document.querySelectorAll('[data-close-drawer]').forEach(function (el) {
      el.addEventListener('click', function () { setDrawer(false); });
    });
    // close when a real navigation link (not an accordion toggle) is tapped
    drawer.querySelectorAll('a[href]').forEach(function (a) {
      a.addEventListener('click', function () { setDrawer(false); });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && drawer.classList.contains('open')) setDrawer(false);
    });
  }

  /* ---- drawer accordions (single-open) ---- */
  var accs = drawer ? drawer.querySelectorAll('.avd-acc') : [];
  accs.forEach(function (acc) {
    var btn = acc.querySelector('.avd-acc-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var willOpen = !acc.classList.contains('open');
      accs.forEach(function (o) { if (o !== acc) { o.classList.remove('open'); var b = o.querySelector('.avd-acc-btn'); if (b) b.setAttribute('aria-expanded', 'false'); } });
      acc.classList.toggle('open', willOpen);
      btn.setAttribute('aria-expanded', String(willOpen));
    });
  });

  /* ---- mega: keep aria-expanded in sync (panels open via CSS) ---- */
  document.querySelectorAll('.nav-links .has-mega').forEach(function (li) {
    var link = li.querySelector(':scope > a');
    if (!link) return;
    var sync = function (state) { link.setAttribute('aria-expanded', String(state)); };
    li.addEventListener('mouseenter', function () { sync(true); });
    li.addEventListener('mouseleave', function () { sync(false); });
    li.addEventListener('focusin', function () { sync(true); });
    li.addEventListener('focusout', function () { if (!li.contains(document.activeElement)) sync(false); });
  });
})();
