<?php
/**
 * gate-pass.php — a member's pass for the CACENTRE gate, at /gate-pass.
 *
 * A QR to hold up to a CACENTRE desk's camera. It is minted fresh on every
 * open and lives GatePass::ttl() (twelve hours by default), so the page is
 * never cached and reloads itself before the pass it shows expires. Nothing
 * on this site trusts the pass; only the gate reads it. See lib/GatePass.php.
 *
 * A pass works without a signal: once it is on screen the desk needs nothing
 * from the phone, and the gate needs nothing from this site.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/partials.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$u = LmsAuth::user();
if (!$u) { header('Location: ' . av_login_url('/gate-pass')); exit; }

$why = !GatePass::ready() ? 'Gate passes are not switched on yet. An administrator needs to set GATE_PASS_SECRET.'
     : (!GatePass::eligible($u) ? 'Gate passes are for Afrovanguard members. Ask the office if you should have one.' : '');
$pass = $why === '' ? GatePass::mint($u) : null;
if (!$pass) http_response_code(403);
/* Reload an hour before it runs out, or at once if the pass is shorter than that. */
$reload = $pass ? max(60, $pass['exp'] - time() - 3600) : 0;
$first = e(explode(' ', trim((string) $u['name']))[0] ?: 'Member');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#111111">
<?php if ($pass): ?><meta http-equiv="refresh" content="<?= (int) $reload ?>"><?php endif; ?>
<title>CACENTRE gate pass</title>
<style>
  :root { --bg:#111; --card:#fff; --ink:#111; --muted:#666; --gold:#e8b536; }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; background:var(--bg); color:#fff; font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;
         display:grid; place-items:center; padding:16px; }
  main { width:100%; max-width:24rem; text-align:center; }
  .eyebrow { color:var(--gold); font-weight:700; letter-spacing:.08em; text-transform:uppercase; font-size:.75rem; margin:0 0 .4rem; }
  h1 { font-size:1.6rem; margin:0; }
  .role { color:#bbb; margin:.1rem 0 1rem; }
  .qr { background:var(--card); border-radius:18px; padding:14px; }
  .qr svg { display:block; width:100%; height:auto; }
  .qr svg path, .qr svg rect { fill:var(--ink); }
  .fine { color:#aaa; font-size:.85rem; margin-top:1rem; }
  a { color:var(--gold); }
</style>
</head>
<body>
<main>
  <p class="eyebrow">CACENTRE · Afrovanguard</p>
<?php if ($pass): ?>
  <h1><?= $first ?></h1>
  <p class="role"><?= e(ucfirst((string) $u['role'])) ?> · member pass</p>
  <div class="qr" role="img" aria-label="Your gate pass QR code"><?= GatePass::svg($pass['url']) ?></div>
  <p class="fine">Hold this up to the desk camera. Turn your screen brightness up if it does not read.<br>
     Good until <strong><?= e(date('D j M, H:i', $pass['exp'])) ?></strong>. This page makes a new one each time you open it.</p>
  <p class="fine">Lost your phone? Tell the office, and every pass you have been given stops working.</p>
<?php else: ?>
  <h1>No pass</h1>
  <p class="fine"><?= e($why) ?></p>
<?php endif; ?>
  <p class="fine"><a href="/portal/">Back to the portal</a></p>
</main>
</body>
</html>
