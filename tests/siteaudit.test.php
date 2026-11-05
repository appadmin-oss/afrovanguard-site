<?php
/**
 * tests/siteaudit.test.php — the public-site audit of 2026-11-05.
 *
 * The things a reviewer (or a search engine) checks first: the sitemap lists
 * each page under the URL that page calls canonical, no internal link points
 * at a page that does not exist, and Street-To-Stardom has one website.
 */
declare(strict_types=1);

$saXml = (string) (new ReflectionMethod('Sitemap', 'buildXml'))->invoke(null);
preg_match_all('~<loc>https://[^/]+(/[^<]*)</loc>~', $saXml, $saM);
$saLocs = $saM[1];

/* Every static sitemap entry agrees with the canonical its page declares. */
$saCanon = [
    '/about.html'   => 'about.html',
    '/contact.html' => 'contact.html',
    '/donate/'      => 'donate.html',
    '/projects/'    => 'projects/index.html',
];
foreach ($saCanon as $loc => $file) {
    $html = (string) @file_get_contents(AV_ROOT . '/' . $file);
    ck("sitemap: $loc is listed", in_array($loc, $saLocs, true));
    ck("sitemap: $file declares $loc canonical",
       str_contains($html, '<link rel="canonical" href="https://afrovanguard.org.ng' . $loc . '"'));
}
foreach (['/about/', '/contact/', '/donate.html', '/projects', '/projects/sts/lcasp/', '/projects/sts/lcasp.html'] as $stale) {
    ck("sitemap: the non-canonical $stale is not listed", !in_array($stale, $saLocs, true));
}
ck('sitemap: the privacy policy and terms are listed',
   in_array('/privacy-policy/', $saLocs, true) && in_array('/terms/', $saLocs, true));

/* Links that pointed at nothing. */
ck('links: the Diary\'s Join goes to the volunteer form, not /volunteer/',
   !str_contains((string) file_get_contents(AV_ROOT . '/diary/partials.php'), 'href="/volunteer/"'));
ck('links: the LCASP page never names /projects/sts/lcasp/ (a 404)',
   !str_contains((string) file_get_contents(AV_ROOT . '/projects/sts/lcasp.html'), '/projects/sts/lcasp/'));

/* About: the 2023 chip agrees with the paragraph beneath it. */
$saAbout = (string) file_get_contents(AV_ROOT . '/about.html');
ck('about: 2023 is 5,000+ lives in both the chip and the text',
   str_contains($saAbout, '2023 · 5,000+ lives') && str_contains($saAbout, 'more than 5,000 lives'));

/* HSTS reaches the static pages, with the value lib/security.php sends. */
ck('http: .htaccess sends HSTS for the pages PHP never touches',
   str_contains($saHt ?? (string) file_get_contents(AV_ROOT . '/.htaccess'), 'Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"')
   && str_contains((string) file_get_contents(AV_ROOT . '/lib/security.php'), "Strict-Transport-Security: max-age=31536000; includeSubDomains"));

/* Street-To-Stardom: its site is sts.afrovanguard.org.ng; the copy here is gone. */
foreach (['about', 'team', 'impact', 'units', 'programs', 'get-involved', 'blog', 'contact', '_astro'] as $d) {
    ck("sts: the old copy's $d/ is removed", !is_dir(AV_ROOT . "/projects/sts/$d"));
}
ck('sts: the speaker-intake tool lives on the STS site, not here',
   !is_file(AV_ROOT . '/projects/sts/ceo/index.html') && !is_file(AV_ROOT . '/projects/sts/ceo/api/submit.php'));
ck('sts: the guard over any backups it left on a host stays', is_file(AV_ROOT . '/projects/sts/ceo/api/data/.htaccess'));
ck('sts: the old copy\'s sitemap (another domain\'s URLs) is removed', !is_file(AV_ROOT . '/projects/sts/sitemap.xml'));
$saHt = (string) file_get_contents(AV_ROOT . '/.htaccess');
$saRt = (string) file_get_contents(AV_ROOT . '/router.php');
$saRule = '^projects/sts/((?:about|team|impact|units|programs|get-involved|blog|contact|ceo)(?:/.*)?)$ https://sts.afrovanguard.org.ng/$1 [R=301';
ck('sts: old paths 301 to the STS site (.htaccess)', str_contains($saHt, $saRule));
ck('sts: the redirect sits above the real-file guard',
   strpos($saHt, $saRule) < strpos($saHt, "RewriteCond %{REQUEST_FILENAME} -f [OR]"));
