<?php
/**
 * lib/SiteHome.php — the homepage hero as content an admin edits.
 *
 * The hero is a carousel of stories, or ONE video. Admins edit it in the
 * Studio (Content → Site → Homepage hero): slides (image, text, link, order, hidden), the
 * single video (file, poster, text, link), and how long each slide shows.
 *
 * DRAFT, PREVIEW, PUBLISH, HISTORY
 *   · Saving writes a DRAFT. Nothing a visitor sees changes.
 *   · Preview renders the homepage with the draft (/admin/preview.php).
 *   · Publishing renders the hero and writes it into index.html between the
 *     `<!-- cms:hero -->` markers. The homepage stays a static file — fast,
 *     cacheable, and still up if PHP is not — and only that block changes.
 *   · Every publish keeps the previous version, with who and when, and any of
 *     them can be compared and restored as the draft.
 *
 * Stored by lib/Cms.php as the document 'hero' (drafts, versions, review,
 * scheduling); this class is what the hero IS: its defaults, rules and markup.
 * defaults() is the hero as it shipped, so publishing the defaults reproduces
 * index.html exactly (tests/sitehome.test.php holds the two together).
 */
declare(strict_types=1);

final class SiteHome
{
    /** Where the first version kept the hero (app_meta); lib/Cms.php imports it once. */
    public const KEY_DRAFT = 'site_home_draft';
    public const KEY_LIVE = 'site_home';
    public const KEY_HIST = 'site_home_hist';
    public const SLIDES_MAX = 10;
    public const MARK_OPEN = '<!-- cms:hero -->';
    public const MARK_CLOSE = '<!-- /cms:hero -->';

    /** Where the homepage file is. Overridable for tests. */
    public static ?string $indexPath = null;
    public static function indexPath(): string { return self::$indexPath ?? (AV_ROOT . '/index.html'); }

    public static function defaults(): array
    {
        $s = static fn(string $img, string $eb, string $t, string $sub, string $href) =>
            ['image' => $img, 'alt' => $t, 'eyebrow' => $eb, 'title' => $t, 'sub' => $sub, 'href' => $href, 'hidden' => false];
        return [
            'mode' => 'carousel',
            'interval' => 6,
            'slides' => [
                $s('/Images/summer6.jpg', 'Ninth Anniversary · 2017 – 2026', 'Nine years of raising incorruptible leaders', 'From one community in Alimosho to young leaders across Africa — thank you for walking with us.', '/about.html'),
                $s('/assets/ngg/p04.jpeg', '02 — Identity & Power', 'Global power without cultural surrender', 'Compete on the world stage without losing who you are. Relevance without imitation.', '/about.html'),
                $s('/Images/alimosho.jpg', '03 — Community', "Build communities. Don't escape them.", 'Transform broken systems from within — street by street, mind by mind.', '/projects/index.html'),
                $s('/Images/storm2.jpg', '04 — Disruption', 'Redesign the status quo', "When systems fail, we don't adapt — we rebuild. Quietly, strategically, relentlessly.", '/diary/'),
                $s('/Images/career1.png', '05 — Tech & Culture', 'Shape the future of tech and culture', 'Create, not consume. Export ideas, identity and innovation to the world.', '/projects/techhome/'),
                $s('/Images/summer1.png', '06 — Responsibility', 'Take radical responsibility', 'No excuses. No spectators. Leadership starts with you — exactly where you are.', '/academy/'),
            ],
            'video' => [
                'src' => '', 'poster' => '/Images/summer6.jpg',
                'eyebrow' => 'Ninth Anniversary · 2017 – 2026', 'title' => 'Nine years of raising incorruptible leaders',
                'sub' => 'From one community in Alimosho to young leaders across Africa — thank you for walking with us.',
                'href' => '/about.html',
            ],
        ];
    }

    /* ── Reading ──────────────────────────────────────────────────────── */

