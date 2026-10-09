<?php /** Report a concern. It goes to the safeguarding lead and nobody else. */ ?>
      <p class="avm-danger">If a young person is in immediate danger, call 112 first, then the safeguarding lead.</p>

      <form class="avm-panel" data-avm-concern>
        <fieldset style="border:0;margin:0;padding:0">
          <legend style="font-size:14px;font-weight:600;padding:0 0 8px">What is it about?</legend>
          <div class="avm-cats">
<?php foreach (MentorPortal::CONCERN_CATEGORIES as $key => $label): ?>
            <label><input type="radio" name="category" value="<?= e($key) ?>"><span><?= e($label) ?></span></label>
<?php endforeach; ?>
          </div>
        </fieldset>

        <label>Who is it about?
          <select class="avm-select" name="pairing_id">
            <option value="0">Not about one of my mentees</option>
<?php foreach ($portal->menteeOptions() as $m): ?>            <option value="<?= (int) $m['id'] ?>"<?= $id === $m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option>
<?php endforeach; ?>
          </select>
        </label>

        <label>What happened?
          <textarea class="avm-textarea" name="facts" rows="6" placeholder="What was said or done, when, and who was there. Their words where you can remember them."></textarea>
          <small style="display:block;margin-top:6px;color:var(--av-muted)">Write what happened, not what you think it means. Don’t investigate yourself and don’t promise to keep it secret.</small>
        </label>

        <p class="avm-evid-err" data-avm-err hidden></p>
        <div><button class="avm-btn avm-btn--danger" type="submit">Send to safeguarding</button></div>
      </form>

      <section class="avm-panel" data-avm-concern-done hidden>
        <h2 tabindex="-1">Sent to the safeguarding lead</h2>
        <p style="margin:0">Your case number is <b class="av-num" data-avm-caseno></b>. Keep it — quote it if anybody asks about this report.</p>
        <p style="margin:0;color:var(--av-text-2)">Don’t investigate yourself or promise to keep it secret. The lead will come back to you.</p>
        <div><a class="avm-btn" href="?v=today">Back to Today</a></div>
      </section>
