<?php
/**
 * The portal's four dialogs and the phone tab bar.
 *
 * Native <dialog>: the browser gives the modal role, the backdrop, the focus
 * trap and Esc-closes-the-top-layer for free, and gets all four right. avm.js
 * returns focus to whatever opened it.
 */
$mentees = $portal->menteeOptions();
?>

<dialog class="avm-dialog" id="avm-d-more" aria-labelledby="avm-d-more-h">
  <form method="dialog">
    <h2 id="avm-d-more-h">More</h2>
    <div class="avm-navgroup">
      <a class="avm-navlink" href="?v=checkins"><?= Icons::mentorPortal('check') ?><span>Check-ins</span><?= $badges['checkins'] ? '<span class="avm-badge">' . (int) $badges['checkins'] . '</span>' : '' ?></a>
      <a class="avm-navlink" href="?v=reflections"><?= Icons::mentorPortal('book') ?><span>Reflections</span><?= $badges['reflections'] ? '<span class="avm-badge">' . (int) $badges['reflections'] . '</span>' : '' ?></a>
      <a class="avm-navlink" href="?v=requests"><?= Icons::mentorPortal('inbox') ?><span>Requests</span><?= $badges['requests'] ? '<span class="avm-badge">' . (int) $badges['requests'] . '</span>' : '' ?></a>
      <a class="avm-navlink" href="?v=profile"><?= Icons::mentorPortal('person') ?><span>Profile</span></a>
      <a class="avm-navlink" href="?v=support"><?= Icons::mentorPortal('help') ?><span>Support</span></a>
    </div>
    <div class="row"><button class="avm-btn" value="close">Close</button></div>
  </form>
</dialog>

<dialog class="avm-dialog" id="avm-d-sched" aria-labelledby="avm-d-sched-h">
  <form method="dialog" data-avm-sched>
    <h2 id="avm-d-sched-h">Schedule a session</h2>
    <input type="hidden" name="group_ids" value="">
    <label>Mentee
      <select class="avm-select" name="pairing_id" required>
<?php foreach ($mentees as $m): ?>        <option value="<?= (int) $m['id'] ?>"<?= $case && $case['pairing_id'] === $m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Type
      <select class="avm-select" name="type">
<?php foreach (Mentorship::sessionTypes() as $k => $label): ?>        <option value="<?= e($k) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>When <input class="avm-input" type="datetime-local" name="when" required></label>
    <label>How long
      <select class="avm-select" name="minutes">
        <option value="30">30 minutes</option><option value="45">45 minutes</option>
        <option value="60" selected>60 minutes</option><option value="90">90 minutes</option>
      </select>
    </label>
    <label>Agenda <input class="avm-input" type="text" name="agenda" maxlength="200" placeholder="What you will cover"></label>
    <div class="row">
      <button class="avm-btn" value="cancel">Cancel</button>
      <button class="avm-btn avm-btn--ink" value="ok">Schedule</button>
    </div>
  </form>
</dialog>

<dialog class="avm-dialog" id="avm-d-support" aria-labelledby="avm-d-support-h">
  <form method="dialog" data-avm-support>
    <h2 id="avm-d-support-h">Ask your coordinator</h2>
    <input type="hidden" name="topic" value="">
    <label>What do you need? <textarea class="avm-textarea" name="body" rows="4" required></textarea></label>
    <div class="row">
      <button class="avm-btn" value="cancel">Cancel</button>
      <button class="avm-btn avm-btn--ink" value="ok">Send</button>
    </div>
  </form>
</dialog>

<dialog class="avm-dialog" id="avm-d-confirm" aria-labelledby="avm-d-confirm-h">
  <form method="dialog">
    <h2 id="avm-d-confirm-h">Are you sure</h2>
    <p id="avm-d-confirm-p" style="margin:0;color:var(--av-text-2)"></p>
    <div class="row">
      <button class="avm-btn" value="cancel">Cancel</button>
      <button class="avm-btn avm-btn--ink" value="ok" data-avm-confirm-ok>Confirm</button>
    </div>
  </form>
</dialog>

<dialog class="avm-dialog" id="avm-d-keys" aria-labelledby="avm-d-keys-h">
  <form method="dialog">
    <h2 id="avm-d-keys-h">Keyboard shortcuts</h2>
    <dl style="margin:0;display:grid;grid-template-columns:auto 1fr;gap:8px 16px;font-size:13.5px">
      <dt><kbd>/</kbd></dt><dd>Search your mentees</dd>
      <dt><kbd>J</kbd> <kbd>K</kbd></dt><dd>Next and previous mentee, in a case file</dd>
      <dt><kbd>N</kbd></dt><dd>Schedule a session</dd>
      <dt><kbd>?</kbd></dt><dd>This list</dd>
      <dt><kbd>Esc</kbd></dt><dd>Close whatever is open</dd>
    </dl>
    <div class="row"><button class="avm-btn avm-btn--ink" value="close">Got it</button></div>
  </form>
</dialog>
