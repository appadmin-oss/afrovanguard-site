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
      if (!email) return;
      fetch('/diary/api.php?action=subscribe', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email })
      }).then(function (r) { return r.json(); })
        .then(function (d) { toast(d && d.ok ? (d.message || 'Subscribed.') : (d && d.error || 'Could not subscribe.')); if (d && d.ok) form.reset(); })
        .catch(function () { toast('Network error — please try again.'); });
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

  /* ---- Listen to this article (speech synthesis, with highlight) ---- */
  var lb = listenBar;
  if (lb && 'speechSynthesis' in window && article) {
    var synth = window.speechSynthesis;
    var playBtns = [].slice.call(document.querySelectorAll('.listen-play, .mini-play'));
    var iconPlay = lb.querySelector('.icon-play');
    var iconPause = lb.querySelector('.icon-pause');
    var curEl = lb.querySelector('.listen-cur');
    var totalEl = lb.querySelector('.listen-total');
    var rateBtn = lb.querySelector('.listen-rate');
    var backBtn = lb.querySelector('.listen-back');
    var fwdBtn = lb.querySelector('.listen-fwd');

    // Source elements -> chunks (so we can highlight what's being read)
    var nodes = [].slice.call(article.querySelectorAll('h2, h3, p, li, blockquote')).filter(function (n) { return (n.textContent || '').trim().length > 1; });
    var chunks = nodes.map(function (n) { return (n.textContent || '').replace(/\s+/g, ' ').trim(); });
    var words = chunks.join(' ').split(/\s+/).length;
    var rates = [1.0, 1.25, 1.5, 0.75]; var rateIdx = 0; var rate = 1.0;
    var idx = 0; var playing = false; var elapsed = 0; var ticker = null; var highlighted = null;

    var fmt = function (s) { s = Math.max(0, Math.round(s)); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); };
    var totalSecs = function () { return words / (2.6 * rate); };
    if (totalEl) totalEl.textContent = fmt(totalSecs());

    function setUI(on) {
      playing = on;
      if (iconPlay) iconPlay.style.display = on ? 'none' : '';
      if (iconPause) iconPause.style.display = on ? '' : 'none';
      document.querySelectorAll('.mini-play').forEach(function (m) { m.innerHTML = on ? '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg>' : '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>'; });
      playBtns.forEach(function (b) { b.setAttribute('aria-label', on ? 'Pause article audio' : 'Listen to this article'); });
    }
    function startTicker() { stopTicker(); ticker = setInterval(function () { elapsed += 0.25; if (curEl) curEl.textContent = fmt(elapsed); }, 250); }
    function stopTicker() { if (ticker) { clearInterval(ticker); ticker = null; } }
    function highlight(i) {
      if (highlighted) highlighted.classList.remove('speaking');
      highlighted = nodes[i] || null;
      if (highlighted) { highlighted.classList.add('speaking'); }
    }
    function clearHighlight() { if (highlighted) { highlighted.classList.remove('speaking'); highlighted = null; } }

    function speakFrom(i) {
      synth.cancel();
      idx = Math.max(0, Math.min(i, chunks.length - 1));
      speakChunk();
      setUI(true); startTicker();
    }
    function speakChunk() {
      if (idx >= chunks.length) { setUI(false); stopTicker(); clearHighlight(); elapsed = totalSecs(); if (curEl) curEl.textContent = fmt(elapsed); idx = 0; return; }
      highlight(idx);
      var u = new SpeechSynthesisUtterance(chunks[idx]);
      u.rate = rate; u.lang = 'en-GB';
      u.onend = function () { if (!playing) return; idx++; speakChunk(); };
      u.onerror = function () { setUI(false); stopTicker(); };
      synth.speak(u);
    }
    playBtns.forEach(function (b) {
      b.addEventListener('click', function () {
        if (!playing) { speakFrom(idx); }
        else { playing = false; synth.cancel(); setUI(false); stopTicker(); }
      });
    });
    if (rateBtn) rateBtn.addEventListener('click', function () {
      rateIdx = (rateIdx + 1) % rates.length; rate = rates[rateIdx];
      rateBtn.textContent = (rate % 1 === 0 ? rate.toFixed(1) : rate) + 'x';
      if (totalEl) totalEl.textContent = fmt(totalSecs());
      if (playing) speakFrom(idx);
    });
    function jump(d) {
      var step = Math.max(1, Math.round(chunks.length * 0.06));
      elapsed = Math.max(0, elapsed + d * 10);
      var t = Math.max(0, Math.min(idx + d * step, chunks.length - 1));
      if (playing) speakFrom(t); else { idx = t; highlight(idx); if (curEl) curEl.textContent = fmt(elapsed); }
    }
    if (backBtn) backBtn.addEventListener('click', function () { jump(-1); });
    if (fwdBtn) fwdBtn.addEventListener('click', function () { jump(1); });
    window.addEventListener('beforeunload', function () { synth.cancel(); });
    window.__avListen = { toggle: function () { playBtns[0] && playBtns[0].click(); } };
  } else if (lb) {
    var pb = lb.querySelector('.listen-play'); if (pb) { pb.title = 'Audio playback is not supported in this browser'; }
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
  if ('IntersectionObserver' in window) {
    var rev = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); rev.unobserve(e.target); } }); }, { threshold: 0.08 });
    document.querySelectorAll('[data-reveal]').forEach(function (n) { rev.observe(n); });
  } else { document.querySelectorAll('[data-reveal]').forEach(function (n) { n.classList.add('in'); }); }

  /* ---- Index: search + category filter + saved view ---- */
  var controls = document.querySelector('.diary-controls') || document.querySelector('.diary-filters');
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
