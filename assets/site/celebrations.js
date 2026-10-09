/* ============================================================
   assets/site/celebrations.js — the celebrations pop-up (row 17 v2).

   Design: “Afrovanguard Celebrations” (5a pop-up, 3b doodle logo,
   3a scenes, 2b member themes, 2a ring logo).

   Data: GET /api.php?action=celebrations (lib/celebrations.php):
     celebration  today’s items, ranked; primary is the one card shown
                  (your birthday › a major holiday › teammates’ birthdays ›
                  other observances). Admin overrides and uploaded doodle
                  art come through it.
     theme        the holiday nearest today (day before → day after), for
                  the quiet themes on member pages.

   When it shows: once per day per celebration (localStorage
   av.celebrate.seen), never on admin, sign-in or checkout pages, never
   while a video is playing, and not where the page opts out with
   data-avcel="off" on <html> or <body>. Member pages (the portal and My
   Account, or any page with <body data-avcel="member">) also get the
   2b theme: a 3px stripe, the doodle logo and a dismissible banner.

   Hooks for other pages: <html data-avcel-theme="<key>"> and the class
   avcel-themed while a theme runs; a ‘avcel:theme’ event on document;
   window.AvCel = { data, open(), ringLogo(key, caption) }.
   Nothing loads beyond this file on a day with nothing to celebrate.
   ============================================================ */
