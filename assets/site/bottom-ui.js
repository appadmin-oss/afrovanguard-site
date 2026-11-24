/* assets/site/bottom-ui.js — how far up the screen the page's bottom bars reach.
 *
 * Every fixed (or sticky) bottom element carries [data-bottom-ui]: the portal's
 * and the mentor portal's phone tab bars, the lesson player's bar, an appeal's
 * Give bar, the cookie notice. While one is on screen this sets --av-bottom-ui
 * on <body>; when it goes, the figure goes with it. The Accessibility button
 * (a11y.css) and Chioma's launcher (chioma.css) read that and nothing else:
 *   bottom: calc(var(--av-bottom-ui, 0px) + 16px + env(safe-area-inset-bottom))
 * Measured, never typed: a typed offset goes stale the day a bar gains a line.
 *
 * THE FIGURE is the distance from the viewport's bottom edge to the HIGHEST top
 * edge among the bars on screen, not the tallest bar's height: bars stack (the
 * cookie notice floats 16px up, above a tab bar), and that one number clears
 * them all. For a single bar flush with the bottom it is its height. A sticky
 * bar only counts while it is actually docked at the bottom of the screen.
 *
 * VISIBLE means getClientRects().length — not offsetParent, which is null for
 * every position:fixed element, the very ones measured here.
 *
 * MOUNT AND UNMOUNT are both seen (MutationObserver on childList, hidden,
 * class, style, data-open), each bar is watched for size (ResizeObserver), and
 * the window for resizes. It writes only when the figure changes: its own write
 * is a style attribute under the observed <body>, so an unconditional write
 * would trigger itself for ever.
 */
(function () {
  'use strict';
  if (window.AvBottomUI) return;
  var last = null, ro = null, watched = [];

  function set() {
    if (!document.body) return;
    var h = 0, vh = window.innerHeight || document.documentElement.clientHeight;
    var els = document.querySelectorAll('[data-bottom-ui]');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (ro && watched.indexOf(el) < 0) { ro.observe(el); watched.push(el); }
      if (!el.getClientRects().length) continue;
      var r = el.getBoundingClientRect();
      if (r.height <= 0 || r.top >= vh) continue;
      if (r.bottom < vh - 48) continue;   // a sticky bar resting mid-page is not at the bottom
      h = Math.max(h, Math.round(vh - r.top));
    }
    var v = Math.max(0, h) + 'px';
    if (v === last) return;
    last = v;
    document.body.style.setProperty('--av-bottom-ui', v);
  }

  function start() {
    if (window.ResizeObserver) ro = new ResizeObserver(set);
    if (window.MutationObserver) {
      new MutationObserver(set).observe(document.body, {
        subtree: true, childList: true,
        attributes: true, attributeFilter: ['hidden', 'class', 'style', 'data-open', 'data-bottom-ui']
      });
    }
    window.addEventListener('resize', set, { passive: true });
    /* A sticky bar docks and undocks as the page scrolls. */
    window.addEventListener('scroll', function () { if (window.requestAnimationFrame) requestAnimationFrame(set); else set(); }, { passive: true });
    set();
  }
  window.AvBottomUI = { measure: set };
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
})();
