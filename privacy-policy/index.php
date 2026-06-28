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
$updated   = 'June 2026';
$contact   = 'cacentre@afrovanguard.org.ng';

render_head([
    'title'     => 'Privacy Policy — Afrovanguard',
    'desc'      => 'How Afrovanguard collects, uses and protects your personal information across our website, accounts, donations and community.',
    'canonical' => $canonical,
    'robots'    => 'index, follow',
    'css'       => ['/assets/site/legal.css'],
]);
render_nav('');
?>
  <main id="main-content" class="legal-main">
    <header class="legal-hero">
      <div class="container">
        <span class="legal-kicker">Legal</span>
        <h1>Privacy Policy</h1>
        <p class="legal-updated">Last updated: <?= e($updated) ?></p>
      </div>
    </header>

    <div class="legal-body">
      <div class="container">
        <div class="legal-toc" aria-label="On this page">
          <h2>On this page</h2>
          <ol>
            <li><a href="#who">Who we are</a></li>
            <li><a href="#collect">What we collect</a></li>
            <li><a href="#use">How we use it</a></li>
            <li><a href="#share">Who we share it with</a></li>
            <li><a href="#cookies">Cookies</a></li>
            <li><a href="#retention">How long we keep it</a></li>
            <li><a href="#rights">Your rights</a></li>
            <li><a href="#children">Children</a></li>
            <li><a href="#changes">Changes</a></li>
          </ol>
        </div>

        <section class="legal-section" id="who">
          <h2>Who we are</h2>
          <p>Afrovanguard is a Nigerian-rooted nonprofit based in Alimosho LGA, Lagos, working to raise one million incorruptible African leaders by 2040 through community, technology and cultural advancement. This policy explains how we handle personal information across <strong>afrovanguard.org.ng</strong> and our member portal, Academy, Diary, community and donation tools.</p>
          <p>Questions about your data? Email <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a>.</p>
        </section>

        <section class="legal-section" id="collect">
          <h2>What we collect</h2>
          <ul>
            <li><strong>Account details</strong> — your name and email address when you create an account, sign in with a one-time code, or continue with Google.</li>
            <li><strong>Donation details</strong> — the amount, reference and contact details you provide when you give. Card and bank details are handled by our payment processor; <strong>we never see or store your full card number</strong>.</li>
            <li><strong>Things you post</strong> — messages, replies and mentorship requests you create in the community and members’ areas.</li>
            <li><strong>Newsletter</strong> — your email address if you subscribe to the Diary.</li>
            <li><strong>Technical data</strong> — your IP address and basic request information, kept briefly for security, abuse-prevention and rate-limiting.</li>
          </ul>
        </section>

        <section class="legal-section" id="use">
          <h2>How we use it</h2>
          <ul>
            <li>To create and secure your account and send one-time sign-in codes and verification links.</li>
            <li>To run the Academy, Diary, mentorship and community features you choose to use.</li>
            <li>To process and acknowledge donations and keep proper records.</li>
            <li>To send updates you’ve asked for (such as the Diary) — you can unsubscribe anytime.</li>
            <li>To protect the site against abuse, fraud and technical problems.</li>
          </ul>
          <p>We do <strong>not</strong> sell your personal information, and we don’t use it for advertising.</p>
        </section>

        <section class="legal-section" id="share">
          <h2>Who we share it with</h2>
          <p>We share data only with the service providers that make the site work, and only as needed:</p>
          <ul>
            <li><strong>Google</strong> — for “Continue with Google” sign-in and, for staff, Google Workspace.</li>
            <li><strong>Paystack</strong> — to process donations securely.</li>
            <li><strong>Email delivery</strong> — to send sign-in codes, verification and notifications via our email provider.</li>
            <li><strong>Cloudflare</strong> — content delivery and protection against attacks.</li>
          </ul>
          <p>We may disclose information if required by law, or to protect the rights, safety and property of Afrovanguard, our members or the public.</p>
        </section>

        <section class="legal-section" id="cookies">
          <h2>Cookies</h2>
          <p>We use a small number of cookies that are essential to the site: keeping you signed in, remembering your light/dark theme choice, and protecting forms against cross-site abuse. We don’t use third-party advertising or tracking cookies.</p>
        </section>

        <section class="legal-section" id="retention">
          <h2>How long we keep it</h2>
          <p>We keep account and donation records for as long as your account is active or as needed to meet legal and accounting obligations. Security logs are kept only briefly. When data is no longer needed, we delete it.</p>
        </section>

        <section class="legal-section" id="rights">
          <h2>Your rights</h2>
          <p>You can ask us to access, correct or delete your personal information, or to stop sending you updates. Email <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a> and we’ll respond within a reasonable time. You can unsubscribe from the Diary using the link in any email.</p>
        </section>

        <section class="legal-section" id="children">
          <h2>Children</h2>
          <p>Some of our programmes (such as LCASP) serve children in person, with consent handled directly with parents, guardians and schools. Our online accounts are intended for adults and older youth; we don’t knowingly collect personal information from young children through this website. If you believe a child has given us information online, contact us and we’ll remove it.</p>
        </section>

        <section class="legal-section" id="changes">
          <h2>Changes to this policy</h2>
          <p>We may update this policy as our work and the law evolve. We’ll change the “last updated” date above, and significant changes will be highlighted on the site.</p>
        </section>

        <div class="legal-contact">
          <h2>Contact us</h2>
          <p>Afrovanguard · Alimosho LGA, Lagos, Nigeria</p>
          <p><a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a></p>
        </div>
      </div>
    </div>
  </main>
<?php render_footer(); ?>
