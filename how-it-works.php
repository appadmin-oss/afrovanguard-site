<?php
/**
 * how-it-works.php — "How Afrovanguard Works": the Progressive Growth &
 * Member Alignment Framework. A public page explaining the membership
 * progression (Level O → A → C…), advancement, contributions and the
 * servant-leadership culture. Served at /how-it-works.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$MONTHLY = defined('AV_DUES_MONTHLY_NGN') ? (int) AV_DUES_MONTHLY_NGN : 1000;
$ANNUAL  = defined('AV_DUES_ANNUAL_NGN')  ? (int) AV_DUES_ANNUAL_NGN  : 12000;

render_head([
    'title'     => 'How Afrovanguard Works — Progressive Growth & Member Alignment',
    'desc'      => 'How members grow at Afrovanguard: a structured progression built on commitment, mentorship, service and leadership — from Foundation Member (Level O) upward.',
    'canonical' => rtrim(SITE_URL, '/') . '/how-it-works',
    'body_class' => 'hiw-page',
]);
render_nav('about');
?>
<style>
  .hiw{--hiw-line:var(--afg-border,#e5e7eb);color:var(--afg-body,#374151);
    font-family:var(--afg-font-body,'Montserrat',system-ui,sans-serif)}
  .hiw .hiw-in{max-width:920px;margin:0 auto;padding:0 20px}
  .hiw-hero{background:var(--afg-surface-2,#f4f2ec);border-bottom:1px solid var(--afg-border,#e5e7eb);
    padding:8px 20px 44px}
  .hiw-hero .page-lead{max-width:640px}
  .hiw-body{padding:52px 0 72px}
  .hiw-intro{font-size:18px;line-height:1.7;margin:0 0 8px}
  .hiw-note{font-size:15px;color:var(--afg-muted,#6b7280);margin:0 0 40px}
  /* progression ladder */
  .hiw-ladder{position:relative;margin:0;padding:0;list-style:none}
  .hiw-ladder::before{content:"";position:absolute;left:27px;top:12px;bottom:12px;width:2px;background:var(--hiw-line)}
  .hiw-step{position:relative;padding:0 0 34px 74px}
  .hiw-badge{position:absolute;left:0;top:0;width:56px;height:56px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;font-family:var(--afg-font-display,'Cormorant',Georgia,serif);
    font-weight:700;font-size:26px;color:var(--afg-on-accent,#111827);background:var(--afg-accent,#f3b416);
    box-shadow:0 8px 22px -10px rgba(0,0,0,.4);z-index:1}
  .hiw-step.is-goal .hiw-badge{background:var(--afg-ink,#111827);color:#fff}
  .hiw-step h2{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-weight:700;
    font-size:26px;line-height:1.15;margin:6px 0 2px;color:var(--afg-ink,#111827)}
  .hiw-step .hiw-kicker{font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;
    color:var(--afg-accent-ink,#b07e08);margin:0 0 8px}
  .hiw-card{background:var(--afg-surface,#fff);border:1px solid var(--afg-border,#e5e7eb);
    border-radius:var(--afg-radius-md,14px);padding:18px 20px;margin:12px 0 0;box-shadow:var(--afg-shadow-sm,0 10px 30px -20px rgba(17,24,39,.28))}
  .hiw-card h3{font-size:14px;font-weight:800;letter-spacing:.02em;margin:0 0 10px;color:var(--afg-ink,#111827)}
  .hiw-list{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:9px}
  .hiw-list li{position:relative;padding-left:26px;font-size:15px;line-height:1.55}
  .hiw-list li::before{content:"";position:absolute;left:2px;top:8px;width:8px;height:8px;border-radius:50%;
    background:var(--afg-accent,#f3b416)}
  .hiw-list.is-check li::before{content:"✓";left:0;top:0;width:auto;height:auto;background:none;
    color:var(--afg-success,#16a34a);font-weight:800}
  /* contribution + culture blocks */
  .hiw-panel{margin:44px 0 0;background:var(--afg-surface,#fff);border:1px solid var(--afg-border,#e5e7eb);
    border-left:4px solid var(--afg-accent,#f3b416);border-radius:var(--afg-radius-md,14px);padding:26px 24px}
  .hiw-panel h2{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-weight:700;font-size:26px;
    margin:0 0 8px;color:var(--afg-ink,#111827)}
  .hiw-amounts{display:flex;gap:14px;flex-wrap:wrap;margin:16px 0}
  .hiw-amt{flex:1;min-width:150px;background:var(--afg-surface-2,#f4f2ec);border-radius:var(--afg-radius-sm,8px);
    padding:16px 18px;text-align:center}
  .hiw-amt .n{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-weight:700;font-size:30px;
    color:var(--afg-accent-ink,#b07e08);display:block;line-height:1}
  .hiw-amt .u{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--afg-muted,#6b7280)}
  .hiw-mand{font-size:14px;color:var(--afg-body,#374151);margin:6px 0 0}
  .hiw-mand strong{color:var(--afg-ink,#111827)}
  .hiw-quote{font-family:var(--afg-font-display,'Cormorant',Georgia,serif);font-size:24px;line-height:1.35;
    color:var(--afg-ink,#111827);text-align:center;margin:44px auto 0;max-width:640px}
  /* CTA */
  .hiw-cta{margin:40px 0 0;text-align:center;display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
  .hiw-btn{display:inline-flex;align-items:center;gap:6px;padding:13px 22px;border-radius:var(--afg-radius-pill,999px);
    font-weight:700;font-size:15px;text-decoration:none;border:1px solid transparent}
  .hiw-btn-primary{background:var(--afg-accent,#f3b416);color:var(--afg-on-accent,#111827)}
  .hiw-btn-ghost{background:transparent;color:var(--afg-ink,#111827);border-color:var(--afg-border,#e5e7eb)}
  .hiw-btn:focus-visible{outline:2px solid var(--afg-focus,#1d4ed8);outline-offset:2px}
  @media(max-width:560px){.hiw-step{padding-left:66px}.hiw-badge{width:48px;height:48px;font-size:22px}.hiw-ladder::before{left:23px}}
</style>

<main id="main-content" class="hiw">
  <header class="hiw-hero">
    <div class="hiw-in">
      <div class="page-head page-head--center" style="padding:0">
        <p class="page-eyebrow">How Afrovanguard Works</p>
        <h1 class="page-title">Progressive Growth &amp; Member Alignment</h1>
        <p class="page-lead">We develop leaders through a structured progression built on personal growth, accountability, service, leadership and alignment with our vision and values.</p>
      </div>
    </div>
  </header>

  <div class="hiw-body">
    <div class="hiw-in">
      <p class="hiw-intro">Advancement at Afrovanguard is earned through demonstrated commitment, leadership capacity and consistent contribution — <strong>not merely by length of membership</strong>. Everyone starts on the same footing and grows by showing up, serving and bringing others along.</p>
      <p class="hiw-note">Here is the path from your first day to organisational leadership.</p>

      <ol class="hiw-ladder">
        <li class="hiw-step">
          <span class="hiw-badge" aria-hidden="true">O</span>
          <p class="hiw-kicker">Where everyone begins</p>
          <h2>Level&nbsp;O — Foundation Member</h2>
          <p>Every new member joins Afrovanguard as a Level&nbsp;O Member. At this stage you are expected to:</p>
          <div class="hiw-card">
            <ul class="hiw-list">
              <li>Select a mentor from the approved mentorship structure.</li>
              <li>Participate actively in Afrovanguard programmes and activities.</li>
              <li>Complete at least one assigned task or service responsibility each week.</li>
              <li>Demonstrate consistency, accountability and a willingness to learn.</li>
              <li>Begin understanding and living the Afrovanguard culture and values.</li>
            </ul>
          </div>
        </li>

        <li class="hiw-step">
          <span class="hiw-badge" aria-hidden="true">A</span>
          <p class="hiw-kicker">Advancement</p>
          <h2>Level&nbsp;A Membership</h2>
          <p>A Level&nbsp;O Member becomes eligible for Level&nbsp;A after developing leadership capacity through the approved progression pathway. Requirements include:</p>
          <div class="hiw-card">
            <ul class="hiw-list">
              <li>Personally introducing <strong>two committed members</strong> into the Afrovanguard community.</li>
              <li>Supporting and mentoring them through their initial growth and integration.</li>
              <li>Demonstrating consistent participation, service and alignment with our mission and values.</li>
            </ul>
          </div>
          <div class="hiw-card">
            <h3>On attaining Level&nbsp;A, you will</h3>
            <ul class="hiw-list is-check">
              <li>Receive an official Afrovanguard email address.</li>
              <li>Be assigned an accountability mentor for guidance, leadership development and performance support.</li>
              <li>Become eligible for additional leadership responsibilities and organisational opportunities.</li>
            </ul>
          </div>
        </li>

        <li class="hiw-step is-goal">
          <span class="hiw-badge" aria-hidden="true">C</span>
          <p class="hiw-kicker">Toward leadership</p>
          <h2>Level&nbsp;C and beyond</h2>
          <p>As members progress, dues become part of the responsibility of leadership, and servant-leadership becomes the standard (see below). Advancement continues to be earned through service and contribution.</p>
        </li>
      </ol>

      <section class="hiw-panel" aria-labelledby="hiw-contrib">
        <h2 id="hiw-contrib">Membership contribution</h2>
        <p>Beginning from Level&nbsp;A, members may <strong>voluntarily</strong> contribute to the sustainability and growth of the mission:</p>
        <div class="hiw-amounts">
          <div class="hiw-amt"><span class="n">&#8358;<?= number_format($MONTHLY) ?></span><span class="u">per month</span></div>
          <div class="hiw-amt"><span class="n">&#8358;<?= number_format($ANNUAL) ?></span><span class="u">per year</span></div>
        </div>
        <p class="hiw-mand">From <strong>Level&nbsp;C upward</strong>, payment of membership dues becomes <strong>mandatory</strong> — part of the responsibilities of organisational leadership. Members can pay or renew their dues any time in the <a href="/portal/">member portal</a>.</p>
      </section>

      <section class="hiw-panel" aria-labelledby="hiw-culture">
        <h2 id="hiw-culture">Leadership culture</h2>
        <p>As members progress, they embrace a culture of <strong>servant leadership</strong>. Before qualifying for Level&nbsp;C, every member must show a willingness to:</p>
        <div class="hiw-card">
          <ul class="hiw-list">
            <li>Arrive early for meetings, programmes and events to support setup and preparation.</li>
            <li>Serve behind the scenes before participating as an attendee.</li>
            <li>Consistently place the success of the mission above personal convenience.</li>
          </ul>
        </div>
        <p class="hiw-mand" style="margin-top:14px">As a minimum, members should arrive at least <strong>30 minutes</strong> before the scheduled start — and, where required, <strong>2–3 hours</strong> earlier for major programmes or events.</p>
      </section>

      <p class="hiw-quote">“Afrovanguard's belief is simple: leadership begins with service. Those who faithfully serve are prepared to lead.”</p>

      <div class="hiw-cta">
        <a class="hiw-btn hiw-btn-primary" href="/portal/">Go to your portal</a>
        <a class="hiw-btn hiw-btn-ghost" href="/mentorship/">Find a mentor</a>
        <a class="hiw-btn hiw-btn-ghost" href="/academy/#membership">Become a member</a>
      </div>
    </div>
  </div>
</main>

<?php render_footer(); ?>
