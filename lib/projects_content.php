<?php
/**
 * projects_content.php — single source of truth for the project detail pages.
 * Rendered by projects/_detail.php; styled by assets/site/project-detail.css.
 *
 * Each entry: name, abbr, eyebrow, title (hero H1, HTML), lead, found_h/found_sub,
 * foundations[[title, desc], …], who, about[keywords for JSON-LD].
 */
declare(strict_types=1);

return [

    'kap' => [
        'name'    => 'Kingdom Advancement Project',
        'abbr'    => 'KAP',
        'eyebrow' => 'Kingdom Advancement Project · KAP',
        'title'   => 'What is Kingdom<br>Advancement?',
        'lead'    => 'Understand and carry your African heritage with depth, wisdom, and strategic intelligence. Kingdom Advancement equips leaders with the cultural foundations that last.',
        'found_h' => 'Four cultural foundations',
        'found_sub' => 'Heritage is not nostalgia — it is a working operating system for leadership. Kingdom Advancement builds it on four foundations that outlast any title.',
        'foundations' => [
            ['Cultural Intelligence',        'Read culture as fluently as strategy — the customs, languages, and unspoken codes that move people — and lead with context instead of assumption.'],
            ['Protocol & Leadership Ethics', 'The etiquette, discretion, and integrity expected in palaces, boardrooms, and public life — so you carry authority without ever losing character.'],
            ['Heritage Development',         'Turn inheritance into momentum: document, protect, and build on the traditions and institutions entrusted to your generation.'],
            ['Traditional Support Systems',  'The councils, elders, and community structures that keep leaders accountable — and carry them when the work grows heavy.'],
        ],
        'who'   => 'Traditional-institution leaders, cultural ambassadors, and heritage advocates — anyone who wants to lead from a foundation that lasts, where character precedes competence and conviction precedes calling.',
        'about' => ['African heritage', 'cultural intelligence', 'traditional leadership', 'leadership ethics'],
    ],

    'techhome' => [
        'name'    => 'Techome',
        'abbr'    => '',
        'eyebrow' => 'Technology · Techome',
        'title'   => 'What is<br>Techome?',
        'lead'    => 'Not everyone learns to code in Silicon Valley — and that is precisely the point. Techome trains the next generation of African developers, designers, and tech entrepreneurs from right here in Alimosho, with the skills that ship real products.',
        'found_h' => 'What you build',
        'found_sub' => 'Techome is hands-on from day one. You leave with work in production, not a certificate of attendance.',
        'foundations' => [
            ['Software Engineering',     'Front-end to back-end: build, test, and deploy real applications — not toy projects.'],
            ['Product & Design',         'UI/UX, branding, and product thinking, so you make things people actually want to use.'],
            ['The Founder Track',        'Turn a build into a business: validation, go-to-market, and the discipline to ship.'],
            ['Mentorship & Placement',   'Pair with working engineers and connect to internships and first roles.'],
        ],
        'who'   => 'Young Africans ready to build — whether you have never written a line of code or you are sharpening up for your first role.',
        'about' => ['software development', 'web development', 'tech entrepreneurship', 'African developers'],
    ],

    'mediapro' => [
        'name'    => 'MediaPro',
        'abbr'    => '',
        'eyebrow' => 'Arts & Media · MediaPro',
        'title'   => 'What is<br>MediaPro?',
        'lead'    => 'Africa has a story to tell — on its own terms. MediaPro develops journalists, content creators, videographers, and digital storytellers who will shape how this continent is seen and heard.',
        'found_h' => 'What you master',
        'found_sub' => 'MediaPro runs like a working newsroom and studio: real briefs, real deadlines, real audiences.',
        'foundations' => [
            ['Storytelling & Journalism', 'Report, write, and frame stories with accuracy, fairness, and craft.'],
            ['Video & Production',        'Shoot, edit, and produce film and video to a professional standard.'],
            ['Content & Social',          'Build audiences and brands across the platforms that decide what gets seen.'],
            ['Studio & Equipment',        'Hands-on access to gear, editing suites, and the rhythm of a real production house.'],
        ],
        'who'   => 'Aspiring journalists, creators, and producers who want their work to compete on any platform — and to count.',
        'about' => ['journalism', 'video production', 'content creation', 'digital storytelling'],
    ],

    'bec' => [
        'name'    => 'Business Executive Club',
        'abbr'    => 'BEC',
        'eyebrow' => 'Business · BEC',
        'title'   => 'What is the Business<br>Executive Club?',
        'lead'    => 'Real business acumen cannot be faked. BEC puts ambitious young Nigerians in rooms with seasoned executives, runs live pitch competitions, and builds the commercial instincts needed to grow lasting enterprises.',
        'found_h' => 'What you gain',
        'found_sub' => 'BEC is a proving ground, not a lecture series. You build instincts by using them under pressure.',
        'foundations' => [
            ['Executive Mentorship',      'Direct access to operators who have built and scaled real businesses.'],
            ['Pitch & Competition',       'Live pitch arenas that pressure-test your idea — and your nerve.'],
            ['Commercial Fundamentals',   'Finance, strategy, and operations: the instincts behind durable companies.'],
            ['Network & Capital',         'Connections to partners, customers, and the people who back good ideas.'],
        ],
        'who'   => 'Founders and future executives who want substance over hustle — and a room that holds them to it.',
        'about' => ['entrepreneurship', 'business mentorship', 'leadership', 'startups'],
    ],

    'career-hub' => [
        'name'    => 'Career Hub',
        'abbr'    => '',
        'eyebrow' => 'Business · Career Hub',
        'title'   => 'What is<br>Career Hub?',
        'lead'    => 'The gap between education and employment is real — and we close it. Career Hub offers CV workshops, interview coaching, sector mentorship, and direct connections to employers who are actually hiring young Nigerians.',
        'found_h' => 'How we close the gap',
        'found_sub' => 'Career Hub turns preparation into offers, with practical support at every step from CV to contract.',
        'foundations' => [
            ['CV & Portfolio',        'Build a CV and portfolio that survive the first ten-second scan.'],
            ['Interview Coaching',    'Practice, feedback, and the confidence to convert interviews into offers.'],
            ['Sector Mentorship',     'Guidance from people already working in the field you are aiming for.'],
            ['Employer Connections',  'Direct introductions to organisations that are hiring right now.'],
        ],
        'who'   => 'Graduates and job-seekers ready to turn preparation into a paycheque.',
        'about' => ['career development', 'employability', 'mentorship', 'job placement'],
    ],

];
