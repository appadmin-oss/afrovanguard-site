<?php
/**
 * login/index.php — the standard Afrovanguard sign-in page.
 *
 * Passwordless-first, PROGRESSIVE flow:
 *   Step 1  Identify  — enter your email and continue.
 *   Step 2  Verify    — we email a one-time code (works for sign-in AND
 *                       sign-up); a password is an alternative when allowed.
 *   Step 3  Secure    — optionally add a password right after verifying, so
 *                       you can sign in with it next time (or skip).
 * "Continue with Google" lights up once AV_GOOGLE_CLIENT_ID is configured.
 * WHICH methods appear and how strict the layers are is set by the superadmin
 * (Studio → Sign-in → Security) and read here from AuthPolicy. Honours ?next=
 * (same-origin only).
 *
 * This is a deliberately focused, full-screen auth shell (a brand panel + the
 * form) rather than the full site chrome — it keeps the member through the
 * sign-in without nav/footer distractions, the standard pattern for auth.
 * It still loads the shared <head> (render_head) so it themes light/dark and
 * carries the brand fonts for free.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

/* ---- where to send the visitor after sign-in (same-origin path only) ---- */
$next = (string) ($_GET['next'] ?? '');
if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, "\n")) {
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

$illo          = av_auth_illustration();
$methods       = AuthPolicy::publicMethods();   // ['otp'=>bool,'password'=>bool,'google'=>bool]
$otpLen        = AuthPolicy::otpLength();        // 4–8 — drives the segmented code inputs
$pwMin         = (int) AuthPolicy::get()['password_min_len'];
$googleStart   = '/auth/google/start?next=' . rawurlencode($next);
$orgDomain     = defined('AV_ORG_DOMAIN') ? (string) AV_ORG_DOMAIN : 'afrovanguard.org.ng';
$canonical     = rtrim(SITE_URL, '/') . '/login';

$cfg = [
    'next'      => $next,
    'methods'   => $methods,
    'otpLen'    => $otpLen,
    'pwMin'     => $pwMin,
    // Afrovanguard accounts sign in with Google only — the page redirects an
    // org-domain email to Google (with it pre-filled) instead of code/password.
    'orgDomain' => defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng',
    'googleOn'  => GoogleAuth::configured() && ($methods['google'] ?? false),
];

render_head([
    'title'      => 'Sign in — Afrovanguard',
    'desc'       => 'Sign in to your Afrovanguard account to continue learning, track your progress, and reach members-only programmes and the community.',
    'canonical'  => $canonical,
    'robots'     => 'noindex, nofollow',
    'body_class' => 'auth-page',
    'css'        => ['/login/auth.css'],
]);
?>
  <main class="auth-shell" id="main-content">
    <!-- Brand panel (session-rotated illustration; mirrors Afrostrength) -->
    <aside class="auth-aside">
      <div class="auth-bg" aria-hidden="true">
<?php if ($illo): ?>        <img class="auth-bg__img" src="<?= e($illo) ?>" alt="" decoding="async" fetchpriority="high" />
<?php endif; ?>      </div>
      <div class="auth-veil" aria-hidden="true"></div>
      <div class="auth-aside__content">
        <a class="auth-brand" href="<?= e(rtrim(SITE_URL, '/')) ?>/" aria-label="Afrovanguard — Home">
          <span class="brand-wordmark"><span class="wm-1">Afro</span><span class="wm-2">vanguard</span></span>
        </a>
        <div class="auth-aside__copy">
          <h2>Raising one million incorruptible leaders for Africa by 2040.</h2>
          <p>Sign in to learn, track your progress, and build the movement with us.</p>
        </div>
        <ul class="auth-aside__points" aria-hidden="true">
          <li><span class="ap-dot"></span>Free, hands-on programmes</li>
          <li><span class="ap-dot"></span>Track your progress &amp; certificates</li>
          <li><span class="ap-dot"></span>Members-only mentorship &amp; community</li>
        </ul>
        <p class="auth-aside__foot">Est. 2018 · Alimosho, Lagos</p>
      </div>
    </aside>

    <!-- Form column -->
    <section class="auth-main">
      <div class="auth-main__inner" id="authCard" data-cfg='<?= e(json_encode($cfg, JSON_UNESCAPED_SLASHES)) ?>'>
        <a class="auth-back" href="<?= e(rtrim(SITE_URL, '/')) ?>/"><span aria-hidden="true">←</span> Back to site</a>

        <!-- Progress / step indicator -->
        <ol class="auth-steps" id="authSteps" aria-label="Sign-in progress">
          <li class="is-active" data-step="1"><span class="st-dot">1</span><span class="st-label">Email</span></li>
          <li data-step="2"><span class="st-dot">2</span><span class="st-label">Verify</span></li>
          <li data-step="3"><span class="st-dot">3</span><span class="st-label">Secure</span></li>
        </ol>

        <header class="auth-head">
          <h1 class="auth-h" id="authH">Welcome</h1>
          <p class="auth-sub" id="authSub">Sign in or create your account — it only takes a moment.</p>
        </header>

<?php if ($authError): ?>        <p class="auth-banner" id="authBanner" role="alert"><?= e($authError) ?></p>
<?php endif; ?>

        <!-- Sliding viewport: one panel is shown at a time -->
        <div class="auth-stage" id="authStage">

          <!-- ===== STEP 1 · identify ===== -->
          <section class="auth-panel is-current" id="panelIdentify" data-step="1">
<?php if ($methods['google']): ?>
            <a class="auth-google" id="authGoogle" href="<?= e($googleStart) ?>">
              <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1A11 11 0 0 0 2.18 7.06l3.66 2.84C6.71 7.3 9.14 5.38 12 5.38z"/></svg>
              <span>Continue with Google</span>
            </a>
            <div class="auth-or"><span>or use your email</span></div>
<?php endif; ?>
            <form class="auth-form" id="formIdentify" novalidate>
              <label class="fld" for="identEmailInput"><span>Email address</span>
                <input name="email" id="identEmailInput" type="email" required autocomplete="email"
                       autocapitalize="off" spellcheck="false" enterkeyhint="next"
                       placeholder="you@example.com" aria-describedby="identMsg" />
              </label>
              <button class="auth-btn" type="submit" id="identSubmit">
                <span class="auth-btn__label">Continue</span>
                <span class="auth-btn__spin" aria-hidden="true"></span>
              </button>
              <p class="auth-msg" id="identMsg" role="alert" aria-live="polite"></p>
            </form>
          </section>

          <!-- ===== STEP 2 · authenticate ===== -->
          <section class="auth-panel" id="panelAuth" data-step="2" hidden>
            <p class="auth-ident">
              <span class="auth-ident__as">Signing in as</span>
              <b id="identEmail"></b>
              <button type="button" class="auth-link-btn" id="changeEmail">Change</button>
            </p>

            <!-- code (passwordless) -->
            <form class="auth-method" id="formCode" hidden>
              <p class="auth-mini" id="codeSent">We emailed a one-time code to your address. It expires shortly.</p>
              <div class="fld">
                <span class="fld-label" id="codeLabel">Sign-in code</span>
                <!-- Segmented inputs: a hidden field holds the full value for autofill -->
                <div class="auth-otp" id="otpBoxes" role="group" aria-labelledby="codeLabel"></div>
                <input id="codeInput" class="auth-otp-hidden" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="<?= (int) $otpLen ?>" aria-label="Sign-in code" />
              </div>
              <details class="auth-newname" id="newNameWrap">
                <summary>New here? Add your name</summary>
                <label class="fld" for="nameInput"><span>Your name</span>
                  <input id="nameInput" type="text" autocomplete="name" enterkeyhint="go" placeholder="e.g. Ada Obi" />
                </label>
              </details>
              <button class="auth-btn" type="submit" id="codeSubmit">
                <span class="auth-btn__label">Verify &amp; continue</span>
                <span class="auth-btn__spin" aria-hidden="true"></span>
              </button>
              <p class="auth-mini">Didn’t get it? <button type="button" class="auth-link-btn" id="resendCode">Resend code</button><span class="auth-resend-wait" id="resendWait" hidden></span></p>
            </form>

            <div class="auth-or auth-or--toggle" id="methodToggle" hidden>
              <span><button type="button" class="auth-link-btn" id="toPassword">Use a password instead</button></span>
            </div>

            <!-- password -->
            <form class="auth-method" id="formPassword" hidden>
              <label class="fld" for="pwInput"><span>Password</span>
                <span class="auth-pw">
                  <input id="pwInput" type="password" autocomplete="current-password" enterkeyhint="go" placeholder="Your password" />
                  <button type="button" class="auth-pw-toggle" id="pwToggle" aria-pressed="false" aria-label="Show password">Show</button>
                </span>
              </label>
              <button class="auth-btn" type="submit" id="pwSubmit">
                <span class="auth-btn__label">Sign in</span>
                <span class="auth-btn__spin" aria-hidden="true"></span>
              </button>
              <div class="auth-or auth-or--toggle" id="toCodeWrap" hidden>
                <span><button type="button" class="auth-link-btn" id="toCode">Email me a code instead</button></span>
              </div>
            </form>

            <p class="auth-msg" id="authMsg" role="alert" aria-live="polite"></p>
          </section>

          <!-- ===== STEP 3 · optional: add a password after verifying ===== -->
          <section class="auth-panel" id="panelSecure" data-step="3" hidden>
            <form class="auth-method" id="formSetPw">
              <label class="fld" for="newPwInput"><span>New password</span>
                <span class="auth-pw">
                  <input id="newPwInput" type="password" autocomplete="new-password" enterkeyhint="go"
                         placeholder="Choose a password" aria-describedby="pwHint" />
                  <button type="button" class="auth-pw-toggle" id="newPwToggle" aria-pressed="false" aria-label="Show password">Show</button>
                </span>
              </label>
              <div class="auth-pw-meter" id="pwMeter" aria-hidden="true"><span></span></div>
              <p class="auth-mini" id="pwHint">At least <?= (int) $pwMin ?> characters. You can change it anytime.</p>
              <button class="auth-btn" type="submit" id="setPwSubmit">
                <span class="auth-btn__label">Save password</span>
                <span class="auth-btn__spin" aria-hidden="true"></span>
              </button>
              <p class="auth-msg" id="setPwMsg" role="alert" aria-live="polite"></p>
              <p class="auth-mini auth-skip"><button type="button" class="auth-link-btn" id="skipPw">Skip for now</button></p>
            </form>
          </section>

        </div>

        <p class="auth-fine">A member with an <strong>@<?= e($orgDomain) ?></strong> address? Use <em>Continue with Google</em> or a sign-in code to reach mentorship and members-only spaces.</p>
      </div>
    </section>
  </main>

  <script src="/login/auth.js" defer></script>
</body>
</html>
