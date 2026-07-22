/* IQ/iq.js — Incorruptible Quiz: hub, quiz player, games, leaderboard.
 * Public. Boots into embed mode (single quiz) when data-embed="1". */
(function () {
  'use strict';
  var root = document.querySelector('.iq');
  if (!root) return;
  var CSRF = root.getAttribute('data-csrf') || '';
  var SIGNED = root.getAttribute('data-signed-in') === '1';
  var MY_NAME = root.getAttribute('data-name') || '';
  var EMBED = root.getAttribute('data-embed') === '1';
  var API = '/IQ/api.php';

  function api(action, opts) {
    opts = opts || {};
    var url = API + '?action=' + action + (opts.qs || '');
    var init = { credentials: 'same-origin' };
    if (opts.body) { init.method = 'POST'; init.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }; init.body = JSON.stringify(opts.body); }
    return fetch(url, init).then(function (r) { return r.json(); });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function el(id) { return document.getElementById(id); }
  function fmtTime(s) { s = Math.max(0, s | 0); var m = Math.floor(s / 60); return m + ':' + ('0' + (s % 60)).slice(-2); }

  /* ---------- Tabs ---------- */
  function showView(name) {
    ['iqQuizzes', 'iqPlayer', 'iqGames', 'iqLeaderboard'].forEach(function (v) { var n = el(v); if (n) n.hidden = true; });
    var map = { quizzes: 'iqQuizzes', games: 'iqGames', leaderboard: 'iqLeaderboard' };
    var target = el(map[name] || 'iqQuizzes'); if (target) target.hidden = false;
    Array.prototype.forEach.call(document.querySelectorAll('.iq-tab'), function (t) {
      if (t.getAttribute('data-tab')) t.classList.toggle('is-on', t.getAttribute('data-tab') === name);
    });
    if (name === 'leaderboard') loadGlobalBoard();
  }
  Array.prototype.forEach.call(document.querySelectorAll('.iq-tab[data-tab]'), function (t) {
    t.addEventListener('click', function () { showView(t.getAttribute('data-tab')); });
  });

  /* ---------- Quiz list ---------- */
  var CAT = '';
  function loadList() {
    api('list', { qs: CAT ? '&category=' + encodeURIComponent(CAT) : '' }).then(function (d) {
      if (!d || !d.ok) { el('iqList').innerHTML = '<p class="iq-empty">Could not load quizzes.</p>'; return; }
      renderFilter(d.categories);
      renderList(d.quizzes);
    }).catch(function () { el('iqList').innerHTML = '<p class="iq-empty">Could not load quizzes.</p>'; });
  }
  function renderFilter(cats) {
    var f = el('iqFilter'); if (!f) return;
    var h = '<button class="iq-chip' + (CAT === '' ? ' is-on' : '') + '" data-cat="">All</button>';
    (cats || []).forEach(function (c) { h += '<button class="iq-chip' + (CAT === c ? ' is-on' : '') + '" data-cat="' + esc(c) + '">' + esc(c) + '</button>'; });
    f.innerHTML = h;
    Array.prototype.forEach.call(f.querySelectorAll('.iq-chip'), function (b) {
      b.addEventListener('click', function () { CAT = b.getAttribute('data-cat'); loadList(); });
    });
  }
  function renderList(quizzes) {
    if (!quizzes || !quizzes.length) { el('iqList').innerHTML = '<p class="iq-empty">No quizzes yet — check back soon.</p>'; return; }
    el('iqList').innerHTML = quizzes.map(function (q) {
      var best = q.best_pct != null ? '<span class="iq-best">Best ' + q.best_pct + '%</span>' : '';
      var badge = q.type === 'profile'
        ? '<span class="iq-card-badge iq-diff--profile">Personality</span>'
        : '<span class="iq-card-badge iq-diff--' + esc(q.difficulty) + '">' + esc(q.difficulty_label) + '</span>';
      return '<button class="iq-card" data-slug="' + esc(q.slug) + '">' + badge +
        '<h3>' + esc(q.title) + '</h3>' +
        '<p>' + esc(q.description || '') + '</p>' +
        '<div class="iq-card-meta"><span>' + q.q_count + (q.type === 'profile' ? ' scenarios' : ' questions') + '</span><span>' + q.plays + ' plays</span>' + best + '</div>' +
        '<span class="iq-card-cta">Play →</span></button>';
    }).join('');
    Array.prototype.forEach.call(el('iqList').querySelectorAll('.iq-card'), function (c) {
      c.addEventListener('click', function () { openQuiz(c.getAttribute('data-slug')); });
    });
  }

  /* ---------- Player ---------- */
  var STATE = null;
  function openQuiz(slug) {
    api('quiz', { qs: '&slug=' + encodeURIComponent(slug) }).then(function (d) {
      if (!d || !d.ok) { alert((d && d.error) || 'Quiz not found.'); return; }
      startQuiz(d.quiz);
    });
  }
  function startQuiz(quiz) {
    STATE = { quiz: quiz, i: 0, answers: {}, started: Date.now(), timer: null, remaining: quiz.time_limit_sec || 0 };
    if (!EMBED) showView('quizzes');
    ['iqQuizzes', 'iqGames', 'iqLeaderboard'].forEach(function (v) { var n = el(v); if (n) n.hidden = true; });
    el('iqPlayer').hidden = false;
    renderQuestion();
    if (STATE.remaining > 0) tick();
  }
  function tick() {
    clearInterval(STATE.timer);
    STATE.timer = setInterval(function () {
      STATE.remaining--;
      var t = document.querySelector('.iq-timer'); if (t) t.textContent = '⏱ ' + fmtTime(STATE.remaining);
      if (STATE.remaining <= 0) { clearInterval(STATE.timer); submitQuiz(); }
    }, 1000);
  }
  function renderQuestion() {
    var q = STATE.quiz, cur = q.questions[STATE.i];
    var timer = STATE.remaining > 0 ? '<span class="iq-timer">⏱ ' + fmtTime(STATE.remaining) + '</span>' : '';
    var h = '<div class="iq-player">' +
      '<div class="iq-p-top"><button class="iq-back" id="iqQuit">‹ Exit</button>' +
      '<span class="iq-progress">Question ' + (STATE.i + 1) + ' / ' + q.questions.length + '</span>' + timer + '</div>' +
      '<div class="iq-bar"><span style="width:' + Math.round(100 * STATE.i / q.questions.length) + '%"></span></div>' +
      '<h2 class="iq-prompt">' + esc(cur.prompt) + '</h2>';
    if (cur.image_url) h += '<img class="iq-qimg" src="' + esc(cur.image_url) + '" alt="">';
    h += '<div class="iq-options">';
    cur.options.forEach(function (o) {
      var picked = STATE.answers[cur.id] === o.i ? ' is-picked' : '';
      h += '<button class="iq-opt' + picked + '" data-i="' + o.i + '">' + esc(o.text) + '</button>';
    });
    h += '</div><div class="iq-p-foot">' +
      (STATE.i > 0 ? '<button class="iq-btn iq-btn-ghost" id="iqPrev">Back</button>' : '<span></span>') +
      '<button class="iq-btn iq-btn-gold" id="iqNext">' + (STATE.i === q.questions.length - 1 ? 'Finish' : 'Next') + '</button>' +
      '</div></div>';
    el('iqPlayer').innerHTML = h;
    Array.prototype.forEach.call(el('iqPlayer').querySelectorAll('.iq-opt'), function (b) {
      b.addEventListener('click', function () {
        STATE.answers[cur.id] = parseInt(b.getAttribute('data-i'), 10);
        Array.prototype.forEach.call(el('iqPlayer').querySelectorAll('.iq-opt'), function (x) { x.classList.remove('is-picked'); });
        b.classList.add('is-picked');
      });
    });
    var quit = el('iqQuit'); quit && quit.addEventListener('click', exitPlayer);
    var prev = el('iqPrev'); prev && prev.addEventListener('click', function () { STATE.i--; renderQuestion(); });
    el('iqNext').addEventListener('click', function () {
      if (STATE.i === q.questions.length - 1) submitQuiz();
      else { STATE.i++; renderQuestion(); }
    });
  }
  function exitPlayer() {
    if (STATE && STATE.timer) clearInterval(STATE.timer);
    STATE = null;
    if (EMBED) { el('iqPlayer').innerHTML = ''; openEmbedQuiz(); return; }
    showView('quizzes');
  }
  function submitQuiz() {
    if (STATE.timer) clearInterval(STATE.timer);
    var dur = Math.round((Date.now() - STATE.started) / 1000);
    var isProfile = STATE.quiz.type === 'profile';
    var nm = MY_NAME;
    if (!SIGNED && !isProfile) { nm = (window.prompt('Enter a name for the leaderboard (optional):', '') || '').trim(); }
    api('submit', { body: { slug: STATE.quiz.slug, answers: STATE.answers, name: nm, duration: dur } }).then(function (r) {
      if (!r || !r.ok) { alert((r && r.error) || 'Could not score the quiz.'); return; }
      if (r.type === 'profile') renderProfileResult(r);
      else renderResult(r, dur);
    });
  }
  function renderProfileResult(r) {
    var q = STATE.quiz, p = r.profile || {}, t = r.tally || {};
    var keys = Object.keys(t).sort();
    var tally = keys.map(function (k) { return '<div class="iq-tally"><b>' + (t[k] | 0) + '</b><span>' + esc(k) + '</span></div>'; }).join('');
    var h = '<div class="iq-player iq-result">' +
      '<div class="iq-p-top"><button class="iq-back" id="iqQuit">‹ Exit</button></div>' +
      '<div class="iq-profile">' +
      '<span class="iq-ptag iq-ptag--' + esc(p.tag_class || 'b') + '">' + esc(p.tag || '') + '</span>' +
      '<h2 class="iq-ptitle">' + esc(p.title || 'Your profile') + '</h2>' +
      '<p class="iq-psub">Your grassroots profile, based on ' + q.questions.length + ' scenarios</p>' +
      (tally ? '<div class="iq-tally-row">' + tally + '</div>' : '') +
      (p.reality ? '<div class="iq-pblock"><h3>The reality</h3><p>' + esc(p.reality) + '</p></div>' : '') +
      (p.warning ? '<div class="iq-pblock"><h3>The warning</h3><p>' + esc(p.warning) + '</p></div>' : '') +
      (p.prescription ? '<div class="iq-pblock"><h3>Vanguard prescription</h3><p>' + esc(p.prescription) + '</p></div>' : '') +
      '<div class="iq-p-foot"><button class="iq-btn iq-btn-ghost" id="iqRetry">Retake</button>' +
      (EMBED ? '' : '<button class="iq-btn iq-btn-gold" id="iqMore">More on IQ</button>') + '</div>' +
      '<p class="iq-note" style="text-align:center;margin-top:14px">Put your best answers into practice — ' +
      '<a href="/donate" target="_blank" rel="noopener">donate</a> or ' +
      '<a href="https://next.afrovanguard.org.ng/volunteer" target="_blank" rel="noopener">volunteer</a> with Afrovanguard.</p>' +
      '</div></div>';
    el('iqPlayer').innerHTML = h;
    el('iqQuit').addEventListener('click', exitPlayer);
    el('iqRetry').addEventListener('click', function () { openQuiz(q.slug); });
    var more = el('iqMore'); more && more.addEventListener('click', function () { exitPlayer(); loadList(); });
  }
  function renderResult(r, dur) {
    var q = STATE.quiz;
    var badges = (r.badges || []).filter(function (b) { return b.earned; });
    var h = '<div class="iq-player iq-result">' +
      '<div class="iq-result-hero iq-' + (r.passed ? 'pass' : 'fail') + '">' +
      '<div class="iq-score-ring"><b>' + r.pct + '%</b></div>' +
      '<h2>' + (r.passed ? 'Well done!' : 'Keep going!') + '</h2>' +
      '<p>' + r.correct + ' / ' + r.total + ' correct · ' + r.score + ' pts · ' + fmtTime(dur) + ' · rank #' + r.rank + '</p>' +
      (SIGNED ? '' : '<p class="iq-note">Sign in to save scores and earn badges.</p>') +
      '</div>';
    if (badges.length) {
      h += '<div class="iq-badges">' + badges.map(function (b) { return '<span class="iq-badge" title="' + esc(b.label) + '">' + b.icon + ' ' + esc(b.label) + '</span>'; }).join('') + '</div>';
    }
    // Review
    h += '<div class="iq-review">';
    q.questions.forEach(function (qu) {
      var rv = (r.review || []).filter(function (x) { return x.id === qu.id; })[0] || {};
      var right = rv.right;
      var correctText = '';
      qu.options.forEach(function (o) { if (o.i === rv.correct_index) correctText = o.text; });
      h += '<div class="iq-rev-item iq-' + (right ? 'ok' : 'no') + '"><span class="iq-rev-ic">' + (right ? '✓' : '✕') + '</span>' +
        '<div><p class="iq-rev-q">' + esc(qu.prompt) + '</p>' +
        (right ? '' : '<p class="iq-rev-a">Answer: ' + esc(correctText) + '</p>') + '</div></div>';
    });
    h += '</div>';
    h += '<div class="iq-p-foot"><button class="iq-btn iq-btn-ghost" id="iqRetry">Try again</button>' +
      (EMBED ? '' : '<button class="iq-btn iq-btn-gold" id="iqMore">More quizzes</button>') + '</div></div>';
    el('iqPlayer').innerHTML = h;
    el('iqRetry').addEventListener('click', function () { openQuiz(q.slug); });
    var more = el('iqMore'); more && more.addEventListener('click', function () { exitPlayer(); loadList(); });
  }

  /* ---------- Leaderboard ---------- */
  function loadGlobalBoard() {
    api('leaderboard').then(function (d) {
      if (!d || !d.ok) { el('iqBoard').innerHTML = '<p class="iq-empty">Could not load.</p>'; return; }
      if (!d.board.length) { el('iqBoard').innerHTML = '<p class="iq-empty">No scores yet. Be the first!</p>'; return; }
      el('iqBoard').innerHTML = '<ol class="iq-lb">' + d.board.map(function (row) {
        return '<li class="iq-lb-row"><span class="iq-lb-rank">' + row.rank + '</span><span class="iq-lb-name">' + esc(row.name) + '</span>' +
          '<span class="iq-lb-pts">' + row.points + ' pts</span><span class="iq-lb-sub">' + row.quizzes + ' quizzes</span></li>';
      }).join('') + '</ol>';
    });
  }

  /* ---------- Games ---------- */
  var VALUES = ['Integrity', 'Courage', 'Service', 'Excellence', 'Discipline', 'Vision', 'Honour', 'Unity'];
  Array.prototype.forEach.call(document.querySelectorAll('.iq-game-tile'), function (t) {
    t.addEventListener('click', function () { launchGame(t.getAttribute('data-game')); });
  });
  function launchGame(kind) {
    var stage = el('iqGameStage'); if (!stage) return;
    document.querySelector('#iqGames .iq-grid').hidden = true;
    stage.hidden = false;
    if (kind === 'memory') memoryGame(stage);
    else scrambleGame(stage);
  }
  function closeGame() {
    var stage = el('iqGameStage'); stage.hidden = true; stage.innerHTML = '';
    document.querySelector('#iqGames .iq-grid').hidden = false;
  }
  function memoryGame(stage) {
    var icons = ['🛡️', '⚡', '🌍', '📚', '🤝', '🎯', '🌱', '🏆'];
    var deck = icons.concat(icons).map(function (v, i) { return { v: v, id: i }; });
    for (var i = deck.length - 1; i > 0; i--) { var j = Math.floor(((Date.now() >> i) ^ i * 2654435761) % (i + 1)); var t = deck[i]; deck[i] = deck[j]; deck[j] = t; }
    var flipped = [], matched = 0, moves = 0, secs = 0, timer;
    stage.innerHTML = '<div class="iq-game"><div class="iq-game-top"><button class="iq-back" id="gBack">‹ Games</button>' +
      '<span id="gMeta">Moves: 0 · 0:00</span></div><div class="iq-mem" id="gGrid"></div></div>';
    el('gBack').addEventListener('click', function () { clearInterval(timer); closeGame(); });
    timer = setInterval(function () { secs++; el('gMeta').textContent = 'Moves: ' + moves + ' · ' + fmtTime(secs); }, 1000);
    var grid = el('gGrid');
    grid.innerHTML = deck.map(function (c, idx) { return '<button class="iq-mem-c" data-idx="' + idx + '"><span>' + c.v + '</span></button>'; }).join('');
    Array.prototype.forEach.call(grid.querySelectorAll('.iq-mem-c'), function (b) {
      b.addEventListener('click', function () {
        var idx = +b.getAttribute('data-idx');
        if (b.classList.contains('is-up') || b.classList.contains('is-done') || flipped.length === 2) return;
        b.classList.add('is-up'); flipped.push({ b: b, idx: idx });
        if (flipped.length === 2) {
          moves++;
          if (deck[flipped[0].idx].v === deck[flipped[1].idx].v) {
            flipped.forEach(function (f) { f.b.classList.add('is-done'); }); matched++; flipped = [];
            if (matched === icons.length) { clearInterval(timer); el('gMeta').textContent = '🎉 Solved in ' + moves + ' moves · ' + fmtTime(secs); }
          } else {
            setTimeout(function () { flipped.forEach(function (f) { f.b.classList.remove('is-up'); }); flipped = []; }, 700);
          }
        }
      });
    });
  }
  function scrambleGame(stage) {
    var pool = VALUES.slice(); var round = 0, score = 0, secs = 40, timer, answer = '';
    stage.innerHTML = '<div class="iq-game"><div class="iq-game-top"><button class="iq-back" id="gBack">‹ Games</button>' +
      '<span id="gMeta">Score: 0 · 0:40</span></div><div class="iq-scr" id="gScr"></div></div>';
    el('gBack').addEventListener('click', function () { clearInterval(timer); closeGame(); });
    timer = setInterval(function () { secs--; el('gMeta').textContent = 'Score: ' + score + ' · ' + fmtTime(secs); if (secs <= 0) { clearInterval(timer); finish(); } }, 1000);
    function scramble(w) { var a = w.toUpperCase().split(''); for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(((Date.now() >> i) ^ (i + round) * 40503) % (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; } return a.join(''); }
    function next() {
      if (!pool.length) { clearInterval(timer); finish(); return; }
      answer = pool.splice(Math.floor((Date.now() >> round) % pool.length), 1)[0];
      el('gScr').innerHTML = '<p class="iq-scr-word">' + scramble(answer) + '</p>' +
        '<input class="iq-scr-in" id="gIn" placeholder="Your answer" autocomplete="off" autocapitalize="characters">' +
        '<button class="iq-btn iq-btn-gold" id="gGo">Check</button><p class="iq-scr-msg" id="gMsg"></p>';
      el('gIn').focus();
      el('gGo').addEventListener('click', check);
      el('gIn').addEventListener('keydown', function (e) { if (e.key === 'Enter') check(); });
    }
    function check() {
      var val = (el('gIn').value || '').trim().toUpperCase();
      if (val === answer.toUpperCase()) { score++; el('gMsg').textContent = '✓ Correct!'; round++; setTimeout(next, 350); }
      else { el('gMsg').textContent = '✕ Try again'; }
    }
    function finish() { el('gScr').innerHTML = '<p class="iq-scr-word">⏱ Time!</p><p>You unscrambled <b>' + score + '</b> value' + (score === 1 ? '' : 's') + '.</p><button class="iq-btn iq-btn-gold" id="gAgain">Play again</button>'; el('gAgain').addEventListener('click', function () { closeGame(); launchGame('scramble'); }); }
    next();
  }

  /* ---------- Boot ---------- */
  function openEmbedQuiz() { openQuiz(root.getAttribute('data-quiz')); }
  if (EMBED) { openEmbedQuiz(); }
  else { loadList(); }
})();
