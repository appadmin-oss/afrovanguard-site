<?php
/**
 * people/index.php — the team directory and individual profiles.
 *   /people/              → directory: a plain hero, a slim Volunteer of the Month strip, a
 *                           small "How we are organised" overview (in-page navigation that
 *                           works without script), one toolbar, Leadership full width, then
 *                           one section per constitutional body (AV_TEAM_STRUCTURE), each a
 *                           full-width header over a grid of cards, then a call to action
 *   /people/<id>-<slug>/  → a single member's profile (see .htaccess; ?id=N)
 *
 * Server-rendered from the native team data (lib/people.php). Only rows that
 * pass av_team_listable() are ever shown here: curated by hand, or auto-synced
 * AND opted in, and never a system or organisation account. Everything is in
 * the markup, so the page works without script; people/people.js only adds
 * the search and filter (the toolbar stays hidden until it runs), keeps them in
 * the URL (?q=, ?g=), and on a profile points "All our people" back at the
 * filtered directory the visitor came from.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/people.php';

$S = rtrim(SITE_URL, '/');
$pdo = Database::pdo();
$id = (int) ($_GET['id'] ?? 0);

// The routes the nav and footer use for "Volunteer" and "Become a member".
defined('PPL_VOLUNTEER_URL') || define('PPL_VOLUNTEER_URL', 'https://cacentre.afrovanguard.org.ng/volunteer');
defined('PPL_MEMBER_URL') || define('PPL_MEMBER_URL', '/academy/#membership');

/* ---------- helpers ---------- */
$socIcon = [
    'li' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M20.45 20.45h-3.55v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.45v6.29zM5.34 7.43a2.06 2.06 0 110-4.13 2.06 2.06 0 010 4.13zM7.12 20.45H3.56V9h3.56z"/></svg>',
    'tw' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18.24 2.25h3.31l-7.23 8.26 8.5 11.24h-6.66l-5.21-6.82-5.97 6.82H1.66l7.73-8.84L1.25 2.25H8.08l4.71 6.23zm-1.16 17.52h1.83L7.08 4.13H5.12z"/></svg>',
    'ig' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>',
    'web' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15 15 0 010 20 15 15 0 010-20z"/></svg>',
    'email' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/></svg>',
];
$socName = ['li' => 'LinkedIn', 'tw' => 'X', 'ig' => 'Instagram', 'web' => 'website', 'email' => 'Email'];
$ext = '<span class="av-sr"> (opens in a new tab)</span>';

/** The social links row for one person ($cls: the row's class). $named: the network's name shows
    beside the icon (the profile's "Get in touch"); the accessible name still starts with the person's. */
$socials = function (array $m, string $cls, bool $named = false) use ($socIcon, $socName, $ext): string {
    $out = '';
    foreach ($m['socials'] as $k => $v) {
        if (!isset($socIcon[$k])) continue;
        $isMail = $k === 'email';
        if ($isMail ? !filter_var($v, FILTER_VALIDATE_EMAIL) : !preg_match('~^https?://~i', $v)) continue;
        $label = $isMail ? ('Email ' . $m['name']) : ($m['name'] . ' on ' . $socName[$k]);
        $out .= '<li><a href="' . e($isMail ? 'mailto:' . $v : $v) . '"' . ($isMail ? '' : ' target="_blank" rel="noopener"')
              . '>' . $socIcon[$k] . ($named && !$isMail ? '<span class="av-sr">' . e($m['name']) . ' on </span>' . e(ucfirst($socName[$k]))
                                                  : '<span class="av-sr">' . e($label) . '</span>') . ($isMail ? '' : $ext) . '</a></li>';
    }
    return $out === '' ? '' : '<ul class="' . $cls . '">' . $out . '</ul>';
};

/** The initials on a tint from the palette, with the photo (when there is one) laid over them:
    a photo that is missing or fails to load leaves the initials showing, with no script and
    no inline handler (the CSP allows neither). Decorative either way: the name is right beside it. */
