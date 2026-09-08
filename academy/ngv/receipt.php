<?php
/**
 * academy/ngv/receipt.php — public NextGen Vanguard receipt + verifier.
 *
 * A print-friendly receipt reached by an unforgeable link (?id=<n>&c=<code>).
 * The code is an HMAC of the payment id (see NgvLedger::receiptCode), so this
 * page doubles as the verification endpoint: a valid link renders the receipt, a
 * tampered or unknown one shows "not verified" and reveals nothing — not even
 * whether that id exists. Same posture as certificate.php, deliberately.
 *
 * WHAT IT DELIBERATELY DOES NOT SHOW. One payment: the amount, what it was for,
 * when, and to whom. Not the account balance, not what is outstanding, not the
 * other payments, not any fine. A receipt is evidence of one transaction, and
 * anybody who can see the link can see everything on the page — a guardian, a
 * bank, a landlord being shown proof of enrolment. What somebody still owes is
 * nobody's business but theirs.
 *
 * A CANCELLED receipt still renders, loudly marked. Somebody producing a voided
 * receipt needs an answer, and "not verified" would be the wrong one: the
 * payment was real, and then it was reversed.
 *
 * No PDF library — none is needed and none would install cleanly on shared
 * cPanel. The print stylesheet is the deliverable: Ctrl-P gives a clean A4 page
 * or a PDF, on any device, with no dependency.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$id   = (int) ($_GET['id'] ?? 0);
$code = (string) ($_GET['c'] ?? '');
$r    = NgvLedger::receiptForVerify($id, $code);

/* The code is 40 bits of HMAC, which is a long way from guessable — but this
 * endpoint is unauthenticated and the thing behind it is somebody's financial
 * record, so guessing is also rate-limited per IP.
 *
 * Only FAILURES consume a token. A valid code is never throttled: reloading,
 * printing, and opening the same receipt on a phone and a laptop are all normal,
 * and locking somebody out of their own proof of payment to slow an attacker
 * down would be protecting the wrong person. */
