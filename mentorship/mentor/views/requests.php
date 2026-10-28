<?php
/** Members asking you to mentor them. */
$rows  = $portal->requests();
$block = $portal->acceptBlock();
?>
<?php if ($block !== ''): ?>
      <p class="avm-offline" role="status"><?= e($block) ?><?= str_contains($block, 'safeguarding') ? ' ' : '' ?><?php if (str_contains($block, 'safeguarding')): ?><a class="avm-link" href="?v=module&amp;key=safeguarding">Take it now ›</a><?php endif; ?></p>
<?php endif; ?>
<?php if (!$rows): ?>
      <p class="avm-empty">No requests. Members find you through the mentor directory once your profile is published.</p>
<?php else: foreach ($rows as $r): ?>
      <section class="avm-card" data-request="<?= (int) $r['id'] ?>">
        <div class="avm-card-h">
          <span style="display:flex;align-items:center;gap:10px">
            <span class="avm-av" aria-hidden="true"><?= e($r['initials']) ?></span>
            <span><b style="display:block;font-size:14px"><?= e($r['name']) ?></b><small style="color:var(--av-muted)">Asked <?= e($r['when']) ?></small></span>
          </span>
        </div>
        <div style="padding:14px;display:flex;flex-direction:column;gap:12px">
<?php if ($r['message'] !== ''): ?>          <p style="margin:0;font-size:14px;line-height:1.6;color:var(--av-text-2)">“<?= e($r['message']) ?>”</p>
<?php endif; ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="avm-btn avm-btn--ink" data-avm-accept<?= $block !== '' ? ' disabled' : '' ?>>Accept</button>
            <button type="button" class="avm-btn" data-avm-decline>Decline</button>
          </div>
        </div>
      </section>
<?php endforeach; endif; ?>
