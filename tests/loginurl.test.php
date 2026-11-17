<?php
/**
 * tests/loginurl.test.php — every page that sends a signed-out visitor to sign in can.
 *
 * av_login_url() lives in lib/partials.php, which lib/bootstrap.php does NOT
 * load. A page that calls it without loading partials works for everyone
 * signed in and is a server error (500) for exactly the person it was meant
 * to help: the volunteer check-in QR on a phone that is not signed in, a Diary
 * export link, the Meeting Recorder's pages. This finds them all.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$loginScan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(AV_ROOT, FilesystemIterator::SKIP_DOTS));
$bad = []; $seen = 0;
foreach ($loginScan as $f) {
    $path = str_replace('\\', '/', substr($f->getPathname(), strlen(AV_ROOT) + 1));
    if (!str_ends_with($path, '.php') || preg_match('~^(vendor|tests|node_modules|\.git)/~', $path) || $path === 'lib/partials.php') continue;
    $src = (string) file_get_contents($f->getPathname());
    if (!str_contains($src, 'av_login_url(')) continue;
    // Only real calls: drop comment lines.
    $code = implode("\n", array_filter(explode("\n", $src), static fn($l) => !preg_match('~^\s*(//|\*|/\*|#)~', $l)));
    if (!preg_match('~\bav_login_url\s*\(~', $code)) continue;
    $seen++;
    $loads = str_contains($src, "lib/partials.php'") || str_contains($src, "function_exists('av_login_url')");
    if (!$loads) $bad[] = $path;
}
ck('login: the scan found pages that send people to sign in', $seen >= 4);
ck('login: every one of them loads av_login_url() (missing: ' . implode(', ', $bad) . ')', $bad === []);
