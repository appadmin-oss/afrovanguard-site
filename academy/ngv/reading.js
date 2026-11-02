/* ============================================================================
 * academy/ngv/reading.js — recording a book, from the member's side.
 *
 * The book is chosen from the programme's book list (staff keep it on
 * /academy/ngv/books.php). Choosing it draws one summary box per chapter;
 * the reflection and the takeaway are about the whole book.
 *
 * The typing signals it sends (how long the reflection took, how many pastes)
 * are TRIAGE, not evidence. Anybody who can open developer tools can send
 * whatever they like, and the server treats them accordingly: they order the
 * review queue and never decide an outcome. They are collected because the
 * cheap fakes really are cheap, and putting those in front of a human first is
 * most of the value.
 * ==========================================================================*/
(function () {
  'use strict';

  var modal = document.getElementById('bkModal');
  var shelf = document.getElementById('books');
  if (!modal || !shelf) return;

  var el = {
    slot: document.getElementById('bkSlot'),
    book: document.getElementById('bkBook'),
    pick: document.getElementById('bkPick'),
    legacy: document.getElementById('bkLegacy'),
    none: document.getElementById('bkNone'),
    chapters: document.getElementById('bkChapters'),
    chapterList: document.getElementById('bkChapterList'),
    started: document.getElementById('bkStarted'),
    finished: document.getElementById('bkFinished'),
    reflection: document.getElementById('bkReflection'),
    takeaway: document.getElementById('bkTakeaway'),
    reflCount: document.getElementById('bkReflCount'),
    takeCount: document.getElementById('bkTakeCount'),
    status: document.getElementById('bkStatus'),
    err: document.getElementById('bkErr'),
    close: document.getElementById('bkClose'),
    draft: document.getElementById('bkSaveDraft'),
    submit: document.getElementById('bkSubmit'),
    /* The sittings. */
    sits: document.getElementById('bkSits'),
    sitList: document.getElementById('bkSitList'),
    sitForm: document.getElementById('bkSitForm'),
    sitAdd: document.getElementById('bkSitAdd'),
    sitSave: document.getElementById('bkSitSave'),
    sitCancel: document.getElementById('bkSitCancel'),
    sitErr: document.getElementById('bkSitErr'),
    sitDay: document.getElementById('bkSitDay'),
    sitMins: document.getElementById('bkSitMins'),
    sitFrom: document.getElementById('bkSitFrom'),
    sitTo: document.getElementById('bkSitTo'),
    sitPages: document.getElementById('bkSitPages'),
    sitPlace: document.getElementById('bkSitPlace'),
    sitValue: document.getElementById('bkSitValue'),
    sitMotto: document.getElementById('bkSitMotto'),
    sitMood: document.getElementById('bkSitMood'),
    sitNotes: document.getElementById('bkSitNotes'),
    sitStance: document.getElementById('bkSitStance'),
    sitApply: document.getElementById('bkSitApply')
  };

  var slot = 0, MIN = 320, MIN_TAKE = 60, MIN_CHAPTER = 80, opener = null;
  /* The sittings on this claim, the one being edited (0 = a new one), the
     seven values with their mottos, and today as the server reckons it. */
  var sits = [], sitId = 0, VALUES = {}, MOTTOS = {}, MOODS = {}, TODAY = '';
  var typedMs = 0, pastes = 0, lastKey = 0;
  /* The book list, the claim being edited, and — for a claim made before
     there was a list — the title and author it was recorded with. */
  var books = [], claim = {}, legacy = null;
  /* What has been typed for each chapter, by number — it outlives the boxes,
     so choosing a shorter book and changing back loses nothing. */
  var notes = [];

  function post(body) {
    /* The dashboard's own URL, not location.pathname: vanguards see this in
       the portal (/portal/#ngv), which has no handler, so every claim came
       back as an HTML page and "The server did not answer properly". */
    return fetch('/academy/ngv/dashboard.php', {
      method: 'POST',
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

  function showErr(m) {
    if (!el.err) return;
    if (!m) { el.err.hidden = true; el.err.textContent = ''; return; }
    el.err.textContent = m; el.err.hidden = false;
    el.err.scrollIntoView({ block: 'nearest' });
  }

  function len(v) { return (v || '').replace(/\s+/g, ' ').trim().length; }

  function counts() {
    var r = len(el.reflection.value);
    var t = len(el.takeaway.value);
    chapterFields().forEach(function (f) {
      var c = f.parentNode.querySelector('.bk-count'), n = len(f.value);
      c.textContent = n + ' / ' + MIN_CHAPTER;
      c.classList.toggle('short', n < MIN_CHAPTER);
    });
    el.reflCount.textContent = r + ' / ' + MIN;
    el.takeCount.textContent = t + ' / ' + MIN_TAKE;
    el.reflCount.classList.toggle('short', r < MIN);
    el.takeCount.classList.toggle('short', t < MIN_TAKE);
  }

  /* Typing time, counted only while somebody is actually typing — a form left
     open in a background tab for six hours is not six hours of writing. */
  function tick() {
    var now = Date.now();
    if (lastKey && now - lastKey < 5000) typedMs += now - lastKey;
    lastKey = now;
  }
  function watch(f) {
    f.addEventListener('keydown', tick);
    f.addEventListener('paste', function () { pastes++; });
    f.addEventListener('input', counts);
  }
  [el.reflection, el.takeaway].forEach(watch);

  function chapterFields() { return Array.prototype.slice.call(el.chapterList.querySelectorAll('textarea')); }

  function bookById(id) {
    for (var i = 0; i < books.length; i++) if (String(books[i].id) === String(id)) return books[i];
    return null;
  }

  /* One box per chapter of the chosen book. What was typed is kept by chapter
     number, so changing the book back and forth loses nothing. */
  function drawChapters() {
    var b = bookById(el.book.value);
    var n = !b ? 0 : (String(claim.book_id || '') === String(b.id) && claim.chapters > 0 ? claim.chapters : b.chapters);
    el.chapterList.innerHTML = '';
    for (var i = 0; i < n; i++) {
      var lab = document.createElement('label');
      lab.className = 'bk-f bk-f--wide';
      var sp = document.createElement('span');
      sp.textContent = 'Chapter ' + (i + 1);
      var ta = document.createElement('textarea');
      ta.rows = 3; ta.maxLength = 1500; ta.value = notes[i] || '';
      ta.setAttribute('data-chapter', String(i));
      ta.addEventListener('input', function (ev) { notes[+ev.target.getAttribute('data-chapter')] = ev.target.value; });
      ta.setAttribute('aria-label', 'Summary of chapter ' + (i + 1));
      var c = document.createElement('span');
      c.className = 'bk-count';
      lab.appendChild(sp); lab.appendChild(ta); lab.appendChild(c);
      el.chapterList.appendChild(lab);
      watch(ta);
    }
    el.chapters.hidden = n === 0;
    counts();
  }

  el.book.addEventListener('change', drawChapters);

  function setStatus(c) {
    if (!c || !c.status || c.status === 'draft') { el.status.hidden = true; el.status.className = 'bk-status'; return; }
    var m = {
      submitted: ['With your track lead. You cannot change it while they have it.', ''],
      verified: ['Verified and counted. Thank you.', 'is-ok'],
      resubmit: ['Sent back: ' + (c.review_note || '') + ' Put that right and send it again.', 'is-back'],
      rejected: ['Not accepted: ' + (c.review_note || '') + ' Speak to your track lead if you think that is wrong.', 'is-back']
    }[c.status];
    if (!m) { el.status.hidden = true; return; }
    el.status.textContent = m[0];
    el.status.className = 'bk-status ' + m[1];
    el.status.hidden = false;
  }

  function lock(on) {
    [el.book, el.started, el.finished, el.reflection, el.takeaway].concat(chapterFields()).forEach(function (f) { f.disabled = on; });
    el.draft.hidden = on; el.submit.hidden = on;
    el.sitAdd.hidden = on; if (on) el.sitForm.hidden = true;
  }

  /* ── The sittings ───────────────────────────────────────────────────
     A book is read over days. Each sitting records when, how far, how long,
     where, what it covered, where they stand on it, what they will do — and
     which of the seven values it grew, which is the thing the challenge is
     actually for and the one thing a shelf of finished titles cannot show. */
  function esc(x) {
    return String(x == null ? '' : x).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function drawSits(locked) {
    if (!sits.length) {
      el.sitList.innerHTML = '<p class="bk-sits-none">Nothing logged yet. Log each time you sit down with this book — '
        + 'it is how the programme sees your reading, and how you see which of the seven values it is growing.</p>';
      return;
    }
    el.sitList.innerHTML = sits.map(function (x) {
      return '<div class="bk-sit">'
        + '<div class="bk-sit-top"><span class="bk-sit-when">' + esc(x.day) + '</span>'
        + (x.covered ? '<span>· ' + esc(x.covered) + '</span>' : '')
        + '<span>· ' + esc(x.minutes) + ' min</span>'
        + (x.value_label ? '<span class="bk-sit-val">' + esc(x.value_label) + '</span>' : '') + '</div>'
        + '<div class="bk-sit-meta">' + esc(x.place) + (x.mood_label ? ' · ' + esc(x.mood_label) : '') + '</div>'
        + '<p>' + esc(x.notes) + '</p>'
        + '<p><b>Where I stand:</b> ' + esc(x.stance) + '</p>'
        + '<p><b>What I will do:</b> ' + esc(x.application) + '</p>'
        + (locked ? '' : '<button type="button" class="bk-sit-x" data-sit-edit="' + x.id + '">Edit</button>'
            + ' <button type="button" class="bk-sit-x" data-sit-drop="' + x.id + '">Remove</button>')
        + '</div>';
    }).join('');
  }
  function sitFormReset(x) {
    x = x || {};
    sitId = +x.id || 0;
    el.sitDay.value = x.day || TODAY;
    el.sitDay.max = TODAY;
    el.sitMins.value = x.minutes || '';
    el.sitFrom.value = x.chapter_from || '';
    el.sitTo.value = x.chapter_to || '';
    el.sitPages.value = x.pages || '';
    el.sitPlace.value = x.place || '';
    el.sitValue.value = x.value_key || '';
    el.sitMood.value = x.mood || '';
    el.sitNotes.value = x.notes || '';
    el.sitStance.value = x.stance || '';
    el.sitApply.value = x.application || '';
    el.sitMotto.textContent = MOTTOS[el.sitValue.value] || '';
    el.sitErr.hidden = true;
    el.sitSave.textContent = sitId ? 'Save this sitting' : 'Add this sitting';
  }
  function fillSelect(sel, map, blank) {
    sel.innerHTML = '<option value="">' + blank + '</option>' + Object.keys(map).map(function (k) {
      return '<option value="' + esc(k) + '">' + esc(map[k]) + '</option>';
    }).join('');
  }
  el.sitValue.addEventListener('change', function () { el.sitMotto.textContent = MOTTOS[el.sitValue.value] || ''; });
  el.sitAdd.addEventListener('click', function () {
    sitFormReset(); el.sitForm.hidden = false; el.sitAdd.hidden = true; el.sitDay.focus();
  });
  el.sitCancel.addEventListener('click', function () { el.sitForm.hidden = true; el.sitAdd.hidden = false; el.sitAdd.focus(); });
  el.sitList.addEventListener('click', function (ev) {
    var e2 = ev.target.closest('[data-sit-edit]'), dr = ev.target.closest('[data-sit-drop]');
    if (e2) {
      var one = sits.filter(function (x) { return x.id === +e2.getAttribute('data-sit-edit'); })[0];
      if (one) { sitFormReset(one); el.sitForm.hidden = false; el.sitAdd.hidden = true; el.sitDay.focus(); }
      return;
    }
    if (!dr) return;
    if (!window.confirm('Remove this sitting? What you wrote in it goes with it.')) return;
    dr.disabled = true;
    post({ book_action: 'session_drop', id: +dr.getAttribute('data-sit-drop') }).then(function (j) {
      sits = j.sessions || []; drawSits(false);
    }).catch(function (err) { dr.disabled = false; el.sitErr.textContent = err.message; el.sitErr.hidden = false; });
  });
  el.sitSave.addEventListener('click', function () {
    var b = el.sitSave, label = b.textContent;
    el.sitErr.hidden = true; b.disabled = true; b.textContent = 'Saving…';
    post({ book_action: 'session', session: {
      id: sitId, slot: slot, day: el.sitDay.value, minutes: +el.sitMins.value || 0,
      chapter_from: +el.sitFrom.value || 0, chapter_to: +el.sitTo.value || 0,
      pages: el.sitPages.value, place: el.sitPlace.value, value_key: el.sitValue.value,
      mood: el.sitMood.value, notes: el.sitNotes.value, stance: el.sitStance.value, application: el.sitApply.value
    } }).then(function (j) {
      b.disabled = false; b.textContent = label;
      sits = j.sessions || []; drawSits(false);
      el.sitForm.hidden = true; el.sitAdd.hidden = false; el.sitAdd.focus();
    }).catch(function (err) {
      b.disabled = false; b.textContent = label;
      el.sitErr.textContent = err.message; el.sitErr.hidden = false;
    });
  });

  function open(n, trigger) {
    slot = n; opener = trigger || null;
    showErr(''); typedMs = 0; pastes = 0; lastKey = 0;
    el.slot.textContent = String(n);
    post({ book_action: 'get', slot: n }).then(function (j) {
      var c = j.claim || {};
      claim = c; books = j.books || [];
      MIN = j.min || MIN; MIN_TAKE = j.minTake || MIN_TAKE; MIN_CHAPTER = j.minChapter || MIN_CHAPTER;
      VALUES = j.values || {}; MOODS = j.moods || {}; TODAY = j.today || '';
      MOTTOS = {}; Object.keys(VALUES).forEach(function (k, i) { MOTTOS[k] = (j.mottos || [])[i] || ''; });
      fillSelect(el.sitValue, VALUES, 'Choose one…');
      fillSelect(el.sitMood, MOODS, 'Rather not say');
      sits = j.sessions || [];
      /* A claim recorded before the book list keeps the book it named. */
      legacy = c.title && !(c.book_id > 0) ? { title: c.title, author: c.author || '' } : null;
      el.book.innerHTML = '<option value="">Choose a book…</option>' + books.map(function (b) {
        var o = document.createElement('option');
        o.value = String(b.id);
        o.textContent = b.title + ' — ' + b.author + ' · ' + b.chapters + ' chapter' + (b.chapters === 1 ? '' : 's');
        return o.outerHTML;
      }).join('');
      el.book.value = c.book_id > 0 ? String(c.book_id) : '';
      el.pick.hidden = !!legacy || (!books.length && !legacy);
      el.none.hidden = !!legacy || books.length > 0;
      el.legacy.hidden = !legacy;
      el.legacy.textContent = legacy ? 'Recorded before the book list: ' + legacy.title + (legacy.author ? ' by ' + legacy.author : '') + '.' : '';
      notes = (c.chapter_notes_list || []).slice();
      drawChapters();
      el.started.value = c.started_on || '';
      el.finished.value = c.finished_on || '';
      el.reflection.value = c.reflection || '';
      el.takeaway.value = c.takeaway || '';
      setStatus(c);
      /* Submitted and verified claims are read-only — a claim a reviewer has
         seen must not change underneath them. */
      var locked = c.status === 'submitted' || c.status === 'verified' || c.status === 'rejected';
      drawSits(locked);
      el.sitForm.hidden = true;
      lock(locked);
      counts();
      modal.hidden = false;
      (el.book.disabled || el.pick.hidden ? el.close : el.book).focus();
    }).catch(function (e) { showErr(e.message); modal.hidden = false; });
  }

  function close() {
    modal.hidden = true;
    if (opener && opener.focus) opener.focus();   // focus goes back where it came from
  }

  shelf.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-slot]');
    if (b) open(parseInt(b.getAttribute('data-slot'), 10) || 1, b);
  });
  el.close.addEventListener('click', close);
  modal.addEventListener('click', function (ev) { if (ev.target === modal) close(); });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !modal.hidden) close();
  });

  function send(submit, btn) {
    showErr('');
    var label = btn.textContent;
    btn.disabled = true; btn.textContent = submit ? 'Sending…' : 'Saving…';
    post({
      book_action: submit ? 'submit' : 'save',
      book: {
        slot: slot,
        book_id: legacy ? 0 : (+el.book.value || 0),
        title: legacy ? legacy.title : '', author: legacy ? legacy.author : '',
        chapter_notes: chapterFields().map(function (f) { return f.value; }),
        started_on: el.started.value, finished_on: el.finished.value,
        reflection: el.reflection.value, takeaway: el.takeaway.value,
        typed_ms: typedMs, paste_count: pastes
      }
    }).then(function () {
      /* Reload rather than patching the shelf by hand: the slot's state, the
         counters, the progress bar and the legacy notice all move together,
         and a half-updated page after a save about honesty is a poor look. */
      location.reload();
    }).catch(function (e) {
      showErr(e.message);
      btn.disabled = false; btn.textContent = label;
    });
  }

  el.draft.addEventListener('click', function () { send(false, el.draft); });
  el.submit.addEventListener('click', function () { send(true, el.submit); });
})();
