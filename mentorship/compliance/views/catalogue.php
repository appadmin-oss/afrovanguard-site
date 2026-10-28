<?php
/**
 * Catalogue: the Pyramid Manual's awards (Section 2), deductions (Scoring
 * Board §3) and fines (Section 1), each switchable and re-priceable by a
 * superadmin, with the manual's price always beside the live one, and every
 * change in the history. The committee sees it read-only.
 */
declare(strict_types=1);
$tabs = ['award' => 'Awards', 'deduction' => 'Deductions', 'fine' => 'Fines'];
$groupsOf = ['award' => Quest::groups('awardable-actions'), 'deduction' => [], 'fine' => Quest::groups('fines')];
$hist = QuestRules::history(30);
$fmt = static fn(string $k, ?int $v): string => $v === null ? 'each time' : ($k === 'fine' ? '₦' . number_format($v) : ($k === 'deduction' ? '−' : '+') . $v);
?>
      <section class="pview avp-view qd-view" id="view-catalogue" data-view="catalogue" hidden aria-labelledby="qdCatH">
        <div class="avp-view-head qd-head">
          <div><h1 class="avp-h1" id="qdCatH"><?= e($title) ?></h1>
            <p class="qd-sub">The Pyramid Manual's prices. A mentor picks the deed; the price is the catalogue's, so the same deed is worth the same for everybody. <?= $edit ? 'Switch a line off, or re-price it — the manual\'s price stays beside it, and "Manual" puts it back.' : 'A superadmin changes prices.' ?></p></div>
        </div>

        <div class="qd-seg" role="tablist" aria-label="Catalogue">
<?php $first = true; foreach ($tabs as $k => $l): $n = count(QuestRules::items($k)); ?>
          <button type="button" role="tab" class="qd-seg-b<?= $first ? ' is-on' : '' ?>" aria-selected="<?= $first ? 'true' : 'false' ?>" aria-controls="qdCat-<?= e($k) ?>" id="qdCatTab-<?= e($k) ?>" data-qd-tab><?= e($l) ?> <span class="av-num"><?= $n ?></span></button>
<?php $first = false; endforeach; ?>
        </div>

<?php $first = true; foreach ($tabs as $kind => $label): $items = QuestRules::items($kind); ?>
        <div class="qd-tabpanel" role="tabpanel" id="qdCat-<?= e($kind) ?>" aria-labelledby="qdCatTab-<?= e($kind) ?>"<?= $first ? '' : ' hidden' ?>>
          <div class="qd-table-wrap">
            <table class="qd-table qd-table--catalogue">
              <caption class="av-sr"><?= e($label) ?></caption>
              <thead><tr><th scope="col" class="qd-c-switch">On</th><th scope="col">Line</th><th scope="col" class="qd-c-num">Price</th><th scope="col" class="qd-c-num">Manual</th><th scope="col"><?= $kind === 'deduction' ? 'Consequence' : ($kind === 'fine' ? 'Charged as' : 'Group') ?></th><?php if ($edit): ?><th scope="col" class="qd-c-act"><span class="av-sr">Change</span></th><?php endif; ?></tr></thead>
              <tbody>
<?php foreach ($items as $id => $i): ?>
                <tr class="<?= $i['enabled'] ? '' : 'is-off' ?><?= $i['overridden'] ? ' is-changed' : '' ?>" data-row="<?= e($kind . ':' . $id) ?>">
                  <td class="qd-c-switch">
<?php if ($edit): ?>
                    <form method="post" action="/mentorship/conduct.php" data-qd-ajax data-qd-switch>
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="rule_item">
                      <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
                      <input type="hidden" name="enabled" value="<?= $i['enabled'] ? '0' : '1' ?>" data-qd-next>
                      <button type="submit" class="qd-switch" role="switch" aria-checked="<?= $i['enabled'] ? 'true' : 'false' ?>" aria-label="<?= e($i['label']) ?>"><span aria-hidden="true"></span></button>
                    </form>
