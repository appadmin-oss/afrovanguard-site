/* portal/meetings.js — the standardized Meetings suite app.
 *
 * Schedule a meeting (Google Meet link + calendar invite + cadence), join it, cancel it, and
 * after it runs, paste or pull the transcript to get Otter-style AI minutes.
 * Hooks into the Suite's refresh registry so opening the tile pulls fresh data.
 */
(function () {
  'use strict';
  var root = document.getElementById('tlMeet');
  if (!root) return;

  var csrf = root.getAttribute('data-csrf') || '';
  var form = document.getElementById('tlMeetForm');
  var listEl = document.getElementById('tlMeetList');
  var msg = document.getElementById('tlMeetMsg');
  var API = '/portal/meetings.php';
  var ME = 0, GEMINI = false, BOT = false;

  function post(action, body) {
    return fetch(API + '?action=' + action, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); });
  }
  function get(action) {
    return fetch(API + '?action=' + action, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function fmtWhen(iso) {
    var d = new Date(iso);
    if (isNaN(d)) return '';
    try {
      return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) + ' · ' +
             d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    } catch (e) { return d.toISOString(); }
  }
  function fmtDur(m) { m = +m || 0; var h = Math.floor(m / 60), r = m % 60; return h ? (h + 'h' + (r ? ' ' + r + 'm' : '')) : (r + 'm'); }

  function say(t, ok) { if (!msg) return; msg.textContent = t || ''; msg.style.color = ok ? 'var(--afg-success,#16a34a)' : ''; }

  function transcriptHtml(m) {
    var t = m.transcript;
    var up = new Date(m.when_iso) > new Date();
    var wrap = '<div class="meet-tr" data-id="' + m.id + '">';
    if (t && t.structured) {
      wrap += '<div class="meet-min">';
      if (t.summary) wrap += '<p class="meet-min-sum">' + esc(t.summary) + '</p>';
      if (t.highlights && t.highlights.length) {
        wrap += '<h5>Key points</h5><ul class="meet-min-list">';
        t.highlights.forEach(function (h) { wrap += '<li>' + esc(h) + '</li>'; });
        wrap += '</ul>';
      }
      if (t.decisions && t.decisions.length) {
        wrap += '<h5>Decisions</h5><ul class="meet-min-list">';
        t.decisions.forEach(function (d) { wrap += '<li>' + esc(d) + '</li>'; });
        wrap += '</ul>';
      }
      if (t.action_items && t.action_items.length) {
        wrap += '<h5>Action items</h5><ul class="meet-min-list meet-min-actions">';
        t.action_items.forEach(function (a) {
          wrap += '<li>' + esc(a.task) + (a.owner ? ' <span class="meet-owner">— ' + esc(a.owner) + '</span>' : '') + '</li>';
        });
        wrap += '</ul>';
      }
      wrap += '<button type="button" class="pbtn pbtn-ghost pbtn-sm meet-redo" data-id="' + m.id + '">Replace transcript</button>';
      wrap += '</div>';
    } else if (t && !t.structured) {
      wrap += '<p class="meet-tr-note">Transcript saved, but AI minutes weren’t generated' + (t.source === 'google' ? '' : '') + '. <button type="button" class="pbtn pbtn-ghost pbtn-sm meet-redo" data-id="' + m.id + '">Try again</button></p>';
    } else {
      wrap += '<details class="meet-tr-add"' + (up ? '' : ' open') + '>';
      wrap += '<summary>' + (up ? 'Add transcript after the meeting' : '📄 Add transcript for AI minutes') + '</summary>';
      wrap += '<textarea class="meet-tr-text" placeholder="Paste the meeting transcript here…"></textarea>';
      wrap += '<div class="meet-tr-btns">';
      wrap += '<button type="button" class="pbtn pbtn-gold pbtn-sm meet-tr-save" data-id="' + m.id + '">Generate minutes</button>';
      wrap += '<button type="button" class="pbtn pbtn-ghost pbtn-sm meet-tr-pull" data-id="' + m.id + '">Pull Google Meet transcript</button>';
      wrap += '<label class="pbtn pbtn-ghost pbtn-sm meet-tr-uplabel">Upload recording<input type="file" class="meet-tr-file" accept="audio/*,video/mp4,video/webm" data-id="' + m.id + '" hidden></label>';
      wrap += '<span class="meet-tr-msg" role="status" aria-live="polite"></span>';
      wrap += '</div>';
      wrap += '<p class="meet-tr-help">' + (GEMINI ? 'Recordings are transcribed by Gemini Flash.' : 'Set AV_GEMINI_API_KEY to enable audio transcription &amp; AI minutes.') + '</p>';
      wrap += '</details>';
    }
    return wrap + '</div>';
  }

  function meetingCard(m) {
    var prov = 'Google Meet';
    var freq = m.frequency && m.frequency !== 'once' ? ' · 🔁 ' + esc(m.frequency_label) : '';
    var isOwner = m.creator_id === ME;
    var h = '<li class="meet-item" data-id="' + m.id + '">';
    h += '<div class="meet-item-main">';
    h += '<div class="meet-item-top"><span class="meet-when">' + esc(fmtWhen(m.when_iso)) + '</span><span class="meet-dur">' + esc(fmtDur(m.duration_min)) + freq + '</span></div>';
    h += '<p class="meet-title">' + esc(m.title);
    if (m.auto_record) h += ' <span class="meet-bot-badge" title="Recording bot ' + (m.bot_state === 'unconfigured' ? 'not wired — use paste / Google transcript' : esc(m.bot_state || 'on')) + '">🤖 ' + (m.bot_state === 'unconfigured' ? 'auto (manual)' : 'auto-record') + '</span>';
    h += '</p>';
    if (m.agenda) h += '<p class="meet-agenda">' + esc(m.agenda) + '</p>';
    h += '<div class="meet-actions">';
    if (m.meet_url) h += '<a class="pbtn pbtn-soft pbtn-sm" href="' + esc(m.meet_url) + '" target="_blank" rel="noopener">▶ Join · ' + esc(prov) + '</a>';
    else h += '<span class="meet-pending">⚠ Meet link pending — connect Google Workspace</span>';
    if (isOwner) h += '<button type="button" class="pbtn pbtn-ghost pbtn-sm meet-cancel" data-id="' + m.id + '">Cancel</button>';
    h += '</div>';
    h += transcriptHtml(m);
    h += '</div></li>';
    return h;
  }

  function render(meetings) {
    if (!meetings || !meetings.length) { listEl.innerHTML = '<p class="pc-empty">No meetings yet. Schedule one above.</p>'; return; }
    listEl.innerHTML = '<ul class="meet-ul">' + meetings.map(meetingCard).join('') + '</ul>';
  }

  function load() {
    get('list').then(function (d) {
      if (!d || !d.ok) { listEl.innerHTML = '<p class="pc-empty">Could not load meetings.</p>'; return; }
      ME = d.me || 0; GEMINI = !!d.gemini; BOT = !!d.bot;
      render(d.meetings);
    }).catch(function () { listEl.innerHTML = '<p class="pc-empty">Could not load meetings.</p>'; });
  }

  // Schedule
  form && form.addEventListener('submit', function (e) {
    e.preventDefault();
    var title = (document.getElementById('tlMeetTitle').value || '').trim();
    var when = document.getElementById('tlMeetWhen').value || '';
    if (!title) { say('Give the meeting a title.'); return; }
    if (!when) { say('Pick a date and time.'); return; }
    say('Scheduling…');
    post('schedule', {
      title: title, when: when,
      duration: +document.getElementById('tlMeetDur').value || 30,
      frequency: document.getElementById('tlMeetFreq').value || 'once',
      attendees: document.getElementById('tlMeetWho').value || '',
      agenda: document.getElementById('tlMeetAgenda').value || '',
      auto_record: !!(document.getElementById('tlMeetRec') && document.getElementById('tlMeetRec').checked),
      context: 'workspace'
    }).then(function (d) {
      if (!d || !d.ok) { say((d && d.error) || 'Could not schedule.'); return; }
      if (d.warning) { say(d.warning); } else { say('Scheduled ✓ — invite sent', true); }
      form.reset();
      document.getElementById('tlMeetDur').value = '30';
      load();
      setTimeout(function () { say(''); }, 2500);
    }).catch(function () { say('Network error.'); });
  });

  // Delegated actions on the list
  listEl && listEl.addEventListener('click', function (e) {
    var t = e.target;

    var cancel = t.closest('.meet-cancel');
    if (cancel) {
      if (!confirm('Cancel this meeting?')) return;
      post('cancel', { id: +cancel.getAttribute('data-id') }).then(function (d) { if (d && d.ok) load(); else alert((d && d.error) || 'Could not cancel.'); });
      return;
    }

    var save = t.closest('.meet-tr-save');
    if (save) {
      var box = save.closest('.meet-tr');
      var area = box.querySelector('.meet-tr-text');
      var tmsg = box.querySelector('.meet-tr-msg');
      var txt = (area && area.value || '').trim();
      if (!txt) { if (tmsg) tmsg.textContent = 'Paste the transcript first.'; return; }
      save.disabled = true; if (tmsg) tmsg.textContent = 'Generating minutes…';
      post('transcript', { id: +save.getAttribute('data-id'), text: txt, source: 'paste' }).then(function (d) {
        save.disabled = false;
        if (!d || !d.ok) { if (tmsg) tmsg.textContent = (d && d.error) || 'Could not save.'; return; }
        if (!d.structured && tmsg) tmsg.textContent = d.note || 'Saved (AI minutes unavailable).';
        load();
      }).catch(function () { save.disabled = false; if (tmsg) tmsg.textContent = 'Network error.'; });
      return;
    }

    var pull = t.closest('.meet-tr-pull');
    if (pull) {
      var box2 = pull.closest('.meet-tr');
      var tmsg2 = box2.querySelector('.meet-tr-msg');
      pull.disabled = true; if (tmsg2) tmsg2.textContent = 'Looking for the Meet transcript…';
      post('pull_transcript', { id: +pull.getAttribute('data-id') }).then(function (d) {
        pull.disabled = false;
        if (!d || !d.ok) { if (tmsg2) tmsg2.textContent = (d && d.error) || 'Not found yet.'; return; }
        load();
      }).catch(function () { pull.disabled = false; if (tmsg2) tmsg2.textContent = 'Network error.'; });
      return;
    }

    var redo = t.closest('.meet-redo');
    if (redo) {
      var item = redo.closest('.meet-item');
      var id = +redo.getAttribute('data-id');
      // Swap the minutes block back to an editable add-transcript form.
      var box3 = item.querySelector('.meet-tr');
      if (box3) {
        box3.innerHTML = '<details class="meet-tr-add" open><summary>Replace transcript</summary>' +
          '<textarea class="meet-tr-text" placeholder="Paste the meeting transcript here…"></textarea>' +
          '<div class="meet-tr-btns"><button type="button" class="pbtn pbtn-gold pbtn-sm meet-tr-save" data-id="' + id + '">Generate minutes</button>' +
          '<button type="button" class="pbtn pbtn-ghost pbtn-sm meet-tr-pull" data-id="' + id + '">Pull from Google Meet</button>' +
          '<span class="meet-tr-msg" role="status" aria-live="polite"></span></div></details>';
      }
      return;
    }
  });

  // Upload a recording → Gemini transcribes → minutes.
  listEl && listEl.addEventListener('change', function (e) {
    var file = e.target.closest('.meet-tr-file');
    if (!file || !file.files || !file.files.length) return;
    var box = file.closest('.meet-tr');
    var tmsg = box && box.querySelector('.meet-tr-msg');
    var f = file.files[0];
    if (f.size > 19 * 1024 * 1024) { if (tmsg) tmsg.textContent = 'Recording too large (max ~19MB). Use the Google Meet transcript.'; file.value = ''; return; }
    var fd = new FormData();
    fd.append('id', file.getAttribute('data-id'));
    fd.append('audio', f);
    if (tmsg) tmsg.textContent = 'Transcribing with Gemini…';
    fetch(API + '?action=transcribe', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { if (tmsg) tmsg.textContent = (d && d.error) || 'Could not transcribe.'; return; }
        load();
      }).catch(function () { if (tmsg) tmsg.textContent = 'Network error.'; });
  });

  // Register with the Suite so opening the tile refreshes; also load once now.
  window.__suiteRefresh = window.__suiteRefresh || {};
  window.__suiteRefresh['meet'] = load;
  load();
})();
