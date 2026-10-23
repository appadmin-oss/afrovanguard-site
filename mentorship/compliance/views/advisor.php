<?php
/**
 * AI advisor: the figures first — how each catalogue line is actually used —
 * then the model, asked to optimise, strategise, update or rethink. Every
 * proposal is a button a superadmin presses or does not; nothing applies
 * itself. Superadmins only.
 */
declare(strict_types=1);
$on = QuestAdvisor::enabled();
$usage = QuestAdvisor::usage();
$signals = QuestAdvisor::signals($usage);
$recent = QuestAdvisor::recent(6);
$modelOn = AvRules::bool('ai.enabled');
$t = $usage['totals'];
?>
      <section class="pview avp-view qd-view" id="view-advisor" data-view="advisor" hidden aria-labelledby="qdAdvH">
        <div class="avp-view-head qd-head">
          <div><h1 class="avp-h1" id="qdAdvH"><?= e($title) ?></h1>
            <p class="qd-sub">It reads how the catalogue is really used and proposes changes. It never applies one: each proposal is a button you press, and what you apply lands in the catalogue's history with the advice cited.</p></div>
        </div>
<?php if (!$on): ?>
        <p class="qd-banner" data-tone="warn">The advisor is switched off in Rules.</p>
<?php else: ?>
        <div class="avp-kpis" aria-label="The last <?= (int) $usage['days'] ?> days">
          <div class="avp-kpi"><div class="avp-kpi-top"><span class="avp-kpi-l">Put forward</span></div><div class="avp-kpi-v av-num"><?= (int) $t['proposed'] ?></div><div class="avp-kpi-s"><?= (int) $t['approved'] ?> approved · <?= (int) $t['rejected'] ?> rejected</div></div>
          <div class="avp-kpi"><div class="avp-kpi-top"><span class="avp-kpi-l">Points</span></div><div class="avp-kpi-v av-num">+<?= number_format((int) $t['points_awarded']) ?></div><div class="avp-kpi-s"><?= $t['points_deducted'] ? '−' . number_format((int) $t['points_deducted']) . ' deducted' : 'nothing deducted' ?></div></div>
          <div class="avp-kpi"><div class="avp-kpi-top"><span class="avp-kpi-l">Fined</span></div><div class="avp-kpi-v av-num"><?= QuestDesk::naira((int) $t['fined']) ?></div><div class="avp-kpi-s">from the catalogue</div></div>
        </div>

        <section class="avp-card" aria-labelledby="qdAskH">
          <div class="avp-card-head"><h2 id="qdAskH">Ask</h2><span class="avp-head-sub"><?= $modelOn ? 'The model reads the catalogue and the figures' : 'No model is switched on — the answer will be the figures alone' ?></span></div>
          <div class="avp-card-body">
            <form class="qd-modes" method="post" action="/mentorship/conduct.php" data-qd-busy="The advisor is reading the catalogue…">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="advisor_ask">
<?php foreach (QuestAdvisor::MODES as $k => $l): [$head, $rest] = array_pad(explode(' — ', $l, 2), 2, ''); ?>
              <button class="qd-mode" type="submit" name="mode" value="<?= e($k) ?>"><b><?= e($head) ?></b><span><?= e($rest) ?></span></button>
<?php endforeach; ?>
            </form>
          </div>
        </section>

        <section class="avp-card qd-gap" aria-labelledby="qdSigH">
          <div class="avp-card-head"><h2 id="qdSigH">What the figures flag</h2></div>
          <div class="avp-card-body">
<?php if (!$signals): ?>
            <p class="qd-empty"><?= $t['proposed'] < 10 ? 'Too few proposals yet (' . (int) $t['proposed'] . ' in ' . (int) $usage['days'] . ' days) to read anything into.' : 'Nothing stands out.' ?></p>
<?php else: ?>
            <ul class="qd-list qd-list--plain">
<?php foreach ($signals as $s): ?>              <li><span class="qd-dot" data-level="<?= e($s['level']) ?>" aria-hidden="true"></span><span class="qd-list-main"><?= e($s['text']) ?></span></li>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
          </div>
        </section>

<?php foreach ($recent as $a): ?>
        <article class="avp-card qd-gap qd-advice" aria-labelledby="qdAdv<?= (int) $a['id'] ?>">
          <div class="avp-card-head"><h2 id="qdAdv<?= (int) $a['id'] ?>"><?= e(explode(' — ', QuestAdvisor::MODES[$a['mode']] ?? $a['mode'])[0]) ?></h2>
            <span class="avp-head-sub"><?= e($a['source'] === 'rules-only' ? 'The figures alone' : 'Model: ' . $a['source']) ?> · <?= e(substr((string) $a['created_at'], 0, 16)) ?></span></div>
          <div class="avp-card-body">
            <p class="avp-note"><?= e((string) $a['summary']) ?></p>
<?php if ($a['proposals']): ?>
            <h3 class="qd-h3">Proposals</h3>
            <ul class="qd-list">
<?php foreach ($a['proposals'] as $i => $p): $line = QuestRules::item((string) $p['kind'], (string) $p['id']); $done = in_array($i, $a['applied'], true); ?>
              <li><span class="qd-list-main"><b><?= e((string) ($line['label'] ?? $p['id'])) ?></b> —
                <?= $p['enabled'] !== null ? ($p['enabled'] ? 'switch on' : 'switch off') : '' ?><?= $p['enabled'] !== null && $p['value'] !== null ? ', ' : '' ?><?= $p['value'] !== null ? 'price ' . e((string) ($line['value'] ?? '?')) . ' → ' . (int) $p['value'] : '' ?>
                <span class="qd-cell-sub"><?= e((string) $p['why']) ?></span></span>
<?php if ($done): ?>
                <span class="avp-chip avp-chip--sm avp-chip--green">Applied</span>
<?php else: ?>
                <form method="post" action="/mentorship/conduct.php" data-qd-ajax data-qd-done-chip="Applied">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="advisor_apply">
                  <input type="hidden" name="advice_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="index" value="<?= (int) $i ?>">
                  <button class="avp-btn avp-btn--sq" type="submit">Apply</button>
                </form>
<?php endif; ?>
              </li>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
<?php if ($a['ideas']): ?>
            <h3 class="qd-h3">Deeds the catalogue does not price</h3>
            <ul class="qd-list qd-list--plain"><?php foreach ($a['ideas'] as $idea): ?><li><span class="qd-list-main"><b><?= e((string) $idea['label']) ?></b> · <?= ($idea['kind'] === 'deduction' ? '−' : '+') . (int) $idea['points'] ?> <span class="qd-cell-sub"><?= e((string) $idea['why']) ?></span></span></li><?php endforeach; ?></ul>
            <p class="qd-hint">A new line is a change to the registry in van-quest — the manual's word — so it is added there, not here.</p>
<?php endif; ?>
<?php if ($a['questions']): ?>
            <h3 class="qd-h3">For leadership to decide</h3>
            <ul class="qd-list qd-list--plain"><?php foreach ($a['questions'] as $q): ?><li><span class="qd-list-main"><?= e((string) $q) ?></span></li><?php endforeach; ?></ul>
<?php endif; ?>
          </div>
        </article>
<?php endforeach; ?>
<?php endif; ?>
      </section>
