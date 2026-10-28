<?php
/**
 * portal/views/volunteer.php — the Volunteer dashboard (volunteers only).
 *
 * Six sections, each one job: Home (what needs me, what is next, this
 * month), Meetings (with their minutes), Tasks, Shifts & events, Teams &
 * people, My record (hours by project, attendance). The header names the
 * projects the volunteer serves and as what. The live room and the minutes
 * editor open over it.
 *
 * The shell draws the frame; portal/volunteer.js fills it from
 * portal/volunteer.php?action=hub, and runs the room and the minutes editor.
 * Data: lib/Volunteers, lib/MeetingMinutes, lib/MeetingLive.
 */
declare(strict_types=1);
?>
      <section class="pview avp-view vh" id="view-volunteer" data-view="volunteer" hidden aria-labelledby="vhH"
               data-csrf="<?= e($collabCsrf) ?>" data-me="<?= (int) $u['id'] ?>">
        <!-- Header: who I am here, and the two things I do most -->
        <header class="vh-head">
          <div class="vh-head-t">
            <p class="vh-eyebrow">Volunteer dashboard</p>
            <h1 class="vh-h" id="vhH">Welcome, <?= e($first) ?>.</h1>
            <ul class="vh-projs" id="vhProjects" aria-label="The projects you volunteer on"></ul>
          </div>
          <div class="vh-head-a">
            <button type="button" class="avp-btn avp-btn--ink" id="vhNextStep" hidden></button>
            <button type="button" class="avp-btn" id="vhLogHours">Log hours</button>
            <button type="button" class="avp-btn" id="vhSchedule" hidden>Schedule a meeting</button>
          </div>
        </header>

        <div id="vhLive" hidden></div>

        <nav class="vh-tabs" role="tablist" aria-label="Volunteer dashboard sections">
          <button type="button" role="tab" id="vt-home" aria-controls="vp-home" aria-selected="true" data-tab="home">Home <span class="vh-tab-n" id="vtnHome" hidden></span></button>
          <button type="button" role="tab" id="vt-meetings" aria-controls="vp-meetings" aria-selected="false" data-tab="meetings">Meetings <span class="vh-tab-n" id="vtnMeet" hidden></span></button>
          <button type="button" role="tab" id="vt-actions" aria-controls="vp-actions" aria-selected="false" data-tab="actions">Tasks <span class="vh-tab-n" id="vtnAct" hidden></span></button>
          <button type="button" role="tab" id="vt-opps" aria-controls="vp-opps" aria-selected="false" data-tab="opps">Shifts &amp; events <span class="vh-tab-n" id="vtnOpp" hidden></span></button>
          <button type="button" role="tab" id="vt-people" aria-controls="vp-people" aria-selected="false" data-tab="people">Teams &amp; people</button>
          <button type="button" role="tab" id="vt-record" aria-controls="vp-record" aria-selected="false" data-tab="record">My record</button>
        </nav>

        <!-- Home: what needs me, what is next, where I stand -->
        <div class="vh-panel" role="tabpanel" id="vp-home" aria-labelledby="vt-home">
          <div class="avp-cols">
            <div class="avp-col avp-col--main">
              <section class="avp-card" aria-labelledby="vhNeedH">
                <div class="avp-card-head"><h2 id="vhNeedH">Needs you</h2><span class="avp-chip avp-chip--gold" id="vhNeedN" hidden></span></div>
                <ul class="vh-list" id="vhNeed"><li class="avp-empty">Loading…</li></ul>
              </section>
              <section class="avp-card" aria-labelledby="vhSoonH">
                <div class="avp-card-head"><h2 id="vhSoonH">Coming up</h2><button type="button" class="vh-linkbtn" data-goto-tab="meetings">All meetings →</button></div>
                <ul class="vh-list" id="vhSoon"></ul>
              </section>
            </div>
            <div class="avp-col avp-col--side">
              <section class="avp-card" aria-labelledby="vhMonthH">
                <div class="avp-card-head"><h2 id="vhMonthH">This month</h2><button type="button" class="vh-linkbtn" data-goto-tab="record">My record →</button></div>
                <div class="avp-card-body" id="vhMonth"></div>
              </section>
              <section class="avp-card" aria-labelledby="vhKudosH">
                <div class="avp-card-head"><h2 id="vhKudosH">Kudos</h2><button type="button" class="vh-linkbtn" data-goto-tab="people">Thank someone →</button></div>
                <ul class="vh-feed" id="vhKudos"></ul>
              </section>
              <section class="avp-card" aria-labelledby="vhFeedH">
                <div class="avp-card-head"><h2 id="vhFeedH">In your teams</h2></div>
                <ul class="vh-feed" id="vhFeed"></ul>
              </section>
            </div>
          </div>
        </div>

        <!-- Meetings, with their minutes -->
        <div class="vh-panel" role="tabpanel" id="vp-meetings" aria-labelledby="vt-meetings" hidden>
          <h2 class="vh-sr">Meetings</h2>
          <div class="vh-toolbar">
            <span class="vh-seg" role="group" aria-label="Which meetings"><button type="button" data-mscope="upcoming" aria-pressed="true">Coming up</button><button type="button" data-mscope="calendar" aria-pressed="false">Calendar</button><button type="button" data-mscope="past" aria-pressed="false">Past</button><button type="button" data-mscope="minutes" aria-pressed="false">Minutes <span class="vh-tab-n" id="vtnMin" hidden></span></button></span>
            <label class="vh-filter">Team <select id="vhMTeam"><option value="0">All my teams</option></select></label>
            <label class="vh-filter vh-filter--grow" id="vhNQWrap" hidden>Search minutes <input id="vhNQ" type="search" placeholder="Title, summary or a decision"></label>
          </div>
          <div id="vhMeetWrap"><section class="avp-card"><ul class="vh-list" id="vhMeetList"><li class="avp-empty">Loading…</li></ul></section></div>
          <div id="vhMinWrap" hidden>
            <div class="vh-toolbar vh-toolbar--sub">
              <span class="vh-seg" role="group" aria-label="Which minutes"><button type="button" data-mstatus="" aria-pressed="true">All</button><button type="button" data-mstatus="draft" aria-pressed="false">Drafts</button><button type="button" data-mstatus="review" aria-pressed="false">With the chair</button><button type="button" data-mstatus="published" aria-pressed="false">Published</button></span>
            </div>
            <section class="avp-card" id="vhToDoCard" hidden><div class="avp-card-head"><h2>Waiting on you</h2></div><ul class="vh-list" id="vhToDo"></ul></section>
            <section class="avp-card"><ul class="vh-list" id="vhArchive"><li class="avp-empty">Loading…</li></ul></section>
          </div>
          <details class="vh-caps-d"><summary>How meetings are captured</summary><div id="vhCaps"></div></details>
        </div>

        <!-- Tasks: actions from meetings -->
        <div class="vh-panel" role="tabpanel" id="vp-actions" aria-labelledby="vt-actions" hidden>
          <h2 class="vh-sr">Tasks</h2>
          <div class="vh-toolbar">
            <span class="vh-seg" role="group" aria-label="Whose tasks"><button type="button" data-ascope="mine" aria-pressed="true">Mine</button><button type="button" data-ascope="team" aria-pressed="false" id="vhATeamBtn" hidden>A team’s</button></span>
            <label class="vh-filter" id="vhATeamWrap" hidden>Team <select id="vhATeam"></select></label>
          </div>
          <div id="vhActGroups"></div>
        </div>

        <!-- Shifts & events -->
        <div class="vh-panel" role="tabpanel" id="vp-opps" aria-labelledby="vt-opps" hidden>
          <h2 class="vh-sr">Shifts and events</h2>
          <div class="vh-toolbar"><p class="vh-note vh-m0">Shifts and events that need hands. Sign up; when it is over, your hours are added for you.</p>
            <button type="button" class="avp-btn avp-btn--ink" id="vhOppNew" hidden>Post a shift or event</button></div>
          <div class="vh-opps" id="vhOpps"></div>
        </div>

        <!-- Teams & people -->
        <div class="vh-panel" role="tabpanel" id="vp-people" aria-labelledby="vt-people" hidden>
          <section class="vh-sec" aria-labelledby="vhTeamsH">
            <div class="vh-sec-head"><h2 id="vhTeamsH">Teams</h2><button type="button" class="avp-btn" id="vhNewTeam" hidden>New team</button></div>
            <div class="vh-teams" id="vhTeamGrid"></div>
            <div id="vhTeamDetail" hidden></div>
          </section>
          <section class="vh-sec" aria-labelledby="vhPeopleH">
            <div class="vh-sec-head"><h2 id="vhPeopleH">Volunteers</h2></div>
            <div class="vh-toolbar">
              <label class="vh-filter vh-filter--grow">Find a volunteer <input id="vhPQ" type="search" placeholder="Name, project, team, centre or role"></label>
              <span class="vh-seg" role="group" aria-label="Which volunteers"><button type="button" data-psrc="" aria-pressed="true">Everyone</button><button type="button" data-psrc="afrovanguard" aria-pressed="false">Afrovanguard</button><button type="button" data-psrc="ngg" aria-pressed="false">NextGen Genius</button></span>
            </div>
            <section class="avp-card" id="vhNggCard" hidden><div class="avp-card-body vh-ngg" id="vhNgg"></div></section>
            <div class="vh-people-grid" id="vhPeople"></div>
          </section>
          <section class="avp-card" aria-labelledby="vhWallH"><div class="avp-card-head"><h2 id="vhWallH">Kudos wall</h2></div><ul class="vh-feed" id="vhWall"></ul></section>
        </div>

        <!-- My record: hours (by project) and attendance -->
        <div class="vh-panel" role="tabpanel" id="vp-record" aria-labelledby="vt-record" hidden>
          <h2 class="vh-sr">My record</h2>
          <div class="avp-kpis vh-kpis" id="vhRecKpis" aria-label="Your record"></div>
          <div class="avp-cols">
            <div class="avp-col avp-col--main">
              <section class="avp-card" aria-labelledby="vhChartH">
                <div class="avp-card-head"><h2 id="vhChartH">Hours, month by month</h2><button type="button" class="vh-linkbtn" id="vhChartTable" aria-pressed="false">Show as a table</button></div>
                <div class="avp-card-body"><div class="vh-chart" id="vhChart"></div></div>
              </section>
              <section class="avp-card" aria-labelledby="vhLogH">
                <div class="avp-card-head"><h2 id="vhLogH">Hours on record</h2><span class="vh-row-btns"><a class="avp-btn avp-btn--sq" href="/portal/volunteer.php?action=service_letter">Certificate of service</a><a class="avp-btn avp-btn--sq" href="/portal/volunteer.php?action=hours_csv">CSV</a></span></div>
                <ul class="vh-list" id="vhHoursList"></ul>
              </section>
              <section class="avp-card" aria-labelledby="vhMyMeetH">
                <div class="avp-card-head"><h2 id="vhMyMeetH">Meetings — were you there?</h2><span class="avp-chip avp-chip--sm" id="vhMyMeetN"></span></div>
                <ul class="vh-list" id="vhMyMeet"><li class="avp-empty">Loading…</li></ul>
              </section>
            </div>
            <div class="avp-col avp-col--side">
              <section class="avp-card" aria-labelledby="vhLogFormH">
                <div class="avp-card-head"><h2 id="vhLogFormH">Log hours</h2></div>
                <form class="avp-card-body avp-form vh-logform" id="vhLogForm">
                  <label class="avp-form-wide">What you did<input id="lhAct" maxlength="200" required placeholder="e.g. Outreach at Egbeda"></label>
                  <label class="avp-form-wide">Project<select id="lhProj"></select></label>
                  <label>Day<input id="lhDay" type="date"></label>
                  <label>Hours<input id="lhH" type="number" min="0.25" max="16" step="0.25" value="2" required></label>
                  <button class="avp-btn avp-btn--ink avp-form-wide" type="submit">Log</button>
                  <p class="vh-err avp-form-wide" id="lhErr" role="alert"></p>
                </form>
                <p class="vh-note vh-note--pad">Meetings, shifts and NGG sessions are added for you.</p>
              </section>
              <section class="avp-card" aria-labelledby="vhByProjH">
                <div class="avp-card-head"><h2 id="vhByProjH">By project</h2></div>
                <ul class="vh-feed" id="vhByProj"></ul>
              </section>
