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
    const won = p.certified === undefined ? p.complete : p.certified;
    const fin = $('[data-avle-done]'); if (fin) fin.hidden = !won;
    if (won) toast('Course complete. Claim your certificate.');
    else if (p.complete) toast('Every item done. Check your grades to see what’s left to pass.');
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
          btn.textContent = now ? 'Completed' : 'Mark complete' + (next ? ' & continue' : '');
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
    const graded = form.dataset.graded === '1';
    form.addEventListener('submit', e => {
      e.preventDefault();
      const qs = $$('fieldset.avle-q', form);
      const answers = qs.map(fs => {
        const picked = $$('input:checked', fs).map(i => parseInt(i.value, 10));
        return fs.dataset.type === 'multi' ? picked : (picked.length ? picked[0] : -1);
      });
      const gaps = answers.map((a, i) => (Array.isArray(a) ? a.length === 0 : a === -1) ? i : -1).filter(i => i > -1);
      qs.forEach((fs, i) => { const off = gaps.includes(i); fs.classList.toggle('is-missing', off); if (off) fs.setAttribute('aria-invalid', 'true'); else fs.removeAttribute('aria-invalid'); });
      if (gaps.length) {
        /* Say WHICH: "please answer every question" left a screen-reader user
           to find the gap by reading the quiz again. */
        const names = gaps.map(i => 'question ' + (i + 1));
        const list = names.length === 1 ? names[0] : names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1];
        say(list.charAt(0).toUpperCase() + list.slice(1) + (gaps.length === 1 ? ' still needs an answer.' : ' still need answers.'), false);
        const f = $('input', qs[gaps[0]]); if (f) f.focus(); return;
      }
      const b = $('button[type=submit]', form); if (b) b.disabled = true;
      post('quiz_submit', { course, lesson, answers })
        .then(d => {
          if (!d || !d.ok) { say((d && d.error) || 'Please sign in.', false); if (d && /sign in/i.test(d.error || '')) goLogin(); return; }
          (d.marks || []).forEach((m, i) => {
            const fs = qs[i]; if (!fs) return;
            fs.classList.toggle('is-right', m.correct); fs.classList.toggle('is-wrong', !m.correct);
            $$('.avle-opt', fs).forEach(l => l.classList.toggle('is-key', false));
            if (m.answer !== null && m.answer !== undefined) {
              [].concat(m.answer).forEach(k => { const l = $('.avle-opt[data-opt="' + k + '"]', fs); if (l) l.classList.add('is-key'); });
            }
            const fb = $('[data-avle-qfb]', fs);
            if (fb) { fb.hidden = false; fb.textContent = (m.correct ? 'Correct.' : 'Not quite.') + (m.explain ? ' ' + m.explain : ''); }
          });
          const left = d.attempts_left === null || d.attempts_left === undefined ? '' : ' ' + d.attempts_left + ' attempt' + (d.attempts_left === 1 ? '' : 's') + ' left.';
          if (!graded) say('You scored ' + d.score + '%. Practice complete.' + left, true);
          else say('You scored ' + d.score + '%. ' + (d.passed ? 'Passed.' : 'You need ' + d.pass + '% to pass.') + left, d.passed);
          const st = $('[data-avle-qstatus]');
          const doneNow = d.passed || !graded;
          if (st && doneNow) { st.textContent = 'Completed'; st.classList.add('is-done'); }
          if (b) { b.textContent = 'Try again'; if (d.attempts_left === 0) b.hidden = true; }
          paint(d.progress, doneNow ? true : undefined);
          if (doneNow && d.progress && !d.progress.complete) showUp();
        })
        .catch(() => say('Network error — please try again.', false))
        .finally(() => { if (b) b.disabled = false; });
    });
  });

  /* Assignment: submit or resubmit; the instructor grades it. */
  run('assignment', () => {
    const form = $('[data-avle-asg]'); if (!form) return;
    const out = $('[data-avle-asg-out]', form);
    const tell = (m, ok) => { if (out) { out.textContent = m; out.className = 'avle-quiz-out ' + (ok ? 'is-ok' : 'is-err'); } };
    form.addEventListener('submit', e => {
      e.preventDefault();
      const b = $('button[type=submit]', form); if (b) b.disabled = true;
      post('assignment_submit', { course, lesson, body: form.elements.body.value, url: form.elements.url.value })
        .then(d => {
          if (!d || !d.ok) { tell((d && d.error) || 'Could not submit.', false); return; }
          tell('Submitted — your instructor will grade it.', true);
          if (b) b.textContent = 'Resubmit';
          const st = $('[data-avle-asg-state]');
          if (st) { st.className = 'avle-asg-state is-submitted'; st.textContent = ''; const k = document.createElement('b'); k.textContent = 'Submitted'; st.append(k, ' Waiting for your instructor to grade it.'); }
        })
        .catch(() => tell('Network error — please try again.', false))
        .finally(() => { if (b) b.disabled = false; });
    });
  });

  /* Discussion item: posting a response completes it. */
  run('discussion', () => {
    const form = $('[data-avle-disc]'); if (!form) return;
    const out = $('[data-avle-disc-out]', form);
    const tell = (m, ok) => { if (out) { out.textContent = m; out.className = 'avle-quiz-out ' + (ok ? 'is-ok' : 'is-err'); } };
    form.addEventListener('submit', e => {
      e.preventDefault();
      const ta = form.elements.body; const text = ta.value.trim(); if (!text) return;
      const b = $('button[type=submit]', form); if (b) b.disabled = true;
      post('forum_reply', { course, lesson, body: text })
        .then(d => {
          if (!d || !d.ok) { tell((d && d.error) || 'Could not post.', false); return; }
          const list = $('.avle-posts');
          const none = list && $('.avle-posts-none', list); if (none) none.hidden = true;
          if (list) {
            const li = document.createElement('li');
            const h = document.createElement('div'); h.className = 'avle-post-h';
            const who = document.createElement('b'); who.textContent = 'You';
            const when = document.createElement('span'); when.className = 'av-num'; when.textContent = 'Just now';
            h.append(who, when);
            const bd = document.createElement('div'); bd.className = 'avle-post-b'; bd.textContent = text;
            li.append(h, bd); list.prepend(li);
          }
          ta.value = '';
          tell('Posted.', true);
          const st = $('[data-avle-qstatus]'); if (st) { st.textContent = 'Completed'; st.classList.add('is-done'); }
          if (d.progress) { paint(d.progress, true); if (!d.progress.complete) showUp(); }
        })
        .catch(() => tell('Network error — please try again.', false))
        .finally(() => { if (b) b.disabled = false; });
    });
  });

  /* Native video: speed, resume where you left off, a transcript that follows
     the captions (click a line to jump there), and the item completes itself
     once 90% has been watched — without leaving the page. */
  run('video', () => {
    const v = $('[data-avle-video]'); if (!v) return;
    const key = v.dataset.key || '';
    const store = (k, val) => { try { if (val === null) localStorage.removeItem(k); else localStorage.setItem(k, val); } catch (e) { /* private mode */ } };
    const read = k => { try { return localStorage.getItem(k); } catch (e) { return null; } };

    const speeds = $$('[data-avle-speed]');
    const setSpeed = r => {
      v.playbackRate = r;
      speeds.forEach(b => b.setAttribute('aria-pressed', String(parseFloat(b.dataset.avleSpeed) === r)));
      store('av.vspeed', String(r));
    };
    speeds.forEach(b => b.addEventListener('click', () => setSpeed(parseFloat(b.dataset.avleSpeed))));
    const sp = parseFloat(read('av.vspeed') || '1'); if (sp && sp !== 1) setSpeed(sp);

    v.addEventListener('loadedmetadata', () => {
      const t = parseFloat(read(key) || '0');
      if (t > 5 && v.duration && t < v.duration - 10) v.currentTime = t;
    }, { once: true });
    let last = 0, sent = false;
    v.addEventListener('timeupdate', () => {
      const now = v.currentTime;
      if (Math.abs(now - last) > 5) { last = now; store(key, String(Math.floor(now))); }
      if (!sent && v.dataset.autocomplete === '1' && v.duration && now / v.duration >= 0.9) {
        sent = true;
        post('lesson_complete', { course, lesson }).then(d => {
          if (!d || !d.ok) { sent = false; return; }
          const b = $('[data-avle-complete]');
          if (b) { b.dataset.done = '1'; b.textContent = 'Completed'; }
          paint(d.progress, true);
        }).catch(() => { sent = false; });
      }
    });
    v.addEventListener('ended', () => store(key, null));

    /* Transcript from the caption track's cues. */
    const box = $('[data-avle-trans][data-cues]');
    const list = box && $('[data-avle-cues]', box);
    const track = v.textTracks && v.textTracks[0];
    if (!list || !track) return;
    track.mode = track.mode === 'showing' ? 'showing' : 'hidden';
    const fmt = s => { const m = Math.floor(s / 60), r = Math.floor(s % 60); return m + ':' + String(r).padStart(2, '0'); };
    const build = () => {
      const cues = track.cues ? Array.from(track.cues) : [];
      if (!cues.length || list.childElementCount) return;
      cues.forEach(c => {
        const li = document.createElement('li');
        const b = document.createElement('button'); b.type = 'button'; b.className = 'avle-cue'; b.dataset.start = String(c.startTime);
        const t = document.createElement('span'); t.className = 'avle-cue-t av-num'; t.textContent = fmt(c.startTime);
        const x = document.createElement('span'); x.textContent = String(c.text || '').replace(/<[^>]*>/g, '');
        b.append(t, x);
        b.addEventListener('click', () => { v.currentTime = c.startTime; v.play().catch(() => {}); });
        li.appendChild(b); list.appendChild(li);
      });
      list.hidden = false;
      const txt = $('[data-avle-trans-text]', box); if (txt) txt.hidden = true;
    };
    const el = v.querySelector('track');
    if (el) el.addEventListener('load', build);
    build();
    track.addEventListener('cuechange', () => {
      const act = track.activeCues && track.activeCues[0];
      $$('.avle-cue', list).forEach(b => {
        const on = !!act && Math.abs(parseFloat(b.dataset.start) - act.startTime) < 0.01;
        b.classList.toggle('is-on', on);
        if (on && box.open) list.scrollTop = Math.max(0, b.parentNode.offsetTop - list.clientHeight / 3);
      });
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

  /* -- Study coach ----------------------------------------------------
     A drawer beside the lesson, opened by a control that is always on
     screen: a learner who is stuck is stuck HERE, and a panel they must
     scroll past the whole lesson to find is one they do not use.

     It is a CONVERSATION. The turns so far travel with each message, so
     "say that again, simpler" means something. The four quick actions are
     the four things people actually ask for.

     "Quiz me" is PRACTICE. It records nothing -- no attempt, no score, no
     progress -- and the panel says so where it cannot be missed. */
  run('coach', () => {
    const el = $('[data-avle-coach]');
    if (!el) return;
    const log = $('[data-avle-coach-log]', el);
    const form = $('[data-avle-coach-form]', el);
    const area = $('#avle-coach-q', el);
    const sendBtn = $('[data-avle-coach-send]', el);
    const acts = $('[data-avle-coach-acts]', el);
    const p = panel(el, $$('[data-avle-coach-toggle]'), { apply: on => { el.hidden = !on; }, focus: () => area, escOnDesk: true });
    const closeBtn = $('[data-avle-coach-close]', el);
    if (closeBtn) closeBtn.addEventListener('click', () => p.set(false, true));
    onMq(desk, () => { if (p.isOpen()) p.set(false, false); });

    /* Where a person is: the course's own forum (academy/forum.php). The
       coach always offers it, and must offer the REAL one rather than a
       second discussion of its own. */
    const FORUM = el.dataset.forum || '';
    const esc = x => String(x == null ? '' : x).replace(/[&<>"']/g, c =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const para = t => esc(t).split(/\n{2,}/).map(x => '<p>' + x.replace(/\n/g, '<br>') + '</p>').join('');
    let turns = [];      /* what the model is told about earlier turns */
    let answering = false;  /* a practice question is open, awaiting an answer */
    let busy = false;

    const bubble = (who, html, extra) => {
      const d = document.createElement('div');
      d.className = 'avle-cm is-' + who + (extra ? ' ' + extra : '');
      d.innerHTML = html;
      log.appendChild(d);
      log.scrollTop = log.scrollHeight;
      return d;
    };
    const setBusy = on => {
      busy = on;
      sendBtn.disabled = on;
      acts.querySelectorAll('button').forEach(b => { b.disabled = on; });
    };
    const ask = (mode, typed) => {
      if (busy) return;
      const label = { summarise: 'Summarise this lesson', simpler: 'Explain it more simply',
                      example: 'Give me an example', quiz: 'Ask me a practice question' }[mode];
      const shown = typed || label || '';
      if (shown) bubble('me', para(shown));
      setBusy(true);
      const wait = bubble('coach', '<p class="avle-cm-wait">Reading the lesson...</p>');
      post('coach_ask', { course: el.dataset.course, lesson: el.dataset.lesson,
                          q: typed || '', mode: mode, history: turns })
        .then(j => {
          if (wait.parentNode) wait.parentNode.removeChild(wait);
          if (!j.ok) {
            bubble('coach', '<p class="avle-cm-err">' + esc(j.error || 'No answer just now.')
              + ' <a href="' + esc(FORUM) + '" data-avle-coach-disc>Ask in the discussions</a></p>', 'is-err');
            return;
          }
          const foot = j.mode === 'quiz'
            ? '<p class="avle-cm-foot">Practice only -- nothing here is marked or recorded.</p>'
            : (j.grounded
                ? '<p class="avle-cm-foot">From this lesson. If it reads wrong, trust the lesson and <a href="' + esc(FORUM) + '" data-avle-coach-disc>ask a person</a>.</p>'
                : '<p class="avle-cm-foot">The lesson does not cover that -- <a href="' + esc(FORUM) + '" data-avle-coach-disc>ask in the discussions</a>, your instructor sees it.</p>');
          bubble('coach', para(j.text) + foot);
          if (shown) turns.push({ role: 'learner', text: shown });
          turns.push({ role: 'coach', text: j.text });
          if (turns.length > 8) turns = turns.slice(-8);
          /* After a practice question the next thing typed is an ANSWER, so
             it is sent as one and comes back as feedback rather than as a
             fresh question. */
          answering = j.mode === 'quiz';
          area.placeholder = answering ? 'Your answer...' : 'Ask about this lesson...';
        })
        .catch(() => {
          if (wait.parentNode) wait.parentNode.removeChild(wait);
          bubble('coach', '<p class="avle-cm-err">No connection.</p>', 'is-err');
        })
        .finally(() => { setBusy(false); area.focus(); });
    };

    acts.addEventListener('click', ev => {
      const b = ev.target.closest('[data-coach-mode]');
      if (b) { ev.preventDefault(); ask(b.getAttribute('data-coach-mode'), ''); }
    });
    form.addEventListener('submit', ev => {
      ev.preventDefault();
      const t = (area.value || '').trim();
      if (!t) { area.focus(); return; }
      area.value = '';
      ask(answering ? 'quiz_mark' : 'ask', t);
    });
    /* Enter sends, Shift+Enter is a new line: the shape of every other
       message box a learner has used. */
    area.addEventListener('keydown', ev => {
      if (ev.key === 'Enter' && !ev.shiftKey) {
        ev.preventDefault();
        if (form.requestSubmit) form.requestSubmit();
        else form.dispatchEvent(new Event('submit', { cancelable: true }));
      }
    });
    /* A link to the discussion closes the drawer on a phone, where the two
       would otherwise sit on top of each other. */
    el.addEventListener('click', ev => {
      if (ev.target.closest('[data-avle-coach-disc]') && !desk.matches) p.set(false, false);
    });
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
