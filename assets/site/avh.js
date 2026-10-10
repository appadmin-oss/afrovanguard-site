/**
 * Home (/index.html) behaviour. Vanilla ES2019, no dependencies.
 * Each feature is an isolated init; a missing element skips that feature instead of breaking the page.
 */
(() => {
  'use strict';
  const root = document.body;
  if (!root || !root.classList.contains('avh')) return;

  const $ = (s, el = root) => el.querySelector(s);
  const $$ = (s, el = root) => Array.from(el.querySelectorAll(s));
  const reduceMq = matchMedia('(prefers-reduced-motion: reduce)');
  const deskMq = matchMedia('(min-width: 1180px)');
  const onMq = (mq, fn) => (mq.addEventListener ? mq.addEventListener('change', fn) : mq.addListener(fn));
  const layers = []; // Esc closes the top-most layer only: [{close}]
  const pushLayer = l => { if (!layers.includes(l)) layers.push(l); };
  const dropLayer = l => { const i = layers.indexOf(l); if (i > -1) layers.splice(i, 1); };
  const run = (name, fn) => { try { fn(); } catch (err) { console.error('[avh] ' + name, err); } };

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
    btns.forEach(b => {
      b.addEventListener('mouseenter', () => { if (deskMq.matches) set(b.dataset.avhMenu); });
      b.addEventListener('click', () => set(open === b.dataset.avhMenu ? null : b.dataset.avhMenu));
    });
    $$('[data-avh-close]').forEach(a => a.addEventListener('mouseenter', () => open && set(null)));
    nav.addEventListener('mouseleave', () => { if (open && deskMq.matches) set(null); });
    nav.addEventListener('focusout', e => { if (open && !nav.contains(e.relatedTarget)) set(null); });
    onMq(deskMq, () => set(null));
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
      document.documentElement.style.overflow = 'hidden';
      pushLayer(layer);
      (focusables()[0] || drawer).focus();
    };
    const close = (returnFocus = true) => {
      if (drawer.hidden) return;
      drawer.hidden = backdrop.hidden = true; setBurger(false);
      document.documentElement.style.overflow = '';
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

  run('hero', () => {
    const hero = $('[data-avh-hero]'); if (!hero) return;
    const slides = $$('.avh-hero-img', hero);
    const link = $('[data-avh-hero-link]', hero), eb = $('[data-avh-hero-eyebrow]', hero);
    const title = $('[data-avh-hero-title]', hero), sub = $('[data-avh-hero-sub]', hero);
    const nextBtn = $('[data-avh-next]', hero), playBtn = $('[data-avh-play]', hero), vid = $('video', hero);
    const videoMode = hero.dataset.mode === 'video' && !!hero.dataset.video && !!vid;
    let i = 0, playing = !reduceMq.matches, hold = false, timer = 0;
    const dotsWrap = $('[data-avh-dots]', hero), dots = $$('[data-avh-dot]', hero), copy = link;
    const syncDots = () => {
      dots.forEach((d, k) => { d.toggleAttribute('aria-current', k === i); if (k === i) d.setAttribute('aria-current', 'true'); d.classList.toggle('is-done', k < i); });
      const cur = dots[i]?.querySelector('span'); if (cur) { cur.style.animation = 'none'; void cur.offsetWidth; cur.style.animation = ''; }
      dotsWrap?.classList.toggle('is-paused', !playing || hold);
    };

    const render = d => { link.href = d.href; eb.textContent = d.eyebrow; title.textContent = d.title; sub.textContent = d.sub; };
    const show = (n, manual) => {
      if (!slides.length) return;
      i = (n + slides.length) % slides.length;
      slides.forEach((s, k) => s.classList.toggle('is-on', k === i));
      link.setAttribute('aria-live', manual || !playing ? 'polite' : 'off'); // no announcements while auto-rotating
      render(slides[i].dataset);
      syncDots();
      if (!reduceMq.matches) { copy.classList.remove('avh-hero-copy-anim'); void copy.offsetWidth; copy.classList.add('avh-hero-copy-anim'); }
      const nx = slides[(i + 1) % slides.length]; if (nx && nx.loading === 'lazy') nx.loading = 'eager';
    };
    const syncPlayBtn = () => {
      if (!playBtn) return;
      playBtn.setAttribute('aria-label', playing ? 'Pause slideshow' : 'Play slideshow');
      playBtn.textContent = playing ? '❚❚' : '▶';
    };
    const tick = () => { if (playing && !hold && !document.hidden) show(i + 1); };
    hero.addEventListener('mouseleave', () => { if (!videoMode) { schedule(); syncDots(); } });
    const schedule = () => { clearInterval(timer); if (!videoMode) timer = setInterval(tick, 6000); };

    if (videoMode) {
      slides.forEach(s => s.remove());
      vid.poster = hero.dataset.poster || ''; vid.src = hero.dataset.video; vid.hidden = false;
      if (playing) vid.play().catch(() => { playing = false; syncPlayBtn(); });
      render({ href: '/about.html', eyebrow: hero.dataset.videoEyebrow || '', title: hero.dataset.videoTitle || '', sub: hero.dataset.videoSub || '' });
      if (nextBtn) nextBtn.hidden = true;
      if (dotsWrap) dotsWrap.hidden = true;
    } else {
      nextBtn?.addEventListener('click', () => { show(i + 1, true); schedule(); });
      dots.forEach((d, k) => d.addEventListener('click', () => { show(k, true); schedule(); }));
      syncDots();
      // WCAG 2.2.2: pause while the user hovers or focuses inside the hero
      const setHold = v => { hold = v; dotsWrap?.classList.toggle('is-paused', !playing || hold); };
      hero.addEventListener('mouseenter', () => setHold(true));
      hero.addEventListener('mouseleave', () => setHold(false));
      hero.addEventListener('focusin', () => setHold(true));
      hero.addEventListener('focusout', e => { if (!hero.contains(e.relatedTarget)) setHold(false); });
      schedule();
    }
    playBtn?.addEventListener('click', () => {
      playing = !playing; syncPlayBtn(); dotsWrap?.classList.toggle('is-paused', !playing || hold);
      link.setAttribute('aria-live', playing ? 'off' : 'polite');
      if (videoMode) playing ? vid.play().catch(() => {}) : vid.pause();
    });
    document.addEventListener('visibilitychange', () => { if (videoMode && playing) document.hidden ? vid.pause() : vid.play().catch(() => {}); });
    onMq(reduceMq, e => { if (e.matches && playing) playBtn?.click(); });
    syncPlayBtn();
  });

  run('story', () => {
    const band = $('[data-avh-story-src]'), story = $('[data-avh-story]'), btn = $('[data-avh-story-play]');
    if (!band || !story || !btn) return;
    btn.addEventListener('click', () => {
      const src = (band.dataset.avhStorySrc || '').trim(); if (!src) return;
      const yt = src.match(/(?:youtu\.be\/|v=|embed\/|shorts\/)([\w-]{11})/), vm = src.match(/vimeo\.com\/(\d+)/);
      const embed = yt ? 'https://www.youtube-nocookie.com/embed/' + yt[1] + '?autoplay=1&rel=0&modestbranding=1'
        : vm ? 'https://player.vimeo.com/video/' + vm[1] + '?autoplay=1' : '';
      let el;
      if (embed) {
        el = document.createElement('iframe');
        Object.assign(el, { src: embed, title: 'Our story — Afrovanguard', allowFullscreen: true });
        el.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
        el.referrerPolicy = 'strict-origin-when-cross-origin';
      } else {
        if (!/^(\/|https:\/\/)/.test(src)) return; // same-origin path or https only
        el = document.createElement('video');
        Object.assign(el, { src, poster: '/Images/summer1.png', controls: true, autoplay: true, playsInline: true });
      }
      el.tabIndex = -1;
      story.replaceChildren(el);
      el.focus();
    }, { once: true });
  });

  run('quote', () => {
    const box = $('[data-avh-quote]'); if (!box) return;
    // Rotation list — the only one: /quote-card.php reads this array (lib/QuoteOfWeek.php). Keep the [text, who, where] shape.
    const QUOTES = [
      ['The trouble with Nigeria is simply and squarely a failure of leadership.', 'Chinua Achebe', 'The Trouble with Nigeria, 1983'],
      ['It always seems impossible until it’s done.', 'Nelson Mandela', 'Statesman, South Africa'],
      ['It’s the little things citizens do. That’s what will make the difference.', 'Wangari Maathai', 'Nobel Peace laureate, Kenya'],
      ['We face neither East nor West; we face forward.', 'Kwame Nkrumah', 'First president of Ghana'],
      ['Come as you are. But don’t stay as you are.', 'Afrovanguard', 'From our member pledge']
    ];
    const SITE = 'https://afrovanguard.org.ng';

    const now = new Date();
    const isoWeek = d => {
      const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
      t.setUTCDate(t.getUTCDate() + 4 - (t.getUTCDay() || 7));
      return Math.ceil(((t - Date.UTC(t.getUTCFullYear(), 0, 1)) / 864e5 + 1) / 7);
    };
    const wk = isoWeek(now);
    const mon = new Date(now); mon.setDate(now.getDate() - ((now.getDay() + 6) % 7));
    const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
    const fmt = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' });
    const [text, who, where] = QUOTES[wk % QUOTES.length];
    const week = 'Week ' + wk + ' · ' + fmt.format(mon) + ' – ' + fmt.format(sun);
    const url = SITE + '/quote/week-' + wk, line = '“' + text + '” — ' + who;
    const E = encodeURIComponent;
    const Q = k => $('[data-avh-q="' + k + '"]', box);
    const setText = (k, v) => { const el = Q(k); if (el) el.textContent = v; };
    const setHref = (k, v) => { const el = Q(k); if (el) el.href = v; };

    setText('text', text); setText('who', who); setText('where', where); setText('week', week);
    setText('ini', who.split(/\s+/).map(w => w[0]).join('').slice(0, 2));
    setHref('wa', 'https://wa.me/?text=' + E(line + ' ' + url));
    setHref('x', 'https://x.com/intent/post?text=' + E(line) + '&url=' + E(url));
    setHref('li', 'https://www.linkedin.com/sharing/share-offsite/?url=' + E(url));

    const share = $('[data-avh-share]', box), btn = $('[data-avh-share-btn]', box), menu = $('#avh-share-menu', box);
    if (!share || !btn || !menu) return;
    const items = () => $$('[role="menuitem"]', menu);
    const layer = { close: () => { set(false); btn.focus(); } };
    const set = open => {
      menu.hidden = !open; btn.setAttribute('aria-expanded', String(open));
      open ? pushLayer(layer) : dropLayer(layer);
    };
    btn.addEventListener('click', () => set(menu.hidden));
    btn.addEventListener('keydown', e => { if (e.key === 'ArrowDown') { e.preventDefault(); set(true); items()[0]?.focus(); } });
    menu.addEventListener('keydown', e => {
      const list = items(), at = list.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); list[(at + 1) % list.length].focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); list[(at - 1 + list.length) % list.length].focus(); }
      else if (e.key === 'Tab') set(false);
    });
    share.addEventListener('mouseleave', () => { if (!menu.hidden && !share.contains(document.activeElement)) set(false); });
    document.addEventListener('click', e => { if (!menu.hidden && !share.contains(e.target)) set(false); });

    const copyBtn = Q('copy');
    const copy = async str => {
      if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(str);
      const ta = Object.assign(document.createElement('textarea'), { value: str, readOnly: true });
      ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } finally { ta.remove(); }
    };
    let reset = 0;
    copyBtn?.addEventListener('click', () => {
      copy(url).catch(() => {}).finally(() => {
        copyBtn.textContent = 'Link copied';
        clearTimeout(reset); reset = setTimeout(() => { copyBtn.textContent = 'Copy link'; }, 2000);
      });
    });
  });

  run('subscribe', () => {
    // Endpoint + payload match repo assets/site/chrome.js (.diary-subscribe). Messages: server first, repo fallbacks second.
    const form = $('[data-avh-sub-form]'), ok = $('[data-avh-sub-ok]'), err = $('[data-avh-sub-err]');
    if (!form || !ok || !window.fetch) return;
    const input = $('input[type="email"]', form), btn = $('button[type="submit"]', form), hp = $('[name="hp"]', form);
    const fail = msg => { if (err) { err.textContent = msg; err.hidden = false; } input?.setAttribute('aria-invalid', 'true'); input?.focus(); };
    let busy = false;
    form.addEventListener('submit', e => {
      e.preventDefault();
      if (busy) return;
      const email = (input?.value || '').trim();
      if (err) err.hidden = true; input?.removeAttribute('aria-invalid');
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return fail('Please enter a valid email address.');
      busy = true; if (btn) btn.disabled = true;
      const ctl = window.AbortController ? new AbortController() : null, to = ctl && setTimeout(() => ctl.abort(), 10000);
      fetch(form.getAttribute('action'), {
        method: 'POST', credentials: 'same-origin', signal: ctl?.signal,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email, hp: hp?.value || '' })
      })
        .then(r => r.json())
        .then(d => {
          if (d && d.ok) { form.hidden = true; ok.hidden = false; ok.focus(); }
          else fail((d && d.error) || 'Could not subscribe.');
        })
        .catch(() => fail('Network error — please try again.'))
        .finally(() => { clearTimeout(to); busy = false; if (btn) btn.disabled = false; });
    });
  });

  run('top', () => {
    $('[data-avh-top]')?.addEventListener('click', e => {
      e.preventDefault();
      scrollTo({ top: 0, behavior: reduceMq.matches ? 'auto' : 'smooth' });
      $('.avh-brand')?.focus({ preventScroll: true });
    });
  });


  run('topo', () => {
    // Contour texture: families of wavy rings meeting at soft boundaries. Behaviour ported from the design's texture; static, so it also shows under reduced motion.
    const NS = 'http://www.w3.org/2000/svg';
    const build = (W, H, seed) => {
      let s = (seed || 7) * 9973;
      const R = () => { s = (s * 1664525 + 1013904223) % 4294967296; return s / 4294967296; };
      const C = [[.08, .1, 1], [.96, .5, 1.2], [.3, 1.1, .95], [.75, -.2, .85], [-.1, .7, .9], [.55, .45, .7]]
        .map(p => ({ x: W * p[0], y: H * p[1], k: p[2] * Math.max(1, W / 1400), a: R() * 6.28, b: R() * 6.28, c: R() * 6.28 }));
      const mine = (px, py, me) => { const dm = Math.hypot(px - me.x, py - me.y) / me.k; return C.every(o => o === me || Math.hypot(px - o.x, py - o.y) / o.k >= dm); };
      const f = Math.round, maxR = Math.hypot(W, H) * 1.1; let soft = '', sharpD = '';
      C.forEach(cn => {
        for (let r = 14, ri = 0; r < maxR; r += 22, ri++) {
          const sharp = ri % 5 === 2, n = sharp ? Math.max(48, Math.round(r / 5)) : Math.max(28, Math.round(r / 12));
          let seg = [];
          const flush = () => {
            if (seg.length > 2) {
              if (sharp) sharpD += 'M' + seg.map(p => f(p[0]) + ' ' + f(p[1])).join('L');
              else {
                soft += 'M' + f(seg[0][0]) + ' ' + f(seg[0][1]);
                for (let i = 1; i < seg.length - 1; i++) soft += 'Q' + f(seg[i][0]) + ' ' + f(seg[i][1]) + ' ' + f((seg[i][0] + seg[i + 1][0]) / 2) + ' ' + f((seg[i][1] + seg[i + 1][1]) / 2);
                soft += 'L' + f(seg[seg.length - 1][0]) + ' ' + f(seg[seg.length - 1][1]);
              }
            }
            seg = [];
          };
          for (let i = 0; i <= n; i++) {
            const t = i / n * 6.2832, k = 1 + .085 * Math.sin(3 * t + cn.a) + .05 * Math.sin(5 * t + cn.b) + .022 * Math.sin(9 * t + cn.c + r * .012) + (sharp ? .016 * (i % 2 ? 1 : -1) : 0);
            const x = cn.x + Math.cos(t) * r * k, y = cn.y + Math.sin(t) * r * k * .9;
            if (x > -30 && x < W + 30 && y > -30 && y < H + 30 && mine(x, y, cn)) seg.push([x, y]); else flush();
          }
          flush();
        }
      });
      const svg = document.createElementNS(NS, 'svg');
      svg.setAttribute('width', W); svg.setAttribute('height', H); svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H); svg.setAttribute('focusable', 'false');
      [[soft, '1.6', 'round'], [sharpD, '1.1', 'miter']].forEach(([d, w, j]) => {
        const p = document.createElementNS(NS, 'path');
        p.setAttribute('d', d); p.setAttribute('fill', 'none'); p.setAttribute('stroke', 'currentColor');
        p.setAttribute('stroke-width', w); p.setAttribute('stroke-linecap', 'round'); p.setAttribute('stroke-linejoin', j);
        svg.appendChild(p);
      });
      return svg;
    };
    $$('[data-avh-topo]').forEach(layer => {
      const host = layer.parentElement; if (!host) return;
      let last = '', t = 0;
      const draw = () => {
        const W = Math.round(host.clientWidth), H = Math.round(host.clientHeight), key = W + 'x' + H;
        if (!W || !H || key === last) return; last = key;
        layer.replaceChildren(build(W, H, +layer.dataset.seed || (W % 13) + 3));
      };
      const idle = window.requestIdleCallback || (fn => setTimeout(fn, 1));
      idle(draw);
      if ('ResizeObserver' in window) new ResizeObserver(() => { clearTimeout(t); t = setTimeout(draw, 150); }).observe(host);
      addEventListener('beforeprint', draw);
    });
  });

  /* ===== Effects (all off under reduced motion) ===== */
  run('reveal', () => {
    const els = $$('[data-avh-reveal]'); if (!els.length) return;
    if (reduceMq.matches || !('IntersectionObserver' in window)) { els.forEach(e => e.classList.add('is-in')); return; }
    const io = new IntersectionObserver(es => es.forEach(en => {
      if (!en.isIntersecting) return;
      const el = en.target, sibs = Array.from(el.parentElement?.children || []), k = Math.max(0, sibs.indexOf(el));
      el.style.transitionDelay = Math.min(k, 5) * 70 + 'ms';
      el.classList.add('is-in'); io.unobserve(el);
    }), { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });
    els.forEach(e => io.observe(e));
  });

  run('countup', () => {
    const els = $$('[data-avh-count]'); if (!els.length || reduceMq.matches || !('IntersectionObserver' in window)) return;
    const parse = s => { const m = s.match(/^([^\d]*)([\d,]+)(.*)$/); return m ? { pre: m[1], n: +m[2].replace(/,/g, ''), post: m[3], comma: m[2].includes(',') } : null; };
    const fmt = (v, comma) => comma ? v.toLocaleString('en-GB') : String(v);
    const io = new IntersectionObserver(es => es.forEach(en => {
      if (!en.isIntersecting) return; io.unobserve(en.target);
      const el = en.target, p = parse(el.dataset.avhCount); if (!p) return;
      const t0 = performance.now(), dur = 1400;
      el.setAttribute('aria-label', el.dataset.avhCount);
      const step = t => { const k = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - k, 3);
        el.textContent = p.pre + fmt(Math.round(p.n * e), p.comma) + p.post; if (k < 1) requestAnimationFrame(step); };
      el.textContent = p.pre + '0' + p.post; requestAnimationFrame(step);
    }), { threshold: 0.6 });
    els.forEach(e => io.observe(e));
  });

  run('parallax', () => {
    const layer = $('[data-avh-parallax]'); if (!layer || reduceMq.matches) return;
    let raf = 0;
    const paint = () => { raf = 0; const y = Math.min(scrollY, innerHeight); layer.style.setProperty('--avh-py', (y * -0.18).toFixed(1) + 'px'); };
    addEventListener('scroll', () => { if (!raf) raf = requestAnimationFrame(paint); }, { passive: true });
  });

  run('spotlight', () => {
    if (reduceMq.matches || !matchMedia('(hover: hover)').matches) return;
    $$('[data-avh-spot]').forEach(layer => {
      const host = layer.parentElement; let raf = 0, x = 0, y = 0;
      host.addEventListener('pointermove', e => {
        const r = host.getBoundingClientRect(); x = e.clientX - r.left; y = e.clientY - r.top;
        if (!raf) raf = requestAnimationFrame(() => { raf = 0; layer.style.setProperty('--mx', x + 'px'); layer.style.setProperty('--my', y + 'px'); });
      });
      host.addEventListener('pointerleave', () => { layer.style.removeProperty('--mx'); layer.style.removeProperty('--my'); });
    });
  });

  /* ===== Restored data sections ===== */
  run('appeals', () => {
    // Feed + fields as repo assets/site/appeals-band.js. Text only, never markup.
    const band = $('[data-avh-appeals]'); if (!band || !window.fetch) return;
    const list = $('[data-avh-appeals-list]', band), needs = $('[data-avh-needs-list]', band);
    const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null && text !== '') n.textContent = String(text); return n; };
    const safe = u => (typeof u === 'string' && /^(\/(?!\/)|https:\/\/)/.test(u)) ? u : '/give/';
    const appeal = a => {
      const card = el('a', 'avh-card'); card.href = safe(a.url);
      if (a.image) { const img = el('img'); Object.assign(img, { src: safe(a.image), alt: '', loading: 'lazy', decoding: 'async', width: 580, height: 387 }); card.appendChild(img); }
      const body = el('div', 'avh-card-body');
      const dl = a.days_left;
      body.appendChild(el('div', 'avh-kicker', a.urgent ? 'Urgent' : a.match_live ? 'Gifts doubled'
        : (dl != null && dl >= 0 && dl <= 7) ? dl + (dl === 1 ? ' day left' : ' days left') : (a.location || a.kind || 'Appeal')));
      body.appendChild(el('h3', null, a.title));
      if (a.tagline) body.appendChild(el('p', null, a.tagline));
      if (a.percent != null) {
        const m = el('div', 'avh-meter' + (a.percent >= 100 ? ' is-met' : ''));
        m.setAttribute('role', 'progressbar'); m.setAttribute('aria-valuemin', '0'); m.setAttribute('aria-valuemax', '100');
        m.setAttribute('aria-valuenow', String(a.percent)); m.setAttribute('aria-label', a.percent + '% raised');
        const f = el('span'); f.style.width = Math.max(0, Math.min(100, +a.percent || 0)) + '%'; m.appendChild(f); body.appendChild(m);
      }
      const meta = el('div', 'avh-raised'); meta.appendChild(el('strong', null, a.raised_label));
      meta.appendChild(el('span', null, a.goal_label ? 'of ' + a.goal_label : (a.donors > 0 ? a.donors + (a.donors === 1 ? ' donor' : ' donors') : 'raised so far')));
      body.appendChild(meta); card.appendChild(body); return card;
    };
    const need = n => { const a = el('a', 'avh-need'); a.href = safe(n.url);
      a.append(el('span', 'avh-need-when', n.when), el('span', 'avh-need-fig av-num', n.figure), el('span', 'avh-need-title', n.title));
      if (n.unit) a.appendChild(el('span', 'avh-need-unit', n.unit)); a.appendChild(el('span', 'avh-need-for', n.for)); return a; };
    fetch('/give/feed.json?limit=3', { headers: { Accept: 'application/json' } })
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      .then(d => {
        const ap = (d && d.appeals) || [], nd = (d && d.needs) || [];
        if (nd.length && needs) { nd.slice(0, 4).forEach(n => needs.appendChild(need(n))); needs.hidden = false; }
        if (ap.length && list) { ap.forEach(a => list.appendChild(appeal(a))); list.hidden = false; }
        if ((ap.length && list) || (nd.length && needs)) band.hidden = false; // nothing live → section stays out of the page
      })
      .catch(() => {});
  });

  run('votm', () => {
    // GET /api.php?action=votm → {status, votm|null}. Shape: lib/people.php av_votm().
    const box = $('[data-avh-votm]'); if (!box || !window.fetch) return;
    const q = k => $('[data-avh-votm-' + k + ']', box);
    const set = s => { box.hidden = s !== 'ready'; }; // no honouree, error or offline → the section is not shown at all
    const slug = n => String(n || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    const ini = n => String(n || '').trim().split(/\s+/).slice(0, 2).map(w => w[0] || '').join('').toUpperCase();
    fetch('/api.php?action=votm', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      .then(d => {
        const v = d && d.status === 'ok' && d.votm; if (!v) return set('empty');
        const month = v.month ? new Intl.DateTimeFormat('en-GB', { month: 'long' }).format(new Date(2000, v.month - 1, 1)) : '';
        q('month').textContent = [month, v.year].filter(Boolean).join(' ');
        q('name').textContent = v.name || ''; q('role').textContent = v.role || '';
        if (v.reason) { q('reason').textContent = v.reason; q('reason').hidden = false; }
        if (v.quote) { q('qtext').textContent = v.quote + '”'; q('quote').hidden = false; }
        const ph = q('photo'), ini$ = q('ini'); ini$.textContent = ini(v.name);
        if (v.photo && /^(\/(?!\/)|https:\/\/)/.test(v.photo)) {
          const img = new Image(); img.alt = 'Portrait of ' + (v.name || 'our Volunteer of the Month'); img.decoding = 'async';
          img.onload = () => ph.replaceChildren(img); img.src = v.photo;
        }
        if (v.id) q('link').href = '/people/' + encodeURIComponent(v.id) + '-' + slug(v.name) + '/';
        set('ready');
      })
      .catch(() => set('empty'));
  });

  addEventListener('keydown', e => {
    if (e.key !== 'Escape' || !layers.length) return;
    layers[layers.length - 1].close();
  });
})();
