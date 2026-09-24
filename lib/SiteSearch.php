<?php
/**
 * lib/SiteSearch.php — one ranked search over everything the public site holds.
 *
 * This logic used to live inside search.php, which meant it could only ever be
 * reached over HTTP by the search modal. Chioma needs the same index — when a
 * visitor asks "do you teach video editing?" the honest answer comes from the
 * live Academy rows, not from whatever the model remembers — so the ranking
 * moved here and search.php became a thin wrapper over it.
 *
 * What it indexes: the key pages, the Diary, the Academy and the public People
 * directory. Each source is wrapped in its own try/catch, because a search box
 * that returns nothing because one repository is unhappy is worse than one that
 * returns the other three.
 *
 * Ranking: the query is tokenised; a result must match ALL terms to rank,
 * falling back to ANY-term matches only when nothing matches everything. Title
 * hits score heaviest, then a per-type weight, so the most relevant and most
 * authoritative results come first. A Diary reference code is an exact
 * identifier rather than a keyword, so a query that IS a code wins outright.
 */
declare(strict_types=1);

final class SiteSearch
{
    /** The fixed pages, as [title, url, blurb]. */
    private const PAGES = [
        ['Home', '/', 'The Afrovanguard movement'],
        ['About', '/about.html', 'Our story, ethos and people'],
        ['Academy', '/academy/', 'Free, hands-on programmes'],
        ['Projects', '/projects/', 'Our programmes across Lagos and beyond'],
        ['The Diary', '/diary/', 'Field notes and methodology'],
        ['Donate', '/donate.html', 'Give funds, materials, skills or time'],
        ['Events', 'https://afg.afrovanguard.org.ng/events', 'Latest and upcoming events'],
        ['Contact', '/contact.html', 'Reach the team'],
        ['Member portal', '/portal/', 'Your learning, mentorship and membership dues'],
    ];

    /** Trim to a single line of at most $n characters. */
    public static function clip(string $s, int $n = 140): string
    {
        $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)) ?? '');
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
    }

    /**
     * Ranked results for a query.
     *
     * @param string $q     what the visitor typed
     * @param int    $limit how many to return
     * @param string $only  optional type filter: Page|Diary|Academy|People
     * @return array<int,array{type:string,title:string,url:string,excerpt:string,code?:string}>
     */
    public static function query(string $q, int $limit = 12, string $only = ''): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];

        $terms = array_values(array_filter(
            preg_split('/[\s,]+/', mb_strtolower($q)) ?: [],
            static fn($t) => mb_strlen($t) >= 2
        ));
        if (!$terms) $terms = [mb_strtolower($q)];
        $qlc  = mb_strtolower($q);
        $only = trim($only);

        $all = [];
        $add = static function (bool $matchedAll, int $score, array $r) use (&$all, $only): void {
            if ($score <= 0) return;
            if ($only !== '' && strcasecmp($r['type'], $only) !== 0) return;
            $all[] = [$matchedAll, $score, $r];
        };

        /* Score a candidate: title heaviest, then body, then meta. */
        $scoreOf = static function (string $title, string $body, string $meta, int $typeWeight) use ($terms, $qlc): array {
            $tl = mb_strtolower($title); $bl = mb_strtolower($body); $ml = mb_strtolower($meta);
            $score = 0; $matchedAll = true; $anyHit = false;
            foreach ($terms as $t) {
                if (mb_strpos($tl, $t) !== false)      { $score += 12; $anyHit = true; }
                elseif (mb_strpos($bl, $t) !== false)  { $score += 4;  $anyHit = true; }
                elseif (mb_strpos($ml, $t) !== false)  { $score += 3;  $anyHit = true; }
                else $matchedAll = false;
            }
            if ($tl !== '' && mb_strpos($tl, $qlc) !== false) $score += 25;      // whole query in title
            elseif ($bl !== '' && mb_strpos($bl, $qlc) !== false) $score += 8;   // whole query in body
            if ($score > 0) $score += $typeWeight;
            return [$matchedAll, $anyHit ? $score : 0];
        };

        /* 1) Key pages — always available, so the search is never empty-handed. */
        foreach (self::PAGES as [$t, $h, $d]) {
            [$ok, $sc] = $scoreOf($t, $d, '', 5);
            $add($ok, $sc, ['type' => 'Page', 'title' => $t, 'url' => $h, 'excerpt' => $d]);
        }

        /* 2) Diary entries, by title, dek, category — and by reference code. */
        try {
            $wantCode = DiaryRepository::normaliseRefCode($q);
            foreach ((new DiaryRepository())->all() as $a) {
                $title = (string) ($a['title'] ?? '');
                $dek   = (string) ($a['dek'] ?? '');
                $code  = (string) ($a['ref_code'] ?? '');
                [$ok, $sc] = $scoreOf($title, $dek, trim((string) ($a['category'] ?? '') . ' ' . $code), 3);
                if ($wantCode !== '' && $code === $wantCode) { $ok = true; $sc = 10000; }
                $add($ok, $sc, ['type' => 'Diary', 'title' => $title,
                    'url' => '/diary/' . (string) ($a['slug'] ?? '') . '/',
                    'excerpt' => self::clip($dek), 'code' => $code]);
            }
        } catch (\Throwable $e) { error_log('[sitesearch] diary: ' . $e->getMessage()); }

        /* 3) Academy courses. */
        try {
            foreach ((new AcademyRepository())->all() as $c) {
                $title = (string) ($c['title'] ?? '');
                $blurb = (string) ($c['blurb'] ?? $c['dek'] ?? $c['summary'] ?? '');
                [$ok, $sc] = $scoreOf($title, $blurb, (string) ($c['category'] ?? ''), 4);
                $add($ok, $sc, ['type' => 'Academy', 'title' => $title,
                    'url' => '/academy/' . (string) ($c['slug'] ?? '') . '/', 'excerpt' => self::clip($blurb)]);
            }
        } catch (\Throwable $e) { error_log('[sitesearch] academy: ' . $e->getMessage()); }

        /* 4) The public People directory. Public profiles only — this indexes
              what the site already publishes, never an email or contact detail. */
        try {
            if (function_exists('av_team_rows') && function_exists('av_team_member_dict')) {
                foreach (av_team_rows(Database::pdo(), true) as $row) {
                    $m = av_team_member_dict($row);
                    $name = (string) ($m['name'] ?? '');
                    if ($name === '') continue;
                    $role = (string) ($m['role'] ?? '');
                    $bio  = (string) ($m['bio'] ?? '');
                    [$ok, $sc] = $scoreOf($name, $role . ' ' . $bio, 'people team', 3);
                    $url = function_exists('av_person_url') ? av_person_url($m) : ('/people/' . (int) ($m['id'] ?? 0));
                    $add($ok, $sc, ['type' => 'People', 'title' => $name, 'url' => $url,
                        'excerpt' => self::clip($role !== '' ? $role : $bio, 90)]);
                }
            }
        } catch (\Throwable $e) { error_log('[sitesearch] people: ' . $e->getMessage()); }

        /* Prefer results that matched every term; relax to any-term only if none did. */
        $matchedAll = array_filter($all, static fn($x) => $x[0]);
        $pool = $matchedAll ?: $all;
        usort($pool, static fn($a, $b) => $b[1] <=> $a[1]);
        return array_map(static fn($x) => $x[2], array_slice($pool, 0, max(1, $limit)));
    }
}
