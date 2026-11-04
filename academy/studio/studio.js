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
  // Lesson-type badge (mirrors the learner-side video/reading/quiz cue).
  function lesTypeBadge(t) {
    var m = { video: ['▶', 'Video', 'video'], quiz: ['✓', 'Graded quiz', 'quiz'], practice: ['✓', 'Practice quiz', 'quiz'], assignment: ['✎', 'Assignment', 'quiz'], discussion: ['❝', 'Discussion', 'reading'], reading: ['≡', 'Reading', 'reading'] };
    var x = m[t] || m.reading;
    return '<span class="les-type t-' + x[2] + '" title="' + x[1] + '">' + x[0] + ' ' + x[1] + '</span>';
  }
  function renderCurriculum(slug) {
    curCourseSlug = slug;
    var wrap = $('#curriculum');
    if (!slug) { wrap.innerHTML = '<p class="empty">No courses yet.</p>'; return; }
    wrap.innerHTML = '<p class="empty">Loading…</p>';
    get('ac_curriculum', 'slug=' + encodeURIComponent(slug)).then(function (d) {
      if (!d || !d.ok) { wrap.innerHTML = '<p class="empty">' + esc((d && d.error) || 'Could not load.') + '</p>'; return; }
      var mods = (d.modules || []).map(function (m) { m.id = +m.id; (m.lessons || []).forEach(function (l) { l.id = +l.id; }); return m; });
      $('#curHint').textContent = mods.length + ' module' + (mods.length === 1 ? '' : 's');
      // Curriculum summary (author overview): modules · lessons · runtime
      var totalLessons = 0, totalMin = 0;
      mods.forEach(function (m) { (m.lessons || []).forEach(function (l) { totalLessons++; totalMin += (+l.duration_min || 0); }); });
      $('#curHint').textContent = mods.length + ' module' + (mods.length === 1 ? '' : 's')
        + ' · ' + totalLessons + ' lesson' + (totalLessons === 1 ? '' : 's') + (totalMin ? ' · ' + totalMin + ' min' : '');
      var html = mods.map(function (m, mi) {
        var lessons = (m.lessons || []).map(function (l, li) {
          return '<li class="les">'
            + '<button class="btn btn-ghost btn-sm les-mv" data-lup="' + l.id + '" data-mod="' + m.id + '" title="Move up"' + (li === 0 ? ' disabled' : '') + '>▲</button>'
            + '<button class="btn btn-ghost btn-sm les-mv" data-ldn="' + l.id + '" data-mod="' + m.id + '" title="Move down"' + (li === m.lessons.length - 1 ? ' disabled' : '') + '>▼</button>'
            + lesTypeBadge(l.type)
            + '<span class="les-title">' + esc(l.title) + '</span>'
            + '<span class="les-tags">' + (l.is_preview && l.is_preview != '0' ? '<span class="badge cert">preview</span>' : '')
            + '<span class="badge access">' + esc(l.duration_min || 0) + ' min</span></span>'
            + '<button class="btn btn-outline btn-sm" data-led="' + l.id + '">Edit</button>'
            + '<button class="btn btn-ghost btn-sm" data-ldel="' + l.id + '">✕</button></li>';
        }).join('') || '<li class="les-empty">No lessons yet.</li>';
        return '<div class="mod"><div class="mod-head"><span class="mod-title">' + esc(m.title) + (m.summary ? '<span class="muted mod-sum">' + esc(m.summary) + '</span>' : '') + '</span>'
          + '<button class="btn btn-ghost btn-sm" data-mup="' + m.id + '" title="Move up"' + (mi === 0 ? ' disabled' : '') + '>▲</button>'
          + '<button class="btn btn-ghost btn-sm" data-mdn="' + m.id + '" title="Move down"' + (mi === mods.length - 1 ? ' disabled' : '') + '>▼</button>'
          + '<button class="btn btn-ghost btn-sm" data-mren="' + m.id + '">Edit</button>'
          + '<button class="btn btn-ghost btn-sm" data-mdel="' + m.id + '">Delete</button></div>'
          + '<ul class="mod-lessons">' + lessons + '</ul>'
          + '<div class="mod-foot"><button class="btn btn-outline btn-sm" data-ladd="' + m.id + '">+ Add item</button></div></div>';
      }).join('');
      wrap.innerHTML = html + '<button class="btn btn-primary btn-sm" id="addMod">+ Add module</button>';
      function swapAndSave(action, payloadKey, payloadVal, ids, i, j) {
        if (i < 0 || j < 0 || j >= ids.length) return;
        var t = ids[i]; ids[i] = ids[j]; ids[j] = t; var p = { ids: ids }; p[payloadKey] = payloadVal;
        post(action, p).then(function () { renderCurriculum(slug); });
      }
      function moveModule(id, dir) { var ids = mods.map(function (m) { return m.id; }); var i = ids.indexOf(id); swapAndSave('mod_reorder', 'course', slug, ids, i, i + dir); }
      function moveLesson(modId, id, dir) { var m = mods.filter(function (x) { return x.id === modId; })[0]; if (!m) return; var ids = (m.lessons || []).map(function (l) { return l.id; }); var i = ids.indexOf(id); swapAndSave('lesson_reorder', 'module_id', modId, ids, i, i + dir); }
      $('#addMod').onclick = function () { openModule(null); };
      $$('[data-mup]', wrap).forEach(function (b) { b.onclick = function () { moveModule(+b.getAttribute('data-mup'), -1); }; });
      $$('[data-mdn]', wrap).forEach(function (b) { b.onclick = function () { moveModule(+b.getAttribute('data-mdn'), 1); }; });
      $$('[data-lup]', wrap).forEach(function (b) { b.onclick = function () { moveLesson(+b.getAttribute('data-mod'), +b.getAttribute('data-lup'), -1); }; });
      $$('[data-ldn]', wrap).forEach(function (b) { b.onclick = function () { moveLesson(+b.getAttribute('data-mod'), +b.getAttribute('data-ldn'), 1); }; });
      $$('[data-mren]', wrap).forEach(function (b) { b.onclick = function () { var id = +b.getAttribute('data-mren'); openModule(mods.filter(function (x) { return x.id === id; })[0] || null); }; });
      $$('[data-mdel]', wrap).forEach(function (b) { b.onclick = function () { if (confirm('Delete this module and its lessons?')) post('mod_delete', { id: +b.getAttribute('data-mdel') }).then(function () { renderCurriculum(slug); }); }; });
      $$('[data-ladd]', wrap).forEach(function (b) { b.onclick = function () { openLesson(null, +b.getAttribute('data-ladd')); }; });
      $$('[data-led]', wrap).forEach(function (b) { b.onclick = function () { openLesson(+b.getAttribute('data-led'), null); }; });
      $$('[data-ldel]', wrap).forEach(function (b) { b.onclick = function () { if (confirm('Delete this lesson?')) post('lesson_delete', { id: +b.getAttribute('data-ldel') }).then(function () { renderCurriculum(slug); }); }; });
    });
  }

  /* ── Lesson modal + quiz builder ──
     A question is single-answer, multi-select or true/false, with an optional
     explanation shown after the learner answers. The server re-validates
     every question (LmsRepository::normaliseQuestion). */
  var lessonId = 0, lessonModuleId = 0;
  function qCard(q) {
    q = q || { type: 'single', q: '', options: ['', ''], answer: 0, explain: '' };
    var type = q.type || 'single';
    var card = document.createElement('div'); card.className = 'qcard';
    card.innerHTML = '<div class="qrow"><select class="q-type" aria-label="Question type"><option value="single">One answer</option><option value="multi">Several answers</option><option value="truefalse">True / false</option></select>'
      + '<input type="text" class="q-prompt" placeholder="Question" value="' + esc(q.q) + '" /><button type="button" class="link-btn q-rm">Remove</button></div>'
      + '<div class="q-opts"></div><button type="button" class="btn btn-ghost btn-sm q-addopt">+ Option</button>'
      + '<input type="text" class="q-explain" placeholder="Explanation shown after answering (optional)" value="' + esc(q.explain || '') + '" />';
    var sel = card.querySelector('.q-type'); sel.value = type;
    var opts = card.querySelector('.q-opts');
    var group = 'rg' + Math.random().toString(36).slice(2);
    var keys = [].concat(q.answer == null ? [0] : q.answer).map(Number);
    function addOpt(val, checked) {
      var row = document.createElement('div'); row.className = 'qopt';
      var multi = sel.value === 'multi';
      row.innerHTML = '<input type="' + (multi ? 'checkbox' : 'radio') + '" name="' + group + '" aria-label="Correct answer" ' + (checked ? 'checked' : '') + ' />'
        + '<input type="text" class="o-text" placeholder="Answer option" value="' + esc(val || '') + '" /><button type="button" class="link-btn o-rm" aria-label="Remove option">✕</button>';
      row.querySelector('.o-rm').onclick = function () { opts.removeChild(row); };
      opts.appendChild(row);
    }
    function draw(list, picked) {
      opts.innerHTML = '';
      var tf = sel.value === 'truefalse';
      (tf ? ['True', 'False'] : list).forEach(function (o, i) { addOpt(o, picked.indexOf(i) > -1); });
      $$('.o-text', opts).forEach(function (t) { t.readOnly = tf; });
      $$('.o-rm', opts).forEach(function (b) { b.hidden = tf; });
      card.querySelector('.q-addopt').hidden = tf;
    }
    function current() {
      return { list: $$('.qopt', opts).map(function (r) { return r.querySelector('.o-text').value; }),
               picked: $$('.qopt', opts).map(function (r, i) { return r.querySelector('input:not(.o-text)').checked ? i : -1; }).filter(function (i) { return i > -1; }) };
    }
    draw(q.options || ['', ''], keys);
    sel.onchange = function () { var c = current(); draw(sel.value === 'truefalse' || c.list.every(function (x) { return x === 'True' || x === 'False'; }) && sel.value !== 'truefalse' ? ['', ''] : c.list, sel.value === 'multi' ? c.picked : c.picked.slice(0, 1)); };
    card.querySelector('.q-addopt').onclick = function () { addOpt('', false); };
    card.querySelector('.q-rm').onclick = function () { card.parentNode.removeChild(card); };
    return card;
  }
  function syncKind() {
    var k = $('#l_kind').value;
    $('#apassWrap').hidden = k !== 'assignment';
    $('#quizWrap').hidden = k !== '';
    $('#l_body_label').textContent = k === 'assignment' ? 'Instructions (HTML allowed)' : (k === 'discussion' ? 'Discussion prompt (HTML allowed)' : 'Body (HTML allowed)');
  }
  $('#l_kind').addEventListener('change', syncKind);
  function openLesson(id, moduleId) {
    lessonOpener = document.activeElement;
    lessonId = id || 0; lessonModuleId = moduleId || 0;
    $('#lessonMsg').textContent = '';
    $('#quizQuestions').innerHTML = ''; $('#q_pass').value = 70; $('#q_attempts').value = 0; $('#q_graded').checked = true;
    function fill(l) {
      $('#lessonModalTitle').textContent = id ? 'Edit item' : 'New item';
      $('#l_title').value = l.title || ''; $('#l_slug').value = l.slug || '';
      $('#l_duration').value = l.duration_min || 0; $('#l_body').value = l.body_html || '';
      $('#l_video').value = l.video_url || ''; $('#l_preview').checked = !!(l.is_preview && l.is_preview != '0');
      $('#l_kind').value = l.kind || ''; $('#l_weight').value = l.weight || 1;
      var a = null; try { a = l.assignment_json ? JSON.parse(l.assignment_json) : null; } catch (e) {}
      $('#l_apass').value = (a && a.pass) || 70;
      var quiz = null; try { quiz = l.quiz_json ? JSON.parse(l.quiz_json) : null; } catch (e) {}
      if (quiz && quiz.questions) {
        $('#q_pass').value = quiz.pass || 70; $('#q_attempts').value = quiz.attempts || 0;
        $('#q_graded').checked = quiz.graded === undefined ? true : !!quiz.graded;
        quiz.questions.forEach(function (q) { $('#quizQuestions').appendChild(qCard(q)); });
      }
      syncKind();
      $('#lessonModal').hidden = false;
      setTimeout(function () { var f = $('#l_title'); if (f) f.focus(); }, 30);
    }
    if (!id) { fill({}); return; }
    get('lesson_get', 'id=' + id).then(function (d) { fill((d && d.lesson) || {}); });
  }
  var lessonOpener = null;  // element to restore focus to on close
  function closeLesson() { $('#lessonModal').hidden = true; if (lessonOpener && lessonOpener.focus) { try { lessonOpener.focus(); } catch (e) {} } lessonOpener = null; }
  $('#lessonClose').onclick = closeLesson; $('#lessonCancel').onclick = closeLesson;
  // Click the backdrop (outside the card) or press Esc to close.
  $('#lessonModal').addEventListener('mousedown', function (e) { if (e.target === this) closeLesson(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('#lessonModal').hidden) closeLesson(); });
  $('#addQ').onclick = function () { $('#quizQuestions').appendChild(qCard()); };
  $('#lessonSave').onclick = function () {
    var title = $('#l_title').value.trim(); if (!title) { $('#lessonMsg').textContent = 'A title is required.'; return; }
    var kind = $('#l_kind').value;
    var questions = [];
    $$('#quizQuestions .qcard').forEach(function (card) {
      var type = card.querySelector('.q-type').value;
      var prompt = card.querySelector('.q-prompt').value.trim();
      var rows = $$('.qopt', card).map(function (r) { return { text: r.querySelector('.o-text').value.trim(), correct: r.querySelector('input:not(.o-text)').checked }; }).filter(function (o) { return o.text !== ''; });
      if (!prompt || rows.length < 2) return;
      var picked = []; rows.forEach(function (o, i) { if (o.correct) picked.push(i); });
      questions.push({ type: type, q: prompt, options: rows.map(function (o) { return o.text; }),
        answer: type === 'multi' ? picked : (picked[0] || 0), explain: card.querySelector('.q-explain').value.trim() });
    });
    var multiNoKey = questions.some(function (q) { return q.type === 'multi' && !q.answer.length; });
    if (multiNoKey) { $('#lessonMsg').textContent = 'Tick at least one correct answer on each several-answer question.'; return; }
    var payload = {
      id: lessonId, module_id: lessonModuleId, title: title, slug: $('#l_slug').value.trim(),
      body_html: $('#l_body').value, video_url: $('#l_video').value.trim(),
      duration_min: +$('#l_duration').value || 0, is_preview: $('#l_preview').checked,
      kind: kind, weight: +$('#l_weight').value || 1, assignment_pass: +$('#l_apass').value || 70
    };
    if (kind === '' && questions.length) payload.quiz = { pass: +$('#q_pass').value || 70, attempts: +$('#q_attempts').value || 0, graded: $('#q_graded').checked, questions: questions };
    $('#lessonSave').disabled = true;
    post('lesson_save', payload).then(function (d) {
      $('#lessonSave').disabled = false;
      if (d && d.ok) { toast('Saved', 'ok'); closeLesson(); renderCurriculum(curCourseSlug); }
      else $('#lessonMsg').textContent = (d && d.error) || 'Save failed.';
    }).catch(function () { $('#lessonSave').disabled = false; $('#lessonMsg').textContent = 'Network error.'; });
  };

  /* ── Module modal: title, what it covers, learning objectives ── */
  var modEditing = 0, modOpener = null;
  function openModule(m) {
    modOpener = document.activeElement; modEditing = m ? m.id : 0;
    $('#modModalTitle').textContent = m ? 'Edit module' : 'New module';
    $('#m_title').value = (m && m.title) || ''; $('#m_summary').value = (m && m.summary) || ''; $('#m_objectives').value = (m && m.objectives) || '';
    $('#modMsg').textContent = ''; $('#modModal').hidden = false;
    setTimeout(function () { $('#m_title').focus(); }, 30);
  }
  function closeModule() { $('#modModal').hidden = true; if (modOpener && modOpener.focus) { try { modOpener.focus(); } catch (e) {} } }
  $('#modClose').onclick = closeModule; $('#modCancel').onclick = closeModule;
  $('#modModal').addEventListener('mousedown', function (e) { if (e.target === this) closeModule(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('#modModal').hidden) closeModule(); });
  $('#modSave').onclick = function () {
    var t = $('#m_title').value.trim(); if (!t) { $('#modMsg').textContent = 'A title is required.'; return; }
    var p = { title: t, summary: $('#m_summary').value, objectives: $('#m_objectives').value };
    if (modEditing) p.id = modEditing; else p.course = curCourseSlug;
    $('#modSave').disabled = true;
    post('mod_save', p).then(function (r) {
      $('#modSave').disabled = false;
      if (r && r.ok) { closeModule(); renderCurriculum(curCourseSlug); } else $('#modMsg').textContent = (r && r.error) || 'Save failed.';
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
  get('session').then(function (d) {
    if (d && d.ok) { csrf = d.csrf || ''; showApp(true); route('overview'); }
    else showApp(false);
  }).catch(function () { showApp(false); });
})();
