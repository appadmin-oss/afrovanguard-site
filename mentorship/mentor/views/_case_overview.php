<?php
/** Overview: the goals, the numbers, what to do next time, what they said. */
$stats = $c['stats'];
?>
      <section class="avm-panel" aria-labelledby="avm-goals-h">
        <form class="avm-goals" data-avm-goals data-pairing="<?= (int) $c['pairing_id'] ?>" data-first="<?= e($c['first']) ?>">
          <p class="avm-eyebrow" id="avm-goals-h">What you agreed</p>
          <p data-avm-goals-view style="margin:6px 0 0;font-size:14.5px;line-height:1.55"<?= $c['goals'] === '' ? ' hidden' : '' ?>><?= e($c['goals']) ?></p>
<?php if ($c['goals'] === ''): ?>          <p style="margin:6px 0 0;color:var(--av-muted)">Nothing written down yet. A goal that lives in a conversation is one neither of you can be held to.</p>
<?php endif; ?>
          <div data-avm-goals-form hidden style="margin-top:10px">
            <label class="av-sr" for="avm-goals-t">The goals</label>
            <textarea class="avm-textarea" id="avm-goals-t" name="goals" rows="4" placeholder="What are you both working towards, and by when?"><?= e($c['goals']) ?></textarea>
            <div style="display:flex;gap:8px;margin-top:8px">
              <button class="avm-btn avm-btn--ink" type="submit">Save goals</button>
              <button class="avm-btn" type="button" data-avm-goals-cancel>Cancel</button>
            </div>
          </div>
          <div style="margin-top:10px"><button class="avm-btn" type="button" data-avm-goals-edit><?= $c['goals'] === '' ? 'Set goals' : 'Edit' ?></button></div>
        </form>

        <div class="avm-stats">
          <div><b class="av-num"><?= e(rtrim(rtrim(number_format($stats['hours'], 1), '0'), '.')) ?></b><small>Hours together</small></div>
          <div><b class="av-num"><?= (int) $stats['sessions'] ?></b><small>Sessions attended</small></div>
          <div><b class="av-num"><?= $stats['kept'] === null ? '—' : (int) $stats['kept'] . '%' ?></b><small>Sessions kept</small></div>
        </div>

<?php if ($c['last']): ?>
        <div>
          <p class="avm-eyebrow">Last time you talked · <?= e($c['last']['when']) ?></p>
          <p style="margin:6px 0 0;color:var(--av-text-2)"><?= $c['last']['outcome'] !== '' ? e($c['last']['outcome']) : 'Nothing was written down for that session.' ?></p>
        </div>
<?php endif; ?>
      </section>

      <section class="avm-panel" data-avm-plan data-pairing="<?= (int) $c['pairing_id'] ?>" aria-labelledby="avm-plan-h">
        <div class="avm-h2row"><h2 id="avm-plan-h">Plan the next session</h2></div>
        <p style="margin:0;color:var(--av-muted);font-size:13.5px">Four lines drafted from this pairing’s own record — the goals, the last outcome, the values and the check-in. It drafts; you decide.</p>
        <ol data-avm-plan-out hidden style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px;font-size:14px"></ol>
        <div><button type="button" class="avm-btn" data-avm-plan-go>Draft a plan</button></div>
      </section>

      <div class="avm-two">
        <section class="avm-panel" aria-labelledby="avm-ck-h">
          <div class="avm-h2row"><h2 id="avm-ck-h">How they said they are</h2></div>
<?php if (!$c['checkin']): ?>
          <p class="avm-empty" style="padding:0">No check-in answered yet.</p>
<?php else: $ck = $c['checkin'];
  $QA = [
    ['Energy', ['Good', 'Up and down', 'Struggling']],
    ['Feels heard', ['Yes', 'Mostly', 'No']],
    ['Worried about anything', ['No', 'A little', 'Yes']],
  ]; ?>
          <p class="avm-eyebrow">Answered <?= e($ck['when']) ?></p>
          <dl style="margin:0;display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:14px">
<?php foreach ($QA as $i => $qa): $val = (int) $ck['q' . $i]; ?>
            <dt style="color:var(--av-muted)"><?= e($qa[0]) ?></dt>
            <dd style="margin:0;font-weight:600"><?= e($qa[1][max(0, min(2, $val))]) ?></dd>
<?php endforeach; ?>
          </dl>
<?php endif; ?>
        </section>

        <section class="avm-panel" aria-labelledby="avm-v-h">
          <div class="avm-h2row"><h2 id="avm-v-h">Values</h2><a class="avm-link" href="?v=values&amp;id=<?= (int) $c['pairing_id'] ?>">Observe ›</a></div>
          <dl style="margin:0;display:grid;grid-template-columns:1fr auto;gap:6px 12px;font-size:13.5px">
<?php foreach ($c['values'] as $val): ?>
            <dt><?= e($val['label']) ?></dt>
            <dd style="margin:0;text-align:right;color:var(--av-muted)">
              <?= e(MentorPortal::LEVELS[max(0, min(3, $val['level']))]) ?><?= $val['last'] !== '' ? ' · ' . e($val['last']) : '' ?>
            </dd>
<?php endforeach; ?>
          </dl>
        </section>
      </div>

<?php if ($c['stage'] >= 6): $done = $c['close_steps']; ?>
      <section class="avm-panel" data-avm-close data-pairing="<?= (int) $c['pairing_id'] ?>" aria-labelledby="avm-close-h">
        <div class="avm-h2row"><h2 id="avm-close-h">Plan the close</h2></div>
        <p style="margin:0;color:var(--av-muted);font-size:13.5px">Most young people here have been left by an adult before. An ending that is named and prepared for is one of the most useful things this gives them.</p>
<?php foreach ([
    'schedule' => 'Schedule the closing session, and tell them which one it is',
    'review'   => 'Look back at the goals together',
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
