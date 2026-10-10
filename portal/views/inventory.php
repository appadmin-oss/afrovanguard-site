<?php /* portal/views/inventory.php — moved verbatim from portal/index.php v1 (row 14 shell
   rebuild). Markup and ids unchanged; restyled through portal.css + avp.css tokens.
   Rendered inside the shell, which defines the variables it reads. */ ?>
        <!-- ============================================================ -->
        <!-- INVENTORY — the centre's register, read from here             -->
        <!-- ============================================================ -->
<?php if (CacSso::ready()): ?>
        <section class="pview" id="view-inventory" data-view="inventory" hidden
                 data-inv-url="<?= e(CacInventory::consoleUrl()) ?>">
          <div class="view-head">
            <h1>Inventory</h1>
            <a class="pcard-link" href="<?= e(CacInventory::consoleUrl()) ?>" target="_blank" rel="noopener">
              Change an item in the console &rarr;
            </a>
          </div>

          <section class="pcard">
            <div class="pcard-head">
              <div>
                <h2>What the centre has</h2>
                <span class="task-head-sub" id="invCount">
                  Read from CACENTRE, which is where these are kept and changed.
                </span>
              </div>
            </div>
            <div class="pcard-body">
              <?php /* A form, so Enter submits and a screen reader announces
                       it as one. It never actually navigates — the script
                       below takes it over — but it works as a form first. */ ?>
              <form class="inv-filters" id="invForm" autocomplete="off">
                <label class="inv-f inv-f--grow">
                  <span class="pc-sr">Search the register</span>
                  <input type="search" id="invQ" placeholder="Name, tag, serial or who has it">
                </label>
                <label class="inv-f">
                  <span class="pc-sr">Category</span>
                  <select id="invCat"><option value="">Any category</option></select>
                </label>
                <label class="inv-f">
                  <span class="pc-sr">Where</span>
                  <select id="invSite"><option value="">Anywhere</option></select>
                </label>
                <label class="inv-f">
                  <span class="pc-sr">Status</span>
                  <select id="invStatus"><option value="">Any status</option></select>
                </label>
                <button class="pbtn" type="submit">Search</button>
              </form>

              <p class="pc-empty" id="invMsg" hidden></p>

              <div class="inv-wrap">
                <table class="inv-table" id="invTable" hidden>
                  <thead>
                    <tr>
                      <th scope="col">Item</th>
                      <th scope="col">Where</th>
                      <th scope="col">Who has it</th>
                      <th scope="col">Status</th>
                    </tr>
                  </thead>
                  <tbody id="invRows"></tbody>
                </table>
              </div>

              <div class="inv-more" id="invMore" hidden>
                <button class="pbtn pbtn--ghost" type="button" id="invPrev">&larr; Back</button>
                <span id="invPage"></span>
                <button class="pbtn pbtn--ghost" type="button" id="invNext">More &rarr;</button>
              </div>
            </div>
          </section>
        </section>
