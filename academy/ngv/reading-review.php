<?php
/**
 * academy/ngv/reading-review.php — the reading review desk, on its own.
 *
 * Every member-facing string in the 24-book challenge says the TRACK LEAD
 * checks the book. There was no track lead in the code: the only door to
 * NgvReading::review() was the NGV console, gated on `admin`/`superadmin`.
 * So either a track lead was handed full NGV admin — the roster, the fines
 * desk, the ledger, the offline payments queue — or the reading work
 * funnelled to the two or three people who had it. The second is what
 * happened, and it is why nobody noticed that three spoken checks in four
 * were never opening.
 *
 * This page is the grant made real: the claims to check, the rejections that
 * can be reopened, and the conversations that are due — and nothing else.
 * NgvReading::canReview() is the one gate, and it answers for an NGV admin
 * and for a member granted the review on the book list page.
 *
 * The desk itself is academy/ngv/_reading-review.php, shared with the console
 * so there is one of it rather than two that drift.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$uid  = (int) (LmsAuth::user()['id'] ?? 0);
$may  = NgvReading::canReview($uid);
$role = function_exists('av_admin_role') ? av_admin_role() : '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$may) json_out(['ok' => false, 'error' => 'You are not one of the people who check the reading.'], 403);
    require_same_origin();
    av_csrf_require();
    $in  = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $act = (string) ($in['action'] ?? '');
    if ($act === 'book_review') {
        $r = NgvReading::review((int) ($in['claim_id'] ?? 0), (string) ($in['verdict'] ?? ''), $uid, (string) ($in['note'] ?? ''));
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    /* Courses are checked on the same desk, by the same people, because
       "who may check learning" is one question (NgvCourses::review). */
    if ($act === 'course_review') {
        $r = NgvCourses::review((int) ($in['claim_id'] ?? 0), (string) ($in['verdict'] ?? ''), $uid, (string) ($in['note'] ?? ''));
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    if ($act === 'course_reopen') {
        $r = NgvCourses::reopen((int) ($in['claim_id'] ?? 0), $uid, (string) ($in['note'] ?? ''));
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    if ($act === 'book_spot') {
        $r = NgvReading::spotRecord((int) ($in['spot_id'] ?? 0), (string) ($in['outcome'] ?? ''), $uid, (string) ($in['note'] ?? ''));
        json_out($r, empty($r['ok']) ? 400 : 200);
    }
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$e    = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$nToCheck = $may ? NgvReading::queueCount() : 0;
$nToAsk   = $may ? NgvReading::spotCount() : 0;
$nCourses = $may ? NgvCourses::queueCount() : 0;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Reading &amp; courses review · NGV</title>
<link href="<?= e(av_asset('/assets/site/fonts.css')) ?>" rel="stylesheet" />
<?php require __DIR__ . '/_staff-style.php'; ?>
<style>
/* The review desk's own cards, which the console already styles for itself. */
.bkq,.spq{border:1px solid var(--line);border-radius:var(--r);padding:14px 16px;margin-bottom:12px}
.bkq--flagged{border-left:3px solid var(--danger)}
.bkq-head{display:flex;gap:10px;align-items:flex-start;margin-bottom:8px}
.bkq-who{font-weight:800}
.bkq-book{font-size:.9rem;margin-top:2px}
.bkq-flags{margin:.4rem 0;padding-left:1.1rem;color:var(--danger);font-size:.86rem}
.bkq-read{margin:.5rem 0}
.bkq-read summary{cursor:pointer;font-weight:700;font-size:.88rem}
.bkq-text,.bkq-chapters{font-size:.92rem;line-height:1.6;margin:.5rem 0;white-space:normal}
.bkq-chapters li{margin-bottom:.5rem}
.bkq-take{font-size:.9rem;margin:.4rem 0}
.bkq-act,.spq-act{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}
/* min-width, not just a basis: the course row's three buttons are wider than
   the book row's, and without it the reason box was crushed to "Why — requi".
   It wraps to its own line instead. */
.bkq-note,.spq-note{flex:1 1 260px;min-width:260px;min-height:40px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;font:inherit}
.bkq-note:focus,.spq-note:focus{outline:3px solid var(--orange);outline-offset:1px}
.btn.ok{background:var(--green);color:#fff;border-color:var(--green)}
.btn.danger{background:var(--danger);color:#fff;border-color:var(--danger)}
.spq-prompts{margin:.4rem 0 0;padding-left:1.1rem;font-size:.9rem}
.bkq-sits{font-size:.86rem;margin:.35rem 0;color:var(--muted)}
.bkq-sits strong{color:var(--ink)}
.bkq-sits-none{color:var(--danger);font-weight:700}
.bkq-val{display:inline-block;margin-left:6px;padding:0 8px;border-radius:999px;background:#fff3cd;color:#6b4e00;font-size:.76rem;font-weight:800}
</style>
</head>
<body>
<?php if (!$may): ?>
  <div class="gatebox">
    <h1>Not your desk</h1>
    <p>This page is for the people who check the 24-book challenge. If you should be one of them,
       an NGV admin adds you on the <a href="/academy/ngv/books.php#reviewers">book list page</a>.</p>
    <p><a href="/portal/">Back to the portal →</a></p>
  </div>
<?php else: ?>
<header class="top">
  <h1><b>NextGen Vanguard</b> <span>· reading &amp; courses review</span></h1>
  <span class="sp"></span>
  <?php if ($isAdmin): ?>
    <a href="/academy/ngv/members.php">Vanguards</a>
    <a href="/academy/ngv/books.php">Book list</a>
  <a href="/academy/ngv/courses.php">Course list</a>
  <?php endif; ?>
  <a href="/academy/ngv/reading-review.php" aria-current="page">Reading review</a>
  <span class="msg" id="msg" role="status" aria-live="polite"></span>
</header>
<main class="wrap">
  <section class="stats" aria-label="What is waiting">
    <div class="stat"><div class="k">Books to check</div><div class="v"><?= (int) $nToCheck ?></div><div class="s">nothing counts until you say so</div></div>
    <div class="stat"><div class="k">Courses to check</div><div class="v"><?= (int) $nCourses ?></div><div class="s">the same floor: the work, not the certificate</div></div>
    <div class="stat"><div class="k">Conversations due</div><div class="v"><?= (int) $nToAsk ?></div><div class="s">one every <?= (int) NgvReading::SPOT_EVERY ?> verified books</div></div>
  </section>

  <?php require __DIR__ . '/_reading-review.php'; ?>
</main>
<?php endif; ?>
</body>
</html>
