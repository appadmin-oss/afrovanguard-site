<?php
/**
 * lib/ErrorPoem.php — a small, dynamic poem for the error page.
 *
 * In the spirit of Anthropic's status/error pages, every error page carries a
 * short poem "for the moment". When ANTHROPIC_API_KEY is configured, Claude
 * writes fresh verses in the Afrovanguard voice; these are cached and rotated
 * so the error page itself NEVER makes a network call — generation happens in
 * the background AFTER the response is flushed. When the AI isn't configured
 * (or a call fails), a curated, hand-written pool is used, so the feature
 * always works — offline, and even when the app is otherwise broken.
 *
 * Moods mirror lib/errors.php: 'lost' (400/404/413), 'stop' (401/403/405/429),
 * 'examine' (500/503).
 *
 * Config:
 *   ANTHROPIC_API_KEY   enables Claude-written poems (via AvBot)
 *   AV_ERROR_POEMS      set to "0" to disable AI generation (curated only)
 */
declare(strict_types=1);

final class ErrorPoem
{
    const TTL       = 43200;   // 12h — refresh cadence per mood
    const POOL_MAX  = 6;       // keep at most N AI poems per mood
    const LOCK_TTL  = 120;     // seconds a refresh lock is honoured

    /** Curated fallbacks — always available, no network, on-brand. */
    const CURATED = [
        'lost' => [
            ["This page wandered off the path we paved —", "but every road here leads back home.", "Take a breath; the work goes on,", "and so, dear traveller, do you."],
            ["Not every door we knock on opens;", "some rooms were never built at all.", "Turn around — the movement waits", "where a million futures call."],
            ["You reached for a page long gone,", "like starlight from a vanished star.", "Yet Africa keeps rising still —", "come, let us go on from where you are."],
        ],
        'stop' => [
            ["A gate, for now, stands in your way —", "not to refuse, but to invite:", "sign in, step through, and take your place", "among the builders of the light."],
            ["Pause. Some thresholds ask a key,", "some ask only that you wait.", "Patience, too, is discipline —", "the quiet craft of the great."],
            ["Slow down, friend; the road is long,", "and haste can fray the strongest thread.", "Begin again with steady care —", "by patient hands are nations led."],
        ],
        'examine' => [
            ["Something slipped beneath our hands —", "a beam gone crooked in the frame.", "We are already at the mend;", "return, and find it whole again."],
            ["Even the sturdiest of houses", "needs a carpenter some days.", "Give us a moment with the wood;", "the doors will open soon, and stay."],
            ["A fault, a pause, a little dust —", "nothing that we cannot clear.", "Africa was built by those", "who fixed the thing and stayed right here."],
        ],
    ];

    private static function moods(): array { return ['lost', 'stop', 'examine']; }
    private static function normalizeMood(string $m): string
    {
        return in_array($m, self::moods(), true) ? $m : 'examine';
    }

    /** Feature flag — AI generation on unless explicitly disabled. */
    private static function aiEnabled(): bool
    {
        if (!class_exists('AvBot') || !AvBot::configured()) return false;
        $v = class_exists('Config') ? Config::str('AV_ERROR_POEMS', '1') : (getenv('AV_ERROR_POEMS') ?: '1');
        return $v !== '0' && strtolower((string) $v) !== 'false';
    }

    private static function cacheFile(): ?string
    {
        if (!defined('AV_ROOT')) return null;
        $dir = AV_ROOT . '/db/cache';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        return is_dir($dir) && is_writable($dir) ? $dir . '/error-poems.json' : null;
    }

