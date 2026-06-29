<?php
$STS_ROOT = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($STS_ROOT === '' || !is_file($STS_ROOT.'/inc/head.php')) { $STS_ROOT = __DIR__; while (!is_file($STS_ROOT.'/inc/head.php') && dirname($STS_ROOT) !== $STS_ROOT) $STS_ROOT = dirname($STS_ROOT); }
$PAGE_TITLE = "Donate — Street-To-Stardom";
$PAGE_DESC  = "";
$PAGE_PATH  = "/get-involved/donate/";
require $STS_ROOT.'/inc/head.php';
?>
<section class="section page-header" data-screen-label="Page header"> <div class="section-inner"> <span class="eyebrow reveal">Donate · transparent ledger</span> <h1 class="page-title reveal">Every donation, <span class="accent">routed against published costs</span>.</h1> <p class="page-lede reveal">Donations are processed by our parent organisation Afrovanguard against STS&#39;s published cost ledger. Receipts arrive in your inbox; recurring donors receive a quarterly report on the sessions their money funded.</p>  </div> </section> <section class="section form-shell" data-screen-label="Donate · Handoff" x-data="donateHandoff()"> <div class="section-inner"> <div class="form-grid"> <div class="form-card"> <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 24px; flex-wrap: wrap;"> <span style="font-family: var(--font-mono); font-size: 11px; color: var(--muted); letter-spacing: 0.08em; text-transform: uppercase;">
Currency · <span x-text="currencyAutoDetected ? 'auto-detected' : 'set'"></span> </span> <div class="seg"> <template x-for="c in ['NGN','USD','GBP','EUR']" :key="c"> <button type="button" class="seg-btn" :class="currency === c ? 'on' : ''" @click="currency = c; currencyAutoDetected = false" style="font-family: var(--font-mono); font-size: 11px; padding: 6px 12px;" x-text="c"></button> </template> </div> <div class="seg" style="margin-left: auto;"> <template x-for="c in ['once','monthly']" :key="c"> <button type="button" class="seg-btn" :class="cycle === c ? 'on' : ''" @click="cycle = c" x-text="c"></button> </template> </div> </div> <div class="sponsor-amount"> <div class="sponsor-display"> <span x-text="symbols[currency]"></span><span x-text="display.toLocaleString()"></span> </div> <div class="sponsor-cycle"> <span x-show="cycle === 'monthly'">every month · <span x-text="symbols[currency]"></span><span x-text="(display*12).toLocaleString()"></span> per year</span> <span x-show="cycle === 'once'">one-time donation</span> </div> <input type="range" class="sponsor-slider" min="1000" max="500000" step="500" x-model.number="amount"> <div class="sponsor-tiers"> <button type="button" class="sponsor-tier" :class="amount === 5000 ? 'on' : ''" @click="amount = 5000"> <div class="sponsor-tier-label">Preset</div> <div class="sponsor-tier-amt">₦5k</div> </button><button type="button" class="sponsor-tier" :class="amount === 20000 ? 'on' : ''" @click="amount = 20000"> <div class="sponsor-tier-label">Preset</div> <div class="sponsor-tier-amt">₦20k</div> </button><button type="button" class="sponsor-tier" :class="amount === 50000 ? 'on' : ''" @click="amount = 50000"> <div class="sponsor-tier-label">Preset</div> <div class="sponsor-tier-amt">₦50k</div> </button><button type="button" class="sponsor-tier" :class="amount === 200000 ? 'on' : ''" @click="amount = 200000"> <div class="sponsor-tier-label">Preset</div> <div class="sponsor-tier-amt">₦200k</div> </button> </div> </div> <div class="sponsor-impact"> <strong>What this funds</strong> <span x-text="impactLine"></span> </div> <div style="margin-top: 32px; display: flex; flex-direction: column; gap: 16px;"> <div class="form-field"> <label class="form-label">Email <span class="opt">for receipt</span></label> <input type="email" class="form-input" placeholder="you@example.org" x-model="email"> </div> <label style="display: flex; gap: 12px; align-items: center; font-size: 13px; color: var(--ink-soft);"> <input type="checkbox" x-model="newsletter"> Send me the quarterly field-notes newsletter (opt-in)
</label> </div> <div class="form-actions"> <span style="font-family: var(--font-mono); font-size: 11px; color: var(--muted); letter-spacing: 0.04em;">
Processed by Afrovanguard · secure · refundable within 14 days
</span> <a :href="handoffUrl" target="_blank" rel="noopener noreferrer" class="btn btn-primary"> <span>Donate <span x-text="symbols[currency]"></span><span x-text="display.toLocaleString()"></span><span x-show="cycle === 'monthly'">/mo</span> via Afrovanguard</span> <span class="btn-arrow">→</span> </a> </div> </div> <div class="form-side"> <div class="form-side-card"> <h4>Where your money goes · 2024</h4> <ul class="form-side-list"> <li class="done"><span>Programs · <strong style="color: var(--ink);">83%</strong></span></li><li class="done"><span>Safeguarding · <strong style="color: var(--ink);">5%</strong></span></li><li class="done"><span>Research &amp; reporting · <strong style="color: var(--ink);">7%</strong></span></li><li class="done"><span>Admin · <strong style="color: var(--ink);">5%</strong></span></li> </ul> <p style="margin-top: 16px;">Ratio audited annually. Full cost ledger in the impact report.</p> </div> <div class="form-side-card"> <h4>Recurring donors</h4> <p>Monthly donors receive a quarterly report with photos and per-cohort metrics from the sessions they funded — generated from real program logs, not templated.</p> </div> </div> </div> </div> </section> <script>
    document.addEventListener('alpine:init', () => {
      window.Alpine.data('donateHandoff', () => ({
        amount: 20000,
        cycle: 'once',
        currency: 'NGN',
        email: '',
        newsletter: false,
        currencyAutoDetected: true,
        symbols: { NGN: '₦', USD: '$', GBP: '£', EUR: '€' },
        rates: { NGN: 1, USD: 1/1500, GBP: 1/1900, EUR: 1/1650 },
        get display() { return Math.round(this.amount * this.rates[this.currency]); },
        get impactLine() {
          const a = this.amount;
          if (a < 10000) return 'A literacy materials kit for 1 child for a school term (workbooks, stationery, printed assessments).';
          if (a < 35000) return 'A full STS session for one cohort of 24 children — facilitator stipend, materials, and meal.';
          if (a < 100000) return 'A full school term of materials and 8 mentor sessions for 4 children in the LCASP program.';
          if (a < 250000) return 'A school-level intervention: 3 facilitators, baseline-endline cycle, and termly report for one partner school.';
          return 'A full cohort of NextGen Genius Club for a year, including the public exhibition at term-end.';
        },
        get handoffUrl() {
          const base = 'https://afrovanguard.org.ng/donate';
          const params = new URLSearchParams({
            source: 'sts',
            amount: String(this.amount),
            currency: this.currency,
            frequency: this.cycle === 'monthly' ? 'monthly' : 'one_time',
            email: this.email || '',
            newsletter: this.newsletter ? '1' : '0',
          });
          return `${base}?${params.toString()}`;
        },
        async init() {
          try {
            const r = await fetch('/api/geo.php');
            const j = await r.json();
            if (j?.currency && ['NGN','USD','GBP','EUR'].includes(j.currency)) this.currency = j.currency;
          } catch {}
        }
      }));
    });
  </script>
<?php
require $STS_ROOT.'/inc/footer.php';
