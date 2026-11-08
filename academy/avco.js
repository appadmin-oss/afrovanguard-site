/**
 * Academy course page: tabs (Overview · Curriculum · Certificate) and the FAQ.
 * Payments, pass codes, the lead form and sign-in links live in avac.js.
 * Without JS every panel shows (the tabs are hidden) and every answer is open.
 */
(() => {
  'use strict';
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const run = (name, fn) => { try { fn(); } catch (err) { console.error('[avco] ' + name, err); } };

  run('tabs', () => {
    const tabs = $$('[data-avco-tab]');
    const panels = $$('[data-avco-panel]');
    if (!tabs.length) return;
    const show = (name, push) => {
      tabs.forEach(t => {
        const on = t.dataset.avcoTab === name;
        t.setAttribute('aria-selected', String(on));
        t.tabIndex = on ? 0 : -1;
      });
      panels.forEach(p => { p.hidden = p.dataset.avcoPanel !== name; });
      if (push) { try { history.replaceState(null, '', '#' + name); } catch (e) { /* file:// */ } }
    };
    tabs.forEach((t, i) => {
      t.addEventListener('click', () => show(t.dataset.avcoTab, true));
      t.addEventListener('keydown', e => {
        const k = e.key;
        if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(k)) return;
        e.preventDefault();
        const n = k === 'Home' ? 0 : k === 'End' ? tabs.length - 1 : (i + (k === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        tabs[n].focus(); show(tabs[n].dataset.avcoTab, true);
      });
    });
    const hash = location.hash.replace('#', '');
    if (tabs.some(t => t.dataset.avcoTab === hash)) show(hash, false);
  });

  run('faq', () => {
    const btns = $$('[data-avco-faq]');
    btns.forEach(b => b.addEventListener('click', () => {
      const open = b.getAttribute('aria-expanded') !== 'true';
      btns.forEach(x => {
        const on = x === b && open;
        x.setAttribute('aria-expanded', String(on));
        const a = document.getElementById(x.getAttribute('aria-controls'));
        if (a) a.hidden = !on;
      });
    }));
  });
  /* Ratings and reviews: save, then reload so the summary and list agree. */
  run('review', () => {
    const form = document.querySelector('[data-avco-review]'); if (!form) return;
    const msg = form.querySelector('[data-avco-review-msg]');
    form.addEventListener('submit', e => {
      e.preventDefault();
      const r = form.querySelector('input[name="rating"]:checked');
      if (!r) { if (msg) { msg.hidden = false; msg.textContent = 'Choose a star rating.'; } return; }
      const b = form.querySelector('button[type=submit]'); if (b) b.disabled = true;
      fetch('/academy/api.php?action=review_save', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ course: form.dataset.avcoReview, rating: parseInt(r.value, 10), body: form.elements.body.value }) })
        .then(x => x.json())
        .then(d => {
          if (msg) { msg.hidden = false; msg.textContent = d && d.ok ? 'Thanks — your review is saved.' : ((d && d.error) || 'Could not save.'); }
          if (d && d.ok) setTimeout(() => { location.hash = 'reviews'; location.reload(); }, 600);
        })
        .catch(() => { if (msg) { msg.hidden = false; msg.textContent = 'Network error — please try again.'; } })
        .finally(() => { if (b) b.disabled = false; });
    });
  });

  /* A link into a tab ("Read reviews") opens that tab. */
  run('goto', () => {
    $$('[data-avco-goto]').forEach(a => a.addEventListener('click', e => {
      const t = document.querySelector('[data-avco-tab="' + a.dataset.avcoGoto + '"]');
      if (t) { e.preventDefault(); t.click(); t.scrollIntoView({ block: 'start' }); t.focus(); }
    }));
  });
})();
