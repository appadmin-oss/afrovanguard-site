<?php
/**
 * lib/AiKnowledge.php — the live "brief" the AI assistants are fed.
 *
 * Chioma (the site guide) and AvBot (the community bot) are told NEVER to invent
 * facts. This class gives them REAL, CURRENT facts to work from: programmes,
 * recent Diary articles, membership dues, upcoming events and today's
 * celebration — compiled from the live database into a compact prompt block.
 *
 * "Fed on every update": the block is cached and rebuilt whenever site content
 * changes. Every domain event the app already emits (diary.published,
 * enrollment.created, member.created, community.post, donation.completed, …)
 * bumps a version stamp that invalidates the cache; a 6-hour TTL is a safety
 * net for edits that don't emit an event. Building is fully guarded so a data
 * hiccup can never break an AI reply or the request.
 */
declare(strict_types=1);

final class AiKnowledge
{
    private const META_KEY = 'ai_knowledge';      // cached { ver, ts, text }
    private const META_VER = 'ai_knowledge_ver';  // bumped on any content change
    private const TTL      = 21600;               // 6h safety rebuild
    private static ?string $memo = null;          // per-request memo

    /** Register listeners so any content update invalidates the cached brief. */
    public static function boot(): void
    {
        if (!class_exists('Events')) return;
        $names = array_keys(Events::catalog());            // all emitted domain events
        $names = array_merge($names, ['celebration.updated', 'academy.updated', 'people.updated']);
        foreach (array_unique($names) as $e) {
            Events::on($e, static function () { self::invalidate(); });
        }
    }

    /** Mark the brief stale so the next AI call rebuilds it. Safe to call often.
     *  Uses a strictly-increasing counter so even two updates in the same second
     *  reliably bust the cache. */
    public static function invalidate(): void
    {
        self::$memo = null;
        try {
            if (class_exists('Database')) {
                $cur = (int) (Database::metaGet(self::META_VER) ?? '0');
                Database::metaSet(self::META_VER, (string) ($cur + 1));
            }
        } catch (\Throwable $e) { /* best-effort */ }
    }

    /** Compact, bounded text block to append to an AI system prompt ('' on failure). */
    public static function asPromptBlock(): string
    {
        if (self::$memo !== null) return self::$memo;
        self::$memo = self::load();
        return self::$memo;
    }

    private static function load(): string
    {
        try {
            if (!class_exists('Database')) return '';
            $ver = (string) (Database::metaGet(self::META_VER) ?? '0');
            $raw = Database::metaGet(self::META_KEY);
            if ($raw) {
                $c = json_decode($raw, true);
                if (is_array($c) && (string) ($c['ver'] ?? '') === $ver
                    && (time() - (int) ($c['ts'] ?? 0)) < self::TTL) {
                    return (string) ($c['text'] ?? '');
                }
            }
            $text = self::build();
            Database::metaSet(self::META_KEY, json_encode(['ver' => $ver, 'ts' => time(), 'text' => $text]));
            return $text;
        } catch (\Throwable $e) {
            error_log('[ai-knowledge] load: ' . $e->getMessage());
            return '';
        }
    }

