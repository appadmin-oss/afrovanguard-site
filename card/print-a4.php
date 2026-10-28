<?php
/**
 * card/print-a4.php — ten cards to an A4 sheet, for a staff print run.
 *
 * ── WHY THE BACK IS MIRRORED ────────────────────────────────────────────────
 * Page 1 is ten fronts, page 2 is ten backs. A duplex printer flipping on the
 * LONG edge turns the sheet left-to-right, so a back laid out in the same
 * column order comes out behind the wrong card — every card on the sheet
 * carries somebody else's card number. Reversing each ROW on page 2 is what
 * makes column 1 land behind column 2.
 *
 * Not a public route; card/print.php includes it. Expects $card and $bleed.
 */
$bleed = $bleed ?? true;
$avcMode = 'print';

/* 2 × 5 on A4 (210 × 297mm). Ten cards at 54 × 85.6mm trim need 108 × 428mm
   laid flat, so the grid is 2 across and 5 down with the sheet's margins
   taking the rest. */
$cols = 2;
$rows = 5;
$cardW = 54.0;
$cardH = 85.6;
$gapX  = 6.0;
$gapY  = 2.0;
$offX  = (210.0 - ($cols * $cardW + ($cols - 1) * $gapX)) / 2;
$offY  = (297.0 - ($rows * $cardH + ($rows - 1) * $gapY)) / 2;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<title>Afrovanguard cards <?= e($card['number']) ?></title>
<link rel="stylesheet" href="/assets/site/av-tokens.css">
<link rel="stylesheet" href="/assets/site/avc-card.css">
<style>
@page{size:A4;margin:0}
html,body{margin:0;background:var(--av-white)}
.avc-a4{position:relative;width:210mm;height:297mm;overflow:hidden;page-break-after:always}
.avc-a4:last-child{page-break-after:auto}
.avc-a4 .avc-face{position:absolute;margin:0}
/* Crop marks at each card's trim corners, 0.25pt and 3mm long (§5). */
.avc-a4 .avc-crop{position:absolute;background:var(--av-registration)}
.avc-a4 .avc-crop.v{width:.25pt;height:3mm}
.avc-a4 .avc-crop.h{width:3mm;height:.25pt}
</style>
</head><body>
<?php foreach (['front', 'back'] as $pageSide): ?>
  <section class="avc-a4">
  <?php for ($r = 0; $r < $rows; $r++): for ($c = 0; $c < $cols; $c++): ?>
    <?php
      /* The mirror. On the back page the columns run right-to-left so a
         long-edge duplex flip puts each back behind its own front. */
      $col = $pageSide === 'back' ? ($cols - 1 - $c) : $c;
      $x = $offX + $col * ($cardW + $gapX);
      $y = $offY + $r * ($cardH + $gapY);
      $avcSide = $pageSide;
    ?>
    <div class="avc-face-slot" style="position:absolute;left:<?= $x ?>mm;top:<?= $y ?>mm;width:<?= $cardW ?>mm;height:<?= $cardH ?>mm">
      <?php include __DIR__ . '/../partials/id-card.php'; ?>
    </div>
    <?php foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$cx, $cy]): ?>
      <?php $mx = $x + $cx * $cardW; $my = $y + $cy * $cardH; ?>
      <span class="avc-crop v" style="left:<?= $mx ?>mm;top:<?= $my + ($cy ? 1 : -4) ?>mm"></span>
      <span class="avc-crop h" style="left:<?= $mx + ($cx ? 1 : -4) ?>mm;top:<?= $my ?>mm"></span>
    <?php endforeach; ?>
  <?php endfor; endfor; ?>
  </section>
<?php endforeach; ?>
</body></html>
