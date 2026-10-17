<?php
/**
 * lib/CacMail.php — sending CACENTRE's bulk mail from this site, under this
 * site's own name.
 *
 * ── WHY THIS EXISTS ────────────────────────────────────────────────────────
 * Two reasons, and the second is the one that makes it worth a file.
 *
 * THE NAME. A letter to a supporter arrives from "CACENTRE", which is the
 * internal console's name — not the organisation the person actually knows.
 * Routed through here it arrives from Afrovanguard, the site they signed up
 * on, which is the only name on the envelope they have ever agreed to.
 *
 * THE ALLOWANCE. Shared cPanel hosting meters outbound mail, and the two
 * sites are metered SEPARATELY because they are two hosting accounts. Before
 * this, one site's quota was the whole organisation's ceiling and the other's
 * went unused — and a send that spends the whole allowance also stops that
 * site's password resets, receipts and enquiry replies for the rest of the
 * window. Appeals already says this in as many words. So CACENTRE now offers
 * the list here first, this site takes what it can afford, and CACENTRE keeps
 * the rest. Two allowances, one send.
 *
 * ── IT IS A QUEUE THAT DRAINS, NEVER A SEND IN ONE REQUEST ─────────────────
 * The same shape as Appeals: accepting a batch writes rows, and `tasks/cron.php`
 * sends them a few at a time. Sending five hundred inside the HTTP request
 * CACENTRE is waiting on would time out, and a timeout on a half-sent batch is
 * the one state nobody can reason about afterwards — did it go?
 *
 * ── AND IT HOLDS AN ADDRESS ONLY UNTIL IT HAS USED IT ──────────────────────
 * These are CACENTRE's people, not ours. The row carries the address because
 * there is no way to send without it, and the address is ERASED the moment
 * the message goes — what stays is a hashed key, so a second offer of the
 * same batch cannot write to somebody twice. A copy of another system's
 * contact list is not something to keep because it was once convenient.
 *
 * The connection is the one the two sites already share: a v1 token signed
 * with AV_SSO_SECRET, verified by integrations/cacentre-mail.php exactly as
 * integrations/cacentre-tasks.php verifies its own. No second secret.
 */
declare(strict_types=1);

final class CacMail
{
    /**
     * How many this site will send per cron tick.
     *
     * Appeals' number, for Appeals' reason: a shared host meters by the hour,
     * and the whole point of this feature is to SHARE an allowance rather
     * than let one job swallow it.
     */
    public const TICK_BATCH = 60;

    /** How many this site will accept in a day, before CACENTRE keeps the rest. */
    public const DAILY_DEFAULT = 600;