$avatar = function (array $m, string $cls, int $size) : string {
    $tint = 'ppl-tint-' . av_person_tint($m['name']); $ini = e(av_person_initials($m['name']));
    $img = $m['photo'] !== '' ? '<img class="' . $tint . '" src="' . e($m['photo']) . '" alt="" data-ini="' . $ini . '" width="' . $size . '" height="' . $size . '" loading="lazy" decoding="async" />' : '';
    return '<span class="' . $cls . ' ' . $tint . ($img ? ' ppl-av--ph' : '') . '" aria-hidden="true">' . $ini . $img . '</span>';
};

/** The text a search matches against: name, role, office, body (directorate, institution…),
    group and place. people.js folds accents and allows a one-letter typo in longer words. */
$haystack = function (array $m): string {
    $p = av_team_placement($m); $G = AV_TEAM_STRUCTURE[$p['group']];
    return mb_strtolower(implode(' ', array_unique(array_filter([$m['name'], av_person_role_line($m), av_team_body_label($p['body']),
        av_team_office_label($p['body'], $p['office']), $G['label'], $G['short'], $m['location']]))));
};
/** The data attributes the filter reads: the person (counted once however often shown), their group, the search text. */
$dataAttrs = fn(array $m) => 'data-id="' . (int) $m['id'] . '" data-group="' . e(av_team_placement($m)['group']) . '" data-q="' . e($haystack($m)) . '"';

/** Where a directorate or institution person works ("TECHOME"), unless their role already says so.
    Under a directorate heading the CSS hides it; in search results and "More from" it shows. */
$cardMeta = function (array $m, string $role): string {
    $p = av_team_placement($m);
    if (!in_array($p['group'], ['directorates', 'institutions'], true)) return '';
    $label = av_team_body_label($p['body']);
    $core = trim((string) preg_replace('~\s*\(.*\)$~', '', $label));
    return ($core === '' || mb_stripos($role, $core) !== false) ? '' : $label;
};

/** One directory card: avatar, name, role (and directorate). The name's link stretches over the
    whole card, so the card is one link; the arrow is a visual hint only. */
$card = function (array $m, string $hLevel = 'h4') use ($avatar, $dataAttrs, $cardMeta): string {
    $role = av_person_role_line($m); $meta = $cardMeta($m, $role);
    ob_start(); ?>
          <li class="ppl-card" <?= $dataAttrs($m) ?>>
            <?= $avatar($m, 'ppl-av', 128) ?>

            <div class="ppl-card-b">
              <<?= $hLevel ?> class="ppl-card-n"><a href="<?= e(av_person_url($m)) ?>"><?= e($m['name']) ?></a></<?= $hLevel ?>>
              <p class="ppl-card-r"><?= e($role) ?></p>
<?php if ($meta !== ''): ?>              <p class="ppl-card-d"><?= e($meta) ?></p>
<?php endif; ?>
            </div>
            <span class="ppl-go" aria-hidden="true">→</span>
          </li>
<?php return (string) ob_get_clean();
};

/** The closing call to action (directory and profile). */
$cta = function () use ($ext): void { ?>
    <section class="ppl-cta" aria-labelledby="ppl-cta-h">
      <div class="ppl-wrap ppl-cta-in">
        <div>
          <h2 id="ppl-cta-h">Want to be part of the vanguard?</h2>
          <p>Give a few hours a month as a volunteer, or join as a member and grow through the Academy. Either way, there is a place for you here.</p>
        </div>
        <div class="ppl-btns">
          <a class="ppl-btn ppl-btn--gold" href="<?= e(PPL_VOLUNTEER_URL) ?>" target="_blank" rel="noopener">Volunteer with us<span aria-hidden="true"> ↗</span><?= $ext ?></a>
          <a class="ppl-btn ppl-btn--onink" href="<?= e(PPL_MEMBER_URL) ?>">Become a member</a>
        </div>
      </div>
    </section>
<?php };

