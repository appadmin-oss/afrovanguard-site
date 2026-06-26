<?php
/**
 * login/index.php — the standard Afrovanguard sign-in page.
 *
 * Passwordless-first: enter your email and we send a one-time code (works for
 * sign-in AND sign-up). A password is optional — you can add one right after
 * verifying, then use it next time. "Continue with Google" lights up once
 * AV_GOOGLE_CLIENT_ID is configured. WHICH methods appear and how strict the
 * security layers are is set by the superadmin (Studio → Sign-in → Security)
 * and read here from AuthPolicy. Honours ?next= (same-origin only).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

/* ---- where to send the visitor after sign-in (same-origin path only) ---- */
$next = (string) ($_GET['next'] ?? '');
if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, "\n")) {
    $next = '/portal/';
}

/* friendly messages for the OAuth round-trip (?e=…) */
$errorMap = [
    'google_off'        => 'Google sign-in isn’t set up yet — use your email below.',
    'google_failed'     => 'We couldn’t complete Google sign-in. Please try again, or use your email.',
    'google_cancelled'  => 'Google sign-in was cancelled.',
    'google_state'      => 'That sign-in link expired. Please try again.',
    'rate'              => 'Too many attempts — wait a moment and try again.',
];
$authError = $errorMap[(string) ($_GET['e'] ?? '')] ?? '';

/* already signed in → straight through */
if (LmsAuth::user()) { header('Location: ' . $next); exit; }

$illo          = av_auth_illustration();
$methods       = AuthPolicy::publicMethods();   // ['otp'=>bool,'password'=>bool,'google'=>bool]
$googleStart   = '/auth/google/start?next=' . rawurlencode($next);
$canonical     = rtrim(SITE_URL, '/') . '/login';

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
    <!-- Illustration aside (session-rotated; mirrors Afrostrength) -->
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
        <p class="auth-aside__foot">Est. 2018 · Alimosho, Lagos</p>
      </div>
    </aside>

    <!-- Form -->
    <section class="auth-main">
      <div class="auth-main__inner" data-next="<?= e($next) ?>" data-methods='<?= e(json_encode($methods)) ?>'>
        <a class="auth-back" href="<?= e(rtrim(SITE_URL, '/')) ?>/">← Back to site</a>
        <h1 class="auth-h" id="authH">Welcome</h1>
        <p class="auth-sub" id="authSub">Sign in or create your account — it takes a moment.</p>
<?php if ($authError): ?>        <p class="auth-banner" role="alert"><?= e($authError) ?></p>
<?php endif; ?>

<?php if ($methods['google']): ?>
        <a class="auth-google" id="authGoogle" href="<?= e($googleStart) ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1A11 11 0 0 0 2.18 7.06l3.66 2.84C6.71 7.3 9.14 5.38 12 5.38z"/></svg>
          <span>Continue with Google</span>
        </a>
        <div class="auth-or"><span>or</span></div>
