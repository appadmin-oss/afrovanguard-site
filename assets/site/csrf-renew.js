/* assets/site/csrf-renew.js — a page left open keeps working.
 *
 * The anti-forgery token a page is given expires two hours after it loads
 * (lib/security.php av_csrf_token). Every page script read it once, at load,
 * so a portal tab left open longer refused every save with "Bad token." and
 * a 403 — a member's money request among them — until they reloaded.
 *
 * Loaded first on every page, this:
 *   · renews the token every 50 minutes while the page is open
 *     (/portal/csrf.php, the signed-in member's own);
 *   · sends the newest token on every same-origin request that carries one,
 *     whatever the script that made it was given at load;
 *   · and when a request is still refused for an expired token, renews it and
 *     sends the request once more. A second refusal is passed on as it is.
 * It touches only requests that already carry an X-CSRF-Token header.
 */
(function () {
  'use strict';
  if (!window.fetch || window.__avCsrfRenew) return;
  window.__avCsrfRenew = true;
  var orig = window.fetch.bind(window);
  var latest = '';
  var pending = null;
  var EXPIRED = /^(Bad token\.|Session expired)/;

  function sameOrigin(url) {
    try { return new URL(url, location.href).origin === location.origin; } catch (e) { return false; }
  }
  function headerOf(init) {
    var h = init && init.headers; if (!h) return '';
    if (typeof Headers !== 'undefined' && h instanceof Headers) return h.get('X-CSRF-Token') || '';
    for (var k in h) if (Object.prototype.hasOwnProperty.call(h, k) && k.toLowerCase() === 'x-csrf-token') return String(h[k] || '');
    return '';
  }
  function withToken(init, token) {
    var out = {}; for (var k in init) if (Object.prototype.hasOwnProperty.call(init, k)) out[k] = init[k];
    var h = init.headers;
    if (typeof Headers !== 'undefined' && h instanceof Headers) { var n = new Headers(h); n.set('X-CSRF-Token', token); out.headers = n; return out; }
    var o = {}; for (var j in h) if (Object.prototype.hasOwnProperty.call(h, j) && j.toLowerCase() !== 'x-csrf-token') o[j] = h[j];
    o['X-CSRF-Token'] = token; out.headers = o; return out;
  }
  /* The new token also goes onto the page, for anything that reads it later. */
  function publish(token) {
    latest = token;
    try {
      document.querySelectorAll('[data-csrf]').forEach(function (el) { el.setAttribute('data-csrf', token); });
      document.querySelectorAll('input[name="csrf"], input[name="csrf_token"]').forEach(function (el) { if (el.form && el.form.method && el.form.method.toLowerCase() === 'post') el.value = token; });
      var m = document.querySelector('meta[name="csrf-token"]'); if (m) m.setAttribute('content', token);
    } catch (e) {}
  }
  function renew() {
    if (pending) return pending;
    pending = orig('/portal/csrf.php', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { pending = null; if (d && d.ok && d.token) { publish(d.token); return d.token; } return ''; })
      .catch(function () { pending = null; return ''; });
    return pending;
  }

  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    if (!init || !headerOf(init) || !sameOrigin(url)) return orig(input, init);
    var send = latest ? withToken(init, latest) : init;
    return orig(input, send).then(function (res) {
      if (res.status !== 403) return res;
      return res.clone().json().then(function (d) {
        if (!d || !EXPIRED.test(String(d.error || ''))) return res;
        return renew().then(function (t) { return t ? orig(input, withToken(init, t)) : res; });
      }, function () { return res; });
    });
  };
  setInterval(renew, 50 * 60 * 1000);
})();
