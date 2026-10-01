<?php
/**
 * gate-pass.php — a member's page for the CACENTRE gate, at /gate-pass.
 *
 * First, the pass: a QR to hold up to a CACENTRE desk's camera, minted fresh
 * on every open and living GatePass::ttl() (twelve hours by default). The
 * page is never cached and reloads itself before the pass it shows expires.
 * Nothing on this site trusts the pass; only the gate reads it. See
 * lib/GatePass.php. A pass works without a signal: once it is on screen the
 * desk needs nothing from the phone, and the gate needs nothing from here.
 *
 * Below it, what the gate has recorded about them (lib/GateAttendance.php):
 * the last month's rate, punctuality and grade, the days themselves, and a
 * way to tell the NGV office about a day they will be — or were — away.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/partials.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$u = LmsAuth::user();
if (!$u) { header('Location: ' . av_login_url('/gate-pass')); exit; }
$mid = (int) $u['id'];

/* A member telling the office about a day away. A form post, not a fetch:
   the page works with scripts off, which a cheap phone on a bad day needs. */
$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!av_csrf_valid((string) ($_POST['csrf'] ?? ''))) $flash = 'That form had gone stale. Try again.';
    else {
        $r = GateAttendance::requestExcuse($mid, (string) ($_POST['day'] ?? ''), (string) ($_POST['reason'] ?? ''));
        $flash = $r['ok'] ? 'Sent to the NGV office. You will see their answer here.' : (string) $r['error'];
    }
}

