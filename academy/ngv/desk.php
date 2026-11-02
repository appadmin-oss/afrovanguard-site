<?php
/**
 * academy/ngv/desk.php — the CACENTRE registration desk.
 *
 * Where an invited applicant becomes a vanguard. They come in, with their
 * guarantor and the MoU they printed and signed; whoever is on the desk finds
 * them (by the reference on the MoU, or by name), ticks the checklist as each
 * document is seen, records the guarantor in person and the ID shown, the MoU
 * version printed on the paper (and a scan of it), takes or waives the
 * membership fee, and completes. Only then is the account made, the programme
 * fee frozen on the terms of the MoU they signed, and the NGV card issued
 * (NgvRegistration::complete). NGG promotions arrive here too: an NGG member
 * signs NGV's MoU like anybody else.
 *
 * Plain forms, no script: a desk on a shared laptop and a slow connection.
 * Each form posts here with the CSRF token as a field, and a success redirects
 * (so a refresh cannot submit it twice); a refusal is drawn on the same page,
 * with what is still missing named.
 *
 * Administrators only, as the console: it moves money (the membership fee) and
 * makes accounts. The MoU scan carries a date of birth and two signatures, so
 * it lives under data/ and is only ever served from here, to staff.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$role    = function_exists('av_admin_role') ? av_admin_role() : '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
$uid     = $isAdmin ? (int) (LmsAuth::user()['id'] ?? 0) : 0;
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');

function ngv_desk_audit(string $action, string $target, string $detail): void
{
    if (!class_exists('AdminAudit')) return;
    $who = function_exists('av_admin_actor') ? (string) av_admin_actor() : 'admin';
    try { AdminAudit::log('ngv', 'ngv.desk.' . $action, $target, $detail, null, $who !== '' ? $who : 'admin'); }
    catch (Throwable $e) { error_log('[ngv] desk audit: ' . $e->getMessage()); }
}

/* ── The signed MoU, to staff only ──────────────────────────────────────── */
if ($isAdmin && isset($_GET['scan'])) {
    $app = NgvMember::application((int) $_GET['scan']);
    $path = $app ? NgvRegistration::scanPath($app) : null;
    if (!$path) { http_response_code(404); exit('No scan.'); }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    header('Content-Type: ' . (['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
    readfile($path);
    exit;
}

/* ── Actions ────────────────────────────────────────────────────────────── */
$err = '';
$errMissing = [];
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_same_origin();
    if (!av_csrf_valid((string) ($_POST['csrf'] ?? ''))) {
        $err = 'This page had been open too long. Nothing was saved — press the button again.';
    } else {
        $act = (string) ($_POST['act'] ?? '');
        $target = 'application#' . $id;
        $r = ['ok' => false, 'error' => 'Unknown action.'];
        $done = '';
        if ($act === 'arrive') {
            $r = NgvRegistration::arrive($id); $done = 'arrived';
        } elseif ($act === 'noshow') {
            $b = NgvRegistration::bookingFor($id);
            $r = $b ? NgvRegistration::noShow((int) $b['id']) : ['ok' => false, 'error' => 'They have no booking to mark.'];
            $done = 'noshow';
        } elseif ($act === 'tick') {
            $r = NgvRegistration::tick($id, array_map('strval', (array) ($_POST['items'] ?? [])), $uid); $done = 'ticked';
        } elseif ($act === 'guarantor') {
            $r = NgvRegistration::guarantorSeen($id, [
                'g_name' => (string) ($_POST['g_name'] ?? ''), 'g_relationship' => (string) ($_POST['g_relationship'] ?? ''),
                'g_relationship_other' => (string) ($_POST['g_relationship_other'] ?? ''), 'g_phone' => (string) ($_POST['g_phone'] ?? ''),
                'g_email' => (string) ($_POST['g_email'] ?? ''),
            ], (string) ($_POST['g_id_seen'] ?? ''), $uid);
            $done = 'guarantor';
        } elseif ($act === 'mou') {
            $r = NgvRegistration::setMouVersion($id, (string) ($_POST['mou_version'] ?? ''));
            if (!empty($r['ok']) && !empty($_FILES['scan']) && (int) ($_FILES['scan']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $r = NgvRegistration::attachScan($id, $_FILES['scan']);
            }
            $done = 'mou';
        } elseif ($act === 'complete') {
            $r = NgvRegistration::complete($id, $uid, [
                'how' => (string) ($_POST['fee_how'] ?? ''), 'amount' => $_POST['fee_amount'] ?? 0,
                'method' => (string) ($_POST['fee_method'] ?? 'cash'), 'reference' => (string) ($_POST['fee_reference'] ?? ''),
                'reason' => (string) ($_POST['fee_reason'] ?? ''),
            ]);
            $done = 'registered';
        }
        if (!empty($r['ok'])) {
            ngv_desk_audit($act, $target, $act === 'complete'
                ? 'registered as member #' . (int) $r['member_id'] . ' · membership ' . (string) $r['membership']
                : $done);
            header('Location: /academy/ngv/desk.php?id=' . $id . '&done=' . rawurlencode($done), true, 303);
            exit;
        }
        $err = (string) ($r['error'] ?? 'That did not work.');
        $errMissing = (array) ($r['missing'] ?? []);
        if ($act === 'complete') ngv_desk_audit('complete_refused', $target, $err);
    }
}

/* ── Data for the view ──────────────────────────────────────────────────── */
$csrf = $isAdmin && function_exists('av_csrf_token') ? av_csrf_token() : '';
$q = trim((string) ($_GET['q'] ?? ''));
$app = null;
$found = [];
if ($isAdmin) {
    if ($id > 0) $app = NgvMember::application($id);
    elseif ($q !== '') {
        $app = NgvRegistration::forDesk($q);
        if (!$app) {
            $like = '%' . mb_strtolower($q) . '%';
            $st = NgvDb::pdo()->prepare("SELECT * FROM ngv_applications WHERE (LOWER(name) LIKE ? OR LOWER(email) LIKE ? OR phone LIKE ?)
                                         AND status IN ('invited','accepted','booked','attended','enrolled') ORDER BY id DESC LIMIT 20");
            $st->execute([$like, $like, '%' . preg_replace('/\D/', '', $q) . '%']);
            $found = $st->fetchAll() ?: [];
            if (count($found) === 1) $app = $found[0];
        }
    }
}
$today = function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d');
$todayList = [];
$waiting = [];
if ($isAdmin && !$app) {
    $st = NgvDb::pdo()->prepare("SELECT b.slot_time, b.centre, b.status AS bstatus, a.id, a.name, a.ref, a.status, a.source
                                 FROM ngv_bookings b JOIN ngv_applications a ON a.id = b.application_id
                                 WHERE b.slot_date = ? AND b.status IN ('booked','attended') ORDER BY b.centre, b.slot_time, a.name");
    $st->execute([$today]);
    $todayList = $st->fetchAll() ?: [];
    $waiting = NgvDb::pdo()->query("SELECT id, name, ref, status, source, invited_at, centre FROM ngv_applications
                                    WHERE status IN ('invited','accepted','attended') ORDER BY invited_at DESC, id DESC LIMIT 40")->fetchAll() ?: [];
}
$state = $app ? NgvRegistration::deskState($app) : null;
$mouNow = (string) (Ngv::get()['mou']['version'] ?? '');
$feesOn = class_exists('NgvLedger') && NgvLedger::enabled();
$memAmt = $feesOn ? (int) (NgvLedger::amounts()['membership']['amount'] ?? 0) : 0;
$doneMsg = [
    'arrived' => 'Marked as here.', 'noshow' => 'Marked as a no-show.', 'ticked' => 'Checklist saved.',
    'guarantor' => 'Guarantor recorded as seen in person.', 'mou' => 'MoU recorded.',
    'registered' => 'Registered. They are a vanguard now, and their account and NGV card are made.',
][(string) ($_GET['done'] ?? '')] ?? '';
$e = 'e';
$relOpts = NgvRegistration::RELATIONSHIPS;
if ($state && $state['minor']) $relOpts = array_intersect_key($relOpts, array_flip(NgvRegistration::MINOR_RELATIONSHIPS));
$hidden = static fn(string $act) => '<input type="hidden" name="csrf" value="' . e($csrf) . '"><input type="hidden" name="id" value="' . (int) ($app['id'] ?? 0) . '"><input type="hidden" name="act" value="' . e($act) . '">';
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $app ? $e((string) ($app['ref'] ?: $app['name'])) . ' · ' : '' ?>Registration desk · NGV</title>
<link href="<?= e(av_asset('/assets/site/fonts.css')) ?>" rel="stylesheet" />
<style>
:root{--ink:#0f172a;--muted:#475569;--line:#d9dee6;--bg:#f5f6f8;--card:#fff;--ok:#166534;--okbg:#ecfdf3;--bad:#991b1b;--badbg:#fef2f2;--acc:#0f3d2e;--focus:#E0A82E}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,system-ui,sans-serif;color:var(--ink);background:var(--bg);font-size:15px;line-height:1.5}
a{color:var(--acc)}
:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
.top{display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:12px 20px;background:var(--acc);color:#fff}
.top h1{font-size:17px;margin:0}.top a{color:#fff}.top .sp{flex:1}
.wrap{max-width:980px;margin:20px auto 48px;padding:0 20px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px 18px;margin-bottom:14px}
.card h2{font-size:16px;margin:0 0 10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.sub{color:var(--muted);font-size:13.5px}
.note{border-radius:10px;padding:10px 14px;margin-bottom:14px}
.note.ok{background:var(--okbg);color:var(--ok);border:1px solid #bbf7d0}
.note.bad{background:var(--badbg);color:var(--bad);border:1px solid #fecaca}
.note ul{margin:6px 0 0;padding-left:18px}
.search{display:flex;gap:8px;flex-wrap:wrap}
.search input{flex:1 1 260px}
input[type=text],input[type=email],input[type=tel],input[type=number],select{min-height:40px;padding:8px 10px;border:1px solid var(--line);border-radius:9px;font:inherit;background:#fff;color:var(--ink)}
label.f{display:block;font-size:13.5px;font-weight:600;margin-bottom:10px}
label.f input,label.f select{display:block;width:100%;margin-top:4px;font-weight:400}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:4px 16px}
.btn{display:inline-flex;align-items:center;min-height:40px;padding:8px 14px;border-radius:9px;border:1px solid var(--line);background:#fff;color:var(--ink);font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
.btn.primary{background:var(--acc);border-color:var(--acc);color:#fff}
.btn:disabled{opacity:.55;cursor:not-allowed}
.chip{display:inline-block;font-size:12px;font-weight:700;padding:2px 8px;border-radius:99px;background:#eef2f7;color:#334155}
.chip.ok{background:var(--okbg);color:var(--ok)}.chip.warn{background:#fff7ed;color:#9a3412}
.facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px 16px;margin:0}
.facts dt{font-size:12.5px;color:var(--muted)}.facts dd{margin:0 0 4px;font-weight:600}
.checks{list-style:none;margin:0 0 12px;padding:0}
.checks li{padding:8px 0;border-bottom:1px solid #eef1f5}
.checks label{display:flex;gap:10px;align-items:flex-start;cursor:pointer}
.checks input{width:20px;height:20px;margin-top:2px;flex:none}
.done{color:var(--ok);font-weight:600}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{text-align:left;padding:8px 6px;border-bottom:1px solid #eef1f5;vertical-align:top}
th{font-size:12.5px;color:var(--muted)}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
fieldset{border:1px solid var(--line);border-radius:10px;padding:10px 14px;margin:0 0 12px}
legend{font-weight:700;font-size:13.5px;padding:0 4px}
.radio{display:flex;gap:8px;align-items:center;font-weight:600;font-size:14px;margin:4px 0 10px}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.gate{max-width:520px;margin:12vh auto;padding:0 20px;text-align:center}
@media(max-width:620px){.grid{grid-template-columns:1fr}.wrap{padding:0 14px}}
</style>
</head>
<body>
<?php if (!$isAdmin): ?>
  <div class="gate">
    <h1>Admin sign-in required</h1>
    <p>The registration desk completes NextGen Vanguard registrations. Sign in to the Academy Studio, then come back.</p>
    <p><a class="btn primary" href="/academy/studio/">Sign in at the Academy Studio →</a></p>
  </div>
<?php else: ?>
<header class="top">
  <h1>NextGen Vanguard · registration desk</h1>
  <span class="sp"></span>
  <a href="/academy/ngv/desk.php">Desk home</a>
  <a href="/academy/ngv/members.php#applications">Applications in the staff console</a>
</header>
<main class="wrap">
  <form class="card search" role="search" method="get" action="/academy/ngv/desk.php">
    <label class="sr-only" for="q">Reference, name, email or phone</label>
    <input id="q" type="text" name="q" value="<?= $e($q) ?>" placeholder="Reference on their MoU (NGV-…), or a name, email or phone" autocomplete="off" autofocus>
    <button class="btn primary" type="submit">Find applicant</button>
  </form>

  <?php if ($doneMsg !== ''): ?><div class="note ok" role="status"><?= $e($doneMsg) ?></div><?php endif; ?>
  <?php if ($err !== ''): ?>
    <div class="note bad" role="alert"><?= $e($errMissing ? 'Not registered yet — still needed:' : $err) ?>
      <?php if ($errMissing): ?><ul><?php foreach ($errMissing as $m): ?><li><?= $e((string) $m) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
  <?php endif; ?>

<?php if (!$app): ?>
  <?php if ($q !== ''): ?>
    <section class="card" aria-labelledby="h-found">
      <h2 id="h-found">Results for “<?= $e($q) ?>”</h2>
      <?php if (!$found): ?><p class="sub">Nobody invited or registered matches that. Check the reference on their MoU, or <a href="/academy/ngv/members.php#applications">find and invite them in the console</a> — they may not have been invited yet.</p>
      <?php else: ?>
        <table><thead><tr><th scope="col">Applicant</th><th scope="col">Reference</th><th scope="col">Status</th></tr></thead><tbody>
        <?php foreach ($found as $f): ?><tr><td><a href="?id=<?= (int) $f['id'] ?>"><?= $e((string) $f['name']) ?></a><br><span class="sub"><?= $e((string) $f['email']) ?></span></td><td><?= $e((string) ($f['ref'] ?: '—')) ?></td><td><?= $e((string) $f['status']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </section>
  <?php endif; ?>
  <section class="card" aria-labelledby="h-today">
    <h2 id="h-today">Booked for today · <?= $e(NgvRegistration::longDate($today)) ?></h2>
    <?php if (!$todayList): ?><p class="sub">No bookings today. Walk-ins are found by the reference on their MoU.</p>
    <?php else: ?>
      <table><thead><tr><th scope="col">Time</th><th scope="col">Applicant</th><th scope="col">Centre</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead><tbody>
      <?php foreach ($todayList as $b): ?><tr><td><?= $e((string) $b['slot_time']) ?></td>
        <td><a href="?id=<?= (int) $b['id'] ?>"><?= $e((string) $b['name']) ?></a> <span class="sub"><?= $e((string) $b['ref']) ?></span><?= NgvRegistration::isNgg($b) ? ' <span class="chip">from NGG</span>' : '' ?></td>
        <td><?= $e((string) $b['centre']) ?></td><td><?= $e((string) $b['status']) ?></td><td><a href="?id=<?= (int) $b['id'] ?>">Check them in</a></td></tr><?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </section>
  <section class="card" aria-labelledby="h-wait">
    <h2 id="h-wait">Invited, not yet registered</h2>
    <?php if (!$waiting): ?><p class="sub">Nobody is waiting to register.</p>
    <?php else: ?>
      <table><thead><tr><th scope="col">Applicant</th><th scope="col">Reference</th><th scope="col">Invited</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead><tbody>
      <?php foreach ($waiting as $w): ?><tr><td><a href="?id=<?= (int) $w['id'] ?>"><?= $e((string) $w['name']) ?></a><?= NgvRegistration::isNgg($w) ? ' <span class="chip">from NGG</span>' : '' ?></td>
        <td><?= $e((string) ($w['ref'] ?: '—')) ?></td><td><?= $e(substr((string) $w['invited_at'], 0, 10)) ?></td><td><?= $e((string) $w['status']) ?></td><td><a href="?id=<?= (int) $w['id'] ?>">Open at the desk</a></td></tr><?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </section>

<?php else: $st = (string) $app['status']; $aid = (int) $app['id']; $bk = $state['booking']; ?>
  <section class="card" aria-labelledby="h-who">
    <h2 id="h-who"><?= $e((string) $app['name']) ?> <span class="chip"><?= $e((string) ($app['ref'] ?: 'no reference')) ?></span>
      <span class="chip<?= $st === 'enrolled' ? ' ok' : '' ?>"><?= $e($st) ?></span>
      <?php if ($state['ngg']): ?><span class="chip">from NGG</span><?php endif; ?>
      <?php if ($state['minor']): ?><span class="chip warn">under 18</span><?php endif; ?></h2>
    <dl class="facts">
      <div><dt>Email</dt><dd><?= $e((string) $app['email']) ?></dd></div>
      <div><dt>Phone</dt><dd><?= $e((string) ($app['phone'] ?: '—')) ?></dd></div>
      <div><dt>Date of birth</dt><dd><?= $e((string) ($app['dob'] ?: '—')) ?></dd></div>
      <div><dt>Centre</dt><dd><?= $e((string) ($app['centre'] ?: '—')) ?></dd></div>
      <div><dt>Track</dt><dd><?= $e((string) ($app['track'] ?: '—')) ?></dd></div>
      <div><dt>Booking</dt><dd><?= $bk ? $e(NgvRegistration::longDate((string) $bk['slot_date']) . ', ' . $bk['slot_time'] . ' · ' . $bk['centre']) : 'Walk-in' ?></dd></div>
    </dl>
    <?php if ($st === 'enrolled'): ?>
      <p><span class="done">Registered<?= $app['registered_at'] ? ' on ' . $e(substr((string) $app['registered_at'], 0, 10)) : '' ?>.</span>
        <?php if ((int) $app['member_id'] > 0): ?><a class="btn" href="/academy/ngv/members.php?m=<?= (int) $app['member_id'] ?>">Open their account in the console</a><?php endif; ?></p>
    <?php elseif (!$state['servable']): ?>
      <p class="sub">This application is <b><?= $e($st) ?></b>, so it cannot be registered here.<?= in_array($st, ['new', 'reviewing', 'lapsed'], true) ? ' <a href="/academy/ngv/members.php#applications">Invite them to register from the console</a> first.' : ($st === 'rejected' ? ' It was rejected. A coordinator can <a href="/academy/ngv/members.php#applications">reopen it for review in the console</a>, then invite them.' : '') ?></p>
    <?php else: ?>
      <div class="row">
        <?php if ($st !== 'attended'): ?>
          <form method="post" action="/academy/ngv/desk.php"><?= $hidden('arrive') ?><button class="btn primary" type="submit">They are here</button></form>
        <?php else: ?><span class="done">Here today.</span><?php endif; ?>
        <?php if ($bk && $st === 'booked'): ?>
          <form method="post" action="/academy/ngv/desk.php"><?= $hidden('noshow') ?><button class="btn" type="submit">Did not come (no-show)</button></form>
        <?php endif; ?>
        <a class="btn" href="<?= $e(NgvRegistration::link((string) $app['ref'])) ?>" target="_blank" rel="noopener">Their application page ↗</a>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($state['servable']): ?>
  <section class="card" aria-labelledby="h-docs">
    <h2 id="h-docs">1. Documents seen</h2>
    <form method="post" action="/academy/ngv/desk.php"><?= $hidden('tick') ?>
      <ul class="checks">
      <?php foreach ($state['items'] as $k => $it): $on = !empty($state['ticks'][$k]['ok']); ?>
        <li><label><input type="checkbox" name="items[]" value="<?= $e((string) $k) ?>"<?= $on ? ' checked' : '' ?>>
          <span><b><?= $e($it['label']) ?></b><?= $it['required'] ? '' : ' <span class="sub">(if they have it)</span>' ?>
          <?php if (($it['desc'] ?? '') !== ''): ?><br><span class="sub"><?= $e((string) $it['desc']) ?></span><?php endif; ?></span></label></li>
      <?php endforeach; ?>
      </ul>
      <button class="btn" type="submit">Save checklist</button>
    </form>
  </section>

  <section class="card" aria-labelledby="h-g">
    <h2 id="h-g">2. Guarantor, in person <?= trim((string) $app['g_present_at']) !== '' ? '<span class="chip ok">seen ' . $e(substr((string) $app['g_present_at'], 0, 10)) . ' · ' . $e((string) $app['g_id_seen']) . '</span>' : '' ?></h2>
    <p class="sub">The guarantor vouches for their character and must be here, with ID. <?= $state['minor'] ? 'They are under 18, so their guarantor must be their parent or guardian — who consents here, in person.' : 'They are not liable for fees.' ?> Correct anything the form had wrong.</p>
    <form method="post" action="/academy/ngv/desk.php"><?= $hidden('guarantor') ?>
      <div class="grid">
        <label class="f">Name<input type="text" name="g_name" required maxlength="120" value="<?= $e((string) $app['g_name']) ?>"></label>
        <label class="f">How they know the applicant<select name="g_relationship" required>
          <option value="">Choose…</option>
          <?php foreach ($relOpts as $k => $l): ?><option value="<?= $e($k) ?>"<?= (string) $app['g_relationship'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
        </select></label>
        <label class="f">If other, what<input type="text" name="g_relationship_other" maxlength="80" value="<?= $e((string) $app['g_relationship_other']) ?>"></label>
        <label class="f">Phone<input type="tel" name="g_phone" required maxlength="40" value="<?= $e((string) $app['g_phone']) ?>"></label>
        <label class="f">Email<?= $state['minor'] ? '' : ' (if they have one)' ?><input type="email" name="g_email" maxlength="160"<?= $state['minor'] ? ' required' : '' ?> value="<?= $e((string) $app['g_email']) ?>"></label>
        <label class="f">ID seen<input type="text" name="g_id_seen" required maxlength="120" placeholder="e.g. NIN slip, driver’s licence, voter’s card" value="<?= $e((string) $app['g_id_seen']) ?>"></label>
      </div>
      <button class="btn" type="submit">Record the guarantor as seen</button>
    </form>
  </section>

  <section class="card" aria-labelledby="h-mou">
    <h2 id="h-mou">3. The signed MoU <?= trim((string) $app['mou_version']) !== '' ? '<span class="chip ok">' . $e((string) $app['mou_version']) . '</span>' : '' ?>
      <?= $state['scan'] ? '<a class="chip ok" href="?scan=' . $aid . '" target="_blank" rel="noopener">scan kept ↗</a>' : '' ?></h2>
    <p class="sub">Enter the version printed at the top of the paper they signed (the current one is <b><?= $e($mouNow ?: '—') ?></b>). Their programme fee is frozen on the terms of that MoU. A photo or scan of it is kept with their record.</p>
    <?php if ($mouNow !== '' && trim((string) $app['mou_version']) !== '' && (string) $app['mou_version'] !== $mouNow): ?>
      <p class="note bad">They signed <?= $e((string) $app['mou_version']) ?>, not the current <?= $e($mouNow) ?>. Ask them to sign the current one, printed from their application page.</p>
    <?php endif; ?>
    <form method="post" action="/academy/ngv/desk.php" enctype="multipart/form-data"><?= $hidden('mou') ?>
      <div class="grid">
        <label class="f">Version on the paper<input type="text" name="mou_version" required maxlength="40" value="<?= $e((string) ($app['mou_version'] ?: $mouNow)) ?>"></label>
        <label class="f">Photo or scan (JPEG, PNG or PDF, up to 10MB)<input type="file" name="scan" accept="image/jpeg,image/png,application/pdf"></label>
      </div>
      <button class="btn" type="submit">Save MoU version and scan</button>
    </form>
  </section>

  <section class="card" aria-labelledby="h-done">
    <h2 id="h-done">4. Complete registration</h2>
    <?php if ($state['missing']): ?>
      <p class="sub">Still needed before they can be registered:</p>
      <ul><?php foreach ($state['missing'] as $m): ?><li><?= $e((string) $m) ?></li><?php endforeach; ?></ul>
    <?php else: ?>
      <p class="done">Everything required is done.</p>
    <?php endif; ?>
    <form method="post" action="/academy/ngv/desk.php"><?= $hidden('complete') ?>
      <?php if ($feesOn): ?>
        <fieldset><legend>Membership fee (₦<?= number_format($memAmt) ?>), taken today</legend>
          <label class="radio"><input type="radio" name="fee_how" value="paid" checked> Paid now</label>
          <div class="grid">
            <label class="f">Amount received (₦)<input type="number" name="fee_amount" min="0" step="1" value="<?= $memAmt ?>"></label>
            <label class="f">How<select name="fee_method"><option value="cash">Cash</option><option value="transfer">Bank transfer</option><option value="pos">POS</option></select></label>
            <label class="f">Reference (transfer or POS)<input type="text" name="fee_reference" maxlength="80"></label>
          </div>
          <label class="radio"><input type="radio" name="fee_how" value="waived"> Waived</label>
          <label class="f">Reason for the waiver<input type="text" name="fee_reason" maxlength="200"></label>
        </fieldset>
      <?php endif; ?>
      <p class="sub">Completing makes their account (or links the one their email already has), enrols them, issues their NGV card<?= $state['minor'] ? ', records their parent or guardian’s consent as given in person' : '' ?>, and freezes their programme fee on the MoU they signed. It cannot be undone from here.</p>
      <button class="btn primary" type="submit"<?= $state['missing'] ? ' disabled aria-describedby="h-done-missing"' : '' ?>>Complete registration</button>
      <?php if ($state['missing']): ?><p class="sub" id="h-done-missing">Disabled until everything listed under “Still needed” above is done.</p><?php endif; ?>
    </form>
  </section>
  <?php endif; ?>
<?php endif; ?>
</main>
<?php endif; ?>
</body>
</html>