    /** Nothing older than this is still worth sending. */
    public const STALE_DAYS = 3;

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            Database::pdo()->exec("CREATE TABLE IF NOT EXISTS av_cac_mail (
                id INTEGER PRIMARY KEY " . (Database::driver() === 'mysql' ? 'AUTO_INCREMENT' : 'AUTOINCREMENT') . ",
                batch_ref   VARCHAR(64)  NOT NULL DEFAULT '',
                email_key   VARCHAR(64)  NOT NULL,
                to_email    VARCHAR(190) NOT NULL DEFAULT '',
                to_name     VARCHAR(160) NOT NULL DEFAULT '',
                subject     VARCHAR(255) NOT NULL DEFAULT '',
                body_html   TEXT,
                status      VARCHAR(16)  NOT NULL DEFAULT 'queued',
                attempts    INTEGER      NOT NULL DEFAULT 0,
                created_at  VARCHAR(32)  NOT NULL DEFAULT '',
                sent_at     VARCHAR(32)  NOT NULL DEFAULT ''
            )");
        } catch (Throwable $e) { error_log('[cacmail] ensure: ' . $e->getMessage()); }

        /* One row per (batch, person). The UNIQUE key is what refuses a
           duplicate, not a SELECT followed by an INSERT: CACENTRE retries a
           batch it did not hear an answer to, and two copies arriving
           together would both find nothing and both queue. */
        foreach ([
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_cacmail_batch_person ON av_cac_mail (batch_ref, email_key)',
            'CREATE INDEX IF NOT EXISTS idx_cacmail_status ON av_cac_mail (status, id)',
            'CREATE INDEX IF NOT EXISTS idx_cacmail_created ON av_cac_mail (created_at)',
        ] as $sql) {
            try { Database::pdo()->exec($sql); } catch (Throwable $e) { /* MySQL pre-8.0 has no IF NOT EXISTS here */ }
        }
    }

    /** The address, hashed. The log keeps this and not the address itself. */
    public static function key(string $email): string
    {
        $salt = defined('APP_KEY') ? (string) APP_KEY : 'av';

        return hash_hmac('sha256', mb_strtolower(trim($email)), $salt);
    }

    /** What this site is willing to take today, in total. */
    public static function dailyCap(): int
    {
        $v = getenv('AV_CAC_MAIL_DAILY');
        if ($v !== false && trim((string) $v) !== '') return max(0, (int) $v);
        if (defined('AV_CAC_MAIL_DAILY')) return max(0, (int) constant('AV_CAC_MAIL_DAILY'));

        return self::DAILY_DEFAULT;
    }

    /**
     * How many more this site can take today.
     *
     * Counted on rows ACCEPTED today rather than on messages sent, and the
     * difference matters: a batch accepted at 23:00 and still draining at
     * 00:30 has already been promised. Counting the sends would let a second
     * offer be accepted against an allowance the first one is still spending.
     */
    public static function headroom(): int
    {
        self::ensure();
        $cap = self::dailyCap();
        if ($cap <= 0) return 0;

        try {
            $st = Database::pdo()->prepare('SELECT COUNT(*) FROM av_cac_mail WHERE created_at >= ?');
            $st->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);

            return max(0, $cap - (int) $st->fetchColumn());
        } catch (Throwable $e) {
            /* Unknown is not "plenty". Answering zero sends the whole batch
               back to CACENTRE, which is the side that was always able to
               send it — degrading to exactly today's behaviour. */
            return 0;
        }
    }

    /**
     * Take what this site can afford of an offered batch.
     *
     * Returns what was accepted and what was NOT, by address, so CACENTRE can
     * queue the remainder itself rather than guessing which ones to keep. A
     * silent partial acceptance would lose people off the end of a list with
     * nothing to say so.
     *
     * Both lists are returned BY ADDRESS and not as counts. CACENTRE has to
     * know exactly which people it still owes a letter, and arithmetic on
     * two totals cannot say which of them an address was in — so a person
     * dropped by a bug here would be dropped by both sides, silently, with
     * every number on the screen adding up.
     *
     * @param  list<array{email:string,name?:string}> $people
     * @return array{accepted:list<string>, declined:list<string>, duplicates:list<string>, headroom:int}
     */
    public static function accept(string $batchRef, string $subject, string $html, array $people): array
    {
        self::ensure();

        $room = self::headroom();
        $accepted = []; $declined = []; $dupes = [];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $batchRef = mb_substr(trim($batchRef), 0, 64);

        $ins = null;
        try {
            $ins = Database::pdo()->prepare(
                'INSERT INTO av_cac_mail (batch_ref, email_key, to_email, to_name, subject, body_html, status, created_at)
                 VALUES (?,?,?,?,?,?,\'queued\',?)'
            );
        } catch (Throwable $e) { error_log('[cacmail] accept: ' . $e->getMessage()); }

        foreach ($people as $p) {
            $email = strtolower(trim((string) ($p['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                /* Not declined back to CACENTRE: an address this site cannot
                   send to is one CACENTRE cannot send to either, and handing
                   it back would have them queue a bounce. */
                continue;
            }
            if ($ins === null || count($accepted) >= $room) { $declined[] = $email; continue; }

            try {
                $ins->execute([$batchRef, self::key($email), $email,
                    mb_substr(trim((string) ($p['name'] ?? '')), 0, 160),
                    mb_substr($subject, 0, 255), $html, $now]);
                $accepted[] = $email;
            } catch (Throwable $e) {
                /* A duplicate key is the answer, not a fault: this person is
                   already queued for this batch. It is NOT declined — handing
                   it back would have CACENTRE send a second copy. */
                $dupes[] = $email;
            }
        }

        return ['accepted' => $accepted, 'declined' => $declined,
                'duplicates' => $dupes, 'headroom' => max(0, $room - count($accepted))];
    }

    /**
     * Send a few. Called from the cron tick.
     *
     * The cap is on ATTEMPTS and not on successes, for Appeals' reason: a
     * batch of addresses that all bounce costs the host as much as one that
     * all arrive, so counting only what worked would let a run of bad
     * addresses spend the whole window while the limit said sixty.
     *
     * @return array<string,mixed>
     */
    public static function drain(?int $limit = null): array
    {
        self::ensure();
        $limit = max(1, min(self::TICK_BATCH, $limit ?? self::TICK_BATCH));

        self::expire();

        try {
            $rows = Database::pdo()->query(
                "SELECT id, to_email, to_name, subject, body_html FROM av_cac_mail
                  WHERE status = 'queued' ORDER BY id ASC LIMIT " . (int) $limit
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return ['sent' => 0, 'failed' => 0, 'error' => 'unreadable'];
        }
        if ($rows === []) return ['sent' => 0, 'failed' => 0];

        $sent = 0; $failed = 0;
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            try {
                Database::pdo()->prepare("UPDATE av_cac_mail SET status='sending', attempts=attempts+1 WHERE id=?")
                    ->execute([$id]);
            } catch (Throwable $e) { continue; }

            /* No fromName override: the default IS this site's name, which is
               the entire point of routing the send through here. Saying it
               again in an option would be a second place for it to be wrong. */
            $ok = class_exists('Mailer')
                && Mailer::send((string) $r['to_email'], (string) $r['subject'], (string) $r['body_html'], [
                    'toName' => (string) $r['to_name'],
                ]);

            try {
                if ($ok) {
                    self::markSent($id);
                    $sent++;
                } else {
                    Database::pdo()->prepare(
                        "UPDATE av_cac_mail SET status = CASE WHEN attempts >= 3 THEN 'failed' ELSE 'queued' END WHERE id=?"
                    )->execute([$id]);
                    $failed++;
                }
            } catch (Throwable $e) { error_log('[cacmail] drain: ' . $e->getMessage()); }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Record a message as sent, and forget the address it went to.
     *
     * ITS OWN METHOD so the promise can be TESTED. The suite cannot send
     * mail — it is disabled there on purpose — so the success path inside
     * drain() is never walked, and a test that re-created the erase with
     * its own UPDATE and then asserted the column would be asserting its
     * own statement. It passed over a mutation that stopped erasing
     * anything, which is exactly the kind of test that reads as coverage
     * and is not.
     *
     * These are CACENTRE's people. This site held the address only for as
     * long as it took to use it. What stays is the hashed key, so a batch
     * offered twice still cannot write to somebody twice.
     */
    public static function markSent(int $id): void
    {
        Database::pdo()->prepare(
            "UPDATE av_cac_mail SET status='sent', sent_at=?, to_email='', to_name='', body_html=NULL WHERE id=?"
        )->execute([gmdate('Y-m-d\TH:i:s\Z'), $id]);
    }

    /**
     * Give up on anything too old to be worth sending.
     *
     * A bulk letter about an event last week is worse than no letter. The
     * address goes with it — there is no reason to hold somebody else's
     * contact details for a message that will never be sent.
     */
    private static function expire(): void
    {
        try {
            Database::pdo()->prepare(
                "UPDATE av_cac_mail SET status='expired', to_email='', to_name='', body_html=NULL
                  WHERE status='queued' AND created_at < ?"
            )->execute([gmdate('Y-m-d\TH:i:s\Z', time() - self::STALE_DAYS * 86400)]);
        } catch (Throwable $e) { /* housekeeping is never the caller's problem */ }
    }

    /** What is waiting, for the cron's report and the status screen. */
    public static function pending(): int
    {
        self::ensure();
        try {
            return (int) Database::pdo()->query("SELECT COUNT(*) FROM av_cac_mail WHERE status='queued'")->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }
}
