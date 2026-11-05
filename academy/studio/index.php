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
  <link href="/assets/site/fonts.css" rel="stylesheet" />
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
      <button class="nav-item" data-view="programmes"><svg viewBox="0 0 24 24"><path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/></svg><span>Programmes</span></button>
      <button class="nav-item" data-view="curriculum"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h10"/></svg><span>Curriculum</span></button>
      <button class="nav-item" data-view="learners"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0112 0M16 3.5a3 3 0 010 5.8M21 20a6 6 0 00-5-5.9"/></svg><span>Learners</span></button>
      <button class="nav-item" data-view="applications"><svg viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h6"/></svg><span>Applications</span></button>
      <button class="nav-item" data-view="activity"><svg viewBox="0 0 24 24"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg><span>Activity</span></button>
    </nav>
    <div class="side-foot">
      <button class="ghost" id="themeToggle" title="Toggle theme"><svg class="t-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg><svg class="t-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1111.2 3 7 7 0 0021 12.8z"/></svg><span>Theme</span></button>
      <a class="ghost" href="/academy/teach/grading" target="_blank" rel="noopener"><svg viewBox="0 0 24 24"><path d="M9 11l3 3 8-8M20 12v7a2 2 0 01-2 2H6a2 2 0 01-2-2V5a2 2 0 012-2h9"/></svg><span>Grading queue</span></a>
      <a class="ghost" href="/academy/" target="_blank" rel="noopener"><svg viewBox="0 0 24 24"><path d="M14 3h7v7M21 3l-9 9M19 14v5a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h5"/></svg><span>View Academy</span></a>
      <button class="ghost danger" id="logout"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg><span>Log out</span></button>
    </div>
  </aside>

  <!-- Main -->
  <div class="main">
    <header class="topbar">
      <button class="hamburger" id="hamburger" aria-label="Menu"><svg viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
      <div class="top-title"><h1 id="viewTitle">Overview</h1><p id="viewSub" class="muted"></p></div>
      <button type="button" class="top-search" id="searchOpen" aria-haspopup="dialog" aria-keyshortcuts="/ Control+K">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><span>Search courses, items, learners</span><kbd>/</kbd></button>
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
        <div class="list-tools" role="search">
          <label class="find"><span class="sr">Find a course</span><input type="search" id="courseFind" placeholder="Find a course" autocomplete="off" /></label>
          <div class="seg" role="group" aria-label="Show">
            <button type="button" data-cf="" aria-pressed="true">All</button><button type="button" data-cf="published" aria-pressed="false">Published</button><button type="button" data-cf="draft" aria-pressed="false">Drafts</button>
          </div>
          <label class="sortby"><span>Sort</span><select id="courseSort"><option value="updated">Recently edited</option><option value="title">Title</option><option value="learners">Most learners</option></select></label>
          <span class="muted list-count" id="courseCount" aria-live="polite"></span>
        </div>
        <div class="table-wrap">
          <table class="tbl" id="coursesTbl">
            <thead><tr><th>Course</th><th>Status</th><th>Access</th><th class="num">Items</th><th class="num">Learners</th><th></th></tr></thead>
            <tbody id="coursesBody"><tr><td colspan="6" class="empty">Loading…</td></tr></tbody>
          </table>
        </div>
      </section>

      <!-- COURSE EDITOR — the questions a course page answers, in the order a
           learner asks them. Nothing technical up front: the web address, the
           card colour and the catalogue order live under "Advanced". -->
      <section class="view" id="view-course" hidden>
        <form id="courseForm" class="editor" novalidate>
          <div class="editor-main">
            <section class="ed-step"><h2><span>1</span> The basics</h2>
              <label class="fld"><span>Course title</span><input id="c_title" required placeholder="e.g. Community Organising 101" autocomplete="off" /></label>
              <label class="fld"><span>One-line summary <em class="muted">— shown on the catalogue card</em></span><textarea id="c_summary" rows="2" maxlength="240" placeholder="Plan, run and report a community project people trust."></textarea></label>
              <div class="grid3">
                <label class="fld"><span>Track</span><input id="c_category" list="c_categories" placeholder="Leadership" autocomplete="off" /><datalist id="c_categories"></datalist></label>
                <label class="fld"><span>Level</span><select id="c_level"><option>All levels</option><option>Beginner</option><option>Intermediate</option><option>Advanced</option></select></label>
                <label class="fld"><span>Language</span><input id="c_language" placeholder="English" /></label>
              </div>
              <div class="cover-row">
                <div class="cover-prev" id="c_coverPrev" aria-hidden="true"></div>
                <div><b>Cover image</b><p class="muted fld-help">A wide photo, at least 1200 × 630.</p>
                  <button type="button" class="btn btn-outline btn-sm" data-upload="c_cover" data-accept="image/*">Upload a cover</button>
                  <button type="button" class="link-btn" id="c_coverClear" hidden>Remove</button></div>
                <input id="c_cover" type="hidden" />
              </div>
            </section>
            <section class="ed-step"><h2><span>2</span> What learners get</h2>
              <label class="fld"><span>What they’ll learn <em class="muted">(one per line)</em></span><textarea id="c_outcomes" rows="4" placeholder="Run a meeting people want to come to&#10;Write a budget a funder trusts"></textarea></label>
              <div class="grid2">
                <label class="fld"><span>Skills they’ll gain <em class="muted">(one per line)</em></span><textarea id="c_skills" rows="4" placeholder="Public speaking&#10;Budgeting"></textarea></label>
                <label class="fld"><span>Before they start <em class="muted">(one per line — leave empty if none)</em></span><textarea id="c_prereqs" rows="4" placeholder="A phone or laptop&#10;Basic English"></textarea></label>
              </div>
              <label class="fld"><span>About the course</span><textarea id="c_body" rows="8"></textarea></label>
            </section>
          </div>
          <aside class="editor-side">
            <div class="card">
              <h3>Who can take it</h3>
              <label class="fld"><span>Access</span>
                <select id="c_access"><option value="open">Free — anyone, no account</option><option value="tracked">Free — with an account (progress saved)</option><option value="membership">Members only</option><option value="paid">Paid</option></select>
              </label>
              <label class="fld" id="priceWrap" hidden><span>Price</span><span class="suffix">₦<input id="c_price_ngn" type="number" min="0" value="0" /></span></label>
              <label class="fld"><span>Instructor’s email <em class="muted">(optional)</em></span><input id="c_instructor" type="email" placeholder="name@afrovanguard.org.ng" /></label>
            </div>
            <div class="card">
              <h3>Certificate &amp; pace</h3>
              <label class="fld"><span>Pass mark</span><span class="suffix"><input id="c_pass_mark" type="number" min="0" max="100" value="70" />%</span></label>
              <p class="muted fld-help">The overall grade needed, with every graded quiz and assignment passed. A course with no graded work certifies on finishing.</p>
              <label class="fld"><span>Suggested length</span><span class="suffix"><input id="c_pace_weeks" type="number" min="0" max="104" value="0" />weeks <em class="muted">(0 = a week a module)</em></span></label>
              <label class="chk"><input type="checkbox" id="c_sequential" /> <span>Open items in order — finish one to unlock the next</span></label>
            </div>
            <div class="card">
              <h3>Publish</h3>
              <label class="fld"><span>Status</span><select id="c_status"><option value="draft">Draft — only you can see it</option><option value="published">Published — on the catalogue</option></select></label>
              <label class="chk"><input type="checkbox" id="c_featured" /> <span>Feature it at the top of the catalogue</span></label>
            </div>
            <details class="card ed-more ed-adv"><summary>Advanced</summary>
              <label class="fld"><span>Web address</span><input id="c_slug" placeholder="made from the title" /></label>
              <div class="grid2">
                <label class="fld"><span>Format</span><input id="c_format" placeholder="Online" /></label>
                <label class="fld"><span>Duration label</span><input id="c_duration" placeholder="6 weeks" /></label>
              </div>
              <div class="grid2">
                <label class="fld"><span>Price label</span><input id="c_pricelabel" placeholder="Free" /></label>
                <label class="fld"><span>Catalogue order</span><input id="c_sort" type="number" value="0" /></label>
              </div>
              <label class="fld"><span>Card colour (no cover)</span>
                <select id="c_gradient"><option value="g-gold">Gold</option><option value="g-sky">Sky</option><option value="g-green">Green</option><option value="g-sunset">Sunset</option><option value="g-ink">Ink</option></select>
              </label>
              <label class="fld"><span>External sign-up link</span><input id="c_cta" placeholder="https://…" /></label>
            </details>
            <p class="editor-note" id="courseNote"></p>
          </aside>
        </form>
      </section>

      <!-- PROGRAMMES -->
      <section class="view" id="view-programmes" hidden>
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th>Programme</th><th class="num">Courses</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="progBody"><tr><td colspan="4" class="empty">Loading…</td></tr></tbody>
          </table>
        </div>
      </section>

      <!-- PROGRAMME EDITOR -->
      <section class="view" id="view-programme" hidden>
        <form id="progForm" class="editor">
          <div class="editor-main">
            <label class="fld"><span>Title *</span><input id="p_title" required placeholder="Programme title" /></label>
            <label class="fld"><span>Slug</span><input id="p_slug" placeholder="auto from title" /></label>
            <label class="fld"><span>Summary</span><textarea id="p_summary" rows="2"></textarea></label>
            <label class="fld"><span>Description (HTML allowed)</span><textarea id="p_body" rows="6"></textarea></label>
            <fieldset class="fld prog-courses"><legend>Courses in this programme, in order</legend>
              <p class="muted">Tick the courses, then number them. Learners earn the programme certificate once every course is certified.</p>
              <div id="progCourses"></div>
            </fieldset>
          </div>
          <aside class="editor-side">
            <div class="card">
              <h3>Publish</h3>
              <label class="fld"><span>Status</span><select id="p_status"><option value="draft">Draft</option><option value="published">Published</option></select></label>
              <label class="fld"><span>Sort order</span><input id="p_sort" type="number" value="0" /></label>
              <label class="fld"><span>Cover image URL</span><input id="p_cover" placeholder="https://…" /></label>
            </div>
          </aside>
        </form>
      </section>

      <!-- CURRICULUM -->
      <section class="view" id="view-curriculum" hidden>
        <div class="picker"><label>Course</label><select id="curCourse"></select><span class="pick-hint" id="curHint"></span></div>
        <section class="ready" id="readiness" aria-live="polite" hidden></section>
        <div class="list-tools" role="search" id="outlineTools" hidden>
          <label class="find"><span class="sr">Find in this outline</span><input type="search" id="outlineFind" placeholder="Find an item in this course" autocomplete="off" /></label>
          <button type="button" class="btn btn-ghost btn-sm" id="foldAll">Collapse all</button>
          <a class="btn btn-outline btn-sm" id="outlinePreview" href="/academy/" target="_blank" rel="noopener">Preview course ↗</a>
          <span class="muted list-count" id="outlineCount" aria-live="polite"></span>
        </div>
        <div class="curriculum" id="curriculum"><p class="empty">Pick a course to build its curriculum.</p></div>
      </section>

      <!-- LEARNERS -->
      <section class="view" id="view-learners" hidden>
        <div class="picker"><label>Course</label><select id="learnCourse"></select><span class="pick-hint" id="learnHint"></span></div>
        <div class="list-tools" role="search">
          <label class="find"><span class="sr">Find a learner</span><input type="search" id="learnFind" placeholder="Find by name or email" autocomplete="off" /></label>
          <div class="seg" role="group" aria-label="Show">
            <button type="button" data-lf="" aria-pressed="true">All</button><button type="button" data-lf="not_started" aria-pressed="false">Not started</button><button type="button" data-lf="in_progress" aria-pressed="false">In progress</button><button type="button" data-lf="finished" aria-pressed="false">Finished</button><button type="button" data-lf="certified" aria-pressed="false">Certified</button>
          </div>
        </div>
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

