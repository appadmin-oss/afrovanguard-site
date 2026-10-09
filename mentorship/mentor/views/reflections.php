<?php
/** Diary entries mentees chose to share with their mentor. */
$rows = $portal->reflections($p);
$REPLIES = ['Thank you for sharing this.', 'That took something to write.', 'Let’s talk about this next session.'];
?>
<?php if (!$rows): ?>
      <p class="avm-empty">Nothing shared yet. A mentee chooses, entry by entry, what you can read — nothing in their diary is visible to you by default.</p>
<?php else: foreach ($rows as $r): ?>
      <article class="avm-card">
        <div class="avm-card-h">
          <span style="display:flex;align-items:center;gap:10px">
            <span class="avm-av" aria-hidden="true"><?= e($r['initials']) ?></span>
            <span><b style="display:block;font-size:14px"><?= e($r['title']) ?></b>
              <small style="color:var(--av-muted)"><?= e($r['name']) ?> · <?= e($r['date']) ?></small></span>
          </span>
        </div>
        <div style="padding:14px">
          <p style="margin:0 0 12px;font-size:14px;line-height:1.6;color:var(--av-text-2)"><?= e($r['excerpt']) ?></p>
<?php if ($r['reply'] !== ''): ?>
          <p style="margin:0;font-size:13px;color:var(--av-green);font-weight:600">You replied: “<?= e($r['reply']) ?>”</p>
<?php else: ?>
          <form data-avm-reflect data-entry="<?= (int) $r['entry_id'] ?>" style="display:flex;gap:6px;flex-wrap:wrap">
<?php foreach ($REPLIES as $reply): ?>
            <button class="avm-chip" type="submit" name="reply" value="<?= e($reply) ?>"><?= e($reply) ?></button>
<?php endforeach; ?>
          </form>
<?php endif; ?>
        </div>
      </article>
<?php endforeach; endif; ?>
