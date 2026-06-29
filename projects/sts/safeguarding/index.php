<?php
$STS_ROOT = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($STS_ROOT === '' || !is_file($STS_ROOT.'/inc/head.php')) { $STS_ROOT = __DIR__; while (!is_file($STS_ROOT.'/inc/head.php') && dirname($STS_ROOT) !== $STS_ROOT) $STS_ROOT = dirname($STS_ROOT); }
$PAGE_TITLE = "Child Safeguarding — Street-To-Stardom";
$PAGE_DESC  = "Child safeguarding is a cross-cutting principle of the STS methodology. Our commitment, code of conduct, and how to report a concern.";
$PAGE_PATH  = "/safeguarding/";
require $STS_ROOT.'/inc/head.php';
?>
<section class="section page-header sts-accent" data-screen-label="Page header"> <div class="section-inner"> <span class="eyebrow reveal">Our commitment · Safeguarding</span> <h1 class="page-title reveal">Every child, <span class="accent">safe by design</span>.</h1> <p class="page-lede reveal">Child safeguarding is not a policy we bolt on — it is one of the cross-cutting principles built into every stage of the STS Transformation Cycle, from the first community conversation to alumni leadership.</p> </div> </section>
<section class="section" data-screen-label="Safeguarding · principles" style="padding-top:24px;padding-bottom:64px;"> <div class="section-inner">
  <span class="eyebrow reveal">Four commitments</span>
  <h2 class="section-title reveal" style="font-size:clamp(28px,3.4vw,36px);">What we promise every child and parent.</h2>
  <div class="sts-pillars" style="margin-top:36px;">
    <div class="sts-pill-card reveal"><div class="n">01</div><div class="t">Vetted adults</div><div class="d">Every staff member, volunteer and mentor is screened, reference-checked and trained before they sit with a child. No exceptions.</div></div>
    <div class="sts-pill-card reveal"><div class="n">02</div><div class="t">Clear code of conduct</div><div class="d">A written behaviour code governs every interaction — language, physical contact, photography, transport and one-to-one contact rules.</div></div>
    <div class="sts-pill-card reveal"><div class="n">03</div><div class="t">Consent-led</div><div class="d">Enrolment, assessment and any photography happen only with the consent of a parent, guardian or partner school — and consent can be withdrawn at any time.</div></div>
    <div class="sts-pill-card reveal"><div class="n">04</div><div class="t">Always reportable</div><div class="d">A named Safeguarding Lead receives every concern. Reports can be made in confidence and are acted on within 24 hours.</div></div>
  </div>
</div> </section>
<section class="section prose-section" data-screen-label="Safeguarding · body" style="background:var(--surface);border-top:1px solid var(--hairline);border-bottom:1px solid var(--hairline);"> <div class="section-inner"> <div class="prose-grid"> <div> <div class="meta">Governance</div> <div style="margin-top:16px;color:var(--muted);font-size:13px;line-height:1.55;max-width:240px;">One safeguarding policy is shared across Afrovanguard and overseen by a single Board of Trustees.</div> </div> <div class="prose">
<h3>Reporting a concern</h3>
<p>If you have a concern about the safety or wellbeing of a child in any STS programme, tell us immediately. You can speak to any centre director in person, or use the <a href="/contact">contact form</a> marking your message <strong>“Safeguarding”</strong>. Concerns are routed straight to the Safeguarding Lead and treated in confidence.</p>
<h3>How we respond</h3>
<p>Every report is logged, assessed within 24 hours, and — where a child may be at risk — escalated to the appropriate authorities and the child's guardians. We never investigate alone where statutory involvement is required.</p>
<h3>Where it lives in the methodology</h3>
<p>Safeguarding systems are established in <strong>Stage One — Discover</strong>, before a single child is enrolled, and are audited continuously through <strong>Stage Five — Measure</strong>. It is one of the cross-cutting principles that every intervention must satisfy.</p>
<p><a href="/methodology">See the full methodology <span aria-hidden="true">→</span></a></p>
</div> </div> </div> </section>
<section class="section cta-strip" data-screen-label="Page CTA"> <div class="section-inner"> <div class="cta-strip-inner reveal"> <div> <div class="eyebrow" style="margin-bottom:12px;">Need to reach us</div> <h2 class="section-title" style="font-size:clamp(28px,3vw,36px);max-width:600px;">Raise a safeguarding concern — we respond within 24 hours.</h2> </div> <div class="cta-strip-actions"> <a class="btn btn-primary" href="/contact">Contact the team <span class="btn-arrow">→</span></a> <a class="btn btn-secondary" href="/privacy">Privacy policy</a> </div> </div> </div> </section>
<?php require $STS_ROOT.'/inc/footer.php';
