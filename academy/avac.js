/**
 * Academy (catalogue, course, lesson gate) shared behaviour. Vanilla, no deps.
 * One guarded init per feature: a missing element skips that feature.
 * Every write goes to the existing /academy/api.php actions (pay_init,
 * pass_redeem, enroll); sign-in is the standalone /login page with ?next=.
 */
(() => {
  'use strict';
  const API = '/academy/api.php';
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const run = (name, fn) => { try { fn(); } catch (err) { console.error('[avac] ' + name, err); } };

  const api = (action, body) => fetch(API + '?action=' + encodeURIComponent(action), {
    method: body ? 'POST' : 'GET',
    headers: body ? { 'Content-Type': 'application/json' } : {},
    body: body ? JSON.stringify(body) : undefined,
    credentials: 'same-origin'
  }).then(r => r.json());

  let toastT = 0;
  const toast = msg => {
    const t = $('[data-avac-toast]');
    if (!t) return;
    t.textContent = msg; t.hidden = false;
    clearTimeout(toastT); toastT = setTimeout(() => { t.hidden = true; }, 4000);
  };
  const loginUrl = mode => '/login?next=' + encodeURIComponent(location.pathname + location.search) + (mode === 'register' ? '&mode=register' : '');
  const goLogin = mode => { location.href = loginUrl(mode); };
  window.avAcademy = { api, toast, goLogin };

  /* Messages land in the card that asked; a page without one gets a toast. */
  const noteIn = (from, text, ok) => {
    const card = from.closest('[data-avac-paycard]');
    const msg = card && $('[data-avac-msg]', card);
    if (!msg) { toast(text); return; }
    msg.hidden = false; msg.textContent = text;
    msg.classList.toggle('is-ok', !!ok); msg.classList.toggle('is-err', !ok);
  };

  run('auth', () => {
    // Keep ?next= pointing at this exact page (the server-rendered href is the no-JS fallback).
    $$('[data-avac-auth]').forEach(a => { a.href = loginUrl(a.dataset.avacAuth); });
  });

  run('pay', () => {
    document.addEventListener('click', e => {
      const t = e.target.closest('[data-avac-pay]');
      if (!t) return;
      e.preventDefault();
      const label = t.textContent;
      t.disabled = true; t.textContent = 'Starting secure checkout…';
      const reset = () => { t.disabled = false; t.textContent = label; };
      api('pay_init', { kind: t.dataset.avacPay, course: t.dataset.course || '' })
        .then(d => {
          if (d && d.ok && d.authorization_url) { location.href = d.authorization_url; return; }
          if (d && d.ok && d.already) { noteIn(t, d.message || 'You already have access.', true); setTimeout(() => location.reload(), 900); return; }
          if (d && /sign in/i.test(d.error || '')) { goLogin('login'); return; }
          noteIn(t, (d && d.error) || 'Could not start the payment. Please try again.', false); reset();
        })
        .catch(() => { noteIn(t, 'Network error — please try again.', false); reset(); });
    });
  });

  run('pass', () => {
    $$('form[data-avac-pass]').forEach(form => form.addEventListener('submit', e => {
      e.preventDefault();
      const btn = $('button[type=submit]', form), label = btn ? btn.textContent : '';
      const code = ($('[name="code"]', form) || {}).value || '';
      if (btn) { btn.disabled = true; btn.textContent = 'Checking…'; }
      api('pass_redeem', { slug: form.dataset.avacPass, code })
        .then(d => {
          if (d && d.ok) { noteIn(form, d.message || 'Unlocked.', true); setTimeout(() => location.reload(), 700); return; }
          if (d && /sign in/i.test(d.error || '')) { goLogin('login'); return; }
          noteIn(form, (d && d.error) || 'That pass code is not valid.', false);
          if (btn) { btn.disabled = false; btn.textContent = label; }
        })
        .catch(() => { noteIn(form, 'Network error — please try again.', false); if (btn) { btn.disabled = false; btn.textContent = label; } });
    }));
  });

  run('lead', () => {
    $$('form[data-avac-lead]').forEach(form => form.addEventListener('submit', e => {
      e.preventDefault();
      const btn = $('button[type=submit]', form), label = btn ? btn.textContent : '';
      const body = { slug: form.dataset.avacLead };
      ['name', 'email', 'phone', 'note'].forEach(k => { const el = $('[name="' + k + '"]', form); if (el) body[k] = el.value; });
      if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }
      api('enroll', body)
        .then(d => {
          if (d && d.ok) { noteIn(form, d.message || 'Application received — we will be in touch shortly.', true); form.reset(); }
          else noteIn(form, (d && d.error) || 'Please provide a valid name and email.', false);
        })
        .catch(() => noteIn(form, 'Network error — please try again.', false))
        .finally(() => { if (btn) { btn.disabled = false; btn.textContent = label; } });
    }));
  });

  /* Catalogue: the filter is a real GET form (works without script, and a
     filtered view is a link you can share). With script it filters live and
     keeps the address bar in step. */
  run('catalogue', () => {
    const grid = $('[data-avac-grid]');
    if (!grid) return;
    const form = $('[data-avac-filter]');
    const cards = $$('.avac-card', grid);
    const tracks = $$('[data-avac-track]');
    const q = $('[data-avac-q]'), sort = $('[data-avac-sort]'), count = $('[data-avac-count]');
    const level = $('[data-avac-level]'), price = $('[data-avac-price]'), trackIn = $('[data-avac-track-in]');
    const none = $('[data-avac-none]'), clear = $('[data-avac-clear]');
    const total = cards.length;
    let cat = (trackIn && trackIn.value) || 'all';
    const num = (c, k) => parseFloat(c.dataset[k] || '0') || 0;
    const order = () => {
      const key = sort ? sort.value : 'recommended';
      cards.slice().sort((a, b) => {
        if (key === 'title') return (a.dataset.title || '').localeCompare(b.dataset.title || '');
        if (key === 'popular') return num(b, 'enrolled') - num(a, 'enrolled');
        if (key === 'rating') return num(b, 'rating') - num(a, 'rating') || num(b, 'ratings') - num(a, 'ratings');
        if (key === 'newest') return (b.dataset.created || '').localeCompare(a.dataset.created || '');
        if (key === 'shortest') return (num(a, 'minutes') || 1e9) - (num(b, 'minutes') || 1e9);
        return num(b, 'featured') - num(a, 'featured') || cards.indexOf(a) - cards.indexOf(b);
      }).forEach(c => grid.appendChild(c));
    };
    /* The address follows the filters only once the visitor has used them.
       Written on load, '#catalogue' made the browser scroll there as the page
       finished loading: everyone — a screen-reader user, someone zoomed in —
       arrived 1,300px down the page, past the menu and the skip link. */
    let touched = false;
    const sync = () => {
      if (!touched || !form || !history.replaceState) return;
      /* Only the filter's own keys change; anything else in the address
         (a campaign tag, a payment flag) is left exactly where it was. */
      const p = new URLSearchParams(location.search);
      const put = (k, v) => { if (v) p.set(k, v); else p.delete(k); };
      put('q', q ? q.value.trim() : '');
      put('track', cat !== 'all' ? cat : '');
      put('level', level ? level.value : '');
      put('price', price ? price.value : '');
      put('sort', sort && sort.value !== 'recommended' ? sort.value : '');
      const s = p.toString();
      history.replaceState(null, '', location.pathname + (s ? '?' + s : '') + '#catalogue');
    };
    const apply = () => {
      const words = (q && q.value || '').trim().toLowerCase().split(/\s+/).filter(Boolean);
      const lv = level ? level.value : '', pr = price ? price.value : '';
      let shown = 0;
      cards.forEach(c => {
        const hay = c.dataset.search || '';
        const on = (cat === 'all' || c.dataset.cat === cat) && (!lv || c.dataset.level === lv)
          && (!pr || c.dataset.price === pr) && words.every(w => hay.includes(w));
        c.hidden = !on; if (on) shown++;
      });
      const all = shown === total && cat === 'all' && !words.length && !lv && !pr;
      if (none) none.hidden = shown !== 0;
      if (count) count.textContent = all ? 'Showing all ' + total : 'Showing ' + shown + ' of ' + total;
      sync();
    };
    const setCat = k => { touched = true; cat = k; if (trackIn) trackIn.value = k === 'all' ? '' : k; tracks.forEach(b => b.setAttribute('aria-pressed', String(b.dataset.avacTrack === k))); apply(); };
    tracks.forEach(b => b.addEventListener('click', () => setCat(b.dataset.avacTrack || 'all')));
    let t = 0;
    const used = fn => (...a) => { touched = true; return fn(...a); };
    if (q) { q.addEventListener('input', used(() => { clearTimeout(t); t = setTimeout(apply, 150); })); q.addEventListener('search', used(apply)); }
    [level, price].forEach(el => { if (el) el.addEventListener('change', used(apply)); });
    if (sort) sort.addEventListener('change', used(() => { order(); apply(); }));
    if (form) form.addEventListener('submit', used(e => { e.preventDefault(); apply(); }));
    if (clear) clear.addEventListener('click', () => { if (q) q.value = ''; if (level) level.value = ''; if (price) price.value = ''; setCat('all'); if (q) q.focus(); });
    order(); apply();
  });
})();
