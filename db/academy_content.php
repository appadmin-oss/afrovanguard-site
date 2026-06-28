<?php
/**
 * db/academy_content.php — canonical Academy course catalogue.
 * Single source of truth, seeded into `courses` by db/academy_seed.php.
 * Edit here, then run:  php db/academy_seed.php --fresh
 */
return [
  [
    'slug' => 'techome', 'title' => 'Techome', 'category' => 'Technology', 'level' => 'Beginner → Intermediate',
    'format' => 'In-person', 'duration' => '12 weeks', 'price' => 'Free', 'gradient' => 'g-sky',
    'summary' => 'A hands-on technology track that teaches teenagers to learn any tool — not just this year’s software — wrapped in real mentorship.',
    'outcomes' => "Read documentation and learn unfamiliar tools independently\nThink in systems: inputs, outputs, and failure\nShip a small, real project end-to-end\nWork with integrity alongside a consistent mentor",
    'body_html' => '<p>Techome is our technology programme for young people in Alimosho. We teach the habits that do not expire — how to learn a new tool, how to break a problem down, and how to finish — using current software as the vehicle, not the destination.</p><h2>Who it is for</h2><p>Teenagers and young adults curious about technology, with little or no prior experience. Bring curiosity; we provide the rest.</p><h2>What you will build</h2><p>Every cohort ships a small but real project, and the strongest graduates return to mentor the next group.</p>',
    'cta_url' => 'https://cacentre.afrovanguard.org.ng/techhome/', 'featured' => 1, 'sort' => 1,
  ],
  [
    'slug' => 'mediapro', 'title' => 'MediaPro', 'category' => 'Creative', 'level' => 'All levels',
    'format' => 'In-person', 'duration' => '10 weeks', 'price' => 'Free', 'gradient' => 'g-sunset',
    'summary' => 'Storytelling, photography, video and design — the craft of telling true stories well, for a generation that lives in media.',
    'outcomes' => "Plan and shoot photo and video stories\nEdit with industry-standard workflows\nDesign for brand and social channels\nBuild a portfolio that opens doors",
    'body_html' => '<p>MediaPro trains young creatives in the full storytelling stack — from camera to edit to publish — with an emphasis on truth, taste and craft.</p><h2>Outcomes</h2><p>Graduates leave with a portfolio and the practical skills to work in media, or to tell their own community’s stories.</p>',
    'cta_url' => 'https://cacentre.afrovanguard.org.ng/mediapro/', 'featured' => 0, 'sort' => 2,
  ],
  [
    'slug' => 'africa-gates', 'title' => 'Africa GATES', 'category' => 'Leadership', 'level' => 'Advanced',
    'format' => 'Hybrid', 'duration' => '16 weeks', 'price' => 'Free', 'gradient' => 'g-gold',
    'summary' => 'Governance, Advocacy, Tech, Entrepreneurship & Service — a leadership intensive for young Africans ready to build institutions.',
    'outcomes' => "Lead teams and projects with integrity\nDesign and pitch a venture or initiative\nNavigate governance and advocacy\nJoin a network of incorruptible peers",
    'body_html' => '<p>Africa GATES is our flagship leadership intensive. It forms young people who can be trusted with responsibility — the core of our goal to raise one million incorruptible leaders by 2040.</p><h2>The five gates</h2><p>Governance, Advocacy, Tech, Entrepreneurship and Service — taught together, because capability without character is not leadership.</p>',
    'cta_url' => 'https://cacentre.afrovanguard.org.ng/africa-gates/', 'featured' => 0, 'sort' => 3,
  ],
  [
    'slug' => 'street-to-stardom', 'title' => 'Street-To-Stardom', 'category' => 'Creative', 'level' => 'All levels',
    'format' => 'In-person', 'duration' => 'Seasonal', 'price' => 'Free', 'gradient' => 'g-ink',
    'summary' => 'Discovering and developing raw talent from under-resourced communities — from the street to the stage, with structure behind it.',
    'outcomes' => "Develop a performance or creative discipline\nReceive coaching, mentorship and a platform\nBuild discipline, confidence and character\nJoin a tracked alumni community",
    'body_html' => '<p>Street-To-Stardom finds talent where the world is not looking and gives it structure, mentorship and a stage. It is education and youth development for under-resourced Lagos communities.</p>',
    'cta_url' => 'https://cacentre.afrovanguard.org.ng/street-to-stardom/', 'featured' => 0, 'sort' => 4,
  ],
  [
    'slug' => 'next-generation-genius', 'title' => 'Next Generation Genius', 'category' => 'Education', 'level' => 'Beginner',
    'format' => 'In-person', 'duration' => 'Ongoing', 'price' => 'Free', 'gradient' => 'g-green',
    'summary' => 'A weekend club building curiosity, character and core skills in children — the engagement layer that feeds everything else.',
    'outcomes' => "Strengthen literacy, numeracy and curiosity\nBuild character and teamwork\nDiscover interests early\nStep into deeper programmes over time",
    'body_html' => '<p>The Next Generation Genius Club is where many children first meet structured learning beyond school. It is deliberately joyful, consistent, and close to home.</p>',
    'cta_url' => 'https://next.afrovanguard.org.ng/', 'featured' => 0, 'sort' => 5,
  ],
  [
    'slug' => 'ngv-academy', 'title' => 'NGV Academy', 'category' => 'Technology', 'level' => 'Intermediate',
    'format' => 'Online', 'duration' => '8 weeks', 'price' => 'Free', 'gradient' => 'g-sky',
    'summary' => 'A focused online academy extending Afrovanguard’s training beyond Alimosho to young people across Africa.',
    'outcomes' => "Learn online at your own pace\nApply skills to a capstone project\nJoin a continent-wide cohort\nEarn a certificate of completion",
    'body_html' => '<p>NGV Academy takes the Afrovanguard method online, so distance is no longer a barrier to formation. It is how the movement scales past a single local government.</p>',
    'cta_url' => 'https://bit.ly/ngv', 'featured' => 0, 'sort' => 6,
  ],
];
