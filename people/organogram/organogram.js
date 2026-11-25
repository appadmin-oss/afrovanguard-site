/* people/organogram/organogram.js — /people/organogram/ made interactive.
 *
 * The page is complete without it: one nested list, every node's words beside it
 * (the text outline is the primary version). This script draws the SAME list three
 * ways, by the class it puts on .org-stage:
 *
 *   .is-tree  the chart's own width allows it (--org-mode from a container query) and
 *             text is normal size: a lean top-down tree. The governance spine is open;
 *             the groups (Board committees, Chief Officers, Directorates, Institutions,
 *             each Directorate's team) start collapsed behind disclosure buttons
 *             (aria-expanded; not the ARIA tree pattern — the APG navigation-tree note). Selecting
 *             a node opens the details panel beside the tree, draws its chain of
 *             accountability up to the General Assembly and its independent lines, and
 *             steps the rest back. "Show independent lines" draws every dashed line,
 *             each end at the nearest visible box. Wider than its region: the region
 *             pans; "Fit to width" scales it down.
 *   .is-nav   narrow charts, or larger text: a drill-down navigator. One level of
 *             cards; a card with something below goes in, a leaf opens its details as
 *             a bottom sheet; the path is always shown as a breadcrumb and Back is
 *             always one level up.
 *   (none)    "Text version": the full outline again.
 *
 * Find: a typo-tolerant search (the People page's matcher) over titles, offices and
 * listed names; choosing a match opens its path and selects it. Deep links: #org-<key>
 * selects and reveals a node; selecting writes the hash with replaceState, so a view
 * can be shared and Back still leaves the page. Print opens every group first.
 */
