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
