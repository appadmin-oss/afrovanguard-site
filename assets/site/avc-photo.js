/* assets/site/avc-photo.js — the card photo editor, for staff and for members.
 *
 * From NextGen Genius's card photo pipeline (card-photo.jsx cphFrameAi,
 * photo-framing.php), cut to what this card needs:
 *   1. the photo is read with its camera orientation applied;
 *   2. the server asks Gemini where the head is (lib/CardPhoto.php::measure);
 *   3. NGG's head-and-shoulders rule proposes the crop at the card panel's
 *      exact shape (47.2 × 35.2 mm) — without a measurement, the top-centre;
 *   4. the person adjusts it in Cropper.js (aspect locked);
 *   5. the crop is resampled to at most 1114 × 832 (600 dpi at the panel),
 *      never enlarged, and refused if it would print soft.
 * The server re-checks and stores it (CardPhoto::save).
 *
 *   AvcPhoto.edit({ file, measure(blob) → Promise<geom|null>, save(blob) → Promise<{ok,url,error}> })
 *     → Promise<url|null>   (null: cancelled)
 *
 * A native <dialog>: modal, focus kept inside, Esc closes it, focus returns.
 */
(function () {
  'use strict';
  var ASPECT = 47.2 / 35.2, MIN_W = 557, MIN_H = 416, SAVE_W = 1114;
  var TOO_SMALL = 'Your photo is too small to print sharply. Upload one at least 800 px wide.';
  var VENDOR = '/assets/vendor/card/';

  /* ── NGG's framing rule (card-photo.jsx cphFrameAi), in image pixels ── */
  var HEAD = 0.56, TOP = 0.11;
  function frameFor(g, W, H) {
    if (!g || !g.subject || !g.head) return null;
    var hx0 = g.head.x0 * W, hx1 = g.head.x1 * W, hy0 = g.head.y0 * H, hy1 = g.head.y1 * H;
    var headW = hx1 - hx0, headH = hy1 - hy0;
    if (!(headW > 0) || !(headH > 0)) return null;
    var fh = headH / HEAD, fw = fh * ASPECT;
    var minW = Math.max(headW * 1.05, headW * 1.45), minH = headH * 1.1;
    if (fw < minW) { fw = minW; fh = fw / ASPECT; }
    if (fh < minH) { fh = minH; fw = fh * ASPECT; }
    var s = Math.min(1, W / fw, H / fh); fw *= s; fh *= s;
    var cx = g.eyes && g.eyes.length === 2 ? (g.eyes[0].x + g.eyes[1].x) / 2 * W : (hx0 + hx1) / 2;
    var x = cx - fw / 2, y = hy0 - TOP * fh;
    if (fw >= headW) x = Math.min(hx0, Math.max(hx1 - fw, x));
    if (fh >= headH) y = Math.min(hy0, Math.max(hy1 - fh, y));
    x = Math.min(Math.max(0, x), W - fw); y = Math.min(Math.max(0, y), H - fh);
    return { x: x, y: y, width: fw, height: fh };
  }
  /* No measurement: the widest panel-shaped crop, a third of the way down. */
  function fallback(W, H) {
    var fw = W, fh = W / ASPECT;
    if (fh > H) { fh = H; fw = H * ASPECT; }
    return { x: (W - fw) / 2, y: (H - fh) * 0.3, width: fw, height: fh };
  }

  var loads = {};
  function asset(src, css) {
    if (loads[src]) return loads[src];
    loads[src] = new Promise(function (res, rej) {
      var el = document.createElement(css ? 'link' : 'script');
      if (css) { el.rel = 'stylesheet'; el.href = src; } else { el.src = src; }
      el.onload = res; el.onerror = function () { delete loads[src]; rej(new Error(src)); };
      document.head.appendChild(el);
    });
    return loads[src];
  }

  /* The photo with its EXIF orientation applied, as a JPEG blob and its size. */
  function upright(file, max) {
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bm) {
      var s = Math.min(1, max / Math.max(bm.width, bm.height));
      var c = document.createElement('canvas');
      c.width = Math.round(bm.width * s); c.height = Math.round(bm.height * s);
      var x = c.getContext('2d'); x.imageSmoothingQuality = 'high'; x.drawImage(bm, 0, 0, c.width, c.height);
      return new Promise(function (res) { c.toBlob(function (b) { res({ blob: b, w: c.width, h: c.height }); }, 'image/jpeg', 0.95); });
    });
  }

  function dialog() {
    var d = document.getElementById('avcp-dialog');
    if (d) return d;
    d = document.createElement('dialog');
    d.id = 'avcp-dialog'; d.className = 'avcph';
    d.setAttribute('aria-labelledby', 'avcph-title');
    d.innerHTML = '<div class="avcph-top"><h2 id="avcph-title">Your card photo</h2>'
      + '<button type="button" class="avcph-x" data-avcph-cancel aria-label="Close">×</button></div>'
      + '<div class="avcph-stage"><img alt="The photo being framed"></div>'
      + '<p class="avcph-msg" role="status" aria-live="polite"></p>'
      + '<p class="avcph-hint">Head and shoulders, facing the camera, on a plain background. Drag the box to adjust.</p>'
      + '<div class="avcph-foot"><button type="button" class="avcph-btn" data-avcph-cancel>Cancel</button>'
      + '<button type="button" class="avcph-btn avcph-btn--primary" data-avcph-use disabled>Use this photo</button></div>';
    document.body.appendChild(d);
    return d;
  }

  function edit(o) {
    var d = dialog(), img = d.querySelector('img'), msgEl = d.querySelector('.avcph-msg'), use = d.querySelector('[data-avcph-use]');
    var trigger = document.activeElement, cropper = null, url = '';
    function msg(t, bad) { msgEl.textContent = t; msgEl.classList.toggle('is-bad', !!bad); }
    return new Promise(function (resolve) {
      var finished = false;
      function finish(v) {
        if (finished) return; finished = true;
        if (cropper) { cropper.destroy(); cropper = null; }
        if (url) URL.revokeObjectURL(url);
        if (d.open) d.close();
        if (trigger && trigger.focus) trigger.focus();
        resolve(v);
      }
      function check() {
        if (!cropper) return;
        var c = cropper.getData(true), ok = c.width >= MIN_W && c.height >= MIN_H;
        use.disabled = !ok;
        if (!ok) msg(TOO_SMALL, true);
        else if (msgEl.classList.contains('is-bad') && msgEl.textContent === TOO_SMALL) msg('Drag to adjust.');
      }
      d.querySelectorAll('[data-avcph-cancel]').forEach(function (b) { b.onclick = function () { finish(null); }; });
      d.oncancel = function (e) { e.preventDefault(); finish(null); };
      use.onclick = function () {
        if (!cropper) return;
        var c = cropper.getData(true), w = Math.min(SAVE_W, c.width), h = Math.round(w / ASPECT);
        var canvas = cropper.getCroppedCanvas({ width: w, height: h, imageSmoothingEnabled: true, imageSmoothingQuality: 'high', fillColor: 'white' });
        use.disabled = true; msg('Saving…');
        canvas.toBlob(function (b) {
          Promise.resolve(o.save(b)).then(function (r) {
            if (r && r.ok) return finish(r.url || '');
            msg((r && r.error) || 'The photo could not be saved.', true); use.disabled = false;
          }, function () { msg('Network error — try again.', true); use.disabled = false; });
        }, 'image/jpeg', 0.92);
      };
      use.disabled = true;
      d.showModal();
      msg('Reading the photo…');
      Promise.all([upright(o.file, 4000), upright(o.file, 1024), asset(VENDOR + 'cropper.min.css', true), asset(VENDOR + 'cropper.min.js')])
        .then(function (r) {
          var full = r[0], small = r[1];
          url = URL.createObjectURL(full.blob);
          img.src = url;
          msg('Finding the face…');
          var measured = Promise.resolve(o.measure ? o.measure(small.blob) : null).catch(function () { return null; });
          return Promise.all([measured, new Promise(function (res) { if (img.complete && img.naturalWidth) res(); else img.onload = res; })]).then(function (x) {
            if (finished) return;
            var g = x[0] || null;
            var box = frameFor(g, full.w, full.h) || fallback(full.w, full.h);
            cropper = new window.Cropper(img, {
              aspectRatio: ASPECT, viewMode: 1, autoCropArea: 1, background: false, zoomable: true, responsive: true,
              ready: function () { cropper.setData(box); check(); }, crop: check,
            });
            if (g && !g.subject) msg('No single face found — frame it by hand.', true);
            else if (g) msg('Framed head and shoulders. Drag to adjust.');
            else msg('Frame it by hand: drag the box, or zoom with the wheel.');
          });
        })
        .catch(function () { msg('That photo could not be opened. Try a JPEG or PNG.', true); });
    });
  }

  window.AvcPhoto = { edit: edit, frameFor: frameFor, ASPECT: ASPECT };
})();
