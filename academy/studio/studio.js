/* ============================================================
   academy/studio/studio.js — Academy admin console.
   Talks to /admin/api.php (ADMIN_TOKEN cookie + CSRF).
   ============================================================ */
(function () {
  'use strict';
  var API = '/admin/api.php';
  var csrf = '';
  var courses = [];
  var editingSlug = null;     // course being edited (null = new)
  var curCourseSlug = '';     // course whose curriculum is open

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(msg, kind) {
    var t = document.createElement('div'); t.className = 'toast' + (kind ? ' ' + kind : '');
    t.textContent = msg; $('#toasts').appendChild(t);
    setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 300); }, 2600);
  }

  function api(action, opts) {
    opts = opts || {};
    var headers = opts.headers || {};
    if (opts.method === 'POST') headers['X-CSRF-Token'] = csrf;
    return fetch(API + '?action=' + action + (opts.qs || ''), {
      method: opts.method || 'GET', headers: headers, body: opts.body, credentials: 'same-origin', cache: 'no-store'
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Bad server response.' }; }); });
  }
  function get(action, qs) { return api(action, { qs: qs ? '&' + qs : '' }); }
  /* Upload one file through /admin/api.php?action=upload; resolves to its URL. */
  function uploadFile(accept) {
    return new Promise(function (resolve, reject) {
      var inp = document.createElement('input'); inp.type = 'file'; if (accept) inp.accept = accept;
      inp.onchange = function () {
        var f = inp.files && inp.files[0]; if (!f) return reject(new Error('No file'));
        var fd = new FormData(); fd.append('file', f);
        var msg = $('#uploadMsg'); if (msg) msg.textContent = 'Uploading ' + f.name + '…';
        api('upload', { method: 'POST', body: fd }).then(function (d) {
          if (msg) msg.textContent = d && d.ok ? 'Uploaded ' + f.name + '.' : ((d && d.error) || 'Upload failed.');
          if (d && d.ok) resolve(d.url); else reject(new Error((d && d.error) || 'Upload failed'));
        }).catch(function (e) { if (msg) msg.textContent = 'Network error.'; reject(e); });
      };
      inp.click();
    });
  }
  function post(action, payload) { return api(action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload || {}) }); }

  /* ── Auth ── */
  function showApp(on) { $('#app').hidden = !on; $('#login').hidden = on; }
  $('#loginForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = e.target.querySelector('button'); btn.disabled = true;
    post('login', { token: $('#token').value }).then(function (d) {
      btn.disabled = false;
      if (d && d.ok) { csrf = d.csrf || ''; showApp(true); startRoute(); }
      else $('#loginMsg').textContent = (d && d.error) || 'Could not sign in.';
    }).catch(function () { btn.disabled = false; $('#loginMsg').textContent = 'Network error.'; });
  });
  $('#logout').addEventListener('click', function () {
    post('logout', {}).finally(function () { csrf = ''; showApp(false); });
  });

  /* ── Theme + mobile nav ── */
  $('#themeToggle').addEventListener('click', function () {
    var d = document.documentElement, next = d.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    d.setAttribute('data-theme', next); try { localStorage.setItem('av.theme', next); } catch (e) {}
  });
  $('#hamburger').addEventListener('click', function () { $('#app').classList.toggle('nav-open'); });

  /* ── Routing ── */
  var VIEWS = {
    overview:     { title: 'Overview',     sub: 'Your Academy at a glance', actions: '' },
    courses:      { title: 'Courses',      sub: 'Create and manage programmes', actions: '<button class="btn btn-primary btn-sm" id="newCourse">+ New course</button>' },
    course:       { title: 'Edit course',  sub: '', actions: '<button class="btn btn-ghost btn-sm" id="courseBack">← Courses</button><button class="btn btn-primary btn-sm" id="courseSave">Save course</button>' },
    programmes:   { title: 'Programmes',   sub: 'Series of courses with a programme certificate', actions: '<button class="btn btn-primary btn-sm" id="newProg">+ New programme</button>' },
    programme:    { title: 'Edit programme', sub: '', actions: '<button class="btn btn-ghost btn-sm" id="progBack">← Programmes</button><button class="btn btn-primary btn-sm" id="progSave">Save programme</button>' },
    curriculum:   { title: 'Curriculum',   sub: 'Modules, lessons, quizzes & assignments', actions: '' },
    learners:     { title: 'Learners',     sub: 'Progress & certificates', actions: '' },
    applications: { title: 'Applications', sub: 'Lead-capture sign-ups', actions: '' },
    activity:     { title: 'Activity log', sub: 'Audit trail — who changed what, and from where', actions: '<button class="btn btn-outline btn-sm" id="auditRefresh">Refresh</button>' }
  };
  function route(name, arg) {
    var v = VIEWS[name] || VIEWS.overview;
    $$('.view').forEach(function (el) { el.hidden = el.id !== 'view-' + name; });
    $$('.nav-item').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-view') === (name === 'course' ? 'courses' : (name === 'programme' ? 'programmes' : name))); });
    $('#viewTitle').textContent = v.title; $('#viewSub').textContent = v.sub;
    $('#topActions').innerHTML = v.actions;
    $('#app').classList.remove('nav-open');
    if (name === 'overview') loadOverview();
    if (name === 'courses') { loadCourses(); var nb = $('#newCourse'); if (nb) nb.onclick = function () { openCourse(null); }; }
    if (name === 'course') { $('#courseBack').onclick = function () { route('courses'); }; $('#courseSave').onclick = saveCourse; }
    if (name === 'programmes') { loadProgrammes(); var np = $('#newProg'); if (np) np.onclick = function () { openProgramme(null); }; }
    if (name === 'programme') { $('#progBack').onclick = function () { route('programmes'); }; $('#progSave').onclick = saveProgramme; }
    if (name === 'curriculum') loadCurriculum(arg);
    if (name === 'learners') loadLearners(arg);
    if (name === 'applications') loadApplications();
    if (name === 'activity') { loadActivity(); var rb = $('#auditRefresh'); if (rb) rb.onclick = loadActivity; }
  }
  $$('.nav-item').forEach(function (b) { b.addEventListener('click', function () { route(b.getAttribute('data-view')); }); });
  $$('.qbtn').forEach(function (b) { b.addEventListener('click', function () { var go = b.getAttribute('data-go'); if (b.getAttribute('data-new')) openCourse(null); else route(go); }); });

  /* ── Overview ── */
  function loadOverview() {
    get('ac_overview').then(function (d) {
      if (!d || !d.ok) return;
      var s = d.stats, cards = [
        ['Courses', s.courses_total, s.courses_published + ' published · ' + s.courses_draft + ' draft'],
        ['Lessons', s.lessons, s.modules + ' modules'],
        ['Enrolments', s.enrolments, 'active learners'],
        ['Certificates', s.certificates, 'issued'],
        ['Applications', s.applications, 'lead sign-ups'],
        ['Paying dues', s.members, 'dues live now — not the same as members']
      ];
      $('#statGrid').innerHTML = cards.map(function (c) {
        return '<div class="stat"><div class="stat-num">' + esc(c[1]) + '</div><div class="stat-label">' + esc(c[0]) + '</div><div class="stat-sub">' + esc(c[2]) + '</div></div>';
      }).join('');
    });
    var pd = $('#purgeDemo');
    if (pd) pd.onclick = function () {
      if (!confirm('Remove all shipped demo/sample content (sample Diary articles + placeholder lessons)? Real content and the starter curriculum are kept. This is logged.')) return;
      post('purge_demo', {}).then(function (d) {
        if (d && d.ok) { var r = d.removed || {}; toast('Removed ' + (r.articles || 0) + ' articles, ' + (r.lessons || 0) + ' lessons, ' + (r.modules || 0) + ' modules', 'ok'); loadOverview(); }
        else toast((d && d.error) || 'Purge failed', 'err');
      }).catch(function () { toast('Network error', 'err'); });
    };
  }

  /* ── Courses ── */
  function accessLabel(a) { return { open: 'Open', tracked: 'Tracked', membership: 'Membership', paid: 'Paid' }[a] || a || 'open'; }
  function loadCourses() {
    var body = $('#coursesBody'); body.innerHTML = '<tr><td colspan="6" class="empty">Loading…</td></tr>';
    get('ac_list').then(function (d) {
      courses = (d && d.courses) || [];
      if (!courses.length) { body.innerHTML = '<tr><td colspan="6" class="empty">No courses yet. Create your first one.</td></tr>'; return; }
      body.innerHTML = courses.map(function (c) {
        var st = c.status === 'published' ? 'published' : 'draft';
        return '<tr>'
          + '<td><div class="c-title">' + esc(c.title) + '</div><div class="c-meta">/academy/' + esc(c.slug) + '/</div></td>'
          + '<td><span class="badge ' + st + '">' + (st === 'published' ? 'Published' : 'Draft') + '</span></td>'
          + '<td><span class="badge access">' + esc(accessLabel(c.access_type)) + (c.access_type === 'paid' && c.price_ngn ? ' ₦' + Number(c.price_ngn).toLocaleString() : '') + '</span></td>'
          + '<td class="num"><button class="btn btn-ghost btn-sm" data-curr="' + esc(c.slug) + '">Build →</button></td>'
          + '<td class="num">' + esc(c.sort || 0) + '</td>'
          + '<td><div class="row-actions">'
          + '<button class="btn btn-outline btn-sm" data-edit="' + esc(c.slug) + '">Edit</button>'
          + '<button class="btn btn-ghost btn-sm" data-toggle="' + esc(c.slug) + '" data-status="' + esc(c.status) + '">' + (c.status === 'published' ? 'Unpublish' : 'Publish') + '</button>'
          + '<button class="btn btn-ghost btn-sm" data-dup="' + esc(c.slug) + '">Duplicate</button>'
          + '<a class="btn btn-ghost btn-sm" href="/academy/' + esc(c.slug) + '/" target="_blank" rel="noopener">View</a>'
          + '<button class="btn btn-ghost btn-sm" data-del="' + esc(c.slug) + '">Delete</button>'
          + '</div></td></tr>';
      }).join('');
      $$('[data-edit]', body).forEach(function (b) { b.onclick = function () { openCourse(b.getAttribute('data-edit')); }; });
      $$('[data-curr]', body).forEach(function (b) { b.onclick = function () { route('curriculum', b.getAttribute('data-curr')); }; });
      $$('[data-del]', body).forEach(function (b) { b.onclick = function () { deleteCourse(b.getAttribute('data-del')); }; });
      $$('[data-toggle]', body).forEach(function (b) { b.onclick = function () {
        var ns = b.getAttribute('data-status') === 'published' ? 'draft' : 'published';
        post('ac_status', { slug: b.getAttribute('data-toggle'), status: ns }).then(function (d) {
          if (d && d.ok) { toast(ns === 'published' ? 'Published' : 'Unpublished (hidden from the catalogue)', 'ok'); loadCourses(); } else toast((d && d.error) || 'Failed', 'err');
        });
      }; });
      $$('[data-dup]', body).forEach(function (b) { b.onclick = function () {
        post('ac_duplicate', { slug: b.getAttribute('data-dup') }).then(function (d) {
          if (d && d.ok) { toast('Duplicated → ' + d.slug + ' (draft)', 'ok'); loadCourses(); } else toast((d && d.error) || 'Failed', 'err');
        });
      }; });
    });
  }
  function deleteCourse(slug) {
    if (!confirm('Delete “' + slug + '” and its curriculum? Consider Unpublish instead to retire it safely.')) return;
    post('ac_delete', { slug: slug }).then(function (d) {
      if (d && d.ok) { toast('Course deleted', 'ok'); loadCourses(); return; }
      if (d && d.needs_confirm) {
        if (confirm((d.error || 'This course has learner data.') + '\n\nForce-delete and permanently destroy that data?')) {
          post('ac_delete', { slug: slug, force: true }).then(function (d2) {
            if (d2 && d2.ok) { toast('Course force-deleted', 'ok'); loadCourses(); } else toast((d2 && d2.error) || 'Failed', 'err');
          });
        }
        return;
      }
      toast((d && d.error) || 'Delete failed', 'err');
    });
  }

  var CF = { title: 'c_title', slug: 'c_slug', summary: 'c_summary', body_html: 'c_body', outcomes: 'c_outcomes',
    category: 'c_category', level: 'c_level', format: 'c_format', duration: 'c_duration', price: 'c_pricelabel',
    cover_url: 'c_cover', cta_url: 'c_cta', sort: 'c_sort', price_ngn: 'c_price_ngn', instructor_email: 'c_instructor',
    language: 'c_language', skills: 'c_skills', prerequisites: 'c_prereqs', pass_mark: 'c_pass_mark', pace_weeks: 'c_pace_weeks' };
  function toggledPrice() { $('#priceWrap').hidden = $('#c_access').value !== 'paid'; }
  $('#c_access').addEventListener('change', toggledPrice);
  /* The cover: an upload, shown as a picture, never as a web address. */
  function showCover() {
    var u = $('#c_cover').value, pv = $('#c_coverPrev');
    pv.style.backgroundImage = u ? 'url("' + u.replace(/["\\]/g, '') + '")' : '';
    pv.classList.toggle('has', !!u); $('#c_coverClear').hidden = !u;
  }
  $('#c_coverClear').addEventListener('click', function () { $('#c_cover').value = ''; showCover(); });
  new MutationObserver(showCover).observe($('#c_cover'), { attributes: true });
  function openCourse(slug) {
    editingSlug = slug;
    $('#courseNote').textContent = '';
    var blank = { status: 'draft', access_type: 'open', gradient: 'g-gold', sort: 0, price_ngn: 0, pass_mark: 70, pace_weeks: 0, language: 'English' };
    function fill(c) {
      Object.keys(CF).forEach(function (k) { var el = $('#' + CF[k]); if (el) el.value = c[k] != null ? c[k] : ''; });
      $('#c_status').value = c.status === 'published' ? 'published' : 'draft';
      $('#c_access').value = c.access_type || 'open';
      $('#c_gradient').value = c.gradient || 'g-gold';
      $('#c_featured').checked = !!(c.featured && c.featured != '0');
      $('#c_sequential').checked = !!(c.sequential && c.sequential != '0');
      $('#c_instructor').value = c.instructor_email || '';
      if (c.level && !$$('#c_level option').some(function (o) { return o.value === c.level; })) { var o = document.createElement('option'); o.textContent = c.level; $('#c_level').appendChild(o); }
      $('#c_level').value = c.level || 'All levels';
      showCover();
      richInit('c_body', c.body_html || '');
      get('ac_categories').then(function (r) { $('#c_categories').innerHTML = ((r && r.categories) || []).map(function (x) { return '<option value="' + esc(x) + '">'; }).join(''); });
      toggledPrice();
      VIEWS.course.title = slug ? 'Edit course' : 'New course';
      route('course');
    }
    if (!slug) { fill(blank); return; }
    get('ac_get', 'slug=' + encodeURIComponent(slug)).then(function (d) { fill((d && d.course) || blank); });
  }
  function saveCourse() {
    var payload = {};
    Object.keys(CF).forEach(function (k) { var el = $('#' + CF[k]); if (el) payload[k] = el.value; });
    payload.body_html = richGet('c_body');
    payload.status = $('#c_status').value; payload.access_type = $('#c_access').value;
    payload.gradient = $('#c_gradient').value; payload.featured = $('#c_featured').checked; payload.sequential = $('#c_sequential').checked ? 1 : 0;
    if (editingSlug && !payload.slug) payload.slug = editingSlug;
    if (!payload.title.trim()) { toast('A title is required', 'err'); return; }
    var btn = $('#courseSave'); if (btn) btn.disabled = true;
    post('ac_save', payload).then(function (d) {
      if (btn) btn.disabled = false;
      if (d && d.ok) { toast('Course saved', 'ok'); if (d.notice) $('#courseNote').textContent = d.notice; route('courses'); }
      else toast((d && d.error) || 'Save failed', 'err');
    }).catch(function () { if (btn) btn.disabled = false; toast('Network error', 'err'); });
  }

  /* ── Curriculum ── */
  function fillCoursePickers() {
    var opts = courses.map(function (c) { return '<option value="' + esc(c.slug) + '">' + esc(c.title) + '</option>'; }).join('');
    ['#curCourse', '#learnCourse'].forEach(function (sel) { var el = $(sel); if (el) el.innerHTML = opts; });
  }
  function ensureCourses() { return courses.length ? Promise.resolve() : get('ac_list').then(function (d) { courses = (d && d.courses) || []; }); }
  function loadCurriculum(slug) {
    ensureCourses().then(function () {
      fillCoursePickers();
      var sel = $('#curCourse');
      if (slug) sel.value = slug;
      sel.onchange = function () { renderCurriculum(sel.value); };
      renderCurriculum(sel.value || (courses[0] && courses[0].slug));
    });
  }
  // Lesson-type badge (mirrors the learner-side video/reading/quiz cue).
  function lesTypeBadge(t) {
    var m = { video: ['▶', 'Video', 'video'], quiz: ['✓', 'Graded quiz', 'quiz'], practice: ['✓', 'Practice quiz', 'quiz'], assignment: ['✎', 'Assignment', 'quiz'], discussion: ['❝', 'Discussion', 'reading'], reading: ['≡', 'Reading', 'reading'] };
    var x = m[t] || m.reading;
    return '<span class="les-type t-' + x[2] + '" title="' + x[1] + '">' + x[0] + ' ' + x[1] + '</span>';
  }
  /* ── Course outline editor ──
     Drag an item or a module by its handle to move it (an item may move to
     another module), or focus the handle and use Alt+↑ / Alt+↓. Every move
     saves the whole outline in one request (outline_save), so the order on
     screen is the order learners get. "+ Add item" asks what kind first. */
  var curMods = [];
  var KINDS = [
    { k: 'reading', label: 'Reading', hint: 'Text, images, embeds' },
    { k: 'video', label: 'Video', hint: 'Upload or paste a link' },
    { k: 'quiz', label: 'Quiz', hint: 'Graded or practice' },
    { k: 'assignment', label: 'Assignment', hint: 'Instructor-graded' },
    { k: 'discussion', label: 'Discussion', hint: 'Learners reply to a prompt' }
  ];
  function saveOutline(slug) {
    var payload = { course: slug, modules: curMods.map(function (m) { return { id: m.id, lessons: (m.lessons || []).map(function (l) { return l.id; }) }; }) };
    return post('outline_save', payload).then(function (d) {
      if (d && d.ok) { toast('Order saved', 'ok'); return true; }
      toast((d && d.error) || 'Could not save the order', 'err'); renderCurriculum(slug); return false;
    });
  }
  function findItem(id) {
    for (var i = 0; i < curMods.length; i++) { var ls = curMods[i].lessons || []; for (var j = 0; j < ls.length; j++) if (ls[j].id === id) return { mi: i, li: j }; }
    return null;
  }
  function renderCurriculum(slug) {
    curCourseSlug = slug;
    var wrap = $('#curriculum');
    if (!slug) { wrap.innerHTML = '<p class="empty">No courses yet.</p>'; return; }
    wrap.innerHTML = '<p class="empty">Loading…</p>';
    get('ac_curriculum', 'slug=' + encodeURIComponent(slug)).then(function (d) {
      if (!d || !d.ok) { wrap.innerHTML = '<p class="empty">' + esc((d && d.error) || 'Could not load.') + '</p>'; return; }
      curMods = (d.modules || []).map(function (m) { m.id = +m.id; (m.lessons || []).forEach(function (l) { l.id = +l.id; }); return m; });
      draw(slug);
      loadReadiness(slug);
    });
  }
  /* Is it ready for learners? Each line opens the thing to fix. */
  function loadReadiness(slug) {
    var box = $('#readiness');
    get('ac_readiness', 'slug=' + encodeURIComponent(slug)).then(function (d) {
      var items = (d && d.items) || [], fixes = items.filter(function (i) { return i.level === 'fix'; });
      box.hidden = false;
      box.className = 'ready ' + (fixes.length ? 'is-fix' : 'is-ok');
      box.innerHTML = '<h3>' + (fixes.length ? fixes.length + ' thing' + (fixes.length === 1 ? '' : 's') + ' to finish before learners arrive' : 'Ready for learners') + '</h3>'
        + (items.length ? '<ul>' + items.map(function (i, n) {
            return '<li class="r-' + esc(i.level) + '"><span class="r-dot" aria-hidden="true"></span>'
              + (i.lesson || i.module ? '<button type="button" class="link-btn" data-ri="' + n + '">' + esc(i.text) + '</button>' : esc(i.text)) + '</li>';
          }).join('') + '</ul>' : '');
      $$('[data-ri]', box).forEach(function (b) { b.onclick = function () {
        var i = items[+b.getAttribute('data-ri')];
        if (i.lesson) openLesson(i.lesson, null); else openModule(curMods.filter(function (m) { return m.id === i.module; })[0] || null);
      }; });
    });
  }
  function draw(slug, focusSel) {
    var wrap = $('#curriculum');
    var totalLessons = 0, totalMin = 0;
    curMods.forEach(function (m) { (m.lessons || []).forEach(function (l) { totalLessons++; totalMin += (+l.duration_min || 0); }); });
    $('#curHint').textContent = curMods.length + ' module' + (curMods.length === 1 ? '' : 's') + ' · ' + totalLessons + ' item' + (totalLessons === 1 ? '' : 's') + (totalMin ? ' · ' + totalMin + ' min' : '');
    var html = curMods.map(function (m, mi) {
      var items = (m.lessons || []).map(function (l) {
        var tags = (l.is_preview && l.is_preview != '0' ? '<span class="badge cert">preview</span>' : '')
          + (l.available_at ? '<span class="badge access">opens ' + esc(String(l.available_at).slice(0, 10)) + '</span>' : '')
          + ((+l.weight || 1) > 1 ? '<span class="badge access">weight ' + esc(l.weight) + '</span>' : '')
          + '<span class="badge access">' + esc(l.duration_min || 0) + ' min</span>';
        return '<li class="les" draggable="true" data-item="' + l.id + '">'
          + '<button type="button" class="drag" data-handle="item" data-id="' + l.id + '" aria-label="Move ' + esc(l.title) + ' (drag, or Alt+arrow keys)">⠿</button>'
          + lesTypeBadge(l.type)
          + '<button type="button" class="les-title les-open" data-led="' + l.id + '">' + esc(l.title) + '</button>'
          + '<span class="les-tags">' + tags + '</span>'
          + '<span class="row-actions"><button class="btn btn-ghost btn-sm" data-ldup="' + l.id + '" aria-label="Duplicate ' + esc(l.title) + '">Duplicate</button>'
          + '<button class="btn btn-ghost btn-sm" data-ldel="' + l.id + '" aria-label="Delete ' + esc(l.title) + '">✕</button></span></li>';
      }).join('');
      var objs = (m.objectives || '').split('\n').filter(Boolean).length;
      return '<section class="mod" data-mod="' + m.id + '" draggable="true">'
        + '<div class="mod-head"><button type="button" class="drag" data-handle="mod" data-id="' + m.id + '" aria-label="Move module ' + esc(m.title) + ' (drag, or Alt+arrow keys)">⠿</button>'
        + '<span class="mod-title"><span class="mod-k">Module ' + (mi + 1) + '</span>' + esc(m.title)
        + '<span class="muted mod-sum">' + (m.summary ? esc(m.summary) : '<em>No introduction yet — learners see it at the start of the module.</em>') + (objs ? ' · ' + objs + ' objective' + (objs === 1 ? '' : 's') : '') + '</span></span>'
        + '<button class="btn btn-outline btn-sm" data-mren="' + m.id + '">Edit introduction</button>'
        + '<button class="btn btn-ghost btn-sm" data-mdel="' + m.id + '">Delete</button></div>'
        + '<ul class="mod-lessons" data-drop="' + m.id + '">' + (items || '<li class="les-empty">No items yet — drop one here or add one.</li>') + '</ul>'
        + '<div class="mod-foot add-kinds" role="group" aria-label="Add an item to ' + esc(m.title) + '"><span class="muted">Add:</span>'
        + KINDS.map(function (k) { return '<button class="btn btn-outline btn-sm" data-ladd="' + m.id + '" data-kind="' + k.k + '" title="' + esc(k.hint) + '">+ ' + k.label + '</button>'; }).join('')
        + '</div></section>';
    }).join('');
    wrap.innerHTML = (html || '<p class="empty">Start with a module — a week or a topic — then add items to it.</p>')
      + '<button class="btn btn-primary btn-sm" id="addMod">+ Add module</button>';
    if (focusSel) { var f = $(focusSel, wrap); if (f) f.focus(); }

    $('#addMod').onclick = function () { openModule(null); };
    $$('[data-mren]', wrap).forEach(function (b) { b.onclick = function () { var id = +b.getAttribute('data-mren'); openModule(curMods.filter(function (x) { return x.id === id; })[0] || null); }; });
    $$('[data-mdel]', wrap).forEach(function (b) { b.onclick = function () { if (confirm('Delete this module and its items?')) post('mod_delete', { id: +b.getAttribute('data-mdel') }).then(function () { renderCurriculum(slug); }); }; });
    $$('[data-ladd]', wrap).forEach(function (b) { b.onclick = function () { openLesson(null, +b.getAttribute('data-ladd'), b.getAttribute('data-kind')); }; });
    $$('[data-led]', wrap).forEach(function (b) { b.onclick = function () { openLesson(+b.getAttribute('data-led'), null); }; });
    $$('[data-ldup]', wrap).forEach(function (b) { b.onclick = function () { post('lesson_duplicate', { id: +b.getAttribute('data-ldup') }).then(function (r) { if (r && r.ok) { toast('Duplicated', 'ok'); renderCurriculum(slug); } else toast((r && r.error) || 'Failed', 'err'); }); }; });
    $$('[data-ldel]', wrap).forEach(function (b) { b.onclick = function () { if (confirm('Delete this item?')) post('lesson_delete', { id: +b.getAttribute('data-ldel') }).then(function () { renderCurriculum(slug); }); }; });

    /* Keyboard: Alt+↑/↓ on a handle moves it; an item crosses into the next/previous module at the ends. */
    $$('[data-handle]', wrap).forEach(function (h) {
      h.addEventListener('keydown', function (e) {
        if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
        e.preventDefault();
        var dir = e.key === 'ArrowUp' ? -1 : 1, id = +h.getAttribute('data-id');
        if (h.getAttribute('data-handle') === 'mod') {
          var i = curMods.map(function (m) { return m.id; }).indexOf(id), j = i + dir;
          if (j < 0 || j >= curMods.length) return;
          var t = curMods[i]; curMods[i] = curMods[j]; curMods[j] = t;
        } else {
          var p = findItem(id); if (!p) return;
          var list = curMods[p.mi].lessons, it = list[p.li], nj = p.li + dir;
          if (nj >= 0 && nj < list.length) { list[p.li] = list[nj]; list[nj] = it; }
          else {
            var nm = p.mi + dir; if (nm < 0 || nm >= curMods.length) return;
            list.splice(p.li, 1); curMods[nm].lessons = curMods[nm].lessons || [];
            if (dir < 0) curMods[nm].lessons.push(it); else curMods[nm].lessons.unshift(it);
          }
        }
        draw(slug, '[data-handle="' + h.getAttribute('data-handle') + '"][data-id="' + id + '"]');
        saveOutline(slug);
      });
    });

    /* Drag and drop. */
    var dragging = null;
    $$('[data-item]', wrap).forEach(function (li) {
      li.addEventListener('dragstart', function (e) { dragging = { kind: 'item', id: +li.getAttribute('data-item') }; li.classList.add('is-drag'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'item'); e.stopPropagation(); });
      li.addEventListener('dragend', function () { li.classList.remove('is-drag'); });
    });
    $$('[data-mod]', wrap).forEach(function (sec) {
      sec.addEventListener('dragstart', function (e) { if (dragging) return; dragging = { kind: 'mod', id: +sec.getAttribute('data-mod') }; sec.classList.add('is-drag'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'mod'); });
      sec.addEventListener('dragend', function () { sec.classList.remove('is-drag'); dragging = null; });
      sec.addEventListener('dragover', function (e) { if (dragging) { e.preventDefault(); sec.classList.add('is-over'); } });
      sec.addEventListener('dragleave', function () { sec.classList.remove('is-over'); });
      sec.addEventListener('drop', function (e) {
        e.preventDefault(); sec.classList.remove('is-over');
        if (!dragging) return;
        var toMod = +sec.getAttribute('data-mod');
        if (dragging.kind === 'mod') {
          var ids = curMods.map(function (m) { return m.id; }), from = ids.indexOf(dragging.id), to = ids.indexOf(toMod);
          if (from < 0 || to < 0 || from === to) { dragging = null; return; }
          var mv = curMods.splice(from, 1)[0]; curMods.splice(to, 0, mv);
        } else {
          var p = findItem(dragging.id); if (!p) { dragging = null; return; }
          var it = curMods[p.mi].lessons.splice(p.li, 1)[0];
          var target = curMods.filter(function (m) { return m.id === toMod; })[0];
          target.lessons = target.lessons || [];
          /* Dropped on an item: go before it; on the module itself: go last. */
          var over = e.target.closest && e.target.closest('[data-item]');
          var at = over ? target.lessons.map(function (l) { return l.id; }).indexOf(+over.getAttribute('data-item')) : -1;
          if (at < 0) target.lessons.push(it); else target.lessons.splice(at, 0, it);
        }
        dragging = null;
        draw(slug); saveOutline(slug);
      });
    });
  }

  /* ── Item editor ──
     One screen per kind of item (reading, video, quiz, assignment,
     discussion). The kind is chosen when the item is added. Text is written
     in a rich editor (TinyMCE, fetched the first time it is needed); nothing
     asks for HTML, a file path or a web address. The server re-validates
     every field (lesson_save → LmsRepository::saveLesson / normaliseQuestion). */
  var lessonId = 0, lessonModuleId = 0, uiKind = 'reading', lessonOpener = null, dirty = false;
  var KIND_UI = {
    reading:    { label: 'Reading',    body: 'Lesson text', help: 'Write as you would in a document. Paste a YouTube or Loom link on its own line to embed it.' },
    video:      { label: 'Video',      body: 'Notes under the video', help: 'Optional: what to look out for, or what to do next.' },
    quiz:       { label: 'Quiz',       body: 'Introduction (optional)', help: 'A line or two shown above the questions.' },
    assignment: { label: 'Assignment', body: 'Instructions', help: 'Say exactly what to hand in and how it will be judged.' },
    discussion: { label: 'Discussion', body: 'The prompt', help: 'Ask one clear question. Learners complete the item by replying.' }
  };

  /* Rich text: TinyMCE when it arrives, a plain box until then (and if it never does). */
  var tinyLoading = null;
  function loadTiny() {
    if (window.tinymce) return Promise.resolve(true);
    if (tinyLoading) return tinyLoading;
    tinyLoading = new Promise(function (resolve) {
      var s = document.createElement('script'); s.src = '/assets/vendor/tinymce/tinymce.min.js';
      s.onload = function () { resolve(!!window.tinymce); }; s.onerror = function () { resolve(false); };
      document.head.appendChild(s);
    });
    return tinyLoading;
  }
  function richInit(id, html) {
    var ta = $('#' + id); ta.value = html || '';
    if (window.tinymce && tinymce.get(id)) tinymce.get(id).remove();
    loadTiny().then(function (ok) {
      if (!ok || !document.body.contains(ta)) return;
      if (tinymce.get(id)) tinymce.get(id).remove();
      var dark = document.documentElement.getAttribute('data-theme') === 'dark';
      tinymce.init({
        target: ta, height: 320, menubar: false, branding: false, promotion: false, license_key: 'gpl',
        skin: dark ? 'oxide-dark' : 'oxide', content_css: dark ? 'dark' : 'default',
        plugins: 'link lists autolink autoresize', autoresize_bottom_margin: 20, min_height: 200, max_height: 640, statusbar: false,
        toolbar: 'blocks | bold italic | bullist numlist | link | avimage | blockquote | undo redo',
        block_formats: 'Paragraph=p; Heading=h2; Subheading=h3',
        link_default_target: '_blank', convert_urls: false,
        setup: function (ed) {
          ed.ui.registry.addButton('avimage', { icon: 'image', tooltip: 'Insert an image', onAction: function () {
            uploadFile('image/*').then(function (url) { ed.insertContent('<p><img src="' + esc(url) + '" alt=""></p>'); }).catch(function () {});
          } });
          ed.on('input change', function () { dirty = true; });
        }
      });
    });
  }
  function richGet(id) { return (window.tinymce && tinymce.get(id)) ? tinymce.get(id).getContent() : $('#' + id).value; }

  /* Questions: one answer, several, or true/false, with an optional explanation. */
  function qCard(q) {
    q = q || { type: 'single', q: '', options: ['', ''], answer: null, explain: '' };
    var card = document.createElement('div'); card.className = 'qcard';
    card.innerHTML = '<div class="qrow"><span class="q-n"></span><input type="text" class="q-prompt" placeholder="Write the question" value="' + esc(q.q) + '" />'
      + '<select class="q-type" aria-label="Answer type"><option value="single">One right answer</option><option value="multi">Several right answers</option><option value="truefalse">True or false</option></select>'
      + '<button type="button" class="link-btn q-rm" aria-label="Remove question">Remove</button></div>'
      + '<p class="muted q-hint">Tick the right answer.</p><div class="q-opts"></div><button type="button" class="btn btn-ghost btn-sm q-addopt">+ Add an answer</button>'
      + '<input type="text" class="q-explain" placeholder="Why that is right (shown after answering) — optional" value="' + esc(q.explain || '') + '" />';
    var sel = card.querySelector('.q-type'); sel.value = q.type || 'single';
    var opts = card.querySelector('.q-opts'), group = 'rg' + Math.random().toString(36).slice(2);
    function addOpt(val, checked) {
      var row = document.createElement('div'); row.className = 'qopt';
      row.innerHTML = '<input type="' + (sel.value === 'multi' ? 'checkbox' : 'radio') + '" name="' + group + '" aria-label="Right answer" ' + (checked ? 'checked' : '') + ' />'
        + '<input type="text" class="o-text" placeholder="An answer" value="' + esc(val || '') + '" /><button type="button" class="link-btn o-rm" aria-label="Remove answer">✕</button>';
      row.querySelector('.o-rm').onclick = function () { if ($$('.qopt', opts).length > 2) opts.removeChild(row); };
      opts.appendChild(row);
    }
    function draw(list, picked) {
      opts.innerHTML = '';
      var tf = sel.value === 'truefalse';
      (tf ? ['True', 'False'] : list).forEach(function (o, i) { addOpt(o, picked.indexOf(i) > -1); });
      $$('.o-text', opts).forEach(function (t) { t.readOnly = tf; });
      $$('.o-rm', opts).forEach(function (b) { b.hidden = tf; });
      card.querySelector('.q-addopt').hidden = tf;
      card.querySelector('.q-hint').textContent = sel.value === 'multi' ? 'Tick every right answer.' : 'Tick the right answer.';
    }
    function current() {
      return { list: $$('.qopt', opts).map(function (r) { return r.querySelector('.o-text').value; }),
               picked: $$('.qopt', opts).map(function (r, i) { return r.querySelector('input:not(.o-text)').checked ? i : -1; }).filter(function (i) { return i > -1; }) };
    }
    /* A new question has nothing ticked, so the author has to choose — a default would quietly make the first answer right. */
    draw(q.options || ['', ''], q.answer == null ? [] : [].concat(q.answer).map(Number));
    sel.onchange = function () { var c = current(); var tfWas = c.list.join('|') === 'True|False'; draw(sel.value === 'truefalse' || tfWas ? ['', ''] : c.list, sel.value === 'multi' ? c.picked : c.picked.slice(0, 1)); };
    card.querySelector('.q-addopt').onclick = function () { addOpt('', false); };
    card.querySelector('.q-rm').onclick = function () { card.parentNode.removeChild(card); numberQs(); };
    return card;
  }
  function numberQs() { $$('#quizQuestions .qcard .q-n').forEach(function (n, i) { n.textContent = (i + 1) + '.'; }); }
  $('#addQ').onclick = function () { $('#quizQuestions').appendChild(qCard()); numberQs(); var p = $$('#quizQuestions .q-prompt'); p[p.length - 1].focus(); };

  function syncMode() {
    var graded = ($('input[name="q_mode"]:checked') || {}).value !== 'practice';
    $('#q_graded').checked = graded; $('#gradedOpts').hidden = !graded;
  }
  $$('input[name="q_mode"]').forEach(function (r) { r.addEventListener('change', syncMode); });

  function showKind(k) {
    uiKind = KIND_UI[k] ? k : 'reading';
    var ui = KIND_UI[uiKind];
    $('#l_kindChip').textContent = ui.label; $('#l_kindChip').className = 'kind-chip k-' + uiKind;
    $('#l_uikind').value = uiKind;
    $('#lessonForm').className = 'modal-body is-' + uiKind;   // a quiz shows its questions before its introduction (CSS order)
    $$('#lessonForm .ed-sec').forEach(function (s) { s.hidden = (' ' + s.getAttribute('data-for') + ' ').indexOf(' ' + uiKind + ' ') < 0; });
    $('#l_body_label').textContent = ui.body; $('#l_body_help').textContent = ui.help;
    $('#l_kind').value = uiKind === 'assignment' || uiKind === 'discussion' ? uiKind : '';
    $('#lessonSave').textContent = lessonId ? 'Save' : 'Add ' + ui.label.toLowerCase();
  }
  $('#l_uikind').addEventListener('change', function () { showKind(this.value); if (uiKind === 'quiz' && !$$('#quizQuestions .qcard').length) { $('#quizQuestions').appendChild(qCard()); numberQs(); } });

  /* Files are named, never shown as paths. */
  function fileName(url) { try { return decodeURIComponent(String(url).split('?')[0].split('/').pop()); } catch (e) { return url; } }
  function showVideo() {
    var v = $('#l_video').value.trim(), up = /^\/uploads\/|res\.cloudinary\.com\/.+\/video\//.test(v);
    $('#videoNow').hidden = !v; $('#videoNow').textContent = v ? (up ? '✓ Uploaded: ' + fileName(v) : '✓ Link added') : '';
    $('#captionsRow').hidden = !up;
    var c = $('#l_captions').value; $('#captionsNow').textContent = c ? '✓ ' + fileName(c) : 'Captions help everyone, and are needed by learners who cannot hear.';
  }
  $('#l_video').addEventListener('input', showVideo);
  $$('[data-upload]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.disabled = true;
      uploadFile(b.getAttribute('data-accept')).then(function (url) {
        $('#' + b.getAttribute('data-upload')).value = url; dirty = true; showVideo();
        if (b.getAttribute('data-upload') === 'c_cover') showCover();
        if (b.getAttribute('data-media') === 'video') readDuration(url);
      }).catch(function () {}).then(function () { b.disabled = false; });
    });
  });
  /* An uploaded video knows its own length. */
  function readDuration(url) {
    var v = document.createElement('video'); v.preload = 'metadata'; v.muted = true;
    v.onloadedmetadata = function () { if (v.duration && isFinite(v.duration) && !(+$('#l_duration').value)) $('#l_duration').value = Math.max(1, Math.round(v.duration / 60)); };
    v.src = url;
  }

  function resRow(r) {
    r = r || { title: '', url: '' };
    var row = document.createElement('div'); row.className = 'res-row';
    row.innerHTML = '<input type="text" class="r-title" placeholder="Name (e.g. Slides)" value="' + esc(r.title) + '" />'
      + '<input type="text" class="r-url" placeholder="Paste a link, or upload →" value="' + esc(r.url) + '" />'
      + '<button type="button" class="btn btn-ghost btn-sm r-up">Upload</button><button type="button" class="link-btn r-rm" aria-label="Remove">✕</button>';
    row.querySelector('.r-rm').onclick = function () { row.parentNode.removeChild(row); countRes(); };
    row.querySelector('.r-up').onclick = function () {
      uploadFile('').then(function (url) { row.querySelector('.r-url').value = url; if (!row.querySelector('.r-title').value) row.querySelector('.r-title').value = fileName(url).replace(/\.[a-z0-9]+$/i, ''); countRes(); }).catch(function () {});
    };
    $('#resList').appendChild(row); countRes();
  }
  function countRes() { var n = $$('#resList .res-row').length; $('#resCount').textContent = n ? '(' + n + ')' : ''; }
  $('#addRes').onclick = function () { resRow(); $('#resDetails').open = true; };

  /* Time, when not given: reading at 200 words a minute, a minute and a half a question. */
  function estimate(html) {
    var words = String(html || '').replace(/<[^>]+>/g, ' ').split(/\s+/).filter(Boolean).length;
    if (uiKind === 'quiz') return Math.max(2, Math.ceil($$('#quizQuestions .qcard').length * 1.5));
    if (uiKind === 'assignment') return Math.max(20, Math.ceil(words / 200) + 20);
    if (uiKind === 'discussion') return 10;
    return Math.max(1, Math.ceil(words / 200));
  }

  function openLesson(id, moduleId, preset) {
    lessonOpener = document.activeElement;
    lessonId = id || 0; lessonModuleId = moduleId || 0; dirty = false;
    $('#lessonMsg').textContent = ''; $('#uploadMsg').textContent = ''; $('#saveState').textContent = '';
    $('#quizQuestions').innerHTML = ''; $('#resList').innerHTML = ''; $('#resDetails').open = false;
    function fill(l, kind) {
      $('#lessonModalTitle').textContent = id ? (l.title || 'Edit item') : 'New ' + (KIND_UI[kind] || KIND_UI.reading).label.toLowerCase();
      $('#l_title').value = l.title || ''; $('#l_slug').value = l.slug || '';
      $('#l_duration').value = l.duration_min || 0; $('#l_video').value = l.video_url || '';
      $('#l_captions').value = l.captions_url || ''; $('#l_transcript').value = l.transcript || '';
      $('#l_preview').checked = !!(l.is_preview && l.is_preview != '0'); $('#l_weight').value = l.weight || 1;
      var a = null; try { a = l.assignment_json ? JSON.parse(l.assignment_json) : null; } catch (e) {}
      $('#l_apass').value = (a && a.pass) || 70;
      var quiz = null; try { quiz = l.quiz_json ? JSON.parse(l.quiz_json) : null; } catch (e) {}
      var graded = !(quiz && quiz.graded === false);
      $$('input[name="q_mode"]').forEach(function (r) { r.checked = r.value === (graded ? 'graded' : 'practice'); });
      $('#q_pass').value = (quiz && quiz.pass) || 70; $('#q_attempts').value = String((quiz && quiz.attempts) || 0);
      if (!$('#q_attempts').value) $('#q_attempts').value = '0';
      ((quiz && quiz.questions) || []).forEach(function (q) { $('#quizQuestions').appendChild(qCard(q)); });
      var res = []; try { res = l.resources_json ? JSON.parse(l.resources_json) : []; } catch (e) {}
      (res || []).forEach(function (r) { resRow(r); });
      showKind(kind); syncMode(); showVideo();
      if (kind === 'quiz' && !$$('#quizQuestions .qcard').length) $('#quizQuestions').appendChild(qCard());
      numberQs();
      richInit('l_body', l.body_html || '');
      $('#lessonModal').hidden = false;
      setTimeout(function () { $('#l_title').focus(); }, 30);
    }
    if (!id) { fill({}, preset || 'reading'); return; }
    get('lesson_get', 'id=' + id).then(function (d) {
      var l = (d && d.lesson) || {};
      var k = l.kind === 'assignment' || l.kind === 'discussion' ? l.kind : (l.video_url ? 'video' : (l.quiz_json ? 'quiz' : 'reading'));
      fill(l, k);
    });
  }
  function closeLesson(force) {
    if (!force && dirty && !confirm('Leave without saving your changes?')) return;
    if (window.tinymce && tinymce.get('l_body')) tinymce.get('l_body').remove();
    $('#lessonModal').hidden = true;
    if (lessonOpener && lessonOpener.focus) { try { lessonOpener.focus(); } catch (e) {} }
    lessonOpener = null;
  }
  $('#lessonForm').addEventListener('input', function () { dirty = true; });
  $('#lessonClose').onclick = function () { closeLesson(false); }; $('#lessonCancel').onclick = function () { closeLesson(false); };
  $('#lessonModal').addEventListener('mousedown', function (e) { if (e.target === this) closeLesson(false); });
  document.addEventListener('keydown', function (e) {
    if ($('#lessonModal').hidden) return;
    if (e.key === 'Escape') closeLesson(false);
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); $('#lessonSave').click(); }
  });

  $('#lessonSave').onclick = function () {
    var title = $('#l_title').value.trim();
    var say = function (m, el) { $('#lessonMsg').textContent = m; if (el) el.focus(); };
    if (!title) { say('Give it a title.', $('#l_title')); return; }
    var body = richGet('l_body');
    var questions = [];
    if (uiKind === 'quiz') {
      var bad = null;
      $$('#quizQuestions .qcard').forEach(function (card, i) {
        var type = card.querySelector('.q-type').value, prompt = card.querySelector('.q-prompt').value.trim();
        var rows = $$('.qopt', card).map(function (r) { return { text: r.querySelector('.o-text').value.trim(), ok: r.querySelector('input:not(.o-text)').checked }; }).filter(function (o) { return o.text !== ''; });
        if (!prompt && !rows.length) return;                                   // an untouched blank card
        if (!prompt) bad = bad || ['Question ' + (i + 1) + ' has no question.', card.querySelector('.q-prompt')];
        else if (rows.length < 2) bad = bad || ['Question ' + (i + 1) + ' needs at least two answers.', card.querySelector('.o-text')];
        else if (!rows.some(function (o) { return o.ok; })) bad = bad || ['Tick the right answer for question ' + (i + 1) + '.', card.querySelector('.qopt input')];
        var picked = []; rows.forEach(function (o, j) { if (o.ok) picked.push(j); });
        questions.push({ type: type, q: prompt, options: rows.map(function (o) { return o.text; }), answer: type === 'multi' ? picked : (picked[0] || 0), explain: card.querySelector('.q-explain').value.trim() });
      });
      if (bad) { say(bad[0], bad[1]); return; }
      if (!questions.length) { say('Add at least one question.', $('#addQ')); return; }
    }
    if (uiKind === 'video' && !$('#l_video').value.trim()) { say('Upload a video or paste its link.', $('#l_video')); return; }
    var payload = {
      id: lessonId, module_id: lessonModuleId, title: title, slug: $('#l_slug').value.trim(), body_html: body,
      duration_min: +$('#l_duration').value || estimate(body), is_preview: $('#l_preview').checked,
      kind: $('#l_kind').value, weight: +$('#l_weight').value || 1, assignment_pass: +$('#l_apass').value || 70,
      video_url: uiKind === 'video' ? $('#l_video').value.trim() : '',
      captions_url: uiKind === 'video' ? $('#l_captions').value.trim() : '',
      transcript: uiKind === 'video' ? $('#l_transcript').value : '',
      resources: $$('#resList .res-row').map(function (r) { return { title: r.querySelector('.r-title').value.trim(), url: r.querySelector('.r-url').value.trim() }; }).filter(function (r) { return r.url !== ''; }),
      quiz: uiKind === 'quiz' ? { pass: +$('#q_pass').value || 70, attempts: +$('#q_attempts').value || 0, graded: $('#q_graded').checked, questions: questions } : null
    };
    var btn = $('#lessonSave'); btn.disabled = true; $('#saveState').textContent = 'Saving…';
    post('lesson_save', payload).then(function (d) {
      btn.disabled = false;
      if (d && d.ok) { dirty = false; toast('Saved', 'ok'); closeLesson(true); renderCurriculum(curCourseSlug); }
      else { $('#saveState').textContent = ''; say((d && d.error) || 'Could not save.'); }
    }).catch(function () { btn.disabled = false; $('#saveState').textContent = ''; say('Network error — your work is still here. Try again.'); });
  };

  /* ── Module editor: title, introduction, objectives — and, for a new one, how to start it ── */
  var modEditing = 0, modOpener = null;
  function openModule(m) {
    modOpener = document.activeElement; modEditing = m ? m.id : 0;
    $('#modModalTitle').textContent = m ? 'Edit module' : 'New module';
    $('#m_title').value = (m && m.title) || ('Module ' + (curMods.length + 1));
    $('#m_summary').value = (m && m.summary) || ''; $('#m_objectives').value = (m && m.objectives) || '';
    $('#m_startWrap').hidden = !!m;
    $('#modMsg').textContent = ''; $('#modModal').hidden = false;
    setTimeout(function () { var t = $('#m_title'); t.focus(); t.select(); }, 30);
  }
  function closeModule() { $('#modModal').hidden = true; if (modOpener && modOpener.focus) { try { modOpener.focus(); } catch (e) {} } }
  $('#modClose').onclick = closeModule; $('#modCancel').onclick = closeModule;
  $('#modModal').addEventListener('mousedown', function (e) { if (e.target === this) closeModule(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('#modModal').hidden) closeModule(); });
  /* A standard week: the shape most modules take, so nobody starts from nothing. */
  function starterWeek(modId) {
    var ph = { type: 'single', q: 'Replace this with your question', options: ['First answer', 'Second answer'], answer: 0, explain: '' };
    var items = [
      { title: 'Lesson 1', kind: '', body_html: '<p>Write your first lesson here.</p>' },
      { title: 'Lesson 2', kind: '', body_html: '<p>Write your second lesson here.</p>' },
      { title: 'Practice check', kind: '', quiz: { pass: 50, graded: false, attempts: 0, questions: [ph] } },
      { title: 'Module quiz', kind: '', quiz: { pass: 70, graded: true, attempts: 3, questions: [ph] } }
    ];
    return items.reduce(function (p, it) {
      return p.then(function () { return post('lesson_save', Object.assign({ id: 0, module_id: modId, slug: '', duration_min: it.quiz ? 2 : 5 }, it)); });
    }, Promise.resolve());
  }
  $('#modSave').onclick = function () {
    var t = $('#m_title').value.trim(); if (!t) { $('#modMsg').textContent = 'Give the module a title.'; $('#m_title').focus(); return; }
    var p = { title: t, summary: $('#m_summary').value, objectives: $('#m_objectives').value };
    if (modEditing) p.id = modEditing; else p.course = curCourseSlug;
    var week = !modEditing && ($('input[name="m_start"]:checked') || {}).value === 'week';
    $('#modSave').disabled = true;
    post('mod_save', p).then(function (r) {
      if (!(r && r.ok)) { $('#modSave').disabled = false; $('#modMsg').textContent = (r && r.error) || 'Could not save.'; return; }
      return (week ? starterWeek(r.id) : Promise.resolve()).then(function () { $('#modSave').disabled = false; closeModule(); renderCurriculum(curCourseSlug); });
    }).catch(function () { $('#modSave').disabled = false; $('#modMsg').textContent = 'Network error.'; });
  };

  /* ── Programmes: series of courses with a programme certificate ── */
  var progEditing = '';
  function loadProgrammes() {
    var body = $('#progBody'); body.innerHTML = '<tr><td colspan="4" class="empty">Loading…</td></tr>';
    get('prog_list').then(function (d) {
      var rows = (d && d.programmes) || [];
      if (!rows.length) { body.innerHTML = '<tr><td colspan="4" class="empty">No programmes yet. A programme groups courses into a series with its own certificate.</td></tr>'; return; }
      body.innerHTML = rows.map(function (p) {
        return '<tr><td class="c-title">' + esc(p.title) + '<div class="muted">/academy/programmes/' + esc(p.slug) + '/</div></td><td class="num">' + esc(p.course_count) + '</td>'
          + '<td><span class="badge ' + (p.status === 'published' ? 'published' : 'draft') + '">' + esc(p.status) + '</span></td>'
          + '<td><div class="row-actions"><button class="btn btn-outline btn-sm" data-pedit="' + esc(p.slug) + '">Edit</button><button class="btn btn-ghost btn-sm" data-pdel="' + esc(p.slug) + '">Delete</button></div></td></tr>';
      }).join('');
      $$('[data-pedit]', body).forEach(function (b) { b.onclick = function () { openProgramme(b.getAttribute('data-pedit')); }; });
      $$('[data-pdel]', body).forEach(function (b) { b.onclick = function () {
        var slug = b.getAttribute('data-pdel'); if (!confirm('Delete this programme? Its courses stay.')) return;
        post('prog_delete', { slug: slug }).then(function (d) {
          if (d && d.ok) { toast('Programme deleted', 'ok'); loadProgrammes(); return; }
          if (d && d.needs_confirm && confirm(d.error)) post('prog_delete', { slug: slug, force: true }).then(function () { loadProgrammes(); });
          else toast((d && d.error) || 'Delete failed', 'err');
        });
      }; });
    });
  }
  function openProgramme(slug) {
    progEditing = slug || '';
    var load = slug ? get('prog_get', 'slug=' + encodeURIComponent(slug)) : Promise.resolve({ programme: { status: 'draft', sort: 0 }, courses: [] });
    Promise.all([load, get('ac_list')]).then(function (res) {
      var p = (res[0] && res[0].programme) || {}; var inProg = (res[0] && res[0].courses) || [];
      var all = (res[1] && res[1].courses) || [];
      $('#p_title').value = p.title || ''; $('#p_slug').value = p.slug || ''; $('#p_summary').value = p.summary || '';
      $('#p_body').value = p.body_html || ''; $('#p_cover').value = p.cover_url || ''; $('#p_status').value = p.status === 'published' ? 'published' : 'draft'; $('#p_sort').value = p.sort || 0;
      var pos = {}; inProg.forEach(function (c, i) { pos[c.slug] = i + 1; });
      $('#progCourses').innerHTML = all.map(function (c) {
        var on = pos[c.slug] !== undefined;
        return '<div class="prog-row"><label class="chk"><input type="checkbox" data-pc="' + esc(c.slug) + '"' + (on ? ' checked' : '') + ' /> <span>' + esc(c.title) + (c.status !== 'published' ? ' <em class="muted">(draft)</em>' : '') + '</span></label>'
          + '<input type="number" min="1" class="prog-pos" aria-label="Order for ' + esc(c.title) + '" data-pp="' + esc(c.slug) + '" value="' + (on ? pos[c.slug] : '') + '" /></div>';
      }).join('') || '<p class="muted">Create a course first.</p>';
      VIEWS.programme.title = slug ? 'Edit programme' : 'New programme';
      route('programme');
    });
  }
  function saveProgramme() {
    var title = $('#p_title').value.trim(); if (!title) { toast('A title is required', 'err'); return; }
    var picked = $$('[data-pc]').filter(function (c) { return c.checked; }).map(function (c, i) {
      var s = c.getAttribute('data-pc'); var n = +($('[data-pp="' + s + '"]').value) || (900 + i); return { s: s, n: n };
    }).sort(function (a, b) { return a.n - b.n; }).map(function (x) { return x.s; });
    var payload = { editing: progEditing, title: title, slug: $('#p_slug').value.trim(), summary: $('#p_summary').value,
      body_html: $('#p_body').value, cover_url: $('#p_cover').value.trim(), status: $('#p_status').value, sort: +$('#p_sort').value || 0, courses: picked };
    post('prog_save', payload).then(function (d) {
      if (d && d.ok) { toast('Programme saved', 'ok'); route('programmes'); } else toast((d && d.error) || 'Save failed', 'err');
    }).catch(function () { toast('Network error', 'err'); });
  }

  /* ── Learners ── */
  var rosterSlug = '';
  function loadLearners(slug) {
    ensureCourses().then(function () {
      fillCoursePickers();
      var sel = $('#learnCourse'); if (slug) sel.value = slug;
      sel.onchange = function () { renderRoster(sel.value); };
      var ef = $('#enrolForm');
      if (ef) ef.onsubmit = function (e) {
        e.preventDefault();
        var email = $('#enrolEmail').value.trim(); if (!email || !rosterSlug) return;
        post('roster_enrol', { course: rosterSlug, email: email }).then(function (d) {
          if (d && d.ok) { toast('Enrolled ' + email, 'ok'); $('#enrolEmail').value = ''; renderRoster(rosterSlug); }
          else toast((d && d.error) || 'Could not enrol', 'err');
        });
      };
      var cb = $('#exportCsv');
      if (cb) cb.onclick = function () { if (rosterSlug) window.open(API + '?action=ac_export&slug=' + encodeURIComponent(rosterSlug), '_blank'); };
      renderRoster(sel.value || (courses[0] && courses[0].slug));
    });
  }
  function rosterAction(action, userId, confirmMsg, okMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return;
    post(action, { course: rosterSlug, user_id: userId }).then(function (d) {
      if (d && d.ok) { toast(okMsg + (d.serial ? ' · ' + d.serial : ''), 'ok'); renderRoster(rosterSlug); }
      else toast((d && d.error) || 'Failed', 'err');
    });
  }
  function renderRoster(slug) {
    rosterSlug = slug;
    var body = $('#rosterBody'); if (!slug) { body.innerHTML = '<tr><td colspan="6" class="empty">No courses.</td></tr>'; return; }
    body.innerHTML = '<tr><td colspan="6" class="empty">Loading…</td></tr>';
    get('ac_roster', 'slug=' + encodeURIComponent(slug)).then(function (d) {
      if (!d || !d.ok) { body.innerHTML = '<tr><td colspan="6" class="empty">Could not load.</td></tr>'; return; }
      $('#learnHint').textContent = (d.roster || []).length + ' enrolled · ' + (d.course ? d.course.lessons : 0) + ' lessons';
      if (!d.roster.length) { body.innerHTML = '<tr><td colspan="6" class="empty">No learners enrolled yet. Use “Enrol by email” to add someone with an Academy account.</td></tr>'; return; }
      body.innerHTML = d.roster.map(function (r) {
        return '<tr><td class="c-title">' + esc(r.name || '—') + '</td><td>' + esc(r.email) + '</td>'
          + '<td class="num"><div style="display:flex;align-items:center;gap:8px;justify-content:flex-end"><div class="progress"><i style="width:' + (r.pct || 0) + '%"></i></div><span>' + (r.pct || 0) + '%</span></div></td>'
          + '<td>' + (r.certified ? '<span class="badge cert">Certified</span>' : '<span class="muted">—</span>') + '</td>'
          + '<td class="muted">' + esc((r.last_active || '').slice(0, 10) || '—') + '</td>'
          + '<td><div class="row-actions">'
          + (r.certified
              ? '<button class="btn btn-ghost btn-sm" data-revoke="' + r.id + '">Revoke cert</button>'
              : '<button class="btn btn-ghost btn-sm" data-issue="' + r.id + '">Issue cert</button>')
          + '<button class="btn btn-ghost btn-sm" data-reset="' + r.id + '">Reset</button>'
          + '<button class="btn btn-ghost btn-sm" data-unenrol="' + r.id + '">Unenrol</button>'
          + '</div></td></tr>';
      }).join('');
      $$('[data-unenrol]', body).forEach(function (b) { b.onclick = function () { rosterAction('roster_unenrol', +b.getAttribute('data-unenrol'), 'Unenrol this learner and delete their progress in this course?', 'Unenrolled'); }; });
      $$('[data-reset]', body).forEach(function (b) { b.onclick = function () { rosterAction('roster_reset', +b.getAttribute('data-reset'), 'Reset this learner’s lesson progress and quiz attempts for this course?', 'Progress reset'); }; });
      $$('[data-issue]', body).forEach(function (b) { b.onclick = function () { rosterAction('cert_issue', +b.getAttribute('data-issue'), 'Issue a certificate to this learner now (bypasses the completion check)?', 'Certificate issued'); }; });
      $$('[data-revoke]', body).forEach(function (b) { b.onclick = function () { rosterAction('cert_revoke', +b.getAttribute('data-revoke'), 'Revoke this learner’s certificate for this course?', 'Certificate revoked'); }; });
    });
  }

  /* ── Applications ── */
  function loadApplications() {
    var body = $('#appsBody'); body.innerHTML = '<tr><td colspan="5" class="empty">Loading…</td></tr>';
    get('enrollments').then(function (d) {
      var rows = (d && d.enrollments) || [];
      if (!rows.length) { body.innerHTML = '<tr><td colspan="5" class="empty">No applications yet.</td></tr>'; return; }
      body.innerHTML = rows.map(function (r) {
        return '<tr><td class="c-title">' + esc(r.name) + '</td><td>' + esc(r.email) + '</td><td>' + esc(r.phone || '—') + '</td>'
          + '<td>' + esc(r.course_slug || r.course_id || '—') + '</td><td class="muted">' + esc((r.created_at || '').slice(0, 16)) + '</td></tr>';
      }).join('');
    });
  }

  /* ── Activity / audit log ── */
  var ALABEL = {
    admin_login: 'Admin signed in', admin_login_failed: 'Failed admin sign-in', admin_logout: 'Admin signed out',
    ac_save: 'Course saved', ac_delete: 'Course deleted', mod_save: 'Module saved', mod_delete: 'Module deleted',
    lesson_save: 'Lesson saved', lesson_delete: 'Lesson deleted', save: 'Diary entry saved', delete: 'Diary entry deleted',
    mem_save: 'Member updated', mem_create: 'Member created', role_change: 'Role changed', suspend: 'Member suspended',
    reactivate: 'Member reactivated', create_member: 'Member created', comm_save: 'Community saved', comm_delete: 'Community deleted',
    wh_save: 'Webhook saved', wh_delete: 'Webhook deleted', wh_test: 'Webhook tested', auth_policy_save: 'Sign-in policy changed',
    apptoken_create: 'API token created', apptoken_revoke: 'API token revoked', team_save: 'Team member saved',
    team_delete: 'Team member deleted', cel_save: 'Celebration saved', cel_delete: 'Celebration deleted', mail_test: 'Test email sent'
  };
  function aclass(a) { return /delete|revoke|suspend|failed/.test(a) ? 'draft' : (/login|create|save|reactivate/.test(a) ? 'published' : 'access'); }
  function loadActivity() {
    var body = $('#auditBody'); body.innerHTML = '<tr><td colspan="6" class="empty">Loading…</td></tr>';
    get('audit_log').then(function (d) {
      var rows = (d && d.audit) || [];
      if (!rows.length) { body.innerHTML = '<tr><td colspan="6" class="empty">No activity recorded yet.</td></tr>'; return; }
      body.innerHTML = rows.map(function (r) {
        return '<tr><td class="muted" style="white-space:nowrap">' + esc((r.created_at || '').replace('T', ' ').slice(0, 16)) + '</td>'
          + '<td><span class="badge ' + aclass(r.action) + '">' + esc(ALABEL[r.action] || r.action) + '</span></td>'
          + '<td class="c-title">' + esc(r.target || '—') + '</td>'
          + '<td class="muted">' + esc(r.detail || '') + '</td>'
          + '<td>' + esc(r.actor || 'admin') + '</td>'
          + '<td class="muted" style="font-variant-numeric:tabular-nums">' + esc(r.ip || '—') + '</td></tr>';
      }).join('');
    });
  }

  /* ── Boot ── */
  /* Where the Studio opens: a deep link (#curriculum=<slug>, from the admin
     console's Curriculum button) or the overview. Both ways in — an existing
     session and a fresh sign-in — come through here. */
  function startRoute() {
    var hm = /^#curriculum=([a-z0-9-]+)$/.exec(location.hash);
    if (hm) route('curriculum', hm[1]); else route('overview');
  }
  get('session').then(function (d) {
    if (d && d.ok) { csrf = d.csrf || ''; showApp(true); startRoute(); }
    else showApp(false);
  }).catch(function () { showApp(false); });
})();
