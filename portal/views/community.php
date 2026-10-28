<?php /* portal/views/community.php — moved verbatim from portal/index.php v1 (row 14 shell
   rebuild). Markup and ids unchanged; restyled through portal.css + avp.css tokens.
   Rendered inside the shell, which defines the variables it reads. */ ?>
        <!-- ============================================================ -->
        <!-- COMMUNITY  (the members' social space, embedded)             -->
        <!-- ============================================================ -->
        <section class="pview pview--community" id="view-community" data-view="community" hidden>
          <div class="view-head">
            <div>
              <h1>Community</h1>
              <p class="view-sub"><?= $isOrg ? 'Talk with members, share field notes, and ask the Afrovanguard bot.' : 'Share field notes and learn alongside the wider community.' ?></p>
            </div>
            <span class="ptop-online view-live"><span class="dot-live"></span>Live</span>
          </div>
<?php av_render_community((int) $u['id'], $isOrg, ['hero' => false, 'space' => $communitySpace]); ?>
        </section>
