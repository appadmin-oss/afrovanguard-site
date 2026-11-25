/* people/organogram/organogram.js — the extras on /people/organogram/. The page is
 * complete without it: the structure is a nested list, the expanders are <details>,
 * and every independent line is written out at both of its ends.
 *
 *   - Dashed connectors. On the desktop tree (and when printing) it draws a dashed
 *     line between the two ends of each independent line that sit side by side
 *     (data-org-pair on the notes), in one aria-hidden SVG laid over the chart.
 *     Redrawn on resize, when a box opens or closes, and before printing.
 *   - Print. Reveals the Print button; printing opens every box first and puts
 *     each back as it was afterwards.
 *   - A link to one Directorate (#org-unit-…) opens it.
 */
(function () {
  'use strict';
  var chart = document.querySelector('.org-chart');
  if (!chart) return;
  var svg = chart.querySelector('.org-dash');
  var NS = 'http://www.w3.org/2000/svg';

  function treeMode() { return svg && getComputedStyle(svg).display !== 'none'; }

  function draw() {
    if (!svg) return;
    while (svg.firstChild) svg.removeChild(svg.firstChild);
    if (!treeMode()) return;
    var base = chart.getBoundingClientRect();
    var z = parseFloat(getComputedStyle(chart).zoom) || 1;
    var seen = {}, n = 0;
    [].slice.call(chart.querySelectorAll('[data-org-pair][data-org-end="from"]')).forEach(function (a) {
      var key = a.getAttribute('data-org-pair');
      if (seen[key]) return; seen[key] = 1;
      var b = chart.querySelector('[data-org-pair="' + key + '"][data-org-end="to"]');
      if (!b || !a.getClientRects().length || !b.getClientRects().length) return;
      var ra = a.getBoundingClientRect(), rb = b.getBoundingClientRect();
      var ax, bx, ay = (ra.top + ra.height / 2 - base.top) / z, by = (rb.top + rb.height / 2 - base.top) / z;
      if (rb.left >= ra.right) { ax = (ra.right - base.left) / z; bx = (rb.left - base.left) / z; }
      else if (ra.left >= rb.right) { ax = (ra.left - base.left) / z; bx = (rb.right - base.left) / z; }
      else { // one above the other: leave from the side and come back in
        ax = (ra.right - base.left) / z; bx = (rb.right - base.left) / z;
        var out = Math.max(ax, bx) + 14;
        path('M' + ax + ' ' + ay + ' H' + out + ' V' + by + ' H' + bx); return;
      }
      var mx = (ax + bx) / 2 + (n++ % 3 - 1) * 7; // side-by-side lines do not share one upright
      path('M' + ax + ' ' + ay + ' H' + mx + ' V' + by + ' H' + bx);
    });
    svg.setAttribute('width', chart.scrollWidth); svg.setAttribute('height', chart.scrollHeight);
  }
  function path(d) {
    var p = document.createElementNS(NS, 'path');
    p.setAttribute('d', d); p.setAttribute('stroke', 'currentColor'); p.setAttribute('fill', 'none');
    svg.appendChild(p);
  }

  var raf = 0;
  function later() { cancelAnimationFrame(raf); raf = requestAnimationFrame(draw); }
  window.addEventListener('resize', later);
  chart.addEventListener('toggle', later, true);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(later);
  window.addEventListener('load', later);
  later();

  /* Print: every box open while printing, then back as it was. */
  var closed = [];
  function before() {
    closed = [].slice.call(chart.querySelectorAll('details.org-group:not([open])'));
    closed.forEach(function (d) { d.open = true; });
    draw();
  }
  function after() { closed.forEach(function (d) { d.open = false; }); closed = []; later(); }
  window.addEventListener('beforeprint', before);
  window.addEventListener('afterprint', after);
  var btn = document.querySelector('[data-org-print]');
  if (btn) { btn.hidden = false; btn.addEventListener('click', function () { window.print(); }); }

  /* #org-unit-sts: open that Directorate. */
  function openHash() {
    var id = decodeURIComponent((location.hash || '').slice(1));
    if (!id) return;
    var el = document.getElementById(id);
    if (!el) return;
    var d = el.querySelector('details') || el.closest('details');
    for (var p = el; p; p = p.parentElement) if (p.tagName === 'DETAILS') p.open = true;
    if (d) d.open = true;
  }
  window.addEventListener('hashchange', openHash);
  openHash();
})();
