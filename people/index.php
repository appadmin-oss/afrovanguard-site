<?php
/**
 * people/index.php — the team directory and individual profiles.
 *   /people/              → directory: hero (+ Volunteer of the Month), one toolbar, a
 *                           spotlight, then one section per constitutional body (AV_TEAM_STRUCTURE), then a call to action
 *   /people/<id>-<slug>/  → a single member's profile (see .htaccess; ?id=N)
 *
 * Server-rendered from the native team data (lib/people.php). Only rows that
 * pass av_team_listable() are ever shown here: curated by hand, or auto-synced
 * AND opted in, and never a system or organisation account. Everything is in
 * the markup, so the page works without script; people/people.js only adds
 * the search and filter (the toolbar stays hidden until it runs).
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

/** The social links row for one person ($cls: the row's class). */
$socials = function (array $m, string $cls) use ($socIcon, $socName, $ext): string {
    $out = '';
    foreach ($m['socials'] as $k => $v) {
        if (!isset($socIcon[$k])) continue;
        $isMail = $k === 'email';
        if ($isMail ? !filter_var($v, FILTER_VALIDATE_EMAIL) : !preg_match('~^https?://~i', $v)) continue;
        $label = $isMail ? ('Email ' . $m['name']) : ($m['name'] . ' on ' . $socName[$k]);
        $out .= '<li><a href="' . e($isMail ? 'mailto:' . $v : $v) . '"' . ($isMail ? '' : ' target="_blank" rel="noopener"')
              . '>' . $socIcon[$k] . '<span class="av-sr">' . e($label) . '</span>' . ($isMail ? '' : $ext) . '</a></li>';
    }
    return $out === '' ? '' : '<ul class="' . $cls . '">' . $out . '</ul>';
};

/** A photo, or the initials on a tint from the palette (decorative either way: the name is right beside it). */
$avatar = function (array $m, string $cls, int $size) : string {
    if ($m['photo'] !== '') {
        return '<img class="' . $cls . '" src="' . e($m['photo']) . '" alt="" width="' . $size . '" height="' . $size . '" loading="lazy" decoding="async" />';
    }
    return '<span class="' . $cls . ' ppl-tint-' . av_person_tint($m['name']) . '" aria-hidden="true">' . e(av_person_initials($m['name'])) . '</span>';
};

/** The text a search matches against. */
$haystack = function (array $m): string {
    $p = av_team_placement($m);
    return mb_strtolower(implode(' ', [$m['name'], av_person_role_line($m), av_team_body_label($p['body']),
        av_team_office_label($p['body'], $p['office']), $m['location']]));
};
/** The data attributes the filter reads: the person (counted once however often shown), their group, the search text. */
$dataAttrs = fn(array $m) => 'data-id="' . (int) $m['id'] . '" data-group="' . e(av_team_placement($m)['group']) . '" data-q="' . e($haystack($m)) . '"';

/** One compact directory card. */
$card = function (array $m, string $hLevel = 'h4') use ($avatar, $dataAttrs): string {
    $role = av_person_role_line($m);
    ob_start(); ?>
          <li class="ppl-card" <?= $dataAttrs($m) ?>>
            <?= $avatar($m, 'ppl-av', 64) ?>

            <div class="ppl-card-b">
              <<?= $hLevel ?> class="ppl-card-n"><a href="<?= e(av_person_url($m)) ?>"><?= e($m['name']) ?></a></<?= $hLevel ?>>
              <p class="ppl-card-r"><?= e($role) ?></p>
<?php if ($m['tagline'] !== ''): ?>              <p class="ppl-card-t"><?= e($m['tagline']) ?></p>
<?php endif; ?>            </div>
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
<?php if ($m['photo'] !== ''): ?>            <img src="<?= e($m['photo']) ?>" alt="Portrait of <?= e($m['name']) ?>" width="600" height="750" />
<?php else: ?>            <span class="ppl-pini ppl-tint-<?= av_person_tint($m['name']) ?>" aria-hidden="true"><?= e(av_person_initials($m['name'])) ?></span>
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
            <?= $socials($m, 'ppl-soc ppl-soc--lg') ?>

            <a class="ppl-back" href="/people/"><span aria-hidden="true">←</span> All our people</a>
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
<?php
    render_footer();
    return;
}


/* ============================================================ DIRECTORY */
// Auto-enrol @afrovanguard.org.ng members. They are created HIDDEN and stay
// off this page until they opt in (av_team_opt_in; av_team_listable).
av_team_sync_org_members($pdo);
$people = array_map('av_team_member_dict', av_team_public_rows($pdo));   // constitutional order

