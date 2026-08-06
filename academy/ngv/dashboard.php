<?php
/**
 * academy/ngv/dashboard.php — the NextGen Vanguard member dashboard.
 *
 * A vanguard's personal home for the programme. It greets the signed-in member,
 * lays out their journey (phases, track, the 24-book reading challenge, a focus
 * note) and the programme facts (fees, schedule, offices, support) — all read
 * live from the DB-backed content document (lib/Ngv.php) so it never drifts
 * from the public page.
 *
 * The member's own progress is real and saved server-side per account via
 * lib/Prefs.php (user_prefs), so it follows them across devices:
 *   ngv_track  · chosen track          ngv_phase · current phase (1 | 2 | done)
 *   ngv_books  · 24-char 0/1 bitstring  ngv_note  · a short focus note (≤200)
 *
 * NOTE: NGV has no in-app enrolment/fee-payment store yet — fee *status* and
 * official certifications are not tracked here (shown as programme facts +
 * "speak to your track lead"). When an intake/ledger exists, wire it into the
 * "My progress" tiles below.
 *
 * Gated on a signed-in member (LmsAuth::user()); noindex. Saves POST to this
 * same URL (same-origin, rate-limited).
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$u      = LmsAuth::user();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$c      = Ngv::get();

/* Valid track names (from the live content) — used to validate saves + render. */
$trackNames = [];
foreach (($c['tracks'] ?? []) as $t) { if (!empty($t['name'])) $trackNames[] = (string) $t['name']; }

/* ── Save handler (member, same-origin, rate-limited) ─────────────────── */
if ($method === 'POST') {
    if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
    require_same_origin();
    $uid = (int) $u['id'];
    av_require_write($uid, 'ngv_dash', 60, 600);

    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];

    // Map the client payload onto the participant's self-editable fields and
    // persist to the SEPARATE NGV database (validation lives in NgvMember).
    $patch = [];
    if (array_key_exists('track', $in)) $patch['track'] = (string) $in['track'];
    if (array_key_exists('phase', $in)) $patch['phase'] = (string) $in['phase'];
    if (array_key_exists('books', $in)) $patch['books'] = (string) $in['books'];
    if (array_key_exists('note',  $in)) $patch['focus_note'] = (string) $in['note'];
    if ($patch) NgvMember::saveSelf($uid, $patch);
    json_out(['ok' => true]);
}

