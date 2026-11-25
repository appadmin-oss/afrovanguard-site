/* assets/site/launcher-tuck.js — how a floating launcher steps aside.
 *
 * Both corner launchers use it: the Accessibility button (a11y.js, bottom
 * left) and Chioma's (chioma.js, bottom right). One rule for both, so the two
 * corners behave alike and a fix to one is a fix to the other:
 *
 *   READING DOWN A PAGE: scrolling down past 80px it slides off its edge
 *   (data-tuck on the button; the stylesheet draws the slide), still in the
 *   tab order. It comes back on the first scroll up and, from 600px, about
 *   900ms after the scrolling stops. On a phone it stays away until the reader
 *   scrolls up — coming back at every pause put it over a row's button mid-read.
 *
 *   NEVER OVER A CONTROL: no fixed spot is clear on every page, so it asks the
 *   page. When the scrolling settles, on load, on resize and once more after
 *   entrance animations it samples nine points of its RESTING box (offsets
 *   ignore the tuck's transform), and while a link, button or field is under
 *   any of them it stays tucked until the spot is clear. The launchers
 *   themselves, and whatever the caller says to ignore (its own panel), do not
 *   count.
 *
 *   NEVER WHILE IT MATTERS: never tucked while it has the keyboard's focus, nor
 *   while the caller holds it (Chioma's panel is open). focus-visible in the
 *   stylesheet brings a tucked one straight back.
 *
 *   AvTuck(button, { scope, ignore, hold, onTuck }) → { look, paint }
 *     scope   the launcher's whole widget (default: the button); ignored by
 *             every launcher's control check
 *     ignore  function (el) → true for elements under the spot that don't count
 *     hold    function () → true while it must stay out
 *     onTuck  called when it tucks
 *
 * Reduced motion, "Stop moving content", right-to-left and print are the
 * stylesheets' (a11y.css, chioma.css). Vanilla ES5-ish, no dependencies.
 */
(function () {
  'use strict';
  if (window.AvTuck) return;
  var CONTROL = 'a[href], button, input, select, textarea, summary, label, [role="button"], [role="link"], [tabindex]:not([tabindex="-1"])';
  var PHONE = '(max-width: 599px)';

  /* The resting box in viewport pixels: offsets up to the fixed ancestor. */
  function resting(el) {
    var x = 0, y = 0, e = el;
    while (e) { x += e.offsetLeft; y += e.offsetTop; e = e.offsetParent; }
    return { x: x, y: y, w: el.offsetWidth, h: el.offsetHeight };
  }

  function AvTuck(fab, opts) {
    opts = opts || {};
    var scope = opts.scope || fab;
    scope.setAttribute('data-av-launcher', '');
    var reading = false, blocked = false;
    var held = function () { return !!(opts.hold && opts.hold()); };

    function overControl() {
      if (!fab.getClientRects().length || !document.elementsFromPoint) return false;
      var b = resting(fab);
      if (!b.w || !b.h) return false;
      var xs = [b.x + 3, b.x + b.w / 2, b.x + b.w - 3], ys = [b.y + 3, b.y + b.h / 2, b.y + b.h - 3];
      for (var i = 0; i < 3; i++) for (var j = 0; j < 3; j++) {
        var under = document.elementsFromPoint(xs[i], ys[j]);
        for (var k = 0; k < under.length; k++) {
          var e = under[k];
          if (!e.closest || e.closest('[data-av-launcher]') || (opts.ignore && opts.ignore(e))) continue;
          if (e.closest(CONTROL)) return true;
        }
      }
      return false;
    }
    function paint() {
      var was = fab.hasAttribute('data-tuck');
      if ((reading || blocked) && document.activeElement !== fab && !held()) {
        fab.setAttribute('data-tuck', '');
        if (!was && opts.onTuck) opts.onTuck();
      } else fab.removeAttribute('data-tuck');
    }
    function look() { blocked = overControl(); paint(); }

    var phone = window.matchMedia ? window.matchMedia(PHONE) : null;
    var lastY = window.pageYOffset, settle = 0, again = 0, upFrame = 0;
    window.addEventListener('scroll', function () {
      var y = window.pageYOffset, dy = y - lastY;
      lastY = y;
      if (Math.abs(dy) < 4) return;
      reading = dy > 0 && y > 80;
      /* Going up, ask the page again (once a frame): what blocked the spot further
         down may have scrolled away, and it should come back now, not at the pause. */
      if (dy < 0 && window.requestAnimationFrame) { if (!upFrame) upFrame = requestAnimationFrame(function () { upFrame = 0; look(); }); }
      else paint();
      clearTimeout(settle);
      settle = setTimeout(function () {
        if (!(phone && phone.matches && y > 80)) reading = false;
        look();
      }, 900);
    }, { passive: true });
    fab.addEventListener('focus', function () { fab.removeAttribute('data-tuck'); });
    fab.addEventListener('blur', paint);
    window.addEventListener('resize', function () {
      if (!window.requestAnimationFrame) return look();
      cancelAnimationFrame(again); again = requestAnimationFrame(look);
    });
    if (window.requestAnimationFrame) requestAnimationFrame(look); else look();
    if (document.readyState !== 'complete') window.addEventListener('load', look);
    /* Once more after the page's entrance animations have put things in place. */
    setTimeout(look, 1600);
    return { look: look, paint: paint };
  }
  AvTuck.CONTROL = CONTROL;
  window.AvTuck = AvTuck;
})();
