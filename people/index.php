<?php
/**
 * people/index.php — the full team directory and individual profiles.
 *   /people/              → directory (leadership spotlight + filterable grid)
 *   /people/<id>-<slug>/  → a single member's profile (see .htaccess; ?id=N)
 * Server-rendered from the native team data (lib/people.php).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/people.php';

$S = rtrim(SITE_URL, '/');
$pdo = Database::pdo();
$id = (int) ($_GET['id'] ?? 0);

/* ---------- helpers ---------- */
$initials = function (string $n): string {
    $p = preg_split('/\s+/', trim($n));
    $a = $p[0][0] ?? ''; $b = (count($p) > 1) ? ($p[count($p) - 1][0] ?? '') : '';
    return strtoupper($a . $b) ?: '—';
};
$socIcon = [
    'li' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.45 20.45h-3.55v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.45v6.29zM5.34 7.43a2.06 2.06 0 110-4.13 2.06 2.06 0 010 4.13zM7.12 20.45H3.56V9h3.56z"/></svg>',
    'tw' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.24 2.25h3.31l-7.23 8.26 8.5 11.24h-6.66l-5.21-6.82-5.97 6.82H1.66l7.73-8.84L1.25 2.25H8.08l4.71 6.23zm-1.16 17.52h1.83L7.08 4.13H5.12z"/></svg>',
    'ig' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 3.25.15 4.77 1.69 4.92 4.92.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.15 3.23-1.66 4.77-4.92 4.92-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-3.26-.15-4.77-1.7-4.92-4.92C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85C2.38 3.92 3.92 2.38 7.15 2.23 8.42 2.17 8.8 2.16 12 2.16zM12 0C8.74 0 8.33.01 7.05.07 2.7.27.28 2.69.08 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.2 4.36 2.62 6.78 6.98 6.98C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c4.35-.2 6.78-2.62 6.98-6.98.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95C23.73 2.69 21.3.27 16.95.07 15.67.01 15.26 0 12 0zm0 5.84A6.16 6.16 0 1018.16 12 6.16 6.16 0 0012 5.84zM12 16a4 4 0 110-8 4 4 0 010 8zm6.41-11.85a1.44 1.44 0 100 2.88 1.44 1.44 0 000-2.88z"/></svg>',
    'web' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15 15 0 010 20 15 15 0 010-20z"/></svg>',
    'email' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/></svg>',
];
$socHref = fn($k, $v) => $k === 'email' ? 'mailto:' . $v : $v;

/* ============================================================ PROFILE */
if ($id) {
    $res = av_team_one($pdo, $id);
    if (($res['status'] ?? '') !== 'ok') { require_once AV_ROOT . '/lib/errors.php'; av_error_render(404); exit; }
    $m = $res['member'];
    $canonical = $S . av_person_url($m);
    $tier = av_tier_label($m['tier']);
    render_head([
        'title' => $m['name'] . ' — ' . ($m['role'] ?: $tier) . ' · Afrovanguard',
        'desc'  => $m['bio'] ?: ($m['name'] . ' — ' . ($m['role'] ?: $tier) . ' at Afrovanguard.'),
        'canonical' => $canonical, 'og_kind' => 'profile',
        'image' => $m['photo'] ?: null, 'css' => ['/people/people.css'],
    ]);
    render_nav('about');
    $votm = av_votm($pdo);
    $isVotm = $votm['votm'] && (int) $votm['votm']['id'] === (int) $m['id'];
    ?>
  <main id="main-content" class="ppl-profile">
    <div class="container">
      <nav class="crumb" aria-label="Breadcrumb"><a href="<?= $S ?>/about/">About</a> · <a href="/people/">Our People</a> · <span><?= e($m['name']) ?></span></nav>
      <div class="pp-grid" data-reveal>
        <aside class="pp-aside">
          <div class="pp-photo<?= $m['photo'] ? ' has' : '' ?>">
<?php if ($m['photo']): ?>            <img src="<?= e($m['photo']) ?>" alt="<?= e($m['name']) ?>" />
<?php else: ?>            <span class="pp-ini"><?= e($initials($m['name'])) ?></span>
<?php endif; ?>          </div>
<?php if ($isVotm): ?>          <div class="pp-votm">🏆 Volunteer of the Month</div>
<?php endif; ?>
<?php if ($m['socials']): ?>          <div class="pp-socials">
<?php foreach ($m['socials'] as $k => $v): if (!isset($socIcon[$k])) continue; ?>            <a href="<?= e($socHref($k, $v)) ?>"<?= $k === 'email' ? '' : ' target="_blank" rel="noopener"' ?> aria-label="<?= e($k) ?>"><?= $socIcon[$k] ?></a>
<?php endforeach; ?>          </div>
<?php endif; ?>        </aside>
        <div class="pp-body">
          <span class="pp-tier"><?= e($tier) ?><?= $m['operations'] ? ' · Operations' : '' ?></span>
          <h1><?= e($m['name']) ?></h1>
<?php if ($m['role']): ?>          <p class="pp-role"><?= e($m['role']) ?></p>
<?php endif; ?>
<?php if ($m['location']): ?>          <p class="pp-loc">📍 <?= e($m['location']) ?></p>
<?php endif; ?>
<?php if ($m['tagline']): ?>          <p class="pp-tagline">“<?= e($m['tagline']) ?>”</p>
<?php endif; ?>
<?php if ($m['bio']): ?>          <div class="pp-bio"><?= nl2br(e($m['bio'])) ?></div>
<?php endif; ?>
          <a class="btn btn-outline" href="/people/">← Back to the directory</a>
        </div>
      </div>
    </div>
  </main>
<?php
    render_footer();
    exit;
}

