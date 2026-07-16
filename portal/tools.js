/* ============================================================
   portal/tools.js — Afrovanguard first-party productivity tools.
   Self-contained, offline, private-to-you (localStorage, keyed by
   user id). Notes · Focus timer · Habits · Countdown.
   ============================================================ */
(function () {
  'use strict';
  var root = document.getElementById('view-tools');
  if (!root) return;
  var uid = root.getAttribute('data-uid') || '0';
  var NS = 'av.tools.' + uid + '.';
  function load(k, d) { try { var v = localStorage.getItem(NS + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } }
  function save(k, v) { try { localStorage.setItem(NS + k, JSON.stringify(v)); } catch (e) {} }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function todayKey() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }

  /* ---- Notes ---- */
  (function () {
    var area = document.getElementById('tlNotesArea'), count = document.getElementById('tlNotesCount'),
        meta = document.getElementById('tlNotesMeta'), clear = document.getElementById('tlNotesClear');
    if (!area) return;
    area.value = load('notes', '') || '';
    function words(s) { s = String(s || '').trim(); return s ? s.split(/\s+/).length : 0; }
    function refresh() { var w = words(area.value); if (count) count.textContent = w + ' word' + (w === 1 ? '' : 's'); }
    refresh();
    var t;
    area.addEventListener('input', function () {
      refresh();
      clearTimeout(t); t = setTimeout(function () { save('notes', area.value); if (meta) { meta.textContent = 'Saved'; setTimeout(function () { meta.textContent = 'Autosaves'; }, 1200); } }, 350);
    });
    clear && clear.addEventListener('click', function () { if (!area.value || confirm('Clear this note?')) { area.value = ''; save('notes', ''); refresh(); area.focus(); } });
  })();

  /* ---- Focus timer (Pomodoro) ---- */
  (function () {
    var clock = document.getElementById('tlFocusClock'), startBtn = document.getElementById('tlFocusStart'),
        resetBtn = document.getElementById('tlFocusReset'), modeWrap = document.getElementById('tlFocusMode'),
        sessEl = document.getElementById('tlFocusSessions');
    if (!clock) return;
    var mins = 25, mode = 'Focus', remaining = mins * 60, running = false, tick = null;
    var sess = load('focus', { date: todayKey(), n: 0 });
    if (sess.date !== todayKey()) { sess = { date: todayKey(), n: 0 }; save('focus', sess); }
    if (sessEl) sessEl.textContent = sess.n;
    function fmt(s) { var m = Math.floor(s / 60), r = s % 60; return m + ':' + ('0' + r).slice(-2); }
    function paint() { clock.textContent = fmt(remaining); }
    function setMode(btn) {
      [].forEach.call(modeWrap.querySelectorAll('.fm-btn'), function (b) { b.classList.toggle('is-on', b === btn); });
      mins = +btn.getAttribute('data-min') || 25; mode = btn.getAttribute('data-mode') || 'Focus';
      stop(); remaining = mins * 60; paint();
    }
    function beep() {
      try {
        var Ac = window.AudioContext || window.webkitAudioContext; if (!Ac) return;
        var ac = new Ac(), o = ac.createOscillator(), g = ac.createGain();
        o.type = 'sine'; o.frequency.value = 660; o.connect(g); g.connect(ac.destination);
        g.gain.setValueAtTime(0.0001, ac.currentTime); g.gain.exponentialRampToValueAtTime(0.3, ac.currentTime + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + 0.9);
        o.start(); o.stop(ac.currentTime + 0.9);
      } catch (e) {}
    }
    function stop() { running = false; clearInterval(tick); tick = null; if (startBtn) startBtn.textContent = 'Start'; clock.classList.remove('is-running'); }
    function done() {
      stop(); remaining = 0; paint(); beep();
      if (mode === 'Focus') { sess.n++; save('focus', sess); if (sessEl) sessEl.textContent = sess.n; }
      try { if (window.Notification && Notification.permission === 'granted') new Notification('Afrovanguard Focus', { body: mode + ' complete.' }); } catch (e) {}
      setTimeout(function () { remaining = mins * 60; paint(); }, 1200);
    }
    function start() {
      if (running) { stop(); return; }
      if (remaining <= 0) remaining = mins * 60;
      running = true; startBtn.textContent = 'Pause'; clock.classList.add('is-running');
      try { if (window.Notification && Notification.permission === 'default') Notification.requestPermission(); } catch (e) {}
      tick = setInterval(function () { remaining--; if (remaining <= 0) { done(); return; } paint(); }, 1000);
    }
    modeWrap && modeWrap.addEventListener('click', function (e) { var b = e.target.closest('.fm-btn'); if (b) setMode(b); });
    startBtn && startBtn.addEventListener('click', start);
    resetBtn && resetBtn.addEventListener('click', function () { stop(); remaining = mins * 60; paint(); });
    paint();
  })();

  /* ---- Habits (daily, with streaks) ---- */
  (function () {
    var listEl = document.getElementById('tlHabitList'), form = document.getElementById('tlHabitAdd'),
        input = document.getElementById('tlHabitInput'), meta = document.getElementById('tlHabitsMeta');
    if (!listEl) return;
    var habits = load('habits', []);
    function streak(h) {
      var n = 0, d = new Date();
      for (;;) { var k = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
        if (h.days && h.days[k]) { n++; d.setDate(d.getDate() - 1); } else break; }
      return n;
    }
    function last7(h) {
      var out = '', d = new Date(); var arr = [];
      for (var i = 6; i >= 0; i--) { var t = new Date(); t.setDate(d.getDate() - i);
        var k = t.getFullYear() + '-' + ('0' + (t.getMonth() + 1)).slice(-2) + '-' + ('0' + t.getDate()).slice(-2);
        arr.push('<span class="hb-dot' + (h.days && h.days[k] ? ' on' : '') + '"></span>'); }
      return arr.join('');
    }
    function render() {
      var tk = todayKey();
      listEl.innerHTML = habits.length ? habits.map(function (h, i) {
        var on = h.days && h.days[tk], s = streak(h);
        return '<li class="habit' + (on ? ' is-done' : '') + '" data-i="' + i + '">'
          + '<button type="button" class="hb-check" aria-pressed="' + (on ? 'true' : 'false') + '">' + (on ? '✓' : '') + '</button>'
          + '<span class="hb-name">' + esc(h.name) + '</span>'
          + '<span class="hb-7">' + last7(h) + '</span>'
          + '<span class="hb-streak" title="Current streak">' + (s ? '🔥 ' + s : '—') + '</span>'
          + '<button type="button" class="hb-del" aria-label="Remove habit">✕</button></li>';
      }).join('') : '<li class="tool-empty">No habits yet — add one to start a streak.</li>';
      if (meta) { var doneN = habits.filter(function (h) { return h.days && h.days[tk]; }).length; meta.textContent = habits.length ? (doneN + '/' + habits.length + ' today') : ''; }
    }
    form && form.addEventListener('submit', function (e) {
      e.preventDefault(); var v = (input.value || '').trim(); if (!v) return;
      habits.push({ name: v, days: {} }); save('habits', habits); input.value = ''; render();
    });
    listEl.addEventListener('click', function (e) {
      var li = e.target.closest('.habit'); if (!li) return; var i = +li.getAttribute('data-i'); var h = habits[i]; if (!h) return;
      if (e.target.closest('.hb-check')) { var tk = todayKey(); h.days = h.days || {}; if (h.days[tk]) delete h.days[tk]; else h.days[tk] = 1; save('habits', habits); render(); }
      else if (e.target.closest('.hb-del')) { if (confirm('Remove “' + h.name + '”?')) { habits.splice(i, 1); save('habits', habits); render(); } }
    });
    render();
  })();

  /* ---- Countdown ---- */
  (function () {
    var setBox = document.getElementById('tlCdSet'), view = document.getElementById('tlCdView'),
        label = document.getElementById('tlCdLabel'), date = document.getElementById('tlCdDate'),
        saveBtn = document.getElementById('tlCdSave'), clearBtn = document.getElementById('tlCdClear'),
        num = document.getElementById('tlCdNum'), unit = document.getElementById('tlCdUnit'), show = document.getElementById('tlCdShow');
    if (!setBox) return;
    var cd = load('countdown', null);
    function refresh() {
      if (!cd || !cd.date) { setBox.hidden = false; view.hidden = true; return; }
      setBox.hidden = true; view.hidden = false;
      var target = new Date(cd.date + 'T00:00:00'); var now = new Date();
      var ms = target - now; var days = Math.floor(ms / 86400000);
      if (ms <= 0 && days > -1) { num.textContent = '🎉'; unit.textContent = 'today'; }
      else if (days < 0) { num.textContent = Math.abs(days); unit.textContent = 'days ago'; }
      else if (days === 0) { var hrs = Math.max(0, Math.floor(ms / 3600000)); num.textContent = hrs; unit.textContent = 'hours'; }
      else { num.textContent = days; unit.textContent = 'day' + (days === 1 ? '' : 's'); }
      show.textContent = cd.label || 'Your countdown';
    }
    saveBtn && saveBtn.addEventListener('click', function () {
      if (!date.value) { date.focus(); return; }
      cd = { label: (label.value || '').trim(), date: date.value }; save('countdown', cd); refresh();
    });
    clearBtn && clearBtn.addEventListener('click', function () { cd = null; save('countdown', null); label.value = ''; date.value = ''; refresh(); });
    refresh();
    setInterval(refresh, 60000);
  })();

  /* ---- Team Polls (enterprise, org-shared, DB-backed) ---- */
  (function () {
    var card = document.getElementById('tlPolls');
    if (!card) return;                       // org-only (server-gated)
    var csrf = card.getAttribute('data-csrf') || '';
    var form = document.getElementById('tlPollForm'), qEl = document.getElementById('tlPollQ'),
        optsWrap = document.getElementById('tlPollOpts'), addOpt = document.getElementById('tlPollAddOpt'),
        listEl = document.getElementById('tlPollList'), msg = document.getElementById('tlPollMsg');
    function post(action, b) {
      return fetch('/portal/polls.php?action=' + action, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(b || {}) }).then(function (r) { return r.json(); });
    }
    function say(t, k) { if (msg) { msg.textContent = t || ''; msg.className = 'poll-msg' + (k ? ' is-' + k : ''); } }

    function pollHTML(p) {
      var voted = p.my_vote >= 0, showResults = voted || p.closed;
      var opts = p.options.map(function (o, i) {
        var on = p.my_vote === i;
        if (showResults) {
          return '<button type="button" class="poll-o poll-o--res' + (on ? ' is-mine' : '') + '" data-poll="' + p.id + '" data-idx="' + i + '"' + (p.closed ? ' disabled' : '') + '>'
            + '<span class="poll-o-bar" style="width:' + o.pct + '%"></span>'
            + '<span class="poll-o-txt">' + esc(o.text) + (on ? ' ✓' : '') + '</span>'
            + '<span class="poll-o-pct">' + o.pct + '%</span></button>';
        }
        return '<button type="button" class="poll-o" data-poll="' + p.id + '" data-idx="' + i + '">' + esc(o.text) + '</button>';
      }).join('');
      var admin = p.mine ? '<button type="button" class="poll-x" data-del="' + p.id + '">Delete</button>'
        + (p.closed ? '' : '<button type="button" class="poll-close" data-close="' + p.id + '">Close</button>') : '';
      return '<article class="poll' + (p.closed ? ' is-closed' : '') + '" data-id="' + p.id + '">'
        + '<div class="poll-top"><h3 class="poll-question">' + esc(p.question) + '</h3>' + (p.closed ? '<span class="poll-tag">Closed</span>' : '') + '</div>'
        + '<div class="poll-o-list">' + opts + '</div>'
        + '<div class="poll-foot"><span>' + esc(p.author) + ' · ' + esc(p.ago) + ' · ' + p.total + ' vote' + (p.total === 1 ? '' : 's') + '</span><span class="poll-admin">' + admin + '</span></div>'
        + '</article>';
    }
    function render(polls) {
      listEl.innerHTML = polls && polls.length ? polls.map(pollHTML).join('')
        : '<p class="pc-empty">No polls yet — ask the team something above.</p>';
    }
    function load() { fetch('/portal/polls.php?action=list', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) { if (d && d.ok) render(d.polls); }).catch(function () {}); }

    addOpt && addOpt.addEventListener('click', function () {
      var n = optsWrap.querySelectorAll('.poll-opt').length;
      if (n >= 6) { say('Up to 6 options.', 'err'); return; }
      var i = document.createElement('input'); i.className = 'poll-opt'; i.placeholder = 'Option ' + (n + 1); i.maxLength = 120;
      optsWrap.appendChild(i); i.focus();
    });
    form && form.addEventListener('submit', function (e) {
      e.preventDefault();
      var q = (qEl.value || '').trim();
      var options = [].map.call(optsWrap.querySelectorAll('.poll-opt'), function (x) { return x.value.trim(); }).filter(Boolean);
      if (!q || options.length < 2) { say('Add a question and at least two options.', 'err'); return; }
      say('Creating…');
      post('create', { question: q, options: options }).then(function (d) {
        if (!d.ok) { say(d.error || 'Could not create.', 'err'); return; }
        qEl.value = ''; optsWrap.innerHTML = '<input class="poll-opt" placeholder="Option 1" maxlength="120"><input class="poll-opt" placeholder="Option 2" maxlength="120">';
        say('Poll posted.', 'ok'); setTimeout(function () { say(''); }, 2000); load();
      }).catch(function () { say('Network error.', 'err'); });
    });
    listEl.addEventListener('click', function (e) {
      var o = e.target.closest('[data-idx]');
      if (o) { post('vote', { id: +o.getAttribute('data-poll'), idx: +o.getAttribute('data-idx') }).then(function (d) { if (d.ok) load(); }); return; }
      var cl = e.target.closest('[data-close]');
      if (cl) { post('close', { id: +cl.getAttribute('data-close') }).then(function (d) { if (d.ok) load(); }); return; }
      var dl = e.target.closest('[data-del]');
      if (dl) { if (confirm('Delete this poll?')) post('delete', { id: +dl.getAttribute('data-del') }).then(function (d) { if (d.ok) load(); }); }
    });

    // Load when the Tools tab is opened (and on boot if already there).
    function maybeLoad() { if (!document.getElementById('view-tools').hidden) load(); }
    document.addEventListener('click', function (e) { if (e.target.closest('[data-view="tools"]')) setTimeout(maybeLoad, 80); });
    window.addEventListener('hashchange', function () { if (location.hash === '#tools') setTimeout(maybeLoad, 80); });
    maybeLoad();
  })();
})();
