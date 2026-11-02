<?php
/**
 * academy/ngv/fines.php — NextGen Vanguard fines, for staff.
 *
 * One desk for fines (lib/NgvFines.php): what is owing, every fine with where
 * it stands, issuing a fine to one member or a whole group, the usual amount
 * for each reason, and importing fines from a spreadsheet — each row assigned
 * to the member it is for — any Afrovanguard member, NGV or not (NgvFines) — and any row that could be two people shown with
 * the candidates for a person to choose. The import sheet can be corrected
 * right here, row by row, and checked again before anything is charged.
 *
 * The money is the NGV ledger's: paying a fine is a payment on the Vanguards
 * page, like any other. Admin-gated exactly like members.php; JSON actions
 * POST to this same URL, guarded by admin + same-origin + CSRF.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$role    = function_exists('av_admin_role') ? av_admin_role() : '';
/* Money and the roster: fees, payments, waivers, fines, enrolment, cards.
   Administrators only, as admin/api.php keeps offline payments ("they move
   money"). `editor` is a content role and used to pass this page's check. */
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
$by      = (int) (LmsAuth::user()['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$isAdmin) json_out(['ok' => false, 'error' => 'Admin sign-in required.'], 403);
    require_same_origin();
    av_csrf_require();

    /* A fine payment, with its receipt: multipart, exactly as the member desk
       records one (academy/ngv/members.php, payment_offline). The money is
       credited only once lib/OfflinePayments has read the receipt and checked
       it against the claim — never from the figure somebody typed. */
    if ((string) ($_POST['action'] ?? '') === 'pay_offline') {
        $f = $_FILES['evidence'] ?? null;
        $okf = $f && (int) ($f['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file((string) $f['tmp_name']);
        $r = NgvFines::payOne((int) ($_POST['id'] ?? 0), $_POST['amount'] ?? '', strtolower(trim((string) ($_POST['method'] ?? ''))),
            (string) ($_POST['reference'] ?? ''), $by,
            $okf ? ['bytes' => (string) file_get_contents((string) $f['tmp_name']), 'mime' => (string) (Storage::mime((string) $f['tmp_name']) ?: '')] : [],
            av_admin_actor(), (string) ($_POST['paid_on'] ?? ''));
        json_out($r, empty($r['ok']) ? 400 : 200);
    }

    $in  = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $act = (string) ($in['action'] ?? '');
    if ($act === 'people') json_out(['ok' => true, 'people' => NgvFines::people((string) ($in['q'] ?? ''))]);
    if ($act === 'issue') {
        json_out(NgvFines::issueMany((array) ($in['members'] ?? []), (string) ($in['reason'] ?? ''), $in['amount'] ?? '', (string) ($in['note'] ?? ''),
            (string) ($in['day'] ?? ''), !empty($in['notify']), $by));
    }
    if ($act === 'waive') json_out(NgvFines::waiveOne((int) ($in['id'] ?? 0), (string) ($in['why'] ?? ''), $by));
    if ($act === 'void') json_out(NgvFines::voidOne((int) ($in['id'] ?? 0), (string) ($in['why'] ?? ''), $by));
    /* The old typed-in payment. Kept as a route so an old tab says what to do
       rather than failing as "Unknown action". */
    if ($act === 'pay') json_out(['ok' => false, 'code' => 'evidence_required',
        'error' => 'Attach the receipt — a fine payment is credited once its evidence is checked.'], 400);
    if ($act === 'catalogue') {
        $r = NgvFines::saveCatalogue((array) ($in['amounts'] ?? []));
        if ($r['ok'] && class_exists('AdminAudit')) AdminAudit::log('ngv', 'ngv_fine_catalogue', 'ngv:fines', 'Usual fine amounts changed');
        json_out($r);
    }
    if ($act === 'import') {
        $rows = $in['rows'] ?? null;
        $cols = null; $unknown = [];
        $readAs = '';
        if (!is_array($rows)) {
            /* A workbook, a CSV with or without headings, or a typed list (NgvFines::parseAny). */
            $p = NgvFines::parseAny((string) ($in['csv'] ?? ''), (string) ($in['xlsx'] ?? ''));
            if (!$p['ok']) json_out($p);
            $rows = $p['rows']; $cols = $p['columns']; $unknown = $p['unknown_columns']; $readAs = (string) ($p['read_as'] ?? '');
        }
        if (count($rows) > NgvFines::MAX_ROWS) json_out(['ok' => false, 'error' => 'Too many rows in one import.']);
        $rows = array_map(static fn($r) => array_intersect_key(array_merge(array_fill_keys(NgvFines::COLUMNS, ''), array_map('strval', (array) $r)), array_flip(NgvFines::COLUMNS)), array_values($rows));
        $r = NgvFines::import($rows, ['apply' => !empty($in['apply']), 'expect_digest' => (string) ($in['digest'] ?? ''), 'notify' => !empty($in['notify']),
                                      'by' => $by, 'source' => (string) ($in['source'] ?? '')]);
        json_out($r + ['sheet' => $rows, 'columns' => $cols, 'unknown_columns' => $unknown, 'read_as' => $readAs]);
    }
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$e   = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$nm  = static fn(int $n): string => '₦' . number_format($n);
$today = function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d');
$f = [
    'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80),
    'reason' => isset(NgvLedger::FINE_REASONS[(string) ($_GET['reason'] ?? '')]) ? (string) $_GET['reason'] : '',
    'status' => in_array((string) ($_GET['status'] ?? ''), ['open', 'owing', 'part', 'settled', 'waived', 'voided'], true) ? (string) $_GET['status'] : '',
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '',
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : '',
    'source' => ($_GET['source'] ?? '') === 'import' ? 'import' : '',
];
$filtered = array_filter($f, static fn($v) => $v !== '') !== [];
$list = $isAdmin ? NgvFines::all($f) : [];
$sum  = $isAdmin ? NgvFines::summary() : [];
$cat  = $isAdmin ? NgvFines::catalogue() : [];
$runs = $isAdmin ? NgvDb::pdo()->query('SELECT * FROM ngv_fine_imports ORDER BY id DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC) : [];
$shown = array_slice($list, 0, 300);
$listTotal = array_sum(array_map(static fn($r) => (int) $r['owing'], $list));
$stLabel = ['owing' => 'Owing', 'part' => 'Part paid', 'settled' => 'Settled', 'waived' => 'Waived', 'voided' => 'Voided'];
$csrf = av_csrf_token();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Fines · NGV staff</title>
  <link href="<?= e(av_asset('/assets/site/fonts.css')) ?>" rel="stylesheet" />
<style>
:root{--red:#e4162b;--orange:#ff6a1a;--gold:#ffb703;--ink:#15120e;--line:#e7e9ee;--muted:#5f6874;--bg:#f5f6f8;--card:#fff;
  --green:#137a3a;--green-bg:#e6f7ec;--amber:#9a5b00;--amber-bg:#fffaf0;--danger:#c0322b;--danger-bg:#fdecec;--blue:#1d4ed8;--blue-bg:#eef4ff;--r:14px}
*{box-sizing:border-box}
body{margin:0;font-family:'Source Sans 3',system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.5}
a{color:var(--red)}
h1,h2,h3{margin:0;font-weight:800;letter-spacing:-.01em}
:focus-visible{outline:3px solid var(--orange);outline-offset:2px}
.top{position:sticky;top:0;z-index:20;background:rgba(21,18,14,.97);color:#fff;display:flex;gap:12px;align-items:center;padding:10px 20px;flex-wrap:wrap}
.top h1{font-size:.95rem}.top h1 b{color:var(--gold)}.top h1 span{font-weight:600;color:rgba(255,255,255,.7)}
.top .sp{flex:1}.top a{color:rgba(255,255,255,.85);text-decoration:none;font-weight:600;font-size:.86rem}
.top a[aria-current]{color:#fff;border-bottom:2px solid var(--gold)}
.msg{font-size:.85rem;font-weight:700}
.wrap{max-width:1180px;margin:22px auto 56px;padding:0 16px;display:flex;flex-direction:column;gap:18px}
.gatebox{max-width:520px;margin:12vh auto;background:#fff;border:1px solid var(--line);border-radius:18px;padding:36px;text-align:center}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);overflow:hidden}
.card>header{padding:13px 18px;border-bottom:1px solid var(--line);display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.card>header h2{font-size:1rem}.card>header .sp{flex:1}
.card>.body{padding:14px 18px 18px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
.stat{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:12px 16px}
.stat .k{font-size:.75rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.stat .v{font-size:1.5rem;font-weight:800;font-variant-numeric:tabular-nums}
.stat .s{font-size:.8rem;color:var(--muted)}
table{width:100%;border-collapse:collapse;font-size:.88rem}
th,td{text-align:left;padding:.45rem .55rem;border-top:1px solid var(--line);vertical-align:top}
th{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;border-top:0;white-space:nowrap}
td.num,th.num{font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap}
.pill{display:inline-block;border-radius:999px;padding:.05rem .55rem;font-size:.76rem;font-weight:700;white-space:nowrap}
.s-owing{background:var(--danger-bg);color:var(--danger)}.s-part{background:var(--amber-bg);color:var(--amber)}
.s-settled{background:var(--green-bg);color:var(--green)}.s-waived{background:var(--blue-bg);color:var(--blue)}.s-voided{background:#eef0f3;color:var(--muted);text-decoration:line-through}
.s-ready,.s-fined{background:var(--green-bg);color:var(--green)}.s-ambiguous{background:var(--amber-bg);color:var(--amber)}
.s-unmatched,.s-invalid{background:var(--danger-bg);color:var(--danger)}.s-already,.s-duplicate{background:#eef0f3;color:var(--muted)}
.btn{font:inherit;font-size:.82rem;font-weight:700;border:1px solid var(--line);background:#fff;border-radius:9px;padding:.4rem .75rem;cursor:pointer;color:var(--ink);text-decoration:none;display:inline-block}
.btn.primary{background:var(--ink);color:#fff;border-color:var(--ink)}
.btn.sm{font-size:.76rem;padding:.2rem .5rem}
.btn:disabled{opacity:.5;cursor:not-allowed}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.row label,.lbl{font-size:.78rem;font-weight:700;color:var(--muted);display:flex;flex-direction:column;gap:4px}
input,select,textarea{font:inherit;font-size:.88rem;padding:.42rem .55rem;border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--ink);min-width:0}
textarea{width:100%;min-height:7rem;font-family:ui-monospace,monospace;font-size:.8rem}
.check{flex-direction:row!important;align-items:center;gap:6px!important;font-weight:600!important}
.sub{color:var(--muted);font-size:.84rem}
.note{background:var(--amber-bg);border:1px solid #f2c98a;border-radius:12px;padding:.7rem .9rem;font-size:.9rem;margin:0}
.scroll{overflow-x:auto}
.chips{display:flex;flex-wrap:wrap;gap:6px;min-height:1.8rem}
.chip{background:#f1f2f5;border-radius:999px;padding:.15rem .3rem .15rem .65rem;font-size:.82rem;font-weight:600;display:inline-flex;gap:4px;align-items:center}
.chip button{border:0;background:none;cursor:pointer;font-size:1rem;line-height:1;color:var(--muted)}
.picker{position:relative;flex:1;min-width:16rem}
.picker input{width:100%}
.found{position:absolute;left:0;right:0;top:100%;z-index:5;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.08);margin-top:4px;max-height:16rem;overflow:auto}
.found button{display:block;width:100%;text-align:left;border:0;background:none;padding:.45rem .7rem;font:inherit;font-size:.86rem;cursor:pointer}
.found button:hover,.found button:focus{background:#f5f6f8}
.found small{color:var(--muted);display:block}
.grid input,.grid select{width:100%;font-size:.8rem;padding:.25rem .35rem;border-radius:7px}
.grid td{padding:.3rem .3rem;min-width:6.5rem}
.grid td.w{min-width:10rem}
.grid tr.bad td{background:#fff8f7}
.grid tr.warn td{background:#fffdf5}
.why{font-size:.76rem;color:var(--muted);margin-top:3px}
.why.err{color:var(--danger)}
.tabs{display:flex;gap:6px;flex-wrap:wrap}
.tabs a{font-size:.8rem;font-weight:700;padding:.25rem .65rem;border-radius:999px;border:1px solid var(--line);text-decoration:none;color:var(--ink);background:#fff}
.tabs a.on{background:var(--ink);color:#fff;border-color:var(--ink)}
.cat{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
dialog{border:1px solid var(--line);border-radius:var(--r);padding:0;max-width:30rem;width:calc(100% - 2rem);color:var(--ink);background:var(--card)}
dialog::backdrop{background:rgba(21,18,14,.45)}
dialog header{padding:13px 18px;border-bottom:1px solid var(--line);display:flex;gap:10px;align-items:center}
dialog header h2{font-size:1rem;flex:1}
dialog .body{padding:14px 18px 18px;display:flex;flex-direction:column;gap:10px}
dialog .lbl input,dialog .lbl select{width:100%}
@media (max-width:640px){.top{padding:10px 16px}.wrap{padding:0 12px}.card>.body{padding:12px}}
</style>
</head>
<body>
<?php if (!$isAdmin): ?>
  <div class="gatebox">
    <h1>Admin sign-in required</h1>
    <p>This page holds NextGen Vanguard fines. Sign in to the Academy Studio, then come back.</p>
    <p><a href="/academy/studio/">Sign in at the Academy Studio →</a></p>
  </div>
<?php else: ?>
<header class="top">
  <h1><b>NextGen Vanguard</b> <span>· fines</span></h1>
  <span class="sp"></span>
  <a href="/academy/ngv/members.php">Vanguards</a>
  <a href="/academy/ngv/attendance.php">Attendance</a>
  <a href="/academy/ngv/fines.php" aria-current="page">Fines</a>
  <a href="/academy/ngv/books.php">Book list</a>
  <span class="msg" id="msg" role="status" aria-live="polite"></span>
</header>
<main class="wrap">
  <section class="stats" aria-label="Fines at a glance">
    <div class="stat"><div class="k">Outstanding</div><div class="v"><?= $nm((int) $sum['outstanding']) ?></div><div class="s"><?php if ((int) $sum['owing_members'] > 0): ?><a href="?status=open#list">See the <?= (int) $sum['owing_members'] ?> member<?= (int) $sum['owing_members'] === 1 ? '' : 's' ?> owing</a><?php else: ?>nobody owing<?php endif; ?></div></div>
    <div class="stat"><div class="k">This month</div><div class="v"><?= $nm((int) $sum['this_month']) ?></div><div class="s"><?= (int) $sum['this_month_n'] ?> fine<?= (int) $sum['this_month_n'] === 1 ? '' : 's' ?> issued</div></div>
    <div class="stat"><div class="k">Settled</div><div class="v"><?= (int) $sum['settled'] ?></div><div class="s">paid in full</div></div>
    <div class="stat"><div class="k">Waived</div><div class="v"><?= (int) $sum['waived'] ?></div><div class="s">set aside, kept on record</div></div>
  </section>

  <section class="card" aria-labelledby="h-issue" id="issue">
    <header><h2 id="h-issue">Issue a fine</h2><span class="sp"></span><span class="sub">to one member, or a whole group at once — NGV or not</span></header>
    <div class="body">
      <div class="row">
        <div class="lbl picker">Who
          <input id="i_find" placeholder="Type a name, email or NGV ID" autocomplete="off" aria-controls="i_found">
          <div class="found" id="i_found" hidden></div>
        </div>
      </div>
      <div class="chips" id="i_chips" aria-live="polite" style="margin:8px 0 12px"></div>
      <div class="row">
        <label>Reason <select id="i_reason"><?php foreach ($cat as $k => $c): ?><option value="<?= $e($k) ?>" data-amount="<?= (int) $c['amount'] ?>"><?= $e($c['label']) ?></option><?php endforeach; ?></select></label>
        <label>Amount (₦) <input id="i_amount" inputmode="numeric" size="9" placeholder="usual"></label>
        <label>Day it happened <input id="i_day" type="date" max="<?= $e($today) ?>" value="<?= $e($today) ?>"></label>
        <label style="flex:1;min-width:14rem">Note <input id="i_note" maxlength="160" placeholder="What happened (needed for “Other”)"></label>
      </div>
      <div class="row" style="margin-top:10px">
        <label class="check"><input type="checkbox" id="i_notify" checked> Tell them (email and portal)</label>
        <span class="sp" style="flex:1"></span>
        <button class="btn primary" id="i_go" type="button" disabled>Issue fine</button>
      </div>
      <p class="sub" id="i_out" role="status"></p>
    </div>
  </section>

  <section class="card" aria-labelledby="h-imp" id="import">
    <header><h2 id="h-imp">Import fines</h2><span class="sp"></span><a class="btn sm" id="tpl" href="#" download="ngv-fines.csv">Download a template</a></header>
    <div class="body">
      <p class="sub" style="margin-top:0">An Excel workbook, a CSV, or a list pasted from anywhere — WhatsApp, a note, an email — one fine a line (“Tunde Bakare – late – 12 Oct – 2k”). Columns are read by their headings, or worked out from what is in them when there are none. Say who with any of <b>NGV ID</b>, <b>Email</b>, <b>Phone</b> or <b>Name</b> — each row is matched to the member it is for — any Afrovanguard member, NGV or not — close spellings included. A row that could be two people is never guessed: you choose. <b>Amount</b> blank takes the usual amount; <b>Reason</b> can be plain words (“came in late”, “no ID card”); <b>Date</b> blank is today. Nothing is charged until you have checked the sheet and press Import. A fine the gate or the desk already gave for the same thing that day is not charged twice; someone fined for the same thing often is pointed out; and when you choose who a row is for, the next sheet that says it the same way is matched for you.</p>
      <div class="row">
        <label>File <input type="file" id="m_file" accept=".xlsx,.csv,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></label>
        <label class="check"><input type="checkbox" id="m_notify"> Tell each member (email and portal)</label>
      </div>
      <details style="margin-top:10px"><summary class="sub" style="cursor:pointer">…or paste it — a sheet or a typed list</summary>
        <textarea id="m_csv" placeholder="Name,NGV ID,Amount,Reason,Date,Note&#10;Adebayo Bello,A-NGV-25-0001,,late,29/09/2026,"></textarea>
      </details>
      <div class="row" style="margin-top:10px"><button class="btn primary" id="m_check" type="button">Check the sheet</button><span class="sub" id="m_out" role="status"></span></div>
      <div id="m_result" hidden style="margin-top:14px">
        <div class="tabs" id="m_tabs" role="tablist" aria-label="Show rows"></div>
        <div class="scroll" style="margin-top:10px"><table class="grid" id="m_grid" aria-label="The sheet, editable"></table></div>
        <div class="row" style="margin-top:12px">
          <span class="sub" id="m_dirty" hidden>You changed the sheet — check it again before importing.</span>
          <span class="sp" style="flex:1"></span>
          <button class="btn" id="m_recheck" type="button">Check again</button>
          <button class="btn primary" id="m_apply" type="button" disabled>Import</button>
        </div>
      </div>
      <?php if ($runs): ?>
      <h3 style="font-size:.85rem;margin:18px 0 6px">Recent imports</h3>
      <div class="scroll"><table><thead><tr><th>When</th><th>File</th><th class="num">Fined</th><th class="num">Total</th><th class="num">Not charged</th></tr></thead><tbody>
      <?php foreach ($runs as $r): ?>
        <tr><td class="sub"><?= $e(substr((string) $r['created_at'], 0, 16)) ?></td><td><?= $e((string) $r['source'] ?: '—') ?></td><td class="num"><?= (int) $r['fined'] ?></td><td class="num"><?= $nm((int) $r['total']) ?></td><td class="num"><?= (int) $r['skipped'] ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
  </section>

  <section class="card" aria-labelledby="h-list" id="list">
    <header><h2 id="h-list">All fines</h2><span class="sp"></span><span class="sub"><?= count($list) ?> fine<?= count($list) === 1 ? '' : 's' ?><?= $filtered ? ' match' : '' ?> · <?= $nm($listTotal) ?> owing</span></header>
    <div class="body">
      <form class="row" method="get" action="#list" aria-label="Filter fines">
        <label style="flex:1;min-width:12rem">Search <input name="q" value="<?= $e($f['q']) ?>" placeholder="Name, email or note"></label>
        <label>Reason <select name="reason"><option value="">Any</option><?php foreach (NgvLedger::FINE_REASONS as $k => $l): ?><option value="<?= $e($k) ?>"<?= $f['reason'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
        <label>Standing <select name="status"><option value="">Any</option><option value="open"<?= $f['status'] === 'open' ? ' selected' : '' ?>>Still owing</option><?php foreach ($stLabel as $k => $l): ?><option value="<?= $e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
        <label>From <input type="date" name="from" value="<?= $e($f['from']) ?>"></label>
        <label>To <input type="date" name="to" value="<?= $e($f['to']) ?>"></label>
        <label class="check"><input type="checkbox" name="source" value="import"<?= $f['source'] ? ' checked' : '' ?>> Imported</label>
        <button class="btn" type="submit">Show fines</button>
        <?php if ($filtered): ?><a class="btn" href="?#list">Clear filters</a><?php endif; ?>
      </form>
      <div class="scroll" style="margin-top:12px">
      <?php if (!$list): ?><p class="sub"><?= $filtered ? 'No fine matches.' : 'No fines yet.' ?></p><?php else: ?>
      <table>
        <thead><tr><th>Day</th><th>Member</th><th>Reason</th><th class="num">Amount</th><th class="num">Owing</th><th>Standing</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($shown as $r): $s = (string) $r['status']; $open = in_array($s, ['owing', 'part'], true);
              /* Nobody settles their own account, so the controls that would
                 are disabled here and say why rather than refusing on press. */
              $mine = $by > 0 && (int) $r['member_id'] === $by;
              $no = $mine ? ' disabled title="This is your own fine. Another member of staff records a payment, waives it or voids it."' : ''; ?>
          <tr>
            <td class="num" style="text-align:left"><?= $e((string) $r['day']) ?></td>
            <td><?= $e((string) ($r['name'] ?: '#' . $r['member_id'])) ?><?= $r['import_run'] ? ' <span class="sub" title="Imported">· imp</span>' : '' ?></td>
            <td><?= $e((string) $r['note'] ?: $r['label']) ?><?= $s === 'voided' && $r['void_reason'] ? '<div class="why">Voided: ' . $e((string) $r['void_reason']) . '</div>' : '' ?></td>
            <td class="num"><?= $nm((int) $r['amount']) ?></td>
            <td class="num"><?= $open ? $nm((int) $r['owing']) : '—' ?></td>
            <td><span class="pill s-<?= $e($s) ?>"><?= $e($stLabel[$s] ?? $s) ?></span></td>
            <td style="white-space:nowrap"><?php if ($open): ?><button class="btn sm" data-pay="<?= (int) $r['id'] ?>" data-owing="<?= (int) $r['owing'] ?>"<?= $no ?>>Record payment</button> <button class="btn sm" data-waive="<?= (int) $r['id'] ?>"<?= $no ?>>Waive fine</button> <?php endif; ?><?php if ($s !== 'voided'): ?><button class="btn sm" data-void="<?= (int) $r['id'] ?>"<?= $no ?>>Void fine</button><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($list) > count($shown)): ?><p class="sub">Showing the newest <?= count($shown) ?>. Narrow the filters to see others.</p><?php endif; ?>
      <?php endif; ?>
      </div>
      <p class="sub" style="margin-bottom:0"><b>Waive</b> sets aside what is left of a fine, with a reason — it stays on record. <b>Void</b> says it should never have been issued. <b>Record payment</b> takes money paid at the office or by transfer against a fine — for any member, NGV or not — and goes with the receipt, which is read and checked before a naira is credited; a vanguard's payments can also be recorded on their account on the <a href="/academy/ngv/members.php">Vanguards</a> page.</p>
    </div>
  </section>

  <section class="card" aria-labelledby="h-cat" id="catalogue">
    <header><h2 id="h-cat">Usual amounts</h2><span class="sp"></span><span class="sub">a blank amount takes these</span></header>
    <div class="body">
      <div class="cat">
      <?php foreach ($cat as $k => $c): ?>
        <label class="lbl"><?= $e($c['label']) ?> <input data-cat="<?= $e($k) ?>" inputmode="numeric" value="<?= (int) $c['amount'] ?: '' ?>" placeholder="priced each time"></label>
      <?php endforeach; ?>
      </div>
      <div class="row" style="margin-top:12px"><button class="btn primary" id="c_save" type="button">Save amounts</button><span class="sub" id="c_out" role="status"></span></div>
    </div>
  </section>
</main>

<!-- Money against a fine. The receipt is the point: it is read and checked
     before anything is credited, so there is no field here that simply
     asserts the money arrived. -->
<dialog id="payDlg" aria-labelledby="payTitle">
  <form method="dialog" id="payForm">
    <header><h2 id="payTitle">Record a payment</h2><button class="btn sm" value="cancel" type="submit" aria-label="Close">Close</button></header>
    <div class="body">
      <p class="sub" id="payWho" style="margin:0"></p>
      <label class="lbl">How much was paid (₦) <input id="pay_amount" inputmode="numeric" required></label>
      <label class="lbl">How it was paid
        <select id="pay_method">
          <option value="cash">Cash</option><option value="transfer">Transfer</option><option value="pos">POS</option>
          <option value="deposit">Bank deposit</option><option value="cheque">Cheque</option><option value="other">Other</option>
        </select>
      </label>
      <label class="lbl">Reference on the receipt, if there is one <input id="pay_ref" placeholder="Transfer reference, receipt book number"></label>
      <label class="lbl">Day it was paid <input id="pay_day" type="date"></label>
      <label class="lbl">The receipt, transfer slip or bank alert — required <input id="pay_file" type="file" accept="image/*,application/pdf" required></label>
      <p class="sub" style="margin:0">It is read and checked against what you have typed. If it does not match, the payment is held with the reason rather than credited.</p>
      <div class="row"><button class="btn primary" id="pay_go" value="go" type="submit">Check the receipt and record it</button></div>
      <p class="sub" id="pay_out" role="status" style="margin:0"></p>
    </div>
  </form>
</dialog>

<script>
(function () {
  var CSRF = <?= json_encode($csrf) ?>;
  var REASONS = <?= json_encode(array_map(static fn($c) => $c['label'], $cat), JSON_UNESCAPED_UNICODE) ?>;
  var msg = document.getElementById('msg'), mt;
  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) { return '₦' + Number(n || 0).toLocaleString('en-NG'); }
  function toast(t, good) { msg.textContent = t; msg.style.color = good === false ? '#ffb4ab' : '#9be7b4'; clearTimeout(mt); mt = setTimeout(function () { msg.textContent = ''; }, 3500); }
  function post(body) {
    return fetch(location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unreadable reply.' }; }); })
      .catch(function () { return { ok: false, error: 'No connection.' }; });
  }
  /* A people search, shared by the issue form and the import grid. */
  function picker(input, box, onPick) {
    var t, seq = 0;
    input.addEventListener('input', function () {
      clearTimeout(t); var q = input.value.trim();
      if (q.length < 2) { box.hidden = true; return; }
      t = setTimeout(function () {
        var mine = ++seq;
        post({ action: 'people', q: q }).then(function (r) {
          if (mine !== seq) return;
          var list = (r && r.people) || [];
          box.innerHTML = list.length ? list.map(function (p) { return '<button type="button" data-id="' + p.id + '" data-name="' + esc(p.name) + '">' + esc(p.name) + '<small>' + esc(p.detail) + '</small></button>'; }).join('') : '<div class="sub" style="padding:.5rem .7rem">Nobody by that.</div>';
          box.hidden = false;
        });
      }, 180);
    });
    box.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-id]'); if (!b) return;
      onPick({ id: +b.getAttribute('data-id'), name: b.getAttribute('data-name') });
      box.hidden = true; input.value = ''; input.focus();
    });
    input.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') box.hidden = true; if (ev.key === 'ArrowDown') { var f = box.querySelector('button'); if (f) { ev.preventDefault(); f.focus(); } } });
    document.addEventListener('click', function (ev) { if (!box.contains(ev.target) && ev.target !== input) box.hidden = true; });
  }

  /* ── Issue ── */
  var chosen = [];
  function drawChips() {
    $('i_chips').innerHTML = chosen.length ? chosen.map(function (p, i) { return '<span class="chip">' + esc(p.name) + '<button type="button" aria-label="Remove ' + esc(p.name) + '" data-rm="' + i + '">×</button></span>'; }).join('') : '<span class="sub">Nobody chosen yet.</span>';
    $('i_go').disabled = !chosen.length;
    $('i_go').textContent = chosen.length > 1 ? 'Fine ' + chosen.length + ' members' : 'Issue fine';
  }
  $('i_chips').addEventListener('click', function (ev) { var b = ev.target.closest('[data-rm]'); if (b) { chosen.splice(+b.getAttribute('data-rm'), 1); drawChips(); } });
  picker($('i_find'), $('i_found'), function (p) { if (!chosen.some(function (c) { return c.id === p.id; })) chosen.push(p); drawChips(); });
  function usual() { var o = $('i_reason').selectedOptions[0]; var a = +o.getAttribute('data-amount'); $('i_amount').placeholder = a ? 'usual ' + a.toLocaleString('en-NG') : 'enter an amount'; }
  $('i_reason').addEventListener('change', usual); usual(); drawChips();
  $('i_go').addEventListener('click', function () {
    var btn = this; btn.disabled = true;
    post({ action: 'issue', members: chosen.map(function (c) { return c.id; }), reason: $('i_reason').value, amount: $('i_amount').value.replace(/[^\d.]/g, ''),
           day: $('i_day').value, note: $('i_note').value, notify: $('i_notify').checked }).then(function (r) {
      btn.disabled = false;
      if (!r.ok) { $('i_out').textContent = r.error || 'Not issued.'; return; }
      var bad = (r.results || []).filter(function (x) { return !x.ok; });
      $('i_out').textContent = r.fined + ' fined · ' + money(r.total) + (bad.length ? ' — not fined: ' + bad.map(function (x) { return x.name + ' (' + x.error + ')'; }).join('; ') : '');
      toast(r.fined ? 'Fine issued' : 'Nothing issued', r.fined > 0);
      if (r.fined && !bad.length) setTimeout(function () { location.hash = 'list'; location.reload(); }, 900);
    });
  });

  /* ── Waive / void ── */
  document.querySelectorAll('[data-waive],[data-void]').forEach(function (b) {
    b.addEventListener('click', function () {
      var waive = b.hasAttribute('data-waive');
      var why = prompt(waive ? 'Why is this fine waived? The reason is kept on the record.' : 'Why should this fine never have been issued?');
      if (why === null) return;
      post({ action: waive ? 'waive' : 'void', id: +b.getAttribute(waive ? 'data-waive' : 'data-void'), why: why }).then(function (r) {
        if (r.ok) location.reload(); else toast(r.error || 'Not saved.', false);
      });
    });
  });

  /* ── A payment against one fine: at the office, by transfer ──
     Multipart, because the receipt goes with it. The money is credited by
     lib/OfflinePayments once the receipt has been read and checked; a receipt
     that does not match is HELD, with the reasons shown here. */
  var payDlg = $('payDlg'), payFor = 0, payBtn = null;
  document.querySelectorAll('[data-pay]').forEach(function (b) {
    b.addEventListener('click', function () {
      payFor = +b.getAttribute('data-pay'); payBtn = b;
      var owing = +b.getAttribute('data-owing');
      var row = b.closest('tr');
      $('payWho').textContent = (row ? row.children[1].textContent.trim() + ' — ' : '') + money(owing) + ' owing on this fine. Like every payment it comes off their oldest fine first.';
      $('pay_amount').value = String(owing);
      $('pay_ref').value = ''; $('pay_day').value = ''; $('pay_file').value = ''; $('pay_out').textContent = '';
      payDlg.showModal();
      $('pay_amount').focus();
    });
  });
  payDlg.addEventListener('close', function () { if (payBtn) payBtn.focus(); });
  $('payForm').addEventListener('submit', function (ev) {
    if (ev.submitter && ev.submitter.value !== 'go') return;          // Close
    ev.preventDefault();
    var file = $('pay_file').files && $('pay_file').files[0];
    if (!file) { $('pay_out').textContent = 'Attach the receipt — it is checked before anything is credited.'; return; }
    var amt = $('pay_amount').value.replace(/[^\d.]/g, '');
    if (!amt) { $('pay_out').textContent = 'How much was paid?'; return; }
    var fd = new FormData();
    fd.append('action', 'pay_offline'); fd.append('id', String(payFor)); fd.append('amount', amt);
    fd.append('method', $('pay_method').value); fd.append('reference', $('pay_ref').value);
    fd.append('paid_on', $('pay_day').value); fd.append('evidence', file);
    $('pay_go').disabled = true; $('pay_out').textContent = 'Checking the receipt…';
    fetch(location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF }, body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Upload rejected — the file may be too large for this server.' }; }); })
      .then(function (j) {
        $('pay_go').disabled = false;
        if (j.ok && j.status === 'verified') { toast('Receipt checked — payment recorded'); setTimeout(function () { location.reload(); }, 700); return; }
        if (j.ok && j.status === 'held') { $('pay_out').textContent = 'Held, not credited: ' + (j.reasons || []).join(' '); return; }
        $('pay_out').textContent = j.error || 'Not recorded.';
      })
      .catch(function () { $('pay_go').disabled = false; $('pay_out').textContent = 'Offline — nothing was recorded.'; });
  });

  /* ── Catalogue ── */
  $('c_save').addEventListener('click', function () {
    var a = {}; document.querySelectorAll('[data-cat]').forEach(function (i) { a[i.getAttribute('data-cat')] = i.value.replace(/[^\d.]/g, '') || '0'; });
    post({ action: 'catalogue', amounts: a }).then(function (r) { $('c_out').textContent = r.ok ? 'Saved.' : (r.error || 'Not saved.'); if (r.ok) setTimeout(function () { location.reload(); }, 600); });
  });

  /* ── Import ── */
  var COLS = [['name', 'Name'], ['id', 'NGV ID'], ['email', 'Email'], ['phone', 'Phone'], ['amount', 'Amount'], ['reason', 'Reason'], ['date', 'Date'], ['note', 'Note']];
  var sheet = null, report = null, digest = '', dirty = false, source = '', view = 'attention', xlsx = '';
  $('tpl').href = 'data:text/csv;charset=utf-8,' + encodeURIComponent('Name,NGV ID,Email,Phone,Amount,Reason,Date,Note\nAdebayo Bello,A-NGV-25-0001,,,,late,29/09/2026,Came in at 7:40\n');
  $('m_file').addEventListener('change', function () {
    var f = this.files && this.files[0]; if (!f) return;
    source = f.name;
    /* A workbook goes as it is (base64); the server reads its first sheet. */
    if (/\.xlsx$/i.test(f.name)) {
      var rb = new FileReader(); rb.onload = function () { var u8 = new Uint8Array(rb.result), bin = ''; for (var i = 0; i < u8.length; i += 0x8000) bin += String.fromCharCode.apply(null, u8.subarray(i, i + 0x8000)); xlsx = btoa(bin); $('m_csv').value = ''; check(true); }; rb.readAsArrayBuffer(f);
      return;
    }
    xlsx = '';
    var rd = new FileReader(); rd.onload = function () { $('m_csv').value = String(rd.result || ''); check(true); }; rd.readAsText(f);
  });
  $('m_check').addEventListener('click', function () { if (!$('m_file').files.length) { source = 'pasted'; xlsx = ''; } check(true); });
  $('m_recheck').addEventListener('click', function () { check(false); });
  function check(fromText) {
    $('m_out').textContent = 'Checking…';
    var body = { action: 'import', notify: $('m_notify').checked, source: source };
    if (fromText || !sheet) { if (xlsx) body.xlsx = xlsx; else body.csv = $('m_csv').value; } else body.rows = sheet;
    post(body).then(function (r) {
      if (!r.ok) { $('m_out').textContent = r.error || 'Could not read it.'; return; }
      sheet = r.sheet; report = r; digest = r.digest; dirty = false;
      var c = r.counts;
      $('m_out').textContent = c.ready + ' ready to charge (' + money(r.total) + ')' + (c.ambiguous ? ' · ' + c.ambiguous + ' to choose' : '') + (c.unmatched ? ' · ' + c.unmatched + ' nobody found' : '')
        + (c.invalid ? ' · ' + c.invalid + ' to fix' : '') + (c.already ? ' · ' + c.already + ' already imported' : '') + (c.duplicate ? ' · ' + c.duplicate + ' repeated' : '')
        + (r.unknown_columns && r.unknown_columns.length ? ' · ignored columns: ' + r.unknown_columns.join(', ') : '')
        + (r.read_as ? ' · read as ' + r.read_as : '');
      if (view === 'attention' && !(c.ambiguous + c.unmatched + c.invalid)) view = 'all';
      draw();
    });
  }
  function counts() { var c = report.counts; return [['attention', 'Needs you', c.ambiguous + c.unmatched + c.invalid], ['ready', 'Ready', c.ready], ['all', 'All rows', sheet.length], ['already', 'Already fined', c.already + c.duplicate]]; }
  function draw() {
    $('m_result').hidden = false;
    $('m_tabs').innerHTML = counts().map(function (t) { return '<a href="#" role="tab" aria-selected="' + (view === t[0]) + '" class="' + (view === t[0] ? 'on' : '') + '" data-view="' + t[0] + '">' + t[1] + ' · ' + t[2] + '</a>'; }).join('');
    var head = '<thead><tr><th>#</th><th>Standing</th><th>For</th>' + COLS.map(function (c) { return '<th>' + c[1] + '</th>'; }).join('') + '</tr></thead>';
    var rows = report.rows.filter(function (r) {
      if (view === 'all') return true;
      if (view === 'attention') return ['ambiguous', 'unmatched', 'invalid'].indexOf(r.status) >= 0;
      if (view === 'ready') return r.status === 'ready' || r.status === 'fined';
      return r.status === 'already' || r.status === 'duplicate';
    });
    var body = rows.map(function (r) {
      var s = sheet[r.i], who;
      if (r.member) who = '<b>' + esc(r.member.name) + '</b><div class="why">by ' + esc(r.how) + '</div>';
      else if (r.status === 'ambiguous') who = '<select data-assign="' + r.i + '" aria-label="Who row ' + (r.i + 2) + ' is for"><option value="">Choose who…</option>' + r.candidates.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + ' (' + c.score + '%) ' + esc(c.detail) + '</option>'; }).join('') + '</select>';
      else who = '';
      if (!r.member) who += '<div class="picker" style="min-width:9rem;margin-top:4px"><input data-find="' + r.i + '" placeholder="Find a member" autocomplete="off"><div class="found" hidden></div></div>';
      var notes = (r.errors || []).map(function (x) { return '<div class="why err">' + esc(x) + '</div>'; }).join('') + (r.warnings || []).map(function (x) { return '<div class="why">' + esc(x) + '</div>'; }).join('');
      var label = { ready: 'Ready', fined: 'Fined', ambiguous: 'Choose', unmatched: 'Nobody found', invalid: 'Fix', already: 'Already', duplicate: 'Repeated' }[r.status] || r.status;
      var extra = r.status === 'ready' || r.status === 'fined' ? '<div class="why">' + money(r.amount) + ' · ' + esc(r.reason_label) + ' · ' + esc(r.day) + '</div>' : '';
      return '<tr class="' + (['invalid', 'unmatched'].indexOf(r.status) >= 0 ? 'bad' : r.status === 'ambiguous' ? 'warn' : '') + '"><td class="sub">' + (r.i + 2) + '</td><td><span class="pill s-' + r.status + '">' + label + '</span>' + extra + notes + '</td><td class="w">' + who + '</td>'
        + COLS.map(function (c) { return '<td><input data-cell="' + r.i + '" data-col="' + c[0] + '" value="' + esc(s[c[0]]) + '" aria-label="' + c[1] + ', row ' + (r.i + 2) + '"' + (c[0] === 'reason' ? ' list="reasons"' : '') + '></td>'; }).join('') + '</tr>';
    }).join('');
    $('m_grid').innerHTML = head + '<tbody>' + (body || '<tr><td colspan="11" class="sub">Nothing here.</td></tr>') + '</tbody>'
      + '<datalist id="reasons">' + Object.keys(REASONS).map(function (k) { return '<option value="' + esc(REASONS[k]) + '">'; }).join('') + '</datalist>';
    $('m_grid').querySelectorAll('[data-find]').forEach(function (inp) {
      var i = +inp.getAttribute('data-find');
      picker(inp, inp.nextElementSibling, function (p) { sheet[i].assign = String(p.id); check(false); });
    });
    sync();
  }
  function sync() {
    $('m_dirty').hidden = !dirty;
    var n = report.counts.ready;
    $('m_apply').disabled = dirty || !n || report.applied;
    $('m_apply').textContent = report.applied ? 'Imported' : (n ? 'Import ' + n + ' fine' + (n === 1 ? '' : 's') + ' · ' + money(report.total) : 'Nothing ready to import');
  }
  $('m_tabs').addEventListener('click', function (ev) { var a = ev.target.closest('[data-view]'); if (!a) return; ev.preventDefault(); view = a.getAttribute('data-view'); draw(); });
  $('m_grid').addEventListener('input', function (ev) {
    var t = ev.target; if (!t.hasAttribute('data-cell')) return;
    var row = sheet[+t.getAttribute('data-cell')];
    row[t.getAttribute('data-col')] = t.value;
    /* The person it is for may have changed with the cell: let the sheet decide again. */
    if (['name', 'id', 'email', 'phone'].indexOf(t.getAttribute('data-col')) >= 0) row.assign = '';
    dirty = true; sync();
  });
  $('m_grid').addEventListener('change', function (ev) {
    var t = ev.target; if (!t.hasAttribute('data-assign') || !t.value) return;
    sheet[+t.getAttribute('data-assign')].assign = t.value; check(false);
  });
  $('m_apply').addEventListener('click', function () {
    var n = report.counts.ready, held = report.counts.ambiguous + report.counts.unmatched + report.counts.invalid;
    if (!confirm('Charge ' + n + ' fine' + (n === 1 ? '' : 's') + ' (' + money(report.total) + ')?' + (held ? '\n\n' + held + ' row' + (held === 1 ? '' : 's') + ' that need you will NOT be charged.' : '') + ($('m_notify').checked ? '\n\nEach member will be told.' : ''))) return;
    var btn = this; btn.disabled = true; btn.textContent = 'Importing…';
    post({ action: 'import', rows: sheet, apply: true, digest: digest, notify: $('m_notify').checked, source: source }).then(function (r) {
      if (!r.ok) { $('m_out').textContent = r.error || 'Not imported.'; if (r.code === 'changed') check(false); else sync(); return; }
      report = r; digest = r.digest;
      $('m_out').textContent = r.counts.fined + ' fined · ' + money(r.total) + '. Rows that needed you were not charged — fix them and check again to import those too.';
      toast('Imported', true); view = 'all'; draw();
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
