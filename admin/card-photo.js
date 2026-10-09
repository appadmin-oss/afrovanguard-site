/* admin/card-photo.js — the member desk's card photo: framed, adjusted, print-ready.
 *
 * From NextGen Genius's card photo pipeline (card-photo.jsx cphFrameAi,
 * photo-framing.php), cut to what this card needs:
 *   1. the photo is read with its camera orientation applied;
 *   2. the server asks Gemini where the head is (lib/CardPhoto.php::measure);
 *   3. NGG's head-and-shoulders rule proposes the crop at the card panel's
 *      exact shape (47.2 × 35.2 mm) — without a measurement, the top-centre;
 *   4. staff adjust it in Cropper.js (aspect locked);
 *   5. the crop is resampled to at most 1114 × 832 (600 dpi at the panel),
 *      never enlarged, and refused if it would print soft.
 * The server re-checks and stores it (CardPhoto::save).
 */
(function () {
  'use strict';
  var A = window.AvAdmin;
  var $ = function (s) { return document.querySelector(s); };
  if (!A || !$('#mdCardPhoto')) return;

  var ASPECT = 47.2 / 35.2, MIN_W = 557, MIN_H = 416, SAVE_W = 1114;
  var TOO_SMALL = 'Your photo is too small to print sharply. Upload one at least 800 px wide.';
  var member = 0, cropper = null, srcBlob = null, trigger = null;

  function load(id) {
    member = +id;
    A.api('card_photo_get&id=' + member).then(function (r) {
      var d = r.data || {};
      show(d.photo || '');
      $('#md_cardrole').value = d.role || '';
    });
  }
  function show(url) {
    var img = $('#mdCpImg');
    img.hidden = !url; $('#mdCpNone').hidden = !!url; $('#mdCpClear').hidden = !url;
    $('#mdCpPick').textContent = url ? 'Replace photo' : 'Add photo';
    if (url) img.src = url + (url.indexOf('?') < 0 ? '?' : '&') + 't=' + Date.now(); else img.removeAttribute('src');
  }

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

  /* ── The dialog ─────────────────────────────────────────────────────── */
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
  function msg(t, bad) { var m = $('#cpMsg'); m.textContent = t; m.classList.toggle('bad', !!bad); }
  function close() {
    if (cropper) { cropper.destroy(); cropper = null; }
    $('#cpModal').hidden = true; $('#cpUse').disabled = true;
    if (trigger) trigger.focus();
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

  function open(file) {
    trigger = $('#mdCpPick');
    $('#cpModal').hidden = false; $('#cpUse').disabled = true;
    msg('Reading the photo…');
    Promise.all([upright(file, 4000), upright(file, 1024), asset('/assets/vendor/card/cropper.min.css', true), asset('/assets/vendor/card/cropper.min.js')])
      .then(function (r) {
        var full = r[0], small = r[1];
        srcBlob = full.blob;
        var img = $('#cpImg'); img.src = URL.createObjectURL(full.blob);
        msg('Finding the face…');
        var fd = new FormData(); fd.append('file', small.blob, 'measure.jpg');
        var measured = A.api('card_photo_measure', { method: 'POST', body: fd }).then(function (m) { return m.data || {}; }).catch(function () { return {}; });
        return Promise.all([measured, new Promise(function (res) { img.onload = res; })]).then(function (x) {
          var g = x[0] && x[0].ok ? x[0].geom : null;
          var box = frameFor(g, full.w, full.h) || fallback(full.w, full.h);
          cropper = new window.Cropper(img, {
            aspectRatio: ASPECT, viewMode: 1, autoCropArea: 1, background: false, zoomable: true, responsive: true,
            ready: function () { cropper.setData(box); check(); },
            crop: check,
          });
          if (g && !g.subject) msg('No single face found — frame it by hand.', true);
          else if (g) msg('Framed head and shoulders. Drag to adjust.');
          else msg('Frame it by hand: drag the box, or zoom with the wheel.');
        });
      })
      .catch(function () { msg('That photo could not be opened. Try a JPEG or PNG.', true); });
  }
  function check() {
    if (!cropper) return;
    var d = cropper.getData(true), ok = d.width >= MIN_W && d.height >= MIN_H;
    $('#cpUse').disabled = !ok;
    if (!ok) msg(TOO_SMALL, true);
    else if ($('#cpMsg').classList.contains('bad') && /small/.test($('#cpMsg').textContent)) msg('Drag to adjust.');
  }
  function use() {
    if (!cropper) return;
    var d = cropper.getData(true);
    var w = Math.min(SAVE_W, d.width), h = Math.round(w / ASPECT);
    var canvas = cropper.getCroppedCanvas({ width: w, height: h, imageSmoothingEnabled: true, imageSmoothingQuality: 'high', fillColor: 'white' });
    $('#cpUse').disabled = true; msg('Saving…');
    canvas.toBlob(function (b) {
      var fd = new FormData(); fd.append('file', b, 'card.jpg'); fd.append('id', String(member));
      A.api('card_photo_save', { method: 'POST', body: fd }).then(function (r) {
        var d2 = r.data || {};
        if (!d2.ok) { msg(d2.error || 'The photo could not be saved.', true); $('#cpUse').disabled = false; return; }
        show(d2.url); close(); A.toast('Card photo saved.');
      }).catch(function () { msg('Network error — try again.', true); $('#cpUse').disabled = false; });
    }, 'image/jpeg', 0.92);
  }

  $('#mdCpPick').addEventListener('click', function () { $('#mdCpFile').value = ''; $('#mdCpFile').click(); });
  $('#mdCpFile').addEventListener('change', function () { if (this.files && this.files[0]) open(this.files[0]); });
  $('#mdCpClear').addEventListener('click', function () {
    A.post('card_photo_clear', { id: member }).then(function (r) { if (r.data && r.data.ok) { show(''); A.toast('Photo removed — the card prints initials.'); } });
  });
  $('#mdRoleSave').addEventListener('click', function () {
    A.post('card_role_save', { id: member, role: $('#md_cardrole').value }).then(function (r) {
      var d = r.data || {}; A.toast(d.ok ? (d.role ? 'Title saved.' : 'Title removed.') : (d.error || 'Could not save.'));
    });
  });
  $('#cpUse').addEventListener('click', use);
  $('#cpCancel').addEventListener('click', close);
  $('#cpClose').addEventListener('click', close);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('#cpModal').hidden) { e.stopPropagation(); close(); } }, true);

  window.AvCardPhoto = { load: load, frameFor: frameFor };
})();
