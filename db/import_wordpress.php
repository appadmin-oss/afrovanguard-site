<?php
/**
 * db/import_wordpress.php — migrate a WordPress blog into the Afrovanguard Diary.
 *
 * WordPress can export everything to a single standard XML file (WXR):
 *   wp-admin → Tools → Export → "Posts" (or "All content") → Download Export File
 *
 * This reads that file and creates one Diary entry per post, reusing
 * DiaryRepository::save() so categories auto-create, slugs de-dup, reactions
 * seed and the diary.published event fires exactly as for a hand-written entry.
 *
 * Post slugs are PRESERVED, so existing /blog/<slug> links keep working — the
 * site already 301-redirects /blog/* → /diary/* (see router.php / blog/index.php).
 *
 * USAGE (run from the project root, e.g. via cPanel → Terminal):
 *   php db/import_wordpress.php path/to/wordpress-export.xml [options]
 *
 * OPTIONS:
 *   --dry-run                 Parse + report what would happen; write nothing.
 *   --status=as-is|published|draft
 *                             as-is (default): WP 'publish' → published, else draft.
 *                             published / draft: force every imported post.
 *   --default-category=NAME   Category for posts that have none (default "Dispatch").
 *   --include-pages           Also import WordPress "pages" (default: posts only).
 *   --limit=N                 Import at most N posts (handy for a trial run).
 *   --help                    Show this help.
 *
 * Safe to re-run: an existing Diary entry with the same slug is UPDATED, not
 * duplicated — so you can do a --dry-run, then a real run, then re-run after
 * publishing more in WordPress.
 *
 * NOTE ON IMAGES: in-content images that point at /wp-content/uploads/ keep
 * working only if you leave that uploads folder in place after removing WP core.
 * If you delete it, re-host those images (e.g. Cloudinary) and update the entries.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This importer runs from the command line only.\n");
}

define('AV_ROOT', dirname(__DIR__));

/* ── Parse arguments ──────────────────────────────────────────────────────── */
$argvRest = array_slice($argv, 1);
$opts = ['status' => 'as-is', 'default-category' => 'Dispatch', 'limit' => 0,
         'dry-run' => false, 'include-pages' => false, 'help' => false];
$file = null;
foreach ($argvRest as $a) {
    if (strpos($a, '--') === 0) {
        $kv = explode('=', substr($a, 2), 2);
        $k = $kv[0]; $v = $kv[1] ?? true;
        if (array_key_exists($k, $opts)) $opts[$k] = $v;
        else { fwrite(STDERR, "Unknown option: --$k\n"); exit(2); }
    } elseif ($file === null) {
        $file = $a;
    }
}

function usage(): void {
    $h = file_get_contents(__FILE__);
    if (preg_match('/USAGE.*?(?=\*\/)/s', $h, $m)) echo preg_replace('/^\s*\*\s?/m', '', $m[0]);
}
if ($opts['help'] || $file === null) { usage(); exit($opts['help'] ? 0 : 2); }
if (!is_file($file) || !is_readable($file)) { fwrite(STDERR, "Cannot read file: $file\n"); exit(2); }

$statusMode = in_array($opts['status'], ['as-is', 'published', 'draft'], true) ? $opts['status'] : 'as-is';
$limit = (int) $opts['limit'];
$dryRun = $opts['dry-run'] !== false;
$includePages = $opts['include-pages'] !== false;

require_once AV_ROOT . '/lib/bootstrap.php';

/* ── Helpers ──────────────────────────────────────────────────────────────── */

/** Light wpautop: strip Gutenberg block comments; wrap bare text in <p> when the
 *  content has no block-level HTML (classic-editor posts store paragraphs as
 *  blank-line-separated plain text). Leaves already-structured HTML untouched. */
function av_wp_autop(string $html): string
{
    $html = preg_replace('/<!--\s*\/?wp:.*?-->/s', '', $html); // Gutenberg block markers
    $html = trim((string) $html);
    if ($html === '') return '';
    if (preg_match('/<(p|div|h[1-6]|ul|ol|li|blockquote|figure|table|pre|section|article)[\s>\/]/i', $html)) {
        return $html; // already has block-level structure
    }
    $out = '';
    foreach (preg_split('/\n\s*\n/', $html) as $block) {
        $block = trim($block);
        if ($block === '') continue;
        $out .= '<p>' . nl2br($block) . "</p>\n";
    }
    return $out !== '' ? $out : $html;
}

/** First paragraph / sentence-ish summary for the dek (card subtitle). */
function av_make_dek(string $excerptRaw, string $bodyRaw): string
{
    $dek = trim(preg_replace('/\s+/', ' ', strip_tags($excerptRaw)) ?? '');
    if ($dek === '') {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($bodyRaw)) ?? '');
        $dek = mb_substr($plain, 0, 200);
        if (mb_strlen($plain) > 200) $dek = preg_replace('/\s+\S*$/u', '', $dek) . '…';
    }
    return $dek !== '' ? $dek : 'A dispatch from the Afrovanguard Diary.';
}

$GRADIENTS = ['g-gold', 'g-sky', 'g-green', 'g-sunset', 'g-ink'];

/* ── Load the WXR file ────────────────────────────────────────────────────── */
$prev = libxml_use_internal_errors(true);
$xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse XML. Is this a WordPress export (WXR) file?\n");
    foreach (libxml_get_errors() as $e) fwrite(STDERR, '  ' . trim($e->message) . "\n");
    exit(1);
}
libxml_use_internal_errors($prev);

