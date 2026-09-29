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
 *   3. takes the payment HERE. Both paths post to the server, which creates
 *      the transaction (or the Plan, for a subscription) and hands back
 *      something to open: a one-off resumes Paystack's inline modal over this
 *      page, a recurring gift follows the URL its Plan needs. Choosing an
 *      amount on one page and being asked for it again on the next is a hop
 *      that only ever loses people.
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

    /* Every gift needs an email now, not just a subscription: the receipt
       has to go somewhere, and Paystack will not start a transaction without
       one. It used to be revealed only for recurring because the one-off path
       collected it on the page it redirected to. */
    var recurring = freq() !== 'once';
    if (emailBox) emailBox.hidden = false;

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

  function busy(word) {
    go.setAttribute('aria-busy', 'true');
    go.disabled = true;
    go.textContent = word;
  }
  function unbusy(label) {
    go.removeAttribute('aria-busy');
    go.disabled = false;
    go.textContent = label;
    sync();
  }

  /* Replace the form with the outcome. Staying on the page is the point of
     all this, so the page has to have something to say when it is done. */
  function done(state, amt, word) {
    var paid = state === 'paid';
    var box = document.createElement('div');
    box.className = 'gw-done';
    box.setAttribute('role', 'status');
    box.innerHTML =
      '<p class="gw-done-h">' + (paid ? 'Thank you — that went through.' : 'Thank you — payment received.') + '</p>' +
      '<p class="gw-done-b">' +
        (paid
          ? 'You gave ' + naira(amt) + (word ? ' ' + word : '') + '. A receipt is on its way to your inbox.'
          : 'Your ' + naira(amt) + ' is with Paystack. The receipt follows as soon as it settles — ' +
            'if it has not arrived within the hour, reply to this page&rsquo;s contact form and we will check it.') +
      '</p>';
    form.parentNode.replaceChild(box, form);
    box.focus && box.focus();
  }

  form.addEventListener('submit', function (ev) {
    var amt = amount();
    var recurring = freq() !== 'once';

    if (amt < min) {
      ev.preventDefault();
      showErr('The smallest gift we can take is ' + naira(min) + '.');
      if (picked() === 'other' && otherIn) otherIn.focus();
      return;
    }

    /* Carry the typed figure under `amount` regardless: if anything below
       falls back to submitting the form, /donate.html reads that name and
       would otherwise arrive with nothing. */
    if (picked() === 'other' && otherIn) {
      var hid = form.querySelector('input[name=amount][type=hidden]');
      if (!hid) {
        hid = document.createElement('input');
        hid.type = 'hidden'; hid.name = 'amount';
        form.appendChild(hid);
      }
      hid.value = String(amt);
    }

    /* One-off: pay on this page. */
    if (!recurring) {
      if (!window.avGive) return;          /* no module → the form goes as it always did */
      ev.preventDefault();

      var email1 = (emailIn && emailIn.value || '').trim();
      if (!email1 || email1.indexOf('@') < 1) {
        showErr('We need an email address to send you the receipt.');
        if (emailIn) emailIn.focus();
        return;
      }

      var label1 = go.textContent;
      busy('Opening the card form…');

      window.avGive.give({
        amount: amt,
        email: email1,
        campaign: slug || 'general',
        frequency: 'One-time',
        onDone: function (state) { done(state, amt, ''); },
        onError: function (msg) {
          unbusy(label1);
          /* An empty message means they closed the modal themselves, which is
             not a failure and does not deserve red text. */
          if (msg) showErr(msg);
        },
        fallback: function () { form.submit(); }
      });
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
