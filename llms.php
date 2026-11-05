<?php
/**
 * llms.php — /llms.txt: who Afrovanguard is and where things are, in plain
 * Markdown, for AI assistants and answer engines (the llms.txt convention).
 *
 * Built on each request from the same sources the site renders — the seven
 * projects (lib/projects_content.php), the reports (lib/Reports.php), the
 * contact details (lib/SiteSettings.php) — so it cannot say something the
 * pages do not. Nothing here is typed twice.
 *
 * .htaccess: ^llms\.txt$ → llms.php (router.php mirrors it).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$S        = rtrim(SITE_URL, '/');
$projects = avpj_projects_list();
$set      = SiteSettings::live();

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');

/** The projects from the one data file, for this endpoint only. */
function avpj_projects_list(): array
{
    require_once AV_ROOT . '/lib/avpj.php';
    return avpj_projects();
}
$abs = static fn (string $h): string => preg_match('~^https?://~', $h) ? $h : $S . $h;

$out = [];
$out[] = '# Afrovanguard';
$out[] = '';
$out[] = '> Afrovanguard is a youth leadership nonprofit founded in 2017 in Alimosho, Lagos, Nigeria. '
       . 'It runs ' . count($projects) . ' projects that train young people in technology, media, arts, enterprise and leadership, '
       . 'with the aim of raising one million incorruptible African leaders by 2040.';
$out[] = '';
$out[] = 'Key facts (confirmed by Afrovanguard, November 2026):';
$out[] = '';
$out[] = '- Founded: 2017, in Alimosho, Lagos, Nigeria.';
$out[] = '- Lives reached across all projects: 10,000+.';
$out[] = '- Projects: ' . count($projects) . '. Programmes run under projects (for example, Next Generation Genius and LCASP are Street-To-Stardom programmes).';
$out[] = '- Community centres (CACENTRE): two, in Alimosho, Lagos —';
$out[] = '  - CACENTRE Egbeda (registered address): 2 Oremeji Street, off Akowonjo Road, Bakey Bus Stop, Egbeda, Alimosho, Lagos.';
$out[] = '  - CACENTRE Ayobo: 18 Camp Davis Road, Orisunbare Phase 2 Bus Stop, Ishefun Road, Alimosho, Lagos.';
$out[] = '- Where a donation goes: 70% to the project or campaign the donor chooses, 30% to running the CACENTRE community centres.';
$out[] = '- Contact: ' . $set['email'] . ', +234 903 777 6318 (Mon–Fri, 9 am–5 pm WAT). Messages are answered within 24–48 hours on business days.';
$out[] = '';
$out[] = '## Projects';
$out[] = '';
foreach ($projects as $p) {
    $line = '- [' . $p['name'] . '](' . $abs((string) $p['href']) . '): ' . $p['d'];
    $out[] = $line;
    foreach ($p['programmes'] ?? [] as [$pn, $ph, $pd]) {
        $out[] = '  - Programme: ' . ($ph !== '' ? '[' . $pn . '](' . $abs($ph) . ')' : $pn) . ' — ' . $pd;
    }
}
$out[] = '';
$out[] = '## Learn, join and give';
$out[] = '';
$out[] = "- [Afrovanguard Academy]($S/academy/): free, hands-on programmes, with certificates that can be verified at $S/academy/verify.php.";
$out[] = "- [NextGen Vanguard]($S/academy/ngv/): the Academy's programme for school leavers, NYSC corps members and IT students in Lagos.";
$out[] = "- [Volunteer](https://cacentre.afrovanguard.org.ng/volunteer) · [Become a mentor]($S/mentorship/become-a-mentor/) · [Franchise a CACENTRE]($S/franchise)";
$out[] = "- [Donate]($S/donate/) · [Live appeals]($S/give/): each appeal shows what it has raised and what the money paid for.";
$out[] = "- [Events]($S/events/) · [The Diary]($S/diary/): field notes from the work.";
$out[] = '';
$out[] = '## About the organisation';
$out[] = '';
$out[] = "- [About]($S/about.html): the story, mission and people.";
$out[] = "- [Our ethos]($S/ethos/): the values every project is held to.";
$out[] = "- [How it works]($S/how-it-works): how members grow, from Foundation Member upward.";
$out[] = "- [Our people]($S/people/)";
$out[] = "- [Contact]($S/contact.html)";
$out[] = '';
$out[] = '## Reports';
$out[] = '';
foreach (Reports::all() as $r) {
    $out[] = '- ' . ($r['href'] !== '' ? '[' . $r['title'] . '](' . $abs($r['href']) . ')' : $r['title'])
           . ' (' . Reports::KINDS[$r['kind']] . ', ' . $r['year']
           . ($r['status'] === 'preparing' ? ', in preparation, expected ' . $r['expected'] : '') . ')';
}
$out[] = "- All reports: $S/reports/";
$out[] = '';
$out[] = '## Elsewhere';
$out[] = '';
foreach ($set['social'] as $net => $url) {
    if ($url !== '' && isset(SiteSettings::SOCIALS[$net])) $out[] = '- ' . SiteSettings::SOCIALS[$net][0] . ': ' . $url;
}
$out[] = '- YouTube (Okun Alimosho TV): https://www.youtube.com/@okunalimosho';
$out[] = '- Street-To-Stardom: https://sts.afrovanguard.org.ng · Africa GATES: https://afg.afrovanguard.org.ng · Next Generation Genius: https://next.afrovanguard.org.ng · CACENTRE: https://cacentre.afrovanguard.org.ng';
$out[] = '';
$out[] = '## Optional';
$out[] = '';
$out[] = "- [Privacy policy]($S/privacy-policy/) · [Terms]($S/terms/) · [Sitemap]($S/sitemap.xml)";

echo implode("\n", $out), "\n";
