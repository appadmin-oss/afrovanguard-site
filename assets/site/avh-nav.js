/**
 * Site navigation behaviour (every page). The Home handoff's nav, member and
 * drawer inits (implementation/assets/site/avh.js) moved out of the Home script
 * so every page runs the same one. Vanilla ES2019, no dependencies.
 * Each feature is an isolated init; a missing element skips that feature.
 */
(() => {
  'use strict';
  const root = document.querySelector('[data-avh-chrome]');

  /* Sign-in links elsewhere on the page (data-login-link) return the reader to
     where they were, as they did with the old nav script; a sign-out control
     anywhere (data-logout, e.g. the portal sidebar) signs out the same way.
     Pages without the site nav (the member portal) load this file for these
     two alone, so they run before the nav check. */
  {
    const next = encodeURIComponent(location.pathname + location.search);
    document.querySelectorAll('[data-login-link]').forEach(a => a.setAttribute('href', '/login?next=' + next));
    document.addEventListener('click', e => {
      const out = e.target.closest('[data-logout]'); if (!out || (root && root.contains(out))) return;
      e.preventDefault();
      fetch('/academy/api.php?action=logout', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: '{}' })
        .catch(() => {}).then(() => location.reload());
    });
  }

  if (!root) return;

  const doc = document.documentElement;
  doc.classList.replace('no-js', 'js');
  const $ = (s, el = root) => el.querySelector(s);
  const $$ = (s, el = root) => Array.from(el.querySelectorAll(s));
  const deskMq = matchMedia('(min-width: 1180px)');
  const onMq = (mq, fn) => (mq.addEventListener ? mq.addEventListener('change', fn) : mq.addListener(fn));
  const layers = []; // Esc closes the top-most layer only: [{close}]
  const pushLayer = l => { if (!layers.includes(l)) layers.push(l); };
  const dropLayer = l => { const i = layers.indexOf(l); if (i > -1) layers.splice(i, 1); };
  const run = (name, fn) => { try { fn(); } catch (err) { console.error('[avh-nav] ' + name, err); } };

  run('nav', () => {
    const nav = $('[data-avh-nav]'); if (!nav) return;
    let ticking = false;
    const paint = () => { nav.classList.toggle('is-scrolled', scrollY > 8); ticking = false; };
    addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(paint); } }, { passive: true });
    paint();

    const btns = $$('[data-avh-menu]'), panels = $$('[data-avh-panel]');
    let open = null;
    const layer = { close: () => { const b = btns.find(x => x.dataset.avhMenu === open); set(null); b && b.focus(); } };
    const set = k => {
      open = k;
      btns.forEach(b => b.setAttribute('aria-expanded', String(b.dataset.avhMenu === k)));
      panels.forEach(p => { p.hidden = p.dataset.avhPanel !== k; });
      k ? pushLayer(layer) : dropLayer(layer);
    };
    // A mouse reaches a button before it clicks it, so hover has already
    // opened the panel by the time the click lands; a plain toggle then shut
    // it again under the pointer. A click within moments of a hover-open keeps
    // the panel; a later click (or a keyboard press) toggles as before.
    let hoverAt = 0;
    btns.forEach(b => {
      b.addEventListener('mouseenter', () => { if (deskMq.matches && open !== b.dataset.avhMenu) { set(b.dataset.avhMenu); hoverAt = Date.now(); } });
      b.addEventListener('click', () => {
        const k = b.dataset.avhMenu;
        if (open === k && Date.now() - hoverAt < 600) return;
        set(open === k ? null : k);
      });
    });
    $$('[data-avh-close]').forEach(a => a.addEventListener('mouseenter', () => open && set(null)));
    nav.addEventListener('mouseleave', () => { if (open && deskMq.matches) set(null); });
    nav.addEventListener('focusout', e => { if (open && !nav.contains(e.relatedTarget)) set(null); });
    onMq(deskMq, () => set(null));
  });

  run('member', () => {
    // GET /academy/api.php?action=me → {ok, user:{name,email}}; POST ?action=logout.
    const next = encodeURIComponent(location.pathname + location.search);
    $$('[data-avh-login]').forEach(a => a.setAttribute('href', '/login?next=' + next));
    const btn = $('[data-avh-acct-btn]'), menu = $('[data-avh-acct-menu]'), wrap = $('[data-avh-acct]');
    if (btn && menu && wrap) {
      const layer = { close: () => { set(false); btn.focus(); } };
      const set = on => { menu.hidden = !on; btn.setAttribute('aria-expanded', String(on)); on ? pushLayer(layer) : dropLayer(layer); };
      btn.addEventListener('click', () => set(menu.hidden));
      document.addEventListener('click', e => { if (!menu.hidden && !wrap.contains(e.target)) set(false); });
      wrap.addEventListener('focusout', e => { if (!menu.hidden && e.relatedTarget && !wrap.contains(e.relatedTarget)) set(false); });
      onMq(deskMq, () => set(false));
    }
    document.addEventListener('click', e => {
      const out = e.target.closest('[data-avh-logout]'); if (!out || !root.contains(out)) return;
      e.preventDefault(); out.disabled = true;
      fetch('/academy/api.php?action=logout', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: '{}' })
        .catch(() => {}).then(() => location.reload());
    });
    const apply = u => {
      const name = String(u.name || '').trim() || 'Member', first = name.split(/\s+/)[0], initial = name.charAt(0).toUpperCase();
      $$('[data-avh-me-initial]').forEach(n => { n.textContent = initial; });
      $$('[data-avh-me-name]').forEach(n => { n.textContent = name; });
      $$('[data-avh-me-first]').forEach(n => { n.textContent = first; });
      $$('[data-avh-me-email]').forEach(n => { n.textContent = u.email || ''; n.hidden = !u.email; });
      if (btn) btn.setAttribute('aria-label', name + ', your account');
      root.classList.add('is-member');
    };
    fetch('/academy/api.php?action=me', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => (r.ok ? r.json() : null))
      .then(d => { if (d && d.ok && d.user) apply(d.user); })
      .catch(() => {});
  });

  run('drawer', () => {
    const drawer = $('[data-avh-drawer]'), backdrop = $('[data-avh-backdrop]'), burger = $('[data-avh-burger]');
    if (!drawer || !backdrop || !burger) return;
    const focusables = () => $$('a[href],button:not([disabled])', drawer).filter(el => el.offsetParent !== null);
    const layer = { close: () => close(true) };
    const setBurger = on => {
      burger.classList.toggle('is-open', on);
      burger.setAttribute('aria-expanded', String(on));
      burger.setAttribute('aria-label', on ? 'Close menu' : 'Open menu');
    };
    const open = () => {
      drawer.hidden = backdrop.hidden = false; setBurger(true);
      doc.style.overflow = 'hidden'; doc.classList.add('avh-drawer-open');
      pushLayer(layer);
      (focusables()[0] || drawer).focus();
    };
    const close = (returnFocus = true) => {
      if (drawer.hidden) return;
      drawer.hidden = backdrop.hidden = true; setBurger(false);
      doc.style.overflow = ''; doc.classList.remove('avh-drawer-open');
      dropLayer(layer);
      if (returnFocus) burger.focus();
    };
    burger.addEventListener('click', () => (drawer.hidden ? open() : close()));
    $('[data-avh-drawer-close]', drawer)?.addEventListener('click', () => close());
    backdrop.addEventListener('click', () => close());
    drawer.addEventListener('keydown', e => {
      if (e.key !== 'Tab') return;
      const f = focusables(); if (!f.length) return;
      const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    const accs = $$('[data-avh-acc]', drawer);
    const panelOf = b => document.getElementById(b.getAttribute('aria-controls'));
    accs.forEach(b => b.addEventListener('click', () => {
      const willOpen = b.getAttribute('aria-expanded') !== 'true';
      accs.forEach(o => { o.setAttribute('aria-expanded', 'false'); const p = panelOf(o); if (p) p.hidden = true; });
      if (willOpen) { b.setAttribute('aria-expanded', 'true'); const p = panelOf(b); if (p) p.hidden = false; }
    }));
    onMq(deskMq, () => close(false));
  });


  addEventListener('keydown', e => {
    if (e.key !== 'Escape' || !layers.length) return;
    layers[layers.length - 1].close();
  });
})();
