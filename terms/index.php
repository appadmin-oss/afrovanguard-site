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
$updated   = '29 June 2026';
$contact   = 'cacentre@afrovanguard.org.ng';

render_head([
    'title'     => 'Terms of Use — Afrovanguard',
    'desc'      => 'The terms that govern your use of the Afrovanguard website, accounts, community and donations.',
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
        <h1 data-cms="terms-h1-1">Terms of Use</h1>
        <p class="legal-lede" data-cms="terms-p-1">The terms that govern your use of the Afrovanguard website, member accounts, community and donations.</p>
        <p class="legal-updated">Last updated: <?= e($updated) ?></p>
      </div>
    </header>

    <div class="legal-body">
      <div class="container">
        <div class="legal-layout">
          <nav class="legal-toc" aria-label="On this page">
            <h2 data-cms="terms-h2-1">On this page</h2>
            <ol>
              <li><a href="#accept" data-cms="terms-a-1">Acceptance</a></li>
              <li><a href="#site" data-cms="terms-a-2">Use of the Site</a></li>
              <li><a href="#accounts" data-cms="terms-a-3">Accounts &amp; membership</a></li>
              <li><a href="#conduct" data-cms="terms-a-4">Acceptable use</a></li>
              <li><a href="#content" data-cms="terms-a-5">Content you post</a></li>
              <li><a href="#donations" data-cms="terms-a-6">Donations</a></li>
              <li><a href="#ip" data-cms="terms-a-7">Intellectual property</a></li>
              <li><a href="#third" data-cms="terms-a-8">Third-party services</a></li>
              <li><a href="#disclaimer" data-cms="terms-a-9">Disclaimers</a></li>
              <li><a href="#liability" data-cms="terms-a-10">Limitation of liability</a></li>
              <li><a href="#law" data-cms="terms-a-11">Governing law</a></li>
              <li><a href="#changes" data-cms="terms-a-12">Changes</a></li>
              <li><a href="#contact" data-cms="terms-a-13">Contact</a></li>
            </ol>
          </nav>

          <div class="legal-content">
            <section class="legal-section" id="accept">
              <h2><span class="legal-num">1</span> Acceptance</h2>
              <p>By using afrovanguard.org.ng and our related services (the &ldquo;Site&rdquo;), you agree to these Terms of Use and to our <a href="<?= e($S) ?>/privacy-policy/">Privacy Policy</a>. If you don&rsquo;t agree, please don&rsquo;t use the Site. If you use the Site on behalf of an organisation, you confirm you have authority to accept these terms for it.</p>
            </section>

            <section class="legal-section" id="site">
              <h2><span class="legal-num">2</span> Use of the Site</h2>
              <p data-cms="terms-p-2">Afrovanguard offers the Site and its educational content free of charge, for personal, non-commercial and good-faith use in support of our mission. You may browse, read, share and link to our content, and take part in the programmes and community features we make available. We may add, change or withdraw features at any time to keep the Site working and improving.</p>
            </section>

            <section class="legal-section" id="accounts">
              <h2><span class="legal-num">3</span> Accounts &amp; membership</h2>
              <ul>
                <li data-cms="terms-li-1">Provide accurate information and keep your sign-in details secure. You&rsquo;re responsible for activity under your account.</li>
                <li data-cms="terms-li-2">One-time codes and verification links are personal to you — don&rsquo;t share them.</li>
                <li data-cms="terms-li-3">Membership and programme access are offered at our discretion and may carry their own joining requirements.</li>
                <li>Tell us promptly at <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a> if you suspect unauthorised use. You may close your account at any time.</li>
              </ul>
            </section>

            <section class="legal-section" id="conduct">
              <h2><span class="legal-num">4</span> Acceptable use</h2>
              <p data-cms="terms-p-3">Our community runs on a few simple house rules — debate the work, never the person; sources beat opinions; keep canvassing out of the threads. You agree not to:</p>
              <ul>
                <li data-cms="terms-li-4">Post unlawful, hateful, harassing, deceptive or spam content.</li>
                <li data-cms="terms-li-5">Impersonate others or misrepresent your affiliation.</li>
                <li data-cms="terms-li-6">Attempt to break, overload, probe or gain unauthorised access to the Site.</li>
                <li data-cms="terms-li-7">Scrape, resell or otherwise misuse the Site or its data.</li>
              </ul>
              <p data-cms="terms-p-4">We may moderate or remove content, and suspend or close accounts, that break these rules.</p>
            </section>

            <section class="legal-section" id="content">
              <h2><span class="legal-num">5</span> Content you post</h2>
              <p data-cms="terms-p-5">You keep ownership of what you post. By posting, you give Afrovanguard a non-exclusive, royalty-free licence to display and distribute it within the Site for the purpose of running our programmes and community. You&rsquo;re responsible for what you share, and you confirm you have the right to share it.</p>
            </section>

            <section class="legal-section" id="donations">
              <h2><span class="legal-num">6</span> Donations</h2>
              <div class="legal-callout">
                <p data-cms="terms-p-6">Afrovanguard welcomes both <strong>material (in-kind) donations</strong> — books, devices, supplies, equipment, venues, professional time and skills — and <strong>monetary donations</strong>. Material gifts go directly to our centres and programmes.</p>
              </div>
              <ul>
                <li data-cms="terms-li-8">All donations are voluntary. Monetary donations are processed securely by our payment provider, Paystack; we never see or store your full card details.</li>
                <li data-cms="terms-li-9">For material donations, we&rsquo;ll coordinate logistics, confirm what&rsquo;s most needed, and provide acknowledgement on request.</li>
                <li data-cms="terms-li-10">Because donations fund ongoing work, they&rsquo;re generally non-refundable. If a monetary gift was made in error, contact us promptly and we&rsquo;ll do our best to help.</li>
              </ul>
            </section>

            <section class="legal-section" id="ip">
              <h2><span class="legal-num">7</span> Intellectual property</h2>
              <p data-cms="terms-p-7">The Afrovanguard name, logo, written content, designs and programme materials are owned by Afrovanguard or used with permission. You may share and link to our content for non-commercial, good-faith purposes with attribution, but you may not copy or reuse it commercially without our written consent.</p>
            </section>

            <section class="legal-section" id="third">
              <h2><span class="legal-num">8</span> Third-party services</h2>
              <p data-cms="terms-p-8">The Site links to and integrates with third-party services — for example Paystack for donations, Google for optional sign-in and Workspace, and Cloudinary for media. We&rsquo;re not responsible for the content or practices of third-party services; their own terms and privacy policies apply when you use them.</p>
            </section>

            <section class="legal-section" id="disclaimer">
              <h2><span class="legal-num">9</span> Disclaimers</h2>
              <p data-cms="terms-p-9">The Site and its educational content are provided &ldquo;as is&rdquo; and &ldquo;as available,&rdquo; without warranties of any kind. We work hard to keep everything accurate and available, but we don&rsquo;t guarantee the Site will always be uninterrupted, error-free or fit for a particular purpose. Our content is for general educational purposes and isn&rsquo;t professional advice.</p>
            </section>

            <section class="legal-section" id="liability">
              <h2><span class="legal-num">10</span> Limitation of liability</h2>
              <p data-cms="terms-p-10">To the fullest extent permitted by law, Afrovanguard and its team, volunteers and partners won&rsquo;t be liable for any indirect, incidental, special or consequential loss arising from your use of, or inability to use, the Site.</p>
            </section>

            <section class="legal-section" id="law">
              <h2><span class="legal-num">11</span> Governing law</h2>
              <p data-cms="terms-p-11">These Terms are governed by the laws of the Federal Republic of Nigeria, and any disputes fall under the exclusive jurisdiction of the courts of Lagos State.</p>
            </section>

            <section class="legal-section" id="changes">
              <h2><span class="legal-num">12</span> Changes to these terms</h2>
              <p data-cms="terms-p-12">We may update these Terms from time to time as our work and the law evolve. The &ldquo;last updated&rdquo; date above reflects the current version, and your continued use of the Site after a change means you accept the updated Terms.</p>
            </section>

            <section class="legal-section" id="contact">
              <div class="legal-contact">
                <h2 data-cms="terms-h2-2">Questions?</h2>
                <p data-cms="terms-p-13">Afrovanguard &middot; Alimosho LGA, Lagos, Nigeria</p>
                <p>Get in touch any time at <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a>.</p>
              </div>
            </section>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php render_footer(); ?>
