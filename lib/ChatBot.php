<?php
/**
 * lib/ChatBot.php — the Google Chat task bot: record a promise, assign it right.
 *
 * Someone says "Bode to submit the revised STS report by Friday" in a Chat
 * space. This turns that into a tracked commitment with an owner and a due
 * date, in the same table the meeting minutes and mentorship sessions file into,
 * so it appears on the same boards and is chased by the same engine. A promise
 * made in chat has exactly the same standing as one made in a meeting; the only
 * thing that used to differ was whether anything remembered it.
 *
 * ── WHAT "CORRECTLY" MEANS HERE ──────────────────────────────────────────
 *
 * Assignment is the part that has to be right, because a wrong one puts
 * somebody else's name on a promise and then chases them for it. So the rule is
 * the one Commitments already sets and this does not relax: a name is resolved
 * only when it matches exactly one member. Two people called Ada means nobody
 * is assigned and the reply says so by name. An unrecognised name means nobody
 * is assigned and the reply says so. The bot would rather hand back an
 * unassigned task and one question than a confidently misfiled one.
 *
 * The same applies to the sender. A valid Google token proves the request came
 * from Google Chat; it does not prove the person behind it is a member. Their
 * email must resolve to an account before anything is written.
 *
 * ── PARSING ──────────────────────────────────────────────────────────────
 *
 * Deterministic first, model second — the pattern the rest of this codebase
 * uses. The regex parser handles the forms people actually type (`/task X by
 * Friday`, "@Ada to do X", "remind Bode to X tomorrow") and needs no key, no
 * network and no latency. The model is asked only to improve on that when it is
 * configured, and its answer is accepted only where it does not contradict
 * something the parser was sure about — a model must not reassign an owner the
 * user named explicitly with an @mention.
 *
 * ── WHY THE REPLY IS TEXT AND NOT A CARD ─────────────────────────────────
 *
 * Chat's `cardsV2` would look better. I cannot verify its widget schema from
 * here, and a card built from memory fails in production while looking finished
 * in review — the same reasoning that left MeetingClock::deliver() empty.
 * Chat's text format (bold, italic, <url|label>) does everything this reply
 * needs, renders identically on mobile, and is a format I can be certain of.
 * Swap it for a card once someone has an API reference open.
 *
 * ── SETUP ────────────────────────────────────────────────────────────────
 *
 *   AV_CHAT_AUDIENCE   required — the Chat app's project number, or the custom
 *                      audience configured on it. Without this the bot refuses
 *                      every request rather than accepting an unverified one.
 *   AV_CHAT_CERTS_URL  override for Google's public certificate endpoint.
 *
 * Point the Chat app's endpoint at https://<host>/webhooks/chat.
 */
declare(strict_types=1);

final class ChatBot
{
    /** Google signs Chat events as this service account. Nothing else is accepted. */
    public const ISSUER = 'chat@system.gserviceaccount.com';

    /** Where Google publishes the matching public certificates. */
    private const CERTS_URL = 'https://www.googleapis.com/service_accounts/v1/metadata/x509/chat@system.gserviceaccount.com';

    /** Tolerance for clock drift between Google and a shared host, in seconds. */
    private const SKEW = 120;

    /** Certificates are cached this long. Google rotates them slowly. */
    private const CERT_TTL = 3600;

    private static bool $ready = false;
    private static ?array $certCache = null;

    /* ════════════════════════════════════════════════════════════════
       Storage
       ════════════════════════════════════════════════════════════════ */

