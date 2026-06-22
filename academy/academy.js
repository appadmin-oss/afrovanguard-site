/* ============================================================
   Afrovanguard Academy — accounts, auth modal, lesson progress.
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

  /* ---- Auth modal ---- */
  var modal;
  function buildModal() {
    modal = document.createElement('div'); modal.className = 'auth-modal';
    modal.innerHTML =
      '<div class="auth-card" role="dialog" aria-modal="true">' +
      '<h2 id="authTitle">Create your account</h2><p class="sub" id="authSub">Free to join. Track your learning across the Academy.</p>' +
      '<form id="authForm">' +
      '<input name="name" placeholder="Full name" autocomplete="name" />' +
      '<input name="email" type="email" placeholder="Email address" required autocomplete="email" />' +
      '<input name="password" type="password" placeholder="Password (min 8 characters)" required autocomplete="current-password" />' +
      '<button class="btn btn-primary" type="submit" id="authSubmit">Create account</button>' +
      '<p class="auth-msg" id="authMsg"></p></form>' +
      '<p class="auth-switch" id="authSwitch"></p></div>';
    document.body.appendChild(modal);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('open')) close(); });
    modal.querySelector('#authForm').addEventListener('submit', submit);
  }
  var mode = 'register';
  function open(m) { if (!modal) buildModal(); mode = m || 'register'; render(); modal.classList.add('open'); }
  function close() { if (modal) modal.classList.remove('open'); }
  function render() {
    var reg = mode === 'register';
    modal.querySelector('#authTitle').textContent = reg ? 'Create your account' : 'Welcome back';
    modal.querySelector('#authSub').textContent = reg ? 'Free to join. Track your learning across the Academy.' : 'Sign in to continue learning.';
    modal.querySelector('[name=name]').style.display = reg ? '' : 'none';
    modal.querySelector('[name=name]').required = reg;
    modal.querySelector('#authSubmit').textContent = reg ? 'Create account' : 'Sign in';
    modal.querySelector('[name=password]').setAttribute('autocomplete', reg ? 'new-password' : 'current-password');
    modal.querySelector('#authMsg').textContent = '';
    modal.querySelector('#authSwitch').innerHTML = reg
      ? 'Already have an account? <a id="toLogin">Sign in</a>'
      : 'New here? <a id="toReg">Create an account</a>';
    var tl = modal.querySelector('#toLogin'), tr = modal.querySelector('#toReg');
    if (tl) tl.onclick = function () { mode = 'login'; render(); };
    if (tr) tr.onclick = function () { mode = 'register'; render(); };
  }
  function submit(e) {
    e.preventDefault();
    var f = e.target, msg = f.querySelector('#authMsg');
    var payload = { email: f.email.value.trim(), password: f.password.value };
    if (mode === 'register') payload.name = f.name.value.trim();
    f.querySelector('#authSubmit').disabled = true;
    api(mode, { method: 'POST', body: payload }).then(function (d) {
      if (d.ok) { close(); renderAccount(d.user); toast('Welcome, ' + d.user.name.split(' ')[0] + '!'); setTimeout(function () { location.reload(); }, 600); }
      else { msg.className = 'auth-msg err'; msg.textContent = d.error || 'Something went wrong.'; }
    }).catch(function () { msg.className = 'auth-msg err'; msg.textContent = 'Network error.'; })
      .finally(function () { f.querySelector('#authSubmit').disabled = false; });
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-auth]'); if (t) { e.preventDefault(); open(t.getAttribute('data-auth')); }
    if (e.target.closest('[data-logout]')) { e.preventDefault(); api('logout', { method: 'POST', body: {} }).then(function () { location.reload(); }); }
  });

  /* ---- Account chip in the nav ---- */
  function renderAccount(user) {
    var actions = document.querySelector('.nav-actions'); if (!actions) return;
    var chip = document.getElementById('acctChip');
    if (!chip) { chip = document.createElement('div'); chip.id = 'acctChip'; chip.className = 'acct-chip'; actions.insertBefore(chip, actions.firstChild); }
    if (user) {
      var teach = (user.role === 'instructor' || user.role === 'admin')
        ? '<a href="/academy/teach/" class="nav-donate" style="padding:8px 10px">Teach</a> ' : '';
      chip.innerHTML = '<span style="color:#fff;font-size:13px;font-weight:600">' + user.name.split(' ')[0] + '</span> ' + teach + '<a href="#" data-logout class="nav-donate" style="padding:8px 10px">Sign out</a>';
    } else {
      chip.innerHTML = '<a href="#" data-auth="login" class="nav-donate">Sign in</a>';
    }
  }
  api('me').then(function (d) { renderAccount(d && d.ok ? d.user : null); });

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
        if (d && /sign in/i.test(d.error || '')) { open('login'); }
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
          if (!d.ok) { out.className = 'quiz-result err'; out.textContent = d.error || 'Please sign in.'; if (d.error && /sign in/i.test(d.error)) open('login'); return; }
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
          if (!d.ok) { toast(d.error || 'Please sign in.'); if (d.error && /sign in/i.test(d.error)) open('login'); return; }
          done = !done; btn.setAttribute('data-done', done ? '1' : '0');
          btn.textContent = done ? '✓ Completed' : 'Mark complete';
          var row = document.querySelector('.lesson-side a.lp.active'); if (row) row.classList.toggle('done', done);
          if (d.progress) { var b = document.getElementById('sideBar'), p = document.getElementById('sidePct'); if (b) b.style.width = d.progress.pct + '%'; if (p) p.textContent = d.progress.pct + '%'; if (d.progress.complete) toast('Course complete! 🎉'); }
        }).catch(function () { toast('Network error'); }).finally(function () { btn.disabled = false; });
    });
  }
})();
