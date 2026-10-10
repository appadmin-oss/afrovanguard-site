/**
 * Give (/give/) behaviour. Vanilla ES2019, no dependencies. Nav, footer and contour texture come from avh.js.
 * "Fund one" opens a sheet and pays on this page through window.avGive (assets/site/give-pay.js, shared with
 * the appeal pages): process-donation.php init_payment → Paystack inline (or the hosted page) → record_donation.
 * Every "Fund one" keeps its /donate.html?amount=…&for=… href, so without this script there is still a way to give.
 */
(() => {
  'use strict';
  const root = document.querySelector('main.avgv');
  const sheet = document.querySelector('[data-avgv-sheet]'), scrim = document.querySelector('[data-avgv-scrim]');
  if (!root || !sheet || !scrim) return;
  const $ = (s, el = sheet) => el.querySelector(s);
  const run = (name, fn) => { try { fn(); } catch (err) { console.error('[avgv] ' + name, err); } };
  const naira = n => '₦' + Number(n).toLocaleString('en-NG');

  run('sheet', () => {
    const form = $('[data-avgv-form]'), done = $('[data-avgv-done]'), errEl = $('[data-avgv-err]');
    const go = $('[data-avgv-go]'), amtEl = $('[data-avgv-amt]'), qtyEl = $('[data-avgv-qty-n]');
    const email = form.elements.email, name = form.elements.name;
    const down = $('[data-avgv-qty="-1"]'), up = $('[data-avgv-qty="1"]');
    let item = null, qty = 1, opener = null, busy = false, paid = false;

    const err = m => { errEl.textContent = m || ''; errEl.hidden = !m; m ? email.setAttribute('aria-invalid', 'true') : email.removeAttribute('aria-invalid'); };
    const paint = () => {
      qtyEl.textContent = String(qty); amtEl.textContent = naira(item.amount * qty);
      down.disabled = qty <= 1; up.disabled = qty >= item.left;
    };
    const focusables = () => Array.from(sheet.querySelectorAll('button:not([disabled]),input,a[href]')).filter(x => x.offsetParent !== null);
    const open = (it, from) => {
      item = it; qty = 1; opener = from; paid = false; busy = false;
      $('[data-avgv-pay-item]').textContent = it.title;
      form.hidden = false; done.hidden = true; err(''); go.disabled = false; go.classList.remove('is-busy'); go.removeAttribute('aria-busy');
      $('[data-avgv-go-idle]').hidden = false; $('[data-avgv-go-busy]').hidden = true;
      paint();
      sheet.hidden = scrim.hidden = false; document.documentElement.style.overflow = 'hidden';
      $('[data-avgv-pay-item]').focus();
    };
    const close = () => {
      if (sheet.hidden || busy) return;
      sheet.hidden = scrim.hidden = true; document.documentElement.style.overflow = '';
      if (paid) { location.reload(); return; } // the list re-reads the verified totals
      if (opener && opener.focus) opener.focus();
    };

    root.querySelectorAll('[data-avgv-pay]').forEach(a => a.addEventListener('click', e => {
      const amount = parseInt(a.dataset.avgvPay, 10);
      if (!amount || !window.avGive) return; // let the href do its job
      e.preventDefault();
      open({ amount, title: a.dataset.avgvItem || '', slug: a.dataset.avgvSlug || '', unit: a.dataset.avgvUnit || 'item', left: Math.max(1, parseInt(a.dataset.avgvLeft, 10) || 1) }, a);
    }));
    down.addEventListener('click', () => { if (qty > 1) { qty--; paint(); } });
    up.addEventListener('click', () => { if (qty < item.left) { qty++; paint(); } });
    sheet.querySelectorAll('[data-avgv-close]').forEach(b => b.addEventListener('click', close));
    $('[data-avgv-finish]').addEventListener('click', close);
    scrim.addEventListener('click', close);
    document.addEventListener('keydown', e => {
      if (sheet.hidden) return;
      if (e.key === 'Escape') { e.stopImmediatePropagation(); close(); return; }
      if (e.key !== 'Tab') return;
      const f = focusables(); if (!f.length) return;
      const first = f[0], last = f[f.length - 1];
      if (!sheet.contains(document.activeElement)) { e.preventDefault(); first.focus(); return; }
      if (e.shiftKey && (document.activeElement === first || document.activeElement === $('[data-avgv-pay-item]'))) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }, true);
    email.addEventListener('input', () => { if (!errEl.hidden) err(''); });

    form.addEventListener('submit', e => {
      e.preventDefault();
      if (busy || !item) return;
      const em = email.value.trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) { err('We need an email address to send you the receipt.'); email.focus(); return; }
      const whole = name.value.trim(), sp = whole.indexOf(' ');
      const it = item, n = qty, amount = it.amount * n;
      busy = true; go.disabled = true; go.classList.add('is-busy'); go.setAttribute('aria-busy', 'true'); err('');
      const idle = $('[data-avgv-go-idle]'), wait = $('[data-avgv-go-busy]');
      idle.hidden = true; wait.hidden = false;
      const reset = () => { busy = false; go.disabled = false; go.classList.remove('is-busy'); go.removeAttribute('aria-busy'); idle.hidden = false; wait.hidden = true; };
      window.avGive.give({
        amount, email: em, firstName: sp > 0 ? whole.slice(0, sp) : whole, lastName: sp > 0 ? whole.slice(sp + 1) : '',
        campaign: 'general', frequency: 'One-time',
        // The item is named so the gift can be reconciled against the catalogue row it was given for.
        message: it.title ? 'For: ' + it.title + (n > 1 ? ' ×' + n : '') + (it.slug ? ' (' + it.slug + ')' : '') : '',
        onDone: state => {
          busy = false; paid = true; form.hidden = true; done.hidden = false;
          $('[data-avgv-done-p]').textContent = state === 'paid'
            ? 'Your gift covers ' + n + ' ' + it.unit + (n > 1 && !/s$/.test(it.unit) ? 's' : '') + '. A receipt is on its way, and the list will show it when you close this.'
            : 'Your gift is with Paystack and the receipt follows once it settles.';
          $('[data-avgv-done-h]').focus();
        },
        onError: msg => { reset(); if (msg) err(msg); else go.focus(); }, // empty message = they closed Paystack themselves
        fallback: () => { location.href = '/donate.html?amount=' + amount + (it.slug ? '&for=' + encodeURIComponent(it.slug) : ''); }
      });
    });
  });
})();
