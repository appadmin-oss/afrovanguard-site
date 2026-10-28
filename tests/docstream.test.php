<?php
/**
 * tests/docstream.test.php — the published PDFs, and how they are served.
 *
 * The ethos, the STS report and the LCASP document were 6.5, 16.6 and 4.4 MB,
 * not linearized, and linked with no hint of their size. On a 2 GB phone on
 * mobile data that is a long, memory-hungry wait for page 1. These checks keep
 * them light (screen-quality, linearized), keep the server from ever holding a
 * whole file in memory (lib/DocStream.php), and keep the pages honest about
 * what a tap will cost.
 *
 * Run via tests/run.php (provides ck()):  php tests/run.php docstream
 */
declare(strict_types=1);
require_once AV_ROOT . '/lib/DocStream.php';

/* ── The allowlist ──────────────────────────────────────────────────────── */
foreach (DocStream::DOCS as $key => $d) {
    $doc = DocStream::doc($key);
    ck("docstream: '$key' is on disk where the allowlist says", $doc !== null && $doc['size'] > 0);
}
foreach (['../../config.php', '../config', 'config.php', '/etc/passwd', 'afrovanguard-ethos/../../config',
          'Afrovanguard-Ethos', 'afrovanguard-ethos.pdf', 'afrovanguard-ethos%00', '', '-', 'lib/DocStream'] as $bad) {
    ck('docstream: ' . var_export($bad, true) . ' is not a document', DocStream::doc($bad) === null);
}
ck('docstream: a URL path maps to its key only when it is exactly an allowlisted file',
   DocStream::keyForPath('/projects/sts/STS_Report_2025.pdf') === 'sts-report-2025'
   && DocStream::keyForPath('/projects/sts/../../config.php') === null
   && DocStream::keyForPath('/assets/fliers/cimc-flier-4x5.pdf') === null);

/* ── The files themselves: light, linearized, the page counts the pages show ── */
$linN = static function (string $path): ?array {
    $fh = fopen($path, 'rb'); $head = (string) fread($fh, 1024); fclose($fh);
    if (!preg_match('~/Linearized\s+1(?:\.0)?\b(.*?)>>~s', $head, $m)) return null;
    preg_match('~/N\s+(\d+)~', $m[1], $n); preg_match('~/L\s+(\d+)~', $m[1], $l);
    return ['pages' => (int) ($n[1] ?? 0), 'length' => (int) ($l[1] ?? 0)];
};
foreach (['afrovanguard-ethos' => 2_500_000, 'sts-report-2025' => 6_000_000, 'lcasp-2026' => 5_000_000] as $key => $max) {
    $doc = DocStream::doc($key);
    if (!$doc) continue;
    $lin = $linN($doc['path']);
    ck("docstream: $key is linearized (fast web view), so page 1 arrives first", $lin !== null && $lin['length'] === $doc['size']);
    ck("docstream: $key has the {$doc['pages']} pages the site says it has", $lin !== null && $lin['pages'] === $doc['pages']);
    ck("docstream: $key stays screen-sized (under " . DocStream::humanSize($max) . ')', $doc['size'] < $max);
}
$print = DocStream::doc('afrovanguard-ethos-print');
$light = DocStream::doc('afrovanguard-ethos');
if ($print && $light) {
    $n = preg_match_all('~/Type\s*/Page(?![a-zA-Z])~', (string) file_get_contents($print['path']));
    ck('docstream: the print-quality ethos has the same 16 pages', $n === $print['pages'] && $print['pages'] === $light['pages']);
    ck('docstream: …and is worth keeping only because it is much larger', $print['size'] > 2 * $light['size']);
}

/* ── Sizes and labels ───────────────────────────────────────────────────── */
ck('docstream: sizes read the way a phone reports them',
   DocStream::humanSize(1_979_774) === '2.0 MB' && DocStream::humanSize(640_000) === '640 KB' && DocStream::humanSize(12) === '1 KB');