/* ============================================================ DIRECTORY */
// Auto-enrol @afrovanguard.org.ng members so they appear here automatically.
av_team_sync_org_members($pdo);
$rows = av_team_rows($pdo, true);
$people = array_map('av_team_member_dict', $rows);
$leaders = array_values(array_filter($people, fn($m) => $m['featured'] && in_array($m['tier'], AV_TEAM_LEAD_TIERS, true)));
$tiersPresent = [];
foreach ($people as $m) { $tiersPresent[$m['tier']] = true; }
$groups = av_team_groups($pdo);   // admin-defined groups, if any

render_head([
    'title' => 'Our People — The Vanguard Behind the Movement · Afrovanguard',
    'desc'  => 'Meet the leadership, team and volunteers powering Afrovanguard — raising one million incorruptible leaders for Africa by 2040.',
    'canonical' => $S . '/people/', 'css' => ['/people/people.css'],
]);
render_nav('about');

/** Render one directory card. */
$card = function (array $m) use ($initials, $socIcon, $socHref) {
    ob_start(); ?>
        <a class="pcard" href="<?= e(av_person_url($m)) ?>" data-name="<?= e(strtolower($m['name'] . ' ' . $m['role'] . ' ' . $m['tier'])) ?>" data-tier="<?= e($m['tier']) ?>" data-grp="<?= e($m['grp']) ?>" data-reveal>
          <div class="pcard-photo<?= $m['photo'] ? ' has' : '' ?>"<?= $m['photo'] ? ' style="background-image:url(\'' . e($m['photo']) . '\')"' : '' ?>>
<?php if (!$m['photo']): ?>            <span><?= e($initials($m['name'])) ?></span>
<?php endif; ?>            <span class="pcard-tier"><?= e(av_tier_label($m['tier'])) ?></span>
          </div>
          <div class="pcard-body">
            <div class="pcard-name"><?= e($m['name']) ?></div>
            <div class="pcard-role"><?= e($m['role'] ?: av_tier_label($m['tier'])) ?></div>
<?php if ($m['tagline']): ?>            <div class="pcard-tag"><?= e($m['tagline']) ?></div>
<?php endif; ?>          </div>
        </a>
<?php return ob_get_clean();
};
?>
  <main id="main-content" class="ppl-dir">
    <section class="ppl-hero">
      <div class="container">
        <p class="ppl-eyebrow">Our People</p>
        <h1>The <em>Vanguard</em> Behind the Movement</h1>
        <p class="ppl-sub">Every leader, coordinator and volunteer who gives their time and talent to raise a generation of incorruptible African leaders.</p>
      </div>
    </section>

    <div class="container">
<?php if ($leaders): ?>
      <div class="ppl-label"><span>Leadership</span></div>
      <div class="pcard-grid spotlight reveal-stagger">
<?php foreach ($leaders as $m) echo $card($m); ?>
      </div>
<?php endif; ?>

<?php if ($people): ?>
      <div class="ppl-label"><span>Full Team Directory</span></div>
      <div class="ppl-controls">
        <div class="ppl-filters" role="group" aria-label="Filter by tier">
          <button class="ppl-pill on" data-f="all">Everyone</button>
<?php foreach (AV_TEAM_TIERS as $t): if (empty($tiersPresent[$t])) continue; ?>          <button class="ppl-pill" data-f="<?= e($t) ?>"><?= e(av_tier_label($t)) ?></button>
<?php endforeach; ?>        </div>
        <input type="search" id="pplSearch" class="ppl-search" placeholder="Search by name or role…" aria-label="Search people" />
      </div>
<?php if ($groups): ?>
      <div class="ppl-filters ppl-groups" role="group" aria-label="Filter by group">
        <button class="ppl-pill ppl-gpill on" data-g="all">All groups</button>
<?php foreach ($groups as $g): ?>        <button class="ppl-pill ppl-gpill" data-g="<?= e($g) ?>"><?= e($g) ?></button>
<?php endforeach; ?>      </div>
<?php endif; ?>
      <div class="pcard-grid" id="pplGrid">
<?php foreach ($people as $m) echo $card($m); ?>
      </div>
      <p class="ppl-empty" id="pplEmpty" hidden>No one matches that search yet.</p>
<?php else: ?>
      <p class="ppl-none">Our directory is being prepared — check back soon.</p>
<?php endif; ?>
    </div>
  </main>

  <script>
  (function(){
    var grid=document.getElementById('pplGrid'); if(!grid) return;
    var cards=[].slice.call(grid.querySelectorAll('.pcard'));
    var empty=document.getElementById('pplEmpty'); var f='all', g='all', q='';
    function apply(){
      var n=0;
      cards.forEach(function(c){
        var ok=(f==='all'||c.getAttribute('data-tier')===f)
             && (g==='all'||c.getAttribute('data-grp')===g)
             && (!q||c.getAttribute('data-name').indexOf(q)!==-1);
        c.style.display=ok?'':'none'; if(ok)n++;
      });
      if(empty) empty.hidden=n!==0;
    }
    document.querySelectorAll('.ppl-pill[data-f]').forEach(function(b){
      b.addEventListener('click',function(){
        document.querySelectorAll('.ppl-pill[data-f]').forEach(function(x){x.classList.remove('on');});
        b.classList.add('on'); f=b.getAttribute('data-f'); apply();
      });
    });
    document.querySelectorAll('.ppl-gpill[data-g]').forEach(function(b){
      b.addEventListener('click',function(){
        document.querySelectorAll('.ppl-gpill[data-g]').forEach(function(x){x.classList.remove('on');});
        b.classList.add('on'); g=b.getAttribute('data-g'); apply();
      });
    });
    var s=document.getElementById('pplSearch');
    if(s) s.addEventListener('input',function(){ q=this.value.trim().toLowerCase(); apply(); });
  })();
  </script>
<?php render_footer();
