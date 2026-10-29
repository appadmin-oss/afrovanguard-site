<?php
/**
 * academy/ngv/mou.php — the MoU an applicant prints, signs with their
 * guarantor, and brings to the CACENTRE.
 *
 * The office's own text (Ngv `mou`), with this applicant's details in, the
 * version printed on the page (the desk records it), the reference as a QR the
 * desk scans to find the application, the signature blocks, and the list of
 * what to bring. Print-first: A4, no JavaScript, the browser's own Print.
 *
 * Opened only with the reference and the signed key from the applicant's own
 * link — the MoU carries their date of birth and their guarantor's name.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$app = NgvRegistration::forKey((string) ($_GET['ref'] ?? ''), (string) ($_GET['k'] ?? ''));
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
if (!$app || !in_array((string) $app['status'], ['invited', 'accepted', 'booked', 'attended'], true)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>MoU</title>'
       . '<p style="font-family:system-ui;max-width:40ch;margin:3rem auto">The MoU is available once you are invited to register. '
       . '<a href="/academy/ngv/status.php">Open your application</a>.</p>';
    exit;
}
$m     = NgvRegistration::mou($app);
$items = NgvRegistration::checklistFor($app);
$minor = NgvMember::applicantIsMinor($app);
$qr    = class_exists('NgvCard') ? NgvCard::qrSvg((string) $app['ref'], 2) : '';
$e     = 'e';
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($m['title']) ?> · <?= $e((string) $app['ref']) ?></title>
<style>
@page{size:A4;margin:16mm 16mm 18mm}
*{box-sizing:border-box}
body{margin:0;font-family:Georgia,'Times New Roman',serif;color:#111;line-height:1.5;font-size:11.5pt;background:#f2f2f2}
.sheet{background:#fff;max-width:210mm;margin:0 auto;padding:16mm}
.top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;border-bottom:2px solid #111;padding-bottom:10px}
.top h1{font-size:16pt;margin:0 0 4px}
.meta{font-family:system-ui,sans-serif;font-size:9.5pt;color:#333}
.qr{width:28mm;height:28mm;flex:none}
.qr svg{width:100%;height:100%}
.body{white-space:pre-line;margin:14px 0}
.who{display:grid;grid-template-columns:1fr 1fr;gap:6px 18px;font-family:system-ui,sans-serif;font-size:10pt;margin:10px 0 4px}
.who b{display:block;font-size:8.5pt;color:#555;font-weight:600}
.sigs{display:grid;grid-template-columns:1fr 1fr;gap:18px 24px;margin-top:20px;page-break-inside:avoid}
.sig{border-top:1px solid #111;padding-top:4px;font-family:system-ui,sans-serif;font-size:9.5pt;min-height:22mm}
.sig .l{color:#444}
.bring{font-family:system-ui,sans-serif;font-size:9.5pt;border:1px solid #bbb;border-radius:6px;padding:8px 12px;margin-top:16px;page-break-inside:avoid}
.bring ul{margin:4px 0 0;padding-left:18px}
.noprint{font-family:system-ui,sans-serif;max-width:210mm;margin:12px auto;padding:0 4px;color:#333}
@media print{body{background:#fff}.sheet{padding:0;max-width:none}.noprint{display:none}}
@media (max-width:640px){.sheet{padding:16px}.who,.sigs{grid-template-columns:1fr}}
</style>
</head>
<body>
<p class="noprint">Print this page (in your browser: Print, or Ctrl+P / ⌘P), sign it with your guarantor, and bring it to the CACENTRE.
  <a href="<?= $e(NgvRegistration::link((string) $app['ref'])) ?>">Back to your application</a></p>
<div class="sheet">
  <div class="top">
    <div>
      <h1><?= $e($m['title']) ?></h1>
      <div class="meta">Afrovanguard Academy · Reference <b><?= $e((string) $app['ref']) ?></b> · <?= $e($m['version']) ?></div>
    </div>
    <?php if ($qr !== ''): ?><div class="qr" role="img" aria-label="Reference <?= $e((string) $app['ref']) ?> as a QR code"><?= $qr ?></div><?php endif; ?>
  </div>

  <div class="who">
    <div><b>Applicant</b><?= $e((string) $app['name']) ?></div>
    <div><b>Date of birth</b><?= $e((string) ($app['dob'] ?: '—')) ?></div>
    <div><b>Guarantor</b><?= $e((string) ($app['g_name'] ?: '—')) ?></div>
    <div><b>Relationship</b><?= $e(NgvRegistration::relationshipLabel($app) ?: '—') ?></div>
  </div>

  <div class="body"><?= $e($m['body']) ?></div>

  <div class="sigs">
    <div class="sig"><span class="l">Applicant’s signature and date</span><br><?= $e((string) $app['name']) ?></div>
    <div class="sig"><span class="l">Guarantor’s signature and date</span><br><?= $e((string) ($app['g_name'] ?: '')) ?>
      <?= $minor ? '<br><span class="l">(as parent or guardian — the applicant is under 18)</span>' : '' ?></div>
    <div class="sig"><span class="l">Guarantor’s phone and ID seen (completed at the CACENTRE)</span></div>
    <div class="sig"><span class="l">For Afrovanguard Academy — name, signature, date</span></div>
  </div>

  <div class="bring"><b>Bring to the CACENTRE</b>
    <ul><?php foreach ($items as $it): ?><li><?= $e($it['label']) ?><?= $it['required'] ? '' : ' (if you can)' ?></li><?php endforeach; ?></ul>
  </div>
</div>
</body>
</html>