ck('docstream: a label says type, size and length', (bool) preg_match('~^PDF, \d+\.\d MB, \d+ pages$~', DocStream::label('sts-report-2025')));
ck('docstream: an unknown document has no label or URL', DocStream::label('nope') === '' && DocStream::url('nope') === '');
ck('docstream: the URL is the static file stamped with its content hash',
   (bool) preg_match('~^/assets/docs/afrovanguard-ethos\.pdf\?v=[0-9a-f]{10}$~', DocStream::url('afrovanguard-ethos'))
   && str_ends_with(DocStream::url('afrovanguard-ethos'), substr((string) md5_file($light['path'] ?? ''), 0, 10)));

/* ── Range parsing ──────────────────────────────────────────────────────── */
$R = static fn(?string $h, int $size = 1000) => DocStream::parseRange($h, $size);
ck('range: bytes=0-99 is the first hundred bytes', $R('bytes=0-99') === [0, 99]);
ck('range: bytes=100- is everything from byte 100', $R('bytes=100-') === [100, 999]);
ck('range: bytes=-500 is the last 500 bytes', $R('bytes=-500') === [500, 999]);
ck('range: a suffix longer than the file is the whole file', $R('bytes=-5000') === [0, 999]);
ck('range: an end past EOF is clamped to the last byte', $R('bytes=900-5000') === [900, 999]);
ck('range: the last byte alone', $R('bytes=999-999') === [999, 999]);
ck('range: a start past EOF is unsatisfiable (416)', $R('bytes=1000-') === false && $R('bytes=5000-6000') === false);
ck('range: bytes=-0 is unsatisfiable', $R('bytes=-0') === false);
ck('range: any range of an empty file is unsatisfiable', $R('bytes=0-0', 0) === false);
ck('range: no header means no range', $R(null) === null && $R('') === null);
foreach (['bytes=abc', 'bytes=-', 'bytes=5-2', 'items=0-9', 'bytes 0-9', 'bytes=0-9,20-29', 'bytes=1e3-', 'bytes=-1-2',
          'bytes=0-99999999999999999999999'] as $bad) {
    ck('range: ' . $bad . ' is ignored (whole file, 200)', $R($bad) === null);
}

/* ── The response plan: status and headers ──────────────────────────────── */
$tmp = tempnam(sys_get_temp_dir(), 'avds');
$fh = fopen($tmp, 'wb'); for ($i = 0; $i < 4; $i++) fwrite($fh, str_repeat(chr(65 + $i), 250)); fclose($fh);
touch($tmp, 1_760_000_000);
clearstatcache();
$tdoc = ['path' => $tmp, 'size' => 1000, 'mtime' => 1_760_000_000, 'name' => 'Test Doc.pdf', 'pages' => 1];
$etag = '"' . substr((string) md5_file($tmp), 0, 10) . '"';
$lm   = gmdate('D, d M Y H:i:s', 1_760_000_000) . ' GMT';

$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET']);
ck('plan: a plain GET is 200 with the whole file', $p['status'] === 200 && $p['start'] === 0 && $p['length'] === 1000 && $p['body']);
$h = $p['headers'];
ck('plan: Content-Length is the file size', ($h['Content-Length'] ?? '') === '1000');
ck('plan: it says it takes byte ranges', ($h['Accept-Ranges'] ?? '') === 'bytes');
ck('plan: it is a PDF, and the browser may not guess otherwise',
   ($h['Content-Type'] ?? '') === 'application/pdf' && ($h['X-Content-Type-Options'] ?? '') === 'nosniff');
ck('plan: a content ETag and Last-Modified', ($h['ETag'] ?? '') === $etag && ($h['Last-Modified'] ?? '') === $lm);
ck('plan: shown in the browser by default, with a safe file name',
   ($h['Content-Disposition'] ?? '') === 'inline; filename="Test-Doc.pdf"');
