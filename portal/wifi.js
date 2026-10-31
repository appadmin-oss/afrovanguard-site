/* portal/wifi.js — the member's Wi-Fi sign-in, which CACENTRE runs.
 *
 * Loaded the first time the Wi-Fi pane opens, never before: the dashboard is
 * the first thing a member sees and must not wait on another site for a pane
 * most visits never open. The sign-in name is the member's ID; the password
 * is the starting password CACENTRE made and emailed them, shown here until
 * they choose their own, which is never shown back. This page opens on the
 * centre's Wi-Fi before the member has signed in to it (CACENTRE's walled
 * garden), so it is where a first-time member sets theirs. Loading, an empty
 * card and an honest error with a retry are all said.
 */
(function () {
  'use strict';

  var pane = document.getElementById('view-wifi');
  if (!pane) return;

  var CSRF = pane.getAttribute('data-csrf') || '';
  var $ = function (id) { return document.getElementById(id); };
  var msg = $('wifiMsg'), dl = $('wifiDl'), form = $('wifiFoot'), retry = $('wifiRetry');
  var userEl = $('wifiUser'), pwState = $('wifiPwState'), pwLabel = $('wifiPwLabel'), formH = $('wifiFormH');
  var speedRow = $('wifiSpeedRow'), speedEl = $('wifiSpeed'), whereEl = $('wifiWhere');
  var todayEl = $('wifiToday'), monthEl = $('wifiMonth');
  var pw1 = $('wifiPw1'), pw2 = $('wifiPw2'), save = $('wifiSave'), leftEl = $('wifiLeft'), err = $('wifiPwErr');
  var card = null, loaded = false, busy = false;

  function bytes(b) {
    b = +b || 0;
    if (b >= 1073741824) return (Math.round(b / 107374182.4) / 10) + ' GB';
    if (b >= 1048576) return Math.round(b / 1048576) + ' MB';
    return b > 0 ? Math.max(1, Math.round(b / 1024)) + ' KB' : 'Nothing yet';
  }
  function say(text, bad) { msg.hidden = text === ''; msg.textContent = text; msg.classList.toggle('is-bad', !!bad); }
  function oops(text) { err.hidden = text === ''; err.textContent = text; }
  function day(s) { var d = s ? new Date(s.replace(' ', 'T')) : null; return d && !isNaN(d) ? d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : ''; }

  function draw() {
    retry.hidden = true;
    if (!card) { dl.hidden = form.hidden = true; say('You are not on the Wi-Fi list yet. Ask the team to check your membership is active.'); return; }
    if (card.access === 'off') { dl.hidden = form.hidden = true; say('You don’t have Wi-Fi access right now' + (card.why ? ': ' + card.why.replace(/\.$/, '') + '.' : '.') + ' Ask the team if you think this is wrong.', true); return; }
    var lim = card.access === 'limited' ? 'Limited for now' + (card.why ? ': ' + card.why.replace(/\.$/, '') : '') + '. ' : '';
    /* No card number recorded yet: CACENTRE signs them in with a stand-in, and says so. */
    var standIn = /^avg-\d+$/.test(card.username) ? ' Your card number isn’t recorded on Afrovanguard yet, so the Wi-Fi uses this name for now. Ask the office to record it: your username then becomes your card number.' : '';
    if (card.startCode) say(lim + (card.hasRouter ? 'Join the CACENTRE Wi-Fi and, when the sign-in page opens, use your username and the starting password below. It is emailed to you too. You can keep it, or choose your own.' : 'The Wi-Fi isn’t set up where you sign in yet. These details will work as soon as it is.') + standIn);
    else if (!card.hasPassword) say(lim + 'Choose a Wi-Fi password below. Then join the CACENTRE Wi-Fi and, when the sign-in page opens, use your username and this password.');
    else say(card.hasRouter ? lim + 'Join the CACENTRE Wi-Fi; when the sign-in page opens, use your username and the password you set.'
                            : 'The Wi-Fi isn’t set up where you sign in yet. Your details will work as soon as it is.');
    if (!card.startCode && standIn) say(msg.textContent + standIn);
    userEl.textContent = card.username;
    pwLabel.textContent = card.startCode ? 'Starting password' : 'Password';
    pwState.classList.toggle('wifi-v', !!card.startCode);
    pwState.textContent = card.startCode ? card.startCode : card.hasPassword ? 'Your own, set' + (card.pinSetAt ? ' on ' + day(card.pinSetAt) : '') : 'Not set yet';
    speedRow.hidden = !card.speed;
    if (card.speed) speedEl.textContent = 'Up to ' + card.speed.down + ' Mbit/s · ' + (card.speed.devices === 1 ? 'one device' : card.speed.devices + ' devices') + ' at a time' + (card.speed.hours ? ' · ' + card.speed.hours.replace('-', '–') : '');
    whereEl.textContent = card.where ? card.where.charAt(0).toUpperCase() + card.where.slice(1) : '';
    var u = card.usage || {};
    todayEl.textContent = bytes(u.today) + (u.dayMb ? ' of ' + bytes(u.dayMb * 1048576) : '');
    monthEl.textContent = bytes(u.month) + (u.monthMb ? ' of ' + bytes(u.monthMb * 1048576) : '');
    var own = card.hasPassword && !card.startCode;
    formH.textContent = card.startCode ? 'Choose your own password (optional)' : own ? 'Change your Wi-Fi password' : 'Set your Wi-Fi password';
    save.textContent = own ? 'Change password' : 'Set password';
    save.disabled = busy || card.resetsLeft < 1;
    leftEl.textContent = card.resetsLeft < 1 ? 'You’ve changed it three times today. Try again tomorrow.' : card.resetsLeft + (card.resetsLeft === 1 ? ' change' : ' changes') + ' left today';
    dl.hidden = form.hidden = false;
  }

  function fail(text) { dl.hidden = form.hidden = true; say(text, true); retry.hidden = false; }

  function load() {
    if (busy) return;
    busy = true; say('Loading your Wi-Fi details…'); retry.hidden = true;
    /* Read as text first, so a reply that is not what this page expects is
       still said in words — its HTTP status and the start of what came back —
       instead of a bare "couldn't load" nobody can act on. */
    fetch('/portal/cac-wifi.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.text().then(function (t) { return { status: r.status, text: t }; }); })
      .then(function (x) {
        busy = false;
        var d = null;
        try { d = JSON.parse(x.text); } catch (e) { d = null; }
        if (d && d.ok) { loaded = true; card = d.card; draw(); return; }
        if (d && d.error) { fail(String(d.error) + (d.detail ? ' (' + d.detail + ')' : '')); return; }
        var said = d && d.message ? String(d.message)
          : String(x.text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
        fail('Your Wi-Fi details couldn’t load (' + x.status + (said ? ': ' + said : '') + ').');
      })
      .catch(function () { busy = false; fail('Your Wi-Fi details couldn’t load. Check your connection.'); });
  }

  /* Checked here first so the common slips are said at once; CACENTRE checks again. */
  function problem(a, b) {
    if (a.length < 8) return 'Use at least 8 characters.';
    if (a.length > 64) return 'Use at most 64 characters.';
    if (/\s/.test(a)) return 'Leave out spaces.';
    if (a.indexOf(';') !== -1) return 'A semicolon (;) can’t be used in a Wi-Fi password.';
    if (card && a.toLowerCase() === String(card.username).toLowerCase()) return 'Your password can’t be your sign-in name.';
    if (a !== b) return 'The two passwords are not the same.';
    return '';
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy) return;
    var why = problem(pw1.value, pw2.value);
    if (why) { oops(why); pw1.focus(); return; }
    oops(''); busy = true; save.disabled = true; save.setAttribute('aria-busy', 'true');
    fetch('/portal/cac-wifi.php?action=password', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ password: pw1.value }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        busy = false; save.removeAttribute('aria-busy');
        if (!d || !d.ok) { save.disabled = false; oops((d && d.error) || 'Your password wasn’t saved. Try again.'); return; }
        pw1.value = pw2.value = ''; card = d.card; draw();
        say('Saved. Your password works at each centre’s Wi-Fi within a few minutes. Devices already signed in stay signed in.');
      })
      .catch(function () { busy = false; save.disabled = false; save.removeAttribute('aria-busy'); oops('Your password wasn’t saved. Check your connection.'); });
  });
  retry.addEventListener('click', load);

  function maybeLoad(view) { if (view === 'wifi' && !loaded) load(); }
  document.addEventListener('portal:view', function (e) { maybeLoad(e && e.detail ? e.detail.view : ''); });
  /* Arriving straight at /portal/#wifi, where no view event fires. */
  if (location.hash === '#wifi') maybeLoad('wifi');
})();
