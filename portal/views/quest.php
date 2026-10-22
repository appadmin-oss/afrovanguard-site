<?php
/**
 * portal/views/quest.php — The Quest: where this member stands in the
 * Vanguard Quest, for every account, because everybody is a part of it.
 *
 * It opens on the JOURNEY LEVEL, not on a number. Most of the register sits
 * at zero points for a long time, and a page that opens on "0 points" tells
 * them the Quest is not about them (van-quest doctrine/04-journey.md).
 *
 * There is no rank on this page, on purpose. Which ladder a member climbs is
 * an open question with the owner (van-quest proposal, question 14), and the
 * letter on the member's ID card (lib/Levels.php, shown on Today) is a
 * different ladder on different criteria. Showing either here as "your Quest
 * rank" would answer the question by accident.
 *
 * Everything comes from QuestRecord / Quest / QuestPoints. Nothing here
 * restates a registry number.
 */
declare(strict_types=1);

$qId      = (int) $u['id'];
$qRec     = QuestRecord::of($qId);
$qLevels  = Quest::journey();
$qHere    = 0;
foreach ($qLevels as $i => $l) if ((string) $l['id'] === $qRec['journey']) $qHere = $i;
$qLevel   = $qLevels[$qHere] ?? ['name' => 'Visitor', 'meaning' => ''];
$qByValue = Quest::progressByValue($qId);
$qWritten = array_sum(array_column($qByValue, 'written'));
$qRecent  = QuestPoints::recent($qId, 6);
$qOpen    = $qRec['integrity'] ? [] : QuestRecord::findings($qId, true);
?>
      <section class="pview avp-view avp-quest" id="view-quest" data-view="quest" hidden aria-labelledby="avpQuestH">
        <div class="avp-view-head"><h1 class="avp-h1" id="avpQuestH">The Quest</h1></div>
        <p class="avp-note avp-quest-intro">Seven values, practised until they show. Points come only from evidence that somebody has changed, seen by one person and approved by another — never for turning up, knowing the words or talking about it.</p>

        <div class="avp-stack">
          <section class="avp-card" aria-labelledby="avpQJourneyH">
            <div class="avp-card-head"><h2 id="avpQJourneyH">Your journey level</h2><span class="avp-chip avp-chip--indigo"><?= e((string) $qLevel['name']) ?></span></div>
            <div class="avp-card-body">
              <ol class="avp-levels avp-quest-levels">
<?php foreach ($qLevels as $i => $l): $st = $i === $qHere ? ' is-here' : ($i < $qHere ? ' is-done' : ''); ?>
                <li class="avp-level<?= $st ?>"<?= $i === $qHere ? ' aria-current="step"' : '' ?>><span class="avp-level-b"><?= (int) $l['order'] ?></span><?= e((string) $l['name']) ?></li>
<?php endforeach; ?>
              </ol>
              <p class="avp-note">You are at <strong><?= e((string) $qLevel['name']) ?></strong> — <?= e(lcfirst((string) $qLevel['meaning'])) ?>.
<?php if ($qHere === 0): ?>
                Everybody starts here, from the first day they meet the culture.
