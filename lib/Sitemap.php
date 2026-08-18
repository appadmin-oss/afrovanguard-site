<?php
/**
 * lib/Sitemap.php — auto-maintained sitemap.xml + robots.txt.
 *
 * Writes physical files at the document root so they are authoritative
 * (WordPress can never override a real file). Rebuilt automatically when
 * content changes (admin saves) and lazily refreshed on the public index
 * pages. Best-effort: if the docroot isn't writable, the committed files
 * remain in place and nothing breaks.
 */
declare(strict_types=1);

final class Sitemap
{
    private const STATIC_PAGES = [
        ['', '1.0', 'weekly'],
        ['about/', '0.8', 'monthly'],
        ['ethos/', '0.9', 'monthly'],
        ['academy/', '0.9', 'weekly'],
        ['academy/ngv/', '0.9', 'weekly'],
        ['diary/', '0.9', 'weekly'],
        ['projects', '0.7', 'monthly'],
        ['events/', '0.7', 'weekly'],
        ['contact/', '0.5', 'yearly'],
        ['donate.html', '0.8', 'monthly'],
    ];

    /**
     * Below this many URLs in the existing file, a shrink is not evidence of
     * anything — a nearly-empty sitemap is what a genuinely new site has.
     */
    private const COLLAPSE_FLOOR = 8;

    /**
     * Rebuild sitemap.xml + robots.txt. Returns true if the sitemap was written.
     *
     * This publishes a real file in the web root, generated from whatever database
     * happens to be connected — so it is trusted only when there is reason to trust
     * it. `$force` bypasses the checks for a deliberate regeneration.
     *
     * @see refuseReason() for what it declines to publish, and why.
     */
    public static function rebuild(bool $force = false): bool
    {
        $xml = self::buildXml();
        if (!$force) {
            $why = self::refuseReason($xml);
            if ($why !== '') { error_log('[sitemap] not rebuilt — ' . $why); return false; }
        }
        $ok = @file_put_contents(AV_ROOT . '/sitemap.xml', $xml, LOCK_EX) !== false;
        @file_put_contents(AV_ROOT . '/robots.txt', self::buildRobots(), LOCK_EX);
        return $ok;
    }

    /**
     * Why this rebuild should not be published — '' when it is fine to write.
     *
     * Two things can make a rebuild destructive, and neither is hypothetical: a
     * test run or a CLI script pointed at a scratch database will happily
     * regenerate the live sitemap from fixture content, and a half-migrated or
     * unreachable database will regenerate it from almost nothing. Both replace a
     * correct file with a wrong one, and the wrong one is what search engines read.
     */
    public static function refuseReason(string $xml): string
    {
        $lock = strtolower(trim((string) (getenv('AV_SITEMAP_LOCK') ?: '')));
        if (in_array($lock, ['1', 'true', 'yes', 'on'], true)) return 'AV_SITEMAP_LOCK is set.';

        // A SQLite database outside this installation is not this site's data.
        // This is the case that matters in practice: the test suite and any CLI
        // run with AV_DB_PATH overridden both land here.
        $db = self::activeSqlitePath();
        if ($db !== '' && !self::insideRoot($db)) {
            return 'the active SQLite database (' . $db . ') is outside the installation, so its content is not this site\'s.';
        }

        // Content-based, and therefore driver-agnostic — it covers MySQL and
        // Postgres too, where there is no path to compare. A sitemap that has
        // collapsed is the signature of reading the wrong database, a migration
        // that half-ran, or a connection that failed into an empty fallback.
        $prevFile = AV_ROOT . '/sitemap.xml';
        if (is_file($prevFile)) {
            $prev = self::countLocs((string) @file_get_contents($prevFile));
            $now  = self::countLocs($xml);
            if ($prev >= self::COLLAPSE_FLOOR && $now < (int) ceil($prev / 2)) {
                return 'it would fall from ' . $prev . ' URLs to ' . $now . ', which reads as a database problem rather than a content change.';
            }
        }
        return '';
    }

