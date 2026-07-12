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
    <div class="studio-brand">
      <button class="studio-burger" id="studioBurger" aria-label="Toggle sections" aria-expanded="false" hidden><span></span><span></span><span></span></button>
      <span class="brand-wordmark"><span class="wm-1">Afro</span><span class="wm-2">vanguard</span></span> <span class="studio-tag">Studio</span>
    </div>
    <div class="studio-actions">
      <button class="icon-btn theme-toggle" id="themeToggle" aria-label="Toggle theme" title="Toggle theme">
        <svg class="sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        <svg class="moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
      </button>
      <a class="btn btn-outline btn-sm" href="/diary/" target="_blank" rel="noopener">View site ↗</a>
      <button class="btn btn-outline btn-sm" id="logoutBtn" hidden>Log out</button>
    </div>
  </header>

  <!-- Left sidebar — grouped sections (data-tab preserved; driven by app.js) -->
  <aside class="studio-side" id="studioSide" aria-label="Studio sections">
    <nav class="studio-nav" id="tabs" hidden>
      <div class="nav-group">
        <button class="tab active" data-tab="overview"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span>Overview</span></button>
      </div>
      <div class="nav-group">
        <p class="nav-group-h">Content</p>
        <button class="tab" data-tab="entries"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h10a2 2 0 0 1 2 2v14H6a2 2 0 0 1-2-2V4z"/><path d="M16 6h4v12a2 2 0 0 1-2 2"/><path d="M8 8h4M8 12h4"/></svg><span>Diary</span></button>
        <button class="tab" data-tab="moderation"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.2-2.9 7.3-7 8-4.1-.7-7-3.8-7-8V6l7-3z"/><path d="M9 12l2 2 4-4"/></svg><span>Moderation</span><span class="tab-badge" id="modBadge" hidden></span></button>
        <button class="tab" data-tab="inbox"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h5l2 3h4l2-3h5"/><path d="M5 5h14a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg><span>Inbox</span></button>
      </div>
      <div class="nav-group">
        <p class="nav-group-h">Academy</p>
        <button class="tab" data-tab="academy"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4L2 9l10 5 10-5-10-5z"/><path d="M6 11v5c0 1.3 2.7 2.5 6 2.5s6-1.2 6-2.5v-5"/><path d="M22 9v5"/></svg><span>Academy</span></button>
      </div>
      <div class="nav-group">
        <p class="nav-group-h">People</p>
        <button class="tab" data-tab="members"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.6 2.7-5.5 6-5.5"/><path d="M15 12l2 2 4-4"/></svg><span>Members</span></button>
        <button class="tab" data-tab="people"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.3 2.9-5.2 6.5-5.2s6.5 1.9 6.5 5.2"/><path d="M17 8.2a3 3 0 0 1 0 5.6"/><path d="M19 20c0-2.2-1-3.7-2.6-4.6"/></svg><span>People</span></button>
        <button class="tab" data-tab="mentorship"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="9" r="2.6"/><circle cx="16.5" cy="8" r="2.2"/><path d="M3.5 19c0-2.8 2.1-4.3 4.5-4.3s4.5 1.5 4.5 4.3"/><path d="M14.5 14.4c2-.3 4 .9 4 3.1"/><path d="M12 13l2.5-2.4"/></svg><span>Mentorship</span></button>
        <button class="tab" data-tab="celebrations"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="9" width="18" height="12" rx="1.5"/><path d="M3 13h18M12 9v12"/><path d="M12 9c-1.5-2.5-5-2.5-5-.5 0 1 .8 1.5 2 1.5h3zM12 9c1.5-2.5 5-2.5 5-.5 0 1-.8 1.5-2 1.5h-3z"/></svg><span>Celebrations</span></button>
      </div>
      <div class="nav-group">
        <p class="nav-group-h">Community</p>
        <button class="tab" data-tab="communities"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2z"/><path d="M8 9h8M8 12h5"/></svg><span>Communities</span></button>
      </div>
      <div class="nav-group">
        <p class="nav-group-h">System</p>
        <button class="tab" data-tab="webhooks"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 13.5a4 4 0 0 0 5.7 0l2.8-2.8a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14.5 10.5a4 4 0 0 0-5.7 0l-2.8 2.8a4 4 0 0 0 5.7 5.7l1-1"/></svg><span>Webhooks</span></button>
        <button class="tab" data-tab="signin"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L20 3"/><path d="M16 5l3 3M18.5 7.5l1.5 1.5"/></svg><span>Sign-in</span></button>
        <button class="tab" data-tab="system"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 13a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 0 1-4 0v-.1A1.7 1.7 0 0 0 7 19.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H3a2 2 0 0 1 0-4h.1A1.7 1.7 0 0 0 4.7 7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10 4.6V4a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9z"/></svg><span>System</span></button>
        <button class="tab" data-tab="activity"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h4l2 6 4-14 2 8h6"/></svg><span>Activity</span></button>
        <button class="tab" data-tab="database"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg><span>Database</span></button>
        <button class="tab" data-tab="design"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2a10 10 0 0 0 0 20c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.2 0-1 .8-1.5 1.7-1.5H17a5 5 0 0 0 5-5c0-5-4.5-9-10-9z"/></svg><span>Design</span></button>
        <button class="tab" data-tab="admins"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="3.2"/><path d="M22 11l-2.5 2.5L18 12"/></svg><span>Team &amp; roles</span></button>
      </div>
      <div class="nav-group">
        <p class="nav-group-h">Help</p>
        <button class="tab" data-tab="guide"><svg class="t-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 4.5 1.5c0 1.5-2 2-2 3"/><line x1="12" y1="17" x2="12" y2="17"/></svg><span>Guide</span></button>
      </div>
    </nav>
  </aside>
  <div class="studio-scrim" id="studioScrim" hidden></div>

  <section class="studio-login" id="loginView">
    <div class="login-card">
      <div class="login-brand"><span class="brand-wordmark"><span class="wm-1">Afro</span><span class="wm-2">vanguard</span></span> <span class="studio-tag">Studio</span></div>
      <h1>Sign in to Studio</h1>
      <p>Enter your admin access token to manage the Diary, Academy &amp; site.</p>
      <form id="loginForm" novalidate>
        <label class="login-field">
          <span class="login-label">Admin token</span>
          <span class="login-input-wrap">
            <input type="password" id="tokenInput" placeholder="••••••••••••••••" autocomplete="current-password" autofocus required />
            <button type="button" class="login-eye" id="tokenToggle" aria-label="Show token" aria-pressed="false">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </span>
        </label>
        <button class="btn btn-primary login-submit" type="submit" id="loginBtn">Sign in</button>
      </form>
      <p class="login-msg" id="loginMsg" role="alert"></p>
      <p class="login-foot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg> Access is rate-limited and logged.</p>
    </div>
  </section>

  <!-- DIARY LIST -->
  <!-- OVERVIEW (landing dashboard) -->
  <main class="studio-main" id="overviewView" hidden>
    <div class="studio-head">
      <div><h1>Overview</h1><p class="muted">A snapshot of the site — content, people and delivery health at a glance.</p></div>
      <button class="btn btn-outline btn-sm" id="ovRefreshBtn">Refresh</button>
    </div>

    <div class="ov-alert" id="ovMailAlert" hidden></div>

    <div class="ov-grid" id="ovGrid"></div>

    <div class="ov-row">
      <div class="ov-panel">
        <h3>Quick actions</h3>
        <div class="ov-actions">
          <button class="btn btn-primary btn-sm" data-go="entries" data-then="new">+ New diary entry</button>
          <button class="btn btn-outline btn-sm" data-go="moderation">Review moderation</button>
          <button class="btn btn-outline btn-sm" data-go="academy">Manage Academy</button>
          <button class="btn btn-outline btn-sm" data-go="members">View members</button>
        </div>
      </div>
      <div class="ov-panel">
        <h3>Delivery &amp; system</h3>
        <p class="ov-health" id="ovHealth"><span class="muted">Checking…</span></p>
        <button class="btn btn-outline btn-sm" data-go="system">Open System health →</button>
      </div>
    </div>
  </main>

  <main class="studio-main" id="entriesView" hidden>
    <div class="studio-head">
      <div><h1>Diary entries</h1><p class="muted" id="cloudinaryNote"></p></div>
      <div class="editor-actions">
        <button class="btn btn-outline" id="wpImportBtn" title="Migrate posts from a WordPress export file">↧ Import from WordPress</button>
        <button class="btn btn-primary" id="newBtn">+ New entry</button>
      </div>
    </div>
    <div class="entry-list" id="entryList"></div>
  </main>

  <!-- WordPress import modal -->
  <div class="modal-backdrop" id="wpModal" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="wpModalTitle">
      <div class="modal-top"><h2 id="wpModalTitle">Import from WordPress</h2><button class="icon-x" id="wpClose" aria-label="Close">×</button></div>
      <div class="modal-body">
        <p class="muted" style="margin:0 0 14px">In WordPress: <strong>Tools → Export → Posts → Download Export File</strong>, then upload that <code>.xml</code> here. Post slugs are preserved, so old <code>/blog/…</code> links keep working. Re-importing is safe — entries update by slug instead of duplicating.</p>
        <label class="fld"><span>WordPress export file (.xml)</span>
          <input type="file" id="wpFile" accept=".xml,text/xml,application/xml" />
        </label>
        <div class="grid2">
          <label class="fld"><span>Publish state</span>
            <select id="wpStatus"><option value="as-is">Keep WordPress state (publish/draft)</option><option value="draft">Import all as drafts</option><option value="published">Publish all</option></select>
          </label>
          <label class="fld checkbox" style="align-self:end"><input type="checkbox" id="wpPages" /> <span>Include pages too</span></label>
        </div>
        <p class="muted tiny" style="margin:6px 0 0">Tip: keep your <code>wp-content/uploads/</code> folder after removing WordPress so in-article images keep loading.</p>
        <div id="wpResult" class="wp-result" hidden></div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-outline" id="wpDryRun">Preview (dry run)</button>
        <button class="btn btn-primary" id="wpRun">Import</button>
      </div>
    </div>
  </div>

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
          <h3>Audio narration</h3>
          <div class="audio-preview" id="audioPreview"><span>No audio yet</span></div>
          <input type="file" id="audioFile" accept="audio/*" hidden />
          <div class="cover-actions">
            <button type="button" class="btn btn-outline btn-sm" id="audioBtn">Upload audio</button>
            <button type="button" class="btn btn-outline btn-sm" id="audioClear" hidden>Remove</button>
          </div>
          <label class="fld"><span>…or paste an audio URL</span><input id="f_audio" type="url" placeholder="https://…/narration.mp3" /></label>
          <p class="muted tiny">Adds a “Listen to this story” player to the top of the post — an author-recorded narration or podcast version. MP3, M4A, OGG or WAV.</p>
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
      <div><h1>Academy programmes</h1><p class="muted">Courses shown on /academy/. For full curriculum, quizzes &amp; learners, use the dedicated console.</p></div>
      <div class="editor-actions">
        <a class="btn btn-outline" href="/academy/studio/" target="_blank" rel="noopener">Open Academy Studio ↗</a>
        <button class="btn btn-primary" id="newCourseBtn">+ New programme</button>
      </div>
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
          <label class="fld"><span>Contact email (for a birthday wish)</span><input id="p_notice_email" type="email" placeholder="name@example.com" autocomplete="off" /></label>
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
          <h3>Live preview</h3>
          <p class="muted tiny" style="margin-top:-4px">Exactly what members see in the banner on the day.</p>
          <div class="cel-preview" id="celPreview" aria-live="polite"></div>
        </div>
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

  <!-- WEBHOOKS + INTEGRATIONS (outbound + inbound) -->
  <main class="studio-main" id="webhooksView" hidden>
    <div class="studio-head">
      <div><h1>Webhooks &amp; integrations</h1><p class="muted">Push signed JSON out when things happen, and let trusted apps call in. Deliveries retry with backoff — drive the queue from cron (<code>php db/webhooks_run.php</code>) or, with no shell access, point a scheduler at <code>/tasks/cron.php?key=…</code>, or hit <b>Run queue now</b>.</p></div>
      <div class="editor-actions"><button class="btn btn-outline" id="whRunBtn">↻ Run queue now</button><button class="btn btn-primary" id="newWhBtn">+ Add endpoint</button></div>
    </div>
    <div class="entry-list" id="whList"></div>
    <h2 style="font-family:var(--font-heading);font-size:22px;margin:28px 0 12px">Recent deliveries</h2>
    <div class="entry-list" id="whDeliveries"></div>

    <h2 style="font-family:var(--font-heading);font-size:22px;margin:34px 0 6px">API tokens (inbound)</h2>
    <p class="muted" style="margin:0 0 14px">Bearer tokens that let apps/other sites call <code>/integrations/api.php</code> — post as the official Afrovanguard bot, emit events, or read the community feed. <a href="/docs/integrations.md" target="_blank" rel="noopener">API docs ↗</a></p>
    <div class="side-card" style="max-width:680px;margin-bottom:20px">
      <h3>Create a token</h3>
      <label class="fld"><span>Name (what is this for?)</span><input id="atName" placeholder="e.g. Discord announcer" /></label>
      <div class="fld"><span>Scopes</span>
        <div id="atScopes" class="at-scopes"></div>
      </div>
      <button type="button" class="btn btn-primary btn-sm" id="atCreate">Create token</button>
      <div id="atReveal" class="at-reveal" hidden>
        <p class="muted tiny" style="margin:14px 0 6px">Copy this now — it is shown only once:</p>
        <code id="atToken" class="at-token"></code>
      </div>
    </div>
    <div class="entry-list" id="atList"></div>
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

    <div class="side-card" style="max-width:680px;margin-top:26px">
      <h3>Send a test email</h3>
      <p class="muted" style="margin-top:-4px">Verify SMTP end-to-end. Sends the brand template through the configured server (Gmail/Workspace) and reports exactly what happened.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <label class="fld" style="flex:1;min-width:240px;margin:0"><span>To (defaults to ADMIN_EMAIL)</span><input id="mailTestTo" type="email" placeholder="you@example.com" /></label>
        <button type="button" class="btn btn-primary btn-sm" id="mailTestBtn">Send test</button>
      </div>
      <p class="muted tiny" id="mailTestMsg" style="margin-top:10px"></p>
    </div>
  </main>

  <!-- GUIDE (how-to + AI assistant) -->
  <main class="studio-main" id="guideView" hidden>
    <div class="studio-head">
      <div><h1>Guide</h1><p class="muted">How to run the site — and an assistant that answers your questions.</p></div>
    </div>

    <div class="guide-grid">
      <div class="guide-main">
        <div class="guide-ai">
          <h3>Ask the Studio Assistant</h3>
          <p class="muted" style="margin-top:-4px">Ask anything about running the site — “How do I publish a diary entry?”, “How do I add a course?”, “How do I set up email?”</p>
          <div class="guide-chat" id="guideChat" aria-live="polite"></div>
          <form class="guide-ask-form" id="guideForm">
            <input id="guideInput" type="text" autocomplete="off" placeholder="Type your question…" aria-label="Ask the Studio assistant" />
            <button class="btn btn-primary btn-sm" type="submit" id="guideSend">Ask</button>
          </form>
          <div class="guide-suggest" id="guideSuggest">
            <button type="button" class="guide-chip">How do I publish a diary entry?</button>
            <button type="button" class="guide-chip">How do I add a new course?</button>
            <button type="button" class="guide-chip">How do I set up email delivery?</button>
            <button type="button" class="guide-chip">How do I connect a webhook?</button>
          </div>
        </div>

        <div class="guide-howto">
          <h3>How-to — the essentials</h3>
          <details class="guide-acc" open><summary>Publish a Diary entry</summary>
            <ol><li>Open <b>Diary</b> in the sidebar.</li><li>Click <b>+ New entry</b>, write your title and body, add a cover image.</li><li>Set the status to <b>Published</b> and <b>Save</b>. It appears on <code>/diary/</code> immediately.</li><li>Migrating from WordPress? Use <b>↧ Import from WordPress</b> and upload your export <code>.xml</code>.</li></ol>
          </details>
          <details class="guide-acc"><summary>Approve member submissions</summary>
            <ol><li>Open <b>Moderation</b> — the badge shows how many are waiting.</li><li>Read each entry, then <b>Approve &amp; publish</b> or <b>Reject</b> (with an optional note).</li></ol>
          </details>
          <details class="guide-acc"><summary>Add or edit an Academy course</summary>
            <ol><li>Open <b>Academy</b> → <b>+ New course</b>; set title, blurb, level and price (free, members, or a NGN amount).</li><li>Open the course’s <b>Curriculum</b> to add modules and lessons (text, video, quiz).</li><li>Set status to <b>Published</b>. Track learners under the course <b>Roster</b> and issue certificates there.</li></ol>
          </details>
          <details class="guide-acc"><summary>Set up email delivery</summary>
            <ol><li>Set <code>SMTP_HOST</code>, <code>SMTP_USERNAME</code> and <code>AV_SMTP_PASSWORD</code> (a 16-char Gmail App Password) via <code>.htaccess</code> <code>SetEnv</code> or <code>config.php</code>.</li><li>Open <b>System</b> → <b>Send a test email</b> to confirm delivery and see which transport was used.</li></ol>
          </details>
          <details class="guide-acc"><summary>Connect a webhook or app token (for bots / agents)</summary>
            <ol><li>Open <b>Webhooks</b> → <b>New endpoint</b>; paste the destination URL, pick the events, and copy the signing secret.</li><li>Use <b>Send test</b> to verify delivery. For inbound bots/agents, create an <b>App token</b> on the same page.</li></ol>
          </details>
          <details class="guide-acc"><summary>Manage members &amp; access</summary>
            <ol><li>Open <b>Members</b> to search accounts, change access level, or suspend/reactivate.</li><li>Use <b>People</b> for public team profiles, and <b>Sign-in</b> to tune the sign-in security policy.</li></ol>
          </details>
          <details class="guide-acc"><summary>Add audio narration to a Diary post</summary>
            <ol><li>Open the entry in <b>Diary</b>; in the <b>Audio narration</b> card, <b>Upload audio</b> (MP3, M4A, OGG or WAV) or paste a URL.</li><li>Save. Readers get a “Listen to this story” player at the top of the post — perfect for a recorded narration or podcast version.</li></ol>
          </details>
          <details class="guide-acc"><summary>Run a mentorship programme</summary>
            <ol><li>Open <b>Mentorship</b>. Approve mentor applications, pair mentors with mentees, or assign people to a cohort/programme.</li><li>Track sessions and flag inactive pairs; export pairings to CSV. Every change is logged under <b>Activity</b> with one-click undo.</li></ol>
          </details>
          <details class="guide-acc"><summary>Move to a MySQL / PostgreSQL database</summary>
            <ol><li>In cPanel, create an empty MySQL/PostgreSQL database and a user with access to it.</li><li>Open <b>Database</b> (Super Admin). Enter the host, name, user and password, then <b>Test connection</b>.</li><li>Run a <b>Dry run</b> to preview, then <b>Migrate now</b> — every table is copied and its row count verified.</li><li>Paste the shown settings into your <code>.env</code> (set the real password) and reload the site to run on the new database.</li></ol>
          </details>
        </div>
      </div>
    </div>
  </main>

  <!-- MENTORSHIP (mentor–mentee management) -->
  <main class="studio-main" id="mentorshipView" hidden>
    <div class="studio-head">
      <div><h1>Mentorship</h1><p class="muted">Mentors, mentees and pairings. <b>Org</b> and <b>external</b> pools are kept separate.</p></div>
      <div class="editor-actions">
        <a class="btn btn-outline btn-sm" id="mtExport" href="#" download>↧ Export CSV</a>
        <button class="btn btn-primary btn-sm" id="mtAssignBtn">+ Assign a pairing</button>
      </div>
    </div>

    <div class="seg-toggle" role="tablist" aria-label="Pool">
      <button class="seg-btn active" role="tab" data-seg="org">Org members</button>
      <button class="seg-btn" role="tab" data-seg="external">External</button>
    </div>

    <div class="ov-grid" id="mtStats" style="margin:18px 0 22px"></div>

    <!-- Assign panel (hidden until "Assign a pairing") -->
    <div class="side-card mt-assign" id="mtAssign" hidden style="max-width:720px;margin-bottom:22px">
      <h3>Assign a pairing <span class="muted" id="mtAssignSeg"></span></h3>
      <div class="grid2">
        <label class="fld"><span>Mentor</span><input id="mtMentorSearch" type="text" autocomplete="off" placeholder="Search approved mentors…" /><div class="mt-pick" id="mtMentorPick"></div></label>
        <label class="fld"><span>Mentee</span><input id="mtMenteeSearch" type="text" autocomplete="off" placeholder="Search members…" /><div class="mt-pick" id="mtMenteePick"></div></label>
      </div>
      <div class="grid2">
        <label class="fld"><span>Cohort (optional)</span><select id="mtCohort"><option value="0">— Ongoing (no cohort) —</option></select></label>
        <label class="fld"><span>Programme (optional)</span><input id="mtProgramme" type="text" placeholder="e.g. Street-To-Stardom" /></label>
      </div>
      <p class="muted tiny" id="mtAssignMsg" style="margin:0 0 12px"></p>
      <button class="btn btn-primary btn-sm" id="mtAssignSave" disabled>Create pairing</button>
    </div>

    <div class="studio-subtabs" role="tablist" aria-label="Mentorship sections">
      <button class="subtab active" data-mt="pairings">Pairings</button>
      <button class="subtab" data-mt="approvals">Mentor approvals <span class="tab-badge" id="mtApprBadge" hidden></span></button>
      <button class="subtab" data-mt="cohorts">Cohorts</button>
      <button class="subtab" data-mt="inactive">Needs attention <span class="tab-badge" id="mtInactBadge" hidden></span></button>
    </div>

    <div class="mt-panel" id="mtPairings"></div>
    <div class="mt-panel" id="mtApprovals" hidden></div>
    <div class="mt-panel" id="mtCohorts" hidden></div>
    <div class="mt-panel" id="mtInactive" hidden></div>
  </main>

  <!-- TEAM & ROLES (superadmin) -->
  <main class="studio-main" id="adminsView" hidden>
    <div class="studio-head">
      <div><h1>Team &amp; roles</h1><p class="muted">Who can sign in to the Studio, and at what level. <b>Editor</b> = content only · <b>Admin</b> = management + undo · <b>Super&nbsp;Admin</b> = everything (roles, security, database, and every manager &amp; member).</p></div>
    </div>
    <!-- Default Super Admin — auto-provisioned, always present -->
    <div class="side-card" id="adSuperCard" style="max-width:640px;margin-bottom:22px;display:none"></div>
    <div class="side-card" style="max-width:640px;margin-bottom:22px">
      <h3>Grant Studio access</h3>
      <p class="muted" style="margin-top:-4px">The person must have signed in as a member once. The break-glass admin token is always Super Admin.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <label class="fld" style="flex:1;min-width:220px;margin:0"><span>Member email</span><input id="adEmail" type="email" placeholder="name@example.com" /></label>
        <label class="fld" style="margin:0"><span>Role</span><select id="adRole"><option value="editor">Editor</option><option value="admin">Admin</option><option value="superadmin">Super Admin</option></select></label>
        <button class="btn btn-primary btn-sm" id="adAdd" type="button">Grant access</button>
      </div>
      <p class="muted tiny" id="adMsg" style="margin-top:10px"></p>
    </div>
    <div class="mt-panel" id="adList"></div>
  </main>

  <!-- ACTIVITY (per-area admin audit trail + undo) -->
  <main class="studio-main" id="activityView" hidden>
    <div class="studio-head">
      <div><h1>Activity</h1><p class="muted">Every admin action, by area — with one-click undo on reversible ones.</p></div>
      <button class="btn btn-outline btn-sm" id="actRefresh">Refresh</button>
    </div>
    <div class="act-areas" id="actAreas"></div>
    <div class="act-list" id="actList"></div>
  </main>

  <!-- DATABASE (superadmin · SQLite → MySQL/Postgres migration, no SSH) -->
  <main class="studio-main" id="databaseView" hidden>
    <div class="studio-head">
      <div><h1>Database</h1><p class="muted">Move the site from the bundled SQLite file to MySQL or PostgreSQL — no SSH or terminal needed. Every table is copied and its row count verified.</p></div>
      <button class="btn btn-outline btn-sm" id="dbRefresh">Refresh</button>
    </div>

    <div class="db-grid">
      <div class="side-card">
        <h3>Current database</h3>
        <div class="db-status" id="dbStatus"><p class="muted">Loading…</p></div>
      </div>

      <div class="side-card">
        <h3>Migrate to a new database</h3>
        <p class="muted" style="margin-top:-4px">Create an empty MySQL/PostgreSQL database in cPanel first, then enter its details below. Go in order: <b>Test connection</b> → <b>Dry run</b> → <b>Migrate now</b>.</p>
        <form id="dbForm" class="db-form" autocomplete="off">
          <div class="db-row3">
            <label class="fld"><span>Type</span><select id="db_driver"><option value="mysql">MySQL / MariaDB</option><option value="pgsql">PostgreSQL</option></select></label>
            <label class="fld"><span>Host</span><input id="db_host" placeholder="localhost" /></label>
            <label class="fld"><span>Port</span><input id="db_port" placeholder="3306" /></label>
          </div>
          <div class="db-row3">
            <label class="fld"><span>Database name</span><input id="db_name" placeholder="cpaneluser_afrovanguard" /></label>
            <label class="fld"><span>Username</span><input id="db_user" placeholder="cpaneluser_dbuser" /></label>
            <label class="fld"><span>Password</span><input id="db_pass" type="password" /></label>
          </div>
          <div class="db-opts">
            <label class="fld checkbox"><input type="checkbox" id="db_apply" checked /> <span>Create the tables first (apply schema)</span></label>
            <label class="fld checkbox"><input type="checkbox" id="db_truncate" /> <span>Replace target tables if they already hold data</span></label>
          </div>
          <div class="editor-actions" style="margin-top:6px">
            <button type="button" class="btn btn-outline" id="dbTestBtn">Test connection</button>
            <button type="button" class="btn btn-outline" id="dbDryBtn">Dry run</button>
            <button type="button" class="btn btn-primary" id="dbMigrateBtn">Migrate now</button>
          </div>
          <p class="db-msg" id="dbMsg" role="status" aria-live="polite"></p>
        </form>
      </div>
    </div>

    <div class="side-card db-result" id="dbResult" hidden>
      <h3>Result</h3>
      <div id="dbReport"></div>
      <div id="dbEnvWrap" hidden>
        <p class="muted" style="margin:10px 0 6px">Add these to your <code>.env</code> (set <code>AV_DB_PASS</code> to the real password), then reload the site to run on the new database:</p>
        <pre class="db-env" id="dbEnv"></pre>
      </div>
      <details style="margin-top:10px"><summary class="muted">Migration log</summary><pre class="db-log" id="dbLog"></pre></details>
    </div>
  </main>

  <!-- DESIGN STUDIO (superadmin · site brand / accent) -->
  <main class="studio-main" id="designView" hidden>
    <div class="studio-head">
      <div><h1>Design studio</h1><p class="muted">Set the site’s brand accent. It applies across the member portal, Diary, Academy, Community and this Studio — instantly, and reversible anytime.</p></div>
      <button class="btn btn-outline btn-sm" id="brandReset">Reset to default</button>
    </div>
    <div class="db-grid">
      <div class="side-card">
        <h3>Brand colours</h3>
        <label class="fld"><span>Accent</span><input id="br_accent" type="color" value="#f3b416" /></label>
        <label class="fld"><span>Accent — deep (hover &amp; links)</span><input id="br_deep" type="color" value="#b07e08" /></label>
        <div class="editor-actions" style="margin-top:10px">
          <button class="btn btn-primary" id="brandSave">Save &amp; apply</button>
        </div>
        <p class="db-msg" id="brandMsg" role="status" aria-live="polite"></p>
        <p class="muted tiny">Keep the deep shade a little darker than the accent for readable hovers and links. Default: gold #f3b416 / #b07e08.</p>
      </div>
      <div class="side-card">
        <h3>Live preview</h3>
        <div class="brand-pv" id="brandPreview"></div>
      </div>
    </div>
  </main>

  <!-- SIGN-IN (security policy + illustrations) -->
  <main class="studio-main" id="signinView" hidden>
    <div class="studio-head">
      <div><h1>Sign-in</h1><p class="muted">Control how members sign in — the security policy and the art beside the <a href="/login" target="_blank" rel="noopener">sign-in form</a>.</p></div>
    </div>

    <div class="side-card" style="max-width:680px;margin-bottom:28px">
      <h3>Security policy</h3>
      <p class="muted" style="margin-top:-4px">Which methods members may use, and the layers around them. Changes apply immediately. At least one method always stays on.</p>
      <form id="authPolicyForm" class="ap-form">
        <fieldset class="ap-group">
          <legend>Allowed sign-in methods</legend>
          <label class="fld checkbox"><input type="checkbox" data-ap="allow_otp" /> <span>Email one-time code <b>(passwordless)</b> — the recommended primary method</span></label>
          <label class="fld checkbox"><input type="checkbox" data-ap="allow_password" /> <span>Email &amp; password</span></label>
          <label class="fld checkbox"><input type="checkbox" data-ap="allow_google" /> <span>Continue with Google <em id="apGoogleNote" class="muted"></em></span></label>
        </fieldset>
        <fieldset class="ap-group">
          <legend>One-time code</legend>
          <label class="fld"><span>Code length (4–8 digits)</span><input type="number" min="4" max="8" data-ap="otp_length" /></label>
          <label class="fld"><span>Valid for (seconds, 60–1800)</span><input type="number" min="60" max="1800" step="30" data-ap="otp_ttl" /></label>
          <label class="fld"><span>Max wrong tries per code (3–10)</span><input type="number" min="3" max="10" data-ap="otp_max_attempts" /></label>
        </fieldset>
        <fieldset class="ap-group">
          <legend>Passwords</legend>
          <label class="fld"><span>Minimum length (6–64)</span><input type="number" min="6" max="64" data-ap="password_min_len" /></label>
          <label class="fld checkbox"><input type="checkbox" data-ap="password_require_mixed" /> <span>Require both letters and numbers</span></label>
          <label class="fld checkbox"><input type="checkbox" data-ap="require_otp_for_org" /> <span>Force <b>@afrovanguard.org.ng</b> accounts to use a code (no password)</span></label>
        </fieldset>
        <fieldset class="ap-group">
          <legend>Sessions &amp; brute-force lockout</legend>
          <label class="fld"><span>Stay signed in (days, 1–90)</span><input type="number" min="1" max="90" data-ap="session_ttl_days" /></label>
          <label class="fld"><span>Lock after N failed sign-ins (0 = off)</span><input type="number" min="0" max="100" data-ap="lockout_threshold" /></label>
          <label class="fld"><span>Lock duration (minutes)</span><input type="number" min="1" max="1440" data-ap="lockout_minutes" /></label>
        </fieldset>
        <button type="button" class="btn btn-primary btn-sm" id="apSave">Save security settings</button>
      </form>
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
