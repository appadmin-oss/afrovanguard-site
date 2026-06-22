<!DOCTYPE html>
<html lang="en-NG" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Studio — Afrovanguard</title>
  <script>(function(){try{var t=localStorage.getItem('av.theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+SC:wght@600;700&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link href="/diary/diary.css" rel="stylesheet" />
  <link href="/admin/admin.css" rel="stylesheet" />
</head>
<body class="studio">
  <header class="studio-bar">
    <div class="studio-brand"><span class="afro">AFRO</span><span class="van">VANGUARD</span> <span class="studio-tag">Studio</span></div>
    <nav class="studio-tabs" id="tabs" hidden>
      <button class="tab active" data-tab="entries">Diary</button>
      <button class="tab" data-tab="academy">Academy</button>
      <button class="tab" data-tab="inbox">Inbox</button>
    </nav>
    <div class="studio-actions">
      <button class="icon-btn theme-toggle" id="themeToggle" aria-label="Toggle theme" title="Toggle theme">
        <svg class="sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        <svg class="moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
      </button>
      <a class="btn btn-outline btn-sm" href="/diary/" target="_blank" rel="noopener">View site ↗</a>
      <button class="btn btn-outline btn-sm" id="logoutBtn" hidden>Log out</button>
    </div>
  </header>

  <section class="studio-login" id="loginView">
    <div class="login-card">
      <h1>Afrovanguard Studio</h1>
      <p>Enter your admin token to manage the Diary &amp; Academy.</p>
      <form id="loginForm">
        <input type="password" id="tokenInput" placeholder="Admin token" autocomplete="current-password" required />
        <button class="btn btn-primary" type="submit">Enter</button>
      </form>
      <p class="login-msg" id="loginMsg"></p>
    </div>
  </section>

  <!-- DIARY LIST -->
  <main class="studio-main" id="entriesView" hidden>
    <div class="studio-head">
      <div><h1>Diary entries</h1><p class="muted" id="cloudinaryNote"></p></div>
      <button class="btn btn-primary" id="newBtn">+ New entry</button>
    </div>
    <div class="entry-list" id="entryList"></div>
  </main>

  <!-- DIARY EDITOR -->
  <main class="studio-main" id="editorView" hidden>
    <div class="studio-head">
      <button class="btn btn-outline btn-sm" id="backBtn">← All entries</button>
      <div class="editor-actions">
        <a class="btn btn-outline btn-sm" id="previewLink" target="_blank" rel="noopener" hidden>Preview ↗</a>
        <button class="btn btn-outline btn-sm" id="embedBtn" title="Insert an embed">＋ Embed</button>
        <button class="btn btn-outline btn-sm" id="qaBtn" title="Insert a question & answer">＋ Q&amp;A</button>
        <button class="btn btn-outline btn-sm" id="saveDraftBtn">Save draft</button>
        <button class="btn btn-primary btn-sm" id="publishBtn">Publish</button>
      </div>
    </div>
    <form id="editorForm" class="editor-grid">
      <div class="editor-main">
        <label class="fld"><span>Title</span><input id="f_title" placeholder="Headline of the entry" required /></label>
        <label class="fld"><span>Standfirst / excerpt</span><textarea id="f_dek" rows="2" placeholder="One-sentence summary shown on cards, search and social."></textarea></label>
        <label class="fld"><span>Body</span></label>
        <textarea id="f_body"></textarea>
      </div>
      <aside class="editor-side">
        <div class="side-card">
          <h3>Publishing</h3>
          <label class="fld"><span>Status</span><select id="f_status"><option value="draft">Draft</option><option value="published">Published</option></select></label>
          <label class="fld"><span>Style</span><select id="f_format"><option value="standard">Standard article</option><option value="qa">Q &amp; A / Interview</option><option value="feature">Feature (cinematic cover)</option></select></label>
          <label class="fld"><span>Slug</span><input id="f_slug" placeholder="auto-from-title" /></label>
          <label class="fld"><span>Published date</span><input id="f_date" type="date" /></label>
          <label class="fld checkbox"><input type="checkbox" id="f_featured" /> <span>Feature on the Diary home</span></label>
        </div>
        <div class="side-card">
          <h3>Classification</h3>
          <label class="fld"><span>Category</span><input id="f_category" list="catList" placeholder="e.g. Field Notes" /><datalist id="catList"></datalist></label>
          <label class="fld"><span>Author(s)</span><input id="f_authors" placeholder="The Afrovanguard Team" /></label>
          <label class="fld"><span>Read time (min, optional)</span><input id="f_read" type="number" min="1" placeholder="auto" /></label>
          <label class="fld"><span>Card gradient</span><select id="f_gradient">
            <option value="g-gold">Gold</option><option value="g-ink">Ink</option><option value="g-sunset">Sunset</option><option value="g-sky">Sky</option><option value="g-green">Green</option></select></label>
        </div>
        <div class="side-card">
          <h3>Cover image</h3>
          <div class="cover-preview" id="coverPreview"><span>No cover yet</span></div>
          <input type="file" id="coverFile" accept="image/*" hidden />
          <div class="cover-actions"><button type="button" class="btn btn-outline btn-sm" id="coverBtn">Upload cover</button><button type="button" class="btn btn-outline btn-sm" id="coverClear" hidden>Remove</button></div>
          <input id="f_cover" type="hidden" />
          <p class="muted tiny">Used on cards, the article hero, and the auto social card.</p>
        </div>
        <div class="side-card">
          <h3>Related entries</h3>
          <div id="relatedBox" class="related-box"></div>
        </div>
      </aside>
    </form>
  </main>

  <!-- ACADEMY LIST -->
  <main class="studio-main" id="academyView" hidden>
    <div class="studio-head">
      <div><h1>Academy programmes</h1><p class="muted">Courses shown on /academy/.</p></div>
      <button class="btn btn-primary" id="newCourseBtn">+ New programme</button>
    </div>
    <div class="entry-list" id="courseList"></div>
  </main>

  <!-- ACADEMY EDITOR -->
  <main class="studio-main" id="courseEditorView" hidden>
    <div class="studio-head">
      <button class="btn btn-outline btn-sm" id="acBackBtn">← All programmes</button>
      <div class="editor-actions">
        <a class="btn btn-outline btn-sm" id="acPreviewLink" target="_blank" rel="noopener" hidden>Preview ↗</a>
        <button class="btn btn-outline btn-sm" id="acSaveDraftBtn">Save draft</button>
        <button class="btn btn-primary btn-sm" id="acPublishBtn">Publish</button>
      </div>
    </div>
    <form id="courseForm" class="editor-grid">
      <div class="editor-main">
        <label class="fld"><span>Programme title</span><input id="c_title" placeholder="e.g. Techome" required /></label>
        <label class="fld"><span>Summary</span><textarea id="c_summary" rows="2" placeholder="One or two sentences for the catalogue card."></textarea></label>
        <label class="fld"><span>Description</span></label>
        <textarea id="c_body"></textarea>
        <label class="fld"><span>Outcomes (one per line)</span><textarea id="c_outcomes" rows="4" placeholder="What learners will gain…"></textarea></label>
      </div>
      <aside class="editor-side">
        <div class="side-card">
          <h3>Publishing</h3>
          <label class="fld"><span>Status</span><select id="c_status"><option value="draft">Draft</option><option value="published">Published</option></select></label>
          <label class="fld"><span>Slug</span><input id="c_slug" placeholder="auto-from-title" /></label>
          <label class="fld"><span>Sort order</span><input id="c_sort" type="number" value="0" /></label>
          <label class="fld checkbox"><input type="checkbox" id="c_featured" /> <span>Feature on the Academy home</span></label>
        </div>
        <div class="side-card">
          <h3>Access &amp; enrolment</h3>
          <label class="fld"><span>Access type</span><select id="c_access">
            <option value="open">Open · free, no account</option>
            <option value="tracked">Tracked · free, sign in to track</option>
            <option value="membership">Members only</option>
            <option value="paid">Paid programme</option>
          </select></label>
          <label class="fld" id="c_price_ngn_wrap" hidden><span>Price (₦, one-time)</span><input id="c_price_ngn" type="number" min="0" step="500" value="0" placeholder="e.g. 15000" /></label>
          <label class="fld"><span>Instructor email</span><input id="c_instructor" type="email" placeholder="instructor@afrovanguard.org.ng" /></label>
          <p class="muted" style="font-size:12px;margin:2px 0 0">They need an Academy account first. They’ll get a dashboard at <code>/academy/teach/</code>.</p>
        </div>
        <div class="side-card">
          <h3>Details</h3>
          <label class="fld"><span>Category</span><input id="c_category" placeholder="Technology / Creative / Leadership" /></label>
          <label class="fld"><span>Level</span><input id="c_level" placeholder="Beginner / Advanced" /></label>
          <label class="fld"><span>Format</span><select id="c_format"><option>In-person</option><option>Online</option><option>Hybrid</option></select></label>
          <label class="fld"><span>Duration</span><input id="c_duration" placeholder="12 weeks" /></label>
          <label class="fld"><span>Price</span><input id="c_price" placeholder="Free" /></label>
          <label class="fld"><span>Location</span><input id="c_location" placeholder="Alimosho, Lagos" /></label>
          <label class="fld"><span>Apply / programme URL</span><input id="c_cta" placeholder="https://…" /></label>
          <label class="fld"><span>Card gradient</span><select id="c_gradient">
            <option value="g-gold">Gold</option><option value="g-ink">Ink</option><option value="g-sunset">Sunset</option><option value="g-sky">Sky</option><option value="g-green">Green</option></select></label>
        </div>
        <div class="side-card">
          <h3>Cover image</h3>
          <div class="cover-preview" id="cCoverPreview"><span>No cover yet</span></div>
          <input type="file" id="cCoverFile" accept="image/*" hidden />
          <div class="cover-actions"><button type="button" class="btn btn-outline btn-sm" id="cCoverBtn">Upload cover</button><button type="button" class="btn btn-outline btn-sm" id="cCoverClear" hidden>Remove</button></div>
          <input id="c_cover" type="hidden" />
        </div>
      </aside>
    </form>
  </main>

  <!-- ACADEMY CURRICULUM -->
  <main class="studio-main" id="curriculumView" hidden>
    <div class="studio-head">
      <button class="btn btn-outline btn-sm" id="curBackBtn">← All programmes</button>
      <div><h1 id="curTitle">Curriculum</h1></div>
      <button class="btn btn-primary btn-sm" id="addModuleBtn">+ Add module</button>
    </div>
    <div id="moduleList" class="module-admin-list"></div>
  </main>

  <!-- LESSON EDITOR -->
  <main class="studio-main" id="lessonEditorView" hidden>
    <div class="studio-head">
      <button class="btn btn-outline btn-sm" id="lesBackBtn">← Curriculum</button>
      <div class="editor-actions"><button class="btn btn-primary btn-sm" id="lesSaveBtn">Save lesson</button></div>
    </div>
    <form id="lessonForm" class="editor-grid">
      <div class="editor-main">
        <label class="fld"><span>Lesson title</span><input id="le_title" placeholder="e.g. Thinking in systems" required /></label>
        <label class="fld"><span>Lesson content</span></label>
        <textarea id="le_body"></textarea>
        <div class="side-card" id="quizBuilder" style="margin-top:18px">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px">
            <h3 style="margin:0">Quiz <span class="muted" style="font-weight:600">(optional — gates completion)</span></h3>
            <label class="muted" style="display:flex;align-items:center;gap:6px;font-size:13px">Pass %
              <input id="qz_pass" type="number" min="1" max="100" value="70" style="width:72px;padding:8px;border:1px solid var(--divider);border-radius:6px;background:var(--bg);color:var(--ink)" /></label>
          </div>
          <div id="qz_questions"></div>
          <button type="button" class="btn btn-outline btn-sm" id="qz_add">+ Add question</button>
        </div>
      </div>
      <aside class="editor-side">
        <div class="side-card">
          <h3>Lesson settings</h3>
          <label class="fld"><span>Slug</span><input id="le_slug" placeholder="auto-from-title" /></label>
          <label class="fld"><span>Video embed URL (optional)</span><input id="le_video" placeholder="https://www.youtube.com/embed/…" /></label>
          <label class="fld"><span>Duration (minutes)</span><input id="le_duration" type="number" min="0" value="0" /></label>
          <label class="fld checkbox"><input type="checkbox" id="le_preview" /> <span>Free preview (open to all)</span></label>
        </div>
      </aside>
    </form>
  </main>

  <!-- INBOX -->
  <main class="studio-main" id="inboxView" hidden>
    <div class="studio-head"><div><h1>Inbox</h1><p class="muted">Academy applications, newest first.</p></div></div>
    <div class="inbox-list" id="inboxList"></div>
  </main>

  <div class="toast" id="toast"></div>
  <script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
  <script src="/admin/app.js" defer></script>
</body>
</html>
