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
      <h2 class="mt-h" id="quest-h">You cannot rise here <em>without</em> mentees</h2>
      <p class="mt-lead">The Vanguard Quest is how Afrovanguard tracks who is actually growing: eight ranks from
        Ordinary to Grand Father, earned on points for demonstrated action. Mentoring is not an optional extra
        inside it. It is a condition of promotion.</p>

      <p class="mt-lead"><strong>You must maintain a minimum of two active direct mentees, sustained for at least
        two cycles (quarters), to be eligible for promotion at all.</strong></p>

      <div class="mt-tablewrap">
        <table class="mt-rank">
          <caption>The Quest ranks, and the direct mentees each expects</caption>
          <thead>
            <tr><th scope="col">Rank</th><th scope="col">Title</th>
                <th scope="col" class="mt-num">Points</th><th scope="col" class="mt-num">Direct mentees</th></tr>
          </thead>
          <tbody>
            <tr><th scope="row">O</th><td>Ordinary</td><td class="mt-num">0–49</td><td class="mt-num">0</td></tr>
            <tr><th scope="row">A</th><td>Active</td><td class="mt-num">50–149</td><td class="mt-num">1</td></tr>
            <tr class="mt-rank--gate"><th scope="row">B</th><td>Becoming</td><td class="mt-num">150–299</td><td class="mt-num">2</td></tr>
            <tr><th scope="row">C</th><td>Committed</td><td class="mt-num">300–499</td><td class="mt-num">3</td></tr>
            <tr><th scope="row">D</th><td>Dedicated</td><td class="mt-num">500–749</td><td class="mt-num">3–4</td></tr>
            <tr><th scope="row">E</th><td>Excellent</td><td class="mt-num">750–999</td><td class="mt-num">4–5</td></tr>
            <tr><th scope="row">F</th><td>Father</td><td class="mt-num">1000–1399</td><td class="mt-num">5+</td></tr>
            <tr><th scope="row">G</th><td>Grand Father</td><td class="mt-num">1400+</td><td class="mt-num">5+</td></tr>
          </tbody>
        </table>
      </div>
      <p class="mt-note mt-note--dark">From Becoming onwards the mentee requirement is the binding one. Points alone
        will not move you.</p>

      <div class="mt-grid mt-grid--2" style="margin-top:32px">
        <article class="mt-card mt-card--dark">
          <h3>Direct and indirect mentees</h3>
          <p><strong>Direct</strong> — the people you personally mentor. These are the ones that count toward the
            minimum of two.</p>
          <p><strong>Indirect</strong> — your mentees' mentees. They are your legacy and they do not count toward
            your own two, which is deliberate: you cannot borrow someone else's work to get promoted.</p>
        </article>
        <article class="mt-card mt-card--dark">
          <h3>Mentoring earns its own points</h3>
          <p>Mentoring a member earns points. So does each active mentee you carry. The largest single award in
            this part of the system is for a mentee whose growth is verified as they cross into the next rank.</p>
          <p>Which is the whole philosophy in an accounting rule: <strong>you are rewarded most when someone
            else rises.</strong></p>
        </article>
      </div>
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
      <p class="mt-kicker">Promotion</p>
      <h2 class="mt-h" id="adv-h">Titles follow evidence, not ambition</h2>
      <p class="mt-lead">Rank is never about how long someone has been around. Beyond the points and the mentee
        minimum, a promotion asks three questions.</p>

      <div class="mt-grid mt-grid--3">
        <article class="mt-card">
          <h3>Are you consistent?</h3>
          <p>Mentorship kept up over cycles, not a burst. Respect for time. The seven values in practice.
            CIMC done or committed to. Commitments finished.</p>
        </article>
        <article class="mt-card">
          <h3>Is it demonstrated?</h3>
          <p>Character and competence others can point at. Service given. Contribution to Afrovanguard's
            objectives. Evidence of your own growth, not just your intentions.</p>
        </article>
        <article class="mt-card">
          <h3>Are your mentees moving?</h3>
          <p>The one that settles it. Are the people you carry actually progressing — and is any of them now
            capable of carrying someone themselves?</p>
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

  <!-- ── Apply ─────────────────────────────────────────────────────────── -->
  <section class="mt-section mt-section--tint" aria-labelledby="apply-h">
    <div class="container">
      <p class="mt-kicker">Apply</p>
      <h2 class="mt-h" id="apply-h">Put your name forward</h2>
      <p class="mt-lead">Open to anyone — you do not need an account to apply. Tell us who you are and what you can
        carry, and the team will come back to you about the next intake.</p>

      <form class="mt-form" id="mentorForm" novalidate>
        <div class="mt-form-row">
          <p class="mt-field">
            <label for="mf-name">Your name <span class="mt-req">(required)</span></label>
            <input id="mf-name" name="name" type="text" autocomplete="name" required
                   aria-describedby="mf-name-e"><span class="mt-err" id="mf-name-e"></span>
          </p>
          <p class="mt-field">
            <label for="mf-email">Your email <span class="mt-req">(required)</span></label>
            <input id="mf-email" name="email" type="email" autocomplete="email" required
                   aria-describedby="mf-email-e"><span class="mt-err" id="mf-email-e"></span>
          </p>
        </div>
        <div class="mt-form-row">
          <p class="mt-field">
            <label for="mf-phone">Phone <span class="mt-opt">(optional)</span></label>
            <input id="mf-phone" name="phone" type="tel" autocomplete="tel" aria-describedby="mf-phone-e">
            <span class="mt-err" id="mf-phone-e"></span>
          </p>
          <p class="mt-field">
            <label for="mf-stage">Where you are now <span class="mt-opt">(optional)</span></label>
            <select id="mf-stage" name="stage">
              <option value="">Choose one…</option>
              <option>Already an Afrovanguard member</option>
              <option>Alumni of a programme</option>
              <option>New to Afrovanguard</option>
              <option>Partner or organisation</option>
            </select>
          </p>
        </div>
        <p class="mt-field">
          <label for="mf-msg">What could you mentor someone on? <span class="mt-req">(required)</span></label>
          <textarea id="mf-msg" name="message" rows="5" required aria-describedby="mf-msg-h mf-msg-e"
            placeholder="The ground you have actually walked — a trade, a discipline, a recovery, a career."></textarea>
          <span class="mt-hint" id="mf-msg-h">Be concrete. "I run a small business and can teach someone to keep
            books" beats "leadership".</span>
          <span class="mt-err" id="mf-msg-e"></span>
        </p>
        <p class="mt-field">
          <label class="mt-consent" for="mf-consent">
            <input id="mf-consent" name="consent" type="checkbox" required aria-describedby="mf-consent-e">
            <span>I agree to Afrovanguard storing this so the team can reply, and I have read what is asked of a
              mentor above.</span>
          </label>
          <span class="mt-err" id="mf-consent-e"></span>
        </p>
        <!-- Anti-spam honeypot: a real person never fills this in. -->
        <p class="mt-hp" aria-hidden="true"><label for="mf-url">Leave this empty</label>
          <input id="mf-url" name="website_url" type="text" tabindex="-1" autocomplete="off"></p>

        <div class="mt-form-foot">
          <button type="submit" class="mt-btn mt-btn--go" id="mfSend">Send my application</button>
          <p class="mt-formmsg" id="mfMsg" role="status" aria-live="polite"></p>
        </div>
      </form>

      <div class="mt-alt">
        <p><strong>Already have an @<?= e($org) ?> account?</strong> You can skip this and publish your mentor
          profile directly — focus areas, how many mentees you can genuinely carry, and whether you are accepting
          requests. <a href="/mentorship/">Go to the mentor network</a>.</p>
        <p><strong>Already mentoring someone informally?</strong> That counts. Bring it into the open so it can be
          supported, reviewed and multiplied.</p>
      </div>
    </div>
  </section>

  <script>
  /* The application posts to the site's own contact processor, so it inherits
     the validation, rate limiting, honeypot and staff notification that every
     other form on the site already uses — rather than adding a second intake
     with its own rules to keep in step. */
  (function () {
    var f = document.getElementById('mentorForm');
    if (!f) return;
    var btn = document.getElementById('mfSend'), msg = document.getElementById('mfMsg');
    function err(id, text) {
      var s = document.getElementById(id + '-e'), c = document.getElementById(id);
      if (s) s.textContent = text || '';
      if (c) { if (text) c.setAttribute('aria-invalid', 'true'); else c.removeAttribute('aria-invalid'); }
      return c;
    }
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      ['mf-name', 'mf-email', 'mf-msg', 'mf-consent'].forEach(function (i) { err(i, ''); });
      msg.textContent = ''; msg.className = 'mt-formmsg';

      var name = f.name.value.trim(), email = f.email.value.trim(),
          body = f.message.value.trim(), stage = f.stage.value, phone = f.phone.value.trim();
      if (!name)  return err('mf-name', 'Please add your name.').focus();
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return err('mf-email', 'Please check the email address.').focus();
      if (!body)  return err('mf-msg', 'Tell us what you could mentor someone on.').focus();
      if (!f.consent.checked) return err('mf-consent', 'Please tick the box so we may store your application.').focus();

      btn.disabled = true; btn.textContent = 'Sending…';
      var lines = ['Mentor application (via /mentorship/become-a-mentor/).', ''];
      if (stage) lines.push('Where they are now: ' + stage);
      if (phone) lines.push('Phone: ' + phone);
      lines.push('', 'Could mentor on:', body);

      fetch('/process-contact.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify({ action: 'submit_contact', purpose: 'volunteer', name: name, email: email,
                               message: lines.join('\n'), consent: true,
                               website_url: f.website_url.value })
      })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (d) {
          btn.disabled = false; btn.textContent = 'Send my application';
          if (!d || d.success !== true) {
            msg.className = 'mt-formmsg is-bad';
            msg.textContent = (d && d.message) || 'That did not go through. Please try again, or use the contact page.';
            return;
          }
          f.reset();
          msg.className = 'mt-formmsg is-good';
          msg.textContent = 'Received — thank you. The team will reply by email about the next intake.';
        })
        .catch(function () {
          btn.disabled = false; btn.textContent = 'Send my application';
          msg.className = 'mt-formmsg is-bad';
          msg.textContent = 'Could not reach the server. Check your connection and try again.';
        });
    });
  })();
  </script>

</main>
<?php render_footer(); ?>
