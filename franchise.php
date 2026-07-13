<?php
/**
 * franchise.php — "Afrovanguard Social Franchise & Governance Framework".
 * The public framework for establishing Community Advancement Centres
 * (CACENTREs) and running Afrovanguard flagship projects (LCASP, BEC,
 * Africa GATES, Street-To-Stardom) under one vision and one standard.
 * Served at /franchise.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$applyMail = 'mailto:cacentre@afrovanguard.org.ng?subject=' . rawurlencode('Afrovanguard Social Franchise — Expression of Interest');

render_head([
    'title'      => 'Social Franchise & Governance Framework — Afrovanguard',
    'desc'       => 'How competent leaders establish Afrovanguard Community Advancement Centres (CACENTREs) and run flagship projects — LCASP, BEC, Africa GATES, Street-To-Stardom — under one vision, one governance framework and one operational standard.',
    'canonical'  => rtrim(SITE_URL, '/') . '/franchise',
    'body_class' => 'fr-page',
]);
render_nav('about');
?>
<style>
  .fr{--fr-line:var(--afg-border,#e5e7eb);color:var(--afg-body,#374151);
    font-family:var(--afg-font-body,'Montserrat',system-ui,sans-serif)}
  .fr .fr-in{max-width:940px;margin:0 auto;padding:0 20px}
  .fr-hero{background:var(--afg-surface-2,#f4f2ec);border-bottom:1px solid var(--afg-border,#e5e7eb);padding:8px 20px 48px}
  .fr-hero .page-lead{max-width:680px}
  .fr-hero-sub{font-size:14px;font-weight:700;letter-spacing:.02em;color:var(--afg-muted,#6b7280);margin:10px 0 0}
  .fr-body{padding:16px 0 72px}
  /* section rhythm */
  .fr-sec{padding:40px 0 0;scroll-margin-top:96px}
  .fr-sec.first{padding-top:44px}
  .fr-num{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-weight:700;font-size:15px;color:var(--afg-accent-ink,#b07e08)}
  .fr-sec h2{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-weight:700;font-size:clamp(26px,3.4vw,34px);line-height:1.1;margin:4px 0 14px;color:var(--afg-ink,#111827)}
  .fr-sec > p{font-size:16px;line-height:1.7;margin:0 0 14px}
  .fr-sec > p:last-child{margin-bottom:0}
  /* cards + lists */
  .fr-card{background:var(--afg-surface,#fff);border:1px solid var(--afg-border,#e5e7eb);border-radius:var(--afg-radius-md,14px);padding:20px 22px;margin:14px 0 0;box-shadow:var(--afg-shadow-sm,0 10px 30px -20px rgba(17,24,39,.28))}
  .fr-card h3{font-size:14px;font-weight:800;letter-spacing:.02em;margin:0 0 12px;color:var(--afg-ink,#111827)}
  .fr-list{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:9px}
  .fr-list li{position:relative;padding-left:26px;font-size:15px;line-height:1.55}
  .fr-list li::before{content:"";position:absolute;left:2px;top:8px;width:7px;height:7px;border-radius:50%;background:var(--afg-accent,#f3b416)}
  .fr-list.is-check li::before{content:"✓";left:0;top:0;width:auto;height:auto;background:none;color:var(--afg-success,#16a34a);font-weight:800}
  .fr-list strong{color:var(--afg-ink,#111827);font-weight:700}
  /* two-column split (provides / responsibilities, adapt / never) */
  .fr-split{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:14px 0 0}
  .fr-split .fr-card{margin:0}
  .fr-note{font-size:14px;line-height:1.6;color:var(--afg-muted,#6b7280);margin:14px 0 0}
  .fr-note strong{color:var(--afg-ink,#111827)}
  /* pillars strip */
  .fr-pillars{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0 0}
  .fr-pillar{background:var(--afg-surface-2,#f4f2ec);border-radius:var(--afg-radius-sm,10px);padding:16px 14px;text-align:center;font-weight:700;font-size:14px;color:var(--afg-ink,#111827)}
  .fr-pillar span{display:block;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--afg-accent-ink,#b07e08);margin-bottom:4px}
  /* flagship project cards */
  .fr-flags{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:16px 0 0}
  .fr-flag{background:var(--afg-surface,#fff);border:1px solid var(--afg-border,#e5e7eb);border-top:3px solid var(--afg-accent,#f3b416);border-radius:var(--afg-radius-md,14px);padding:20px 20px 22px;box-shadow:var(--afg-shadow-sm,0 10px 30px -20px rgba(17,24,39,.28))}
  .fr-flag h3{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-weight:700;font-size:21px;margin:0 0 2px;color:var(--afg-ink,#111827)}
  .fr-flag .fr-flag-sub{font-size:12px;font-weight:700;color:var(--afg-muted,#6b7280);margin:0 0 12px}
  .fr-flag .fr-list li{font-size:14px}
  /* system chips */
  .fr-chips{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 0}
  .fr-chip{font-size:13px;font-weight:600;color:var(--afg-body,#374151);background:var(--afg-surface,#fff);border:1px solid var(--afg-border,#e5e7eb);border-radius:var(--afg-radius-pill,999px);padding:7px 14px}
  /* LCASP timeline */
  .fr-time{position:relative;margin:16px 0 0;padding:0;list-style:none}
  .fr-time::before{content:"";position:absolute;left:9px;top:8px;bottom:8px;width:2px;background:var(--fr-line)}
  .fr-tstep{position:relative;padding:0 0 22px 36px}
  .fr-tstep::before{content:"";position:absolute;left:2px;top:4px;width:16px;height:16px;border-radius:50%;background:var(--afg-accent,#f3b416);border:3px solid var(--afg-surface,#fff);box-shadow:0 0 0 1px var(--afg-border,#e5e7eb);z-index:1}
  .fr-tstep h4{font-size:15px;font-weight:800;margin:0 0 4px;color:var(--afg-ink,#111827)}
  .fr-tstep p{font-size:14.5px;line-height:1.55;margin:0}
  .fr-tstep .fr-list{margin-top:8px}
  /* guiding principle + CTA */
  .fr-principle{margin:48px 0 0;background:#111827;color:#fff;border-radius:var(--afg-radius-md,14px);padding:30px 28px;text-align:center}
  .fr-principle .fr-principle-lbl{font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--afg-accent,#f3b416);margin:0 0 10px}
  .fr-principle p{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-size:22px;line-height:1.4;margin:0;max-width:720px;margin-inline:auto;color:#fff}
  .fr-cta{margin:36px 0 0;text-align:center;display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
  .fr-btn{display:inline-flex;align-items:center;gap:6px;padding:14px 26px;border-radius:var(--afg-radius-pill,999px);font-weight:700;font-size:15px;text-decoration:none;border:1px solid transparent}
  .fr-btn-primary{background:var(--afg-accent,#f3b416);color:var(--afg-on-accent,#111827)}
  .fr-btn-ghost{background:transparent;color:var(--afg-ink,#111827);border-color:var(--afg-border,#e5e7eb)}
  .fr-btn:focus-visible{outline:2px solid var(--afg-focus,#1d4ed8);outline-offset:2px}
  @media(max-width:720px){.fr-split,.fr-flags{grid-template-columns:1fr}.fr-pillars{grid-template-columns:1fr 1fr}}
</style>

<main id="main-content" class="fr">
  <header class="fr-hero">
    <div class="fr-in">
      <div class="page-head page-head--center" style="padding:0">
        <p class="page-eyebrow">Afrovanguard Social Franchise</p>
        <h1 class="page-title">Franchise &amp; Governance Framework</h1>
        <p class="page-lead">How competent leaders establish Afrovanguard Community Advancement Centres and carry the mission into new communities — under one vision, one governance framework, one operational standard and one culture.</p>
        <p class="fr-hero-sub">For the Lagos Child Accelerated Success Project (LCASP) &amp; the CACENTRE Network</p>
      </div>
    </div>
  </header>

  <div class="fr-body">
    <div class="fr-in">

      <section class="fr-sec first" id="purpose">
        <p class="fr-num">01 · Purpose</p>
        <h2>Why the franchise exists</h2>
        <p>The Afrovanguard Social Franchise model expands the vision through competent leaders who faithfully establish Community Advancement Centres (CACENTREs) and implement standardized projects that advance lives, minds, values, leadership and socio-economic development across communities.</p>
        <p>Every centre operates as an extension of Afrovanguard — adapting appropriately to its local community while never departing from the shared foundation:</p>
        <div class="fr-pillars">
          <div class="fr-pillar"><span>One</span>Vision</div>
          <div class="fr-pillar"><span>One</span>Governance</div>
          <div class="fr-pillar"><span>One</span>Standard</div>
          <div class="fr-pillar"><span>One</span>Culture</div>
        </div>
      </section>

      <section class="fr-sec" id="model">
        <p class="fr-num">02 · The Social Franchise Model</p>
        <h2>What a franchise grants</h2>
        <p>The Lagos Child Accelerated Success Project (LCASP) and all Afrovanguard flagship projects are implemented through the Afrovanguard Social Franchise Model. A franchise grants qualified individuals or organizations the right to establish and operate an Afrovanguard CACENTRE, implement Afrovanguard programmes, and use approved systems, curriculum, branding and operational standards.</p>
        <p class="fr-note"><strong>No individual, institution or organization</strong> may independently operate, replicate or represent any Afrovanguard project without an official franchise approval.</p>
      </section>

      <section class="fr-sec" id="eligibility">
        <p class="fr-num">03 · Franchise Eligibility</p>
        <h2>Who can apply</h2>
        <p>An applicant is considered for an Afrovanguard Social Franchise only when they:</p>
        <div class="fr-card">
          <ul class="fr-list is-check">
            <li>Hold <strong>Level C Membership</strong> or a higher level approved by Afrovanguard.</li>
            <li>Successfully complete <strong>CIMC Levels 1 &amp; 2</strong>.</li>
            <li>Demonstrate proven leadership, integrity, accountability and consistency.</li>
            <li>Meet all organizational leadership requirements before the annual qualification deadline.</li>
            <li>Complete a supervised pilot implementation (physical or virtual).</li>
            <li>Demonstrate seamless execution and complete alignment with the Afrovanguard vision.</li>
            <li>Establish a functional leadership team.</li>
            <li>Sign the official Franchise Agreement.</li>
            <li>Commit to all governance policies, safeguarding standards, operational procedures and quality-assurance requirements.</li>
          </ul>
        </div>
        <p class="fr-note">Final approval remains the <strong>sole responsibility of Afrovanguard</strong>.</p>
      </section>

      <section class="fr-sec" id="level-c">
        <p class="fr-num">04 · Level C &amp; Leadership Multiplication</p>
        <h2>The path to qualifying</h2>
        <p>Level C is reached by progressing through the Afrovanguard leadership-multiplication pathway — proving leadership capacity, mentorship ability and sustainable growth by developing others.</p>
        <div class="fr-card">
          <h3>The multiplication model</h3>
          <ul class="fr-list">
            <li>Attain <strong>Level A</strong> by personally introducing two committed members.</li>
            <li>Mentor those two until <strong>each of them develops two further committed members</strong>.</li>
          </ul>
        </div>
        <div class="fr-card">
          <h3>In addition, the member must</h3>
          <ul class="fr-list is-check">
            <li>Complete CIMC Levels 1 &amp; 2.</li>
            <li>Demonstrate consistent commitment.</li>
            <li>Demonstrate integrity and accountability.</li>
            <li>Meet all annual leadership-assessment requirements.</li>
          </ul>
        </div>
      </section>

      <section class="fr-sec" id="responsibilities">
        <p class="fr-num">05 · Franchise Responsibilities</p>
        <h2>What every franchise holder does</h2>
        <div class="fr-card">
          <ul class="fr-list">
            <li>Operate according to Afrovanguard Standard Operating Procedures.</li>
            <li>Protect the integrity of the Afrovanguard brand.</li>
            <li>Submit operational reports and participate in leadership reviews.</li>
            <li>Participate in quality-assurance audits.</li>
            <li>Maintain safeguarding standards and financial accountability.</li>
            <li>Participate in continuous leadership development.</li>
          </ul>
        </div>
      </section>

      <section class="fr-sec" id="funding">
        <p class="fr-num">06 · Funding &amp; Facility</p>
        <h2>Who carries what</h2>
        <p>Every CACENTRE operates as a financially sustainable community centre.</p>
        <div class="fr-split">
          <div class="fr-card">
            <h3>The franchise holder provides</h3>
            <ul class="fr-list">
              <li>Local fundraising, sponsorship &amp; donor engagement</li>
              <li>Community partnerships</li>
              <li>Venue acquisition</li>
              <li>Operational expenses &amp; utilities</li>
              <li>Equipment &amp; facility maintenance</li>
              <li>Volunteer support</li>
            </ul>
          </div>
          <div class="fr-card">
            <h3>Afrovanguard provides</h3>
            <ul class="fr-list is-check">
              <li>Vision, curriculum &amp; training</li>
              <li>Branding &amp; governance</li>
              <li>Standard Operating Procedures</li>
              <li>Monitoring &amp; Evaluation</li>
              <li>Leadership coaching</li>
              <li>Strategic partnerships where applicable</li>
              <li>National &amp; international representation</li>
            </ul>
          </div>
        </div>
        <p class="fr-note">Where Afrovanguard owns the facility: <strong>major structural repairs</strong> remain Afrovanguard's responsibility, while <strong>routine maintenance, cleaning, utilities and minor repairs</strong> remain the franchise holder's.</p>
      </section>

      <section class="fr-sec" id="cacentre">
        <p class="fr-num">07 · The Community Advancement Centre</p>
        <h2>The CACENTRE facility</h2>
        <p>Every franchise holder establishes a CACENTRE that serves as the operational headquarters for all Afrovanguard programmes. Minimum facility: a <strong>three-bedroom apartment</strong>, or a hall / commercial facility that can be professionally partitioned.</p>
        <div class="fr-card">
          <h3>The centre should accommodate</h3>
          <ul class="fr-list">
            <li>Training rooms &amp; a digital learning space</li>
            <li>Co-working space</li>
            <li>Administrative office &amp; meeting room</li>
            <li>Counselling &amp; mentorship room</li>
            <li>Creative / media workspace where applicable</li>
          </ul>
        </div>
      </section>

      <section class="fr-sec" id="identity">
        <p class="fr-num">08 · Community Identity</p>
        <h2>An indigenous name, one network</h2>
        <p>Every CACENTRE adopts an indigenous community name reflecting the local language and culture — expressing strength, excellence, hope, resilience or advancement.</p>
        <div class="fr-card">
          <h3>Example</h3>
          <p style="margin:0"><strong>Okun Alimosho CACENTRE</strong> — “Strength of Alimosho”.</p>
        </div>
        <p class="fr-note">Whatever its local identity, every CACENTRE remains part of the Afrovanguard Community Advancement Centre Network.</p>
      </section>

      <section class="fr-sec" id="flagships">
        <p class="fr-num">09 · Flagship Projects</p>
        <h2>What every centre is built to run</h2>
        <div class="fr-flags">
          <div class="fr-flag">
            <h3>Business Executive Club</h3>
            <p class="fr-flag-sub">BEC</p>
            <ul class="fr-list">
              <li>Ethical business practice</li>
              <li>Collaboration &amp; networking</li>
              <li>Measurable business value</li>
              <li>Mentorship &amp; business education</li>
            </ul>
          </div>
          <div class="fr-flag">
            <h3>Africa GATES</h3>
            <p class="fr-flag-sub">Global Approach To Entertainment Services</p>
            <ul class="fr-list">
              <li>Artists, musicians &amp; content creators</li>
              <li>Media &amp; event professionals</li>
              <li>Creative entrepreneurs</li>
              <li>Positive values through arts &amp; culture</li>
            </ul>
          </div>
          <div class="fr-flag">
            <h3>Street to Stardom</h3>
            <p class="fr-flag-sub">STS · ages 10–17</p>
            <ul class="fr-list">
              <li>Character development &amp; leadership</li>
              <li>Academic support</li>
              <li>Talent discovery &amp; mentorship</li>
              <li>An incorruptible generation</li>
            </ul>
          </div>
        </div>
      </section>

      <section class="fr-sec" id="operations">
        <p class="fr-num">10 · Standardized Operations</p>
        <h2>The systems every centre runs</h2>
        <p>No centre may establish an independent operating model that contradicts Afrovanguard standards.</p>
        <div class="fr-chips">
          <span class="fr-chip">Attendance</span>
          <span class="fr-chip">Accountability framework</span>
          <span class="fr-chip">Membership management</span>
          <span class="fr-chip">Leadership pathway</span>
          <span class="fr-chip">Volunteer management</span>
          <span class="fr-chip">Training curriculum</span>
          <span class="fr-chip">Certification</span>
          <span class="fr-chip">Financial accountability</span>
          <span class="fr-chip">Reporting</span>
          <span class="fr-chip">Monitoring &amp; Evaluation</span>
          <span class="fr-chip">Communication</span>
          <span class="fr-chip">Branding standards</span>
        </div>
      </section>

      <section class="fr-sec" id="global-access">
        <p class="fr-num">11 · Global Member Access</p>
        <h2>One member, every centre</h2>
        <p>Every CACENTRE belongs to one Afrovanguard network, so any member in good standing may attend programmes at any CACENTRE worldwide.</p>
        <div class="fr-card">
          <ul class="fr-list is-check">
            <li>Membership records recognized across all centres.</li>
            <li>Attendance history recognized across all centres.</li>
            <li>Leadership progression recognized across all centres.</li>
            <li>Certifications recognized across all centres.</li>
            <li>Members who relocate integrate seamlessly into the nearest CACENTRE.</li>
          </ul>
        </div>
      </section>

      <section class="fr-sec" id="flexibility">
        <p class="fr-num">12 · Local Flexibility</p>
        <h2>What may adapt — and what never changes</h2>
        <div class="fr-split">
          <div class="fr-card">
            <h3>Centres may adapt</h3>
            <ul class="fr-list">
              <li>Local language</li>
              <li>Culture</li>
              <li>Programme scheduling</li>
              <li>Community partnerships</li>
              <li>Legal requirements</li>
            </ul>
          </div>
          <div class="fr-card">
            <h3>Never altered</h3>
            <ul class="fr-list">
              <li>Vision &amp; core values</li>
              <li>Governance &amp; leadership structure</li>
              <li>Safeguarding standards</li>
              <li>Quality-assurance systems</li>
              <li>Operational standards</li>
            </ul>
          </div>
        </div>
      </section>

      <section class="fr-sec" id="partnerships">
        <p class="fr-num">13 · Strategic Partnerships</p>
        <h2>Who centres partner with</h2>
        <p>CACENTREs are encouraged to partner with government, schools, NGOs, faith-based organizations, businesses, community associations and international organizations. Every partnership must align with Afrovanguard's vision and governance framework.</p>
      </section>

      <section class="fr-sec" id="lcasp">
        <p class="fr-num">14 · The LCASP Cycle</p>
        <h2>Lagos Child Accelerated Success Project</h2>
        <p>A scalable, standardized project built to serve every Local Government Area in Lagos State, run on a continuous <strong>12-month implementation cycle</strong>.</p>
        <ol class="fr-time">
          <li class="fr-tstep">
            <h4>After the Community Concert — stakeholder engagement</h4>
            <p>Send partnership and approval letters to the Lagos State Government, local governments, schools, sponsors, partners, donors and community leaders. Government approvals ideally processed December–January.</p>
          </li>
          <li class="fr-tstep">
            <h4>School engagement — Second Term (January)</h4>
            <p>Project introduction, student counselling, leadership sessions, parent engagement and Summer School preparation. Each school receives at least three official follow-up visits after approval.</p>
          </li>
          <li class="fr-tstep">
            <h4>School Storm — from May</h4>
            <p>Every participating school receives a first visit (introduction) and a second visit (follow-up &amp; Summer School preparation).</p>
          </li>
          <li class="fr-tstep">
            <h4>Volunteer standards</h4>
            <p>Recruitment completed before school engagement. Weekly service; a minimum of five volunteers per school engagement; removal after two consecutive missed assignments without approval.</p>
          </li>
          <li class="fr-tstep">
            <h4>Summer School readiness</h4>
            <p>Before commencement: venue secured, Summer Packs ready, donor commitments confirmed, at least ten committed volunteers, ten trained instructors, and overall deployment of thirty to forty people.</p>
          </li>
          <li class="fr-tstep">
            <h4>Community Concert</h4>
            <p>Before entering any community, hold a Community Concert using a culturally relevant local name while maintaining Afrovanguard branding.</p>
          </li>
          <li class="fr-tstep">
            <h4>Community expansion</h4>
            <p>Before launching: establish leadership, define responsibilities, implement Standard Operating Procedures and train leaders.</p>
          </li>
          <li class="fr-tstep">
            <h4>Meetings — compulsory</h4>
            <p>Weekly virtual meetings and monthly physical meetings. Missing three without approval results in removal from the active implementation team.</p>
          </li>
        </ol>
      </section>

      <section class="fr-sec" id="admin">
        <p class="fr-num">15 · Project Admin Eligibility</p>
        <h2>Who can administer a project</h2>
        <div class="fr-card">
          <ul class="fr-list is-check">
            <li>Complete CIMC Levels 1 &amp; 2.</li>
            <li>Demonstrate consistency and reliability.</li>
            <li>Maintain at least <strong>70% meeting attendance</strong>.</li>
            <li>Demonstrate accountability, responsibility, integrity and professionalism.</li>
            <li>Hold Level C Membership (or an approved advanced Level B).</li>
            <li>Successfully teach at least one CIMC Level 1 class.</li>
            <li>Demonstrate competence in Afrovanguard systems and project operations.</li>
          </ul>
        </div>
        <p class="fr-note">Appointment remains subject to final approval by Afrovanguard leadership.</p>
      </section>

      <div class="fr-principle">
        <p class="fr-principle-lbl">Guiding principle</p>
        <p>Every CACENTRE and every Afrovanguard Social Franchise faithfully preserves the vision, values, systems and culture. Expansion prioritises quality, sustainability, accountability and transformational impact over numerical growth — so every community experiences the same standard of excellence while celebrating its unique cultural identity.</p>
      </div>

      <div class="fr-cta">
        <a class="fr-btn fr-btn-primary" href="<?= e($applyMail) ?>">Express your interest →</a>
        <a class="fr-btn fr-btn-ghost" href="/how-it-works">How members grow</a>
        <a class="fr-btn fr-btn-ghost" href="/contact/">Talk to our team</a>
      </div>

    </div>
  </div>
</main>

<?php render_footer(); ?>
