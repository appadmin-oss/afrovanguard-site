/* Events (/events/). Prefix avev-. Vanilla, no dependencies.
   "What's coming up": loads the events feed (/events-feed.php → AvEvents), then
   filters it in the browser by when (Upcoming / This month / Past) and category.
   States: loading (skeleton at final height), empty, error with retry.
   Feed text is only ever set with textContent. */
(() => {
  'use strict';
  const host = document.querySelector('[data-avev-list]');
  if (!host) return;
  const feed = host.getAttribute('data-feed') || '';
  const today = host.getAttribute('data-today') || new Date().toISOString().slice(0, 10);
  const whenG = document.querySelector('[data-avev-when]');
  const catG = document.querySelector('[data-avev-cats]');
  let all = null, w = 'up', c = 'All';

  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
  const safeUrl = u => { if (!u || typeof u !== 'string') return ''; try { const x = new URL(u, location.href); return /^https?:$/.test(x.protocol) ? x.href : ''; } catch (e) { return ''; } };

  /* ---- the cover: the shared renderer (assets/site/avcv.js, twin of partials/av-cover.php) ---- */
  function cover(e) {
    const o = { kind: 'event', ratio: '3:2', title: e.title, date: e.date, time: e.time, location: e.location };
    if (e.st === 'live' && e.date) o.today = e.date;
    if (typeof window.avCover === 'function') return window.avCover(o);
    return el('div', 'avcv');
  }

  /* ---- data ---- */
  const cat = t => { t = t.toLowerCase(); return /bootcamp|storm|school|masterclass|workshop/.test(t) ? (/masterclass|integrity/.test(t) ? 'Leadership' : 'Learning') : /showcase|stardom|gala/.test(t) ? 'Arts' : /town hall|volunteer|parade|community/.test(t) ? 'Community' : 'Leadership'; };
  function norm(x) {
    const title = String(x.title || '').trim();
    const iso = String(x.iso || '');
    const date = /^\d{4}-\d{2}-\d{2}/.test(iso) ? iso.slice(0, 10) : '';
    const parts = String(x.when || '').split(' · ');
    const time = parts.length > 1 ? parts[parts.length - 1] : '';
    const st = x.ongoing || date === today ? 'live' : date && date < today ? 'past' : 'up';
    return { title, date, time, st, url: safeUrl(x.url || '') || 'https://afg.afrovanguard.org.ng/events', when: String(x.when || ''), location: String(x.location || ''),
      excerpt: String(x.excerpt || ''), image: safeUrl(x.image || ''), cat: cat(title), month: date.slice(0, 7) };
  }

  /* ---- render ---- */
  function state(lines, retry) {
    const box = el('div', 'avev-state');
    lines.forEach(t => box.append(el('p', null, t)));
    if (retry) { const b = el('button', 'avev-retry', 'Try again'); b.type = 'button'; b.addEventListener('click', load); box.append(b); }
    host.replaceChildren(box);
    host.setAttribute('aria-busy', 'false');
  }
  function render() {
    const month = today.slice(0, 7);
    const list = all.filter(e => (w === 'up' ? e.st !== 'past' : w === 'month' ? e.month === month : e.st === 'past') && (c === 'All' || e.cat === c))
      .sort((a, b) => w === 'past' ? b.date.localeCompare(a.date) : a.date.localeCompare(b.date));
    if (!list.length) return state(['Nothing on the calendar for this filter yet.', 'New dates are announced in the community first.']);
    const ul = el('ul', 'avev-grid');
    list.forEach(e => {
      const li = el('li'), a = el('a', 'avev-card');
      a.href = e.url; a.target = '_blank'; a.rel = 'noopener';
      const media = el('div', 'avev-card-media');
      if (e.image) { const im = el('img'); im.src = e.image; im.alt = ''; im.loading = 'lazy'; media.append(im); } else media.append(cover(e));
      const body = el('div', 'avev-card-body');
      const kick = el('span', 'avev-kick' + (e.st === 'live' ? ' is-live' : e.st === 'past' ? ' is-past' : ''), e.st === 'live' ? 'Today' : e.st === 'past' ? 'Took place' : 'Upcoming');
      body.append(kick, el('h3', null, e.title), el('span', 'avev-when', [e.when, e.location].filter(Boolean).join(' · ')), el('span', 'avev-ex', e.excerpt));
      a.append(media, body); li.append(a); ul.append(li);
    });
    host.replaceChildren(ul);
    host.setAttribute('aria-busy', 'false');
  }
  function skeleton() {
    const ul = el('ul', 'avev-grid');
    ul.setAttribute('aria-label', 'Loading events');
    for (let i = 0; i < 3; i++) { const li = el('li', 'avev-sk'); li.setAttribute('aria-hidden', 'true'); li.append(el('span'), el('span'), el('span'), el('span')); ul.append(li); }
    host.replaceChildren(ul);
    host.setAttribute('aria-busy', 'true');
  }
  function load() {
    if (!feed) { all = []; render(); return; }
    skeleton();
    const ctl = 'AbortController' in window ? new AbortController() : null;
    const t = setTimeout(() => ctl && ctl.abort(), 10000);
    fetch(feed, { credentials: 'same-origin', signal: ctl ? ctl.signal : undefined })
      .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(d => {
        clearTimeout(t);
        if (!d || !Array.isArray(d.events)) throw new Error('bad feed');
        // Our own summit is featured above the list, so it is not repeated here.
        all = d.events.filter(x => x && x.title && x.source !== 'afrovanguard').map(norm);
        render();
      })
      .catch(() => { clearTimeout(t); state(['We couldn’t load the calendar just now.', 'Check your connection, or see the full calendar on Africa GATES.'], true); });
  }

  /* ---- filters ---- */
  const wire = (group, set) => {
    if (!group) return;
    group.addEventListener('click', ev => {
      const b = ev.target.closest('button[data-v]');
      if (!b) return;
      group.querySelectorAll('button[data-v]').forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
      set(b.getAttribute('data-v'));
      if (all) render();
    });
  };
  wire(whenG, v => { w = v; });
  wire(catG, v => { c = v; });

  load();
})();