    /** The SQLite file currently in use, or '' when the database is not SQLite. */
    private static function activeSqlitePath(): string
    {
        try { if (class_exists('Database') && Database::driver() !== 'sqlite') return ''; }
        catch (Throwable $e) { /* not connected yet — fall through to the path */ }
        return defined('AV_DB_PATH') ? (string) AV_DB_PATH : '';
    }

    /** Is $path inside the installation directory? */
    private static function insideRoot(string $path): bool
    {
        $root = realpath(AV_ROOT);
        // realpath() is null for a database that does not exist yet, so compare the
        // directory instead — the intent is "does it live here", not "is it there".
        $dir = realpath(dirname($path));
        return $root !== false && $dir !== false
            && ($dir === $root || strncmp($dir, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0);
    }

    /** How many <loc> entries a sitemap document carries. */
    private static function countLocs(string $xml): int
    {
        return preg_match_all('~<loc>~', $xml);
    }

    /** Rebuild only if the sitemap is missing or older than $maxAge seconds. */
    public static function ensureFresh(int $maxAge = 3600): void
    {
        $f = AV_ROOT . '/sitemap.xml';
        if (!is_file($f) || (time() - (int) @filemtime($f)) > $maxAge) {
            self::rebuild();
        }
    }

    private static function buildXml(): string
    {
        $S = rtrim(SITE_URL, '/');
        $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rows = [];
        foreach (self::STATIC_PAGES as [$path, $pri, $freq]) {
            $img = $path === 'ethos/' ? "$S/assets/img/ethos-og.webp" : '';
            $rows[] = ['loc' => "$S/$path", 'pri' => $pri, 'freq' => $freq, 'img' => $img];
        }
        try {
            foreach ((new DiaryRepository())->all() as $a) {
                $rows[] = ['loc' => "$S/diary/{$a['slug']}/", 'pri' => $a['featured'] ? '0.8' : '0.7',
                    'freq' => 'monthly', 'lastmod' => $a['published_at'], 'img' => "$S/diary/og/{$a['slug']}.png", 'title' => $a['title']];
            }
        } catch (Throwable $e) {}
        try {
            foreach ((new AcademyRepository())->all() as $c) {
                $rows[] = ['loc' => "$S/academy/{$c['slug']}/", 'pri' => $c['featured'] ? '0.8' : '0.7', 'freq' => 'monthly',
                    'img' => "$S/academy/og/{$c['slug']}.png", 'title' => $c['title']];
            }
        } catch (Throwable $e) {}

        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<!-- Auto-generated by the custom app · authoritative real file (WordPress cannot override). -->' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        foreach ($rows as $r) {
            $out .= "  <url>\n    <loc>" . $esc($r['loc']) . "</loc>\n";
            if (!empty($r['lastmod'])) $out .= "    <lastmod>" . $esc($r['lastmod']) . "</lastmod>\n";
            $out .= "    <changefreq>{$r['freq']}</changefreq>\n    <priority>{$r['pri']}</priority>\n";
            if (!empty($r['img'])) {
                $out .= "    <image:image><image:loc>" . $esc($r['img']) . "</image:loc>";
                if (!empty($r['title'])) $out .= "<image:title>" . $esc($r['title']) . "</image:title>";
                $out .= "</image:image>\n";
            }
            $out .= "  </url>\n";
        }
        return $out . "</urlset>\n";
    }

    private static function buildRobots(): string
    {
        $S = rtrim(SITE_URL, '/');
        return "# Afrovanguard — robots.txt (auto-maintained by the custom app; authoritative real file)\n"
            . "User-agent: *\nAllow: /\n\n"
            . "# Server internals (also blocked at directory level)\n"
            . "Disallow: /lib/\nDisallow: /db/\nDisallow: /admin/\nDisallow: /error.php\n\n"
            . "# Sitemaps — custom first (authoritative), then WordPress core's own.\n"
            . "Sitemap: $S/sitemap.xml\nSitemap: $S/diary/sitemap.xml\nSitemap: $S/academy/sitemap.xml\nSitemap: $S/wp-sitemap.xml\n";
    }
}
