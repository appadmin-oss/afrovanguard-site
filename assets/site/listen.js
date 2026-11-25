/* assets/site/listen.js — "Listen": read a page's main text aloud.
 *
 * Uses the browser's own speech (window.speechSynthesis). Nothing is sent
 * anywhere and there is no outside service. The control (lib/A11y.php
 * listen()) stays hidden unless the browser can speak, and nothing plays until
 * the reader presses Listen.
 *
 * It reads one block at a time (paragraph, heading, list item, quote) from the
 * element the control names, skipping navigation, footers, asides, anything
 * aria-hidden or hidden, and the control itself; the block being read is
 * highlighted and kept in view (without smooth scrolling when the reader has
 * asked for less motion). Pause keeps the place; Stop goes back to the start;
 * Escape stops. A short polite announcement through the page's one announcer
 * (a11y-core.js) says what changed. It prefers a Nigerian English voice, then
 * British English, then any English one.
 *
 * WITH A SCREEN READER: the group is named "Read aloud with your browser's
 * voice", so somebody whose screen reader already reads the page knows what it
 * is and can pass it by. It never starts by itself, and it stops when the page
 * goes away (pagehide), when the text it was reading leaves the page (the
 * lesson player swaps lessons), and when another Listen control starts.
 */
(function () {
  'use strict';
  var synth = window.speechSynthesis;
  var boxes = document.querySelectorAll('[data-av-listen]');
  if (!boxes.length || !synth || typeof window.SpeechSynthesisUtterance !== 'function') return;

  var ICON = {
    play: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8.5 6.2v11.6a.8.8 0 0 0 1.2.7l9.3-5.8a.8.8 0 0 0 0-1.4L9.7 5.5a.8.8 0 0 0-1.2.7z" fill="currentColor"/></svg>',
    pause: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="7" y="5.5" width="3.5" height="13" rx="1" fill="currentColor"/><rect x="13.5" y="5.5" width="3.5" height="13" rx="1" fill="currentColor"/></svg>',
    stop: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="6.5" y="6.5" width="11" height="11" rx="2" fill="currentColor"/></svg>'
  };
  var SKIP = 'nav,footer,aside,script,style,noscript,template,button,select,textarea,form,[hidden],[aria-hidden="true"],[data-av-listen],.av-listen,.av-sr-skip,.avle-brief';
  var BLOCK = 'h1,h2,h3,h4,h5,h6,p,li,blockquote,dt,dd,figcaption,caption,td,th,pre';
  var voice = null;
  function pickVoice() {
    var vs = synth.getVoices() || [];
    var by = function (re) { for (var i = 0; i < vs.length; i++) if (re.test((vs[i].lang || '').replace('_', '-'))) return vs[i]; return null; };
    voice = by(/^en-NG/i) || by(/^en-GB/i) || by(/^en(-|$)/i) || null;
  }
  pickVoice();
  if (typeof synth.addEventListener === 'function') synth.addEventListener('voiceschanged', pickVoice);
  else synth.onvoiceschanged = pickVoice;
  var reduce = function () {
    if (window.AvA11y) return window.AvA11y.reducedMotion();
    return document.documentElement.classList.contains('a11y-still') || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  };

  function blocks(root) {
    var out = [];
    root.querySelectorAll(BLOCK).forEach(function (b) {
      if (b.closest(SKIP)) return;
      if (b.parentElement && b.parentElement.closest(BLOCK)) return; // a <p> inside an <li> is read with it
      if (b.getClientRects().length === 0) return;                  // display:none and friends
      var t = text(b);
      if (t) out.push({ el: b, text: t });
    });
    return out;
  }
  function text(b) {
    var c = b.cloneNode(true);
    c.querySelectorAll(SKIP).forEach(function (x) { x.remove(); });
    return (c.textContent || '').replace(/\s+/g, ' ').trim();
  }

  boxes.forEach(function (box) {
    var root = document.querySelector(box.getAttribute('data-av-listen'));
    if (!root) return;
    var label = box.getAttribute('data-label') || 'Listen to this page';
    box.setAttribute('role', 'group');
    box.setAttribute('aria-label', 'Read aloud with your browser’s voice');
    box.classList.add('av-ui');
    box.innerHTML =
      '<button type="button" class="av-listen-btn" data-act="play">' + ICON.play + '<span>' + esc(label) + '</span></button>'
      + '<button type="button" class="av-listen-btn" data-act="stop" hidden>' + ICON.stop + '<span>Stop</span></button>'
      + '<label class="av-listen-rate">Speed <select data-act="rate"><option value="0.75">Slower</option><option value="1" selected>Normal</option><option value="1.25">Faster</option><option value="1.5">Fastest</option></select></label>';
    box.hidden = false;
    var play = box.querySelector('[data-act="play"]'), stop = box.querySelector('[data-act="stop"]'),
        rate = box.querySelector('[data-act="rate"]');
    try { var r = localStorage.getItem('av.listen.rate'); if (r && rate.querySelector('option[value="' + r + '"]')) rate.value = r; } catch (e) {}

    var list = [], i = 0, mode = 'idle', gen = 0, on = null;
    /* The page's one polite announcer (a11y-core.js). */
    function say(t) { if (window.AvA11y) window.AvA11y.announce(t); }
    function mark(el) {
      if (on) on.classList.remove('av-listen-on');
      on = el;
      if (!el) return;
      el.classList.add('av-listen-on');
      var rc = el.getBoundingClientRect();
      if (rc.top < 80 || rc.bottom > innerHeight - 40) el.scrollIntoView({ block: 'center', behavior: reduce() ? 'auto' : 'smooth' });
    }
    function setMode(m) {
      mode = m;
      var playing = m === 'playing';
      play.setAttribute('data-act', playing ? 'pause' : 'play');
      play.innerHTML = (playing ? ICON.pause + '<span>Pause</span>' : ICON.play + '<span>' + (m === 'paused' ? 'Resume' : esc(label)) + '</span>');
      stop.hidden = m === 'idle';
      box.setAttribute('data-state', m);
    }
    function speak() {
      var my = ++gen;
      if (!root.isConnected) { halt(true); return; }   // the text it was reading has left the page
      if (i >= list.length) { finish(); return; }
      var item = list[i];
      mark(item.el);
      var u = new SpeechSynthesisUtterance(item.text);
      if (voice) { u.voice = voice; u.lang = voice.lang; } else u.lang = document.documentElement.lang || 'en-NG';
      u.rate = +rate.value || 1;
      u.onend = function () { if (my !== gen || mode !== 'playing') return; i++; speak(); };
      u.onerror = function (e) { if (my !== gen) return; if (e && (e.error === 'interrupted' || e.error === 'canceled')) return; i++; speak(); };
      synth.speak(u);
    }
    function start() {
      var resumed = mode === 'paused';
      if (mode === 'idle') { list = blocks(root); i = 0; if (!list.length) { say('There is nothing here to read.'); return; } }
      stopOthers(box);
      synth.cancel();
      setMode('playing');
      say(resumed ? 'Reading resumed.' : 'Reading aloud.');
      speak();
    }
    function pause() { gen++; synth.cancel(); setMode('paused'); say('Paused.'); }
    function halt(quiet) { gen++; synth.cancel(); i = 0; mark(null); setMode('idle'); if (!quiet) say('Stopped.'); }
    function finish() { mark(null); i = 0; setMode('idle'); say('Finished reading.'); }
    box._halt = halt;

    play.addEventListener('click', function () { if (play.getAttribute('data-act') === 'pause') pause(); else start(); });
    stop.addEventListener('click', function () { halt(false); play.focus(); });
    rate.addEventListener('change', function () {
      try { localStorage.setItem('av.listen.rate', rate.value); } catch (e) {}
      if (mode === 'playing') { gen++; synth.cancel(); speak(); } // the same block again, at the new speed
    });
    box.addEventListener('keydown', function (e) { if (e.key === 'Escape' && mode !== 'idle') { e.stopPropagation(); halt(false); play.focus(); } });
    setMode('idle');
  });

  function stopOthers(keep) { boxes.forEach(function (b) { if (b !== keep && b._halt && b.getAttribute('data-state') !== 'idle') b._halt(true); }); }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  /* Leaving the page stops the voice: speech outlives the page in some browsers. */
  addEventListener('pagehide', function () { synth.cancel(); });
})();
