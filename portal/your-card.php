<?php
/**
 * Portal → Membership → "Your card" block. Include inside the portal's Membership panel.
 * Expects $card (or null when MemberCards has no card), $isStaffView (bool).
 * States: no card yet · photo missing (initials + link) · photo too small (message above buttons).
 */
$photoTooSmall = $card && !empty($card['photo_too_small']);
?>
<section class="avc-section" aria-labelledby="avc-title">
  <h2 id="avc-title">Your card</h2>
  <?php if (!$card): ?>
    <p>No card yet — ask the NGV office.</p>
  <?php else: ?>
    <div class="avc-holder" data-side="front">
      <?php $avcSide = 'both'; include __DIR__ . '/../partials/id-card.php'; ?>
      <?php if (empty($card['photo_url'])): ?><a href="/portal/#profile">Add a photo</a><?php endif; ?>
      <?php if ($photoTooSmall): ?><p role="status">Your photo is too small to print sharply. Upload one at least 800 px wide.</p><?php endif; ?>
      <div class="avc-actions">
        <button type="button" class="avc-btn" data-avc-flip aria-pressed="false">Show back</button>
        <a class="avc-btn avc-btn--primary" href="/card/print?format=pdf" download<?= $photoTooSmall ? ' aria-disabled="true" tabindex="-1"' : '' ?>>Download for printing (PDF)</a>
        <a class="avc-btn" href="/card/print?format=png" download>Download images (PNG)</a>
      </div>
    </div>
    <script>
    document.querySelectorAll('[data-avc-flip]').forEach(function (b) {
      b.addEventListener('click', function () {
        var h = b.closest('.avc-holder'), back = h.dataset.side === 'front';
        h.dataset.side = back ? 'back' : 'front';
        b.setAttribute('aria-pressed', String(back));
        b.textContent = back ? 'Show front' : 'Show back';
      });
    });
    </script>
  <?php endif; ?>
</section>