$pin = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M12 22s7-6.1 7-12a7 7 0 10-14 0c0 5.9 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/></svg>';
$star = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 2.5l2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg>';
$abs = fn(string $u) => preg_match('~^https?://~i', $u) ? $u : ($S . '/' . ltrim($u, '/'));
$monthName = fn(int $m, int $y) => date('F', mktime(0, 0, 0, max(1, min(12, $m)), 1)) . ' ' . $y;

/* ============================================================ PROFILE */
if ($id) {
    $res = av_team_public_one($pdo, $id);
    /* Hidden, unconsented, system or missing: the same 404, so a profile URL
       cannot be used to learn that somebody is in the Studio. `return`, not
       exit, ends this script the same way and lets the suite render it. */
    if (($res['status'] ?? '') !== 'ok') { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); return; }
    $m = $res['member'];
    $canonical = $S . av_person_url($m);
    $place = av_team_placement($m);
    $grpDef = AV_TEAM_STRUCTURE[$place['group']];
    $where = $grpDef['label'] . (count($grpDef['bodies']) > 1 ? ' · ' . av_team_body_label($place['body']) : '');
    $role = av_person_role_line($m);
    $votm = av_votm($pdo)['votm'] ?? null;
    $isVotm = $votm && (int) $votm['id'] === (int) $m['id'];
    // Listable people from the same body (else the same group), not this person — up to four.
    $others = array_values(array_filter(array_map('av_team_member_dict', av_team_public_rows($pdo)), fn($p) => $p['id'] !== $m['id']));
    $related = array_values(array_filter($others, fn($p) => av_team_placement($p)['body'] === $place['body']));
    $relLabel = count($grpDef['bodies']) > 1 ? av_team_body_label($place['body']) : $grpDef['label'];
    if (!$related) { $related = array_values(array_filter($others, fn($p) => av_team_placement($p)['group'] === $place['group'])); $relLabel = $grpDef['label']; }
    $related = array_slice($related, 0, 4);
    // How to reach them: only what they chose to list (socials), checked the same way as the icons.
    $mail = (string) ($m['socials']['email'] ?? '');
    $mail = filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : '';
    $links = $socials(['socials' => array_diff_key($m['socials'], ['email' => 1])] + $m, 'ppl-soc ppl-soc--named', true);
    $contact = ['mail' => $mail, 'links' => $links, 'any' => $mail !== '' || $links !== ''];
    $first = explode(' ', trim($m['name']))[0] ?: $m['name'];

    render_head([
        'title' => $m['name'] . ' — ' . $role . ' · Afrovanguard',
        'desc'  => $m['bio'] !== '' ? mb_substr($m['bio'], 0, 160) : ($m['name'] . ' — ' . $role . ' at Afrovanguard.'),
        'canonical' => $canonical, 'og_kind' => 'profile',
        'image' => $m['photo'] !== '' ? $abs($m['photo']) : null, 'css' => ['/people/people.css'],
        'jsonld' => [schema_org(), [
            '@type' => 'ProfilePage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'inLanguage' => 'en-NG',
            'mainEntity' => ['@type' => 'Person', 'name' => $m['name'], 'jobTitle' => $role,
                'worksFor' => ['@id' => $S . '/#organization']] + ($m['photo'] !== '' ? ['image' => $abs($m['photo'])] : []),
        ], schema_breadcrumb([
            ['name' => 'Home', 'url' => $S . '/'], ['name' => 'About', 'url' => $S . '/about.html'],
            ['name' => 'Our people', 'url' => $S . '/people/'], ['name' => $m['name']],
        ])],
    ]);
    render_nav('people');
    ?>
  <main id="main" tabindex="-1" class="ppl ppl-profile">
    <header class="ppl-phero">
      <div class="avh-topo" data-avh-topo="light" data-seed="11" aria-hidden="true"></div>
      <div class="ppl-wrap">
        <nav class="ppl-crumbs" aria-label="Breadcrumb"><ol class="av-crumbs"><li><a href="/">Home</a><span aria-hidden="true">›</span></li><li><a href="/about.html">About</a><span aria-hidden="true">›</span></li><li><a href="/people/">Our people</a><span aria-hidden="true">›</span></li><li><span aria-current="page"><?= e($m['name']) ?></span></li></ol></nav>
        <div class="ppl-pgrid">
          <div class="ppl-pphoto<?= $m['photo'] === '' ? ' ppl-pphoto--ini' : '' ?>">
            <span class="ppl-pini ppl-tint-<?= av_person_tint($m['name']) ?>" aria-hidden="true"><?= e(av_person_initials($m['name'])) ?></span>
