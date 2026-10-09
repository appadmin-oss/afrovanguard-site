/* Mentor portal behaviour. Prefix avm-. No dependencies. Progressive: every view works without JS except inline forms.
   API: /mentorship/api.php?action=… JSON, header X-CSRF from <meta name="csrf-token">. Server re-checks ownership on every id. */
(function () {
  'use strict';
  var root = document.querySelector('.avm'); if (!root) return;
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var typing = function (e) { var t = e.target; return t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)); };
  function api(action, data) {
    if (!navigator.onLine) return Promise.reject(new Error('offline'));
    return fetch('/mentorship/api.php?action=' + action, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: JSON.stringify(data || {}) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw j; return j; }); });
  }
  var fail = function (e) { toast(e && e.message === 'offline' ? 'You’re offline. Try again when you reconnect.' : (e && e.error) || 'That didn’t save. Try again.'); };

  /* toast: plain 4 s; with undo 6.5 s */
  var T = $('[data-avm-toast]'), tT;
  function toast(msg, undo) {
    $('span', T).textContent = msg; var b = $('button', T); b.hidden = !undo;
    b.onclick = undo ? function () { T.hidden = true; undo(); } : null;
    T.hidden = false; clearTimeout(tT); tT = setTimeout(function () { T.hidden = true; }, undo ? 6500 : 4000);
  }

  /* dialogs: native <dialog>; focus returns to trigger */
  var lastTrigger;
  function openDialog(id, trigger) { var d = document.getElementById(id); lastTrigger = trigger || document.activeElement; d.showModal(); return d; }
  $$('dialog.avm-dialog').forEach(function (d) { d.addEventListener('close', function () { if (lastTrigger) lastTrigger.focus(); }); });
  function confirmDialog(title, body, okLabel, danger) {
    return new Promise(function (res) {
      var d = document.getElementById('avm-d-confirm'), ok = $('[data-avm-confirm-ok]', d);
      $('#avm-d-confirm-h').textContent = title; $('#avm-d-confirm-p').textContent = body; ok.textContent = okLabel;
      ok.className = 'avm-btn ' + (danger ? 'avm-btn--danger' : 'avm-btn--ink');
      d.returnValue = ''; openDialog('avm-d-confirm');
      d.addEventListener('close', function h() { d.removeEventListener('close', h); res(d.returnValue === 'ok'); });
    });
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-avm-open]'); if (!t) return;
    var d = openDialog('avm-d-' + t.dataset.avmOpen, t);
    if (t.dataset.topic) { d.querySelector('[name=topic]').value = t.dataset.topic; $('#avm-d-support-h').textContent = t.dataset.topic; }
  });

  /* schedule dialog */
  var sf = $('[data-avm-sched]');
  if (sf) sf.closest('dialog').addEventListener('close', function () {
    var d = sf.closest('dialog'); if (d.returnValue !== 'ok') return;
    var data = Object.fromEntries(new FormData(sf));
    api('session-create', data).then(function () { toast('Session scheduled. They see it in their portal.'); sf.reset(); }).catch(fail);
  });
  var sup = $('[data-avm-support]');
  if (sup) sup.closest('dialog').addEventListener('close', function () {
    if (sup.closest('dialog').returnValue !== 'ok') return;
    api('support', Object.fromEntries(new FormData(sup))).then(function () { toast('Sent to your coordinator.'); sup.reset(); }).catch(fail);
  });

  /* offline banner */
  var off = $('[data-avm-offline]');
  function net() { if (off) off.hidden = navigator.onLine; root.classList.toggle('is-offline', !navigator.onLine); }
  addEventListener('online', net); addEventListener('offline', net); net();

  /* keyboard: / search · J/K case nav · N schedule · ? shortcuts. Esc handled by <dialog>. */
  document.addEventListener('keydown', function (e) {
    if (typing(e) || e.metaKey || e.ctrlKey || e.altKey || $('dialog[open]')) return;
    var k = e.key;
    if (k === '/') { e.preventDefault(); var q = $('[data-avm-q]'); if (q) q.focus(); else location.href = '?v=mentees#q'; }
    else if (k === '?') openDialog('avm-d-keys');
    else if (k === 'n' || k === 'N') openDialog('avm-d-sched');
    else if ((k === 'j' || k === 'J') && $('[data-avm-next]')) location.href = $('[data-avm-next]').href;
    else if ((k === 'k' || k === 'K') && $('[data-avm-prev]')) location.href = $('[data-avm-prev]').href;
  });
  if (location.hash === '#q' && $('[data-avm-q]')) $('[data-avm-q]').focus();

  /* ===== roster ===== */
  var form = $('[data-avm-roster]');
  if (form) {
    var q = $('[data-avm-q]'), sSel = $('[data-avm-s]'), rows = $('[data-avm-rows]'), table = $('[data-avm-table]'), dt;
    function go() {   // URL is the state; server renders. Debounced search keeps typing smooth.
      var p = new URLSearchParams(location.search); p.set('v', 'mentees'); p.delete('p');
      q.value.trim() ? p.set('q', q.value.trim()) : p.delete('q'); p.set('s', sSel.value);
      if (table) { table.setAttribute('aria-busy', 'true'); rows.innerHTML = new Array(11).join('<div class="avm-skel"></div>'); }
      location.search = p.toString();
    }
    q.addEventListener('input', function () { clearTimeout(dt); dt = setTimeout(go, 250); });
    sSel.addEventListener('change', go);
    form.addEventListener('submit', function (e) { e.preventDefault(); go(); });
    var more = $('[data-avm-more]');
    if (more) more.addEventListener('click', function (e) {
      e.preventDefault();
      var p = new URLSearchParams(location.search), next = +more.dataset.next; p.set('p', next); p.set('partial', 'rows');
      more.setAttribute('aria-disabled', 'true');
      fetch('?' + p, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
        var tmp = document.createElement('div'); tmp.innerHTML = html; var first = tmp.firstElementChild;
        while (tmp.firstChild) rows.appendChild(tmp.firstChild);
        p.delete('partial'); history.replaceState(null, '', '?' + p);
        var shown = rows.children.length, total = +($('[data-avm-shown]').textContent.match(/of (\d+)/) || [0, 0])[1];
        $('[data-avm-shown]').textContent = $('[data-avm-shown]').textContent.replace(/Showing \d+/, 'Showing ' + shown);
        if (shown >= total) more.remove(); else { more.dataset.next = next + 1; more.removeAttribute('aria-disabled'); }
        var link = first && first.querySelector('.avm-who'); if (link) link.focus();   // focus first new row
        syncAll();
      });
    });

    /* selection + bulk */
    var bulk = $('[data-avm-bulk]'), all = $('[data-avm-all]'), picked = new Set();
    function syncAll() {
      var boxes = $$('[data-avm-pick]', rows);
      boxes.forEach(function (b) { b.checked = picked.has(b.value); b.closest('.avm-tr').classList.toggle('is-picked', b.checked); });
      if (all) { var n = boxes.filter(function (b) { return b.checked; }).length; all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
      if (bulk) { bulk.hidden = picked.size === 0; $('[data-avm-pickn]').textContent = picked.size; }
    }
    if (rows) rows.addEventListener('change', function (e) { var b = e.target.closest('[data-avm-pick]'); if (!b) return; b.checked ? picked.add(b.value) : picked.delete(b.value); syncAll(); });
    if (all) all.addEventListener('change', function () { $$('[data-avm-pick]', rows).forEach(function (b) { all.checked ? picked.add(b.value) : picked.delete(b.value); }); syncAll(); });
    if (bulk) {
      $('[data-avm-bulk-clear]').addEventListener('click', function () { picked.clear(); syncAll(); });
      $$('[data-avm-bulk-act]', bulk).forEach(function (b) { b.addEventListener('click', function () {
        var ids = Array.from(picked).slice(0, 100), act = b.dataset.avmBulkAct, prev = new Set(picked);
        if (act === 'group') {
          var d = openDialog('avm-d-sched', b); d.querySelector('[name=group_ids]').value = ids.join(',');
          d.querySelector('[name=type]').value = 'Skills'; d.querySelector('[name=agenda]').value = 'Group session for ' + ids.length + ' mentees';
          return;
        }
        api('bulk', { action: act, ids: ids }).then(function (r) {
          var msg = act === 'checkin' ? 'Check-in requested from ' + r.ok + ' mentees' : 'Message drafted to ' + r.ok + ' mentees. They receive it in their portal.';
          if (r.failed && r.failed.length) msg = (act === 'checkin' ? 'Requested from ' : 'Sent to ') + r.ok + ' of ' + ids.length + '. ' + r.failed.length + ' failed: ' + r.failed.map(function (f) { return f.name; }).join(', ');
          picked.clear(); syncAll();
          toast(msg, act === 'checkin' ? function () { api('bulk-undo', { token: r.undo }).then(function () { prev.forEach(function (v) { picked.add(v); }); syncAll(); }); } : null);
        }).catch(fail);
      }); });
    }
    syncAll();
  }

  /* ===== case file ===== */
  /* Goals: the server draws the panel, here and after every write, so a goal
     the browser shows and a goal the database holds cannot disagree. One
     form serves add and edit; it is populated from the row's own data-*. */
  (function () {
    if (!$('[data-avm-goals]')) return;
    var pairing = $('[data-avm-goals]').dataset.pairing, first = $('[data-avm-goals]').dataset.first;
    var panel = function () { return $('[data-avm-goals]'); };
    var form  = function () { var b = panel(); return b && $('[data-avm-goal-form]', b); };

    function refresh() {
      return fetch('?v=case&id=' + encodeURIComponent(pairing) + '&partial=goals', { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          var cur = panel(); if (!cur) return;
          var box = document.createElement('div'); box.innerHTML = html;
          if (box.firstElementChild) cur.parentNode.replaceChild(box.firstElementChild, cur);
        });
    }

    function openForm(d) {
      var b = panel(), f = $('[data-avm-goal-form]', b), el = b.elements;
      el.goal_id.value = d ? d.goal : '';
      el.title.value   = d ? d.title : '';
      el.measure.value = d ? d.measure : '';
      el.due.value     = d ? d.due : '';
      $('[data-avm-goal-formh]', f).textContent = d ? 'Edit this goal' : 'New goal';
      $('[data-avm-goal-aside]',  f).hidden = !d || d.status !== 'open';
      $('[data-avm-goal-back]',   f).hidden = !d || d.status === 'open';
      $('[data-avm-goal-remove]', f).hidden = !d;
      $('[data-avm-goal-err]', f).hidden = true;
      var add = $('[data-avm-goal-new]', b); if (add) add.hidden = true;
      f.hidden = false; el.title.focus();
    }
    function closeForm() {
      var b = panel(); if (!b) return;
      $('[data-avm-goal-form]', b).hidden = true;
      var add = $('[data-avm-goal-new]', b); if (add) { add.hidden = false; add.focus(); }
    }
    /* A refusal belongs beside the field that caused it, not in a toast that
       has gone by the time you look up. */
    function err(msg) {
      var f = form(); if (!f || f.hidden) return false;
      var p = $('[data-avm-goal-err]', f); p.textContent = msg; p.hidden = false; return true;
    }
    function write(action, data, msg) {
      data.pairing_id = pairing;
      return api(action, data)
        .then(refresh)
        .then(function () { if (msg) toast(msg); })
        .catch(function (x) { if (!(x && x.error && err(x.error))) fail(x); });
    }

    document.addEventListener('click', function (e) {
      var b = panel(); if (!b || !e.target.closest) return;
      var t = e.target.closest('button'); if (!t || !b.contains(t)) return;
      var gid = function () { return b.elements.goal_id.value; };

      if (t.hasAttribute('data-avm-goal-tick')) {
        var met = t.getAttribute('aria-pressed') === 'true';
        write('goal-status', { goal_id: t.dataset.goal, status: met ? 'open' : 'met' },
              met ? 'Back to working on it.' : 'Goal met. ' + first + ' sees it in their portal.');
      } else if (t.hasAttribute('data-avm-goal-edit')) {
        openForm({ goal: t.dataset.goal, title: t.dataset.title, measure: t.dataset.measure,
                   due: t.dataset.due, status: t.dataset.status });
      } else if (t.hasAttribute('data-avm-goal-new')) {
        openForm(null);
      } else if (t.hasAttribute('data-avm-goal-cancel')) {
        closeForm();
      } else if (t.hasAttribute('data-avm-goal-back')) {
        write('goal-status', { goal_id: gid(), status: 'open' }, 'Back on the list.');
      } else if (t.hasAttribute('data-avm-goal-aside')) {
        write('goal-status', { goal_id: gid(), status: 'dropped' }, 'Set aside. It stays in the record.');
      } else if (t.hasAttribute('data-avm-goal-remove')) {
        var g = gid();
        confirmDialog('Remove this goal?',
          'It goes from the record and from ' + first + '’s portal. “Set aside” keeps what you agreed and marks that you stopped.',
          'Remove', true).then(function (yes) { if (yes) write('goal-remove', { goal_id: g }, 'Goal removed.'); });
      }
    });

    document.addEventListener('submit', function (e) {
      var b = panel(); if (!b || e.target !== b) return;
      e.preventDefault();
      var el = b.elements;
      var d = { title: el.title.value.trim(), measure: el.measure.value.trim(), due: el.due.value };
      if (!d.title) { err('Write what you are both working towards.'); el.title.focus(); return; }
      if (el.goal_id.value) { d.goal_id = el.goal_id.value; write('goal-edit', d, 'Goal updated.'); }
      else write('goal-add', d, 'Goal saved. ' + first + ' sees it in their portal.');
    });
  }());

  var plan = $('[data-avm-plan]');
  if (plan) $('[data-avm-plan-go]', plan).addEventListener('click', function (e) {
    var out = $('[data-avm-plan-out]', plan); e.target.disabled = true;
    api('plan', { pairing_id: plan.dataset.pairing }).then(function (r) {   // r.lines = {Open,Check,Ask,Agree}; never suggests off-platform contact
      out.innerHTML = ''; ['Open', 'Check', 'Ask', 'Agree'].forEach(function (k) { var li = document.createElement('li'); li.innerHTML = '<b></b> '; li.firstChild.textContent = k + ':'; li.appendChild(document.createTextNode(r.lines[k])); out.appendChild(li); });
      out.hidden = false; e.target.disabled = false; e.target.textContent = 'Draft again';
    }).catch(function (x) { e.target.disabled = false; fail(x); });
  });
  var close = $('[data-avm-close]');
  if (close) {
    var cbs = $$('input[type=checkbox]', close), cgo = $('[data-avm-close-go]', close);
    var sync = function () { cgo.disabled = !cbs.every(function (c) { return c.checked; }); };
    cbs.forEach(function (c) { c.addEventListener('change', function () { sync(); api('close-step', { pairing_id: close.dataset.pairing, step: c.name, on: c.checked }).catch(fail); }); }); sync();
    cgo.addEventListener('click', function () {
      confirmDialog('Close the mentorship?', 'This ends the pairing and moves it to your history. Your mentee is told it has closed.', 'Close the mentorship', true).then(function (ok) {
        if (ok) api('close', { pairing_id: close.dataset.pairing }).then(function () { location.href = '?v=mentees'; }).catch(fail);
      });
    });
  }
  $$('[data-avm-log-open]').forEach(function (b) {
    var f = b.parentElement.querySelector('[data-avm-log]');
    b.addEventListener('click', function () { f.hidden = !f.hidden; b.setAttribute('aria-expanded', String(!f.hidden)); if (!f.hidden) f.querySelector('input').focus(); });
    f.addEventListener('submit', function (e) {
      e.preventDefault(); var row = b.closest('[data-session]'), fd = new FormData(f), mood = fd.get('mood');
      var data = { session_id: row.dataset.session, minutes: fd.get('minutes'), topics: fd.getAll('topics[]'), mood: mood, outcome: fd.get('outcome') };
      api('session-log', data).then(function (r) {
        f.hidden = true; b.outerHTML = '<span class="avm-pill avm-st--attended">Attended</span>';
        toast('Saved. ' + data.minutes + ' minutes added to your hours.', function () { api('session-unlog', { session_id: data.session_id }).then(function () { location.reload(); }); });
        if (mood === 'Worried' || mood === 'Upset') setTimeout(function () { toast('If something worried you, use Report a concern. It goes straight to the safeguarding lead.'); }, 6600);
      }).catch(fail);
    });
    var missed = f.querySelector('[data-avm-missed]');
    if (missed) missed.addEventListener('click', function () {
      var id = b.closest('[data-session]').dataset.session;
      api('session-missed', { session_id: id }).then(function () { f.hidden = true; b.outerHTML = '<span class="avm-pill avm-st--missed">Missed</span>'; toast('Marked as missed.', function () { api('session-unlog', { session_id: id }).then(function () { location.reload(); }); }); }).catch(fail);
    });
  });
  var msg = $('[data-avm-msg]');
  if (msg) msg.addEventListener('submit', function (e) {
    e.preventDefault(); var t = msg.body.value.trim(); if (!t) return;
    api('message', { pairing_id: msg.dataset.pairing, body: t }).then(function (r) {   // r.queued true outside 8am–8pm
      var d = document.createElement('div'); d.className = 'avm-msg me'; d.textContent = t;
      var s = document.createElement('small'); s.style.cssText = 'display:block;opacity:.7;margin-top:4px'; s.textContent = r.when + (r.queued ? ' · sends at 8am' : ''); d.appendChild(s);
      $('[data-avm-thread]').appendChild(d); msg.body.value = '';
    }).catch(fail);
  });

  /* ===== values ===== */
  var pick = $('[data-avm-values-pick]');
  if (pick) pick.addEventListener('change', function () { location.search = '?v=values&id=' + pick.value; });
  var vf = $('[data-avm-values]');
  if (vf) {
    var WEAK = /^(good|great|excellent|very good|nice|well done|ok|okay)[.!]?$/i;
    var weak = function (t) { t = (t || '').trim(); return t.length < 18 || WEAK.test(t); };
    var needs = function (fs) { var c = fs.querySelector('input:checked'); if (!c) return false; var lv = +c.value; return lv > 0 && (lv === 3 || lv > +fs.dataset.last); };
    function check(fs, showErr) {
      var ev = fs.querySelector('[data-avm-evidence]'), ta = ev.querySelector('textarea'), need = needs(fs), empty = !ta.value.trim(), isWeak = !empty && weak(ta.value);
      ev.hidden = !need;
      ev.querySelector('[data-avm-evmissing]').hidden = !(need && empty && showErr);
      ev.querySelector('[data-avm-evweak]').hidden = !(need && isWeak);
      return !need || (!empty && !isWeak);
    }
    $$('fieldset.avm-val', vf).forEach(function (fs) {
      fs.addEventListener('change', function () { check(fs, false); });
      fs.addEventListener('input', function () { check(fs, false); });
      $$('[data-avm-evchip]', fs).forEach(function (c) { c.addEventListener('click', function () { var ta = fs.querySelector('textarea'); ta.value = (ta.value.trim() ? ta.value.trim() + ' ' : '') + c.textContent + '.'; check(fs, false); }); });
    });
    vf.addEventListener('submit', function (e) {
      e.preventDefault();
      var sets = $$('fieldset.avm-val', vf), unrated = sets.filter(function (fs) { return !fs.querySelector('input:checked'); });
      var bad = sets.filter(function (fs) { return !check(fs, true); });
      if (unrated.length) { toast('Rate all seven values. Use Not seen if you didn’t see it.'); unrated[0].querySelector('input').focus(); return; }
      if (bad.length) { bad[0].querySelector('textarea').focus(); return; }
      api('values', Object.assign({ pairing_id: vf.dataset.pairing }, Object.fromEntries(new FormData(vf)))).then(function (r) {
        toast('Saved to their Quest record.'); location.search = r.next_id ? '?v=values&id=' + r.next_id : '?v=values';
      }).catch(fail);
    });
  }

  /* ===== check-ins ===== */
  $$('[data-avm-checkin]').forEach(function (f) {
    var flag = $('[data-avm-ckflag]', f);
    var isFlag = function () { var fd = new FormData(f); return fd.get('q0') === '2' || fd.get('q1') === '2' || +fd.get('q2') >= 1; };
    f.addEventListener('change', function () { flag.hidden = !isFlag(); });
    f.addEventListener('submit', function (e) {
      e.preventDefault(); if (!f.checkValidity()) { f.reportValidity(); return; }
      api('checkin', Object.assign({ pairing_id: f.dataset.pairing, flag: isFlag() }, Object.fromEntries(new FormData(f)))).then(function () {
        var det = f.closest('details'); f.remove(); det.open = false; det.querySelector('summary .avm-link').outerHTML = '<span class="avm-pill avm-st--attended">Sent</span>';
        toast('Check-in sent to ' + f.querySelector('[type=submit]').textContent.replace('Send to ', ''));
      }).catch(fail);
    });
  });

  /* ===== reflections ===== */
  $$('[data-avm-reflect]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault(); var t = e.submitter.value;
      api('reflect-reply', { entry_id: f.dataset.entry, reply: t }).then(function (r) {
        var p = document.createElement('p'); p.style.cssText = 'margin:0;font-size:13px;color:var(--av-green);font-weight:600'; p.textContent = 'You replied: “' + t + '”';
        f.replaceWith(p); toast('Reply sent to ' + r.first, function () { api('reflect-unreply', { entry_id: f.dataset.entry }).then(function () { location.reload(); }); });
      }).catch(fail);
    });
  });

  /* ===== requests ===== */
  $$('[data-request]').forEach(function (s) {
    var id = s.dataset.request, acc = $('[data-avm-accept]', s), dec = $('[data-avm-decline]', s);
    acc.addEventListener('click', function () { api('request-accept', { id: id }).then(function (r) { location.href = '?v=case&id=' + r.pairing_id; }).catch(fail); });
    dec.addEventListener('click', function () {
      s.hidden = true;
      api('request-decline', { id: id }).then(function (r) { toast('Declined. We sent a kind note.', function () { api('request-undecline', { id: id, token: r.undo }).then(function () { s.hidden = false; }); }); }).catch(function (x) { s.hidden = false; fail(x); });
    });
  });

  /* ===== profile live preview + switch ===== */
  $$('[data-pv]').forEach(function (i) { i.addEventListener('input', function () { var o = $('[data-pv-out="' + i.dataset.pv + '"]'); if (o) o.textContent = i.value; }); });
  $$('[data-avm-switch]').forEach(function (b) {
    b.addEventListener('click', function () {
      var on = b.getAttribute('aria-checked') !== 'true', h = b.closest('form').querySelector('[name="' + b.dataset.avmSwitch + '"]');
      b.setAttribute('aria-checked', String(on)); b.style.background = on ? 'var(--av-green)' : 'var(--av-line-2)'; b.firstElementChild.style.left = on ? '21px' : '3px'; h.value = on ? 1 : 0;
    });
  });
  var pf = $('[data-avm-profile]');
  if (pf) pf.addEventListener('submit', function (e) { e.preventDefault(); api('profile', Object.fromEntries(new FormData(pf))).then(function () { toast('Profile saved.'); }).catch(fail); });

  /* ===== academy scenario ===== */
  var sc = $('[data-avm-scenario]');
  if (sc) sc.addEventListener('change', function (e) { var i = e.target; var w = $('[data-avm-why]', sc); w.hidden = false; w.textContent = (i.dataset.best === '1' ? 'Best choice. ' : 'Less helpful. ') + i.dataset.why; });

  /* ===== report a concern ===== */
  var cf = $('[data-avm-concern]');
  if (cf) cf.addEventListener('submit', function (e) {
    e.preventDefault(); var err = $('[data-avm-err]', cf), fd = new FormData(cf);
    err.hidden = true;
    if (!fd.get('category')) { err.textContent = 'Choose what it is about.'; err.hidden = false; cf.querySelector('[name=category]').focus(); return; }
    if ((fd.get('facts') || '').trim().length < 10) { err.textContent = 'Write at least a sentence about what happened.'; err.hidden = false; cf.facts.focus(); return; }
    confirmDialog('Send to safeguarding?', 'The safeguarding lead will read this today. Only they can see it.', 'Send to safeguarding', true).then(function (ok) {
      if (!ok) return;
      api('concern', Object.fromEntries(fd)).then(function (r) {
        cf.hidden = true; var done = $('[data-avm-concern-done]'); done.hidden = false; $('[data-avm-caseno]', done).textContent = r.case_no; done.querySelector('h2').setAttribute('tabindex', '-1'); done.querySelector('h2').focus();
      }).catch(fail);
    });
  });

  /* ===== coordinator note ===== */
  $$('[data-avm-ack]').forEach(function (b) { b.addEventListener('click', function () { if (b.getAttribute('aria-pressed') === 'true') return; b.setAttribute('aria-pressed', 'true'); b.textContent = 'Acknowledged ✓'; api('ack', { id: b.dataset.avmAck }).catch(fail); }); });
})();
