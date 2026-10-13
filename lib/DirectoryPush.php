<?php
/**
 * lib/DirectoryPush.php — telling CACENTRE who has an account here.
 *
 * ── WHAT THIS IS FOR ───────────────────────────────────────────────────────
 * The estate runs five apps over twelve separate identity stores with no
 * common key, so one person is five unrelated rows and nobody can answer
 * "who is this, and what do they have?" without opening five consoles. The
 * person directory at CACENTRE is one record per human, and this is the half
 * of it that lives here: the two stores this site owns, pushed on the cron
 * tick the site already runs.
 *
 * ── WE PUSH; CACENTRE READS NOTHING ────────────────────────────────────────
 * Already the estate's posture for money (CFIS ADR 22: "Properties push
 * settlements; CFIS reads nobody's database"), and the directory does not get
 * a second one. CACENTRE holds no credential to this database, so a break-in
 * there cannot read this site's members — it gets a list of names, addresses
 * and roles, which is what it was given on purpose.
 *
 * ── WHAT GOES, AND WHAT MUST NOT ───────────────────────────────────────────
 * Exactly the columns the directory matches on or shows an operator deciding
 * whether two records are one human. NO password hash, NO session token, NO
 * postal address, NO date of birth. CACENTRE refuses a field it did not ask
 * for rather than ignoring it, so a well-meaning addition here fails loudly
 * on the first push instead of quietly becoming a second copy of everybody's
 * personal data.
 *
 * ── THIS SIDE SAYS WHO. IT DOES NOT SAY WHAT THEY MAY DO ───────────────────
 * `local_role` travels because an operator looking at two candidate records
 * needs to know what each account IS. CACENTRE acts on it nowhere — the same
 * rule CacSso.php states at the top of this directory, for the same reason:
 * if permission were read off what we send, adding a role here would silently
 * widen access there.
 *
 * ── THE FORMAT IS NOT OURS TO CHANGE ───────────────────────────────────────
 * v1.<base64url payload>.<base64url HMAC-SHA256>, the envelope CacSso and
 * CfisCheckout already use. The rows travel INSIDE the signature, never
 * beside it: "an unsigned amount sitting next to a signed one, and the wrong
 * one is always the one somebody reads" (CfisCheckout::post).
 *
 * tests/directory-vectors.json is a byte-identical copy of the file in
 * cacentre-site/cac/tests/, asserted in both suites. It is the only thing
 * holding two implementations in two repositories to one wire format.
 */
declare(strict_types=1);

final class DirectoryPush
{
    /** Who we are in the directory's app registry. */
    public const APP = 'avg';

    /** The two stores this site owns. Nothing else may be sent under our key. */
    public const STORES = ['lms_users', 'admin_users'];

    /** Rows per push. CACENTRE refuses a larger batch and names this number. */
    public const BATCH = 500;

    /** Must match DirectoryEnvelope::TTL on the receiving side. */
    public const TTL = 300;

    public const TYP = 'dir.push';

    /** Where the sweep got to, so a tick picks up where the last one stopped. */
    private const CURSOR = 'dir_push_cursor';

    public static function secret(): string
    {
        $v = getenv('DIR_PUSH_SECRET_AVG');
        if ($v !== false && trim((string) $v) !== '') return trim((string) $v);
        if (defined('DIR_PUSH_SECRET_AVG') && trim((string) constant('DIR_PUSH_SECRET_AVG')) !== '') {
            return trim((string) constant('DIR_PUSH_SECRET_AVG'));
        }

        return '';
    }

    /**
     * Is the push configured?
     *
     * A half-set key counts as absent — the same rule CacSso::ready() applies.
     * Unset, this feature does nothing at all and says so once; it must never
     * be the reason a cron tick reports a failure every five minutes.
     */
    public static function ready(): bool
    {
        $s = self::secret();
        if (strlen($s) < 32) return false;

        return !preg_match('/^<.*>$/s', $s)
            && !preg_match('/x{8,}/i', $s)
            && !preg_match('/^(your|changeme|replace|example|todo)/i', $s);
    }

    /** Where CACENTRE is. One variable moves the SSO handoff and this. */
    public static function endpoint(): string
    {
        $base = class_exists('CacSso') ? CacSso::base() : 'https://cacentre.afrovanguard.org.ng';

        return $base . '/api/directory-push.php?app=' . self::APP;
    }

    /* ══ The rows ════════════════════════════════════════════════════════ */

