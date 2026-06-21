/* ============================================================
   Diary Studio — admin editor logic (no framework).
   Talks to /admin/api.php with a bearer token kept in sessionStorage.
   ============================================================ */
(function () {
  'use strict';
  var API = '/admin/api.php';
  var TOKEN_KEY = 'av.admin.token';
  var token = sessionStorage.getItem(TOKEN_KEY) || '';
  var editing = null;       // slug being edited (null = new)
  var coverUrl = '';
  var cloudinary = false;

  var $ = function (s) { return document.querySelector(s); };
  var views = { login: $('#loginView'), list: $('#listView'), editor: $('#editorView') };
  function show(view) { Object.keys(views).forEach(function (k) { views[k].hidden = (k !== view); }); $('#logoutBtn').hidden = (view === 'login'); }

  var toastEl = $('#toast'), toastT;
  function toast(m) { toastEl.textContent = m; toastEl.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(function () { toastEl.classList.remove('show'); }, 2600); }

  function api(action, opts) {
    opts = opts || {};
    var headers = opts.headers || {};
    headers['Authorization'] = 'Bearer ' + token;
    return fetch(API + '?action=' + action, {
      method: opts.method || 'GET', headers: headers, body: opts.body
    }).then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); });
  }
  function apiJSON(action, payload) {
    return api(action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
  }

  /* ---- Theme ---- */
  $('#themeToggle').addEventListener('click', function () {
    var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', t);
    try { localStorage.setItem('av.theme', t); } catch (e) {}
    if (window.tinymce && tinymce.activeEditor) initEditorBody(getBody(), true);
  });

  /* ---- Auth ---- */
  $('#loginForm').addEventListener('submit', function (e) {
    e.preventDefault();
    token = $('#tokenInput').value.trim();
    api('ping').then(function (r) {
      if (r.data && r.data.ok) {
        sessionStorage.setItem(TOKEN_KEY, token);
        cloudinary = !!r.data.cloudinary;
        boot();
      } else { $('#loginMsg').textContent = (r.data && r.data.error) || 'Invalid token.'; }
    }).catch(function () { $('#loginMsg').textContent = 'Network error.'; });
  });
  $('#logoutBtn').addEventListener('click', function () {
    sessionStorage.removeItem(TOKEN_KEY); token = ''; show('login');
  });

  /* ---- List ---- */
  function loadList() {
    return api('list').then(function (r) {
      var box = $('#entryList'); box.innerHTML = '';
      if (!r.data.ok) { box.innerHTML = '<p class="muted">Could not load entries.</p>'; return; }
      $('#cloudinaryNote').textContent = cloudinary ? 'Media uploads go to Cloudinary.' : 'Cloudinary not configured — uploads are stored locally under /uploads.';
      r.data.articles.forEach(function (a) {
        var row = document.createElement('div'); row.className = 'entry-row';
        row.innerHTML =
          '<div class="entry-thumb ' + a.gradient + '"' + (a.cover_url ? ' style="background-image:url(\'' + a.cover_url + '\')"' : '') + '></div>' +
          '<div class="entry-info"><div class="entry-title">' + escapeHtml(a.title) + '</div>' +
          '<div class="entry-meta"><span class="badge ' + a.status + '">' + a.status + '</span> ' +
          escapeHtml(a.category) + ' · ' + escapeHtml(a.published) + (a.featured == 1 ? ' · ★ featured' : '') + '</div></div>' +
          '<div class="entry-ops"><button class="btn btn-outline btn-sm" data-edit="' + a.slug + '">Edit</button>' +
          '<button class="btn btn-outline btn-sm danger" data-del="' + a.slug + '">Delete</button></div>';
        box.appendChild(row);
      });
    });
  }
  $('#entryList').addEventListener('click', function (e) {
    var ed = e.target.closest('[data-edit]'); var del = e.target.closest('[data-del]');
    if (ed) openEditor(ed.getAttribute('data-edit'));
    if (del) {
      var slug = del.getAttribute('data-del');
      if (confirm('Delete “' + slug + '”? This cannot be undone.')) {
        apiJSON('delete', { slug: slug }).then(function (r) { toast(r.data.ok ? 'Deleted' : 'Delete failed'); loadList(); });
      }
    }
  });
  $('#newBtn').addEventListener('click', function () { openEditor(null); });
  $('#backBtn').addEventListener('click', function () { show('list'); loadList(); });

  /* ---- Editor ---- */
  function getBody() { return (window.tinymce && tinymce.get('f_body')) ? tinymce.get('f_body').getContent() : $('#f_body').value; }
  function setBody(html) { if (window.tinymce && tinymce.get('f_body')) tinymce.get('f_body').setContent(html || ''); else $('#f_body').value = html || ''; }

  function initEditorBody(initial, reinit) {
    if (!window.tinymce) { $('#f_body').value = initial || ''; $('#f_body').style.minHeight = '480px'; return; } // graceful fallback
    if (window.tinymce && tinymce.get('f_body')) { if (!reinit) { setBody(initial); return; } tinymce.get('f_body').remove(); }
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    tinymce.init({
      selector: '#f_body',
      height: 560,
      menubar: false,
      branding: false,
      promotion: false,
      skin: dark ? 'oxide-dark' : 'oxide',
      content_css: dark ? 'dark' : 'default',
      plugins: 'link lists image media table code autolink quickbars wordcount fullscreen',
      toolbar: 'undo redo | blocks | bold italic | bullist numlist | blockquote link image media table | removeformat code fullscreen',
      block_formats: 'Paragraph=p; Heading=h2; Subheading=h3',
      quickbars_selection_toolbar: 'bold italic | h2 h3 | quicklink blockquote',
      content_style: "body{font-family:Montserrat,system-ui,sans-serif;font-size:17px;line-height:1.7;max-width:720px} h2{font-family:'Cormorant SC',Georgia,serif;font-size:30px} blockquote{border-left:3px solid #f3b416;padding-left:18px;color:#374151}",
      images_upload_handler: function (blobInfo) {
        return new Promise(function (resolve, reject) {
          var fd = new FormData(); fd.append('file', blobInfo.blob(), blobInfo.filename());
          api('upload', { method: 'POST', body: fd }).then(function (r) {
            if (r.data && r.data.ok) resolve(r.data.url); else reject(r.data && r.data.error || 'Upload failed');
          }).catch(function () { reject('Network error'); });
        });
      },
      setup: function (ed) { ed.on('init', function () { if (initial != null) ed.setContent(initial); }); }
    });
  }

  function openEditor(slug) {
    editing = slug; coverUrl = '';
    resetForm();
    Promise.all([api('articles'), api('categories')]).then(function (res) {
      buildCategoryList(res[1].data.categories || []);
      buildRelated(res[0].data.articles || [], slug, []);
      if (slug) {
        api('get&slug=' + encodeURIComponent(slug)).then(function (r) {
          if (!r.data.ok) { toast('Could not load entry'); return; }
          fillForm(r.data.article);
          buildRelated(res[0].data.articles || [], slug, r.data.article.related || []);
        });
      } else {
        initEditorBody('<p></p>', true);
      }
    });
    show('editor');
  }

  function resetForm() {
    ['f_title', 'f_dek', 'f_slug', 'f_authors', 'f_read', 'f_mctitle'].forEach(function (id) { $('#' + id).value = ''; });
    $('#f_status').value = 'draft'; $('#f_category').value = ''; $('#f_gradient').value = 'g-gold';
    $('#f_featured').checked = false; $('#f_date').value = new Date().toISOString().slice(0, 10);
    setCover(''); $('#previewLink').hidden = true;
  }
  function fillForm(a) {
    $('#f_title').value = a.title || ''; $('#f_dek').value = a.dek || '';
    $('#f_slug').value = a.slug || ''; $('#f_authors').value = stripTags(a.authors_html || '');
    $('#f_read').value = a.read_minutes || ''; $('#f_mctitle').value = (a.mc_title || '').replace(/<br\s*\/?>/g, '\n');
    $('#f_status').value = a.status || 'draft'; $('#f_category').value = a.category || '';
    $('#f_gradient').value = a.gradient || 'g-gold'; $('#f_featured').checked = a.featured == 1;
    $('#f_date').value = (a.published_at || '').slice(0, 10);
    setCover(a.cover_url || '');
    initEditorBody(a.body_html || '<p></p>', true);
    var pl = $('#previewLink'); pl.hidden = false; pl.href = '/diary/' + a.slug + '/';
  }

  function buildCategoryList(cats) {
    $('#catList').innerHTML = cats.map(function (c) { return '<option value="' + escapeHtml(c.name) + '">'; }).join('');
  }
  function buildRelated(all, currentSlug, selected) {
    var box = $('#relatedBox'); box.innerHTML = '';
    all.filter(function (a) { return a.slug !== currentSlug; }).forEach(function (a) {
      var id = 'rel_' + a.slug;
      var lab = document.createElement('label'); lab.className = 'rel-item';
      lab.innerHTML = '<input type="checkbox" value="' + a.slug + '" ' + (selected.indexOf(a.slug) !== -1 ? 'checked' : '') + '> <span>' + escapeHtml(a.title) + '</span>';
      box.appendChild(lab);
    });
  }
  function selectedRelated() { return [].slice.call(document.querySelectorAll('#relatedBox input:checked')).map(function (i) { return i.value; }); }

  /* ---- Cover image ---- */
  function setCover(url) {
    coverUrl = url || ''; $('#f_cover').value = coverUrl;
    var p = $('#coverPreview');
    if (coverUrl) { p.style.backgroundImage = 'url("' + coverUrl + '")'; p.classList.add('has'); p.innerHTML = ''; $('#coverClear').hidden = false; }
    else { p.style.backgroundImage = ''; p.classList.remove('has'); p.innerHTML = '<span>No cover yet</span>'; $('#coverClear').hidden = true; }
  }
  $('#coverBtn').addEventListener('click', function () { $('#coverFile').click(); });
  $('#coverClear').addEventListener('click', function () { setCover(''); });
  $('#coverFile').addEventListener('change', function () {
    if (!this.files[0]) return;
    var fd = new FormData(); fd.append('file', this.files[0]);
    toast('Uploading cover…');
    api('upload', { method: 'POST', body: fd }).then(function (r) {
      if (r.data && r.data.ok) { setCover(r.data.url); toast('Cover uploaded'); } else { toast((r.data && r.data.error) || 'Upload failed'); }
    }).catch(function () { toast('Upload error'); });
    this.value = '';
  });

  /* ---- Save ---- */
  function collect(status) {
    return {
      slug: $('#f_slug').value.trim(), title: $('#f_title').value.trim(), dek: $('#f_dek').value.trim(),
      category: $('#f_category').value.trim() || 'Dispatch', authors_html: $('#f_authors').value.trim() || 'The Afrovanguard Team',
      published_at: $('#f_date').value, read_minutes: $('#f_read').value, gradient: $('#f_gradient').value,
      mc_title: $('#f_mctitle').value.trim().replace(/\n/g, '<br/>'), cover_url: coverUrl,
      body_html: getBody(), featured: $('#f_featured').checked, status: status, related: selectedRelated()
    };
  }
  function save(status) {
    if (!$('#f_title').value.trim()) { toast('A title is required'); return; }
    apiJSON('save', collect(status)).then(function (r) {
      if (!r.data.ok) { toast(r.data.error || 'Save failed'); return; }
      editing = r.data.slug; $('#f_slug').value = r.data.slug;
      var pl = $('#previewLink'); pl.hidden = false; pl.href = r.data.url;
      $('#f_status').value = status;
      toast(status === 'published' ? 'Published ✓' : 'Draft saved ✓');
    }).catch(function () { toast('Network error'); });
  }
  $('#saveDraftBtn').addEventListener('click', function () { save('draft'); });
  $('#publishBtn').addEventListener('click', function () { save('published'); });

  /* ---- utils ---- */
  function escapeHtml(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function stripTags(s) { var d = document.createElement('div'); d.innerHTML = s; return d.textContent || ''; }

  /* ---- boot ---- */
  function boot() { show('list'); loadList(); }
  if (token) { api('ping').then(function (r) { if (r.data && r.data.ok) { cloudinary = !!r.data.cloudinary; boot(); } else { show('login'); } }).catch(function () { show('login'); }); }
  else { show('login'); }
})();
