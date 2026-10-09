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

  /* ---- the cover (mirror of partials/avev-cover.php) ---- */
  const CATS = { summit: ['Summit', 'arcs'], gala: ['Gala', 'hatch'], workshop: ['Workshop', 'dots'], bootcamp: ['Bootcamp', 'grid'], townhall: ['Town hall', 'contour'],
    meetup: ['Community', 'contour'], arts: ['Arts & showcase', 'chevron'], faith: ['Faith', 'rays'], school: ['Schools', 'grid'], sports: ['Sports & wellbeing', 'chevron'],
    leadership: ['Leadership', 'arcs'], technology: ['Technology', 'grid'], culture: ['Culture', 'chevron'], education: ['Education', 'dots'], nation: ['Nation-building', 'contour'], general: ['', 'hatch'] };
  const HOL = { '10-01': ['independence', 'Independence Day'], '06-12': ['democracyday', 'Democracy Day'], '12-25': ['christmas', 'Christmas'], '01-01': ['newyear', 'New Year'],
    '05-25': ['africaday', 'Africa Day'], '05-27': ['childrensday', 'Children’s Day'], '11-14': ['founding', 'Anniversary'] };
  const INFER = [[/summit|conference|congress/, 'summit'], [/gala|dinner|award|banquet/, 'gala'], [/bootcamp|hackathon|coding|techome/, 'bootcamp'], [/workshop|masterclass|training|clinic|seminar|webinar/, 'workshop'],
    [/town ?hall|forum|assembly|agm/, 'townhall'], [/storm|school|students|tutor|waec|exam/, 'school'], [/showcase|concert|art|music|stardom|talent|film|media/, 'arts'],
    [/prayer|worship|devotion|faith|kingdom|church|mosque|carol/, 'faith'], [/football|sport|match|race|fitness|wellbeing|health/, 'sports'],
    [/meetup|get-together|community|volunteer|chapter|parade/, 'meetup'], [/leader|incorrupt|integrity|character|creed|genius/, 'leadership'],
    [/tech|digital|code|data|laptop/, 'technology'], [/culture|heritage|language|tradition/, 'culture'], [/nation|governance|democracy|citizen|corruption|africa/, 'nation'],
    [/education|learn|appraisal|book|read/, 'education']];
  const infer = t => { t = String(t || '').toLowerCase(); for (const [re, k] of INFER) if (re.test(t)) return k; return 'general'; };
  const hash = s => { let h = 2166136261; for (const ch of String(s)) { h ^= ch.charCodeAt(0); h = Math.imul(h, 16777619); } return Math.abs(h); };
  const M = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  function cover(e) {
    const ck = infer(e.title), [label, motif] = CATS[ck], h = hash(e.title), kick = label || 'Event';
    const meta = [e.time, e.location].filter(Boolean).join(' · ');
    const lx = 70 + h % 30;
    let ox = '0%', oy = '0%', ang = '0deg';
    if (motif === 'arcs') { ox = (88 + h % 14) + '%'; oy = (118 + h % 20) + '%'; }
    if (motif === 'contour') { ox = (80 + h % 20) + '%'; oy = (-20 + h % 25) + '%'; }
    if (motif === 'hatch') ang = (45 + (h % 3) * 15) + 'deg';
    if (motif === 'rays') ang = (h % 30) + 'deg';
    const len = e.title.length;
    const root = el('div', 'avev-cov avev-cov--' + ck + ' avev-m-' + motif + (e.st === 'past' ? ' is-past' : '') + (len > 72 ? ' is-longer' : len > 46 ? ' is-long' : ''));
    root.setAttribute('role', 'img');
    root.setAttribute('aria-label', kick + ': ' + e.title + (meta ? ' — ' + meta : ''));
    root.style.cssText = `--lx:${lx}%;--ly:${h % 40}%;--gx:${100 - (lx - 70)}%;--mx:${h % 22}px;--my:${h % 13}px;--ox:${ox};--oy:${oy};--ang:${ang}`;
    root.append(el('span', 'avev-cov-motif'), el('span', 'avev-cov-light'), el('span', 'avev-cov-grain'));
    const hol = e.date ? HOL[e.date.slice(5, 10)] : null;
    const inner = el('span', 'avev-cov-in'), top = el('span', 'avev-cov-top'), src = el('span', 'avev-cov-src');
    const seal = el('img'); seal.src = '/assets/site/av-seal.png'; seal.alt = '';
    src.append(seal, el('span', null, 'Afrovanguard · Events' + (hol ? ' · ' + hol[1] : '')));
    top.append(src);
    if (e.st === 'live') top.append(el('span', 'avev-cov-live', 'Live now'));
    const bot = el('span', 'avev-cov-bot');
    if (e.date) {
      const d = new Date(e.date + 'T00:00:00'), date = el('span', 'avev-cov-date'), mon = el('span', 'avev-cov-mon');
      mon.append(el('span', null, M[d.getMonth()] + ' ' + d.getFullYear()), el('span', null, e.st === 'past' ? 'Took place' : kick));
      date.append(el('span', 'avev-cov-day', String(d.getDate())), mon);
      bot.append(date);
    } else bot.append(el('span', 'avev-cov-kick', kick));
    bot.append(el('span', 'avev-cov-title', e.title));
    if (meta) { const m = el('span', 'avev-cov-meta'); m.append(el('span', null, meta)); bot.append(m); }
    inner.append(top, bot);
    root.append(inner);
    if (hol) root.append(el('span', 'avev-cov-stripe avev-hol-' + hol[0]));
    return root;
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
