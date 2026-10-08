<?php
/**
 * Print sheet rendered by card/print.php through the headless renderer (ID_CARD_PRINT §5).
 * Not a public route. card/print.php does auth, rate limit, photo-size check, caching,
 * then renders this file to PDF (page 68 × 99.6mm) and sets the Subject metadata.
 * Expects $card (see partials/id-card.php) and $bleed (bool, default true).
 */
$bleed = $bleed ?? true;
$avcMode = 'print';
// crop mark positions: trim edges at 7mm and 61mm (x), 7mm and 92.6mm (y)
$marks = [];
foreach ([7, 61] as $x) foreach ([7, 92.6] as $y) {
  $marks[] = ['v', $x, $y < 50 ? 0 : 96.6];   // vertical tick above/below
  $marks[] = ['h', $x < 30 ? 0 : 65, $y];     // horizontal tick left/right
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<title>Afrovanguard card <?= e($card['number']) ?></title>
<link rel="stylesheet" href="/assets/site/fonts.css">
<link rel="stylesheet" href="/assets/site/av-tokens.css">
<link rel="stylesheet" href="/assets/site/avc-card.css">
<style>
@page{size:68mm 99.6mm;margin:0}
html,body{margin:0;background:var(--av-white)}
/* MODIFIED FROM THE DROP-IN, and listed in the PR (README §1.2).
   Without a base family on the document, any element avc-card.css does not
   name — a <strong>, a <dt> — fell through to dompdf's core Times, which is
   never embedded. CARD-07 requires every font embedded and subset. */
html,body{font-family:var(--av-sans)}
<?php if (!$bleed): ?>.avc-face.is-print{--bleed:0mm}.avc-sheet>.avc-face{left:7mm;top:7mm}<?php endif; ?>
</style>
</head><body>
<?php foreach (['front', 'back'] as $avcSide): ?>
  <section class="avc-sheet">
    <?php include __DIR__ . '/../partials/id-card.php'; ?>
    <?php foreach ($marks as [$o, $x, $y]): ?>
      <span class="avc-crop <?= $o ?>" style="left:<?= $x ?>mm;top:<?= $y ?>mm"></span>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>
</body></html>
