/* ============================================================================
 * academy/ngv/pay.js — paying NGV fees from the dashboard.
 *
 * The amount here only OPENS a transaction. What lands on the ledger is what
 * Paystack reports back to the server on verification, so nothing a person can
 * type in this form decides how much they have paid.
 * ==========================================================================*/
/* Paid offline: the receipt goes for checking; nothing is credited on the payer's word. */
(function () {
  'use strict';
  var box = document.getElementById('noff'); if (!box) return;
  var form = box.querySelector('form'), msg = box.querySelector('.noff-msg');
  function say(t) { msg.hidden = false; msg.textContent = t; }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!form.evidence.files.length) { say('Attach the receipt — it is what gets checked.'); return; }
    var fd = new FormData(form); fd.append('purpose', 'ngv');
    var b = form.querySelector('button'); b.disabled = true; say('Checking the receipt…');
    fetch('/portal/offline-payment.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.NGV_CSRF || '' }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        b.disabled = false;
        if (d && d.ok && d.status === 'verified') { say('Checked — it is on your account.'); setTimeout(function () { location.reload(); }, 1200); return; }
        if (d && d.ok && d.status === 'held') { say('Not credited yet: ' + (d.reasons || []).join(' ') + ' Send a clearer receipt, or your team will look at it.'); return; }
        say((d && d.error) || 'Could not send that.');
      })
      .catch(function () { b.disabled = false; say('Network error — nothing was sent.'); });
  });
})();

(function () {
  'use strict';

  var panel = document.getElementById('npay');
  var leadBtns = document.querySelectorAll('[data-pay]');
  if (!panel && !leadBtns.length) return;

  function naira(n) {
    try { return '₦' + Number(n).toLocaleString('en-NG'); } catch (e) { return '₦' + n; }
  }

  /* Start a payment and hand over to Paystack. `btn` is managed for the whole
     round trip so a slow network cannot produce two transactions. */
  function start(amount, btn, onErr) {
    if (!amount || amount < 100) { onErr('The smallest payment is ' + naira(100) + '.'); return; }
    var label = btn ? btn.innerHTML : '';
    if (btn) { btn.setAttribute('aria-busy', 'true'); btn.disabled = true; btn.textContent = 'Opening…'; }
    fetch('/academy/ngv/pay.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.NGV_CSRF || '' },
      body: JSON.stringify({ amount: amount })
    })
      .then(function (r) {
        return r.json().catch(function () {
          throw new Error('We could not reach the payment service. Please try again in a moment.');
        });
      })
      .then(function (j) {
        if (!j.ok || !j.url) throw new Error(j.error || 'That did not work. Please try again.');
        window.location.href = j.url;
      })
      .catch(function (e) {
        onErr(e.message);
        if (btn) { btn.removeAttribute('aria-busy'); btn.disabled = false; btn.innerHTML = label; }
      });
  }

  /* The lead's one-tap button. */
  leadBtns.forEach(function (b) {
    b.addEventListener('click', function () {
      start(parseInt(b.getAttribute('data-pay'), 10) || 0, b, function (msg) { window.alert(msg); });
    });
  });

  if (!panel) return;

  var opts   = Array.prototype.slice.call(panel.querySelectorAll('.npay-opt'));
  var custom = panel.querySelector('.npay-custom');
  var other  = document.getElementById('npayOther');
  var go     = document.getElementById('npayGo');
  var goAmt  = document.getElementById('npayGoAmt');
  var err    = document.getElementById('npayErr');

  function chosen() {
    var r = panel.querySelector('input[name=npay_amt]:checked');
    if (!r) return 0;
    if (r.value === 'other') return parseInt((other && other.value) || '0', 10) || 0;
    return parseInt(r.value, 10) || 0;
  }

  function showErr(msg) {
    if (!err) return;
    if (!msg) { err.hidden = true; err.textContent = ''; return; }
    err.textContent = msg; err.hidden = false;
  }

  function sync() {
    opts.forEach(function (o) {
      var i = o.querySelector('input');
      o.classList.toggle('is-on', !!(i && i.checked));
    });
    var isOther = (panel.querySelector('input[name=npay_amt]:checked') || {}).value === 'other';
    if (custom) custom.hidden = !isOther;
    if (isOther && other && document.activeElement !== other) other.focus();
    var amt = chosen();
    if (goAmt) goAmt.textContent = amt > 0 ? naira(amt) : '';
    showErr('');
  }

  panel.addEventListener('change', sync);
  if (other) other.addEventListener('input', sync);
  sync();

  if (go) go.addEventListener('click', function () { start(chosen(), go, showErr); });
})();
