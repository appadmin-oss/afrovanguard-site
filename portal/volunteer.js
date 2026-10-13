/* portal/volunteer.js — the Volunteer hub (portal/views/volunteer.php).
 *
 * Fills the hub from portal/volunteer.php?action=hub the first time the view
 * opens, and again whenever something changes. Runs two larger pieces:
 *
 *   · THE LIVE ROOM — polls the room every few seconds (shared hosting has no
 *     sockets, and a few seconds is live enough for people in a meeting):
 *     who is here and from where, the words as they are said, the check-in QR
 *     that changes every minute, the agenda and the clock. The room can be
 *     captioned from this browser with the Web Speech API (Chrome, Edge,
 *     Safari), each finished sentence sent to the server; and the secretary
 *     or chair can capture decisions and actions straight into the minutes.
 *   · THE MINUTES EDITOR — every section of the minutes as a form, reordered
 *     by dragging (SortableJS, self-hosted), saved as you type, and moved
 *     along draft → with the chair → published.
 *
 * Every write carries the CSRF token; every failure says what went wrong.
 */
(function () {
  'use strict';

  var view = document.getElementById('view-volunteer');
  if (!view) return;
  var CSRF = view.getAttribute('data-csrf') || '';
  var ME = +view.getAttribute('data-me') || 0;
  var API = '/portal/volunteer.php';
  var hub = null, loaded = false, busy = false;

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function api(action, body, q) {
    var url = API + '?action=' + encodeURIComponent(action) + (q ? '&' + q : '');
    var opt = { credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } };
    if (body) { opt.method = 'POST'; opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    return fetch(url, opt).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server sent something unreadable.' }; }); })
      .catch(function () { return { ok: false, error: 'No connection. Check your internet and try again.' }; });
  }
  function toast(t, bad) {
    var el = $('vhToast');
    if (!el) { el = document.createElement('div'); el.id = 'vhToast'; el.setAttribute('role', 'status'); el.className = 'vh-toast'; document.body.appendChild(el);
      el.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:9999;padding:10px 16px;border-radius:999px;font-weight:600;font-size:14px;box-shadow:0 8px 30px rgba(0,0,0,.2);max-width:90vw'; }
    el.textContent = t; el.style.background = bad ? '#c0322b' : '#15120e'; el.style.color = '#fff'; el.hidden = false;
    clearTimeout(el._t); el._t = setTimeout(function () { el.hidden = true; }, 3500);
  }
  function initials(n) { return (String(n || '').replace(/[^\p{L}\s]/gu, '').split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w[0]; }).join('') || '?').toUpperCase(); }
  function day(iso) { var d = new Date(iso + 'T12:00:00'); return isNaN(d) ? iso : d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }); }
  function row(title, sub, btns, extra) {
    return '<li class="vh-row"' + (extra || '') + '><span class="vh-row-t"><span class="vh-row-title">' + title + '</span>' + (sub ? '<span class="vh-row-sub">' + sub + '</span>' : '') + '</span><span class="vh-row-btns">' + (btns || '') + '</span></li>';
  }
  function closeOn(dlg) { dlg.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { dlg.close(); }); }); }
  ['vhRoom', 'vhMin', 'vhForm', 'vhQrBigD'].forEach(function (id) { if ($(id)) closeOn($(id)); });

  /* ══ The hub ══════════════════════════════════════════════════════════ */

  var tab = 'overview', mscope = 'upcoming', mstatus = '', ascope = 'mine', openTeamId = 0, chartTable = false;
  try { tab = localStorage.getItem('vh.tab') || 'overview'; } catch (e) {}

  function load() {
    if (busy) return; busy = true;
    api('hub').then(function (r) {
      busy = false;
      if (!r.ok) { $('vhNeed').innerHTML = '<li class="avp-empty">' + esc(r.error || 'Could not load the hub.') + '</li>'; return; }
      hub = r; loaded = true; draw();
    });
  }
  function teamsAll() { return hub.me.can_run_teams ? hub.all_teams : hub.teams; }
  function fillTeams(sel, withAll) {
    var el = $(sel), keep = el.value;
    el.innerHTML = (withAll ? '<option value="0">All my teams</option>' : '') + teamsAll().map(function (t) { return '<option value="' + t.id + '">' + esc(t.name) + '</option>'; }).join('');
    if (keep && el.querySelector('option[value="' + keep + '"]')) el.value = keep;
  }
  function rel(ts) {
    var s = ts * 1000 - Date.now(), m = Math.round(s / 60000);
    if (m <= 0) return 'now';
    if (m < 60) return 'in ' + m + ' min';
    if (m < 1440) return 'in ' + Math.round(m / 60) + ' h';
    var d = Math.round(m / 1440); return d === 1 ? 'tomorrow' : 'in ' + d + ' days';
  }

  function setTab(t, focus) {
    tab = t; try { localStorage.setItem('vh.tab', t); } catch (e) {}
    view.querySelectorAll('[role=tab]').forEach(function (b) {
      var on = b.getAttribute('data-tab') === t;
      b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1;
      if (on && focus) b.focus();
    });
    view.querySelectorAll('.vh-panel').forEach(function (p) { p.hidden = p.id !== 'vp-' + t; });
    /* The welcome is the overview's; the other tabs get straight to work. */
    view.querySelector('.vh-hero').hidden = t !== 'overview';
    $('vhBadges').hidden = t !== 'overview';
    if (!hub) return;
    if (t === 'meetings') drawMeetings();
    if (t === 'minutes') drawMinutesTab();
    if (t === 'actions') drawActions();
    if (t === 'teams') drawTeams();
    if (t === 'attendance') drawAttendance();
    if (t === 'hours') drawHours();
  }
  var tabs = view.querySelector('.vh-tabs');
  tabs.addEventListener('click', function (ev) { var b = ev.target.closest('[role=tab]'); if (b) setTab(b.getAttribute('data-tab')); });
  tabs.addEventListener('keydown', function (ev) {
    if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(ev.key) < 0) return;
    var list = Array.prototype.slice.call(tabs.querySelectorAll('[role=tab]')), i = list.findIndex(function (b) { return b.getAttribute('aria-selected') === 'true'; });
    i = ev.key === 'Home' ? 0 : ev.key === 'End' ? list.length - 1 : (i + (ev.key === 'ArrowRight' ? 1 : -1) + list.length) % list.length;
    ev.preventDefault(); setTab(list[i].getAttribute('data-tab'), true);
  });

  function draw() {
    var c = hub.counts, waiting = c.rsvp + c.minutes;
    $('vhSchedule').hidden = !hub.teams.length && !hub.me.can_run_teams;
    $('vhNewTeam').hidden = !hub.me.can_run_teams;
    ['vtnMeet', 'vtnMin', 'vtnAct'].forEach(function (id, i) { var n = [c.rsvp, c.minutes, c.overdue][i]; $(id).hidden = !n; $(id).textContent = n; });
    fillTeams('vhMTeam', true); fillTeams('vhNTeam', true); fillTeams('vhATeam', false);
    var lead = teamsAll().filter(function (t) { return t.can_manage; });
    $('vhATeamBtn').hidden = !lead.length && !hub.me.can_run_teams;

    var live = hub.upcoming.filter(function (m) { return m.room; });
    $('vhLive').hidden = !live.length;
    $('vhLive').innerHTML = live.map(function (m) {
      return '<div class="vh-live"><span class="vh-pulse" aria-hidden="true"></span><span class="vh-live-t"><b>' + esc(m.title) + '</b><br>' + (m.live ? 'On now' : 'Starting ' + esc(rel(m.ts))) + (m.team ? ' · ' + esc(m.team) : '') + '</span>'
        + (m.meet_url ? '<a class="avp-btn avp-btn--paper" href="' + esc(m.meet_url) + '" target="_blank" rel="noopener">Join the call</a>' : '')
        + '<button type="button" class="avp-btn avp-btn--paper" data-room="' + m.id + '" data-occ="' + esc(m.occurrence) + '">Open the live room</button></div>';
    }).join('');

    drawHero();

    /* KPIs */
    var next = hub.upcoming.filter(function (m) { return !m.live; })[0];
    $('vhKNext').textContent = next ? rel(next.ts) : '—';
    $('vhKNextS').textContent = next ? next.title : 'nothing scheduled';
    $('vhKWait').textContent = waiting; $('vhKWaitChip').hidden = !waiting;
    $('vhKWaitS').textContent = waiting ? [c.rsvp ? c.rsvp + ' to answer' : '', c.minutes ? c.minutes + ' minutes' : ''].filter(Boolean).join(' · ') : 'all caught up';
    $('vhKAct').textContent = c.actions; $('vhKOverChip').hidden = !c.overdue; $('vhKActS').textContent = c.overdue ? c.overdue + ' overdue' : (c.actions ? 'none overdue' : 'nothing open');
    var a = hub.attendance;
    var gt = hub.engagement.gate;
    if (gt && gt.counted) { $('vhKAtt').textContent = gt.rate + '%'; }
    else $('vhKAtt').textContent = a.rate === null ? '—' : a.rate + '%';
    if (gt && gt.counted) $('vhKAttS').textContent = 'at the gate · ' + gt.punctuality + '% on time · last 30 days';
    else $('vhKAttS').textContent = a.meetings ? (a.present + a.late) + ' of ' + (a.present + a.late + a.absent) + ' meeting' + (a.present + a.late + a.absent === 1 ? '' : 's') + ' · last six months' : 'no minutes published yet';
    $('vhKHours').textContent = hub.hours.month;
    $('vhKSpark').innerHTML = spark(hub.hours_series.slice(-6));

    /* Needs you: one list of everything waiting on this person, most urgent first */
    var need = [];
    hub.to_approve.forEach(function (m) { need.push({ w: 0, html: row(esc(m.title), 'Minutes of ' + esc(m.when) + ' — waiting for you to approve', '<button type="button" class="avp-btn avp-btn--sq avp-btn--ink" data-minutes="' + m.id + '" data-occ="' + esc(m.occurrence) + '">Read and approve</button>') }); });
    hub.to_write.forEach(function (m) { need.push({ w: 1, html: row(esc(m.title), 'Minutes of ' + esc(m.when) + ' — ' + (m.minutes_id ? 'a draft is waiting' : 'not started'), '<button type="button" class="avp-btn avp-btn--sq" data-minutes="' + m.id + '" data-occ="' + esc(m.occurrence) + '">' + (m.minutes_id ? 'Continue' : 'Write them') + '</button>') }); });
    hub.actions.filter(function (x) { return x.overdue; }).forEach(function (x) { need.push({ w: 2, html: row(esc(x.title), '<span class="is-overdue">Overdue — was due ' + esc(day(x.due)) + '</span>' + (x.from ? ' · ' + esc(x.from) : ''), '<button type="button" class="avp-btn avp-btn--sq" data-done="' + x.id + '">Done</button>') }); });
    hub.upcoming.filter(function (m) { return !m.rsvp && !m.live; }).forEach(function (m) { need.push({ w: 3, html: row(esc(m.title), esc(m.when) + ' · are you coming?', seg(m)) }); });
    need.sort(function (x, y) { return x.w - y.w; });
    $('vhNeedN').hidden = !need.length; $('vhNeedN').textContent = need.length;
    $('vhNeed').innerHTML = need.length ? need.map(function (n) { return n.html; }).join('') : '<li class="avp-empty">Nothing needs you. Well done.</li>';

    /* The next two weeks: a strip of days, then the meetings in them */
    var days = [], today = new Date(); today.setHours(0, 0, 0, 0);
    for (var i = 0; i < 14; i++) { var d = new Date(today.getTime() + i * 86400000); days.push({ d: d, n: 0 }); }
    var soon = hub.upcoming.filter(function (m) { return m.ts * 1000 < today.getTime() + 14 * 86400000; });
    soon.forEach(function (m) { var i = Math.floor((m.ts * 1000 - today.getTime()) / 86400000); if (days[i]) days[i].n++; });
    $('vhWeek').innerHTML = days.map(function (x, i) {
      return '<span class="vh-day' + (i === 0 ? ' is-today' : '') + (x.n ? ' has' : '') + '" title="' + esc(x.d.toDateString()) + (x.n ? ' — ' + x.n + ' meeting' + (x.n === 1 ? '' : 's') : '') + '"><span>' + x.d.toLocaleDateString(undefined, { weekday: 'narrow' }) + '</span><b>' + x.d.getDate() + '</b><i aria-hidden="true">' + (x.n ? '•'.repeat(Math.min(3, x.n)) : '') + '</i></span>';
    }).join('');
    $('vhSoon').innerHTML = soon.length ? soon.slice(0, 5).map(meetRow).join('') : '<li class="avp-empty">No meetings in the next two weeks.</li>';

    /* Activity */
    $('vhFeed').innerHTML = hub.activity.length ? hub.activity.map(function (e) {
      return '<li><span class="vh-feed-i vh-feed-i--' + esc(e.kind) + '" aria-hidden="true">' + ({ minutes: '≡', done: '✓', joined: '+' }[e.kind] || '•') + '</span><span><span>' + esc(e.text) + '</span><small>' + esc(ago(e.at)) + (e.team ? ' · ' + esc(e.team) : '') + '</small></span></li>';
    }).join('') : '<li class="avp-empty">Nothing yet. When minutes are published or actions done, it shows here.</li>';

    /* Capture: what this site has switched on — everything works with none */
    var g = hub.integrations, caps = [
      ['Answers, reminders and calendar invites', true, 'always on'],
      ['QR check-in in the room', true, 'always on'],
      ['Room captions from a browser', !!(window.SpeechRecognition || window.webkitSpeechRecognition), 'needs Chrome, Edge or Safari'],
      ['Who joined the Google Meet', g.google_meet, 'needs Google Workspace in the Studio'],
      ['The notetaker (Attendee)', g.notetaker, 'optional — not set up'],
      ['Notes tidied by the assistant', g.assistant, 'optional — AI is off'],
    ];
    $('vhCaps').innerHTML = '<ul class="vh-caps-l">' + caps.map(function (x) { return '<li class="' + (x[1] ? 'on' : 'off') + '"><span aria-hidden="true">' + (x[1] ? '✓' : '–') + '</span><span>' + esc(x[0]) + (x[1] ? '' : '<small>' + esc(x[2]) + '</small>') + '</span></li>'; }).join('') + '</ul>';

    setTab(tab);
  }

  /* ── The hero: why come back today ── */
  var LINES = [
    'Every hour you give moves Afrovanguard forward.',
    'Small, steady acts build big things.',
    'Thank you for showing up — it shows.',
    'Your team is better because you are in it.',
    'Today is a good day to make a difference.'
  ];
  function drawHero() {
    var e = hub.engagement, now = new Date(), h = now.getHours();
    var first = (hub.me.name || '').split(/\s+/)[0] || 'there';
    $('vhH').textContent = (h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening') + ', ' + first + '.';
    $('vhDate').textContent = now.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
    var line = LINES[(now.getDate() + hub.me.id) % LINES.length];
    if (e.streak >= 2) line = 'You have given time ' + e.streak + ' weeks in a row. Keep the run going!';
    if (e.next && e.next.pct >= 75) line = 'Almost there — ' + Math.round(e.next.pct) + '% of the way to “' + e.next.label + '”.';
    var me = (e.birthdays || []).filter(function (b) { return b.me && b.in === 0; })[0];
    if (me) line = 'Happy birthday from all of us at Afrovanguard! 🎉';
    $('vhLine').textContent = line;

    var chips = [];
    chips.push('<span class="vh-chip vh-chip--flame" title="Weeks in a row with something given">' + (e.streak ? '🔥 ' + e.streak + '-week streak' : '🔥 Start a streak this week') + '</span>');
    if (e.gate) {
      var t = e.gate.today;
      chips.push(t ? '<span class="vh-chip vh-chip--' + (t.status === 'late' ? 'gold' : 'green') + '">' + (t.status === 'late' ? '🕘 In late today' : '✓ In today') + (t.in_at ? ' · ' + new Date(t.in_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '') + '</span>'
                   : '<span class="vh-chip">🚪 Not at the gate today</span>');
      if (e.gate.points && e.gate.points.month) chips.push('<span class="vh-chip">⭐ ' + e.gate.points.month + ' points this month</span>');
    }
    if (e.my_rank && e.my_rank.of > 1) chips.push('<span class="vh-chip">🏅 #' + e.my_rank.rank + ' of ' + e.my_rank.of + ' in your teams</span>');
    $('vhChips').innerHTML = chips.join('');

    /* One next step: the most useful thing to do right now. */
    var step = null, live = hub.upcoming.filter(function (m) { return m.room; })[0];
    if (live) step = ['Open the live room', function () { openRoom(live.id, live.occurrence); }];
    else if (hub.to_approve.length) step = ['Approve the minutes', function () { openMinutes(hub.to_approve[0].id, hub.to_approve[0].occurrence); }];
    else if (hub.to_write.length) step = ['Write the minutes', function () { openMinutes(hub.to_write[0].id, hub.to_write[0].occurrence); }];
    else if (hub.upcoming.some(function (m) { return !m.rsvp && !m.live; })) step = ['Answer your invitations', function () { setTab('meetings'); }];
    else if (hub.counts.overdue) step = ['See your overdue actions', function () { setTab('actions'); }];
    $('vhNextStep').hidden = !step;
    if (step) { $('vhNextStep').textContent = step[0] + ' →'; $('vhNextStep').onclick = step[1]; }

    /* The ring: this month's hours toward the goal. */
    var got = hub.hours.month, goal = e.goal, pct = Math.min(1, got / goal), r = 52, c = 2 * Math.PI * r;
    $('vhRing').innerHTML = '<svg viewBox="0 0 128 128" width="128" height="128" role="img" aria-label="' + got + ' of ' + goal + ' hours this month">'
      + '<circle cx="64" cy="64" r="' + r + '" class="vh-ring-bg"/><circle cx="64" cy="64" r="' + r + '" class="vh-ring-fg" stroke-dasharray="' + (c * pct).toFixed(1) + ' ' + c.toFixed(1) + '" transform="rotate(-90 64 64)"/></svg>'
      + '<span class="vh-ring-t"><b class="av-num">' + got + '</b><small>of ' + goal + ' h this month</small></span>'
      + (got >= goal ? '<span class="vh-ring-done">Goal reached ✨</span>' : '');

    /* Milestones */
    var badges = e.earned.slice(-6).map(function (b) { return '<span class="vh-badge" title="' + esc(b.label) + '"><span aria-hidden="true">' + b.emoji + '</span>' + esc(b.label) + '</span>'; }).join('');
    var next = e.next ? '<span class="vh-next"><span>Next: ' + e.next.emoji + ' ' + esc(e.next.label) + '</span><progress max="100" value="' + e.next.pct + '" aria-label="' + e.next.pct + '% of the way"></progress><small>' + e.next.have + ' / ' + e.next.need + '</small></span>' : '';
    $('vhBadges').innerHTML = (badges || '<span class="vh-badge vh-badge--empty">Your first milestone is one hour away 🌱</span>') + next;
    celebrateNew(e.earned);

    /* Side: spotlight, leaderboard, birthdays */
    var v = e.votm;
    $('vhSpot').hidden = !v;
    if (v) $('vhSpot').innerHTML = '<div class="vh-spot-in">' + (v.photo ? '<img src="' + esc(v.photo) + '" alt="" loading="lazy">' : '<span class="vh-spot-av" aria-hidden="true">' + esc(initials(v.name)) + '</span>')
      + '<div><p class="vh-spot-k" id="vhSpotH">Volunteer of the Month</p><b>' + esc(v.name) + '</b>' + (v.role ? '<small>' + esc(v.role) + '</small>' : '') + (v.reason ? '<p>' + esc(v.reason) + '</p>' : '') + (v.quote ? '<q>' + esc(v.quote) + '</q>' : '') + '</div></div>';
    $('vhBoardCard').hidden = !e.board.length;
    $('vhRank').textContent = e.my_rank ? 'You: #' + e.my_rank.rank : '';
    var top = Math.max.apply(null, e.board.map(function (b) { return b.hours; }).concat([1]));
    $('vhBoard').innerHTML = e.board.map(function (b) {
      return '<li class="' + (b.me ? 'is-me' : '') + '"><span class="vh-board-n">' + (['🥇', '🥈', '🥉'][b.rank - 1] || b.rank) + '</span><span class="vh-board-t"><span>' + esc(b.me ? 'You' : b.name) + '</span><span class="vh-board-bar"><i style="width:' + Math.round(b.hours / top * 100) + '%"></i></span></span><b class="av-num">' + b.hours + 'h</b></li>';
    }).join('');
    $('vhBdayCard').hidden = !e.birthdays.length;
    $('vhBdays').innerHTML = e.birthdays.map(function (b) {
      return '<li><span class="vh-feed-i" aria-hidden="true">🎂</span><span><span>' + esc(b.me ? 'You' : b.name) + '</span><small>' + (b.in === 0 ? 'Today — say happy birthday!' : b.in === 1 ? 'Tomorrow' : day(b.day)) + '</small></span></li>';
    }).join('');
  }
  /* A milestone newly earned gets a moment — once. */
  function celebrateNew(earned) {
    var seen = []; try { seen = JSON.parse(localStorage.getItem('vh.badges') || '[]'); } catch (x) {}
    var keys = earned.map(function (b) { return b.key; });
    var fresh = earned.filter(function (b) { return seen.indexOf(b.key) < 0; });
    try { localStorage.setItem('vh.badges', JSON.stringify(keys)); } catch (x) {}
    if (!seen.length || !fresh.length) return;   /* the first visit sets the baseline quietly */
    var b = fresh[fresh.length - 1];
    var el = document.createElement('div'); el.className = 'vh-celebrate'; el.setAttribute('role', 'status');
    el.innerHTML = '<span class="vh-celebrate-e" aria-hidden="true">' + b.emoji + '</span><b>Milestone reached</b><span>' + esc(b.label) + '</span>';
    document.body.appendChild(el);
    setTimeout(function () { el.classList.add('out'); }, 4200); setTimeout(function () { el.remove(); }, 5000);
  }

  /* ── Attendance tab ── */
  function drawAttendance() {
    var g = hub.engagement.gate, a = hub.attendance, k = [];
    if (g) {
      k.push(['At the gate', g.counted ? g.rate + '%' : '—', 'last 30 days']);
      k.push(['On time', g.counted ? g.punctuality + '%' : '—', g.late + ' late' + (g.run ? ' · ' + g.run + '-day on-time run' : '')]);
      k.push(['Grade', g.grade || '—', g.points ? (g.points.month || 0) + ' points this month' : '']);
    }
    k.push(['Meetings attended', a.rate === null ? '—' : a.rate + '%', a.meetings ? (a.present + a.late) + ' of ' + (a.present + a.late + a.absent) + ' · ' + a.apologies + ' apolog' + (a.apologies === 1 ? 'y' : 'ies') : 'from published minutes']);
    $('vhAttKpis').innerHTML = k.map(function (x) { return '<div class="avp-kpi"><div class="avp-kpi-top"><span class="avp-kpi-l">' + esc(x[0]) + '</span></div><div class="avp-kpi-v av-num">' + esc(x[1]) + '</div><div class="avp-kpi-s">' + esc(x[2]) + '</div></div>'; }).join('');
    api('past', null, 'team_id=0').then(function (r) {
      if (!r.ok) { $('vhMyMeet').innerHTML = '<li class="avp-empty">' + esc(r.error) + '</li>'; return; }
      var LBL = { present: ['On time', 'green'], late: ['Late', 'gold'], apologies: ['Apologies', 'indigo'], absent: ['Missed', 'red'] };
      var mine = r.meetings.filter(function (m) { return m.my_mark || m.minutes_status === 'published'; });
      $('vhMyMeetN').textContent = mine.length;
      $('vhMyMeet').innerHTML = mine.length ? mine.map(function (m) {
        var l = LBL[m.my_mark];
        return row(esc(m.title) + ' ' + (l ? '<span class="avp-chip avp-chip--sm avp-chip--' + l[1] + '">' + l[0] + '</span>' : '<span class="avp-chip avp-chip--sm">Not marked</span>'),
          esc(m.when) + (m.team ? ' · ' + esc(m.team) : '') + ' · ' + m.came + ' of ' + m.invited + ' came',
          m.minutes_status === 'published' ? '<button type="button" class="avp-btn avp-btn--sq" data-minutes="' + m.id + '" data-occ="' + esc(m.occurrence) + '">Minutes</button>' : '');
      }).join('') : '<li class="avp-empty">No meetings recorded yet.</li>';
    });
  }

  function seg(m) {
    return '<span class="vh-seg" role="group" aria-label="Are you coming to ' + esc(m.title) + '?">'
      + [['yes', 'Coming'], ['maybe', 'Maybe'], ['no', 'Can’t']].map(function (a) {
        return '<button type="button" data-rsvp="' + a[0] + '" data-id="' + m.id + '" data-occ="' + esc(m.occurrence) + '" aria-pressed="' + (m.rsvp === a[0]) + '">' + a[1] + '</button>';
      }).join('') + '</span>';
  }
  function meetRow(m) {
    var sub = esc(m.when) + ' · ' + m.duration_min + ' min' + (m.frequency_label !== 'One-off' ? ' · ' + esc(m.frequency_label) : '') + (m.team ? ' · ' + esc(m.team) : '')
      + '<br>' + m.coming + ' coming' + (m.apologies ? ' · ' + m.apologies + ' apolog' + (m.apologies === 1 ? 'y' : 'ies') : '') + ' · ' + m.invited + ' invited'
      + (m.agenda && m.agenda.length ? '<br><span class="vh-agenda-mini">' + m.agenda.map(esc).join(' · ') + '</span>' : '');
    var btns = (m.live ? '' : seg(m))
      + (m.room ? '<button type="button" class="avp-btn avp-btn--sq avp-btn--ink" data-room="' + m.id + '" data-occ="' + esc(m.occurrence) + '">Live room</button>' : '')
      + (m.meet_url ? '<a class="avp-btn avp-btn--sq" href="' + esc(m.meet_url) + '" target="_blank" rel="noopener">Join</a>' : '')
      + '<a class="avp-btn avp-btn--sq" href="' + API + '?action=ics&meeting_id=' + m.id + '" title="Add to your calendar" aria-label="Add ' + esc(m.title) + ' to your calendar">.ics</a>';
    return row(esc(m.title), sub, btns);
  }
  function ago(at) {
    var t = Date.parse(String(at).replace(' ', 'T') + (String(at).indexOf('T') < 0 ? 'Z' : ''));
    if (isNaN(t)) return ''; var m = Math.round((Date.now() - t) / 60000);
    return m < 60 ? (m <= 1 ? 'just now' : m + ' min ago') : m < 1440 ? Math.round(m / 60) + ' h ago' : Math.round(m / 1440) + ' d ago';
  }
  function spark(series) {
    var max = Math.max.apply(null, series.map(function (x) { return x.hours; }).concat([1])), w = 8, gap = 3;
    return '<svg viewBox="0 0 ' + (series.length * (w + gap)) + ' 24" width="' + (series.length * (w + gap)) + '" height="24">' + series.map(function (x, i) {
      var h = Math.max(2, Math.round(x.hours / max * 22));
      return '<rect x="' + i * (w + gap) + '" y="' + (24 - h) + '" width="' + w + '" height="' + h + '" rx="2" class="' + (i === series.length - 1 ? 'now' : '') + '"><title>' + esc(x.long) + ': ' + x.hours + ' h</title></rect>';
    }).join('') + '</svg>';
  }

  /* ── Meetings tab ── */
  function drawMeetings() {
    var team = +$('vhMTeam').value;
    view.querySelectorAll('[data-mscope]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-mscope') === mscope ? 'true' : 'false'); });
    if (mscope === 'upcoming') {
      var list = hub.upcoming.filter(function (m) { return !team || m.team_id === team; });
      $('vhMeetList').innerHTML = list.length ? list.map(meetRow).join('') : '<li class="avp-empty">No meetings coming up' + (team ? ' for this team' : '') + '.</li>';
      return;
    }
    $('vhMeetList').innerHTML = '<li class="avp-empty">Loading…</li>';
    api('past', null, 'team_id=' + team).then(function (r) {
      if (!r.ok) { $('vhMeetList').innerHTML = '<li class="avp-empty">' + esc(r.error) + '</li>'; return; }
      var MARK = { present: 'You came', late: 'You were late', apologies: 'You sent apologies', absent: 'You missed it' };
      $('vhMeetList').innerHTML = r.meetings.length ? r.meetings.map(function (m) {
        var st = m.minutes_status === 'published' ? '<span class="avp-chip avp-chip--sm avp-chip--green">Minutes out</span>' : m.minutes_status === 'review' ? '<span class="avp-chip avp-chip--sm avp-chip--indigo">With the chair</span>' : m.minutes_status === 'draft' ? '<span class="avp-chip avp-chip--sm avp-chip--gold">Draft</span>' : '<span class="avp-chip avp-chip--sm">No minutes yet</span>';
        var btn = m.minutes_status === 'published' || (m.minutes_id && m.can_write) || m.minutes_status === 'review' ? '<button type="button" class="avp-btn avp-btn--sq" data-minutes="' + m.id + '" data-occ="' + esc(m.occurrence) + '">' + (m.minutes_status === 'published' ? 'Read the minutes' : 'Open the minutes') + '</button>'
          : m.can_write ? '<button type="button" class="avp-btn avp-btn--sq avp-btn--ink" data-minutes="' + m.id + '" data-occ="' + esc(m.occurrence) + '">Write the minutes</button>' : '';
        return row(esc(m.title) + ' ' + st, esc(m.when) + (m.team ? ' · ' + esc(m.team) : '') + ' · ' + m.came + ' of ' + m.invited + ' came' + (MARK[m.my_mark] ? ' · ' + MARK[m.my_mark] : ''), btn);
      }).join('') : '<li class="avp-empty">No past meetings in the last four months.</li>';
    });
  }
  view.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-mscope]'); if (b) { mscope = b.getAttribute('data-mscope'); drawMeetings(); }
    b = ev.target.closest('[data-mstatus]'); if (b) { mstatus = b.getAttribute('data-mstatus'); drawMinutesTab(); }
    b = ev.target.closest('[data-ascope]'); if (b) { ascope = b.getAttribute('data-ascope'); drawActions(); }
    b = ev.target.closest('[data-goto-tab]'); if (b) setTab(b.getAttribute('data-goto-tab'));
  });
  $('vhMTeam').addEventListener('change', drawMeetings);

  /* ── Minutes tab ── */
  var qT;
  function drawMinutesTab() {
    view.querySelectorAll('[data-mstatus]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-mstatus') === mstatus ? 'true' : 'false'); });
    var todo = hub.to_approve.map(function (m) { return [m, 'approve']; }).concat(hub.to_write.map(function (m) { return [m, 'write']; }));
    $('vhToDoCard').hidden = !todo.length;
    $('vhToDo').innerHTML = todo.map(function (x) {
      var m = x[0];
      return row(esc(m.title), esc(m.when) + ' · ' + (x[1] === 'approve' ? 'with you to approve' : m.minutes_id ? 'draft' : 'not started'),
        '<button type="button" class="avp-btn avp-btn--sq' + (x[1] === 'approve' ? ' avp-btn--ink' : '') + '" data-minutes="' + m.id + '" data-occ="' + esc(m.occurrence) + '">' + (x[1] === 'approve' ? 'Read and approve' : m.minutes_id ? 'Continue' : 'Write them') + '</button>');
    }).join('');
    api('archive', null, 'status=' + encodeURIComponent(mstatus) + '&team_id=' + (+$('vhNTeam').value) + '&q=' + encodeURIComponent($('vhNQ').value)).then(function (r) {
      if (!r.ok) { $('vhArchive').innerHTML = '<li class="avp-empty">' + esc(r.error) + '</li>'; return; }
      var tone = { draft: 'gold', review: 'indigo', published: 'green' };
      $('vhArchive').innerHTML = r.minutes.length ? r.minutes.map(function (n) {
        return row(esc(n.title) + ' <span class="avp-chip avp-chip--sm avp-chip--' + tone[n.status] + '">' + esc(n.status_label) + '</span>',
          esc(n.when) + (n.team ? ' · ' + esc(n.team) : '') + ' · ' + n.decisions + ' decision' + (n.decisions === 1 ? '' : 's') + ', ' + n.actions + ' action' + (n.actions === 1 ? '' : 's') + (n.summary ? '<br>' + esc(n.summary) : ''),
          '<button type="button" class="avp-btn avp-btn--sq" data-minutes="' + n.id + '" data-occ="' + esc(n.occurrence) + '">Open</button>'
          + (hub.integrations.pdf ? '<a class="avp-btn avp-btn--sq" href="' + API + '?action=pdf&id=' + n.minutes_id + '">PDF</a>' : '<a class="avp-btn avp-btn--sq" href="' + API + '?action=print&id=' + n.minutes_id + '" target="_blank" rel="noopener">Print</a>'));
      }).join('') : '<li class="avp-empty">' + ($('vhNQ').value || mstatus ? 'No minutes match.' : 'No minutes yet. They are drafted by themselves when a meeting ends.') + '</li>';
    });
  }
  $('vhNTeam').addEventListener('change', drawMinutesTab);
  $('vhNQ').addEventListener('input', function () { clearTimeout(qT); qT = setTimeout(drawMinutesTab, 250); });

  /* ── Actions tab ── */
  function groupActions(list, showOwner) {
    var today = new Date().toISOString().slice(0, 10), week = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
    var g = [['Overdue', []], ['This week', []], ['Later', []], ['No date', []]];
    list.forEach(function (a) { (a.overdue ? g[0] : !a.due ? g[3] : a.due <= week ? g[1] : g[2])[1].push(a); });
    var html = g.filter(function (x) { return x[1].length; }).map(function (x) {
      return '<section class="avp-card"><div class="avp-card-head"><h2>' + x[0] + '</h2><span class="avp-chip avp-chip--sm' + (x[0] === 'Overdue' ? ' avp-chip--red' : '') + '">' + x[1].length + '</span></div><ul class="vh-list">'
        + x[1].map(function (a) {
          return row(esc(a.title), (a.due ? '<span class="' + (a.overdue ? 'is-overdue' : '') + '">Due ' + esc(day(a.due)) + '</span>' : 'No due date') + (showOwner ? ' · ' + esc(a.owner) : '') + (a.from ? ' · ' + esc(a.from) : ''),
            !showOwner || a.owner_id === ME ? '<button type="button" class="avp-btn avp-btn--sq" data-done="' + a.id + '">Done</button>' : '');
        }).join('') + '</ul></section>';
    }).join('');
    return html || '<section class="avp-card"><p class="avp-empty">' + (showOwner ? 'This team has no open actions.' : 'You have no open actions. 🎉') + '</p></section>';
  }
  function drawActions() {
    view.querySelectorAll('[data-ascope]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-ascope') === ascope ? 'true' : 'false'); });
    $('vhATeamWrap').hidden = ascope !== 'team';
    if (ascope === 'mine') { $('vhActGroups').innerHTML = groupActions(hub.actions, false); return; }
    api('team_actions', null, 'team_id=' + (+$('vhATeam').value)).then(function (r) { $('vhActGroups').innerHTML = r.ok ? groupActions(r.actions, true) : '<p class="avp-empty">' + esc(r.error) + '</p>'; });
  }
  $('vhATeam').addEventListener('change', drawActions);

  /* ── Teams tab ── */
  function drawTeams() {
    var ts = teamsAll();
    $('vhTeamGrid').hidden = !!openTeamId; $('vhTeamDetail').hidden = !openTeamId;
    if (openTeamId) return openTeam(openTeamId);
    $('vhTeamGrid').innerHTML = ts.length ? ts.map(function (t) {
      var lead = t.members.filter(function (p) { return p.role === 'lead'; }).map(function (p) { return p.name; }).join(', ');
      var next = hub.upcoming.filter(function (m) { return m.team_id === t.id; })[0];
      return '<button type="button" class="vh-team" data-team="' + t.id + '"><b>' + esc(t.name) + '</b><span>' + esc(t.purpose || '') + '</span>'
        + '<span class="vh-avs" aria-label="' + t.members.length + ' members">' + t.members.slice(0, 6).map(function (p) { return '<i title="' + esc(p.name) + '">' + esc(initials(p.name)) + '</i>'; }).join('') + (t.members.length > 6 ? '<i>+' + (t.members.length - 6) + '</i>' : '') + '</span>'
        + '<small>' + (lead ? 'Led by ' + esc(lead) : 'No lead yet') + (t.my_role ? ' · you: ' + esc(t.my_role) : '') + '</small>'
        + '<small>' + (next ? 'Next: ' + esc(next.title) + ', ' + esc(rel(next.ts)) : 'No meeting scheduled') + '</small></button>';
    }).join('') : '<section class="avp-card"><p class="avp-empty">You are not on a team yet. A coordinator adds you.</p></section>';
  }
  function openTeam(id) {
    openTeamId = id; $('vhTeamGrid').hidden = true; $('vhTeamDetail').hidden = false;
    $('vhTeamDetail').innerHTML = '<section class="avp-card"><p class="avp-empty">Loading…</p></section>';
    api('team', null, 'id=' + id).then(function (r) {
      if (!r.ok) { $('vhTeamDetail').innerHTML = '<section class="avp-card"><p class="avp-empty">' + esc(r.error) + '</p></section>'; return; }
      var t = r.team, hrs = {}; r.hours.forEach(function (h) { hrs[h.id] = h.hours; });
      var att = r.attendance || {};
      var h = '<div class="vh-toolbar"><button type="button" class="vh-linkbtn" id="vhTeamBack">← All teams</button></div>'
        + '<div class="avp-cols"><div class="avp-col avp-col--main">'
        + '<section class="avp-card"><div class="avp-card-head"><h2>' + esc(t.name) + '</h2>' + (t.can_manage ? '<span class="avp-chip avp-chip--sm avp-chip--indigo">You manage this team</span>' : '') + '</div>'
        + (t.purpose ? '<p class="vh-note" style="padding:12px 20px 0;margin:0">' + esc(t.purpose) + '</p>' : '')
        + '<div class="vh-table-wrap"><table class="vh-table"><thead><tr><th scope="col">Member</th><th scope="col">Role</th><th scope="col" class="num">Came</th><th scope="col" class="num">Hours this month</th>' + (t.can_manage ? '<th scope="col"><span class="vh-sr">Remove</span></th>' : '') + '</tr></thead><tbody>'
        + t.members.map(function (p) {
          var a = att[p.id];
          return '<tr><td><b>' + esc(p.name) + '</b><br><small>' + esc(p.email) + '</small></td><td>'
            + (t.can_manage ? '<select data-role="' + p.id + '" aria-label="Role of ' + esc(p.name) + '">' + ['lead', 'secretary', 'member'].map(function (x) { return '<option value="' + x + '"' + (p.role === x ? ' selected' : '') + '>' + x[0].toUpperCase() + x.slice(1) + '</option>'; }).join('') + '</select>' : esc(p.role))
            + '</td><td class="num">' + (a && a.rate !== null ? a.rate + '% <small>(' + a.came + '/' + a.counted + ')</small>' : '—') + '</td><td class="num">' + (hrs[p.id] || 0) + '</td>'
            + (t.can_manage ? '<td><button type="button" class="vh-x" data-rm="' + p.id + '" aria-label="Remove ' + esc(p.name) + '">×</button></td>' : '') + '</tr>';
        }).join('') + '</tbody></table></div>'
        + (t.can_manage ? '<form class="vh-addform" id="vhAddMember"><label>Add somebody<input id="ftAdd" type="email" list="ftPeople" autocomplete="off" placeholder="their email" required><datalist id="ftPeople"></datalist></label><select id="ftRole" aria-label="As"><option value="member">Member</option><option value="secretary">Secretary</option><option value="lead">Lead</option></select><button class="avp-btn avp-btn--sq avp-btn--ink" type="submit">Add</button></form><p class="vh-err" id="ftErr" role="alert"></p>' : '')
        + '</section>'
        + '<section class="avp-card"><div class="avp-card-head"><h2>Open actions</h2><span class="avp-chip avp-chip--sm">' + r.actions.length + '</span></div><ul class="vh-list">'
        + (r.actions.length ? r.actions.map(function (a) { return row(esc(a.title), (a.due ? '<span class="' + (a.overdue ? 'is-overdue' : '') + '">Due ' + esc(day(a.due)) + '</span> · ' : '') + esc(a.owner) + (a.from ? ' · ' + esc(a.from) : ''), ''); }).join('') : '<li class="avp-empty">Nothing open.</li>')
        + '</ul></section></div><div class="avp-col avp-col--side">'
        + '<section class="avp-card"><div class="avp-card-head"><h2>Coming up</h2></div><ul class="vh-list">' + (r.upcoming.length ? r.upcoming.map(function (m) { return row(esc(m.title), esc(m.when), ''); }).join('') : '<li class="avp-empty">Nothing scheduled.</li>') + '</ul></section>'
        + '<section class="avp-card"><div class="avp-card-head"><h2>Recent meetings</h2></div><ul class="vh-list">' + (r.past.length ? r.past.map(function (m) { return row(esc(m.title), esc(m.when) + ' · ' + m.came + '/' + m.invited + ' came', m.minutes_status === 'published' ? '<button type="button" class="avp-btn avp-btn--sq" data-minutes="' + m.id + '" data-occ="' + esc(m.occurrence) + '">Minutes</button>' : ''); }).join('') : '<li class="avp-empty">None yet.</li>') + '</ul></section>'
        + '</div></div>';
      $('vhTeamDetail').innerHTML = h;
      $('vhTeamBack').addEventListener('click', function () { openTeamId = 0; drawTeams(); });
      var d = $('vhTeamDetail');
      d.onchange = function (ev) {
        var s = ev.target.closest('[data-role]'); if (!s) return;
        api('team_member', { team_id: id, who: s.getAttribute('data-role'), role: s.value }).then(function (x) { if (x.ok) { toast('Saved.'); load(); openTeam(id); } else { toast(x.error, true); openTeam(id); } });
      };
      d.onclick = function (ev) {
        var b = ev.target.closest('[data-rm]'); if (!b) return;
        if (!confirm('Remove them from the team?')) return;
        api('team_remove', { team_id: id, user_id: +b.getAttribute('data-rm') }).then(function (x) { if (x.ok) { load(); openTeam(id); } else toast(x.error, true); });
      };
      var f = $('vhAddMember');
      if (f) {
        f.addEventListener('submit', function (ev) {
          ev.preventDefault();
          api('team_member', { team_id: id, who: val('ftAdd'), role: val('ftRole') }).then(function (x) { if (x.ok) { toast('Added — they are invited to the team’s meetings.'); load(); openTeam(id); } else $('ftErr').textContent = x.error; });
        });
        var st; $('ftAdd').addEventListener('input', function () {
          clearTimeout(st); var q = this.value.trim(); if (q.length < 2) return;
          st = setTimeout(function () { api('people', null, 'q=' + encodeURIComponent(q)).then(function (x) {
            $('ftPeople').innerHTML = (x.people || []).map(function (p) { return '<option value="' + esc(p.email) + '">' + esc(p.name) + '</option>'; }).join('');
          }); }, 200);
        });
      }
    });
  }

  /* ── Hours tab ── */
  function drawHours() {
    var h = hub.hours, s = hub.hours_series;
    $('vhHoursTot').innerHTML = '<div class="vh-hours-n"><div><b class="av-num">' + h.month + '</b><span>this month</span></div><div><b class="av-num">' + h.year + '</b><span>this year</span></div><div><b class="av-num">' + h.total + '</b><span>in all</span></div></div>';
    $('vhChartTable').setAttribute('aria-pressed', chartTable ? 'true' : 'false'); $('vhChartTable').textContent = chartTable ? 'Show as a chart' : 'Show as a table';
    if (chartTable) {
      $('vhChart').innerHTML = '<table class="vh-table"><thead><tr><th scope="col">Month</th><th scope="col" class="num">Hours</th></tr></thead><tbody>' + s.map(function (x) { return '<tr><td>' + esc(x.long) + '</td><td class="num">' + x.hours + '</td></tr>'; }).join('') + '</tbody></table>';
    } else {
      var max = Math.max.apply(null, s.map(function (x) { return x.hours; }).concat([1]));
      var nice = max <= 5 ? 5 : max <= 10 ? 10 : Math.ceil(max / 10) * 10, H = 160;
      $('vhChart').innerHTML = '<div class="vh-bars" role="img" aria-label="Hours given each month for the last twelve months. Use Show as a table for the numbers.">'
        + '<span class="vh-axis" aria-hidden="true"><i>' + nice + '</i><i>' + (nice / 2) + '</i><i>0</i></span>'
        + s.map(function (x, i) {
          var hgt = x.hours ? Math.max(3, Math.round(x.hours / nice * H)) : 0;
          return '<span class="vh-bar' + (i === s.length - 1 ? ' is-now' : '') + '" tabindex="0" data-tip="' + esc(x.long) + ' · ' + x.hours + ' h"><i style="height:' + hgt + 'px"></i><small>' + esc(x.label) + '</small></span>';
        }).join('') + '</div>';
    }
    $('vhHoursList').innerHTML = h.recent.length ? h.recent.map(function (r) {
      return row(esc(r.activity), esc(day(r.day)) + ' · ' + r.hours + ' h · ' + (r.source === 'manual' ? 'logged by you' : 'meeting attended'),
        r.source === 'manual' ? '<button type="button" class="vh-x" data-unhours="' + r.id + '" aria-label="Remove ' + esc(r.activity) + '">×</button>' : '');
    }).join('') + (h.recent.length >= 12 ? '<li class="vh-note" style="padding:10px 20px">The latest twelve. Download the CSV for everything.</li>' : '') : '<li class="avp-empty">Nothing logged yet.</li>';
    if (!$('lhDay').value) { $('lhDay').value = new Date().toISOString().slice(0, 10); $('lhDay').max = $('lhDay').value; }
  }
  $('vhChartTable').addEventListener('click', function () { chartTable = !chartTable; drawHours(); });
  $('vhLogForm').addEventListener('submit', function (ev) {
    ev.preventDefault();
    api('hours', { activity: val('lhAct'), day: val('lhDay'), hours: val('lhH') }).then(function (r) {
      if (!r.ok) { $('lhErr').textContent = r.error; return; }
      $('lhErr').textContent = ''; $('lhAct').value = ''; toast('Logged — thank you.'); load();
    });
  });

  view.addEventListener('click', function (ev) {
    var b = ev.target.closest('button, a'); if (!b) return;
    if (b.hasAttribute('data-rsvp')) {
      var ans = b.getAttribute('data-rsvp'), note = '';
      if (ans === 'no') { note = prompt('Send your apologies — anything the chair should know? (optional)', ''); if (note === null) return; }
      api('rsvp', { meeting_id: +b.getAttribute('data-id'), occurrence: b.getAttribute('data-occ'), answer: ans, note: note }).then(function (r) {
        if (!r.ok) return toast(r.error, true);
        toast(ans === 'yes' ? 'See you there.' : ans === 'no' ? 'Apologies sent.' : 'Noted.'); load();
      });
    } else if (b.hasAttribute('data-room')) openRoom(+b.getAttribute('data-room'), b.getAttribute('data-occ'));
    else if (b.hasAttribute('data-minutes')) openMinutes(+b.getAttribute('data-minutes'), b.getAttribute('data-occ'));
    else if (b.hasAttribute('data-done')) {
      fetch('/portal/commitments.php?action=done', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ id: +b.getAttribute('data-done') }) })
        .then(function (r) { return r.json(); }).then(function (r) { if (r.ok) { toast('Done — well done.'); load(); } else toast(r.error || 'Not saved.', true); })
        .catch(function () { toast('No connection.', true); });
    } else if (b.hasAttribute('data-team')) { setTab('teams'); openTeam(+b.getAttribute('data-team')); }
    else if (b.hasAttribute('data-unhours')) api('hours_remove', { id: +b.getAttribute('data-unhours') }).then(function (r) { if (r.ok) load(); else toast(r.error, true); });
  });

  /* ══ Small forms ══════════════════════════════════════════════════════ */

  function form(title, html, go, submit) {
    var d = $('vhForm');
    $('vhFormH').textContent = title; $('vhFormBody').innerHTML = html; $('vhFormErr').textContent = ''; $('vhFormGo').textContent = go;
    $('vhFormF').onsubmit = function (ev) {
      ev.preventDefault();
      $('vhFormGo').disabled = true;
      submit(function (r) {
        $('vhFormGo').disabled = false;
        if (r && r.ok) { d.close(); load(); } else $('vhFormErr').textContent = (r && r.error) || 'Not saved.';
      });
    };
    d.showModal();
    var f = d.querySelector('.vh-form-body input, .vh-form-body select, .vh-form-body textarea'); if (f) f.focus();
  }
  function val(id) { var e = $(id); return e ? e.value : ''; }

  $('vhLogHours').addEventListener('click', function () { setTab('hours'); setTimeout(function () { $('lhAct').focus(); }, 50); });
  $('vhNewTeam').addEventListener('click', function () {
    form('New team', '<label>Name<input id="ftName" maxlength="120" required></label><label class="avp-form-wide" style="justify-self:stretch">What it does<input id="ftPur" maxlength="400"></label>', 'Create', function (done) {
      api('team_create', { name: val('ftName'), purpose: val('ftPur') }).then(function (r) { done(r); if (r.ok) { setTab('teams'); openTeam(r.id); } });
    });
  });
  $('vhSchedule').addEventListener('click', function () {
    var teams = teamsAll();
    var d = new Date(Date.now() + 86400000); d.setMinutes(0, 0, 0);
    var local = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    /* The notetaker is offered only where one is set up. Without it the
       meeting is still captured: check-in, captions, Google's own record. */
    var bot = hub.integrations.notetaker;
    form('Schedule a team meeting',
      '<label>Team<select id="fsTeam">' + teams.map(function (t) { return '<option value="' + t.id + '"' + (t.id === openTeamId ? ' selected' : '') + '>' + esc(t.name) + '</option>'; }).join('') + '</select></label>'
      + '<label>Title<input id="fsTitle" maxlength="200" placeholder="Team meeting"></label>'
      + '<label>When<input id="fsWhen" type="datetime-local" value="' + local + '" required></label>'
      + '<label>How long<select id="fsDur"><option value="30">30 minutes</option><option value="45">45 minutes</option><option value="60" selected>1 hour</option><option value="90">1½ hours</option><option value="120">2 hours</option></select></label>'
      + '<label>Repeats<select id="fsFreq"><option value="once">Once</option><option value="weekly">Every week</option><option value="biweekly">Every 2 weeks</option><option value="monthly">Every month</option></select></label>'
      + '<label class="avp-form-wide" style="justify-self:stretch">Agenda — one item a line<textarea id="fsAgenda" rows="4"></textarea></label>'
      + '<label class="avp-form-wide" style="justify-self:stretch">Also invite (emails, optional)<input id="fsExtra" placeholder="guest@example.org"></label>'
      + (bot ? '<label class="avp-form-wide" style="flex-direction:row;align-items:center;gap:8px"><input type="checkbox" id="fsRec" style="height:auto"> Send the notetaker to record and transcribe</label>' : ''),
      'Schedule and invite', function (done) {
        api('schedule', { team_id: +val('fsTeam'), title: val('fsTitle'), when: val('fsWhen'), duration: +val('fsDur'), frequency: val('fsFreq'), agenda: val('fsAgenda'), extra: val('fsExtra'), auto_record: !!(bot && $('fsRec') && $('fsRec').checked) })
          .then(function (r) { done(r); if (r.ok) toast(r.warning || 'Scheduled — the team has been invited.'); });
      });
  });

  /* ══ The live room ════════════════════════════════════════════════════ */

  var room = { id: 0, occ: '', since: 0, timer: 0, data: null, rec: null, recOn: false };
  var SRC = { google: 'Meet', bot: 'Notetaker', checkin: 'QR', self: 'Said so', browser: 'Room', manual: 'Hand' };

  function openRoom(id, occ) {
    room.id = id; room.occ = occ; room.since = 0; room.data = null;
    $('vhWords').innerHTML = ''; $('vhInterim').textContent = '';
    $('vhRoom').showModal();
    pollRoom();
    clearInterval(room.timer); room.timer = setInterval(pollRoom, 4000);
  }
  $('vhRoom').addEventListener('close', function () { clearInterval(room.timer); stopCaptions(); load(); });

  function pollRoom() {
    if (!$('vhRoom').open) return;
    api('room', null, 'meeting_id=' + room.id + '&occurrence=' + encodeURIComponent(room.occ) + '&since=' + room.since).then(function (r) {
      if (!r.ok) { $('vhRoomSub').textContent = r.error || 'The room is not available.'; return; }
      var first = !room.data; room.data = r; room.since = r.last_id;
      var m = r.meeting;
      $('vhRoomH').textContent = m.title; $('vhRoomSub').textContent = m.when;
      $('vhRoomJoin').hidden = !m.meet_url; if (m.meet_url) $('vhRoomJoin').href = m.meet_url;
      $('vhHereN').textContent = '· ' + r.people.length;
      $('vhHere').innerHTML = r.people.map(function (p) {
        return '<li><span class="vh-av" aria-hidden="true">' + esc(initials(p.name)) + '</span><span>' + esc(p.name) + '</span>'
          + (p.late ? '<span class="avp-chip avp-chip--sm avp-chip--gold">' + p.late_by + ' min late</span>' : '')
          + (p.in_now ? '' : '<span class="vh-src">left</span>')
          + p.sources.map(function (s) { return '<span class="vh-src">' + esc(SRC[s] || s) + '</span>'; }).join('') + '</li>';
      }).join('') || '<li class="vh-note">Nobody yet.</li>';
      $('vhMissing').innerHTML = r.missing.map(function (p) { return '<li><span class="vh-av" aria-hidden="true">' + esc(initials(p.name)) + '</span>' + esc(p.name) + '</li>'; }).join('') || '<li class="vh-note">Everybody is here.</li>';
      $('vhImHere').hidden = r.people.some(function (p) { return p.user_id === ME; });
      var list = $('vhWords'), atEnd = list.scrollTop + list.clientHeight >= list.scrollHeight - 30;
      r.words.forEach(function (w) {
        var li = document.createElement('li');
        li.innerHTML = '<b>' + esc(w.name || 'Someone') + ' · ' + new Date(w.at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + '</b>' + esc(w.text);
        list.appendChild(li);
      });
      if (!list.children.length) list.innerHTML = '<li class="vh-note" data-empty>Words appear here as they are said — from the notetaker, or from this room’s captions.</li>';
      else { var e = list.querySelector('[data-empty]'); if (e && list.children.length > 1) e.remove(); }
      if (atEnd || first) list.scrollTop = list.scrollHeight;
      if (r.checkin) { $('vhQr').innerHTML = r.checkin.qr; $('vhQrBigCode').innerHTML = r.checkin.qr; $('vhQrIn').textContent = '(next in ' + r.checkin.refresh_in + 's)'; }
      $('vhQrBigT').textContent = m.title;
      if (first) {
        $('vhRoomAgenda').innerHTML = m.agenda.map(function (a) { return '<li>' + esc(a.item) + '</li>'; }).join('') || '<li class="vh-note">No agenda.</li>';
        $('vhCapture').hidden = !m.can_capture;
        $('vhRoomMin').hidden = !m.can_capture;
        $('vhCaption').hidden = !(window.SpeechRecognition || window.webkitSpeechRecognition);
        var opts = '<option value="0">Who owns it?</option>';
        api('minutes', null, 'meeting_id=' + room.id + '&occurrence=' + encodeURIComponent(room.occ)).then(function (x) {
          if (x.ok && x.meeting) $('vhCapOwner').innerHTML = opts + x.meeting.invitees.filter(function (p) { return p.id > 0; }).map(function (p) { return '<option value="' + p.id + '">' + esc(p.name) + '</option>'; }).join('');
        });
        var s = r.sources, said = [];
        if (s.google) said.push('Google Meet tells us who joined and left');
        if (s.bot) said.push('the notetaker sends what is said, live');
        said.push('the QR checks people in the room');
        $('vhSources').textContent = 'Captured automatically: ' + said.join('; ') + '.';
      }
      tickClock();
    });
  }
  function tickClock() {
    if (!room.data) return;
    var s = new Date(room.data.meeting.start).getTime(), e = new Date(room.data.meeting.end).getTime(), now = Date.now();
    var fmt = function (ms) { var m = Math.floor(Math.abs(ms) / 60000); return Math.floor(m / 60) ? Math.floor(m / 60) + 'h ' + (m % 60) + 'm' : m + 'm'; };
    $('vhRoomClock').textContent = now < s ? 'Starts in ' + fmt(s - now) : now < e ? fmt(e - now) + ' left' : 'Over by ' + fmt(now - e);
  }
  $('vhRoomMin').addEventListener('click', function () { openMinutes(room.id, room.occ); });
  $('vhImHere').addEventListener('click', function () {
    api('here', { meeting_id: room.id, occurrence: room.occ }).then(function (r) { if (r.ok) { toast('Marked as here.'); pollRoom(); } else toast(r.error, true); });
  });
  $('vhQrBig').addEventListener('click', function () { $('vhQrBigD').showModal(); });
  $('vhCapType').addEventListener('change', function () { $('vhCapOwner').hidden = this.value !== 'action'; });
  $('vhCapture').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var t = $('vhCapText').value.trim(); if (!t) return;
    api('capture', { meeting_id: room.id, occurrence: room.occ, type: $('vhCapType').value, text: t, owner_id: +$('vhCapOwner').value })
      .then(function (r) { if (r.ok) { $('vhCapText').value = ''; toast(r.captured[0].toUpperCase() + r.captured.slice(1) + ' added to the minutes.'); } else toast(r.error, true); });
  });

  /* Room captions — the Web Speech API. Each FINAL result is one sentence,
     sent with a client id so a retry is stored once. Recognition stops by
     itself after a pause; while captions are on it is started again. */
  function stopCaptions() {
    room.recOn = false;
    try { if (room.rec) room.rec.stop(); } catch (e) {}
    $('vhCaption').setAttribute('aria-pressed', 'false'); $('vhCaption').textContent = 'Caption this room'; $('vhInterim').textContent = '';
  }
  $('vhCaption').addEventListener('click', function () {
    if (room.recOn) return stopCaptions();
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition; if (!SR) return toast('This browser cannot caption. Try Chrome or Edge.', true);
    var rec = new SR(); rec.continuous = true; rec.interimResults = true; rec.lang = document.documentElement.lang === 'en' ? 'en-NG' : (navigator.language || 'en-GB');
    rec.onresult = function (e) {
      var interim = '';
      for (var i = e.resultIndex; i < e.results.length; i++) {
        var res = e.results[i];
        if (res.isFinal) {
          var text = res[0].transcript.trim();
          if (text) api('caption', { meeting_id: room.id, occurrence: room.occ, text: text, id: 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8) });
        } else interim += res[0].transcript;
      }
      $('vhInterim').textContent = interim;
    };
    rec.onerror = function (e) { if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { toast('Allow the microphone to caption the room.', true); stopCaptions(); } };
    rec.onend = function () { if (room.recOn) { try { rec.start(); } catch (x) {} } };
    room.rec = rec; room.recOn = true;
    try { rec.start(); } catch (x) {}
    this.setAttribute('aria-pressed', 'true'); this.textContent = 'Stop captions';
    toast('Captioning — what is said in the room is added as it is said.');
  });

  /* ══ The minutes editor ═══════════════════════════════════════════════ */

  var ed = { min: null, mtg: null, dirty: false, saveT: 0, sortables: [] };
  var MARKS = [['', '—'], ['present', 'Present'], ['late', 'Late'], ['apologies', 'Apologies'], ['absent', 'Absent']];

  function openMinutes(id, occ) {
    api('minutes', null, 'meeting_id=' + id + '&occurrence=' + encodeURIComponent(occ)).then(function (r) {
      if (!r.ok) return toast(r.error, true);
      if (!r.minutes) return toast('The minutes have not been started yet. The secretary or the chair writes them.', true);
      ed.min = r.minutes; ed.mtg = r.meeting; ed.dirty = false; ed.words = r.words || '';
      drawMinutes();
      if (!$('vhMin').open) $('vhMin').showModal();
    });
  }
  $('vhMin').addEventListener('close', function () { if (ed.dirty) save(); load(); });

  function owners(sel) {
    return '<option value="0">Who owns it?</option>' + ed.mtg.invitees.filter(function (p) { return p.id > 0; }).map(function (p) {
      return '<option value="' + p.id + '"' + (+sel === p.id ? ' selected' : '') + '>' + esc(p.name) + '</option>';
    }).join('');
  }
  function itemRow(kind, i, data, ro) {
    var dis = ro ? ' disabled' : '';
    var grip = '<span class="vh-grip" aria-hidden="true" title="Drag to reorder">⋮⋮</span>';
    var x = ro ? '' : '<button type="button" class="vh-x" data-del="' + kind + '" aria-label="Remove">×</button>';
    if (kind === 'agenda') return '<li class="vh-item" data-k="agenda">' + grip + '<div><input data-f="item" value="' + esc(data.item) + '" aria-label="Agenda item"' + dis + '><textarea data-f="notes" placeholder="What was said and agreed (Markdown works)" aria-label="Notes on ' + esc(data.item) + '"' + dis + '>' + esc(data.notes) + '</textarea></div>' + x + '</li>';
    if (kind === 'decision') return '<li class="vh-item" data-k="decision">' + grip + '<input data-f="text" value="' + esc(data) + '" aria-label="Decision"' + dis + '>' + x + '</li>';
    return '<li class="vh-item vh-item--action" data-k="action">' + grip + '<input data-f="task" value="' + esc(data.task) + '" placeholder="What will be done" aria-label="Action"' + dis + '>'
      + '<select data-f="owner_id" aria-label="Owner"' + dis + '>' + owners(data.owner_id) + '</select>'
      + '<input data-f="due" type="date" value="' + esc(data.due) + '" aria-label="Due"' + dis + '>' + x + '</li>';
  }
  function drawMinutes() {
    var mn = ed.min, b = mn.body, m = ed.mtg, ro = !mn.can_write || (mn.status === 'review' && !mn.can_publish);
    $('vhMinTitle').textContent = m.title;
    $('vhMinSub').textContent = m.when + (m.team ? ' · ' + m.team.name : '') + ' · secretary: ' + (mn.secretary.name || '—') + ' · version ' + mn.version;
    var tone = { draft: 'gold', review: 'indigo', published: 'green' }[mn.status];
    $('vhMinStatus').className = 'avp-chip avp-chip--' + tone; $('vhMinStatus').textContent = mn.status_label;
    $('vhMinSaved').textContent = '';
    var att = mn.attendance || {};
    var from = (mn.seeded_from || []).map(function (s) { return { agenda: 'agenda', arising: 'matters arising', transcript: 'the transcript', live: 'live attendance' }[s] || s; });
    var h = '';
    if (from.length) h += '<p class="vh-note">Drafted for you from ' + esc(from.join(', ')) + '. Check it, then add what was said.</p>';
    h += '<section class="vh-min-sec"><h3>Who was there <span class="vh-hint">' + (from.indexOf('live attendance') >= 0 ? 'from the live room' : 'mark each person') + '</span></h3><div class="vh-att">'
      + m.invitees.filter(function (p) { return p.id > 0; }).map(function (p) {
        var a = (m.answers.by || {})[p.id];
        return '<label>' + esc(p.name) + (a && a.answer === 'no' ? ' <span class="vh-src">sent apologies</span>' : '') + '<select data-att="' + p.id + '"' + (ro ? ' disabled' : '') + '>' + MARKS.map(function (k) { return '<option value="' + k[0] + '"' + ((att[p.id] || '') === k[0] ? ' selected' : '') + '>' + k[1] + '</option>'; }).join('') + '</select></label>';
      }).join('') + '</div></section>';
    h += '<section class="vh-min-sec"><h3>Summary <span class="vh-hint">Markdown works</span></h3><textarea id="mSummary"' + (ro ? ' disabled' : '') + '>' + esc(b.summary) + '</textarea>'
      + (ro ? '' : (hub && hub.integrations.assistant
          ? '<details style="margin-top:8px"' + (ed.words ? ' open' : '') + '><summary class="vh-note" style="cursor:pointer">' + (ed.words ? 'Turn what was said in the room into the minutes' : 'Paste rough notes and let the assistant sort them into the sections') + '</summary>'
            + (ed.words ? '<button type="button" class="avp-btn avp-btn--sq" id="mUseWords" style="margin:6px 0">Use what was said in the room</button>' : '')
            + '<textarea id="mRough" placeholder="Paste your notes or a transcript…"></textarea><button type="button" class="avp-btn avp-btn--sq" id="mTidy" style="margin-top:6px">Tidy into the minutes</button></details>'
          /* No assistant on this site: what the room heard can still go into the notes, as it was said. */
          : (ed.words ? '<button type="button" class="avp-btn avp-btn--sq" id="mWordsToNotes" style="margin-top:8px">Add what was said in the room to the notes</button>' : ''))) + '</section>';
    if (b.arising.length) h += '<section class="vh-min-sec"><h3>Matters arising <span class="vh-hint">open actions from earlier meetings</span></h3><ul class="vh-list vh-arising">'
      + b.arising.map(function (a, i) {
        return '<li data-ar="' + i + '"><span>' + esc(a.title) + (a.owner ? ' <span class="vh-note">(' + esc(a.owner) + (a.due ? ', due ' + esc(a.due) : '') + ')</span>' : '') + '</span>'
          + '<input data-f="update" value="' + esc(a.update || '') + '" placeholder="Update" aria-label="Update on ' + esc(a.title) + '"' + (ro ? ' disabled' : '') + '>'
          + '<label class="vh-note" style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-f="done" style="height:auto"' + (a.done ? ' checked' : '') + (ro ? ' disabled' : '') + '> done</label></li>';
      }).join('') + '</ul></section>';
    h += '<section class="vh-min-sec"><h3>Agenda and discussion</h3><ul class="vh-items" id="mAgenda">' + b.agenda.map(function (a, i) { return itemRow('agenda', i, a, ro); }).join('') + '</ul>' + (ro ? '' : '<button type="button" class="vh-add" data-add="agenda">+ Agenda item</button>') + '</section>';
    h += '<section class="vh-min-sec"><h3>Decisions</h3><ul class="vh-items" id="mDecisions">' + b.decisions.map(function (d, i) { return itemRow('decision', i, d, ro); }).join('') + '</ul>' + (ro ? '' : '<button type="button" class="vh-add" data-add="decision">+ Decision</button>') + '</section>';
    h += '<section class="vh-min-sec"><h3>Actions <span class="vh-hint">each becomes a tracked action for its owner when published</span></h3><ul class="vh-items" id="mActions">' + b.actions.map(function (a, i) { return itemRow('action', i, a, ro); }).join('') + '</ul>' + (ro ? '' : '<button type="button" class="vh-add" data-add="action">+ Action</button>') + '</section>';
    h += '<section class="vh-min-sec"><h3>Other notes</h3><textarea id="mNotes"' + (ro ? ' disabled' : '') + '>' + esc(b.notes) + '</textarea></section>';
    h += '<section class="vh-min-sec"><h3>Next meeting</h3><input id="mNext" value="' + esc(b.next_meeting) + '" placeholder="e.g. Saturday 9 November, 10am"' + (ro ? ' disabled' : '') + ' style="width:100%"></section>';
    $('vhMinBody').innerHTML = h;

    var f = '';
    f += '<a class="avp-btn avp-btn--sq" href="' + mn.print_url + '" target="_blank" rel="noopener">Print</a><a class="avp-btn avp-btn--sq" href="' + API + '?action=pdf&id=' + mn.id + '">PDF</a>';
    if (mn.can_write && mn.status === 'draft' && !mn.can_publish) f += '<button type="button" class="avp-btn avp-btn--ink" data-step="submit">Hand to the chair</button>';
    if (mn.can_publish && mn.status === 'review') f += '<button type="button" class="avp-btn" data-step="return">Send back</button>';
    if (mn.can_publish && mn.status !== 'published') f += '<button type="button" class="avp-btn avp-btn--ink" data-step="publish">Approve and publish</button>';
    if (mn.can_publish && mn.status === 'published') f += '<span class="vh-note">Change anything and publish again to amend — everybody is told.</span>';
    $('vhMinFoot').innerHTML = f;

    ed.sortables.forEach(function (s) { try { s.destroy(); } catch (e) {} }); ed.sortables = [];
    if (!ro && window.Sortable) ['mAgenda', 'mDecisions', 'mActions'].forEach(function (id) {
      ed.sortables.push(window.Sortable.create($(id), { handle: '.vh-grip', animation: 150, ghostClass: 'vh-ghost', onEnd: changed }));
    });
  }

  function collect() {
    var b = { summary: val('mSummary'), notes: val('mNotes'), next_meeting: val('mNext'), agenda: [], decisions: [], actions: [], arising: [] };
    $('mAgenda').querySelectorAll('.vh-item').forEach(function (li) { b.agenda.push({ item: li.querySelector('[data-f=item]').value, notes: li.querySelector('[data-f=notes]').value }); });
    $('mDecisions').querySelectorAll('.vh-item').forEach(function (li) { b.decisions.push(li.querySelector('[data-f=text]').value); });
    $('mActions').querySelectorAll('.vh-item').forEach(function (li) { b.actions.push({ task: li.querySelector('[data-f=task]').value, owner_id: +li.querySelector('[data-f=owner_id]').value, due: li.querySelector('[data-f=due]').value }); });
    document.querySelectorAll('#vhMinBody [data-ar]').forEach(function (li) {
      var a = ed.min.body.arising[+li.getAttribute('data-ar')];
      b.arising.push({ id: a.id, update: li.querySelector('[data-f=update]').value, done: li.querySelector('[data-f=done]').checked });
    });
    var att = {};
    document.querySelectorAll('#vhMinBody [data-att]').forEach(function (s) { att[s.getAttribute('data-att')] = s.value; });
    return { body: b, attendance: att };
  }
  function changed() {
    ed.dirty = true; $('vhMinSaved').textContent = 'Unsaved…';
    clearTimeout(ed.saveT); ed.saveT = setTimeout(save, 1500);
  }
  function save() {
    if (!ed.min || !ed.dirty) return Promise.resolve({ ok: true });
    clearTimeout(ed.saveT);
    var c = collect(); ed.dirty = false; $('vhMinSaved').textContent = 'Saving…';
    return api('minutes_save', { id: ed.min.id, body: c.body, attendance: c.attendance }).then(function (r) {
      if (!r.ok) { ed.dirty = true; $('vhMinSaved').textContent = r.error || 'Not saved'; return r; }
      var statusWas = ed.min.status;
      ed.min = r.minutes;
      $('vhMinSaved').textContent = 'Saved · v' + r.minutes.version;
      if (statusWas !== r.minutes.status) drawMinutes();
      return r;
    });
  }
  $('vhMinBody').addEventListener('input', changed);
  $('vhMinBody').addEventListener('change', changed);
  $('vhMinBody').addEventListener('click', function (ev) {
    var add = ev.target.closest('[data-add]'), del = ev.target.closest('[data-del]');
    if (add) {
      var k = add.getAttribute('data-add'), list = $({ agenda: 'mAgenda', decision: 'mDecisions', action: 'mActions' }[k]);
      list.insertAdjacentHTML('beforeend', itemRow(k, list.children.length, k === 'agenda' ? { item: '', notes: '' } : k === 'decision' ? '' : { task: '', owner_id: 0, due: '' }, false));
      list.lastElementChild.querySelector('input').focus(); changed();
    } else if (del) { del.closest('li').remove(); changed(); }
    else if (ev.target.id === 'mWordsToNotes') { var n = $('mNotes'); n.value = (n.value.trim() ? n.value.trim() + '\n\n' : '') + ed.words; changed(); n.focus(); toast('Added to Other notes — trim it to what matters.'); }
    else if (ev.target.id === 'mUseWords') { $('mRough').value = ed.words; $('mRough').focus(); }
    else if (ev.target.id === 'mTidy') {
      var notes = val('mRough'); if (!notes.trim()) return toast('Paste your notes first.', true);
      ev.target.disabled = true; ev.target.textContent = 'Tidying…';
      save().then(function () { return api('minutes_notes', { id: ed.min.id, notes: notes }); }).then(function (r) {
        ev.target.disabled = false; ev.target.textContent = 'Tidy into the minutes';
        if (!r.ok) return toast(r.error, true);
        ed.min = r.minutes; drawMinutes(); toast('Sorted into the sections — check them.');
      });
    }
  });
  $('vhMinFoot').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-step]'); if (!b) return;
    var step = b.getAttribute('data-step'), note = '';
    if (step === 'publish' && !confirm('Publish the minutes? Everybody invited is sent them, and each action goes to its owner.')) return;
    if (step === 'return') { note = prompt('What should the secretary change?', ''); if (note === null) return; }
    b.disabled = true;
    save().then(function (s) {
      if (s && s.ok === false) { b.disabled = false; return; }
      return api('minutes_' + step, { id: ed.min.id, note: note }).then(function (r) {
        b.disabled = false;
        if (!r.ok) return toast(r.error, true);
        ed.min = r.minutes; drawMinutes();
        toast(step === 'publish' ? (r.amended ? 'Amended and sent.' : 'Published — sent to everybody, ' + r.actions + ' action' + (r.actions === 1 ? '' : 's') + ' filed.') : step === 'submit' ? 'With the chair now.' : 'Sent back.');
      });
    });
  });

  /* ══ When the view opens ═══════════════════════════════════════════════ */

  if (location.hash === '#attendance') { tab = 'attendance'; try { history.replaceState(null, '', '#volunteer'); } catch (x) {} }
  document.addEventListener('portal:view', function (e) { if (e.detail && e.detail.view === 'volunteer') load(); });
  if (!view.hidden || location.hash === '#volunteer') load();
  /* Keep "coming up" and "live now" honest while the page sits open. */
  setInterval(function () { if (!view.hidden && !document.hidden && loaded && !$('vhRoom').open && !$('vhMin').open && !$('vhForm').open) load(); }, 60000);
  setInterval(function () { if ($('vhRoom').open) tickClock(); }, 1000);
})();
