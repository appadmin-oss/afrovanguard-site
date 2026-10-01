<?php
/**
 * lib/CacTasks.php — a member's CACENTRE tasks, read from here.
 *
 * The mirror of CACENTRE's CrmAvTasks, and deliberately its mirror. The
 * console reads this site's tasks; without this the portal showed half a
 * member's day, and "what have I got today" answered differently depending on
 * which site you asked. Two halves of one answer is worse than one half,
 * because it teaches people to check both.
 *
 * ── READ, NOT COPIED ────────────────────────────────────────────────────────
 * CACENTRE stays the one place a CACENTRE task is true, exactly as this site
 * stays the one place a portal task is. Neither copies the other, so there is
 * never a pair of rows to reconcile and never a question about which of them
 * is right. The portal links across to work one.
 *
 * ── IT FAILS SOFT ───────────────────────────────────────────────────────────
 * The portal dashboard is the first thing a member sees. It must not be
 * blank, slow or broken because the other site is deploying or the shared
 * secret has not been set here. Every failure is an empty list and a reason;
 * none throws.
 */
declare(strict_types=1);

final class CacTasks
{
    /** Where CACENTRE answers. */
    public const PATH = '/api/afrovanguard-tasks.php';

    /** Long enough for a slow deploy, short enough not to be a hang. */
    private const TIMEOUT = 3;

    /** One fetch per request, however many times a page asks. */
    private static array $memo = [];

    /**
     * The other site.
     *
     * Asked of CacSso rather than resolved again here. This used to read
     * CAC_SITE_URL itself and fall back to parsing the bridge's hardcoded
     * landing URL — so the two agreed by default and diverged the moment
     * anybody set the variable, pointing the task list at staging while
     * sign-on still went to production.
     */
    public static function site(): string
    {
        return CacSso::base();
    }

    /** Where a member goes to actually work one of these. */
    public static function consoleUrl(): string
    {
        return self::site() . '/crm/tasks.php';
    }

    /**
     * The tasks CACENTRE holds for this member.
     *
     * @return array{ok:bool, tasks:array<int,array<string,mixed>>, error:string, url:string}
     */
    public static function forMember(int $memberId): array
    {
        if ($memberId <= 0) {
            return ['ok' => false, 'tasks' => [], 'error' => '', 'url' => self::consoleUrl()];
        }
        if (isset(self::$memo[$memberId])) return self::$memo[$memberId];

        if (!CacSso::ready()) {
            return self::$memo[$memberId] =
                ['ok' => false, 'tasks' => [], 'error' => 'not-configured', 'url' => self::consoleUrl()];
        }

        $url  = self::site() . self::PATH . '?t=' . rawurlencode(CacSso::mint(['id' => $memberId]));
        $body = self::get($url);

        if ($body === null) {
            return self::$memo[$memberId] =
                ['ok' => false, 'tasks' => [], 'error' => 'unreachable', 'url' => self::consoleUrl()];
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['ok'])) {
            $why = is_array($data) ? (string) ($data['error'] ?? 'refused') : 'unreadable';
            return self::$memo[$memberId] =
                ['ok' => false, 'tasks' => [], 'error' => $why, 'url' => self::consoleUrl()];
        }

        return self::$memo[$memberId] = [
            'ok'    => true,
            'tasks' => self::clean($data['tasks'] ?? []),
            'error' => '',
            'url'   => self::consoleUrl(),
        ];
    }

    /**
     * Change one of this member's CACENTRE tasks, over there.
     *
     * The mirror of CrmAvTasks::write(). The member id travels inside the
     * signed token, and CACENTRE checks the task's owner against its own row
     * before touching it — a remote write is not a licence to name any id.
     *
     * @param array<string,mixed> $fields
     * @return array{ok:bool, error:string, data:array}
     */
    public static function write(int $memberId, string $action, int $taskId, array $fields = []): array
    {
        $bad = static fn(string $why): array => ['ok' => false, 'error' => $why, 'data' => []];

        if ($memberId <= 0 || $taskId <= 0)                 return $bad('no-task');
        if (!in_array($action, ['toggle', 'update'], true)) return $bad('unknown-action');
        if (!CacSso::ready())                               return $bad('not-configured');

        $url = self::site() . self::PATH . '?t=' . rawurlencode(CacSso::mint(['id' => $memberId]));
        $raw = self::send($url, array_merge(['action' => $action, 'id' => $taskId], $fields));
        if ($raw === null) return $bad('unreachable');

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['ok'])) {
            return $bad(is_array($data) ? (string) ($data['error'] ?? 'refused') : 'unreadable');
        }

        /* The cached read is stale the moment a write lands. */
        unset(self::$memo[$memberId]);
        return ['ok' => true, 'error' => '', 'data' => $data];
    }

    /** One JSON POST, every failure turned into null. TLS verification stays on. */
    private static function send(string $url, array $body): ?string
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Afrovanguard/1.0 (+tasks)',
        ]);
        $out = curl_exec($ch);
        curl_close($ch);
        /* The body comes back on a refusal too: the far side says which
           refusal it was, and the screen needs to pass that on. */
        return $out === false ? null : (string) $out;
    }

    /** Just the open ones, which is what a dashboard is for. */
    public static function openFor(int $memberId): array
    {
        $r = self::forMember($memberId);
        return array_values(array_filter(
            $r['tasks'],
            static fn(array $t): bool => !in_array($t['status'], ['done', 'dropped'], true)
        ));
    }

    /**
     * Shape another system's rows into the few fields this site draws.
     *
     * Every field arrives bounded, because it is another system's data and a
     * page renders it. A missing one becomes empty rather than a warning
     * halfway down somebody's dashboard.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function clean(mixed $rows): array
    {
        if (!is_array($rows)) return [];
        $out = [];
        foreach (array_slice($rows, 0, 50) as $r) {
            if (!is_array($r)) continue;
            $title = trim((string) ($r['title'] ?? ''));
            if ($title === '') continue;
            $out[] = [
                'id'       => (int) ($r['id'] ?? 0),
                'title'    => mb_substr($title, 0, 300),
                'due'      => mb_substr(trim((string) ($r['due'] ?? '')), 0, 40),
                'priority' => mb_substr(trim((string) ($r['priority'] ?? 'normal')), 0, 10),
                'status'   => mb_substr(trim((string) ($r['status'] ?? 'todo')), 0, 12),
            ];
        }
        return $out;
    }

    /**
     * One GET, with every failure mode turned into null.
     *
     * TLS verification stays on. A task list is not worth teaching this
     * codebase that a certificate is optional.
     */
    private static function get(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'Afrovanguard/1.0 (+tasks)',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ($body === false || $code !== 200) ? null : (string) $body;
        }

        $ctx = stream_context_create(['http' => [
            'timeout'       => self::TIMEOUT,
            'ignore_errors' => true,
            'header'        => "User-Agent: Afrovanguard/1.0 (+tasks)\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : (string) $body;
    }
}
