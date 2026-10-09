<?php /* portal/views/tools.php — moved verbatim from portal/index.php v1 (row 14 shell
   rebuild). Markup and ids unchanged; restyled through portal.css + avp.css tokens.
   Rendered inside the shell, which defines the variables it reads. */ ?>
        <!-- ============================================================ -->
        <!-- TOOLS  (Afrovanguard first-party productivity apps)          -->
        <!-- ============================================================ -->
        <section class="pview" id="view-tools" data-view="tools" hidden data-uid="<?= (int) $u['id'] ?>">
          <div class="view-head">
            <div><h1>Suite</h1><p class="view-sub">Your Afrovanguard productivity suite — personal tools and shared team apps, right in the portal.</p></div>
          </div>

          <!-- App launcher: pick one app to open -->
          <div class="suite-home" id="suiteHome">
            <h2 class="suite-section"><span>◧ Personal</span><small>Private to you, synced to your account</small></h2>
            <div class="app-tiles">
              <button type="button" class="app-tile app-tile--cal" data-app="cal"><span class="app-ic">◗</span><span class="app-tx"><span class="app-nm">Calendar</span><span class="app-desc">Your month, unified</span></span></button>
              <button type="button" class="app-tile" data-app="notes"><span class="app-ic">✎</span><span class="app-tx"><span class="app-nm">Notes</span><span class="app-desc">Quick private scratchpad</span></span></button>
              <button type="button" class="app-tile" data-app="focus"><span class="app-ic">◐</span><span class="app-tx"><span class="app-nm">Focus</span><span class="app-desc">Pomodoro timer</span></span></button>
              <button type="button" class="app-tile" data-app="habits"><span class="app-ic">✓</span><span class="app-tx"><span class="app-nm">Habits</span><span class="app-desc">Build daily streaks</span></span></button>
              <button type="button" class="app-tile" data-app="countdown"><span class="app-ic">◔</span><span class="app-tx"><span class="app-nm">Countdown</span><span class="app-desc">Days to a date</span></span></button>
              <button type="button" class="app-tile" data-app="rem"><span class="app-ic">⏰</span><span class="app-tx"><span class="app-nm">Reminders</span><span class="app-desc">Nudges with due dates</span></span></button>
            </div>
<?php if ($isOrg): ?>
            <h2 class="suite-section"><span>◨ Team</span><small>Shared with everyone at Afrovanguard</small></h2>
            <div class="app-tiles">
              <button type="button" class="app-tile app-tile--team" data-app="meet"><span class="app-ic">🎥</span><span class="app-tx"><span class="app-nm">Meetings</span><span class="app-desc">Schedule with a Meet link + AI minutes</span></span></button>
              <button type="button" class="app-tile app-tile--team" data-app="board"><span class="app-ic">▦</span><span class="app-tx"><span class="app-nm">Team board</span><span class="app-desc">Kanban workflow</span></span></button>
              <button type="button" class="app-tile app-tile--team" data-app="polls"><span class="app-ic">▤</span><span class="app-tx"><span class="app-nm">Team polls</span><span class="app-desc">Quick decisions</span></span></button>
              <button type="button" class="app-tile app-tile--team" data-app="standup"><span class="app-ic">◷</span><span class="app-tx"><span class="app-nm">Daily standup</span><span class="app-desc">Async check-ins</span></span></button>
              <button type="button" class="app-tile app-tile--team" data-app="goals"><span class="app-ic">◎</span><span class="app-tx"><span class="app-nm">Goals &amp; OKRs</span><span class="app-desc">Track objectives</span></span></button>
              <button type="button" class="app-tile app-tile--team" data-app="links"><span class="app-ic">🔖</span><span class="app-tx"><span class="app-nm">Team links</span><span class="app-desc">Shared resources</span></span></button>
            </div>
