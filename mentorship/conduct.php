<?php
/**
 * mentorship/conduct.php — the one write endpoint for fines and awards
 * (lib/Conduct.php). Plain form posts, answered with a redirect, so it works
 * without JavaScript:
 *
 *   mentor     propose | withdraw            → back to the mentor portal
 *   committee  approve | reject | kind       → back to /mentorship/compliance/
 *   superadmin member_add | member_remove    → back to /mentorship/compliance/
 *
 * Who may do what is decided in Conduct, from the session — never from the
 * form. Same-origin and a CSRF token on every post.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit('POST only.'); }
require_same_origin();
$u = LmsAuth::user();
if (!$u) { header('Location: /login/?next=' . rawurlencode('/mentorship/')); exit; }
if (!av_csrf_valid((string) ($_POST['csrf'] ?? ''))) { http_response_code(403); exit('This page has been open a while — go back, reload and try again.'); }
if (!av_rate_ok('conduct_' . (int) $u['id'], 60, 600)) { http_response_code(429); exit('That is a lot at once. Give it a moment.'); }

$uid = (int) $u['id'];
$op = (string) ($_POST['op'] ?? '');
$back = static function (string $url, array $q): void {
    header('Location: ' . $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($q), true, 303);
    exit;
};

if ($op === 'propose' || $op === 'withdraw') {
    $pid = (int) ($_POST['pairing_id'] ?? 0);
    $to = '/mentorship/mentor/?v=conduct&id=' . $pid . '&kind=' . rawurlencode((string) ($_POST['kind'] ?? 'award'));
    if (!Mentorship::isMentor($uid)) $back($to, ['err' => 'You do not have a mentor profile.']);
    $r = $op === 'propose'
        ? Conduct::propose($uid, $pid, $_POST)
        : Conduct::withdraw($uid, (int) ($_POST['case_id'] ?? 0));
    $back($to, empty($r['ok']) ? ['err' => (string) ($r['error'] ?? 'Not saved.')] : ['done' => $op === 'propose' ? 'proposed' : 'withdrawn']);
}

$to = '/mentorship/compliance/';
switch ($op) {
    case 'approve':
    case 'reject':
        $r = Conduct::decide((int) ($_POST['case_id'] ?? 0), $u, $op === 'approve', (string) ($_POST['note'] ?? ''));
        $back($to, empty($r['ok']) ? ['err' => (string) $r['error']] : ['done' => $op === 'approve' ? 'approved' : 'rejected']);
    case 'kind':
        $r = Conduct::setPairingKind((int) ($_POST['pairing_id'] ?? 0), (string) ($_POST['kind'] ?? ''), $u);
        $back($to . '?tab=pairings', empty($r['ok']) ? ['err' => (string) $r['error']] : ['done' => 'kind']);
    case 'member_add':
        $r = Conduct::addMember((string) ($_POST['email'] ?? ''), $u);
        $back($to . '?tab=committee', empty($r['ok']) ? ['err' => (string) $r['error']] : ['done' => 'added']);
    case 'member_remove':
        $r = Conduct::removeMember((int) ($_POST['user_id'] ?? 0), $u);
        $back($to . '?tab=committee', empty($r['ok']) ? ['err' => (string) $r['error']] : ['done' => 'removed']);
}
http_response_code(400);
echo 'Unknown action.';
