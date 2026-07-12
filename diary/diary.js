/* ============================================================
   THE AFROVANGUARD DIARY — shared behaviour (no dependencies)
   Modern reading features, all working client-side:
   theme, reading progress, condensed sub-bar, font-size,
   listen-to-article (with live paragraph highlight), TOC
   scroll-spy, bookmarks, reactions, search, share, lightbox,
   reading-position resume, scroll reveal, keyboard shortcuts.
   ============================================================ */
(function () {
  'use strict';
  var LS = window.localStorage;
  var get = function (k, d) { try { var v = LS.getItem(k); return v === null ? d : v; } catch (e) { return d; } };
  var set = function (k, v) { try { LS.setItem(k, v); } catch (e) {} };
  var slug = (document.body.getAttribute('data-slug') || location.pathname).replace(/\/+$/, '');

  /* ---- Toast ---- */
  var toastEl;
  function toast(msg) {
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'toast'; document.body.appendChild(toastEl); }
    toastEl.textContent = msg; toastEl.classList.add('show');
    clearTimeout(toast._t); toast._t = setTimeout(function () { toastEl.classList.remove('show'); }, 2400);
  }

  /* ---- Theme (light/dark) ---- */
  var root = document.documentElement;
  function applyTheme(t) { root.setAttribute('data-theme', t); set('av.theme', t); }
  document.querySelectorAll('.theme-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var cur = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      applyTheme(cur); toast(cur === 'dark' ? 'Dark mode on' : 'Light mode on');
    });
  });

  /* ---- Header shadow on scroll ---- */
  var header = document.getElementById('site-header');

  /* ---- Mobile nav ---- */
  var toggle = document.getElementById('nav-toggle');
  var mobile = document.getElementById('nav-mobile');
  var scrim = document.querySelector('.scrim');
  if (toggle && mobile) {
    var setOpen = function (open) {
      mobile.classList.toggle('open', open);
      if (scrim) scrim.classList.toggle('open', open);
      toggle.setAttribute('aria-expanded', String(open));
      if (open) mobile.removeAttribute('inert'); else mobile.setAttribute('inert', '');
      document.body.style.overflow = open ? 'hidden' : '';
    };
    toggle.addEventListener('click', function () { setOpen(toggle.getAttribute('aria-expanded') !== 'true'); });
    if (scrim) scrim.addEventListener('click', function () { setOpen(false); });
    mobile.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', function () { setOpen(false); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
  }

  /* ---- Font-size controls ---- */
  var scale = parseFloat(get('av.scale', '1')) || 1;
  function applyScale() { root.style.setProperty('--reading-scale', String(scale)); set('av.scale', String(scale)); }
  applyScale();
  document.querySelectorAll('[data-font="dec"]').forEach(function (b) { b.addEventListener('click', function () { scale = Math.max(0.85, Math.round((scale - 0.1) * 100) / 100); applyScale(); }); });
  document.querySelectorAll('[data-font="inc"]').forEach(function (b) { b.addEventListener('click', function () { scale = Math.min(1.4, Math.round((scale + 0.1) * 100) / 100); applyScale(); }); });

  /* ---- Reading progress + back-to-top + sub-bar + header shadow ---- */
  var bar = document.getElementById('read-progress');
  var subbar = document.querySelector('.subbar');
  var toTop = document.querySelector('.to-top');
  var article = document.querySelector('.article-body');
  var listenBar = document.querySelector('.listen-bar');
  function onScroll() {
    var y = window.scrollY || window.pageYOffset;
    if (header) header.classList.toggle('scrolled', y > 8);
    if (bar) {
      var h = document.documentElement.scrollHeight - window.innerHeight;
      bar.style.width = (h > 0 ? Math.min(100, (y / h) * 100) : 0) + '%';
    }
    if (subbar && listenBar) {
      var past = listenBar.getBoundingClientRect().bottom < 60;
      subbar.classList.toggle('show', past);
    }
    if (toTop) toTop.classList.toggle('show', y > 700);
    if (article && slug) savePos();
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  if (toTop) toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });

  /* ---- Reading-position resume ---- */
  var posKey = 'av.pos.' + slug;
  var saveT;
  function savePos() {
    clearTimeout(saveT);
    saveT = setTimeout(function () {
      var h = document.documentElement.scrollHeight - window.innerHeight;
      var pct = h > 0 ? (window.scrollY / h) : 0;
      if (pct > 0.04 && pct < 0.92) set(posKey, pct.toFixed(3)); else set(posKey, '0');
    }, 400);
  }
  if (article) {
    var saved = parseFloat(get(posKey, '0'));
    if (saved > 0.04) {
      var resume = document.createElement('button');
      resume.className = 'btn btn-ink btn-sm';
      resume.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:190';
      resume.textContent = 'Resume reading ↓';
      resume.addEventListener('click', function () {
        var h = document.documentElement.scrollHeight - window.innerHeight;
        window.scrollTo({ top: h * saved, behavior: 'smooth' });
        resume.remove();
      });
      document.body.appendChild(resume);
      setTimeout(function () { resume.remove(); }, 8000);
    }
  }

  /* ---- Table-of-contents scroll-spy ---- */
  var toc = document.querySelector('.toc');
  if (toc && 'IntersectionObserver' in window) {
    var links = [].slice.call(toc.querySelectorAll('a'));
    var map = {};
    links.forEach(function (a) { var id = a.getAttribute('href').slice(1); var s = document.getElementById(id); if (s) map[id] = a; });
    var spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { links.forEach(function (l) { l.classList.remove('active'); }); if (map[en.target.id]) map[en.target.id].classList.add('active'); }
      });
    }, { rootMargin: '-100px 0px -65% 0px', threshold: 0 });
    Object.keys(map).forEach(function (id) { spy.observe(document.getElementById(id)); });
  }

  /* ---- Bookmark / save-for-later ---- */
  function savedSet() { try { return JSON.parse(get('av.saved', '[]')); } catch (e) { return []; } }
  function isSaved(s) { return savedSet().indexOf(s) !== -1; }
  function toggleSaved(s) {
    var arr = savedSet(); var i = arr.indexOf(s);
    if (i === -1) arr.push(s); else arr.splice(i, 1);
    set('av.saved', JSON.stringify(arr));
    return i === -1;
  }
  document.querySelectorAll('[data-bookmark]').forEach(function (btn) {
    var s = btn.getAttribute('data-bookmark');
    var sync = function () { btn.classList.toggle('is-on', isSaved(s)); btn.setAttribute('aria-pressed', String(isSaved(s))); };
    sync();
    btn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      var now = toggleSaved(s); sync();
      toast(now ? 'Saved to your reading list' : 'Removed from reading list');
      document.querySelectorAll('[data-bookmark="' + s + '"]').forEach(function (b) { b.classList.toggle('is-on', now); });
    });
  });

  /* ---- Reactions (clap) — persisted server-side via /diary/api.php ---- */
  document.querySelectorAll('[data-react]').forEach(function (btn) {
    var s = btn.getAttribute('data-react');
    var key = 'av.clap.' + s;                       // remembers if THIS device clapped
    var total = parseInt(btn.getAttribute('data-base') || '0', 10); // server total (SSR'd)
    var mine = parseInt(get(key, '0'), 10) || 0;
    var countEl = btn.querySelector('.react-count');
    var render = function () { if (countEl) countEl.textContent = total; btn.classList.toggle('clapped', mine > 0); };
    render();
    // Refresh the live total from the server (kept in sync across readers).
    fetch('/diary/api.php?action=reactions&slug=' + encodeURIComponent(s))
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.ok) { total = d.claps; render(); } })
      .catch(function () {});
    btn.addEventListener('click', function () {
      var em = btn.querySelector('.emoji');
      if (em && em.animate) em.animate([{ transform: 'scale(1.4) rotate(-12deg)' }, { transform: 'scale(1)' }], { duration: 260 });
      mine = Math.min(mine + 1, 50); set(key, String(mine));
      total += 1; render();                          // optimistic
      fetch('/diary/api.php?action=react', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ slug: s, count: 1 })
      }).then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.ok) { total = d.claps; render(); } })
        .catch(function () {});
    });
  });

  /* ---- Newsletter subscribe — persisted server-side ---- */
  document.querySelectorAll('.diary-subscribe').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var input = form.querySelector('input[type="email"]');
      var email = (input && input.value || '').trim();
      var msg = form.querySelector('.sub-msg');
      var btn = form.querySelector('button[type="submit"]');
      var setMsg = function (t, ok) { if (msg) { msg.textContent = t; msg.style.color = ok ? '#16a34a' : '#dc2626'; } else { toast(t); } };
      if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { setMsg('Please enter a valid email address.', false); return; }
      if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'Subscribing…'; }
      fetch('/diary/api.php?action=subscribe', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email, hp: (form.querySelector('[name=hp]') || {}).value || '' })
      }).then(function (r) { return r.json(); })
        .then(function (d) { setMsg(d && d.ok ? (d.message || 'You’re subscribed — watch for the next dispatch.') : (d && d.error || 'Could not subscribe.'), !!(d && d.ok)); if (d && d.ok) form.reset(); })
        .catch(function () { setMsg('Network error — please try again.', false); })
        .finally(function () { if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label || 'Subscribe →'; } });
    });
  });

  /* ---- Share / copy link ---- */
  document.querySelectorAll('[data-share]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-share') || location.href;
      var title = document.title;
      if (navigator.share) { navigator.share({ title: title, url: url }).catch(function () {}); }
      else if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { toast('Link copied to clipboard'); }); }
      else { toast(url); }
    });
  });

  /* ---- Scholar Reader — free, human-like read-aloud (neural Web Speech)
         sentence-by-sentence with a karaoke caption that lights each word. ---- */
  var lb = listenBar;
  var hasTTS = 'speechSynthesis' in window && typeof window.SpeechSynthesisUtterance !== 'undefined';
  // Neural (human-like) read-aloud: server-synthesised MP3 per passage, played
  // through <audio> so it works on every browser. Falls back to the browser's
  // speechSynthesis when no engine is configured.
  var neural = !!(lb && article && lb.getAttribute('data-tts') === '1');
  var ttsSlug = (lb && lb.getAttribute('data-slug')) || slug;
  if (lb && article && (neural || hasTTS)) {
    var synth = window.speechSynthesis;
    var audioEl = neural ? new Audio() : null;
    var prefetch = {};
    var elapsedBase = 0;
    function ttsUrl(t) { return '/diary/tts.php?slug=' + encodeURIComponent(ttsSlug) + '&t=' + encodeURIComponent(t); }
    var playBtns = [].slice.call(document.querySelectorAll('.listen-play, .mini-play'));
    var iconPlay = lb.querySelector('.icon-play');
    var iconPause = lb.querySelector('.icon-pause');
    var curEl = lb.querySelector('.listen-cur');
    var totalEl = lb.querySelector('.listen-total');
    var rateBtn = lb.querySelector('.listen-rate');
    var backBtn = lb.querySelector('.listen-back');
    var fwdBtn = lb.querySelector('.listen-fwd');
    var voiceSel = lb.querySelector('.listen-voice');
    function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    // Source elements → readable units. Skip media/embeds/figures/code.
    var nodes = [].slice.call(article.querySelectorAll('h2, h3, p, li, blockquote'))
      .filter(function (n) {
        if (n.closest('.embed, figure, pre, .callout > strong')) return false;
        return (n.textContent || '').trim().length > 1;
      });

    // Normalise text so the engine reads it like a person, not a parser.
    var ABBR = { 'e.g.': 'for example', 'i.e.': 'that is', 'etc.': 'and so on', 'vs.': 'versus',
      'Dr.': 'Doctor', 'Mr.': 'Mister', 'Mrs.': 'Misses', 'Ms.': 'Miss', 'Prof.': 'Professor',
      'No.': 'Number', 'approx.': 'approximately', 'Fig.': 'Figure', 'St.': 'Saint', '&': ' and ',
      '%': ' percent', 'NGO': 'N G O', 'LGA': 'L G A', 'AI': 'A.I.', 'FAQ': 'F A Q', 'RSS': 'R S S' };
    function normalise(t, isHeading) {
      t = t.replace(/\s+/g, ' ').trim();
      t = t.replace(/https?:\/\/\S+/g, ' link ');
      t = t.replace(/\[[0-9]+\]/g, '');
      Object.keys(ABBR).forEach(function (k) {
        t = t.replace(new RegExp(k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), ABBR[k]);
      });
      t = t.replace(/\s*[—–]\s*/g, ', ');
      t = t.replace(/…|\.\.\./g, ', ');
      t = t.replace(/(\d),(\d{3})/g, '$1$2');
      if (isHeading && !/[.!?]$/.test(t)) t += '.';
      return t;
    }
    // Split into sentences for natural cadence + tighter highlight sync.
    function sentences(t) {
      return t.replace(/([.!?])\s+(?=["'(]?[A-Z0-9])/g, '$1').split('')
        .map(function (s) { return s.trim(); }).filter(function (s) { return s.length; });
    }
    // Break a paragraph's text into URL-safe pieces (≤ ~280 chars) at sentence/
    // word boundaries — each piece is verbatim article text, so the server can
    // validate it and synthesise it, and the <audio> src stays well under URL limits.
    function chunkText(t) {
      var out = [], max = 280, buf = '';
      (t.match(/[^.!?]+[.!?]*\s*/g) || [t]).forEach(function (p) {
        p = p.trim(); if (!p) return;
        if ((buf ? buf.length + 1 + p.length : p.length) <= max) { buf = buf ? buf + ' ' + p : p; }
        else { if (buf) out.push(buf); if (p.length <= max) { buf = p; } else { for (var i = 0; i < p.length; i += max) out.push(p.slice(i, i + max).trim()); buf = ''; } }
      });
      if (buf) out.push(buf);
      return out;
    }
    var units = [];
    nodes.forEach(function (n) {
      var heading = /^H[23]$/.test(n.tagName);
      if (neural) {
        chunkText((n.textContent || '').replace(/\s+/g, ' ').trim()).forEach(function (piece) {
          if (piece) units.push({ node: n, text: piece, raw: piece });
        });
      } else {
        sentences(normalise(n.textContent || '', heading)).forEach(function (s) { units.push({ node: n, text: s, raw: s }); });
      }
    });
    if (!units.length) { var t0 = (article.textContent || '').replace(/\s+/g, ' ').trim(); units = [{ node: nodes[0] || article, text: neural ? t0.slice(0, 280) : normalise(t0, false), raw: t0.slice(0, 280) }]; }
    var words = units.reduce(function (a, u) { return a + u.text.split(/\s+/).length; }, 0);

    var rates = [1.0, 1.15, 1.3, 1.5, 0.85]; var rate = parseFloat(get('av.read.rate', '1')) || 1;
    var rateIdx = Math.max(0, rates.indexOf(rate));
    var idx = 0, playing = false, elapsed = 0, ticker = null, highlighted = null, keepAlive = null, voices = [], voice = null;
    var capWords = [], capRanges = [], activeWord = null;

    var fmt = function (s) { s = Math.max(0, Math.round(s)); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); };
    var totalSecs = function () { return words / (2.7 * rate); };
    if (totalEl) totalEl.textContent = fmt(totalSecs());
    if (rateBtn) rateBtn.textContent = (rate % 1 === 0 ? rate.toFixed(1) : rate) + 'x';

    /* ---- floating karaoke caption (teleprompter) ---- */
    var cap = document.createElement('div');
    cap.className = 'av-reader'; cap.setAttribute('aria-hidden', 'true');
    cap.innerHTML = '<div class="avr-inner"><div class="avr-eq"><span></span><span></span><span></span></div>'
      + '<p class="avr-text"></p>'
      + '<div class="avr-controls">'
      + '<button class="avr-btn avr-back" aria-label="Back"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 17l-5-5 5-5"/><path d="M18 17l-5-5 5-5"/></svg></button>'
      + '<button class="avr-btn avr-play" aria-label="Pause"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg></button>'
      + '<button class="avr-btn avr-fwd" aria-label="Forward"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 17l5-5-5-5"/><path d="M6 17l5-5-5-5"/></svg></button>'
      + '<button class="avr-btn avr-rate" aria-label="Speed">1.0x</button>'
      + '<span class="avr-time"><b class="avr-cur">0:00</b> / <span class="avr-tot">0:00</span></span>'
      + '<button class="avr-btn avr-close" aria-label="Stop reading"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>'
      + '</div></div>';
    document.body.appendChild(cap);
    var capText = cap.querySelector('.avr-text');
    var capRate = cap.querySelector('.avr-rate');
    var capCur = cap.querySelector('.avr-cur');
    var capTot = cap.querySelector('.avr-tot');
    capTot.textContent = fmt(totalSecs());

    function renderCaption(s) {
      var html = '', pos = 0; capRanges = [];
      s.split(/(\s+)/).forEach(function (tok) {
        if (/\S/.test(tok)) { html += '<span class="cw" data-a="' + pos + '" data-b="' + (pos + tok.length) + '">' + esc(tok) + '</span>'; }
        else { html += tok; }
        pos += tok.length;
      });
      capText.innerHTML = html;
      capWords = [].slice.call(capText.querySelectorAll('.cw'));
      capRanges = capWords.map(function (w) { return [+w.getAttribute('data-a'), +w.getAttribute('data-b'), w]; });
      activeWord = null;
    }
    function highlightWord(ci) {
      for (var k = 0; k < capRanges.length; k++) {
        if (ci >= capRanges[k][0] && ci < capRanges[k][1]) {
          if (activeWord) activeWord.classList.remove('on');
          activeWord = capRanges[k][2]; activeWord.classList.add('on'); return;
        }
      }
    }

    /* ---- voices: prefer natural/neural ---- */
    function scoreVoice(v) {
      var n = (v.name + ' ' + (v.voiceURI || '')).toLowerCase(); var s = 0;
      if (/^en[-_]/i.test(v.lang)) s += 5;
      if (/en[-_]gb/i.test(v.lang)) s += 3; else if (/en[-_](us|ng|au|ie)/i.test(v.lang)) s += 2;
      if (/natural|neural|enhanced|premium|wavenet|siri/.test(n)) s += 6;
      if (/google/.test(n)) s += 4;
      if (/microsoft/.test(n)) s += 2;
      if (/(daniel|samantha|serena|aria|libby|sonia|ryan|arthur|george|jenny|guy)/.test(n)) s += 3;
      if (v.localService === false) s += 1;
      return s;
    }
    function loadVoices() {
      voices = (synth.getVoices() || []).filter(function (v) { return /^en/i.test(v.lang); });
      if (!voices.length) return;
      voices.sort(function (a, b) { return scoreVoice(b) - scoreVoice(a); });
      var saved = get('av.read.voice', '');
      voice = voices.filter(function (v) { return v.voiceURI === saved; })[0] || voices[0];
      if (voiceSel) {
        voiceSel.hidden = voices.length < 2;
        voiceSel.innerHTML = voices.map(function (v) {
          return '<option value="' + v.voiceURI + '"' + (v === voice ? ' selected' : '') + '>' + v.name.replace(/\(.*\)/, '').trim() + '</option>';
        }).join('');
      }
    }
    // Neural mode uses the server-configured voice, so the browser-voice picker
    // is irrelevant (and synth may be absent on this browser) — skip it entirely.
    if (neural) {
      if (voiceSel) voiceSel.hidden = true;
    } else {
      loadVoices();
      if (synth && synth.onvoiceschanged !== undefined) synth.onvoiceschanged = loadVoices;
      if (voiceSel) voiceSel.addEventListener('change', function () {
        voice = voices.filter(function (v) { return v.voiceURI === this.value; }, this)[0] || voice;
        set('av.read.voice', voice ? voice.voiceURI : '');
        if (playing) speakFrom(idx);
      });
    }

    function setUI(on) {
      playing = on;
      cap.classList.toggle('show', on);
      var pp = on ? '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg>'
                  : '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
      var avrPlay = cap.querySelector('.avr-play'); if (avrPlay) avrPlay.innerHTML = pp;
      if (iconPlay) iconPlay.style.display = on ? 'none' : '';
      if (iconPause) iconPause.style.display = on ? '' : 'none';
      document.querySelectorAll('.mini-play').forEach(function (m) { m.innerHTML = pp; });
      playBtns.forEach(function (b) { b.setAttribute('aria-pressed', String(on)); b.setAttribute('aria-label', on ? 'Pause article audio' : 'Listen to this article'); });
    }
    function startTicker() { if (neural) return; stopTicker(); ticker = setInterval(function () { elapsed += 0.25; var t = fmt(elapsed); if (curEl) curEl.textContent = t; if (capCur) capCur.textContent = t; }, 250); }
    function stopTicker() { if (ticker) { clearInterval(ticker); ticker = null; } }
    function highlight(i) {
      if (highlighted) highlighted.classList.remove('speaking');
      highlighted = units[i] ? units[i].node : null;
      if (highlighted) {
        highlighted.classList.add('speaking');
        var r = highlighted.getBoundingClientRect();
        if (r.top < 90 || r.bottom > window.innerHeight - 180) highlighted.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }
    function clearHighlight() { if (highlighted) { highlighted.classList.remove('speaking'); highlighted = null; } }

    function startKeepAlive() { stopKeepAlive(); keepAlive = setInterval(function () { if (playing && synth.speaking) { synth.pause(); synth.resume(); } }, 9000); }
    function stopKeepAlive() { if (keepAlive) { clearInterval(keepAlive); keepAlive = null; } }

    function speakFrom(i) {
      if (neural && audioEl) { try { audioEl.pause(); } catch (e) {} } else { synth.cancel(); }
      idx = Math.max(0, Math.min(i, units.length - 1));
      if (neural) elapsedBase = totalSecs() * (idx / Math.max(1, units.length));
      setUI(true); startTicker(); if (!neural) startKeepAlive(); speakChunk();
    }
    function speakChunk() {
      if (idx >= units.length) { stop(); elapsed = totalSecs(); var t = fmt(elapsed); if (curEl) curEl.textContent = t; if (capCur) capCur.textContent = t; idx = 0; return; }
      highlight(idx);
      renderCaption(units[idx].text);
      if (neural) { playNeural(idx); return; }
      var u = new SpeechSynthesisUtterance(units[idx].text);
      u.rate = rate; u.pitch = 1.0; u.volume = 1; u.lang = (voice && voice.lang) || 'en-GB';
      if (voice) u.voice = voice;
      u.onboundary = function (e) { if (e.charIndex != null && (e.name === 'word' || e.name == null)) highlightWord(e.charIndex); };
      u.onend = function () { if (!playing) return; idx++; speakChunk(); };
      u.onerror = function () { if (!playing) return; idx++; speakChunk(); };
      synth.speak(u);
    }
    /* ── Neural playback: one cached MP3 per passage, played via <audio> ── */
    function highlightWordByFrac(frac) {
      if (!capWords.length) return;
      var wi = Math.max(0, Math.min(capWords.length - 1, Math.floor(frac * capWords.length)));
      if (activeWord) activeWord.classList.remove('on');
      activeWord = capWords[wi]; if (activeWord) activeWord.classList.add('on');
    }
    function prefetchNext() {
      var j = idx + 1;
      if (units[j] && !prefetch[j]) { var a = new Audio(); a.preload = 'auto'; a.src = ttsUrl(units[j].raw); prefetch[j] = a; }
    }
    function playNeural(i) {
      var pre = prefetch[i];
      if (pre) { audioEl = pre; delete prefetch[i]; } else { audioEl.src = ttsUrl(units[i].raw); }
      try { audioEl.playbackRate = rate; } catch (e) {}
      audioEl.ontimeupdate = function () {
        var d = audioEl.duration;
        if (d && isFinite(d)) {
          var t = elapsedBase + audioEl.currentTime;
          if (curEl) curEl.textContent = fmt(t); if (capCur) capCur.textContent = fmt(t);
          highlightWordByFrac(audioEl.currentTime / d);
        }
      };
      audioEl.onended = function () { if (!playing) return; elapsedBase += (audioEl.duration && isFinite(audioEl.duration)) ? audioEl.duration : 0; idx++; speakChunk(); };
      audioEl.onerror = function () { if (!playing) return; idx++; speakChunk(); };
      var p = audioEl.play(); if (p && p.catch) p.catch(function () {});
      prefetchNext();
    }
    function stop() { playing = false; if (neural && audioEl) { try { audioEl.pause(); } catch (e) {} } else { synth.cancel(); } setUI(false); stopTicker(); stopKeepAlive(); clearHighlight(); }
    function toggle() { playing ? stop() : speakFrom(idx); }
    playBtns.forEach(function (b) { b.addEventListener('click', toggle); });
    cap.querySelector('.avr-play').addEventListener('click', toggle);
    cap.querySelector('.avr-close').addEventListener('click', stop);

    function setRate() {
      rateIdx = (rateIdx + 1) % rates.length; rate = rates[rateIdx]; set('av.read.rate', String(rate));
      var lbl = (rate % 1 === 0 ? rate.toFixed(1) : rate) + 'x';
      if (rateBtn) rateBtn.textContent = lbl; if (capRate) capRate.textContent = lbl;
      var t = fmt(totalSecs()); if (totalEl) totalEl.textContent = t; if (capTot) capTot.textContent = t;
      if (playing) speakFrom(idx);
    }
    if (rateBtn) rateBtn.addEventListener('click', setRate);
    capRate.addEventListener('click', setRate);
    capRate.textContent = (rate % 1 === 0 ? rate.toFixed(1) : rate) + 'x';

    function jump(d) {
      elapsed = Math.max(0, elapsed + d * 8);
      var t = Math.max(0, Math.min(idx + d, units.length - 1));
      if (playing) speakFrom(t); else { idx = t; highlight(idx); renderCaption(units[idx].text); }
    }
    if (backBtn) backBtn.addEventListener('click', function () { jump(-1); });
    if (fwdBtn) fwdBtn.addEventListener('click', function () { jump(1); });
    cap.querySelector('.avr-back').addEventListener('click', function () { jump(-1); });
    cap.querySelector('.avr-fwd').addEventListener('click', function () { jump(1); });
    window.addEventListener('beforeunload', function () { if (neural && audioEl) { try { audioEl.pause(); } catch (e) {} } else { synth.cancel(); } });
    window.__avListen = { toggle: function () { toggle(); } };
  } else if (lb) {
    var pb = lb.querySelector('.listen-play');
    if (pb) { pb.setAttribute('aria-disabled', 'true'); pb.title = 'Read-aloud is not available in this browser'; pb.addEventListener('click', function () { toast('Read-aloud is not supported in this browser.'); }); }
  }

  /* ---- Lightbox for all article images (incl. editor-inserted) ---- */
  var imgs = [].slice.call(document.querySelectorAll('.article-body img, .course-main img'));
  if (imgs.length) {
    imgs.forEach(function (im) { if (!im.getAttribute('loading')) im.setAttribute('loading', 'lazy'); });
    var lbox = document.createElement('div'); lbox.className = 'lightbox'; lbox.setAttribute('role', 'dialog'); lbox.setAttribute('aria-modal', 'true');
    lbox.innerHTML = '<button class="lb-close" aria-label="Close">×</button>'
      + '<button class="lb-nav lb-prev" aria-label="Previous">‹</button>'
      + '<img alt="" /><div class="lb-cap"></div>'
      + '<button class="lb-nav lb-next" aria-label="Next">›</button>';
    document.body.appendChild(lbox);
    var limg = lbox.querySelector('img'), lcap = lbox.querySelector('.lb-cap'), cur = 0;
    function capFor(im) { var f = im.closest('figure'); var fc = f && f.querySelector('figcaption'); return (fc && fc.textContent) || im.alt || ''; }
    function openAt(i) { cur = (i + imgs.length) % imgs.length; var im = imgs[cur]; limg.src = im.currentSrc || im.src; limg.alt = im.alt || ''; lcap.textContent = capFor(im); lbox.classList.add('open'); document.body.style.overflow = 'hidden'; }
    function close() { lbox.classList.remove('open'); document.body.style.overflow = ''; }
    imgs.forEach(function (im, i) { im.addEventListener('click', function () { openAt(i); }); });
    lbox.querySelector('.lb-close').addEventListener('click', close);
    lbox.querySelector('.lb-prev').addEventListener('click', function (e) { e.stopPropagation(); openAt(cur - 1); });
    lbox.querySelector('.lb-next').addEventListener('click', function (e) { e.stopPropagation(); openAt(cur + 1); });
    lbox.addEventListener('click', function (e) { if (e.target === lbox) close(); });
    document.addEventListener('keydown', function (e) {
      if (!lbox.classList.contains('open')) return;
      if (e.key === 'Escape') close(); else if (e.key === 'ArrowLeft') openAt(cur - 1); else if (e.key === 'ArrowRight') openAt(cur + 1);
    });
  }

  /* ---- Academy enrolment form ---- */
  document.querySelectorAll('.enroll-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = form.querySelector('.enroll-msg');
      var data = { slug: form.getAttribute('data-course') };
      ['name', 'email', 'phone', 'note'].forEach(function (k) { var el = form.querySelector('[name="' + k + '"]'); if (el) data[k] = el.value.trim(); });
      var btn = form.querySelector('button[type=submit]'); btn.disabled = true;
      fetch('/academy/api.php?action=enroll', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          msg.hidden = false; msg.className = 'enroll-msg ' + (d.ok ? 'ok' : 'err');
          msg.textContent = d.ok ? d.message : (d.error || 'Could not submit.');
          if (d.ok) form.reset();
        })
        .catch(function () { msg.hidden = false; msg.className = 'enroll-msg err'; msg.textContent = 'Network error — please try again.'; })
        .finally(function () { btn.disabled = false; });
    });
  });

  /* ---- Scroll reveal ---- */
  var revealAll = function () { document.querySelectorAll('[data-reveal], .reveal-stagger').forEach(function (n) { n.classList.add('in'); }); };
  if ('IntersectionObserver' in window) {
    var rev = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); rev.unobserve(e.target); } }); }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
    document.querySelectorAll('[data-reveal], .reveal-stagger').forEach(function (n) { rev.observe(n); });
    setTimeout(revealAll, 3000); // safety: never leave content hidden
  } else { revealAll(); }

  /* ---- Index: search + category filter + saved view ---- */
  // On the diary index, feed.js owns search + filtering + progressive loading;
  // skip this legacy client-side filter there to avoid double-binding. It still
  // runs on any other page that uses .diary-filters without the new controller.
  var controls = document.getElementById('diaryControls') ? null
    : (document.querySelector('.diary-controls') || document.querySelector('.diary-filters'));
  if (controls) {
    var cards = [].slice.call(document.querySelectorAll('[data-cat]'));
    var searchInput = document.querySelector('.search-input');
    var chips = [].slice.call(document.querySelectorAll('.chip'));
    var noRes = document.querySelector('.no-results');
    var featured = document.querySelector('.featured');
    var curFilter = 'all';
    var curQuery = searchInput && searchInput.value ? searchInput.value.trim().toLowerCase() : '';
    function apply() {
      var shown = 0;
      cards.forEach(function (card) {
        var cat = card.getAttribute('data-cat');
        var text = (card.getAttribute('data-search') || card.textContent || '').toLowerCase();
        var okCat = curFilter === 'all' || (curFilter === 'saved' ? isSaved((card.getAttribute('data-slug') || '')) : cat === curFilter);
        var okQ = !curQuery || text.indexOf(curQuery) !== -1;
        var show = okCat && okQ; card.style.display = show ? '' : 'none'; if (show) shown++;
      });
      if (featured) featured.style.display = (curFilter === 'all' && !curQuery) ? '' : 'none';
      if (noRes) noRes.style.display = shown === 0 ? 'block' : 'none';
    }
    if (searchInput) searchInput.addEventListener('input', function () { curQuery = this.value.trim().toLowerCase(); apply(); });
    chips.forEach(function (chip) {
      chip.addEventListener('click', function () { chips.forEach(function (c) { c.classList.remove('active'); }); chip.classList.add('active'); curFilter = chip.getAttribute('data-filter'); apply(); });
    });
    // reflect saved markers on cards
    document.querySelectorAll('[data-bookmark]').forEach(function (b) { b.classList.toggle('is-on', isSaved(b.getAttribute('data-bookmark'))); });
    if (curQuery) apply(); // honour ?q= prefill from the server
  }

  /* ---- Heading deep-links (hover # → copy section URL) ---- */
  if (article) {
    article.querySelectorAll('h2[id], h3[id]').forEach(function (h) {
      var a = document.createElement('a');
      a.className = 'heading-anchor'; a.href = '#' + h.id;
      a.setAttribute('aria-label', 'Link to this section'); a.textContent = '#';
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var url = location.href.split('#')[0] + '#' + h.id;
        history.replaceState(null, '', '#' + h.id);
        if (navigator.clipboard) navigator.clipboard.writeText(url).then(function () { toast('Section link copied'); });
        h.scrollIntoView({ behavior: 'smooth' });
      });
      h.appendChild(a);
    });
  }

  /* ---- Keyboard-shortcuts help dialog (press ?) ---- */
  function showShortcuts() {
    var existing = document.getElementById('kbd-help');
    if (existing) { existing.remove(); return; }
    var rows = [
      ['/', 'Focus search'], ['l', 'Listen / pause'], ['d', 'Toggle dark mode'],
      ['b', 'Save / bookmark'], ['t', 'Back to top'], ['?', 'Show this help'], ['Esc', 'Close']
    ];
    var ov = document.createElement('div');
    ov.id = 'kbd-help'; ov.className = 'kbd-help';
    ov.innerHTML = '<div class="kbd-card" role="dialog" aria-modal="true" aria-label="Keyboard shortcuts">'
      + '<h3>Keyboard shortcuts</h3><dl>'
      + rows.map(function (r) { return '<dt><kbd>' + r[0] + '</kbd></dt><dd>' + r[1] + '</dd>'; }).join('')
      + '</dl><button class="btn btn-ink btn-sm" data-close>Got it</button></div>';
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target.hasAttribute('data-close')) ov.remove(); });
    document.body.appendChild(ov);
  }

  /* ---- Human narration player (author-uploaded audio) ---- */
  (function () {
    var fig = document.querySelector('[data-narration]');
    if (!fig) return;
    var audio = fig.querySelector('audio');
    var playBtn = fig.querySelector('.na-play');
    var icPlay = fig.querySelector('.na-ic-play');
    var icPause = fig.querySelector('.na-ic-pause');
    var bar = fig.querySelector('.na-bar');
    var prog = fig.querySelector('.na-progress');
    var timeEl = fig.querySelector('.na-time');
    var speedBtn = fig.querySelector('.na-speed');
    if (!audio || !playBtn) return;
    var speeds = [1, 1.25, 1.5, 0.75];
    var si = 0;
    function fmt(s) {
      if (!isFinite(s) || s < 0) s = 0;
      var m = Math.floor(s / 60), r = Math.floor(s % 60);
      return m + ':' + (r < 10 ? '0' : '') + r;
    }
    function setPlaying(on) {
      fig.classList.toggle('is-playing', on);
      if (icPlay) icPlay.hidden = on;
      if (icPause) icPause.hidden = !on;
      playBtn.setAttribute('aria-label', on ? 'Pause narration' : 'Play narration');
    }
    playBtn.addEventListener('click', function () {
      if (audio.paused) { audio.play().catch(function () {}); } else { audio.pause(); }
    });
    audio.addEventListener('play', function () { setPlaying(true); });
    audio.addEventListener('pause', function () { setPlaying(false); });
    audio.addEventListener('ended', function () { setPlaying(false); if (prog) prog.style.width = '0%'; });
    audio.addEventListener('timeupdate', function () {
      var d = audio.duration || 0;
      if (prog && d) prog.style.width = (audio.currentTime / d * 100) + '%';
      if (timeEl) timeEl.textContent = fmt(d ? d - audio.currentTime : audio.currentTime);
      if (bar) bar.setAttribute('aria-valuenow', String(Math.floor(audio.currentTime)));
    });
    audio.addEventListener('loadedmetadata', function () {
      if (timeEl) timeEl.textContent = fmt(audio.duration);
      if (bar) { bar.setAttribute('aria-valuemin', '0'); bar.setAttribute('aria-valuemax', String(Math.floor(audio.duration || 0))); }
    });
    function seekFromEvent(e) {
      var rect = bar.getBoundingClientRect();
      var x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
      var ratio = Math.max(0, Math.min(1, x / rect.width));
      if (audio.duration) audio.currentTime = ratio * audio.duration;
    }
    if (bar) {
      bar.addEventListener('click', seekFromEvent);
      bar.addEventListener('keydown', function (e) {
        if (!audio.duration) return;
        if (e.key === 'ArrowRight') { e.preventDefault(); audio.currentTime = Math.min(audio.duration, audio.currentTime + 10); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); audio.currentTime = Math.max(0, audio.currentTime - 10); }
        else if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); playBtn.click(); }
      });
    }
    if (speedBtn) {
      speedBtn.addEventListener('click', function () {
        si = (si + 1) % speeds.length;
        audio.playbackRate = speeds[si];
        speedBtn.textContent = speeds[si] + '×';
      });
    }
  })();

  /* ---- Keyboard shortcuts ---- */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { var h = document.getElementById('kbd-help'); if (h) h.remove(); }
    if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
    if (e.key === '/') { var si = document.querySelector('.search-input'); if (si) { e.preventDefault(); si.focus(); } }
    else if (e.key === '?') { e.preventDefault(); showShortcuts(); }
    else if (e.key === 't') { window.scrollTo({ top: 0, behavior: 'smooth' }); }
    else if (e.key === 'd') { var c = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'; applyTheme(c); }
    else if (e.key === 'l' && window.__avListen) { window.__avListen.toggle(); }
    else if (e.key === 'b') { var bm = document.querySelector('.subbar [data-bookmark], [data-bookmark]'); if (bm) bm.click(); }
  });
})();
