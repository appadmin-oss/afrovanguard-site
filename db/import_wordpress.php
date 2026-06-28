<?php
/**
 * db/import_wordpress.php — migrate a WordPress blog into the Afrovanguard Diary (CLI).
 *
 * No SSH? Use the browser importer instead: Admin Studio → Diary → "Import from
 * WordPress". This CLI is for servers that do have shell access; both share the
 * same engine in lib/WordpressImport.php.
 *
 * Export from WordPress:  wp-admin → Tools → Export → "Posts" → Download Export File
 *
 * USAGE (from the project root):
 *   php db/import_wordpress.php path/to/wordpress-export.xml [options]
 *
 * OPTIONS:
 *   --dry-run                 Parse + report what would happen; write nothing.
 *   --status=as-is|published|draft   as-is (default) keeps WP publish/draft state.
 *   --default-category=NAME   Category for posts with none (default "Dispatch").
 *   --include-pages           Also import WordPress "pages" (default: posts only).
 *   --limit=N                 Import at most N posts (handy for a trial run).
 *   --help                    Show this help.
 *
 * Safe to re-run: an existing entry with the same slug is UPDATED, not duplicated.
 * Post slugs are preserved, so existing /blog/<slug> links keep resolving.
 *
 * IMAGES: in-content images point at /wp-content/uploads/ — they keep working only
 * if you leave that folder in place after removing WP core (or re-host them).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This importer runs from the command line only.\n"); }

define('AV_ROOT', dirname(__DIR__));

$opts = ['status' => 'as-is', 'default-category' => 'Dispatch', 'limit' => 0,
         'dry-run' => false, 'include-pages' => false, 'help' => false];
$file = null;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--') === 0) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, true);
        if (array_key_exists($k, $opts)) $opts[$k] = $v; else { fwrite(STDERR, "Unknown option: --$k\n"); exit(2); }
    } elseif ($file === null) { $file = $a; }
}
if ($opts['help'] || $file === null) {
    $h = file_get_contents(__FILE__);
    if (preg_match('/USAGE.*?(?=\*\/)/s', $h, $m)) echo preg_replace('/^\s*\*\s?/m', '', $m[0]);
    exit($opts['help'] ? 0 : 2);
}
if (!is_file($file) || !is_readable($file)) { fwrite(STDERR, "Cannot read file: $file\n"); exit(2); }

require_once AV_ROOT . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/WordpressImport.php';

$r = av_wordpress_import(file_get_contents($file), [
    'dry_run' => $opts['dry-run'] !== false,
    'status' => $opts['status'],
    'default_category' => $opts['default-category'],
    'include_pages' => $opts['include-pages'] !== false,
    'limit' => (int) $opts['limit'],
]);

if (empty($r['ok'])) { fwrite(STDERR, ($r['error'] ?? 'Import failed.') . "\n"); exit(1); }

echo ($r['dry_run'] ? "DRY RUN — no changes written.\n" : "Importing into the Diary…\n");
echo "Source: $file\n" . str_repeat('─', 60) . "\n";
foreach ($r['posts'] as $p) {
    printf("  %s [%-5s] /diary/%s\n", $p['action'] === 'new' ? 'NEW' : 'UPD', $p['status'] === 'published' ? 'pub' : 'draft', $p['slug']);
}
echo str_repeat('─', 60) . "\n";
echo ($r['dry_run'] ? "Would import" : "Imported") . ": {$r['imported']} new, {$r['updated']} updated · Skipped: {$r['skipped']}\n";
foreach ($r['skip_reasons'] as $reason => $n) echo "    · $reason: $n\n";
echo 'Categories: ' . implode(', ', $r['categories']) . "\n";
echo "Items scanned: {$r['items']}\n";
echo $r['dry_run'] ? "\nRe-run without --dry-run to write these entries.\n" : "\nDone. Visit /diary/ — re-running is safe (updates by slug).\n";
exit(0);
