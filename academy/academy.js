/* ============================================================
   Afrovanguard Academy — Coursera-style interactions + learning
   flows (catalogue filter/sort, course tabs, curriculum accordion,
   lesson-player sidebar, payments, quizzes, lesson progress).

   Sign-in is the standalone /login page (no modal); the site-wide
   nav.js owns the account chip + logout. This file keeps the
   Academy-specific behaviour and sends any "please sign in" moment
   to /login with a ?next= back to the current page.
   ============================================================ */
(function () {
  'use strict';
  var API = '/academy/api.php';
  function api(action, opts) {
    opts = opts || {};
    return fetch(API + '?action=' + action, {
      method: opts.method || 'GET',
      headers: opts.body ? { 'Content-Type': 'application/json' } : {},
      body: opts.body ? JSON.stringify(opts.body) : undefined,
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }
  function toast(m) {
    var t = document.querySelector('.toast');
    if (!t) { t = document.createElement('div'); t.className = 'toast'; document.body.appendChild(t); }
    t.textContent = m; t.classList.add('show'); clearTimeout(toast._t); toast._t = setTimeout(function () { t.classList.remove('show'); }, 2600);
  }
  // Reveal the in-player "course complete → get your certificate" banner.
  function revealDone() { var cd = document.getElementById('courseDone'); if (cd && cd.hidden) { cd.hidden = false; try { cd.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch (e) {} } }

  /* ---- "Up next" auto-continue card (Coursera/YouTube-style) ----
     Shown when a lesson is completed and another lesson follows. Counts
     down, then advances — cancelable so a learner can linger. */
  var _upNextTimer = null;
  function cancelUpNext() {
    if (_upNextTimer) { clearInterval(_upNextTimer); _upNextTimer = null; }
    var el = document.getElementById('upNext'); if (el) { el.hidden = true; el.classList.remove('show'); }
  }
  function showUpNext() {
    var el = document.getElementById('upNext');
    if (!el) return false;
    var url = el.getAttribute('data-next-url'); if (!url) return false;
    var countEl = el.querySelector('[data-upnext-count]');
    var go = function () { cancelUpNext(); window.location.href = url; };
    el.hidden = false;
    requestAnimationFrame(function () { el.classList.add('show'); });
    try { el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch (e) {}
    var left = 6;
    if (countEl) countEl.textContent = '(' + left + ')';
    if (_upNextTimer) clearInterval(_upNextTimer);
    _upNextTimer = setInterval(function () {
      left--;
      if (countEl) countEl.textContent = left > 0 ? '(' + left + ')' : '';
      if (left <= 0) go();
    }, 1000);
    var goBtn = el.querySelector('[data-upnext-go]');
    var cancelBtn = el.querySelector('[data-upnext-cancel]');
    if (goBtn && !goBtn._wired) { goBtn._wired = 1; goBtn.addEventListener('click', go); }
    if (cancelBtn && !cancelBtn._wired) { cancelBtn._wired = 1; cancelBtn.addEventListener('click', cancelUpNext); }
    return true;
  }

  /* ---- Scroll reveal (matches the Diary) ---- */
  var revealAll = function () { document.querySelectorAll('[data-reveal], .reveal-stagger').forEach(function (n) { n.classList.add('in'); }); };
  if ('IntersectionObserver' in window) {
    var rev = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); rev.unobserve(e.target); } });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
    document.querySelectorAll('[data-reveal], .reveal-stagger').forEach(function (n) { rev.observe(n); });
    setTimeout(revealAll, 3000); // safety: never leave content hidden
  } else { revealAll(); }

  /* ---- Catalogue: search + category filter + sort ----
     Cards carry data-cat / data-level / data-search / data-title /
     data-featured / data-lessons. A card shows when it matches the active
     category AND the search query; the visible set is then ordered by the
     chosen sort key. Order is applied by reordering DOM nodes in the grid. */
  (function () {
    var grid = document.querySelector('.ac-grid');
    var input = document.querySelector('.search-input');
    var chips = Array.prototype.slice.call(document.querySelectorAll('.ac-filters .chip[data-filter], .diary-filters .chip[data-filter]'));
    if (!grid || (!input && !chips.length)) return;
    var cards = Array.prototype.slice.call(grid.querySelectorAll('.ac-card'));
    var noResults = document.querySelector('.no-results');
    var countEl = document.querySelector('[data-count]');
    var sortSel = document.querySelector('.ac-sort-select');
    var clearBtn = document.querySelector('[data-clear-filters]');
    var total = cards.length;
    var cat = 'all';

    function num(c, attr) { return parseInt(c.getAttribute(attr) || '0', 10) || 0; }
    function sortCards() {
      if (!sortSel) return;
      var key = sortSel.value;
      var arr = cards.slice();
      arr.sort(function (a, b) {
        if (key === 'title') return (a.getAttribute('data-title') || '').localeCompare(b.getAttribute('data-title') || '');
        if (key === 'lessons') return num(b, 'data-lessons') - num(a, 'data-lessons');
        // featured / recommended: featured first, then keep DOM order
        return num(b, 'data-featured') - num(a, 'data-featured');
      });
      arr.forEach(function (c) { grid.appendChild(c); });
    }
    function apply() {
      var q = (input && input.value || '').trim().toLowerCase();
      var shown = 0;
      cards.forEach(function (c) {
        var okCat = cat === 'all' || c.getAttribute('data-cat') === cat;
        var okQ = !q || (c.getAttribute('data-search') || '').indexOf(q) !== -1;
        var show = okCat && okQ;
        c.hidden = !show; if (show) shown++;
      });
      if (noResults) noResults.style.display = shown ? 'none' : 'block';
      if (countEl) {
        if (shown === total && cat === 'all' && !q) countEl.textContent = 'Showing all ' + total;
        else countEl.textContent = 'Showing ' + shown + ' of ' + total;
      }
    }
    if (input) {
      var t; input.addEventListener('input', function () { clearTimeout(t); t = setTimeout(apply, 120); });
      input.addEventListener('search', apply); // clearing the native ✕
    }
    chips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        cat = chip.getAttribute('data-filter') || 'all';
        chips.forEach(function (c) { var on = c === chip; c.classList.toggle('active', on); c.setAttribute('aria-selected', on ? 'true' : 'false'); });
        apply();
      });
    });
    if (sortSel) sortSel.addEventListener('change', function () { sortCards(); apply(); });
    if (clearBtn) clearBtn.addEventListener('click', function () {
      cat = 'all'; if (input) input.value = '';
      chips.forEach(function (c) { var on = c.getAttribute('data-filter') === 'all'; c.classList.toggle('active', on); c.setAttribute('aria-selected', on ? 'true' : 'false'); });
      apply();
    });
    sortCards();
    apply();
  })();

  /* ---- Course detail: tabbed sections (Overview / Curriculum / Certificate) ---- */
  (function () {
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.course-tab[data-tab]'));
    if (!tabs.length) return;
    var panels = Array.prototype.slice.call(document.querySelectorAll('.course-panel[data-panel]'));
    function show(name, focus) {
      tabs.forEach(function (t) { var on = t.getAttribute('data-tab') === name; t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
      panels.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
      if (focus) { try { history.replaceState(null, '', '#' + name); } catch (e) {} }
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-tab'), true); }); });
    // keyboard arrows between tabs
    tabs.forEach(function (t, i) {
      t.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        e.preventDefault();
        var ni = e.key === 'ArrowRight' ? (i + 1) % tabs.length : (i - 1 + tabs.length) % tabs.length;
        tabs[ni].focus(); show(tabs[ni].getAttribute('data-tab'), true);
      });
    });
    // deep-link: #curriculum / #certificate opens that tab
    var hash = (location.hash || '').replace('#', '');
    if (hash && tabs.some(function (t) { return t.getAttribute('data-tab') === hash; })) show(hash, false);
  })();

  /* ---- Course detail: curriculum accordion + "expand all" ---- */
  (function () {
    var modules = Array.prototype.slice.call(document.querySelectorAll('.curriculum .module'));
    if (!modules.length) return;
    modules.forEach(function (m) {
      var head = m.querySelector('.module-head');
      if (!head) return;
      head.addEventListener('click', function () {
        var open = m.classList.toggle('is-open');
        head.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    });
    var expand = document.querySelector('[data-expand-all]');
    if (expand) expand.addEventListener('click', function () {
      var anyClosed = modules.some(function (m) { return !m.classList.contains('is-open'); });
      modules.forEach(function (m) {
        m.classList.toggle('is-open', anyClosed);
        var h = m.querySelector('.module-head'); if (h) h.setAttribute('aria-expanded', anyClosed ? 'true' : 'false');
      });
      expand.textContent = anyClosed ? 'Collapse all' : 'Expand all';
      expand.setAttribute('aria-expanded', anyClosed ? 'true' : 'false');
    });
  })();

  /* ---- Lesson player: sidebar module accordion + mobile toggle ---- */
  (function () {
    var groups = Array.prototype.slice.call(document.querySelectorAll('.ls-mod-group'));
    groups.forEach(function (g) {
      var head = g.querySelector('.ls-mod');
      if (!head) return;
      head.addEventListener('click', function () {
        var open = g.classList.toggle('is-open');
        head.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    });
    var toggle = document.getElementById('lsToggle');
    var side = document.getElementById('lessonSide');
    if (toggle && side) toggle.addEventListener('click', function () {
      var open = side.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  })();

  /* ---- Sign-in → the standalone /login page (no modal) ---- */
  function loginUrl(mode) {
    var u = '/login?next=' + encodeURIComponent(location.pathname + location.search);
    return mode === 'register' ? u + '&mode=register' : u;
  }
  function goLogin(mode) { window.location.href = loginUrl(mode); }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-auth]');
    if (t) { e.preventDefault(); goLogin(t.getAttribute('data-auth')); }
  });

  /* ---- Payments (Paystack) ---- */
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-pay]'); if (!t) return;
    e.preventDefault();
    var kind = t.getAttribute('data-pay');
    var course = t.getAttribute('data-course') || '';
    var card = t.closest('.pay-card, .gate') || document;
    var msg = card.querySelector('.enroll-msg');
    function note(text, ok) { if (!msg) { toast(text); return; } msg.hidden = false; msg.className = 'enroll-msg' + (ok ? ' ok' : ' err'); msg.textContent = text; }
    var label = t.textContent; t.disabled = true; t.textContent = 'Starting secure checkout…';
    api('pay_init', { method: 'POST', body: { kind: kind, course: course } })
      .then(function (d) {
        if (d && d.ok && d.authorization_url) { window.location.href = d.authorization_url; return; }
        if (d && d.ok && d.already) { note(d.message || 'You already have access.', true); setTimeout(function () { location.reload(); }, 900); return; }
        if (d && /sign in/i.test(d.error || '')) { goLogin('login'); return; }
        note((d && d.error) || 'Could not start the payment. Please try again.', false);
        t.disabled = false; t.textContent = label;
      })
      .catch(function () { note('Network error — please try again.', false); t.disabled = false; t.textContent = label; });
  });

  /* ---- Lead-capture application form (open courses with no lessons yet) ---- */
  (function () {
    var form = document.querySelector('.enroll-form[data-course]');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = (form.parentElement && form.parentElement.querySelector('.enroll-msg')) || form.querySelector('.enroll-msg');
      function note(text, ok) { if (!msg) { toast(text); return; } msg.hidden = false; msg.className = 'enroll-msg' + (ok ? ' ok' : ' err'); msg.textContent = text; }
      var btn = form.querySelector('button[type=submit]'); var label = btn ? btn.textContent : '';
      var body = { slug: form.getAttribute('data-course') };
      ['name', 'email', 'phone', 'note'].forEach(function (k) { var el = form.querySelector('[name="' + k + '"]'); if (el) body[k] = el.value; });
      if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }
      api('enroll', { method: 'POST', body: body })
        .then(function (d) {
          if (d && d.ok) { note(d.message || 'Application received — we will be in touch shortly.', true); form.reset(); }
          else note((d && d.error) || 'Please provide a valid name and email.', false);
        })
        .catch(function () { note('Network error — please try again.', false); })
        .finally(function () { if (btn) { btn.disabled = false; btn.textContent = label; } });
    });
  })();

  /* ---- Lesson: quiz ---- */
  var quizForm = document.getElementById('quizForm');
  if (quizForm) {
    quizForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var qs = quizForm.querySelectorAll('fieldset.quiz-q');
      var answers = []; var unanswered = false;
      qs.forEach(function (fs, i) {
        var sel = fs.querySelector('input[name="q' + i + '"]:checked');
        if (!sel) unanswered = true;
        answers.push(sel ? parseInt(sel.value, 10) : -1);
      });
      var out = quizForm.querySelector('.quiz-result');
      if (unanswered) { out.className = 'quiz-result err'; out.textContent = 'Please answer every question.'; return; }
      var btn = quizForm.querySelector('button[type=submit]'); btn.disabled = true;
      api('quiz_submit', { method: 'POST', body: { course: quizForm.getAttribute('data-course'), lesson: quizForm.getAttribute('data-lesson'), answers: answers } })
        .then(function (d) {
          if (!d.ok) { out.className = 'quiz-result err'; out.textContent = d.error || 'Please sign in.'; if (d.error && /sign in/i.test(d.error)) goLogin('login'); return; }
          out.className = 'quiz-result ' + (d.passed ? 'ok' : 'err');
          out.textContent = 'You scored ' + d.score + '%. ' + (d.passed ? 'Passed — lesson complete!' : 'You need ' + d.pass + '% to pass. Try again.');
          var st = document.getElementById('quizStatus'); if (st && d.passed) { st.textContent = '✓ Completed'; st.classList.add('done'); }
          var row = document.querySelector('.lesson-side a.lp.active'); if (row && d.passed) { row.classList.add('done'); var dot = row.querySelector('.dot'); if (dot) dot.textContent = '✓'; }
          if (d.progress) { updateSideProgress(d.progress); if (d.progress.complete) { revealDone(); toast('Course complete! 🎉 Claim your certificate.'); } else if (d.passed) { showUpNext(); } }
        }).catch(function () { out.className = 'quiz-result err'; out.textContent = 'Network error.'; })
        .finally(function () { btn.disabled = false; });
    });
  }

  /* ---- Lesson: progress bar sync (sidebar + collapsed toggle) ---- */
  function updateSideProgress(p) {
    var b = document.getElementById('sideBar'), pct = document.getElementById('sidePct');
    if (b) b.style.width = p.pct + '%'; if (pct) pct.textContent = p.pct + '%';
    var tp = document.querySelector('.ls-toggle-pct'); if (tp) tp.textContent = p.pct + '%';
    var note = document.querySelector('.ls-progress-note'); if (note && typeof p.completed === 'number') note.textContent = p.completed + ' of ' + p.total + ' lessons complete';
    // refresh the current module's "done" count in the sidebar
    var active = document.querySelector('.lesson-side a.lp.active');
    if (active) {
      var grp = active.closest('.ls-mod-group');
      if (grp) {
        var done = grp.querySelectorAll('a.lp.done').length;
        var totalL = grp.querySelectorAll('a.lp').length;
        var c = grp.querySelector('.ls-mod-count'); if (c) c.textContent = done + '/' + totalL;
      }
    }
  }

  /* ---- Lesson: mark complete (and continue) ---- */
  var lessonEl = document.querySelector('.lesson-main[data-lesson]');
  var btn = document.getElementById('completeBtn');
  if (lessonEl && btn) {
    btn.addEventListener('click', function () {
      var done = btn.getAttribute('data-done') === '1';
      var next = btn.getAttribute('data-next') || '';
      var act = done ? 'lesson_uncomplete' : 'lesson_complete';
      btn.disabled = true;
      api(act, { method: 'POST', body: { course: lessonEl.getAttribute('data-course'), lesson: lessonEl.getAttribute('data-lesson') } })
        .then(function (d) {
          if (!d.ok) { toast(d.error || 'Please sign in.'); if (d.error && /sign in/i.test(d.error)) goLogin('login'); return; }
          done = !done; btn.setAttribute('data-done', done ? '1' : '0');
          btn.textContent = done ? '✓ Completed' : ('Mark complete' + (next ? ' & continue' : ''));
          var row = document.querySelector('.lesson-side a.lp.active');
          if (row) { row.classList.toggle('done', done); var dot = row.querySelector('.dot'); if (dot) dot.textContent = done ? '✓' : ''; }
          if (d.progress) { updateSideProgress(d.progress); if (d.progress.complete) { revealDone(); toast('Course complete! 🎉'); } else { var cd = document.getElementById('courseDone'); if (cd) cd.hidden = true; } }
          // Coursera-style: just-completed → surface the "up next" card (which
          // counts down and advances), instead of an abrupt redirect.
          if (done && next) { if (!showUpNext()) setTimeout(function () { window.location.href = next; }, 500); }
          else { cancelUpNext(); }
        }).catch(function () { toast('Network error'); }).finally(function () { btn.disabled = false; });
    });
  }

  /* ---- Lesson: reading-progress bar (tracks scroll through the lesson) ---- */
  (function () {
    var bar = document.getElementById('readBar');
    var main = document.querySelector('.lesson-main');
    if (!bar || !main) return;
    function update() {
      var rect = main.getBoundingClientRect();
      var vh = window.innerHeight || document.documentElement.clientHeight;
      var total = rect.height - vh;
      var scrolled = -rect.top;
      var pct = total > 0 ? Math.max(0, Math.min(1, scrolled / total)) : (rect.bottom <= vh ? 1 : 0);
      bar.style.width = (pct * 100).toFixed(1) + '%';
    }
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update, { passive: true });
    update();
  })();

  /* ---- Lesson: personal notes ----
     Signed-in learners sync to their account (cross-device) via the API;
     anonymous readers fall back to on-device localStorage. */
  (function () {
    var panel = document.querySelector('.l-notes[data-notes-key]');
    if (!panel) return;
    var area = panel.querySelector('[data-notes-area]');
    var statusEl = panel.querySelector('[data-notes-status]');
    var dlBtn = panel.querySelector('[data-notes-download]');
    var key = panel.getAttribute('data-notes-key');
    var course = panel.getAttribute('data-notes-course') || 'course';
    var lessonSlug = panel.getAttribute('data-notes-lesson') || '';
    var lessonTitle = panel.getAttribute('data-lesson-title') || '';
    var remote = panel.getAttribute('data-notes-remote') === '1';
    var LS = window.localStorage;
    function get(k) { try { return LS.getItem(k); } catch (e) { return null; } }
    function set(k, v) { try { LS.setItem(k, v); } catch (e) {} }
    function flash(msg) { if (statusEl) { statusEl.textContent = msg; statusEl.classList.add('show'); clearTimeout(flash._t); flash._t = setTimeout(function () { statusEl.classList.remove('show'); }, 1600); } }
    // Local mode loads the saved value (remote mode is prefilled server-side).
    if (area && !remote) { var saved = get(key); if (saved != null) area.value = saved; }

    var t;
    if (area) area.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () {
        var v = area.value;
        if (remote) {
          flash('Saving…');
          api('note_save', { method: 'POST', body: { course: course, lesson: lessonSlug, body: v } })
            .then(function (d) { flash(d && d.ok ? 'Saved' : 'Not saved'); })
            .catch(function () { flash('Offline'); });
        } else {
          if (v.trim() === '') { try { LS.removeItem(key); } catch (e) {} } else { set(key, v); }
          flash('Saved');
        }
      }, remote ? 600 : 350);
    });

    function downloadMd(items) {
      if (!items.length) { toast('No notes saved yet — start typing to capture your first note.'); return; }
      var md = '# My notes — ' + course + '\n\n';
      items.forEach(function (it) { md += '## ' + it.title + '\n\n' + it.body.trim() + '\n\n'; });
      var blob = new Blob([md], { type: 'text/markdown' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = course + '-notes.md';
      document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
      flash('Downloaded');
    }
    if (dlBtn) dlBtn.addEventListener('click', function () {
      if (remote) {
        fetch('/academy/api.php?action=notes_all&course=' + encodeURIComponent(course), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d || !d.ok) { toast('Could not load your notes.'); return; }
            downloadMd((d.notes || []).map(function (n) { return { title: n.title, body: n.body }; }));
          })
          .catch(function () { toast('Network error — please try again.'); });
        return;
      }
      var prefix = 'av.notes.' + course + '.';
      var items = [];
      for (var i = 0; i < LS.length; i++) {
        var k = LS.key(i);
        if (k && k.indexOf(prefix) === 0) {
          var body = get(k) || '';
          if (body.trim() === '') continue;
          var s = k.slice(prefix.length);
          items.push({ slug: s, title: s === lessonSlug ? lessonTitle : s.replace(/-/g, ' '), body: body });
        }
      }
      items.sort(function (a, b) { return a.slug.localeCompare(b.slug); });
      downloadMd(items);
    });
  })();

  /* ---- Lesson: keyboard shortcuts (N/P next-prev, K complete, S contents) ---- */
  (function () {
    if (!document.body.classList.contains('learn-mode')) return;
    document.addEventListener('keydown', function (e) {
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      var tag = (e.target && e.target.tagName) || '';
      if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (e.target && e.target.isContentEditable)) return;
      var k = e.key.toLowerCase();
      if (k === 'n') { var nx = document.querySelector('.lesson-nav-next a'); if (nx) { e.preventDefault(); window.location.href = nx.href; } }
      else if (k === 'p') { var pv = document.querySelector('.lesson-nav-prev a'); if (pv) { e.preventDefault(); window.location.href = pv.href; } }
      else if (k === 'k') { var cb = document.getElementById('completeBtn'); if (cb) { e.preventDefault(); cb.click(); } }
      else if (k === 's') { var tg = document.getElementById('lsToggle'); var side = document.getElementById('lessonSide'); if (tg && getComputedStyle(tg).display !== 'none') { e.preventDefault(); tg.click(); } else if (side) { e.preventDefault(); side.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }
    });
  })();

  /* ---- Instructor roster: search + status filter + column sort ---- */
  (function () {
    var root = document.querySelector('[data-teach-roster]');
    if (!root) return;
    var table = root.querySelector('[data-roster-table]');
    var tbody = table && table.querySelector('tbody');
    if (!tbody) return;
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    var search = root.querySelector('[data-roster-search]');
    var chips = Array.prototype.slice.call(root.querySelectorAll('[data-roster-filter]'));
    var emptyEl = root.querySelector('[data-roster-empty]');
    var filter = 'all', query = '';

    function apply() {
      var shown = 0;
      rows.forEach(function (r) {
        var okF = filter === 'all' || r.getAttribute('data-status') === filter;
        var okQ = !query || (r.getAttribute('data-name') || '').indexOf(query) !== -1;
        var show = okF && okQ; r.hidden = !show; if (show) shown++;
      });
      if (emptyEl) emptyEl.hidden = shown !== 0;
    }
    if (search) { var t; search.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { query = search.value.trim().toLowerCase(); apply(); }, 110); }); }
    chips.forEach(function (c) {
      c.addEventListener('click', function () {
        filter = c.getAttribute('data-roster-filter') || 'all';
        chips.forEach(function (x) { var on = x === c; x.classList.toggle('active', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
        apply();
      });
    });

    var sortHeads = Array.prototype.slice.call(table.querySelectorAll('.th-sort[data-sort]'));
    function sortBy(key, dir) {
      var arr = rows.slice();
      arr.sort(function (a, b) {
        var av, bv, cmp;
        if (key === 'name') { av = a.getAttribute('data-name'); bv = b.getAttribute('data-name'); cmp = av.localeCompare(bv); }
        else { av = parseInt(a.getAttribute('data-' + key) || '0', 10); bv = parseInt(b.getAttribute('data-' + key) || '0', 10); cmp = av - bv; }
        return dir === 'asc' ? cmp : -cmp;
      });
      arr.forEach(function (r) { tbody.appendChild(r); });
    }
    sortHeads.forEach(function (th) {
      th.addEventListener('click', function () {
        var key = th.getAttribute('data-sort');
        var cur = th.getAttribute('aria-sort');
        var dir = cur === 'ascending' ? 'desc' : 'asc';
        sortHeads.forEach(function (h) { h.removeAttribute('aria-sort'); });
        th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
        sortBy(key, dir);
      });
    });
  })();
})();
