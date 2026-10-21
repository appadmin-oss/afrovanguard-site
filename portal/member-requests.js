/* ============================================================
   portal/member-requests.js — asking for a change to your own record (portal → Membership).

   Another centre, corrected details, pausing or leaving, anything else.
   Nothing changes until an admin approves it in the Studio; the answer is
   shown here (and emailed). The fields shown follow what is being asked
   for, and only what is shown is sent.
   ============================================================ */
(function () {
  'use strict';

  var card = document.getElementById('memberRequests');
  if (!card) return;

  var API  = '/portal/member-requests.php';
  var CSRF = card.getAttribute('data-csrf') || '';
  var form = card.querySelector('[data-mq-form]');
  var kind = card.querySelector('[data-mq-kind]');
  var list = card.querySelector('[data-mq-list]');
  var msg  = card.querySelector('[data-mq-msg]');
  var WORDS = { pending: 'Waiting for an admin', approved: 'Approved', rejected: 'Not approved', withdrawn: 'Withdrawn', deciding: 'Being decided' };
  var TONE = { pending: 'gold', approved: 'green', rejected: 'red', withdrawn: 'gray', deciding: 'gold' };

  function say(text, bad) { msg.textContent = text; msg.hidden = !text; msg.classList.toggle('is-bad', !!bad); }

  function fields() {
    var k = kind.value;
    card.querySelectorAll('[data-mq-for]').forEach(function (el) { el.hidden = el.getAttribute('data-mq-for') !== k; });
    card.querySelector('[data-mq-message]').placeholder = k === 'centre' ? 'I moved house and that centre is nearer.'
      : k === 'details' ? 'What else should be corrected?'
      : k === 'pause' ? 'Pause or leave, and from when.' : 'What are you asking for?';
  }

  function post(body, action) {
    return fetch(API + '?action=' + (action || 'create'), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Could not reach the portal.' }; });
  }

  function draw(rows) {
    if (!rows.length) { list.innerHTML = '<p class="pc-empty">You have not asked for anything yet.</p>'; return; }
    list.innerHTML = '';
    rows.forEach(function (r) {
      var row = document.createElement('div'); row.className = 'mr-row';
      var head = document.createElement('div'); head.className = 'mr-head';
      var t = document.createElement('strong'); t.textContent = r.kind_label + (r.payload && r.payload.centre ? ' — ' + r.payload.centre : '');
      var chip = document.createElement('span'); chip.className = 'pchip pchip--' + (TONE[r.status] || 'gray'); chip.textContent = WORDS[r.status] || r.status;
      head.appendChild(t); head.appendChild(chip); row.appendChild(head);
      var sub = document.createElement('p'); sub.className = 'mr-sub';
      var bits = ['asked ' + String(r.created_at).slice(0, 10)];
      if (r.payload && r.payload.name) bits.push('name → ' + r.payload.name);
      if (r.payload && r.payload.phone) bits.push('phone → ' + r.payload.phone);
      sub.textContent = bits.join(' · '); row.appendChild(sub);
      if (r.message) { var m = document.createElement('p'); m.className = 'mr-sub'; m.textContent = '“' + r.message + '”'; row.appendChild(m); }
      if (r.status === 'rejected' && r.reason) { var why = document.createElement('p'); why.className = 'mr-sub is-bad'; why.textContent = 'Why: ' + r.reason; row.appendChild(why); }
      if (r.status === 'approved' && r.outcome) { var out = document.createElement('p'); out.className = 'mr-sub'; out.textContent = r.outcome; row.appendChild(out); }
      if (r.status === 'pending') {
        var w = document.createElement('button'); w.type = 'button'; w.className = 'pbtn pbtn-ghost'; w.textContent = 'Withdraw';
        w.addEventListener('click', function () {
          if (!window.confirm('Withdraw this request?')) return;
          w.disabled = true;
          post({ id: r.id }, 'withdraw').then(function (res) { say(res.ok ? 'Withdrawn.' : (res.error || 'It could not be withdrawn.'), !res.ok); load(); });
        });
        row.appendChild(w);
      }
      list.appendChild(row);
    });
  }

  function load() {
    fetch(API, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { list.innerHTML = '<p class="pc-empty">Your requests could not be loaded. <button type="button" class="pbtn pbtn-ghost" data-mq-retry>Try again</button></p>'; return; }
        draw(d.requests || []);
      })
      .catch(function () { list.innerHTML = '<p class="pc-empty">Your requests could not be loaded. <button type="button" class="pbtn pbtn-ghost" data-mq-retry>Try again</button></p>'; });
  }

  list.addEventListener('click', function (e) { if (e.target.closest('[data-mq-retry]')) load(); });
  kind.addEventListener('change', fields);
  form.addEventListener('submit', function (e) {
    e.preventDefault(); say('');
    var body = { kind: kind.value, message: form.elements.message.value.trim() };
    if (kind.value === 'centre') body.centre = form.elements.centre.value;
    if (kind.value === 'details') { body.name = form.elements.name.value.trim(); body.phone = form.elements.phone.value.trim(); }
    var btn = form.querySelector('button[type=submit]'); btn.disabled = true;
    post(body).then(function (res) {
      btn.disabled = false;
      if (res.ok) { form.elements.message.value = ''; say('Sent. An admin decides; you will see the answer here and by email.'); load(); }
      else say(res.error || 'It could not be sent.', true);
    });
  });

  fields();
  load();
})();
