/* ============================================================================
 * give/give.js — the giving widget on an appeal page.
 * ----------------------------------------------------------------------------
 * The form works WITHOUT this file. Left alone it is an ordinary GET form that
 * hands campaign and amount to /donate.html, which is the path that already
 * takes money. This script does three things on top:
 *
 *   1. marks the selected chip (a class, not :has(), so the selected state is
 *      visible on an older browser too — this form takes money),
 *   2. reveals the custom-amount and email fields only when they are needed,
 *   3. intercepts the RECURRING path, which genuinely cannot be a plain form:
 *      a Paystack Plan has to exist server-side before a subscription can, so
 *      it posts to /give/recurring.php and follows the URL that comes back.
 *
 * Anything unexpected falls back to letting the form submit normally rather
 * than trapping the donor behind a dead button.
 * ==========================================================================*/
(function () {
  'use strict';

  var form = document.getElementById('giveWidget');
  if (!form) return;

  var chips    = Array.prototype.slice.call(form.querySelectorAll('.gw-chip'));
  var otherBox = form.querySelector('.gw-other');
  var otherIn  = document.getElementById('gwOther');
  var emailBox = form.querySelector('.gw-email');
  var emailIn  = document.getElementById('gwEmail');
  var go       = document.getElementById('gwGo');
  var goAmt    = document.getElementById('gwGoAmt');
  var summary  = document.getElementById('gwSummary');
  var errBox   = document.getElementById('gwErr');
  var slug     = form.getAttribute('data-slug') || '';
  var min      = parseInt(form.getAttribute('data-min'), 10) || 1000;

  var WORD = { once: '', monthly: 'a month', quarterly: 'a quarter', annually: 'a year' };

  function naira(n) {
    try { return '₦' + Number(n).toLocaleString('en-NG'); }
    catch (e) { return '₦' + n; }
  }
  function freq()  { var r = form.querySelector('input[name=frequency]:checked'); return r ? r.value : 'once'; }
  function picked(){ var r = form.querySelector('input[name=amount]:checked');    return r ? r.value : ''; }

  function amount() {
    var p = picked();
    if (p === 'other') return parseInt((otherIn && otherIn.value) || '0', 10) || 0;
    return parseInt(p, 10) || 0;
  }

  function showErr(msg) {
    if (!errBox) return;
    if (!msg) { errBox.hidden = true; errBox.textContent = ''; return; }
    errBox.textContent = msg;
    errBox.hidden = false;
  }

  function sync() {
    chips.forEach(function (c) {
      var input = c.querySelector('input');
      c.classList.toggle('is-on', !!(input && input.checked));
    });

    var isOther = picked() === 'other';
    if (otherBox) otherBox.hidden = !isOther;
    if (isOther && otherIn && document.activeElement !== otherIn) otherIn.focus();

    var recurring = freq() !== 'once';
    if (emailBox) emailBox.hidden = !recurring;

    var amt = amount();
    var word = WORD[freq()] || '';
    if (goAmt) goAmt.textContent = amt > 0 ? naira(amt) + (word ? ' ' + word : '') : '';
    if (summary) {
      summary.textContent = amt <= 0 ? ''
        : (recurring
            ? 'You can stop this any time.'
            : 'A one-off gift. Nothing is stored or repeated.');
    }
    showErr('');
  }

  form.addEventListener('change', sync);
  if (otherIn) otherIn.addEventListener('input', sync);
  sync();

  form.addEventListener('submit', function (ev) {
    var amt = amount();
    var recurring = freq() !== 'once';

    if (amt < min) {
      ev.preventDefault();
      showErr('The smallest gift we can take is ' + naira(min) + '.');
      if (picked() === 'other' && otherIn) otherIn.focus();
      return;
    }

    /* One-off: let the form go where it was always going. */
    if (!recurring) {
      if (picked() === 'other' && otherIn) {
        /* The donate page reads `amount`, so carry the typed figure under that
           name rather than leaving it in `custom_amount` where nothing reads it. */
        var hid = form.querySelector('input[name=amount][type=hidden]');
        if (!hid) {
          hid = document.createElement('input');
          hid.type = 'hidden'; hid.name = 'amount';
          form.appendChild(hid);
        }
        hid.value = String(amt);
        chips.forEach(function (c) { var i = c.querySelector('input'); if (i) i.disabled = true; });
      }
      return;
    }

    /* Recurring: needs a Plan created server-side first. */
    ev.preventDefault();
    var email = (emailIn && emailIn.value || '').trim();
    if (!email || email.indexOf('@') < 1) {
      showErr('We need an email address to set up a recurring gift and send you the receipts.');
      if (emailIn) emailIn.focus();
      return;
    }

    go.setAttribute('aria-busy', 'true');
    go.disabled = true;
    var label = go.textContent;
    go.textContent = 'Setting it up…';

    fetch('/give/recurring.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ slug: slug, email: email, amount: amt, interval: freq() })
    })
      .then(function (r) { return r.json().catch(function () { throw new Error('We could not reach the payment service. Please try again.'); }); })
      .then(function (j) {
        if (!j.ok || !j.url) throw new Error(j.error || 'That did not work. Please try a one-off gift instead.');
        window.location.href = j.url;
      })
      .catch(function (e) {
        showErr(e.message);
        go.removeAttribute('aria-busy');
        go.disabled = false;
        go.textContent = label;
      });
  });
})();
