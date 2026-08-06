<?php
/**
 * academy/ngv/members.php — NextGen Vanguard staff console.
 *
 * The write path that makes the member dashboard's fees + certifications real:
 * staff enrol members, set status/cohort/track, record fee payments, and add
 * certifications. All data lives in the SEPARATE NGV database (lib/NgvDb.php);
 * this page never touches the main site tables except to look up a member by
 * email at enrolment time.
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
        NgvMember::setAdmin($mid, [
            'status' => $in['status'] ?? null, 'cohort' => $in['cohort'] ?? null,
            'track'  => $in['track'] ?? null,  'phase'  => $in['phase'] ?? null,
        ]);
        json_out(['ok' => true]);
    }
    if ($act === 'payment') {
        if ($mid <= 0) json_out(['ok' => false, 'error' => 'Missing member.'], 400);
        NgvMember::recordPayment($mid, [
            'kind' => $in['kind'] ?? 'commitment', 'amount' => $in['amount'] ?? 0,
            'period' => $in['period'] ?? '', 'method' => $in['method'] ?? '',
            'reference' => $in['reference'] ?? '', 'note' => $in['note'] ?? '',
        ], $adminUid);
        json_out(['ok' => true]);
    }
    if ($act === 'void') {
        NgvMember::voidPayment((int) ($in['payment_id'] ?? 0), $adminUid);
        json_out(['ok' => true]);
    }
    if ($act === 'cert') {
        if ($mid <= 0) json_out(['ok' => false, 'error' => 'Missing member.'], 400);
        $ok = NgvMember::addCertification($mid, [
            'title' => $in['title'] ?? '', 'issued_on' => $in['issued_on'] ?? '',
            'issued_by' => $in['issued_by'] ?? '', 'reference' => $in['reference'] ?? '',
        ]);
        json_out(['ok' => $ok, 'error' => $ok ? '' : 'A title is required.']);
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
$roster = $isAdmin ? NgvMember::roster('', 300) : [];
$apps   = $isAdmin ? NgvMember::applications('', 100) : [];
$sel = null; $selPayments = []; $selCerts = [];
$mid = (int) ($_GET['m'] ?? 0);
if ($isAdmin && $mid > 0) {
    $sel = NgvMember::participant($mid);
    if ($sel) { $selPayments = NgvMember::payments($mid); $selCerts = NgvMember::certifications($mid); }
}
$trackNames = [];
foreach ((Ngv::get()['tracks'] ?? []) as $t) { if (!empty($t['name'])) $trackNames[] = (string) $t['name']; }
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
.btn.primary{background:var(--grad);color:#fff;border-color:transparent}
.btn.sm{padding:5px 10px;font-size:.82rem}
.fld{margin-bottom:12px}.fld label{display:block;font-size:.78rem;font-weight:700;color:var(--muted);margin-bottom:5px}
input,select,textarea{width:100%;border:1.5px solid var(--line);border-radius:9px;padding:9px;font:inherit}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--orange)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.sub{font-size:.82rem;color:var(--muted)}
.enroll{display:flex;gap:8px}.enroll input{flex:1}
.msg{font-size:.85rem;font-weight:700;margin-left:auto}
.pay-row{display:flex;gap:8px;align-items:center;font-size:.86rem;padding:6px 0;border-bottom:1px dashed var(--line)}
.pay-row .sp{flex:1}
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
    <div class="stat"><div class="k">Participants</div><div class="v"><?= (int)$stats['total'] ?></div></div>
    <div class="stat"><div class="k">Active</div><div class="v"><?= (int)($stats['by_status']['active'] ?? 0) ?></div></div>
    <div class="stat"><div class="k">Collected</div><div class="v">₦<?= number_format((int)$stats['collected']) ?></div></div>
    <div class="stat"><div class="k">Applications</div><div class="v"><?= (int)($stats['apps_pending'] ?? 0) ?><?php if ((int)($stats['apps_total'] ?? 0) > 0): ?> <span class="sub" style="font-size:.8rem">pending</span><?php endif; ?></div></div>
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
      <header>Vanguards <span class="sp"></span><span class="sub"><?= count($roster) ?> shown</span></header>
      <div class="body">
        <div class="enroll" style="margin-bottom:14px">
          <input id="enrollEmail" type="email" placeholder="Enrol by member email…">
          <button class="btn primary" id="enrollBtn">Enrol</button>
        </div>
        <table>
          <thead><tr><th>Name</th><th>Track</th><th>Status</th><th>Paid</th><th>Certs</th></tr></thead>
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
          <?php if (!$roster): ?><tr><td colspan="5" class="sub">No participants yet — enrol a member by email above.</td></tr><?php endif; ?>
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
            <input id="f_cohort" placeholder="Cohort (e.g. 2026 Alpha)" value="<?= $e((string)$sel['cohort']) ?>">
            <select id="f_phase">
              <option value="" <?= $sel['phase']===''?'selected':'' ?>>Phase — not set</option>
              <option value="1" <?= $sel['phase']==='1'?'selected':'' ?>>Phase 1</option>
              <option value="2" <?= $sel['phase']==='2'?'selected':'' ?>>Phase 2</option>
              <option value="done" <?= $sel['phase']==='done'?'selected':'' ?>>Completed</option>
            </select>
          </div>
          <div style="margin-top:10px"><button class="btn primary sm" data-act="admin" data-m="<?= $m ?>">Save enrolment</button></div>
        </div>

        <div class="fld"><label>Record a payment</label>
          <div class="grid2">
            <select id="p_kind"><?php foreach (NgvMember::KINDS as $k): ?><option value="<?= $e($k) ?>"><?= $e(ucfirst($k)) ?></option><?php endforeach; ?></select>
            <input id="p_amount" type="number" min="0" step="100" placeholder="Amount (₦)">
          </div>
          <div class="grid2" style="margin-top:10px">
            <input id="p_period" placeholder="Period: 2026 or 2026-08">
            <input id="p_method" placeholder="Method (transfer, cash…)">
          </div>
          <input id="p_ref" style="margin-top:10px" placeholder="Reference / note (optional)">
          <div style="margin-top:10px"><button class="btn primary sm" data-act="payment" data-m="<?= $m ?>">Record payment</button></div>
        </div>

        <?php if ($selPayments): ?>
        <div class="fld"><label>Payments</label>
          <?php foreach ($selPayments as $pay): ?>
          <div class="pay-row"><span>₦<?= number_format((int)$pay['amount']) ?> <b><?= $e((string)$pay['kind']) ?></b> <?= !empty($pay['period']) ? '('.$e((string)$pay['period']).')' : '' ?></span><span class="sp"></span><span class="sub"><?= $e(substr((string)$pay['created_at'],0,10)) ?></span> <button class="btn sm" data-act="void" data-pid="<?= (int)$pay['id'] ?>">Void</button></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

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
          <div class="pay-row"><span>🏅 <b><?= $e((string)$cert['title']) ?></b> <span class="sub"><?= $e((string)$cert['issued_by']) ?></span></span><span class="sp"></span><span class="sub"><?= $e(substr((string)($cert['issued_on'] ?: $cert['created_at']),0,10)) ?></span></div>
          <?php endforeach; ?>
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

  document.querySelectorAll('[data-act]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var act = btn.getAttribute('data-act'), m = parseInt(btn.getAttribute('data-m')||'0',10), body={action:act, member_id:m};
      if(act==='admin'){ body.status=val('f_status'); body.track=val('f_track'); body.cohort=val('f_cohort'); body.phase=val('f_phase'); }
      else if(act==='payment'){ body.kind=val('p_kind'); body.amount=val('p_amount'); body.period=val('p_period'); body.method=val('p_method'); body.reference=val('p_ref'); if(!body.amount){ toast('Enter an amount', false); return; } }
      else if(act==='cert'){ body.title=val('c_title'); body.issued_by=val('c_by'); body.issued_on=val('c_on'); if(!body.title){ toast('Title required', false); return; } }
      else if(act==='void'){ body.payment_id=parseInt(btn.getAttribute('data-pid')||'0',10); if(!confirm('Void this payment?')) return; }
      else if(act==='app_status'){ body.app_id=parseInt(btn.getAttribute('data-app')||'0',10); body.status=btn.getAttribute('data-status')||''; if(body.status==='rejected' && !confirm('Reject this application?')) return; }
      else if(act==='app_enroll'){ body.app_id=parseInt(btn.getAttribute('data-app')||'0',10); }
      post(body).then(function(j){ if(j.ok){ toast('Saved ✓'); setTimeout(function(){location.reload();},350); } else toast(j.error||'Failed', false); });
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
