<?php
/**
 * Rules: every rule of the Quest as a switch or a number — the "Vanguard
 * Quest" group of AvRules, the same engine as every other rule in the Studio,
 * with its validation, its provenance and its history. Superadmins only.
 */
declare(strict_types=1);
$rows = QuestDesk::rules();
?>
      <section class="pview avp-view qd-view" id="view-rules" data-view="rules" hidden aria-labelledby="qdRulesH">
        <div class="avp-view-head qd-head">
          <div><h1 class="avp-h1" id="qdRulesH"><?= e($title) ?></h1>
            <p class="qd-sub">The owner's instruction: every rule toggleable. Each one is the Pyramid Manual's unless somebody here changed it; the line under each says who and when. Every catalogue price is a switch of its own in Catalogue.</p></div>
        </div>
        <form class="avp-card qd-rules" method="post" action="/mentorship/conduct.php">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="op" value="rules_save">
          <ul class="qd-rule-list">
<?php foreach ($rows as $r): $field = str_replace('.', '__', (string) $r['key']); $id = 'qdRule-' . $field; ?>
            <li class="qd-rule">
              <div class="qd-rule-t">
                <label for="<?= e($id) ?>"><b><?= e((string) $r['label']) ?></b></label>
                <p class="qd-cell-sub" id="<?= e($id) ?>-h"><?= e((string) $r['help']) ?></p>
                <p class="qd-rule-src"><?= $r['source'] === 'studio' ? 'Set by ' . e((string) $r['updated_by']) . ' on ' . e(substr((string) $r['updated_at'], 0, 10)) : ($r['source'] === 'config' ? 'Set in the site\'s configuration' : 'The manual\'s default') ?></p>
              </div>
              <div class="qd-rule-v">
<?php if ($r['type'] === 'bool'): ?>
                <input type="hidden" name="<?= e($field) ?>" value="0">
                <input class="qd-check-switch" type="checkbox" role="switch" id="<?= e($id) ?>" name="<?= e($field) ?>" value="1" aria-describedby="<?= e($id) ?>-h"<?= $r['value'] ? ' checked' : '' ?>>
<?php else: ?>
                <input class="qd-num-in" type="number" id="<?= e($id) ?>" name="<?= e($field) ?>" value="<?= e((string) $r['value']) ?>"<?= $r['min'] !== null ? ' min="' . (int) $r['min'] . '"' : '' ?><?= $r['max'] !== null ? ' max="' . (int) $r['max'] . '"' : '' ?> aria-describedby="<?= e($id) ?>-h">
<?php endif; ?>
              </div>
            </li>
<?php endforeach; ?>
          </ul>
          <div class="qd-card-foot"><button class="avp-btn avp-btn--ink" type="submit">Save the rules</button><p class="qd-hint">Saved together and validated together; nothing is half-saved.</p></div>
        </form>
      </section>
