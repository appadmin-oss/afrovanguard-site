<?php
/**
 * academy/ngv/status.php — an applicant's own application page.
 *
 * Opened from the link in their emails (reference + a signed key), or by
 * typing the reference and the email they applied with. It says where they
 * stand and what to do next, and once they are invited it is where they print
 * the MoU, see what to bring, and book (or move) the visit at which they finish
 * registering. lib/NgvRegistration.php holds the rules; this only shows them.
 *
 * Works with JavaScript off: plain forms that post. Same-origin and a per-IP
 * limit, as register.php. A wrong lookup says the same thing whether the
 * reference exists or not, so the page cannot be used to test references.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$ref = is_string($_REQUEST['ref'] ?? null) ? (string) $_REQUEST['ref'] : '';
$key = is_string($_REQUEST['k'] ?? null) ? (string) $_REQUEST['k'] : '';
$app = $key !== '' ? NgvRegistration::forKey($ref, $key) : null;
$err = ''; $msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_same_origin();
    $ip = function_exists('av_client_ip') ? av_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0');
    $do = (string) ($_POST['do'] ?? '');
    if (function_exists('av_rate_ok') && !av_rate_ok('ngv_status_' . $ip, 30, 900)) {
        $err = 'Too many attempts. Please wait a little while and try again.';
    } elseif ($do === 'find') {
        $found = NgvRegistration::forApplicant($ref, (string) ($_POST['email'] ?? ''));
        if ($found) { header('Location: ' . NgvRegistration::link((string) $found['ref']), true, 303); exit; }
        $err = 'We could not find an application with that reference and email. Check both, exactly as you gave them.';
    } elseif ($app && $do === 'book') {
        [$d, $t] = array_pad(explode('|', (string) ($_POST['slot'] ?? ''), 2), 2, '');
        $r = NgvRegistration::book((int) $app['id'], (string) ($_POST['centre'] ?? ''), $d, $t);
        if (!empty($r['ok'])) { header('Location: ' . NgvRegistration::link((string) $app['ref']) . '&booked=1', true, 303); exit; }
        $err = (string) $r['error'];
    } elseif ($app && $do === 'cancel') {
        $r = NgvRegistration::cancel((int) $app['id']);
        if (!empty($r['ok'])) { header('Location: ' . NgvRegistration::link((string) $app['ref']) . '&cancelled=1', true, 303); exit; }
        $err = (string) $r['error'];
    }
}
if (isset($_GET['booked']))    $msg = 'Booked. We have emailed you the details.';
if (isset($_GET['cancelled'])) $msg = 'Your booking is cancelled. You can choose another time, or walk in.';

$c       = Ngv::get();
$centres = NgvRegistration::centres();
$st      = $app ? (string) $app['status'] : '';
$booking = $app ? NgvRegistration::bookingFor((int) $app['id']) : null;
$pick    = $app ? (string) ($_GET['c'] ?? ($booking['centre'] ?? $app['centre'] ?? '')) : '';
if (!isset($centres[$pick])) $pick = (string) (array_key_first($centres) ?? '');
$slots   = ($app && in_array($st, ['invited', 'booked'], true) && $pick !== '') ? NgvRegistration::slots($pick) : [];
$items   = $app ? NgvRegistration::checklistFor($app) : [];
$ticked  = [];
if ($app) foreach ((array) json_decode((string) ($app['checklist_json'] ?? ''), true) as $k => $v) if (!empty($v['ok'])) $ticked[$k] = true;
$first   = $app ? (explode(' ', trim((string) $app['name']))[0] ?: 'there') : '';
/* The step in progress: everything before it is done. Applying is done the
   moment there is an application to show. */
