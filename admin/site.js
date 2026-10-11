/* admin/site.js — the Studio's content screens: the Site workspace (the
   homepage hero, every page and the site details, over lib/Cms.php), Media
   (lib/Media.php), the library picker, and the picture editor.

   Uses the Studio's own request path (window.AvAdmin: CSRF, retry, toast).
   Classes only — nothing is positioned with style: the crop frame is drawn on
   the editor's canvas, not laid over it. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };
  var A = function () { return window.AvAdmin; };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var mb = function (n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; };
  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function say(box, text, good) { if (!box) return; box.textContent = text || ''; box.hidden = !text; box.classList.toggle('is-ok', !!good); }

  /* ══ Uploading, in pieces ═══════════════════════════════════════════════
     Each piece goes as its own small request, so the host's upload limit never
     applies. A failed piece is retried twice before the upload gives up. */
  function upload(file, list, done) {
    var row = el('li', 'cms-up'); row.innerHTML = '<span class="cms-up-n">' + esc(file.name) + '</span><progress max="100" value="0"></progress><span class="cms-up-s">Starting…</span>';
    list.appendChild(row);
    var bar = $('progress', row), st = $('.cms-up-s', row);
    var fail = function (m) { row.classList.add('is-bad'); st.textContent = m || 'Upload failed.'; };
    A().post('media_start', { name: file.name, size: file.size }).then(function (r) {
      var d = r.data || {}; if (!d.ok) return fail(d.error);
      var size = d.chunk, n = Math.ceil(file.size / size), i = 0, tries = 0;
      var next = function () {
        if (i >= n) {
          st.textContent = 'Processing…';
          return A().post('media_finish', { token: d.token }).then(function (f) {
            var fd = f.data || {}; if (!fd.ok) return fail(fd.error);
            bar.value = 100; st.textContent = 'Done'; row.classList.add('is-ok');
            setTimeout(function () { row.remove(); }, 2500);
            if (done) done(fd.item);
          });
        }
        var blob = file.slice(i * size, Math.min(file.size, (i + 1) * size));
        var rd = new FileReader();
        rd.onload = function () {
          var b64 = String(rd.result).split(',')[1] || '';
          A().post('media_chunk', { token: d.token, index: i, data: b64 }).then(function (c) {
            var cd = c.data || {};
            if (!cd.ok) { if (++tries <= 2) return next(); return fail(cd.error); }
            tries = 0; i++; bar.value = Math.round(i / n * 95); st.textContent = Math.round(i / n * 100) + '%'; next();
          });
        };
        rd.onerror = function () { fail('That file could not be read.'); };
        rd.readAsDataURL(blob);
      };
      next();
    });
  }

  /* ══ Media library ═════════════════════════════════════════════════════ */
  var M = { page: 1, kind: '', q: '', items: [], total: 0, sel: null };

  function tile(m, pick) {
    var li = el('li', 'cms-tile' + (m.kind === 'video' ? ' is-video' : ''));
    var b = el('button'); b.type = 'button'; b.setAttribute('aria-label', (m.kind === 'video' ? 'Video: ' : 'Picture: ') + (m.alt || m.name));
    b.innerHTML = m.kind === 'video'
      ? '<span class="cms-vthumb" aria-hidden="true">▶</span><span class="cms-tile-n">' + esc(m.name) + '</span>'
      : '<img src="' + esc(m.thumb) + '" alt="" loading="lazy"><span class="cms-tile-n">' + esc(m.name) + '</span>';
    if (!m.alt && m.kind === 'image') li.classList.add('needs-alt');
    b.addEventListener('click', function () { pick(m, li); });
    li.appendChild(b); return li;
  }

  function loadMedia(reset) {
    if (reset) { M.page = 1; M.items = []; }
    var grid = $('#mediaGrid'); if (reset) grid.innerHTML = '<li class="cms-empty">Loading…</li>';
    A().api('media_list&page=' + M.page + '&kind=' + encodeURIComponent(M.kind) + '&q=' + encodeURIComponent(M.q)).then(function (r) {
      var d = r.data || {};
      if (!d.ok) { grid.innerHTML = '<li class="cms-empty">Could not load the library. <button type="button" class="btn btn-outline btn-sm" data-retry>Try again</button></li>'; $('[data-retry]', grid).onclick = function () { loadMedia(true); }; return; }
      if (reset) grid.innerHTML = '';
      M.items = M.items.concat(d.items); M.total = d.total;
      d.items.forEach(function (m) { grid.appendChild(tile(m, selectMedia)); });
      if (!M.items.length) grid.innerHTML = '<li class="cms-empty">' + (M.q || M.kind ? 'Nothing matches.' : 'Nothing here yet. Upload a picture or a video.') + '</li>';
      $('#mediaCount').textContent = M.total + (M.total === 1 ? ' item' : ' items');
      $('#mediaMore').hidden = M.items.length >= M.total;
    });
  }

  function selectMedia(m, li) {
    M.sel = m;
    $$('#mediaGrid .cms-tile').forEach(function (t) { t.classList.toggle('is-on', t === li); });
    var box = $('#mediaDetail'); box.hidden = false;
    var abs = location.origin + m.url;
    box.innerHTML =
      (m.kind === 'video' ? '<video class="cms-dprev" src="' + esc(m.url) + '" controls muted playsinline preload="metadata"></video>'
                          : '<img class="cms-dprev" src="' + esc(m.variants.length ? m.variants[m.variants.length - 1].url : m.url) + '" alt="">') +
      '<p class="muted tiny">' + (m.width ? m.width + ' × ' + m.height + ' px · ' : '') + mb(m.bytes) + (m.edits && Object.keys(m.edits).length ? ' · edited' : '') + '</p>' +
      '<label class="fld"><span>Name</span><input id="cmsMdName" maxlength="190" value="' + esc(m.name) + '"></label>' +
      (m.kind === 'image' ? '<label class="fld"><span>Description for people who cannot see it (alt text)</span><textarea id="cmsMdAlt" rows="2" maxlength="255">' + esc(m.alt) + '</textarea></label>' : '') +
      '<div class="cms-btnrow"><button class="btn btn-primary btn-sm" type="button" id="cmsMdSave">Save details</button>' +
      '<button class="btn btn-outline btn-sm" type="button" id="cmsMdCopy">Copy address</button></div>' +
      (m.kind === 'image' ? '<div class="cms-btnrow"><button class="btn btn-outline btn-sm" type="button" id="cmsMdEdit">Edit picture</button>' +
        (m.edits && Object.keys(m.edits).length ? '<button class="btn btn-outline btn-sm" type="button" id="cmsMdRevert">Back to the original</button>' : '') + '</div>' : '') +
      '<div class="cms-btnrow"><button class="btn danger btn-sm" type="button" id="cmsMdDelete">Delete</button></div>' +
      '<p class="cms-msg" id="cmsMdMsg" role="alert" hidden></p>';
    var msg = $('#cmsMdMsg');
    $('#cmsMdSave').onclick = function () {
      A().post('media_describe', { id: m.id, name: $('#cmsMdName').value, alt: $('#cmsMdAlt') ? $('#cmsMdAlt').value : m.alt }).then(function (r) {
        var d = r.data || {}; if (!d.ok) return say(msg, d.error); A().toast('Saved'); refreshItem(d.item);
      });
    };
    $('#cmsMdCopy').onclick = function () { (navigator.clipboard ? navigator.clipboard.writeText(abs) : Promise.reject()).then(function () { A().toast('Address copied'); }, function () { say(msg, abs, true); }); };
    if ($('#cmsMdEdit')) $('#cmsMdEdit').onclick = function () { openEditor(m, function (item) { refreshItem(item); }); };
    if ($('#cmsMdRevert')) $('#cmsMdRevert').onclick = function () {
      if (!confirm('Put back the picture exactly as it was uploaded?')) return;
      A().post('media_revert', { id: m.id }).then(function (r) { var d = r.data || {}; if (!d.ok) return say(msg, d.error); A().toast('Back to the original'); refreshItem(d.item); });
    };
    $('#cmsMdDelete').onclick = function () {
      if (!confirm('Delete "' + m.name + '" for good?')) return;
      A().post('media_delete', { id: m.id }).then(function (r) { var d = r.data || {}; if (!d.ok) return say(msg, d.error); box.hidden = true; A().toast('Deleted'); loadMedia(true); });
    };
  }
  function refreshItem(item) {
    M.items = M.items.map(function (x) { return x.id === item.id ? item : x; });
    var grid = $('#mediaGrid'); grid.innerHTML = ''; var on = null;
    M.items.forEach(function (x) { var t = tile(x, selectMedia); if (x.id === item.id) on = t; grid.appendChild(t); });
    selectMedia(item, on);
  }

  function wireMedia() {
    var q, deb;
    $('#mediaQ').addEventListener('input', function () { clearTimeout(deb); var v = this.value; deb = setTimeout(function () { M.q = v.trim(); loadMedia(true); }, 250); });
    $$('input[name=mediaKind]').forEach(function (r) { r.addEventListener('change', function () { M.kind = this.value; loadMedia(true); }); });
    $('#mediaMore').addEventListener('click', function () { M.page++; loadMedia(false); });
    var take = function (files) { [].forEach.call(files, function (f) { upload(f, $('#mediaUploads'), function () { loadMedia(true); }); }); };
    $('#mediaFile').addEventListener('change', function () { take(this.files); this.value = ''; });
    var drop = $('#mediaDrop');
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
    drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) take(e.dataTransfer.files); });
  }

  /* ══ Picker (for the homepage's image and video fields) ═════════════════ */
  var P = { kind: 'image', cb: null };
  function openPicker(kind, cb) {
    P.kind = kind; P.cb = cb;
    var dlg = $('#mediaPicker');
    $('#mediaPickerH').textContent = kind === 'video' ? 'Choose a video' : 'Choose a picture';
    $('#pickFile').accept = kind === 'video' ? 'video/mp4,video/webm,.m4v,.mov' : 'image/jpeg,image/png,image/webp,image/gif';
    $('#pickQ').value = ''; loadPick('');
    dlg.showModal();
  }
  function loadPick(q) {
    var g = $('#pickGrid'); g.innerHTML = '<li class="cms-empty">Loading…</li>';
    A().api('media_list&kind=' + P.kind + '&q=' + encodeURIComponent(q)).then(function (r) {
      var d = r.data || {}; g.innerHTML = '';
      if (!d.ok) { g.innerHTML = '<li class="cms-empty">Could not load the library.</li>'; return; }
      if (!d.items.length) g.innerHTML = '<li class="cms-empty">Nothing yet — upload one above.</li>';
      d.items.forEach(function (m) { g.appendChild(tile(m, choose)); });
    });
  }
  function choose(m) { $('#mediaPicker').close(); if (P.cb) P.cb(m); }
  function wirePicker() {
    var deb; $('#pickQ').addEventListener('input', function () { clearTimeout(deb); var v = this.value; deb = setTimeout(function () { loadPick(v.trim()); }, 250); });
    $('#pickFile').addEventListener('change', function () { if (this.files[0]) upload(this.files[0], $('#pickUploads'), function (item) { if (item && item.kind === P.kind) choose(item); else loadPick(''); }); this.value = ''; });
  }

  /* ══ Picture editor: everything drawn on one canvas ═════════════════════ */
  var E = null;
  var ASPECTS = [['free', 'Free', 0], ['16:9', 'Wide 16:9', 16 / 9], ['4:3', '4:3', 4 / 3], ['1:1', 'Square', 1], ['4:5', 'Tall 4:5', 4 / 5]];
  function openEditor(m, saved) {
    var dlg = $('#imgEditor'), cv = $('#edCanvas'), msg = $('#edMsg'); say(msg, '');
    var img = new Image();
    img.onload = function () {
      E = { m: m, img: img, rot: (m.edits && m.edits.rotate) || 0, flip: (m.edits && m.edits.flip) || '', aspect: 0, crop: null, saved: saved, drag: null };
      orient(); E.crop = m.edits && m.edits.crop ? fit(m.edits.crop) : { x: 0, y: 0, w: E.W, h: E.H };
      $('#edAspect').innerHTML = ASPECTS.map(function (a, i) { return '<label><input type="radio" name="edAspect" value="' + i + '"' + (i === 0 ? ' checked' : '') + '> <span>' + a[1] + '</span></label>'; }).join('');
      $$('input[name=edAspect]').forEach(function (r) { r.onchange = function () { E.aspect = ASPECTS[+this.value][2]; applyAspect(); draw(); }; });
      var widths = [0, 2560, 1920, 1600, 1280, 1024, 800];
      $('#edMax').innerHTML = widths.map(function (w) { return '<option value="' + w + '">' + (w ? w + ' px' : 'Keep the size') + '</option>'; }).join('');
      $('#edMax').value = String((m.edits && m.edits.max_width) || 0);
      $$('[data-flip]').forEach(function (b) { b.setAttribute('aria-pressed', String(E.flip === b.dataset.flip)); });
      draw(); dlg.showModal(); cv.focus();
    };
    img.onerror = function () { A().toast('The original could not be opened for editing.'); };
    img.src = m.original;
  }
  /* The oriented size: the original turned and mirrored as the server will. */
  function orient() { var q = E.rot % 180 !== 0; E.W = q ? E.img.naturalHeight : E.img.naturalWidth; E.H = q ? E.img.naturalWidth : E.img.naturalHeight; }
  function fit(c) {
    var x = Math.max(0, Math.min(E.W - 16, Math.round(c.x))), y = Math.max(0, Math.min(E.H - 16, Math.round(c.y)));
    return { x: x, y: y, w: Math.max(16, Math.min(E.W - x, Math.round(c.w))), h: Math.max(16, Math.min(E.H - y, Math.round(c.h))) };
  }
  function applyAspect() {
    if (!E.aspect) return;
    var c = E.crop, w = c.w, h = Math.round(w / E.aspect);
    if (h > E.H) { h = E.H; w = Math.round(h * E.aspect); }
    if (w > E.W) { w = E.W; h = Math.round(w / E.aspect); }
    E.crop = fit({ x: c.x + (c.w - w) / 2, y: c.y + (c.h - h) / 2, w: w, h: h });
  }
  function draw() {
    var cv = $('#edCanvas'), box = cv.parentNode, maxW = Math.max(240, box.clientWidth), maxH = Math.max(240, Math.min(window.innerHeight * 0.6, 640));
    var s = Math.min(maxW / E.W, maxH / E.H, 1); E.s = s;
    cv.width = Math.round(E.W * s); cv.height = Math.round(E.H * s);
    var g = cv.getContext('2d');
    g.save(); g.translate(cv.width / 2, cv.height / 2); g.rotate(E.rot * Math.PI / 180);
    g.scale(E.flip === 'h' ? -1 : 1, E.flip === 'v' ? -1 : 1);
    var iw = E.img.naturalWidth * s, ih = E.img.naturalHeight * s;
    g.drawImage(E.img, -iw / 2, -ih / 2, iw, ih); g.restore();
    var c = E.crop, x = c.x * s, y = c.y * s, w = c.w * s, h = c.h * s;
    g.fillStyle = 'rgba(17,24,39,.55)';
    g.fillRect(0, 0, cv.width, y); g.fillRect(0, y + h, cv.width, cv.height - y - h); g.fillRect(0, y, x, h); g.fillRect(x + w, y, cv.width - x - w, h);
    g.strokeStyle = '#f3b416'; g.lineWidth = 2; g.strokeRect(x + 1, y + 1, w - 2, h - 2);
    g.strokeStyle = 'rgba(255,255,255,.5)'; g.lineWidth = 1;
    for (var k = 1; k < 3; k++) { g.beginPath(); g.moveTo(x + w * k / 3, y); g.lineTo(x + w * k / 3, y + h); g.moveTo(x, y + h * k / 3); g.lineTo(x + w, y + h * k / 3); g.stroke(); }
    g.fillStyle = '#f3b416';
    [[x, y], [x + w, y], [x, y + h], [x + w, y + h]].forEach(function (p) { g.fillRect(p[0] - 6, p[1] - 6, 12, 12); });
    $('#edX').value = c.x; $('#edY').value = c.y; $('#edW').value = c.w; $('#edH').value = c.h;
    $('#edInfo').textContent = 'Result: ' + c.w + ' × ' + c.h + ' px' + (E.rot ? ' · turned ' + E.rot + '°' : '') + (E.flip ? ' · ' + (E.flip === 'h' ? 'mirrored' : 'upside down') : '');
  }
  function hit(px, py) {
    var c = E.crop, s = E.s, x = c.x * s, y = c.y * s, w = c.w * s, h = c.h * s, r = 14;
    var near = function (a, b) { return Math.abs(px - a) <= r && Math.abs(py - b) <= r; };
    if (near(x, y)) return 'nw'; if (near(x + w, y)) return 'ne'; if (near(x, y + h)) return 'sw'; if (near(x + w, y + h)) return 'se';
    if (px > x && px < x + w && py > y && py < y + h) return 'move';
    return null;
  }
  function wireEditor() {
    var cv = $('#edCanvas');
    var pos = function (e) { var b = cv.getBoundingClientRect(); return [e.clientX - b.left, e.clientY - b.top]; };
    cv.addEventListener('pointerdown', function (e) {
      if (!E) return; var p = pos(e), h = hit(p[0], p[1]); if (!h) return;
      E.drag = { h: h, p: p, c: Object.assign({}, E.crop) }; cv.setPointerCapture(e.pointerId); e.preventDefault();
    });
    cv.addEventListener('pointermove', function (e) {
      if (!E) return; var p = pos(e);
      cv.classList.toggle('is-grab', !!hit(p[0], p[1]) || !!E.drag);
      if (!E.drag) return;
      var d = E.drag, dx = (p[0] - d.p[0]) / E.s, dy = (p[1] - d.p[1]) / E.s, c = Object.assign({}, d.c);
      if (d.h === 'move') { c.x = Math.max(0, Math.min(E.W - c.w, c.x + dx)); c.y = Math.max(0, Math.min(E.H - c.h, c.y + dy)); }
      else {
        if (d.h.indexOf('w') >= 0) { c.x += dx; c.w -= dx; } else c.w += dx;
        if (d.h.indexOf('n') >= 0) { c.y += dy; c.h -= dy; } else c.h += dy;
        if (E.aspect) { var nh = c.w / E.aspect; if (d.h.indexOf('n') >= 0) c.y += c.h - nh; c.h = nh; }
      }
      if (c.w >= 16 && c.h >= 16) { E.crop = fit(c); draw(); }
    });
    var end = function () { if (E) E.drag = null; };
    cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end);
    cv.addEventListener('keydown', function (e) {
      if (!E) return; var k = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key]; if (!k) return;
      e.preventDefault(); var step = Math.max(1, Math.round(10 / E.s)), c = Object.assign({}, E.crop);
      if (e.shiftKey) { c.w += k[0] * step; c.h = E.aspect ? c.w / E.aspect : c.h + k[1] * step; } else { c.x += k[0] * step; c.y += k[1] * step; }
      E.crop = fit(c); draw();
    });
    ['edX', 'edY', 'edW', 'edH'].forEach(function (id) {
      $('#' + id).addEventListener('change', function () {
        if (!E) return; var c = { x: +$('#edX').value, y: +$('#edY').value, w: +$('#edW').value, h: +$('#edH').value };
        if (E.aspect && id === 'edW') c.h = c.w / E.aspect; if (E.aspect && id === 'edH') c.w = c.h * E.aspect;
        E.crop = fit(c); draw();
      });
    });
    $$('[data-rot]').forEach(function (b) { b.addEventListener('click', function () {
      if (!E) return; E.rot = (E.rot + (+b.dataset.rot) + 360) % 360; orient(); E.crop = { x: 0, y: 0, w: E.W, h: E.H }; applyAspect(); draw();
    }); });
    $$('[data-flip]').forEach(function (b) { b.addEventListener('click', function () {
      if (!E) return; E.flip = E.flip === b.dataset.flip ? '' : b.dataset.flip;
      $$('[data-flip]').forEach(function (x) { x.setAttribute('aria-pressed', String(E.flip === x.dataset.flip)); }); draw();
    }); });
    $('#edReset').addEventListener('click', function () { if (!E) return; E.rot = 0; E.flip = ''; orient(); E.crop = { x: 0, y: 0, w: E.W, h: E.H }; $$('[data-flip]').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); }); draw(); });
    $('#edSave').addEventListener('click', function () {
      if (!E) return; var btn = this, msg = $('#edMsg'); btn.disabled = true; say(msg, 'Saving…', true);
      var full = E.crop.x === 0 && E.crop.y === 0 && E.crop.w === E.W && E.crop.h === E.H;
      var ops = { rotate: E.rot, flip: E.flip, max_width: +$('#edMax').value || 0 };
      if (!full) ops.crop = E.crop;
      A().post('media_edit', { id: E.m.id, ops: ops }).then(function (r) {
        btn.disabled = false; var d = r.data || {};
        if (!d.ok) return say(msg, d.error);
        $('#imgEditor').close(); A().toast(d.repointed ? 'Saved — the homepage draft now uses the new version' : 'Picture saved');
        if (E.saved) E.saved(d.item);
      });
    });
    window.addEventListener('resize', function () { if (E && $('#imgEditor').open) draw(); });
  }

  /* ══ Homepage ═════════════════════════════════════════════════════════ */
  var H = { doc: null };
  function slideRow(s, k, n) {
    var li = el('li', 'cms-slide' + (s.hidden ? ' is-hidden' : ''));
    li.innerHTML =
      '<div class="cms-slide-media">' + (s.image ? '<img src="' + esc(s.image) + '" alt="">' : '<span class="cms-noimg">No picture</span>') +
        '<button type="button" class="btn btn-outline btn-sm" data-act="img">' + (s.image ? 'Change picture' : 'Choose picture') + '</button></div>' +
      '<div class="cms-slide-fields">' +
        '<label class="fld"><span>Small line above the headline</span><input data-f="eyebrow" maxlength="80" value="' + esc(s.eyebrow) + '"></label>' +
        '<label class="fld"><span>Headline</span><input data-f="title" maxlength="120" value="' + esc(s.title) + '"></label>' +
        '<label class="fld"><span>Sentence under it</span><textarea data-f="sub" rows="2" maxlength="240">' + esc(s.sub) + '</textarea></label>' +
        '<div class="cms-two"><label class="fld"><span>Links to</span><input data-f="href" maxlength="300" value="' + esc(s.href) + '" placeholder="/about.html"></label>' +
        '<label class="fld"><span>Picture description (alt)</span><input data-f="alt" maxlength="200" value="' + esc(s.alt) + '"></label></div>' +
      '</div>' +
      '<div class="cms-slide-ctl" role="group" aria-label="Slide ' + (k + 1) + '">' +
        '<span class="cms-slide-no">' + (k + 1) + '</span>' +
        '<button type="button" class="btn btn-outline btn-sm" data-act="up"' + (k === 0 ? ' disabled' : '') + ' aria-label="Move slide ' + (k + 1) + ' up">↑</button>' +
        '<button type="button" class="btn btn-outline btn-sm" data-act="down"' + (k === n - 1 ? ' disabled' : '') + ' aria-label="Move slide ' + (k + 1) + ' down">↓</button>' +
        '<button type="button" class="btn btn-outline btn-sm" data-act="hide" aria-pressed="' + (!!s.hidden) + '">' + (s.hidden ? 'Show' : 'Hide') + '</button>' +
        '<button type="button" class="btn danger btn-sm" data-act="del" aria-label="Remove slide ' + (k + 1) + '">Remove</button>' +
      '</div>';
    $$('[data-f]', li).forEach(function (i) { i.addEventListener('input', function () { H.doc.slides[k][i.dataset.f] = i.value; dirty(); }); });
    $$('[data-act]', li).forEach(function (b) { b.addEventListener('click', function () {
      var sl = H.doc.slides, a = b.dataset.act;
      if (a === 'img') return openPicker('image', function (m) { sl[k].image = m.variants.length ? m.variants[m.variants.length - 1].url : m.url; if (!sl[k].alt && m.alt) sl[k].alt = m.alt; dirty(); renderSlides(); });
      if (a === 'up' && k > 0) { var t = sl[k - 1]; sl[k - 1] = sl[k]; sl[k] = t; }
      if (a === 'down' && k < sl.length - 1) { var u = sl[k + 1]; sl[k + 1] = sl[k]; sl[k] = u; }
      if (a === 'hide') sl[k].hidden = !sl[k].hidden;
      if (a === 'del') { if (!confirm('Remove this slide?')) return; sl.splice(k, 1); }
      dirty(); renderSlides();
      var again = $$('#homeSlides .cms-slide')[a === 'up' ? k - 1 : a === 'down' ? k + 1 : k];
      if (again) { var f = $('[data-act="' + a + '"]', again) || $('[data-f]', again); if (f && !f.disabled) f.focus(); }
    }); });
    return li;
  }
  function renderSlides() {
    var ol = $('#homeSlides'); ol.innerHTML = '';
    H.doc.slides.forEach(function (s, k) { ol.appendChild(slideRow(s, k, H.doc.slides.length)); });
    $('#homeAddSlide').disabled = H.doc.slides.length >= 10;
  }
  function pickBox(sel, label, kind, key) {
    var v = H.doc.video[key], box = $(sel);
    box.innerHTML = '<span class="cms-pick-l">' + label + '</span>' +
      (v ? (kind === 'video' ? '<video src="' + esc(v) + '" muted playsinline preload="metadata"></video>' : '<img src="' + esc(v) + '" alt="">') : '<span class="cms-noimg">None chosen</span>') +
      '<button type="button" class="btn btn-outline btn-sm">' + (v ? 'Change' : 'Choose') + '</button>';
    $('button', box).onclick = function () { openPicker(kind, function (m) {
      H.doc.video[key] = kind === 'image' && m.variants.length ? m.variants[m.variants.length - 1].url : m.url; dirty(); renderVideo();
    }); };
  }
  function renderVideo() {
    pickBox('#homeVideoPick', 'Video', 'video', 'src'); pickBox('#homePosterPick', 'Poster picture', 'image', 'poster');
    ['eyebrow', 'title', 'sub', 'href'].forEach(function (f) { var i = $('#hv_' + f); i.value = H.doc.video[f] || ''; });
  }
  function renderMode() {
    var v = H.doc.mode === 'video';
    $$('input[name=homeMode]').forEach(function (r) { r.checked = r.value === H.doc.mode; });
    $('#homeCarousel').hidden = v; $('#homeVideo').hidden = !v;
  }
  function dirty() { markDirty(); }
  function renderHero() {
    $('#homeInterval').innerHTML = [3, 4, 5, 6, 7, 8, 10, 12, 15, 20].map(function (n) { return '<option value="' + n + '">' + n + ' seconds</option>'; }).join('');
    $('#homeInterval').value = String(H.doc.interval || 6);
    renderMode(); renderSlides(); renderVideo();
  }
  function wireHero() {
    $$('input[name=homeMode]').forEach(function (r) { r.addEventListener('change', function () { H.doc.mode = this.value; renderMode(); dirty(); }); });
    $('#homeInterval').addEventListener('change', function () { H.doc.interval = +this.value; dirty(); });
    ['eyebrow', 'title', 'sub', 'href'].forEach(function (f) { $('#hv_' + f).addEventListener('input', function () { H.doc.video[f] = this.value; dirty(); }); });
    $('#homeAddSlide').addEventListener('click', function () {
      H.doc.slides.push({ image: '', alt: '', eyebrow: '', title: '', sub: '', href: '/', hidden: false }); dirty(); renderSlides();
      var last = $$('#homeSlides .cms-slide').pop(); if (last) $('[data-act=img]', last).focus();
    });
  }

  /* ══ Pages (lib/SitePages.php) ═════════════════════════════════════════
     A page's marked words, links and pictures. The draft holds only what
     differs from the live page; a field put back to what is live drops out. */
  var PG = { page: null, draft: {} };
  var KIND = { h1: 'Main heading', h2: 'Heading', h3: 'Sub-heading', h4: 'Small heading', p: 'Paragraph', li: 'List item',
    figcaption: 'Caption', blockquote: 'Quote', dt: 'Term', dd: 'Definition', a: 'Link', img: 'Picture' };
  function pCount(d) { var n = 0; Object.keys(d).forEach(function (k) { n += k === 'seo' ? Object.keys(d.seo).length : 1; }); return n; }
  function pDirty() { markDirty(); }
  function liveOf(f, prop) { return f[prop] == null ? '' : f[prop]; }
  function setDraft(f, prop, v) {
    var d = PG.draft[f.key] || {};
    if (v === liveOf(f, prop)) delete d[prop]; else d[prop] = v;
    if (Object.keys(d).length) PG.draft[f.key] = d; else delete PG.draft[f.key];
    pDirty(); markChanged(f.key);
  }
  function valOf(f, prop) { var d = PG.draft[f.key]; return d && d[prop] != null ? d[prop] : liveOf(f, prop); }
  function markChanged(key) {
    var row = $('#pageFields [data-key="' + key + '"]'); if (!row) return;
    var on = !!PG.draft[key]; row.classList.toggle('is-changed', on); $('.cms-undo', row).hidden = !on;
  }
  function fieldRow(f) {
    var row = el('div', 'cms-field'), id = 'pf-' + f.key, label = KIND[f.tag] || 'Text';
    row.setAttribute('data-key', f.key);
    var head = el('div', 'cms-field-h');
    head.innerHTML = '<span class="cms-field-l">' + esc(label) + '</span>';
    var undo = el('button', 'btn btn-outline btn-sm cms-undo', 'Undo'); undo.type = 'button'; undo.hidden = true;
    undo.setAttribute('aria-label', 'Undo the change to this ' + label.toLowerCase());
    undo.addEventListener('click', function () { delete PG.draft[f.key]; pDirty(); var fresh = fieldRow(f); row.replaceWith(fresh); markChanged(f.key); });
    head.appendChild(undo); row.appendChild(head);
    if (f.type === 'image') {
      var box = el('div', 'cms-fimg');
      var paint = function () {
        box.innerHTML = '<img src="' + esc(valOf(f, 'src')) + '" alt="">';
        var b = el('button', 'btn btn-outline btn-sm', 'Change picture'); b.type = 'button';
        b.addEventListener('click', function () { openPicker('image', function (m) {
          setDraft(f, 'src', m.url); if (m.alt && valOf(f, 'alt') === liveOf(f, 'alt')) { setDraft(f, 'alt', m.alt); $('#' + id + '-alt').value = m.alt; } paint();
        }); });
        box.appendChild(b);
      };
      paint(); row.appendChild(box);
      var alt = el('label', 'fld', '<span>Description for people who cannot see it</span>');
      var ai = el('input'); ai.id = id + '-alt'; ai.maxLength = 300; ai.value = valOf(f, 'alt');
      ai.addEventListener('input', function () { setDraft(f, 'alt', ai.value); });
      alt.appendChild(ai); row.appendChild(alt);
    } else if (f.type === 'rich') {
      var bar = el('div', 'cms-richbar'); bar.setAttribute('role', 'toolbar'); bar.setAttribute('aria-label', 'Text style');
      var ed = el('div', 'cms-rich'); ed.id = id; ed.contentEditable = 'true'; ed.setAttribute('role', 'textbox'); ed.setAttribute('aria-multiline', 'false');
      ed.setAttribute('aria-label', label); ed.innerHTML = valOf(f, 'value');
      [['bold', 'B', 'Bold'], ['italic', 'I', 'Italic']].forEach(function (c) {
        var b = el('button', 'btn btn-outline btn-sm cms-rb cms-rb--' + c[0], c[1]); b.type = 'button'; b.setAttribute('aria-label', c[2]);
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });
        b.addEventListener('click', function () { ed.focus(); document.execCommand(c[0]); ed.dispatchEvent(new Event('input')); });
        bar.appendChild(b);
      });
      ed.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (e.shiftKey) { document.execCommand('insertLineBreak'); ed.dispatchEvent(new Event('input')); } } });
      ed.addEventListener('paste', function (e) { e.preventDefault(); var t = (e.clipboardData || window.clipboardData).getData('text/plain'); document.execCommand('insertText', false, t.replace(/\s+/g, ' ')); });
      ed.addEventListener('input', function () { setDraft(f, 'value', ed.innerHTML.replace(/&nbsp;/g, ' ').trim()); });
      row.appendChild(bar); row.appendChild(ed);
    } else {
      var long = f.tag !== 'a' && (f.value.length > 70 || /^(p|li|blockquote|dd|figcaption)$/.test(f.tag));
      var inp = el(long ? 'textarea' : 'input'); inp.id = id; inp.value = valOf(f, 'value'); inp.maxLength = 2000;
      if (long) inp.rows = Math.min(8, Math.max(2, Math.ceil(inp.value.length / 70)));
      inp.setAttribute('aria-label', label + (f.tag === 'a' ? ' words' : ''));
      inp.addEventListener('input', function () { setDraft(f, 'value', inp.value.replace(/\s+/g, ' ').trim()); });
      if (f.tag === 'a') {
        var two = el('div', 'cms-two');
        var w = el('label', 'fld', '<span>Words</span>'); w.appendChild(inp);
        var h = el('label', 'fld', '<span>Goes to</span>'); var hi = el('input'); hi.value = valOf(f, 'href'); hi.maxLength = 300;
        hi.placeholder = '/about.html, https://…, mailto:…';
        hi.addEventListener('input', function () { setDraft(f, 'href', hi.value.trim()); });
        h.appendChild(hi); two.appendChild(w); two.appendChild(h); row.appendChild(two);
      } else row.appendChild(inp);
    }
    if (PG.draft[f.key]) { row.classList.add('is-changed'); undo.hidden = false; }
    return row;
  }
  function renderFields() {
    var box = $('#pageFields'), q = $('#pageFind').value.trim().toLowerCase(), only = $('#pageOnlyChanged').checked;
    box.innerHTML = ''; var groups = {}, order = [], shown = 0;
    PG.page.fields.forEach(function (f) {
      var hay = ((f.value || '') + ' ' + (f.alt || '') + ' ' + (f.href || '') + ' ' + f.group).toLowerCase();
      if (q && hay.indexOf(q) < 0) return; if (only && !PG.draft[f.key]) return;
      if (!groups[f.group]) { groups[f.group] = []; order.push(f.group); }
      groups[f.group].push(f); shown++;
    });
    order.forEach(function (g) {
      var sec = el('section', 'side-card cms-fgroup'); var h = el('h3'); h.textContent = g; sec.appendChild(h);
      groups[g].forEach(function (f) { sec.appendChild(fieldRow(f)); }); box.appendChild(sec);
    });
    if (!shown) box.appendChild(el('p', 'cms-empty', q || only ? 'Nothing matches. Clear the search to see every field.' : 'This page has nothing the Studio can edit yet.'));
    $('#pageCount').textContent = shown + (shown === 1 ? ' field' : ' fields');
  }
  function seoVal(k) { return PG.draft.seo && PG.draft.seo[k] != null ? PG.draft.seo[k] : (PG.page.seo[k] || ''); }
  function setSeo(k, v) {
    var s = PG.draft.seo || {};
    if (v === (PG.page.seo[k] || '')) delete s[k]; else s[k] = v;
    if (Object.keys(s).length) PG.draft.seo = s; else delete PG.draft.seo; pDirty();
  }
  function renderSeo() {
    var has = PG.page.seo || {};
    $('#seoTitle').closest('.fld').hidden = has.title == null; $('#seoDesc').closest('.fld').hidden = has.description == null;
    $('#seoTitle').value = seoVal('title'); $('#seoDesc').value = seoVal('description'); seoCounts();
    var box = $('#seoImagePick');
    if (has.image == null) { box.hidden = true; return; }
    box.hidden = false;
    var v = seoVal('image');
    box.innerHTML = '<span class="cms-pick-l">Share picture</span>' + (v ? '<img src="' + esc(v) + '" alt="">' : '<span class="cms-noimg">None chosen</span>');
    var b = el('button', 'btn btn-outline btn-sm', 'Change'); b.type = 'button';
    b.addEventListener('click', function () { openPicker('image', function (m) { setSeo('image', m.url); renderSeo(); }); });
    box.appendChild(b);
  }
  function seoCounts() {
    var t = $('#seoTitle').value.length, d = $('#seoDesc').value.length;
    $('#seoTitleN').textContent = t + ' / 60'; $('#seoTitleN').classList.toggle('is-over', t > 60);
    $('#seoDescN').textContent = d + ' / 155'; $('#seoDescN').classList.toggle('is-over', d > 155);
  }
  function wirePageEditor() {
    var deb; $('#pageFind').addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(renderFields, 150); });
    $('#pageOnlyChanged').addEventListener('change', renderFields);
    $('#seoTitle').addEventListener('input', function () { setSeo('title', this.value.replace(/\s+/g, ' ').trim()); seoCounts(); });
    $('#seoDesc').addEventListener('input', function () { setSeo('description', this.value.replace(/\s+/g, ' ').trim()); seoCounts(); });
  }

  /* ══ Site: one workspace for everything the site lets staff edit ═══════
     lib/Cms.php keeps one life for each document — the hero, every page, the
     site details: a draft, review, publish now or on a schedule, and a
     history of versions to compare and put back. The editors above (hero,
     page fields) only say what the document IS; this part is everything
     around it, so the hero and a page cannot drift into behaving differently. */
  var W = { ref: '', doc: null, dirty: false, canPublish: false, docs: [], revs: false };
  var STATE = { live: ['Live', 'ok'], draft: ['Draft not published', 'warn'], review: ['Waiting for review', 'warn'], scheduled: ['Scheduled', 'ok'] };
  var ACT = { save: 'Draft saved', submit: 'Sent for review', reject: 'Sent back', publish: 'Published', baseline: 'As it was before the Studio',
    schedule: 'Scheduled', unschedule: 'Schedule cancelled', discard: 'Draft discarded', restore: 'An earlier version made the draft', failed: 'Scheduled publish failed' };
  function when(utc) {
    if (!utc) return '';
    var d = new Date(String(utc).replace(' ', 'T') + 'Z');
    if (isNaN(d)) return utc;
    // Lagos time, like the schedule picker, wherever the person editing is.
    try { return d.toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Lagos' }) + ' WAT'; } catch (e) { return utc; }
  }
  function clone(o) { return o == null ? o : JSON.parse(JSON.stringify(o)); }
  function wState(text, kind) { var s = $('#docState'); s.textContent = text; s.className = 'cms-state' + (kind ? ' is-' + kind : ''); }
  function markDirty() { if (!W.ref) return; W.dirty = true; wState('Unsaved changes', 'warn'); }

  /* the contents tree */
  function loadTree(open) {
    return A().api('cms_overview').then(function (r) {
      var d = r.data || {}, ul = $('#siteTree');
      if (!d.ok) { ul.innerHTML = '<li class="cms-msg">Could not load the site’s content. <button type="button" class="btn btn-outline btn-sm" data-retry>Try again</button></li>'; return; }
      W.docs = d.docs; W.canPublish = !!d.can_publish; ul.innerHTML = '';
      var items = [{ ref: '', title: 'Overview', sub: 'What is waiting, and what changed' }].concat(d.docs.map(function (x) {
        return { ref: x.ref, title: x.title, sub: x.type === 'page' ? x.href : x.type === 'hero' ? 'The top of the homepage' : 'Footer email, place, social links', state: x.state, changes: x.changes, at: x.scheduled_at };
      }));
      var group = '';
      items.forEach(function (it) {
        var g = it.ref === '' ? '' : it.ref === 'site' ? 'Everywhere' : it.ref === 'hero' ? 'Homepage' : 'Pages';
        if (g && g !== group) { group = g; ul.appendChild(el('li', 'cms-tree-g', esc(g))); }
        var li = el('li'), b = el('button', 'cms-pbtn' + (it.ref === W.ref ? ' is-on' : ''));
        b.type = 'button'; b.setAttribute('aria-current', it.ref === W.ref ? 'page' : 'false'); b.setAttribute('data-ref', it.ref);
        var chip = it.state && it.state !== 'live' ? '<span class="cms-pw cms-pw--' + it.state + '">' + esc(STATE[it.state][0]) + (it.state === 'scheduled' && it.at ? ' · ' + esc(when(it.at)) : '') + '</span>' : '';
        b.innerHTML = '<span class="cms-pn">' + esc(it.title) + '</span><span class="cms-pa">' + esc(it.sub) + '</span>' + chip;
        li.appendChild(b); ul.appendChild(li);
      });
      var waiting = d.docs.filter(function (x) { return x.state === 'review'; }).length, drafts = d.docs.filter(function (x) { return x.state !== 'live'; }).length;
      var badge = $('#siteBadge'); badge.hidden = !drafts; badge.textContent = W.canPublish && waiting ? waiting : (drafts || '');
      if (open !== undefined) openRef(open, true);
      else if (!W.ref) renderOverview();
    });
  }
  function openRef(ref, force) {
    if (!force && ref === W.ref) return;
    if (W.dirty && !confirm('You have unsaved changes. Leave without saving them?')) return;
    W.dirty = false; W.ref = ref;
    $$('#siteTree .cms-pbtn').forEach(function (b) { var on = b.getAttribute('data-ref') === ref; b.classList.toggle('is-on', on); b.setAttribute('aria-current', on ? 'page' : 'false'); });
    // The overview is always fetched fresh: it is where someone checks what waits for them.
    if (!ref) { $('#paneDoc').hidden = true; $('#paneOverview').hidden = false; if (force) renderOverview(); else loadTree(); return; }
    loadDoc(ref);
  }
  function renderOverview() {
    var box = $('#ovGroups'); box.innerHTML = '';
    var by = function (st) { return W.docs.filter(function (x) { return x.state === st; }); };
    var groups = [
      ['review', W.canPublish ? 'Waiting for your review' : 'Waiting for review', 'Nothing is waiting for review.'],
      ['scheduled', 'Scheduled to go live', 'Nothing is scheduled.'],
      ['draft', 'Drafts not yet published', 'No drafts. Everything matches the live site.']
    ];
    groups.forEach(function (g) {
      var list = by(g[0]), sec = el('section', 'side-card');
      sec.appendChild(el('h3', null, esc(g[1]) + (list.length ? ' <span class="cms-count">' + list.length + '</span>' : '')));
      if (!list.length) { sec.appendChild(el('p', 'muted tiny', esc(g[2]))); box.appendChild(sec); return; }
      var ul = el('ul', 'cms-ovlist');
      list.forEach(function (x) {
        var li = el('li'), b = el('button', 'btn btn-outline btn-sm', 'Open'); b.type = 'button';
        b.setAttribute('aria-label', 'Open ' + x.title);
        b.addEventListener('click', function () { openRef(x.ref); });
        var meta = g[0] === 'scheduled' ? 'Goes live ' + when(x.scheduled_at) : g[0] === 'review' ? 'Sent by ' + (x.submitted_by || 'someone') + (x.note ? ': “' + x.note + '”' : '') : (x.changes ? x.changes + (x.changes === 1 ? ' change' : ' changes') + ' · ' : '') + 'last by ' + (x.updated_by || 'someone') + ' ' + when(x.updated_at);
        li.innerHTML = '<span><strong>' + esc(x.title) + '</strong><br><span class="muted tiny">' + esc(meta) + '</span></span>';
        li.appendChild(b); ul.appendChild(li);
      });
      sec.appendChild(ul); box.appendChild(sec);
    });
    var all = el('section', 'side-card'); all.appendChild(el('h3', null, 'Last published'));
    var ul2 = el('ul', 'cms-ovlist');
    W.docs.forEach(function (x) { ul2.appendChild(el('li', null, '<span>' + esc(x.title) + '</span><span class="muted tiny">' + (x.published_at ? esc(when(x.published_at)) : 'Not changed from the Studio yet') + '</span>')); });
    all.appendChild(ul2); box.appendChild(all);
  }

  /* one document */
  function loadDoc(ref) {
    say($('#docMsg'), ''); $('#paneOverview').hidden = true; $('#paneDoc').hidden = false;
    $('#docTitle').textContent = 'Loading…'; ['#edHero', '#edPage', '#edSite'].forEach(function (s) { $(s).hidden = true; });
    return A().api('cms_get&ref=' + encodeURIComponent(ref)).then(function (r) {
      var d = r.data || {};
      if (ref !== W.ref) return;
      if (!d.ok) { $('#docTitle').textContent = 'Could not load'; say($('#docMsg'), d.error || 'Could not load this. Try again.'); return; }
      W.doc = d; W.dirty = false; W.canPublish = !!d.can_publish;
      $('#docTitle').textContent = d.title; $('#docLive').href = d.href; $('#docPreview').href = d.preview;
      if (d.type === 'hero') { H.doc = clone(d.draft || d.live); renderHero(); $('#edHero').hidden = false; }
      if (d.type === 'page') {
        PG.page = { fields: d.fields, seo: d.seo || {}, name: d.title };
        PG.draft = clone(d.draft) || {}; if (Array.isArray(PG.draft)) PG.draft = {};
        renderSeo(); renderFields(); $('#edPage').hidden = false;
        if (!d.writable) say($('#docMsg'), 'The server cannot write this page yet, so publishing will fail. In cPanel → File Manager, set the file to 644 and its folder to 755.');
      }
      if (d.type === 'site') { renderSettings(d.draft || d.live, d.socials); $('#edSite').hidden = false; }
      renderWorkflow(); if (W.revs) renderRevs();
    }, function () { say($('#docMsg'), 'The Studio could not be reached. Try again.'); });
  }
  function collect() {
    if (W.doc.type === 'hero') { H.doc.interval = +$('#homeInterval').value; return H.doc; }
    if (W.doc.type === 'page') return PG.draft;
    var soc = {}; $$('#setSocials input').forEach(function (i) { soc[i.getAttribute('data-net')] = i.value.trim(); });
    return { email: $('#set_email').value.trim(), place: $('#set_place').value.trim(), country: $('#set_country').value.trim(), copyright: $('#set_copyright').value.trim(), social: soc };
  }
  function renderSettings(v, nets) {
    $('#set_email').value = v.email || ''; $('#set_place').value = v.place || ''; $('#set_country').value = v.country || ''; $('#set_copyright').value = v.copyright || '';
    var box = $('#setSocials'); box.innerHTML = '';
    Object.keys(nets || {}).forEach(function (k) {
      var l = el('label', 'fld', '<span>' + esc(nets[k]) + '</span>'), i = el('input');
      i.type = 'url'; i.maxLength = 300; i.placeholder = 'https://'; i.value = (v.social || {})[k] || ''; i.setAttribute('data-net', k);
      l.appendChild(i); box.appendChild(l);
    });
  }
  function renderWorkflow() {
    var d = W.doc, st = d.state || 'live', pub = W.canPublish, hasDraft = d.draft != null && !(d.type === 'page' && !Object.keys(d.draft || {}).length);
    wState(STATE[st][0] + (st === 'scheduled' ? ' · ' + when(d.scheduled_at) : ''), STATE[st][1]);
    $('#docDiscard').hidden = !hasDraft;
    $('#wfSubmit').hidden = pub || st === 'review';
    $('#wfPublish').hidden = !pub; $('#wfPublish').textContent = st === 'review' ? 'Approve & publish' : 'Publish';
    $('#wfSchedule').hidden = !pub || st === 'scheduled';
    $('#wfUnschedule').hidden = !pub || st !== 'scheduled';
    $('#wfReject').hidden = !pub || st !== 'review';
    var note = '';
    if (st === 'review') note = 'Sent for review by ' + (d.submitted_by || 'someone') + (d.note ? ': “' + d.note + '”' : '.') + (pub ? '' : ' An admin will publish it or send it back.');
    else if (st === 'scheduled') note = 'This draft goes live by itself on ' + when(d.scheduled_at) + '. Editing it takes it off the schedule.';
    else if (st === 'draft' && d.note) note = 'Sent back: “' + d.note + '”';
    var n = $('#docNote'); n.textContent = note; n.hidden = !note;
    $('#revCmpDraft').hidden = !hasDraft;
  }
  function saveDoc(quiet) {
    var b = $('#docSave'); b.disabled = true;
    return A().post('cms_save', { ref: W.ref, data: collect() }).then(function (r) {
      b.disabled = false; var d = r.data || {};
      if (!d.ok) { say($('#docMsg'), d.error || 'Could not save. Your changes are still here; try again.'); wState('Not saved', 'bad'); return false; }
      say($('#docMsg'), ''); W.dirty = false;
      if (!quiet) A().toast(d.changes ? 'Draft saved' : 'Nothing differs from the live site');
      return loadDoc(W.ref).then(function () { loadTree(); return true; });
    }, function () { b.disabled = false; say($('#docMsg'), 'The Studio could not be reached. Your changes are still here; try again.'); wState('Not saved', 'bad'); return false; });
  }
  /* Ask with the Studio's own dialog: a note, a time, or just a yes. */
  function ask(o) {
    return new Promise(function (done) {
      var dlg = $('#wfDialog');
      $('#wfDlgTitle').textContent = o.title; $('#wfDlgText').textContent = o.text || '';
      $('#wfAtWrap').hidden = !o.at; $('#wfNoteWrap').hidden = !o.note; $('#wfNoteLabel').textContent = o.noteLabel || 'Note (optional)';
      $('#wfNote').value = ''; $('#wfDlgGo').textContent = o.go || 'OK'; say($('#wfDlgMsg'), '');
      if (o.at) { var t = new Date(Date.now() + 3600e3); t.setMinutes(0, 0, 0); var lagos = new Date(t.getTime() + (60 + t.getTimezoneOffset()) * 60e3);
        var p2 = function (n) { return ('0' + n).slice(-2); };
        $('#wfAt').value = lagos.getFullYear() + '-' + p2(lagos.getMonth() + 1) + '-' + p2(lagos.getDate()) + 'T' + p2(lagos.getHours()) + ':00'; }
      var onClose = function () {
        dlg.removeEventListener('close', onClose);
        if (dlg.returnValue !== 'go') return done(null);
        done({ note: $('#wfNote').value.trim(), at: $('#wfAt').value });
      };
      dlg.addEventListener('close', onClose);
      dlg.returnValue = ''; dlg.showModal();
      (o.at ? $('#wfAt') : o.note ? $('#wfNote') : $('#wfDlgGo')).focus();
    });
  }
  function act(action, payload, okText) {
    return A().post(action, Object.assign({ ref: W.ref }, payload || {})).then(function (r) {
      var d = r.data || {};
      if (!d.ok) { say($('#docMsg'), d.error || 'That did not work. Try again.'); return false; }
      say($('#docMsg'), ''); A().toast(okText + (d.pages ? ' · ' + d.pages + (d.pages === 1 ? ' page updated' : ' pages updated') : ''));
      loadDoc(W.ref); loadTree(); return true;
    }, function () { say($('#docMsg'), 'The Studio could not be reached. Nothing changed; try again.'); return false; });
  }
  function firstSave() { return W.dirty ? saveDoc(true) : Promise.resolve(true); }
  function wireWorkflow() {
    $('#siteTree').addEventListener('click', function (e) {
      if (e.target.closest('[data-retry]')) return loadTree();
      var b = e.target.closest('.cms-pbtn'); if (b) openRef(b.getAttribute('data-ref'));
    });
    $('#edSite').addEventListener('input', markDirty);
    $('#docSave').addEventListener('click', function () { saveDoc(false); });
    $('#docPreview').addEventListener('click', function (e) {
      if (!W.dirty) return; e.preventDefault(); var href = this.href;
      saveDoc(true).then(function (ok) { if (ok) window.open(href, '_blank', 'noopener'); });
    });
    $('#docDiscard').addEventListener('click', function () {
      if (!confirm('Throw away the draft and go back to what is live?')) return;
      W.dirty = false; act('cms_discard', {}, 'Draft discarded');
    });
    $('#wfSubmit').addEventListener('click', function () {
      firstSave().then(function (ok) { if (!ok) return;
        ask({ title: 'Send for review', text: 'An admin will check it and publish it, or send it back with a note.', note: true, noteLabel: 'Anything they should know? (optional)', go: 'Send for review' })
          .then(function (a) { if (a) act('cms_submit', { note: a.note }, 'Sent for review'); });
      });
    });
    $('#wfPublish').addEventListener('click', function () {
      firstSave().then(function (ok) { if (!ok) return;
        var site = W.doc.type === 'site';
        ask({ title: W.doc.state === 'review' ? 'Approve and publish' : 'Publish now', text: site ? 'Every page’s footer changes at once. Every visitor will see it.' : 'Every visitor will see this as soon as it is published.', note: true, noteLabel: 'What changed? (optional, kept in the history)', go: 'Publish' })
          .then(function (a) { if (a) act('cms_publish', { note: a.note }, 'Published'); });
      });
    });
    $('#wfSchedule').addEventListener('click', function () {
      firstSave().then(function (ok) { if (!ok) return;
        ask({ title: 'Schedule', text: 'The draft goes live by itself at this time. Editing it before then takes it off the schedule.', at: true, go: 'Schedule' })
          .then(function (a) { if (a) act('cms_schedule', { at: a.at }, 'Scheduled'); });
      });
    });
    $('#wfUnschedule').addEventListener('click', function () { act('cms_unschedule', {}, 'Schedule cancelled'); });
    $('#wfReject').addEventListener('click', function () {
      ask({ title: 'Send back', text: 'The draft stays as it is; the editor sees your note.', note: true, noteLabel: 'What needs changing?', go: 'Send back' })
        .then(function (a) { if (!a) return; if (!a.note) { say($('#docMsg'), 'Say what needs changing, so the editor knows.'); return; } act('cms_reject', { note: a.note }, 'Sent back'); });
    });
    $('#docHistoryBtn').addEventListener('click', function () {
      W.revs = !W.revs; this.setAttribute('aria-pressed', String(W.revs)); $('#docRevs').hidden = !W.revs;
      $('#paneDoc').classList.toggle('has-revs', W.revs);
      if (W.revs) renderRevs();
    });
    $('#revCmpDraft').addEventListener('click', function () { compare('live', 'draft', 'Live → your draft'); });
    $('#revCmpClose').addEventListener('click', function () { $('#revCompare').hidden = true; });
    window.addEventListener('beforeunload', function (e) { if (W.dirty) { e.preventDefault(); e.returnValue = ''; } });
  }

  /* history and comparing */
  function renderRevs() {
    var ol = $('#revList'); ol.innerHTML = '';
    var tl = (W.doc && W.doc.timeline) || [];
    if (!tl.length) { ol.appendChild(el('li', 'muted tiny', 'Nothing has happened here yet.')); return; }
    var liveId = (tl.filter(function (t) { return t.action === 'publish' || t.action === 'baseline'; })[0] || {}).id;
    tl.forEach(function (t) {
      var li = el('li', 'cms-tl cms-tl--' + t.action);
      li.innerHTML = '<span class="cms-tl-a">' + esc(ACT[t.action] || t.action) + (t.id === liveId ? ' <span class="cms-pw cms-pw--live">Live now</span>' : '') + '</span>' +
        '<span class="muted tiny">' + esc(when(t.at)) + ' · ' + esc(t.by || 'someone') + '</span>' + (t.note ? '<span class="cms-tl-n">' + esc(t.note) + '</span>' : '');
      if (t.data && t.id !== liveId) {
        var row = el('div', 'cms-btnrow');
        var c = el('button', 'btn btn-outline btn-sm', 'Compare with live'); c.type = 'button';
        c.addEventListener('click', function () { compare(String(t.id), 'live', (ACT[t.action] || 'This version') + ' ' + when(t.at) + ' → live'); });
        var r = el('button', 'btn btn-outline btn-sm', 'Make this the draft'); r.type = 'button';
        r.addEventListener('click', function () {
          if (W.dirty && !confirm('This replaces your unsaved changes. Go on?')) return;
          W.dirty = false; act('cms_restore', { revision: t.id }, 'That version is now your draft');
        });
        row.appendChild(c); row.appendChild(r); li.appendChild(row);
      }
      ol.appendChild(li);
    });
  }
  function compare(a, b, title) {
    var box = $('#revCompare'), body = $('#revCmpBody');
    box.hidden = false; $('#revCmpTitle').textContent = title; body.innerHTML = '<p class="muted tiny">Comparing…</p>';
    A().api('cms_compare&ref=' + encodeURIComponent(W.ref) + '&a=' + encodeURIComponent(a) + '&b=' + encodeURIComponent(b)).then(function (r) {
      var d = r.data || {};
      if (!d.ok) { body.innerHTML = '<p class="cms-msg">' + esc(d.error || 'Could not compare.') + '</p>'; return; }
      if (!d.rows.length) { body.innerHTML = '<p class="muted tiny">No differences.</p>'; return; }
      body.innerHTML = '<table class="cms-diff"><thead><tr><th scope="col">What</th><th scope="col">Before</th><th scope="col">After</th></tr></thead><tbody>' +
        d.rows.map(function (x) { return '<tr><th scope="row">' + esc(x.label) + '</th><td class="cms-del">' + (x.before === '' ? '<span class="muted">—</span>' : esc(x.before)) + '</td><td class="cms-ins">' + (x.after === '' ? '<span class="muted">—</span>' : esc(x.after)) + '</td></tr>'; }).join('') +
        '</tbody></table>';
      box.scrollIntoView({ block: 'nearest' });
    });
  }

  /* ══ Dialogs ══ */
  $$('.cms-dialog [data-close]').forEach(function (b) { b.addEventListener('click', function () { b.closest('dialog').close(); }); });

  var wired = {};
  window.AvSite = {
    open: function (which, ref) {
      if (!window.AvAdmin) return;
      if (!wired.common) { wirePicker(); wireEditor(); wired.common = true; }
      if (which === 'media') { if (!wired.media) { wireMedia(); wired.media = true; } loadMedia(true); }
      if (which === 'site') {
        if (!wired.site) { wireHero(); wirePageEditor(); wireWorkflow(); wired.site = true; }
        loadTree(ref || W.ref || '');
      }
    }
  };
})();
