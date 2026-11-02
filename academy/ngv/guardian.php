<?php
/**
 * academy/ngv/guardian.php — a parent or guardian confirms a child's NGV application.
 *
 * An applicant under 18 names their parent or guardian; NgvMember::askGuardian()
 * emails that person a link here. The link only SHOWS the application: agreeing
 * is a button that POSTs. A link that agreed on being opened would be agreed to
 * by whatever opens links in an inbox first — mail scanners and previewers fetch
 * every URL in a message — and the consent would then be a machine's.
 *
 * The token is single use, expires after NgvMember::GUARDIAN_LINK_DAYS, and
 * only its hash is stored. Same-origin and a per-IP limit, like register.php.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$id    = (int) ($_POST['a'] ?? $_GET['a'] ?? 0);
$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$token = preg_match('/^[a-f0-9]{40}$/', $token) ? $token : '';
$done = false; $err = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_same_origin();
    $ip = function_exists('av_client_ip') ? av_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0');
    if (function_exists('av_rate_ok') && !av_rate_ok('ngv_guardian_' . $ip, 10, 900)) {
        $err = 'Too many attempts. Please wait a little while and try again.';
    } elseif (empty($_POST['agree'])) {
        $err = 'Please tick the box to say you agree.';
    } else {
        $r = NgvMember::confirmGuardian($id, $token);
        if (!empty($r['ok'])) {
            $done = true;
            $app = $r['application'];
            if (class_exists('Events')) { try { Events::emit('ngv.guardian_confirmed', ['id' => $id]); } catch (Throwable $e) {} }
        } else {
            $err = (string) $r['error'];
        }
    }
}
$app = $done ? $app : NgvMember::guardianRequest($id, $token);
$child = $app ? (trim((string) $app['name']) ?: 'Your child') : '';
$first = $child !== '' ? explode(' ', $child)[0] : '';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Confirm an application · NextGen Vanguard</title>
<link href="<?= e(av_asset('/assets/site/fonts.css')) ?>" rel="stylesheet" />
<style>
:root{--red:#e4162b;--orange:#ff6a1a;--ink:#15120e;--line:#e7e9ee;--muted:#5f6874;--bg:#f5f6f8;--card:#fff;--grad:linear-gradient(100deg,#e4162b,#ff6a1a 55%,#ffb703);--r:16px}
*{box-sizing:border-box}
body{margin:0;font-family:'Source Sans 3',system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.55}
a{color:var(--red)}
:focus-visible{outline:3px solid var(--orange);outline-offset:2px}
.wrap{max-width:640px;margin:0 auto;padding:0 18px}
.hero{background:var(--grad);color:#fff;padding:30px 0}
.hero h1{margin:0;font-size:1.7rem;line-height:1.15}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);margin:22px 0 40px;padding:22px}
.card h2{margin:0 0 10px;font-size:1.2rem}
dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:0 0 16px}
dt{color:var(--muted)}
dd{margin:0;font-weight:600}
ul{padding-left:20px;margin:0 0 16px}
.chk{display:flex;gap:.6rem;align-items:flex-start;margin:1rem 0;font-size:1rem;line-height:1.45}
.chk input{margin-top:.25rem;width:1.15rem;height:1.15rem;flex:none}
.btn{border:0;border-radius:11px;padding:14px 22px;font:inherit;font-weight:800;cursor:pointer;background:var(--grad);color:#fff;font-size:1rem;min-height:44px}
.err{background:#fdecec;color:#c0322b;border:1px solid #f6c9c6;border-radius:11px;padding:12px 14px;font-weight:600;margin-bottom:16px}
.note{font-size:.9rem;color:var(--muted)}
</style>
</head>
<body>
<header class="hero"><div class="wrap"><h1>NextGen Vanguard · a parent’s or guardian’s agreement</h1></div></header>
<main id="main" class="wrap">
  <div class="card">
  <?php if ($done): ?>
    <div role="status">
      <h2>Thank you. Your agreement is recorded.</h2>
      <p><?= e($first) ?>’s application can now go ahead. The team will review it and contact <?= e($first) ?> directly. If you have any questions, reply to the email we sent you.</p>
    </div>
  <?php elseif (!$app): ?>
    <h2>This link has expired or has already been used</h2>
    <p>Links work for <?= (int) NgvMember::GUARDIAN_LINK_DAYS ?> days and only once. If you still need to confirm an application, ask the applicant to contact Afrovanguard Academy, and we will send you a new one.</p>
  <?php else: ?>
    <?php if ($err !== ''): ?><div class="err" role="alert"><?= e($err) ?></div><?php endif; ?>
    <h2><?= e($child) ?> has applied to NextGen Vanguard</h2>
    <p>NextGen Vanguard is Afrovanguard Academy’s skills and leadership programme in Lagos. <?= e($first) ?> named you as their parent or guardian. Because they are under 18, we need your agreement before we can offer them a place.</p>
    <dl>
      <dt>Applicant</dt><dd><?= e($child) ?></dd>
      <?php if (trim((string) $app['dob']) !== ''): ?><dt>Date of birth</dt><dd><?= e((string) $app['dob']) ?></dd><?php endif; ?>
      <?php if (trim((string) $app['track']) !== ''): ?><dt>Track</dt><dd><?= e((string) $app['track']) ?></dd><?php endif; ?>
      <?php if (trim((string) $app['plan']) !== ''): ?><dt>Plan</dt><dd><?= e((string) $app['plan']) ?></dd><?php endif; ?>
      <dt>You, as named</dt><dd><?= e(trim((string) $app['guardian_name']) ?: '—') ?></dd>
    </dl>
    <p>By agreeing, you confirm that:</p>
    <ul>
      <li>you are <?= e($first) ?>’s parent or legal guardian;</li>
      <li><?= e($first) ?> may take part in NextGen Vanguard if offered a place;</li>
      <li>Afrovanguard may keep the details in this application to run the programme, and contact you about it. They are not sold or shared for marketing (<a href="/privacy-policy/" target="_blank" rel="noopener">privacy policy</a>).</li>
    </ul>
    <?php if (trim((string) $app['plan']) !== '' && stripos((string) $app['plan'], 'full') !== false): ?>
      <p class="note">The plan chosen carries a fee. Nothing is charged until a place is offered and the fee is agreed with you and <?= e($first) ?>.</p>
    <?php endif; ?>
    <form method="post" action="/academy/ngv/guardian.php">
      <input type="hidden" name="a" value="<?= (int) $id ?>">
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <label class="chk"><input type="checkbox" name="agree" value="1" required> I am <?= e($first) ?>’s parent or guardian, and I agree.</label>
      <button class="btn" type="submit">Confirm my agreement</button>
    </form>
    <p class="note">If you do not agree, or do not know about this application, close this page. Nothing will happen, and the link stops working in <?= (int) NgvMember::GUARDIAN_LINK_DAYS ?> days.</p>
  <?php endif; ?>
  </div>
</main>
</body>
</html>
