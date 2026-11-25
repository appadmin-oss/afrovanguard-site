<?php
/**
 * lib/SiteSearch.php — one ranked search over everything the public site holds.
 *
 * Used by search.php (the JSON the search palette reads, and the results page)
 * and by Chioma's site_search tool, so both answer from the same code.
 *
 * What it indexes — public content only, exactly what a visitor who is not
 * signed in can already open:
 *   · the key pages (a fixed list, with a few plain-word synonyms);
 *   · Diary entries that are published (never drafts);
 *   · Academy courses that are published;
 *   · the seven projects (lib/projects_content.php);
 *   · events that are published AND open to everyone (not members' or team
 *     events, not drafts);
 *   · the public People directory (names, roles, bios — never contact details);
 *   · the constitution, but only while ConstitutionDoc::PUBLIC says it is public
 *     (today it is members-only, so it is not indexed).
 * Each source has its own try/catch: one unhappy repository must not empty
 * the whole search.
 *
 * Ranking. The query is folded (lower case, accents off), cut to 120
 * characters and at most 8 words; filler words go when there are others. Each
 * word is matched against the title, then the body, then the meta, best match
 * kept: a whole word, the start of a word ("ment" → mentorship, so results
 * come as you type), anywhere in a word, or — for words of five letters or
 * more — one letter off (a typo, a missing or doubled letter, two letters
 * swapped). Title matches weigh most, then a per-type weight; the whole
 * query in a title, or a title that starts with it, adds more. A result must
 * match every word to rank, relaxing to any word only when nothing matches
 * them all. A Diary reference code is an identifier, so a query that IS one
 * wins outright.
 *
 * Each result: type, title, url, excerpt (plain text, one line) and crumb
 * (where it lives, e.g. "Diary · Technology"); a Diary result also has code.
 * Callers escape on output; highlight() does it for HTML.
 */
declare(strict_types=1);

final class SiteSearch
{
    public const MAX_QUERY = 120;
    public const MAX_TERMS = 8;

    /** The result types, in the order the palette groups them, with their plural label. */
    public const TYPES = [
        'Page'         => 'Pages',
        'Academy'      => 'Academy courses',
        'Project'      => 'Projects',
        'Event'        => 'Events',
        'Diary'        => 'Diary',
        'People'       => 'People',
        'Constitution' => 'Constitution',
    ];

