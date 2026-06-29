<?php
$STS_ROOT = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($STS_ROOT === '' || !is_file($STS_ROOT.'/inc/head.php')) { $STS_ROOT = __DIR__; while (!is_file($STS_ROOT.'/inc/head.php') && dirname($STS_ROOT) !== $STS_ROOT) $STS_ROOT = dirname($STS_ROOT); }
$PAGE_TITLE = "Sponsor — Street-To-Stardom";
$PAGE_DESC  = "";
$PAGE_PATH  = "/get-involved/sponsor/";
require $STS_ROOT.'/inc/head.php';
?>
<section class="section page-header" data-screen-label="Page header"> <div class="section-inner"> <span class="eyebrow reveal">Sponsor · live impact ledger</span> <h1 class="page-title reveal">Sponsor a child, a cohort, <span class="accent">a school term</span>.</h1> <p class="page-lede reveal">Move the slider; the impact line below shows what that amount translates to from our public cost ledger. Recurring sponsors receive monthly reporting on the children they fund, with photos and metrics from real session logs.</p>  </div> </section> <section class="section form-shell" data-screen-label="Sponsor · Form"> <div class="section-inner"> <div x-data="sponsorForm()" class="form-grid"> <div class="form-card"> <template x-if="!submitted"> <div> <div class="form-stepper-label"> <span>Step 0<span x-text="step + 1"></span> / 04</span> <strong x-text="stepLabels[step]"></strong> </div> <div class="form-stepper"> <template x-for="i in [0,1,2,3]" :key="i"> <span class="step" :class="{ 'done': i < step, 'on': i === step }"></span> </template> </div> <!-- Step 0: Interest --> <div x-show="step === 0" x-transition.opacity x-cloak> <div class="form-step-title">Which program would you like to sponsor?</div> <p class="form-step-sub">Sponsorship is termly; you can switch programs each cycle.</p> <div class="chip-grid" style="margin-bottom: 24px;"> <button type="button" class="chip" :class="program === 'next-gen' ? 'on' : ''" @click="program = 'next-gen'">Next Gen Genius Club</button><button type="button" class="chip" :class="program === 'summer-school' ? 'on' : ''" @click="program = 'summer-school'">Alimosho Summer School</button><button type="button" class="chip" :class="program === 'lcasp' ? 'on' : ''" @click="program = 'lcasp'">LCASP (flagship)</button><button type="button" class="chip" :class="program === 'street-storm' ? 'on' : ''" @click="program = 'street-storm'">STREET Storm</button><button type="button" class="chip" :class="program === 'any' ? 'on' : ''" @click="program = 'any'">Wherever it’s needed most</button> </div> <div class="form-field"> <label class="form-label">How many children would you like to support? <span class="opt">approx</span></label> <input type="number" class="form-input" min="1" max="200" x-model.number="numChildren"> </div> </div> <!-- Step 1: Amount --> <div x-show="step === 1" x-transition.opacity x-cloak> <div class="form-step-title">How much, and how often?</div> <p class="form-step-sub">Tiers are real — not invented. Slide to set your level, or pick a preset.</p> <div class="sponsor-amount"> <div class="sponsor-display">₦<span x-text="amount.toLocaleString()"></span></div> <div class="sponsor-cycle"> <span x-show="cycle === 'monthly'">every month · ₦<span x-text="(amount * 12).toLocaleString()"></span> per year</span> <span x-show="cycle === 'one_time'">one-time</span> <span x-show="cycle === 'quarterly'">every quarter · ₦<span x-text="(amount * 4).toLocaleString()"></span> per year</span> <span x-show="cycle === 'annually'">once per year</span> </div> <input type="range" class="sponsor-slider" min="5000" max="500000" step="1000" x-model.number="amount"> <div class="sponsor-tiers"> <button type="button" class="sponsor-tier" :class="amount === 25000 ? 'on' : ''" @click="amount = 25000"> <div class="sponsor-tier-label">Materials · 2 children</div> <div class="sponsor-tier-amt">₦25k</div> </button><button type="button" class="sponsor-tier" :class="amount === 50000 ? 'on' : ''" @click="amount = 50000"> <div class="sponsor-tier-label">Term · 4 children + sessions</div> <div class="sponsor-tier-amt">₦50k</div> </button><button type="button" class="sponsor-tier" :class="amount === 120000 ? 'on' : ''" @click="amount = 120000"> <div class="sponsor-tier-label">Cohort sponsor</div> <div class="sponsor-tier-amt">₦120k</div> </button><button type="button" class="sponsor-tier" :class="amount === 300000 ? 'on' : ''" @click="amount = 300000"> <div class="sponsor-tier-label">School-level</div> <div class="sponsor-tier-amt">₦300k</div> </button> </div> </div> <div class="seg" style="margin-bottom: 24px;"> <template x-for="c in ['monthly','quarterly','annually','one_time']" :key="c"> <button type="button" @click="cycle = c" class="seg-btn" :class="cycle === c ? 'on' : ''" x-text="c.replace('_', '-')"></button> </template> </div> <div class="sponsor-impact"> <strong x-text="impact.label"></strong>
₦<span x-text="amount.toLocaleString()"></span><span x-show="cycle === 'monthly'">/month</span> = <span x-text="impact.line"></span> </div> </div> <!-- Step 2: Contact --> <div x-show="step === 2" x-transition.opacity x-cloak> <div class="form-step-title">How should we reach you?</div> <p class="form-step-sub">We'll use this to send your sponsorship summary and a payment handoff to Afrovanguard.</p> <div class="form-field"> <label class="form-label">Full name</label> <input class="form-input" type="text" x-model="contact.name" placeholder="As you'd like us to address you"> </div> <div class="form-field"> <label class="form-label">Email</label> <input class="form-input" type="email" x-model="contact.email" placeholder="you@example.org" :class="{ 'invalid': emailInvalid }" @blur="validateEmail()"> <p class="form-help" x-show="emailInvalid" x-cloak style="color: var(--crimson);">Please enter a valid email address.</p> </div> <div class="form-field"> <label class="form-label">Phone <span class="opt">optional</span></label> <input class="form-input" type="tel" x-model="contact.phone" placeholder="+234 …"> </div> <div class="form-field"> <label class="form-label">Organisation <span class="opt">if sponsoring on behalf of one</span></label> <input class="form-input" type="text" x-model="contact.organization"> </div> </div> <!-- Step 3: Review --> <div x-show="step === 3" x-transition.opacity x-cloak> <div class="form-step-title">Review</div> <p class="form-step-sub">Looks right? We'll save this and send you to Afrovanguard's payment page with everything pre-filled.</p> <dl class="confirm-meta" style="margin: 24px 0;"> <div> <dt>Program</dt> <dd x-text="programLabel()"></dd> </div> <div> <dt>Amount</dt> <dd>₦<span x-text="amount.toLocaleString()"></span> <span x-text="cycle === 'one_time' ? '(one time)' : '/ ' + cycle.replace('_','-')"></span></dd> </div> <div> <dt>Contact</dt> <dd x-text="contact.email || '—'"></dd> </div> </dl> </div> <input type="text" name="website" tabindex="-1" autocomplete="off" x-model="honeypot" style="position:absolute; left:-9999px;" aria-hidden="true"> <div class="form-actions"> <button type="button" class="form-back" @click="goBack" :disabled="step === 0" :style="{ opacity: step === 0 ? 0.3 : 1 }">← Back</button> <button type="button" class="btn btn-primary" x-show="step < 3" @click="goNext" :disabled="step === 2 && (!contact.name || !contact.email)" :style="{ opacity: (step === 2 && (!contact.name || !contact.email)) ? 0.5 : 1 }">
Continue <span class="btn-arrow">→</span> </button> <button type="button" class="btn btn-primary" x-show="step === 3" @click="submit" :disabled="submitting" :style="{ opacity: submitting ? 0.5 : 1 }"> <span x-text="submitting ? 'Saving…' : 'Continue to Afrovanguard'"></span> <span class="btn-arrow" x-show="!submitting">→</span> </button> </div> <p class="form-help" x-show="submitError" x-cloak style="color: var(--crimson); margin-top: 12px;" x-text="submitError"></p> </div> </template> <template x-if="submitted"> <div class="confirm-block slide-up" role="status" aria-live="polite"> <div class="confirm-check" aria-hidden="true">✓</div> <h2 class="confirm-title">Saved. Take the next step.</h2> <p class="confirm-sub">We've logged your sponsorship intent. To complete payment, head to Afrovanguard — your selections are pre-filled there.</p> <a :href="handoffUrl" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="margin: 0 auto;">Complete on Afrovanguard <span class="btn-arrow">→</span></a> <dl class="confirm-meta"> <div><dt>Program</dt><dd x-text="programLabel()"></dd></div> <div><dt>Amount</dt><dd>₦<span x-text="amount.toLocaleString()"></span></dd></div> <div><dt>Reply by</dt><dd>Within 2 business days</dd></div> </dl> </div> </template> </div> <div class="form-side"> <div class="form-side-card"> <h4>What sponsors get</h4> <ul class="form-side-list"> <li class="done">A 1-page sponsorship summary PDF (auto-generated)</li> <li class="done">A calendar of upcoming sessions you're funding</li> <li class="done">Monthly report with photos + metrics from real logs</li> <li class="done">Direct line to the program director</li> </ul> </div> <div class="form-side-card"> <h4>Where the money lands</h4> <p>Every sponsor tier maps to a published cost-ledger line. The cost ledger is reviewed monthly by Finance, audited annually.</p> <a href="/impact" style="display: inline-block; margin-top: 12px; font-size: 13px; font-weight: 600; color: var(--blue);">See the cost ledger <span aria-hidden="true">→</span></a> </div> </div> </div> <script>(function(){const programs = [{"id":"next-gen","label":"Next Gen Genius Club"},{"id":"summer-school","label":"Alimosho Summer School"},{"id":"lcasp","label":"LCASP (flagship)"},{"id":"street-storm","label":"STREET Storm"},{"id":"any","label":"Wherever it’s needed most"}];

  document.addEventListener('alpine:init', () => {
    const STORAGE_KEY = 'sts.sponsor.draft';
    const load = () => { try { return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '{}'); } catch { return {}; } };
    const save = (d) => { try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(d)); } catch {} };

    window.Alpine.data('sponsorForm', () => {
      const draft = load();
      return {
        step: 0,
        program: draft.program || 'lcasp',
        numChildren: draft.numChildren || 1,
        amount: draft.amount || 50000,
        cycle: draft.cycle || 'monthly',
        contact: draft.contact || { name: '', email: '', phone: '', organization: '' },
        ledger: [],
        honeypot: '',
        submitting: false,
        submitted: false,
        submitError: '',
        emailInvalid: false,
        handoffUrl: '#',
        stepLabels: ["Program", "Amount", "Contact", "Review"],

        programLabel() {
          const p = programs.find(x => x.id === this.program);
          return p ? p.label : '—';
        },
        get impact() {
          const a = this.amount;
          // Prefer live cost ledger when available — translate the slider into
          // a concrete combination of published programmatic line items.
          if (this.ledger.length > 0) {
            const get = (k) => this.ledger.find(x => x.key === k);
            const matKit  = get('materials_kit_term')?.unit_cost_ngn || 12000;
            const session = get('session_cohort')?.unit_cost_ngn      || 20000;
            const mentor  = get('mentor_session')?.unit_cost_ngn      || 3000;
            const cohort  = get('cohort_term')?.unit_cost_ngn         || 120000;
            const school  = get('school_intervention')?.unit_cost_ngn || 300000;
            if (a >= school) {
              return { label: 'School-level tier', line: `A full school-level intervention (3 facilitators, full term, termly report) for one partner school. ₦${school.toLocaleString()} per cycle from our published ledger.` };
            }
            if (a >= cohort) {
              return { label: 'Cohort tier', line: `A complete cohort of 24 children for one full term — ₦${cohort.toLocaleString()} per cohort from our published ledger.` };
            }
            if (a >= matKit * 4) {
              const kids = Math.floor(a / matKit);
              const sessions = Math.floor((a - kids * matKit) / mentor);
              return { label: 'Term tier', line: `Term materials for ${kids} child${kids === 1 ? '' : 'ren'}${sessions > 0 ? ` + ${sessions} mentorship session${sessions === 1 ? '' : 's'}` : ''} (₦${matKit.toLocaleString()} per child, ₦${mentor.toLocaleString()} per session).` };
            }
            const kids = Math.max(1, Math.floor(a / matKit));
            return { label: 'Materials tier', line: `A literacy materials kit for ${kids} child${kids === 1 ? '' : 'ren'} for one school term. ₦${matKit.toLocaleString()} per child from our published cost ledger.` };
          }
          // Static fallback if /api/cost-ledger.php isn't reachable in dev.
          if (a < 30000) return { label: 'Materials tier', line: `Materials and assessments for ${Math.max(1, Math.round(a/12000))} child(ren) for one school term.` };
          if (a < 75000) return { label: 'Term tier', line: 'One full school term of materials for 4 children + 8 mentorship sessions across LCASP and NextGen.' };
          if (a < 200000) return { label: 'Cohort tier', line: 'A complete cohort of 24 children for one term — facilitators, materials, baseline-endline cycle.' };
          return { label: 'School-level tier', line: 'A school-level intervention: 3 facilitators, full term, termly report for one partner school.' };
        },
        async fetchLedger() {
          try {
            const r = await fetch('/api/cost-ledger.php');
            if (!r.ok) return;
            const j = await r.json();
            if (Array.isArray(j?.ledger)) this.ledger = j.ledger;
          } catch {}
        },
        validateEmail() {
          this.emailInvalid = this.contact.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(this.contact.email);
        },
        persist() {
          save({ program: this.program, numChildren: this.numChildren, amount: this.amount, cycle: this.cycle, contact: this.contact });
        },
        goNext() {
          this.step = Math.min(this.step + 1, 3);
          this.persist();
        },
        goBack() {
          this.step = Math.max(0, this.step - 1);
        },
        async submit() {
          this.validateEmail();
          if (this.emailInvalid) return;
          if (this.honeypot) { this.submitted = true; this.handoffUrl = '#'; return; }
          this.submitError = '';
          this.submitting = true;
          try {
            const fd = new FormData();
            fd.append('csrf_token', document.cookie.match(/sts_csrf=([^;]+)/)?.[1] || '');
            fd.append('full_name', this.contact.name);
            fd.append('email', this.contact.email);
            fd.append('phone', this.contact.phone);
            fd.append('organization', this.contact.organization);
            fd.append('intended_amount_ngn', String(this.amount));
            fd.append('frequency', this.cycle);
            fd.append('selected_program', this.program);
            const r = await fetch('/api/sponsor-inquiry.php', { method: 'POST', body: fd, credentials: 'include' });
            const j = await r.json().catch(() => ({}));
            if (r.ok && j.ok) {
              this.handoffUrl = j.handoff_url || '#';
              this.submitted = true;
              try { sessionStorage.removeItem(STORAGE_KEY); } catch {}
              window.stsToast?.('Saved — your sponsorship is pre-filled on Afrovanguard.', 'success');
            } else {
              this.submitError = j.error || 'Something went wrong. Please try again.';
              window.stsToast?.(this.submitError, 'error');
            }
          } catch {
            this.submitError = 'Network error. Please try again.';
            window.stsToast?.(this.submitError, 'error');
          } finally {
            this.submitting = false;
          }
        },
        init() {
          this.fetchLedger();
          this.$watch('amount', () => this.persist());
          this.$watch('cycle', () => this.persist());
          this.$watch('program', () => this.persist());
          this.$watch('contact', () => this.persist());
        }
      };
    });
  });
})();</script> </div> </section>
<?php
require $STS_ROOT.'/inc/footer.php';
