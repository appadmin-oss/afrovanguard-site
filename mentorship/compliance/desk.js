/* mentorship/compliance/desk.js — the Quest desk, upgraded in place.
 *
 * Every action on the desk is a plain form post that works without this file.
 * With it, one guarded init per feature, so a missing element never stops the
 * rest (the same shape as portal/avp.js, which runs the shell):
 *   crumb    the header names the section that is open
 *   filters  search and facets over a table, with a live count
 *   bulk     select rows, see how many, act on all of them — the count is
 *            read before the second press, so a list changing underneath
 *            cannot change what was agreed to; per-row verdicts are reported
 *   ajax     forms marked data-qd-ajax post with ajax=1 and update in place:
 *            switches flip, prices change, decided cards leave
 *   drawer   one member panel, filled from the row; Esc closes, focus returns
 *   tabs     the catalogue's segmented tabs, arrow keys included
 *   confirm  a second press, not a dialog, for anything that moves money
 */
(function () {
  'use strict';
  var body = document.body;
  body.classList.add('qd-js');
  var page = document.querySelector('.qd-page');
  var CSRF = page ? page.getAttribute('data-csrf') : '';

  function run(name, fn) { try { fn(); } catch (e) { if (window.console) console.warn('qd ' + name, e); } }
  function $$(sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); }

  /* ── toast ───────────────────────────────────────────────────────────── */
  var toastEl = document.getElementById('qdToast'), toastT = 0;
  function toast(msg, bad) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.className = 'qd-flash ' + (bad ? 'is-bad' : 'is-ok');
    toastEl.setAttribute('data-pos', 'float');
    toastEl.setAttribute('role', bad ? 'alert' : 'status');
    toastEl.hidden = false;
    clearTimeout(toastT);
    toastT = setTimeout(function () { toastEl.hidden = true; }, bad ? 8000 : 4500);
  }

  var DONE = {
    approved: 'Approved — it is real now, and both people have been told.', rejected: 'Rejected — the mentor has been told why.',
    rule: 'Saved — it is in the catalogue’s history.', accepted: 'Done — the member has been told.',
    dismissed: 'Dismissed, with your reason on the record.', promoted: 'Promoted — the member and their Quest mentor have been told.',
    deferred: 'Set aside, with your reason.', journey: 'Journey level saved.', finding: 'Finding recorded.',
    resolved: 'Resolved — it stays on the record.', bracket: 'Saved.', kind: 'Saved.', applied: 'Applied — it is in the catalogue’s history.'
  };

  /* One POST with ajax=1. Resolves with the JSON either way; a network
     failure becomes {ok:false} with words a person can act on. */
  function post(form, submitter) {
    var fd = new FormData(form);
    if (submitter && submitter.name) fd.append(submitter.name, submitter.value);
    fd.append('ajax', '1');
    if (!fd.get('csrf') && CSRF) fd.append('csrf', CSRF);
    return fetch(form.getAttribute('action') || '/mentorship/conduct.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: r.status === 429 ? 'That is a lot at once. Give it a moment.' : 'The desk did not answer properly (' + r.status + '). Reload and try again.' }; }); })
      .catch(function () { return { ok: false, error: 'No connection — nothing was changed. Try again.' }; });
  }

  function badge(name, delta) {
    var b = document.querySelector('[data-badge="' + name + '"]');
    if (!b) return;
    var n = Math.max(0, (parseInt(b.textContent, 10) || 0) + delta);
    if (n === 0) b.remove(); else b.textContent = String(n);
  }

  /* ── crumb ───────────────────────────────────────────────────────────── */
  run('crumb', function () {
    var crumb = document.getElementById('crumbHere');
    if (!crumb) return;
    function sync(name) {
      var a = document.querySelector('.avp-nav-link[data-view="' + name + '"] .avp-nav-label');
      if (a) crumb.textContent = a.textContent.trim();
    }
    document.addEventListener('portal:view', function (e) { sync((e.detail && e.detail.view) || 'overview'); });
    sync((location.hash || '#overview').slice(1));
  });

  /* ── filters ─────────────────────────────────────────────────────────── */
  var refilter = {};
  run('filters', function () {
    $$('form[data-qd-filter]').forEach(function (form) {
      var table = document.getElementById(form.getAttribute('data-qd-filter'));
      if (!table) return;
      var q = form.querySelector('[data-qd-q]'), count = form.querySelector('[data-qd-count]');
      var cols = $$('[data-qd-col]', form);
      var rows = $$('tbody tr', table);
      var noun = count ? count.textContent.replace(/^[\d,]+\s*/, '') : '';
      function apply() {
        var needle = q ? q.value.trim().toLowerCase() : '';
        var shown = 0;
        rows.forEach(function (tr) {
          if (!tr.isConnected) return;
          var ok = !needle || tr.textContent.toLowerCase().indexOf(needle) !== -1;
          cols.forEach(function (sel) {
            var want = sel.value, attr = sel.getAttribute('data-qd-col');
            if (!want || !ok) return;
            var have = (tr.getAttribute('data-' + attr) || '').split(/\s+/);
            ok = have.indexOf(want) !== -1;
          });
          tr.hidden = !ok;
          if (ok) shown++;
        });
        if (count) count.textContent = shown + ' ' + (shown === rows.filter(function (r) { return r.isConnected; }).length ? noun : 'of ' + rows.filter(function (r) { return r.isConnected; }).length + ' shown');
      }
      form.addEventListener('submit', function (e) { e.preventDefault(); apply(); });
      if (q) q.addEventListener('input', apply);
      cols.forEach(function (s) { s.addEventListener('change', apply); });
      refilter[table.id] = apply;
    });
    /* "/" jumps to the open section's search, as in the Control Room. */
    document.addEventListener('keydown', function (e) {
      if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
      var t = e.target;
      if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
      var view = document.querySelector('.pview:not([hidden])');
      var input = view && view.querySelector('[data-qd-q]');
      if (input) { e.preventDefault(); input.focus(); }
    });
  });

  /* ── bulk ────────────────────────────────────────────────────────────── */
  run('bulk', function () {
    $$('form[data-qd-bulk]').forEach(function (form) {
      var table = document.getElementById(form.getAttribute('data-qd-bulk'));
      if (!table) return;
      var all = form.querySelector('[data-qd-all]'), bar = form.querySelector('[data-qd-bulkbar]'), nEl = form.querySelector('[data-qd-n]');
      var noun = form.getAttribute('data-noun') || 'row';
      function boxes() { return $$('input[data-qd-row]', table).filter(function (b) { return !b.closest('tr').hidden; }); }
      function picked() { return $$('input[data-qd-row]:checked', table); }
      function sync() {
        var p = picked(), v = boxes();
        if (nEl) nEl.textContent = String(p.length);
        if (bar) bar.hidden = p.length === 0;
        $$('tbody tr', table).forEach(function (tr) { var b = tr.querySelector('input[data-qd-row]'); tr.classList.toggle('is-picked', !!(b && b.checked)); });
        if (all) {
          var on = v.filter(function (b) { return b.checked; }).length;
          all.checked = v.length > 0 && on === v.length;
          all.indeterminate = on > 0 && on < v.length;
        }
      }
      table.addEventListener('change', function (e) {
        if (e.target === all) boxes().forEach(function (b) { b.checked = all.checked; });
        sync();
      });
      var armed = null, armT = 0;
      form.addEventListener('submit', function (e) {
        var btn = e.submitter;
        if (!btn || !/^bulk_/.test(btn.value)) return;   /* row buttons post their own forms */
        e.preventDefault();
        var p = picked();
        if (!p.length) return;
        var reject = btn.value === 'bulk_reject';
        var note = form.querySelector('input[name="note"]');
        if (reject && note && !note.value.trim()) { toast('Say why they are rejected — every mentor is told.', true); note.focus(); return; }
        /* The count is read now, and the second press confirms THIS count. */
        if (armed !== btn) {
          armed = btn; btn.setAttribute('data-label', btn.textContent);
          btn.textContent = (reject ? 'Reject ' : 'Approve ') + p.length + ' ' + noun + (p.length === 1 ? '' : 's') + '?';
          clearTimeout(armT);
          armT = setTimeout(function () { btn.textContent = btn.getAttribute('data-label'); armed = null; }, 5000);
          return;
        }
        clearTimeout(armT); armed = null;
        btn.textContent = btn.getAttribute('data-label');
        var ids = p.map(function (b) { return b.value; });
        var fd = new FormData();
        fd.append('csrf', CSRF); fd.append('op', btn.value); fd.append('ajax', '1');
        if (note) fd.append('note', note.value);
        ids.forEach(function (id) { fd.append('case_ids[]', id); });
        btn.disabled = true;
        fetch('/mentorship/conduct.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            var failed = {}; (j.failed || []).forEach(function (f) { failed[String(f.id)] = f.error; });
            ids.forEach(function (id) {
              if (failed[id]) return;
              var tr = table.querySelector('tr[data-id="' + id + '"]'); if (tr) tr.remove();
            });
            badge('cases', -(j.done || 0));
            var n = j.done || 0, bad = (j.failed || []).length;
            toast(n + ' ' + (reject ? 'rejected' : 'approved') + (bad ? '; ' + bad + ' could not be: ' + j.failed[0].error : '.'), bad > 0);
            if (note) note.value = '';
            if (refilter[table.id]) refilter[table.id]();
            sync();
          })
          .catch(function () { toast('No connection — nothing was decided. Try again.', true); })
          .then(function () { btn.disabled = false; });
      });
      sync();
    });

    /* One case at a time: the row's buttons post the row's own form. */
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('button[data-qd-one]');
      if (!btn) return;
      var form = document.getElementById(btn.getAttribute('form'));
      if (!form) return;
      e.preventDefault();
      var tr = btn.closest('tr');
      var note = tr && tr.querySelector('input[name="note"]');
      if (btn.value === 'reject' && note && !note.value.trim()) { toast('Write the reason in the box beside Reject — the mentor reads it.', true); note.focus(); return; }
      var fd = new FormData(form);
      fd.append('op', btn.value); fd.append('ajax', '1');
      if (note) fd.set('note', note.value);
      if (tr) tr.classList.add('is-busy');
      fetch('/mentorship/conduct.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (tr) tr.classList.remove('is-busy');
          if (!j.ok) { toast(j.error || 'Not decided.', true); return; }
          var table = tr && tr.closest('table');
          if (tr) tr.remove();
          badge('cases', -1);
          toast(DONE[j.done] || 'Done.');
          if (table && refilter[table.id]) refilter[table.id]();
        })
        .catch(function () { if (tr) tr.classList.remove('is-busy'); toast('No connection — nothing was decided.', true); });
    });
  });

  /* ── ajax forms ──────────────────────────────────────────────────────── */
  run('ajax', function () {
    function fmt(kind, v) {
      if (v === null || v === undefined) return 'each time';
      return kind === 'fine' ? '₦' + Number(v).toLocaleString('en-NG') : (kind === 'deduction' ? '−' : '+') + v;
    }
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form.matches || !form.matches('form[data-qd-ajax]')) return;
      e.preventDefault();
      var btn = e.submitter || form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      post(form, e.submitter).then(function (j) {
        if (btn) btn.disabled = false;
        if (!j.ok) { toast(j.error || 'Not saved.', true); return; }
        toast(DONE[j.done] || 'Saved.');
        var tr = form.closest('tr');
        if (form.hasAttribute('data-qd-switch') && j.item) {
          var sw = form.querySelector('[role="switch"]'), next = form.querySelector('[data-qd-next]');
          sw.setAttribute('aria-checked', j.item.enabled ? 'true' : 'false');
          next.value = j.item.enabled ? '0' : '1';
          if (tr) { tr.classList.toggle('is-off', !j.item.enabled); tr.classList.toggle('is-changed', !!j.item.overridden); }
        }
        if (form.hasAttribute('data-qd-price-form') && j.item && tr) {
          var cell = tr.querySelector('[data-qd-price] b');
          var kind = form.querySelector('input[name="kind"]').value;
          if (cell) cell.textContent = fmt(kind, j.item.value);
          tr.classList.toggle('is-changed', !!j.item.overridden);
          var why = form.querySelector('input[name="note"]'); if (why) why.value = '';
        }
        if (form.querySelector('input[name="op"][value="rule_reset"]') && j.item && tr) {
          var c2 = tr.querySelector('[data-qd-price] b'); if (c2) c2.textContent = fmt(form.querySelector('input[name="kind"]').value, j.item.value);
          var pin = tr.querySelector('input[name="value"]'); if (pin) pin.value = j.item.value === null ? '' : j.item.value;
          tr.classList.remove('is-changed', 'is-off');
          var s2 = tr.querySelector('[role="switch"]'); if (s2) { s2.setAttribute('aria-checked', 'true'); var n2 = tr.querySelector('[data-qd-next]'); if (n2) n2.value = '0'; }
          form.remove();
        }
        var rm = form.getAttribute('data-qd-remove');
        if (rm) {
          var gone = form.closest(rm);
          if (gone) gone.remove();
          if (j.done === 'accepted' || j.done === 'dismissed') badge('suggestions', -1);
        }
        var chip = form.getAttribute('data-qd-done-chip');
        if (chip) { var s = document.createElement('span'); s.className = 'avp-chip avp-chip--sm avp-chip--green'; s.textContent = chip; form.replaceWith(s); }
        if (j.done === 'finding' && j.id) addFinding(j.id, form);
        if (j.done === 'journey' || j.done === 'bracket') syncMemberRow(form);
      });
    });
    /* Selects that save themselves (mentor kinds). */
    document.addEventListener('change', function (e) {
      var f = e.target.closest && e.target.closest('form[data-qd-autosubmit]');
      if (f && e.target.tagName === 'SELECT') {
        if (f.requestSubmit) f.requestSubmit(); else f.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
      }
    });
  });

  /* ── confirm by a second press ───────────────────────────────────────── */
  run('confirm', function () {
    $$('form[data-qd-confirm]').forEach(function (form) {
      var armed = false, t = 0;
      form.addEventListener('submit', function (e) {
        var btn = form.querySelector('button[type="submit"]');
        if (armed) return;
        e.preventDefault();
        armed = true;
        var label = btn.textContent;
        btn.textContent = 'Press again to confirm';
        toast(form.getAttribute('data-qd-confirm'));
        t = setTimeout(function () { armed = false; btn.textContent = label; }, 6000);
      });
    });
    $$('form[data-qd-busy]').forEach(function (form) {
      form.addEventListener('submit', function () { form.setAttribute('aria-busy', 'true'); toast(form.getAttribute('data-qd-busy')); });
    });
  });

  /* ── the member drawer ───────────────────────────────────────────────── */
  var drawerEl = document.getElementById('qdMemberPanel'), opener = null, currentRow = null;
  function addFinding(id, fromForm) {
    var list = drawerEl && drawerEl.querySelector('[data-qd-findings]'), tpl = document.getElementById('qdFindingRow');
    if (!list || !tpl) return;
    var kindSel = fromForm.querySelector('select[name="finding_kind"]');
    var li = tpl.content.firstElementChild.cloneNode(true);
    li.querySelector('[data-t]').textContent = (kindSel ? kindSel.options[kindSel.selectedIndex].text : 'Finding') + ' · today';
    li.querySelector('[data-id]').value = id;
    list.appendChild(li);
    var ta = fromForm.querySelector('textarea'); if (ta) ta.value = '';
  }
  function syncMemberRow(form) {
    if (!currentRow) return;
    var data = JSON.parse(currentRow.getAttribute('data-member') || '{}');
    var js = form.querySelector('[data-qd-journey]');
    if (js) {
      data.journey = js.value;
      var cell = currentRow.children[2]; if (cell) cell.textContent = js.options[js.selectedIndex].text.replace(/^\d+\s*·\s*/, '');
    }
    var bs = form.querySelector('[data-qd-bracket]');
    if (bs) {
      var opt = bs.options[bs.selectedIndex];
      data.bracket = bs.value === '0' ? '' : opt.getAttribute('data-label');
    }
    currentRow.setAttribute('data-member', JSON.stringify(data));
  }
  run('drawer', function () {
    if (!drawerEl) return;
    var tpl = document.getElementById('qdFindingRow');
    function open(row, from) {
      var m = JSON.parse(row.getAttribute('data-member') || '{}');
      currentRow = row; opener = from || document.activeElement;
      drawerEl.querySelector('[data-qd-panel-name]').textContent = m.name || 'Member';
      $$('[data-qd-mid]', drawerEl).forEach(function (i) { i.value = m.id; });
      var js = drawerEl.querySelector('[data-qd-journey]'); if (js) js.value = m.journey || 'visitor';
      var bs = drawerEl.querySelector('[data-qd-bracket]');
      if (bs) { bs.value = '0'; $$('option', bs).forEach(function (o) { if (o.getAttribute('data-label') === m.bracket) bs.value = o.value; }); }
      var list = drawerEl.querySelector('[data-qd-findings]');
      if (list && tpl) {
        while (list.firstChild) list.removeChild(list.firstChild);
        (m.findings || []).forEach(function (f) {
          var li = tpl.content.firstElementChild.cloneNode(true);
          li.querySelector('[data-t]').textContent = f.kind + ' · ' + f.at;
          li.querySelector('[data-id]').value = f.id;
          list.appendChild(li);
        });
      }
      drawerEl.hidden = false;
      var first = drawerEl.querySelector('select, input:not([type="hidden"]), textarea, button');
      if (first) first.focus();
    }
    function close() {
      if (drawerEl.hidden) return;
      drawerEl.hidden = true;
      if (opener && opener.focus) opener.focus();
    }
    document.addEventListener('click', function (e) {
      var m = e.target.closest && e.target.closest('[data-qd-manage]');
      if (m) { e.preventDefault(); open(m.closest('tr'), m); return; }
      if (e.target.closest && e.target.closest('[data-qd-close]')) { e.preventDefault(); close(); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !drawerEl.hidden) { e.preventDefault(); close(); } });
    document.addEventListener('portal:view', function () { close(); });
  });

  /* ── tabs ────────────────────────────────────────────────────────────── */
  run('tabs', function () {
    $$('.qd-seg').forEach(function (seg) {
      var tabs = $$('[data-qd-tab]', seg);
      function pick(t, focus) {
        tabs.forEach(function (x) {
          var on = x === t;
          x.classList.toggle('is-on', on);
          x.setAttribute('aria-selected', on ? 'true' : 'false');
          x.tabIndex = on ? 0 : -1;
          var p = document.getElementById(x.getAttribute('aria-controls')); if (p) p.hidden = !on;
        });
        if (focus) t.focus();
      }
      tabs.forEach(function (t, i) {
        t.tabIndex = t.classList.contains('is-on') ? 0 : -1;
        t.addEventListener('click', function () { pick(t, false); });
        t.addEventListener('keydown', function (e) {
          var d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
          if (!d) return;
          e.preventDefault();
          pick(tabs[(i + d + tabs.length) % tabs.length], true);
        });
      });
    });
  });
})();