ck('plan: an unstamped URL is cached briefly', ($h['Cache-Control'] ?? '') === DocStream::CACHE_PLAIN);

$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET'], ['dl' => '1', 'v' => trim($etag, '"')]);
ck('plan: ?dl=1 saves it instead', str_starts_with($p['headers']['Content-Disposition'] ?? '', 'attachment;'));
ck('plan: the current ?v= stamp is cached for a year, immutable', ($p['headers']['Cache-Control'] ?? '') === DocStream::CACHE_STAMPED);
$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET'], ['v' => '0000000000']);
ck('plan: a stale stamp is not cached for a year', ($p['headers']['Cache-Control'] ?? '') === DocStream::CACHE_PLAIN);

$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET', 'HTTP_RANGE' => 'bytes=0-99']);
ck('plan: bytes=0-99 is 206 with Content-Range and a 100-byte body',
   $p['status'] === 206 && ($p['headers']['Content-Range'] ?? '') === 'bytes 0-99/1000'
   && ($p['headers']['Content-Length'] ?? '') === '100' && $p['start'] === 0 && $p['length'] === 100);
$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET', 'HTTP_RANGE' => 'bytes=-500']);
ck('plan: bytes=-500 is 206 for bytes 500-999', $p['status'] === 206 && ($p['headers']['Content-Range'] ?? '') === 'bytes 500-999/1000' && $p['start'] === 500);
$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET', 'HTTP_RANGE' => 'bytes=1000-']);
ck('plan: a range past EOF is 416 with bytes */size and no body',
   $p['status'] === 416 && ($p['headers']['Content-Range'] ?? '') === 'bytes */1000' && !$p['body']);
$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET', 'HTTP_RANGE' => 'bytes=oops']);
ck('plan: a malformed range is the whole file, 200', $p['status'] === 200 && $p['length'] === 1000);
$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET', 'HTTP_RANGE' => 'bytes=0-99', 'HTTP_IF_RANGE' => '"stale"']);
ck('plan: If-Range with another ETag gets the whole (new) file', $p['status'] === 200 && $p['length'] === 1000);
$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET', 'HTTP_RANGE' => 'bytes=0-99', 'HTTP_IF_RANGE' => $etag]);
ck('plan: If-Range with this ETag gets the range', $p['status'] === 206);

$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET', 'HTTP_IF_NONE_MATCH' => $etag]);
ck('plan: a matching ETag is 304 with no body', $p['status'] === 304 && !$p['body'] && ($p['headers']['ETag'] ?? '') === $etag);
$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET', 'HTTP_IF_NONE_MATCH' => '"x", W/' . $etag]);
ck('plan: …also inside a list, and weakly compared', $p['status'] === 304);
$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET', 'HTTP_IF_NONE_MATCH' => '"other"', 'HTTP_IF_MODIFIED_SINCE' => $lm]);
ck('plan: a different ETag is 200 even with a current If-Modified-Since', $p['status'] === 200);
$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET', 'HTTP_IF_MODIFIED_SINCE' => $lm]);
ck('plan: If-Modified-Since at the file time is 304', $p['status'] === 304);
$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'GET', 'HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', 1_700_000_000) . ' GMT']);
ck('plan: an older If-Modified-Since is 200', $p['status'] === 200);
$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'HEAD']);
ck('plan: HEAD has the headers and no body', $p['status'] === 200 && ($p['headers']['Content-Length'] ?? '') === '1000' && !$p['body']);
$p = DocStream::plan($tdoc, ['REQUEST_METHOD' => 'POST']);
ck('plan: anything but GET/HEAD is 405', $p['status'] === 405 && ($p['headers']['Allow'] ?? '') === 'GET, HEAD' && !$p['body']);

/* ── The copy: exact bytes, in chunks ───────────────────────────────────── */
$got = ''; $calls = 0;
$n = DocStream::pipe($tmp, 240, 20, static function (string $b) use (&$got, &$calls): bool { $got .= $b; $calls++; return true; }, 8);
ck('pipe: a range is copied exactly, in chunks no bigger than asked',
   $n === 20 && $got === str_repeat('A', 10) . str_repeat('B', 10) && $calls === 3);