    /** The fixed pages: [title, url, blurb, extra words people search with, crumb]. */
    private const PAGES = [
        ['Home', '/', 'The Afrovanguard movement: one million incorruptible leaders by 2040', 'start main front', 'Afrovanguard'],
        ['About us', '/about.html', 'Our story, ethos and the people behind the movement', 'story history who mission vision team', 'About'],
        ['How it works', '/how-it-works', 'How a young person moves from first contact to leading others', 'journey pathway model steps', 'About'],
        ['Our ethos', '/ethos/', 'The values, creed and commitments behind everything we build', 'values creed beliefs principles leadership', 'About'],
        ['Reports', '/reports/', 'Annual reports and accounts of what we did and what it cost', 'annual report accounts transparency finance', 'About'],
        ['Academy', '/academy/', 'Free, hands-on programmes in technology, creativity and leadership', 'learn courses training classes school', 'Academy'],
        ['NextGen Vanguard', '/academy/ngv/', 'Our flagship six-month learn-and-earn programme', 'ngv flagship programme learn earn', 'Academy'],
        ['Verify a certificate', '/academy/verify.php', 'Check that an Afrovanguard Academy certificate is genuine', 'certificate verify check genuine', 'Academy'],
        ['Projects', '/projects/', 'Our seven projects across Lagos and beyond', 'programmes initiatives work', 'Projects'],
        ['Events', '/events/', 'Town halls, the Annual Gala, workshops and meetups — what is coming up', 'calendar gala town hall meetup workshop', 'Events'],
        ['The Diary', '/diary/', 'Field notes, stories and methodology from the movement', 'blog news stories articles journal', 'Diary'],
        ['Community', '/community/', 'Conversations and announcements from members and the team', 'forum discussion members', 'Community'],
        ['Mentorship', '/mentorship/', 'Find a mentor, or become one', 'mentor mentee coaching guidance', 'Get involved'],
        ['Become a mentor', '/mentorship/become-a-mentor/', 'Give an hour a month to a young leader', 'mentor volunteer coach', 'Get involved'],
        ['Donate', '/donate.html', 'Give funds, materials, skills or time', 'give money support pay sponsor gift', 'Get involved'],
        ['Live appeals', '/give/', 'What each appeal is for, how far along it is and what a gift pays for', 'appeal fundraising give donate', 'Get involved'],
        ['Our people', '/people/', 'The team, trustees and volunteers who run Afrovanguard', 'team staff trustees directory', 'About'],
        ['Organogram', '/people/organogram/', 'How Afrovanguard is organised, from the General Assembly to the Directorates', 'org chart organisation organization structure hierarchy governance board council trustees executive', 'About'],
        ['Contact us', '/contact.html', 'Reach the team by email, phone or the contact form', 'email phone address reach help support', 'Contact'],
        ['Member portal', '/portal/', 'Your learning, mentorship and membership dues', 'account dashboard dues membership', 'Members'],
        ['Sign in', '/login', 'Sign in or create your Afrovanguard account', 'login log in register account create', 'Members'],
        ['Accessibility', '/accessibility/', 'Our accessibility statement and the display settings', 'a11y disability screen reader contrast text size', 'About'],
        ['Privacy policy', '/privacy-policy/', 'What we collect, why, and the cookies the site uses', 'privacy data cookies gdpr ndpr', 'Legal'],
        ['Terms of use', '/terms/', 'The terms for using this website and the Academy', 'terms conditions legal', 'Legal'],
    ];

    /** Words that carry no meaning on their own; dropped when the query has others. */
    private const STOP = ['a', 'an', 'and', 'are', 'at', 'be', 'by', 'do', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'me', 'my', 'of', 'on', 'or', 'the', 'to', 'we', 'what', 'where', 'who', 'with', 'you', 'your'];

    /* ── Text ──────────────────────────────────────────────────────────── */