(function () {
  'use strict';
  if (window.AvCel) return;

  var D = document, root = D.documentElement;
  var SEAL = '/assets/site/av-seal.png';
  var reduced = function () { return !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches); };
  var store = {
    get: function (k) { try { return localStorage.getItem(k) || ''; } catch (e) { return ''; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } }
  };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var path = location.pathname;
  var optedOut = function () { return root.getAttribute('data-avcel') === 'off' || (D.body && D.body.getAttribute('data-avcel') === 'off'); };
  var NO_POPUP = /^\/(admin|studio|login|signin|sign-in|checkout|pay)(\/|$)|^\/academy\/studio|\/pay(\.php)?\/?$/i.test(path);
  var isMember = function () { return (D.body && D.body.getAttribute('data-avcel') === 'member') || /^\/(portal|account|my-account|member(\.html)?|donor-dashboard(\.html)?|diary\/me|academy\/ngv\/dashboard)(\/|$|\.php)/i.test(path); };
  var MOVABLE = { goodfriday: 1, easter: 1, eastermonday: 1, eidfitr: 1, eidadha: 1 };
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var FOUNDED = 2017;
  var WORDS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
  var TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
  function words(n) { return n < 20 ? WORDS[n] : n < 100 ? TENS[Math.floor(n / 10)] + (n % 10 ? '-' + WORDS[n % 10] : '') : String(n); }
  function dayMonth(iso, short) { var p = String(iso || '').split('-'); if (p.length < 3) return ''; var m = MONTHS[+p[1] - 1] || ''; return (+p[2]) + ' ' + (short ? m.slice(0, 3) : m); }
  function initials(name) { var p = String(name || '').trim().split(/\s+/); return ((p[0] || '?').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : '')).toUpperCase(); }
  function first(name) { return String(name || '').trim().split(/\s+/)[0] || 'friend'; }
  function safeUrl(u) { u = String(u || ''); return /^(\/[^\/]|https:\/\/)/.test(u) ? u.replace(/["<>\s]/g, '') : ''; }
  function hex(h) { return /^#[0-9a-fA-F]{6}$/.test(String(h || '')) ? h : ''; }
  var T = function (fn, ms, bag) { var id = setTimeout(fn, ms); if (bag) bag.push(id); return id; };
  var icon = function (d, s, w) { return '<svg aria-hidden="true" focusable="false" width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + w + '" stroke-linecap="round"><path d="' + d + '"/></svg>'; };
  var X = 'M6 6l12 12M18 6L6 18';

  /* ── assets: tokens + styles + drawings, only when needed ──────── */
  function ensureCss() {
    var has = function (h) { return [].some.call(D.querySelectorAll('link[rel="stylesheet"]'), function (l) { return (l.getAttribute('href') || '').indexOf(h) > -1; }); };
    var add = function (h) { var l = D.createElement('link'); l.rel = 'stylesheet'; l.href = h; D.head.appendChild(l); return l; };
    if (!has('av-tokens.css') && !getComputedStyle(root).getPropertyValue('--av-cel-ng')) add('/assets/site/av-tokens.css');
    return has('/avcel.css') ? null : add('/assets/site/avcel.css');
  }
  function loadArt(cb) {
    if (window.AvCelArt) return cb();
    var s = D.createElement('script'); s.src = '/assets/site/avcel-art.js'; s.async = true;
    s.onload = function () { if (window.AvCelArt) cb(); };
    D.head.appendChild(s);
  }
  function whenFonts(cb) {
    var done = false, go = function () { if (!done) { done = true; cb(); } };
    try { if (D.fonts && D.fonts.load) { D.fonts.load('600 42px "Cormorant Garamond"').then(go, go); setTimeout(go, 1200); return; } } catch (e) { /* old browser */ }
    go();
  }

  /* ── what the card says ───────────────────────────────────────── */
  function artKeyOf(p) {
    if (!p) return '';
    if (p.type === 'birthday') return 'birthday';
    return window.AvCelArt && window.AvCelArt.has(p.key) ? p.key : '';
  }
  function uploadedArt(p) {
    var u = safeUrl(p && p.doodle);
    return u && u.indexOf('/assets/doodles/') !== 0 ? u : '';
  }
  function artOpts(dateIso) {
    var y = +String(dateIso || '').slice(0, 4) || new Date().getFullYear(), n = Math.max(1, y - FOUNDED);
    return { years: n, yearsWord: words(n), since: FOUNDED, year: y };
  }
  function model(c, signedIn, member) {
    var p = c.primary, m = { p: p, date: c.date, art: artKeyOf(p), img: uploadedArt(p), title: p.title || '', msg: p.message || '', opts: artOpts(c.date) };
    if (p.type === 'birthday' && p.mine) {
      var per = p.person || {};
      m.kind = 'mine'; m.cls = 'mine'; m.ini = per.initials || initials(per.name); m.age = per.age || '';
      m.kicker = 'Today · ' + dayMonth(c.date) + ' · your birthday';
      if (p.points > 0) m.perk = '+' + p.points + ' birthday points when you sign in at CACENTRE today';
      m.cta = { label: 'See your birthday card', href: member ? '' : '/portal/' };
      m.second = 'Maybe later'; m.docks = true;
    } else if (p.type === 'birthday') {
      var ppl = p.people || [];
      if (ppl.length > 1) {
        m.kind = 'group'; m.cls = 'group'; m.group = ppl; m.kicker = 'Birthdays · today';
        m.cta = { label: 'Wish them all', href: '/community/' }; m.second = 'Not now';
      } else {
        var one = ppl[0] || { name: 'our team' };
        m.kind = 'mate'; m.cls = 'mate'; m.person = one; m.ini = initials(one.name);
        m.kicker = 'Birthday · ' + (one.role || 'today');
        m.cta = one.id ? { label: 'Send ' + first(one.name) + ' a wish', wish: true } : { label: 'Send ' + first(one.name) + ' a wish', href: '/community/?wish=' + encodeURIComponent(first(one.name)) };
        m.second = 'Not now';
      }
    } else {
      m.kind = 'holiday'; m.cls = m.art || 'custom'; m.docks = true;
      m.kicker = 'Today · ' + (MOVABLE[p.key] && window.AvCelArt.META[p.key] ? window.AvCelArt.META[p.key][0] : dayMonth(c.date, true));
      m.cta = signedIn ? { label: 'Continue to your portal', href: member ? '' : '/portal/' } : { label: 'Close' };
    }
    return m;
  }

  /* ── 3a scene SVG with the 3b logo in it ─────────────────────── */
  function doodleSvg(k, o, logo) {
    var A = window.AvCelArt, sc = A.scene(k, o);
    var word = logo === false ? '' :
      '<image class="avcel-dseal" href="' + SEAL + '" x="8" y="36" width="56" height="56"/>' +
      '<g class="avcel-dacc" transform="translate(8 36) scale(1.4)"><g class="avcel-dacc-in">' + A.accInner(k, o) + '</g></g>' +
      '<text class="avcel-w1" x="74" y="78" style="--i:0">Afr</text>' +
      '<g class="avcel-wgpos" transform="translate(127 57) scale(.98)"><g class="avcel-wg" style="--i:1">' + A.glyphInner(k, o) + '</g></g>' +
      '<text class="avcel-w2" x="151" y="78" style="--i:2">vanguard</text>' +
      '<circle class="avcel-rip" cx="139" cy="69" r="10"/><circle class="avcel-rip avcel-rip--2" cx="139" cy="69" r="10"/>';
    return '<svg class="avcel-doodle" viewBox="0 0 400 124" aria-hidden="true" focusable="false"><g class="avcel-back">' + sc.b + '</g>' + word + '<g class="avcel-front">' + sc.f + '</g></svg>';
  }
  /* Place the glyph and “vanguard” after the measured “Afr”, as the 3b logo spaces them. */
  function layoutWord(svg) {
    var w1 = svg.querySelector('.avcel-w1'); if (!w1) return null;
    var len = 52; try { len = w1.getComputedTextLength() || len; } catch (e) { /* not rendered */ }
    var gw = 42 * 0.56, gx = 74 + len + 42 * 0.02, gy = 78 + 42 * 0.06 - gw;
    svg.querySelector('.avcel-wgpos').setAttribute('transform', 'translate(' + gx.toFixed(1) + ' ' + gy.toFixed(1) + ') scale(' + (gw / 24).toFixed(3) + ')');
    svg.querySelector('.avcel-w2').setAttribute('x', (gx + gw + 42 * 0.01).toFixed(1));
    [].forEach.call(svg.querySelectorAll('.avcel-rip'), function (r) { r.setAttribute('cx', (gx + gw / 2).toFixed(1)); r.setAttribute('cy', (gy + gw / 2).toFixed(1)); });
    /* Fold distances: each piece travels 40% of the way back to the seal. */
    [['.avcel-w1', 74 + len / 2], ['.avcel-wg', gx + gw / 2], ['.avcel-w2', gx + gw + 80]].forEach(function (a) { var el = svg.querySelector(a[0]); if (el) el.style.setProperty('--fx', (-(a[1] - 36) * 0.4).toFixed(1) + 'px'); });
    return { gx: gx, gy: gy, gw: gw };
  }
  function sceneEnd(svg) {
    var end = 0;
    [].forEach.call(svg.querySelectorAll('[data-t]'), function (el) { end = Math.max(end, parseFloat(el.getAttribute('data-t')) || 0); });
    return end;
  }

  /* ── 5a · the dialog ──────────────────────────────────────────── */
  var cur = null, pill = null, payload = null;

  function cardHtml(m) {
    var badge, after = '', i = 0;
    if (m.kind === 'mine' || m.kind === 'mate') {
      var photo = m.person && safeUrl(m.person.photo);
      badge = '<span class="avcel-ini">' + esc(m.ini) + (photo ? '<img src="' + esc(photo) + '" alt="" onerror="this.remove()">' : '') + '</span><img class="avcel-sealov" src="' + SEAL + '" alt="">';
    } else {
      badge = '<img src="' + SEAL + '" alt="">';
    }
    if (m.age) after += '<span class="avcel-age avcel-after" aria-hidden="true" style="--i:' + (i++) + '">' + esc(m.age) + '</span>';
    var grp = '';
    if (m.group) {
      var shown = m.group.slice(0, 5);
      grp = '<ul class="avcel-group avcel-after" aria-hidden="true" style="--i:' + (i++) + '">' + shown.map(function (g) {
        var ph = safeUrl(g.photo);
        return '<li>' + (ph ? '<img src="' + esc(ph) + '" alt="" onerror="this.parentNode.textContent=\'' + esc(initials(g.name)) + '\'">' : esc(initials(g.name))) + '</li>';
      }).join('') + (m.group.length > 5 ? '<li>+' + (m.group.length - 5) + '</li>' : '') + '</ul>';
    }
    var cta = m.cta.href
      ? '<a class="avcel-cta" href="' + esc(m.cta.href) + '">' + esc(m.cta.label) + '</a>'
      : '<button type="button" class="avcel-cta"' + (m.cta.wish ? ' aria-expanded="false" aria-controls="avcel-wish"' : ' data-avcel-close') + '>' + esc(m.cta.label) + '</button>';
    var second = m.second ? '<button type="button" class="avcel-second" data-avcel-close>' + esc(m.second) + '</button>' : '';
    var wish = '';
    if (m.cta.wish && m.person) {
      var f = first(m.person.name);
      wish = '<form class="avcel-wish" id="avcel-wish" hidden novalidate data-id="' + esc(m.person.id) + '">' +
        '<label class="av-sr" for="avcel-from">Your name</label><input id="avcel-from" type="text" maxlength="60" autocomplete="name" placeholder="Your name">' +
        '<label class="av-sr" for="avcel-note">Your note to ' + esc(f) + '</label><textarea id="avcel-note" maxlength="1000" placeholder="Write a birthday note to ' + esc(f) + '…"></textarea>' +
        '<input class="avcel-hp" type="text" tabindex="-1" autocomplete="off" aria-hidden="true">' +
        '<button type="submit" class="avcel-send">Send privately</button>' +
        '<p class="avcel-status" role="status" aria-live="polite"></p>' +
        '<p class="avcel-more">Or <a href="/community/?wish=' + encodeURIComponent(f) + '">post in the community</a> · <a href="/member?id=' + encodeURIComponent(m.person.id) + '">View profile</a></p>' +
        '</form>';
    }
    var stage = m.img ? '<img class="avcel-dimg" src="' + esc(m.img) + '" alt="">' : doodleSvg(m.art || 'newyear', m.opts);
    return '<div class="avcel-dialog" role="dialog" aria-modal="true" aria-labelledby="avcel-title" aria-describedby="avcel-msg" tabindex="-1">' +
      '<div class="avcel-card avcel-k--' + esc(m.cls) + '">' +
        '<div class="avcel-art">' +
          '<div class="avcel-dots" aria-hidden="true"></div>' + after +
          '<div class="avcel-cream" aria-hidden="true"></div>' +
          '<div class="avcel-stage" aria-hidden="true">' + stage + '</div>' +
          '<span class="avcel-badge" aria-hidden="true">' + badge + '</span>' + grp +
        '</div>' +
        '<div class="avcel-body">' +
          '<p class="avcel-kicker">' + esc(m.kicker) + '</p>' +
          '<h2 class="avcel-title" id="avcel-title">' + esc(m.title) + '</h2>' +
          '<p class="avcel-msg" id="avcel-msg">' + esc(m.msg) + '</p>' +
          (m.perk ? '<p class="avcel-perk">' + esc(m.perk) + '</p>' : '') +
          '<div class="avcel-actions">' + cta + second + '</div>' + wish +
        '</div>' +
        '<button type="button" class="avcel-x avcel-x--desk" aria-label="Close" data-avcel-close>' + icon(X, 14, 2.4) + '</button>' +
      '</div>' +
      '<button type="button" class="avcel-x avcel-x--phone" aria-label="Close" data-avcel-close>' + icon(X, 18, 2.2) + '</button>' +
    '</div>';
  }

  function customVars(el, p) {
    var h = hex(p && p.theme); if (!h) return;
    el.style.setProperty('--avcel-c1', h);
    el.style.setProperty('--avcel-ring', 'conic-gradient(' + h + ',var(--av-gold-light),' + h + ')');
    el.style.setProperty('--avcel-stripe', 'linear-gradient(90deg,' + h + ',var(--av-gold-light),' + h + ')');
    el.style.setProperty('--avcel-kfg', 'var(--av-gold-text)');
    el.style.setProperty('--avcel-a', 'color-mix(in srgb,' + h + ' 45%,var(--av-ink))');
    el.style.setProperty('--avcel-b', 'color-mix(in srgb,' + h + ' 20%,var(--av-ink-deep))');
  }

  function focusables(scope) {
    return [].filter.call(scope.querySelectorAll('a[href],button:not([disabled]),input:not([tabindex="-1"]),textarea,select'), function (el) { return el.getClientRects().length > 0; });
  }

  function open(m, finished) {
    if (cur) return;
    if (pill) { pill.remove(); pill = null; }
    var last = D.activeElement;
    var layer = D.createElement('div');
    layer.className = 'avcel-layer';
    layer.innerHTML = cardHtml(m);
    if (m.cls === 'custom') customVars(layer.querySelector('.avcel-card'), m.p);
    var others = [].filter.call(D.body.children, function (el) { return el !== layer && !el.hasAttribute('inert') && el.tagName !== 'SCRIPT'; });
    D.body.appendChild(layer);
    others.forEach(function (el) { el.setAttribute('inert', ''); });
    var prevOverflow = root.style.overflow; root.style.overflow = 'hidden';
    var dialog = layer.querySelector('.avcel-dialog'), art = layer.querySelector('.avcel-art');
    var timers = [], anims = [];
    cur = { m: m, layer: layer, timers: timers, anims: anims };

    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopImmediatePropagation(); close(); return; }
      if (e.key !== 'Tab') return;
      var f = focusables(dialog); if (!f.length) { e.preventDefault(); return; }
      var a = f[0], z = f[f.length - 1];
      if (e.shiftKey && (D.activeElement === a || D.activeElement === dialog)) { e.preventDefault(); z.focus(); }
      else if (!e.shiftKey && D.activeElement === z) { e.preventDefault(); a.focus(); }
    }
    function close() {
      if (!cur) return;
      timers.forEach(clearTimeout); anims.forEach(function (x) { try { x.cancel(); } catch (e) { /* gone */ } });
      D.removeEventListener('keydown', onKey, true);
      others.forEach(function (el) { el.removeAttribute('inert'); });
      root.style.overflow = prevOverflow;
      cur = null;
      var gone = function () { if (layer.parentNode) layer.parentNode.removeChild(layer); };
      if (reduced()) gone(); else { layer.classList.add('is-leaving'); setTimeout(gone, 200); }
      if (m.docks) dock(m, true);
      showPill(m);
      var back = last && last !== D.body && D.contains(last) ? last : pill;
      try { if (back && back.focus) back.focus({ preventScroll: true }); } catch (e) { /* detached */ }
    }
    cur.close = close;
    D.addEventListener('keydown', onKey, true);
    layer.addEventListener('click', function (e) {
      if (e.target === layer) { close(); return; }
      if (e.target.closest('[data-avcel-close]')) close();
      else if (e.target.closest('a.avcel-cta')) { store.set('av.celebrate.seen', payload && payload.tag || ''); }
    });
    wireWish(layer, m);
    try { dialog.focus({ preventScroll: true }); } catch (e) { dialog.focus(); }

    if (finished || reduced()) { art.classList.add('is-final'); return; }
    intro(art, m, timers, anims);
  }

  /* Acts 1–3: today’s logo → the scene plays → the letters fold into the
     seal, the colour spreads from it and the seal lands as the badge. */
  function intro(art, m, timers, anims) {
    var stage = art.querySelector('.avcel-stage'), svg = stage.querySelector('svg'), badge = art.querySelector('.avcel-badge');
    art.classList.add('is-intro');
    var go = function () {
      if (svg) { layoutWord(svg); stage.firstChild.classList.add('is-play'); }
      var end = svg ? 0.9 + sceneEnd(svg) : 1.6;
      T(exit, (end + 0.9) * 1000, timers);
    };
    whenFonts(go);
    function exit() {
      art.classList.add('is-exit');
      T(function () {
        var ar = art.getBoundingClientRect(), br = badge.getBoundingClientRect();
        var seal = svg && svg.querySelector('.avcel-dseal'), sr = seal ? seal.getBoundingClientRect() : null;
        if (!sr || !sr.width) sr = { left: ar.left + ar.width / 2 - 10, top: ar.top + ar.height / 2 - 10, width: 20, height: 20 };
        var cx = sr.left + sr.width / 2, cy = sr.top + sr.height / 2;
        var dx = cx - (br.left + br.width / 2), dy = cy - (br.top + br.height / 2), s = sr.width / (br.width * 0.88);
        if (seal) seal.style.visibility = 'hidden';
        badge.style.opacity = '1';
        if (badge.animate) {
          anims.push(badge.animate([
            { transform: 'translate(' + dx.toFixed(1) + 'px,' + dy.toFixed(1) + 'px) scale(' + s.toFixed(3) + ') rotate(-50deg)' },
            { transform: 'translate(' + (dx * 0.42).toFixed(1) + 'px,' + (dy * 0.42 - 30).toFixed(1) + 'px) scale(' + ((s + 1) / 2 * 1.1).toFixed(3) + ') rotate(-14deg)', offset: 0.5 },
            { transform: 'translate(0,0) scale(1.07) rotate(2deg)', offset: 0.82 },
            { transform: 'none' }
          ], { duration: 1050, easing: 'cubic-bezier(.55,0,.2,1)' }));
          var ov = badge.querySelector('.avcel-sealov');
          if (ov) { ov.style.opacity = '1'; anims.push(ov.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 420, delay: 540, fill: 'forwards' })); }
          var wash = D.createElement('div'); wash.className = 'avcel-wash'; wash.setAttribute('aria-hidden', 'true');
          art.querySelector('.avcel-cream').after(wash);
          var x = cx - ar.left, y = cy - ar.top, R = Math.hypot(ar.width, ar.height) + 20;
          anims.push(wash.animate([{ clipPath: 'circle(0px at ' + x.toFixed(0) + 'px ' + y.toFixed(0) + 'px)' }, { clipPath: 'circle(' + R.toFixed(0) + 'px at ' + x.toFixed(0) + 'px ' + y.toFixed(0) + 'px)' }], { duration: 950, delay: 60, easing: 'cubic-bezier(.7,0,.2,1)', fill: 'forwards' }));
        }
        art.classList.add('is-after');
        T(function () { art.classList.add('is-washed'); }, 1060, timers);
        T(function () { badge.classList.add('is-landed'); }, 880, timers);
        T(function () {
          art.classList.remove('is-intro', 'is-exit', 'is-after', 'is-washed');
          art.classList.add('is-final'); badge.style.opacity = '';
          var w = art.querySelector('.avcel-wash'); if (w) w.remove();
        }, 1700, timers);
      }, 300, timers);
    }
  }

  /* Private birthday note → process-wish.php (unchanged contract). */
  function wireWish(layer, m) {
    var btn = layer.querySelector('.avcel-cta[aria-controls]'), form = layer.querySelector('.avcel-wish');
    if (!btn || !form) return;
    btn.addEventListener('click', function () {
      var show = form.hidden; form.hidden = !show; btn.setAttribute('aria-expanded', String(show));
      if (!show) return;
      var ta = form.querySelector('textarea');
      if (!ta.value) ta.value = 'Happy birthday, ' + first(m.person.name) + '. ';
      try { ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); } catch (e) { /* fine */ }
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var st = form.querySelector('.avcel-status'), send = form.querySelector('.avcel-send');
      var say = function (t, k) { st.textContent = t; st.className = 'avcel-status' + (k ? ' is-' + k : ''); };
      var text = form.querySelector('textarea').value.trim();
      if (text.length < 2) { say('Write a short note first.', 'err'); form.querySelector('textarea').focus(); return; }
      send.disabled = true; say('Sending…');
      var ctl = window.AbortController ? new AbortController() : null, to = setTimeout(function () { if (ctl) ctl.abort(); }, 10000);
      fetch('/process-wish.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, signal: ctl ? ctl.signal : undefined,
        body: JSON.stringify({ id: form.getAttribute('data-id'), from: form.querySelector('#avcel-from').value.trim(), message: text, company: form.querySelector('.avcel-hp').value.trim() })
      }).then(function (r) { return r.json(); }).then(function (d) {
        clearTimeout(to);
        if (d && d.ok) { say('Your note is on its way. Thank you.', 'ok'); form.querySelector('textarea').value = ''; }
        else { send.disabled = false; say((d && d.error) || 'Could not send just now. Try again in a moment.', 'err'); }
      }).catch(function () { clearTimeout(to); send.disabled = false; say('Could not reach us. Check your connection and try again.', 'err'); });
    });
  }

  function showPill(m) {
    if (pill) pill.remove();
    pill = D.createElement('button');
    pill.type = 'button'; pill.className = 'avcel-pill'; pill.textContent = 'Show the pop-up again';
    pill.addEventListener('click', function () { open(m, true); });
    D.body.appendChild(pill);
    var p = pill; setTimeout(function () { if (pill === p && D.activeElement !== p) { p.remove(); pill = null; } }, 12000);
  }

  /* ── 3b · the nav logo for the day ────────────────────────────── */
  function brand() { return D.querySelector('.avh-nav .avh-brand') || D.querySelector('.avh-brand') || D.querySelector('.nav-logo'); }
  function dock(m, play) {
    if (m.img) return decorateImg(m.img);
    decorate(m.art || 'newyear', m.opts, play && !reduced(), m.cls === 'custom' ? m.p : null);
  }
  function decorateImg(src) {
    var b = brand(); if (!b || b.getAttribute('data-avcel') === 'img') return;
    var word = b.querySelector('.avh-brand > span, .brand-wordmark'); if (!word) return;
    b.setAttribute('data-avcel', 'img');
    word.innerHTML = '<img class="avcel-navimg" src="' + esc(src) + '" alt=""><span class="av-sr">Afrovanguard</span>';
  }
  function decorate(k, o, play, customP) {
    var A = window.AvCelArt, b = brand(); if (!A || !b) return;
    if (b.getAttribute('data-avcel') !== k) {
      var word = b.querySelector(':scope > span') || b.querySelector('.brand-wordmark .wm-1');
      if (!word) return;
      b.setAttribute('data-avcel', k);
      b.classList.add('avcel-brand', 'avcel-k--' + (A.has(k) ? k : 'custom'));
      if (customP) customVars(b, customP);
      if (word.classList.contains('wm-1')) word.innerHTML = '<span aria-hidden="true">Afr' + A.glyph(k, o) + '</span><span class="av-sr">Afro</span>';
      else word.innerHTML = '<span aria-hidden="true">Afr' + A.glyph(k, o) + 'vanguard</span><span class="av-sr">Afrovanguard</span>';
      var seal = b.querySelector('img:not(.avcel-navimg)');
      if (seal && A.accInner(k, o)) {
        b.insertAdjacentHTML('afterbegin', A.acc(k, o));
        var sizeAcc = function () { var w = seal.offsetWidth; if (w) { b.style.setProperty('--avcel-seal', w + 'px'); b.style.setProperty('--avcel-seal-x', seal.offsetLeft + 'px'); } };
        sizeAcc(); if (window.ResizeObserver) new ResizeObserver(sizeAcc).observe(seal);
      }
    }
    if (play) navPlay(b, k, o);
  }
  /* The doodle plays around the nav logo, then settles into the 3b logo. */
  function navPlay(b, k, o) {
    var A = window.AvCelArt, seal = b.querySelector('img:not(.avcel-navimg)');
    b.classList.remove('avcel-brand--pop'); void b.offsetWidth; b.classList.add('avcel-brand--pop');
    if (!seal || !seal.offsetWidth) return;
    var br = b.getBoundingClientRect(), sr = seal.getBoundingClientRect(), s = sr.width / 56;
    var left = sr.left - br.left - 8 * s, top = sr.top - br.top - 36 * s;
    var room = Math.max(0, D.documentElement.clientWidth - (br.left + left) - 8);
    var sc = A.scene(k, o), made = [];
    [['back', sc.b], ['front', sc.f]].forEach(function (pair) {
      if (!pair[1]) return;
      var w = D.createElement('span');
      w.className = 'avcel-navplay avcel-navplay--' + pair[0]; w.setAttribute('aria-hidden', 'true');
      w.style.cssText = 'left:' + left.toFixed(1) + 'px;top:' + top.toFixed(1) + 'px;width:' + Math.min(400 * s, room).toFixed(1) + 'px;height:' + (124 * s).toFixed(1) + 'px';
      w.innerHTML = '<svg class="is-play" viewBox="0 0 400 124" width="' + (400 * s).toFixed(1) + '" height="' + (124 * s).toFixed(1) + '" focusable="false">' + pair[1] + '</svg>';
      b.appendChild(w); made.push(w);
    });
    var end = 0; made.forEach(function (w) { end = Math.max(end, sceneEnd(w)); });
    setTimeout(function () { made.forEach(function (w) { w.classList.add('is-gone'); }); }, (0.15 + end + 1.2) * 1000);
    setTimeout(function () { made.forEach(function (w) { w.remove(); }); }, (0.15 + end + 1.8) * 1000);
  }

  /* ── 2b · quiet themes for member pages ───────────────────────── */
  function applyTheme(th, playNav) {
    var A = window.AvCelArt, k = A.has(th.key) ? th.key : 'custom';
    root.classList.add('avcel-themed', 'avcel-k--' + k);
    root.setAttribute('data-avcel-theme', th.key);
    if (k === 'custom') customVars(root, th);
    if (!D.querySelector('.avcel-stripe')) { var s = D.createElement('div'); s.className = 'avcel-stripe'; s.setAttribute('aria-hidden', 'true'); D.body.insertBefore(s, D.body.firstChild); }
    var dkey = th.date + ':' + th.key;
    if (store.get('av.celebrate.dismissed') !== dkey && !D.querySelector('.avcel-ban')) {
      var ban = D.createElement('div');
      ban.className = 'avcel-ban';
      ban.innerHTML = A.icon(th.key) + '<span class="avcel-ban-bar" aria-hidden="true"></span><p class="avcel-ban-txt" style="margin:0"><strong>' + esc(th.title) + '.</strong> <span>' + esc(th.message) + '</span></p><button type="button" class="avcel-ban-x">Dismiss</button>';
      var nav = D.querySelector('.avh-nav') || D.querySelector('header') || D.querySelector('nav');
      if (nav && nav.parentNode) nav.parentNode.insertBefore(ban, nav.nextSibling);
      else { var main = D.querySelector('main'); (main || D.body).insertBefore(ban, (main || D.body).firstChild); }
      ban.querySelector('.avcel-ban-x').addEventListener('click', function () {
        store.set('av.celebrate.dismissed', dkey);
        var next = D.querySelector('main') || D.body;
        ban.remove();
        try { (next.hasAttribute('tabindex') ? next : D.body).focus({ preventScroll: true }); } catch (e) { /* fine */ }
      });
    }
    var uploaded = uploadedArt(th);
    if (uploaded) decorateImg(uploaded);
    else decorate(A.has(th.key) ? th.key : 'newyear', artOpts(th.date), playNav && !reduced(), k === 'custom' ? th : null);
    try { D.dispatchEvent(new CustomEvent('avcel:theme', { detail: th })); } catch (e) { /* old browser */ }
  }

  /* ── wait for a quiet moment: page loaded, no video playing ──── */
  function whenFree(cb) {
    var playing = function () { return [].some.call(D.querySelectorAll('video'), function (v) { return !v.paused && !v.ended; }); };
    var tryNow = function () {
      if (!playing()) return cb();
      var again = function () { D.removeEventListener('pause', again, true); D.removeEventListener('ended', again, true); setTimeout(tryNow, 400); };
      D.addEventListener('pause', again, true); D.addEventListener('ended', again, true);
    };
    var start = function () { setTimeout(tryNow, 600); };
    if (D.readyState === 'complete') start(); else window.addEventListener('load', start, { once: true });
  }

  function start(d) {
    var c = d.celebration, th = d.theme, member = isMember(), signedIn = !!(c && c.viewer && c.viewer.signed_in);

    var m = c && c.primary ? model(c, signedIn, member) : null;
    var tag = m ? c.date + ':' + (m.p.key || '') + (m.p.mine ? ':mine' : '') : '';
    payload = { tag: tag };
    var willOpen = m && !NO_POPUP && !optedOut() && store.get('av.celebrate.seen') !== tag;
    if (member && th && th.key) {
      var navTag = th.date + ':' + th.key, firstToday = store.get('avcel.nav') !== navTag;
      if (firstToday && !willOpen) store.set('avcel.nav', navTag);
      applyTheme(th, firstToday && !willOpen);
    }
    if (m && m.docks && !willOpen) dock(m, false);
    window.AvCel.open = function () { if (m) open(m, true); };
    if (!willOpen) return;
    whenFree(function () {
      if (store.get('av.celebrate.seen') === tag) return;
      store.set('av.celebrate.seen', tag);
      open(m, false);
    });
  }

  window.AvCel = {
    data: null,
    open: function () {},
    /* 2a: the seal with a ring of the occasion’s colours and a small-caps caption. */
    ringLogo: function (k, caption) {
      return '<span class="avcel-ring avcel-k--' + esc(k) + '" role="img" aria-label="Afrovanguard, ' + esc(caption || '') + '"><span class="avcel-ring-seal"><img src="' + SEAL + '" alt=""></span><span class="avcel-ring-word" aria-hidden="true"><span>Afrovanguard</span><span class="avcel-ring-cap">' + esc(caption || '') + '</span></span></span>';
    }
  };

  if (!window.fetch) return;
  fetch('/api.php?action=celebrations', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) {
      if (!d || (!d.celebration && !(d.theme && isMember()))) return;
      window.AvCel.data = d;
      var css = ensureCss();
      var go = function () { loadArt(function () { start(d); }); };
      if (css && !css.sheet) { css.addEventListener('load', go, { once: true }); css.addEventListener('error', go, { once: true }); } else go();
    })
    .catch(function () { /* a celebration is never worth an error */ });
})();
