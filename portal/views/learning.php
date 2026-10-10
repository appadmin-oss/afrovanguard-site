<?php /* portal/views/learning.php — moved verbatim from portal/index.php v1 (row 14 shell
   rebuild). Markup and ids unchanged; restyled through portal.css + avp.css tokens.
   Rendered inside the shell, which defines the variables it reads. */ ?>
        <!-- ============================================================ -->
        <!-- LEARNING                                                     -->
        <!-- ============================================================ -->
        <section class="pview" id="view-learning" data-view="learning" hidden>
          <div class="view-head"><h1>Learning</h1><a class="pcard-link" href="/academy/">Browse the Academy →</a></div>
          <section class="pcard">
            <div class="pcard-head"><h2>My learning</h2><span class="pchip pchip--indigo"><?= count($courses) ?> enrolled</span></div>
            <div class="pcard-body">
<?php if ($courses): ?>
              <p class="pcard-note"><b><?= count($courses) ?></b> programme<?= count($courses) === 1 ? '' : 's' ?><?= $inProgress ? ' · ' . $inProgress . ' in progress' : '' ?><?= $certs ? ' · ' . $certs . ' 🎓 certificate' . ($certs === 1 ? '' : 's') : '' ?></p>
              <div class="learn-list">
<?php foreach ($courses as $c): ?>                <a class="learn-row" href="/academy/<?= e($c['slug']) ?>/learn/">
                  <div class="learn-info"><span class="learn-title"><?= e($c['title']) ?></span><span class="learn-meta"><?= $c['complete'] ? '✓ Complete' : ((int) $c['pct']) . '% complete' ?><?= $c['certified'] ? ' · 🎓 Certified' : '' ?></span></div>
                  <div class="learn-bar" aria-hidden="true"><span style="width:<?= (int) $c['pct'] ?>%"></span></div>
                </a>
<?php endforeach; ?>              </div>
<?php else: ?>
              <p class="pc-empty">You haven’t joined a programme yet. <a href="/academy/">Explore the Academy →</a></p>
<?php endif; ?>
            </div>
          </section>
        </section>