    /** Compile the current site facts. Each source is independently guarded. */
    public static function build(): string
    {
        $lines = [];

        // Membership dues (the annual fee).
        try {
            $ngn = defined('AV_MEMBERSHIP_NGN') ? (int) AV_MEMBERSHIP_NGN : 0;
            if ($ngn > 0) $lines[] = 'Academy membership / annual dues: ₦' . number_format($ngn) . ' per year (pay or renew in the member Portal).';
        } catch (\Throwable $e) {}

        // How Afrovanguard works — the membership progression framework.
        //
        // Derived from Levels (the live ladder) and AvRules (leadership's own
        // thresholds) rather than restated here. This paragraph used to hardcode
        // "two committed members" and the ladder's shape, which meant every
        // change to the rules silently left the assistants describing the old
        // policy to members.
        try {
            $ladder = class_exists('Levels') ? Levels::order() : ['O', 'A', 'B', 'C'];
            $needA  = class_exists('AvRules') ? AvRules::int('levels.active_mentees_for_a') : 2;
            $multi  = class_exists('AvRules') ? AvRules::bool('levels.require_multiplication') : false;
            $prog = 'Membership progression (see /how-it-works): members grow by commitment, service and leadership — not length of membership. '
                . 'The ladder is ' . implode(' → ', $ladder) . '. '
                . 'Level ' . ($ladder[0] ?? 'O') . ': pick a mentor, join programmes, complete a weekly task, live the values. '
                . 'Level ' . ($ladder[1] ?? 'A') . ': actively mentor ' . $needA . ' member(s) — meeting them, not merely listing them — plus consistent service; '
                . 'you then get an official Afrovanguard email, an accountability mentor and leadership opportunities.';
            if ($multi && count($ladder) > 2) {
                $prog .= ' Levels above ' . ($ladder[1] ?? 'A') . ' require multiplication: your mentees must themselves be mentoring.';
            }
            $lines[] = $prog;
        } catch (\Throwable $e) { error_log('[ai-knowledge] progression: ' . $e->getMessage()); }

        // Dues (kept separate from progression so one failing never hides the other).
        try {
            $monthly = defined('AV_DUES_MONTHLY_NGN') ? (int) AV_DUES_MONTHLY_NGN : 1000;
            $annual  = defined('AV_DUES_ANNUAL_NGN') ? (int) AV_DUES_ANNUAL_NGN : 12000;
            $lines[] = 'Membership contribution from Level A is voluntary at ₦' . number_format($monthly) . '/month or ₦'
                . number_format($annual) . '/year (pay or renew in the member Portal); from Level C upward, dues are mandatory. '
                . 'Servant-leadership culture: arrive early (30 min, or 2–3 hours for major events) and serve before attending.';
        } catch (\Throwable $e) {}

        // Academy programmes (title · access · path).
        try {
            if (class_exists('AcademyRepository')) {
                $rows = (new AcademyRepository())->all();
                $items = [];
                foreach ($rows as $c) {
                    $title = trim((string) ($c['title'] ?? ''));
                    if ($title === '') continue;
                    $slug = (string) ($c['slug'] ?? '');
                    $access = (string) ($c['access_type'] ?? 'open');
                    $tag = ['open' => 'free', 'tracked' => 'free · account', 'membership' => 'members', 'paid' => 'paid'][$access] ?? $access;
                    $items[] = '“' . $title . '” (' . $tag . ') /academy/' . $slug . '/';
                    if (count($items) >= 30) break;
                }
                if ($items) $lines[] = 'Academy programmes currently listed: ' . implode('; ', $items) . '.';
            }
        } catch (\Throwable $e) { error_log('[ai-knowledge] academy: ' . $e->getMessage()); }

        // Recent Diary articles (title · path).
        try {
            if (class_exists('DiaryRepository')) {
                $arts = (new DiaryRepository())->all();
                $items = [];
                foreach ($arts as $a) {
                    $title = trim((string) ($a['title'] ?? ''));
                    if ($title === '') continue;
                    $items[] = '“' . $title . '” /diary/' . (string) ($a['slug'] ?? '') . '/';
                    if (count($items) >= 10) break;
                }
                if ($items) $lines[] = 'Recent Diary articles: ' . implode('; ', $items) . '.';
            }
        } catch (\Throwable $e) { error_log('[ai-knowledge] diary: ' . $e->getMessage()); }

        // Upcoming events.
        try {
            if (class_exists('AvEvents')) {
                $evs = AvEvents::latest(3);
                $items = [];
                foreach ($evs as $ev) {
                    $t = trim((string) ($ev['title'] ?? ''));
                    if ($t === '') continue;
                    $when = trim((string) ($ev['when'] ?? $ev['date'] ?? ''));
                    $items[] = $t . ($when !== '' ? ' (' . $when . ')' : '');
                }
                if ($items) $lines[] = 'Upcoming events: ' . implode('; ', $items) . '. See https://afg.afrovanguard.org.ng/events.';
            }
        } catch (\Throwable $e) { error_log('[ai-knowledge] events: ' . $e->getMessage()); }

        // Today's celebration (so the assistants know when it's someone's day).
        try {
            if (function_exists('av_celebration_today')) {
                $c = av_celebration_today(Database::pdo());
                if ($c && !empty($c['primary']['title'])) {
                    $lines[] = 'Today the site is celebrating: ' . (string) $c['primary']['title'] . '.';
                }
            }
        } catch (\Throwable $e) {}

        $block = '';
        if ($lines) {
            $block = "\n\nCURRENT SITE KNOWLEDGE (auto-updated " . gmdate('Y-m-d') . " — these are real, current facts; use them and never contradict them):\n- "
                . implode("\n- ", $lines);
            // Hard size bound so the system prompt never balloons.
            if (mb_strlen($block) > 3200) $block = mb_substr($block, 0, 3200) . '…';
        }

        // Leadership's own written knowledge, appended under its own heading and
        // its own budget (AvKnowledge bounds itself), so a long knowledge base
        // can never squeeze out the derived facts above or vice versa.
        try {
            if (class_exists('AvKnowledge')) $block .= AvKnowledge::asPromptBlock('assistant');
        } catch (\Throwable $e) { error_log('[ai-knowledge] kb: ' . $e->getMessage()); }

        return $block;
    }
}
