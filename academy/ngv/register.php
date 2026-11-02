<?php
/**
 * academy/ngv/register.php — public NextGen Vanguard registration / application.
 *
 * The real in-app intake that replaces the old external bit.ly form: a
 * prospective vanguard applies here and the application lands in the SEPARATE
 * NGV database (ngv_applications), where staff review and enrol them from the
 * console (academy/ngv/members.php). No account needed to apply.
 *
 * Progressive + robust for low-end mobile: a classic <form method="post"> that
 * works with JS off. Anonymous-intake defenses mirror process-contact.php —
 * honeypot + same-origin + per-IP rate limit (no login/CSRF for a public form).
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

/**
 * Acknowledge the applicant + alert staff on a new application. Best-effort:
 * wrapped so a mail hiccup never blocks the form (matches the site's
 * graceful-degradation posture — the application is already saved).
 */
function ngv_notify_application(int $id, array $d): void
{
    if (!class_exists('Mailer')) return;
    $esc   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $name  = trim((string) ($d['name'] ?? '')) ?: 'there';
    $first = $esc(explode(' ', $name)[0]);
    $email = trim((string) ($d['email'] ?? ''));
    $site  = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        /* It used to send four bare <p> tags, so it arrived in browser-default
           Times with no margins. Same words, in the house template. */
        $html = Mailer::shell(
            'We have your application',
            [
                "Hi {$first},",
                'Thank you for applying to <b>NextGen Vanguard</b> — we\'ve received your application and our team will review it and be in touch.',
                'Your reference is <b>' . $esc($ref = class_exists('NgvRegistration') ? NgvRegistration::ensureRef($id) : '') . '</b>. Keep it.',
                '<b>What happens next:</b> if you are invited, you print the MoU from your application page, book a time, and come to the CACENTRE to finish registering with the MoU signed by you and your guarantor, your ID, and your guarantor in person. You do not need to make an account — one is made for you then.',
            ],
            $ref !== '' ? ['text' => 'Open your application', 'url' => NgvRegistration::link($ref)] : null,
            'We received your NextGen Vanguard application.',
            ['eyebrow' => 'NextGen Vanguard']
        );
        try { Mailer::send($email, 'We received your NextGen Vanguard application', $html); } catch (Throwable $e) {}
    }

    $admin = defined('ADMIN_EMAIL') && ADMIN_EMAIL ? (string) ADMIN_EMAIL : (defined('FROM_EMAIL') ? (string) FROM_EMAIL : '');
    if ($admin !== '') {
        $rows = '';
        foreach (['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'dob' => 'Date of birth', 'guardian_name' => 'Parent/guardian', 'guardian_email' => 'Guardian email', 'location' => 'Location', 'education' => 'Status', 'track' => 'Track', 'plan' => 'Plan', 'message' => 'Message'] as $k => $lbl) {
            $v = trim((string) ($d[$k] ?? '')); if ($v === '') continue;
            $rows .= '<tr><td style="padding:2px 12px 2px 0;color:#5f6874;vertical-align:top">' . $esc($lbl) . '</td><td>' . nl2br($esc($v)) . '</td></tr>';
        }
        $html = '<p>New NextGen Vanguard application (#' . (int) $id . ').</p>'
              . '<table style="border-collapse:collapse">' . $rows . '</table>'
              . ($site ? '<p style="margin-top:14px"><a href="' . $esc($site . '/academy/ngv/members.php#applications') . '">Invite or reject this applicant in the Vanguards console →</a></p>' : '');
        try { Mailer::send($admin, 'New NGV application: ' . $name, $html); } catch (Throwable $e) {}
    }
}

$c        = Ngv::get();
$e        = 'e';
$tracks   = is_array($c['tracks'] ?? null) ? $c['tracks'] : [];
$ct       = is_array($c['contact'] ?? null) ? $c['contact'] : [];
$enabled  = Ngv::isEnabled();

$done = false;
$err  = '';
$old  = ['name' => '', 'email' => '', 'phone' => '', 'dob' => '', 'gender' => '', 'location' => '', 'education' => '', 'track' => '', 'plan' => '', 'message' => '',
         'centre' => '', 'availability' => '',
         'g_name' => '', 'g_relationship' => '', 'g_relationship_other' => '', 'g_phone' => '', 'g_email' => ''];
