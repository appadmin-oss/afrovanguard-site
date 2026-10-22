<?php
/**
 * Money: the Pyramid Manual's Section 3. Income brackets (each the naira
 * value of a point and a scale on the manual's fines), last month previewed
 * member by member, and the button that runs it — a waiver on each member's
 * NGV fines line, once. Inert until switched on in Rules and a bracket gives
 * a point a value.
 */
declare(strict_types=1);
$brackets = QuestMoney::brackets();
$last = gmdate('Y-m', strtotime(gmdate('Y-m-01') . ' -1 month'));
$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) && (string) $_GET['month'] < gmdate('Y-m') ? (string) $_GET['month'] : $last;
$pv = QuestMoney::preview($month);
$on = AvRules::bool('quest.money_enabled');
$ready = QuestMoney::ready();
?>
      <section class="pview avp-view qd-view" id="view-money" data-view="money" hidden aria-labelledby="qdMoneyH">
        <div class="avp-view-head qd-head">
          <div><h1 class="avp-h1" id="qdMoneyH"><?= e($title) ?></h1>
            <p class="qd-sub">At the end of every month each member's points are monetised by their income bracket and offset against that month's fines; what is left is paid within <?= (int) AvRules::int('quest.money_settle_days') ?> days. The manual names the brackets and gives none, so they are yours to set.</p></div>
        </div>

        <p class="qd-banner" data-tone="<?= $ready ? 'ok' : 'warn' ?>">
          <?= $ready ? 'On. The month can be run.' : ($on ? 'Switched on, but no bracket gives a point a value yet — set one below.' : 'Switched off. Brackets can be set and the month previewed; nothing moves money until it is switched on in Rules.') ?>
        </p>

        <section class="avp-card" aria-labelledby="qdBrH">
          <div class="avp-card-head"><h2 id="qdBrH">Income brackets</h2><span class="avp-head-sub">A fine scale of 100% is the manual's price</span></div>
          <div class="qd-brackets" role="group" aria-label="Income brackets">
            <div class="qd-rowform qd-rowform--head" aria-hidden="true"><span>Bracket</span><span>Income from (₦)</span><span>to (₦)</span><span>1 point = (₦)</span><span>Fines at (%)</span><span class="qd-c-num">Members</span><span></span></div>
<?php foreach ($brackets as $b): ?>
                  <form class="qd-rowform" method="post" action="/mentorship/conduct.php">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="bracket_save"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                    <label><span class="av-sr">Name</span><input type="text" name="label" value="<?= e((string) $b['label']) ?>" required maxlength="80"></label>
                    <label><span class="av-sr">From</span><input type="text" inputmode="numeric" name="min_income" value="<?= (int) $b['min_income'] ?>"></label>
                    <label><span class="av-sr">To</span><input type="text" inputmode="numeric" name="max_income" value="<?= $b['max_income'] === null ? '' : (int) $b['max_income'] ?>" placeholder="no top"></label>
                    <label><span class="av-sr">Value of a point</span><input type="text" inputmode="numeric" name="point_value" value="<?= (int) $b['point_value'] ?>"></label>
                    <label><span class="av-sr">Fine scale</span><input type="number" name="fine_pct" min="0" max="1000" value="<?= (int) $b['fine_pct'] ?>"></label>
                    <span class="qd-c-num av-num"><?= (int) $b['members'] ?></span>
                    <span class="qd-row-actions"><button class="avp-btn avp-btn--sq" type="submit">Save</button>
                      <button class="avp-btn avp-btn--sq qd-btn-quiet" type="submit" name="op" value="bracket_delete"<?= (int) $b['members'] > 0 ? ' disabled title="Move its members first"' : '' ?>>Delete</button></span>
                  </form>
