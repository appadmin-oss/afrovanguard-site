<?php /* academy/ngv/_book-modal.php — the book claim sheet, opened from a slot by
   academy/ngv/reading.js. Drawn ONCE per page: by _dashboard-body.php where
   the reading card is, and by the portal's Programme view (portal/views/ngv.php). */ ?>
        <!-- The claim sheet. One at a time, opened from a slot. -->
        <div class="bkmodal" id="bkModal" hidden role="dialog" aria-modal="true" aria-labelledby="bkTitle">
          <div class="bkmodal-card" role="document">
            <div class="bkmodal-head">
              <h3 id="bkTitle">Book <span id="bkSlot">1</span></h3>
              <button type="button" class="bkmodal-x" id="bkClose" aria-label="Close">&times;</button>
            </div>
            <div class="bkmodal-body">
              <p class="bk-status" id="bkStatus" hidden></p>
              <div class="bk-grid">
                <label class="bk-f bk-f--wide" id="bkPick"><span>Book <em>from the book list</em></span>
                  <select id="bkBook"><option value="">Choose a book…</option></select></label>
                <p class="bk-f bk-f--wide bk-legacy" id="bkLegacy" hidden></p>
                <p class="bk-f bk-f--wide bk-none" id="bkNone" hidden>The book list is empty for now. The NGV office adds the books — check back soon.</p>
                <label class="bk-f"><span>Started</span>
                  <input id="bkStarted" type="date"></label>
                <label class="bk-f"><span>Finished</span>
                  <input id="bkFinished" type="date"></label>
              </div>
              <div id="bkChapters" class="bk-chapters" hidden>
                <p class="bk-chapters-h">A summary of each chapter <em>at least <?= (int) NgvReading::MIN_CHAPTER ?> characters each — what the chapter said, in your words</em></p>
                <div id="bkChapterList"></div>
              </div>
              <?php /* THE SITTINGS. A book is read over days, and the record
                       used to keep only what was written at the end — so the
                       programme could see that twenty-four were finished and
                       nothing about the reading. Each sitting also names the
                       VALUE it grew, which is what the challenge is for and
                       the one thing a list of titles cannot show. */ ?>
              <div class="bk-sits" id="bkSits">
                <p class="bk-chapters-h">Your reading of this book
                  <em>log each sitting — at least one before you can send it</em></p>
                <div id="bkSitList"></div>
                <div class="bk-sit-form" id="bkSitForm" hidden>
                  <fieldset class="bk-fs">
                    <legend>The sitting</legend>
                    <div class="bk-sit-grid">
                      <label class="bk-f"><span>Day</span><input id="bkSitDay" type="date"></label>
                      <label class="bk-f"><span>Minutes</span><input id="bkSitMins" type="number" min="1" max="<?= (int) NgvReading::MAX_SESSION_MINUTES ?>" inputmode="numeric"></label>
                      <label class="bk-f"><span>Chapters <em>from</em></span><input id="bkSitFrom" type="number" min="1" inputmode="numeric"></label>
                      <label class="bk-f"><span><em>to</em></span><input id="bkSitTo" type="number" min="1" inputmode="numeric"></label>
                    </div>
                    <div class="bk-grid">
                      <label class="bk-f" id="bkSitPagesF"><span>Pages <em>if it has no chapters</em></span><input id="bkSitPages" maxlength="40" placeholder="40–72"></label>
                      <label class="bk-f"><span>Where</span><input id="bkSitPlace" maxlength="80" placeholder="At the centre"></label>
                      <label class="bk-f bk-f--wide"><span>How it went <em>optional</em></span>
                        <select id="bkSitMood"><option value="">Rather not say</option></select></label>
                    </div>
                  </fieldset>
                  <fieldset class="bk-fs">
                    <legend>What you made of it</legend>
                    <label class="bk-f bk-f--wide"><span>What this sitting covered</span>
                      <textarea id="bkSitNotes" rows="3" maxlength="4000" data-min="<?= (int) NgvReading::MIN_SESSION_NOTES ?>"></textarea>
                      <span class="bk-count" data-for="bkSitNotes">0</span></label>
                    <label class="bk-f bk-f--wide"><span>What you agree with, or argue against — and why</span>
                      <textarea id="bkSitStance" rows="3" maxlength="4000" data-min="<?= (int) NgvReading::MIN_SESSION_STANCE ?>"></textarea>
                      <span class="bk-count" data-for="bkSitStance">0</span></label>
                    <label class="bk-f bk-f--wide"><span>What you will do differently <em>the life application</em></span>
                      <textarea id="bkSitApply" rows="2" maxlength="4000" data-min="<?= (int) NgvReading::MIN_SESSION_APPLICATION ?>"></textarea>
                      <span class="bk-count" data-for="bkSitApply">0</span></label>
                  </fieldset>
                  <p class="bk-err" id="bkSitErr" hidden role="alert"></p>
                  <div class="bk-sit-acts">
                    <button type="button" class="pbtn pbtn-gold" id="bkSitSave">Save this sitting</button>
                    <button type="button" class="pbtn pbtn-ghost" id="bkSitCancel">Cancel</button>
                  </div>
                </div>
                <button type="button" class="pbtn pbtn-ghost" id="bkSitAdd">Log a sitting</button>
              </div>

              <label class="bk-f bk-f--wide"><span>The whole book: what did it argue, and did you agree?
                <em>at least <?= (int) NgvReading::MIN_REFLECTION ?> characters</em></span>
                <textarea id="bkReflection" rows="8" maxlength="6000"></textarea>
                <span class="bk-count" id="bkReflCount">0</span></label>
              <label class="bk-f bk-f--wide"><span>One thing you have done, or will do, because of it
                <em>you may be asked about this</em></span>
                <textarea id="bkTakeaway" rows="3" maxlength="600"></textarea>
                <span class="bk-count" id="bkTakeCount">0</span></label>
              <p class="bk-err" id="bkErr" hidden role="alert"></p>
            </div>
            <div class="bkmodal-foot">
              <button type="button" class="pbtn pbtn-ghost" id="bkSaveDraft">Save draft</button>
              <button type="button" class="pbtn pbtn-gold" id="bkSubmit">Send for checking</button>
            </div>
          </div>
        </div>