ck('sts: the dev router mirrors it', str_contains($saRt, "header('Location: https://sts.afrovanguard.org.ng/'"));
ck('sts: what this site still serves is kept',
   is_file(AV_ROOT . '/projects/sts/index.php') && is_file(AV_ROOT . '/projects/sts/lcasp.html')
   && is_file(AV_ROOT . '/projects/sts/STS_Report_2025.pdf')
   && is_file(AV_ROOT . '/projects/sts/2026-LCASP-Document.pdf'));

/* No merge-conflict markers in anything the site serves. One sat in the <head>
   of projects/index.html, where a browser prints it at the top of the page. */
$saConflicts = [];
$saIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(AV_ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($saIt as $f) {
    $rel = substr($f->getPathname(), strlen(AV_ROOT) + 1);
    if (preg_match('~^(vendor|\.git|node_modules|db|uploads)/~', $rel) || !preg_match('~\.(html|php|css|js|json|xml|htaccess)$~', $rel)) continue;
    if (preg_match('~^(<{7}|>{7}) ~m', (string) @file_get_contents($f->getPathname()))) $saConflicts[] = $rel;
}
ck('repo: no merge-conflict markers (' . implode(', ', $saConflicts) . ')', $saConflicts === []);

/* Sex is recorded as Male or Female, on every form and in the NGV store. */
ck('ngv: sex keeps Male and Female, normalised',
   NgvMember::sex('female') === 'Female' && NgvMember::sex(' M ') === 'Male' && NgvMember::sex('Male') === 'Male');
ck('ngv: anything else is not stored', NgvMember::sex('other') === '' && NgvMember::sex('') === '');

/* Reports (/reports/): the list is sound, newest first, linked and indexed. */
ck('reports: the list has no problems (' . implode('; ', Reports::problems()) . ')', Reports::problems() === []);
$saRep = Reports::all();
$saYears = array_column($saRep, 'year'); $saSorted = $saYears; rsort($saSorted);
ck('reports: newest year first, the annual report leading its year',
   $saYears === $saSorted && $saRep[0]['year'] === 2026 && $saRep[0]['kind'] === 'annual');
ck('reports: the 2026 annual report is listed as in preparation, with no file',
   (function () use ($saRep) { foreach ($saRep as $r) if ($r['year'] === 2026 && $r['kind'] === 'annual') return $r['status'] === 'preparing' && $r['href'] === '' && $r['expected'] !== ''; return false; })());
ck('reports: every published report links a real file',
   array_filter($saRep, static fn ($r) => $r['status'] !== 'preparing' && $r['href'] === '') === []);
ck('reports: in the sitemap', in_array('/reports/', $saLocs, true) || str_contains((string) (new ReflectionMethod('Sitemap', 'buildXml'))->invoke(null), '/reports/</loc>'));
ck('reports: linked from the shared footer and the Home page',
   str_contains((string) file_get_contents(AV_ROOT . '/partials/avh-chrome.php'), '<a href="/reports/">Reports</a>')
   && str_contains((string) file_get_contents(AV_ROOT . '/index.html'), '<a href="/reports/">Reports</a>'));

/* Facts the owner settled (2026-11-05). */
$saFacts = [
    'login/index.php' => 'Est. 2017',
    'member.html'     => "Founded Afrovanguard in 2017",
    'index.html'      => '"streetAddress": "CACENTRE Egbeda, 2 Oremeji Street, off Akowonjo Road, Bakey Bus Stop"',
    'contact.html'    => 'CACENTRE Egbeda, 2 Oremeji Street, off Akowonjo Road, Bakey Bus Stop',
];
foreach ($saFacts as $f => $needle) ck("facts: $f says “{$needle}”", str_contains((string) file_get_contents(AV_ROOT . "/$f"), $needle));
foreach (['index.html', 'about.html', 'member.html', 'projects/index.html', 'login/index.php'] as $f) {
    $t = (string) file_get_contents(AV_ROOT . "/$f");
    ck("facts: $f gives no other founding year or project count",
       !preg_match('~Est\. 2018|[Ff]ounded[^.]{0,40}2018|\b(?:nine|Nine|9) (?:Flagship |Active )?(?:[Pp]rogram|[Pp]rojects?)|[Ee]ight (?:active )?programmes|8 flagship~', $t));
}
ck('reports: a project document (a plan) is never shown as the latest report',
   (Reports::headline(Reports::all())['latest']['kind'] ?? '') !== 'document');
ck('nav: the footer and menus list projects, not NGG, as projects',
   !str_contains((string) file_get_contents(AV_ROOT . '/partials/avh-chrome.php'), 'Kingdom Advancement</a><a href="https://next.afrovanguard.org.ng/"'));

/* The two centres (owner, 2026-11-05): their addresses and the owner's own map links. */
$saC = (string) file_get_contents(AV_ROOT . '/contact.html');
ck('centres: Ayobo is 18 Camp Davis Road, with its map link',
   str_contains($saC, 'data-addr="18 Camp Davis Road, Orisunbare Phase 2 Bus Stop, Ishefun Road, Alimosho, Lagos"')
   && str_contains($saC, 'data-dir="https://maps.app.goo.gl/MdfC7vS58sDKm7cK8"'));
ck('centres: Egbeda is 2 Oremeji Street, with its map link',
   str_contains($saC, 'data-addr="2 Oremeji Street, off Akowonjo Road, Bakey Bus Stop, Egbeda, Alimosho, Lagos"')
   && str_contains($saC, 'data-dir="https://maps.app.goo.gl/srnufRqc7LAT8rEp6"'));
foreach (['contact.html', 'index.html', 'lib/Ngv.php', 'reports/index.php'] as $f) {
    $t = (string) file_get_contents(AV_ROOT . "/$f");
    ck("centres: $f has no old or misspelt address", !str_contains($t, 'Abolude') && !str_contains($t, 'Bakery Bus Stop'));
}
ck('centres: the address line follows the selected tab', str_contains((string) file_get_contents(AV_ROOT . '/assets/site/avct.js'), "t.dataset.addr"));

/* Reports: requests are contact-form messages, not mailto: links. */
$saRp = (string) file_get_contents(AV_ROOT . '/reports/index.php');
ck('reports: no mailto: buttons; requests open the contact form pre-filled',
   !str_contains($saRp, '$mail(') && str_contains($saRp, "'/contact/?'") && str_contains($saRp, "#contact-form"));
ck('contact: the form accepts a pre-fill, purpose only from its own options',
   str_contains((string) file_get_contents(AV_ROOT . '/assets/site/avct.js'), "[...purpose.options].some(o => o.value === pp)"));

/* Campaigns are appeals, timed by their own dates (owner, 2026-11-05). */
$saA = ['status' => 'live', 'starts_on' => '2026-03-01', 'ends_on' => '2026-08-31'];
ck('campaigns: before its start date an appeal is scheduled', Appeals::window($saA, '2026-02-28') === 'upcoming' && !Appeals::isRunning($saA, '2026-02-28'));
ck('campaigns: from its first day to its last it is running (both ends inclusive)',
   Appeals::isRunning($saA, '2026-03-01') && Appeals::isRunning($saA, '2026-08-31'));
ck('campaigns: the day after its end date it has ended', Appeals::window($saA, '2026-09-01') === 'ended' && !Appeals::isRunning($saA, '2026-09-01'));
ck('campaigns: an appeal with no dates runs while it is live',
   Appeals::isRunning(['status' => 'live', 'starts_on' => '', 'ends_on' => ''], '2030-01-01')
   && !Appeals::isRunning(['status' => 'paused', 'starts_on' => '', 'ends_on' => ''], '2030-01-01'));
ck('campaigns: the LCASP 2026 page redirects to the STS page (.htaccess and dev router)',
   str_contains($saHt, 'RewriteRule ^projects/sts/lcasp(?:\.html|/)?$ /projects/sts/ [R=301,L]')
   && strpos($saHt, 'RewriteRule ^projects/sts/lcasp') < strpos($saHt, "RewriteCond %{REQUEST_FILENAME} -f [OR]")
   && str_contains($saRt, "~^/projects/sts/lcasp(?:\\.html|/)?$~"));
foreach (['index.html', 'projects/index.html', 'donate.html', 'lib/projects_content.php', 'partials/avh-chrome.php'] as $f) {
    ck("campaigns: $f no longer links the retired LCASP page or names a past campaign",
       !preg_match('~href="/projects/sts/lcasp\.html"|LCASP 2026 — the|Campus Tour 2026|2026 Season</option>|School Storm 2026|Summer 2026 · Limited~', (string) file_get_contents(AV_ROOT . "/$f")));
}
ck('campaigns: Donate, Projects and Home fill their campaign spots from the appeals feed',
   str_contains((string) file_get_contents(AV_ROOT . '/assets/site/avdn.js'), "/give/feed.json?limit=12")
   && str_contains((string) file_get_contents(AV_ROOT . '/assets/site/avpj.js'), "/give/feed.json?limit=4")
   && str_contains((string) file_get_contents(AV_ROOT . '/projects/index.html'), 'data-avpj-open hidden'));

/* Claims the owner withdrew (2026-11-05): no "14 nations", no "100% of funds". */
foreach (['index.html', 'contact.html', 'donate.html', 'about.html'] as $f) {
    $t = (string) file_get_contents(AV_ROOT . "/$f");
    ck("claims: $f says nothing about nations reached or 100% of funds",
       !preg_match('~[Nn]ations [Rr]eached|data-avh-count="14"|100% of (?:my |your )?(?:funds|donation)|zero admin cut~', $t));
}
ck('claims: where a donation goes is the owner\'s split, 70% project / 30% CACENTRE, on Donate and Contact',
   str_contains((string) file_get_contents(AV_ROOT . '/donate.html'), '70% goes to the project or campaign you give to, and 30% to running the CACENTRE')
   && str_contains((string) file_get_contents(AV_ROOT . '/contact.html'), '70% to the project, 30% to running CACENTRE'));

/* Search engines and AI assistants (2026-11-05). */
$saOrg = schema_org();
ck('seo: the organisation\'s logo and image are files the site serves',
   is_file(AV_ROOT . parse_url($saOrg['logo']['url'], PHP_URL_PATH)) && is_file(AV_ROOT . parse_url($saOrg['image'], PHP_URL_PATH)));
ck('seo: the organisation data states only confirmed facts',
   $saOrg['foundingDate'] === '2017' && str_contains($saOrg['address']['streetAddress'], '2 Oremeji Street')
   && !isset($saOrg['numberOfEmployees']) && in_array('https://www.instagram.com/afro.vanguard/', $saOrg['sameAs'], true));
$saHome = (string) file_get_contents(AV_ROOT . '/index.html');
ck('seo: the Home page\'s hand-written organisation node agrees (no 404 wp-content logo, no employee count, real search)',
   !str_contains($saHome, '/wp-content/uploads/') && !str_contains($saHome, 'numberOfEmployees')
   && str_contains($saHome, '"target": "https://afrovanguard.org.ng/search.php?q={search_term_string}"'));
ck('seo: the shared site search action is the real search', str_contains(json_encode(schema_website(), JSON_UNESCAPED_SLASHES), '/search.php?q={search_term_string}'));
$saRobots = (new ReflectionMethod('Sitemap', 'buildRobots'))->invoke(null);
ck('seo: robots.txt names no WordPress sitemap (a 404) and points at llms.txt',
   !str_contains($saRobots, 'wp-sitemap') && str_contains($saRobots, '/llms.txt'));
ck('seo: /llms.txt is routed (.htaccess and dev router)',
   str_contains($saHt, 'RewriteRule ^llms\.txt$ llms.php [L]') && str_contains($saRt, "if (\$uri === '/llms.txt')"));
foreach (['index.html', 'about.html', 'contact.html', 'donate.html', 'projects/index.html'] as $f) {
    $t = (string) file_get_contents(AV_ROOT . "/$f");
    preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $t, $m);
    $bad = array_filter($m[1], static fn ($j) => json_decode($j, true) === null);
    ck("seo: $f has structured data and all of it parses", $m[1] !== [] && $bad === []);
    preg_match('~<meta name="description" content="([^"]*)"~', $t, $d);
    ck("seo: $f has a description search results show in full (70–165 characters)", isset($d[1]) && mb_strlen(html_entity_decode($d[1])) >= 70 && mb_strlen(html_entity_decode($d[1])) <= 165);
    ck("seo: $f has a share image", str_contains($t, '<meta property="og:image"'));
    ck("seo: $f says nothing a funder would contest", !preg_match('~100% to projects|3-4 hour|foremost youth leadership NGO~', $t));
}
ck('seo: the projects list given to search engines is the seven projects, from the data file',
   substr_count((string) file_get_contents(AV_ROOT . '/projects/index.html'), '"@type":"ListItem","position":') === 9);