<?php endforeach; ?>
                  <form class="qd-rowform qd-rowform--new" method="post" action="/mentorship/conduct.php">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="bracket_save">
                    <label><span class="av-sr">Name</span><input type="text" name="label" required maxlength="80" placeholder="New bracket, e.g. Under ₦100,000"></label>
                    <label><span class="av-sr">From</span><input type="text" inputmode="numeric" name="min_income" placeholder="0"></label>
                    <label><span class="av-sr">To</span><input type="text" inputmode="numeric" name="max_income" placeholder="no top"></label>
                    <label><span class="av-sr">Value of a point</span><input type="text" inputmode="numeric" name="point_value" placeholder="₦ per point"></label>
                    <label><span class="av-sr">Fine scale</span><input type="number" name="fine_pct" min="0" max="1000" value="100"></label>
                    <span></span>
                    <span class="qd-row-actions"><button class="avp-btn avp-btn--ink avp-btn--sq" type="submit">Add</button></span>
                  </form>
          </div>
        </section>

        <section class="avp-card qd-gap" aria-labelledby="qdMonthH">
          <div class="avp-card-head">
            <h2 id="qdMonthH"><?= e(date('F Y', strtotime($month . '-01'))) ?></h2>
            <form class="qd-inline" method="get" action="#money">
              <input type="hidden" name="s" value="money">
              <label><span class="av-sr">Month</span><input type="month" name="month" value="<?= e($month) ?>" max="<?= e($last) ?>"></label>
              <button class="avp-btn avp-btn--sq" type="submit">Show</button>
            </form>
          </div>
          <div class="avp-card-body">
            <dl class="qd-stats">
              <div><dt>Points worth</dt><dd class="av-num"><?= QuestDesk::naira($pv['totals']['credit']) ?></dd></div>
              <div><dt>Fines that month</dt><dd class="av-num"><?= QuestDesk::naira($pv['totals']['fines']) ?></dd></div>
              <div><dt>Offset</dt><dd class="av-num"><?= QuestDesk::naira($pv['totals']['offset']) ?></dd></div>
              <div><dt>Left to pay</dt><dd class="av-num"><?= QuestDesk::naira($pv['totals']['balance']) ?></dd></div>
              <div><dt>Reward Pool (<?= (int) $pv['pool']['pct'] ?>%)</dt><dd class="av-num"><?= QuestDesk::naira((int) $pv['pool']['amount']) ?></dd></div>
            </dl>
<?php if ($pv['unplaced']): ?>
            <p class="qd-hint"><?= count($pv['unplaced']) ?> member(s) earned points or were fined that month with no bracket, and are left out: <?= e(implode(', ', array_slice(array_column($pv['unplaced'], 'name'), 0, 8))) ?><?= count($pv['unplaced']) > 8 ? '…' : '' ?>. Place them in Members.</p>
<?php endif; ?>
          </div>
<?php if ($pv['rows']): ?>
          <div class="qd-table-wrap">
            <table class="qd-table">
              <caption class="av-sr">The month, member by member</caption>
              <thead><tr><th scope="col">Member</th><th scope="col">Bracket</th><th scope="col" class="qd-c-num">Points</th><th scope="col" class="qd-c-num">Worth</th><th scope="col" class="qd-c-num">Fines owed</th><th scope="col" class="qd-c-num">Offset</th><th scope="col" class="qd-c-num">Left</th></tr></thead>
              <tbody>
<?php foreach ($pv['rows'] as $r): ?>
                <tr><td><b><?= e($r['name']) ?></b><?php if ($r['suspended']): ?> <span class="avp-chip avp-chip--sm avp-chip--gold">suspended — no incentive</span><?php endif; ?><?php if ($r['applied']): ?> <span class="avp-chip avp-chip--sm avp-chip--green">run</span><?php endif; ?></td>
                  <td><?= e($r['bracket']) ?></td><td class="qd-c-num av-num"><?= number_format($r['points']) ?></td>
                  <td class="qd-c-num av-num"><?= QuestDesk::naira($r['credit']) ?></td><td class="qd-c-num av-num"><?= QuestDesk::naira($r['owing']) ?></td>
                  <td class="qd-c-num av-num"><?= QuestDesk::naira($r['offset']) ?></td><td class="qd-c-num av-num"><b><?= QuestDesk::naira($r['balance']) ?></b></td></tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
<?php else: ?>
          <div class="avp-card-body"><p class="qd-empty">Nobody in a bracket earned points or was fined in <?= e($month) ?>.</p></div>
<?php endif; ?>
          <div class="qd-card-foot">
            <form method="post" action="/mentorship/conduct.php" data-qd-confirm="Run <?= e($month) ?> for <?= count(array_filter($pv['rows'], static fn($r) => !$r['applied'])) ?> member(s)? Offsets are posted to their NGV accounts as waivers and they are told.">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="money_apply"><input type="hidden" name="month" value="<?= e($month) ?>">
              <button class="avp-btn avp-btn--ink" type="submit"<?= $ready && $pv['rows'] ? '' : ' disabled' ?>>Run <?= e($month) ?></button>
            </form>
            <p class="qd-hint"><?= $ready ? 'Once per member per month. An unpaid balance past its day raises a suspension and a fine as suggestions — never imposed.' : 'Switch the reconciliation on in Rules, and give at least one bracket a value for a point.' ?></p>
          </div>
        </section>

        <p class="qd-hint">Earnings by level — the manual's share of CACENTRE's service charges, <?= e(implode(' · ', array_map(static fn($r) => $r['id'] . ' ' . $r['earning']['base_pct'] . '–' . $r['earning']['cap_pct'] . '%', Quest::ranks()))) ?> — are recorded, not yet computed: that waits on how the service charges are counted.</p>
      </section>
