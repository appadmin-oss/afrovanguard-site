<?php
/**
 * mentorship/admin/index.php — the dedicated Mentorship admin portal.
 *
 * A focused console for the Mentorship admin: approve mentors, pair mentees,
 * run cohorts, chase inactive pairings and export. The admin signs in with
 * their own email + password (a `mentorship_admin` member account); the signed
 * role cookie scopes /admin/api.php to mentorship actions only. The Super Admin
 * manages from the main Studio — this portal carries no Super Admin surface.
 */
declare(strict_types=1);
?><!DOCTYPE html>
<html lang="en-NG" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Mentorship Admin — Afrovanguard</title>
  <script>(function(){try{var t=localStorage.getItem('av.theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant:wght@600;700&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link href="/mentorship/admin/admin.css" rel="stylesheet" />
</head>
<body>

<!-- ░░ LOGIN ░░ -->
<section class="login" id="login" hidden>
  <form class="login-card" id="loginForm">
    <div class="login-brand"><span class="wm-1">Afro</span><span class="wm-2">vanguard</span> <span class="login-tag">Mentorship Admin</span></div>
    <p class="login-sub">Approve mentors, pair mentees and run cohorts.</p>
    <div id="loginFields">
      <a class="gbtn" id="googleBtn" href="#" hidden>
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1A11 11 0 0 0 2.18 7.06l3.66 2.84C6.71 7.3 9.14 5.38 12 5.38z"/></svg>
        <span>Continue with Google</span>
      </a>
      <div class="login-or" id="loginOr" hidden><span>or use your email</span></div>
      <label class="fld"><span>Email</span>
        <input type="email" id="email" autocomplete="username" placeholder="you@afrovanguard.org.ng" />
      </label>
      <label class="fld"><span>Password</span>
        <input type="password" id="password" autocomplete="current-password" placeholder="Your account password" />
      </label>
      <button class="btn btn-primary" type="submit">Enter the portal</button>
    </div>
    <p class="login-msg" id="loginMsg" role="alert" aria-live="polite"></p>
    <div class="login-note" id="memberNote" hidden></div>
    <a class="login-back" href="/mentorship/">← Back to Mentorship</a>
  </form>
</section>

<!-- ░░ APP SHELL ░░ -->
<div class="shell" id="app" hidden>
  <aside class="side" id="side">
    <div class="side-brand">
      <span class="side-mark">M</span>
      <span class="side-name">Mentorship<small>Admin</small></span>
    </div>
    <nav class="side-nav" aria-label="Sections">
      <button class="nav-item is-active" data-view="overview"><svg viewBox="0 0 24 24"><path d="M3 13h8V3H3zM13 21h8V3h-8zM3 21h8v-6H3z"/></svg><span>Overview</span></button>
      <button class="nav-item" data-view="pairings"><svg viewBox="0 0 24 24"><circle cx="7" cy="8" r="3"/><circle cx="17" cy="8" r="3"/><path d="M2 20c0-3 2.2-4.5 5-4.5M22 20c0-3-2.2-4.5-5-4.5M9.5 15.5l5-5"/></svg><span>Pairings</span></button>
      <button class="nav-item" data-view="mentors"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.2"/><path d="M5 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/></svg><span>Mentors</span><span class="nav-badge" id="badgeApprovals" hidden></span></button>
      <button class="nav-item" data-view="cohorts"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 4v16"/></svg><span>Cohorts</span></button>
      <button class="nav-item" data-view="attention"><svg viewBox="0 0 24 24"><path d="M12 3l9 16H3z"/><path d="M12 10v4M12 17h.01"/></svg><span>Needs attention</span><span class="nav-badge warn" id="badgeAttn" hidden></span></button>
      <button class="nav-item" data-view="activity"><svg viewBox="0 0 24 24"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg><span>Activity</span></button>
    </nav>
    <div class="side-foot">
      <button class="ghost" id="themeToggle" title="Toggle theme"><svg class="t-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg><svg class="t-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1111.2 3 7 7 0 0021 12.8z"/></svg><span>Theme</span></button>
      <a class="ghost" href="/mentorship/" target="_blank" rel="noopener"><svg viewBox="0 0 24 24"><path d="M14 3h7v7M21 3l-9 9M19 14v5a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h5"/></svg><span>View Mentorship</span></a>
      <button class="ghost danger" id="logout"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg><span>Log out</span></button>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <button class="hamburger" id="hamburger" aria-label="Menu"><svg viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
      <div class="top-title"><h1 id="viewTitle">Overview</h1><p class="muted" id="viewSub">Mentor network at a glance.</p></div>
      <div class="top-actions">
        <div class="seg" id="segToggle" role="tablist" aria-label="Pool">
          <button class="seg-btn is-active" data-seg="" role="tab">All</button>
          <button class="seg-btn" data-seg="org" role="tab">Org</button>
          <button class="seg-btn" data-seg="external" role="tab">External</button>
        </div>
      </div>
    </header>

    <div class="content">
      <!-- OVERVIEW -->
      <section class="view" id="v-overview">
        <div class="stat-grid" id="mtStatGrid"></div>
        <div class="quick">
          <h2>Quick actions</h2>
          <div class="quick-row">
            <button class="qbtn" data-go="pairings" data-assign="1"><b>Assign a pairing</b><span>Match an approved mentor with a mentee</span></button>
            <button class="qbtn" data-go="mentors"><b>Review mentor approvals</b><span>Approve or decline applications</span></button>
            <button class="qbtn" data-go="attention"><b>Chase inactive pairs</b><span>Pairings with no recent session</span></button>
            <button class="qbtn" data-go="cohorts"><b>Manage cohorts</b><span>Open, close or create a round</span></button>
          </div>
        </div>
      </section>

      <!-- PAIRINGS -->
      <section class="view" id="v-pairings" hidden>
        <div class="toolbar">
          <div class="search-wrap"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
            <input type="search" id="pairQ" placeholder="Search mentor or mentee…" /></div>
          <select id="pairStatus" aria-label="Filter by status">
            <option value="">All statuses</option><option value="active">Active</option>
            <option value="pending">Pending</option><option value="ended">Ended</option><option value="declined">Declined</option>
          </select>
          <div class="toolbar-end">
            <a class="btn btn-outline btn-sm" id="exportBtn" href="#" download>↧ Export CSV</a>
            <button class="btn btn-primary btn-sm" id="assignBtn">+ Assign a pairing</button>
          </div>
        </div>

        <div class="panel" id="assignPanel" hidden>
          <h3>Assign a pairing <span class="muted" id="assignSegNote"></span></h3>
          <div class="grid2">
            <label class="fld"><span>Mentor (approved)</span>
              <input type="text" id="aMentorQ" autocomplete="off" placeholder="Search approved mentors…" />
              <div class="pick" id="aMentorPick"></div>
            </label>
            <label class="fld"><span>Mentee</span>
              <input type="text" id="aMenteeQ" autocomplete="off" placeholder="Search members…" />
              <div class="pick" id="aMenteePick"></div>
            </label>
          </div>
          <div class="grid2">
            <label class="fld"><span>Cohort (optional)</span><select id="aCohort"><option value="0">— Ongoing (no cohort) —</option></select></label>
            <label class="fld"><span>Programme (optional)</span><input type="text" id="aProgramme" placeholder="e.g. Street-To-Stardom" /></label>
          </div>
          <p class="panel-msg" id="assignMsg"></p>
          <div class="panel-foot">
            <button class="btn btn-ghost btn-sm" id="assignCancel">Cancel</button>
            <button class="btn btn-primary btn-sm" id="assignSave" disabled>Create pairing</button>
          </div>
        </div>

        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>Mentor</th><th>Mentee</th><th>Pool</th><th>Status</th><th class="num">Sessions</th><th>Last session</th><th></th></tr></thead>
          <tbody id="mtPairBody"><tr><td colspan="7" class="empty">Loading…</td></tr></tbody>
        </table></div>
      </section>

      <!-- MENTORS -->
      <section class="view" id="v-mentors" hidden>
        <div class="toolbar">
          <div class="search-wrap"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
            <input type="search" id="mentorQ" placeholder="Search mentors…" /></div>
          <select id="mentorApproval" aria-label="Filter by approval">
            <option value="">All</option><option value="pending">Pending</option>
            <option value="approved">Approved</option><option value="declined">Declined</option>
          </select>
          <div class="toolbar-end">
            <button class="btn btn-primary btn-sm" id="addMentorBtn">+ Add a mentor</button>
          </div>
        </div>

        <div class="panel" id="addMentorPanel" hidden>
          <h3>Add a mentor directly</h3>
          <div class="grid2">
            <label class="fld"><span>Member email</span><input type="email" id="amEmail" placeholder="name@afrovanguard.org.ng" /></label>
            <label class="fld"><span>Headline</span><input type="text" id="amHeadline" placeholder="e.g. Product designer · 8 yrs" /></label>
          </div>
          <div class="grid2">
            <label class="fld"><span>Focus areas</span><input type="text" id="amFocus" placeholder="Design, Career, Leadership" /></label>
            <label class="fld"><span>Capacity</span><input type="number" id="amCapacity" min="1" max="20" value="3" /></label>
          </div>
          <p class="panel-msg" id="addMentorMsg"></p>
          <div class="panel-foot">
            <button class="btn btn-ghost btn-sm" id="addMentorCancel">Cancel</button>
            <button class="btn btn-primary btn-sm" id="addMentorSave">Add &amp; approve</button>
          </div>
        </div>

        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>Mentor</th><th>Pool</th><th>Focus</th><th class="num">Mentees</th><th>Status</th><th></th></tr></thead>
          <tbody id="mtMentorBody"><tr><td colspan="6" class="empty">Loading…</td></tr></tbody>
        </table></div>
      </section>

      <!-- COHORTS -->
      <section class="view" id="v-cohorts" hidden>
        <div class="toolbar">
          <div class="toolbar-end"><button class="btn btn-primary btn-sm" id="addCohortBtn">+ New cohort</button></div>
        </div>
        <div class="panel" id="cohortPanel" hidden>
          <h3>Create a cohort</h3>
          <div class="grid2">
            <label class="fld"><span>Name</span><input type="text" id="coName" placeholder="e.g. 2026 Spring round" /></label>
            <label class="fld"><span>Pool</span><select id="coSegment"><option value="org">Org members</option><option value="external">External</option></select></label>
          </div>
          <div class="grid2">
            <label class="fld"><span>Programme (optional)</span><input type="text" id="coProgramme" placeholder="e.g. Techome" /></label>
            <label class="fld"><span>Starts (optional)</span><input type="date" id="coStarts" /></label>
          </div>
          <p class="panel-msg" id="cohortMsg"></p>
          <div class="panel-foot">
            <button class="btn btn-ghost btn-sm" id="cohortCancel">Cancel</button>
            <button class="btn btn-primary btn-sm" id="cohortSave">Create cohort</button>
          </div>
        </div>
        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>Cohort</th><th>Pool</th><th>Programme</th><th class="num">Pairs</th><th>Status</th><th></th></tr></thead>
          <tbody id="mtCohortBody"><tr><td colspan="6" class="empty">Loading…</td></tr></tbody>
        </table></div>
      </section>

      <!-- NEEDS ATTENTION -->
      <section class="view" id="v-attention" hidden>
        <p class="view-intro muted">Active pairings with no session in the last <b>21 days</b>. Nudge the mentor, or end the pairing if it has stalled.</p>
        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>Mentor</th><th>Mentee</th><th>Pool</th><th>Last session</th><th>Idle since</th><th></th></tr></thead>
          <tbody id="mtAttnBody"><tr><td colspan="6" class="empty">Loading…</td></tr></tbody>
        </table></div>
      </section>

      <!-- ACTIVITY -->
      <section class="view" id="v-activity" hidden>
        <p class="view-intro muted">Recent mentorship actions. Reversible changes show an <b>Undo</b>.</p>
        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>When</th><th>Action</th><th>Target</th><th>Detail</th><th></th></tr></thead>
          <tbody id="mtActBody"><tr><td colspan="5" class="empty">Loading…</td></tr></tbody>
        </table></div>
      </section>
    </div>
  </main>
  <div class="scrim" id="scrim" hidden></div>
</div>

<div class="toast-wrap" id="toasts"></div>
<script src="/mentorship/admin/admin.js"></script>
</body>
</html>
