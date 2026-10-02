<?php
/**
 * lib/MemberCards.php — a member's card, as the CACENTRE gate reads it.
 *
 * The same model NGG uses (NGG api/_lib/domain/member-cards.php), so the two
 * organisations' members are carded one way:
 *
 *   SECURE    AVQR- and sixteen random characters, issued for every member —
 *             created in the Studio, imported, or by the backfill for
 *             everybody already on the roll. Unguessable and revocable:
 *             reissuing voids the old one at once. New cards are printed with
 *             it, beside the NGV number a person reads.
 *
 *   PRINTED   A card already in somebody's wallet. The NGV number
 *             (X-NGV-YY-NNNN) has always been read by the gate itself and
 *             stays in gate_member_cards (GateAttendance). Any other old card —
 *             a portal ID, a code from an earlier system — is described by a
 *             card FORMAT (a template and a mask, worked out from an example
 *             card) and its code recorded per member here at import. The gate
 *             pulls the formats and decodes with them.
 *
 * A printed card is weaker than a secure one — a number is not a secret — so
 * the desk is told when a card was an old printed one.
 */
declare(strict_types=1);

final class MemberCards
{
    public const PREFIX = 'AVQR-';
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const META_FORMATS = 'gate_card_formats';
    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        $pdo = Database::pdo();
        Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS av_member_cards (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id  INTEGER NOT NULL,
            kind       VARCHAR(10) NOT NULL DEFAULT 'secure',
            code       VARCHAR(120) NOT NULL,
            format     VARCHAR(40) NOT NULL DEFAULT '',
            status     VARCHAR(10) NOT NULL DEFAULT 'active',
            source     VARCHAR(16) NOT NULL DEFAULT '',
            issued_by  VARCHAR(64) NOT NULL DEFAULT '',
            issued_at  VARCHAR(32) NOT NULL DEFAULT '',
            voided_at  VARCHAR(32) NOT NULL DEFAULT ''
        )");
        try { $pdo->exec('CREATE UNIQUE INDEX uq_av_member_cards ON av_member_cards (kind, format, code)'); } catch (Throwable $e) {}
        try { $pdo->exec('CREATE INDEX idx_av_member_cards_member ON av_member_cards (member_id, status)'); } catch (Throwable $e) {}
    }

    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

    /* ── Formats ─────────────────────────────────────────────────────────── */

    /** The formats importers have described. None by default: the NGV number is read natively. */
    public static function formats(): array
    {
        $raw = json_decode((string) (Database::metaGet(self::META_FORMATS) ?? ''), true);
        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    public static function format(string $id): ?array
    {
        foreach (self::formats() as $f) if ((string) ($f['id'] ?? '') === $id) return $f;
        return null;
    }

    /** A mask to an expression body, by the gate's rules (gate/src/cards.ts). */
    public static function maskSource(string $mask): array
    {
        if ($mask === '' || strlen($mask) > 40) return ['ok' => false, 'error' => 'A mask is 1 to 40 characters.'];
        if (preg_match('/\s/', $mask)) return ['ok' => false, 'error' => 'A mask has no spaces.'];
        $out = ''; $fixed = 0; $i = 0; $n = strlen($mask);
        while ($i < $n) {
            $ch = $mask[$i]; $run = 1;
            while ($i + $run < $n && $mask[$i + $run] === $ch) $run++;
            $rep = $run > 1 ? '{' . $run . '}' : '';
            if ($ch === '9') { $out .= '\d' . $rep; $fixed += $run; }
            elseif ($ch === 'A') { $out .= '[A-Za-z]' . $rep; $fixed += $run; }
            elseif ($ch === 'X') { $out .= '[A-Za-z0-9]' . $rep; $fixed += $run; }
            elseif ($ch === '*') { if ($run > 1) return ['ok' => false, 'error' => 'One * is enough.']; $out .= '[A-Za-z0-9._-]{1,40}'; }
            else { $out .= preg_quote(str_repeat($ch, $run), '~'); $fixed += $run; }
            $i += $run;
        }
        return ['ok' => true, 'source' => $out, 'fixed' => $fixed];
    }

    public static function compile(array $f): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', (string) ($f['id'] ?? ''))) return ['ok' => false, 'error' => 'A format id is lower-case letters, digits and dashes.'];
        $t = (string) ($f['template'] ?? '');
        if (strlen($t) > 200) return ['ok' => false, 'error' => 'A template is at most 200 characters.'];
        if (substr_count($t, '{id}') !== 1) return ['ok' => false, 'error' => 'A template has {id} in it exactly once.'];
        $m = self::maskSource((string) ($f['mask'] ?? ''));
        if (!$m['ok']) return $m;
        if (strlen((string) preg_replace('/\{id\}|\{any\}|\s/', '', $t)) + $m['fixed'] < 4) {
            return ['ok' => false, 'error' => 'This shape would match almost anything. Give the mask fixed characters (999-999), or put the number in the template’s context.'];
        }
        $body = '';
        foreach (preg_split('/(\{id\}|\{any\})/', $t, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $p) {
            $body .= $p === '{id}' ? '(' . $m['source'] . ')' : ($p === '{any}' ? '\S{0,120}?' : preg_quote($p, '~'));
        }
        $urlish = (strpos($t, '://') !== false || strpos($t, '{any}') === 0 || strpos($t, '/') !== false) && substr(rtrim($t), -4) === '{id}';
        return ['ok' => true, 'regex' => '~^\s*' . $body . ($urlish ? '(?:[/?#]\S{0,200})?' : '') . '\s*$~i'];
    }

    public static function decode(array $f, string $text): ?string
    {
        $c = self::compile($f);
        if (!$c['ok'] || strlen($text) > 2000) return null;
        return preg_match($c['regex'], $text, $hit) ? strtoupper($hit[1]) : null;
    }

    /** A format from what an old card scans as and the number in it — digits vary, the rest is as printed. */
    public static function fromExample(string $scan, string $number): array
    {
        $scan = trim($scan); $number = trim($number);
        if ($scan === '' || $number === '') return ['ok' => false, 'error' => 'Paste what an old card scans as, and the number in it.'];
        $at = stripos($scan, $number);
        if ($at === false) return ['ok' => false, 'error' => 'That number is not in what the card scans as.'];
        $before = substr($scan, 0, $at); $after = substr($scan, $at + strlen($number));
        if (preg_match('#^https?://[^/]+(/.*)?$#i', $before, $hm)) $before = '{any}' . ($hm[1] ?? '');
        return ['ok' => true, 'template' => $before . '{id}' . $after, 'mask' => (string) preg_replace('/\d/', '9', strtoupper($number))];
    }

    /** Add or replace a format. The example, if given, must read under it. */
    public static function saveFormat(array $in, string $by): array
    {
        $f = [
            'id' => strtolower(trim((string) ($in['id'] ?? ''))),
            'label' => trim((string) ($in['label'] ?? '')) ?: 'Printed cards',
            'template' => (string) ($in['template'] ?? ''),
            'mask' => strtoupper(trim((string) ($in['mask'] ?? ''))),
            'example' => substr(trim((string) ($in['example'] ?? '')), 0, 200),
        ];
        $c = self::compile($f);
        if (!$c['ok']) return ['ok' => false, 'error' => $c['error']];
        if ($f['example'] !== '' && self::decode($f, $f['example']) === null) return ['ok' => false, 'error' => 'The example does not read under this format. Check the template and the mask.'];
        $list = array_values(array_filter(self::formats(), static fn($x) => ($x['id'] ?? '') !== $f['id']));
        if (count($list) >= 12) return ['ok' => false, 'error' => 'Twelve formats is the most the gate will try.'];
        $list[] = $f;
        Database::metaSet(self::META_FORMATS, (string) json_encode($list, JSON_UNESCAPED_SLASHES));
        return ['ok' => true, 'format' => $f, 'formats' => $list, 'by' => $by];
    }

    /** What the gate is given: the formats that compile. */
    public static function gateFormats(): array
    {
        $out = [];
        foreach (self::formats() as $f) {
            if (!self::compile($f)['ok']) continue;
            $out[] = ['id' => (string) $f['id'], 'label' => (string) $f['label'], 'template' => (string) $f['template'], 'mask' => (string) $f['mask'], 'example' => (string) ($f['example'] ?? '')];
        }
        return $out;
    }

    /* ── Cards ───────────────────────────────────────────────────────────── */

    public static function newCode(): string
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT 1 FROM av_member_cards WHERE code = ?');
        do {
            $code = self::PREFIX; $b = random_bytes(16);
            for ($i = 0; $i < 16; $i++) $code .= self::ALPHABET[ord($b[$i]) & 31];
            $st->execute([$code]);
        } while ($st->fetchColumn());
        return $code;
    }

    public static function secure(int $memberId): ?string
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT code FROM av_member_cards WHERE member_id = ? AND kind = 'secure' AND status = 'active' ORDER BY id DESC LIMIT 1");
        $st->execute([$memberId]);
        $c = $st->fetchColumn();
        return $c === false ? null : (string) $c;
    }

    /** Every card a member holds — the NGV number with them — newest first. */
    public static function of(int $memberId): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT kind, code, format, status, source, issued_at, voided_at FROM av_member_cards WHERE member_id = ? ORDER BY id DESC LIMIT 20');
        $st->execute([$memberId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (class_exists('GateAttendance') && ($ngv = GateAttendance::cardFor($memberId))) {
            array_unshift($rows, ['kind' => 'ngv', 'code' => $ngv, 'format' => 'ngv-number', 'status' => 'active', 'source' => '', 'issued_at' => '', 'voided_at' => '']);
        }
        return $rows;
    }

    /** A new secure card, the old one void at once. */
    public static function issue(int $memberId, string $by, string $source = 'reissue'): string
    {
        self::ensure();
        $pdo = Database::pdo();
        $pdo->prepare("UPDATE av_member_cards SET status = 'void', voided_at = ? WHERE member_id = ? AND kind = 'secure' AND status = 'active'")->execute([self::now(), $memberId]);
        $code = self::newCode();
        $pdo->prepare("INSERT INTO av_member_cards (member_id, kind, code, format, status, source, issued_by, issued_at) VALUES (?, 'secure', ?, '', 'active', ?, ?, ?)")
            ->execute([$memberId, $code, $source, substr($by, 0, 64), self::now()]);
        return $code;
    }

    /** Record the code on an old card a member already holds. Never over someone else's. */
    public static function recordPrinted(int $memberId, string $format, string $code, string $by, string $source): string
    {
        self::ensure();
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) > 120 || !self::format($format)) return 'skipped';
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT member_id, status FROM av_member_cards WHERE kind = 'printed' AND format = ? AND code = ?");
        $st->execute([$format, $code]);
        $held = $st->fetch(PDO::FETCH_ASSOC);
        if ($held && (int) $held['member_id'] !== $memberId) return 'taken';
        if ($held) {
            if ((string) $held['status'] !== 'active') $pdo->prepare("UPDATE av_member_cards SET status = 'active', voided_at = '' WHERE kind = 'printed' AND format = ? AND code = ?")->execute([$format, $code]);
            return 'already';
        }
        $pdo->prepare("INSERT INTO av_member_cards (member_id, kind, code, format, status, source, issued_by, issued_at) VALUES (?, 'printed', ?, ?, 'active', ?, ?, ?)")
            ->execute([$memberId, $code, $format, $source, substr($by, 0, 64), self::now()]);
        return 'recorded';
    }

    /** A secure card if they have none; their old card recorded if one is named. */
    public static function ensureFor(int $memberId, string $by, string $source, ?string $format = null, string $code = ''): array
    {
        $out = ['secure' => self::secure($memberId), 'issued' => false, 'printed' => 'none'];
        if ($out['secure'] === null) { $out['secure'] = self::issue($memberId, $by, $source); $out['issued'] = true; }
        if ($format !== null && $format !== '' && trim($code) !== '') $out['printed'] = self::recordPrinted($memberId, $format, $code, $by, $source);
        return $out;
    }

    /** Whose a scanned code is: ['member_id', 'void', 'kind'] or null. */
    public static function lookup(?string $token, ?string $format = null, ?string $value = null): ?array
    {
        self::ensure();
        $pdo = Database::pdo();
        if ($token !== null && $token !== '') {
            $st = $pdo->prepare("SELECT member_id, status FROM av_member_cards WHERE kind = 'secure' AND code = ?");
            $st->execute([strtoupper(trim($token))]);
        } else {
            if ($format === null || $value === null || !self::format($format)) return null;
            $st = $pdo->prepare("SELECT member_id, status FROM av_member_cards WHERE kind = 'printed' AND format = ? AND code = ?");
            $st->execute([$format, strtoupper(trim($value))]);
        }
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['member_id' => (int) $r['member_id'], 'void' => (string) $r['status'] !== 'active', 'kind' => $token ? 'secure' : 'printed'] : null;
    }

    /** Retire a member's old printed cards (they have been reprinted). */
    public static function voidPrinted(int $memberId): int
    {
        self::ensure();
        $st = Database::pdo()->prepare("UPDATE av_member_cards SET status = 'void', voided_at = ? WHERE member_id = ? AND kind = 'printed' AND status = 'active'");
        $st->execute([self::now(), $memberId]);
        return $st->rowCount();
    }

    /** Cards for every active member with none — bounded per call; repeat until remaining is 0. */
    public static function backfill(string $by, int $limit = 200): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $sql = "FROM lms_users u WHERE u.status = 'active' AND NOT EXISTS (SELECT 1 FROM av_member_cards c WHERE c.member_id = u.id AND c.kind = 'secure' AND c.status = 'active')";
        $ids = $pdo->query('SELECT u.id ' . $sql . ' ORDER BY u.id LIMIT ' . max(1, min(500, $limit)))->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) self::issue((int) $id, $by, 'backfill');
        return ['issued' => count($ids), 'remaining' => (int) $pdo->query('SELECT COUNT(*) ' . $sql)->fetchColumn()];
    }
}