<?php endif; ?>
          </div>

          <!-- Opened app: back bar + a single app on stage -->
          <div class="suite-open" id="suiteOpen" hidden>
            <div class="suite-bar">
              <button type="button" class="suite-back" id="suiteBack">‹ All apps</button>
              <h2 class="suite-open-title" id="suiteOpenTitle"></h2>
            </div>
            <div class="suite-stage" id="suiteStage">

            <div class="suite-app" data-app="cal" hidden>
          <!-- Integrated calendar: team events + AFG events + sessions + tasks + reminders -->
          <section class="pcard tool-cal" id="tlCal" data-csrf="<?= e($collabCsrf) ?>" data-org="<?= $isOrg ? '1' : '0' ?>">
            <div class="pcard-head cal-head">
              <h2>◗ Calendar</h2>
              <div class="cal-ctrls">
                <button type="button" class="cal-arrow" id="tlCalPrev" aria-label="Previous month">‹</button>
                <span class="cal-month" id="tlCalMonth">—</span>
                <button type="button" class="cal-arrow" id="tlCalNext" aria-label="Next month">›</button>
                <button type="button" class="pbtn pbtn-ghost pbtn-sm" id="tlCalToday">Today</button>
              </div>
            </div>
            <div class="pcard-body cal-body">
              <div class="cal-main">
                <div class="cal-dow"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
                <div class="cal-grid" id="tlCalGrid"><p class="pc-empty">Loading calendar…</p></div>
                <div class="cal-legend">
                  <span class="cal-key cal-key--event">Team event</span>
                  <span class="cal-key cal-key--afg">Afrovanguard</span>
                  <span class="cal-key cal-key--session">Mentorship</span>
                  <span class="cal-key cal-key--task">Task</span>
                  <span class="cal-key cal-key--reminder">Reminder</span>
                </div>
              </div>
              <aside class="cal-side">
                <h3 class="cal-side-title" id="tlCalAgendaTitle">Today</h3>
                <div class="cal-agenda" id="tlCalAgenda"></div>
<?php if ($isOrg): ?>
                <form id="tlCalForm" class="cal-form" autocomplete="off">
                  <h4>Add an event</h4>
                  <input id="tlCalTitle" class="cal-in" placeholder="Event title…" maxlength="300">
                  <div class="cal-row">
                    <input type="date" id="tlCalDate" class="cal-in" aria-label="Date">
                  </div>
                  <div class="cal-row">
                    <input type="time" id="tlCalStart" class="cal-in" aria-label="Start time">
                    <input type="time" id="tlCalEnd" class="cal-in" aria-label="End time">
                  </div>
                  <input id="tlCalLoc" class="cal-in" placeholder="Location (optional)" maxlength="200">
                  <input id="tlCalNote" class="cal-in" placeholder="Note (optional)" maxlength="500">
                  <div class="cal-form-foot">
                    <button type="submit" class="pbtn pbtn-gold">Add event</button>
                    <span class="poll-msg" id="tlCalMsg" role="status" aria-live="polite"></span>
                  </div>
                </form>
