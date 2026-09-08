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
 * The member's own progress, plan, fee account and certifications are real and
 * saved server-side in the SEPARATE NGV database (lib/NgvMember.php), so they
 * follow the member across devices: track, plan, phase, 24-book bitstring, a
 * focus note, the payment ledger and issued certificates.
 *
 * DESIGN: this wears the same skin as the Afrovanguard member portal (/portal,
 * portal.css) — the sidebar + top-bar + KPI + card system, navy/gold, light and
 * dark — so the NGV dashboard and the member portal feel like one product.
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
    /* The one money-adjacent thing a participant may do: say something about
       their own account. It is a message, not a decision — nothing here moves a
       figure, and the answer comes back from a person. */
    if ((string) ($in['action'] ?? '') === 'fee_request') {
        $r = NgvLedger::raiseRequest($uid, (string) ($in['kind'] ?? 'consideration'), (string) ($in['message'] ?? ''));
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    /* Ask for the statement by email. Read-only by construction: it composes
       what is already on this page and sends it, and cannot change a figure.
       Rate-limited to one a day inside the ledger, so a nervous tap on the
       button four times does not send four letters. */
    if ((string) ($in['action'] ?? '') === 'statement') {
        $r = NgvLedger::sendStatement($uid, true);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    /* Report damage yourself. This is a leadership programme: somebody who
       breaks something and says so has done the thing the programme is trying
       to teach, and a system whose only path is "staff notice" teaches the
       opposite. It charges nothing — see lib/NgvDamage.php. */
    if ((string) ($in['action'] ?? '') === 'damage') {
        $r = NgvDamage::report($uid, $in, 0, true);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }

    $patch = [];
    if (array_key_exists('track', $in)) $patch['track'] = (string) $in['track'];
    if (array_key_exists('plan',  $in)) $patch['plan']  = (string) $in['plan'];
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

/* Real account (training fee + membership + monthly commitment + any fines),
 * certifications and the full ledger — all from the NGV DB. Read-only: there is
 * no member-side action here that moves money, and there never should be. */
$account   = NgvLedger::account($uid);
$myPlan    = (string) ($p['plan'] ?? '');
$planOpts  = NgvMember::planOptions();
$myCerts   = NgvMember::certifications($uid);
$myEntries = $account['entries'];
/* Their own damage records. Read-only apart from reporting a new one: a
 * participant can say what happened, and only staff can attach money to it. */
$myDamage  = NgvDamage::forMember($uid);
/* Receipts. Derived from the payment rows, so this is not a second list that
 * can disagree with the ledger above it — it is the same rows, addressable. */
$myReceipts = NgvLedger::receiptsFor($uid);

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
/* Same theme source as the member portal, so a member who set dark there keeps
 * it here. */
$ptheme  = (($_COOKIE['av_portal_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$initial = strtoupper(mb_substr($first, 0, 1));
$owed    = (int) $account['payable'];
$feesOn  = !empty($account['enabled']);
$kindLabel = ['membership' => 'Membership fee', 'commitment' => 'Monthly commitment',
              'programme' => 'Training fee', 'fine' => 'Fine', 'adjustment' => 'Adjustment',
              'other' => 'Payment'];
/* A credit is not just "a payment": a waiver is the programme saying it is not
 * asking, and somebody reading their own account deserves to see which it was. */
$creditWord = ['payment' => 'Payment received', 'waiver' => 'Waived', 'writeoff' => 'Written off'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>My Dashboard · NextGen Vanguard</title>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portal/portal.css">
<style>
/* NGV dashboard — built on the member-portal design system (portal.css). Only
   the NGV-specific components live here; cards, KPIs, chips, buttons, the
   sidebar and both themes all come from portal.css and its tokens. */
.portal-app .save-msg{font-size:12.5px;font-weight:700;color:var(--green);opacity:0;transition:opacity .25s;white-space:nowrap}
.portal-app .save-msg.on{opacity:1}
.ngv-banner{display:flex;gap:10px;align-items:center;background:var(--gold-soft);border:1px solid var(--gold-soft-bd);
  color:var(--gold-deeper);border-radius:11px;padding:11px 14px;font-size:13.5px;font-weight:600;margin-bottom:18px}
.ngv-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.ngv-grid .wide{grid-column:1 / -1}
@media(max-width:860px){.ngv-grid{grid-template-columns:1fr}}

/* journey timeline */
.ngv-phase{display:flex;gap:14px;padding:13px 0;border-bottom:1px solid var(--surface-2)}
.ngv-phase:last-of-type{border-bottom:0}
.ngv-phase .dot{flex:0 0 auto;width:30px;height:30px;border-radius:50%;background:var(--surface-2);color:var(--muted);
  display:grid;place-items:center;font-weight:800;font-size:.9rem}
.ngv-phase.on .dot{background:var(--gold);color:var(--on-gold)}
.ngv-phase .tag{font-size:10.5px;font-weight:700;color:var(--gold-deep);text-transform:uppercase;letter-spacing:.06em}
.ngv-phase h3{margin:2px 0;font-size:14.5px;color:var(--ink)}
.ngv-phase .when{font-size:12.5px;color:var(--muted-2)}
.ngv-phase ul{margin:8px 0 0;padding-left:18px;font-size:13px;color:var(--body)}
.ngv-phase li{margin:2px 0}
.ngv-btns{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.portal-app .pbtn.on{background:var(--gold);color:var(--on-gold);border-color:transparent}

/* track + plan tiles */
.ngv-sub-h{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted-2);margin:18px 0 9px}
.ngv-picks{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:520px){.ngv-picks{grid-template-columns:1fr}}
.trk{border:1.5px solid var(--border);border-radius:11px;padding:12px;cursor:pointer;background:var(--surface);
  transition:border-color .12s,background .12s}
.trk:hover{border-color:var(--gold)}
.trk.on{border-color:var(--gold);background:var(--gold-soft)}
.trk .i{font-size:1.25rem}
.trk .n{font-weight:700;font-size:13.5px;margin-top:3px;color:var(--ink)}
.trk .d{font-size:12.5px;color:var(--muted);margin-top:3px}

/* 24-book grid */
.ngv-books{display:grid;grid-template-columns:repeat(8,1fr);gap:8px}
@media(max-width:520px){.ngv-books{grid-template-columns:repeat(6,1fr)}}
.book{aspect-ratio:1;border:1.5px solid var(--border);border-radius:9px;background:var(--surface);cursor:pointer;
  font-weight:800;color:var(--muted-2);display:grid;place-items:center;font-size:.9rem;transition:transform .1s}
.book:hover{transform:translateY(-1px);border-color:var(--gold)}
.book.on{background:var(--gold);border-color:transparent;color:var(--on-gold)}
.ngv-bar{height:9px;border-radius:999px;background:var(--surface-2);overflow:hidden;margin:14px 0 7px}
.ngv-bar>i{display:block;height:100%;background:var(--gold);transition:width .3s}

/* generic rows / notes (portal-toned) */
.portal-app textarea{width:100%;border:1.5px solid var(--border);border-radius:11px;padding:12px;font:inherit;
  background:var(--surface-2);color:var(--ink);resize:vertical;min-height:82px}
.portal-app textarea:focus{outline:none;border-color:var(--gold);background:var(--surface)}
.ngv-rows{display:flex;flex-direction:column}
.ngv-row{display:flex;gap:12px;align-items:flex-start;padding:11px 0;border-bottom:1px solid var(--surface-2)}
.ngv-row:last-child{border-bottom:0}
.ngv-row .k{font-weight:700;color:var(--ink);font-size:13.5px}
.ngv-row .d{display:block;font-size:12.5px;color:var(--muted-2);margin-top:2px}
.ngv-row .amt{margin-left:auto;font-weight:800;white-space:nowrap;color:var(--ink)}
.ngv-figure{font-size:30px;font-weight:800;letter-spacing:-.02em;line-height:1.05;color:var(--ink)}
.ngv-figure.clear{color:var(--green);font-size:24px}
.ngv-figure-sub{font-size:12.5px;color:var(--muted-2);margin:2px 0 14px}
.ngv-box{font-size:12.5px;color:var(--muted);background:var(--surface-2);border:1px solid var(--border);
  border-radius:10px;padding:10px 12px;margin-top:12px;line-height:1.5}
.ngv-box b{color:var(--ink)}
.ngv-pills{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.ngv-pill{background:var(--surface-2);color:var(--body);border:1px solid var(--border);border-radius:999px;
  padding:5px 12px;font-size:12.5px;font-weight:600}
.ngv-contact{display:flex;flex-wrap:wrap;gap:10px}
.portal-app .ngv-contact a{display:inline-flex;align-items:center;gap:7px;background:var(--surface);
  border:1.5px solid var(--border);border-radius:10px;padding:9px 14px;color:var(--body);font-weight:600;font-size:13px}
.portal-app .ngv-contact a:hover{border-color:var(--gold);color:var(--ink)}
.ngv-details summary{cursor:pointer;font-weight:700;color:var(--ink);font-size:13.5px;padding:4px 0}
.ngv-fine{font-size:11.5px;color:var(--muted-2);margin:8px 0 0;line-height:1.5}
.ngv-sched{margin-top:14px;border:1px solid var(--border);border-radius:12px;padding:12px 13px;background:var(--surface-2)}
.ngv-sched-head{display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;font-size:13px;color:var(--ink)}
.ngv-sched-head span{margin-left:auto;font-size:12px;color:var(--muted-2);font-weight:600}
.ngv-bar{height:6px;border-radius:999px;background:var(--border);overflow:hidden;margin:9px 0 11px}
.ngv-bar span{display:block;height:100%;border-radius:999px;background:var(--green)}
.ngv-insts{display:grid;grid-template-columns:repeat(auto-fill,minmax(84px,1fr));gap:7px}
.ngv-inst{border:1.5px solid var(--border);border-radius:9px;padding:7px 8px;background:var(--surface);
  display:flex;flex-direction:column;gap:1px;min-width:0}
.ngv-inst .mo{font-size:10.5px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:var(--muted-2)}
.ngv-inst .amt{font-size:13px;font-weight:800;color:var(--ink)}
.ngv-inst .st{font-size:10.5px;color:var(--muted-2)}
.ngv-inst--charged{border-color:var(--green-bd);background:var(--green-soft)}
.ngv-inst--charged .st{color:var(--green)}
.ngv-inst--due{border-color:var(--gold-soft-bd);background:var(--gold-soft)}
.ngv-inst--due .st{color:var(--gold-deep);font-weight:700}
.ngv-ask{margin-top:12px;border:1px solid var(--border);border-radius:10px;padding:10px 12px;background:var(--surface-2)}
.ngv-ask summary{font-size:13px}
.ngv-input{width:100%;margin-top:8px;border:1.5px solid var(--border);border-radius:9px;padding:9px 10px;
  font:inherit;font-size:13px;background:var(--surface);color:var(--ink)}
.ngv-input:focus{outline:none;border-color:var(--gold)}
.ngv-ask .pbtn{margin-top:9px}
.ngv-quote{display:block;margin:4px 0;padding-left:9px;border-left:2px solid var(--border);color:var(--muted-2)}
.ngv-two{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.ngv-mini{display:inline-block;margin-left:8px;font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:999px;
  border:1px solid var(--border);color:var(--body);text-decoration:none;vertical-align:middle}
.ngv-mini:hover{border-color:var(--gold);color:var(--ink)}
.ngv-row .k .pchip{margin-left:6px;vertical-align:middle}
</style>
</head>
<body class="portal-app<?= $ptheme === 'dark' ? ' is-dark' : '' ?>">
<div class="portal-shell">

  <!-- Sidebar (same rail as the member portal) -->
  <aside class="pside" id="pside">
    <a class="pside-brand" href="/academy/ngv/" target="_blank" rel="noopener">
      <span class="pside-mark">NV</span>
      <span class="pside-brand-text"><b class="pb-name">NextGen Vanguard</b><span class="pb-sub">Member dashboard</span></span>
    </a>
    <nav class="pside-nav">
      <div class="pnav-group">
        <div class="pnav-title">Dashboard</div>
        <a class="pnav-link is-active" href="#top" data-spy="top"><span class="pnav-dot pnav-dot--gold"></span><span class="pnav-label">Overview</span></a>
        <a class="pnav-link" href="#journey" data-spy="journey"><span class="pnav-dot"></span><span class="pnav-label">My journey</span></a>
        <a class="pnav-link" href="#track" data-spy="track"><span class="pnav-dot"></span><span class="pnav-label">Track &amp; plan</span></a>
        <a class="pnav-link" href="#reading" data-spy="reading"><span class="pnav-dot"></span><span class="pnav-label">Reading</span><span class="pnav-badge"><?= $booksRead ?>/<?= $BOOKS_TOTAL ?></span></a>
        <a class="pnav-link" href="#account" data-spy="account"><span class="pnav-dot <?= $owed > 0 ? '' : 'pnav-dot--green' ?>"></span><span class="pnav-label">Your account</span><?php if ($owed > 0): ?><span class="pnav-badge">₦<?= number_format($owed) ?></span><?php endif; ?></a>
        <?php $dmgOpen = 0; foreach ($myDamage as $d) { if ($d['open']) $dmgOpen++; } ?>
        <a class="pnav-link" href="#damage" data-spy="damage"><span class="pnav-dot <?= $dmgOpen > 0 ? '' : ($myDamage ? 'pnav-dot--green' : '') ?>"></span><span class="pnav-label">Damage</span><?php if ($dmgOpen > 0): ?><span class="pnav-badge"><?= $dmgOpen ?></span><?php endif; ?></a>
        <a class="pnav-link" href="#certs" data-spy="certs"><span class="pnav-dot"></span><span class="pnav-label">Certifications</span><span class="pnav-badge"><?= count($myCerts) ?></span></a>
        <a class="pnav-link" href="#schedule" data-spy="schedule"><span class="pnav-dot"></span><span class="pnav-label">Schedule</span></a>
      </div>
      <div class="pnav-group">
        <div class="pnav-title">Links</div>
        <a class="pnav-link" href="/academy/ngv/" target="_blank" rel="noopener"><span class="pnav-dot"></span><span class="pnav-label">Programme page</span><span class="pnav-ext">↗</span></a>
        <a class="pnav-link" href="/portal/"><span class="pnav-dot"></span><span class="pnav-label">Member portal</span><span class="pnav-ext">↗</span></a>
      </div>
    </nav>
    <div class="pside-user">
      <span class="pside-avatar"><?= $e($initial) ?></span>
      <span class="pside-user-meta"><b class="pu-name"><?= $e($first) ?></b><span class="pu-role">NextGen Vanguard</span></span>
      <a class="pside-signout" href="/login?logout=1" title="Sign out">⎋</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="portal-main">
    <div class="ptop">
      <button class="ptop-burger" id="pBurger" aria-label="Menu">☰</button>
      <div class="ptop-crumb"><span>NextGen Vanguard</span><span class="ptop-sep">/</span><span class="ptop-here">My dashboard</span></div>
      <div class="ptop-actions">
        <span class="save-msg" id="saveMsg">Saved ✓</span>
        <button class="ptop-icon" id="pThemeBtn" title="Light / dark" aria-label="Toggle light or dark">◐</button>
      </div>
    </div>

    <div class="portal-scroll" id="top">
      <?php if (!$enabled): ?>
      <div class="ngv-banner">⏳ The next cohort is being prepared — your personal tools below still work, and your progress is saved.</div>
      <?php endif; ?>

      <div class="phead">
        <div>
          <h1>Welcome back, <?= $e($first) ?> 👋</h1>
          <p class="phead-sub">Your NextGen Vanguard home — track your journey, log the 24-book challenge, choose your track &amp; plan, and keep your focus in view. Everything here saves to your account.</p>
        </div>
        <div class="phead-actions">
          <a class="pbtn pbtn-ghost" href="/academy/ngv/" target="_blank" rel="noopener">Programme page ↗</a>
          <a class="pbtn pbtn-gold" href="#account">View my account</a>
        </div>
      </div>

      <!-- KPIs -->
      <div class="pkpis">
        <div class="pkpi">
          <div class="pkpi-top"><span class="pkpi-label">Current phase</span><?php if ($myPhase === 'done'): ?><span class="pchip pchip--green">Complete</span><?php endif; ?></div>
          <div class="pkpi-value" id="tilePhase" style="font-size:17px"><?= $e($phaseLabel) ?></div>
          <div class="pkpi-sub">of the <?= count($phases) ?: 2 ?>-phase journey</div>
        </div>
        <div class="pkpi">
          <div class="pkpi-top"><span class="pkpi-label">My track</span></div>
          <div class="pkpi-value" id="tileTrack" style="font-size:15px"><?= $myTrack !== '' ? $e($myTrack) : '—' ?></div>
          <div class="pkpi-sub"><?= $myTrack !== '' ? 'Locked in' : 'Pick one below' ?></div>
        </div>
        <div class="pkpi">
          <div class="pkpi-top"><span class="pkpi-label">Reading challenge</span></div>
          <div class="pkpi-value"><span id="tileBooks"><?= $booksRead ?></span> / <?= $BOOKS_TOTAL ?></div>
          <div class="pkpi-sub">books this year</div>
        </div>
        <div class="pkpi">
          <div class="pkpi-top"><span class="pkpi-label">Account</span><?php if ($owed > 0): ?><span class="pchip pchip--red">Due</span><?php else: ?><span class="pchip pchip--green">Clear</span><?php endif; ?></div>
          <div class="pkpi-value"><?= $owed > 0 ? '₦' . number_format($owed) : 'All clear' ?></div>
          <div class="pkpi-sub"><?= $owed > 0 ? 'outstanding' : 'nothing outstanding' ?></div>
        </div>
      </div>

      <div class="ngv-grid">

        <!-- My journey -->
        <section class="pcard wide" id="journey">
          <div class="pcard-head"><h2>My journey</h2><span class="pcard-sub">Tap the phase you're in</span></div>
          <div class="pcard-body">
            <?php foreach ($phases as $i => $ph): $on = ($myPhase === (string)($i + 1)) || ($myPhase === 'done'); ?>
            <div class="ngv-phase <?= $on ? 'on' : '' ?>" data-phase-row="<?= $i + 1 ?>">
              <div class="dot"><?= $myPhase === 'done' ? '✓' : ($i + 1) ?></div>
              <div>
                <?php if (!empty($ph['tag'])): ?><div class="tag"><?= $e((string)$ph['tag']) ?></div><?php endif; ?>
                <h3><?= $e((string)($ph['title'] ?? 'Phase ' . ($i + 1))) ?></h3>
                <?php if (!empty($ph['when'])): ?><div class="when"><?= $e((string)$ph['when']) ?></div><?php endif; ?>
                <?php if (!empty($ph['items']) && is_array($ph['items'])): ?>
                <ul><?php foreach ($ph['items'] as $it): ?><li><?= $e((string)$it) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
            <div class="ngv-btns">
              <?php for ($i = 1; $i <= max(2, count($phases)); $i++): ?>
              <button class="pbtn pbtn-ghost phase-btn <?= $myPhase === (string)$i ? 'on' : '' ?>" data-phase="<?= $i ?>">I'm in Phase <?= $i ?></button>
              <?php endfor; ?>
              <button class="pbtn pbtn-ghost phase-btn <?= $myPhase === 'done' ? 'on' : '' ?>" data-phase="done">Completed 🎓</button>
            </div>
          </div>
        </section>

        <!-- My track + plan -->
        <section class="pcard" id="track">
          <div class="pcard-head"><h2>My track &amp; plan</h2></div>
          <div class="pcard-body">
            <div class="ngv-sub-h">My track</div>
            <div class="ngv-picks">
              <?php foreach ($tracks as $t): $nm = (string)($t['name'] ?? ''); if ($nm === '') continue; ?>
              <div class="trk <?= $myTrack === $nm ? 'on' : '' ?>" data-track="<?= $e($nm) ?>">
                <div class="i"><?= $e((string)($t['icon'] ?? '🎯')) ?></div>
                <div class="n"><?= $e($nm) ?></div>
                <div class="d"><?= $e((string)($t['desc'] ?? '')) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php if ($myTrack !== ''): ?><div class="ngv-box" id="trackNote">You're on <b><?= $e($myTrack) ?></b>. <?= $e($myTrackDesc) ?></div><?php endif; ?>

            <?php if (!empty($planOpts)): ?>
            <div class="ngv-sub-h">My plan</div>
            <div class="ngv-picks">
              <?php foreach ($planOpts as $pl): $pn = (string)$pl['name']; ?>
              <div class="trk <?= $myPlan === $pn ? 'on' : '' ?>" data-plan="<?= $e($pn) ?>">
                <div class="i">💳</div>
                <div class="n"><?= $e($pn) ?> · <?= $e((string)$pl['priceLabel']) ?></div>
                <div class="d"><?= $e((string)$pl['desc']) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="ngv-box">Your plan sets your <b>training fee</b> in <a href="#account">Your account</a>. Free tracks stay free — no one is turned away for lack.</div>
            <?php endif; ?>
          </div>
        </section>

        <!-- Focus note -->
        <section class="pcard" id="focus">
          <div class="pcard-head"><h2>My focus this month</h2><span class="pcard-sub">private to you</span></div>
          <div class="pcard-body">
            <textarea id="note" maxlength="200" placeholder="What are you committed to this month? e.g. finish 2 books, ship my track project, show up on time every day."><?= $e($myNote) ?></textarea>
            <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
              <button class="pbtn pbtn-gold" id="noteSave">Save note</button>
              <span style="font-size:12px;color:var(--muted-2)"><span id="noteCount"><?= mb_strlen($myNote) ?></span>/200</span>
            </div>
          </div>
        </section>

        <!-- 24-book reading challenge -->
        <section class="pcard wide" id="reading">
          <div class="pcard-head"><h2>24-book reading challenge</h2><span class="pcard-sub">tap a book once you finish it</span></div>
          <div class="pcard-body">
            <div class="ngv-books" id="books">
              <?php for ($i = 0; $i < $BOOKS_TOTAL; $i++): $on = ($myBooks[$i] ?? '0') === '1'; ?>
              <div class="book <?= $on ? 'on' : '' ?>" data-i="<?= $i ?>" title="Book <?= $i + 1 ?>"><?= $i + 1 ?></div>
              <?php endfor; ?>
            </div>
            <div class="ngv-bar"><i id="booksBar" style="width:<?= (int)round($booksRead / $BOOKS_TOTAL * 100) ?>%"></i></div>
            <div style="font-size:12.5px;color:var(--muted)"><b id="booksLabel"><?= $booksRead ?></b> of <?= $BOOKS_TOTAL ?> read — leadership, finance, law &amp; your track. Keep going!</div>
          </div>
        </section>

        <!-- Your account — training fee, membership, monthly commitment, any
             fines, and every entry behind them. One plain figure, never in red,
             no deadline, and the money conversation pointed at a person. -->
        <section class="pcard" id="account">
          <div class="pcard-head"><h2>Your account</h2><span class="pcard-sub">recorded by your team</span></div>
          <div class="pcard-body">
            <?php if (!$feesOn): ?>
              <div class="ngv-figure clear">Nothing to pay</div>
              <div class="ngv-figure-sub">fees aren't switched on yet</div>
              <div class="ngv-box">When your team starts recording membership and commitment, it will appear here — with
                every entry, so you can always see what a figure is made of.</div>
            <?php else: ?>
              <?php if ($owed > 0): ?>
                <div class="ngv-figure">₦<?= number_format($owed) ?></div>
                <div class="ngv-figure-sub">outstanding</div>
              <?php else: ?>
                <div class="ngv-figure clear">All clear</div>
                <div class="ngv-figure-sub">nothing outstanding</div>
              <?php endif; ?>

              <?php if ((int)$account['paidAhead'] > 0): ?>
                <div class="ngv-box">You're ₦<?= number_format((int)$account['paidAhead']) ?> ahead on a fee that's already
                  settled. It stays on your record as paid ahead rather than being moved onto something else.</div>
              <?php endif; ?>

              <?php $T = is_array($account['training'] ?? null) && !empty($account['training']) ? $account['training'] : null; ?>
              <?php if ($account['planLabel'] !== ''): ?>
              <div class="ngv-box">
                You're on the <b><?= $e((string)$account['planLabel']) ?></b> plan.
                <?php if ($account['planFree']): ?>Your training is <b>free</b> — only membership and the monthly commitment apply.
                <?php elseif ($T): ?>Its training fee is shown below, month by month.
                <?php elseif ((int)$account['planFee'] > 0): ?>
                  Its training fee is ₦<?= number_format((int)$account['planFee']) ?>; nothing has been agreed on your account yet.
                <?php else: ?>Its training fee is shown below.<?php endif; ?>
              </div>
              <?php endif; ?>

              <!-- Per line, not one netted total: "square on membership, two
                   months behind on commitment" is something you can act on. -->
              <div class="ngv-rows" style="margin-top:12px">
                <?php foreach ($account['lines'] as $ln): ?>
                <div class="ngv-row">
                  <div><span class="k"><?= $e((string)$ln['label']) ?></span><span class="d"><?= $e((string)$ln['detail']) ?></span></div>
                  <span class="amt">
                    <?php if (!empty($ln['free'])): ?><span class="pchip pchip--green">Free</span>
                    <?php elseif ((int)$ln['due'] > 0): ?><span class="pchip pchip--red">₦<?= number_format((int)$ln['due']) ?></span>
                    <?php elseif ((int)$ln['charged'] > 0): ?><span class="pchip pchip--green">Paid</span>
                    <?php else: ?><span class="pchip pchip--gold">Not yet charged</span><?php endif; ?>
                  </span>
                </div>
                <?php endforeach; ?>
                <div class="ngv-row"><div><span class="k">Received from you</span><span class="d">across every fee</span></div><span class="amt">₦<?= number_format((int)$account['received']) ?></span></div>
                <?php if ((int)$account['waived'] > 0): ?>
                <div class="ngv-row"><div><span class="k">Set aside for you</span><span class="d">waived by your team — not money you paid, and not money you owe</span></div><span class="amt">₦<?= number_format((int)$account['waived']) ?></span></div>
                <?php endif; ?>
              </div>

              <?php if ($T && (int)$T['months'] > 1): ?>
              <!-- Month by month. A ₦240,000 total says nothing you can plan
                   around; "instalment 4 of 12, ₦20,000, this month" does. -->
              <div class="ngv-sched">
                <div class="ngv-sched-head">
                  <b>Your training fee</b>
                  <span><?= (int)$T['settled'] ?> of <?= (int)$T['months'] ?> charged ·
                    ₦<?= number_format((int)$T['paid']) ?> of ₦<?= number_format((int)$T['total']) ?> paid</span>
                </div>
                <div class="ngv-bar"><span style="width:<?= (int)$T['total'] > 0 ? min(100, round(100 * (int)$T['paid'] / (int)$T['total'])) : 0 ?>%"></span></div>
                <div class="ngv-insts">
                  <?php foreach ($T['instalments'] as $i): ?>
                    <div class="ngv-inst ngv-inst--<?= $e((string)$i['state']) ?>">
                      <span class="mo"><?= $e(date('M y', (int) strtotime($i['period'] . '-01'))) ?></span>
                      <span class="amt">₦<?= number_format((int)$i['amount']) ?></span>
                      <span class="st"><?= $i['state'] === 'charged' ? 'on your account' : ($i['state'] === 'due' ? 'due' : 'to come') ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
                <p class="ngv-fine">Only the months already on your account are being asked for. The rest arrive one at a
                  time, and the total was fixed when this was agreed — it does not change if the programme's prices do.</p>
              </div>
              <?php endif; ?>

              <?php $rcByPay = []; foreach ($myReceipts as $rc) $rcByPay[(int)$rc['id']] = $rc; ?>
              <?php if ($myReceipts): ?>
              <!-- Somebody who handed over cash has no other proof it arrived.
                   These are the same payment rows as the ledger below, addressed
                   so they can be opened, printed, or shown to a third party. -->
              <details class="ngv-details">
                <summary>My receipts (<?= count($myReceipts) ?>)</summary>
                <div class="ngv-rows" style="margin-top:6px">
                  <?php foreach ($myReceipts as $rc): ?>
                  <div class="ngv-row"<?= $rc['void'] ? ' style="opacity:.55"' : '' ?>>
                    <div>
                      <span class="k"><?= $e((string)$rc['no']) ?>
                        <?php if ($rc['void']): ?><span class="pchip pchip--red">cancelled</span><?php endif; ?></span>
                      <span class="d"><?= $e((string)$rc['lineLabel']) ?><?= $rc['period'] !== '' ? ' · ' . $e((string)$rc['period']) : '' ?>
                        · paid <?= $e((string)$rc['paidOn']) ?><?= $rc['method'] !== '' ? ' · ' . $e((string)$rc['method']) : '' ?></span>
                    </div>
                    <span class="amt">₦<?= number_format((int)$rc['amount']) ?>
                      <a class="ngv-mini" href="/academy/ngv/receipt.php?id=<?= (int)$rc['id'] ?>&amp;c=<?= urlencode($rc['code']) ?>"
                         target="_blank" rel="noopener">open</a></span>
                  </div>
                  <?php endforeach; ?>
                </div>
                <p class="ngv-fine">Each opens a printable receipt anyone can check without an account — useful as proof of
                  payment. It shows that one payment and nothing else about your account.</p>
              </details>
              <?php endif; ?>

              <?php if (!empty($myEntries)): ?>
              <details class="ngv-details" style="margin-top:10px">
                <summary>See every entry (<?= count($myEntries) ?>)</summary>
                <div class="ngv-rows" style="margin-top:6px">
                  <?php foreach ($myEntries as $en): $isCharge = $en['side'] === 'charge'; ?>
                  <div class="ngv-row"<?= $en['void'] ? ' style="opacity:.55"' : '' ?>>
                    <div>
                      <span class="k"><?= $e($isCharge
                            ? ($kindLabel[$en['kind']] ?? ucfirst((string)$en['kind']))
                            : (($creditWord[$en['creditKind']] ?? 'Payment received') . ' · ' . ($kindLabel[$en['kind']] ?? $en['kind']))) ?></span>
                      <span class="d">
                        <?= $e(substr((string)$en['created_at'], 0, 10)) ?>
                        <?= $en['period'] !== '' ? ' · ' . $e((string)$en['period']) : '' ?>
                        <?= $en['note'] !== '' ? ' · ' . $e((string)$en['note']) : '' ?>
                        <?= $en['void'] ? ' · cancelled (' . $e((string)$en['voidReason']) . ')' : '' ?>
                        <?php if (!$isCharge && $en['creditKind'] === 'payment'): $rc = $rcByPay[(int)$en['id']] ?? null; ?>
                          <?php if ($rc): ?> · <a href="/academy/ngv/receipt.php?id=<?= (int)$rc['id'] ?>&amp;c=<?= urlencode($rc['code']) ?>"
                            target="_blank" rel="noopener">receipt <?= $e($rc['no']) ?></a><?php endif; ?>
                        <?php endif; ?>
                      </span>
                    </div>
                    <span class="amt"><?= $isCharge ? '' : '− ' ?>₦<?= number_format((int)$en['amount']) ?></span>
                  </div>
                  <?php endforeach; ?>
                </div>
                <p class="ngv-fine">Nothing is ever deleted here. A correction stays visible with its reason, so this list
                  always adds up to the figure at the top.</p>
              </details>
              <?php else: ?>
              <div class="ngv-box">Nothing on your account yet. When your team records a charge or a payment it shows here.</div>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($feesOn && !empty($account['payTo'])): ?><div class="ngv-box"><?= $e((string)$account['payTo']) ?></div><?php endif; ?>

            <?php if ($feesOn): ?>
            <!-- Somebody who wants their position in writing should be able to
                 get it without asking a person for it. -->
            <div class="ngv-box">
              Want this in writing? <button class="pbtn pbtn-ghost" id="stmtBtn" type="button">Email me my statement</button>
              <span id="stmtOut" class="ngv-fine"></span>
            </div>
            <?php endif; ?>

            <!-- The page promises "no one is turned away for lack — speak to your
                 track lead or send a letter requesting consideration". Repeating
                 that and stopping there makes it a dead end: the person who most
                 needs it is the one least likely to walk up and start the
                 conversation. So here is the conversation, in one box. -->
            <?php
              $reqs = is_array($account['requests'] ?? null) ? $account['requests'] : [];
              $openReq = null;
              foreach ($reqs as $rq) { if ($rq['status'] === 'open') { $openReq = $rq; break; } }
            ?>
            <?php if ($openReq): ?>
              <div class="ngv-box">
                <b>Your track lead has your message.</b><br>
                <span class="ngv-quote"><?= $e((string)$openReq['message']) ?></span><br>
                Sent <?= $e(substr((string)$openReq['created_at'], 0, 10)) ?>. They'll come back to you here.
              </div>
            <?php elseif ($feesOn): ?>
              <!-- Offered only while fees are actually running. "I need
                   consideration this month" against an account with nothing on
                   it is a form that invites a message nobody can answer. An
                   existing request still shows above either way, so a reply is
                   never lost because a switch was flipped. -->
              <details class="ngv-details ngv-ask">
                <summary>Something look wrong, or is this a difficult month?</summary>
                <p class="ngv-fine" style="margin-top:6px">Tell your track lead here rather than letting it sit. Nothing you
                  write changes your account on its own — a person reads it and replies.</p>
                <select id="reqKind" class="ngv-input">
                  <option value="consideration">I need consideration this month</option>
                  <option value="query">A figure here looks wrong</option>
                </select>
                <textarea id="reqMsg" class="ngv-input" rows="3" maxlength="1200"
                  placeholder="A sentence or two is plenty."></textarea>
                <button class="pbtn pbtn-gold" id="reqSend" type="button">Send it</button>
                <span id="reqMsgOut" class="ngv-fine"></span>
              </details>
            <?php endif; ?>

            <?php foreach (array_slice(array_filter($reqs, static fn($r) => $r['status'] !== 'open'), 0, 3) as $rq): ?>
              <div class="ngv-box">
                <b><?= $rq['status'] === 'declined' ? 'Answered' : 'Sorted' ?>
                  — <?= $e(substr((string)$rq['handled_at'], 0, 10)) ?></b><br>
                <span class="ngv-quote"><?= $e((string)$rq['message']) ?></span><br>
                <?= $e((string)$rq['outcome']) ?>
              </div>
            <?php endforeach; ?>

            <?php if (!empty($account['note'])): ?><div class="ngv-box"><?= $e((string)$account['note']) ?></div><?php endif; ?>
          </div>
        </section>

        <!-- ══ Damage ═══════════════════════════════════════════════════════
             Recording damage costs nothing, and this section says so before it
             says anything else. The natural fear on being told "damage has been
             recorded" is a bill, and the status line is what replaces guessing
             with knowing. -->
        <section class="pcard" id="damage">
          <div class="pcard-head"><h2>Damage &amp; equipment</h2>
            <span class="pcard-sub"><?= $myDamage ? count($myDamage) . ' on record' : 'nothing on record' ?></span></div>
          <div class="pcard-body">
            <?php if ($myDamage): ?>
              <div class="ngv-rows">
                <?php foreach ($myDamage as $d): ?>
                <div class="ngv-row">
                  <div>
                    <span class="k"><?= $e((string)$d['item']) ?>
                      <?php if ($d['selfReport']): ?><span class="pchip pchip--green">you told us</span><?php endif; ?></span>
                    <span class="d">
                      <?= $e((string)$d['occurred_on']) ?><?= $d['place'] !== '' ? ' · ' . $e((string)$d['place']) : '' ?>
                      · <?= $e((string)$d['severityLabel']) ?>
                      <?php if ($d['outcome'] !== ''): ?><br><?= $e((string)$d['outcome']) ?><?php endif; ?>
                    </span>
                  </div>
                  <span class="amt">
                    <?php if ($d['status'] === 'charged'): ?>
                      <span class="pchip pchip--red">₦<?= number_format((int)$d['charged']) ?></span>
                    <?php elseif ($d['open']): ?>
                      <span class="pchip pchip--gold"><?= $e((string)$d['statusLabel']) ?></span>
                    <?php else: ?>
                      <span class="pchip pchip--green"><?= $e((string)$d['statusLabel']) ?></span>
                    <?php endif; ?>
                  </span>
                </div>
                <?php endforeach; ?>
              </div>
              <p class="ngv-fine">Nothing is charged until you are told a figure. Where something has been assessed and
                you are being asked for less than it cost, the programme is carrying the rest.</p>
            <?php else: ?>
              <div class="ngv-box">Nothing on record. If you break or lose something, say so here — it costs you nothing
                to report, and telling us yourself is the right instinct.</div>
            <?php endif; ?>

            <details class="ngv-details ngv-ask">
              <summary>Report damage or something lost</summary>
              <p class="ngv-fine" style="margin-top:6px">This charges you nothing. It records what happened, and your track
                lead will tell you if there is anything to pay before it reaches your account.</p>
              <input id="dmItem" class="ngv-input" maxlength="120" placeholder="What was it? (e.g. laptop screen, chair)">
              <div class="ngv-two">
                <input id="dmWhen" class="ngv-input" type="date" value="<?= $e(function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d')) ?>">
                <select id="dmSev" class="ngv-input">
                  <?php foreach (NgvDamage::SEVERITIES as $k => $lbl): ?><option value="<?= $e($k) ?>"><?= $e($lbl) ?></option><?php endforeach; ?>
                </select>
              </div>
              <textarea id="dmDesc" class="ngv-input" rows="3" maxlength="1200" placeholder="What happened?"></textarea>
              <button class="pbtn pbtn-gold" id="dmSend" type="button">Report it</button>
              <span id="dmOut" class="ngv-fine"></span>
            </details>
          </div>
        </section>

        <!-- Certifications -->
        <section class="pcard" id="certs">
          <div class="pcard-head"><h2>My certifications</h2><span class="pcard-sub"><?= count($myCerts) ?> earned</span></div>
          <div class="pcard-body">
            <?php if (!empty($myCerts)): ?>
            <div class="ngv-rows">
              <?php foreach ($myCerts as $cert): $certUrl = '/academy/ngv/certificate.php?id=' . (int)$cert['id'] . '&c=' . rawurlencode((string)($cert['code'] ?? '')); ?>
              <div class="ngv-row">
                <div><span class="k">🏅 <?= $e((string)($cert['title'] ?? '')) ?></span><span class="d"><?= $e(trim(((string)($cert['issued_by'] ?? '')) . (!empty($cert['issued_on']) ? ' · ' . substr((string)$cert['issued_on'], 0, 10) : ''), ' ·')) ?></span></div>
                <a class="amt pcard-link" href="<?= $e($certUrl) ?>" target="_blank" rel="noopener">View / print ↗</a>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="ngv-box">No certifications yet — earn them by completing your track milestones. The programme offers up to <?= $e((string)($stats[1]['num'] ?? '6')) ?> per year.</div>
            <?php endif; ?>
          </div>
        </section>

        <!-- Schedule & where -->
        <section class="pcard" id="schedule">
          <div class="pcard-head"><h2>Schedule &amp; where</h2></div>
          <div class="pcard-body">
            <div class="ngv-rows">
              <?php foreach (['days' => 'Attendance', 'time' => 'Daily schedule', 'uniform' => 'Dress code'] as $k => $lbl): if (!empty($sched[$k])): ?>
              <div class="ngv-row"><div><span class="k"><?= $e($lbl) ?></span><span class="d"><?= $e((string)$sched[$k]) ?></span></div></div>
              <?php endif; endforeach; ?>
              <?php foreach ($offices as $o): if (empty($o['name'])) continue; ?>
              <div class="ngv-row"><div><span class="k">📍 <?= $e((string)$o['name']) ?></span><span class="d"><?= $e((string)($o['address'] ?? '')) ?></span></div></div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

        <!-- Skills + support -->
        <section class="pcard wide" id="support">
          <div class="pcard-head"><h2>Skills you're building &amp; support</h2></div>
          <div class="pcard-body">
            <?php if ($marquee): ?>
            <div class="ngv-pills">
              <?php foreach ($marquee as $m): ?><span class="ngv-pill"><?= $e((string)$m) ?></span><?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="ngv-contact">
              <?php if (!empty($ct['phone'])): $tel = preg_replace('/[^0-9+]/', '', (string)$ct['phone']); ?>
              <a href="tel:<?= $e($tel) ?>">📞 Call your team</a>
              <a href="https://wa.me/<?= $e(ltrim($tel, '+')) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
              <?php endif; ?>
              <?php if (!empty($ct['email'])): ?><a href="mailto:<?= $e((string)$ct['email']) ?>">✉️ <?= $e((string)$ct['email']) ?></a><?php endif; ?>
              <a href="/academy/ngv/#faq" target="_blank" rel="noopener">❓ Programme FAQ</a>
            </div>
          </div>
        </section>

      </div>
    </div>
  </main>
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

  /* Asking for consideration. Deliberately NOT routed through save(): that one
     toasts "Saved" and swallows the server's reason, and the two answers this
     can give — "tell us a little about it" and "you already have one open" —
     are the whole conversation. */
  var reqSend = document.getElementById('reqSend');
  if(reqSend) reqSend.addEventListener('click', function(){
    var kindEl = document.getElementById('reqKind'), msgEl = document.getElementById('reqMsg');
    var out = document.getElementById('reqMsgOut');
    var body = {action:'fee_request', kind: kindEl ? kindEl.value : 'consideration', message: msgEl ? msgEl.value : ''};
    reqSend.disabled = true;
    fetch(location.pathname, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, credentials:'same-origin', body:JSON.stringify(body)})
      .then(function(r){ return r.json().catch(function(){ return {ok:false}; }); })
      .then(function(j){
        reqSend.disabled = false;
        if(j && j.ok){ if(out) out.textContent = 'Sent — your track lead will come back to you here.';
                       setTimeout(function(){ location.reload(); }, 1200); }
        else if(out){ out.textContent = (j && j.error) || 'Could not send that — try again.'; }
      })
      .catch(function(){ reqSend.disabled = false; if(out) out.textContent = 'Offline — not sent.'; });
  });

  /* Ask for the statement by email. Same reasoning as the request box: save()
     toasts "Saved" and swallows the server's reason, and "we sent you one today"
     is the answer worth reading. */
  var stmtBtn = document.getElementById('stmtBtn');
  if(stmtBtn) stmtBtn.addEventListener('click', function(){
    var out = document.getElementById('stmtOut');
    stmtBtn.disabled = true; if(out) out.textContent = 'Sending…';
    fetch(location.pathname, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, credentials:'same-origin', body:JSON.stringify({action:'statement'})})
      .then(function(r){ return r.json().catch(function(){ return {ok:false}; }); })
      .then(function(j){
        stmtBtn.disabled = false;
        if(out) out.textContent = (j && j.ok)
          ? (j.delivered ? 'Sent to ' + (j.to || 'your email') + '.' : 'We could not get that email out — tell your track lead.')
          : ((j && j.error) || 'Could not send that.');
      })
      .catch(function(){ stmtBtn.disabled = false; if(out) out.textContent = 'Offline — not sent.'; });
  });

  // Reporting your own damage. Costs nothing; staff decide if money follows.
  var dmSend = document.getElementById('dmSend');
  if(dmSend) dmSend.addEventListener('click', function(){
    var out = document.getElementById('dmOut'), g = function(id){ var el=document.getElementById(id); return el?el.value:''; };
    var body = {action:'damage', item:g('dmItem'), occurred_on:g('dmWhen'), severity:g('dmSev'), description:g('dmDesc')};
    if(!body.item.trim()){ if(out) out.textContent = 'What was it?'; return; }
    if(body.description.trim().length < 10){ if(out) out.textContent = 'A sentence or two about what happened, please.'; return; }
    dmSend.disabled = true;
    fetch(location.pathname, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, credentials:'same-origin', body:JSON.stringify(body)})
      .then(function(r){ return r.json().catch(function(){ return {ok:false}; }); })
      .then(function(j){
        dmSend.disabled = false;
        if(j && j.ok){ if(out) out.textContent = 'Recorded. Nothing has been charged.'; setTimeout(function(){ location.reload(); }, 1200); }
        else if(out){ out.textContent = (j && j.error) || 'Could not record that.'; }
      })
      .catch(function(){ dmSend.disabled = false; if(out) out.textContent = 'Offline — not recorded.'; });
  });

  // Track picker (scoped to track tiles — the plan tiles below reuse .trk)
  document.querySelectorAll('.trk[data-track]').forEach(function(el){
    el.addEventListener('click', function(){
      var name = el.getAttribute('data-track');
      var wasOn = el.classList.contains('on');
      document.querySelectorAll('.trk[data-track]').forEach(function(x){ x.classList.remove('on'); });
      var val = wasOn ? '' : name;
      if(!wasOn) el.classList.add('on');
      save({track: val}, function(){
        var tile = document.getElementById('tileTrack');
        if(tile){ tile.textContent = val || '—'; }
        var tsub = tile && tile.nextElementSibling; if(tsub) tsub.textContent = val ? 'Locked in' : 'Pick one below';
      });
    });
  });

  // Plan picker — sets the training fee shown in "Your account". A reload
  // follows a change so the account figure and the fee line recompute server-
  // side (the money is computed there, never in the browser).
  document.querySelectorAll('.trk[data-plan]').forEach(function(el){
    el.addEventListener('click', function(){
      var name = el.getAttribute('data-plan');
      var wasOn = el.classList.contains('on');
      document.querySelectorAll('.trk[data-plan]').forEach(function(x){ x.classList.remove('on'); });
      var val = wasOn ? '' : name;
      if(!wasOn) el.classList.add('on');
      save({plan: val}, function(){ setTimeout(function(){ location.reload(); }, 500); });
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

  // Theme toggle — same cookie the member portal uses, so the choice carries
  // between the two surfaces.
  var themeBtn = document.getElementById('pThemeBtn');
  if(themeBtn){ themeBtn.addEventListener('click', function(){
    var dark = document.body.classList.toggle('is-dark');
    document.cookie = 'av_portal_theme=' + (dark?'dark':'light') + ';path=/;max-age=31536000;samesite=Lax';
  }); }

  // Sidebar on small screens
  var burger = document.getElementById('pBurger'), side = document.getElementById('pside');
  if(burger && side){ burger.addEventListener('click', function(){ side.classList.toggle('is-open'); }); }

  // Scrollspy — light-touch active state on the sidebar nav.
  var links = {}; document.querySelectorAll('.pnav-link[data-spy]').forEach(function(a){ links[a.getAttribute('data-spy')] = a; });
  var spied = Object.keys(links).map(function(id){ return document.getElementById(id); }).filter(Boolean);
  if('IntersectionObserver' in window && spied.length){
    var io = new IntersectionObserver(function(entries){
      entries.forEach(function(en){ if(en.isIntersecting){ var a = links[en.target.id]; if(a){ Object.values(links).forEach(function(x){ x.classList.remove('is-active'); }); a.classList.add('is-active'); } } });
    }, {rootMargin:'-45% 0px -50% 0px'});
    spied.forEach(function(s){ io.observe(s); });
  }
})();
</script>
</body>
</html>
