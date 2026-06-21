/* ============================================================
   Afrovanguard Studio — admin logic (no framework).
   Auth: signed httpOnly cookie + HMAC CSRF token (in memory).
   Manages Diary entries, Academy programmes, and the Inbox.
   ============================================================ */
(function () {
  'use strict';
  var API = '/admin/api.php';
  var csrf = '';            // CSRF token kept in memory only
  var cloudinary = false;
  var coverUrl = '', cCoverUrl = '';

  var $ = function (s) { return document.querySelector(s); };
  var views = {
    login: $('#loginView'), entries: $('#entriesView'), editor: $('#editorView'),
    academy: $('#academyView'), courseEditor: $('#courseEditorView'),
    curriculum: $('#curriculumView'), lessonEditor: $('#lessonEditorView'), inbox: $('#inboxView')
  };
  function show(v) { Object.keys(views).forEach(function (k) { if (views[k]) views[k].hidden = (k !== v); });
    $('#logoutBtn').hidden = (v === 'login'); $('#tabs').hidden = (v === 'login'); }

  var toastEl = $('#toast'), toastT;
  function toast(m) { toastEl.textContent = m; toastEl.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(function () { toastEl.classList.remove('show'); }, 2600); }
  function escapeHtml(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function stripTags(s) { var d = document.createElement('div'); d.innerHTML = s; return d.textContent || ''; }

  function api(action, opts) {
    opts = opts || {};
    var headers = opts.headers || {};
    if (opts.method === 'POST') headers['X-CSRF-Token'] = csrf;
    return fetch(API + '?action=' + action, { method: opts.method || 'GET', headers: headers, body: opts.body, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); });
  }
  function post(action, payload) { return api(action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }); }
  function uploadFile(file) { var fd = new FormData(); fd.append('file', file); return api('upload', { method: 'POST', body: fd }); }

  /* ---- Theme ---- */
  $('#themeToggle').addEventListener('click', function () {
    var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', t); try { localStorage.setItem('av.theme', t); } catch (e) {}
  });

  /* ---- Tabs ---- */
  document.querySelectorAll('.tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('.tab').forEach(function (t) { t.classList.remove('active'); });
      tab.classList.add('active');
      var which = tab.getAttribute('data-tab');
      if (which === 'entries') { show('entries'); loadList(); }
      else if (which === 'academy') { show('academy'); loadCourses(); }
      else { show('inbox'); loadInbox(); }
    });
  });

  /* ---- Auth ---- */
  $('#loginForm').addEventListener('submit', function (e) {
    e.preventDefault();
    post('login', { token: $('#tokenInput').value.trim() }).then(function (r) {
      if (r.data && r.data.ok) { csrf = r.data.csrf; cloudinary = !!r.data.cloudinary; boot(); }
      else { $('#loginMsg').textContent = (r.data && r.data.error) || 'Invalid token.'; }
    }).catch(function () { $('#loginMsg').textContent = 'Network error.'; });
  });
  $('#logoutBtn').addEventListener('click', function () { post('logout', {}).finally(function () { csrf = ''; show('login'); }); });

  /* ---- Diary list ---- */
  function loadList() {
    return api('list').then(function (r) {
      var box = $('#entryList'); box.innerHTML = '';
      if (!r.data.ok) { box.innerHTML = '<p class="muted">Could not load entries.</p>'; return; }
      $('#cloudinaryNote').textContent = cloudinary ? 'Media uploads go to Cloudinary.' : 'Cloudinary not configured — uploads stored locally under /uploads.';
      r.data.articles.forEach(function (a) {
        var row = document.createElement('div'); row.className = 'entry-row';
        row.innerHTML = '<div class="entry-thumb ' + a.gradient + '"' + (a.cover_url ? ' style="background-image:url(\'' + a.cover_url + '\')"' : '') + '></div>' +
          '<div class="entry-info"><div class="entry-title">' + escapeHtml(a.title) + '</div><div class="entry-meta"><span class="badge ' + a.status + '">' + a.status + '</span> ' +
          escapeHtml(a.category) + ' · ' + escapeHtml(a.published) + (a.featured == 1 ? ' · ★' : '') + '</div></div>' +
          '<div class="entry-ops"><button class="btn btn-outline btn-sm" data-edit="' + a.slug + '">Edit</button><button class="btn btn-outline btn-sm danger" data-del="' + a.slug + '">Delete</button></div>';
        box.appendChild(row);
      });
    });
  }
  $('#entryList').addEventListener('click', function (e) {
    var ed = e.target.closest('[data-edit]'), del = e.target.closest('[data-del]');
    if (ed) openEditor(ed.getAttribute('data-edit'));
    if (del && confirm('Delete “' + del.getAttribute('data-del') + '”?')) post('delete', { slug: del.getAttribute('data-del') }).then(function (r) { toast(r.data.ok ? 'Deleted' : 'Failed'); loadList(); });
  });
  $('#newBtn').addEventListener('click', function () { openEditor(null); });
  $('#backBtn').addEventListener('click', function () { show('entries'); loadList(); });

  /* ---- TinyMCE ---- */
  function getBody(id) { return (window.tinymce && tinymce.get(id)) ? tinymce.get(id).getContent() : ($('#' + id) ? $('#' + id).value : ''); }
  function initTiny(id, initial) {
    if (!window.tinymce) { var ta = $('#' + id); if (ta) { ta.value = initial || ''; ta.style.minHeight = '420px'; } return; }
    if (tinymce.get(id)) tinymce.get(id).remove();
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    tinymce.init({
      selector: '#' + id, height: 520, menubar: false, branding: false, promotion: false,
      skin: dark ? 'oxide-dark' : 'oxide', content_css: dark ? 'dark' : 'default',
      plugins: 'link lists image media table code autolink quickbars wordcount fullscreen',
      toolbar: 'undo redo | blocks | bold italic | bullist numlist | blockquote link image media embedBtn | removeformat code fullscreen',
      block_formats: 'Paragraph=p; Heading=h2; Subheading=h3',
      quickbars_selection_toolbar: 'bold italic | h2 h3 | quicklink blockquote',
      media_live_embeds: true,
      content_style: "body{font-family:Montserrat,system-ui,sans-serif;font-size:17px;line-height:1.7;max-width:720px} h2{font-family:'Cormorant SC',Georgia,serif;font-size:30px} blockquote{border-left:3px solid #f3b416;padding-left:18px;color:#666}",
      setup: function (ed) {
        ed.ui.registry.addButton('embedBtn', {
          text: 'Embed', tooltip: 'Embed a video, post, map or audio',
          onAction: function () {
            var url = window.prompt('Paste a link to embed (YouTube, Vimeo, Spotify, X, Instagram, Maps, …):');
            if (url) ed.insertContent('<p>' + url.trim() + '</p>');
          }
        });
        ed.on('init', function () { if (initial != null) ed.setContent(initial); });
      },
      images_upload_handler: function (blobInfo) {
        return new Promise(function (resolve, reject) {
          var fd = new FormData(); fd.append('file', blobInfo.blob(), blobInfo.filename());
          api('upload', { method: 'POST', body: fd }).then(function (r) { r.data && r.data.ok ? resolve(r.data.url) : reject(r.data && r.data.error || 'Upload failed'); }).catch(function () { reject('Network error'); });
        });
      }
    });
  }
  function insertBody(html) {
    if (window.tinymce && tinymce.get('f_body')) tinymce.get('f_body').insertContent(html);
    else { var ta = document.getElementById('f_body'); if (ta) ta.value += '\n' + html; }
  }
  $('#embedBtn').addEventListener('click', function () {
    var url = window.prompt('Paste a link to embed (YouTube, Vimeo, Spotify, X, Instagram, Maps, …):');
    if (url) insertBody('<p>' + url.trim() + '</p>');
  });
  $('#qaBtn').addEventListener('click', function () {
    var q = window.prompt('Question:'); if (!q) return;
    var a = window.prompt('Answer:') || '';
    insertBody('<h3>' + q.trim() + '</h3><p>' + a.trim() + '</p>');
    if ($('#f_format').value === 'standard') $('#f_format').value = 'qa';
  });

  /* ---- Diary editor ---- */
  function openEditor(slug) {
    resetForm();
    Promise.all([api('articles'), api('categories')]).then(function (res) {
      $('#catList').innerHTML = (res[1].data.categories || []).map(function (c) { return '<option value="' + escapeHtml(c.name) + '">'; }).join('');
      buildRelated(res[0].data.articles || [], slug, []);
      if (slug) api('get&slug=' + encodeURIComponent(slug)).then(function (r) { if (r.data.ok) { fillForm(r.data.article); buildRelated(res[0].data.articles || [], slug, r.data.article.related || []); } });
      else initTiny('f_body', '<p></p>');
    });
    show('editor');
  }
  function resetForm() {
    ['f_title', 'f_dek', 'f_slug', 'f_authors', 'f_read'].forEach(function (id) { $('#' + id).value = ''; });
    $('#f_status').value = 'draft'; $('#f_category').value = ''; $('#f_gradient').value = 'g-gold';
    $('#f_format').value = 'standard';
    $('#f_featured').checked = false; $('#f_date').value = new Date().toISOString().slice(0, 10);
    setCover(''); $('#previewLink').hidden = true;
  }
  function fillForm(a) {
    $('#f_title').value = a.title || ''; $('#f_dek').value = a.dek || ''; $('#f_slug').value = a.slug || '';
    $('#f_authors').value = stripTags(a.authors_html || ''); $('#f_read').value = a.read_minutes || '';
    $('#f_status').value = a.status || 'draft'; $('#f_category').value = a.category || ''; $('#f_gradient').value = a.gradient || 'g-gold';
    $('#f_format').value = a.format || 'standard';
    $('#f_featured').checked = a.featured == 1; $('#f_date').value = (a.published_at || '').slice(0, 10);
    setCover(a.cover_url || ''); initTiny('f_body', a.body_html || '<p></p>');
    var pl = $('#previewLink'); pl.hidden = false; pl.href = '/diary/' + a.slug + '/';
  }
  function buildRelated(all, currentSlug, selected) {
    var box = $('#relatedBox'); box.innerHTML = '';
    all.filter(function (a) { return a.slug !== currentSlug; }).forEach(function (a) {
      var lab = document.createElement('label'); lab.className = 'rel-item';
      lab.innerHTML = '<input type="checkbox" value="' + a.slug + '" ' + (selected.indexOf(a.slug) !== -1 ? 'checked' : '') + '> <span>' + escapeHtml(a.title) + '</span>';
      box.appendChild(lab);
    });
  }
  function selectedRelated() { return [].slice.call(document.querySelectorAll('#relatedBox input:checked')).map(function (i) { return i.value; }); }
  function setCover(url) {
    coverUrl = url || ''; $('#f_cover').value = coverUrl; var p = $('#coverPreview');
    if (coverUrl) { p.style.backgroundImage = 'url("' + coverUrl + '")'; p.classList.add('has'); p.innerHTML = ''; $('#coverClear').hidden = false; }
    else { p.style.backgroundImage = ''; p.classList.remove('has'); p.innerHTML = '<span>No cover yet</span>'; $('#coverClear').hidden = true; }
  }
  $('#coverBtn').addEventListener('click', function () { $('#coverFile').click(); });
  $('#coverClear').addEventListener('click', function () { setCover(''); });
  $('#coverFile').addEventListener('change', function () {
    if (!this.files[0]) return; toast('Uploading…');
    uploadFile(this.files[0]).then(function (r) { r.data && r.data.ok ? (setCover(r.data.url), toast('Cover uploaded')) : toast((r.data && r.data.error) || 'Upload failed'); });
    this.value = '';
  });
  function collect(status) {
    return { slug: $('#f_slug').value.trim(), title: $('#f_title').value.trim(), dek: $('#f_dek').value.trim(),
      category: $('#f_category').value.trim() || 'Dispatch', authors_html: $('#f_authors').value.trim() || 'The Afrovanguard Team',
      published_at: $('#f_date').value, read_minutes: $('#f_read').value, gradient: $('#f_gradient').value,
      cover_url: coverUrl, body_html: getBody('f_body'), featured: $('#f_featured').checked, status: status, format: $('#f_format').value, related: selectedRelated() };
  }
  function saveDiary(status) {
    if (!$('#f_title').value.trim()) { toast('A title is required'); return; }
    post('save', collect(status)).then(function (r) {
      if (!r.data.ok) { toast(r.data.error || 'Save failed'); return; }
      $('#f_slug').value = r.data.slug; var pl = $('#previewLink'); pl.hidden = false; pl.href = r.data.url; $('#f_status').value = status;
      toast(status === 'published' ? 'Published ✓' : 'Draft saved ✓');
    }).catch(function () { toast('Network error'); });
  }
  $('#saveDraftBtn').addEventListener('click', function () { saveDiary('draft'); });
  $('#publishBtn').addEventListener('click', function () { saveDiary('published'); });

  /* ---- Academy ---- */
  function loadCourses() {
    return api('ac_list').then(function (r) {
      var box = $('#courseList'); box.innerHTML = '';
      if (!r.data.ok) { box.innerHTML = '<p class="muted">Could not load programmes.</p>'; return; }
      r.data.courses.forEach(function (c) {
        var row = document.createElement('div'); row.className = 'entry-row';
        row.innerHTML = '<div class="entry-thumb ' + c.gradient + '"' + (c.cover_url ? ' style="background-image:url(\'' + c.cover_url + '\')"' : '') + '></div>' +
          '<div class="entry-info"><div class="entry-title">' + escapeHtml(c.title) + '</div><div class="entry-meta"><span class="badge ' + c.status + '">' + c.status + '</span> ' +
          escapeHtml(c.category) + ' · ' + escapeHtml(c.level) + (c.featured == 1 ? ' · ★' : '') + '</div></div>' +
          '<div class="entry-ops"><button class="btn btn-outline btn-sm" data-ccur="' + c.slug + '">Curriculum</button><button class="btn btn-outline btn-sm" data-cedit="' + c.slug + '">Edit</button><button class="btn btn-outline btn-sm danger" data-cdel="' + c.slug + '">Delete</button></div>';
        box.appendChild(row);
      });
    });
  }
  $('#courseList').addEventListener('click', function (e) {
    var ed = e.target.closest('[data-cedit]'), del = e.target.closest('[data-cdel]'), cur = e.target.closest('[data-ccur]');
    if (ed) openCourse(ed.getAttribute('data-cedit'));
    if (cur) openCurriculum(cur.getAttribute('data-ccur'));
    if (del && confirm('Delete “' + del.getAttribute('data-cdel') + '”?')) post('ac_delete', { slug: del.getAttribute('data-cdel') }).then(function (r) { toast(r.data.ok ? 'Deleted' : 'Failed'); loadCourses(); });
  });

  /* ---- Curriculum manager ---- */
  var curSlug = '', curLessonModule = 0, curLessonId = 0;
  function openCurriculum(slug) { curSlug = slug; show('curriculum'); loadCurriculum(); }
  $('#curBackBtn').addEventListener('click', function () { show('academy'); loadCourses(); });
  function loadCurriculum() {
    return api('ac_curriculum&slug=' + encodeURIComponent(curSlug)).then(function (r) {
      if (!r.data.ok) { toast('Could not load'); return; }
      $('#curTitle').textContent = r.data.course.title + ' — curriculum';
      var box = $('#moduleList'); box.innerHTML = '';
      if (!r.data.modules.length) { box.innerHTML = '<p class="muted">No modules yet. Add your first module to start building the curriculum.</p>'; }
      r.data.modules.forEach(function (m) {
        var lessons = m.lessons.map(function (l) {
          return '<div class="entry-row" style="padding:10px 14px"><div class="entry-info"><div class="entry-title" style="font-size:15px">' + escapeHtml(l.title) +
            (l.is_preview == 1 ? ' <span class="badge published">preview</span>' : '') + '</div><div class="entry-meta">' + (l.duration_min || 0) + ' min</div></div>' +
            '<div class="entry-ops"><button class="btn btn-outline btn-sm" data-ledit="' + l.id + '">Edit</button><button class="btn btn-outline btn-sm danger" data-ldel="' + l.id + '">Delete</button></div></div>';
        }).join('');
        var el = document.createElement('div'); el.className = 'side-card'; el.style.marginBottom = '16px';
        el.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px">' +
          '<h3 style="margin:0">' + escapeHtml(m.title) + '</h3><div style="display:flex;gap:6px">' +
          '<button class="btn btn-outline btn-sm" data-mren="' + m.id + '" data-mt="' + escapeHtml(m.title) + '">Rename</button>' +
          '<button class="btn btn-outline btn-sm" data-ladd="' + m.id + '">+ Lesson</button>' +
          '<button class="btn btn-outline btn-sm danger" data-mdel="' + m.id + '">Delete</button></div></div>' +
          (lessons || '<p class="muted" style="margin:0">No lessons yet.</p>');
        box.appendChild(el);
      });
    });
  }
  $('#addModuleBtn').addEventListener('click', function () {
    var t = prompt('Module title:'); if (!t) return;
    post('mod_save', { course: curSlug, title: t.trim() }).then(function () { loadCurriculum(); });
  });
  $('#moduleList').addEventListener('click', function (e) {
    var ren = e.target.closest('[data-mren]'), del = e.target.closest('[data-mdel]'), ladd = e.target.closest('[data-ladd]'), led = e.target.closest('[data-ledit]'), ldel = e.target.closest('[data-ldel]');
    if (ren) { var t = prompt('Rename module:', ren.getAttribute('data-mt')); if (t) post('mod_save', { id: +ren.getAttribute('data-mren'), title: t.trim() }).then(loadCurriculum); }
    if (del && confirm('Delete this module and its lessons?')) post('mod_delete', { id: +del.getAttribute('data-mdel') }).then(loadCurriculum);
    if (ladd) openLesson(+ladd.getAttribute('data-ladd'), 0);
    if (led) openLesson(0, +led.getAttribute('data-ledit'));
    if (ldel && confirm('Delete this lesson?')) post('lesson_delete', { id: +ldel.getAttribute('data-ldel') }).then(loadCurriculum);
  });
  function openLesson(moduleId, lessonId) {
    curLessonModule = moduleId; curLessonId = lessonId;
    ['le_title', 'le_slug', 'le_video'].forEach(function (id) { $('#' + id).value = ''; });
    $('#le_duration').value = '0'; $('#le_preview').checked = false;
    show('lessonEditor');
    if (lessonId) api('lesson_get&id=' + lessonId).then(function (r) {
      if (!r.data.ok) return; var l = r.data.lesson; curLessonModule = +l.module_id;
      $('#le_title').value = l.title || ''; $('#le_slug').value = l.slug || ''; $('#le_video').value = l.video_url || '';
      $('#le_duration').value = l.duration_min || 0; $('#le_preview').checked = l.is_preview == 1;
      initTiny('le_body', l.body_html || '<p></p>');
    });
    else initTiny('le_body', '<p></p>');
  }
  $('#lesBackBtn').addEventListener('click', function () { show('curriculum'); loadCurriculum(); });
  $('#lesSaveBtn').addEventListener('click', function () {
    if (!$('#le_title').value.trim()) { toast('A title is required'); return; }
    post('lesson_save', {
      id: curLessonId, module_id: curLessonModule, title: $('#le_title').value.trim(), slug: $('#le_slug').value.trim(),
      body_html: getBody('le_body'), video_url: $('#le_video').value.trim(), duration_min: $('#le_duration').value, is_preview: $('#le_preview').checked
    }).then(function (r) { if (!r.data.ok) { toast(r.data.error || 'Save failed'); return; } curLessonId = r.data.id; toast('Lesson saved ✓'); });
  });
  $('#newCourseBtn').addEventListener('click', function () { openCourse(null); });
  $('#acBackBtn').addEventListener('click', function () { show('academy'); loadCourses(); });
  function setCCover(url) {
    cCoverUrl = url || ''; $('#c_cover').value = cCoverUrl; var p = $('#cCoverPreview');
    if (cCoverUrl) { p.style.backgroundImage = 'url("' + cCoverUrl + '")'; p.classList.add('has'); p.innerHTML = ''; $('#cCoverClear').hidden = false; }
    else { p.style.backgroundImage = ''; p.classList.remove('has'); p.innerHTML = '<span>No cover yet</span>'; $('#cCoverClear').hidden = true; }
  }
  $('#cCoverBtn').addEventListener('click', function () { $('#cCoverFile').click(); });
  $('#cCoverClear').addEventListener('click', function () { setCCover(''); });
  $('#cCoverFile').addEventListener('change', function () {
    if (!this.files[0]) return; toast('Uploading…');
    uploadFile(this.files[0]).then(function (r) { r.data && r.data.ok ? (setCCover(r.data.url), toast('Cover uploaded')) : toast((r.data && r.data.error) || 'Upload failed'); });
    this.value = '';
  });
  function openCourse(slug) {
    ['c_title', 'c_summary', 'c_slug', 'c_category', 'c_level', 'c_duration', 'c_price', 'c_location', 'c_cta', 'c_outcomes'].forEach(function (id) { $('#' + id).value = ''; });
    $('#c_status').value = 'draft'; $('#c_format').value = 'In-person'; $('#c_gradient').value = 'g-gold'; $('#c_featured').checked = false; $('#c_sort').value = '0';
    setCCover(''); $('#acPreviewLink').hidden = true;
    if (slug) api('ac_get&slug=' + encodeURIComponent(slug)).then(function (r) { if (r.data.ok) fillCourse(r.data.course); });
    else initTiny('c_body', '<p></p>');
    show('courseEditor');
  }
  function fillCourse(c) {
    $('#c_title').value = c.title || ''; $('#c_summary').value = c.summary || ''; $('#c_slug').value = c.slug || '';
    $('#c_category').value = c.category || ''; $('#c_level').value = c.level || ''; $('#c_format').value = c.format || 'In-person';
    $('#c_duration').value = c.duration || ''; $('#c_price').value = c.price || ''; $('#c_location').value = c.location || '';
    $('#c_cta').value = c.cta_url || ''; $('#c_outcomes').value = c.outcomes || ''; $('#c_gradient').value = c.gradient || 'g-gold';
    $('#c_status').value = c.status || 'draft'; $('#c_featured').checked = c.featured == 1; $('#c_sort').value = c.sort || 0;
    setCCover(c.cover_url || ''); initTiny('c_body', c.body_html || '<p></p>');
    var pl = $('#acPreviewLink'); pl.hidden = false; pl.href = '/academy/' + c.slug + '/';
  }
  function collectCourse(status) {
    return { slug: $('#c_slug').value.trim(), title: $('#c_title').value.trim(), summary: $('#c_summary').value.trim(),
      body_html: getBody('c_body'), outcomes: $('#c_outcomes').value.trim(), category: $('#c_category').value.trim() || 'Programme',
      level: $('#c_level').value.trim() || 'All levels', format: $('#c_format').value, duration: $('#c_duration').value.trim(),
      price: $('#c_price').value.trim() || 'Free', location: $('#c_location').value.trim() || 'Alimosho, Lagos',
      cta_url: $('#c_cta').value.trim(), gradient: $('#c_gradient').value, cover_url: cCoverUrl,
      featured: $('#c_featured').checked, status: status, sort: $('#c_sort').value };
  }
  function saveCourse(status) {
    if (!$('#c_title').value.trim()) { toast('A title is required'); return; }
    post('ac_save', collectCourse(status)).then(function (r) {
      if (!r.data.ok) { toast(r.data.error || 'Save failed'); return; }
      $('#c_slug').value = r.data.slug; var pl = $('#acPreviewLink'); pl.hidden = false; pl.href = r.data.url; $('#c_status').value = status;
      toast(status === 'published' ? 'Published ✓' : 'Draft saved ✓');
    }).catch(function () { toast('Network error'); });
  }
  $('#acSaveDraftBtn').addEventListener('click', function () { saveCourse('draft'); });
  $('#acPublishBtn').addEventListener('click', function () { saveCourse('published'); });

  /* ---- Inbox ---- */
  function loadInbox() {
    var box = $('#inboxList'); box.innerHTML = '';
    Promise.all([api('enrollments'), api('subscribers')]).then(function (res) {
      var en = res[0].data, su = res[1].data;
      var h = '<h2 style="font-family:var(--font-heading);font-size:26px;margin:8px 0 14px">Applications</h2>';
      if (en.ok && en.enrollments.length) {
        en.enrollments.forEach(function (m) {
          h += '<div class="inbox-row"><div><strong>' + escapeHtml(m.name) + '</strong> · <a href="mailto:' + escapeHtml(m.email) + '">' + escapeHtml(m.email) + '</a>' +
            (m.phone ? ' · ' + escapeHtml(m.phone) : '') + '<div class="inbox-meta">' + escapeHtml(m.course_slug || '') + ' · ' + escapeHtml(m.created_at) + '</div>' +
            (m.note ? '<p class="inbox-note">' + escapeHtml(m.note) + '</p>' : '') + '</div></div>';
        });
      } else { h += '<p class="muted">No applications yet.</p>'; }
      h += '<h2 style="font-family:var(--font-heading);font-size:26px;margin:32px 0 14px">Newsletter subscribers (' + (su.ok ? su.count : 0) + ')</h2>';
      if (su.ok && su.subscribers.length) {
        h += '<div class="inbox-row" style="display:block">';
        su.subscribers.forEach(function (s) { h += '<div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px dashed var(--divider)"><a href="mailto:' + escapeHtml(s.email) + '">' + escapeHtml(s.email) + '</a><span class="inbox-meta">' + escapeHtml(s.source) + ' · ' + escapeHtml(s.created_at) + '</span></div>'; });
        h += '</div>';
      } else { h += '<p class="muted">No subscribers yet.</p>'; }
      box.innerHTML = h;
    });
  }

  /* ---- boot ---- */
  function boot() { show('entries'); loadList(); }
  api('session').then(function (r) { if (r.data && r.data.ok) { csrf = r.data.csrf; cloudinary = !!r.data.cloudinary; boot(); } else show('login'); }).catch(function () { show('login'); });
})();
