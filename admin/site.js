/* admin/site.js — the Studio's content screens: Homepage (lib/SiteHome.php),
   Pages (lib/SitePages.php) and Media (lib/Media.php), the library picker,
   and the picture editor.

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
  var H = { doc: null, dirty: false, canPublish: false };
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
  function state(text, kind) { var s = $('#homeState'); s.textContent = text; s.className = 'cms-state' + (kind ? ' is-' + kind : ''); }
  function dirty() { H.dirty = true; state('Unsaved changes', 'warn'); }
  function loadHome() {
    say($('#homeMsg'), '');
    A().api('home_get').then(function (r) {
      var d = r.data || {};
      if (!d.ok) { say($('#homeMsg'), d.error || 'Could not load the homepage.'); return; }
      H.doc = d.draft; H.dirty = false; H.canPublish = !!d.can_publish;
      $('#homePublish').hidden = !H.canPublish;
      $('#homeInterval').innerHTML = [3, 4, 5, 6, 7, 8, 10, 12, 15, 20].map(function (n) { return '<option value="' + n + '">' + n + ' seconds</option>'; }).join('');
      $('#homeInterval').value = String(H.doc.interval || 6);
      renderMode(); renderSlides(); renderVideo();
      state(d.unpublished ? 'Draft not yet published' : 'Matches the live site', d.unpublished ? 'warn' : 'ok');
      $('#homeBadge').hidden = !d.unpublished; $('#homeBadge').textContent = d.unpublished ? '•' : '';
      var hist = $('#homeHistory'); hist.innerHTML = '';
      if (!d.history.length) hist.innerHTML = '<li class="muted">Nothing published from here yet.</li>';
      d.history.forEach(function (h, i) {
        var li = el('li', null, '<span>' + esc(h.at.replace(' ', ' at ').slice(0, 19)) + ' UTC<br><span class="muted tiny">before a publish by ' + esc(h.by || 'someone') + '</span></span>');
        var b = el('button', 'btn btn-outline btn-sm', 'Restore'); b.type = 'button';
        b.hidden = !H.canPublish;
        b.onclick = function () { if (!confirm('Make this version the draft? The live site does not change until you publish.')) return;
          A().post('home_restore', { index: i }).then(function (x) { var xd = x.data || {}; if (!xd.ok) return say($('#homeMsg'), xd.error); A().toast('Restored as the draft'); loadHome(); }); };
        li.appendChild(b); hist.appendChild(li);
      });
    });
  }
  function saveHome() {
    H.doc.interval = +$('#homeInterval').value;
    return A().post('home_save', { doc: H.doc }).then(function (r) {
      var d = r.data || {};
      if (!d.ok) { say($('#homeMsg'), d.error); state('Not saved', 'bad'); return false; }
      say($('#homeMsg'), ''); H.doc = d.doc; H.dirty = false;
      state(d.unpublished ? 'Draft saved — not yet published' : 'Matches the live site', d.unpublished ? 'warn' : 'ok');
      $('#homeBadge').hidden = !d.unpublished; return true;
    });
  }
  function wireHome() {
    $$('input[name=homeMode]').forEach(function (r) { r.addEventListener('change', function () { H.doc.mode = this.value; renderMode(); dirty(); }); });
    $('#homeInterval').addEventListener('change', function () { H.doc.interval = +this.value; dirty(); });
    ['eyebrow', 'title', 'sub', 'href'].forEach(function (f) { $('#hv_' + f).addEventListener('input', function () { H.doc.video[f] = this.value; dirty(); }); });
    $('#homeAddSlide').addEventListener('click', function () {
      H.doc.slides.push({ image: '', alt: '', eyebrow: '', title: '', sub: '', href: '/', hidden: false }); dirty(); renderSlides();
      var last = $$('#homeSlides .cms-slide').pop(); if (last) $('[data-act=img]', last).focus();
    });
    $('#homeSave').addEventListener('click', function () { saveHome().then(function (ok) { if (ok) A().toast('Draft saved'); }); });
    $('#homePreview').addEventListener('click', function (e) {
      if (!H.dirty) return; e.preventDefault(); var href = this.href;
      saveHome().then(function (ok) { if (ok) window.open(href, '_blank', 'noopener'); });
    });
    $('#homeDiscard').addEventListener('click', function () {
      if (!confirm('Throw away the draft and go back to what is live?')) return;
      A().post('home_discard', {}).then(function () { A().toast('Draft discarded'); loadHome(); });
    });
    $('#homePublish').addEventListener('click', function () {
      var go = function () {
        if (!confirm('Publish this to the homepage now? Every visitor will see it.')) return;
        var b = $('#homePublish'); b.disabled = true;
        A().post('home_publish', {}).then(function (r) {
          b.disabled = false; var d = r.data || {};
          if (!d.ok) { say($('#homeMsg'), d.error); return; }
          A().toast('Published'); loadHome();
        });
      };
      if (H.dirty) saveHome().then(function (ok) { if (ok) go(); }); else go();
    });
    window.addEventListener('beforeunload', function (e) { if (H.dirty) { e.preventDefault(); e.returnValue = ''; } });
  }

  /* ══ Pages (lib/SitePages.php) ═════════════════════════════════════════
     A page's marked words, links and pictures. The draft holds only what
     differs from the live page; a field put back to what is live drops out. */
  var PG = { file: '', page: null, draft: {}, dirty: false, pages: [] };
  var KIND = { h1: 'Main heading', h2: 'Heading', h3: 'Sub-heading', h4: 'Small heading', p: 'Paragraph', li: 'List item',
    figcaption: 'Caption', blockquote: 'Quote', dt: 'Term', dd: 'Definition', a: 'Link', img: 'Picture' };
  function pState(text, kind) { var s = $('#pageState'); s.textContent = text; s.className = 'cms-state' + (kind ? ' is-' + kind : ''); }
  function pCount(d) { var n = 0; Object.keys(d).forEach(function (k) { n += k === 'seo' ? Object.keys(d.seo).length : 1; }); return n; }
  function pDirty() { PG.dirty = true; pState('Unsaved changes', 'warn'); }
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
  function loadPages(select) {
    A().api('pages_list').then(function (r) {
      var d = r.data || {}, ul = $('#pagesList');
      if (!d.ok) { ul.innerHTML = '<li class="cms-msg">Could not load the pages. <button type="button" class="btn btn-outline btn-sm" data-retry>Try again</button></li>'; return; }
      PG.pages = d.pages; ul.innerHTML = '';
      var waiting = 0;
      d.pages.forEach(function (pg) {
        var li = el('li'), b = el('button', 'cms-pbtn' + (pg.file === PG.file ? ' is-on' : ''));
        b.type = 'button'; b.setAttribute('aria-current', pg.file === PG.file ? 'page' : 'false');
        b.innerHTML = '<span class="cms-pn">' + esc(pg.name) + '</span><span class="cms-pa">' + esc(pg.href) + '</span>' +
          (pg.pending ? '<span class="cms-pw">' + pg.pending + (pg.pending === 1 ? ' change waiting' : ' changes waiting') + '</span>' : '');
        b.addEventListener('click', function () { openPage(pg.file); });
        li.appendChild(b); ul.appendChild(li); if (pg.pending) waiting++;
      });
      var badge = $('#pagesBadge'); badge.hidden = !waiting; badge.textContent = waiting || '';
      if (select && !PG.file && d.pages[0]) openPage(d.pages[0].file);
    });
  }
  function openPage(file) {
    if (PG.dirty && file !== PG.file && !confirm('You have unsaved changes on this page. Leave without saving them?')) return;
    PG.file = file; PG.dirty = false; say($('#pageMsg'), '');
    $$('#pagesList .cms-pbtn').forEach(function (b, i) { var on = PG.pages[i] && PG.pages[i].file === file; b.classList.toggle('is-on', on); b.setAttribute('aria-current', on ? 'page' : 'false'); });
    $('#pageEmpty').hidden = false; $('#pageEmpty').textContent = 'Loading…'; $('#pageBody').hidden = true;
    A().api('page_get&file=' + encodeURIComponent(file)).then(function (r) {
      var d = r.data || {};
      if (!d.ok) { $('#pageEmpty').textContent = d.error || 'Could not load this page.'; return; }
      PG.page = d; PG.draft = JSON.parse(JSON.stringify(d.draft || {})); if (Array.isArray(PG.draft)) PG.draft = {};
      $('#pageEmpty').hidden = true; $('#pageBody').hidden = false;
      $('#pageName').textContent = d.name; $('#pageLive').href = d.href;
      $('#pagePreview').href = '/admin/preview.php?page=' + encodeURIComponent(file);
      $('#pagePublish').hidden = !d.can_publish;
      if (!d.writable) say($('#pageMsg'), 'The server cannot write this page yet, so publishing will fail. In cPanel → File Manager, set the file to 644 and its folder to 755.');
      var n = pCount(PG.draft);
      pState(n ? n + (n === 1 ? ' change saved — not yet published' : ' changes saved — not yet published') : 'Matches the live page', n ? 'warn' : 'ok');
      renderSeo(); renderFields(); renderPageHistory();
    });
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
  function renderPageHistory() {
    var ul = $('#pageHistory'); ul.innerHTML = '';
    if (!PG.page.history.length) { ul.appendChild(el('li', 'muted', 'Nothing published from here yet.')); return; }
    PG.page.history.forEach(function (h, i) {
      var li = el('li'); var t = el('span'); t.textContent = h.at.slice(0, 16) + ' UTC · ' + h.by + ' · ' + h.n + (h.n === 1 ? ' change' : ' changes');
      li.appendChild(t);
      if (PG.page.can_publish) {
        var b = el('button', 'btn btn-outline btn-sm', 'Put back'); b.type = 'button';
        b.setAttribute('aria-label', 'Put back what the publish of ' + h.at + ' replaced');
        b.addEventListener('click', function () {
          if (PG.dirty && !confirm('This replaces your unsaved changes. Go on?')) return;
          A().post('page_restore', { file: PG.file, index: i }).then(function (x) { var xd = x.data || {}; if (!xd.ok) return say($('#pageMsg'), xd.error || 'Could not put it back.'); PG.dirty = false; A().toast('Put back as your draft'); openPage(PG.file); loadPages(); });
        });
        li.appendChild(b);
      }
      ul.appendChild(li);
    });
  }
  function savePage() {
    return A().post('page_save', { file: PG.file, draft: PG.draft }).then(function (r) {
      var d = r.data || {};
      if (!d.ok) { say($('#pageMsg'), d.error || 'Could not save. Your changes are still here; try again.'); pState('Not saved', 'bad'); return false; }
      say($('#pageMsg'), ''); PG.dirty = false;
      pState(d.changed ? d.changed + (d.changed === 1 ? ' change saved — not yet published' : ' changes saved — not yet published') : 'Matches the live page', d.changed ? 'warn' : 'ok');
      loadPages(); return true;
    }, function () { say($('#pageMsg'), 'The Studio could not be reached. Your changes are still here; try again.'); pState('Not saved', 'bad'); return false; });
  }
  function wirePages() {
    $('#pagesList').addEventListener('click', function (e) { if (e.target.closest('[data-retry]')) loadPages(true); });
    var deb; $('#pageFind').addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(renderFields, 150); });
    $('#pageOnlyChanged').addEventListener('change', renderFields);
    $('#seoTitle').addEventListener('input', function () { setSeo('title', this.value.replace(/\s+/g, ' ').trim()); seoCounts(); });
    $('#seoDesc').addEventListener('input', function () { setSeo('description', this.value.replace(/\s+/g, ' ').trim()); seoCounts(); });
    $('#pageSave').addEventListener('click', function () { savePage().then(function (ok) { if (ok) A().toast('Draft saved'); }); });
    $('#pagePreview').addEventListener('click', function (e) {
      if (!PG.dirty) return; e.preventDefault(); var href = this.href;
      savePage().then(function (ok) { if (ok) window.open(href, '_blank', 'noopener'); });
    });
    $('#pageDiscard').addEventListener('click', function () {
      if (!confirm('Throw away the draft for this page and go back to what is live?')) return;
      A().post('page_discard', { file: PG.file }).then(function () { PG.dirty = false; A().toast('Draft discarded'); openPage(PG.file); loadPages(); });
    });
    $('#pagePublish').addEventListener('click', function () {
      var go = function () {
        if (!pCount(PG.draft)) { say($('#pageMsg'), 'Nothing to publish: the draft matches the live page.'); return; }
        if (!confirm('Publish these changes to the ' + PG.page.name + ' page now? Every visitor will see them.')) return;
        var b = $('#pagePublish'); b.disabled = true;
        A().post('page_publish', { file: PG.file }).then(function (r) {
          b.disabled = false; var d = r.data || {};
          if (!d.ok) { say($('#pageMsg'), d.error || 'Could not publish. Try again.'); return; }
          A().toast('Published'); openPage(PG.file); loadPages();
        }, function () { b.disabled = false; say($('#pageMsg'), 'The Studio could not be reached. Nothing was published; try again.'); });
      };
      if (PG.dirty) savePage().then(function (ok) { if (ok) go(); }); else go();
    });
    window.addEventListener('beforeunload', function (e) { if (PG.dirty) { e.preventDefault(); e.returnValue = ''; } });
  }

  /* ══ Dialogs ══ */
  $$('.cms-dialog [data-close]').forEach(function (b) { b.addEventListener('click', function () { b.closest('dialog').close(); }); });

  var wired = {};
  window.AvSite = {
    open: function (which) {
      if (!window.AvAdmin) return;
      if (!wired.common) { wirePicker(); wireEditor(); wired.common = true; }
      if (which === 'media') { if (!wired.media) { wireMedia(); wired.media = true; } loadMedia(true); }
      if (which === 'homepage') { if (!wired.home) { wireHome(); wired.home = true; } loadHome(); }
      if (which === 'pages') { if (!wired.pages) { wirePages(); wired.pages = true; } loadPages(true); }
    }
  };
})();
