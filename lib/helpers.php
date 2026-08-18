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

/**
 * The five card gradients the Studio offers. Anything else is a typo or an
 * injection attempt — this value is emitted as a CSS class name.
 */
const AV_CARD_GRADIENTS = ['g-gold', 'g-ink', 'g-sunset', 'g-sky', 'g-green'];

/** Coerce a submitted card gradient to one we actually ship. */
function av_card_gradient(?string $g): string {
    $g = strtolower(trim((string) $g));
    return in_array($g, AV_CARD_GRADIENTS, true) ? $g : 'g-gold';
}

/**
 * Accept an asset URL only in the two shapes the app really produces: an
 * absolute http(s) URL (Cloudinary, a CDN) or a root-relative path (local
 * /uploads). Anything else — `javascript:`, `data:`, a protocol-relative
 * `//host`, or a value carrying quotes, angle brackets or control characters —
 * returns '' and the caller falls back to the gradient.
 *
 * These values land inside `style="background-image:url('…')"` and `src`
 * attributes. Rendering escapes them; this stops them being storable at all,
 * because two defences beat one and the render sites are easy to add to.
 */
function av_safe_asset_url(?string $url): string {
    $url = trim((string) $url);
    if ($url === '') return '';
    if (strlen($url) > 2048) return '';
    // Nothing that could break out of an HTML attribute or a CSS url(): quotes,
    // angle brackets, backticks, backslashes, whitespace or control bytes.
    if (strcspn($url, "\"'<>`\\ \t\r\n\v\f") !== strlen($url)) return '';
    if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) return '';

    if ($url[0] === '/') {
        // `//host/path` is protocol-relative, not root-relative — the browser
        // treats it as another origin, so it is not a local path.
        if (isset($url[1]) && $url[1] === '/') return '';
        return strpos($url, '..') === false ? $url : '';
    }
    if (!preg_match('~^https?://~i', $url)) return '';
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return '';
    if (!empty($p['user']) || !empty($p['pass'])) return '';   // no embedded credentials
    return $url;
}

/**
 * A stored byline, safe to emit as markup.
 *
 * `authors_html` is intentionally HTML — a byline may carry a link to an author
 * page — so it is sanitized on save. This covers rows written before that was
 * true, and costs a parse only for bylines that actually contain markup, which
 * almost none do.
 */
function av_byline_html(?string $html): string {
    $html = (string) $html;
    if (strpos($html, '<') === false) return e($html);
    // Embeds is not in the bootstrap's eager set — the public article page never
    // needed it before — so load it here rather than silently degrading to
    // strip_tags() and dropping the author link this field exists to carry.
    if (!class_exists('Embeds')) {
        $lib = __DIR__ . '/Embeds.php';
        if (is_file($lib)) require_once $lib;
    }
    return class_exists('Embeds') ? Embeds::sanitize($html) : e(strip_tags($html));
}

/**
 * Read a local asset by its web path.
 *
 * A leading slash is not permission to read the server: the path is confined to
 * the directories the app itself writes assets into, resolved with realpath so a
 * symlink cannot lead out of them.
 */
function av_read_local_asset(string $path): ?string {
    if (strpos($path, "\0") !== false) return null;
    $parts = parse_url($path);
    if ($parts === false || !empty($parts['host'])) return null;   // `//host/x` is not local
    $p = (string) ($parts['path'] ?? '');
    if ($p === '' || $p[0] !== '/' || strpos($p, '..') !== false) return null;

    $abs = realpath(AV_ROOT . rawurldecode($p));
    if ($abs === false || !is_file($abs)) return null;
    foreach (['/uploads', '/assets'] as $dir) {
        $root = realpath(AV_ROOT . $dir);
        if ($root !== false && strncmp($abs, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0) {
            return (string) file_get_contents($abs);
        }
    }
    return null;
}

/**
 * Fetch image bytes for a local (web-root-relative) path or an http(s) URL.
 *
 * The URL comes from a cover-image field, so it is chosen by an editor — the
 * lowest admin tier — and this runs on the server. Both halves are therefore
 * confined: the local branch to the asset directories, and the remote branch to
 * public addresses via `AvWeb::guard()`, which re-validates after every redirect.
 * It previously did neither, which made an editor-supplied string into a request
 * against whatever the server can reach (audit finding M-5).
 */
function av_fetch_image_bytes(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    if ($url[0] === '/') return av_read_local_asset($url);
    if (!preg_match('~^https?://~i', $url)) return null;

    if (!class_exists('AvWeb')) {
        $lib = __DIR__ . '/AvWeb.php';
        if (!is_file($lib)) return null;
        require_once $lib;
    }
    return AvWeb::fetchBytes($url, 8000000);
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

/** Current wall-clock in a timezone (default the org tz), e.g. '2026-07-17 14:05'. */
function av_now_tz(string $fmt = 'Y-m-d H:i', ?string $tz = null): string {
    try { return (new DateTime('now', new DateTimeZone($tz ?: (defined('AV_TZ') ? AV_TZ : 'UTC'))))->format($fmt); }
    catch (Throwable $e) { return gmdate($fmt); }
}

/** Today's date (Y-m-d) in a timezone (default the org tz). */
function av_today_tz(?string $tz = null): string { return av_now_tz('Y-m-d', $tz); }

/** A user's timezone: their saved preference, else the org default. */
function av_user_tz(int $uid): string {
    if ($uid > 0 && class_exists('Prefs')) { $t = Prefs::get($uid, 'tz', ''); if ($t !== '') return $t; }
    return defined('AV_TZ') ? AV_TZ : 'UTC';
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

/**
 * May this member READ this diary entry?
 *
 * Three ways in, and they are genuinely different rights:
 *
 *   1. they wrote it
 *   2. it was shared with them directly (diary_shares — one entry, one person)
 *   3. it sits in a notebook that is shared with them (diary_notebook_members)
 *
 * (3) is the one that needs saying out loud: notebook access covers entries
 * that did not exist when the share was granted. That is the entire reason
 * notebook sharing exists — a mentor given the mentoring notebook should not
 * need re-granting every time a session is written up.
 *
 * Lives here rather than in DiaryTabs or DiaryNotebooks because it is a
 * question about an ENTRY that spans both, and burying it in either would mean
 * the other one answers it differently one day.
 */
function av_diary_may_read(int $uid, int $entryId): bool {
    if ($uid <= 0 || $entryId <= 0) return false;

    // Each route gets its OWN guard. One try around all three means a missing
    // `diary_shares` — created lazily, so it genuinely does not exist until
    // somebody first shares an entry — throws on route 2 and skips route 3
    // entirely, silently denying every notebook member. That is exactly what it
    // did on the first run of this function's own tests.
    try {
        $st = Database::pdo()->prepare('SELECT author_id, notebook_id FROM diary_entries WHERE id = ?');
        $st->execute([$entryId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return false;
    } catch (Throwable $e) { return false; }

    if ((int) $r['author_id'] === $uid) return true;

    try {
        $s = Database::pdo()->prepare('SELECT 1 FROM diary_shares WHERE entry_id = ? AND user_id = ?');
        $s->execute([$entryId, $uid]);
        if ($s->fetchColumn()) return true;
    } catch (Throwable $e) { /* table not created yet: a "no", not a stop */ }

    try {
        $nbId = (int) ($r['notebook_id'] ?? 0);
        if ($nbId > 0 && class_exists('DiaryNotebooks')) {
            return (new DiaryNotebooks(Database::pdo()))->canRead($uid, $nbId);
        }
    } catch (Throwable $e) { /* ditto */ }

    return false;
}
