<?php
/**
 * portal/views/reading.php — the 24-book challenge for a member or a
 * volunteer.
 *
 * A NextGen Vanguard reads inside their programme, so their copy of this card
 * sits in the Programme view. Everybody else who may read (a verified member,
 * a volunteer) gets it here, on its own page — and it is the SAME card,
 * portal/_reading-card.php, because two drawings of the same shelf drift
 * within a season.
 *
 * Who may read is NgvReading::mayRead(); lib/PortalNav.php decides whether
 * this section is listed at all.
 */
declare(strict_types=1);
$rdKind     = NgvReading::readerKind((int) $u['id']);
$rdProgress = NgvReading::progress((int) $u['id']);
$rdShelf    = NgvReading::shelf((int) $u['id']);
?>
      <section class="pview avp-view avp-ngv" id="view-reading" data-view="reading" hidden aria-labelledby="avpRdH">
        <div class="avp-view-head"><h1 class="avp-h1" id="avpRdH">Reading</h1></div>
        <div class="avp-stack avng">
<?php require AV_ROOT . '/portal/_reading-card.php'; ?>
        </div>
      </section>
