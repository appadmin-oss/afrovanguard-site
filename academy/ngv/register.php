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
.top{background:rgba(21,18,14,.97);color:#fff;display:flex;gap:12px;align-items:center;padding:12px 20px;flex-wrap:wrap}
.top .brand{font-weight:800}.top .brand b{color:var(--gold)}.top .sp{flex:1}
.top a{color:rgba(255,255,255,.85);text-decoration:none;font-weight:600;font-size:.86rem}.top a:hover{color:#fff}
.hero{background:var(--grad);color:#fff;padding:34px 0}
.wrap{max-width:760px;margin:0 auto;padding:0 18px}
.hero h1{margin:0 0 6px;font-size:1.9rem;line-height:1.12}
.hero p{margin:0;opacity:.95;max-width:60ch}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);margin:22px 0 40px;overflow:hidden}
.card>.body{padding:22px}
.fld{margin-bottom:16px}
.fld label{display:block;font-weight:700;font-size:.85rem;margin-bottom:6px}
.fld .req{color:var(--red)}
input,select,textarea{width:100%;border:1.5px solid var(--line);border-radius:11px;padding:12px;font:inherit;background:#fff}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--orange)}
textarea{min-height:110px;resize:vertical}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.btn{border:0;border-radius:11px;padding:14px 22px;font:inherit;font-weight:800;cursor:pointer;background:var(--grad);color:#fff;font-size:1rem}
.btn:hover{opacity:.95}
.note{font-size:.86rem;color:var(--muted);margin-top:14px}
.err{background:#fdecec;color:#c0322b;border:1px solid #f6c9c6;border-radius:11px;padding:12px 14px;font-weight:600;margin-bottom:18px}
.ok{text-align:center;padding:26px 8px}
.ok .big{font-size:3rem;line-height:1}
.ok h2{margin:12px 0 6px}
.ok p{color:var(--muted);max-width:52ch;margin:0 auto 8px}
.paused{background:#15120e;color:#ffd9a8;font-size:.9rem;padding:8px 0}
@media(max-width:560px){.row2{grid-template-columns:1fr}.hero h1{font-size:1.55rem}}
</style>
</head>
<body>
<div class="top">
  <span class="brand"><b>NextGen Vanguard</b> · Afrovanguard Academy</span>
  <span class="sp"></span>
  <a href="/academy/ngv/">← Programme</a>
</div>

<?php if (!$enabled && !$done): ?>
<div class="paused"><div class="wrap">⏳ Applications for the next cohort open soon — register your interest below and we'll reach out first.</div></div>
<?php endif; ?>

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
      <div class="ok">
        <div class="big">🎉</div>
        <h2>Application received!</h2>
        <p>Thank you<?= $old['name'] !== '' ? ', ' . $e(trim(explode(' ', trim($old['name']))[0])) : '' ?> — your application is in. Our team will review it and reach out<?= !empty($old['email']) ? ' at ' . $e($old['email']) : '' ?>.</p>
        <p>Next step: if you don't already have an Afrovanguard account, create one with the same email so we can enrol you and open your dashboard.</p>
        <p style="margin-top:18px"><a class="btn" style="display:inline-block;text-decoration:none" href="/academy/ngv/">Back to the programme</a></p>
      </div>
    <?php else: ?>
      <?php if ($err !== ''): ?><div class="err"><?= $e($err) ?></div><?php endif; ?>
      <form method="post" action="/academy/ngv/register.php" novalidate>
        <div class="hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <div class="row2">
          <div class="fld"><label>Full name <span class="req">*</span></label><input type="text" name="name" required maxlength="120" value="<?= $e($old['name']) ?>"></div>
          <div class="fld"><label>Email <span class="req">*</span></label><input type="email" name="email" required maxlength="160" value="<?= $e($old['email']) ?>"></div>
        </div>
        <div class="row2">
          <div class="fld"><label>Phone / WhatsApp</label><input type="tel" name="phone" maxlength="40" value="<?= $e($old['phone']) ?>"></div>
          <div class="fld"><label>Age</label><input type="text" name="age" maxlength="12" inputmode="numeric" value="<?= $e($old['age']) ?>"></div>
        </div>
        <div class="row2">
          <div class="fld"><label>Location</label><input type="text" name="location" maxlength="120" placeholder="e.g. Egbeda, Lagos" value="<?= $e($old['location']) ?>"></div>
          <div class="fld"><label>Current status</label>
            <select name="education">
              <?php $EDU = ['', 'School leaver', 'NYSC corper', 'Undergraduate', 'Graduate', 'Working', 'Other'];
              foreach ($EDU as $opt): ?><option value="<?= $e($opt) ?>" <?= $old['education'] === $opt ? 'selected' : '' ?>><?= $opt === '' ? '— Select —' : $e($opt) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="row2">
          <div class="fld"><label>Track you're interested in</label>
            <select name="track">
              <option value="">— No preference yet —</option>
              <?php foreach ($tracks as $t): $tn = (string)($t['name'] ?? ''); if ($tn==='') continue; ?>
              <option value="<?= $e($tn) ?>" <?= $old['track'] === $tn ? 'selected' : '' ?>><?= $e($tn) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fld"><label>Plan</label>
            <select name="plan">
              <option value="">— Not sure yet —</option>
              <?php foreach ($plans as $pl): $pn = (string)($pl['name'] ?? ''); if ($pn==='') continue; ?>
              <option value="<?= $e($pn) ?>" <?= $old['plan'] === $pn ? 'selected' : '' ?>><?= $e($pn) ?><?= !empty($pl['price']) ? ' — ' . $e((string)$pl['price']) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="fld"><label>Why do you want to join? <span style="font-weight:500;color:var(--muted)">(optional)</span></label>
          <textarea name="message" maxlength="1500" placeholder="Tell us a little about yourself and your goals."><?= $e($old['message']) ?></textarea>
        </div>

        <button class="btn" type="submit">Submit my application</button>
        <p class="note">No one is turned away for lack. Committed applicants who need support can say so above or speak to a track lead. We'll only use your details to process your application.</p>
      </form>
    <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
