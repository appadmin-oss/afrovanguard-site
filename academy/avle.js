/**
 * Academy lesson player behaviour. Vanilla, no deps; one guarded init per
 * feature. Progress, quizzes and notes use the existing /academy/api.php
 * actions (lesson_complete, lesson_uncomplete, quiz_submit, note_save,
 * notes_all). Payments / pass codes / sign-in links come from avac.js.
 */
(() => {
  'use strict';
  const body = document.body;
  if (!body || !body.classList.contains('avle')) return;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const run = (name, fn) => { try { fn(); } catch (err) { console.error('[avle] ' + name, err); } };
  const AC = window.avAcademy || {};
  const API = '/academy/api.php';
  const post = (action, data) => fetch(API + '?action=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data), credentials: 'same-origin' }).then(r => r.json());
  const toast = m => (AC.toast ? AC.toast(m) : null);
  const goLogin = () => (AC.goLogin ? AC.goLogin('login') : (location.href = '/login?next=' + encodeURIComponent(location.pathname)));
  const desk = matchMedia('(min-width: 960px)');
  const onMq = (mq, fn) => (mq.addEventListener ? mq.addEventListener('change', fn) : mq.addListener(fn));
  const main = $('.avle-main');
  const course = main ? main.dataset.course : '';
  const lesson = main ? main.dataset.lesson : '';

  /* Esc closes the top-most layer only. */
  const layers = [];
  const pushLayer = l => { if (!layers.includes(l)) layers.push(l); };
  const dropLayer = l => { const i = layers.indexOf(l); if (i > -1) layers.splice(i, 1); };
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && layers.length) { e.preventDefault(); layers[layers.length - 1].close(true); } });
  const scrim = $('[data-avle-scrim]');
  const syncScrim = () => { if (scrim) scrim.hidden = !layers.some(l => l.modal); };
  if (scrim) scrim.addEventListener('click', () => { const top = layers[layers.length - 1]; if (top) top.close(false); });
  const focusables = el => $$('a[href],button:not([disabled]),textarea,input,select', el).filter(x => x.offsetParent !== null || x === document.activeElement);
  const trap = (el, e) => {
    if (e.key !== 'Tab') return;
    const f = focusables(el); if (!f.length) return;
    const first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  };

  /* A panel that sits beside the lesson on desktop and is a drawer below 960px. */
  const panel = (el, triggers, opts) => {
    let open = false, returnTo = null;
    const layer = { modal: false, close: refocus => set(false, refocus) };
    const onKey = e => trap(el, e);
    const set = (on, refocus) => {
      open = on;
      const drawer = !desk.matches;
      opts.apply(on, drawer);
      triggers.forEach(t => {
        t.setAttribute('aria-expanded', String(on));
        if (opts.label) t.setAttribute('aria-label', opts.label(on));
      });
      layer.modal = drawer;
      if (on && drawer) {
        el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true');
        el.addEventListener('keydown', onKey); pushLayer(layer);
        setTimeout(() => { const f = (opts.focus && opts.focus()) || focusables(el)[0]; if (f) f.focus(); }, 30);
      } else {
        el.removeAttribute('role'); el.removeAttribute('aria-modal');
        el.removeEventListener('keydown', onKey);
        if (on && opts.escOnDesk) pushLayer(layer); else dropLayer(layer);
        if (on && opts.focus) { const f = opts.focus(); if (f) f.focus(); }
      }
      if (!on && refocus && returnTo) returnTo.focus();
      syncScrim();
    };
    triggers.forEach(t => t.addEventListener('click', () => { returnTo = t; set(!open, true); }));
    return { set, isOpen: () => open, layer };
  };

  run('side', () => {
    const side = $('[data-avle-side]');
    const toggles = $$('[data-avle-side-toggle]');
    if (!side || !toggles.length) return;
    const p = panel(side, toggles, {
      apply: (on, drawer) => {
        side.classList.toggle('is-open', on && drawer);
        body.classList.toggle('is-side-off', !on && !drawer);
      },
      label: on => (on ? 'Hide contents' : 'Contents'),
      focus: () => (desk.matches ? null : $('[data-avle-cur]', side) || $('[data-avle-side-close]', side))
    });
    // Desktop starts with the contents open, phone and tablet with them closed.
    const init = () => { dropLayer(p.layer); p.set(desk.matches, false); };
    init(); onMq(desk, init);
    const x = $('[data-avle-side-close]', side);
    if (x) x.addEventListener('click', () => { p.set(false, true); });
        $$('[data-avle-mod]', side).forEach(b => b.addEventListener('click', () => {
      const list = document.getElementById(b.getAttribute('aria-controls'));
      const on = b.getAttribute('aria-expanded') !== 'true';
      b.setAttribute('aria-expanded', String(on));
      if (list) list.hidden = !on;
    }));
    // Keep the current lesson in view inside the contents column.
    const cur = $('[data-avle-cur]', side);
    if (cur && desk.matches) side.scrollTop = Math.max(0, cur.offsetTop - side.clientHeight / 2);
  });

  run('notes', () => {
    const el = $('[data-avle-notes]');
    if (!el) return;
    const area = $('[data-avle-notes-area]', el), status = $('[data-avle-notes-status]', el);
    const key = el.dataset.key, remote = el.dataset.remote === '1';
    const p = panel(el, $$('[data-avle-notes-toggle]'), { apply: on => { el.hidden = !on; }, focus: () => area, escOnDesk: true });
    const close = $('[data-avle-notes-close]', el);
    if (close) close.addEventListener('click', () => p.set(false, true));
    onMq(desk, () => { if (p.isOpen()) p.set(false, false); });
    const store = (() => { try { return window.localStorage; } catch (e) { return null; } })();
    const get = k => { try { return store ? store.getItem(k) : null; } catch (e) { return null; } };
    if (area && !remote) { const v = get(key); if (v != null) area.value = v; }
    let ft = 0;
    const flash = m => { if (!status) return; status.textContent = m; clearTimeout(ft); ft = setTimeout(() => { status.textContent = ''; }, 1800); };
    let t = 0;
    if (area) area.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(() => {
        const v = area.value;
        if (remote) {
          flash('Saving…');
          post('note_save', { course, lesson, body: v }).then(d => flash(d && d.ok ? 'Saved' : 'Not saved')).catch(() => flash('Offline — not saved'));
        } else {
          try { if (v.trim() === '') store.removeItem(key); else store.setItem(key, v); flash('Saved on this device'); } catch (e) { flash('Not saved'); }
        }
      }, remote ? 600 : 350);
    });
    const download = items => {
      if (!items.length) { toast('No notes saved yet — start typing to capture your first note.'); return; }
      let md = '# My notes — ' + course + '\n\n';
      items.forEach(it => { md += '## ' + it.title + '\n\n' + String(it.body).trim() + '\n\n'; });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([md], { type: 'text/markdown' }));
      a.download = course + '-notes.md';
      document.body.appendChild(a); a.click();
      setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
      flash('Downloaded');
    };
    const dl = $('[data-avle-notes-dl]', el);
    if (dl) dl.addEventListener('click', () => {
      if (remote) {
        fetch(API + '?action=notes_all&course=' + encodeURIComponent(course), { credentials: 'same-origin' })
          .then(r => r.json())
          .then(d => { if (!d || !d.ok) { toast('Could not load your notes.'); return; } download((d.notes || []).map(n => ({ title: n.title, body: n.body }))); })
          .catch(() => toast('Network error — please try again.'));
        return;
      }
      const prefix = 'av.notes.' + course + '.', items = [];
      try {
        for (let i = 0; i < store.length; i++) {
          const k = store.key(i);
          if (!k || k.indexOf(prefix) !== 0) continue;
          const v = get(k) || ''; if (!v.trim()) continue;
          const s = k.slice(prefix.length);
          items.push({ slug: s, title: s === lesson ? el.dataset.lessonTitle : s.replace(/-/g, ' '), body: v });
        }
      } catch (e) { /* storage blocked */ }
      items.sort((a, b) => a.slug.localeCompare(b.slug));
      download(items);
    });
  });

  run('share', () => {
    const b = $('[data-avle-share]'); if (!b) return;
    const label = $('[data-avle-share-label]', b);
    const done = () => { if (label) { label.textContent = 'Link copied'; setTimeout(() => { label.textContent = 'Share'; }, 2000); } };
    const fallback = () => {
      const ta = document.createElement('textarea'); ta.value = location.href; ta.setAttribute('readonly', '');
      ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { toast('Copy this link: ' + location.href); }
      ta.remove();
    };
    b.addEventListener('click', () => {
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(location.href).then(done, fallback);
      else fallback();
    });
  });

  /* Progress readouts: header ring, contents bar and note, module count, row. */
  const paint = (p, lessonDone) => {
    if (!p) return;
    const ring = $('.avle-ring'); if (ring) ring.style.setProperty('--p', p.pct);
    const c = $('[data-avle-count]'); if (c) c.textContent = p.completed + '/' + p.total;
    const sr = $('[data-avle-count-sr]'); if (sr) sr.textContent = p.completed + ' of ' + p.total + ' lessons complete';
    const bar = $('[data-avle-bar]'); if (bar) bar.style.width = p.pct + '%';
    const note = $('[data-avle-note]'); if (note) note.textContent = p.pct + '% · ' + p.completed + ' of ' + p.total + ' lessons complete';
    const row = $('[data-avle-cur]');
    if (row && typeof lessonDone === 'boolean') {
      row.classList.toggle('is-done', lessonDone);
      const ck = $('.avle-ck', row); if (ck) ck.textContent = lessonDone ? '✓' : '';
      const s = $('[data-avle-done-sr]', row); if (s) s.textContent = lessonDone ? ' (completed)' : '';
      const mod = row.closest('.avle-mod');
      const mc = mod && $('[data-avle-modcount]', mod);
      if (mc) mc.textContent = $$('.avle-item.is-done', mod).length + '/' + $$('.avle-item', mod).length + ' complete';
    }
    const fin = $('[data-avle-done]'); if (fin) fin.hidden = !p.complete;
    if (p.complete) toast('Course complete. Claim your certificate.');
  };

  /* Up next: counts down, then moves on; Cancel keeps the learner here. */
  let upT = 0;
  const up = $('[data-avle-upnext]');
  const upLayer = { modal: false, close: () => cancelUp() };
  const cancelUp = () => { clearInterval(upT); upT = 0; if (up) up.hidden = true; dropLayer(upLayer); };
  const showUp = () => {
    if (!up || !up.dataset.nextUrl) return false;
    const k = $('[data-avle-upnext-k]', up);
    let left = 6;
    up.hidden = false; pushLayer(upLayer);
    if (k) k.textContent = 'Up next in ' + left;
    const go = $('.avle-upnext-go', up); if (go) go.focus({ preventScroll: true });
    clearInterval(upT);
    upT = setInterval(() => {
      left--;
      if (k) k.textContent = left > 0 ? 'Up next in ' + left : 'Up next';
      if (left <= 0) { clearInterval(upT); location.href = up.dataset.nextUrl; }
    }, 1000);
    return true;
  };
  run('upnext', () => {
    const c = $('[data-avle-upnext-cancel]'); if (!c) return;
    c.addEventListener('click', () => { cancelUp(); const b = $('[data-avle-complete]') || main; if (b) b.focus(); });
  });

  run('complete', () => {
    const btn = $('[data-avle-complete]'); if (!btn) return;
    btn.addEventListener('click', () => {
      const done = btn.dataset.done === '1';
      const next = btn.dataset.next || '';
      btn.disabled = true;
      post(done ? 'lesson_uncomplete' : 'lesson_complete', { course, lesson })
        .then(d => {
          if (!d || !d.ok) { toast((d && d.error) || 'Please sign in.'); if (d && /sign in/i.test(d.error || '')) goLogin(); return; }
          const now = !done;
          btn.dataset.done = now ? '1' : '0';
          btn.textContent = now ? 'Completed ✓' : 'Mark complete' + (next ? ' & continue' : '');
          paint(d.progress, now);
          if (now && next && !(d.progress && d.progress.complete)) { if (!showUp()) setTimeout(() => { location.href = next; }, 500); }
          else cancelUp();
        })
        .catch(() => toast('Network error — please try again.'))
        .finally(() => { btn.disabled = false; });
    });
  });

  run('quiz', () => {
    const form = $('[data-avle-quiz]'); if (!form) return;
    const out = $('[data-avle-quiz-out]', form);
    const say = (m, ok) => { if (!out) return; out.textContent = m; out.classList.toggle('is-ok', !!ok); out.classList.toggle('is-err', !ok); };
    form.addEventListener('submit', e => {
      e.preventDefault();
      const qs = $$('fieldset.avle-q', form);
      const answers = qs.map((fs, i) => { const s = $('input[name="q' + i + '"]:checked', fs); return s ? parseInt(s.value, 10) : -1; });
      const missing = answers.indexOf(-1);
      if (missing > -1) { say('Please answer every question.', false); const f = $('input', qs[missing]); if (f) f.focus(); return; }
      const b = $('button[type=submit]', form); if (b) b.disabled = true;
      post('quiz_submit', { course, lesson, answers })
        .then(d => {
          if (!d || !d.ok) { say((d && d.error) || 'Please sign in.', false); if (d && /sign in/i.test(d.error || '')) goLogin(); return; }
          say('You scored ' + d.score + '%. ' + (d.passed ? 'Passed — lesson complete.' : 'You need ' + d.pass + '% to pass. Try again.'), d.passed);
          const st = $('[data-avle-qstatus]');
          if (st && d.passed) { st.textContent = 'Completed ✓'; st.classList.add('is-done'); }
          if (b) b.textContent = 'Retake quiz';
          paint(d.progress, d.passed ? true : undefined);
          if (d.passed && d.progress && !d.progress.complete) showUp();
        })
        .catch(() => say('Network error — please try again.', false))
        .finally(() => { if (b) b.disabled = false; });
    });
  });

  run('readbar', () => {
    const bar = $('[data-avle-readbar]'); if (!bar || !main) return;
    let ticking = false;
    const update = () => {
      const r = main.getBoundingClientRect(), vh = document.documentElement.clientHeight;
      const total = r.height - vh;
      const pct = total > 0 ? Math.max(0, Math.min(1, -r.top / total)) : 1;
      bar.style.width = (pct * 100).toFixed(1) + '%';
      ticking = false;
    };
    addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
    addEventListener('resize', update, { passive: true });
    update();
  });

  run('keys', () => {
    document.addEventListener('keydown', e => {
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      const t = e.target, tag = (t && t.tagName) || '';
      if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (t && t.isContentEditable)) return;
      const k = (e.key || '').toLowerCase();
      if (k === 'n') { const a = $('[data-avle-next]'); if (a) { e.preventDefault(); location.href = a.href; } }
      else if (k === 'p') { const a = $('[data-avle-prev]'); if (a) { e.preventDefault(); location.href = a.href; } }
      else if (k === 'k') { const b = $('[data-avle-complete]'); if (b) { e.preventDefault(); b.click(); } }
      else if (k === 's') { const b = $('[data-avle-side-toggle]'); if (b) { e.preventDefault(); b.click(); } }
    });
  });
})();
