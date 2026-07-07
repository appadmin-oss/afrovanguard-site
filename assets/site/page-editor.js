/* ============================================================
   assets/site/page-editor.js — in-place page editor (admin only).

   Loaded by page-edits.js when the page is opened with ?edit=1.
   Activates only after verifying a signed-in Studio admin session.

   Every text block (headings, paragraphs, list items…), leaf text
   element (spans, buttons, links…) and image on the page becomes
   editable in place — no tagging, no CMS. Edits are keyed by a stable
   structural selector and saved through the admin API; page-edits.js
   applies them for every visitor. Chrome (nav, footer, forms, search,
   celebration banner) is off-limits by design.
   ============================================================ */
(function () {
  'use strict';
  if (window.__avEditorActive) return;
  window.__avEditorActive = true;

  var page = document.body.getAttribute('data-av-page') || '';
  if (!page) return;

  var API = '/admin/api.php';
  var csrf = '';
  var edits = {};        // selector → payload (the working set, starts from saved)
  var defaults = window.__avPageDefaults || {};   // pristine shipped content
  var dirty = false;
  var selected = null;   // currently selected element
  var editingText = false;

  var TEXTBLOCK = { H1: 1, H2: 1, H3: 1, H4: 1, H5: 1, H6: 1, P: 1, LI: 1, BLOCKQUOTE: 1, FIGCAPTION: 1 };
  var LEAF = { SPAN: 1, A: 1, EM: 1, STRONG: 1, B: 1, I: 1, SMALL: 1, DIV: 1, BUTTON: 1, DT: 1, DD: 1, SUMMARY: 1 };
  var SKIP = 'header.site-header,.nav-sub,nav,footer,form,svg,script,style,iframe,input,textarea,select,' +
    '.av-search,.av-drawer,.scrim,.av-celebrate,[data-av-noedit],#ave-bar,#ave-panel,#processingOverlay';

  /* ── helpers ─────────────────────────────────────────────── */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function cssPath(el) {
    var parts = [];
    while (el && el !== document.body && el.parentElement) {
      var i = 1, sib = el;
      while ((sib = sib.previousElementSibling)) { if (sib.tagName === el.tagName) i++; }
      parts.unshift(el.tagName.toLowerCase() + ':nth-of-type(' + i + ')');
      el = el.parentElement;
    }
    return 'body>' + parts.join('>');
  }

  function editableKind(el) {
    if (!el || el.nodeType !== 1 || el.closest(SKIP)) return '';
    if (el.tagName === 'IMG') return 'img';
    if (TEXTBLOCK[el.tagName] && !el.querySelector('input,select,textarea,button,iframe,form,img,video,audio')) return 'block';
    if (LEAF[el.tagName] && el.children.length === 0 && el.textContent.replace(/\s+/g, '') !== '') {
      // an editable block ancestor already covers this text — edit the block instead
      var p = el.parentElement;
      while (p && p !== document.body) {
        if (TEXTBLOCK[p.tagName] && !p.querySelector('input,select,textarea,button,iframe,form,img,video,audio')) return '';
        p = p.parentElement;
      }
      return 'leaf';
    }
    return '';
  }

  function snapshot(el, key) {
    var d = defaults[key] = defaults[key] || {};
    if (el.tagName === 'IMG') {
      if (d.src == null) d.src = el.getAttribute('src') || '';
      if (d.alt == null) d.alt = el.getAttribute('alt') || '';
    } else if (d.html == null) d.html = el.innerHTML;
    if (el.tagName === 'A' && d.href == null) d.href = el.getAttribute('href') || '';
    if (d.display == null) d.display = el.style.display || '';
    return d;
  }

  function setEdit(key, patch) {
    var cur = edits[key] || {};
    Object.keys(patch).forEach(function (k) {
      if (patch[k] === undefined) delete cur[k]; else cur[k] = patch[k];
    });
    if (Object.keys(cur).length === 0) delete edits[key]; else edits[key] = cur;
    dirty = true;
    refreshBar();
  }

  /* ── editor chrome ───────────────────────────────────────── */
  var style = document.createElement('style');
  style.textContent =
    'body.ave-on [data-ave]{cursor:pointer}' +
    'body.ave-on [data-ave]:hover{outline:2px dashed #f3b416;outline-offset:2px}' +
    '.ave-sel{outline:3px solid #f3b416 !important;outline-offset:2px;}' +
    '.ave-hidden{opacity:.35;outline:2px dotted #ef4444 !important}' +
    '.ave-editing{outline:3px solid #0ea5e9 !important;outline-offset:2px;min-height:1em}' +
    '#ave-bar{position:fixed;left:50%;transform:translateX(-50%);bottom:14px;z-index:4000;display:flex;align-items:center;gap:10px;' +
    'background:#15140f;color:#fff;border:1px solid #f3b416;border-radius:999px;padding:9px 16px;font:600 13px/1.3 Montserrat,system-ui,sans-serif;box-shadow:0 12px 40px rgba(0,0,0,.45);flex-wrap:wrap;max-width:96vw}' +
    '#ave-bar .ave-count{color:#f3b416}' +
    '#ave-bar button{border:0;border-radius:999px;padding:8px 15px;font:700 12.5px Montserrat,system-ui,sans-serif;cursor:pointer;min-height:36px}' +
    '#ave-bar button:focus-visible,#ave-panel button:focus-visible{outline:2px solid #fff;outline-offset:2px}' +
    '#ave-save{background:#f3b416;color:#15140f}#ave-discard,#ave-exit{background:rgba(255,255,255,.12);color:#fff}' +
    '#ave-panel{position:fixed;left:50%;transform:translateX(-50%);bottom:70px;z-index:4000;display:none;align-items:center;gap:8px;' +
    'background:#0d1220;color:#fff;border:1px solid rgba(255,255,255,.18);border-radius:12px;padding:8px 12px;font:600 12.5px Montserrat,system-ui,sans-serif;box-shadow:0 12px 40px rgba(0,0,0,.45);flex-wrap:wrap;max-width:96vw}' +
    '#ave-panel.on{display:flex}' +
    '#ave-panel .ave-what{color:#f3b416;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
    '#ave-panel button{border:1px solid rgba(255,255,255,.25);background:transparent;color:#fff;border-radius:8px;padding:6px 11px;font:inherit;cursor:pointer;min-height:34px}';
  document.head.appendChild(style);

  var bar = document.createElement('div');
  bar.id = 'ave-bar';
  bar.setAttribute('role', 'region');
  bar.setAttribute('aria-label', 'Page editor');
  bar.innerHTML = '<span>✏️ Editing this page</span><span class="ave-count" id="ave-count" aria-live="polite"></span>' +
    '<button type="button" id="ave-save">Save</button>' +
    '<button type="button" id="ave-discard">Discard</button>' +
    '<button type="button" id="ave-exit">Exit</button>';

  var panel = document.createElement('div');
  panel.id = 'ave-panel';
  panel.setAttribute('role', 'region');
  panel.setAttribute('aria-label', 'Selected element actions');

  var fileInput = document.createElement('input');
  fileInput.type = 'file'; fileInput.accept = 'image/*'; fileInput.hidden = true;

  function refreshBar() {
    var n = Object.keys(edits).length;
    var c = document.getElementById('ave-count');
    if (c) c.textContent = n ? (n + ' region' + (n === 1 ? '' : 's') + ' customised' + (dirty ? ' — unsaved' : '')) : 'click any outlined element';
  }

  /* ── selection + actions ─────────────────────────────────── */
  function deselect() {
    if (editingText && selected) commitText(selected);
    if (selected) selected.classList.remove('ave-sel');
    selected = null;
    panel.classList.remove('on');
  }

  function select(el) {
    if (selected === el) return;
    deselect();
    selected = el;
    el.classList.add('ave-sel');
    var kind = el.getAttribute('data-ave');
    var key = cssPath(el);
    snapshot(el, key);
    var hidden = !!(edits[key] && edits[key].hide);
    var what = el.tagName === 'IMG' ? 'Image' : (el.textContent.trim().slice(0, 32) || el.tagName.toLowerCase());
    var b = '<span class="ave-what">' + esc(what) + '</span>';
    if (kind !== 'img') b += '<button type="button" data-act="text">Edit text</button>';
    if (kind === 'img') b += '<button type="button" data-act="image">Change image…</button><button type="button" data-act="alt">Alt text…</button>';
    if (el.tagName === 'A') b += '<button type="button" data-act="link">Edit link…</button>';
    b += '<button type="button" data-act="hide">' + (hidden ? 'Show' : 'Hide') + '</button>' +
      '<button type="button" data-act="reset">Reset</button>' +
      '<button type="button" data-act="close">Close</button>';
    panel.innerHTML = b;
    panel.classList.add('on');
  }

  function commitText(el) {
    editingText = false;
    el.removeAttribute('contenteditable');
    el.classList.remove('ave-editing');
    var key = cssPath(el), d = defaults[key] || {};
    if (el.innerHTML === d.html) setEdit(key, { html: undefined });
    else setEdit(key, { html: el.innerHTML });
  }

  function startText(el) {
    editingText = true;
    el.classList.add('ave-editing');
    el.setAttribute('contenteditable', 'true');
    el.focus();
    var done = function () { el.removeEventListener('blur', done); if (editingText) commitText(el); };
    el.addEventListener('blur', done);
  }

  function resetEl(el) {
    var key = cssPath(el), d = defaults[key] || {};
    if (el.tagName === 'IMG') {
      if (d.src != null) el.setAttribute('src', d.src);
      if (d.alt != null) el.setAttribute('alt', d.alt);
    } else if (d.html != null) el.innerHTML = d.html;
    if (el.tagName === 'A' && d.href != null) el.setAttribute('href', d.href);
    el.style.display = d.display || '';
    el.classList.remove('ave-hidden');
    delete edits[key];
    dirty = true;
    refreshBar();
    select(el === selected ? el : el);
    panel.classList.add('on');
  }

  function toggleHide(el) {
    var key = cssPath(el);
    var hidden = !!(edits[key] && edits[key].hide);
    if (hidden) { el.classList.remove('ave-hidden'); setEdit(key, { hide: undefined }); }
    else { el.classList.add('ave-hidden'); setEdit(key, { hide: true }); }
    select(el); panel.classList.add('on');
    // rebuild panel button label
    var btn = panel.querySelector('[data-act="hide"]'); if (btn) btn.textContent = hidden ? 'Hide' : 'Show';
  }

  function changeImage(el) {
    fileInput.onchange = function () {
      var f = fileInput.files && fileInput.files[0];
      fileInput.value = '';
      if (!f) return;
      var fd = new FormData(); fd.append('file', f);
      announce('Uploading image…');
      fetch(API + '?action=upload', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok && d.url) {
            var key = cssPath(el); snapshot(el, key);
            el.setAttribute('src', d.url); setEdit(key, { src: d.url });
            announce('Image updated.');
          } else announce((d && d.error) || 'Upload failed.');
        }).catch(function () { announce('Upload failed — network error.'); });
    };
    fileInput.click();
  }

  function announce(m) {
    var c = document.getElementById('ave-count');
    if (c) c.textContent = m;
  }

  /* ── event wiring (capture phase so page handlers never fire) ── */
  function onClick(e) {
    if (e.target.closest('#ave-bar,#ave-panel')) return;      // editor UI behaves normally
    if (editingText && selected && (selected === e.target || selected.contains(e.target))) return; // typing
    var el = e.target.closest('[data-ave]');
    e.preventDefault(); e.stopPropagation();
    if (editingText && selected) commitText(selected);
    if (el) select(el); else deselect();
  }

  panel.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]'); if (!b || !selected) return;
    var act = b.getAttribute('data-act');
    if (act === 'text') startText(selected);
    else if (act === 'image') changeImage(selected);
    else if (act === 'alt') {
      var key = cssPath(selected); snapshot(selected, key);
      var v = prompt('Describe this image for screen readers:', selected.getAttribute('alt') || '');
      if (v !== null) { selected.setAttribute('alt', v); setEdit(key, { alt: v }); }
    } else if (act === 'link') {
      var k2 = cssPath(selected); snapshot(selected, k2);
      var h = prompt('Link URL:', selected.getAttribute('href') || '');
      if (h !== null && h !== '') { selected.setAttribute('href', h); setEdit(k2, { href: h }); }
    } else if (act === 'hide') toggleHide(selected);
    else if (act === 'reset') resetEl(selected);
    else if (act === 'close') deselect();
  });

  function save() {
    if (editingText && selected) commitText(selected);
    announce('Saving…');
    fetch(API + '?action=page_save', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ page: page, edits: edits })
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.ok) { dirty = false; refreshBar(); announce('Saved ✓ — live for all visitors.'); }
      else announce((d && d.error) || 'Could not save.');
    }).catch(function () { announce('Could not save — network error.'); });
  }

  function exitEditor() {
    if (dirty && !confirm('You have unsaved changes. Leave without saving?')) return;
    window.onbeforeunload = null;
    location.href = location.pathname + location.search.replace(/([?&])edit=1(&|$)/, function (m, a, b) { return b === '&' ? a : ''; }) + location.hash;
  }

  /* ── activation ──────────────────────────────────────────── */
  function activate() {
    document.querySelectorAll('body *').forEach(function (el) {
      var kind = editableKind(el);
      if (kind) el.setAttribute('data-ave', kind);
    });
    // Regions the applier hid for visitors stay visible-but-dimmed in the
    // editor, so they can still be selected and shown again.
    Object.keys(edits).forEach(function (k) {
      if (!edits[k] || !edits[k].hide) return;
      var el; try { el = document.querySelector(k); } catch (e) { return; }
      if (el) { el.style.display = (defaults[k] && defaults[k].display) || ''; el.classList.add('ave-hidden'); }
    });
    document.body.classList.add('ave-on');
    document.body.appendChild(bar);
    document.body.appendChild(panel);
    document.body.appendChild(fileInput);
    document.addEventListener('click', onClick, true);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        if (editingText && selected) { commitText(selected); e.preventDefault(); }
        else deselect();
      }
    });
    document.getElementById('ave-save').addEventListener('click', save);
    document.getElementById('ave-discard').addEventListener('click', function () {
      if (confirm('Discard ALL unsaved changes and reload?')) { window.onbeforeunload = null; location.reload(); }
    });
    document.getElementById('ave-exit').addEventListener('click', exitEditor);
    window.onbeforeunload = function () { if (dirty) return 'Unsaved page edits.'; };
    refreshBar();
  }

  fetch(API + '?action=session', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d || !d.ok) { alert('Page editing needs a Studio sign-in. Open /admin, sign in, then come back to this page with ?edit=1.'); return; }
      csrf = d.csrf || '';
      return fetch(API + '?action=page_get&page=' + encodeURIComponent(page), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (g) { edits = (g && g.edits) || {}; activate(); });
    })
    .catch(function () {});
})();
