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
            <p class="avp-lede">Your team’s meetings, minutes and actions — reminders, attendance and the first draft of the minutes take care of themselves.</p>
          </div>
          <div class="avp-hello-actions">
            <button type="button" class="avp-btn" id="vhLogHours">Log hours</button>
            <button type="button" class="avp-btn avp-btn--ink" id="vhSchedule" hidden>Schedule a team meeting</button>
          </div>
        </div>

        <div id="vhLive" hidden></div>

        <div class="avp-kpis" aria-label="At a glance">
          <div class="avp-kpi"><div class="avp-kpi-top"><span class="avp-kpi-l">Meetings to answer</span><span class="avp-chip avp-chip--sm avp-chip--indigo">Next 60 days</span></div><div class="avp-kpi-v av-num" id="vhKRsvp">–</div><div class="avp-kpi-s">say if you are coming</div></div>
          <div class="avp-kpi"><div class="avp-kpi-top"><span class="avp-kpi-l">Minutes waiting on you</span><span class="avp-chip avp-chip--sm avp-chip--gold">To do</span></div><div class="avp-kpi-v av-num" id="vhKMin">–</div><div class="avp-kpi-s">to write or approve</div></div>
          <div class="avp-kpi"><div class="avp-kpi-top"><span class="avp-kpi-l">Your open actions</span><span class="avp-chip avp-chip--sm avp-chip--red" id="vhKOverChip" hidden>Overdue</span></div><div class="avp-kpi-v av-num" id="vhKAct">–</div><div class="avp-kpi-s" id="vhKActS">from meetings</div></div>
          <div class="avp-kpi"><div class="avp-kpi-top"><span class="avp-kpi-l">Hours this month</span><span class="avp-chip avp-chip--sm avp-chip--green">Given</span></div><div class="avp-kpi-v av-num" id="vhKHours">–</div><div class="avp-kpi-s" id="vhKHoursS">this year</div></div>
        </div>

        <div class="avp-cols">
          <div class="avp-col avp-col--main">
            <section class="avp-card" aria-labelledby="vhUpH">
              <div class="avp-card-head"><h2 id="vhUpH">Coming up</h2><span class="avp-chip avp-chip--indigo" id="vhUpN" hidden></span></div>
              <ul class="vh-list" id="vhUpcoming" aria-live="polite"><li class="avp-empty">Loading…</li></ul>
            </section>
            <section class="avp-card" aria-labelledby="vhMinH">
              <div class="avp-card-head"><h2 id="vhMinH">Minutes</h2><span class="avp-chip avp-chip--gold" id="vhMinN" hidden></span></div>
              <ul class="vh-list" id="vhMinutes"></ul>
              <h3 class="vh-sub">Published</h3>
              <ul class="vh-list" id="vhPublished"></ul>
            </section>
          </div>
          <div class="avp-col avp-col--side">
            <section class="avp-card" aria-labelledby="vhActH">
              <div class="avp-card-head"><h2 id="vhActH">Your actions</h2></div>
              <ul class="vh-list" id="vhActions"></ul>
            </section>
            <section class="avp-card" aria-labelledby="vhTeamH">
              <div class="avp-card-head"><h2 id="vhTeamH">Your teams</h2><button type="button" class="avp-btn avp-btn--sq" id="vhNewTeam" hidden>New team</button></div>
              <ul class="vh-list" id="vhTeams"></ul>
            </section>
            <section class="avp-card" aria-labelledby="vhHoursH">
              <div class="avp-card-head"><h2 id="vhHoursH">Your hours</h2></div>
              <div class="avp-card-body" id="vhHours"></div>
            </section>
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
