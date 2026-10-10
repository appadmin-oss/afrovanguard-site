/**
 * Contact (/contact.html) behaviour. Vanilla ES2019, no dependencies. Nav, footer and contour texture come from avh.js.
 * One guarded init per feature: a missing element skips that feature instead of breaking the page.
 * Form contract (unchanged from contact.html v1): POST multipart to /process-contact.php with
 * action=submit_contact, purpose, name, location, email, phone, subject, message, consent, website_url (honeypot),
 * attachment → JSON {success, message, field?, reference?}; 422/429 carry JSON too.
 */
(() => {
  'use strict';
  const root = document.querySelector('main.avct');
  if (!root) return;
  const $ = (s, el = root) => el.querySelector(s);
  const $$ = (s, el = root) => Array.from(el.querySelectorAll(s));
  const run = (name, fn) => { try { fn(); } catch (err) { console.error('[avct] ' + name, err); } };

  run('copy', () => {
    const status = $('[data-avct-copy-status]');
    const fallback = txt => {
      const ta = document.createElement('textarea');
      ta.value = txt; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      let ok = false; try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      ta.remove(); return ok ? Promise.resolve() : Promise.reject();
    };
    $$('[data-avct-copy]').forEach(btn => {
      let t = 0;
      btn.addEventListener('click', () => {
        const txt = btn.dataset.avctCopy;
        const p = navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText(txt).catch(() => fallback(txt)) : fallback(txt);
        p.then(() => {
          btn.textContent = 'Copied'; btn.classList.add('is-done');
          if (status) status.textContent = 'Copied ' + txt;
          clearTimeout(t); t = setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('is-done'); }, 1600);
        }).catch(() => { if (status) status.textContent = 'Could not copy. Select the text instead.'; });
      });
    });
  });

  run('hours', () => {
    const box = $('[data-avct-hours]'); if (!box) return;
    // Today in Lagos (WAT), whatever the visitor's time zone
    let day = new Date().getDay();
    try {
      const wd = new Intl.DateTimeFormat('en-GB', { weekday: 'short', timeZone: 'Africa/Lagos' }).format(new Date());
      day = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(wd);
    } catch (e) { /* keep local day */ }
    const row = $('[data-day="' + day + '"]', box); if (!row) return;
    row.classList.add('is-today');
    const dt = $('dt', row); if (dt) dt.textContent += ' · today';
  });

  run('form', () => {
    const form = $('[data-avct-form]'); if (!form || !window.fetch || !window.FormData) return;
    const sent = $('[data-avct-sent]'), alertBox = $('[data-avct-alert]');
    const btn = $('[data-avct-submit]'), btnLabel = $('[data-avct-submit-label]');
    const purpose = form.elements.purpose, msg = form.elements.message, file = $('[data-avct-file]', form);
    const hint = $('[data-avct-hint]'), count = $('[data-avct-count]'), fileLabel = $('[data-avct-file-label]');
    const LABEL = btnLabel ? btnLabel.textContent : 'Send message →';
    const MAX = 10 * 1024 * 1024, EXT = /\.(pdf|docx?|pptx?|jpe?g|png)$/i;
    const fields = { purpose: 'avct-purpose', name: 'avct-name', email: 'avct-email', message: 'avct-message', consent: 'avct-consent', attachment: 'avct-file' };
    const errEl = k => document.getElementById((k === 'attachment' ? 'avct-file' : fields[k]) + '-err');

    const setErr = (k, text) => {
      const e = errEl(k), input = k === 'attachment' ? file : document.getElementById(fields[k]);
      if (e) { e.textContent = text || ''; e.hidden = !text; }
      if (input) text ? input.setAttribute('aria-invalid', 'true') : input.removeAttribute('aria-invalid');
    };
    const clearAll = () => { Object.keys(fields).forEach(k => setErr(k, '')); if (alertBox) { alertBox.hidden = true; alertBox.textContent = ''; } };
    const showAlert = text => { if (!alertBox) return; alertBox.textContent = text; alertBox.hidden = false; };

    purpose?.addEventListener('change', () => {
      const o = purpose.selectedOptions[0], h = o && o.dataset.hint;
      if (hint) { hint.textContent = h || ''; hint.hidden = !h; }
      if (purpose.value) setErr('purpose', '');
    });
    const upCount = () => { if (count) count.textContent = msg.value.length.toLocaleString('en-GB') + ' / 2,000'; };
    msg?.addEventListener('input', upCount);
    file?.addEventListener('change', () => {
      const f = file.files && file.files[0];
      if (fileLabel) fileLabel.textContent = f ? f.name : 'Attach a file (optional)';
      setErr('attachment', f ? check.attachment() : '');
    });

    const v = n => (form.elements[n]?.value || '').trim();
    const check = {
      purpose: () => v('purpose') ? '' : 'Please select a purpose.',
      name: () => v('name').length >= 2 ? '' : 'Please enter your full name.',
      email: () => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v('email')) ? '' : 'Please enter a valid email address.',
      message: () => v('message').length >= 20 ? '' : 'Please tell us a little more (at least 20 characters).',
      consent: () => form.elements.consent.checked ? '' : 'Please accept the privacy notice before sending.',
      attachment: () => {
        const f = file && file.files && file.files[0]; if (!f) return '';
        if (f.size > MAX) return 'Attachment exceeds the 10 MB limit.';
        if (!EXT.test(f.name)) return 'File type not allowed. Accepted: PDF, DOC, DOCX, PPT, PPTX, JPG, PNG.';
        return '';
      }
    };
    // Re-validate a field once it has shown an error
    ['name', 'email', 'message'].forEach(k => form.elements[k]?.addEventListener('input', () => { if (errEl(k) && !errEl(k).hidden) setErr(k, check[k]()); }));
    form.elements.consent?.addEventListener('change', () => setErr('consent', check.consent()));

    let busy = false;
    const setBusy = on => {
      busy = on; if (!btn) return;
      btn.disabled = on; btn.classList.toggle('is-busy', on); btn.setAttribute('aria-busy', String(on));
      if (btnLabel) btnLabel.textContent = on ? 'Sending…' : LABEL;
      form.setAttribute('aria-busy', String(on));
    };

    form.addEventListener('submit', e => {
      e.preventDefault();
      if (busy) return;
      clearAll();
      let first = null;
      Object.keys(check).forEach(k => { const m = check[k](); setErr(k, m); if (m && !first) first = k; });
      if (first) {
        showAlert('Please check the highlighted fields.');
        (first === 'attachment' ? file : document.getElementById(fields[first]))?.focus();
        return;
      }
      const fd = new FormData(form);
      fd.set('consent', 'true');
      ['name', 'location', 'email', 'phone', 'subject', 'message'].forEach(k => fd.set(k, v(k)));
      if (!(file && file.files && file.files[0])) fd.delete('attachment');
      setBusy(true);
      const ctl = window.AbortController ? new AbortController() : null;
      const to = ctl && setTimeout(() => ctl.abort(), 30000);
      fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin', signal: ctl?.signal, headers: { Accept: 'application/json' } })
        .then(r => r.json().catch(() => Promise.reject(new Error('HTTP ' + r.status))))
        .then(d => {
          if (d && d.success) {
            form.hidden = true; sent.hidden = false;
            const ref = $('[data-avct-ref]');
            if (ref) { ref.textContent = d.reference ? 'Reference: ' + d.reference : ''; ref.hidden = !d.reference; }
            $('[data-avct-sent-h]')?.focus();
            return;
          }
          const m = (d && d.message) || 'Something went wrong. Please try again.';
          if (d && d.field && fields[d.field]) { setErr(d.field, m); document.getElementById(fields[d.field])?.focus(); }
          showAlert(m);
          if (!(d && d.field)) alertBox?.focus();
        })
        .catch(() => { showAlert('Could not reach the server. Please check your connection and try again — your message is still here.'); alertBox?.focus(); })
        .finally(() => { clearTimeout(to); setBusy(false); });
    });

    $('[data-avct-reset]')?.addEventListener('click', () => {
      form.reset(); clearAll(); upCount();
      if (hint) { hint.hidden = true; hint.textContent = ''; }
      if (fileLabel) fileLabel.textContent = 'Attach a file (optional)';
      sent.hidden = true; form.hidden = false; purpose?.focus();
    });
  });

  run('directory', () => {
    const chips = $$('[data-avct-chips] button'), items = $$('[data-avct-dir] li'), status = $('[data-avct-dir-status]');
    if (!chips.length || !items.length) return;
    chips.forEach(c => c.addEventListener('click', () => {
      const f = c.dataset.f;
      chips.forEach(o => o.setAttribute('aria-pressed', String(o === c)));
      let n = 0;
      items.forEach(li => { const on = f === 'All' || li.dataset.tag === f; li.hidden = !on; if (on) n++; });
      const list = $('[data-avct-dir]'); if (list) list.scrollLeft = 0;
      if (status) status.textContent = n + (n === 1 ? ' programme' : ' programmes') + ' shown';
    }));
  });

  run('locations', () => {
    const tabs = $$('[data-avct-tabs] [role=tab]'), panel = $('[data-avct-loc]'), map = $('[data-avct-map]');
    if (!tabs.length || !panel) return;
    const select = (t, focus) => {
      tabs.forEach(o => { const on = o === t; o.setAttribute('aria-selected', String(on)); o.tabIndex = on ? 0 : -1; });
      panel.setAttribute('aria-labelledby', t.id);
      $('[data-avct-loc-name]', panel).textContent = t.dataset.name;
      $('[data-avct-loc-dir]', panel).href = t.dataset.dir;
      if (map && map.getAttribute('src') !== t.dataset.map) { map.src = t.dataset.map; map.title = t.dataset.name + ' map'; }
      if (focus) t.focus();
    };
    tabs.forEach((t, i) => {
      t.addEventListener('click', () => select(t));
      t.addEventListener('keydown', e => {
        const k = e.key; let j = -1;
        if (k === 'ArrowRight') j = (i + 1) % tabs.length; else if (k === 'ArrowLeft') j = (i - 1 + tabs.length) % tabs.length;
        else if (k === 'Home') j = 0; else if (k === 'End') j = tabs.length - 1;
        if (j > -1) { e.preventDefault(); select(tabs[j], true); }
      });
    });
  });

  run('faq', () => {
    const btns = $$('[data-avct-acc] button[aria-controls]'); if (!btns.length) return;
    const panelOf = b => document.getElementById(b.getAttribute('aria-controls'));
    btns.forEach(b => b.addEventListener('click', () => {
      const willOpen = b.getAttribute('aria-expanded') !== 'true';
      btns.forEach(o => { o.setAttribute('aria-expanded', 'false'); const p = panelOf(o); if (p) p.hidden = true; });
      if (willOpen) { b.setAttribute('aria-expanded', 'true'); const p = panelOf(b); if (p) p.hidden = false; }
    }));
  });
})();
