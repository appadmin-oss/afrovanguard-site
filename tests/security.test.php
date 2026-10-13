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
/* Every PHP file under the CLI folders refuses a web request itself, not a
   named few: a new tool is covered the day it is added. */
foreach (array_merge(glob(AV_ROOT . '/{tools,scripts,bin}/*.php', GLOB_BRACE) ?: [], [AV_ROOT . '/tests/run.php']) as $secCli) {
    ck('http: ' . substr($secCli, strlen(AV_ROOT) + 1) . ' refuses a web request',
        str_contains((string) file_get_contents($secCli), "if (PHP_SAPI !== 'cli') { http_response_code(403)"));
}
$secRouter = (string) file_get_contents(AV_ROOT . '/router.php');
ck('http: the dev router mirrors the CLI-folder deny', str_contains($secRouter, "~^/(tools|scripts|bin|tests|deploy|docs|vendor|partials|lib)(/|$)~i"));
ck('http: the dev router mirrors the STS data deny', str_contains($secRouter, "~^/projects/sts/ceo/api/data(/|$)~i"));
/* The suite's NGV connection is the throwaway file whatever the host has
   configured: NgvDb picks MySQL as soon as AV_NGV_DB_HOST/_NAME/_USER is set,
   and the NGV tests delete every charge, payment and participant. */
ck('tests: NGV is forced to SQLite', getenv('AV_NGV_DB_DRIVER') === 'sqlite' && str_starts_with((string) getenv('AV_NGV_DB_DSN'), 'sqlite:'));
ck('tests: the NGV connection the suite holds is the throwaway file',
    NgvDb::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && str_contains(NgvDb::sqlitePathPublic(), sys_get_temp_dir()));
ck('tests: the main DB is forced to SQLite', getenv('AV_DB_DRIVER') === 'sqlite' && Database::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');

/* STS form backups: a deny rule that ships, rather than a template to merge. */
$secSts = (string) @file_get_contents(AV_ROOT . '/projects/sts/ceo/api/data/.htaccess');
ck('sts: the data folder carries its own deny', str_contains($secSts, 'Require all denied'));
$secIgn = (string) file_get_contents(AV_ROOT . '/.gitignore');
ck('sts: the deny is tracked while the data stays ignored',
    str_contains($secIgn, "projects/sts/ceo/api/data/*\n!projects/sts/ceo/api/data/.htaccess"));
ck('upload: the no-execute rules are tracked while the uploads stay ignored', str_contains($secIgn, "uploads/*\n!uploads/.htaccess"));
ck('upload: PHP is switched off in /uploads/ where mod_php reads .htaccess', str_contains(Storage::UPLOADS_HTACCESS, "<IfModule mod_php.c>\n  php_flag engine off"));
ck('upload: script types lose their handler', str_contains(Storage::UPLOADS_HTACCESS, 'RemoveHandler .php .phtml') && str_contains(Storage::UPLOADS_HTACCESS, 'RemoveType .php .phtml'));
ck('upload: Cloudinary\'s local fallback names files from the bytes too',
    str_contains((string) file_get_contents(AV_ROOT . '/lib/Cloudinary.php'), 'Storage::safeExt(Storage::mime($tmpPath), $originalName)'));

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
/* The role in a bridged cookie is never trusted over the table. */
$_COOKIE[AV_ADMIN_COOKIE] = av_admin_cookie_value(time() + 3600, 'n', 'superadmin', 'm', av_secret());
ck('admin cookie: a bridged cookie claiming more than the table grants gets the table\'s level', AdminRoles::current() === 'admin');
$secDb->prepare("UPDATE lms_users SET status = 'suspended' WHERE id = ?")->execute([$secUid]); $secResetAuth();
ck('admin cookie: suspending the member ends their Studio session on the next request', AdminRoles::current() === '');
$secDb->prepare("UPDATE lms_users SET status = 'active' WHERE id = ?")->execute([$secUid]); $secResetAuth();
unset($_COOKIE[LmsAuth::COOKIE]); $secResetAuth();
ck('admin cookie: signing out of the site ends the bridged session', AdminRoles::current() === '');
av_admin_cookie_issue(3600, 'superadmin', 't');
/* With no ADMIN_TOKEN there is no token sign-in to stand on. Asked in a child
   process, because a constant once defined by an earlier file stays defined. */
$secNoTok = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    'require ' . var_export(AV_ROOT . '/lib/bootstrap.php', true) . ';'
    . ' if (defined("ADMIN_TOKEN")) { echo "has-token"; exit; }'
    . ' $_COOKIE[AV_ADMIN_COOKIE] = av_admin_cookie_value(time() + 600, "n", "superadmin", "t", av_secret());'
    . ' echo "role=" . AdminRoles::current();') . ' 2>/dev/null');
ck('admin cookie: with no ADMIN_TOKEN a token-session cookie grants nothing', $secNoTok === 'role=' || $secNoTok === 'has-token');
if (!defined('ADMIN_TOKEN')) define('ADMIN_TOKEN', 'sec-test-admin-token-0123456789abcdef0123456789');
av_admin_cookie_issue(3600, 'superadmin', 't');
ck('admin cookie: a token sign-in stands on its own credential', AdminRoles::current() === 'superadmin');
$_COOKIE[AV_ADMIN_COOKIE] = av_admin_cookie_value(time() + 3600, 'n', 'superadmin', 'm', 'not-the-key');
ck('admin cookie: a forged cookie grants nothing', av_admin_cookie_parse() === null && AdminRoles::current() === '');
unset($_COOKIE[AV_ADMIN_COOKIE]); AdminRoles::remove($secEmail);

