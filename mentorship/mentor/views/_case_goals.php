<?php
/**
 * The goals panel — the thing the whole pairing is for.
 *
 * Rendered by the case file and, after every write, by itself (index.php,
 * ?partial=goals) so the server stays the only thing that decides what a goal
 * looks like. One <form> serves add and edit: three fields is not enough
 * markup to justify repeating it under every row, and a mentor is only ever
 * editing one goal at a time.
 */
$goals = $c['goals_list'];
$tally = MentorPortal::goalTally($goals);
$pct   = $tally['live'] > 0 ? (int) round(100 * $tally['met'] / $tally['live']) : 0;
?>
        <form class="avm-goalbox" data-avm-goals data-pairing="<?= (int) $c['pairing_id'] ?>" data-first="<?= e($c['first']) ?>" aria-labelledby="avm-goals-h">
          <div class="avm-goals">
          <div class="avm-val-h" style="align-items:center">
            <p class="avm-eyebrow" id="avm-goals-h">Goals</p>
<?php if ($tally['live'] > 0): ?>
            <div class="avm-goalmeter">
              <div class="avm-goalbar" role="img" aria-label="<?= $tally['met'] ?> of <?= $tally['live'] ?> goals met">
                <i style="width:<?= $pct ?>%"></i>
              </div>
              <p class="avm-goalsum"><b class="av-num"><?= $tally['met'] ?> of <?= $tally['live'] ?></b> met</p>
            </div>
<?php endif; ?>
          </div>

<?php if (!$goals): ?>
          <p class="avm-goal-none">No goals yet. Agree one to three at the kick-off — they are what every session comes back to.</p>
<?php else: ?>
          <ol class="avm-goal-list">
<?php foreach ($goals as $g): $met = $g['status'] === 'met'; $drop = $g['status'] === 'dropped'; ?>
            <li class="avm-goal<?= $met ? ' is-met' : '' ?><?= $drop ? ' is-dropped' : '' ?>">
<?php if ($drop): ?>
              <span class="avm-goal-tick is-aside" aria-hidden="true">—</span>
<?php else: ?>
              <button type="button" class="avm-goal-tick" data-avm-goal-tick data-goal="<?= (int) $g['id'] ?>"
                      aria-pressed="<?= $met ? 'true' : 'false' ?>"
                      aria-label="<?= $met ? 'Not met after all' : 'Mark met' ?>: <?= e($g['title']) ?>">
                <svg viewBox="0 0 20 20" width="14" height="14" aria-hidden="true" focusable="false"><path d="M4 10.5 8 14.5 16 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
<?php endif; ?>
              <div class="avm-goal-b">
                <p class="avm-goal-t"><?= e($g['title']) ?></p>
<?php if ($g['measure'] !== ''): ?>
                <p class="avm-goal-m">You’ll know because: <?= e($g['measure']) ?></p>
<?php endif; ?>
                <p class="avm-goal-meta">
<?php if ($met): ?>
                  <span>Met<?= $g['closed_at'] !== '' ? ' ' . e(MentorPortal::when($g['closed_at'], 'j M Y')) : '' ?></span>
<?php elseif ($drop): ?>
                  <span>Set aside<?= $g['closed_at'] !== '' ? ' ' . e(MentorPortal::when($g['closed_at'], 'j M Y')) : '' ?></span>
<?php endif; ?>
<?php if ($g['due_label'] !== ''): ?>
                  <span class="avm-goal-due<?= $g['overdue'] ? ' is-over' : '' ?>"><?= $g['overdue'] ? 'Was due ' : 'By ' ?><?= e($g['due_label']) ?></span>
<?php endif; ?>
                  <span>Agreed <?= e(MentorPortal::when($g['created_at'], 'j M Y')) ?></span>
                </p>
              </div>
              <button type="button" class="avm-link avm-goal-edit" data-avm-goal-edit
                      data-goal="<?= (int) $g['id'] ?>" data-title="<?= e($g['title']) ?>"
                      data-measure="<?= e($g['measure']) ?>" data-due="<?= e($g['due_on']) ?>"
                      data-status="<?= e($g['status']) ?>">Edit<span class="av-sr">: <?= e($g['title']) ?></span></button>
            </li>
<?php endforeach; ?>
          </ol>
<?php endif; ?>
          </div>

<?php if ($tally['can_add']): ?>
          <button type="button" class="avm-btn avm-goal-add" data-avm-goal-new>+ <?= $goals ? 'Add a goal' : 'Add the first goal' ?></button>
<?php else: ?>
          <p class="avm-goal-none">Three goals at a time is the limit. Mark one met, or set it aside, before adding another.</p>
<?php endif; ?>

          <div class="avm-goalform" data-avm-goal-form hidden>
            <input type="hidden" name="goal_id" value="">
            <p class="avm-goalform-h" data-avm-goal-formh>New goal</p>
            <label class="avm-lbl" for="avm-goal-t">What are you both working towards?</label>
            <input class="avm-input" id="avm-goal-t" name="title" maxlength="200"
                   placeholder="Lead one community project by December">
            <label class="avm-lbl" for="avm-goal-m">How will you both know it happened? <em>Optional</em></label>
            <input class="avm-input" id="avm-goal-m" name="measure" maxlength="300"
                   placeholder="The project runs and she reports back to the chapter">
            <label class="avm-lbl" for="avm-goal-d">By when <em>Optional</em></label>
            <input class="avm-input avm-goal-date" id="avm-goal-d" name="due" type="date">
            <div class="avm-goalform-a">
              <button type="submit" class="avm-btn avm-btn--ink" data-avm-goal-save>Save goal</button>
              <button type="button" class="avm-btn" data-avm-goal-cancel>Cancel</button>
              <button type="button" class="avm-link avm-goal-aside" data-avm-goal-aside hidden>Set aside</button>
              <button type="button" class="avm-link avm-goal-aside" data-avm-goal-back hidden>Bring back</button>
              <button type="button" class="avm-link avm-goal-del" data-avm-goal-remove hidden>Remove</button>
            </div>
            <p class="avm-goal-err" data-avm-goal-err role="alert" hidden></p>
          </div>
        </form>
