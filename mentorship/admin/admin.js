/* ============================================================
   mentorship/admin/admin.js — Mentorship admin portal.
   Talks to /admin/api.php. Sign-in is the Mentorship admin's own
   email + password (login_pw); the role cookie scopes the API to
   mentorship actions only. No Super Admin surface lives here.
   ============================================================ */
(function () {
  'use strict';
  var API = '/admin/api.php';
  var csrf = '';
  var seg = '';            // '' all | 'org' | 'external'
  var current = 'overview';
  var cohortsCache = [];   // for the assign panel's cohort <select>
  var assign = { mentor: null, mentee: null };

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(msg, kind) {
    var t = document.createElement('div'); t.className = 'toast' + (kind ? ' ' + kind : '');
    t.textContent = msg; $('#toasts').appendChild(t);
    setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 300); }, 2600);
  }
  function when(s) { return esc(String(s || '').replace('T', ' ').slice(0, 16) || '—'); }

  function api(action, opts) {
    opts = opts || {};
    var headers = opts.headers || {};
    if (opts.method === 'POST') headers['X-CSRF-Token'] = csrf;
    return fetch(API + '?action=' + action + (opts.qs || ''), {
      method: opts.method || 'GET', headers: headers, body: opts.body, credentials: 'same-origin'
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Bad server response.' }; }); });
  }
  function get(action, qs) { return api(action, { qs: qs ? '&' + qs : '' }); }
  function post(action, payload) { return api(action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload || {}) }); }
  function segQ(extra) { return (seg ? 'segment=' + seg : '') + (extra ? (seg ? '&' : '') + extra : ''); }

  /* ── Auth ── (email + password only; Super Admin uses the main Studio) */
  function showApp(on) { $('#app').hidden = !on; $('#login').hidden = on; }
  function enter(d) {
    if (d.home && d.home.indexOf('/mentorship/') !== 0) { location.replace(d.home); return; }
    csrf = d.csrf || ''; showApp(true); route('overview');
  }
  // Configure the login screen from the session probe: offer Google when set up,
  // or explain the dead-end where someone is signed in but isn't a Mentorship admin.
  function setupLogin(d) {
    d = d || {};
    var fields = $('#loginFields'), note = $('#memberNote'), g = $('#googleBtn'), or = $('#loginOr');
    if (d.member) {
      fields.hidden = true; note.hidden = false;
      note.innerHTML = 'You’re signed in as <b>' + esc(d.member) + '</b>, but this account isn’t a Mentorship admin.<br>'
        + 'Ask a Super Admin for access, or <button type="button" class="linklike" id="memberSignout">sign out</button> to use a different account.';
      var so = $('#memberSignout');
      if (so) so.addEventListener('click', function () { fetch('/academy/api.php?action=logout', { method: 'POST', credentials: 'same-origin' }).finally(function () { location.reload(); }); });
    } else {
      fields.hidden = false; note.hidden = true;
      if (d.google) { g.href = '/auth/google/start?next=' + encodeURIComponent('/mentorship/admin/'); g.hidden = false; or.hidden = false; }
      else { g.hidden = true; or.hidden = true; }
    }
  }
  $('#loginForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = e.target.querySelector('button'); btn.disabled = true;
    post('login_pw', { email: ($('#email').value || '').trim(), password: $('#password').value }).then(function (d) {
      btn.disabled = false;
      if (d && d.ok) enter(d);
      else $('#loginMsg').textContent = (d && d.error) || 'Could not sign in.';
    }).catch(function () { btn.disabled = false; $('#loginMsg').textContent = 'Network error.'; });
  });
  $('#logout').addEventListener('click', function () {
    post('logout', {}).finally(function () { csrf = ''; showApp(false); });
  });

  /* ── Theme + mobile nav ── */
  $('#themeToggle').addEventListener('click', function () {
    var d = document.documentElement, next = d.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    d.setAttribute('data-theme', next); try { localStorage.setItem('av.theme', next); } catch (e) {}
  });
  function closeNav() { $('#app').classList.remove('nav-open'); $('#scrim').hidden = true; }
  $('#hamburger').addEventListener('click', function () { var o = $('#app').classList.toggle('nav-open'); $('#scrim').hidden = !o; });
  $('#scrim').addEventListener('click', closeNav);

  /* ── Routing ── */
  var META = {
    overview:  ['Overview', 'Mentor network at a glance.'],
    pairings:  ['Pairings', 'Mentor–mentee matches and their status.'],
    mentors:   ['Mentors', 'Approve applications and manage mentor profiles.'],
    cohorts:   ['Cohorts', 'Rounds and programmes that group pairings.'],
    attention: ['Needs attention', 'Active pairings that have gone quiet.'],
    activity:  ['Activity', 'Recent mentorship actions.']
  };
  function route(view) {
    current = view;
    $$('.nav-item').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-view') === view); });
    $$('.view').forEach(function (v) { v.hidden = v.id !== 'v-' + view; });
    var m = META[view] || ['', '']; $('#viewTitle').textContent = m[0]; $('#viewSub').textContent = m[1];
    closeNav();
    load(view);
  }
  $$('.nav-item').forEach(function (b) { b.addEventListener('click', function () { route(b.getAttribute('data-view')); }); });
  $$('[data-go]').forEach(function (b) {
    b.addEventListener('click', function () { var v = b.getAttribute('data-go'); route(v); if (b.getAttribute('data-assign')) openAssign(true); });
  });

  /* ── Segment toggle ── */
  $$('#segToggle .seg-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      $$('#segToggle .seg-btn').forEach(function (x) { x.classList.remove('is-active'); });
      b.classList.add('is-active'); seg = b.getAttribute('data-seg') || '';
      refreshBadges(); load(current);
    });
  });

  function load(view) {
    if (view === 'overview') return loadOverview();
    if (view === 'pairings') return loadPairings();
    if (view === 'mentors') return loadMentors();
    if (view === 'cohorts') return loadCohorts();
    if (view === 'attention') return loadAttention();
    if (view === 'activity') return loadActivity();
  }

  /* ── Overview ── */
  function sum(o) { o = o || {}; return (o.org || 0) + (o.external || 0); }
  function pick(o) { o = o || {}; return seg ? (o[seg] || 0) : sum(o); }
  function loadOverview() {
    var grid = $('#mtStatGrid'); grid.innerHTML = '';
    get('mentorship_stats').then(function (d) {
      if (!d || !d.ok) { grid.innerHTML = '<p class="muted">Could not load stats.</p>'; return; }
      var s = d.stats, inactive = s.inactive || 0;
      var cards = [
        ['Active pairings', pick(s.pairs_active), 'currently mentoring', ''],
        ['Pending requests', pick(s.pairs_pending), 'awaiting a mentor’s reply', ''],
        ['Mentors approved', pick(s.mentors_approved), 'accepting mentees', ''],
        ['Mentors pending', pick(s.mentors_pending), 'awaiting your review', ''],
        ['Sessions logged', s.sessions || 0, 'all time', ''],
        ['Needs attention', inactive, 'no session in 21 days', inactive > 0 ? 'alert' : '']
      ];
      grid.innerHTML = cards.map(function (c) {
        return '<div class="stat ' + c[3] + '"><div class="stat-num">' + esc(c[1]) + '</div><div class="stat-label">' + esc(c[0]) + '</div><div class="stat-sub">' + esc(c[2]) + '</div></div>';
      }).join('');
      refreshBadges(s);
    });
  }
  function refreshBadges(s) {
    var apply = function (st) {
      var ap = pick(st.mentors_pending), at = st.inactive || 0;
      var ba = $('#badgeApprovals'), bt = $('#badgeAttn');
      ba.hidden = !ap; ba.textContent = ap; bt.hidden = !at; bt.textContent = at;
    };
    if (s) apply(s); else get('mentorship_stats').then(function (d) { if (d && d.ok) apply(d.stats); });
  }

  /* ── Pairings ── */
  function statusBadge(st) { return '<span class="badge ' + esc(st) + '">' + esc(st) + '</span>'; }
  function poolBadge(sg) { return '<span class="badge ' + (sg === 'org' ? 'org' : 'external') + '">' + (sg === 'org' ? 'Org' : 'External') + '</span>'; }
  function loadPairings() {
    var body = $('#mtPairBody'); body.innerHTML = '<tr><td colspan="7" class="empty">Loading…</td></tr>';
    var status = $('#pairStatus').value, q = ($('#pairQ').value || '').trim();
    $('#exportBtn').href = API + '?action=mentorship_export' + (seg ? '&segment=' + seg : '');
    get('mentorship_pairings', segQ((status ? 'status=' + encodeURIComponent(status) : '') + (q ? '&q=' + encodeURIComponent(q) : ''))).then(function (d) {
      var rows = (d && d.pairings) || [];
      if (!rows.length) { body.innerHTML = '<tr><td colspan="7" class="empty">No pairings here yet.</td></tr>'; return; }
      body.innerHTML = rows.map(function (p) {
        var acts = [];
        if (p.status === 'active') { acts.push(btn('end', p.id, 'End', 'btn-danger')); acts.push(btn('reassign', p.id, 'Reassign', 'btn-outline')); }
        else if (p.status === 'pending') { acts.push(btn('activate', p.id, 'Activate', 'btn-outline')); acts.push(btn('end', p.id, 'Decline', 'btn-danger')); }
        else if (p.status === 'ended' || p.status === 'declined') { acts.push(btn('activate', p.id, 'Re-open', 'btn-outline')); }
        return '<tr data-seg="' + esc(p.segment) + '">'
          + '<td><div class="c-title">' + esc(p.mentor.name) + '</div><div class="c-meta">' + esc(p.mentor.email) + '</div></td>'
          + '<td><div class="c-title">' + esc(p.mentee.name) + '</div><div class="c-meta">' + esc(p.mentee.email) + '</div></td>'
          + '<td>' + poolBadge(p.segment) + '</td>'
          + '<td>' + statusBadge(p.status) + (p.programme ? '<div class="c-meta">' + esc(p.programme) + '</div>' : '') + '</td>'
          + '<td class="num">' + esc(p.sessions) + '</td>'
          + '<td class="c-meta">' + when(p.last_session) + '</td>'
          + '<td><div class="row-actions">' + acts.join('') + '</div></td></tr>';
      }).join('');
      $$('#mtPairBody [data-act]').forEach(function (b) { b.addEventListener('click', onPairAction); });
    });
  }
  function btn(act, id, label, cls) { return '<button class="btn btn-sm ' + cls + '" data-act="' + act + '" data-id="' + id + '">' + esc(label) + '</button>'; }
  function onPairAction(e) {
    var act = e.target.getAttribute('data-act'), id = +e.target.getAttribute('data-id');
    if (act === 'end') { if (!confirm('End / decline this pairing?')) return; setStatus(id, 'ended'); }
    else if (act === 'activate') setStatus(id, 'active');
    else if (act === 'reassign') openReassign(e.target.closest('tr'), id);
  }
  function setStatus(id, status) {
    post('mentorship_set_status', { id: id, status: status }).then(function (d) {
      if (d && d.ok) { toast('Pairing updated', 'ok'); loadPairings(); refreshBadges(); }
      else toast((d && d.error) || 'Update failed', 'err');
    });
  }
  function openReassign(tr, id) {
    var seg2 = tr.getAttribute('data-seg');
    var cell = tr.lastElementChild;
    cell.innerHTML = '<div class="search-wrap" style="min-width:200px"><input type="search" placeholder="New approved mentor…" /></div><div class="pick"></div>';
    var input = cell.querySelector('input'), box = cell.querySelector('.pick');
    input.focus();
    input.addEventListener('input', debounce(function () {
      var q = input.value.trim(); if (!q) { box.className = 'pick'; box.innerHTML = ''; return; }
      get('mentorship_mentors', 'approval=approved&segment=' + encodeURIComponent(seg2) + '&q=' + encodeURIComponent(q)).then(function (d) {
        var ms = (d && d.mentors) || [];
        box.className = 'pick open';
        box.innerHTML = ms.length ? ms.map(function (m) {
          return '<div class="pick-row" data-id="' + m.user_id + '"><b>' + esc(m.name) + '</b><span>' + esc(m.email) + ' · ' + m.active_mentees + '/' + m.capacity + ' mentees</span></div>';
        }).join('') : '<div class="pick-row"><span>No approved mentors match.</span></div>';
        $$('.pick-row[data-id]', box).forEach(function (r) {
          r.addEventListener('click', function () {
            post('mentorship_reassign', { id: id, mentor_id: +r.getAttribute('data-id') }).then(function (res) {
              if (res && res.ok) { toast('Reassigned', 'ok'); loadPairings(); }
              else toast((res && res.error) || 'Could not reassign', 'err');
            });
          });
        });
      });
    }, 220));
  }

  /* ── Assign panel ── */
  function openAssign(on) {
    $('#assignPanel').hidden = !on;
    $('#assignSegNote').textContent = seg ? '· ' + (seg === 'org' ? 'Org pool' : 'External pool') : '· pick a pool below via the toggle';
    if (on) { assign = { mentor: null, mentee: null }; $('#aMentorQ').value = ''; $('#aMenteeQ').value = ''; $('#assignMsg').textContent = ''; populateCohortSelect(); validateAssign(); }
  }
  $('#assignBtn').addEventListener('click', function () { openAssign($('#assignPanel').hidden); });
  $('#assignCancel').addEventListener('click', function () { openAssign(false); });
  function bindPicker(inputSel, boxSel, kind) {
    var input = $(inputSel), box = $(boxSel);
    input.addEventListener('input', debounce(function () {
      var q = input.value.trim(); if (!q) { box.className = 'pick'; box.innerHTML = ''; return; }
      var req = kind === 'mentor'
        ? get('mentorship_mentors', 'approval=approved&' + segQ('q=' + encodeURIComponent(q)))
        : get('mentorship_find_users', segQ('q=' + encodeURIComponent(q)));
      req.then(function (d) {
        var list = kind === 'mentor' ? (d.mentors || []) : (d.users || []);
        box.className = 'pick open';
        box.innerHTML = list.length ? list.map(function (m) {
          var id = kind === 'mentor' ? m.user_id : m.id;
          var sub = kind === 'mentor' ? (m.email + ' · ' + m.active_mentees + '/' + m.capacity + ' mentees') : (m.email + ' · ' + (m.segment === 'org' ? 'Org' : 'External'));
          return '<div class="pick-row" data-id="' + id + '" data-name="' + esc(m.name) + '"><b>' + esc(m.name) + '</b><span>' + esc(sub) + '</span></div>';
        }).join('') : '<div class="pick-row"><span>No matches.</span></div>';
        $$('.pick-row[data-id]', box).forEach(function (r) {
          r.addEventListener('click', function () {
            assign[kind] = { id: +r.getAttribute('data-id'), name: r.getAttribute('data-name') };
            input.value = r.getAttribute('data-name'); box.className = 'pick'; box.innerHTML = ''; validateAssign();
          });
        });
      });
    }, 220));
  }
  function validateAssign() { $('#assignSave').disabled = !(assign.mentor && assign.mentee); }
  $('#assignSave').addEventListener('click', function () {
    if (!assign.mentor || !assign.mentee) return;
    $('#assignMsg').textContent = '';
    post('mentorship_assign', { mentor_id: assign.mentor.id, mentee_id: assign.mentee.id, cohort_id: +$('#aCohort').value || 0, programme: $('#aProgramme').value.trim() }).then(function (d) {
      if (d && d.ok) { toast('Pairing created', 'ok'); openAssign(false); route('pairings'); }
      else $('#assignMsg').textContent = (d && d.error) || 'Could not create the pairing.';
    });
  });
  function populateCohortSelect() {
    var sel = $('#aCohort'); sel.innerHTML = '<option value="0">— Ongoing (no cohort) —</option>';
    cohortsCache.filter(function (c) { return c.status === 'open' && (!seg || c.segment === seg); })
      .forEach(function (c) { var o = document.createElement('option'); o.value = c.id; o.textContent = c.name; sel.appendChild(o); });
  }

  /* ── Mentors ── */
  function loadMentors() {
    var body = $('#mtMentorBody'); body.innerHTML = '<tr><td colspan="6" class="empty">Loading…</td></tr>';
    var ap = $('#mentorApproval').value, q = ($('#mentorQ').value || '').trim();
    get('mentorship_mentors', segQ((ap ? 'approval=' + ap : '') + (q ? '&q=' + encodeURIComponent(q) : ''))).then(function (d) {
      var rows = (d && d.mentors) || [];
      if (!rows.length) { body.innerHTML = '<tr><td colspan="6" class="empty">No mentors here yet.</td></tr>'; return; }
      body.innerHTML = rows.map(function (m) {
        var acts = [];
        if (m.approval !== 'approved') acts.push(btn2('approve', m.user_id, 'Approve', 'btn-primary'));
        if (m.approval !== 'declined') acts.push(btn2('decline', m.user_id, 'Decline', 'btn-danger'));
        return '<tr>'
          + '<td><div class="c-title">' + esc(m.name) + '</div><div class="c-meta">' + esc(m.email) + (m.headline ? ' · ' + esc(m.headline) : '') + '</div></td>'
          + '<td>' + poolBadge(m.segment) + '</td>'
          + '<td class="c-meta">' + (esc(m.focus) || '—') + '</td>'
          + '<td class="num">' + m.active_mentees + ' / ' + m.capacity + '</td>'
          + '<td>' + statusBadge(m.approval === 'approved' ? 'active' : (m.approval === 'declined' ? 'declined' : 'pending')) + '</td>'
          + '<td><div class="row-actions">' + acts.join('') + '</div></td></tr>';
      }).join('');
      $$('#mtMentorBody [data-act]').forEach(function (b) { b.addEventListener('click', onMentorAction); });
    });
  }
  function btn2(act, uid, label, cls) { return '<button class="btn btn-sm ' + cls + '" data-act="' + act + '" data-uid="' + uid + '">' + esc(label) + '</button>'; }
  function onMentorAction(e) {
    var act = e.target.getAttribute('data-act'), uid = +e.target.getAttribute('data-uid');
    var to = act === 'approve' ? 'mentorship_approve' : 'mentorship_decline';
    if (act === 'decline' && !confirm('Decline this mentor? They won’t receive new mentees.')) return;
    post(to, { user_id: uid }).then(function (d) {
      if (d && d.ok) { toast(act === 'approve' ? 'Mentor approved' : 'Mentor declined', 'ok'); loadMentors(); refreshBadges(); }
      else toast((d && d.error) || 'Action failed', 'err');
    });
  }
  $('#addMentorBtn').addEventListener('click', function () { var p = $('#addMentorPanel'); p.hidden = !p.hidden; });
  $('#addMentorCancel').addEventListener('click', function () { $('#addMentorPanel').hidden = true; });
  $('#addMentorSave').addEventListener('click', function () {
    $('#addMentorMsg').textContent = '';
    post('mentorship_add', { email: $('#amEmail').value.trim(), headline: $('#amHeadline').value.trim(), focus: $('#amFocus').value.trim(), capacity: +$('#amCapacity').value || 3 }).then(function (d) {
      if (d && d.ok) { toast('Mentor added', 'ok'); $('#addMentorPanel').hidden = true; ['amEmail', 'amHeadline', 'amFocus'].forEach(function (i) { $('#' + i).value = ''; }); loadMentors(); }
      else $('#addMentorMsg').textContent = (d && d.error) || 'Could not add mentor.';
    });
  });

  /* ── Cohorts ── */
  function loadCohorts() {
    var body = $('#mtCohortBody'); body.innerHTML = '<tr><td colspan="6" class="empty">Loading…</td></tr>';
    get('mentorship_cohorts', segQ()).then(function (d) {
      cohortsCache = (d && d.cohorts) || [];
      if (!cohortsCache.length) { body.innerHTML = '<tr><td colspan="6" class="empty">No cohorts yet — create one to group pairings into rounds.</td></tr>'; return; }
      body.innerHTML = cohortsCache.map(function (c) {
        var next = c.status === 'open' ? 'closed' : (c.status === 'closed' ? 'archived' : 'open');
        var label = c.status === 'open' ? 'Close' : (c.status === 'closed' ? 'Archive' : 'Re-open');
        return '<tr>'
          + '<td><div class="c-title">' + esc(c.name) + '</div>' + (c.starts ? '<div class="c-meta">from ' + esc(c.starts) + '</div>' : '') + '</td>'
          + '<td>' + poolBadge(c.segment) + '</td>'
          + '<td class="c-meta">' + (esc(c.programme) || '—') + '</td>'
          + '<td class="num">' + c.pairs + '</td>'
          + '<td>' + statusBadge(c.status === 'open' ? 'active' : (c.status === 'archived' ? 'declined' : 'pending')) + '</td>'
          + '<td><div class="row-actions"><button class="btn btn-sm btn-outline" data-co="' + c.id + '" data-to="' + next + '">' + label + '</button></div></td></tr>';
      }).join('');
      $$('#mtCohortBody [data-co]').forEach(function (b) {
        b.addEventListener('click', function () {
          post('mentorship_cohort_status', { id: +b.getAttribute('data-co'), status: b.getAttribute('data-to') }).then(function (d) {
            if (d && d.ok) { toast('Cohort updated', 'ok'); loadCohorts(); } else toast((d && d.error) || 'Failed', 'err');
          });
        });
      });
    });
  }
  $('#addCohortBtn').addEventListener('click', function () { var p = $('#cohortPanel'); p.hidden = !p.hidden; });
  $('#cohortCancel').addEventListener('click', function () { $('#cohortPanel').hidden = true; });
  $('#cohortSave').addEventListener('click', function () {
    $('#cohortMsg').textContent = '';
    post('mentorship_cohort_create', { name: $('#coName').value.trim(), segment: $('#coSegment').value, programme: $('#coProgramme').value.trim(), starts: $('#coStarts').value }).then(function (d) {
      if (d && d.ok) { toast('Cohort created', 'ok'); $('#cohortPanel').hidden = true; $('#coName').value = ''; $('#coProgramme').value = ''; loadCohorts(); }
      else $('#cohortMsg').textContent = (d && d.error) || 'Could not create cohort.';
    });
  });

  /* ── Needs attention ── */
  function loadAttention() {
    var body = $('#mtAttnBody'); body.innerHTML = '<tr><td colspan="6" class="empty">Loading…</td></tr>';
    get('mentorship_inactive', 'days=21').then(function (d) {
      var rows = (d && d.pairs) || [];
      if (seg) rows = rows.filter(function (r) { return r.segment === seg; });
      if (!rows.length) { body.innerHTML = '<tr><td colspan="6" class="empty">Nothing needs attention — every active pairing has met recently. 🎉</td></tr>'; return; }
      body.innerHTML = rows.map(function (r) {
        return '<tr>'
          + '<td class="c-title">' + esc(r.mentor) + '</td>'
          + '<td class="c-title">' + esc(r.mentee) + '</td>'
          + '<td>' + poolBadge(r.segment) + '</td>'
          + '<td class="c-meta">' + (r.last_session ? when(r.last_session) : 'never met') + '</td>'
          + '<td class="c-meta">' + when(r.since) + '</td>'
          + '<td><div class="row-actions">' + btn('end', r.id, 'End pairing', 'btn-danger') + '</div></td></tr>';
      }).join('');
      $$('#mtAttnBody [data-act]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('End this stalled pairing?')) return;
          setStatus(+b.getAttribute('data-id'), 'ended');
          setTimeout(loadAttention, 250);
        });
      });
    });
  }

  /* ── Activity (scoped server-side to mentorship actions) ── */
  var ALABEL = {
    mentor_approved: 'Mentor approved', mentor_declined: 'Mentor declined', mentor_added: 'Mentor added',
    pair_assigned: 'Pairing assigned', pair_reassigned: 'Pairing reassigned', pair_active: 'Pairing re-opened',
    pair_ended: 'Pairing ended', pair_pending: 'Pairing set pending', pair_declined: 'Pairing declined',
    cohort_created: 'Cohort created', undo: 'Action undone'
  };
  function loadActivity() {
    var body = $('#mtActBody'); body.innerHTML = '<tr><td colspan="5" class="empty">Loading…</td></tr>';
    get('activity').then(function (d) {
      var rows = (d && d.entries) || [];
      if (!rows.length) { body.innerHTML = '<tr><td colspan="5" class="empty">No mentorship activity recorded yet.</td></tr>'; return; }
      body.innerHTML = rows.map(function (r) {
        var undo = r.can_undo ? '<button class="btn btn-sm btn-outline" data-undo="' + r.id + '">' + esc(r.undo_label || 'Undo') + '</button>' : (r.undone ? '<span class="badge neutral">undone</span>' : '');
        return '<tr>'
          + '<td class="c-meta" style="white-space:nowrap">' + when(r.created_at) + '</td>'
          + '<td>' + esc(ALABEL[r.action] || r.action) + '</td>'
          + '<td class="c-meta">' + (esc(r.target) || '—') + '</td>'
          + '<td class="c-meta">' + (esc(r.detail) || '—') + '</td>'
          + '<td><div class="row-actions">' + undo + '</div></td></tr>';
      }).join('');
      $$('#mtActBody [data-undo]').forEach(function (b) {
        b.addEventListener('click', function () {
          post('activity_undo', { id: +b.getAttribute('data-undo') }).then(function (d) {
            if (d && d.ok) { toast('Reverted', 'ok'); loadActivity(); refreshBadges(); } else toast((d && d.error) || 'Could not undo', 'err');
          });
        });
      });
    });
  }

  /* ── Filters (debounced) ── */
  function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }
  $('#pairQ').addEventListener('input', debounce(loadPairings, 250));
  $('#pairStatus').addEventListener('change', loadPairings);
  $('#mentorQ').addEventListener('input', debounce(loadMentors, 250));
  $('#mentorApproval').addEventListener('change', loadMentors);
  bindPicker('#aMentorQ', '#aMentorPick', 'mentor');
  bindPicker('#aMenteeQ', '#aMenteePick', 'mentee');

  /* ── Boot ── */
  get('session').then(function (d) {
    if (d && d.ok) enter(d);
    else { setupLogin(d); showApp(false); }
  }).catch(function () { showApp(false); });
})();
