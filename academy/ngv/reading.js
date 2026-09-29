/* ============================================================================
 * academy/ngv/reading.js — recording a book, from the member's side.
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
    title: document.getElementById('bkBookTitle'),
    author: document.getElementById('bkAuthor'),
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
    submit: document.getElementById('bkSubmit')
  };

  var slot = 0, MIN = 320, MIN_TAKE = 60, opener = null;
  var typedMs = 0, pastes = 0, lastKey = 0;

  function post(body) {
    return fetch(location.pathname, {
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

  function counts() {
    var r = (el.reflection.value || '').replace(/\s+/g, ' ').trim().length;
    var t = (el.takeaway.value || '').replace(/\s+/g, ' ').trim().length;
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
  [el.reflection, el.takeaway].forEach(function (f) {
    f.addEventListener('keydown', tick);
    f.addEventListener('paste', function () { pastes++; });
    f.addEventListener('input', counts);
  });

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
    [el.title, el.author, el.started, el.finished, el.reflection, el.takeaway].forEach(function (f) { f.disabled = on; });
    el.draft.hidden = on; el.submit.hidden = on;
  }

  function open(n, trigger) {
    slot = n; opener = trigger || null;
    showErr(''); typedMs = 0; pastes = 0; lastKey = 0;
    el.slot.textContent = String(n);
    post({ book_action: 'get', slot: n }).then(function (j) {
      var c = j.claim || {};
      MIN = j.min || MIN; MIN_TAKE = j.minTake || MIN_TAKE;
      el.title.value = c.title || '';
      el.author.value = c.author || '';
      el.started.value = c.started_on || '';
      el.finished.value = c.finished_on || '';
      el.reflection.value = c.reflection || '';
      el.takeaway.value = c.takeaway || '';
      setStatus(c);
      /* Submitted and verified claims are read-only — a claim a reviewer has
         seen must not change underneath them. */
      lock(c.status === 'submitted' || c.status === 'verified');
      counts();
      modal.hidden = false;
      (el.title.disabled ? el.close : el.title).focus();
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
        title: el.title.value, author: el.author.value,
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
