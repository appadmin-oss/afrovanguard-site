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

  function load() {
    if (busy) return; busy = true;
    api('hub').then(function (r) {
      busy = false;
      if (!r.ok) { $('vhUpcoming').innerHTML = '<li class="avp-empty">' + esc(r.error || 'Could not load the hub.') + '</li>'; return; }
      hub = r; loaded = true; draw();
    });
  }

  function draw() {
    var c = hub.counts;
    $('vhKRsvp').textContent = c.rsvp; $('vhKMin').textContent = c.minutes; $('vhKAct').textContent = c.actions;
    $('vhKOverChip').hidden = !c.overdue; $('vhKActS').textContent = c.overdue ? c.overdue + ' overdue' : 'from meetings';
    $('vhKHours').textContent = hub.hours.month; $('vhKHoursS').textContent = hub.hours.year + ' this year';
    $('vhSchedule').hidden = !hub.teams.length && !hub.me.can_run_teams;
    $('vhNewTeam').hidden = !hub.me.can_run_teams;

    var live = hub.upcoming.filter(function (m) { return m.room; });
    $('vhLive').hidden = !live.length;
    $('vhLive').innerHTML = live.map(function (m) {
      return '<div class="vh-live"><span class="vh-pulse" aria-hidden="true"></span><span class="vh-live-t"><b>' + esc(m.title) + '</b><br>' + (m.live ? 'On now' : 'Starting ' + esc(m.when)) + (m.team ? ' · ' + esc(m.team) : '') + '</span>'
        + (m.meet_url ? '<a class="avp-btn avp-btn--paper" href="' + esc(m.meet_url) + '" target="_blank" rel="noopener">Join the call</a>' : '')
        + '<button type="button" class="avp-btn avp-btn--paper" data-room="' + m.id + '" data-occ="' + esc(m.occurrence) + '">Open the live room</button></div>';
    }).join('');

    var up = hub.upcoming;
    $('vhUpN').hidden = !up.length; $('vhUpN').textContent = up.length;
    $('vhUpcoming').innerHTML = up.length ? up.map(function (m) {
      var seg = '<span class="vh-seg" role="group" aria-label="Are you coming to ' + esc(m.title) + '?">'
        + [['yes', 'Coming'], ['maybe', 'Maybe'], ['no', 'Can’t']].map(function (a) {
          return '<button type="button" data-rsvp="' + a[0] + '" data-id="' + m.id + '" data-occ="' + esc(m.occurrence) + '" aria-pressed="' + (m.rsvp === a[0]) + '">' + a[1] + '</button>';
        }).join('') + '</span>';
      var sub = esc(m.when) + ' · ' + m.duration_min + ' min' + (m.frequency_label !== 'One-off' ? ' · ' + esc(m.frequency_label) : '') + (m.team ? ' · ' + esc(m.team) : '')
        + '<br>' + m.coming + ' coming' + (m.apologies ? ' · ' + m.apologies + ' apolog' + (m.apologies === 1 ? 'y' : 'ies') : '') + ' · ' + m.invited + ' invited';
      var btns = (m.live ? '' : seg)
        + (m.room ? '<button type="button" class="avp-btn avp-btn--sq avp-btn--ink" data-room="' + m.id + '" data-occ="' + esc(m.occurrence) + '">Live room</button>' : '')
        + (m.meet_url ? '<a class="avp-btn avp-btn--sq" href="' + esc(m.meet_url) + '" target="_blank" rel="noopener">Join</a>' : '')
        + '<a class="avp-btn avp-btn--sq" href="' + API + '?action=ics&meeting_id=' + m.id + '" title="Add to your calendar">.ics</a>';
      return row(esc(m.title), sub, btns);
    }).join('') : '<li class="avp-empty">No meetings coming up.' + (hub.teams.length ? ' Schedule one for your team.' : '') + '</li>';

    var todo = hub.to_write.map(function (m) { return { m: m, kind: 'write' }; }).concat(hub.to_approve.map(function (m) { return { m: m, kind: 'approve' }; }));
    $('vhMinN').hidden = !todo.length; $('vhMinN').textContent = todo.length + ' to do';
    $('vhMinutes').innerHTML = todo.length ? todo.map(function (t) {
      var m = t.m;
      var label = t.kind === 'approve' ? 'Read and approve' : (m.minutes_id ? 'Continue' : 'Write the minutes');
      return row(esc(m.title), esc(m.when) + (m.team ? ' · ' + esc(m.team) : '') + ' · ' + (t.kind === 'approve' ? 'with you to approve' : (m.minutes_id ? 'draft' : 'not started')),
        '<button type="button" class="avp-btn avp-btn--sq' + (t.kind === 'approve' ? ' avp-btn--ink' : '') + '" data-minutes="' + m.id + '" data-occ="' + esc(m.occurrence) + '">' + label + '</button>');
    }).join('') : '<li class="avp-empty">No minutes waiting on you.</li>';
    $('vhPublished').innerHTML = hub.published.length ? hub.published.map(function (p) {
      return row(esc(p.title), esc(p.when) + (p.team ? ' · ' + esc(p.team) : ''),
        '<button type="button" class="avp-btn avp-btn--sq" data-minutes="' + p.id + '" data-occ="' + esc(p.occurrence) + '">Read</button>'
        + '<a class="avp-btn avp-btn--sq" href="' + API + '?action=pdf&id=' + p.minutes_id + '">PDF</a>');
    }).join('') : '<li class="avp-empty">Published minutes appear here.</li>';

    $('vhActions').innerHTML = hub.actions.length ? hub.actions.map(function (a) {
      return row(esc(a.title), (a.due ? '<span class="' + (a.overdue ? 'is-overdue' : '') + '">Due ' + esc(day(a.due)) + (a.overdue ? ' — overdue' : '') + '</span>' : '') + (a.from ? ' · ' + esc(a.from) : ''),
        '<button type="button" class="avp-btn avp-btn--sq" data-done="' + a.id + '">Done</button>');
    }).join('') : '<li class="avp-empty">No open actions. 🎉</li>';

    var teams = hub.me.can_run_teams ? hub.all_teams : hub.teams;
    $('vhTeams').innerHTML = teams.length ? teams.map(function (t) {
      var lead = t.members.filter(function (p) { return p.role === 'lead'; }).map(function (p) { return p.name; }).join(', ');
      return row(esc(t.name), t.members.length + ' member' + (t.members.length === 1 ? '' : 's') + (lead ? ' · led by ' + esc(lead) : '') + (t.my_role ? ' · you: ' + esc(t.my_role) : ''),
        '<button type="button" class="avp-btn avp-btn--sq" data-team="' + t.id + '">' + (t.can_manage ? 'Manage' : 'Open') + '</button>');
    }).join('') : '<li class="avp-empty">You are not on a team yet. A coordinator adds you.</li>';

    var h = hub.hours;
    $('vhHours').innerHTML = '<div class="vh-hours-n"><div><b class="av-num">' + h.month + '</b><span>this month</span></div><div><b class="av-num">' + h.year + '</b><span>this year</span></div><div><b class="av-num">' + h.total + '</b><span>in all</span></div></div>'
      + '<p class="vh-note">Meetings you attend count by themselves once the minutes are out. Log anything else.</p>'
      + (h.recent.length ? '<ul class="vh-hours-list">' + h.recent.map(function (r) {
        return '<li><span>' + esc(r.activity) + '</span><span class="vh-note">' + esc(r.day) + ' · ' + r.hours + 'h</span>' + (r.source === 'manual' ? '<button type="button" class="vh-x" data-unhours="' + r.id + '" aria-label="Remove ' + esc(r.activity) + '">×</button>' : '') + '</li>';
      }).join('') + '</ul>' : '');
  }

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
    } else if (b.hasAttribute('data-team')) openTeam(+b.getAttribute('data-team'));
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
    var f = d.querySelector('input, select, textarea'); if (f) f.focus();
  }
  function val(id) { var e = $(id); return e ? e.value : ''; }

  $('vhLogHours').addEventListener('click', function () {
    form('Log hours', '<label>What you did<input id="fhAct" maxlength="200" required placeholder="e.g. Outreach at Egbeda"></label>'
      + '<label>Day<input id="fhDay" type="date" max="' + new Date().toISOString().slice(0, 10) + '" value="' + new Date().toISOString().slice(0, 10) + '"></label>'
      + '<label>Hours<input id="fhH" type="number" min="0.25" max="16" step="0.25" value="2" required></label>', 'Log', function (done) {
      api('hours', { activity: val('fhAct'), day: val('fhDay'), hours: val('fhH') }).then(done);
    });
  });
  $('vhNewTeam').addEventListener('click', function () {
    form('New team', '<label>Name<input id="ftName" maxlength="120" required></label><label class="avp-form-wide" style="justify-self:stretch">What it does<input id="ftPur" maxlength="400"></label>', 'Create', function (done) {
      api('team_create', { name: val('ftName'), purpose: val('ftPur') }).then(function (r) { done(r); if (r.ok) openTeam(r.id); });
    });
  });
  $('vhSchedule').addEventListener('click', function () {
    var teams = hub.me.can_run_teams ? hub.all_teams : hub.teams;
    var d = new Date(Date.now() + 86400000); d.setMinutes(0, 0, 0);
    var local = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    form('Schedule a team meeting',
      '<label>Team<select id="fsTeam">' + teams.map(function (t) { return '<option value="' + t.id + '">' + esc(t.name) + '</option>'; }).join('') + '</select></label>'
      + '<label>Title<input id="fsTitle" maxlength="200" placeholder="Team meeting"></label>'
      + '<label>When<input id="fsWhen" type="datetime-local" value="' + local + '" required></label>'
      + '<label>How long<select id="fsDur"><option value="30">30 minutes</option><option value="45">45 minutes</option><option value="60" selected>1 hour</option><option value="90">1½ hours</option><option value="120">2 hours</option></select></label>'
      + '<label>Repeats<select id="fsFreq"><option value="once">Once</option><option value="weekly">Every week</option><option value="biweekly">Every 2 weeks</option><option value="monthly">Every month</option></select></label>'
      + '<label class="avp-form-wide" style="justify-self:stretch">Agenda — one item a line<textarea id="fsAgenda" rows="4"></textarea></label>'
      + '<label class="avp-form-wide" style="justify-self:stretch">Also invite (emails, optional)<input id="fsExtra" placeholder="guest@example.org"></label>'
      + '<label class="avp-form-wide" style="flex-direction:row;align-items:center;gap:8px"><input type="checkbox" id="fsRec" style="height:auto"> Send the notetaker to record and transcribe</label>',
      'Schedule and invite', function (done) {
        api('schedule', { team_id: +val('fsTeam'), title: val('fsTitle'), when: val('fsWhen'), duration: +val('fsDur'), frequency: val('fsFreq'), agenda: val('fsAgenda'), extra: val('fsExtra'), auto_record: $('fsRec').checked })
          .then(function (r) { done(r); if (r.ok) toast(r.warning || 'Scheduled — the team has been invited.'); });
      });
  });

  function openTeam(id) {
    api('team', null, 'id=' + id).then(function (r) {
      if (!r.ok) return toast(r.error, true);
      var t = r.team, hrs = {};
      r.hours.forEach(function (h) { hrs[h.id] = h.hours; });
      var html = '<p class="vh-note avp-form-wide" style="justify-self:stretch">' + esc(t.purpose || '') + '</p>'
        + '<ul class="vh-list avp-form-wide" style="justify-self:stretch">' + t.members.map(function (p) {
          return '<li class="vh-row" style="padding:8px 0"><span class="vh-row-t"><span class="vh-row-title">' + esc(p.name) + '</span><span class="vh-row-sub">' + esc(p.email) + ' · ' + (hrs[p.id] || 0) + 'h this month</span></span><span class="vh-row-btns">'
            + (t.can_manage ? '<select data-role="' + p.id + '" aria-label="Role of ' + esc(p.name) + '">' + ['lead', 'secretary', 'member'].map(function (x) { return '<option value="' + x + '"' + (p.role === x ? ' selected' : '') + '>' + x[0].toUpperCase() + x.slice(1) + '</option>'; }).join('') + '</select>'
              + '<button type="button" class="vh-x" data-rm="' + p.id + '" aria-label="Remove ' + esc(p.name) + '">×</button>' : '<span class="avp-chip avp-chip--sm">' + esc(p.role) + '</span>')
            + '</span></li>';
        }).join('') + '</ul>'
        + (t.can_manage ? '<label class="avp-form-wide" style="justify-self:stretch">Add somebody (their email)<input id="ftAdd" type="email" list="ftPeople" autocomplete="off"><datalist id="ftPeople"></datalist></label>' : '');
      form(t.name, html, t.can_manage ? 'Add' : 'Close', function (done) {
        if (!t.can_manage || !val('ftAdd')) { $('vhForm').close(); return; }
        api('team_member', { team_id: id, who: val('ftAdd'), role: 'member' }).then(function (x) { if (x.ok) { load(); openTeam(id); } else done(x); });
      });
      var body = $('vhFormBody');
      body.onchange = function (ev) {
        var s = ev.target.closest('[data-role]'); if (!s) return;
        var p = t.members.filter(function (m) { return m.id === +s.getAttribute('data-role'); })[0];
        api('team_member', { team_id: id, who: String(p.id), role: s.value }).then(function (x) { if (x.ok) { toast('Saved.'); openTeam(id); load(); } else toast(x.error, true); });
      };
      body.onclick = function (ev) {
        var b = ev.target.closest('[data-rm]'); if (!b) return;
        if (!confirm('Remove them from the team?')) return;
        api('team_remove', { team_id: id, user_id: +b.getAttribute('data-rm') }).then(function (x) { if (x.ok) { openTeam(id); load(); } else toast(x.error, true); });
      };
      var add = $('ftAdd'), st;
      if (add) add.addEventListener('input', function () {
        clearTimeout(st); var q = add.value.trim(); if (q.length < 2) return;
        st = setTimeout(function () { api('people', null, 'q=' + encodeURIComponent(q)).then(function (x) {
          $('ftPeople').innerHTML = (x.people || []).map(function (p) { return '<option value="' + esc(p.email) + '">' + esc(p.name) + '</option>'; }).join('');
        }); }, 200);
      });
    });
  }

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
      + (ro ? '' : '<details style="margin-top:8px"' + (ed.words ? ' open' : '') + '><summary class="vh-note" style="cursor:pointer">' + (ed.words ? 'Turn what was said in the room into the minutes' : 'Paste rough notes and let the assistant sort them into the sections') + '</summary>'
        + (ed.words ? '<button type="button" class="avp-btn avp-btn--sq" id="mUseWords" style="margin:6px 0">Use what was said in the room</button>' : '')
        + '<textarea id="mRough" placeholder="Paste your notes or a transcript…"></textarea><button type="button" class="avp-btn avp-btn--sq" id="mTidy" style="margin-top:6px">Tidy into the minutes</button></details>') + '</section>';
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

  document.addEventListener('portal:view', function (e) { if (e.detail && e.detail.view === 'volunteer') load(); });
  if (!view.hidden || location.hash === '#volunteer') load();
  /* Keep "coming up" and "live now" honest while the page sits open. */
  setInterval(function () { if (!view.hidden && !document.hidden && loaded && !$('vhRoom').open && !$('vhMin').open) load(); }, 60000);
  setInterval(function () { if ($('vhRoom').open) tickClock(); }, 1000);
})();
