<?php
$STS_ROOT = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($STS_ROOT === '' || !is_file($STS_ROOT.'/inc/head.php')) { $STS_ROOT = __DIR__; while (!is_file($STS_ROOT.'/inc/head.php') && dirname($STS_ROOT) !== $STS_ROOT) $STS_ROOT = dirname($STS_ROOT); }
$PAGE_TITLE = "Contact — Street-To-Stardom";
$PAGE_DESC  = "";
$PAGE_PATH  = "/contact/";
$PAGE_HEAD_EXTRA = <<<'STSHEAD'
<style>.ct-row[data-astro-cid-zlruecd3]{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media (max-width: 600px){.ct-row[data-astro-cid-zlruecd3]{grid-template-columns:1fr;gap:0}}
</style>
STSHEAD;
require $STS_ROOT.'/inc/head.php';
?>
<section class="section page-header" data-screen-label="Page header"> <div class="section-inner"> <span class="eyebrow reveal">Contact · STS</span> <h1 class="page-title reveal">Get in touch. <span class="accent">We reply</span>, even if briefly.</h1> <p class="page-lede reveal">A short note routes faster than a long one. Tell us what you need; we&#39;ll send it to the right inbox.</p>  </div> </section> <section class="section form-shell" data-screen-label="Contact · Form"> <div class="section-inner"> <div x-data="contactForm()" class="form-grid" data-astro-cid-zlruecd3> <div class="form-card" data-astro-cid-zlruecd3> <template x-if="!submitted" data-astro-cid-zlruecd3> <div data-astro-cid-zlruecd3> <div class="form-field" data-astro-cid-zlruecd3> <label class="form-label" for="ct-name" data-astro-cid-zlruecd3>Your name</label> <input id="ct-name" class="form-input" type="text" placeholder="As you'd like us to address you" x-model="name" data-astro-cid-zlruecd3> </div> <div class="ct-row" data-astro-cid-zlruecd3> <div class="form-field" data-astro-cid-zlruecd3> <label class="form-label" for="ct-email" data-astro-cid-zlruecd3>Email</label> <input id="ct-email" class="form-input" type="email" placeholder="you@example.org" x-model="email" :class="{ 'invalid': emailInvalid }" @blur="validateEmail()" data-astro-cid-zlruecd3> </div> <div class="form-field" data-astro-cid-zlruecd3> <label class="form-label" for="ct-phone" data-astro-cid-zlruecd3>Phone <span class="opt" data-astro-cid-zlruecd3>optional</span></label> <input id="ct-phone" class="form-input" type="tel" placeholder="+234 …" x-model="phone" data-astro-cid-zlruecd3> </div> </div> <div class="form-field" data-astro-cid-zlruecd3> <label class="form-label" for="ct-msg" data-astro-cid-zlruecd3>Message</label> <textarea id="ct-msg" class="form-textarea" rows="6" placeholder="A line or two is enough." x-model="message" @input.debounce.800ms="categorize" data-astro-cid-zlruecd3></textarea> </div> <div x-show="intent && !submitted && !aiLoading" x-transition.opacity x-cloak class="ai-banner" style="margin-top: 8px;" data-astro-cid-zlruecd3> <span class="spark" data-astro-cid-zlruecd3>✦</span> <div data-astro-cid-zlruecd3> <strong data-astro-cid-zlruecd3>Routing to <span x-text="intents.find(i => i.id === intent)?.inbox || ''" data-astro-cid-zlruecd3></span></strong> <span x-show="aiSource === 'ai'" style="font-size: 11px; padding: 2px 6px; border-radius: 4px; background: rgba(7,50,247,0.12); color: var(--blue); font-family: var(--font-mono); margin-left: 8px; letter-spacing: 0.04em;" data-astro-cid-zlruecd3>AI · classified</span> <span x-show="aiSource === 'local'" style="font-size: 11px; padding: 2px 6px; border-radius: 4px; background: var(--hairline); color: var(--muted); font-family: var(--font-mono); margin-left: 8px; letter-spacing: 0.04em;" data-astro-cid-zlruecd3>heuristic</span> <span x-show="aiSource === 'user'" style="font-size: 11px; padding: 2px 6px; border-radius: 4px; background: var(--crimson-tint); color: var(--crimson); font-family: var(--font-mono); margin-left: 8px; letter-spacing: 0.04em;" data-astro-cid-zlruecd3>you chose</span> <div style="margin-top: 4px; font-size: 12.5px; color: var(--muted);" data-astro-cid-zlruecd3>Override the route below if that's not right.</div> </div> </div> <div x-show="aiLoading" x-transition.opacity x-cloak class="ai-banner" style="margin-top: 8px;" data-astro-cid-zlruecd3> <span class="spark" data-astro-cid-zlruecd3>✦</span> <div data-astro-cid-zlruecd3> <strong data-astro-cid-zlruecd3>Reading your message<span class="ai-typing" data-astro-cid-zlruecd3></span></strong> <div style="margin-top: 4px; font-size: 12.5px; color: var(--muted);" data-astro-cid-zlruecd3>Finding the right inbox to route this to.</div> </div> </div> <div class="form-field" style="margin-top: 24px;" data-astro-cid-zlruecd3> <label class="form-label" data-astro-cid-zlruecd3>Route to <span class="opt" data-astro-cid-zlruecd3>override AI suggestion</span></label> <div class="chip-grid" data-astro-cid-zlruecd3> <button type="button" class="chip" :class="intent === 'press' ? 'on' : ''" @click="intent = 'press'; userOverride = true; aiSource = 'user'" data-astro-cid-zlruecd3>Press · media</button><button type="button" class="chip" :class="intent === 'partner' ? 'on' : ''" @click="intent = 'partner'; userOverride = true; aiSource = 'user'" data-astro-cid-zlruecd3>Partnership</button><button type="button" class="chip" :class="intent === 'volunteer' ? 'on' : ''" @click="intent = 'volunteer'; userOverride = true; aiSource = 'user'" data-astro-cid-zlruecd3>Volunteer</button><button type="button" class="chip" :class="intent === 'general' ? 'on' : ''" @click="intent = 'general'; userOverride = true; aiSource = 'user'" data-astro-cid-zlruecd3>General</button><button type="button" class="chip" :class="intent === 'complaint' ? 'on' : ''" @click="intent = 'complaint'; userOverride = true; aiSource = 'user'" data-astro-cid-zlruecd3>Concern / complaint</button> </div> </div> <input type="text" name="website" tabindex="-1" autocomplete="off" x-model="honeypot" style="position:absolute; left:-9999px;" aria-hidden="true" data-astro-cid-zlruecd3> <div class="form-actions" data-astro-cid-zlruecd3> <span style="font-family: var(--font-mono); font-size: 11px; color: var(--muted);" data-astro-cid-zlruecd3>Replies within 2 business days</span> <button type="button" class="btn btn-primary" @click="submit" :disabled="!name || !email || !message || submitting" :style="{ opacity: (!name || !email || !message || submitting) ? 0.5 : 1 }" data-astro-cid-zlruecd3> <span x-text="submitting ? 'Sending…' : 'Send message'" data-astro-cid-zlruecd3></span> <span class="btn-arrow" x-show="!submitting" data-astro-cid-zlruecd3>→</span> </button> </div> <p class="form-help" x-show="submitError" x-cloak style="color: var(--crimson); margin-top: 12px;" x-text="submitError" data-astro-cid-zlruecd3></p> </div> </template> <template x-if="submitted" data-astro-cid-zlruecd3> <div class="confirm-block slide-up" role="status" aria-live="polite" data-astro-cid-zlruecd3> <div class="confirm-check" aria-hidden="true" data-astro-cid-zlruecd3>✓</div> <h2 class="confirm-title" data-astro-cid-zlruecd3>Message sent.</h2> <p class="confirm-sub" data-astro-cid-zlruecd3>We've routed this to <strong x-text="intents.find(i => i.id === intent)?.inbox || 'hello@'" data-astro-cid-zlruecd3></strong> and a named member of the team will reply within two business days.</p> <dl class="confirm-meta" data-astro-cid-zlruecd3> <div data-astro-cid-zlruecd3><dt data-astro-cid-zlruecd3>Routed to</dt><dd x-text="intents.find(i => i.id === intent)?.inbox || 'hello@'" data-astro-cid-zlruecd3></dd></div> <div data-astro-cid-zlruecd3><dt data-astro-cid-zlruecd3>Reply by</dt><dd data-astro-cid-zlruecd3>Within 2 business days</dd></div> <div data-astro-cid-zlruecd3><dt data-astro-cid-zlruecd3>Reference</dt><dd x-text="reference || 'pending'" data-astro-cid-zlruecd3></dd></div> </dl> </div> </template> </div> <div class="form-side" data-astro-cid-zlruecd3> <div x-show="suggestion" x-cloak x-transition.opacity class="form-side-card" style="border-color: var(--blue); background: var(--blue-tint);" data-astro-cid-zlruecd3> <h4 style="color: var(--blue);" data-astro-cid-zlruecd3> <span class="spark" style="margin-right: 6px;" data-astro-cid-zlruecd3>✦</span>
Quick answer
</h4> <p x-text="suggestion" style="color: var(--ink-soft);" data-astro-cid-zlruecd3></p> <div style="margin-top: 12px; font-size: 11px; font-family: var(--font-mono); color: var(--muted);" data-astro-cid-zlruecd3>
Drawn from our FAQ · still sending your message
</div> </div> <div class="form-side-card" data-astro-cid-zlruecd3> <h4 data-astro-cid-zlruecd3>WhatsApp</h4> <p data-astro-cid-zlruecd3>For quick questions and program enquiries.</p> <a href="https://wa.me/2348012345678" target="_blank" rel="noopener noreferrer" style="display: inline-block; margin-top: 12px; font-family: var(--font-mono); font-size: 14px; color: var(--ink);" data-astro-cid-zlruecd3>
+234 903 777 6318 <span aria-hidden="true" data-astro-cid-zlruecd3>↗</span> </a> </div> <div class="form-side-card" data-astro-cid-zlruecd3> <h4 data-astro-cid-zlruecd3>Office</h4> <p style="font-family: var(--font-mono); font-size: 12.5px; line-height: 1.7; color: var(--ink);" data-astro-cid-zlruecd3>
CACENTRE, Egbeda<br data-astro-cid-zlruecd3>
Alimosho LGA, Lagos<br data-astro-cid-zlruecd3>
Nigeria
</p> <p style="margin-top: 12px;" data-astro-cid-zlruecd3>Mon – Fri, 09:00 – 16:00 WAT. Drop-ins welcome with 24h notice.</p> </div> <div class="form-side-card" data-astro-cid-zlruecd3> <h4 data-astro-cid-zlruecd3>Direct inboxes</h4> <ul style="list-style: none; padding: 0; margin: 0; font-family: var(--font-mono); font-size: 12.5px; line-height: 1.8;" data-astro-cid-zlruecd3> <li data-astro-cid-zlruecd3>cacentre@afrovanguard.org.ng</li> <li data-astro-cid-zlruecd3>partnership@afrovanguard.org.ng</li> </ul> </div> </div> </div>  <script>(function(){const intents = [{"id":"press","label":"Press · media","inbox":"cacentre@"},{"id":"partner","label":"Partnership","inbox":"partnership@"},{"id":"volunteer","label":"Volunteer","inbox":"cacentre@"},{"id":"general","label":"General","inbox":"cacentre@"},{"id":"complaint","label":"Concern / complaint","inbox":"cacentre@"}];

  document.addEventListener('alpine:init', () => {
    const FAQ = [
      { re: /next.?intake|when.*(start|begin|enrol|enroll)|application.*open/i,
        a: "Next intakes: Summer School (Jul 2026, apps open May), NextGen Genius Club (Aug 2026, enrolment opens 4 Jul), STREET Storm (Oct 2026, referrals from Sept). LCASP runs continuously through partner schools." },
      { re: /cost|price|how.*much|fees?/i,
        a: "All four programs are free for participants. The cost ledger on /impact shows per-cohort funding — that's what donors and sponsors fund, not families." },
      { re: /location|where.*based|address|office/i,
        a: "Plot 24, Lateef Jakande Way, Alimosho LGA, Lagos. We run programs across Alimosho, Ikeja, Kosofe and Agege LGAs." },
      { re: /volunteer.*how|join.*volunteer|how.*volunteer/i,
        a: "Five-minute intake at /get-involved/volunteer. Most volunteers attach to one program; named reply within 2 business days." },
      { re: /donat|tax.*receipt|deductib/i,
        a: "Donations are processed by Afrovanguard against our published cost ledger. Receipts arrive in your inbox; recurring donors get a quarterly report." },
      { re: /annual.*report|audit|financial/i,
        a: "Our 2024 Annual Impact Report and audited financials are on /impact under Downloads. Raw assessment data is available on request." },
      { re: /partner.*school|school.*partner/i,
        a: "We work with 23 partner schools across 4 LGAs. New schools can express interest via /get-involved/partner — the form drafts a proposal you can edit." },
    ];

    window.Alpine.data('contactForm', () => ({
      name: '', email: '', phone: '', message: '',
      intent: null, userOverride: false, aiLoading: false,
      aiSource: 'local',
      suggestion: '',
      honeypot: '', submitting: false, submitted: false, submitError: '',
      emailInvalid: false, reference: '',
      intents,

      validateEmail() {
        this.emailInvalid = this.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(this.email);
      },
      assist() {
        const t = this.message || '';
        if (t.length < 12) { this.suggestion = ''; return; }
        for (const f of FAQ) { if (f.re.test(t)) { this.suggestion = f.a; return; } }
        this.suggestion = '';
      },
      // Lightweight local heuristic + AI categorisation
      localDetect(t) {
        const l = (t || '').toLowerCase();
        if (/press|journalist|article|interview|magazine/.test(l)) return 'press';
        if (/partner|school|ngo|sponsor.*organi|cor[pn]orate/.test(l)) return 'partner';
        if (/volunteer|teach|mentor|help out/.test(l)) return 'volunteer';
        if (/concern|complaint|safeguard|abuse|incident/.test(l)) return 'complaint';
        if ((t || '').trim().length > 12) return 'general';
        return null;
      },
      async categorize() {
        // Always try the local FAQ assist alongside the route classifier.
        this.assist();
        if (this.userOverride) return;
        const local = this.localDetect(this.message);
        if (local) { this.intent = local; this.aiSource = 'local'; }
        if (!this.message || this.message.length < 20) return;
        this.aiLoading = true;
        try {
          const fd = new FormData();
          fd.append('step', 'categorize');
          fd.append('message', this.message);
          const r = await fetch('/api/contact.php?step=categorize', { method: 'POST', body: fd, credentials: 'include' });
          if (r.ok) {
            const j = await r.json();
            if (j?.category && !this.userOverride) {
              this.intent = j.category === 'partnership' ? 'partner' : j.category;
              this.aiSource = 'ai';
            }
          }
        } catch {} finally { this.aiLoading = false; }
      },
      async submit() {
        this.validateEmail();
        if (this.emailInvalid) return;
        if (this.honeypot) { this.submitted = true; return; }
        this.submitError = '';
        this.submitting = true;
        try {
          const fd = new FormData();
          fd.append('step', 'submit');
          fd.append('csrf_token', document.cookie.match(/sts_csrf=([^;]+)/)?.[1] || '');
          fd.append('full_name', this.name);
          fd.append('email', this.email);
          fd.append('phone', this.phone);
          fd.append('message', this.message);
          fd.append('user_confirmed_category', this.intent || 'general');
          const r = await fetch('/api/contact.php?step=submit', { method: 'POST', body: fd, credentials: 'include' });
          const j = await r.json().catch(() => ({}));
          if (r.ok && j.ok) {
            this.reference = j.reference || '';
            this.submitted = true;
            window.stsToast?.('Message sent — reference #' + (this.reference || 'logged') + '.', 'success');
          } else {
            this.submitError = j.error || 'Something went wrong. Please try again.';
            window.stsToast?.(this.submitError, 'error');
          }
        } catch {
          this.submitError = 'Network error. Please try again.';
          window.stsToast?.(this.submitError, 'error');
        } finally { this.submitting = false; }
      }
    }));
  });
})();</script> </div> </section>
<?php
require $STS_ROOT.'/inc/footer.php';
