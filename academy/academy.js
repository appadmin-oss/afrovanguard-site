/* ============================================================
   Afrovanguard Academy — learning flows (payments, quizzes,
   lesson progress) + sign-in redirect.

   Sign-in is now the standalone /login page (no modal), and the
   site-wide nav.js owns the account chip + logout. This file keeps
   the Academy-specific behaviour and sends any "please sign in"
   moment to /login with a ?next= back to the current page.
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

  /* ---- Scroll reveal (matches the Diary) ---- */
  var revealAll = function () { document.querySelectorAll('[data-reveal], .reveal-stagger').forEach(function (n) { n.classList.add('in'); }); };
  if ('IntersectionObserver' in window) {
    var rev = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); rev.unobserve(e.target); } });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
    document.querySelectorAll('[data-reveal], .reveal-stagger').forEach(function (n) { rev.observe(n); });
    setTimeout(revealAll, 3000); // safety: never leave content hidden
  } else { revealAll(); }

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
          var row = document.querySelector('.lesson-side a.lp.active'); if (row && d.passed) row.classList.add('done');
          if (d.progress) { var b = document.getElementById('sideBar'), p = document.getElementById('sidePct'); if (b) b.style.width = d.progress.pct + '%'; if (p) p.textContent = d.progress.pct + '%'; if (d.progress.complete) toast('Course complete! 🎉 Claim your certificate.'); }
        }).catch(function () { out.className = 'quiz-result err'; out.textContent = 'Network error.'; })
        .finally(function () { btn.disabled = false; });
    });
  }

  /* ---- Lesson: mark complete ---- */
  var lessonEl = document.querySelector('.lesson-main[data-lesson]');
  var btn = document.getElementById('completeBtn');
  if (lessonEl && btn) {
    btn.addEventListener('click', function () {
      var done = btn.getAttribute('data-done') === '1';
      var act = done ? 'lesson_uncomplete' : 'lesson_complete';
      btn.disabled = true;
      api(act, { method: 'POST', body: { course: lessonEl.getAttribute('data-course'), lesson: lessonEl.getAttribute('data-lesson') } })
        .then(function (d) {
          if (!d.ok) { toast(d.error || 'Please sign in.'); if (d.error && /sign in/i.test(d.error)) goLogin('login'); return; }
          done = !done; btn.setAttribute('data-done', done ? '1' : '0');
          btn.textContent = done ? '✓ Completed' : 'Mark complete';
          var row = document.querySelector('.lesson-side a.lp.active'); if (row) row.classList.toggle('done', done);
          if (d.progress) { var b = document.getElementById('sideBar'), p = document.getElementById('sidePct'); if (b) b.style.width = d.progress.pct + '%'; if (p) p.textContent = d.progress.pct + '%'; if (d.progress.complete) toast('Course complete! 🎉'); }
        }).catch(function () { toast('Network error'); }).finally(function () { btn.disabled = false; });
    });
  }
})();
