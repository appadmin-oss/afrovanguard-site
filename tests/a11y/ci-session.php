<?php
/**
 * tests/a11y/ci-session.php — a signed-in learner for the CI accessibility run.
 *
 * Creates (once) a learner, enrols them in the first published course, and
 * prints a session token for the av_lms cookie, so tests/a11y/axe-ci.js can
 * test the pages only a learner sees: lessons, the quiz, My learning, grades.
 *
 * It writes to the database, so it runs only on a throwaway one: CI=true must
 * be set (GitHub Actions sets it) and the database must be the checkout's own
 * SQLite file. It refuses anywhere else.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
if (getenv('CI') !== 'true') { fwrite(STDERR, "Refusing: CI=true is not set. This writes a test account.\n"); exit(2); }
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
if (getenv('AV_DB_DSN') || getenv('AV_DB_PATH')) { fwrite(STDERR, "Refusing: a database other than the checkout's own is configured.\n"); exit(2); }

$pdo = Database::pdo();
$email = 'a11y.ci@example.test';
$id = (int) ($pdo->query('SELECT id FROM lms_users WHERE email = ' . $pdo->quote($email))->fetchColumn() ?: 0);
if (!$id) {
    $r = LmsAuth::register('Ada Accessibility', $email, bin2hex(random_bytes(12)));
    $id = (int) ($pdo->query('SELECT id FROM lms_users WHERE email = ' . $pdo->quote($email))->fetchColumn() ?: 0);
    if (!$id) { fwrite(STDERR, "Could not create the test learner: " . json_encode($r) . "\n"); exit(1); }
}
$pdo->prepare("UPDATE lms_users SET status = 'active', email_verified = 1 WHERE id = ?")->execute([$id]);
$slug = (string) ($argv[1] ?? 'techome');
$cid = (int) ($pdo->query('SELECT id FROM courses WHERE slug = ' . $pdo->quote($slug))->fetchColumn() ?: 0);
if (!$cid) { fwrite(STDERR, "No course '$slug'.\n"); exit(1); }
(new LmsRepository())->enrol($id, $cid);

$tok = bin2hex(random_bytes(20));
$pdo->prepare('INSERT INTO lms_sessions (token_hash, user_id, ip, ua, expires_at) VALUES (?,?,?,?,?)')
    ->execute([hash('sha256', $tok), $id, '127.0.0.1', 'a11y-ci', gmdate('Y-m-d H:i:s', time() + 3600)]);
echo $tok, "\n";
