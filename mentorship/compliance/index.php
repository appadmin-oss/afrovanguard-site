<?php
/**
 * /mentorship/compliance/ — the Quest desk.
 *
 * Where the compliance committee and superadmins run the Vanguard Quest on the
 * Pyramid Manual: mentors' awards, deductions and fines waiting for a
 * decision; the demotions, suspensions and fines the manual calls for, put to
 * a person; promotions the engine recommends; the register; points against
 * fines by income bracket; and — for superadmins — the catalogue's prices,
 * every rule as a switch, and an AI advisor that proposes and never applies.
 *
 * The shell is the member portal's (portal/avp.css, portal/avp.js): a grouped
 * sidebar, a sticky header, a drawer under 960px, light and dark. Sections come
 * from ONE registry, QuestDesk::SECTIONS, filtered by who is looking; each is a
 * file in ./views/. Every action is a plain form post to /mentorship/conduct.php
 * that works without script; ./desk.js upgrades them in place.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$u = LmsAuth::user();
if (!$u) { header('Location: /login/?next=' . rawurlencode('/mentorship/compliance/')); exit; }
$can = Conduct::canDecide($u);
$superior = Conduct::isSuperior($u);
$edit = QuestRules::canEdit($u);
$csrf = av_csrf_token();
$done = (string) ($_GET['done'] ?? '');
$err = (string) ($_GET['err'] ?? '');
$doneWord = [
    'approved' => 'Approved. It is real now — the points are theirs or the fine is on their account — and both people have been told.',
    'rejected' => 'Rejected. The mentor has been told why.',
    'kind' => 'Saved.', 'added' => 'Added to the committee.', 'removed' => 'Removed from the committee.',
    'rule' => 'Saved. The change is in the catalogue\'s history.', 'rules' => 'Rules saved.',
    'accepted' => 'Done. The member has been told.', 'dismissed' => 'Dismissed, with your reason on the record.',
    'promoted' => 'Promoted. The member and their Quest mentor have been told.', 'deferred' => 'Set aside, with your reason.',
    'journey' => 'Journey level saved.', 'finding' => 'Finding recorded.', 'resolved' => 'Finding resolved. It stays on the record.',
    'bracket' => 'Saved.', 'month' => 'The month has been run.', 'advice' => 'The advisor has answered — below.', 'applied' => 'Applied. It is in the catalogue\'s history.',
];

$ptheme = (($_COOKIE['av_portal_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
render_head([
    'title'      => 'Quest desk — Afrovanguard',
    'desc'       => 'The Vanguard Quest: cases, suggestions, promotions, the register, money, the catalogue and its rules.',
    'canonical'  => rtrim(SITE_URL, '/') . '/mentorship/compliance/',
    'robots'     => 'noindex, nofollow',
    'body_class' => 'portal-app avp qd' . ($ptheme === 'dark' ? ' is-dark' : ''),
    'css'        => ['/assets/site/av-tokens.css', '/portal/portal.css', '/portal/avp.css', '/mentorship/conduct.css', '/mentorship/quest-desk.css'],
    'chrome'     => 'app',
]);

if (!$can): ?>
<main class="avcd-page" id="main-content">
  <header><h1>Quest desk</h1></header>
  <section class="avcd-box"><p class="avcd-p">This desk is for the compliance committee and superadmins. If you should be on the committee, ask a superadmin to add you.</p></section>
</main>
</body>
</html>
<?php exit; endif;

$sections = QuestDesk::sectionsFor($u);
$ov = QuestDesk::overview();
$badge = ['cases' => $ov['waiting'], 'suggestions' => $ov['suggestions']];
$groups = [];
foreach ($sections as $id => $s) $groups[$s['group']][$id] = $s;
$icons = [
    'overview' => 'M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z',
    'cases' => 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
    'suggestions' => 'M12 2a7 7 0 0 0-4 12.7V18h8v-3.3A7 7 0 0 0 12 2zM9 22h6',
    'promotions' => 'M12 19V5M5 12l7-7 7 7',
    'members' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    'money' => 'M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
    'catalogue' => 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5zM4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5',
    'rules' => 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6',
    'advisor' => 'M12 3l1.9 5.8L20 10l-6.1 1.2L12 17l-1.9-5.8L4 10l6.1-1.2z',
    'people' => 'M12 3l8 4v5c0 4.5-3.4 8.4-8 9-4.6-.6-8-4.5-8-9V7zM9 12l2 2 4-4',
];
$parts = preg_split('/\s+/', trim((string) $u['name'])) ?: [];
$initials = strtoupper(substr((string) ($parts[0] ?? 'A'), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1)) ?: 'A';
?>
<div class="avp-app">
  <aside class="avp-side" id="avpSide" aria-label="Quest desk sections">
    <a class="avp-brand" href="/mentorship/compliance/" aria-label="Quest desk">
      <span class="avp-mark" aria-hidden="true">Q</span>
      <span class="avp-brand-t"><span class="avp-brand-n">Vanguard Quest</span><span class="avp-brand-s">Quest desk</span></span>
    </a>
    <div class="avp-search-wrap">
      <button class="avp-search" type="button" data-cmdk="cmd">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <span class="avp-search-t">Go to…</span>
        <span class="avp-keys"><kbd class="cmdk-mod">Ctrl</kbd><kbd>K</kbd></span>
      </button>
    </div>
    <nav class="avp-nav" aria-label="Sections">
<?php foreach ($groups as $g => $items): ?>
      <div class="avp-nav-group">
        <div class="avp-nav-title"><?= e($g) ?></div>
<?php foreach ($items as $id => $s): $b = (int) ($badge[$id] ?? 0); ?>
        <a class="avp-nav-link pnav-link" href="#<?= e($id) ?>" data-view="<?= e($id) ?>">
          <svg class="qd-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= e($icons[$id] ?? '') ?>"/></svg>
          <span class="avp-nav-label pnav-label"><?= e($s['label']) ?></span>
<?php if ($b > 0): ?>          <span class="avp-nav-badge pnav-badge" data-badge="<?= e($id) ?>"><?= $b ?></span>
<?php endif; ?>        </a>
<?php endforeach; ?>
      </div>
<?php endforeach; ?>
      <div class="avp-nav-group">
        <div class="avp-nav-title">More</div>
        <a class="avp-nav-link" href="/mentorship/mentor/"><span class="avp-dot avp-dot--gray" aria-hidden="true"></span><span class="avp-nav-label">Mentor portal</span><span class="avp-ext" aria-hidden="true">↗</span></a>
        <a class="avp-nav-link" href="/portal/#quest"><span class="avp-dot avp-dot--gray" aria-hidden="true"></span><span class="avp-nav-label">Your own Quest</span><span class="avp-ext" aria-hidden="true">↗</span></a>
<?php if ($superior): ?>        <a class="avp-nav-link" href="/admin/"><span class="avp-dot avp-dot--gray" aria-hidden="true"></span><span class="avp-nav-label">Studio</span><span class="avp-ext" aria-hidden="true">↗</span></a>
<?php endif; ?>
      </div>
    </nav>
    <div class="avp-me">
      <span class="avp-me-av" aria-hidden="true"><?= e($initials) ?></span>
      <span class="avp-me-t"><span class="avp-me-n"><?= e((string) $u['name']) ?></span><span class="avp-me-r"><?= $superior ? 'Superadmin' : 'Compliance committee' ?></span></span>
      <button type="button" class="avp-icon-btn" id="portalTheme" aria-pressed="<?= $ptheme === 'dark' ? 'true' : 'false' ?>" aria-label="Dark mode" title="Dark mode">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
      </button>
    </div>
  </aside>
  <div class="avp-scrim" id="avpScrim" hidden></div>

  <main class="avp-main" id="main-content" tabindex="-1">
    <header class="avp-top">
      <button type="button" class="avp-icon-btn avp-burger" id="avpBurger" aria-label="Open sections" aria-controls="avpSide" aria-expanded="false">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
      </button>
      <div class="avp-crumb"><span class="avp-crumb-root">Quest desk</span><span class="avp-crumb-sep" aria-hidden="true">/</span><span class="avp-crumb-here" id="crumbHere" aria-live="polite">Overview</span></div>
      <div class="avp-top-actions">
        <span class="qd-who"><?= $edit ? 'You can change prices and rules' : 'You decide; a superadmin sets prices' ?></span>
      </div>
    </header>

    <div class="avp-page qd-page" data-csrf="<?= e($csrf) ?>">
<?php if ($done !== '' && isset($doneWord[$done])): ?>
      <p class="qd-flash is-ok" role="status"><?= e($doneWord[$done]) ?><?php if ($done === 'month'): ?> <?= (int) ($_GET['ran'] ?? 0) ?> member(s), ₦<?= number_format((int) ($_GET['offset'] ?? 0)) ?> offset.<?php endif; ?></p>
<?php elseif ($err !== ''): ?>
      <p class="qd-flash is-bad" role="alert"><?= e($err) ?></p>
<?php endif; ?>
      <p class="qd-flash" id="qdToast" role="status" aria-live="polite" hidden></p>
<?php
foreach (array_keys($sections) as $sid) {
    $title = $sections[$sid]['title'];
    require __DIR__ . '/views/' . $sid . '.php';
}
?>
    </div>
  </main>
</div>
  <script src="<?= e(av_asset('/portal/avp.js')) ?>" defer></script>
  <script type="application/json" id="cmdk-data"><?= json_encode([
      'navFrom' => '.pnav-link[data-view]',
      'actions' => array_values(array_filter([
          ['label' => 'Decide the waiting cases', 'href' => '#cases', 'sub' => $ov['waiting'] . ' waiting'],
          ['label' => 'Run last month\'s points against fines', 'href' => '#money', 'sub' => 'Section 3 of the manual'],
          $edit ? ['label' => 'Ask the AI advisor', 'href' => '#advisor', 'sub' => 'Optimise, strategise, update, rethink'] : null,
          $edit ? ['label' => 'Change a price', 'href' => '#catalogue', 'sub' => 'Awards, deductions, fines'] : null,
      ])),
  ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script src="<?= e(av_asset('/portal/palette.js')) ?>" defer></script>
  <script src="<?= e(av_asset('/mentorship/compliance/desk.js')) ?>" defer></script>
</body>
</html>
