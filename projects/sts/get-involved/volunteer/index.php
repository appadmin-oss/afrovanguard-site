<?php
$STS_ROOT = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($STS_ROOT === '' || !is_file($STS_ROOT.'/inc/head.php')) { $STS_ROOT = __DIR__; while (!is_file($STS_ROOT.'/inc/head.php') && dirname($STS_ROOT) !== $STS_ROOT) $STS_ROOT = dirname($STS_ROOT); }
$PAGE_TITLE = "Volunteer — Street-To-Stardom";
$PAGE_DESC  = "";
$PAGE_PATH  = "/get-involved/volunteer/";
$PAGE_HEAD_EXTRA = <<<'STSHEAD'
<style>.vol-role-grid[data-astro-cid-inbwlv3e]{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:24px}.vol-role[data-astro-cid-inbwlv3e]{-webkit-appearance:none;-moz-appearance:none;appearance:none;padding:16px 18px;text-align:left;border:1.5px solid var(--hairline-strong);background:var(--bg);border-radius:12px;cursor:pointer;transition:border-color .12s ease,background .12s ease,transform 60ms ease;font-family:inherit}.vol-role[data-astro-cid-inbwlv3e].on{border-color:var(--blue);background:var(--blue-tint)}.vol-role[data-astro-cid-inbwlv3e]:hover{border-color:var(--ink)}.vol-role[data-astro-cid-inbwlv3e]:active{transform:scale(.99)}.vol-role-name[data-astro-cid-inbwlv3e]{font-family:var(--font-display);font-weight:600;font-size:15px;color:var(--ink)}.vol-role-desc[data-astro-cid-inbwlv3e]{font-size:12.5px;color:var(--muted);margin-top:4px;line-height:1.4}.review-grid[data-astro-cid-inbwlv3e]{display:flex;flex-direction:column;border:1px solid var(--hairline);border-radius:var(--r-lg);background:var(--bg);overflow:hidden}.review-row[data-astro-cid-inbwlv3e]{display:grid;grid-template-columns:120px 1fr auto;gap:20px;padding:18px 20px;align-items:start;border-top:1px solid var(--hairline)}.review-row[data-astro-cid-inbwlv3e]:first-child{border-top:0}.review-label[data-astro-cid-inbwlv3e]{font-family:var(--font-mono);font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);padding-top:4px}.review-value[data-astro-cid-inbwlv3e]{font-size:14.5px;color:var(--ink);line-height:1.5;min-width:0}.review-value[data-astro-cid-inbwlv3e] strong[data-astro-cid-inbwlv3e]{font-family:var(--font-display);font-weight:600}.review-pill[data-astro-cid-inbwlv3e]{display:inline-block;padding:3px 10px;border-radius:999px;background:var(--blue-tint);color:var(--blue);font-size:12px;font-weight:500}.review-edit[data-astro-cid-inbwlv3e]{-webkit-appearance:none;-moz-appearance:none;appearance:none;border:0;background:transparent;color:var(--blue);font-weight:600;font-size:12.5px;cursor:pointer;padding:4px 8px;border-radius:6px}.review-edit[data-astro-cid-inbwlv3e]:hover{background:var(--blue-tint)}@media (max-width: 600px){.review-row[data-astro-cid-inbwlv3e]{grid-template-columns:1fr auto;row-gap:8px;padding:14px 16px}.review-label[data-astro-cid-inbwlv3e]{grid-column:1;padding-top:0;font-size:10px}.review-edit[data-astro-cid-inbwlv3e]{grid-column:2;grid-row:1;padding:2px 8px;font-size:12px}.review-value[data-astro-cid-inbwlv3e]{grid-column:1 / -1;font-size:14px}.review-pill[data-astro-cid-inbwlv3e]{font-size:11px;padding:2px 8px}.vol-role-grid[data-astro-cid-inbwlv3e]{grid-template-columns:1fr;gap:8px}.vol-role[data-astro-cid-inbwlv3e]{padding:14px 16px}}
</style>
STSHEAD;
require $STS_ROOT.'/inc/head.php';
?>
<section class="section page-header" data-screen-label="Page header"> <div class="section-inner"> <span class="eyebrow reveal">Volunteer · 5 min intake</span> <h1 class="page-title reveal">Volunteer with <span class="accent">Street-To-Stardom</span>.</h1> <p class="page-lede reveal">Most volunteers attach to one program and one weekend rhythm. This intake takes about 5 minutes. We come back to you within 2 business days.</p>  </div> </section> <section class="section form-shell" data-screen-label="Volunteer · Form"> <div class="section-inner"> <div x-data="volunteerForm()" class="form-grid" data-astro-cid-inbwlv3e> <div class="form-card" data-astro-cid-inbwlv3e> <template x-if="!submitted" data-astro-cid-inbwlv3e> <div data-astro-cid-inbwlv3e> <div class="form-stepper-label" data-astro-cid-inbwlv3e> <span data-astro-cid-inbwlv3e>Step 0<span x-text="step + 1" data-astro-cid-inbwlv3e></span> / 05</span> <strong x-text="stepLabels[step]" data-astro-cid-inbwlv3e></strong> </div> <div class="form-stepper" data-astro-cid-inbwlv3e> <template x-for="i in [0,1,2,3,4]" :key="i" data-astro-cid-inbwlv3e> <span class="step" :class="{ 'done': i < step, 'on': i === step }" data-astro-cid-inbwlv3e></span> </template> </div> <!-- Step 0: Role --> <div x-show="step === 0" x-transition.opacity x-cloak data-astro-cid-inbwlv3e> <div class="form-step-title" data-astro-cid-inbwlv3e>Where do you see yourself helping?</div> <p class="form-step-sub" data-astro-cid-inbwlv3e>Pick the closest fit; you can adjust skills next.</p> <div class="vol-role-grid" data-astro-cid-inbwlv3e> <button type="button" @click="role = 'instructor'" class="vol-role" :class="role === 'instructor' ? 'on' : ''" data-astro-cid-inbwlv3e> <div class="vol-role-name" data-astro-cid-inbwlv3e>Instructor</div> <div class="vol-role-desc" data-astro-cid-inbwlv3e>Teach a session or co-teach with a lead facilitator.</div> </button><button type="button" @click="role = 'mentor'" class="vol-role" :class="role === 'mentor' ? 'on' : ''" data-astro-cid-inbwlv3e> <div class="vol-role-name" data-astro-cid-inbwlv3e>Mentor</div> <div class="vol-role-desc" data-astro-cid-inbwlv3e>1:1 mentorship for STREET Storm or NextGen members.</div> </button><button type="button" @click="role = 'fundraiser'" class="vol-role" :class="role === 'fundraiser' ? 'on' : ''" data-astro-cid-inbwlv3e> <div class="vol-role-name" data-astro-cid-inbwlv3e>Fundraiser</div> <div class="vol-role-desc" data-astro-cid-inbwlv3e>Sponsor pipeline, corporate intros, donor stewardship.</div> </button><button type="button" @click="role = 'creator'" class="vol-role" :class="role === 'creator' ? 'on' : ''" data-astro-cid-inbwlv3e> <div class="vol-role-name" data-astro-cid-inbwlv3e>Content Creator</div> <div class="vol-role-desc" data-astro-cid-inbwlv3e>Photography, video, writing, social — consent-trained.</div> </button><button type="button" @click="role = 'admin'" class="vol-role" :class="role === 'admin' ? 'on' : ''" data-astro-cid-inbwlv3e> <div class="vol-role-name" data-astro-cid-inbwlv3e>Administrator</div> <div class="vol-role-desc" data-astro-cid-inbwlv3e>Logistics, scheduling, back-office, records.</div> </button> </div> <div class="form-field" data-astro-cid-inbwlv3e> <label class="form-label" data-astro-cid-inbwlv3e>
What draws you to this role? <span class="opt" data-astro-cid-inbwlv3e>optional · 1–2 lines</span> </label> <textarea class="form-textarea" placeholder="A short note — we use this to pre-select likely skills in the next step." x-model="why" maxlength="800" data-astro-cid-inbwlv3e></textarea> <p class="form-help" data-astro-cid-inbwlv3e>If you write a sentence, our intake assistant uses it to highlight relevant skill chips next. You can adjust them either way.</p> </div> </div> <!-- Step 1: Skills --> <div x-show="step === 1" x-transition.opacity x-cloak data-astro-cid-inbwlv3e> <div class="form-step-title" data-astro-cid-inbwlv3e>Confirm your skills.</div> <p class="form-step-sub" data-astro-cid-inbwlv3e>Tap to toggle. Suggestions are starting points — adjust freely.</p> <div x-show="aiSuggestions.length > 0 && !aiLoading" x-transition.opacity class="ai-banner" x-cloak data-astro-cid-inbwlv3e> <span class="spark" data-astro-cid-inbwlv3e>✦</span> <div data-astro-cid-inbwlv3e> <strong data-astro-cid-inbwlv3e>Pre-selected from your role and note.</strong> Highlighted <span x-text="aiSuggestions.length" data-astro-cid-inbwlv3e></span> skill<span x-text="aiSuggestions.length === 1 ? '' : 's'" data-astro-cid-inbwlv3e></span> below. Adjust as needed.
</div> </div> <div x-show="aiLoading" x-transition.opacity class="ai-banner" x-cloak data-astro-cid-inbwlv3e> <span class="spark" data-astro-cid-inbwlv3e>✦</span> <div data-astro-cid-inbwlv3e> <strong data-astro-cid-inbwlv3e>Reviewing your note<span class="ai-typing" data-astro-cid-inbwlv3e></span></strong> <div style="margin-top: 6px; display: flex; gap: 6px;" data-astro-cid-inbwlv3e> <span class="skeleton" style="width: 60px;" data-astro-cid-inbwlv3e></span> <span class="skeleton" style="width: 90px;" data-astro-cid-inbwlv3e></span> <span class="skeleton" style="width: 70px;" data-astro-cid-inbwlv3e></span> </div> </div> </div> <div class="chip-grid" data-astro-cid-inbwlv3e> <button type="button" @click="toggleSkill('Teaching')" class="chip" :class="{ 'on': skills.includes('Teaching'), 'ai-suggested': aiSuggestions.includes('Teaching'), 'chip-just-suggested': aiSuggestions.includes('Teaching') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Teaching') ? ('--i:' + aiSuggestions.indexOf('Teaching')) : ''" data-astro-cid-inbwlv3e>Teaching</button><button type="button" @click="toggleSkill('Curriculum design')" class="chip" :class="{ 'on': skills.includes('Curriculum design'), 'ai-suggested': aiSuggestions.includes('Curriculum design'), 'chip-just-suggested': aiSuggestions.includes('Curriculum design') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Curriculum design') ? ('--i:' + aiSuggestions.indexOf('Curriculum design')) : ''" data-astro-cid-inbwlv3e>Curriculum design</button><button type="button" @click="toggleSkill('Mentoring')" class="chip" :class="{ 'on': skills.includes('Mentoring'), 'ai-suggested': aiSuggestions.includes('Mentoring'), 'chip-just-suggested': aiSuggestions.includes('Mentoring') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Mentoring') ? ('--i:' + aiSuggestions.indexOf('Mentoring')) : ''" data-astro-cid-inbwlv3e>Mentoring</button><button type="button" @click="toggleSkill('Photography')" class="chip" :class="{ 'on': skills.includes('Photography'), 'ai-suggested': aiSuggestions.includes('Photography'), 'chip-just-suggested': aiSuggestions.includes('Photography') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Photography') ? ('--i:' + aiSuggestions.indexOf('Photography')) : ''" data-astro-cid-inbwlv3e>Photography</button><button type="button" @click="toggleSkill('Videography')" class="chip" :class="{ 'on': skills.includes('Videography'), 'ai-suggested': aiSuggestions.includes('Videography'), 'chip-just-suggested': aiSuggestions.includes('Videography') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Videography') ? ('--i:' + aiSuggestions.indexOf('Videography')) : ''" data-astro-cid-inbwlv3e>Videography</button><button type="button" @click="toggleSkill('Copywriting')" class="chip" :class="{ 'on': skills.includes('Copywriting'), 'ai-suggested': aiSuggestions.includes('Copywriting'), 'chip-just-suggested': aiSuggestions.includes('Copywriting') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Copywriting') ? ('--i:' + aiSuggestions.indexOf('Copywriting')) : ''" data-astro-cid-inbwlv3e>Copywriting</button><button type="button" @click="toggleSkill('Editing')" class="chip" :class="{ 'on': skills.includes('Editing'), 'ai-suggested': aiSuggestions.includes('Editing'), 'chip-just-suggested': aiSuggestions.includes('Editing') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Editing') ? ('--i:' + aiSuggestions.indexOf('Editing')) : ''" data-astro-cid-inbwlv3e>Editing</button><button type="button" @click="toggleSkill('Graphic design')" class="chip" :class="{ 'on': skills.includes('Graphic design'), 'ai-suggested': aiSuggestions.includes('Graphic design'), 'chip-just-suggested': aiSuggestions.includes('Graphic design') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Graphic design') ? ('--i:' + aiSuggestions.indexOf('Graphic design')) : ''" data-astro-cid-inbwlv3e>Graphic design</button><button type="button" @click="toggleSkill('Web / IT')" class="chip" :class="{ 'on': skills.includes('Web / IT'), 'ai-suggested': aiSuggestions.includes('Web / IT'), 'chip-just-suggested': aiSuggestions.includes('Web / IT') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Web / IT') ? ('--i:' + aiSuggestions.indexOf('Web / IT')) : ''" data-astro-cid-inbwlv3e>Web / IT</button><button type="button" @click="toggleSkill('Data analysis')" class="chip" :class="{ 'on': skills.includes('Data analysis'), 'ai-suggested': aiSuggestions.includes('Data analysis'), 'chip-just-suggested': aiSuggestions.includes('Data analysis') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Data analysis') ? ('--i:' + aiSuggestions.indexOf('Data analysis')) : ''" data-astro-cid-inbwlv3e>Data analysis</button><button type="button" @click="toggleSkill('Accounting')" class="chip" :class="{ 'on': skills.includes('Accounting'), 'ai-suggested': aiSuggestions.includes('Accounting'), 'chip-just-suggested': aiSuggestions.includes('Accounting') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Accounting') ? ('--i:' + aiSuggestions.indexOf('Accounting')) : ''" data-astro-cid-inbwlv3e>Accounting</button><button type="button" @click="toggleSkill('Project management')" class="chip" :class="{ 'on': skills.includes('Project management'), 'ai-suggested': aiSuggestions.includes('Project management'), 'chip-just-suggested': aiSuggestions.includes('Project management') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Project management') ? ('--i:' + aiSuggestions.indexOf('Project management')) : ''" data-astro-cid-inbwlv3e>Project management</button><button type="button" @click="toggleSkill('Logistics')" class="chip" :class="{ 'on': skills.includes('Logistics'), 'ai-suggested': aiSuggestions.includes('Logistics'), 'chip-just-suggested': aiSuggestions.includes('Logistics') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Logistics') ? ('--i:' + aiSuggestions.indexOf('Logistics')) : ''" data-astro-cid-inbwlv3e>Logistics</button><button type="button" @click="toggleSkill('Public speaking')" class="chip" :class="{ 'on': skills.includes('Public speaking'), 'ai-suggested': aiSuggestions.includes('Public speaking'), 'chip-just-suggested': aiSuggestions.includes('Public speaking') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Public speaking') ? ('--i:' + aiSuggestions.indexOf('Public speaking')) : ''" data-astro-cid-inbwlv3e>Public speaking</button><button type="button" @click="toggleSkill('Fundraising')" class="chip" :class="{ 'on': skills.includes('Fundraising'), 'ai-suggested': aiSuggestions.includes('Fundraising'), 'chip-just-suggested': aiSuggestions.includes('Fundraising') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Fundraising') ? ('--i:' + aiSuggestions.indexOf('Fundraising')) : ''" data-astro-cid-inbwlv3e>Fundraising</button><button type="button" @click="toggleSkill('Grant writing')" class="chip" :class="{ 'on': skills.includes('Grant writing'), 'ai-suggested': aiSuggestions.includes('Grant writing'), 'chip-just-suggested': aiSuggestions.includes('Grant writing') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Grant writing') ? ('--i:' + aiSuggestions.indexOf('Grant writing')) : ''" data-astro-cid-inbwlv3e>Grant writing</button><button type="button" @click="toggleSkill('Social media')" class="chip" :class="{ 'on': skills.includes('Social media'), 'ai-suggested': aiSuggestions.includes('Social media'), 'chip-just-suggested': aiSuggestions.includes('Social media') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Social media') ? ('--i:' + aiSuggestions.indexOf('Social media')) : ''" data-astro-cid-inbwlv3e>Social media</button><button type="button" @click="toggleSkill('Community organising')" class="chip" :class="{ 'on': skills.includes('Community organising'), 'ai-suggested': aiSuggestions.includes('Community organising'), 'chip-just-suggested': aiSuggestions.includes('Community organising') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Community organising') ? ('--i:' + aiSuggestions.indexOf('Community organising')) : ''" data-astro-cid-inbwlv3e>Community organising</button><button type="button" @click="toggleSkill('Counselling')" class="chip" :class="{ 'on': skills.includes('Counselling'), 'ai-suggested': aiSuggestions.includes('Counselling'), 'chip-just-suggested': aiSuggestions.includes('Counselling') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Counselling') ? ('--i:' + aiSuggestions.indexOf('Counselling')) : ''" data-astro-cid-inbwlv3e>Counselling</button><button type="button" @click="toggleSkill('Safeguarding')" class="chip" :class="{ 'on': skills.includes('Safeguarding'), 'ai-suggested': aiSuggestions.includes('Safeguarding'), 'chip-just-suggested': aiSuggestions.includes('Safeguarding') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Safeguarding') ? ('--i:' + aiSuggestions.indexOf('Safeguarding')) : ''" data-astro-cid-inbwlv3e>Safeguarding</button><button type="button" @click="toggleSkill('Translation (Yoruba)')" class="chip" :class="{ 'on': skills.includes('Translation (Yoruba)'), 'ai-suggested': aiSuggestions.includes('Translation (Yoruba)'), 'chip-just-suggested': aiSuggestions.includes('Translation (Yoruba)') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Translation (Yoruba)') ? ('--i:' + aiSuggestions.indexOf('Translation (Yoruba)')) : ''" data-astro-cid-inbwlv3e>Translation (Yoruba)</button><button type="button" @click="toggleSkill('Translation (Igbo)')" class="chip" :class="{ 'on': skills.includes('Translation (Igbo)'), 'ai-suggested': aiSuggestions.includes('Translation (Igbo)'), 'chip-just-suggested': aiSuggestions.includes('Translation (Igbo)') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Translation (Igbo)') ? ('--i:' + aiSuggestions.indexOf('Translation (Igbo)')) : ''" data-astro-cid-inbwlv3e>Translation (Igbo)</button><button type="button" @click="toggleSkill('Translation (Hausa)')" class="chip" :class="{ 'on': skills.includes('Translation (Hausa)'), 'ai-suggested': aiSuggestions.includes('Translation (Hausa)'), 'chip-just-suggested': aiSuggestions.includes('Translation (Hausa)') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Translation (Hausa)') ? ('--i:' + aiSuggestions.indexOf('Translation (Hausa)')) : ''" data-astro-cid-inbwlv3e>Translation (Hausa)</button><button type="button" @click="toggleSkill('Music')" class="chip" :class="{ 'on': skills.includes('Music'), 'ai-suggested': aiSuggestions.includes('Music'), 'chip-just-suggested': aiSuggestions.includes('Music') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Music') ? ('--i:' + aiSuggestions.indexOf('Music')) : ''" data-astro-cid-inbwlv3e>Music</button><button type="button" @click="toggleSkill('Art')" class="chip" :class="{ 'on': skills.includes('Art'), 'ai-suggested': aiSuggestions.includes('Art'), 'chip-just-suggested': aiSuggestions.includes('Art') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Art') ? ('--i:' + aiSuggestions.indexOf('Art')) : ''" data-astro-cid-inbwlv3e>Art</button><button type="button" @click="toggleSkill('STEM coaching')" class="chip" :class="{ 'on': skills.includes('STEM coaching'), 'ai-suggested': aiSuggestions.includes('STEM coaching'), 'chip-just-suggested': aiSuggestions.includes('STEM coaching') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('STEM coaching') ? ('--i:' + aiSuggestions.indexOf('STEM coaching')) : ''" data-astro-cid-inbwlv3e>STEM coaching</button><button type="button" @click="toggleSkill('Sports coaching')" class="chip" :class="{ 'on': skills.includes('Sports coaching'), 'ai-suggested': aiSuggestions.includes('Sports coaching'), 'chip-just-suggested': aiSuggestions.includes('Sports coaching') &#38;&#38; aiJustReturned }" :style="aiSuggestions.includes('Sports coaching') ? ('--i:' + aiSuggestions.indexOf('Sports coaching')) : ''" data-astro-cid-inbwlv3e>Sports coaching</button> </div> <p class="form-help" style="margin-top: 24px;" data-astro-cid-inbwlv3e>
Selected: <strong style="color: var(--ink);" x-text="skills.length" data-astro-cid-inbwlv3e></strong> skill<span x-text="skills.length === 1 ? '' : 's'" data-astro-cid-inbwlv3e></span> — there's no minimum or maximum.
</p> </div> <!-- Step 2: Availability --> <div x-show="step === 2" x-transition.opacity x-cloak data-astro-cid-inbwlv3e> <div class="form-step-title" data-astro-cid-inbwlv3e>Pick a session to shadow.</div> <p class="form-step-sub" data-astro-cid-inbwlv3e>Live capacity, pulled from our program calendar. Volunteers commit to two shadow sessions before onboarding to a regular cohort.</p> <div class="cal" x-show="!sessionsLoading && sessions.length > 0" data-astro-cid-inbwlv3e> <template x-for="(s, i) in sessions" :key="i" data-astro-cid-inbwlv3e> <div class="cal-slot" :class="{ 'on': slot === i && (s.cap.total - s.cap.taken) > 0, 'full': (s.cap.total - s.cap.taken) <= 0 }" @click="if ((s.cap.total - s.cap.taken) > 0) slot = i" data-astro-cid-inbwlv3e> <div class="cal-date" data-astro-cid-inbwlv3e> <strong x-text="s.date.split(' ')[1]" data-astro-cid-inbwlv3e></strong> <span x-text="s.date.split(' ')[0] + ' ' + s.month" data-astro-cid-inbwlv3e></span> </div> <div class="cal-meta" data-astro-cid-inbwlv3e> <span x-text="s.program" data-astro-cid-inbwlv3e></span> <small x-text="s.venue" data-astro-cid-inbwlv3e></small> </div> <div class="cal-cap" data-astro-cid-inbwlv3e> <strong x-text="(s.cap.total - s.cap.taken) <= 0 ? 'Full' : ((s.cap.total - s.cap.taken) + ' left')" data-astro-cid-inbwlv3e></strong>
of <span x-text="s.cap.total" data-astro-cid-inbwlv3e></span> </div> <div class="cal-check" aria-hidden="true" x-text="slot === i && (s.cap.total - s.cap.taken) > 0 ? '✓' : ''" data-astro-cid-inbwlv3e></div> </div> </template> </div> <div x-show="sessionsLoading" style="padding: 24px; color: var(--muted);" data-astro-cid-inbwlv3e>Loading available sessions…</div> </div> <!-- Step 3: Contact --> <div x-show="step === 3" x-transition.opacity x-cloak data-astro-cid-inbwlv3e> <div class="form-step-title" data-astro-cid-inbwlv3e>How do we reach you?</div> <p class="form-step-sub" data-astro-cid-inbwlv3e>Collected last, after the intake feels mutual.</p> <div class="form-field" data-astro-cid-inbwlv3e> <label class="form-label" for="vol-name" data-astro-cid-inbwlv3e>Full name</label> <input id="vol-name" class="form-input" type="text" x-model="contact.name" placeholder="As you'd like us to address you" required data-astro-cid-inbwlv3e> </div> <div class="form-field" data-astro-cid-inbwlv3e> <label class="form-label" for="vol-email" data-astro-cid-inbwlv3e>Email</label> <input id="vol-email" class="form-input" type="email" x-model="contact.email" placeholder="you@example.org" required :class="{ 'invalid': emailInvalid }" @blur="validateEmail()" data-astro-cid-inbwlv3e> <p class="form-help" x-show="emailInvalid" x-cloak style="color: var(--crimson);" data-astro-cid-inbwlv3e>Please enter a valid email address.</p> </div> <div class="form-field" data-astro-cid-inbwlv3e> <label class="form-label" for="vol-phone" data-astro-cid-inbwlv3e>WhatsApp / phone <span class="opt" data-astro-cid-inbwlv3e>optional</span></label> <input id="vol-phone" class="form-input" type="tel" x-model="contact.phone" placeholder="+234 …" data-astro-cid-inbwlv3e> </div> <p class="form-help" style="margin-top: 16px;" data-astro-cid-inbwlv3e>
We use your contact details for one purpose: getting back to you about volunteering. Never shared. Removable on request.
</p> </div> <!-- Step 4: Review --> <div x-show="step === 4" x-transition.opacity x-cloak data-astro-cid-inbwlv3e> <div class="form-step-title" data-astro-cid-inbwlv3e>Quick review.</div> <p class="form-step-sub" data-astro-cid-inbwlv3e>This is what reaches our volunteer team. Anything to change? Step back and edit.</p> <div class="review-grid" data-astro-cid-inbwlv3e> <div class="review-row" data-astro-cid-inbwlv3e> <div class="review-label" data-astro-cid-inbwlv3e>Role</div> <div class="review-value" x-text="roleLabel() || '—'" data-astro-cid-inbwlv3e></div> <button type="button" class="review-edit" @click="step = 0" data-astro-cid-inbwlv3e>Edit</button> </div> <div class="review-row" data-astro-cid-inbwlv3e> <div class="review-label" data-astro-cid-inbwlv3e>Skills · <span x-text="skills.length" data-astro-cid-inbwlv3e></span></div> <div class="review-value" data-astro-cid-inbwlv3e> <template x-if="skills.length > 0" data-astro-cid-inbwlv3e> <div style="display: flex; flex-wrap: wrap; gap: 6px;" data-astro-cid-inbwlv3e> <template x-for="s in skills" :key="s" data-astro-cid-inbwlv3e> <span class="review-pill" x-text="s" data-astro-cid-inbwlv3e></span> </template> </div> </template> <template x-if="skills.length === 0" data-astro-cid-inbwlv3e><span style="color: var(--muted);" data-astro-cid-inbwlv3e>None selected</span></template> </div> <button type="button" class="review-edit" @click="step = 1" data-astro-cid-inbwlv3e>Edit</button> </div> <div class="review-row" data-astro-cid-inbwlv3e> <div class="review-label" data-astro-cid-inbwlv3e>Session</div> <div class="review-value" data-astro-cid-inbwlv3e> <template x-if="slot !== null && sessions[slot]" data-astro-cid-inbwlv3e> <span data-astro-cid-inbwlv3e> <strong x-text="sessions[slot].date + ' ' + sessions[slot].month" data-astro-cid-inbwlv3e></strong> <small style="display:block;color:var(--muted);" x-text="sessions[slot].program + ' · ' + sessions[slot].venue" data-astro-cid-inbwlv3e></small> </span> </template> <template x-if="slot === null" data-astro-cid-inbwlv3e><span style="color: var(--muted);" data-astro-cid-inbwlv3e>No session picked</span></template> </div> <button type="button" class="review-edit" @click="step = 2" data-astro-cid-inbwlv3e>Edit</button> </div> <div class="review-row" data-astro-cid-inbwlv3e> <div class="review-label" data-astro-cid-inbwlv3e>Contact</div> <div class="review-value" data-astro-cid-inbwlv3e> <strong x-text="contact.name" data-astro-cid-inbwlv3e></strong> <small style="display:block;color:var(--muted);" x-text="contact.email + (contact.phone ? ' · ' + contact.phone : '')" data-astro-cid-inbwlv3e></small> </div> <button type="button" class="review-edit" @click="step = 3" data-astro-cid-inbwlv3e>Edit</button> </div> </div> </div> <!-- Honeypot --> <input type="text" name="website" tabindex="-1" autocomplete="off" x-model="honeypot" style="position:absolute; left:-9999px;" aria-hidden="true" data-astro-cid-inbwlv3e> <div class="form-actions" data-astro-cid-inbwlv3e> <button type="button" class="form-back" @click="goBack" :disabled="step === 0" :style="{ opacity: step === 0 ? 0.3 : 1, cursor: step === 0 ? 'default' : 'pointer' }" data-astro-cid-inbwlv3e>
← Back
</button> <button type="button" class="btn btn-primary" x-show="step < 4" @click="goNext" :disabled="(step === 0 && !role) || (step === 2 && slot === null) || (step === 3 && (!contact.name || !contact.email)) || aiLoading || sessionsLoading" :style="{ opacity: ((step === 0 && !role) || (step === 2 && slot === null) || (step === 3 && (!contact.name || !contact.email)) || aiLoading || sessionsLoading) ? 0.6 : 1 }" data-astro-cid-inbwlv3e> <span x-show="!aiLoading && !sessionsLoading" data-astro-cid-inbwlv3e>Continue <span class="btn-arrow" data-astro-cid-inbwlv3e>→</span></span> <span x-show="aiLoading" x-cloak data-astro-cid-inbwlv3e>Reading your note…</span> <span x-show="sessionsLoading" x-cloak data-astro-cid-inbwlv3e>Loading sessions…</span> </button> <button type="button" class="btn btn-primary" x-show="step === 4" @click="submit" :disabled="!contact.name || !contact.email || submitting" :style="{ opacity: (!contact.name || !contact.email || submitting) ? 0.5 : 1 }" data-astro-cid-inbwlv3e> <span x-text="submitting ? 'Submitting…' : 'Submit application'" data-astro-cid-inbwlv3e></span> <span class="btn-arrow" x-show="!submitting" data-astro-cid-inbwlv3e>→</span> </button> </div> <p class="form-help" x-show="submitError" x-cloak style="color: var(--crimson); margin-top: 12px;" x-text="submitError" data-astro-cid-inbwlv3e></p> </div> </template> <template x-if="submitted" data-astro-cid-inbwlv3e> <div class="confirm-block slide-up" role="status" aria-live="polite" data-astro-cid-inbwlv3e> <div class="confirm-check" aria-hidden="true" data-astro-cid-inbwlv3e>✓</div> <h2 class="confirm-title" data-astro-cid-inbwlv3e>You're in the pipeline.</h2> <p class="confirm-sub" data-astro-cid-inbwlv3e>We've logged your application as a <span x-text="roleLabel().toLowerCase()" data-astro-cid-inbwlv3e></span> volunteer. Confirmation in your inbox in the next few minutes.</p> <a href="#" class="btn btn-primary" style="margin: 0 auto;" data-astro-cid-inbwlv3e>Book your screening call <span class="btn-arrow" data-astro-cid-inbwlv3e>→</span></a> <dl class="confirm-meta" data-astro-cid-inbwlv3e> <div data-astro-cid-inbwlv3e> <dt data-astro-cid-inbwlv3e>Role</dt> <dd x-text="roleLabel()" data-astro-cid-inbwlv3e></dd> </div> <div data-astro-cid-inbwlv3e> <dt data-astro-cid-inbwlv3e>Next step</dt> <dd data-astro-cid-inbwlv3e>Screening call (15 min)</dd> </div> <div data-astro-cid-inbwlv3e> <dt data-astro-cid-inbwlv3e>Reply by</dt> <dd data-astro-cid-inbwlv3e>Within 2 business days</dd> </div> </dl> </div> </template> </div> <div class="form-side" data-astro-cid-inbwlv3e> <div class="form-side-card" data-astro-cid-inbwlv3e> <h4 data-astro-cid-inbwlv3e>Your application so far</h4> <ul class="form-side-list" data-astro-cid-inbwlv3e> <li :class="role ? 'done' : ''" data-astro-cid-inbwlv3e> <span data-astro-cid-inbwlv3e>Role · <strong style="color: var(--ink);" x-text="roleLabel() || '—'" data-astro-cid-inbwlv3e></strong></span> </li> <li :class="skills.length > 0 ? 'done' : ''" data-astro-cid-inbwlv3e> <span data-astro-cid-inbwlv3e>Skills · <strong style="color: var(--ink);" x-text="skills.length || '—'" data-astro-cid-inbwlv3e></strong> selected</span> </li> <li :class="slot !== null ? 'done' : ''" data-astro-cid-inbwlv3e> <span data-astro-cid-inbwlv3e>Session · <strong style="color: var(--ink);" x-text="slot !== null && sessions[slot] ? sessions[slot].date : '—'" data-astro-cid-inbwlv3e></strong></span> </li> <li :class="contact.email ? 'done' : ''" data-astro-cid-inbwlv3e> <span data-astro-cid-inbwlv3e>Contact · <strong style="color: var(--ink);" x-text="contact.email || '—'" data-astro-cid-inbwlv3e></strong></span> </li> </ul> </div> <div class="form-side-card" data-astro-cid-inbwlv3e> <h4 data-astro-cid-inbwlv3e>What happens next</h4> <p data-astro-cid-inbwlv3e>1. You submit · 2. Named reply within 2 business days · 3. 15-min screening call · 4. Two shadow sessions · 5. Onboarding to a cohort.</p> </div> </div> </div>  <script>(function(){const VOL_ROLES = [{"id":"instructor","label":"Instructor","desc":"Teach a session or co-teach with a lead facilitator."},{"id":"mentor","label":"Mentor","desc":"1:1 mentorship for STREET Storm or NextGen members."},{"id":"fundraiser","label":"Fundraiser","desc":"Sponsor pipeline, corporate intros, donor stewardship."},{"id":"creator","label":"Content Creator","desc":"Photography, video, writing, social — consent-trained."},{"id":"admin","label":"Administrator","desc":"Logistics, scheduling, back-office, records."}];
const VOL_SKILLS = ["Teaching","Curriculum design","Mentoring","Photography","Videography","Copywriting","Editing","Graphic design","Web / IT","Data analysis","Accounting","Project management","Logistics","Public speaking","Fundraising","Grant writing","Social media","Community organising","Counselling","Safeguarding","Translation (Yoruba)","Translation (Igbo)","Translation (Hausa)","Music","Art","STEM coaching","Sports coaching"];

  document.addEventListener('alpine:init', () => {
    // Local fallback session data — replaced by /api/sessions.php if available.
    // `id` is left null on the fallback rows; the API supplies a real one.
    const FALLBACK_SLOTS = [
      { id: null, date: "Sat 23", month: "May 2026", program: "NextGen Genius Club", venue: "Alimosho · Cohort A", cap: { total: 24, taken: 18 } },
      { id: null, date: "Sat 23", month: "May 2026", program: "NextGen Genius Club", venue: "Ikeja · Cohort B", cap: { total: 24, taken: 9 } },
      { id: null, date: "Sun 24", month: "May 2026", program: "LCASP", venue: "Partner school · St. Anthony's", cap: { total: 12, taken: 12 } },
      { id: null, date: "Sat 30", month: "May 2026", program: "Summer School onboarding", venue: "Central · STS office", cap: { total: 30, taken: 14 } },
      { id: null, date: "Sat 06", month: "Jun 2026", program: "STREET Storm intake clinic", venue: "Agege · Community centre", cap: { total: 16, taken: 6 } },
      { id: null, date: "Sat 13", month: "Jun 2026", program: "NextGen Genius Club", venue: "Alimosho · Cohort A", cap: { total: 24, taken: 21 } },
    ];

    const STORAGE_KEY = 'sts.volunteer.draft';
    function loadDraft() {
      try { return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '{}'); } catch { return {}; }
    }
    function saveDraft(d) {
      try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(d)); } catch {}
    }

    window.Alpine.data('volunteerForm', () => {
      const draft = loadDraft();
      return {
        step: 0,
        role: draft.role || null,
        why: draft.why || '',
        skills: draft.skills || [],
        aiSuggestions: [],
        aiLoading: false,
        aiJustReturned: false,
        sessions: FALLBACK_SLOTS,
        sessionsLoading: false,
        slot: draft.slot ?? null,
        contact: draft.contact || { name: '', email: '', phone: '' },
        honeypot: '',
        submitting: false,
        submitted: false,
        submitError: '',
        emailInvalid: false,
        stepLabels: ["Role", "Skills", "Availability", "Contact", "Review"],

        roleLabel() {
          const r = VOL_ROLES.find(r => r.id === this.role);
          return r ? r.label : '';
        },
        toggleSkill(s) {
          if (this.skills.includes(s)) this.skills = this.skills.filter(x => x !== s);
          else this.skills = [...this.skills, s];
          this.persist();
        },
        validateEmail() {
          this.emailInvalid = this.contact.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(this.contact.email);
        },
        persist() {
          saveDraft({ role: this.role, why: this.why, skills: this.skills, slot: this.slot, contact: this.contact });
        },
        async fetchSessions() {
          this.sessionsLoading = true;
          try {
            const r = await fetch('/api/sessions.php?program=all');
            if (r.ok) {
              const j = await r.json();
              if (Array.isArray(j?.sessions) && j.sessions.length > 0) this.sessions = j.sessions;
            }
          } catch {}
          finally { this.sessionsLoading = false; }
        },
        async fetchAi() {
          if (!this.role) return;
          this.aiLoading = true;
          try {
            const fd = new FormData();
            fd.append('action', 'suggest_skills');
            fd.append('role', this.role);
            fd.append('motivation', this.why);
            const r = await fetch('/api/volunteer.php', { method: 'POST', body: fd, credentials: 'include' });
            if (r.ok) {
              const j = await r.json();
              if (Array.isArray(j?.skills)) {
                // Map to user-facing capitalised skills
                const titleCase = (s) => s.split('_').map(w => w[0].toUpperCase() + w.slice(1)).join(' ');
                const mapped = j.skills.map(s => {
                  const t = titleCase(s);
                  const known = VOL_SKILLS;
                  return known.find(k => k.toLowerCase().startsWith(t.toLowerCase()) || t.toLowerCase().includes(k.toLowerCase().split(' ')[0])) || t;
                }).filter(Boolean);
                this.aiSuggestions = [...new Set(mapped)];
                // Pre-select suggestions if user hasn't manually chosen
                if (this.skills.length === 0) this.skills = [...this.aiSuggestions];
              }
            }
          } catch {}
          finally { this.aiLoading = false; }

          // Mocked fallback if AI returned nothing
          if (this.aiSuggestions.length === 0) {
            const hints = {
              instructor: ["Teaching", "Curriculum design", "Public speaking", "STEM coaching"],
              mentor: ["Mentoring", "Counselling", "Public speaking", "Community organising"],
              fundraiser: ["Fundraising", "Grant writing", "Copywriting", "Project management"],
              creator: ["Photography", "Videography", "Copywriting", "Editing", "Social media"],
              admin: ["Project management", "Logistics", "Accounting", "Data analysis"],
            };
            this.aiSuggestions = hints[this.role] || [];
            if (this.skills.length === 0) this.skills = [...this.aiSuggestions];
          }

          // Trigger the one-shot stagger animation, then clear so re-renders
          // don't replay it.
          this.aiJustReturned = true;
          setTimeout(() => { this.aiJustReturned = false; }, 1200);
        },
        async goNext() {
          if (this.step === 0 && this.role) {
            await this.fetchAi();
          }
          if (this.step === 1) {
            await this.fetchSessions();
          }
          if (this.step === 3) {
            this.validateEmail();
            if (this.emailInvalid) return;
          }
          this.step = Math.min(this.step + 1, 4);
          this.persist();
          // Move focus to the new step's heading for screen-reader continuity.
          requestAnimationFrame(() => {
            const h = document.querySelector('.form-card .form-step-title:not([style*="display: none"])') as HTMLElement | null;
            h?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          });
        },
        goBack() {
          this.step = Math.max(0, this.step - 1);
        },
        async submit() {
          this.validateEmail();
          if (this.emailInvalid) return;
          if (this.honeypot) { this.submitted = true; return; } // silent discard
          this.submitError = '';
          this.submitting = true;
          try {
            const fd = new FormData();
            fd.append('action', 'submit');
            fd.append('csrf_token', document.cookie.match(/sts_csrf=([^;]+)/)?.[1] || '');
            fd.append('full_name', this.contact.name);
            fd.append('email', this.contact.email);
            fd.append('phone', this.contact.phone);
            fd.append('role_applied', this.role);
            fd.append('motivation_text', this.why);
            fd.append('skills', JSON.stringify(this.skills));
            const chosen = this.slot !== null ? this.sessions[this.slot] : null;
            fd.append('session_id', chosen && chosen.id ? String(chosen.id) : '');
            const r = await fetch('/api/volunteer.php', { method: 'POST', body: fd, credentials: 'include' });
            const j = await r.json().catch(() => ({}));
            if (r.ok && j.ok) {
              this.submitted = true;
              try { sessionStorage.removeItem(STORAGE_KEY); } catch {}
              window.stsToast?.('Application received — we will reply within 2 business days.', 'success');
            } else {
              this.submitError = j.error || 'Something went wrong. Please try again.';
              window.stsToast?.(this.submitError, 'error');
            }
          } catch (e) {
            this.submitError = 'Network error. Please try again.';
            window.stsToast?.(this.submitError, 'error');
          } finally {
            this.submitting = false;
          }
        },
        init() {
          this.$watch('why', () => this.persist());
          this.$watch('role', () => this.persist());
          this.$watch('contact', () => this.persist());
        }
      };
    });
  });
})();</script> </div> </section>
<?php
require $STS_ROOT.'/inc/footer.php';