$got = '';
$n = DocStream::pipe($tmp, 0, 1000, static function (string $b) use (&$got): bool { $got .= $b; return false; }, 100);
ck('pipe: a client that goes away stops the copy', $n === 100 && strlen($got) === 100);
ck('pipe: a missing file sends nothing', DocStream::pipe($tmp . '-gone', 0, 10, static fn() => true) === 0);
@unlink($tmp);

/* Memory: stream 50 MB and PHP's peak barely moves. The file is written a
   megabyte at a time so the test itself never holds it either. */
$big = tempnam(sys_get_temp_dir(), 'avds-big');
$fh = fopen($big, 'wb'); $mb = str_repeat('0123456789abcdef', 65536);
for ($i = 0; $i < 50; $i++) fwrite($fh, $mb);
fclose($fh); unset($mb);
$sink = fopen('php://temp/maxmemory:0', 'w+b');   // straight to disk: the sink must not be what grows
$ctx = hash_init('md5');
gc_collect_cycles();
if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
$before = memory_get_peak_usage();
$sent = DocStream::pipe($big, 0, 50 * 1048576, static function (string $b) use ($sink, $ctx): bool { hash_update($ctx, $b); return fwrite($sink, $b) !== false; });
$delta = memory_get_peak_usage() - $before;
fclose($sink);
ck('pipe: all 50 MB were sent, unchanged', $sent === 50 * 1048576 && hash_final($ctx) === md5_file($big));
ck('pipe: …while PHP\'s peak memory grew by under 4 MB (it grew ' . round($delta / 1048576, 2) . ' MB)', $delta < 4 * 1048576);
@unlink($big);

/* send() end to end, in a PHP with a 4 MB memory limit, on the 4.9 MB STS
   report: a version that read the file into a string would die here. */
