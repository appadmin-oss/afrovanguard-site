<?php
/**
 * STS · newsletter unsubscribe — one-click via signed token from welcome email.
 *
 * GET /api/unsubscribe.php?token=...  → flips subscriber status to 'unsubscribed'
 * and returns a friendly HTML page (no rebuild dependency).
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
$ok = false;
$email = '';

if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT email FROM newsletter_subscribers WHERE unsubscribe_token = :t LIMIT 1");
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();
        if ($row) {
            $email = (string)$row['email'];
            $pdo->prepare("UPDATE newsletter_subscribers SET status = 'unsubscribed' WHERE unsubscribe_token = :t")
                ->execute([':t' => $token]);
            $ok = true;
        }
    } catch (Throwable $e) {
        log_line('db', 'unsubscribe failed', ['err' => $e->getMessage()]);
    }
}

$site = env('SITE_URL', 'https://streettostardom.org');
$title = $ok ? 'Unsubscribed' : 'Token not recognised';
$body = $ok
    ? "<p>You won't receive any more newsletter emails from Street-To-Stardom" . ($email ? ' at <strong>' . htmlspecialchars($email) . '</strong>' : '') . ".</p><p>If this was a mistake, you can resubscribe from any page footer.</p>"
    : "<p>We couldn't find a matching subscription. The link may have expired or already been used.</p><p>If you're still receiving emails, reply 'unsubscribe' to any one of them and we'll handle it manually.</p>";

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= $title ?> — Street-To-Stardom</title>
  <style>
    body { font: 16px/1.55 -apple-system, system-ui, Inter, sans-serif; color: #0A0A0F; background: #FAFAF8; padding: 64px 24px; }
    .box { max-width: 520px; margin: 0 auto; background: #fff; border: 1px solid #ECECEC; border-radius: 12px; padding: 40px; }
    h1 { font-family: Montserrat, system-ui, sans-serif; font-weight: 600; font-size: 28px; margin: 0 0 16px; letter-spacing: -0.02em; }
    p { color: #6B6B70; margin: 0 0 12px; }
    a.btn { display: inline-flex; align-items: center; gap: 6px; margin-top: 24px; padding: 10px 18px; background: #0732F7; color: #fff; border-radius: 999px; font-weight: 600; text-decoration: none; font-size: 14px; }
  </style>
</head>
<body>
  <div class="box">
    <h1><?= htmlspecialchars($title) ?></h1>
    <?= $body ?>
    <a href="<?= htmlspecialchars($site) ?>" class="btn">Back to streettostardom.org →</a>
  </div>
</body>
</html>
