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

  /* ---- Scroll-aware header (site-wide) ----
     PHP pages already DEFINE .site-header.scrolled styling but nothing toggled
     it — so the header never condensed on scroll. Add a rAF-throttled toggle so
     every page gets the crisp, solid-on-scroll bar. (The static home page also
     toggles this class itself; the result is identical, so they don't fight.) */
  var avHeader = document.getElementById('site-header') || document.querySelector('.site-header');
  if (avHeader) {
    var avTick = false;
    var avScroll = function () {
      if (avTick) return;
      avTick = true;
      window.requestAnimationFrame(function () { avHeader.classList.toggle('scrolled', window.scrollY > 8); avTick = false; });
    };
    window.addEventListener('scroll', avScroll, { passive: true });
    avScroll();
  }

  /* ---- Auth-aware chrome (site-wide) ----
     Every sign-in entry returns the visitor to where they were, and the nav
     reflects the signed-in member once known. One source of truth so the
     header is consistent on every page (PHP + static). */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function nextParam() { return encodeURIComponent(location.pathname + location.search); }
  function loginHref() { return '/login?next=' + nextParam(); }
  Array.prototype.forEach.call(document.querySelectorAll('[data-login-link]'), function (a) { a.setAttribute('href', loginHref()); });
  // "Create account" carries an explicit sign-up intent into the progressive flow.
  var acctCreate = document.getElementById('acctCreate');
  if (acctCreate) acctCreate.setAttribute('href', '/login?intent=signup&next=' + nextParam());

  /* ---- Account popover (AWS-style profile card) ----
     Clicking the avatar opens a small card instead of navigating — Sign in /
     Create account when signed out; account links + sign out when signed in. */
  var acctBtn = document.getElementById('acctBtn');
  var acctMenu = document.getElementById('acctMenu');
  function acctAnchor() {
    // fixed popover: anchor it just under the avatar, right-aligned to it.
    if (!acctBtn || !acctMenu) return;
    var r = acctBtn.getBoundingClientRect();
    acctMenu.style.top = Math.round(r.bottom + 10) + 'px';
    acctMenu.style.right = Math.max(8, Math.round(window.innerWidth - r.right)) + 'px';
    acctMenu.style.left = 'auto';
  }
  function acctOpen(open) {
    if (!acctBtn || !acctMenu) return;
    if (open) acctAnchor();
    acctMenu.hidden = !open;
    acctMenu.classList.toggle('open', open);
    acctBtn.setAttribute('aria-expanded', String(open));
    if (open) {
      var f = acctMenu.querySelector('a,button');
      // preventScroll: focusing an element under the sticky header must NOT
      // yank the page into a scroll-into-view jump.
      if (f) { try { f.focus({ preventScroll: true }); } catch (e) { try { f.focus(); } catch (e2) {} } }
    }
  }
  if (acctBtn && acctMenu) {
    acctBtn.addEventListener('click', function (e) { e.preventDefault(); acctOpen(acctMenu.hidden); });
    document.addEventListener('click', function (e) {
      if (acctMenu.hidden) return;
      if (!acctMenu.contains(e.target) && !acctBtn.contains(e.target)) acctOpen(false);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !acctMenu.hidden) { acctOpen(false); acctBtn.focus(); } });
    // a fixed popover would drift on scroll/resize — re-anchor or close.
    window.addEventListener('scroll', function () { if (!acctMenu.hidden) acctAnchor(); }, { passive: true });
    window.addEventListener('resize', function () { if (!acctMenu.hidden) acctAnchor(); });
  }

  function reflectMember(user) {
    var name = user.name || 'Member';
    var first = esc(name.split(' ')[0]);
    var initial = esc((name.trim()[0] || 'M').toUpperCase());
    var email = esc(user.email || '');
    // The avatar shows the member's initial and stays the popover trigger.
    if (acctBtn) {
      acctBtn.classList.add('is-member');
      acctBtn.setAttribute('aria-label', name + ' — your account');
      acctBtn.setAttribute('data-tip', first);
      acctBtn.innerHTML = '<span class="acct-initial">' + initial + '</span>';
    }
    // The popover becomes the member menu: identity + quick links + sign out.
    if (acctMenu) {
      acctMenu.innerHTML =
        '<div class="am-head am-head-member">'
        + '<span class="am-avatar">' + initial + '</span>'
        + '<span class="am-id"><span class="am-name">' + esc(name) + '</span>'
        + (email ? '<span class="am-email">' + email + '</span>' : '') + '</span></div>'
        + '<nav class="am-links" role="none">'
        + '<a role="menuitem" href="/portal/">Your portal</a>'
        + '<a role="menuitem" href="/academy/">Academy</a>'
        + '<a role="menuitem" href="/diary/">The Diary</a>'
        + '<a role="menuitem" href="/community/">Community</a>'
        + '</nav>'
        + '<div class="am-actions am-actions-member"><a class="am-btn am-btn-ghost" href="#" data-logout>Sign out</a></div>';
    }
    // Sign-in text link (utility strip) becomes the member's first name → portal.
    var sl = document.querySelector('.nav-signin-link');
    if (sl) { sl.textContent = 'Hi, ' + first; sl.setAttribute('href', '/portal/'); sl.removeAttribute('data-login-link'); }
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

  /* ---- Accessible, AI-integrated site search modal ---- */
  (function () {
    var modal = document.getElementById('avSearch');
    var input = document.getElementById('avSearchInput');
    var resultsEl = document.getElementById('avSearchResults');
    var aiBox = document.getElementById('avSearchAi'), aiText = document.getElementById('avSearchAiText');
    var hint = document.getElementById('avSearchHint');
    if (!modal || !input || !resultsEl) return;
    var opener = null, tDeb = null, lastReq = 0;
    var TYPE_BADGE = { Page: 'Page', Diary: 'Diary', Academy: 'Academy' };

    function open() {
      opener = document.activeElement;
      modal.hidden = false;
      requestAnimationFrame(function () { modal.classList.add('open'); });
      document.body.style.overflow = 'hidden';
      setTimeout(function () { try { input.focus(); } catch (e) {} }, 30);
    }
    function close() {
      modal.classList.remove('open');
      document.body.style.overflow = '';
      setTimeout(function () { modal.hidden = true; }, 180);
      input.value = ''; resultsEl.innerHTML = ''; if (aiBox) aiBox.hidden = true; if (aiText) aiText.textContent = '';
      if (hint) hint.hidden = false;
      if (opener && opener.focus) { try { opener.focus(); } catch (e) {} }
    }
    function row(r) {
      var a = document.createElement('a');
      a.className = 'avs-result'; a.href = r.url; a.setAttribute('role', 'option');
      if (/^https?:/.test(r.url)) { a.target = '_blank'; a.rel = 'noopener'; }
      a.innerHTML = '<span class="avs-type">' + esc(TYPE_BADGE[r.type] || r.type) + '</span>'
        + '<span class="avs-rt"><span class="avs-rtitle">' + esc(r.title) + '</span>'
        + (r.excerpt ? '<span class="avs-rex">' + esc(r.excerpt) + '</span>' : '') + '</span>';
      a.addEventListener('click', function () { close(); });
      return a;
    }
    function render(d, withAi) {
      if (hint) hint.hidden = true;
      var list = (d && d.results) || [];
      resultsEl.innerHTML = '';
      if (!list.length && !(withAi)) {
        resultsEl.innerHTML = '<p class="avs-empty">No matches. Press <kbd>Enter</kbd> to ask the assistant.</p>';
      } else {
        list.forEach(function (r) { resultsEl.appendChild(row(r)); });
      }
      if (withAi && aiBox && aiText) {
        var ai = d && d.ai;
        aiBox.hidden = false;
        if (ai && ai.ok && ai.text) aiText.textContent = ai.text;
        else if (ai && ai.configured === false) aiText.textContent = 'The AI assistant isn’t enabled yet — try the results above, or Contact us.';
        else aiText.textContent = 'I couldn’t answer that just now — try the results above.';
      }
    }
    function search(withAi) {
      var q = input.value.trim();
      if (q.length < 2) { resultsEl.innerHTML = ''; if (aiBox) aiBox.hidden = true; if (hint) hint.hidden = false; return; }
      var req = ++lastReq;
      if (withAi && aiBox && aiText) { aiBox.hidden = false; aiText.textContent = 'Thinking…'; }
      fetch('/search.php?q=' + encodeURIComponent(q) + (withAi ? '&ai=1' : ''), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (req === lastReq) render(d, withAi); })
        .catch(function () { if (req === lastReq) resultsEl.innerHTML = '<p class="avs-empty">Search is unavailable right now.</p>'; });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-search-open]'), function (el) {
      el.addEventListener('click', function (e) { e.preventDefault(); open(); });
    });
    Array.prototype.forEach.call(modal.querySelectorAll('[data-search-close]'), function (el) {
      el.addEventListener('click', close);
    });
    input.addEventListener('input', function () { clearTimeout(tDeb); tDeb = setTimeout(function () { search(false); }, 240); });
    var form = document.getElementById('avSearchForm');
    if (form) form.addEventListener('submit', function (e) { e.preventDefault(); clearTimeout(tDeb); search(true); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) { close(); return; }
      // focus trap inside the dialog
      if (e.key === 'Tab' && !modal.hidden) {
        var f = modal.querySelectorAll('input, button, a[href]');
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
      // Arrow keys walk the results listbox (the roles promise this; deliver it):
      // ↓ from the input reaches the first result, ↑/↓ move between results,
      // ↑ from the first result returns to the input. Enter follows the link.
      if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !modal.hidden) {
        var opts = Array.prototype.slice.call(resultsEl.querySelectorAll('.avs-result'));
        if (!opts.length) return;
        var idx = opts.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') {
          if (document.activeElement === input) { e.preventDefault(); opts[0].focus(); }
          else if (idx >= 0 && idx < opts.length - 1) { e.preventDefault(); opts[idx + 1].focus(); }
        } else {
          if (idx === 0) { e.preventDefault(); input.focus(); }
          else if (idx > 0) { e.preventDefault(); opts[idx - 1].focus(); }
        }
      }
    });
  })();
})();