<?php else: ?>
                    <span class="qd-switch" role="img" aria-label="<?= $i['enabled'] ? 'On' : 'Off' ?>" data-on="<?= $i['enabled'] ? '1' : '0' ?>"><span aria-hidden="true"></span></span>
<?php endif; ?>
                  </td>
                  <td><b><?= e((string) $i['label']) ?></b><?php if (!empty($i['cadence'])): ?><span class="qd-cell-sub"><?= e(ucfirst((string) $i['cadence'])) ?></span><?php endif; ?><?php if (!empty($i['note'])): ?><span class="qd-cell-sub"><?= e((string) $i['note']) ?></span><?php endif; ?><?php if ($i['override_note'] !== ''): ?><span class="qd-cell-sub qd-why">Changed: <?= e((string) $i['override_note']) ?></span><?php endif; ?></td>
                  <td class="qd-c-num av-num" data-qd-price><b><?= e($fmt($kind, $i['value'])) ?></b></td>
                  <td class="qd-c-num av-num qd-muted"><?= e($fmt($kind, $i['base'])) ?></td>
                  <td><?= e($kind === 'deduction' ? (string) ($i['consequence'] ?? '') . (($i['suggests'] ?? '') === 'demotion' ? ' — suggested, never applied' : '')
                         : ($kind === 'fine' ? ucfirst((string) ($i['ledger_reason'] ?? '')) . ' · ' . ($groupsOf['fine'][$i['group']] ?? '')
                         : ($groupsOf['award'][$i['group']] ?? ''))) ?></td>
<?php if ($edit): ?>
                  <td class="qd-c-act"><div class="qd-row-actions">
                    <form class="qd-inline" method="post" action="/mentorship/conduct.php" data-qd-ajax data-qd-price-form>
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="rule_item">
                      <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
                      <label><span class="av-sr">New price for <?= e((string) $i['label']) ?></span><input class="qd-price-in" type="text" inputmode="numeric" name="value" value="<?= $i['value'] === null ? '' : (int) $i['value'] ?>" placeholder="<?= $kind === 'fine' ? 'each time' : '' ?>"></label>
                      <label><span class="av-sr">Why</span><input class="qd-why-in" type="text" name="note" maxlength="300" placeholder="Why"></label>
                      <button class="avp-btn avp-btn--sq" type="submit">Set</button>
                    </form>
<?php if ($i['overridden']): ?>
                    <form method="post" action="/mentorship/conduct.php" data-qd-ajax class="qd-inline">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="rule_reset">
                      <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
                      <button class="avp-btn avp-btn--sq qd-btn-quiet" type="submit">Manual</button>
                    </form>
<?php endif; ?>
                  </div></td>
<?php endif; ?>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
<?php $first = false; endforeach; ?>

        <section class="avp-card qd-gap" aria-labelledby="qdCatHistH">
          <div class="avp-card-head"><h2 id="qdCatHistH">History</h2><span class="avp-head-sub">Every change to a price or a switch, newest first</span></div>
<?php if (!$hist): ?>
          <div class="avp-card-body"><p class="qd-empty">No changes — the catalogue is the manual's.</p></div>
<?php else: ?>
          <div class="avp-card-body">
            <ul class="qd-list qd-list--plain">
<?php foreach ($hist as $h): ?>
              <li><span class="qd-list-main"><b><?= e((string) (QuestRules::item((string) $h['kind'], (string) $h['item_id'])['label'] ?? $h['item_id'])) ?></b> — <?= e((string) $h['what']) ?><?= trim((string) $h['note']) !== '' ? ' · ' . e((string) $h['note']) : '' ?></span>
                <span class="qd-list-meta"><?= e((string) ($h['by_name'] ?? '')) ?> · <?= e(substr((string) $h['done_at'], 0, 10)) ?></span></li>
<?php endforeach; ?>
            </ul>
          </div>
<?php endif; ?>
        </section>
      </section>
