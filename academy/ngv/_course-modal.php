<?php /* academy/ngv/_course-modal.php — the course sheet, opened from a course
   in the list by academy/ngv/courses-take.js. Drawn ONCE per page, by
   portal/_courses-card.php.

   Deliberately the same shape as the book sheet (_book-modal.php), because
   somebody who has learned one should not have to learn the other. What
   differs is what a course IS: modules rather than chapters, a provider and
   a link rather than an author, and a certificate that may be attached and
   is never what the claim rests on. */ ?>
        <div class="bkmodal" id="coModal" hidden role="dialog" aria-modal="true" aria-labelledby="coTitle">
          <div class="bkmodal-card" role="document">
            <div class="bkmodal-head">
              <h3 id="coTitle">Course</h3>
              <button type="button" class="bkmodal-x" id="coClose" aria-label="Close">&times;</button>
            </div>
            <div class="bkmodal-body">
              <p class="bk-status" id="coStatus" hidden></p>
              <div class="bk-grid">
                <?php /* The course's NAME is the dialog's heading and is not
                         repeated here — the same fact twice on one screen is
                         noise. What belongs here is what the heading cannot
                         say: who teaches it and where to open it. */ ?>
                <div class="bk-f bk-f--wide bk-pickrow">
                  <img class="bk-cover" id="coCover" alt="" hidden width="54" height="76">
                  <div class="bk-picklabel">
                    <span class="co-where" id="coWhere"></span>
                    <span class="co-note" id="coNote"></span>
                  </div>
                </div>
                <label class="bk-f"><span>Started</span><input id="coStarted" type="date"></label>
                <label class="bk-f"><span>Finished</span><input id="coFinished" type="date"></label>
              </div>

              <div id="coModules" class="bk-chapters" hidden>
                <p class="bk-chapters-h">A note per module
                  <em>at least <?= (int) NgvCourses::MIN_MODULE ?> characters each — what it covered, in your words. Leave one blank if you have not reached it.</em></p>
                <div id="coModuleList"></div>
              </div>

              <div class="bk-sits" id="coSits">
                <p class="bk-chapters-h">The time you put in
                  <em>log each sitting — at least one before you can send it</em></p>
                <div id="coSitList"></div>
                <div class="bk-sit-form" id="coSitForm" hidden>
                  <fieldset class="bk-fs">
                    <legend>The sitting</legend>
                    <div class="bk-sit-grid">
                      <label class="bk-f"><span>Day</span><input id="coSitDay" type="date"></label>
                      <label class="bk-f"><span>Minutes</span><input id="coSitMins" type="number" min="1" max="<?= (int) NgvCourses::MAX_SESSION_MINUTES ?>" inputmode="numeric"></label>
                      <label class="bk-f" id="coSitFromF"><span>Modules <em>from</em></span><input id="coSitFrom" type="number" min="1" inputmode="numeric" aria-label="First module this sitting covered"></label>
                      <label class="bk-f" id="coSitToF"><span>Modules <em>to</em></span><input id="coSitTo" type="number" min="1" inputmode="numeric" aria-label="Last module this sitting covered"></label>
                    </div>
                    <div class="bk-grid">
                      <label class="bk-f"><span>Where</span><input id="coSitPlace" maxlength="80" placeholder="At home"></label>
                      <label class="bk-f bk-f--wide"><span>How it went <em>optional</em></span>
                        <select id="coSitMood"><option value="">Rather not say</option></select></label>
                    </div>
                  </fieldset>
                  <fieldset class="bk-fs">
                    <legend>What you made of it</legend>
                    <label class="bk-f bk-f--wide"><span>What this sitting covered</span>
                      <textarea id="coSitNotes" rows="3" maxlength="4000" data-min="<?= (int) NgvCourses::MIN_SESSION_NOTES ?>"></textarea>
                      <span class="bk-count" data-for="coSitNotes">0</span></label>
                    <label class="bk-f bk-f--wide"><span>What you agree with, or argue against — and why</span>
                      <textarea id="coSitStance" rows="3" maxlength="4000" data-min="<?= (int) NgvCourses::MIN_SESSION_STANCE ?>"></textarea>
                      <span class="bk-count" data-for="coSitStance">0</span></label>
                    <label class="bk-f bk-f--wide"><span>What you will do differently <em>the life application</em></span>
                      <textarea id="coSitApply" rows="2" maxlength="4000" data-min="<?= (int) NgvCourses::MIN_SESSION_APPLICATION ?>"></textarea>
                      <span class="bk-count" data-for="coSitApply">0</span></label>
                  </fieldset>
                  <p class="bk-err" id="coSitErr" hidden role="alert"></p>
                  <div class="bk-sit-acts">
                    <button type="button" class="pbtn pbtn-gold" id="coSitSave">Save this sitting</button>
                    <button type="button" class="pbtn pbtn-ghost" id="coSitCancel">Cancel</button>
                  </div>
                </div>
                <button type="button" class="pbtn pbtn-ghost" id="coSitAdd">Log a sitting</button>
              </div>

              <label class="bk-f bk-f--wide"><span>The whole course: what did it teach, and what did you make of it?
                <em>at least <?= (int) NgvCourses::MIN_REFLECTION ?> characters</em></span>
                <textarea id="coReflection" rows="8" maxlength="6000"></textarea>
                <span class="bk-count" id="coReflCount">0</span></label>
              <label class="bk-f bk-f--wide"><span>One thing you have done, or will do, because of it
                <em>you may be asked about this</em></span>
                <textarea id="coTakeaway" rows="3" maxlength="600"></textarea>
                <span class="bk-count" id="coTakeCount">0</span></label>

              <?php /* The certificate is evidence BESIDE the claim, never the
                       claim itself: a certificate says a provider's system
                       recorded a completion, and the sittings say what the
                       person did. The copy says so, because somebody who
                       thinks the upload is the point will stop writing. */ ?>
              <div class="co-cert" id="coCert">
                <p class="bk-chapters-h">Your certificate <em>optional — it sits beside your account of the work, and is not what the course rests on</em></p>
                <p class="co-cert-have" id="coCertHave" hidden><a id="coCertLink" href="#" target="_blank" rel="noopener noreferrer">The certificate you attached</a>
                  <button type="button" class="pbtn pbtn-ghost" id="coCertClear">Remove</button></p>
                <?php /* The input is visually hidden, NOT [hidden]: a hidden input
                         takes no focus, and the only way to reach it would have
                         been the mouse. */ ?>
                <label class="co-cert-pick">
                  <input type="file" id="coCertFile" class="av-sr" accept="application/pdf,image/jpeg,image/png,image/webp">
                  <span class="pbtn pbtn-ghost">Attach a certificate</span>
                </label>
              </div>

              <p class="bk-err" id="coErr" hidden role="alert"></p>
            </div>
            <div class="bkmodal-foot">
              <button type="button" class="pbtn pbtn-ghost" id="coSaveDraft">Save draft</button>
              <button type="button" class="pbtn pbtn-gold" id="coSubmit">Send for checking</button>
            </div>
          </div>
        </div>
