<?php
/**
 * academy/ngv/certificate.php — public NextGen Vanguard certificate + verifier.
 *
 * A shareable, print-friendly certificate reached by an unforgeable link
 * (?id=<n>&c=<code>). The code is an HMAC of the cert id (see NgvMember::
 * certCode), so the page doubles as the verification endpoint: a valid link
 * renders the certificate; a tampered or unknown one shows "not verified" and
 * reveals nothing. Data comes from the separate NGV database.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$id   = (int) ($_GET['id'] ?? 0);
$code = (string) ($_GET['c'] ?? '');
$rec  = NgvMember::certForVerify($id, $code);

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
$e = 'e';
$org = 'NextGen Vanguard · Afrovanguard Academy';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $rec ? 'Certificate · ' . $e($rec['name']) : 'Verify certificate' ?> · NextGen Vanguard</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
<style>
:root{--red:#e4162b;--orange:#ff6a1a;--gold:#ffb703;--ink:#15120e;--line:#e7e9ee;--muted:#5f6874;--bg:#f5f6f8;--grad:linear-gradient(100deg,#e4162b,#ff6a1a 55%,#ffb703)}
*{box-sizing:border-box}
body{margin:0;font-family:Montserrat,system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.5;padding:24px}
.bar{max-width:900px;margin:0 auto 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.bar .sp{flex:1}
.btn{border:0;border-radius:10px;padding:10px 18px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;background:var(--grad);color:#fff;display:inline-block}
.btn.ghost{background:#fff;border:1.5px solid var(--line);color:var(--ink)}
/* certificate */
.cert{max-width:900px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 20px 50px -30px rgba(0,0,0,.5);position:relative}
.cert::before{content:"";position:absolute;inset:0;border:14px solid transparent;border-image:var(--grad) 1;pointer-events:none}
.cert-in{padding:56px 60px;text-align:center}
.eyebrow{letter-spacing:.22em;text-transform:uppercase;font-size:.8rem;font-weight:800;color:var(--red)}
.brandline{width:64px;height:5px;background:var(--grad);border-radius:99px;margin:14px auto 26px}
.pre{color:var(--muted);margin:0 0 6px}
.name{font-family:"Playfair Display",Georgia,serif;font-size:2.6rem;font-weight:800;margin:6px 0 4px;line-height:1.1}
.for{color:var(--muted);margin:10px 0 2px}
.title{font-size:1.5rem;font-weight:800;margin:2px 0 8px}
.meta{color:var(--muted);font-size:.95rem}
.seal{width:96px;height:96px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;margin:26px auto 6px;font-weight:800;font-size:.72rem;text-align:center;line-height:1.15;box-shadow:0 8px 20px -8px rgba(228,22,43,.6)}
.foot{display:flex;justify-content:space-between;gap:20px;margin-top:30px;flex-wrap:wrap;align-items:end}
.foot .who{text-align:left}.foot .who b{display:block}
.verify{text-align:right;font-size:.8rem;color:var(--muted)}
.verify .code{font-family:ui-monospace,Menlo,monospace;font-weight:700;color:var(--ink)}
.ok-chip{display:inline-flex;align-items:center;gap:6px;background:#e6f7ec;color:#137a3a;border-radius:999px;padding:4px 12px;font-size:.8rem;font-weight:800}
/* invalid state */
.bad{max-width:560px;margin:8vh auto;background:#fff;border:1px solid var(--line);border-radius:16px;padding:38px;text-align:center}
.bad .big{font-size:3rem}
@media print{
  body{background:#fff;padding:0}
  .bar{display:none}
  .cert{box-shadow:none;border-radius:0}
}
@media(max-width:560px){.cert-in{padding:34px 22px}.name{font-size:2rem}}
</style>
</head>
<body>
<?php if (!$rec): ?>
  <div class="bad">
    <div class="big">🔒</div>
    <h1>Certificate not verified</h1>
    <p style="color:var(--muted)">This link is invalid, expired, or the certificate could not be found. If you were given a certificate, ask for a fresh link, or contact the Academy.</p>
    <p style="margin-top:18px"><a class="btn" href="/academy/ngv/">NextGen Vanguard</a></p>
  </div>
<?php else: $c = $rec['cert']; $issued = substr((string)($c['issued_on'] ?: $c['created_at']), 0, 10); ?>
  <div class="bar">
    <span class="ok-chip">✓ Verified certificate</span>
    <span class="sp"></span>
    <button class="btn ghost" onclick="window.print()" type="button">Print / Save PDF</button>
    <a class="btn ghost" href="/academy/ngv/">Programme</a>
  </div>
  <div class="cert">
    <div class="cert-in">
      <div class="eyebrow">NextGen Vanguard</div>
      <div class="brandline"></div>
      <p class="pre">This certifies that</p>
      <div class="name"><?= $e($rec['name'] ?: 'A NextGen Vanguard') ?></div>
      <p class="for">has been awarded</p>
      <div class="title"><?= $e((string)($c['title'] ?? 'Certificate of Achievement')) ?></div>
      <p class="meta">
        <?php if (!empty($rec['cohort'])): ?>Cohort <?= $e($rec['cohort']) ?> · <?php endif; ?>
        Issued <?= $e(date('j F Y', strtotime($issued) ?: time())) ?>
        <?php if (!empty($c['issued_by'])): ?> · by <?= $e((string)$c['issued_by']) ?><?php endif; ?>
      </p>
      <div class="seal">NGV<br>AFRO<br>VANGUARD</div>
      <div class="foot">
        <div class="who">
          <b>Afrovanguard Academy</b>
          <span style="color:var(--muted);font-size:.85rem">NextGen Vanguard programme</span>
        </div>
        <div class="verify">
          Verify at <b>/academy/ngv/certificate.php</b><br>
          Code <span class="code"><?= $e((string)$c['code']) ?></span>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
</body>
</html>
