/* ============================================================
   sw.js — Afrovanguard service worker (PWA).
   Served from the site root so its scope is the whole site.
   - Precaches a small static app shell + an offline page.
   - Navigations: network-first, fall back to the offline page.
   - Same-origin static assets: stale-while-revalidate.
   Never caches API responses or authenticated HTML.
   ============================================================ */
'use strict';
var VERSION = 'av-pwa-v3';
var SHELL = [
  '/assets/site/offline.html',
  '/assets/site/nav.css',
  '/assets/site/nav.js',
  '/diary/diary.css',
  '/portal/portal.css',
  '/assets/site/icon-192.png',
  '/assets/site/icon-512.png',
];

self.addEventListener('install', function (e) {
  e.waitUntil(
    caches.open(VERSION).then(function (c) {
      // addAll fails the whole install if any 404s — add resiliently instead.
      return Promise.all(SHELL.map(function (u) {
        return c.add(new Request(u, { cache: 'reload' })).catch(function () {});
      }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (k) { if (k !== VERSION) return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

function isStatic(url) {
  return /\.(css|js|png|jpg|jpeg|webp|svg|woff2?|ico)$/i.test(url.pathname);
}

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;          // leave cross-origin (CDNs, APIs) alone
  if (url.pathname.indexOf('/api.php') !== -1 || url.search.indexOf('action=') !== -1) return; // never cache APIs

  // Navigations: try network, fall back to cache, then the offline page.
  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req).catch(function () {
        return caches.match(req).then(function (hit) { return hit || caches.match('/assets/site/offline.html'); });
      })
    );
    return;
  }

  // Static assets: stale-while-revalidate.
  if (isStatic(url)) {
    e.respondWith(
      caches.open(VERSION).then(function (c) {
        return c.match(req).then(function (hit) {
          var net = fetch(req).then(function (res) {
            if (res && res.status === 200 && res.type === 'basic') c.put(req, res.clone());
            return res;
          }).catch(function () { return hit; });
          return hit || net;
        });
      })
    );
  }
});