/* ── GET: require sign-in ─────────────────────────────────────────────── */
if (!$u) {
    $next = '/academy/ngv/dashboard.php';
    $login = function_exists('av_login_url') ? av_login_url($next) : '/login?next=' . rawurlencode($next);
    header('Location: ' . $login);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$uid   = (int) $u['id'];
$first = trim(explode(' ', trim((string) ($u['name'] ?? 'Vanguard')))[0]) ?: 'Vanguard';
$csrf  = function_exists('av_csrf_token') ? av_csrf_token(43200) : ''; // 12h — matches "leave the tab open" use

/* Member state — from the SEPARATE NGV database. On first visit we enrol the
 * member and migrate any progress they saved before this lived in its own DB
 * (the old Prefs keys), so nobody loses what they'd tracked. */
$seed = ['name' => (string) ($u['name'] ?? ''), 'email' => (string) ($u['email'] ?? '')];
if (!NgvMember::participant($uid)) {
    $seed['track']      = Prefs::get($uid, 'ngv_track', '');
    $seed['phase']      = Prefs::get($uid, 'ngv_phase', '');
    $seed['books']      = Prefs::get($uid, 'ngv_books', '');
    $seed['focus_note'] = Prefs::get($uid, 'ngv_note', '');
}
$p = NgvMember::ensureParticipant($uid, $seed);

$myTrack = (string) ($p['track'] ?? '');
$myPhase = (string) ($p['phase'] ?? '');
$myBooks = str_pad(substr((string) ($p['books'] ?? ''), 0, 24), 24, '0');
$myNote  = (string) ($p['focus_note'] ?? '');
$booksRead = substr_count($myBooks, '1');
$BOOKS_TOTAL = 24;

/* Real fee status + certifications from the NGV DB. */
$feeStatus = NgvMember::feeStatus($uid);
$myCerts   = NgvMember::certifications($uid);
$myPayments = NgvMember::payments($uid);

/* Content slices */
$g       = static fn(array $a, string $k, string $d = ''): string => (string) ($a[$k] ?? $d);
$enabled = Ngv::isEnabled();
$phases  = is_array($c['phases'] ?? null) ? $c['phases'] : [];
$tracks  = is_array($c['tracks'] ?? null) ? $c['tracks'] : [];
$fees    = is_array($c['fees'] ?? null) ? $c['fees'] : [];
$sched   = is_array($c['schedule'] ?? null) ? $c['schedule'] : [];
$offices = is_array($c['offices'] ?? null) ? $c['offices'] : [];
$marquee = is_array($c['marquee'] ?? null) ? $c['marquee'] : [];
$why     = is_array($c['why'] ?? null) ? $c['why'] : [];
$ct      = is_array($c['contact'] ?? null) ? $c['contact'] : [];
$stats   = is_array($c['stats'] ?? null) ? $c['stats'] : [];

/* Phase labels for the "current phase" tile */
$phaseLabel = 'Not set';
if ($myPhase === 'done')      $phaseLabel = 'Completed 🎓';
elseif ($myPhase === '1' && isset($phases[0])) $phaseLabel = (string) ($phases[0]['title'] ?? 'Phase 1');
elseif ($myPhase === '2' && isset($phases[1])) $phaseLabel = (string) ($phases[1]['title'] ?? 'Phase 2');

$myTrackDesc = '';
foreach ($tracks as $t) { if (($t['name'] ?? '') === $myTrack) { $myTrackDesc = (string) ($t['desc'] ?? ''); break; } }

$e = 'e'; // htmlspecialchars helper name
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>My Dashboard · NextGen Vanguard</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--red:#e4162b;--orange:#ff6a1a;--gold:#ffb703;--ink:#15120e;--line:#e7e9ee;--muted:#5f6874;
  --bg:#f5f6f8;--card:#fff;--grad:linear-gradient(100deg,#e4162b,#ff6a1a 55%,#ffb703);--r:16px}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;font-family:Montserrat,system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.5}
a{color:var(--red)}
.wrap{max-width:1060px;margin:0 auto;padding:0 18px}
/* top bar */
.top{position:sticky;top:0;z-index:20;background:rgba(21,18,14,.97);backdrop-filter:blur(8px);color:#fff;
  display:flex;align-items:center;gap:12px;padding:12px 18px;flex-wrap:wrap}
.top .brand{font-weight:800}.top .brand b{color:var(--gold)}
.top .sp{flex:1}
.top a{color:rgba(255,255,255,.85);text-decoration:none;font-weight:600;font-size:.86rem}
.top a:hover{color:#fff}
.chip{font-size:.78rem;font-weight:700;background:rgba(255,255,255,.14);padding:5px 11px;border-radius:999px}
/* hero */
.hero{background:var(--grad);color:#fff;padding:30px 0 34px}
.hero h1{margin:0 0 4px;font-size:1.7rem;line-height:1.15}
.hero p{margin:0;opacity:.94;max-width:64ch}
.hero .save{font-size:.82rem;font-weight:700;opacity:0;transition:opacity .25s;height:1.1em}
.hero .save.on{opacity:.95}
.paused{background:#15120e;color:#ffd9a8;font-size:.9rem;padding:8px 0}
.paused .wrap{display:flex;gap:8px;align-items:center}
/* tiles */
.tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:-22px auto 0;position:relative;z-index:2}
.tile{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:16px 16px 14px;box-shadow:0 8px 24px -18px rgba(0,0,0,.4)}
.tile .k{font-size:.74rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.tile .v{font-size:1.5rem;font-weight:800;margin-top:3px;line-height:1.1}
.tile .s{font-size:.8rem;color:var(--muted)}
/* layout */
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin:22px 0 40px}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);overflow:hidden}
.card.wide{grid-column:1 / -1}
.card>header{display:flex;align-items:center;gap:10px;padding:15px 20px;border-bottom:1px solid var(--line)}
.card>header h2{margin:0;font-size:1.02rem;flex:1}
.card>header .hint{font-size:.78rem;color:var(--muted);font-weight:600}
.card>.body{padding:18px 20px}
/* phases */
.phase{display:flex;gap:14px;padding:12px 0;border-bottom:1px dashed var(--line)}
.phase:last-child{border-bottom:0}
.phase .dot{flex:0 0 auto;width:30px;height:30px;border-radius:50%;background:#eceef2;color:var(--muted);
  display:grid;place-items:center;font-weight:800;font-size:.9rem}
.phase.on .dot{background:var(--grad);color:#fff}
.phase .tag{font-size:.72rem;font-weight:700;color:var(--orange);text-transform:uppercase;letter-spacing:.04em}
.phase h3{margin:1px 0 2px;font-size:1rem}
.phase .when{font-size:.8rem;color:var(--muted)}
.phase ul{margin:8px 0 0;padding-left:18px;font-size:.88rem;color:#3a3f47}
.phase li{margin:2px 0}
.phasebtns{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
/* track picker */
.tracks{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.trk{border:1.5px solid var(--line);border-radius:12px;padding:12px;cursor:pointer;transition:border-color .12s,background .12s;background:#fff}
.trk:hover{border-color:var(--orange)}
.trk.on{border-color:var(--red);background:#fff6f2}
.trk .i{font-size:1.3rem}
.trk .n{font-weight:700;font-size:.92rem;margin-top:2px}
.trk .d{font-size:.8rem;color:var(--muted);margin-top:3px}
/* books */
.books{display:grid;grid-template-columns:repeat(8,1fr);gap:8px}
.book{aspect-ratio:1;border:1.5px solid var(--line);border-radius:9px;background:#fff;cursor:pointer;font-weight:800;
  color:var(--muted);display:grid;place-items:center;font-size:.9rem;transition:transform .1s}
.book:hover{transform:translateY(-1px)}
.book.on{background:var(--grad);border-color:transparent;color:#fff}
.bar{height:9px;border-radius:999px;background:#eceef2;overflow:hidden;margin:14px 0 6px}
.bar>i{display:block;height:100%;background:var(--grad);transition:width .3s}
/* generic */
.btn{border:1.5px solid var(--line);background:#fff;border-radius:10px;padding:8px 14px;font:inherit;font-weight:700;
  cursor:pointer;color:var(--ink);transition:border-color .12s,transform .1s}
.btn:hover{border-color:var(--orange);transform:translateY(-1px)}
.btn.on,.btn.primary{background:var(--grad);color:#fff;border-color:transparent}
textarea{width:100%;border:1.5px solid var(--line);border-radius:12px;padding:12px;font:inherit;resize:vertical;min-height:82px}
textarea:focus{outline:none;border-color:var(--orange)}
.rows{display:grid;gap:10px}
.row{display:flex;gap:12px;align-items:flex-start;padding:11px 0;border-bottom:1px dashed var(--line)}
.row:last-child{border-bottom:0}
.row b{display:block}
.row .amt{margin-left:auto;font-weight:800;white-space:nowrap;color:var(--red)}
.row .d{font-size:.84rem;color:var(--muted)}
.badge{font-size:.72rem;font-weight:800;padding:3px 10px;border-radius:999px}
.badge.ok{background:#e6f7ec;color:#137a3a}
.badge.due{background:#fdecec;color:#c0322b}
.note-box{font-size:.82rem;color:var(--muted);background:#fbfbfc;border:1px dashed var(--line);border-radius:10px;padding:10px 12px;margin-top:12px}
.pills{display:flex;flex-wrap:wrap;gap:8px}
.pill{background:#fff4ec;color:#b5480f;border:1px solid #ffe0cc;border-radius:999px;padding:5px 12px;font-size:.82rem;font-weight:600}
.pill.plain{background:#f1f3f6;color:#3a3f47;border-color:var(--line)}
.contact{display:flex;flex-wrap:wrap;gap:10px}
.contact a{display:inline-flex;align-items:center;gap:7px;background:#fff;border:1.5px solid var(--line);border-radius:10px;
  padding:9px 14px;text-decoration:none;color:var(--ink);font-weight:600;font-size:.9rem}
.contact a:hover{border-color:var(--orange)}
@media(max-width:820px){.tiles{grid-template-columns:1fr 1fr}.grid{grid-template-columns:1fr}.tracks{grid-template-columns:1fr}}
@media(max-width:520px){.books{grid-template-columns:repeat(6,1fr)}.hero h1{font-size:1.4rem}}
</style>
</head>
<body>
<div class="top">
  <span class="brand"><b>NextGen Vanguard</b> · my dashboard</span>
  <span class="sp"></span>
  <span class="chip"><?= $e(strtoupper(substr($first, 0, 1))) ?> · <?= $e($first) ?></span>
  <a href="/academy/ngv/" target="_blank" rel="noopener">Programme ↗</a>
  <a href="/portal/">Portal</a>
  <a href="/login?logout=1">Sign out</a>
</div>

<?php if (!$enabled): ?>
<div class="paused"><div class="wrap">⏳ The next cohort is being prepared — your personal tools below still work, and your progress is saved.</div></div>
<?php endif; ?>

<section class="hero">
  <div class="wrap">
    <h1>Welcome back, <?= $e($first) ?> 👋</h1>
    <p>This is your NextGen Vanguard home — track your journey, log the 24-book challenge, choose your track and keep your focus in view. Everything you change here saves to your account.</p>
    <div class="save" id="saveMsg">Saved ✓</div>
  </div>
</section>

<div class="wrap">
  <div class="tiles">
    <div class="tile"><div class="k">Current phase</div><div class="v" id="tilePhase"><?= $e($phaseLabel) ?></div><div class="s">of the <?= count($phases) ?: 2 ?>-phase journey</div></div>
    <div class="tile"><div class="k">My track</div><div class="v" style="font-size:1.05rem" id="tileTrack"><?= $myTrack !== '' ? $e($myTrack) : '—' ?></div><div class="s"><?= $myTrack !== '' ? 'Locked in' : 'Pick one below' ?></div></div>
    <div class="tile"><div class="k">Reading challenge</div><div class="v"><span id="tileBooks"><?= $booksRead ?></span> / <?= $BOOKS_TOTAL ?></div><div class="s">books this year</div></div>
    <div class="tile"><div class="k">Certifications</div><div class="v"><?= count($myCerts) ?></div><div class="s"><?= count($myCerts) === 1 ? 'earned so far' : 'earned so far' ?></div></div>
  </div>

  <div class="grid">
    <!-- My journey -->
    <div class="card wide">
      <header><h2>My journey</h2><span class="hint">Tap the phase you're in</span></header>
      <div class="body">
        <?php foreach ($phases as $i => $p): $on = ($myPhase === (string)($i+1)) || ($myPhase === 'done'); ?>
        <div class="phase <?= $on ? 'on' : '' ?>" data-phase-row="<?= $i+1 ?>">
          <div class="dot"><?= $myPhase === 'done' ? '✓' : ($i+1) ?></div>
          <div>
            <?php if (!empty($p['tag'])): ?><div class="tag"><?= $e((string)$p['tag']) ?></div><?php endif; ?>
            <h3><?= $e((string)($p['title'] ?? 'Phase '.($i+1))) ?></h3>
            <?php if (!empty($p['when'])): ?><div class="when"><?= $e((string)$p['when']) ?></div><?php endif; ?>
            <?php if (!empty($p['items']) && is_array($p['items'])): ?>
            <ul><?php foreach ($p['items'] as $it): ?><li><?= $e((string)$it) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="phasebtns">
          <?php for ($i = 1; $i <= max(2, count($phases)); $i++): ?>
          <button class="btn phase-btn <?= $myPhase === (string)$i ? 'on' : '' ?>" data-phase="<?= $i ?>">I'm in Phase <?= $i ?></button>
          <?php endfor; ?>
          <button class="btn phase-btn <?= $myPhase === 'done' ? 'on' : '' ?>" data-phase="done">Completed 🎓</button>
        </div>
      </div>
    </div>

    <!-- My track -->
    <div class="card">
      <header><h2>My track</h2></header>
      <div class="body">
        <div class="tracks">
          <?php foreach ($tracks as $t): $nm = (string)($t['name'] ?? ''); if ($nm==='') continue; ?>
          <div class="trk <?= $myTrack === $nm ? 'on' : '' ?>" data-track="<?= $e($nm) ?>">
            <div class="i"><?= $e((string)($t['icon'] ?? '🎯')) ?></div>
            <div class="n"><?= $e($nm) ?></div>
            <div class="d"><?= $e((string)($t['desc'] ?? '')) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if ($myTrack !== ''): ?><div class="note-box" id="trackNote">You're on <b><?= $e($myTrack) ?></b>. <?= $e($myTrackDesc) ?></div><?php endif; ?>
      </div>
    </div>

    <!-- Focus note -->
    <div class="card">
      <header><h2>My focus this month</h2><span class="hint">private to you</span></header>
      <div class="body">
        <textarea id="note" maxlength="200" placeholder="What are you committed to this month? e.g. finish 2 books, ship my track project, show up on time every day."><?= $e($myNote) ?></textarea>
        <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
          <button class="btn primary" id="noteSave">Save note</button>
          <span style="font-size:.8rem;color:var(--muted)"><span id="noteCount"><?= mb_strlen($myNote) ?></span>/200</span>
        </div>
      </div>
    </div>

    <!-- 24-book reading challenge -->
    <div class="card wide">
      <header><h2>24-book reading challenge</h2><span class="hint">tap a book once you finish it</span></header>
      <div class="body">
        <div class="books" id="books">
          <?php for ($i = 0; $i < $BOOKS_TOTAL; $i++): $on = ($myBooks[$i] ?? '0') === '1'; ?>
          <div class="book <?= $on ? 'on' : '' ?>" data-i="<?= $i ?>" title="Book <?= $i+1 ?>"><?= $i+1 ?></div>
          <?php endfor; ?>
        </div>
        <div class="bar"><i id="booksBar" style="width:<?= (int)round($booksRead/$BOOKS_TOTAL*100) ?>%"></i></div>
        <div style="font-size:.84rem;color:var(--muted)"><b id="booksLabel"><?= $booksRead ?></b> of <?= $BOOKS_TOTAL ?> read — leadership, finance, law & your track. Keep going!</div>
      </div>
    </div>

    <!-- Fees & commitment (real, from the NGV DB) -->
    <div class="card">
      <header><h2>My fees</h2><span class="hint">recorded by your team</span></header>
      <div class="body">
        <div class="rows">
          <?php foreach ($feeStatus['lines'] as $ln): ?>
          <div class="row">
            <div><b><?= $e((string)$ln['label']) ?></b><span class="d"><?= $e((string)$ln['detail']) ?></span></div>
            <span class="amt"><span class="badge <?= $ln['ok'] ? 'ok' : 'due' ?>"><?= $ln['ok'] ? 'Paid' : 'Due' ?></span></span>
          </div>
          <?php endforeach; ?>
          <div class="row"><div><b>Total recorded</b><span class="d">across all fees</span></div><span class="amt">₦<?= number_format((int)$feeStatus['total']) ?></span></div>
        </div>
        <?php if (!empty($myPayments)): ?>
        <div class="note-box"><b>Recent payments</b><br>
          <?php foreach (array_slice($myPayments, 0, 4) as $pay): ?>
          • ₦<?= number_format((int)($pay['amount'] ?? 0)) ?> <?= $e((string)($pay['kind'] ?? '')) ?><?= !empty($pay['period']) ? ' ('.$e((string)$pay['period']).')' : '' ?> — <?= $e(substr((string)($pay['created_at'] ?? ''), 0, 10)) ?><br>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="note-box">No payments recorded yet. When your team logs a payment it shows here. Need help? Speak to your track lead.</div>
        <?php endif; ?>
        <?php if (!empty($sched['payment'])): ?><div class="note-box"><?= $e((string)$sched['payment']) ?></div><?php endif; ?>
      </div>
    </div>

    <!-- Certifications (real, from the NGV DB) -->
    <div class="card">
      <header><h2>My certifications</h2><span class="hint"><?= count($myCerts) ?> earned</span></header>
      <div class="body">
        <?php if (!empty($myCerts)): ?>
        <div class="rows">
          <?php foreach ($myCerts as $cert): ?>
          <div class="row">
            <div><b>🏅 <?= $e((string)($cert['title'] ?? '')) ?></b><span class="d"><?= $e(trim(((string)($cert['issued_by'] ?? '')) . (!empty($cert['issued_on']) ? ' · ' . substr((string)$cert['issued_on'],0,10) : ''), ' ·')) ?></span></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="note-box">No certifications yet — earn them by completing your track milestones. The programme offers up to <?= $e((string)($stats[1]['num'] ?? '6')) ?> per year.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Schedule & where -->
    <div class="card">
      <header><h2>Schedule &amp; where</h2></header>
      <div class="body">
        <div class="rows">
          <?php foreach (['days'=>'Attendance','time'=>'Daily schedule','uniform'=>'Dress code'] as $k=>$lbl): if (!empty($sched[$k])): ?>
          <div class="row"><div><b><?= $e($lbl) ?></b><span class="d"><?= $e((string)$sched[$k]) ?></span></div></div>
          <?php endif; endforeach; ?>
          <?php foreach ($offices as $o): if (empty($o['name'])) continue; ?>
          <div class="row"><div><b>📍 <?= $e((string)$o['name']) ?></b><span class="d"><?= $e((string)($o['address'] ?? '')) ?></span></div></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Skills + support -->
    <div class="card wide">
      <header><h2>Skills you're building &amp; support</h2></header>
      <div class="body">
        <?php if ($marquee): ?>
        <div class="pills" style="margin-bottom:14px">
          <?php foreach ($marquee as $m): ?><span class="pill plain"><?= $e((string)$m) ?></span><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="contact">
          <?php if (!empty($ct['phone'])): $tel = preg_replace('/[^0-9+]/', '', (string)$ct['phone']); ?>
          <a href="tel:<?= $e($tel) ?>">📞 Call your team</a>
          <a href="https://wa.me/<?= $e(ltrim($tel,'+')) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
          <?php endif; ?>
          <?php if (!empty($ct['email'])): ?><a href="mailto:<?= $e((string)$ct['email']) ?>">✉️ <?= $e((string)$ct['email']) ?></a><?php endif; ?>
          <a href="/academy/ngv/#faq" target="_blank" rel="noopener">❓ Programme FAQ</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var BOOKS_TOTAL = <?= $BOOKS_TOTAL ?>;
  var CSRF = <?= json_encode($csrf) ?>;
  var msg = document.getElementById('saveMsg');
  var msgT;
  function toast(txt){ if(!msg) return; msg.textContent = txt || 'Saved ✓'; msg.classList.add('on'); clearTimeout(msgT); msgT = setTimeout(function(){ msg.classList.remove('on'); }, 1600); }
  function save(patch, ok){
    fetch(location.pathname, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, credentials:'same-origin', body:JSON.stringify(patch)})
      .then(function(r){ return r.json().catch(function(){ return {ok:false, status:r.status}; }).then(function(j){ j.status = r.status; return j; }); })
      .then(function(j){ if(j && j.ok){ toast(); if(ok) ok(); } else if(j && j.status === 403){ toast('Session timed out — reload the page'); } else { toast('Couldn’t save — try again'); } })
      .catch(function(){ toast('Offline — not saved'); });
  }

  // Track picker
  document.querySelectorAll('.trk').forEach(function(el){
    el.addEventListener('click', function(){
      var name = el.getAttribute('data-track');
      var wasOn = el.classList.contains('on');
      document.querySelectorAll('.trk').forEach(function(x){ x.classList.remove('on'); });
      var val = wasOn ? '' : name;
      if(!wasOn) el.classList.add('on');
      save({track: val}, function(){
        var tile = document.getElementById('tileTrack');
        if(tile){ tile.textContent = val || '—'; }
        var tsub = tile && tile.nextElementSibling; if(tsub) tsub.textContent = val ? 'Locked in' : 'Pick one below';
      });
    });
  });

  // Phase buttons
  document.querySelectorAll('.phase-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var ph = btn.getAttribute('data-phase');
      var wasOn = btn.classList.contains('on');
      document.querySelectorAll('.phase-btn').forEach(function(x){ x.classList.remove('on'); });
      var val = wasOn ? '' : ph;
      if(!wasOn) btn.classList.add('on');
      // reflect on the phase rows
      document.querySelectorAll('[data-phase-row]').forEach(function(row){
        var n = row.getAttribute('data-phase-row');
        row.classList.toggle('on', val === 'done' || val === n);
        var dot = row.querySelector('.dot'); if(dot) dot.textContent = (val==='done') ? '✓' : n;
      });
      var tile = document.getElementById('tilePhase');
      if(tile){ tile.textContent = val==='done' ? 'Completed 🎓' : (val ? (btn.textContent.replace("I'm in ","")) : 'Not set'); }
      save({phase: val});
    });
  });

  // 24-book challenge
  var books = document.getElementById('books');
  function bits(){ var s=''; document.querySelectorAll('.book').forEach(function(b){ s += b.classList.contains('on')?'1':'0'; }); return s; }
  function refreshBooks(){
    var n = bits().split('1').length - 1;
    var bar = document.getElementById('booksBar'); if(bar) bar.style.width = Math.round(n/BOOKS_TOTAL*100)+'%';
    ['booksLabel','tileBooks'].forEach(function(id){ var el=document.getElementById(id); if(el) el.textContent = n; });
  }
  if(books){
    var bt;
    books.addEventListener('click', function(ev){
      var b = ev.target.closest('.book'); if(!b) return;
      b.classList.toggle('on'); refreshBooks();
      clearTimeout(bt); bt = setTimeout(function(){ save({books: bits()}); }, 400); // debounce rapid taps
    });
  }

  // Focus note
  var note = document.getElementById('note');
  var noteCount = document.getElementById('noteCount');
  if(note && noteCount){ note.addEventListener('input', function(){ noteCount.textContent = note.value.length; }); }
  var noteSave = document.getElementById('noteSave');
  if(noteSave && note){ noteSave.addEventListener('click', function(){ save({note: note.value}); }); }
})();
</script>
</body>
</html>
