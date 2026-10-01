<?php
/**
 * academy/ngv/attendance.php — NextGen Vanguard attendance, for staff.
 *
 * The day's register as the CACENTRE gate reported it (lib/GateAttendance),
 * every active participant not in yet, the excuses waiting on a decision,
 * and member ID cards. It replaces the admin screens of the two Apps Script
 * attendance systems, and unlike them it is behind the admin sign-in.
 *
 * Nothing here takes a passage: arrivals only ever come from the gate,
 * signed. What staff do here is decide — excuse a day, answer a request,
 * give a member their card — and every one of those lands on the audit
 * trail. Fines are the NGV ledger's, on the Vanguards page, like any other.
 *
 * Admin-gated exactly like members.php. JSON actions POST to this same URL,
 * guarded by admin + same-origin + CSRF.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$role    = function_exists('av_admin_role') ? av_admin_role() : '';
$isAdmin = $role !== '';
$by      = (int) (LmsAuth::user()['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$isAdmin) json_out(['ok' => false, 'error' => 'Admin sign-in required.'], 403);
    require_same_origin();
    av_csrf_require();
    $in  = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $act = (string) ($in['action'] ?? '');
    if ($act === 'excuse_day') json_out(GateAttendance::excuseDay((int) ($in['member_id'] ?? 0), (string) ($in['day'] ?? ''), (string) ($in['why'] ?? ''), $by));
    if ($act === 'decide') json_out(GateAttendance::decideExcuse((int) ($in['id'] ?? 0), !empty($in['approve']), (string) ($in['outcome'] ?? ''), $by));
    if ($act === 'card' || $act === 'withdraw') {
        $who = trim((string) ($in['member'] ?? ''));
        $st = Database::pdo()->prepare(ctype_digit($who) ? 'SELECT id FROM lms_users WHERE id = ?' : 'SELECT id FROM lms_users WHERE email = ?');
        $st->execute([ctype_digit($who) ? (int) $who : strtolower($who)]);
        $mid = (int) ($st->fetchColumn() ?: 0);
        if ($mid <= 0) json_out(['ok' => false, 'error' => 'No member with that email or number.'], 404);
        if ($act === 'card') json_out(GateAttendance::assignCard($mid, (string) ($in['code'] ?? ''), $by));
        /* A lost phone: every pass issued so far stops at the gate. Their next
           visit to /gate-pass makes a good one. */
        $r = GatePass::revoke($mid);
        if (!empty($r['ok']) && class_exists('AdminAudit')) AdminAudit::log('attendance', 'gate.revoke', 'member:' . $mid, 'Gate passes withdrawn');
        json_out($r);
    }
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$today = function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d');
$day   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['day'] ?? '')) ? (string) $_GET['day'] : $today;
$reg   = $isAdmin ? GateAttendance::register($day) : null;
$asks  = $isAdmin ? GateAttendance::pendingExcuses() : [];
$csrf  = av_csrf_token();
$e     = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$hm    = static function (string $iso): string {
    if ($iso === '' || ($t = strtotime($iso)) === false) return '';
    try { return (new DateTime('@' . $t))->setTimezone(new DateTimeZone(defined('AV_TZ') ? AV_TZ : 'Africa/Lagos'))->format('H:i'); }
    catch (Throwable $x) { return gmdate('H:i', $t); }
};
$label = ['present' => 'On time', 'late' => 'Late', 'absent' => 'Absent', 'excused' => 'Excused'];
$prev  = date('Y-m-d', strtotime($day . ' -1 day'));
$next  = date('Y-m-d', strtotime($day . ' +1 day'));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Attendance · NGV staff</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--red:#e4162b;--orange:#ff6a1a;--gold:#ffb703;--ink:#15120e;--line:#e7e9ee;--muted:#5f6874;--bg:#f5f6f8;--card:#fff;
  --green:#137a3a;--green-bg:#e6f7ec;--amber:#9a5b00;--amber-bg:#fffaf0;--danger:#c0322b;--danger-bg:#fdecec;--r:14px}