<?php if ($m['photo'] !== ''): ?>            <img class="ppl-tint-<?= av_person_tint($m['name']) ?>" src="<?= e($m['photo']) ?>" alt="Portrait of <?= e($m['name']) ?>" data-ini="<?= e(av_person_initials($m['name'])) ?>" width="600" height="750" />
<?php endif; ?>          </div>
          <div class="ppl-pbody">
            <p class="ppl-eyebrow"><?= e($where) ?></p>
            <h1><?= e($m['name']) ?></h1>
            <p class="ppl-prole"><?= e($role) ?></p>
<?php if ($isVotm): ?>            <p class="ppl-badge"><?= $star ?>Volunteer of the Month, <?= e($monthName((int) $votm['month'], (int) $votm['year'])) ?></p>
<?php endif; ?>
<?php if ($m['location'] !== ''): ?>            <p class="ppl-ploc"><?= $pin ?><?= e($m['location']) ?></p>
<?php endif; ?>
<?php if ($m['tagline'] !== ''): ?>            <blockquote class="ppl-pquote"><p><?= e($m['tagline']) ?></p></blockquote>
<?php endif; ?>
<?php if ($m['bio'] !== ''): ?>            <div class="ppl-pbio"><?= nl2br(e($m['bio'])) ?></div>
<?php endif; ?>
<?php /* The next step. "Get in touch" only when the person chose to list an email or a
         social link; otherwise the way to reach them is through Afrovanguard. */ ?>
            <section class="ppl-next" aria-labelledby="ppl-next-h">
<?php if ($contact['any']): ?>
              <h2 class="ppl-next-h" id="ppl-next-h">Get in touch</h2>
              <div class="ppl-next-row">
<?php if ($contact['mail'] !== ''): ?>                <a class="ppl-btn" href="mailto:<?= e($contact['mail']) ?>">Email <?= e($first) ?></a>
<?php endif; ?>
                <?= $contact['links'] ?>

              </div>
<?php else: ?>
              <h2 class="ppl-next-h" id="ppl-next-h">Want to reach <?= e($first) ?>?</h2>
              <p class="ppl-next-p">Write to Afrovanguard and our team will get back to you.</p>
              <div class="ppl-next-row"><a class="ppl-btn" href="/contact.html">Contact Afrovanguard</a></div>
<?php endif; ?>
            </section>

            <a class="ppl-back" href="/people/" data-ppl-back><span aria-hidden="true">←</span> All our people</a>
          </div>
        </div>
      </div>
    </header>

<?php if ($related): ?>
    <section class="ppl-sec ppl-related" aria-labelledby="ppl-rel-h">
      <div class="ppl-wrap">
        <div class="ppl-sec-h">
          <h2 id="ppl-rel-h">More from <?= e($relLabel) ?></h2>
          <a class="ppl-more" href="/people/#ppl-g-<?= e($place['group']) ?>">See everyone <span aria-hidden="true">→</span></a>
        </div>
        <ul class="ppl-grid">
<?php foreach ($related as $p) echo $card($p, 'h3'); ?>
        </ul>
      </div>
    </section>
<?php endif; ?>
<?php $cta(); ?>
  </main>
  <script src="<?= e(av_asset('/people/people.js')) ?>" defer></script>
<?php
    render_footer();
    return;
}


