/* portal/recording.js — plays a recording's ten-minute parts as one: the next
 * part starts when one ends, and a marked moment jumps to the right part and
 * second. Times are from the start of the recording. */
(function () {
  'use strict';
  function clock(s) {
    s = Math.max(0, Math.floor(s));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(x).padStart(2, '0');
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-rec]'), function (card) {
    var audio = card.querySelector('[data-player]');
    if (!audio) return;
    var parts = [];
    try { parts = JSON.parse(card.getAttribute('data-parts') || '[]'); } catch (e) { parts = []; }
    if (!parts.length) return;
    var now = card.querySelector('[data-now]');
    var cur = -1, pendingSeek = null, autoplay = false;

    function show(i) {
      Array.prototype.forEach.call(card.querySelectorAll('[data-part]'), function (b) {
        b.classList.toggle('is-on', Number(b.getAttribute('data-part')) === parts[i].n);
      });
    }
    function load(i, at, play) {
      if (i < 0 || i >= parts.length) return;
      if (i !== cur) {
        cur = i;
        pendingSeek = at || 0; autoplay = !!play;
        audio.src = '/portal/recording.php?action=audio&part=' + parts[i].id;
        audio.load();
        show(i);
      } else {
        audio.currentTime = at || 0;
        if (play) audio.play().catch(function () {});
      }
    }
    audio.addEventListener('loadedmetadata', function () {
      if (pendingSeek) { try { audio.currentTime = pendingSeek; } catch (e) { /* not seekable yet */ } }
      pendingSeek = null;
      if (autoplay) audio.play().catch(function () {});
    });
    audio.addEventListener('timeupdate', function () {
      if (cur < 0 || !now) return;
      now.textContent = 'Part ' + (parts[cur].n + 1) + ' of ' + parts.length + ' · ' + clock(parts[cur].offset + audio.currentTime) + ' into the meeting';
    });
    audio.addEventListener('ended', function () { if (cur + 1 < parts.length) load(cur + 1, 0, true); });

    Array.prototype.forEach.call(card.querySelectorAll('[data-part]'), function (b) {
      b.addEventListener('click', function () {
        var n = Number(b.getAttribute('data-part'));
        for (var i = 0; i < parts.length; i++) if (parts[i].n === n) load(i, 0, true);
      });
    });
    Array.prototype.forEach.call(card.querySelectorAll('[data-jump]'), function (b) {
      b.addEventListener('click', function () {
        var t = Number(b.getAttribute('data-jump'));
        var i = 0;
        for (var k = 0; k < parts.length; k++) if (parts[k].offset <= t) i = k;
        // Start a few seconds early: a mark is pressed just after the moment it marks.
        load(i, Math.max(0, t - parts[i].offset - 5), true);
      });
    });
    load(0, 0, false);
  });
})();
