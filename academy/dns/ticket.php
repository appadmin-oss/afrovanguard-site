<?php
/**
 * academy/dns/ticket.php — one seat claim's ticket, as a QR image or a
 * calendar entry.
 *
 *   /academy/dns/ticket.php?t=AVT-XXXXXX&f=png   the QR the email shows
 *   /academy/dns/ticket.php?t=AVT-XXXXXX&f=ics   "add to calendar"
 *   /academy/dns/ticket.php?t=AVT-XXXXXX         the door scan lands here
 *
 * WHY PNG. The site draws its other QR codes as inline SVG, which is right
 * for a web page and wrong for an email: Gmail and Outlook will not render an
 * inline SVG, and a ticket whose QR is a blank box is not a ticket. The
 * template therefore asks for an absolute https URL to a PNG, and this is it.
 *
 * WHAT IT SHOWS. A ticket code is printable and forwardable, so this page
 * tells a stranger holding it only what a door needs: that the code is real,
 * which event it is for, and the first name it was issued to. Never the email
 * address, the phone number or the rest of the claim. Unknown codes say so
 * and nothing else, and only failures are rate-limited — a ticket is meant to
 * be scanned, guessing is not.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$code = strtoupper(trim((string) ($_GET['t'] ?? '')));
$fmt  = strtolower(trim((string) ($_GET['f'] ?? '')));
$reg  = Summit::byTicket($code);

if (!$reg) {
    if (function_exists('av_rate_ok')) av_rate_ok('summit_ticket_miss', 40, 600);
    http_response_code(404);
    if ($fmt === 'png') { header('Content-Type: image/png'); readfile(AV_ROOT . '/assets/site/icon-192.png'); exit; }
    header('Content-Type: text/plain; charset=utf-8');
    echo "That ticket code is not one of ours.\n";
    exit;
}

$f    = Summit::facts();
$v    = $f['venue'];
$url  = rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/') . '/academy/dns/ticket.php?t=' . rawurlencode($code);
$name = trim((string) $reg['name']);
$first = explode(' ', $name)[0] ?: 'Guest';

/* ── the QR, as a PNG ──────────────────────────────────────────────────── */
if ($fmt === 'png') {
    header('Content-Type: image/png');
    // A ticket's QR never changes, so let the inbox and the proxy keep it.
    header('Cache-Control: public, max-age=604800');
    try {
        $o = new \chillerlan\QRCode\QROptions([
            'outputInterface'  => \chillerlan\QRCode\Output\QRGdImagePNG::class,
            'eccLevel'         => \chillerlan\QRCode\Common\EccLevel::M,
            'scale'            => 8,
            'addQuietzone'     => true,
            'quietzoneSize'    => 2,
            'outputBase64'     => false,
            'bgColor'          => [255, 255, 255],
        ]);
        echo (new \chillerlan\QRCode\QRCode($o))->render($url);
    } catch (Throwable $e) {
        error_log('[summit] ticket QR: ' . $e->getMessage());
        http_response_code(500);
    }
    exit;
}

/* ── the calendar entry ────────────────────────────────────────────────── */
if ($fmt === 'ics') {
    $esc = static fn(string $s): string => str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\;'], $s);
    $stamp = gmdate('Ymd\THis\Z');
    $start = date('Ymd', strtotime((string) $f['starts']) ?: time());
    $end   = date('Ymd', (strtotime((string) ($f['ends'] ?: $f['starts'])) ?: time()) + 86400);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="afrovanguard-' . strtolower($code) . '.ics"');
    echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Afrovanguard//Summit//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
    echo "BEGIN:VEVENT\r\nUID:" . $esc($code) . "@afrovanguard.org.ng\r\nDTSTAMP:{$stamp}\r\n";
    echo "DTSTART;VALUE=DATE:{$start}\r\nDTEND;VALUE=DATE:{$end}\r\n";
    echo 'SUMMARY:' . $esc((string) $f['name'] . ' · ' . (string) $f['edition']) . "\r\n";
    echo 'LOCATION:' . $esc($v['name'] . ', ' . $v['area'] . ', ' . $v['city']) . "\r\n";
    echo 'DESCRIPTION:' . $esc('Ticket ' . $code . '. Doors from ' . (string) $f['time_label'] . '.') . "\r\n";
    echo 'URL:' . $esc(Summit::url()) . "\r\n";
    echo "END:VEVENT\r\nEND:VCALENDAR\r\n";
    exit;
}

/* ── what a door sees when it scans ────────────────────────────────────── */
require_once AV_ROOT . '/lib/partials.php';
render_head([
    'title'  => 'Ticket ' . $code . ' — ' . (string) $f['edition'],
    'desc'   => 'An Afrovanguard summit ticket.',
    'robots' => 'noindex, nofollow',
]);
?>
<main class="av-wrap" style="max-width:560px;margin:0 auto;padding:48px 20px">
  <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--av-gold-text,#8A6406)">Valid ticket</p>
  <h1 style="margin:0 0 4px;font-family:var(--av-serif,Georgia,serif);font-size:30px;line-height:1.2"><?= e((string) $f['name']) ?></h1>
  <p style="margin:0 0 24px;color:var(--av-muted,#5F636D)"><?= e((string) $f['edition']) ?> · <?= e((string) $f['date_label']) ?> · <?= e((string) $f['time_label']) ?></p>
  <dl style="margin:0;display:grid;grid-template-columns:auto 1fr;gap:10px 18px;font-size:15px">
    <dt style="color:var(--av-muted,#5F636D)">Ticket</dt><dd style="margin:0;font-weight:700"><?= e($code) ?></dd>
    <dt style="color:var(--av-muted,#5F636D)">Issued to</dt><dd style="margin:0;font-weight:700"><?= e($first) ?></dd>
    <dt style="color:var(--av-muted,#5F636D)">Seats</dt><dd style="margin:0;font-weight:700"><?= (int) ($reg['seats'] ?: 1) ?></dd>
    <dt style="color:var(--av-muted,#5F636D)">Where</dt><dd style="margin:0"><?= e($v['name'] . ', ' . $v['area'] . ', ' . $v['city']) ?></dd>
  </dl>
</main>
<?php render_footer();
