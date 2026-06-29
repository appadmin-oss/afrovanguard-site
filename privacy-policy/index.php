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
$updated   = '29 June 2026';
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
  <main id="main-content" class="legal-main">
    <header class="legal-hero">
      <div class="container">
        <span class="legal-kicker">Legal</span>
        <h1>Privacy Policy</h1>
        <p class="legal-lede">How we collect, use and protect your personal information across our website, member accounts, donations and community.</p>
        <p class="legal-updated">Last updated: <?= e($updated) ?></p>
      </div>
    </header>

    <div class="legal-body">
      <div class="container">
        <div class="legal-layout">
          <nav class="legal-toc" aria-label="On this page">
            <h2>On this page</h2>
            <ol>
              <li><a href="#who">Who we are</a></li>
              <li><a href="#collect">What we collect</a></li>
              <li><a href="#use">How we use it</a></li>
              <li><a href="#legal-basis">Our legal basis</a></li>
              <li><a href="#share">Who we share it with</a></li>
              <li><a href="#cookies">Cookies</a></li>
              <li><a href="#retention">How long we keep it</a></li>
              <li><a href="#security">How we protect it</a></li>
              <li><a href="#rights">Your rights</a></li>
              <li><a href="#children">Children</a></li>
              <li><a href="#changes">Changes</a></li>
              <li><a href="#contact">Contact us</a></li>
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
              <p>We only collect what we need to run the Site and our programmes:</p>
              <ul>
                <li><strong>Account details</strong> — your name and email address when you create an account, sign in with a one-time code, or continue with Google.</li>
                <li><strong>Donation details</strong> — the amount, reference and contact details you provide when you give. Card and bank details are handled entirely by our payment processor; <strong>we never see or store your full card number</strong>.</li>
                <li><strong>Things you post</strong> — messages, replies and mentorship requests you create in the community and members&rsquo; areas.</li>
                <li><strong>Media you upload</strong> — images or files you submit (for example a profile photo or programme materials), stored with our media provider.</li>
                <li><strong>Newsletter</strong> — your email address if you subscribe to the Diary.</li>
                <li><strong>Technical data</strong> — your IP address and basic request information, kept briefly for security, abuse-prevention and rate-limiting.</li>
              </ul>
            </section>

            <section class="legal-section" id="use">
              <h2><span class="legal-num">3</span> How we use it</h2>
              <ul>
                <li>To create and secure your account and send one-time sign-in codes and verification links.</li>
                <li>To run the Academy, Diary, mentorship and community features you choose to use.</li>
                <li>To process and acknowledge donations and keep proper financial records.</li>
                <li>To send updates you&rsquo;ve asked for (such as the Diary) — you can unsubscribe at any time.</li>
                <li>To protect the Site against abuse, fraud and technical problems, and to comply with our legal obligations.</li>
              </ul>
              <p>We do <strong>not</strong> sell your personal information, and we don&rsquo;t use it for advertising or profiling.</p>
            </section>

            <section class="legal-section" id="legal-basis">
              <h2><span class="legal-num">4</span> Our legal basis</h2>
              <p>We rely on the following grounds to process your information:</p>
              <ul>
                <li><strong>Your consent</strong> — for example when you subscribe to the Diary or upload content. You can withdraw consent at any time.</li>
                <li><strong>Performing a service you asked for</strong> — running your account and delivering programme features.</li>
                <li><strong>Our legitimate interests</strong> — keeping the Site secure, preventing abuse, and acknowledging donations, balanced against your rights.</li>
                <li><strong>Legal obligation</strong> — meeting accounting, tax and other regulatory requirements.</li>
              </ul>
            </section>

            <section class="legal-section" id="share">
              <h2><span class="legal-num">5</span> Who we share it with</h2>
              <p>We share data only with the service providers (data processors) that make the Site work, and only as needed to deliver it:</p>
              <ul>
                <li><strong>Paystack</strong> — to process monetary donations securely. Payment details go directly to Paystack.</li>
                <li><strong>Google</strong> — for optional &ldquo;Continue with Google&rdquo; sign-in and, for our staff, Google Workspace.</li>
                <li><strong>Cloudinary</strong> — to store and serve images and other media you or we upload.</li>
                <li><strong>Email delivery</strong> — to send sign-in codes, verification messages and notifications through our email provider.</li>
              </ul>
              <p>These providers may process data outside Nigeria; where they do, we rely on their contractual and technical safeguards to protect it. We may also disclose information if required by law, or where necessary to protect the rights, safety and property of Afrovanguard, our members or the public.</p>
            </section>

            <section class="legal-section" id="cookies">
              <h2><span class="legal-num">6</span> Cookies</h2>
              <p>We use a small number of cookies that are essential to the Site: keeping you signed in, remembering your light or dark theme choice, and protecting forms against cross-site abuse. We don&rsquo;t use third-party advertising or tracking cookies, and we don&rsquo;t need a cookie banner because we set only what the Site requires to function.</p>
            </section>

            <section class="legal-section" id="retention">
              <h2><span class="legal-num">7</span> How long we keep it</h2>
              <p>We keep account and donation records for as long as your account is active, or for as long as we need them to meet legal and accounting obligations. Security and request logs are kept only briefly. When information is no longer needed, we delete or anonymise it.</p>
            </section>

            <section class="legal-section" id="security">
              <h2><span class="legal-num">8</span> How we protect it</h2>
              <p>We use reasonable technical and organisational measures to safeguard your information — including encrypted connections (HTTPS), access controls, and trusted processors for payments and media. No system can be guaranteed perfectly secure, but we work to limit what we hold and to keep it protected.</p>
            </section>

            <section class="legal-section" id="rights">
              <h2><span class="legal-num">9</span> Your rights</h2>
              <p>You can ask us to access, correct, export or delete your personal information, to restrict or object to how we use it, or to stop sending you updates. Email <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a> and we&rsquo;ll respond within a reasonable time, and in any case within the period required by law. You can unsubscribe from the Diary using the link in any email. If you believe we have not handled your data properly, you may also contact the Nigeria Data Protection Commission.</p>
            </section>

            <section class="legal-section" id="children">
              <h2><span class="legal-num">10</span> Children</h2>
              <p>Some of our programmes (such as LCASP) serve children in person, with consent handled directly with parents, guardians and schools. Our online accounts are intended for adults and older youth; we don&rsquo;t knowingly collect personal information from young children through this website. If you believe a child has given us information online, contact us and we&rsquo;ll remove it.</p>
            </section>

            <section class="legal-section" id="changes">
              <h2><span class="legal-num">11</span> Changes to this policy</h2>
              <p>We may update this policy as our work and the law evolve. We&rsquo;ll revise the &ldquo;last updated&rdquo; date above, and we&rsquo;ll highlight significant changes on the Site so you know what&rsquo;s different.</p>
            </section>

            <section class="legal-section" id="contact">
              <div class="legal-contact">
                <h2>Contact us</h2>
                <p>Afrovanguard &middot; Alimosho LGA, Lagos, Nigeria</p>
                <p>Questions about your privacy or a data request? Email <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a>.</p>
              </div>
            </section>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php render_footer(); ?>
