/* ============================================================================
 * give/manage.js — the appeals console.
 * Dependency-free. Every write goes through post(), which carries the CSRF
 * token, reports what actually happened, and re-enables the button whichever
 * way the request went — a form that stays disabled after a failed save is a
 * form somebody reloads, losing what they typed.
 * ==========================================================================*/
(function () {
  'use strict';

  var toastEl = document.getElementById('toast');
  var toastTimer = null;
  function toast(msg, isErr) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.toggle('is-err', !!isErr);
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, isErr ? 6000 : 3000);
  }

  /** POST a JSON action. Resolves with the payload, rejects with an Error whose
   *  message is safe to show — the server's own wording where there is one. */
  function post(payload) {
    return fetch(location.pathname + location.search, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.GIVE_CSRF || '' },
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().catch(function () {
        /* A non-JSON body means something upstream answered instead of the app
           — a proxy error page, a redirect to a login. Saying "server error"
           is more use than a JSON parse exception. */
        throw new Error('The server did not answer properly (' + r.status + '). Are you still signed in?');
      }).then(function (j) {
        if (!r.ok || !j.ok) throw new Error(j.error || ('Request failed (' + r.status + ').'));
        return j;
      });
    });
  }

  /** Run an action with a button's state managed for the whole round trip. */
  function withButton(btn, payload, okMsg, after) {
    var label = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Working…'; }
    return post(payload).then(function (j) {
      if (okMsg) toast(okMsg);
      if (after) after(j);
      return j;
    }).catch(function (err) {
      toast(err.message, true);
    }).finally(function () {
      if (btn) { btn.disabled = false; btn.textContent = label; }
    });
  }

  function formData(form) {
    var out = {};
    new FormData(form).forEach(function (v, k) { out[k] = v; });
    return out;
  }

  /* ── new appeal ────────────────────────────────────────────────────────── */
  var newBtn = document.getElementById('newAppeal');
  if (newBtn) {
    newBtn.addEventListener('click', function () {
      var title = window.prompt('What is this appeal called?\n\nYou can change the wording later — the web address is fixed from this first title, so make it the real one.');
      if (title === null) return;
      title = title.trim();
      if (!title) { toast('An appeal needs a title.', true); return; }
      withButton(newBtn, { action: 'save_appeal', title: title, status: 'draft' }, 'Appeal created', function (j) {
        location.href = '?a=' + j.id;
      });
    });
  }

  /* ── tabs ──────────────────────────────────────────────────────────────── */
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.gm-tab'));
  function selectTab(tab) {
    tabs.forEach(function (t) {
      var on = t === tab;
      t.classList.toggle('is-on', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      var pane = document.getElementById(t.getAttribute('aria-controls'));
      if (pane) pane.hidden = !on;
    });
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { selectTab(t); });
    /* Arrow keys move between tabs — the pattern a screen-reader user expects
       from role="tablist", and free to support. */
    t.addEventListener('keydown', function (ev) {
      var d = ev.key === 'ArrowRight' ? 1 : ev.key === 'ArrowLeft' ? -1 : 0;
      if (!d) return;
      ev.preventDefault();
      var next = tabs[(i + d + tabs.length) % tabs.length];
      next.focus(); selectTab(next);
    });
  });

  /* ── status chips ──────────────────────────────────────────────────────── */
  document.querySelectorAll('[data-status]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var status = chip.getAttribute('data-status');
      if (status === 'live' && !window.confirm('Publish this appeal?\n\nIt becomes a public page, enters the sitemap and starts accepting donations.')) return;
      withButton(chip, { action: 'set_status', id: window.GIVE_APPEAL, status: status }, 'Now ' + status, function () {
        document.querySelectorAll('[data-status]').forEach(function (c) { c.classList.toggle('is-on', c === chip); });
      });
    });
  });

  /* ── the appeal form ───────────────────────────────────────────────────── */
  var appealForm = document.getElementById('appealForm');
  if (appealForm) {
    appealForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var d = formData(appealForm);
      d.action = 'save_appeal';
      var btn = appealForm.querySelector('button[type=submit]');
      withButton(btn, d, 'Saved', function () {
        var saved = document.getElementById('appealSaved');
        if (saved) { saved.textContent = 'Saved'; setTimeout(function () { saved.textContent = ''; }, 2600); }
        var t = document.getElementById('selTitle');
        if (t && d.title) t.textContent = d.title;
      });
    });

    var del = document.getElementById('deleteAppeal');
    if (del) del.addEventListener('click', function () {
      if (!window.confirm('Delete this appeal for good?\n\nThis cannot be undone. An appeal that has received donations cannot be deleted at all — close it instead.')) return;
      withButton(del, { action: 'delete_appeal', id: window.GIVE_APPEAL }, 'Deleted', function () {
        location.href = '/give/manage.php';
      });
    });
  }

  /* ── needs ─────────────────────────────────────────────────────────────── */
  var needForm = document.getElementById('needForm');
  if (needForm) {
    needForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var d = formData(needForm);
      d.action = 'post_need';
      var btn = needForm.querySelector('button[type=submit]');
      withButton(btn, d, 'Need posted', function () { location.reload(); });
    });
  }
  document.querySelectorAll('[data-meet]').forEach(function (b) {
    b.addEventListener('click', function () {
      withButton(b, { action: 'meet_need', need_id: +b.getAttribute('data-meet') }, 'Marked met', function () { location.reload(); });
    });
  });
  document.querySelectorAll('[data-delneed]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!window.confirm('Delete this need?')) return;
      withButton(b, { action: 'delete_need', need_id: +b.getAttribute('data-delneed') }, 'Deleted', function () { location.reload(); });
    });
  });

  /* ── updates ───────────────────────────────────────────────────────────── */
  var updForm = document.getElementById('updateForm');
  if (updForm) {
    updForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var d = formData(updForm);
      d.action = 'post_update';
      var btn = updForm.querySelector('button[type=submit]');
      withButton(btn, d, 'Update posted', function () { location.reload(); });
    });
  }
  document.querySelectorAll('[data-delupdate]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!window.confirm('Delete this update?')) return;
      withButton(b, { action: 'delete_update', update_id: +b.getAttribute('data-delupdate') }, 'Deleted', function () { location.reload(); });
    });
  });

  /* ── emailing donors ───────────────────────────────────────────────────── */
  document.querySelectorAll('[data-mail]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!window.confirm('Email everyone who gave to this appeal?\n\nThe first batch goes now and the cron finishes the rest. Anyone already sent this update is skipped, so pressing it twice is safe.')) return;
      withButton(b, { action: 'mail_update', update_id: +b.getAttribute('data-mail') }, null, function (j) {
        var parts = [];
        if (j.sent)      parts.push(j.sent + ' sent');
        if (j.remaining) parts.push(j.remaining + ' queued for the cron');
        if (j.skipped)   parts.push(j.skipped + ' already had it');
        toast(j.note || (parts.length ? parts.join(' · ') : 'Nothing to send'));
      });
    });
  });

  /* ── recurring gifts ───────────────────────────────────────────────────── */
  document.querySelectorAll('[data-stopsub]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!window.confirm('Cancel this recurring gift?\n\nIt stops at Paystack as well as here, and cannot be restarted from this page — the donor would have to set it up again.')) return;
      withButton(b, { action: 'stop_recurring', sub_code: b.getAttribute('data-stopsub') }, 'Cancelled', function () { location.reload(); });
    });
  });

  /* ── tiers ─────────────────────────────────────────────────────────────── */
  var rows = document.getElementById('tierRows');
  function bindDrop(scope) {
    (scope || document).querySelectorAll('[data-droprow]').forEach(function (b) {
      if (b.dataset.bound) return;
      b.dataset.bound = '1';
      b.addEventListener('click', function () {
        var row = b.closest('.gm-tier-row');
        /* Never remove the last row — an empty editor gives nowhere to type,
           and "add a tier" to get back to where you were is a puzzle. */
        if (row && rows.querySelectorAll('.gm-tier-row').length > 1) row.remove();
        else if (row) row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
      });
    });
  }
  bindDrop();
  var addTier = document.getElementById('addTier');
  if (addTier && rows) addTier.addEventListener('click', function () {
    var row = document.createElement('div');
    row.className = 'gm-tier-row';
    row.innerHTML = '<input class="t-amt" type="number" min="0" step="500" placeholder="5000">'
                  + '<input class="t-label" maxlength="80" placeholder="A week of lunches">'
                  + '<input class="t-impact" maxlength="200" placeholder="Feeds one child for five school days">'
                  + '<button type="button" class="gm-btn gm-ghost gm-sm" data-droprow>Remove</button>';
    rows.appendChild(row);
    bindDrop(rows);
    var first = row.querySelector('input');
    if (first) first.focus();
  });
  var saveTiers = document.getElementById('saveTiers');
  if (saveTiers && rows) saveTiers.addEventListener('click', function () {
    var tiers = [];
    rows.querySelectorAll('.gm-tier-row').forEach(function (r) {
      var amt = parseInt(r.querySelector('.t-amt').value, 10);
      if (!amt || amt <= 0) return;                  // a blank row is not an error, it is a blank row
      tiers.push({ amount_ngn: amt,
                   label: r.querySelector('.t-label').value,
                   impact: r.querySelector('.t-impact').value });
    });
    withButton(saveTiers, { action: 'save_tiers', appeal_id: window.GIVE_APPEAL, tiers: tiers },
               tiers.length + ' tier' + (tiers.length === 1 ? '' : 's') + ' saved', function () {
      var s = document.getElementById('tierSaved');
      if (s) { s.textContent = 'Saved'; setTimeout(function () { s.textContent = ''; }, 2600); }
    });
  });
})();
