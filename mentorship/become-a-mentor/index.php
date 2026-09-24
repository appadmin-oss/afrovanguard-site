<?php
/**
 * mentorship/become-a-mentor/index.php — the public invitation to mentor.
 *
 * /mentorship/ is the signed-in tool: it finds mentors, runs the mentee inbox
 * and publishes a mentor profile, and it is noindex behind a login gate. That
 * left the nav's "Mentor a young leader" landing an anonymous visitor on a
 * sign-in wall with nothing explaining what they were being asked to join.
 *
 * This is that missing page: public, indexable, and written as a standard
 * rather than a brochure, because it is asking someone to accept an obligation.
 * It ends by routing the reader to the right next step rather than by
 * pretending everyone can act on it today.
 *
 * The spine is the Vanguard Quest: the Quest makes a person (seven values, ten
 * observable challenges each, points for evidence rather than for knowing the
 * answer), and mentoring is how a person who has been made goes on to make
 * others. Level 3 is where you begin; Level 4 is where the culture reproduces.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$canonical = rtrim(SITE_URL, '/') . '/mentorship/become-a-mentor/';
$org = defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng';

render_head([
    'title'     => 'Become a Mentor — Afrovanguard',
    'desc'      => 'Mentoring at Afrovanguard is measured by evidence, not attendance. What we ask of a mentor, what you will actually do, and how to begin.',
    'canonical' => $canonical,
    // page.css is already in the shared head; only the page's own sheet here.
    'css'       => ['/assets/site/mentor.css'],
    'jsonld'    => [
        '@context' => 'https://schema.org',
        '@type'    => 'WebPage',
        'name'     => 'Become a Mentor — Afrovanguard',
        'url'      => $canonical,
        'description' => 'The Afrovanguard mentorship standard: what we ask of a mentor, what a mentor does, and how mentoring completes the Vanguard Quest.',
        'isPartOf' => ['@type' => 'WebSite', 'name' => 'Afrovanguard', 'url' => rtrim(SITE_URL, '/') . '/'],
    ],
]);
render_nav('involved');
?>
<main id="main-content" class="mt">

  <!-- ── The ask ───────────────────────────────────────────────────────── -->
  <section class="mt-section">
    <div class="container">
      <header class="page-head" style="padding-top:0">
        <p class="page-eyebrow">Afrovanguard Mentorship</p>
        <h1 class="page-title">Become a Mentor</h1>
        <p class="page-lead">Growing people. Building leaders. Multiplying good.</p>
      </header>

      <p class="mt-lead">Mentorship here is not a senior person assigned to a junior one. It is a deliberate
        relationship for character, competence, accountability, leadership, service — and multiplication.</p>

      <p class="mt-lead"><strong>An Afrovanguard mentor does not simply know the way.
        A mentor is walking it, and helping someone else walk it.</strong></p>

      <blockquote class="mt-ask">
        <q>Who is becoming better because I am here?</q>
        <footer>Every mentor answers this one. It is the whole standard in a sentence.</footer>
      </blockquote>
    </div>
  </section>

  <!-- ── Where mentoring sits in the Quest ─────────────────────────────── -->
  <section class="mt-section mt-section--dark" aria-labelledby="quest-h">
    <div class="container">
      <p class="mt-kicker">The Vanguard Quest</p>
      <h2 class="mt-h" id="quest-h">Mentoring is not an extra.<br>It is how the Quest <em>completes</em>.</h2>
      <p class="mt-lead">The Quest is seven values, ten observable challenges each — seventy points in all. You do not
        earn a point for attending, and you do not earn one for knowing the right answer. You earn it by showing
        evidence that something in you changed. Mentoring is where that logic turns outward: having been formed,
        you go and form somebody.</p>

      <ol class="mt-ladder">
        <li class="mt-step">
          <span class="mt-step-n">Level 0</span>
          <span class="mt-step-b"><span class="mt-step-t">Visitor</span>
            <span class="mt-step-d">You meet the culture. Come as you are — but don't stay as you are.</span></span>
        </li>
        <li class="mt-step">
          <span class="mt-step-n">Level 1</span>
          <span class="mt-step-b"><span class="mt-step-t">Explorer</span>
            <span class="mt-step-d">Self-examination begins. You start telling yourself the truth.</span></span>
        </li>
        <li class="mt-step">
          <span class="mt-step-n">Level 2</span>
          <span class="mt-step-b"><span class="mt-step-t">Practitioner</span>
            <span class="mt-step-d">The values stop being vocabulary and start being conduct.</span></span>
        </li>
        <li class="mt-step mt-step--here">
          <span class="mt-step-n">Level 3</span>
          <span class="mt-step-b"><span class="mt-step-t">Contributor — you begin mentoring here</span>
            <span class="mt-step-d">You are steady enough that someone else can lean on you without it costing them.</span></span>
        </li>
        <li class="mt-step">
          <span class="mt-step-n">Level 4</span>
          <span class="mt-step-b"><span class="mt-step-t">Vanguard</span>
            <span class="mt-step-d">You reproduce the culture. Your mentees are mentoring.</span></span>
        </li>
      </ol>

      <p class="mt-lead" style="margin-top:28px">Seventy out of seventy does not mean finished. It means ready for
        greater responsibility — and mentoring is that responsibility.</p>
    </div>
  </section>

  <!-- ── What we ask of you ────────────────────────────────────────────── -->
  <section class="mt-section" aria-labelledby="ask-h">
    <div class="container">
      <p class="mt-kicker">Before you mentor</p>
      <h2 class="mt-h" id="ask-h">Four things we ask</h2>
      <p class="mt-lead">None of them are paperwork. Each one is something a mentee will read off you within a month,
        whatever you say in a session.</p>

      <div class="mt-grid mt-grid--2">
        <article class="mt-card">
          <span class="mt-card-n">01</span>
          <h3>Keep Africa Time</h3>
          <p>Fifteen minutes to an hour <em>before</em> time. We are deliberately redefining African time from a
            culture of lateness into one of preparedness, honour and reliability.</p>
          <p>A mentor who arrives late teaches lateness, whatever they say when they get there. Punctuality is not
            an administrative requirement here. It is mentorship by example.</p>
        </article>
        <article class="mt-card">
          <span class="mt-card-n">02</span>
          <h3>Practise the seven values</h3>
          <p>Individuation, Faith, Diligence, Accountability, Responsibility, Cultural Appreciation, Communal Spirit
            — not recited, practised.</p>
          <p>We cannot reproduce in another person what we consistently refuse to do ourselves. Mentorship is
            founded on embodied values or it is founded on nothing.</p>
        </article>
        <article class="mt-card">
          <span class="mt-card-n">03</span>
          <h3>Complete CIMC 1 and 2</h3>
          <p>Completed, or a demonstrated commitment to complete them. CIMC is not a certificate to collect; it is
            the common ground — our culture, philosophy, expectations and leadership principles.</p>
          <p>Readiness to keep learning is itself a qualification for leadership.</p>
        </article>
        <article class="mt-card">
          <span class="mt-card-n">04</span>
          <h3>Mentor beyond the session</h3>
          <p>Mentorship has to be visible in ordinary life, not only in a scheduled meeting. You should be able to
            help someone think through:</p>
          <p><strong>Themselves</strong> — character, discipline, time, emotional maturity, decisions.<br>
            <strong>Their work</strong> — study, career, business, money, professional conduct.<br>
            <strong>Their people</strong> — relationships, communication, service, leadership.<br>
            <strong>Their growth</strong> — goals, problems, failure and recovery, and turning what they know into
            what they do.</p>
        </article>
      </div>

      <p class="mt-note">The goal is not people who perform well in a mentorship session. It is people who live well
        when nobody is watching.</p>
    </div>
  </section>

  <!-- ── Evidence, not attendance ──────────────────────────────────────── -->
  <section class="mt-section mt-section--tint" aria-labelledby="ev-h">
    <div class="container">
      <p class="mt-kicker">How it is measured</p>
      <h2 class="mt-h" id="ev-h">Evidence, not attendance</h2>
      <p class="mt-lead">Mentorship must not become a relationship where everyone feels inspired and nobody can point
        at anything that changed. The same rule that governs the Quest governs this: we do not award the point for
        knowing the right answer.</p>

      <div class="mt-versus">
        <div class="mt-vs mt-vs--weak">
          <h3>The question that proves nothing</h3>
          <q>“Did we meet?”</q>
        </div>
        <div class="mt-vs mt-vs--real">
          <h3>The questions we actually ask</h3>
          <q>“What changed?”</q>
          <q>“What did we build?”</q>
          <q>“What responsibility can this person now carry that they could not carry before?”</q>
          <q>“Who else is becoming better because of this relationship?”</q>
        </div>
      </div>

      <p class="mt-lead" style="margin-top:28px">Progress shows up in consistency, character, competence,
        accountability, goals actually met, service, leadership behaviour, learning applied — and in whether your
        mentee is developing anyone themselves.</p>
    </div>
  </section>

  <!-- ── The work itself ───────────────────────────────────────────────── -->
  <section class="mt-section" aria-labelledby="do-h">
    <div class="container">
      <p class="mt-kicker">The work</p>
      <h2 class="mt-h" id="do-h">What a mentor actually does</h2>

      <div class="mt-grid mt-grid--2">
        <div>
          <h3 style="font-size:15px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--afg-gold-ink);margin:0 0 4px">You do this</h3>
          <ul class="mt-list mt-list--plain">
            <li>Keep a real relationship, not a reporting line.</li>
            <li>Meet on a steady cadence, and review goals and commitments each time.</li>
            <li>Follow up on what was left unfinished.</li>
            <li>Ask the difficult question that everyone else is avoiding.</li>
            <li>Challenge assumptions and destructive patterns.</li>
            <li>Correct without humiliating. Encourage without flattering.</li>
            <li>Teach without creating dependency.</li>
            <li>Share your experience without imposing your choices.</li>
            <li>Name strengths as precisely as you name gaps.</li>
            <li>Help them convert knowledge into action.</li>
            <li>Track actual progress, not attendance.</li>
            <li>Prepare them for more responsibility than they currently hold.</li>
            <li>Get them to the point where they can mentor someone else.</li>
          </ul>
        </div>
        <div>
          <h3 style="font-size:15px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--afg-muted);margin:0 0 4px">You are not this</h3>
          <ul class="mt-list mt-list--plain mt-list--no">
            <li>Not a personal assistant.</li>
            <li>Not a saviour.</li>
            <li>Not a financier.</li>
            <li>Not a dictator.</li>
          </ul>
          <p style="margin-top:18px"><strong>A mentor is a builder of capacity.</strong> The mentor can open the
            door; the mentee has to walk through it. Mentorship is not a rescue programme.</p>

          <h3 style="font-size:15px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--afg-gold-ink);margin:28px 0 4px">What your mentee owes you</h3>
          <p>Goals they actually set. Attendance they actually keep. Preparation, honest reporting, assignments
            finished, questions asked, correction received like an adult, and the values lived where you cannot
            see them. Their side is not optional either.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ── Multiplication ────────────────────────────────────────────────── -->
  <section class="mt-section mt-section--dark" aria-labelledby="mult-h">
    <div class="container">
      <p class="mt-kicker">The test</p>
      <h2 class="mt-h" id="mult-h">Multiplication is the <em>real</em> result</h2>
      <p class="mt-lead">We do not measure maturity by how many people stand behind you. We measure it by how many
        people you have helped become capable of standing on their own — and of helping someone else.</p>

      <ol class="mt-mult" aria-label="One mentor becoming two, four, eight and onward to a hundred and twenty-eight">
        <li>1</li><li>2</li><li>4</li><li>8</li><li>16</li><li>32</li><li>64</li><li>128</li>
      </ol>

      <p class="mt-lead" style="margin-top:26px">This is not a pyramid of dependency. It is a network of capable
        people who can reproduce values, competence and leadership. So we celebrate the mentor who produces a good
        mentee — and we celebrate more the mentee who goes on to produce a good mentor.</p>

      <p class="mt-lead">Keep your direct mentees few enough that the relationship stays relational rather than
        bureaucratic. <strong>Do not collect mentees. Develop people.</strong></p>
    </div>
  </section>

  <!-- ── Advancement ───────────────────────────────────────────────────── -->
  <section class="mt-section" aria-labelledby="adv-h">
    <div class="container">
      <p class="mt-kicker">O to G</p>
      <h2 class="mt-h" id="adv-h">Titles follow evidence, not ambition</h2>
      <p class="mt-lead">The leadership journey runs Ordinary, Active, Becoming, Committed, Dedicated, Excellent,
        Father, Grand Father. Progression is never about how long someone has been around.</p>

      <div class="mt-grid mt-grid--3">
        <article class="mt-card">
          <h3>What is weighed</h3>
          <p>Consistency in mentorship. Respect for time. The seven values in practice. CIMC done or committed to.
            Reliability. Commitments completed.</p>
        </article>
        <article class="mt-card">
          <h3>What is proven</h3>
          <p>Demonstrated character and competence. Service to others. Contribution to Afrovanguard's objectives.
            Evidence of your own growth.</p>
        </article>
        <article class="mt-card">
          <h3>What settles it</h3>
          <p>Whether your mentees are actually progressing — and whether any of them are now capable of mentoring
            somebody else.</p>
        </article>
      </div>

      <p class="mt-note">The higher we rise, the more people we should be capable of helping rise.</p>
    </div>
  </section>

  <!-- ── The daily-life standard ───────────────────────────────────────── -->
  <section class="mt-section mt-section--tint" aria-labelledby="daily-h">
    <div class="container">
      <p class="mt-kicker">The real test</p>
      <h2 class="mt-h" id="daily-h">It is not decided in the session</h2>
      <p class="mt-lead">The real test of Afrovanguard mentorship happens nowhere near a mentorship meeting. It
        happens:</p>

      <ul class="mt-when">
        <li>Monday morning.</li>
        <li>At work.</li>
        <li>At home.</li>
        <li>When nobody is watching.</li>
        <li>When money is involved.</li>
        <li>When we are offended.</li>
        <li>When we have authority.</li>
        <li>When we have made a mistake.</li>
        <li>When nobody will reward us.</li>
        <li>When doing the right thing costs us something.</li>
      </ul>

      <p class="mt-lead" style="margin-top:26px">That is where values become character. Where character becomes
        culture. And where culture becomes civilisation.</p>
    </div>
  </section>

  <!-- ── The creed ─────────────────────────────────────────────────────── -->
  <section class="mt-section mt-section--dark" aria-labelledby="creed-h">
    <div class="container">
      <p class="mt-kicker">What you are agreeing to</p>
      <h2 class="mt-h" id="creed-h">The Mentor's Creed</h2>
      <ol class="mt-creed">
        <li>I will not merely tell people what to do. I will model what I teach.</li>
        <li>I will not create dependency. I will build capacity.</li>
        <li>I will not measure mentorship by attendance alone. I will look for transformation.</li>
        <li>I will respect time, because I respect people.</li>
        <li>I will practise the values I expect others to practise.</li>
        <li>I will remain teachable, because leadership without learning becomes arrogance.</li>
        <li>I will challenge with truth, correct with dignity and encourage with responsibility.</li>
        <li>I will help those entrusted to me discover who they can become.</li>
        <li>And when they become capable, I will help them become capable of helping others.</li>
      </ol>
      <p class="mt-lead" style="margin-top:32px">Afrovanguard is not merely raising members. We are raising people
        who can raise people. We are building leaders who build leaders. We are multiplying the Force for Good.</p>
    </div>
  </section>

  <!-- ── Begin ─────────────────────────────────────────────────────────── -->
  <section class="mt-section" aria-labelledby="begin-h">
    <div class="container">
      <p class="mt-kicker">Begin</p>
      <h2 class="mt-h" id="begin-h">Where you start depends on where you are</h2>

      <div class="mt-apply">
        <article class="mt-route">
          <h3>You have an @<?= e($org) ?> account</h3>
          <p>Publish your mentor profile — your focus areas, how many mentees you can genuinely carry, and whether
            you are accepting requests right now.</p>
          <a class="mt-btn mt-btn--go" href="/mentorship/">Publish your mentor profile</a>
        </article>
        <article class="mt-route">
          <h3>You are a member, without an org account</h3>
          <p>Mentoring is open to Afrovanguard members. Start with membership and the Academy, keep the Quest, and
            come back at Level 3.</p>
          <a class="mt-btn mt-btn--out" href="/academy/#membership">Become a member</a>
        </article>
        <article class="mt-route">
          <h3>You are new here</h3>
          <p>Come and see the work first. Read the Diary, look at the programmes, or simply tell us what you would
            want to give and we will point you to the right door.</p>
          <a class="mt-btn mt-btn--out" href="/contact.html">Talk to the team</a>
        </article>
      </div>

      <p class="mt-note">Already mentoring someone informally? That counts — bring it into the open so it can be
        supported, reviewed and multiplied.</p>
    </div>
  </section>

</main>
<?php render_footer(); ?>
