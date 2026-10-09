<?php
/** Sessions: every one, and logging the ones that happened — inline. */
$LABEL = ['scheduled' => 'Scheduled', 'attended' => 'Attended', 'missed' => 'Missed', 'cancelled' => 'Cancelled'];
$past  = fn(array $sx): bool => $sx['attendance'] === 'scheduled' && $sx['when'] !== '' && strtotime($sx['when'] . ' UTC') < time();
?>
      <section class="avm-panel" aria-labelledby="avm-sess-h">
        <div class="avm-h2row">
          <h2 id="avm-sess-h">Sessions</h2>
          <button type="button" class="avm-btn avm-btn--ink" data-avm-open="sched">+ Schedule</button>
        </div>
<?php if (!$c['sessions']): ?>
        <p class="avm-empty" style="padding:0">Nothing scheduled yet.</p>
<?php else: foreach (array_reverse($c['sessions']) as $sx): $att = (string) $sx['attendance']; ?>
        <div class="avm-sess" data-session="<?= (int) $sx['id'] ?>" id="s<?= (int) $sx['id'] ?>">
          <span class="avm-pill"><?= e(Mentorship::typeLabel((string) $sx['type'])) ?></span>
          <span>
            <b style="display:block;font-size:14px"><?= e($sx['title']) ?></b>
            <small style="color:var(--av-muted)"><?= e(MentorPortal::when((string) $sx['when'], 'j M Y · g:ia')) ?><?= (int) $sx['duration_min'] > 0 ? ' · ' . (int) $sx['duration_min'] . ' min' : '' ?></small>
<?php if (trim((string) $sx['outcome']) !== ''): ?>            <small style="display:block;margin-top:4px;color:var(--av-text-2)"><?= e($sx['outcome']) ?></small>
<?php endif; ?>
          </span>
<?php if ($past($sx)): ?>
          <button type="button" class="avm-btn" data-avm-log-open aria-expanded="false">Log it</button>
          <form class="avm-log" data-avm-log hidden>
            <fieldset>
              <legend>How long</legend>
<?php foreach ([30, 45, 60, 90] as $mins): ?>
              <label class="avm-opt"><input type="radio" name="minutes" value="<?= $mins ?>"<?= $mins === 60 ? ' checked' : '' ?>><span><?= $mins ?> min</span></label>
<?php endforeach; ?>
            </fieldset>
            <fieldset>
              <legend>What you covered</legend>
<?php foreach (MentorPortal::TOPICS as $topic): ?>
              <label class="avm-opt"><input type="checkbox" name="topics[]" value="<?= e($topic) ?>"><span><?= e($topic) ?></span></label>
<?php endforeach; ?>
            </fieldset>
            <fieldset>
              <legend>How they seemed</legend>
<?php foreach (MentorPortal::MOODS as $mood): ?>
              <label class="avm-opt"><input type="radio" name="mood" value="<?= e($mood) ?>"><span><?= e($mood) ?></span></label>
<?php endforeach; ?>
            </fieldset>
            <label>Outcome and action items
              <textarea class="avm-textarea" name="outcome" rows="3" placeholder="What was covered, and what they will do next"></textarea>
            </label>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="avm-btn avm-btn--ink" type="submit">Save as attended</button>
              <button class="avm-btn" type="button" data-avm-missed>They missed it</button>
            </div>
          </form>
<?php else: ?>
          <span class="avm-pill avm-st--<?= e($att) ?>"><?= e($LABEL[$att] ?? $att) ?></span>
<?php endif; ?>
        </div>
<?php endforeach; endif; ?>
      </section>
