<?php
/**
 * terms/index.php — Afrovanguard terms of use.
 * Linked from the global footer (/terms/). Covers accounts, acceptable use,
 * donations (material & monetary), content, and the usual disclaimers.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$S         = rtrim(SITE_URL, '/');
$canonical = "$S/terms/";
$updated   = 'June 2026';
$contact   = 'cacentre@afrovanguard.org.ng';

render_head([
    'title'     => 'Terms of Use — Afrovanguard',
    'desc'      => 'The terms that govern your use of the Afrovanguard website, accounts, community and donations.',
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
        <h1>Terms of Use</h1>
        <p class="legal-updated">Last updated: <?= e($updated) ?></p>
      </div>
    </header>

    <div class="legal-body">
      <div class="container">
        <div class="legal-toc" aria-label="On this page">
          <h2>On this page</h2>
          <ol>
            <li><a href="#accept">Acceptance</a></li>
            <li><a href="#accounts">Your account</a></li>
            <li><a href="#use">Acceptable use</a></li>
            <li><a href="#content">Content you post</a></li>
            <li><a href="#donations">Donations</a></li>
            <li><a href="#ip">Intellectual property</a></li>
            <li><a href="#third">Third-party links</a></li>
            <li><a href="#disclaimer">Disclaimers</a></li>
            <li><a href="#liability">Liability</a></li>
            <li><a href="#law">Governing law</a></li>
          </ol>
        </div>

        <section class="legal-section" id="accept">
          <h2>Acceptance</h2>
          <p>By using afrovanguard.org.ng and our related services (the “Site”), you agree to these Terms of Use and to our <a href="<?= e($S) ?>/privacy-policy/">Privacy Policy</a>. If you don’t agree, please don’t use the Site.</p>
        </section>

        <section class="legal-section" id="accounts">
          <h2>Your account</h2>
          <ul>
            <li>Provide accurate information and keep your sign-in details secure. You’re responsible for activity under your account.</li>
            <li>One-time codes and verification links are personal to you — don’t share them.</li>
            <li>Tell us promptly at <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a> if you suspect unauthorised use.</li>
          </ul>
        </section>

        <section class="legal-section" id="use">
          <h2>Acceptable use</h2>
          <p>Our community runs on a few simple house rules — debate the work, never the person; sources beat opinions; keep canvassing out of the threads. You agree not to:</p>
          <ul>
            <li>Post unlawful, hateful, harassing, deceptive or spam content.</li>
            <li>Impersonate others or misrepresent your affiliation.</li>
            <li>Attempt to break, overload, probe or gain unauthorised access to the Site.</li>
            <li>Scrape, resell or misuse the Site or its data.</li>
          </ul>
          <p>We may moderate, remove content, or suspend accounts that break these rules.</p>
        </section>

        <section class="legal-section" id="content">
          <h2>Content you post</h2>
          <p>You keep ownership of what you post. By posting, you give Afrovanguard a non-exclusive licence to display and distribute it within the Site for the purpose of running our programmes and community. You’re responsible for what you share, and you confirm you have the right to share it.</p>
        </section>

        <section class="legal-section" id="donations">
          <h2>Donations</h2>
          <div class="legal-callout">
            <p>Afrovanguard welcomes both <strong>material (in-kind) donations</strong> — books, devices, supplies, equipment, venues, professional time and skills — and <strong>monetary donations</strong>. Material gifts go directly to our centres and programmes.</p>
          </div>
          <ul>
            <li>All donations are voluntary. Monetary donations are processed securely by our payment provider.</li>
            <li>For material donations, we’ll coordinate logistics, confirm what’s most needed, and provide acknowledgement on request.</li>
            <li>Because donations fund ongoing work, they’re generally non-refundable. If a monetary gift was made in error, contact us promptly and we’ll do our best to help.</li>
          </ul>
        </section>

        <section class="legal-section" id="ip">
          <h2>Intellectual property</h2>
          <p>The Afrovanguard name, logo, written content, designs and programme materials are owned by Afrovanguard or used with permission. You may share and link to our content for non-commercial, good-faith purposes with attribution, but you may not copy or reuse it commercially without our written consent.</p>
        </section>

        <section class="legal-section" id="third">
          <h2>Third-party links &amp; services</h2>
          <p>The Site links to and integrates with third-party services (for example Google sign-in, Google Workspace and our payment provider). We’re not responsible for the content or practices of third-party sites; their own terms and privacy policies apply.</p>
        </section>

        <section class="legal-section" id="disclaimer">
          <h2>Disclaimers</h2>
          <p>The Site and its educational content are provided “as is,” without warranties of any kind. We work hard to keep everything accurate and available, but we don’t guarantee the Site will always be uninterrupted, error-free or fit for a particular purpose.</p>
        </section>

        <section class="legal-section" id="liability">
          <h2>Limitation of liability</h2>
          <p>To the fullest extent permitted by law, Afrovanguard and its team won’t be liable for any indirect, incidental or consequential loss arising from your use of the Site.</p>
        </section>

        <section class="legal-section" id="law">
          <h2>Governing law</h2>
          <p>These Terms are governed by the laws of the Federal Republic of Nigeria, and disputes fall under the jurisdiction of the courts of Lagos State. We may update these Terms from time to time; the “last updated” date above reflects the current version.</p>
        </section>

        <div class="legal-contact">
          <h2>Questions?</h2>
          <p>Afrovanguard · Alimosho LGA, Lagos, Nigeria</p>
          <p><a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a></p>
        </div>
      </div>
    </div>
  </main>
<?php render_footer(); ?>
