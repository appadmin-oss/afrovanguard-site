<?php
/**
 * lib/DocStream.php — the site's published documents (the ethos, the STS
 * report, the LCASP project document), and a way to serve them that keeps
 * PHP's memory flat however large the file and however slow the phone.
 *
 * Two ways a document reaches a reader:
 *
 *   1. The static file, which is what the pages link: /assets/docs/….pdf?v=<hash>.
 *      Apache serves it with sendfile, answers Range requests itself and holds
 *      no PHP worker for the minutes a 2G download takes; the root .htaccess
 *      gives it long caching for the stamped URL and keeps mod_deflate off it
 *      (a compressed PDF cannot be fetched by byte range).
 *
 *   2. /doc/<name>.pdf (doc.php) — and, on the development server, every
 *      static request for one of these files (router.php), because `php -S`
 *      ignores Range and sends no validators. send() answers with
 *      Content-Length, a single byte range (206/416), ETag + Last-Modified
 *      (304), and copies the file in fixed 64 KB chunks, flushing each.
 *
 * Only the names in DOCS are ever served: a name is a key, never a path, so
 * "../../config.php" has nothing to resolve against.
 *
 * The PDFs themselves are screen-quality (images at 150 dpi, JPEG q70; text and
 * vector art untouched) and linearized ("fast web view"), so a viewer can show
 * page 1 from the first range it asks for. The ethos is a scan with no text
 * layer; its 300 dpi original is kept as the "print quality" download.
 */
declare(strict_types=1);

final class DocStream
{
    /** Bytes per read. Memory use is this, not the file size. */
    public const CHUNK = 65536;

    /** A stamped URL never changes content, so it may be cached for a year. */
    public const CACHE_STAMPED = 'public, max-age=31536000, immutable';
    public const CACHE_PLAIN   = 'public, max-age=3600';

    /**
     * The allowlist. `file` is relative to the web root; `name` is what a
     * download is saved as; `pages` is checked against the file by the tests.
     */
    public const DOCS = [
        'afrovanguard-ethos' => [
            'file'  => 'assets/docs/afrovanguard-ethos.pdf',
            'name'  => 'Afrovanguard-Ethos.pdf',
            'title' => 'The Global Ethos of Afrovanguardism',
            'pages' => 16,
        ],
        'afrovanguard-ethos-print' => [
            'file'  => 'assets/docs/afrovanguard-ethos-print.pdf',
            'name'  => 'Afrovanguard-Ethos-print-quality.pdf',
            'title' => 'The Global Ethos of Afrovanguardism (print quality)',
            'pages' => 16,
        ],
        'sts-report-2025' => [
            'file'  => 'projects/sts/STS_Report_2025.pdf',
            'name'  => 'STS-2025-Impact-Report.pdf',
            'title' => 'Street-to-Stardom 2025 Impact Report',
            'pages' => 21,
        ],
        'lcasp-2026' => [
            'file'  => 'projects/sts/2026-LCASP-Document.pdf',
            'name'  => '2026-LCASP-Project-Document.pdf',
            'title' => 'LCASP 2026 Project Document',
            'pages' => 27,
        ],
    ];

    /**
     * Static pages that link these documents. They cannot call url(), so
     * tools/stamp-assets.php writes the stamps in and tests/docstream.test.php
     * fails when a stamp, size or page count shown there has gone stale.
     */
    public const PAGES = ['about.html', 'projects/sts/lcasp.html'];

    private static function root(): string
    {
        return defined('AV_ROOT') ? (string) AV_ROOT : dirname(__DIR__);
    }

