<?php
/**
 * lib/VanQuest.php — the players of VanQuest (vquest.afrovanguard.org.ng).
 *
 * The journey, as the owner set it out (26 October 2026):
 *
 *   1. Someone enters their email on the VanQuest site. They get the first mail,
 *      vanquest_welcome: how the game works, their status, the community with
 *      its numbers and its people, and a "Confirm your seat" link.
 *   2. The link opens the seat page on the VanQuest site. They give their first
 *      name and sex (Male / Female) and the seat is confirmed. That is what
 *      confirms the email address: only its owner has the link.
 *   3. Fifteen minutes after confirming, the second mail goes, vanquest_onboard:
 *      how to start, and the way back into the community.
 *   4. The community page greets them by name, with their status, the community and the
 *      interview form (first name and sex already filled in).
 *
 * Entering an email is the opt-in to appearing in the community: confirmed
 * players are shown to other players by first name, rank letter and a generated
 * pixel avatar. Nothing else about them is shown.
 *
 * A player's link carries a token derived from the site secret and a per-player
 * nonce (tokenFor); only the token's SHA-256 is stored. A new first mail draws a
 * new nonce, so the old link stops working; the onboarding mail reuses the
 * current one, so a browser already in the community stays in. A player's
 * placement letter and XP are their own browser's tally, kept for wording and
 * never treated as a rank in the Quest.
 */
declare(strict_types=1);