/* ============================================================ DIRECTORY */
// Auto-enrol @afrovanguard.org.ng members. They are created HIDDEN and stay
// off this page until they opt in (av_team_opt_in; av_team_listable).
av_team_sync_org_members($pdo);
$people = array_map('av_team_member_dict', av_team_public_rows($pdo));   // constitutional order

/* The spotlight: the President and Chief Servant, the Governing Council
   Chairperson and the COO, when the page has them. A directory with none of
   them (every live row today) falls back to anybody the Studio featured, and
   with nobody featured the section is left out. A spotlit person appears only
   there: their section below leaves them out, and the filter chips still find
   them (their card carries their group). */
$spot = [];
foreach ([['executive', 'president'], ['council', 'chair'], ['executive', 'coo']] as [$b, $o]) {
    foreach ($people as $m) { $p = av_team_placement($m); if ($p['body'] === $b && $p['office'] === $o) { $spot[] = $m; break; } }
}
if (!$spot) $spot = array_slice(array_values(array_filter($people, fn($m) => $m['featured'])), 0, 3);
$spotIds = array_flip(array_map(fn($m) => (int) $m['id'], $spot));

/* Everybody counted once, per group: a chip for each group that has anybody. */
$chipCount = [];
foreach (array_keys(AV_TEAM_STRUCTURE) as $g) $chipCount[$g] = 0;
foreach ($people as $m) $chipCount[av_team_placement($m)['group']]++;
$chipCount = array_filter($chipCount);

/* Sorted into the constitutional structure: group → body → people (spotlit people excluded). */
$tree = [];
foreach ($people as $m) { if (isset($spotIds[(int) $m['id']])) continue; $p = av_team_placement($m); $tree[$p['group']][$p['body']][] = $m; }
$tree = array_filter(array_map(fn($g) => $tree[$g] ?? [], array_combine(array_keys(AV_TEAM_STRUCTURE), array_keys(AV_TEAM_STRUCTURE))));
foreach ($tree as $g => $bodies) {   // bodies in map order
    $ordered = [];
    foreach (array_keys(AV_TEAM_STRUCTURE[$g]['bodies']) as $b) if (!empty($bodies[$b])) $ordered[$b] = $bodies[$b];
    $tree[$g] = $ordered;
}

$votm = av_votm($pdo)['votm'] ?? null;
$total = count($people);

/* "How we are organised": the groups in constitutional order, only those with listable
   people, each with how many (everyone once, leaders included) and a link to its section.
   A group whose only people are in Leadership links there. */
$overview = [];
foreach ($chipCount as $g => $n) {
    $overview[$g] = ['label' => $g === 'council' ? AV_TEAM_STRUCTURE[$g]['label'] : AV_TEAM_STRUCTURE[$g]['short'], 'n' => $n,
                     'href' => isset($tree[$g]) ? '#ppl-g-' . $g : '#ppl-leadership'];
}

render_head([
    'title' => 'Our People — The Vanguard Behind the Movement · Afrovanguard',
    'desc'  => 'Meet the people who govern, lead and serve Afrovanguard — raising one million incorruptible leaders for Africa by 2040.',
    'canonical' => $S . '/people/', 'css' => ['/people/people.css'],
    'jsonld' => [schema_org(), schema_website(), [
        '@type' => 'CollectionPage', '@id' => $S . '/people/#webpage', 'url' => $S . '/people/',
        'name' => 'Our People', 'inLanguage' => 'en-NG', 'isPartOf' => ['@id' => $S . '/#website'],
        'about' => ['@id' => $S . '/#organization'],
    ], schema_breadcrumb([
        ['name' => 'Home', 'url' => $S . '/'], ['name' => 'About', 'url' => $S . '/about.html'], ['name' => 'Our people'],
    ])],
]);
render_nav('people');
?>
  <main id="main" tabindex="-1" class="ppl ppl-dir">
    <header class="ppl-hero">
      <div class="avh-topo" data-avh-topo="light" data-seed="11" aria-hidden="true"></div>
      <div class="ppl-wrap">
        <nav class="ppl-crumbs" aria-label="Breadcrumb"><ol class="av-crumbs"><li><a href="/">Home</a><span aria-hidden="true">›</span></li><li><a href="/about.html">About</a><span aria-hidden="true">›</span></li><li><span aria-current="page">Our people</span></li></ol></nav>
        <p class="ppl-eyebrow">Our people</p>
        <h1>The <em>Vanguard</em> Behind the Movement</h1>
        <p class="ppl-intro">Every leader, coordinator and volunteer who gives their time and talent to raise a generation of incorruptible African leaders.</p>
        <div class="ppl-btns">
          <a class="ppl-btn" href="<?= e(PPL_VOLUNTEER_URL) ?>" target="_blank" rel="noopener">Join the team<span aria-hidden="true"> ↗</span><?= $ext ?></a>
        </div>
      </div>
    </header>

