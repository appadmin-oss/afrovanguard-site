<?php
/**
 * q.php — the page behind a member card's QR, at /q/AVQR-….
 *
 * The two-way scan NGG's cards have. The card's QR carries this URL
 * (MemberCards::scanUrl), so:
 *
 *   · a CACENTRE desk scanner reads the AVQR- code out of it and checks the
 *     member in — the gate never opens this page;
 *   · an ordinary phone camera opens it here, and gets the card with its LIVE
 *     standing (lib/NgvCard.php), which a printed card cannot have.
 *
 * Who is looking decides what is shown:
 *
 *   a stranger     the card with the first name and the public standing only
 *                  (current member, or not). Never money, never discipline:
 *                  whoever found the card in a street is owed nothing more.
 *   the holder     their own card with their own standing, and the way to the
 *                  portal and the full-screen gate pass.
 *   NGV staff      the full name, the whole standing in words, what the gate
 *                  will say, and the way to their record.
 *
 * An unknown code says so and nothing else, and only failures are rate
 * limited — a card is meant to be scanned by strangers; guessing is not.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/partials.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$raw = (string) ($_GET['c'] ?? '');
$code = MemberCards::tokenFrom($raw);
$hit = $code !== '' ? MemberCards::lookup($code) : null;
$holder = $hit && !$hit['void'] ? NgvCard::user((int) $hit['member_id']) : null;

if (!$holder) {
    if (function_exists('av_rate_ok') && !av_rate_ok('avq_card', 20, 600)) http_response_code(429);
    else http_response_code($hit && $hit['void'] ? 410 : 404);
}

$viewer = LmsAuth::user();
$role = function_exists('av_admin_role') ? av_admin_role() : '';
$isStaff = in_array($role, ['admin', 'superadmin'], true) || LmsAuth::canManageMembers($viewer);
$isHolder = $holder && $viewer && (int) $viewer['id'] === (int) $holder['id'];
$standing = $holder ? NgvCard::standing($holder) : null;
$full = $isStaff || $isHolder;
$first = $holder ? (explode(' ', trim((string) $holder['name']))[0] ?: 'Member') : '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $holder ? e($full ? (string) $holder['name'] : $first) . ' · NextGen Vanguard card' : 'Member card' ?> · Afrovanguard</title>
<style>
:root{--ink:#15120e;--muted:#4a4f5a;--line:#e3e5ea;--bg:#f5f6f8}
*{box-sizing:border-box}
body{margin:0;font-family:Montserrat,system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.5;padding:24px 16px}
main{max-width:460px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.box{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px}
.box h2{margin:0 0 6px;font-size:16px}
.box p{margin:4px 0;color:var(--muted)}
.kv{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;margin:8px 0 0}
.kv dt{color:var(--muted);font-size:13px}.kv dd{margin:0;font-weight:600}
.gate-ok{color:#0f6b34;font-weight:700}.gate-no{color:#a3121f;font-weight:700}
.links{display:flex;gap:10px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;min-height:44px;padding:0 16px;border-radius:10px;font-weight:700;text-decoration:none;background:#15120e;color:#fff}
.btn.ghost{background:#fff;color:var(--ink);border:1.5px solid var(--line)}
.miss{text-align:center}
button.btn{border:0;font:inherit;font-weight:700;cursor:pointer}
/* Printing from here is printing the card: ID-1, nothing else on the page. */
@media print{body{background:#fff;padding:0}.box,.links{display:none}main{max-width:none}}
</style>
</head>
<body>
<main>
<?php if (!$holder): ?>
  <section class="box miss" role="alert">
    <h2><?= $hit && $hit['void'] ? 'This card has been replaced' : 'Not an Afrovanguard member card' ?></h2>
    <p><?= $hit && $hit['void'] ? 'Its holder has a newer card. This one no longer opens anything.' : 'This code is not one of Afrovanguard’s cards.' ?></p>
  </section>
<?php else: ?>
  <!-- card: rebuilt in partials/id-card.php -->
<?php if ($full): ?>
  <section class="box" aria-labelledby="st-h">
    <h2 id="st-h"><?= $isHolder && !$isStaff ? 'Your standing' : 'Standing' ?>: <?= e($standing['label']) ?></h2>
    <p><?= e($standing['detail']) ?></p>
    <dl class="kv">
      <dt>CACENTRE gate</dt><dd class="<?= $standing['gate'] ? 'gate-ok' : 'gate-no' ?>"><?= $standing['gate'] ? ($isHolder && !$isStaff ? 'Lets you in' : 'Lets them in') : e($standing['gateWhy']) ?></dd>
<?php if ($isStaff): ?>      <dt>Name</dt><dd><?= e((string) $holder['name']) ?></dd>
      <dt>Card</dt><dd><?= e($code) ?></dd>
<?php endif; ?>
    </dl>
  </section>
  <nav class="links" aria-label="Next">
<?php if ($isHolder): ?>    <a class="btn" href="/gate-pass">My gate pass</a>
    <a class="btn ghost" href="/portal/#attendance">My portal</a>
<?php endif; ?>
<?php if ($isStaff): ?>    <button type="button" class="btn" onclick="window.print()">Print card</button>
    <a class="btn ghost" href="/academy/ngv/attendance.php">NGV attendance</a>
<?php endif; ?>
  </nav>
<?php else: ?>
  <section class="box">
    <p>This is <?= e($first) ?>’s Afrovanguard member card. At the CACENTRE gate it is scanned at the desk; nothing here opens a door.</p>
    <p><a href="<?= e(av_login_url('/q/' . $code)) ?>">Is this your card? Sign in</a> to see your full standing.</p>
  </section>
<?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
