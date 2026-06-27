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
      method: opts.method || 'GET', headers: headers, body: opts.body, credentials: 'same-origin'
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Bad server response.' }; }); });
  }
  function get(action, qs) { return api(action, { qs: qs ? '&' + qs : '' }); }
  function post(action, payload) { return api(action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload || {}) }); }

  /* ── Auth ── */
  function showApp(on) { $('#app').hidden = !on; $('#login').hidden = on; }
  $('#loginForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = e.target.querySelector('button'); btn.disabled = true;
    post('login', { token: $('#token').value }).then(function (d) {
      btn.disabled = false;
      if (d && d.ok) { csrf = d.csrf || ''; showApp(true); route('overview'); }
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
    curriculum:   { title: 'Curriculum',   sub: 'Modules, lessons & quizzes', actions: '' },
    learners:     { title: 'Learners',     sub: 'Progress & certificates', actions: '' },
    applications: { title: 'Applications', sub: 'Lead-capture sign-ups', actions: '' }
  };
  function route(name, arg) {
    var v = VIEWS[name] || VIEWS.overview;
    $$('.view').forEach(function (el) { el.hidden = el.id !== 'view-' + name; });
    $$('.nav-item').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-view') === (name === 'course' ? 'courses' : name)); });
    $('#viewTitle').textContent = v.title; $('#viewSub').textContent = v.sub;
    $('#topActions').innerHTML = v.actions;
    $('#app').classList.remove('nav-open');
    if (name === 'overview') loadOverview();
    if (name === 'courses') { loadCourses(); var nb = $('#newCourse'); if (nb) nb.onclick = function () { openCourse(null); }; }
    if (name === 'course') { $('#courseBack').onclick = function () { route('courses'); }; $('#courseSave').onclick = saveCourse; }
    if (name === 'curriculum') loadCurriculum(arg);
    if (name === 'learners') loadLearners(arg);
    if (name === 'applications') loadApplications();
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
        ['Members', s.members, 'active memberships']
      ];
      $('#statGrid').innerHTML = cards.map(function (c) {
        return '<div class="stat"><div class="stat-num">' + esc(c[1]) + '</div><div class="stat-label">' + esc(c[0]) + '</div><div class="stat-sub">' + esc(c[2]) + '</div></div>';
      }).join('');
    });
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
          + '<a class="btn btn-ghost btn-sm" href="/academy/' + esc(c.slug) + '/" target="_blank" rel="noopener">View</a>'
          + '<button class="btn btn-ghost btn-sm" data-del="' + esc(c.slug) + '">Delete</button>'
          + '</div></td></tr>';
      }).join('');
      $$('[data-edit]', body).forEach(function (b) { b.onclick = function () { openCourse(b.getAttribute('data-edit')); }; });
      $$('[data-curr]', body).forEach(function (b) { b.onclick = function () { route('curriculum', b.getAttribute('data-curr')); }; });
      $$('[data-del]', body).forEach(function (b) { b.onclick = function () { deleteCourse(b.getAttribute('data-del')); }; });
    });
  }
  function deleteCourse(slug) {
    if (!confirm('Delete “' + slug + '” and its curriculum? This cannot be undone.')) return;
    post('ac_delete', { slug: slug }).then(function (d) { if (d && d.ok) { toast('Course deleted', 'ok'); loadCourses(); } else toast((d && d.error) || 'Delete failed', 'err'); });
  }

  var CF = { title: 'c_title', slug: 'c_slug', summary: 'c_summary', body_html: 'c_body', outcomes: 'c_outcomes',
    category: 'c_category', level: 'c_level', format: 'c_format', duration: 'c_duration', price: 'c_pricelabel',
    cover_url: 'c_cover', cta_url: 'c_cta', sort: 'c_sort', price_ngn: 'c_price_ngn', instructor_email: 'c_instructor' };
  function toggledPrice() { $('#priceWrap').hidden = $('#c_access').value !== 'paid'; }
  $('#c_access').addEventListener('change', toggledPrice);
  function openCourse(slug) {
    editingSlug = slug;
    $('#courseNote').textContent = '';
    var blank = { status: 'draft', access_type: 'open', gradient: 'g-gold', sort: 0, price_ngn: 0 };
    function fill(c) {
      Object.keys(CF).forEach(function (k) { var el = $('#' + CF[k]); if (el) el.value = c[k] != null ? c[k] : ''; });
      $('#c_status').value = c.status === 'published' ? 'published' : 'draft';
      $('#c_access').value = c.access_type || 'open';
      $('#c_gradient').value = c.gradient || 'g-gold';
      $('#c_featured').checked = !!(c.featured && c.featured != '0');
      $('#c_instructor').value = c.instructor_email || '';
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
    payload.status = $('#c_status').value; payload.access_type = $('#c_access').value;
    payload.gradient = $('#c_gradient').value; payload.featured = $('#c_featured').checked;
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
  function renderCurriculum(slug) {
    curCourseSlug = slug;
    var wrap = $('#curriculum');
    if (!slug) { wrap.innerHTML = '<p class="empty">No courses yet.</p>'; return; }
    wrap.innerHTML = '<p class="empty">Loading…</p>';
    get('ac_curriculum', 'slug=' + encodeURIComponent(slug)).then(function (d) {
      if (!d || !d.ok) { wrap.innerHTML = '<p class="empty">' + esc((d && d.error) || 'Could not load.') + '</p>'; return; }
      $('#curHint').textContent = (d.modules || []).length + ' modules';
      var html = (d.modules || []).map(function (m) {
        var lessons = (m.lessons || []).map(function (l) {
          return '<li class="les"><span class="les-grip">⋮⋮</span><span class="les-title">' + esc(l.title) + '</span>'
            + '<span class="les-tags">' + (l.is_preview && l.is_preview != '0' ? '<span class="badge cert">preview</span>' : '')
            + '<span class="badge access">' + esc(l.duration_min || 0) + ' min</span></span>'
            + '<button class="btn btn-outline btn-sm" data-led="' + l.id + '">Edit</button>'
            + '<button class="btn btn-ghost btn-sm" data-ldel="' + l.id + '">✕</button></li>';
        }).join('') || '<li class="les-empty">No lessons yet.</li>';
        return '<div class="mod"><div class="mod-head"><span class="mod-title">' + esc(m.title) + '</span>'
          + '<button class="btn btn-ghost btn-sm" data-mren="' + m.id + '">Rename</button>'
          + '<button class="btn btn-ghost btn-sm" data-mdel="' + m.id + '">Delete</button></div>'
          + '<ul class="mod-lessons">' + lessons + '</ul>'
          + '<div class="mod-foot"><button class="btn btn-outline btn-sm" data-ladd="' + m.id + '">+ Add lesson</button></div></div>';
      }).join('');
      wrap.innerHTML = html + '<button class="btn btn-primary btn-sm" id="addMod">+ Add module</button>';
      $('#addMod').onclick = function () {
        var t = prompt('Module title'); if (!t) return;
        post('mod_save', { course: slug, title: t }).then(function (r) { if (r && r.ok) renderCurriculum(slug); else toast((r && r.error) || 'Failed', 'err'); });
      };
      $$('[data-mren]', wrap).forEach(function (b) { b.onclick = function () { var t = prompt('New module title'); if (t) post('mod_save', { id: +b.getAttribute('data-mren'), title: t }).then(function () { renderCurriculum(slug); }); }; });
      $$('[data-mdel]', wrap).forEach(function (b) { b.onclick = function () { if (confirm('Delete this module and its lessons?')) post('mod_delete', { id: +b.getAttribute('data-mdel') }).then(function () { renderCurriculum(slug); }); }; });
      $$('[data-ladd]', wrap).forEach(function (b) { b.onclick = function () { openLesson(null, +b.getAttribute('data-ladd')); }; });
      $$('[data-led]', wrap).forEach(function (b) { b.onclick = function () { openLesson(+b.getAttribute('data-led'), null); }; });
      $$('[data-ldel]', wrap).forEach(function (b) { b.onclick = function () { if (confirm('Delete this lesson?')) post('lesson_delete', { id: +b.getAttribute('data-ldel') }).then(function () { renderCurriculum(slug); }); }; });
    });
  }

  /* ── Lesson modal + quiz builder ── */
  var lessonId = 0, lessonModuleId = 0;
  function qCard(q) {
    q = q || { q: '', options: ['', ''], answer: 0 };
    var card = document.createElement('div'); card.className = 'qcard';
    card.innerHTML = '<div class="qrow"><input type="text" class="q-prompt" placeholder="Question" value="' + esc(q.q) + '" /><button type="button" class="link-btn q-rm">Remove</button></div><div class="q-opts"></div><button type="button" class="btn btn-ghost btn-sm q-addopt">+ Option</button>';
    var opts = card.querySelector('.q-opts');
    function addOpt(val, checked) {
      var row = document.createElement('div'); row.className = 'qopt';
      row.innerHTML = '<input type="radio" name="ans-' + Math.random().toString(36).slice(2) + '" ' + (checked ? 'checked' : '') + ' /><input type="text" class="o-text" placeholder="Answer option" value="' + esc(val || '') + '" /><button type="button" class="link-btn o-rm">✕</button>';
      // group radios within this card
      row.querySelector('input[type=radio]').name = card._rg || (card._rg = 'rg' + Math.random().toString(36).slice(2));
      row.querySelector('.o-rm').onclick = function () { row.remove(); };
      opts.appendChild(row);
    }
    (q.options || ['', '']).forEach(function (o, i) { addOpt(o, i === (q.answer || 0)); });
    card.querySelector('.q-addopt').onclick = function () { addOpt('', false); };
    card.querySelector('.q-rm').onclick = function () { card.remove(); };
    return card;
  }
  function openLesson(id, moduleId) {
    lessonId = id || 0; lessonModuleId = moduleId || 0;
    $('#lessonMsg').textContent = '';
    $('#quizQuestions').innerHTML = ''; $('#q_pass').value = 70;
    function fill(l) {
      $('#lessonModalTitle').textContent = id ? 'Edit lesson' : 'New lesson';
      $('#l_title').value = l.title || ''; $('#l_slug').value = l.slug || '';
      $('#l_duration').value = l.duration_min || 0; $('#l_body').value = l.body_html || '';
      $('#l_video').value = l.video_url || ''; $('#l_preview').checked = !!(l.is_preview && l.is_preview != '0');
      var quiz = null; try { quiz = l.quiz_json ? JSON.parse(l.quiz_json) : null; } catch (e) {}
      if (quiz && quiz.questions) { $('#q_pass').value = quiz.pass || 70; quiz.questions.forEach(function (q) { $('#quizQuestions').appendChild(qCard(q)); }); }
      $('#lessonModal').hidden = false;
    }
    if (!id) { fill({}); return; }
    get('lesson_get', 'id=' + id).then(function (d) { fill((d && d.lesson) || {}); });
  }
  function closeLesson() { $('#lessonModal').hidden = true; }
  $('#lessonClose').onclick = closeLesson; $('#lessonCancel').onclick = closeLesson;
  $('#addQ').onclick = function () { $('#quizQuestions').appendChild(qCard()); };
  $('#lessonSave').onclick = function () {
    var title = $('#l_title').value.trim(); if (!title) { $('#lessonMsg').textContent = 'A title is required.'; return; }
    // gather quiz
    var questions = [];
    $$('#quizQuestions .qcard').forEach(function (card) {
      var prompt = card.querySelector('.q-prompt').value.trim();
      var opts = $$('.qopt', card).map(function (r) { return { text: r.querySelector('.o-text').value.trim(), correct: r.querySelector('input[type=radio]').checked }; }).filter(function (o) { return o.text !== ''; });
      if (!prompt || opts.length < 2) return;
      var ans = 0; opts.forEach(function (o, i) { if (o.correct) ans = i; });
      questions.push({ q: prompt, options: opts.map(function (o) { return o.text; }), answer: ans });
    });
    var payload = {
      id: lessonId, module_id: lessonModuleId, title: title, slug: $('#l_slug').value.trim(),
      body_html: $('#l_body').value, video_url: $('#l_video').value.trim(),
      duration_min: +$('#l_duration').value || 0, is_preview: $('#l_preview').checked
    };
    if (questions.length) payload.quiz = { pass: +$('#q_pass').value || 70, questions: questions };
    $('#lessonSave').disabled = true;
    post('lesson_save', payload).then(function (d) {
      $('#lessonSave').disabled = false;
      if (d && d.ok) { toast('Lesson saved', 'ok'); closeLesson(); renderCurriculum(curCourseSlug); }
      else $('#lessonMsg').textContent = (d && d.error) || 'Save failed.';
    }).catch(function () { $('#lessonSave').disabled = false; $('#lessonMsg').textContent = 'Network error.'; });
  };

  /* ── Learners ── */
  function loadLearners(slug) {
    ensureCourses().then(function () {
      fillCoursePickers();
      var sel = $('#learnCourse'); if (slug) sel.value = slug;
      sel.onchange = function () { renderRoster(sel.value); };
      renderRoster(sel.value || (courses[0] && courses[0].slug));
    });
  }
  function renderRoster(slug) {
    var body = $('#rosterBody'); if (!slug) { body.innerHTML = '<tr><td colspan="5" class="empty">No courses.</td></tr>'; return; }
    body.innerHTML = '<tr><td colspan="5" class="empty">Loading…</td></tr>';
    get('ac_roster', 'slug=' + encodeURIComponent(slug)).then(function (d) {
      if (!d || !d.ok) { body.innerHTML = '<tr><td colspan="5" class="empty">Could not load.</td></tr>'; return; }
      $('#learnHint').textContent = (d.roster || []).length + ' enrolled · ' + (d.course ? d.course.lessons : 0) + ' lessons';
      if (!d.roster.length) { body.innerHTML = '<tr><td colspan="5" class="empty">No learners enrolled yet.</td></tr>'; return; }
      body.innerHTML = d.roster.map(function (r) {
        return '<tr><td class="c-title">' + esc(r.name || '—') + '</td><td>' + esc(r.email) + '</td>'
          + '<td class="num"><div style="display:flex;align-items:center;gap:8px;justify-content:flex-end"><div class="progress"><i style="width:' + (r.pct || 0) + '%"></i></div><span>' + (r.pct || 0) + '%</span></div></td>'
          + '<td>' + (r.certified ? '<span class="badge cert">Certified</span>' : '<span class="muted">—</span>') + '</td>'
          + '<td class="muted">' + esc((r.last_active || '').slice(0, 10) || '—') + '</td></tr>';
      }).join('');
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

  /* ── Boot ── */
  get('session').then(function (d) {
    if (d && d.ok) { csrf = d.csrf || ''; showApp(true); route('overview'); }
    else showApp(false);
  }).catch(function () { showApp(false); });
})();