$agreed = false;
$questions = NgvRegistration::questions();
$centres   = NgvRegistration::centres();
$answers   = [];       // question index => chosen option, as posted
$wrongQ    = [];       // question index => the explanation, for a wrong answer
$ref       = '';       // the new application's reference, once saved
$guardianAsked = false;   // their parent or guardian still has to confirm
$guardianSent  = false;   // …and the email asking them went out just now
/* Arriving from NGG's application ("18 and over — register for NGV"): what
   they already typed there comes with them, so the hand-off does not start
   from an empty form. Only a GET pre-fills; nothing is saved until they send. */
$fromNgg = ($_GET['from'] ?? '') === 'ngg';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    foreach (['name' => 120, 'email' => 160, 'phone' => 40, 'dob' => 10] as $k => $max) {
        if (isset($_GET[$k]) && is_string($_GET[$k])) $old[$k] = mb_substr(trim($_GET[$k]), 0, $max);
    }
    if ($old['dob'] !== '' && NgvMember::cleanDob($old['dob']) === '') $old['dob'] = '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_same_origin();
    $hp = trim((string) ($_POST['website'] ?? '')); // honeypot: humans never see/fill this
    $ip = function_exists('av_client_ip') ? av_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0');
    $limited = function_exists('av_rate_ok') && !av_rate_ok('ngv_apply_' . $ip, 5, 900); // 5 / 15 min

    foreach ($old as $k => $_) $old[$k] = is_string($_POST[$k] ?? null) ? (string) $_POST[$k] : '';
    $agreed     = !empty($_POST['consent']);
    foreach ((array) ($_POST['q'] ?? []) as $i => $v) if (is_scalar($v)) $answers[(int) $i] = (int) $v;
    /* School leavers are often 16 or 17, and they may join as long as they can
       keep the programme's commitment: their availability is asked, their
       guarantor must be their parent or guardian, and that person is told. */
    $dob   = NgvMember::cleanDob($old['dob']);
    $ageN  = $dob !== '' ? (int) NgvMember::ageFromDob($dob) : 0;
    $minor = $dob !== '' && $ageN < 18;
    $understood = NgvRegistration::checkUnderstanding($answers);
    $guar = NgvRegistration::cleanGuarantor($old, $minor, $old['email']);

    if ($hp !== '') {
        $done = true; // silently absorb bots — look successful, store nothing
    } elseif ($limited) {
        $err = 'You have submitted a few times already. Please wait a little while and try again.';
    } elseif (trim($old['name']) === '' || !filter_var(trim($old['email']), FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter your full name and a valid email address.';
    } elseif ($dob === '') {
        $err = 'Please give your date of birth.';
    } elseif ($ageN < 13 || $ageN > 99) {
        $err = 'NextGen Vanguard is open from age 13. Please check your date of birth.';
    } elseif (!$understood['ok']) {
        /* Not a refusal of the person: the right answers are shown beside the
           questions, and they answer again. */
        $wrongQ = $understood['wrong'];
        $err = 'Some answers about the programme were not right. The right answer is now shown under each one — read it, then choose again.';
    } elseif (empty($guar['ok'])) {
        $err = (string) $guar['error'];
    } elseif ($centres && !isset($centres[$old['centre']])) {
        $err = 'Choose the CACENTRE where you will finish registering.';
    } elseif (trim($old['availability']) === '') {
        $err = 'Tell us when you can attend — the programme meets ' . trim((string) ($c['schedule']['days'] ?? '') . ' ' . (string) ($c['schedule']['time'] ?? '')) . '.';
    } elseif (!$agreed) {
        $err = 'Please confirm you have read how we use your details.';
    } else {
        $old['dob'] = $dob;
        $row = $old + $guar['g'];
        /* A child's guarantor IS their parent or guardian: they are the one told
           about the application before the visit. Their consent is given in
           person, on the MoU, at the CACENTRE. */
        if ($minor) $row += ['guardian_name' => $guar['g']['g_name'], 'guardian_phone' => $guar['g']['g_phone'], 'guardian_email' => $guar['g']['g_email']];
        $id = NgvMember::submitApplication($row + ['source' => $fromNgg ? 'ngg' : 'register', 'consent' => true, 'understood' => true]);
        if ($id > 0) {
            $done = true;
            $ref = NgvRegistration::ensureRef($id);
            if ($minor) {
                /* Not on every resubmission: a repeat within ten minutes is a
                   correction, and a second email would only confuse the parent. */
                $app = NgvMember::application($id);
                $last = $app ? strtotime((string) $app['guardian_asked_at']) : false;
                if ($app && !NgvMember::guardianConfirmed($app) && ($last === false || $last < time() - 600)) {
                    try { $ask = NgvMember::askGuardian($id); $guardianSent = !empty($ask['sent']); } catch (Throwable $e2) {}
                }
                $guardianAsked = $app && !NgvMember::guardianConfirmed($app);
            }
            try { NgvMember::autolinkApplication($id); } catch (Throwable $e2) {}   // pre-link if they already have an account
            try { ngv_notify_application($id, $old); } catch (Throwable $e2) {}      // acknowledge + alert staff (graceful)
            if (class_exists('Events')) { try { Events::emit('ngv.application', ['id' => $id]); } catch (Throwable $e2) {} }
        } else {
            $err = 'Something went wrong saving your application. Please try again.';
        }
    }
}

