<?php
/**
 * login/index.php — sign in / create account (route /login).
 *
 * Rebuilt to the design "Afrovanguard Sign In" (REPLACEMENT_MAP row 10).
 * A standalone screen: brand panel (from 960px) + the form column, no site
 * nav or footer. Three steps, one visible at a time:
 *   1 Email   — Continue with Google, or enter an email address
 *   2 Verify  — emailed one-time code (or a password, when allowed)
 *   3 Secure  — optionally add a password after a code sign-in
 * Behaviour in /assets/site/avsi.js; it talks to /academy/api.php, which holds
 * every server check (same-origin, rate limits, org → Google, policy).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';   // av_auth_illustration()

/* Guarantee the default Super Admin exists before anyone tries to sign in
 * (idempotent + fingerprint-guarded → a single cheap lookup once provisioned). */
if (class_exists('SuperAdmin')) { try { SuperAdmin::ensure(); } catch (Throwable $e) {} }

/* ---- where to send the visitor after sign-in (same-origin path only) ---- */
$next = (string) ($_GET['next'] ?? '');
// A backslash or control character is refused too: browsers read "/\\host" as
// "//host", which would make this an open redirect.
if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '\\') || preg_match('/[\x00-\x1F\x7F]/', $next)) {
    $next = '/portal/';
}

/* friendly messages for the OAuth / email-verification round-trips (?e=… / ?verify_error=1) */
$errorMap = [
    'google_off'        => 'Google sign-in isn’t set up yet — use your email below.',
    'google_failed'     => 'We couldn’t complete Google sign-in. Please try again, or use your email.',
    'google_cancelled'  => 'Google sign-in was cancelled.',
    'google_state'      => 'That sign-in link expired. Please try again.',
    'rate'              => 'Too many attempts — wait a moment and try again.',
];
$authError = $errorMap[(string) ($_GET['e'] ?? '')] ?? '';
if ($authError === '' && isset($_GET['verify_error'])) {
    $authError = 'That verification link is invalid or has expired. Sign in below and we can send a new one.';
}

/* already signed in → straight through */
if (LmsAuth::user()) { header('Location: ' . $next); exit; }

if (function_exists('send_security_headers')) send_security_headers('public');

/* Studio-scheduled sign-in art wins; otherwise the design's photograph. */
$illo        = av_auth_illustration() ?: '/Images/summer6.jpg';
$methods     = AuthPolicy::publicMethods();   // ['otp'=>bool,'password'=>bool,'google'=>bool]
$otpLen      = AuthPolicy::otpLength();        // 4–8 — drives the segmented code inputs
$otpMin      = max(1, (int) round(AuthPolicy::otpTtl() / 60));
$pwMin       = (int) AuthPolicy::get()['password_min_len'];
$googleStart = '/auth/google/start?next=' . rawurlencode($next);
$orgDomain   = defined('AV_ORG_DOMAIN') ? (string) AV_ORG_DOMAIN : 'afrovanguard.org.ng';
$canonical   = rtrim(SITE_URL, '/') . '/login';

$cfg = [
    'next'      => $next,
    'methods'   => $methods,
    'otpLen'    => $otpLen,
    'pwMin'     => $pwMin,
    // Afrovanguard accounts sign in with Google only — the page redirects an
    // org-domain email to Google (with it pre-filled) instead of code/password.
    'orgDomain' => $orgDomain,
    'googleOn'  => GoogleAuth::configured() && ($methods['google'] ?? false),
];

$title = 'Sign in — Afrovanguard';
$desc  = 'Sign in to your Afrovanguard account to continue learning, track your progress, and reach members-only programmes and the community.';
?>
<!DOCTYPE html>
<html lang="en-NG" class="no-js">
<head>
  <script>document.documentElement.classList.replace('no-js','js')</script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($desc) ?>" />
  <meta name="robots" content="noindex, nofollow" />
  <link rel="canonical" href="<?= e($canonical) ?>" />
  <meta name="theme-color" content="rgb(17,24,39)" />
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="Afrovanguard" />
  <meta property="og:locale" content="en_NG" />
  <meta property="og:title" content="<?= e($title) ?>" />
  <meta property="og:description" content="<?= e($desc) ?>" />
  <meta property="og:url" content="<?= e($canonical) ?>" />
  <meta property="og:image" content="<?= e(rtrim(SITE_URL, '/')) ?>/Images/og-image.png" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:site" content="@afrovanguard" />
  <meta name="twitter:title" content="<?= e($title) ?>" />
  <meta name="twitter:description" content="<?= e($desc) ?>" />
  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/site/icon-192.png" />
  <link rel="apple-touch-icon" href="/assets/site/icon-192.png" />
  <link rel="stylesheet" href="/assets/site/fonts.css" />
  <link rel="stylesheet" href="/assets/site/av-tokens.css" />
  <link rel="stylesheet" href="/assets/site/avsi.css" />
  <script src="/assets/site/avsi.js" defer></script>
