<?php
/**
 * Member ID card, front + back. Class prefix avc-.
 * Used by: portal "Your card", q.php (holder/staff), card/print-template.php.
 *
 * Expects $card = IdCard::forMember($id):
 *   given, family, initials, role, tier_letter, number ('AVM-YY-NNNN'),
 *   photo_url|null, programmes (string[] max 2), member_since (YYYY),
 *   band: ['label'=>'Active member','tone'=>'ok|warn|bad|neutral'],   // from NgvCard::standing() ONLY
 *   status_date ('6 Oct 2026'), qr_svg (NgvCard::qrSvg(MemberCards::scanUrl($code))),
 *   card_code ('AVQR-XXXX-XXXX'), category, issued ('Oct 2026')
 * Optional: $avcMode = 'screen' (default) | 'print'; $avcSide = 'both' | 'front' | 'back'
 * NEVER add money, discipline, medical data, phone numbers or addresses.
 */
$avcMode = $avcMode ?? 'screen';
$avcSide = $avcSide ?? 'both';
$seal = '/assets/site/av-seal.png';
?>
<?php if ($avcSide !== 'back'): ?>
<article class="avc avc-face avc-front<?= $avcMode === 'print' ? ' is-print' : '' ?>" aria-label="Member card, front">
  <div class="avc-trim">
    <header class="avc-head">
      <img class="avc-seal" src="<?= e($seal) ?>" alt="">
      <div class="avc-brand">
        <span class="avc-brand-name">Afrovanguard</span>
        <span class="avc-strap">Ambassadors for Community, Tech &amp; Cultural Advancements</span>
      </div>
      <span class="avc-kind">MEMBER</span>
    </header>

    <div class="avc-photo">
      <?php if (!empty($card['photo_url'])): ?>
        <img src="<?= e($card['photo_url']) ?>" alt="">
      <?php else: ?>
        <span class="avc-initials" aria-hidden="true"><?= e($card['initials']) ?></span>
      <?php endif; ?>
      <span class="avc-tier"><span class="avc-tier-letter"><?= e($card['tier_letter']) ?></span><span class="av-num"><?= e($card['number']) ?></span></span>
    </div>

    <div class="avc-name">
      <span class="avc-given"><?= e($card['given']) ?></span>
      <span class="avc-family"><?= e($card['family']) ?></span>
      <?php if (!empty($card['role'])): ?><span class="avc-role"><?= e($card['role']) ?></span><?php endif; ?>
    </div>

    <div class="avc-foot">
      <dl class="avc-facts">
        <?php if (!empty($card['programmes'])): ?>
          <dt>Programmes</dt>
          <dd><?php foreach (array_slice($card['programmes'], 0, 2) as $p): ?><span><?= e($p) ?></span><?php endforeach; ?></dd>
        <?php endif; ?>
        <dt>Member since</dt><dd class="av-num"><?= e($card['member_since']) ?></dd>
      </dl>
      <div class="avc-qr" role="img" aria-label="QR code. Scan to check this card"><?= $card['qr_svg'] /* trusted SVG from NgvCard::qrSvg */ ?></div>
    </div>
  </div>
  <div class="avc-band avc-band--<?= e($card['band']['tone']) ?>">
    <strong><?= e(mb_strtoupper($card['band']['label'])) ?></strong>
    <span>Status as of <?= e($card['status_date']) ?><br>Scan for the live one</span>
  </div>
</article>
<?php endif; ?>

<?php if ($avcSide !== 'front'): ?>
<article class="avc avc-face avc-back<?= $avcMode === 'print' ? ' is-print' : '' ?>" aria-label="Member card, back">
  <div class="avc-trim">
    <img class="avc-back-seal" src="<?= e($seal) ?>" alt="">
    <p class="avc-back-name">Afrovanguard</p>
    <p class="avc-mission">Raising one million incorruptible African leaders by 2040.</p>
    <dl class="avc-grid">
      <div><dt>Card no.</dt><dd class="av-num"><?= e($card['card_code']) ?></dd></div>
      <div><dt>Category</dt><dd><?= e($card['category']) ?></dd></div>
      <div><dt>Issued</dt><dd class="av-num"><?= e($card['issued']) ?></dd></div>
      <div><dt>Valid</dt><dd>While membership is active</dd></div>
    </dl>
  </div>
</article>
<?php endif; ?>