<!-- Item editor: one screen per kind of item. The kind is chosen when the item
     is added; only that kind's fields are shown. Nothing technical is asked up
     front — the web address, grade weight and time are worked out, and live
     under "Advanced" for the rare change. -->
<div class="modal" id="lessonModal" role="dialog" aria-modal="true" aria-labelledby="lessonModalTitle" hidden>
  <div class="modal-card modal-card--wide">
    <div class="modal-head">
      <div><span class="kind-chip" id="l_kindChip">Reading</span><h2 id="lessonModalTitle">New item</h2></div>
      <button class="icon-x" id="lessonClose" aria-label="Close">×</button>
    </div>
    <div class="draft-bar" id="draftBar" role="status" hidden>
      <span>You have unsaved changes from <b id="draftWhen"></b>.</span>
      <button type="button" class="btn btn-primary btn-sm" id="draftRestore">Restore them</button>
      <button type="button" class="btn btn-ghost btn-sm" id="draftDiscard">Discard</button>
    </div>
    <form id="lessonForm" class="modal-body" novalidate>
      <label class="fld"><span>Title</span><input id="l_title" required placeholder="e.g. What a mandate is" autocomplete="off" /></label>

      <!-- Video -->
      <section class="ed-sec" data-for="video">
        <h3>Video</h3>
        <div class="src-pick" id="videoPick">
          <button type="button" class="src-card" data-upload="l_video" data-accept="video/mp4,video/webm,video/quicktime" data-media="video">
            <b>Upload a video file</b><span>MP4 or WebM from your computer</span></button>
          <div class="src-card src-card--link"><b>Or paste a link</b><span>YouTube, Vimeo, Loom, Wistia or Google Drive</span>
            <input id="l_video" type="url" inputmode="url" placeholder="https://youtu.be/…" autocomplete="off" /></div>
        </div>
        <p class="file-ok" id="videoNow" hidden></p>
        <div id="captionsRow" hidden>
          <button type="button" class="btn btn-outline btn-sm" data-upload="l_captions" data-accept=".vtt,text/vtt">Add captions (.vtt)</button>
          <span class="muted" id="captionsNow"></span>
          <input id="l_captions" type="hidden" />
        </div>
        <details class="ed-more"><summary>Add a transcript</summary>
          <textarea id="l_transcript" rows="5" placeholder="Paste what is said in the video. Learners can read it beside the video."></textarea>
        </details>
      </section>

      <!-- Text: the lesson itself, notes under a video, instructions, the prompt, a quiz intro -->
      <section class="ed-sec" data-for="reading video assignment discussion quiz">
        <h3 id="l_body_label">Lesson text</h3>
        <p class="muted fld-help" id="l_body_help">Write as you would in a document. Paste a YouTube or Loom link on its own line to embed it.</p>
        <textarea id="l_body" rows="10"></textarea>
      </section>

      <!-- Quiz -->
      <section class="ed-sec" data-for="quiz">
        <h3>Questions</h3>
        <div class="mode-pick" role="radiogroup" aria-label="Does this quiz count toward the grade?">
          <label class="mode-card"><input type="radio" name="q_mode" value="practice" /><b>Practice check</b><span>Learners see the answers. Doesn’t count.</span></label>
          <label class="mode-card"><input type="radio" name="q_mode" value="graded" checked /><b>Graded quiz</b><span>Must be passed for the certificate.</span></label>
        </div>
        <div class="grid2" id="gradedOpts">
          <label class="fld"><span>Pass mark</span><span class="suffix"><input id="q_pass" type="number" min="1" max="100" value="70" />%</span></label>
          <label class="fld"><span>Attempts allowed</span><select id="q_attempts"><option value="0">Unlimited</option><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="5">5</option></select></label>
        </div>
        <input id="q_graded" type="checkbox" hidden checked />
        <div id="quizQuestions"></div>
        <button type="button" class="btn btn-outline btn-sm" id="addQ">+ Add a question</button>
      </section>

      <!-- Assignment -->
      <section class="ed-sec" data-for="assignment">
        <h3>Grading</h3>
        <label class="fld"><span>Pass mark</span><span class="suffix"><input id="l_apass" type="number" min="1" max="100" value="70" />%</span></label>
        <p class="muted fld-help">Learners submit text or a link. You grade it from the grading queue and can return it for changes.</p>
      </section>

      <!-- Resources, for any item -->
      <details class="ed-more" id="resDetails"><summary>Attach files or links <span class="muted" id="resCount"></span></summary>
        <div id="resList"></div>
        <button type="button" class="btn btn-ghost btn-sm" id="addRes">+ Add a file or link</button>
      </details>

      <label class="chk"><input type="checkbox" id="l_preview" /> <span>Free preview — anyone can open it without signing in</span></label>

      <details class="ed-more ed-adv"><summary>Advanced</summary>
        <div class="grid2">
          <label class="fld"><span>Type</span>
            <select id="l_uikind"><option value="reading">Reading</option><option value="video">Video</option><option value="quiz">Quiz</option><option value="assignment">Assignment</option><option value="discussion">Discussion</option></select></label>
          <label class="fld"><span>Time it takes (min) <em class="muted">— 0 works it out</em></span><input id="l_duration" type="number" min="0" value="0" /></label>
          <label class="fld"><span>Share of the grade <em class="muted">(graded items)</em></span><input id="l_weight" type="number" min="1" max="100" value="1" /></label>
          <label class="fld"><span>Web address</span><input id="l_slug" placeholder="made from the title" autocomplete="off" /></label>
        </div>
        <select id="l_kind" hidden><option value=""></option><option value="assignment"></option><option value="discussion"></option></select>
      </details>
      <p class="muted fld-help" id="uploadMsg" role="status" aria-live="polite"></p>
      <p class="modal-msg" id="lessonMsg" role="alert"></p>
    </form>
    <div class="modal-foot">
      <span class="muted save-state" id="saveState" aria-live="polite"></span>
      <button class="btn btn-ghost" id="lessonCancel" type="button">Cancel</button>
      <button class="btn btn-primary" id="lessonSave" type="button">Save</button>
    </div>
  </div>
