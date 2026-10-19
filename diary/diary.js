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

  /* ---- Audio download (content-type checked) ----
     The narration links point at /diary/audio.php with a `download` attr; on
     any failure that endpoint returns JSON, and the browser would happily save
     that JSON as a file. Intercept: fetch first, save only real audio, and
     surface the error message otherwise. */
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href*="/diary/audio.php"]');
    if (!a) return;
    e.preventDefault();
    var url = a.getAttribute('href');
    var label = a.getAttribute('title') || '';
    a.setAttribute('aria-busy', 'true'); toast('Preparing audio…');
    fetch(url, { credentials: 'same-origin' }).then(function (r) {
      var ct = r.headers.get('Content-Type') || '';
      if (!r.ok || ct.indexOf('audio') === -1) {
        return r.json().then(function (d) { toast((d && d.error) || 'Audio isn’t available yet.'); })
                       .catch(function () { toast('Audio isn’t available yet.'); });
      }
      return r.blob().then(function (blob) {
        var m = /slug=([a-z0-9\-]+)/i.exec(url); var name = (m ? m[1] : 'article') + '.mp3';
        var dl = URL.createObjectURL(blob), link = document.createElement('a');
        link.href = dl; link.download = name; document.body.appendChild(link); link.click();
        setTimeout(function () { URL.revokeObjectURL(dl); link.remove(); }, 1500);
        toast('Audio downloaded.');
      });
    }).catch(function () { toast('Could not download audio.'); })
      .then(function () { a.removeAttribute('aria-busy'); });
  });

  /* ---- Theme (light/dark) ---- */
  var root = document.documentElement;
  /* Pages in the Home chrome are light-only (lib/partials.php THEME_BOOT_SITE):
     data-theme-lock means neither the `d` shortcut nor a stray toggle flips them. */
  function applyTheme(t) { if (root.hasAttribute('data-theme-lock')) return; root.setAttribute('data-theme', t); set('av.theme', t); }
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

  /* ---- Reading progress + back-to-top + sub-bar + header shadow ---- */
  var bar = document.getElementById('read-progress');
  var toTop = document.querySelector('.to-top');
  var article = document.querySelector('.article-body');
  /* Tables: wrap each in a horizontal-scroll container so wide tables never
     break the reading column or overflow the page on small screens. */
  if (article) {
    [].slice.call(article.querySelectorAll('table')).forEach(function (t) {
      if (t.parentElement && t.parentElement.classList.contains('table-scroll')) return;
      var w = document.createElement('div'); w.className = 'table-scroll';
      t.parentNode.insertBefore(w, t); w.appendChild(t);
    });
  }
  function onScroll() {
    var y = window.scrollY || window.pageYOffset;
    if (header) header.classList.toggle('scrolled', y > 8);
    if (bar) {
      var h = document.documentElement.scrollHeight - window.innerHeight;
      bar.style.width = (h > 0 ? Math.min(100, (y / h) * 100) : 0) + '%';
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
    ].filter(function (r) { return r[0] !== 'd' || !root.hasAttribute('data-theme-lock'); });
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
  });
})();
