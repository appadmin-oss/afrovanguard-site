/* ============================================================
   assets/site/celebrations.js — the site that celebrates itself.

   Fetches today's celebrations (holidays / birthdays / observances from
   api.php?action=celebrations) and honours them intentionally:

     • BIRTHDAYS → a dignified, focus-trapped POPUP that honours the person
       (photo, name, role, a warm message, and a way to celebrate them).
       Shown once per day, never nagging.
     • Holidays / observances → a refined festive banner + logo doodle.
     • A gentle confetti flourish (respecting reduced-motion).

   Design tokens (--afg-*) are used with literal fallbacks so it renders
   correctly on both token-aware pages and plain static pages.
   ============================================================ */
(function () {
  'use strict';
  var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var GOLD = '#f3b416';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* ── Styles (injected once) ─────────────────────────────────── */
  function injectStyles(theme) {
    if (document.getElementById('av-celebrate-css')) return;
    var s = document.createElement('style'); s.id = 'av-celebrate-css';
    s.textContent = [
      /* Shared */
      ':root{--avc-accent:' + theme + '}',
      /* Refined holiday banner */
      '.av-celebrate{position:relative;z-index:210;overflow:hidden;color:#fff;',
      'background:linear-gradient(100deg,var(--avc-accent),color-mix(in srgb,var(--avc-accent) 55%,#0b0f1a));',
      'box-shadow:0 2px 18px rgba(0,0,0,.18)}',
      '.av-celebrate .avc-inner{max-width:1200px;margin:0 auto;display:flex;align-items:center;gap:14px;padding:11px 20px;',
      "font-family:var(--afg-font-body,'Montserrat',system-ui,sans-serif)}",
      '.av-celebrate .avc-emoji{font-size:24px;line-height:1;animation:avcPop .6s cubic-bezier(.22,1,.36,1) both}',
      '.av-celebrate .avc-txt{display:flex;flex-direction:column;gap:1px;min-width:0;flex:1}',
      '.av-celebrate .avc-txt strong{font-size:14.5px;font-weight:800;letter-spacing:.01em}',
      '.av-celebrate .avc-txt span{font-size:12.5px;opacity:.92;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
      '.av-celebrate .avc-x{margin-left:auto;background:rgba(255,255,255,.16);border:0;color:#fff;width:30px;height:30px;',
      'border-radius:50%;cursor:pointer;font-size:18px;line-height:1;flex-shrink:0}',
      '.av-celebrate .avc-x:hover{background:rgba(255,255,255,.3)}',
      '.av-celebrate .avc-shine{position:absolute;inset:0;background:linear-gradient(105deg,transparent 30%,rgba(255,255,255,.22) 50%,transparent 70%);',
      'transform:translateX(-100%);animation:avcShine 3.4s ease-in-out infinite}',
      /* Birthday popup */
      '.avc-modal{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;',
      'background:rgba(9,12,20,.62);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);opacity:0;transition:opacity .28s ease}',
      '.avc-modal.is-open{opacity:1}',
      '.avc-card{position:relative;width:min(440px,100%);background:var(--afg-surface,#fff);color:var(--afg-ink,#111827);',
      'border-radius:var(--afg-radius-lg,22px);box-shadow:0 40px 90px -30px rgba(0,0,0,.6);padding:40px 32px 32px;text-align:center;',
      "font-family:var(--afg-font-body,'Montserrat',system-ui,sans-serif);transform:translateY(14px) scale(.98);opacity:0;",
      'transition:transform .34s cubic-bezier(.22,.61,.36,1),opacity .34s ease;overflow:hidden}',
      '.avc-modal.is-open .avc-card{transform:none;opacity:1}',
      '.avc-card::before{content:"";position:absolute;left:0;right:0;top:0;height:5px;',
      'background:linear-gradient(90deg,var(--avc-accent),color-mix(in srgb,var(--avc-accent) 40%,#fff))}',
      '.avc-seal{font-size:40px;line-height:1;display:inline-block;animation:avcPop .6s cubic-bezier(.22,1,.36,1) both}',
      '.avc-eyebrow{margin:12px 0 4px;font-size:11px;font-weight:800;letter-spacing:.16em;text-transform:uppercase;',
      'color:var(--afg-accent-ink,#b07e08)}',
      '.avc-avatars{display:flex;justify-content:center;margin:14px 0 6px}',
      '.avc-av{width:96px;height:96px;border-radius:50%;background:var(--afg-surface-2,#f4f2ec) center/cover no-repeat;',
      'border:3px solid var(--avc-accent);box-shadow:0 10px 30px -12px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;',
      "font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-weight:700;font-size:38px;color:var(--avc-accent);margin-left:-18px}",
      '.avc-av:first-child{margin-left:0}',
      '.avc-avatars.multi .avc-av{width:72px;height:72px;font-size:28px}',
      '.avc-name{margin:6px 0 2px;font-family:var(--afg-font-display,\'Cormorant\',Georgia,serif);font-weight:700;',
      'font-size:30px;line-height:1.15;color:var(--afg-ink,#111827)}',
      '.avc-role{font-size:13px;color:var(--afg-muted,#6b7280);margin:0 0 12px}',
      '.avc-message{font-size:15px;line-height:1.6;color:var(--afg-body,#374151);margin:0 auto 22px;max-width:34ch}',
      '.avc-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}',
      '.avc-btn{display:inline-flex;align-items:center;gap:6px;padding:11px 18px;border-radius:var(--afg-radius-pill,999px);',
      'font-weight:700;font-size:14px;text-decoration:none;cursor:pointer;border:1px solid transparent}',
      '.avc-btn-primary{background:var(--avc-accent);color:#111827}',
      '.avc-btn-primary:hover{filter:brightness(.96)}',
      '.avc-btn-ghost{background:transparent;color:var(--afg-ink,#111827);border-color:var(--afg-border,#e5e7eb)}',
      '.avc-btn-ghost:hover{background:var(--afg-surface-2,#f4f2ec)}',
      '.avc-close{position:absolute;top:12px;right:12px;width:34px;height:34px;border-radius:50%;border:0;cursor:pointer;',
      'background:var(--afg-surface-2,#f1f1ee);color:var(--afg-muted,#6b7280);font-size:20px;line-height:1}',
      '.avc-close:hover{background:var(--afg-border,#e5e7eb);color:var(--afg-ink,#111827)}',
      '.avc-btn:focus-visible,.avc-close:focus-visible{outline:2px solid var(--afg-focus,#1d4ed8);outline-offset:2px}',
      /* doodle */
      '.brand-wordmark.av-doodled,.nav-logo-mark.av-doodled{position:relative}',
      '.brand-wordmark.av-doodled::after,.nav-logo-mark.av-doodled::after{content:attr(data-emoji);position:absolute;top:-12px;right:-18px;font-size:15px;animation:avcBob 2.2s ease-in-out infinite}',
      '.brand-wordmark.av-doodled .wm-2,.nav-logo-mark.av-doodled .van{background:linear-gradient(90deg,var(--avc-accent),#fff);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}',
      '.nav-logo-doodle-img{height:30px;width:auto;vertical-align:middle;display:block}',
      /* keyframes */
      '@keyframes avcPop{0%{transform:scale(0) rotate(-25deg)}100%{transform:scale(1) rotate(0)}}',
      '@keyframes avcShine{0%,100%{transform:translateX(-120%)}55%{transform:translateX(120%)}}',
      '@keyframes avcBob{0%,100%{transform:translateY(0) rotate(-8deg)}50%{transform:translateY(-3px) rotate(8deg)}}',
      '@media(prefers-reduced-motion:reduce){.av-celebrate .avc-shine,.nav-logo-mark.av-doodled::after,.avc-seal,.avc-emoji{animation:none}',
      '.avc-modal,.avc-card{transition:none}}'
    ].join('');
    document.head.appendChild(s);
  }

  /* ── Birthday: an honourable popup ──────────────────────────── */
  function avatar(p) {
    var ini = (p && p.name ? p.name : '?').trim().charAt(0).toUpperCase();
    return p && p.photo
      ? '<span class="avc-av" style="background-image:url(\'' + String(p.photo).replace(/['"\\]/g, '') + '\')" aria-hidden="true"></span>'
      : '<span class="avc-av" aria-hidden="true">' + esc(ini) + '</span>';
  }

  function joinNames(list) {
    var names = list.map(function (p) { return p.name; });
    if (names.length === 1) return esc(names[0]);
    if (names.length === 2) return esc(names[0]) + ' &amp; ' + esc(names[1]);
    return names.slice(0, -1).map(esc).join(', ') + ' &amp; ' + esc(names[names.length - 1]);
  }

  function showBirthday(c) {
    var p = c.primary, theme = p.theme || GOLD;
    var people = (p.people && p.people.length) ? p.people : [{ name: 'our team' }];
    var single = people.length === 1 ? people[0] : null;
    injectStyles(theme);

    var lastFocus = document.activeElement;
    var modal = document.createElement('div');
    modal.className = 'avc-modal';
    modal.style.setProperty('--avc-accent', theme);
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'avcName');

    var actions = '';
    if (single && single.id) {
      actions += '<a class="avc-btn avc-btn-ghost" href="/member?id=' + encodeURIComponent(single.id) + '">View profile</a>';
    }
    actions += '<a class="avc-btn avc-btn-primary" href="/community/">Send warm wishes</a>';

    modal.innerHTML =
      '<div class="avc-card" role="document">'
      + '<button class="avc-close" type="button" aria-label="Close">&times;</button>'
      + '<span class="avc-seal" aria-hidden="true">' + esc(p.emoji || '🎂') + '</span>'
      + '<p class="avc-eyebrow">Today the movement celebrates</p>'
      + '<div class="avc-avatars' + (people.length > 1 ? ' multi' : '') + '">'
      + people.slice(0, 4).map(avatar).join('') + '</div>'
      + '<h2 class="avc-name" id="avcName">' + joinNames(people) + '</h2>'
      + (single && single.role ? '<p class="avc-role">' + esc(single.role) + '</p>' : '')
      + '<p class="avc-message">' + esc(p.message || 'Wishing you a wonderful birthday from the whole Afrovanguard family.') + '</p>'
      + '<div class="avc-actions">' + actions + '</div>'
      + '</div>';

    document.body.appendChild(modal);
    document.documentElement.style.overflow = 'hidden';
    requestAnimationFrame(function () { modal.classList.add('is-open'); });
    if (!REDUCED) setTimeout(function () { confetti(theme); }, 240);

    function close() {
      modal.classList.remove('is-open');
      document.documentElement.style.overflow = '';
      document.removeEventListener('keydown', onKey);
      setTimeout(function () { modal.remove(); }, 300);
      try { if (lastFocus && lastFocus.focus) lastFocus.focus(); } catch (e) {}
    }
    function onKey(e) {
      if (e.key === 'Escape') { close(); return; }
      if (e.key === 'Tab') { // focus trap
        var f = modal.querySelectorAll('a[href],button');
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    }
    modal.querySelector('.avc-close').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', onKey);
    var focusBtn = modal.querySelector('.avc-close'); if (focusBtn) focusBtn.focus();
  }

  /* ── Holidays / observances: a refined banner ───────────────── */
  function showBanner(c) {
    var p = c.primary, theme = p.theme || GOLD;
    injectStyles(theme);
    var bar = document.createElement('div');
    bar.className = 'av-celebrate'; bar.setAttribute('data-key', p.key || '');
    bar.setAttribute('role', 'status');
    bar.style.setProperty('--avc-accent', theme);
    bar.innerHTML = '<div class="avc-shine" aria-hidden="true"></div><div class="avc-inner">'
      + '<span class="avc-emoji" aria-hidden="true">' + esc(p.emoji || '🎉') + '</span>'
      + '<div class="avc-txt"><strong>' + esc(p.title || 'Celebrating today') + '</strong>'
      + '<span>' + esc(p.message || '') + '</span></div>'
      + '<button class="avc-x" type="button" aria-label="Dismiss">&times;</button></div>';
    document.body.insertBefore(bar, document.body.firstChild);
    bar.querySelector('.avc-x').addEventListener('click', function () {
      try { localStorage.setItem('av.celebrate.dismissed', c.date + ':' + (p.key || '')); } catch (e) {}
      bar.style.transition = 'transform .3s, opacity .3s';
      bar.style.transform = 'translateY(-100%)'; bar.style.opacity = '0';
      setTimeout(function () { bar.remove(); }, 320);
    });
  }

  function doodleLogo(p) {
    var mark = document.querySelector('.nav-logo .brand-wordmark') || document.querySelector('.nav-logo-mark');
    if (!mark) return;
    injectStyles(p.theme || GOLD);
    if (p.doodle) {
      mark.innerHTML = '<img class="nav-logo-doodle-img" src="' + String(p.doodle).replace(/"/g, '') + '" alt="Afrovanguard" />';
      return;
    }
    mark.classList.add('av-doodled');
    mark.setAttribute('data-emoji', p.emoji || '🎉');
    mark.style.setProperty('--avc-accent', p.theme || GOLD);
  }

  function confetti(theme) {
    if (REDUCED) return;
    var cv = document.createElement('canvas');
    cv.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:2147483001';
    document.body.appendChild(cv);
    var ctx = cv.getContext('2d'), W = cv.width = innerWidth, H = cv.height = innerHeight;
    var cols = [theme || GOLD, '#16a34a', '#ffffff', '#0ea5e9', '#ec4899'];
    var parts = [], N = Math.min(160, Math.round(W / 8));
    for (var i = 0; i < N; i++) parts.push({
      x: Math.random() * W, y: -20 - Math.random() * H * 0.5,
      r: 4 + Math.random() * 6, c: cols[i % cols.length],
      vy: 2 + Math.random() * 3.5, vx: -1.5 + Math.random() * 3,
      rot: Math.random() * 6.28, vr: -0.2 + Math.random() * 0.4
    });
    var t0 = performance.now();
    (function frame(t) {
      ctx.clearRect(0, 0, W, H);
      parts.forEach(function (p) {
        p.x += p.vx; p.y += p.vy; p.rot += p.vr;
        ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.rot); ctx.fillStyle = p.c;
        ctx.fillRect(-p.r / 2, -p.r / 2, p.r, p.r * 0.6); ctx.restore();
      });
      if (t - t0 < 2800) requestAnimationFrame(frame);
      else { cv.style.transition = 'opacity .5s'; cv.style.opacity = '0'; setTimeout(function () { cv.remove(); }, 520); }
    })(t0);
  }

  /* ── Fetch + dispatch ───────────────────────────────────────── */
  fetch('/api.php?action=celebrations', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var c = d && d.celebration; if (!c || !c.primary) return;
      var p = c.primary;
      doodleLogo(p); // the doodle always shows on a celebration day
      var tag = c.date + ':' + (p.key || '');

      if (p.type === 'birthday') {
        // Honour it with a popup — once per day, so it's a moment, not a nag.
        var bseen = '';
        try { bseen = localStorage.getItem('av.celebrate.bday') || ''; } catch (e) {}
        if (bseen === tag) return;
        try { localStorage.setItem('av.celebrate.bday', tag); } catch (e) {}
        showBirthday(c);
        return;
      }

      // Holidays / observances → banner (dismissible, remembered for the day).
      var dismissed = '';
      try { dismissed = localStorage.getItem('av.celebrate.dismissed') || ''; } catch (e) {}
      if (dismissed === tag) return;
      showBanner(c);
      var seen = '';
      try { seen = localStorage.getItem('av.celebrate.seen') || ''; } catch (e) {}
      if (seen !== tag) {
        confetti(p.theme);
        try { localStorage.setItem('av.celebrate.seen', tag); } catch (e) {}
      }
    })
    .catch(function () {});
})();
