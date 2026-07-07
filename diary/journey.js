/* ============================================================
   diary/journey.js — the Diary "journey map".

   A canvas-rendered winding trail of entry markers (Candy-Crush
   style, timeline order) that scales to a LOT of entries:

   • Camera model — the canvas is viewport-sized and *sticky*; a
     native scroll container provides the scroll length, so we
     never build a 30,000px canvas. We only draw the markers in
     view (culling), so hundreds/thousands of entries stay smooth.
   • Chapters — entries are grouped by year; each year opens with
     a chapter band, like the books of a diary.
   • A year rail jumps you anywhere instantly; a hover card previews
     each entry; click to read.
   • Map / List views — Map is the default; List reveals the same
     (already-filtered) entries as a clean journal index.

   The visually-hidden <ul id="journeyList"> of real <a> links is
   the crawlable, keyboard-navigable source of truth AND the List
   view. The canvas mirrors whichever links are currently visible,
   so the site search / category filter (diary.js) drives it free.
   ============================================================ */
(function () {
  'use strict';
  var wrap     = document.getElementById('journeyWrap');
  var viewport = document.getElementById('journeyViewport');
  var canvas   = document.getElementById('journeyCanvas');
  var spacer   = document.getElementById('journeySpacer');
  var list     = document.getElementById('journeyList');
  if (!wrap || !viewport || !canvas || !spacer || !list) return;

  var ctx     = canvas.getContext('2d');
  var rail    = document.getElementById('journeyRail');
  var card    = document.getElementById('journeyCard');
  var fsBtn   = document.getElementById('journeyFs');
  var fsClose = document.getElementById('journeyFsClose');
  var countEl = document.getElementById('journeyCount');

  /* Category → accent colour (slug-keyed; falls back to gold). */
  var PALETTE = {
    'field-notes': '#f3b416', 'events': '#3b82f6', 'methodology': '#16a34a',
    'vanguard-voices': '#8b5cf6', 'dispatch': '#f3b416', 'voices': '#8b5cf6',
    'reflections': '#ec4899', 'mission': '#ef4444'
  };
  var colorFor = function (cat) { return PALETTE[cat] || css('--gold', '#f3b416'); };

  var dpr = Math.max(1, window.devicePixelRatio || 1);
  var nodes = [], years = [], hover = -1, fullscreen = false, raf = 0;
  var worldH = 0, viewH = 0, viewW = 0, narrow = false;

  function css(name, fallback) {
    var v = getComputedStyle(document.body).getPropertyValue(name);
    return (v && v.trim()) || fallback;
  }

  /* ── Read currently-visible links into node descriptors ────────────── */
  function readNodes() {
    nodes = [].slice.call(list.querySelectorAll('a'))
      .filter(function (a) { return getComputedStyle(a).display !== 'none'; })
      .map(function (a) {
        var date = a.getAttribute('data-date') || '';
        return {
          href: a.getAttribute('href'),
          title: a.getAttribute('data-title') || a.textContent || '',
          cat: a.getAttribute('data-cat') || '',
          catName: a.getAttribute('data-cat-name') || '',
          published: a.getAttribute('data-published') || '',
          min: a.getAttribute('data-min') || '',
          num: a.getAttribute('data-num') || '',
          latest: a.getAttribute('data-latest') === '1',
          year: (date.match(/^\d{4}/) || ['—'])[0],
          color: colorFor(a.getAttribute('data-cat') || '')
        };
      });
    if (countEl) countEl.textContent = String(nodes.length);
  }

  /* ── Lay the nodes out as a winding, year-chaptered trail ──────────── */
  function layout() {
    viewW = viewport.clientWidth;
    viewH = viewport.clientHeight || 480;
    narrow = viewW < 560;
    var STEP   = narrow ? 104 : 122;
    var TOP    = narrow ? 58 : 70;
    var BOTTOM = 90;
    var YEARGAP = narrow ? 52 : 64;
    var leftX  = narrow ? viewW * 0.34 : viewW * 0.32;
    var rightX = narrow ? viewW * 0.66 : viewW * 0.68;

    years = [];
    var y = TOP, prevYear = null, lane = 0;
    nodes.forEach(function (nd, i) {
      var opensYear = (nd.year !== prevYear);
      if (opensYear) {
        if (prevYear !== null) y += YEARGAP;       // breathing room before a new chapter
        nd.bandY = y - (narrow ? 30 : 34);
        years.push({ year: nd.year, y: y, index: i });
        lane = 0;                                   // each chapter restarts the weave on the left
      }
      nd.opensYear = opensYear;
      nd.x = (lane % 2 === 0) ? leftX : rightX;
      nd.side = (lane % 2 === 0) ? 'left' : 'right';
      nd.y = y;
      nd.r = nd.latest ? (narrow ? 27 : 31) : (narrow ? 22 : 25);
      y += STEP; lane++; prevYear = nd.year;
    });
    worldH = nodes.length ? (y - STEP + BOTTOM) : viewH;

    // The canvas is the viewport; the spacer supplies the scroll length.
    canvas.style.width = viewW + 'px';
    canvas.style.height = viewH + 'px';
    canvas.width = Math.round(viewW * dpr);
    canvas.height = Math.round(viewH * dpr);
    spacer.style.height = Math.max(0, worldH - viewH) + 'px';
  }

  /* ── Draw only what's on camera ────────────────────────────────────── */
  function draw() {
    var camY = viewport.scrollTop;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, viewW, viewH);
    if (!nodes.length) return;
    ctx.translate(0, -camY);

    var ink   = css('--ink', '#111827'),  muted   = css('--muted', '#6b7280'),
        surf  = css('--surface', '#fff'), gold    = css('--gold', '#f3b416'),
        divider = css('--divider', '#e5e7eb');
    var top = camY - 60, bot = camY + viewH + 60;     // cull window

    // --- the trail (glow under, dashes over), clipped to the cull window ---
    var first = -1, last = -1;
    for (var k = 0; k < nodes.length; k++) {
      if (nodes[k].y >= top && first === -1) first = k;
      if (nodes[k].y <= bot) last = k;
    }
    if (first === -1) first = 0;
    var a = Math.max(0, first - 1), b = Math.min(nodes.length - 1, last + 1);
    if (b > a) {
      ctx.beginPath();
      ctx.moveTo(nodes[a].x, nodes[a].y);
      for (var i = a + 1; i <= b; i++) {
        var p0 = nodes[i - 1], p1 = nodes[i], my = (p0.y + p1.y) / 2;
        ctx.bezierCurveTo(p0.x, my, p1.x, my, p1.x, p1.y);
      }
      ctx.lineCap = 'round'; ctx.lineJoin = 'round';
      ctx.strokeStyle = gold; ctx.globalAlpha = 0.13; ctx.lineWidth = 13; ctx.setLineDash([]); ctx.stroke();
      ctx.globalAlpha = 0.6; ctx.lineWidth = 2.5; ctx.setLineDash([1, 12]); ctx.stroke();
      ctx.globalAlpha = 1; ctx.setLineDash([]);
    }

    // --- chapter (year) bands within view ---
    years.forEach(function (yr) {
      if (yr.y < top - 40 || yr.y > bot) return;
      var by = yr.bandY != null ? yr.bandY : (nodes[yr.index] ? nodes[yr.index].bandY : yr.y);
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.strokeStyle = divider; ctx.globalAlpha = 0.9; ctx.lineWidth = 1; ctx.setLineDash([2, 6]);
      ctx.beginPath(); ctx.moveTo(viewW * 0.16, by); ctx.lineTo(viewW * 0.84, by); ctx.stroke();
      ctx.setLineDash([]); ctx.globalAlpha = 1;
      ctx.fillStyle = surf;
      var label = yr.year, lw = 0;
      ctx.font = '700 ' + (narrow ? 15 : 17) + 'px Cormorant, Georgia, serif';
      lw = ctx.measureText(label).width + 26;
      roundRect(viewW / 2 - lw / 2, by - (narrow ? 12 : 13), lw, narrow ? 24 : 26, 13);
      ctx.fillStyle = surf; ctx.fill();
      ctx.lineWidth = 1; ctx.strokeStyle = divider; ctx.stroke();
      ctx.fillStyle = muted; ctx.fillText(label, viewW / 2, by + 1);
    });

    // --- markers + always-on labels ---
    for (var n = a; n <= b; n++) {
      var nd = nodes[n], hovered = n === hover, r = nd.r * (hovered ? 1.08 : 1);
      var onLeft = nd.side === 'left';
      var lx = onLeft ? nd.x + r + 15 : nd.x - r - 15;
      ctx.textAlign = onLeft ? 'left' : 'right';
      ctx.textBaseline = 'alphabetic';
      // category eyebrow
      ctx.fillStyle = nd.color;
      ctx.font = '800 9.5px Montserrat, system-ui, sans-serif';
      ctx.fillText((nd.catName || '').toUpperCase() + (nd.latest ? '  ·  LATEST' : ''), lx, nd.y - 12);
      // title
      ctx.fillStyle = ink;
      ctx.font = '600 ' + (narrow ? 16 : 18) + 'px Cormorant, Georgia, serif';
      ctx.fillText(fit(ctx, nd.title, viewW * (narrow ? 0.46 : 0.42)), lx, nd.y + 6);
      // meta
      ctx.fillStyle = muted;
      ctx.font = '500 11px Montserrat, system-ui, sans-serif';
      ctx.fillText(nd.published + (nd.min ? '  ·  ' + nd.min + ' min' : ''), lx, nd.y + 23);

      // medallion
      ctx.save();
      ctx.shadowColor = hovered ? nd.color : 'rgba(0,0,0,.28)';
      ctx.shadowBlur = hovered ? 20 : 9; ctx.shadowOffsetY = hovered ? 0 : 4;
      // Subtle 3D: radial body (lit from upper-left) + a soft specular glint.
      var g = ctx.createRadialGradient(nd.x - r * 0.35, nd.y - r * 0.4, r * 0.12, nd.x, nd.y, r);
      g.addColorStop(0, lighten(nd.color, 0.5));
      g.addColorStop(0.55, lighten(nd.color, 0.12));
      g.addColorStop(1, shade(nd.color, 0.14));
      ctx.beginPath(); ctx.arc(nd.x, nd.y, r, 0, Math.PI * 2); ctx.fillStyle = g; ctx.fill();
      ctx.restore();
      ctx.save();
      var sg = ctx.createRadialGradient(nd.x - r * 0.4, nd.y - r * 0.45, 0, nd.x - r * 0.4, nd.y - r * 0.45, r * 0.75);
      sg.addColorStop(0, 'rgba(255,255,255,.8)'); sg.addColorStop(1, 'rgba(255,255,255,0)');
      ctx.globalAlpha = hovered ? 0.6 : 0.45;
      ctx.beginPath(); ctx.arc(nd.x, nd.y, r, 0, Math.PI * 2); ctx.fillStyle = sg; ctx.fill();
      ctx.restore();
      ctx.lineWidth = 3; ctx.strokeStyle = surf;
      ctx.beginPath(); ctx.arc(nd.x, nd.y, r, 0, Math.PI * 2); ctx.stroke();
      if (hovered) { ctx.lineWidth = 1.5; ctx.strokeStyle = nd.color; ctx.beginPath(); ctx.arc(nd.x, nd.y, r + 3, 0, Math.PI * 2); ctx.stroke(); }
      // glyph
      ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.font = (nd.latest ? '700 ' + (narrow ? 20 : 23) + 'px ' : '700 ' + (narrow ? 14 : 16) + 'px ') + 'Cormorant, Georgia, serif';
      ctx.fillText(nd.num || '•', nd.x, nd.y + 1);
    }
  }

  function roundRect(x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }
  function fit(c, text, maxW) {
    if (c.measureText(text).width <= maxW) return text;
    var t = text;
    while (t.length > 1 && c.measureText(t + '…').width > maxW) t = t.slice(0, -1);
    return t.replace(/\s+$/, '') + '…';
  }
  function lighten(hex, amt) {
    var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex); if (!m) return hex;
    var f = function (x) { return Math.round(parseInt(x, 16) + (255 - parseInt(x, 16)) * amt); };
    return 'rgb(' + f(m[1]) + ',' + f(m[2]) + ',' + f(m[3]) + ')';
  }
  function shade(hex, amt) {
    var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex); if (!m) return hex;
    var f = function (x) { return Math.round(parseInt(x, 16) * (1 - amt)); };
    return 'rgb(' + f(m[1]) + ',' + f(m[2]) + ',' + f(m[3]) + ')';
  }

  function schedule() { if (!raf) raf = requestAnimationFrame(function () { raf = 0; draw(); syncRail(); }); }
  // layout() fills years[], so the rail must be (re)built AFTER it.
  function refresh() { layout(); buildRail(); schedule(); }
  function rebuild() {
    readNodes();
    // an empty filter result shouldn't leave an empty map frame hanging above
    // the "no results" notice (diary.js owns that message).
    wrap.style.display = nodes.length ? '' : 'none';
    layout(); buildRail(); viewport.scrollTop = 0; hideCard(); schedule();
  }

  /* ── Year rail (fast jump + "you are here") ────────────────────────── */
  function buildRail() {
    if (!rail) return;
    rail.innerHTML = '';
    if (years.length < 2) { rail.hidden = true; return; }
    rail.hidden = false;
    years.forEach(function (yr) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'jr-dot'; b.textContent = yr.year;
      b.setAttribute('data-y', String(yr.y));
      b.addEventListener('click', function () {
        viewport.scrollTo({ top: Math.max(0, yr.y - (narrow ? 56 : 70)), behavior: 'smooth' });
      });
      rail.appendChild(b);
    });
  }
  function syncRail() {
    if (!rail || rail.hidden) return;
    var mid = viewport.scrollTop + viewH * 0.4, cur = 0;
    for (var i = 0; i < years.length; i++) { if (years[i].y <= mid) cur = i; }
    var dots = rail.children;
    for (var j = 0; j < dots.length; j++) dots[j].classList.toggle('is-current', j === cur);
  }

  /* ── Hover card + pointer ──────────────────────────────────────────── */
  function nodeAt(ev) {
    var rect = canvas.getBoundingClientRect(), camY = viewport.scrollTop;
    var x = ev.clientX - rect.left, y = ev.clientY - rect.top + camY;
    for (var i = 0; i < nodes.length; i++) {
      if (Math.hypot(x - nodes[i].x, y - nodes[i].y) <= nodes[i].r + 7) return i;
    }
    return -1;
  }
  function showCard(i) {
    if (!card) return;
    var nd = nodes[i], camY = viewport.scrollTop;
    card.innerHTML = '<span class="jc-cat" style="color:' + nd.color + '">' + escapeHtml(nd.catName) + '</span>'
      + '<span class="jc-title">' + escapeHtml(nd.title) + '</span>'
      + '<span class="jc-meta">' + escapeHtml(nd.published) + (nd.min ? ' · ' + escapeHtml(nd.min) + ' min read' : '') + '</span>'
      + '<span class="jc-go">Read this entry →</span>';
    card.style.borderColor = nd.color;
    card.hidden = false;
    var cw = card.offsetWidth || 240, ch = card.offsetHeight || 96;
    var sx = nd.x, sy = nd.y - camY;
    var left = nd.side === 'left' ? sx + nd.r + 14 : sx - nd.r - 14 - cw;
    left = Math.max(8, Math.min(viewW - cw - 8, left));
    var topPos = Math.max(8, Math.min(viewH - ch - 8, sy - ch / 2));
    card.style.left = left + 'px'; card.style.top = topPos + 'px';
  }
  function hideCard() { if (card) card.hidden = true; }
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  canvas.addEventListener('mousemove', function (e) {
    var h = nodeAt(e);
    if (h !== hover) {
      hover = h; canvas.style.cursor = h >= 0 ? 'pointer' : 'default';
      if (h >= 0) showCard(h); else hideCard();
      schedule();
    }
  });
  canvas.addEventListener('mouseleave', function () { if (hover !== -1) { hover = -1; hideCard(); schedule(); } });
  canvas.addEventListener('click', function (e) { var h = nodeAt(e); if (h >= 0 && nodes[h].href) window.location.href = nodes[h].href; });
  var nearEndT = 0;
  viewport.addEventListener('scroll', function () {
    if (hover !== -1) { hover = -1; hideCard(); }
    schedule();
    // Panning near the end of the trail asks the feed controller for more.
    if (worldH > viewH && (viewport.scrollTop + viewH) > (worldH - 500)) {
      var now = Date.now();
      if (now - nearEndT > 600) { nearEndT = now; document.dispatchEvent(new CustomEvent('diary:mapnearend')); }
    }
  }, { passive: true });

  /* ── Map / List view toggle (Map is the default) ───────────────────── */
  function setView(v) {
    var listView = v === 'list';
    wrap.classList.toggle('is-listview', listView);
    document.querySelectorAll('.jv-btn').forEach(function (b) {
      var on = b.getAttribute('data-view') === v;
      b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', String(on));
    });
    if (fsBtn) fsBtn.disabled = listView;
    if (!listView) refresh();
  }
  document.querySelectorAll('.jv-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = b.getAttribute('data-view');
      setView(v);
      try { localStorage.setItem('av.diary.view', v); } catch (e) {}
    });
  });

  /* ── Initial view ──────────────────────────────────────────────────────
     The modern card GRID is the default; the winding Map is opt-in and
     remembered per device. (The page ships in grid/list mode, so no-JS and
     first paint are correct; we only switch to Map if the reader chose it.) */
  (function initView() {
    var saved = null; try { saved = localStorage.getItem('av.diary.view'); } catch (e) {}
    setView(saved === 'map' ? 'map' : 'list');
  })();

  /* ── Full screen ───────────────────────────────────────────────────── */
  function setFs(on) {
    fullscreen = on;
    wrap.classList.toggle('is-fullscreen', on);
    if (fsClose) fsClose.hidden = !on;
    if (fsBtn) fsBtn.setAttribute('aria-pressed', String(on));
    document.body.style.overflow = (on && !document.fullscreenElement) ? 'hidden' : '';
    setTimeout(refresh, 60);
  }
  function openFs() {
    if (wrap.requestFullscreen) wrap.requestFullscreen().then(function () { setFs(true); }).catch(function () { setFs(true); });
    else setFs(true);
  }
  function closeFs() {
    if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen();
    setFs(false);
  }
  if (fsBtn) fsBtn.addEventListener('click', function () { fullscreen ? closeFs() : openFs(); });
  if (fsClose) fsClose.addEventListener('click', closeFs);
  document.addEventListener('fullscreenchange', function () { if (!document.fullscreenElement && fullscreen) setFs(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && fullscreen) closeFs(); });

  /* ── React to resize, filter, and font load ────────────────────────── */
  var rt; window.addEventListener('resize', function () {
    dpr = Math.max(1, window.devicePixelRatio || 1); clearTimeout(rt); rt = setTimeout(refresh, 120);
  }, { passive: true });
  // feed.js owns filtering + progressive loading; it fires diary:changed after
  // it mutates #journeyList, so rebuild the map from the (grown/filtered) list.
  document.addEventListener('diary:changed', function () { setTimeout(rebuild, 0); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(refresh);

  readNodes(); refresh();
  setTimeout(refresh, 400);
})();
