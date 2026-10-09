/* assets/site/avc-print.js — card/print.php: the card's print files, made here.
 *
 * Each face in the off-screen farm is the screen partial laid out on the
 * design's canvas (10px per mm, 600 × 916px with its 3 mm of real bleed). snapDOM rasterises it through the
 * browser's own layout engine, straight at the target resolution, so text and
 * QR modules land on device pixels. Then:
 *   pdf  68 × 99.6 mm, two pages (front, back), the face at 600 dpi inside a
 *        60 × 91.6 mm bleed box, crop marks 0.25 pt × 3 mm, 1 mm off the bleed
 *   png  front and back at 300 dpi with bleed, 709 × 1082 px, sRGB, zipped
 *   a4   ten up, cards turned landscape 2 × 5; the back page mirrored for a
 *        long-edge flip; 1.5 mm of bleed each (half the 3 mm gutter); cut marks
 *        in the margins
 * No dependency is loaded until a button is pressed.
 */
(function () {
  'use strict';
  var root = document.querySelector('[data-avcp]');
  if (!root) return;

  var VENDOR = '/assets/vendor/card/';
  var SUBJECT = 'CR80 54×85.6 mm, 3 mm bleed, RGB. Ask the printer to convert to CMYK.';
  var FAIL = 'We couldn’t make the file. Try again in a minute.';
  var TRIM_W = 54, TRIM_H = 85.6, BLEED = 3;
  var FACE_W = TRIM_W + 2 * BLEED, FACE_H = TRIM_H + 2 * BLEED;   // 60 × 91.6
  var name = root.getAttribute('data-name') || 'afrovanguard-card';
  var status = document.querySelector('[data-avcp-status]');
  var busy = false;

  var loads = {};
  function load(file, global) {
    if (window[global]) return Promise.resolve(window[global]);
    if (loads[file]) return loads[file];
    loads[file] = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = VENDOR + file; s.async = false;
      s.onload = function () { window[global] ? resolve(window[global]) : reject(new Error(file + ' did not load')); };
      s.onerror = function () { delete loads[file]; reject(new Error(file + ' did not load')); };
      document.head.appendChild(s);
    });
    return loads[file];
  }

  function face(side) { return document.querySelector('.avcp-farm .avc-' + side); }

  /* Everything the face shows has arrived: fonts, the photo, the seal. */
  function settled(node) {
    var imgs = Array.prototype.slice.call(node.querySelectorAll('img'));
    return Promise.all([document.fonts ? document.fonts.ready : null].concat(imgs.map(function (im) {
      return im.decode ? im.decode().catch(function () {}) : null;
    })));
  }

  /* One face as a canvas, (60 / 25.4 × dpi) px wide. */
  function raster(side, dpi) {
    var node = face(side);
    if (!node) return Promise.reject(new Error('no ' + side));
    return Promise.all([load('snapdom.js', 'snapdom'), settled(node)]).then(function (r) {
      var sd = r[0];
      var W = Math.round(FACE_W / 25.4 * dpi), H = Math.round(W * FACE_H / FACE_W);
      return sd.toCanvas(node, { width: W, height: H, dpr: 1, embedFonts: true }).then(function (cv) {
        cv.getContext('2d').getImageData(0, 0, 1, 1);   // throws now if the canvas is tainted
        return cv;
      });
    });
  }

  function save(blob, file) {
    var url = URL.createObjectURL(blob), a = document.createElement('a');
    a.href = url; a.download = file; document.body.appendChild(a); a.click();
    setTimeout(function () { a.remove(); URL.revokeObjectURL(url); }, 1500);
  }

  /* ── crop marks ──────────────────────────────────────────────────────── */
  var HAIR = 0.25 * 25.4 / 72;   // 0.25 pt in mm
  function line(doc, x1, y1, x2, y2) { doc.line(x1, y1, x2, y2); }

  /* ── pdf: one card, two pages ────────────────────────────────────────── */
  function makePdf() {
    return Promise.all([load('jspdf.umd.min.js', 'jspdf'), raster('front', 600), raster('back', 600)]).then(function (r) {
      var doc = new r[0].jsPDF({ unit: 'mm', format: [68, 99.6], orientation: 'portrait', compress: true });
      doc.setProperties({ title: 'Afrovanguard member card', subject: SUBJECT, creator: 'Afrovanguard' });
      [r[1], r[2]].forEach(function (cv, i) {
        if (i) doc.addPage([68, 99.6], 'portrait');
        doc.addImage(cv, 'PNG', 4, 4, FACE_W, FACE_H, 'face' + i, 'FAST');
        doc.setDrawColor(0, 0, 0); doc.setLineWidth(HAIR);
        [7, 61].forEach(function (x) { line(doc, x, 0, x, 3); line(doc, x, 96.6, x, 99.6); });
        [7, 92.6].forEach(function (y) { line(doc, 0, y, 3, y); line(doc, 65, y, 68, y); });
      });
      return doc.output('blob');
    }).then(function (b) { save(b, name + '.pdf'); });
  }

  /* ── png: two faces, 300 dpi, zipped ─────────────────────────────────── */
  var CRC = (function () {
    var t = new Uint32Array(256);
    for (var n = 0; n < 256; n++) { var c = n; for (var k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; }
    return t;
  })();
  function crc32(bytes) { var c = 0xFFFFFFFF; for (var i = 0; i < bytes.length; i++) c = CRC[(c ^ bytes[i]) & 255] ^ (c >>> 8); return (c ^ 0xFFFFFFFF) >>> 0; }
  function chunk(type, data) {
    var out = new Uint8Array(12 + data.length), dv = new DataView(out.buffer);
    dv.setUint32(0, data.length);
    for (var i = 0; i < 4; i++) out[4 + i] = type.charCodeAt(i);
    out.set(data, 8);
    dv.setUint32(8 + data.length, crc32(out.subarray(4, 8 + data.length)));
    return out;
  }
  /* 300 dpi and sRGB written into the file, so the printer is not left to guess. */
  function tagPng(bytes) {
    var has = {}, p = 8;
    while (p + 8 <= bytes.length) {
      var len = new DataView(bytes.buffer, bytes.byteOffset + p, 4).getUint32(0);
      has[String.fromCharCode(bytes[p + 4], bytes[p + 5], bytes[p + 6], bytes[p + 7])] = true;
      p += 12 + len;
    }
    var add = [];
    if (!has.sRGB && !has.iCCP) add.push(chunk('sRGB', new Uint8Array([0])));
    if (!has.pHYs) {
      var d = new Uint8Array(9), v = new DataView(d.buffer);
      v.setUint32(0, 11811); v.setUint32(4, 11811); d[8] = 1;   // 300 dpi = 11811 px/m
      add.push(chunk('pHYs', d));
    }
    var extra = add.reduce(function (n, c) { return n + c.length; }, 0);
    var out = new Uint8Array(bytes.length + extra), at = 33;   // signature + IHDR
    out.set(bytes.subarray(0, at), 0);
    add.forEach(function (c) { out.set(c, at); at += c.length; });
    out.set(bytes.subarray(33), at);
    return out;
  }
  function pngBytes(cv) {
    return new Promise(function (res, rej) { cv.toBlob(function (b) { b ? res(b) : rej(new Error('encode')); }, 'image/png'); })
      .then(function (b) { return b.arrayBuffer(); }).then(function (ab) { return tagPng(new Uint8Array(ab)); });
  }
  function makePng() {
    return Promise.all([load('jszip.min.js', 'JSZip'), raster('front', 300), raster('back', 300)]).then(function (r) {
      return Promise.all([pngBytes(r[1]), pngBytes(r[2])]).then(function (png) {
        var zip = new r[0]();
        zip.file(name + '-front.png', png[0]);
        zip.file(name + '-back.png', png[1]);
        return zip.generateAsync({ type: 'blob' });
      });
    }).then(function (b) { save(b, name + '-png.zip'); });
  }

  /* ── a4: ten up ──────────────────────────────────────────────────────── */
  /* The face turned landscape with 1.5 mm of its 3 mm bleed. The front turns
     anticlockwise (its top to the sheet's left); the back clockwise, because
     a long-edge flip carries the sheet's left edge to the back's right. */
  function turned(cv, clockwise) {
    var pxmm = cv.width / FACE_W, keep = 1.5;
    var sx = (BLEED - keep) * pxmm, sw = (TRIM_W + 2 * keep) * pxmm, sh = (TRIM_H + 2 * keep) * pxmm;
    var out = document.createElement('canvas');
    out.width = Math.round(sh); out.height = Math.round(sw);
    var ctx = out.getContext('2d');
    if (clockwise) { ctx.translate(out.width, 0); ctx.rotate(Math.PI / 2); }
    else { ctx.translate(0, out.height); ctx.rotate(-Math.PI / 2); }
    ctx.drawImage(cv, sx, sx, sw, sh, 0, 0, sw, sh);
    return out;
  }
  function makeA4() {
    return Promise.all([load('jspdf.umd.min.js', 'jspdf'), raster('front', 600), raster('back', 600)]).then(function (r) {
      var doc = new r[0].jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait', compress: true });
      doc.setProperties({ title: 'Afrovanguard member cards, A4 10-up', subject: SUBJECT, creator: 'Afrovanguard' });
      var CW = TRIM_H, CH = TRIM_W, GAP = 3, COLS = 2, ROWS = 5, KEEP = 1.5;   // cards lie landscape
      var offX = (210 - (COLS * CW + (COLS - 1) * GAP)) / 2, offY = (297 - (ROWS * CH + (ROWS - 1) * GAP)) / 2;
      [turned(r[1], false), turned(r[2], true)].forEach(function (img, side) {
        if (side) doc.addPage('a4', 'portrait');
        for (var row = 0; row < ROWS; row++) for (var c = 0; c < COLS; c++) {
          var col = side ? COLS - 1 - c : c;   // the mirror: column 1's back lands behind column 1
          var x = offX + col * (CW + GAP), y = offY + row * (CH + GAP);
          doc.addImage(img, 'PNG', x - KEEP, y - KEEP, CW + 2 * KEEP, CH + 2 * KEEP, 'a4face' + side, 'FAST');
        }
        /* Cut marks in the margins, on every cut line, 1 mm clear of the bleed. */
        doc.setDrawColor(0, 0, 0); doc.setLineWidth(HAIR);
        var top = offY - KEEP - 1, bottom = offY + ROWS * CH + (ROWS - 1) * GAP + KEEP + 1;
        var left = offX - KEEP - 1, right = offX + COLS * CW + (COLS - 1) * GAP + KEEP + 1;
        for (var k = 0; k < COLS; k++) [offX + k * (CW + GAP), offX + k * (CW + GAP) + CW].forEach(function (x) {
          line(doc, x, top - 3, x, top); line(doc, x, bottom, x, bottom + 3);
        });
        for (var j = 0; j < ROWS; j++) [offY + j * (CH + GAP), offY + j * (CH + GAP) + CH].forEach(function (y) {
          line(doc, left - 3, y, left, y); line(doc, right, y, right + 3, y);
        });
      });
      return doc.output('blob');
    }).then(function (b) { save(b, name + '-a4-10up.pdf'); });
  }

  /* ── the buttons ─────────────────────────────────────────────────────── */
  var makers = { pdf: makePdf, png: makePng, a4: makeA4 };
  var labels = { pdf: 'the PDF', png: 'the images', a4: 'the A4 sheet' };

  function say(text, retry) {
    status.textContent = text;
    if (retry) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'avc-btn'; b.textContent = 'Retry';
      b.addEventListener('click', function () { run(retry, b); });
      status.appendChild(b);
    }
  }

  function run(kind, trigger) {
    if (busy) return;
    busy = true;
    var btn = root.querySelector('[data-avcp-make="' + kind + '"]');
    btn.setAttribute('aria-busy', 'true');
    say('Preparing ' + labels[kind] + '…');
    makers[kind]().then(function () {
      say('Done. Check your downloads.');
      btn.focus();
    }).catch(function (e) {
      if (window.console) console.error('[card print]', e);
      say(FAIL, kind);
      var r = status.querySelector('button'); if (r) r.focus();
    }).then(function () {
      busy = false; btn.removeAttribute('aria-busy');
      if (trigger && trigger.isConnected === false) btn.focus();
    });
  }

  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-avcp-make]');
    if (b && !b.disabled) run(b.getAttribute('data-avcp-make'));
  });
})();
