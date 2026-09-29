/**
 * give/pay-sheet.js — "Fund one" on the giving page, paid on the giving page.
 *
 * Each item in the catalogue carries a price, and the point of putting the
 * price there is that somebody can act on it where they read it. The link
 * underneath still points at /donate.html, and stays the answer when this
 * script is absent or Paystack cannot load — so nothing here removes a way to
 * give, it only adds a shorter one.
 */
(function () {
  'use strict';

  var sheet = document.getElementById('gvPay');
  if (!sheet || !window.avGive) return;

  var titleEl = document.getElementById('gvPayTitle');
  var whatEl  = document.getElementById('gvPayWhat');
  var amtEl   = document.getElementById('gvPayAmt');
  var emailIn = document.getElementById('gvPayEmail');
  var nameIn  = document.getElementById('gvPayName');
  var goBtn   = document.getElementById('gvPayGo');
  var errEl   = document.getElementById('gvPayErr');
  var closeBt = document.getElementById('gvPayX');

  var current = null, opener = null;

  function naira(n) {
    try { return '₦' + Number(n).toLocaleString('en-NG'); } catch (e) { return '₦' + n; }
  }
  function err(msg) {
    if (!errEl) return;
    errEl.textContent = msg || '';
    errEl.hidden = !msg;
  }
  function open(item) {
    current = item;
    opener = document.activeElement;
    if (titleEl) titleEl.textContent = 'Fund one';
    if (whatEl)  whatEl.textContent  = item.title;
    if (amtEl)   amtEl.textContent   = naira(item.amount);
    err('');
    sheet.hidden = false;
    document.body.style.overflow = 'hidden';
    if (emailIn) emailIn.focus();
  }
  function close() {
    sheet.hidden = true;
    document.body.style.overflow = '';
    current = null;
    if (opener && opener.focus) opener.focus();
  }

  document.querySelectorAll('[data-gv-pay]').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      var amount = parseInt(a.getAttribute('data-gv-pay'), 10);
      if (!amount) return;                       /* let the href do its job */
      ev.preventDefault();
      open({ amount: amount, title: a.getAttribute('data-gv-item') || '', slug: a.getAttribute('data-gv-slug') || '' });
    });
  });

  if (closeBt) closeBt.addEventListener('click', close);
  sheet.addEventListener('click', function (e) { if (e.target === sheet) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !sheet.hidden) close();
  });

  if (goBtn) goBtn.addEventListener('click', function () {
    if (!current) return;
    var email = (emailIn && emailIn.value || '').trim();
    if (!email || email.indexOf('@') < 1) {
      err('We need an email address to send you the receipt.');
      if (emailIn) emailIn.focus();
      return;
    }
    var whole = (nameIn && nameIn.value || '').trim();
    var sp = whole.indexOf(' ');
    var fn = sp > 0 ? whole.slice(0, sp) : whole;
    var ln = sp > 0 ? whole.slice(sp + 1) : '';

    var label = goBtn.textContent;
    goBtn.disabled = true;
    goBtn.setAttribute('aria-busy', 'true');
    goBtn.textContent = 'Opening the card form…';
    err('');

    var item = current;
    window.avGive.give({
      amount: item.amount,
      email: email,
      firstName: fn,
      lastName: ln,
      campaign: 'general',
      frequency: 'One-time',
      /* The item is named in the message so the gift can be reconciled
         against the catalogue row it was given for. */
      message: item.title ? ('For: ' + item.title + (item.slug ? ' (' + item.slug + ')' : '')) : '',
      onDone: function (state) {
        var card = sheet.querySelector('.gvpay-card');
        if (card) {
          card.innerHTML =
            '<h2 class="gvpay-h">' + (state === 'paid' ? 'Thank you — that went through.' : 'Thank you — payment received.') + '</h2>' +
            '<p class="gvpay-what">' + (state === 'paid'
              ? 'You funded ' + (item.title || 'this') + '. A receipt is on its way.'
              : 'Your gift is with Paystack and the receipt follows once it settles.') + '</p>' +
            '<button type="button" class="gvpay-go" id="gvPayDone">Close</button>';
          var d = document.getElementById('gvPayDone');
          if (d) { d.addEventListener('click', function () { location.reload(); }); d.focus(); }
        }
      },
      onError: function (msg) {
        goBtn.disabled = false;
        goBtn.removeAttribute('aria-busy');
        goBtn.textContent = label;
        if (msg) err(msg);                        /* empty = they closed it themselves */
      },
      fallback: function () { window.location.href = '/donate.html?amount=' + item.amount; }
    });
  });
})();
