<?php
/**
 * mentorship/mentor/index.php — the mentor portal.
 *
 * One front controller, one shell, one view per screen. The URL IS the state:
 * ?v= which screen, ?id= which pairing, ?q=&f=&s=&p= the roster's search,
 * filter, sort and page. Nothing the mentor can see is unreachable by link,
 * which is what makes the back button, a shared URL and a bookmark all work
 * without a line of code about any of them.
 *
 * Every view renders on the server. avm.js adds the inline forms, the bulk
 * bar and the keyboard shortcuts on top; with JavaScript off, every screen
 * still reads and every link still goes somewhere.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$u = LmsAuth::user();
if (!$u) { header('Location: /login?next=' . rawurlencode('/mentorship/mentor/'), true, 302); exit; }

$uid = (int) $u['id'];
if (!Mentorship::isMentor($uid)) {
    render_head([
        'title' => 'Mentor portal — Afrovanguard', 'robots' => 'noindex, nofollow',
        'canonical' => rtrim(SITE_URL, '/') . '/mentorship/mentor/',
        'css' => ['/assets/site/avm.css'],
    ]);
    render_nav('mentorship');
    ?>
  <main id="main" tabindex="-1" class="avm" style="display:block">
    <div class="avm-body" style="max-width:620px;margin:0 auto">
      <section class="avm-panel">
        <div class="avm-h2row"><h2>The mentor portal</h2></div>
        <p style="margin:0;font-size:15px;line-height:1.6;color:var(--av-text-2)">This is where mentors keep their mentees, their sessions and their training. Publish a mentor profile and it opens for you.</p>
        <div><a class="avm-btn avm-btn--ink" href="/mentorship/#become">Become a mentor</a></div>
      </section>
    </div>
  </main>
<?php
    render_footer();
    exit;
}

$portal = new MentorPortal($uid);

/* ── Which screen ──────────────────────────────────────────────────────── */
$VIEWS = ['today', 'mentees', 'case', 'values', 'checkins', 'reflections', 'requests', 'academy', 'module', 'profile', 'support', 'concern'];
$v  = (string) ($_GET['v'] ?? 'today');
if (!in_array($v, $VIEWS, true)) $v = 'today';

$q  = trim((string) ($_GET['q'] ?? ''));
$f  = (string) ($_GET['f'] ?? 'all');
$s  = (string) ($_GET['s'] ?? 'wait');
$p  = max(1, (int) ($_GET['p'] ?? 1));
$id = (int) ($_GET['id'] ?? 0);

/* ── "Show 25 more": the next page of rows, and nothing else ───────────────
   The same renderer the full page uses, so a row appended by JavaScript and a
   row drawn by the server cannot drift apart. */
if (($_GET['partial'] ?? '') === 'rows' && $v === 'mentees') {
    header('Content-Type: text/html; charset=utf-8');
    $roster = $portal->roster($q, $f, $s, $p, true);
    foreach ($roster['rows'] as $row) require __DIR__ . '/views/_row.php';
    exit;
}

/* ── The goals panel, after a goal was added, reworded, met or set aside ───
   Same renderer as the case file, for the same reason: a goal the browser
   drew and a goal the server drew cannot drift apart if only one of them
   knows how to draw it. */
if (($_GET['partial'] ?? '') === 'goals' && $v === 'case') {
    $c = $portal->caseFile($id);
    if (!$c) { http_response_code(404); exit; }
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/views/_case_goals.php';
    exit;
}

$HEADS = [
    'today'       => ['Today', 'Your next session, and anything waiting on you.'],
    'mentees'     => ['Mentees', 'The case file for each pairing: goals, sessions, timeline and messages.'],
    'case'        => ['Mentee', 'Their goals, sessions, values and messages.'],
    'values'      => ['Values', 'Observe each mentee against the seven values of the Vanguard Quest.'],
    'checkins'    => ['Check-ins', 'Week 1, 2 and 4, then monthly.'],
    'reflections' => ['Reflections', 'Diary entries your mentees shared with you.'],
    'requests'    => ['Requests', 'Members asking you to mentor them.'],
    'academy'     => ['Academy', 'What you need before accepting anyone, and what helps after.'],
    'module'      => ['Academy', 'Lessons, one practice scenario, then the quiz.'],
    'profile'     => ['Profile', 'How you appear to a member looking for a mentor.'],
    'support'     => ['Support', 'Your coordinator, and how to report a concern.'],
    'concern'     => ['Report a concern', 'It goes straight to the safeguarding lead.'],
];

