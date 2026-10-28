<?php /** Messages: in the portal, between 8am and 8pm, kept and readable by the safeguarding lead. */ ?>
      <section class="avm-panel" aria-labelledby="avm-msg-h">
        <div class="avm-h2row"><h2 id="avm-msg-h">Messages</h2></div>
        <div class="avm-msgs" data-avm-thread>
<?php if (!$c['messages']): ?>
          <p class="avm-empty" style="padding:0">Nothing yet.</p>
<?php else: foreach ($c['messages'] as $m): ?>
          <div class="avm-msg<?= $m['mine'] ? ' me' : '' ?>"><?= e($m['body']) ?><small style="display:block;opacity:.7;margin-top:4px"><?= e($m['when']) ?><?= $m['queued'] ? ' · sends at 8am' : '' ?></small></div>
<?php endforeach; endif; ?>
        </div>
        <form data-avm-msg data-pairing="<?= (int) $c['pairing_id'] ?>">
          <label class="av-sr" for="avm-msg-b">Your message</label>
          <textarea class="avm-textarea" id="avm-msg-b" name="body" rows="3" placeholder="Write to <?= e($c['first']) ?>"></textarea>
          <div style="display:flex;gap:8px;margin-top:8px"><button class="avm-btn avm-btn--ink" type="submit">Send</button></div>
        </form>
        <p class="avm-msgnote">Portal only, 8am to 8pm. Messages are kept, and the safeguarding lead can see them. Don’t move the conversation to another app or meet alone off-site.</p>
      </section>
