/* assets/site/a11y.js — the Accessibility panel (lib/A11y.php, a11y.css).
 *
 * A native <dialog>, opened from the header's "Accessibility" control, the
 * footer link or Alt+Shift+A. Focus goes in and stays in (showModal makes the
 * page behind it inert), Escape closes it, and focus goes back to whatever
 * opened it. Every control is a native radio or checkbox; a change applies at
 * once and is kept:
 *   · localStorage av.a11y and the av_a11y cookie (PHP reads the cookie, so the
 *     next page is drawn with the classes and nothing flashes);
 *   · for a signed-in member, their account too (portal/prefs.php), newest wins,
 *     so the settings follow them to another device.
 * These are conveniences on top of the pages; the wording says so and claims
 * nothing more. Vanilla ES5-ish, no dependencies.
 */
(function () {
  'use strict';
  if (window.__avA11y) return; window.__avA11y = true;
  var d = document.documentElement, KEY = 'av.a11y', AT = 'av.a11y.at', CK = 'av_a11y';
  var TOKEN = /^(t125|t150|t175|t200|spacing|font|links|focus|still|targets|hc-auto|hc-light|hc-dark)$/;
  var FLAGS = [
    ['spacing', 'Comfortable spacing', 'More room between lines, words and letters'],
    ['font', 'Easy-to-read font', 'A plain, clear typeface (Atkinson Hyperlegible)'],
    ['links', 'Underline all links', 'So links do not rely on colour'],
    ['focus', 'Large focus outline', 'A thick outline round whatever the keyboard is on'],
    ['still', 'Stop animation and moving content', 'Pauses the news ticker, slideshows, videos that play by themselves and transitions'],
    ['targets', 'Bigger buttons and links', 'At least 44 pixels to tap or click; links inside sentences keep their size']
  ];
  var SIZES = [100, 125, 150, 175, 200];
  var CONTRAST = [['', 'Standard'], ['hc-auto', 'High contrast, matching my device'], ['hc-light', 'High contrast, light'], ['hc-dark', 'High contrast, dark']];

  /* ── Reading and writing the value ─────────────────────────────────── */
  function parse(v) {
    var s = { text: 100, contrast: '', flags: {} };
    if (!v || !/^[a-z0-9.@-]{1,80}$/.test(v)) return s;
    String(v).split('@')[0].split('.').forEach(function (t) {
      if (!TOKEN.test(t)) return;
      if (t.charAt(0) === 't' && /^t\d{3}$/.test(t)) s.text = +t.slice(1);
      else if (t.indexOf('hc-') === 0) s.contrast = t;
      else s.flags[t] = true;
    });
    return s;
  }
  function serialize(s) {
    var t = [];
    if (s.text !== 100) t.push('t' + s.text);
    FLAGS.forEach(function (f) { if (s.flags[f[0]]) t.push(f[0]); });
    if (s.contrast) t.push(s.contrast);
    return t.length ? t.join('.') : '0';
  }
  function cookieVal() { var m = document.cookie.match(/(?:^|; )av_a11y=([a-z0-9.-]{1,80})(?:;|$)/); return m ? m[1] : null; }
  function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) {} }
  function writeCookie(v) {
    document.cookie = CK + '=' + v + ';path=/;max-age=31536000;samesite=lax' + (location.protocol === 'https:' ? ';secure' : '');
  }

  var state = parse(cookieVal() || lsGet(KEY));
  /* The cookie is what the server drew this page with; keep localStorage in step. */
  (function () { var c = cookieVal(); if (c !== null && c !== lsGet(KEY)) { lsSet(KEY, c); if (!lsGet(AT)) lsSet(AT, '0'); } })();

  function apply(s) {
    var c = d.className.replace(/(^|\s)a11y-\S+/g, '').replace(/^\s+/, '');
    var v = serialize(s);
    if (v !== '0') v.split('.').forEach(function (t) { c += ' a11y-' + t + (t.indexOf('hc-') === 0 ? ' a11y-hc' : ''); });
    d.className = c.replace(/^\s+/, '');
    stillMedia(!!s.flags.still);
    try { document.dispatchEvent(new CustomEvent('av:a11y', { detail: { still: !!s.flags.still, value: v } })); } catch (e) {}
  }
  function save(s, fromServer, at) {
    state = s;
    var v = serialize(s);
    lsSet(KEY, v); lsSet(AT, String(at || Math.floor(Date.now() / 1000)));
    writeCookie(v);
    apply(s);
    if (!fromServer) push(v);
  }

  /* ── "Stop moving content": what CSS cannot stop ──────────────────── */
  function stillMedia(on) {
    if (!on) return;
    document.querySelectorAll('video').forEach(function (v) {
      if (v.autoplay || (!v.paused && v.muted)) { try { v.pause(); } catch (e) {} v.autoplay = false; v.removeAttribute('autoplay'); }
    });
  }
  /* Videos added later (a lazy section, the portal's views) are caught too. */
  if (window.MutationObserver) new MutationObserver(function () { if (state.flags.still) stillMedia(true); })
    .observe(document.documentElement, { childList: true, subtree: true });

  /* ── A signed-in member's account ─────────────────────────────────── */
  var signedIn = /(^|; )av_si=1/.test(document.cookie);
  var csrf = null;
  function token() {
    if (csrf) return csrf;
    csrf = fetch('/portal/csrf.php', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; }).then(function (j) { return j && j.ok ? j.token : ''; })
      .catch(function () { csrf = null; return ''; });
    return csrf;
  }
  function push(v) {
    if (!signedIn || !window.fetch) return;
    var at = +(lsGet(AT) || 0);
    token().then(function (t) {
      if (!t) return;
      return fetch('/portal/prefs.php?action=set_a11y', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t, Accept: 'application/json' },
        body: JSON.stringify({ value: v, at: at })
      });
    }).catch(function () {});
  }
  function pull() {
    if (!signedIn || !window.fetch) return;
    try { if (sessionStorage.getItem('av.a11y.sync')) return; sessionStorage.setItem('av.a11y.sync', '1'); } catch (e) {}
    fetch('/portal/prefs.php?action=get', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.ok || typeof j.a11y !== 'string') return;
        var localAt = +(lsGet(AT) || 0), serverAt = +(j.a11y_at || 0), local = serialize(state), remote = j.a11y || '0';
        if (serverAt > localAt && remote !== local) { save(parse(remote), true, serverAt); sync(); }
        else if (localAt > serverAt && remote !== local) push(local);
      }).catch(function () {});
  }

  /* ── The dialog ───────────────────────────────────────────────────── */
  var dlg = null, opener = null, status = null;
  function el(tag, attrs, html) {
    var e = document.createElement(tag);
    for (var k in attrs) if (Object.prototype.hasOwnProperty.call(attrs, k)) e.setAttribute(k, attrs[k]);
    if (html != null) e.innerHTML = html;
    return e;
  }
  var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
  function build() {
    dlg = el('dialog', { 'class': 'av-a11y', id: 'av-a11y', 'aria-labelledby': 'av-a11y-h', 'aria-describedby': 'av-a11y-d' });
    var h = '<div class="av-a11y-in">'
      + '<div class="av-a11y-hd"><h2 id="av-a11y-h">Accessibility settings</h2>'
      + '<button type="button" class="av-a11y-x" data-a11y-close aria-label="Close accessibility settings"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg></button></div>'
      + '<p class="av-a11y-lede" id="av-a11y-d">These change how this site looks for you. Your own device’s settings, screen reader and magnifier work here too.</p>'
      + '<fieldset><legend>Text size</legend><div class="av-a11y-seg">';
    SIZES.forEach(function (n) { h += '<label><input type="radio" name="av-a11y-text" value="' + n + '"> ' + n + '%</label>'; });
    h += '</div></fieldset><fieldset><legend>Contrast</legend><div class="av-a11y-opts">';
    CONTRAST.forEach(function (c) { h += '<label class="av-a11y-opt"><input type="radio" name="av-a11y-hc" value="' + c[0] + '"><span>' + c[1] + '</span></label>'; });
    h += '</div></fieldset><fieldset><legend>Reading and moving</legend><div class="av-a11y-opts">';
    FLAGS.forEach(function (f) { h += '<label class="av-a11y-opt"><input type="checkbox" name="av-a11y-' + f[0] + '" value="' + f[0] + '"><span>' + f[1] + '<small>' + f[2] + '</small></span></label>'; });
    h += '</div></fieldset>'
      + '<p class="av-a11y-note">Changes apply straight away and are kept on this device' + (signedIn ? ' and in your account' : '') + '. Open this panel from any page with <kbd>' + (mac ? 'Option' : 'Alt') + '</kbd> + <kbd>Shift</kbd> + <kbd>A</kbd>.</p>'
      + '<div class="av-a11y-ft"><button type="button" class="av-a11y-reset" data-a11y-reset>Reset to standard</button><button type="button" class="av-a11y-done" data-a11y-close>Done</button></div>'
      + '<p class="av-a11y-more"><a href="/accessibility/">Our accessibility statement</a> — what works, what falls short, and how to tell us about a barrier.</p>'
      + '<p class="av-a11y-sr" role="status" aria-live="polite" data-a11y-status></p>'
      + '</div>';
    dlg.innerHTML = h;
    document.body.appendChild(dlg);
    status = dlg.querySelector('[data-a11y-status]');
    dlg.addEventListener('change', onChange);
    dlg.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-a11y-close],[data-a11y-reset]') : null;
      if (e.target === dlg) { close(); return; } // a click on the backdrop
      if (!t) return;
      if (t.hasAttribute('data-a11y-reset')) { save(parse('0')); sync(); say('Settings reset to standard.'); return; }
      close();
    });
    dlg.addEventListener('close', restore);
    /* Tab and Shift+Tab go round the dialog, never out to the address bar and back. */
    dlg.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      var f = [].filter.call(dlg.querySelectorAll('button,a[href],input:not([type=radio]),input[type=radio]:checked,select'), function (x) { return !x.disabled && x.offsetParent !== null; });
      var named = {}; // a radio group with nothing checked is reached by its first radio
      dlg.querySelectorAll('input[type=radio]').forEach(function (r) { if (!named[r.name]) named[r.name] = r; });
      Object.keys(named).forEach(function (n) { if (!dlg.querySelector('input[name="' + n + '"]:checked')) f.push(named[n]); });
      f.sort(function (a, b) { return a.compareDocumentPosition(b) & 4 ? -1 : 1; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
  }
  function say(t) { if (!status) return; status.textContent = ''; setTimeout(function () { status.textContent = t; }, 50); }
  function sync() {
    if (!dlg) return;
    dlg.querySelectorAll('input[name="av-a11y-text"]').forEach(function (i) { i.checked = +i.value === state.text; });
    dlg.querySelectorAll('input[name="av-a11y-hc"]').forEach(function (i) { i.checked = i.value === state.contrast; });
    FLAGS.forEach(function (f) { var i = dlg.querySelector('input[name="av-a11y-' + f[0] + '"]'); if (i) i.checked = !!state.flags[f[0]]; });
  }
  function onChange(e) {
    var i = e.target, s = parse(serialize(state));
    if (i.name === 'av-a11y-text') s.text = +i.value;
    else if (i.name === 'av-a11y-hc') s.contrast = i.value;
    else if (i.type === 'checkbox') { if (i.checked) s.flags[i.value] = true; else delete s.flags[i.value]; }
    save(s);
  }
  function open(from) {
    if (typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) { location.href = '/accessibility/#settings'; return; }
    if (!dlg) build();
    if (dlg.open) return;
    opener = from || document.activeElement;
    sync();
    dlg.showModal();
    var first = dlg.querySelector('input[name="av-a11y-text"]:checked') || dlg.querySelector('input');
    if (first) first.focus();
    document.querySelectorAll('[data-a11y-open][aria-expanded]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
  }
  function close() { if (dlg && dlg.open) dlg.close(); }
  function restore() {
    document.querySelectorAll('[data-a11y-open][aria-expanded]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    var o = opener; opener = null;
    /* Back to the control that opened it — or, if that one is hidden at this
       width now, its twin that is showing. */
    if (o && o.isConnected && o.offsetParent === null && o.hasAttribute && o.hasAttribute('data-a11y-open')) {
      var twin = [].filter.call(document.querySelectorAll('[data-a11y-open]'), function (b) { return b.offsetParent !== null; })[0];
      if (twin) o = twin;
    }
    if (o && o.focus && o.isConnected) { try { o.focus(); } catch (e) {} }
  }

  /* ── The ways in ──────────────────────────────────────────────────── */
  function upgrade() {
    var openers = document.querySelectorAll('[data-a11y-open]');
    /* A page drawn without the shared header still gets the control. */
    if (![].some.call(openers, function (o) { return !o.hasAttribute('data-a11y-link'); })) {
      var host = document.querySelector('body > header, header[role="banner"], .avsi-head, header');
      var a = el('a', { 'class': 'av-a11y-btn av-a11y-btn--compact' + (host ? '' : ' av-a11y-float'), href: '/accessibility/#settings', 'data-a11y-open': '' },
        '<svg class="av-a11y-ico" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10.25" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="6.9" r="1.6" fill="currentColor"/><path d="M6.8 9.6l5.2 1.1 5.2-1.1M12 10.7v3.4m0 0l-2.6 4.6M12 14.1l2.6 4.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="av-a11y-btn-t">Accessibility</span>');
      if (host) host.appendChild(a); else document.body.insertBefore(a, document.body.firstChild);
    }
    /* The header control is a link so it works without JavaScript; with it,
       it is what it does — a button that opens a dialog. */
    document.querySelectorAll('a[data-a11y-open]:not([data-a11y-link])').forEach(function (a) {
      var b = el('button', { type: 'button', 'class': a.className, 'data-a11y-open': '', 'aria-haspopup': 'dialog', 'aria-expanded': 'false', 'aria-controls': 'av-a11y', title: 'Accessibility settings (' + (mac ? 'Option' : 'Alt') + '+Shift+A)' });
      b.innerHTML = a.innerHTML;
      a.parentNode.replaceChild(b, a);
    });
    document.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-a11y-open]') : null;
      if (!t || e.defaultPrevented || e.button > 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
      e.preventDefault(); open(t);
    });
  }
  document.addEventListener('keydown', function (e) {
    /* Alt+Shift+A (Option+Shift+A on a Mac, where the key types "Å", hence e.code). */
    if (e.altKey && e.shiftKey && !e.ctrlKey && !e.metaKey && (e.code === 'KeyA' || e.key === 'A' || e.key === 'a')) {
      e.preventDefault();
      if (dlg && dlg.open) close(); else open(null);
    }
  });

  function init() {
    upgrade();
    document.querySelectorAll('.a11y-page-open').forEach(function (p) { p.hidden = false; });
    apply(state);
    pull();
    /* The statement page's "Open the settings" and a #settings arrival from the panel's fallback. */
    if (location.hash === '#a11y-open') open(null);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
