/* ============================================================
   Afrovanguard Studio — admin logic (no framework).
   Auth: signed httpOnly cookie + HMAC CSRF token (in memory).
   Manages Diary entries, Academy programmes, and the Inbox.
   ============================================================ */
(function () {
  'use strict';
  var API = '/admin/api.php';
  var csrf = '';            // CSRF token kept in memory only
  var currentRole = 'superadmin';   // editor | admin | superadmin (from session)
  var ROLE_RANK = { editor: 1, admin: 2, superadmin: 3 };
  var TAB_MIN = { overview: 'editor', entries: 'editor', moderation: 'editor', academy: 'editor', guide: 'editor',
    inbox: 'admin', members: 'admin', people: 'admin', celebrations: 'admin', communities: 'admin',
    mentorship: 'admin', webhooks: 'admin', system: 'admin', activity: 'admin', signin: 'superadmin', admins: 'superadmin', database: 'superadmin', design: 'superadmin',
    // The rules decide promotions and escalations movement-wide, and the prompts
    // steer every AI reply — Super Admin only, matching the API's own gate.
    rules: 'superadmin' };
  function roleAllows(tab) { var need = TAB_MIN[tab] || 'admin'; return (ROLE_RANK[currentRole] || 0) >= (ROLE_RANK[need] || 99); }
  function applyRoleVisibility() {
    document.querySelectorAll('.tab[data-tab]').forEach(function (t) {
      var tab = t.getAttribute('data-tab'); t.hidden = !roleAllows(tab);
    });
    // hide now-empty sidebar groups
    document.querySelectorAll('.nav-group').forEach(function (g) {
      var any = Array.prototype.some.call(g.querySelectorAll('.tab'), function (t) { return !t.hidden; });
      var h = g.querySelector('.nav-group-h'); if (h) h.style.display = any ? '' : 'none';
    });
  }
  var cloudinary = false;
  var coverUrl = '', cCoverUrl = '', audioUrl = '';

  var $ = function (s) { return document.querySelector(s); };
  var views = {
    login: $('#loginView'), overview: $('#overviewView'), entries: $('#entriesView'), editor: $('#editorView'),
    academy: $('#academyView'), courseEditor: $('#courseEditorView'),
    curriculum: $('#curriculumView'), lessonEditor: $('#lessonEditorView'), inbox: $('#inboxView'), moderation: $('#moderationView'),
    people: $('#peopleView'), personEdit: $('#personEditView'),
    celebrations: $('#celebrationsView'), celEdit: $('#celEditView'), communities: $('#communitiesView'), commEdit: $('#commEditView'), webhooks: $('#webhooksView'), whEdit: $('#whEditView'), system: $('#systemView'), signin: $('#signinView'), members: $('#membersView'), guide: $('#guideView'), mentorship: $('#mentorshipView'), activity: $('#activityView'), admins: $('#adminsView'), database: $('#databaseView'), design: $('#designView'), rules: $('#rulesView')
  };
  function show(v) { Object.keys(views).forEach(function (k) { if (views[k]) views[k].hidden = (k !== v); });
    $('#logoutBtn').hidden = (v === 'login'); $('#tabs').hidden = (v === 'login');
    document.body.classList.toggle('studio-authed', v !== 'login');
    var burger = $('#studioBurger'); if (burger) burger.hidden = (v === 'login');
    if (typeof closeSide === 'function') closeSide(); }

  var toastEl = $('#toast'), toastT;
  function toast(m) { toastEl.textContent = m; toastEl.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(function () { toastEl.classList.remove('show'); }, 2600); }
  // Covers the single quote too: several render sites put a value inside a
  // single-quoted CSS url('…'), which a bare ' closes.
  function escapeHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
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
    if (which === 'overview') { show('overview'); loadOverview(); }
    else if (which === 'entries') { show('entries'); loadList(); }
    else if (which === 'academy') { show('academy'); loadCourses(); }
    else if (which === 'people') { show('people'); loadTeam(); }
    else if (which === 'celebrations') { show('celebrations'); loadCelebrations(); }
    else if (which === 'communities') { show('communities'); loadCommunities(); }
    else if (which === 'webhooks') { show('webhooks'); loadWebhooks(); loadAppTokens(); }
    else if (which === 'system') { show('system'); loadSystem(); }
    else if (which === 'moderation') { show('moderation'); loadModeration(); }
    else if (which === 'signin') { show('signin'); loadAuthPolicy(); loadArt(); }
    else if (which === 'members') { show('members'); loadMembers(); }
    else if (which === 'guide') { show('guide'); }
    else if (which === 'mentorship') { show('mentorship'); loadMentorship(); }
    else if (which === 'activity') { show('activity'); loadActivity(); }
    else if (which === 'admins') { show('admins'); loadAdmins(); }
    else if (which === 'database') { show('database'); loadDatabase(); }
    else if (which === 'design') { show('design'); loadDesign(); }
    else if (which === 'rules') { show('rules'); loadRules(); }
    else { show('inbox'); loadInbox(); }
    var on = document.querySelector('.tab.active');
    if (on && on.scrollIntoView) { try { on.scrollIntoView({ inline: 'center', block: 'nearest' }); } catch (e) {} }
  }
  document.querySelectorAll('.tab').forEach(function (tab) {
    tab.addEventListener('click', function () { activateTab(tab.getAttribute('data-tab')); });
  });

  /* ---- Sidebar drawer (mobile) ---- */
  function closeSide() { var s = $('#studioSide'), sc = $('#studioScrim'), b = $('#studioBurger'); if (s) s.classList.remove('open'); if (sc) sc.hidden = true; if (b) b.setAttribute('aria-expanded', 'false'); }
  function toggleSide() { var s = $('#studioSide'), sc = $('#studioScrim'), b = $('#studioBurger'); if (!s) return; var open = !s.classList.contains('open'); s.classList.toggle('open', open); if (sc) sc.hidden = !open; if (b) b.setAttribute('aria-expanded', String(open)); }
  (function () { var b = $('#studioBurger'), sc = $('#studioScrim'); if (b) b.addEventListener('click', toggleSide); if (sc) sc.addEventListener('click', closeSide); document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSide(); }); })();

  /* ---- Auth ---- */
  $('#loginForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = $('#loginBtn'), msg = $('#loginMsg');
    msg.textContent = ''; msg.classList.remove('is-error');
    var label = btn.textContent; btn.disabled = true; btn.classList.add('is-loading'); btn.textContent = 'Signing in…';
    var reset = function () { btn.disabled = false; btn.classList.remove('is-loading'); btn.textContent = label; };
    post('login', { token: $('#tokenInput').value.trim() }).then(function (r) {
      if (r.data && r.data.ok) { csrf = r.data.csrf; cloudinary = !!r.data.cloudinary; currentRole = r.data.role || 'superadmin'; boot(); }
      else { msg.textContent = (r.data && r.data.error) || 'That token was not accepted.'; msg.classList.add('is-error'); reset(); var i = $('#tokenInput'); i.focus(); i.select(); }
    }).catch(function () { msg.textContent = 'Network error — please try again.'; msg.classList.add('is-error'); reset(); });
  });
  // show / hide the token
  (function () {
    var t = $('#tokenToggle'), inp = $('#tokenInput');
    if (!t || !inp) return;
    t.addEventListener('click', function () {
      var reveal = inp.type === 'password'; inp.type = reveal ? 'text' : 'password';
      t.setAttribute('aria-pressed', String(reveal)); t.setAttribute('aria-label', reveal ? 'Hide token' : 'Show token');
      t.classList.toggle('is-on', reveal); inp.focus();
    });
  })();
  $('#logoutBtn').addEventListener('click', function () { post('logout', {}).finally(function () { csrf = ''; show('login'); }); });

  // Filtering is client-side: the admin list is already loaded in full, so a
  // keystroke should not cost a round trip.
  $('#entryFind') && $('#entryFind').addEventListener('input', renderList);
  $('#entryFind') && $('#entryFind').addEventListener('search', renderList);

  /* ---- Diary list ---- */
  var entries = [];

  // A reference code is typed as AVD-2608-0003, but it will also be pasted with
  // different dashes, or without the prefix, or in lower case. Compare on digits
  // and letters alone so all of those find the entry.
  function refKey(s) { return String(s == null ? '' : s).toUpperCase().replace(/[^A-Z0-9]/g, ''); }

  function renderList() {
    var box = $('#entryList'); if (!box) return;
    var qEl = $('#entryFind');
    var q = ((qEl && qEl.value) || '').trim();
    var note = $('#entryFindNote');
    var rows = entries;
    if (q) {
      var qlc = q.toLowerCase(), qref = refKey(q);
      rows = entries.filter(function (a) {
        // Name, code, slug and category — the four things somebody actually has
        // to hand when they are looking for an entry.
        return (a.title || '').toLowerCase().indexOf(qlc) !== -1
          || (a.slug || '').toLowerCase().indexOf(qlc) !== -1
          || (a.category || '').toLowerCase().indexOf(qlc) !== -1
          || (qref.length >= 4 && refKey(a.ref_code).indexOf(qref) !== -1);
      });
    }
    if (note) {
      note.hidden = !q;
      note.textContent = q ? (rows.length + ' of ' + entries.length + ' entries match “' + q + '”') : '';
    }
    box.innerHTML = '';
    if (!rows.length) {
      box.innerHTML = '<p class="muted">' + (q ? 'No entry matches that name or code.' : 'No entries yet.') + '</p>';
      return;
    }
    rows.forEach(function (a) {
      var row = document.createElement('div'); row.className = 'entry-row';
      row.innerHTML = '<div class="entry-thumb ' + escapeHtml(a.gradient) + '"' + (a.cover_url ? ' style="background-image:url(\'' + escapeHtml(a.cover_url) + '\')"' : '') + '></div>' +
        '<div class="entry-info"><div class="entry-title">' + escapeHtml(a.title) + '</div><div class="entry-meta"><span class="badge ' + a.status + '">' + a.status + '</span> ' +
        (a.ref_code ? '<span class="entry-ref" title="Reference code — quote this to identify the entry">' + escapeHtml(a.ref_code) + '</span> · ' : '') +
        escapeHtml(a.category) + ' · ' + escapeHtml(a.published) + (a.featured == 1 ? ' · ★' : '') + '</div></div>' +
        '<div class="entry-ops"><button class="btn btn-outline btn-sm" data-edit="' + a.slug + '">Edit</button><button class="btn btn-outline btn-sm danger" data-del="' + a.slug + '">Delete</button></div>';
      box.appendChild(row);
    });
  }

  function loadList() {
    return api('list').then(function (r) {
      var box = $('#entryList');
      if (!r.data.ok) { box.innerHTML = '<p class="muted">Could not load entries.</p>'; return; }
      $('#cloudinaryNote').textContent = cloudinary ? 'Media uploads go to Cloudinary.' : 'Cloudinary not configured — uploads stored locally under /uploads.';
      entries = r.data.articles || [];
      renderList();
    });
  }
  $('#entryList').addEventListener('click', function (e) {
    var ed = e.target.closest('[data-edit]'), del = e.target.closest('[data-del]');
    if (ed) openEditor(ed.getAttribute('data-edit'));
    if (del && confirm('Delete “' + del.getAttribute('data-del') + '”?')) post('delete', { slug: del.getAttribute('data-del') }).then(function (r) { toast(r.data.ok ? 'Deleted' : 'Failed'); loadList(); });
  });
  $('#newBtn').addEventListener('click', function () { openEditor(null); });
  $('#backBtn').addEventListener('click', function () { show('entries'); loadList(); });

  /* ── WordPress import modal (browser-based migration — no SSH needed) ── */
  (function () {
    var modal = $('#wpModal');
    if (!modal) return;
    function openModal() { $('#wpResult').hidden = true; $('#wpResult').innerHTML = ''; $('#wpFile').value = ''; modal.hidden = false; }
    function closeModal() { modal.hidden = true; }
    $('#wpImportBtn').addEventListener('click', openModal);
    $('#wpClose').addEventListener('click', closeModal);
    modal.addEventListener('mousedown', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeModal(); });

    function run(dryRun) {
      var res = $('#wpResult');
      var file = $('#wpFile').files[0];
      if (!file) { res.hidden = false; res.className = 'wp-result err'; res.textContent = 'Choose your WordPress export .xml first.'; return; }
      var fd = new FormData();
      fd.append('wxr', file);
      fd.append('dry_run', dryRun ? '1' : '0');
      fd.append('status', $('#wpStatus').value);
      fd.append('include_pages', $('#wpPages').checked ? '1' : '0');
      var dry = $('#wpDryRun'), go = $('#wpRun');
      dry.disabled = go.disabled = true;
      res.hidden = false; res.className = 'wp-result'; res.textContent = 'Working…';
      api('diary_import_wp', { method: 'POST', body: fd }).then(function (r) {
        dry.disabled = go.disabled = false;
        var d = r.data || {};
        if (!d.ok) { res.className = 'wp-result err'; res.textContent = d.error || 'Import failed.'; return; }
        var posts = d.posts || [];
        var lead = (d.dry_run ? 'Preview — would import ' : 'Imported ') + d.imported + ' new, ' + d.updated + ' updated' + (d.skipped ? ' · ' + d.skipped + ' skipped' : '');
        var html = '<div class="wp-sum">' + lead + '</div><ul>';
        posts.slice(0, 200).forEach(function (p) {
          html += '<li>' + (p.action === 'new' ? 'NEW' : 'UPD') + ' · <b>' + escapeHtml(p.title) + '</b> <span>(' + p.status + ' · /diary/' + escapeHtml(p.slug) + ')</span></li>';
        });
        if (posts.length > 200) html += '<li>…and ' + (posts.length - 200) + ' more</li>';
        html += '</ul>';
        if ((d.categories || []).length) html += '<div class="wp-sum" style="margin-top:8px">Categories: ' + d.categories.map(escapeHtml).join(', ') + '</div>';
        res.innerHTML = html;
        if (!d.dry_run) { toast('Imported ' + (d.imported + d.updated) + ' post(s)'); loadList(); }
      }).catch(function () { dry.disabled = go.disabled = false; res.className = 'wp-result err'; res.textContent = 'Network error.'; });
    }
    $('#wpDryRun').addEventListener('click', function () { run(true); });
    $('#wpRun').addEventListener('click', function () { run(false); });
  })();

  /* ---- TinyMCE ---- */
  function getBody(id) { return (window.tinymce && tinymce.get(id)) ? tinymce.get(id).getContent() : ($('#' + id) ? $('#' + id).value : ''); }
  function initTiny(id, initial) {
    if (!window.tinymce) { var ta = $('#' + id); if (ta) { ta.value = initial || ''; ta.style.minHeight = '420px'; } return; }
    if (tinymce.get(id)) tinymce.get(id).remove();
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    tinymce.init({
      selector: '#' + id, height: 520, menubar: false, branding: false, promotion: false, license_key: 'gpl',
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
    Promise.all([api('articles'), api('categories'), api('diary_series')]).then(function (res) {
      $('#catList').innerHTML = (res[1].data.categories || []).map(function (c) { return '<option value="' + escapeHtml(c.name) + '">'; }).join('');
      if ($('#seriesList')) $('#seriesList').innerHTML = ((res[2] && res[2].data && res[2].data.series) || []).map(function (s) { return '<option value="' + escapeHtml(s.title) + '">'; }).join('');
      buildRelated(res[0].data.articles || [], slug, []);
      if (slug) api('get&slug=' + encodeURIComponent(slug)).then(function (r) { if (r.data.ok) { fillForm(r.data.article); buildRelated(res[0].data.articles || [], slug, r.data.article.related || []); } });
      else initTiny('f_body', '<p></p>');
    });
    show('editor');
  }
  function resetForm() {
    ['f_title', 'f_dek', 'f_slug', 'f_authors', 'f_read', 'f_series', 'f_series_part'].forEach(function (id) { var el = $('#' + id); if (el) el.value = ''; });
    $('#f_status').value = 'draft'; $('#f_category').value = ''; $('#f_gradient').value = 'g-gold';
    $('#f_format').value = 'standard';
    $('#f_featured').checked = false; $('#f_date').value = new Date().toISOString().slice(0, 10);
    setCover(''); setAudio(''); $('#previewLink').hidden = true;
    setRefCode('');
  }
  function fillForm(a) {
    $('#f_title').value = a.title || ''; $('#f_dek').value = a.dek || ''; $('#f_slug').value = a.slug || '';
    $('#f_authors').value = stripTags(a.authors_html || ''); $('#f_read').value = a.read_minutes || '';
    if ($('#f_series')) $('#f_series').value = a.series_title || '';
    if ($('#f_series_part')) $('#f_series_part').value = a.series_part || '';
    $('#f_status').value = a.status || 'draft'; $('#f_category').value = a.category || ''; $('#f_gradient').value = a.gradient || 'g-gold';
    $('#f_format').value = a.format || 'standard';
    $('#f_featured').checked = a.featured == 1; $('#f_date').value = (a.published_at || '').slice(0, 10);
    setCover(a.cover_url || ''); setAudio(a.audio_url || ''); initTiny('f_body', a.body_html || '<p></p>');
    setRefCode(a.ref_code || '');
    var pl = $('#previewLink'); pl.hidden = false; pl.href = '/diary/' + a.slug + '/';
  }

  // The reference code of the entry on screen. Sent back with the save so the
  // server updates THIS entry — which is what lets the slug be corrected without
  // leaving the old entry behind as a duplicate.
  var editingRef = '';
  function setRefCode(code) {
    editingRef = code || '';
    var box = $('#f_refWrap'), out = $('#f_ref');
    if (!box || !out) return;
    box.hidden = !editingRef;
    out.textContent = editingRef;
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
  function setAudio(url) {
    audioUrl = (url || '').trim();
    var inp = $('#f_audio'); if (inp && inp.value !== audioUrl) inp.value = audioUrl;
    var p = $('#audioPreview');
    if (audioUrl) { p.innerHTML = '<audio controls preload="none" src="' + escapeHtml(audioUrl) + '"></audio>'; p.classList.add('has'); $('#audioClear').hidden = false; }
    else { p.innerHTML = '<span>No audio yet</span>'; p.classList.remove('has'); $('#audioClear').hidden = true; }
  }
  $('#audioBtn').addEventListener('click', function () { $('#audioFile').click(); });
  $('#audioClear').addEventListener('click', function () { setAudio(''); });
  $('#f_audio').addEventListener('input', function () { setAudio(this.value); });
  $('#audioFile').addEventListener('change', function () {
    if (!this.files[0]) return; toast('Uploading audio…');
    uploadFile(this.files[0]).then(function (r) { r.data && r.data.ok ? (setAudio(r.data.url), toast('Audio uploaded')) : toast((r.data && r.data.error) || 'Upload failed'); });
    this.value = '';
  });
  function collect(status) {
    return { ref_code: editingRef, slug: $('#f_slug').value.trim(), title: $('#f_title').value.trim(), dek: $('#f_dek').value.trim(),
      category: $('#f_category').value.trim() || 'Dispatch', authors_html: $('#f_authors').value.trim() || 'The Afrovanguard Team',
      series: ($('#f_series') ? $('#f_series').value.trim() : ''), series_part: ($('#f_series_part') ? $('#f_series_part').value : ''),
      published_at: $('#f_date').value, read_minutes: $('#f_read').value, gradient: $('#f_gradient').value,
      cover_url: coverUrl, audio_url: ($('#f_audio').value || '').trim(), body_html: getBody('f_body'), featured: $('#f_featured').checked, status: status, format: $('#f_format').value, related: selectedRelated() };
  }
  function saveDiary(status) {
    if (!$('#f_title').value.trim()) { toast('A title is required'); return; }
    post('save', collect(status)).then(function (r) {
      if (!r.data.ok) { toast(r.data.error || 'Save failed'); return; }
      $('#f_slug').value = r.data.slug; var pl = $('#previewLink'); pl.hidden = false; pl.href = r.data.url; $('#f_status').value = status;
      setRefCode(r.data.ref_code || editingRef);
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
        row.innerHTML = '<div class="entry-thumb ' + escapeHtml(c.gradient) + '"' + (c.cover_url ? ' style="background-image:url(\'' + escapeHtml(c.cover_url) + '\')"' : '') + '></div>' +
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
    }).then(function (r) {
      if (!r.data.ok) { toast(r.data.error || 'Save failed'); return; }
      curLessonId = r.data.id; toast('Lesson saved ✓');
      loadCurriculum();      // a new lesson should appear without going back first
    });
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
  // The slug the course editor was opened with. The server resolves edit-vs-create
  // from this, NOT from the slug field — otherwise correcting the slug of an
  // existing course would look like a brand new one. Empty means "new course".
  var editingCourse = '';

  function openCourse(slug) {
    editingCourse = slug || '';
    ['c_title', 'c_summary', 'c_slug', 'c_category', 'c_level', 'c_duration', 'c_price', 'c_location', 'c_cta', 'c_outcomes'].forEach(function (id) { $('#' + id).value = ''; });
    $('#c_status').value = 'draft'; $('#c_format').value = 'In-person'; $('#c_gradient').value = 'g-gold'; $('#c_featured').checked = false; $('#c_sort').value = '0';
    $('#c_access').value = 'open'; $('#c_price_ngn').value = '0'; if ($('#c_pass_code')) $('#c_pass_code').value = '';
    renderGrants([]); syncAccess();
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
    if ($('#c_pass_code')) $('#c_pass_code').value = c.pass_code || '';
    $('#c_instructor').value = c.instructor_email || ''; syncAccess();
    setCCover(c.cover_url || ''); initTiny('c_body', c.body_html || '<p></p>');
    var pl = $('#acPreviewLink'); pl.hidden = false; pl.href = '/academy/' + c.slug + '/';
    loadGrants();
  }
  function syncAccess() {
    var access = $('#c_access').value;
    var w = $('#c_price_ngn_wrap'); if (w) w.hidden = access !== 'paid';
    var pw = $('#c_pass_wrap'); if (pw) pw.hidden = access !== 'restricted';
    var gw = $('#c_access_grants_wrap'); if (gw) gw.hidden = access !== 'restricted';
    if (access === 'restricted') loadGrants();
  }
  if ($('#c_access')) { $('#c_access').addEventListener('change', syncAccess); syncAccess(); }
  /* ---- Restricted-course access grants ---- */
  function renderGrants(grants) {
    var ul = $('#c_grant_list'); if (!ul) return;
    if (!grants || !grants.length) { ul.innerHTML = '<li class="muted" style="font-size:12px">No members added yet.</li>'; return; }
    ul.innerHTML = grants.map(function (g) {
      return '<li style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:5px 8px;background:var(--surface-2,#f6f6f4);border-radius:8px">' +
        '<span style="min-width:0"><strong style="font-size:13px">' + escapeHtml(g.name || g.email) + '</strong>' +
        '<span class="muted" style="display:block;font-size:11px;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(g.email) + '</span></span>' +
        '<button type="button" class="btn btn-outline btn-sm" data-revoke="' + g.id + '">Remove</button></li>';
    }).join('');
  }
  function loadGrants() {
    if ($('#c_access').value !== 'restricted') return;
    var slug = $('#c_slug').value.trim();
    if (!slug) { renderGrants([]); return; }
    api('ac_grants&slug=' + encodeURIComponent(slug)).then(function (r) { if (r.data && r.data.ok) renderGrants(r.data.grants); });
  }
  if ($('#c_grant_btn')) {
    $('#c_grant_btn').addEventListener('click', function () {
      var slug = $('#c_slug').value.trim();
      if (!slug) { toast('Save the course first, then add members.'); return; }
      var email = $('#c_grant_email').value.trim();
      if (!email) { toast('Enter a member email.'); return; }
      post('ac_grant', { slug: slug, email: email }).then(function (r) {
        if (!r.data.ok) { toast(r.data.error || 'Could not grant access.'); return; }
        $('#c_grant_email').value = ''; renderGrants(r.data.grants); toast('Access granted ✓');
      }).catch(function () { toast('Network error'); });
    });
  }
  if ($('#c_grant_list')) {
    $('#c_grant_list').addEventListener('click', function (e) {
      var b = e.target.closest('[data-revoke]'); if (!b) return;
      var slug = $('#c_slug').value.trim();
      post('ac_revoke', { slug: slug, user_id: parseInt(b.getAttribute('data-revoke'), 10) }).then(function (r) {
        if (r.data.ok) renderGrants(r.data.grants);
      });
    });
  }
  function collectCourse(status) {
    return { editing: editingCourse, slug: $('#c_slug').value.trim(), title: $('#c_title').value.trim(), summary: $('#c_summary').value.trim(),
      body_html: getBody('c_body'), outcomes: $('#c_outcomes').value.trim(), category: $('#c_category').value.trim() || 'Programme',
      level: $('#c_level').value.trim() || 'All levels', format: $('#c_format').value, duration: $('#c_duration').value.trim(),
      price: $('#c_price').value.trim() || 'Free', location: $('#c_location').value.trim() || 'Alimosho, Lagos',
      cta_url: $('#c_cta').value.trim(), gradient: $('#c_gradient').value, cover_url: cCoverUrl,
      access_type: $('#c_access').value, price_ngn: parseInt($('#c_price_ngn').value, 10) || 0,
      pass_code: $('#c_pass_code') ? $('#c_pass_code').value.trim() : '', instructor_email: $('#c_instructor').value.trim(),
      featured: $('#c_featured').checked, status: status, sort: $('#c_sort').value };
  }
  function saveCourse(status) {
    if (!$('#c_title').value.trim()) { toast('A title is required'); return; }
    post('ac_save', collectCourse(status)).then(function (r) {
      if (!r.data.ok) { toast(r.data.error || 'Save failed'); return; }
      // The course now exists under this slug, so the next save is an edit of it.
      editingCourse = r.data.slug;
      $('#c_slug').value = r.data.slug; var pl = $('#acPreviewLink'); pl.hidden = false; pl.href = r.data.url; $('#c_status').value = status;
      toast(r.data.notice || (status === 'published' ? 'Published ✓' : 'Draft saved ✓'));
      loadCourses();          // so the catalogue reflects it without a page reload
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
      if ($('#p_grp')) $('#p_grp').value = m.grp || '';
      $('#p_birthday').value = m.birthday || '';
      if ($('#p_notice_email')) $('#p_notice_email').value = m.email || '';
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
      grp: ($('#p_grp') ? $('#p_grp').value.trim() : ''),
      active: $('#p_active').checked, position: +$('#p_position').value || 0,
      tagline: $('#p_tagline').value.trim(), bio: $('#p_bio').value.trim(), location: $('#p_location').value.trim(),
      photo: pPhoto, socials: socials, birthday: $('#p_birthday').value.trim(),
      email: ($('#p_notice_email') ? $('#p_notice_email').value.trim() : ''),
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
    if (typeof renderCelPreview === 'function') renderCelPreview();
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
  function renderCelPreview() {
    var box = $('#celPreview'); if (!box) return;
    var name = $('#c_name').value.trim() || 'Celebrating today';
    var msg = $('#c_message').value.trim();
    var emoji = $('#c_emoji').value.trim() || '🎉';
    var theme = /^#[0-9a-f]{6}$/i.test($('#c_theme').value) ? $('#c_theme').value : '#f3b416';
    var off = !$('#c_enabled').checked;
    var media = cDoodle
      ? '<img class="cel-pv-doodle" src="' + escapeHtml(cDoodle) + '" alt="" />'
      : '<span class="cel-pv-emoji">' + escapeHtml(emoji) + '</span>';
    box.innerHTML = '<div class="cel-pv-bar" style="--cc:' + escapeHtml(theme) + '">' + media
      + '<span class="cel-pv-txt"><strong>' + escapeHtml(name) + '</strong>'
      + (msg ? '<span>' + escapeHtml(msg) + '</span>' : '') + '</span></div>'
      + (off ? '<p class="cel-pv-off">Disabled — won’t show to members.</p>' : '');
  }
  function openCel(id) {
    editingCel = id; $('#celForm').reset(); setCDoodle(''); $('#c_theme').value = '#f3b416';
    $('#celDeleteBtn').hidden = !id; show('celEdit');
    if (!id) { renderCelPreview(); return; }
    api('cel_list').then(function (r) {
      var c = (r.data.celebrations || []).filter(function (x) { return +x.id === id; })[0]; if (!c) return;
      $('#c_name').value = c.name || ''; $('#c_message').value = c.message || ''; $('#c_key').value = c.key || '';
      $('#c_md').value = c.md || ''; $('#c_scope').value = c.scope || 'internal'; $('#c_emoji').value = c.emoji || '';
      $('#c_theme').value = /^#[0-9a-f]{6}$/i.test(c.theme) ? c.theme : '#f3b416';
      $('#c_enabled').checked = parseInt(c.enabled, 10) !== 0; setCDoodle(c.doodle_url || '');
      renderCelPreview();
    });
  }
  // Live preview follows every edit (name, message, emoji, theme, enabled).
  $('#celForm').addEventListener('input', renderCelPreview);
  $('#celForm').addEventListener('change', renderCelPreview);
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
  if ($('#whRunBtn')) $('#whRunBtn').addEventListener('click', function () {
    var b = this; b.disabled = true; var l = b.textContent; b.textContent = 'Running…';
    post('wh_run', {}).then(function (r) {
      var x = (r.data && r.data.result) || {};
      toast('Queue run — delivered ' + (x.ok || 0) + ' of ' + (x.processed || 0) + ' due.');
      loadWebhooks();
    }).catch(function () { toast('Network error.'); }).finally(function () { b.disabled = false; b.textContent = l; });
  });

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

  /* ---- Overview (landing dashboard) ---- */
  function ovCard(opts) {
    return '<button class="ov-card" data-go="' + opts.go + '">' +
      '<span class="ov-card-ico">' + opts.ico + '</span>' +
      '<span class="ov-card-num">' + opts.num + '</span>' +
      '<span class="ov-card-label">' + escapeHtml(opts.label) + '</span>' +
      (opts.sub ? '<span class="ov-card-sub">' + escapeHtml(opts.sub) + '</span>' : '') +
      '</button>';
  }
  // Report §21/§31 — the leadership brief. Critical first, because the whole
  // point is that a leader reads the top and can stop. Every figure here was
  // counted by lib/Brief.php; the model only phrases them, and the footer says
  // which so nobody has to wonder whether a number was inferred.
  var BRIEF_SECTIONS = [
    ['critical',    'Critical',    'Needs a decision this week'],
    ['attention',   'Attention',   'Worth your eye, not yet urgent'],
    ['opportunity', 'Opportunity', 'Someone has earned a review'],
    ['growth',      'Growth',      'Measured against the last brief']
  ];

  function briefHtml(b) {
    if (!b || !b.narrative) {
      return '<div class="brief-empty"><p><b>No brief yet for this period.</b></p>'
        + '<p class="muted">One is written automatically each week. Use <b>Generate now</b> to write it immediately.</p></div>';
    }
    var n = b.narrative || {}, total = 0;
    BRIEF_SECTIONS.forEach(function (s) { total += ((n[s[0]] || []).length); });

    var html = '<p class="brief-headline">' + escapeHtml(n.headline || '') + '</p>';
    if (!total) {
      html += '<div class="brief-empty"><p><b>Nothing needs your attention this period.</b></p>'
            + '<p class="muted">No relationships at risk, no overdue commitments, nobody awaiting a review.</p></div>';
    }
    BRIEF_SECTIONS.forEach(function (sec) {
      var key = sec[0], items = n[key] || [];
      if (!items.length) return;
      html += '<div class="brief-sec brief-' + key + '">'
            + '<h4><span class="brief-count">' + items.length + '</span> ' + escapeHtml(sec[1])
            + ' <span class="brief-sub">' + escapeHtml(sec[2]) + '</span></h4><ul>'
            + items.map(function (i) { return '<li>' + escapeHtml(i) + '</li>'; }).join('')
            + '</ul></div>';
    });

    var src = b.source === 'computed'
      ? 'Figures counted from the records; wording generated without a model.'
      : 'Figures counted from the records; wording written by ' + escapeHtml(b.source) + '.';
    html += '<p class="brief-foot">' + escapeHtml((b.from || '').slice(0, 10)) + ' to '
          + escapeHtml((b.to || '').slice(0, 10)) + ' · ' + src + '</p>';
    return html;
  }

  function loadBrief() {
    var box = $('#ovBriefBody'), sec = $('#ovBrief');
    if (!box || !sec) return;
    sec.hidden = false;
    box.innerHTML = '<p class="muted">Loading the brief…</p>';
    var period = ($('#ovBriefPeriod') || {}).value || 'week';
    api('brief_latest&period=' + encodeURIComponent(period)).then(function (r) {
      var d = r.data || {};
      if (!d.ok) { box.innerHTML = '<p class="muted">Could not load the brief.</p>'; return; }
      box.innerHTML = briefHtml(d.brief);
    }).catch(function () { box.innerHTML = '<p class="muted">Could not load the brief.</p>'; });
  }

  $('#ovBriefPeriod') && $('#ovBriefPeriod').addEventListener('change', loadBrief);
  $('#ovBriefRun') && $('#ovBriefRun').addEventListener('click', function () {
    var btn = this, box = $('#ovBriefBody');
    var period = ($('#ovBriefPeriod') || {}).value || 'week';
    btn.disabled = true;
    var was = btn.textContent; btn.textContent = 'Working…';
    if (box) box.innerHTML = '<p class="muted">Counting every pairing — this can take a minute.</p>';
    post('brief_run', { period: period }).then(function (r) {
      btn.disabled = false; btn.textContent = was;
      var d = r.data || {};
      if (!d.ok) { if (box) box.innerHTML = '<p class="muted">' + escapeHtml(d.error || 'That did not work.') + '</p>'; return; }
      if (box) box.innerHTML = briefHtml(d.brief);
    }).catch(function () {
      btn.disabled = false; btn.textContent = was;
      if (box) box.innerHTML = '<p class="muted">The request failed.</p>';
    });
  });

  function loadOverview() {
    loadBrief();
    var grid = $('#ovGrid'), health = $('#ovHealth'), alert = $('#ovMailAlert');
    if (grid) grid.innerHTML = '<p class="muted" style="grid-column:1/-1">Loading…</p>';
    return api('dashboard').then(function (r) {
      var d = (r.data && r.data.ok) ? r.data : null;
      if (!d) { if (grid) grid.innerHTML = '<p class="muted" style="grid-column:1/-1">Could not load the overview.</p>'; return; }
      var s = d.stats || {};
      var I = {
        diary: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h10a2 2 0 0 1 2 2v14H6a2 2 0 0 1-2-2V4z"/><path d="M16 6h4v12a2 2 0 0 1-2 2"/></svg>',
        mod:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.2-2.9 7.3-7 8-4.1-.7-7-3.8-7-8V6l7-3z"/><path d="M9 12l2 2 4-4"/></svg>',
        inbox: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h5l2 3h4l2-3h5"/><path d="M5 5h14a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>',
        member:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.6 2.7-5.5 6-5.5"/><path d="M15 12l2 2 4-4"/></svg>',
        mail:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>',
        acad:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4L2 9l10 5 10-5-10-5z"/><path d="M6 11v5c0 1.3 2.7 2.5 6 2.5s6-1.2 6-2.5v-5"/></svg>'
      };
      grid.innerHTML = [
        ovCard({ go: 'entries', ico: I.diary, num: s.diary_published || 0, label: 'Diary entries', sub: (s.diary_drafts || 0) + ' draft' + ((s.diary_drafts === 1) ? '' : 's') }),
        ovCard({ go: 'moderation', ico: I.mod, num: s.moderation || 0, label: 'Awaiting review', sub: (s.moderation ? 'Needs attention' : 'All clear') }),
        ovCard({ go: 'members', ico: I.member, num: s.members || 0, label: 'Active members' }),
        ovCard({ go: 'inbox', ico: I.inbox, num: s.inbox || 0, label: 'Inbox messages', sub: (s.subscribers || 0) + ' subscriber' + ((s.subscribers === 1) ? '' : 's') }),
        ovCard({ go: 'academy', ico: I.acad, num: s.courses_published || 0, label: 'Published courses', sub: (s.enrolments || 0) + ' enrolment' + ((s.enrolments === 1) ? '' : 's') }),
        ovCard({ go: 'system', ico: I.mail, num: (d.email && d.email.configured) ? '✓' : '—', label: 'Email delivery', sub: (d.email && d.email.label) || '' })
      ].join('');

      // Email/delivery banner — only when something needs the owner's attention.
      if (alert) {
        if (d.email && !d.email.configured) {
          alert.hidden = false;
          alert.className = 'ov-alert ov-alert-warn';
          alert.innerHTML = '<strong>Email isn’t configured yet.</strong> Members won’t get sign-in codes, receipts or notifications until SMTP is set. ' +
            'Set <code>SMTP_HOST</code>, <code>SMTP_USERNAME</code> and <code>AV_SMTP_PASSWORD</code> (a Gmail App Password), then ' +
            '<button class="ov-link" data-go="system">send a test from System →</button>';
        } else { alert.hidden = true; }
      }

      if (health) {
        var h = d.health || {}, parts = [];
        if (h.ok) parts.push('<span class="ov-dot ok"></span>' + h.ok + ' ready');
        if (h.warn) parts.push('<span class="ov-dot warn"></span>' + h.warn + ' optional');
        if (h.off) parts.push('<span class="ov-dot off"></span>' + h.off + ' needs attention');
        health.innerHTML = parts.length ? parts.join('<span class="ov-sep">·</span>') : '<span class="muted">No checks reported.</span>';
      }
    }).catch(function () { if (grid) grid.innerHTML = '<p class="muted" style="grid-column:1/-1">Could not load the overview.</p>'; });
  }
  (function () {
    var ov = $('#overviewView'); if (!ov) return;
    ov.addEventListener('click', function (e) {
      var go = e.target.closest('[data-go]'); if (!go) return;
      var which = go.getAttribute('data-go'), then = go.getAttribute('data-then');
      activateTab(which);
      if (then === 'new') { var nb = $('#newBtn'); if (nb) nb.click(); }
    });
    var rb = $('#ovRefreshBtn'); if (rb) rb.addEventListener('click', loadOverview);
  })();

  /* ---- Guide: how-to + AI assistant ---- */
  (function () {
    var form = $('#guideForm'); if (!form) return;
    var input = $('#guideInput'), chat = $('#guideChat'), send = $('#guideSend');
    var history = [];
    function bubble(role, text, pending) {
      var el = document.createElement('div');
      el.className = 'gc-msg gc-' + role + (pending ? ' gc-pending' : '');
      el.textContent = text;
      chat.appendChild(el); chat.scrollTop = chat.scrollHeight;
      return el;
    }
    function ask(q) {
      q = (q || '').trim(); if (!q) return;
      bubble('user', q);
      input.value = ''; send.disabled = true;
      var pending = bubble('bot', 'Thinking…', true);
      post('guide_ask', { q: q, history: history.slice(-8) }).then(function (r) {
        var d = r.data || {};
        pending.classList.remove('gc-pending');
        pending.textContent = d.answer || 'Sorry — no answer.';
        history.push({ role: 'member', text: q }); history.push({ role: 'bot', text: d.answer || '' });
      }).catch(function () { pending.classList.remove('gc-pending'); pending.textContent = 'Network error — please try again.'; })
        .finally(function () { send.disabled = false; input.focus(); });
    }
    form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
    var sug = $('#guideSuggest');
    if (sug) sug.addEventListener('click', function (e) { var c = e.target.closest('.guide-chip'); if (c) ask(c.textContent); });
  })();

  /* ---- Mentorship (mentor–mentee management) ---- */
  var mtSeg = 'org', mtTab = 'pairings', mtPick = { mentor: null, mentee: null };
  function statCard(n, label) { return '<div class="ov-card" style="cursor:default"><span class="ov-card-num">' + n + '</span><span class="ov-card-label">' + escapeHtml(label) + '</span></div>'; }
  function loadMentorship() {
    var ex = $('#mtExport'); if (ex) ex.setAttribute('href', API + '?action=mentorship_export&segment=' + mtSeg);
    api('mentorship_stats').then(function (r) {
      var s = (r.data && r.data.stats) || {}, g = function (o) { return (o && o[mtSeg]) || 0; };
      var grid = $('#mtStats'); if (grid) grid.innerHTML = [
        statCard(g(s.mentors_pending), 'Awaiting approval'),
        statCard(g(s.mentors_approved), 'Approved mentors'),
        statCard(g(s.pairs_active), 'Active pairings'),
        statCard(g(s.pairs_pending), 'Pending requests'),
        statCard(s.inactive || 0, 'Need attention')
      ].join('');
    });
    mtLoadCohortOptions();
    mtRenderTab();
    mtBadges();
  }
  function mtBadges() {
    fetch(API + '?action=mentorship_mentors&approval=pending&segment=' + mtSeg, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var n = (d.mentors || []).length, b = $('#mtApprBadge'); if (b) { b.textContent = n; b.hidden = !n; }
    }).catch(function () {});
    fetch(API + '?action=mentorship_inactive', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var n = (d.pairs || []).length, b = $('#mtInactBadge'); if (b) { b.textContent = n; b.hidden = !n; }
    }).catch(function () {});
    // The badge counts only Red — Amber is worth reading, Red is worth acting on,
    // and a badge that counts both trains people to ignore it.
    fetch(API + '?action=mentorship_health', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var n = ((d.counts || {}).red) || 0, b = $('#mtHealthBadge'); if (b) { b.textContent = n; b.hidden = !n; }
    }).catch(function () {});
    // Counts only those who MEET the criteria — someone merely approaching is
    // worth reading, not worth a badge demanding attention.
    fetch(API + '?action=promotion_queue', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var n = (d.queue || []).filter(function (p) { return p.ready; }).length, b = $('#mtPromoBadge');
      if (b) { b.textContent = n; b.hidden = !n; }
    }).catch(function () {});
  }
  function mtRenderTab() {
    ['pairings', 'approvals', 'cohorts', 'inactive', 'health', 'promotions'].forEach(function (t) { var el = $('#mt' + t.charAt(0).toUpperCase() + t.slice(1)); if (el) el.hidden = (t !== mtTab); });
    document.querySelectorAll('.subtab[data-mt]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-mt') === mtTab); });
    if (mtTab === 'pairings') mtPairings();
    else if (mtTab === 'approvals') mtApprovals();
    else if (mtTab === 'cohorts') mtCohorts();
    else if (mtTab === 'health') mtHealth();
    else if (mtTab === 'promotions') mtPromotions();
    else mtInactive();
  }
  function statusPill(s) { return '<span class="badge ' + (s === 'active' ? 'published' : (s === 'pending' ? 'draft' : 'draft')) + '">' + escapeHtml(s) + '</span>'; }
  function mtPairings() {
    var box = $('#mtPairings'); box.innerHTML = '<p class="muted">Loading…</p>';
    fetch(API + '?action=mentorship_pairings&segment=' + mtSeg, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var rows = (d.pairings) || [];
      if (!rows.length) { box.innerHTML = '<p class="muted">No pairings in this pool yet. Use “Assign a pairing”, or members can request a mentor.</p>'; return; }
      box.innerHTML = rows.map(function (p) {
        var acts = '';
        if (p.status === 'pending') acts += '<button class="btn btn-primary btn-sm" data-mt-act="activate" data-id="' + p.id + '">Approve</button> <button class="btn btn-outline btn-sm" data-mt-act="decline" data-id="' + p.id + '">Decline</button> ';
        if (p.status === 'active') acts += '<button class="btn btn-outline btn-sm" data-mt-act="reassign" data-id="' + p.id + '">Reassign</button> <button class="btn btn-outline btn-sm" data-mt-act="end" data-id="' + p.id + '">End</button>';
        if (p.status === 'ended' || p.status === 'declined') acts += '<button class="btn btn-outline btn-sm" data-mt-act="reactivate" data-id="' + p.id + '">Reactivate</button>';
        return '<div class="mt-row"><div class="mt-row-main">'
          + '<div class="mt-pair"><b>' + escapeHtml(p.mentor.name) + '</b> <span class="muted">mentor</span> <span class="mt-arrow">→</span> <b>' + escapeHtml(p.mentee.name) + '</b> <span class="muted">mentee</span></div>'
          + '<div class="mt-meta">' + statusPill(p.status) + ' · ' + p.sessions + ' session' + (p.sessions === 1 ? '' : 's')
          + (p.programme ? ' · ' + escapeHtml(p.programme) : '') + (p.origin === 'admin' ? ' · <span class="muted">admin-matched</span>' : '') + '</div></div>'
          + '<div class="mt-acts">' + acts + '</div></div>';
      }).join('');
    });
  }
  function mtApprovals() {
    var box = $('#mtApprovals'); box.innerHTML = '<p class="muted">Loading…</p>';
    fetch(API + '?action=mentorship_mentors&approval=pending&segment=' + mtSeg, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var rows = d.mentors || [];
      if (!rows.length) { box.innerHTML = '<p class="muted">No mentors awaiting approval in this pool. 🎉</p>'; return; }
      box.innerHTML = rows.map(function (m) {
        return '<div class="mt-row"><div class="mt-row-main"><div class="mt-pair"><b>' + escapeHtml(m.name) + '</b> <span class="muted">' + escapeHtml(m.email) + '</span></div>'
          + '<div class="mt-meta">' + escapeHtml(m.headline) + (m.focus ? ' · ' + escapeHtml(m.focus) : '') + ' · capacity ' + m.capacity + '</div></div>'
          + '<div class="mt-acts"><button class="btn btn-primary btn-sm" data-mt-act="approve" data-uid="' + m.user_id + '">Approve</button> <button class="btn btn-outline btn-sm" data-mt-act="mdecline" data-uid="' + m.user_id + '">Decline</button></div></div>';
      }).join('');
    });
  }
  function mtCohorts() {
    var box = $('#mtCohorts'); box.innerHTML = '<p class="muted">Loading…</p>';
    fetch(API + '?action=mentorship_cohorts&segment=' + mtSeg, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var rows = d.cohorts || [];
      var form = '<div class="side-card" style="max-width:680px;margin-bottom:18px"><h3>New cohort (' + mtSeg + ')</h3>'
        + '<div class="grid2"><label class="fld"><span>Name</span><input id="coName" placeholder="e.g. 2026 Leadership Round 1"></label><label class="fld"><span>Programme (optional)</span><input id="coProg" placeholder="e.g. Academy"></label></div>'
        + '<div class="grid2"><label class="fld"><span>Starts</span><input id="coStart" type="date"></label><label class="fld"><span>Ends</span><input id="coEnd" type="date"></label></div>'
        + '<button class="btn btn-primary btn-sm" id="coCreate">Create cohort</button></div>';
      var list = rows.length ? rows.map(function (c) {
        return '<div class="mt-row"><div class="mt-row-main"><div class="mt-pair"><b>' + escapeHtml(c.name) + '</b> ' + statusPill(c.status) + '</div>'
          + '<div class="mt-meta">' + (c.programme ? escapeHtml(c.programme) + ' · ' : '') + (c.starts || '—') + ' → ' + (c.ends || '—') + ' · ' + c.pairs + ' pairing' + (c.pairs === 1 ? '' : 's') + '</div></div>'
          + '<div class="mt-acts"><button class="btn btn-outline btn-sm" data-mt-act="' + (c.status === 'open' ? 'cohort_close' : 'cohort_open') + '" data-id="' + c.id + '">' + (c.status === 'open' ? 'Close' : 'Re-open') + '</button></div></div>';
      }).join('') : '<p class="muted">No cohorts yet — mentorship runs ongoing until you create one.</p>';
      box.innerHTML = form + list;
    });
  }
  function mtInactive() {
    var box = $('#mtInactive'); box.innerHTML = '<p class="muted">Loading…</p>';
    fetch(API + '?action=mentorship_inactive', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var rows = (d.pairs || []).filter(function (p) { return p.segment === mtSeg; });
      if (!rows.length) { box.innerHTML = '<p class="muted">No inactive pairings — everyone’s meeting. 🎉</p>'; return; }
      box.innerHTML = '<p class="muted tiny">Active pairings with no session in the last 3 weeks:</p>' + rows.map(function (p) {
        return '<div class="mt-row"><div class="mt-row-main"><div class="mt-pair"><b>' + escapeHtml(p.mentor) + '</b> <span class="mt-arrow">→</span> <b>' + escapeHtml(p.mentee) + '</b></div>'
          + '<div class="mt-meta">last session ' + (p.last_session ? escapeHtml(p.last_session) : 'never') + '</div></div></div>';
      }).join('');
    });
  }
  // Report §15 — the health board. Sorted worst-first because this is an
  // exception queue, not a directory: the point is that a leader manages the
  // reds and never has to read the greens. Status is a WORD as well as a colour
  // (colour alone is not a signal), and every row carries the behaviour that
  // produced its grade — §3A wants evidence a human can weigh, not a verdict.
  function mtHealth() {
    var box = $('#mtHealth'); box.innerHTML = '<p class="muted">Loading…</p>';
    fetch(API + '?action=mentorship_health', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { box.innerHTML = '<p class="muted">Could not load relationship health.</p>'; return; }
      var rows = (d.pairs || []).filter(function (p) { return p.segment === mtSeg; });
      var c = { red: 0, amber: 0, green: 0 };
      rows.forEach(function (p) { c[p.status]++; });
      if (!rows.length) {
        box.innerHTML = '<p class="muted">No active pairings in this pool yet.</p>';
        return;
      }
      var head = '<div class="mt-health-sum">'
        + '<span class="badge draft">' + c.red + ' Red</span> '
        + '<span class="badge draft">' + c.amber + ' Amber</span> '
        + '<span class="badge published">' + c.green + ' Green</span>'
        + '<p class="muted tiny" style="margin:8px 0 0">Escalations count misses from ' + escapeHtml(d.since || '') + ' onward — nothing before the engine was switched on.</p></div>';
      box.innerHTML = head + rows.map(function (p) {
        var label = p.status.charAt(0).toUpperCase() + p.status.slice(1);
        var meta = [];
        if (p.rate !== null && p.rate !== undefined) meta.push(p.rate + '% attendance');
        if (p.streak > 0) meta.push(p.streak + ' missed in a row');
        if (p.escalations > 0) meta.push(p.escalations + ' escalation' + (p.escalations === 1 ? '' : 's'));
        return '<div class="mt-row mt-h-' + escapeHtml(p.status) + '">'
          + '<div class="mt-row-main">'
          + '<div class="mt-pair"><span class="mt-h-tag mt-h-tag-' + escapeHtml(p.status) + '">' + escapeHtml(label) + '</span> '
          + '<b>' + escapeHtml(p.mentor) + '</b> <span class="mt-arrow">&rarr;</span> <b>' + escapeHtml(p.mentee) + '</b></div>'
          + (meta.length ? '<div class="mt-meta">' + escapeHtml(meta.join(' \u00b7 ')) + '</div>' : '')
          + '<ul class="mt-h-why">' + (p.reasons || []).map(function (r) { return '<li>' + escapeHtml(r) + '</li>'; }).join('') + '</ul>'
          + '</div></div>';
      }).join('');
    }).catch(function () { box.innerHTML = '<p class="muted">Could not load relationship health.</p>'; });
  }
  // Report §20 — the promotion queue. Nothing here promotes anybody: the level
  // is changed in Members, with its own audit trail. What this shows is the CASE,
  // so the decision is informed rather than a guess — and where the rules engine
  // and the AI disagree, that disagreement leads, because it is the single most
  // useful thing on the screen.
  function mtPromotions() {
    var box = $('#mtPromotions'); box.innerHTML = '<p class="muted">Loading…</p>';
    api('promotion_queue').then(function (r) {
      var d = r.data || {};
      if (!d.ok) { box.innerHTML = '<p class="muted">Could not load the promotion queue.</p>'; return; }
      var rows = d.queue || [];
      if (!rows.length) {
        box.innerHTML = '<div class="mt-promo-empty"><p><b>Nobody is up for review.</b></p>'
          + '<p class="muted tiny">Members appear here once they are actively mentoring and meeting consistently. '
          + 'Advancement needs active mentees — names on a list do not qualify.</p></div>';
        return;
      }
      box.innerHTML = '<p class="muted tiny">The AI writes the case. You decide — change a level in <b>Members &amp; People</b>.</p>'
        + rows.map(promoRow).join('');
    }).catch(function () { box.innerHTML = '<p class="muted">Could not load the promotion queue.</p>'; });
  }

  function promoRow(p) {
    var rev = p.review;
    var cls = p.ready ? 'is-ready' : 'is-near';
    var h = '<div class="mt-row mt-promo ' + cls + '" data-uid="' + p.user_id + '">';
    h += '<div class="mt-row-main">';
    h += '<div class="mt-pair"><span class="mt-h-tag ' + (p.ready ? 'mt-h-tag-green' : 'mt-h-tag-amber') + '">'
       + (p.ready ? 'Meets the criteria' : 'Approaching') + '</span> '
       + '<b>' + escapeHtml(p.name) + '</b> <span class="mt-arrow">&rarr;</span> <b>' + escapeHtml(p.next) + '</b>'
       + ' <span class="mt-meta">currently ' + escapeHtml(p.level) + '</span></div>';

    if (rev && !rev.agrees) {
      h += '<p class="promo-clash"><b>The rules engine and the AI disagree.</b> '
         + 'The engine says ' + (rev.engine_ok ? 'yes' : 'no') + '; the AI says ' + (rev.ai_ok ? 'yes' : 'no')
         + '. Read both before deciding — the engine owns the criteria, the AI is reading how well the mentoring is actually going.</p>';
    }

    if (p.reasons && p.reasons.length) {
      h += '<div class="promo-block"><h5>Met</h5><ul>' + p.reasons.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul></div>';
    }
    if (p.gaps && p.gaps.length) {
      h += '<div class="promo-block promo-gaps"><h5>Outstanding</h5><ul>' + p.gaps.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul></div>';
    }
    if (rev && rev.ai_reasons && rev.ai_reasons.length) {
      h += '<div class="promo-block"><h5>The AI\'s read'
         + (rev.ai_confidence ? ' <span class="muted">(' + escapeHtml(rev.ai_confidence) + ' confidence)</span>' : '')
         + '</h5><ul>' + rev.ai_reasons.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul></div>';
    }
    if (rev && rev.status === 'deferred') {
      h += '<p class="promo-deferred"><b>Set aside</b> by ' + escapeHtml(rev.decided_by || 'someone')
         + (rev.note ? ' — ' + escapeHtml(rev.note) : '') + '</p>';
    }
    if (rev && rev.source === 'engine-only') {
      h += '<p class="mt-meta">No AI provider answered, so this is the rules engine\'s assessment alone.</p>';
    }

    h += '<div class="mt-acts">';
    if (!rev)                            h += '<button class="btn btn-outline btn-sm" data-promo="review" data-uid="' + p.user_id + '">Write the case</button> ';
    else                                 h += '<button class="btn btn-outline btn-sm" data-promo="review" data-uid="' + p.user_id + '" data-force="1">Re-run the review</button> ';
    if (rev && rev.status === 'deferred') h += '<button class="btn btn-outline btn-sm" data-promo="reopen" data-uid="' + p.user_id + '">Put it back</button>';
    else if (rev)                         h += '<button class="btn btn-outline btn-sm" data-promo="defer" data-uid="' + p.user_id + '">Not yet…</button>';
    h += '</div></div></div>';
    return h;
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-promo]') : null;
    if (!b) return;
    var act = b.getAttribute('data-promo'), uid = +b.getAttribute('data-uid');
    if (act === 'defer') {
      var note = prompt('Why is this not the moment? Whoever reads the queue next will see this.');
      if (note === null) return;
      if (!note.trim()) { alert('A reason is required — an empty note tells the next reader nothing.'); return; }
      post('promotion_defer', { user_id: uid, note: note.trim() }).then(function (r) {
        if (!(r.data || {}).ok) alert(((r.data || {}).error) || 'Could not save that.');
        mtPromotions();
      });
      return;
    }
    b.disabled = true;
    if (act === 'review') b.textContent = 'Reading the record…';
    post(act === 'review' ? 'promotion_review' : 'promotion_reopen',
         { user_id: uid, force: b.getAttribute('data-force') === '1' }).then(function (r) {
      var d = r.data || {};
      if (!d.ok && d.error) alert(d.error);
      mtPromotions();
    }).catch(function () { mtPromotions(); });
  });

  function mtLoadCohortOptions() {
    fetch(API + '?action=mentorship_cohorts&segment=' + mtSeg, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var sel = $('#mtCohort'); if (!sel) return;
      sel.innerHTML = '<option value="0">— Ongoing (no cohort) —</option>' + (d.cohorts || []).filter(function (c) { return c.status === 'open'; }).map(function (c) { return '<option value="' + c.id + '">' + escapeHtml(c.name) + '</option>'; }).join('');
    });
  }
  function mtPickerWire(inputId, pickId, action, which) {
    var inp = $('#' + inputId), pick = $('#' + pickId); if (!inp) return;
    var t;
    inp.addEventListener('input', function () {
      clearTimeout(t); var q = inp.value.trim(); if (q.length < 2) { pick.innerHTML = ''; return; }
      t = setTimeout(function () {
        var url = action === 'mentor'
          ? (API + '?action=mentorship_mentors&approval=approved&segment=' + mtSeg + '&q=' + encodeURIComponent(q))
          : (API + '?action=mentorship_find_users&segment=' + mtSeg + '&q=' + encodeURIComponent(q));
        fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
          var rows = action === 'mentor' ? (d.mentors || []) : (d.users || []);
          pick.innerHTML = rows.slice(0, 8).map(function (u) {
            var id = action === 'mentor' ? u.user_id : u.id;
            return '<button type="button" class="mt-pick-item" data-id="' + id + '" data-name="' + escapeHtml(u.name) + '">' + escapeHtml(u.name) + ' <span class="muted">' + escapeHtml(u.email) + '</span></button>';
          }).join('') || '<p class="muted tiny" style="padding:6px">No matches in this pool.</p>';
        });
      }, 220);
    });
    pick.addEventListener('click', function (e) {
      var it = e.target.closest('.mt-pick-item'); if (!it) return;
      mtPick[which] = { id: parseInt(it.getAttribute('data-id'), 10), name: it.getAttribute('data-name') };
      inp.value = it.getAttribute('data-name'); pick.innerHTML = '';
      $('#mtAssignSave').disabled = !(mtPick.mentor && mtPick.mentee);
    });
  }
  (function wireMentorship() {
    var view = $('#mentorshipView'); if (!view) return;
    document.querySelectorAll('.seg-btn[data-seg]').forEach(function (b) {
      b.addEventListener('click', function () {
        mtSeg = b.getAttribute('data-seg');
        document.querySelectorAll('.seg-btn').forEach(function (x) { x.classList.toggle('active', x === b); });
        $('#mtAssignSeg').textContent = '· ' + (mtSeg === 'org' ? 'Org members' : 'External');
        mtPick = { mentor: null, mentee: null }; $('#mtMentorSearch').value = ''; $('#mtMenteeSearch').value = ''; $('#mtAssignSave').disabled = true;
        loadMentorship();
      });
    });
    document.querySelectorAll('.subtab[data-mt]').forEach(function (b) { b.addEventListener('click', function () { mtTab = b.getAttribute('data-mt'); mtRenderTab(); }); });
    $('#mtAssignBtn').addEventListener('click', function () { var a = $('#mtAssign'); a.hidden = !a.hidden; $('#mtAssignSeg').textContent = '· ' + (mtSeg === 'org' ? 'Org members' : 'External'); });
    mtPickerWire('mtMentorSearch', 'mtMentorPick', 'mentor', 'mentor');
    mtPickerWire('mtMenteeSearch', 'mtMenteePick', 'user', 'mentee');
    $('#mtAssignSave').addEventListener('click', function () {
      if (!(mtPick.mentor && mtPick.mentee)) return;
      var btn = this; btn.disabled = true;
      post('mentorship_assign', { mentor_id: mtPick.mentor.id, mentee_id: mtPick.mentee.id, cohort_id: parseInt($('#mtCohort').value, 10) || 0, programme: $('#mtProgramme').value.trim() }).then(function (r) {
        var d = r.data || {};
        if (d.ok) { toast('Pairing created.'); $('#mtAssign').hidden = true; mtPick = { mentor: null, mentee: null }; $('#mtMentorSearch').value = ''; $('#mtMenteeSearch').value = ''; $('#mtProgramme').value = ''; loadMentorship(); }
        else { $('#mtAssignMsg').textContent = d.error || 'Could not assign.'; $('#mtAssignMsg').style.color = '#d22'; btn.disabled = false; }
      });
    });
    // delegated actions across the panels
    view.addEventListener('click', function (e) {
      var b = e.target.closest('[data-mt-act]'); if (!b) return;
      var act = b.getAttribute('data-mt-act'), id = b.getAttribute('data-id'), uid = b.getAttribute('data-uid');
      var go = function (action, payload) { b.disabled = true; post(action, payload).then(function (r) { if (r.data && r.data.ok) { toast('Done.'); loadMentorship(); } else { toast((r.data && r.data.error) || 'Could not complete.'); b.disabled = false; } }); };
      if (act === 'approve') go('mentorship_approve', { user_id: +uid });
      else if (act === 'mdecline') go('mentorship_decline', { user_id: +uid });
      else if (act === 'activate') go('mentorship_set_status', { id: +id, status: 'active' });
      else if (act === 'decline') go('mentorship_set_status', { id: +id, status: 'declined' });
      else if (act === 'end') { if (confirm('End this mentorship?')) go('mentorship_set_status', { id: +id, status: 'ended' }); }
      else if (act === 'reactivate') go('mentorship_set_status', { id: +id, status: 'active' });
      else if (act === 'cohort_close') go('mentorship_cohort_status', { id: +id, status: 'closed' });
      else if (act === 'cohort_open') go('mentorship_cohort_status', { id: +id, status: 'open' });
      else if (act === 'reassign') {
        var em = prompt('Reassign to which approved mentor? Enter their email (same pool):'); if (!em) return;
        fetch(API + '?action=mentorship_mentors&approval=approved&segment=' + mtSeg + '&q=' + encodeURIComponent(em.trim()), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
          var m = (d.mentors || []).filter(function (x) { return x.email.toLowerCase() === em.trim().toLowerCase(); })[0] || (d.mentors || [])[0];
          if (!m) { toast('No approved mentor matches that email in this pool.'); return; }
          go('mentorship_reassign', { id: +id, mentor_id: m.user_id });
        });
      } else if (act === 'cohort_close' || act === 'cohort_open') { /* handled */ }
    });
    // cohort create (delegated, since the form is re-rendered)
    $('#mtCohorts').addEventListener('click', function (e) {
      if (!e.target.closest('#coCreate')) return;
      post('mentorship_cohort_create', { name: ($('#coName') || {}).value || '', programme: ($('#coProg') || {}).value || '', starts: ($('#coStart') || {}).value || '', ends: ($('#coEnd') || {}).value || '', segment: mtSeg }).then(function (r) {
        if (r.data && r.data.ok) { toast('Cohort created.'); mtCohorts(); mtLoadCohortOptions(); } else toast((r.data && r.data.error) || 'Could not create.');
      });
    });
  })();

  /* ---- Activity (per-area audit trail + undo) ---- */
  var actArea = '';
  function loadActivity() {
    var list = $('#actList'); if (list) list.innerHTML = '<p class="muted">Loading…</p>';
    api('activity' + (actArea ? '&area=' + encodeURIComponent(actArea) : '')).then(function (r) {
      var d = r.data || {}, areas = d.areas || [], entries = d.entries || [];
      var chips = $('#actAreas');
      if (chips) chips.innerHTML = ['<button class="act-chip' + (actArea === '' ? ' active' : '') + '" data-area="">All</button>']
        .concat(areas.map(function (a) { return '<button class="act-chip' + (actArea === a ? ' active' : '') + '" data-area="' + escapeHtml(a) + '">' + escapeHtml(a) + '</button>'; })).join('');
      if (!list) return;
      list.innerHTML = entries.length ? entries.map(function (en) {
        return '<div class="act-row"><div class="act-main"><span class="act-area">' + escapeHtml(en.area) + '</span>'
          + '<span class="act-action">' + escapeHtml(en.action.replace(/_/g, ' ')) + '</span>'
          + (en.target ? ' <span class="muted">#' + escapeHtml(en.target) + '</span>' : '')
          + '<div class="act-detail">' + escapeHtml(en.detail) + (en.undone ? ' <span class="badge draft">undone</span>' : '') + '</div>'
          + '<div class="act-when">' + escapeHtml(en.created_at) + ' · ' + escapeHtml(en.actor) + '</div></div>'
          + '<div class="act-acts">' + (en.can_undo ? '<button class="btn btn-outline btn-sm" data-undo="' + en.id + '">' + escapeHtml(en.undo_label || 'Undo') + '</button>' : '') + '</div></div>';
      }).join('') : '<p class="muted">No activity recorded yet.</p>';
    });
  }
  (function wireActivity() {
    var view = $('#activityView'); if (!view) return;
    $('#actRefresh').addEventListener('click', loadActivity);
    $('#actAreas').addEventListener('click', function (e) { var c = e.target.closest('.act-chip'); if (!c) return; actArea = c.getAttribute('data-area'); loadActivity(); });
    $('#actList').addEventListener('click', function (e) {
      var u = e.target.closest('[data-undo]'); if (!u) return;
      if (!confirm('Undo this action?')) return;
      u.disabled = true;
      post('activity_undo', { id: parseInt(u.getAttribute('data-undo'), 10) }).then(function (r) {
        if (r.data && r.data.ok) { toast('Reverted.'); loadActivity(); } else { toast((r.data && r.data.error) || 'Could not undo.'); u.disabled = false; }
      });
    });
  })();

  /* ---- Team & roles (superadmin) ---- */
  function loadAdmins() {
    var box = $('#adList'); if (box) box.innerHTML = '<p class="muted">Loading…</p>';
    api('admins_list').then(function (r) {
      var d = r.data || {};
      var rows = d.admins || [];
      var superEmail = (d.default_superadmin || '').toLowerCase();
      renderSuperCard(superEmail, !!d.has_initial_password);
      if (!box) return;
      box.innerHTML = rows.length ? rows.map(function (a) {
        var isDefault = superEmail && (a.email || '').toLowerCase() === superEmail;
        var tag = isDefault ? ' <span class="badge" style="background:#f3b41622;color:#8a6400">default</span>' : '';
        // The default super admin can't be revoked from here — it re-provisions
        // on next sign-in anyway; revoking would only confuse.
        var act = isDefault
          ? '<span class="muted tiny">default super admin</span>'
          : '<button class="btn btn-outline btn-sm" data-admin-remove="' + escapeHtml(a.email) + '">Revoke</button>';
        return '<div class="mt-row"><div class="mt-row-main"><div class="mt-pair"><b>' + escapeHtml(a.email) + '</b> <span class="badge published" style="text-transform:capitalize">' + escapeHtml(a.role) + '</span>' + tag + '</div>'
          + '<div class="mt-meta">added ' + escapeHtml(a.created_at || '') + (a.added_by ? ' · by ' + escapeHtml(a.added_by) : '') + '</div></div>'
          + '<div class="mt-acts">' + act + '</div></div>';
      }).join('') : '<p class="muted">Only the break-glass token (Super Admin) has access right now. Grant a member access above.</p>';
    });
  }

  // The always-present default super admin, with a one-time reveal of the
  // auto-generated password (only shown when one is pending).
  function renderSuperCard(email, hasPw) {
    var card = $('#adSuperCard'); if (!card) return;
    if (!email) { card.style.display = 'none'; return; }
    card.style.display = '';
    card.innerHTML =
      '<h3>Default Super Admin</h3>'
      + '<p class="muted" style="margin-top:-4px">A single account is auto-provisioned so the Studio always has a way in. It has full access to every admin, manager and member.</p>'
      + '<div class="mt-pair" style="margin:8px 0"><b>' + escapeHtml(email) + '</b> <span class="badge published">superadmin</span></div>'
      + (hasPw
          ? '<p class="muted tiny" style="margin:0 0 8px">A one-time password was generated for this account. Reveal it once, save it somewhere safe, then change it.</p>'
            + '<button class="btn btn-primary btn-sm" id="adReveal" type="button">Reveal one-time password</button>'
            + '<p class="mono" id="adRevealOut" style="margin-top:10px;display:none;user-select:all;background:var(--panel,#f6f7f9);padding:10px 12px;border-radius:8px"></p>'
          : '<p class="muted tiny" style="margin:0">Signs in with its configured password (or with Google, if your org uses Google sign-in). Reset the password anytime from the member’s email above.</p>');
    var btn = $('#adReveal');
    if (btn) btn.addEventListener('click', function () {
      btn.disabled = true;
      post('superadmin_reveal', {}).then(function (r) {
        var out = $('#adRevealOut'); var dd = r.data || {};
        if (dd.password) {
          out.style.display = ''; out.textContent = dd.email + '  ·  ' + dd.password;
          btn.textContent = 'Revealed — copy it now'; toast('Copied to the field below. Save it, then change it.');
        } else { toast('No pending password (an explicit password is configured).'); btn.disabled = false; }
      });
    });
  }
  (function wireAdmins() {
    var view = $('#adminsView'); if (!view) return;
    $('#adAdd').addEventListener('click', function () {
      var email = $('#adEmail').value.trim(), role = $('#adRole').value, msg = $('#adMsg'), btn = this;
      if (!email) { msg.textContent = 'Enter an email.'; msg.style.color = '#d22'; return; }
      btn.disabled = true;
      post('admin_add', { email: email, role: role }).then(function (r) {
        var d = r.data || {}; msg.style.color = d.ok ? '#2ea043' : '#d22';
        msg.textContent = d.ok ? ('Granted ' + role + ' access to ' + email + '.') : (d.error || 'Could not grant access.');
        if (d.ok) { $('#adEmail').value = ''; loadAdmins(); }
      }).finally(function () { btn.disabled = false; });
    });
    $('#adList').addEventListener('click', function (e) {
      var b = e.target.closest('[data-admin-remove]'); if (!b) return;
      var em = b.getAttribute('data-admin-remove');
      if (!confirm('Revoke Studio access for ' + em + '?')) return;
      b.disabled = true;
      post('admin_remove', { email: em }).then(function (r) { if (r.data && r.data.ok) { toast('Access revoked.'); loadAdmins(); } else { toast((r.data && r.data.error) || 'Could not revoke.'); b.disabled = false; } });
    });
  })();

  /* ============================================================
     Rules & AI — Afrovanguard's constitution as editable data.

     Three panes over three endpoints: the rules registry (typed, validated
     server-side), the knowledge base fed to the assistants, and the prompt
     templates. Every value shows where it came from — default, config/env or
     set here — because "why is this rule behaving like that" is the question
     this screen exists to answer.
     ============================================================ */
  var rulesState = { groups: {}, dirty: {} };

  document.querySelectorAll('.rt-tab').forEach(function (t) {
    t.addEventListener('click', function () {
      var which = t.getAttribute('data-rt');
      document.querySelectorAll('.rt-tab').forEach(function (x) { x.classList.toggle('active', x === t); });
      $('#rtSetup').hidden = which !== 'setup';
      $('#rtRules').hidden = which !== 'rules';
      $('#rtKb').hidden = which !== 'kb';
      $('#rtPrompts').hidden = which !== 'prompts';
      $('#rtLab').hidden = which !== 'lab';
      $('#rtChat').hidden = which !== 'chat';
      $('#rtProps').hidden = which !== 'props';
      if (which === 'setup') loadSetup();
      if (which === 'kb') loadKb();
      if (which === 'prompts') loadPrompts();
      if (which === 'lab') loadAiStatus();
      if (which === 'chat') loadAiStatus();
      if (which === 'props') loadProposals();
    });
  });

  /* ============================================================
     Setup — connecting the AI without editing a file on the server.

     A stored key is never sent back here, so the field for one is always empty
     with the masked tail shown beside it. Typing replaces it; leaving it blank
     leaves it alone; the Clear button removes it.
     ============================================================ */
  var setupDirty = {};

  function loadSetup() {
    var host = $('#setupGroups'); if (!host) return;
    host.innerHTML = '<p class="muted">Loading…</p>';
    setupDirty = {};
    api('setup_get').then(function (r) {
      var d = r.data || {};
      if (!d.ok) { host.innerHTML = '<p class="muted">Could not load the setup.</p>'; return; }
      renderSetup(d);
    });
  }

  function renderSetup(d) {
    var warn = $('#setupCrypto');
    var c = d.crypto || {};
    if (warn) {
      warn.hidden = !!c.ok;
      warn.innerHTML = c.ok ? '' : '<strong>Keys cannot be stored yet</strong><p>' + escapeHtml(c.reason) + '</p>';
    }

    var tests = $('#setupTests');
    if (tests) {
      tests.innerHTML = (d.testable || []).map(function (t) {
        return '<button type="button" class="btn btn-outline btn-sm setup-test" data-what="' + escapeHtml(t.key) + '"'
          + (t.ready ? '' : ' disabled title="Nothing configured to test yet"') + '>Test ' + escapeHtml(t.label) + '</button>'
          + '<span class="setup-test-out" id="stout_' + escapeHtml(t.key) + '"></span>';
      }).join('');
    }

    var host = $('#setupGroups'); if (!host) return;
    host.innerHTML = (d.groups || []).map(function (g) {
      return '<section class="setup-group"><h3>' + escapeHtml(g.group) + '</h3>'
        + g.fields.map(setupField).join('') + '</section>';
    }).join('');
  }

  function setupField(f) {
    var id = 'set_' + f.key;
    var input;
    if (f.type === 'enum') {
      input = '<select id="' + id + '" data-skey="' + escapeHtml(f.key) + '">'
        + f.options.map(function (o) {
            return '<option value="' + escapeHtml(o) + '"' + (o === f.value ? ' selected' : '') + '>'
              + escapeHtml(o === '' ? 'Auto-detect' : o) + '</option>';
          }).join('') + '</select>';
    } else {
      input = '<input id="' + id + '" type="' + (f.secret ? 'password' : 'text') + '" spellcheck="false" autocomplete="off"'
        + ' data-skey="' + escapeHtml(f.key) + '"'
        + ' value="' + escapeHtml(f.secret ? '' : f.value) + '"'
        + ' placeholder="' + escapeHtml(f.secret && f.is_set ? 'Stored — type to replace' : f.placeholder) + '" />';
    }
    var badge = { studio: 'set here', config: 'from config.php', env: 'from the environment', unset: 'not set' }[f.source] || f.source;
    var cls = f.source === 'unset' ? 'setup-src-unset' : (f.source === 'studio' ? 'setup-src-studio' : 'setup-src-env');
    return '<div class="setup-field">'
      + '<div class="setup-f-h"><label for="' + id + '">' + escapeHtml(f.label) + '</label>'
      + '<span class="setup-src ' + cls + '">' + escapeHtml(badge) + '</span>'
      + (f.secret && f.preview ? '<code class="setup-mask">' + escapeHtml(f.preview) + '</code>' : '')
      + '</div>'
      + '<p class="setup-help">' + escapeHtml(f.help) + '</p>'
      + (f.shadowing
          ? '<p class="setup-shadow">This is overriding a value from ' + escapeHtml(f.shadowing === 'config' ? 'config.php' : 'the environment') + '. Clear it to go back to that.</p>'
          : '')
      + '<div class="setup-f-row">' + input
      + (f.source === 'studio' ? '<button type="button" class="btn btn-outline btn-sm setup-clear" data-skey="' + escapeHtml(f.key) + '">Clear</button>' : '')
      + '</div></div>';
  }

  $('#setupGroups') && $('#setupGroups').addEventListener('input', function (e) {
    var el = e.target.closest('[data-skey]'); if (!el) return;
    setupDirty[el.getAttribute('data-skey')] = el.value;
  });
  $('#setupGroups') && $('#setupGroups').addEventListener('change', function (e) {
    var el = e.target.closest('select[data-skey]'); if (!el) return;
    setupDirty[el.getAttribute('data-skey')] = el.value;
  });

  $('#setupGroups') && $('#setupGroups').addEventListener('click', function (e) {
    var b = e.target.closest('.setup-clear'); if (!b) return;
    var key = b.getAttribute('data-skey');
    if (!confirm('Remove this value? Anything set in the environment will apply again.')) return;
    var vals = {}; vals[key] = '';
    post('setup_save', { values: vals }).then(function (r) {
      var d = r.data || {};
      if (d.saved) { toast('Cleared.'); renderSetup(d); setupDirty = {}; }
      else toast('Could not clear that.');
    });
  });

  $('#setupSave') && $('#setupSave').addEventListener('click', function () {
    if (!Object.keys(setupDirty).length) { $('#setupMsg').textContent = 'Nothing changed.'; return; }
    $('#setupSave').disabled = true;
    $('#setupMsg').textContent = 'Saving…';
    post('setup_save', { values: setupDirty }).then(function (r) {
      $('#setupSave').disabled = false;
      var d = r.data || {};
      var errs = d.errors || {};
      var names = Object.keys(errs);
      if (names.length) {
        $('#setupMsg').textContent = names.map(function (k) { return k + ': ' + errs[k]; }).join(' · ');
      } else {
        $('#setupMsg').textContent = 'Saved.';
        toast('Setup saved.');
      }
      // Re-render either way: the successful fields have landed and their
      // source badges need to reflect that.
      if (d.groups) { renderSetup(d); setupDirty = {}; }
    }).catch(function () { $('#setupSave').disabled = false; $('#setupMsg').textContent = 'Could not save.'; });
  });

  $('#setupRefresh') && $('#setupRefresh').addEventListener('click', loadSetup);

  $('#setupTests') && $('#setupTests').addEventListener('click', function (e) {
    var b = e.target.closest('.setup-test'); if (!b) return;
    var what = b.getAttribute('data-what');
    var out = $('#stout_' + what);
    b.disabled = true; if (out) { out.className = 'setup-test-out'; out.textContent = 'testing…'; }
    post('setup_test', { what: what }).then(function (r) {
      b.disabled = false;
      var d = r.data || {};
      if (!out) return;
      out.className = 'setup-test-out ' + (d.ok ? 'is-ok' : 'is-bad');
      out.textContent = (d.ok ? '✓ ' : '✗ ') + (d.detail || '') + (d.ms != null ? ' (' + d.ms + 'ms)' : '');
    }).catch(function () { b.disabled = false; if (out) { out.className = 'setup-test-out is-bad'; out.textContent = 'The test failed.'; } });
  });

  /* ============================================================
     The AI bench, the chat console, and the proposal queue.

     Three things an administrator could not do before: run an AI job without
     waiting for a real meeting to end, ask the assistant a question it has to
     look up, and see what it wants to change about itself.
     ============================================================ */
  var aiCaps = [], aiCap = null, aiTools = [], chatHistory = [];

  function loadAiStatus() {
    if (aiCaps.length) { renderLabPick(); renderChatCaps(); return; }
    api('ai_status').then(function (r) {
      var d = r.data || {};
      if (!d.ok) { $('#labStatus').innerHTML = '<p class="muted">Could not load the AI status.</p>'; return; }
      aiCaps = d.capabilities || [];
      aiTools = d.tools || [];
      aiCap = aiCap || (aiCaps[0] && aiCaps[0].key);
      renderAiStatus(d);
      renderLabPick();
      renderChatCaps();
      setPropBadge(d.pending || 0);
    });
  }

  function setPropBadge(n) {
    var b = $('#propBadge'); if (!b) return;
    b.hidden = !n; b.textContent = n || '';
  }

  function renderAiStatus(d) {
    var box = $('#labStatus'); if (!box) return;
    var a = d.agent || {}, s = d.search || {};
    var bits = [];
    bits.push(a.available
      ? '<span class="lab-ok">Tool use ready · ' + escapeHtml(a.provider) + '</span>'
      : '<span class="lab-off">Tool use unavailable</span>');
    bits.push('<span class="muted">Tiers granted: ' + escapeHtml((a.tiers || []).join(', ') || 'none') + '</span>');
    bits.push(s.provider
      ? '<span class="lab-ok">Web search · ' + escapeHtml(s.provider) + '</span>'
      : '<span class="lab-off">Web search off' + (s.why ? ' — ' + escapeHtml(s.why) : '') + '</span>');
    box.innerHTML = bits.join(' ');
  }

  function renderLabPick() {
    var host = $('#labPick'); if (!host) return;
    host.innerHTML = aiCaps.map(function (c) {
      return '<button type="button" class="lab-cap' + (c.key === aiCap ? ' active' : '') + '" data-cap="' + escapeHtml(c.key) + '">'
        + '<strong>' + escapeHtml(c.label) + '</strong>'
        + '<span>' + escapeHtml(c.about) + '</span>'
        + (c.ready ? '<em class="lab-off">' + escapeHtml(c.ready) + '</em>' : '')
        + '</button>';
    }).join('');
    renderLabForm();
  }

  function currentCap() {
    for (var i = 0; i < aiCaps.length; i++) if (aiCaps[i].key === aiCap) return aiCaps[i];
    return null;
  }

  function renderLabForm() {
    var host = $('#labForm'); if (!host) return;
    var c = currentCap();
    if (!c) { host.innerHTML = ''; return; }
    if (c.needs === 'tool') {
      host.innerHTML = '<label class="fld"><span>Tool</span><select id="labTool">'
        + aiTools.map(function (t) {
            return '<option value="' + escapeHtml(t.name) + '"' + (t.usable ? '' : ' disabled')
              + '>' + escapeHtml(t.name) + ' (' + escapeHtml(t.tier) + ')' + (t.usable ? '' : ' — ' + escapeHtml(t.why)) + '</option>';
          }).join('')
        + '</select></label>'
        + '<label class="fld"><span>Arguments (JSON)</span><textarea id="labArgs" rows="4" spellcheck="false">{}</textarea></label>'
        + '<p class="muted tiny" id="labToolDesc"></p>';
      var sel = $('#labTool');
      var showDesc = function () {
        for (var i = 0; i < aiTools.length; i++) if (aiTools[i].name === sel.value) { $('#labToolDesc').textContent = aiTools[i].desc; return; }
      };
      sel.addEventListener('change', showDesc); showDesc();
      return;
    }
    var rows = c.needs === 'text' ? 10 : 2;
    host.innerHTML = '<label class="fld"><span>' + (c.needs === 'url' ? 'Page URL' : c.needs === 'question' ? 'Your question' : 'Input')
      + '</span><textarea id="labText" rows="' + rows + '" placeholder="' + escapeHtml(c.placeholder) + '"></textarea></label>';
  }

  function renderChatCaps() {
    var host = $('#chatCaps'); if (!host) return;
    var usable = aiTools.filter(function (t) { return t.usable; });
    host.innerHTML = '<p class="muted tiny">Tools it can use right now: '
      + (usable.length ? usable.map(function (t) { return '<code>' + escapeHtml(t.name) + '</code>'; }).join(' ') : 'none')
      + '</p>';
  }

  function labStepsHtml(steps) {
    if (!steps || !steps.length) return '';
    return '<div class="lab-steps"><h4>What it looked up</h4>' + steps.map(function (s) {
      return '<div class="lab-step' + (s.ok ? '' : ' is-bad') + '">'
        + '<code>' + escapeHtml(s.tool) + '</code>'
        + '<span class="lab-args">' + escapeHtml(JSON.stringify(s.args || {})) + '</span>'
        + (s.ok ? '<span class="lab-prev">' + escapeHtml(s.preview || '') + '</span>'
                : '<span class="lab-off">' + escapeHtml(s.error || 'failed') + '</span>')
        + '</div>';
    }).join('') + '</div>';
  }

  $('#labGo') && $('#labGo').addEventListener('click', function () {
    var c = currentCap(); if (!c) return;
    var payload = { capability: c.key };
    if (c.needs === 'tool') {
      payload.tool = ($('#labTool') || {}).value || '';
      try { payload.args = JSON.parse(($('#labArgs') || {}).value || '{}'); }
      catch (e) { $('#labMsg').textContent = 'The arguments are not valid JSON.'; return; }
    } else {
      payload.text = ($('#labText') || {}).value || '';
    }
    $('#labGo').disabled = true;
    $('#labMsg').textContent = 'Running…';
    $('#labOut').innerHTML = '';
    post('ai_run', payload).then(function (r) {
      $('#labGo').disabled = false;
      var d = r.data || {};
      $('#labMsg').textContent = (d.ms != null ? d.ms + 'ms' : '') + (d.provider ? ' · ' + d.provider : '');
      var h = '';
      if (d.error) h += '<div class="lab-err">' + escapeHtml(d.error) + '</div>';
      h += labStepsHtml(d.steps);
      if (d.output) h += '<div class="lab-block"><h4>Result</h4><pre>' + escapeHtml(d.output) + '</pre></div>';
      if (d.prompt) h += '<details class="lab-block"><summary>The prompt it was given (' + d.prompt.length + ' chars)</summary><pre>' + escapeHtml(d.prompt) + '</pre></details>';
      $('#labOut').innerHTML = h || '<p class="muted">No output.</p>';
    }).catch(function () { $('#labGo').disabled = false; $('#labMsg').textContent = 'The run failed.'; });
  });

  $('#labSample') && $('#labSample').addEventListener('click', function () {
    var c = currentCap(); if (!c || !$('#labText')) return;
    $('#labText').value = c.sample || '';
  });

  $('#labPick') && $('#labPick').addEventListener('click', function (e) {
    var b = e.target.closest('.lab-cap'); if (!b) return;
    aiCap = b.getAttribute('data-cap');
    renderLabPick();
    $('#labOut').innerHTML = ''; $('#labMsg').textContent = '';
  });

  /* ---- chat ---- */
  function renderChat() {
    var log = $('#chatLog'); if (!log) return;
    if (!chatHistory.length) {
      log.innerHTML = '<p class="pc-empty muted">Ask it something. It will look the answer up rather than guess — and it will tell you when it cannot find out.</p>';
      return;
    }
    log.innerHTML = chatHistory.map(function (m) {
      return '<div class="chat-msg chat-' + m.role + '">'
        + '<div class="chat-who">' + (m.role === 'user' ? 'You' : 'Assistant') + '</div>'
        + '<div class="chat-body">' + escapeHtml(m.text).replace(/\n/g, '<br>') + '</div>'
        + (m.steps ? labStepsHtml(m.steps) : '')
        + '</div>';
    }).join('');
    log.scrollTop = log.scrollHeight;
  }

  $('#chatForm') && $('#chatForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var input = $('#chatInput');
    var msg = (input.value || '').trim();
    if (!msg) return;
    input.value = '';
    chatHistory.push({ role: 'user', text: msg });
    renderChat();
    $('#chatSend').disabled = true;
    // Only the plain turns go back as history — the tool steps are display-only.
    var hist = chatHistory.slice(0, -1).map(function (m) { return { role: m.role, text: m.text }; });
    post('ai_chat', { message: msg, history: hist }).then(function (r) {
      $('#chatSend').disabled = false;
      var d = r.data || {};
      chatHistory.push({
        role: 'assistant',
        text: d.ok ? (d.text || '') : ('⚠ ' + (d.error || 'That did not work.')),
        steps: d.steps || []
      });
      renderChat();
      setPropBadge(d.pending || 0);
    }).catch(function () {
      $('#chatSend').disabled = false;
      chatHistory.push({ role: 'assistant', text: '⚠ The request failed.' });
      renderChat();
    });
  });

  $('#chatClear') && $('#chatClear').addEventListener('click', function () { chatHistory = []; renderChat(); });

  /* ---- proposals ---- */
  function loadProposals() {
    var host = $('#propList'); if (!host) return;
    host.innerHTML = '<p class="muted">Loading…</p>';
    var status = ($('#propFilter') || {}).value || 'pending';
    api('ai_proposals&status=' + encodeURIComponent(status)).then(function (r) {
      var d = r.data || {};
      if (!d.ok) { host.innerHTML = '<p class="muted">Could not load the proposals.</p>'; return; }
      renderProposals(d.proposals || []);
    });
  }

  function renderProposals(list) {
    var host = $('#propList'); if (!host) return;
    if (!list.length) { host.innerHTML = '<p class="pc-empty muted">Nothing here. The AI files a proposal when you ask it to improve something.</p>'; return; }
    host.innerHTML = list.map(function (p) {
      var pl = p.payload || {};
      var body = '';
      if (p.kind === 'rule')      body = '<code>' + escapeHtml(pl.key || '') + '</code> → <b>' + escapeHtml(String(pl.value == null ? '' : pl.value)) + '</b>';
      if (p.kind === 'knowledge') body = '<b>' + escapeHtml(pl.title || '') + '</b> <span class="muted">(' + escapeHtml(pl.scope || 'all') + ', priority ' + escapeHtml(String(pl.priority == null ? '' : pl.priority)) + ')</span><pre>' + escapeHtml(pl.body || '') + '</pre>';
      if (p.kind === 'prompt')    body = '<code>' + escapeHtml(pl.key || '') + '</code><pre>' + escapeHtml(pl.text || '') + '</pre>';
      return '<div class="prop' + (p.status !== 'pending' ? ' is-decided' : '') + '" data-id="' + p.id + '">'
        + '<div class="prop-h"><span class="prop-kind">' + escapeHtml(p.kind) + '</span>'
        + '<span class="muted tiny">' + escapeHtml(p.created_at) + ' · by ' + escapeHtml(p.created_by || 'ai') + '</span>'
        + '<span class="prop-status prop-' + escapeHtml(p.status) + '">' + escapeHtml(p.status) + '</span></div>'
        + '<div class="prop-body">' + body + '</div>'
        + '<div class="prop-why"><strong>Why:</strong> ' + escapeHtml(p.rationale) + '</div>'
        + (p.status === 'pending'
            ? '<div class="rules-foot"><button class="btn btn-primary btn-sm prop-ok">Approve &amp; apply</button>'
              + '<button class="btn btn-outline btn-sm prop-no">Reject</button></div>'
            : (p.note ? '<p class="muted tiny">Note: ' + escapeHtml(p.note) + '</p>' : ''))
        + '</div>';
    }).join('');
  }

  $('#propFilter') && $('#propFilter').addEventListener('change', loadProposals);
  $('#propList') && $('#propList').addEventListener('click', function (e) {
    var ok = e.target.closest('.prop-ok'), no = e.target.closest('.prop-no');
    if (!ok && !no) return;
    var row = (ok || no).closest('.prop');
    var id = parseInt(row.getAttribute('data-id'), 10) || 0;
    var payload = { id: id, decision: ok ? 'approve' : 'reject' };
    if (no) {
      var why = prompt('Why are you rejecting it? (optional — the AI is not told, this is for your record)');
      if (why === null) return;
      payload.note = why;
    }
    (ok || no).disabled = true;
    post('ai_proposal_decide', payload).then(function (r) {
      var d = r.data || {};
      if (d.ok) { toast(ok ? 'Applied.' : 'Rejected.'); setPropBadge(d.pending || 0); loadProposals(); if (ok) loadRules(); }
      else { toast(d.error || 'Could not do that.'); (ok || no).disabled = false; }
    });
  });

  function sourceBadge(src) {
    var label = { studio: 'set here', config: 'from config/env', 'default': 'default' }[src] || src;
    return '<span class="rule-src rule-src-' + escapeHtml(src) + '">' + escapeHtml(label) + '</span>';
  }

  function ruleField(r) {
    var id = 'rule_' + r.key.replace(/\./g, '_');
    var input;
    if (r.type === 'bool') {
      input = '<input type="checkbox" id="' + id + '" data-key="' + escapeHtml(r.key) + '"' + (r.value ? ' checked' : '') + ' />';
    } else if (r.type === 'enum') {
      input = '<select id="' + id + '" data-key="' + escapeHtml(r.key) + '">' +
        (r.options || []).map(function (o) {
          return '<option value="' + escapeHtml(o) + '"' + (String(o) === String(r.value) ? ' selected' : '') + '>' + escapeHtml(o) + '</option>';
        }).join('') + '</select>';
    } else if (r.type === 'int') {
      input = '<input type="number" id="' + id + '" data-key="' + escapeHtml(r.key) + '" value="' + escapeHtml(r.value) + '"' +
        (r.min !== null && r.min !== undefined ? ' min="' + escapeHtml(r.min) + '"' : '') +
        (r.max !== null && r.max !== undefined ? ' max="' + escapeHtml(r.max) + '"' : '') + ' step="1" />';
    } else {
      input = '<input type="text" id="' + id + '" data-key="' + escapeHtml(r.key) + '" value="' + escapeHtml(r.value) + '" />';
    }
    var meta = r.source === 'studio' && r.updated_by
      ? '<span class="rule-by">by ' + escapeHtml(r.updated_by) + '</span>' : '';
    // Be honest about a rule nothing reads yet, and about a stored value that
      // failed validation and is therefore NOT the value in force.
    var pending = r.pending
      ? '<span class="rule-pending" title="Stored now, enforced when this ships">awaiting ' + escapeHtml(r.pending) + '</span>' : '';
    var stale = r.stale
      ? '<p class="rule-stale">A saved value (<code>' + escapeHtml(r.stale) + '</code>) is no longer valid and is being ignored. ' +
        escapeHtml(r.expected || '') + ' Save a new value or reset to clear it.</p>' : '';
    return '<div class="rule-row' + (r.type === 'bool' ? ' rule-row-check' : '') + '">' +
      '<div class="rule-label"><label for="' + id + '">' + escapeHtml(r.label) + '</label>' + sourceBadge(r.source) + pending + meta +
      '<p class="rule-help">' + escapeHtml(r.help || '') + '</p>' + stale + '</div>' +
      '<div class="rule-input">' + input +
      '<button type="button" class="btn btn-ghost btn-xs rule-reset" data-key="' + escapeHtml(r.key) + '" title="Back to the default">Default</button>' +
      '</div></div>';
  }

  function renderRules(d) {
    rulesState.groups = d.groups || {};
    rulesState.dirty = {};
    var wrap = $('#rulesGroups');
    var html = '';
    Object.keys(rulesState.groups).forEach(function (g) {
      html += '<section class="rule-group"><h3>' + escapeHtml(g) + '</h3>' +
        rulesState.groups[g].map(ruleField).join('') + '</section>';
    });
    wrap.innerHTML = html || '<p class="muted">No rules registered.</p>';

    var warn = $('#rulesConflicts');
    if ((d.conflicts || []).length) {
      warn.hidden = false;
      warn.innerHTML = '<strong>These rules contradict each other:</strong><ul>' +
        d.conflicts.map(function (c) { return '<li>' + escapeHtml(c) + '</li>'; }).join('') + '</ul>';
    } else { warn.hidden = true; warn.innerHTML = ''; }
    $('#rulesVer').textContent = 'v' + (d.version || '0');

    wrap.querySelectorAll('.rule-reset').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('Reset this rule to its built-in default?')) return;
        post('rules_reset', { key: b.getAttribute('data-key') }).then(function (r) {
          if (!r.data.ok) return toast(r.data.error || 'Could not reset.');
          renderRules(r.data); toast('Reset to default.');
        });
      });
    });
    wrap.querySelectorAll('[data-key]').forEach(function (el) {
      el.addEventListener('change', function () {
        rulesState.dirty[el.getAttribute('data-key')] = (el.type === 'checkbox') ? (el.checked ? '1' : '0') : el.value;
      });
    });
  }

  function loadRules() {
    api('rules_get').then(function (r) {
      if (!r.data.ok) return toast(r.data.error || 'Could not load the rules.');
      renderRules(r.data);
    });
  }

  $('#rulesRefresh').addEventListener('click', function () { loadRules(); });
  $('#rulesSave').addEventListener('click', function () {
    var keys = Object.keys(rulesState.dirty);
    if (!keys.length) return toast('Nothing changed.');
    post('rules_save', { rules: rulesState.dirty }).then(function (r) {
      if (!r.data.ok) {
        // Field-level rejections are the useful half — show them, not just a code.
        var errs = r.data.errors || {};
        var first = Object.keys(errs)[0];
        return toast(first ? (first + ': ' + errs[first]) : (r.data.error || 'Could not save.'));
      }
      renderRules(r.data);
      toast('Saved ' + keys.length + ' rule(s).');
    });
  });
  $('#rulesResetAll').addEventListener('click', function () {
    if (!confirm('Reset EVERY rule to its built-in default? Escalation, promotion and health thresholds all go back to the shipped values.')) return;
    post('rules_reset', {}).then(function (r) {
      if (!r.data.ok) return toast(r.data.error || 'Could not reset.');
      renderRules(r.data); toast('All rules reset.');
    });
  });

  /* ---- Knowledge base ---- */
  function loadKb() {
    api('kb_list').then(function (r) {
      if (!r.data.ok) return toast(r.data.error || 'Could not load the knowledge base.');
      var sel = $('#kb_scope');
      if (sel && !sel.options.length) {
        sel.innerHTML = (r.data.scopes || []).map(function (s) { return '<option value="' + escapeHtml(s) + '">' + escapeHtml(s) + '</option>'; }).join('');
      }
      renderKb(r.data.entries || []);
    });
  }
  function renderKb(entries) {
    $('#kbList').innerHTML = entries.length ? entries.map(function (e) {
      return '<div class="kb-item' + (e.active ? '' : ' is-off') + '">' +
        '<div class="kb-item-main"><div class="kb-item-title">' + escapeHtml(e.title) + '</div>' +
        '<div class="kb-item-meta"><span class="badge">' + escapeHtml(e.scope) + '</span> priority ' + (e.priority | 0) +
        (e.active ? '' : ' · <em>inactive</em>') + (e.updated_by ? ' · ' + escapeHtml(e.updated_by) : '') + '</div>' +
        '<p class="kb-item-body">' + escapeHtml(e.body.length > 220 ? e.body.slice(0, 220) + '…' : e.body) + '</p></div>' +
        '<div class="kb-item-act"><button class="btn btn-outline btn-xs kb-ed" data-id="' + (e.id | 0) + '">Edit</button>' +
        '<button class="btn btn-ghost btn-xs kb-del" data-id="' + (e.id | 0) + '">Delete</button></div></div>';
    }).join('') : '<p class="muted">Nothing yet. Add what the assistants should know that the database cannot tell them.</p>';

    $('#kbList').querySelectorAll('.kb-ed').forEach(function (b) {
      b.addEventListener('click', function () {
        var e = entries.filter(function (x) { return x.id === +b.getAttribute('data-id'); })[0];
        if (e) kbEdit(e);
      });
    });
    $('#kbList').querySelectorAll('.kb-del').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('Delete this entry? Activity → Undo can restore it.')) return;
        post('kb_delete', { id: +b.getAttribute('data-id') }).then(function (r) {
          if (!r.data.ok) return toast(r.data.error || 'Could not delete.');
          renderKb(r.data.entries || []); toast('Deleted.');
        });
      });
    });
  }
  function kbEdit(e) {
    $('#kb_id').value = e ? e.id : 0;
    $('#kb_title').value = e ? e.title : '';
    $('#kb_body').value = e ? e.body : '';
    $('#kb_scope').value = e ? e.scope : 'all';
    $('#kb_priority').value = e ? e.priority : 50;
    $('#kb_active').checked = e ? !!e.active : true;
    $('#kbEdit').hidden = false;
  }
  $('#kbNew').addEventListener('click', function () { kbEdit(null); });
  $('#kbCancel').addEventListener('click', function () { $('#kbEdit').hidden = true; });
  $('#kbSave').addEventListener('click', function () {
    post('kb_save', {
      id: +$('#kb_id').value || 0,
      title: $('#kb_title').value,
      body: $('#kb_body').value,
      scope: $('#kb_scope').value,
      priority: +$('#kb_priority').value || 0,
      active: $('#kb_active').checked
    }).then(function (r) {
      if (!r.data.ok) return toast(r.data.error || 'Could not save.');
      $('#kbEdit').hidden = true;
      renderKb(r.data.entries || []); toast('Saved.');
    });
  });

  /* ---- Prompt templates ---- */
  function loadPrompts() {
    api('prompts_list').then(function (r) {
      if (!r.data.ok) return toast(r.data.error || 'Could not load the prompts.');
      renderPrompts(r.data.prompts || []);
    });
  }
  function renderPrompts(list) {
    $('#promptList').innerHTML = list.map(function (p) {
      var id = 'pr_' + p.key.replace(/\./g, '_');
      var vars = (p.vars || []).length
        ? '<p class="rule-help">Must keep these placeholders: ' + p.vars.map(function (v) { return '<code>{{' + escapeHtml(v) + '}}</code>'; }).join(', ') + '</p>'
        : '';
      var appends = [];
      if (p.appends_rules) appends.push('the live rules');
      if (p.scope) appends.push('knowledge scoped “' + p.scope + '”');
      var pending = p.pending
        ? '<span class="rule-pending" title="Nothing calls this template yet">awaiting ' + escapeHtml(p.pending) + '</span>' : '';
      return '<section class="prompt-item' + (p.pending ? ' is-pending' : '') + '"><div class="rule-label"><label for="' + id + '">' + escapeHtml(p.label) + '</label>' +
        sourceBadge(p.source) + pending + (p.updated_by ? '<span class="rule-by">by ' + escapeHtml(p.updated_by) + '</span>' : '') +
        (appends.length ? '<p class="rule-help">' + escapeHtml(appends.join(' and ')) + ' are appended automatically.</p>' : '') + vars + '</div>' +
        '<textarea id="' + id + '" rows="10" data-key="' + escapeHtml(p.key) + '">' + escapeHtml(p.text) + '</textarea>' +
        '<div class="rules-foot"><button class="btn btn-primary btn-xs pr-save" data-key="' + escapeHtml(p.key) + '">Save</button>' +
        '<button class="btn btn-outline btn-xs pr-reset" data-key="' + escapeHtml(p.key) + '">Revert to built-in</button></div></section>';
    }).join('');

    $('#promptList').querySelectorAll('.pr-save').forEach(function (b) {
      b.addEventListener('click', function () {
        var key = b.getAttribute('data-key');
        var ta = $('#promptList').querySelector('textarea[data-key="' + key + '"]');
        post('prompts_save', { key: key, text: ta ? ta.value : '' }).then(function (r) {
          if (!r.data.ok) return toast(r.data.error || 'Could not save.');
          renderPrompts(r.data.prompts || []);
          toast(r.data.cleared ? 'Matched the built-in prompt — reverted.' : 'Prompt saved.');
        });
      });
    });
    $('#promptList').querySelectorAll('.pr-reset').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('Revert this prompt to the built-in version?')) return;
        post('prompts_reset', { key: b.getAttribute('data-key') }).then(function (r) {
          if (!r.data.ok) return toast(r.data.error || 'Could not reset.');
          renderPrompts(r.data.prompts || []); toast('Reverted.');
        });
      });
    });
  }

  /* ---- Database (superadmin · SQLite → MySQL/Postgres migration) ---- */
  function loadDatabase() {
    var box = $('#dbStatus'); if (!box) return;
    box.innerHTML = '<p class="muted">Loading…</p>';
    api('db_status').then(function (r) {
      var d = r.data || {};
      if (!d.ok) { box.innerHTML = '<p class="muted">Could not load.</p>'; return; }
      var rows = Object.keys(d.tables || {}).map(function (t) {
        return '<tr><td>' + escapeHtml(t) + '</td><td class="db-n">' + (d.tables[t] | 0) + '</td></tr>';
      }).join('');
      var driverLabel = { sqlite: 'SQLite (bundled file)', mysql: 'MySQL / MariaDB', pgsql: 'PostgreSQL' }[d.driver] || d.driver;
      box.innerHTML =
        '<p class="db-cur"><span class="db-badge ' + (d.is_sqlite ? 'is-sqlite' : 'is-server') + '">' + escapeHtml(driverLabel) + '</span>'
        + (d.db ? ' <span class="muted">· ' + escapeHtml(d.db) + '</span>' : '') + '</p>'
        + (d.is_sqlite ? '<p class="muted tiny">You’re on the portable SQLite file. Migrate below to run on a managed server database.</p>'
                       : '<p class="muted tiny">Already running on a server database. You can still re-copy from SQLite if needed.</p>')
        + '<table class="db-table"><thead><tr><th>Table</th><th class="db-n">Rows</th></tr></thead><tbody>' + rows
        + '</tbody><tfoot><tr><td>Total</td><td class="db-n">' + (d.total_rows | 0) + '</td></tr></tfoot></table>';
    }).catch(function () { box.innerHTML = '<p class="muted">Could not load.</p>'; });
  }
  (function wireDatabase() {
    var view = $('#databaseView'); if (!view) return;
    var msg = $('#dbMsg');
    function setMsg(t, kind) { msg.textContent = t || ''; msg.style.color = kind === 'err' ? '#d22' : (kind === 'ok' ? '#2ea043' : ''); }
    function params() {
      return { driver: $('#db_driver').value, host: $('#db_host').value.trim(), port: $('#db_port').value.trim(),
        name: $('#db_name').value.trim(), user: $('#db_user').value.trim(), pass: $('#db_pass').value,
        apply_schema: $('#db_apply').checked, truncate: $('#db_truncate').checked };
    }
    // Default port follows the chosen driver unless the user typed one.
    $('#db_driver').addEventListener('change', function () {
      var p = $('#db_port'); if (!p.value || p.value === '3306' || p.value === '5432') p.value = this.value === 'pgsql' ? '5432' : '3306';
    });
    function busy(on) { ['dbTestBtn', 'dbDryBtn', 'dbMigrateBtn'].forEach(function (id) { $('#' + id).disabled = on; }); }
    function renderResult(d) {
      var wrap = $('#dbResult'); wrap.hidden = false;
      var rep = d.report || {};
      var rows = Object.keys(rep).map(function (t) {
        var r = rep[t]; var ok = r.ok;
        return '<tr><td>' + escapeHtml(t) + '</td><td class="db-n">' + (r.source | 0) + '</td><td class="db-n">' + (r.copied | 0)
          + '</td><td>' + (ok ? '<span class="db-ok">✓</span>' : '<span class="db-bad">✗</span>') + '</td></tr>';
      }).join('');
      $('#dbReport').innerHTML =
        '<p class="' + (d.ok ? 'db-note-ok' : 'db-note-bad') + '">' + escapeHtml(d.note || '') + '</p>'
        + (d.server ? '<p class="muted tiny">Target server: ' + escapeHtml(d.server) + '</p>' : '')
        + '<table class="db-table"><thead><tr><th>Table</th><th class="db-n">Source</th><th class="db-n">Copied</th><th></th></tr></thead><tbody>'
        + rows + '</tbody><tfoot><tr><td>' + (d.tables | 0) + ' tables</td><td></td><td class="db-n">' + (d.rows | 0) + '</td><td></td></tr></tfoot></table>';
      var envWrap = $('#dbEnvWrap');
      if (d.env && !d.dry_run && d.ok) { envWrap.hidden = false; $('#dbEnv').textContent = d.env; }
      else envWrap.hidden = true;
      $('#dbLog').textContent = d.log || '(no log)';
      wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function run(action, confirmMsg) {
      var p = params();
      if (!p.name) { setMsg('Enter the target database name.', 'err'); return; }
      if (confirmMsg && !confirm(confirmMsg)) return;
      busy(true); setMsg(action === 'db_test' ? 'Connecting…' : (p.dry_run ? 'Checking…' : 'Migrating… this can take a moment.'), '');
      post(action, p).then(function (r) {
        var d = r.data || {};
        if (action === 'db_test') {
          setMsg(d.ok ? ('Connected ✓ ' + (d.server ? '· ' + d.server : '')) : (d.error || 'Connection failed.'), d.ok ? 'ok' : 'err');
        } else {
          setMsg(d.ok ? (d.dry_run ? 'Dry run OK — review below.' : 'Migration verified ✓') : (d.error || 'Migration failed — see below.'), d.ok ? 'ok' : 'err');
          renderResult(d);
          loadDatabase();
        }
      }).catch(function () { setMsg('Network error — try again.', 'err'); })
        .finally(function () { busy(false); });
    }
    $('#dbRefresh').addEventListener('click', loadDatabase);
    $('#dbTestBtn').addEventListener('click', function () { run('db_test'); });
    $('#dbDryBtn').addEventListener('click', function () {
      var pp = params(); pp.dry_run = true;
      if (!pp.name) { setMsg('Enter the target database name.', 'err'); return; }
      busy(true); setMsg('Checking…', '');
      post('db_migrate', pp).then(function (r) {
        var d = r.data || {};
        setMsg(d.ok ? 'Dry run OK — review below.' : (d.error || 'Dry run failed — see below.'), d.ok ? 'ok' : 'err');
        renderResult(d);
      }).catch(function () { setMsg('Network error — try again.', 'err'); }).finally(function () { busy(false); });
    });
    $('#dbMigrateBtn').addEventListener('click', function () {
      var pp = params(); pp.dry_run = false;
      if (!pp.name) { setMsg('Enter the target database name.', 'err'); return; }
      if (!confirm('Copy all data into ' + pp.driver.toUpperCase() + ' database “' + pp.name + '” on ' + (pp.host || 'localhost') + '?\n\nThis writes to the target. Make sure it is the right, empty database.')) return;
      busy(true); setMsg('Migrating… this can take a moment.', '');
      post('db_migrate', pp).then(function (r) {
        var d = r.data || {};
        setMsg(d.ok ? 'Migration verified ✓' : (d.error || 'Migration failed — see below.'), d.ok ? 'ok' : 'err');
        renderResult(d); loadDatabase();
      }).catch(function () { setMsg('Network error — try again.', 'err'); }).finally(function () { busy(false); });
    });
  })();

  /* ---- Design studio (superadmin · brand accent) ---- */
  function renderBrandPreview() {
    var box = $('#brandPreview'); if (!box) return;
    var a = $('#br_accent').value, d = $('#br_deep').value;
    box.innerHTML =
      '<div class="brand-pv-swatches"><span style="background:' + a + '">Accent<br>' + escapeHtml(a) + '</span>'
      + '<span style="background:' + d + ';color:#fff">Deep<br>' + escapeHtml(d) + '</span></div>'
      + '<div class="brand-pv-demo">'
      + '<button class="bpv-btn" style="background:' + a + '">Primary button</button>'
      + '<span class="bpv-badge" style="background:' + a + '">Badge</span>'
      + '<span class="bpv-avatar" style="background:linear-gradient(135deg,' + a + ',' + d + ')">A</span>'
      + '<a class="bpv-link" style="color:' + d + '" href="#" onclick="return false">A sample link →</a>'
      + '</div>';
  }
  function loadDesign() {
    api('brand_get').then(function (r) {
      var d = r.data || {}; var b = d.brand || d.defaults || {};
      $('#br_accent').value = /^#[0-9a-f]{6}$/i.test(b.accent) ? b.accent : '#f3b416';
      $('#br_deep').value = /^#[0-9a-f]{6}$/i.test(b.accent_deep) ? b.accent_deep : '#b07e08';
      renderBrandPreview();
    }).catch(renderBrandPreview);
  }
  (function wireDesign() {
    var v = $('#designView'); if (!v) return;
    $('#br_accent').addEventListener('input', renderBrandPreview);
    $('#br_deep').addEventListener('input', renderBrandPreview);
    $('#brandSave').addEventListener('click', function () {
      var msg = $('#brandMsg'), self = this; self.disabled = true; msg.textContent = 'Saving…'; msg.style.color = '';
      post('brand_save', { accent: $('#br_accent').value, accent_deep: $('#br_deep').value }).then(function (r) {
        var d = r.data || {}; msg.style.color = d.ok ? '#2ea043' : '#d22';
        msg.textContent = d.ok ? 'Saved — reload any page to see the new brand.' : (d.error || 'Could not save.');
      }).catch(function () { msg.style.color = '#d22'; msg.textContent = 'Network error.'; })
        .finally(function () { self.disabled = false; });
    });
    $('#brandReset').addEventListener('click', function () {
      if (!confirm('Reset the brand back to the default Afrovanguard gold?')) return;
      var msg = $('#brandMsg');
      post('brand_save', { reset: true }).then(function (r) {
        if (r.data && r.data.ok) { $('#br_accent').value = '#f3b416'; $('#br_deep').value = '#b07e08'; renderBrandPreview(); msg.style.color = '#2ea043'; msg.textContent = 'Reset to default gold — reload to see it.'; }
        else { msg.style.color = '#d22'; msg.textContent = (r.data && r.data.error) || 'Could not reset.'; }
      });
    });
  })();

  /* ---- System / Health ---- */
  function loadSystem() {
    var box = $('#sysHealth'); box.innerHTML = '<p class="muted">Checking…</p>';
    var dot = { ok: '#2ea043', warn: '#e0a106', off: '#d22', info: '#5b6472' };
    api('sys_health').then(function (r) {
      if (!r.data || !r.data.ok) { box.innerHTML = '<p class="muted">Could not load.</p>'; return; }
      box.innerHTML = (r.data.groups || []).map(function (g) {
        return '<div style="margin:0 0 22px"><h2 style="font-family:var(--font-heading);font-size:20px;margin:0 0 8px">' + escapeHtml(g.group) + '</h2>' +
          (g.checks || []).map(function (c) {
            return '<div style="display:flex;align-items:center;gap:10px;padding:7px 2px;border-bottom:1px solid rgba(128,128,128,.15)">' +
              '<span style="width:10px;height:10px;border-radius:50%;flex:0 0 auto;background:' + (dot[c.state] || '#5b6472') + '"></span>' +
              '<span style="font-weight:600;flex:0 0 230px">' + escapeHtml(c.label) + '</span>' +
              '<span style="color:#5b6472;font-size:13px">' + escapeHtml(c.detail || '') + '</span></div>';
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
      + '<select class="mem-level" data-id="' + m.id + '" title="Membership level (progression)">'
      + ['O', 'A', 'B', 'C'].map(function (L) { return '<option value="' + L + '"' + ((m.level || 'O') === L ? ' selected' : '') + '>Level ' + L + '</option>'; }).join('')
      + '</select>'
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
      var roleSel = e.target.closest('.mem-role');
      if (roleSel) {
        post('mem_save', { id: roleSel.getAttribute('data-id'), role: roleSel.value }).then(function (r) {
          if (r.data && r.data.ok) { toast('Access level updated.'); loadMembers(); } else toast((r.data && r.data.error) || 'Could not update.');
        });
        return;
      }
      var lvlSel = e.target.closest('.mem-level');
      if (lvlSel) {
        post('mem_save', { id: lvlSel.getAttribute('data-id'), level: lvlSel.value }).then(function (r) {
          if (r.data && r.data.ok) { toast('Membership level updated.'); loadMembers(); } else toast((r.data && r.data.error) || 'Could not update.');
        });
      }
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

  function boot() {
    applyRoleVisibility();
    var saved = 'overview';
    try { saved = localStorage.getItem('av.studio.tab') || 'overview'; } catch (e) {}
    if (!document.querySelector('.tab[data-tab="' + saved + '"]') || !roleAllows(saved)) saved = 'overview';
    activateTab(saved);
    refreshModBadge();
  }
  api('session').then(function (r) { if (r.data && r.data.ok) { csrf = r.data.csrf; cloudinary = !!r.data.cloudinary; currentRole = r.data.role || 'superadmin'; boot(); } else show('login'); }).catch(function () { show('login'); });
})();