    /** Trim to a single line of at most $n characters. */
    public static function clip(string $s, int $n = 140): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
        return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s;
    }

    /** What the visitor typed, made safe to work with: one line, no control characters, at most 120 characters. */
    public static function normalise(string $q): string
    {
        $q = (string) preg_replace('/[\p{C}]+/u', ' ', $q);   // control and format characters, invalid UTF-8 → space
        if (!mb_check_encoding($q, 'UTF-8')) $q = mb_convert_encoding($q, 'UTF-8', 'UTF-8');
        $q = trim((string) preg_replace('/\s+/u', ' ', $q));
        return mb_strlen($q) > self::MAX_QUERY ? rtrim(mb_substr($q, 0, self::MAX_QUERY)) : $q;
    }

    /** Lower case with accents taken off, so "Ọ̀ṣun" finds "osun" and the other way round. */
    public static function fold(string $s): string
    {
        $s = mb_strtolower($s);
        if (class_exists('Normalizer')) {
            $d = Normalizer::normalize($s, Normalizer::FORM_D);
            if (is_string($d)) $s = (string) preg_replace('/\p{Mn}+/u', '', $d);
        }
        return $s;
    }

    /** The words of a query, folded, de-duplicated, filler dropped when there is anything else. */
    public static function terms(string $q): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', self::fold(self::normalise($q)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_unique(array_filter($words, static fn($w) => mb_strlen($w) >= 2 || ctype_digit($w))));
        $keep = array_values(array_filter($words, static fn($w) => !in_array($w, self::STOP, true)));
        if ($keep) $words = $keep;
        return array_slice($words, 0, self::MAX_TERMS);
    }

    /** The words of a field, folded. */
    private static function words(string $s): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', self::fold($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** One edit apart: a letter changed, added or dropped, or two neighbours swapped. */
    public static function nearly(string $a, string $b): bool
    {
        if ($a === $b) return true;
        $la = strlen($a); $lb = strlen($b);
        if (abs($la - $lb) > 1) return false;
        if (levenshtein($a, $b) <= 1) return true;
        if ($la === $lb) {   // a transposition: "mentro" for "mentor"
            for ($i = 0; $i < $la - 1; $i++) {
                if ($a[$i] !== $b[$i]) return $a[$i] === $b[$i + 1] && $a[$i + 1] === $b[$i] && substr($a, $i + 2) === substr($b, $i + 2);
            }
        }
        return false;
    }

    /**
     * How well one query word matches a field (its folded text and words):
     * 1 whole word, .85 start of a word, .6 inside a word, .5 one letter off.
     */
    private static function level(string $t, string $text, array $words): float
    {
        if ($text === '' || mb_strpos($text, $t) === false) {
            if (mb_strlen($t) < 5) return 0.0;
            foreach ($words as $w) {
                if (self::nearly($t, $w)) return 0.5;
                // a typo in the part typed so far: "mentro" → "mentorship"
                if (mb_strlen($w) > mb_strlen($t) + 1 && self::nearly($t, mb_substr($w, 0, mb_strlen($t)))) return 0.45;
            }
            return 0.0;
        }
        $best = mb_strlen($t) >= 3 ? 0.6 : 0.0;
        foreach ($words as $w) {
            if ($w === $t) return 1.0;
            if (str_starts_with($w, $t)) $best = 0.85;
        }
        return $best;
    }

    /* ── The search ────────────────────────────────────────────────────── */

    /**
     * Ranked results for a query.
     *
     * @param string $q     what the visitor typed
     * @param int    $limit how many to return
     * @param string $only  optional type filter (a key of TYPES)
     * @return array<int,array{type:string,title:string,url:string,excerpt:string,crumb:string,code?:string}>
     */
    public static function query(string $q, int $limit = 12, string $only = ''): array
    {
        $q = self::normalise($q);
        if (mb_strlen($q) < 2) return [];
        $terms = self::terms($q);
        if (!$terms) return [];
        $qf   = implode(' ', self::words($q));
        $only = trim($only);

        $all = [];
        $add = static function (bool $matchedAll, float $score, array $r) use (&$all, $only): void {
            if ($score <= 0) return;
            if ($only !== '' && strcasecmp($r['type'], $only) !== 0) return;
            $all[] = [$matchedAll, $score, $r];
        };

        /* Score a candidate: each word's best match, title heaviest. */
        $scoreOf = static function (string $title, string $body, string $meta, int $typeWeight) use ($terms, $qf): array {
            $tw = self::words($title); $bw = self::words($body); $mw = self::words($meta);
            $tl = implode(' ', $tw); $bl = implode(' ', $bw); $ml = implode(' ', $mw);
            $score = 0.0; $matchedAll = true;
            foreach ($terms as $t) {
                $s = max(12 * self::level($t, $tl, $tw), 4 * self::level($t, $bl, $bw), 3 * self::level($t, $ml, $mw));
                if ($s <= 0) $matchedAll = false;
                $score += $s;
            }
            if ($score <= 0) return [false, 0.0];
            if ($tl === $qf) $score += 30;                                     // the title IS the query
            elseif ($qf !== '' && str_starts_with($tl, $qf)) $score += 18;     // the title starts with it
            elseif ($qf !== '' && mb_strpos($tl, $qf) !== false) $score += 12; // the whole query in the title
            elseif ($qf !== '' && mb_strpos($bl, $qf) !== false) $score += 6;  // …or in the body
            return [$matchedAll, $score + $typeWeight];
        };

        /* 1) Key pages — always there, so the search is never empty-handed. */
        foreach (self::PAGES as [$t, $h, $d, $kw, $crumb]) {
            [$ok, $sc] = $scoreOf($t, $d, $kw, 5);
            $add($ok, $sc, ['type' => 'Page', 'title' => $t, 'url' => $h, 'excerpt' => $d, 'crumb' => $crumb]);
        }

        /* 2) Diary entries (published only), by title, dek, category — and by reference code. */
        try {
            if (class_exists('DiaryRepository')) {
                $wantCode = DiaryRepository::normaliseRefCode($q);
                foreach ((new DiaryRepository())->all() as $a) {
                    $title = (string) ($a['title'] ?? '');
                    $dek   = (string) ($a['dek'] ?? '');
                    $code  = (string) ($a['ref_code'] ?? '');
                    $cat   = (string) ($a['category'] ?? '');
                    [$ok, $sc] = $scoreOf($title, $dek, trim($cat . ' ' . $code), 3);
                    if ($wantCode !== '' && $code === $wantCode) { $ok = true; $sc = 10000; }
                    $add($ok, $sc, ['type' => 'Diary', 'title' => $title,
                        'url' => '/diary/' . rawurlencode((string) ($a['slug'] ?? '')) . '/',
                        'excerpt' => self::clip($dek), 'crumb' => 'Diary' . ($cat !== '' ? ' · ' . $cat : ''), 'code' => $code]);
                }
            }
        } catch (\Throwable $e) { error_log('[sitesearch] diary: ' . $e->getMessage()); }

        /* 3) Academy courses (published only). */
        try {
            if (class_exists('AcademyRepository')) {
                foreach ((new AcademyRepository())->all() as $c) {
                    $title = (string) ($c['title'] ?? '');
                    $blurb = (string) ($c['blurb'] ?? $c['dek'] ?? $c['summary'] ?? '');
                    $cat   = (string) ($c['category'] ?? '');
                    [$ok, $sc] = $scoreOf($title, $blurb, $cat . ' course', 4);
                    $add($ok, $sc, ['type' => 'Academy', 'title' => $title,
                        'url' => '/academy/' . rawurlencode((string) ($c['slug'] ?? '')) . '/', 'excerpt' => self::clip($blurb),
                        'crumb' => 'Academy' . ($cat !== '' ? ' · ' . $cat : '')]);
                }
            }
        } catch (\Throwable $e) { error_log('[sitesearch] academy: ' . $e->getMessage()); }

        /* 4) The seven projects, with the programmes that run under them. */
        try {
            if (!function_exists('avpj_projects') && is_file(__DIR__ . '/avpj.php')) require_once __DIR__ . '/avpj.php';
            if (function_exists('avpj_projects')) {
                foreach (avpj_projects() as $p) {
                    $name = (string) ($p['name'] ?? '');
                    if ($name === '') continue;
                    $progs = implode(' ', array_map(static fn($x) => (string) ($x[0] ?? '') . ' ' . (string) ($x[2] ?? ''), (array) ($p['programmes'] ?? [])));
                    [$ok, $sc] = $scoreOf($name, (string) ($p['d'] ?? '') . ' ' . $progs,
                        (string) ($p['abbr'] ?? '') . ' ' . (string) ($p['cat'] ?? '') . ' ' . implode(' ', (array) ($p['tags'] ?? [])) . ' project', 4);
                    $add($ok, $sc, ['type' => 'Project', 'title' => $name, 'url' => (string) ($p['href'] ?? '/projects/'),
                        'excerpt' => self::clip((string) ($p['d'] ?? '')), 'crumb' => 'Projects · ' . (string) ($p['cat'] ?? '')]);
                }
            }
        } catch (\Throwable $e) { error_log('[sitesearch] projects: ' . $e->getMessage()); }

        /* 5) Events: published and open to everyone. Members' and team events,
              drafts and Africa GATES' feed (another site, fetched over the
              network) are left out. Upcoming ones first. */
        try {
            if (class_exists('SiteEvents') && class_exists('Database')) {
                SiteEvents::ensure();
                $st = Database::pdo()->query("SELECT * FROM av_site_events WHERE status = 'published' AND audience = 'public' ORDER BY starts_at DESC LIMIT 300");
                $today = (new DateTimeImmutable('now', new DateTimeZone(SiteEvents::TZ)))->format('Y-m-d');
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $title = (string) ($row['title'] ?? '');
                    if ($title === '' || (string) ($row['slug'] ?? '') === '') continue;
                    $past = substr((string) (($row['ends_at'] ?? '') ?: $row['starts_at']), 0, 10) < $today;
                    [$ok, $sc] = $scoreOf($title, (string) ($row['summary'] ?? '') . ' ' . strip_tags((string) ($row['body'] ?? '')),
                        (string) ($row['category'] ?? '') . ' ' . (string) ($row['venue'] ?? '') . ' ' . (string) ($row['location'] ?? '') . ' event', $past ? 1 : 4);
                    if ($sc <= 0 || ($only !== '' && strcasecmp($only, 'Event') !== 0)) continue;
                    $full = SiteEvents::find((int) $row['id']);   // only for a match: the shape the site shows
                    if (!$full) continue;
                    $e = SiteEvents::shape($full);
                    $add($ok, $sc, ['type' => 'Event', 'title' => $title, 'url' => (string) $e['url'],
                        'excerpt' => self::clip((string) ($e['excerpt'] ?? '')),
                        'crumb' => ($past ? 'Past event · ' : 'Events · ') . (string) ($e['when'] ?? '')]);
                }
            }
        } catch (\Throwable $e) { error_log('[sitesearch] events: ' . $e->getMessage()); }

        /* 6) The public People directory. Public profiles only — this indexes
              what the site already publishes, never an email or contact detail. */
        try {
            if (function_exists('av_team_public_rows')) {
                /* Listable rows only (av_team_listable): never a synced account
                   nobody asked, nor a system one, though the Studio still has them. */
                foreach (av_team_public_rows(Database::pdo()) as $row) {
                    $m = av_team_member_dict($row);
                    $name = (string) ($m['name'] ?? '');
                    if ($name === '') continue;
                    $role = (string) ($m['role'] ?? '');
                    $bio  = (string) ($m['bio'] ?? '');
                    [$ok, $sc] = $scoreOf($name, $role . ' ' . $bio, 'people team', 3);
                    $url = function_exists('av_person_url') ? av_person_url($m) : ('/people/' . (int) ($m['id'] ?? 0));
                    $add($ok, $sc, ['type' => 'People', 'title' => $name, 'url' => $url,
                        'excerpt' => self::clip($role !== '' ? $role : $bio, 90), 'crumb' => 'Our people']);
                }
            }
        } catch (\Throwable $e) { error_log('[sitesearch] people: ' . $e->getMessage()); }

        /* 7) The constitution, part by part — only while it is public. */
        try {
            if (!class_exists('ConstitutionDoc') && is_file(__DIR__ . '/ConstitutionDoc.php')) require_once __DIR__ . '/ConstitutionDoc.php';
            if (class_exists('ConstitutionDoc') && ConstitutionDoc::PUBLIC) {
                $md = ConstitutionDoc::markdown();
                if ($md !== '') {
                    [, $toc] = ConstitutionDoc::render($md);
                    foreach ((array) $toc as $part) {
                        $pt = (string) ($part['part'] ?? '');
                        foreach ((array) ($part['items'] ?? []) as $it) {
                            [$id, $text] = [(string) ($it[0] ?? ''), (string) ($it[1] ?? '')];
                            if ($id === '' || $text === '') continue;
                            [$ok, $sc] = $scoreOf($text, $pt, 'constitution governance', 2);
                            $add($ok, $sc, ['type' => 'Constitution', 'title' => $text, 'url' => '/constitution/#' . rawurlencode($id),
                                'excerpt' => self::clip($pt), 'crumb' => 'Constitution · ' . $pt]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) { error_log('[sitesearch] constitution: ' . $e->getMessage()); }

        /* Prefer results that matched every word; relax to any word only if none did. */
        $matchedAll = array_filter($all, static fn($x) => $x[0]);
        $pool = $matchedAll ?: $all;
        usort($pool, static fn($a, $b) => $b[1] <=> $a[1] ?: strcmp($a[2]['title'], $b[2]['title']));
        return array_map(static fn($x) => $x[2], array_slice($pool, 0, max(1, $limit)));
    }

    /**
     * "Did you mean …?" for a query that found nothing: each word swapped for
     * the closest title word within two letters. '' when there is nothing better.
     */
    public static function suggest(string $q): string
    {
        $terms = self::terms($q);
        if (!$terms) return '';
        $vocab = [];
        foreach (self::PAGES as $p) foreach (self::words($p[0] . ' ' . $p[3]) as $w) $vocab[$w] = true;
        try {
            if (class_exists('AcademyRepository')) foreach ((new AcademyRepository())->all() as $c) foreach (self::words((string) ($c['title'] ?? '')) as $w) $vocab[$w] = true;
            if (class_exists('DiaryRepository')) foreach ((new DiaryRepository())->all() as $a) foreach (self::words((string) ($a['title'] ?? '')) as $w) $vocab[$w] = true;
            if (function_exists('avpj_projects')) foreach (avpj_projects() as $p) foreach (self::words((string) ($p['name'] ?? '')) as $w) $vocab[$w] = true;
        } catch (\Throwable $e) { /* the pages alone still give a suggestion */ }
        $out = []; $changed = false;
        foreach ($terms as $t) {
            if (isset($vocab[$t]) || mb_strlen($t) < 4) { $out[] = $t; continue; }
            $best = ''; $bd = 3;
            foreach (array_keys($vocab) as $w) {
                $w = (string) $w;
                if (strlen($w) < 3 || abs(strlen($w) - strlen($t)) > 2) continue;
                $d = levenshtein($t, $w);
                if ($d < $bd) { $bd = $d; $best = $w; }
            }
            if ($best !== '' && $bd <= 2) { $out[] = $best; $changed = true; } else $out[] = $t;
        }
        return $changed ? implode(' ', $out) : '';
    }

    /**
     * $text as HTML with the query's words marked: every piece escaped, the
     * starts of words that match wrapped in <mark>. Safe to echo.
     */
    public static function highlight(string $text, string $q): string
    {
        $terms = array_filter(self::terms($q), static fn($t) => mb_strlen($t) >= 2);
        if (!$terms || $text === '') return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        usort($terms, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $rx = '/(?<![\p{L}\p{N}])(' . implode('|', array_map(static fn($t) => preg_quote($t, '/'), $terms)) . ')/iu';
        $parts = preg_split($rx, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $out = '';
        foreach ($parts as $i => $p) {
            $out .= $i % 2 ? '<mark>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</mark>' : htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
        }
        return $out;
    }

    /* ── The way in, on every page (assets/site/avsearch.{css,js}) ─────── */

    /** The search icon, drawn once. */
    public const ICON = '<svg class="av-st-ico" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="10.75" cy="10.75" r="6.25" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m15.5 15.5 4.75 4.75" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';

    /**
     * The header control: a field-like "Search…" with its shortcut on a wide
     * screen, an icon button on a phone ('icon'). A link to the search page,
     * so it works with JavaScript off; avsearch.js makes it the button that
     * opens the palette.
     */
    public static function trigger(string $variant = '', string $label = 'Search…'): string
    {
        $icon = $variant === 'icon';
        return '<a class="av-st' . ($icon ? ' av-st--icon' : '') . '" href="/search.php" data-search-open>'
            . self::ICON
            . '<span class="' . ($icon ? 'av-sr' : 'av-st-t') . '">' . ($icon ? 'Search Afrovanguard' : htmlspecialchars($label, ENT_QUOTES, 'UTF-8')) . '</span>'
            . ($icon ? '' : '<kbd class="av-st-k" aria-hidden="true">Ctrl K</kbd>')
            . '</a>';
    }

    /** The palette's stylesheet and script, for a page's <head> (A11y::head() includes it). */
    public static function head(): string
    {
        return '  <link href="' . htmlspecialchars(av_asset('/assets/site/avsearch.css'), ENT_QUOTES) . '" rel="stylesheet" />' . "\n"
            . '  <script src="' . htmlspecialchars(av_asset('/assets/site/avsearch.js'), ENT_QUOTES) . '" defer></script>' . "\n";
    }
}