    private static function readCache(): array
    {
        $f = self::cacheFile();
        if (!$f || !is_file($f)) return [];
        $raw = @file_get_contents($f);
        if ($raw === false || $raw === '') return [];
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    private static function writeCache(array $data): void
    {
        $f = self::cacheFile();
        if (!$f) return;
        @file_put_contents($f, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Pick a poem for an error — never throws, never touches the network.
     * Prefers a cached Claude poem for the mood, otherwise a curated one.
     * Returns ['lines'=>string[], 'ai'=>bool].
     */
    public static function pick(int $code, string $mood): array
    {
        $mood = self::normalizeMood($mood);
        try {
            $pool = [];
            $cache = self::readCache();
            $entry = $cache[$mood] ?? null;
            if (is_array($entry) && !empty($entry['poems']) && is_array($entry['poems'])) {
                foreach ($entry['poems'] as $p) {
                    $lines = self::cleanLines(is_array($p) ? $p : preg_split('/\r?\n/', (string) $p));
                    if ($lines) $pool[] = ['lines' => $lines, 'ai' => true];
                }
            }
            foreach ((self::CURATED[$mood] ?? []) as $p) {
                $pool[] = ['lines' => $p, 'ai' => false];
            }
            if (!$pool) return ['lines' => (self::CURATED['examine'][0]), 'ai' => false];
            return $pool[random_int(0, count($pool) - 1)];
        } catch (\Throwable $e) {
            $c = self::CURATED[$mood] ?? self::CURATED['examine'];
            return ['lines' => $c[0], 'ai' => false];
        }
    }

    /** Tidy AI output into 2–4 clean lines. */
    private static function cleanLines($lines): array
    {
        $out = [];
        foreach ((array) $lines as $l) {
            $l = trim((string) $l);
            $l = trim($l, "\"'`—-• \t");           // strip stray bullets/quotes
            $l = preg_replace('/\s+/', ' ', $l);
            if ($l !== '') $out[] = $l;
            if (count($out) >= 4) break;
        }
        return $out;
    }

    /**
     * Best-effort background refresh for one mood. Call AFTER the response is
     * flushed (e.g. once fastcgi_finish_request() has run). Honours a TTL so it
     * regenerates rarely, and a lock so concurrent errors don't stampede the API.
     */
    public static function maybeRefresh(string $mood): void
    {
        try {
            if (!self::aiEnabled()) return;
            $mood = self::normalizeMood($mood);
            $cache = self::readCache();
            $entry = $cache[$mood] ?? null;
            $fresh = is_array($entry) && !empty($entry['poems']) && (time() - (int) ($entry['at'] ?? 0) < self::TTL);
            if ($fresh) return;
            if (!self::lock($mood)) return;
            self::refresh($mood);
        } catch (\Throwable $e) {
            error_log('[errorpoem] refresh skipped: ' . $e->getMessage());
        }
    }

    /** Generate one fresh Claude poem and fold it into the mood's pool. */
    public static function refresh(string $mood): bool
    {
        if (!self::aiEnabled()) return false;
        $mood = self::normalizeMood($mood);
        $situation = [
            'lost'    => 'a visitor has reached a page that cannot be found (a 404 — something missing or moved)',
            'stop'    => 'a visitor is gently stopped at a threshold (needs to sign in, lacks permission, or is going too fast)',
            'examine' => 'the website has hit an unexpected error and the team is repairing it',
        ][$mood];
        $system = "You are a poet writing for Afrovanguard, a Pan-African movement raising one million incorruptible African leaders. "
            . "Write ONE short poem for a website error page. Rules: 3 or 4 lines only; no title; no quotation marks; no preamble or explanation; "
            . "warm, dignified, hopeful, lightly witty; rooted in an African sense of resilience and community. Return ONLY the poem lines, one per line.";
        $user = "The moment: {$situation}. Write the poem.";
        $res = AvBot::reply($user, [], ['system' => $system, 'max_tokens' => 160]);
        if (empty($res['ok']) || empty($res['text'])) return false;
        $lines = self::cleanLines(preg_split('/\r?\n/', (string) $res['text']));
        if (count($lines) < 2) return false;

        $cache = self::readCache();
        $pool = (isset($cache[$mood]['poems']) && is_array($cache[$mood]['poems'])) ? $cache[$mood]['poems'] : [];
        array_unshift($pool, $lines);
        $pool = array_slice($pool, 0, self::POOL_MAX);
        $cache[$mood] = ['at' => time(), 'poems' => $pool];
        self::writeCache($cache);
        return true;
    }

    /** Warm every mood (for a cron / CLI). */
    public static function warm(): array
    {
        $out = [];
        foreach (self::moods() as $m) { $out[$m] = self::refresh($m); }
        return $out;
    }

    /** Simple filesystem lock so only one request refreshes a mood at a time. */
    private static function lock(string $mood): bool
    {
        $dir = defined('AV_ROOT') ? AV_ROOT . '/db/cache' : sys_get_temp_dir();
        $lf = $dir . '/error-poem-' . preg_replace('/[^a-z]/', '', $mood) . '.lock';
        if (is_file($lf) && (time() - (int) @filemtime($lf) < self::LOCK_TTL)) return false;
        return @file_put_contents($lf, (string) time(), LOCK_EX) !== false;
    }
}
