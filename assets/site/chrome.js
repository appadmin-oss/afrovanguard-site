/* assets/site/chrome.js — shared behaviour for the unified footer on the
 * static marketing pages (the PHP pages use diary.js for the same things).
 * Deliberately tiny and scoped to elements those pages don't otherwise use,
 * so it never collides with a page's own navigation script. */
(function () {
  'use strict';

  // Theme toggle — shares the 'av.theme' key with the Diary/Academy pages so
  // a chosen theme persists across the whole site. (A no-FOUC boot script in
  // <head> applies the saved theme before paint.)
  var root = document.documentElement;
  function applyTheme(t) {
    root.setAttribute('data-theme', t);
    try { localStorage.setItem('av.theme', t); } catch (e) {}
  }
  document.querySelectorAll('.theme-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      applyTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    });
  });

  // Footer newsletter — same endpoint/shape as the Diary footer form.
  document.querySelectorAll('.diary-subscribe').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var input = form.querySelector('input[type="email"]');
      var email = ((input && input.value) || '').trim();
      var msg = form.querySelector('.sub-msg');
      var btn = form.querySelector('button[type="submit"]');
      var setMsg = function (t, ok) { if (msg) { msg.textContent = t; msg.style.color = ok ? '#16a34a' : '#dc2626'; } };
      if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { setMsg('Please enter a valid email address.', false); return; }
      if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'Subscribing…'; }
      fetch('/diary/api.php?action=subscribe', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email, hp: (form.querySelector('[name=hp]') || {}).value || '' })
      }).then(function (r) { return r.json(); })
        .then(function (d) { setMsg(d && d.ok ? (d.message || 'You’re subscribed — watch for the next dispatch.') : ((d && d.error) || 'Could not subscribe.'), !!(d && d.ok)); if (d && d.ok) form.reset(); })
        .catch(function () { setMsg('Network error — please try again.', false); })
        .finally(function () { if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label || 'Subscribe →'; } });
    });
  });
})();
