/**
 * assets/site/give-pay.js — paying on the giving pages, without leaving them.
 *
 * The appeal widget used to be a GET form pointed at /donate.html: you chose
 * an amount here, landed on another page, and chose it again. Every hop is
 * somewhere to lose the person, and the second page asked the same questions
 * the first one already had answers to.
 *
 * Now the page initialises the transaction server-side and opens Paystack's
 * inline modal over the top. The amount, the reference and the metadata are
 * set by process-donation.php, not by this file — the browser only resumes a
 * transaction the server already created, so nothing here can change what is
 * being charged.
 *
 * It stays progressive enhancement. The markup is a real form with a real
 * action, and every failure path below falls back to it rather than leaving
 * somebody stuck: no script, no modal, a blocked CDN, a Paystack error.
 */
(function () {
  'use strict';

  var PS_SRC = 'https://js.paystack.co/v2/inline.js';
  var loading = null;

  /* Load the SDK once, on first use — not on page load. Most people reading
     an appeal never open the form, and this is a third-party script. */
  function paystack() {
    if (window.PaystackPop) return Promise.resolve(window.PaystackPop);
    if (loading) return loading;
    loading = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = PS_SRC; s.async = true;
      s.onload = function () { window.PaystackPop ? resolve(window.PaystackPop) : reject(new Error('sdk')); };
      s.onerror = function () { reject(new Error('blocked')); };
      document.head.appendChild(s);
    });
    return loading;
  }

  function post(body) {
    return fetch('/process-donation.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  /**
   * Start a gift. `opts` = { amount, email, campaign, frequency, firstName,
   * lastName, message, onDone(state, data), onError(msg), fallback() }.
   */
  function give(opts) {
    var fail = function (msg) {
      if (typeof opts.onError === 'function') opts.onError(msg);
    };

    return post({
      action: 'init_payment',
      email: opts.email,
      amount: opts.amount,
      currency: 'NGN',
      campaign: opts.campaign || 'general',
      frequency: opts.frequency || 'One-time',
      firstName: opts.firstName || '',
      lastName: opts.lastName || '',
      message: opts.message || ''
    }).then(function (d) {
      if (!d || !d.success) { fail((d && d.message) || 'Could not start the payment.'); return; }

      return paystack().then(function (Pop) {
        var popup = new Pop();
        popup.resumeTransaction(d.access_code, {
          onSuccess: function (tx) {
            /* record_donation re-verifies against Paystack server-side and
               takes the amount from THEIR record, not ours, before it stores
               anything. So this is the verification as well as the write —
               the browser saying it went through is not the same as it
               having gone through. */
            post({
              action: 'record_donation',
              reference: (tx && tx.reference) || d.reference,
              email: opts.email,
              firstName: opts.firstName || '',
              lastName: opts.lastName || '',
              campaign: opts.campaign || 'general',
              frequency: opts.frequency || 'One-time',
              anonymous: !!opts.anonymous,
              message: opts.message || ''
            })
              .then(function (v) {
                if (typeof opts.onDone === 'function') {
                  /* "pending" is the honest answer when Paystack has taken the
                     money but not yet settled it to us: the receipt follows. */
                  opts.onDone(v && v.success ? 'paid' : 'pending', v || {});
                }
              })
              .catch(function () {
                if (typeof opts.onDone === 'function') opts.onDone('pending', {});
              });
          },
          onCancel: function () { fail(''); },      /* they closed it — not an error to shout about */
          onError:  function () { fail('The payment could not be completed.'); }
        });
      }).catch(function () {
        /* The SDK is blocked or failed. Do not strand them on a dead button:
           the hosted page still works, so use it. */
        if (d.authorization_url) { window.location.href = d.authorization_url; return; }
        if (typeof opts.fallback === 'function') opts.fallback();
        else fail('Could not open the payment window.');
      });
    }).catch(function () { fail('Could not reach the payment service.'); });
  }

  window.avGive = { give: give, ready: paystack };
})();
