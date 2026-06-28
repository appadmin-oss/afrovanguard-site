<?php
/**
 * lib/WordpressImport.php — shared WordPress (WXR) → Diary import core.
 *
 * Used by both the CLI tool (db/import_wordpress.php) and the browser-based
 * importer in the admin Studio (admin/api.php?action=diary_import_wp), so the
 * mapping logic lives in exactly one place. Dependency-free (SimpleXML).
 */
declare(strict_types=1);

/** Light wpautop: strip Gutenberg block comments; wrap bare text in <p> when the
 *  content has no block-level HTML (classic-editor posts store paragraphs as
 *  blank-line-separated plain text). Leaves already-structured HTML untouched. */
function av_wp_autop(string $html): string
{
    $html = preg_replace('/<!--\s*\/?wp:.*?-->/s', '', $html); // Gutenberg block markers
    $html = trim((string) $html);
    if ($html === '') return '';
    if (preg_match('/<(p|div|h[1-6]|ul|ol|li|blockquote|figure|table|pre|section|article)[\s>\/]/i', $html)) {
        return $html; // already structured
    }
    $out = '';
    foreach (preg_split('/\n\s*\n/', $html) as $block) {
        $block = trim($block);
        if ($block === '') continue;
        $out .= '<p>' . nl2br($block) . "</p>\n";
    }
    return $out !== '' ? $out : $html;
}

/** Card subtitle: the WP excerpt, else the opening of the body. */
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

/**
 * Parse a WXR export (XML string) and import posts into the Diary.
 *
 * @param string $xml  Raw WordPress export file contents.
 * @param array  $opts dry_run(bool), status('as-is'|'published'|'draft'),
 *                     default_category(string), include_pages(bool), limit(int).
 * @return array Summary: ok, error?, items, imported, updated, skipped,
 *               skip_reasons{}, categories[], posts[] (slug,title,status,action).
 */
function av_wordpress_import(string $xml, array $opts = [], ?DiaryRepository $repo = null): array
{
    $dryRun       = !empty($opts['dry_run']);
    $statusMode   = in_array($opts['status'] ?? 'as-is', ['as-is', 'published', 'draft'], true) ? $opts['status'] : 'as-is';
    $defaultCat   = trim((string) ($opts['default_category'] ?? 'Dispatch')) ?: 'Dispatch';
    $includePages = !empty($opts['include_pages']);
    $limit        = (int) ($opts['limit'] ?? 0);

    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
    if ($doc === false) {
        $msgs = array_map(fn($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        return ['ok' => false, 'error' => 'Could not parse the file as XML. Is it a WordPress export (WXR)? ' . implode('; ', array_slice($msgs, 0, 2))];
    }
    libxml_use_internal_errors($prev);

    if (!isset($doc->channel)) {
        return ['ok' => false, 'error' => 'This XML has no <channel> — it does not look like a WordPress export.'];
    }

    $ns = $doc->getDocNamespaces(true);
    $WP      = $ns['wp']      ?? 'http://wordpress.org/export/1.2/';
    $CONTENT = $ns['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
    $EXCERPT = $ns['excerpt'] ?? 'http://wordpress.org/export/1.2/excerpt/';
    $DC      = $ns['dc']      ?? 'http://purl.org/dc/elements/1.1/';

    $repo = $repo ?? new DiaryRepository();
    $gradients = ['g-gold', 'g-sky', 'g-green', 'g-sunset', 'g-ink'];

    $res = ['ok' => true, 'items' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0,
            'skip_reasons' => [], 'categories' => [], 'posts' => [], 'dry_run' => $dryRun];
    $cats = [];

    // Existing slugs → accurate new-vs-updated, even in dry-run.
    $existing = [];
    foreach (Database::pdo()->query('SELECT slug FROM articles')->fetchAll(PDO::FETCH_COLUMN) as $s) $existing[$s] = true;

    $allowed = $includePages ? ['post', 'page'] : ['post'];
    $idx = 0;
    foreach (($doc->channel->item ?? []) as $item) {
        $res['items']++;
        $wp = $item->children($WP);
        $type = (string) $wp->post_type;
        $wpStatus = strtolower((string) $wp->status);

        if (!in_array($type, $allowed, true)) { $res['skipped']++; $res['skip_reasons']['not a post'] = ($res['skip_reasons']['not a post'] ?? 0) + 1; continue; }
        if (in_array($wpStatus, ['auto-draft', 'trash', 'inherit'], true)) { $res['skipped']++; $res['skip_reasons']["status: $wpStatus"] = ($res['skip_reasons']["status: $wpStatus"] ?? 0) + 1; continue; }

        $title = trim((string) $item->title);
        $bodyRaw = (string) $item->children($CONTENT)->encoded;
        $exRaw   = (string) $item->children($EXCERPT)->encoded;
        if ($title === '' && trim(strip_tags($bodyRaw)) === '') { $res['skipped']++; $res['skip_reasons']['empty'] = ($res['skip_reasons']['empty'] ?? 0) + 1; continue; }
        if ($title === '') $title = 'Untitled';

        $slug = slugify(urldecode((string) $wp->post_name) ?: $title);

        $category = '';
        foreach ($item->category as $c) { if ((string) $c['domain'] === 'category') { $category = trim((string) $c); break; } }
        if ($category === '') $category = $defaultCat;
        $cats[$category] = true;

        $author = trim((string) $item->children($DC)->creator);
        $dateRaw = (string) $wp->post_date_gmt;
        if ($dateRaw === '' || strpos($dateRaw, '0000') === 0) $dateRaw = (string) $wp->post_date;
        if ($dateRaw === '' || strpos($dateRaw, '0000') === 0) $dateRaw = (string) $item->pubDate;
        $ts = $dateRaw ? strtotime($dateRaw) : false;
        if ($ts === false) $ts = time();

        $words = str_word_count(strip_tags($bodyRaw));
        $diaryStatus = $wpStatus === 'publish' ? 'published' : 'draft';
        if ($statusMode === 'published') $diaryStatus = 'published';
        elseif ($statusMode === 'draft') $diaryStatus = 'draft';

        $isNew = empty($existing[$slug]);
        $res['posts'][] = ['slug' => $slug, 'title' => $title, 'status' => $diaryStatus, 'action' => $isNew ? 'new' : 'updated'];

        if (!$dryRun) {
            try {
                $repo->save([
                    'slug' => $slug, 'title' => $title, 'dek' => av_make_dek($exRaw, $bodyRaw),
                    'category' => $category,
                    'authors_html' => htmlspecialchars($author !== '' ? $author : 'Afrovanguard', ENT_QUOTES, 'UTF-8'),
                    'published' => date('M j, Y', $ts), 'published_at' => date('Y-m-d', $ts),
                    'read_minutes' => max(1, (int) round($words / 200)),
                    'gradient' => $gradients[$idx++ % count($gradients)],
                    'mc_title' => $title, 'cover_url' => null, 'og_image' => null,
                    'body_html' => av_wp_autop($bodyRaw), 'featured' => 0,
                    'status' => $diaryStatus, 'format' => 'standard', 'sections' => [], 'related' => [],
                ]);
            } catch (Throwable $e) {
                $res['skipped']++; array_pop($res['posts']);
                $res['skip_reasons']['save error'] = ($res['skip_reasons']['save error'] ?? 0) + 1;
                continue;
            }
        }
        $existing[$slug] = true;
        $isNew ? $res['imported']++ : $res['updated']++;
        if ($limit && ($res['imported'] + $res['updated']) >= $limit) break;
    }

    $res['categories'] = array_keys($cats);
    return $res;
}