    /**
     * One portal member.
     *
     * `email_unique_at_source` is not sent: the receiver derives it from its
     * own map of which schemas enforce uniqueness, and a sender that could
     * assert "trust my addresses" is a sender that can cause a merge. Ours
     * happens to be VARCHAR(191) UNIQUE NOT NULL, which is why this store
     * auto-links over there and `admin_users` also does.
     *
     * @param array<string,mixed> $r a row of lms_users
     * @return array<string,mixed>
     */
    public static function fromLmsUser(array $r): array
    {
        return [
            'store' => 'lms_users',
            'local_id' => (string) ($r['id'] ?? ''),
            'email' => strtolower(trim((string) ($r['email'] ?? ''))),
            /* This store HAS the concept, so the answer is true or false.
               admin_users below does not, and sends null — three states,
               because "we do not track it" is not "not verified". */
            'email_verified' => !empty($r['email_verified']),
            'name' => trim((string) ($r['name'] ?? '')),
            'local_role' => trim((string) ($r['role'] ?? '')),
            'local_status' => trim((string) ($r['status'] ?? '')),
            'last_seen_at' => self::when($r['last_login'] ?? null),
        ];
    }

    /**
     * One Studio admin.
     *
     * The table is keyed BY EMAIL — `email VARCHAR(191) PRIMARY KEY` — so the
     * address is also the local id. It carries no name and no last login;
     * those go as empty rather than invented, and the directory shows the
     * account for what it is.
     *
     * An off-domain row is sent, and sent as inactive. AdminRoles::orgEmail()
     * refuses to honour one — "the address is the identity the organisation
     * controls; a personal mailbox is one it cannot suspend" — but it is
     * still a row in this table, and a directory that hid it would be telling
     * an operator the estate is tidier than it is. `local_status` carries the
     * difference, and CACENTRE acts on neither.
     *
     * @param array<string,mixed> $r a row of admin_users
     * @return array<string,mixed>
     */
    public static function fromAdminUser(array $r): array
    {
        $email = strtolower(trim((string) ($r['email'] ?? '')));
        $honoured = class_exists('AdminRoles') ? AdminRoles::orgEmail($email) : true;

        return [
            'store' => 'admin_users',
            'local_id' => $email,
            'email' => $email,
            /* NULL, not false: this table has no verification concept at all,
               and sending false would mark every Studio admin "unverified"
               on a screen that is not about them. */
            'email_verified' => null,
            'name' => '',
            'local_role' => trim((string) ($r['role'] ?? '')),
            'local_status' => $honoured ? 'active' : 'off_domain',
            'last_seen_at' => null,
        ];
    }

    /** A date CACENTRE can store, or null. Never a guess. */
    public static function when(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') return null;
        $t = strtotime($s);

        return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
    }

    /* ══ The sweep ═══════════════════════════════════════════════════════ */

    /**
     * The next page of members, and where the cursor should go after it.
     *
     * A ROLLING SWEEP, not a diff. lms_users has no updated_at and no change
     * marker worth trusting — last_login moves on sign-in, which is not the
     * same thing — so rather than invent one and quietly miss every edit it
     * does not catch, this walks the table in id order, BATCH at a time, and
     * starts again at the top when it runs off the end. Every row is re-sent
     * once per sweep and upserts on its source key, so the directory is
     * eventually correct for every account rather than exactly correct for
     * the ones a guessed watermark happened to notice.
     *
     * @return array{rows:list<array<string,mixed>>, cursor:int, wrapped:bool, dropped_dates:int}
     */
    public static function batch(PDO $p, int $cursor, int $limit = self::BATCH): array
    {
        $limit = max(1, min(self::BATCH, $limit));
        $rows = [];
        $last = $cursor;
        $dropped = 0;

        $st = $p->prepare(
            'SELECT id, name, email, role, status, last_login, email_verified
               FROM lms_users WHERE id > ? ORDER BY id ASC LIMIT ' . (int) $limit
        );
        $st->execute([$cursor]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $shaped = self::fromLmsUser($r);
            /* COUNTED HERE BECAUSE IT CANNOT BE COUNTED ANYWHERE ELSE.
               CACENTRE counts unreadable dates it is sent — but we normalise
               before sending, so it never sees one and its counter reads
               zero however much rubbish is in this column. A check that is
               defeated by the thing it is checking is worse than none: it
               reports all-clear. `last_login` is a TEXT column written by
               more than one code path over the years, which is exactly the
               kind of column that quietly stops parsing. */
            if ($shaped['last_seen_at'] === null && trim((string) ($r['last_login'] ?? '')) !== '') $dropped++;
            $rows[] = $shaped;
            $last = (int) $r['id'];
        }

        /* A short page is the end of the table: back to the top next tick. */
        $wrapped = count($rows) < $limit;
        if ($wrapped) $last = 0;

        return ['rows' => $rows, 'cursor' => $last, 'wrapped' => $wrapped, 'dropped_dates' => $dropped];
    }

    /**
     * Every Studio admin, in batch-sized pages.
     *
     * Its OWN batch rather than riding along with the members, which is what
     * this did first. Sharing one batch meant the admin rows ate the member
     * page's room — and if this table ever reached BATCH rows there would be
     * no room left at all, the cursor would never advance, and the sweep
     * would re-send the same admins for ever while no member was ever
     * pushed. The table is tiny today. "Tiny today" is not a guard.
     *
     * @return list<list<array<string,mixed>>>
     */
    public static function adminBatches(PDO $p, int $limit = self::BATCH): array
    {
        $rows = [];
        foreach (self::rows($p, 'SELECT email, role FROM admin_users ORDER BY email ASC') as $r) {
            $rows[] = self::fromAdminUser($r);
        }

        return $rows === [] ? [] : array_chunk($rows, max(1, min(self::BATCH, $limit)));
    }