</div>

<!-- Module editor: its introduction, and how to start it -->
<div class="modal" id="modModal" role="dialog" aria-modal="true" aria-labelledby="modModalTitle" hidden>
  <div class="modal-card">
    <div class="modal-head"><h2 id="modModalTitle">Module</h2><button class="icon-x" id="modClose" aria-label="Close">×</button></div>
    <form id="modForm" class="modal-body" novalidate>
      <label class="fld"><span>Module title</span><input id="m_title" required placeholder="e.g. Week 1 — Why organise" autocomplete="off" /></label>
      <label class="fld"><span>Introduction <em class="muted">— shown to learners at the start of the module</em></span><textarea id="m_summary" rows="3" placeholder="In this module you will…"></textarea></label>
      <label class="fld"><span>By the end, learners will be able to… <em class="muted">(one per line)</em></span><textarea id="m_objectives" rows="4" placeholder="Explain what a mandate is&#10;Plan a first meeting"></textarea></label>
      <fieldset class="fld" id="m_startWrap"><legend>Start with</legend>
        <div class="mode-pick">
          <label class="mode-card"><input type="radio" name="m_start" value="week" checked /><b>A standard week</b><span>Two lessons, a practice check and a graded quiz, ready to fill in.</span></label>
          <label class="mode-card"><input type="radio" name="m_start" value="empty" /><b>An empty module</b><span>Add each item yourself.</span></label>
        </div>
      </fieldset>
      <p class="modal-msg" id="modMsg" role="alert"></p>
    </form>
    <div class="modal-foot">
      <button class="btn btn-ghost" id="modCancel" type="button">Cancel</button>
      <button class="btn btn-primary" id="modSave" type="button">Save module</button>
    </div>
  </div>
</div>

<!-- Search: one box for the whole Academy -->
<div class="modal palette" id="searchModal" role="dialog" aria-modal="true" aria-label="Search the Academy" hidden>
  <div class="modal-card palette-card">
    <label class="palette-in"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <span class="sr">Search</span><input id="searchInput" type="search" placeholder="Search courses, items and learners" autocomplete="off" aria-controls="searchResults" /></label>
    <div id="searchResults" class="palette-res" role="listbox" aria-label="Results"><p class="muted palette-hint">Type a course, a lesson title, or a learner’s name or email.</p></div>
    <p class="muted palette-foot"><kbd>↑</kbd><kbd>↓</kbd> to move · <kbd>Enter</kbd> to open · <kbd>Esc</kbd> to close</p>
  </div>
</div>

<div class="toast-wrap" id="toasts"></div>
<script src="/academy/studio/studio.js" defer></script>
</body>
</html>
