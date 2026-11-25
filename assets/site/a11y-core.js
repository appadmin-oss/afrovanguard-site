/* assets/site/a11y-core.js — the one accessibility foundation every page shares.
 *
 * Loaded first by lib/A11y.php head() (and by the static pages and error pages),
 * before the Accessibility panel (a11y.js), the search palette (avsearch.js),
 * Listen (listen.js), Chioma and the People page. Each of those used to carry
 * its own copy of the same five things, written in different rounds; they now
 * call this instead, so they behave alike and a fix to one is a fix to all:
 *
 *   AvA11y.announce(text, { assertive })   ONE polite and ONE assertive live
 *       region per page. While a modal <dialog> is open the page behind it is
 *       inert — and inert content is not announced — so the region moves into
 *       the top-most open modal dialog and back out when it closes. Repeating
 *       the same words still speaks (cleared, then written a beat later).
 *   AvA11y.dialog(dlg, { opener, focus, onClose })   the native <dialog>
 *       pattern (WAI-ARIA APG "Dialog (Modal)"): showModal() makes the page
 *       inert and gives Escape; focus goes to the first sensible control (or
 *       the one named), Tab and Shift+Tab go round the dialog, a click on the
 *       backdrop closes it, the page behind does not scroll, and focus goes
 *       back to whatever opened it (or, if that is hidden now, the fallback).
 *   AvA11y.trap(container, event)   the Tab round for a container (a radio
 *       group is one stop: its checked radio, else its first).
 *   AvA11y.disclosure(button)   APG "Disclosure": a button with aria-controls
 *       and aria-expanded shows and hides its region. [data-av-disclosure]
 *       buttons are wired automatically.
 *   AvA11y.reducedMotion()   true when the device asks for less motion OR the
 *       visitor turned on "Stop moving content" (html.a11y-still).
 *   AvA11y.singleKeys()   false when the visitor turned single-key shortcuts
 *       off (html.a11y-nokeys) — WCAG 2.1.4 Character Key Shortcuts. "/" for
 *       search is the only one; Ctrl/⌘ K, Alt+Shift+A and Escape use modifiers.
 *   AvA11y.typing(el)   whether a key press is going into a field.
 *   AvA11y.modalOpen(except)   whether a modal dialog other than `except` is open.
 *   AvA11y.errorSummary(box, errors)   the GOV.UK error summary: "There is a
 *       problem" and a list of links, one per [{ id, text }], each moving
 *       focus to its field; focus goes to the summary so a screen reader reads
 *       it at once. The fields' own messages, aria-invalid and
 *       aria-describedby stay with the form. errorSummary(box, []) hides it.
 *
 * Vanilla ES5-ish, no dependencies, no inline handlers (CSP).
 */