</head>
<body class="avsi">
<a class="avsi-skip" href="#avsi-card">Skip to sign in</a>
<main class="avsi-main" id="main" data-screen="Sign in">
  <aside class="avsi-aside">
    <img class="avsi-aside-img" src="<?= e($illo) ?>" alt="" decoding="async" fetchpriority="high" />
    <div class="avsi-veil" aria-hidden="true"></div>
    <a class="avsi-aside-brand" href="/"><img src="/assets/site/av-seal.png" alt="" width="44" height="44" /><span>Afrovanguard</span></a>
    <div class="avsi-aside-copy">
      <h2>Raising one million incorruptible leaders for Africa by 2040.</h2>
      <p>Sign in to learn, track your progress, and build the movement with us.</p>
      <ul class="avsi-points">
        <li>Free, hands-on programmes</li>
        <li>Progress &amp; certificates</li>
        <li>Mentorship &amp; community</li>
      </ul>
    </div>
    <span class="avsi-est">Est. 2018 · Alimosho, Lagos</span>
  </aside>

  <section class="avsi-col" aria-labelledby="avsi-h">
    <div class="avsi-top">
      <a class="avsi-brand" href="/"><img src="/assets/site/av-seal.png" alt="" width="36" height="36" /><span>Afrovanguard</span></a>
      <a class="avsi-back" href="/"><span aria-hidden="true">←</span>&nbsp;Back to site</a>
    </div>
    <div class="avsi-center">
      <div class="avsi-card" id="avsi-card" tabindex="-1" data-avsi data-cfg="<?= e(json_encode($cfg, JSON_UNESCAPED_SLASHES)) ?>">
        <ol class="avsi-steps" aria-label="Sign-in progress" data-avsi-steps>
          <li class="is-on" data-step="1" aria-current="step">Email</li>
          <li data-step="2">Verify</li>
          <li data-step="3">Secure</li>
        </ol>
        <header class="avsi-head">
          <h1 id="avsi-h" data-avsi-h>Welcome</h1>
          <p data-avsi-sub>Sign in or create your account — it only takes a moment.</p>
        </header>
<?php if ($authError !== ''): ?>
        <p class="avsi-banner" role="alert" data-avsi-banner><?= e($authError) ?></p>
<?php endif; ?>

        <!-- Step 1 · email -->
        <div class="avsi-stack" data-avsi-step="1">
<?php if (!empty($methods['google'])): ?>
          <a class="avsi-google" href="<?= e($googleStart) ?>" data-avsi-google><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="rgb(66,133,244)" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z"/><path fill="rgb(52,168,83)" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/><path fill="rgb(251,188,5)" d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84z"/><path fill="rgb(234,67,53)" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1A11 11 0 0 0 2.18 7.06l3.66 2.84C6.71 7.3 9.14 5.38 12 5.38z"/></svg>Continue with Google</a>
          <div class="avsi-or">or use your email</div>
<?php endif; ?>
          <form class="avsi-form" data-avsi-email-form novalidate>
            <label class="avsi-label" for="avsi-email">Email address
              <input class="avsi-input" id="avsi-email" name="email" type="email" required autocomplete="email" autocapitalize="off" spellcheck="false" enterkeyhint="next" placeholder="you@example.com" aria-describedby="avsi-email-err" />
            </label>
            <span class="avsi-err" id="avsi-email-err" role="alert" data-avsi-email-err></span>
            <button class="avsi-btn" type="submit" data-avsi-cont>Continue</button>
          </form>
        </div>

        <!-- Step 2 · verify -->
        <div class="avsi-stack" data-avsi-step="2" hidden>
          <div class="avsi-ident"><span>Signing in as</span><strong data-avsi-who></strong><button type="button" class="avsi-link" data-avsi-change>Change</button></div>
