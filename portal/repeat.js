/* portal/repeat.js — "Repeats", for any meeting form.
 *
 *   var r = AVRepeat(hostEl, { when: datetimeLocalInput, idp: 'tl', csrf: '…' });
 *   r.value()      → the rule to send as `repeat` ({freq:'once'} for a one-off)
 *   r.set(rule)    → fill it from a meeting's `repeat` (null for a one-off)
 *   r.reset()
 *
 * The quick choices cover most meetings; "Custom…" opens the rest: every N
 * days/weeks/months/years, which weekdays, the date or the nth/last weekday
 * of the month, an end (a date, or a number of times), and dates to skip.
 * Under it, in words, what the rule will do and its next dates, asked of the
 * server (lib/Recurrence.php) so the preview is what Google will be told.
 */
(function () {
  'use strict';
  var DAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
  var SHORT = { MO: 'Mon', TU: 'Tue', WE: 'Wed', TH: 'Thu', FR: 'Fri', SA: 'Sat', SU: 'Sun' };
  var LONG = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
  var ORD = ['first', 'second', 'third', 'fourth'];
  var PRESETS = {
    once: { freq: 'once' }, daily: { freq: 'daily' }, weekdays: { freq: 'weekly', days: ['MO', 'TU', 'WE', 'TH', 'FR'] },
    weekly: { freq: 'weekly' }, biweekly: { freq: 'weekly', interval: 2 }, monthly: { freq: 'monthly' }
  };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function ordinal(n) { var s = ['th', 'st', 'nd', 'rd'], v = n % 100; return n + (s[(v - 20) % 10] || s[v] || s[0]); }

  window.AVRepeat = function (host, opt) {
    opt = opt || {};
    var p = (opt.idp || 'rp') + 'Rp', whenEl = opt.when, skip = [], timer = 0, seq = 0;
    host.classList.add('rp');
    host.innerHTML =
      '<label class="meet-f rp-f"><span>Repeats</span><select id="' + p + 'Preset" class="meet-in">'
      + '<option value="once">Does not repeat</option><option value="daily">Every day</option><option value="weekdays">Every weekday (Mon–Fri)</option>'
      + '<option value="weekly">Every week</option><option value="biweekly">Every 2 weeks</option><option value="monthly">Every month</option>'
      + '<option value="custom">Custom…</option></select></label>'
      + '<fieldset class="rp-custom" id="' + p + 'Custom" hidden><legend>Custom repeat</legend>'
      + '<div class="rp-row"><label for="' + p + 'N">Every</label><input id="' + p + 'N" type="number" min="1" max="99" value="1" class="meet-in rp-n" inputmode="numeric">'
      + '<select id="' + p + 'Unit" class="meet-in" aria-label="Unit"><option value="daily">day(s)</option><option value="weekly" selected>week(s)</option><option value="monthly">month(s)</option><option value="yearly">year(s)</option></select></div>'
      + '<div class="rp-row rp-days" id="' + p + 'Days" role="group" aria-label="On these days">'
      + DAYS.map(function (d) { return '<button type="button" class="rp-day" data-day="' + d + '" aria-pressed="false">' + SHORT[d] + '</button>'; }).join('') + '</div>'
      + '<div class="rp-row rp-month" id="' + p + 'Month" role="radiogroup" aria-label="Which day of the month">'
      + ['date', 'nth', 'last'].map(function (m, i) { return '<label class="rp-radio"><input type="radio" name="' + p + 'Mon" value="' + m + '"' + (i ? '' : ' checked') + '> <span data-mon="' + m + '"></span></label>'; }).join('') + '</div>'
      + '<div class="rp-row rp-end" role="radiogroup" aria-label="Ends">'
      + '<span class="rp-lab">Ends</span>'
      + '<label class="rp-radio"><input type="radio" name="' + p + 'End" value="never" checked> Never</label>'
      + '<label class="rp-radio"><input type="radio" name="' + p + 'End" value="until"> On <input id="' + p + 'Until" type="date" class="meet-in rp-sm" aria-label="Last date"></label>'
      + '<label class="rp-radio"><input type="radio" name="' + p + 'End" value="count"> After <input id="' + p + 'Count" type="number" min="1" max="500" value="10" class="meet-in rp-n" aria-label="Number of meetings"> times</label></div>'
      + '<div class="rp-row rp-skip"><span class="rp-lab">Skip</span><input id="' + p + 'SkipIn" type="date" class="meet-in rp-sm" aria-label="A date to skip">'
      + '<button type="button" class="pbtn pbtn-ghost rp-add" id="' + p + 'SkipAdd">Skip this date</button><ul class="rp-chips" id="' + p + 'Skips" aria-label="Skipped dates"></ul></div>'
      + '</fieldset>'
      + '<p class="rp-preview" id="' + p + 'Prev" aria-live="polite"></p>';
    var $ = function (s) { return document.getElementById(p + s); };
    var preset = $('Preset'), custom = $('Custom');

    function start() {
      var v = whenEl && whenEl.value; if (!v) return null;
      var d = new Date(v); return isNaN(d) ? null : d;
    }
    function dayCode(d) { return DAYS[(d.getDay() + 6) % 7]; }
    /* The quick choices and the month options name the start's own day. */
    function relabel() {
      var d = start(), o = preset.querySelector('[value=weekly]'), o2 = preset.querySelector('[value=biweekly]'), o3 = preset.querySelector('[value=monthly]');
      if (!d) { o.textContent = 'Every week'; o2.textContent = 'Every 2 weeks'; o3.textContent = 'Every month'; return; }
      var wd = LONG[(d.getDay() + 6) % 7], n = Math.floor((d.getDate() - 1) / 7);
      o.textContent = 'Every week on ' + wd; o2.textContent = 'Every 2 weeks on ' + wd; o3.textContent = 'Every month on the ' + ordinal(d.getDate());
      host.querySelector('[data-mon=date]').textContent = 'On the ' + ordinal(d.getDate());
      host.querySelector('[data-mon=nth]').textContent = n < 4 ? 'On the ' + ORD[n] + ' ' + wd : 'On the last ' + wd;
      host.querySelector('[data-mon=last]').textContent = 'On the last ' + wd;
      host.querySelector('[data-mon=nth]').parentNode.hidden = n >= 4;
    }
    function showParts() {
      var u = $('Unit').value;
      $('Days').hidden = u !== 'weekly';
      $('Month').hidden = u !== 'monthly';
      if (u === 'weekly' && !host.querySelector('.rp-day[aria-pressed=true]')) {
        var d = start(); if (d) { var b = host.querySelector('[data-day=' + dayCode(d) + ']'); if (b) b.setAttribute('aria-pressed', 'true'); }
      }
    }
    function drawSkips() {
      $('Skips').innerHTML = skip.map(function (s) {
        var t = new Date(s + 'T12:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
        return '<li class="rp-chip">' + esc(t) + ' <button type="button" data-unskip="' + s + '" aria-label="Stop skipping ' + esc(t) + '">×</button></li>';
      }).join('');
    }

    function value() {
      var k = preset.value;
      if (k !== 'custom') return JSON.parse(JSON.stringify(PRESETS[k] || PRESETS.once));
      var r = { freq: $('Unit').value, interval: Math.max(1, Math.min(99, +$('N').value || 1)) };
      if (r.freq === 'weekly') r.days = [].map.call(host.querySelectorAll('.rp-day[aria-pressed=true]'), function (b) { return b.getAttribute('data-day'); });
      if (r.freq === 'monthly') { var m = host.querySelector('[name=' + p + 'Mon]:checked'); r.monthly = m ? m.value : 'date'; }
      var e = host.querySelector('[name=' + p + 'End]:checked'); r.end = e ? e.value : 'never';
      if (r.end === 'until') r.until = $('Until').value;
      if (r.end === 'count') r.count = Math.max(1, Math.min(500, +$('Count').value || 1));
      if (skip.length) r.skip = skip.slice();
      return r;
    }

    function preview() {
      clearTimeout(timer);
      timer = setTimeout(function () {
        var out = $('Prev'), v = value();
        if (v.freq === 'once') { out.textContent = ''; return; }
        if (!whenEl || !whenEl.value) { out.textContent = 'Pick the first date and time to see when it repeats.'; return; }
        var mine = ++seq;
        fetch('/portal/meetings.php?action=repeat_preview', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': opt.csrf || '' },
          body: JSON.stringify({ when: whenEl.value, repeat: v })
        }).then(function (r) { return r.json(); }).then(function (d) {
          if (mine !== seq) return;
          if (!d || !d.ok) { out.textContent = (d && d.error) || ''; return; }
          if (d.never) { out.innerHTML = '<b class="rp-warn">This never happens</b> — its end comes before its first date.'; return; }
          out.innerHTML = '<b>' + esc(d.label) + '</b>' + (d.moved ? '<br>Starts ' + esc(d.moved) + ', the first day it allows.' : '')
            + '<br><span class="rp-next">Next: ' + d.dates.map(esc).join(' · ') + '</span>';
        }).catch(function () {});
      }, 250);
    }

    preset.addEventListener('change', function () {
      custom.hidden = preset.value !== 'custom';
      if (preset.value === 'custom') {
        /* Opened from a quick choice: start from what it meant. */
        $('Unit').value = 'weekly'; showParts();
      }
      preview();
    });
    custom.addEventListener('click', function (e) {
      var b = e.target.closest('.rp-day');
      if (b) { b.setAttribute('aria-pressed', b.getAttribute('aria-pressed') === 'true' ? 'false' : 'true'); preview(); return; }
      var x = e.target.closest('[data-unskip]');
      if (x) { var s = x.getAttribute('data-unskip'); skip = skip.filter(function (y) { return y !== s; }); drawSkips(); preview(); }
    });
    $('SkipAdd').addEventListener('click', function () {
      var s = $('SkipIn').value; if (!s || skip.indexOf(s) >= 0) return;
      skip.push(s); skip.sort(); $('SkipIn').value = ''; drawSkips(); preview();
    });
    $('Unit').addEventListener('change', function () { showParts(); preview(); });
    custom.addEventListener('input', preview);
    custom.addEventListener('change', function (e) {
      /* Typing a date or a count chooses that ending. */
      if (e.target.id === p + 'Until') host.querySelector('[name=' + p + 'End][value=until]').checked = true;
      if (e.target.id === p + 'Count') host.querySelector('[name=' + p + 'End][value=count]').checked = true;
      preview();
    });
    if (whenEl) whenEl.addEventListener('change', function () { relabel(); preview(); });
    relabel();

    function set(rule) {
      skip = [];
      host.querySelectorAll('.rp-day').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
      relabel();
      var k = 'once';
      if (rule) {
        var d = start(), plain = !rule.until && !rule.count && !(rule.skip && rule.skip.length) && (rule.end || 'never') === 'never', iv = rule.interval || 1;
        var one = rule.days && rule.days.length === 1 && d && rule.days[0] === dayCode(d);
        if (plain && rule.freq === 'daily' && iv === 1) k = 'daily';
        else if (plain && rule.freq === 'weekly' && iv === 1 && rule.days && rule.days.join() === 'MO,TU,WE,TH,FR') k = 'weekdays';
        else if (plain && rule.freq === 'weekly' && one && iv <= 2) k = iv === 1 ? 'weekly' : 'biweekly';
        else if (plain && rule.freq === 'monthly' && iv === 1 && (rule.monthly || 'date') === 'date') k = 'monthly';
        else {
          k = 'custom';
          $('Unit').value = rule.freq; $('N').value = iv;
          (rule.days || []).forEach(function (dd) { var b = host.querySelector('[data-day=' + dd + ']'); if (b) b.setAttribute('aria-pressed', 'true'); });
          var m = host.querySelector('[name=' + p + 'Mon][value=' + (rule.monthly || 'date') + ']'); if (m) m.checked = true;
          host.querySelector('[name=' + p + 'End][value=' + (rule.end || 'never') + ']').checked = true;
          $('Until').value = rule.until || ''; $('Count').value = rule.count || 10;
          skip = (rule.skip || []).slice();
        }
      }
      preset.value = k; custom.hidden = k !== 'custom';
      showParts(); drawSkips(); preview();
    }
    function reset() { set(null); $('N').value = 1; $('Unit').value = 'weekly'; host.querySelector('[name=' + p + 'End][value=never]').checked = true; $('Until').value = ''; $('Count').value = 10; $('Prev').textContent = ''; }

    return { value: value, set: set, reset: reset };
  };
})();
