<?php
/**
 * tests/security.test.php — the security audit's regression checks.
 *
 * Each block pins one fix: the path that used to be open, shown closed.
 */
declare(strict_types=1);

/* ══ 1. Uploads are stored under the extension their bytes earn ══════════
   /uploads/ is in the web root. A real PNG named "shell.php" passed the MIME
   check and was saved — and so served, and run — as .php. */
ck('upload: a PNG named .php is stored as .png', Storage::safeExt('image/png', 'shell.php') === 'png');
ck('upload: a JPEG named .phtml is stored as .jpg', Storage::safeExt('image/jpeg', 'x.phtml') === 'jpg');
ck('upload: text named .php is stored as .txt', Storage::safeExt('text/plain', 'x.php') === 'txt');
ck('upload: an unknown type with a script name becomes .bin', Storage::safeExt('application/octet-stream', 'x.php') === 'bin');
ck('upload: an unknown type keeps an inert audio extension', Storage::safeExt('application/octet-stream', 'talk.mp3') === 'mp3');
ck('upload: svg is not kept as svg', Storage::safeExt('image/svg+xml', 'a.svg') === 'bin');

$secPng = tempnam(sys_get_temp_dir(), 'secpng');
$secIm = imagecreatetruecolor(4, 4); imagepng($secIm, $secPng); imagedestroy($secIm);
file_put_contents($secPng, (string) file_get_contents($secPng) . '<?php echo "pwned"; ?>');
$secPut = Storage::put($secPng, 'shell.php', 'image', 'sectest');
ck('upload: the local fallback never writes a .php file', ($secPut['provider'] ?? '') !== 'local' || str_ends_with((string) $secPut['url'], '.png'));
if (($secPut['provider'] ?? '') === 'local') @unlink(AV_ROOT . $secPut['url']);
@rmdir(AV_ROOT . '/uploads/sectest'); @unlink($secPng);
ck('upload: /uploads/ carries a no-execute .htaccess', is_file(AV_ROOT . '/uploads/.htaccess')
    && str_contains((string) file_get_contents(AV_ROOT . '/uploads/.htaccess'), 'Require all denied')
    && str_contains((string) file_get_contents(AV_ROOT . '/uploads/.htaccess'), 'php[0-9]?'));
ck('upload: the shipped .htaccess matches the one written at runtime',
    (string) file_get_contents(AV_ROOT . '/uploads/.htaccess') === Storage::UPLOADS_HTACCESS);

/* ══ 2. Command-line tools, tests and vendor code are not web endpoints ══
   scripts/seed-mentor-200.php seeds 200 accounts into the LIVE database and
   tools/gate-revoke.php withdraws passes; both ran for anyone who requested
   them, because nothing denied the folders. */
$secRoot = (string) file_get_contents(AV_ROOT . '/.htaccess');
ck('http: the root .htaccess refuses the CLI/test/vendor folders',
    str_contains($secRoot, 'RewriteRule ^(tools|scripts|bin|tests|deploy|docs|vendor|partials)(/|$) - [F,L]'));
ck('http: the STS form backups are refused', str_contains($secRoot, 'RewriteRule ^projects/sts/ceo/api/data(/|$) - [F,L]'));
foreach (['tools', 'scripts', 'bin', 'tests', 'deploy', 'docs', 'vendor', 'partials', 'lib'] as $secDir) {
    ck("http: $secDir/ carries its own deny", str_contains((string) @file_get_contents(AV_ROOT . "/$secDir/.htaccess"), 'Require all denied'));
}
foreach (['tools/gate-revoke.php', 'scripts/seed-mentor-200.php', 'tests/run.php', 'bin/sync-nav.php', 'tools/build-chrome.php'] as $secCli) {
    ck("http: $secCli refuses a web request", str_contains((string) file_get_contents(AV_ROOT . '/' . $secCli), "if (PHP_SAPI !== 'cli') { http_response_code(403)"));
}

/* ══ 3. Every Studio POST carries a CSRF token ════════════════════════════
   The CSRF gate keyed off a hand-kept list of action names; promotion_review,
   aiops_cron, brief_run, dc_publish, superadmin_reveal and others were missing
   from it and were accepted without a token. */