$canon = rtrim(SITE_URL, '/') . '/academy/ngv/register.php';
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php /* A hand-off from NGG carries their name and email in the address; it goes no further than this site. */ ?>
<meta name="referrer" content="same-origin">
<title>Register · NextGen Vanguard</title>
<meta name="description" content="Apply to join NextGen Vanguard — Afrovanguard Academy's transformation programme. Learn future-ready skills, earn stipends and gain global certifications.">
<link rel="canonical" href="<?= $e($canon) ?>">
  <link href="<?= e(av_asset('/assets/site/fonts.css')) ?>" rel="stylesheet" />
<style>
:root{--red:#e4162b;--orange:#ff6a1a;--gold:#ffb703;--ink:#15120e;--line:#e7e9ee;--muted:#5f6874;--bg:#f5f6f8;--card:#fff;--grad:linear-gradient(100deg,#e4162b,#ff6a1a 55%,#ffb703);--r:16px}
*{box-sizing:border-box}
body{margin:0;font-family:'Source Sans 3',system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.55}
a{color:var(--red)}
h1,h2{margin:0;letter-spacing:-.01em}
:focus-visible{outline:3px solid var(--orange);outline-offset:2px}
.chk{display:flex;gap:.6rem;align-items:flex-start;margin:.9rem 0;font-size:.95rem;line-height:1.45}
.chk input{margin-top:.25rem;width:1.1rem;height:1.1rem;flex:none}
.skip{position:absolute;left:-9999px;top:0;background:#fff;color:var(--ink);font-weight:700;padding:10px 16px;border-radius:0 0 10px 0;z-index:10}
.skip:focus{left:0}

/* ── Frame ───────────────────────────────────────────────────────────── */
.wrap{max-width:760px;margin:0 auto;padding:0 18px}
.top{background:rgba(21,18,14,.97);color:#fff;display:flex;gap:12px;align-items:center;padding:12px 20px;flex-wrap:wrap}
.top .brand{font-weight:800;color:#fff;text-decoration:none}
.top .brand b{color:var(--gold)}
.top .sp{flex:1}
.top a{color:rgba(255,255,255,.85);text-decoration:none;font-weight:600;font-size:.86rem;
  display:inline-flex;align-items:center;min-height:28px}
.top a:hover{color:#fff}
.paused{background:#15120e;color:#ffd9a8;font-size:.9rem;padding:8px 0}
.hero{background:var(--grad);color:#fff;padding:34px 0}
.hero h1{margin:0 0 6px;font-size:1.9rem;line-height:1.12}
.hero p{margin:0;opacity:.95;max-width:60ch}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);margin:22px 0 40px;overflow:hidden}
.card>.body{padding:22px}
.foot{border-top:1px solid var(--line);padding:18px 0 34px;font-size:.86rem;color:var(--muted)}
.foot a{color:var(--muted)}
.foot p{margin:0 0 4px}

/* ── The form, in the three groups it actually asks about ────────────── */
fieldset{border:0;margin:0 0 26px;padding:0}
fieldset:last-of-type{margin-bottom:18px}
legend{padding:0;font-size:.78rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase;
  color:var(--muted);margin-bottom:14px}
.fld{margin-bottom:16px}
.fld:last-child{margin-bottom:0}
.fld label{display:block;font-weight:700;font-size:.85rem;margin-bottom:6px}
.fld .req{color:var(--red)}
.fld .opt{font-weight:500;color:var(--muted)}
input,select,textarea{width:100%;border:1.5px solid var(--line);border-radius:11px;padding:12px;font:inherit;background:#fff}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--orange)}
textarea{min-height:110px;resize:vertical}
/* Fluid, so a narrow phone and a wide tablet both get a sensible number of
   columns without a breakpoint having to name every width. */
.row2{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
.row2 .fld{margin-bottom:0}
.row2+.row2{margin-top:14px}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.btn{border:0;border-radius:11px;padding:14px 22px;font:inherit;font-weight:800;cursor:pointer;
  background:var(--grad);color:#fff;font-size:1rem;display:inline-block;text-decoration:none;text-align:center}
.btn:hover{opacity:.95}
.note{font-size:.86rem;color:var(--muted);margin-top:14px}
.reqnote{font-size:.82rem;color:var(--muted);margin:0 0 20px}
.err{background:#fdecec;color:#c0322b;border:1px solid #f6c9c6;border-radius:11px;padding:12px 14px;font-weight:600;margin-bottom:18px}
.ok{text-align:center;padding:26px 8px}
.ok .big{font-size:3rem;line-height:1}
.ok h2{margin:12px 0 6px}
.ok p{color:var(--muted);max-width:52ch;margin:0 auto 8px}
.ok .btn{margin-top:18px}
.refbox{background:#fff7e6;border:1px solid #f3d9a4;border-radius:11px;padding:12px 14px;display:inline-block}
.steps{text-align:left;max-width:52ch;margin:12px auto;padding-left:20px;color:var(--ink)}
.steps li{margin:6px 0}
.q{border:1px solid var(--line);border-radius:11px;padding:10px 14px;margin:10px 0}
.q-wrong{border-color:#f6c9c6;background:#fffafa}
.q-t{margin:2px 0 4px;font-weight:600}
.q-why{margin:6px 0 2px;color:#7a2a24;font-size:.92rem}
@media(max-width:560px){.hero h1{font-size:1.55rem}}
</style>
</head>
<body>
<a class="skip" href="#form">Skip to the form</a>
<header class="top">
  <a class="brand" href="/academy/ngv/"><b>NextGen Vanguard</b> · Afrovanguard Academy</a>
  <span class="sp"></span>
  <a href="/academy/ngv/">← Programme</a>
</header>

<?php if (!$enabled && !$done): ?>
<div class="paused" role="status"><div class="wrap">⏳ Applications for the next cohort open soon — register your interest below and we'll reach out first.</div></div>
<?php endif; ?>

<main id="main">
<div class="hero">
  <div class="wrap">
    <h1>Join NextGen Vanguard</h1>
    <p>Learn future-ready skills, earn stipends as you grow, and gain globally recognised certifications. Tell us about yourself and our team will be in touch.</p>
    <?php if ($fromNgg && !$done): ?><p><b>From Next Generation Genius?</b> We have brought over what you typed there. Check it, then fill in the rest.</p><?php endif; ?>
  </div>
</div>

<div class="wrap">
  <div class="card">
    <div class="body">
    <?php if ($done): ?>
      <div class="ok" role="status">
        <div class="big">🎉</div>
        <h2>Application received</h2>
        <p>Thank you<?= $old['name'] !== '' ? ', ' . $e(trim(explode(' ', trim($old['name']))[0])) : '' ?> — your application is in.</p>
        <?php if ($ref !== ''): ?>
          <p class="refbox">Your reference: <b><?= $e($ref) ?></b><br><span class="note">Keep it. You will be asked for it at the CACENTRE.</span></p>
        <?php endif; ?>
        <ol class="steps">
          <li><b>We review your application</b> and email <?= $e($old['email']) ?> when it is decided.</li>
          <li><b>If you are invited</b>, your application page lets you print the MoU and book a time at <?= $e($old['centre'] ?: 'a CACENTRE') ?>.</li>
          <li><b>You come in to finish registering</b> with the MoU signed by you and your guarantor, your ID, and your guarantor in person.</li>
        </ol>
        <?php if ($guardianAsked): ?>
          <p><?= $guardianSent ? 'We have emailed your parent or guardian at <b>' . $e($old['g_email']) . '</b> to tell them about your application.' : 'We will contact your parent or guardian about your application.' ?>
             They come with you to the CACENTRE as your guarantor.</p>
        <?php endif; ?>
        <p>You do not need to make an Afrovanguard account. One is made for you when you register.</p>
        <p><?php if ($ref !== ''): ?><a class="btn" href="<?= $e(NgvRegistration::link($ref)) ?>">Open your application</a> <?php endif; ?>
           <a href="/academy/ngv/">Back to the programme</a></p>
      </div>
    <?php else: ?>
      <?php /* A submission that comes back with an error lands the reader on the
               reason for it, rather than at the top of a form they have to work
               out for themselves. */ ?>
      <?php if ($err !== ''): ?><div class="err" id="err" role="alert" tabindex="-1" autofocus><?= $e($err) ?></div><?php endif; ?>
      <form method="post" action="/academy/ngv/register.php" id="form" novalidate>
        <div class="hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <p class="reqnote"><span class="req">*</span> Required. Everything else helps us place you, but you can leave it blank.</p>

        <?php if ($questions): ?>
        <fieldset>
          <legend>Before you apply</legend>
          <p class="note">Applying online is the first step. To finish, you come to a CACENTRE with the MoU signed by you and your
            guarantor, your ID, and your guarantor in person. These questions check the main points. A wrong answer is not held
            against you — the right one is shown, and you choose again.</p>
          <?php foreach ($questions as $qi => $q): ?>
            <div class="q<?= isset($wrongQ[$qi]) ? ' q-wrong' : '' ?>" role="group" aria-labelledby="q<?= (int)$qi ?>-t">
              <p class="q-t" id="q<?= (int)$qi ?>-t"><?= (int)$qi + 1 ?>. <?= $e($q['q']) ?> <span class="req" aria-hidden="true">*</span></p>
              <?php foreach ($q['options'] as $oi => $opt): ?>
                <label class="chk"><input type="radio" name="q[<?= (int)$qi ?>]" value="<?= (int)$oi + 1 ?>" required
                  <?= ($answers[$qi] ?? 0) === $oi + 1 && !isset($wrongQ[$qi]) ? 'checked' : '' ?>> <?= $e($opt) ?></label>
              <?php endforeach; ?>
              <?php if (isset($wrongQ[$qi])): ?><p class="q-why" role="note"><b>Not quite.</b> <?= $e($wrongQ[$qi]) ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </fieldset>
        <?php endif; ?>

        <fieldset>
          <legend>About you</legend>
          <div class="row2">
            <div class="fld"><label for="f-name">Full name <span class="req" aria-hidden="true">*</span></label>
              <input id="f-name" type="text" name="name" required maxlength="120" autocomplete="name" value="<?= $e($old['name']) ?>"></div>
            <div class="fld"><label for="f-email">Email <span class="req" aria-hidden="true">*</span></label>
              <input id="f-email" type="email" name="email" required maxlength="160" autocomplete="email" value="<?= $e($old['email']) ?>"></div>
          </div>
          <div class="row2">
            <div class="fld"><label for="f-phone">Phone / WhatsApp</label>
              <input id="f-phone" type="tel" name="phone" maxlength="40" autocomplete="tel" value="<?= $e($old['phone']) ?>"></div>
            <div class="fld"><label for="f-dob">Date of birth <span class="req" aria-hidden="true">*</span></label>
              <input id="f-dob" type="date" name="dob" required max="<?= $e(date('Y-m-d')) ?>" autocomplete="bday" value="<?= $e($old['dob']) ?>"></div>
          </div>
          <div class="row2">
            <div class="fld"><label for="f-location">Location</label>
              <input id="f-location" type="text" name="location" maxlength="120" autocomplete="address-level2" placeholder="e.g. Egbeda, Lagos" value="<?= $e($old['location']) ?>"></div>
            <div class="fld"><label for="f-education">Current status</label>
              <select id="f-education" name="education">
                <?php $EDU = ['', 'School leaver', 'NYSC corper', 'Undergraduate', 'Graduate', 'Working', 'Other'];
                foreach ($EDU as $opt): ?><option value="<?= $e($opt) ?>" <?= $old['education'] === $opt ? 'selected' : '' ?>><?= $opt === '' ? '— Select —' : $e($opt) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
        </fieldset>

        <fieldset>
          <legend>What you're interested in</legend>
          <div class="row2">
            <div class="fld"><label for="f-track">Track</label>
              <select id="f-track" name="track">
                <option value="">— No preference yet —</option>
                <?php foreach ($tracks as $t): $tn = (string)($t['name'] ?? ''); if ($tn==='') continue; ?>
                <option value="<?= $e($tn) ?>" <?= $old['track'] === $tn ? 'selected' : '' ?>><?= $e($tn) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fld"><label for="f-centre">Where you will finish registering <span class="req" aria-hidden="true">*</span></label>
              <select id="f-centre" name="centre" required>
                <option value="">— Choose a CACENTRE —</option>
                <?php foreach ($centres as $cn => $cc): ?>
                <option value="<?= $e($cn) ?>" <?= $old['centre'] === $cn ? 'selected' : '' ?>><?= $e($cn) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="fld"><label for="f-avail">When can you attend? <span class="req" aria-hidden="true">*</span></label>
            <p class="note">The programme meets <?= $e(trim((string)($c['schedule']['days'] ?? '') . ', ' . (string)($c['schedule']['time'] ?? ''), ', ')) ?>.
              Say honestly what fits around school, work or other commitments — under-18s are welcome as long as they can keep to it.</p>
            <textarea id="f-avail" name="availability" maxlength="300" required><?= $e($old['availability']) ?></textarea>
          </div>
        </fieldset>

        <fieldset>
          <legend>In your own words</legend>
          <div class="fld"><label for="f-message">Why do you want to join? <span class="opt">(optional)</span></label>
            <textarea id="f-message" name="message" maxlength="1500" placeholder="Tell us a little about yourself and your goals."><?= $e($old['message']) ?></textarea>
          </div>
        </fieldset>

        <fieldset>
          <legend>Your guarantor</legend>
          <p class="note">An adult who knows you and will vouch for your character: a parent or guardian, an employer, a faith leader or
            a community leader. <b>They come to the CACENTRE with you</b> and sign the MoU. They are not responsible for your fees.
            If you are under 18, your guarantor must be your parent or guardian.</p>
          <div class="row2">
            <div class="fld"><label for="f-gname">Their full name <span class="req" aria-hidden="true">*</span></label>
              <input id="f-gname" type="text" name="g_name" maxlength="120" autocomplete="off" required value="<?= $e($old['g_name']) ?>"></div>
            <div class="fld"><label for="f-grel">How they know you <span class="req" aria-hidden="true">*</span></label>
              <select id="f-grel" name="g_relationship" required>
                <option value="">— Choose —</option>
                <?php foreach (NgvRegistration::RELATIONSHIPS as $rk => $rl): ?>
                <option value="<?= $e($rk) ?>" <?= $old['g_relationship'] === $rk ? 'selected' : '' ?>><?= $e($rl) ?></option>
                <?php endforeach; ?>
              </select></div>
          </div>
          <div class="fld"><label for="f-grelo">If "Other", what are they to you?</label>
            <input id="f-grelo" type="text" name="g_relationship_other" maxlength="80" autocomplete="off" value="<?= $e($old['g_relationship_other']) ?>"></div>
          <div class="row2">
            <div class="fld"><label for="f-gphone">Their phone <span class="req" aria-hidden="true">*</span></label>
              <input id="f-gphone" type="tel" name="g_phone" maxlength="40" autocomplete="off" required value="<?= $e($old['g_phone']) ?>"></div>
            <div class="fld"><label for="f-gemail">Their email <span class="opt">(required if you are under 18)</span></label>
              <input id="f-gemail" type="email" name="g_email" maxlength="160" autocomplete="off" value="<?= $e($old['g_email']) ?>"></div>
          </div>
        </fieldset>

        <label class="chk"><input type="checkbox" name="consent" value="1" required <?= $agreed ? 'checked' : '' ?>>
          I have read the <a href="/privacy-policy/" target="_blank" rel="noopener">privacy policy</a>: my details are used
          to process this application and run the programme, and are not sold or shared for marketing. <span class="req" aria-hidden="true">*</span></label>

        <button class="btn" type="submit">Submit my application</button>
        <p class="note">No one is turned away for lack. Committed applicants who need support can say so above or speak to a track lead. We'll only use your details to process your application.</p>
      </form>
    <?php endif; ?>
    </div>
  </div>
</div>
</main>

<footer class="foot">
  <div class="wrap">
    <p><a href="/academy/ngv/">NextGen Vanguard programme</a> · <a href="/academy/">Afrovanguard Academy</a> · <a href="/">Afrovanguard</a></p>
    <?php if (!empty($ct['email']) || !empty($ct['phone'])): ?>
      <p>Questions before you apply?
        <?php if (!empty($ct['phone'])): ?><a href="tel:<?= $e(preg_replace('/[^0-9+]/', '', (string)$ct['phone'])) ?>"><?= $e((string)$ct['phone']) ?></a><?php endif; ?>
        <?php if (!empty($ct['email']) && !empty($ct['phone'])): ?> · <?php endif; ?>
        <?php if (!empty($ct['email'])): ?><a href="mailto:<?= $e((string)$ct['email']) ?>"><?= $e((string)$ct['email']) ?></a><?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
</footer>
</body>
</html>