<?php if (!empty($methods['otp'])): ?>
          <form class="avsi-form avsi-form--code" data-avsi-code-form novalidate>
            <span class="avsi-codelabel" id="avsi-code-l">Sign-in code <span>· sent to your inbox, expires in <?= (int) $otpMin ?> <?= $otpMin === 1 ? 'minute' : 'minutes' ?></span></span>
            <div class="avsi-otp" role="group" aria-labelledby="avsi-code-l" style="--avsi-n:<?= (int) $otpLen ?>" data-avsi-otp>
<?php for ($i = 1; $i <= $otpLen; $i++): ?>
              <input class="avsi-box" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]*" autocomplete="<?= $i === 1 ? 'one-time-code' : 'off' ?>" aria-label="Digit <?= $i ?>" />
<?php endfor; ?>
            </div>
            <p class="avsi-msg avsi-msg--tight" role="status" aria-live="polite" data-avsi-msg="code"></p>
            <input class="avsi-otp-hidden" tabindex="-1" aria-hidden="true" inputmode="numeric" autocomplete="one-time-code" maxlength="<?= (int) $otpLen ?>" data-avsi-code />
            <details class="avsi-name"><summary>New here? Add your name</summary><label class="av-sr" for="avsi-name">Your name</label><input class="avsi-input" id="avsi-name" type="text" autocomplete="name" enterkeyhint="go" placeholder="e.g. Ada Obi" data-avsi-name /></details>
            <button class="avsi-btn is-dim" type="submit" data-avsi-verify>Verify &amp; continue</button>
            <span class="avsi-resend">Didn’t get it? <button type="button" class="avsi-link" data-avsi-resend>Resend code</button></span>
          </form>
<?php endif; ?>
<?php if (!empty($methods['password'])): ?>
          <form class="avsi-form" data-avsi-pw-form novalidate<?= !empty($methods['otp']) ? ' hidden' : '' ?>>
            <label class="avsi-label" for="avsi-pw">Password
              <span class="avsi-pw"><input class="avsi-input" id="avsi-pw" type="password" autocomplete="current-password" enterkeyhint="go" placeholder="Your password" /><button type="button" class="avsi-pwtoggle" aria-pressed="false" aria-label="Show password" data-avsi-pwtoggle="avsi-pw">Show</button></span>
            </label>
            <p class="avsi-msg avsi-msg--tight" role="status" aria-live="polite" data-avsi-msg="pw"></p>
            <button class="avsi-btn" type="submit" data-avsi-pwsubmit>Sign in</button>
          </form>
<?php endif; ?>
<?php if (!empty($methods['otp']) && !empty($methods['password'])): ?>
          <div class="avsi-or"><button type="button" class="avsi-link avsi-swap" data-avsi-swap>Use a password instead</button></div>
<?php endif; ?>
        </div>

        <!-- Step 3 · secure (optional) -->
        <form class="avsi-form" data-avsi-step="3" data-avsi-setpw-form novalidate hidden>
          <div class="avsi-okbar"><span aria-hidden="true">✓</span>Verified — you’re signed in.</div>
          <label class="avsi-label" for="avsi-npw">New password
            <span class="avsi-pw"><input class="avsi-input" id="avsi-npw" type="password" autocomplete="new-password" enterkeyhint="go" placeholder="Choose a password" aria-describedby="avsi-npw-hint" /><button type="button" class="avsi-pwtoggle" aria-pressed="false" aria-label="Show password" data-avsi-pwtoggle="avsi-npw">Show</button></span>
          </label>
          <div class="avsi-meter" aria-hidden="true" data-score="0" data-avsi-meter><span></span><span></span><span></span><span></span></div>
          <span class="avsi-hint" id="avsi-npw-hint"><span data-avsi-meterlabel>Choose something memorable</span> · at least <?= (int) $pwMin ?> characters. You can change it any time.</span>
          <p class="avsi-msg" role="status" aria-live="polite" data-avsi-setpw-msg></p>
          <button class="avsi-btn" type="submit" data-avsi-setpw>Save password</button>
          <a class="avsi-skipto" href="<?= e($next) ?>" data-avsi-skip>Skip for now → go to your portal</a>
        </form>

        <p class="avsi-fine">A member with an <strong>@<?= e($orgDomain) ?></strong> address? Use Continue with Google. By continuing you agree to our <a href="/terms/">Terms</a> and <a href="/privacy-policy/">Privacy Policy</a>.</p>
      </div>
    </div>
  </section>
</main>
</body>
</html>