$step    = ['new' => 2, 'reviewing' => 2, 'accepted' => 3, 'invited' => 3, 'booked' => 4, 'attended' => 4, 'enrolled' => 6][$st] ?? 0;
$e       = 'e';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Your application · NextGen Vanguard</title>
<link href="<?= e(av_asset('/assets/site/fonts.css')) ?>" rel="stylesheet" />
<style>
:root{--red:#e4162b;--orange:#ff6a1a;--ink:#15120e;--line:#e7e9ee;--muted:#5f6874;--bg:#f5f6f8;--card:#fff;--ok:#1d6b30;--grad:linear-gradient(100deg,#e4162b,#ff6a1a 55%,#ffb703);--r:16px}
*{box-sizing:border-box}
body{margin:0;font-family:'Source Sans 3',system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.55}
a{color:var(--red)}
:focus-visible{outline:3px solid var(--orange);outline-offset:2px}
.wrap{max-width:720px;margin:0 auto;padding:0 16px}
.hero{background:var(--grad);color:#fff;padding:28px 0}
.hero h1{margin:0;font-size:1.6rem;line-height:1.15}
.hero p{margin:4px 0 0;opacity:.95}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);margin:18px 0;padding:20px}
.card h2{margin:0 0 10px;font-size:1.15rem}
.fld{display:grid;gap:5px;margin:0 0 12px}
.fld label{font-weight:600;font-size:.95rem}
input,select{font:inherit;font-size:1rem;padding:11px 12px;border:1px solid #cfd4dc;border-radius:10px;width:100%;min-height:44px;background:#fff}
.btn{border:0;border-radius:11px;padding:12px 20px;font:inherit;font-weight:800;cursor:pointer;background:var(--grad);color:#fff;font-size:1rem;min-height:44px;text-decoration:none;display:inline-block}
.btn.ghost{background:#fff;color:var(--ink);border:1px solid #cfd4dc}
.err{background:#fdecec;color:#c0322b;border:1px solid #f6c9c6;border-radius:11px;padding:12px 14px;font-weight:600;margin:14px 0}
.msg{background:#eef8f0;color:var(--ok);border:1px solid #cfe8d4;border-radius:11px;padding:12px 14px;font-weight:600;margin:14px 0}
.note{font-size:.92rem;color:var(--muted)}
.steps{list-style:none;padding:0;margin:0;display:grid;gap:6px}
.steps li{display:flex;gap:10px;align-items:flex-start;color:var(--muted)}
.steps li .n{flex:none;width:26px;height:26px;border-radius:50%;border:2px solid #cfd4dc;display:grid;place-items:center;font-weight:800;font-size:.85rem}
.steps li.done{color:var(--ink)} .steps li.done .n{background:var(--ok);border-color:var(--ok);color:#fff}
.steps li.now{color:var(--ink);font-weight:700} .steps li.now .n{border-color:var(--orange);color:var(--orange)}
.list{margin:0;padding-left:20px} .list li{margin:6px 0}
.list .opt{color:var(--muted);font-size:.9rem}
.slots{display:grid;gap:8px;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));margin:8px 0 12px}
.slot{display:flex;gap:8px;align-items:center;border:1px solid var(--line);border-radius:10px;padding:10px;min-height:44px;cursor:pointer}
.slot input{width:auto;min-height:0}
.slot small{color:var(--muted)}
.day{margin:12px 0 2px;font-weight:700}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.ref{font-family:ui-monospace,monospace;font-weight:700;letter-spacing:.04em}
</style>
</head>
<body>
<header class="hero"><div class="wrap">
  <h1>Your NextGen Vanguard application</h1>
  <?php if ($app): ?><p>Reference <span class="ref"><?= $e((string) $app['ref']) ?></span></p><?php endif; ?>
</div></header>
<main id="main" class="wrap">
<?php if ($err !== ''): ?><div class="err" role="alert"><?= $e($err) ?></div><?php endif; ?>
<?php if ($msg !== ''): ?><div class="msg" role="status"><?= $e($msg) ?></div><?php endif; ?>

<?php if (!$app): ?>
  <div class="card">
    <h2>Find your application</h2>
    <p class="note">Use the link in your emails, or enter your reference and the email you applied with.</p>
    <form method="post" action="/academy/ngv/status.php">
      <input type="hidden" name="do" value="find">
      <div class="fld"><label for="s-ref">Reference</label><input id="s-ref" name="ref" required placeholder="NGV-XXXXXX" value="<?= $e($ref) ?>" autocapitalize="characters"></div>
      <div class="fld"><label for="s-email">Email</label><input id="s-email" type="email" name="email" required autocomplete="email"></div>
      <button class="btn" type="submit">Open my application</button>
    </form>
  </div>
<?php else:
  /* Where a status asks them to get in touch, it names how — "contact us" with
     no address is a dead end for somebody whose invitation has just closed. */
  $ctEmail = trim((string) ($c['contact']['email'] ?? ''));
  $ctPhone = trim((string) ($c['contact']['phone'] ?? ''));
  $reach = $ctEmail !== '' ? '<a href="mailto:' . $e($ctEmail) . '?subject=' . rawurlencode('NGV application ' . (string) $app['ref']) . '">Email ' . $e($ctEmail) . '</a>'
         : ($ctPhone !== '' ? '<a href="tel:' . $e(preg_replace('/[^0-9+]/', '', $ctPhone)) . '">Call ' . $e($ctPhone) . '</a>' : ''); ?>
  <div class="card">
    <h2>Hello, <?= $e($first) ?></h2>
    <ol class="steps" aria-label="Your progress">
      <?php foreach (['Applied online', 'Invited to register', 'Visit booked (or walk in)', 'Came in — documents checked', 'Registered'] as $i => $label): $n = $i + 1; ?>
        <li class="<?= $n < $step ? 'done' : ($n === $step ? 'now' : '') ?>"><span class="n"><?= $n ?></span><span><?= $e($label) ?></span></li>
      <?php endforeach; ?>
    </ol>
    <p style="margin-top:14px">
    <?php switch ($st):
      case 'new': case 'reviewing': ?>We are reviewing your application, and will email <?= $e((string) $app['email']) ?> when it is decided.<?php break;
      case 'invited': case 'accepted': ?><b>You are invited to register.</b> Print the MoU, have it signed, and come to a CACENTRE with your guarantor. <?= $st === 'invited' ? '<a href="#book">Book a time below</a> — advised, though you can walk in.' : 'Booking a time is advised.' ?><?php break;
      case 'booked': ?><b>Your visit is booked.</b> Bring everything on the list below, and your guarantor.<?php break;
      case 'attended': ?><b>You came in, and some things are still needed</b> — see the list below. Bring them to the CACENTRE to finish.<?php break;
      case 'enrolled': ?><b>You are registered.</b> Sign in at <a href="/login">afrovanguard.org.ng/login</a> with <?= $e((string) $app['email']) ?> — we email you a code; there is no password to set first.<?php break;
      case 'rejected': ?>We are not able to offer you a place this time. Thank you for applying.<?= $reach !== '' ? ' Questions about the decision? ' . $reach . ', quoting ' . $e((string) $app['ref']) . '.' : '' ?><?php break;
      case 'lapsed': ?>Your invitation has closed because it was not taken up in time. If you still want to join, <?= $reach !== '' ? $reach . ' with your reference ' . $e((string) $app['ref']) . ' and we can invite you again' : 'contact the programme team with your reference ' . $e((string) $app['ref']) . ' and we can invite you again' ?>.<?php break;
      default: ?>Your application is <?= $e($st) ?>.<?php endswitch; ?>
    </p>
  </div>

  <?php if (in_array($st, ['invited', 'accepted', 'booked', 'attended'], true)): ?>
  <div class="card">
    <h2>What to bring</h2>
    <ul class="list">
      <?php foreach ($items as $k => $it): ?>
        <li><?= isset($ticked[$k]) ? '✅ ' : '' ?><b><?= $e($it['label']) ?></b><?= $it['required'] ? '' : ' <span class="opt">(if you can)</span>' ?>
          <?php if ($it['desc'] !== ''): ?><br><span class="note"><?= $e($it['desc']) ?></span><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <p class="row" style="margin-top:12px">
      <a class="btn" href="<?= $e(NgvRegistration::link((string) $app['ref'], 'mou.php')) ?>">Open the MoU to print</a>
      <span class="note">Your guarantor: <b><?= $e((string) ($app['g_name'] ?: 'not given yet')) ?></b><?= $app['g_relationship'] !== '' ? ' (' . $e(NgvRegistration::relationshipLabel($app)) . ')' : '' ?></span>
    </p>
  </div>
  <?php endif; ?>

  <?php if (in_array($st, ['invited', 'booked'], true)): ?>
  <div class="card" id="book">
    <h2><?= $booking ? 'Your visit' : 'Book a time' ?></h2>
    <?php if ($booking): ?>
      <p><b><?= $e(NgvRegistration::longDate((string) $booking['slot_date'])) ?> at <?= $e((string) $booking['slot_time']) ?></b>, <?= $e((string) $booking['centre']) ?>.</p>
      <form method="post" action="/academy/ngv/status.php" class="row">
        <input type="hidden" name="ref" value="<?= $e((string) $app['ref']) ?>"><input type="hidden" name="k" value="<?= $e($key) ?>">
        <button class="btn ghost" type="submit" name="do" value="cancel">Cancel this booking</button>
        <span class="note">Or choose a different time below — it replaces this one.</span>
      </form>
    <?php endif; ?>
    <?php if ($centres): ?>
      <form method="get" action="/academy/ngv/status.php" class="row" style="margin:10px 0">
        <input type="hidden" name="ref" value="<?= $e((string) $app['ref']) ?>"><input type="hidden" name="k" value="<?= $e($key) ?>">
        <label for="b-centre" class="note">CACENTRE</label>
        <select id="b-centre" name="c" style="max-width:280px"><?php foreach ($centres as $cn => $_): ?><option value="<?= $e($cn) ?>" <?= $cn === $pick ? 'selected' : '' ?>><?= $e($cn) ?></option><?php endforeach; ?></select>
        <button class="btn ghost" type="submit">Show times at this CACENTRE</button>
      </form>
      <?php if ($slots): ?>
        <form method="post" action="/academy/ngv/status.php">
          <input type="hidden" name="ref" value="<?= $e((string) $app['ref']) ?>"><input type="hidden" name="k" value="<?= $e($key) ?>">
          <input type="hidden" name="centre" value="<?= $e($pick) ?>"><input type="hidden" name="do" value="book">
          <fieldset style="border:0;padding:0;margin:0"><legend class="note">Times at <?= $e($pick) ?></legend>
          <?php $lastDay = ''; foreach ($slots as $sl): if ($sl['date'] !== $lastDay): if ($lastDay !== ''): ?></div><?php endif; $lastDay = $sl['date']; ?>
            <p class="day"><?= $e(NgvRegistration::longDate($sl['date'])) ?></p><div class="slots">
          <?php endif; ?>
            <label class="slot"><input type="radio" name="slot" value="<?= $e($sl['date'] . '|' . $sl['time']) ?>" required> <?= $e($sl['time']) ?> <small><?= (int) $sl['left'] ?> left</small></label>
          <?php endforeach; ?></div>
          </fieldset>
          <button class="btn" type="submit">Book this time</button>
        </form>
      <?php else: ?>
        <p class="note">No times left at <?= $e($pick) ?> in the next four weeks. Try another CACENTRE, or walk in.</p>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (trim((string) ($c['reg_walkin'] ?? '')) !== ''): ?><p class="note"><?= $e((string) $c['reg_walkin']) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
  <p class="note"><a href="/academy/ngv/">NextGen Vanguard</a><?php if (!empty($c['contact']['phone'])): ?> · Questions? <?= $e((string) $c['contact']['phone']) ?><?php endif; ?></p>
</main>
</body>
</html>
