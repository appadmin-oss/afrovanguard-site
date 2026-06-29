<?php
$STS_ROOT = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($STS_ROOT === '' || !is_file($STS_ROOT.'/inc/head.php')) { $STS_ROOT = __DIR__; while (!is_file($STS_ROOT.'/inc/head.php') && dirname($STS_ROOT) !== $STS_ROOT) $STS_ROOT = dirname($STS_ROOT); }
$PAGE_TITLE = "Privacy Policy — Street-To-Stardom";
$PAGE_DESC  = "How Street-To-Stardom collects, uses and protects personal data — including the data of children — across our programmes and this website.";
$PAGE_PATH  = "/privacy/";
require $STS_ROOT.'/inc/head.php';
?>
<section class="section page-header sts-accent" data-screen-label="Page header"> <div class="section-inner"> <span class="eyebrow reveal">Legal · Privacy</span> <h1 class="page-title reveal">Privacy <span class="accent">Policy</span>.</h1> <p class="page-lede reveal">We collect the least data we can, use it only for the programme work we describe, and protect the data of children with particular care. This policy explains what we hold and your rights over it.</p> </div> </section>
<section class="section prose-section" data-screen-label="Privacy · body"> <div class="section-inner"> <div class="prose-grid"> <div> <div class="meta">Last updated</div> <div style="margin-top:16px;color:var(--muted);font-size:13px;line-height:1.55;max-width:240px;">29 June 2026 · An Afrovanguard initiative · Questions: <a href="/contact">contact us</a>.</div> </div> <div class="prose">
<h3>What we collect</h3>
<p>For programme participants we hold enrolment and assessment records (name, age, school, baseline and endline results, attendance and safeguarding notes). For supporters, volunteers and partners we hold the contact details you provide through our forms. On this website we record only the analytics needed to keep the site fast and secure.</p>
<h3>Why we collect it</h3>
<p>Children's data is used solely to deliver, measure and improve their development pathway, and to keep them safe. Supporter data is used to respond to you, to administer volunteering, sponsorship and partnerships, and — only where you have opted in — to send our quarterly field notes.</p>
<h3>Children's data</h3>
<p>We treat children's data as our most sensitive responsibility. It is collected with the consent of a parent, guardian or partner school, stored with restricted access, never sold, and never published in a form that identifies an individual child. Photography is used only with consent and can be withdrawn at any time.</p>
<h3>Sharing</h3>
<p>We share aggregate, de-identified results openly as part of our commitment to transparency. We share individual data only with the partner school or guardian it relates to, and with the auditors and trustees of Afrovanguard under the same safeguarding policy.</p>
<h3>Your rights</h3>
<p>You may ask us what we hold about you or your child, request a correction, withdraw consent, or ask us to delete data we are not legally required to keep. Email <a href="/contact">the team</a> and we will respond within 30 days.</p>
<h3>Newsletter</h3>
<p>The footer newsletter is strictly opt-in and every email carries a one-click unsubscribe. We never share the list.</p>
</div> </div> </div> </section>
<section class="section cta-strip" data-screen-label="Page CTA"> <div class="section-inner"> <div class="cta-strip-inner reveal"> <div> <div class="eyebrow" style="margin-bottom:12px;">Related</div> <h2 class="section-title" style="font-size:clamp(28px,3vw,36px);max-width:600px;">How we keep children safe is set out in our safeguarding commitment.</h2> </div> <div class="cta-strip-actions"> <a class="btn btn-primary" href="/safeguarding">Read safeguarding <span class="btn-arrow">→</span></a> <a class="btn btn-secondary" href="/contact">Contact us</a> </div> </div> </div> </section>
<?php require $STS_ROOT.'/inc/footer.php';
