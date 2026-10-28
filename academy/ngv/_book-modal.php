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
                <label class="bk-f bk-f--wide" id="bkPick"><span>Book <em>from the programme's book list</em></span>
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
              <label class="bk-f bk-f--wide"><span>The whole book: what did it argue, and did you agree?
                <em>at least <?= (int) NgvReading::MIN_REFLECTION ?> characters</em></span>
                <textarea id="bkReflection" rows="8" maxlength="6000"></textarea>
                <span class="bk-count" id="bkReflCount">0</span></label>
              <label class="bk-f bk-f--wide"><span>One thing you have done, or will do, because of it
                <em>your track lead may ask you about this</em></span>
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
