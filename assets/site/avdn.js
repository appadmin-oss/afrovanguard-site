/**
 * Donate (/donate.html) behaviour. Vanilla ES2019, no dependencies. Nav, footer and contour texture come from avh.js.
 * One guarded init per feature. Payment contract unchanged from donate.html v1 (POST JSON to /process-donation.php):
 *   init_payment → {success, authorization_url, reference} → Paystack hosted checkout → back to /donate?reference=… → record_donation
 *   generate_virtual_account → {success, account_number, bank_name, account_name, reference, expires_mins}; GET verify_payment polled every 8 s
 *   no virtual account → static Zenith account + bank_transfer_copy (emails the details)
 *   submit_contribute (in-kind), GET get_stats (campaign totals), GET get_donors (donor wall)
 * Incoming links: ?amount=N&campaign=slug&for=slug (from /give/) preset the gift.
 */
(() => {
  'use strict';
  const root = document.querySelector('main.avdn');
  if (!root || !window.fetch) return;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const run = (name, fn) => { try { fn(); } catch (err) { console.error('[avdn] ' + name, err); } };
  const reduceMq = matchMedia('(prefers-reduced-motion: reduce)');
  const API = '/process-donation.php';
  const SYM = { NGN: '₦', USD: '$', GBP: '£' };
  const LIMITS = { NGN: [5000, 10000000], USD: [10, 50000], GBP: [10, 40000] };
  const DESC = ['1 week of mentorship', 'Training for 3 leaders', 'A full workshop', 'A month for a centre', 'Fund a full programme'];
  const IMG = ['gates1.png', 'bootcamp1.png', 'storm3.png', 'summer1.png', 'culture1.png'].map(f => '/Images/' + f);
  const fmt = (n, cur) => SYM[cur] + Number(n).toLocaleString('en-US');
  const post = (body, ms = 20000) => {
    const ctl = window.AbortController ? new AbortController() : null, to = ctl && setTimeout(() => ctl.abort(), ms);
    return fetch(API, { method: 'POST', credentials: 'same-origin', signal: ctl?.signal, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) })
      .then(r => r.json().catch(() => Promise.reject(new Error('HTTP ' + r.status))))
      .finally(() => clearTimeout(to));
  };
  const get = (qs, ms = 10000) => {
    const ctl = window.AbortController ? new AbortController() : null, to = ctl && setTimeout(() => ctl.abort(), ms);
    return fetch(API + '?' + qs, { credentials: 'same-origin', signal: ctl?.signal, headers: { Accept: 'application/json' } })
      .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status))).finally(() => clearTimeout(to));
  };

  /* ===== Dialogs: role=dialog, focus trap, Esc closes the top one, focus returns ===== */
  const scrim = $('[data-avdn-scrim]'); const stack = [];
  const dialog = el => {
    let opener = null;
    const focusables = () => $$('a[href],button:not([disabled]),input:not([disabled]),select,textarea', el).filter(x => x.offsetParent !== null);
    const api = {
      el, onClose: null,
      open(from) {
        opener = from || document.activeElement; el.hidden = false; scrim.hidden = false;
        document.documentElement.style.overflow = 'hidden'; stack.push(api);
        ($('h2', el) || el).focus();
      },
      close() {
        if (el.hidden) return; el.hidden = true;
        const i = stack.indexOf(api); if (i > -1) stack.splice(i, 1);
        if (!stack.length) { scrim.hidden = true; document.documentElement.style.overflow = ''; }
        api.onClose && api.onClose();
        if (opener && opener.focus) opener.focus();
      }
    };
    el.addEventListener('keydown', e => {
      if (e.key !== 'Tab') return;
      const f = focusables(); if (!f.length) return;
      const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && (document.activeElement === first || document.activeElement === $('h2', el))) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    $$('[data-avdn-close]', el).forEach(b => b.addEventListener('click', () => api.close()));
    return api;
  };
  scrim?.addEventListener('click', () => stack.length && stack[stack.length - 1].close());
  // Esc closes the top-most dialog only, wherever focus is (it can fall to <body> when a step hides)
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && stack.length) { e.stopImmediatePropagation(); stack[stack.length - 1].close(); } }, true);

  const copyText = (txt, btn, status) => {
    const fallback = () => { const ta = document.createElement('textarea'); ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select(); let ok = false; try { ok = document.execCommand('copy'); } catch (e) { ok = false; } ta.remove(); return ok ? Promise.resolve() : Promise.reject(); };
    const p = navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText(txt).catch(fallback) : fallback();
    p.then(() => { btn.textContent = 'Copied'; btn.classList.add('is-done'); if (status) status.textContent = 'Copied ' + txt; setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('is-done'); }, 2000); })
      .catch(() => { if (status) status.textContent = 'Could not copy. The number is ' + txt; });
  };

  /* ===== Gift card ===== */
  const card = $('[data-avdn-card]');
  const s1 = $('[data-avdn-step="1"]', card), s2 = $('[data-avdn-step="2"]', card);
  const sBusy = $('[data-avdn-step="busy"]', card), sDone = $('[data-avdn-step="done"]', card);
  const state = { cur: 'NGN', freq: 'One-time', sel: 1, custom: 0, camp: 'general' };
  const amtSpans = $$('[data-avdn-amts] span[data-ngn]', s1);
  const tierOf = (i, cur) => +amtSpans[i].dataset[cur.toLowerCase()];
  const amount = () => state.sel === 'custom' ? state.custom : tierOf(state.sel, state.cur);
  const campLabel = () => { const o = s1.elements.campaign.selectedOptions[0]; return o ? o.textContent : ''; };
  const show = step => { [s1, s2, sBusy, sDone].forEach(s => { s.hidden = s !== step; }); };

  const paint = () => {
    const cur = state.cur, amt = amount(), f = state.freq;
    amtSpans.forEach((sp, i) => { sp.textContent = fmt(tierOf(i, cur), cur); });
    $$('[data-avdn-tiers] button').forEach(b => {
      const i = +b.dataset.tier; $('b', b).textContent = fmt(tierOf(i, cur), cur);
      b.classList.toggle('is-on', state.sel === i); b.setAttribute('aria-pressed', String(state.sel === i));
    });
    $('[data-avdn-sym]', s1).textContent = SYM[cur];
    $('[data-avdn-custom]', s1).hidden = state.sel !== 'custom';
    const custom = state.sel === 'custom';
    $('[data-avdn-impact-img]', s1).src = IMG[custom ? 0 : state.sel];
    $('[data-avdn-impact-head]', s1).textContent = custom ? 'Every amount counts' : fmt(amt, cur) + (f === 'Monthly' ? ' / month' : f === 'Annual' ? ' / year' : '');
    $('[data-avdn-impact-line]', s1).textContent = custom ? 'Your gift is tagged to “' + campLabel() + '”.'
      : (/^fund /i.test(DESC[state.sel]) ? DESC[state.sel] : 'Funds ' + DESC[state.sel].toLowerCase()) + (f === 'One-time' ? '.' : ', every ' + (f === 'Monthly' ? 'month.' : 'year.'));
    const per = f === 'Monthly' ? ' monthly' : f === 'Annual' ? ' yearly' : '';
    $('[data-avdn-go-label]', s1).textContent = amt > 0 ? 'Give ' + fmt(amt, cur) + per : 'Enter an amount';
    $('[data-avdn-pay-label]', s2).textContent = amt > 0 ? 'Pay ' + fmt(amt, cur) + per + ' by card' : 'Pay by card';
    $('[data-avdn-sum]', s2).textContent = (amt > 0 ? fmt(amt, cur) + per : '') + ' · ' + campLabel();
    $('[data-avdn-transfer]', s2).hidden = cur !== 'NGN';
  };

  const setAmountRadio = v => { const r = s1.querySelector('input[name="amt"][value="' + v + '"]'); if (r) r.checked = true; };

  run('step1', () => {
    s1.addEventListener('change', e => {
      const t = e.target;
      if (t.name === 'freq') state.freq = t.value;
      if (t.name === 'cur') { state.cur = t.value; }
      if (t.name === 'amt') { state.sel = t.value === 'custom' ? 'custom' : +t.value; if (state.sel === 'custom') $('[data-avdn-custom-input]', s1).focus(); }
      if (t.name === 'campaign') state.camp = t.value;
      paint(); paintCampStat();
    });
    const ci = $('[data-avdn-custom-input]', s1), cErr = $('#avdn-custom-err');
    ci.addEventListener('input', () => { state.custom = Math.max(0, parseFloat(ci.value) || 0); cErr.hidden = true; ci.removeAttribute('aria-invalid'); paint(); });
    s1.addEventListener('submit', e => {
      e.preventDefault();
      const amt = amount(), [min, max] = LIMITS[state.cur];
      if (state.sel === 'custom' && (amt < min || amt > max)) {
        cErr.textContent = amt > max ? 'The most we can take in one payment is ' + fmt(max, state.cur) + '. For larger gifts email donations@afrovanguard.org.ng.' : 'The smallest gift we can process is ' + fmt(min, state.cur) + '.';
        cErr.hidden = false; ci.setAttribute('aria-invalid', 'true'); ci.focus(); return;
      }
      paint(); show(s2); $('#avdn-s2-h').focus();
    });
    $('[data-avdn-back]', s2).addEventListener('click', () => { show(s1); s1.querySelector('input[name="amt"]:checked')?.focus(); });
  });

  run('tiers', () => {
    $$('[data-avdn-tiers] button').forEach(b => b.addEventListener('click', () => {
      state.sel = +b.dataset.tier; setAmountRadio(b.dataset.tier); show(s1); paint();
      scrollTo({ top: 0, behavior: reduceMq.matches ? 'auto' : 'smooth' });
      s1.querySelector('input[name="amt"]:checked')?.focus({ preventScroll: true });
    }));
  });

  /* Details + card payment */
  const alertBox = $('[data-avdn-alert]', s2);
  const showAlert = m => { alertBox.textContent = m; alertBox.hidden = false; alertBox.focus(); };
  const fieldErr = (name, id, msg) => { const el = s2.elements[name], e = $('#' + id); e.textContent = msg || ''; e.hidden = !msg; msg ? el.setAttribute('aria-invalid', 'true') : el.removeAttribute('aria-invalid'); return !msg; };
  const donor = () => ({ fn: s2.elements.firstName.value.trim(), ln: s2.elements.lastName.value.trim(), email: s2.elements.email.value.trim(), phone: s2.elements.phone.value.trim(), msg: s2.elements.message.value.trim(), anon: s2.elements.anonymous.checked });
  const validDetails = () => {
    const d = donor(); alertBox.hidden = true;
    const ok = [fieldErr('firstName', 'avdn-fn-err', d.fn ? '' : 'Please enter your first name.'),
      fieldErr('lastName', 'avdn-ln-err', d.ln ? '' : 'Please enter your last name.'),
      fieldErr('email', 'avdn-em-err', /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email) ? '' : 'Please enter a valid email for your receipt.')];
    if (ok.includes(false)) { s2.querySelector('[aria-invalid="true"]')?.focus(); return null; }
    return d;
  };
  const payBtn = $('[data-avdn-pay]', s2), trBtn = $('[data-avdn-transfer]', s2);
  const busyBtn = (on, label) => { payBtn.disabled = trBtn.disabled = on; payBtn.classList.toggle('is-busy', on); payBtn.setAttribute('aria-busy', String(on)); if (on) $('[data-avdn-pay-label]', s2).textContent = label; else paint(); };

  run('pay', () => {
    s2.addEventListener('submit', e => {
      e.preventDefault();
      if (payBtn.disabled) return;
      const d = validDetails(); if (!d) return;
      const amt = amount(); if (!(amt > 0)) { show(s1); return; }
      busyBtn(true, 'Connecting to Paystack…');
      post({ action: 'init_payment', firstName: d.fn, lastName: d.ln, email: d.email, phone: d.phone, amount: amt, currency: state.cur, campaign: state.camp, frequency: state.freq, anonymous: d.anon, message: d.msg })
        .then(data => {
          if (data && data.success && data.authorization_url && /^https:\/\//.test(data.authorization_url)) {
            try { sessionStorage.setItem('av_donation', JSON.stringify({ fn: d.fn, ln: d.ln, email: d.email, anon: d.anon, ref: data.reference, amount: amt, currency: state.cur, campaign: state.camp, frequency: state.freq })); } catch (err) { /* private mode: thank-you is less personal */ }
            location.href = data.authorization_url; // → Paystack hosted checkout
            return;
          }
          busyBtn(false); showAlert((data && data.message) || 'Could not start the payment. Please try again or use bank transfer.');
        })
        .catch(() => { busyBtn(false); showAlert('We couldn’t reach the payment service. Check your connection and try again, or use bank transfer.'); });
    });
  });

  const done = (head, text) => {
    $('[data-avdn-done-h]', card).textContent = head; $('[data-avdn-done-text]', card).textContent = text;
    show(sDone); $('[data-avdn-done-h]', card).focus();
  };
  $('[data-avdn-again]', card)?.addEventListener('click', () => { s2.reset(); show(s1); paint(); s1.querySelector('input[name="amt"]:checked')?.focus(); });

  run('return', () => {
    const qs = new URLSearchParams(location.search), ref = qs.get('reference') || qs.get('trxref');
    if (!ref) return;
    let saved = {}; try { saved = JSON.parse(sessionStorage.getItem('av_donation') || '{}'); sessionStorage.removeItem('av_donation'); } catch (e) { saved = {}; }
    try { history.replaceState(null, '', location.pathname); } catch (e) { /* keep URL */ }
    $('[data-avdn-busy-text]', card).textContent = 'Confirming your gift…'; show(sBusy);
    post({ action: 'record_donation', reference: ref, firstName: saved.fn || '', lastName: saved.ln || '', email: saved.email || '', amount: saved.amount || 0, currency: SYM[saved.currency || 'NGN'] || '₦', campaign: saved.campaign || 'general', anonymous: !!saved.anon, frequency: saved.frequency || 'One-time' }, 30000)
      .then(data => {
        if (data && data.campaigns) statCache = data.campaigns;
        if (data && data.success === false) return done('We’re still confirming', data.message || 'We could not confirm the payment yet — your receipt will arrive once it is confirmed.');
        done('Thank you' + (saved.fn ? ', ' + saved.fn : ''), 'Your gift is confirmed. A receipt is on its way to ' + (saved.email || 'your email') + '.');
        loadWall();
      })
      .catch(() => done('We’re still confirming', 'We could not confirm the payment right now. If you were charged, your receipt will still arrive.'));
  });

  /* ===== Bank transfer dialog ===== */
  run('transfer', () => {
    const el = $('[data-avdn-bt]'); if (!el) return;
    const dlg = dialog(el);
    const part = k => $('[data-bt="' + k + '"]', el);
    const setPart = k => ['loading', 'account', 'paid', 'expired'].forEach(x => { part(x).hidden = x !== k; });
    let timer = 0, poll = 0, left = 0, ref = null, paidOk = false;
    const stop = () => { clearInterval(timer); clearInterval(poll); };
    dlg.onClose = () => { stop(); if (paidOk) { paidOk = false; done('Thank you', 'Your transfer has landed. A receipt is on its way to your email.'); } };
    const status = $('[data-bt-status]', el);
    const tick = () => {
      left--; const m = String(Math.max(0, Math.floor(left / 60))).padStart(2, '0'), s = String(Math.max(0, left % 60)).padStart(2, '0');
      $('[data-bt-timer]', el).textContent = m + ':' + s;
      $('[data-bt-live]', el).classList.toggle('is-urgent', left <= 300);
      if (left <= 0) { stop(); setPart('expired'); }
    };
    const check = () => {
      if (!ref) return Promise.resolve();
      return get('action=verify_payment&reference=' + encodeURIComponent(ref)).then(d => {
        if (d && d.paid) { stop(); paidOk = true; setPart('paid'); $('[data-bt="paid"] button', el).focus(); return; }
        status.textContent = d && d.status === 'abandoned' ? 'Waiting for your transfer…' : 'Checking for your transfer…';
      }).catch(() => { status.textContent = 'Can’t check right now — we’ll keep trying.'; });
    };
    const fill = (bank, acc, name, refTxt) => {
      $('[data-bt-amount]', el).textContent = fmt(amount(), 'NGN');
      $('[data-bt-bank]', el).textContent = bank; $('[data-bt-acc]', el).textContent = acc; $('[data-bt-name]', el).textContent = name;
      $('[data-bt-refrow]', el).hidden = !refTxt; $('[data-bt-ref]', el).textContent = refTxt || '';
    };
    const staticAccount = d => {
      ref = null; fill('Zenith Bank', '1229629683', 'Ambassadors for Community, Tech & Cultural Advancements', '');
      $('[data-bt-live]', el).hidden = true;
      $('[data-bt-note]', el).textContent = 'After transferring, email donations@afrovanguard.org.ng with your name, amount and date — we’ll send your receipt within 24 hours.';
      setPart('account');
      post({ action: 'bank_transfer_copy', name: d.fn + ' ' + d.ln, email: d.email }).catch(() => {});
    };
    const start = d => {
      setPart('loading'); stop();
      post({ action: 'generate_virtual_account', name: d.fn + ' ' + d.ln, email: d.email, amount: amount(), campaign: state.camp })
        .then(data => {
          if (!(data && data.success && data.account_number)) return staticAccount(d);
          ref = data.reference; left = (data.expires_mins || 30) * 60 + 1;
          fill(data.bank_name || 'Wema Bank', data.account_number, data.account_name || 'Paystack / Afrovanguard', data.reference);
          $('[data-bt-live]', el).hidden = false;
          $('[data-bt-note]', el).textContent = 'Your receipt goes to ' + d.email + ' once the transfer lands.';
          setPart('account'); tick(); timer = setInterval(tick, 1000); poll = setInterval(check, 8000);
        })
        .catch(() => staticAccount(d));
    };
    let last = null;
    trBtn.addEventListener('click', () => {
      const d = validDetails(); if (!d) return;
      const [min] = LIMITS.NGN;
      if (amount() < min) return showAlert('The smallest bank transfer we can set up is ' + fmt(min, 'NGN') + '.');
      last = d; dlg.open(trBtn); start(d);
    });
    $('[data-bt-check]', el).addEventListener('click', () => { status.textContent = 'Checking now…'; check(); });
    $('[data-bt-retry]', el).addEventListener('click', () => last && start(last));
    $('[data-bt-copy]', el).addEventListener('click', e => copyText($('[data-bt-acc]', el).textContent, e.currentTarget, status));
  });

  /* ===== In-kind dialog ===== */
  run('inkind', () => {
    const el = $('[data-avdn-inkind]'), opener = $('[data-avdn-inkind-open]'); if (!el || !opener) return;
    const dlg = dialog(el), form = $('[data-ik-form]', el), alertB = $('[data-ik-alert]', el), btn = $('[data-ik-submit]', el), lbl = $('[data-ik-label]', el);
    opener.addEventListener('click', e => { e.preventDefault(); form.hidden = false; $('[data-ik-done]', el).hidden = true; dlg.open(opener); });
    form.addEventListener('submit', e => {
      e.preventDefault(); if (btn.disabled) return;
      const f = form.elements, v = n => f[n].value.trim();
      alertB.hidden = true; let first = null;
      [['name', v('name')], ['email', /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v('email'))], ['contribute_type', v('contribute_type')], ['description', v('description')]]
        .forEach(([n, ok]) => { if (!ok) { f[n].setAttribute('aria-invalid', 'true'); first = first || f[n]; } else f[n].removeAttribute('aria-invalid'); });
      if (first) { alertB.textContent = 'Please fill in the highlighted fields.'; alertB.hidden = false; first.focus(); return; }
      btn.disabled = true; btn.classList.add('is-busy'); lbl.textContent = 'Sending…';
      post({ action: 'submit_contribute', name: v('name'), email: v('email'), contribute_type: v('contribute_type'), description: v('description'), campaign: 'lcasp-2026' })
        .then(d => {
          if (d && d.success) { form.reset(); form.hidden = true; $('[data-ik-done]', el).hidden = false; $('[data-ik-done] button', el).focus(); return; }
          alertB.textContent = (d && d.message) || 'Something went wrong. Please try again.'; alertB.hidden = false; alertB.focus();
        })
        .catch(() => { alertB.textContent = 'Connection failed. Please try again or email donations@afrovanguard.org.ng.'; alertB.hidden = false; alertB.focus(); })
        .finally(() => { btn.disabled = false; btn.classList.remove('is-busy'); lbl.textContent = 'Send'; });
    });
  });

  /* ===== Campaign totals (get_stats) ===== */
  let statCache = null;
  const paintCampStat = () => {
    const out = $('[data-avdn-camp-stat]', s1); const c = statCache && statCache[state.camp];
    if (!out || !c || !(c.raised > 0)) { if (out) out.hidden = true; return; }
    out.textContent = fmt(c.raised, 'NGN') + ' raised' + (c.goal ? ' of ' + fmt(c.goal, 'NGN') : '') + (c.donors ? ' · ' + c.donors.toLocaleString('en-US') + (c.donors === 1 ? ' donor' : ' donors') : '');
    out.hidden = false;
  };
  run('stats', () => { get('action=get_stats').then(d => { if (d && d.success) { statCache = d.campaigns || null; paintCampStat(); } }).catch(() => {}); });

  /* ===== Donor wall (get_donors) ===== */
  const wall = $('[data-avdn-wall]');
  const TIERS = [[50000000, 'Platinum'], [20000000, 'Gold'], [5000000, 'Silver'], [1000000, 'Bronze'], [100000, 'Patron'], [0, 'Friend']];
  function loadWall() {
    if (!wall) return;
    const list = $('[data-avdn-wall-list]', wall), empty = $('[data-avdn-wall-empty]', wall), err = $('[data-avdn-wall-error]', wall), count = $('[data-avdn-wall-count]', wall);
    empty.hidden = err.hidden = true; list.hidden = false; list.setAttribute('aria-busy', 'true');
    if (!list.querySelector('.avdn-skel')) list.innerHTML = '<li class="avdn-skel" aria-hidden="true"></li>'.repeat(4);
    get('action=get_donors&limit=12').then(d => {
      const donors = (d && d.success && d.donors) || [];
      list.replaceChildren(); list.setAttribute('aria-busy', 'false');
      if (!donors.length) { list.hidden = true; empty.hidden = false; count.textContent = ''; return; }
      count.textContent = (d.total || donors.length).toLocaleString('en-US') + ' gifts so far.';
      donors.forEach(x => {
        const li = document.createElement('li'), ini = document.createElement('span'), body = document.createElement('span'), b = document.createElement('b'), sm = document.createElement('small');
        ini.className = 'avdn-ini'; ini.setAttribute('aria-hidden', 'true'); ini.textContent = String(x.initials || '').slice(0, 2);
        b.textContent = x.name || 'Donor';
        const amt = String(x.amount_display || ''); sm.textContent = [amt, x.time_ago].filter(Boolean).join(' · ');
        if (x.type !== 'inkind' && amt.startsWith('₦')) {
          const n = +amt.replace(/[^\d]/g, ''); const t = TIERS.find(([min]) => n >= min);
          if (t) { const tg = document.createElement('span'); tg.className = 'avdn-tier'; tg.textContent = t[1]; b.appendChild(tg); }
        }
        body.append(b, sm); li.append(ini, body); list.appendChild(li);
      });
    }).catch(() => { list.replaceChildren(); list.hidden = true; list.setAttribute('aria-busy', 'false'); err.hidden = false; });
  }
  run('wall', () => {
    if (!wall) return;
    $('[data-avdn-wall-retry]', wall)?.addEventListener('click', loadWall);
    if ('IntersectionObserver' in window) { const io = new IntersectionObserver(es => { if (es.some(e => e.isIntersecting)) { io.disconnect(); loadWall(); } }, { rootMargin: '400px' }); io.observe(wall); }
    else loadWall();
  });

  /* ===== FAQ + copy ===== */
  run('faq', () => {
    const btns = $$('[data-avdn-acc] button[aria-controls]'); const panelOf = b => document.getElementById(b.getAttribute('aria-controls'));
    btns.forEach(b => b.addEventListener('click', () => {
      const willOpen = b.getAttribute('aria-expanded') !== 'true';
      btns.forEach(o => { o.setAttribute('aria-expanded', 'false'); const p = panelOf(o); if (p) p.hidden = true; });
      if (willOpen) { b.setAttribute('aria-expanded', 'true'); const p = panelOf(b); if (p) p.hidden = false; }
    }));
  });
  run('copy', () => { const st = $('[data-avdn-copy-status]'); $$('[data-avdn-copy]').forEach(b => b.addEventListener('click', () => copyText(b.dataset.avdnCopy, b, st))); });

  /* ===== Presets: links from /give/, then the visitor's region ===== */
  run('preset', () => {
    const qs = new URLSearchParams(location.search);
    const amt = parseInt(qs.get('amount') || '', 10);
    const slug = (qs.get('campaign') || '').toLowerCase().replace(/[^a-z0-9_-]/g, '').slice(0, 60);
    if (slug) {
      const sel = s1.elements.campaign;
      if (!sel.querySelector('option[value="' + slug + '"]')) {
        const o = document.createElement('option'); o.value = slug;
        o.textContent = slug.replace(/[-_]+/g, ' ').replace(/^./, c => c.toUpperCase()); sel.appendChild(o);
      }
      sel.value = slug; state.camp = slug;
    }
    if (amt > 0) {
      const i = amtSpans.findIndex(sp => +sp.dataset.ngn === amt);
      if (i > -1) { state.sel = i; setAmountRadio(String(i)); }
      else { state.sel = 'custom'; state.custom = amt; setAmountRadio('custom'); $('[data-avdn-custom-input]', s1).value = String(amt); }
      return; // a /give/ amount is in naira
    }
    try {
      const loc = (navigator.languages && navigator.languages[0]) || navigator.language || '', region = (loc.split('-')[1] || '').toUpperCase();
      const cur = region === 'GB' ? 'GBP' : ['US', 'CA', 'AU', 'NZ', 'SG', 'HK', 'IN', 'ZA', 'KE'].includes(region) ? 'USD' : '';
      if (cur) { state.cur = cur; const r = s1.querySelector('input[name="cur"][value="' + cur + '"]'); if (r) r.checked = true; }
    } catch (e) { /* NGN */ }
  });
  paint();
})();
