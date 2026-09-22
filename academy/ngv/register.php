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
        $html = "<p>Hi {$first},</p>"
              . '<p>Thank you for applying to <b>NextGen Vanguard</b> — we\'ve received your application and our team will review it and be in touch.</p>'
              . '<p><b>Next step:</b> if you don\'t already have an Afrovanguard account, create one with this same email so we can enrol you and open your dashboard.</p>'
              . '<p>— Afrovanguard Academy</p>';
        try { Mailer::send($email, 'We received your NextGen Vanguard application', $html); } catch (Throwable $e) {}
    }

    $admin = defined('ADMIN_EMAIL') && ADMIN_EMAIL ? (string) ADMIN_EMAIL : (defined('FROM_EMAIL') ? (string) FROM_EMAIL : '');
    if ($admin !== '') {
        $rows = '';
        foreach (['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'age' => 'Age', 'location' => 'Location', 'education' => 'Status', 'track' => 'Track', 'plan' => 'Plan', 'message' => 'Message'] as $k => $lbl) {
            $v = trim((string) ($d[$k] ?? '')); if ($v === '') continue;
            $rows .= '<tr><td style="padding:2px 12px 2px 0;color:#5f6874;vertical-align:top">' . $esc($lbl) . '</td><td>' . nl2br($esc($v)) . '</td></tr>';
        }
        $html = '<p>New NextGen Vanguard application (#' . (int) $id . ').</p>'
              . '<table style="border-collapse:collapse">' . $rows . '</table>'
              . ($site ? '<p style="margin-top:14px"><a href="' . $esc($site . '/academy/ngv/members.php') . '">Review in the Vanguards console →</a></p>' : '');
        try { Mailer::send($admin, 'New NGV application: ' . $name, $html); } catch (Throwable $e) {}
    }
}

$c        = Ngv::get();
$e        = 'e';
$tracks   = is_array($c['tracks'] ?? null) ? $c['tracks'] : [];
$plans    = Ngv::activePlans();
$ct       = is_array($c['contact'] ?? null) ? $c['contact'] : [];
$enabled  = Ngv::isEnabled();

$done = false;
$err  = '';
$old  = ['name' => '', 'email' => '', 'phone' => '', 'age' => '', 'gender' => '', 'location' => '', 'education' => '', 'track' => '', 'plan' => '', 'message' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_same_origin();
    $hp = trim((string) ($_POST['website'] ?? '')); // honeypot: humans never see/fill this
    $ip = function_exists('av_client_ip') ? av_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0');
    $limited = function_exists('av_rate_ok') && !av_rate_ok('ngv_apply_' . $ip, 5, 900); // 5 / 15 min

    foreach ($old as $k => $_) $old[$k] = (string) ($_POST[$k] ?? '');

    if ($hp !== '') {
        $done = true; // silently absorb bots — look successful, store nothing
    } elseif ($limited) {
        $err = 'You have submitted a few times already. Please wait a little while and try again.';
    } elseif (trim($old['name']) === '' || !filter_var(trim($old['email']), FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter your full name and a valid email address.';
    } else {
        $id = NgvMember::submitApplication($old + ['source' => 'register']);
        if ($id > 0) {
            $done = true;
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
<title>Register · NextGen Vanguard</title>
<meta name="description" content="Apply to join NextGen Vanguard — Afrovanguard Academy's transformation programme. Learn future-ready skills, earn stipends and gain global certifications.">
<link rel="canonical" href="<?= $e($canon) ?>">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--red:#e4162b;--orange:#ff6a1a;--gold:#ffb703;--ink:#15120e;--line:#e7e9ee;--muted:#5f6874;--bg:#f5f6f8;--card:#fff;--grad:linear-gradient(100deg,#e4162b,#ff6a1a 55%,#ffb703);--r:16px}
*{box-sizing:border-box}
body{margin:0;font-family:Montserrat,system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.55}
a{color:var(--red)}
h1,h2{margin:0;letter-spacing:-.01em}
:focus-visible{outline:3px solid var(--orange);outline-offset:2px}
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
  </div>
</div>

<div class="wrap">
  <div class="card">
    <div class="body">
    <?php if ($done): ?>
      <div class="ok" role="status">
        <div class="big">🎉</div>
        <h2>Application received!</h2>
        <p>Thank you<?= $old['name'] !== '' ? ', ' . $e(trim(explode(' ', trim($old['name']))[0])) : '' ?> — your application is in. Our team will review it and reach out<?= !empty($old['email']) ? ' at ' . $e($old['email']) : '' ?>.</p>
        <p>Next step: if you don't already have an Afrovanguard account, create one with the same email so we can enrol you and open your dashboard.</p>
        <p><a class="btn" href="/academy/ngv/">Back to the programme</a></p>
      </div>
    <?php else: ?>
      <?php /* A submission that comes back with an error lands the reader on the
               reason for it, rather than at the top of a form they have to work
               out for themselves. */ ?>
      <?php if ($err !== ''): ?><div class="err" id="err" role="alert" tabindex="-1" autofocus><?= $e($err) ?></div><?php endif; ?>
      <form method="post" action="/academy/ngv/register.php" id="form" novalidate>
        <div class="hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <p class="reqnote"><span class="req">*</span> Required. Everything else helps us place you, but you can leave it blank.</p>

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
            <div class="fld"><label for="f-age">Age</label>
              <input id="f-age" type="text" name="age" maxlength="12" inputmode="numeric" value="<?= $e($old['age']) ?>"></div>
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
            <div class="fld"><label for="f-plan">Plan</label>
              <select id="f-plan" name="plan">
                <option value="">— Not sure yet —</option>
                <?php foreach ($plans as $pl): $pn = (string)($pl['name'] ?? ''); if ($pn==='') continue; ?>
                <option value="<?= $e($pn) ?>" <?= $old['plan'] === $pn ? 'selected' : '' ?>><?= $e($pn) ?><?= !empty($pl['price']) ? ' — ' . $e((string)$pl['price']) : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </fieldset>

        <fieldset>
          <legend>In your own words</legend>
          <div class="fld"><label for="f-message">Why do you want to join? <span class="opt">(optional)</span></label>
            <textarea id="f-message" name="message" maxlength="1500" placeholder="Tell us a little about yourself and your goals."><?= $e($old['message']) ?></textarea>
          </div>
        </fieldset>

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
