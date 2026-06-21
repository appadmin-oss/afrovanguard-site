<?php
/**
 * db/content.php — Canonical Afrovanguard Diary content.
 * Single source of truth, seeded into the SQLite database by db/seed.php.
 * Edit here, then run:  php db/seed.php --fresh
 */
return [
  [
    'slug' => 'introducing-the-afrovanguard-diary',
    'title' => 'Introducing The Afrovanguard Diary',
    'dek' => 'Why a youth movement raising one million incorruptible leaders is opening its field notebook to the public.',
    'category' => 'Dispatch',
    'category_slug' => 'dispatch',
    'authors_html' => '<a href="https://afrovanguard.org.ng/about/">Babatunde Just-Joe Adeola</a>, The Afrovanguard Team',
    'published' => 'Jun 18, 2026',
    'published_at' => '2026-06-18',
    'read_minutes' => 7,
    'gradient' => 'g-gold',
    'mc_session' => 'DISPATCH 001',
    'mc_title' => 'The<br/>Afrovanguard<br/>Diary',
    'mc_tag' => 'ALIMOSHO · LAGOS · SINCE 2018',
    'base_claps' => 214,
    'featured' => 1,
    'toc' => [['introduction', 'Introduction'], ['why-a-diary', 'Why a diary'], ['what-youll-read', 'What you will read here'], ['how-we-work', 'How we work'], ['get-involved', 'Get involved']],
    'related' => ['school-storm-reaching-30000-children', 'the-math-behind-1-million-leaders', 'rebuilding-summer-school-six-lgas'],
    'body_html' => <<<'AVHTML'
<p class="lead">At Afrovanguard, our mission has never been complicated to state and never been easy to do: raise <strong>one million incorruptible leaders for Africa by 2040</strong>. Since 2018 we have pursued that one sentence out of Alimosho, Lagos — through classrooms, code labs, creative stages, and a great deal of trial and error.</p>
        <p>Today we are opening the notebook. <strong>The Afrovanguard Diary</strong> is where we publish what we are actually learning as we build the movement — the programmes that worked, the ones that did not, the numbers behind them, and the people in the middle of it all.</p>
        <h2 id="introduction">Introduction</h2>
        <p>Afrovanguard is a Nigerian non-profit — Ambassadors for Community, Tech &amp; Cultural Advancements. We work where young people are: under-resourced communities across the six wards of Alimosho and, increasingly, beyond it. Over the last eight years we have reached more than <strong>5,000 lives</strong> directly through mentorship, technology training, the creative arts, and faith-driven character formation.</p>
        <p>Programmes like <a href="https://cacentre.afrovanguard.org.ng/street-to-stardom/">Street-To-Stardom</a>, <a href="https://next.afrovanguard.org.ng/">Next Generation Genius</a>, <a href="https://cacentre.afrovanguard.org.ng/techhome/">Techome</a>, MediaPro and Africa GATES are not abstractions to us — they are weekends, attendance registers, and the specific names of specific children. The Diary is our attempt to write that reality down honestly.</p>
        <div class="media-card g-gold g-grain" data-reveal>
          <span class="mc-session">DISPATCH 001</span>
          <h3 class="mc-title">One sentence,<br/>one million leaders</h3>
          <span class="mc-tag">RAISING INCORRUPTIBLE LEADERS BY 2040</span>
          <span class="mc-play"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>
        </div>
        <h2 id="why-a-diary">Why a diary</h2>
        <p>Most organisations publish outcomes. We want to publish the working — the assumptions we tested, the things that surprised us, and the decisions we changed our minds about. We believe the most useful thing a youth movement can offer other builders is not a highlight reel but an honest record.</p>
        <p>There is a second reason. Incorruptibility is not only a promise we ask of young leaders; it is a standard we have to hold ourselves to. Writing in the open — about budgets, attendance, what we got wrong — is one practical way to stay accountable to the families who trust us with their children.</p>
        <blockquote>If we are serious about leaders who cannot be bought, broken, or bent, we have to be willing to show our own work.<cite>— Babatunde Just-Joe Adeola, Chief Servant</cite></blockquote>
        <h2 id="what-youll-read">What you will read here</h2>
        <p>The Diary collects a vertically integrated set of stories from across the movement, designed to be useful to three audiences at once:</p>
        <ul>
          <li><strong>Field notes:</strong> What actually happened in a cohort, a centre, or a campaign — the texture, not just the totals.</li>
          <li><strong>Methodology:</strong> How we measure impact, track cohorts, and decide what to scale and what to stop.</li>
          <li><strong>Mission &amp; movement:</strong> The thinking behind the goal of one million leaders, and the values that hold it together.</li>
        </ul>
        <p>Some entries will be celebratory. Many will be plainly about problems — a girls'-attendance gap, a programme that under-delivered, a hire we made three years too late. That mix is the point.</p>
        <h2 id="how-we-work">How we work</h2>
        <p>Every diary entry is edited internally before publication and never written by committee. We publish roughly twice a month. Where we cite numbers, we mean the numbers we can defend; where we are still unsure, we say so. And because not everyone reads the same way, every entry can be <strong>listened to</strong> — press play at the top of any article and the Diary will read it aloud, highlighting each paragraph as it goes.</p>
        <div class="callout"><strong>A note on the reader.</strong> The audio is generated in your own browser from the text on the page — no upload, no tracking. You can change the text size, switch to dark mode, save an entry for later, or pick up exactly where you left off. Your preferences live on your device.</div>
        <h2 id="get-involved">Get involved</h2>
        <p>If the Diary is useful to you, the best thing you can do is join the work it describes. You can <a href="https://cacentre.afrovanguard.org.ng/volunteer">volunteer with a programme</a>, <a href="https://afrovanguard.org.ng/donate.html">fund a leader</a>, or simply forward an entry to someone building something similar. Every article has our inbox at the bottom, and we read every reply.</p>
        <p>Welcome to the notebook. Let's build leaders Africa cannot buy.</p>
AVHTML,
  ],
  [
    'slug' => 'school-storm-reaching-30000-children',
    'title' => 'What it takes to reach 30,000 children: notes from School Storm',
    'dek' => 'Behind the logistics of our largest single campaign — and why we measure presence before we measure impact.',
    'category' => 'Field Notes',
    'category_slug' => 'field-notes',
    'authors_html' => 'Yemi Oloye, Programmes Desk',
    'published' => 'Jun 06, 2026',
    'published_at' => '2026-06-06',
    'read_minutes' => 6,
    'gradient' => 'g-sunset',
    'mc_session' => 'FIELD NOTE',
    'mc_title' => 'School<br/>Storm<br/>2026',
    'mc_tag' => 'ALIMOSHO LGA · 3 MONTHS · 20 VOLUNTEERS',
    'base_claps' => 167,
    'featured' => 0,
    'toc' => [['the-number', 'The number'], ['logistics', 'The logistics nobody sees'], ['what-we-track', 'What we actually track'], ['what-changed', 'What we changed for 2026']],
    'related' => ['introducing-the-afrovanguard-diary', 'rebuilding-summer-school-six-lgas', 'the-math-behind-1-million-leaders'],
    'body_html' => <<<'AVHTML'
<p class="lead">School Storm is the widest net we cast all year. The headline figure — <strong>30,000 children across Alimosho LGA</strong> — is easy to print and very hard to earn. These are the notes from earning it.</p>
        <h2 id="the-number">The number</h2>
        <p>School Storm 2026 ran as a three-month campaign beginning in late April, staffed by a core team of around twenty volunteers moving between schools across Alimosho. The ambition is reach: get in front of as many children as possible with a consistent message about character, possibility, and the habits that compound into leadership.</p>
        <p>Thirty thousand is not a vanity figure for us. Alimosho is one of the most densely populated local governments in Lagos, and the children inside it are exactly the young people our 2040 goal is about. But a number that large only means something if we are honest about what it does — and does not — represent.</p>
        <h2 id="logistics">The logistics nobody sees</h2>
        <p>Most of School Storm is not inspiration; it is coordination. A single week can involve:</p>
        <ul>
          <li><strong>Access:</strong> Securing permission from school administrators, many of whom have been disappointed by one-off "motivational" visits before.</li>
          <li><strong>Sequencing:</strong> Routing a small volunteer team through multiple schools without burning them out by week three.</li>
          <li><strong>Consistency:</strong> Making sure the child in the first school and the child in the thirtieth hear the same core message, delivered with the same care.</li>
        </ul>
        <p>The unglamorous truth is that the campaign lives or dies on the calendar and the relationships behind it, not on any single talk.</p>
        <blockquote>Reach is the easy half. The hard half is making sure reach was not the same thing as noise.</blockquote>
        <h2 id="what-we-track">What we actually track</h2>
        <p>We have learned to separate two things we used to blur together: <strong>presence</strong> and <strong>impact</strong>. Presence is how many children we stood in front of, and whether the encounter was coherent. Impact is whether anything changed afterwards — and that can only be measured in the smaller, deeper programmes children step into next.</p>
        <p>So School Storm's honest job is to be the top of a funnel. Its success metric is not "30,000 inspired" — a claim we could never defend — but how many of those children we can name a next step for: a club, a summer school seat, a mentor. Presence first, then a door.</p>
        <h2 id="what-changed">What we changed for 2026</h2>
        <p>After previous editions we made three deliberate changes. We tightened the core message so every volunteer could deliver it well. We built a simple hand-off so interested children were pointed toward <a href="https://next.afrovanguard.org.ng/">Next Generation Genius</a> and the Alimosho Summer School rather than left at the inspiration stage. And we started counting follow-through, not just attendance.</p>
        <div class="callout"><strong>The open question.</strong> We still do not have a clean way to measure how many School Storm children convert into sustained programmes a year later. Building that tracking is on the Diary's agenda — and we will publish it, working included.</div>
        <p>If you want to help us turn reach into follow-through, the most useful thing you can give is time. <a href="https://cacentre.afrovanguard.org.ng/volunteer">Volunteering</a> with a single cohort does more than a campaign ever can.</p>
AVHTML,
  ],
  [
    'slug' => 'the-math-behind-1-million-leaders',
    'title' => 'The math behind one million incorruptible leaders by 2040',
    'dek' => 'A goal that large is either a slogan or a system. Here is how we make ours a system.',
    'category' => 'Mission',
    'category_slug' => 'mission',
    'authors_html' => 'The Afrovanguard Team',
    'published' => 'May 24, 2026',
    'published_at' => '2026-05-24',
    'read_minutes' => 8,
    'gradient' => 'g-ink',
    'mc_session' => 'MISSION',
    'mc_title' => 'One<br/>Million<br/>by 2040',
    'mc_tag' => 'A GOAL THAT HAS TO BE A SYSTEM',
    'base_claps' => 301,
    'featured' => 0,
    'toc' => [['the-slogan-problem', 'The slogan problem'], ['definition', 'Defining the unit'], ['the-funnel', 'The funnel'], ['compounding', 'Why we believe in compounding'], ['honesty', 'Where the math is fragile']],
    'related' => ['introducing-the-afrovanguard-diary', 'school-storm-reaching-30000-children', 'what-techome-taught-us'],
    'body_html' => <<<'AVHTML'
<p class="lead">"One million incorruptible leaders for Africa by 2040" is the kind of sentence that can inspire a room and mean nothing by Monday. We have spent years trying to make sure ours survives the Monday.</p>
        <h2 id="the-slogan-problem">The slogan problem</h2>
        <p>Big numbers are dangerous for non-profits. They attract attention and then quietly become decoration — a figure on a banner that no internal decision actually depends on. If a goal does not change what you do this quarter, it is a slogan, not a target.</p>
        <p>So the first thing we did with "one million" was refuse to treat it as marketing. We treat it as a constraint that has to reach all the way down to a single Saturday cohort.</p>
        <h2 id="definition">Defining the unit</h2>
        <p>You cannot count what you have not defined. For us a "leader" raised by Afrovanguard is not anyone we briefly met. It is a young person who has gone through sustained formation — a programme measured in months, not minutes — and who carries the three things the word <strong>incorruptible</strong> implies: they cannot easily be bought, broken, or bent.</p>
        <p>That definition is deliberately expensive. It rules out counting the 30,000 children of a <a href="/diary/school-storm-reaching-30000-children/">School Storm</a> campaign as a million-in-the-making. Reach is the funnel's mouth; leaders are formed much further down.</p>
        <blockquote>If your unit of impact is cheap to count, your number will be large and meaningless. We chose an expensive unit on purpose.</blockquote>
        <h2 id="the-funnel">The funnel</h2>
        <p>Once the unit is defined, the goal becomes a pipeline problem. Each layer is narrower and deeper than the one above it:</p>
        <ul>
          <li><strong>Reach</strong> — broad campaigns like School Storm that put possibility in front of tens of thousands.</li>
          <li><strong>Engagement</strong> — clubs and short courses such as Next Generation Genius and the Alimosho Summer School.</li>
          <li><strong>Formation</strong> — sustained programmes like Techome, MediaPro, Africa GATES and Street-To-Stardom, where character and capability are built over time.</li>
          <li><strong>Multiplication</strong> — graduates who go on to lead cohorts of their own.</li>
        </ul>
        <p>One million is only reachable if the bottom layer — multiplication — works. We are not trying to personally teach a million children. We are trying to form leaders who form others.</p>
        <h2 id="compounding">Why we believe in compounding</h2>
        <p>The arithmetic that makes 2040 plausible is not addition; it is compounding. A leader who returns to run a cohort turns one formed person into a recurring source of formed people. That is the entire bet. It is also why we invest so heavily in the smaller, slower programmes that produce people willing to come back.</p>
        <h2 id="honesty">Where the math is fragile</h2>
        <p>We owe the Diary honesty about the weak joints. Two of them keep us up at night. First, <strong>conversion between layers is still poorly measured</strong> — we know far more about reach than about how many reached children become formed leaders. Second, <strong>multiplication is an assumption, not yet a proven rate</strong>; we have inspiring individual examples but not a defensible average.</p>
        <div class="callout"><strong>What this means.</strong> When you see us obsess over cohort tracking and follow-through in other diary entries, this is why. The credibility of one million by 2040 lives entirely in those unglamorous spreadsheets.</div>
        <p>We would rather tell you the math is fragile and show you the work than print a confident banner. If you want to strengthen the weakest joint, <a href="https://afrovanguard.org.ng/donate.html">funding a leader</a> funds exactly the deep formation the goal depends on.</p>
AVHTML,
  ],
  [
    'slug' => 'rebuilding-summer-school-six-lgas',
    'title' => 'Six wards, six weeks: rebuilding Summer School for 2026',
    'dek' => 'Why we moved the Alimosho Summer School closer to where children actually live — and what it costs to run ten courses at once.',
    'category' => 'Field Notes',
    'category_slug' => 'field-notes',
    'authors_html' => 'Yemi Oloye, Programmes Desk',
    'published' => 'May 10, 2026',
    'published_at' => '2026-05-10',
    'read_minutes' => 6,
    'gradient' => 'g-green',
    'mc_session' => 'FIELD NOTE',
    'mc_title' => 'Summer<br/>School<br/>2026',
    'mc_tag' => '6 WEEKS · 600 CHILDREN · 50 VOLUNTEERS',
    'base_claps' => 143,
    'featured' => 0,
    'toc' => [['the-redesign', 'The redesign'], ['going-local', 'Going local'], ['ten-courses', 'Running ten courses'], ['what-we-watch', 'What we are watching']],
    'related' => ['introducing-the-afrovanguard-diary', 'school-storm-reaching-30000-children', 'what-techome-taught-us'],
    'body_html' => <<<'AVHTML'
<p class="lead">The Alimosho Summer School 2026 is built for <strong>600 children across six weeks</strong>, staffed by around fifty volunteers and structured around ten courses. The biggest change this year is not the size. It is the geography.</p>
        <h2 id="the-redesign">The redesign</h2>
        <p>Summer School sits in the engagement layer of our work — deeper than a campaign, lighter than a full formation programme. It is often a child's first real taste of structured learning that is not their ordinary school, and that first taste matters disproportionately. So this year we rebuilt it around a single question: what stops a child from coming every day for six weeks?</p>
        <h2 id="going-local">Going local</h2>
        <p>The honest answer was usually distance and cost of transport. A brilliant programme a child cannot reliably travel to is not a brilliant programme for that child. So for 2026 we distributed Summer School across the wards where children actually live — running it close to home in <strong>Egbeda, Mosan, Ayobo, Idimu, Ikotun and Ijaiye</strong> rather than concentrating it in one venue.</p>
        <ul>
          <li><strong>Proximity:</strong> Shorter journeys mean higher and more even attendance, especially for younger children and girls.</li>
          <li><strong>Trust:</strong> Running inside a community, with familiar faces, lowers the barrier for parents deciding whether to send a child.</li>
          <li><strong>Roots:</strong> A presence in six wards is the beginning of a permanent relationship, not a summer visit.</li>
        </ul>
        <blockquote>Access is a design choice. If attendance drops with distance, then distance — not motivation — is the thing to fix.</blockquote>
        <h2 id="ten-courses">Running ten courses</h2>
        <p>Ten courses across six locations with fifty volunteers is, frankly, an operations problem before it is an education one. The hard parts are matching volunteers to the courses they can actually teach well, keeping the curriculum consistent across sites, and making sure six simultaneous Summer Schools feel like one programme rather than six improvisations.</p>
        <p>We do not romanticise this. Distribution multiplies logistics, and a six-week commitment from fifty volunteers is a serious ask. But concentrating everything in one place to make our own logistics easier would mean optimising for the organisation instead of the child — which is exactly backwards.</p>
        <h2 id="what-we-watch">What we are watching</h2>
        <p>Our headline metric this year is not enrolment; it is the <strong>attendance curve across all six weeks</strong>, broken down by ward and by gender. Enrolment tells us we marketed well. The week-six curve tells us whether going local actually worked — and whether the girls'-attendance gap we have written about elsewhere narrows when the programme is closer to home.</p>
        <div class="callout"><strong>We will publish the curve.</strong> Once the 2026 edition closes, the attendance-by-ward data goes into the Diary — including any wards where going local did not move the numbers the way we hoped.</div>
        <p>If you can teach, mentor, or coordinate for even part of the six weeks, that is the most valuable thing you can give. <a href="https://cacentre.afrovanguard.org.ng/volunteer">Volunteer with Summer School</a> and we will find the right fit.</p>
AVHTML,
  ],
  [
    'slug' => 'what-techome-taught-us',
    'title' => 'What Techome taught us about teaching technology to teenagers',
    'dek' => 'Tools change every eighteen months. The habits we are really teaching do not. Notes on building a tech programme that ages well.',
    'category' => 'Programmes',
    'category_slug' => 'programmes',
    'authors_html' => 'The Afrovanguard Team',
    'published' => 'Apr 26, 2026',
    'published_at' => '2026-04-26',
    'read_minutes' => 5,
    'gradient' => 'g-sky',
    'mc_session' => 'PROGRAMME NOTE',
    'mc_title' => 'Techome',
    'mc_tag' => 'TECHNOLOGY · MENTORSHIP · CHARACTER',
    'base_claps' => 98,
    'featured' => 0,
    'toc' => [['the-trap', 'The shiny-tool trap'], ['habits', 'Teaching habits, not tools'], ['mentorship', 'Why mentorship is the real curriculum'], ['forward', 'Where this goes']],
    'related' => ['introducing-the-afrovanguard-diary', 'the-math-behind-1-million-leaders', 'school-storm-reaching-30000-children'],
    'body_html' => <<<'AVHTML'
<p class="lead">Techome is our technology programme, and the temptation with any tech programme is the same: chase the newest tool and call it relevance. After several cohorts, we are convinced that is the wrong instinct.</p>
        <h2 id="the-trap">The shiny-tool trap</h2>
        <p>It is genuinely easy to build a tech curriculum around whatever is trending. It demos well, it photographs well, and parents like the vocabulary. The problem is that the specific tool a teenager learns today may be irrelevant by the time they would use it professionally. If the tool is the point, the programme expires.</p>
        <h2 id="habits">Teaching habits, not tools</h2>
        <p>So we reframed Techome around the things that do not expire:</p>
        <ul>
          <li><strong>How to learn a tool you have never seen</strong> — reading documentation, breaking a problem down, and not panicking at unfamiliar interfaces.</li>
          <li><strong>How to think in systems</strong> — inputs, outputs, and what happens when something fails.</li>
          <li><strong>How to finish</strong> — shipping a small, real thing rather than endlessly starting.</li>
        </ul>
        <p>The specific technology becomes the vehicle, not the destination. A child who leaves Techome able to teach themselves the next tool has gained something the next tool cannot take away.</p>
        <blockquote>We are not raising users of this year's software. We are raising people who can pick up next decade's.</blockquote>
        <h2 id="mentorship">Why mentorship is the real curriculum</h2>
        <p>The other thing Techome confirmed is that technology, taught alone, does not form leaders — it forms operators. The part that connects to our wider mission is the mentorship wrapped around the code: the consistent adult presence, the conversations about integrity, the modelling of how a competent person behaves when no one is watching. That is where "incorruptible" actually gets taught.</p>
        <p>This is also why Techome sits alongside <a href="https://cacentre.afrovanguard.org.ng/mediapro/">MediaPro</a>, <a href="https://cacentre.afrovanguard.org.ng/africa-gates/">Africa GATES</a> and our creative programmes rather than standing apart as "the tech track". Capability without character is not the goal; the two are taught together or the formation is incomplete.</p>
        <h2 id="forward">Where this goes</h2>
        <p>The version of Techome we are most proud of is the one whose graduates come back to mentor the next cohort — closing exactly the multiplication loop our <a href="/diary/the-math-behind-1-million-leaders/">2040 math</a> depends on. That is the quiet ambition behind a tech class: not better users, but returning teachers.</p>
        <p>If you work in technology and can give a few hours a month, mentoring a Techome cohort is one of the highest-leverage things you can do for the movement. <a href="https://cacentre.afrovanguard.org.ng/volunteer">Come and mentor</a>.</p>
AVHTML,
  ],
];
