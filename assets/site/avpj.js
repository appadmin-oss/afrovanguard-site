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
})();