    /**
     * Read, tolerating a table this deployment has not provisioned.
     *
     * admin_users is created on demand by AdminRoles::ensure(), so a site
     * where nobody has opened the Studio genuinely does not have it. That is
     * not a failure and must not stop the members being pushed.
     *
     * @return list<array<string,mixed>>
     */
    private static function rows(PDO $p, string $sql): array
    {
        try { return $p->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { return []; }
    }

    /* ══ The wire ════════════════════════════════════════════════════════ */

    /**
     * Sign a batch. Identical in every byte to DirectoryEnvelope::mint() on
     * the receiving side, and held to that by the shared vectors.
     *
     * @param array<string,mixed> $claims
     */
    public static function mint(array $claims, ?string $secret = null, ?int $now = null): string
    {
        $now ??= time();
        $payload = json_encode($claims + [
            'iss'   => self::APP,
            'typ'   => self::TYP,
            'nonce' => bin2hex(random_bytes(16)),
            'iat'   => $now,
            'exp'   => $now + self::TTL,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $body = rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
        $mac = hash_hmac('sha256', $body, $secret ?? self::secret(), true);

        return 'v1.' . $body . '.' . rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /**
     * Send one batch and report honestly.
     *
     * @param  list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public static function send(array $rows): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'not-configured'];
        if ($rows === [])   return ['ok' => true, 'written' => 0, 'skipped' => 'nothing to send'];

        $token = self::mint(['rows' => array_values($rows)]);

        $ch = curl_init(self::endpoint());
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            /* The batch is in the token, which is in the body. Not a header:
               a 500-row token is far past Apache's 8KB header limit, and that
               is a limit discovered on the first REAL push rather than the
               first test one. */
            CURLOPT_POSTFIELDS => json_encode(['token' => $token], JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err !== '' || $body === false) {
            error_log('[directory-push] ' . ($err ?: 'no response'));

            return ['ok' => false, 'error' => 'unreachable'];
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            error_log('[directory-push] HTTP ' . $code . ' in a shape this does not understand');

            return ['ok' => false, 'error' => 'bad-response', 'http' => $code];
        }
        /* A refusal is logged with the reason CACENTRE gave. "expired" and
           "bad_signature" are different afternoons — one is a clock, the
           other is a key — and collapsing them into "push failed" costs
           whichever one it was. */
        if (empty($json['ok'])) {
            error_log('[directory-push] HTTP ' . $code . ' ' . json_encode($json));
        }

        return $json + ['http' => $code];
    }

    /* ══ The tick ════════════════════════════════════════════════════════ */

    /**
     * One pass per call, from wherever the last one stopped.
     *
     * The cursor only advances on a push CACENTRE accepted. A batch lost to a
     * timeout is re-sent on the next tick rather than skipped — the whole
     * sweep is idempotent, so re-sending costs nothing and skipping would
     * leave a silent hole in the directory until the sweep came round again.
     *
     * @return array<string,mixed>
     */
    public static function tick(?PDO $p = null, int $limit = self::BATCH): array
    {
        if (!self::ready()) return ['skipped' => 'no key'];

        $p ??= Database::pdo();
        $cursor = (int) (Database::metaGet(self::CURSOR) ?? 0);
        $out = ['sent' => 0, 'written' => 0, 'refused' => 0];
        $failed = false;

        $note = static function (array $res) use (&$out, &$failed): void {
            $out['written'] += (int) ($res['written'] ?? 0);
            $out['refused'] += (int) ($res['refused'] ?? 0);
            if (empty($res['ok'])) { $failed = true; $out['error'] = (string) ($res['error'] ?? 'refused'); }
        };

        /* The admin table is small and has no id to page by, so it goes whole
           at the top of each sweep rather than on every tick. */
        if ($cursor <= 0) {
            foreach (self::adminBatches($p, $limit) as $chunk) {
                $out['sent'] += count($chunk);
                $note(self::send($chunk));
            }
        }

        $b = self::batch($p, $cursor, $limit);
        if ($b['rows'] !== []) {
            $out['sent'] += count($b['rows']);
            $note(self::send($b['rows']));
        }
        if ($b['dropped_dates'] > 0) $out['dropped_dates'] = $b['dropped_dates'];

        /* Only move on if this side was heard. A refused or unreachable push
           leaves the cursor where it was, and the same page goes again. */
        if (!$failed) Database::metaSet(self::CURSOR, (string) $b['cursor']);

        return $out + ['wrapped' => $b['wrapped'], 'cursor' => $failed ? $cursor : $b['cursor']];
    }
}
