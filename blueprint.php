<?php
/**
 * blueprint.php — "Continental Scaling Plan to 1,000,000 C-Level Leaders".
 * The internal strategic plan of the Afrovanguard ecosystem: the 2026–2040
 * phase-by-phase path to one million incorruptible (C-Level) leaders, driven
 * by the CACENTRE production model and the O → A → B → C mentorship chain.
 *
 * MEMBERS ONLY. This document is restricted to verified @afrovanguard members
 * (org accounts and ranked leaders). Learners and the public never see the
 * body — they get the sign-in / access gate instead. Served at /blueprint.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$u     = LmsAuth::user();
$isOrg = LmsAuth::isOrgMember($u);

render_head([
    'title'     => 'Continental Scaling Plan — Afrovanguard',
    'desc'      => 'The Afrovanguard 2026–2040 continental scaling plan: the phase-by-phase path to one million incorruptible C-Level leaders. Restricted to Afrovanguard members.',
    'canonical' => rtrim(SITE_URL, '/') . '/blueprint',
    'robots'    => 'noindex, nofollow',
    'body_class' => 'bp-page',
]);
render_nav('about');
?>
<style>
  .bp{--bp-line:var(--afg-border,#e5e7eb);color:var(--afg-body,#374151);
    font-family:var(--afg-font-body,'Montserrat',system-ui,sans-serif)}
  .bp .bp-in{max-width:940px;margin:0 auto;padding:0 20px}

  /* ---- Access gate (non-members) ---- */
  .bp-gate{min-height:52vh;display:flex;align-items:center;justify-content:center;
    background:var(--afg-surface-2,#f4f2ec);border-bottom:1px solid var(--afg-border,#e5e7eb);padding:64px 20px}
  .bp-gate-card{max-width:520px;text-align:center;background:var(--afg-surface,#fff);
    border:1px solid var(--afg-border,#e5e7eb);border-radius:var(--afg-radius-md,14px);
    padding:40px 36px;box-shadow:var(--afg-shadow-sm,0 10px 30px -20px rgba(17,24,39,.28))}
  .bp-gate-badge{display:inline-block;font-size:11px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;
    color:var(--afg-accent-ink,#b07e08);background:var(--afg-surface-2,#f4f2ec);border-radius:999px;padding:6px 14px;margin-bottom:16px}
  .bp-gate-card h1{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-weight:700;
    font-size:clamp(26px,4vw,34px);line-height:1.12;margin:0 0 12px;color:var(--afg-ink,#111827)}
  .bp-gate-card p{font-size:15px;line-height:1.65;margin:0 0 22px;color:var(--afg-muted,#6b7280)}
  .bp-gate-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
  .bp-btn{display:inline-flex;align-items:center;gap:8px;font-weight:700;font-size:14px;
    border-radius:999px;padding:12px 22px;text-decoration:none;border:1px solid transparent;cursor:pointer}
  .bp-btn-gold{background:var(--afg-accent,#f3b416);color:#1a1300}
  .bp-btn-ghost{background:transparent;border-color:var(--afg-border,#e5e7eb);color:var(--afg-ink,#111827)}

  /* ---- Hero ---- */
  .bp-hero{background:var(--afg-surface-2,#f4f2ec);border-bottom:1px solid var(--afg-border,#e5e7eb);padding:10px 20px 44px}
  .bp-hero .bp-in{max-width:940px}
  .bp-kicker{display:inline-flex;align-items:center;gap:8px;font-size:11px;font-weight:800;letter-spacing:.09em;
    text-transform:uppercase;color:var(--afg-accent-ink,#b07e08);margin:8px 0 10px}
  .bp-kicker .bp-lock{font-size:13px}
  .bp-hero h1{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-weight:700;
    font-size:clamp(30px,5vw,46px);line-height:1.06;margin:0 0 12px;color:var(--afg-ink,#111827);max-width:760px}
  .bp-lead{font-size:17px;line-height:1.65;max-width:680px;margin:0;color:var(--afg-body,#374151)}
  .bp-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px}
  .bp-tag{font-size:12px;font-weight:700;background:var(--afg-surface,#fff);border:1px solid var(--afg-border,#e5e7eb);
    border-radius:999px;padding:6px 13px;color:var(--afg-ink,#111827)}

  /* ---- Layout: sticky TOC + body ---- */
  .bp-wrap{padding:8px 0 80px}
  .bp-grid{display:grid;grid-template-columns:230px 1fr;gap:40px;align-items:start;max-width:1080px;margin:0 auto;padding:0 20px}
  .bp-toc{position:sticky;top:88px;font-size:13px}
  .bp-toc-title{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--afg-muted,#6b7280);margin:0 0 10px}
  .bp-toc a{display:block;padding:6px 10px;border-radius:8px;color:var(--afg-body,#374151);text-decoration:none;line-height:1.35;border-left:2px solid transparent}
  .bp-toc a:hover{background:var(--afg-surface-2,#f4f2ec)}
  .bp-toc a.is-active{color:var(--afg-accent-ink,#b07e08);font-weight:700;border-left-color:var(--afg-accent,#f3b416);background:var(--afg-surface-2,#f4f2ec)}

  .bp-doc{min-width:0}
  .bp-part{padding:36px 0 0;scroll-margin-top:88px}
  .bp-part.first{padding-top:12px}
  .bp-part-eyebrow{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-weight:700;font-size:15px;color:var(--afg-accent-ink,#b07e08)}
  .bp-part h2{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-weight:700;
    font-size:clamp(24px,3.4vw,32px);line-height:1.12;margin:4px 0 8px;color:var(--afg-ink,#111827)}
  .bp-part > p{font-size:15.5px;line-height:1.7;margin:0 0 14px}
  .bp-part h3{font-size:17px;font-weight:800;margin:26px 0 10px;color:var(--afg-ink,#111827)}

  .bp-card{background:var(--afg-surface,#fff);border:1px solid var(--afg-border,#e5e7eb);
    border-radius:var(--afg-radius-md,14px);padding:18px 20px;margin:12px 0 0;
    box-shadow:var(--afg-shadow-sm,0 10px 30px -20px rgba(17,24,39,.28))}
  .bp-list{margin:8px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:10px}
  .bp-list li{position:relative;padding-left:24px;font-size:15px;line-height:1.6}
  .bp-list li::before{content:"";position:absolute;left:2px;top:9px;width:7px;height:7px;border-radius:50%;background:var(--afg-accent,#f3b416)}
  .bp-list strong{color:var(--afg-ink,#111827);font-weight:800}
  .bp-list.is-num{counter-reset:bpn}
  .bp-list.is-num li{padding-left:34px}
  .bp-list.is-num li::before{content:counter(bpn);counter-increment:bpn;background:var(--afg-accent,#f3b416);
    color:#1a1300;width:20px;height:20px;border-radius:50%;font-size:11px;font-weight:800;
    display:flex;align-items:center;justify-content:center;left:0;top:1px}

  /* callout */
  .bp-callout{border-left:4px solid var(--afg-accent,#f3b416);background:var(--afg-surface-2,#f4f2ec);
    border-radius:0 12px 12px 0;padding:16px 20px;margin:16px 0 0;font-size:15px;line-height:1.65}
  .bp-callout.is-danger{border-left-color:#dc2626}

  /* tables (scaling plan) */
  .bp-tablewrap{overflow-x:auto;margin:12px 0 0;border:1px solid var(--afg-border,#e5e7eb);border-radius:12px}
  table.bp-table{width:100%;border-collapse:collapse;font-size:14px;min-width:420px}
  table.bp-table th,table.bp-table td{text-align:left;padding:10px 14px;border-bottom:1px solid var(--afg-border,#e5e7eb)}
  table.bp-table thead th{background:var(--afg-surface-2,#f4f2ec);font-size:12px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:var(--afg-muted,#6b7280)}
  table.bp-table tbody tr:last-child td{border-bottom:none}
  table.bp-table td.bp-num{font-variant-numeric:tabular-nums;font-weight:700;color:var(--afg-ink,#111827)}
  table.bp-table tr.bp-milestone td{background:rgba(243,180,22,.12);font-weight:800;color:var(--afg-ink,#111827)}

  /* phase heading */
  .bp-phase{margin:28px 0 0}
  .bp-phase-tag{font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--afg-accent-ink,#b07e08)}
  .bp-phase h3{margin:2px 0 4px}
  .bp-phase .bp-goal{font-size:13.5px;font-weight:700;color:var(--afg-muted,#6b7280);margin:0 0 4px}

  /* three-up cards (structure, engine) */
  .bp-threeup{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:12px 0 0}
  .bp-mini{background:var(--afg-surface-2,#f4f2ec);border-radius:12px;padding:16px 15px}
  .bp-mini .bp-mini-ic{font-size:22px;display:block;margin-bottom:6px}
  .bp-mini h4{font-size:14px;margin:0 0 4px;color:var(--afg-ink,#111827)}
  .bp-mini p{font-size:13px;line-height:1.5;margin:0;color:var(--afg-body,#374151)}

  @media (max-width:820px){
    .bp-grid{grid-template-columns:1fr;gap:0}
    .bp-toc{display:none}
    .bp-threeup{grid-template-columns:1fr}
  }
  body.is-dark .bp-card,body.is-dark table.bp-table{background:var(--afg-surface,#fff)}
</style>

<?php if (!$isOrg): ?>
  <!-- ===================== ACCESS GATE (non-members) ===================== -->
  <main id="main-content" class="bp">
    <section class="bp-gate">
      <div class="bp-gate-card">
        <span class="bp-gate-badge">🔒 Members only</span>
        <h1>This is an Afrovanguard members' document</h1>
<?php if (!$u): ?>
        <p>The Continental Scaling Plan is restricted to verified Afrovanguard members. Sign in with your <strong>@afrovanguard.org.ng</strong> account to read it.</p>
        <div class="bp-gate-actions">
          <a class="bp-btn bp-btn-gold" href="<?= e(av_login_url('/blueprint')) ?>">Sign in →</a>
          <a class="bp-btn bp-btn-ghost" href="/">Back to site</a>
        </div>
<?php else: ?>
        <p>You’re signed in as <strong><?= e((string) $u['email']) ?></strong>, but this plan is reserved for Afrovanguard members. If you believe you should have access, contact the executive channel at <a href="mailto:cacentre@afrovanguard.org.ng">cacentre@afrovanguard.org.ng</a>.</p>
        <div class="bp-gate-actions">
          <a class="bp-btn bp-btn-gold" href="/portal/">Go to your portal →</a>
          <a class="bp-btn bp-btn-ghost" href="/">Back to site</a>
        </div>
<?php endif; ?>
      </div>
    </section>
  </main>
<?php else: ?>
  <!-- ===================== MEMBERS' DOCUMENT ===================== -->
  <main id="main-content" class="bp">
    <header class="bp-hero">
      <div class="bp-in">
        <p class="bp-kicker"><span class="bp-lock">🔒</span> Members only · The Governing Council</p>
        <h1>Continental Scaling Plan to 1,000,000 C-Level Leaders</h1>
        <p class="bp-lead">The internal strategic plan of the Afrovanguard ecosystem — the phase-by-phase path (2026–2040) to one million incorruptible leaders, driven by the CACENTRE production model.</p>
        <div class="bp-meta">
          <span class="bp-tag">Authority: The Afrovanguard Governing Council</span>
          <span class="bp-tag">Executive channel: cacentre@afrovanguard.org.ng</span>
          <span class="bp-tag">Restricted circulation</span>
        </div>
      </div>
    </header>

    <div class="bp-wrap">
      <div class="bp-grid">

        <!-- TOC -->
        <nav class="bp-toc" aria-label="On this page">
          <p class="bp-toc-title">On this page</p>
          <a href="#principles">Growth principles</a>
          <a href="#phase1">Phase 1 · Foundation</a>
          <a href="#phase2">Phase 2 · Nigeria</a>
          <a href="#phase3">Phase 3 · West Africa</a>
          <a href="#phase4">Phase 4 · Continental</a>
          <a href="#cacentre">One CACENTRE’s output</a>
          <a href="#structure">Org structure</a>
          <a href="#engine">The cultural engine</a>
        </nav>

        <!-- DOCUMENT -->
        <article class="bp-doc">

          <!-- ============ CONTINENTAL SCALING PLAN ============ -->
          <section class="bp-part first" id="principles">
            <p class="bp-part-eyebrow">Strategic Plan</p>
            <h2>The road to one million incorruptible leaders</h2>
            <p>To reach one million <strong>C-Level (Incorruptible) Vanguards</strong> by 2040, the path is structured exponential growth — not random expansion. The engine of that growth is the <strong>CACENTRE</strong>, because every centre becomes a leadership production hub.</p>

            <div class="bp-card">
              <h3 style="margin-top:0">The three growth principles</h3>
              <ul class="bp-list">
                <li>Each CACENTRE produces new C-Level leaders every year.</li>
                <li>Each C-Level maintains the <strong>O → A → B → C</strong> mentorship chain.</li>
                <li>New CACENTREs are opened by mature C- and D-level leaders.</li>
              </ul>
            </div>

            <h3>Planning assumptions</h3>
            <ul class="bp-list">
              <li>A mature CACENTRE produces <strong>100–200 C-Level leaders yearly</strong>.</li>
              <li>A new centre takes <strong>2–3 years to mature</strong>.</li>
              <li>Expansion accelerates once strong systems and culture are built.</li>
            </ul>
          </section>

          <!-- Phase 1 -->
          <section class="bp-part" id="phase1">
            <div class="bp-phase" style="margin-top:0">
              <span class="bp-phase-tag">Phase 1 · 2026–2028</span>
              <h3>Foundation</h3>
              <p class="bp-goal">Goal: perfect the model before scaling.</p>
              <p>Build the flagship CACENTRE, finalize the mentorship systems, launch digital tracking, and train the first national leaders. This stage is about culture and system integrity — curriculum stabilized, leadership ladder tested, technology tracking created.</p>
              <div class="bp-tablewrap">
                <table class="bp-table">
                  <thead><tr><th>Year</th><th>CACENTREs</th><th>New C-Level</th><th>Total C-Level</th></tr></thead>
                  <tbody>
                    <tr><td>2026</td><td class="bp-num">1</td><td class="bp-num">20</td><td class="bp-num">20</td></tr>
                    <tr><td>2027</td><td class="bp-num">5</td><td class="bp-num">150</td><td class="bp-num">170</td></tr>
                    <tr><td>2028</td><td class="bp-num">20</td><td class="bp-num">800</td><td class="bp-num">970</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </section>

          <!-- Phase 2 -->
          <section class="bp-part" id="phase2">
            <div class="bp-phase" style="margin-top:0">
              <span class="bp-phase-tag">Phase 2 · 2029–2032</span>
              <h3>Nigeria Expansion</h3>
              <p class="bp-goal">Goal: establish national presence.</p>
              <p>Nigeria alone can produce a massive base thanks to its population and youth energy. Afrovanguard becomes nationally recognized, hundreds of Level C leaders begin mentoring, and the CACENTRE model becomes fully replicable.</p>
              <div class="bp-tablewrap">
                <table class="bp-table">
                  <thead><tr><th>Year</th><th>CACENTREs</th><th>New C-Level</th><th>Total C-Level</th></tr></thead>
                  <tbody>
                    <tr><td>2029</td><td class="bp-num">50</td><td class="bp-num">3,000</td><td class="bp-num">3,970</td></tr>
                    <tr><td>2030</td><td class="bp-num">100</td><td class="bp-num">10,000</td><td class="bp-num">13,970</td></tr>
                    <tr><td>2031</td><td class="bp-num">180</td><td class="bp-num">25,000</td><td class="bp-num">38,970</td></tr>
                    <tr><td>2032</td><td class="bp-num">250</td><td class="bp-num">60,000</td><td class="bp-num">98,970</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </section>

          <!-- Phase 3 -->
          <section class="bp-part" id="phase3">
            <div class="bp-phase" style="margin-top:0">
              <span class="bp-phase-tag">Phase 3 · 2033–2036</span>
              <h3>West Africa Expansion</h3>
              <p class="bp-goal">Target countries: Ghana, Benin, Togo, Côte d’Ivoire, Senegal.</p>
              <p>By this stage the mentorship chain is fully operational, thousands of leaders are mentoring O–A–B generations, and growth becomes network-driven.</p>
              <div class="bp-tablewrap">
                <table class="bp-table">
                  <thead><tr><th>Year</th><th>CACENTREs</th><th>New C-Level</th><th>Total C-Level</th></tr></thead>
                  <tbody>
                    <tr><td>2033</td><td class="bp-num">350</td><td class="bp-num">90,000</td><td class="bp-num">188,970</td></tr>
                    <tr><td>2034</td><td class="bp-num">500</td><td class="bp-num">120,000</td><td class="bp-num">308,970</td></tr>
                    <tr><td>2035</td><td class="bp-num">700</td><td class="bp-num">170,000</td><td class="bp-num">478,970</td></tr>
                    <tr><td>2036</td><td class="bp-num">900</td><td class="bp-num">200,000</td><td class="bp-num">678,970</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </section>

          <!-- Phase 4 -->
          <section class="bp-part" id="phase4">
            <div class="bp-phase" style="margin-top:0">
              <span class="bp-phase-tag">Phase 4 · 2037–2040</span>
              <h3>Continental Expansion</h3>
              <p class="bp-goal">Target regions: East, Southern &amp; North Africa — Kenya, Rwanda, South Africa, Ethiopia, Egypt, Tanzania.</p>
              <div class="bp-tablewrap">
                <table class="bp-table">
                  <thead><tr><th>Year</th><th>CACENTREs</th><th>New C-Level</th><th>Total C-Level</th></tr></thead>
                  <tbody>
                    <tr><td>2037</td><td class="bp-num">1,200</td><td class="bp-num">120,000</td><td class="bp-num">798,970</td></tr>
                    <tr><td>2038</td><td class="bp-num">1,500</td><td class="bp-num">100,000</td><td class="bp-num">898,970</td></tr>
                    <tr><td>2039</td><td class="bp-num">1,800</td><td class="bp-num">80,000</td><td class="bp-num">978,970</td></tr>
                    <tr class="bp-milestone"><td>2040</td><td class="bp-num">2,000</td><td class="bp-num">25,000</td><td class="bp-num">1,003,970</td></tr>
                  </tbody>
                </table>
              </div>
              <div class="bp-callout">🎯 <strong>Target achieved: 1 million C-Level leaders.</strong></div>
            </div>
          </section>

          <!-- CACENTRE output -->
          <section class="bp-part" id="cacentre">
            <h3 style="margin-top:0">What one mature CACENTRE must produce yearly</h3>
            <div class="bp-tablewrap">
              <table class="bp-table">
                <thead><tr><th>Level</th><th>Annual output</th></tr></thead>
                <tbody>
                  <tr><td>O-Level</td><td class="bp-num">200</td></tr>
                  <tr><td>A-Level</td><td class="bp-num">100</td></tr>
                  <tr><td>B-Level</td><td class="bp-num">50</td></tr>
                  <tr><td>C-Level (new)</td><td class="bp-num">20–50</td></tr>
                </tbody>
              </table>
            </div>
            <p>With the O → A → B → C ladder, leaders are constantly rising.</p>
          </section>

          <!-- Structure -->
          <section class="bp-part" id="structure">
            <h3 style="margin-top:0">Organizational structure needed to manage 2,000 CACENTREs</h3>
            <ol class="bp-list is-num">
              <li><strong>Continental Leadership Council</strong> — guides ideology and structure.</li>
              <li><strong>Regional Directors</strong> — West, East, North and Southern Africa.</li>
              <li><strong>National Command Teams</strong> — oversee CACENTRE networks.</li>
              <li><strong>CACENTRE Directors</strong> — run each centre daily.</li>
            </ol>

            <h3>Technology backbone</h3>
            <p>The website and digital system must eventually track every input that prevents corruption as the network grows:</p>
            <ul class="bp-list">
              <li>O, A, B, C member status</li>
              <li>Book club participation</li>
              <li>Courses completed</li>
              <li>Mentorship relationships</li>
              <li>Leadership scorecard</li>
            </ul>
          </section>

          <!-- Cultural engine -->
          <section class="bp-part" id="engine">
            <h3 style="margin-top:0">The cultural engine — three things that must never stop</h3>
            <div class="bp-threeup">
              <div class="bp-mini"><span class="bp-mini-ic">📚</span><h4>Book Club</h4><p>12 books yearly, across every CACENTRE.</p></div>
              <div class="bp-mini"><span class="bp-mini-ic">🧠</span><h4>Courses &amp; tech learning</h4><p>Continuous skill and technology development.</p></div>
              <div class="bp-mini"><span class="bp-mini-ic">🤝</span><h4>Mentorship chain</h4><p>The unbroken O → A → B → C ladder.</p></div>
            </div>
            <p>Together these produce intellectual, moral and practical leaders.</p>

            <div class="bp-callout is-danger">
              <strong>Reality check.</strong> The biggest danger is scaling faster than culture. If standards weaken you may reach a million members — but not a million <em>incorruptible</em> leaders. The rule is absolute: <strong>integrity before expansion.</strong>
            </div>
          </section>

        </article>
      </div>
    </div>
  </main>

  <script>
  /* Blueprint TOC scroll-spy — highlight the section in view. */
  (function () {
    var links = Array.prototype.slice.call(document.querySelectorAll('.bp-toc a'));
    if (!links.length || !('IntersectionObserver' in window)) return;
    var byId = {};
    links.forEach(function (a) { byId[a.getAttribute('href').slice(1)] = a; });
    var obs = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          links.forEach(function (l) { l.classList.remove('is-active'); });
          var a = byId[en.target.id];
          if (a) a.classList.add('is-active');
        }
      });
    }, { rootMargin: '-15% 0px -75% 0px', threshold: 0 });
    document.querySelectorAll('.bp-part[id]').forEach(function (s) { obs.observe(s); });
  })();
  </script>
<?php endif; ?>

<?php render_footer(); ?>
