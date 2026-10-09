<?php
/** The roster. Search, sort, filter and page all live in the URL. */
$roster = $portal->roster($q, $f, $s, $p);
$CHIPS = [
    'all'     => 'All',
    'attn'    => 'Needs attention',
    'wait'    => 'No session in 21+ days',
    'goals'   => 'Goals not set',
    'log'     => 'Session to log',
    'closing' => 'Closing soon',
];
$SORTS = [
    'wait'  => 'Longest since last session',
    'name'  => 'Name A–Z',
    'kept'  => 'Lowest sessions kept',
    'stage' => 'Pairing stage',
];
$shown = count($roster['rows']);
?>
      <form class="avm-tools" data-avm-roster role="search" action="" method="get">
        <input type="hidden" name="v" value="mentees">
        <input type="hidden" name="f" value="<?= e($roster['filter']) ?>">
        <label class="avm-search">
          <?= Icons::SEARCH ?><span class="av-sr">Search your mentees</span>
          <input type="search" name="q" id="q" value="<?= e($roster['q']) ?>" placeholder="Search name, track or chapter" data-avm-q autocomplete="off">
        </label>
        <label class="avm-sort">Sort
          <select name="s" data-avm-s>
<?php foreach ($SORTS as $k => $label): ?>            <option value="<?= e($k) ?>"<?= $k === $roster['sort'] ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <noscript><button class="avm-btn avm-btn--ink" type="submit">Search</button></noscript>
      </form>

      <div class="avm-chips" role="group" aria-label="Filter">
<?php foreach ($CHIPS as $key => $label): ?>
        <a class="avm-chip" href="<?= e(avm_url(['v' => 'mentees', 'f' => $key, 'p' => null, 'id' => null])) ?>"<?= $key === $roster['filter'] ? ' aria-current="true"' : '' ?>>
          <?= e($label) ?> <span class="av-num"><?= (int) $roster['counts'][$key] ?></span>
        </a>
<?php endforeach; ?>
      </div>

<?php if ($roster['all'] === 0): ?>
      <p class="avm-empty">No mentees yet. Members can request you once your profile is published. <a class="avm-link" href="?v=profile">Your profile ›</a></p>
<?php elseif ($shown === 0): ?>
      <p class="avm-empty">No one matches. <a class="avm-link" href="?v=mentees">Show everyone</a></p>
<?php else: ?>
      <div class="avm-table" data-avm-table>
        <div class="avm-tr avm-tr--head">
          <label class="avm-pick"><input class="avm-ck" type="checkbox" data-avm-all aria-label="Select everyone on this page"></label>
          <span>Mentee</span><span>Stage</span><span class="r">Last</span><span class="r">Kept</span><span class="next">Next session</span>
        </div>
        <div data-avm-rows>
<?php foreach ($roster['rows'] as $row) require __DIR__ . '/_row.php'; ?>
        </div>
      </div>

      <div class="avm-foot">
        <span data-avm-shown>Showing <?= $shown ?> of <?= (int) $roster['total'] ?></span>
<?php if ($shown < $roster['total']): ?>
        <a class="avm-btn" href="<?= e(avm_url(['v' => 'mentees', 'p' => $roster['page'] + 1])) ?>" data-avm-more data-next="<?= $roster['page'] + 1 ?>">Show 25 more</a>
<?php endif; ?>
      </div>

      <div class="avm-bulk" data-avm-bulk hidden>
        <span><span data-avm-pickn>0</span> selected</span>
        <button type="button" class="avm-btn avm-btn--ghost-ink" data-avm-bulk-act="message">Message</button>
        <button type="button" class="avm-btn avm-btn--ghost-ink" data-avm-bulk-act="group">Schedule group session</button>
        <button type="button" class="avm-btn avm-btn--ghost-ink" data-avm-bulk-act="checkin">Ask for a check-in</button>
        <button type="button" class="avm-x" data-avm-bulk-clear aria-label="Clear selection">×</button>
      </div>
<?php endif; ?>
