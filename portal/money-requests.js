/* ============================================================
   portal/money-requests.js — asking CACENTRE finance for money.

   A member who paid a bus fare out of their own pocket, or needs
   something bought, asks here. The request goes to CFIS and joins the
   same approval queue as one typed at a finance desk; nothing on this
   page decides anything, and the statuses shown are finance's, not ours.

   Two things this client is careful about:

     · THE KINDS OF SPENDING come from finance when the card loads, never
       from a list kept here. Every property in this estate has at some
       point kept its own copy of a list CFIS owns, and every copy
       drifted. If finance cannot be reached the form says so instead of
       offering a guess.
     · A REQUEST THAT DID NOT ARRIVE is kept, with a "send it again"
       button. Sending again reuses the same reference, so a request that
       was in fact received cannot become a second one.

   And, since CFIS ADR 58: several lines in one request (progressive —
   without this script the one line posts as before), a needed-by date,
   finance's bank list with the bank's own name for the account shown
   before sending (never a reason not to send), where each request stands
   and why it was refused, a Withdraw button while nobody has decided, and
   the member's earlier requests from finance's own record.
   ============================================================ */
(function () {
  'use strict';

  var card = document.getElementById('moneyRequests');
  if (!card) return;                                   // not the portal (Requests view)

  var API  = '/portal/money-requests.php';
  var CSRF = card.getAttribute('data-csrf') || '';
  var list = card.querySelector('[data-mr-list]');
  var form = card.querySelector('[data-mr-form]');
  var kind = card.querySelector('[name=category]');
  var msg  = card.querySelector('[data-mr-msg]');
  var bank = card.querySelector('[data-mr-bank]');

  function say(text, bad) {
    msg.textContent = text;
    msg.hidden = !text;
    msg.classList.toggle('is-bad', !!bad);
  }

  /* Finance sends a plain decimal, which is right for a machine and
     wrong for a sentence: 500000.00 is not a figure anybody reads. */
  function money(v) {
    var s = String(v);
    var parts = s.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return '₦' + parts.join('.');
  }

  /* Finance owns the words for a status; this only decides the colour. */
  function tone(status, paid) {
    if (paid) return 'green';
    if (status === 'rejected' || status === 'cancelled' || status === 'withdrawn') return 'red';
    if (status === 'approved' || status === 'partially_approved') return 'green';
    return 'gold';
  }

  var hint = card.querySelector('[data-mr-hint]');
  var noReceipt = card.querySelector('[data-mr-noreceipt]');
  var cats = [];

  /* The words for each kind come from finance (category_list), with what
     it covers under the select. Keys alone are a heading code, not a
     sentence: "facilitation" means nothing to somebody who bought units. */
  function syncHint() {
    var c = cats.filter(function (x) { return x.key === kind.value; })[0];
    if (hint) hint.textContent = c && c.hint ? c.hint : '';
  }
  function syncKind() {
    var spent = (form.querySelector('[name=claim_kind]:checked') || {}).value === 'reimbursement';
    if (noReceipt) noReceipt.hidden = !spent;
  }
  kind.addEventListener('change', syncHint);
  form.addEventListener('change', function (e) { if (e.target.name === 'claim_kind') syncKind(); });

  function draw(data) {
    kind.innerHTML = '';
    cats = (data.category_list && data.category_list.length) ? data.category_list
      : (data.categories || []).map(function (c) { return { key: c, label: c.charAt(0).toUpperCase() + c.slice(1).replace(/_/g, ' '), hint: '' }; });
    cats.forEach(function (c) {
      var o = document.createElement('option');
      o.value = c.key;
      o.textContent = c.label;
      kind.appendChild(o);
    });
    syncHint();
    syncKind();
    var rule = card.querySelector('[data-mr-receipt-rule]');
    if (rule && data.receipt_over) rule.textContent = 'Asked for money already spent over ' + money(data.receipt_over) + '.';
    maxLines = data.max_lines || 10;
    addWrap.hidden = false;
    syncLineOptions();
    drawBanks(data.banks || []);
    drawEarlier(data.earlier || []);

    var reachable = !!data.finance_reachable;
    /* Asking is always open (data.accepting): a request finance cannot take
       yet is kept and sent by itself. Not set up, or set up and not
       answering, are still two different sentences under the form. */
    var off = data.finance_configured === false;
    form.hidden = data.accepting === false && !reachable;
    card.querySelector('[data-mr-down]').hidden = reachable || off;
    var offNote = card.querySelector('[data-mr-off]'); if (offNote) offNote.hidden = !off;
    card.querySelector('[data-mr-max]').hidden = false;
    if (reachable && data.max) {
      card.querySelector('[data-mr-max]').textContent = 'Up to ' + money(data.max) + ' through this form.';
    }

    var rows = data.requests || [];
    if (!rows.length) {
      list.innerHTML = '<p class="pc-empty">You have not asked for anything yet.</p>';
      return;
    }

    list.innerHTML = '';
    rows.forEach(function (r) {
      var li = document.createElement('div');
      li.className = 'mr-row';

      var head = document.createElement('div');
      head.className = 'mr-head';
      head.innerHTML = '<strong></strong><span class="pchip pchip--' + tone(r.status, r.paid) + '"></span>';
      head.querySelector('strong').textContent = r.title;
      head.querySelector('span').textContent = r.paid ? 'Paid'
        : (r.status === 'withdrawn' || r.status === 'cancelled' ? 'Withdrawn'
        : (r.sent ? String(r.status).replace(/_/g, ' ') : 'Waiting to send'));
      li.appendChild(head);

      var sub = document.createElement('p');
      sub.className = 'mr-sub';
      sub.textContent = money(r.amount) + ' · ' + r.category
        + (r.lines > 1 ? ' and ' + (r.lines - 1) + ' more' : '')
        + (r.claim_kind === 'reimbursement' ? ' · already paid' : '')
        + (r.receipts ? ' · ' + r.receipts + ' receipt' + (r.receipts === 1 ? '' : 's') : '')
        + ' · asked ' + r.asked_on + (r.needed_by ? ' · needed by ' + r.needed_by : '')
        + (r.reference ? ' · ' + r.reference : '');
      li.appendChild(sub);

      standing(li, r);

      if (r.can_withdraw) li.appendChild(withdrawButton(r));

      /* Only where it can act: a request finance already has is not
         offered a button that would do nothing. */
      if (!r.sent && r.status !== 'withdrawn') {
        var why = document.createElement('p');
        why.className = 'mr-sub is-bad';
        why.textContent = r.error || 'This has not reached finance yet. It is kept and goes by itself.';
        li.appendChild(why);
        /* Nothing to press while finance is not connected: it goes by itself. */
        if (off) { list.appendChild(li); return; }

        var again = document.createElement('button');
        again.type = 'button';
        again.className = 'pbtn pbtn-ghost';
        again.textContent = 'Send it again';
        again.addEventListener('click', function () {
          again.disabled = true;
          post({ id: r.id }, 'retry').then(function (res) {
            say(res.ok ? 'Sent — finance has it now.' : (res.error || 'Still cannot reach finance.'), !res.ok);
            load();
          });
        });
        li.appendChild(again);
      }

      list.appendChild(li);
    });
  }

  /* Where it is, in finance's words, and — only when it was refused —
     the reason the approver gave. A CFIS from before these sends neither. */
  function standing(li, r) {
    if (r.stage) {
      var st = document.createElement('p');
      st.className = 'mr-sub mr-stage';
      st.textContent = r.stage;
      li.appendChild(st);
    }
    if (r.reason) {
      var why = document.createElement('p');
      why.className = 'mr-sub mr-reason';
      why.textContent = 'Finance said: ' + r.reason;
      li.appendChild(why);
    }
  }

  function withdrawButton(r) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'pbtn pbtn-ghost';
    b.textContent = 'Withdraw';
    b.setAttribute('aria-label', 'Withdraw "' + r.title + '"');
    b.addEventListener('click', function () {
      /* A native confirm: keyboard and screen reader handle it as the
         browser's own dialog, and focus comes back to this button. */
      if (!window.confirm('Withdraw "' + r.title + '"? Finance will not consider it. This cannot be undone — you can ask again.')) return;
      b.disabled = true;
      post({ id: r.id }, 'withdraw').then(function (res) {
        say(res.ok ? (res.message || 'Withdrawn.') : (res.error || 'It could not be withdrawn.'), !res.ok);
        load();
      });
    });
    return b;
  }

  var earlier = card.querySelector('[data-mr-earlier]');
  function drawEarlier(rows) {
    if (!earlier) return;
    var box = earlier.querySelector('[data-mr-earlier-list]');
    box.innerHTML = '';
    earlier.hidden = !rows.length;
    rows.forEach(function (r) {
      var li = document.createElement('div');
      li.className = 'mr-row';
      var head = document.createElement('div');
      head.className = 'mr-head';
      head.innerHTML = '<strong></strong><span class="pchip pchip--' + tone(r.status, r.paid) + '"></span>';
      head.querySelector('strong').textContent = r.title || r.reference;
      head.querySelector('span').textContent = r.paid ? 'Paid' : (r.status === 'cancelled' ? 'Withdrawn' : String(r.status).replace(/_/g, ' '));
      li.appendChild(head);
      var sub = document.createElement('p');
      sub.className = 'mr-sub';
      sub.textContent = r.amount + ' · asked ' + r.asked_on + (r.reference ? ' · ' + r.reference : '');
      li.appendChild(sub);
      standing(li, r);
      box.appendChild(li);
    });
  }

  /* ── several lines ────────────────────────────────────────────── */
  var maxLines = 10;
  var linesBox = card.querySelector('[data-mr-lines]');
  var lineRows = card.querySelector('[data-mr-line-rows]');
  var totalOut = card.querySelector('[data-mr-total]');
  var addWrap = card.querySelector('[data-mr-addline-wrap]');
  var addBtn = card.querySelector('[data-mr-addline]');
  var singles = Array.prototype.slice.call(card.querySelectorAll('[data-mr-single]'));
  var amountIn = form.querySelector('[name=amount]');

  function inLines() { return !linesBox.hidden; }

  function catOptions(sel, value) {
    sel.innerHTML = '';
    cats.forEach(function (c) {
      var o = document.createElement('option');
      o.value = c.key; o.textContent = c.label;
      sel.appendChild(o);
    });
    if (value) sel.value = value;
  }
  function syncLineOptions() {
    Array.prototype.forEach.call(lineRows.querySelectorAll('select'), function (sel) { catOptions(sel, sel.value); });
  }

  function renumber() {
    var rows = lineRows.querySelectorAll('.mr-line');
    Array.prototype.forEach.call(rows, function (row, i) {
      row.querySelector('legend').textContent = 'Line ' + (i + 1);
      ['description', 'category', 'amount'].forEach(function (f) {
        row.querySelector('[data-f=' + f + ']').name = 'lines[' + i + '][' + f + ']';
      });
      row.querySelector('[data-mr-rmline]').setAttribute('aria-label', 'Remove line ' + (i + 1));
    });
    addBtn.disabled = rows.length >= maxLines;
    total();
  }

  function total() {
    var sum = 0;
    Array.prototype.forEach.call(lineRows.querySelectorAll('[data-f=amount]'), function (i) { sum += Math.max(0, Number(i.value) || 0); });
    totalOut.textContent = 'Total: ' + money(sum.toFixed(2));
  }

  function addLine(values) {
    values = values || {};
    var row = document.createElement('fieldset');
    row.className = 'mr-line';
    row.innerHTML = '<legend></legend>'
      + '<label class="mr-line-what">What <input data-f="description" maxlength="160" required></label>'
      + '<label>Kind <select data-f="category" required></select></label>'
      + '<label>Amount (₦) <input data-f="amount" type="number" min="1" step="1" required></label>'
      + '<button type="button" class="pbtn pbtn-ghost mr-rmline" data-mr-rmline>Remove</button>';
    row.querySelector('[data-f=description]').value = values.description || '';
    catOptions(row.querySelector('[data-f=category]'), values.category || '');
    row.querySelector('[data-f=amount]').value = values.amount || '';
    row.querySelector('[data-mr-rmline]').addEventListener('click', function () {
      row.parentNode.removeChild(row);
      if (lineRows.querySelectorAll('.mr-line').length < 2) singleMode(); else renumber();
      addBtn.focus();
    });
    lineRows.appendChild(row);
    renumber();
    return row;
  }

  /* Into lines: the one line becomes line 1, and line 2 is added empty. */
  function linesMode() {
    var title = form.querySelector('[name=title]').value;
    singles.forEach(function (l) { l.hidden = true; l.querySelector('select, input').disabled = true; });
    linesBox.hidden = false;
    addLine({ description: title, category: kind.value, amount: amountIn.value });
  }

  /* Back to one line, keeping what the remaining line said. */
  function singleMode() {
    var last = lineRows.querySelector('.mr-line');
    if (last) {
      kind.value = last.querySelector('[data-f=category]').value;
      amountIn.value = last.querySelector('[data-f=amount]').value;
    }
    lineRows.innerHTML = '';
    linesBox.hidden = true;
    singles.forEach(function (l) { l.hidden = false; l.querySelector('select, input').disabled = false; });
    syncHint();
    addBtn.disabled = false;
  }

  addBtn.addEventListener('click', function () {
    if (!inLines()) linesMode();
    var row = addLine();
    row.querySelector('[data-f=description]').focus();
  });
  lineRows.addEventListener('input', function (e) { if (e.target.getAttribute('data-f') === 'amount') total(); });

  /* ── the bank, and the name the bank has ─────────────────────────── */
  var pickWrap = card.querySelector('[data-mr-bankpick-wrap]');
  var pick = card.querySelector('[data-mr-bankpick]');
  var free = Array.prototype.slice.call(card.querySelectorAll('[data-mr-bankfree]'));
  var acctOut = card.querySelector('[data-mr-acct]');
  var numberIn = form.querySelector('[name=account_number]');
  var bankName = form.querySelector('[name=bank_name]');
  var bankCode = form.querySelector('[name=bank_code]');

  function drawBanks(banks) {
    if (!pick) return;
    var have = banks.length > 0;
    pickWrap.hidden = !have;
    /* With finance's list, the two boxes are filled from it; without one
       they are the way to say which bank. Hidden, not disabled: they are
       what is sent. */
    free.forEach(function (l) { l.hidden = have; });
    if (!have) return;
    pick.length = 1;
    banks.forEach(function (b) {
      var o = document.createElement('option');
      o.value = b.code; o.textContent = b.name;
      pick.appendChild(o);
    });
    if (bankCode.value) pick.value = bankCode.value;
  }
  if (pick) pick.addEventListener('change', function () {
    bankCode.value = pick.value;
    bankName.value = pick.value ? pick.options[pick.selectedIndex].textContent : '';
    checkName();
  });

  var asking = 0, timer = null;
  function checkName() {
    clearTimeout(timer);
    var n = (numberIn.value || '').replace(/\D+/g, '');
    if (n.length !== 10 || !bankCode.value) { acctOut.hidden = true; return; }
    timer = setTimeout(function () {
      var mine = ++asking;
      acctOut.hidden = false;
      acctOut.textContent = 'Checking the name with the bank…';
      post({ account_number: n, bank_code: bankCode.value }, 'account_name').then(function (res) {
        if (mine !== asking) return;                       // a newer number was typed meanwhile
        /* Said, never enforced: a lookup that is down must not stop
           anybody asking for money. */
        acctOut.textContent = res && res.name ? 'The bank has this account as ' + res.name + '.'
          : (res && res.checked ? 'The bank did not confirm this account number. Check it — you can still send.'
          : 'Could not check the name with the bank just now. You can still send.');
      });
    }, 500);
  }
  numberIn.addEventListener('input', checkName);
  bankCode.addEventListener('input', checkName);

  function post(body, action) {
    return fetch(API + (action ? '?action=' + action : '?action=create'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'Could not reach the portal.' }; });
  }

  function load() {
    fetch(API, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(draw)
      .catch(function () { say('Could not load your requests.', true); });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    say('');

    /* Multipart, so the receipts go with it. The CSRF token rides in the
       header exactly as for the JSON calls. */
    var fd = new FormData(form);
    var btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    fetch(API + '?action=create', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'Could not reach the portal.' }; })
      .then(function (res) {
      btn.disabled = false;
      if (res.ok) {
        if (inLines()) singleMode();                     // before reset, which would empty what it copies back
        form.reset();
        acctOut.hidden = true;
        if (bank) bank.open = false;
        say(res.queued ? (res.message || 'Asked. Your request is kept and goes to finance by itself.')
          : 'Sent to finance' + (res.reference ? ' as ' + res.reference : '') + '. They decide from here.');
        load();
      } else {
        /* Finance's own sentence, not "something went wrong": it says
           which heading is not on offer, or that the amount is over what
           a form may ask for. */
        say(res.error || 'It could not be sent.', true);
      }
      });
  });

  load();
})();
