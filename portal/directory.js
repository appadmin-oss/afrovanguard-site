/* ============================================================
   portal/directory.js — member profile hover/tap cards.
   Any element with [data-member="<id>"] shows a rich card on
   hover/focus (and on click/Enter, pinned) with role, cohort,
   local time, headline and skills. Fetched once, then cached.
   ============================================================ */
(function () {
  'use strict';
  if (document.getElementById('avMemberCard')) return;
  var csrfEl = document.querySelector('[data-csrf]');
  var csrf = csrfEl ? (csrfEl.getAttribute('data-csrf') || '') : '';
  var cache = {}, pop = null, hideT = null, curId = null, pinned = false;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function ensurePop() {
    if (pop) return pop;
    pop = document.createElement('div');
    pop.id = 'avMemberCard'; pop.className = 'mcard'; pop.hidden = true;
    pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-label', 'Member profile');
    document.body.appendChild(pop);
    pop.addEventListener('mouseenter', function () { clearTimeout(hideT); });
    pop.addEventListener('mouseleave', function () { if (!pinned) scheduleHide(); });
    return pop;
  }
  function scheduleHide() { clearTimeout(hideT); hideT = setTimeout(hide, 220); }
  function hide() { if (pop) { pop.hidden = true; } curId = null; pinned = false; }

  function render(card) {
    var skills = (card.skills || []).map(function (s) { return '<span class="mcard-skill">' + esc(s) + '</span>'; }).join('');
    var meta = [card.role]; if (card.level) meta.push('Level ' + card.level);
    var mine = card.is_me
      ? '<button type="button" class="mcard-edit" data-edit-skills>' + (card.skills && card.skills.length ? 'Edit skills' : 'Add your skills') + '</button>'
      : '';
    return '<div class="mcard-top"><span class="mcard-av">' + esc(card.initial) + '</span>'
      + '<div class="mcard-id"><span class="mcard-name">' + esc(card.name) + (card.is_me ? ' <em>(you)</em>' : '') + '</span>'
      + '<span class="mcard-role">' + esc(meta.join(' · ')) + '</span></div></div>'
      + (card.local_time ? '<div class="mcard-row">🕒 <b>' + esc(card.local_time) + '</b> local' + (card.tz ? ' <span class="mcard-tz">' + esc(card.tz.split('/').pop().replace('_', ' ')) + '</span>' : '') + '</div>' : '')
      + (card.headline ? '<p class="mcard-headline">' + esc(card.headline) + '</p>' : '')
      + (skills ? '<div class="mcard-skills">' + skills + '</div>' : (card.is_me ? '' : ''))
      + (mine ? '<div class="mcard-foot">' + mine + '</div>' : '');
  }

  function place(target) {
    var r = target.getBoundingClientRect();
    ensurePop();
    pop.style.visibility = 'hidden'; pop.hidden = false;
    var pw = pop.offsetWidth, ph = pop.offsetHeight;
    var top = r.bottom + 8, left = r.left;
    if (top + ph > window.innerHeight - 8) top = Math.max(8, r.top - ph - 8);
    if (left + pw > window.innerWidth - 8) left = Math.max(8, window.innerWidth - pw - 8);
    pop.style.top = (top + window.scrollY) + 'px';
    pop.style.left = (left + window.scrollX) + 'px';
    pop.style.visibility = '';
  }
  function show(target, id) {
    curId = id; ensurePop();
    function paint(card) { if (curId !== id) return; pop.innerHTML = render(card); place(target); }
    if (cache[id]) { paint(cache[id]); return; }
    pop.innerHTML = '<div class="mcard-loading">Loading…</div>'; place(target);
    fetch('/portal/directory.php?action=card&id=' + id, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.ok) { cache[id] = d.card; paint(d.card); } else { if (curId === id) hide(); } })
      .catch(function () { if (curId === id) hide(); });
  }

  document.addEventListener('mouseover', function (e) {
    var t = e.target.closest('[data-member]'); if (!t) return;
    clearTimeout(hideT); var id = +t.getAttribute('data-member'); if (!id || pinned) return;
    hideT = setTimeout(function () { show(t, id); }, 160);
  });
  document.addEventListener('mouseout', function (e) {
    var t = e.target.closest('[data-member]'); if (!t || pinned) return;
    if (pop && pop.contains(e.relatedTarget)) return;
    scheduleHide();
  });
  document.addEventListener('click', function (e) {
    var edit = e.target.closest('[data-edit-skills]');
    if (edit) {
      var cur = (cache[curId] && cache[curId].skills || []).join(', ');
      var v = prompt('Your skills (comma-separated, up to 8):', cur); if (v === null) return;
      fetch('/portal/directory.php?action=set_skills', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ skills: v }) })
        .then(function (r) { return r.json(); }).then(function (d) { if (d && d.ok) { cache[d.card.id] = d.card; pop.innerHTML = render(d.card); } });
      return;
    }
    var t = e.target.closest('[data-member]');
    if (t) { var id = +t.getAttribute('data-member'); if (id) { pinned = false; show(t, id); pinned = true; } return; }
    if (pop && !pop.contains(e.target)) hide();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') hide();
    if ((e.key === 'Enter' || e.key === ' ') && e.target.closest && e.target.closest('[data-member]')) {
      var t = e.target.closest('[data-member]'); e.preventDefault(); var id = +t.getAttribute('data-member'); if (id) { show(t, id); pinned = true; }
    }
  });
  window.addEventListener('scroll', function () { if (!pinned) hide(); }, true);
})();