<?php if ($votm): $vm = ['id' => $votm['id'], 'name' => $votm['name'], 'photo' => $votm['photo']]; ?>
    <aside class="ppl-votm" aria-labelledby="ppl-votm-h">
      <div class="ppl-wrap ppl-votm-in">
        <?= $avatar($vm, 'ppl-av ppl-av--sm', 96) ?>

        <div class="ppl-votm-b">
          <p class="ppl-votm-k" id="ppl-votm-h"><?= $star ?>Volunteer of the Month<span class="ppl-votm-m"><span aria-hidden="true"> · </span><span class="av-sr">, </span><?= e($monthName((int) $votm['month'], (int) $votm['year'])) ?></span></p>
          <p class="ppl-votm-n"><a href="<?= e(av_person_url($votm)) ?>"><?= e($votm['name']) ?></a><span class="ppl-votm-r"><?= e(av_person_role_line($votm)) ?></span></p>
        </div>
<?php if ($votm['quote'] !== ''): ?>        <blockquote class="ppl-votm-q"><p><?= e($votm['quote']) ?></p></blockquote>
<?php endif; ?>      </div>
    </aside>
<?php endif; ?>

<?php if (!$people): ?>
    <section class="ppl-sec">
      <div class="ppl-wrap"><p class="ppl-none">Our directory is being prepared — check back soon.</p></div>
    </section>
<?php else: ?>
    <nav class="ppl-org" aria-labelledby="ppl-org-h">
      <div class="ppl-wrap ppl-org-in">
        <h2 class="ppl-org-h" id="ppl-org-h">How we are organised</h2>
        <ol class="ppl-org-list">
<?php foreach ($overview as $g => $o): ?>          <li><a href="<?= e($o['href']) ?>" data-ppl-jump="<?= e($g) ?>"><span class="ppl-org-l"><?= e($o['label']) ?></span> <span class="ppl-org-c"><?= (int) $o['n'] ?><span class="av-sr"> <?= $o['n'] === 1 ? 'person' : 'people' ?></span></span></a></li>
<?php endforeach; ?>        </ol>
      </div>
    </nav>

    <div class="ppl-tools-band">
      <div class="ppl-wrap ppl-tools-row">
        <div class="ppl-tools" id="ppl-tools" hidden>
          <div class="ppl-search">
            <label class="av-sr" for="ppl-q">Search people</label>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="search" id="ppl-q" placeholder="Search name, role, directorate or place" autocomplete="off" spellcheck="false" />
          </div>
          <div class="ppl-chips" role="group" aria-label="Show people from">
            <button type="button" class="ppl-chip" data-group="all" aria-pressed="true">Everyone</button>
<?php foreach (array_keys($chipCount) as $g): ?>            <button type="button" class="ppl-chip" data-group="<?= e($g) ?>" aria-pressed="false"><?= e(AV_TEAM_STRUCTURE[$g]['short']) ?></button>
<?php endforeach; ?>          </div>
        </div>
        <div class="ppl-status">
          <p class="ppl-count" id="ppl-count" role="status" aria-live="polite" aria-atomic="true"></p>
          <button type="button" class="ppl-clear" data-ppl-clear hidden>Clear<span class="av-sr"> search and filters</span></button>
        </div>
      </div>
    </div>