    /**
     * An allowlisted document with its path, size and mtime — or null for any
     * name that is not a key of DOCS (including anything path-like) or whose
     * file is missing.
     *
     * @return array{key:string,file:string,name:string,title:string,pages:int,path:string,size:int,mtime:int}|null
     */
    public static function doc(string $key): ?array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $key) || !isset(self::DOCS[$key])) return null;
        $d    = self::DOCS[$key];
        $root = realpath(self::root());
        $path = realpath(self::root() . '/' . $d['file']);
        if ($root === false || $path === false || !is_file($path)
            || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) return null;
        clearstatcache(true, $path);
        return $d + ['key' => $key, 'path' => $path, 'size' => (int) filesize($path), 'mtime' => (int) filemtime($path)];
    }

    /** The allowlisted key whose file is at this URL path ("/projects/sts/x.pdf"), if any. */
    public static function keyForPath(string $uriPath): ?string
    {
        $rel = ltrim(rawurldecode($uriPath), '/');
        foreach (self::DOCS as $key => $d) {
            if ($d['file'] === $rel) return $key;
        }
        return null;
    }

    /**
     * A short content hash (the same shape as av_asset()'s): it changes when
     * the bytes do and not when a deploy merely touches the file. Hashing a
     * 5 MB file costs a few milliseconds, so the result is remembered next to
     * the other caches, keyed by size and mtime.
     */
    public static function hash(string $path): string
    {
        static $memo = [];
        clearstatcache(true, $path);
        $sig = (int) @filesize($path) . '-' . (int) @filemtime($path);
        if (isset($memo[$path][$sig])) return $memo[$path][$sig];
        $dir  = self::root() . '/db/cache';
        $file = $dir . '/docstream-' . md5($path) . '.json';
        $j = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($j) && ($j['sig'] ?? '') === $sig && preg_match('/^[0-9a-f]{10}$/', (string) ($j['hash'] ?? ''))) {
            $h = (string) $j['hash'];
        } else {
            $h = substr((string) md5_file($path), 0, 10);   // md5_file reads in blocks, not all at once
            $root = realpath(self::root());
            $mine = $root !== false && str_starts_with((string) realpath($path), $root . DIRECTORY_SEPARATOR);
            if ($mine && (is_dir($dir) || @mkdir($dir, 0775, true))) @file_put_contents($file, json_encode(['sig' => $sig, 'hash' => $h]), LOCK_EX);
        }
        $memo[$path] = [$sig => $h];
        return $h;
    }

    /** The URL pages link: the static file, stamped with its content hash. */
    public static function url(string $key): string
    {
        $d = self::doc($key);
        if (!$d) return '';
        return '/' . $d['file'] . '?v=' . self::hash($d['path']);
    }

    /** "2.0 MB", "640 KB" — decimal units, as phones report storage. */
    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1_000_000) return number_format($bytes / 1_000_000, 1) . ' MB';
        return max(1, (int) round($bytes / 1000)) . ' KB';
    }

    /** "2.0 MB, 16 pages" — what a reader is about to download. */
    public static function meta(string $key): string
    {
        $d = self::doc($key);
        return $d ? self::humanSize($d['size']) . ', ' . $d['pages'] . ' pages' : '';
    }

    /** "PDF, 2.0 MB, 16 pages" — shown next to every link to the document. */
    public static function label(string $key): string
    {
        $m = self::meta($key);
        return $m === '' ? '' : 'PDF, ' . $m;
    }

    /**
     * Rewrite every link to an allowlisted document in a static page so it
     * carries the current content hash (tools/stamp-assets.php). Matches
     * href="/path/file.pdf" and href="/path/file.pdf?v=…".
     */
    public static function stampHtml(string $html): string
    {
        $map = [];
        foreach (array_keys(self::DOCS) as $key) {
            $d = self::doc($key);
            if ($d) $map['/' . $d['file']] = self::url($key);
        }
        return (string) preg_replace_callback(
            '~(\bhref=")(/[^"?#<>\s]+\.pdf)(?:\?v=[0-9a-f]*)?(")~i',
            static fn(array $m): string => isset($map[$m[2]]) ? $m[1] . $map[$m[2]] . $m[3] : $m[0],
            $html
        );
    }

    /**
     * Parse a Range header against a file of $size bytes (RFC 9110 §14).
     *
     *   [start, end]  one satisfiable range (inclusive, clamped to the file)
     *   null          no range, or one this server ignores and answers with the
     *                 whole file: malformed syntax, another unit, several ranges
     *   false         syntactically valid but unsatisfiable → 416
     *
     * @return array{0:int,1:int}|null|false
     */
    public static function parseRange(?string $header, int $size): array|null|false
    {
        $h = trim((string) $header);
        if ($h === '' || !preg_match('/^bytes\s*=\s*(\d{0,18})\s*-\s*(\d{0,18})$/i', $h, $m)) return null;
        [$a, $b] = [$m[1], $m[2]];
        if ($a === '' && $b === '') return null;              // "bytes=-"
        if ($a === '') {                                      // suffix: the last N bytes
            $n = (int) $b;
            if ($n === 0 || $size === 0) return false;
            return [max(0, $size - $n), $size - 1];
        }
        $start = (int) $a;
        if ($b !== '' && (int) $b < $start) return null;      // "5-2" is invalid → ignore the header
        if ($start >= $size) return false;
        $end = $b === '' ? $size - 1 : (int) $b;
        return [$start, min($end, $size - 1)];
    }

    /** Does an If-None-Match list contain this ETag (weak comparison)? */
    private static function etagMatches(string $list, string $etag): bool
    {
        $list = trim($list);
        if ($list === '*') return true;
        $bare = static fn(string $t): string => preg_replace('~^W/~', '', trim($t)) ?? '';
        foreach (explode(',', $list) as $t) {
            if ($bare($t) === $bare($etag)) return true;
        }
        return false;
    }

    /**
     * Decide the response for a request, without sending anything: status,
     * headers, and which bytes the body is. Pure, so the tests can ask it.
     *
     * @param array $doc    from doc()
     * @param array $server $_SERVER-shaped: REQUEST_METHOD, HTTP_RANGE, HTTP_IF_RANGE,
     *                      HTTP_IF_NONE_MATCH, HTTP_IF_MODIFIED_SINCE
     * @param array $query  $_GET-shaped: v (content stamp), dl=1 (attachment)
     * @return array{status:int, headers:array<string,string>, start:int, length:int, body:bool}
     */
    public static function plan(array $doc, array $server, array $query = []): array
    {
        $size   = (int) $doc['size'];
        $etag   = '"' . self::hash($doc['path']) . '"';
        $lm     = gmdate('D, d M Y H:i:s', (int) $doc['mtime']) . ' GMT';
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $stamped = isset($query['v']) && is_string($query['v']) && '"' . $query['v'] . '"' === $etag;
        $fname  = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $doc['name']);
        $attach = (string) ($query['dl'] ?? '') === '1';

        $h = [
            'Content-Type'           => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges'          => 'bytes',
            'ETag'                   => $etag,
            'Last-Modified'          => $lm,
            'Cache-Control'          => $stamped ? self::CACHE_STAMPED : self::CACHE_PLAIN,
            'Content-Disposition'    => ($attach ? 'attachment' : 'inline') . '; filename="' . $fname . '"',
        ];
        $out = static fn(int $status, array $headers, int $start = 0, int $length = 0): array =>
            ['status' => $status, 'headers' => $headers, 'start' => $start, 'length' => $length,
             'body' => $method !== 'HEAD' && $length > 0];

        if ($method !== 'GET' && $method !== 'HEAD') {
            return $out(405, ['Allow' => 'GET, HEAD', 'Content-Length' => '0', 'X-Content-Type-Options' => 'nosniff']);
        }

        // Conditional GET: If-None-Match wins over If-Modified-Since when both are sent.
        $inm = (string) ($server['HTTP_IF_NONE_MATCH'] ?? '');
        $ims = (string) ($server['HTTP_IF_MODIFIED_SINCE'] ?? '');
        $fresh = $inm !== '' ? self::etagMatches($inm, $etag)
               : ($ims !== '' && ($t = strtotime($ims)) !== false && $t >= (int) $doc['mtime']);
        if ($fresh) {
            unset($h['Content-Type'], $h['Content-Disposition']);
            return $out(304, $h);
        }

        // If-Range: only honour the Range when the client's copy is this one.
        $range = (string) ($server['HTTP_RANGE'] ?? '');
        $ifr   = trim((string) ($server['HTTP_IF_RANGE'] ?? ''));
        if ($range !== '' && $ifr !== '' && $ifr !== $etag && $ifr !== $lm) $range = '';

        $r = self::parseRange($range, $size);
        if ($r === false) {
            return $out(416, ['Content-Range' => 'bytes */' . $size, 'Content-Length' => '0',
                              'Accept-Ranges' => 'bytes', 'X-Content-Type-Options' => 'nosniff']);
        }
        if (is_array($r)) {
            [$s, $e] = $r;
            $h['Content-Range']  = 'bytes ' . $s . '-' . $e . '/' . $size;
            $h['Content-Length'] = (string) ($e - $s + 1);
            return $out(206, $h, $s, $e - $s + 1);
        }
        $h['Content-Length'] = (string) $size;
        return $out(200, $h, 0, $size);
    }

    /**
     * Copy $length bytes of $path from $start to $write, one CHUNK at a time.
     * $write receives each chunk; returning false stops the copy (the client
     * went away). Returns the number of bytes handed over.
     */
    public static function pipe(string $path, int $start, int $length, callable $write, int $chunk = self::CHUNK): int
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) return 0;
        $sent = 0;
        try {
            if ($start > 0 && fseek($fh, $start) !== 0) return 0;
            while ($sent < $length && !feof($fh)) {
                $buf = fread($fh, min($chunk, $length - $sent));
                if ($buf === false || $buf === '') break;
                $sent += strlen($buf);
                if ($write($buf) === false) break;
                unset($buf);
            }
        } finally {
            fclose($fh);
        }
        return $sent;
    }

    /**
     * Answer the current request for an allowlisted document and stop.
     * Unknown names get a plain 404; nothing outside DOCS is ever opened.
     */
    public static function send(string $key, ?array $server = null, ?array $query = null): never
    {
        $doc = self::doc($key);
        if (!$doc) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            echo "Not found\n";
            exit;
        }
        $p = self::plan($doc, $server ?? $_SERVER, $query ?? $_GET);

        // Nothing may sit between fread() and the socket: no output buffer
        // collecting the file, and no compression (a gzipped body cannot be
        // range-addressed, and the viewer asks for ranges).
        @ini_set('zlib.output_compression', '0');
        if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
        while (ob_get_level() > 0) @ob_end_clean();
        header_remove('X-Powered-By');
        header_remove('Pragma');
        header_remove('Expires');

        http_response_code($p['status']);
        foreach ($p['headers'] as $k => $v) header($k . ': ' . $v);
        if (!$p['body']) exit;

        ignore_user_abort(false);
        self::pipe($doc['path'], $p['start'], $p['length'], static function (string $buf): bool {
            echo $buf;
            flush();
            // The limit is per chunk, not per file: a slow 2G download keeps
            // going as long as it keeps moving, and a stalled one still ends.
            @set_time_limit(30);
            return connection_aborted() === 0;
        });
        exit;
    }
}
