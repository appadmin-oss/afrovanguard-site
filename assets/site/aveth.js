/**
 * Ethos (/ethos/) behaviour. Vanilla ES2019, no dependencies. Nav, footer, contour texture and
 * reduced motion are handled by avh.js. Without JS the contents list is plain anchor links.
 */
(() => {
  'use strict';
  const run = (name, fn) => { try { fn(); } catch (err) { console.error('[aveth] ' + name, err); } };

  run('toc', () => {
    // Contents list for the nine commitments: marks the commitment being read (design: the one
    // under the pointer; ported to the one in view, so it also follows keyboard and touch reading).
    const toc = document.querySelector('[data-aveth-toc]'); if (!toc) return;
    const links = Array.from(toc.querySelectorAll('[data-aveth-toc-link]'));
    const arts = Array.from(document.querySelectorAll('[data-aveth-commit]'));
    if (!links.length || !arts.length) return;
    let on = 0;
    const set = i => {
      if (i === on || !links[i]) return; on = i;
      links.forEach((a, k) => (k === i ? a.setAttribute('aria-current', 'true') : a.removeAttribute('aria-current')));
    };
    arts.forEach(a => a.addEventListener('mouseenter', () => set(+a.dataset.avethCommit)));
    // The commitment being read = the last one whose top has passed the sticky line (nav 68px + breathing room).
    let raf = 0;
    const pick = () => {
      raf = 0;
      let i = 0;
      arts.forEach((a, k) => { if (a.getBoundingClientRect().top <= 140) i = k; });
      set(i);
    };
    addEventListener('scroll', () => { if (!raf) raf = requestAnimationFrame(pick); }, { passive: true });
    addEventListener('hashchange', pick);
    pick();
  });
})();
