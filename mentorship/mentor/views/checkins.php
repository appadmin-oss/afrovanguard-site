<?php
/** Check-ins. Week 1, 2 and 4 of a pairing, then monthly. Due ones first. */
$all  = !empty($_GET['all']);
$rows = $portal->checkins($all);
$tot  = $portal->checkinsTotal();
$coord = $portal->coordinatorFirstName();
$Q = [
    ['How has your energy been?', ['Good', 'Up and down', 'Struggling']],
    ['Do you feel heard in your sessions?', ['Yes', 'Mostly', 'No']],
    ['Is anything worrying you?', ['No', 'A little', 'Yes']],
];
?>
<?php if (!$rows): ?>
      <p class="avm-empty">Nothing due. Check-ins appear in week 1, 2 and 4 of a pairing, then once a month.</p>
<?php else: ?>
      <div style="display:flex;flex-direction:column;gap:8px">
<?php foreach ($rows as $r): ?>
        <details class="avm-card" id="c<?= (int) $r['id'] ?>"<?= !$r['sent'] && $r['overdue'] ? ' open' : '' ?>>
          <summary class="avm-card-h" style="cursor:pointer;list-style:none">
            <span style="display:flex;align-items:center;gap:10px">
              <span class="avm-av" aria-hidden="true"><?= e($r['initials']) ?></span>
              <span><b style="display:block;font-size:14px"><?= e($r['name']) ?></b>
                <small style="color:var(--av-muted)"><?= $r['week'] ? 'Week ' . (int) $r['week'] : 'Requested' ?> · due <?= e($r['due']) ?></small></span>
            </span>
<?php if ($r['sent']): ?>            <span class="avm-pill avm-st--attended">Sent</span>
<?php else: ?>            <span class="avm-link<?= $r['overdue'] ? ' av-late' : '' ?>"><?= $r['overdue'] ? 'Due now' : 'Open' ?></span>
<?php endif; ?>
          </summary>
<?php if (!$r['sent']): ?>
          <form data-avm-checkin data-pairing="<?= (int) $r['pairing_id'] ?>" style="padding:14px;display:flex;flex-direction:column;gap:14px">
            <input type="hidden" name="checkin_id" value="<?= (int) $r['id'] ?>">
<?php foreach ($Q as $i => $qa): ?>
            <fieldset class="avm-val" style="border:0;padding:0">
              <legend style="font-size:14px;font-weight:600;padding:0 0 8px"><?= e($qa[0]) ?></legend>
              <div class="avm-seg" style="grid-template-columns:repeat(3,1fr)">
<?php foreach ($qa[1] as $vi => $label): ?>
                <label><input type="radio" name="q<?= $i ?>" value="<?= $vi ?>" required><span><?= e($label) ?></span></label>
<?php endforeach; ?>
              </div>
            </fieldset>
<?php endforeach; ?>
            <p class="avm-danger" data-avm-ckflag hidden><?= e(ucfirst($coord)) ?> will be asked to call you this week.</p>
            <div><button class="avm-btn avm-btn--ink" type="submit">Send to <?= e($r['name']) ?></button></div>
          </form>
<?php endif; ?>
        </details>
<?php endforeach; ?>
      </div>
<?php if (!$all && $tot > count($rows)): ?>
      <div class="avm-foot"><span>Showing <?= count($rows) ?> of <?= $tot ?></span><a class="avm-btn" href="?v=checkins&amp;all=1">Show all</a></div>
<?php endif; ?>
<?php endif; ?>