/* Sorted into the constitutional structure: group → body → people. */
$tree = [];
foreach ($people as $m) { $p = av_team_placement($m); $tree[$p['group']][$p['body']][] = $m; }
$tree = array_filter(array_map(fn($g) => $tree[$g] ?? [], array_combine(array_keys(AV_TEAM_STRUCTURE), array_keys(AV_TEAM_STRUCTURE))));
foreach ($tree as $g => $bodies) {   // bodies in map order
    $ordered = [];
    foreach (array_keys(AV_TEAM_STRUCTURE[$g]['bodies']) as $b) if (!empty($bodies[$b])) $ordered[$b] = $bodies[$b];
    $tree[$g] = $ordered;
}
$groupCount = array_map(fn($bodies) => array_sum(array_map('count', $bodies)), $tree);

/* The spotlight: the President and Chief Servant, the Governing Council
   Chairperson and the COO, when the page has them. A directory with none of
   them (every live row today) falls back to anybody the Studio featured, and
   with nobody featured the section is left out. Spotlit people stay in their
   own section too, so the structure below is never missing its head. */
$spot = [];
foreach ([['executive', 'president'], ['council', 'chair'], ['executive', 'coo']] as [$b, $o]) {
    foreach ($people as $m) { $p = av_team_placement($m); if ($p['body'] === $b && $p['office'] === $o) { $spot[] = $m; break; } }
}
if (!$spot) $spot = array_slice(array_values(array_filter($people, fn($m) => $m['featured'])), 0, 3);

$votm = av_votm($pdo)['votm'] ?? null;
$total = count($people);

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
        <div class="ppl-hero-grid<?= $votm ? '' : ' ppl-hero-grid--solo' ?>">
          <div class="ppl-hero-main">
            <p class="ppl-eyebrow">Our people</p>
            <h1>The <em>Vanguard</em> Behind the Movement</h1>
            <p class="ppl-intro">Every leader, coordinator and volunteer who gives their time and talent to raise a generation of incorruptible African leaders.</p>
<?php if ($total): ?>
            <p class="ppl-summary"><span class="av-sr">On this page: </span><b><?= $total ?> <?= $total === 1 ? 'person' : 'people' ?></b><?php foreach ($groupCount as $g => $n): ?><span class="ppl-dot" aria-hidden="true">·</span><span><?= e(AV_TEAM_STRUCTURE[$g]['short']) ?> <?= $n ?></span><?php endforeach; ?></p>
<?php endif; ?>
            <div class="ppl-btns">
              <a class="ppl-btn" href="<?= e(PPL_VOLUNTEER_URL) ?>" target="_blank" rel="noopener">Join the team<span aria-hidden="true"> ↗</span><?= $ext ?></a>
              <a class="ppl-btn ppl-btn--line" href="<?= e(PPL_MEMBER_URL) ?>">Become a member</a>
            </div>
          </div>
<?php if ($votm): $vm = ['id' => $votm['id'], 'name' => $votm['name'], 'photo' => $votm['photo']]; ?>
          <aside class="ppl-votm" aria-labelledby="ppl-votm-h">
            <p class="ppl-votm-k" id="ppl-votm-h"><?= $star ?>Volunteer of the Month<span class="ppl-votm-m"><span class="av-sr">, </span><?= e($monthName((int) $votm['month'], (int) $votm['year'])) ?></span></p>
            <div class="ppl-votm-who">
              <?= $avatar($vm, 'ppl-av ppl-av--lg', 72) ?>

              <div>
                <p class="ppl-votm-n"><a href="<?= e(av_person_url($votm)) ?>"><?= e($votm['name']) ?></a></p>
                <p class="ppl-votm-r"><?= e(av_person_role_line($votm)) ?></p>
              </div>
            </div>
<?php if ($votm['reason'] !== ''): ?>            <p class="ppl-votm-why"><?= e($votm['reason']) ?></p>
<?php endif; ?>
<?php if ($votm['quote'] !== ''): ?>            <blockquote class="ppl-votm-q"><p><?= e($votm['quote']) ?></p></blockquote>
<?php endif; ?>          </aside>
<?php endif; ?>
        </div>
      </div>
    </header>

<?php if (!$people): ?>
    <section class="ppl-sec">
      <div class="ppl-wrap"><p class="ppl-none">Our directory is being prepared — check back soon.</p></div>
    </section>
