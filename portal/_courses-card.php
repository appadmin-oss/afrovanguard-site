<?php
/**
 * portal/_courses-card.php — the courses half of somebody's learning record.
 *
 * Drawn beside the reading card, by the same two hosts: the NextGen Vanguard
 * programme view and the portal's own Reading page. One partial, for the same
 * reason the reading card is one — two drawings of the same shelf drift.
 *
 * What it shows depends on who is looking, and the difference is deliberate:
 *
 *   a vanguard      the programme's target — "4 of 12 this year"
 *   anybody else    what they have done, and nothing about what is left,
 *                   unless they have set themselves a number. A progress bar
 *                   reading "3 of 12" to somebody who never agreed to twelve
 *                   is a reproach, not information.
 *
 * The list is a MENU, not a syllabus. Only the Foundation group is asked of
 * every vanguard; Business, Art, Media and Tech are chosen with a track in
 * mind. The headings say so, because a flat list of eighteen titles reads as
 * eighteen things somebody is behind on.
 *
 * Expects from the host: $u, $rdKind (NgvReading::readerKind).
 */
declare(strict_types=1);

$rcProg   = NgvCourses::progress((int) $u['id']);
$rcMine   = NgvCourses::mine((int) $u['id']);
$rcGroups = NgvCourses::byGroup();
$rcTaken  = [];
foreach ($rcMine as $m) $rcTaken[(int) $m['course_id']] = $m;
$rcWord = ['draft' => 'draft', 'submitted' => 'with the checker', 'resubmit' => 'sent back',
           'rejected' => 'not accepted', 'verified' => 'verified'];
$rcTone = ['draft' => 'gray', 'submitted' => 'gold', 'resubmit' => 'gold', 'rejected' => 'red', 'verified' => 'green'];
$rcHasTarget = (int) $rcProg['target'] > 0;
?>
                <section class="avng-card" aria-labelledby="avng-co-h" id="ngv-courses">
                  <div class="avng-card-h">
                    <h2 id="avng-co-h">Courses</h2>
                    <span class="av-num"><?= (int) $rcProg['verified'] ?><?= $rcHasTarget ? ' / ' . (int) $rcProg['target'] : '' ?></span>
                  </div>
                  <div class="avng-books" id="courses">
<?php if ($rcProg['fixed']): ?>
                    <p class="avng-pace"><?= (int) $rcProg['target'] ?> courses this year, alongside the twenty-four books. The Foundation ones are asked of everybody; the rest you choose with your track in mind. Most are external — freeCodeCamp, Google, a tutor at the centre — and each one is checked before it counts.</p>
<?php elseif ($rcHasTarget): ?>
                    <p class="avng-pace">Your own target: <?= (int) $rcProg['target'] ?> course<?= (int) $rcProg['target'] === 1 ? '' : 's' ?>. You set it and you can change it — nothing here is counting against you.</p>
<?php else: ?>
                    <p class="avng-pace">No target and no clock. Take what is useful, log the time you put in, and have it checked. Set yourself a number if you would rather have one.</p>
<?php endif; ?>
                    <p class="avng-pace"><?= (int) $rcProg['verified'] ?> verified<?= $rcProg['waiting'] > 0 ? ' · ' . (int) $rcProg['waiting'] . ' with the checker' : '' ?><?= $rcProg['needs_work'] > 0 ? ' · ' . (int) $rcProg['needs_work'] . ' to put right' : '' ?><?= $rcProg['drafts'] > 0 ? ' · ' . (int) $rcProg['drafts'] . ' started' : '' ?>.</p>

<?php if (!$rcProg['fixed']): ?>
                    <form class="avng-ctarget" data-course-target>
                      <label for="rcTarget">My target for the year</label>
                      <input id="rcTarget" type="number" min="0" max="<?= (int) NgvCourses::TARGET_MAX ?>" inputmode="numeric" value="<?= (int) $rcProg['target'] ?>" aria-describedby="rcTargetHelp">
                      <button type="submit" class="avng-btn">Save</button>
                      <span id="rcTargetHelp" class="avng-ctarget-help">0 for none</span>
                    </form>
<?php endif; ?>

<?php foreach ($rcGroups as $gk => $g): if (!$g['courses']) continue; ?>
                    <?php /* Only the required group is marked. Writing
                             "choose what fits your track" on all four of the
                             others says the same thing four times, which the
                             line above the list already says once. */ ?>
                    <p class="avng-cgrp"><?= e($g['label']) ?><?php if ($rcProg['fixed'] && $g['required']): ?>
                      <span>everybody does these · <?= (int) $rcProg['foundation'] ?> of <?= (int) $rcProg['foundation_total'] ?></span>
<?php endif; ?></p>
                    <ul class="avng-shelf avng-courses">
<?php foreach ($g['courses'] as $c):
      $mine = $rcTaken[(int) $c['id']] ?? null;
      $st = $mine ? (string) $mine['status'] : '';
      $ed = !$mine || in_array($st, ['draft', 'resubmit'], true);
      $sits = $mine ? (array) $mine['sessions'] : [];
      $min = $mine ? (int) $mine['session_minutes'] : 0; ?>
                      <li>
<?php if ((string) $c['cover_url'] !== ''): ?>
                        <img class="avng-shelf-cov" src="<?= e((string) $c['cover_url']) ?>" alt="" loading="lazy" width="34" height="48">
<?php endif; ?>
                        <span class="avng-shelf-t">
                          <span><?= e((string) $c['title']) ?></span>
                          <span><?= (string) $c['provider'] !== '' ? e((string) $c['provider']) : 'at the centre' ?><?= (int) $c['modules'] > 0 ? ' · ' . (int) $c['modules'] . ' modules' : '' ?><?= $sits ? ' · ' . count($sits) . ' sitting' . (count($sits) === 1 ? '' : 's') . ', ' . $min . ' min' : '' ?></span>
                        </span>
<?php if ($st !== ''): ?>
                        <span class="avng-chip" data-tone="<?= e($rcTone[$st] ?? 'gray') ?>"><?= e($rcWord[$st] ?? $st) ?></span>
<?php else: ?>
                        <span class="avng-chip" data-tone="gray">not started</span>
<?php endif; ?>
                        <button type="button" class="avng-btn" data-course="<?= (int) $c['id'] ?>"><?= $ed ? ($mine ? 'Continue' : 'Take it') : 'View' ?></button>
                      </li>
<?php endforeach; ?>
                    </ul>
<?php endforeach; ?>
<?php if (!array_filter($rcGroups, static fn($g) => (bool) $g['courses'])): ?>
                    <p class="avng-pace">The course list is empty for now. The office adds the courses — check back soon.</p>
<?php endif; ?>
                  </div>
<?php require AV_ROOT . '/academy/ngv/_course-modal.php'; ?>
                </section>
