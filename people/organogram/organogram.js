/* people/organogram/organogram.js — /people/organogram/ made interactive.
 *
 * The page is complete without it: one nested list, every node's words beside it
 * (the text outline is the primary, accessible version). This script shows the SAME
 * structure three ways, by the class it puts on .org-stage:
 *
 *   .is-chart  the stage is wide enough (--org-mode from a container query) and text is
 *              normal size: a drawn chart by d3-org-chart (bumbeishvili, MIT; on d3 v7
 *              and d3-flextree), self-hosted and fetched on first use from the stamped
 *              URLs in data-org-lib, fed from #org-data (the same model as the list).
 *              Compact layout, zoom and pan, expand/collapse, highlight-to-root for the
 *              selected node, its independent lines as dashed "connections" (all of them
 *              with the toggle), PNG export. The drawing is presentational (role="img",
 *              nothing focusable inside it): every action in it is also in the toolbar,
 *              the details drawer (which can open and close a group) and the outline.
 *              The drawer slides over the chart from the right; it never squeezes it.
 *   .is-nav    narrow stages, or larger text: a drill-down navigator. One level of cards;
 *              a card with something below goes in, a leaf opens its details as a bottom
 *              sheet; the path is always shown as a breadcrumb and Back is one level up.
 *   (none)     "Text version": the full outline again.
 *
 * Find: a typo-tolerant search (the People page's matcher) over titles, offices and
 * listed names; choosing a match reveals and selects it. Deep links: #org-<key> selects
 * and reveals a node; selecting writes the hash with replaceState, so a view can be
 * shared and Back still leaves the page. Print: a fitted copy of the chart with every
 * group open, on one landscape page (organogram.css); the PDF is the full version.
 */
