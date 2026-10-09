<?php
/**
 * One roster row. Used by the full page AND by "Show 25 more", which fetches
 * this same file — so a row appended by JavaScript is byte-identical to one
 * the server drew, and the two cannot drift.
 *
 * $row is set by the caller.
 */
$href = '?v=case&amp;id=' . (int) $row['pairing_id']
      . ($roster['q'] !== '' ? '&amp;q=' . rawurlencode($roster['q']) : '')
      . ($roster['filter'] !== 'all' ? '&amp;f=' . e($roster['filter']) : '')
      . ($roster['sort'] !== 'wait' ? '&amp;s=' . e($roster['sort']) : '');
$sub = trim($row['track'] . ($row['chapter'] !== '' ? ' · ' . $row['chapter'] : ''));
?>
<div class="avm-tr">
  <label class="avm-pick"><input class="avm-ck" type="checkbox" data-avm-pick value="<?= (int) $row['pairing_id'] ?>" aria-label="Select <?= e($row['name']) ?>"></label>
  <a class="avm-who" href="<?= $href ?>">
    <span class="avm-av" aria-hidden="true"><?= e($row['initials']) ?></span>
    <span style="min-width:0">
      <b><?= e($row['name']) ?><span class="avm-dot avm-dot--<?= e($row['tone']) ?>" title="<?= e($row['health']) ?>"></span><span class="av-sr"> · <?= e($row['health']) ?></span></b>
      <small><?= e($sub !== '' ? $sub : 'No track set') ?></small>
    </span>
  </a>
  <span class="stage"><?= e($row['stage_label']) ?></span>
  <span class="r <?= $row['days'] !== null && $row['days'] > 21 ? 'av-late' : ($row['days'] !== null && $row['days'] > 14 ? 'av-due' : '') ?>">
    <?= $row['days'] === null ? '—' : (int) $row['days'] . 'd' ?><span class="av-sr"> since the last session</span>
  </span>
  <span class="r"><?= $row['kept'] === null ? '—' : (int) $row['kept'] . '%' ?><span class="av-sr"> of sessions kept</span></span>
  <span class="next"><?= $row['next'] !== null ? e($row['next']) : ($row['to_log'] > 0 ? 'Session to log' : 'Nothing booked') ?></span>
</div>