$sts = DocStream::doc('sts-report-2025');
if ($sts && function_exists('proc_open')) {
    $out = tempnam(sys_get_temp_dir(), 'avds-out');
    $code = 'define("AV_ROOT", ' . var_export(AV_ROOT, true) . '); require AV_ROOT . "/lib/DocStream.php";'
          . '$_SERVER["REQUEST_METHOD"] = "GET"; DocStream::send("sts-report-2025", null, []);';
    $proc = proc_open([PHP_BINARY, '-n', '-d', 'memory_limit=4M', '-d', 'output_buffering=0', '-r', $code],
        [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $rc = proc_close($proc);
    ck('send: the whole 4.9 MB report goes out of a PHP limited to 4 MB of memory' . ($err ? ' [' . trim($err) . ']' : ''),
       $rc === 0 && filesize($out) === $sts['size'] && md5_file($out) === md5_file($sts['path']));
    @unlink($out);
}

/* ── The pages ──────────────────────────────────────────────────────────── */
$lite = '/(^|-)2g$/';
$ethosHtml = render_page(AV_ROOT . '/ethos/index.php');
$pdfAt  = strpos($ethosHtml, 'href="' . htmlspecialchars(DocStream::url('afrovanguard-ethos')) . '"');
$readAt = strpos($ethosHtml, 'href="#commitments"');
ck('ethos page: reading the text on the page is the first action, the PDF second', $readAt !== false && $pdfAt !== false && $readAt < $pdfAt);
ck('ethos page: the PDF link shows its size and page count', str_contains($ethosHtml, '(' . DocStream::meta('afrovanguard-ethos') . ')'));
ck('ethos page: the print-quality original is offered, with its size',
   str_contains($ethosHtml, htmlspecialchars(DocStream::url('afrovanguard-ethos-print'))) && str_contains($ethosHtml, DocStream::meta('afrovanguard-ethos-print')));
ck('ethos page: the cover no longer opens the PDF', !preg_match('~class="aveth-cover"[^>]*\.pdf~', $ethosHtml));
ck('ethos page: a light-reading note, as a note, in real text',
   (bool) preg_match('~<p class="[^"]*av-docnote[^"]*" role="note">[^<]*<b>[^<]+</b>[^<]+Wi-Fi~u', $ethosHtml));
$head = preg_match('~<head>(.*?)</head>~s', $ethosHtml, $hm) ? $hm[1] : '';
ck('ethos page: the low-end check (memory, data saver, 2G) runs in <head>, before first paint',
   str_contains($head, 'deviceMemory<=2') && str_contains($head, 'saveData') && str_contains($head, $lite) && str_contains($head, "add('av-lite')"));

foreach (DocStream::PAGES as $rel) {
    $src = (string) file_get_contents(AV_ROOT . '/' . $rel);
    ck("$rel: links its PDFs at their current stamps (php tools/stamp-assets.php)", DocStream::stampHtml($src) === $src);
    preg_match_all('~href="(/[^"?]+\.pdf)\?v=[0-9a-f]{10}"[^>]*>(.*?)</a>~s', $src, $m, PREG_SET_ORDER);
    $ok = $m !== [];
    foreach ($m as $a) {
        $key = DocStream::keyForPath($a[1]);
        $ok = $ok && $key !== null && str_contains(strip_tags($a[2]), DocStream::meta($key));
    }
    ck("$rel: every PDF link says its current size and page count", $ok);
    ck("$rel: no PDF link is left unstamped", !preg_match('~href="[^"]*\.pdf"~', $src));
    ck("$rel: a light-reading note for low-end phones, as a note", str_contains($src, 'role="note"') && str_contains($src, 'av-lite')
       && str_contains($src, 'deviceMemory<=2') && str_contains($src, 'saveData') && str_contains($src, $lite));
}

/* Never embedded: no <iframe>, <object> or <embed> of a PDF anywhere on the site. */
$embeds = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(AV_ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    /* The site's own files only: .claude holds an editor's scratch copies
       of the whole site (git-ignored), and scanning them failed this check
       on every run for code that is not deployed. */
    if (!preg_match('~\.(html|php|js)$~', $p) || preg_match('~/(vendor|node_modules|tests|\.git|\.claude)/~', $p)) continue;
    $src = (string) @file_get_contents($p);
    if (stripos($src, '.pdf') === false) continue;
    if (preg_match('~<(iframe|object|embed)\b[^>]*\.pdf~i', $src)) $embeds[] = substr($p, strlen(AV_ROOT) + 1);
}
ck('pages: no PDF is embedded inline anywhere' . ($embeds ? ' (' . implode(', ', $embeds) . ')' : ''), $embeds === []);

/* ── Apache and the dev router ──────────────────────────────────────────── */
$ht = (string) file_get_contents(AV_ROOT . '/.htaccess');
ck('.htaccess: PDFs are never gzipped (a compressed body cannot be fetched by range)',
   str_contains($ht, 'SetEnvIfNoCase Request_URI "\.pdf$" no-gzip'));
ck('.htaccess: PDFs advertise byte ranges and are cached, a year when stamped',
   (bool) preg_match('~<FilesMatch "\\\\\.pdf\$">.*Accept-Ranges "bytes".*max-age=31536000, immutable.*</FilesMatch>~s', $ht));
ck('.htaccess: /doc/<name>.pdf reaches doc.php', str_contains($ht, 'RewriteRule ^doc/([a-z0-9-]+)\.pdf$ doc.php?name=$1'));
$sw = (string) file_get_contents(AV_ROOT . '/sw.js');
ck('sw.js: the service worker does not cache PDFs (ranges go straight to the server)',
   preg_match('~function isStatic[^}]*\}~s', $sw, $m) === 1 && !str_contains($m[0], 'pdf'));

$dsPort = 0;
if ($probe = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) {
    $nm = (string) stream_socket_get_name($probe, false); $dsPort = (int) substr($nm, strrpos($nm, ':') + 1); fclose($probe);
}
$dsLog = tempnam(sys_get_temp_dir(), 'avds-log');
$dsProc = $dsPort > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $dsPort, AV_ROOT . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $dsLog, 'a'], 2 => ['file', $dsLog, 'a']], $dsPipes, AV_ROOT) : null;
$dsUp = false;
if (is_resource($dsProc)) {
    for ($i = 0; $i < 100 && !$dsUp; $i++) { $c = @fsockopen('127.0.0.1', $dsPort, $e1, $e2, 0.2); if ($c) { fclose($c); $dsUp = true; } else usleep(50000); }
}
$get = static function (string $path, array $headers = [], string $method = 'GET') use ($dsPort): array {
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 20,
        'header' => implode("\r\n", $headers)]]);
    $body = (string) @file_get_contents('http://127.0.0.1:' . $dsPort . $path, false, $ctx);
    $code = 0; $hs = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+ (\d{3})~', $h, $mm)) { $code = (int) $mm[1]; $hs = []; continue; }
        [$k, $v] = array_pad(explode(':', $h, 2), 2, ''); $hs[strtolower(trim($k))] = trim($v);
    }
    return [$code, $hs, $body];
};
if (!$dsUp) {
    echo "  (skipped: could not start a loopback HTTP server)\n";
} else {
    $sts = DocStream::doc('sts-report-2025');
    [$c, $h, $b] = $get('/projects/sts/STS_Report_2025.pdf', ['Range: bytes=0-99']);
    ck('http: a Range request for the static STS report is 206 with exactly those bytes',
       $c === 206 && ($h['content-range'] ?? '') === 'bytes 0-99/' . $sts['size'] && $b === (string) file_get_contents($sts['path'], false, null, 0, 100));
    [$c, $h, $b] = $get('/projects/sts/STS_Report_2025.pdf', ['Range: bytes=-1024']);
    ck('http: the tail a viewer asks for first (the xref) is a 206 of 1024 bytes', $c === 206 && strlen($b) === 1024);
    [$c, $h] = $get('/projects/sts/STS_Report_2025.pdf', ['Range: bytes=' . ($sts['size'] + 10) . '-']);
    ck('http: a range past the end is 416', $c === 416 && ($h['content-range'] ?? '') === 'bytes */' . $sts['size']);
    [$c, $h, $b] = $get('/doc/afrovanguard-ethos.pdf?dl=1', [], 'HEAD');
    ck('http: /doc/<name>.pdf answers HEAD with length, ranges, nosniff and an attachment',
       $c === 200 && ($h['content-length'] ?? '') === (string) $light['size'] && ($h['accept-ranges'] ?? '') === 'bytes'
       && ($h['x-content-type-options'] ?? '') === 'nosniff' && str_starts_with($h['content-disposition'] ?? '', 'attachment;') && $b === '');
    $et = $h['etag'] ?? '';
    [$c, , $b] = $get('/doc/afrovanguard-ethos.pdf', ['If-None-Match: ' . $et]);
    ck('http: a matching ETag is 304 with no body', $et !== '' && $c === 304 && $b === '');
    [$c, $h] = $get(DocStream::url('afrovanguard-ethos'), [], 'HEAD');
    ck('http: the stamped URL the pages link is cached for a year', $c === 200 && ($h['cache-control'] ?? '') === DocStream::CACHE_STAMPED);
    [$c, , $b] = $get('/doc.php?name=../../config.php');
    ck('http: a path through doc.php is refused', $c === 404 && !str_contains($b, '<?php'));
    [$c, , $b] = $get('/doc/..%2F..%2Fconfig.php.pdf');
    ck('http: …and through the /doc/ route', $c === 404 && !str_contains($b, '<?php'));
}
if (is_resource($dsProc)) { proc_terminate($dsProc); proc_close($dsProc); }
@unlink($dsLog);
