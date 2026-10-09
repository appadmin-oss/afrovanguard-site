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
