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

  /* ---- App launcher: open one app at a time, with a back button ---- */
  (function () {
    var home = document.getElementById('suiteHome'), open = document.getElementById('suiteOpen'),
        stage = document.getElementById('suiteStage'), titleEl = document.getElementById('suiteOpenTitle'),
        back = document.getElementById('suiteBack');
    if (!home || !open || !stage) return;
    var NAMES = { cal: 'Calendar', notes: 'Notes', focus: 'Focus timer', habits: 'Habits', countdown: 'Countdown',
      rem: 'Reminders', board: 'Team board', polls: 'Team polls', standup: 'Daily standup', goals: 'Goals & OKRs', links: 'Team links' };
    var panels = stage.querySelectorAll('.suite-app');
    function toHome() {
      open.hidden = true; home.hidden = false;
      [].forEach.call(panels, function (p) { p.hidden = true; });
      try { window.scrollTo(0, 0); } catch (e) {}
    }
    function openApp(key) {
      var found = false;
      [].forEach.call(panels, function (p) { var m = p.getAttribute('data-app') === key; p.hidden = !m; if (m) found = true; });
      if (!found) return;
      titleEl.textContent = NAMES[key] || 'App';
      home.hidden = true; open.hidden = false;
      // Nudge the just-opened app to refresh its data, if it exposes a loader.
      if (window.__suiteRefresh && window.__suiteRefresh[key]) { try { window.__suiteRefresh[key](); } catch (e) {} }
      try { window.scrollTo(0, 0); } catch (e) {}
    }
    home.addEventListener('click', function (e) { var t = e.target.closest('[data-app]'); if (t) openApp(t.getAttribute('data-app')); });
    back.addEventListener('click', toHome);
    // Reset to the launcher only when the Suite NAV LINK is clicked (the whole
    // view carries data-view="tools", so scope to the sidebar link itself).
    document.addEventListener('click', function (e) { if (e.target.closest('a.pnav-link[data-view="tools"]')) setTimeout(toHome, 0); });
  })();

  // Apps register a refresh fn here so opening a tile re-pulls fresh data.
  window.__suiteRefresh = window.__suiteRefresh || {};

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
    window.__suiteRefresh['polls'] = load;
    maybeLoad();
  })();

  /* ---- Async daily standup (enterprise, org-shared, DB-backed) ---- */
  (function () {
    var card = document.getElementById('tlStandup');
    if (!card) return;                       // org-only (server-gated)
    var csrf = card.getAttribute('data-csrf') || '';
    var form = document.getElementById('tlSuForm'),
        doneEl = document.getElementById('tlSuDone'), nextEl = document.getElementById('tlSuNext'), blkEl = document.getElementById('tlSuBlk'),
        clearBtn = document.getElementById('tlSuClear'), boardEl = document.getElementById('tlSuBoard'), msg = document.getElementById('tlSuMsg');
    function post(action, b) {
      return fetch('/portal/standup.php?action=' + action, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(b || {}) }).then(function (r) { return r.json(); });
    }
    function say(t, k) { if (msg) { msg.textContent = t || ''; msg.className = 'poll-msg' + (k ? ' is-' + k : ''); } }

    function line(icon, cls, text) {
      return text ? '<p class="su-line ' + cls + '"><span class="su-ic">' + icon + '</span>' + esc(text).replace(/\n/g, '<br>') + '</p>' : '';
    }
    function entryHTML(s) {
      return '<article class="su-entry' + (s.mine ? ' is-mine' : '') + (s.blockers ? ' has-blk' : '') + '">'
        + '<div class="su-e-head"><b>' + esc(s.author) + (s.mine ? ' <span class="su-you">you</span>' : '') + '</b><span class="su-ago">' + esc(s.ago) + '</span></div>'
        + line('✅', 'su-done', s.done) + line('▶', 'su-next', s.next) + line('⛔', 'su-blk', s.blockers)
        + '</article>';
    }
    function render(board, mine) {
      boardEl.innerHTML = board && board.length ? board.map(entryHTML).join('')
        : '<p class="pc-empty">No check-ins yet today — be the first above.</p>';
      if (mine) {
        doneEl.value = mine.done || ''; nextEl.value = mine.next || ''; blkEl.value = mine.blockers || '';
        clearBtn.hidden = false; document.getElementById('tlSuSave').textContent = 'Update';
      } else { clearBtn.hidden = true; document.getElementById('tlSuSave').textContent = 'Post update'; }
    }
    function load() {
      fetch('/portal/standup.php?action=board', { credentials: 'same-origin' }).then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.ok) render(d.board, d.mine); }).catch(function () {});
    }

    form && form.addEventListener('submit', function (e) {
      e.preventDefault();
      var b = { done: doneEl.value.trim(), next: nextEl.value.trim(), blockers: blkEl.value.trim() };
      if (!b.done && !b.next && !b.blockers) { say('Add at least one field.', 'err'); return; }
      say('Posting…');
      post('post', b).then(function (d) {
        if (!d.ok) { say(d.error || 'Could not post.', 'err'); return; }
        say('Posted.', 'ok'); setTimeout(function () { say(''); }, 2000); load();
      }).catch(function () { say('Network error.', 'err'); });
    });
    clearBtn && clearBtn.addEventListener('click', function () {
      if (!confirm('Remove your standup for today?')) return;
      post('delete', {}).then(function (d) { if (d.ok) { doneEl.value = nextEl.value = blkEl.value = ''; say('Cleared.', 'ok'); setTimeout(function () { say(''); }, 2000); load(); } });
    });

    function maybeLoad() { if (!document.getElementById('view-tools').hidden) load(); }
    document.addEventListener('click', function (e) { if (e.target.closest('[data-view="tools"]')) setTimeout(maybeLoad, 90); });
    window.addEventListener('hashchange', function () { if (location.hash === '#tools') setTimeout(maybeLoad, 90); });
    window.__suiteRefresh['standup'] = load;
    maybeLoad();
  })();

  /* ---- shared helpers for the DB-backed suite apps ---- */
  function suitePost(url, action, csrf, b) {
    return fetch(url + '?action=' + action, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(b || {}) }).then(function (r) { return r.json(); });
  }
  function suiteGet(url, action) {
    return fetch(url + '?action=' + action, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function onToolsOpen(fn) {
    document.addEventListener('click', function (e) { if (e.target.closest('[data-view="tools"]')) setTimeout(function () { if (!document.getElementById('view-tools').hidden) fn(); }, 90); });
    window.addEventListener('hashchange', function () { if (location.hash === '#tools') setTimeout(function () { if (!document.getElementById('view-tools').hidden) fn(); }, 90); });
    if (!document.getElementById('view-tools').hidden) fn();
  }

  /* ---- Reminders (personal, DB-backed, syncs across devices) ---- */
  (function () {
    var card = document.getElementById('tlRem');
    if (!card) return;
    var csrf = card.getAttribute('data-csrf') || '';
    var form = document.getElementById('tlRemForm'), textEl = document.getElementById('tlRemText'),
        dueEl = document.getElementById('tlRemDue'), listEl = document.getElementById('tlRemList'),
        meta = document.getElementById('tlRemMeta'), msg = document.getElementById('tlRemMsg');
    function say(t, k) { if (msg) { msg.textContent = t || ''; msg.className = 'poll-msg' + (k ? ' is-' + k : ''); } }
    function itemHTML(r) {
      return '<li class="rem' + (r.done ? ' is-done' : '') + (r.overdue ? ' is-over' : '') + '" data-id="' + r.id + '">'
        + '<button type="button" class="rem-check" data-toggle="' + r.id + '" aria-label="Toggle done">' + (r.done ? '✓' : '') + '</button>'
        + '<span class="rem-body"><span class="rem-text">' + esc(r.text) + '</span>'
        + (r.due_label ? '<span class="rem-due-tag">' + esc(r.due_label) + '</span>' : '') + '</span>'
        + '<button type="button" class="rem-del" data-del="' + r.id + '" aria-label="Delete">✕</button></li>';
    }
    function render(items) {
      listEl.innerHTML = items && items.length ? items.map(itemHTML).join('')
        : '<li class="tool-empty">Nothing due — add a reminder above.</li>';
      if (meta) { var open = (items || []).filter(function (x) { return !x.done; }).length; meta.textContent = open ? open + ' open' : ''; }
    }
    function load() { suiteGet('/portal/reminders.php', 'list').then(function (d) { if (d && d.ok) render(d.reminders); }).catch(function () {}); }
    form && form.addEventListener('submit', function (e) {
      e.preventDefault();
      var t = (textEl.value || '').trim();
      if (!t) { say('Add a reminder.', 'err'); return; }
      suitePost('/portal/reminders.php', 'add', csrf, { text: t, due: dueEl.value || '' }).then(function (d) {
        if (!d.ok) { say(d.error || 'Could not add.', 'err'); return; }
        textEl.value = ''; dueEl.value = ''; say(''); load();
      }).catch(function () { say('Network error.', 'err'); });
    });
    listEl.addEventListener('click', function (e) {
      var tg = e.target.closest('[data-toggle]');
      if (tg) { suitePost('/portal/reminders.php', 'toggle', csrf, { id: +tg.getAttribute('data-toggle') }).then(function (d) { if (d.ok) load(); }); return; }
      var dl = e.target.closest('[data-del]');
      if (dl) { suitePost('/portal/reminders.php', 'delete', csrf, { id: +dl.getAttribute('data-del') }).then(function (d) { if (d.ok) load(); }); }
    });
    onToolsOpen(load); window.__suiteRefresh['rem'] = load;
  })();

  /* ---- Team board (Kanban, org-shared, DB-backed) ---- */
  (function () {
    var card = document.getElementById('tlBoard');
    if (!card) return;                       // org-only (server-gated)
    var csrf = card.getAttribute('data-csrf') || '';
    var wrap = document.getElementById('tlBoardCols');
    var COLS = [{ key: 'todo', label: 'To do' }, { key: 'doing', label: 'Doing' }, { key: 'done', label: 'Done' }];
    function cardHTML(c) {
      return '<li class="bcard" draggable="true" data-id="' + c.id + '">'
        + '<span class="bcard-txt">' + esc(c.title) + '</span>'
        + '<span class="bcard-by">' + esc(c.author) + '</span>'
        + '<button type="button" class="bcard-x" data-del="' + c.id + '" aria-label="Delete">✕</button></li>';
    }
    function colHTML(col) {
      return '<div class="bcol" data-col="' + col.key + '">'
        + '<div class="bcol-head"><span>' + esc(col.label) + '</span><span class="bcol-n">' + col.cards.length + '</span></div>'
        + '<ul class="bcol-list">' + col.cards.map(cardHTML).join('') + '</ul>'
        + '<form class="bcol-add" data-col="' + col.key + '"><input placeholder="+ Add card" maxlength="300"></form>'
        + '</div>';
    }
    function render(cols) { wrap.innerHTML = cols.map(colHTML).join(''); bindDnD(); }
    function load() { suiteGet('/portal/boards.php', 'board').then(function (d) { if (d && d.ok) render(d.cols); }).catch(function () {}); }

    wrap.addEventListener('submit', function (e) {
      var f = e.target.closest('.bcol-add'); if (!f) return;
      e.preventDefault();
      var inp = f.querySelector('input'), t = (inp.value || '').trim();
      if (!t) return;
      suitePost('/portal/boards.php', 'add', csrf, { title: t, col: f.getAttribute('data-col') }).then(function (d) { if (d.ok) load(); });
    });
    wrap.addEventListener('click', function (e) {
      var dl = e.target.closest('[data-del]');
      if (dl) { suitePost('/portal/boards.php', 'delete', csrf, { id: +dl.getAttribute('data-del') }).then(function (d) { if (d.ok) load(); }); }
    });
    function bindDnD() {
      var dragId = null;
      [].forEach.call(wrap.querySelectorAll('.bcard'), function (el) {
        el.addEventListener('dragstart', function () { dragId = el.getAttribute('data-id'); el.classList.add('is-drag'); });
        el.addEventListener('dragend', function () { el.classList.remove('is-drag'); });
      });
      [].forEach.call(wrap.querySelectorAll('.bcol'), function (col) {
        col.addEventListener('dragover', function (e) { e.preventDefault(); col.classList.add('is-over'); });
        col.addEventListener('dragleave', function () { col.classList.remove('is-over'); });
        col.addEventListener('drop', function (e) {
          e.preventDefault(); col.classList.remove('is-over');
          if (!dragId) return;
          suitePost('/portal/boards.php', 'move', csrf, { id: +dragId, col: col.getAttribute('data-col') }).then(function (d) { if (d.ok) load(); });
          dragId = null;
        });
      });
    }
    onToolsOpen(load); window.__suiteRefresh['board'] = load;
  })();

  /* ---- Goals & OKRs (org-shared, DB-backed) ---- */
  (function () {
    var card = document.getElementById('tlGoals');
    if (!card) return;                       // org-only (server-gated)
    var csrf = card.getAttribute('data-csrf') || '';
    var form = document.getElementById('tlGoalForm'), titleEl = document.getElementById('tlGoalTitle'),
        targetEl = document.getElementById('tlGoalTarget'), listEl = document.getElementById('tlGoalList'),
        msg = document.getElementById('tlGoalMsg');
    function say(t, k) { if (msg) { msg.textContent = t || ''; msg.className = 'poll-msg' + (k ? ' is-' + k : ''); } }
    function goalHTML(g) {
      var ctrl = g.mine && !g.closed
        ? '<div class="goal-ctrl"><button type="button" class="goal-step" data-dec="' + g.id + '">−10</button>'
          + '<button type="button" class="goal-step" data-inc="' + g.id + '">+10</button>'
          + '<button type="button" class="goal-x" data-del="' + g.id + '">Delete</button></div>'
        : (g.mine ? '<div class="goal-ctrl"><button type="button" class="goal-x" data-del="' + g.id + '">Delete</button></div>' : '');
      return '<article class="goal' + (g.closed ? ' is-done' : '') + '" data-id="' + g.id + '">'
        + '<div class="goal-top"><h3>' + esc(g.title) + '</h3>' + (g.closed ? '<span class="poll-tag">Done</span>' : '<span class="goal-pct">' + g.progress + '%</span>') + '</div>'
        + (g.target ? '<p class="goal-target">🎯 ' + esc(g.target) + '</p>' : '')
        + '<div class="goal-bar"><span style="width:' + g.progress + '%"></span></div>'
        + '<div class="goal-foot"><span>' + esc(g.author) + '</span>' + ctrl + '</div>'
        + '</article>';
    }
    function render(goals) {
      listEl.innerHTML = goals && goals.length ? goals.map(goalHTML).join('')
        : '<p class="pc-empty">No goals yet — set an objective above.</p>';
    }
    function load() { suiteGet('/portal/goals.php', 'list').then(function (d) { if (d && d.ok) render(d.goals); }).catch(function () {}); }
    function bump(id, delta) {
      var el = listEl.querySelector('.goal[data-id="' + id + '"] .goal-pct');
      var cur = el ? parseInt(el.textContent, 10) || 0 : 0;
      suitePost('/portal/goals.php', 'progress', csrf, { id: id, pct: Math.max(0, Math.min(100, cur + delta)) }).then(function (d) { if (d.ok) load(); });
    }
    form && form.addEventListener('submit', function (e) {
      e.preventDefault();
      var t = (titleEl.value || '').trim();
      if (!t) { say('Add an objective.', 'err'); return; }
      suitePost('/portal/goals.php', 'create', csrf, { title: t, target: targetEl.value || '' }).then(function (d) {
        if (!d.ok) { say(d.error || 'Could not add.', 'err'); return; }
        titleEl.value = ''; targetEl.value = ''; say(''); load();
      }).catch(function () { say('Network error.', 'err'); });
    });
    listEl.addEventListener('click', function (e) {
      var inc = e.target.closest('[data-inc]'); if (inc) { bump(+inc.getAttribute('data-inc'), 10); return; }
      var dec = e.target.closest('[data-dec]'); if (dec) { bump(+dec.getAttribute('data-dec'), -10); return; }
      var dl = e.target.closest('[data-del]'); if (dl) { if (confirm('Delete this goal?')) suitePost('/portal/goals.php', 'delete', csrf, { id: +dl.getAttribute('data-del') }).then(function (d) { if (d.ok) load(); }); }
    });
    onToolsOpen(load); window.__suiteRefresh['goals'] = load;
  })();

  /* ---- Team links (org-shared bookmark hub, DB-backed) ---- */
  (function () {
    var card = document.getElementById('tlLinks');
    if (!card) return;                       // org-only (server-gated)
    var csrf = card.getAttribute('data-csrf') || '';
    var form = document.getElementById('tlLinkForm'), urlEl = document.getElementById('tlLinkUrl'),
        titleEl = document.getElementById('tlLinkTitle'), noteEl = document.getElementById('tlLinkNote'),
        listEl = document.getElementById('tlLinkList'), msg = document.getElementById('tlLinkMsg');
    function say(t, k) { if (msg) { msg.textContent = t || ''; msg.className = 'poll-msg' + (k ? ' is-' + k : ''); } }
    function linkHTML(l) {
      return '<article class="tlink" data-id="' + l.id + '">'
        + '<a class="tlink-main" href="' + esc(l.url) + '" target="_blank" rel="noopener noreferrer">'
        + '<span class="tlink-title">' + esc(l.title) + '</span>'
        + '<span class="tlink-host">' + esc(l.host) + '</span></a>'
        + (l.note ? '<p class="tlink-note">' + esc(l.note) + '</p>' : '')
        + '<div class="tlink-foot"><span>' + esc(l.author) + '</span>'
        + (l.mine ? '<button type="button" class="goal-x" data-del="' + l.id + '">Remove</button>' : '') + '</div>'
        + '</article>';
    }
    function render(links) {
      listEl.innerHTML = links && links.length ? links.map(linkHTML).join('')
        : '<p class="pc-empty">No links yet — save a useful resource above.</p>';
    }
    function load() { suiteGet('/portal/bookmarks.php', 'list').then(function (d) { if (d && d.ok) render(d.links); }).catch(function () {}); }
    form && form.addEventListener('submit', function (e) {
      e.preventDefault();
      var url = (urlEl.value || '').trim();
      if (!url) { say('Paste a URL.', 'err'); return; }
      suitePost('/portal/bookmarks.php', 'add', csrf, { url: url, title: titleEl.value || '', note: noteEl.value || '' }).then(function (d) {
        if (!d.ok) { say(d.error || 'Could not save.', 'err'); return; }
        urlEl.value = titleEl.value = noteEl.value = ''; say(''); load();
      }).catch(function () { say('Network error.', 'err'); });
    });
    listEl.addEventListener('click', function (e) {
      var dl = e.target.closest('[data-del]');
      if (dl) { if (confirm('Remove this link?')) suitePost('/portal/bookmarks.php', 'delete', csrf, { id: +dl.getAttribute('data-del') }).then(function (d) { if (d.ok) load(); }); }
    });
    onToolsOpen(load); window.__suiteRefresh['links'] = load;
  })();

  /* ---- Integrated Calendar (team events + AFG + sessions + tasks + reminders) ---- */
  (function () {
    var card = document.getElementById('tlCal');
    if (!card) return;
    var csrf = card.getAttribute('data-csrf') || '', isOrg = card.getAttribute('data-org') === '1';
    var gridEl = document.getElementById('tlCalGrid'), monthEl = document.getElementById('tlCalMonth'),
        agendaEl = document.getElementById('tlCalAgenda'), agendaTitle = document.getElementById('tlCalAgendaTitle'),
        msg = document.getElementById('tlCalMsg');
    var MON = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var DOW = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(d) { return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }
    function utc(y, m, day) { return new Date(Date.UTC(y, m, day || 1)); }
    var now = new Date(), today = ymd(new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate())));
    var viewY = now.getUTCFullYear(), viewM = now.getUTCMonth();   // month shown
    var sel = today;                                              // selected day
    var byDate = {};                                             // 'Y-m-d' -> [items]

    function gridStart(y, m) { var d1 = utc(y, m, 1); var mi = (d1.getUTCDay() + 6) % 7; return utc(y, m, 1 - mi); }
    function say(t, k) { if (msg) { msg.textContent = t || ''; msg.className = 'poll-msg' + (k ? ' is-' + k : ''); } }

    function load() {
      var gs = gridStart(viewY, viewM), ge = new Date(gs.getTime() + 41 * 86400000);
      monthEl.textContent = MON[viewM] + ' ' + viewY;
      suiteGet('/portal/calendar.php', 'feed&from=' + ymd(gs) + '&to=' + ymd(ge)).then(function (d) {
        byDate = {};
        if (d && d.ok) (d.items || []).forEach(function (it) { (byDate[it.date] = byDate[it.date] || []).push(it); });
        renderGrid(gs); renderAgenda();
      }).catch(function () { gridEl.innerHTML = '<p class="pc-empty">Could not load the calendar.</p>'; });
    }

    function renderGrid(gs) {
      var html = '';
      for (var i = 0; i < 42; i++) {
        var d = new Date(gs.getTime() + i * 86400000), key = ymd(d), items = byDate[key] || [];
        var out = d.getUTCMonth() !== viewM;
        var pills = items.slice(0, 3).map(function (it) {
          return '<span class="cal-pill cal-pill--' + it.kind + (it.done ? ' is-done' : '') + '" title="' + esc(it.title) + '">'
            + (it.time ? '<b>' + esc(it.time) + '</b> ' : '') + esc(it.title) + '</span>';
        }).join('');
        var more = items.length > 3 ? '<span class="cal-more">+' + (items.length - 3) + '</span>' : '';
        html += '<button type="button" class="cal-cell' + (out ? ' is-out' : '') + (key === today ? ' is-today' : '')
          + (key === sel ? ' is-sel' : '') + (items.length ? ' has-ev' : '') + '" data-day="' + key + '">'
          + '<span class="cal-dnum">' + d.getUTCDate() + '</span>'
          + '<span class="cal-pills">' + pills + more + '</span></button>';
      }
      gridEl.innerHTML = html;
    }

    function humanDay(key) {
      if (key === today) return 'Today';
      var t = new Date(today + 'T00:00:00Z'), d = new Date(key + 'T00:00:00Z');
      var diff = Math.round((d - t) / 86400000);
      if (diff === 1) return 'Tomorrow';
      if (diff === -1) return 'Yesterday';
      return DOW[d.getUTCDay()] + ', ' + d.getUTCDate() + ' ' + MON[d.getUTCMonth()].slice(0, 3);
    }
    function itemHTML(it) {
      var meet = it.url ? '<a class="cal-ev-link" href="' + esc(it.url) + '" target="_blank" rel="noopener noreferrer">'
        + (it.kind === 'session' ? 'Join' : 'Open') + ' ↗</a>' : '';
      var del = it.can_delete ? '<button type="button" class="cal-ev-del" data-del="' + it.id + '" aria-label="Delete">✕</button>' : '';
      var meta = [];
      if (it.location) meta.push('📍 ' + esc(it.location));
      if (it.who && it.kind !== 'reminder') meta.push(esc(it.who));
      return '<div class="cal-ev cal-ev--' + it.kind + (it.done ? ' is-done' : '') + '">'
        + '<span class="cal-ev-when">' + (it.all_day || !it.time ? 'All day' : esc(it.time) + (it.end ? '–' + esc(it.end) : '')) + '</span>'
        + '<div class="cal-ev-body"><span class="cal-ev-title">' + esc(it.title) + '</span>'
        + (it.note ? '<span class="cal-ev-note">' + esc(it.note) + '</span>' : '')
        + (meta.length ? '<span class="cal-ev-meta">' + meta.join(' · ') + '</span>' : '')
        + '</div>' + meet + del + '</div>';
    }
    function renderAgenda() {
      agendaTitle.textContent = humanDay(sel);
      var items = byDate[sel] || [];
      agendaEl.innerHTML = items.length ? items.map(itemHTML).join('')
        : '<p class="pc-empty">Nothing scheduled.</p>';
    }

    function syncFormDate() { var di = document.getElementById('tlCalDate'); if (di) di.value = sel; }
    gridEl.addEventListener('click', function (e) {
      var cell = e.target.closest('[data-day]'); if (!cell) return;
      sel = cell.getAttribute('data-day'); syncFormDate();
      var d = new Date(sel + 'T00:00:00Z');
      if (d.getUTCMonth() !== viewM || d.getUTCFullYear() !== viewY) { viewY = d.getUTCFullYear(); viewM = d.getUTCMonth(); load(); }
      else { [].forEach.call(gridEl.querySelectorAll('.cal-cell'), function (c) { c.classList.toggle('is-sel', c.getAttribute('data-day') === sel); }); renderAgenda(); }
    });
    agendaEl.addEventListener('click', function (e) {
      var dl = e.target.closest('[data-del]');
      if (dl) { if (confirm('Delete this event?')) suitePost('/portal/calendar.php', 'delete', csrf, { id: +dl.getAttribute('data-del') }).then(function (d) { if (d.ok) load(); }); }
    });
    document.getElementById('tlCalPrev').addEventListener('click', function () { viewM--; if (viewM < 0) { viewM = 11; viewY--; } load(); });
    document.getElementById('tlCalNext').addEventListener('click', function () { viewM++; if (viewM > 11) { viewM = 0; viewY++; } load(); });
    document.getElementById('tlCalToday').addEventListener('click', function () { sel = today; viewY = now.getUTCFullYear(); viewM = now.getUTCMonth(); load(); });

    var form = document.getElementById('tlCalForm');
    form && form.addEventListener('submit', function (e) {
      e.preventDefault();
      var title = (document.getElementById('tlCalTitle').value || '').trim();
      var date = document.getElementById('tlCalDate').value || sel;
      if (!title) { say('Add a title.', 'err'); return; }
      if (!date) { say('Pick a date.', 'err'); return; }
      suitePost('/portal/calendar.php', 'create', csrf, {
        title: title, date: date,
        start: document.getElementById('tlCalStart').value || '', end: document.getElementById('tlCalEnd').value || '',
        location: document.getElementById('tlCalLoc').value || '', note: document.getElementById('tlCalNote').value || ''
      }).then(function (d) {
        if (!d.ok) { say(d.error || 'Could not add.', 'err'); return; }
        form.reset(); say('Event added.', 'ok'); setTimeout(function () { say(''); }, 2000);
        sel = date; var dd = new Date(date + 'T00:00:00Z'); viewY = dd.getUTCFullYear(); viewM = dd.getUTCMonth(); load();
      }).catch(function () { say('Network error.', 'err'); });
    });
    // Pre-fill the add-event date with the selected day when the tab opens.
    onToolsOpen(function () { var di = document.getElementById('tlCalDate'); if (di && !di.value) di.value = sel; load(); });
    window.__suiteRefresh['cal'] = load;
  })();
})();
