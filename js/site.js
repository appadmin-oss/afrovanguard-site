/**
 * Afrovanguard — Site Module v2.0
 * =========================================================
 * Shared utilities available to all pages. Loaded deferred.
 * No external dependencies. Tree-shaken by usage.
 *
 * Exports (to window.AV namespace):
 *   AV.openBotChat()       — open Botpress webchat
 *   AV.nlSubmit(form)      — newsletter subscription handler
 *   AV.rot13(s)            — decode obfuscated email strings
 *   AV.fadeIn(selector)    — trigger scroll fade-in observer
 *   AV.countUp(el, target) — animate counter
 */
(function (global) {
  'use strict';

  const AV = global.AV || {};

  /* ── ROT-13 decoder ───────────────────────────────────────────── */
  AV.rot13 = function (s) {
    return String(s || '').replace(/[a-zA-Z]/g, function (c) {
      return String.fromCharCode(
        (c <= 'Z' ? 90 : 122) >= (c = c.charCodeAt(0) + 13) ? c : c - 26
      );
    });
  };

  /* ── Open Botpress webchat ────────────────────────────────────── */
  AV.openBotChat = function (e) {
    if (e) e.preventDefault();
    // Close the dock if it's open
    var fab  = document.getElementById('dock-fab');
    var menu = document.getElementById('dock-menu');
    if (fab)  { fab.classList.remove('open');  fab.setAttribute('aria-expanded', 'false'); }
    if (menu) { menu.classList.remove('open'); menu.setAttribute('aria-hidden', 'true'); }
    // Open botpress
    if (window.botpress && typeof window.botpress.open === 'function') {
      window.botpress.open();
    } else {
      // Fallback: WhatsApp
      window.open('https://wa.me/2349037776318', '_blank', 'noopener');
    }
  };

  /* ── LCASP programme data (single source of truth) ───────────── */
  AV.programmes = {
    schoolStorm: {
      name: 'School Storm 2026',
      start: new Date('2026-04-28'),
      end: null,
      duration: '3 months',
      children: '30,000',
      volunteers: 20,
      centres: ['Alimosho LGA, Lagos'],
      href: 'https://cacentre.afrovanguard.org.ng/school-storm/',
      status: 'open'
    },
    summerSchool: {
      name: 'Alimosho Summer School 2026',
      start: new Date('2026-07-28'),
      end: new Date('2026-09-05'),
      duration: '6 weeks',
      children: 600,
      volunteers: 50,
      courses: 10,
      centres: ['Egbeda', 'Mosan', 'Ayobo', 'Idimu', 'Ikotun', 'Ijaiye'],
      href: 'https://cacentre.afrovanguard.org.ng/alimosho-summer-school/',
      status: 'upcoming'
    },
    bootCamp: {
      name: 'BootCamp 2026',
      start: new Date('2026-08-31'),
      end: new Date('2026-09-03'),
      duration: '4 days',
      children: 100,
      volunteers: 20,
      href: 'https://cacentre.afrovanguard.org.ng/',
      status: 'upcoming'
    },
    ogidiOmo: {
      name: 'Ogidi Omo Grand Expo 2026',
      start: new Date('2026-09-05'),
      duration: '1 day',
      children: 600,
      volunteers: 50,
      guests: 500,
      href: 'https://afrovanguard.org.ng/events/',
      status: 'upcoming'
    }
  };

  /* ── Budget data ─────────────────────────────────────────────── */
  AV.budget = {
    total: 40530000,
    categories: {
      capital:    { amount: 28800000, pct: 71.4, label: 'Capital Expenditure' },
      operations: { amount:  6550000, pct: 16.2, label: 'Operational Cost' },
      concert:    { amount:  4780000, pct: 11.9, label: 'Concert & Exhibition' },
      admin:      { amount:   400000, pct:  0.5, label: 'Admin & Structure' }
    }
  };

  /* ── Newsletter submission helper ────────────────────────────── */
  AV.nlSubmit = async function (form, opts) {
    opts = opts || {};
    var input = form.querySelector('input[type="email"]');
    var btn   = form.querySelector('button[type="submit"], button:not([type])');
    var email = input ? input.value.trim() : '';

    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      if (input) { input.focus(); input.style.borderColor = '#f87171'; }
      AV._nlFeedback(form, 'error', 'Please enter a valid email address.');
      setTimeout(function () { if (input) input.style.borderColor = ''; }, 3000);
      return;
    }

    var origHTML = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Subscribing…'; }

    try {
      var fd = new FormData();
      fd.append('action', 'newsletter');
      fd.append('email', email);
      var res  = await fetch('process-contact.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      var data = res.ok ? await res.json() : { success: false, message: 'Server error. Please try again.' };
      if (data.success) {
        AV._nlFeedback(form, 'ok', data.message || "You're in — welcome to the community!");
        form.reset();
        if (opts.onSuccess) opts.onSuccess(data);
      } else {
        AV._nlFeedback(form, 'error', data.message || 'Something went wrong. Please try again.');
      }
    } catch (_) {
      AV._nlFeedback(form, 'error', 'Connection failed. Please check your network and try again.');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = origHTML; }
    }
  };

  AV._nlFeedback = function (form, type, msg) {
    var parent = form.closest('.newsletter-form-wrap') || form.parentElement;
    var el = parent.querySelector('.nl-feedback');
    if (!el) {
      el = document.createElement('p');
      el.className = 'nl-feedback';
      el.style.cssText = 'font-size:13px;margin-top:10px;font-weight:600;display:flex;align-items:center;gap:6px;';
      form.after(el);
    }
    el.style.color = type === 'ok' ? '#22c55e' : '#f87171';
    var icon = type === 'ok'
      ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>'
      : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    el.innerHTML = icon + msg;
    setTimeout(function () { if (el && el.parentElement) el.remove(); }, 7000);
  };

  /* ── Countdown utility (for programme start dates) ───────────── */
  AV.daysUntil = function (date) {
    var now   = new Date();
    var target = new Date(date);
    var diff  = Math.ceil((target - now) / (1000 * 60 * 60 * 24));
    if (diff < 0) return { past: true, days: Math.abs(diff) };
    if (diff === 0) return { today: true, days: 0 };
    return { future: true, days: diff };
  };

  /* ── Format currency ─────────────────────────────────────────── */
  AV.formatNGN = function (amount) {
    return '₦' + Number(amount).toLocaleString('en-NG');
  };

  /* ── Analytics event helper (noop until analytics loaded) ─────── */
  AV.track = function (event, props) {
    if (window.gtag) window.gtag('event', event, props || {});
    if (window.fbq)  window.fbq('track', event);
  };

  /* ── Export ───────────────────────────────────────────────────── */
  global.AV = AV;

})(window);