<?php endif; ?>

        <!-- STEP 1 · identify -->
        <form class="auth-form" id="stepIdentify" novalidate>
          <label class="fld"><span>Email address</span>
            <input name="email" id="identEmailInput" type="email" required autocomplete="email" placeholder="you@example.com" />
          </label>
          <button class="btn btn-primary auth-submit" type="submit">Continue</button>
          <p class="auth-msg" id="identMsg" role="alert" aria-live="polite"></p>
        </form>

        <!-- STEP 2 · authenticate -->
        <div id="stepAuth" hidden>
          <p class="auth-ident">Signing in as <b id="identEmail"></b> · <button type="button" class="auth-link-btn" id="changeEmail">Change</button></p>

          <!-- code (passwordless) -->
          <form class="auth-method" id="codeForm" hidden>
            <p class="auth-mini" id="codeSent">We emailed a one-time code to your address. It expires shortly.</p>
            <label class="fld"><span>Sign-in code</span>
              <input id="codeInput" class="auth-code-input" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="••••••" />
            </label>
            <details class="auth-newname"><summary>First time here? Add your name</summary>
              <label class="fld"><span>Your name</span><input id="nameInput" type="text" autocomplete="name" placeholder="Your name" /></label>
            </details>
            <button class="btn btn-primary auth-submit" type="submit">Verify &amp; continue</button>
            <p class="auth-mini">Didn’t get it? <button type="button" class="auth-link-btn" id="resendCode">Resend code</button></p>
          </form>

          <div class="auth-or" id="methodOr" hidden><span>or use a password</span></div>

          <!-- password -->
          <form class="auth-method" id="passwordForm" hidden>
            <label class="fld"><span>Password</span>
              <input id="pwInput" type="password" autocomplete="current-password" placeholder="••••••••" />
            </label>
            <button class="btn btn-primary auth-submit" type="submit">Sign in</button>
          </form>

          <p class="auth-msg" id="authMsg" role="alert" aria-live="polite"></p>
        </div>

        <!-- STEP 3 · optional: add a password after verifying -->
        <form id="stepSetPw" hidden>
          <label class="fld"><span>New password</span>
            <input id="newPwInput" type="password" autocomplete="new-password" placeholder="Choose a password" />
          </label>
          <button class="btn btn-primary auth-submit" type="submit">Save password</button>
          <p class="auth-msg" id="setPwMsg" role="alert" aria-live="polite"></p>
          <p class="auth-mini"><button type="button" class="auth-link-btn" id="skipPw">Skip for now</button></p>
        </form>

        <p class="auth-fine">An <strong>@afrovanguard.org.ng</strong> member? Use <em>Continue with Google</em> or a sign-in code to reach mentorship and members-only spaces.</p>
      </div>
    </section>
  </main>

  <script>
  (function () {
    'use strict';
    var API     = '/academy/api.php';
    var inner   = document.querySelector('.auth-main__inner');
    var next    = inner.getAttribute('data-next') || '/portal/';
    var methods = (function () { try { return JSON.parse(inner.getAttribute('data-methods')); } catch (e) { return { otp: true, password: true, google: false }; } })();

    var stepIdentify = document.getElementById('stepIdentify');
    var stepAuth     = document.getElementById('stepAuth');
    var stepSetPw    = document.getElementById('stepSetPw');
    var emailInput   = document.getElementById('identEmailInput');
    var identMsg     = document.getElementById('identMsg');
    var identEmail   = document.getElementById('identEmail');
    var codeForm     = document.getElementById('codeForm');
    var codeInput    = document.getElementById('codeInput');
    var nameInput    = document.getElementById('nameInput');
    var passwordForm = document.getElementById('passwordForm');
    var pwInput      = document.getElementById('pwInput');
    var methodOr     = document.getElementById('methodOr');
    var authMsg      = document.getElementById('authMsg');
    var H            = document.getElementById('authH');
    var SUB          = document.getElementById('authSub');
    var email        = '';

    function post(action, payload) {
      return fetch(API + '?action=' + action, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload || {}), credentials: 'same-origin'
      }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected server response.' }; }); });
    }
    function setMsg(el, text, kind) { el.textContent = text || ''; el.className = 'auth-msg' + (kind ? ' ' + kind : ''); }
    function busy(form, on, label) {
      var b = form.querySelector('button[type=submit]');
      if (!b) return;
      if (on) { b.dataset.label = b.textContent; b.disabled = true; b.textContent = label || 'Please wait…'; }
      else { b.disabled = false; if (b.dataset.label) b.textContent = b.dataset.label; }
    }

    /* STEP 1 → choose method(s) */
    stepIdentify.addEventListener('submit', function (e) {
      e.preventDefault();
      email = emailInput.value.trim();
      if (!email || email.indexOf('@') < 1) { setMsg(identMsg, 'Enter a valid email address.', 'err'); return; }
      identEmail.textContent = email;
      H.textContent = 'Almost there'; SUB.textContent = 'Confirm it’s you to continue.';
      stepIdentify.hidden = true; stepAuth.hidden = false;
      var authGoogle = document.getElementById('authGoogle'); if (authGoogle) authGoogle.style.display = 'none';
      var or = document.querySelector('.auth-main__inner > .auth-or'); if (or) or.style.display = 'none';

      if (methods.password) { passwordForm.hidden = false; }
      if (methods.otp) {
        codeForm.hidden = false; methodOr.hidden = !methods.password;
        requestCode(true);
      } else if (methods.password) {
        pwInput.focus();
      }
    });

    document.getElementById('changeEmail').addEventListener('click', function () {
      stepAuth.hidden = true; stepSetPw.hidden = true; stepIdentify.hidden = false;
      H.textContent = 'Welcome'; SUB.textContent = 'Sign in or create your account — it takes a moment.';
      setMsg(authMsg, ''); emailInput.focus();
      var authGoogle = document.getElementById('authGoogle'); if (authGoogle) authGoogle.style.display = '';
      var or = document.querySelector('.auth-main__inner > .auth-or'); if (or) or.style.display = '';
    });

    /* request / resend a code */
    function requestCode(silent) {
      if (!silent) setMsg(authMsg, 'Sending a new code…');
      post('otp-request', { email: email }).then(function (d) {
        if (d && d.ok) { if (!silent) setMsg(authMsg, 'A fresh code is on its way.', 'ok'); if (codeInput) codeInput.focus(); }
        else setMsg(authMsg, (d && d.error) || 'Could not send a code right now.', 'err');
      }).catch(function () { setMsg(authMsg, 'Network error — try again.', 'err'); });
    }
    document.getElementById('resendCode').addEventListener('click', function () { requestCode(false); });

    /* verify code → sign in (or sign up) */
    codeForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var code = (codeInput.value || '').replace(/\D/g, '');
      if (code.length < 4) { setMsg(authMsg, 'Enter the code we emailed you.', 'err'); return; }
      busy(codeForm, true, 'Verifying…');
      post('otp-verify', { email: email, code: code, name: (nameInput.value || '').trim() }).then(function (d) {
        busy(codeForm, false);
        if (d && d.ok) { afterSignIn(d); return; }
        setMsg(authMsg, (d && d.error) || 'That code didn’t work.', 'err');
      }).catch(function () { busy(codeForm, false); setMsg(authMsg, 'Network error — try again.', 'err'); });
    });

    /* password sign-in */
    passwordForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var pw = pwInput.value;
      if (!pw) { setMsg(authMsg, 'Enter your password.', 'err'); return; }
      busy(passwordForm, true, 'Signing in…');
      post('login', { email: email, password: pw }).then(function (d) {
        busy(passwordForm, false);
        if (d && d.ok && !d.verify_required) { go(next); return; }
        if (d && d.verify_required) { setMsg(authMsg, d.error || d.message || 'Verify your email to continue.', 'err'); return; }
        if (d && d.use_otp) {            // password off / locked / org step-up → push to code
          setMsg(authMsg, d.error || 'Use a sign-in code instead.', 'err');
          if (methods.otp) { codeForm.hidden = false; requestCode(false); }
          return;
        }
        setMsg(authMsg, (d && d.error) || 'Could not sign in.', 'err');
      }).catch(function () { busy(passwordForm, false); setMsg(authMsg, 'Network error — try again.', 'err'); });
    });

    /* after a code sign-in: offer to set a password (the "password after OTP" path) */
    function afterSignIn(d) {
      if (d.user && d.has_password === false && methods.password) {
        stepAuth.hidden = true; stepSetPw.hidden = false;
        H.textContent = 'Add a password'; SUB.textContent = 'Optional — set one to sign in faster next time, or skip.';
        document.getElementById('newPwInput').focus();
      } else {
        go(next);
      }
    }
    stepSetPw.addEventListener('submit', function (e) {
      e.preventDefault();
      var pw = document.getElementById('newPwInput').value;
      busy(stepSetPw, true, 'Saving…');
      post('set-password', { password: pw }).then(function (d) {
        busy(stepSetPw, false);
        if (d && d.ok) { go(next); return; }
        setMsg(document.getElementById('setPwMsg'), (d && d.error) || 'Could not save that password.', 'err');
      }).catch(function () { busy(stepSetPw, false); setMsg(document.getElementById('setPwMsg'), 'Network error — try again.', 'err'); });
    });
    document.getElementById('skipPw').addEventListener('click', function () { go(next); });

    function go(url) { window.location.href = url; }

    emailInput.focus();
  })();
  </script>
</body>
</html>
