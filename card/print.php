<?php
/**
 * card/print.php — the member card as a file a printer can use.
 *
 * ── WHY dompdf AND NOT HEADLESS CHROME ──────────────────────────────────────
 * ID_CARD_PRINT §5 offers spatie/browsershot or dompdf/dompdf, and browsershot
 * renders more faithfully. It is still the wrong choice here: DEPLOY.md §126
 * lists "Shared cPanel (no SSH)" as a supported target, and a shared host has
 * no Chrome binary and usually no proc_open either. A renderer that only works
 * on the Docker path would make this feature exist on the documentation and
 * not on the site.
 *
 * dompdf is pure PHP, embeds and subsets fonts, keeps the QR as vector (it is
 * an SVG in the markup), and honours @page size. It renders the SAME partial
 * the screen card uses, which is the point §5 is really making — the card must
 * not drift from itself. TCPDF, named in CHECKLIST G-04, is the PDF DRAWING
 * API §5 explicitly forbids for that exact reason, so it is not used; the
 * contradiction between those two documents is listed in the PR.
 *
 * ── WHAT IS CHECKED, IN ORDER ───────────────────────────────────────────────
 * Access, then rate limit, then photo size, then cache, then render. Access
 * first because an endpoint that rate-limits before it authorises tells a
 * stranger how often other people are printing.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/LmsAuth.php';
require_once __DIR__ . '/../lib/MemberCards.php';
require_once __DIR__ . '/../lib/NgvCard.php';
require_once __DIR__ . '/../lib/IdCard.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_render.php';

/* ── Who is asking ──────────────────────────────────────────────────────── */

$me   = LmsAuth::user();
$meId = (int) ($me['id'] ?? 0);
if ($meId <= 0) { card_refuse(403, 'Sign in to download your card.'); }

$wanted  = (int) ($_GET['member'] ?? 0);
$isStaff = LmsAuth::canManageMembers($me);
$target  = $wanted > 0 ? $wanted : $meId;

/* The holder may have their own; staff may have anybody's. Everyone else is
   refused — and refused the SAME WAY whether or not the member exists, so the
   endpoint cannot be used to test which ids are real. */
if ($target !== $meId && !$isStaff) { card_refuse(403, 'That is not your card.'); }

/* ── Rate limit ─────────────────────────────────────────────────────────── */

if (!card_rate_ok($meId)) {
    card_refuse(429, 'You have asked for a lot of cards in the last hour. Try again later.');
}

/* ── What was asked for ─────────────────────────────────────────────────── */

$format = in_array(($_GET['format'] ?? 'pdf'), ['pdf', 'png'], true) ? $_GET['format'] : 'pdf';
$layout = in_array(($_GET['layout'] ?? 'single'), ['single', 'a4'], true) ? $_GET['layout'] : 'single';
$bleed  = ($_GET['bleed'] ?? '1') !== '0';

/* A4 imposition is a staff print run, not something a member needs. */
if ($layout === 'a4' && !$isStaff) { card_refuse(403, 'That layout is for staff print runs.'); }

$card = IdCard::forMember($target);
if (trim((string) $card['card_code']) === '') {
    card_refuse(404, 'No card yet — ask the NGV office.');
}

/* ── The photo has to be big enough to print ────────────────────────────── */

$photo = IdCard::photoCheck($card['photo_url'] ?? null);
if (!$photo['ok'] && $photo['why'] !== '') { card_refuse(422, $photo['why']); }

/* ── Cache ──────────────────────────────────────────────────────────────── */

$key  = IdCard::cacheKey($target) . '-' . $format . '-' . $layout . '-' . ($bleed ? 'b1' : 'b0');
$file = card_cache_path($key, $format === 'png' ? 'zip' : 'pdf');
$name = 'afrovanguard-card-' . ($card['number'] !== '' ? $card['number'] : $card['card_code']);

if (is_file($file) && (time() - (int) @filemtime($file)) < 86400) {
    card_send($file, $name . ($format === 'png' ? '.zip' : '.pdf'),
              $format === 'png' ? 'application/zip' : 'application/pdf');
}

/* ── Render ─────────────────────────────────────────────────────────────── */

if ($format === 'png') {
    /* NOT MET, and said rather than faked. §5 wants 300dpi rasters of both
       faces in a zip. dompdf emits vector PDF; turning that into a raster
       needs Imagick (absent here) or Ghostscript (absent on shared hosting).
       Handing back a low-resolution GD approximation would be worse than
       refusing: somebody would send it to a printer believing it was the
       300dpi file the page promised. */
    card_refuse(501, 'PNG download is not available on this server yet. '
                   . 'The PDF is print-ready — any card printer can use it.');
}

try {
    $html = card_html($card, $layout, $bleed);
    $pdf  = card_pdf($html, $layout);
} catch (Throwable $e) {
    error_log('[card/print] ' . $e->getMessage());
    card_refuse(500, 'We couldn’t make the file. Try again in a minute.');
}

@file_put_contents($file, $pdf);
card_send_body($pdf, $name . '.pdf', 'application/pdf');
