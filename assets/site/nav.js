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

  /* ---- mega: hover-intent open/close ----
     CSS opens on :hover/:focus-within; this layers a class with a CLOSE DELAY so
     a brief slip between the trigger and the panel (or a diagonal path to a
     sub-item) doesn't make the menu vanish. Re-entering cancels the pending
     close. Keyboard: focus opens, Escape closes. */
  document.querySelectorAll('.nav-links .has-mega').forEach(function (li) {
    var link = li.querySelector(':scope > a');
    if (!link) return;
    var closeT = null;
    var open = function () { clearTimeout(closeT); li.classList.add('is-open'); link.setAttribute('aria-expanded', 'true'); };
    var close = function (immediate) {
      clearTimeout(closeT);
      closeT = setTimeout(function () { li.classList.remove('is-open'); link.setAttribute('aria-expanded', 'false'); }, immediate ? 0 : 260);
    };
    li.addEventListener('mouseenter', open);
    li.addEventListener('mouseleave', function () { close(false); });
    li.addEventListener('focusin', open);
    li.addEventListener('focusout', function () { if (!li.contains(document.activeElement)) close(true); });
    li.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(true); link.focus(); } });
  });

  /* ---- Auth-aware chrome (site-wide) ----
     Every sign-in entry returns the visitor to where they were, and the nav
     reflects the signed-in member once known. One source of truth so the
     header is consistent on every page (PHP + static). */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function loginHref() { return '/login?next=' + encodeURIComponent(location.pathname + location.search); }
  Array.prototype.forEach.call(document.querySelectorAll('[data-login-link]'), function (a) { a.setAttribute('href', loginHref()); });

  function reflectMember(user) {
    var first = esc((user.name || 'Member').split(' ')[0]);
    var slot = document.getElementById('navAuth');
    if (slot) {
      slot.innerHTML = '<a class="nav-acct" href="/portal/"><span class="nav-acct-hi">Hi,</span> ' + first + '</a>'
        + '<a class="nav-signin" href="#" data-logout>Sign out</a>';
    }
    var subLogin = document.getElementById('navSubLogin');
    if (subLogin) { subLogin.textContent = first; subLogin.setAttribute('href', '/portal/'); }
  }
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-logout]')) {
      e.preventDefault();
      fetch('/academy/api.php?action=logout', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}', credentials: 'same-origin' })
        .then(function () { location.reload(); }).catch(function () { location.reload(); });
    }
  });
  if (document.getElementById('navAuth') || document.getElementById('navSubLogin')) {
    fetch('/academy/api.php?action=me', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.ok && d.user) reflectMember(d.user); })
      .catch(function () {});
  }
})();
