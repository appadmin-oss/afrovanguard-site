<?php /* portal/views/meet-modal.php — moved verbatim from portal/index.php v1 (row 14 shell
   rebuild). Markup and ids unchanged; restyled through portal.css + avp.css tokens.
   Rendered inside the shell, which defines the variables it reads. */ ?>
        <!-- Schedule-a-meeting modal (creates a Google Meet + calendar invite) -->
        <div class="pm-scrim" id="meetScrim" hidden>
          <div class="pm-modal" role="dialog" aria-modal="true" aria-labelledby="meetModalTitle" id="meetModal" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pm-head"><h2 id="meetModalTitle">Schedule a meeting</h2><button type="button" class="pm-x" id="meetClose" aria-label="Close">✕</button></div>
            <form id="meetForm" class="pm-body" autocomplete="off">
              <label class="pm-f"><span>Title</span><input type="text" id="mmTitle" maxlength="200" required placeholder="e.g. Clean-up drive planning"></label>
              <div class="pm-row">
                <label class="pm-f"><span>When</span><input type="datetime-local" id="mmWhen" required></label>
                <label class="pm-f pm-f--sm"><span>Duration</span>
                  <select id="mmDur"><option value="15">15 min</option><option value="30" selected>30 min</option><option value="45">45 min</option><option value="60">1 hour</option><option value="90">1.5 hours</option></select>
                </label>
                <label class="pm-f pm-f--sm"><span>Repeats</span>
                  <select id="mmFreq"><option value="once" selected>Once</option><option value="weekly">Weekly</option><option value="biweekly">Every 2 weeks</option><option value="monthly">Monthly</option></select>
                </label>
              </div>
              <label class="pm-f"><span>Invite (comma-separated emails)</span><input type="text" id="mmAtt" placeholder="ada@afrovanguard.org.ng, bode@…"></label>
              <label class="pm-f"><span>Agenda <small>(optional)</small></span><textarea id="mmAgenda" rows="2" maxlength="2000" placeholder="What we’ll cover…"></textarea></label>
              <p class="pm-msg" id="mmMsg" hidden></p>
              <div class="pm-actions">
                <button type="button" class="pbtn pbtn-ghost" id="meetCancel">Cancel</button>
                <button type="submit" class="pbtn pbtn-gold" id="mmSubmit">Create meeting &amp; Meet link</button>
              </div>
            </form>
          </div>
        </div>