(function () {
  'use strict';
  if (window.AvA11y) return;
  var d = document, html = d.documentElement;

  /* ── The announcer ─────────────────────────────────────────────────── */
  function isModal(el) { try { return el.matches(':modal'); } catch (e) { return true; } }
  var regions = {};
  function region(kind) {
    var r = regions[kind];
    if (!r) {
      r = d.createElement('div');
      r.className = 'av-sr av-announce';
      r.id = 'av-announce-' + kind;
      if (kind === 'assertive') { r.setAttribute('role', 'alert'); }
      else { r.setAttribute('role', 'status'); r.setAttribute('aria-live', 'polite'); }
      r.setAttribute('aria-atomic', 'true');
      regions[kind] = r;
    }
    /* Inside the top-most open modal dialog, or at the end of <body>. */
    var dlgs = d.querySelectorAll('dialog[open]'), host = null;
    for (var i = dlgs.length - 1; i >= 0; i--) if (isModal(dlgs[i])) { host = dlgs[i]; break; }
    host = host || d.body;
    if (host && r.parentNode !== host) host.appendChild(r);
    return r;
  }
  var timers = {};
  function announce(text, opts) {
    if (!d.body) return;
    var kind = opts && opts.assertive ? 'assertive' : 'polite';
    var r = region(kind);
    clearTimeout(timers[kind]);
    r.textContent = '';
    if (!text) return;
    timers[kind] = setTimeout(function () { region(kind).textContent = String(text); }, (opts && opts.delay) || 120);
  }

  /* ── Motion and keys ───────────────────────────────────────────────── */
  var mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  function reducedMotion() { return html.classList.contains('a11y-still') || !!(mq && mq.matches); }
  function singleKeys() { return !html.classList.contains('a11y-nokeys'); }
  function typing(t) {
    t = t || d.activeElement;
    return !!(t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || (t.closest && t.closest('[role="textbox"],[role="combobox"],[role="application"]'))));
  }
  function modalOpen(except) {
    var o = d.querySelectorAll('dialog[open]');
    for (var i = 0; i < o.length; i++) if (o[i] !== except && isModal(o[i])) return true;
    return false;
  }

  /* ── Focus helpers ─────────────────────────────────────────────────── */
  var FOCUSABLE = 'a[href],button,input:not([type=hidden]),select,textarea,summary,[tabindex]';
  function focusables(c) {
    var list = [].filter.call(c.querySelectorAll(FOCUSABLE), function (x) {
      return !x.disabled && x.tabIndex >= 0 && x.getClientRects().length && !x.closest('[inert],[hidden]');
    });
    /* A radio group is one stop: its checked radio, or its first. */
    var seen = {};
    return list.filter(function (x) {
      if (x.type !== 'radio' || !x.name) return true;
      if (seen[x.name]) return false;
      var on = c.querySelector('input[type=radio][name="' + x.name.replace(/"/g, '\\"') + '"]:checked');
      if (on && on !== x) return false;
      seen[x.name] = true; return true;
    });
  }
  function trap(c, e) {
    if (e.key !== 'Tab') return;
    var f = focusables(c);
    if (!f.length) { e.preventDefault(); return; }
    var first = f[0], last = f[f.length - 1], a = d.activeElement;
    if (e.shiftKey && (a === first || !c.contains(a))) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && (a === last || !c.contains(a))) { e.preventDefault(); first.focus(); }
  }
  function focusEl(el) { if (el && el.focus) { try { el.focus({ preventScroll: true }); } catch (x) { el.focus(); } } }

  /* ── The modal dialog ──────────────────────────────────────────────── */
  function dialog(dlg, opts) {
    opts = opts || {};
    if (!dlg || typeof dlg.showModal !== 'function') return false;
    if (dlg.open) return true;
    var opener = opts.opener || d.activeElement;
    if (!dlg.__avWired) {
      dlg.__avWired = true;
      dlg.addEventListener('keydown', function (e) { trap(dlg, e); });
      /* A click on the backdrop (the dialog box itself, outside its content). */
      dlg.addEventListener('click', function (e) {
        if (e.target !== dlg) return;
        var r = dlg.getBoundingClientRect();
        if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) dlg.close();
      });
      dlg.addEventListener('close', function () {
        html.classList.remove('av-modal-open');
        if (modalOpen()) html.classList.add('av-modal-open');
        /* The announcer goes back out with the dialog closed. */
        for (var k in regions) if (regions[k].parentNode === dlg) region(k);
        var o = dlg.__avOpener; dlg.__avOpener = null;
        if (o && o.isConnected && !o.getClientRects().length && dlg.__avFallback) o = dlg.__avFallback();
        if (o && o.isConnected) focusEl(o);
        if (dlg.__avOnClose) dlg.__avOnClose();
      });
    }
    dlg.__avOpener = opener;
    dlg.__avFallback = opts.fallback || null;
    dlg.__avOnClose = opts.onClose || null;
    dlg.showModal();
    html.classList.add('av-modal-open');
    var first = typeof opts.focus === 'function' ? opts.focus() : opts.focus;
    if (!first) first = focusables(dlg).filter(function (x) { return !x.hasAttribute('data-av-close'); })[0];
    focusEl(first || dlg);
    return true;
  }

  /* ── Disclosure ────────────────────────────────────────────────────── */
  function disclosure(btn) {
    if (!btn || btn.__avDisc) return;
    var panel = d.getElementById(btn.getAttribute('aria-controls') || '');
    if (!panel) return;
    btn.__avDisc = true;
    if (!btn.hasAttribute('aria-expanded')) btn.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
    btn.addEventListener('click', function () {
      var open = btn.getAttribute('aria-expanded') !== 'true';
      btn.setAttribute('aria-expanded', String(open));
      panel.hidden = !open;
    });
  }
  function wire() {
    [].forEach.call(d.querySelectorAll('[data-av-disclosure][aria-controls]'), disclosure);
    /* A skip link whose target has gone (a page renamed its main) still lands
       on the main: a safety net under tests/templatecontract.test.php. */
    var skip = d.querySelector('body > a[href^="#"][class*="skip"], body > a.skip-link');
    var main = d.querySelector('main:not([hidden]), [role="main"]:not([hidden])');
    if (skip && main && !d.getElementById(skip.getAttribute('href').slice(1))) {
      if (!main.id) main.id = 'main';
      if (!main.hasAttribute('tabindex')) main.setAttribute('tabindex', '-1');
      skip.setAttribute('href', '#' + main.id);
    }
  }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', wire); else wire();

  /* ── The error summary (GOV.UK Design System pattern) ──────────────── */
  function errorSummary(box, errors) {
    if (!box) return;
    errors = errors || [];
    box.classList.add('av-errsum');
    if (!errors.length) { box.hidden = true; box.innerHTML = ''; return; }
    box.setAttribute('tabindex', '-1');
    /* Focus is what makes it heard (as GOV.UK does); a role of alert as well
       would have some screen readers read it twice. */
    box.removeAttribute('role');
    var h = '<h2 class="av-errsum-h" id="av-errsum-h">There is a problem</h2><ul class="av-errsum-list">';
    errors.forEach(function (x) {
      h += '<li><a href="#' + String(x.id).replace(/[^A-Za-z0-9_-]/g, '') + '">' + String(x.text).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }) + '</a></li>';
    });
    box.innerHTML = h + '</ul>';
    box.hidden = false;
    if (!box.__avWired) {
      box.__avWired = true;
      box.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[href^="#"]') : null;
        var f = a && d.getElementById(a.getAttribute('href').slice(1));
        if (!f) return;
        e.preventDefault();
        var lab = f.id && d.querySelector('label[for="' + f.id + '"]');
        (lab || f).scrollIntoView({ block: 'center', behavior: reducedMotion() ? 'auto' : 'smooth' });
        focusEl(f);
      });
    }
    focusEl(box);
  }

  window.AvA11y = {
    errorSummary: errorSummary,
    announce: announce, dialog: dialog, trap: trap, focusables: focusables, disclosure: disclosure,
    reducedMotion: reducedMotion, singleKeys: singleKeys, typing: typing, modalOpen: modalOpen
  };
})();
