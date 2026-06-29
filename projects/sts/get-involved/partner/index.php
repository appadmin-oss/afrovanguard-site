<?php
$STS_ROOT = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($STS_ROOT === '' || !is_file($STS_ROOT.'/inc/head.php')) { $STS_ROOT = __DIR__; while (!is_file($STS_ROOT.'/inc/head.php') && dirname($STS_ROOT) !== $STS_ROOT) $STS_ROOT = dirname($STS_ROOT); }
$PAGE_TITLE = "Partner with STS — Street-To-Stardom";
$PAGE_DESC  = "";
$PAGE_PATH  = "/get-involved/partner/";
$PAGE_HEAD_EXTRA = <<<'STSHEAD'
<style>.form-row-2[data-astro-cid-v5n3xdeu]{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media (max-width: 600px){.form-row-2[data-astro-cid-v5n3xdeu]{grid-template-columns:1fr;gap:0}}.proposal-typing[data-astro-cid-v5n3xdeu]{width:100%;min-height:200px;padding:14px;border:1px solid var(--blue);background:var(--blue-tint);border-radius:8px;font-size:14.5px;line-height:1.55;color:var(--ink);white-space:pre-wrap}
</style>
STSHEAD;
require $STS_ROOT.'/inc/head.php';
?>
<section class="section page-header" data-screen-label="Page header"> <div class="section-inner"> <span class="eyebrow reveal">Partner · institutional intake</span> <h1 class="page-title reveal">Five org types. <span class="accent">One branching form</span>. Tailored proposal at the end.</h1> <p class="page-lede reveal">We work with schools, NGOs, corporates, government and faith bodies. The form below asks four to five tailored questions per org type, then drafts a partnership proposal you can edit and approve before we route it.</p>  </div> </section> <section class="section form-shell" data-screen-label="Partner · Form"> <div class="section-inner"> <div x-data="partnerForm()" class="form-grid" data-astro-cid-v5n3xdeu> <div class="form-card" data-astro-cid-v5n3xdeu> <template x-if="!submitted" data-astro-cid-v5n3xdeu> <div data-astro-cid-v5n3xdeu> <div class="form-stepper-label" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Step 0<span x-text="step + 1" data-astro-cid-v5n3xdeu></span> / 03</span> <strong x-text="stepLabels[step]" data-astro-cid-v5n3xdeu></strong> </div> <div class="form-stepper" data-astro-cid-v5n3xdeu> <template x-for="i in [0,1,2]" :key="i" data-astro-cid-v5n3xdeu> <span class="step" :class="{ 'done': i < step, 'on': i === step }" data-astro-cid-v5n3xdeu></span> </template> </div> <!-- Step 0: Type --> <div x-show="step === 0" x-transition.opacity x-cloak data-astro-cid-v5n3xdeu> <div class="form-step-title" data-astro-cid-v5n3xdeu>Tell us who's reaching out.</div> <p class="form-step-sub" data-astro-cid-v5n3xdeu>Pick the closest fit; we'll branch the next page accordingly.</p> <div class="chip-grid" style="margin-bottom: 32px;" data-astro-cid-v5n3xdeu> <button type="button" class="chip" :class="orgType === 'school' ? 'on' : ''" @click="orgType = 'school'" data-astro-cid-v5n3xdeu>School</button><button type="button" class="chip" :class="orgType === 'ngo' ? 'on' : ''" @click="orgType = 'ngo'" data-astro-cid-v5n3xdeu>NGO</button><button type="button" class="chip" :class="orgType === 'corporate' ? 'on' : ''" @click="orgType = 'corporate'" data-astro-cid-v5n3xdeu>Corporate</button><button type="button" class="chip" :class="orgType === 'government' ? 'on' : ''" @click="orgType = 'government'" data-astro-cid-v5n3xdeu>Government</button><button type="button" class="chip" :class="orgType === 'faith_based' ? 'on' : ''" @click="orgType = 'faith_based'" data-astro-cid-v5n3xdeu>Faith body</button> </div> <div class="form-step-title" style="font-size: 20px;" data-astro-cid-v5n3xdeu>Branching · <span x-text="typeLabel()" data-astro-cid-v5n3xdeu></span></div> <p class="form-step-sub" style="font-size: 13px;" data-astro-cid-v5n3xdeu>Preview of fields we'll ask next.</p> <div style="display: flex; flex-direction: column; gap: 12px;" data-astro-cid-v5n3xdeu> <template x-if="orgType === 'school'" data-astro-cid-v5n3xdeu> <div style="display: flex; flex-direction: column; gap: 12px;" data-astro-cid-v5n3xdeu> <div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>School name</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>LGA</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Pupil count</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Headteacher contact</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div> </div> </template><template x-if="orgType === 'ngo'" data-astro-cid-v5n3xdeu> <div style="display: flex; flex-direction: column; gap: 12px;" data-astro-cid-v5n3xdeu> <div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Organisation name</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Registration / RC number</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Focus area</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Annual budget bracket</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div> </div> </template><template x-if="orgType === 'corporate'" data-astro-cid-v5n3xdeu> <div style="display: flex; flex-direction: column; gap: 12px;" data-astro-cid-v5n3xdeu> <div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Company name</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Sector</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>CSR contact</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Engagement type (cash / in-kind / volunteering)</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div> </div> </template><template x-if="orgType === 'government'" data-astro-cid-v5n3xdeu> <div style="display: flex; flex-direction: column; gap: 12px;" data-astro-cid-v5n3xdeu> <div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Agency / ministry</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Department</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Programme alignment</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Liaison officer</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div> </div> </template><template x-if="orgType === 'faith_based'" data-astro-cid-v5n3xdeu> <div style="display: flex; flex-direction: column; gap: 12px;" data-astro-cid-v5n3xdeu> <div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Body name</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Denomination / tradition</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Catchment area</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div><div style="padding: 14px 16px; border: 1px dashed var(--hairline-strong); border-radius: 10px; font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; align-items: center;" data-astro-cid-v5n3xdeu> <span data-astro-cid-v5n3xdeu>Pastoral lead contact</span> <span style="font-family: var(--font-mono); font-size: 11px;" data-astro-cid-v5n3xdeu>required</span> </div> </div> </template> </div> <div class="ai-banner" style="margin-top: 24px;" data-astro-cid-v5n3xdeu> <span class="spark" data-astro-cid-v5n3xdeu>✦</span> <div data-astro-cid-v5n3xdeu> <strong data-astro-cid-v5n3xdeu>AI-drafted proposal</strong> · Once you complete step 02, we'll generate a one-paragraph partnership-proposal preview from your responses. You can edit and approve it before it routes to the partnerships inbox.
</div> </div> </div> <!-- Step 1: Details --> <div x-show="step === 1" x-transition.opacity x-cloak data-astro-cid-v5n3xdeu> <div class="form-step-title" data-astro-cid-v5n3xdeu>Tell us a little more.</div> <p class="form-step-sub" data-astro-cid-v5n3xdeu>Five short fields — we'll turn this into a proposal in the next step.</p> <div class="form-field" data-astro-cid-v5n3xdeu> <label class="form-label" data-astro-cid-v5n3xdeu>Organisation name</label> <input class="form-input" type="text" x-model="orgName" placeholder="As registered" data-astro-cid-v5n3xdeu> </div> <div class="form-row-2" data-astro-cid-v5n3xdeu> <div class="form-field" data-astro-cid-v5n3xdeu> <label class="form-label" data-astro-cid-v5n3xdeu>Your name</label> <input class="form-input" type="text" x-model="contactName" data-astro-cid-v5n3xdeu> </div> <div class="form-field" data-astro-cid-v5n3xdeu> <label class="form-label" data-astro-cid-v5n3xdeu>Your role</label> <input class="form-input" type="text" x-model="contactRole" placeholder="e.g. Head of Partnerships" data-astro-cid-v5n3xdeu> </div> </div> <div class="form-row-2" data-astro-cid-v5n3xdeu> <div class="form-field" data-astro-cid-v5n3xdeu> <label class="form-label" data-astro-cid-v5n3xdeu>Email</label> <input class="form-input" type="email" x-model="email" :class="{ 'invalid': emailInvalid }" @blur="validateEmail()" data-astro-cid-v5n3xdeu> <p class="form-help" x-show="emailInvalid" x-cloak style="color: var(--crimson);" data-astro-cid-v5n3xdeu>Please enter a valid email address.</p> </div> <div class="form-field" data-astro-cid-v5n3xdeu> <label class="form-label" data-astro-cid-v5n3xdeu>Phone <span class="opt" data-astro-cid-v5n3xdeu>optional</span></label> <input class="form-input" type="tel" x-model="phone" data-astro-cid-v5n3xdeu> </div> </div> <div class="form-field" data-astro-cid-v5n3xdeu> <label class="form-label" data-astro-cid-v5n3xdeu>What partnership are you exploring?</label> <textarea class="form-textarea" rows="5" x-model="interest" placeholder="A short description — we'll use this to draft a proposal preview." data-astro-cid-v5n3xdeu></textarea> </div> </div> <!-- Step 2: AI proposal draft --> <div x-show="step === 2" x-transition.opacity x-cloak data-astro-cid-v5n3xdeu> <div class="form-step-title" data-astro-cid-v5n3xdeu>Here's a draft we'll send to our partnerships team — edit anything.</div> <p class="form-step-sub" data-astro-cid-v5n3xdeu>Our intake assistant wrote this from your answers. Tweak it; the version you submit is the one we read first.</p> <div x-show="aiLoading" class="ai-banner" x-cloak data-astro-cid-v5n3xdeu> <span class="spark" data-astro-cid-v5n3xdeu>✦</span> <div data-astro-cid-v5n3xdeu> <strong data-astro-cid-v5n3xdeu>Drafting your proposal<span class="ai-typing" data-astro-cid-v5n3xdeu></span></strong> <div style="margin-top: 8px; display: flex; flex-direction: column; gap: 6px;" data-astro-cid-v5n3xdeu> <span class="skeleton" style="width: 92%;" data-astro-cid-v5n3xdeu></span> <span class="skeleton" style="width: 84%;" data-astro-cid-v5n3xdeu></span> <span class="skeleton" style="width: 76%;" data-astro-cid-v5n3xdeu></span> <span class="skeleton" style="width: 60%;" data-astro-cid-v5n3xdeu></span> </div> </div> </div> <div class="form-field" x-show="!aiLoading" x-transition.opacity x-cloak data-astro-cid-v5n3xdeu> <label class="form-label" data-astro-cid-v5n3xdeu>
Proposal preview <span class="opt" data-astro-cid-v5n3xdeu>edit freely</span> </label> <div x-show="typing" x-cloak class="proposal-typing" data-astro-cid-v5n3xdeu> <span x-text="typedText" data-astro-cid-v5n3xdeu></span><span class="ai-typing" aria-hidden="true" data-astro-cid-v5n3xdeu></span> </div> <textarea x-show="!typing" class="form-textarea" rows="10" x-model="proposal" data-astro-cid-v5n3xdeu></textarea> <p class="form-help" data-astro-cid-v5n3xdeu>80–120 words. References real STS programs where relevant. You can edit it down to a single sentence if you prefer.</p> </div> </div> <input type="text" name="website" tabindex="-1" autocomplete="off" x-model="honeypot" style="position:absolute; left:-9999px;" aria-hidden="true" data-astro-cid-v5n3xdeu> <div class="form-actions" data-astro-cid-v5n3xdeu> <button type="button" class="form-back" @click="goBack" :disabled="step === 0" :style="{ opacity: step === 0 ? 0.3 : 1 }" data-astro-cid-v5n3xdeu>← Back</button> <button type="button" class="btn btn-primary" x-show="step < 2" @click="goNext" :disabled="(step === 1 && (!orgName || !contactName || !email || !interest))" :style="{ opacity: (step === 1 && (!orgName || !contactName || !email || !interest)) ? 0.5 : 1 }" data-astro-cid-v5n3xdeu>
Continue <span class="btn-arrow" data-astro-cid-v5n3xdeu>→</span> </button> <button type="button" class="btn btn-primary" x-show="step === 2" @click="submit" :disabled="submitting || aiLoading" :style="{ opacity: (submitting || aiLoading) ? 0.5 : 1 }" data-astro-cid-v5n3xdeu> <span x-text="submitting ? 'Sending…' : 'Send to partnerships team'" data-astro-cid-v5n3xdeu></span> <span class="btn-arrow" x-show="!submitting" data-astro-cid-v5n3xdeu>→</span> </button> </div> <p class="form-help" x-show="submitError" x-cloak style="color: var(--crimson); margin-top: 12px;" x-text="submitError" data-astro-cid-v5n3xdeu></p> </div> </template> <template x-if="submitted" data-astro-cid-v5n3xdeu> <div class="confirm-block slide-up" role="status" aria-live="polite" data-astro-cid-v5n3xdeu> <div class="confirm-check" aria-hidden="true" data-astro-cid-v5n3xdeu>✓</div> <h2 class="confirm-title" data-astro-cid-v5n3xdeu>Proposal received.</h2> <p class="confirm-sub" data-astro-cid-v5n3xdeu>A named member of our partnerships team will review and reply within five business days.</p> <dl class="confirm-meta" data-astro-cid-v5n3xdeu> <div data-astro-cid-v5n3xdeu><dt data-astro-cid-v5n3xdeu>Organisation</dt><dd x-text="orgName" data-astro-cid-v5n3xdeu></dd></div> <div data-astro-cid-v5n3xdeu><dt data-astro-cid-v5n3xdeu>Type</dt><dd x-text="typeLabel()" data-astro-cid-v5n3xdeu></dd></div> <div data-astro-cid-v5n3xdeu><dt data-astro-cid-v5n3xdeu>Reply by</dt><dd data-astro-cid-v5n3xdeu>Within 5 business days</dd></div> </dl> </div> </template> </div> <div class="form-side" data-astro-cid-v5n3xdeu> <div class="form-side-card" data-astro-cid-v5n3xdeu> <h4 data-astro-cid-v5n3xdeu>How partnership works</h4> <ul class="form-side-list" data-astro-cid-v5n3xdeu> <li :class="step >= 0 ? 'done' : ''" data-astro-cid-v5n3xdeu>Five-minute branching intake</li> <li :class="step >= 2 ? 'done' : ''" data-astro-cid-v5n3xdeu>AI-drafted proposal preview</li> <li :class="proposal ? 'done' : ''" data-astro-cid-v5n3xdeu>You edit and approve</li> <li :class="submitted ? 'done' : ''" data-astro-cid-v5n3xdeu>Routes to partnerships@</li> <li data-astro-cid-v5n3xdeu>Named reply within 5 business days</li> </ul> </div> <div class="form-side-card" data-astro-cid-v5n3xdeu> <h4 data-astro-cid-v5n3xdeu>Already working with us?</h4> <p data-astro-cid-v5n3xdeu>If you're an existing partner liaison, log in to the partner portal for term reports and joint planning materials.</p> <a href="#" style="display: inline-block; margin-top: 12px; font-size: 13px; font-weight: 600; color: var(--blue);" data-astro-cid-v5n3xdeu>Partner portal <span aria-hidden="true" data-astro-cid-v5n3xdeu>→</span></a> </div> </div> </div>  <script>(function(){const types = [{"id":"school","label":"School"},{"id":"ngo","label":"NGO"},{"id":"corporate","label":"Corporate"},{"id":"government","label":"Government"},{"id":"faith_based","label":"Faith body"}];

  document.addEventListener('alpine:init', () => {
    const STORAGE_KEY = 'sts.partner.draft';
    const load = () => { try { return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '{}'); } catch { return {}; } };
    const save = (d) => { try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(d)); } catch {} };

    window.Alpine.data('partnerForm', () => {
      const draft = load();
      return {
        step: 0,
        orgType: draft.orgType || 'school',
        orgName: draft.orgName || '',
        contactName: draft.contactName || '',
        contactRole: draft.contactRole || '',
        email: draft.email || '',
        phone: draft.phone || '',
        interest: draft.interest || '',
        proposal: draft.proposal || '',
        aiLoading: false,
        typing: false,
        typedText: '',
        honeypot: '',
        submitting: false,
        submitted: false,
        submitError: '',
        emailInvalid: false,
        stepLabels: ["Org type", "Details", "Proposal"],

        typeLabel() {
          const t = types.find(x => x.id === this.orgType);
          return t ? t.label : '';
        },
        validateEmail() {
          this.emailInvalid = this.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(this.email);
        },
        persist() {
          save({ orgType: this.orgType, orgName: this.orgName, contactName: this.contactName, contactRole: this.contactRole, email: this.email, phone: this.phone, interest: this.interest, proposal: this.proposal });
        },
        async draftProposal() {
          this.aiLoading = true;
          this.typing = false;
          this.typedText = '';
          let next = '';
          try {
            const fd = new FormData();
            fd.append('action', 'draft');
            fd.append('csrf_token', document.cookie.match(/sts_csrf=([^;]+)/)?.[1] || '');
            fd.append('org_name', this.orgName);
            fd.append('org_type', this.orgType);
            fd.append('partnership_interest', this.interest);
            const r = await fetch('/api/partner.php', { method: 'POST', body: fd, credentials: 'include' });
            const j = await r.json().catch(() => ({}));
            next = (j && j.proposal) ? String(j.proposal) : this.fallbackProposal();
          } catch {
            next = this.fallbackProposal();
          } finally {
            this.aiLoading = false;
            await this.revealProposal(next);
          }
        },
        async revealProposal(text) {
          const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
          this.proposal = text;
          if (reduced) { this.typing = false; return; }
          this.typing = true;
          this.typedText = '';
          // Reveal ~25 chars / frame; takes ~0.6s for a 100-word paragraph.
          const total = text.length;
          let i = 0;
          await new Promise((resolve) => {
            const tick = () => {
              i = Math.min(total, i + Math.max(6, Math.round(total / 60)));
              this.typedText = text.slice(0, i);
              if (i < total) requestAnimationFrame(tick);
              else resolve(null);
            };
            requestAnimationFrame(tick);
          });
          // Tiny dwell so the cursor sits at the end momentarily.
          await new Promise(r => setTimeout(r, 220));
          this.typing = false;
        },
        fallbackProposal() {
          return `Street-To-Stardom is exploring a partnership with ${this.orgName} (${this.typeLabel().toLowerCase()}). Their stated interest: ${this.interest}. Potential alignment includes our Next Gen Genius Club, Alimosho Summer School, LCASP and STREET Storm programs. We would propose a discovery call to identify which of our four streams maps most clearly to their stated priorities, followed by a short scoping memo and a six-month pilot proposal with shared evaluation metrics. Cohort sizes remain capped at 24, and all outcomes would be reported per the published baseline-endline framework.`;
        },
        async goNext() {
          if (this.step === 1) await this.draftProposal();
          this.step = Math.min(this.step + 1, 2);
          this.persist();
        },
        goBack() { this.step = Math.max(0, this.step - 1); },
        async submit() {
          this.validateEmail();
          if (this.emailInvalid) return;
          if (this.honeypot) { this.submitted = true; return; }
          this.submitError = '';
          this.submitting = true;
          try {
            const fd = new FormData();
            fd.append('action', 'submit');
            fd.append('csrf_token', document.cookie.match(/sts_csrf=([^;]+)/)?.[1] || '');
            fd.append('org_name', this.orgName);
            fd.append('org_type', this.orgType);
            fd.append('contact_name', this.contactName);
            fd.append('contact_role', this.contactRole);
            fd.append('email', this.email);
            fd.append('phone', this.phone);
            fd.append('partnership_interest', this.interest);
            fd.append('user_approved_proposal', this.proposal);
            const r = await fetch('/api/partner-confirm.php', { method: 'POST', body: fd, credentials: 'include' });
            const j = await r.json().catch(() => ({}));
            if (r.ok && j.ok) {
              this.submitted = true;
              try { sessionStorage.removeItem(STORAGE_KEY); } catch {}
              window.stsToast?.('Proposal sent — our partnerships team replies within 5 business days.', 'success');
            } else {
              this.submitError = j.error || 'Something went wrong. Please try again.';
              window.stsToast?.(this.submitError, 'error');
            }
          } catch {
            this.submitError = 'Network error. Please try again.';
            window.stsToast?.(this.submitError, 'error');
          } finally { this.submitting = false; }
        },
        init() {
          ['orgType','orgName','contactName','contactRole','email','phone','interest','proposal'].forEach(k => this.$watch(k, () => this.persist()));
        }
      };
    });
  });
})();</script> </div> </section>
<?php
require $STS_ROOT.'/inc/footer.php';
