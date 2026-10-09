<?php /* portal/views/mentorship.php — moved verbatim from portal/index.php v1 (row 14 shell
   rebuild). Markup and ids unchanged; restyled through portal.css + avp.css tokens.
   Rendered inside the shell, which defines the variables it reads. */ ?>
        <!-- ============================================================ -->
        <!-- MENTORSHIP                                                   -->
        <!-- ============================================================ -->
        <section class="pview" id="view-mentorship" data-view="mentorship" hidden>
          <div class="view-head"><h1>Mentorship</h1><a class="pcard-link" href="/mentorship/">Open the mentor network →</a></div>

          <!-- Hours logged + consistency -->
          <section class="pcard">
            <div class="pcard-head"><h2>Your mentorship</h2><span class="pchip pchip--gold"><?= $isOrg ? 'Member' : 'Open' ?></span></div>
            <div class="pcard-body">
              <div class="mentor-stats mentor-stats--lg">
                <div class="mstat"><span class="mstat-n"><?= e($mentorHoursLabel) ?></span><span class="mstat-l">Hours logged</span></div>
                <div class="mstat"><span class="mstat-n"><?= (int) $mentorStats['attended'] ?></span><span class="mstat-l">Sessions attended</span></div>
                <div class="mstat"><span class="mstat-n"><?= (int) $mentorStats['held'] ?></span><span class="mstat-l">Sessions held</span></div>
                <div class="mstat"><span class="mstat-n"><?= $mentorStats['rate'] !== null ? (int) $mentorStats['rate'] . '%' : '—' ?></span><span class="mstat-l">Consistency</span></div>
              </div>
              <p class="pcard-note"><?= $isOrg ? 'Find a mentor, run your mentee inbox, and give back by mentoring others. Attended sessions are counted toward your logged hours.' : 'Get paired with an Afrovanguard mentor for guidance on your journey. Attended sessions count toward your logged hours.' ?></p>
              <a class="pbtn pbtn-soft" href="/mentorship/">Open mentor network →</a>
            </div>
          </section>

          <!-- Upcoming schedule — start the Meet from here; start/end auto-logged -->
          <section class="pcard" id="mentorSchedule" data-csrf="<?= e($collabCsrf) ?>">
            <div class="pcard-head"><h2>Your schedule</h2><a class="pcard-link" href="/mentorship/">Manage →</a></div>
            <div class="pcard-body">
<?php if ($upcoming): ?>              <ul class="mini-sched">
<?php foreach (array_slice($upcoming, 0, 6) as $s): $sd = strtotime((string) $s['when'] . ' UTC') ?: time();
                $mst = $s['ended_at'] !== '' ? 'done' : ($s['live'] ? 'live' : 'idle'); ?>
                <li class="msi" data-session="<?= (int) $s['id'] ?>" data-meet="<?= e($s['meet_url']) ?>" data-state="<?= $mst ?>" data-started="<?= e($s['started_at']) ?>" data-source="<?= e((string) ($s['source'] ?? '')) ?>">
                  <div class="msi-top">
                    <span class="ms-when"><?= e(date('j M', $sd)) ?> · <?= e(date('g:ia', $sd)) ?></span>
                    <span class="ms-title"><?= e($s['title']) ?> <span class="ms-with">· <?= e($s['role']) ?> <?= e($s['with']) ?></span></span>
                  </div>
                  <div class="msi-meet">
                    <button type="button" class="pbtn pbtn-gold msi-start"<?= $mst === 'done' ? ' hidden' : '' ?>><?= $s['live'] ? 'Join meeting' : 'Start meeting' ?></button>
                    <span class="msi-log"><?php
                      if ($s['ended_at'] !== '') {
                          $src = (string) ($s['source'] ?? '');
                          $vcls = in_array($src, ['meet', 'reports'], true) ? 'msi-verified' : 'msi-provisional';
                          echo '✓ Logged ' . e(self_fmt_dur((int) $s['duration_min'])) . ' · ' . e(date('g:ia', strtotime($s['started_at'] . ' UTC') ?: time())) . '–' . e(date('g:ia', strtotime($s['ended_at'] . ' UTC') ?: time()));
                          echo ' <span class="msi-src ' . $vcls . '">' . e(self_meet_source($src)) . '</span>';
                      }
                      elseif ($s['live']) { echo '<span class="msi-live"><span class="dot-live"></span>Live · <span class="msi-timer" aria-label="Elapsed meeting time">…</span></span>'; }
                    ?></span>
                  </div>
                </li>
<?php endforeach; ?>              </ul>
              <p class="msi-note">Start the meeting from here — the system logs the hours automatically. Times are reconciled against Google Meet’s own record for complete transparency, so the logged hours reflect the real call.</p>
<?php else: ?>              <p class="pc-empty">No upcoming sessions. <a href="/mentorship/">Book one with your mentor →</a></p>
<?php endif; ?>
            </div>
          </section>
        </section>
