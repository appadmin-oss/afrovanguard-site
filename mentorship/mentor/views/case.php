<?php
/** One mentee: their goals, their sessions, what you have seen, what you said. */
$c    = $case;
$pos  = $portal->positionInRoster($c['pairing_id'], $q, $f, $s);
$tab  = (string) ($_GET['tab'] ?? 'overview');
if (!in_array($tab, ['overview', 'sessions', 'timeline', 'messages'], true)) $tab = 'overview';
$back = '?v=mentees' . ($q !== '' ? '&amp;q=' . rawurlencode($q) : '') . ($f !== 'all' ? '&amp;f=' . e($f) : '') . ($s !== 'wait' ? '&amp;s=' . e($s) : '');
$navTo = fn(?int $pid): string => $pid === null ? '' : '?v=case&amp;id=' . $pid . ($q !== '' ? '&amp;q=' . rawurlencode($q) : '') . ($f !== 'all' ? '&amp;f=' . e($f) : '') . ($s !== 'wait' ? '&amp;s=' . e($s) : '') . '&amp;tab=' . e($tab);
?>
      <div class="avm-casebar">
        <a class="avm-link" href="<?= $back ?>">‹ All mentees</a>
<?php if ($pos['prev'] !== null): ?>        <a class="avm-btn" href="<?= $navTo($pos['prev']) ?>" data-avm-prev aria-label="Previous mentee (K)">‹</a>
<?php endif; ?>
<?php if ($pos['next'] !== null): ?>        <a class="avm-btn" href="<?= $navTo($pos['next']) ?>" data-avm-next aria-label="Next mentee (J)">›</a>
<?php endif; ?>
        <span class="pos"><?= (int) $pos['index'] ?> of <?= (int) $pos['total'] ?></span>
      </div>

      <div class="avm-casehead">
        <div class="avm-casehead-top">
          <span class="avm-av" aria-hidden="true"><?= e($c['initials']) ?></span>
          <div>
            <h2><?= e($c['name']) ?></h2>
            <p><?= e($c['track'] !== '' ? $c['track'] : 'No track set') ?> · paired since <?= e($c['since']) ?></p>
          </div>
          <span class="avm-stagechip"><?= e(MentorPortal::STAGES[$c['stage'] - 1]) ?></span>
        </div>
        <ol class="avm-stages">
<?php foreach ($c['stages'] as $i => $label): ?>
          <li class="<?= $i + 1 < $c['stage'] ? 'done' : '' ?>"<?= $i + 1 === $c['stage'] ? ' aria-current="step"' : '' ?>><?= e($label) ?></li>
<?php endforeach; ?>
        </ol>
      </div>

      <nav class="avm-tabs" aria-label="Case file sections">
<?php foreach (['overview' => 'Overview', 'sessions' => 'Sessions', 'timeline' => 'Timeline', 'messages' => 'Messages'] as $k => $label): ?>
        <a href="<?= e(avm_url(['v' => 'case', 'id' => $c['pairing_id'], 'tab' => $k])) ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
<?php endforeach; ?>
      </nav>

<?php require __DIR__ . '/_case_' . $tab . '.php'; ?>
