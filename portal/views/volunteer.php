<?php
/**
 * portal/views/volunteer.php — the Volunteer hub.
 *
 * One place for a volunteer's activities: the meetings to answer and join,
 * the live room while a meeting is on (who is here, what is said, the
 * check-in QR, decisions and actions captured as they happen), the minutes to
 * write or approve, published minutes, my actions, my teams and my hours.
 *
 * The shell draws the frame; portal/volunteer.js fills it from
 * portal/volunteer.php?action=hub, and runs the room and the minutes editor.
 * Data: lib/Volunteers, lib/MeetingMinutes, lib/MeetingLive.
 */
declare(strict_types=1);
?>
      <section class="pview avp-view vh" id="view-volunteer" data-view="volunteer" hidden aria-labelledby="vhH"
               data-csrf="<?= e($collabCsrf) ?>" data-me="<?= (int) $u['id'] ?>">
        <div class="avp-hello">
          <div class="avp-hello-t">
            <h1 class="avp-h1" id="vhH">Volunteer hub</h1>
            <p class="avp-lede" id="vhLede">Your team’s meetings, minutes and actions — reminders, attendance and the first draft of the minutes take care of themselves.</p>
          </div>
          <div class="avp-hello-actions">
            <button type="button" class="avp-btn" id="vhLogHours">Log hours</button>
            <button type="button" class="avp-btn avp-btn--ink" id="vhSchedule" hidden>Schedule a meeting</button>
          </div>
        </div>

        <div id="vhLive" hidden></div>

        <nav class="vh-tabs" role="tablist" aria-label="Volunteer hub sections">
          <button type="button" role="tab" id="vt-overview" aria-controls="vp-overview" aria-selected="true" data-tab="overview">Overview</button>
          <button type="button" role="tab" id="vt-meetings" aria-controls="vp-meetings" aria-selected="false" data-tab="meetings">Meetings <span class="vh-tab-n" id="vtnMeet" hidden></span></button>
          <button type="button" role="tab" id="vt-minutes" aria-controls="vp-minutes" aria-selected="false" data-tab="minutes">Minutes <span class="vh-tab-n" id="vtnMin" hidden></span></button>
          <button type="button" role="tab" id="vt-actions" aria-controls="vp-actions" aria-selected="false" data-tab="actions">Actions <span class="vh-tab-n" id="vtnAct" hidden></span></button>
          <button type="button" role="tab" id="vt-teams" aria-controls="vp-teams" aria-selected="false" data-tab="teams">Teams</button>
          <button type="button" role="tab" id="vt-hours" aria-controls="vp-hours" aria-selected="false" data-tab="hours">Hours</button>
        </nav>

        <!-- Overview -->
        <div class="vh-panel" role="tabpanel" id="vp-overview" aria-labelledby="vt-overview">
          <div class="avp-kpis vh-kpis" aria-label="At a glance">
            <div class="avp-kpi"><div class="avp-kpi-top"><span class="avp-kpi-l">Next meeting</span></div><div class="avp-kpi-v" id="vhKNext">–</div><div class="avp-kpi-s" id="vhKNextS">&nbsp;</div></div>
            <div class="avp-kpi"><div class="avp-kpi-top"><span class="avp-kpi-l">Waiting on you</span><span class="avp-chip avp-chip--sm avp-chip--gold" id="vhKWaitChip" hidden>To do</span></div><div class="avp-kpi-v av-num" id="vhKWait">–</div><div class="avp-kpi-s" id="vhKWaitS">&nbsp;</div></div>
            <div class="avp-kpi"><div class="avp-kpi-top"><span class="avp-kpi-l">Open actions</span><span class="avp-chip avp-chip--sm avp-chip--red" id="vhKOverChip" hidden>Overdue</span></div><div class="avp-kpi-v av-num" id="vhKAct">–</div><div class="avp-kpi-s" id="vhKActS">&nbsp;</div></div>
            <div class="avp-kpi"><div class="avp-kpi-top"><span class="avp-kpi-l">Your attendance</span></div><div class="avp-kpi-v av-num" id="vhKAtt">–</div><div class="avp-kpi-s" id="vhKAttS">last six months</div></div>
            <div class="avp-kpi"><div class="avp-kpi-top"><span class="avp-kpi-l">Hours this month</span></div><div class="avp-kpi-v av-num" id="vhKHours">–</div><div class="vh-spark" id="vhKSpark" aria-hidden="true"></div></div>
          </div>
          <div class="avp-cols">
            <div class="avp-col avp-col--main">
              <section class="avp-card" aria-labelledby="vhNeedH">
                <div class="avp-card-head"><h2 id="vhNeedH">Needs you</h2><span class="avp-chip avp-chip--gold" id="vhNeedN" hidden></span></div>
                <ul class="vh-list" id="vhNeed"><li class="avp-empty">Loading…</li></ul>
              </section>
              <section class="avp-card" aria-labelledby="vhWeekH">
                <div class="avp-card-head"><h2 id="vhWeekH">The next two weeks</h2><button type="button" class="avp-head-link vh-linkbtn" data-goto-tab="meetings">All meetings →</button></div>
                <div class="vh-week" id="vhWeek"></div>
                <ul class="vh-list" id="vhSoon"></ul>
              </section>
            </div>
            <div class="avp-col avp-col--side">
              <section class="avp-card" aria-labelledby="vhFeedH">
                <div class="avp-card-head"><h2 id="vhFeedH">In your teams</h2></div>
                <ul class="vh-feed" id="vhFeed"></ul>
              </section>
              <section class="avp-card" aria-labelledby="vhCapH">
                <div class="avp-card-head"><h2 id="vhCapH">How meetings are captured</h2></div>
                <div class="avp-card-body vh-caps" id="vhCaps"></div>
              </section>
            </div>
          </div>
        </div>

        <!-- Meetings -->
        <div class="vh-panel" role="tabpanel" id="vp-meetings" aria-labelledby="vt-meetings" hidden>
          <div class="vh-toolbar">
            <span class="vh-seg" role="group" aria-label="Which meetings"><button type="button" data-mscope="upcoming" aria-pressed="true">Coming up</button><button type="button" data-mscope="past" aria-pressed="false">Past</button></span>
            <label class="vh-filter">Team <select id="vhMTeam"><option value="0">All my teams</option></select></label>
          </div>
          <section class="avp-card"><ul class="vh-list" id="vhMeetList"><li class="avp-empty">Loading…</li></ul></section>
        </div>

        <!-- Minutes -->
        <div class="vh-panel" role="tabpanel" id="vp-minutes" aria-labelledby="vt-minutes" hidden>
          <div class="vh-toolbar">
            <span class="vh-seg" role="group" aria-label="Which minutes"><button type="button" data-mstatus="" aria-pressed="true">All</button><button type="button" data-mstatus="draft" aria-pressed="false">Drafts</button><button type="button" data-mstatus="review" aria-pressed="false">With the chair</button><button type="button" data-mstatus="published" aria-pressed="false">Published</button></span>
            <label class="vh-filter">Team <select id="vhNTeam"><option value="0">All my teams</option></select></label>
            <label class="vh-filter vh-filter--grow">Search <input id="vhNQ" type="search" placeholder="Title, summary or a decision"></label>
          </div>
          <section class="avp-card" id="vhToDoCard" hidden><div class="avp-card-head"><h2>Waiting on you</h2></div><ul class="vh-list" id="vhToDo"></ul></section>
          <section class="avp-card"><ul class="vh-list" id="vhArchive"><li class="avp-empty">Loading…</li></ul></section>
        </div>

        <!-- Actions -->
        <div class="vh-panel" role="tabpanel" id="vp-actions" aria-labelledby="vt-actions" hidden>
          <div class="vh-toolbar">
            <span class="vh-seg" role="group" aria-label="Whose actions"><button type="button" data-ascope="mine" aria-pressed="true">Mine</button><button type="button" data-ascope="team" aria-pressed="false" id="vhATeamBtn" hidden>A team’s</button></span>
            <label class="vh-filter" id="vhATeamWrap" hidden>Team <select id="vhATeam"></select></label>
          </div>
          <div id="vhActGroups"></div>
        </div>

        <!-- Teams -->
        <div class="vh-panel" role="tabpanel" id="vp-teams" aria-labelledby="vt-teams" hidden>
          <div class="vh-toolbar"><button type="button" class="avp-btn avp-btn--sq" id="vhNewTeam" hidden>New team</button></div>
          <div class="vh-teams" id="vhTeamGrid"></div>
          <div id="vhTeamDetail" hidden></div>
        </div>

        <!-- Hours -->
        <div class="vh-panel" role="tabpanel" id="vp-hours" aria-labelledby="vt-hours" hidden>
          <div class="avp-cols">
            <div class="avp-col avp-col--main">
              <section class="avp-card" aria-labelledby="vhChartH">
                <div class="avp-card-head"><h2 id="vhChartH">Hours given, month by month</h2><button type="button" class="vh-linkbtn" id="vhChartTable" aria-pressed="false">Show as a table</button></div>
                <div class="avp-card-body"><div class="vh-chart" id="vhChart"></div></div>
              </section>
              <section class="avp-card" aria-labelledby="vhLogH">
                <div class="avp-card-head"><h2 id="vhLogH">Everything logged</h2><a class="avp-btn avp-btn--sq" href="/portal/volunteer.php?action=hours_csv">Download (CSV)</a></div>
                <ul class="vh-list" id="vhHoursList"></ul>
              </section>
            </div>
            <div class="avp-col avp-col--side">
              <section class="avp-card"><div class="avp-card-head"><h2>Totals</h2></div><div class="avp-card-body" id="vhHoursTot"></div></section>
              <section class="avp-card" aria-labelledby="vhLogFormH">
                <div class="avp-card-head"><h2 id="vhLogFormH">Log hours</h2></div>
                <form class="avp-card-body avp-form vh-logform" id="vhLogForm">
                  <label>What you did<input id="lhAct" maxlength="200" required placeholder="e.g. Outreach at Egbeda"></label>
                  <label>Day<input id="lhDay" type="date"></label>
                  <label>Hours<input id="lhH" type="number" min="0.25" max="16" step="0.25" value="2" required></label>
                  <button class="avp-btn avp-btn--ink avp-form-wide" type="submit">Log</button>
                  <p class="vh-err avp-form-wide" id="lhErr" role="alert"></p>
                </form>
                <p class="vh-note" style="padding:0 20px 16px">Meetings you attend are counted for you once their minutes are published.</p>
              </section>
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
