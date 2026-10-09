/* Sign in (/login). Prefix avsi-. Vanilla, no dependencies.
   Steps: 1 email → 2 code (or password) → 3 optional password.
   Talks to /academy/api.php (same-origin JSON), which holds every server check:
   same-origin, rate limits, policy, and steering org addresses to Google.
   Enumeration-safe: it never asks whether an account exists; a correct code
   both signs in and signs up. */
(() => {
  'use strict';
  const card = document.querySelector('[data-avsi]');
  if (!card) return;
  const API = '/academy/api.php';
  const q = s => card.querySelector(s);
  const qa = s => Array.from(card.querySelectorAll(s));

  let cfg = {};
  try { cfg = JSON.parse(card.getAttribute('data-cfg')) || {}; } catch (e) { cfg = {}; }
  // Same-origin path only; the server already validated it, this is belt and braces.
  const next = (typeof cfg.next === 'string' && cfg.next.charAt(0) === '/' && cfg.next.charAt(1) !== '/' && cfg.next.charAt(1) !== '\\') ? cfg.next : '/portal/';
  const methods = cfg.methods || { otp: true, password: true, google: false };
  const OTP_LEN = Math.max(4, Math.min(8, parseInt(cfg.otpLen, 10) || 6));
  const PW_MIN = Math.max(6, parseInt(cfg.pwMin, 10) || 8);

  const H = q('[data-avsi-h]'), SUB = q('[data-avsi-sub]'), banner = q('[data-avsi-banner]');
  const steps = qa('[data-avsi-steps] li');
  const panes = { 1: q('[data-avsi-step="1"]'), 2: q('[data-avsi-step="2"]'), 3: q('[data-avsi-step="3"]') };
  const emailForm = q('[data-avsi-email-form]'), emailIn = q('#avsi-email'), emailErr = q('[data-avsi-email-err]'), contBtn = q('[data-avsi-cont]');
  const who = q('[data-avsi-who]'), msgs = { code: q('[data-avsi-msg="code"]'), pw: q('[data-avsi-msg="pw"]') };
  const codeForm = q('[data-avsi-code-form]'), otp = q('[data-avsi-otp]'), codeHidden = q('[data-avsi-code]'), nameIn = q('[data-avsi-name]');
  const verifyBtn = q('[data-avsi-verify]'), resendBtn = q('[data-avsi-resend]');
  const pwForm = q('[data-avsi-pw-form]'), pwIn = q('#avsi-pw'), pwBtn = q('[data-avsi-pwsubmit]');
  const swapBtn = q('[data-avsi-swap]');
  const setForm = q('[data-avsi-setpw-form]'), npwIn = q('#avsi-npw'), meter = q('[data-avsi-meter]'), meterLabel = q('[data-avsi-meterlabel]'), setMsg = q('[data-avsi-setpw-msg]'), setBtn = q('[data-avsi-setpw]');

  const HEADS = {
    1: ['Welcome', 'Sign in or create your account — it only takes a moment.'],
    code: ['Check your email', 'Enter the ' + OTP_LEN + '-digit code we just sent.'],
    pw: ['Check your email', 'Enter your password to continue.'],
    3: ['Secure your account', 'Add a password so you can sign in without a code next time. Optional.']
  };

  let email = '', method = methods.otp ? 'code' : 'pw', codeRequested = false, waitT = 0;
  // Step 2 messages sit under the active method's field.
  const msg = { get el() { return msgs[method] || msgs.code || msgs.pw; } };

  /* ---- helpers ---- */
  const validEmail = v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
  const isOrg = v => {
    const at = String(v || '').lastIndexOf('@');
    return !!cfg.googleOn && !!cfg.orgDomain && at >= 0 && v.slice(at + 1).toLowerCase() === String(cfg.orgDomain).toLowerCase();
  };
  const goGoogle = hint => {
    location.href = '/auth/google/start?next=' + encodeURIComponent(next) + (hint ? '&hint=' + encodeURIComponent(hint) : '');
  };
  const go = url => location.assign(url);
  const say = (el, text, kind) => { if (el === msg) el = msg.el; if (!el) return; el.textContent = text || ''; el.classList.toggle('is-err', kind === 'err'); el.classList.toggle('is-ok', kind === 'ok'); };
  const busy = (btn, on) => { if (!btn) return; btn.disabled = !!on; btn.classList.toggle('is-busy', !!on); btn.setAttribute('aria-busy', on ? 'true' : 'false'); };
  const post = (action, payload) => fetch(API + '?action=' + encodeURIComponent(action), {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload || {})
  }).then(r => r.json().catch(() => ({ ok: false, error: 'Unexpected server response.' }))).then(d => {
    // Server guard: org accounts are steered to Google.
    if (d && d.google) { goGoogle(d.hint); return new Promise(() => {}); }
    return d;
  });
  const focusSoon = el => { if (el) setTimeout(() => { try { el.focus(); } catch (e) {} }, 30); };

  function head(key) { const h = HEADS[key]; if (H) H.textContent = h[0]; if (SUB) SUB.textContent = h[1]; }
  function show(n) {
    Object.keys(panes).forEach(k => { if (panes[k]) panes[k].hidden = +k !== n; });
    steps.forEach(li => {
      const s = +li.dataset.step;
      li.classList.toggle('is-on', s <= n);
      if (s === n) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
    });
    if (banner && n > 1) banner.hidden = true;
  }

  /* ---- step 1 ---- */
  if (emailIn && contBtn) emailIn.addEventListener('input', () => {
    contBtn.textContent = isOrg(emailIn.value.trim()) ? 'Continue with Google' : 'Continue';
    emailIn.classList.remove('is-bad'); emailIn.removeAttribute('aria-invalid'); say(emailErr, '');
  });
  if (emailForm) emailForm.addEventListener('submit', e => {
    e.preventDefault();
    const v = (emailIn.value || '').trim().toLowerCase();
    if (!validEmail(v)) {
      say(emailErr, 'Enter a valid email address.');
      emailIn.classList.add('is-bad'); emailIn.setAttribute('aria-invalid', 'true'); emailIn.focus();
      return;
    }
    email = v;
    if (isOrg(v)) { busy(contBtn, true); goGoogle(v); return; }
    if (who) who.textContent = email;
    enterVerify();
  });

  function enterVerify() {
    say(msg, '');
    show(2);
    if (methods.otp && codeForm) {
      setMethod('code');
      clearCode(); requestCode(true);
      focusSoon(boxes()[0]);
    } else if (methods.password && pwForm) {
      setMethod('pw');
      focusSoon(pwIn);
    } else {
      // Policy guarantees one method; if none, only Google is left.
      head(1);
    }
  }
  const changeBtn = q('[data-avsi-change]');
  if (changeBtn) changeBtn.addEventListener('click', () => {
    codeRequested = false; say(msg, ''); head(1); show(1); focusSoon(emailIn);
  });

  /* ---- step 2 · method swap ---- */
  function setMethod(m) {
    method = m;
    Object.values(msgs).forEach(el => { if (el) el.textContent = ''; });
    if (codeForm) codeForm.hidden = m !== 'code';
    if (pwForm) pwForm.hidden = m !== 'pw';
    if (swapBtn) swapBtn.textContent = m === 'code' ? 'Use a password instead' : 'Email me a code instead';
    head(m);
  }
  if (swapBtn) swapBtn.addEventListener('click', () => {
    say(msg, '');
    if (method === 'code') { setMethod('pw'); focusSoon(pwIn); }
    else { setMethod('code'); if (!codeRequested) requestCode(false); focusSoon(boxes()[0]); }
  });

  /* ---- step 2 · code boxes ---- */
  const boxes = () => (otp ? Array.from(otp.querySelectorAll('.avsi-box')) : []);
  const code = () => boxes().map(b => b.value).join('').replace(/\D/g, '');
  function paint() {
    boxes().forEach(b => b.classList.toggle('is-filled', !!b.value));
    if (codeHidden) codeHidden.value = code();
    if (verifyBtn) verifyBtn.classList.toggle('is-dim', code().length !== OTP_LEN);
  }
  function bad(on) { if (otp) otp.classList.toggle('is-bad', !!on); }
  function clearCode() { boxes().forEach(b => { b.value = ''; }); bad(false); paint(); }
  function fill(digits) {
    const d = String(digits || '').replace(/\D/g, '').slice(0, OTP_LEN).split('');
    const els = boxes();
    els.forEach((b, i) => { b.value = d[i] || ''; });
    bad(false); paint();
    const nx = els[Math.min(OTP_LEN - 1, d.length)];
    if (nx) nx.focus();
    if (code().length === OTP_LEN) verify();
  }
  boxes().forEach((box, i, els) => {
    box.addEventListener('input', () => {
      const d = (box.value || '').replace(/\D/g, '');
      if (d.length > 1) { fill(code().slice(0, i) + d); return; }
      box.value = d;
      bad(false); say(msg, ''); paint();
      if (d && els[i + 1]) els[i + 1].focus();
      if (code().length === OTP_LEN) verify();
    });
    box.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !box.value && els[i - 1]) { e.preventDefault(); els[i - 1].value = ''; els[i - 1].focus(); paint(); }
      else if (e.key === 'ArrowLeft' && els[i - 1]) { e.preventDefault(); els[i - 1].focus(); }
      else if (e.key === 'ArrowRight' && els[i + 1]) { e.preventDefault(); els[i + 1].focus(); }
    });
    box.addEventListener('paste', e => {
      const t = ((e.clipboardData || window.clipboardData).getData('text') || '').replace(/\D/g, '');
      if (!t) return;
      e.preventDefault(); fill(t);
    });
    box.addEventListener('focus', () => box.select());
  });
  // Some autofill drops the whole code into the hidden one-time-code field.
  if (codeHidden) codeHidden.addEventListener('input', () => { if (codeHidden.value) fill(codeHidden.value); });

  /* request / resend (enumeration-safe: always proceeds) */
  let waitTimer = 0;
  function cooldown() {
    if (!resendBtn) return;
    waitT = 30;
    const tick = () => {
      resendBtn.disabled = waitT > 0;
      resendBtn.textContent = waitT > 0 ? 'Resend in ' + waitT + 's' : 'Resend code';
      if (waitT <= 0) { clearInterval(waitTimer); return; }
      waitT -= 1;
    };
    clearInterval(waitTimer); tick(); waitTimer = setInterval(tick, 1000);
  }
  function requestCode(silent) {
    codeRequested = true;
    cooldown();
    if (!silent) say(msg, 'Sending a fresh code…');
    post('otp-request', { email }).then(d => {
      if (d && d.ok) { if (!silent) say(msg, 'A new code is on its way.', 'ok'); }
      else say(msg, (d && d.error) || 'Could not send a code right now.', 'err');
    }).catch(() => say(msg, 'Network error — please try again.', 'err'));
  }
  if (resendBtn) resendBtn.addEventListener('click', () => { if (waitT <= 0) requestCode(false); });

  let verifying = false;
  function verify() {
    const c = code();
    if (verifying) return;
    if (c.length < OTP_LEN) { say(msg, 'Enter the code we emailed you.', 'err'); const f = boxes().find(b => !b.value); if (f) f.focus(); return; }
    verifying = true; busy(verifyBtn, true); say(msg, '');
    post('otp-verify', { email, code: c, name: ((nameIn && nameIn.value) || '').trim() }).then(d => {
      verifying = false; busy(verifyBtn, false);
      if (d && d.ok) { afterSignIn(d); return; }
      bad(true);
      say(msg, (d && d.error) || 'That code doesn’t match. Check the latest email and try again.', 'err');
      const f = boxes()[0]; if (f) f.focus();
    }).catch(() => { verifying = false; busy(verifyBtn, false); say(msg, 'Network error — please try again.', 'err'); });
  }
  if (codeForm) codeForm.addEventListener('submit', e => { e.preventDefault(); verify(); });

  /* ---- step 2 · password ---- */
  if (pwForm) pwForm.addEventListener('submit', e => {
    e.preventDefault();
    const pw = pwIn.value;
    if (!pw) { say(msg, 'Enter your password.', 'err'); pwIn.focus(); return; }
    busy(pwBtn, true);
    post('login', { email, password: pw }).then(d => {
      busy(pwBtn, false);
      if (d && d.ok && !d.verify_required) { go(next); return; }
      if (d && d.verify_required) {
        // Unverified password account: send a fresh verification link.
        say(msg, d.error || d.message || 'Please verify your email to continue. We can resend the link.', 'err');
        post('resend-verification', { email }).catch(() => {});
        return;
      }
      if (d && d.use_otp) {
        // Password off, locked, or org step-up: push to the code path.
        if (methods.otp && codeForm) { setMethod('code'); if (!codeRequested) requestCode(true); focusSoon(boxes()[0]); }
        say(msg, d.error || 'Use a sign-in code instead.', 'err');
        return;
      }
      say(msg, (d && d.error) || 'Could not sign in.', 'err');
    }).catch(() => { busy(pwBtn, false); say(msg, 'Network error — please try again.', 'err'); });
  });

  /* ---- step 3 · optional password ---- */
  function afterSignIn(d) {
    if (d.user && d.has_password === false && methods.password && setForm) {
      head(3); show(3); focusSoon(npwIn);
    } else {
      go(next);
    }
  }
  function score(pw) {
    // Ported from the design: length, case, digit, symbol, long; short passwords lose a point.
    if (!pw) return 0;
    const s = (pw.length >= PW_MIN) + /[A-Z]/.test(pw) + /\d/.test(pw) + /[^A-Za-z0-9]/.test(pw) + (pw.length >= 12 ? 1 : 0) - (pw.length < PW_MIN ? 1 : 0);
    return Math.max(0, Math.min(4, s));
  }
  if (npwIn) npwIn.addEventListener('input', () => {
    const pw = npwIn.value, s = score(pw);
    if (meter) meter.setAttribute('data-score', String(pw ? s : 0));
    if (meterLabel) meterLabel.textContent = pw ? ['Too short', 'Weak', 'Fair', 'Good', 'Strong'][s] : 'Choose something memorable';
    say(setMsg, '');
  });
  if (setForm) setForm.addEventListener('submit', e => {
    e.preventDefault();
    const pw = npwIn.value;
    if (pw.length < PW_MIN) { say(setMsg, 'Use at least ' + PW_MIN + ' characters.', 'err'); npwIn.focus(); return; }
    busy(setBtn, true);
    post('set-password', { password: pw }).then(d => {
      busy(setBtn, false);
      if (d && d.ok) { go(next); return; }
      say(setMsg, (d && d.error) || 'Could not save that password.', 'err');
    }).catch(() => { busy(setBtn, false); say(setMsg, 'Network error — please try again.', 'err'); });
  });

  /* ---- show / hide password ---- */
  qa('[data-avsi-pwtoggle]').forEach(btn => {
    const input = document.getElementById(btn.getAttribute('data-avsi-pwtoggle'));
    if (!input) return;
    btn.addEventListener('click', () => {
      const showIt = input.type === 'password';
      input.type = showIt ? 'text' : 'password';
      btn.textContent = showIt ? 'Hide' : 'Show';
      btn.setAttribute('aria-pressed', showIt ? 'true' : 'false');
      btn.setAttribute('aria-label', showIt ? 'Hide password' : 'Show password');
      input.focus();
    });
  });

  /* ---- boot ---- */
  if (emailIn && !banner) focusSoon(emailIn);
})();
