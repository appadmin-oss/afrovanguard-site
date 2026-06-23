/* ============================================================
   assets/site/celebrations.js — the site that celebrates itself.
   On a holiday or a team birthday it shows: a Google-style logo doodle,
   a big Apple-style banner, a once-per-day celebratory modal (with a
   shareable poster), and confetti. Fully automatic — data from
   api.php?action=celebrations. Shareable via the Web Share API / download
   / social links.
   ============================================================ */
(function () {
  'use strict';
  var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function injectStyles(theme) {
    if (document.getElementById('av-celebrate-css')) return;
    var s = document.createElement('style'); s.id = 'av-celebrate-css';
    s.textContent = [
      ':root{--cc:' + theme + '}',
      /* doodle */
      '.nav-logo-mark.av-doodled{position:relative}',
      '.nav-logo-mark.av-doodled::after{content:attr(data-emoji);position:absolute;top:-12px;right:-16px;font-size:15px;animation:avcBob 2.2s ease-in-out infinite}',
      '.nav-logo-mark.av-doodled .van{background:linear-gradient(90deg,var(--cc),#fff);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}',
      '.nav-logo-doodle-img{height:30px;width:auto;vertical-align:middle}',
      '@keyframes avcBob{0%,100%{transform:translateY(0) rotate(-8deg)}50%{transform:translateY(-3px) rotate(8deg)}}',
      /* big Apple-style banner */
      '.av-cbanner{--cc:' + theme + ';position:relative;overflow:hidden;color:#fff;text-align:center;z-index:215;',
      'background:radial-gradient(120% 160% at 50% -40%,color-mix(in srgb,var(--cc) 78%,#fff) 0%,var(--cc) 42%,color-mix(in srgb,var(--cc) 60%,#0b0f1a) 100%)}',
      '.av-cbanner .cb-shine{position:absolute;inset:0;background:linear-gradient(105deg,transparent 32%,rgba(255,255,255,.22) 50%,transparent 68%);transform:translateX(-120%);animation:avcShine 4s ease-in-out infinite}',
      '.av-cbanner .cb-inner{position:relative;max-width:1100px;margin:0 auto;padding:clamp(16px,3.4vw,30px) 56px clamp(16px,3.4vw,30px) 20px;display:flex;flex-direction:column;align-items:center;gap:6px}',
      '.av-cbanner .cb-emoji{font-size:clamp(30px,5vw,46px);line-height:1;animation:avcPop .6s cubic-bezier(.22,1,.36,1) both}',
      '.av-cbanner .cb-avatars{display:flex;justify-content:center;margin-bottom:2px}',
      '.av-cbanner .cb-av{width:44px;height:44px;border-radius:50%;border:2.5px solid rgba(255,255,255,.9);background:#fff center/cover;margin-left:-12px;display:flex;align-items:center;justify-content:center;color:var(--cc);font-weight:800}',
      '.av-cbanner .cb-av:first-child{margin-left:0}',
      '.av-cbanner h2{font-family:Montserrat,system-ui,sans-serif;font-weight:800;letter-spacing:-.01em;font-size:clamp(20px,3.4vw,34px);margin:2px 0 0;line-height:1.05}',
      '.av-cbanner p{font-family:Montserrat,system-ui,sans-serif;font-size:clamp(13px,1.5vw,16px);opacity:.92;margin:0;max-width:60ch}',
      '.av-cbanner .cb-cta{display:inline-flex;align-items:center;gap:7px;margin-top:10px;background:#fff;color:#111;font-family:Montserrat,sans-serif;font-weight:800;font-size:13.5px;padding:10px 20px;border-radius:999px;border:0;cursor:pointer;text-decoration:none;transition:transform .2s}',
      '.av-cbanner .cb-cta:hover{transform:translateY(-2px)}',
      '.av-cbanner .cb-x{position:absolute;top:10px;right:12px;background:rgba(255,255,255,.16);border:0;color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:19px;line-height:1}',
      '.av-cbanner .cb-x:hover{background:rgba(255,255,255,.3)}',
      /* modal */
      '.av-cmodal{position:fixed;inset:0;z-index:500;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(8,10,16,.66);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);opacity:0;transition:opacity .3s}',
      '.av-cmodal.in{opacity:1}',
      '.av-cmodal .cm-card{position:relative;width:min(440px,94vw);max-height:92vh;overflow:auto;background:#fff;border-radius:22px;box-shadow:0 40px 90px -30px rgba(0,0,0,.7);transform:translateY(16px) scale(.98);transition:transform .35s cubic-bezier(.22,1,.36,1);text-align:center}',
      '.av-cmodal.in .cm-card{transform:none}',
      '.av-cmodal .cm-x{position:absolute;top:12px;right:12px;z-index:2;background:rgba(255,255,255,.85);border:0;width:36px;height:36px;border-radius:50%;cursor:pointer;font-size:20px;line-height:1;color:#111;box-shadow:0 2px 8px rgba(0,0,0,.2)}',
      '.av-cmodal .cm-poster{width:100%;display:block;background:#f4f3ef}',
      '.av-cmodal .cm-emoji{font-size:52px;margin:22px 0 4px}',
      '.av-cmodal .cm-title{font-family:Montserrat,sans-serif;font-weight:800;font-size:22px;color:#111;margin:6px 22px}',
      '.av-cmodal .cm-msg{font-family:Montserrat,sans-serif;font-size:14px;color:#555;line-height:1.6;margin:8px 26px 0}',
      '.av-cmodal .cm-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;padding:18px 22px 22px}',
      '.av-cmodal .cm-btn{display:inline-flex;align-items:center;gap:7px;font-family:Montserrat,sans-serif;font-weight:800;font-size:13.5px;padding:11px 18px;border-radius:999px;cursor:pointer;text-decoration:none;border:1.5px solid #e5e5e0;background:#fff;color:#111;transition:all .2s}',
      '.av-cmodal .cm-btn:hover{border-color:var(--cc)}',
      '.av-cmodal .cm-btn.primary{background:var(--cc);border-color:var(--cc);color:#1a1205}',
      '.av-cmodal .cm-soc{display:flex;gap:8px;justify-content:center;padding:0 22px 22px}',
      '.av-cmodal .cm-soc a{width:40px;height:40px;border-radius:50%;border:1px solid #e5e5e0;display:inline-flex;align-items:center;justify-content:center;color:#444}',
      '.av-cmodal .cm-soc a:hover{border-color:var(--cc);color:var(--cc)}',
      '.av-cmodal .cm-soc svg{width:18px;height:18px}',
      '@keyframes avcPop{0%{transform:scale(0) rotate(-25deg)}100%{transform:scale(1)}}',
      '@keyframes avcShine{0%,100%{transform:translateX(-120%)}55%{transform:translateX(120%)}}',
      '@media(prefers-reduced-motion:reduce){.av-cbanner .cb-shine,.nav-logo-mark.av-doodled::after,.av-cbanner .cb-emoji{animation:none}}'
    ].join('');
    document.head.appendChild(s);
  }

  function avatarsHtml(people, cls) {
    if (!people || !people.length) return '';
    var h = '<div class="' + cls + '">';
    people.slice(0, 4).forEach(function (p) {
      var ini = (p.name || '?').trim().charAt(0).toUpperCase();
      h += p.photo ? '<span class="cb-av" style="background-image:url(\'' + String(p.photo).replace(/'/g, '') + '\')"></span>'
                   : '<span class="cb-av">' + ini + '</span>';
    });
    return h + '</div>';
  }

  /* shareable poster URL for this celebration (or '') */
  function posterUrl(c) {
    var p = c.primary;
    if (p.type === 'birthday') return p.people && p.people[0] ? '/celebrate.php?type=birthday&id=' + p.people[0].id : '';
    return '/celebrate.php?type=holiday&date=' + encodeURIComponent(c.date);
  }
  function pageUrl(c) {
    var p = c.primary;
    if (p.type === 'birthday' && p.people && p.people[0]) return location.origin + '/people/' + p.people[0].id + '/';
    return location.origin + '/';
  }
  function shareText(c) { var p = c.primary; return p.title + ' · Afrovanguard'; }

  function doShare(c, img) {
    var title = shareText(c), text = (c.primary.message || ''), url = pageUrl(c);
    if (navigator.canShare && img) {
      fetch(img).then(function (r) { return r.blob(); }).then(function (b) {
        var f = new File([b], 'afrovanguard-celebration.png', { type: 'image/png' });
        if (navigator.canShare({ files: [f] })) return navigator.share({ files: [f], title: title, text: text });
        throw 0;
      }).catch(function () { basicShare(title, text, url); });
    } else basicShare(title, text, url);
  }
  function basicShare(title, text, url) {
    if (navigator.share) { navigator.share({ title: title, text: text, url: url }).catch(function () {}); return; }
    window.open('https://wa.me/?text=' + encodeURIComponent(text + ' ' + url), '_blank', 'noopener');
  }
  function socialHtml(c) {
    var u = encodeURIComponent(pageUrl(c)), t = encodeURIComponent(shareText(c) + ' — ' + (c.primary.message || ''));
    var wa = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 00-8.5 15.3L2 22l4.8-1.4A10 10 0 1012 2zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .1-1.7-.1-.4-.1-.9-.3-1.6-.6-2.7-1.2-4.5-4-4.6-4.2-.1-.2-1.1-1.5-1.1-2.8s.7-2 .9-2.2c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 1.9c.1.2.1.3 0 .5l-.4.6c-.2.2-.3.4-.1.7.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.3 2.4 1.5.2.1.4.1.6-.1l.7-.9c.2-.2.4-.2.6-.1l1.9.9c.2.1.4.2.4.3.1.2.1.7-.1 1.2z"/></svg>';
    var x = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.2 2.3h3.3l-7.2 8.3 8.5 11.1h-6.7l-5.2-6.8-6 6.8H1.6l7.7-8.8L1.3 2.3H8l4.7 6.2zm-1.1 17.5h1.8L7.1 4.1H5.1z"/></svg>';
    var fb = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 10-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.2c-1.2 0-1.6.8-1.6 1.5V12h2.7l-.4 2.9h-2.3v7A10 10 0 0022 12z"/></svg>';
    return '<a href="https://wa.me/?text=' + t + '%20' + u + '" target="_blank" rel="noopener" aria-label="Share on WhatsApp">' + wa + '</a>'
      + '<a href="https://twitter.com/intent/tweet?text=' + t + '&url=' + u + '" target="_blank" rel="noopener" aria-label="Share on X">' + x + '</a>'
      + '<a href="https://www.facebook.com/sharer/sharer.php?u=' + u + '" target="_blank" rel="noopener" aria-label="Share on Facebook">' + fb + '</a>';
  }

  function showBanner(c) {
    var p = c.primary, theme = p.theme || '#f3b416';
    var bar = document.createElement('div');
    bar.className = 'av-cbanner'; bar.style.setProperty('--cc', theme);
    bar.innerHTML = '<div class="cb-shine"></div><div class="cb-inner">'
      + (p.type === 'birthday' ? avatarsHtml(p.people, 'cb-avatars') : '<span class="cb-emoji">' + esc(p.emoji || '🎉') + '</span>')
      + '<h2>' + esc(p.title || 'Celebrating today') + '</h2>'
      + '<p>' + esc(p.message || '') + '</p>'
      + '<button class="cb-cta" type="button">Share the moment</button>'
      + '<button class="cb-x" aria-label="Dismiss">&times;</button></div>';
    document.body.insertBefore(bar, document.body.firstChild);
    bar.querySelector('.cb-cta').addEventListener('click', function () { doShare(c, posterUrl(c)); });
    bar.querySelector('.cb-x').addEventListener('click', function () {
      try { localStorage.setItem('av.celebrate.bannerX', c.date + ':' + (p.key || '')); } catch (e) {}
      bar.style.transition = 'transform .3s,opacity .3s'; bar.style.transform = 'translateY(-100%)'; bar.style.opacity = '0';
      setTimeout(function () { bar.remove(); }, 320);
    });
  }

  function showModal(c) {
    var p = c.primary, theme = p.theme || '#f3b416', img = posterUrl(c);
    var m = document.createElement('div'); m.className = 'av-cmodal'; m.style.setProperty('--cc', theme);
    var poster = img ? '<img class="cm-poster" src="' + esc(img) + '" alt="' + esc(p.title) + '" loading="eager" />'
                     : '<div class="cm-emoji">' + esc(p.emoji || '🎉') + '</div>';
    m.innerHTML = '<div class="cm-card" role="dialog" aria-modal="true" aria-label="' + esc(p.title) + '">'
      + '<button class="cm-x" aria-label="Close">&times;</button>'
      + poster
      + (img ? '' : '<div class="cm-title">' + esc(p.title) + '</div>')
      + '<p class="cm-msg">' + esc(p.message || '') + '</p>'
      + '<div class="cm-actions">'
      + '<button class="cm-btn primary" data-share type="button">Share</button>'
      + '<a class="cm-btn" data-dl href="' + esc(img ? img + '&dl=1' : '#') + '"' + (img ? ' download' : '') + '>Download</a>'
      + '</div>'
      + '<div class="cm-soc">' + socialHtml(c) + '</div>'
      + '</div>';
    document.body.appendChild(m);
    requestAnimationFrame(function () { m.classList.add('in'); });
    var close = function () { m.classList.remove('in'); setTimeout(function () { m.remove(); }, 320); };
    m.querySelector('.cm-x').addEventListener('click', close);
    m.addEventListener('click', function (e) { if (e.target === m) close(); });
    document.addEventListener('keydown', function onk(e) { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', onk); } });
    m.querySelector('[data-share]').addEventListener('click', function () { doShare(c, img); });
    if (!img) m.querySelector('[data-dl]').style.display = 'none';
  }

  function doodleLogo(p) {
    var mark = document.querySelector('.nav-logo-mark'); if (!mark) return;
    if (p.doodle) { mark.innerHTML = '<img class="nav-logo-doodle-img" src="' + String(p.doodle).replace(/"/g, '') + '" alt="Afrovanguard" />'; return; }
    mark.classList.add('av-doodled'); mark.setAttribute('data-emoji', p.emoji || '🎉'); mark.style.setProperty('--cc', p.theme || '#f3b416');
  }

  function confetti(theme) {
    if (REDUCED) return;
    var cv = document.createElement('canvas');
    cv.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:600';
    document.body.appendChild(cv);
    var ctx = cv.getContext('2d'), W = cv.width = innerWidth, H = cv.height = innerHeight;
    var cols = [theme || '#f3b416', '#16a34a', '#ffffff', '#0ea5e9', '#ec4899'];
    var parts = [], N = Math.min(170, Math.round(W / 8));
    for (var i = 0; i < N; i++) parts.push({ x: Math.random() * W, y: -20 - Math.random() * H * 0.5, r: 4 + Math.random() * 6, c: cols[i % cols.length], vy: 2 + Math.random() * 3.5, vx: -1.5 + Math.random() * 3, rot: Math.random() * 6.28, vr: -0.2 + Math.random() * 0.4 });
    var t0 = performance.now();
    (function frame(t) {
      ctx.clearRect(0, 0, W, H);
      parts.forEach(function (p) { p.x += p.vx; p.y += p.vy; p.rot += p.vr; ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.rot); ctx.fillStyle = p.c; ctx.fillRect(-p.r / 2, -p.r / 2, p.r, p.r * 0.6); ctx.restore(); });
      if (t - t0 < 3000) requestAnimationFrame(frame);
      else { cv.style.transition = 'opacity .5s'; cv.style.opacity = '0'; setTimeout(function () { cv.remove(); }, 520); }
    })(t0);
  }

  fetch('/api.php?action=celebrations', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var c = d && d.celebration; if (!c || !c.primary) return;
      var p = c.primary, tag = c.date + ':' + (p.key || '');
      injectStyles(p.theme || '#f3b416');
      doodleLogo(p);
      var get = function (k) { try { return localStorage.getItem(k) || ''; } catch (e) { return ''; } };
      var set = function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} };
      if (get('av.celebrate.bannerX') !== tag) showBanner(c);
      if (get('av.celebrate.modal') !== tag) {
        set('av.celebrate.modal', tag);
        setTimeout(function () { showModal(c); confetti(p.theme); }, 650);
      }
    })
    .catch(function () {});
})();
