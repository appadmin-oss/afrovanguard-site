<?php
/** The seven Vanguard Quest values, observed with evidence. */
$opts = $portal->menteeOptions();
$pid  = $id > 0 && $portal->ownsPairing($id) ? $id : 0;
if (!$pid) { $next = $portal->nextUnobserved(0); $pid = $next ?? (int) ($opts[0]['id'] ?? 0); }
$seen  = count(array_filter($opts, fn($o) => $o['observed']));
$total = count($opts);
$vals  = $pid ? $portal->valuesFor($pid) : [];
$who   = '';
foreach ($opts as $o) if ($o['id'] === $pid) $who = $o['name'];

$CHIPS = [
    'individuation'  => ['Chose their own path when it was easier not to', 'Said what they thought in front of people who disagreed'],
    'faith'          => ['Kept going on something with no quick result', 'Steadied someone else who was ready to give up'],
    'diligence'      => ['Finished what they started without being chased', 'Came back to work that had already been marked'],
    'accountability' => ['Owned a mistake before anybody raised it', 'Said what they would do differently, and did it'],
    'responsibility' => ['Took on something nobody asked them to', 'Carried a task that affected other people'],
    'culture'        => ['Explained where something came from, not just what it is', 'Made room for a way of doing things that was not theirs'],
    'communal'       => ['Put the group’s work ahead of their own turn', 'Noticed somebody being left out and acted'],
];
?>
<?php if (!$total): ?>
      <p class="avm-empty">No mentees yet. Members can request you once your profile is published.</p>
<?php elseif ($seen >= $total): ?>
      <p class="avm-empty">Everyone has been observed this month. You can still record again for <?= e($who) ?> below.</p>
<?php endif; ?>

<?php if ($total): ?>
      <div class="avm-tools">
        <label class="avm-sort">Mentee
          <select data-avm-values-pick>
<?php foreach ($opts as $o): ?>            <option value="<?= (int) $o['id'] ?>"<?= $o['id'] === $pid ? ' selected' : '' ?>><?= e($o['name']) ?><?= $o['observed'] ? ' · done' : '' ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <span style="flex:1;min-width:180px">
          <small style="display:block;color:var(--av-muted);margin-bottom:4px"><span class="av-num"><?= $seen ?></span> of <span class="av-num"><?= $total ?></span> observed this month</small>
          <span class="avm-progress"><span style="width:<?= $total ? (int) round(100 * $seen / $total) : 0 ?>%"></span></span>
        </span>
      </div>

      <form data-avm-values data-pairing="<?= (int) $pid ?>" style="display:flex;flex-direction:column;gap:12px">
<?php foreach ($vals as $val): $k = $val['key']; ?>
        <fieldset class="avm-val" data-last="<?= (int) $val['level'] ?>">
          <div class="avm-val-h">
            <legend style="padding:0"><b><?= e($val['label']) ?></b></legend>
            <small><?= $val['last'] === '' ? 'Not recorded yet' : 'Last time · ' . e(MentorPortal::LEVELS[max(0, min(3, $val['level']))]) . ' · ' . e($val['last']) ?></small>
          </div>
          <div class="avm-seg">
<?php foreach (MentorPortal::LEVELS as $lvl => $label): ?>
            <label><input type="radio" name="level[<?= e($k) ?>]" value="<?= $lvl ?>"><span><?= e($label) ?></span></label>
<?php endforeach; ?>
          </div>
          <div data-avm-evidence hidden>
            <label class="av-sr" for="ev-<?= e($k) ?>">What they did</label>
            <textarea class="avm-textarea" id="ev-<?= e($k) ?>" name="evidence[<?= e($k) ?>]" rows="2" placeholder="What did they actually do?"></textarea>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
<?php foreach (($CHIPS[$k] ?? []) as $chip): ?>
              <button type="button" class="avm-chip" data-avm-evchip><?= e($chip) ?></button>
<?php endforeach; ?>
            </div>
            <p class="avm-evid-err" data-avm-evmissing hidden>Say what they did before saving this one.</p>
            <p class="avm-evid-err" data-avm-evweak hidden>Say what they did, not how good it was.</p>
          </div>
        </fieldset>
<?php endforeach; ?>
        <div><button class="avm-btn avm-btn--ink" type="submit">Save and go to the next mentee</button></div>
      </form>
<?php endif; ?>
