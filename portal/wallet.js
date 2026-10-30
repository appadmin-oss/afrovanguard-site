/* portal/wallet.js — the Wallet's NGG volunteer pay, read from NGG when the
 * view opens (portal/ngg-pay.php), never before: the dashboard must not wait
 * on another site for a section most members do not have. Loading, nothing
 * (the section stays hidden for anybody who is not an NGG volunteer), and an
 * honest error with a retry are all said. Built with textContent only: NGG's
 * answer is data, never markup.
 */
(function () {
  'use strict';
  var box = document.getElementById('walletNgg');
  if (!box) return;
  var $ = function (id) { return document.getElementById(id); };
  var msg = $('walletNggMsg'), kpis = $('walletNggKpis'), list = $('walletNggList'), retry = $('walletNggRetry'), sub = $('walletNggSub');
  var pays = $('walletNggPays'), bank = $('walletNggBank'), form = $('walletNggBankForm'), opener = $('walletNggBankOpen'), bad = $('walletNggBankBad'), done = $('walletNggBankDone');
  var loaded = false, busy = false;
  var naira = function (n) { return '₦' + (Math.round(+n || 0)).toLocaleString('en-NG'); };
  var day = function (s) { var d = s ? new Date(s + 'T12:00:00') : null; return d && !isNaN(d) ? d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : s; };
  var el = function (tag, cls, text) { var x = document.createElement(tag); if (cls) x.className = cls; if (text != null) x.textContent = text; return x; };

  function say(text, bad) { msg.hidden = text === ''; msg.textContent = text; msg.classList.toggle('is-bad', !!bad); }

  function draw(w) {
    box.hidden = false; retry.hidden = true;
    say(w.on ? 'You earn ' + naira(w.rate) + ' for each NGG programme day you are present and active' + (w.frequency ? ', paid ' + w.frequency + ' after each period ends.' : '.')
             : 'NGG volunteer pay is paused. What you have already earned is kept.');
    sub.textContent = w.centre || '';
    $('walletNggOwed').textContent = naira(w.owed);
    $('walletNggEarned').textContent = naira(w.earned);
    $('walletNggDays').textContent = w.daysEarned === 1 ? 'For 1 day.' : 'For ' + w.daysEarned + ' days.';
    $('walletNggPaid').textContent = naira(w.paid);
    var last = (w.payouts || [])[0];
    $('walletNggLast').textContent = last ? 'Last: ' + naira(last.amount) + ' for ' + last.period + (last.how ? ', ' + last.how.toLowerCase() : '') + '.' : 'Nothing paid yet.';
    kpis.hidden = false;
    var fines = $('walletNggFines');
    fines.textContent = (w.finesDeducted ? 'Fines you owe, NGG’s or Afrovanguard’s, come off a payment first; what is left stays owed.' : '')
      + (w.finesTaken > 0 ? ' ' + naira(w.finesTaken) + ' has been taken off for fines so far.' : '');
    fines.hidden = fines.textContent === '';
    drawBank(w.bank || {});
    pays.textContent = '';
    (w.payouts || []).slice(0, 12).forEach(function (p) {
      var li = el('li', 'avp-wallet-row');
      var what = el('div', 'avp-wallet-what');
      what.appendChild(el('b', '', p.period));
      what.appendChild(el('span', '', (p.fines > 0 ? naira(p.gross) + ' earned, ' + naira(p.fines) + ' taken off for fines' : p.days === 1 ? 'For 1 day' : 'For ' + p.days + ' days') + (p.how ? ' · ' + p.how.toLowerCase() : '')));
      var amts = el('div', 'avp-wallet-amts');
      amts.appendChild(el('span', 'avp-wallet-amt av-num is-green', naira(p.amount)));
      li.appendChild(what); li.appendChild(amts);
      pays.appendChild(li);
    });
    pays.hidden = $('walletNggPaysH').hidden = !(w.payouts || []).length;
    $('walletNggDaysH').hidden = !(w.days || []).length;
    list.textContent = '';
    (w.days || []).slice(0, 30).forEach(function (d) {
      var li = el('li', 'avp-wallet-row' + (d.earned ? '' : ' is-pending'));
      var what = el('div', 'avp-wallet-what');
      what.appendChild(el('b', '', day(d.day)));
      what.appendChild(el('span', '', d.earned ? (d.paid ? 'Paid' : 'Not paid yet') : 'Not paid: ' + (d.why || 'excluded').replace(/^./, function (c) { return c.toLowerCase(); })));
      var amts = el('div', 'avp-wallet-amts');
      amts.appendChild(el('span', 'avp-wallet-amt av-num ' + (d.earned ? 'is-green' : ''), d.earned ? naira(d.amount) : naira(0)));
      li.appendChild(what); li.appendChild(amts);
      list.appendChild(li);
    });
    list.hidden = !(w.days || []).length;
    if (!(w.days || []).length) say(msg.textContent + ' No days yet: a day is counted once it is over.');
  }

  function drawBank(b) {
    bank.hidden = false;
    $('walletNggBankNow').textContent = b.set ? b.bankName + ' ••••' + b.last4 + ' · ' + b.accountName : 'No bank details yet. Add them so NGG can pay you by transfer.';
    opener.textContent = b.set ? 'Change bank details' : 'Add bank details';
  }
  function openForm(on) {
    form.hidden = !on; opener.hidden = on; opener.setAttribute('aria-expanded', on ? 'true' : 'false'); bad.hidden = true;
    if (on) { done.textContent = ''; form.elements.bankName.focus(); } else { form.reset(); opener.focus(); }
  }
  opener.addEventListener('click', function () { openForm(true); });
  $('walletNggBankCancel').addEventListener('click', function () { openForm(false); });
  form.addEventListener('keydown', function (e) { if (e.key === 'Escape') openForm(false); });
  form.addEventListener('submit', function (e) {
    e.preventDefault(); bad.hidden = true;
    var f = form.elements, num = f.accountNumber.value.replace(/[\s-]/g, '');
    if (!/^\d{10}$/.test(num)) { bad.textContent = 'An account number is 10 digits.'; bad.hidden = false; f.accountNumber.focus(); return; }
    var btn = form.querySelector('button[type="submit"]'); btn.disabled = true;
    fetch('/portal/ngg-pay.php', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': box.getAttribute('data-csrf') || '' },
      body: JSON.stringify({ action: 'bank', bankName: f.bankName.value, accountNumber: num, accountName: f.accountName.value }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (d && d.ok) { if (d.bank) drawBank(d.bank); openForm(false); done.textContent = 'Saved. NGG has emailed you to confirm the change.'; return; }
        bad.textContent = (d && d.error) || 'That couldn’t be saved. Try again.'; bad.hidden = false;
      })
      .catch(function () { btn.disabled = false; bad.textContent = 'That couldn’t be saved. Check your connection.'; bad.hidden = false; });
  });

  function load() {
    if (busy) return;
    busy = true; retry.hidden = true;
    fetch('/portal/ngg-pay.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        busy = false;
        if (d && d.ok) { loaded = true; if (d.wallet) draw(d.wallet); else box.hidden = true; return; }
        box.hidden = false; kpis.hidden = list.hidden = true; say((d && d.error) || 'Your NGG volunteer pay couldn’t load.', true); retry.hidden = false;
      })
      .catch(function () { busy = false; box.hidden = false; kpis.hidden = list.hidden = true; say('Your NGG volunteer pay couldn’t load. Check your connection.', true); retry.hidden = false; });
  }
  retry.addEventListener('click', load);
  function maybe(view) { if (view === 'wallet' && !loaded) load(); }
  document.addEventListener('portal:view', function (e) { maybe(e && e.detail ? e.detail.view : ''); });
  if (location.hash === '#wallet') maybe('wallet');
})();