/* Chioma knows what the site says (owner, 2026-11-05: "knows absolutely nothing"). */
$saFacts = SiteFacts::markdown();
foreach (['Founded: 2017', '10,000+', '2 Oremeji Street', '18 Camp Davis Road', '70% to the project', 'They are different things', 'cacentre.afrovanguard.org.ng/volunteer'] as $needle) {
    ck("chioma: the fact sheet says “{$needle}”", str_contains($saFacts, $needle));
}
$saBrief = Chioma::systemPrompt(['title' => 'Home', 'path' => '/'], false);
ck('chioma: her briefing carries the whole fact sheet', str_contains($saBrief, trim($saFacts)));
ck('chioma: her briefing no longer lists Techome, MediaPro, GATES and NGG as Academy programmes',
   !str_contains($saBrief, 'Techome, MediaPro, Africa GATES, Next Generation Genius'));
ck('chioma: her briefing sends people to pages that exist', !str_contains($saBrief, '(/contact/)') && !str_contains($saBrief, '/donate.html'));
ck('chioma: the community bot is briefed from the same sheet', str_contains(AvBot::systemPrompt(), trim($saFacts)));
$saAsk = static fn (string $q): string => Chioma::fallback($q);
ck('chioma: without AI she still knows when we were founded', str_contains($saAsk('When was Afrovanguard founded?'), '2017'));
ck('chioma: without AI she knows where the centres are', str_contains($saAsk('Where is your office?'), '2 Oremeji Street') && str_contains($saAsk('Where is your office?'), 'Camp Davis'));
ck('chioma: without AI she knows where a donation goes', str_contains($saAsk('Where does my donation go?'), '70%'));
ck('chioma: without AI she sends volunteers to the volunteer form', str_contains($saAsk('How do I volunteer?'), 'cacentre.afrovanguard.org.ng/volunteer'));
ck('chioma: a question about one project gets that project', str_contains($saAsk('Tell me about Techome'), '/projects/techhome/'));
ck('chioma: NextGen Vanguard and Next Generation Genius are not confused',
   str_contains($saAsk('Tell me about NextGen Vanguard'), '/academy/ngv/') && str_contains($saAsk('What is Next Generation Genius?'), 'next.afrovanguard.org.ng'));

/* Second consistency sweep (2026-11-05): wording that contradicted the
   seven projects, the programme names, or invented figures. */
$saPublic = ['index.html', 'about.html', 'contact.html', 'donate.html', 'projects/index.html', 'projects/_detail.php',
             'academy/index.php', 'member.html', 'donor-dashboard.html', 'process-contact.php'];
foreach ($saPublic as $f) {
    $t = (string) file_get_contents(AV_ROOT . "/$f");
    ck("consistency: $f has no leftover count, name or campaign",
       !preg_match('~[Nn]ine projects|Active programmes</dt>|Next Generation Vanguard|Apply to a program<|Selective programs|Campus Tour 2026|Street-To-Stardom 2026 Season|Summer School</option>|Creative / Cultural — Africa GATES~', $t));
}
$saDD = (string) file_get_contents(AV_ROOT . '/donor-dashboard.html');
ck('donor dashboard: never shows invented totals when the request fails', !preg_match('~raised:\s*\d{4,}~', $saDD) && str_contains($saDD, 'We could not load the campaign totals just now'));
ck('donor dashboard: its campaigns are the running appeals', str_contains($saDD, "fetch('/give/feed.json?limit=12'") && !str_contains($saDD, 'CAMP_META'));
