<?php
/**
 * tests/harness.test.php — the runner's own guarantees.
 *
 * `render_page()` suppresses one specific warning so that including a page
 * entry point mid-run does not bury the results in 128 copies of "Cannot
 * modify header information". A suppressor is exactly the kind of convenience
 * that widens quietly: one `str_contains` edited into something looser and the
 * suite stops reporting real warnings from the pages it renders, while still
 * printing OK. Since the harness is what decides whether everything else
 * passed, its own behaviour is asserted rather than assumed.
 *
 * Run via tests/run.php (provides ck() and render_page()).
 */
declare(strict_types=1);

$hDir = sys_get_temp_dir() . '/av-harness-' . getmypid();
@mkdir($hDir);
$hWrite = static function (string $name, string $php) use ($hDir): string {
    file_put_contents($hDir . '/' . $name, "<?php\n" . $php);
    return $hDir . '/' . $name;
};

/* The premise. If output had NOT already been sent, there would be no warning
   to suppress and this whole helper would be pointless — so state it. */
ck('harness: output has already been sent by the time a test renders a page',
   headers_sent());

/* ── what is suppressed ─────────────────────────────────────────────────── */

$hPage = $hWrite('page.php', <<<'P'
header('X-Anything: 1');
http_response_code(429);
echo 'the body';
P);
$hErr = '';
set_error_handler(static function (int $n, string $m) use (&$hErr): bool { $hErr .= $m . "\n"; return true; });
$hHtml = render_page($hPage);
restore_error_handler();
ck('harness: the header warning a page raises mid-run is suppressed',
   !str_contains($hErr, 'Cannot modify header information'));
ck('harness: and the page still renders, so the suppression costs nothing',
   trim($hHtml) === 'the body');

/* ── what is NOT suppressed: the part that has to keep working ──────────── */

$hNoisy = $hWrite('noisy.php', <<<'P'
trigger_error('a real problem in the page', E_USER_WARNING);
echo 'rendered anyway';
P);
$hErr = '';
set_error_handler(static function (int $n, string $m) use (&$hErr): bool { $hErr .= $m . "\n"; return true; });
render_page($hNoisy);
restore_error_handler();
ck('harness: a genuine warning from the rendered page still surfaces',
   str_contains($hErr, 'a real problem in the page'));

/* ── a page that throws must not look like a page that rendered nothing ─── */

$hBoom = $hWrite('boom.php', <<<'P'
echo 'before';
throw new RuntimeException('page blew up');
P);
$hOut = render_page($hBoom);
ck('harness: a page that throws says so instead of returning silence',
   str_contains($hOut, 'render_page: threw RuntimeException')
   && str_contains($hOut, 'page blew up'));
/* An empty string quietly satisfies every "does NOT contain" assertion in the
   suite, which is how a crashed page passes a test written to catch a forgery. */
ck('harness: and what it returns is not empty, so negative assertions cannot pass on a crash',
   trim($hOut) !== '');

/* ── the handler is handed back, including down the throwing path ───────── */

$hErr = '';
set_error_handler(static function (int $n, string $m) use (&$hErr): bool { $hErr .= $m . "\n"; return true; });
render_page($hBoom);                       // throws inside
@header('X-After: 1');                     // would warn — our probe records it
restore_error_handler();
ck('harness: the error handler is restored even when the page threw',
   str_contains($hErr, 'Cannot modify header information'));

/* ── superglobals are borrowed, not kept ────────────────────────────────── */

$_GET  = ['keep' => 'get'];
$_POST = ['keep' => 'post'];
$hEcho = $hWrite('echo.php', <<<'P'
echo ($_GET['id'] ?? '-') . '|' . ($_POST['act'] ?? '-');
P);
$hSeen = render_page($hEcho, ['id' => '7'], ['act' => 'pay']);
ck('harness: the page sees the $_GET and $_POST it was given',
   trim($hSeen) === '7|pay');
ck('harness: and the caller gets its own back afterwards',
   $_GET === ['keep' => 'get'] && $_POST === ['keep' => 'post']);
$_GET = []; $_POST = [];

array_map('unlink', glob($hDir . '/*.php') ?: []);
@rmdir($hDir);
