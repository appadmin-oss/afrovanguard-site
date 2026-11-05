<?php
/**
 * privacy-policy/index.php — Afrovanguard privacy policy.
 * Linked from the global footer (/privacy-policy/). Plain, honest, and specific
 * to how this site actually handles data (accounts, donations, community).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$S         = rtrim(SITE_URL, '/');
$canonical = "$S/privacy-policy/";
$updated   = '5 November 2026';
$contact   = 'cacentre@afrovanguard.org.ng';

render_head([
    'title'     => 'Privacy Policy — Afrovanguard',
    'desc'      => 'How Afrovanguard collects, uses and protects your personal information across our website, accounts, donations and community.',
    'canonical' => $canonical,
    'robots'    => 'index, follow',
    'css'       => ['/assets/legal.css'],
]);
render_nav('');
?>
  <main id="main" tabindex="-1" class="legal-main">
    <header class="legal-hero">
      <div class="container">
        <span class="legal-kicker">Legal</span>
        <h1 data-cms="privacy-policy-h1-1">Privacy Policy</h1>
        <p class="legal-lede" data-cms="privacy-policy-p-1">How we collect, use and protect your personal information across our website, member accounts, donations and community.</p>
        <p class="legal-updated">Last updated: <?= e($updated) ?></p>
      </div>
    </header>

    <div class="legal-body">
      <div class="container">
        <div class="legal-layout">
          <nav class="legal-toc" aria-label="On this page">
            <h2 data-cms="privacy-policy-h2-1">On this page</h2>
            <ol>
              <li><a href="#who" data-cms="privacy-policy-a-1">Who we are</a></li>
              <li><a href="#collect" data-cms="privacy-policy-a-2">What we collect</a></li>
              <li><a href="#use" data-cms="privacy-policy-a-3">How we use it</a></li>
              <li><a href="#legal-basis" data-cms="privacy-policy-a-4">Our legal basis</a></li>
              <li><a href="#share" data-cms="privacy-policy-a-5">Who we share it with</a></li>
              <li><a href="#cookies" data-cms="privacy-policy-a-6">Cookies</a></li>
              <li><a href="#retention" data-cms="privacy-policy-a-7">How long we keep it</a></li>
              <li><a href="#security" data-cms="privacy-policy-a-8">How we protect it</a></li>
              <li><a href="#rights" data-cms="privacy-policy-a-9">Your rights</a></li>
              <li><a href="#children" data-cms="privacy-policy-a-10">Children</a></li>
              <li><a href="#changes" data-cms="privacy-policy-a-11">Changes</a></li>
              <li><a href="#contact" data-cms="privacy-policy-a-12">Contact us</a></li>
            </ol>
          </nav>

          <div class="legal-content">
            <section class="legal-section" id="who">
              <h2><span class="legal-num">1</span> Who we are</h2>
              <p>Afrovanguard is a Nigerian-rooted nonprofit based in Alimosho LGA, Lagos, working to raise one million incorruptible African leaders by 2040 through community, technology and cultural advancement. This policy explains how we handle personal information across <strong>afrovanguard.org.ng</strong> and our connected services — the member portal, Academy, Diary, community and donation tools (together, the &ldquo;Site&rdquo;).</p>
              <p>For the purpose of applicable data-protection law, including the Nigeria Data Protection Act 2023, Afrovanguard is the controller of the personal information described here. Questions about your data? Email <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a>.</p>
            </section>

            <section class="legal-section" id="collect">
              <h2><span class="legal-num">2</span> What we collect</h2>
              <p data-cms="privacy-policy-p-2">We only collect what we need to run the Site and our programmes:</p>
              <ul>
                <li data-cms="privacy-policy-li-1"><strong>Account details</strong> — your name and email address when you create an account, sign in with a one-time code, or continue with Google.</li>
                <li data-cms="privacy-policy-li-2"><strong>Donation details</strong> — the amount, reference and contact details you provide when you give. Card and bank details are handled entirely by our payment processor; <strong>we never see or store your full card number</strong>.</li>
                <li><strong>Things you post</strong> — messages, replies and mentorship requests you create in the community and members&rsquo; areas.</li>
                <li data-cms="privacy-policy-li-3"><strong>Media you upload</strong> — images or files you submit (for example a profile photo or programme materials), stored with our media provider.</li>
                <li data-cms="privacy-policy-li-4"><strong>Newsletter</strong> — your email address if you subscribe to the Diary.</li>
                <li data-cms="privacy-policy-li-5"><strong>Technical data</strong> — your IP address and basic request information, kept briefly for security, abuse-prevention and rate-limiting.</li>
              </ul>
            </section>

            <section class="legal-section" id="use">
              <h2><span class="legal-num">3</span> How we use it</h2>
              <ul>
                <li data-cms="privacy-policy-li-6">To create and secure your account and send one-time sign-in codes and verification links.</li>
                <li data-cms="privacy-policy-li-7">To run the Academy, Diary, mentorship and community features you choose to use.</li>
                <li data-cms="privacy-policy-li-8">To process and acknowledge donations and keep proper financial records.</li>
                <li data-cms="privacy-policy-li-9">To send updates you&rsquo;ve asked for (such as the Diary) — you can unsubscribe at any time.</li>
                <li data-cms="privacy-policy-li-10">To protect the Site against abuse, fraud and technical problems, and to comply with our legal obligations.</li>
              </ul>
              <p>We do <strong>not</strong> sell your personal information, and we don&rsquo;t use it for advertising or profiling.</p>
            </section>

            <section class="legal-section" id="legal-basis">
              <h2><span class="legal-num">4</span> Our legal basis</h2>
              <p data-cms="privacy-policy-p-3">We rely on the following grounds to process your information:</p>
              <ul>
                <li data-cms="privacy-policy-li-11"><strong>Your consent</strong> — for example when you subscribe to the Diary or upload content. You can withdraw consent at any time.</li>
                <li data-cms="privacy-policy-li-12"><strong>Performing a service you asked for</strong> — running your account and delivering programme features.</li>
                <li data-cms="privacy-policy-li-13"><strong>Our legitimate interests</strong> — keeping the Site secure, preventing abuse, and acknowledging donations, balanced against your rights.</li>
                <li data-cms="privacy-policy-li-14"><strong>Legal obligation</strong> — meeting accounting, tax and other regulatory requirements.</li>
              </ul>
            </section>

            <section class="legal-section" id="share">
              <h2><span class="legal-num">5</span> Who we share it with</h2>
              <p data-cms="privacy-policy-p-4">We share data only with the service providers (data processors) that make the Site work, and only as needed to deliver it:</p>
              <ul>
                <li data-cms="privacy-policy-li-15"><strong>Paystack</strong> — to process monetary donations securely. Payment details go directly to Paystack.</li>
                <li><strong>Google</strong> — for optional &ldquo;Continue with Google&rdquo; sign-in and, for our staff, Google Workspace.</li>
                <li data-cms="privacy-policy-li-16"><strong>Cloudinary</strong> — to store and serve images and other media you or we upload.</li>
                <li data-cms="privacy-policy-li-17"><strong>Email delivery</strong> — to send sign-in codes, verification messages and notifications through our email provider.</li>
              </ul>
              <p data-cms="privacy-policy-p-5">These providers may process data outside Nigeria; where they do, we rely on their contractual and technical safeguards to protect it. We may also disclose information if required by law, or where necessary to protect the rights, safety and property of Afrovanguard, our members or the public.</p>
            </section>

            <section class="legal-section" id="cookies">
              <h2><span class="legal-num">6</span> Cookies</h2>
              <p data-cms="privacy-policy-p-6">Most of what this Site stores in your browser is there to make it work, never leaves it, and is not used to follow you anywhere else. One thing is optional: Google Analytics, which counts visits and shows us which pages help people. It runs <strong>only if you allow it</strong> in the cookie notice. Until you do, nothing is sent to Google and none of its cookies (named <code>_ga</code>) are set. If you allow it, we send the address of the page without any codes or links in it, and never your name, email or account details. If your browser sends Global Privacy Control or Do Not Track, we treat that as a no and do not ask. You can change your mind at any time with <a href="#cookies" data-av-consent-open>Cookie settings</a>, here or at the foot of any page; withdrawing removes Google&rsquo;s cookies. We don&rsquo;t use advertising cookies.</p>
              <h3 data-cms="privacy-policy-h3-1">What the Site stores, and why</h3>
              <div class="legal-table-wrap">
              <table class="legal-table">
                <thead><tr><th scope="col">Name</th><th scope="col">What it is for</th><th scope="col">How long</th></tr></thead>
                <tbody>
                  <tr><td><code>av_consent</code></td><td>Your answer to the cookie notice.</td><td>1 year</td></tr>
                  <tr><td><code>av_lms</code>, <code>av_si</code></td><td>Keeping you signed in to your account, and remembering that you are.</td><td>Up to 30 days, or until you sign out</td></tr>
                  <tr><td><code>av_oauth</code>, <code>av_gws</code></td><td>Signing in with Google: proves the reply came back to the same browser.</td><td>Minutes</td></tr>
                  <tr><td><code>av_admin</code></td><td>Staff only: the signed-in session for the staff console.</td><td>Until sign-out</td></tr>
                  <tr><td><code>cf_clearance</code></td><td>Set by Cloudflare, which protects the Site, when it has checked that a visitor is not an automated attack.</td><td>Up to 1 year</td></tr>
                  <tr><td><code>av_rv</code> and the Diary&rsquo;s view and applause cookies</td><td>Counting Diary views and applause without knowing who you are: a random number, not linked to your name or account.</td><td><code>av_rv</code> 1 year; the others 2 hours</td></tr>
                  <tr><td><code>av_ref</code></td><td>When you arrive through a member&rsquo;s invitation link, which member invited you, so they are credited if you join.</td><td>30 days</td></tr>
                  <tr><td><code>av_illo</code></td><td>Which illustration a page showed you, so it does not change as you move around.</td><td>Until you close the browser</td></tr>
                  <tr><td>Browser storage (<code>av.theme</code>, <code>av.scale</code>, <code>av.saved</code>, <code>av.follow</code>)</td><td>Your light or dark theme, text size, and the Diary entries you saved or follow. This stays on your device; it is not a cookie and is never sent to us.</td><td>Until you clear it</td></tr>
                  <tr><td><code>_ga</code>, <code>_ga_*</code></td><td>Google Analytics. <strong>Only if you allow it.</strong></td><td>Up to 2 years</td></tr>
                </tbody>
              </table>
              </div>
              <h3 data-cms="privacy-policy-h3-2">Services a page loads from elsewhere</h3>
              <ul>
                <li data-cms="privacy-policy-li-18"><strong>Cloudflare</strong> serves and protects the Site, and counts page views with its Web Analytics, which sets no cookies and does not identify you.</li>
                <li><strong>jsDelivr</strong> and <strong>Cloudinary</strong> deliver some icons and images. Like any website, they see your device&rsquo;s internet address when your browser fetches a file; they set no cookies here.</li>
                <li><strong>Google Maps</strong> on the Contact page loads only when you press &ldquo;Show the map&rdquo;. Google may then set its own cookies.</li>
                <li data-cms="privacy-policy-li-19"><strong>Videos and posts</strong> from YouTube and other networks: the film on the home page loads only when you press play, and YouTube is used in its reduced-cookie mode. Posts embedded in a Diary entry load from that network when you open the entry.</li>
                <li data-cms="privacy-policy-li-20"><strong>Paystack</strong> handles card payments when you give. It sees only what you enter on its payment form, under its own privacy policy.</li>
              </ul>
            </section>

            <section class="legal-section" id="retention">
              <h2><span class="legal-num">7</span> How long we keep it</h2>
              <p data-cms="privacy-policy-p-7">We keep account and donation records for as long as your account is active, or for as long as we need them to meet legal and accounting obligations. Security and request logs are kept only briefly. When information is no longer needed, we delete or anonymise it.</p>
            </section>

            <section class="legal-section" id="security">
              <h2><span class="legal-num">8</span> How we protect it</h2>
              <p data-cms="privacy-policy-p-8">We use reasonable technical and organisational measures to safeguard your information — including encrypted connections (HTTPS), access controls, and trusted processors for payments and media. No system can be guaranteed perfectly secure, but we work to limit what we hold and to keep it protected.</p>
            </section>

            <section class="legal-section" id="rights">
              <h2><span class="legal-num">9</span> Your rights</h2>
              <p>You can ask us to access, correct, export or delete your personal information, to restrict or object to how we use it, or to stop sending you updates. Email <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a> and we&rsquo;ll respond within a reasonable time, and in any case within the period required by law. You can unsubscribe from the Diary using the link in any email. If you believe we have not handled your data properly, you may also contact the Nigeria Data Protection Commission.</p>
            </section>

            <section class="legal-section" id="children">
              <h2><span class="legal-num">10</span> Children</h2>
              <p data-cms="privacy-policy-p-9">Some of our programmes (such as LCASP) serve children in person, with consent handled directly with parents, guardians and schools. Our online accounts are intended for adults and older youth; we don&rsquo;t knowingly collect personal information from young children through this website. If you believe a child has given us information online, contact us and we&rsquo;ll remove it.</p>
            </section>

            <section class="legal-section" id="changes">
              <h2><span class="legal-num">11</span> Changes to this policy</h2>
              <p data-cms="privacy-policy-p-10">We may update this policy as our work and the law evolve. We&rsquo;ll revise the &ldquo;last updated&rdquo; date above, and we&rsquo;ll highlight significant changes on the Site so you know what&rsquo;s different.</p>
            </section>

            <section class="legal-section" id="contact">
              <div class="legal-contact">
                <h2 data-cms="privacy-policy-h2-2">Contact us</h2>
                <p data-cms="privacy-policy-p-11">Afrovanguard &middot; Alimosho LGA, Lagos, Nigeria</p>
                <p>Questions about your privacy or a data request? Email <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a>.</p>
              </div>
            </section>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php render_footer(); ?>
