/**
 * academy/ngv/courses-take.js — taking a course, from the member's side.
 *
 * The sibling of reading.js, and deliberately its twin: open a course from
 * the list, log the time you put in sitting by sitting, write what you made
 * of the whole thing, send it to be checked. Somebody who has learned the
 * book sheet should recognise every control here, down to the counters and
 * the wording of the status line.
 *
 * What differs is what a course IS — modules rather than chapters, a provider
 * and a link rather than an author, and a certificate that may be attached
 * and is never what the claim rests on.
 *
 * It posts to the NGV dashboard's own URL, not location.pathname: a member or
 * a volunteer sees this card in the portal, which has no handler of its own.
 */
(function () {
  'use strict';
  var modal = document.getElementById('coModal');
  var list = document.getElementById('courses');
  if (!modal || !list) return;

  var POST_TO = '/academy/ngv/dashboard.php';
  var courseId = 0, claim = {}, sits = [], sitId = 0, MOODS = {}, TODAY = '';
  var MIN = 320, MIN_TAKE = 60, MIN_MODULE = 60, MIN_SIT = {};
  var modules = 0, notes = [], groups = {};

  var $ = function (id) { return document.getElementById(id); };
  var el = {
    close: $('coClose'), title: $('coTitle'), status: $('coStatus'),
    cover: $('coCover'), where: $('coWhere'),
    started: $('coStarted'), finished: $('coFinished'),
    mods: $('coModules'), modList: $('coModuleList'),
    sitList: $('coSitList'), sitForm: $('coSitForm'), sitErr: $('coSitErr'),
    sitDay: $('coSitDay'), sitMins: $('coSitMins'), sitFrom: $('coSitFrom'), sitTo: $('coSitTo'),
    sitFromF: $('coSitFromF'), sitToF: $('coSitToF'),
    sitPlace: $('coSitPlace'), sitMood: $('coSitMood'),
    sitNotes: $('coSitNotes'), sitStance: $('coSitStance'), sitApply: $('coSitApply'),
    sitSave: $('coSitSave'), sitCancel: $('coSitCancel'), sitAdd: $('coSitAdd'),
    refl: $('coReflection'), reflCount: $('coReflCount'),
    take: $('coTakeaway'), takeCount: $('coTakeCount'),
    certHave: $('coCertHave'), certLink: $('coCertLink'), certClear: $('coCertClear'), certFile: $('coCertFile'),
    err: $('coErr'), draft: $('coSaveDraft'), submit: $('coSubmit')
  };

  function esc(x) {
    return String(x == null ? '' : x).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  /* The same length the server counts: whitespace collapsed, so forty blank
     lines are not a reflection. */
  function len(v) { return (v || '').replace(/\s+/g, ' ').trim().length; }

  function post(body) {
    return fetch(POST_TO, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.NGV_CSRF || '' },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () {
        throw new Error('The server did not answer properly. Are you still signed in?');
      }).then(function (j) {
        if (!r.ok || !j.ok) throw new Error(j.error || 'That did not work.');
        return j;
      });
    });
  }
  function showErr(box, m) {
    if (!box) return;
    if (!m) { box.hidden = true; box.textContent = ''; return; }
    box.textContent = m; box.hidden = false;
    box.scrollIntoView({ block: 'nearest' });
  }

  function fillSelect(sel, map, blank) {
    sel.innerHTML = '<option value="">' + esc(blank) + '</option>' + Object.keys(map).map(function (k) {
      return '<option value="' + esc(k) + '">' + esc(map[k]) + '</option>';
    }).join('');
  }
  function courseById(id) {
    var out = null;
    Object.keys(groups).forEach(function (g) {
      (groups[g].courses || []).forEach(function (c) { if (String(c.id) === String(id)) out = c; });
    });
    return out;
  }

  /* ── Counters: see the floor while writing, not on save ─────────────── */
  function moduleFields() { return Array.prototype.slice.call(el.modList.querySelectorAll('textarea')); }
  function counts() {
    var r = len(el.refl.value), t = len(el.take.value);
    moduleFields().forEach(function (f) {
      var c = f.parentNode.querySelector('.bk-count'), n = len(f.value);
      if (!c) return;
      c.textContent = n + ' / ' + MIN_MODULE;
      c.classList.toggle('short', n > 0 && n < MIN_MODULE);
    });
    el.reflCount.textContent = r + ' / ' + MIN;
    el.takeCount.textContent = t + ' / ' + MIN_TAKE;
    el.reflCount.classList.toggle('short', r < MIN);
    el.takeCount.classList.toggle('short', t < MIN_TAKE);
  }
  function sitCounts() {
    [el.sitNotes, el.sitStance, el.sitApply].forEach(function (t) {
      var out = modal.querySelector('.bk-count[data-for="' + t.id + '"]');
      if (!out) return;
      var n = len(t.value), min = +t.getAttribute('data-min') || 0;
      out.textContent = n < min ? n + ' / ' + min : String(n);
      out.classList.toggle('short', n < min);
    });
  }

  /* One box per module, keyed by number so what was typed survives a redraw. */
  function drawModules() {
    el.modList.innerHTML = '';
    for (var i = 0; i < modules; i++) {
      var lab = document.createElement('label');
      lab.className = 'bk-f bk-f--wide';
      var sp = document.createElement('span');
      sp.textContent = 'Module ' + (i + 1);
      var ta = document.createElement('textarea');
      ta.rows = 2; ta.maxLength = 1500; ta.value = notes[i] || '';
      ta.setAttribute('data-module', String(i));
      ta.setAttribute('aria-label', 'What module ' + (i + 1) + ' covered');
      ta.addEventListener('input', function (ev) {
        notes[+ev.target.getAttribute('data-module')] = ev.target.value;
        counts();
      });
      var c = document.createElement('span');
      c.className = 'bk-count';
      lab.appendChild(sp); lab.appendChild(ta); lab.appendChild(c);
      el.modList.appendChild(lab);
    }
    el.mods.hidden = modules === 0;
    /* No modules recorded for this course, no module range to ask for. */
    el.sitFromF.hidden = modules === 0;
    el.sitToF.hidden = modules === 0;
  }

  function drawSits(locked) {
    if (!sits.length) {
      el.sitList.innerHTML = '<p class="bk-sits-none">Nothing logged yet. Log each time you sit down with this course — '
        + 'it is the record of the work, and what the checker reads before the course counts.</p>';
      return;
    }
    el.sitList.innerHTML = sits.map(function (x) {
      return '<div class="bk-sit">'
        + '<div class="bk-sit-top"><span class="bk-sit-when">' + esc(x.day) + '</span>'
        + (x.covered ? '<span>· ' + esc(x.covered) + '</span>' : '')
        + '<span>· ' + esc(x.minutes) + ' min</span>'
        + '</div>'
        + '<div class="bk-sit-meta">' + esc(x.place) + (x.mood_label ? ' · ' + esc(x.mood_label) : '') + '</div>'
        + '<p>' + esc(x.notes) + '</p>'
        + '<p><b>Where I stand:</b> ' + esc(x.stance) + '</p>'
        + '<p><b>What I will do:</b> ' + esc(x.application) + '</p>'
        + (locked ? '' : '<button type="button" class="bk-sit-x" data-sit-edit="' + x.id + '">Edit</button>'
            + ' <button type="button" class="bk-sit-x" data-sit-drop="' + x.id + '">Remove</button>')
        + '</div>';
    }).join('');
  }

  /* Who checks this learner's work, in words they can act on — the same
     wording the book sheet uses, from the same page-level variable. */
  var CHECKER = window.NGV_CHECKER || 'your track lead';
  function setStatus(c) {
    if (!c || !c.status || c.status === 'draft') { el.status.hidden = true; el.status.className = 'bk-status'; return; }
    var m = {
      submitted: ['With ' + CHECKER + '. You cannot change it while they have it.', ''],
      verified: ['Verified and counted. Thank you.', 'is-ok'],
      resubmit: ['Sent back: ' + (c.review_note || '') + ' Put that right and send it again.', 'is-back'],
      rejected: ['Not accepted: ' + (c.review_note || '') + ' Speak to ' + CHECKER + ' if you think that is wrong.', 'is-back']
    }[c.status];
    if (!m) { el.status.hidden = true; return; }
    el.status.textContent = m[0];
    el.status.className = 'bk-status ' + m[1];
    el.status.hidden = false;
  }

  function lock(on) {
    [el.started, el.finished, el.refl, el.take, el.certFile]
      .concat(moduleFields()).forEach(function (f) { if (f) f.disabled = on; });
    el.draft.hidden = on; el.submit.hidden = on;
    el.sitAdd.hidden = on;
    el.certClear.hidden = on || !claim.certificate_url;
    if (on) el.sitForm.hidden = true;
  }

  /* ── Opening and closing ────────────────────────────────────────────── */
  var opener = null;
  function open(id) {
    courseId = +id;
    showErr(el.err, ''); showErr(el.sitErr, '');
    el.sitForm.hidden = true; el.sitAdd.hidden = false; sitId = 0;
    post({ course_action: 'get', course_id: courseId }).then(function (j) {
      groups = j.groups || {};
      MOODS = j.moods || {}; TODAY = j.today || '';
      MIN = j.min || MIN; MIN_TAKE = j.minTake || MIN_TAKE; MIN_MODULE = j.minModule || MIN_MODULE;
      MIN_SIT = j.minSit || {};
      fillSelect(el.sitMood, MOODS, 'Rather not say');
      if (MIN_SIT.minutes) el.sitMins.max = MIN_SIT.minutes;

      var c = courseById(courseId) || {};
      claim = j.claim || {};
      el.title.textContent = c.title || 'Course';
      el.where.innerHTML = (c.provider ? esc(c.provider) : 'Taught at the centre')
        + (c.url ? ' · <a href="' + esc(c.url) + '" target="_blank" rel="noopener noreferrer">open the course</a>' : '');
      el.cover.hidden = !c.cover_url;
      if (c.cover_url) el.cover.src = c.cover_url;

      modules = claim.modules > 0 ? claim.modules : (c.modules || 0);
      notes = (claim.module_notes_list || []).slice();
      drawModules();
      el.started.value = claim.started_on || '';
      el.finished.value = claim.finished_on || '';
      el.refl.value = claim.reflection || '';
      el.take.value = claim.takeaway || '';
      sits = claim.sessions || [];

      var locked = ['submitted', 'verified', 'rejected'].indexOf(String(claim.status || '')) >= 0;
      drawSits(locked); setStatus(claim);
      el.certHave.hidden = !claim.certificate_url;
      if (claim.certificate_url) el.certLink.href = claim.certificate_url;
      lock(locked);
      counts(); sitCounts();

      modal.hidden = false;
      document.body.style.overflow = 'hidden';
      el.close.focus();
    }).catch(function (e) {
      modal.hidden = false;
      document.body.style.overflow = 'hidden';
      showErr(el.err, e.message);
    });
  }
  function close() {
    modal.hidden = true; document.body.style.overflow = '';
    if (opener && document.contains(opener)) opener.focus();
  }

  list.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-course]') : null;
    if (!b) return;
    ev.preventDefault(); opener = b; open(b.getAttribute('data-course'));
  });
  el.close.addEventListener('click', close);
  modal.addEventListener('click', function (ev) { if (ev.target === modal) close(); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !modal.hidden) close(); });
  el.refl.addEventListener('input', counts);
  el.take.addEventListener('input', counts);
  [el.sitNotes, el.sitStance, el.sitApply].forEach(function (t) { t.addEventListener('input', sitCounts); });

  /* ── Saving the claim ───────────────────────────────────────────────── */
  function send(submit) {
    showErr(el.err, '');
    var b = submit ? el.submit : el.draft;
    b.disabled = true;
    post({
      course_action: submit ? 'submit' : 'save',
      course: {
        course_id: courseId, started_on: el.started.value, finished_on: el.finished.value,
        reflection: el.refl.value, takeaway: el.take.value,
        modules: modules, module_notes: moduleFields().map(function (f) { return f.value; })
      }
    }).then(function () { location.reload(); })
      .catch(function (e) { showErr(el.err, e.message); })
      .finally(function () { b.disabled = false; });
  }
  el.draft.addEventListener('click', function () { send(false); });
  el.submit.addEventListener('click', function () { send(true); });

  /* ── Sittings ───────────────────────────────────────────────────────── */
  function sitFormReset(x) {
    x = x || {};
    sitId = +x.id || 0;
    el.sitDay.value = x.day || TODAY; el.sitDay.max = TODAY;
    el.sitMins.value = x.minutes || '';
    el.sitFrom.value = x.module_from || '';
    el.sitTo.value = x.module_to || '';
    el.sitPlace.value = x.place || '';
    el.sitMood.value = x.mood || '';
    el.sitNotes.value = x.notes || '';
    el.sitStance.value = x.stance || '';
    el.sitApply.value = x.application || '';
    sitCounts();
    showErr(el.sitErr, '');
    el.sitSave.textContent = sitId ? 'Save this sitting' : 'Add this sitting';
  }
  el.sitAdd.addEventListener('click', function () {
    sitFormReset(); el.sitForm.hidden = false; el.sitAdd.hidden = true; el.sitDay.focus();
  });
  el.sitCancel.addEventListener('click', function () {
    el.sitForm.hidden = true; el.sitAdd.hidden = false; el.sitAdd.focus();
  });
  el.sitSave.addEventListener('click', function () {
    showErr(el.sitErr, '');
    el.sitSave.disabled = true;
    /* started_on rides along so the FIRST sitting can open the draft itself
       — see the same note in reading.js. */
    post({ course_action: 'session', session: {
      id: sitId, course_id: courseId, started_on: el.started.value,
      day: el.sitDay.value, minutes: +el.sitMins.value || 0,
      module_from: +el.sitFrom.value || 0, module_to: +el.sitTo.value || 0,
      place: el.sitPlace.value, mood: el.sitMood.value, notes: el.sitNotes.value,
      stance: el.sitStance.value, application: el.sitApply.value
    } }).then(function (j) {
      sits = j.sessions || [];
      drawSits(false);
      el.sitForm.hidden = true; el.sitAdd.hidden = false; sitId = 0;
    }).catch(function (e) { showErr(el.sitErr, e.message); })
      .finally(function () { el.sitSave.disabled = false; });
  });
  el.sitList.addEventListener('click', function (ev) {
    var ed = ev.target.closest ? ev.target.closest('[data-sit-edit]') : null;
    var dr = ev.target.closest ? ev.target.closest('[data-sit-drop]') : null;
    if (ed) {
      var id = +ed.getAttribute('data-sit-edit'), found = null;
      sits.forEach(function (x) { if (+x.id === id) found = x; });
      if (!found) return;
      sitFormReset(found);
      el.sitForm.hidden = false; el.sitAdd.hidden = true; el.sitDay.focus();
    } else if (dr) {
      if (!window.confirm('Remove this sitting? What you wrote in it goes with it.')) return;
      post({ course_action: 'session_drop', id: +dr.getAttribute('data-sit-drop') })
        .then(function (j) { sits = j.sessions || []; drawSits(false); })
        .catch(function (e) { showErr(el.sitErr, e.message); });
    }
  });

  /* ── The certificate: multipart, so it does not go through post(). ──── */
  function certSend(fd) {
    return fetch(POST_TO, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': window.NGV_CSRF || '' }, body: fd
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'The server did not answer properly.' }; });
    }).then(function (j) {
      if (!j.ok) { showErr(el.err, j.error || 'It was not attached.'); return; }
      location.reload();
    }).catch(function () { showErr(el.err, 'No connection. Try again.'); });
  }
  el.certFile.addEventListener('change', function () {
    if (!el.certFile.files || !el.certFile.files[0]) return;
    var fd = new FormData();
    fd.append('action', 'course_certificate');
    fd.append('course_id', String(courseId));
    fd.append('certificate', el.certFile.files[0]);
    certSend(fd);
  });
  el.certClear.addEventListener('click', function () {
    var fd = new FormData();
    fd.append('action', 'course_certificate');
    fd.append('course_id', String(courseId));
    fd.append('remove', '1');
    certSend(fd);
  });

  /* ── A member's own target. A vanguard's is the programme's, and this
        form is not drawn for them at all. ─────────────────────────────── */
  var tf = document.querySelector('[data-course-target]');
  if (tf) tf.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var f = document.getElementById('rcTarget');
    post({ course_action: 'target', target: f ? (+f.value || 0) : 0 })
      .then(function () { location.reload(); })
      .catch(function (e) { window.alert(e.message); });
  });
})();
