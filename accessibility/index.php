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

/* The display settings without JavaScript (#settings): a plain form that sets
   the same av_a11y cookie the panel does (lib/A11y.php), so a visitor whose
   browser runs no script still gets larger text, spacing, contrast and the
   rest — PHP puts the classes on <html> from the cookie. Same anti-forgery
   token and same-site checks as the site's other forms. */
$a11yMsg = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['a11y_save'])) {
    $host  = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $from  = (string) ($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    $sameSite = $from === '' || strtolower((string) parse_url($from, PHP_URL_HOST)) === $host;
    if (!$sameSite || strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site' || !av_csrf_valid((string) ($_POST['csrf'] ?? ''))) {
        $a11yMsg = 'That form had expired, so nothing was changed. Please choose your settings again.';
    } else {
        $chosen = ($_POST['a11y_save'] ?? '') === 'reset' ? '0' : A11y::serialize([
            'text'     => (int) ($_POST['text'] ?? 100),
            'contrast' => (string) ($_POST['contrast'] ?? ''),
            'flags'    => array_map('strval', (array) ($_POST['flags'] ?? [])),
        ]);
        A11y::setCookie($chosen);
        $me = class_exists('LmsAuth') ? LmsAuth::user() : null;
        if ($me) Prefs::set((int) $me['id'], A11y::PREF, A11y::serialize(A11y::parse($chosen)) . '@' . time());
        header('Location: /accessibility/?saved=1#settings', true, 303);
        exit;
    }
}
$a11yNow = A11y::current();

$S         = rtrim(SITE_URL, '/');
$canonical = "$S/accessibility/";
$reviewed  = '24 November 2026';
$contact   = 'cacentre@afrovanguard.org.ng';   // reply within five working days — both confirmed by the owner, 2026-11-12
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
              <li><a href="#settings">Change how this site looks</a></li>
              <li><a href="#known">What we know falls short</a></li>
              <li><a href="#tested">How we test</a></li>
              <li><a href="#law">Standards and the law</a></li>
            </ol>
          </nav>

          <div class="legal-content">
            <?= A11y::listen('.legal-content', 'Listen to this page') ?>
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
              <p>The pages are built to work with the tools you already use. On top of that there are a few optional settings of our own; they are a convenience, not a substitute for the pages working properly, and the pages do not depend on them.</p>
              <ul>
                <li><strong>Use a screen reader</strong> — pages have headings and landmarks to jump between, pictures are described, and the Academy's lessons, quizzes and certificates can be completed by voice and keyboard alone.</li>
                <li><strong>Use only a keyboard</strong> — everything that can be clicked can be reached with Tab, and you can always see where you are. Each page starts with a “Skip to content” link.</li>
                <li><strong>Zoom to 400%</strong>, or use a small phone, without text going off the side of the screen.</li>
                <li><strong>Use your device's settings</strong> — Windows contrast themes, larger text, and “reduce motion” are respected.</li>
                <li><strong>Read lessons your way</strong> — lessons open in Edge's Immersive Reader and Safari's Reader view, which can read them aloud.</li>
                <li><strong>Follow videos without sound</strong> — Academy videos must have captions or a transcript before a course is ready; where one is missing, the lesson tells you and lets you ask for it.</li>
                <li><strong>Take your time</strong> — quizzes have no time limit, and the Academy keeps your place.</li>
                <li><strong>Change how this site looks for you</strong> — the round <em>Accessibility settings</em> button at the bottom-left of every page (or <kbd>Alt</kbd> + <kbd>Shift</kbd> + <kbd>A</kbd>, <kbd>Option</kbd> + <kbd>Shift</kbd> + <kbd>A</kbd> on a Mac) opens settings for larger text (up to 200%), comfortable spacing between lines, words and letters, an easy-to-read font, high contrast (light, dark, or matching your device), underlined links, a large focus outline, stopping animation and moving content, and bigger buttons. They are kept on your device, and in your account when you are signed in, so they follow you. <a href="#settings">The same settings are below</a>, and work without JavaScript.</li>
                <li><strong>Listen</strong> — Diary entries, Academy lessons, the constitution, project pages and this page have a <em>Listen</em> button that reads the main text aloud with your browser's own voice, highlighting each paragraph as it goes. You can pause, stop and change the speed. Nothing is sent anywhere, and it never starts by itself. It appears only where your browser can speak.</li>
                <li><strong>Search from the keyboard</strong> — the <em>Search</em> control in every page's header (or <kbd>Ctrl</kbd> + <kbd>K</kbd>, <kbd>⌘</kbd> + <kbd>K</kbd> on a Mac, or <kbd>/</kbd> when you are not typing in a field) opens a search you can use entirely with the arrow keys, <kbd>Enter</kbd> and <kbd>Esc</kbd>. Without JavaScript it is a plain search page.</li>
                <li><strong>Pause moving content</strong> — the moving highlights on the home page have a Pause button, and your choice is remembered.</li>
              </ul>
            </section>

            <section class="legal-section" id="settings">
              <h2 id="settings-h"><span class="legal-num">4</span> Change how this site looks</h2>
              <p>These change how this site looks for you. Your own device's settings, screen reader and magnifier work here too.</p>
<?php if (isset($_GET['saved'])): ?>
              <p class="legal-callout" role="status">Your settings are saved on this device<?= LmsAuth::user() ? ' and in your account' : '' ?>.</p>
<?php elseif ($a11yMsg !== ''): ?>
              <p class="legal-callout" role="alert"><?= e($a11yMsg) ?></p>
<?php endif; ?>
              <p class="a11y-page-open" hidden><button type="button" class="a11y-form-btn" data-a11y-open data-a11y-link>Open the settings panel</button> — or change them here.</p>
              <form class="a11y-form" method="post" action="/accessibility/#settings">
                <input type="hidden" name="csrf" value="<?= e(av_csrf_token()) ?>">
                <fieldset>
                  <legend>Text size</legend>
<?php foreach (A11y::TEXT as $n): ?>
                  <label><input type="radio" name="text" value="<?= $n ?>"<?= $a11yNow['text'] === $n ? ' checked' : '' ?>> <?= $n ?>%</label>
<?php endforeach; ?>
                </fieldset>
                <fieldset>
                  <legend>Contrast</legend>
<?php foreach (['' => 'Standard', 'hc-auto' => 'High contrast, matching my device', 'hc-light' => 'High contrast, light', 'hc-dark' => 'High contrast, dark'] as $v => $l): ?>
                  <label><input type="radio" name="contrast" value="<?= e($v) ?>"<?= $a11yNow['contrast'] === $v ? ' checked' : '' ?>> <?= e($l) ?></label>
<?php endforeach; ?>
                </fieldset>
                <fieldset>
                  <legend>Reading and moving</legend>
<?php foreach (['spacing' => 'Comfortable spacing', 'font' => 'Easy-to-read font', 'links' => 'Underline all links', 'focus' => 'Large focus outline', 'still' => 'Stop animation and moving content', 'targets' => 'Bigger buttons and links'] as $v => $l): ?>
                  <label><input type="checkbox" name="flags[]" value="<?= e($v) ?>"<?= in_array($v, $a11yNow['flags'], true) ? ' checked' : '' ?>> <?= e($l) ?></label>
<?php endforeach; ?>
                </fieldset>
                <p class="a11y-form-btns"><button type="submit" name="a11y_save" value="save" class="a11y-form-btn">Save these settings</button> <button type="submit" name="a11y_save" value="reset" class="a11y-form-btn a11y-form-btn--line">Reset to standard</button></p>
              </form>
            </section>

            <section class="legal-section" id="known">
              <h2><span class="legal-num">5</span> What we know falls short</h2>
              <p>These are the problems we know about. We are working on them; if one stops you, <a href="<?= e($report) ?>">tell us</a> and we will help you another way.</p>
              <ul>
                <li><strong>Some older videos</strong> have no captions or transcript yet. Ask for one from the lesson and we will add it.</li>
                <li><strong>Annual reports and other PDFs</strong> may not be fully readable by a screen reader. Ask for an accessible version.</li>
                <li><strong>Things we embed from other companies</strong> — YouTube videos and Google Maps — follow those companies' own accessibility. Every address on a map is also written out in text.</li>
                <li><strong>Sign-in</strong> uses a one-time code sent by email. You can paste it; you do not have to remember or retype it.</li>
                <li><strong>Listen</strong> uses the voices installed on your device, so how it sounds depends on your browser and system; few devices have a Nigerian English voice, and some browsers have no voices at all (then the button does not appear). It reads the text of the page, not pictures or videos.</li>
                <li><strong>The high-contrast setting</strong> replaces our colours with black, white, blue and yellow across very different pages. If anything becomes hard to see or use with it on, <a href="<?= e($report) ?>">tell us</a>. Your device's own contrast themes, such as Windows contrast themes, keep working and are more thorough.</li>
                <li><strong>Some staff and printing pages</strong> — the volunteer and NextGen Vanguard desks, printable cards and posters — have the settings from a small button but have not been through the same checks as the public site, the Academy and the member portal.</li>
              </ul>
            </section>

            <section class="legal-section" id="tested">
              <h2><span class="legal-num">6</span> How we test</h2>
              <ul>
                <li>Every change to the site is checked automatically with <a href="https://github.com/dequelabs/axe-core">axe</a> against WCAG 2.2 A and AA — public pages, Academy pages, the member portal and the members' constitution — and the change is held back if a page fails. The check runs again with the high-contrast, 200% text and spacing settings switched on.</li>
                <li>Every Academy lesson is checked for pictures without a description, unclear links, broken heading order, tables without headers and videos without captions — both when an instructor prepares a course and in a yearly review of all courses.</li>
                <li>We check pages with a keyboard alone, at 320 pixels wide (what 400% zoom looks like), in Windows contrast themes, and with a screen reader.</li>
                <li>The screen readers people use most are VoiceOver (iPhone, iPad, Mac), TalkBack (Android), NVDA and JAWS (Windows), and Narrator (Windows); we write to the standards they share rather than to any one of them.</li>
              </ul>
            </section>

            <section class="legal-section" id="law">
              <h2><span class="legal-num">7</span> Standards and the law</h2>
              <p>Afrovanguard is based in Lagos, Nigeria. We support the aims of the Discrimination Against Persons with Disabilities (Prohibition) Act 2018, and we measure our website against WCAG 2.2 AA, the international standard.</p>
              <p>This statement was last reviewed on <?= e($reviewed) ?>. We review it at least once a year, and whenever the site changes in a way that affects it.</p>
            </section>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php render_footer(); ?>
