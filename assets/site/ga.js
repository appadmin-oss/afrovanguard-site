/**
 * Google Analytics (GA4) behind a consent notice — the one place the site's
 * tag lives.
 *
 * Loaded on every public page: PHP pages get it from lib/Analytics.php
 * (injected before </head>), the static .html pages carry the same one line.
 *
 *  - Nothing is sent to Google, and no Google cookie is set, until the visitor
 *    presses "Allow" in the notice. "Decline" is the same size and colour —
 *    a notice where yes is a button and no is grey text is not a choice.
 *  - The answer is kept for a year in the `av_consent` cookie
 *    (`analytics=1` or `analytics=0`). Withdrawing it deletes Google's cookies.
 *  - A browser sending Global Privacy Control or Do Not Track has already
 *    answered: no notice, no tag.
 *  - Any element with `data-av-consent-open` reopens the notice ("Cookie
 *    settings" in the footers and on the privacy policy).
 *  - Page addresses go to Google WITHOUT their query string: sign-in, reset,
 *    verify, payment and "manage my gift" links carry secret tokens there.
 *    Campaign tags (utm_*, gclid) are kept.
 *  - Staff tools (the admin console, the Academy Studio, card printing) are
 *    never counted and never show the notice.
 */
(function () {
  'use strict';
  var ID = 'G-K3MXKHNB0W';
  var COOKIE = 'av_consent';
  var n = navigator;
  if (window.__avGa) return;                // included twice: run once
  window.__avGa = true;
  if (/^\/(admin|academy\/studio|card)(\/|$)/.test(location.pathname)) return;
  var refused = n.globalPrivacyControl === true || n.doNotTrack === '1' || window.doNotTrack === '1' || n.msDoNotTrack === '1';

  function choice() {
    var m = document.cookie.match(/(?:^|;\s*)av_consent=analytics%3D([01])|(?:^|;\s*)av_consent=analytics=([01])/);
    return m ? (m[1] || m[2]) : null;
  }
  function remember(v) {
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = COOKIE + '=analytics%3D' + v + '; Max-Age=' + (365 * 86400) + '; Path=/; SameSite=Lax' + secure;
  }
  function forgetGoogle() {
    var host = location.hostname, parts = host.split('.'), domains = [''];
    for (var i = 0; i < parts.length - 1; i++) domains.push('; Domain=.' + parts.slice(i).join('.'));
    document.cookie.split(';').forEach(function (c) {
      var name = c.split('=')[0].trim();
      if (!/^_ga(_|$)|^_gid$|^_gat/.test(name)) return;
      domains.forEach(function (d) { document.cookie = name + '=; Max-Age=0; Path=/' + d; });
    });
  }

  var loaded = false;
  function load() {
    if (loaded) return; loaded = true;
    var keep = [];
    try {
      new URLSearchParams(location.search).forEach(function (v, k) {
        if (/^utm_[a-z]+$/.test(k) || k === 'gclid') keep.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
      });
    } catch (e) { /* very old browser: send the path alone */ }
    var ref = document.referrer ? document.referrer.split(/[?#]/)[0] : '';
    window.dataLayer = window.dataLayer || [];
    function gtag() { window.dataLayer.push(arguments); }
    window.gtag = gtag;
    gtag('js', new Date());
    gtag('config', ID, {
      page_location: location.origin + location.pathname + (keep.length ? '?' + keep.join('&') : ''),
      page_referrer: ref || undefined
    });
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + ID;
    (document.head || document.documentElement).appendChild(s);
  }

  /* ── The notice ── */
  var CSS = '.av-cc{position:fixed;left:16px;right:16px;bottom:16px;z-index:2147483000;max-width:560px;margin:0 auto;'
    + 'background:var(--av-white,#fff);color:var(--av-ink,#111827);border:1px solid var(--av-line,#e5e2da);border-radius:14px;'
    + 'box-shadow:0 12px 40px rgba(17,24,39,.18);padding:18px 20px;font:15px/1.5 var(--av-sans,system-ui,sans-serif)}'
    + '.av-cc[hidden]{display:none}.av-cc h2{margin:0 0 4px;font-size:16px;font-weight:700}.av-cc p{margin:0 0 14px}'
    + '.av-cc a{color:inherit;text-decoration:underline}.av-cc-b{display:flex;gap:10px;flex-wrap:wrap}'
    + '.av-cc-b button{flex:1 1 140px;min-height:44px;padding:0 18px;border-radius:8px;border:1px solid var(--av-ink,#111827);'
    + 'background:var(--av-ink,#111827);color:var(--av-white,#fff);font:600 15px var(--av-sans,system-ui,sans-serif);cursor:pointer}'
    + '.av-cc-b button:focus-visible{outline:3px solid var(--av-focus,#f3b416);outline-offset:2px}';
  var box = null;
  function notice() {
    if (box) { box.hidden = false; focusFirst(); return; }
    var st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);
    box = document.createElement('section');
    box.className = 'av-cc'; box.setAttribute('role', 'region'); box.setAttribute('aria-labelledby', 'av-cc-h');
    box.innerHTML = '<h2 id="av-cc-h">Cookies</h2>'
      + '<p>We’d like to use Google Analytics to count visits and see which pages help people. It sets cookies. '
      + 'Nothing is sent until you allow it. The cookies the site needs to work are listed in our <a href="/privacy-policy/#cookies">privacy policy</a>.</p>'
      + '<div class="av-cc-b"><button type="button" data-v="1">Allow analytics</button><button type="button" data-v="0">Decline</button></div>';
    box.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('button[data-v]'); if (!b) return;
      var v = b.getAttribute('data-v'), was = choice();
      remember(v);
      box.hidden = true;
      if (v === '1') load();
      else { forgetGoogle(); if (was === '1') location.reload(); }   // the running tag stops only on a fresh page
    });
    document.body.appendChild(box);
  }
  function focusFirst() { var b = box && box.querySelector('button'); if (b) b.focus(); }

  function start() {
    document.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('[data-av-consent-open]');
      if (!t) return;
      e.preventDefault();
      if (refused) { sayRefused(t); return; }
      notice(); focusFirst();
    });
    if (refused) return;
    var c = choice();
    if (c === '1') load();
    else if (c === null) notice();
  }
  function sayRefused(el) {
    var p = document.createElement('span');
    p.setAttribute('role', 'status');
    p.textContent = ' Your browser asks sites not to track you, so analytics stays off.';
    if (!el.nextSibling || !el.nextSibling.getAttribute || el.nextSibling.getAttribute('role') !== 'status') el.parentNode.insertBefore(p, el.nextSibling);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
