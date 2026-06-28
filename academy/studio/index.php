<?php
/**
 * academy/studio/index.php — a dedicated admin for the Academy.
 *
 * A focused, professional management console for courses, curriculum, quizzes,
 * learners and applications. It talks to the existing admin API (/admin/api.php,
 * ADMIN_TOKEN-gated) — no new auth surface — but gives the Academy its own clean,
 * fast, user-friendly home instead of one cramped tab in the general Studio.
 */
declare(strict_types=1);
?><!DOCTYPE html>
<html lang="en-NG" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Academy Studio — Afrovanguard</title>
  <script>(function(){try{var t=localStorage.getItem('av.theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant:wght@600;700&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link href="/academy/studio/studio.css" rel="stylesheet" />
</head>
<body>

<!-- ░░ LOGIN ░░ -->
<section class="login" id="login" hidden>
  <form class="login-card" id="loginForm">
    <div class="login-brand"><span class="wm-1">Afro</span><span class="wm-2">vanguard</span> <span class="login-tag">Academy Studio</span></div>
    <p class="login-sub">Manage courses, curriculum, quizzes and learners.</p>
    <label class="fld"><span>Admin token</span>
      <input type="password" id="token" autocomplete="current-password" placeholder="Enter your admin token" required />
    </label>
    <button class="btn btn-primary" type="submit">Enter the studio</button>
    <p class="login-msg" id="loginMsg" role="alert" aria-live="polite"></p>
    <a class="login-back" href="/academy/">← Back to the Academy</a>
  </form>
</section>

<!-- ░░ APP SHELL ░░ -->
<div class="shell" id="app" hidden>
  <!-- Sidebar -->
  <aside class="side" id="side">
    <div class="side-brand">
      <span class="side-mark">A</span>
      <span class="side-name">Academy<small>Studio</small></span>
    </div>
    <nav class="side-nav" aria-label="Sections">
      <button class="nav-item is-active" data-view="overview"><svg viewBox="0 0 24 24"><path d="M3 13h8V3H3zM13 21h8V3h-8zM3 21h8v-6H3z"/></svg><span>Overview</span></button>
      <button class="nav-item" data-view="courses"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"/><path d="M4 9h16"/></svg><span>Courses</span></button>
      <button class="nav-item" data-view="curriculum"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h10"/></svg><span>Curriculum</span></button>
      <button class="nav-item" data-view="learners"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0112 0M16 3.5a3 3 0 010 5.8M21 20a6 6 0 00-5-5.9"/></svg><span>Learners</span></button>
      <button class="nav-item" data-view="applications"><svg viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h6"/></svg><span>Applications</span></button>
      <button class="nav-item" data-view="activity"><svg viewBox="0 0 24 24"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg><span>Activity</span></button>
    </nav>
    <div class="side-foot">
      <button class="ghost" id="themeToggle" title="Toggle theme"><svg class="t-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg><svg class="t-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1111.2 3 7 7 0 0021 12.8z"/></svg><span>Theme</span></button>
      <a class="ghost" href="/academy/" target="_blank" rel="noopener"><svg viewBox="0 0 24 24"><path d="M14 3h7v7M21 3l-9 9M19 14v5a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h5"/></svg><span>View Academy</span></a>
      <button class="ghost danger" id="logout"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg><span>Log out</span></button>
    </div>
  </aside>

  <!-- Main -->
  <div class="main">
    <header class="topbar">
      <button class="hamburger" id="hamburger" aria-label="Menu"><svg viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
      <div class="top-title"><h1 id="viewTitle">Overview</h1><p id="viewSub" class="muted"></p></div>
      <div class="top-actions" id="topActions"></div>
    </header>

    <div class="content" id="content">

      <!-- OVERVIEW -->
      <section class="view" id="view-overview">
        <div class="stat-grid" id="statGrid"></div>
        <div class="quick">
          <h2>Quick actions</h2>
          <div class="quick-row">
            <button class="qbtn" data-go="courses" data-new="1"><b>+ New course</b><span>Create a programme</span></button>
            <button class="qbtn" data-go="curriculum"><b>Build curriculum</b><span>Modules, lessons &amp; quizzes</span></button>
            <button class="qbtn" data-go="learners"><b>See learners</b><span>Progress &amp; certificates</span></button>
            <button class="qbtn" data-go="applications"><b>Applications</b><span>Lead-capture sign-ups</span></button>
          </div>
        </div>
        <div class="quick" style="margin-top:26px">
          <h2>Maintenance</h2>
          <div class="quick-row">
            <button class="qbtn" id="purgeDemo"><b>Remove demo data</b><span>Delete shipped sample articles &amp; placeholder lessons (real content is kept)</span></button>
          </div>
        </div>
      </section>

      <!-- COURSES LIST -->
      <section class="view" id="view-courses" hidden>
        <div class="table-wrap">
          <table class="tbl" id="coursesTbl">
            <thead><tr><th>Course</th><th>Status</th><th>Access</th><th class="num">Lessons</th><th class="num">Sort</th><th></th></tr></thead>
            <tbody id="coursesBody"><tr><td colspan="6" class="empty">Loading…</td></tr></tbody>
          </table>
        </div>
      </section>

      <!-- COURSE EDITOR -->
      <section class="view" id="view-course" hidden>
        <form id="courseForm" class="editor">
          <div class="editor-main">
            <label class="fld"><span>Title *</span><input id="c_title" required placeholder="Course title" /></label>
            <div class="grid2">
              <label class="fld"><span>Slug</span><input id="c_slug" placeholder="auto from title" /></label>
              <label class="fld"><span>Category</span><input id="c_category" placeholder="Technology" /></label>
            </div>
            <label class="fld"><span>Summary</span><textarea id="c_summary" rows="2" placeholder="One or two sentences shown on cards."></textarea></label>
            <label class="fld"><span>Description (HTML allowed)</span><textarea id="c_body" rows="8" placeholder="The course overview…"></textarea></label>
            <label class="fld"><span>Outcomes (one per line — each becomes a lesson when curriculum is seeded)</span><textarea id="c_outcomes" rows="4" placeholder="Read documentation independently&#10;Ship a small real project"></textarea></label>
          </div>
          <aside class="editor-side">
            <div class="card">
              <h3>Publish</h3>
              <label class="fld"><span>Status</span><select id="c_status"><option value="draft">Draft</option><option value="published">Published</option></select></label>
              <label class="chk"><input type="checkbox" id="c_featured" /> <span>Featured on the catalogue</span></label>
              <label class="fld"><span>Sort order</span><input id="c_sort" type="number" value="0" /></label>
            </div>
            <div class="card">
              <h3>Access</h3>
              <label class="fld"><span>Access type</span>
                <select id="c_access"><option value="open">Open — all lessons free</option><option value="tracked">Tracked — free, account-gated</option><option value="membership">Membership only</option><option value="paid">Paid (one-off)</option></select>
              </label>
              <label class="fld" id="priceWrap" hidden><span>Price (₦)</span><input id="c_price_ngn" type="number" min="0" value="0" /></label>
              <label class="fld"><span>Instructor email</span><input id="c_instructor" type="email" placeholder="instructor@afrovanguard.org.ng" /></label>
            </div>
            <div class="card">
              <h3>Details</h3>
              <div class="grid2">
                <label class="fld"><span>Level</span><input id="c_level" placeholder="All levels" /></label>
                <label class="fld"><span>Format</span><input id="c_format" placeholder="In-person" /></label>
              </div>
              <div class="grid2">
                <label class="fld"><span>Duration</span><input id="c_duration" placeholder="12 weeks" /></label>
                <label class="fld"><span>Price label</span><input id="c_pricelabel" placeholder="Free" /></label>
              </div>
              <label class="fld"><span>Cover image URL</span><input id="c_cover" placeholder="https://…" /></label>
              <label class="fld"><span>Card gradient</span>
                <select id="c_gradient"><option value="g-gold">Gold</option><option value="g-sky">Sky</option><option value="g-green">Green</option><option value="g-sunset">Sunset</option><option value="g-ink">Ink</option></select>
              </label>
              <label class="fld"><span>External CTA URL (optional)</span><input id="c_cta" placeholder="https://…" /></label>
            </div>
            <p class="editor-note" id="courseNote"></p>
          </aside>
        </form>
      </section>

      <!-- CURRICULUM -->
      <section class="view" id="view-curriculum" hidden>
        <div class="picker"><label>Course</label><select id="curCourse"></select><span class="pick-hint" id="curHint"></span></div>
        <div class="curriculum" id="curriculum"><p class="empty">Pick a course to build its curriculum.</p></div>
      </section>

      <!-- LEARNERS -->
      <section class="view" id="view-learners" hidden>
        <div class="picker"><label>Course</label><select id="learnCourse"></select><span class="pick-hint" id="learnHint"></span></div>
        <div class="roster-tools">
          <form id="enrolForm" class="enrol-form">
            <input id="enrolEmail" type="email" placeholder="Enrol by email (needs an Academy account)" autocomplete="off" />
            <button class="btn btn-outline btn-sm" type="submit">+ Enrol</button>
          </form>
          <button class="btn btn-ghost btn-sm" id="exportCsv" type="button">Export CSV</button>
        </div>
        <div class="table-wrap">
          <table class="tbl" id="rosterTbl">
            <thead><tr><th>Learner</th><th>Email</th><th class="num">Progress</th><th>Certified</th><th>Last active</th><th>Actions</th></tr></thead>
            <tbody id="rosterBody"><tr><td colspan="6" class="empty">Pick a course.</td></tr></tbody>
          </table>
        </div>
      </section>

      <!-- ACTIVITY / AUDIT -->
      <section class="view" id="view-activity" hidden>
        <p class="muted" style="margin:-6px 0 16px">Every state-changing admin action is recorded with the actor and originating IP. Read-only.</p>
        <div class="table-wrap">
          <table class="tbl" id="auditTbl">
            <thead><tr><th>When</th><th>Action</th><th>Target</th><th>Detail</th><th>Actor</th><th>IP</th></tr></thead>
            <tbody id="auditBody"><tr><td colspan="6" class="empty">Loading…</td></tr></tbody>
          </table>
        </div>
      </section>

      <!-- APPLICATIONS -->
      <section class="view" id="view-applications" hidden>
        <div class="table-wrap">
          <table class="tbl" id="appsTbl">
            <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Course</th><th>When</th></tr></thead>
            <tbody id="appsBody"><tr><td colspan="5" class="empty">Loading…</td></tr></tbody>
          </table>
        </div>
      </section>

    </div>
  </div>
</div>

<!-- Lesson editor modal -->
<div class="modal" id="lessonModal" hidden>
  <div class="modal-card">
    <div class="modal-head"><h2 id="lessonModalTitle">Lesson</h2><button class="icon-x" id="lessonClose" aria-label="Close">×</button></div>
    <form id="lessonForm" class="modal-body">
      <label class="fld"><span>Title *</span><input id="l_title" required /></label>
      <div class="grid2">
        <label class="fld"><span>Slug</span><input id="l_slug" placeholder="auto from title" /></label>
        <label class="fld"><span>Duration (min)</span><input id="l_duration" type="number" min="0" value="0" /></label>
      </div>
      <label class="fld"><span>Body (HTML allowed)</span><textarea id="l_body" rows="6"></textarea></label>
      <label class="fld"><span>Video URL (optional)</span><input id="l_video" placeholder="https://…" /></label>
      <label class="chk"><input type="checkbox" id="l_preview" /> <span>Free preview (open without an account)</span></label>

      <div class="quiz">
        <div class="quiz-head"><h3>Quiz <small>(optional — gates completion)</small></h3>
          <label class="quiz-pass">Pass %<input id="q_pass" type="number" min="1" max="100" value="70" /></label>
        </div>
        <div id="quizQuestions"></div>
        <button type="button" class="btn btn-outline btn-sm" id="addQ">+ Add question</button>
      </div>
      <p class="modal-msg" id="lessonMsg"></p>
    </form>
    <div class="modal-foot">
      <button class="btn btn-ghost" id="lessonCancel" type="button">Cancel</button>
      <button class="btn btn-primary" id="lessonSave" type="button">Save lesson</button>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toasts"></div>
<script src="/academy/studio/studio.js" defer></script>
</body>
</html>
