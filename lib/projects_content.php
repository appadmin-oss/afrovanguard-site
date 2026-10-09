<?php
/**
 * projects_content.php — the nine Afrovanguard projects, one source of truth.
 *
 * Copy from design/Afrovanguard Projects.dc.html and Afrovanguard Project.dc.html
 * (REPLACEMENT_MAP row 6), ported as data. Read by:
 *   - projects/_detail.php   /projects/<slug>/ for every entry with 'local' => true
 *   - projects/index.html    static cards; tests/projects.test.php keeps them in step
 *   - Appeals::projects()    the programmes an appeal may be filed against (local only)
 *
 * Keys: abbr, name, cat (filter category), status, selective (gold dot +
 * application entry; otherwise green dot + open registration), img, href,
 * ext (another site: opens in a new tab), local (has a page here), d, tags,
 * mail (Talk to the team), gallery (optional, How to join images).
 * Order is the design's card order.
 */
declare(strict_types=1);

$img = static fn (string $f): string => '/Images/' . $f;

return [
    'sts' => [
        'abbr' => 'STS', 'name' => 'Street-To-Stardom', 'cat' => 'Arts & Media',
        'status' => 'Year-round', 'selective' => false,
        'img' => $img('culture1.png'), 'href' => '/projects/sts/', 'ext' => false, 'local' => true,
        'd' => 'Where raw talent meets relentless refinement. STS channels music, dance and performing arts into professional-grade careers.',
        'tags' => ['Music', 'Dance', 'Performing Arts', 'Free'],
        'mail' => 'sts@afrovanguard.org.ng',
    ],
    'ngg' => [
        'abbr' => 'NGG', 'name' => 'Next Generation Genius', 'cat' => 'Leadership',
        'status' => 'Selective entry', 'selective' => true,
        'img' => '/assets/ngg/p04.jpeg', 'href' => 'https://next.afrovanguard.org.ng/', 'ext' => true, 'local' => false,
        'd' => 'Born from STS, NGG accelerates exceptional talent through competitive challenges, scholarships and stretching programming.',
        'tags' => ['Competitive', 'Scholarships', 'Residential'],
        'mail' => 'contact@afrovanguard.org.ng',
    ],
    'techhome' => [
        'abbr' => 'Techome', 'name' => 'Techome', 'cat' => 'Technology',
        'status' => 'Ongoing intake', 'selective' => false,
        'img' => $img('bootcamp1.png'), 'href' => '/projects/techhome/', 'ext' => false, 'local' => true,
        'd' => 'Training the next generation of African developers, designers and tech entrepreneurs — from right here in Alimosho.',
        'tags' => ['Web Dev', 'Design', 'Entrepreneurship', 'Free'],
        'mail' => 'tech@afrovanguard.org.ng',
    ],
    'mediapro' => [
        'abbr' => 'MediaPro', 'name' => 'MediaPro', 'cat' => 'Arts & Media',
        'status' => 'Active', 'selective' => false,
        'img' => $img('summer4.png'), 'href' => '/projects/mediapro/', 'ext' => false, 'local' => true,
        'd' => 'Developing journalists, content creators, videographers and digital storytellers who will shape how Africa is seen and heard.',
        'tags' => ['Journalism', 'Video', 'Content Creation'],
        'mail' => 'media@afrovanguard.org.ng',
    ],
    'kap' => [
        'abbr' => 'KAP', 'name' => 'Kingdom Advancement Project', 'cat' => 'Culture',
        'status' => 'Heritage-first', 'selective' => true,
        'img' => $img('alimosho.jpg'), 'href' => '/projects/kap/', 'ext' => false, 'local' => true,
        'd' => 'Cultural intelligence, leadership protocol and ethics, heritage development, and the traditional support systems that hold leaders up.',
        'tags' => ['Heritage', 'Culture', 'Leadership'],
        'mail' => 'kingdom@afrovanguard.org.ng',
    ],
    'africa-gates' => [
        'abbr' => 'GATES', 'name' => 'Africa GATES', 'cat' => 'Leadership',
        'status' => 'Open to apply', 'selective' => false,
        'img' => $img('gates2.png'), 'href' => 'https://afg.afrovanguard.org.ng', 'ext' => true, 'local' => false,
        'd' => 'Curating international scholarships and fellowships, then walking applicants through every step of the journey.',
        'tags' => ['Scholarships', 'Fellowships', 'Global'],
        'mail' => 'gates@afrovanguard.org.ng',
    ],
    'bec' => [
        'abbr' => 'BEC', 'name' => 'Business Executive Club', 'cat' => 'Business',
        'status' => 'Active', 'selective' => false,
        'img' => $img(rawurlencode('Afrovanguard Volunteers Get-Together- Lagos ChapterOn December 8th, 2024, the Lagos Chapter of  (1)') . '.webp'),
        'href' => '/projects/bec/', 'ext' => false, 'local' => true,
        'd' => 'Rooms with seasoned executives, live pitch competitions, and the commercial instincts needed to grow lasting enterprises.',
        'tags' => ['Pitching', 'Executives', 'Enterprise'],
        'mail' => 'bec@afrovanguard.org.ng',
    ],
    'career-hub' => [
        'abbr' => 'Career Hub', 'name' => 'Career Hub', 'cat' => 'Business',
        'status' => 'Active', 'selective' => false,
        'img' => $img('career1.png'), 'href' => '/projects/career-hub/', 'ext' => false, 'local' => true,
        'd' => 'CV workshops, interview coaching, sector mentorship, and direct connections to employers hiring young Nigerians.',
        'tags' => ['CV Coaching', 'Mentorship', 'Job Placement'],
        'mail' => 'careers@afrovanguard.org.ng',
    ],
    'ngv' => [
        'abbr' => 'NGV', 'name' => 'Afrovanguard Academy', 'cat' => 'Leadership',
        'status' => 'Forming NGV cohort', 'selective' => true,
        'img' => $img('summer1.png'), 'href' => '/academy/', 'ext' => false, 'local' => false,
        'd' => 'Where Vanguards are made — structured leadership formation, practical training and doctrine for the Next Generation Vanguard cohort.',
        'tags' => ['Leadership', 'Doctrine', 'NGV Cohort'],
        'mail' => 'contact@afrovanguard.org.ng',
    ],
];
