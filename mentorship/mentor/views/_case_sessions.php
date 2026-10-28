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
<?php elseif ($att === 'scheduled' && $sx['when'] !== ''): ?>
          <span class="avm-pill avm-st--scheduled"><?= e($LABEL['scheduled']) ?></span>
          <button type="button" class="avm-btn" data-avm-edit-open aria-expanded="false">Edit</button>
          <form class="avm-log" data-avm-edit hidden>
            <label>Type
              <select class="avm-select" name="type">
<?php foreach (Mentorship::sessionTypes() as $k => $label): ?>                <option value="<?= e($k) ?>"<?= $k === $sx['type'] ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
              </select>
            </label>
            <label>When <input class="avm-input" type="datetime-local" name="when" value="<?= e($sx['when_local']) ?>" required></label>
            <label>How long
              <select class="avm-select" name="minutes">
<?php foreach ([30, 45, 60, 90, 120] as $mins): ?>                <option value="<?= $mins ?>"<?= $mins === ((int) $sx['duration_min'] ?: 60) ? ' selected' : '' ?>><?= $mins ?> minutes</option>
<?php endforeach; ?>
              </select>
            </label>
            <label>Agenda <input class="avm-input" type="text" name="agenda" maxlength="200" value="<?= e($sx['notes']) ?>"></label>
            <p style="margin:0;font-size:12px;color:var(--av-muted)">Your mentee’s Google Calendar invite moves with it.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="avm-btn avm-btn--ink" type="submit">Save changes</button>
            </div>
          </form>
<?php else: ?>
          <span class="avm-pill avm-st--<?= e($att) ?>"><?= e($LABEL[$att] ?? $att) ?></span>
<?php endif; ?>
<?php /* What Google captures in the session's Meet: the transcript comes back here as minutes. */
      $cap = (string) ($sx['capture'] ?? ''); if ($sx['meet_url'] !== '' && $att === 'scheduled'): ?>
          <small class="avm-cap" style="flex-basis:100%;color:var(--av-muted)"><?= e(match (true) {
              $cap === 'all' => 'Google records and transcribes this session; the transcript becomes its minutes here.',
              $cap === 'transcript' => 'Google transcribes this session; the transcript becomes its minutes here.',
              $cap === 'off' => 'Automatic transcripts are switched off for meetings.',
              ($sx['capture_note'] ?? '') !== '' => 'No automatic transcript: ' . $sx['capture_note'],
              default => 'Paste or attach the transcript afterwards to get its minutes.',
          }) ?></small>
<?php endif; ?>
        </div>
<?php endforeach; endif; ?>
      </section>
