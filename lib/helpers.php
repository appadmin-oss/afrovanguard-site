<?php
/**
 * lib/helpers.php — small, shared view + request helpers.
 * Used across every Diary page and the API so behaviour stays in sync.
 */
declare(strict_types=1);

/** HTML-escape for safe output in markup/attributes. */
function e(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL/anchor-safe slug. */
function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'entry';
}

/** Fetch image bytes for a local (web-root-relative) path or an http(s) URL. */
function av_fetch_image_bytes(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    if ($url[0] === '/') {                       // web-root-relative local file
        $p = AV_ROOT . $url;
        return is_file($p) ? (string) file_get_contents($p) : null;
    }
    if (preg_match('~^https?://~i', $url) && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_USERAGENT => 'AfrovanguardBot/1.0',
        ]);
        $b = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($b !== false && $code >= 200 && $code < 300 && strlen((string) $b) < 8000000) ? (string) $b : null;
    }
    return null;
}

/**
 * Is a cover image dark where text sits? Computed server-side (GD) at save time
 * so overlaid text can pick a legible colour with no client work or CORS limits.
 *   $region: 'top' (cover chips), 'bottom' (hero title), or 'all'.
 * Returns 1 (dark → light text), 0 (light → dark text), or null (unknown).
 */
function av_cover_is_dark(?string $url, string $region = 'top'): ?int {
    $url = trim((string) $url);
    if ($url === '' || !function_exists('imagecreatefromstring')) return null;
    $bytes = av_fetch_image_bytes($url);
    if ($bytes === null || $bytes === '') return null;
    $img = @imagecreatefromstring($bytes);
    if (!$img) return null;
    if (function_exists('imagepalettetotruecolor')) @imagepalettetotruecolor($img);
    $w = imagesx($img); $h = imagesy($img);
    if ($w < 2 || $h < 2) { imagedestroy($img); return null; }
    if ($region === 'bottom')      { $rx = 0; $ry = (int) ($h * 0.55); $rw = $w; $rh = $h - $ry; }
    elseif ($region === 'all')     { $rx = 0; $ry = 0; $rw = $w; $rh = $h; }
    else                           { $rx = 0; $ry = 0; $rw = $w; $rh = (int) ($h * 0.5); }
    $stepX = max(1, (int) ($rw / 24)); $stepY = max(1, (int) ($rh / 24));
    $sum = 0.0; $n = 0;
    for ($y = $ry; $y < $ry + $rh; $y += $stepY) {
        for ($x = $rx; $x < $rx + $rw; $x += $stepX) {
            $rgb = imagecolorat($img, $x, $y);
            $sum += 0.2126 * (($rgb >> 16) & 0xFF) + 0.7152 * (($rgb >> 8) & 0xFF) + 0.0722 * ($rgb & 0xFF);
            $n++;
        }
    }
    imagedestroy($img);
    if ($n === 0) return null;
    return (($sum / $n) / 255) < 0.6 ? 1 : 0;
}

