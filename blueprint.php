<?php
/**
 * blueprint.php — "Institutional Policy, Operational Blueprint & Continental
 * Scaling Plan". The internal governance charter of the Afrovanguard
 * ecosystem: student & volunteer conduct code, chain of command, crisis and
 * reinstatement protocols, the AV-Incentive Framework, and the 2026–2040
 * continental scaling plan to one million C-Level leaders.
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
    'title'     => 'Institutional Policy & Continental Scaling Plan — Afrovanguard',
    'desc'      => 'The Afrovanguard institutional charter: conduct code, chain of command, crisis protocols and the 2026–2040 continental scaling plan. Restricted to Afrovanguard members.',
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
  .bp-applies{font-size:12.5px;font-weight:700;color:var(--afg-muted,#6b7280);
    background:var(--afg-surface-2,#f4f2ec);border-radius:8px;padding:8px 12px;display:inline-block;margin:0 0 6px}

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

  /* strike escalation ladder */
  .bp-strikes{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:12px 0 0}
  .bp-strike{border:1px solid var(--afg-border,#e5e7eb);border-radius:12px;padding:14px 16px;background:var(--afg-surface,#fff)}
  .bp-strike .bp-strike-n{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--afg-muted,#6b7280)}
  .bp-strike h4{font-size:15px;margin:2px 0 6px;color:var(--afg-ink,#111827)}
  .bp-strike p{font-size:13.5px;line-height:1.55;margin:0;color:var(--afg-body,#374151)}
  .bp-strike--warn{border-top:3px solid #f3b416}
  .bp-strike--freeze{border-top:3px solid #ea8a00}
  .bp-strike--out{border-top:3px solid #dc2626}

  /* chain of command */
  .bp-chain{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:12px 0 0;font-size:13.5px;font-weight:700}
  .bp-chain span{background:var(--afg-surface-2,#f4f2ec);border:1px solid var(--afg-border,#e5e7eb);border-radius:999px;padding:7px 13px;color:var(--afg-ink,#111827)}
  .bp-chain .bp-arrow{background:none;border:none;color:var(--afg-accent-ink,#b07e08);padding:0 2px}
  .bp-chain span.bp-top{background:var(--afg-accent,#f3b416);color:#1a1300;border-color:transparent}

  /* callout / covenant */
  .bp-callout{border-left:4px solid var(--afg-accent,#f3b416);background:var(--afg-surface-2,#f4f2ec);
    border-radius:0 12px 12px 0;padding:16px 20px;margin:16px 0 0;font-size:15px;line-height:1.65}
  .bp-callout.is-danger{border-left-color:#dc2626}
  .bp-covenant{text-align:center;background:var(--afg-ink,#111827);color:#f4f2ec;border-radius:16px;padding:34px 30px;margin:20px 0 0}
  .bp-covenant p{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-size:clamp(19px,2.6vw,24px);
    line-height:1.4;font-style:italic;margin:0 0 8px}
  .bp-covenant small{font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.7}

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
    .bp-strikes,.bp-threeup{grid-template-columns:1fr}
  }
  body.is-dark .bp-card,body.is-dark .bp-strike,body.is-dark table.bp-table{background:var(--afg-surface,#fff)}
</style>

<?php if (!$isOrg): ?>
  <!-- ===================== ACCESS GATE (non-members) ===================== -->
  <main id="main-content" class="bp">
    <section class="bp-gate">
      <div class="bp-gate-card">
        <span class="bp-gate-badge">🔒 Members only</span>
        <h1>This is an Afrovanguard members' document</h1>
<?php if (!$u): ?>
        <p>The Institutional Policy, Operational Blueprint &amp; Continental Scaling Plan is restricted to verified Afrovanguard members. Sign in with your <strong>@afrovanguard.org.ng</strong> account to read it.</p>
        <div class="bp-gate-actions">
          <a class="bp-btn bp-btn-gold" href="<?= e(av_login_url('/blueprint')) ?>">Sign in →</a>
          <a class="bp-btn bp-btn-ghost" href="/">Back to site</a>
        </div>
<?php else: ?>
        <p>You’re signed in as <strong><?= e((string) $u['email']) ?></strong>, but this charter is reserved for Afrovanguard members. If you believe you should have access, contact the executive channel at <a href="mailto:cacentre@afrovanguard.org.ng">cacentre@afrovanguard.org.ng</a>.</p>
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
        <h1>Institutional Policy, Operational Blueprint &amp; Continental Scaling Plan</h1>
        <p class="bp-lead">The internal charter of the Afrovanguard ecosystem — how we hold the line on discipline and order, and how we scale that culture to one million incorruptible leaders by 2040.</p>
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
          <a href="#students">I · Student Blueprint</a>
          <a href="#vanguard">II · Volunteer Vanguard Code</a>
          <a href="#order">III · Order &amp; Crisis Protocol</a>
          <a href="#reinstatement">IV · Reinstatement</a>
          <a href="#incentives">V · AV-Incentive Framework</a>
          <a href="#deadlines">VI · Absolute Lateness Deadlines</a>
          <a href="#covenant">The Vanguard Covenant</a>
          <a href="#scaling">Continental Scaling Plan</a>
        </nav>

        <!-- DOCUMENT -->
        <article class="bp-doc">

          <!-- ============ PART I — STUDENTS ============ -->
          <section class="bp-part first" id="students">
            <p class="bp-part-eyebrow">Part I</p>
            <h2>The Student Blueprint</h2>
            <span class="bp-applies">Applies to all students across Street-To-Stardom and LCASP initiatives.</span>
            <p>We are building elite Cultural Architects. Passivity and casual attendance are not tolerated — the standards below are enforced by the AI monitoring system, not by verbal discretion on the floor.</p>

            <h3>1.1 Attendance &amp; the “3-Strike” rule</h3>
            <p>Students are permitted a maximum of <strong>two (2) absences</strong> for the entire programme, and only for valid, verified emergencies.</p>
            <div class="bp-strikes">
              <div class="bp-strike bp-strike--warn"><span class="bp-strike-n">Strike 1</span><h4>Warning</h4><p>First absence logged. A formal warning is recorded on the profile.</p></div>
              <div class="bp-strike bp-strike--freeze"><span class="bp-strike-n">Strike 2</span><h4>Dormancy status</h4><p>Second absence logged. The student moves to the “Dormant Track” — all privileges, special activities and eligibility for the ₦500k Awards are frozen.</p></div>
              <div class="bp-strike bp-strike--out"><span class="bp-strike-n">Strike 3</span><h4>Immediate eviction</h4><p>On a third missed session the AI flags the profile for permanent expulsion — no re-entry, no certificate, and an absolute forfeit of all project showcases.</p></div>
            </div>

            <h3>1.2 Lateness &amp; suspension thresholds</h3>
            <ul class="bp-list">
              <li><strong>The 7:00 AM standard.</strong> Under the 1-Hour Advance Resumption Protocol, if the programme block opens at 8:00 AM, the student check-in deadline is <strong>7:00 AM sharp</strong>.</li>
              <li><strong>The lateness cap.</strong> Three (3) instances of lateness trigger an automatic suspension.</li>
              <li><strong>Post-suspension enforcement.</strong> Two (2) further late arrivals after returning from suspension force an ultimatum: a non-negotiable <strong>₦5,000 fine</strong> or immediate eviction.</li>
            </ul>

            <h3>1.3 Check-in &amp; AI verification</h3>
            <ul class="bp-list">
              <li>Every student must physically scan their digital registration slip or student ID <strong>in and out</strong> at the designated checkpoint, every day.</li>
              <li>The monitoring AI tracks compliance in real time. A missed scan is logged as a <strong>total absence</strong> for that day — no verbal excuses, no manual overrides.</li>
              <li><strong>The Musing Period is 100% compulsory.</strong> Arriving late to it, or missing it, automatically downgrades the day to a half-day and accelerates the path to the Dormant Track.</li>
            </ul>
          </section>

          <!-- ============ PART II — VOLUNTEERS ============ -->
          <section class="bp-part" id="vanguard">
            <p class="bp-part-eyebrow">Part II</p>
            <h2>The Volunteer Vanguard Code</h2>
            <span class="bp-applies">Applies to all Volunteers, Instructors, Mentors and Coordinators.</span>
            <p>Volunteers do not follow the schedule of the public; they set the standard the public follows.</p>

            <h3>2.1 The “Africa Time” paradigm &amp; lateness limits</h3>
            <ul class="bp-list">
              <li><strong>Before 7:00 AM resumption.</strong> The Vanguard runs on a strict one-hour advance resumption protocol. If general movement or briefings begin at 8:00 AM, the volunteer check-in window closes <strong>before 7:00 AM sharp</strong>.</li>
              <li><strong>Lateness cap.</strong> Four (4) instances of lateness trigger an automatic institutional suspension.</li>
              <li><strong>Post-suspension enforcement.</strong> Two (2) further late arrivals after serving a suspension result in an immediate <strong>₦10,000 fine</strong> or permanent eviction from the Vanguard.</li>
              <li><strong>Mandatory Review Time.</strong> All volunteers, mentors and instructors must attend the daily/weekly post-programme Review Time. Missing it without written executive clearance is a direct administrative infraction.</li>
            </ul>

            <h3>2.2 ID credentials &amp; financial penalties</h3>
            <p>Professional identification is non-negotiable for security and institutional branding.</p>
            <ul class="bp-list">
              <li><strong>First-time oversight.</strong> A volunteer arriving without their official ID is issued a temporary Volunteer Emergency Card for that single day only.</li>
              <li><strong>Second-time fine — ₦1,000.</strong> Forgetting or failing to wear the official ID a second time triggers an automatic, non-negotiable ₦1,000 fine.</li>
              <li><strong>Lost ID card — ₦2,000.</strong> A lost ID must be replaced immediately at a fee of ₦2,000 to mint a new digital credential.</li>
            </ul>

            <h3>2.3 Professional dormancy, suspension &amp; eviction</h3>
            <div class="bp-card">
              <ul class="bp-list">
                <li><strong>Dormancy.</strong> Triggered by declining energy, missed Review Times or low engagement. The volunteer is un-deployed from active projects and benched until cleared by management.</li>
                <li><strong>Suspension.</strong> Triggered by the 4-lateness threshold, severe administrative negligence, failure to enforce student policies, or public insubordination. Result: a <strong>14-day total ban</strong> from CACENTRE premises and digital channels.</li>
                <li><strong>Eviction.</strong> Triggered by critical post-suspension lateness, financial misconduct, gross subversion of the vision, or unapproved absences. Result: total termination of association and blacklisting.</li>
              </ul>
            </div>
          </section>

          <!-- ============ PART III — ORDER & CRISIS ============ -->
          <section class="bp-part" id="order">
            <p class="bp-part-eyebrow">Part III</p>
            <h2>The Architecture of Order &amp; Crisis Protocol</h2>
            <span class="bp-applies">Applies equally to everyone within the Afrovanguard ecosystem.</span>

            <h3>3.1 The chain of command — order over age</h3>
            <p>Authority is determined strictly by organizational structure and visionary lineage — never by biological age. A younger leader holding a superior operational office commands absolute cooperation from everyone beneath them. The ladder is respected without exception:</p>
            <div class="bp-chain">
              <span>Student</span><span class="bp-arrow">→</span>
              <span>Volunteer</span><span class="bp-arrow">→</span>
              <span>Center Leader</span><span class="bp-arrow">→</span>
              <span>Group Centre Leaders</span><span class="bp-arrow">→</span>
              <span>NGV</span><span class="bp-arrow">→</span>
              <span>Afrovanguard Members</span><span class="bp-arrow">→</span>
              <span class="bp-top">The Governing Council</span>
            </div>

            <h3>3.2 The honour &amp; silence mandate</h3>
            <ul class="bp-list">
              <li><strong>The Silence Protocol.</strong> Absolute decorum and silence in the immediate presence of superiors or during active strategy sessions.</li>
              <li><strong>Usurpation.</strong> Talking over a superior officer, arguing directives in public, or bypassing the chain of command is a direct act of dishonour against the vision and our collective sacrifices.</li>
            </ul>

            <h3>3.3 Crisis resolution &amp; the safety valve</h3>
            <p>Grievances must never breed gossip or on-site insubordination.</p>
            <ol class="bp-list is-num">
              <li><strong>On-ground issues</strong> flow step-by-step up the chain of command (e.g. Student → Volunteer → Center Leader).</li>
              <li><strong>Formal escalation.</strong> If an action feels unfairly resolved on the floor, causing a scene is strictly prohibited. The aggrieved party must document the case and transmit it digitally to the executive portal at <a href="mailto:cacentre@afrovanguard.org.ng">cacentre@afrovanguard.org.ng</a>.</li>
            </ol>
          </section>

          <!-- ============ PART IV — REINSTATEMENT ============ -->
          <section class="bp-part" id="reinstatement">
            <p class="bp-part-eyebrow">Part IV</p>
            <h2>Reinstatement Protocols After Eviction</h2>
            <p>Eviction is severe, but not entirely the end of the road for those genuinely broken and willing to submit to structural alignment. To be reintegrated into the ecosystem, an evicted student or volunteer must fulfil <strong>all</strong> of the following:</p>
            <ol class="bp-list is-num">
              <li><strong>The 365-day cool-off period.</strong> Complete bar from all premises, digital networks and programmes for one full calendar year from the date of eviction. No appeals are reviewed during this time.</li>
              <li><strong>Mandatory institutional training.</strong> After the one-year ban, the individual must register for and pass the intensive <strong>CIMC 1</strong> (Community Influencing Master Class 1) track to realign their mindset with our cultural architecture.</li>
              <li><strong>Reinstatement fine.</strong> A non-negotiable ₦10,000 processing and clearing fine, paid in full to the institutional treasury, before the AI digital profile and access credentials are reactivated.</li>
            </ol>
          </section>

          <!-- ============ PART V — INCENTIVES ============ -->
          <section class="bp-part" id="incentives">
            <p class="bp-part-eyebrow">Part V</p>
            <h2>The AV-Incentive Framework &amp; Awards</h2>
            <p>To prove that character, discipline and integrity pay higher dividends than compromise, the Governing Council has instituted financial awards for students and volunteers who model flawless compliance.</p>
            <div class="bp-callout">
              <strong>Eligibility is earned, not given.</strong> Only members in good standing qualify — the ₦500k Awards (Part I) are frozen the moment a student reaches Dormancy status. Flawless attendance, punctuality and conduct are the entry ticket.
            </div>
            <h3>5.1 Prize metrics &amp; categories</h3>
            <p>The full prize schedule and category breakdown is being finalized by the Governing Council and will be published to members here. Awards recognise, at minimum:</p>
            <ul class="bp-list">
              <li>Flawless attendance and punctuality across the full programme duration.</li>
              <li>Exemplary conduct within the chain of command and the honour &amp; silence mandate.</li>
              <li>Standout project showcases and demonstrated leadership of peers.</li>
            </ul>
          </section>

          <!-- ============ PART VI — DEADLINES ============ -->
          <section class="bp-part" id="deadlines">
            <p class="bp-part-eyebrow">Part VI</p>
            <h2>The Absolute Lateness Deadlines</h2>
            <p>Early resumption is heavily rewarded; these are the exact thresholds where an arrival transitions from “tardy” to an actionable institutional breach.</p>
            <div class="bp-tablewrap">
              <table class="bp-table">
                <thead><tr><th>Cohort</th><th>Redline (flagged LATE)</th><th>Triggers suspension</th></tr></thead>
                <tbody>
                  <tr><td>Students</td><td class="bp-num">Scan in at 8:30 AM or later</td><td>3 late logs</td></tr>
                  <tr><td>Volunteers, Mentors &amp; Instructors</td><td class="bp-num">Scan in at 9:00 AM or later</td><td>4 late logs</td></tr>
                </tbody>
              </table>
            </div>
            <div class="bp-callout is-danger">
              <strong>System sync note.</strong> The AI tracking engine is locked to these exact timestamps. No manual overrides, no verbal adjustments. You are either beating the clock, or the system is logging your infractions.
            </div>
          </section>

          <!-- ============ COVENANT ============ -->
          <section class="bp-part" id="covenant">
            <div class="bp-covenant">
              <p>“We do not build systems based on convenience; we build them based on character. Every scan, every silent room, and every escalated email is proof that we respect the future we are building. Guard the order, protect the vision.”</p>
              <small>The Vanguard Covenant</small>
            </div>
          </section>

          <!-- ============ CONTINENTAL SCALING PLAN ============ -->
          <section class="bp-part" id="scaling">
            <p class="bp-part-eyebrow">Strategic Annex</p>
            <h2>Continental Scaling Plan to 1,000,000 C-Level Leaders (2040)</h2>
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

            <!-- Phase 1 -->
            <div class="bp-phase">
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

            <!-- Phase 2 -->
            <div class="bp-phase">
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

            <!-- Phase 3 -->
            <div class="bp-phase">
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

            <!-- Phase 4 -->
            <div class="bp-phase">
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

            <h3>What one mature CACENTRE must produce yearly</h3>
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

            <h3>Organizational structure needed to manage 2,000 CACENTREs</h3>
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

            <h3>The cultural engine — three things that must never stop</h3>
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
