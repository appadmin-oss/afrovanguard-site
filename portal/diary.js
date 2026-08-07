/* ============================================================
   portal/diary.js — the in-portal Diary composer (Trix editor).
   Writes hit /diary/api.php (same-origin). Rich HTML is sanitised
   server-side; templates load a structured starter into the editor.
   ============================================================ */
(function () {
  'use strict';
  var form = document.getElementById('pdCompose');
  if (!form) return;
  var API = '/diary/api.php';
  var kindEl = document.getElementById('pdKind');
  var tplEl = document.getElementById('pdTemplate');
  var dateEl = document.getElementById('pdDate');
  var titleEl = document.getElementById('pdTitle');
  var bodyEl = document.getElementById('pdBody');           // hidden input Trix syncs to
  var editorEl = form.querySelector('trix-editor');
  var hintEl = document.getElementById('pdHint');
  var msgEl = document.getElementById('pdMsg');
  var saveBtn = document.getElementById('pdSave');
  var list = document.getElementById('pdList');
  var countEl = document.getElementById('pdCount');

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function post(action, payload) {
    return fetch(API + '?action=' + action, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload || {}) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Server error.' }; }); });
  }
  function say(t, kind) { if (msgEl) { msgEl.textContent = t || ''; msgEl.className = 'pd-msg' + (kind ? ' is-' + kind : ''); } }

  var HINTS = {
    private: '🔒 Private entries are visible only to you.',
    public:  '🌐 Public entries are reviewed by an admin before they appear on the Diary.',
    event:   '📅 Events are public happenings — shown on the Diary after review. You can backdate them.'
  };
  if (kindEl) kindEl.addEventListener('change', function () { if (hintEl) hintEl.textContent = HINTS[kindEl.value] || ''; });

  /* Templates → structured HTML starters loaded into the editor. */
  var TEMPLATES = {
    daily:    '<h1>How today went</h1><div>What went well</div><ul><li></li></ul><div>What was hard</div><ul><li></li></ul><div>One thing for tomorrow</div><ul><li></li></ul>',
    field:    '<h1>Field note</h1><div>Context</div><div><br></div><div>What I observed</div><ul><li></li></ul><div>What it means</div><ul><li></li></ul><div>Next step</div><ul><li></li></ul>',
    project:  '<h1>Project log</h1><div>Progress this session</div><ul><li></li></ul><div>Blockers</div><ul><li></li></ul><div>Next up</div><ul><li></li></ul>',
    meeting:  '<h1>Meeting notes</h1><div>Attendees: </div><div>Agenda</div><ul><li></li></ul><div>Decisions</div><ul><li></li></ul><div>Action items</div><ul><li></li></ul>',
    gratitude:'<h1>Gratitude</h1><div>Three things I’m grateful for today</div><ol><li></li><li></li><li></li></ol><div>Why it mattered</div>',
    weekly:   '<h1>Week in review</h1><div>Wins</div><ul><li></li></ul><div>Lessons</div><ul><li></li></ul><div>Focus next week</div><ul><li></li></ul>',
    idea:     '<h1>The idea</h1><div><br></div><div>Why it matters</div><ul><li></li></ul><div>How it could work</div><ul><li></li></ul><div>What I’d need</div><ul><li></li></ul>'
  };
  var TITLES = { daily: 'Daily reflection', field: 'Field note', project: 'Project log', meeting: 'Meeting notes', gratitude: 'Gratitude', weekly: 'Weekly review', idea: 'Idea / proposal' };
  if (tplEl) tplEl.addEventListener('change', function () {
    var key = tplEl.value; if (!key || !TEMPLATES[key]) return;
    var hasText = editorEl && editorEl.editor && editorEl.editor.getDocument().toString().trim().length > 1;
    if (hasText && !confirm('Replace what you’ve written with this template?')) { tplEl.value = ''; return; }
    if (editorEl && editorEl.editor) editorEl.editor.loadHTML(TEMPLATES[key]);
    if (titleEl && !titleEl.value.trim() && TITLES[key]) titleEl.value = TITLES[key] + ' · ' + new Date().toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    if (editorEl) editorEl.focus();
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var body = (bodyEl.value || '').trim();
    var plain = body.replace(/<[^>]*>/g, '').trim();
    if (!plain) { say('Write something before saving.', 'err'); return; }
    saveBtn.disabled = true; say('Saving…');
    post('entry.create', { kind: kindEl.value, title: titleEl.value, body: body, entry_date: dateEl ? dateEl.value : '' }).then(function (d) {
      saveBtn.disabled = false;
      if (!d.ok) { say(d.error || 'Could not save.', 'err'); return; }
      say((d.kind === 'public' || d.kind === 'event') ? 'Submitted for review.' : 'Saved.', 'ok');
      // Optimistically prepend a row.
      var en = { id: d.id, kind: d.kind, title: titleEl.value, body: plain, entry_date: (dateEl && dateEl.value) || new Date().toISOString().slice(0, 10), status: (d.kind === 'private' ? 'logged' : 'pending') };
      var empty = document.getElementById('pdEmpty'); if (empty) empty.remove();
      list.insertAdjacentHTML('afterbegin', rowHTML(en));
      bumpCount(1);
      if (editorEl && editorEl.editor) editorEl.editor.loadHTML(''); titleEl.value = '';
      setTimeout(function () { say(''); }, 2500);
    }).catch(function () { saveBtn.disabled = false; say('Network error — try again.', 'err'); });
  });

  function rowHTML(en) {
    var icon = { event: '📅', private: '🔒', public: '🌐' }[en.kind] || '📝';
    var klabel = { event: 'Event', private: 'Private', public: 'Public' }[en.kind] || 'Entry';
    var st = (en.kind === 'public' || en.kind === 'event') ? { t: 'Pending review', c: 'is-pending' } : { t: 'Logged', c: 'is-logged' };
    var d = new Date((en.entry_date || '') + 'T00:00:00');
    var date = isNaN(d) ? (en.entry_date || '') : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    var ex = (en.body || '').slice(0, 140);
    return '<li class="pd-item" data-id="' + en.id + '">'
      + '<div class="pd-item-top"><span class="pd-kind">' + icon + ' ' + esc(klabel) + '</span><span class="pd-st ' + st.c + '">' + st.t + '</span></div>'
      + (en.title ? '<p class="pd-item-title">' + esc(en.title) + '</p>' : '')
      + '<p class="pd-item-ex">' + esc(ex) + '</p>'
      + '<div class="pd-item-foot"><time>' + esc(date) + '</time>'
      + '<button type="button" class="pd-share" data-id="' + en.id + '">Share</button>'
      + '<button type="button" class="pd-del" data-id="' + en.id + '">Delete</button></div></li>';
  }
  function bumpCount(delta) { if (!countEl) return; countEl.textContent = Math.max(0, (parseInt(countEl.textContent, 10) || 0) + delta); }

  /* Share (GWS-style: people + link) / Delete (delegated) */
  function fetchJson(url) { return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }); }

  function renderPeople(box, id, people) {
    var wrap = box.querySelector('.pd-people');
    wrap.innerHTML = (people && people.length)
      ? people.map(function (p) { return '<li class="pd-person"><span>' + esc(p.name) + ' <em>' + esc(p.email) + '</em></span>'
          + '<button type="button" class="pd-remove" data-uid="' + p.id + '">Remove</button></li>'; }).join('')
      : '<li class="pd-person pd-person--none">Not shared with anyone yet.</li>';
  }

  function openShareBox(li, id) {
    var existing = li.querySelector('.pd-sharebox');
    if (existing) { existing.remove(); return; }             // toggle off
    var box = document.createElement('div');
    box.className = 'pd-sharebox';
    box.innerHTML =
      '<div class="pd-share-row"><input type="email" class="pd-share-email" placeholder="Share with a member (email)"><button type="button" class="pd-share-add pbtn pbtn-gold">Add</button></div>'
      + '<ul class="pd-people"><li class="pd-person pd-person--none">Loading…</li></ul>'
      + '<div class="pd-linkrow"><button type="button" class="pd-linkbtn">🔗 Copy secret link</button></div>';
    li.appendChild(box);
    fetchJson(API + '?action=entry.people&id=' + id).then(function (d) { renderPeople(box, id, d.people || []); });

    box.querySelector('.pd-share-add').addEventListener('click', function () {
      var em = box.querySelector('.pd-share-email'); var email = (em.value || '').trim();
      if (!email) return;
      var btn = this; btn.disabled = true;
      post('entry.share_add', { id: id, email: email }).then(function (d) {
        btn.disabled = false;
        if (!d.ok) { say(d.error || 'Could not share.', 'err'); return; }
        em.value = '';
        fetchJson(API + '?action=entry.people&id=' + id).then(function (r) { renderPeople(box, id, r.people || []); });
        say('Shared with ' + d.user.name + '.', 'ok');
      }).catch(function () { btn.disabled = false; say('Network error.', 'err'); });
    });
    box.querySelector('.pd-people').addEventListener('click', function (e) {
      var rm = e.target.closest('.pd-remove'); if (!rm) return;
      post('entry.share_remove', { id: id, user_id: +rm.getAttribute('data-uid') }).then(function () {
        fetchJson(API + '?action=entry.people&id=' + id).then(function (r) { renderPeople(box, id, r.people || []); });
      });
    });
    box.querySelector('.pd-linkbtn').addEventListener('click', function () {
      var btn = this; btn.disabled = true;
      post('entry.share', { id: id }).then(function (d) {
        btn.disabled = false;
        if (!d.ok || !d.url) { say(d.error || 'Could not create a link.', 'err'); return; }
        if (navigator.clipboard) { navigator.clipboard.writeText(d.url).catch(function () {}); }
        window.prompt('Secret link — anyone with it can read this entry:', d.url);
      }).catch(function () { btn.disabled = false; });
    });
  }

  list && list.addEventListener('click', function (e) {
    var share = e.target.closest('.pd-share');
    if (share) { openShareBox(share.closest('.pd-item'), share.getAttribute('data-id')); return; }
    var del = e.target.closest('.pd-del');
    if (del) {
      if (!confirm('Delete this entry? This cannot be undone.')) return;
      var li = del.closest('.pd-item'); var id = del.getAttribute('data-id');
      post('entry.delete', { id: id }).then(function (d) {
        if (d.ok) { li.remove(); bumpCount(-1); if (list && !list.querySelector('.pd-item')) list.innerHTML = '<li class="pc-empty" id="pdEmpty">No entries yet — write your first above.</li>'; }
        else { say(d.error || 'Could not delete.', 'err'); }
      });
    }
  });

  /* ── Shared with me + read modal ── */
  var sharedCard = document.getElementById('pdSharedCard');
  var sharedList = document.getElementById('pdShared');
  var sharedCount = document.getElementById('pdSharedCount');
  var modal = document.getElementById('pdModal');
  function loadShared() {
    if (!sharedList) return;
    fetchJson(API + '?action=mine.shared').then(function (d) {
      if (!d.ok) return;
      var items = d.entries || [];
      if (sharedCount) sharedCount.textContent = items.length;
      if (sharedCard) sharedCard.hidden = items.length === 0;
      sharedList.innerHTML = items.map(function (en) {
        var icon = { event: '📅', private: '🔒', public: '🌐' }[en.kind] || '📝';
        return '<li class="pd-item" data-read="' + en.id + '">'
          + '<div class="pd-item-top"><span class="pd-kind">' + icon + ' from ' + esc(en.author) + '</span></div>'
          + (en.title ? '<p class="pd-item-title">' + esc(en.title) + '</p>' : '')
          + '<p class="pd-item-ex">' + esc(en.excerpt) + '</p>'
          + '<div class="pd-item-foot"><button type="button" class="pd-read">Read →</button></div></li>';
      }).join('');
    }).catch(function () {});
  }
  sharedList && sharedList.addEventListener('click', function (e) {
    var li = e.target.closest('[data-read]'); if (!li) return;
    var id = li.getAttribute('data-read');
    fetchJson(API + '?action=entry.read&id=' + id).then(function (d) {
      if (!d.ok) { say('Could not open.', 'err'); return; }
      document.getElementById('pdModalTitle').textContent = d.entry.title;
      document.getElementById('pdModalMeta').textContent = 'By ' + d.entry.author + ' · ' + d.entry.entry_date;
      document.getElementById('pdModalBody').innerHTML = d.entry.body_html;   // server-sanitised
      modal.hidden = false;
    });
  });
  modal && modal.addEventListener('click', function (e) { if (e.target.closest('[data-pdclose]')) modal.hidden = true; });

  /* Load shared-with-me when the Diary tab is opened (and once on boot). */
  function onDiaryOpen() { var v = document.getElementById('view-diary'); if (v && !v.hidden) loadShared(); }
  document.addEventListener('click', function (e) { if (e.target.closest('[data-view="diary"]')) setTimeout(onDiaryOpen, 80); });
  window.addEventListener('hashchange', function () { if (location.hash === '#diary') setTimeout(onDiaryOpen, 80); });
  onDiaryOpen();
})();
