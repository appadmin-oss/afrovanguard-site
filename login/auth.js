/* ============================================================
   login/auth.js — the progressive Afrovanguard sign-in flow.

   Three steps, one panel visible at a time, animated transitions:
     1 Identify  → enter email, continue
     2 Verify    → emailed one-time code (segmented inputs) OR password
     3 Secure    → optionally set a password after a code sign-in

   Talks to /academy/api.php (same-origin JSON). It NEVER probes whether an
   account exists (the API is enumeration-safe by design): a correct code both
   signs in AND signs up, so the same screen serves new and returning members.
   Dependency-free, no build step. Degrades to a usable form without JS-niceties.
   ============================================================ */
(function () {
  'use strict';

  var API  = '/academy/api.php';
  var card = document.getElementById('authCard');
  if (!card) return;

  var cfg = (function () {
    try { return JSON.parse(card.getAttribute('data-cfg')) || {}; }
    catch (e) { return {}; }
  })();
  var next    = (cfg.next && cfg.next.charAt(0) === '/') ? cfg.next : '/portal/';
  var methods = cfg.methods || { otp: true, password: true, google: false };
  var OTP_LEN = Math.max(4, Math.min(8, parseInt(cfg.otpLen, 10) || 6));
  var PW_MIN  = Math.max(6, parseInt(cfg.pwMin, 10) || 8);

  /* ---- element refs ---- */
  var $ = function (id) { return document.getElementById(id); };
  var steps      = $('authSteps');
  var stage      = $('authStage');
  var H          = $('authH');
  var SUB        = $('authSub');
  var banner     = $('authBanner');

  var panelIdentify = $('panelIdentify');
  var panelAuth     = $('panelAuth');
  var panelSecure   = $('panelSecure');

  var formIdentify  = $('formIdentify');
  var emailInput    = $('identEmailInput');
  var identMsg      = $('identMsg');

  var identEmail    = $('identEmail');
  var formCode      = $('formCode');
  var codeInput     = $('codeInput');
  var otpBoxes      = $('otpBoxes');
  var newNameWrap   = $('newNameWrap');
  var nameInput     = $('nameInput');
  var resendBtn     = $('resendCode');
  var resendWait    = $('resendWait');
  var methodToggle  = $('methodToggle');
  var toPasswordBtn = $('toPassword');
  var formPassword  = $('formPassword');
  var pwInput       = $('pwInput');
  var toCodeWrap    = $('toCodeWrap');
  var toCodeBtn     = $('toCode');
  var authMsg       = $('authMsg');

  var formSetPw     = $('formSetPw');
  var newPwInput    = $('newPwInput');
  var pwMeter       = $('pwMeter');
  var setPwMsg      = $('setPwMsg');

  var email = '';
  var currentStep = 1;
  var codeRequested = false;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- tiny helpers ---- */
  function post(action, payload) {
    return fetch(API + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload || {}),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Unexpected server response.' }; });
    });
  }
  function setMsg(el, text, kind) {
    if (!el) return;
    el.textContent = text || '';
    el.className = 'auth-msg' + (kind ? ' ' + kind : '');
  }
  function busy(btn, on) {
    if (!btn) return;
    btn.classList.toggle('is-busy', !!on);
    btn.disabled = !!on;
  }
  function go(url) { window.location.assign(url); }
  function validEmail(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }

  /* ---- step indicator + animated panel transitions ---- */
  function markSteps(n) {
    if (!steps) return;
    Array.prototype.forEach.call(steps.children, function (li) {
      var s = parseInt(li.getAttribute('data-step'), 10);
      li.classList.toggle('is-active', s === n);
      li.classList.toggle('is-done', s < n);
    });
  }

  function show(panel, opts) {
    opts = opts || {};
    var from = stage.querySelector('.auth-panel.is-current');
    if (from === panel) { return; }
    var back = !!opts.back;

    panel.hidden = false;
    if (reduce) {
      if (from) { from.classList.remove('is-current'); from.hidden = true; }
      panel.classList.add('is-current');
      if (opts.focus) try { opts.focus.focus(); } catch (e) {}
      return;
    }

    // fade/slide the outgoing panel away, then the incoming one in.
    panel.classList.add(back ? 'enter-back' : 'enter');
    if (from) {
      from.classList.add(back ? 'leave-back' : 'leave');
      from.classList.remove('is-current');
      var done = function () {
        from.classList.remove('leave', 'leave-back');
        from.hidden = true;
        from.removeEventListener('transitionend', done);
      };
      from.addEventListener('transitionend', done);
      // safety net if transitionend doesn't fire
      setTimeout(done, 320);
    }
    // next frame → trigger the enter transition
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        panel.classList.remove('enter', 'enter-back');
        panel.classList.add('is-current');
      });
    });
    if (opts.focus) {
      setTimeout(function () { try { opts.focus.focus(); } catch (e) {} }, 180);
    }
  }

  function head(title, sub) {
    if (H) H.textContent = title;
    if (SUB) SUB.textContent = sub;
  }
  function hideBanner() { if (banner) banner.hidden = true; }

  /* ============================================================
     STEP 1 · identify
     ============================================================ */
  formIdentify.addEventListener('submit', function (e) {
    e.preventDefault();
    var v = (emailInput.value || '').trim().toLowerCase();
    if (!validEmail(v)) {
      setMsg(identMsg, 'Enter a valid email address.', 'err');
      emailInput.focus();
      return;
    }
    setMsg(identMsg, '');
    email = v;
    identEmail.textContent = email;
    enterStepTwo();
  });

  function enterStepTwo() {
    hideBanner();
    currentStep = 2;
    markSteps(2);
    head('Confirm it’s you', 'Enter the code we just emailed, or use your password.');

    // Reset method visibility for this email.
    setMsg(authMsg, '');
    formCode.hidden = !methods.otp;
    formPassword.hidden = true;
    methodToggle.hidden = true;
    toCodeWrap.hidden = true;

    if (methods.otp) {
      // Primary path: passwordless code.
      methodToggle.hidden = !methods.password;       // offer the password alternative
      buildOtpBoxes();
      requestCode(true);
      show(panelAuth, { focus: otpBoxes.querySelector('input') });
    } else if (methods.password) {
      // Password-only deployment.
      formPassword.hidden = false;
      head('Welcome back', 'Enter your password to continue.');
      show(panelAuth, { focus: pwInput });
    } else {
      // Nothing enabled (shouldn't happen — policy guarantees one) → Google only.
      show(panelAuth);
    }
  }

  $('changeEmail').addEventListener('click', function () {
    currentStep = 1;
    markSteps(1);
    head('Welcome', 'Sign in or create your account — it only takes a moment.');
    setMsg(authMsg, '');
    codeRequested = false;
    show(panelIdentify, { back: true, focus: emailInput });
  });

  /* ============================================================
     STEP 2 · code (passwordless)
     ============================================================ */
  function buildOtpBoxes() {
    if (otpBoxes.childElementCount === OTP_LEN) return; // already built
    otpBoxes.innerHTML = '';
    for (var i = 0; i < OTP_LEN; i++) {
      var inp = document.createElement('input');
      inp.type = 'text';
      inp.className = 'auth-otp__box';
      inp.inputMode = 'numeric';
      inp.maxLength = 1;
      inp.setAttribute('aria-label', 'Digit ' + (i + 1));
      inp.autocomplete = i === 0 ? 'one-time-code' : 'off';
      otpBoxes.appendChild(inp);
    }
    wireOtp();
  }

  function otpEls() { return Array.prototype.slice.call(otpBoxes.querySelectorAll('.auth-otp__box')); }

  function syncOtpHidden() {
    codeInput.value = otpEls().map(function (b) { return b.value; }).join('').replace(/\D/g, '');
  }

  function fillOtp(digits) {
    var els = otpEls();
    digits = (digits || '').replace(/\D/g, '').slice(0, OTP_LEN).split('');
    els.forEach(function (b, i) { b.value = digits[i] || ''; });
    syncOtpHidden();
    var firstEmpty = els.find(function (b) { return !b.value; });
    (firstEmpty || els[els.length - 1]).focus();
    maybeAutoVerify();
  }

  function maybeAutoVerify() {
    if (codeInput.value.length === OTP_LEN) { verifyCode(); }
  }

  function wireOtp() {
    var els = otpEls();
    els.forEach(function (box, idx) {
      box.addEventListener('input', function () {
        // keep only the last typed digit
        var d = (box.value || '').replace(/\D/g, '');
        box.value = d ? d.charAt(d.length - 1) : '';
        syncOtpHidden();
        setMsg(authMsg, '');
        if (box.value && idx < els.length - 1) els[idx + 1].focus();
        maybeAutoVerify();
      });
      box.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && !box.value && idx > 0) { els[idx - 1].focus(); els[idx - 1].value = ''; syncOtpHidden(); e.preventDefault(); }
        else if (e.key === 'ArrowLeft' && idx > 0) { els[idx - 1].focus(); e.preventDefault(); }
        else if (e.key === 'ArrowRight' && idx < els.length - 1) { els[idx + 1].focus(); e.preventDefault(); }
      });
      box.addEventListener('paste', function (e) {
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text');
        fillOtp(text);
      });
      box.addEventListener('focus', function () { box.select(); });
    });
    // some password managers / OS autofill drop the whole code into the hidden field
    codeInput.addEventListener('input', function () {
      if (codeInput.value) { fillOtp(codeInput.value); }
    });
  }

  /* request / resend a code (enumeration-safe; we always proceed) */
  var resendTimer = null;
  function startResendCooldown() {
    var left = 30;
    resendBtn.hidden = true;
    resendWait.hidden = false;
    var tick = function () {
      resendWait.textContent = ' You can resend in ' + left + 's';
      if (left <= 0) {
        clearInterval(resendTimer);
        resendWait.hidden = true;
        resendBtn.hidden = false;
        return;
      }
      left -= 1;
    };
    tick();
    clearInterval(resendTimer);
    resendTimer = setInterval(tick, 1000);
  }

  function requestCode(silent) {
    if (!silent) setMsg(authMsg, 'Sending a fresh code…');
    codeRequested = true;
    startResendCooldown();
    post('otp-request', { email: email }).then(function (d) {
      if (d && d.ok) {
        if (!silent) setMsg(authMsg, 'A new code is on its way.', 'ok');
      } else {
        setMsg(authMsg, (d && d.error) || 'Could not send a code right now.', 'err');
      }
    }).catch(function () { setMsg(authMsg, 'Network error — please try again.', 'err'); });
  }
  resendBtn.addEventListener('click', function () { requestCode(false); });

  function verifyCode() {
    syncOtpHidden();
    var code = codeInput.value;
    if (code.length < Math.min(4, OTP_LEN)) {
      setMsg(authMsg, 'Enter the code we emailed you.', 'err');
      return;
    }
    busy($('codeSubmit'), true);
    post('otp-verify', { email: email, code: code, name: (nameInput.value || '').trim() }).then(function (d) {
      busy($('codeSubmit'), false);
      if (d && d.ok) { afterSignIn(d); return; }
      setMsg(authMsg, (d && d.error) || 'That code didn’t work. Request a new one.', 'err');
      fillOtp('');
      var first = otpBoxes.querySelector('input'); if (first) first.focus();
    }).catch(function () {
      busy($('codeSubmit'), false);
      setMsg(authMsg, 'Network error — please try again.', 'err');
    });
  }
  formCode.addEventListener('submit', function (e) { e.preventDefault(); verifyCode(); });

  /* ============================================================
     STEP 2 · password
     ============================================================ */
  function showPasswordMethod() {
    formCode.hidden = true;
    methodToggle.hidden = true;
    formPassword.hidden = false;
    toCodeWrap.hidden = !methods.otp;
    head('Welcome back', 'Enter your password to continue.');
    setMsg(authMsg, '');
    setTimeout(function () { pwInput.focus(); }, 60);
  }
  function showCodeMethod() {
    formPassword.hidden = true;
    formCode.hidden = false;
    methodToggle.hidden = !methods.password;
    head('Confirm it’s you', 'Enter the code we just emailed.');
    setMsg(authMsg, '');
    if (!codeRequested) { buildOtpBoxes(); requestCode(false); }
    var first = otpBoxes.querySelector('input'); if (first) setTimeout(function () { first.focus(); }, 60);
  }
  if (toPasswordBtn) toPasswordBtn.addEventListener('click', showPasswordMethod);
  if (toCodeBtn) toCodeBtn.addEventListener('click', showCodeMethod);

  formPassword.addEventListener('submit', function (e) {
    e.preventDefault();
    var pw = pwInput.value;
    if (!pw) { setMsg(authMsg, 'Enter your password.', 'err'); pwInput.focus(); return; }
    busy($('pwSubmit'), true);
    post('login', { email: email, password: pw }).then(function (d) {
      busy($('pwSubmit'), false);
      if (d && d.ok && !d.verify_required) { go(next); return; }
      if (d && d.verify_required) {
        // unverified password account → send a fresh verification link
        setMsg(authMsg, d.error || d.message || 'Please verify your email to continue. We can resend the link.', 'err');
        post('resend-verification', { email: email }).catch(function () {});
        return;
      }
      if (d && d.use_otp) {
        // password off / locked / org step-up → push to the code path
        setMsg(authMsg, d.error || 'Use a sign-in code instead.', 'err');
        if (methods.otp) { showCodeMethod(); }
        return;
      }
      setMsg(authMsg, (d && d.error) || 'Could not sign in.', 'err');
    }).catch(function () {
      busy($('pwSubmit'), false);
      setMsg(authMsg, 'Network error — please try again.', 'err');
    });
  });

  /* ============================================================
     STEP 3 · optional: add a password after a code sign-in
     ============================================================ */
  function afterSignIn(d) {
    if (d.user && d.has_password === false && methods.password) {
      currentStep = 3;
      markSteps(3);
      head('Secure your account', 'Optional — add a password to sign in faster next time, or skip.');
      show(panelSecure, { focus: newPwInput });
    } else {
      markSteps(3);
      go(next);
    }
  }

  if (newPwInput) {
    newPwInput.addEventListener('input', function () {
      var v = newPwInput.value;
      var score = 0;
      if (v.length >= PW_MIN) score++;
      if (/[A-Za-z]/.test(v) && /\d/.test(v)) score++;
      if (v.length >= PW_MIN + 4 && /[^A-Za-z0-9]/.test(v)) score++;
      pwMeter.setAttribute('data-score', String(v ? score + 1 : 0));
    });
  }

  formSetPw.addEventListener('submit', function (e) {
    e.preventDefault();
    var pw = newPwInput.value;
    if (pw.length < PW_MIN) { setMsg(setPwMsg, 'Use at least ' + PW_MIN + ' characters.', 'err'); newPwInput.focus(); return; }
    busy($('setPwSubmit'), true);
    post('set-password', { password: pw }).then(function (d) {
      busy($('setPwSubmit'), false);
      if (d && d.ok) { go(next); return; }
      setMsg(setPwMsg, (d && d.error) || 'Could not save that password.', 'err');
    }).catch(function () {
      busy($('setPwSubmit'), false);
      setMsg(setPwMsg, 'Network error — please try again.', 'err');
    });
  });
  $('skipPw').addEventListener('click', function () { go(next); });

  /* ---- show/hide password toggles ---- */
  function wireToggle(btnId, inputEl) {
    var btn = $(btnId);
    if (!btn || !inputEl) return;
    btn.addEventListener('click', function () {
      var show = inputEl.type === 'password';
      inputEl.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      inputEl.focus();
    });
  }
  wireToggle('pwToggle', pwInput);
  wireToggle('newPwToggle', newPwInput);

  /* ---- boot ---- */
  markSteps(1);
  // Autofocus the email field unless the browser has restored a value.
  setTimeout(function () { try { emailInput.focus(); } catch (e) {} }, 40);
})();