    /* The store is lib/Cms.php (document 'hero'); these read it the way the editor thinks of it. */
    public static function live(): array { return Cms::liveOf('hero') ?? self::defaults(); }
    public static function draft(): array { return Cms::draft('hero') ?? self::live(); }
    /** Earlier versions, newest first: [{id, at, by, doc}]. */
    public static function history(): array
    {
        return array_map(static fn($v) => $v + ['doc' => Cms::revision('hero', $v['id'])['data'] ?? []], Cms::history('hero'));
    }
    public static function hasUnpublished(): bool { return Cms::draft('hero') !== null; }

    /* ── Validating ───────────────────────────────────────────────────── */

    /** A link or media path we will put in the page: a site path, or https. Never javascript:, data:, etc. */
    public static function url(string $u, bool $media = false): string
    {
        $u = trim($u);
        if ($u === '') return '';
        if (preg_match('#^/(?!/)[^\s"<>\\\\]*$#', $u)) return $u;
        if (!$media && preg_match('#^\#[A-Za-z0-9_-]*$#', $u)) return $u;
        if (preg_match('#^https://[^\s"<>\\\\]+$#i', $u) && filter_var($u, FILTER_VALIDATE_URL)) return $u;
        return '';
    }

    private static function text(mixed $v, int $max): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v)) ?? '');
        return mb_substr($v, 0, $max);
    }

    /**
     * Clean a document as posted by the Studio. Refuses (rather than silently
     * dropping) what would break the page: no visible slide in carousel mode,
     * a video mode with no playable video, an unsafe link.
     *
     * @return array{ok:bool, doc?:array, error?:string}
     */
    public static function clean(array $in): array
    {
        $mode = ($in['mode'] ?? 'carousel') === 'video' ? 'video' : 'carousel';
        $interval = max(3, min(20, (int) ($in['interval'] ?? 6)));
        $slides = [];
        foreach (array_slice(array_values((array) ($in['slides'] ?? [])), 0, self::SLIDES_MAX) as $k => $s) {
            if (!is_array($s)) continue;
            $img = self::url((string) ($s['image'] ?? ''), true);
            $title = self::text($s['title'] ?? '', 120);
            if ($img === '' && $title === '') continue;          // an empty row from the form
            if ($img === '') return ['ok' => false, 'error' => 'Slide ' . ($k + 1) . ' needs an image.'];
            if ($title === '') return ['ok' => false, 'error' => 'Slide ' . ($k + 1) . ' needs a title — it is the page’s main heading while it shows.'];
            $href = trim((string) ($s['href'] ?? ''));
            if ($href !== '' && self::url($href) === '') return ['ok' => false, 'error' => 'Slide ' . ($k + 1) . '’s link must be a site path like /about.html or an https:// address.'];
            $slides[] = [
                'image' => $img,
                'alt' => self::text(($s['alt'] ?? '') !== '' ? $s['alt'] : $title, 200),
                'eyebrow' => self::text($s['eyebrow'] ?? '', 80),
                'title' => $title,
                'sub' => self::text($s['sub'] ?? '', 240),
                'href' => self::url($href) ?: '/',
                'hidden' => !empty($s['hidden']),
            ];
        }
        $v = (array) ($in['video'] ?? []);
        $src = self::url((string) ($v['src'] ?? ''), true);
        if ((string) ($v['src'] ?? '') !== '' && ($src === '' || !preg_match('#\.(mp4|webm|m4v)(\?|$)#i', $src))) {
            return ['ok' => false, 'error' => 'The video must be an MP4 or WebM file — upload one to the Media library. A YouTube link cannot play silently behind the headline.'];
        }
        $vhref = trim((string) ($v['href'] ?? ''));
        if ($vhref !== '' && self::url($vhref) === '') return ['ok' => false, 'error' => 'The video’s link must be a site path or an https:// address.'];
        $video = [
            'src' => $src,
            'poster' => self::url((string) ($v['poster'] ?? ''), true),
            'eyebrow' => self::text($v['eyebrow'] ?? '', 80),
            'title' => self::text($v['title'] ?? '', 120),
            'sub' => self::text($v['sub'] ?? '', 240),
            'href' => self::url($vhref) ?: '/about.html',
        ];
        if ($mode === 'carousel' && !array_filter($slides, static fn($s) => !$s['hidden'])) {
            return ['ok' => false, 'error' => 'The carousel needs at least one slide that is not hidden.'];
        }
        if ($mode === 'video') {
            if ($video['src'] === '') return ['ok' => false, 'error' => 'Choose the video to show, or switch back to the carousel.'];
            if ($video['title'] === '') return ['ok' => false, 'error' => 'Give the video a headline — it is the page’s main heading.'];
            if ($video['poster'] === '') return ['ok' => false, 'error' => 'Choose a poster image: it shows while the video loads, and instead of it for visitors who prefer less motion.'];
        }
        return ['ok' => true, 'doc' => ['mode' => $mode, 'interval' => $interval, 'slides' => $slides, 'video' => $video]];
    }

    /* ── Writing ──────────────────────────────────────────────────────── */

    public static function saveDraft(array $in, string $by = 'studio'): array
    {
        $r = Cms::save('hero', $in, $by);
        return $r['ok'] ? ['ok' => true, 'doc' => $r['data'], 'unpublished' => self::hasUnpublished()] : $r;
    }

    /** Render the draft into index.html. The version it replaces stays in the history. */
    public static function publish(string $by): array { return Cms::publish('hero', $by); }

    /** Make an earlier version the draft ($index 0 = the one before what is live). */
    public static function restore(int $index, string $by = 'studio'): array
    {
        $v = self::history()[$index] ?? null;
        if (!$v) return ['ok' => false, 'error' => 'That version is not in the history.'];
        $r = Cms::restore('hero', $v['id'], $by);
        return $r['ok'] ? ['ok' => true, 'doc' => $r['data'], 'unpublished' => self::hasUnpublished()] : $r;
    }

    public static function discardDraft(string $by = 'studio'): void { Cms::discard('hero', $by); }

    /**
     * Replace the marked block in index.html, atomically: write a temp file in
     * the same folder and rename it over the original, so a visitor never gets
     * half a page. Refuses when the markers are missing rather than guessing.
     */
    public static function writeIndex(string $block): array
    {
        $path = self::indexPath();
        $html = @file_get_contents($path);
        if ($html === false) return ['ok' => false, 'error' => 'Could not read the homepage file.'];
        $merged = self::replaceBlock($html, $block);
        if ($merged === null) return ['ok' => false, 'error' => 'The homepage file has no cms:hero markers, so it was not changed.'];
        if (!is_writable($path) || !is_writable(dirname($path))) {
            return ['ok' => false, 'error' => 'The server cannot write the homepage file (index.html). In cPanel → File Manager, set index.html to 644 and its folder to 755, then publish again.'];
        }
        $tmp = dirname($path) . '/.index.html.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $merged, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Could not write the homepage file.'];
        }
        return ['ok' => true];
    }

    public static function replaceBlock(string $html, string $block): ?string
    {
        $a = strpos($html, self::MARK_OPEN);
        $b = strpos($html, self::MARK_CLOSE);
        if ($a === false || $b === false || $b < $a) return null;
        return substr($html, 0, $a + strlen(self::MARK_OPEN)) . "\n" . $block . "\n" . substr($html, $b);
    }

    /** The current homepage with $doc's hero in place — for the Studio preview. */
    public static function previewHtml(array $doc): ?string
    {
        $c = self::clean($doc);
        $html = @file_get_contents(self::indexPath());
        if ($html === false || !$c['ok']) return null;
        return self::replaceBlock($html, self::heroHtml($c['doc']));
    }

    /* ── Rendering ────────────────────────────────────────────────────── */

    private static function e(string $s): string { return htmlspecialchars($s, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8'); }

    /** The <header class="avh-hero"> block, exactly as avh.js expects it. */
    public static function heroHtml(array $d): string
    {
        $e = [self::class, 'e'];
        $video = $d['mode'] === 'video';
        $v = $d['video'];
        /* Video mode still renders ONE image — the poster — so the page has its
           picture before the script runs (and without it); avh.js swaps it
           for the video. No dots: there is nothing to step through. */
        $slides = $video
            ? [['image' => $v['poster'], 'alt' => $v['title'], 'eyebrow' => $v['eyebrow'], 'title' => $v['title'], 'sub' => $v['sub'], 'href' => $v['href']]]
            : array_values(array_filter($d['slides'], static fn($s) => !$s['hidden']));
        $n = $video ? 0 : count($slides);
        $first = $video
            ? ['eyebrow' => $v['eyebrow'], 'title' => $v['title'], 'sub' => $v['sub'], 'href' => $v['href']]
            : $slides[0];

        $o = '<header class="avh-hero" aria-roledescription="carousel" aria-label="Featured stories" data-avh-hero data-mode="' . ($video ? 'video' : 'carousel') . '" data-video="' . $e($v['src']) . '" data-poster="' . $e($v['poster']) . '"'
           . ((int) $d['interval'] !== 6 ? ' data-interval="' . (int) $d['interval'] . '"' : '') . ($v['href'] !== '/about.html' ? ' data-video-href="' . $e($v['href']) . '"' : '') . "\n"
           . '  data-video-eyebrow="' . $e($v['eyebrow']) . '" data-video-title="' . $e($v['title']) . '" data-video-sub="' . $e($v['sub']) . '">' . "\n"
           . '  <video class="avh-hero-video" muted loop playsinline aria-hidden="true" hidden></video>' . "\n";
        foreach ($slides as $k => $s) {
            $o .= '  <img class="avh-hero-img' . ($k === 0 ? ' is-on" fetchpriority="high" decoding="async"' : '"') . ' src="' . $e($s['image']) . '" alt="' . $e($s['alt']) . '" data-eyebrow="' . $e($s['eyebrow']) . '" data-title="' . $e($s['title']) . '" data-sub="' . $e($s['sub']) . '" data-href="' . $e($s['href']) . '"' . ($k === 0 ? '' : ' loading="lazy"') . ">\n";
        }
        $o .= '  <div class="avh-hero-shade"></div>' . "\n"
            . '  <div class="avh-shapes avh-shapes--hero" data-avh-parallax aria-hidden="true"></div>' . "\n"
            . '  <div class="avh-hero-in avh-pad">' . "\n"
            . '    <a class="avh-hero-link" href="' . $e($first['href']) . '" aria-live="off" data-avh-hero-link>' . "\n"
            . '      <span class="avh-hero-eyebrow" data-avh-hero-eyebrow>' . $e($first['eyebrow']) . '</span>' . "\n"
            . '      <h1 data-avh-hero-title>' . $e($first['title']) . '</h1>' . "\n"
            . '      <span class="avh-hero-sub" data-avh-hero-sub>' . $e($first['sub']) . '</span>' . "\n"
            . '    </a>' . "\n"
            . '    <div class="avh-hero-ctrl">' . "\n"
            . '      <div class="avh-dots" data-avh-dots>';
        for ($k = 0; $k < $n; $k++) {
            $o .= '<button type="button" class="avh-dot" data-avh-dot="' . $k . '" aria-label="Story ' . ($k + 1) . ' of ' . $n . '"' . ($k === 0 ? ' aria-current="true"' : '') . '><span></span></button>';
        }
        $o .= "</div>\n"
            . '      <button type="button" class="avh-next" data-avh-next aria-label="Next story">›</button>' . "\n"
            . '      <button type="button" class="avh-play" data-avh-play aria-label="' . ($video ? 'Pause video' : 'Pause slideshow') . '">❚❚</button>' . "\n"
            . "    </div>\n  </div>\n</header>";
        return $o;
    }
}
