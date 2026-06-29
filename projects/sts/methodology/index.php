<?php
$STS_ROOT = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($STS_ROOT === '' || !is_file($STS_ROOT.'/inc/head.php')) { $STS_ROOT = __DIR__; while (!is_file($STS_ROOT.'/inc/head.php') && dirname($STS_ROOT) !== $STS_ROOT) $STS_ROOT = dirname($STS_ROOT); }
$PAGE_TITLE = "The STS Transformation Methodology — Street-To-Stardom";
$PAGE_DESC  = "The STS Transformation Cycle (STC): five pillars of formation, seven Afrovanguard core values, and a seven-stage journey that turns potential into stardom — raising the Incorruptible Generation.";
$PAGE_PATH  = "/methodology/";
$PAGE_HEAD_EXTRA = <<<'STSHEAD'
<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","headline":"The STS Transformation Methodology","about":"The Street-To-Stardom Transformation Cycle (STC)","author":{"@type":"Organization","name":"Street-To-Stardom"},"publisher":{"@type":"Organization","name":"Afrovanguard","url":"https://afrovanguard.org.ng"},"inLanguage":"en","url":"https://sts.afrovanguard.org.ng/methodology/"}</script>
<style>
/* ── Methodology page — lively system, riding the Hero v2 design language ── */
.placeholder::before,.placeholder::after{display:none!important}.placeholder{background:#111!important}
.mth-hero{position:relative;overflow:hidden;background:#E6F0FF;padding:104px var(--section-pad-x) 88px}
:root[data-theme=dark] .mth-hero{background:#06080F}
.mth-hero__blob{position:absolute;inset:0;pointer-events:none;z-index:0;opacity:.6}
:root[data-theme=dark] .mth-hero__blob{opacity:.2}
.mth-hero__inner{position:relative;z-index:1;max-width:1100px;margin:0 auto;text-align:center}
.mth-motto{margin-top:26px;display:inline-flex;align-items:center;gap:12px;font-family:var(--font-display);font-weight:600;font-size:clamp(15px,1.8vw,19px);color:var(--blue);letter-spacing:-.01em}
.mth-motto .q{font-style:italic}
:root[data-theme=dark] .mth-motto{color:#D8DCFF}
.mth-formula{margin:36px auto 0;max-width:760px;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px 10px;font-family:var(--font-mono);font-size:clamp(11px,1.3vw,13px);letter-spacing:.02em}
.mth-formula .term{padding:7px 13px;border-radius:999px;background:rgba(7,50,247,.10);border:1px solid rgba(7,50,247,.20);color:var(--blue);white-space:nowrap}
:root[data-theme=dark] .mth-formula .term{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.14);color:#D8DCFF}
.mth-formula .plus{color:var(--muted);font-weight:600}
.mth-formula .eq{padding:7px 15px;border-radius:999px;background:var(--crimson);border:1px solid var(--crimson);color:#fff;font-weight:600}
/* shared eyebrow centring helper */
.mth-center{text-align:center}
.mth-center .section-lede{margin-left:auto;margin-right:auto}
/* five dimensions / pillars */
.mth-pillars{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;margin-top:44px}
.mth-pcard{position:relative;border:1px solid var(--hairline);border-radius:16px;padding:26px 22px 24px;background:var(--surface);overflow:hidden;transition:transform .25s cubic-bezier(.3,.7,.4,1),box-shadow .25s ease,border-color .25s ease}
.mth-pcard:hover{transform:translateY(-4px);box-shadow:0 22px 44px -22px rgba(7,50,247,.4);border-color:rgba(7,50,247,.4)}
.mth-pcard__bar{position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--blue),var(--crimson))}
.mth-pcard__n{font-family:var(--font-mono);font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--blue)}
.mth-pcard__t{font-family:var(--font-display);font-weight:700;font-size:21px;letter-spacing:-.02em;margin:8px 0 10px}
.mth-pcard__d{font-size:13.5px;line-height:1.6;color:var(--muted)}
/* seven core values */
.mth-values{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;margin-top:40px}
.mth-value{display:flex;gap:16px;align-items:flex-start;padding:20px;border:1px solid var(--hairline);border-radius:14px;background:var(--surface);transition:border-color .2s ease,transform .2s ease}
.mth-value:hover{border-color:rgba(200,16,46,.4);transform:translateY(-2px)}
.mth-value__num{flex-shrink:0;width:38px;height:38px;border-radius:10px;display:grid;place-items:center;font-family:var(--font-display);font-weight:700;font-size:16px;color:#fff;background:var(--blue)}
.mth-value:nth-child(even) .mth-value__num{background:var(--crimson)}
.mth-value__t{font-family:var(--font-display);font-weight:700;font-size:16px;letter-spacing:-.01em;margin-bottom:5px}
.mth-value__d{font-size:13px;line-height:1.55;color:var(--muted)}
/* seven-stage cycle timeline */
.mth-cycle{margin-top:44px;display:grid;gap:14px}
.mth-stage{position:relative;display:grid;grid-template-columns:auto 1fr;gap:22px;padding:24px 26px;border:1px solid var(--hairline);border-radius:18px;background:var(--surface);transition:transform .25s cubic-bezier(.3,.7,.4,1),box-shadow .25s ease}
.mth-stage:hover{transform:translateX(6px);box-shadow:0 18px 40px -24px rgba(7,50,247,.45)}
.mth-stage__badge{display:flex;flex-direction:column;align-items:center;justify-content:flex-start;min-width:64px}
.mth-stage__num{font-family:var(--font-display);font-weight:700;font-size:34px;line-height:1;letter-spacing:-.03em;color:var(--blue);font-variant-numeric:tabular-nums}
.mth-stage:nth-child(even) .mth-stage__num{color:var(--crimson)}
.mth-stage__verb{margin-top:8px;font-family:var(--font-mono);font-size:10px;letter-spacing:.13em;text-transform:uppercase;color:var(--muted)}
.mth-stage__t{font-family:var(--font-display);font-weight:700;font-size:19px;letter-spacing:-.02em;margin-bottom:6px}
.mth-stage__sub{font-family:var(--font-mono);font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--blue);margin-bottom:12px}
.mth-stage__d{font-size:13.5px;line-height:1.6;color:var(--muted);margin-bottom:12px}
.mth-chips{display:flex;flex-wrap:wrap;gap:7px}
.mth-chip{font-family:var(--font-mono);font-size:10.5px;letter-spacing:.02em;padding:5px 10px;border-radius:8px;background:rgba(7,50,247,.07);border:1px solid rgba(7,50,247,.14);color:var(--ink,#1A1A2E)}
:root[data-theme=dark] .mth-chip{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.12);color:rgba(255,255,255,.78)}
/* daily formation list */
.mth-day{margin-top:36px;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}
.mth-day__item{display:flex;gap:14px;align-items:flex-start;padding:16px 18px;border-radius:12px;background:var(--surface);border:1px solid var(--hairline)}
.mth-day__dot{flex-shrink:0;width:9px;height:9px;border-radius:50%;margin-top:6px;background:var(--blue)}
.mth-day__item:nth-child(even) .mth-day__dot{background:var(--crimson)}
.mth-day__txt{font-size:13.5px;line-height:1.5}
/* theory of change flow */
.mth-toc{margin-top:40px;display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.mth-toc__node{font-family:var(--font-mono);font-size:11.5px;letter-spacing:.02em;padding:9px 14px;border-radius:999px;background:var(--surface);border:1px solid var(--hairline);transition:border-color .2s,background .2s}
.mth-toc__node:hover{border-color:var(--blue);background:rgba(7,50,247,.06)}
.mth-toc__node.final{background:var(--crimson);border-color:var(--crimson);color:#fff}
.mth-toc__arrow{color:var(--blue);font-size:14px}
/* outcomes + headline 2031 stat */
.mth-outcomes{margin-top:36px;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}
.mth-outcome{display:flex;gap:13px;align-items:flex-start;padding:18px 20px;border-radius:12px;border:1px solid var(--hairline);background:var(--surface);font-size:13.5px;line-height:1.55}
.mth-outcome svg{flex-shrink:0;margin-top:2px;color:var(--blue)}
.mth-2031{margin-top:44px;position:relative;overflow:hidden;border-radius:22px;background:var(--blue);color:#fff;padding:52px 44px;text-align:center}
.mth-2031::after{content:"";position:absolute;inset:0;pointer-events:none;background:radial-gradient(ellipse at 8% 120%,rgba(200,16,46,.32),transparent 50%),radial-gradient(ellipse at 95% -10%,rgba(255,255,255,.08),transparent 46%)}
.mth-2031__big{position:relative;font-family:var(--font-display);font-weight:700;font-size:clamp(44px,7vw,80px);line-height:.95;letter-spacing:-.03em}
.mth-2031__label{position:relative;margin-top:14px;font-size:clamp(15px,2vw,19px);color:rgba(255,255,255,.9);max-width:560px;margin-left:auto;margin-right:auto;line-height:1.5}
.mth-2031__tag{position:relative;display:inline-block;margin-bottom:18px;font-family:var(--font-mono);font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:rgba(255,255,255,.6)}
@media(max-width:600px){.mth-stage{grid-template-columns:1fr}.mth-stage__badge{flex-direction:row;gap:12px;align-items:baseline}.mth-2031{padding:40px 24px}}
@media(prefers-reduced-motion:reduce){.mth-pcard,.mth-stage,.mth-value{transition:none}}
</style>
STSHEAD;
require $STS_ROOT.'/inc/head.php';
?>
<!-- ── Hero ─────────────────────────────────────────────────────── -->
  <section class="mth-hero" data-screen-label="Methodology · Hero">
    <svg class="mth-hero__blob" viewBox="0 0 1200 420" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" preserveAspectRatio="xMidYMid slice">
      <ellipse cx="1000" cy="80" rx="260" ry="220" fill="var(--blue,#0732F7)" opacity="0.12" transform="rotate(-18 1000 80)"/>
      <ellipse cx="1090" cy="240" rx="180" ry="260" fill="var(--crimson,#C8102E)" opacity="0.07" transform="rotate(14 1090 240)"/>
      <ellipse cx="140" cy="60" rx="220" ry="150" fill="var(--blue,#0732F7)" opacity="0.06" transform="rotate(10 140 60)"/>
    </svg>
    <div class="mth-hero__inner">
      <span class="eyebrow reveal">Our methodology · The STC</span>
      <h1 class="page-title reveal" style="max-width:880px;margin-left:auto;margin-right:auto;">The STS Transformation Methodology — <span class="accent">raising the Incorruptible Generation</span>.</h1>
      <p class="page-lede reveal" style="max-width:720px;margin-left:auto;margin-right:auto;">Every Street-To-Stardom programme — School Storms, Summer Impact School, Bootcamps, the Next Generation Genius Club, Community Projects and Leadership Academies — runs on one unified framework: the <strong>STS Transformation Cycle</strong>. Five pillars of formation, seven core values, and a seven-stage journey from discovery to lifelong leadership.</p>
      <div class="mth-motto reveal"><span class="q">&ldquo;Where Dreams Take Root and Glory Blooms.&rdquo;</span></div>
      <div class="mth-formula reveal" aria-label="The STS transformation formula">
        <span class="term">Potential</span><span class="plus">+</span>
        <span class="term">Character</span><span class="plus">+</span>
        <span class="term">Competence</span><span class="plus">+</span>
        <span class="term">Mentorship</span><span class="plus">+</span>
        <span class="term">Community</span><span class="plus">+</span>
        <span class="term">Opportunity</span>
        <span class="eq">= Stardom</span>
      </div>
    </div>
  </section>

  <!-- ── Philosophy ───────────────────────────────────────────────── -->
  <section class="section prose-section" data-screen-label="Methodology · Philosophy">
    <div class="section-inner">
      <div class="prose-grid">
        <div>
          <div class="meta">01 · Why we exist</div>
          <div style="margin-top:16px;color:var(--muted);font-size:13px;line-height:1.55;max-width:240px;">Corruption, cultism, violence, drug abuse, academic failure and societal decay do not begin in adulthood. They begin in childhood — through neglected values, poor mentorship and broken systems.</div>
        </div>
        <div class="prose">
          <p>Street-To-Stardom intervenes at the roots. Rather than reacting to society's failures, we intentionally shape children during their formative years through structured mentorship, experiential learning, leadership development, technology, arts, entrepreneurship, community engagement and ethical formation.</p>
          <p>We believe every child possesses innate greatness waiting to be discovered, nurtured and deployed. Transformation occurs when five essential dimensions of human development are intentionally cultivated — <strong>Character, Leadership, Skill, Community</strong> and <strong>Purpose</strong> — reinforced through Afrovanguard's value system, practical learning, mentorship, family engagement and measurable community impact.</p>
          <blockquote>Our vision is not merely to educate children, but to transform them into responsible citizens, ethical leaders, innovative thinkers, community builders and global problem-solvers capable of advancing Africa and the world.</blockquote>
        </div>
      </div>
    </div>
  </section>

  <!-- ── Five Pillars ─────────────────────────────────────────────── -->
  <section class="section" data-screen-label="Methodology · Five pillars" style="padding-top:24px;padding-bottom:72px;">
    <div class="section-inner mth-center">
      <span class="eyebrow reveal">The five pillars of daily formation</span>
      <h2 class="section-title reveal">Every lesson, project and conversation feeds at least one pillar.</h2>
      <p class="section-lede reveal">These five dimensions are not abstract values — they are cultivated, practised and measured every single programme day.</p>
      <div class="mth-pillars">
        <div class="mth-pcard reveal"><span class="mth-pcard__bar"></span><div class="mth-pcard__n">Pillar 01</div><div class="mth-pcard__t">Character</div><div class="mth-pcard__d">Integrity, discipline, honesty, accountability, resilience and ethical decision-making. Children learn not merely what is right, but how to consistently practise it.</div></div>
        <div class="mth-pcard reveal"><span class="mth-pcard__bar"></span><div class="mth-pcard__n">Pillar 02</div><div class="mth-pcard__t">Leadership</div><div class="mth-pcard__d">Visionary, courageous, servant leaders who influence schools, communities and nations. Leadership is taught as influence through service rather than authority.</div></div>
        <div class="mth-pcard reveal"><span class="mth-pcard__bar"></span><div class="mth-pcard__n">Pillar 03</div><div class="mth-pcard__t">Skill</div><div class="mth-pcard__d">Future-ready competencies: AI, technology, coding, entrepreneurship, financial literacy, digital media, creative arts, music and problem solving. Every child graduates with demonstrable skill.</div></div>
        <div class="mth-pcard reveal"><span class="mth-pcard__bar"></span><div class="mth-pcard__n">Pillar 04</div><div class="mth-pcard__t">Community</div><div class="mth-pcard__d">Civic responsibility through volunteerism, environmental stewardship, collaboration and service-learning. Every child becomes a solution provider in their environment.</div></div>
        <div class="mth-pcard reveal"><span class="mth-pcard__bar"></span><div class="mth-pcard__n">Pillar 05</div><div class="mth-pcard__t">Purpose</div><div class="mth-pcard__d">Helping every participant discover identity, destiny, life vision and their responsibility toward Africa and humanity. Purpose transforms education into meaningful impact.</div></div>
      </div>
    </div>
  </section>

  <!-- ── Seven Core Values ────────────────────────────────────────── -->
  <section class="section" data-screen-label="Methodology · Core values" style="padding-top:64px;padding-bottom:72px;background:var(--surface);border-top:1px solid var(--hairline);border-bottom:1px solid var(--hairline);">
    <div class="section-inner mth-center">
      <span class="eyebrow reveal">The seven Afrovanguard core values</span>
      <h2 class="section-title reveal">The ethical foundation embedded in everything we do.</h2>
      <p class="section-lede reveal">Demonstrated, practised, assessed, rewarded and reinforced throughout every participant's journey — not merely taught.</p>
      <div class="mth-values">
        <div class="mth-value reveal"><div class="mth-value__num">1</div><div><div class="mth-value__t">Individuation</div><div class="mth-value__d">Self-awareness, originality, critical thinking and the courage to become one's highest potential rather than conforming to destructive patterns.</div></div></div>
        <div class="mth-value reveal"><div class="mth-value__num">2</div><div><div class="mth-value__t">Faith</div><div class="mth-value__d">Confidence in God, purpose, possibility and the future — nurturing hope, resilience and moral conviction.</div></div></div>
        <div class="mth-value reveal"><div class="mth-value__num">3</div><div><div class="mth-value__t">Diligence</div><div class="mth-value__d">Excellence through discipline, hard work, consistency, continuous learning and perseverance.</div></div></div>
        <div class="mth-value reveal"><div class="mth-value__num">4</div><div><div class="mth-value__t">Accountability</div><div class="mth-value__d">Ownership of actions, responsibilities, decisions and measurable outcomes — with honesty and transparency.</div></div></div>
        <div class="mth-value reveal"><div class="mth-value__num">5</div><div><div class="mth-value__t">Responsibility</div><div class="mth-value__d">Using one's talents, opportunities and influence to solve problems and positively transform society.</div></div></div>
        <div class="mth-value reveal"><div class="mth-value__num">6</div><div><div class="mth-value__t">Cultural Appreciation</div><div class="mth-value__d">Pride in African identity, heritage, creativity and history, while respecting the diversity of other cultures.</div></div></div>
        <div class="mth-value reveal"><div class="mth-value__num">7</div><div><div class="mth-value__t">Communal Spirit</div><div class="mth-value__d">Teamwork, compassion, volunteerism, collaboration and collective responsibility for community advancement.</div></div></div>
      </div>
    </div>
  </section>

  <!-- ── The Seven-Stage Cycle ────────────────────────────────────── -->
  <section class="section" data-screen-label="Methodology · The cycle" style="padding-top:72px;padding-bottom:72px;">
    <div class="section-inner">
      <div class="mth-center">
        <span class="eyebrow reveal">The STS Transformation Cycle</span>
        <h2 class="section-title reveal">Seven interconnected stages — from identification to lifelong leadership.</h2>
        <p class="section-lede reveal">Discover &rarr; Build &rarr; Equip &rarr; Experience &rarr; Measure &rarr; Celebrate &rarr; Multiply. The same rhythm guides every centre, every cohort, every child.</p>
      </div>
      <div class="mth-cycle">
        <div class="mth-stage reveal"><div class="mth-stage__badge"><span class="mth-stage__num">1</span><span class="mth-stage__verb">Discover</span></div><div><div class="mth-stage__t">Discover</div><div class="mth-stage__sub">Community intelligence &amp; partnership development</div><div class="mth-stage__d">Transformation begins by understanding the community. Children are identified through schools, referrals and outreach, then comprehensively profiled so each receives an individualized development pathway.</div><div class="mth-chips"><span class="mth-chip">Needs assessment</span><span class="mth-chip">Parent sensitization</span><span class="mth-chip">Government partnerships</span><span class="mth-chip">Baseline data</span><span class="mth-chip">Child safeguarding</span><span class="mth-chip">MOUs</span></div></div></div>
        <div class="mth-stage reveal"><div class="mth-stage__badge"><span class="mth-stage__num">2</span><span class="mth-stage__verb">Build</span></div><div><div class="mth-stage__t">Build</div><div class="mth-stage__sub">Character formation &amp; identity re-engineering</div><div class="mth-stage__d">Transformation begins with identity before ability. Structured formation builds character daily — it is measured, not assumed.</div><div class="mth-chips"><span class="mth-chip">Anchor Journal</span><span class="mth-chip">Moral Intelligence</span><span class="mth-chip">Leadership Masterclasses</span><span class="mth-chip">Mentorship</span><span class="mth-chip">CEO Talks</span><span class="mth-chip">Daily Accountability</span></div></div></div>
        <div class="mth-stage reveal"><div class="mth-stage__badge"><span class="mth-stage__num">3</span><span class="mth-stage__verb">Equip</span></div><div><div class="mth-stage__t">Equip</div><div class="mth-stage__sub">Academy-based learning</div><div class="mth-stage__d">Every child is deployed into specialized academies where theory immediately becomes practice.</div><div class="mth-chips"><span class="mth-chip">AI &amp; Technology</span><span class="mth-chip">Entrepreneurship</span><span class="mth-chip">Media Production</span><span class="mth-chip">Creative Arts</span><span class="mth-chip">Coding</span><span class="mth-chip">Financial Literacy</span></div></div></div>
        <div class="mth-stage reveal"><div class="mth-stage__badge"><span class="mth-stage__num">4</span><span class="mth-stage__verb">Experience</span></div><div><div class="mth-stage__t">Experience</div><div class="mth-stage__sub">The community-impact challenge</div><div class="mth-stage__d">Every child performs and documents at least one verified act of community service. Learning becomes visible through service.</div><div class="mth-chips"><span class="mth-chip">Cleaning public spaces</span><span class="mth-chip">Reading to younger children</span><span class="mth-chip">Tutoring peers</span><span class="mth-chip">Tree planting</span><span class="mth-chip">Environmental sanitation</span></div></div></div>
        <div class="mth-stage reveal"><div class="mth-stage__badge"><span class="mth-stage__num">5</span><span class="mth-stage__verb">Measure</span></div><div><div class="mth-stage__t">Measure</div><div class="mth-stage__sub">Continuous monitoring &amp; evaluation</div><div class="mth-stage__d">Transformation must be measurable. The Genius Scorecard evaluates centres across attendance, skills, innovation, leadership, reading and community service — backed by weekly audits and digital attendance.</div><div class="mth-chips"><span class="mth-chip">Character growth</span><span class="mth-chip">Skill acquisition</span><span class="mth-chip">Reading progress</span><span class="mth-chip">Innovation</span><span class="mth-chip">Parent participation</span></div></div></div>
        <div class="mth-stage reveal"><div class="mth-stage__badge"><span class="mth-stage__num">6</span><span class="mth-stage__verb">Celebrate</span></div><div><div class="mth-stage__t">Celebrate</div><div class="mth-stage__sub">The annual Street-To-Stardom Expo</div><div class="mth-stage__d">Transformation deserves recognition. Children showcase their achievements — and excellence is reinforced publicly.</div><div class="mth-chips"><span class="mth-chip">Innovation Fair</span><span class="mth-chip">AI Projects</span><span class="mth-chip">Music Performances</span><span class="mth-chip">Book Launch</span><span class="mth-chip">Awards Ceremony</span><span class="mth-chip">Leadership Certification</span></div></div></div>
        <div class="mth-stage reveal"><div class="mth-stage__badge"><span class="mth-stage__num">7</span><span class="mth-stage__verb">Multiply</span></div><div><div class="mth-stage__t">Multiply</div><div class="mth-stage__sub">The Next Generation Genius (NGG) Network</div><div class="mth-stage__d">Transformation does not end at graduation. Graduates mentor younger participants, lead clubs, and replicate the STS model in new communities. Every transformed child becomes a multiplier of transformation.</div><div class="mth-chips"><span class="mth-chip">Mentor juniors</span><span class="mth-chip">Lead school clubs</span><span class="mth-chip">Community projects</span><span class="mth-chip">Ethical-leadership ambassadors</span></div></div></div>
      </div>
    </div>
  </section>

  <!-- ── Daily formation + decentralized model ────────────────────── -->
  <section class="section" data-screen-label="Methodology · Daily formation" style="padding-top:64px;padding-bottom:72px;background:var(--surface);border-top:1px solid var(--hairline);border-bottom:1px solid var(--hairline);">
    <div class="section-inner">
      <span class="eyebrow reveal">Structured daily formation</span>
      <h2 class="section-title reveal" style="font-size:clamp(28px,3.4vw,36px);">One standardized programme day — discipline, consistency and holistic growth.</h2>
      <div class="mth-day">
        <div class="mth-day__item reveal"><span class="mth-day__dot"></span><span class="mth-day__txt">Arrival, environmental sanitation &amp; grooming inspection</span></div>
        <div class="mth-day__item reveal"><span class="mth-day__dot"></span><span class="mth-day__txt">Morning energizer &amp; musing session</span></div>
        <div class="mth-day__item reveal"><span class="mth-day__dot"></span><span class="mth-day__txt">Anchor Journal — Values &middot; Vision &middot; Virtues</span></div>
        <div class="mth-day__item reveal"><span class="mth-day__dot"></span><span class="mth-day__txt">Leadership Masterclass with CEOs &amp; community leaders</span></div>
        <div class="mth-day__item reveal"><span class="mth-day__dot"></span><span class="mth-day__txt">Specialized academy training</span></div>
        <div class="mth-day__item reveal"><span class="mth-day__dot"></span><span class="mth-day__txt">Break &amp; networking</span></div>
        <div class="mth-day__item reveal"><span class="mth-day__dot"></span><span class="mth-day__txt">Chess &amp; strategic thinking</span></div>
        <div class="mth-day__item reveal"><span class="mth-day__dot"></span><span class="mth-day__txt">Reflection, appraisal &amp; clean-up</span></div>
      </div>
      <div class="duo-cards" style="margin-top:44px;">
        <div class="duo-card blue reveal">
          <span class="duo-label">Decentralized by design</span>
          <h3>Many community centres. One vision.</h3>
          <p>To scale without compromising quality, STS runs through community centres led by Centre Directors under a Central Steering Committee — each following one vision, one curriculum, one handbook, one assessment system, one reporting structure and one legacy project.</p>
        </div>
        <div class="duo-card crimson reveal">
          <span class="duo-label">Family &amp; community integration</span>
          <h3>Transformation extends into homes and neighbourhoods.</h3>
          <p>Children thrive when families participate. Parenting seminars, parent-child projects, family mentorship, community dialogues and school partnerships carry the work beyond the classroom.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ── Theory of change ─────────────────────────────────────────── -->
  <section class="section" data-screen-label="Methodology · Theory of change" style="padding-top:72px;padding-bottom:48px;">
    <div class="section-inner">
      <span class="eyebrow reveal">Theory of change</span>
      <h2 class="section-title reveal" style="font-size:clamp(28px,3.4vw,36px);">From community engagement to societal transformation.</h2>
      <div class="mth-toc reveal">
        <span class="mth-toc__node">Community Engagement</span><span class="mth-toc__arrow">&rarr;</span>
        <span class="mth-toc__node">Child Enrollment</span><span class="mth-toc__arrow">&rarr;</span>
        <span class="mth-toc__node">Values Formation</span><span class="mth-toc__arrow">&rarr;</span>
        <span class="mth-toc__node">Leadership Development</span><span class="mth-toc__arrow">&rarr;</span>
        <span class="mth-toc__node">Skills Acquisition</span><span class="mth-toc__arrow">&rarr;</span>
        <span class="mth-toc__node">Mentorship</span><span class="mth-toc__arrow">&rarr;</span>
        <span class="mth-toc__node">Community Projects</span><span class="mth-toc__arrow">&rarr;</span>
        <span class="mth-toc__node">Family Reinforcement</span><span class="mth-toc__arrow">&rarr;</span>
        <span class="mth-toc__node">Monitoring &amp; Evaluation</span><span class="mth-toc__arrow">&rarr;</span>
        <span class="mth-toc__node">Recognition</span><span class="mth-toc__arrow">&rarr;</span>
        <span class="mth-toc__node">Alumni Leadership</span><span class="mth-toc__arrow">&rarr;</span>
        <span class="mth-toc__node final">Societal Transformation</span>
      </div>
    </div>
  </section>

  <!-- ── Expected outcomes + 2031 ─────────────────────────────────── -->
  <section class="section" data-screen-label="Methodology · Outcomes" style="padding-top:48px;padding-bottom:88px;">
    <div class="section-inner">
      <span class="eyebrow reveal">Expected outcomes</span>
      <h2 class="section-title reveal" style="font-size:clamp(28px,3.4vw,36px);">What this methodology is built to achieve.</h2>
      <div class="mth-outcomes">
        <div class="mth-outcome reveal"><svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M3 9.5l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Raise an incorruptible generation of ethical, competent leaders.</span></div>
        <div class="mth-outcome reveal"><svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M3 9.5l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Improve academic performance and school retention.</span></div>
        <div class="mth-outcome reveal"><svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M3 9.5l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Reduce youth involvement in cultism, violence and substance abuse.</span></div>
        <div class="mth-outcome reveal"><svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M3 9.5l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Develop future-ready competencies in technology, entrepreneurship and the arts.</span></div>
        <div class="mth-outcome reveal"><svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M3 9.5l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Strengthen family and community participation in child development.</span></div>
        <div class="mth-outcome reveal"><svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M3 9.5l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Build resilient volunteer and mentorship networks, and scalable community models across Africa.</span></div>
      </div>
      <div class="mth-2031 reveal">
        <span class="mth-2031__tag">The North Star</span>
        <div class="mth-2031__big">1,000,000</div>
        <div class="mth-2031__label">Incorruptible children raised by <strong>2031</strong> — the vision this entire methodology is engineered to deliver.</div>
      </div>
    </div>
  </section>

  <!-- ── CTA ──────────────────────────────────────────────────────── -->
  <section class="section cta-strip" data-screen-label="Page CTA">
    <div class="section-inner">
      <div class="cta-strip-inner reveal">
        <div>
          <div class="eyebrow" style="margin-bottom:12px;">See it in practice</div>
          <h2 class="section-title" style="font-size:clamp(28px,3vw,36px);max-width:600px;">The same cycle runs through every programme we deliver.</h2>
        </div>
        <div class="cta-strip-actions">
          <a class="btn btn-primary" href="/programs">Explore programs <span class="btn-arrow">&rarr;</span></a>
          <a class="btn btn-secondary" href="/get-involved">Get involved</a>
        </div>
      </div>
    </div>
  </section>
<?php
require $STS_ROOT.'/inc/footer.php';