$badges = $portal->navBadges();
$me     = $portal->me();
$case   = null;
if ($v === 'case') {
    $case = $portal->caseFile($id);
    if (!$case) { http_response_code(404); $v = 'mentees'; }
}
[$title, $subtitle] = $HEADS[$v];
if ($case) { $title = $case['name']; $subtitle = $case['where'] !== '' ? $case['where'] : 'No track set'; }

render_head([
    'title'     => $title . ' — Mentor portal',
    'desc'      => 'The Afrovanguard mentor portal.',
    'canonical' => rtrim(SITE_URL, '/') . '/mentorship/mentor/',
    'robots'    => 'noindex, nofollow',
    'csrf'      => true,
    'css'       => ['/assets/site/avm.css', '/assets/site/avm-portal.css'],
    'body_class'=> 'avm-page',
    'chrome'     => 'app', /* its own shell and theme; not the Home chrome */
]);

/** One query-string, with some keys changed. Keeps the roster's state across links. */
function avm_url(array $over = []): string {
    $keep = array_intersect_key($_GET, array_flip(['v', 'id', 'q', 'f', 's', 'p', 'tab', 'key']));
    $out = array_filter(array_merge($keep, $over), fn($x) => $x !== null && $x !== '');
    return '?' . http_build_query($out);
}

/** A nav entry. $badge 0 renders nothing — an empty gold pill reads as a zero. */
function avm_nav(string $key, string $label, string $icon, int $badge, string $current): void { ?>
      <a class="avm-navlink" href="?v=<?= e($key) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>>
        <?= Icons::mentorPortal($icon) ?><span><?= e($label) ?></span>
<?php if ($badge > 0): ?>        <span class="avm-badge"><?= $badge ?><span class="av-sr"> waiting</span></span>
<?php endif; ?>      </a>
<?php }
?>
<div class="avm">
  <aside class="avm-side">
    <a class="avm-brand" href="/mentorship/mentor/">
      <span class="avm-av avm-av--ink" aria-hidden="true">AV</span>
      <span><b>Afrovanguard</b><small>Mentor portal</small></span>
    </a>
    <nav class="avm-nav" aria-label="Mentor portal">
      <div class="avm-navgroup">
        <span>Work</span>
<?php
avm_nav('today', 'Today', 'today', $badges['today'], $v);
avm_nav('mentees', 'Mentees', 'people', 0, $v === 'case' ? 'mentees' : $v);
avm_nav('values', 'Values', 'star', $badges['values'], $v);
avm_nav('checkins', 'Check-ins', 'check', $badges['checkins'], $v);
avm_nav('reflections', 'Reflections', 'book', $badges['reflections'], $v);
avm_nav('requests', 'Requests', 'inbox', $badges['requests'], $v);
?>
      </div>
      <div class="avm-navgroup">
        <span>Grow</span>
<?php
avm_nav('academy', 'Academy', 'cap', $badges['academy'], $v === 'module' ? 'academy' : $v);
avm_nav('profile', 'Profile', 'person', 0, $v);
?>
      </div>
      <div class="avm-navgroup">
        <span>Help</span>
<?php avm_nav('support', 'Support', 'help', 0, $v); ?>
      </div>
    </nav>
    <a class="avm-report" href="?v=concern">Report a concern</a>
    <div class="avm-me">
      <span class="avm-av" aria-hidden="true"><?= e($me['initials']) ?></span>
      <span><b><?= e($me['name']) ?></b><small<?= $me['cleared'] ? '' : ' style="color:var(--av-gold-text)"' ?>><?= e($me['academy_line']) ?></small></span>
    </div>
  </aside>

  <main class="avm-main" id="main-content">
    <header class="avm-head">
      <div class="avm-head-txt">
        <h1><?= e($title) ?></h1>
        <p><?= e($subtitle) ?></p>
      </div>
      <a class="avm-warn" href="?v=concern" aria-label="Report a concern"><span aria-hidden="true">⚠</span></a>
      <button type="button" class="avm-btn avm-btn--ink avm-sched" data-avm-open="sched">+ Schedule a session</button>
    </header>

    <div class="avm-body">
      <p class="avm-offline" data-avm-offline hidden role="status">You are offline. You can read, but nothing will save until you reconnect.</p>
<?php
$view = __DIR__ . '/views/' . $v . '.php';
require is_file($view) ? $view : __DIR__ . '/views/today.php';
?>
    </div>
  </main>
<?php require __DIR__ . '/views/_tabbar.php'; ?>
</div>

<?php require __DIR__ . '/views/_dialogs.php'; ?>

<div class="avm-toast" data-avm-toast hidden><span></span><button type="button" hidden>Undo</button></div>
<script src="/assets/site/avm.js" defer></script>
<script src="/assets/site/avm-extra.js" defer></script>
</body>
</html>
