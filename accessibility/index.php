<?php
/**
 * accessibility/index.php — the accessibility hub and statement.
 *
 * Linked from every footer (/accessibility/). One page for everything about
 * using this site with a disability: the display settings (which work without
 * JavaScript, #settings), how to use the site with a keyboard, a screen
 * reader, zoom or a magnifier and Listen, every keyboard shortcut in one
 * table (#shortcuts), how to report a barrier (#report), and the
 * accessibility statement (#statement), written on the GOV.UK model: scope,
 * compliance status, what is not accessible and why, how it was tested,
 * enforcement, and when the statement was prepared.
 *
 * Keep it honest: every "known problem" below is real, and a problem is taken
 * off only when it is fixed. Never claim more than "partially compliant"
 * until disabled people have tested the site with their own assistive
 * technology and the issues found are fixed. The audit behind it is
 * docs/accessibility/AUDIT-2026-11-25.md. Review it at least once a year —
 * the CI accessibility job (tests/a11y/), `php tools/academy-a11y-audit.php`,
 * and a manual pass with screen readers — and update $reviewed.
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
$reviewed  = '25 November 2026';
$prepared  = '25 November 2026';
$contact   = 'cacentre@afrovanguard.org.ng';   // reply within five working days — both confirmed by the owner, 2026-11-12
$report    = '/contact.html?purpose=general&subject=' . rawurlencode('Accessibility barrier')
           . '&message=' . rawurlencode("The page (its address):\nWhat I was trying to do:\nWhat stopped me:\nWhat I use (e.g. a screen reader, zoom, keyboard only, a phone):\n") . '#contact-form';
$signedIn  = class_exists('LmsAuth') && LmsAuth::user();

render_head([
    'title'     => 'Accessibility',
    'desc'      => 'How to use the Afrovanguard website and Academy with a keyboard, screen reader, magnifier or Listen; display settings; keyboard shortcuts; and our accessibility statement.',
    'canonical' => $canonical,
    'robots'    => 'index, follow',
    'css'       => ['/assets/legal.css'],
]);
render_nav('');
?>
  <main id="main" tabindex="-1" class="legal-main a11y-hub">
    <header class="legal-hero">
      <div class="container">
        <span class="legal-kicker">Help</span>
        <h1>Accessibility</h1>
        <p class="legal-lede">Everyone should be able to learn, volunteer and give here — whether they use a screen reader, a keyboard, a magnifier, captions or a phone on a slow connection.</p>
        <p class="legal-updated">Statement prepared <?= e($prepared) ?> · last reviewed <?= e($reviewed) ?></p>
      </div>
    </header>

    <div class="legal-body">
      <div class="container">
        <ul class="a11y-hub-cards" role="list">
          <li><a class="a11y-hub-card" href="#settings"><span class="a11y-hub-ico" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="4.75" r="1.9" fill="currentColor"/><path d="M5 8.6c2.3.7 4.6 1.05 7 1.05s4.7-.35 7-1.05M12 9.65v4.4m0 0-2.6 5.7m2.6-5.7 2.6 5.7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span><strong>Display settings</strong><small>Text size, contrast, spacing, motion</small></span></a></li>
          <li><a class="a11y-hub-card" href="#shortcuts"><span class="a11y-hub-ico" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="3" y="6" width="18" height="12" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M7 10h.01M11 10h.01M15 10h.01M8 14h8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span><span><strong>Keyboard shortcuts</strong><small>Every shortcut in one place</small></span></a></li>
          <li><a class="a11y-hub-card" href="#report"><span class="a11y-hub-ico" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M4 5.5h16v10H9l-5 4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></span><span><strong>Report a barrier</strong><small>We reply within five working days</small></span></a></li>
        </ul>
        <div class="legal-layout">
          <nav class="legal-toc" aria-label="On this page">
            <h2>On this page</h2>
            <ol>
              <li><a href="#settings">Display settings</a></li>
              <li><a href="#using">Using this site your way</a></li>
              <li><a href="#shortcuts">Keyboard shortcuts</a></li>
              <li><a href="#report">Report a barrier</a></li>
              <li><a href="#statement">Accessibility statement</a></li>
            </ol>
          </nav>

          <div class="legal-content">
            <?= A11y::listen('.legal-content', 'Listen to this page') ?>

            <section class="legal-section" id="settings" aria-labelledby="settings-h">
              <h2 id="settings-h"><span class="legal-num">1</span> Display settings</h2>
              <p>These change how this site looks for you. Your own device's settings, screen reader and magnifier keep working underneath them. They are a convenience, not a substitute for the pages working properly — the pages do not depend on them, and they do not make the site “compliant”.</p>
              <p>Open them from the small round <em>Accessibility settings</em> button at the bottom left of every page, the footer link, or <kbd class="av-kbd">Alt</kbd> <kbd class="av-kbd">Shift</kbd> <kbd class="av-kbd">A</kbd> (<kbd class="av-kbd">Option</kbd> <kbd class="av-kbd">Shift</kbd> <kbd class="av-kbd">A</kbd> on a Mac). They are kept on this device<?= $signedIn ? ' and in your account' : ', and in your account when you are signed in' ?>, so they follow you.</p>
<?php if (isset($_GET['saved'])): ?>
              <p class="legal-callout" role="status">Your settings are saved on this device<?= $signedIn ? ' and in your account' : '' ?>.</p>
<?php elseif ($a11yMsg !== ''): ?>
              <p class="legal-callout" role="alert"><?= e($a11yMsg) ?></p>
<?php endif; ?>
              <p class="a11y-page-open" hidden><button type="button" class="a11y-form-btn" data-a11y-open data-a11y-link>Open the settings panel</button></p>
              <p>Or change them here — this form works without JavaScript:</p>
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
<?php foreach (['' => 'Standard', 'hc-light' => 'High contrast, light', 'hc-dark' => 'High contrast, dark', 'hc-auto' => 'High contrast, matching my device'] as $v => $l): ?>
                  <label><input type="radio" name="contrast" value="<?= e($v) ?>"<?= $a11yNow['contrast'] === $v ? ' checked' : '' ?>> <?= e($l) ?></label>
<?php endforeach; ?>
                </fieldset>
                <fieldset>
                  <legend>Reading and moving around</legend>
<?php foreach (['spacing' => 'Comfortable spacing', 'font' => 'Easy-to-read font', 'links' => 'Underline all links', 'focus' => 'Large focus outline', 'targets' => 'Bigger buttons and links', 'still' => 'Stop animation and moving content', 'nokeys' => 'Turn off single-key shortcuts'] as $v => $l): ?>
                  <label><input type="checkbox" name="flags[]" value="<?= e($v) ?>"<?= in_array($v, $a11yNow['flags'], true) ? ' checked' : '' ?>> <?= e($l) ?></label>
<?php endforeach; ?>
                </fieldset>
                <p class="a11y-form-btns"><button type="submit" name="a11y_save" value="save" class="a11y-form-btn">Save these settings</button> <button type="submit" name="a11y_save" value="reset" class="a11y-form-btn a11y-form-btn--line">Reset to standard</button></p>
              </form>
            </section>

            <section class="legal-section" id="using" aria-labelledby="using-h">
              <h2 id="using-h"><span class="legal-num">2</span> Using this site your way</h2>
              <h3>With a keyboard</h3>
              <ul>
                <li>Every page starts with a <em>Skip to main content</em> link: press <kbd class="av-kbd">Tab</kbd> once after a page opens.</li>
                <li>Everything that can be clicked can be reached with <kbd class="av-kbd">Tab</kbd> and <kbd class="av-kbd">Shift</kbd> <kbd class="av-kbd">Tab</kbd>, and a blue ring always shows where you are. Turn on <em>Large focus outline</em> for a bolder one.</li>
                <li>Menus, the search, the settings panel and other pop-ups close with <kbd class="av-kbd">Esc</kbd>, and focus goes back to where you were.</li>
              </ul>
              <h3>With a screen reader</h3>
              <ul>
                <li>Every page has one main heading, headings for each section, and the same landmarks: the site header, the main content, navigation menus with names (“Main”, “Breadcrumb”, “Footer”), and the footer. Jump by heading (<kbd class="av-kbd">H</kbd> in NVDA and JAWS, the rotor in VoiceOver) or by landmark (<kbd class="av-kbd">D</kbd> in NVDA, <kbd class="av-kbd">R</kbd> in JAWS).</li>
                <li>Pictures that carry meaning are described; decorative ones are hidden. Links say where they go, and links that open a new tab say so.</li>
                <li>Search results, filters, form messages and settings changes are announced without moving your focus.</li>
                <li>The Academy's lessons, quizzes and certificates can be completed by keyboard and screen reader alone.</li>
              </ul>
              <h3>With zoom or a magnifier</h3>
              <ul>
                <li>Pages work at 400% browser zoom and on a 320-pixel-wide screen without text going off the side.</li>
                <li>The <em>Text size</em> setting goes up to 200%, and <em>Comfortable spacing</em> adds room between lines, words and letters.</li>
                <li>Your device's settings — Windows contrast themes, larger text, dark mode and “reduce motion” — are respected.</li>
              </ul>
              <h3>With Listen</h3>
              <ul>
                <li><strong>Listen</strong> — Diary entries, Academy lessons, the constitution, project pages and this page have a <em>Listen</em> control that reads the main text aloud with your browser's own voice, highlighting each paragraph. You can pause, stop (or press <kbd class="av-kbd">Esc</kbd>) and change the speed. Nothing is sent anywhere, it never starts by itself, and it stops when you leave the page.</li>
                <li>If you already use a screen reader you do not need it: the group is named “Read aloud with your browser's voice” so you can pass it by.</li>
                <li>Lessons also open in Edge's Immersive Reader and Safari's Reader view.</li>
              </ul>
              <h3>Other help</h3>
              <ul>
                <li><strong>Captions and transcripts</strong> — Academy videos must have captions or a transcript before a course is ready; where one is missing, the lesson tells you and lets you ask for it.</li>
                <li><strong>Time</strong> — quizzes have no time limit, and the Academy keeps your place.</li>
                <li><strong>Moving content</strong> — the home page's slideshow and highlights have Pause buttons, and <em>Stop moving content</em> stops them everywhere.</li>
                <li><strong>Signing in</strong> — you can sign in with a one-time code sent by email, which you can paste; you never have to remember or retype it.</li>
              </ul>
            </section>

            <section class="legal-section" id="shortcuts" aria-labelledby="shortcuts-h">
              <h2 id="shortcuts-h"><span class="legal-num">3</span> Keyboard shortcuts</h2>
              <p>The site has very few shortcuts, so they do not get in the way of your browser's or screen reader's own keys.</p>
              <div class="legal-table-wrap" tabindex="0" role="region" aria-label="Keyboard shortcuts (table)">
              <table class="legal-table">
                <caption class="av-sr">Keyboard shortcuts on this site</caption>
                <thead><tr><th scope="col">Keys</th><th scope="col">What they do</th><th scope="col">Where</th></tr></thead>
                <tbody>
                  <tr><th scope="row"><kbd class="av-kbd">Alt</kbd> <kbd class="av-kbd">Shift</kbd> <kbd class="av-kbd">A</kbd><br><small>Mac: <kbd class="av-kbd">Option</kbd> <kbd class="av-kbd">Shift</kbd> <kbd class="av-kbd">A</kbd></small></th><td>Open or close the accessibility settings</td><td>Every page</td></tr>
                  <tr><th scope="row"><kbd class="av-kbd">Ctrl</kbd> <kbd class="av-kbd">K</kbd><br><small>Mac: <kbd class="av-kbd">⌘</kbd> <kbd class="av-kbd">K</kbd></small></th><td>Open the site search</td><td>Every page (in the member portal it opens the portal's own search)</td></tr>
                  <tr><th scope="row"><kbd class="av-kbd">/</kbd></th><td>Open the search, when you are not typing in a field. You can turn this off with <em>Single-key shortcuts</em> in the settings.</td><td>Every page</td></tr>
                  <tr><th scope="row"><kbd class="av-kbd">↑</kbd> <kbd class="av-kbd">↓</kbd> then <kbd class="av-kbd">Enter</kbd></th><td>Move through search results and open one</td><td>The search</td></tr>
                  <tr><th scope="row"><kbd class="av-kbd">←</kbd> <kbd class="av-kbd">→</kbd></th><td>Move between tabs and filter buttons</td><td>Search filters, IQ, People</td></tr>
                  <tr><th scope="row"><kbd class="av-kbd">Esc</kbd></th><td>Close a menu, the search or the settings; stop Listen</td><td>Every page</td></tr>
                </tbody>
              </table>
              </div>
              <p><strong>Screen reader users:</strong> NVDA, JAWS and VoiceOver use single letters for their own quick navigation in browse mode, and they catch those keys before this site does. The one single-character shortcut here, <kbd class="av-kbd">/</kbd>, only works when focus is not in a field, and you can switch it off (WCAG 2.1.4).</p>
            </section>

            <section class="legal-section" id="report" aria-labelledby="report-h">
              <h2 id="report-h"><span class="legal-num">4</span> Report a barrier</h2>
              <p>If something on this site or in the Academy stops you — a page you cannot read, a button you cannot reach, a video you cannot follow — tell us. You do not need to know the technical words.</p>
              <ul>
                <li><a href="<?= e($report) ?>">Report a barrier with the contact form</a> — it is filled in with the questions that help us most.</li>
                <li>Or email <a href="mailto:<?= e($contact) ?>?subject=<?= rawurlencode('Accessibility barrier') ?>"><?= e($contact) ?></a>.</li>
              </ul>
              <p>Tell us the page, what you were trying to do, and what you use (a screen reader, zoom, a keyboard, a phone). We aim to reply within five working days. If you need a lesson, report or form in another format — a transcript, large print, a plain text version, or someone to go through it with you — ask, and we will arrange it.</p>
            </section>

            <section class="legal-section" id="statement" aria-labelledby="statement-h">
              <h2 id="statement-h"><span class="legal-num">5</span> Accessibility statement</h2>
              <p>This statement applies to the Afrovanguard website at <a href="<?= e($S) ?>/"><?= e(preg_replace('~^https?://~', '', $S)) ?></a>: the public pages, the Diary, the Afrovanguard Academy (courses, lessons, quizzes and certificates), the member portal and the members' constitution. It does not cover our other sites (Street-To-Stardom, Africa GATES, CACENTRE), which have their own.</p>

              <h3>Compliance status</h3>
              <p>We measure this website against the <a href="https://www.w3.org/TR/WCAG22/">Web Content Accessibility Guidelines (WCAG) 2.2</a> at level AA. This is also the level behind the European standard EN 301 549.</p>
              <p>This website is <strong>partially compliant</strong> with WCAG 2.2 level AA, because of the non-accessible content listed below, and because it has not yet been tested by disabled people using their own assistive technology.</p>

              <h3>Non-accessible content</h3>
              <p>The content below is not yet fully accessible. If one of these stops you, <a href="<?= e($report) ?>">tell us</a> and we will help you another way.</p>
              <h4>Not meeting WCAG 2.2 AA</h4>
              <ul>
                <li><strong>Some older videos</strong> have no captions or transcript yet (WCAG 1.2.2 Captions, 1.2.3 and 1.2.5 Audio description). New courses cannot be published without them. We aim to caption or transcribe every older Academy video by 31 March 2027; ask from the lesson and we will do that one first.</li>
                <li><strong>Annual reports and other PDFs</strong> may not be fully readable by a screen reader: some lack tags, headings and a reading order (WCAG 1.3.1, 2.4.2, 2.4.6). We will publish new reports as accessible web pages or tagged PDFs; ask for an accessible version of an older one.</li>
                <li><strong>Staff and printing tools</strong> — the Studio, the NextGen Vanguard staff desks, and printable cards and posters — have the settings but have not been through the same checks as the public site, the Academy and the member portal. Some have no page landmarks or skip link, and some printed layouts do not reflow (WCAG 1.3.1, 2.4.1, 1.4.10). We aim to review them by 30 June 2027.</li>
              </ul>
              <h4>Content that is not within our control</h4>
              <ul>
                <li><strong>Things we embed from other companies</strong> — YouTube videos and Google Maps — follow those companies' own accessibility. Every address on a map is also written out in text.</li>
                <li><strong>Listen</strong> uses the voices installed on your device, so how it sounds depends on your browser and system; few devices have a Nigerian English voice, and some browsers have none (then the control does not appear). It reads the text of the page, not pictures or videos.</li>
              </ul>
              <h4>Known limits of our own settings</h4>
              <ul>
                <li><strong>The high-contrast setting</strong> replaces our colours with black, white, blue and yellow across very different pages. If anything becomes hard to see or use with it on, <a href="<?= e($report) ?>">tell us</a>. Your device's own contrast themes, such as Windows contrast themes, keep working and are more thorough.</li>
              </ul>

              <h3>How we tested this website</h3>
              <p>This statement is based on an audit carried out on 25 November 2026 by our development team, with automated tools and manual checks. We have not yet had the site tested by disabled people or by an independent accessibility specialist.</p>
              <ul>
                <li><strong>Automated checks.</strong> Every change is checked with <a href="https://github.com/dequelabs/axe-core">axe</a> against WCAG 2.2 A and AA, in Chromium, on a page of every kind of page we have — public pages, policy pages, the Academy, lessons and the learner's pages, the member portal, the constitution, sign-in, error pages — at desktop width and at 320 pixels (what 400% zoom looks like), and again with 200% text, comfortable spacing and high contrast (light and dark) switched on, and with the search and the settings open. The change is held back if a page fails, scrolls sideways at 320 pixels, or hides the keyboard focus under a fixed bar.</li>
                <li><strong>Structure checks.</strong> A test renders each page template and checks that it has a language, one main heading, one main landmark, a header and footer, a working skip link, our title pattern, named breadcrumbs, no positive tab order, a text alternative for every image and a name for every icon button.</li>
                <li><strong>The accessibility tree.</strong> We read key pages as a screen reader would, through the browser's accessibility tree, and keep snapshots so a change to names or structure is noticed.</li>
                <li><strong>Manual checks.</strong> Keyboard-only use, 320-pixel and 400% zoom, text spacing, reduced motion, and the Academy's lesson checks (pictures without descriptions, unclear links, heading order, tables, captions).</li>
              </ul>
              <p><strong>Still to do:</strong> automated tools find only some problems — around a third. We have not yet tested with real screen readers and their users: NVDA and JAWS on Windows, VoiceOver on iPhone and Mac, TalkBack on Android, and Narrator. We also want people who use voice control, switch access and magnifiers to try the site. Until that is done we will not describe the site as fully compliant. If you would be willing to help, <a href="<?= e($report) ?>">please tell us</a>.</p>

              <h3>What we are doing to improve accessibility</h3>
              <ul>
                <li>Captions or transcripts for every older Academy video by 31 March 2027.</li>
                <li>Testing by disabled people with NVDA, JAWS, VoiceOver and TalkBack, and fixing what they find, during the first half of 2027.</li>
                <li>Bringing the staff tools up to the same standard by 30 June 2027.</li>
              </ul>

              <h3>Enforcement</h3>
              <p>Afrovanguard is based in Lagos, Nigeria. We support the aims of the Discrimination Against Persons with Disabilities (Prohibition) Act 2018. If you contact us about a barrier and you are not happy with how we respond, you can contact the <a href="https://ncpwd.gov.ng/">National Commission for Persons with Disabilities</a>.</p>

              <h3>Preparation of this statement</h3>
              <p>This statement was prepared on <?= e($prepared) ?> and last reviewed on <?= e($reviewed) ?>. We review it at least once a year, and whenever the site changes in a way that affects it.</p>
            </section>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php render_footer(); ?>