$secAdmin = (string) file_get_contents(AV_ROOT . '/admin/api.php');
ck('admin: any POST counts as a write for CSRF', str_contains($secAdmin, "if (\$method === 'POST') \$writing = true;\n    if (\$writing && !av_admin_bearer_ok()) av_csrf_require();"));
ck('admin: the CSRF check still runs on writes', str_contains($secAdmin, 'if ($writing && !av_admin_bearer_ok()) av_csrf_require();'));
ck('admin: mail settings are management-only', (bool) preg_match("/managementOnly = \\[.*'mail_status'/s", $secAdmin));

/* ══ 4. A Studio session bridged from a member ends with the member ════════
   The bridged admin cookie was a free-standing 12-hour credential: signing out
   of the site, or being removed from the admin team, left the Studio open on
   that browser until it expired. */
$secResetAuth = static function (): void {
    $r = new ReflectionClass('LmsAuth');
    foreach (['cache' => null, 'checked' => false] as $k => $v) { $pp = $r->getProperty($k); $pp->setAccessible(true); $pp->setValue(null, $v); }
};
$secDb = Database::pdo();
$secEmail = 'sec.admin.' . bin2hex(random_bytes(3)) . '@afrovanguard.org.ng';
$secDb->prepare('INSERT INTO lms_users (name, email, password_hash, role, status, email_verified) VALUES (?,?,?,?,?,1)')
      ->execute(['Sec Admin', $secEmail, 'x', 'member', 'active']);
$secUid = (int) $secDb->lastInsertId();
$secTok = bin2hex(random_bytes(20));
$secDb->prepare('INSERT INTO lms_sessions (token_hash, user_id, ip, ua, expires_at) VALUES (?,?,?,?,?)')
      ->execute([hash('sha256', $secTok), $secUid, '', '', gmdate('Y-m-d H:i:s', time() + 3600)]);
AdminRoles::add($secEmail, 'editor', 'test');
unset($_COOKIE[AV_ADMIN_COOKIE]);
$_COOKIE[LmsAuth::COOKIE] = $secTok; $secResetAuth();
ck('admin cookie: a signed-in team member is bridged at their level', AdminRoles::current() === 'editor');
ck('admin cookie: the bridged cookie is marked as the member\'s', (av_admin_cookie_parse()['src'] ?? '') === 'm');
AdminRoles::remove($secEmail);
ck('admin cookie: removal from the team ends the bridged session at once', AdminRoles::current() === '');
ck('admin cookie: and the stale cookie is cleared', !isset($_COOKIE[AV_ADMIN_COOKIE]));
AdminRoles::add($secEmail, 'admin', 'test');
ck('admin cookie: re-bridged at the new level', AdminRoles::current() === 'admin');
unset($_COOKIE[LmsAuth::COOKIE]); $secResetAuth();
ck('admin cookie: signing out of the site ends the bridged session', AdminRoles::current() === '');
av_admin_cookie_issue(3600, 'superadmin', 't');
ck('admin cookie: a token sign-in stands on its own credential', AdminRoles::current() === 'superadmin');
$_COOKIE[AV_ADMIN_COOKIE] = av_admin_cookie_value(time() + 3600, 'n', 'superadmin', 'm', 'not-the-key');
ck('admin cookie: a forged cookie grants nothing', av_admin_cookie_parse() === null && AdminRoles::current() === '');
unset($_COOKIE[AV_ADMIN_COOKIE]); AdminRoles::remove($secEmail);

/* ══ 5. ?next= stays on this site ═════════════════════════════════════════
   "/\evil.example" passed the old check (starts with "/", not "//") and a
   browser follows it to evil.example — after Google sign-in, from a link the
   attacker chose. */
ck('next: a plain path is kept', GoogleAuth::safeNext('/academy/x/?a=1') === '/academy/x/?a=1');
ck('next: //host is refused', GoogleAuth::safeNext('//evil.example') === '/portal/');
ck('next: /\\host is refused', GoogleAuth::safeNext('/\\evil.example') === '/portal/');
ck('next: a tab-split //host is refused', GoogleAuth::safeNext("/\t/evil.example") === '/portal/');
ck('next: an absolute URL is refused', GoogleAuth::safeNext('https://evil.example/') === '/portal/');
ck('next: the signed state carries only a safe path', GoogleAuth::readState(GoogleAuth::makeState('/\\evil.example')) === '/portal/');

/* ══ 6. A community thread is as private as its post ═══════════════════════
   GET community/api.php?action=replies&id=N returned every reply under any
   post — members-only and confidential included — to anyone, signed in or not;
   and any account could reply to or like a post it is not cleared to see. */
