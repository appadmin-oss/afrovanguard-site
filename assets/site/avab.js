/**
 * About (/about.html) behaviour. Vanilla ES2019, no dependencies. Nav, footer, contour texture,
 * count-up and reduced motion are handled by avh.js; this file only wires the page's live data.
 */
(() => {
  'use strict';
  const run = (name, fn) => { try { fn(); } catch (err) { console.error('[avab] ' + name, err); } };

  run('team', () => {
    // Our people · leadership spotlight. Same source as about.html v1: GET /api.php?action=members
    // → {status, members:[{id,name,role,tier,featured,photo,…}]} (lib/people.php av_team_members()).
    // Featured management + director members, as v1's spotlight. Stays hidden when empty or on error.
    const box = document.querySelector('[data-avab-team]');
    const list = box && box.querySelector('[data-avab-team-list]');
    if (!list || !window.fetch) return;
    const LEAD = ['management', 'director'];
    const TIER = { management: 'Management', director: 'Director' };
    const slug = n => String(n || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    const url = m => '/people/' + (parseInt(m.id, 10) || 0) + '-' + slug(m.name) + '/'; // = av_person_url()
    const ini = n => String(n || '').trim().split(/\s+/).slice(0, 2).map(w => w.charAt(0)).join('').toUpperCase();
    const photo = p => { const m = String(p || '').match(/\/file\/d\/([^/?]+)/); return m ? 'https://drive.google.com/uc?export=view&id=' + m[1] : String(p || ''); };
    const el = (tag, cls, txt) => { const e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; };

    const ctl = 'AbortController' in window ? new AbortController() : null;
    const timer = setTimeout(() => ctl && ctl.abort(), 10000);
    fetch('/api.php?action=members', { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: ctl ? ctl.signal : undefined })
      .then(r => (r.ok ? r.json() : null))
      .then(d => {
        clearTimeout(timer);
        const people = (d && d.status === 'ok' && Array.isArray(d.members) ? d.members : [])
          .filter(m => m && m.featured && LEAD.includes(m.tier) && m.name).slice(0, 8);
        if (!people.length) return;
        list.replaceChildren(...people.map(m => {
          const li = el('li'), a = el('a', 'avab-person'), ph = el('div', 'avab-person-ph'), b = el('div', 'avab-person-b');
          a.href = url(m);
          const initials = el('span', null, ini(m.name)); initials.setAttribute('aria-hidden', 'true');
          ph.appendChild(initials);
          const src = photo(m.photo);
          if (src.length > 4) {
            const img = new Image(); img.alt = ''; img.loading = 'lazy'; img.decoding = 'async';
            img.onload = () => ph.replaceChildren(img);
            img.src = src;
          }
          b.append(el('small', null, TIER[m.tier] || ''), el('b', null, m.name), el('span', null, m.role || ''));
          a.append(ph, b); li.appendChild(a);
          return li;
        }));
        box.hidden = false;
      })
      .catch(() => clearTimeout(timer));
  });
})();
