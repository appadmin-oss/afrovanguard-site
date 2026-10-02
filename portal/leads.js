/* portal/leads.js — the follow-ups a member owes, inside the portal.
 *
 * ── IT LOADS WHEN THE PANE IS OPENED ────────────────────────────────────────
 * Not with the page. Reading these means an HTTP call to CACENTRE behind a
 * three-second timeout, and the dashboard must not wait on the other site for
 * a card most visits never look at.
 *
 * ── AND LOGGING IS THE POINT ────────────────────────────────────────────────
 * A list that says somebody owes a call and then sends them elsewhere to
 * record it rebuilds, in a new place, exactly the gap that left the centre's
 * workbook with ninety leads and no notes. So every row carries the log, the
 * summary is required, and the row only leaves the list once the log is
 * actually stored on the other side.
 */
(function () {
  'use strict';

  var card = document.getElementById('cacLeads');
  if (!card) return;

  var list  = document.getElementById('cacLeadsList');
  var msg   = document.getElementById('cacLeadsMsg');
  var sub   = document.getElementById('cacLeadsSub');
  var CSRF  = card.getAttribute('data-csrf') || '';
  /* `started`, not `loaded`: loaded is only true once the answer comes back,
     and two view events in quick succession would both see it false and both
     fetch. It is cleared on failure so a member can try again. */
  var loaded = false, started = false;

  function say(text, bad) {
    if (!msg) return;
    msg.hidden = !text;
    msg.textContent = text || '';
    msg.style.color = bad ? 'var(--av-red, #b3261e)' : '';
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  /* "3 days ago" reads faster than a date when the question is whether you are
     late. The exact date stays in the title attribute for when it matters. */
  function when(iso, overdue) {
    if (!iso) return 'no date';
    var d = new Date(iso + 'T00:00:00');
    if (isNaN(d)) return iso;
    var days = Math.round((d - new Date(new Date().toDateString())) / 86400000);
    if (days === 0) return 'today';
    if (days === 1) return 'tomorrow';
    if (days === -1) return 'yesterday';
    return days < 0 ? Math.abs(days) + ' days ago' : 'in ' + days + ' days';
  }

  function row(lead) {
    var li = el('li', 'lead' + (lead.overdue ? ' lead--late' : ''));

    var head = el('div', 'lead-head');
    head.appendChild(el('span', 'lead-name', lead.name));
    var meta = [lead.where, lead.stage].filter(Boolean).join(' · ');
    if (meta) head.appendChild(el('span', 'lead-meta', meta));
    li.appendChild(head);

    var line = el('div', 'lead-due');
    var due = el('span', 'lead-when' + (lead.overdue ? ' is-late' : ''), when(lead.due));
    if (lead.due) due.title = lead.due;
    line.appendChild(due);
    if (lead.what) line.appendChild(el('span', 'lead-what', lead.what));
    /* A number is what you act on. Not a WhatsApp link: normalising a Nigerian
       number into the form wa.me needs lives in CACENTRE, and a second copy
       here would be a second answer waiting to disagree with the first. */
    if (lead.phone) {
      var tel = el('a', 'lead-tel', lead.phone);
      tel.href = 'tel:' + lead.phone.replace(/[^\d+]/g, '');
      line.appendChild(tel);
    }
    li.appendChild(line);

    /* The log, folded away until wanted: five rows each showing three fields
       is a wall, and the common case is reading the list, not writing to it. */
    var open = el('button', 'lead-log-open', 'Log a conversation');
    open.type = 'button';
    li.appendChild(open);

    var form = el('form', 'lead-log');
    form.hidden = true;
    var what = el('input');
    what.type = 'text'; what.required = true; what.maxLength = 500;
    what.placeholder = 'What happened — even briefly';
    what.className = 'lead-in';
    var next = el('input');
    next.type = 'text'; next.maxLength = 160;
    next.placeholder = 'And then what (optional)';
    next.className = 'lead-in';
    var by = el('input');
    by.type = 'date'; by.className = 'lead-in lead-in--date';
    by.setAttribute('aria-label', 'Follow up again on');
    var save = el('button', 'pbtn', 'Save it');
    save.type = 'submit';
    form.appendChild(what); form.appendChild(next); form.appendChild(by); form.appendChild(save);
    li.appendChild(form);

    open.addEventListener('click', function () {
      form.hidden = !form.hidden;
      open.textContent = form.hidden ? 'Log a conversation' : 'Never mind';
      if (!form.hidden) what.focus();
    });

    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      if (!what.value.trim()) return;
      save.disabled = true;
      var res, d;
      try {
        res = await fetch('/portal/cac-leads.php?action=log', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
          credentials: 'same-origin',
          body: JSON.stringify({
            id: lead.id, summary: what.value.trim(),
            next_action: next.value.trim(), next_action_at: by.value
          })
        });
        d = await res.json();
      } catch (err) {
        save.disabled = false;
        say('The console did not answer. Nothing was recorded — try again in a moment.', true);
        return;
      }
      save.disabled = false;
      if (!d || !d.ok) { say((d && d.error) || 'That was not recorded.', true); return; }

      /* Only now: the conversation is stored on the other side, so the
         follow-up genuinely is not owed any more. Removing the row before the
         answer came back would show work as done that had not been written. */
      li.classList.add('lead--done');
      li.textContent = '';
      /* Back inside .lead-head, which is the flex row: appended straight to
         the <li> the two spans butt together and it reads "Allo PaulRecorded". */
      var done = el('div', 'lead-head');
      done.appendChild(el('span', 'lead-name', lead.name));
      done.appendChild(el('span', 'lead-meta',
        by.value ? 'Recorded. Back on ' + by.value + '.' : 'Recorded.'));
      li.appendChild(done);
      say('', false);
    });

    return li;
  }

  async function load() {
    if (started) return;
    started = true;
    say('Looking…', false);
    var res, d;
    try {
      res = await fetch('/portal/cac-leads.php', { credentials: 'same-origin' });
      d = await res.json();
    } catch (e) {
      started = false;
      say('Could not reach the console just now.', true);
      return;
    }
    if (!d || !d.ok) {
      started = false;
      say((d && d.error) || 'The console did not answer.', true);
      return;
    }

    loaded = true;
    list.textContent = '';
    (d.leads || []).forEach(function (l) { list.appendChild(row(l)); });

    var n = (d.leads || []).length;
    var late = (d.leads || []).filter(function (l) { return l.overdue; }).length;
    if (sub) {
      sub.textContent = n === 0
        ? 'Nobody is waiting on you in the console.'
        : n + (n === 1 ? ' person' : ' people') + ' waiting on you'
          + (late ? ', ' + late + ' overdue' : '') + '.';
    }
    say(n === 0 ? 'Nothing owed. That is the good state, not an empty screen.' : '', false);
  }

  document.addEventListener('portal:view', function (e) {
    var v = e && e.detail ? e.detail.view : '';
    if (v === 'tasks') load();
  });
  if (location.hash === '#tasks') load();
})();
