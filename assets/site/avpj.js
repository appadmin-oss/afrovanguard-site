/**
 * Projects index (/projects/) behaviour. Vanilla ES2019, no dependencies.
 * Ported from the design's pf filter state: one category at a time, counts fixed.
 * Without JS the filter row is hidden (.no-js) and every program shows.
 */
(() => {
  'use strict';
  const run = (name, fn) => { try { fn(); } catch (err) { console.error('[avpj] ' + name, err); } };

  run('filter', () => {
    const group = document.querySelector('[data-avpj-filters]');
    const grid = document.querySelector('[data-avpj-progs]');
    if (!group || !grid) return;
    const btns = Array.from(group.querySelectorAll('[role="radio"]'));
    const cards = Array.from(grid.querySelectorAll('[data-cat]'));
    const status = document.querySelector('[data-avpj-count]');

    const select = (btn, focus) => {
      const f = btn.dataset.f;
      btns.forEach(b => {
        const on = b === btn;
        b.setAttribute('aria-checked', String(on));
        b.tabIndex = on ? 0 : -1;
      });
      let shown = 0;
      cards.forEach(c => {
        const show = f === 'All' || c.dataset.cat === f;
        c.hidden = !show;
        if (show) shown++;
      });
      if (status) status.textContent = 'Showing ' + shown + (shown === 1 ? ' program' : ' programs') + (f === 'All' ? '' : ' in ' + f) + '.';
      if (focus) btn.focus();
    };

    btns.forEach((b, i) => {
      b.addEventListener('click', () => select(b, false));
      b.addEventListener('keydown', e => {
        let n = null;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') n = (i + 1) % btns.length;
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') n = (i - 1 + btns.length) % btns.length;
        else if (e.key === 'Home') n = 0;
        else if (e.key === 'End') n = btns.length - 1;
        if (n === null) return;
        e.preventDefault();
        select(btns[n], true);
      });
    });
  });
  run('open', () => {
    /* The campaigns running now, from the appeals (live and inside their
       dates). Text only; images and links only from this site or https. */
    const wrap = document.querySelector('[data-avpj-open]'), list = document.querySelector('[data-avpj-open-list]');
    if (!wrap || !list || !window.fetch) return;
    const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text) n.textContent = String(text); return n; };
    const safe = u => (typeof u === 'string' && /^(\/(?!\/)|https:\/\/)/.test(u)) ? u : '';
    const day = iso => { try { return new Date(iso + 'T12:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }); } catch (e) { return ''; } };
    fetch('/give/feed.json?limit=4', { headers: { Accept: 'application/json' } })
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      .then(d => {
        const ap = ((d && d.appeals) || []).filter(a => a && a.title && safe(a.url)).slice(0, 2);
        if (!ap.length) return;
        list.replaceChildren(...ap.map((a, n) => {
          const card = el('a', 'avpj-open-card'); card.href = safe(a.url);
          const src = safe(a.image);
          if (src) { const img = el('img'); Object.assign(img, { src, alt: '', width: 1200, height: 675, loading: n ? 'lazy' : 'eager' }); card.appendChild(img); }
          const cap = el('div', 'avpj-open-cap'), body = el('div');
          const when = a.ends_on ? 'Open until ' + day(a.ends_on) : 'Open now';
          body.append(el('div', 'avpj-kicker avpj-kicker--light', when), el('h3', null, a.title));
          if (a.tagline) body.appendChild(el('p', null, a.tagline));
          cap.append(body, el('span', 'avpj-open-cta', 'See the campaign'));
          card.appendChild(cap); return card;
        }));
        wrap.hidden = false;
      })
      .catch(() => {});
  });
})();
