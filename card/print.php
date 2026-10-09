<?php
/**
 * card/print.php — print one member's card. Staff only.
 *
 * ── WHO MAY PRINT ───────────────────────────────────────────────────────────
 * Printing is an office job (owner, 2026-10-09): an admin of this site
 * (AdminRoles 'admin' or above), from the member desk or a scanned card's
 * page — ?member=<id>. A member cannot print their own card. NextGen Genius
 * prints Afrovanguard cards in its own ID Card Studio, from the same partial,
 * through integrations/ngg-cards.php.
 *
 * ── WHY THE FILES ARE MADE IN THE BROWSER ───────────────────────────────────
 * The site runs on shared hosting: no shell, no Chrome, no Ghostscript. A PHP
 * PDF library re-implements CSS and could not draw this design (no flexbox,
 * grid, gradients, shadows or clipped corners), so the print came out unlike
 * the card. Instead the admin's own browser lays out the SAME partial the
 * screen shows, at its true millimetre size with 3 mm of real bleed, and
 * snapDOM rasterises it through the browser's own engine — NGG's ID Card
 * Studio does exactly this, chosen there by measurement (0.0% of pixels off
 * against Chromium's own screenshot). jsPDF assembles the PDF; JSZip the PNGs.
 * All three are vendored in assets/vendor/card/, byte-identical to NGG's.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/AdminRoles.php';
require_once __DIR__ . '/../lib/MemberCards.php';
require_once __DIR__ . '/../lib/NgvCard.php';
require_once __DIR__ . '/../lib/IdCard.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

function card_print_refuse(int $status, string $why): never
{
    http_response_code($status);
    echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex">'
       . '<title>Card printing · Afrovanguard</title><p style="font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">'
       . e($why) . '</p>';
    exit;
}

/* ── Who is asking ──────────────────────────────────────────────────────── */

if (!AdminRoles::can('admin')) card_print_refuse(403, 'Card printing is for Afrovanguard staff. Open it from the member desk in the admin.');
$target = (int) ($_GET['member'] ?? 0);
if ($target <= 0) card_print_refuse(404, 'Choose a member on the member desk first.');
$actor = av_admin_actor();

if (!av_rate_ok('card_print', 60, 3600)) card_print_refuse(429, 'A lot of cards have been opened for printing from here in the last hour. Try again later.');

$holder = NgvCard::user($target);
if (!$holder) card_print_refuse(404, 'No such member.');

$card  = IdCard::forMember($target);
$photo = IdCard::photoCheck($card['photo_url'] ?? null);
$noCard = trim((string) $card['card_code']) === '';
/* Every print opening is on the record: a card is an identity document. */
if (class_exists('AdminAudit')) {
    try { AdminAudit::log('members', 'card_print_opened', (string) $target, (string) ($card['card_code'] ?: 'no card'), null, $actor); }
    catch (Throwable $e) { /* the audit table is optional; printing is not */ }
}
$name = 'afrovanguard-card-' . preg_replace('/[^A-Za-z0-9-]/', '', $card['number'] !== '' ? $card['number'] : ($card['card_code'] ?: 'member-' . $target));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Print card · <?= e((string) $holder['name']) ?> · Afrovanguard</title>
<link rel="stylesheet" href="/assets/site/fonts.css">
<link rel="stylesheet" href="/assets/site/av-tokens.css">
<link rel="stylesheet" href="/assets/site/avc-card.css">
<link rel="stylesheet" href="/assets/site/avc-print.css">
</head>
<body class="avcp">
<main class="avcp-main" id="main">
  <header class="avcp-head">
    <p class="avcp-eyebrow">Member card · print</p>
    <h1><?= e((string) $holder['name']) ?></h1>
    <p class="avcp-sub av-num"><?= e($card['number'] !== '' ? $card['number'] . ' · ' : '') ?><?= e($card['card_code'] ?: 'No card yet') ?> · <?= e($card['band']['label']) ?></p>
  </header>

<?php if ($noCard): ?>
  <p class="avcp-msg" role="status">No card yet — issue one from the member desk, then print it.</p>
<?php else: ?>
  <div class="avcp-cards" aria-label="The card as it will print">
    <?php $avcSide = 'both'; include __DIR__ . '/../partials/id-card.php'; ?>
  </div>
<?php if (empty($card['photo_url'])): ?>
  <p class="avcp-msg" role="status">No photo on this card — it prints with the member’s initials.</p>
<?php endif; ?>
<?php if (!$photo['ok'] && $photo['why'] !== ''): ?>
  <p class="avcp-msg avcp-msg--bad" role="alert"><?= e($photo['why']) ?></p>
<?php endif; ?>

  <div class="avcp-actions" data-avcp data-name="<?= e($name) ?>" data-code="<?= e($card['card_code']) ?>">
    <button type="button" class="avc-btn avc-btn--primary" data-avcp-make="pdf"<?= $photo['ok'] || $photo['why'] === '' ? '' : ' disabled' ?>>Download for printing (PDF)</button>
    <button type="button" class="avc-btn" data-avcp-make="png"<?= $photo['ok'] || $photo['why'] === '' ? '' : ' disabled' ?>>Download images (PNG)</button>
    <button type="button" class="avc-btn" data-avcp-make="a4"<?= $photo['ok'] || $photo['why'] === '' ? '' : ' disabled' ?>>A4 sheet, 10 cards (PDF)</button>
  </div>
  <p class="avcp-note">Printed at 54 × 85.6 mm with 3 mm bleed. Any card printer can use this file.</p>
  <p class="avcp-note">A4 sheet: print double-sided, flip on the long edge, then cut on the marks.</p>
  <div class="avcp-status" role="status" aria-live="polite" data-avcp-status></div>

  <!-- The farm: the same partial at its true size with real bleed, off screen. -->
  <div class="avcp-farm" aria-hidden="true">
    <?php $avcMode = 'print'; $avcSide = 'front'; include __DIR__ . '/../partials/id-card.php'; ?>
    <?php $avcSide = 'back'; include __DIR__ . '/../partials/id-card.php'; ?>
  </div>
<?php endif; ?>
  <noscript><p class="avcp-msg avcp-msg--bad">The print files are made in this browser. Turn on JavaScript to download them.</p></noscript>
</main>
<script src="/assets/site/avc-print.js" defer></script>
</body>
</html>