<?php if ($spot): ?>
    <section class="ppl-sec ppl-leaders" id="ppl-leadership" aria-labelledby="ppl-lead-h" data-ppl-section>
      <div class="ppl-wrap"><div class="ppl-sec-in">
        <div class="ppl-sec-head">
          <h2 id="ppl-lead-h">Leadership</h2>
        </div>
        <ul class="ppl-lead-grid">
<?php foreach ($spot as $m): $role = av_person_role_line($m); ?>
          <li class="ppl-lead" <?= $dataAttrs($m) ?>>
            <?= $avatar($m, 'ppl-av ppl-av--xl', 240) ?>

            <div class="ppl-lead-b">
              <h3 class="ppl-lead-n"><a href="<?= e(av_person_url($m)) ?>"><?= e($m['name']) ?></a></h3>
              <p class="ppl-lead-r"><?= e($role) ?></p>
<?php if ($m['tagline'] !== ''): ?>              <p class="ppl-lead-t"><?= e($m['tagline']) ?></p>
<?php endif; ?>
              <div class="ppl-lead-f">
                <?= $socials($m, 'ppl-soc') ?>

                <span class="ppl-lead-go" aria-hidden="true">View profile →</span>
              </div>
            </div>
          </li>
<?php endforeach; ?>
        </ul>
      </div></div>
    </section>
<?php endif; ?>

<?php foreach ($tree as $g => $bodies): $G = AV_TEAM_STRUCTURE[$g]; $multi = count($G['bodies']) > 1; ?>
    <section class="ppl-sec ppl-group" id="ppl-g-<?= e($g) ?>" aria-labelledby="ppl-g-<?= e($g) ?>-h" data-ppl-section>
      <div class="ppl-wrap"><div class="ppl-sec-in">
        <div class="ppl-sec-head">
          <h2 id="ppl-g-<?= e($g) ?>-h"><?= e($G['label']) ?></h2>
          <p class="ppl-sec-d"><?= e($G['desc']) ?></p>
        </div>
        <div class="ppl-sec-c">
<?php if (!$multi): ?>
        <ul class="ppl-grid">
<?php foreach (reset($bodies) as $m) echo $card($m, 'h3'); ?>
        </ul>
<?php else: foreach ($bodies as $b => $list): $B = $G['bodies'][$b]; ?>
        <?php /* A div, not a section: a sub-group is not a landmark of its own (and
                 "Registered Trustees" inside "Registered Trustees" would name two alike).
                 ppl-body--nN: it takes N of the section's columns, so small sub-groups sit
                 side by side instead of each starting a new row. */ ?>
        <div class="ppl-body ppl-body--n<?= min(4, count($list)) ?>" id="ppl-b-<?= e($b) ?>" data-ppl-section>
          <div class="ppl-body-h">
            <h3 id="ppl-b-<?= e($b) ?>-h"><?= e($B['label']) ?></h3>
<?php if (!empty($B['desc'])): ?>            <p class="ppl-body-d"><?= e($B['desc']) ?></p>
<?php endif; ?>
          </div>
          <ul class="ppl-grid">
<?php foreach ($list as $m) echo $card($m); ?>
          </ul>
        </div>
<?php endforeach; endif; ?>
        </div>
      </div></div>
    </section>
<?php endforeach; ?>

    <div class="ppl-wrap">
      <div class="ppl-empty" id="ppl-empty" hidden>
        <p>No one matches that search.</p>
        <button type="button" class="ppl-btn ppl-btn--line" data-ppl-reset>Clear search and filters</button>
      </div>
    </div>
<?php endif; ?>

<?php $cta(); ?>
  </main>
  <script src="<?= e(av_asset('/people/people.js')) ?>" defer></script>
<?php render_footer();