<?php if (($hasGate ?? false) || ($isNgv ?? false)): ?>
              <section class="avp-card"><div class="avp-card-head"><h2>At the gate</h2></div>
                <div class="avp-card-body"><p class="vh-note vh-mb10" id="vhGateLine"></p><a class="avp-btn avp-btn--sq" href="#attendance" data-goto="attendance">Attendance &amp; pass →</a></div></section>
<?php endif; ?>
            </div>
          </div>
        </div>

        <!-- The live room -->
        <dialog class="vh-dlg vh-room" id="vhRoom" aria-labelledby="vhRoomH">
          <header class="vh-dlg-head">
            <div><h2 id="vhRoomH">Live room</h2><p class="vh-dlg-sub" id="vhRoomSub"></p></div>
            <span class="vh-clock" id="vhRoomClock" aria-live="off"></span>
            <a class="avp-btn avp-btn--sq" id="vhRoomJoin" target="_blank" rel="noopener" hidden>Join the call</a>
            <button type="button" class="avp-btn avp-btn--sq" id="vhRoomMin" hidden>Open the minutes</button>
            <button type="button" class="avp-btn avp-btn--sq" data-close>Close</button>
          </header>
          <div class="vh-room-grid">
            <section class="vh-pane" aria-labelledby="vhHereH">
              <h3 id="vhHereH">Here <span class="av-num" id="vhHereN"></span></h3>
              <ul class="vh-people" id="vhHere"></ul>
              <h3>Not here yet</h3>
              <ul class="vh-people vh-people--missing" id="vhMissing"></ul>
              <button type="button" class="avp-btn avp-btn--sq avp-btn--block" id="vhImHere">I’m here</button>
            </section>
            <section class="vh-pane vh-pane--words" aria-labelledby="vhWordsH">
              <div class="vh-words-head">
                <h3 id="vhWordsH">What is being said</h3>
                <button type="button" class="avp-btn avp-btn--sq" id="vhCaption" hidden aria-pressed="false">Caption this room</button>
              </div>
              <ol class="vh-words" id="vhWords" aria-live="polite"></ol>
              <p class="vh-interim" id="vhInterim" aria-hidden="true"></p>
              <form class="vh-capture" id="vhCapture" hidden>
                <select id="vhCapType" aria-label="What it is"><option value="decision">Decision</option><option value="action">Action</option><option value="note">Note</option></select>
                <input id="vhCapText" placeholder="Capture it into the minutes…" autocomplete="off" aria-label="Decision, action or note">
                <select id="vhCapOwner" aria-label="Who owns the action" hidden><option value="0">Who owns it?</option></select>
                <button class="avp-btn avp-btn--ink avp-btn--sq" type="submit">Add</button>
              </form>
            </section>
            <section class="vh-pane" aria-labelledby="vhQrH">
              <h3 id="vhQrH">Check in</h3>
              <div class="vh-qr" id="vhQr"></div>
              <p class="vh-note">Scan with a phone camera. The code changes every minute <span id="vhQrIn"></span>.</p>
              <button type="button" class="avp-btn avp-btn--sq avp-btn--block" id="vhQrBig">Show on the big screen</button>
              <h3>Agenda</h3>
              <ol class="vh-agenda" id="vhRoomAgenda"></ol>
              <p class="vh-note" id="vhSources"></p>
            </section>
          </div>
        </dialog>

        <!-- The minutes editor -->
        <dialog class="vh-dlg vh-min" id="vhMin" aria-labelledby="vhMinTitle">
          <header class="vh-dlg-head">
            <div><h2 id="vhMinTitle">Minutes</h2><p class="vh-dlg-sub" id="vhMinSub"></p></div>
            <span class="avp-chip" id="vhMinStatus"></span>
            <span class="vh-saved" id="vhMinSaved" aria-live="polite"></span>
            <button type="button" class="avp-btn avp-btn--sq" data-close>Close</button>
          </header>
          <div class="vh-min-body" id="vhMinBody"></div>
          <footer class="vh-dlg-foot" id="vhMinFoot"></footer>
        </dialog>

        <!-- Small forms -->
        <dialog class="vh-dlg vh-dlg--sm" id="vhForm" aria-labelledby="vhFormH">
          <form method="dialog" id="vhFormF">
            <header class="vh-dlg-head"><h2 id="vhFormH"></h2><button type="button" class="avp-btn avp-btn--sq" data-close>Cancel</button></header>
            <div class="vh-form-body avp-form" id="vhFormBody"></div>
            <p class="vh-err" id="vhFormErr" role="alert"></p>
            <footer class="vh-dlg-foot"><button class="avp-btn avp-btn--ink" type="submit" id="vhFormGo">Save</button></footer>
          </form>
        </dialog>

        <!-- The QR on a projector -->
        <dialog class="vh-qrbig" id="vhQrBigD" aria-label="Check-in code">
          <div class="vh-qrbig-in"><p class="vh-qrbig-t" id="vhQrBigT"></p><div id="vhQrBigCode"></div><p>Scan to check in · changes every minute</p><button type="button" class="avp-btn" data-close>Close</button></div>
        </dialog>
      </section>