/** Human "time ago" from an ISO/Y-m-d date. */
function time_ago(string $date): string {
    $t = strtotime($date);
    if (!$t) return '';
    $d = time() - $t;
    if ($d < 0) return date('M j, Y', $t);
    foreach ([31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day'] as $s => $u) {
        if ($d >= $s) { $n = (int) floor($d / $s); return $n . ' ' . $u . ($n > 1 ? 's' : '') . ' ago'; }
    }
    return 'today';
}

/**
 * Ensure every <h2> has a stable id and return [cleanHtml, sections].
 * Lets editors write plain headings; the TOC is derived automatically.
 */
function extract_sections(string $html): array {
    $sections = [];
    if (trim($html) === '') return [$html, $sections];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $seen = [];
    foreach ($doc->getElementsByTagName('h2') as $h2) {
        $label = trim($h2->textContent);
        if ($label === '') continue;
        $id = $h2->getAttribute('id') ?: slugify($label);
        $base = $id; $i = 2;
        while (isset($seen[$id])) { $id = $base . '-' . $i++; }
        $seen[$id] = true;
        $h2->setAttribute('id', $id);
        $sections[] = [$id, $label];
    }
    // Give h3 (e.g. Q&A questions) stable ids too, so they're deep-linkable
    // and shareable — but keep them out of the table of contents.
    foreach (iterator_to_array($doc->getElementsByTagName('h3')) as $h3) {
        $label = trim($h3->textContent);
        if ($label === '' || $h3->getAttribute('id') !== '') continue;
        $id = 'q-' . slugify($label); $base = $id; $i = 2;
        while (isset($seen[$id])) { $id = $base . '-' . $i++; }
        $seen[$id] = true;
        $h3->setAttribute('id', $id);
    }
    // Serialise inner HTML of the wrapper back out.
    $root = $doc->getElementById('__root');
    $out = '';
    foreach ($root->childNodes as $n) { $out .= $doc->saveHTML($n); }
    return [$out, $sections];
}

/** Canonical absolute URL for a diary path (always pretty, trailing slash). */
function diary_url(string $path = ''): string {
    $path = ltrim($path, '/');
    return rtrim(SITE_URL, '/') . '/diary/' . $path;
}

/** Send a JSON response and end the request. */
function json_out($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ── Schema.org / JSON-LD builders (shared, so structured data stays in sync) ── */

function schema_org(): array {
    return [
        '@type' => ['Organization', 'NGO'],
        '@id'   => SITE_URL . '/#organization',
        'name'  => 'Afrovanguard',
        'url'   => rtrim(SITE_URL, '/') . '/',
        'logo'  => rtrim(SITE_URL, '/') . '/Images/og-image.png',
        'sameAs' => [
            'https://www.instagram.com/afrovanguard/',
            'https://twitter.com/afrovanguard',
            'https://www.facebook.com/afrovanguard/',
            'https://www.linkedin.com/company/afrovanguard/',
            'https://www.youtube.com/@afrovanguard',
        ],
    ];
}

function schema_website(): array {
    return [
        '@type' => 'WebSite',
        '@id'   => SITE_URL . '/#website',
        'url'   => rtrim(SITE_URL, '/') . '/',
        'name'  => 'Afrovanguard',
        'publisher' => ['@id' => SITE_URL . '/#organization'],
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => diary_url('?q={search_term_string}')],
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

/** BreadcrumbList from [['name'=>..,'url'=>..], ...]. */
function schema_breadcrumb(array $items): array {
    $list = [];
    foreach ($items as $i => $it) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $it['name']]
                + (isset($it['url']) ? ['item' => $it['url']] : []);
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

/** Reject cross-origin state-changing requests (lightweight CSRF guard). */
function require_same_origin(): void {
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($origin === '') return; // some privacy modes strip these; allow but rely on rate limit
    $oh = parse_url($origin, PHP_URL_HOST) ?: '';
    if ($oh !== '' && $host !== '' && stripos($host, $oh) === false && stripos($oh, $host) === false) {
        json_out(['ok' => false, 'error' => 'Cross-origin request rejected.'], 403);
    }
}

/**
 * One-liner guard for a JSON write endpoint: same-origin + CSRF token
 * (X-CSRF-Token header) + per-user rate limit. json_out()s and exits on any
 * failure. Replaces the copy-pasted $writeGuard closures across portal/*.php.
 */
function av_require_write(int $uid, string $bucket, int $max = 40, int $window = 600): void {
    require_same_origin();
    if (!av_csrf_valid((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        json_out(['ok' => false, 'error' => 'Bad token.'], 403);
    }
    if (!av_rate_ok($bucket . '_' . $uid, $max, $window)) {
        json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
    }
}

/** Humanise a UTC 'Y-m-d H:i:s' timestamp as "just now / 5m ago / 3h ago / 2d ago". */
function av_ago(string $ts): string {
    $t = strtotime($ts . ' UTC') ?: 0; if (!$t) return '';
    $d = max(0, time() - $t);
    if ($d < 60)    return 'just now';
    if ($d < 3600)  return (int) floor($d / 60) . 'm ago';
    if ($d < 86400) return (int) floor($d / 3600) . 'h ago';
    return (int) floor($d / 86400) . 'd ago';
}
