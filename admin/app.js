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
    curriculum: $('#curriculumView'), lessonEditor: $('#lessonEditorView'), inbox: $('#inboxView'), moderation: $('#moderationView'),
    people: $('#peopleView'), personEdit: $('#personEditView'),
    celebrations: $('#celebrationsView'), celEdit: $('#celEditView'), communities: $('#communitiesView'), commEdit: $('#commEditView'), webhooks: $('#webhooksView'), whEdit: $('#whEditView'), system: $('#systemView'), signin: $('#signinView'), members: $('#membersView'), sponsorship: $('#sponsorshipView')
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
  function activateTab(which) {
    document.querySelectorAll('.tab').forEach(function (t) { t.classList.toggle('active', t.getAttribute('data-tab') === which); });
    try { localStorage.setItem('av.studio.tab', which); } catch (e) {}
    if (which === 'entries') { show('entries'); loadList(); }
    else if (which === 'academy') { show('academy'); loadCourses(); }
    else if (which === 'people') { show('people'); loadTeam(); }
    else if (which === 'celebrations') { show('celebrations'); loadCelebrations(); }
    else if (which === 'communities') { show('communities'); loadCommunities(); }
    else if (which === 'webhooks') { show('webhooks'); loadWebhooks(); loadAppTokens(); }
    else if (which === 'system') { show('system'); loadSystem(); }
    else if (which === 'moderation') { show('moderation'); loadModeration(); }
    else if (which === 'signin') { show('signin'); loadAuthPolicy(); loadArt(); }
    else if (which === 'members') { show('members'); loadMembers(); }
    else if (which === 'sponsorship') { show('sponsorship'); loadSponsorship(); }
    else { show('inbox'); loadInbox(); }
    var on = document.querySelector('.tab.active');
    if (on && on.scrollIntoView) { try { on.scrollIntoView({ inline: 'center', block: 'nearest' }); } catch (e) {} }
  }
  document.querySelectorAll('.tab').forEach(function (tab) {
    tab.addEventListener('click', function () { activateTab(tab.getAttribute('data-tab')); });
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
      content_style: "body{font-family:Montserrat,system-ui,sans-serif;font-size:17px;line-height:1.7;max-width:720px} h2{font-family:'Cormorant',Georgia,serif;font-size:30px} blockquote{border-left:3px solid #f3b416;padding-left:18px;color:#666}",
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
  /* quiz builder */
  function quizQuestionEl(q) {
    q = q || { q: '', options: ['', ''], answer: 0 };
    var wrap = document.createElement('div'); wrap.className = 'qz-q'; wrap.style.cssText = 'border:1px solid var(--divider);border-radius:8px;padding:14px;margin-bottom:12px';
    var opts = q.options.map(function (o, i) {
      return '<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><input type="radio" name="ans_PLACEHOLDER" ' + (i === q.answer ? 'checked' : '') + '> ' +
        '<input type="text" class="qz-opt" value="' + escapeHtml(o) + '" placeholder="Option ' + (i + 1) + '" style="flex:1;padding:8px;border:1px solid var(--divider);border-radius:6px;background:var(--bg);color:var(--ink)">' +
        '<button type="button" class="btn btn-outline btn-sm qz-rmopt">✕</button></label>';
    }).join('');
    wrap.innerHTML = '<input type="text" class="qz-prompt" value="' + escapeHtml(q.q) + '" placeholder="Question" style="width:100%;padding:9px;border:1px solid var(--divider);border-radius:6px;background:var(--bg);color:var(--ink);font-weight:600;margin-bottom:10px">' +
      '<div class="qz-opts">' + opts + '</div>' +
      '<div style="display:flex;gap:8px;margin-top:6px"><button type="button" class="btn btn-outline btn-sm qz-addopt">+ Option</button>' +
      '<button type="button" class="btn btn-outline btn-sm danger qz-rmq">Remove question</button><span class="muted" style="font-size:12px;align-self:center">• radio = correct answer</span></div>';
    // unique radio name
    var rn = 'ans_' + Math.random().toString(36).slice(2);
    wrap.querySelectorAll('input[type=radio]').forEach(function (r) { r.name = rn; });
    return wrap;
  }
  $('#qz_add').addEventListener('click', function () { $('#qz_questions').appendChild(quizQuestionEl()); });
  $('#qz_questions').addEventListener('click', function (e) {
    var q = e.target.closest('.qz-q');
    if (e.target.classList.contains('qz-rmq')) { q.remove(); return; }
    if (e.target.classList.contains('qz-addopt')) {
      var rn = q.querySelector('input[type=radio]').name;
      var lab = document.createElement('label'); lab.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px';
      lab.innerHTML = '<input type="radio" name="' + rn + '"> <input type="text" class="qz-opt" placeholder="Option" style="flex:1;padding:8px;border:1px solid var(--divider);border-radius:6px;background:var(--bg);color:var(--ink)"><button type="button" class="btn btn-outline btn-sm qz-rmopt">✕</button>';
      q.querySelector('.qz-opts').appendChild(lab);
    }
    if (e.target.classList.contains('qz-rmopt')) { var l = e.target.closest('label'); if (q.querySelectorAll('.qz-opt').length > 2) l.remove(); }
  });
  function loadQuiz(quizJson) {
    $('#qz_questions').innerHTML = ''; $('#qz_pass').value = 70;
    if (!quizJson) return;
    try { var qz = JSON.parse(quizJson); $('#qz_pass').value = qz.pass || 70; (qz.questions || []).forEach(function (q) { $('#qz_questions').appendChild(quizQuestionEl(q)); }); } catch (e) {}
  }
  function collectQuiz() {
    var qs = [];
    $('#qz_questions').querySelectorAll('.qz-q').forEach(function (q) {
      var prompt = q.querySelector('.qz-prompt').value.trim();
      var opts = [].slice.call(q.querySelectorAll('.qz-opt')).map(function (i) { return i.value.trim(); }).filter(Boolean);
      var radios = [].slice.call(q.querySelectorAll('input[type=radio]'));
      var ans = radios.findIndex(function (r) { return r.checked; }); if (ans < 0) ans = 0;
      if (prompt && opts.length >= 2) qs.push({ q: prompt, options: opts, answer: ans });
    });
    return qs.length ? { pass: parseInt($('#qz_pass').value, 10) || 70, questions: qs } : null;
  }

  function openLesson(moduleId, lessonId) {
    curLessonModule = moduleId; curLessonId = lessonId;
    ['le_title', 'le_slug', 'le_video'].forEach(function (id) { $('#' + id).value = ''; });
    $('#le_duration').value = '0'; $('#le_preview').checked = false; loadQuiz(null);
    show('lessonEditor');
    if (lessonId) api('lesson_get&id=' + lessonId).then(function (r) {
      if (!r.data.ok) return; var l = r.data.lesson; curLessonModule = +l.module_id;
      $('#le_title').value = l.title || ''; $('#le_slug').value = l.slug || ''; $('#le_video').value = l.video_url || '';
      $('#le_duration').value = l.duration_min || 0; $('#le_preview').checked = l.is_preview == 1;
      loadQuiz(l.quiz_json); initTiny('le_body', l.body_html || '<p></p>');
    });
    else initTiny('le_body', '<p></p>');
  }
  $('#lesBackBtn').addEventListener('click', function () { show('curriculum'); loadCurriculum(); });
  $('#lesSaveBtn').addEventListener('click', function () {
    if (!$('#le_title').value.trim()) { toast('A title is required'); return; }
    post('lesson_save', {
      id: curLessonId, module_id: curLessonModule, title: $('#le_title').value.trim(), slug: $('#le_slug').value.trim(),
      body_html: getBody('le_body'), video_url: $('#le_video').value.trim(), duration_min: $('#le_duration').value, is_preview: $('#le_preview').checked,
      quiz: collectQuiz()
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
    $('#c_access').value = c.access_type || 'open'; $('#c_price_ngn').value = c.price_ngn || 0;
    $('#c_instructor').value = c.instructor_email || ''; syncAccess();
    setCCover(c.cover_url || ''); initTiny('c_body', c.body_html || '<p></p>');
    var pl = $('#acPreviewLink'); pl.hidden = false; pl.href = '/academy/' + c.slug + '/';
  }
  function syncAccess() { var w = $('#c_price_ngn_wrap'); if (w) w.hidden = $('#c_access').value !== 'paid'; }
  if ($('#c_access')) { $('#c_access').addEventListener('change', syncAccess); syncAccess(); }
  function collectCourse(status) {
    return { slug: $('#c_slug').value.trim(), title: $('#c_title').value.trim(), summary: $('#c_summary').value.trim(),
      body_html: getBody('c_body'), outcomes: $('#c_outcomes').value.trim(), category: $('#c_category').value.trim() || 'Programme',
      level: $('#c_level').value.trim() || 'All levels', format: $('#c_format').value, duration: $('#c_duration').value.trim(),
      price: $('#c_price').value.trim() || 'Free', location: $('#c_location').value.trim() || 'Alimosho, Lagos',
      cta_url: $('#c_cta').value.trim(), gradient: $('#c_gradient').value, cover_url: cCoverUrl,
      access_type: $('#c_access').value, price_ngn: parseInt($('#c_price_ngn').value, 10) || 0, instructor_email: $('#c_instructor').value.trim(),
      featured: $('#c_featured').checked, status: status, sort: $('#c_sort').value };
  }
  function saveCourse(status) {
    if (!$('#c_title').value.trim()) { toast('A title is required'); return; }
    post('ac_save', collectCourse(status)).then(function (r) {
      if (!r.data.ok) { toast(r.data.error || 'Save failed'); return; }
      $('#c_slug').value = r.data.slug; var pl = $('#acPreviewLink'); pl.hidden = false; pl.href = r.data.url; $('#c_status').value = status;
      toast(r.data.notice || (status === 'published' ? 'Published ✓' : 'Draft saved ✓'));
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

  /* ---- People / Team ---- */
  var TIER_LABEL = { management: 'Management', director: 'Director', patron: 'Patron', ngv: 'NGV', ngg: 'NGG', volunteer: 'Volunteer' };
  var pPhoto = '';
  function setPPhoto(u) {
    pPhoto = u || ''; $('#p_photo').value = pPhoto;
    var pv = $('#pPhotoPreview');
    pv.innerHTML = pPhoto ? '<img src="' + escapeHtml(pPhoto) + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit" />' : '<span>No photo yet</span>';
    $('#pPhotoClear').hidden = !pPhoto;
  }
  function loadTeam() {
    var box = $('#peopleList'); box.innerHTML = '<p class="muted">Loading…</p>';
    api('team_list').then(function (r) {
      if (!r.data || !r.data.ok) { box.innerHTML = '<p class="muted">Could not load people.</p>'; return; }
      var rows = r.data.team || [];
      if (!rows.length) { box.innerHTML = '<p class="muted">No people yet. Add your leadership, team and volunteers.</p>'; return; }
      box.innerHTML = rows.map(function (m) {
        return '<div class="entry-row" data-id="' + m.id + '">' +
          '<div class="entry-info"><div class="entry-title">' + escapeHtml(m.name) +
          (m.featured ? ' <span class="badge published">Spotlight</span>' : '') +
          (m.active ? '' : ' <span class="badge draft">Hidden</span>') + '</div>' +
          '<div class="entry-meta">' + escapeHtml(TIER_LABEL[m.tier] || m.tier) +
          (m.role ? ' · ' + escapeHtml(m.role) : '') + (m.birthday ? ' · 🎂 ' + escapeHtml(m.birthday) : '') +
          '</div></div>' +
          '<div class="entry-ops"><button class="btn btn-outline btn-sm" data-edit="' + m.id + '">Edit</button></div></div>';
      }).join('');
    });
  }
  $('#peopleList').addEventListener('click', function (e) {
    var b = e.target.closest('[data-edit]'); if (b) openPerson(+b.getAttribute('data-edit'));
  });
  $('#newPersonBtn').addEventListener('click', function () { openPerson(null); });
  $('#pBackBtn').addEventListener('click', function () { show('people'); loadTeam(); });
  $('#pPhotoBtn').addEventListener('click', function () { $('#pPhotoFile').click(); });
  $('#pPhotoClear').addEventListener('click', function () { setPPhoto(''); });
  $('#pPhotoFile').addEventListener('change', function () {
    var f = this.files && this.files[0]; if (!f) return;
    toast('Uploading…');
    uploadFile(f).then(function (r) { if (r.data && r.data.ok) { setPPhoto(r.data.url); toast('Photo uploaded.'); } else { toast((r.data && r.data.error) || 'Upload failed.'); } });
  });

  var editingPerson = null;
  function openPerson(id) {
    editingPerson = id;
    $('#personForm').reset();
    setPPhoto('');
    $('#pDeleteBtn').hidden = !id;
    show('personEdit');
    if (!id) return;
    api('team_get&id=' + id).then(function (r) {
      if (!r.data || !r.data.ok) { toast('Not found.'); return; }
      var m = r.data.member, s = m.socials || {};
      $('#p_name').value = m.name || ''; $('#p_role').value = m.role || '';
      $('#p_tagline').value = m.tagline || ''; $('#p_bio').value = m.bio || '';
      $('#p_location').value = m.location || ''; $('#p_tier').value = m.tier || 'volunteer';
      $('#p_featured').checked = !!m.featured; $('#p_operations').checked = !!m.operations;
      $('#p_active').checked = m.active !== false; $('#p_position').value = m.position || 0;
      $('#p_birthday').value = m.birthday || '';
      $('#p_votm_month').value = m.votm_month || m.votmMonth || '';
      $('#p_votm_reason').value = m.votm_reason || ''; $('#p_votm_quote').value = m.votm_quote || '';
      $('#p_li').value = s.li || ''; $('#p_tw').value = s.tw || ''; $('#p_ig').value = s.ig || '';
      $('#p_web').value = s.web || ''; $('#p_email').value = s.email || '';
      setPPhoto(m.photo || '');
    });
  }
  function savePerson() {
    var name = $('#p_name').value.trim();
    if (!name) { toast('A name is required.'); return; }
    var socials = {};
    ['li', 'tw', 'ig', 'web', 'email'].forEach(function (k) { var v = $('#p_' + k).value.trim(); if (v) socials[k] = v; });
    var payload = {
      id: editingPerson || 0, name: name, role: $('#p_role').value.trim(),
      tier: $('#p_tier').value, featured: $('#p_featured').checked, operations: $('#p_operations').checked,
      active: $('#p_active').checked, position: +$('#p_position').value || 0,
      tagline: $('#p_tagline').value.trim(), bio: $('#p_bio').value.trim(), location: $('#p_location').value.trim(),
      photo: pPhoto, socials: socials, birthday: $('#p_birthday').value.trim(),
      votm_month: $('#p_votm_month').value.trim(), votm_reason: $('#p_votm_reason').value.trim(), votm_quote: $('#p_votm_quote').value.trim()
    };
    post('team_save', payload).then(function (r) {
      if (r.data && r.data.ok) { toast('Saved.'); show('people'); loadTeam(); }
      else { toast((r.data && r.data.error) || 'Could not save.'); }
    });
  }
  $('#pSaveBtn').addEventListener('click', savePerson);
  $('#pDeleteBtn').addEventListener('click', function () {
    if (!editingPerson || !confirm('Delete this person?')) return;
    post('team_delete', { id: editingPerson }).then(function () { toast('Deleted.'); show('people'); loadTeam(); });
  });

  /* ---- Celebrations ---- */
  var cDoodle = '';
  function setCDoodle(u) {
    cDoodle = u || ''; $('#c_doodle').value = cDoodle;
    $('#cDoodlePreview').innerHTML = cDoodle ? '<img src="' + escapeHtml(cDoodle) + '" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:inherit;background:#0b0f1a" />' : '<span>No art — uses emoji + colour</span>';
    $('#cDoodleClear').hidden = !cDoodle;
  }
  function loadCelebrations() {
    var box = $('#celList'), bi = $('#celBuiltins');
    box.innerHTML = '<p class="muted">Loading…</p>'; bi.innerHTML = '';
    api('cel_list').then(function (r) {
      if (!r.data || !r.data.ok) { box.innerHTML = '<p class="muted">Could not load.</p>'; return; }
      var rows = r.data.celebrations || [];
      box.innerHTML = rows.length ? rows.map(function (c) {
        return '<div class="entry-row"><div class="entry-info"><div class="entry-title">' + (c.emoji || '🎉') + ' ' + escapeHtml(c.name) +
          (parseInt(c.enabled, 10) ? '' : ' <span class="badge draft">Off</span>') + '</div>' +
          '<div class="entry-meta">' + escapeHtml(c.md) + ' · ' + escapeHtml(c.scope) + (c.key ? ' · overrides “' + escapeHtml(c.key) + '”' : '') + '</div></div>' +
          '<div class="entry-ops"><button class="btn btn-outline btn-sm" data-celedit="' + c.id + '">Edit</button></div></div>';
      }).join('') : '<p class="muted">No custom celebrations yet — the built-in calendar below runs automatically.</p>';
      (r.data.builtins || []).forEach(function (b) {
        bi.innerHTML += '<div class="entry-row"><div class="entry-info"><div class="entry-title">' + b[4] + ' ' + escapeHtml(b[1]) + '</div>' +
          '<div class="entry-meta">' + escapeHtml(b[2]) + ' · ' + escapeHtml(b[3]) + '</div></div></div>';
      });
    });
  }
  $('#celList').addEventListener('click', function (e) { var b = e.target.closest('[data-celedit]'); if (b) openCel(+b.getAttribute('data-celedit')); });
  $('#newCelBtn').addEventListener('click', function () { openCel(null); });
  $('#celBackBtn').addEventListener('click', function () { show('celebrations'); loadCelebrations(); });
  $('#cDoodleBtn').addEventListener('click', function () { $('#cDoodleFile').click(); });
  $('#cDoodleClear').addEventListener('click', function () { setCDoodle(''); });
  $('#cDoodleFile').addEventListener('change', function () {
    var f = this.files && this.files[0]; if (!f) return; toast('Uploading…');
    uploadFile(f).then(function (r) { if (r.data && r.data.ok) { setCDoodle(r.data.url); toast('Art uploaded.'); } else toast((r.data && r.data.error) || 'Upload failed.'); });
  });
  var editingCel = null;
  function openCel(id) {
    editingCel = id; $('#celForm').reset(); setCDoodle(''); $('#c_theme').value = '#f3b416';
    $('#celDeleteBtn').hidden = !id; show('celEdit');
    if (!id) return;
    api('cel_list').then(function (r) {
      var c = (r.data.celebrations || []).filter(function (x) { return +x.id === id; })[0]; if (!c) return;
      $('#c_name').value = c.name || ''; $('#c_message').value = c.message || ''; $('#c_key').value = c.key || '';
      $('#c_md').value = c.md || ''; $('#c_scope').value = c.scope || 'internal'; $('#c_emoji').value = c.emoji || '';
      $('#c_theme').value = /^#[0-9a-f]{6}$/i.test(c.theme) ? c.theme : '#f3b416';
      $('#c_enabled').checked = parseInt(c.enabled, 10) !== 0; setCDoodle(c.doodle_url || '');
    });
  }
  $('#celSaveBtn').addEventListener('click', function () {
    var name = $('#c_name').value.trim();
    if (!name || !/^\d{2}-\d{2}$/.test($('#c_md').value.trim())) { toast('Name and date (MM-DD) are required.'); return; }
    post('cel_save', {
      id: editingCel || 0, name: name, message: $('#c_message').value.trim(), key: $('#c_key').value.trim(),
      md: $('#c_md').value.trim(), scope: $('#c_scope').value, emoji: $('#c_emoji').value.trim() || '🎉',
      theme: $('#c_theme').value, enabled: $('#c_enabled').checked, doodle_url: cDoodle
    }).then(function (r) { if (r.data && r.data.ok) { toast('Saved.'); show('celebrations'); loadCelebrations(); } else toast((r.data && r.data.error) || 'Could not save.'); });
  });
  $('#celDeleteBtn').addEventListener('click', function () {
    if (!editingCel || !confirm('Delete this celebration?')) return;
    post('cel_delete', { id: editingCel }).then(function () { toast('Deleted.'); show('celebrations'); loadCelebrations(); });
  });

  /* ---- Communities (member-portal Spaces / Groups) ---- */
  function loadCommunities() {
    var box = $('#commList');
    box.innerHTML = '<p class="muted">Loading…</p>';
    api('comm_list').then(function (r) {
      if (!r.data || !r.data.ok) { box.innerHTML = '<p class="muted">Could not load.</p>'; return; }
      var rows = r.data.communities || [];
      box.innerHTML = rows.length ? rows.map(function (c) {
        return '<div class="entry-row"><div class="entry-info"><div class="entry-title">' + escapeHtml(c.name) +
          (parseInt(c.enabled, 10) ? '' : ' <span class="badge draft">Hidden</span>') + '</div>' +
          '<div class="entry-meta">' + escapeHtml(c.url) + '</div></div>' +
          '<div class="entry-ops"><button class="btn btn-outline btn-sm" data-commedit="' + c.id + '">Edit</button></div></div>';
      }).join('') : '<p class="muted">No communities yet. Add your Google Chat Spaces or Groups so members can find them in the portal.</p>';
    });
  }
  $('#commList').addEventListener('click', function (e) { var b = e.target.closest('[data-commedit]'); if (b) openComm(+b.getAttribute('data-commedit')); });
  $('#newCommBtn').addEventListener('click', function () { openComm(null); });
  $('#commBackBtn').addEventListener('click', function () { show('communities'); loadCommunities(); });
  var editingComm = null;
  function openComm(id) {
    editingComm = id; $('#commForm').reset(); $('#m_enabled').checked = true; $('#m_sort').value = 0;
    $('#commDeleteBtn').hidden = !id; show('commEdit');
    if (!id) return;
    api('comm_list').then(function (r) {
      var c = (r.data.communities || []).filter(function (x) { return +x.id === id; })[0]; if (!c) return;
      $('#m_name').value = c.name || ''; $('#m_description').value = c.description || ''; $('#m_url').value = c.url || '';
      $('#m_sort').value = parseInt(c.sort, 10) || 0; $('#m_enabled').checked = parseInt(c.enabled, 10) !== 0;
    });
  }
  $('#commSaveBtn').addEventListener('click', function () {
    var name = $('#m_name').value.trim(), url = $('#m_url').value.trim();
    if (!name) { toast('A name is required.'); return; }
    if (!/^https:\/\//i.test(url)) { toast('A valid https:// link is required.'); return; }
    post('comm_save', {
      id: editingComm || 0, name: name, description: $('#m_description').value.trim(),
      url: url, sort: parseInt($('#m_sort').value, 10) || 0, enabled: $('#m_enabled').checked
    }).then(function (r) { if (r.data && r.data.ok) { toast('Saved.'); show('communities'); loadCommunities(); } else toast((r.data && r.data.error) || 'Could not save.'); });
  });
  $('#commDeleteBtn').addEventListener('click', function () {
    if (!editingComm || !confirm('Delete this community?')) return;
    post('comm_delete', { id: editingComm }).then(function () { toast('Deleted.'); show('communities'); loadCommunities(); });
  });

  /* ---- Webhooks (outbound integrations) ---- */
  var whCatalog = {};
  function loadWebhooks() {
    var box = $('#whList'), dlv = $('#whDeliveries');
    box.innerHTML = '<p class="muted">Loading…</p>'; dlv.innerHTML = '';
    api('wh_list').then(function (r) {
      if (!r.data || !r.data.ok) { box.innerHTML = '<p class="muted">Could not load.</p>'; return; }
      whCatalog = r.data.events || {};
      var eps = r.data.endpoints || [];
      box.innerHTML = eps.length ? eps.map(function (e) {
        var ev = (e.events === '*' || !e.events) ? 'all events' : escapeHtml(e.events);
        return '<div class="entry-row"><div class="entry-info"><div class="entry-title">' + escapeHtml(e.url) +
          (parseInt(e.enabled, 10) ? '' : ' <span class="badge draft">Off</span>') + '</div>' +
          '<div class="entry-meta">' + ev + '</div></div>' +
          '<div class="entry-ops"><button class="btn btn-outline btn-sm" data-whtest="' + e.id + '">Test</button><button class="btn btn-outline btn-sm" data-whedit="' + e.id + '">Edit</button></div></div>';
      }).join('') : '<p class="muted">No endpoints yet. Add one to start sending signed events.</p>';
      var ds = r.data.deliveries || [];
      dlv.innerHTML = ds.length ? ds.map(function (d) {
        return '<div class="entry-row"><div class="entry-info"><div class="entry-title">' + escapeHtml(d.event) +
          ' <span class="badge ' + (d.status === 'success' ? '' : 'draft') + '">' + escapeHtml(d.status) + '</span></div>' +
          '<div class="entry-meta">#' + d.id + ' · ' + d.attempts + ' attempt(s) · HTTP ' + (d.last_code || '—') +
          (d.last_error ? ' · ' + escapeHtml(d.last_error) : '') + ' · ' + escapeHtml(d.updated_at) + '</div></div></div>';
      }).join('') : '<p class="muted">No deliveries yet.</p>';
    });
  }
  $('#whList').addEventListener('click', function (e) {
    var ed = e.target.closest('[data-whedit]'), tt = e.target.closest('[data-whtest]');
    if (ed) { openWh(+ed.getAttribute('data-whedit')); return; }
    if (tt) {
      tt.disabled = true; var lbl = tt.textContent; tt.textContent = 'Testing…';
      post('wh_test', { id: +tt.getAttribute('data-whtest') }).then(function (r) {
        var x = (r.data && r.data.result) || {};
        toast(x.ok ? 'Test delivered (HTTP ' + x.code + ').' : ('Test failed: ' + (x.error || ('HTTP ' + (x.code || 0)))));
        loadWebhooks();
      }).catch(function () { toast('Network error.'); }).finally(function () { tt.disabled = false; tt.textContent = lbl; });
    }
  });
  $('#newWhBtn').addEventListener('click', function () { openWh(null); });

  /* ---- API tokens (inbound integrations) ---- */
  var atScopesRendered = false;
  function loadAppTokens() {
    var box = $('#atList'); if (!box) return;
    api('apptoken_list').then(function (r) {
      var d = r.data || {}; if (!d.ok) { box.innerHTML = '<p class="muted">Could not load tokens.</p>'; return; }
      if (!atScopesRendered) {
        $('#atScopes').innerHTML = Object.keys(d.scopes || {}).map(function (k) {
          return '<label class="fld checkbox" style="margin:4px 0"><input type="checkbox" class="at-scope" value="' + escapeHtml(k) +
            '"' + (k === 'community:read' ? ' checked' : '') + '> <span><code>' + escapeHtml(k) + '</code> — ' + escapeHtml(d.scopes[k]) + '</span></label>';
        }).join('');
        atScopesRendered = true;
      }
      var ts = d.tokens || [];
      box.innerHTML = ts.length ? ts.map(function (t) {
        return '<div class="entry-row"><div class="entry-info"><div class="entry-title">' + escapeHtml(t.name) +
          (parseInt(t.revoked, 10) ? ' <span class="badge draft">Revoked</span>' : '') + '</div>' +
          '<div class="entry-meta">' + escapeHtml(t.scopes || '—') + ' · created ' + escapeHtml(t.created_at) +
          (t.last_used ? ' · last used ' + escapeHtml(t.last_used) : ' · never used') + '</div></div>' +
          '<div class="entry-ops">' + (parseInt(t.revoked, 10) ? '' : '<button class="btn btn-outline btn-sm danger" data-atrevoke="' + t.id + '">Revoke</button>') + '</div></div>';
      }).join('') : '<p class="muted">No tokens yet.</p>';
    });
  }
  if ($('#atCreate')) {
    $('#atCreate').addEventListener('click', function () {
      var name = $('#atName').value.trim();
      var scopes = Array.prototype.map.call(document.querySelectorAll('.at-scope:checked'), function (c) { return c.value; });
      if (!scopes.length) { toast('Pick at least one scope.'); return; }
      var btn = this; btn.disabled = true;
      post('apptoken_create', { name: name, scopes: scopes }).then(function (r) {
        if (r.data && r.data.ok) {
          $('#atToken').textContent = r.data.token; $('#atReveal').hidden = false;
          $('#atName').value = ''; toast('Token created — copy it now.'); loadAppTokens();
        } else toast((r.data && r.data.error) || 'Could not create token.');
      }).catch(function () { toast('Network error.'); }).finally(function () { btn.disabled = false; });
    });
    $('#atList').addEventListener('click', function (e) {
      var b = e.target.closest('[data-atrevoke]'); if (!b) return;
      if (!confirm('Revoke this token? Apps using it will stop working immediately.')) return;
      post('apptoken_revoke', { id: +b.getAttribute('data-atrevoke') }).then(function () { toast('Revoked.'); loadAppTokens(); });
    });
  }
  $('#whBackBtn').addEventListener('click', function () { show('webhooks'); loadWebhooks(); });
  var editingWh = null;
  function renderWhEvents(selected) {
    var all = selected === '*' || !selected;
    var sel = all ? [] : String(selected).split(',').map(function (s) { return s.trim(); });
    var html = '<label class="wh-ev" style="display:block;margin:6px 0"><input type="checkbox" id="w_ev_all" ' + (all ? 'checked' : '') + '> <b>All events (*)</b></label>';
    Object.keys(whCatalog).forEach(function (k) {
      html += '<label class="wh-ev" style="display:block;margin:6px 0"><input type="checkbox" class="w-ev" value="' + escapeHtml(k) + '" ' +
        (sel.indexOf(k) >= 0 ? 'checked' : '') + (all ? ' disabled' : '') + '> ' + escapeHtml(k) +
        ' <span class="muted">— ' + escapeHtml(whCatalog[k]) + '</span></label>';
    });
    $('#w_events').innerHTML = html;
    $('#w_ev_all').addEventListener('change', function () {
      document.querySelectorAll('.w-ev').forEach(function (c) { c.disabled = $('#w_ev_all').checked; });
    });
  }
  function openWh(id) {
    editingWh = id; $('#whForm').reset(); $('#w_enabled').checked = true; $('#w_secret').value = '';
    $('#whDeleteBtn').hidden = !id; show('whEdit');
    if (!id) { renderWhEvents('*'); return; }
    api('wh_list').then(function (r) {
      whCatalog = r.data.events || whCatalog;
      var e = (r.data.endpoints || []).filter(function (x) { return +x.id === id; })[0]; if (!e) return;
      $('#w_url').value = e.url || ''; $('#w_secret').value = e.secret || ''; $('#w_enabled').checked = parseInt(e.enabled, 10) !== 0;
      renderWhEvents(e.events || '*');
    });
  }
  $('#whSaveBtn').addEventListener('click', function () {
    var url = $('#w_url').value.trim();
    if (!/^https?:\/\//i.test(url)) { toast('A valid http(s):// URL is required.'); return; }
    var events = '*';
    if (!$('#w_ev_all') || !$('#w_ev_all').checked) {
      var picked = Array.prototype.map.call(document.querySelectorAll('.w-ev:checked'), function (c) { return c.value; });
      events = picked.length ? picked.join(',') : '*';
    }
    post('wh_save', { id: editingWh || 0, url: url, secret: $('#w_secret').value.trim(), events: events, enabled: $('#w_enabled').checked })
      .then(function (r) { if (r.data && r.data.ok) { toast('Saved.'); show('webhooks'); loadWebhooks(); } else toast((r.data && r.data.error) || 'Could not save.'); });
  });
  $('#whDeleteBtn').addEventListener('click', function () {
    if (!editingWh || !confirm('Delete this endpoint and its delivery log?')) return;
    post('wh_delete', { id: editingWh }).then(function () { toast('Deleted.'); show('webhooks'); loadWebhooks(); });
  });

  /* ---- System / Health ---- */
  function loadSystem() {
    var box = $('#sysHealth'); box.innerHTML = '<p class="muted">Checking…</p>';
    var dot = { ok: '#2ea043', warn: '#e0a106', off: '#d22', info: '#8a93a3' };
    api('sys_health').then(function (r) {
      if (!r.data || !r.data.ok) { box.innerHTML = '<p class="muted">Could not load.</p>'; return; }
      box.innerHTML = (r.data.groups || []).map(function (g) {
        return '<div style="margin:0 0 22px"><h2 style="font-family:var(--font-heading);font-size:20px;margin:0 0 8px">' + escapeHtml(g.group) + '</h2>' +
          (g.checks || []).map(function (c) {
            return '<div style="display:flex;align-items:center;gap:10px;padding:7px 2px;border-bottom:1px solid rgba(128,128,128,.15)">' +
              '<span style="width:10px;height:10px;border-radius:50%;flex:0 0 auto;background:' + (dot[c.state] || '#8a93a3') + '"></span>' +
              '<span style="font-weight:600;flex:0 0 230px">' + escapeHtml(c.label) + '</span>' +
              '<span style="color:#8a93a3;font-size:13px">' + escapeHtml(c.detail || '') + '</span></div>';
          }).join('') + '</div>';
      }).join('');
    });
  }
  $('#sysRefreshBtn').addEventListener('click', loadSystem);
  if ($('#mailTestBtn')) {
    $('#mailTestBtn').addEventListener('click', function () {
      var btn = this, msg = $('#mailTestMsg');
      btn.disabled = true; msg.textContent = 'Sending…'; msg.style.color = '';
      post('mail_test', { to: $('#mailTestTo').value.trim() }).then(function (r) {
        var d = r.data || {};
        msg.style.color = d.ok ? '#2ea043' : '#d22';
        msg.textContent = d.ok ? ('✓ ' + (d.detail || 'Sent.') + ' (to ' + d.to + ')') : ('✗ ' + (d.error || d.detail || 'Failed.'));
      }).catch(function () { msg.style.color = '#d22'; msg.textContent = 'Network error.'; })
        .finally(function () { btn.disabled = false; });
    });
  }

  /* ---- boot ---- */
  /* ---- Diary moderation (member public-journal submissions) ---- */
  function modRowHTML(e) {
    var body = String(e.body || '');
    return '<div class="inbox-row mod-row" data-id="' + e.id + '"><div style="flex:1">'
      + '<strong>' + escapeHtml(e.title || '(untitled)') + '</strong>'
      + (e.kind === 'event' ? ' <span class="badge published">Event</span>' : ' <span class="badge draft">Journal</span>')
      + '<div class="inbox-meta">' + escapeHtml(e.author_name || '') + ' · ' + escapeHtml(e.author_email || '') + ' · ' + escapeHtml(e.entry_date || '') + '</div>'
      + '<p class="inbox-note" style="white-space:pre-wrap">' + escapeHtml(body.length > 800 ? body.slice(0, 799) + '…' : body) + '</p>'
      + '<div class="mod-actions" style="display:flex;gap:8px;margin-top:10px">'
      + '<button class="btn btn-primary btn-sm mod-approve" data-id="' + e.id + '">Approve &amp; publish</button>'
      + '<button class="btn btn-outline btn-sm mod-reject" data-id="' + e.id + '">Reject</button>'
      + '</div></div></div>';
  }
  function setModBadge(n) { var b = $('#modBadge'); if (!b) return; if (n > 0) { b.textContent = n; b.hidden = false; } else { b.hidden = true; } }
  function refreshModBadge() { api('mod_queue').then(function (r) { setModBadge((r.data && r.data.ok && r.data.entries) ? r.data.entries.length : 0); }).catch(function () {}); }
  function loadModeration() {
    var box = $('#modList'); box.innerHTML = '<p class="muted">Loading…</p>';
    return api('mod_queue').then(function (r) {
      var d = r.data || {}, rows = (d.ok && d.entries) || [];
      setModBadge(rows.length);
      box.innerHTML = rows.length ? rows.map(modRowHTML).join('') : '<p class="muted">Nothing awaiting review right now. 🎉</p>';
    }).catch(function () { box.innerHTML = '<p class="muted">Could not load the queue.</p>'; });
  }
  (function () {
    var box = $('#modList'); if (!box) return;
    box.addEventListener('click', function (e) {
      var ap = e.target.closest('.mod-approve'), rj = e.target.closest('.mod-reject');
      if (ap) {
        ap.disabled = true;
        post('mod_approve', { id: ap.getAttribute('data-id') }).then(function (r) {
          if (r.data && r.data.ok) { toast('Published to the Diary.'); loadModeration(); }
          else { ap.disabled = false; toast((r.data && r.data.error) || 'Could not approve.'); }
        });
      } else if (rj) {
        var note = prompt('Optional note (why this wasn’t approved):') || '';
        post('mod_reject', { id: rj.getAttribute('data-id'), note: note }).then(function (r) {
          if (r.data && r.data.ok) { toast('Entry rejected.'); loadModeration(); }
          else { toast((r.data && r.data.error) || 'Could not reject.'); }
        });
      }
    });
  })();

  /* ---- Sign-in illustrations (admin-managed + schedulable) ---- */
  var artRows = [];
  function setArtImage(url) {
    $('#art_image').value = url || '';
    var pv = $('#artPreview');
    if (url) { pv.style.backgroundImage = "url('" + url.replace(/'/g, '') + "')"; pv.classList.add('has'); pv.innerHTML = ''; }
    else { pv.style.backgroundImage = ''; pv.classList.remove('has'); pv.innerHTML = '<span>No image yet</span>'; }
  }
  function artScheduleFields() {
    var k = $('#art_kind').value;
    $('#artAnnual').hidden = k !== 'annual';
    $('#artRange').hidden = k !== 'range';
  }
  function artScheduleText(a) {
    if (a.schedule_kind === 'annual') return 'Holiday · ' + (a.start_md || '?') + ' → ' + (a.end_md || '?') + ' (yearly)';
    if (a.schedule_kind === 'range') return 'Range · ' + (a.start_date || '?') + ' → ' + (a.end_date || '?');
    return 'Always — year-round';
  }
  function artRowHTML(a, live) {
    var on = live.indexOf(Number(a.id)) !== -1;
    return '<div class="entry-row" data-id="' + a.id + '">'
      + '<div class="entry-thumb" style="background-image:url(\'' + String(a.image_url).replace(/'/g, '') + '\')"></div>'
      + '<div class="entry-info"><div class="entry-title">' + escapeHtml(a.label || '(untitled)')
      + (on ? ' <span class="badge published">Showing now</span>' : '') + '</div>'
      + '<div class="entry-meta">' + escapeHtml(artScheduleText(a)) + ' · ' + (Number(a.active) ? 'Active' : 'Hidden') + '</div></div>'
      + '<div class="entry-ops">'
      + '<button class="btn btn-outline btn-sm art-toggle" data-id="' + a.id + '">' + (Number(a.active) ? 'Hide' : 'Activate') + '</button>'
      + '<button class="btn btn-outline btn-sm danger art-del" data-id="' + a.id + '">Delete</button>'
      + '</div></div>';
  }
  function loadArt() {
    var box = $('#artList'); box.innerHTML = '<p class="muted">Loading…</p>';
    return api('art_list').then(function (r) {
      var d = r.data || {}; artRows = (d.ok && d.art) || []; var live = d.today || [];
      box.innerHTML = artRows.length
        ? artRows.map(function (a) { return artRowHTML(a, live); }).join('')
        : '<p class="muted">No illustrations yet — the sign-in page falls back to any committed art, then the brand gradient.</p>';
    }).catch(function () { box.innerHTML = '<p class="muted">Could not load.</p>'; });
  }
  function artFormPayload() {
    return {
      image_url: $('#art_image').value, label: $('#art_label').value.trim(),
      schedule_kind: $('#art_kind').value, active: $('#art_active').checked ? 1 : 0,
      start_md: $('#art_start_md').value.trim(), end_md: $('#art_end_md').value.trim(),
      start_date: $('#art_start_date').value, end_date: $('#art_end_date').value
    };
  }
  /* ---- Sign-in security policy ---- */
  function apReflect(p) {
    if (!p) return;
    document.querySelectorAll('#authPolicyForm [data-ap]').forEach(function (el) {
      var k = el.getAttribute('data-ap');
      if (el.type === 'checkbox') el.checked = !!p[k]; else el.value = p[k];
    });
  }
  function loadAuthPolicy() {
    if (!$('#authPolicyForm')) return;
    api('auth_policy_get').then(function (r) {
      var d = r.data || {}; if (!d.ok) return;
      apReflect(d.policy);
      var note = $('#apGoogleNote');
      if (note) note.textContent = d.google_configured ? '— configured' : '— needs AV_GOOGLE_CLIENT_ID (stays off until set)';
    });
  }
  if ($('#signinView')) {
    $('#apSave').addEventListener('click', function () {
      var policy = {};
      document.querySelectorAll('#authPolicyForm [data-ap]').forEach(function (el) {
        var k = el.getAttribute('data-ap');
        policy[k] = el.type === 'checkbox' ? el.checked : el.value;
      });
      var btn = this; btn.disabled = true;
      post('auth_policy_save', { policy: policy }).then(function (r) {
        if (r.data && r.data.ok) { apReflect(r.data.policy); toast('Security settings saved.'); }
        else toast((r.data && r.data.error) || 'Could not save.');
      }).catch(function () { toast('Network error.'); }).finally(function () { btn.disabled = false; });
    });
    $('#art_kind').addEventListener('change', artScheduleFields);
    $('#artUploadBtn').addEventListener('click', function () { $('#artFile').click(); });
    $('#artFile').addEventListener('change', function () {
      if (!this.files || !this.files[0]) return;
      toast('Uploading…');
      uploadFile(this.files[0]).then(function (r) {
        if (r.data && r.data.ok) { setArtImage(r.data.url); toast('Image uploaded.'); }
        else toast((r.data && r.data.error) || 'Upload failed.');
      }).catch(function () { toast('Upload failed.'); });
    });
    $('#artSaveBtn').addEventListener('click', function () {
      var p = artFormPayload();
      if (!p.image_url) { toast('Upload an image first.'); return; }
      this.disabled = true;
      post('art_save', p).then(function (r) {
        if (r.data && r.data.ok) {
          toast('Saved.');
          setArtImage(''); $('#art_label').value = ''; $('#art_kind').value = 'always'; artScheduleFields(); $('#art_active').checked = true;
          loadArt();
        } else toast((r.data && r.data.error) || 'Could not save.');
      }).catch(function () { toast('Network error.'); }).finally(function () { $('#artSaveBtn').disabled = false; });
    });
    $('#artList').addEventListener('click', function (e) {
      var tg = e.target.closest('.art-toggle'), dl = e.target.closest('.art-del');
      if (tg) {
        var a = artRows.filter(function (x) { return String(x.id) === tg.getAttribute('data-id'); })[0]; if (!a) return;
        post('art_save', {
          id: a.id, image_url: a.image_url, label: a.label, schedule_kind: a.schedule_kind,
          active: Number(a.active) ? 0 : 1, start_md: a.start_md, end_md: a.end_md, start_date: a.start_date, end_date: a.end_date
        }).then(function () { loadArt(); });
      } else if (dl) {
        if (!confirm('Delete this illustration?')) return;
        post('art_delete', { id: dl.getAttribute('data-id') }).then(function () { toast('Deleted.'); loadArt(); });
      }
    });
  }

  /* ---- Members (RBAC console) ---- */
  var memRoles = ['learner', 'member', 'mentor', 'coordinator', 'admin'], memT;
  function memCountsHTML(c) {
    return ['total'].concat(memRoles).filter(function (k) { return k === 'total' || c[k]; }).map(function (k) {
      return '<span class="mem-chip"><b>' + (c[k] || 0) + '</b> ' + (k === 'total' ? 'total' : escapeHtml(k)) + '</span>';
    }).join('');
  }
  function memRowHTML(m) {
    var opts = memRoles.map(function (r) { return '<option value="' + r + '"' + (r === m.role ? ' selected' : '') + '>' + r + '</option>'; }).join('');
    var badges = (m.org ? ' <span class="badge published">org</span>' : '') + (m.status === 'suspended' ? ' <span class="badge draft">suspended</span>' : '');
    var seen = m.last_login ? ' · last seen ' + escapeHtml(String(m.last_login).slice(0, 10)) : '';
    return '<div class="entry-row mem-row" data-id="' + m.id + '">'
      + '<div class="entry-info"><div class="entry-title">' + escapeHtml(m.name || '(no name)') + badges + '</div>'
      + '<div class="entry-meta">' + escapeHtml(m.email) + ' · joined ' + escapeHtml(String(m.created_at || '').slice(0, 10)) + seen + '</div></div>'
      + '<div class="entry-ops">'
      + '<select class="mem-role" data-id="' + m.id + '" title="Access level">' + opts + '</select>'
      + '<button class="btn btn-outline btn-sm mem-status" data-id="' + m.id + '" data-to="' + (m.status === 'suspended' ? 'active' : 'suspended') + '">' + (m.status === 'suspended' ? 'Reactivate' : 'Suspend') + '</button>'
      + '</div></div>';
  }
  function memAuditHTML(a) {
    return '<div class="inbox-row"><strong>' + escapeHtml(a.action) + '</strong> ' + escapeHtml(a.target || '')
      + (a.detail ? ' <span class="muted">(' + escapeHtml(a.detail) + ')</span>' : '')
      + '<div class="inbox-meta">' + escapeHtml(String(a.created_at || '')) + '</div></div>';
  }
  function loadMembers() {
    var box = $('#memList'); if (!box) return;
    var q = encodeURIComponent($('#memQ').value.trim());
    box.innerHTML = '<p class="muted">Loading…</p>';
    return api('mem_list&q=' + q + '&role=' + $('#memRole').value + '&status=' + $('#memStatus').value).then(function (r) {
      var d = r.data || {}; if (!d.ok) { box.innerHTML = '<p class="muted">Could not load.</p>'; return; }
      memRoles = d.roles || memRoles;
      $('#memCounts').innerHTML = memCountsHTML(d.counts || {});
      if ($('#memRole').options.length <= 1) { var keep = $('#memRole').value; memRoles.forEach(function (r) { var o = document.createElement('option'); o.value = r; o.textContent = r; $('#memRole').appendChild(o); }); $('#memRole').value = keep; }
      if (!$('#mc_role').options.length) memRoles.forEach(function (r) { var o = document.createElement('option'); o.value = r; o.textContent = r; if (r === 'member') o.selected = true; $('#mc_role').appendChild(o); });
      var rows = d.members || [];
      box.innerHTML = rows.length ? rows.map(memRowHTML).join('') : '<p class="muted">No members match.</p>';
      $('#memAudit').innerHTML = (d.audit || []).length ? d.audit.map(memAuditHTML).join('') : '<p class="muted">No activity yet.</p>';
    }).catch(function () { box.innerHTML = '<p class="muted">Could not load.</p>'; });
  }
  if ($('#membersView')) {
    $('#memQ').addEventListener('input', function () { clearTimeout(memT); memT = setTimeout(loadMembers, 280); });
    $('#memRole').addEventListener('change', loadMembers);
    $('#memStatus').addEventListener('change', loadMembers);
    $('#memNewBtn').addEventListener('click', function () { var c = $('#memCreate'); c.hidden = !c.hidden; if (!c.hidden) $('#mc_name').focus(); });
    $('#memCreateCancel').addEventListener('click', function () { $('#memCreate').hidden = true; });
    $('#memCreateSave').addEventListener('click', function () {
      var p = { name: $('#mc_name').value.trim(), email: $('#mc_email').value.trim(), role: $('#mc_role').value };
      if (!p.email) { toast('An email is required.'); return; }
      this.disabled = true;
      post('mem_create', p).then(function (r) {
        if (r.data && r.data.ok) { toast('Member created.'); $('#mc_name').value = ''; $('#mc_email').value = ''; $('#memCreate').hidden = true; loadMembers(); }
        else toast((r.data && r.data.error) || 'Could not create.');
      }).catch(function () { toast('Network error.'); }).finally(function () { $('#memCreateSave').disabled = false; });
    });
    $('#memList').addEventListener('change', function (e) {
      var sel = e.target.closest('.mem-role'); if (!sel) return;
      post('mem_save', { id: sel.getAttribute('data-id'), role: sel.value }).then(function (r) {
        if (r.data && r.data.ok) { toast('Access level updated.'); loadMembers(); } else toast((r.data && r.data.error) || 'Could not update.');
      });
    });
    $('#memList').addEventListener('click', function (e) {
      var b = e.target.closest('.mem-status'); if (!b) return;
      var to = b.getAttribute('data-to');
      if (to === 'suspended' && !confirm('Suspend this member? They will be signed out.')) return;
      post('mem_save', { id: b.getAttribute('data-id'), status: to }).then(function (r) {
        if (r.data && r.data.ok) { toast(to === 'suspended' ? 'Member suspended.' : 'Member reactivated.'); loadMembers(); } else toast((r.data && r.data.error) || 'Could not update.');
      });
    });
  }

  /* ---- STS Sponsorship (inquiries + cost/impact ledger + sponsor config) ---- */
  var STATUSES = ['new', 'contacted', 'active', 'declined'];
  function ngn(n) { return '₦' + Number(n || 0).toLocaleString(); }

  function loadSponsorship() {
    // Inquiries.
    api('spons_list').then(function (r) {
      var box = $('#sponsList'); box.innerHTML = '';
      if (!r.data.ok) { box.innerHTML = '<p class="muted">Could not load inquiries.</p>'; return; }
      var counts = r.data.counts || {};
      $('#sponsCounts').innerHTML = STATUSES.map(function (s) {
        return '<span class="mem-count"><b>' + (counts[s] || 0) + '</b> ' + s + '</span>';
      }).join('');
      var badge = $('#sponsBadge'); if (badge) { var n = counts['new'] || 0; badge.textContent = n; badge.hidden = !n; }
      if (!r.data.sponsorships.length) { box.innerHTML = '<p class="muted">No sponsorship inquiries yet.</p>'; return; }
      r.data.sponsorships.forEach(function (s) {
        var row = document.createElement('div'); row.className = 'inbox-row';
        var opts = STATUSES.map(function (v) { return '<option value="' + v + '"' + (v === s.status ? ' selected' : '') + '>' + v + '</option>'; }).join('');
        row.innerHTML =
          '<div><strong>' + escapeHtml(s.full_name) + ' · ' + ngn(s.amount_ngn) + ' <span class="muted">/ ' + escapeHtml(String(s.frequency).replace('_', '-')) + '</span></strong>' +
          '<div class="inbox-meta"><a href="mailto:' + escapeHtml(s.email) + '">' + escapeHtml(s.email) + '</a>' + (s.phone ? ' · ' + escapeHtml(s.phone) : '') + (s.organization ? ' · ' + escapeHtml(s.organization) : '') +
          ' · <b>' + escapeHtml(s.program) + '</b> · <span class="mono">' + escapeHtml(s.ref) + '</span> · ' + escapeHtml(s.created_at) + '</div>' +
          (s.note ? '<p class="inbox-note">' + escapeHtml(s.note) + '</p>' : '') + '</div>' +
          '<div><select data-spons-status="' + s.id + '">' + opts + '</select></div>';
        box.appendChild(row);
      });
    });
    // Ledger tiers.
    api('tier_list').then(function (r) {
      var box = $('#tierList'); box.innerHTML = '';
      if (!r.data.ok) { box.innerHTML = '<p class="muted">Could not load tiers.</p>'; return; }
      r.data.tiers.forEach(function (t) { box.appendChild(tierRow(t)); });
    });
    // Sponsor config JSON.
    api('sts_get&section=sponsor').then(function (r) {
      if (r.data && r.data.ok) $('#sponsCfg').value = JSON.stringify(r.data.section.data, null, 2);
    });
  }

  function tierRow(t) {
    t = t || { id: 0, sort: 0, amount_ngn: 0, label: '', impact_line: '', active: 1 };
    var row = document.createElement('div'); row.className = 'side-card'; row.style.marginBottom = '10px';
    row.innerHTML =
      '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">' +
      '<label class="fld" style="width:90px"><span>Sort</span><input type="number" data-f="sort" value="' + (t.sort | 0) + '"></label>' +
      '<label class="fld" style="width:140px"><span>Amount (₦)</span><input type="number" data-f="amount_ngn" value="' + (t.amount_ngn | 0) + '"></label>' +
      '<label class="fld" style="flex:1;min-width:180px"><span>Label</span><input type="text" data-f="label" value="' + escapeHtml(t.label) + '"></label>' +
      '<label class="fld checkbox" style="align-self:center"><input type="checkbox" data-f="active"' + (t.active == 1 ? ' checked' : '') + '> <span>Active</span></label>' +
      '</div>' +
      '<label class="fld" style="margin-top:8px"><span>Impact line</span><textarea data-f="impact_line" rows="2">' + escapeHtml(t.impact_line) + '</textarea></label>' +
      '<div class="editor-actions" style="margin-top:8px"><button class="btn btn-outline btn-sm danger" data-tier-del="' + (t.id | 0) + '"' + (t.id ? '' : ' hidden') + '>Delete</button><button class="btn btn-primary btn-sm" data-tier-save="' + (t.id | 0) + '">Save</button></div>';
    return row;
  }

  // Inquiry status change.
  $('#sponsList').addEventListener('change', function (e) {
    var sel = e.target.closest('[data-spons-status]'); if (!sel) return;
    post('spons_status', { id: parseInt(sel.getAttribute('data-spons-status'), 10), status: sel.value })
      .then(function (r) { toast(r.data && r.data.ok ? 'Status updated.' : 'Could not update.'); if (r.data && r.data.ok) loadSponsorship(); });
  });
  // Tier save / delete (event delegation on the list).
  $('#tierList').addEventListener('click', function (e) {
    var saveBtn = e.target.closest('[data-tier-save]');
    var delBtn = e.target.closest('[data-tier-del]');
    if (saveBtn) {
      var card = saveBtn.closest('.side-card');
      var g = function (f) { return card.querySelector('[data-f="' + f + '"]'); };
      post('tier_save', {
        id: parseInt(saveBtn.getAttribute('data-tier-save'), 10) || 0,
        sort: parseInt(g('sort').value, 10) || 0,
        amount_ngn: parseInt(g('amount_ngn').value, 10) || 0,
        label: g('label').value, impact_line: g('impact_line').value,
        active: g('active').checked ? 1 : 0,
      }).then(function (r) { toast(r.data && r.data.ok ? 'Tier saved.' : ((r.data && r.data.error) || 'Could not save.')); if (r.data && r.data.ok) loadSponsorship(); });
    } else if (delBtn) {
      if (!confirm('Delete this tier?')) return;
      post('tier_delete', { id: parseInt(delBtn.getAttribute('data-tier-del'), 10) })
        .then(function (r) { toast('Tier deleted.'); loadSponsorship(); });
    }
  });
  $('#tierAdd').addEventListener('click', function () { $('#tierList').appendChild(tierRow(null)); });
  $('#sponsRefresh').addEventListener('click', loadSponsorship);
  $('#sponsCfgSave').addEventListener('click', function () {
    var raw = $('#sponsCfg').value, parsed;
    try { parsed = JSON.parse(raw); } catch (err) { $('#sponsCfgMsg').textContent = 'Not valid JSON: ' + err.message; return; }
    $('#sponsCfgMsg').textContent = '';
    post('sts_save', { section: 'sponsor', data: parsed })
      .then(function (r) { toast(r.data && r.data.ok ? 'Config saved.' : 'Could not save.'); });
  });

  function boot() {
    var saved = 'entries';
    try { saved = localStorage.getItem('av.studio.tab') || 'entries'; } catch (e) {}
    if (!document.querySelector('.tab[data-tab="' + saved + '"]')) saved = 'entries';
    activateTab(saved);
    refreshModBadge();
  }
  api('session').then(function (r) { if (r.data && r.data.ok) { csrf = r.data.csrf; cloudinary = !!r.data.cloudinary; boot(); } else show('login'); }).catch(function () { show('login'); });
})();
