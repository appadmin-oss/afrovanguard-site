<?php
/**
 * lib/CacSend.php — CACENTRE's email, sent by this site's mail.
 *
 * CACENTRE's own outbound mail was failing on its host. This site already
 * sends — its roads (SMTP, the Apps Script, Resend; lib/Mailer.php) are set
 * up and working — and the two sites already trust each other through the
 * bridge secret. So CACENTRE hands a message here, one at a time, and this
 * site sends it with its own mail, at once, and answers how it went. A
 * password reset or a staff invitation cannot wait for a cron.
 *
 * The door is integrations/cacentre-send.php; the token is checked by
 * lib/CacInbound.php for the "mail-send" door, bound to the message.
 *
 * What is kept from CACENTRE: the recipient, the subject, the body, and who
 * it is from BY NAME ("CACENTRE"), with replies going to CACENTRE's address.
 * The sending ADDRESS is this site's, because that is the address this
 * site's mail is allowed to send as — a From it does not own is the thing
 * SPF and DKIM exist to bin.
 *
 * A daily ceiling (AV_CAC_SEND_DAILY, default 500) keeps CACENTRE's volume
 * from spending this site's own quota. Past it the answer is "over the
 * limit", and CACENTRE tries its own roads, as it did before.
 */
declare(strict_types=1);

final class CacSend
{
    public const AUD = 'mail-send';
    public const DAILY_DEFAULT = 500;

    public static function ensure(): void
    {
        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS av_cac_send (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            to_key VARCHAR(64) NOT NULL DEFAULT '',
            ok INTEGER NOT NULL DEFAULT 0,
            road VARCHAR(16) NOT NULL DEFAULT '',
            error VARCHAR(300) NOT NULL DEFAULT '',
            at INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_av_cac_send_at ON av_cac_send (at)");
    }

    public static function dailyCap(): int
    {
        $v = getenv('AV_CAC_SEND_DAILY');
        if ($v !== false && trim((string) $v) !== '') return max(0, (int) $v);
        if (defined('AV_CAC_SEND_DAILY')) return max(0, (int) constant('AV_CAC_SEND_DAILY'));
        return self::DAILY_DEFAULT;
    }

    /** How many more it may send in the next 24 hours. */
    public static function headroom(?int $now = null): int
    {
        self::ensure();
        $now = $now ?? time();
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM av_cac_send WHERE at >= ?');
        $st->execute([$now - 86400]);
        return max(0, self::dailyCap() - (int) $st->fetchColumn());
    }

    /**
     * Send one message. In: to, to_name, subject, html, text, from_name, reply_to, headers.
     *
     * @return array{ok:bool, error?:string, code?:string, road?:string, headroom?:int}
     */
    public static function send(array $m): array
    {
        self::ensure();
        $to = strtolower(trim((string) ($m['to'] ?? '')));
        $subject = trim(mb_substr((string) ($m['subject'] ?? ''), 0, 300));
        $html = (string) ($m['html'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'code' => 'bad_recipient', 'error' => 'Not an email address.'];
        if ($subject === '' || trim($html) === '') return ['ok' => false, 'code' => 'empty', 'error' => 'A subject and a body are needed.'];
        if (strlen($html) > 1_000_000) return ['ok' => false, 'code' => 'too_big', 'error' => 'The message is over 1 MB.'];
        if (self::headroom() <= 0) return ['ok' => false, 'code' => 'daily_cap', 'error' => 'Afrovanguard has sent its ' . self::dailyCap() . ' for CACENTRE in the last 24 hours.'];

        /* Never this server's mail(): it reports "sent" for mail the
           recipient's provider bins, and CACENTRE then believed it had
           delivered and tried nothing else. Refused here, CACENTRE goes on to
           its own roads. A batch (CACENTRE's Wi-Fi starting passwords) is
           also kept off the Apps Script, whose small daily allowance this
           site's own sign-in codes need. */
        $opt = ['fromName' => mb_substr(trim((string) ($m['from_name'] ?? '')) ?: 'CACENTRE', 0, 80), 'noHost' => true, 'bulk' => !empty($m['batch'])];
        $reply = trim((string) ($m['reply_to'] ?? ''));
        if (filter_var($reply, FILTER_VALIDATE_EMAIL)) $opt['replyTo'] = $reply;
        if (trim((string) ($m['text'] ?? '')) !== '') $opt['text'] = (string) $m['text'];
        /* Only the headers that belong to a message, never routing ones. */
        $h = [];
        foreach ((array) ($m['headers'] ?? []) as $k => $v) {
            if (in_array(strtolower((string) $k), ['list-unsubscribe', 'list-unsubscribe-post'], true)) $h[(string) $k] = mb_substr((string) $v, 0, 500);
        }
        if ($h) $opt['headers'] = $h;

        $ok = Mailer::send($to, $subject, $html, $opt);
        $road = (string) Mailer::lastTransport();
        $err = $ok ? '' : (Mailer::lastError() ?: 'not sent');
        Database::pdo()->prepare('INSERT INTO av_cac_send (to_key, ok, road, error, at) VALUES (?,?,?,?,?)')
            ->execute([hash('sha256', $to), $ok ? 1 : 0, mb_substr($road, 0, 16), mb_substr($err, 0, 300), time()]);
        return $ok ? ['ok' => true, 'road' => $road, 'headroom' => self::headroom()]
                   : ['ok' => false, 'code' => 'not_sent', 'error' => 'Afrovanguard could not send it: ' . $err, 'road' => $road];
    }
}