    /**
     * One row per task filed from chat.
     *
     * It exists so a chat commitment has a source row of its own to point at.
     * Using the sender's user id as `source_id` would have been simpler and
     * wrong: the dedupe key is (kind, source_id, normalised title), so the same
     * person saying "follow up with Bode" in January and again in June would
     * have collided into one commitment six months apart.
     */
    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            $db = Database::pdo();
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS av_chat_tasks (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                commitment_id INTEGER NOT NULL DEFAULT 0,
                space         VARCHAR(191) NOT NULL DEFAULT '',
                thread        VARCHAR(191) NOT NULL DEFAULT '',
                message_name  VARCHAR(191) NOT NULL DEFAULT '',
                sender_email  VARCHAR(191) NOT NULL DEFAULT '',
                sender_id     INTEGER NOT NULL DEFAULT 0,
                raw           TEXT NOT NULL DEFAULT '',
                created_at    VARCHAR(32) NOT NULL DEFAULT ''
            );");
            foreach (['idx_chattask_msg ON av_chat_tasks(message_name)',
                      'idx_chattask_cmt ON av_chat_tasks(commitment_id)'] as $ix) {
                try { $db->exec('CREATE INDEX IF NOT EXISTS ' . $ix); } catch (Throwable $e) {}
            }
        } catch (Throwable $e) { error_log('[chatbot] ensure: ' . $e->getMessage()); }
    }

    /* ════════════════════════════════════════════════════════════════
       Configuration
       ════════════════════════════════════════════════════════════════ */

    private static function cfg(string $k, string $def = ''): string
    {
        if (class_exists('Config') && Config::has($k)) return Config::str($k, $def);
        return (string) (getenv($k) ?: $def);
    }

    /** The audience claim every incoming token must carry. */
    public static function audience(): string { return trim(self::cfg('AV_CHAT_AUDIENCE')); }

    public static function certsUrl(): string { return self::cfg('AV_CHAT_CERTS_URL', self::CERTS_URL); }

    /** Is the bot switched on and able to verify what it receives? */
    public static function enabled(): bool
    {
        try { if (class_exists('AvRules') && !AvRules::bool('chat.enabled')) return false; }
        catch (Throwable $e) {}
        return self::audience() !== '';
    }

    /**
     * Spaces the bot will act in. Empty — the default — means any space it has
     * been added to, which is the sane setting: membership is the real gate,
     * and an allowlist that has to be edited by hand every time a team makes a
     * room is an allowlist that gets emptied in frustration.
     */
    private static function allowedSpaces(): array
    {
        try { return class_exists('AvRules') ? AvRules::list('chat.allowed_spaces') : []; }
        catch (Throwable $e) { return []; }
    }

    /* ════════════════════════════════════════════════════════════════
       Verifying that Google sent this
       ════════════════════════════════════════════════════════════════ */

    private static function b64urlDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        $out = base64_decode($s, true);
        return is_string($out) ? $out : '';
    }

    /**
     * Verify the bearer token on an inbound Chat request.
     *
     * $certs lets a test inject a keyset — the whole point of separating this
     * from the fetch is that every branch below (a forged signature, an `alg`
     * downgrade, a wrong audience, an expired token, an unknown key id) can be
     * exercised against a locally generated keypair, with no network and no
     * Google. Security code that is only testable in production is security
     * code nobody tests.
     *
     * @param array<string,string>|null $certs kid => PEM certificate
     * @return array{ok:bool,claims:array,error:string}
     */
    public static function verifyBearer(string $header, ?array $certs = null): array
    {
        $fail = static fn(string $e): array => ['ok' => false, 'claims' => [], 'error' => $e];

        $aud = self::audience();
        if ($aud === '') return $fail('AV_CHAT_AUDIENCE is not configured.');

        $header = trim($header);
        if (stripos($header, 'bearer ') !== 0) return $fail('No bearer token.');
        $token = trim(substr($header, 7));

        $parts = explode('.', $token);
        if (count($parts) !== 3) return $fail('Malformed token.');
        [$h64, $p64, $s64] = $parts;

        $head = json_decode(self::b64urlDecode($h64), true);
        if (!is_array($head)) return $fail('Malformed token header.');

        // Only RS256. Accepting the algorithm the token names is the classic
        // JWT hole: 'none' verifies trivially, and HS256 lets an attacker sign
        // with the PUBLIC key, which is published. The algorithm is ours to
        // decide, not the token's.
        if (($head['alg'] ?? '') !== 'RS256') return $fail('Unexpected signing algorithm.');

        $kid = (string) ($head['kid'] ?? '');
        if ($kid === '') return $fail('Token names no key.');

        $certs = $certs ?? self::fetchCerts();
        if (!$certs) return $fail('Could not load Google’s signing certificates.');
        if (!isset($certs[$kid])) return $fail('Unknown signing key.');

        $pub = @openssl_pkey_get_public((string) $certs[$kid]);
        if ($pub === false) return $fail('Unreadable signing certificate.');

        $sig = self::b64urlDecode($s64);
        $ok  = @openssl_verify($h64 . '.' . $p64, $sig, $pub, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) return $fail('Signature does not verify.');

        $claims = json_decode(self::b64urlDecode($p64), true);
        if (!is_array($claims)) return $fail('Malformed token payload.');

        if (($claims['iss'] ?? '') !== self::ISSUER) return $fail('Wrong issuer.');
        // The audience is what stops a token minted for somebody else's Chat app
        // being replayed at ours. hash_equals because it is a secret-ish
        // comparison and there is no reason to leak timing on it.
        if (!hash_equals($aud, (string) ($claims['aud'] ?? ''))) return $fail('Wrong audience.');

        $now = time();
        if (isset($claims['exp']) && $now > (int) $claims['exp'] + self::SKEW) return $fail('Token has expired.');
        if (isset($claims['iat']) && (int) $claims['iat'] > $now + self::SKEW)  return $fail('Token is not valid yet.');

        return ['ok' => true, 'claims' => $claims, 'error' => ''];
    }

    /** Google's current public certificates, kid => PEM. Cached per TTL. */
    private static function fetchCerts(): array
    {
        if (self::$certCache !== null && self::$certCache['exp'] > time()) return self::$certCache['certs'];

        $cached = null;
        try { $cached = Database::metaGet('chat_certs'); } catch (Throwable $e) {}
        if (is_string($cached) && $cached !== '') {
            $d = json_decode($cached, true);
            if (is_array($d) && (int) ($d['exp'] ?? 0) > time() && is_array($d['certs'] ?? null)) {
                self::$certCache = ['exp' => (int) $d['exp'], 'certs' => $d['certs']];
                return $d['certs'];
            }
        }

        if (!function_exists('curl_init')) return [];
        $ch = curl_init(self::certsUrl());
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $code >= 400) { error_log('[chatbot] certs HTTP ' . $code); return []; }

        $d = json_decode($body, true);
        if (!is_array($d) || !$d) return [];
        $certs = [];
        foreach ($d as $kid => $pem) { if (is_string($pem) && $pem !== '') $certs[(string) $kid] = $pem; }
        if (!$certs) return [];

        self::$certCache = ['exp' => time() + self::CERT_TTL, 'certs' => $certs];
        try { Database::metaSet('chat_certs', json_encode(self::$certCache)); } catch (Throwable $e) {}
        return $certs;
    }

    /* ════════════════════════════════════════════════════════════════
       Parsing what somebody typed
       ════════════════════════════════════════════════════════════════ */

    /** Words that introduce a deadline, longest first so "by end of" beats "by". */
    private const DUE_PATTERNS = [
        '/\bby\s+end\s+of\s+(?:the\s+)?(day|week|month)\b/i'   => 'endof',
        '/\b(?:due|by|before|on)\s+(\d{4}-\d{2}-\d{2})\b/i'    => 'iso',
        '/\bin\s+(\d{1,3})\s+(day|days|week|weeks)\b/i'        => 'in',
        '/\b(?:due|by|before|on)?\s*\b(today)\b/i'             => 'today',
        '/\b(?:due|by|before|on)?\s*\b(tomorrow)\b/i'          => 'tomorrow',
        '/\b(?:due|by|before|on)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i' => 'weekday',
        '/\b(next\s+week)\b/i'                                 => 'nextweek',
    ];

    /**
     * Pull a task out of a message. No network, no key, no model.
     *
     * @return array{title:string,owner:string,due_days:?int,owner_explicit:bool}
     */
    public static function parseTask(string $text, array $mentions = []): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        // Strip the bot's own @mention — it is addressing, not content.
        //
        // ONE token, and no spaces inside it. The first version allowed spaces
        // in the name (for "@Afrovanguard Task Bot") and was greedy, so
        // "@Bode please write up the outreach notes" matched as far as the last
        // space and left the title as "notes". A multi-word bot name arrives
        // through the annotations instead, which the loop below handles by
        // display name — this pattern only has to cover the single-token case.
        $text = trim((string) preg_replace('/^@[\w.\'-]{1,40}(?:\s+|$)/u', '', $text));
        // And the slash command, if they used one.
        $text = trim((string) preg_replace('/^\/(task|todo|action|remind)\b:?\s*/i', '', $text));

        $owner = '';
        $ownerExplicit = false;

        // An @mention Google resolved for us is the strongest possible signal:
        // the person picked a human out of a list. Nothing later may override it.
        foreach ($mentions as $m) {
            $dn = trim((string) ($m['displayName'] ?? ''));
            if ($dn !== '' && !self::isSelf($dn)) { $owner = $dn; $ownerExplicit = true; break; }
        }
        // Remove every mention from the text either way, so "@Bode" does not end
        // up inside the task title. Whole words only: a plain str_replace on the
        // display name would gut "Book the hall" for a member called Book.
        foreach ($mentions as $m) {
            $dn = trim((string) ($m['displayName'] ?? ''));
            if ($dn === '') continue;
            $q = preg_quote($dn, '/');
            $text = (string) preg_replace('/@?\b' . $q . '\b/u', ' ', $text);
        }
        $text = trim((string) preg_replace('/\s{2,}/u', ' ', $text));

        $dueDays = null;
        foreach (self::DUE_PATTERNS as $re => $kind) {
            if (!preg_match($re, $text, $m)) continue;
            $dueDays = self::dueDaysFor($kind, (string) ($m[1] ?? ''));
            if ($dueDays !== null) { $text = trim((string) preg_replace($re, '', $text, 1)); break; }
        }

        // "remind Bode to X" / "ask Ada to X" / "Bode to X" — a name before "to".
        if (!$ownerExplicit && preg_match('/^(?:remind|ask|tell|get)?\s*([A-Z][\w\'-]+(?:\s+[A-Z][\w\'-]+)?)\s+to\s+(.+)$/u', $text, $m)) {
            $owner = trim($m[1]); $text = trim($m[2]);
        } elseif (!$ownerExplicit && preg_match('/^(.+?)\s+\((?:for\s+)?([A-Z][\w\'-]+(?:\s+[A-Z][\w\'-]+)?)\)$/u', $text, $m)) {
            $text = trim($m[1]); $owner = trim($m[2]);
        } elseif (!$ownerExplicit && preg_match('/^(.+?)\s+(?:for|assigned to|owner:)\s+([A-Z][\w\'-]+(?:\s+[A-Z][\w\'-]+)?)$/u', $text, $m)) {
            $text = trim($m[1]); $owner = trim($m[2]);
        }

        $title = trim((string) preg_replace('/\s{2,}/', ' ', $text));
        $title = trim($title, " \t\n\r\0\x0B-–—:,;.");
        if ($title !== '') $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);

        return ['title' => mb_substr($title, 0, 300), 'owner' => mb_substr($owner, 0, 120),
                'due_days' => $dueDays, 'owner_explicit' => $ownerExplicit];
    }

    private static function isSelf(string $displayName): bool
    {
        $n = strtolower($displayName);
        return strpos($n, 'afrovanguard') !== false || strpos($n, 'task bot') !== false;
    }

    /** A deadline phrase as a number of days from today. */
    private static function dueDaysFor(string $kind, string $captured): ?int
    {
        $captured = strtolower(trim($captured));
        switch ($kind) {
            case 'today':    return 1;   // Commitments' minimum is 1 day out.
            case 'tomorrow': return 1;
            case 'nextweek': return 7;
            case 'in':
                // The unit was matched but not captured; recover it from the phrase.
                return null;
            case 'iso':
                $ts = strtotime($captured . ' 00:00:00 UTC');
                if ($ts === false) return null;
                $d = (int) ceil(($ts - strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC')) / 86400);
                return $d >= 1 ? min(365, $d) : null;
            case 'endof':
                if ($captured === 'day')   return 1;
                if ($captured === 'week')  return max(1, 7 - (int) gmdate('N'));
                if ($captured === 'month') return max(1, (int) gmdate('t') - (int) gmdate('j'));
                return null;
            case 'weekday':
                $target = array_search($captured, ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'], true);
                if ($target === false) return null;
                $today = (int) gmdate('N') - 1;                 // 0 = Monday
                $delta = ((int) $target - $today + 7) % 7;
                return $delta === 0 ? 7 : $delta;               // "by Friday" on Friday means next Friday
        }
        return null;
    }

    /* ════════════════════════════════════════════════════════════════
       Who is this, and who is it for
       ════════════════════════════════════════════════════════════════ */

    /** A Chat sender's email → member id, or 0. */
    public static function memberByEmail(string $email): int
    {
        $email = strtolower(trim($email));
        if ($email === '') return 0;
        try {
            $st = Database::pdo()->prepare('SELECT id FROM lms_users WHERE LOWER(email) = ? LIMIT 1');
            $st->execute([$email]);
            return (int) ($st->fetchColumn() ?: 0);
        } catch (Throwable $e) { return 0; }
    }

    /**
     * Resolve an owner name, strictly.
     *
     * @return array{id:int,why:string,candidates:list<string>}
     *   id 0 with `why` = 'none' | 'ambiguous' | 'unknown', so the reply can say
     *   WHICH kind of failure it was. "I could not assign that" is unhelpful;
     *   "there are two Adas — which one?" can be answered.
     */
    public static function resolveOwner(string $name): array
    {
        $name = trim($name);
        if ($name === '') return ['id' => 0, 'why' => 'none', 'candidates' => []];
        try {
            $st = Database::pdo()->prepare('SELECT id, name FROM lms_users WHERE LOWER(name) = ? LIMIT 5');
            $st->execute([strtolower($name)]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) === 1) return ['id' => (int) $rows[0]['id'], 'why' => 'exact', 'candidates' => []];
            if (count($rows) > 1) {
                return ['id' => 0, 'why' => 'ambiguous', 'candidates' => array_column($rows, 'name')];
            }
            // No exact match. Offer near ones in the reply rather than guessing —
            // a prefix match is a good suggestion and a terrible assignment.
            $st = Database::pdo()->prepare('SELECT name FROM lms_users WHERE LOWER(name) LIKE ? LIMIT 4');
            $st->execute([strtolower($name) . '%']);
            $near = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return ['id' => 0, 'why' => 'unknown', 'candidates' => array_map('strval', $near)];
        } catch (Throwable $e) { return ['id' => 0, 'why' => 'unknown', 'candidates' => []]; }
    }

    /* ════════════════════════════════════════════════════════════════
       Handling one event
       ════════════════════════════════════════════════════════════════ */

    /**
     * Turn a verified Chat event into a reply.
     *
     * Pure with respect to HTTP — the endpoint does transport, this does
     * meaning — so the whole decision tree is testable by handing it an event
     * array. Always returns something sayable; a bot that goes silent on an
     * error looks broken in a room full of people.
     *
     * @return array{text:string}
     */
    public static function handleEvent(array $event): array
    {
        self::ensure();
        $type = (string) ($event['type'] ?? '');

        if ($type === 'ADDED_TO_SPACE')   return ['text' => self::helpText()];
        if ($type === 'REMOVED_FROM_SPACE') return ['text' => ''];
        if ($type !== 'MESSAGE')          return ['text' => ''];

        $msg    = (array) ($event['message'] ?? []);
        $space  = (string) ($msg['space']['name'] ?? ($event['space']['name'] ?? ''));
        $sender = (array) ($msg['sender'] ?? ($event['user'] ?? []));
        $email  = strtolower(trim((string) ($sender['email'] ?? '')));
        $text   = (string) ($msg['argumentText'] ?? ($msg['text'] ?? ''));

        $allowed = self::allowedSpaces();
        if ($allowed && $space !== '' && !in_array($space, $allowed, true)) {
            return ['text' => 'This space is not on the allowed list, so I am not recording tasks here. An administrator can add it in the Studio under Rules & AI.'];
        }

        if (trim($text) === '' || preg_match('/^\s*(help|\/help|what can you do\??)\s*$/i', $text)) {
            return ['text' => self::helpText()];
        }

        // A valid Google token proves the request came from Chat. It does not
        // prove the person is one of ours, and a task filed against an account
        // that does not exist is a task nobody is accountable for.
        $senderId = self::memberByEmail($email);
        if ($senderId === 0) {
            return ['text' => '*I could not match you to a member account.*' . "\n"
                            . 'Tasks are tracked against real people, so I need to know who you are before I record one. '
                            . ($email !== '' ? 'The address I see is `' . self::safe($email) . '`. ' : '')
                            . 'Ask an administrator to add that address to your member profile.'];
        }

        $mentions = self::mentionsFrom($msg);
        $parsed   = self::parseTask($text, $mentions);
        $parsed   = self::refine($text, $parsed);

        if ($parsed['title'] === '') {
            return ['text' => 'I could not find a task in that. Try something like:' . "\n"
                            . '`/task Submit the revised STS report by Friday`'];
        }

        return self::file($parsed, [
            'space' => $space, 'thread' => (string) ($msg['thread']['name'] ?? ''),
            'message' => (string) ($msg['name'] ?? ''), 'email' => $email,
            'sender_id' => $senderId, 'raw' => $text,
        ]);
    }

    /** Mentions Google resolved on the message, excluding the bot itself. */
    private static function mentionsFrom(array $msg): array
    {
        $out = [];
        foreach ((array) ($msg['annotations'] ?? []) as $a) {
            if (($a['type'] ?? '') !== 'USER_MENTION') continue;
            $u = (array) ($a['userMention']['user'] ?? []);
            if (($u['type'] ?? '') === 'BOT') continue;
            $out[] = ['displayName' => (string) ($u['displayName'] ?? ''),
                      'name' => (string) ($u['name'] ?? ''),
                      'email' => strtolower((string) ($u['email'] ?? ''))];
        }
        return $out;
    }

    /**
     * Let a model improve the parse — but never contradict what was certain.
     *
     * An @mention is a person picked out of a list; a model must not reassign
     * it. A date the regex matched is unambiguous; a model must not move it.
     * The model's job is the residue: a rambling sentence the patterns did not
     * fit, where a cleaner title or a spotted owner genuinely helps.
     */
    private static function refine(string $raw, array $parsed): array
    {
        if (!class_exists('AvRouter') || !AvRouter::available(AvRouter::JOB_BULK)) return $parsed;
        // Nothing to gain when the deterministic parse already got everything.
        if ($parsed['title'] !== '' && $parsed['owner'] !== '' && $parsed['due_days'] !== null) return $parsed;

        $sys = "You extract one action item from a chat message. Reply with ONLY a JSON object:\n"
             . '{"title":"…","owner":"…","due_days":N}' . "\n"
             . "title: the task as a short imperative, no owner name, no date. "
             . "owner: the person responsible, EXACTLY as written in the message, or \"\" if nobody is named. "
             . "due_days: whole days from today, or null if no deadline is stated. "
             . "Invent nothing. If the message states no owner, owner is empty — do not guess from context.";
        $r = AvRouter::complete(AvRouter::JOB_BULK, "Message:\n" . mb_substr($raw, 0, 2000), [
            'system' => $sys, 'max_tokens' => 200, 'temperature' => 0, 'actor' => 'chatbot.parse',
        ]);
        if (empty($r['ok'])) return $parsed;

        $j = null;
        if (preg_match('/\{.*\}/s', (string) $r['text'], $m)) $j = json_decode($m[0], true);
        if (!is_array($j)) return $parsed;

        $title = trim((string) ($j['title'] ?? ''));
        if ($title !== '' && $parsed['title'] === '') $parsed['title'] = mb_substr($title, 0, 300);

        // Only fills a gap. An explicit @mention is never overwritten.
        if (!$parsed['owner_explicit'] && $parsed['owner'] === '') {
            $o = trim((string) ($j['owner'] ?? ''));
            if ($o !== '') $parsed['owner'] = mb_substr($o, 0, 120);
        }
        if ($parsed['due_days'] === null && isset($j['due_days']) && $j['due_days'] !== null) {
            $d = (int) $j['due_days'];
            if ($d >= 1 && $d <= 365) $parsed['due_days'] = $d;
        }
        return $parsed;
    }

    /* ════════════════════════════════════════════════════════════════
       Filing it
       ════════════════════════════════════════════════════════════════ */

    private static function file(array $parsed, array $ctx): array
    {
        $owner = ['id' => 0, 'why' => 'none', 'candidates' => []];
        if ($parsed['owner'] !== '') $owner = self::resolveOwner($parsed['owner']);

        $now = gmdate('Y-m-d H:i:s');
        try {
            $db = Database::pdo();
            // The chat row first, so the commitment has a source of its own to
            // point at and its dedupe key is unique to this message.
            $db->prepare('INSERT INTO av_chat_tasks (commitment_id, space, thread, message_name, sender_email, sender_id, raw, created_at)
                          VALUES (0,?,?,?,?,?,?,?)')
               ->execute([mb_substr($ctx['space'], 0, 191), mb_substr($ctx['thread'], 0, 191),
                          mb_substr($ctx['message'], 0, 191), mb_substr($ctx['email'], 0, 191),
                          (int) $ctx['sender_id'], mb_substr($ctx['raw'], 0, 2000), $now]);
            $chatId = (int) $db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[chatbot] file: ' . $e->getMessage());
            return ['text' => 'Something went wrong recording that. Nothing was saved — please try again.'];
        }

        $r = Commitments::fileMany(Commitments::SRC_CHAT, $chatId, [[
            'task' => $parsed['title'], 'owner' => $parsed['owner'], 'due_days' => $parsed['due_days'],
        ]], (int) $ctx['sender_id']);

        $cid = (int) ($r['ids'][0] ?? 0);
        if ($cid <= 0) return ['text' => 'I could not record that. Nothing was saved.'];

        try { Database::pdo()->prepare('UPDATE av_chat_tasks SET commitment_id = ? WHERE id = ?')->execute([$cid, $chatId]); }
        catch (Throwable $e) {}

        // Assignment is a deliberate second step, not a side effect of filing:
        // Commitments only auto-assigns when the rule allows it, and this bot
        // has a stronger signal than a name in a transcript — a person picked
        // from a list. It still refuses anything that is not a unique match.
        if ($owner['id'] > 0) {
            try { Commitments::assign($cid, $owner['id'], (int) $ctx['sender_id']); }
            catch (Throwable $e) { error_log('[chatbot] assign: ' . $e->getMessage()); }
        }

        $c = Commitments::get($cid);
        $due = (string) ($c['due'] ?? '');
        return ['text' => self::confirmation($parsed['title'], $owner, $parsed['owner'], $due)];
    }

    /**
     * What the bot says back.
     *
     * Every branch states what WAS recorded before what was not, because the
     * task being tracked is the reassuring part and the missing owner is the
     * actionable part — in that order, or people read the failure and assume
     * nothing was saved.
     */
    private static function confirmation(string $title, array $owner, string $named, string $due): string
    {
        $lines = ['*Recorded:* ' . self::safe($title)];
        if ($due !== '') $lines[] = '*Due:* ' . self::safe($due);

        if ($owner['id'] > 0) {
            $n = '';
            try {
                $st = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?');
                $st->execute([$owner['id']]);
                $n = (string) ($st->fetchColumn() ?: '');
            } catch (Throwable $e) {}
            $lines[] = '*Owner:* ' . self::safe($n !== '' ? $n : $named);
        } elseif ($owner['why'] === 'ambiguous') {
            $lines[] = '*Owner:* not set — there is more than one ' . self::safe($named)
                     . ' (' . self::safe(implode(', ', $owner['candidates'])) . '). '
                     . 'Say who you meant and I will assign it.';
        } elseif ($owner['why'] === 'unknown') {
            $lines[] = '*Owner:* not set — I do not have a member called ' . self::safe($named) . '.'
                     . ($owner['candidates'] ? ' Did you mean ' . self::safe(implode(' or ', $owner['candidates'])) . '?' : '');
        } else {
            $lines[] = '*Owner:* not set. Reply with a name, or claim it yourself in the portal.';
        }
        $lines[] = '_It is on the commitments board now, and will be chased like any other._';
        return implode("\n", $lines);
    }

    /**
     * Chat renders *bold*, _italic_ and `code` in plain text, so a message
     * containing those characters can restyle the reply around it. Neutralise
     * them: this text is quoted back from user input.
     */
    private static function safe(string $s): string
    {
        return trim((string) preg_replace('/[*_`~<>]/u', '', $s));
    }

    private static function helpText(): string
    {
        return "*Afrovanguard task bot.* Tell me a promise and I will track it.\n"
             . "\n"
             . "`/task Submit the revised STS report by Friday`\n"
             . "`@Bode to draft the Alimosho outreach plan by 2026-09-01`\n"
             . "`remind Ada to call the venue tomorrow`\n"
             . "\n"
             . "I record it against the same board as meeting minutes, with a due date, "
             . "and I only assign it when the name matches exactly one member — I will never guess "
             . "whose promise it is.";
    }
}
