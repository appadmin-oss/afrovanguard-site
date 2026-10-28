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
    if (status === 'rejected' || status === 'cancelled') return 'red';
    if (status === 'approved' || status === 'partially_approved') return 'green';
    return 'gold';
  }

  function draw(data) {
    kind.innerHTML = '';
    (data.categories || []).forEach(function (c) {
      var o = document.createElement('option');
      o.value = c;
      o.textContent = c.charAt(0).toUpperCase() + c.slice(1);
      kind.appendChild(o);
    });

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
        : (r.sent ? String(r.status).replace(/_/g, ' ') : 'Waiting to send');
      li.appendChild(head);

      var sub = document.createElement('p');
      sub.className = 'mr-sub';
      sub.textContent = money(r.amount) + ' · ' + r.category + ' · asked ' + r.asked_on
        + (r.reference ? ' · ' + r.reference : '');
      li.appendChild(sub);

      /* Only where it can act: a request finance already has is not
         offered a button that would do nothing. */
      if (!r.sent) {
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

    var fd = new FormData(form);
    var body = {};
    fd.forEach(function (v, k) { body[k] = v; });

    var btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    post(body).then(function (res) {
      btn.disabled = false;
      if (res.ok) {
        form.reset();
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
