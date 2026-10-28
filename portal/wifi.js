/* portal/wifi.js — the member's Wi-Fi sign-in, which CACENTRE runs.
 *
 * Loaded the first time the Wi-Fi pane opens, never before: the dashboard is
 * the first thing a member sees and must not wait on another site for a pane
 * most visits never open. The PIN is hidden until asked for — this is read on
 * phones in a room full of people. Loading, an empty card and an honest error
 * with a retry are all said.
 */
(function () {
  'use strict';

  var pane = document.getElementById('view-wifi');
  if (!pane) return;

  var CSRF = pane.getAttribute('data-csrf') || '';
  var msg = document.getElementById('wifiMsg'), dl = document.getElementById('wifiDl'), foot = document.getElementById('wifiFoot');
  var userEl = document.getElementById('wifiUser'), pinEl = document.getElementById('wifiPin'), showBtn = document.getElementById('wifiShow');
  var speedRow = document.getElementById('wifiSpeedRow'), speedEl = document.getElementById('wifiSpeed'), whereEl = document.getElementById('wifiWhere');
  var todayEl = document.getElementById('wifiToday'), monthEl = document.getElementById('wifiMonth');
  var newBtn = document.getElementById('wifiNew'), leftEl = document.getElementById('wifiLeft'), retry = document.getElementById('wifiRetry');
  var card = null, shown = false, loaded = false, busy = false;

  function bytes(b) {
    b = +b || 0;
    if (b >= 1073741824) return (Math.round(b / 107374182.4) / 10) + ' GB';
    if (b >= 1048576) return Math.round(b / 1048576) + ' MB';
    return b > 0 ? Math.max(1, Math.round(b / 1024)) + ' KB' : 'Nothing yet';
  }
  function say(text, bad) { msg.hidden = text === ''; msg.textContent = text; msg.classList.toggle('is-bad', !!bad); }
  function pin() { pinEl.textContent = shown && card ? card.pin : '••••••'; showBtn.textContent = shown ? 'Hide' : 'Show'; showBtn.setAttribute('aria-pressed', shown ? 'true' : 'false'); showBtn.setAttribute('aria-label', shown ? 'Hide your PIN' : 'Show your PIN'); }

  function draw() {
    retry.hidden = true;
    if (!card) { dl.hidden = foot.hidden = true; say('Your Wi-Fi details are on their way from CACENTRE, which runs the Wi-Fi. Look again in a quarter of an hour.'); return; }
    if (card.access === 'off') { dl.hidden = foot.hidden = true; say('You don’t have Wi-Fi access right now' + (card.why ? ': ' + card.why.replace(/\.$/, '') + '.' : '.') + ' Ask the team if you think this is wrong.', true); return; }
    say(card.hasRouter ? (card.access === 'limited' ? 'Limited for now' + (card.why ? ': ' + card.why.replace(/\.$/, '') : '') + '. ' : '') + 'Join the CACENTRE Wi-Fi; when the sign-in page opens, use these.'
                       : 'The Wi-Fi isn’t set up where you sign in yet. These details will work as soon as it is.');
    userEl.textContent = card.username;
    pin();
    speedRow.hidden = !card.speed;
    if (card.speed) speedEl.textContent = 'Up to ' + card.speed.down + ' Mbit/s · ' + (card.speed.devices === 1 ? 'one device' : card.speed.devices + ' devices') + ' at a time' + (card.speed.hours ? ' · ' + card.speed.hours.replace('-', '–') : '');
    whereEl.textContent = card.where ? card.where.charAt(0).toUpperCase() + card.where.slice(1) : '';
    var u = card.usage || {};
    todayEl.textContent = bytes(u.today) + (u.dayMb ? ' of ' + bytes(u.dayMb * 1048576) : '');
    monthEl.textContent = bytes(u.month) + (u.monthMb ? ' of ' + bytes(u.monthMb * 1048576) : '');
    newBtn.disabled = busy || card.resetsLeft < 1;
    leftEl.textContent = card.resetsLeft < 1 ? 'You’ve changed it three times today. Try again tomorrow.' : card.resetsLeft + ' left today';
    dl.hidden = foot.hidden = false;
  }

  function fail(text) { dl.hidden = foot.hidden = true; say(text, true); retry.hidden = false; }

  function load() {
    if (busy) return;
    busy = true; say('Loading your Wi-Fi details…'); retry.hidden = true;
    fetch('/portal/cac-wifi.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { busy = false; if (!d || !d.ok) { fail((d && d.error) || 'Your Wi-Fi details couldn’t load.'); return; } loaded = true; card = d.card; draw(); })
      .catch(function () { busy = false; fail('Your Wi-Fi details couldn’t load. Check your connection.'); });
  }

  showBtn.addEventListener('click', function () { shown = !shown; pin(); });
  retry.addEventListener('click', load);
  newBtn.addEventListener('click', function () {
    if (busy || !window.confirm('Get a new Wi-Fi PIN? The old one stops working at each router’s next update; devices already signed in stay signed in until they leave.')) return;
    busy = true; newBtn.disabled = true;
    fetch('/portal/cac-wifi.php?action=newpin', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: '{}' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        busy = false;
        if (!d || !d.ok) { newBtn.disabled = false; leftEl.textContent = (d && d.error) || 'Couldn’t change your PIN. Try again later.'; return; }
        card = d.card; shown = true; draw(); say('New PIN ready. Devices signed in with the old one stay signed in until they leave.');
      })
      .catch(function () { busy = false; newBtn.disabled = false; leftEl.textContent = 'Couldn’t change your PIN. Check your connection.'; });
  });

  function maybeLoad(view) { if (view === 'wifi' && !loaded) load(); }
  document.addEventListener('portal:view', function (e) { maybeLoad(e && e.detail ? e.detail.view : ''); });
  /* Arriving straight at /portal/#wifi, where no view event fires. */
  if (location.hash === '#wifi') maybeLoad('wifi');
})();
