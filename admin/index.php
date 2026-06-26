<!DOCTYPE html>
<html lang="en-NG" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Studio — Afrovanguard</title>
  <script>(function(){try{var t=localStorage.getItem('av.theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant:wght@600;700&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link href="/diary/diary.css" rel="stylesheet" />
  <link href="/admin/admin.css" rel="stylesheet" />
</head>
<body class="studio">
  <header class="studio-bar">
    <div class="studio-brand"><span class="brand-wordmark"><span class="wm-1">Afro</span><span class="wm-2">vanguard</span></span> <span class="studio-tag">Studio</span></div>
    <nav class="studio-tabs" id="tabs" hidden>
      <button class="tab active" data-tab="entries">Diary</button>
      <button class="tab" data-tab="moderation">Moderation<span class="tab-badge" id="modBadge" hidden></span></button>
      <button class="tab" data-tab="academy">Academy</button>
      <button class="tab" data-tab="members">Members</button>
      <button class="tab" data-tab="people">People</button>
      <button class="tab" data-tab="celebrations">Celebrations</button>
      <button class="tab" data-tab="communities">Communities</button>
      <button class="tab" data-tab="webhooks">Webhooks</button>
      <button class="tab" data-tab="system">System</button>
      <button class="tab" data-tab="signin">Sign-in</button>
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

  <!-- DIARY MODERATION (public journal submissions) -->
  <main class="studio-main" id="moderationView" hidden>
    <div class="studio-head"><div><h1>Diary moderation</h1><p class="muted">Public journal submissions from members, awaiting review. Approving publishes the entry to the Diary feed under the author’s name. Private and event entries are never shown here.</p></div></div>
    <div class="inbox-list" id="modList"></div>
  </main>

  <!-- MEMBERS (RBAC console) -->
  <main class="studio-main" id="membersView" hidden>
    <div class="studio-head">
      <div><h1>Members</h1><p class="muted">Accounts &amp; access levels. Verified <strong>@afrovanguard.org.ng</strong> sign-ins become members (mentorship access); promote to Mentor / Coordinator / Admin, suspend, or pre-create accounts.</p></div>
      <button class="btn btn-primary" id="memNewBtn">+ Add member</button>
    </div>
    <div class="mem-counts" id="memCounts"></div>
    <div class="side-card mem-create" id="memCreate" hidden>
      <h3>Add a member</h3>
      <div class="mem-create-grid">
        <label class="fld"><span>Name</span><input id="mc_name" placeholder="Full name" /></label>
        <label class="fld"><span>Email</span><input id="mc_email" type="email" placeholder="name@afrovanguard.org.ng" /></label>
        <label class="fld"><span>Access level</span><select id="mc_role"></select></label>
      </div>
      <div class="editor-actions"><button class="btn btn-outline btn-sm" id="memCreateCancel">Cancel</button><button class="btn btn-primary btn-sm" id="memCreateSave">Create account</button></div>
      <p class="muted tiny">Pre-creates a passwordless account; they sign in with Google (org email) to claim it.</p>
    </div>
    <div class="mem-filters">
      <div class="search-wrap mem-search">&#128269;<input type="search" id="memQ" placeholder="Search name or email…" aria-label="Search members" /></div>
      <select id="memRole" aria-label="Filter by access level"><option value="">All access levels</option></select>
      <select id="memStatus" aria-label="Filter by status"><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option></select>
    </div>
    <div class="entry-list" id="memList"></div>
    <h2 class="mem-audit-h">Recent activity</h2>
    <div class="inbox-list" id="memAudit"></div>
  </main>

  <!-- PEOPLE LIST -->
  <main class="studio-main" id="peopleView" hidden>
    <div class="studio-head">
      <div><h1>People</h1><p class="muted">Leadership, team &amp; volunteers shown on the About page and directory. Set the Volunteer of the Month and birthdays here.</p></div>
      <button class="btn btn-primary" id="newPersonBtn">+ Add person</button>
    </div>
    <div class="entry-list" id="peopleList"></div>
  </main>

  <!-- PERSON EDITOR -->
  <main class="studio-main" id="personEditView" hidden>
    <div class="studio-head">
      <button class="btn btn-outline btn-sm" id="pBackBtn">← All people</button>
      <div class="editor-actions">
        <button class="btn btn-outline btn-sm" id="pDeleteBtn" hidden>Delete</button>
        <button class="btn btn-primary btn-sm" id="pSaveBtn">Save person</button>
      </div>
    </div>
    <form id="personForm" class="editor-grid">
      <div class="editor-main">
        <label class="fld"><span>Full name</span><input id="p_name" placeholder="e.g. Omolola Adefuye" required /></label>
        <label class="fld"><span>Role / title</span><input id="p_role" placeholder="e.g. Volunteer, STS" /></label>
        <label class="fld"><span>Tagline (short line on the card)</span><input id="p_tagline" placeholder="e.g. A heart for people. A force for good." /></label>
        <label class="fld"><span>Bio</span><textarea id="p_bio" rows="4" placeholder="Short biography shown on the profile."></textarea></label>
        <label class="fld"><span>Location</span><input id="p_location" placeholder="e.g. Lagos, Nigeria" /></label>
      </div>
      <aside class="editor-side">
        <div class="side-card">
          <h3>Placement</h3>
          <label class="fld"><span>Group / tier</span><select id="p_tier">
            <option value="management">Management</option><option value="director">Director</option>
            <option value="patron">Patron</option><option value="ngv">NGV</option>
            <option value="ngg">NGG</option><option value="volunteer">Volunteer</option></select></label>
          <label class="fld checkbox"><input type="checkbox" id="p_featured" /> <span>Feature in Leadership spotlight</span></label>
          <label class="fld checkbox"><input type="checkbox" id="p_operations" /> <span>Operations team</span></label>
          <label class="fld checkbox"><input type="checkbox" id="p_active" checked /> <span>Active (visible)</span></label>
          <label class="fld"><span>Sort order</span><input id="p_position" type="number" value="0" /></label>
        </div>
        <div class="side-card">
          <h3>Photo</h3>
          <div class="cover-preview" id="pPhotoPreview"><span>No photo yet</span></div>
          <input type="file" id="pPhotoFile" accept="image/*" hidden />
          <div class="cover-actions"><button type="button" class="btn btn-outline btn-sm" id="pPhotoBtn">Upload photo</button><button type="button" class="btn btn-outline btn-sm" id="pPhotoClear" hidden>Remove</button></div>
          <input id="p_photo" type="hidden" />
        </div>
        <div class="side-card">
          <h3>Celebrations</h3>
          <label class="fld"><span>Birthday (auto-celebrated)</span><input id="p_birthday" type="text" placeholder="MM-DD e.g. 06-22" pattern="\d{2}-\d{2}" /></label>
          <label class="fld"><span>Volunteer of the Month (YYYY-MM)</span><input id="p_votm_month" type="text" placeholder="e.g. 2026-06" pattern="\d{4}-\d{2}" /></label>
          <label class="fld"><span>VOTM tribute / reason</span><textarea id="p_votm_reason" rows="2" placeholder="Why they were chosen."></textarea></label>
          <label class="fld"><span>VOTM quote</span><input id="p_votm_quote" placeholder="e.g. A heart for people." /></label>
        </div>
        <div class="side-card">
          <h3>Social links</h3>
          <label class="fld"><span>LinkedIn</span><input id="p_li" placeholder="https://linkedin.com/in/…" /></label>
          <label class="fld"><span>Twitter / X</span><input id="p_tw" placeholder="https://x.com/…" /></label>
          <label class="fld"><span>Instagram</span><input id="p_ig" placeholder="https://instagram.com/…" /></label>
          <label class="fld"><span>Website</span><input id="p_web" placeholder="https://…" /></label>
          <label class="fld"><span>Email</span><input id="p_email" type="email" placeholder="name@afrovanguard.org.ng" /></label>
        </div>
      </aside>
    </form>
  </main>

  <!-- CELEBRATIONS -->
  <main class="studio-main" id="celebrationsView" hidden>
    <div class="studio-head">
      <div><h1>Celebrations</h1><p class="muted">Auto-celebrated holidays &amp; dates. Built-ins run automatically; add your own with custom doodle art.</p></div>
      <button class="btn btn-primary" id="newCelBtn">+ Add celebration</button>
    </div>
    <div class="entry-list" id="celList"></div>
    <h2 style="font-family:var(--font-heading);font-size:22px;margin:28px 0 12px">Built-in calendar (automatic)</h2>
    <div class="entry-list" id="celBuiltins"></div>
  </main>

  <!-- CELEBRATION EDITOR -->
  <main class="studio-main" id="celEditView" hidden>
    <div class="studio-head">
      <button class="btn btn-outline btn-sm" id="celBackBtn">← All celebrations</button>
      <div class="editor-actions">
        <button class="btn btn-outline btn-sm" id="celDeleteBtn" hidden>Delete</button>
        <button class="btn btn-primary btn-sm" id="celSaveBtn">Save</button>
      </div>
    </div>
    <form id="celForm" class="editor-grid">
      <div class="editor-main">
        <label class="fld"><span>Name</span><input id="c_name" placeholder="e.g. Eid Mubarak" required /></label>
        <label class="fld"><span>Message</span><textarea id="c_message" rows="3" placeholder="The festive line shown in the banner."></textarea></label>
        <label class="fld"><span>Override a built-in (optional key)</span><input id="c_key" placeholder="e.g. christmas — leave blank for a new celebration" /></label>
      </div>
      <aside class="editor-side">
        <div class="side-card">
          <h3>When &amp; style</h3>
          <label class="fld"><span>Date (MM-DD)</span><input id="c_md" placeholder="12-25" pattern="\d{2}-\d{2}" required /></label>
          <label class="fld"><span>Scope</span><select id="c_scope"><option value="internal">Internal</option><option value="african">African</option><option value="international">International</option></select></label>
          <label class="fld"><span>Emoji</span><input id="c_emoji" placeholder="🎉" maxlength="4" /></label>
          <label class="fld"><span>Theme colour</span><input id="c_theme" type="color" value="#f3b416" /></label>
          <label class="fld checkbox"><input type="checkbox" id="c_enabled" checked /> <span>Enabled</span></label>
        </div>
        <div class="side-card">
          <h3>Doodle art (optional)</h3>
          <div class="cover-preview" id="cDoodlePreview"><span>No art — uses emoji + colour</span></div>
          <input type="file" id="cDoodleFile" accept="image/*" hidden />
          <div class="cover-actions"><button type="button" class="btn btn-outline btn-sm" id="cDoodleBtn">Upload art</button><button type="button" class="btn btn-outline btn-sm" id="cDoodleClear" hidden>Remove</button></div>
          <input id="c_doodle" type="hidden" />
          <p class="muted tiny">Shown in place of the logo on the day (Google-doodle style). Wide transparent PNG works best.</p>
        </div>
      </aside>
    </form>
  </main>

  <!-- COMMUNITIES (Google Chat Spaces / Groups shown in the member portal) -->
  <main class="studio-main" id="communitiesView" hidden>
    <div class="studio-head">
      <div><h1>Communities</h1><p class="muted">Google Chat Spaces &amp; Groups shown to members in the portal. Members open them in Google — already signed in.</p></div>
      <button class="btn btn-primary" id="newCommBtn">+ Add community</button>
    </div>
    <div class="entry-list" id="commList"></div>
  </main>

  <!-- COMMUNITY EDITOR -->
  <main class="studio-main" id="commEditView" hidden>
    <div class="studio-head">
      <button class="btn btn-outline btn-sm" id="commBackBtn">← All communities</button>
      <div class="editor-actions">
        <button class="btn btn-outline btn-sm" id="commDeleteBtn" hidden>Delete</button>
        <button class="btn btn-primary btn-sm" id="commSaveBtn">Save</button>
      </div>
    </div>
    <form id="commForm" class="editor-grid">
      <div class="editor-main">
        <label class="fld"><span>Name</span><input id="m_name" placeholder="e.g. All-hands" required /></label>
        <label class="fld"><span>Description</span><textarea id="m_description" rows="2" placeholder="A short line shown under the name."></textarea></label>
        <label class="fld"><span>Link (https://)</span><input id="m_url" type="url" placeholder="https://chat.google.com/room/… or a Groups URL" required /></label>
        <p class="muted tiny">Open the Space in Google Chat → ⋮ → Copy link; or use a Group’s groups.google.com URL.</p>
      </div>
      <aside class="editor-side">
        <div class="side-card">
          <h3>Display</h3>
          <label class="fld"><span>Sort order</span><input id="m_sort" type="number" value="0" /></label>
          <label class="fld checkbox"><input type="checkbox" id="m_enabled" checked /> <span>Shown to members</span></label>
        </div>
      </aside>
    </form>
  </main>

  <!-- WEBHOOKS (outbound integrations) -->
  <main class="studio-main" id="webhooksView" hidden>
    <div class="studio-head">
      <div><h1>Webhooks</h1><p class="muted">POST signed JSON to external systems when things happen on the site. Deliveries retry automatically (cron: <code>db/webhooks_run.php</code>).</p></div>
      <button class="btn btn-primary" id="newWhBtn">+ Add endpoint</button>
    </div>
    <div class="entry-list" id="whList"></div>
    <h2 style="font-family:var(--font-heading);font-size:22px;margin:28px 0 12px">Recent deliveries</h2>
    <div class="entry-list" id="whDeliveries"></div>
  </main>

  <!-- WEBHOOK EDITOR -->
  <main class="studio-main" id="whEditView" hidden>
    <div class="studio-head">
      <button class="btn btn-outline btn-sm" id="whBackBtn">← All webhooks</button>
      <div class="editor-actions">
        <button class="btn btn-outline btn-sm" id="whDeleteBtn" hidden>Delete</button>
        <button class="btn btn-primary btn-sm" id="whSaveBtn">Save</button>
      </div>
    </div>
    <form id="whForm" class="editor-grid">
      <div class="editor-main">
        <label class="fld"><span>Payload URL (https://)</span><input id="w_url" type="url" placeholder="https://example.com/hooks/afrovanguard" required /></label>
        <div class="fld"><span>Events</span><div id="w_events" class="wh-events"></div></div>
        <p class="muted tiny">Each POST is signed: <code>X-AV-Signature: sha256=HMAC_SHA256(timestamp + "." + body, secret)</code>, with <code>X-AV-Timestamp</code> &amp; <code>X-AV-Event</code>. Verify on your receiver.</p>
      </div>
      <aside class="editor-side">
        <div class="side-card">
          <h3>Settings</h3>
          <label class="fld"><span>Signing secret</span><input id="w_secret" placeholder="(auto-generated if left blank)" /></label>
          <label class="fld checkbox"><input type="checkbox" id="w_enabled" checked /> <span>Enabled</span></label>
        </div>
      </aside>
    </form>
  </main>

  <!-- SYSTEM / HEALTH (configuration & integration status) -->
  <main class="studio-main" id="systemView" hidden>
    <div class="studio-head">
      <div><h1>System</h1><p class="muted">Configuration &amp; integration health — <b style="color:#2ea043">green</b> ready · <b style="color:#e0a106">amber</b> optional/degraded · <b style="color:#d22">red</b> needs attention.</p></div>
      <button class="btn btn-outline btn-sm" id="sysRefreshBtn">Refresh</button>
    </div>
    <div id="sysHealth"></div>
  </main>

  <!-- SIGN-IN ILLUSTRATIONS (admin-managed + schedulable) -->
  <main class="studio-main" id="signinView" hidden>
    <div class="studio-head">
      <div><h1>Sign-in illustrations</h1><p class="muted">The art shown beside the <a href="/login" target="_blank" rel="noopener">sign-in form</a>. “Always” art rotates year-round; scheduled or holiday art takes over on its dates. One is shown per visitor session.</p></div>
    </div>

    <div class="side-card" style="max-width:680px;margin-bottom:28px">
      <h3>Add an illustration</h3>
      <div class="art-add-grid">
        <div>
          <div class="cover-preview" id="artPreview"><span>No image yet</span></div>
          <input type="file" id="artFile" accept="image/*" hidden />
          <div class="cover-actions"><button type="button" class="btn btn-outline btn-sm" id="artUploadBtn">Upload image</button></div>
          <input id="art_image" type="hidden" />
        </div>
        <div>
          <label class="fld"><span>Label (for your reference)</span><input id="art_label" placeholder="e.g. Christmas — children" /></label>
          <label class="fld"><span>Schedule</span>
            <select id="art_kind">
              <option value="always">Always (year-round rotation)</option>
              <option value="annual">Holiday — every year (MM-DD window)</option>
              <option value="range">Date range — one-off</option>
            </select>
          </label>
          <div id="artAnnual" hidden>
            <div class="art-dates">
              <label class="fld"><span>From (MM-DD)</span><input id="art_start_md" placeholder="12-24" pattern="\d{2}-\d{2}" /></label>
              <label class="fld"><span>To (MM-DD)</span><input id="art_end_md" placeholder="01-02" pattern="\d{2}-\d{2}" /></label>
            </div>
            <p class="muted tiny">Repeats every year. A wrap like 12-24 → 01-02 covers the festive season.</p>
          </div>
          <div id="artRange" hidden>
            <div class="art-dates">
              <label class="fld"><span>From</span><input id="art_start_date" type="date" /></label>
              <label class="fld"><span>To</span><input id="art_end_date" type="date" /></label>
            </div>
          </div>
          <label class="fld checkbox"><input type="checkbox" id="art_active" checked /> <span>Active</span></label>
          <button type="button" class="btn btn-primary btn-sm" id="artSaveBtn">Add illustration</button>
        </div>
      </div>
    </div>

    <div class="entry-list" id="artList"></div>
  </main>

  <div class="toast" id="toast"></div>
  <script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
  <script src="/admin/app.js" defer></script>
</body>
</html>
