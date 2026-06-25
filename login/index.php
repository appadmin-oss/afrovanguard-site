<?php
/**
 * login/index.php — the standard Afrovanguard sign-in page.
 *
 * Replaces the old JS auth modal with a real, linkable page (served at
 * /login by virtue of being a real directory — WordPress never intercepts it).
 * Split layout: a session-rotated illustration aside (mirrors Afrostrength)
 * beside the form. Honours ?next= (same-origin only) for the post-login
 * redirect and ?mode=register to start on the create-account view.
 *
 * Sign-in methods: email + password (LmsAuth) now; "Continue with Google"
 * lights up automatically once AV_GOOGLE_CLIENT_ID is configured (Phase 2).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

/* ---- where to send the visitor after sign-in (same-origin path only) ---- */
$next = (string) ($_GET['next'] ?? '');
if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, "\n")) {
    $next = '/portal/';
}
$mode = (($_GET['mode'] ?? '') === 'register') ? 'register' : 'login';

/* friendly messages for the OAuth round-trip (?e=…) */
$errorMap = [
    'google_off'        => 'Google sign-in isn’t set up yet — please use your email below.',
    'google_failed'     => 'We couldn’t complete Google sign-in. Please try again, or use your email.',
    'google_cancelled'  => 'Google sign-in was cancelled.',
    'google_state'      => 'That sign-in link expired. Please try again.',
    'rate'              => 'Too many attempts — wait a moment and try again.',
];
$authError = $errorMap[(string) ($_GET['e'] ?? '')] ?? '';

/* already signed in → straight through */
if (LmsAuth::user()) { header('Location: ' . $next); exit; }

$illo          = av_auth_illustration();
$googleEnabled = GoogleAuth::configured();
$googleStart   = '/auth/google/start?next=' . rawurlencode($next);
$canonical     = rtrim(SITE_URL, '/') . '/login';

