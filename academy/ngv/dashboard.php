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
    /* Every action below is a vanguard's. Somebody not enrolled — or
       withdrawn — is told so; nothing here enrols them as a side effect. */
    if (!NgvMember::isVanguard($uid)) {
        json_out(['ok' => false, 'code' => 'not_enrolled',
                  'error' => 'You are not enrolled in NextGen Vanguard. Apply at /academy/ngv/register.php.'], 403);
    }

    /* A self-report with a photo arrives as multipart, so it is handled before
       php://input is read — that stream is empty on a multipart request, and
       parsing it first would drop the report on the floor. One step rather than
       two on purpose: somebody reporting damage from a phone should not have to
       submit, wait for a reload, and then find the attach button. */
    if ((string) ($_POST['action'] ?? '') === 'damage') {
        $r = NgvDamage::report($uid, $_POST, 0, true);
        if (!empty($r['ok']) && !empty($_FILES['photos'])) {
            $up = NgvDamage::addPhotos((int) $r['id'], $_FILES['photos'], 0);
            /* The report is already saved. A photo that would not upload is
               reported as a note on a success, never as a failure that loses
               what they typed. */
            if (empty($up['ok'])) $r['photoNote'] = (string) ($up['error'] ?? '');
            else $r['photos'] = (int) $up['added'];
        }
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    if ((string) ($_POST['action'] ?? '') === 'damage_photos') {
        /* Only onto their OWN record. Without this check any signed-in member
           could attach a picture to somebody else's incident. */
        $d = NgvDamage::get((int) ($_POST['damage_id'] ?? 0));
        if (!$d || (int) $d['member_id'] !== $uid) json_out(['ok' => false, 'error' => 'No such record.'], 404);
        $r = NgvDamage::addPhotos((int) $d['id'], $_FILES['photos'] ?? [], 0);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }

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

    /* ── Book claims ──────────────────────────────────────────────────
       Their own slots only. The member id comes from the session and is
       never read from the body — a claim is for whoever is signed in. */
    $bkAct = (string) ($in['book_action'] ?? '');
    if ($bkAct === 'save' || $bkAct === 'submit') {
        $r = NgvReading::save($uid, is_array($in['book'] ?? null) ? $in['book'] : [], $bkAct === 'submit');
        if (!empty($r['ok'])) $r['progress'] = NgvReading::progress($uid);
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    if ($bkAct === 'get') {
        $slot = (int) ($in['slot'] ?? 0);
        $c = NgvReading::claim($uid, $slot);
        json_out(['ok' => true, 'claim' => $c, 'min' => NgvReading::MIN_REFLECTION,
                  'minTake' => NgvReading::MIN_TAKEAWAY]);
    }

    $patch = [];
    /* Track, plan and phase are staff's (NgvMember::saveSelf says why). A
       request that names them is refused out loud rather than half-applied,
       so a stale tab shows "not saved" instead of a tick for nothing. */
    if (array_key_exists('track', $in) || array_key_exists('plan', $in) || array_key_exists('phase', $in)) {
        json_out(['ok' => false, 'error' => 'Your track and plan are set by the programme team. Ask your track lead to change them.'], 403);
    }
    /* Books are not self-reported any more — a claim goes through
       NgvReading and a track lead verifies it. Left here as a comment rather
       than deleted silently so nobody re-adds it wondering why it is missing. */
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

/* The dashboard lives in the member portal now, so a vanguard has one place
   to go. This page stays for staff previewing it (?preview=1).
   It used to enrol, on first visit, whoever was not a vanguard yet — so the
   admin bar's preview link, or any signed-in account typing the address,
   became an ACTIVE vanguard: fees accrued and the gate expected them. Nobody
   is enrolled by looking now. A preview is staff's and is read-only. Email
   links (#account) keep their fragment through the redirect. */
$isVanguard = NgvMember::isVanguard((int) $u['id']);
$ngvPreview = !empty($_GET['preview']) && function_exists('av_admin_role') && av_admin_role() !== '';
if ($isVanguard && !$ngvPreview) {
    header('Location: /portal/#ngv', true, 302);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
if (!$isVanguard && !$ngvPreview) {
    http_response_code(403);
    $nm = htmlspecialchars(trim(explode(' ', trim((string) ($u['name'] ?? '')))[0]) ?: 'there', ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow"><title>Not enrolled · NextGen Vanguard</title>'
       . '<link rel="stylesheet" href="/portal/portal.css"><body class="portal-app"><main style="max-width:34rem;margin:12vh auto;padding:0 1rem">'
       . '<h1>Hi ' . $nm . ', you are not on NextGen Vanguard yet.</h1>'
       . '<p>The dashboard is for enrolled vanguards. Apply, and the team will be in touch once your place is confirmed.</p>'
       . '<p><a class="btn" href="/academy/ngv/register.php">Apply to NextGen Vanguard</a> &nbsp; <a href="/portal/">Back to your portal</a></p>'
       . '</main>';
    exit;
}
require __DIR__ . '/_dashboard-data.php';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>My Dashboard · NextGen Vanguard</title>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portal/portal.css">
<link rel="stylesheet" href="/academy/ngv/ngv-dashboard.css">
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
        <a class="pnav-link" href="#track" data-spy="track"><span class="pnav-dot"></span><span class="pnav-label">Track &amp; plan</span></a>
        <a class="pnav-link" href="#account" data-spy="account"><span class="pnav-dot <?= $owed > 0 ? '' : 'pnav-dot--green' ?>"></span><span class="pnav-label">Your account</span><?php if ($owed > 0): ?><span class="pnav-badge">₦<?= number_format($owed) ?></span><?php endif; ?></a>
        <?php $dmgOpen = 0; foreach ($myDamage as $d) { if ($d['open']) $dmgOpen++; } ?>
        <a class="pnav-link" href="#damage" data-spy="damage"><span class="pnav-dot <?= $dmgOpen > 0 ? '' : ($myDamage ? 'pnav-dot--green' : '') ?>"></span><span class="pnav-label">Damage</span><?php if ($dmgOpen > 0): ?><span class="pnav-badge"><?= $dmgOpen ?></span><?php endif; ?></a>
        <a class="pnav-link" href="#certs" data-spy="certs"><span class="pnav-dot"></span><span class="pnav-label">Certifications</span><span class="pnav-badge"><?= count($myCerts) ?></span></a>
        <a class="pnav-link" href="#reading" data-spy="reading"><span class="pnav-dot"></span><span class="pnav-label">Reading</span><span class="pnav-badge"><?= $booksRead ?>/<?= $BOOKS_TOTAL ?></span></a>
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
        <button class="ptop-icon" id="pThemeBtn" title="Light / dark" aria-label="Toggle light or dark">◐</button>
      </div>
    </div>

    <div class="portal-scroll" id="top">
<?php require __DIR__ . '/_dashboard-body.php'; ?>
    </div>
  </main>
</div>

<script>
(function(){
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
