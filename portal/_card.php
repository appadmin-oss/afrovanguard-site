<?php
/**
 * portal/_card.php — Membership → "Your card" (ID_CARD_PRINT §6).
 *
 * The member's card as it prints, front and back, and their photo on it:
 * "Add your photo" opens the editor (assets/site/avc-photo.js, portal/card-photo.js),
 * which only ever changes the signed-in member's own card (card/photo.php).
 * Printing is the office's (card/print.php, or NGG's ID Card Studio).
 * Here, not under Attendance & pass, because every member has a card and only
 * some have the gate. Expects $u and $isOrg.
 */
declare(strict_types=1);

$ycId = (int) $u['id'];
$ycCode = $isOrg && class_exists('MemberCards') ? MemberCards::secure($ycId) : null;
if ($ycCode !== null):
    require_once __DIR__ . '/../lib/IdCard.php';
    $card = IdCard::forMember($ycId);
    $ycHasPhoto = !empty($card['photo_url']);
?>
              <section class="pcard" id="your-card" aria-labelledby="yc-h">
                <div class="pcard-head"><h2 id="yc-h">Your card</h2><span class="pchip pchip--<?= e(['ok' => 'green', 'warn' => 'gold', 'bad' => 'red'][$card['band']['tone']] ?? 'indigo') ?>"><?= e($card['band']['label']) ?></span></div>
                <div class="pcard-body">
                  <div class="avc-holder" data-side="front">
                    <?php $avcSide = 'both'; $avcMode = 'screen'; include __DIR__ . '/../partials/id-card.php'; ?>
                    <div class="avc-actions avc-self" data-avc-self data-csrf="<?= e(av_csrf_token()) ?>">
                      <button type="button" class="avc-btn" data-avc-flip aria-pressed="false">Show back</button>
                      <button type="button" class="avc-btn avc-btn--primary" data-avc-self-pick><?= $ycHasPhoto ? 'Change your photo' : 'Add your photo' ?></button>
<?php if ($ycHasPhoto): ?>                      <button type="button" class="avc-btn" data-avc-self-clear>Remove photo</button>
<?php endif; ?>                      <a class="avc-btn" href="<?= e(MemberCards::scanUrl($ycCode)) ?>">Add to phone</a>
                      <input type="file" accept="image/jpeg,image/png,image/webp" hidden data-avc-self-file>
                    </div>
                    <p class="pcard-note" data-avc-self-msg role="status" aria-live="polite"><?= $ycHasPhoto ? 'Head and shoulders, facing the camera.' : 'No photo yet — your card shows your initials. Head and shoulders, facing the camera.' ?> The office prints your card.</p>
                  </div>
                </div>
              </section>
<?php endif; ?>