(function () {
  'use strict';
  var stage = document.querySelector('[data-org-stage]');
  if (!stage) return;
  var list = stage.querySelector('.org-tree'), body = stage.querySelector('.org-body'), viz = stage.querySelector('[data-org-viz]');
  var panel = document.getElementById('org-panel'), pathNav = stage.querySelector('[data-org-path]');
  var bar = document.querySelector('[data-org-bar]'), live = document.getElementById('org-live'), legend = document.getElementById('org-legend');
  var TIER = { gov: 'Governance', exec: 'Executive', ops: 'Operations' };
  var lis = [].slice.call(list.querySelectorAll('.org-li')), byKey = {};
  var root = lis[0];
  var mode = '', sel = null, focusKey = root.getAttribute('data-org-key'), linesOn = false, textOn = false, printing = null, retFocus = null;
  var DATA = { nodes: [], lines: [] };
  try { DATA = JSON.parse(document.getElementById('org-data').textContent); } catch (e) {}

  /* ── Small helpers ──────────────────────────────────────────────── */
  function key(li) { return li.getAttribute('data-org-key'); }
  function upLi(li) { return li.parentElement ? li.parentElement.closest('.org-li') : null; }
  function kidsUl(li) { return li.querySelector(':scope > .org-kids'); }
  function kids(li) { var u = kidsUl(li); return u ? [].slice.call(u.children) : []; }
  function chain(li) { var a = []; for (var p = li; p; p = upLi(p)) a.push(p); return a; }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function say(msg) { live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 30); }
  function face(li) { var f = li.querySelector(':scope > .org-node .org-face-n'); return f ? f.firstChild.textContent.trim() : ''; }
  function count(li) { var m = li.querySelector(':scope > .org-node .org-meta'); return m ? m.textContent : ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function still() { return document.documentElement.classList.contains('a11y-still') || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches); }
  function shown(e) { return !!(e && e.getClientRects().length); }

  /* ── Enhance each list item: its title becomes a button (navigator, outline) ─ */
  lis.forEach(function (li) {
    var k = key(li); byKey[k] = li;
    var t = li.querySelector(':scope > .org-node .org-t');
    li._title = t.textContent.trim();
    t.setAttribute('tabindex', '-1');
    var b = el('button', 'org-pick'); b.type = 'button';
    while (t.firstChild) b.appendChild(t.firstChild);
    b.appendChild(el('span', 'org-pick-x'));
    var desc = [];
    ['org-f-', 'org-m-'].forEach(function (p) { if (document.getElementById(p + k)) desc.push(p + k); });
    if (desc.length) b.setAttribute('aria-describedby', desc.join(' '));
    t.appendChild(b); li._pick = b;
    if (kidsUl(li)) li.setAttribute('data-org-has', '');
  });

  /* ── Modes ──────────────────────────────────────────────────────── */
  var libFailed = false;
  function cssMode() { return !libFailed && (getComputedStyle(body).getPropertyValue('--org-mode') || '').trim() === 'chart' ? 'chart' : 'nav'; }
  function pickLabels() {
    lis.forEach(function (li) {
      var x = li._pick.querySelector('.org-pick-x'); x.textContent = '';
      if (mode === 'nav') {
        if (key(li) === focusKey) x.textContent = 'Details';
        else x.appendChild(el('span', 'av-sr', li.hasAttribute('data-org-has') ? ': see what is below' : ', details'));
      }
    });
  }
  function setMode(m) {
    if (m === mode) return;
    var wasOpen = panel.open;
    if (wasOpen) panel.close();
    body.classList.remove('has-panel');
    mode = m;
    stage.classList.toggle('is-chart', m === 'chart');
    stage.classList.toggle('is-nav', m === 'nav');
    viz.hidden = m !== 'chart';
    [].forEach.call(bar.querySelectorAll('[data-org-chart-only]'), function (x) { x.hidden = m !== 'chart'; });
    pathNav.hidden = m !== 'nav';
    legend.hidden = m !== 'chart';
    pickLabels();
    if (m === 'nav') drill(sel ? (byKey[sel].hasAttribute('data-org-has') && sel !== focusKey ? key(upLi(byKey[sel]) || byKey[sel]) : focusKey) : focusKey, { quiet: true });
    paintSel();
    if (wasOpen && sel && m !== 'text') openPanel(sel, { focus: false });
    if (m === 'chart') ensureChart(function () { if (sel) { reveal(sel); refresh(); centerOn(sel, false); } else { refresh({ instant: true }); fitView(false); } });
  }
  function pickMode() { setMode(textOn ? 'text' : cssMode()); }

  /* ── The drawn chart (d3-org-chart) ────────────────────────────── */
  var W = 232, H = 74, CHIP = 168;
  var NODE = {}, PAR = {}, rows = [], open = {}, VIS = {}, linkEnds = {}, chart = null, libP = null, userZoomed = false;
  DATA.nodes.forEach(function (n) { NODE[n.id] = n; PAR[n.id] = n.parentId; rows.push({ id: n.id, parentId: n.parentId }); });
  function isAnc(a, b) { for (var p = PAR[b]; p; p = PAR[p]) if (p === a) return true; return false; }
  function loadLib() {
    if (libP) return libP;
    var srcs = [];
    try { srcs = JSON.parse(stage.getAttribute('data-org-lib') || '[]'); } catch (e) {}
    libP = srcs.reduce(function (p, src) {
      return p.then(function () {
        return new Promise(function (ok, no) { var s = document.createElement('script'); s.src = src; s.async = false; s.onload = ok; s.onerror = no; document.head.appendChild(s); });
      });
    }, Promise.resolve()).then(function () { if (!window.d3 || !window.d3.OrgChart) throw new Error('org chart library missing'); });
    return libP;
  }
  function ensureChart(then) {
    if (chart) { then(); return; }
    viz.setAttribute('aria-busy', 'true');
    loadLib().then(function () { viz.removeAttribute('aria-busy'); buildChart(); if (mode === 'chart') then(); },
      function () { libFailed = true; viz.removeAttribute('aria-busy'); pickMode(); });
  }
  /* Which nodes are drawn: every ancestor is either not a group or an open group. */
  function computeVis() {
    VIS = {};
    rows.forEach(function (r) {
      var v = true;
      for (var p = PAR[r.id]; p; p = PAR[p]) if (NODE[p].group && !open[p]) { v = false; break; }
      VIS[r.id] = v;
    });
  }
  function nearestVis(k) { while (k && !VIS[k]) k = PAR[k]; return k; }
  function reveal(k) { for (var p = PAR[k]; p; p = PAR[p]) if (NODE[p].group) open[p] = true; }
  /* The selected node's lines land on their real ends: open the groups that hold them. */
  function revealEnds(k) {
    DATA.lines.forEach(function (l) {
      if (l.from.length === 1 && l.from[0] === k && l.to) reveal(l.to);
      if (l.to === k && l.from.length === 1) reveal(l.from[0]);
    });
  }
  /* The dashed lines: all of them with the toggle on, else only the selected node's. Each end at
     the nearest drawn box; the seven Finance Officers' lines only while their team is open
     (at the CFO's end they are one line, to the Directorates). */
  function connections() {
    var out = [], seen = {}; linkEnds = {};
    DATA.lines.forEach(function (l) {
      l.from.forEach(function (f) {
        var mine = !!sel && (f === sel || l.to === sel);
        if (!linesOn && !mine) return;
        if (/-finance$/.test(f) && !VIS[f] && !mine) return;
        var a = nearestVis(f), b = nearestVis(l.to);
        if (!a || !b || a === b || isAnc(a, b) || isAnc(b, a)) return;
        var id = a < b ? a + '|' + b : b + '|' + a;
        if (seen[id]) { seen[id].mine = seen[id].mine || mine; } else { seen[id] = { from: a, to: b, label: '', mine: mine }; out.push(seen[id]); }
        if (mine) { linkEnds[a] = 1; linkEnds[b] = 1; }
      });
    });
    return out;
  }
  function avatar(n) {
    if (!n.holders.length) {
      return n.group ? '<span class="ocn-av ocn-av--grp"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg></span>'
        : '<span class="ocn-av ocn-av--none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="9" r="3.5"/><path d="M5.5 19c1.2-3.2 3.6-4.8 6.5-4.8s5.3 1.6 6.5 4.8"/></svg></span>';
    }
    var m = n.holders[0];
    return '<span class="ocn-av ppl-tint-' + (+m.tint || 0) + '">' + esc(m.ini) + (m.photo ? '<img src="' + esc(m.photo) + '" alt="" width="36" height="36" decoding="async" />' : '') + '</span>';
  }
  function cardHtml(d) {
    var n = NODE[d.data.id], k = n.id, c = 'ocn ocn--' + n.tier;
    if (n.beside) c += ' ocn--beside';
    if (k === sel) c += ' is-sel';
    if (d.data._upToTheRootHighlighted) c += ' is-path';
    if (linkEnds[k] && k !== sel) c += ' is-link';
    var tag = n.beside ? '<span class="ocn-tag">Beside, no line</span>' : '';
    var who = n.holders.length ? '<span class="ocn-h">' + esc(n.holders[0].name) + (n.more ? ' <span class="ocn-more">+' + n.more + '</span>' : '') + (tag ? ' · ' + tag : '') + '</span>' : tag;
    return '<div class="' + c + '">' + avatar(n) + '<span class="ocn-tx">'
      + '<span class="ocn-t">' + esc(n.title) + '</span>' + who + '</span></div>';
  }
  function chipHtml(node) {
    var n = NODE[node.data.id];
    if (!n || !n.group) return '';
    return '<div class="ocn-chip' + (node.children ? ' is-open' : '') + '"><span>' + esc(n.count) + '</span><i></i></div>';
  }
  function buildChart() {
    var h = viz.clientHeight || 600;
    chart = new window.d3.OrgChart()
      .container(viz).data(rows).svgHeight(h)
      .nodeId(function (d) { return d.id; }).parentNodeId(function (d) { return d.parentId; })
      .nodeWidth(function () { return W; }).nodeHeight(function () { return H; })
      .childrenMargin(function () { return 46; }).siblingsMargin(function () { return 16; }).neighbourMargin(function () { return 28; })
      .compact(true).compactMarginBetween(function () { return 18; }).compactMarginPair(function () { return 36; })
      .rootMargin(24).duration(still() ? 0 : 320).scaleExtent([0.15, 2.5]).setActiveNodeCentered(false)
      .createZoom(function () {
        // A plain wheel scrolls the page; pinch (ctrl+wheel on a trackpad), Ctrl or ⌘ and the wheel, drag and touch zoom and pan.
        return window.d3.zoom().filter(function (ev) { return ev.type === 'wheel' ? (ev.ctrlKey || ev.metaKey) : !ev.ctrlKey && !ev.button; });
      })
      .nodeButtonWidth(function (d) { return NODE[d.data.id].group ? CHIP : 0; }).nodeButtonHeight(function (d) { return NODE[d.data.id].group ? 26 : 0; })
      .nodeButtonX(function () { return -CHIP / 2; }).nodeButtonY(function () { return -13; })
      .buttonContent(function (o) { return chipHtml(o.node); })
      .nodeContent(cardHtml)
      .nodeUpdate(function () {
        var g = window.d3.select(this);
        g.select('.node-rect').attr('stroke', 'none');
        g.selectAll('.ocn-av img').on('error', function () { this.remove(); });
      })
      .linkUpdate(function (d) {
        var s = window.d3.select(this).attr('stroke', null).attr('stroke-width', null)
          .classed('is-path', !!d.data._upToTheRootHighlighted).classed('is-none', !!NODE[d.data.id].beside);
        if (d.data._upToTheRootHighlighted) s.raise();
      })
      .defs(function () { return '<defs></defs>'; })
      .connectionsUpdate(function (c) {
        window.d3.select(this).attr('stroke', null).attr('stroke-width', null).attr('marker-start', null).attr('marker-end', null)
          .attr('pointer-events', 'none').classed('is-mine', !!c.mine);
      })
      .onNodeClick(function (d) { var k = d.data.id; if (sel === k && panel.open) clearSel(true); else select(k); })
      .onZoomStart(function (e) { if (e && e.sourceEvent) userZoomed = true; });
    computeVis();
    rows.forEach(function (r) { r._expanded = VIS[r.id]; });
    chart.render();
    chart.getChartState().centerG.attr('transform', 'translate(0,0)');
    // The expand chip: our own toggle (capture, before the library's handler), so one state drives the chart.
    viz.addEventListener('click', function (e) {
      var g = e.target.closest && e.target.closest('.node-button-g');
      if (!g || !g.__data__) return;
      e.stopPropagation(); e.preventDefault();
      toggle(g.__data__.data.id);
    }, true);
  }
  function refresh(o) {
    if (!chart) return;
    o = o || {};
    computeVis();
    rows.forEach(function (r) { r._expanded = VIS[r.id]; r._upToTheRootHighlighted = false; r._highlighted = false; });
    if (sel && NODE[sel] && !NODE[sel].beside) chart.setUpToTheRootHighlighted(sel);
    chart.connections(connections()).duration(o.instant || still() ? 0 : 320);
    viz.classList.toggle('has-sel', !!sel);
    chart.svgHeight(viz.clientHeight || 600);
    chart.render();
    chart.getChartState().connectionsWrapper.lower();   // dashed lines run behind the cards
    if (o.instant && window.d3.timerFlush) { window.d3.timerFlush(); window.d3.timerFlush(); }
  }
  function toggle(k) {
    var n = NODE[k]; if (!n || !n.group) return;
    open[k] = !open[k];
    if (!open[k]) Object.keys(open).forEach(function (x) { if (isAnc(k, x)) delete open[x]; });
    if (!open[k] && sel && isAnc(k, sel)) clearSel(false);   // its selected member is no longer drawn
    refresh();
    say(n.title + ': ' + n.count + (open[k] ? ', shown.' : ', hidden.'));
    if (open[k]) ensureVisible(k);
    if (panel.open && sel === k) syncExp();
  }
  /* Geometry: node boxes in chart space, and the view (left of the drawer when it is open). */
  function drawerW() { return mode === 'chart' && panel.open ? panel.offsetWidth : 0; }
  function boxes(ids) {
    var st = chart.getChartState(), all = st.root.descendants(), b = null;
    all.forEach(function (d) {
      if (ids && ids.indexOf(d.data.id) === -1) return;
      var x0 = d.x - W / 2, x1 = d.x + W / 2, y0 = d.y, y1 = d.y + H + (NODE[d.data.id].group ? 13 : 0);
      if (!b) b = { x0: x0, x1: x1, y0: y0, y1: y1 }; else { b.x0 = Math.min(b.x0, x0); b.x1 = Math.max(b.x1, x1); b.y0 = Math.min(b.y0, y0); b.y1 = Math.max(b.y1, y1); }
    });
    return b;
  }
  function moveTo(t, animate) {
    var st = chart.getChartState(), d3 = window.d3;
    if (animate && !still()) st.svg.transition().duration(380).call(st.zoomBehavior.transform, t);
    else st.svg.call(st.zoomBehavior.transform, t);
  }
  function fitView(animate, ids, cap) {
    if (!chart) return;
    var st = chart.getChartState(), b = boxes(ids); if (!b) return;
    var pad = 28, w = st.svgWidth - drawerW(), h = viz.clientHeight || st.svgHeight;
    var k = Math.min(cap || 1, (w - 2 * pad) / (b.x1 - b.x0), (h - 2 * pad) / (b.y1 - b.y0));
    k = Math.max(0.15, k);
    var tx = (w - (b.x1 - b.x0) * k) / 2 - b.x0 * k, ty = Math.max(pad, (h - (b.y1 - b.y0) * k) / 2 * (ids ? 1 : 0.5)) - b.y0 * k;
    moveTo(window.d3.zoomIdentity.translate(tx, ty).scale(k), animate);
    userZoomed = false;
  }
  function centerOn(k, animate) {
    if (!chart) return;
    var st = chart.getChartState(), d = st.root.descendants().filter(function (x) { return x.data.id === k; })[0];
    if (!d) return;
    var w = st.svgWidth - drawerW(), h = viz.clientHeight || st.svgHeight;
    var kk = Math.min(0.9, Math.max(0.72, st.lastTransform.k || 1));
    moveTo(window.d3.zoomIdentity.translate(w / 2 - d.x * kk, h * 0.42 - (d.y + H / 2) * kk).scale(kk), animate);
  }
  /* After a group opens: if it and its members do not fit the view, bring them in (zooming out a little if needed). */
  function ensureVisible(k) {
    var st = chart.getChartState(), ids = [k], t = st.lastTransform;
    st.root.descendants().forEach(function (d) { if (isAnc(k, d.data.id)) ids.push(d.data.id); });
    var b = boxes(ids); if (!b) return;
    var w = st.svgWidth - drawerW(), h = viz.clientHeight || st.svgHeight;
    var L = b.x0 * t.k + t.x, R = b.x1 * t.k + t.x, T = b.y0 * t.k + t.y, B = b.y1 * t.k + t.y;
    if (L >= 8 && R <= w - 8 && T >= 8 && B <= h - 8) return;
    fitView(true, ids, Math.max(t.k, 0.5) > 1 ? 1 : Math.max(t.k, 0.5));
  }

  /* ── PNG: the library's export, of a fitted copy of what is drawn ─ */
  function exportCss() {
    var txt = '', seen = {}, decl = '';
    [].forEach.call(document.styleSheets, function (ss) {
      var r; try { r = ss.cssRules; } catch (e) { return; }
      [].forEach.call(r || [], function (x) { if (x.selectorText && /\.ocn|svg-chart-container|ppl-tint-/.test(x.selectorText) && !/a11y-/.test(x.selectorText)) txt += x.cssText + '\n'; });
    });
    var cs = getComputedStyle(viz);
    (txt.match(/--[\w-]+/g) || []).forEach(function (v) { if (seen[v]) return; seen[v] = 1; var val = cs.getPropertyValue(v).trim(); if (val) decl += v + ':' + val + ';'; });
    return '.svg-chart-container{' + decl + 'font-family:' + cs.fontFamily + '}\n' + txt;
  }
  /* A copy of the drawing, fitted at scale 1 (or less for print), with its styles inside it. */
  function snapshot(title) {
    var st = chart.getChartState(), b = boxes(), pad = 32, top = title ? 56 : 0;
    var c = st.svg.node().cloneNode(true), w = Math.ceil(b.x1 - b.x0 + 2 * pad), h = Math.ceil(b.y1 - b.y0 + 2 * pad + top);
    c.setAttribute('width', w); c.setAttribute('height', h); c.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
    c.removeAttribute('cursor'); c.removeAttribute('style');
    c.querySelector('g.chart').setAttribute('transform', 'translate(' + (pad - b.x0) + ',' + (pad + top - b.y0) + ')');
    c.querySelector('g.center-group').setAttribute('transform', 'translate(0,0)');
    [].forEach.call(c.querySelectorAll('.node'), function (n) { n.setAttribute('opacity', '1'); });
    var NS = 'http://www.w3.org/2000/svg', style = document.createElementNS(NS, 'style');
    style.textContent = exportCss(); c.insertBefore(style, c.firstChild);
    if (title) {
      var t = document.createElementNS(NS, 'text'); t.setAttribute('x', pad); t.setAttribute('y', pad + 14); t.setAttribute('class', 'svg-chart-title'); t.textContent = title; c.insertBefore(t, style.nextSibling);
    }
    return c;
  }
  function settle(fn) { setTimeout(fn, still() ? 30 : 420); }
  function exportPng(btn) {
    if (!chart) return;
    btn.disabled = true; say('Preparing the image.');
    settle(function () {
      var c = snapshot('Afrovanguard — How we are organised'), holder = el('div', 'org-offstage');
      holder.setAttribute('aria-hidden', 'true'); holder.appendChild(c); document.body.appendChild(holder);
      var imgs = [].slice.call(c.querySelectorAll('img')), left = imgs.length, done = false;
      function go() {
        if (done) return; done = true;
        var w = +c.getAttribute('width'), h = +c.getAttribute('height'), scale = Math.max(1, Math.min(2, 8000 / Math.max(w, h)));
        chart.downloadImage({ node: c, scale: scale, imageName: 'Afrovanguard-Organogram', isSvg: false, save: true, backgroundColor: '#ffffff',
          onLoad: function () { holder.remove(); btn.disabled = false; say('Image downloaded.'); } });
      }
      // Photos are inlined; one from another site is left out (its initials stay).
      imgs.forEach(function (im) {
        var u; try { u = new URL(im.getAttribute('src'), location.href); } catch (e) { u = null; }
        if (!u || u.origin !== location.origin) { im.remove(); if (--left === 0) go(); return; }
        chart.toDataURL(u.href, function (data) { im.setAttribute('src', data); if (--left === 0) go(); });
      });
      if (!imgs.length) go();
      setTimeout(go, 4000);
    });
  }

  /* ── Selection and the details drawer ──────────────────────────── */
  function paintSel() {
    lis.forEach(function (li) { li.classList.remove('is-sel'); });
    if (sel && byKey[sel]) byKey[sel].classList.add('is-sel');
  }
  function setHash(k) {
    var u = location.pathname + location.search + (k ? '#org-' + k : '');
    try { history.replaceState(history.state, '', u); } catch (e) {}
  }
  function select(k, o) {
    o = o || {};
    var li = byKey[k]; if (!li) return;
    sel = k;
    if (mode === 'nav') { var p = upLi(li); drill(p ? key(p) : k, { quiet: true, keepSel: true }); }
    if (mode === 'text') { lis.forEach(function (x) { x.classList.remove('is-hit'); }); }
    paintSel();
    setHash(k);
    if (mode === 'text') { li.scrollIntoView({ block: 'start' }); li.querySelector('.org-t').focus({ preventScroll: true }); return; }
    if (mode === 'chart') {
      openPanel(k, o);
      ensureChart(function () { reveal(k); revealEnds(k); refresh(); centerOn(k, o.animate !== false); });
      return;
    }
    openPanel(k, o);
  }
  function clearSel(back) {
    var was = sel; sel = null;
    if (panel.open) panel.close();
    body.classList.remove('has-panel');
    paintSel(); setHash(mode === 'nav' && focusKey !== key(root) ? focusKey : null);
    if (mode === 'chart') refresh();
    if (!back) return;
    if (mode === 'chart') { var r = retFocus; retFocus = null; (r && shown(r) && r !== document.body ? r : viz).focus({ preventScroll: true }); }
    else if (was && byKey[was] && shown(byKey[was]._pick)) byKey[was]._pick.focus();
  }
  function jump(k, label) {
    var b = el('button', 'org-jump'); b.type = 'button'; b.setAttribute('data-org-go', k);
    b.appendChild(document.createTextNode(label || byKey[k]._title));
    return b;
  }
  function syncExp() {
    var b = panel.querySelector('[data-org-exp]'); if (!b || !sel) return;
    var n = NODE[sel], what = n.count.split(' · ')[0];
    b.textContent = (open[sel] ? 'Hide its ' : 'Show its ') + what + ' on the chart';
    b.setAttribute('aria-pressed', open[sel] ? 'true' : 'false');
  }
  function buildPanel(k) {
    var li = byKey[k], about = li.querySelector(':scope > .org-about'), tier = (li.className.match(/org-tier--(\w+)/) || [])[1] || 'gov';
    panel.textContent = '';
    var head = el('div', 'org-ph org-tier--' + tier), ht = el('div', 'org-ph-t');
    ht.appendChild(el('p', 'org-ph-k', li.hasAttribute('data-org-beside') ? 'Beside the structure' : TIER[tier]));
    var h = el('h2', '', li._title); h.id = 'org-panel-h'; h.tabIndex = -1; ht.appendChild(h);
    var x = el('button', 'org-x'); x.type = 'button'; x.setAttribute('data-org-close', ''); x.setAttribute('aria-label', 'Close details');
    x.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>';
    head.appendChild(ht); head.appendChild(x); panel.appendChild(head);
    var pb = el('div', 'org-pb'), sec = function (t) { pb.appendChild(el('h3', '', t)); };
    if (mode === 'chart' && NODE[k] && NODE[k].group) { var eb = el('button', 'org-pexp'); eb.type = 'button'; eb.setAttribute('data-org-exp', ''); pb.appendChild(eb); }
    var d = about.querySelector(':scope > .org-d'); if (d) pb.appendChild(d.cloneNode(true));
    var held = about.querySelector(':scope > .org-held, :scope > .org-offices');
    if (held) { sec(held.classList.contains('org-offices') ? 'Offices and who holds them' : 'Held by'); pb.appendChild(held.cloneNode(true)); }
    else if (!li.hasAttribute('data-org-has') && !li.hasAttribute('data-org-beside') && k !== key(root)) { sec('Held by'); pb.appendChild(el('p', 'org-held', 'Not yet listed publicly.')); }
    var up = about.querySelector(':scope > .org-up');
    if (up) {
      sec('Answers to'); var p = el('p', 'org-up'); p.appendChild(jump(up.getAttribute('data-org-up')));
      var s = up.querySelector('.org-say'); if (s) p.appendChild(s.cloneNode(true));
      pb.appendChild(p);
    }
    var ks = kids(li).filter(function (c) { return !c.hasAttribute('data-org-beside'); });
    if (ks.length) {
      sec(li.classList.contains('org-li--group') ? 'In this group' : 'Answers to it'); var ul = el('ul', 'org-pkids');
      ks.forEach(function (c) { var i = el('li'), b = jump(key(c)); var w = face(c) || count(c); if (w) b.appendChild(el('small', '', w)); i.appendChild(b); ul.appendChild(i); });
      pb.appendChild(ul);
    }
    kids(li).filter(function (c) { return c.hasAttribute('data-org-beside'); }).forEach(function (c) { sec('Beside it, with no line'); var p = el('p'); p.appendChild(jump(key(c))); pb.appendChild(p); });
    var more = about.querySelector(':scope > .org-say--p'); if (more) pb.appendChild(more.cloneNode(true));
    var ind = about.querySelector(':scope > .org-ind');
    if (ind) {
      sec('Independent lines'); var c2 = ind.cloneNode(true);
      [].forEach.call(c2.querySelectorAll('[data-org-to]'), function (i) { var t = i.getAttribute('data-org-to'); if (byKey[t]) i.appendChild(jump(t, 'Go to ' + byKey[t]._title)); });
      pb.appendChild(c2);
    }
    panel.appendChild(pb);
    syncExp();
  }
  function openPanel(k, o) {
    o = o || {};
    var a = document.activeElement;
    if (!panel.open && a && a !== document.body && !panel.contains(a)) retFocus = a;
    buildPanel(k);
    if (mode === 'chart') {
      body.classList.add('has-panel');
      if (panel.open && panel.matches(':modal')) panel.close();
      if (!panel.open) panel.show();
    } else if (mode === 'nav') {
      if (panel.open && !panel.matches(':modal')) panel.close();
      if (!panel.open) panel.showModal();
    }
    panel.scrollTop = 0;
    if (o.focus !== false) {
      if (mode === 'chart') {
        // The whole chart in view (clear of the sticky header), then the drawer's heading.
        var r = body.getBoundingClientRect();
        if (r.top < 80 || r.bottom > window.innerHeight) window.scrollBy(0, r.top - 88);
      }
      document.getElementById('org-panel-h').focus({ preventScroll: mode === 'chart' });
    } else say('Showing ' + byKey[k]._title + '.');
  }
  panel.addEventListener('click', function (e) {
    var g = e.target.closest('[data-org-go]');
    if (g) { select(g.getAttribute('data-org-go')); return; }
    if (e.target.closest('[data-org-exp]')) { toggle(sel); return; }
    if (e.target.closest('[data-org-close]')) clearSel(true);
  });
  panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); clearSel(true); } });
  panel.addEventListener('close', function () { if (sel && !panel.open && mode === 'nav') { var k = sel; setTimeout(function () { if (!panel.open && sel === k) clearSel(true); }, 0); } });

  /* ── Navigator: one level at a time ────────────────────────────── */
  function drill(k, o) {
    o = o || {};
    var li = byKey[k]; if (!li) return;
    if (!li.hasAttribute('data-org-has')) li = upLi(li) || li;
    focusKey = key(li); pathOpen = false;
    lis.forEach(function (x) { x.classList.remove('is-path', 'is-focus'); });
    chain(li).forEach(function (x) { x.classList.add('is-path'); });
    li.classList.add('is-focus');
    if (mode === 'nav') pickLabels();
    renderPath(li);
    if (!o.keepSel && !o.quiet) { sel = null; paintSel(); setHash(focusKey === key(root) ? null : focusKey); }
    if (!o.quiet) {
      var n = kids(li).length;
      say(li._title + '. ' + n + (n === 1 ? ' item' : ' items') + ' below.');
      var t = li.querySelector(':scope > .org-node .org-t');
      var top = stage.getBoundingClientRect().top;
      if (top < 70 || top > window.innerHeight * 0.6) window.scrollBy(0, top - 90);
      t.focus({ preventScroll: true });
    }
  }
  function renderPath(li) {
    pathNav.textContent = '';
    var c = chain(li).reverse();
    if (c.length > 1) {
      var bk = el('button', 'org-back', 'Back'); bk.type = 'button'; bk.setAttribute('data-org-drill', key(c[c.length - 2]));
      bk.appendChild(el('span', 'av-sr', ' up to ' + c[c.length - 2]._title));
      pathNav.appendChild(bk);
    }
    var ol = el('ol', 'org-crumbs');
    // Deep paths condense: the root, a "…" that opens the middle, the parent and here (Tableau drill-down practice).
    var fold = c.length > 3 && !pathOpen;
    c.forEach(function (x, i) {
      var item = el('li');
      if (fold && i > 0 && i < c.length - 2) {
        if (i === 1) { var m = el('button', 'org-fold', '…'); m.type = 'button'; m.setAttribute('aria-label', 'Show ' + (c.length - 3) + ' more levels of the path'); m.setAttribute('data-org-unfold', ''); item.appendChild(m); ol.appendChild(item); }
        return;
      }
      if (i === c.length - 1) { var s = el('span', '', x._title); s.setAttribute('aria-current', 'location'); item.appendChild(s); }
      else { var b = el('button', '', x._title); b.type = 'button'; b.setAttribute('data-org-drill', key(x)); item.appendChild(b); }
      ol.appendChild(item);
    });
    pathNav.appendChild(ol);
  }
  var pathOpen = false;
  pathNav.addEventListener('click', function (e) {
    if (e.target.closest('[data-org-unfold]')) { pathOpen = true; renderPath(byKey[focusKey]); var f = pathNav.querySelectorAll('.org-crumbs button')[1]; if (f) f.focus(); return; }
    var b = e.target.closest('[data-org-drill]'); if (b) drill(b.getAttribute('data-org-drill'));
  });

  /* ── Clicks on the list (navigator and outline) ────────────────── */
  list.addEventListener('click', function (e) {
    var p = e.target.closest('.org-pick');
    if (p) {
      var l = p.closest('.org-li'), k = key(l);
      if (mode === 'nav' && l.hasAttribute('data-org-has') && k !== focusKey) drill(k);
      else select(k);
      return;
    }
    var a = e.target.closest('.org-about a[href^="#org-"]');
    if (a && byKey[a.getAttribute('href').slice(5)]) { e.preventDefault(); select(a.getAttribute('href').slice(5)); }
  });

  /* ── Toolbar ───────────────────────────────────────────────────── */
  bar.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    if (b.hasAttribute('data-org-all')) {
      // Expand all opens every group to its offices and units; pressed again, every team too
      // (a team is wide: seven of them side by side would shrink the whole chart past reading).
      var all = b.getAttribute('data-org-all') === 'open', groups = rows.filter(function (r) { return NODE[r.id].group; });
      var units = groups.filter(function (r) { return /^unit-/.test(r.id); }), rest = groups.filter(function (r) { return !/^unit-/.test(r.id); });
      var teams = all && rest.every(function (r) { return open[r.id]; });
      open = {};
      if (all) (teams ? groups : rest).forEach(function (r) { open[r.id] = true; });
      else if (sel) reveal(sel);
      refresh(); fitView(true);
      say(!all ? 'Groups collapsed to the governance spine.' : teams ? 'Every group and every team is open.' : 'Every group is open. Press Expand all again to open each team as well (' + units.length + ').');
    } else if (b.hasAttribute('data-org-lines')) {
      linesOn = !linesOn; b.setAttribute('aria-pressed', String(linesOn));
      refresh();
      say(linesOn ? 'Independent lines shown.' : 'Independent lines hidden.');
    } else if (b.hasAttribute('data-org-fit')) {
      fitView(true); say('The whole chart is in view.');
    } else if (b.hasAttribute('data-org-png')) {
      exportPng(b);
    } else if (b.hasAttribute('data-org-text')) {
      textOn = !textOn; b.setAttribute('aria-pressed', String(textOn));
      pickMode();
      say(textOn ? 'Text version: the whole structure as an outline.' : 'Chart view.');
    }
  });
  legend.querySelector('[data-org-kbd]').addEventListener('click', function () { select(sel || key(root)); });

  /* ── Find: typo-tolerant, the People page's matcher ────────────── */
  function norm(s) { s = String(s || '').toLowerCase(); return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s; }
  function within1(a, b) {
    if (a === b) return true;
    var la = a.length, lb = b.length; if (Math.abs(la - lb) > 1) return false;
    var i = 0, j = 0, ed = 0;
    while (i < la && j < lb) { if (a[i] === b[j]) { i++; j++; continue; } if (++ed > 1) return false; if (la > lb) i++; else if (lb > la) j++; else { i++; j++; } }
    return ed + (la - i) + (lb - j) <= 1;
  }
  function wordHit(w, t) {
    if (t.indexOf(w) === 0) return w.length;
    if (w.length < 5) return 0;
    if (within1(w, t)) return t.length;
    for (var n = w.length - 1; n <= w.length + 1; n++) if (n < t.length && within1(w, t.slice(0, n))) return n;
    return 0;
  }
  lis.forEach(function (li) { li._q = norm(li.getAttribute('data-org-q')); li._tok = li._q.split(/[^a-z0-9]+/).filter(Boolean); li._tq = norm(li._title); });
  function hit(li, w) {
    if (li._q.indexOf(w) !== -1) return true;
    if (w.length < 5) return false;
    for (var i = 0; i < li._tok.length; i++) if (wordHit(w, li._tok[i])) return true;
    return false;
  }
  var input = document.getElementById('org-q'), res = document.getElementById('org-res'), timer = 0, found = [];
  function mark(text, words) {
    var f = norm(text), frag = document.createDocumentFragment(), ranges = [];
    words.forEach(function (w) {
      var p = f.indexOf(w); if (p !== -1) ranges.push([p, p + w.length]);
      else if (w.length >= 5) { var re = /[a-z0-9]+/g, m; while ((m = re.exec(f))) if (wordHit(w, m[0])) ranges.push([m.index, m.index + m[0].length]); }
    });
    ranges.sort(function (a, b) { return a[0] - b[0]; });
    var last = 0;
    ranges.forEach(function (r) { if (r[0] < last) return; if (r[0] > last) frag.appendChild(document.createTextNode(text.slice(last, r[0]))); frag.appendChild(el('mark', '', text.slice(r[0], r[1]))); last = r[1]; });
    if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
    return frag;
  }
  function search() {
    var raw = input.value.trim(), q = norm(raw), words = q.split(/\s+/).filter(Boolean);
    res.textContent = ''; found = [];
    if (!words.length) { res.hidden = true; live.textContent = ''; return; }
    lis.forEach(function (li) {
      if (!words.every(function (w) { return hit(li, w); })) return;
      var s = li._tq.indexOf(q) === 0 ? 0 : li._tq.indexOf(q) !== -1 ? 1 : words.every(function (w) { return li._tq.indexOf(w) !== -1; }) ? 2 : 3;
      found.push([s, li]);
    });
    found.sort(function (a, b) { return a[0] - b[0]; });
    found = found.slice(0, 8).map(function (x) { return x[1]; });
    if (!found.length) { res.appendChild(el('li', 'org-res-none', 'Nothing matches “' + raw + '”.')); res.hidden = false; say('No match for ' + raw + '.'); return; }
    found.forEach(function (li) {
      var i = el('li'), b = el('button'); b.type = 'button'; b.setAttribute('data-org-go', key(li));
      b.appendChild(mark(li._title, words));
      var p = upLi(li), names = li.querySelectorAll(':scope > .org-about .org-who-n'), who = [].map.call(names, function (n) { return n.textContent; }).join(', ');
      var sm = el('small'); if (who) { sm.appendChild(mark(who, words)); sm.appendChild(document.createTextNode(p ? ' · ' : '')); }
      if (p) sm.appendChild(document.createTextNode('under ' + p._title));
      b.appendChild(sm); i.appendChild(b); res.appendChild(i);
    });
    res.hidden = false;
    say(found.length + (found.length === 1 ? ' match: ' : ' matches. First: ') + found[0]._title + '. Press Enter to show it, or the down arrow to choose.');
  }
  function choose(k) {
    res.hidden = true;
    lis.forEach(function (x) { x.classList.remove('is-hit'); });
    byKey[k].classList.add('is-hit');
    retFocus = input;
    select(k);
  }
  input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 140); });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); search(); if (found[0]) choose(key(found[0])); }
    else if (e.key === 'ArrowDown') { var f = res.querySelector('button'); if (f && !res.hidden) { e.preventDefault(); f.focus(); } }
    else if (e.key === 'Escape') { if (!res.hidden) { e.preventDefault(); res.hidden = true; } }
  });
  res.addEventListener('click', function (e) { var b = e.target.closest('[data-org-go]'); if (b) choose(b.getAttribute('data-org-go')); });
  res.addEventListener('keydown', function (e) {
    var bs = [].slice.call(res.querySelectorAll('button')), i = bs.indexOf(document.activeElement);
    if (e.key === 'ArrowDown' && i < bs.length - 1) { e.preventDefault(); bs[i + 1].focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); (i > 0 ? bs[i - 1] : input).focus(); }
    else if (e.key === 'Escape') { e.preventDefault(); res.hidden = true; input.focus(); }
  });
  document.addEventListener('click', function (e) { if (!res.hidden && !e.target.closest('.org-find')) res.hidden = true; });

  /* ── Deep links ────────────────────────────────────────────────── */
  function openHash(first) {
    var id = decodeURIComponent((location.hash || '').slice(1));
    if (id.indexOf('org-') !== 0) return;
    var k = id.slice(4), li = byKey[k];
    if (!li) return;
    if (mode === 'nav') {
      if (li.hasAttribute('data-org-has')) drill(k, { quiet: !!first });
      else { drill(key(upLi(li)), { quiet: true }); lis.forEach(function (x) { x.classList.remove('is-hit'); }); li.classList.add('is-hit'); sel = k; paintSel(); }
      if (first) li.scrollIntoView({ block: 'center' });
    } else {
      select(k, { focus: !first, animate: !first });
      if (first && mode === 'chart') stage.scrollIntoView({ block: 'start' });
    }
  }
  window.addEventListener('hashchange', function () { openHash(false); });

  /* ── Print: a fitted copy of the chart, every group open (not each team), on one page ─ */
  var printBox = el('div', 'org-print'); printBox.setAttribute('aria-hidden', 'true'); body.appendChild(printBox);
  function beforePrint() {
    if (printing) return;
    printing = { mode: mode };
    if (mode === 'chart' && chart) {
      var keep = open; open = {};
      rows.forEach(function (r) { if (NODE[r.id].group && !/^unit-/.test(r.id)) open[r.id] = true; });
      var keepSel = sel; sel = null; var keepLines = linesOn; linesOn = false;
      refresh({ instant: true });
      var c = snapshot(''); c.removeAttribute('width'); c.removeAttribute('height');
      printBox.textContent = ''; printBox.appendChild(c);
      open = keep; sel = keepSel; linesOn = keepLines;
      refresh({ instant: true });
      stage.classList.add('is-print');
    } else {
      // The navigator prints as the outline.
      stage.classList.remove('is-nav'); stage.classList.add('is-print-text');
    }
  }
  function afterPrint() {
    if (!printing) return;
    stage.classList.remove('is-print', 'is-print-text');
    if (printing.mode === 'nav') stage.classList.add('is-nav');
    printing = null; printBox.textContent = '';
  }
  window.addEventListener('beforeprint', beforePrint);
  window.addEventListener('afterprint', afterPrint);

  /* ── Start ─────────────────────────────────────────────────────── */
  [].forEach.call(bar.querySelectorAll('.org-find,[data-org-text]'), function (x) { x.hidden = false; });
  pickMode();
  openHash(true);
  var lastW = 0, lastH = 0;
  function resized() {
    if (printing) return;
    pickMode();
    if (mode === 'chart' && chart) {
      var w = viz.clientWidth, h = viz.clientHeight;
      if (w === lastW && h === lastH) return;
      lastW = w; lastH = h;
      var st = chart.getChartState(); chart.svgHeight(h); st.svg.attr('width', w).attr('height', h); st.svgWidth = w;
      if (!userZoomed && !sel) fitView(false);
    }
  }
  if ('ResizeObserver' in window) new ResizeObserver(resized).observe(stage);
  else window.addEventListener('resize', resized);
  document.addEventListener('av:a11y', function () { setTimeout(function () { pickMode(); if (mode === 'chart') refresh({ instant: true }); }, 50); });
})();
