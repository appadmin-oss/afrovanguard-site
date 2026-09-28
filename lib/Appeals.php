<?php
/**
 * lib/Appeals.php — fundraising appeals: campaigns, needs, updates, tiers.
 *
 * An APPEAL is one specific, named ask with a story and a number: a borehole
 * for a school, the December outreach, a laptop fund. It is not the donate
 * page, which is the standing "give to Afrovanguard" surface and stays exactly
 * where it is.
 *
 * WHY THIS EXISTS. `process-donation.php` already takes money against a
 * campaign key, but the keys were three constants — `general`, `sts`,
 * `techhome` — written into `defaultData()`. There was no way to run a real
 * appeal: no page to send anybody to, nothing to share, nothing for a search
 * engine to index, and no way to say what today actually costs. The money path
 * worked; there was simply nothing in front of it.
 *
 * THE MONEY IS NOT DUPLICATED HERE. Verified donations live in the file
 * `process-donation.php` writes under `av_private_path('donations.json')`,
 * which is idempotent, exclusively locked and already correct. This class READS
 * that file for every figure it shows. An appeal that stored its own running
 * total would be a second set of books, and the interesting question about two
 * sets of books is never *whether* they will disagree.
 *
 * The one figure that does live here is `offline_ngn`: cash, a bank transfer
 * somebody made in a branch, a donation in kind valued by staff. It is recorded
 * and displayed SEPARATELY rather than folded in, because a total that mixes
 * "Paystack verified this" with "a colleague typed this" cannot be audited
 * afterwards, and the first person to ask will be a donor.
 *
 * NEEDS are the small end of the same idea: "today we need ₦18,000 for 40 hot
 * meals". They carry a cadence (daily or weekly) and a period, and the pair is
 * UNIQUE — the same lesson the NGV ledger learned about accrual, for the same
 * reason: a cron that overlaps a staff button must not post Tuesday twice.
 *
 * NOBODY OUTSIDE AFROVANGUARD CAN START AN APPEAL. Every write on this class is
 * staff-gated by its callers. That is a deliberate product decision, not an
 * omission: user-created fundraisers would make this a payment platform holding
 * other people's money, with the trust, identity and payout obligations that
 * come with it.
 */
declare(strict_types=1);

final class Appeals
{
    /** What an appeal is for. Drives the schema.org type on the public page. */
    public const KINDS = ['appeal', 'event', 'programme', 'emergency'];

    /** draft: invisible. live: public and indexed. paused: public, not asking.
     *  funded: goal met, still readable. closed: archived, noindex. */
    public const STATUSES = ['draft', 'live', 'paused', 'funded', 'closed'];

    public const CADENCES = ['daily', 'weekly', 'once'];

    /** An appeal cannot ask for more than this, and a need cannot either. Not a
     *  policy about ambition — a guard against a stray zero on a keyboard. */
    private const MAX_NGN = 500000000;      // ₦500m

    private static ?array $moneyCache = null;
    private static bool $ready = false;

    /* ── schema ──────────────────────────────────────────────────────────── */

