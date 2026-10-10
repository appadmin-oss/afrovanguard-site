<?php
/**
 * franchise.php — "Afrovanguard Social Franchise & Governance Framework" (/franchise).
 * The public framework for establishing Community Advancement Centres
 * (CACENTREs) and running Afrovanguard flagship projects (BEC, Africa GATES,
 * Street-To-Stardom) in any community, under one vision and one standard.
 *
 * In the site's current system: the Home chrome, the sand hero with crumbs
 * and calls to action, a sticky "On this page" rail beside the sections (a
 * scrolling bar on phones), stone cards, and the dark closing band.
 * Styles assets/site/avfr.css (prefix avfr-, tokens only — no inline styles);
 * the rail's scroll-spy assets/site/avfr.js. Copy unchanged from v1.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$applyMail = 'mailto:cacentre@afrovanguard.org.ng?subject=' . rawurlencode('Afrovanguard Social Franchise — Expression of Interest');
$toc = [
    'purpose' => 'Purpose', 'model' => 'The model', 'eligibility' => 'Eligibility', 'level-c' => 'Qualifying',
    'responsibilities' => 'Responsibilities', 'funding' => 'Funding', 'cacentre' => 'The centre', 'identity' => 'Identity',
    'flagships' => 'Flagship projects', 'operations' => 'Operations', 'global-access' => 'Member access',
    'flexibility' => 'Local flexibility', 'partnerships' => 'Partnerships', 'cycle' => 'Implementation', 'admin' => 'Project admin',
];

render_head([
    'title'     => 'Social Franchise & Governance Framework — Afrovanguard',
    'desc'      => 'How competent leaders establish Afrovanguard Community Advancement Centres (CACENTREs) and run flagship projects — BEC, Africa GATES, Street-To-Stardom — in any community, under one vision, one governance framework and one operational standard.',
    'canonical' => rtrim(SITE_URL, '/') . '/franchise',
    'css'       => ['/assets/site/avfr.css'],
]);
render_nav('involved');
?>
<main id="main" tabindex="-1" class="avfr">
  <header class="avfr-hero avfr-pad">
    <div class="avh-topo" data-avh-topo="light" data-seed="5" aria-hidden="true"></div>
    <div class="avfr-wrap">
      <nav aria-label="Breadcrumb"><ol class="avfr-crumbs"><li><a href="/">Home</a></li><li><a href="/donate.html">Get involved</a></li><li><span aria-current="page">Franchise a CACENTRE</span></li></ol></nav>
      <div class="avfr-hero-grid">
        <div>
          <p class="avfr-eyebrow">Afrovanguard Social Franchise</p>
          <h1>Franchise &amp; Governance Framework</h1>
        </div>
        <div class="avfr-hero-side">
          <p>How competent leaders establish Afrovanguard Community Advancement Centres and carry the mission into new communities — anywhere in the world — under one vision, one governance framework, one operational standard and one culture.</p>
          <p class="avfr-hero-sub">For the Afrovanguard Community Advancement Centre (CACENTRE) Network</p>
          <div class="avfr-btns">
            <a class="avfr-btn" href="<?= e($applyMail) ?>">Express your interest →</a>
            <a class="avfr-btn avfr-btn--line" href="#eligibility">Who can apply</a>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="avfr-body avfr-pad">
    <div class="avfr-wrap avfr-grid">
      <nav class="avfr-toc" aria-label="On this page">
        <p class="avfr-toc-h">On this page</p>
        <ol class="avfr-toc-list">
<?php foreach ($toc as $id => $label): ?>
          <li><a href="#<?= $id ?>"><?= e($label) ?></a></li>
<?php endforeach; ?>
        </ol>
      </nav>
      <div class="avfr-main">
        <section class="avfr-sec" id="purpose">
        <p class="avfr-num">01 · Purpose</p>
        <h2>Why the franchise exists</h2>
        <p>The Afrovanguard Social Franchise model expands the vision through competent leaders who faithfully establish Community Advancement Centres (CACENTREs) and implement standardized projects that advance lives, minds, values, leadership and socio-economic development across communities.</p>
        <p>Every centre operates as an extension of Afrovanguard — adapting appropriately to its local community while never departing from the shared foundation:</p>
        <div class="avfr-pillars">
          <div class="avfr-pillar"><span>One</span>Vision</div>
          <div class="avfr-pillar"><span>One</span>Governance</div>
          <div class="avfr-pillar"><span>One</span>Standard</div>
          <div class="avfr-pillar"><span>One</span>Culture</div>
        </div>
      </section>

      <section class="avfr-sec" id="model">
        <p class="avfr-num">02 · The Social Franchise Model</p>
        <h2>What a franchise grants</h2>
        <p>All Afrovanguard flagship projects are implemented through the Afrovanguard Social Franchise Model. A franchise grants qualified individuals or organizations the right to establish and operate an Afrovanguard CACENTRE in their own community, implement Afrovanguard programmes, and use approved systems, curriculum, branding and operational standards.</p>
        <p class="avfr-note"><strong>No individual, institution or organization</strong> may independently operate, replicate or represent any Afrovanguard project without an official franchise approval.</p>
      </section>

      <section class="avfr-sec" id="eligibility">
        <p class="avfr-num">03 · Franchise Eligibility</p>
        <h2>Who can apply</h2>
        <p>An applicant is considered for an Afrovanguard Social Franchise only when they:</p>
        <div class="avfr-card">
          <ul class="avfr-list is-check">
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
        <p class="avfr-note">Final approval remains the <strong>sole responsibility of Afrovanguard</strong>.</p>
      </section>

      <section class="avfr-sec" id="level-c">
        <p class="avfr-num">04 · Level C &amp; Leadership Multiplication</p>
        <h2>The path to qualifying</h2>
        <p>Level C is reached by progressing through the Afrovanguard leadership-multiplication pathway — proving leadership capacity, mentorship ability and sustainable growth by developing others.</p>
        <div class="avfr-card">
          <h3>The multiplication model</h3>
          <ul class="avfr-list">
            <li>Attain <strong>Level A</strong> by personally introducing two committed members.</li>
            <li>Mentor those two until <strong>each of them develops two further committed members</strong>.</li>
          </ul>
        </div>
        <div class="avfr-card">
          <h3>In addition, the member must</h3>
          <ul class="avfr-list is-check">
            <li>Complete CIMC Levels 1 &amp; 2.</li>
            <li>Demonstrate consistent commitment.</li>
            <li>Demonstrate integrity and accountability.</li>
            <li>Meet all annual leadership-assessment requirements.</li>
          </ul>
        </div>
      </section>

      <section class="avfr-sec" id="responsibilities">
        <p class="avfr-num">05 · Franchise Responsibilities</p>
        <h2>What every franchise holder does</h2>
        <div class="avfr-card">
          <ul class="avfr-list">
            <li>Operate according to Afrovanguard Standard Operating Procedures.</li>
            <li>Protect the integrity of the Afrovanguard brand.</li>
            <li>Submit operational reports and participate in leadership reviews.</li>
            <li>Participate in quality-assurance audits.</li>
            <li>Maintain safeguarding standards and financial accountability.</li>
            <li>Participate in continuous leadership development.</li>
          </ul>
        </div>
      </section>

      <section class="avfr-sec" id="funding">
        <p class="avfr-num">06 · Funding &amp; Facility</p>
        <h2>Who carries what</h2>
        <p>Every CACENTRE operates as a financially sustainable community centre.</p>
        <div class="avfr-split">
          <div class="avfr-card">
            <h3>The franchise holder provides</h3>
            <ul class="avfr-list">
              <li>Local fundraising, sponsorship &amp; donor engagement</li>
              <li>Community partnerships</li>
              <li>Venue acquisition</li>
              <li>Operational expenses &amp; utilities</li>
              <li>Equipment &amp; facility maintenance</li>
              <li>Volunteer support</li>
            </ul>
          </div>
          <div class="avfr-card">
            <h3>Afrovanguard provides</h3>
            <ul class="avfr-list is-check">
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
        <p class="avfr-note">Where Afrovanguard owns the facility: <strong>major structural repairs</strong> remain Afrovanguard's responsibility, while <strong>routine maintenance, cleaning, utilities and minor repairs</strong> remain the franchise holder's.</p>
      </section>

      <section class="avfr-sec" id="cacentre">
        <p class="avfr-num">07 · The Community Advancement Centre</p>
        <h2>The CACENTRE facility</h2>
        <p>Every franchise holder establishes a CACENTRE that serves as the operational headquarters for all Afrovanguard programmes. Minimum facility: a <strong>three-bedroom apartment</strong>, or a hall / commercial facility that can be professionally partitioned.</p>
        <div class="avfr-card">
          <h3>The centre should accommodate</h3>
          <ul class="avfr-list">
            <li>Training rooms &amp; a digital learning space</li>
            <li>Co-working space</li>
            <li>Administrative office &amp; meeting room</li>
            <li>Counselling &amp; mentorship room</li>
            <li>Creative / media workspace where applicable</li>
          </ul>
        </div>
      </section>

      <section class="avfr-sec" id="identity">
        <p class="avfr-num">08 · Community Identity</p>
        <h2>An indigenous name, one network</h2>
        <p>Every CACENTRE adopts an indigenous community name reflecting the local language and culture — expressing strength, excellence, hope, resilience or advancement.</p>
        <div class="avfr-card">
          <h3>Example</h3>
          <p class="avfr-flat"><strong>Okun [Community] CACENTRE</strong> — where “Okun” carries the local meaning of strength. Each centre chooses a name rooted in its own community and language.</p>
        </div>
        <p class="avfr-note">Whatever its local identity, every CACENTRE remains part of the Afrovanguard Community Advancement Centre Network.</p>
      </section>

      <section class="avfr-sec" id="flagships">
        <p class="avfr-num">09 · Flagship Projects</p>
        <h2>What every centre is built to run</h2>
        <div class="avfr-flags">
          <div class="avfr-flag">
            <h3>Business Executive Club</h3>
            <p class="avfr-flag-sub">BEC</p>
            <ul class="avfr-list">
              <li>Ethical business practice</li>
              <li>Collaboration &amp; networking</li>
              <li>Measurable business value</li>
              <li>Mentorship &amp; business education</li>
            </ul>
          </div>
          <div class="avfr-flag">
            <h3>Africa GATES</h3>
            <p class="avfr-flag-sub">Global Approach To Entertainment Services</p>
            <ul class="avfr-list">
              <li>Artists, musicians &amp; content creators</li>
              <li>Media &amp; event professionals</li>
              <li>Creative entrepreneurs</li>
              <li>Positive values through arts &amp; culture</li>
            </ul>
          </div>
          <div class="avfr-flag">
            <h3>Street to Stardom</h3>
            <p class="avfr-flag-sub">STS · ages 10–17</p>
            <ul class="avfr-list">
              <li>Character development &amp; leadership</li>
              <li>Academic support</li>
              <li>Talent discovery &amp; mentorship</li>
              <li>An incorruptible generation</li>
            </ul>
          </div>
        </div>
      </section>

      <section class="avfr-sec" id="operations">
        <p class="avfr-num">10 · Standardized Operations</p>
        <h2>The systems every centre runs</h2>
        <p>No centre may establish an independent operating model that contradicts Afrovanguard standards.</p>
        <div class="avfr-chips">
          <span class="avfr-chip">Attendance</span>
          <span class="avfr-chip">Accountability framework</span>
          <span class="avfr-chip">Membership management</span>
          <span class="avfr-chip">Leadership pathway</span>
          <span class="avfr-chip">Volunteer management</span>
          <span class="avfr-chip">Training curriculum</span>
          <span class="avfr-chip">Certification</span>
          <span class="avfr-chip">Financial accountability</span>
          <span class="avfr-chip">Reporting</span>
          <span class="avfr-chip">Monitoring &amp; Evaluation</span>
          <span class="avfr-chip">Communication</span>
          <span class="avfr-chip">Branding standards</span>
        </div>
      </section>

      <section class="avfr-sec" id="global-access">
        <p class="avfr-num">11 · Global Member Access</p>
        <h2>One member, every centre</h2>
        <p>Every CACENTRE belongs to one Afrovanguard network, so any member in good standing may attend programmes at any CACENTRE worldwide.</p>
        <div class="avfr-card">
          <ul class="avfr-list is-check">
            <li>Membership records recognized across all centres.</li>
            <li>Attendance history recognized across all centres.</li>
            <li>Leadership progression recognized across all centres.</li>
            <li>Certifications recognized across all centres.</li>
            <li>Members who relocate integrate seamlessly into the nearest CACENTRE.</li>
          </ul>
        </div>
      </section>

      <section class="avfr-sec" id="flexibility">
        <p class="avfr-num">12 · Local Flexibility</p>
        <h2>What may adapt — and what never changes</h2>
        <div class="avfr-split">
          <div class="avfr-card">
            <h3>Centres may adapt</h3>
            <ul class="avfr-list">
              <li>Local language</li>
              <li>Culture</li>
              <li>Programme scheduling</li>
              <li>Community partnerships</li>
              <li>Legal requirements</li>
            </ul>
          </div>
          <div class="avfr-card">
            <h3>Never altered</h3>
            <ul class="avfr-list">
              <li>Vision &amp; core values</li>
              <li>Governance &amp; leadership structure</li>
              <li>Safeguarding standards</li>
              <li>Quality-assurance systems</li>
              <li>Operational standards</li>
            </ul>
          </div>
        </div>
      </section>

      <section class="avfr-sec" id="partnerships">
        <p class="avfr-num">13 · Strategic Partnerships</p>
        <h2>Who centres partner with</h2>
        <p>CACENTREs are encouraged to partner with government, schools, NGOs, faith-based organizations, businesses, community associations and international organizations. Every partnership must align with Afrovanguard's vision and governance framework.</p>
      </section>

      <section class="avfr-sec" id="cycle">
        <p class="avfr-num">14 · The Implementation Cycle</p>
        <h2>How a centre runs its year</h2>
        <p>Every CACENTRE runs its flagship youth project on a continuous <strong>12-month implementation cycle</strong> — a scalable, standardized rhythm that adapts to any community, region or country while keeping the same milestones and quality bar.</p>
        <ol class="avfr-time">
          <li class="avfr-tstep">
            <h3>Stakeholder engagement</h3>
            <p>Send partnership and approval letters to the relevant government authorities, local councils, schools, sponsors, partners, donors and community leaders, following each locality's own approval calendar.</p>
          </li>
          <li class="avfr-tstep">
            <h3>School engagement</h3>
            <p>Project introduction, student counselling, leadership sessions, parent engagement and holiday-programme preparation. Each school receives at least three official follow-up visits after approval.</p>
          </li>
          <li class="avfr-tstep">
            <h3>Outreach drive</h3>
            <p>Every participating school receives a first visit (introduction) and a second visit (follow-up &amp; holiday-programme preparation).</p>
          </li>
          <li class="avfr-tstep">
            <h3>Volunteer standards</h3>
            <p>Recruitment completed before school engagement. Weekly service; a minimum of five volunteers per school engagement; removal after two consecutive missed assignments without approval.</p>
          </li>
          <li class="avfr-tstep">
            <h3>Holiday-programme readiness</h3>
            <p>Before commencement: venue secured, learning packs ready, donor commitments confirmed, at least ten committed volunteers, ten trained instructors, and overall deployment of thirty to forty people.</p>
          </li>
          <li class="avfr-tstep">
            <h3>Community Concert</h3>
            <p>Before entering any community, hold a Community Concert using a culturally relevant local name while maintaining Afrovanguard branding.</p>
          </li>
          <li class="avfr-tstep">
            <h3>Community expansion</h3>
            <p>Before launching: establish leadership, define responsibilities, implement Standard Operating Procedures and train leaders.</p>
          </li>
          <li class="avfr-tstep">
            <h3>Meetings — compulsory</h3>
            <p>Weekly virtual meetings and monthly physical meetings. Missing three without approval results in removal from the active implementation team.</p>
          </li>
        </ol>
      </section>

      <section class="avfr-sec" id="admin">
        <p class="avfr-num">15 · Project Admin Eligibility</p>
        <h2>Who can administer a project</h2>
        <div class="avfr-card">
          <ul class="avfr-list is-check">
            <li>Complete CIMC Levels 1 &amp; 2.</li>
            <li>Demonstrate consistency and reliability.</li>
            <li>Maintain at least <strong>70% meeting attendance</strong>.</li>
            <li>Demonstrate accountability, responsibility, integrity and professionalism.</li>
            <li>Hold Level C Membership (or an approved advanced Level B).</li>
            <li>Successfully teach at least one CIMC Level 1 class.</li>
            <li>Demonstrate competence in Afrovanguard systems and project operations.</li>
          </ul>
        </div>
        <p class="avfr-note">Appointment remains subject to final approval by Afrovanguard leadership.</p>
      </section>


      </div>
    </div>
  </div>

  <section class="avfr-cta avfr-pad" aria-labelledby="avfr-cta-h">
    <div class="avh-topo" data-avh-topo="dark" data-seed="9" aria-hidden="true"></div>
    <div class="avfr-cta-in">
      <h2 class="avfr-eyebrow avfr-eyebrow--light" id="avfr-cta-h">Guiding principle</h2>
      <p>Every CACENTRE and every Afrovanguard Social Franchise faithfully preserves the vision, values, systems and culture. Expansion prioritises quality, sustainability, accountability and transformational impact over numerical growth — so every community experiences the same standard of excellence while celebrating its unique cultural identity.</p>
      <div class="avfr-btns avfr-btns--c">
        <a class="avfr-btn avfr-btn--gold" href="<?= e($applyMail) ?>">Express your interest →</a>
        <a class="avfr-btn avfr-btn--onink" href="/how-it-works">How members grow</a>
        <a class="avfr-btn avfr-btn--onink" href="/contact.html">Talk to our team</a>
      </div>
    </div>
  </section>
</main>
<script src="/assets/site/avfr.js" defer></script>
<?php render_footer();
