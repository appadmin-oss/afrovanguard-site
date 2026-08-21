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

    /**
     * Curated fallbacks — always available, no network, and grounded in the
     * Afrovanguard movement: the mission (one million incorruptible leaders by
     * 2040), its home (Alimosho, Lagos), its work (the Academy, the Diary,
     * mentorship, Street-To-Stardom) and its values (integrity, service,
     * building). Grouped by mood; pick() also mixes in any code-specific verse.
     */
    const CURATED = [
        'lost' => [
            ["This page slipped out of the Diary —", "a story we have not yet told.", "But the work goes on in Alimosho:", "a million futures still unfold."],
            ["We looked in every room we've built,", "from the Academy to the square.", "This one isn't here, dear friend —", "but the movement is; we'll meet you there."],
            ["Not every path leads where we planned;", "some doors were never ours to find.", "Turn toward the work that lasts —", "the raising of incorruptible minds."],
            ["Lagos is wide and pages stray;", "this one took a road unknown.", "Come back to where the builders are —", "you never search these halls alone."],
            ["The page is gone, the mission isn't:", "one million leaders, Africa's dawn.", "Step back onto the road with us —", "in Alimosho, the light goes on."],
        ],
        'stop' => [
            ["A gate, for now — not to refuse,", "but to learn the name you bear.", "Sign in, and take your rightful place", "among the leaders forming there."],
            ["Some rooms are kept for members yet;", "integrity unlocks the door.", "Join the movement, do the work,", "and these walls will hold you more."],
            ["Patience, too, is discipline,", "the quiet craft the great ones learn.", "Wait a moment, then return —", "your place is here; it's yours to earn."],
            ["This threshold asks a little proof:", "that you belong, that you'll be true.", "Afrovanguard keeps a seat", "at the table, saved for you."],
        ],
        'examine' => [
            ["A beam slipped in the house we're building;", "already we are at the mend.", "Even Alimosho's finest walls", "needed a steady hand to tend."],
            ["Something broke, but nothing's lost —", "the mission holds, the vision stays.", "Give the builders one more moment;", "the doors reopen soon, to stay."],
            ["A fault, a pause, a little dust:", "nothing this movement cannot clear.", "We were built by those who stayed", "and fixed the thing, and kept it here."],
            ["Incorruptible means we don't walk off —", "we mend, we steady, and we stay.", "One breath here while we set it right;", "Africa's dawn is on its way."],
        ],
    ];

    /**
     * Smart, code-specific verses — mixed into the candidate pool when that
     * exact status is hit, so a rate-limit reads differently from a locked
     * page or an oversized upload. Falls back to the mood pool above.
     */
    const CODE_EXTRA = [
        401 => [["A name unknown still waits outside;", "sign in, and the doors will learn your face.", "The work of leaders asks a key —", "step through, and take your place."]],
        403 => [["This room is held for those who've earned it —", "membership, and a steady hand.", "Keep faith with the work, dear friend;", "soon here is where you'll stand."]],
        413 => [["That upload outgrew the doorway —", "trim it lighter, try once more.", "Even great things travel best", "when they still fit through the door."]],
        429 => [["Easy, friend — the road is long,", "and Africa was not built in haste.", "Breathe once; begin again with care.", "No honest effort goes to waste."]],
        503 => [["We're tightening a bolt or two", "so the work will hold for years.", "Back shortly, stronger than before —", "Africa's dawn is drawing near."]],
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
            // Smart layer: a verse written for THIS exact status (rate-limit,
            // locked room, oversized upload…) — weighted so it shows often.
            foreach ((self::CODE_EXTRA[$code] ?? []) as $p) {
                $pool[] = ['lines' => $p, 'ai' => false];
                $pool[] = ['lines' => $p, 'ai' => false]; // double weight
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
            'lost'    => 'a visitor reached a page that is missing or moved (a 404)',
            'stop'    => 'a visitor is gently stopped at a threshold — they need to sign in, lack permission, or are going too fast',
            'examine' => 'the site hit an unexpected error and the team is already repairing it',
        ][$mood];
        // Ground the poem in the actual movement. The live "site brief" (same one
        // that feeds the @Afrovanguard bot) is added when available so verses can
        // nod to real programmes without inventing anything.
        $brief = class_exists('AiKnowledge') ? trim((string) AiKnowledge::asPromptBlock()) : '';
        $system = "You are a poet for Afrovanguard — a Nigerian-rooted, Pan-African movement based in Alimosho, Lagos, on a mission to raise one million incorruptible African leaders by 2040 through community, technology and cultural advancement. "
            . "Its work includes the Academy (free programmes — Techome, MediaPro, Africa GATES, Next Generation Genius), the LCASP children's programme, Street-To-Stardom, mentorship, and a public Diary of the work; its values are integrity, servant leadership, service and initiative.\n\n"
            . "Write ONE short poem for a website error page. Rules: exactly 3 or 4 lines; no title; no quotation marks; no preamble or explanation. "
            . "Warm, dignified, hopeful, lightly witty; rooted in African resilience and community. You MAY nod to the movement (the mission, Alimosho/Lagos, the Academy or the Diary, building, incorruptible leadership) but keep it natural and never invent specific facts, names, dates, figures or links. Return ONLY the poem lines, one per line."
            . ($brief !== '' ? "\n\nReference (do not quote verbatim, do not invent beyond it):\n" . mb_substr($brief, 0, 2000) : '');
        $user = "The moment: {$situation}. Write the Afrovanguard poem.";
        // Bulk: three lines on an error page, warmed into a cache ahead of time.
        // Spending a frontier model on this was never defensible.
        $res = class_exists('AvAgent')
            ? AvAgent::complete($system, $user, ['job' => 'bulk', 'max_tokens' => 160, 'actor' => 'errorpoem'])
            : ['ok' => false];
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
