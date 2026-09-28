/* ============================================================================
 * assets/site/appeals-band.js — fills the home page's live-appeals band.
 * ----------------------------------------------------------------------------
 * The home page is static HTML, so it cannot read the database. This asks
 * /give/feed.json once and paints what comes back.
 *
 * PROGRESSIVE, NOT REQUIRED. The band ships finished: a real heading, real
 * copy and a link to /give/. This only ever ADDS to it, and on any failure —
 * offline, blocked, a 500, a slow network — it removes nothing and says
 * nothing. A visitor who never sees this run sees a complete section, which is
 * the only honest way to put live data on a cached static page.
 *
 * Everything from the feed is inserted as TEXT, never as markup. The figures
 * are pre-formatted server-side by one formatter, so the band cannot punctuate
 * naira differently from the page it links to.
 * ==========================================================================*/
(function () {
  'use strict';

  var band = document.querySelector('[data-appeals-band]');
  if (!band || !window.fetch) return;

  var listEl     = band.querySelector('[data-appeals-list]');
  var needsEl    = band.querySelector('[data-needs-list]');
  var fallbackEl = band.querySelector('[data-appeals-fallback]');

  /** el(tag, className, text) — text goes in as text, always. */
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null && text !== '') n.textContent = String(text);
    return n;
  }

  function meter(pct, met) {
    var wrap = el('div', 'ed-meter');
    wrap.style.marginBottom = 'var(--afg-space-3)';
    if (pct === null || pct === undefined) return wrap;
    var bar = el('div', 'ed-bar ed-bar--slim' + (met ? ' is-met' : ''));
    bar.setAttribute('role', 'progressbar');
    bar.setAttribute('aria-valuenow', String(pct));
    bar.setAttribute('aria-valuemin', '0');
    bar.setAttribute('aria-valuemax', '100');
    bar.setAttribute('aria-label', pct + '% raised');
    var fill = el('span');
    fill.style.width = pct + '%';
    bar.appendChild(fill);
    wrap.appendChild(bar);
    return wrap;
  }

  function appealCard(a) {
    var card = el('a', 'ed-story');
    card.href = a.url;

    if (a.image) {
      var wrap = el('div', 'ed-story-img-wrap');
      var img = el('img', 'ed-story-img');
      img.src = a.image; img.alt = ''; img.loading = 'lazy'; img.decoding = 'async';
      img.width = 580; img.height = 387;
      wrap.appendChild(img);
      card.appendChild(wrap);
    }

    var kicker = a.urgent ? 'Urgent'
      : (a.match_live ? 'Gifts doubled'
      : (a.days_left !== null && a.days_left !== undefined && a.days_left >= 0 && a.days_left <= 7
          ? a.days_left + (a.days_left === 1 ? ' day left' : ' days left')
          : (a.location || a.kind || 'Appeal')));
    card.appendChild(el('span', 'ed-kicker', kicker));
    card.appendChild(el('h3', 'ed-story-title', a.title));
    if (a.tagline) card.appendChild(el('p', 'ed-story-excerpt', a.tagline));
    card.appendChild(meter(a.percent, a.percent >= 100));

    var meta = el('div', 'ed-story-meta');
    var raised = el('strong', null, a.raised_label);
    raised.style.color = '#fff';
    meta.appendChild(raised);
    meta.appendChild(el('span', null,
      a.goal_label ? 'of ' + a.goal_label
        : (a.donors > 0 ? a.donors + (a.donors === 1 ? ' donor' : ' donors') : 'raised so far')));
    card.appendChild(meta);
    return card;
  }

  function needCard(n) {
    var a = el('a', 'ed-need');
    a.href = n.url;
    a.appendChild(el('span', 'ed-need-when', n.when));
    a.appendChild(el('span', 'ed-need-fig', n.figure));
    a.appendChild(el('span', 'ed-need-title', n.title));
    if (n.unit) a.appendChild(el('span', 'ed-need-unit', n.unit));
    a.appendChild(el('span', 'ed-need-for', n.for));
    return a;
  }

  fetch('/give/feed.json?limit=3', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error(String(r.status))); })
    .then(function (data) {
      var appeals = (data && data.appeals) || [];
      var needs   = (data && data.needs) || [];
      /* Nothing live is a real answer, and the written fallback already says
         the right thing in that case — so leave it alone. */
      if (!appeals.length && !needs.length) return;

      if (needs.length && needsEl) {
        needs.slice(0, 4).forEach(function (n) { needsEl.appendChild(needCard(n)); });
        needsEl.hidden = false;
      }
      if (appeals.length && listEl) {
        appeals.forEach(function (a) { listEl.appendChild(appealCard(a)); });
        listEl.hidden = false;
        /* The written fallback was standing in for exactly this. Once the real
           appeals are on screen it is a second, vaguer version of them. */
        if (fallbackEl) fallbackEl.hidden = true;
      }
    })
    .catch(function () { /* the band is already complete without us */ });
})();
