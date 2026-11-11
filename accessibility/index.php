<?php
/**
 * accessibility/index.php — Afrovanguard's accessibility statement.
 *
 * Linked from every footer (/accessibility/). Says what we aim for, what we
 * know falls short, how it was tested, and how to tell us about a barrier.
 *
 * Keep it honest: every "known problem" below is real, and a problem is taken
 * off only when it is fixed. Review it at least once a year — run
 * `php tools/academy-a11y-audit.php`, the CI accessibility job, and a manual
 * pass with a screen reader — and update $reviewed.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$S         = rtrim(SITE_URL, '/');
$canonical = "$S/accessibility/";
$reviewed  = '11 November 2026';
$contact   = 'cacentre@afrovanguard.org.ng';
$report    = '/contact.html?purpose=general&subject=' . rawurlencode('Accessibility barrier')
           . '&message=' . rawurlencode("The page (its address):\nWhat I was trying to do:\nWhat stopped me:\nWhat I use (e.g. a screen reader, zoom, keyboard only, a phone):\n") . '#contact-form';

render_head([
    'title'     => 'Accessibility — Afrovanguard',
    'desc'      => 'How the Afrovanguard website and Academy work for people with disabilities, what we know falls short, and how to tell us about a barrier.',
    'canonical' => $canonical,
    'robots'    => 'index, follow',
    'css'       => ['/assets/legal.css'],
]);
render_nav('');
?>
  <main id="main" tabindex="-1" class="legal-main">
    <header class="legal-hero">
      <div class="container">
        <span class="legal-kicker">Policies</span>
        <h1>Accessibility</h1>
        <p class="legal-lede">Everyone should be able to learn, volunteer and give here — whether they use a screen reader, a keyboard, a magnifier, captions or a phone on a slow connection.</p>
        <p class="legal-updated">Last reviewed: <?= e($reviewed) ?></p>
      </div>
    </header>

    <div class="legal-body">
      <div class="container">
        <div class="legal-layout">
          <nav class="legal-toc" aria-label="On this page">
            <h2>On this page</h2>
            <ol>
              <li><a href="#report">Tell us about a barrier</a></li>
              <li><a href="#aim">What we aim for</a></li>
              <li><a href="#works">What you can do here</a></li>
              <li><a href="#known">What we know falls short</a></li>
              <li><a href="#tested">How we test</a></li>
              <li><a href="#law">Standards and the law</a></li>
            </ol>
          </nav>

          <div class="legal-content">
            <section class="legal-section" id="report">
              <h2><span class="legal-num">1</span> Tell us about a barrier</h2>
              <p>If something on this site or in the Academy stops you — a page you cannot read, a button you cannot reach, a video you cannot follow — tell us. You do not need to know the technical words.</p>
              <ul>
                <li><a href="<?= e($report) ?>">Report a barrier with the contact form</a> — it is filled in with the questions that help us most.</li>
                <li>Or email <a href="mailto:<?= e($contact) ?>?subject=<?= rawurlencode('Accessibility barrier') ?>"><?= e($contact) ?></a>.</li>
              </ul>
              <p>Tell us the page, what you were trying to do, and what you use (a screen reader, zoom, a keyboard, a phone). We aim to reply within five working days. If you need a lesson, report or form in another format — a transcript, large print, a plain text version, or someone to go through it with you — ask, and we will arrange it.</p>
            </section>

            <section class="legal-section" id="aim">
              <h2><span class="legal-num">2</span> What we aim for</h2>
              <p>We aim to meet the <a href="https://www.w3.org/TR/WCAG22/">Web Content Accessibility Guidelines (WCAG) 2.2</a> at level AA, on the main site and in the Afrovanguard Academy. The site <strong>partly meets</strong> that standard today: most of it does, and the exceptions are listed below.</p>
            </section>

            <section class="legal-section" id="works">
              <h2><span class="legal-num">3</span> What you can do here</h2>
              <ul>
                <li><strong>Use a screen reader</strong> — pages have headings and landmarks to jump between, pictures are described, and the Academy's lessons, quizzes and certificates can be completed by voice and keyboard alone.</li>
                <li><strong>Use only a keyboard</strong> — everything that can be clicked can be reached with Tab, and you can always see where you are. Each page starts with a “Skip to content” link.</li>
                <li><strong>Zoom to 400%</strong>, or use a small phone, without text going off the side of the screen.</li>
                <li><strong>Use your device's settings</strong> — Windows contrast themes, larger text, and “reduce motion” are respected.</li>
                <li><strong>Read lessons your way</strong> — lessons open in Edge's Immersive Reader and Safari's Reader view, which can read them aloud.</li>
                <li><strong>Follow videos without sound</strong> — Academy videos must have captions or a transcript before a course is ready; where one is missing, the lesson tells you and lets you ask for it.</li>
                <li><strong>Take your time</strong> — quizzes have no time limit, and the Academy keeps your place.</li>
              </ul>
            </section>

            <section class="legal-section" id="known">
              <h2><span class="legal-num">4</span> What we know falls short</h2>
              <p>These are the problems we know about. We are working on them; if one stops you, <a href="<?= e($report) ?>">tell us</a> and we will help you another way.</p>
              <ul>
                <li><strong>Some older videos</strong> have no captions or transcript yet. Ask for one from the lesson and we will add it.</li>
                <li><strong>Annual reports and other PDFs</strong> may not be fully readable by a screen reader. Ask for an accessible version.</li>
                <li><strong>Things we embed from other companies</strong> — YouTube videos and Google Maps — follow those companies' own accessibility. Every address on a map is also written out in text.</li>
                <li><strong>The member portal</strong> has a few links that are smaller than they should be on a touchscreen, and one that can slip behind the tab bar when reached with the keyboard.</li>
                <li><strong>Sign-in</strong> uses a one-time code sent by email. You can paste it; you do not have to remember or retype it.</li>
              </ul>
            </section>

            <section class="legal-section" id="tested">
              <h2><span class="legal-num">5</span> How we test</h2>
              <ul>
                <li>Every change to the site is checked automatically with <a href="https://github.com/dequelabs/axe-core">axe</a> against WCAG 2.2 A and AA, and the change is held back if a page fails.</li>
                <li>Every Academy lesson is checked for pictures without a description, unclear links, broken heading order, tables without headers and videos without captions — both when an instructor prepares a course and in a yearly review of all courses.</li>
                <li>We check pages with a keyboard alone, at 320 pixels wide (what 400% zoom looks like), in Windows contrast themes, and with a screen reader.</li>
                <li>The screen readers people use most are VoiceOver (iPhone, iPad, Mac), TalkBack (Android), NVDA and JAWS (Windows), and Narrator (Windows); we write to the standards they share rather than to any one of them.</li>
              </ul>
            </section>

            <section class="legal-section" id="law">
              <h2><span class="legal-num">6</span> Standards and the law</h2>
              <p>Afrovanguard is based in Lagos, Nigeria. We support the aims of the Discrimination Against Persons with Disabilities (Prohibition) Act 2018, and we measure our website against WCAG 2.2 AA, the international standard.</p>
              <p>This statement was last reviewed on <?= e($reviewed) ?>. We review it at least once a year, and whenever the site changes in a way that affects it.</p>
            </section>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php render_footer(); ?>
