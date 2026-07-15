/* color-aware.js — make text overlaid on images adapt to what's under it.
 *
 * For each target (academy cover chips, feature hero) it samples the luminance
 * of the image region the text sits on and adds `is-on-light` (→ dark text) or
 * `is-on-dark` (→ light text). Cross-origin images that can't be read from a
 * canvas fall back to `is-on-dark` (light text + the element's own scrim), so
 * the text is always legible whether or not sampling succeeds.
 */
(function () {
  'use strict';
  function bgUrl(el) {
    var cs = getComputedStyle(el).backgroundImage || '';
    var m = cs.match(/url\((['"]?)(.*?)\1\)/);
    return m ? m[2] : '';
  }
  // Average relative luminance (0..1) of a sub-rectangle of the image.
  function regionLuma(img, fx, fy, fw, fh) {
    var W = 40, H = 40; // downsample — plenty for an average
    var c = document.createElement('canvas'); c.width = W; c.height = H;
    var ctx = c.getContext('2d');
    var sx = img.naturalWidth * fx, sy = img.naturalHeight * fy,
        sw = img.naturalWidth * fw, sh = img.naturalHeight * fh;
    ctx.drawImage(img, sx, sy, sw, sh, 0, 0, W, H);
    var d = ctx.getImageData(0, 0, W, H).data, sum = 0, n = 0;
    for (var i = 0; i < d.length; i += 4) {
      // sRGB → relative luminance (Rec. 709), weighted by alpha
      var a = d[i + 3] / 255;
      sum += (0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2]) * a;
      n += a;
    }
    return n ? (sum / n) / 255 : 0.5;
  }
  function apply(el, region) {
    var url = bgUrl(el);
    if (!url) return;
    var img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = function () {
      var light;
      try { light = regionLuma(img, region[0], region[1], region[2], region[3]) > 0.6; }
      catch (e) { el.classList.add('is-on-dark'); return; } // tainted → assume light text
      el.classList.toggle('is-on-light', light);
      el.classList.toggle('is-on-dark', !light);
    };
    img.onerror = function () { el.classList.add('is-on-dark'); };
    img.src = url;
  }
  function run() {
    // Academy cover chips sit at the TOP of the thumb.
    [].forEach.call(document.querySelectorAll('.ac-thumb.has-cover'), function (el) { apply(el, [0, 0, 1, 0.5]); });
    // Feature hero title sits at the BOTTOM.
    [].forEach.call(document.querySelectorAll('.feature-hero'), function (el) { apply(el, [0, 0.55, 1, 0.45]); });
    // Anything explicitly opted in: data-color-aware="x,y,w,h" (fractions) or default whole image.
    [].forEach.call(document.querySelectorAll('[data-color-aware]'), function (el) {
      var r = (el.getAttribute('data-color-aware') || '').split(',').map(parseFloat);
      apply(el, r.length === 4 && !r.some(isNaN) ? r : [0, 0, 1, 1]);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
