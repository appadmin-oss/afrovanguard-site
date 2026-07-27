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
    if (name === 'games') { renderGamesGrid(); gamesHome(); }
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
      '<div class="iq-score-ring" style="--p:' + (r.pct | 0) + '"><b>' + r.pct + '%</b></div>' +
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
  function shuffle(a) { for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; } return a; }
  function bestKey(id) { return 'iq.best.' + id; }
  function bestGet(id) { var v = null; try { v = localStorage.getItem(bestKey(id)); } catch (e) {} return v == null || v === '' ? null : +v; }
  function bestSet(id, val, lower) {
    var cur = bestGet(id); var improved = cur == null || (lower ? val < cur : val > cur);
    if (improved) { try { localStorage.setItem(bestKey(id), String(val)); } catch (e) {} }
    return { best: improved ? val : cur, improved: improved };
  }

  // The game catalogue. Each game declares how to play (rules), how its best
  // score is measured, and a play(stage, game) implementation.
  var GAMES = [
    { id: 'memory', icon: '🧠', badge: 'Memory', title: 'Integrity Memory', blurb: 'Match every hidden pair in the fewest moves.',
      rules: ['Eight pairs of symbols sit face-down on the board.', 'Flip two cards per turn — matching pairs stay revealed.', 'Clear the whole board in as few moves (and as little time) as you can.'],
      best: { lower: true, label: 'Fewest moves', fmt: function (v) { return v + ' moves'; } }, play: memoryGame },
    { id: 'scramble', icon: '🔤', badge: 'Vocabulary', title: 'Word Vanguard', blurb: 'Unscramble the values against a 40-second clock.',
      rules: ['Each round shows one scrambled Afrovanguard value.', 'Type the unscrambled word and press Enter.', 'Solve as many as possible before the 40 seconds run out.'],
      best: { lower: false, label: 'Best score', fmt: function (v) { return v + ' word' + (v === 1 ? '' : 's'); } }, play: scrambleGame },
    { id: 'sequence', icon: '🎯', badge: 'Working memory', title: 'Recall Ladder', blurb: 'Repeat the glowing pattern as it grows.',
      rules: ['Watch the pads flash in a sequence.', 'Repeat it by tapping the pads in the same order.', 'Each level adds one more step — climb as high as you can.'],
      best: { lower: false, label: 'Highest level', fmt: function (v) { return 'level ' + v; } }, play: sequenceGame },
    { id: 'reaction', icon: '⚡', badge: 'Focus', title: 'Sharp Focus', blurb: 'Tap the instant the panel turns green.',
      rules: ['Tap to arm each round, then wait — the panel starts amber.', 'The moment it turns green, tap as fast as you can.', 'Five rounds; we average your time. Tap early and you reset the round.'],
      best: { lower: true, label: 'Fastest average', fmt: function (v) { return v + ' ms'; } }, play: reactionGame },
    { id: 'math', icon: '➗', badge: 'Numeracy', title: 'Number Vanguard', blurb: 'Solve as many sums as you can in 60 seconds.',
      rules: ['A new arithmetic problem appears each turn.', 'Type the answer and press Enter — a correct streak earns bonus points.', 'Score as high as possible before the 60-second timer ends.'],
      best: { lower: false, label: 'High score', fmt: function (v) { return v + ' pts'; } }, play: mathGame },
  ];
  function gameById(id) { for (var i = 0; i < GAMES.length; i++) if (GAMES[i].id === id) return GAMES[i]; return null; }

  function renderGamesGrid() {
    var grid = el('iqGamesGrid'); if (!grid) return;
    var gc = el('iqStatGames'); if (gc) gc.textContent = GAMES.length; // keep hero stat in sync
    grid.innerHTML = GAMES.map(function (g) {
      var b = bestGet(g.id);
      var best = b != null ? '<div class="iq-card-meta"><span class="iq-tile-best">🏆 ' + esc(g.best.fmt(b)) + '</span></div>' : '';
      return '<button class="iq-card iq-game-tile" data-game="' + g.id + '">' +
        '<span class="iq-game-ico" aria-hidden="true">' + g.icon + '</span>' +
        '<span class="iq-card-badge">Game · ' + esc(g.badge) + '</span>' +
        '<h3>' + esc(g.title) + '</h3><p>' + esc(g.blurb) + '</p>' + best +
        '<span class="iq-card-cta">How to play <span aria-hidden="true">→</span></span></button>';
    }).join('');
    Array.prototype.forEach.call(grid.querySelectorAll('.iq-game-tile'), function (t) {
      t.addEventListener('click', function () { openGuide(t.getAttribute('data-game')); });
    });
  }
  function gamesHome() { var s = el('iqGameStage'); if (s) { s.hidden = true; s.innerHTML = ''; } var g = el('iqGamesGrid'); if (g) g.hidden = false; }
  function stageOpen() { var g = el('iqGamesGrid'); if (g) g.hidden = true; var s = el('iqGameStage'); s.hidden = false; return s; }

  /* Rules / guide screen shown before a game starts. */
  function openGuide(id) {
    var g = gameById(id); if (!g) return;
    var stage = stageOpen(); var b = bestGet(id);
    stage.innerHTML = '<div class="iq-game iq-guide">' +
      '<button class="iq-back" id="gGuideBack">‹ All games</button>' +
      '<div class="iq-guide-ico">' + g.icon + '</div>' +
      '<span class="iq-card-badge">Game · ' + esc(g.badge) + '</span>' +
      '<h2 class="iq-guide-title">' + esc(g.title) + '</h2>' +
      '<p class="iq-guide-blurb">' + esc(g.blurb) + '</p>' +
      '<h3 class="iq-guide-h">How to play</h3>' +
      '<ol class="iq-guide-rules">' + g.rules.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ol>' +
      (b != null ? '<div class="iq-guide-best">🏆 ' + esc(g.best.label) + ': <b>' + esc(g.best.fmt(b)) + '</b></div>' : '') +
      '<button class="iq-btn iq-btn-gold iq-guide-start" id="gStart">Start playing</button>' +
      '</div>';
    el('gGuideBack').addEventListener('click', gamesHome);
    el('gStart').addEventListener('click', function () { g.play(stageOpen(), g); });
  }

  /* Common game-over screen; records the best score and offers replay. */
  function finishGame(stage, g, score, summaryHTML) {
    var res = bestSet(g.id, score, g.best.lower);
    renderGamesGrid();
    stage.innerHTML = '<div class="iq-game iq-gover">' +
      '<div class="iq-gover-ic">' + (res.improved ? '🎉' : '🏁') + '</div>' +
      '<h2 class="iq-gover-title">' + (res.improved ? 'New personal best!' : 'Nice run') + '</h2>' +
      '<div class="iq-gover-score">' + summaryHTML + '</div>' +
      '<p class="iq-note">' + esc(g.best.label) + ': <b>' + esc(g.best.fmt(res.best)) + '</b></p>' +
      '<div class="iq-p-foot" style="justify-content:center;gap:12px">' +
      '<button class="iq-btn iq-btn-ghost" id="gRules">How to play</button>' +
      '<button class="iq-btn iq-btn-gold" id="gAgain">Play again</button></div>' +
      '<button class="iq-back" id="gHome" style="display:block;margin:16px auto 0">‹ All games</button>' +
      '</div>';
    el('gAgain').addEventListener('click', function () { g.play(stageOpen(), g); });
    el('gRules').addEventListener('click', function () { openGuide(g.id); });
    el('gHome').addEventListener('click', gamesHome);
  }
  function gameTop(g, meta) {
    return '<div class="iq-game-top"><button class="iq-back" id="gBack">‹ Games</button>' +
      '<span class="iq-game-title2">' + esc(g.title) + '</span>' +
      '<span id="gMeta" class="iq-game-meta">' + meta + '</span></div>';
  }

  /* --- Game 1: memory match --- */
  function memoryGame(stage, g) {
    var icons = ['🛡️', '⚡', '🌍', '📚', '🤝', '🎯', '🌱', '🏆'];
    var deck = shuffle(icons.concat(icons).slice());
    var flipped = [], matched = 0, moves = 0, secs = 0, timer;
    stage.innerHTML = '<div class="iq-game">' + gameTop(g, 'Moves: 0 · 0:00') + '<div class="iq-mem" id="gGrid"></div></div>';
    el('gBack').addEventListener('click', function () { clearInterval(timer); gamesHome(); });
    timer = setInterval(function () { secs++; el('gMeta').textContent = 'Moves: ' + moves + ' · ' + fmtTime(secs); }, 1000);
    var grid = el('gGrid');
    grid.innerHTML = deck.map(function (v, idx) { return '<button class="iq-mem-c" data-idx="' + idx + '"><span>' + v + '</span></button>'; }).join('');
    Array.prototype.forEach.call(grid.querySelectorAll('.iq-mem-c'), function (b) {
      b.addEventListener('click', function () {
        var idx = +b.getAttribute('data-idx');
        if (b.classList.contains('is-up') || b.classList.contains('is-done') || flipped.length === 2) return;
        b.classList.add('is-up'); flipped.push({ b: b, idx: idx });
        if (flipped.length === 2) {
          moves++;
          if (deck[flipped[0].idx] === deck[flipped[1].idx]) {
            flipped.forEach(function (f) { f.b.classList.add('is-done'); }); matched++; flipped = [];
            if (matched === icons.length) { clearInterval(timer); setTimeout(function () { finishGame(stage, g, moves, '<b>' + moves + '</b> moves · ' + fmtTime(secs)); }, 450); }
          } else { setTimeout(function () { flipped.forEach(function (f) { f.b.classList.remove('is-up'); }); flipped = []; }, 700); }
        }
      });
    });
  }

  /* --- Game 2: word scramble --- */
  function scrambleGame(stage, g) {
    var pool = shuffle(VALUES.slice()), score = 0, secs = 40, timer, answer = '';
    stage.innerHTML = '<div class="iq-game">' + gameTop(g, 'Score: 0 · 0:40') + '<div class="iq-scr" id="gScr"></div></div>';
    el('gBack').addEventListener('click', function () { clearInterval(timer); gamesHome(); });
    timer = setInterval(function () { secs--; el('gMeta').textContent = 'Score: ' + score + ' · ' + fmtTime(secs); if (secs <= 0) { clearInterval(timer); finishGame(stage, g, score, '<b>' + score + '</b> value' + (score === 1 ? '' : 's') + ' unscrambled'); } }, 1000);
    function scramble(w) { var s; do { s = shuffle(w.toUpperCase().split('')).join(''); } while (s === w.toUpperCase() && w.length > 1); return s; }
    function next() {
      if (!pool.length) pool = shuffle(VALUES.slice());
      answer = pool.pop();
      el('gScr').innerHTML = '<p class="iq-scr-word">' + scramble(answer) + '</p>' +
        '<input class="iq-scr-in" id="gIn" placeholder="Your answer" autocomplete="off" autocapitalize="characters">' +
        '<button class="iq-btn iq-btn-gold" id="gGo">Check</button><p class="iq-scr-msg" id="gMsg"></p>';
      el('gIn').focus(); el('gGo').addEventListener('click', check);
      el('gIn').addEventListener('keydown', function (e) { if (e.key === 'Enter') check(); });
    }
    function check() {
      var val = (el('gIn').value || '').trim().toUpperCase();
      if (val === answer.toUpperCase()) { score++; el('gMsg').textContent = '✓ Correct!'; setTimeout(next, 300); }
      else { el('gMsg').textContent = '✕ Try again'; }
    }
    next();
  }

  /* --- Game 3: sequence memory (Simon) --- */
  function sequenceGame(stage, g) {
    var PADS = 4, seq = [], input = [], level = 0, completed = 0, locked = true, timers = [];
    stage.innerHTML = '<div class="iq-game">' + gameTop(g, 'Level 1') +
      '<p class="iq-seq-status" id="gStatus">Watch closely…</p><div class="iq-seq" id="gPads"></div></div>';
    el('gBack').addEventListener('click', function () { timers.forEach(clearTimeout); gamesHome(); });
    var grid = el('gPads'), h = '';
    for (var i = 0; i < PADS; i++) h += '<button class="iq-pad iq-pad--' + i + '" data-p="' + i + '" aria-label="pad ' + (i + 1) + '"></button>';
    grid.innerHTML = h;
    var padEls = grid.querySelectorAll('.iq-pad');
    Array.prototype.forEach.call(padEls, function (b) { b.addEventListener('click', function () { if (!locked) press(+b.getAttribute('data-p')); }); });
    function lit(i, dur) { var p = padEls[i]; p.classList.add('is-lit'); timers.push(setTimeout(function () { p.classList.remove('is-lit'); }, dur)); }
    function nextRound() {
      level++; el('gMeta').textContent = 'Level ' + level; el('gStatus').textContent = 'Watch closely…';
      seq.push(Math.floor(Math.random() * PADS)); input = []; locked = true;
      var d = Math.max(240, 560 - level * 20), gap = d + 130;
      seq.forEach(function (p, k) { timers.push(setTimeout(function () { lit(p, d); if (k === seq.length - 1) { locked = false; el('gStatus').textContent = 'Your turn — repeat it'; } }, 500 + k * gap)); });
    }
    function press(p) {
      lit(p, 200); input.push(p); var i = input.length - 1;
      if (input[i] !== seq[i]) { locked = true; el('gStatus').textContent = '✕ Wrong — that ends the run'; setTimeout(function () { finishGame(stage, g, completed, 'You matched <b>' + completed + '</b> level' + (completed === 1 ? '' : 's')); }, 700); return; }
      if (input.length === seq.length) { completed = level; locked = true; el('gStatus').textContent = '✓ Nice!'; setTimeout(nextRound, 650); }
    }
    nextRound();
  }

  /* --- Game 4: reaction time --- */
  function reactionGame(stage, g) {
    var ROUNDS = 5, done = 0, times = [], state = 'idle', t0 = 0, to = null;
    stage.innerHTML = '<div class="iq-game">' + gameTop(g, 'Round 1 / ' + ROUNDS) +
      '<button class="iq-react is-idle" id="gPanel"><span id="gRT">Tap to begin</span></button>' +
      '<p class="iq-note" id="gRMsg" style="text-align:center;margin-top:10px"></p></div>';
    el('gBack').addEventListener('click', function () { clearTimeout(to); gamesHome(); });
    var panel = el('gPanel'), label = el('gRT');
    function arm() {
      state = 'wait'; panel.className = 'iq-react is-wait'; label.textContent = 'Wait for green…'; el('gRMsg').textContent = '';
      to = setTimeout(function () { state = 'go'; t0 = (window.performance || Date).now(); panel.className = 'iq-react is-go'; label.textContent = 'TAP!'; }, 900 + Math.random() * 2600);
    }
    panel.addEventListener('click', function () {
      if (state === 'idle' || state === 'result') { arm(); return; }
      if (state === 'wait') { clearTimeout(to); state = 'idle'; panel.className = 'iq-react is-early'; label.textContent = 'Too soon! Tap to retry'; return; }
      if (state === 'go') {
        var ms = Math.round((window.performance || Date).now() - t0); times.push(ms); done++;
        panel.className = 'iq-react is-done'; label.textContent = ms + ' ms';
        if (done >= ROUNDS) {
          var avg = Math.round(times.reduce(function (a, b) { return a + b; }, 0) / times.length);
          el('gMeta').textContent = 'Done';
          setTimeout(function () { finishGame(stage, g, avg, 'Average <b>' + avg + ' ms</b> over ' + ROUNDS + ' rounds'); }, 550);
        } else { state = 'result'; el('gMeta').textContent = 'Round ' + (done + 1) + ' / ' + ROUNDS; el('gRMsg').textContent = 'Tap to continue'; }
      }
    });
  }

  /* --- Game 5: mental-math sprint --- */
  function mathGame(stage, g) {
    var score = 0, streak = 0, secs = 60, timer, a = 0, b = 0, op = '+', ans = 0;
    stage.innerHTML = '<div class="iq-game">' + gameTop(g, '0 pts · 1:00') +
      '<div class="iq-math"><p class="iq-math-q" id="gQ"></p>' +
      '<input class="iq-scr-in" id="gIn" inputmode="numeric" autocomplete="off" placeholder="?">' +
      '<button class="iq-btn iq-btn-gold" id="gGo">Enter</button>' +
      '<p class="iq-math-streak" id="gStreak"></p></div></div>';
    el('gBack').addEventListener('click', function () { clearInterval(timer); gamesHome(); });
    timer = setInterval(function () { secs--; el('gMeta').textContent = score + ' pts · ' + fmtTime(secs); if (secs <= 0) { clearInterval(timer); finishGame(stage, g, score, '<b>' + score + '</b> points'); } }, 1000);
    function r(lo, hi) { return lo + Math.floor(Math.random() * (hi - lo + 1)); }
    function gen() {
      op = ['+', '−', '×'][Math.floor(Math.random() * 3)];
      if (op === '+') { a = r(2, 50); b = r(2, 50); ans = a + b; }
      else if (op === '−') { a = r(10, 60); b = r(1, a); ans = a - b; }
      else { a = r(2, 12); b = r(2, 12); ans = a * b; }
      el('gQ').textContent = a + ' ' + op + ' ' + b; el('gIn').value = ''; el('gIn').focus();
    }
    function check() {
      var v = parseInt(el('gIn').value, 10); if (isNaN(v)) return;
      if (v === ans) { streak++; var pts = 10 + Math.min(streak, 10); score += pts; el('gStreak').textContent = '🔥 Streak ' + streak + '  +' + pts; el('gStreak').className = 'iq-math-streak is-ok'; }
      else { streak = 0; el('gStreak').textContent = '✕ ' + a + ' ' + op + ' ' + b + ' = ' + ans; el('gStreak').className = 'iq-math-streak is-no'; }
      el('gMeta').textContent = score + ' pts · ' + fmtTime(secs); gen();
    }
    el('gGo').addEventListener('click', check);
    el('gIn').addEventListener('keydown', function (e) { if (e.key === 'Enter') check(); });
    gen();
  }

  /* ---------- Boot ---------- */
  function openEmbedQuiz() { openQuiz(root.getAttribute('data-quiz')); }
  if (EMBED) { openEmbedQuiz(); }
  else { loadList(); renderGamesGrid(); }
})();