final class VanQuest
{
    public const SITE = 'https://vquest.afrovanguard.org.ng';
    /** Seconds between confirming a seat and the onboarding mail. */
    public const ONBOARD_AFTER = 900;
    public const SEXES = ['male' => 'Male', 'female' => 'Female'];
    public const PLACEMENTS = ['O', 'A', 'B', 'C', 'D'];
    /** A resend of the first mail is refused inside this many seconds. */
    private const RESEND_GAP = 120;
    private const ONBOARD_TRIES = 3;

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS vq_players (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email VARCHAR(191) NOT NULL DEFAULT '',
            token_hash VARCHAR(64) NOT NULL DEFAULT '',
            nonce VARCHAR(32) NOT NULL DEFAULT '',
            avatar VARCHAR(16) NOT NULL DEFAULT '',
            first_name VARCHAR(40) NOT NULL DEFAULT '',
            sex VARCHAR(8) NOT NULL DEFAULT '',
            placement VARCHAR(1) NOT NULL DEFAULT '',
            xp INTEGER NOT NULL DEFAULT 0,
            joined_at VARCHAR(32) NOT NULL DEFAULT '',
            mailed_at VARCHAR(32) NOT NULL DEFAULT '',
            confirmed_at VARCHAR(32) NOT NULL DEFAULT '',
            onboarded_at VARCHAR(32) NOT NULL DEFAULT '',
            onboard_tries INTEGER NOT NULL DEFAULT 0,
            interview_at VARCHAR(32) NOT NULL DEFAULT '',
            phone VARCHAR(32) NOT NULL DEFAULT ''
        )");
        Database::ensureIndex(Database::pdo(), 'ux_vq_players_email', 'vq_players', 'email', true);
        Database::ensureIndex(Database::pdo(), 'ix_vq_players_token', 'vq_players', 'token_hash');
    }

    private static function now(int $ago = 0): string { return gmdate('Y-m-d H:i:s', time() - $ago); }
    private static function hash(string $token): string { return hash('sha256', $token); }

    /** The link token for a player and nonce: 48 hex characters only the site can make. */
    private static function tokenFor(int $id, string $nonce): string
    {
        $secret = function_exists('av_secret') ? (string) av_secret() : (defined('APP_KEY') ? (string) APP_KEY : (string) getenv('APP_KEY'));
        return substr(hash_hmac('sha256', 'vanquest:' . $id . ':' . $nonce, $secret), 0, 48);
    }

    /** Give a player a new nonce (the old link stops working); returns the new token. */
    private static function rekey(int $id): string
    {
        $nonce = bin2hex(random_bytes(12));
        $token = self::tokenFor($id, $nonce);
        Database::pdo()->prepare('UPDATE vq_players SET nonce = ?, token_hash = ? WHERE id = ?')->execute([$nonce, self::hash($token), $id]);
        return $token;
    }

    public static function seatUrl(string $token): string { return self::SITE . '/seat/?t=' . rawurlencode($token); }
    public static function communityUrl(string $token): string { return self::SITE . '/community/?t=' . rawurlencode($token); }
    public static function avatarUrl(array $p): string
    {
        return rtrim(defined('SITE_URL') ? SITE_URL : 'https://afrovanguard.org.ng', '/')
            . '/vanquest/api.php?action=avatar&a=' . rawurlencode((string) $p['avatar']) . '&s=' . ($p['sex'] === 'female' ? 'f' : 'm');
    }

    /** The letter a player is shown at: their placement, else where everybody begins. */
    public static function letter(array $p): string { return in_array($p['placement'] ?? '', self::PLACEMENTS, true) ? $p['placement'] : 'O'; }

    /* ══ The journey ═════════════════════════════════════════════════════════ */

    /**
     * Step 1. A new email gets a player and the first mail. An email already
     * here gets a fresh link by the same mail (the old link stops working), at
     * most once every two minutes.
     *
     * @return array{ok:bool, status:string, message:string, token?:string}
     */
    public static function join(string $email, string $placement = '', int $xp = 0): array
    {
        self::ensure();
        $email = mb_strtolower(trim($email), 'UTF-8');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
            return ['ok' => false, 'status' => 'invalid', 'message' => 'Please enter a valid email address.'];
        }
        $placement = in_array($placement, self::PLACEMENTS, true) ? $placement : '';
        $xp = min(99999, max(50, $xp));
        $pdo = Database::pdo();
        $p = self::byEmail($email);
        if ($p === null) {
            $pdo->prepare('INSERT INTO vq_players (email, avatar, placement, xp, joined_at, mailed_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$email, bin2hex(random_bytes(4)), $placement, $xp, self::now(), self::now()]);
            $status = 'new';
        } else {
            if ($p['mailed_at'] !== '' && $p['mailed_at'] > self::now(self::RESEND_GAP)) {
                return ['ok' => true, 'status' => 'wait', 'message' => 'We have just emailed you. Check your inbox and spam folder.'];
            }
            $pdo->prepare('UPDATE vq_players SET mailed_at = ?, placement = CASE WHEN ? <> \'\' THEN ? ELSE placement END, xp = CASE WHEN xp < ? THEN ? ELSE xp END WHERE id = ?')
                ->execute([self::now(), $placement, $placement, $xp, $xp, $p['id']]);
            $status = $p['confirmed_at'] !== '' ? 'seated' : 'resent';
        }
        $p = self::byEmail($email);
        $token = self::rekey((int) $p['id']);
        $sent = self::mail($email, 'vanquest_welcome', [
            'seat_url' => self::seatUrl($token), 'placement' => $p['placement'], 'xp' => (int) $p['xp'],
            'seated' => $p['confirmed_at'] !== '', 'first_name' => $p['first_name'],
            'people' => self::people(6, (int) $p['id']), 'players' => self::players((int) $p['id']),
        ]);
        if (!$sent) {
            /* Not "an email has been sent" when none was: the player would wait
               for a mail that is not coming, and the resend gap would refuse
               them for two minutes. Say so, and let them ask again at once. */
            $pdo->prepare("UPDATE vq_players SET mailed_at = '' WHERE id = ?")->execute([(int) $p['id']]);
            return ['ok' => false, 'status' => 'unsent', 'mailed' => false, 'token' => $token,
                'message' => 'We could not send your email just now. Try again in a few minutes.'];
        }
        return ['ok' => true, 'status' => $status, 'mailed' => $sent, 'token' => $token,
            'message' => $status === 'seated' ? 'You already have a seat. We have emailed you a new link to it.' : 'An email has been sent.'];
    }

    /** Step 2, opening the link: who the token belongs to, or null. */
    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM vq_players WHERE token_hash = ?');
        $st->execute([self::hash($token)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Step 2, the form: first name and sex confirm the seat. Confirming again
     * only updates the name and sex; the onboarding mail is still sent once.
     *
     * @return array{ok:bool, errors?:array, player?:array}
     */
    public static function confirm(string $token, string $firstName, string $sex): array
    {
        $p = self::byToken($token);
        if ($p === null) return ['ok' => false, 'errors' => ['token' => 'This link is not valid any more. Join again for a new one.']];
        $name = self::cleanName($firstName);
        $e = [];
        if ($name === '') $e['first_name'] = 'Enter your first name.';
        if (!isset(self::SEXES[$sex])) $e['sex'] = 'Choose Male or Female.';
        if ($e) return ['ok' => false, 'errors' => $e];
        Database::pdo()->prepare('UPDATE vq_players SET first_name = ?, sex = ?, confirmed_at = CASE WHEN confirmed_at = \'\' THEN ? ELSE confirmed_at END WHERE id = ?')
            ->execute([$name, $sex, self::now(), $p['id']]);
        return ['ok' => true, 'player' => self::byToken($token)];
    }

    /**
     * A placement taken in the browser after joining, reported by the community
     * page. Kept only while the player has none: it words their status and
     * unlocks the interview, and is never a rank in the Quest.
     */
    public static function recordPlacement(array $p, string $letter): array
    {
        if ($p['placement'] !== '' || !in_array($letter, self::PLACEMENTS, true)) return $p;
        Database::pdo()->prepare("UPDATE vq_players SET placement = ? WHERE id = ? AND placement = ''")->execute([$letter, $p['id']]);
        $p['placement'] = $letter;
        return $p;
    }

    /** First name as people write it: letters, spaces, hyphens and apostrophes, 40 at most. */
    public static function cleanName(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        $s = preg_replace("/[^\\p{L}\\p{M} '\\-’]/u", '', $s) ?? '';
        return mb_substr(trim($s), 0, 40);
    }

    /** Step 4: everything the community page shows. */
    public static function community(array $p): array
    {
        return [
            'player' => self::publicPlayer($p, true),
            // Players change by the minute, so their count is read live, not from the cache.
            'metrics' => ['players' => self::players()] + self::metrics(),
            'people' => self::people(24, (int) $p['id']),
            'onboard_at' => $p['confirmed_at'] !== '' ? gmdate('c', strtotime($p['confirmed_at'] . ' UTC') + self::ONBOARD_AFTER) : null,
        ];
    }

    public static function publicPlayer(array $p, bool $own = false): array
    {
        $out = [
            'first_name' => $p['first_name'], 'letter' => self::letter($p), 'placed' => in_array($p['placement'], self::PLACEMENTS, true),
            'avatar' => self::avatarUrl($p), 'since' => substr((string) ($p['confirmed_at'] ?: $p['joined_at']), 0, 10),
        ];
        if ($own) $out += ['sex' => $p['sex'], 'xp' => (int) $p['xp'], 'seated' => $p['confirmed_at'] !== '', 'interview_requested' => $p['interview_at'] !== '', 'phone' => $p['phone']];
        return $out;
    }

    /**
     * The interview request, from the community page. First name and sex come filled in
     * from the seat and can be corrected; a phone number is how Afrovanguard
     * arranges it. Afrovanguard is told by email.
     */
    public static function requestInterview(string $token, string $firstName, string $sex, string $phone, string $note = ''): array
    {
        $p = self::byToken($token);
        if ($p === null || $p['confirmed_at'] === '') return ['ok' => false, 'errors' => ['token' => 'Confirm your seat first.']];
        // The interview confirms a placement, so it comes after the placement test.
        if (!in_array($p['placement'], self::PLACEMENTS, true)) return ['ok' => false, 'errors' => ['placement' => 'Take the placement test first: the interview confirms its result.']];
        $name = self::cleanName($firstName);
        $phone = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        $e = [];
        if ($name === '') $e['first_name'] = 'Enter your first name.';
        if (!isset(self::SEXES[$sex])) $e['sex'] = 'Choose Male or Female.';
        if (strlen(ltrim($phone, '+')) < 7 || strlen($phone) > 16) $e['phone'] = 'Enter a phone number we can call.';
        if ($e) return ['ok' => false, 'errors' => $e];
        $note = mb_substr(trim($note), 0, 500);
        Database::pdo()->prepare('UPDATE vq_players SET first_name = ?, sex = ?, phone = ?, interview_at = ? WHERE id = ?')
            ->execute([$name, $sex, $phone, self::now(), $p['id']]);
        $to = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'cacentre@afrovanguard.org.ng';
        $rows = [
            'First name' => $name, 'Sex' => self::SEXES[$sex], 'Email' => $p['email'], 'Phone' => $phone,
            'Placement' => $p['placement'] !== '' ? 'Potential ' . $p['placement'] . ' (placement test)' : 'Not taken',
            'Seat confirmed' => $p['confirmed_at'] . ' UTC',
        ];
        $html = '<p>A VanQuest player asks for their interview.</p><table cellpadding="6" style="border-collapse:collapse">';
        foreach ($rows as $k => $v) $html .= '<tr><td style="color:#5F636D">' . e($k) . '</td><td><b>' . e($v) . '</b></td></tr>';
        $html .= '</table>' . ($note !== '' ? '<p><b>Note:</b> ' . nl2br(e($note)) . '</p>' : '');
        self::sendRaw($to, 'VanQuest interview request: ' . $name, $html);
        return ['ok' => true];
    }

    /**
     * Step 3. Sends the onboarding mail to every player whose seat was confirmed
     * at least fifteen minutes ago and who has not had it. Run by tasks/cron.php,
     * and opportunistically by the VanQuest API so a host without cron still
     * sends it on the next visit. A send that fails is retried, three tries in all.
     */
    public static function tick(int $max = 20): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT * FROM vq_players WHERE confirmed_at <> '' AND confirmed_at <= ? AND onboarded_at = '' AND onboard_tries < ? ORDER BY confirmed_at LIMIT " . max(1, $max));
        $st->execute([self::now(self::ONBOARD_AFTER), self::ONBOARD_TRIES]);
        $sent = 0; $failed = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            // Claim the row first so two ticks at once cannot both send.
            $claim = Database::pdo()->prepare("UPDATE vq_players SET onboard_tries = onboard_tries + 1 WHERE id = ? AND onboarded_at = '' AND onboard_tries = ?");
            $claim->execute([$p['id'], $p['onboard_tries']]);
            if ($claim->rowCount() !== 1) continue;
            // The same link the player already has: a browser in the community stays in.
            $token = self::tokenFor((int) $p['id'], (string) $p['nonce']);
            $ok = self::mail($p['email'], 'vanquest_onboard', [
                'first_name' => $p['first_name'], 'community_url' => self::communityUrl($token), 'placement' => $p['placement'],
                'xp' => (int) $p['xp'], 'avatar_url' => self::avatarUrl($p), 'sex' => $p['sex'],
                'people' => self::peopleSince((string) $p['confirmed_at'], (int) $p['id']) ?: self::people(6, (int) $p['id']),
                'joined_since' => self::joinedSince((string) $p['confirmed_at'], (int) $p['id']),
            ]);
            if ($ok) { Database::pdo()->prepare('UPDATE vq_players SET onboarded_at = ? WHERE id = ?')->execute([self::now(), $p['id']]); $sent++; }
            else $failed++;
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /* ══ The community ═══════════════════════════════════════════════════════ */

    /**
     * Afrovanguard's own numbers, read live (cached ten minutes):
     *   volunteers_active    members whose account is active and who signed in
     *                        within 60 days (the member desk's "dormant" line)
     *   volunteers_inactive  every other member: dormant or suspended
     *   centres              centres not retired (Centres::all)
     *   leaders              members at level C or above (Levels)
     *   players              VanQuest players with a confirmed seat
     * A number that cannot be read is left out, never guessed.
     */
    public static function metrics(bool $fresh = false): array
    {
        if (!$fresh) {
            $c = json_decode((string) (Database::metaGet('vq_metrics') ?? ''), true);
            if (is_array($c) && ($c['at'] ?? 0) > time() - 600) return $c['m'];
        }
        $m = [];
        $pdo = Database::pdo();
        try {
            MemberRoster::ensure();
            $M = MemberRoster::memberWhere();
            $total = (int) $pdo->query("SELECT COUNT(*) FROM lms_users u WHERE $M")->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM lms_users u WHERE $M AND u.status = 'active' AND u.last_login IS NOT NULL AND u.last_login <> '' AND u.last_login >= ?");
            $st->execute([gmdate('Y-m-d H:i:s', strtotime('-60 days'))]);
            $m['volunteers_active'] = (int) $st->fetchColumn();
            $m['volunteers_inactive'] = max(0, $total - $m['volunteers_active']);
        } catch (Throwable $e) { error_log('[vanquest] metrics volunteers: ' . $e->getMessage()); }
        try { $m['centres'] = count(Centres::all(false)); }
        catch (Throwable $e) { error_log('[vanquest] metrics centres: ' . $e->getMessage()); }
        try {
            Levels::ensure();
            $order = Levels::order();
            $high = array_slice($order, (int) array_search('C', $order, true));
            if (in_array('C', $order, true) && $high) {
                $in = implode(',', array_fill(0, count($high), '?'));
                $st = $pdo->prepare('SELECT COUNT(*) FROM lms_users u WHERE ' . MemberRoster::memberWhere() . " AND u.level IN ($in)");
                $st->execute($high);
                $m['leaders'] = (int) $st->fetchColumn();
            }
        } catch (Throwable $e) { error_log('[vanquest] metrics leaders: ' . $e->getMessage()); }
        try {
            self::ensure();
            $m['players'] = (int) $pdo->query("SELECT COUNT(*) FROM vq_players WHERE confirmed_at <> ''")->fetchColumn();
        } catch (Throwable $e) { error_log('[vanquest] metrics players: ' . $e->getMessage()); }
        try { Database::metaSet('vq_metrics', (string) json_encode(['at' => time(), 'm' => $m])); } catch (Throwable $e) {}
        return $m;
    }

    /** The labels the mails and the community put on metrics(), in the order shown. */
    public const METRIC_LABELS = [
        'volunteers_active' => 'Active volunteers', 'volunteers_inactive' => 'Inactive volunteers',
        'centres' => 'Centres', 'leaders' => 'Leaders at C and above', 'players' => 'VanQuest players',
    ];

    /** Confirmed players, newest first, as other players see them. */
    public static function people(int $limit = 12, int $exceptId = 0): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT * FROM vq_players WHERE confirmed_at <> '' AND first_name <> '' AND id <> ? ORDER BY confirmed_at DESC LIMIT " . max(1, $limit));
        $st->execute([$exceptId]);
        return array_map(fn($p) => self::publicPlayer($p), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Players with a confirmed seat, other than $exceptId. */
    public static function players(int $exceptId = 0): int
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM vq_players WHERE confirmed_at <> '' AND id <> ?");
        $st->execute([$exceptId]);
        return (int) $st->fetchColumn();
    }

    /** The players who confirmed a seat after $at, newest first. */
    public static function peopleSince(string $at, int $exceptId = 0, int $limit = 6): array
    {
        if ($at === '') return [];
        $st = Database::pdo()->prepare("SELECT * FROM vq_players WHERE confirmed_at > ? AND first_name <> '' AND id <> ? ORDER BY confirmed_at DESC LIMIT " . max(1, $limit));
        $st->execute([$at, $exceptId]);
        return array_map(fn($p) => self::publicPlayer($p), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** How many other players confirmed a seat since $at: "who joined after you". */
    public static function joinedSince(string $at, int $exceptId = 0): int
    {
        if ($at === '') return 0;
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM vq_players WHERE confirmed_at > ? AND id <> ?");
        $st->execute([$at, $exceptId]);
        return (int) $st->fetchColumn();
    }

    private static function byEmail(string $email): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM vq_players WHERE email = ?');
        $st->execute([$email]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Who VanQuest's mails come from: VanQuest, not Afrovanguard. They went out
     * under the site's own name and address, so a player who joined a game got
     * "Afrovanguard <no-reply@…>" and took it for somebody else's mail.
     * AV_VQ_FROM_NAME / AV_VQ_FROM_EMAIL (Studio → Rules & AI → Setup → Email).
     */
    public static function sender(): array
    {
        $name = trim((string) Config::get('AV_VQ_FROM_NAME', ''));
        $addr = strtolower(trim((string) Config::get('AV_VQ_FROM_EMAIL', '')));
        $out = ['fromName' => $name !== '' ? mb_substr($name, 0, 80) : 'VanQuest'];
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) { $out['from'] = $addr; $out['replyTo'] = $addr; }
        return $out;
    }

    private static function mail(string $to, string $template, array $vars): bool
    {
        if (defined('ENABLE_EMAIL_NOTIFICATIONS') && !ENABLE_EMAIL_NOTIFICATIONS) return true;
        if (!Mailer::sendTemplate($to, $template, $vars, self::sender())) {
            error_log('[vanquest] ' . $template . ' to ' . $to . ': ' . Mailer::lastError());
            return false;
        }
        return true;
    }

    private static function sendRaw(string $to, string $subject, string $html): void
    {
        if (!Mailer::send($to, $subject, $html)) error_log('[vanquest] ' . $subject . ': ' . Mailer::lastError());
    }

    /* ══ Avatars ═════════════════════════════════════════════════════════════ */

    /**
     * A player's pixel avatar, in VanQuest's style (van-quest site/js/vq-icons.js:
     * the same ramps, 1px dark outline, light from the top). It is drawn from the
     * player's random avatar seed, so it says nothing about them but their sex,
     * which picks the hair styles. 16x16 logical pixels, PNG at $scale.
     */
    public static function avatarPng(string $seed, string $sex, int $scale = 4): string
    {
        $seed = preg_match('/^[a-f0-9]{8}$/', $seed) ? $seed : '00000000';
        $b = array_map('hexdec', str_split($seed, 2));
        $female = $sex === 'f';
        $skins = [['c98b5e', 'a86a41'], ['a86a41', '85502f'], ['7d4a2a', '5f361d'], ['5a3420', '42251a']];
        $hairs = ['1a0f08', '3b2416', '24160d'];
        $shirts = [['ff9f1c', 'd2600c'], ['ffc65c', 'd2900c'], ['b49c82', '857060'], ['d2600c', '8f3a0c'], ['6f8f5a', '4c6a3d']];
        [$skin, $skinD] = $skins[$b[0] % 4];
        $hair = $hairs[$b[1] % 3];
        [$shirt, $shirtD] = $shirts[$b[3] % 5];
        $style = $b[2] % 3;
        $g = array_fill(0, 16, array_fill(0, 16, '.'));
        $put = function (int $x, int $y, string $c) use (&$g) { if ($x >= 0 && $x < 16 && $y >= 0 && $y < 16) $g[$y][$x] = $c; };
        $rect = function (int $x0, int $y0, int $x1, int $y1, string $c) use ($put) { for ($y = $y0; $y <= $y1; $y++) for ($x = $x0; $x <= $x1; $x++) $put($x, $y, $c); };
        // Shoulders and shirt, then neck, then head.
        $rect(3, 13, 12, 15, 'S'); $rect(2, 14, 13, 15, 'S'); $rect(4, 12, 11, 12, 'S');
        $rect(3, 15, 12, 15, 's');
        $rect(7, 11, 8, 12, 'k'); $put(7, 12, 'K2');
        $rect(4, 4, 11, 10, 'k'); $put(4, 4, '.'); $put(11, 4, '.'); $put(4, 10, '.'); $put(11, 10, '.');
        $rect(4, 9, 11, 10, 'k');
        $put(4, 10, '.'); $put(11, 10, '.');
        $rect(10, 5, 11, 9, 'k2');                              // shade on the far cheek
        $put(6, 7, 'E'); $put(9, 7, 'E');                       // eyes
        $put(7, 9, 'm'); $put(8, 9, 'm');                       // mouth
        // Hair.
        $H = 'h';
        if (!$female) {
            if ($style === 0) { $rect(4, 3, 11, 4, $H); $put(4, 5, $H); $put(11, 5, $H); }                       // crop
            elseif ($style === 1) { $rect(3, 1, 12, 4, $H); $rect(4, 0, 11, 0, $H); $rect(3, 5, 3, 6, $H); $rect(12, 5, 12, 6, $H); } // afro
            else { $rect(5, 3, 10, 3, $H); $rect(4, 4, 11, 4, $H); $put(4, 5, 'k2'); $put(11, 5, 'k2'); }       // fade
        } else {
            if ($style === 0) { $rect(4, 3, 11, 4, $H); $rect(6, 0, 9, 2, $H); $rect(5, 1, 10, 1, $H); }         // puff
            elseif ($style === 1) { $rect(4, 3, 11, 4, $H); for ($y = 5; $y <= 12; $y++) { $put(3, $y, $y % 2 ? $H : 'h2'); $put(12, $y, $y % 2 ? $H : 'h2'); } } // braids
            else { $rect(3, 1, 12, 4, 'W'); $rect(4, 0, 11, 0, 'W'); $rect(10, 0, 12, 1, 'Wd'); $rect(3, 4, 12, 4, 'Wd'); }  // head wrap
            $put(4, 9, 'R'); $put(11, 9, 'R');                  // earrings
        }
        // Outline: every empty pixel touching art becomes the dark line.
        $art = fn($x, $y) => isset($g[$y][$x]) && $g[$y][$x] !== '.' && $g[$y][$x] !== 'K';
        for ($y = 0; $y < 16; $y++) for ($x = 0; $x < 16; $x++)
            if ($g[$y][$x] === '.' && ($art($x - 1, $y) || $art($x + 1, $y) || $art($x, $y - 1) || $art($x, $y + 1))) $g[$y][$x] = 'K';
        $pal = [
            '.' => '1d1915', 'K' => '1a0f08', 'K2' => '1a0f08', 'E' => '1a0f08', 'k' => $skin, 'k2' => $skinD, 'm' => '5f2a14',
            'h' => $hair, 'h2' => '3b2416', 'S' => $shirt, 's' => $shirtD, 'W' => $shirt, 'Wd' => $shirtD, 'R' => 'ffc65c',
        ];
        $im = imagecreate(16 * $scale, 16 * $scale);
        $idx = [];
        foreach ($pal as $k => $hex) $idx[$k] = imagecolorallocate($im, ...array_map('hexdec', str_split($hex, 2)));
        foreach ($g as $y => $row) foreach ($row as $x => $c)
            imagefilledrectangle($im, $x * $scale, $y * $scale, ($x + 1) * $scale - 1, ($y + 1) * $scale - 1, $idx[$c]);
        ob_start(); imagepng($im, null, 9); return (string) ob_get_clean();
    }
}