render_head([
    'title'      => ($mode === 'register' ? 'Create your account' : 'Sign in') . ' — Afrovanguard',
    'desc'       => 'Sign in to your Afrovanguard account to continue learning, track your progress, and reach members-only programmes and mentorship.',
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
      <div class="auth-main__inner" data-mode="<?= e($mode) ?>" data-next="<?= e($next) ?>">
        <a class="auth-back" href="<?= e(rtrim(SITE_URL, '/')) ?>/">← Back to site</a>
        <h1 class="auth-h" id="authH"><?= $mode === 'register' ? 'Create your account' : 'Welcome back' ?></h1>
        <p class="auth-sub" id="authSub"><?= $mode === 'register' ? 'Free to join — track your learning across the Academy.' : 'Sign in to your Afrovanguard account.' ?></p>
<?php if ($authError): ?>        <p class="auth-banner" role="alert"><?= e($authError) ?></p>
<?php endif; ?>
        <a class="auth-google<?= $googleEnabled ? '' : ' is-disabled' ?>" id="authGoogle"
           href="<?= $googleEnabled ? e($googleStart) : '#' ?>"<?= $googleEnabled ? '' : ' aria-disabled="true" title="Google sign-in is being set up"' ?>>
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1A11 11 0 0 0 2.18 7.06l3.66 2.84C6.71 7.3 9.14 5.38 12 5.38z"/></svg>
          <span>Continue with Google</span>
        </a>
<?php if (!$googleEnabled): ?>        <p class="auth-google-note">Google sign-in is being set up — use your email below for now.</p>
<?php endif; ?>
        <div class="auth-or"><span>or</span></div>

        <form id="authForm" novalidate>
          <label class="fld fld-name" id="fldName"><span>Full name</span>
            <input name="name" type="text" autocomplete="name" placeholder="Your name" />
          </label>
          <label class="fld"><span>Email address</span>
            <input name="email" type="email" required autocomplete="email" placeholder="you@example.com" />
          </label>
          <label class="fld"><span>Password</span>
            <input name="password" type="password" required minlength="8" autocomplete="current-password" placeholder="••••••••" />
          </label>
          <button class="btn btn-primary auth-submit" type="submit" id="authSubmit"><?= $mode === 'register' ? 'Create account' : 'Sign in' ?></button>
          <p class="auth-msg" id="authMsg" role="alert" aria-live="polite"></p>
        </form>

        <p class="auth-switch" id="authSwitch"></p>
        <p class="auth-fine">An <strong>@afrovanguard.org.ng</strong> member? Use <em>Continue with Google</em> to reach mentorship and members-only spaces.</p>
      </div>
    </section>
  </main>

  <script>
  (function () {
    'use strict';
    var inner  = document.querySelector('.auth-main__inner');
    var next   = inner.getAttribute('data-next') || '/academy/';
    var mode   = inner.getAttribute('data-mode') === 'register' ? 'register' : 'login';
    var form   = document.getElementById('authForm');
    var msg    = document.getElementById('authMsg');
    var submit = document.getElementById('authSubmit');
    var fldName = document.getElementById('fldName');
    var nameInput = form.querySelector('[name=name]');

    function render() {
      var reg = mode === 'register';
      document.getElementById('authH').textContent   = reg ? 'Create your account' : 'Welcome back';
      document.getElementById('authSub').textContent = reg ? 'Free to join — track your learning across the Academy.' : 'Sign in to your Afrovanguard account.';
      submit.textContent = reg ? 'Create account' : 'Sign in';
      fldName.style.display = reg ? '' : 'none';
      nameInput.required = reg;
      form.querySelector('[name=password]').setAttribute('autocomplete', reg ? 'new-password' : 'current-password');
      document.getElementById('authSwitch').innerHTML = reg
        ? 'Already have an account? <a href="#" data-to="login">Sign in</a>'
        : 'New to Afrovanguard? <a href="#" data-to="register">Create an account</a>';
      msg.textContent = ''; msg.className = 'auth-msg';
      try { history.replaceState(null, '', reg ? '?mode=register' : location.pathname + (next !== '/academy/' ? '?next=' + encodeURIComponent(next) : '')); } catch (e) {}
    }
    document.getElementById('authSwitch').addEventListener('click', function (e) {
      var a = e.target.closest('[data-to]'); if (!a) return;
      e.preventDefault(); mode = a.getAttribute('data-to'); render();
    });

    // Email-verification notice + a one-tap "resend the link" affordance.
    function showVerifyNotice(text) {
      msg.className = 'auth-msg'; msg.innerHTML = '';
      var span = document.createElement('span'); span.textContent = text + ' ';
      var btn = document.createElement('button');
      btn.type = 'button'; btn.textContent = 'Resend link';
      btn.style.cssText = 'background:none;border:0;color:#a8821a;font:inherit;font-weight:700;text-decoration:underline;cursor:pointer;padding:0';
      btn.addEventListener('click', function () {
        var email = form.email.value.trim();
        if (!email) { msg.className = 'auth-msg err'; msg.textContent = 'Enter your email above, then tap Resend.'; return; }
        btn.disabled = true; btn.textContent = 'Sending…';
        fetch('/academy/api.php?action=resend-verification', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: email }), credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (x) {
          msg.className = 'auth-msg'; msg.textContent = (x && x.message) || 'If that account needs verifying, a new link is on its way.';
        }).catch(function () { btn.disabled = false; btn.textContent = 'Resend link'; msg.className = 'auth-msg err'; msg.textContent = 'Could not resend right now — try again.'; });
      });
      msg.appendChild(span); msg.appendChild(btn);
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var payload = { email: form.email.value.trim(), password: form.password.value };
      if (mode === 'register') payload.name = nameInput.value.trim();
      submit.disabled = true; var label = submit.textContent; submit.textContent = 'Please wait…';
      fetch('/academy/api.php?action=' + mode, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload), credentials: 'same-origin'
      }).then(function (r) { return r.json(); }).then(function (d) {
        // A verified, logged-in success continues; verification responses carry
        // verify_required (register → ok:true, login → ok:false) — show the notice.
        if (d && d.ok && !d.verify_required) { window.location.href = next; return; }
        submit.disabled = false; submit.textContent = label;
        if (d && d.verify_required) { showVerifyNotice(d.message || d.error || 'Please verify your email to continue.'); return; }
        msg.className = 'auth-msg err'; msg.textContent = (d && d.error) || 'Something went wrong. Please try again.';
      }).catch(function () {
        msg.className = 'auth-msg err'; msg.textContent = 'Network error — please try again.';
        submit.disabled = false; submit.textContent = label;
      });
    });

    render();
    // If they arrived from an expired/invalid verification link, prompt a resend.
    try { if (/[?&]verify_error=1/.test(location.search)) showVerifyNotice('That verification link was invalid or has expired. Enter your email and request a new one.'); } catch (e) {}
  })();
  </script>
</body>
</html>
