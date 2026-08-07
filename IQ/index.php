<?php
/**
 * IQ/index.php — Incorruptible Quiz hub. Public quizzes + games + leaderboard.
 * Anyone can play; signing in saves scores and earns badges. Single page:
 * iq.js switches between the Quizzes list, a Quiz player, the Games, and the
 * Leaderboard. Served at /IQ/.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';

$u    = LmsAuth::user();
$name = $u ? explode(' ', trim((string) $u['name']))[0] : '';
$csrf = av_csrf_token();
$isAdmin = $u && class_exists('Community') && Community::isAdmin((int) $u['id']);

// Lightweight, best-effort stats for the hero (never fatal).
$quizCount = 0; $playCount = 0;
try {
    $qs = IQ::listQuizzes();
    $quizCount = count($qs);
    foreach ($qs as $q) { $playCount += (int) ($q['plays'] ?? 0); }
} catch (Throwable $e) { /* leave zeros */ }

render_head([
    'title'     => 'IQ — Incorruptible Quiz · Quizzes & Games — Afrovanguard',
    'desc'      => 'Test what you know and sharpen your thinking with Afrovanguard’s Incorruptible Quiz — quizzes, games and a leaderboard. Free to play.',
    'canonical' => rtrim(SITE_URL, '/') . '/IQ/',
    'body_class' => 'iq-page',
    'css'       => ['/IQ/iq.css'],
]);
render_nav('academy');
?>
<main id="main-content" class="iq" data-csrf="<?= e($csrf) ?>" data-signed-in="<?= $u ? '1' : '0' ?>" data-name="<?= e($name) ?>">
  <header class="iq-hero">
    <div class="iq-in">
      <div class="iq-emblem" aria-hidden="true">🛡️</div>
      <p class="iq-kicker">Incorruptible Quiz</p>
      <h1>Sharpen your mind.<br>Prove your integrity.</h1>
      <p class="iq-lead">Free quizzes and brain games from Afrovanguard. Play instantly — sign in to save your score, climb the leaderboard and earn Incorruptible badges.</p>
      <div class="iq-stats" role="list">
        <div class="iq-stat" role="listitem"><b><?= (int) $quizCount ?></b><span>Quiz<?= $quizCount === 1 ? '' : 'zes' ?></span></div>
        <div class="iq-stat" role="listitem"><b><?= number_format((int) $playCount) ?></b><span>Plays</span></div>
        <div class="iq-stat" role="listitem"><b id="iqStatGames">5</b><span>Games</span></div>
        <div class="iq-stat" role="listitem"><b>Free</b><span>To play</span></div>
      </div>
    </div>
  </header>

  <div class="iq-tabwrap">
    <nav class="iq-tabrow" role="tablist" aria-label="IQ sections">
      <div class="iq-tabs">
        <button class="iq-tab is-on" data-tab="quizzes" role="tab" aria-selected="true">Quizzes</button>
        <button class="iq-tab" data-tab="games" role="tab" aria-selected="false">Games</button>
        <button class="iq-tab" data-tab="leaderboard" role="tab" aria-selected="false">Leaderboard</button>
      </div>
<?php if ($isAdmin): ?>      <a class="iq-tab iq-tab--admin" href="/IQ/admin.php">✎ Author</a>
<?php endif; ?>
    </nav>
  </div>

  <div class="iq-in iq-body">
    <!-- QUIZZES -->
    <section class="iq-view" id="iqQuizzes">
      <div class="iq-filter" id="iqFilter"></div>
      <div class="iq-grid" id="iqList"><p class="iq-empty">Loading quizzes…</p></div>
    </section>

    <!-- PLAYER -->
    <section class="iq-view" id="iqPlayer" hidden></section>

    <!-- GAMES -->
    <section class="iq-view" id="iqGames" hidden>
      <div class="iq-grid" id="iqGamesGrid"><p class="iq-empty">Loading games…</p></div>
      <div id="iqGameStage" hidden></div>
    </section>

    <!-- LEADERBOARD -->
    <section class="iq-view" id="iqLeaderboard" hidden>
      <div class="iq-lb-head"><h2>Global leaderboard</h2><p>Total points across every quiz. Sign in so your scores count.</p></div>
      <div id="iqBoard"><p class="iq-empty">Loading…</p></div>
    </section>
  </div>
</main>

<script src="/IQ/iq.js" defer></script>
<?php render_footer(); ?>