*{box-sizing:border-box}
body{margin:0;font-family:Montserrat,system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.5}
a{color:var(--red)}
h1,h2{margin:0;font-weight:800;letter-spacing:-.01em}
:focus-visible{outline:3px solid var(--orange);outline-offset:2px}
.top{position:sticky;top:0;z-index:20;background:rgba(21,18,14,.97);color:#fff;display:flex;gap:12px;align-items:center;padding:10px 20px;flex-wrap:wrap}
.top h1{font-size:.95rem}.top h1 b{color:var(--gold)}.top h1 span{font-weight:600;color:rgba(255,255,255,.7)}
.top .sp{flex:1}.top a{color:rgba(255,255,255,.85);text-decoration:none;font-weight:600;font-size:.86rem}
.msg{font-size:.85rem;font-weight:700}
.wrap{max-width:1100px;margin:22px auto 56px;padding:0 16px;display:flex;flex-direction:column;gap:18px}
.gatebox{max-width:520px;margin:12vh auto;background:#fff;border:1px solid var(--line);border-radius:18px;padding:36px;text-align:center}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);overflow:hidden}
.card>header{padding:13px 18px;border-bottom:1px solid var(--line);display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.card>header h2{font-size:1rem}.card>header .sp{flex:1}
.card>.body{padding:14px 18px 18px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}
.stat{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:12px 16px}
.stat .k{font-size:.75rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.stat .v{font-size:1.6rem;font-weight:800;font-variant-numeric:tabular-nums}
table{width:100%;border-collapse:collapse;font-size:.9rem}
th,td{text-align:left;padding:.5rem .6rem;border-top:1px solid var(--line);vertical-align:top}
th{font-size:.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;border-top:0}
td.num{font-variant-numeric:tabular-nums}
.pill{display:inline-block;border-radius:999px;padding:.05rem .55rem;font-size:.78rem;font-weight:700}
.p-present{background:var(--green-bg);color:var(--green)}.p-late{background:var(--amber-bg);color:var(--amber)}
.p-absent{background:var(--danger-bg);color:var(--danger)}.p-excused{background:#eef0f3;color:var(--muted)}
.btn{font:inherit;font-size:.82rem;font-weight:700;border:1px solid var(--line);background:#fff;border-radius:9px;padding:.35rem .7rem;cursor:pointer}
.btn.primary{background:var(--ink);color:#fff;border-color:var(--ink)}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:end}
.row label{font-size:.8rem;font-weight:700;color:var(--muted);display:flex;flex-direction:column;gap:4px}
input{font:inherit;padding:.45rem .6rem;border:1px solid var(--line);border-radius:9px}
.sub{color:var(--muted);font-size:.84rem}
.note{background:var(--amber-bg);border:1px solid #f2c98a;border-radius:12px;padding:.7rem .9rem;font-size:.9rem;margin:0}
.scroll{overflow-x:auto}
</style>
</head>
<body>
<?php if (!$isAdmin): ?>
  <div class="gatebox">
    <h1>Admin sign-in required</h1>
    <p>This page shows NextGen Vanguard attendance at the CACENTRE gate. Sign in to the Academy Studio, then come back.</p>
    <p><a href="/academy/studio/">Go to the Studio →</a></p>
  </div>
<?php else: $c = $reg['counts']; ?>
<header class="top">
  <h1><b>NextGen Vanguard</b> <span>· attendance</span></h1>
  <span class="sp"></span>
  <a href="/academy/ngv/members.php">Vanguards</a>
  <a href="/academy/studio/">Rules (Studio)</a>
  <span class="msg" id="msg" role="status" aria-live="polite"></span>
</header>
<main class="wrap">
  <?php if (!GatePass::ready()): ?><p class="note">The CACENTRE gate is not connected: GATE_PASS_SECRET is not set, so no passes are issued and nothing is reported here.</p><?php endif; ?>
  <?php if (!GateAttendance::marksAbsent()): ?><p class="note">Absences are not being marked — turn on <b>Mark absences</b> under Attendance in the Studio rules. Until then this page shows who came, and attendance rates count only those days.</p><?php endif; ?>

  <form class="row" method="get" aria-label="Choose a day">
    <a class="btn" href="?day=<?= $e($prev) ?>" aria-label="Previous day">‹</a>
    <label>Day <input type="date" name="day" value="<?= $e($day) ?>"></label>
    <button class="btn" type="submit">Show</button>
    <a class="btn" href="?day=<?= $e($next) ?>" aria-label="Next day">›</a>
    <?php if ($day !== $today): ?><a class="btn" href="?">Today</a><?php endif; ?>
    <span class="sub"><?= $e(date('l j F Y', strtotime($day . 'T12:00:00'))) ?><?= $reg['programme_day'] ? '' : ' · not a programme day' ?></span>
  </form>

  <section class="stats" aria-label="The day at a glance">
    <div class="stat"><div class="k">On time</div><div class="v"><?= (int) $c['present'] ?></div></div>
    <div class="stat"><div class="k">Late</div><div class="v"><?= (int) $c['late'] ?></div></div>
    <div class="stat"><div class="k">Absent</div><div class="v"><?= (int) $c['absent'] ?></div></div>
    <div class="stat"><div class="k">Excused</div><div class="v"><?= (int) $c['excused'] ?></div></div>
    <div class="stat"><div class="k">Not in<?= $day === $today ? ' yet' : '' ?></div><div class="v"><?= count($reg['not_in']) ?></div></div>
  </section>

  <?php if ($asks): ?>
  <section class="card" aria-labelledby="h-asks">
    <header><h2 id="h-asks">Asked to be excused</h2><span class="sp"></span><span class="sub"><?= count($asks) ?> waiting</span></header>
    <div class="body scroll"><table>
      <thead><tr><th>Member</th><th>Day</th><th>Why</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($asks as $a): ?>
        <tr><td><?= $e((string) ($a['name'] ?? ('#' . $a['member_id']))) ?></td><td class="num"><?= $e((string) $a['day']) ?></td><td><?= $e((string) $a['reason']) ?></td>
            <td><button class="btn primary" data-decide="<?= (int) $a['id'] ?>" data-approve="1">Excuse</button> <button class="btn" data-decide="<?= (int) $a['id'] ?>" data-approve="0">Decline</button></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </section>
  <?php endif; ?>

  <section class="card" aria-labelledby="h-reg">
    <header><h2 id="h-reg">Through the gate</h2><span class="sp"></span><span class="sub"><?= count($reg['rows']) ?> recorded</span></header>
    <div class="body scroll">
    <?php if (!$reg['rows']): ?><p class="sub">Nobody recorded for this day.</p><?php else: ?>
    <table>
      <thead><tr><th>Member</th><th>Status</th><th>In</th><th>Out</th><th>Where</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($reg['rows'] as $r): $s = (string) $r['status']; ?>
        <tr><td><?= $e((string) ($r['name'] ?? ('#' . $r['member_id']))) ?></td>
            <td><span class="pill p-<?= $e($s) ?>"><?= $e($label[$s] ?? $s) ?><?= $s === 'late' ? ' · ' . (int) $r['late_minutes'] . ' min' : '' ?></span><?= (int) $r['fine_id'] > 0 ? ' <span class="sub">fined</span>' : '' ?></td>
            <td class="num"><?= $e($hm((string) $r['in_at'])) ?></td><td class="num"><?= $e($hm((string) $r['out_at'])) ?></td>
            <td class="sub"><?= $e(trim((string) $r['centre'] . ((string) $r['method'] !== '' ? ' · ' . $r['method'] : ''), ' ·')) ?></td>
            <td><?php if ($s === 'absent'): ?><button class="btn" data-excuse="<?= (int) $r['member_id'] ?>">Excuse</button><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
    </div>
  </section>

  <?php if ($reg['not_in']): ?>
  <section class="card" aria-labelledby="h-not">
    <header><h2 id="h-not">Active participants not in<?= $day === $today ? ' yet' : '' ?></h2></header>
    <div class="body scroll"><table><tbody>
      <?php foreach ($reg['not_in'] as $p): ?>
        <tr><td><?= $e($p['name'] !== '' ? $p['name'] : '#' . $p['member_id']) ?></td><td style="text-align:right"><button class="btn" data-excuse="<?= (int) $p['member_id'] ?>">Excuse this day</button></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>
  <?php endif; ?>

  <section class="card" aria-labelledby="h-cards">
    <header><h2 id="h-cards">Member ID cards</h2></header>
    <div class="body">
      <p class="sub" style="margin-top:0">A printed card works at the desk scanned or typed, for a member whose phone is flat. Enter the number on a card they already hold — cards from the spreadsheet days keep working — or leave it empty for the next free one. Their earlier card stops working.</p>
      <div class="row">
        <label>Member (email or number) <input id="c_member" autocomplete="off"></label>
        <label>Card number <input id="c_code" placeholder="A-NGV-25-0001" autocapitalize="characters" autocomplete="off"></label>
        <button class="btn primary" id="c_go" type="button">Give card</button>
        <button class="btn" id="c_withdraw" type="button" title="For a lost or stolen phone">Withdraw their passes</button>
      </div>
      <p class="sub" id="c_out" role="status"></p>
    </div>
  </section>
</main>
<script>
(function () {
  var CSRF = <?= json_encode($csrf) ?>, DAY = <?= json_encode($day) ?>;
  var msg = document.getElementById('msg'), mt;
  function toast(t, good) { msg.textContent = t; msg.style.color = good === false ? '#ffb4ab' : '#9be7b4'; clearTimeout(mt); mt = setTimeout(function () { msg.textContent = ''; }, 3000); }
  function post(body) {
    return fetch(location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unreadable reply.' }; }); });
  }
  document.querySelectorAll('[data-excuse]').forEach(function (b) {
    b.addEventListener('click', function () {
      var why = prompt('Why is this day excused?'); if (why === null) return;
      post({ action: 'excuse_day', member_id: +b.getAttribute('data-excuse'), day: DAY, why: why }).then(function (r) { if (r.ok) location.reload(); else toast(r.error || 'Not saved.', false); });
    });
  });
  document.querySelectorAll('[data-decide]').forEach(function (b) {
    b.addEventListener('click', function () {
      var yes = b.getAttribute('data-approve') === '1';
      var note = prompt(yes ? 'A note for the member (optional)' : 'Why not? The member will see this.'); if (note === null) return;
      post({ action: 'decide', id: +b.getAttribute('data-decide'), approve: yes, outcome: note }).then(function (r) { if (r.ok) location.reload(); else toast(r.error || 'Not saved.', false); });
    });
  });
  document.getElementById('c_withdraw').addEventListener('click', function () {
    var out = document.getElementById('c_out');
    if (!confirm('Every gate pass this member has been issued stops working now. Continue?')) return;
    post({ action: 'withdraw', member: document.getElementById('c_member').value }).then(function (r) {
      out.textContent = r.ok ? 'Withdrawn. Their next visit to /gate-pass makes a good one.' : (r.error || 'Not withdrawn.');
    });
  });
  document.getElementById('c_go').addEventListener('click', function () {
    var out = document.getElementById('c_out');
    post({ action: 'card', member: document.getElementById('c_member').value, code: document.getElementById('c_code').value }).then(function (r) {
      out.textContent = r.ok ? (r.already ? r.code + ' is already theirs.' : 'Card ' + r.code + ' given.') : (r.error || 'Not saved.');
      toast(r.ok ? 'Saved' : 'Not saved', !!r.ok);
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