/* ══ 4b. No other signed value is an admin cookie ═════════════════════════
   av_csrf_token() made exp.nonce.HMAC(exp.nonce) and the admin cookie parser
   accepted exactly that shape as a "legacy" cookie, returning superadmin. Public
   pages (/IQ/, the csrf-token meta, the diary comment form) hand such tokens to
   anonymous visitors: reproduced end to end, a token from GET /IQ/ sent back as
   av_admin opened the Studio and the NGV member desk. Google's sign-in state
   (exp.nonce.next.sig) matched the four-part cookie shape the same way. */
$secAsCookie = static function (string $v): string {
    $_COOKIE[AV_ADMIN_COOKIE] = $v;
    $r = AdminRoles::current();
    unset($_COOKIE[AV_ADMIN_COOKIE]);
    return $r;
};
unset($_COOKIE[LmsAuth::COOKIE]); $secResetAuth();
$secCsrf = av_csrf_token();
ck('admin cookie: a CSRF token is a valid CSRF token', av_csrf_valid($secCsrf));
ck('admin cookie: a CSRF token is refused as an admin cookie', $secAsCookie($secCsrf) === '');
$secState = GoogleAuth::makeState('/portal/');
ck('admin cookie: a Google state is a valid Google state', GoogleAuth::readState($secState) === '/portal/');
ck('admin cookie: a Google sign-in state is refused as an admin cookie', $secAsCookie($secState) === '');
ck('admin cookie: a Workspace connect state is refused as an admin cookie', $secAsCookie(GoogleWorkspaceUser::makeState(1, '/workspace')) === '');
$secExp = time() + 3600;
ck('admin cookie: the old three-part shape signed with the bare key is refused',
    $secAsCookie($secExp . '.n.' . hash_hmac('sha256', $secExp . '.n', av_secret())) === '');
ck('admin cookie: the old four-part shape signed with the bare key is refused',
    $secAsCookie($secExp . '.n.superadmin.' . hash_hmac('sha256', $secExp . '.n.superadmin', av_secret())) === '');
ck('admin cookie: a five-part cookie MACed without its purpose label is refused',
    $secAsCookie($secExp . '.n.superadmin.t.' . hash_hmac('sha256', $secExp . '.n.superadmin.t', av_secret())) === '');
ck('admin cookie: a role outside AdminRoles::RANK is refused even when correctly signed',
    $secAsCookie(av_admin_cookie_value($secExp, 'n', 'root', 't', av_secret())) === '');
ck('admin cookie: a correctly signed token cookie still works', $secAsCookie(av_admin_cookie_value($secExp, 'n', 'admin', 't', av_secret())) === 'admin');
ck('csrf: an admin cookie is not a CSRF token', !av_csrf_valid(av_admin_cookie_value($secExp, 'n', 'admin', 't', av_secret())));
ck('google state: a CSRF token is not a Google state', GoogleAuth::readState($secCsrf) === null);
ck('google state: a Workspace connect state is not a sign-in state', GoogleAuth::readState(GoogleWorkspaceUser::makeState(1, '/x')) === null);
ck('admin cookie: a bridged-shape cookie with nobody signed in grants nothing',
    $secAsCookie(av_admin_cookie_value($secExp, 'n', 'superadmin', 'm', av_secret())) === '');
ck('admin cookie: av_admin_cookie_issue refuses to mint an unknown role',
    (static function () { av_admin_cookie_issue(60, 'owner', 't'); $ok = !isset($_COOKIE[AV_ADMIN_COOKIE]); unset($_COOKIE[AV_ADMIN_COOKIE]); return $ok; })());
ck('admin cookie: a token session is bound to the ADMIN_TOKEN it was issued under',
    str_contains((string) file_get_contents(AV_ROOT . '/lib/security.php'), "av_mac('admin-cookie', \$payload . '|' . av_admin_cookie_binding(\$src), \$secret)"));
/* Member sign-out takes the Studio cookie with it. */
av_admin_cookie_issue(3600, 'admin', 'm');
@LmsAuth::logout();   // @: the harness has already sent output, so its setcookie() warns
ck('admin cookie: signing out of the site clears the admin cookie', !isset($_COOKIE[AV_ADMIN_COOKIE]));

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

/* ══ 11. Unsubscribing somebody needs their link, not just their address ═ */
ck('unsubscribe: a token is minted per address', av_unsubscribe_token('a@x.example') !== '' && av_unsubscribe_token('a@x.example') !== av_unsubscribe_token('b@x.example'));
ck('unsubscribe: the address is normalised', av_unsubscribe_token(' A@X.example ') === av_unsubscribe_token('a@x.example'));
ck('unsubscribe: the endpoint checks it', str_contains((string) file_get_contents(AV_ROOT . '/diary/api.php'), "hash_equals(\$want, (string) (\$body['token'] ?? ''))"));
