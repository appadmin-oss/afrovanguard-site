<?php
/**
 * Member ID card, front + back. Class prefix avc-.
 * Used by: portal "Your NGV ID", q.php (holder/staff), admin print, card/print-template.php.
 *
 * Built to design/Afrovanguard Member ID Card.dc.html, which is drawn at 10px per
 * card millimetre: every size in avc-card.css is that design's px ÷ 10, times --u.
 *
 * Expects $card = IdCard::forMember($id):
 *   given, family, initials, role, tier_letter, number ('AVM-YY-NNNN'),
 *   photo_url|null, programmes (string[] max 2), member_since (YYYY),
 *   band: ['label'=>'Active member','tone'=>'ok|warn|bad|neutral'],   // from NgvCard::standing() ONLY
 *   status_date ('6 Oct 2026'), qr_svg (NgvCard::qrSvg(MemberCards::scanUrl($code))),
 *   card_code ('AVQR-XXXX-XXXX'), category ('E · Executive'), issued ('06 Oct 2026')
 * Optional: $avcMode = 'screen' (default) | 'print'; $avcSide = 'both' | 'front' | 'back'
 * NEVER add money, discipline, medical data or phone numbers. The return
 * address on the back is the organisation's (CACENTRE), never the member's.
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
      <span class="avc-brand">
        <span class="avc-brand-name">Afrovanguard</span>
        <span class="avc-strap">Ambassadors for Community, Tech &amp; Cultural Advancements</span>
      </span>
      <span class="avc-kind">Member</span>
    </header>

    <div class="avc-photo">
      <?php if (!empty($card['photo_url'])): ?>
        <img src="<?= e($card['photo_url']) ?>" alt="">
      <?php else: ?>
        <span class="avc-initials" aria-hidden="true"><?= e($card['initials']) ?></span>
      <?php endif; ?>
      <span class="avc-tier"><span class="avc-tier-letter"><?= e($card['tier_letter']) ?></span><?php if ($card['number'] !== ''): ?><span class="av-num"><?= e($card['number']) ?></span><?php endif; ?></span>
    </div>

    <div class="avc-name">
      <span class="avc-given"><?= e($card['given']) ?></span>
      <span class="avc-family"><?= e($card['family']) ?></span>
      <?php if (!empty($card['role'])): ?><span class="avc-role"><?= e($card['role']) ?></span><?php endif; ?>
    </div>

    <div class="avc-foot">
      <dl class="avc-facts">
        <?php if (!empty($card['programmes'])): ?>
          <div><dt>Programmes</dt><dd><?= implode('<br>', array_map('e', array_slice($card['programmes'], 0, 2))) ?></dd></div>
        <?php endif; ?>
        <?php if ($card['member_since'] !== ''): ?>
          <div><dt>Member since</dt><dd class="av-num"><?= e($card['member_since']) ?></dd></div>
        <?php endif; ?>
      </dl>
      <div class="avc-qr" role="img" aria-label="QR code — scan to open this card’s page"><?= $card['qr_svg'] /* trusted SVG from NgvCard::qrSvg */ ?></div>
    </div>

    <div class="avc-band avc-band--<?= e($card['band']['tone']) ?>">
      <strong><?= e($card['band']['label']) ?></strong>
      <span>Status as of <?= e($card['status_date']) ?><br>Scan for the live one</span>
    </div>
  </div>
</article>
<?php endif; ?>

<?php if ($avcSide !== 'front'): ?>
<article class="avc avc-face avc-back<?= $avcMode === 'print' ? ' is-print' : '' ?>" aria-label="Member card, back">
  <div class="avc-trim">
    <div class="avc-back-body">
      <img class="avc-back-seal" src="<?= e($seal) ?>" alt="">
      <span class="avc-back-name">Afrovanguard</span>
      <p class="avc-mission">Raising one million incorruptible African leaders by 2040.</p>
      <dl class="avc-grid">
        <div><dt>Card no.</dt><dd class="av-num"><?= e($card['card_code']) ?></dd></div>
        <div><dt>Category</dt><dd><?= e($card['category']) ?></dd></div>
        <div><dt>Issued</dt><dd class="av-num"><?= e($card['issued']) ?></dd></div>
        <div><dt>Valid</dt><dd>While membership is active</dd></div>
      </dl>
      <div class="avc-signs">
        <span><span class="avc-sign-line"></span>Issuing officer</span>
        <span><span class="avc-sign-line"></span>Holder</span>
      </div>
      <p class="avc-found">This card is the property of Afrovanguard and must be returned on request. If found, please return it to CACENTRE, Alimosho, Lagos, or write to cacentre@afrovanguard.org.ng.</p>
    </div>
    <div class="avc-strip">
      <strong>afrovanguard.org.ng</strong>
      <span>Scan the front to verify</span>
    </div>
  </div>
</article>
<?php endif; ?>