<?php endif; ?>
              </aside>
            </div>
          </section>
            </div><!-- /cal -->

            <div class="suite-app" data-app="notes" hidden>
            <section class="pcard tool" id="tlNotes">
              <div class="pcard-head"><h2>✎ Notes</h2><span class="tool-meta" id="tlNotesMeta">Autosaves</span></div>
              <div class="pcard-body">
                <textarea id="tlNotesArea" class="tool-notes" placeholder="Jot anything… it autosaves as you type."></textarea>
                <div class="tool-row tool-row--foot"><span class="tool-hint" id="tlNotesCount">0 words</span><button type="button" class="pbtn pbtn-ghost" id="tlNotesClear">Clear</button></div>
              </div>
            </section>
            </div><!-- /notes -->

            <div class="suite-app" data-app="focus" hidden>
            <section class="pcard tool" id="tlFocus">
              <div class="pcard-head"><h2>◐ Focus timer</h2><span class="tool-meta"><b id="tlFocusSessions">0</b> done today</span></div>
              <div class="pcard-body tool-focus">
                <div class="focus-mode" id="tlFocusMode">
                  <button type="button" class="fm-btn is-on" data-min="25" data-mode="Focus">Focus · 25</button>
                  <button type="button" class="fm-btn" data-min="5" data-mode="Break">Break · 5</button>
                  <button type="button" class="fm-btn" data-min="15" data-mode="Long break">Long · 15</button>
                </div>
                <div class="focus-clock" id="tlFocusClock">25:00</div>
                <div class="focus-actions">
                  <button type="button" class="pbtn pbtn-gold" id="tlFocusStart">Start</button>
                  <button type="button" class="pbtn pbtn-ghost" id="tlFocusReset">Reset</button>
                </div>
              </div>
            </section>
            </div><!-- /focus -->

            <div class="suite-app" data-app="habits" hidden>
            <section class="pcard tool" id="tlHabits">
              <div class="pcard-head"><h2>✓ Habits</h2><span class="tool-meta" id="tlHabitsMeta"></span></div>
              <div class="pcard-body">
                <form id="tlHabitAdd" class="tool-row" autocomplete="off"><input id="tlHabitInput" placeholder="Add a daily habit…" maxlength="60"><button class="pbtn pbtn-gold" type="submit">Add</button></form>
                <ul class="habit-list" id="tlHabitList"></ul>
              </div>
            </section>
            </div><!-- /habits -->

            <div class="suite-app" data-app="countdown" hidden>
            <section class="pcard tool" id="tlCountdown">
              <div class="pcard-head"><h2>◔ Countdown</h2></div>
              <div class="pcard-body tool-cd">
                <div class="cd-set" id="tlCdSet">
                  <input id="tlCdLabel" placeholder="Counting down to…" maxlength="60">
                  <input type="date" id="tlCdDate">
                  <button type="button" class="pbtn pbtn-gold" id="tlCdSave">Set</button>
                </div>
                <div class="cd-view" id="tlCdView" hidden>
                  <div class="cd-big"><b id="tlCdNum">0</b><span id="tlCdUnit">days</span></div>
                  <p class="cd-label" id="tlCdShow"></p>
                  <button type="button" class="pbtn pbtn-ghost" id="tlCdClear">Clear</button>
                </div>
              </div>
            </section>
            </div><!-- /countdown -->

            <div class="suite-app" data-app="rem" hidden>
            <section class="pcard tool tool-rem" id="tlRem" data-csrf="<?= e($collabCsrf) ?>">
              <div class="pcard-head"><h2>⏰ Reminders</h2><span class="tool-meta" id="tlRemMeta"></span></div>
              <div class="pcard-body">
                <form id="tlRemForm" class="rem-form" autocomplete="off">
                  <input id="tlRemText" class="rem-in" placeholder="Remind me to…" maxlength="300">
                  <div class="rem-row">
                    <input type="datetime-local" id="tlRemDue" class="rem-due" aria-label="Due (optional)">
                    <button type="submit" class="pbtn pbtn-gold">Add</button>
                  </div>
                  <span class="poll-msg" id="tlRemMsg" role="status" aria-live="polite"></span>
                </form>
                <ul class="rem-list" id="tlRemList"></ul>
                <div class="rem-tz">
                  <label for="tlRemTz">Times shown in</label>
                  <select id="tlRemTz" class="rem-tz-sel" data-csrf="<?= e($collabCsrf) ?>">
<?php $curTz = av_user_tz((int) $u['id']); foreach (Prefs::TIMEZONES as $tzLabel => $tzId): ?>
                    <option value="<?= e($tzId) ?>"<?= $tzId === $curTz ? ' selected' : '' ?>><?= e($tzLabel) ?></option>
<?php endforeach; ?>
                  </select>
                </div>
              </div>
            </section>
            </div><!-- /rem -->

