<?php
/**
 * academy/ngv/members.php — NextGen Vanguard staff console.
 *
 * The write path that makes the member dashboard's account + certifications
 * real: staff enrol members, set status/cohort/track/plan, run the accrual,
 * record payments, issue fines, waive what should not be collected, chase
 * arrears, and add certifications.
 *
 * The money all goes through lib/NgvLedger.php — nothing on this page does its
 * own arithmetic, and every mutating action lands on the admin audit trail with
 * the amount and the reason on it. Participant data lives in the SEPARATE NGV
 * database (lib/NgvDb.php); this page never touches the main site tables except
 * to look up a member by email at enrolment time and to write that trail.
 *
 * Admin-gated exactly like edit.php (av_admin_role). JSON actions POST to this
 * same URL, guarded by admin + same-origin + CSRF.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$role    = function_exists('av_admin_role') ? av_admin_role() : '';
$isAdmin = $role !== '';
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ── JSON actions ─────────────────────────────────────────────────────── */
if ($method === 'POST') {
    if (!$isAdmin) json_out(['ok' => false, 'error' => 'Admin sign-in required.'], 403);
    require_same_origin();
    av_csrf_require();

    /* Photos come as multipart/form-data, so this branch runs BEFORE the JSON
       body is read — php://input is empty on a multipart request, and parsing it
       first would turn every upload into "Unknown action". */
    if (!empty($_FILES['photos']) && (string) ($_POST['action'] ?? '') === 'damage_photos') {
        $r = NgvDamage::addPhotos((int) ($_POST['damage_id'] ?? 0), $_FILES['photos'],
                                  (int) (LmsAuth::user()['id'] ?? 0));
        json_out($r, empty($r['ok']) ? 400 : 200);
    }

    $in  = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $act = (string) ($in['action'] ?? '');
    $adminUid = (int) (LmsAuth::user()['id'] ?? 0);

    if ($act === 'enroll') {
        $email = trim((string) ($in['email'] ?? ''));
        if ($email === '') json_out(['ok' => false, 'error' => 'Enter an email.'], 400);
        $st = Database::pdo()->prepare('SELECT id, name, email FROM lms_users WHERE email = ?');
        $st->execute([$email]);
        $m = $st->fetch();
        if (!$m) json_out(['ok' => false, 'error' => 'No member account with that email. They must have signed in to the site at least once.'], 404);
        NgvMember::ensureParticipant((int) $m['id'], ['name' => (string) $m['name'], 'email' => (string) $m['email']]);
        json_out(['ok' => true, 'member_id' => (int) $m['id']]);
    }

    $mid = (int) ($in['member_id'] ?? 0);
    if ($act === 'admin') {
        if ($mid <= 0) json_out(['ok' => false, 'error' => 'Missing member.'], 400);
        $patch = ['status' => $in['status'] ?? null, 'cohort' => $in['cohort'] ?? null,
                  'track'  => $in['track'] ?? null,  'phase'  => $in['phase'] ?? null];
        foreach (['plan', 'start_date'] as $k) { if (array_key_exists($k, $in)) $patch[$k] = $in[$k]; }
        NgvMember::setAdmin($mid, $patch);
        AdminAudit::log('ngv', 'ngv_enrolment', 'ngv:member:' . $mid,
            'Enrolment updated — ' . trim(implode(' · ', array_filter([
                (string) ($in['status'] ?? ''), (string) ($in['cohort'] ?? ''),
                (string) ($in['track'] ?? ''), (string) ($in['plan'] ?? ''),
                ($in['start_date'] ?? '') !== '' ? 'from ' . (string) $in['start_date'] : '',
            ]))));
        json_out(['ok' => true]);
    }
    if ($act === 'payment') {
        if ($mid <= 0) json_out(['ok' => false, 'error' => 'Missing member.'], 400);
        $r = NgvLedger::payment($mid, (string) ($in['kind'] ?? 'commitment'), $in['amount'] ?? 0, [
            'period' => $in['period'] ?? '', 'method' => $in['method'] ?? '',
            'reference' => $in['reference'] ?? '', 'note' => $in['note'] ?? '',
            /* Default ON. Off is for typing in a backlog of historic payments,
               where forty emails at once is a fault rather than a feature — the
               receipts still exist and can be sent one at a time. */
            'receipt' => !array_key_exists('receipt', $in) || !empty($in['receipt']),
        ], $adminUid);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    /* Send (or re-send) a receipt for one payment. Needed more often than it
       sounds: a bounced address that has since been fixed, a participant who
       deleted the email, and every payment recorded before receipts existed. */
    if ($act === 'receipt') {
        $r = NgvLedger::sendReceipt((int) ($in['payment_id'] ?? 0));
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    /* A fine or an adjustment. The reason vocabulary and the "say why" rule live
       in the ledger, so this route cannot post one without them. */
    if ($act === 'charge') {
        if ($mid <= 0) json_out(['ok' => false, 'error' => 'Missing member.'], 400);
        $r = NgvLedger::charge($mid, (string) ($in['kind'] ?? 'fine'), $in['amount'] ?? 0,
            (string) ($in['reason'] ?? ''), (string) ($in['note'] ?? ''), $adminUid);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    /* Two different sentences, so two different operations. A WAIVER says "this
       is owed and we are not asking for it"; a WRITE-OFF says "the programme has
       stopped carrying this" — what a withdrawal leaves behind. Recording one as
       the other loses the only part anybody reads back later. */
    if ($act === 'waive' || $act === 'writeoff') {
        if ($mid <= 0) json_out(['ok' => false, 'error' => 'Missing member.'], 400);
        $line = (string) ($in['kind'] ?? 'commitment');
        $r = $act === 'waive'
            ? NgvLedger::waive($mid, $line, $in['amount'] ?? 0, (string) ($in['reason'] ?? ''), $adminUid)
            : NgvLedger::writeOff($mid, $line, $in['amount'] ?? 0, (string) ($in['reason'] ?? ''), $adminUid);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    /* Agree a training fee, or stop one. Two operations because they are two
       decisions: stopping leaves every instalment already posted on the account,
       and whether those should still be asked for is a waiver's question. */
    if ($act === 'training') {
        if ($mid <= 0) json_out(['ok' => false, 'error' => 'Missing member.'], 400);
        $r = NgvLedger::startTrainingFee($mid, (int) ($in['months'] ?? 1), $adminUid, $in['amount'] ?? null);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    if ($act === 'training_stop') {
        if ($mid <= 0) json_out(['ok' => false, 'error' => 'Missing member.'], 400);
        $r = NgvLedger::stopTrainingFee($mid);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    if ($act === 'remind_off') {
        if ($mid <= 0) json_out(['ok' => false, 'error' => 'Missing member.'], 400);
        $r = NgvLedger::setRemindOff($mid, !empty($in['off']));
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    /* Void either side of the ledger. Nothing is deleted and nothing is edited:
       the row stays, flagged, with the reason on it. */
    if ($act === 'void') {
        $side = ((string) ($in['side'] ?? 'credit')) === 'charge' ? 'charge' : 'credit';
        $r = NgvLedger::void($side, (int) ($in['entry_id'] ?? ($in['payment_id'] ?? 0)),
            (string) ($in['reason'] ?? ''), $adminUid);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }

    /* Answer a participant who asked. This does NOT move money — where the
       answer is a waiver, that is posted separately with its own reason. */
    if ($act === 'request') {
        $r = NgvLedger::resolveRequest((int) ($in['request_id'] ?? 0), (string) ($in['status'] ?? 'resolved'),
            (string) ($in['outcome'] ?? ''), $adminUid);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }

    /* ── Damage ──────────────────────────────────────────────────────────
       Recording damage charges nothing. Only `advance` with status `charged`
       moves money, and it does it through the ledger as a fine, so it lands on
       the fines line and is voided or waived by the same paths as any other. */
    if ($act === 'damage') {
        if ($mid <= 0) json_out(['ok' => false, 'error' => 'Missing member.'], 400);
        $r = NgvDamage::report($mid, $in, $adminUid, false);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    if ($act === 'damage_advance') {
        $r = NgvDamage::advance((int) ($in['damage_id'] ?? 0), (string) ($in['status'] ?? ''), $in, $adminUid);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    if ($act === 'damage_notify') {
        $r = NgvDamage::setNotify((int) ($in['damage_id'] ?? 0), !empty($in['on']));
        json_out($r, empty($r['ok']) ? 400 : 200);
    }

    /* ── Statements ──────────────────────────────────────────────────────
       Not a reminder. A reminder chases money and only goes to somebody who
       owes; a statement says where you stand and goes to anybody — including
       somebody square with the programme, who the reminder rules could never
       tell so. */
    if ($act === 'statement') {
        if ($mid <= 0) json_out(['ok' => false, 'error' => 'Missing member.'], 400);
        $r = NgvLedger::sendStatement($mid, false);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    if ($act === 'statement_run') {
        json_out(NgvLedger::sendStatements(NgvLedger::STATEMENT_BATCH));
    }
    /* Receipts for payments recorded before receipts existed. One digest per
       person, not one email per payment — see NgvLedger::backfillReceipts. */
    if ($act === 'backfill_preview') {
        json_out(NgvLedger::backfillReceipts(NgvLedger::BACKFILL_BATCH, true));
    }
    if ($act === 'backfill_run') {
        json_out(NgvLedger::backfillReceipts(NgvLedger::BACKFILL_BATCH, false));
    }

    /* ── Programme-wide ── */
    if ($act === 'fees_settings') {
        $cfg = NgvLedger::saveSettings(is_array($in['settings'] ?? null) ? $in['settings'] : [], 'admin');
        json_out(['ok' => true, 'settings' => $cfg]);
    }
    if ($act === 'fees_reviewed') {
        json_out(['ok' => true, 'settings' => NgvLedger::markReviewed('admin')]);
    }
    if ($act === 'accrue') {
        $r = NgvLedger::accrueAll(NgvLedger::ACCRUE_BATCH);
        if (empty($r['ok'])) json_out(['ok' => false, 'error' => 'Switch fees on first.'], 400);
        AdminAudit::log('ngv', 'ngv_fee_accrue', 'ngv:fees',
            'Accrual run — ' . $r['accrued']['membership'] . ' membership, ' . $r['accrued']['commitment']
            . ' commitment, ' . $r['accrued']['programme'] . ' training across ' . $r['accrued']['participants'] . ' participant(s)');
        json_out($r);
    }
    /* Offered before the button that sends, because a send is not undoable: a
       message to sixty people cannot be recalled, and "how many, and to whom" is
       the question worth answering first. */
    if ($act === 'remind_preview') {
        $c = NgvLedger::reminderCandidates();
        json_out(['ok' => true, 'count' => count($c['due']), 'everyDays' => $c['everyDays'],
                  'skipped' => $c['skipped'],
                  'due' => array_map(static fn($x) => ['name' => $x['name'], 'email' => $x['email'],
                                                       'payable' => $x['payable'], 'last' => $x['lastRemindedAt']], $c['due'])]);
    }
    if ($act === 'remind_run') {
        $r = NgvLedger::runReminders(NgvLedger::REMIND_BATCH);
        if (empty($r['ok'])) json_out(['ok' => false, 'error' => 'Reminders are switched off.'], 400);
        json_out($r);
    }
    if ($act === 'cert') {
        if ($mid <= 0) json_out(['ok' => false, 'error' => 'Missing member.'], 400);
        $ok = NgvMember::addCertification($mid, [
            'title' => $in['title'] ?? '', 'issued_on' => $in['issued_on'] ?? '',
            'issued_by' => $in['issued_by'] ?? '', 'reference' => $in['reference'] ?? '',
        ]);
        json_out(['ok' => $ok, 'error' => $ok ? '' : 'A title is required.']);
    }
    /* Revoked, never deleted — the link is public and somebody may already have
       given it to an employer. See NgvMember::revokeCertification. */
    if ($act === 'cert_revoke') {
        $r = NgvMember::revokeCertification((int) ($in['cert_id'] ?? 0), (string) ($in['reason'] ?? ''), $adminUid);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    if ($act === 'app_status') {
        $ok = NgvMember::setApplicationStatus((int) ($in['app_id'] ?? 0), (string) ($in['status'] ?? ''), $adminUid);
        json_out(['ok' => $ok, 'error' => $ok ? '' : 'Bad application/status.']);
    }
    if ($act === 'app_enroll') {
        $r = NgvMember::enrollApplication((int) ($in['app_id'] ?? 0), $adminUid);
        json_out($r + ['ok' => (bool) ($r['ok'] ?? false)]);
    }
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
$e = 'e';
$csrf = $isAdmin && function_exists('av_csrf_token') ? av_csrf_token() : '';

/* Data for the view */
$stats = $isAdmin ? NgvMember::stats() : ['total' => 0, 'by_status' => [], 'collected' => 0, 'certs' => 0, 'apps_pending' => 0, 'apps_total' => 0];
/* Roster filters, from the query string so a filtered view is a shareable URL —
 * "the paused people in 2026 Alpha" is a thing one coordinator sends another. */
$fStatus = (string) ($_GET['s'] ?? '');
$fCohort = (string) ($_GET['c'] ?? '');
$fQuery  = (string) ($_GET['r'] ?? '');
$roster  = $isAdmin ? NgvMember::roster($fStatus, 300, $fQuery, $fCohort) : [];
$rosterAll = $isAdmin ? NgvMember::rosterCount($fStatus, $fQuery, $fCohort) : 0;
$cohorts = $isAdmin ? NgvMember::cohorts() : [];
$filtered = $fStatus !== '' || $fCohort !== '' || trim($fQuery) !== '';
$apps   = $isAdmin ? NgvMember::applications('', 100) : [];
$sel = null; $selAcct = null; $selCerts = [];
$mid = (int) ($_GET['m'] ?? 0);
if ($isAdmin && $mid > 0) {
    $sel = NgvMember::participant($mid);
    /* Staff see revoked certificates too — a withdrawn one is part of the record,
       and hiding it means nobody can tell a revoked certificate from one that was
       never issued. */
    if ($sel) { $selAcct = NgvLedger::account($mid); $selCerts = NgvMember::certifications($mid, true); }
}
$trackNames = [];
foreach ((Ngv::get()['tracks'] ?? []) as $t) { if (!empty($t['name'])) $trackNames[] = (string) $t['name']; }

/* ── Money ─────────────────────────────────────────────────────────────────
 * Everything here is read straight off the ledger. The amounts row is worth
 * showing rather than assuming: `source` says whether a figure came from the
 * public page, from a pinned override, or from the built-in fallback, so staff
 * can see WHERE what they are charging comes from instead of trusting it. */
$fees    = $isAdmin ? NgvLedger::settings() : [];
$amounts = $isAdmin ? NgvLedger::amounts() : [];
$money   = $isAdmin ? NgvLedger::totals() : [];
$arrears = $isAdmin && !empty($fees['enabled']) ? NgvLedger::arrears(50) : ['rows' => [], 'matched' => 0, 'truncated' => false, 'totalPayable' => 0];
$review  = $isAdmin ? NgvLedger::reviewDue() : ['due' => false];
$look    = $isAdmin ? NgvLedger::lookup((string) ($_GET['q'] ?? '')) : ['rows' => [], 'q' => '', 'tooShort' => true];
/* People who have asked for consideration or queried a figure. Above the
 * arrears list on purpose: somebody who wrote to say they cannot pay is not a
 * debtor to chase, and answering them is the more urgent of the two jobs. */
$reqs    = $isAdmin ? NgvLedger::requests(false, 60) : [];
$reqOpen = 0;
foreach ($reqs as $rq) { if ($rq['status'] === 'open') $reqOpen++; }
/* Damage. An incident record, not a charge: a report costs nothing until
 * somebody decides on an amount, which is a separate and audited step. */
$dmgAll  = $isAdmin ? NgvDamage::all(false, 60) : [];
$dmgTot  = $isAdmin ? NgvDamage::totals() : ['records' => 0, 'open' => 0, 'assessed' => 0, 'charged' => 0, 'absorbed' => 0];
$selDmg  = $sel ? NgvDamage::forMember($mid) : [];
$B       = NgvLedger::bounds();
$srcWord = ['page' => 'from the public page', 'pinned' => 'pinned here', 'fallback' => 'built-in fallback'];
$planCat = NgvLedger::planCatalogue();
$kindLabel = ['membership' => 'Membership', 'commitment' => 'Monthly commitment',
              'programme' => 'Training fee', 'fine' => 'Fine', 'adjustment' => 'Adjustment', 'other' => 'Unallocated'];
$creditWord = ['payment' => 'Payment', 'waiver' => 'Waived', 'writeoff' => 'Written off'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Vanguards · NGV staff</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--red:#e4162b;--orange:#ff6a1a;--gold:#ffb703;--ink:#15120e;--line:#e7e9ee;--muted:#5f6874;--bg:#f5f6f8;--card:#fff;--grad:linear-gradient(100deg,#e4162b,#ff6a1a 55%,#ffb703);--r:14px}
*{box-sizing:border-box}body{margin:0;font-family:Montserrat,system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.5}
a{color:var(--red)}
.top{position:sticky;top:0;z-index:20;background:rgba(21,18,14,.97);color:#fff;display:flex;gap:12px;align-items:center;padding:12px 20px;flex-wrap:wrap}
.top .brand{font-weight:800}.top .brand b{color:var(--gold)}.top .sp{flex:1}
.top a{color:rgba(255,255,255,.85);text-decoration:none;font-weight:600;font-size:.86rem}.top a:hover{color:#fff}
.wrap{max-width:1180px;margin:22px auto;padding:0 18px}
.gate{max-width:520px;margin:12vh auto;background:#fff;border:1px solid var(--line);border-radius:18px;padding:36px;text-align:center}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.stat{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:14px 16px}
.stat .k{font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.stat .v{font-size:1.5rem;font-weight:800}
.shell{display:grid;grid-template-columns:1.1fr 1.3fr;gap:18px;align-items:start}
.card{background:#fff;border:1px solid var(--line);border-radius:var(--r);overflow:hidden}
.card>header{padding:14px 18px;border-bottom:1px solid var(--line);font-weight:700;display:flex;gap:10px;align-items:center}
.card>header .sp{flex:1}
.card>.body{padding:16px 18px}
table{width:100%;border-collapse:collapse;font-size:.88rem}
th,td{text-align:left;padding:8px 8px;border-bottom:1px solid var(--line);vertical-align:middle}
th{font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;color:var(--muted)}
tr.on{background:#fff6f2}
.badge{font-size:.72rem;font-weight:800;padding:2px 9px;border-radius:999px;text-transform:capitalize}
.b-active{background:#e6f7ec;color:#137a3a}.b-applicant{background:#eef2fb;color:#274690}.b-completed{background:#fff4e0;color:#9a5b00}
.b-paused{background:#f1f3f6;color:#5f6874}.b-withdrawn{background:#fdecec;color:#c0322b}
.btn{border:1.5px solid var(--line);background:#fff;border-radius:9px;padding:7px 12px;font:inherit;font-weight:700;cursor:pointer;color:var(--ink);text-decoration:none;display:inline-block}
.btn:hover{border-color:var(--orange)}
.btn:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:3px solid var(--orange);outline-offset:2px}
.btn[disabled]{opacity:.55;cursor:progress}
.btn.primary{background:var(--grad);color:#fff;border-color:transparent}
.btn.sm{padding:5px 10px;font-size:.82rem}
.fld{margin-bottom:12px}.fld label{display:block;font-size:.78rem;font-weight:700;color:var(--muted);margin-bottom:5px}
input,select,textarea{width:100%;border:1.5px solid var(--line);border-radius:9px;padding:9px;font:inherit}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--orange)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.sub{font-size:.82rem;color:var(--muted)}
.enroll{display:flex;gap:8px}.enroll input{flex:1}
.rfilter{display:flex;gap:8px;flex-wrap:wrap}
.rfilter input{flex:1;min-width:140px}.rfilter select{width:auto}
.msg{font-size:.85rem;font-weight:700;margin-left:auto}
.pay-row{display:flex;gap:8px;align-items:flex-start;font-size:.86rem;padding:7px 0;border-bottom:1px dashed var(--line)}
.pay-row .sp{flex:1}
.pay-row.voided{opacity:.55}.pay-row.voided b{text-decoration:line-through}
.dir{display:inline-block;width:16px;font-weight:800;text-align:center}
.dir.charge{color:#c0322b}.dir.credit{color:#137a3a}
.pill{font-size:.68rem;font-weight:800;padding:2px 9px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em}
.pill-on{background:#e6f7ec;color:#137a3a}.pill-off{background:#f1f3f6;color:#5f6874}
.pill-open{background:#eef2fb;color:#274690}
.dmg{border:1px solid var(--line);border-radius:11px;padding:11px 13px;margin-bottom:10px}
.dmg-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.dmg-head .sp{flex:1}
.dmg blockquote{margin:6px 0;padding-left:10px;border-left:3px solid var(--line);color:var(--muted);font-size:.88rem}
.shots{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}
.shots img{width:96px;height:96px;object-fit:cover;border-radius:9px;border:1px solid var(--line);display:block}
.shots a:focus-visible img{outline:3px solid var(--orange);outline-offset:2px}
textarea{min-height:60px;resize:vertical}
.note{border:1.5px solid var(--line);border-radius:11px;padding:11px 13px;margin-bottom:14px;font-size:.88rem}
.note-warn{border-color:#f2c98a;background:#fffaf0}
.note-ask{border-color:#a9c6ef;background:#f5f9ff}
.stat--ask{border-color:#a9c6ef}
.reqs{display:grid;gap:10px;margin-top:10px}
.req{border:1px solid var(--line);border-radius:11px;padding:10px 12px;background:#fff}
.req--done{opacity:.8}
.req-who{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-weight:700}
.req blockquote{margin:6px 0;padding-left:10px;border-left:3px solid var(--line);color:var(--muted);font-size:.88rem}
.req .req-out{margin-bottom:8px}
.note .sub{display:block;margin-top:4px}
.amts{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:8px}
.amt-box{border:1px solid var(--line);border-radius:11px;padding:10px 12px}
.amt-box .k{font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.amt-box .v{font-size:1.15rem;font-weight:800}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:22px}
.chk{display:flex;gap:8px;align-items:center;font-weight:600;font-size:.88rem;margin-bottom:6px}
.chk input{width:auto}
.card.money .fld p.sub{margin:5px 0 0}
.insts{display:flex;flex-wrap:wrap;gap:5px;margin:8px 0 2px}
.inst{width:26px;height:26px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;
  font-size:.72rem;font-weight:800;border:1.5px solid var(--line);color:var(--muted);cursor:default}
.inst--charged{background:#e6f7ec;border-color:#a9dcbd;color:#137a3a}
.inst--due{background:#fdecec;border-color:#f0b4b0;color:#c0322b}
.figure{font-size:1.7rem;font-weight:800;line-height:1.1}
.figure.clear{color:#137a3a}
.figure .sub{display:block;font-size:.78rem;font-weight:600}
@media(max-width:900px){.amts{grid-template-columns:1fr}.cols{grid-template-columns:1fr}}
@media(max-width:900px){.shell{grid-template-columns:1fr}.stats{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<?php if (!$isAdmin): ?>
  <div class="gate">
    <h1>Admin sign-in required</h1>
    <p>This console manages NextGen Vanguard participants, fees and certifications. Sign in to the Academy Studio, then come back.</p>
    <p style="margin-top:18px"><a class="btn primary" href="/academy/studio/">Go to the Studio →</a></p>
    <p style="margin-top:12px"><a href="/academy/ngv/">View the public page</a></p>
  </div>
<?php else: ?>
<div class="top">
  <span class="brand"><b>NextGen Vanguard</b> · staff</span>
  <span class="sp"></span>
  <a href="/academy/ngv/edit.php">Edit page</a>
  <a href="/academy/ngv/" target="_blank" rel="noopener">Public ↗</a>
  <span class="msg" id="msg"></span>
</div>
<div class="wrap">
  <div class="stats">
    <div class="stat"><div class="k">Participants</div><div class="v"><?= (int)$stats['total'] ?></div>
      <?php if ((int)($money['charged'] ?? 0) > 0): ?><div class="sub">₦<?= number_format((int)$money['charged']) ?> charged</div><?php endif; ?></div>
    <div class="stat"><div class="k">Active</div><div class="v"><?= (int)($stats['by_status']['active'] ?? 0) ?></div></div>
    <div class="stat"><div class="k">Received</div><div class="v">₦<?= number_format((int)($money['received'] ?? 0)) ?></div>
      <?php if ((int)($money['waived'] ?? 0) > 0): ?><div class="sub">₦<?= number_format((int)$money['waived']) ?> waived</div><?php endif; ?></div>
    <div class="stat<?= $reqOpen > 0 ? ' stat--ask' : '' ?>"><div class="k">Outstanding</div><div class="v">₦<?= number_format((int)($money['outstanding'] ?? 0)) ?></div>
      <div class="sub">
        <?php if ((int)($arrears['matched'] ?? 0) > 0): ?><?= (int)$arrears['matched'] ?> behind<?php endif; ?>
        <?php if ($reqOpen > 0): ?><?= (int)($arrears['matched'] ?? 0) > 0 ? ' · ' : '' ?><b><?= $reqOpen ?> asked for help</b><?php endif; ?>
      </div></div>
  </div>

  <!-- ══ Money ══════════════════════════════════════════════════════════════
       A ledger, not a payment processor: nothing on this page takes money. It
       records what is owed and what staff confirm arrived. -->
  <div class="card money" style="margin-bottom:18px">
    <header>Fees &amp; dues
      <span class="pill <?= !empty($fees['enabled']) ? 'pill-on' : 'pill-off' ?>"><?= !empty($fees['enabled']) ? 'On' : 'Off' ?></span>
      <span class="sp"></span>
      <span class="sub">membership · monthly commitment · training fee · fines</span>
    </header>
    <div class="body">

      <?php if ($reqOpen > 0): ?>
        <div class="note note-ask">
          <b><?= $reqOpen ?> <?= $reqOpen === 1 ? 'person has' : 'people have' ?> written about their account.</b>
          Answering comes before chasing — somebody who said they cannot pay is not a debtor to chase.
          <div class="reqs">
          <?php foreach ($reqs as $rq): if ($rq['status'] !== 'open') continue; ?>
            <div class="req">
              <div class="req-who">
                <a href="?m=<?= (int)$rq['member_id'] ?>"><?= $e((string)($rq['name'] ?: ('#'.$rq['member_id']))) ?></a>
                <span class="sub"><?= $e((string)$rq['kindLabel']) ?> · <?= $e(substr((string)$rq['created_at'], 0, 10)) ?></span>
              </div>
              <blockquote><?= $e((string)$rq['message']) ?></blockquote>
              <input class="req-out" data-for="<?= (int)$rq['id'] ?>" placeholder="What you are telling them — they see this">
              <div>
                <button class="btn sm primary" data-act="request" data-req="<?= (int)$rq['id'] ?>" data-status="resolved">Sorted</button>
                <button class="btn sm" data-act="request" data-req="<?= (int)$rq['id'] ?>" data-status="declined">Answered, no change</button>
                <a class="btn sm" href="?m=<?= (int)$rq['member_id'] ?>#waive">Open their account</a>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($review['due']): ?>
        <div class="note note-warn">
          These amounts have not been reviewed
          <?= $review['lastAt'] === '' ? 'since fees were switched on' : 'since ' . $e((string)$review['lastAt']) ?>.
          Naira inflation erodes a fixed figure fast — check <a href="/academy/ngv/edit.php" target="_blank" rel="noopener">the public page</a>
          against what you are charging, then
          <button class="btn sm" data-act="fees_reviewed">mark them reviewed</button>.
          <span class="sub">Nothing here ever changes an amount on its own.</span>
        </div>
      <?php endif; ?>

      <!-- What is being charged, and where each figure came from. -->
      <div class="amts">
        <?php foreach (['membership' => 'Membership', 'commitment' => 'Monthly commitment'] as $k => $lbl):
              $a = $amounts[$k] ?? ['amount' => 0, 'cadence' => '', 'source' => '']; ?>
          <div class="amt-box">
            <div class="k"><?= $e($lbl) ?></div>
            <div class="v">₦<?= number_format((int)$a['amount']) ?> <span class="sub">/ <?= $e((string)$a['cadence']) ?></span></div>
            <div class="sub"><?= $e($srcWord[(string)$a['source']] ?? (string)$a['source']) ?></div>
          </div>
        <?php endforeach; ?>
        <div class="amt-box">
          <div class="k">Training fee</div>
          <?php $paid = array_filter($planCat, static fn($p) => (int) $p['fee'] > 0); ?>
          <div class="v"><?= $paid ? '₦' . number_format((int) reset($paid)['fee']) : 'Free' ?>
            <?php if ($paid): ?><span class="sub">/ <?= $e((string) reset($paid)['cadence']) ?></span><?php endif; ?></div>
          <div class="sub"><?= $paid ? $e((string) key($paid)) . ' · from the plans table' : 'no paid plan on the page' ?></div>
        </div>
      </div>
      <p class="sub" style="margin:2px 0 14px">
        The figures come from <a href="/academy/ngv/edit.php" target="_blank" rel="noopener">the public NGV page</a> — edit them
        there and the ledger follows, so a receipt can never disagree with the website. Pin one below only when the page cannot say it.
      </p>

      <div class="cols">
        <!-- Settings -->
        <div>
          <div class="fld"><label>How it runs</label>
            <label class="chk"><input type="checkbox" id="s_enabled" <?= !empty($fees['enabled']) ? 'checked' : '' ?>> Charge membership and monthly commitment</label>
            <label class="chk"><input type="checkbox" id="s_trainingAuto" <?= !empty($fees['trainingAuto']) ? 'checked' : '' ?>> Also raise the training fee automatically</label>
            <p class="sub">Participants pick their own plan on their dashboard. Left off, the training fee is raised by you
               from their record — one press, priced from the plan — so nobody can give themselves a ₦240,000 debt by clicking about.</p>
          </div>
          <div class="grid2">
            <div class="fld"><label>Charge nothing before</label>
              <input id="s_accrueFrom" type="date" value="<?= $e((string)($fees['accrueFrom'] ?? '')) ?>">
              <p class="sub">The rollout guard. Set to the month you switched fees on, so turning the ledger on for a
                 programme with history does not back-charge a year on the first run.</p></div>
            <div class="fld"><label>Stop an account at</label>
              <input id="s_balanceCap" type="number" min="0" step="1000" value="<?= (int)($fees['balanceCap'] ?? 0) ?>">
              <p class="sub">Accrual stops here rather than growing into a figure nobody will pay. 0 = no ceiling.</p></div>
          </div>
          <div class="grid2">
            <div class="fld"><label>Pin membership (₦/year)</label>
              <input id="s_membershipYearly" type="number" min="0" step="500" placeholder="auto — read off the page"
                     value="<?= $fees['membershipYearly'] === null ? '' : (int)$fees['membershipYearly'] ?>"></div>
            <div class="fld"><label>Pin commitment (₦/month)</label>
              <input id="s_commitmentMonthly" type="number" min="0" step="100" placeholder="auto — read off the page"
                     value="<?= $fees['commitmentMonthly'] === null ? '' : (int)$fees['commitmentMonthly'] ?>"></div>
          </div>

          <div class="fld"><label>Reminders</label>
            <label class="chk"><input type="checkbox" id="s_remindEnabled" <?= !empty($fees['remindEnabled']) ? 'checked' : '' ?>> Remind people what is outstanding</label>
            <div class="grid2" style="margin-top:8px">
              <input id="s_remindEveryDays" type="number" min="<?= (int)$B['remindMinDays'] ?>" max="<?= (int)$B['remindMaxDays'] ?>"
                     value="<?= (int)($fees['remindEveryDays'] ?? 21) ?>" title="Days between reminders">
              <input id="s_remindMinBalance" type="number" min="0" step="100" value="<?= (int)($fees['remindMinBalance'] ?? 1) ?>" title="Do not chase below (₦)">
            </div>
            <p class="sub">Days apart, then the smallest balance worth a message. The floor is <?= (int)$B['remindMinDays'] ?> days —
               anything tighter is how a programme gets its sender blocked and its people to stop reading anything it sends.</p>
          </div>
          <button class="btn primary" data-act="fees_settings">Save fee settings</button>
        </div>

        <!-- Running it -->
        <div>
          <div class="fld"><label>Bring charges up to date</label>
            <p class="sub">Adds any membership and monthly commitment not yet charged. Safe to press twice — it cannot
               charge the same month twice — and the cron does it too.</p>
            <button class="btn" data-act="accrue">Run accrual</button>
            <span id="accrueOut" class="sub"></span>
          </div>
          <div class="fld"><label>Chase what is outstanding</label>
            <p class="sub">Preview first: a message to sixty people cannot be recalled.</p>
            <button class="btn" data-act="remind_preview">Preview</button>
            <button class="btn" data-act="remind_run">Send them</button>
            <div id="remindOut" class="sub" style="margin-top:8px"></div>
          </div>

          <div class="fld"><label>Tell everybody where they stand</label>
            <p class="sub">A statement, not a reminder: every fee line, the training instalments month by month, each fine
               with the reason it was issued, anything set aside, and any damage report and its status. It goes to people
               who owe nothing too — under the reminder rules they could never be told they were square.</p>
            <button class="btn" data-act="statement_run">Send statements</button>
            <span id="stmtOut" class="sub"></span>
          </div>

          <?php $bf = NgvLedger::backfillReceipts(1, true); ?>
          <?php if ((int)$bf['pending'] > 0): ?>
          <div class="fld"><label>Receipts for older payments
              <span class="pill pill-open"><?= (int)$bf['pending'] ?> waiting</span></label>
            <p class="sub">
              <?= (int)$bf['sendablePayments'] ?> payment<?= (int)$bf['sendablePayments'] === 1 ? '' : 's' ?>
              across <?= (int)$bf['sendable'] ?> <?= (int)$bf['sendable'] === 1 ? 'person' : 'people' ?>
              <?= (int)$bf['sendablePayments'] === 1 ? 'has' : 'have' ?> no receipt out yet<?= $bf['oldest'] !== '' ? ', going back to ' . $e((string)$bf['oldest']) : '' ?>.
              <?php if ((int)$bf['noEmail'] > 0): ?>
                A further <?= (int)$bf['noEmailPayments'] ?> belong to <?= (int)$bf['noEmail'] ?> without an email address —
                they stay in the queue until one is added, rather than being marked done.
              <?php endif; ?>
            </p>
            <p class="sub"><b>One email per person, not per payment.</b> Somebody eighteen months in has a membership
               payment and a dozen commitments behind them; thirteen separate emails would read as something having gone
               wrong with their account, not as good record-keeping. Each digest says plainly that nothing has changed and
               nothing is being asked for.</p>
            <button class="btn" data-act="backfill_preview">Preview</button>
            <button class="btn" data-act="backfill_run">Send <?= (int)$bf['sendable'] > (int)NgvLedger::BACKFILL_BATCH
                ? 'the first ' . (int)NgvLedger::BACKFILL_BATCH : 'them' ?></button>
            <div id="bfOut" class="sub" style="margin-top:8px"></div>
            <p class="sub">Safe to press again — the queue is “no receipt sent yet”, so a run that stops halfway picks up
               exactly where it left off.</p>
          </div>
          <?php endif; ?>

          <div class="fld"><label>Find anybody's account</label>
            <form method="get" class="enroll">
              <?php if ($mid > 0): ?><input type="hidden" name="m" value="<?= $mid ?>"><?php endif; ?>
              <input name="q" value="<?= $e((string)($_GET['q'] ?? '')) ?>" placeholder="Name or email…">
              <button class="btn">Find</button>
            </form>
            <p class="sub">The arrears list only holds people who owe. This is how you answer “has Ada paid?” for somebody who has.</p>
            <?php if (!empty($look['rows'])): ?>
              <table style="margin-top:6px">
                <tbody>
                <?php foreach ($look['rows'] as $r): ?>
                  <tr><td><a href="?m=<?= (int)$r['member_id'] ?>"><?= $e((string)$r['name']) ?></a><br><span class="sub"><?= $e((string)$r['email']) ?></span></td>
                      <td class="sub"><?= $e((string)$r['status']) ?></td>
                      <td><?= (int)$r['payable'] > 0 ? '<b>₦' . number_format((int)$r['payable']) . '</b> due' : '<span class="sub">square</span>' ?>
                          <br><span class="sub">₦<?= number_format((int)$r['charged']) ?> charged</span></td></tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              <?php if (!empty($look['truncated'])): ?><p class="sub">More than <?= (int)$look['max'] ?> matched — narrow the search.</p><?php endif; ?>
            <?php elseif (!empty($look['q']) && empty($look['tooShort'])): ?>
              <p class="sub">Nobody matched “<?= $e((string)$look['q']) ?>”.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ══ Damage ═══════════════════════════════════════════════════════
           A report costs nothing. The status is the product: somebody who broke
           a laptop screen needs to know it is being priced, not to wonder for
           three weeks whether a bill is coming. -->
      <div class="fld" style="margin-top:6px">
        <label>Damage
          <?php if ((int)$dmgTot['open'] > 0): ?><span class="pill pill-open"><?= (int)$dmgTot['open'] ?> open</span><?php endif; ?>
        </label>
        <p class="sub">
          <?= (int)$dmgTot['records'] ?> recorded · ₦<?= number_format((int)$dmgTot['assessed']) ?> assessed ·
          ₦<?= number_format((int)$dmgTot['charged']) ?> charged ·
          <b>₦<?= number_format((int)$dmgTot['absorbed']) ?> absorbed by the programme</b>.
          Record damage on somebody's own page below; every move emails them.
        </p>
        <?php if (!$dmgAll): ?>
          <p class="sub">Nothing recorded.</p>
        <?php else: ?>
        <table>
          <thead><tr><th>What &amp; when</th><th>Who</th><th>Status</th><th>Cost / charged</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($dmgAll as $d): ?>
            <tr>
              <td><b><?= $e((string)$d['item']) ?></b><br>
                  <span class="sub"><?= $e((string)$d['occurred_on']) ?><?= $d['place'] !== '' ? ' · ' . $e((string)$d['place']) : '' ?>
                  · <?= $e((string)$d['severityLabel']) ?></span></td>
              <td><a href="?m=<?= (int)$d['member_id'] ?>"><?= $e((string)($d['name'] ?: ('#'.$d['member_id']))) ?></a>
                  <?php if ($d['selfReport']): ?><br><span class="pill pill-on">told us themselves</span><?php endif; ?></td>
              <td><span class="badge <?= $d['open'] ? 'b-applicant' : ($d['status']==='charged' ? 'b-withdrawn' : 'b-active') ?>">
                    <?= $e((string)$d['statusLabel']) ?></span>
                  <?php if (!$d['notify']): ?><br><span class="sub">emails off</span><?php endif; ?></td>
              <td class="sub"><?= (int)$d['assessed'] > 0 ? '₦' . number_format((int)$d['assessed']) : '—' ?>
                  <?= (int)$d['charged'] > 0 ? '<br><b>₦' . number_format((int)$d['charged']) . '</b> charged' : '' ?></td>
              <td><a class="btn sm" href="?m=<?= (int)$d['member_id'] ?>#damage">Open</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <?php $done = array_values(array_filter($reqs, static fn($r) => $r['status'] !== 'open')); ?>
      <?php if ($done): ?>
      <details class="fld" style="margin-top:6px"><summary class="sub">Answered requests (<?= count($done) ?>)</summary>
        <div class="reqs" style="margin-top:8px">
        <?php foreach (array_slice($done, 0, 12) as $rq): ?>
          <div class="req req--done">
            <div class="req-who"><a href="?m=<?= (int)$rq['member_id'] ?>"><?= $e((string)($rq['name'] ?: ('#'.$rq['member_id']))) ?></a>
              <span class="sub"><?= $e((string)$rq['kindLabel']) ?> · <?= $rq['status'] === 'declined' ? 'no change' : 'sorted' ?>
                · <?= $e(substr((string)$rq['handled_at'], 0, 10)) ?></span></div>
            <blockquote><?= $e((string)$rq['message']) ?></blockquote>
            <p class="sub"><?= $e((string)$rq['outcome']) ?></p>
          </div>
        <?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>

      <!-- Arrears, ranked by what is actually payable -->
      <div class="fld" style="margin-top:6px"><label>Behind (<?= (int)$arrears['matched'] ?>) · ₦<?= number_format((int)$arrears['totalPayable']) ?> outstanding</label>
        <?php if (empty($fees['enabled'])): ?>
          <p class="sub">Fees are switched off, so nothing is being charged and nobody is behind.</p>
        <?php elseif (!$arrears['rows']): ?>
          <p class="sub">Nobody is behind. </p>
        <?php else: ?>
        <table>
          <thead><tr><th>Who</th><th>Outstanding</th><th>Made up of</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($arrears['rows'] as $r): ?>
            <tr>
              <td><a href="?m=<?= (int)$r['member_id'] ?>"><?= $e((string)($r['name'] ?: ('#'.$r['member_id']))) ?></a>
                  <?php if ($r['remindOff']): ?><br><span class="sub">not being chased</span><?php endif; ?>
                  <?php if ($r['atCap']): ?><br><span class="sub">at the ceiling</span><?php endif; ?></td>
              <td><b>₦<?= number_format((int)$r['payable']) ?></b></td>
              <td class="sub"><?php
                  $bits = [];
                  foreach ($r['due'] as $k => $v) { if ((int)$v > 0) $bits[] = ($kindLabel[$k] ?? $k) . ' ₦' . number_format((int)$v); }
                  echo $e(implode(' · ', $bits)); ?></td>
              <td><a class="btn sm" href="?m=<?= (int)$r['member_id'] ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (!empty($arrears['truncated'])): ?><p class="sub">Showing the 50 largest.</p><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Applications inbox -->
  <div class="card" style="margin-bottom:18px">
    <header>Applications <span class="sp"></span><span class="sub"><?= count($apps) ?> shown · <a href="/academy/ngv/register.php" target="_blank" rel="noopener">registration page ↗</a></span></header>
    <div class="body">
      <?php if (!$apps): ?>
        <p class="sub">No applications yet. Share the <a href="/academy/ngv/register.php" target="_blank" rel="noopener">registration page</a> to start collecting them.</p>
      <?php else: ?>
      <table>
        <thead><tr><th>Applicant</th><th>Interest</th><th>Status</th><th>When</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($apps as $a): $st = (string)$a['status']; ?>
          <tr>
            <td><b><?= $e((string)($a['name'] ?: '—')) ?></b><br><span class="sub"><?= $e((string)$a['email']) ?><?= !empty($a['phone']) ? ' · '.$e((string)$a['phone']) : '' ?></span></td>
            <td class="sub"><?= $e(trim(((string)($a['track'] ?: '')).' '.((string)($a['plan'] ? '· '.$a['plan'] : '')), ' ·')) ?: '—' ?><?= !empty($a['location']) ? '<br>'.$e((string)$a['location']) : '' ?></td>
            <td><span class="badge b-<?= $st==='enrolled'?'active':($st==='rejected'?'withdrawn':($st==='accepted'?'completed':'applicant')) ?>"><?= $e($st) ?></span></td>
            <td class="sub"><?= $e(substr((string)$a['created_at'],0,10)) ?></td>
            <td>
              <?php if ($st !== 'enrolled'): ?>
                <button class="btn sm primary" data-act="app_enroll" data-app="<?= (int)$a['id'] ?>">Enrol</button>
                <?php /* `reviewing` and `accepted` were in the vocabulary with no way to
                         reach them, so every application sat at `new` until somebody
                         enrolled or rejected it — and a queue with one state cannot show
                         who has already been looked at. */ ?>
                <?php if ($st === 'new'): ?><button class="btn sm" data-act="app_status" data-app="<?= (int)$a['id'] ?>" data-status="reviewing">Reviewing</button><?php endif; ?>
                <?php if ($st === 'new' || $st === 'reviewing'): ?><button class="btn sm" data-act="app_status" data-app="<?= (int)$a['id'] ?>" data-status="accepted">Accept</button><?php endif; ?>
                <?php if ($st !== 'rejected'): ?><button class="btn sm" data-act="app_status" data-app="<?= (int)$a['id'] ?>" data-status="rejected">Reject</button><?php endif; ?>
              <?php else: ?>
                <?php if ((int)$a['member_id'] > 0): ?><a class="btn sm" href="?m=<?= (int)$a['member_id'] ?>">Open</a><?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="shell">
    <!-- Roster -->
    <div class="card">
      <header>Vanguards <span class="sp"></span><span class="sub">
        <?= count($roster) ?><?= $rosterAll > count($roster) ? ' of ' . $rosterAll : '' ?> shown<?php
          if ($filtered): ?> · <a href="?<?= $mid > 0 ? 'm=' . $mid : '' ?>">clear filter</a><?php endif; ?></span></header>
      <div class="body">
        <div class="enroll" style="margin-bottom:10px">
          <input id="enrollEmail" type="email" placeholder="Enrol by member email…">
          <button class="btn primary" id="enrollBtn">Enrol</button>
        </div>
        <!-- Filters in the query string, so a filtered view is a URL a
             coordinator can send to another one. -->
        <form method="get" class="rfilter" style="margin-bottom:14px">
          <?php if ($mid > 0): ?><input type="hidden" name="m" value="<?= $mid ?>"><?php endif; ?>
          <input name="r" value="<?= $e($fQuery) ?>" placeholder="Name, email or track…">
          <select name="s">
            <option value="">Any status</option>
            <?php foreach (NgvMember::STATUSES as $st): ?>
              <option value="<?= $e($st) ?>" <?= $fStatus === $st ? 'selected' : '' ?>><?= $e(ucfirst($st)) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($cohorts): ?>
          <select name="c">
            <option value="">Any cohort</option>
            <?php foreach ($cohorts as $co): ?>
              <option value="<?= $e($co) ?>" <?= $fCohort === $co ? 'selected' : '' ?>><?= $e($co) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
          <button class="btn">Filter</button>
        </form>
        <table>
          <thead><tr><th>Name</th><th>Track</th><th>Status</th><th>Received</th><th>Certs</th></tr></thead>
          <tbody>
          <?php foreach ($roster as $r): $on = $sel && (int)$r['member_id'] === (int)$sel['member_id']; ?>
            <tr class="<?= $on ? 'on' : '' ?>">
              <td><a href="?m=<?= (int)$r['member_id'] ?>"><?= $e((string)($r['name'] ?: ('#'.$r['member_id']))) ?></a><br><span class="sub"><?= $e((string)$r['email']) ?></span></td>
              <td><?= $e((string)($r['track'] ?: '—')) ?><?= !empty($r['cohort']) ? '<br><span class="sub">'.$e((string)$r['cohort']).'</span>' : '' ?></td>
              <td><span class="badge b-<?= $e((string)$r['status']) ?>"><?= $e((string)$r['status']) ?></span></td>
              <td>₦<?= number_format((int)($r['paid_total'] ?? 0)) ?></td>
              <td><?= (int)($r['cert_count'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$roster): ?><tr><td colspan="5" class="sub"><?= $filtered
              ? 'Nobody matches that filter. <a href="?' . ($mid > 0 ? 'm=' . $mid : '') . '">Show everybody</a>.'
              : 'No participants yet — enrol a member by email above.' ?></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Detail -->
    <div class="card">
      <header>Manage<?= $sel ? ' · ' . $e((string)($sel['name'] ?: ('#'.$sel['member_id']))) : '' ?></header>
      <div class="body">
      <?php if (!$sel): ?>
        <p class="sub">Select a vanguard on the left to record fees, add certifications, or change their status.</p>
      <?php else: $m = (int)$sel['member_id']; ?>
        <div class="fld"><span class="sub"><?= $e((string)$sel['email']) ?> · member #<?= $m ?> · joined <?= $e(substr((string)($sel['start_date'] ?: $sel['created_at']),0,10)) ?></span></div>

        <div class="fld"><label>Enrolment</label>
          <div class="grid2">
            <select id="f_status">
              <?php foreach (NgvMember::STATUSES as $s): ?><option value="<?= $e($s) ?>" <?= $sel['status']===$s?'selected':'' ?>><?= $e(ucfirst($s)) ?></option><?php endforeach; ?>
            </select>
            <select id="f_track">
              <option value="">— No track —</option>
              <?php foreach ($trackNames as $tn): ?><option value="<?= $e($tn) ?>" <?= $sel['track']===$tn?'selected':'' ?>><?= $e($tn) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="grid2" style="margin-top:10px">
            <select id="f_plan">
              <option value="">— No plan —</option>
              <?php foreach ($planCat as $pn => $pl): ?>
                <option value="<?= $e($pn) ?>" <?= ((string)($sel['plan'] ?? '')) === $pn ? 'selected' : '' ?>>
                  <?= $e($pn) ?> · <?= $e((string)$pl['priceLabel']) ?></option>
              <?php endforeach; ?>
            </select>
            <input id="f_cohort" placeholder="Cohort (e.g. 2026 Alpha)" value="<?= $e((string)$sel['cohort']) ?>">
          </div>
          <div class="grid2" style="margin-top:10px">
            <label class="sub">Enrolled from
              <input id="f_start" type="date" value="<?= $e(substr((string)($sel['start_date'] ?: $sel['created_at']), 0, 10)) ?>"
                     max="<?= $e(function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d')) ?>"></label>
            <select id="f_phase">
              <option value="" <?= $sel['phase']===''?'selected':'' ?>>Phase — not set</option>
              <option value="1" <?= $sel['phase']==='1'?'selected':'' ?>>Phase 1</option>
              <option value="2" <?= $sel['phase']==='2'?'selected':'' ?>>Phase 2</option>
              <option value="done" <?= $sel['phase']==='done'?'selected':'' ?>>Completed</option>
            </select>
          </div>
          <div style="margin-top:10px"><button class="btn primary sm" data-act="admin" data-m="<?= $m ?>">Save enrolment</button></div>
          <p class="sub"><b>Enrolled from</b> decides what the accrual charges from and when a training schedule starts.
             Changing it moves what happens NEXT — charges already posted keep the figures they were posted at, like
             everything else here. Void them if they should not have existed.</p>
        </div>

        <!-- ── Their account ──────────────────────────────────────────────
             One figure, then the lines it is made of, then every entry behind
             it. Nothing here is computed in the template. -->
        <?php $A = $selAcct; ?>
        <div class="fld"><label>Account</label>
          <?php if (empty($A['enabled'])): ?>
            <p class="sub">Fees are switched off, so nothing is being charged. Turn them on in <b>Fees &amp; dues</b> above.</p>
          <?php endif; ?>
          <div class="figure <?= (int)$A['payable'] > 0 ? '' : 'clear' ?>">
            <?= (int)$A['payable'] > 0 ? '₦' . number_format((int)$A['payable']) : 'All clear' ?>
            <span class="sub"><?= (int)$A['payable'] > 0 ? 'outstanding' : 'nothing outstanding' ?></span>
          </div>
          <?php if ((int)$A['paidAhead'] > 0): ?>
            <p class="sub">₦<?= number_format((int)$A['paidAhead']) ?> paid ahead on a line that is already settled — it is
               reported rather than netted off, so it cannot hide arrears somewhere else.</p>
          <?php endif; ?>
          <?php if ((int)$A['unallocated'] > 0): ?>
            <p class="sub">₦<?= number_format((int)$A['unallocated']) ?> received without a fee line against it. It reduces the
               account, not a line.</p>
          <?php endif; ?>
          <?php if (!empty($A['atCap'])): ?>
            <p class="sub">This account is at the ₦<?= number_format((int)$A['cap']) ?> ceiling — accrual has stopped.</p>
          <?php endif; ?>

          <table style="margin-top:8px">
            <tbody>
            <?php foreach ($A['lines'] as $ln): ?>
              <tr>
                <td><b><?= $e((string)$ln['label']) ?></b><br><span class="sub"><?= $e((string)$ln['detail']) ?></span></td>
                <td style="text-align:right;white-space:nowrap">
                  <?php if (!empty($ln['free'])): ?><span class="badge b-active">Free</span>
                  <?php elseif ((int)$ln['due'] > 0): ?><b>₦<?= number_format((int)$ln['due']) ?></b><br><span class="sub">due</span>
                  <?php elseif ((int)$ln['charged'] > 0): ?><span class="badge b-active">Settled</span>
                  <?php else: ?><span class="sub">—</span><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn sm" data-act="statement" data-m="<?= $m ?>">Email them a statement</button>
            <button class="btn sm" data-act="remind_off" data-m="<?= $m ?>" data-off="<?= !empty($A['remindOff']) ? '0' : '1' ?>">
              <?= !empty($A['remindOff']) ? 'Start chasing again' : 'Stop chasing this person' ?></button>
          </div>
          <p class="sub">A statement says where they stand — fee lines, instalments, fines with their reasons, and any
             damage. Safe to send to somebody who owes nothing.</p>
        </div>

        <!-- The training fee, as a commitment with a shape. ₦240,000 posted as
             one charge is a wall: the same fact shouted every fortnight, and a
             reminder quoting a figure nobody could pay this month. -->
        <div class="fld"><label>Training fee</label>
          <?php $T = is_array($A['training'] ?? null) && !empty($A['training']) ? $A['training'] : null; ?>
          <?php if ($T): ?>
            <p class="sub"><b>₦<?= number_format((int)$T['total']) ?></b>
               <?= (int)$T['months'] > 1 ? 'over ' . (int)$T['months'] . ' months from ' . $e((string)$T['from']) : 'in full' ?>
               · <?= (int)$T['settled'] ?> charged · ₦<?= number_format((int)$T['paid']) ?> paid</p>
            <div class="insts">
              <?php foreach ($T['instalments'] as $i): ?>
                <span class="inst inst--<?= $e((string)$i['state']) ?>" title="<?= $e((string)$i['period']) ?> · ₦<?= number_format((int)$i['amount']) ?>">
                  <?= (int)$i['n'] ?></span>
              <?php endforeach; ?>
            </div>
            <p class="sub">Charged · due now · still to come. Each instalment lands as its month arrives.</p>
            <div style="margin-top:8px"><button class="btn sm" data-act="training_stop" data-m="<?= $m ?>">Stop future instalments</button></div>
            <p class="sub">Stopping leaves what has already been charged on the account — that happened. Whether the rest
               should still be asked for is a separate decision: waive it, or write it off.</p>
          <?php else: ?>
            <div class="grid2">
              <input id="t_amount" type="number" min="0" step="1000"
                     value="<?= (int)$A['planFee'] > 0 ? (int)$A['planFee'] : '' ?>"
                     placeholder="Total (₦)<?= (int)$A['planFee'] > 0 ? '' : ' — no paid plan chosen' ?>">
              <select id="t_months">
                <?php foreach ([1 => 'In full', 3 => 'Over 3 months', 6 => 'Over 6 months', 12 => 'Over 12 months'] as $mo => $lbl): ?>
                  <option value="<?= $mo ?>" <?= (int)($fees['trainingInstalments'] ?? 12) === $mo ? 'selected' : '' ?>><?= $e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="margin-top:10px"><button class="btn sm" data-act="training" data-m="<?= $m ?>">Agree this training fee</button></div>
            <p class="sub">Prefilled from their plan (<?= $A['planLabel'] !== '' ? $e((string)$A['planLabel']) : 'none chosen yet' ?>), and
               the total is frozen once agreed — a later price edit on the public page moves what the next person is quoted, not this.</p>
          <?php endif; ?>
        </div>

        <div class="fld"><label>Record a payment</label>
          <div class="grid2">
            <select id="p_kind">
              <?php foreach (NgvLedger::CREDIT_LINES as $k): ?><option value="<?= $e($k) ?>" <?= $k==='commitment'?'selected':'' ?>><?= $e($kindLabel[$k] ?? $k) ?></option><?php endforeach; ?>
            </select>
            <input id="p_amount" type="number" min="0" step="100" placeholder="Amount (₦)">
          </div>
          <div class="grid2" style="margin-top:10px">
            <input id="p_period" placeholder="For which period: 2026 or 2026-08">
            <input id="p_method" placeholder="Method (transfer, cash…)">
          </div>
          <input id="p_ref" style="margin-top:10px" placeholder="Reference / note (optional)">
          <label class="chk" style="margin-top:10px"><input type="checkbox" id="p_receipt" checked> Email them a receipt now</label>
          <div style="margin-top:6px"><button class="btn primary sm" data-act="payment" data-m="<?= $m ?>">Record payment</button></div>
          <p class="sub">Allocated to the line it pays, so “square on membership, two months behind on commitment” survives
             into the figure instead of being flattened into one total. Somebody who handed over cash has no other proof it
             arrived, so the receipt goes immediately — untick it only when typing in a backlog.</p>
        </div>

        <!-- A fine is the one charge somebody will dispute, so the reason is
             part of the row rather than a note somebody may or may not write. -->
        <div class="fld"><label>Issue a fine or an adjustment</label>
          <div class="grid2">
            <select id="x_kind"><option value="fine">Fine</option><option value="adjustment">Adjustment</option></select>
            <input id="x_amount" type="number" min="0" step="100" placeholder="Amount (₦)">
          </div>
          <select id="x_reason" style="margin-top:10px">
            <?php foreach (NgvLedger::FINE_REASONS as $k => $lbl): ?><option value="<?= $e($k) ?>"><?= $e($lbl) ?></option><?php endforeach; ?>
          </select>
          <input id="x_note" style="margin-top:10px" placeholder="What happened (required for “Other” and for adjustments)">
          <div style="margin-top:10px"><button class="btn sm" data-act="charge" data-m="<?= $m ?>">Post it</button></div>
          <p class="sub">“Late four times in August” is answerable later. “Misconduct” is not.</p>
        </div>

        <!-- No one is turned away for lack: the page says so, so the ledger has
             to be able to act on it, and to record who did. -->
        <div class="fld" id="waive"><label>Stop asking for part of what is owed</label>
          <div class="grid2">
            <select id="w_kind">
              <?php foreach (['commitment','membership','programme','fine','other'] as $k): ?><option value="<?= $e($k) ?>"><?= $e($kindLabel[$k] ?? $k) ?></option><?php endforeach; ?>
            </select>
            <input id="w_amount" type="number" min="0" step="100" placeholder="Amount (₦)">
          </div>
          <input id="w_reason" style="margin-top:10px" placeholder="Why (required — this is the part worth reading later)">
          <div style="margin-top:10px">
            <button class="btn sm" data-act="waive" data-m="<?= $m ?>">Waive it</button>
            <button class="btn sm" data-act="writeoff" data-m="<?= $m ?>">Write it off</button>
          </div>
          <p class="sub"><b>Waive</b> when this person should not be asked — no one is turned away for lack.
             <b>Write off</b> when the programme has stopped carrying the balance at all, which is usually what a
             withdrawal leaves behind. Either way the charge stays on the record: voiding would say it should never
             have existed, which is a different and usually untrue thing.</p>
        </div>

        <?php if (!empty($A['entries'])): ?>
        <div class="fld"><label>Ledger (<?= count($A['entries']) ?>)</label>
          <?php foreach ($A['entries'] as $en): ?>
          <div class="pay-row<?= $en['void'] ? ' voided' : '' ?>">
            <span>
              <span class="dir <?= $en['side'] ?>"><?= $en['side'] === 'charge' ? '+' : '−' ?></span>
              ₦<?= number_format((int)$en['amount']) ?>
              <b><?= $e($en['side'] === 'credit' ? ($creditWord[$en['creditKind']] ?? 'Payment') . ' · ' . ($kindLabel[$en['kind']] ?? $en['kind'])
                                                 : ($kindLabel[$en['kind']] ?? $en['kind'])) ?></b>
              <?= $en['period'] !== '' ? '<span class="sub">(' . $e((string)$en['period']) . ')</span>' : '' ?>
              <?php if ($en['note'] !== ''): ?><br><span class="sub"><?= $e((string)$en['note']) ?></span><?php endif; ?>
              <?php if ($en['reference'] !== '' || $en['method'] !== ''): ?><br><span class="sub"><?= $e(trim($en['method'] . ' ' . $en['reference'])) ?></span><?php endif; ?>
              <?php if ($en['side'] === 'credit' && $en['creditKind'] === 'payment'):
                        $rc = NgvLedger::receiptFor((int) $en['id']); ?>
                <?php if ($rc): ?>
                <br><span class="sub">Receipt
                  <a href="/academy/ngv/receipt.php?id=<?= (int)$rc['id'] ?>&amp;c=<?= urlencode($rc['code']) ?>"
                     target="_blank" rel="noopener"><?= $e($rc['no']) ?></a>
                  <?= $rc['issuedAt'] !== '' ? '· emailed ' . $e(substr((string)$rc['issuedAt'], 0, 10)) : '· not emailed yet' ?></span>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($en['void']): ?><br><span class="sub">Void — <?= $e((string)$en['voidReason']) ?></span><?php endif; ?>
            </span>
            <span class="sp"></span>
            <span class="sub"><?= $e(substr((string)$en['created_at'], 0, 10)) ?></span>
            <?php if (!$en['void'] && $en['side'] === 'credit' && $en['creditKind'] === 'payment'): ?>
              <button class="btn sm" data-act="receipt" data-pid="<?= (int)$en['id'] ?>">Receipt</button>
            <?php endif; ?>
            <?php if (!$en['void']): ?>
              <button class="btn sm" data-act="void" data-side="<?= $e($en['side']) ?>" data-eid="<?= (int)$en['id'] ?>">Void</button>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <p class="sub">Nothing is ever deleted. A corrected mistake stays here with its reason, because “why is this
             different from last month” is the question an account has to be able to answer.</p>
        </div>
        <?php endif; ?>

        <!-- ══ Damage ═══════════════════════════════════════════════════════
             The incident, then — separately, later, and only if somebody decides
             — the money. `assessed` is what it cost; `charged` is what this
             person is being asked for. They are allowed to differ, and a
             programme that bills a nineteen-year-old retail for an accident
             should have to type that number rather than get it by default. -->
        <div class="fld" id="damage"><label>Damage</label>
          <?php if ($selDmg): ?>
            <?php foreach ($selDmg as $d): ?>
              <div class="dmg">
                <div class="dmg-head">
                  <b><?= $e((string)$d['item']) ?></b>
                  <span class="badge <?= $d['open'] ? 'b-applicant' : ($d['status']==='charged' ? 'b-withdrawn' : 'b-active') ?>"><?= $e((string)$d['statusLabel']) ?></span>
                  <?php if ($d['selfReport']): ?><span class="pill pill-on">told us themselves</span><?php endif; ?>
                  <span class="sp"></span>
                  <span class="sub"><?= $e((string)$d['occurred_on']) ?></span>
                </div>
                <p class="sub"><?= $e((string)$d['severityLabel']) ?><?= $d['place'] !== '' ? ' · ' . $e((string)$d['place']) : '' ?>
                  <?php if ((int)$d['estimate'] > 0): ?> · first guess ₦<?= number_format((int)$d['estimate']) ?><?php endif; ?>
                  <?php if ((int)$d['assessed'] > 0): ?> · assessed <b>₦<?= number_format((int)$d['assessed']) ?></b><?php endif; ?>
                  <?php if ((int)$d['charged'] > 0): ?> · charged <b>₦<?= number_format((int)$d['charged']) ?></b><?php endif; ?>
                </p>
                <blockquote><?= $e((string)$d['description']) ?></blockquote>
                <?php if (!empty($d['photos'])): ?>
                  <div class="shots">
                    <?php foreach ($d['photos'] as $ph): ?>
                      <a href="<?= $e((string)$ph) ?>" target="_blank" rel="noopener">
                        <img src="<?= $e((string)$ph) ?>" alt="Photo of the reported damage to <?= $e((string)$d['item']) ?>" loading="lazy"></a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <?php /* Staff can attach to a CLOSED record too — evidence often turns up
                         after the fact, and the file should be able to hold it. The member
                         side deliberately cannot: offering somebody an upload on a record
                         nobody will look at again implies an action that is not coming. */ ?>
                <?php if (count($d['photos']) < NgvDamage::PHOTOS_MAX): ?>
                  <label class="sub" style="display:block;margin-top:8px">Add a photo
                    <input type="file" class="d-photo" data-for="<?= (int)$d['id'] ?>" accept="image/*" multiple
                           style="margin-top:4px"></label>
                  <button class="btn sm" data-act="damage_photos" data-dmg="<?= (int)$d['id'] ?>">Attach</button>
                <?php endif; ?>
                <?php if ($d['outcome'] !== ''): ?><p class="sub"><b>Told them:</b> <?= $e((string)$d['outcome']) ?></p><?php endif; ?>
                <?php if ($d['open']): ?>
                  <div class="grid2">
                    <input class="d-assessed" data-for="<?= (int)$d['id'] ?>" type="number" min="0" step="500"
                           value="<?= (int)$d['assessed'] > 0 ? (int)$d['assessed'] : '' ?>" placeholder="What it costs (₦)">
                    <input class="d-charged" data-for="<?= (int)$d['id'] ?>" type="number" min="0" step="500"
                           placeholder="What to ask them for (₦)">
                  </div>
                  <input class="d-outcome" data-for="<?= (int)$d['id'] ?>" style="margin-top:8px"
                         placeholder="What you are telling them — they get this by email">
                  <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
                    <button class="btn sm" data-act="damage_advance" data-dmg="<?= (int)$d['id'] ?>" data-status="assessing">Being assessed</button>
                    <button class="btn sm" data-act="damage_advance" data-dmg="<?= (int)$d['id'] ?>" data-status="charged">Charge it</button>
                    <button class="btn sm" data-act="damage_advance" data-dmg="<?= (int)$d['id'] ?>" data-status="waived">Waive it</button>
                    <button class="btn sm" data-act="damage_advance" data-dmg="<?= (int)$d['id'] ?>" data-status="closed">Close, nothing owed</button>
                  </div>
                  <p class="sub">Each of these emails them. “Charge it” raises a fine with reason <i>equipment</i> on their
                     account — it shows in the fines line and is voided or waived like any other charge.</p>
                <?php else: ?>
                  <p class="sub">Closed <?= $e(substr((string)$d['updated_at'], 0, 10)) ?>.
                    <?php if ((int)$d['assessed'] > (int)$d['charged']): ?>
                      The programme carried ₦<?= number_format((int)$d['assessed'] - (int)$d['charged']) ?> of this.
                    <?php endif; ?></p>
                <?php endif; ?>
                <button class="btn sm" data-act="damage_notify" data-dmg="<?= (int)$d['id'] ?>" data-on="<?= $d['notify'] ? '0' : '1' ?>">
                  <?= $d['notify'] ? 'Stop emailing about this' : 'Email them about this again' ?></button>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

          <details class="fld" style="margin-top:<?= $selDmg ? '12' : '0' ?>px">
            <summary class="sub">Record damage</summary>
            <div class="grid2" style="margin-top:8px">
              <input id="dm_item" placeholder="What was damaged (required)">
              <input id="dm_when" type="date" value="<?= $e(function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d')) ?>">
            </div>
            <div class="grid2" style="margin-top:8px">
              <select id="dm_sev">
                <?php foreach (NgvDamage::SEVERITIES as $k => $lbl): ?><option value="<?= $e($k) ?>"><?= $e($lbl) ?></option><?php endforeach; ?>
              </select>
              <input id="dm_place" placeholder="Where (optional)">
            </div>
            <textarea id="dm_desc" rows="2" style="margin-top:8px" placeholder="What happened (required)"></textarea>
            <input id="dm_est" type="number" min="0" step="500" style="margin-top:8px" placeholder="First guess at the cost, if you have one (₦)">
            <div style="margin-top:8px"><button class="btn sm" data-act="damage" data-m="<?= $m ?>">Record it</button></div>
            <p class="sub">This charges nothing. It emails them to say it has been recorded, that nothing is on their
               account, and that they will be told the cost before anything is.</p>
          </details>
        </div>

        <div class="fld"><label>Add a certification</label>
          <input id="c_title" placeholder="Certificate title (required)">
          <div class="grid2" style="margin-top:10px">
            <input id="c_by" placeholder="Issued by">
            <input id="c_on" type="date">
          </div>
          <div style="margin-top:10px"><button class="btn primary sm" data-act="cert" data-m="<?= $m ?>">Add certification</button></div>
        </div>

        <?php if ($selCerts): ?>
        <div class="fld"><label>Certifications</label>
          <?php foreach ($selCerts as $cert): ?>
          <div class="pay-row<?= !empty($cert['revoked']) ? ' voided' : '' ?>">
            <span>🏅 <b><?= $e((string)$cert['title']) ?></b>
              <span class="sub"><?= $e((string)$cert['issued_by']) ?></span>
              <br><span class="sub">
                <a href="/academy/ngv/certificate.php?id=<?= (int)$cert['id'] ?>&amp;c=<?= urlencode((string)$cert['code']) ?>"
                   target="_blank" rel="noopener">verify link</a>
                <?php if (!empty($cert['revoked'])): ?>
                  · revoked <?= $e(substr((string)$cert['revoked_at'], 0, 10)) ?>
                  <?= (string)$cert['revoke_reason'] !== '' ? '— ' . $e((string)$cert['revoke_reason']) : '' ?>
                <?php endif; ?>
              </span>
            </span>
            <span class="sp"></span>
            <span class="sub"><?= $e(substr((string)($cert['issued_on'] ?: $cert['created_at']),0,10)) ?></span>
            <?php if (empty($cert['revoked'])): ?>
              <button class="btn sm" data-act="cert_revoke" data-cert="<?= (int)$cert['id'] ?>">Revoke</button>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <p class="sub">Revoked, never deleted. The link is public and may already be with an employer — deleting it
             would make a real link read as a forgery, so it keeps working and says it was withdrawn.</p>
        </div>
        <?php endif; ?>
      <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var CSRF = <?= json_encode($csrf) ?>;
  var msg = document.getElementById('msg'), mt;
  function toast(t, good){ if(!msg) return; msg.textContent = t; msg.style.color = good===false ? '#ffb4ab' : '#9be7b4'; clearTimeout(mt); mt=setTimeout(function(){msg.textContent='';},2500); }
  function post(body){
    return fetch(location.pathname, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, credentials:'same-origin', body:JSON.stringify(body)})
      .then(function(r){ return r.json().catch(function(){return {ok:false};}); });
  }
  var val = function(id){ var el=document.getElementById(id); return el?el.value:''; };

  var eb = document.getElementById('enrollBtn');
  if(eb) eb.addEventListener('click', function(){
    var email = val('enrollEmail').trim(); if(!email){ toast('Enter an email', false); return; }
    post({action:'enroll', email:email}).then(function(j){ if(j.ok){ toast('Enrolled ✓'); location.href='?m='+j.member_id; } else toast(j.error||'Failed', false); });
  });

  var chk = function(id){ var el=document.getElementById(id); return !!(el && el.checked); };
  var money = function(n){ return '₦' + (n||0).toLocaleString('en-NG'); };
  var out = function(id, html){ var el=document.getElementById(id); if(el) el.innerHTML = html; };

  /* Photos go as multipart, so they cannot ride post(), which sends JSON. Its
     own handler, with the same disable-in-flight and always-recover rules. */
  document.querySelectorAll('[data-act="damage_photos"]').forEach(function(btn){
    btn.addEventListener('click', function(ev){
      ev.stopImmediatePropagation();
      var id = parseInt(btn.getAttribute('data-dmg')||'0',10);
      var input = document.querySelector('.d-photo[data-for="' + id + '"]');
      if(!input || !input.files || !input.files.length){ toast('Choose a photo first', false); return; }
      var fd = new FormData();
      fd.append('action','damage_photos'); fd.append('damage_id', String(id));
      for(var i=0;i<input.files.length;i++) fd.append('photos[]', input.files[i]);
      btn.disabled = true; toast('Uploading…');
      fetch(location.pathname, {method:'POST', headers:{'X-CSRF-Token':CSRF}, credentials:'same-origin', body:fd})
        .then(function(r){ return r.json().catch(function(){return {ok:false, error:'Upload rejected — the file may be too large for this server.'};}); })
        .then(function(j){
          btn.disabled = false;
          if(j.ok){ toast(j.warning ? 'Attached ' + j.added + ' — ' + j.warning : 'Attached ✓', !j.warning);
                    setTimeout(function(){location.reload();}, j.warning ? 1800 : 400); }
          else toast(j.error||'Could not attach that', false);
        })
        .catch(function(){ btn.disabled = false; toast('Offline — nothing was attached.', false); });
    });
  });

  document.querySelectorAll('[data-act]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var act = btn.getAttribute('data-act'), m = parseInt(btn.getAttribute('data-m')||'0',10), body={action:act, member_id:m};
      if(act==='damage_photos') return;              // handled above, as multipart
      // Actions that render their own result rather than reloading the page.
      var quiet = {remind_preview:1, remind_run:1, accrue:1, backfill_preview:1, backfill_run:1};
      if(act==='admin'){ body.status=val('f_status'); body.track=val('f_track'); body.cohort=val('f_cohort'); body.phase=val('f_phase'); body.plan=val('f_plan'); body.start_date=val('f_start'); }
      else if(act==='cert_revoke'){
        body.cert_id = parseInt(btn.getAttribute('data-cert')||'0',10);
        var why = prompt('Revoke this certificate — why? The holder and anyone with the link can see this.');
        if(why===null || !why.trim()) return;
        body.reason = why;
      }
      else if(act==='payment'){ body.kind=val('p_kind'); body.amount=val('p_amount'); body.period=val('p_period'); body.method=val('p_method'); body.reference=val('p_ref'); body.receipt=chk('p_receipt'); if(!body.amount){ toast('Enter an amount', false); return; } }
      else if(act==='receipt'){ body.payment_id=parseInt(btn.getAttribute('data-pid')||'0',10); }
      else if(act==='charge'){ body.kind=val('x_kind'); body.amount=val('x_amount'); body.reason=val('x_reason'); body.note=val('x_note');
                               if(!body.amount){ toast('Enter an amount', false); return; }
                               if(!confirm('Post a ' + body.kind + ' of ₦' + body.amount + '?')) return; }
      else if(act==='waive' || act==='writeoff'){ body.kind=val('w_kind'); body.amount=val('w_amount'); body.reason=val('w_reason');
                              if(!body.amount){ toast('Enter an amount', false); return; }
                              if(!body.reason){ toast('Say why — it is the part worth reading later', false); return; }
                              if(act==='writeoff' && !confirm('Write off ₦' + body.amount + '? Use “waive” if this person simply should not be asked.')) return; }
      else if(act==='training'){ body.amount=val('t_amount'); body.months=parseInt(val('t_months')||'1',10);
                                 if(!body.amount){ toast('Enter a total', false); return; }
                                 if(!confirm('Agree ₦' + body.amount + (body.months>1 ? ' over ' + body.months + ' months?' : ' in full?'))) return; }
      else if(act==='training_stop'){ if(!confirm('Stop future instalments? What is already charged stays on the account.')) return; }
      else if(act==='remind_off'){ body.off = btn.getAttribute('data-off')==='1'; }
      else if(act==='cert'){ body.title=val('c_title'); body.issued_by=val('c_by'); body.issued_on=val('c_on'); if(!body.title){ toast('Title required', false); return; } }
      else if(act==='void'){ body.side=btn.getAttribute('data-side')||'credit'; body.entry_id=parseInt(btn.getAttribute('data-eid')||'0',10);
                             // Voiding a receipted payment emails a cancellation — say so before, not after.
                             if(!confirm('Void this entry? If a receipt went out for it, they will be emailed to say it is cancelled.')) return;
                             // A void needs a reason: without one it is a deletion, and the
                             // server refuses it anyway — better to ask here than to fail there.
                             var why = prompt('Void this entry — why? (it stays on the account with this reason)');
                             if(why===null || !why.trim()){ return; } body.reason = why; }
      else if(act==='app_status'){ body.app_id=parseInt(btn.getAttribute('data-app')||'0',10); body.status=btn.getAttribute('data-status')||''; if(body.status==='rejected' && !confirm('Reject this application?')) return; }
      else if(act==='app_enroll'){ body.app_id=parseInt(btn.getAttribute('data-app')||'0',10); }
      else if(act==='fees_settings'){
        body.settings = {
          enabled: chk('s_enabled'), trainingAuto: chk('s_trainingAuto'),
          accrueFrom: val('s_accrueFrom'), balanceCap: val('s_balanceCap'),
          membershipYearly: val('s_membershipYearly'), commitmentMonthly: val('s_commitmentMonthly'),
          remindEnabled: chk('s_remindEnabled'), remindEveryDays: val('s_remindEveryDays'),
          remindMinBalance: val('s_remindMinBalance')
        };
      }
      else if(act==='remind_run'){ if(!confirm('Send reminders now? This cannot be recalled.')) return; }
      else if(act==='statement'){ if(!confirm('Email them a statement of where their account stands?')) return; }
      else if(act==='statement_run'){ if(!confirm('Send a statement to everybody with an account? This cannot be recalled.')) return; }
      else if(act==='backfill_run'){ if(!confirm('Send receipts for older payments? One email per person. This cannot be recalled.')) return; }
      else if(act==='damage'){
        body.item=val('dm_item'); body.occurred_on=val('dm_when'); body.severity=val('dm_sev');
        body.place=val('dm_place'); body.description=val('dm_desc'); body.estimate=val('dm_est');
        if(!body.item){ toast('What was damaged?', false); return; }
        if(!body.description){ toast('What happened?', false); return; }
      }
      else if(act==='damage_advance'){
        body.damage_id = parseInt(btn.getAttribute('data-dmg')||'0',10);
        body.status = btn.getAttribute('data-status')||'';
        var pick = function(cls){ var el=document.querySelector(cls+'[data-for="'+body.damage_id+'"]'); return el?el.value:''; };
        body.assessed = pick('.d-assessed'); body.charged = pick('.d-charged'); body.outcome = pick('.d-outcome');
        if(body.status==='charged'){
          if(!body.charged){ toast('Enter what they are being asked for', false); return; }
          if(!confirm('Put ₦' + body.charged + ' on their account as a fine, and email them?')) return;
        }
        if(body.status==='waived' && !body.outcome.trim()){ toast('Say why — they see this', false); return; }
      }
      else if(act==='damage_notify'){ body.damage_id=parseInt(btn.getAttribute('data-dmg')||'0',10); body.on = btn.getAttribute('data-on')==='1'; }
      else if(act==='request'){
        body.request_id = parseInt(btn.getAttribute('data-req')||'0',10);
        body.status = btn.getAttribute('data-status')||'resolved';
        var box = document.querySelector('.req-out[data-for="' + body.request_id + '"]');
        body.outcome = box ? box.value : '';
        // They see this. An empty reply is the "request into a void" this exists to stop.
        if(!body.outcome.trim()){ toast('Say what you are telling them', false); if(box) box.focus(); return; }
      }

      /* Every one of these can send email or move money, and a second click
         before the first returns sends it twice. Re-enabled in the same place
         the response is handled, including the failure path. */
      btn.disabled = true;
      var release = function(){ btn.disabled = false; };
      post(body).then(function(j){
        release();
        if(!j.ok){ toast(j.error||'Failed', false); return; }
        if(act==='accrue'){
          var a = j.accrued||{};
          out('accrueOut', ' Charged ' + (a.membership||0) + ' membership, ' + (a.commitment||0) + ' commitment and '
              + (a.programme||0) + ' training across ' + (a.participants||0) + ' participant(s).'
              + ((a.atCap||0) ? ' ' + a.atCap + ' at the ceiling.' : '')
              + ((a.noStartDate||0) ? ' ' + a.noStartDate + ' with no start date.' : ''));
          toast('Accrual run ✓'); return;
        }
        if(act==='remind_preview'){
          // Every exclusion is named: "it would send 4 of 60" is not checkable.
          var s = j.skipped||{}, names = (j.due||[]).map(function(d){ return d.name + ' (' + money(d.payable) + ')'; });
          out('remindOut', '<b>' + j.count + '</b> would be sent, ' + j.everyDays + ' days apart.'
              + (names.length ? '<br>' + names.join(' · ') : '')
              + '<br>Not sent: ' + [
                  s.notCharged ? s.notCharged + ' have nothing charged yet' : '',
                  s.optedOut ? s.optedOut + ' asked not to be chased' : '',
                  s.notActive ? s.notActive + ' not currently enrolled' : '',
                  s.nothingPayable ? s.nothingPayable + ' owe nothing' : '',
                  s.underMinimum ? s.underMinimum + ' below the minimum' : '',
                  s.tooSoon ? s.tooSoon + ' reminded recently' : '',
                  s.noEmail ? s.noEmail + ' have no email' : '',
                  s.off ? 'reminders are switched off' : ''
                ].filter(Boolean).join(' · '));
          return;
        }
        if(act==='remind_run'){
          out('remindOut', 'Sent ' + j.sent + ' of ' + j.due + (j.failed ? ' — ' + j.failed + ' could not be delivered.' : '.'));
          toast('Sent ✓'); return;
        }
        if(act==='statement_run'){
          out('stmtOut', 'Sent ' + j.sent + ' of ' + j.considered + '.'
              + (j.failed ? ' ' + j.failed + ' could not be delivered.' : '')
              + (j.noEmail ? ' ' + j.noEmail + ' have no email.' : '')
              + (j.notActive ? ' ' + j.notActive + ' not currently enrolled.' : ''));
          toast('Sent ✓'); return;
        }
        if(act==='backfill_preview'){
          var who = (j.who||[]).map(function(w){ return w.name + ' (' + w.payments + ' · ' + money(w.total) + ')'; });
          out('bfOut', '<b>' + j.sendable + '</b> ' + (j.sendable === 1 ? 'person' : 'people')
              + ' would be emailed, covering ' + j.sendablePayments + ' payment(s).'
              + (who.length ? '<br>' + who.join(' · ') + (j.sendable > who.length ? ' …' : '') : '')
              + (j.noEmail ? '<br>' + j.noEmail + ' skipped — no email address (kept in the queue).' : '')
              + (j.orphan ? '<br>' + j.orphan + ' payment group(s) have no participant record.' : ''));
          return;
        }
        if(act==='backfill_run'){
          out('bfOut', 'Sent ' + j.sent + ' of ' + (j.sent + j.failed) + '. '
              + j.stamped + ' payment(s) receipted.'
              + (j.failed ? ' ' + j.failed + ' could not be delivered — re-send from the person\u2019s record.' : '')
              + (j.remaining ? ' ' + j.remaining + ' still to do — press again.' : ' Nothing left in the queue.'));
          toast('Sent ✓'); return;
        }
        if(act==='statement'){ toast(j.delivered ? 'Statement sent ✓' : 'Recorded, but delivery failed', j.delivered); return; }
        if(act==='receipt'){ toast(j.delivered ? 'Receipt ' + j.no + ' sent ✓' : 'Receipt ' + j.no + ' issued, but delivery failed', j.delivered); return; }
        if(act==='payment' && j.receipt){ toast(j.receipt.delivered ? 'Recorded · receipt ' + j.receipt.no + ' sent ✓'
                                                                    : 'Recorded · receipt ' + j.receipt.no + ' (email failed)', j.receipt.delivered);
                                          setTimeout(function(){location.reload();}, 1400); return; }
        if(j.receiptCancelled){ toast('Voided — they have been emailed that the receipt is cancelled'); setTimeout(function(){location.reload();}, 1400); return; }
        if(j.clamped){ toast('Waived ' + money(j.waived) + ' — that is all that was outstanding'); }
        else if(j.overCap){ toast('Posted — this account is now over the ceiling'); }
        else { toast('Saved ✓'); }
        if(!quiet[act]) setTimeout(function(){location.reload();}, j.clamped||j.overCap ? 1400 : 350);
      }).catch(function(){
        /* A dropped connection rejects the promise outright, so without this the
           button stays disabled for good and the staff member is stuck with no
           way to retry and nothing on screen saying why. */
        release();
        toast('Offline — nothing was saved. Try again.', false);
      });
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