$why = !GatePass::ready() ? 'Gate passes are not switched on yet. An administrator needs to set GATE_PASS_SECRET.' : (GatePass::whyNot($u) ?? '');
$pass = $why === '' ? GatePass::mint($u) : null;
/* Reload an hour before it runs out, or at once if the pass is shorter than that. */
$reload = $pass ? max(60, $pass['exp'] - time() - 3600) : 0;
$first = e(explode(' ', trim((string) $u['name']))[0] ?: 'Member');
$card = GateAttendance::cardFor($mid);
$sum = GateAttendance::summary($mid, 30);
$days = array_slice(GateAttendance::history($mid, 60), 0, 20);
$asks = GateAttendance::excusesFor($mid);
$expected = GateAttendance::expected($mid);
$label = ['present' => 'On time', 'late' => 'Late', 'absent' => 'Absent', 'excused' => 'Excused'];
/* The gate stamps passages in UTC; a member reads them on the centre's clock. */
$hm = static function (string $iso): string {
    if ($iso === '' || ($t = strtotime($iso)) === false) return '';
    try { return (new DateTime('@' . $t))->setTimezone(new DateTimeZone(defined('AV_TZ') ? AV_TZ : 'Africa/Lagos'))->format('H:i'); }
    catch (Throwable $e) { return gmdate('H:i', $t); }
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#111111">
<?php if ($pass && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'): ?><meta http-equiv="refresh" content="<?= (int) $reload ?>"><?php endif; ?>
<title>CACENTRE gate pass</title>
<style>
  :root { --bg:#111; --panel:#1b1b1b; --line:#2c2c2c; --card:#fff; --ink:#111; --muted:#aaa; --gold:#e8b536; --ok:#4cc38a; --late:#e8b536; --no:#ef6b6b; }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; background:var(--bg); color:#fff; font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif; padding:16px; }
  main { width:100%; max-width:26rem; margin:0 auto; }
  .eyebrow { color:var(--gold); font-weight:700; letter-spacing:.08em; text-transform:uppercase; font-size:.75rem; margin:0 0 .4rem; text-align:center; }
  h1 { font-size:1.6rem; margin:0; text-align:center; }
  h2 { font-size:1rem; margin:0 0 .6rem; }
  .role { color:#bbb; margin:.1rem 0 1rem; text-align:center; }
  .qr { background:var(--card); border-radius:18px; padding:14px; }
  .qr svg { display:block; width:100%; height:auto; }
  .qr svg path, .qr svg rect { fill:var(--ink); }
  .fine { color:var(--muted); font-size:.85rem; margin-top:.8rem; }
  .centre { text-align:center; }
  .panel { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:14px 16px; margin-top:16px; }
  .stats { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; text-align:center; }
  .stats b { display:block; font-size:1.35rem; font-variant-numeric:tabular-nums; }
  .stats span { color:var(--muted); font-size:.75rem; }
  .card-no { font:700 1.15rem/1.2 ui-monospace,SFMono-Regular,Menlo,monospace; letter-spacing:.06em; }
  ul.days { list-style:none; margin:0; padding:0; }
  ul.days li { display:flex; justify-content:space-between; gap:8px; padding:.45rem 0; border-top:1px solid var(--line); font-size:.92rem; }
  ul.days li:first-child { border-top:0; }
  .s-present { color:var(--ok); } .s-late { color:var(--late); } .s-absent { color:var(--no); } .s-excused { color:var(--muted); }
  label { display:block; font-size:.85rem; color:#ddd; margin-top:.6rem; }
  input, textarea { width:100%; margin-top:.25rem; padding:.6rem .7rem; border-radius:10px; border:1px solid var(--line); background:#0d0d0d; color:#fff; font:inherit; }
  button { margin-top:.8rem; width:100%; padding:.7rem; border:0; border-radius:10px; background:var(--gold); color:#111; font:inherit; font-weight:700; }
  .flash { background:#262013; border:1px solid #4a3d16; border-radius:10px; padding:.6rem .8rem; margin-top:16px; font-size:.92rem; }
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
  <p class="fine centre">Hold this up to the desk camera. Turn your screen brightness up if it does not read.<br>
     Good until <strong><?= e((new DateTime('@' . $pass['exp']))->setTimezone(new DateTimeZone(defined('AV_TZ') ? AV_TZ : 'Africa/Lagos'))->format('D j M, H:i')) ?></strong>. This page makes a new one each time you open it.</p>
<?php else: ?>
  <h1>No pass</h1>
  <p class="fine centre"><?= e($why) ?></p>
<?php endif; ?>
<?php if ($flash !== ''): ?><p class="flash" role="status"><?= e($flash) ?></p><?php endif; ?>

<?php if ($card): ?>
  <section class="panel">
    <h2>Your member card</h2>
    <p class="card-no"><?= e($card) ?></p>
    <p class="fine" style="margin-top:.3rem">The desk takes the printed card too — scanned, or this number typed — if your phone is flat.</p>
  </section>
<?php endif; ?>

  <section class="panel" aria-labelledby="h_rec">
    <h2 id="h_rec">The last 30 days</h2>
<?php if ($sum['counted'] === 0): ?>
    <p class="fine" style="margin-top:0">Nothing recorded yet. Your first time through the gate will show here.</p>
<?php else: ?>
    <div class="stats">
      <div><b><?= (int) $sum['rate'] ?>%</b><span>attendance</span></div>
      <div><b><?= (int) $sum['punctuality'] ?>%</b><span>on time</span></div>
      <div><b><?= e((string) $sum['grade']) ?></b><span>grade</span></div>
      <div><b><?= (int) $sum['streak'] ?></b><span>on-time run</span></div>
    </div>
    <p class="fine"><?= (int) $sum['present'] ?> on time · <?= (int) $sum['late'] ?> late<?= $sum['late'] ? ' (' . (int) $sum['late_minutes'] . ' min in all)' : '' ?> · <?= (int) $sum['absent'] ?> absent · <?= (int) $sum['excused'] ?> excused</p>
<?php endif; ?>
<?php if ($days): ?>
    <ul class="days">
<?php foreach ($days as $d): $s = (string) $d['status']; ?>
      <li><span><?= e(date('D j M', (int) strtotime((string) $d['day'] . 'T12:00:00'))) ?></span>
          <span class="s-<?= e($s) ?>"><?= e($label[$s] ?? $s) ?><?= $s === 'late' ? ' · ' . (int) $d['late_minutes'] . ' min' : '' ?><?= $d['in_at'] !== '' ? ' · ' . e($hm((string) $d['in_at'])) . ($d['out_at'] !== '' ? '–' . e($hm((string) $d['out_at'])) : '') : '' ?></span></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>

<?php if ($expected): ?>
  <section class="panel" aria-labelledby="h_away">
    <h2 id="h_away">Away on a programme day?</h2>
    <p class="fine" style="margin-top:0">Tell the NGV office before, or within two weeks after. An excused day counts against nothing.</p>
    <form method="post" action="/gate-pass">
      <input type="hidden" name="csrf" value="<?= e(av_csrf_token()) ?>">
      <label>The day <input type="date" name="day" required value="<?= e(function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d')) ?>"></label>
      <label>Why <textarea name="reason" rows="2" maxlength="300" required placeholder="Exam at school, hospital appointment…"></textarea></label>
      <button type="submit">Send to the NGV office</button>
    </form>
<?php if ($asks): ?>
    <ul class="days" style="margin-top:.8rem">
<?php foreach ($asks as $a): ?>
      <li><span><?= e(date('D j M', (int) strtotime((string) $a['day'] . 'T12:00:00'))) ?></span>
          <span class="<?= $a['status'] === 'approved' ? 's-present' : ($a['status'] === 'declined' ? 's-absent' : 's-excused') ?>"><?= e(['pending' => 'Waiting', 'approved' => 'Excused', 'declined' => 'Not excused'][(string) $a['status']] ?? (string) $a['status']) ?><?= (string) $a['outcome'] !== '' ? ' — ' . e((string) $a['outcome']) : '' ?></span></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>
<?php endif; ?>

  <p class="fine centre">Lost your phone? Tell the office, and every pass you have been given stops working.</p>
  <p class="fine centre"><a href="/portal/">Back to the portal</a></p>
</main>
</body>
</html>