(function () {
  'use strict';
  var stage = document.querySelector('[data-org-stage]');
  if (!stage) return;
  var chart = stage.querySelector('.org-chart'), scroller = stage.querySelector('.org-scroll'), body = stage.querySelector('.org-body');
  var svg = stage.querySelector('.org-svg'), panel = document.getElementById('org-panel'), pathNav = stage.querySelector('[data-org-path]');
  var bar = document.querySelector('[data-org-bar]'), live = document.getElementById('org-live');
  var NS = 'http://www.w3.org/2000/svg';
  var TIER = { gov: 'Governance', exec: 'Executive', ops: 'Operations' };
  var lis = [].slice.call(chart.querySelectorAll('.org-li')), byKey = {};
  var root = lis[0];
  var mode = '', sel = null, focusKey = root.getAttribute('data-org-key'), linesOn = false, fitOn = false, textOn = false, printing = null;

  /* ── Small helpers ──────────────────────────────────────────────── */
  function key(li) { return li.getAttribute('data-org-key'); }
  function nodeOf(li) { return li.querySelector(':scope > .org-node'); }
  function upLi(li) { return li.parentElement ? li.parentElement.closest('.org-li') : null; }
  function kidsUl(li) { return li.querySelector(':scope > .org-kids'); }
  function kids(li) { var u = kidsUl(li); return u ? [].slice.call(u.children) : []; }
  function chain(li) { var a = []; for (var p = li; p; p = upLi(p)) a.push(p); return a; }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function say(msg) { live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 30); }
  function face(li) { var f = li.querySelector(':scope > .org-node .org-face-n'); return f ? f.firstChild.textContent.trim() : ''; }
  function count(li) { var m = li.querySelector(':scope > .org-node .org-meta'); return m ? m.textContent : ''; }

  /* ── Enhance each node: a pick button on the title, a disclosure button on groups ─ */
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
    if (li.hasAttribute('data-org-collapsible')) {
      var g = el('button', 'org-tog'); g.type = 'button';
      g.setAttribute('aria-expanded', 'false'); g.setAttribute('aria-controls', kidsUl(li).id);
      g.appendChild(el('span', '', count(li)));
      var sr = el('span', 'av-sr', ', under ' + li._title); g.firstChild.appendChild(sr);
      nodeOf(li).appendChild(g); li._tog = g;
      li.classList.add('is-closed');
    }
  });

  /* ── Modes ──────────────────────────────────────────────────────── */
  function cssMode() { return (getComputedStyle(chart).getPropertyValue('--org-mode') || '').trim() === 'tree' ? 'tree' : 'nav'; }
  function setMode(m) {
    if (m === mode) return;
    var wasOpen = panel.open;
    if (wasOpen) panel.close();
    mode = m;
    stage.classList.toggle('is-tree', m === 'tree');
    stage.classList.toggle('is-nav', m === 'nav');
    [].forEach.call(bar.querySelectorAll('[data-org-all],[data-org-lines]'), function (x) { x.hidden = m !== 'tree'; });
    if (m !== 'tree') bar.querySelector('[data-org-fit]').hidden = true;
    chart.style.removeProperty('--org-z');
    pathNav.hidden = m !== 'nav';
    var lg = document.getElementById('org-legend'); if (lg) lg.hidden = m === 'nav';
    lis.forEach(function (li) {
      var x = li._pick.querySelector('.org-pick-x'); x.textContent = '';
      if (m === 'nav') {
        if (key(li) === focusKey) x.textContent = 'Details';
        else x.appendChild(el('span', 'av-sr', li.hasAttribute('data-org-has') ? ': see what is below' : ', details'));
      } else if (m === 'tree') x.appendChild(el('span', 'av-sr', ', details'));
    });
    if (m === 'nav') drill(sel ? (byKey[sel].hasAttribute('data-org-has') && sel !== focusKey ? key(upLi(byKey[sel]) || byKey[sel]) : focusKey) : focusKey, { quiet: true });
    if (m === 'tree' && sel) reveal(byKey[sel]);
    paintSel();
    if (wasOpen && sel && m !== 'text') openPanel(sel, { focus: false });
    relayout();
  }
  function pickMode() { setMode(textOn ? 'text' : cssMode()); }

  /* ── Tree: fit, reveal, draw ───────────────────────────────────── */
  var fitBtn = bar.querySelector('[data-org-fit]');
  function zoom() { return parseFloat(chart.style.getPropertyValue('--org-z')) || 1; }
  function fit() {
    if (mode !== 'tree') return;
    chart.style.setProperty('--org-z', '1');
    var natW = chart.getBoundingClientRect().width, avail = scroller.clientWidth - 8;
    var z = Math.min(1, avail / natW);
    if (!fitOn) z = Math.max(0.85, z);
    chart.style.setProperty('--org-z', String(Math.round(z * 1000) / 1000));
    fitBtn.hidden = !(fitOn || natW * 0.85 > avail + 1);
  }
  function reveal(li) {
    chain(li).slice(1).forEach(function (p) { if (p.classList.contains('is-closed')) setOpen(p, true); });
  }
  function setOpen(li, open) {
    if (!li._tog) return;
    li.classList.toggle('is-closed', !open);
    li._tog.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  function visible(li) { while (li && !nodeOf(li).getClientRects().length) li = upLi(li); return li; }
  function rel(r, base, z) { return { l: (r.left - base.left) / z, t: (r.top - base.top) / z, r: (r.right - base.left) / z, b: (r.bottom - base.top) / z }; }
  function path(d, cls) { var p = document.createElementNS(NS, 'path'); p.setAttribute('d', d); p.setAttribute('class', cls); svg.appendChild(p); }
  function dot(x, y) { var c = document.createElementNS(NS, 'circle'); c.setAttribute('cx', x); c.setAttribute('cy', y); c.setAttribute('r', 3); svg.appendChild(c); }
  function lines() {
    var seen = {}, out = [];
    lis.forEach(function (li) {
      [].forEach.call(li.querySelectorAll(':scope > .org-about .org-ind [data-org-to]'), function (x) {
        var a = key(li), b = x.getAttribute('data-org-to'), id = a < b ? a + '|' + b : b + '|' + a;
        if (byKey[b] && !seen[id]) { seen[id] = 1; out.push([li, byKey[b]]); }
      });
    });
    return out;
  }
  var allLines = null;
  function draw() {
    while (svg.firstChild) svg.removeChild(svg.firstChild);
    lis.forEach(function (li) { li.classList.remove('on-link'); });
    if (mode !== 'tree') return;
    var z = zoom(), base = chart.getBoundingClientRect();
    var R = function (e) { return rel(e.getBoundingClientRect(), base, z); };
    svg.setAttribute('width', base.width / z); svg.setAttribute('height', base.height / z);
    if (sel) {
      var c = chain(byKey[sel]);
      for (var i = 0; i < c.length - 1; i++) {
        var ch = c[i], pa = c[i + 1];
        if (ch.hasAttribute('data-org-beside')) continue;
        var pn = R(nodeOf(pa)), cn = R(nodeOf(ch)), lay = kidsUl(pa).getAttribute('data-org-layout');
        if (lay === 'stack') { var x = R(ch).l; path('M' + x + ' ' + pn.b + ' V' + (cn.t + 18) + ' H' + cn.l, 'org-chain'); }
        else { var px = (pn.l + pn.r) / 2, cx = (cn.l + cn.r) / 2, by = R(ch).t; path('M' + px + ' ' + pn.b + ' V' + by + ' H' + cx + ' V' + cn.t, 'org-chain'); }
      }
    }
    allLines = allLines || lines();
    var done = {};
    allLines.forEach(function (pr) {
      var mine = sel && (key(pr[0]) === sel || key(pr[1]) === sel);
      if (!linesOn) return;   // dashed lines only when asked for; the panel says them in words
      // A Directorate Finance Officer's own line only while its team is open; otherwise the CFO's line to the Directorates says it.
      if (pr.some(function (x) { return /-finance$/.test(key(x)) && !nodeOf(x).getClientRects().length; })) return;
      var a = visible(pr[0]), b = visible(pr[1]);
      if (!a || !b || a === b || a.contains(b) || b.contains(a)) return;
      var id = key(a) < key(b) ? key(a) + '|' + key(b) : key(b) + '|' + key(a);
      if (done[id] && !mine) return; done[id] = 1;
      if (mine) { a.classList.add('on-link'); b.classList.add('on-link'); }
      var A = R(nodeOf(a)), B = R(nodeOf(b)), ay = A.t + Math.min(22, (A.b - A.t) / 2), by2 = B.t + Math.min(22, (B.b - B.t) / 2), d;
      var gapX = B.l >= A.r ? B.l - A.r : A.l >= B.r ? A.l - B.r : 0, gapY = B.t > A.b ? B.t - A.b : A.t > B.b ? A.t - B.b : 0;
      if (gapX > 0 && gapY > gapX * 1.2) {
        // far above or below: leave from the side, arrive on the near edge's middle with an upright tangent
        var sx = B.l >= A.r ? A.r : A.l, ex = (B.l + B.r) / 2, ey = B.t > A.b ? B.t : B.b, k = Math.min(80, gapY / 2) * (B.t > A.b ? -1 : 1);
        d = 'M' + sx + ' ' + ay + ' C' + (sx + (ex > sx ? 40 : -40)) + ' ' + ay + ',' + ex + ' ' + (ey + k) + ',' + ex + ' ' + ey;
        dot(sx, ay); dot(ex, ey);
      } else if (B.l >= A.r || A.l >= B.r) {
        var ax = B.l >= A.r ? A.r : A.l, bx = B.l >= A.r ? B.l : B.r, dx = Math.max(30, Math.abs(bx - ax) / 2) * (bx > ax ? 1 : -1);
        d = 'M' + ax + ' ' + ay + ' C' + (ax + dx) + ' ' + ay + ',' + (bx - dx) + ' ' + by2 + ',' + bx + ' ' + by2;
        dot(ax, ay); dot(bx, by2);
      } else {
        var o = Math.max(A.r, B.r) + 30;
        d = 'M' + A.r + ' ' + ay + ' C' + o + ' ' + ay + ',' + o + ' ' + by2 + ',' + B.r + ' ' + by2;
        dot(A.r, ay); dot(B.r, by2);
      }
      path(d, 'org-indl' + (mine ? ' is-mine' : ''));
    });
  }
  var raf = 0;
  function relayout() { cancelAnimationFrame(raf); raf = requestAnimationFrame(function () { fit(); draw(); }); }

  /* ── Selection and the details panel ───────────────────────────── */
  function paintSel() {
    lis.forEach(function (li) { li.classList.remove('is-sel', 'on-chain'); });
    chart.classList.toggle('has-sel', !!sel && mode === 'tree');
    if (!sel) return;
    byKey[sel].classList.add('is-sel');
    if (mode === 'tree') chain(byKey[sel]).forEach(function (li) { li.classList.add('on-chain'); });
  }
  function setHash(k) {
    var u = location.pathname + location.search + (k ? '#org-' + k : '');
    try { history.replaceState(history.state, '', u); } catch (e) {}
  }
  function select(k, o) {
    o = o || {};
    var li = byKey[k]; if (!li) return;
    sel = k;
    if (mode === 'tree') reveal(li);
    if (mode === 'nav') { var p = upLi(li); drill(p ? key(p) : k, { quiet: true, keepSel: true }); }
    if (mode === 'text') { lis.forEach(function (x) { x.classList.remove('is-hit'); }); }
    paintSel();
    setHash(k);
    if (mode === 'text') { li.scrollIntoView({ block: 'start' }); li.querySelector('.org-t').focus({ preventScroll: true }); return; }
    if (mode === 'tree') { openPanel(k, o); fit(); draw(); scrollTo(li); }
    else openPanel(k, o);
  }
  function scrollTo(li) {
    var n = nodeOf(li), r = n.getBoundingClientRect(), s = scroller.getBoundingClientRect();
    if (r.left < s.left + 8 || r.right > s.right - 8) scroller.scrollLeft += (r.left + r.width / 2) - (s.left + s.width / 2);
    r = n.getBoundingClientRect();
    if (r.top < 90 || r.bottom > window.innerHeight - 20) window.scrollBy(0, r.top - Math.max(110, window.innerHeight / 3));
  }
  function clearSel(back) {
    var was = sel; sel = null;
    if (panel.open) panel.close();
    body.classList.remove('has-panel');
    paintSel(); setHash(mode === 'nav' && focusKey !== key(root) ? focusKey : null); relayout();
    if (back && was && byKey[was] && byKey[was]._pick.getClientRects().length) byKey[was]._pick.focus();
  }
  function jump(k, label) {
    var b = el('button', 'org-jump'); b.type = 'button'; b.setAttribute('data-org-go', k);
    b.appendChild(document.createTextNode(label || byKey[k]._title));
    return b;
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
  }
  function openPanel(k, o) {
    o = o || {};
    buildPanel(k);
    if (mode === 'tree') {
      body.classList.add('has-panel');
      if (!panel.open) panel.show();
    } else if (mode === 'nav') {
      if (panel.open && !panel.matches(':modal')) panel.close();
      if (!panel.open) panel.showModal();
    }
    panel.scrollTop = 0;
    if (o.focus !== false) document.getElementById('org-panel-h').focus({ preventScroll: mode === 'tree' });
    else if (mode === 'tree') say('Showing ' + byKey[k]._title + '.');
  }
  panel.addEventListener('click', function (e) {
    var g = e.target.closest('[data-org-go]');
    if (g) { var k = g.getAttribute('data-org-go'); select(k); return; }
    if (e.target.closest('[data-org-close]')) clearSel(true);
  });
  panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); clearSel(true); } });
  panel.addEventListener('close', function () { if (sel && !panel.open && mode !== 'text') { var k = sel; setTimeout(function () { if (!panel.open && sel === k) clearSel(true); }, 0); } });

  /* ── Navigator: one level at a time ────────────────────────────── */
  function drill(k, o) {
    o = o || {};
    var li = byKey[k]; if (!li) return;
    if (!li.hasAttribute('data-org-has')) li = upLi(li) || li;
    focusKey = key(li); pathOpen = false;
    lis.forEach(function (x) { x.classList.remove('is-path', 'is-focus'); });
    chain(li).forEach(function (x) { x.classList.add('is-path'); });
    li.classList.add('is-focus');
    if (mode === 'nav') {
      lis.forEach(function (x) {
        var px = x._pick.querySelector('.org-pick-x'); px.textContent = '';
        if (x === li) px.textContent = 'Details';
        else px.appendChild(el('span', 'av-sr', x.hasAttribute('data-org-has') ? ': see what is below' : ', details'));
      });
    }
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

  /* ── Clicks on the chart ───────────────────────────────────────── */
  chart.addEventListener('click', function (e) {
    var g = e.target.closest('.org-tog');
    if (g) {
      var li = g.closest('.org-li'), open = li.classList.contains('is-closed');
      setOpen(li, open);
      say(li._title + ': ' + count(li) + (open ? ', shown.' : ', hidden.'));
      relayout(); return;
    }
    var p = e.target.closest('.org-pick');
    if (p) {
      var l = p.closest('.org-li'), k = key(l);
      if (mode === 'nav' && l.hasAttribute('data-org-has') && k !== focusKey) drill(k);
      else if (mode === 'tree' && sel === k && panel.open) clearSel(true);
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
      var open = b.getAttribute('data-org-all') === 'open';
      lis.forEach(function (li) { setOpen(li, open); });
      if (!open && sel) reveal(byKey[sel]);
      say(open ? 'Every group is open.' : 'Groups collapsed to the governance spine.');
      relayout();
    } else if (b.hasAttribute('data-org-lines')) {
      linesOn = !linesOn; b.setAttribute('aria-pressed', String(linesOn));
      say(linesOn ? 'Independent lines shown.' : 'Independent lines hidden.'); draw();
    } else if (b.hasAttribute('data-org-fit')) {
      fitOn = !fitOn; b.setAttribute('aria-pressed', String(fitOn)); relayout();
    } else if (b.hasAttribute('data-org-text')) {
      textOn = !textOn; b.setAttribute('aria-pressed', String(textOn));
      if (textOn && panel.open) { panel.close(); body.classList.remove('has-panel'); }
      pickMode();
      say(textOn ? 'Text version: the whole structure as an outline.' : 'Chart view.');
    }
  });

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
    } else select(k, { focus: !first });
  }
  window.addEventListener('hashchange', function () { openHash(false); });

  /* ── Print: the whole tree, every group open (not each team), on one page ─ */
  function beforePrint() {
    printing = { mode: mode, closed: lis.filter(function (li) { return li.classList.contains('is-closed'); }) };
    stage.classList.remove('is-nav'); stage.classList.add('is-tree', 'is-print'); mode = 'tree';
    lis.forEach(function (li) { if (!/^unit-/.test(key(li))) setOpen(li, true); });
    var coo = document.getElementById('org-k-coo'); if (coo) coo.setAttribute('data-org-layout', 'row');   // on paper the two groups sit side by side
    chart.style.setProperty('--org-z', '1');
    var r = chart.getBoundingClientRect();
    chart.style.setProperty('--org-print-z', String(Math.min(1, 1060 / r.width, 640 / r.height).toFixed(3)));
  }
  function afterPrint() {
    if (!printing) return;
    lis.forEach(function (li) { setOpen(li, printing.closed.indexOf(li) === -1); });
    var coo = document.getElementById('org-k-coo'); if (coo) coo.setAttribute('data-org-layout', 'stack');
    stage.classList.remove('is-print'); var m = printing.mode; printing = null; mode = ''; setMode(m);
  }
  window.addEventListener('beforeprint', beforePrint);
  window.addEventListener('afterprint', afterPrint);
  var pbtn = document.querySelector('[data-org-print]');
  if (pbtn) { pbtn.hidden = false; pbtn.addEventListener('click', function () { window.print(); }); }

  /* ── Start ─────────────────────────────────────────────────────── */
  bar.hidden = false;
  pickMode();
  openHash(true);
  if ('ResizeObserver' in window) new ResizeObserver(function () { if (!printing) { pickMode(); relayout(); } }).observe(stage);
  else window.addEventListener('resize', function () { pickMode(); relayout(); });
  document.addEventListener('av:a11y', function () { setTimeout(function () { pickMode(); relayout(); }, 50); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(relayout);
  window.addEventListener('load', relayout);
})();
