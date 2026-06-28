/* ============================================================
   assets/site/celebrations.js — the site that celebrates itself.
   Fetches today's auto-celebrations (holidays / birthdays / VOTM data
   from api.php?action=celebrations) and, when there's something to
   celebrate, shows a festive banner, a Google-style logo doodle and a
   burst of confetti. Fully automatic; no per-day editing.
   ============================================================ */
(function () {
  'use strict';
  var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function injectStyles(theme) {
    if (document.getElementById('av-celebrate-css')) return;
    var s = document.createElement('style'); s.id = 'av-celebrate-css';
    s.textContent =
      '.av-celebrate{--cc:' + theme + ';position:relative;z-index:210;overflow:hidden;'
      + 'background:linear-gradient(100deg,var(--cc),color-mix(in srgb,var(--cc) 55%,#0b0f1a));color:#fff;'
      + 'box-shadow:0 2px 18px rgba(0,0,0,.18)}'
      + '.av-celebrate .avc-inner{max-width:1200px;margin:0 auto;display:flex;align-items:center;gap:14px;padding:11px 20px;font-family:Montserrat,system-ui,sans-serif}'
      + '.av-celebrate .avc-emoji{font-size:24px;line-height:1;animation:avcPop .6s cubic-bezier(.22,1,.36,1) both}'
      + '.av-celebrate .avc-txt{display:flex;flex-direction:column;gap:1px;min-width:0;flex:1}'
      + '.av-celebrate .avc-txt strong{font-size:14.5px;font-weight:800;letter-spacing:.01em}'
      + '.av-celebrate .avc-txt span{font-size:12.5px;opacity:.9;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
      + '.av-celebrate .avc-avatars{display:flex;margin-right:2px}'
      + '.av-celebrate .avc-av{width:34px;height:34px;border-radius:50%;border:2px solid rgba(255,255,255,.85);background:#fff center/cover;margin-left:-10px;display:flex;align-items:center;justify-content:center;color:var(--cc);font-weight:800;font-size:13px}'
      + '.av-celebrate .avc-av:first-child{margin-left:0}'
      + '.av-celebrate .avc-x{margin-left:auto;background:rgba(255,255,255,.16);border:0;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:18px;line-height:1;flex-shrink:0}'
      + '.av-celebrate .avc-x:hover{background:rgba(255,255,255,.3)}'
      + '.av-celebrate .avc-shine{position:absolute;inset:0;background:linear-gradient(105deg,transparent 30%,rgba(255,255,255,.25) 50%,transparent 70%);transform:translateX(-100%);animation:avcShine 3.4s ease-in-out infinite}'
      + '@keyframes avcPop{0%{transform:scale(0) rotate(-25deg)}100%{transform:scale(1) rotate(0)}}'
      + '@keyframes avcShine{0%,100%{transform:translateX(-120%)}55%{transform:translateX(120%)}}'
      + '.brand-wordmark.av-doodled,.nav-logo-mark.av-doodled{position:relative}'
      + '.brand-wordmark.av-doodled::after,.nav-logo-mark.av-doodled::after{content:attr(data-emoji);position:absolute;top:-12px;right:-18px;font-size:15px;animation:avcBob 2.2s ease-in-out infinite}'
      + '.brand-wordmark.av-doodled .wm-2,.nav-logo-mark.av-doodled .van{background:linear-gradient(90deg,var(--cc),#fff);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}'
      + '.nav-logo-doodle-img{height:30px;width:auto;vertical-align:middle;display:block}'
      + '@keyframes avcBob{0%,100%{transform:translateY(0) rotate(-8deg)}50%{transform:translateY(-3px) rotate(8deg)}}'
      + '@media(prefers-reduced-motion:reduce){.av-celebrate .avc-shine,.nav-logo-mark.av-doodled::after,.av-celebrate .avc-emoji{animation:none}}';
    document.head.appendChild(s);
  }

  function avatarsHtml(people) {
    if (!people || !people.length) return '';
    var h = '<div class="avc-avatars">';
    people.slice(0, 4).forEach(function (p) {
      var ini = (p.name || '?').trim().charAt(0).toUpperCase();
      h += p.photo
        ? '<span class="avc-av" style="background-image:url(\'' + String(p.photo).replace(/'/g, '') + '\')"></span>'
        : '<span class="avc-av">' + ini + '</span>';
    });
    return h + '</div>';
  }

  function showBanner(c) {
    var p = c.primary, theme = p.theme || '#f3b416';
    injectStyles(theme);
    var bar = document.createElement('div');
    bar.className = 'av-celebrate'; bar.setAttribute('data-key', p.key || '');
    bar.style.setProperty('--cc', theme);
    bar.innerHTML = '<div class="avc-shine"></div><div class="avc-inner">'
      + (p.type === 'birthday' ? avatarsHtml(p.people) : '<span class="avc-emoji">' + (p.emoji || '🎉') + '</span>')
      + '<div class="avc-txt"><strong>' + esc(p.title || 'Celebrating today') + '</strong>'
      + '<span>' + esc(p.message || '') + '</span></div>'
      + '<button class="avc-x" aria-label="Dismiss">&times;</button></div>';
    document.body.insertBefore(bar, document.body.firstChild);
    bar.querySelector('.avc-x').addEventListener('click', function () {
      try { localStorage.setItem('av.celebrate.dismissed', c.date + ':' + (p.key || '')); } catch (e) {}
      bar.style.transition = 'transform .3s, opacity .3s';
      bar.style.transform = 'translateY(-100%)'; bar.style.opacity = '0';
      setTimeout(function () { bar.remove(); }, 320);
    });
  }

  function doodleLogo(p) {
    // new Cormorant wordmark (.brand-wordmark) with a legacy fallback
    var mark = document.querySelector('.nav-logo .brand-wordmark') || document.querySelector('.nav-logo-mark');
    if (!mark) return;
    if (p.doodle) {
      // admin-uploaded / built-in art replaces the wordmark for the day
      mark.innerHTML = '<img class="nav-logo-doodle-img" src="' + String(p.doodle).replace(/"/g, '') + '" alt="Afrovanguard" />';
      return;
    }
    // otherwise keep the live brand wordmark and dress it festively
    mark.classList.add('av-doodled');
    mark.setAttribute('data-emoji', p.emoji || '🎉');
    mark.style.setProperty('--cc', p.theme || '#f3b416');
  }

  function confetti(theme) {
    if (REDUCED) return;
    var cv = document.createElement('canvas');
    cv.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:400';
    document.body.appendChild(cv);
    var ctx = cv.getContext('2d'), W = cv.width = innerWidth, H = cv.height = innerHeight;
    var cols = [theme || '#f3b416', '#16a34a', '#ffffff', '#0ea5e9', '#ec4899'];
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

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  fetch('/api.php?action=celebrations', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var c = d && d.celebration; if (!c || !c.primary) return;
      var p = c.primary;
      doodleLogo(p); // the doodle always shows on a celebration day
      var dismissed = '';
      try { dismissed = localStorage.getItem('av.celebrate.dismissed') || ''; } catch (e) {}
      if (dismissed === c.date + ':' + (p.key || '')) return; // already dismissed today
      showBanner(c);
      var seen = '';
      try { seen = localStorage.getItem('av.celebrate.seen') || ''; } catch (e) {}
      if (seen !== c.date + ':' + (p.key || '')) {
        confetti(p.theme);
        try { localStorage.setItem('av.celebrate.seen', c.date + ':' + (p.key || '')); } catch (e) {}
      }
    })
    .catch(function () {});
})();
