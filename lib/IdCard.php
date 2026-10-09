<?php
/**
 * lib/IdCard.php — everything one member's card says, in one array.
 *
 * ── ONE READ, THREE SURFACES ────────────────────────────────────────────────
 * The portal card, the public scan page and the print renderer all take this
 * array. They used to be free to each fetch their own bits, and the result was
 * a card whose front said one thing and whose QR page said another. One
 * function, one shape, one answer.
 *
 * ── IT READS THE CONTRACTS, IT DOES NOT RE-DECIDE THEM ──────────────────────
 * NgvCard::standing() is the only source of status and MemberCards owns the
 * code behind the QR. Both already existed; this maps their shapes onto the
 * one array partials/id-card.php takes. standing() returns `public` as the
 * STRANGER'S LABEL (a string), not a boolean — publicFor() passes that word
 * through rather than inventing its own.
 *
 * ── WHAT IS NEVER IN HERE ───────────────────────────────────────────────────
 * Money, discipline, medical data, phone numbers, addresses. Not "not shown" —
 * NOT FETCHED. A field that exists in the array is a field some future surface
 * will print, and the card is a thing strangers hold. The status band carries
 * a WORD from NgvCard::standing() and never a figure or a reason.
 */
declare(strict_types=1);

require_once __DIR__ . '/NgvCard.php';
require_once __DIR__ . '/MemberCards.php';

final class IdCard
{
    /** Bumped when the card's design changes, so the print cache misses. */
    public const DESIGN_VERSION = 2;

    /** The smallest photo that can print sharply at the panel's final size. */
    public const PHOTO_MIN_W = 402;
    public const PHOTO_MIN_H = 416;

    /**
     * @return array{given:string,family:string,initials:string,role:string,
     *   tier_letter:string,number:string,photo_url:?string,programmes:string[],
     *   member_since:string,band:array,status_date:string,qr_svg:string,
     *   card_code:string,category:string,issued:string,member_id:int}
     */
    public static function forMember(int $memberId): array
    {
        $user = self::user($memberId);
        $part = self::participant($memberId);

        $name   = trim((string) ($user['name'] ?? ''));
        [$given, $family] = self::splitName($name);

        $code = (string) (MemberCards::secure($memberId) ?? '');

        return [
            'member_id'    => $memberId,
            'given'        => $given,
            'family'       => $family,
            'initials'     => self::initials($given, $family),
            'role'         => self::role($memberId, $part),
            'tier_letter'  => self::tierLetter($part),
            'number'       => self::number($memberId),
            'photo_url'    => self::photo($memberId),
            'programmes'   => self::programmes($part),
            'member_since' => self::since($part),
            /* The ONLY source of status, per the spec. It takes the USER row
               (it reads the participant itself) and returns label+tone among
               other things; the partial wants exactly those two. */
            'band'         => self::band($user),
            'status_date'  => date('j M Y'),
            'qr_svg'       => NgvCard::qrSvg(MemberCards::scanUrl($code), 0),
            'card_code'    => $code,
            /* Design: the tier letter, then the plan — "E · Executive". */
            'category'     => self::tierLetter($part) . ' · ' . self::category($part),
            'issued'       => self::issued($memberId),
        ];
    }

    /**
     * The same card as a stranger scanning the QR may see it.
     *
     * The photo goes too. A card found in the street should not hand whoever
     * picked it up a face to go with the name.
     */
    public static function publicFor(int $memberId): array
    {
        $c    = self::forMember($memberId);
        $user = self::user($memberId);
        $s    = $user ? NgvCard::standing($user) : [];

        /* standing()['public'] IS the stranger's wording — "Current member",
           "Not active". Taking the member's own label and hoping it is safe
           is how a suspension reaches whoever found the card. */
        return [
            'given'        => $c['given'],
            'band'         => ['label' => (string) ($s['public'] ?? 'Not a member'), 'tone' => 'neutral'],
            'member_since' => $c['member_since'],
            'card_code'    => $c['card_code'],
            'status_date'  => $c['status_date'],
        ];
    }

    /**
     * The band the card prints: the member's own label and its tone.
     *
     * A missing user is not an exception — an id that no longer resolves is a
     * card whose holder was deleted, and "Not a member" is the true answer.
     */
    private static function band(array $user): array
    {
        if (!$user) return ['label' => 'Not a member', 'tone' => 'neutral'];
        $s = NgvCard::standing($user);
        return ['label' => (string) ($s['label'] ?? 'Not a member'),
                'tone'  => in_array((string) ($s['tone'] ?? ''), NgvCard::TONES, true) ? (string) $s['tone'] : 'neutral'];
    }

    /**
     * Whether a photo is big enough to print, and why not.
     *
     * @return array{ok:bool, why:string, w:int, h:int}
     */
    public static function photoCheck(?string $url): array
    {
        $nil = ['ok' => false, 'why' => '', 'w' => 0, 'h' => 0];
        if ($url === null || trim($url) === '') return $nil + [];

        $size = null;
        try {
            /* Local files only for the measurement. Reaching out to whatever
               host a photo URL names, on a page render, is an SSRF with a
               timeout attached. A remote photo is accepted on screen and
               checked at print time by the endpoint, which can afford it. */
            $local = self::localPath($url);
            if ($local !== null && is_file($local)) $size = @getimagesize($local);
        } catch (\Throwable $e) { $size = null; }

        if (!is_array($size)) return ['ok' => true, 'why' => '', 'w' => 0, 'h' => 0];

        $w = (int) $size[0];
        $h = (int) $size[1];
        if ($w < self::PHOTO_MIN_W || $h < self::PHOTO_MIN_H) {
            return ['ok' => false, 'w' => $w, 'h' => $h,
                    'why' => 'Your photo is too small to print sharply. Upload one at least 800 px wide.'];
        }
        return ['ok' => true, 'why' => '', 'w' => $w, 'h' => $h];
    }

