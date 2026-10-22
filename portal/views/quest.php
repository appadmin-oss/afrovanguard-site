<?php
/**
 * portal/views/quest.php — The Quest: where this member stands in the
 * Vanguard Quest, for every account, because everybody is a part of it.
 *
 * It opens on the JOURNEY LEVEL, not on a number. Most of the register sits
 * at zero points for a long time, and a page that opens on "0 points" tells
 * them the Quest is not about them (van-quest doctrine/04-journey.md).
 *
 * The level sits BESIDE the journey, second. The owner's Pyramid Manual
 * (22 October 2026) settled which ladder stands: the eight levels O–G, on
 * Quest points and direct mentees actually met, held through Levels — the same
 * letter as the ID card. The card shows the level held (a decision), what the
 * next one asks, and this month against the level's ceiling.
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
$qHeld    = Levels::of($qId);
$qRank    = Quest::rank($qHeld) ?? [];
$qNext    = Levels::nextOf($qHeld);
$qNextR   = $qNext !== null ? (Quest::rank($qNext) ?? null) : null;
$qMonth   = QuestPoints::earnedInMonth($qId, gmdate('Y-m'));
$qCeil    = AvRules::bool('quest.monthly_ceiling') ? ($qRank['monthly_ceiling'] ?? null) : null;
/* The sections that feed the Quest, as the shell decided this account sees
   them (lib/PortalNav.php). Drawn outside the shell, nothing is linked. */
$qCtx     = $portalCtx ?? null;
$qLinks   = [];
if ($qCtx !== null) {
    $qLinks[] = ['mentorship', 'Mentorship', 'Direct mentees you actually meet count toward every level above the first.'];
    if (PortalNav::visible('attendance', $qCtx)) $qLinks[] = ['attendance', 'Attendance & pass', 'Turning up, and on time, earns points.'];
    if (PortalNav::visible('ngv-fines', $qCtx)) $qLinks[] = ['ngv-fines', 'Fines', QuestMoney::ready()
        ? 'Each month, the points you earn are set against the fines you owe, at your income bracket\'s rate.'
        : 'A fine is proposed by a mentor and decided by the committee, like an award.'];
}
$qMoney   = $qCtx !== null && QuestMoney::ready() ? (QuestMoney::forMember($qId)[0] ?? null) : null;
?>
      <section class="pview avp-view avp-quest" id="view-quest" data-view="quest" hidden aria-labelledby="avpQuestH">
        <div class="avp-view-head"><h1 class="avp-h1" id="avpQuestH">The Quest</h1></div>
        <p class="avp-note avp-quest-intro">Seven values, practised until they show. Points come from what somebody saw you do — turning up on time included — put forward by one person and approved by another. Points and the people you actually mentor carry you up the levels; a person confirms every step.</p>

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

          <section class="avp-card" aria-labelledby="avpQLevelH">
            <div class="avp-card-head"><h2 id="avpQLevelH">Your level on the pyramid</h2><span class="avp-chip avp-chip--sm avp-quest-colour" data-colour="<?= e((string) ($qRank['colour'] ?? 'white')) ?>"><?= e($qHeld) ?> · <?= e(Levels::labelOf($qHeld)) ?></span></div>
            <div class="avp-card-body">
<?php if ($qNextR): $needP = (int) $qNextR['points']['min']; $needM = (int) $qNextR['mentees']['min']; ?>
              <div class="avp-progress">
                <div class="avp-bar" role="progressbar" aria-label="Quest points toward <?= e(Levels::labelOf((string) $qNext)) ?>" aria-valuemin="0" aria-valuemax="<?= $needP ?>" aria-valuenow="<?= min($needP, max(0, (int) $qRec['points'])) ?>"><span style="width:<?= $needP > 0 ? min(100, (int) round(100 * max(0, (int) $qRec['points']) / $needP)) : 100 ?>%"></span></div>
                <span class="avp-progress-n av-num"><?= number_format(max(0, (int) $qRec['points'])) ?> / <?= number_format($needP) ?> points</span>
              </div>
              <p class="avp-note"><strong><?= e(Levels::labelOf((string) $qNext)) ?></strong> starts at <?= number_format($needP) ?> points<?= !empty($qNextR['binding_mentees']) ? ' and ' . $needM . ' direct mentee' . ($needM === 1 ? '' : 's') . ' you actually meet — you have ' . (int) $qRec['mentees_active'] . '. Points alone do not promote' : '' ?>. The committee confirms every promotion.</p>
<?php else: ?>
              <p class="avp-note">The top of the pyramid.</p>
<?php endif; ?>
<?php if ($qCeil !== null): ?>
              <p class="avp-quest-ceiling">This month <span class="av-num"><?= number_format($qMonth) ?> / <?= number_format((int) $qCeil) ?></span> — the most your level can earn in a month.</p>
<?php endif; ?>
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
            <<?= $qLinks ? 'a href="#mentorship" data-goto="mentorship"' : 'div' ?> class="avp-kpi<?= $qLinks ? ' avp-kpi--link' : '' ?>">
              <div class="avp-kpi-top"><span class="avp-kpi-l">People you mentor</span><span class="avp-chip avp-chip--sm avp-chip--indigo">Active</span></div>
              <div class="avp-kpi-v av-num"><?= (int) $qRec['mentees_active'] ?></div>
              <div class="avp-kpi-s"><?= (int) $qRec['mentees_paired'] === (int) $qRec['mentees_active']
                ? 'Only mentees you actually meet count.'
                : (int) $qRec['mentees_paired'] . ' paired; only the ones you actually meet count.' ?></div>
            </<?= $qLinks ? 'a' : 'div' ?>>
          </div>

<?php if ($qLinks): ?>
          <section class="avp-card" aria-labelledby="avpQFeedsH">
            <div class="avp-card-head"><h2 id="avpQFeedsH">What feeds your Quest</h2></div>
            <div class="avp-card-body">
              <ul class="avp-quest-feeds">
<?php foreach ($qLinks as [$lid, $ltitle, $lwhy]): ?>
                <li><a href="#<?= e($lid) ?>" data-goto="<?= e($lid) ?>"><b><?= e($ltitle) ?> →</b><span><?= e($lwhy) ?></span></a></li>
<?php endforeach; ?>
              </ul>
<?php if ($qMoney): ?>
              <p class="avp-note">Last set against your fines: <?= e((string) $qMoney['month']) ?>.</p>
<?php endif; ?>
            </div>
          </section>
<?php endif; ?>

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
                <li><span class="av-num avp-quest-pts<?= (int) $r['points'] < 0 ? ' is-minus' : '' ?>"><?= (int) $r['points'] < 0 ? '−' . abs((int) $r['points']) : '+' . (int) $r['points'] ?></span> <?= e((string) ($r['note'] !== '' ? $r['note'] : $r['reason'])) ?><?php if ((string) $r['day'] !== ''): ?> <span class="avp-quest-when"><?= e((string) $r['day']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
              </ul>
<?php endif; ?>
            </div>
          </section>
        </div>
      </section>
