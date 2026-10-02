<?php
/**
 * lib/CacLeads.php — a member's CACENTRE follow-ups, read and logged from here.
 *
 * ── WHY FOLLOW-UPS AND NOT THE LEAD REGISTER ────────────────────────────────
 * The register is ninety people's contact details, and a member does not need
 * it to answer the question they actually have, which is "who am I meant to
 * be getting back to". This carries that and nothing else: leads they own,
 * still open, overdue first. Their own work, not the centre's book.
 *
 * ── AND IT WRITES, BECAUSE READING ALONE IS THE GAP ─────────────────────────
 * CACENTRE's leads screen exists because the centre's workbook has a NOTES
 * column filled in on none of ninety rows — every conversation lived in
 * somebody's memory or somebody's WhatsApp. A follow-up list that told a
 * member they owed a call and then sent them to another site to record it
 * would rebuild that gap in a new place. So the log is written from here,
 * through the same call the console's own button makes: one conversation,
 * recorded once, wherever it was had.
 *
 * Stored THERE, not here — the mirror of CacTasks. Nothing on this side holds
 * a lead, so there is never a pair of rows to reconcile.
 */
declare(strict_types=1);

final class CacLeads
{
    /** Where CACENTRE answers. */
    public const PATH = '/api/afrovanguard-leads.php';

    /** Long enough for a slow deploy, short enough not to be a hang. */
    private const TIMEOUT = 3;

    /** One fetch per request, however many times a page asks. */
    private static array $memo = [];

    /** Where a member goes to work one of these properly. */
    public static function consoleUrl(): string
    {
        return CacTasks::site() . '/crm/leads.php';
    }

    /**
     * The follow-ups CACENTRE holds for this member.
     *
     * @return array{ok:bool, leads:array<int,array<string,mixed>>, error:string, url:string}
     */
    public static function forMember(int $memberId): array
    {
        $blank = ['ok' => false, 'leads' => [], 'error' => '', 'url' => self::consoleUrl()];
        if ($memberId <= 0) return $blank;
        if (isset(self::$memo[$memberId])) return self::$memo[$memberId];
        if (!CacSso::ready()) {
            return self::$memo[$memberId] = array_merge($blank, ['error' => 'not-configured']);
        }

        $url  = CacTasks::site() . self::PATH . '?t=' . rawurlencode(CacSso::mint(['id' => $memberId]));
        $body = self::get($url);
        if ($body === null) return self::$memo[$memberId] = array_merge($blank, ['error' => 'unreachable']);

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['ok'])) {
            $why = is_array($data) ? (string) ($data['error'] ?? 'refused') : 'unreadable';
            return self::$memo[$memberId] = array_merge($blank, ['error' => $why]);
        }

        return self::$memo[$memberId] = [
            'ok' => true, 'leads' => self::clean($data['leads'] ?? []),
            'error' => '', 'url' => self::consoleUrl(),
        ];
    }

    /**
     * Record a conversation against one of this member's leads, over there.
     *
     * The mirror of CacTasks::write(). The member id travels inside the signed
     * token and CACENTRE checks the lead's owner against its own row before
     * writing — a remote write is not a licence to name any id.
     *
     * @param array<string,mixed> $fields  summary, direction, next_action, next_action_at
     * @return array{ok:bool, error:string}
     */
    public static function log(int $memberId, int $leadId, array $fields): array
    {
        $bad = static fn(string $why): array => ['ok' => false, 'error' => $why];

        if ($memberId <= 0 || $leadId <= 0) return $bad('no-lead');
        if (trim((string) ($fields['summary'] ?? '')) === '') return $bad('no-summary');
        if (!CacSso::ready()) return $bad('not-configured');

        $url = CacTasks::site() . self::PATH . '?t=' . rawurlencode(CacSso::mint(['id' => $memberId]));
        $raw = self::send($url, array_merge(['action' => 'log', 'id' => $leadId], $fields));
        if ($raw === null) return $bad('unreachable');

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['ok'])) {
            return $bad(is_array($data) ? (string) ($data['error'] ?? 'refused') : 'unreadable');
        }

        /* The cached read is stale the moment a log lands: the follow-up it
           completed is no longer owed. */
        unset(self::$memo[$memberId]);
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Shape another system's rows into the few fields this site draws.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function clean(mixed $rows): array
    {
        if (!is_array($rows)) return [];
        $out = [];
        foreach (array_slice($rows, 0, 25) as $r) {
            if (!is_array($r)) continue;
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') continue;
            $out[] = [
                'id'      => (int) ($r['id'] ?? 0),
                'name'    => mb_substr($name, 0, 160),
                'phone'   => mb_substr(trim((string) ($r['phone'] ?? '')), 0, 40),
                'where'   => mb_substr(trim((string) ($r['where'] ?? '')), 0, 80),
                'stage'   => mb_substr(trim((string) ($r['stage'] ?? '')), 0, 40),
                'due'     => self::date($r['due'] ?? ''),
                'overdue' => !empty($r['overdue']),
                'what'    => mb_substr(trim((string) ($r['what'] ?? '')), 0, 160),
                'spoken'  => self::date($r['spoken'] ?? ''),
            ];
        }
        return $out;
    }

    /** A date, or nothing — never another system's idea of one, rendered raw. */
    private static function date(mixed $v): string
    {
        $s = trim((string) $v);
        if ($s === '') return '';
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $s) ? substr($s, 0, 10) : '';
    }

    /** One GET, every failure turned into null. TLS verification stays on. */
    private static function get(string $url): ?string
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Afrovanguard/1.0 (+leads)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($body === false || $code !== 200) ? null : (string) $body;
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
            CURLOPT_USERAGENT      => 'Afrovanguard/1.0 (+leads)',
        ]);
        $out = curl_exec($ch);
        curl_close($ch);
        /* The body comes back on a refusal too: the far side says which
           refusal it was, and the screen needs to pass that on. */
        return $out === false ? null : (string) $out;
    }
}