<?php endif; ?>
                A person moves you along the journey when they see it in you; no count of points or sessions does.</p>
            </div>
          </section>

          <div class="avp-kpis" aria-label="Your Quest at a glance">
            <div class="avp-kpi">
              <div class="avp-kpi-top"><span class="avp-kpi-l">Quest points</span><span class="avp-chip avp-chip--sm avp-chip--gold">Evidence</span></div>
              <div class="avp-kpi-v av-num"><?= number_format((int) $qRec['points']) ?></div>
              <div class="avp-kpi-s">Separate from the points on your gate pass, which count attendance.</div>
            </div>
            <div class="avp-kpi">
              <div class="avp-kpi-top"><span class="avp-kpi-l">Challenges proved</span><span class="avp-chip avp-chip--sm avp-chip--green">Once each</span></div>
              <div class="avp-kpi-v av-num"><?= (int) $qRec['challenges']['done'] ?> / <?= (int) $qRec['challenges']['total'] ?></div>
              <div class="avp-kpi-s">Ten under each value.</div>
            </div>
            <div class="avp-kpi">
              <div class="avp-kpi-top"><span class="avp-kpi-l">People you mentor</span><span class="avp-chip avp-chip--sm avp-chip--indigo">Active</span></div>
              <div class="avp-kpi-v av-num"><?= (int) $qRec['mentees_active'] ?></div>
              <div class="avp-kpi-s"><?= (int) $qRec['mentees_paired'] === (int) $qRec['mentees_active']
                ? 'Only mentees you actually meet count.'
                : (int) $qRec['mentees_paired'] . ' paired; only the ones you actually meet count.' ?></div>
            </div>
          </div>

<?php if ($qOpen): ?>
          <section class="avp-card avp-quest-integrity" aria-labelledby="avpQIntH">
            <div class="avp-card-head"><h2 id="avpQIntH">Open with the compliance committee</h2><span class="avp-chip avp-chip--red"><?= count($qOpen) ?> open</span></div>
            <div class="avp-card-body">
              <ul class="avp-quest-list">
<?php foreach ($qOpen as $f): ?>
                <li><strong><?= e(QuestRecord::FINDING_KINDS[(string) $f['kind']] ?? 'A finding') ?></strong> <span class="avp-quest-when">recorded <?= e(substr((string) $f['raised_at'], 0, 10)) ?></span></li>
<?php endforeach; ?>
              </ul>
              <p class="avp-note">While this stands, it holds your progress where it is. Points do not outweigh it; only resolving it with the committee does, and it stays on your record afterwards as resolved.</p>
            </div>
          </section>
<?php endif; ?>

          <section class="avp-card" aria-labelledby="avpQValuesH">
            <div class="avp-card-head"><h2 id="avpQValuesH">The seven values</h2></div>
            <div class="avp-card-body">
              <table class="avp-quest-values">
                <caption class="av-sr">Points and challenges proved under each value</caption>
                <thead><tr><th scope="col">Value</th><th scope="col">Points</th><th scope="col">Challenges proved</th></tr></thead>
                <tbody>
<?php foreach ($qByValue as $vid => $v): ?>
                  <tr>
                    <th scope="row"><?= e($v['name']) ?></th>
                    <td class="av-num"><?= number_format((int) ($qRec['by_value'][$vid] ?? 0)) ?></td>
                    <td class="av-num"><?= (int) $v['done'] ?> / <?= (int) $v['total'] ?></td>
                  </tr>
<?php endforeach; ?>
                </tbody>
              </table>
<?php if ($qWritten === 0): ?>
              <p class="avp-note">The wording of the seventy challenges is still being written. Until it is published, none can be signed off — nobody is scored against a challenge nobody has written.</p>
<?php endif; ?>
            </div>
          </section>

          <section class="avp-card" aria-labelledby="avpQRecentH">
            <div class="avp-card-head"><h2 id="avpQRecentH">Recently recorded</h2></div>
            <div class="avp-card-body">
<?php if (!$qRecent): ?>
              <p class="pc-empty">Nothing yet. When a mentor proposes something you did and the committee approves it, it appears here.</p>
<?php else: ?>
              <ul class="avp-quest-list">
<?php foreach ($qRecent as $r): ?>
                <li><span class="av-num avp-quest-pts">+<?= (int) $r['points'] ?></span> <?= e((string) ($r['note'] !== '' ? $r['note'] : $r['reason'])) ?><?php if ((string) $r['day'] !== ''): ?> <span class="avp-quest-when"><?= e((string) $r['day']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
              </ul>
<?php endif; ?>
            </div>
          </section>
        </div>
      </section>