    /** The cache key the print endpoint uses. Spec §5: 24 h, keyed on these. */
    public static function cacheKey(int $memberId): string
    {
        $c = self::forMember($memberId);
        $photo = (string) ($c['photo_url'] ?? '');
        $local = self::localPath($photo);
        $hash  = ($local !== null && is_file($local)) ? (string) @md5_file($local) : md5($photo);

        $user = self::user($memberId);
        $code = $user ? (string) (NgvCard::standing($user)['code'] ?? '') : '';

        return hash('sha256', implode('|', [
            $memberId, $code, $hash, self::DESIGN_VERSION,
        ]));
    }

    /* ══ ══════════════════════════════════════════════════════════════════ */

    /**
     * The printed member number.
     *
     * GateAttendance::cardFor() is where the NGV number already lives — the
     * v1 card read it from there and the gate scans it. Minting a second
     * number here would give one member two, and the one on the card would be
     * the one the gate does not know.
     */
    private static function number(int $id): string
    {
        try {
            if (class_exists('GateAttendance')) {
                $c = GateAttendance::cardFor($id);
                $n = trim((string) ($c['code'] ?? $c['number'] ?? ''));
                if ($n !== '') return $n;
            }
        } catch (\Throwable $e) { /* no gate card yet */ }
        return '';
    }

    /** NgvCard::user() is the contract's own reader — not a second query. */
    private static function user(int $id): array
    {
        if ($id <= 0) return [];
        try { return NgvCard::user($id) ?? []; }
        catch (\Throwable $e) { error_log('[idcard] user: ' . $e->getMessage()); return []; }
    }

    private static function participant(int $id): ?array
    {
        if ($id <= 0) return null;
        try {
            return NgvCard::participant($id);
        } catch (\Throwable $e) {
            error_log('[idcard] participant: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Given and family from one stored name.
     *
     * The LAST word is the family name and everything before it is given.
     * Not the first word: "Mrs Adaeze Nwosu" is Nwosu, and splitting on the
     * first space puts "Mrs" in 800-weight capitals across the card.
     */
    private static function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') return ['', ''];
        $parts = explode(' ', $name);
        if (count($parts) === 1) return ['', $parts[0]];
        $family = array_pop($parts);
        return [implode(' ', $parts), $family];
    }

    private static function initials(string $given, string $family): string
    {
        $a = $given !== '' ? mb_substr($given, 0, 1) : '';
        $b = $family !== '' ? mb_substr($family, 0, 1) : '';
        $out = mb_strtoupper($a . $b);
        return $out !== '' ? $out : '—';
    }

    /** A member-set title, if they gave one. Never their system role. */
    private static function role(int $id, ?array $part): string
    {
        try {
            require_once __DIR__ . '/Prefs.php';
            $r = trim(Prefs::get($id, 'card_role', ''));
            if ($r !== '') return mb_substr($r, 0, 40);
        } catch (\Throwable $e) { /* no prefs table yet */ }
        return '';
    }

    /** First letter of the plan or track — the tier chip on the photo. */
    private static function tierLetter(?array $part): string
    {
        foreach (['plan', 'track'] as $k) {
            $v = trim((string) ($part[$k] ?? ''));
            if ($v !== '') return mb_strtoupper(mb_substr($v, 0, 1));
        }
        return 'M';
    }

    /** A member-set photo. Null is a valid card — the partial draws initials. */
    private static function photo(int $id): ?string
    {
        try {
            require_once __DIR__ . '/Prefs.php';
            $p = trim(Prefs::get($id, 'card_photo', ''));
            return $p !== '' ? $p : null;
        } catch (\Throwable $e) { return null; }
    }

    /** At most two, because the card has room for two. */
    private static function programmes(?array $part): array
    {
        $out = [];
        foreach (['track', 'cohort'] as $k) {
            $v = trim((string) ($part[$k] ?? ''));
            if ($v !== '') $out[] = $v;
        }
        return array_slice(array_values(array_unique($out)), 0, 2);
    }

    private static function since(?array $part): string
    {
        $d = trim((string) ($part['start_date'] ?? ''));
        if ($d === '') return '';
        $ts = strtotime($d);
        return $ts !== false ? date('Y', $ts) : '';
    }

    private static function category(?array $part): string
    {
        $plan = trim((string) ($part['plan'] ?? ''));
        return $plan !== '' ? $plan : 'Member';
    }

    private static function issued(int $id): string
    {
        /* From the card row itself. lookup() answers "whose card is this?" and
           carries no issued_at, so reading it there was a TypeError for every
           member who actually had a card — the portal card and /q/ both died. */
        $code = (string) (MemberCards::secure($id) ?? '');
        $at = '';
        foreach (MemberCards::of($id) as $c) {
            if (($c['kind'] ?? '') === 'secure' && ($c['code'] ?? '') === $code) { $at = trim((string) ($c['issued_at'] ?? '')); break; }
        }
        $ts  = $at !== '' ? strtotime($at) : false;
        return $ts !== false ? date('d M Y', $ts) : date('d M Y');
    }

    /** A site-relative URL mapped to a file on disk, or null. */
    private static function localPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('~^https?://~i', $url)) return null;
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || str_contains($path, '..')) return null;
        return __DIR__ . '/../' . ltrim($path, '/');
    }
}
