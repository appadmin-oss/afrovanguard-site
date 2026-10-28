<?php
/**
 * Overview: what you agreed, where it stands, what to do next time, and what
 * they said about themselves.
 *
 * One card for the record, one gold card for the thing you are here to do,
 * then two quiet panels. Five equal bordered boxes would say that the goals
 * and the values list matter the same amount, which is not true.
 */
$stats = $c['stats'];
$CK = [
    ['Energy',      ['Good', 'Up and down', 'Struggling'], ['green', 'warn', 'red']],
    ['Feels heard', ['Yes', 'Mostly', 'No'],               ['green', 'warn', 'red']],
    ['Worries',     ['None', 'A little', 'Yes'],           ['green', 'warn', 'red']],
];
?>
      <section class="avm-panel">
<?php require __DIR__ . '/_case_goals.php'; ?>

        <div class="avm-figs">
          <div><b class="av-num"><?= e(rtrim(rtrim(number_format($stats['hours'], 1), '0'), '.')) ?></b><small>hours logged</small></div>
          <div><b class="av-num"><?= (int) $stats['sessions'] ?> / <?= (int) $stats['held'] ?></b><small>sessions attended</small></div>
          <div><b class="av-num"><?= $stats['kept'] === null ? '—' : (int) $stats['kept'] . '%' ?></b><small>sessions kept</small></div>
          <div><b class="av-num"><?= $stats['days'] === null ? 'None yet' : (int) $stats['days'] ?></b><small><?= $stats['days'] === null ? 'no session yet' : 'days since last session' ?></small></div>
        </div>

        <div class="avm-tint">
          <span>Last time you talked<?= $c['last'] ? ' · ' . e($c['last']['when']) : '' ?></span>
          <p style="margin:4px 0 0;font-size:14.5px;line-height:1.55"><?= $c['last'] && $c['last']['outcome'] !== '' ? e($c['last']['outcome']) : 'See the sessions tab for the latest outcome.' ?></p>
        </div>
      </section>

      <section class="avm-act" data-avm-plan data-pairing="<?= (int) $c['pairing_id'] ?>" aria-labelledby="avm-plan-h">
        <div class="avm-act-txt">
          <h2 id="avm-plan-h">Plan the next session</h2>
          <p>Reads the goals, the last session, the values observations and this month’s check-in. You decide what to use.</p>
          <ol data-avm-plan-out hidden style="margin:12px 0 0;padding-left:18px;display:flex;flex-direction:column;gap:6px;font-size:14px"></ol>
        </div>
        <button type="button" class="avm-btn" data-avm-plan-go>Suggest a plan</button>
      </section>

      <div class="avm-two">
        <section class="avm-panel" aria-labelledby="avm-ck-h">
          <div class="avm-h2row"><h2 id="avm-ck-h">How they say they’re doing</h2></div>
<?php if (!$c['checkin']): ?>
          <p class="avm-empty" style="padding:0">They have not answered a check-in yet.</p>
<?php else: $ck = $c['checkin']; ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px">
<?php foreach ($CK as $i => $qa): $val = max(0, min(2, (int) $ck['q' . $i])); ?>
            <div class="avm-tint avm-tint--<?= e($qa[2][$val]) ?>">
              <span><?= e($qa[0]) ?></span><b><?= e($qa[1][$val]) ?></b>
            </div>
<?php endforeach; ?>
          </div>
          <p class="avm-msgnote">From their own check-in in the member portal · <?= e($ck['when']) ?></p>
<?php endif; ?>
        </section>

        <section class="avm-panel" aria-labelledby="avm-v-h">
          <div class="avm-h2row"><h2 id="avm-v-h">The seven values · last observed</h2><a class="avm-link" href="?v=values&amp;id=<?= (int) $c['pairing_id'] ?>">Observe ›</a></div>
          <div class="avm-rows">
<?php foreach ($c['values'] as $val): ?>
            <div><span><?= e($val['label']) ?></span><span class="avm-lv avm-lv--<?= (int) $val['level'] ?>"><?= e(MentorPortal::LEVELS[max(0, min(3, $val['level']))]) ?></span></div>
<?php endforeach; ?>
          </div>
        </section>
      </div>

<?php if ($c['stage'] >= 6): $done = $c['close_steps']; $gt = MentorPortal::goalTally($c['goals_list']); ?>
      <section class="avm-panel" data-avm-close data-pairing="<?= (int) $c['pairing_id'] ?>" aria-labelledby="avm-close-h">
        <div class="avm-h2row"><h2 id="avm-close-h">Plan the close</h2></div>
        <p style="margin:0;color:var(--av-muted);font-size:13.5px">Most young people here have been left by an adult before. An ending that is named and prepared for is one of the most useful things this gives them.</p>
<?php foreach ([
    'schedule' => 'Schedule the closing session, and tell them which one it is',
    'review'   => 'Look back at the goals together' . ($gt['live'] > 0 ? ' — ' . $gt['met'] . ' of ' . $gt['live'] . ' met' : ''),
    'contact'  => 'Agree what contact there will and will not be afterwards',
] as $key => $label): ?>
        <label style="display:flex;gap:10px;align-items:flex-start;font-size:14px">
          <input class="avm-ck" type="checkbox" name="<?= e($key) ?>"<?= in_array($key, $done, true) ? ' checked' : '' ?>>
          <span><?= e($label) ?></span>
        </label>
<?php endforeach; ?>
        <div><button type="button" class="avm-btn avm-btn--danger" data-avm-close-go disabled>Close the mentorship</button></div>
      </section>
<?php endif; ?>