<?php else: ?>
    <div class="ppl-tools-band">
      <div class="ppl-wrap">
        <div class="ppl-tools" id="ppl-tools" hidden>
          <div class="ppl-search">
            <label class="av-sr" for="ppl-q">Search people</label>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="search" id="ppl-q" placeholder="Search by name, role or place" autocomplete="off" spellcheck="false" />
          </div>
          <div class="ppl-chips" role="group" aria-label="Show people from">
            <button type="button" class="ppl-chip" data-group="all" aria-pressed="true">Everyone <span class="ppl-chip-n"><?= $total ?></span></button>
<?php foreach ($groupCount as $g => $n): ?>            <button type="button" class="ppl-chip" data-group="<?= e($g) ?>" aria-pressed="false"><?= e(AV_TEAM_STRUCTURE[$g]['short']) ?> <span class="ppl-chip-n"><?= $n ?></span></button>
<?php endforeach; ?>          </div>
        </div>
        <p class="ppl-count" id="ppl-count" role="status" aria-live="polite" aria-atomic="true"></p>
      </div>
    </div>

<?php if ($spot): ?>
    <section class="ppl-sec ppl-leaders" id="ppl-leadership" aria-labelledby="ppl-lead-h" data-ppl-section>
      <div class="ppl-wrap">
        <div class="ppl-sec-h">
          <h2 id="ppl-lead-h">Leadership</h2>
        </div>
        <ul class="ppl-lead-grid">
<?php foreach ($spot as $m): $role = av_person_role_line($m); ?>
          <li class="ppl-lead" <?= $dataAttrs($m) ?>>
            <div class="ppl-lead-ph">
<?php if ($m['photo'] !== ''): ?>              <img src="<?= e($m['photo']) ?>" alt="" width="600" height="750" loading="lazy" decoding="async" />
<?php else: ?>              <span class="ppl-lead-ini ppl-tint-<?= av_person_tint($m['name']) ?>" aria-hidden="true"><?= e(av_person_initials($m['name'])) ?></span>
<?php endif; ?>            </div>
            <div class="ppl-lead-b">
              <h3 class="ppl-lead-n"><a href="<?= e(av_person_url($m)) ?>"><?= e($m['name']) ?></a></h3>
              <p class="ppl-lead-r"><?= e($role) ?></p>
<?php if ($m['tagline'] !== ''): ?>              <p class="ppl-lead-t"><?= e($m['tagline']) ?></p>
<?php endif; ?>
              <?= $socials($m, 'ppl-soc') ?>

            </div>
          </li>
<?php endforeach; ?>
        </ul>
      </div>
    </section>
<?php endif; ?>

<?php foreach ($tree as $g => $bodies): $G = AV_TEAM_STRUCTURE[$g]; $multi = count($G['bodies']) > 1; ?>
    <section class="ppl-sec ppl-group" id="ppl-g-<?= e($g) ?>" aria-labelledby="ppl-g-<?= e($g) ?>-h" data-ppl-section>
      <div class="ppl-wrap">
        <div class="ppl-sec-h">
          <h2 id="ppl-g-<?= e($g) ?>-h"><?= e($G['label']) ?></h2>
          <p class="ppl-sec-n"><span data-ppl-n><?= $groupCount[$g] ?></span><span class="av-sr"> <?= $groupCount[$g] === 1 ? 'person' : 'people' ?></span></p>
        </div>
        <p class="ppl-sec-d"><?= e($G['desc']) ?></p>
<?php if (!$multi): ?>
        <ul class="ppl-grid">
<?php foreach (reset($bodies) as $m) echo $card($m, 'h3'); ?>
        </ul>
<?php else: foreach ($bodies as $b => $list): $B = $G['bodies'][$b]; ?>
        <?php /* A div, not a section: a sub-group is not a landmark of its own (and
                 "Registered Trustees" inside "Registered Trustees" would name two alike). */ ?>
        <div class="ppl-body" id="ppl-b-<?= e($b) ?>" data-ppl-section>
          <div class="ppl-body-h">
            <h3 id="ppl-b-<?= e($b) ?>-h"><?= e($B['label']) ?></h3>
            <p class="ppl-sec-n"><span data-ppl-n><?= count($list) ?></span><span class="av-sr"> <?= count($list) === 1 ? 'person' : 'people' ?></span></p>
<?php if (!empty($B['desc'])): ?>            <p class="ppl-body-d"><?= e($B['desc']) ?></p>
<?php endif; ?>
          </div>
          <ul class="ppl-grid">
<?php foreach ($list as $m) echo $card($m); ?>
          </ul>
        </div>
<?php endforeach; endif; ?>
      </div>
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
