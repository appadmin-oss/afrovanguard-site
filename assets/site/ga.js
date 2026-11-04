/**
 * Google Analytics (GA4) — the one place the site's tag lives.
 *
 * Loaded on every public page: PHP pages get it from lib/Analytics.php
 * (injected before </head>), the static .html pages carry the same one line.
 * It does what Google's snippet does, with three differences on purpose:
 *
 *  - Page addresses go to Google WITHOUT their query string. Sign-in, reset,
 *    verify, payment and "manage my gift" links carry secret tokens there, and
 *    a page_location is stored by Google for good. Campaign tags (utm_*,
 *    gclid) are kept, so campaign reports still work.
 *  - A browser sending Global Privacy Control or Do Not Track is not counted.
 *  - Staff tools (the admin console, the Academy Studio, card printing) are
 *    never counted.
 */
(function () {
  'use strict';
  var ID = 'G-K3MXKHNB0W';
  var n = navigator;
  if (n.globalPrivacyControl === true || n.doNotTrack === '1' || window.doNotTrack === '1' || n.msDoNotTrack === '1') return;
  if (/^\/(admin|academy\/studio|card)(\/|$)/.test(location.pathname)) return;
  if (window.__avGa) return;                // included twice: count once
  window.__avGa = true;

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
})();