$ns = $xml->getDocNamespaces(true);
$WP = $ns['wp']       ?? 'http://wordpress.org/export/1.2/';
$CONTENT = $ns['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
$EXCERPT = $ns['excerpt'] ?? 'http://wordpress.org/export/1.2/excerpt/';
$DC = $ns['dc']       ?? 'http://purl.org/dc/elements/1.1/';

$items = $xml->channel->item ?? [];
$repo = new DiaryRepository();

$stats = ['items' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0];
$skipReasons = [];
$catsSeen = [];
$idx = 0;

echo ($dryRun ? "DRY RUN — no changes will be written.\n" : "Importing into the Diary…\n");
echo "Source: $file\n";
echo str_repeat('─', 60) . "\n";

// Which slugs already exist (for an accurate new-vs-updated count, esp. in dry-run).
$existing = [];
foreach (Database::pdo()->query('SELECT slug FROM articles')->fetchAll(PDO::FETCH_COLUMN) as $s) $existing[$s] = true;

foreach ($items as $item) {
    $stats['items']++;
    $wp = $item->children($WP);
    $postType = (string) $wp->post_type;
    $wpStatus = strtolower((string) $wp->status);

    // Only blog posts (and pages when asked); never revisions/menus/attachments.
    $allowedTypes = $includePages ? ['post', 'page'] : ['post'];
    if (!in_array($postType, $allowedTypes, true)) { $stats['skipped']++; $skipReasons['not a post' . ($includePages ? '/page' : '')] = ($skipReasons['not a post' . ($includePages ? '/page' : '')] ?? 0) + 1; continue; }
    if (in_array($wpStatus, ['auto-draft', 'trash', 'inherit'], true)) { $stats['skipped']++; $skipReasons["status: $wpStatus"] = ($skipReasons["status: $wpStatus"] ?? 0) + 1; continue; }

    $title = trim((string) $item->title);
    $bodyRaw = (string) $item->children($CONTENT)->encoded;
    $exRaw = (string) $item->children($EXCERPT)->encoded;
    if ($title === '' && trim(strip_tags($bodyRaw)) === '') { $stats['skipped']++; $skipReasons['empty'] = ($skipReasons['empty'] ?? 0) + 1; continue; }
    if ($title === '') $title = 'Untitled';

    $slug = slugify(urldecode((string) $wp->post_name) ?: $title);

    // First "category" taxonomy term (skip post_tag).
    $category = '';
    foreach ($item->category as $c) {
        if ((string) $c['domain'] === 'category') { $category = trim((string) $c); break; }
    }
    if ($category === '') $category = (string) $opts['default-category'];
    $catsSeen[$category] = true;

    $author = trim((string) $item->children($DC)->creator);
    $dateRaw = (string) $wp->post_date_gmt;
    if ($dateRaw === '' || strpos($dateRaw, '0000') === 0) $dateRaw = (string) $wp->post_date;
    if ($dateRaw === '' || strpos($dateRaw, '0000') === 0) $dateRaw = (string) $item->pubDate;
    $ts = $dateRaw ? strtotime($dateRaw) : false;
    if ($ts === false) $ts = time();

    $body = av_wp_autop($bodyRaw);
    $words = str_word_count(strip_tags($bodyRaw));

    $diaryStatus = $wpStatus === 'publish' ? 'published' : 'draft';
    if ($statusMode === 'published') $diaryStatus = 'published';
    elseif ($statusMode === 'draft') $diaryStatus = 'draft';

    $d = [
        'slug' => $slug,
        'title' => $title,
        'dek' => av_make_dek($exRaw, $bodyRaw),
        'category' => $category,
        'authors_html' => htmlspecialchars($author !== '' ? $author : 'Afrovanguard', ENT_QUOTES, 'UTF-8'),
        'published' => date('M j, Y', $ts),
        'published_at' => date('Y-m-d', $ts),
        'read_minutes' => max(1, (int) round($words / 200)),
        'gradient' => $GRADIENTS[$idx++ % count($GRADIENTS)],
        'mc_title' => $title,
        'cover_url' => null,   // WP uploads will be removed; set covers later (e.g. Cloudinary)
        'og_image' => null,
        'body_html' => $body,
        'featured' => 0,
        'status' => $diaryStatus,
        'format' => 'standard',
        'sections' => [],
        'related' => [],
    ];

    $isNew = empty($existing[$slug]);
    $tag = $isNew ? 'NEW ' : 'UPD ';
    printf("  %s [%s] %-9s /diary/%s\n", $tag, $diaryStatus === 'published' ? 'pub  ' : 'draft', '', $slug);

    if (!$dryRun) {
        try {
            $repo->save($d);
        } catch (Throwable $e) {
            $stats['skipped']++; $skipReasons['save error: ' . $e->getMessage()] = ($skipReasons['save error: ' . $e->getMessage()] ?? 0) + 1;
            continue;
        }
    }
    $existing[$slug] = true;
    $isNew ? $stats['imported']++ : $stats['updated']++;

    if ($limit && ($stats['imported'] + $stats['updated']) >= $limit) { echo "  …limit ($limit) reached.\n"; break; }
}

/* ── Summary ──────────────────────────────────────────────────────────────── */
echo str_repeat('─', 60) . "\n";
echo ($dryRun ? "Would import" : "Imported") . ": {$stats['imported']} new, {$stats['updated']} updated\n";
echo "Skipped: {$stats['skipped']}\n";
foreach ($skipReasons as $r => $n) echo "    · $r: $n\n";
echo "Categories: " . implode(', ', array_keys($catsSeen)) . "\n";
echo "Items scanned: {$stats['items']}\n";
if ($dryRun) echo "\nRe-run without --dry-run to write these entries.\n";
else echo "\nDone. Visit /diary/ to see them. Re-running is safe (updates by slug).\n";
exit(0);
