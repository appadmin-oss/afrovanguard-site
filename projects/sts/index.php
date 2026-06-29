<?php
$STS_ROOT = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($STS_ROOT === '' || !is_file($STS_ROOT.'/inc/head.php')) { $STS_ROOT = __DIR__; while (!is_file($STS_ROOT.'/inc/head.php') && dirname($STS_ROOT) !== $STS_ROOT) $STS_ROOT = dirname($STS_ROOT); }
$PAGE_TITLE = "Street-To-Stardom — Education that meets children where they are";
$PAGE_DESC  = "Education, mentorship and youth development for under-resourced communities across Lagos. Data published openly. An Afrovanguard initiative.";
$PAGE_PATH  = "/";
$PAGE_HEAD_EXTRA = <<<'STSHEAD'
<script type="application/ld+json">{"@context":"https://schema.org","@type":"NGO","name":"Street-To-Stardom","url":"https://streettostardom.org","logo":"https://streettostardom.org/assets/sts-logo.svg","description":"Education, mentorship and youth development for under-resourced communities across Lagos.","parentOrganization":{"@type":"Organization","name":"Afrovanguard","url":"https://afrovanguard.org.ng"},"areaServed":{"@type":"City","name":"Lagos"},"foundingDate":"2021","founder":{"@type":"Person","name":"Ogunbanjo Anuoluwapo Oluwaseun"}}</script>
<style>/* ── Hero v2 — Donate · Impact · Empower ─────────────────────────── */
.hero-v2{position:relative;background:#E6F0FF;overflow:hidden;padding:0}
:root[data-theme=dark] .hero-v2{background:#06080F}
/* wavy blob decoration (top-right, matches flyer) */
.hero-v2__blob{position:absolute;top:-60px;right:-60px;width:min(480px,55vw);height:min(420px,45vw);pointer-events:none;z-index:0;opacity:.55}
:root[data-theme=dark] .hero-v2__blob{opacity:.18}
/* two-column layout */
.hero-v2__inner{position:relative;z-index:1;max-width:1440px;margin:0 auto;padding:0 var(--section-pad-x);display:grid;grid-template-columns:1fr minmax(0,420px);gap:60px;align-items:center;min-height:90vh}
/* left copy */
.hero-v2__copy{display:flex;flex-direction:column;gap:0;padding:100px 0 80px}
.hero-v2__pill{display:inline-flex;align-items:center;gap:10px;margin-bottom:28px;font-family:var(--font-mono);font-size:11px;letter-spacing:.15em;text-transform:uppercase;color:var(--blue);background:rgba(7,50,247,.10);border:1px solid rgba(7,50,247,.20);border-radius:999px;padding:6px 14px}
:root[data-theme=dark] .hero-v2__pill{color:rgba(255,255,255,.82);background:rgba(255,255,255,.07);border-color:rgba(255,255,255,.14)}
.hero-v2__pill-dot{width:5px;height:5px;border-radius:50%;background:var(--blue);flex-shrink:0}
:root[data-theme=dark] .hero-v2__pill-dot{background:#fff}
/* stacked headline – each word its own line, one colour each */
.hero-v2__headline{font-family:var(--font-display);font-weight:700;font-size:clamp(60px,9vw,116px);line-height:.92;letter-spacing:-.035em;margin:0 0 20px}
.hero-v2__headline .h-black{display:block;color:#0C0C14}
:root[data-theme=dark] .hero-v2__headline .h-black{color:#F0F0F8}
.hero-v2__headline .h-crimson{display:block;color:var(--crimson,#C8102E)}
.hero-v2__headline .h-blue{display:block;color:var(--blue,#0732F7)}
/* sub-headline */
.hero-v2__sub-head{font-family:var(--font-display);font-weight:600;font-size:clamp(18px,2.2vw,24px);letter-spacing:-.015em;color:#1A1A2E;margin:0 0 18px}
:root[data-theme=dark] .hero-v2__sub-head{color:#D8DCFF}
/* body */
.hero-v2__body{font-size:clamp(15px,1.4vw,16.5px);line-height:1.65;color:#3A3A5C;max-width:540px;margin:0 0 32px}
:root[data-theme=dark] .hero-v2__body{color:rgba(255,255,255,.65)}
.hero-v2__body strong{color:#1A1A2E;font-weight:600}
:root[data-theme=dark] .hero-v2__body strong{color:#E0E3FF}
/* CTAs */
.hero-v2__ctas{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:40px}
/* credits bar */
.hero-v2__credits{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-family:var(--font-mono);font-size:11px;letter-spacing:.10em;text-transform:uppercase;color:#5A5A7A;border-top:1px solid rgba(7,50,247,.12);padding-top:20px}
:root[data-theme=dark] .hero-v2__credits{color:rgba(255,255,255,.38);border-top-color:rgba(255,255,255,.08)}
.hero-v2__credits-sep{color:rgba(7,50,247,.28)}
:root[data-theme=dark] .hero-v2__credits-sep{color:rgba(255,255,255,.18)}
.hero-v2__credits a{color:inherit;text-decoration:underline;text-underline-offset:3px}
/* right media column – rotating card-stack carousel */
.hero-v2__photos{display:flex;align-items:center;justify-content:center;padding:80px 0;align-self:stretch}
.hero-v2__stack{position:relative;width:100%;max-width:380px;aspect-ratio:4/5}
.hero-v2__card{
  position:absolute;inset:0;border-radius:20px;overflow:hidden;background:#0C0C20;
  transition:transform 1.1s cubic-bezier(.34,1.15,.34,1),opacity .8s ease,box-shadow .8s ease;
  will-change:transform;backface-visibility:hidden;-webkit-backface-visibility:hidden;
  transform-style:preserve-3d;-webkit-font-smoothing:antialiased
}
.hero-v2__card img{width:100%;height:100%;object-fit:cover;display:block;image-rendering:auto;backface-visibility:hidden}
/* dark tint overlay for non-front cards — depth without softening/blurring the photo itself */
.hero-v2__card-shade{position:absolute;inset:0;background:#06070D;opacity:0;transition:opacity .8s ease;pointer-events:none}
/* three resting positions in the stack — JS toggles which card sits in which via data-pos */
.hero-v2__card[data-pos="0"]{transform:rotate(-3deg) translate(0,0) scale(1);z-index:3;opacity:1;box-shadow:0 0 0 3px var(--crimson,#C8102E),0 44px 80px -20px rgba(0,0,0,.55)}
.hero-v2__card[data-pos="1"]{transform:rotate(5deg) translate(7%,5%) scale(.93);z-index:2;opacity:1;box-shadow:0 26px 54px -18px rgba(0,0,0,.4)}
.hero-v2__card[data-pos="1"] .hero-v2__card-shade{opacity:.16}
.hero-v2__card[data-pos="2"]{transform:rotate(-9deg) translate(-6%,9%) scale(.87);z-index:1;opacity:1;box-shadow:0 16px 36px -14px rgba(0,0,0,.32)}
.hero-v2__card[data-pos="2"] .hero-v2__card-shade{opacity:.32}
/* responsive */
@media(max-width:1000px){
  .hero-v2__inner{grid-template-columns:1fr;gap:0;min-height:auto}
  .hero-v2__copy{padding:80px 0 0}
  .hero-v2__photos{padding:36px 0 64px}
  .hero-v2__stack{max-width:300px}
}
@media(max-width:640px){
  .hero-v2__inner{gap:0}
  .hero-v2__copy{padding:72px 0 0}
  .hero-v2__headline{font-size:clamp(52px,14vw,80px)}
  .hero-v2__photos{padding:28px 0 52px}
  .hero-v2__stack{max-width:240px}
  .hero-v2__blob{width:70vw;top:-30px;right:-30px}
}
@media(prefers-reduced-motion:reduce){.hero-v2__card{transition:none}}
/* staggered entrance — rides the site's existing .reveal/rev keyframe, just sequenced */
.reveal-ready .hero-v2__copy .reveal{animation-delay:calc(var(--i,0) * 110ms)}
/* card stack: clickable back cards + focus state */
.hero-v2__card[data-pos="1"],.hero-v2__card[data-pos="2"]{cursor:pointer}
.hero-v2__card:focus-visible{outline:3px solid var(--blue,#0732F7);outline-offset:4px}
/* dot indicators */
.hero-v2__dots{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:18px}
.hero-v2__dot{width:8px;height:8px;border-radius:50%;border:0;padding:0;background:rgba(7,50,247,.18);cursor:pointer;transition:transform .25s cubic-bezier(.3,.7,.4,1),background .25s ease}
:root[data-theme=dark] .hero-v2__dot{background:rgba(255,255,255,.18)}
.hero-v2__dot:hover{transform:scale(1.2)}
.hero-v2__dot[aria-current="true"]{background:var(--crimson,#C8102E);width:22px;border-radius:5px}
/* caption under the stack — names whichever photo is currently front */
.hero-v2__stack-cap{margin-top:46px;text-align:center;font-family:var(--font-mono);font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#5A5A7A;min-height:16px}
:root[data-theme=dark] .hero-v2__stack-cap{color:rgba(255,255,255,.42)}
.hero-v2__stack-cap strong{color:#1A1A2E;font-weight:600}
:root[data-theme=dark] .hero-v2__stack-cap strong{color:#E0E3FF}
/* floating stat badge overlapping the stack */
.hero-v2__stack-wrap{position:relative;width:100%;display:flex;flex-direction:column;align-items:center}
.hero-v2__stat-badge{position:absolute;left:-18px;bottom:54px;z-index:4;display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:14px;background:rgba(255,255,255,.86);backdrop-filter:blur(10px) saturate(160%);-webkit-backdrop-filter:blur(10px) saturate(160%);box-shadow:0 14px 34px -10px rgba(10,10,30,.28),0 0 0 1px rgba(7,50,247,.08);transform:rotate(-2deg)}
:root[data-theme=dark] .hero-v2__stat-badge{background:rgba(20,20,30,.78);box-shadow:0 14px 34px -10px rgba(0,0,0,.5),0 0 0 1px rgba(255,255,255,.08)}
.hero-v2__stat-badge strong{font-family:var(--font-display);font-weight:700;font-size:22px;letter-spacing:-.02em;color:var(--crimson,#C8102E);line-height:1}
.hero-v2__stat-badge span{font-family:var(--font-mono);font-size:9.5px;letter-spacing:.06em;text-transform:uppercase;color:#5A5A7A;line-height:1.3;max-width:80px}
:root[data-theme=dark] .hero-v2__stat-badge span{color:rgba(255,255,255,.55)}
@media(max-width:1000px){.hero-v2__stat-badge{left:-8px;bottom:auto;top:-14px}}
@media(max-width:640px){.hero-v2__stat-badge{display:none}}
/* scroll cue at the foot of the hero */
.hero-v2__scroll-cue{position:relative;z-index:1;display:flex;justify-content:center;padding-bottom:28px;margin-top:-12px}
.hero-v2__scroll-cue button{display:inline-flex;flex-direction:column;align-items:center;gap:6px;background:none;border:0;padding:8px;color:#5A5A7A;font-family:var(--font-mono);font-size:10px;letter-spacing:.12em;text-transform:uppercase;cursor:pointer}
:root[data-theme=dark] .hero-v2__scroll-cue button{color:rgba(255,255,255,.42)}
.hero-v2__scroll-cue svg{animation:hsc-bounce 1.8s ease-in-out infinite}
@keyframes hsc-bounce{0%,100%{transform:translateY(0)}50%{transform:translateY(5px)}}
@media(max-width:640px){.hero-v2__scroll-cue{display:none}}
@media(prefers-reduced-motion:reduce){.hero-v2__scroll-cue svg{animation:none}}
/* placeholder overlay suppression – injected after SVG→img swap */
.placeholder::before,.placeholder::after{display:none!important}
.placeholder{background:#111!important}
/* ── STS Programme Countdown ──────────────────────────────────────── */
.sts-countdown{position:relative;background:var(--blue);color:#fff;padding:80px var(--section-pad-x) 68px;overflow:hidden}
.sts-countdown::before{content:"";position:absolute;inset:0;pointer-events:none;background-image:repeating-linear-gradient(135deg,rgba(255,255,255,.04) 0 1px,transparent 1px 24px)}
.sts-countdown::after{content:"";position:absolute;inset:0;pointer-events:none;background:radial-gradient(ellipse at 0% 120%,rgba(200,16,46,.22),transparent 48%),radial-gradient(ellipse at 96% -10%,rgba(255,255,255,.06),transparent 44%)}
.sts-countdown__inner{position:relative;z-index:1;max-width:1440px;margin:0 auto}
.sts-countdown__header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:52px;flex-wrap:wrap}
.sts-countdown__live{display:inline-flex;align-items:center;gap:10px;font-family:var(--font-mono);font-size:11px;letter-spacing:.15em;text-transform:uppercase;color:rgba(255,255,255,.65)}
.sts-countdown__live-pill{display:inline-flex;align-items:center;gap:8px;padding:5px 12px;border-radius:999px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.18)}
.sts-countdown__live-dot{display:inline-block;width:5px;height:5px;border-radius:50%;background:#fff;animation:sts-live-pulse 2.4s ease-in-out infinite}
@keyframes sts-live-pulse{0%,100%{box-shadow:0 0 0 0 rgba(255,255,255,.45)}50%{box-shadow:0 0 0 5px rgba(255,255,255,0)}}
.sts-countdown__cta{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:999px;background:#fff;color:#0420B5;font-family:var(--font-body);font-weight:700;font-size:13px;letter-spacing:-.01em;text-decoration:none;white-space:nowrap;transition:transform .12s ease,background .12s ease;box-shadow:0 4px 20px -4px rgba(0,0,0,.22)}
.sts-countdown__cta:hover{transform:translateY(-1px);background:#eef0ff}
.sts-countdown__cta .ar{display:inline-block;transition:transform .18s cubic-bezier(.3,.7,.4,1)}
.sts-countdown__cta:hover .ar{transform:translate(3px)}
.sts-countdown__nums-row{display:flex;align-items:flex-start;flex-wrap:wrap;gap:0;margin-bottom:0}
.sts-countdown__unit{display:flex;flex-direction:column;align-items:flex-start;padding-right:44px}
.sts-countdown__unit strong{font-family:var(--font-display);font-weight:700;font-size:clamp(68px,9.5vw,120px);line-height:.88;letter-spacing:-.04em;color:#fff;font-variant-numeric:tabular-nums;display:block}
.sts-countdown__unit em{font-style:normal;font-family:var(--font-mono);font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:rgba(255,255,255,.52);margin-top:10px;display:block}
.sts-countdown__divider{font-family:var(--font-display);font-weight:200;font-size:clamp(44px,5.5vw,72px);line-height:.88;color:rgba(255,255,255,.16);padding-right:44px;align-self:flex-start;padding-top:2px}
.sts-countdown__meta{display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap;margin-top:28px;padding-top:22px;border-top:1px solid rgba(255,255,255,.10)}
.sts-countdown__caption{font-family:var(--font-mono);font-size:11px;letter-spacing:.13em;text-transform:uppercase;color:rgba(255,255,255,.50);margin:0}
.sts-countdown__caption strong{color:rgba(255,255,255,.88);font-weight:500}
.sts-milestones{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:8px}
.sts-milestones .sm{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:10px;padding:10px 14px;border:1px solid rgba(255,255,255,.09);border-radius:8px;background:rgba(255,255,255,.05);transition:background .15s ease,border-color .15s ease}
.sts-milestones .sm--next{border-color:rgba(255,255,255,.30);background:rgba(255,255,255,.10)}
.sts-milestones .sm--past{opacity:.38;background:rgba(255,255,255,.02)}
.sts-milestones .sm__num{font-family:var(--font-display);font-weight:600;font-size:18px;letter-spacing:-.02em;line-height:1;color:#fff;min-width:40px}
.sts-milestones .sm__label{font-size:12px;line-height:1.3;color:rgba(255,255,255,.84)}
.sts-milestones .sm__tag{font-family:var(--font-mono);font-size:9px;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.42);white-space:nowrap}
.sts-milestones .sm--next .sm__num{color:#fff}
.sts-milestones .sm--next .sm__tag{color:rgba(255,255,255,.78)}
.sts-milestones .sm--next .sm__label{color:#fff}
@media(max-width:1100px){.sts-milestones{grid-template-columns:repeat(4,1fr)}}
@media(max-width:900px){.sts-countdown{padding:60px var(--section-pad-x) 52px}.sts-countdown__unit{padding-right:28px}.sts-countdown__divider{padding-right:28px}}
@media(max-width:640px){.sts-countdown{padding:48px var(--section-pad-x) 40px}.sts-countdown__header{flex-direction:column;align-items:flex-start;gap:14px}.sts-countdown__unit{padding-right:16px}.sts-countdown__divider{padding-right:16px;font-size:clamp(32px,8vw,44px)}.sts-milestones{grid-template-columns:1fr 1fr}}
@media(max-width:420px){.sts-milestones{grid-template-columns:1fr}}
:root[data-theme=dark] .sts-countdown{background:linear-gradient(135deg,#0420b5 0%,#021266 100%)}
:root[data-theme=dark] .sts-countdown__cta{color:#2A4DDD}
@media(prefers-reduced-motion:reduce){.sts-countdown__live-dot{animation:none}}
@media print{.sts-countdown{display:none!important}}
</style>
STSHEAD;
require $STS_ROOT.'/inc/head.php';
?>
<section class="hero-v2" data-screen-label="01 Hero">
  <!-- wavy blob: matches flyer top-right decoration -->
  <svg class="hero-v2__blob" viewBox="0 0 480 420" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <ellipse cx="340" cy="90" rx="220" ry="200" fill="var(--blue,#0732F7)" opacity="0.13" transform="rotate(-20 340 90)"/>
    <ellipse cx="400" cy="200" rx="160" ry="240" fill="var(--crimson,#C8102E)" opacity="0.07" transform="rotate(15 400 200)"/>
    <ellipse cx="200" cy="60" rx="180" ry="120" fill="var(--blue,#0732F7)" opacity="0.07" transform="rotate(10 200 60)"/>
  </svg>
  <div class="hero-v2__inner">
    <!-- LEFT: copy -->
    <div class="hero-v2__copy">
      <span class="hero-v2__pill reveal" style="--i:0">
        <span class="hero-v2__pill-dot"></span>
        Active in 4 Lagos LGAs&nbsp;&middot;&nbsp;Term 3 enrolment open
      </span>
      <h1 class="hero-v2__headline reveal" style="--i:1">
        <span class="h-black">Equip.</span>
        <span class="h-crimson">Impact.</span>
        <span class="h-blue">Empower.</span>
      </h1>
      <p class="hero-v2__sub-head reveal" style="--i:2">Equip a Lagos Child</p>
      <p class="hero-v2__body reveal" style="--i:3">
        Help us provide <strong>Summer Packs, laptops, projectors, internet access, learning furniture, teaching materials, sound equipment, power solutions, event branding,</strong> and <strong>logistics support</strong> for <strong style="color:var(--crimson)">900+ children</strong> across <strong style="color:var(--crimson)">6 learning centres</strong> in <strong style="color:var(--blue)">Alimosho</strong>.
      </p>
      <div class="hero-v2__ctas reveal" style="--i:4">
        <a class="btn btn-primary" href="/get-involved">Get Involved <span class="btn-arrow">&rarr;</span></a>
        <a class="btn btn-secondary" href="/impact">See the Evidence</a>
      </div>
      <div class="hero-v2__credits reveal" style="--i:5">
        <span><strong>An initiative of Afrovanguard</strong></span>
        <span class="hero-v2__credits-sep" aria-hidden="true">&middot;</span>
        <span>Registered NGO</span>
        <span class="hero-v2__credits-sep" aria-hidden="true">&middot;</span>
        <span>Operating since 2021</span>
        <span class="hero-v2__credits-sep" aria-hidden="true">&middot;</span>
        <a href="https://afrovanguard.org.ng/donate" target="_blank" rel="noopener noreferrer">Donate at afrovanguard.org.ng/donate</a>
      </div>
    </div>
    <!-- RIGHT: rotating card-stack carousel -->
    <div class="hero-v2__photos reveal" style="--i:2">
      <div class="hero-v2__stack-wrap">
        <div class="hero-v2__stat-badge" aria-hidden="true">
          <strong>30k+</strong>
          <span>children equipped across Lagos</span>
        </div>
        <div class="hero-v2__stack" id="hero-stack">
          <div class="hero-v2__card" data-i="0" data-pos="0" data-label="School Storm · Outreach" tabindex="0" role="button" aria-label="Bring Street Storm photo to front">
            <img src="https://afrovanguard.org.ng/Images/anuoluwapo-ogunbanjo.jpg" alt="Students at a Street-To-Stardom session" width="960" height="1200" loading="eager" decoding="async" fetchpriority="high"/>
            <span class="hero-v2__card-shade"></span>
          </div>
          <div class="hero-v2__card" data-i="1" data-pos="0" data-label="CEO Time-out · NGG" tabindex="0" role="button" aria-label="Bring Time-out photo to front">
            <img src="https://afrovanguard.org.ng/Images/tayo-adeyemo.jpg" alt="Students at a Street-To-Stardom session" width="960" height="1200" loading="eager" decoding="async" fetchpriority="high"/>
            <span class="hero-v2__card-shade"></span>
          </div>
          <div class="hero-v2__card" data-i="2" data-pos="2" data-label="Street Storm · Mentorship" tabindex="0" role="button" aria-label="Bring Street Storm photo to front">
            <img src="https://afrovanguard.org.ng/Images/storm1.png" alt="Students at a Street-To-Stardom session" width="960" height="1200" loading="eager" decoding="async" fetchpriority="high"/>
            <span class="hero-v2__card-shade"></span>
          </div>
          <div class="hero-v2__card" data-i="3" data-pos="3" data-label="Summer School · Alimosho" tabindex="0" role="button" aria-label="Bring Summer School photo to front">
            <img src="https://afrovanguard.org.ng/Images/summer6.jpg" alt="Children at Alimosho Summer School" width="960" height="1200" loading="eager" decoding="async"/>
            <span class="hero-v2__card-shade"></span>
          </div>
          <div class="hero-v2__card" data-i="4" data-pos="4" data-label="NextGen · Bootcamp" tabindex="0" role="button" aria-label="Bring NextGen Bootcamp photo to front">
            <img src="https://afrovanguard.org.ng/Images/bootcamp1.png" alt="Next Gen Genius Club bootcamp" width="960" height="1200" loading="eager" decoding="async"/>
            <span class="hero-v2__card-shade"></span>
          </div>
        </div>
        <p class="hero-v2__stack-cap" id="hero-stack-cap" aria-live="polite"></p>
        <div class="hero-v2__dots" id="hero-stack-dots" role="tablist" aria-label="Choose a photo"></div>
      </div>
    </div>
  </div>
  <div class="hero-v2__scroll-cue">
    <button type="button" id="hero-scroll-cue" aria-label="Scroll to the programme countdown">
      <span>See what's next</span>
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M2 5l5 5 5-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
  </div>
</section>
<script>
(function(){
  var stack = document.getElementById("hero-stack");
  if (!stack) return;
  var cards = Array.prototype.slice.call(stack.querySelectorAll(".hero-v2__card"));
  var capEl = document.getElementById("hero-stack-cap");
  var dotsEl = document.getElementById("hero-stack-dots");
  var scrollCue = document.getElementById("hero-scroll-cue");
  var reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var INTERVAL_MS = 4200;

  // order[k] = index of the card currently sitting in stack-position k (0 = front/main)
  var order = cards.map(function(_, i){ return i; });
  var timer = null;
  var paused = false;

  // build dot indicators, one per card
  var dots = cards.map(function(_, i){
    var b = document.createElement("button");
    b.type = "button";
    b.className = "hero-v2__dot";
    b.setAttribute("role", "tab");
    b.setAttribute("aria-label", "Show photo " + (i + 1) + " of " + cards.length);
    b.addEventListener("click", function(){ bringToFront(i); restartTimer(); });
    dotsEl.appendChild(b);
    return b;
  });

  function applyPositions(){
    order.forEach(function(cardIndex, pos){
      cards[cardIndex].setAttribute("data-pos", pos);
    });
    var frontIndex = order[0];
    if (capEl) {
      var label = cards[frontIndex].getAttribute("data-label") || "";
      capEl.innerHTML = label ? ("Now showing &middot; <strong>" + label + "</strong>") : "";
    }
    dots.forEach(function(d, i){
      d.setAttribute("aria-current", i === frontIndex ? "true" : "false");
    });
  }

  function bringToFront(cardIndex){
    var pos = order.indexOf(cardIndex);
    if (pos <= 0) return;
    order.splice(pos, 1);
    order.unshift(cardIndex);
    applyPositions();
  }

  function rotate(){
    order.push(order.shift()); // front card rotates to the back; next card becomes main
    applyPositions();
  }

  function startTimer(){
    if (reduceMotion || timer) return;
    timer = setInterval(function(){ if (!paused) rotate(); }, INTERVAL_MS);
  }
  function restartTimer(){
    if (timer) { clearInterval(timer); timer = null; }
    startTimer();
  }

  applyPositions();
  startTimer();

  // pause the auto-rotation while a visitor is reading / interacting with the stack
  stack.addEventListener("mouseenter", function(){ paused = true; });
  stack.addEventListener("mouseleave", function(){ paused = false; });
  stack.addEventListener("focusin", function(){ paused = true; });
  stack.addEventListener("focusout", function(){ paused = false; });

  // clicking (or pressing Enter/Space on) a back card brings it to the front immediately
  cards.forEach(function(card, i){
    card.addEventListener("click", function(){
      if (card.getAttribute("data-pos") !== "0") { bringToFront(i); restartTimer(); }
    });
    card.addEventListener("keydown", function(e){
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        if (card.getAttribute("data-pos") !== "0") { bringToFront(i); restartTimer(); }
      }
    });
  });

  // scroll cue eases down to the countdown band
  if (scrollCue) {
    scrollCue.addEventListener("click", function(){
      var heroSection = stack.closest("section");
      var next = heroSection ? heroSection.nextElementSibling : null;
      if (next) next.scrollIntoView({ behavior: reduceMotion ? "auto" : "smooth", block: "start" });
    });
  }
})();
</script> <section class="sts-countdown" aria-label="Programme countdown"> <div class="sts-countdown__inner"> <div class="sts-countdown__header"> <div class="sts-countdown__live"> <span class="sts-countdown__live-pill"><span class="sts-countdown__live-dot"></span><span>Live · 2026 Programme</span></span> </div> <a class="sts-countdown__cta" href="/ceo">Become a 2026 Speaker <span class="ar" aria-hidden="true">→</span></a> </div> <div class="sts-countdown__nums-row" aria-live="polite"> <div class="sts-countdown__unit"><strong id="cd-weeks">—</strong><em>weeks</em></div> <span class="sts-countdown__divider" aria-hidden="true">·</span> <div class="sts-countdown__unit"><strong id="cd-days">—</strong><em>days</em></div> <span class="sts-countdown__divider" aria-hidden="true">·</span> <div class="sts-countdown__unit"><strong id="cd-hours">—</strong><em>hours</em></div> </div> <div class="sts-countdown__meta"> <p class="sts-countdown__caption" id="cd-caption">Until <strong>Summer School</strong> · Aug 2026 · Alimosho, Lagos</p> </div> <ul class="sts-milestones" aria-label="Programme milestones" id="milestone-list"></ul> </div> </section> <section class="section stats-section" data-screen-label="02 At a glance"> <div class="section-inner"> <div class="stats-head"> <div> <span class="eyebrow reveal">At a glance</span> <h2 class="section-title reveal">Numbers we can stand behind, refreshed each term.</h2> </div> <div class="stats-meta reveal">
Last verified Sept 2025 · Baseline–endline framework applied to all program outcomes · Full methodology under <a href="/impact" style="color: var(--blue); text-decoration: underline; text-underline-offset: 3px;">Impact</a>.
</div> </div> <div class="stats-grid" data-stats-grid=""> <div class="stat"> <div class="stat-numeral"> <span data-count="31072" data-stat-key="children_reached">0</span> </div> <div class="stat-label">Children reached</div> <div class="stat-foot">Across all programs, cumulative since 2021</div> </div> <div class="stat"> <div class="stat-numeral"> <span data-count="23" data-stat-key="schools_engaged">0</span> </div> <div class="stat-label">Schools engaged</div> <div class="stat-foot">Public + community schools in Alimosho, Ikeja, Kosofe, Agege</div> </div> <div class="stat"> <div class="stat-numeral"> <span data-count="156" data-stat-key="sessions_delivered">0</span> </div> <div class="stat-label">Sessions delivered</div> <div class="stat-foot">Workshops, clinics and field events in the 2024–25 cycle</div> </div> <div class="stat"> <div class="stat-numeral"> <span data-count="89" data-stat-key="literacy_improvement">0</span> <span class="stat-suffix">%</span> </div> <div class="stat-label">Literacy improvement</div> <div class="stat-foot">LCASP cohort, baseline to endline within one term</div> </div> </div> </div> </section> <section class="section what-section" data-screen-label="03 Programs"> <div class="section-inner"> <div class="what-head"> <div> <span class="eyebrow reveal">Our programs</span> <h2 class="section-title reveal">Four programs. One framework. Designed for the children Lagos overlooks.</h2> <p class="section-lede reveal">Each delivers a measurable outcome within a school term. Pick a path to see the methodology and results.</p> </div> <div class="reveal"> <a class="btn btn-ghost" href="/programs">All programs <span class="btn-arrow">→</span></a> </div> </div> <div class="programs-grid"> <div class="reveal"> <a class="program-card" href="/programs/next-gen"> <div class="placeholder card-ph" style=""><img alt="Next Generation Geniuses" loading="lazy" src="https://afrovanguard.org.ng/Images/bootcamp1.png" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:2;"/></div> <div class="program-body"> <div class="program-name"> <span>Next Generation Genius Club</span> <span class="program-arrow">→</span> </div> <p class="program-desc">Weekend Nation Builders Labs for ages 10–17, combining leadership, technology, creativity, entrepreneurship, and civic responsibility. Delivered through a structured year-long curriculum that culminates in a public showcase of projects, innovations, and community impact.</p> <div class="program-meta"> <strong>248</strong> <span>active members</span> <span style="margin-left: auto;"><span class="program-pill">year-long</span></span> </div> </div> </a> </div><div class="reveal"> <a class="program-card" href="/programs/summer-school"> <div class="placeholder card-ph" style=""><img alt="Alimosho Summer Students" loading="lazy" src="https://afrovanguard.org.ng/Images/summer6.jpg" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:2;"/></div> <div class="program-body"> <div class="program-name"> <span>Alimosho Summer School</span> <span class="program-arrow">→</span> </div> <p class="program-desc">A holiday transformation experience for children and teenagers, turning free time into a season of discovery, discipline, and growth. Participants engage in hands-on learning, leadership development, creative exploration, and practical life lessons designed to prepare them for the next stage of their journey.</p> <div class="program-meta"> <strong>412</strong> <span>summer 2024 enrolment</span> <span style="margin-left: auto;"><span class="program-pill">annual</span></span> </div> </div> </a> </div><div class="reveal"> <a class="program-card" href="/programs/lcasp"> <div class="placeholder card-ph" style=""><img alt="LCASP 2026" loading="lazy" src="https://afrovanguard.org.ng/Images/storm1.png" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:2;"/></div> <div class="program-body"> <div class="program-name"> <span>Lagos Community Advancement School Project</span> <span class="program-arrow">→</span> </div> <p class="program-desc">Our flagship program designed to equip children and teenagers with the knowledge, character, and leadership skills needed to thrive and make a positive impact in society.</p> <div class="program-meta"> <strong>23</strong> <span>partner schools</span> <span style="margin-left: auto;"><span class="program-pill">flagship</span></span> </div> </div> </a> </div><div class="reveal"> <a class="program-card" href="/programs/street-storm"> <div class="placeholder card-ph" style=""><img alt="STREET Storm mentorship session" loading="lazy" src="https://afrovanguard.org.ng/Images/storm1.png" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:2;"/></div> <div class="program-body"> <div class="program-name"> <span>STREET Storm</span> <span class="program-arrow">→</span> </div> <p class="program-desc">Street Storm is a grassroots engagement campaign that takes inspiration, opportunity, and transformation directly to children and families in their communities. Through school visits, neighborhood activations, mentorship, performances, and awareness drives, the initiative identifies hidden potential and connects young people to pathways for growth and success.</p> <div class="program-meta"> <strong>94</strong> <span>mentors active</span> <span style="margin-left: auto;"><span class="program-pill">youth</span></span> </div> </div> </a> </div> </div> </div> </section> <section class="section how-section" data-screen-label="04 Methodology"> <div class="section-inner"> <div class="pillars-head"> <div> <span class="eyebrow reveal">How we work</span> <h2 class="section-title reveal" style="font-size: clamp(28px, 3.4vw, 36px);">Five pillars. The same across every program.</h2> </div> <p class="section-lede reveal" style="margin-top: 0;">
We're not the only ones doing education work in Lagos. We try to be among the most transparent. The five-step rhythm below applies whether the cohort is 12 or 412.
</p> <div class="reveal" style="margin-top:22px;"> <a class="btn btn-ghost" href="/methodology">Explore the full Transformation Cycle <span class="btn-arrow">→</span></a> </div> </div> <div class="pillars-grid"> <div class="reveal pillar"> <div class="pillar-num">01</div> <div> <div class="pillar-title">Diagnose</div> <div class="pillar-desc">Baseline assessments at intake. We measure what we plan to change before we change it.</div> </div> </div><div class="reveal pillar"> <div class="pillar-num">02</div> <div> <div class="pillar-title">Design</div> <div class="pillar-desc">Curriculum and session plans built with practising teachers from the partner schools we serve.</div> </div> </div><div class="reveal pillar"> <div class="pillar-num">03</div> <div> <div class="pillar-title">Deliver</div> <div class="pillar-desc">Cohorts capped at 24. Twice-weekly sessions. Attendance and engagement tracked per child.</div> </div> </div><div class="reveal pillar"> <div class="pillar-num">04</div> <div> <div class="pillar-title">Document</div> <div class="pillar-desc">Endline assessments at the end of every term. Methods, raw data and findings published openly.</div> </div> </div><div class="reveal pillar"> <div class="pillar-num">05</div> <div> <div class="pillar-title">Distribute</div> <div class="pillar-desc">Reports shared with partner schools, funders, and the public — including the parts that didn't work.</div> </div> </div> </div> </div> </section> <section class="section evidence-section" data-screen-label="05 Evidence band"> <div class="section-inner"> <div class="evidence-grid"> <div class="reveal"> <span class="evidence-eyebrow">Evidence</span> <div style="font-size: 14px; color: rgba(255,255,255,0.78); line-height: 1.55; max-width: 360px;">
We collect baseline and endline data on every cohort. When something works, we say by how much. When it doesn't, we say so and revise.
</div> <div class="evidence-meta"> <div><strong>Study</strong>LCASP Cohort 2024–25</div> <div><strong>Period</strong>Dec 2024 – Sept 2025</div> <div><strong>Sample</strong>n = 412 across 23 schools</div> </div> </div> <div class="evidence-stat reveal"> <span class="big">89%</span>
of LCASP participants improved literacy scores within a single school term.
<em>Measured against assessments administered at intake using a partner-verified instrument. Endline data collected September 2025. Full methodology and per-school breakdown forthcoming in the 2025 annual report.</em> <a class="evidence-cta" href="/impact">Read the methodology <span aria-hidden="true">→</span></a> </div> </div> </div> </section> <section class="section latest-section" data-screen-label="06 Latest"> <div class="section-inner"> <div class="latest-head"> <div> <span class="eyebrow reveal">Latest from the field</span> <h2 class="section-title reveal">Field notes, methodology, and the parts we're still figuring out.</h2> </div> <div class="reveal"> <a class="btn btn-ghost" href="/blog">Read the blog <span class="btn-arrow">→</span></a> </div> </div> <div class="latest-grid"> <div class="reveal"> <a class="post-card" href="/blog"> <div class="placeholder" style=""><img alt="Alimosho Summer School session" loading="lazy" src="https://afrovanguard.org.ng/Images/summer1.png" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:2;"/></div> <div class="post-meta"> <span class="post-cat">Education</span> <span class="sep">·</span> <span>May 2026</span> </div> <div class="post-title">What we learned running Summer School across two new LGAs</div> <p class="post-excerpt">Scaling Alimosho's six-week intensive into Kosofe and Ikeja taught us more about logistics than pedagogy. A field note.</p> </a> </div><div class="reveal"> <a class="post-card" href="/blog"> <div class="placeholder" style=""><img alt="Research and methodology in practice" loading="lazy" src="https://afrovanguard.org.ng/Images/summer1.png" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:2;"/></div> <div class="post-meta"> <span class="post-cat">Methodology</span> <span class="sep">·</span> <span>Apr 2026</span> </div> <div class="post-title">Why we publish the studies that don't work the way we hoped</div> <p class="post-excerpt">An honest accounting of our 2024 numeracy pilot, the parts that worked, and the parts we've already retired.</p> </a> </div><div class="reveal"> <a class="post-card" href="/blog"> <div class="placeholder" style=""><img alt="Community and family engagement" loading="lazy" src="https://afrovanguard.org.ng/Images/culture1.png" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:2;"/></div> <div class="post-meta"> <span class="post-cat">Community</span> <span class="sep">·</span> <span>Mar 2026</span> </div> <div class="post-title">Notes from the parents we meet every Saturday morning</div> <p class="post-excerpt">Six months of NextGen Genius Club from the family-engagement desk. Recurring themes, surprises, and one quiet ask.</p> </a> </div> </div> </div> </section> <section class="section gi-section" data-screen-label="07 Get involved"> <div class="section-inner"> <div class="gi-head"> <span class="eyebrow reveal">Get involved</span> <h2 class="section-title reveal">Four ways to help. None of them start with "donate now".</h2> <p class="section-lede reveal">
We've built four pathways so that anyone with time, expertise or resources can find a seat at the table. Each one tells you what to expect before you sign anything.
</p> </div> <div class="gi-grid"> <div class="reveal"> <a class="gi-tile" href="/get-involved/volunteer"> <div> <div class="gi-tile-num">01 / 04</div> <div class="gi-tile-title">Volunteer</div> </div> <div> <div class="gi-tile-fact">234 active volunteers across teaching, mentoring and back-office roles.</div> <div class="gi-tile-arrow" style="margin-top: 16px;">
Open pathway <span aria-hidden="true">→</span> </div> </div> </a> </div><div class="reveal"> <a class="gi-tile" href="/get-involved/partner"> <div> <div class="gi-tile-num">02 / 04</div> <div class="gi-tile-title">Partner</div> </div> <div> <div class="gi-tile-fact">12 institutional partners: schools, faith bodies, community NGOs.</div> <div class="gi-tile-arrow" style="margin-top: 16px;">
Open pathway <span aria-hidden="true">→</span> </div> </div> </a> </div><div class="reveal"> <a class="gi-tile" href="/get-involved/sponsor"> <div> <div class="gi-tile-num">03 / 04</div> <div class="gi-tile-title">Sponsor</div> </div> <div> <div class="gi-tile-fact">67 children currently sponsored. Term-by-term reporting to every sponsor.</div> <div class="gi-tile-arrow" style="margin-top: 16px;">
Open pathway <span aria-hidden="true">→</span> </div> </div> </a> </div><div class="reveal"> <a class="gi-tile primary" href="/get-involved/donate"> <div> <div class="gi-tile-num">04 / 04</div> <div class="gi-tile-title">Donate</div> </div> <div> <div class="gi-tile-fact">Every donation routed against published costs. Receipts in your inbox.</div> <div class="gi-tile-arrow" style="margin-top: 16px;">
Open pathway <span aria-hidden="true">→</span> </div> </div> </a> </div> </div> </div> </section>
<section class="sts-eco sts-accent" data-screen-label="08 Afrovanguard ecosystem" aria-label="The Afrovanguard ecosystem">
  <div class="sts-eco__inner">
    <span class="eyebrow reveal">Part of something bigger</span>
    <h2 class="section-title reveal" style="font-size:clamp(28px,3.4vw,36px);max-width:680px;">Street-To-Stardom is one of several Afrovanguard initiatives rebuilding Africa from the grassroots.</h2>
    <div class="sts-eco__grid">
      <a class="sts-eco__card reveal" href="https://afrovanguard.org.ng" target="_blank" rel="noopener noreferrer"><span class="tag">Parent organisation</span><span class="name">Afrovanguard ↗</span><span class="desc">The institutional home: governance, safeguarding, trustees and a single audited set of accounts.</span><span class="go">afrovanguard.org.ng</span></a>
      <a class="sts-eco__card reveal" href="https://next.afrovanguard.org.ng/" target="_blank" rel="noopener noreferrer"><span class="tag">Sister initiative</span><span class="name">Next Generation Genius ↗</span><span class="desc">The alumni and innovation network where transformed children become mentors and multipliers.</span><span class="go">next.afrovanguard.org.ng</span></a>
      <a class="sts-eco__card reveal" href="https://cacentre.afrovanguard.org.ng/techhome/" target="_blank" rel="noopener noreferrer"><span class="tag">Sister initiative</span><span class="name">Techome ↗</span><span class="desc">Technology and digital-skills programmes that feed the Skill pillar of our methodology.</span><span class="go">cacentre.afrovanguard.org.ng</span></a>
      <a class="sts-eco__card reveal" href="https://afrovanguard.org.ng/donate" target="_blank" rel="noopener noreferrer"><span class="tag">Support the mission</span><span class="name">Donate via Afrovanguard ↗</span><span class="desc">Every gift is routed against a published cost ledger, with receipts and term-by-term reporting.</span><span class="go">afrovanguard.org.ng/donate</span></a>
    </div>
  </div>
</section>
<?php
$PAGE_FOOT_EXTRA = <<<'STSFOOT'
<script>
(function () {
  // ── Fallback constants (mirrors the milestones cache) ────────────
  var FALLBACK = [
    { key:"ss-start",   label:"School Storm starts",            date:"2026-04-28T00:00:00+01:00" },
    { key:"ss-end",     label:"School Storm ends",              date:"2026-07-28T00:00:00+01:00" },
    { key:"sum-start",  label:"Summer School starts",           date:"2026-07-28T00:00:00+01:00" },
    { key:"sum-end",    label:"Summer School ends",             date:"2026-08-30T00:00:00+01:00" },
    { key:"boot-start", label:"Bootcamp starts",                date:"2026-08-30T00:00:00+01:00" },
    { key:"boot-end",   label:"Bootcamp ends",                  date:"2026-09-05T00:00:00+01:00" },
    { key:"ogidi",      label:"Ogidi Omo Concert & Exhibition", date:"2026-09-05T00:00:00+01:00" }
  ];

  // ── Countdown target (may be updated after fetch) ────────────────
  var TARGET_MS = new Date("2026-07-28T00:00:00+01:00").getTime();

  // ── DOM helpers ──────────────────────────────────────────────────
  function $id(id) { return document.getElementById(id); }

  function computeCountdown() {
    var diff = Math.max(0, TARGET_MS - Date.now());
    return {
      weeks: Math.floor(diff / (86400000 * 7)),
      days:  Math.floor(diff / 86400000),
      hours: Math.floor((diff % 86400000) / 3600000)
    };
  }

  function renderCountdown() {
    var cd = computeCountdown();
    var w = $id("cd-weeks"), d = $id("cd-days"), h = $id("cd-hours");
    if (w) w.textContent = cd.weeks;
    if (d) d.textContent = cd.days;
    if (h) h.textContent = cd.hours;
  }

  function computeStatus(isoDate) {
    var t = new Date(isoDate).getTime(), now = Date.now();
    var diff = t - now, abs = Math.abs(diff);
    return {
      isPast: diff < 0,
      weeks:  Math.floor(abs / (86400000 * 7)),
      totalDays: Math.floor(abs / 86400000)
    };
  }

  function fmtDate(isoDate) {
    try {
      return new Date(isoDate).toLocaleDateString("en-GB", {
        day:"numeric", month:"short", year:"numeric", timeZone:"Africa/Lagos"
      });
    } catch(e) { return isoDate.slice(0,10); }
  }

  function updateCaption(target) {
    var cap = $id("cd-caption");
    if (!cap || !target) return;
    cap.innerHTML = "Until <strong>" + target.label + "</strong> · "
      + fmtDate(target.date) + " · Alimosho, Lagos";
  }

  function findTarget(list) {
    var now = Date.now(), sumStart = null, firstFuture = null;
    for (var i = 0; i < list.length; i++) {
      var t = new Date(list[i].date).getTime();
      if (list[i].key === "sum-start") sumStart = list[i];
      if (!firstFuture && t > now) firstFuture = list[i];
    }
    if (sumStart && new Date(sumStart.date).getTime() > now) return sumStart;
    return firstFuture || sumStart || list[0] || null;
  }

  function renderMilestones(list) {
    var el = $id("milestone-list");
    if (!el) return;
    var now = Date.now();
    // identify the next upcoming milestone
    var nextIdx = -1;
    for (var i = 0; i < list.length; i++) {
      if (new Date(list[i].date).getTime() > now) { nextIdx = i; break; }
    }
    el.className = "sts-milestones";
    el.innerHTML = list.map(function (m, i) {
      var s = computeStatus(m.date);
      var isNext = (i === nextIdx);
      var cls = "sm" + (s.isPast ? " sm--past" : isNext ? " sm--next" : "");
      var display = s.weeks > 0 ? (s.weeks + "w") : (s.totalDays + "d");
      var tag = s.isPast ? "passed" : (isNext ? "next up" : "to go");
      return '<li class="' + cls + '">'
        + '<span class="sm__num">' + display + '</span>'
        + '<span class="sm__label">' + m.label + '</span>'
        + '<span class="sm__tag">' + tag + '</span></li>';
    }).join("");
  }

  function applyData(milestones) {
    var target = findTarget(milestones);
    if (target) TARGET_MS = new Date(target.date).getTime();
    renderCountdown();
    updateCaption(target);
    renderMilestones(milestones);
  }

  // ── Render immediately with fallback ─────────────────────────────
  applyData(FALLBACK);

  // ── Tick every 60 s ──────────────────────────────────────────────
  setInterval(renderCountdown, 60000);

  // ── Fetch live milestones from Google Sheets (via PHP proxy) ─────
  if (typeof fetch !== "undefined") {
    fetch("/ceo/api/milestones.php", { cache: "default" })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (body) {
        if (body && body.ok && body.data && body.data.milestones && body.data.milestones.length) {
          applyData(body.data.milestones);
        }
      })
      .catch(function () { /* keep fallback — already rendered */ });
  }
})();
</script>
STSFOOT;
require $STS_ROOT.'/inc/footer.php';