require_once AV_ROOT . '/lib/Community.php';
$secMk = static function (string $role) use ($secDb): int {
    $secDb->prepare('INSERT INTO lms_users (name, email, password_hash, role, status, email_verified) VALUES (?,?,?,?,?,1)')
          ->execute(['C ' . $role, 'c.' . $role . '.' . bin2hex(random_bytes(3)) . '@example.test', 'x', $role, 'active']);
    return (int) $secDb->lastInsertId();
};
$secBoss = $secMk('admin'); $secLearner = $secMk('learner');
$secConf = Community::createPost($secBoss, 'open-floor', 'Board minutes: salaries', null, false, 'confidential');
$secPub  = Community::createPost($secBoss, 'open-floor', 'Hello everyone', null, false, 'public');
Community::reply($secBoss, $secConf, 'The figure is 4.2m');
Community::reply($secBoss, $secPub, 'Welcome!');
ck('community: an anonymous reader gets no replies under a confidential post', Community::replies($secConf, 0) === []);
ck('community: a learner gets no replies under a confidential post', Community::replies($secConf, $secLearner) === []);
ck('community: a cleared reader still sees them', count(Community::replies($secConf, $secBoss)) === 1);
ck('community: replies under a public post stay public', count(Community::replies($secPub, 0)) === 1);
$secComm = (string) file_get_contents(AV_ROOT . '/community/api.php');
ck('community: reply and like check the post is visible first', substr_count($secComm, 'comm_visible_post((int) ($body[\'id\'] ?? 0), (int) $u[\'id\']);') === 2);
ck('community: the origin check compares hosts exactly', !str_contains($secComm, 'stripos($host, $oh)'));

/* ══ 7. The public team API names people, not their inboxes ════════════════
   /api.php?action=members returned each team member's private email (the one
   birthday mail goes to) and birthday to anyone. */
require_once AV_ROOT . '/lib/people.php';
$secTeam = av_team_public_dict(['id' => 1, 'name' => 'A', 'email' => 'a@private.example', 'birthday' => '05-04', 'socials' => ['email' => 'pub@x.example']]);
ck('team api: the private email is dropped', !array_key_exists('email', $secTeam));
ck('team api: the birthday is dropped', !array_key_exists('birthday', $secTeam));
ck('team api: a deliberately public social email stays', ($secTeam['socials']['email'] ?? '') === 'pub@x.example');
$secApi = (string) file_get_contents(AV_ROOT . '/api.php');
ck('team api: both public reads go through the public shape', substr_count($secApi, 'av_team_public_dict') === 2);

/* ══ 8. Donations: the public wall, the admin token, the mail relay ═══════ */
$secDon = (string) file_get_contents(AV_ROOT . '/process-donation.php');
ck('donations: the webhook never publishes the payer\'s email as their name', !str_contains($secDon, 'trim("$fn $ln") ?: $email'));
ck('donations: the token endpoints use the length-checked, rate-limited compare',
    substr_count($secDon, 'av_admin_token_matches($token)') === 2 && !str_contains($secDon, 'hash_equals(ADMIN_TOKEN'));
ck('donations: the mail-sending actions have their own tight limit', str_contains($secDon, "['submit_contribute','bank_transfer_copy'], true) && !rateLimit('mail_'"));
ck('contact: the inbox uses the same token check', str_contains((string) file_get_contents(AV_ROOT . '/process-contact.php'), 'av_admin_token_matches($token)'));
ck('donor dashboard: no donor field reaches innerHTML', !preg_match('/innerHTML = `[^`]*\$\{d\./', (string) file_get_contents(AV_ROOT . '/donor-dashboard.html')));
ck('admin token: an empty token never matches', av_admin_token_matches('') === false);

/* ══ 9. The web cron key is rate-limited before it is checked ═════════════ */
$secCron = (string) file_get_contents(AV_ROOT . '/tasks/cron.php');
ck('cron: guesses are counted before the key is compared',
    strpos($secCron, "av_rate_ok('cron_web'") < strpos($secCron, 'if (!hash_equals($want, $key))'));

/* ══ 10. Public AI answers are rate-limited ═══════════════════════════════ */
$secSearch = (string) file_get_contents(AV_ROOT . '/search.php');
ck('search: the AI answer is limited before the model is called',
    strpos($secSearch, "av_rate_ok('search_ai'") !== false && strpos($secSearch, "av_rate_ok('search_ai'") < strpos($secSearch, 'AvBot::reply('));
