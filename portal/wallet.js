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
