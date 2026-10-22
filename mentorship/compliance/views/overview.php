<?php
/** Overview: where the Quest stands this month, and what is waiting on somebody. */
declare(strict_types=1);
$waitingRows = Conduct::queue('proposed', 6);
$sig = $edit && QuestAdvisor::enabled() ? array_slice(QuestAdvisor::signals(), 0, 5) : [];
$drift = $ov['drift'];
$driftN = array_sum(array_map(static fn($d) => $d['off'] + $d['repriced'], $drift));
?>
      <section class="pview avp-view qd-view" id="view-overview" data-view="overview" hidden aria-labelledby="qdOverviewH">
        <div class="avp-view-head qd-head">
          <div><h1 class="avp-h1" id="qdOverviewH"><?= e($title) ?></h1>
            <p class="qd-sub">The Pyramid Manual, run as a desk. Nothing a mentor puts forward is real until somebody here decides it, and nothing the manual calls for — a demotion, a suspension — happens until somebody here accepts it.</p></div>
        </div>

        <div class="avp-kpis" aria-label="At a glance">
          <a class="avp-kpi qd-kpi" href="?s=cases#cases" data-goto="cases">
            <div class="avp-kpi-top"><span class="avp-kpi-l">Waiting for a decision</span><span class="avp-chip avp-chip--sm avp-chip--<?= $ov['waiting'] ? 'gold' : 'green' ?>"><?= $ov['waiting'] ? 'Needs you' : 'Clear' ?></span></div>
            <div class="avp-kpi-v av-num"><?= (int) $ov['waiting'] ?></div>
            <div class="avp-kpi-s"><?= (int) $ov['decided_30'] ?> decided in the last 30 days</div>
          </a>
          <a class="avp-kpi qd-kpi" href="?s=suggestions#suggestions" data-goto="suggestions">
            <div class="avp-kpi-top"><span class="avp-kpi-l">Suggestions open</span><span class="avp-chip avp-chip--sm avp-chip--indigo">Suggested only</span></div>
            <div class="avp-kpi-v av-num"><?= (int) $ov['suggestions'] ?></div>
            <div class="avp-kpi-s">Demotions, suspensions and fines the manual calls for</div>
          </a>
          <div class="avp-kpi">
            <div class="avp-kpi-top"><span class="avp-kpi-l">Points this month</span><span class="avp-chip avp-chip--sm avp-chip--green"><?= e($ov['month']) ?></span></div>
            <div class="avp-kpi-v av-num">+<?= number_format((int) $ov['awarded']) ?></div>
            <div class="avp-kpi-s"><?= $ov['deducted'] ? '−' . number_format((int) $ov['deducted']) . ' deducted · ' : '' ?><?= (int) $ov['members_pts'] ?> member(s) have Quest points</div>
          </div>
          <a class="avp-kpi qd-kpi" href="?s=money#money" data-goto="money">
            <div class="avp-kpi-top"><span class="avp-kpi-l">Points against fines</span><span class="avp-chip avp-chip--sm avp-chip--<?= $ov['money_ready'] ? 'green' : 'gold' ?>"><?= $ov['money_ready'] ? 'On' : ($ov['money_on'] ? 'Needs brackets' : 'Off') ?></span></div>
            <div class="avp-kpi-v av-num"><?= QuestDesk::naira((int) $ov['pool']) ?></div>
            <div class="avp-kpi-s">in the Reward Pool</div>
          </a>
        </div>

        <div class="avp-cols">
          <div class="avp-col avp-col--main">
            <section class="avp-card" aria-labelledby="qdOvWaitH">
              <div class="avp-card-head"><h2 id="qdOvWaitH">Oldest waiting</h2><a class="avp-head-link" href="?s=cases#cases" data-goto="cases">All cases →</a></div>
              <div class="avp-card-body">
<?php if (!$waitingRows): ?>
                <p class="qd-empty">Nothing is waiting. Mentors' proposals arrive here, and the committee is notified.</p>
<?php else: ?>
                <ul class="qd-list">
<?php foreach ($waitingRows as $c): ?>
                  <li><span class="qd-kindtag" data-kind="<?= e((string) $c['kind']) ?>"><?= e(ucfirst((string) $c['kind'])) ?></span>
                    <span class="qd-list-main"><b><?= e((string) $c['mentee_name']) ?></b> · <?= e(Conduct::caseLabel($c)) ?></span>
                    <span class="qd-list-meta av-num"><?= $c['kind'] === 'fine' ? QuestDesk::naira((int) $c['amount']) : (($c['kind'] === 'deduction' ? '−' : '+') . (int) $c['points']) ?></span></li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
              </div>
            </section>
          </div>
          <div class="avp-col avp-col--side">
            <section class="avp-card" aria-labelledby="qdOvCatH">
              <div class="avp-card-head"><h2 id="qdOvCatH">The catalogue</h2><a class="avp-head-link" href="?s=catalogue#catalogue" data-goto="catalogue">Open →</a></div>
              <div class="avp-card-body">
                <p class="avp-note"><?= $driftN === 0 ? 'Exactly the Pyramid Manual\'s prices — nothing switched off, nothing re-priced.' : $driftN . ' line(s) depart from the manual: ' . (int) ($drift['award']['repriced'] + $drift['deduction']['repriced'] + $drift['fine']['repriced']) . ' re-priced, ' . (int) ($drift['award']['off'] + $drift['deduction']['off'] + $drift['fine']['off']) . ' switched off.' ?></p>
              </div>
            </section>
<?php if ($sig): ?>
            <section class="avp-card" aria-labelledby="qdOvSigH">
              <div class="avp-card-head"><h2 id="qdOvSigH">The figures flag</h2><a class="avp-head-link" href="?s=advisor#advisor" data-goto="advisor">Advisor →</a></div>
              <div class="avp-card-body">
                <ul class="qd-list qd-list--plain">
<?php foreach ($sig as $s): ?>                  <li><span class="qd-dot" data-level="<?= e($s['level']) ?>" aria-hidden="true"></span><span class="qd-list-main"><?= e($s['text']) ?></span></li>
<?php endforeach; ?>
                </ul>
              </div>
            </section>
<?php endif; ?>
          </div>
        </div>
      </section>