$throttled = false;
if (!$r && function_exists('av_rate_ok') && !av_rate_ok('ngv_receipt', 20, 600)) {
    $throttled = true;
    http_response_code(429);
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
/* A receipt is one person's financial record. It must never sit in a shared
 * cache, and a bare link is enough to read it — so nothing about it is stored
 * anywhere it could be served to somebody else. */
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$e = 'e';

$org  = 'Afrovanguard Academy';
$prog = 'NextGen Vanguard';
$payTo = class_exists('Ngv') ? (string) (Ngv::get()['schedule']['payment'] ?? '') : '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $r ? 'Receipt ' . $e($r['no']) : 'Verify receipt' ?> · NextGen Vanguard</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
/* Same brand tokens as certificate.php — a receipt and a certificate from the
   same programme should not look like they came from two different systems. */
:root{--red:#e4162b;--orange:#ff6a1a;--gold:#ffb703;--ink:#15120e;--line:#e7e9ee;--muted:#5f6874;
      --bg:#f5f6f8;--green:#137a3a;--green-soft:#e6f7ec;
      --void:#c0322b;--void-soft:#fdecec;--void-bd:#f0b4b0;
      --grad:linear-gradient(100deg,#e4162b,#ff6a1a 55%,#ffb703)}
*{box-sizing:border-box}
body{margin:0;font-family:Montserrat,system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.5;padding:24px}
.bar{max-width:720px;margin:0 auto 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.bar .sp{flex:1}
.btn{border:0;border-radius:10px;padding:10px 18px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;background:var(--grad);color:#fff;display:inline-block}
.btn.ghost{background:#fff;border:1.5px solid var(--line);color:var(--ink)}
.btn:focus-visible,a:focus-visible{outline:3px solid var(--orange);outline-offset:2px}

.doc{max-width:720px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 20px 50px -30px rgba(0,0,0,.5)}
.doc-top{background:var(--ink);color:#fff;padding:22px 30px;display:flex;gap:14px;align-items:baseline;flex-wrap:wrap;border-bottom:4px solid var(--gold)}
.doc-top b{font-size:1.05rem;font-weight:800}
.doc-top .gold{color:var(--gold)}
.doc-top .sp{flex:1}
.doc-top .no{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.9rem;letter-spacing:.02em}
.doc-in{padding:30px}

.amount{font-size:2.6rem;font-weight:800;letter-spacing:-.02em;line-height:1.05}
.amount-sub{color:var(--muted);font-size:.9rem;margin-top:2px}
.chip{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:4px 12px;font-size:.78rem;font-weight:800}
.chip-ok{background:var(--green-soft);color:var(--green)}
.chip-void{background:var(--void-soft);color:var(--void)}

dl{display:grid;grid-template-columns:auto 1fr;gap:9px 20px;margin:24px 0 0;font-size:.94rem}
dt{color:var(--muted)}
dd{margin:0;font-weight:700;overflow-wrap:anywhere}

.void-note{margin:20px 0 0;border:1.5px solid var(--void-bd);background:var(--void-soft);border-radius:11px;padding:13px 15px;font-size:.9rem}
.void-note b{display:block;margin-bottom:3px}

.foot{margin-top:26px;padding-top:18px;border-top:1px solid var(--line);display:flex;gap:20px;flex-wrap:wrap;align-items:end}
.foot .who b{display:block}
.foot .sp{flex:1}
.verify{text-align:right;font-size:.78rem;color:var(--muted);line-height:1.6}
.verify .code{font-family:ui-monospace,Menlo,Consolas,monospace;font-weight:700;color:var(--ink)}
.paidto{margin-top:14px;font-size:.8rem;color:var(--muted)}

.bad{max-width:560px;margin:8vh auto;background:#fff;border:1px solid var(--line);border-radius:16px;padding:38px;text-align:center}
.bad .big{font-size:3rem;line-height:1}

@media print{
  body{background:#fff;padding:0;font-size:12pt}
  .bar{display:none}
  .doc{box-shadow:none;border-radius:0;max-width:none}
  .doc-top{background:#fff;color:var(--ink);border-bottom:2px solid var(--ink)}
  .doc-top .gold{color:var(--ink)}
  .chip-ok{border:1px solid var(--green)}
  a[href]:after{content:""}
}
@media(max-width:560px){.doc-in{padding:22px}.amount{font-size:2.1rem}dl{grid-template-columns:1fr;gap:2px 0}dd{margin-bottom:8px}}
</style>
</head>
<body>
<?php if (!$r): ?>
  <div class="bad">
    <div class="big">🔒</div>
    <h1>Receipt not verified</h1>
    <?php if ($throttled): ?>
      <p style="color:var(--muted)">Too many attempts from this connection. Wait a few minutes and try the link from your
        email again — it always works.</p>
    <?php else: ?>
      <p style="color:var(--muted)">This link is invalid, incomplete, or the receipt could not be found. If you were given a
        receipt, ask your track lead for a fresh link — the one in your email always works.</p>
    <?php endif; ?>
    <p style="margin-top:18px"><a class="btn" href="/academy/ngv/">NextGen Vanguard</a></p>
  </div>
<?php else: ?>
  <div class="bar">
    <?php if ($r['void']): ?>
      <span class="chip chip-void">✕ Cancelled</span>
    <?php else: ?>
      <span class="chip chip-ok">✓ Verified receipt</span>
    <?php endif; ?>
    <span class="sp"></span>
    <button class="btn ghost" onclick="window.print()" type="button">Print / Save PDF</button>
    <a class="btn ghost" href="/academy/ngv/dashboard.php#account">My account</a>
  </div>

  <div class="doc">
    <div class="doc-top">
      <b><?= $e($org) ?> <span class="gold"><?= $e($prog) ?></span></b>
      <span class="sp"></span>
      <span class="no"><?= $e($r['no']) ?></span>
    </div>
    <div class="doc-in">
      <div class="amount">₦<?= number_format((int) $r['amount']) ?></div>
      <div class="amount-sub">
        <?= $r['void'] ? 'was received, and has since been cancelled' : 'received with thanks' ?>
      </div>

      <?php if ($r['void']): ?>
        <div class="void-note">
          <b>This receipt has been cancelled.</b>
          <?php if ($r['voidReason'] !== ''): ?>
            This payment is no longer on the account — <?= $e($r['voidReason']) ?>.
          <?php else: ?>
            This payment is no longer on the account. Usually that means it was entered twice, or recorded against the
            wrong person.
          <?php endif; ?>
          If you made this payment and no corrected receipt has reached you, speak to your track lead.
        </div>
      <?php endif; ?>

      <dl>
        <dt>Received from</dt><dd><?= $e($r['name'] !== '' ? $r['name'] : 'NextGen Vanguard participant') ?><?php
            if ($r['cohort'] !== ''): ?> <span style="font-weight:400;color:var(--muted)">· cohort <?= $e($r['cohort']) ?></span><?php endif; ?></dd>
        <dt>For</dt><dd><?= $e($r['lineLabel']) ?><?= $r['period'] !== '' ? ' · ' . $e($r['period']) : '' ?></dd>
        <dt>Paid on</dt><dd><?= $e(date('j F Y', strtotime($r['paidOn']) ?: time())) ?></dd>
        <?php if ($r['method'] !== ''): ?><dt>How</dt><dd><?= $e($r['method']) ?></dd><?php endif; ?>
        <?php if ($r['reference'] !== ''): ?><dt>Reference</dt><dd><?= $e($r['reference']) ?></dd><?php endif; ?>
        <?php if ($r['note'] !== ''): ?><dt>Note</dt><dd style="font-weight:400"><?= $e($r['note']) ?></dd><?php endif; ?>
      </dl>

      <div class="foot">
        <div class="who">
          <b><?= $e($org) ?></b>
          <span style="color:var(--muted);font-size:.85rem"><?= $e($prog) ?> programme</span>
        </div>
        <span class="sp"></span>
        <div class="verify">
          Check this receipt at <b>/academy/ngv/receipt.php</b><br>
          Code <span class="code"><?= $e($r['code']) ?></span>
        </div>
      </div>
      <?php if ($payTo !== ''): ?><p class="paidto"><?= $e($payTo) ?></p><?php endif; ?>
    </div>
  </div>
<?php endif; ?>
</body>
</html>
