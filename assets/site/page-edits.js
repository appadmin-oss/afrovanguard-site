/* ============================================================
   assets/site/page-edits.js — applies admin page overrides.

   Pages that opt in carry <body data-av-page="slug">. Overrides are
   authored in-place by an admin (open the page with ?edit=1), stored
   server-side keyed by a structural CSS selector, and patched onto the
   DOM here for every visitor: text/HTML, image src/alt, link href, or
   hide. A selector that no longer matches (page structure changed)
   silently no-ops — the shipped page is always the safe fallback.

   With ?edit=1 this also loads the in-place editor, which activates
   only after verifying an admin session.
   ============================================================ */
(function () {
  'use strict';
  var page = document.body.getAttribute('data-av-page');
  if (!page) return;

  var wantEdit = /(?:\?|&)edit=1(?:&|$)/.test(location.search);
  // Pristine shipped content, captured before a patch touches an element —
  // the editor's per-element "Reset" restores from here.
  var defaults = (window.__avPageDefaults = {});

  function applyOne(sel, p) {
    var el;
    try { el = document.querySelector(sel); } catch (e) { return; }
    if (!el || !p) return;
    var d = defaults[sel] = defaults[sel] || {};
    if (typeof p.html === 'string' && el.tagName !== 'IMG') {
      if (d.html == null) d.html = el.innerHTML;
      el.innerHTML = p.html;
    }
    if (p.src && el.tagName === 'IMG') {
      if (d.src == null) d.src = el.getAttribute('src') || '';
      el.setAttribute('src', p.src);
    }
    if (typeof p.alt === 'string' && el.tagName === 'IMG') {
      if (d.alt == null) d.alt = el.getAttribute('alt') || '';
      el.setAttribute('alt', p.alt);
    }
    if (p.href && el.tagName === 'A') {
      if (d.href == null) d.href = el.getAttribute('href') || '';
      el.setAttribute('href', p.href);
    }
    if (p.hide) {
      if (d.display == null) d.display = el.style.display || '';
      el.style.display = 'none';
      el.setAttribute('data-av-hidden', '1');
    }
  }

  function loadEditor() {
    if (!wantEdit) return;
    var s = document.createElement('script');
    s.src = '/assets/site/page-editor.js';
    s.defer = true;
    document.head.appendChild(s);
  }

  fetch('/api.php?action=page_content&page=' + encodeURIComponent(page), { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var edits = (d && d.edits) || {};
      window.__avPageEdits = edits;
      Object.keys(edits).forEach(function (k) { applyOne(k, edits[k]); });
    })
    .catch(function () { window.__avPageEdits = window.__avPageEdits || {}; })
    .then(loadEditor, loadEditor);
})();
