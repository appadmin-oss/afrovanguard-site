/* ============================================================
   portal/commitments.js — G-1 commitments, member side and chair side.

   Two lists, one client, because they are two halves of one idea:

     · MY COMMITMENTS   what you promised. Mark it kept, or missed with a
                        reason. The reason is yours — it is stored against
                        your record and never shown to the assistant.
     · CONFIRM OWNERS   promises the assistant heard but would not attribute.
                        Only appears when this member was in the room.

   Everything talks to /portal/commitments.php. Authorisation lives on the
   server (lib/Commitments::canManage, and an ownership check per action);
   the queue card staying hidden when empty is courtesy, not security.
   ============================================================ */
(function () {
  'use strict';

  var card = document.getElementById('commitments');
  if (!card) return;                                   // not the portal home

  var API   = '/portal/commitments.php';
  var CSRF  = card.getAttribute('data-csrf') || '';
  var list  = document.getElementById('cmtList');
  var chip  = document.getElementById('cmtChip');
  var stats = document.getElementById('cmtStats');
  var qCard = document.getElementById('cmtQueue');
  var qList = document.getElementById('cmtQueueList');
  var qCount = document.getElementById('cmtQueueCount');

  var S = { open: [], settled: [], stats: null, requireReason: true, queue: [], members: [] };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function get(action) {
    return fetch(API + '?action=' + action, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function post(action, payload) {
    return fetch(API + '?action=' + action, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(payload || {})
    }).then(function (r) { return r.json(); });
  }
  function say(msg) {
    // The portal has no global toast on this card, so failures are spoken where
    // the action was taken rather than swallowed.
    var n = document.getElementById('cmtMsg');
    if (!n) { n = document.createElement('p'); n.id = 'cmtMsg'; n.className = 'pcard-note'; card.querySelector('.pcard-body').appendChild(n); }
    n.textContent = msg || '';
  }

  /* ── my commitments ─────────────────────────────────────────────────── */

  function render() {
    var rows = S.open.concat(S.settled);
    if (!rows.length) {
      list.innerHTML = '<li class="pc-empty task-empty">Nothing outstanding. Commitments appear here when a meeting or session records one.</li>';
      chip.textContent = 'none';
      stats.hidden = true;
      return;
    }
    var overdue = S.open.filter(function (c) { return c.overdue; }).length;
    chip.textContent = S.open.length + ' open' + (overdue ? ' · ' + overdue + ' overdue' : '');
    chip.className = 'pchip' + (overdue ? ' pchip--gold' : '');

    list.innerHTML = rows.map(function (c) {
      var settled = c.status !== 'open';
      var badge = c.status === 'done'   ? '<span class="pchip">kept</span>'
                : c.status === 'missed' ? '<span class="pchip">missed' + (Number(c.self_reported) ? ' · you told us' : '') + '</span>'
                : c.overdue             ? '<span class="pchip pchip--gold">overdue</span>'
                : '';
      var meta = (c.due ? 'due ' + esc(c.due) : 'no deadline')
               + ' · from a ' + (c.source_kind === 'session' ? 'mentorship session' : 'meeting');
      var actions = settled ? '' :
        '<div class="task-ops">'
        + '<button type="button" class="btn btn-outline btn-sm" data-done="' + c.id + '">Kept it</button>'
        + '<button type="button" class="btn btn-outline btn-sm" data-miss="' + c.id + '">Missed it</button>'
        + '</div>';
      // A recorded reason is shown back to its owner, and to nobody else: the
      // server strips it from every other response.
      var reason = (c.status === 'missed' && c.miss_reason)
        ? '<div class="pcard-note"><strong>You said:</strong> ' + esc(c.miss_reason) + '</div>' : '';
      return '<li class="task-item' + (settled ? ' is-done' : '') + '">'
        + '<div class="task-main"><div class="task-title">' + esc(c.title) + ' ' + badge + '</div>'
        + '<div class="task-meta">' + meta + '</div>' + reason + '</div>'
        + actions + '</li>';
    }).join('');

    var st = S.stats || {};
    if (st.rate !== null && st.rate !== undefined) {
      stats.hidden = false;
      stats.innerHTML = 'Kept <strong>' + st.rate + '%</strong> of ' + (Number(st.done) + Number(st.missed))
        + ' settled commitments.'
        + (Number(st.self_reported_misses) ? ' ' + st.self_reported_misses + ' miss(es) you reported yourself — that counts in your favour, not against you.' : '');
    } else { stats.hidden = true; }
  }

  list.addEventListener('click', function (e) {
    var d = e.target.closest('[data-done]'), m = e.target.closest('[data-miss]');
    if (d) {
      post('done', { id: +d.getAttribute('data-done') }).then(function (r) {
        if (!r.ok) return say(r.error || 'Could not update that.');
        say(''); load();
      });
    }
    if (m) {
      var why = S.requireReason
        ? window.prompt('What got in the way? This is private to your record — the assistant never sees it.')
        : window.prompt('Anything to note? (optional)');
      if (why === null) return;                        // cancelled, not a miss
      post('miss', { id: +m.getAttribute('data-miss'), reason: why || '' }).then(function (r) {
        if (!r.ok) return say(r.error || 'Could not record that.');
        say(''); load();
      });
    }
  });

  /* ── the chair's queue ──────────────────────────────────────────────── */

  function renderQueue() {
    if (!S.queue.length) { qCard.hidden = true; return; }
    qCard.hidden = false;
    qCount.textContent = String(S.queue.length);
    var opts = S.members.map(function (m) { return '<option value="' + m.id + '">' + esc(m.name) + '</option>'; }).join('');
    qList.innerHTML = S.queue.map(function (c) {
      // The suggestion is pre-selected, so confirming is one click when the
      // assistant guessed right and a dropdown when it did not.
      var sel = '<select class="cmt-who" data-for="' + c.id + '" aria-label="Who owns this">'
              + '<option value="0">Choose someone…</option>' + opts + '</select>';
      var heard = c.owner_hint ? ' · the assistant heard “' + esc(c.owner_hint) + '”' : ' · no name was heard';
      return '<li class="task-item"><div class="task-main">'
        + '<div class="task-title">' + esc(c.title) + '</div>'
        + '<div class="task-meta">' + (c.due ? 'due ' + esc(c.due) : 'no deadline') + heard + '</div>'
        + '<div class="task-ops">' + sel
        + '<button type="button" class="btn btn-outline btn-sm" data-assign="' + c.id + '">Confirm</button>'
        + '<button type="button" class="btn btn-outline btn-sm" data-drop="' + c.id + '">Not a commitment</button>'
        + '</div></div></li>';
    }).join('');
    S.queue.forEach(function (c) {
      var sug = Number(c.suggested_member_id || 0);
      if (!sug) return;
      var el = qList.querySelector('.cmt-who[data-for="' + c.id + '"]');
      if (el) el.value = String(sug);
    });
  }

  qList.addEventListener('click', function (e) {
    var a = e.target.closest('[data-assign]'), d = e.target.closest('[data-drop]');
    if (a) {
      var id = +a.getAttribute('data-assign');
      var sel = qList.querySelector('.cmt-who[data-for="' + id + '"]');
      var who = sel ? +sel.value : 0;
      if (!who) return say('Choose who owns it first.');
      post('assign', { id: id, member_id: who }).then(function (r) {
        if (!r.ok) return say(r.error || 'Could not confirm that.');
        say(''); load();
      });
    }
    if (d) {
      if (!window.confirm('Drop this? It stops being tracked.')) return;
      post('cancel', { id: +d.getAttribute('data-drop') }).then(function (r) {
        if (!r.ok) return say(r.error || 'Could not drop that.');
        say(''); load();
      });
    }
  });

  /* ── load ───────────────────────────────────────────────────────────── */

  function load() {
    get('mine').then(function (d) {
      if (!d.ok) { list.innerHTML = '<li class="pc-empty task-empty">Could not load your commitments.</li>'; return; }
      S.open = d.open || []; S.settled = d.settled || []; S.stats = d.stats || null;
      S.requireReason = d.require_reason !== false;
      render();
    }).catch(function () {
      list.innerHTML = '<li class="pc-empty task-empty">Could not load your commitments.</li>';
    });
    get('queue').then(function (d) {
      if (!d.ok) return;
      S.queue = d.queue || []; S.members = d.members || [];
      renderQueue();
    }).catch(function () { /* the queue is optional furniture */ });
  }

  load();

  // The cards live in the Tasks view, which is hidden until the member navigates
  // to it — so a list loaded at page load can be minutes stale by the time it is
  // looked at. Refresh when the view opens, the same way tools.js and diary.js do.
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-goto="tasks"]') || e.target.closest('[data-view="tasks"]')) {
      setTimeout(load, 90);
    }
  });
})();
