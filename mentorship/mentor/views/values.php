<?php
/**
 * Values — the seven Vanguard Quest values, observed with evidence.
 *
 * Each value carries the sentence it is taught by, because a mentor rating
 * "Faith" should be reading the same words the young person was given.
 */
$opts = $portal->menteeOptions();
$pid  = $id > 0 && $portal->ownsPairing($id) ? $id : 0;
if (!$pid) { $next = $portal->nextUnobserved(0); $pid = $next ?? (int) ($opts[0]['id'] ?? 0); }
$seen  = count(array_filter($opts, fn($o) => $o['observed']));
$total = count($opts);
$vals  = $pid ? $portal->valuesFor($pid) : [];
$who   = '';
foreach ($opts as $o) if ($o['id'] === $pid) $who = $o['name'];
$first = $who === '' ? 'them' : explode(' ', trim($who))[0];
?>
<?php if (!$total): ?>
      <p class="avm-empty">No mentees yet. Members can request you once your profile is published. <a class="avm-link" href="?v=profile">Your profile ›</a></p>
<?php else: ?>
      <div class="avm-observing">
        <label class="avm-sort">Observing
          <select data-avm-values-pick>
<?php foreach ($opts as $o): ?>            <option value="<?= (int) $o['id'] ?>"<?= $o['id'] === $pid ? ' selected' : '' ?>><?= e($o['name']) ?><?= $o['observed'] ? ' · done' : '' ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <span class="av-num" style="font-size:13px;color:var(--av-muted)"><?= $seen ?> of <?= $total ?> observed this month</span>
        <span class="avm-progress"><span style="width:<?= $total ? (int) round(100 * $seen / $total) : 0 ?>%"></span></span>
      </div>

      <p style="margin:0;max-width:66ch;font-size:14px;line-height:1.6;color:var(--av-text-2)">
        Rate what you saw this month, not who they are. Exemplary, or any rise since last time,
        needs a sentence about what they did. These are the seven values of the Vanguard Quest;
        the scores feed <?= e($first) ?>’s Quest record.
      </p>

<?php if ($seen >= $total): ?>
      <p class="avm-empty" style="padding:0">Everyone has been observed this month. You can record again for <?= e($who) ?> below.</p>
<?php endif; ?>

      <form data-avm-values data-pairing="<?= (int) $pid ?>" style="display:flex;flex-direction:column;gap:10px">
<?php foreach ($vals as $val): $k = $val['key']; ?>
        <fieldset class="avm-val" data-last="<?= (int) $val['level'] ?>">
          <div class="avm-val-h">
            <legend style="padding:0"><b><?= e($val['label']) ?></b> <em><?= e($val['motto']) ?></em></legend>
            <small><?= $val['last'] === '' ? 'Not recorded yet' : 'Last time: ' . e(MentorPortal::LEVELS[max(0, min(3, $val['level']))]) ?></small>
          </div>
          <div class="avm-seg">
<?php foreach (MentorPortal::LEVELS as $lvl => $label): ?>
            <label><input type="radio" name="level[<?= e($k) ?>]" value="<?= $lvl ?>"><span><?= e($label) ?></span></label>
<?php endforeach; ?>
          </div>
          <div data-avm-evidence hidden>
            <label class="av-sr" for="ev-<?= e($k) ?>">What they did</label>
            <textarea class="avm-textarea" id="ev-<?= e($k) ?>" name="evidence[<?= e($k) ?>]" rows="2" placeholder="What did <?= e($first) ?> actually do?"></textarea>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
<?php foreach ($val['chips'] as $chip): ?>
              <button type="button" class="avm-chip" data-avm-evchip><?= e($chip) ?></button>
<?php endforeach; ?>
            </div>
            <p class="avm-evid-err" data-avm-evmissing hidden>Say what they did before saving this one.</p>
            <p class="avm-evid-err" data-avm-evweak hidden>Say what they did, not how good it was.</p>
          </div>
        </fieldset>
<?php endforeach; ?>
        <div class="avm-save">
          <p data-avm-left><?= count($vals) ?> values still to rate</p>
          <button class="avm-btn avm-btn--ink" type="submit">Save and go to next</button>
        </div>
      </form>
<?php endif; ?>