<?php if ($isOrg): ?>
            <div class="suite-app" data-app="meet" hidden>
          <!-- Standardized meetings: schedule (with a link + cadence) and get AI minutes -->
          <section class="pcard tool-meet" id="tlMeet" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>🎥 Meetings</h2><span class="pchip pchip--indigo">Members · shared</span></div>
            <div class="pcard-body">
              <form id="tlMeetForm" class="meet-form" autocomplete="off">
                <input id="tlMeetTitle" class="meet-in" placeholder="Meeting title…" maxlength="200">
                <div class="meet-row">
                  <label class="meet-f"><span>When</span><input type="datetime-local" id="tlMeetWhen" class="meet-in"></label>
                  <label class="meet-f"><span>Length</span>
                    <select id="tlMeetDur" class="meet-in">
                      <option value="15">15 min</option>
                      <option value="30" selected>30 min</option>
                      <option value="45">45 min</option>
                      <option value="60">1 hour</option>
                      <option value="90">1.5 hours</option>
                      <option value="120">2 hours</option>
                    </select>
                  </label>
                  <label class="meet-f"><span>Repeats</span>
                    <select id="tlMeetFreq" class="meet-in">
                      <option value="once" selected>One-off</option>
                      <option value="daily">Every day</option>
                      <option value="weekdays">Every weekday</option>
                      <option value="weekly">Every week</option>
                      <option value="biweekly">Every 2 weeks</option>
                      <option value="monthly">Every month</option>
                    </select>
                  </label>
                </div>
                <input id="tlMeetWho" class="meet-in" placeholder="Invite by email (comma-separated, optional)" maxlength="600">
                <input id="tlMeetAgenda" class="meet-in" placeholder="Agenda / notes (optional)" maxlength="2000">
                <label class="meet-bot"><input type="checkbox" id="tlMeetRec"> <span>🤖 Add the recording bot — auto-capture &amp; transcribe this meeting</span></label>
                <div class="meet-form-foot">
                  <button type="submit" class="pbtn pbtn-gold">Schedule meeting</button>
                  <span class="poll-msg" id="tlMeetMsg" role="status" aria-live="polite"></span>
                </div>
                <p class="meet-hint">A Google Meet link is created and the meeting is added to everyone’s Google Calendar with an invite. Minutes are generated by Gemini Flash from the Google Meet transcript, an uploaded recording, or pasted text.</p>
              </form>
              <div class="meet-list" id="tlMeetList"><p class="pc-empty">Loading meetings…</p></div>
            </div>
          </section>
            </div><!-- /meet -->

            <div class="suite-app" data-app="board" hidden>
          <!-- Enterprise: Kanban board — shared team workflow -->
          <section class="pcard tool-board" id="tlBoard" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>▦ Team board</h2><span class="pchip pchip--indigo">Members · shared</span></div>
            <div class="pcard-body">
              <div class="board-cols" id="tlBoardCols"><p class="pc-empty">Loading board…</p></div>
            </div>
          </section>
            </div><!-- /board -->

            <div class="suite-app" data-app="polls" hidden>
          <!-- Enterprise: Team Polls — collaborative decisions with live tallies -->
          <section class="pcard tool-polls" id="tlPolls" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>▤ Team polls</h2><span class="pchip pchip--indigo">Members · shared</span></div>
            <div class="pcard-body">
              <form id="tlPollForm" class="poll-new" autocomplete="off">
                <input id="tlPollQ" class="poll-q" placeholder="Ask the team a question…" maxlength="300">
                <div class="poll-opts" id="tlPollOpts">
                  <input class="poll-opt" placeholder="Option 1" maxlength="120">
                  <input class="poll-opt" placeholder="Option 2" maxlength="120">
                </div>
                <div class="poll-new-foot">
                  <button type="button" class="pbtn pbtn-ghost" id="tlPollAddOpt">+ Add option</button>
                  <button type="submit" class="pbtn pbtn-gold">Create poll</button>
                  <span class="poll-msg" id="tlPollMsg" role="status" aria-live="polite"></span>
                </div>
              </form>
              <div class="poll-list" id="tlPollList"><p class="pc-empty">Loading polls…</p></div>
            </div>
          </section>
            </div><!-- /polls -->

            <div class="suite-app" data-app="standup" hidden>
          <!-- Enterprise: Async standup — daily team check-ins -->
          <section class="pcard tool-standup" id="tlStandup" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>◷ Daily standup</h2><span class="pchip pchip--indigo">Members · today</span></div>
            <div class="pcard-body">
              <form id="tlSuForm" class="su-form" autocomplete="off">
                <label class="su-field"><span>✅ What I did</span><textarea id="tlSuDone" class="su-in" rows="2" maxlength="1000" placeholder="Yesterday / recently…"></textarea></label>
                <label class="su-field"><span>▶ What's next</span><textarea id="tlSuNext" class="su-in" rows="2" maxlength="1000" placeholder="Today's focus…"></textarea></label>
                <label class="su-field"><span>⛔ Blockers</span><textarea id="tlSuBlk" class="su-in" rows="1" maxlength="1000" placeholder="Anything in the way? (optional)"></textarea></label>
                <div class="su-foot">
                  <button type="submit" class="pbtn pbtn-gold" id="tlSuSave">Post update</button>
                  <button type="button" class="pbtn pbtn-ghost" id="tlSuClear" hidden>Clear mine</button>
                  <span class="poll-msg" id="tlSuMsg" role="status" aria-live="polite"></span>
                </div>
              </form>
              <div class="su-board" id="tlSuBoard"><p class="pc-empty">Loading today's board…</p></div>
            </div>
          </section>
            </div><!-- /standup -->

            <div class="suite-app" data-app="goals" hidden>
          <!-- Enterprise: Goals & OKRs — shared objectives with progress -->
          <section class="pcard tool-goals" id="tlGoals" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>◎ Goals &amp; OKRs</h2><span class="pchip pchip--indigo">Members · shared</span></div>
            <div class="pcard-body">
              <form id="tlGoalForm" class="goal-form" autocomplete="off">
                <input id="tlGoalTitle" class="goal-in" placeholder="Set an objective…" maxlength="300">
                <div class="goal-row">
                  <input id="tlGoalTarget" class="goal-in goal-in--sm" placeholder="Target metric (optional)" maxlength="200">
                  <button type="submit" class="pbtn pbtn-gold">Add goal</button>
                </div>
                <span class="poll-msg" id="tlGoalMsg" role="status" aria-live="polite"></span>
              </form>
              <div class="goal-list" id="tlGoalList"><p class="pc-empty">Loading goals…</p></div>
            </div>
          </section>
            </div><!-- /goals -->

            <div class="suite-app" data-app="links" hidden>
          <!-- Enterprise: Team links — shared resource hub -->
          <section class="pcard tool-links" id="tlLinks" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>🔖 Team links</h2><span class="pchip pchip--indigo">Members · shared</span></div>
            <div class="pcard-body">
              <form id="tlLinkForm" class="link-form" autocomplete="off">
                <input id="tlLinkUrl" class="link-in" placeholder="Paste a URL…" maxlength="600">
                <div class="link-row">
                  <input id="tlLinkTitle" class="link-in link-in--sm" placeholder="Title (optional)" maxlength="200">
                  <button type="submit" class="pbtn pbtn-gold">Save</button>
                </div>
                <input id="tlLinkNote" class="link-in" placeholder="Note (optional)" maxlength="300">
                <span class="poll-msg" id="tlLinkMsg" role="status" aria-live="polite"></span>
              </form>
              <div class="link-list" id="tlLinkList"><p class="pc-empty">Loading links…</p></div>
            </div>
          </section>
            </div><!-- /links -->
<?php endif; ?>
            </div><!-- /suiteStage -->
          </div><!-- /suiteOpen -->
        </section>