    /**
     * Provision the tables on demand.
     *
     * On-demand rather than a version-stamped migration step, deliberately: the
     * stamped steps are the ones that leave a deployment broken when its stamp
     * already matched, which is exactly the failure `tests/drift.test.php`
     * exists to document. A subsystem that checks its own schema heals itself.
     */
    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try { Database::execSchema(Database::pdo(), self::ddl()); }
        catch (Throwable $e) { error_log('[appeals] schema: ' . $e->getMessage()); }
    }

    /**
     * The tables.
     *
     * A nowdoc, and no semicolon inside any comment. `execSchema()` splits this
     * into statements by exploding on ';', so a semicolon in prose cuts a CREATE
     * in half and both halves fail down the benign-error path — silently. The
     * NGV schema learned this the hard way and `tests/drift.test.php` pins it
     * there; the same trap is the same trap here.
     *
     * `slug`, `cadence` and `period` are VARCHAR rather than TEXT because MySQL
     * cannot index a TEXT column without a prefix length, and declared TEXT the
     * UNIQUE index is dropped there by the benign-error path — the idempotency
     * would hold on SQLite and quietly not on one engine.
     */
    private static function ddl(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS av_appeals (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  slug          VARCHAR(90) NOT NULL UNIQUE,
  title         TEXT NOT NULL DEFAULT '',
  tagline       TEXT NOT NULL DEFAULT '',
  story         TEXT NOT NULL DEFAULT '',
  kind          VARCHAR(16) NOT NULL DEFAULT 'appeal',
  status        VARCHAR(16) NOT NULL DEFAULT 'draft',
  goal_ngn      INTEGER NOT NULL DEFAULT 0,
  offline_ngn   INTEGER NOT NULL DEFAULT 0,
  spent_ngn     INTEGER NOT NULL DEFAULT 0,
  spend_note    TEXT NOT NULL DEFAULT '',
  cover_url     TEXT NOT NULL DEFAULT '',
  video_url     TEXT NOT NULL DEFAULT '',
  gallery       TEXT NOT NULL DEFAULT '',
  match_ngn     INTEGER NOT NULL DEFAULT 0,
  match_sponsor TEXT NOT NULL DEFAULT '',
  match_until   TEXT NOT NULL DEFAULT '',
  urgent        INTEGER NOT NULL DEFAULT 0,
  beneficiary   TEXT NOT NULL DEFAULT '',
  location      TEXT NOT NULL DEFAULT '',
  organiser     TEXT NOT NULL DEFAULT '',
  starts_on     TEXT NOT NULL DEFAULT '',
  ends_on       TEXT NOT NULL DEFAULT '',
  event_start   TEXT NOT NULL DEFAULT '',
  event_end     TEXT NOT NULL DEFAULT '',
  event_venue   TEXT NOT NULL DEFAULT '',
  seo_title     TEXT NOT NULL DEFAULT '',
  seo_desc      TEXT NOT NULL DEFAULT '',
  keywords      TEXT NOT NULL DEFAULT '',
  featured      INTEGER NOT NULL DEFAULT 0,
  sort          INTEGER NOT NULL DEFAULT 0,
  share_count   INTEGER NOT NULL DEFAULT 0,
  view_count    INTEGER NOT NULL DEFAULT 0,
  created_by    TEXT NOT NULL DEFAULT '',
  created_at    TEXT NOT NULL DEFAULT '',
  updated_at    TEXT NOT NULL DEFAULT '',
  published_at  TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_appeals_status ON av_appeals (status, sort);
CREATE TABLE IF NOT EXISTS av_appeal_needs (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  appeal_id    INTEGER NOT NULL,
  cadence      VARCHAR(8) NOT NULL DEFAULT 'daily',
  period       VARCHAR(16) NOT NULL DEFAULT '',
  title        TEXT NOT NULL DEFAULT '',
  detail       TEXT NOT NULL DEFAULT '',
  target_ngn   INTEGER NOT NULL DEFAULT 0,
  unit_label   TEXT NOT NULL DEFAULT '',
  unit_cost    INTEGER NOT NULL DEFAULT 0,
  units_target INTEGER NOT NULL DEFAULT 0,
  met_ngn      INTEGER NOT NULL DEFAULT 0,
  status       VARCHAR(12) NOT NULL DEFAULT 'open',
  met_at       TEXT NOT NULL DEFAULT '',
  created_by   TEXT NOT NULL DEFAULT '',
  created_at   TEXT NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_need_period ON av_appeal_needs (appeal_id, cadence, period);
CREATE TABLE IF NOT EXISTS av_appeal_updates (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  appeal_id  INTEGER NOT NULL,
  kind       VARCHAR(16) NOT NULL DEFAULT 'update',
  title      TEXT NOT NULL DEFAULT '',
  body       TEXT NOT NULL DEFAULT '',
  image_url  TEXT NOT NULL DEFAULT '',
  amount_ngn INTEGER NOT NULL DEFAULT 0,
  posted_by  TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_update_appeal ON av_appeal_updates (appeal_id, id);
CREATE TABLE IF NOT EXISTS av_appeal_tiers (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  appeal_id  INTEGER NOT NULL,
  amount_ngn INTEGER NOT NULL DEFAULT 0,
  label      TEXT NOT NULL DEFAULT '',
  impact     TEXT NOT NULL DEFAULT '',
  sort       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_tier_appeal ON av_appeal_tiers (appeal_id, sort);
SQL;
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private static function now(): string { return function_exists('av_now_tz') ? av_now_tz('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s'); }
    private static function today(): string { return function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d'); }

    /** Clamp money to a whole, non-negative number of naira within the ceiling. */
    public static function money($n): int
    {
        $v = (int) round((float) $n);
        return max(0, min(self::MAX_NGN, $v));
    }

    /**
     * A URL-safe slug.
     *
     * Once an appeal is published its slug is its address: it is on a poster, in
     * somebody's WhatsApp, and in a search index. `save()` therefore never
     * changes it afterwards, no matter how the title is edited.
     */
    public static function slugify(string $s): string
    {
        $s = trim($s);
        /* Fold the diacritics BEFORE iconv gets a chance to discard them.
           //TRANSLIT is locale-dependent and in the C locale a great many
           accented letters simply vanish — "Ìlorin" came out "lorin", which is
           not a transliteration of anything and would have been the permanent
           public address of the appeal. Yoruba and Igbo marks are first-class
           here, not an edge case: this is a Nigerian organisation. */
        $s = strtr($s, [
            'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
            'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','Ẹ'=>'E','ẹ'=>'e','Ẽ'=>'E','ẽ'=>'e',
            'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','Ị'=>'I','ị'=>'i',
            'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ọ'=>'O','ọ'=>'o',
            'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','Ụ'=>'U','ụ'=>'u',
            'Ṣ'=>'S','ṣ'=>'s','Ñ'=>'N','ñ'=>'n','Ń'=>'N','ń'=>'n','Ç'=>'C','ç'=>'c','Ỵ'=>'Y','ỵ'=>'y',
            'Ǹ'=>'N','ǹ'=>'n','Ẅ'=>'W','ẅ'=>'w','ß'=>'ss','Æ'=>'AE','æ'=>'ae','Ø'=>'O','ø'=>'o','&'=>' and ',
        ]);
        $s = mb_strtolower($s, 'UTF-8');
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false && trim($t, '-? ') !== '') $s = $t;
        }
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim(substr($s, 0, 80), '-');
    }

    /** The period key a need of this cadence falls in on $date. */
    public static function periodFor(string $cadence, string $date = ''): string
    {
        $date = $date !== '' ? $date : self::today();
        $ts = strtotime($date) ?: time();
        if ($cadence === 'weekly') return gmdate('o-\WW', $ts);
        if ($cadence === 'once')   return '';
        return gmdate('Y-m-d', $ts);
    }

    /* ── reading appeals ─────────────────────────────────────────────────── */

    /** One appeal by slug, whatever its status. Null when there is none. */
    public static function bySlug(string $slug): ?array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM av_appeals WHERE slug = ?');
            $st->execute([$slug]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? self::shape($r) : null;
        } catch (Throwable $e) { error_log('[appeals] bySlug: ' . $e->getMessage()); return null; }
    }

    public static function byId(int $id): ?array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM av_appeals WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? self::shape($r) : null;
        } catch (Throwable $e) { return null; }
    }

    /**
     * Appeals, newest-featured first.
     *
     * $status '' means every status — the staff console. Callers rendering
     * anything public pass 'live' (or use `public_()`), because a draft is
     * somebody's unfinished writing and must never be reachable.
     */
    public static function all(string $status = '', int $limit = 200): array
    {
        self::ensure();
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM av_appeals';
        $args = [];
        if ($status !== '' && in_array($status, self::STATUSES, true)) { $sql .= ' WHERE status = ?'; $args[] = $status; }
        $sql .= ' ORDER BY featured DESC, sort ASC, id DESC LIMIT ' . $limit;
        try {
            $st = Database::pdo()->prepare($sql);
            $st->execute($args);
            return array_map([self::class, 'shape'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { error_log('[appeals] all: ' . $e->getMessage()); return []; }
    }

    /** Everything a visitor may see: live, paused and funded — never a draft. */
    public static function published(int $limit = 200): array
    {
        self::ensure();
        $limit = max(1, min(500, $limit));
        try {
            $st = Database::pdo()->prepare(
                "SELECT * FROM av_appeals WHERE status IN ('live','paused','funded')
                 ORDER BY featured DESC, sort ASC, id DESC LIMIT " . $limit);
            $st->execute();
            return array_map([self::class, 'shape'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { return []; }
    }

    /**
     * May the public OPEN this appeal? Everything except a draft.
     *
     * Deliberately wider than `published()`, which is what gets LISTED. A
     * closed appeal is not advertised any more, but it stays reachable: people
     * follow old links, a poster outlives the campaign on it, and how a thing
     * ended is the most persuasive page on the site. 404ing it would break
     * every inbound link the appeal ever earned — including the ones that are
     * still carrying its share card around WhatsApp.
     *
     * Reachable is not the same as indexed: `give/appeal.php` sends a closed
     * appeal `noindex, follow` so it stops competing in search with the ones
     * still asking.
     */
    public static function isPublic(?array $a): bool
    {
        $s = (string) ($a['status'] ?? '');
        return $a !== null && $s !== '' && $s !== 'draft' && in_array($s, self::STATUSES, true);
    }

    /** Rows come back with the ints as ints and the derived fields computed. */
    private static function shape(array $r): array
    {
        foreach (['id', 'goal_ngn', 'offline_ngn', 'spent_ngn', 'featured', 'sort',
                  'share_count', 'view_count', 'match_ngn', 'urgent'] as $k) {
            $r[$k] = (int) ($r[$k] ?? 0);
        }
        $r['gallery'] = array_values(array_filter(array_map('trim', explode("\n", (string) ($r['gallery'] ?? '')))));
        return $r;
    }

    /* ── the money, read from the donation record ────────────────────────── */

    /**
     * The verified donation store, read-only.
     *
     * This is `process-donation.php`'s file and it stays that way. The shared
     * lock matters: that script writes under LOCK_EX, and a read taken while a
     * write is half-done would parse a truncated document and report a total
     * that is simply wrong — on the page a donor is looking at.
     *
     * Memoised per request. A page listing twenty appeals asks for totals twenty
     * times, and re-reading and re-decoding the file for each is work nobody
     * needs done.
     */
    private static function donationFile(): array
    {
        if (self::$moneyCache !== null) return self::$moneyCache;
        self::$moneyCache = ['campaigns' => [], 'donations' => [], 'totals' => []];
        if (!function_exists('av_private_path')) return self::$moneyCache;
        $path = av_private_path('donations.json');
        if (!is_file($path)) return self::$moneyCache;
        $fp = @fopen($path, 'r');
        if (!$fp) return self::$moneyCache;
        @flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        @flock($fp, LOCK_UN);
        fclose($fp);
        $d = json_decode((string) $raw, true);
        if (is_array($d)) {
            self::$moneyCache = [
                'campaigns' => is_array($d['campaigns'] ?? null) ? $d['campaigns'] : [],
                'donations' => is_array($d['donations'] ?? null) ? $d['donations'] : [],
                'totals'    => is_array($d['totals'] ?? null) ? $d['totals'] : [],
            ];
        }
        return self::$moneyCache;
    }

    /** Drop the memoised read — for a caller that has just taken a donation. */
    public static function forgetMoney(): void { self::$moneyCache = null; }

    /**
     * What one appeal has raised, and how far along it is.
     *
     * `online` is what Paystack verified. `offline` is what staff recorded by
     * hand. They are added for the headline figure and also reported separately,
     * so that "we have ₦1.2m" can always be broken into "₦900k of it through the
     * site, ₦300k of it cash somebody logged" without going back to the file.
     */
    public static function progress(array $a): array
    {
        $slug   = (string) ($a['slug'] ?? '');
        $file   = self::donationFile();
        $c      = $file['campaigns'][$slug] ?? [];
        $online = self::money($c['raised'] ?? 0);
        $donors = max(0, (int) ($c['donors'] ?? 0));
        $offline = self::money($a['offline_ngn'] ?? 0);
        $goal   = self::money($a['goal_ngn'] ?? 0);
        $raised = $online + $offline;

        /* A goal of 0 means an OPEN appeal — one with no finish line, which is
           the honest shape for a running programme. It is not "0% of nothing",
           and a progress bar would be a lie, so percent is null and the page
           renders a figure instead of a meter. */
        $pct = $goal > 0 ? min(100, (int) floor($raised * 100 / $goal)) : null;

        return [
            'online'    => $online,
            'offline'   => $offline,
            'raised'    => $raised,
            'goal'      => $goal,
            'percent'   => $pct,
            'donors'    => $donors,
            'remaining' => $goal > 0 ? max(0, $goal - $raised) : 0,
            'met'       => $goal > 0 && $raised >= $goal,
            'open'      => $goal === 0,
        ];
    }

    /**
     * Recent donors for the wall, newest first.
     *
     * Only a display name and an amount ever leave this method. The store holds
     * the donor's email and reference too, and a public wall is not the place
     * for either — the wall exists to thank people, not to publish them.
     * Anything the donor marked anonymous comes back as "Anonymous" with the
     * name dropped entirely rather than masked, because a masked name that can
     * be guessed is not anonymity.
     */
    public static function donors(array $a, int $limit = 12): array
    {
        $slug = (string) ($a['slug'] ?? '');
        $out  = [];
        foreach (self::donationFile()['donations'] as $d) {
            if ((string) ($d['campaign'] ?? '') !== $slug) continue;
            $anon = !empty($d['anonymous']);
            $name = trim((string) ($d['name'] ?? ''));
            $out[] = [
                'name'   => ($anon || $name === '') ? 'Anonymous' : mb_substr($name, 0, 60),
                'amount' => self::money($d['amount'] ?? 0),
                'when'   => (string) ($d['date'] ?? $d['created_at'] ?? ''),
                'note'   => $anon ? '' : mb_substr(trim((string) ($d['message'] ?? '')), 0, 200),
            ];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /**
     * Make sure the donation store knows this campaign key.
     *
     * `storeDonationIfNew()` files a donation under 'general' when the key it is
     * given is not in the campaigns map. Without this, every gift to a new
     * appeal would be credited to the general fund: the appeal would sit at zero
     * while the money was real and somewhere else, which is the kind of error
     * that is discovered by a donor asking why their name is not on the page.
     */
    public static function registerCampaignKey(string $slug, int $goal): bool
    {
        if ($slug === '' || !function_exists('av_private_path')) return false;
        $path = av_private_path('donations.json');
        $fp = @fopen($path, 'c+');
        if (!$fp) { error_log('[appeals] cannot open donation store to register ' . $slug); return false; }
        @flock($fp, LOCK_EX);
        $raw  = stream_get_contents($fp) ?: '';
        $data = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['campaigns']) || !is_array($data['campaigns'])) {
            /* No file yet, or one this code does not recognise. Creating a fresh
               document here would discard whatever is actually in it, so the
               only safe move is to leave it alone and say so. */
            @flock($fp, LOCK_UN); fclose($fp);
            if ($raw !== '') error_log('[appeals] donation store unreadable — not registering ' . $slug);
            return false;
        }
        if (isset($data['campaigns'][$slug])) {
            // Already there. Keep whatever it has raised; only refresh the goal.
            $data['campaigns'][$slug]['goal'] = $goal;
        } else {
            $data['campaigns'][$slug] = ['raised' => 0, 'goal' => $goal, 'donors' => 0];
        }
        $data['last_updated'] = date('c');
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        ftruncate($fp, 0); rewind($fp); fwrite($fp, (string) $json); fflush($fp);
        @flock($fp, LOCK_UN); fclose($fp);
        self::forgetMoney();
        return true;
    }

    /* ── writing appeals ─────────────────────────────────────────────────── */

    /**
     * Create or update an appeal. Returns its id, or 0 when it was refused.
     *
     * $in['id'] > 0 updates, otherwise a row is created. The slug is derived
     * from the title on creation and is NEVER rewritten afterwards: it is the
     * address on a poster and in a search index, and an appeal that changes its
     * own URL when somebody fixes a typo in the title breaks every link to it.
     */
    public static function save(array $in, string $actor = ''): int
    {
        self::ensure();
        $id    = (int) ($in['id'] ?? 0);
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 160);
        if ($title === '' && $id === 0) return 0;

        $kind   = in_array((string) ($in['kind'] ?? ''), self::KINDS, true) ? (string) $in['kind'] : 'appeal';
        $status = in_array((string) ($in['status'] ?? ''), self::STATUSES, true) ? (string) $in['status'] : 'draft';
        $goal   = self::money($in['goal_ngn'] ?? 0);

        $cols = [
            'title'       => $title,
            'tagline'     => mb_substr(trim((string) ($in['tagline'] ?? '')), 0, 240),
            'story'       => mb_substr((string) ($in['story'] ?? ''), 0, 40000),
            'kind'        => $kind,
            'status'      => $status,
            'goal_ngn'    => $goal,
            'offline_ngn' => self::money($in['offline_ngn'] ?? 0),
            'spent_ngn'   => self::money($in['spent_ngn'] ?? 0),
            'spend_note'  => mb_substr(trim((string) ($in['spend_note'] ?? '')), 0, 2000),
            'cover_url'   => self::safeUrl((string) ($in['cover_url'] ?? '')),
            'video_url'   => self::safeUrl((string) ($in['video_url'] ?? '')),
            'gallery'     => self::galleryLines($in['gallery'] ?? ''),
            /* Matched giving. A sponsor pledges to match what the public gives,
               up to a ceiling and until a date — "every naira doubled until
               Friday". It is the single most effective thing on a fundraising
               page that is not the story itself, and it is a PLEDGE rather than
               money in hand, so it is never added to what has been raised. */
            'match_ngn'     => self::money($in['match_ngn'] ?? 0),
            'match_sponsor' => mb_substr(trim((string) ($in['match_sponsor'] ?? '')), 0, 120),
            'match_until'   => self::validDate((string) ($in['match_until'] ?? '')),
            'urgent'        => !empty($in['urgent']) ? 1 : 0,
            'beneficiary' => mb_substr(trim((string) ($in['beneficiary'] ?? '')), 0, 160),
            'location'    => mb_substr(trim((string) ($in['location'] ?? '')), 0, 120),
            'organiser'   => mb_substr(trim((string) ($in['organiser'] ?? '')), 0, 120),
            'starts_on'   => self::validDate((string) ($in['starts_on'] ?? '')),
            'ends_on'     => self::validDate((string) ($in['ends_on'] ?? '')),
            'event_start' => self::validDateTime((string) ($in['event_start'] ?? '')),
            'event_end'   => self::validDateTime((string) ($in['event_end'] ?? '')),
            'event_venue' => mb_substr(trim((string) ($in['event_venue'] ?? '')), 0, 200),
            'seo_title'   => mb_substr(trim((string) ($in['seo_title'] ?? '')), 0, 70),
            'seo_desc'    => mb_substr(trim((string) ($in['seo_desc'] ?? '')), 0, 200),
            'keywords'    => mb_substr(trim((string) ($in['keywords'] ?? '')), 0, 300),
            'featured'    => !empty($in['featured']) ? 1 : 0,
            'sort'        => max(0, min(9999, (int) ($in['sort'] ?? 0))),
            'updated_at'  => self::now(),
        ];

        try {
            $pdo = Database::pdo();
            if ($id > 0) {
                $was = self::byId($id);
                if (!$was) return 0;
                /* Publishing for the first time stamps published_at, which is
                   what the sitemap and the Article schema report as the date. */
                if ($status === 'live' && (string) $was['published_at'] === '') $cols['published_at'] = self::now();
                $set = implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($cols)));
                $args = array_values($cols);
                $args[] = $id;
                $pdo->prepare('UPDATE av_appeals SET ' . $set . ' WHERE id = ?')->execute($args);
                if ($goal !== (int) $was['goal_ngn'] || $status === 'live') {
                    self::registerCampaignKey((string) $was['slug'], $goal);
                }
                self::audit('appeal_save', (string) $was['slug'], $actor, $title);
                return $id;
            }

            $cols['slug']       = self::uniqueSlug(self::slugify($title) ?: 'appeal');
            $cols['created_by'] = mb_substr($actor, 0, 120);
            $cols['created_at'] = self::now();
            if ($status === 'live') $cols['published_at'] = self::now();
            $names = implode(',', array_keys($cols));
            $marks = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO av_appeals ($names) VALUES ($marks)")->execute(array_values($cols));
            $newId = (int) $pdo->lastInsertId();
            self::registerCampaignKey($cols['slug'], $goal);
            self::audit('appeal_create', $cols['slug'], $actor, $title);
            return $newId;
        } catch (Throwable $e) { error_log('[appeals] save: ' . $e->getMessage()); return 0; }
    }

    /** A slug nothing else is using, by suffixing -2, -3 … when it is taken. */
    private static function uniqueSlug(string $base): string
    {
        $base = $base !== '' ? $base : 'appeal';
        $try = $base; $n = 1;
        while (self::bySlug($try) !== null && $n < 50) { $n++; $try = substr($base, 0, 76) . '-' . $n; }
        return $try;
    }

    /** Move an appeal's status. Returns false for one this class does not know. */
    public static function setStatus(int $id, string $status, string $actor = ''): bool
    {
        self::ensure();
        if (!in_array($status, self::STATUSES, true)) return false;
        $a = self::byId($id);
        if (!$a) return false;
        try {
            $set = 'status = ?, updated_at = ?';
            $args = [$status, self::now()];
            if ($status === 'live' && (string) $a['published_at'] === '') { $set .= ', published_at = ?'; $args[] = self::now(); }
            $args[] = $id;
            Database::pdo()->prepare('UPDATE av_appeals SET ' . $set . ' WHERE id = ?')->execute($args);
            if ($status === 'live') self::registerCampaignKey((string) $a['slug'], (int) $a['goal_ngn']);
            self::audit('appeal_status', (string) $a['slug'], $actor, $status);
            self::bumpSitemap();
            return true;
        } catch (Throwable $e) { error_log('[appeals] setStatus: ' . $e->getMessage()); return false; }
    }

    /**
     * Delete an appeal and everything hanging off it.
     *
     * Refused once anything has been given, whatever the status. A donation was
     * made TO this appeal and the record of what it was for has to survive the
     * page — deleting it would leave money in the store pointing at a campaign
     * key nothing explains. Closing is what staff want in that case anyway.
     */
    public static function delete(int $id, string $actor = ''): array
    {
        self::ensure();
        $a = self::byId($id);
        if (!$a) return ['ok' => false, 'error' => 'No such appeal.'];
        $p = self::progress($a);
        if ($p['raised'] > 0 || $p['donors'] > 0) {
            return ['ok' => false, 'error' => 'This appeal has received donations, so it cannot be deleted. Close it instead — the page stays readable and the record survives.'];
        }
        try {
            $pdo = Database::pdo();
            foreach (['av_appeal_needs', 'av_appeal_updates', 'av_appeal_tiers'] as $t) {
                $pdo->prepare("DELETE FROM $t WHERE appeal_id = ?")->execute([$id]);
            }
            $pdo->prepare('DELETE FROM av_appeals WHERE id = ?')->execute([$id]);
            self::audit('appeal_delete', (string) $a['slug'], $actor, (string) $a['title']);
            self::bumpSitemap();
            return ['ok' => true];
        } catch (Throwable $e) { error_log('[appeals] delete: ' . $e->getMessage()); return ['ok' => false, 'error' => 'Could not delete.']; }
    }

    /* ── needs: what today and this week actually cost ───────────────────── */

    /**
     * Post a need, or update the one already standing for that period.
     *
     * The (appeal, cadence, period) triple is UNIQUE, so "today's need" is a
     * single row that can be corrected rather than a list that grows every time
     * somebody presses save. That is also what makes this safe to call from a
     * scheduled job: posting Tuesday twice updates Tuesday.
     *
     * A need can be priced two ways and they agree by construction. Give it a
     * unit cost and a count — 40 meals at ₦450 — and the target is computed, so
     * the page can say "40 hot meals" and "₦18,000" without anybody keeping the
     * two in step by hand. Give it a bare figure and it is just a figure.
     */
    public static function postNeed(int $appealId, array $in, string $actor = ''): array
    {
        self::ensure();
        $a = self::byId($appealId);
        if (!$a) return ['ok' => false, 'error' => 'No such appeal.'];

        $cadence = in_array((string) ($in['cadence'] ?? ''), self::CADENCES, true) ? (string) $in['cadence'] : 'daily';
        $period  = trim((string) ($in['period'] ?? ''));
        if ($period === '') $period = self::periodFor($cadence, (string) ($in['date'] ?? ''));
        $title   = mb_substr(trim((string) ($in['title'] ?? '')), 0, 160);
        if ($title === '') return ['ok' => false, 'error' => 'A need has to say what it is for.'];

        $unitCost   = self::money($in['unit_cost'] ?? 0);
        $unitsTarget = max(0, min(1000000, (int) ($in['units_target'] ?? 0)));
        $target = ($unitCost > 0 && $unitsTarget > 0)
            ? self::money($unitCost * $unitsTarget)
            : self::money($in['target_ngn'] ?? 0);
        if ($target <= 0) return ['ok' => false, 'error' => 'A need has to carry a figure, or a unit cost and a count.'];

        $row = [
            'appeal_id'   => $appealId,
            'cadence'     => $cadence,
            'period'      => $period,
            'title'       => $title,
            'detail'      => mb_substr(trim((string) ($in['detail'] ?? '')), 0, 1200),
            'target_ngn'  => $target,
            'unit_label'  => mb_substr(trim((string) ($in['unit_label'] ?? '')), 0, 60),
            'unit_cost'   => $unitCost,
            'units_target' => $unitsTarget,
            'created_by'  => mb_substr($actor, 0, 120),
            'created_at'  => self::now(),
        ];
        try {
            $pdo = Database::pdo();
            $st = $pdo->prepare('SELECT id FROM av_appeal_needs WHERE appeal_id = ? AND cadence = ? AND period = ?');
            $st->execute([$appealId, $cadence, $period]);
            $existing = $st->fetchColumn();
            if ($existing !== false) {
                unset($row['appeal_id'], $row['cadence'], $row['period'], $row['created_by'], $row['created_at']);
                $set = implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($row)));
                $args = array_values($row); $args[] = (int) $existing;
                $pdo->prepare('UPDATE av_appeal_needs SET ' . $set . ' WHERE id = ?')->execute($args);
                self::audit('need_update', (string) $a['slug'], $actor, $title);
                return ['ok' => true, 'id' => (int) $existing, 'updated' => true];
            }
            $names = implode(',', array_keys($row));
            $marks = implode(',', array_fill(0, count($row), '?'));
            $pdo->prepare("INSERT INTO av_appeal_needs ($names) VALUES ($marks)")->execute(array_values($row));
            /* Read the id BEFORE anything else touches the connection. The audit
               trail writes a row of its own, and lastInsertId() is per-connection
               rather than per-table — so asking after it returns the audit row's
               id, and the caller acts on the wrong record. */
            $needId = (int) $pdo->lastInsertId();
            self::audit('need_post', (string) $a['slug'], $actor, $title);
            return ['ok' => true, 'id' => $needId, 'updated' => false];
        } catch (Throwable $e) { error_log('[appeals] postNeed: ' . $e->getMessage()); return ['ok' => false, 'error' => 'Could not save that need.']; }
    }

    /** Mark a need met, optionally recording what came in against it. */
    public static function meetNeed(int $needId, $amount = null, string $actor = ''): bool
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM av_appeal_needs WHERE id = ?');
            $st->execute([$needId]);
            $n = $st->fetch(PDO::FETCH_ASSOC);
            if (!$n) return false;
            $met = $amount === null ? (int) $n['target_ngn'] : self::money($amount);
            Database::pdo()->prepare('UPDATE av_appeal_needs SET status = ?, met_ngn = ?, met_at = ? WHERE id = ?')
                ->execute(['met', $met, self::now(), $needId]);
            self::audit('need_met', (string) ($n['title'] ?? ''), $actor, (string) $met);
            return true;
        } catch (Throwable $e) { error_log('[appeals] meetNeed: ' . $e->getMessage()); return false; }
    }

    public static function deleteNeed(int $needId): bool
    {
        self::ensure();
        try { Database::pdo()->prepare('DELETE FROM av_appeal_needs WHERE id = ?')->execute([$needId]); return true; }
        catch (Throwable $e) { return false; }
    }

    /** Every need on an appeal, newest period first. */
    public static function needsFor(int $appealId, int $limit = 60): array
    {
        self::ensure();
        $limit = max(1, min(400, $limit));
        try {
            $st = Database::pdo()->prepare(
                'SELECT * FROM av_appeal_needs WHERE appeal_id = ? ORDER BY period DESC, id DESC LIMIT ' . $limit);
            $st->execute([$appealId]);
            return array_map([self::class, 'shapeNeed'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { return []; }
    }

    /**
     * The needs standing RIGHT NOW: today's daily one and this week's weekly
     * one, plus any open one-off. This is what the public page leads with,
     * because "₦18,000 today for 40 hot meals" is a decision somebody can make
     * in one breath and "help us reach ₦4,000,000" is not.
     *
     * A need whose period has passed is not shown as current even when it was
     * never met. Yesterday's lunch cannot still be bought.
     */
    public static function currentNeeds(int $appealId): array
    {
        $today = self::periodFor('daily');
        $week  = self::periodFor('weekly');
        $out = [];
        foreach (self::needsFor($appealId, 200) as $n) {
            $is = ($n['cadence'] === 'daily'  && $n['period'] === $today)
               || ($n['cadence'] === 'weekly' && $n['period'] === $week)
               || ($n['cadence'] === 'once'   && $n['status'] === 'open');
            if ($is) $out[] = $n;
        }
        /* Daily first: it is the most urgent and the most concrete. */
        usort($out, static function ($x, $y) {
            $rank = ['daily' => 0, 'weekly' => 1, 'once' => 2];
            return ($rank[$x['cadence']] ?? 3) <=> ($rank[$y['cadence']] ?? 3);
        });
        return $out;
    }

    /**
     * The needs standing right now across EVERY live appeal, each carrying the
     * appeal it belongs to.
     *
     * This is what the index and the home page lead with, and it is the single
     * most effective thing either page can say. "Help us reach ₦4,000,000" asks
     * somebody to care about an institution; "₦18,000 today for 40 hot meals"
     * asks them to buy lunch for a work crew, which is a decision a person can
     * actually make while standing at a bus stop.
     *
     * Ordered the way urgency runs: today before this week, and within a day,
     * the appeal that is furthest from its target first.
     */
    public static function currentNeedsAll(int $limit = 8): array
    {
        self::ensure();
        $out = [];
        foreach (self::published(60) as $a) {
            if ((string) $a['status'] !== 'live') continue;
            foreach (self::currentNeeds((int) $a['id']) as $n) {
                if ($n['status'] === 'met') continue;          // met is not a need
                $n['appeal'] = [
                    'id' => (int) $a['id'], 'slug' => (string) $a['slug'],
                    'title' => (string) $a['title'], 'url' => '/give/' . rawurlencode((string) $a['slug']) . '/',
                    'cover_url' => (string) $a['cover_url'], 'location' => (string) $a['location'],
                ];
                $st = self::state($a);
                $n['appeal_percent'] = $st['percent'];
                $out[] = $n;
            }
        }
        $rank = ['daily' => 0, 'weekly' => 1, 'once' => 2];
        usort($out, static function (array $x, array $y) use ($rank): int {
            $r = ($rank[$x['cadence']] ?? 3) <=> ($rank[$y['cadence']] ?? 3);
            if ($r !== 0) return $r;
            /* Furthest from done first. A null percent is an open appeal with no
               finish line, which sorts last rather than as if it were at 0%. */
            $px = $x['appeal_percent'] ?? 101;
            $py = $y['appeal_percent'] ?? 101;
            return $px <=> $py;
        });
        return array_slice($out, 0, max(1, min(40, $limit)));
    }

    /** What all the open needs add up to right now — one honest headline figure. */
    public static function needsTotal(): array
    {
        $rows = self::currentNeedsAll(40);
        $today = 0; $week = 0;
        foreach ($rows as $n) {
            if ($n['cadence'] === 'daily')  $today += (int) $n['target_ngn'];
            if ($n['cadence'] === 'weekly') $week  += (int) $n['target_ngn'];
        }
        return ['count' => count($rows), 'today' => $today, 'week' => $week];
    }

    private static function shapeNeed(array $n): array
    {
        foreach (['id', 'appeal_id', 'target_ngn', 'unit_cost', 'units_target', 'met_ngn'] as $k) $n[$k] = (int) ($n[$k] ?? 0);
        $n['lapsed'] = $n['status'] === 'open' && self::periodPassed((string) $n['cadence'], (string) $n['period']);
        return $n;
    }

    /** Has the window this need belongs to already closed? */
    public static function periodPassed(string $cadence, string $period): bool
    {
        if ($cadence === 'once' || $period === '') return false;
        return $period < self::periodFor($cadence);
    }

    /* ── updates: what the money did ─────────────────────────────────────── */

    /**
     * Post an update. `kind` = update | milestone | thanks | spend.
     *
     * A `spend` update carries an amount and is what turns a fundraiser into
     * something accountable: the appeal says what it took in, and then it says
     * what it bought. Most appeals never do the second half, which is exactly
     * why the second half is worth building.
     */
    public static function postUpdate(int $appealId, array $in, string $actor = ''): array
    {
        self::ensure();
        $a = self::byId($appealId);
        if (!$a) return ['ok' => false, 'error' => 'No such appeal.'];
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 160);
        $body  = mb_substr(trim((string) ($in['body'] ?? '')), 0, 20000);
        if ($title === '' && $body === '') return ['ok' => false, 'error' => 'An update needs a title or something to say.'];
        $kind = in_array((string) ($in['kind'] ?? ''), ['update', 'milestone', 'thanks', 'spend'], true) ? (string) $in['kind'] : 'update';
        try {
            Database::pdo()->prepare(
                'INSERT INTO av_appeal_updates (appeal_id,kind,title,body,image_url,amount_ngn,posted_by,created_at)
                 VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$appealId, $kind, $title, $body, self::safeUrl((string) ($in['image_url'] ?? '')),
                           self::money($in['amount_ngn'] ?? 0), mb_substr($actor, 0, 120), self::now()]);
            $updateId = (int) Database::pdo()->lastInsertId();   // before audit() writes its own row
            self::audit('appeal_update', (string) $a['slug'], $actor, $title);
            return ['ok' => true, 'id' => $updateId];
        } catch (Throwable $e) { error_log('[appeals] postUpdate: ' . $e->getMessage()); return ['ok' => false, 'error' => 'Could not post that.']; }
    }

    public static function updatesFor(int $appealId, int $limit = 40): array
    {
        self::ensure();
        $limit = max(1, min(200, $limit));
        try {
            $st = Database::pdo()->prepare('SELECT * FROM av_appeal_updates WHERE appeal_id = ? ORDER BY id DESC LIMIT ' . $limit);
            $st->execute([$appealId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['amount_ngn'] = (int) $r['amount_ngn']; }
            return $rows;
        } catch (Throwable $e) { return []; }
    }

    public static function deleteUpdate(int $id): bool
    {
        self::ensure();
        try { Database::pdo()->prepare('DELETE FROM av_appeal_updates WHERE id = ?')->execute([$id]); return true; }
        catch (Throwable $e) { return false; }
    }

    /* ── tiers: a figure with a consequence attached ─────────────────────── */

    /**
     * Replace an appeal's giving tiers.
     *
     * A tier is an amount with a sentence: "₦5,000 — a week of hot lunches for
     * one child". Somebody deciding what to give is answering "what does this
     * buy", and a bare row of amounts makes them do that arithmetic themselves.
     */
    public static function saveTiers(int $appealId, array $tiers): bool
    {
        self::ensure();
        try {
            $pdo = Database::pdo();
            $pdo->prepare('DELETE FROM av_appeal_tiers WHERE appeal_id = ?')->execute([$appealId]);
            $st = $pdo->prepare('INSERT INTO av_appeal_tiers (appeal_id,amount_ngn,label,impact,sort) VALUES (?,?,?,?,?)');
            $i = 0;
            foreach ($tiers as $t) {
                $amt = self::money($t['amount_ngn'] ?? $t['amount'] ?? 0);
                if ($amt <= 0) continue;
                $st->execute([$appealId, $amt,
                    mb_substr(trim((string) ($t['label'] ?? '')), 0, 80),
                    mb_substr(trim((string) ($t['impact'] ?? '')), 0, 200), $i++]);
                if ($i >= 12) break;                    // a wall of options is not a choice
            }
            return true;
        } catch (Throwable $e) { error_log('[appeals] saveTiers: ' . $e->getMessage()); return false; }
    }

    public static function tiersFor(int $appealId): array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM av_appeal_tiers WHERE appeal_id = ? ORDER BY sort ASC, amount_ngn ASC');
            $st->execute([$appealId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['amount_ngn'] = (int) $r['amount_ngn']; }
            return $rows;
        } catch (Throwable $e) { return []; }
    }

    /* ── the state of an appeal, in the words a page needs ───────────────── */

    /**
     * Everything derived, in one place.
     *
     * The page, the card, the OG image and the embed all need the same handful
     * of answers — how far along, how long left, is a match running. Computing
     * them in four templates is how four surfaces come to disagree about how
     * many days are left.
     */
    public static function state(array $a): array
    {
        $p = self::progress($a);
        $today = self::today();
        $ends = (string) ($a['ends_on'] ?? '');
        $daysLeft = null;
        if ($ends !== '') {
            $d = (strtotime($ends) - strtotime($today)) / 86400;
            $daysLeft = (int) floor($d);
        }
        $matchLive = ((int) ($a['match_ngn'] ?? 0)) > 0
            && ((string) ($a['match_until'] ?? '') === '' || (string) $a['match_until'] >= $today);
        /* How much of the pledge is still live: a sponsor matching to ₦500k when
           ₦300k has come in has ₦200k left to give, and saying so is a far
           stronger ask than repeating the ceiling. */
        $matchLeft = $matchLive ? max(0, (int) $a['match_ngn'] - $p['raised']) : 0;

        return $p + [
            'days_left'   => $daysLeft,
            'ending_soon' => $daysLeft !== null && $daysLeft >= 0 && $daysLeft <= 7,
            'ended'       => $daysLeft !== null && $daysLeft < 0,
            'urgent'      => !empty($a['urgent']),
            'match_live'  => $matchLive,
            'match_left'  => $matchLeft,
            'match_ngn'   => (int) ($a['match_ngn'] ?? 0),
            'sponsor'     => (string) ($a['match_sponsor'] ?? ''),
            'accepting'   => (string) ($a['status'] ?? '') === 'live',
            'spent'       => (int) ($a['spent_ngn'] ?? 0),
        ];
    }

    /* ── sharing ─────────────────────────────────────────────────────────── */

    /** The canonical public address of an appeal. One definition, used by all. */
    public static function url(array $a, string $source = ''): string
    {
        $base = rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/give/' . rawurlencode((string) ($a['slug'] ?? '')) . '/';
        /* A share carries where it came from, so the team can see whether
           WhatsApp or X is actually doing the work. Campaign and medium are
           fixed; only the source varies, which keeps the analytics readable. */
        return $source === '' ? $base
            : $base . '?utm_source=' . rawurlencode($source) . '&utm_medium=share&utm_campaign=' . rawurlencode((string) $a['slug']);
    }

    /** The OG card, which is generated rather than uploaded. */
    public static function ogUrl(array $a): string
    {
        return rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/give/og/' . rawurlencode((string) ($a['slug'] ?? '')) . '.png';
    }

    /**
     * Ready-made share links.
     *
     * WhatsApp first and deliberately: in Nigeria it is where a link actually
     * travels, and a share sheet that leads with X is designed for a different
     * country. Each carries its own utm_source so the difference is measurable
     * rather than assumed.
     */
    public static function shareLinks(array $a): array
    {
        $st = self::state($a);
        $title = (string) ($a['title'] ?? '');
        $line = $title;
        if (!empty($a['tagline'])) $line .= ' — ' . $a['tagline'];
        if ($st['goal'] > 0 && $st['percent'] !== null) {
            $line .= ' (' . $st['percent'] . '% of ' . self::naira($st['goal']) . ' raised)';
        }
        $enc = static fn(string $s): string => rawurlencode($s);
        return [
            'whatsapp' => 'https://wa.me/?text=' . $enc($line . "\n\n" . self::url($a, 'whatsapp')),
            'x'        => 'https://twitter.com/intent/tweet?text=' . $enc($line) . '&url=' . $enc(self::url($a, 'x')),
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $enc(self::url($a, 'facebook')),
            'telegram' => 'https://t.me/share/url?url=' . $enc(self::url($a, 'telegram')) . '&text=' . $enc($line),
            'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $enc(self::url($a, 'linkedin')),
            'email'    => 'mailto:?subject=' . $enc($title) . '&body=' . $enc($line . "\n\n" . self::url($a, 'email')),
            'copy'     => self::url($a),
        ];
    }

    /**
     * A QR code for the appeal, as inline SVG.
     *
     * Vector, not a PNG, because the thing it is for is print — a poster on a
     * noticeboard, a flyer at a service, a slide behind a speaker — and a raster
     * QR blown up to A3 is a QR that will not scan. Error correction Q tolerates
     * a quarter of the symbol being damaged, which is what a logo in the middle
     * and a photocopier between it and a phone actually amount to.
     *
     * Returns '' when the library is absent rather than throwing: a missing
     * vendor tree should cost the page its QR, not its page.
     */
    public static function qrSvg(array $a, string $source = 'qr'): string
    {
        if (!class_exists(\chillerlan\QRCode\QRCode::class)) return '';
        try {
            $opts = new \chillerlan\QRCode\QROptions([
                'outputInterface'      => \chillerlan\QRCode\Output\QRMarkupSVG::class,
                'outputBase64'         => false,
                'eccLevel'             => \chillerlan\QRCode\Common\EccLevel::Q,
                'svgUseFillAttributes' => false,
                'drawLightModules'     => false,
                'quietzoneSize'        => 2,
                'addQuietzone'         => true,
            ]);
            return (new \chillerlan\QRCode\QRCode($opts))->render(self::url($a, $source));
        } catch (Throwable $e) { error_log('[appeals] qr: ' . $e->getMessage()); return ''; }
    }

    /** Count a share. Best-effort — a failed counter must never fail a share. */
    public static function countShare(int $id): void
    {
        self::ensure();
        try { Database::pdo()->prepare('UPDATE av_appeals SET share_count = share_count + 1 WHERE id = ?')->execute([$id]); }
        catch (Throwable $e) { /* a metric, not the point */ }
    }

    public static function countView(int $id): void
    {
        self::ensure();
        try { Database::pdo()->prepare('UPDATE av_appeals SET view_count = view_count + 1 WHERE id = ?')->execute([$id]); }
        catch (Throwable $e) { }
    }

    /* ── SEO ─────────────────────────────────────────────────────────────── */

    /** ₦1,250,000 — one formatter, so no two surfaces punctuate differently. */
    public static function naira(int $n): string { return '₦' . number_format($n); }

    /** The <title> and meta description, falling back to the written copy. */
    public static function meta(array $a): array
    {
        $st = self::state($a);
        $title = trim((string) ($a['seo_title'] ?? '')) ?: (string) ($a['title'] ?? '');
        $desc  = trim((string) ($a['seo_desc'] ?? '')) ?: trim((string) ($a['tagline'] ?? ''));
        if ($desc === '') {
            $desc = trim(mb_substr(strip_tags((string) ($a['story'] ?? '')), 0, 155));
        }
        /* The progress belongs in the description: a search result that already
           says "63% of ₦4,000,000 raised · 118 donors" is a result somebody
           clicks, and it is true at the moment it is rendered. */
        if ($st['goal'] > 0 && $st['percent'] !== null) {
            $desc = rtrim($desc, ' .') . ' · ' . $st['percent'] . '% of ' . self::naira($st['goal']) . ' raised';
            if ($st['donors'] > 0) $desc .= ' · ' . $st['donors'] . ' donor' . ($st['donors'] === 1 ? '' : 's');
        }
        return [
            'title' => mb_substr($title, 0, 65) . ' · Afrovanguard',
            'desc'  => mb_substr($desc, 0, 185),
        ];
    }

    /**
     * The structured data for one appeal.
     *
     * Three types, each chosen because Google actually renders it: Article for
     * the story, Event when the appeal IS one, and BreadcrumbList. DonateAction
     * is included as well — it earns no rich result, but it is the correct
     * vocabulary for what this page is, and the machines reading pages now are
     * not only search crawlers.
     *
     * FAQPage is deliberately absent. Google retired FAQ rich results in May
     * 2026, so marking up an FAQ for the snippet is now work that buys nothing.
     */
    public static function jsonLd(array $a): array
    {
        $url  = self::url($a);
        $base = rtrim(defined('SITE_URL') ? SITE_URL : '', '/');
        $desc = self::meta($a)['desc'];
        /* Reference the Organization by @id rather than restating it. That node
           is emitted once by schema_org(), which the page also passes, and two
           spellings of the same organisation in one graph is how a knowledge
           panel ends up unsure which is the publisher. */
        $orgRef = ['@id' => $base . '/#organization'];
        $graph  = [];

        $article = [
            '@type'            => 'Article',
            '@id'              => $url . '#article',
            'headline'         => mb_substr((string) ($a['title'] ?? ''), 0, 110),
            'description'      => $desc,
            'image'            => self::ogUrl($a),
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'author'           => $orgRef,
            'publisher'        => $orgRef,
        ];
        if (!empty($a['published_at'])) $article['datePublished'] = self::iso((string) $a['published_at']);
        if (!empty($a['updated_at']))   $article['dateModified']  = self::iso((string) $a['updated_at']);
        $graph[] = $article;

        /* An appeal FOR an event is an event, and Google still lists those.
           Only with a real start — an Event without one is rejected anyway. */
        if ((string) ($a['kind'] ?? '') === 'event' && !empty($a['event_start'])) {
            $ev = [
                '@type'       => 'Event',
                'name'        => (string) ($a['title'] ?? ''),
                'description' => $desc,
                'startDate'   => self::iso((string) $a['event_start']),
                'image'       => self::ogUrl($a),
                'url'         => $url,
                'organizer'   => $orgRef,
                'eventStatus' => 'https://schema.org/EventScheduled',
                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                'location'    => [
                    '@type'   => 'Place',
                    'name'    => (string) ($a['event_venue'] ?: ($a['location'] ?: 'Afrovanguard')),
                    'address' => (string) ($a['location'] ?: 'Nigeria'),
                ],
            ];
            if (!empty($a['event_end'])) $ev['endDate'] = self::iso((string) $a['event_end']);
            $graph[] = $ev;
        }

        /* No rich result comes of this one, and it is included anyway: it is the
           correct vocabulary for what the page is, and the machines reading
           pages now are not only search crawlers. FAQPage is deliberately NOT
           here — Google retired FAQ rich results in May 2026, so marking one up
           for the snippet is work that buys nothing. */
        $graph[] = [
            '@type'     => 'DonateAction',
            'name'      => 'Donate to ' . (string) ($a['title'] ?? ''),
            'recipient' => $orgRef,
            'target'    => ['@type' => 'EntryPoint', 'urlTemplate' => $url],
            'priceSpecification' => [
                '@type'         => 'PriceSpecification',
                'priceCurrency' => 'NGN',
                'minPrice'      => defined('MIN_DONATION_AMOUNT') ? (int) MIN_DONATION_AMOUNT : 1000,
            ],
        ];
        return $graph;
    }

    /* ── small shared helpers ────────────────────────────────────────────── */

    /** An ISO-8601 timestamp, or '' for something that is not a date at all. */
    private static function iso(string $s): string
    {
        $t = strtotime($s);
        return $t ? gmdate('c', $t) : '';
    }

    /** '' or YYYY-MM-DD. Anything else is refused rather than half-parsed. */
    private static function validDate(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : '';
    }

    private static function validDateTime(string $s): string
    {
        $s = trim(str_replace('T', ' ', $s));
        if ($s === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $s) ? substr($s, 0, 16) : '';
    }

    /**
     * A URL safe to put in an href or a src.
     *
     * Only http, https and a site-relative path survive. `javascript:` and
     * `data:` are the two that matter: these fields are typed by staff, but
     * "typed by staff" includes a pasted value from somewhere else, and a
     * cover image is rendered into an attribute on a public page.
     */
    private static function safeUrl(string $u): string
    {
        $u = trim($u);
        if ($u === '') return '';
        if (str_starts_with($u, '/')) return mb_substr($u, 0, 500);
        if (preg_match('~^https?://~i', $u)) return mb_substr($u, 0, 500);
        return '';
    }

    /** A gallery is one image URL per line, each run through safeUrl(). */
    private static function galleryLines($in): string
    {
        $lines = is_array($in) ? $in : explode("\n", (string) $in);
        $out = [];
        foreach ($lines as $l) {
            $u = self::safeUrl((string) $l);
            if ($u !== '') $out[] = $u;
            if (count($out) >= 12) break;
        }
        return implode("\n", $out);
    }

    /** Audit trail. Never throws — an unwritable log must not fail a save. */
    private static function audit(string $action, string $subject, string $actor, string $detail = ''): void
    {
        try {
            if (class_exists('AdminAudit')) {
                AdminAudit::log('appeals', $action, $subject, $detail, null,
                                $actor !== '' ? mb_substr($actor, 0, 80) : 'admin');
            }
        } catch (Throwable $e) { /* best effort */ }
    }

    /** Content changed, so the sitemap is stale. Best-effort by design. */
    private static function bumpSitemap(): void
    {
        try { if (class_exists('Sitemap')) Sitemap::rebuild(); }
        catch (Throwable $e) { /* the committed file stays correct */ }
    }

    /* ── totals, for the index page and the console ──────────────────────── */

    public static function summary(): array
    {
        $live = self::published(500);
        $raised = 0; $goal = 0; $donors = 0;
        foreach ($live as $a) {
            $p = self::progress($a);
            $raised += $p['raised']; $goal += $p['goal']; $donors += $p['donors'];
        }
        return ['appeals' => count($live), 'raised' => $raised, 'goal' => $goal, 'donors' => $donors];
    }
}
