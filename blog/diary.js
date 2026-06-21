/* ============================================================
   THE AFROVANGUARD DIARY — shared behaviour
   - "Listen to this article" player (Web Speech API)
   - Sticky table-of-contents scroll-spy
   - Header shadow on scroll, mobile nav
   - Category filtering on the index
   ============================================================ */
(function () {
  'use strict';

  /* ---- Header shadow on scroll ---- */
  var header = document.getElementById('site-header');
  if (header) {
    var onScroll = function () { header.classList.toggle('scrolled', window.scrollY > 8); };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* ---- Mobile nav ---- */
  var toggle = document.getElementById('nav-toggle');
  var mobile = document.getElementById('nav-mobile');
  if (toggle && mobile) {
    var setOpen = function (open) {
      mobile.classList.toggle('open', open);
      toggle.setAttribute('aria-expanded', String(open));
      if (open) { mobile.removeAttribute('inert'); } else { mobile.setAttribute('inert', ''); }
      document.body.style.overflow = open ? 'hidden' : '';
    };
    toggle.addEventListener('click', function () { setOpen(toggle.getAttribute('aria-expanded') !== 'true'); });
    mobile.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', function () { setOpen(false); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
  }

  /* ---- Table-of-contents scroll-spy ---- */
  var toc = document.querySelector('.toc');
  if (toc && 'IntersectionObserver' in window) {
    var links = Array.prototype.slice.call(toc.querySelectorAll('a'));
    var map = {};
    links.forEach(function (a) {
      var id = a.getAttribute('href').slice(1);
      var sec = document.getElementById(id);
      if (sec) map[id] = a;
    });
    var spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          links.forEach(function (l) { l.classList.remove('active'); });
          var a = map[en.target.id];
          if (a) a.classList.add('active');
        }
      });
    }, { rootMargin: '-96px 0px -65% 0px', threshold: 0 });
    Object.keys(map).forEach(function (id) { spy.observe(document.getElementById(id)); });
  }

  /* ---- Listen to this article (speech synthesis) ---- */
  var bar = document.querySelector('.listen-bar');
  if (bar && 'speechSynthesis' in window) {
    var body = document.querySelector('.article-body');
    var playBtn = bar.querySelector('.listen-play');
    var iconPlay = bar.querySelector('.icon-play');
    var iconPause = bar.querySelector('.icon-pause');
    var timeEl = bar.querySelector('.listen-cur');
    var totalEl = bar.querySelector('.listen-total');
    var rateBtn = bar.querySelector('.listen-rate');
    var backBtn = bar.querySelector('.listen-back');
    var fwdBtn = bar.querySelector('.listen-fwd');

    // Build sentence list from article prose (skip media cards / figures).
    var chunks = [];
    if (body) {
      body.querySelectorAll('h2, h3, p, li, blockquote').forEach(function (n) {
        var t = (n.textContent || '').replace(/\s+/g, ' ').trim();
        if (t.length > 1) chunks.push(t);
      });
    }
    var fullText = chunks.join('. ');
    var words = fullText.split(/\s+/).length;
    var rate = 1.0;
    var rates = [1.0, 1.25, 1.5, 0.75];
    var rateIdx = 0;
    var idx = 0;          // current chunk
    var playing = false;
    var elapsed = 0;      // seconds estimate
    var ticker = null;

    var fmt = function (s) {
      s = Math.max(0, Math.round(s));
      var m = Math.floor(s / 60);
      var ss = ('0' + (s % 60)).slice(-2);
      return m + ':' + ss;
    };
    var totalSecs = function () { return (words / (2.6 * rate)); }; // ~156 wpm baseline
    if (totalEl) totalEl.textContent = fmt(totalSecs());

    var setPlayingUI = function (on) {
      playing = on;
      if (iconPlay) iconPlay.style.display = on ? 'none' : '';
      if (iconPause) iconPause.style.display = on ? '' : 'none';
      playBtn.setAttribute('aria-label', on ? 'Pause article audio' : 'Listen to this article');
    };

    var startTicker = function () {
      stopTicker();
      ticker = setInterval(function () {
        elapsed += 0.25;
        if (timeEl) timeEl.textContent = fmt(elapsed);
      }, 250);
    };
    var stopTicker = function () { if (ticker) { clearInterval(ticker); ticker = null; } };

    var speakFrom = function (i) {
      window.speechSynthesis.cancel();
      idx = Math.max(0, Math.min(i, chunks.length - 1));
      var u = new SpeechSynthesisUtterance(chunks.slice(idx).join('. '));
      u.rate = rate;
      u.lang = 'en-GB';
      u.onend = function () { setPlayingUI(false); stopTicker(); if (timeEl) timeEl.textContent = fmt(totalSecs()); elapsed = totalSecs(); };
      u.onerror = function () { setPlayingUI(false); stopTicker(); };
      window.speechSynthesis.speak(u);
      setPlayingUI(true);
      startTicker();
    };

    playBtn.addEventListener('click', function () {
      if (!playing) {
        if (window.speechSynthesis.paused) { window.speechSynthesis.resume(); setPlayingUI(true); startTicker(); }
        else { elapsed = idx === 0 ? 0 : elapsed; speakFrom(idx); }
      } else {
        window.speechSynthesis.pause(); setPlayingUI(false); stopTicker();
      }
    });

    if (rateBtn) rateBtn.addEventListener('click', function () {
      rateIdx = (rateIdx + 1) % rates.length;
      rate = rates[rateIdx];
      rateBtn.textContent = rate.toFixed(2).replace(/0$/, '') + 'x';
      if (totalEl) totalEl.textContent = fmt(totalSecs());
      if (playing) speakFrom(idx); // re-speak at new rate
    });

    var jump = function (delta) {
      var step = Math.max(1, Math.round(chunks.length * 0.06)); // ~ a few sentences ≈ 10s
      var target = idx + delta * step;
      elapsed = Math.max(0, elapsed + delta * 10);
      if (playing) speakFrom(target); else { idx = Math.max(0, Math.min(target, chunks.length - 1)); if (timeEl) timeEl.textContent = fmt(elapsed); }
    };
    if (backBtn) backBtn.addEventListener('click', function () { jump(-1); });
    if (fwdBtn) fwdBtn.addEventListener('click', function () { jump(1); });

    window.addEventListener('beforeunload', function () { window.speechSynthesis.cancel(); });
  } else if (bar) {
    // No speech support — keep the control as a visual element, disable play.
    var pb = bar.querySelector('.listen-play');
    if (pb) { pb.setAttribute('aria-disabled', 'true'); pb.title = 'Audio playback is not supported in this browser'; }
  }

  /* ---- Index category filter ---- */
  var filters = document.querySelector('.diary-filters');
  if (filters) {
    var chips = filters.querySelectorAll('.chip');
    var cards = document.querySelectorAll('[data-cat]');
    filters.addEventListener('click', function (e) {
      var chip = e.target.closest('.chip');
      if (!chip) return;
      chips.forEach(function (c) { c.classList.remove('active'); });
      chip.classList.add('active');
      var cat = chip.getAttribute('data-filter');
      cards.forEach(function (card) {
        var show = cat === 'all' || card.getAttribute('data-cat') === cat;
        card.style.display = show ? '' : 'none';
      });
    });
  }
})();
