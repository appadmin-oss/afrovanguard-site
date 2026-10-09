<?php /* portal/views/diary.php — moved verbatim from portal/index.php v1 (row 14 shell
   rebuild). Markup and ids unchanged; restyled through portal.css + avp.css tokens.
   Rendered inside the shell, which defines the variables it reads. */ ?>
        <!-- ============================================================ -->
        <!-- DIARY                                                        -->
        <!-- ============================================================ -->
        <section class="pview" id="view-diary" data-view="diary" hidden>
          <div class="view-head">
            <div><h1>My Diary</h1><p class="view-sub">Write with a rich editor. Private stays yours; public is reviewed before it joins the Diary.</p></div>
            <a class="pcard-link" href="/diary/" target="_blank" rel="noopener">Open the public Diary →</a>
          </div>
          <!-- ── Notebooks rail + finder ──────────────────────────────────
               The rail is the diary's spine: buckets, notebooks, tags. Rendered
               client-side from /portal/notebooks.php rather than server-side,
               because every action on it (file, pin, archive, tag) changes the
               counts and a full reload per action would make organising the
               diary feel like paperwork. -->
          <div class="nb-shell">
            <aside class="nb-side" id="nbRail" aria-label="Notebooks" data-csrf="<?= e($collabCsrf) ?>"></aside>

            <div class="nb-main">
              <div class="nb-finder">
                <input type="search" id="nbSearch" class="nb-search"
                       placeholder="Search your diary — titles, bodies, and every tab"
                       aria-label="Search your diary">
                <span class="nb-msg" id="nbMsg" role="status" aria-live="polite"></span>
              </div>
              <div class="nb-bulk" id="nbBulk" hidden></div>

              <!-- An open entry, as a document with tabs. -->
              <section class="pcard nb-doc" id="nbDoc" hidden>
                <div class="pcard-head">
                  <h2>Entry</h2>
                  <button type="button" class="pbtn pbtn-ghost pbtn-sm" id="nbDocClose">Close</button>
                </div>
                <div class="pcard-body">
                  <div class="nb-tabstrip" id="nbTabStrip" role="tablist" aria-label="Tabs in this entry"></div>
                  <label class="pd-field"><span>Tab name</span>
                    <input type="text" id="nbTabTitle" maxlength="80" placeholder="Entry">
                  </label>
                  <textarea id="nbTabBody" class="nb-tabbody" rows="12" placeholder="Write this tab…"></textarea>
                  <div class="nb-docfoot">
                    <button type="button" class="pbtn pbtn-gold" id="nbTabSave">Save tab</button>
                    <span class="nb-hint" id="nbTabHint"></span>
                  </div>
                </div>
              </section>

              <section class="pcard">
                <div class="pcard-head"><h2>Entries</h2></div>
                <div class="pcard-body"><ul class="nb-entries" id="nbList"></ul></div>
              </section>
            </div>
          </div>

          <div class="pcols pcols--diary">
            <div class="pcol pcol--main">
              <section class="pcard" id="pdiary">
                <div class="pcard-body">
                  <form id="pdCompose" class="pd-form" autocomplete="off">
                    <div class="pd-meta">
                      <label class="pd-field"><span>Category</span>
                        <select id="pdKind">
                          <option value="private" selected>🔒 Private — only you</option>
                          <option value="public">🌐 Public — submit to the Diary</option>
                          <option value="event">📅 Event — a public happening</option>
                        </select>
                      </label>
                      <label class="pd-field"><span>Template</span>
                        <select id="pdTemplate">
                          <option value="">Blank page</option>
                          <option value="daily">Daily reflection</option>
                          <option value="field">Field note</option>
                          <option value="project">Project log</option>
                          <option value="meeting">Meeting notes</option>
                          <option value="gratitude">Gratitude</option>
                          <option value="weekly">Weekly review</option>
                          <option value="idea">Idea / proposal</option>
                        </select>
                      </label>
                      <label class="pd-field"><span>Date</span>
                        <input type="date" id="pdDate" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>">
                      </label>
                    </div>
                    <label class="pd-field pd-field--title"><span>Title <em>(optional)</em></span>
                      <input type="text" id="pdTitle" maxlength="160" placeholder="A short headline">
                    </label>
                    <input type="hidden" id="pdBody" name="body">
                    <trix-editor input="pdBody" class="pd-editor" placeholder="Write your entry…"></trix-editor>
                    <div class="pd-foot">
                      <button type="submit" class="pbtn pbtn-gold" id="pdSave">Save entry</button>
                      <span class="pd-hint" id="pdHint">🔒 Private entries are visible only to you.</span>
                      <span class="pd-msg" id="pdMsg" role="status" aria-live="polite"></span>
                    </div>
                  </form>
                </div>
              </section>
            </div>
            <div class="pcol pcol--side">
              <section class="pcard">
                <div class="pcard-head"><h2>Your entries</h2><span class="pchip pchip--gray" id="pdCount"><?= count($myEntries) ?></span></div>
                <div class="pcard-body">
                  <ul class="pd-list" id="pdList">
<?php if ($myEntries): foreach ($myEntries as $e) { echo self_diary_item($e); } else: ?>
                    <li class="pc-empty" id="pdEmpty">No entries yet — write your first above.</li>
<?php endif; ?>
                  </ul>
                </div>
              </section>
              <section class="pcard" id="pdSharedCard" hidden>
                <div class="pcard-head"><h2>Shared with me</h2><span class="pchip pchip--indigo" id="pdSharedCount">0</span></div>
                <div class="pcard-body"><ul class="pd-list" id="pdShared"></ul></div>
              </section>
            </div>
          </div>

          <!-- read modal for shared-with-me entries -->
          <div class="pd-modal" id="pdModal" hidden>
            <div class="pd-modal-back" data-pdclose></div>
            <div class="pd-modal-card" role="dialog" aria-modal="true" aria-labelledby="pdModalTitle">
              <button type="button" class="pd-modal-x" data-pdclose aria-label="Close">✕</button>
              <h2 id="pdModalTitle"></h2>
              <p class="pd-modal-meta" id="pdModalMeta"></p>
              <div class="pd-modal-body" id="pdModalBody"></div>
            </div>
          </div>
        </section>
