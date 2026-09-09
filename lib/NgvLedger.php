<?php
/**
 * lib/NgvLedger.php — NextGen Vanguard money: membership, monthly commitment,
 * the training fee, and fines.
 *
 * ── WHAT THIS IS AND IS NOT ──────────────────────────────────────────────────
 * A LEDGER, not a payment processor. It records what a participant owes and what
 * has been received; staff mark payments as they arrive by transfer or cash to
 * the account printed on the programme schedule. Nothing here touches a card, a
 * gateway or a webhook, and no code in this file moves money. That is
 * deliberate: the moment this subsystem holds card data it becomes a different
 * piece of software with a different threat model, and the ledger has to exist
 * and be trusted first.
 *
 * ── THE FOUR CHARGES ─────────────────────────────────────────────────────────
 *   membership   yearly, while enrolled          (page: "Membership fee")
 *   commitment   monthly, while enrolled         (page: "Commitment fee")
 *   programme    the plan's training fee         (page: the plans table)
 *   fine         somebody's decision, with a reason
 *
 * …and one more, `adjustment`, for the correction that is neither a fine nor a
 * mistake worth erasing.
 *
 * ── WHERE THE AMOUNTS COME FROM ──────────────────────────────────────────────
 * From the PUBLIC PAGE, not from a second copy in a settings screen.
 *
 * /academy/ngv/ already states "₦10,000 / year" and "₦1,000 / month" and prices
 * every plan, and all of that is admin-editable in the Studio. A ledger with its
 * own figures would drift from the page the day somebody edited one of them, and
 * the first anyone would learn of it is a participant holding a receipt that
 * disagrees with the website. So `amounts()` reads the live content and parses
 * it. An admin who needs a figure the page cannot express can pin it in the
 * settings document — and `source` on every amount says which happened, so a
 * staff screen can show where a number came from rather than just asserting it.
 *
 * ── THE TRAINING FEE IS NOT ACCRUED BY DEFAULT, AND THAT IS THE POINT ────────
 * A participant picks their own plan on their dashboard. If accrual posted the
 * plan's tuition, a school leaver clicking "Full Programme" out of curiosity
 * would give themselves a ₦240,000 debt, and the ledger would be right to insist
 * on it. So the training fee is RAISED BY STAFF, once, from the plan catalogue —
 * one click, prefilled, audited, idempotent for the period. `trainingAuto` turns
 * automatic accrual on for an organisation that wants it; it is off out of the
 * box and the console says why.
 *
 * NGV has no earn-off. NGG writes its training fee down as a member SERVES,
 * because service is what discharges it there. NGV's Phase 2 is a *paid*
 * internship with weekly stipends — the participant is already being paid for
 * that time, so writing the fee down as well would be paying twice. Where a fee
 * should not be collected, that is a WAIVER: a decision somebody makes and signs
 * their name to, not an arithmetic side effect.
 *
 * ── "NO ONE IS TURNED AWAY FOR LACK" ─────────────────────────────────────────
 * That sentence is on the public page, and it is the reason `waive()` is a
 * first-class operation here rather than an afterthought. A ledger that can only
 * charge is a ledger that quietly contradicts its own programme's promise. A
 * waiver keeps the charge visible and records who set it aside and why, which is
 * what makes the promise auditable instead of merely stated.
 *
 * ── WHAT DELIBERATELY DOES NOT HAPPEN ────────────────────────────────────────
 * Nothing compounds; there is no interest and none should be added. A balance is
 * capped (`balanceCap`) so it cannot run away into a figure nobody will ever pay.
 * No financial state gates anything: nothing here is read by attendance, the
 * dashboard's learning sections, certification, or the public site.
 *
 * And there is no automatic uprating. NGG raises its amounts on a schedule
 * because they live in a settings row nobody looks at; NGV's live on a public
 * page an admin edits by hand, and a cron that rewrote that page would change
 * what the programme advertises without anyone deciding to. Instead
 * `reviewDue()` says out loud that the figures have not been looked at in a
 * year, and a human goes and looks.
 */
declare(strict_types=1);

final class NgvLedger
{
    /* ── Bounds, all of them named ──────────────────────────────────────────
     * Every number this file enforces lives here rather than inline where it
     * bites, and the ones a screen has to STATE are handed to the screen by
     * `bounds()` instead of being retyped into the markup. A floor written as
     * `max(7, …)` here and as `|| 7` in a template drifts the day one of them
     * changes, and the drift shows up as staff being told one minimum and given
     * another. */

    /** Whole naira. A ceiling on every settable amount, so a slipped keystroke
     *  in a staff form cannot post a ₦900,000,000 charge. */
    public const AMOUNT_MAX = 10000000;
    /** The ceiling one participant's account may reach. Reached, accrual stops
     *  and says so rather than growing into a number nobody will ever pay.
     *  Zero means no ceiling — available, but not the default. */
    public const CAP_DEFAULT = 500000;
    /** Reminder cadence. The floor exists because "constant" reminders are how a
     *  programme gets its sender blocked and its participants to stop reading
     *  anything it sends. */
    public const REMIND_MIN_DAYS = 7;
    public const REMIND_MAX_DAYS = 365;
    /** Batch sizes. Each is a bound on how much one press or one cron tick may
     *  do, so a mis-click cannot walk the whole roster. */
    public const ACCRUE_BATCH = 300;
    public const SCAN_MAX = 2000;
    public const REMIND_BATCH = 25;
    public const REMIND_BATCH_MAX = 200;
    public const REMIND_CRON_BATCH = 10;
    public const CANDIDATE_MAX = 200;
    public const ARREARS_PAGE = 200;
    public const ARREARS_PAGE_MAX = 1000;
    public const LOOKUP_MAX = 25;
    /** Periods one accrual may reach back over. A row imported with a start date
     *  of 1970 must not post six hundred charges. */
    public const PERIODS_MAX = 120;
    /** How long the amounts may go unreviewed before `reviewDue()` says so. */
    public const REVIEW_MONTHS = 12;
    /** While a review is overdue, journal it at most this often — the cron runs
     *  on every tick and a nudge that repeats per tick is noise, not a nudge. */
    public const REVIEW_NOTE_DAYS = 30;

    /** Charge kinds. `programme` is the training fee; the page calls the monthly
     *  one a "commitment" rather than "dues", and so does this. */
    public const CHARGE_KINDS = ['membership', 'commitment', 'programme', 'fine', 'adjustment'];
    /** The two a human posts, as opposed to the three the accrual posts. Each
     *  needs a REASON on the row and is audited with the reason in it: "who
     *  decided this, and why" is the first question asked about any charge, and
     *  it has to be answerable from the ledger alone. */
    public const MANUAL_CHARGE_KINDS = ['fine', 'adjustment'];
    /** Credit kinds. `payment` is money received; `waiver` is money set aside;
     *  `writeoff` is a balance the programme has decided to stop carrying. */
    public const CREDIT_KINDS = ['payment', 'waiver', 'writeoff'];
    /** Which fee line a credit may be allocated to. `other` is the unallocated
     *  pool — it reduces the account rather than a specific line, and it is what
     *  every payment recorded before this ledger existed lands in. */
    public const CREDIT_LINES = ['membership', 'commitment', 'programme', 'fine', 'adjustment', 'other'];

    /**
     * Why a fine was issued. A fixed vocabulary rather than free text, because a
     * fine is the one charge a participant will dispute, and "late four times in
     * August" is answerable where "misconduct" is not. `other` exists so nobody
     * mislabels to fit the list — and it REQUIRES a written reason.
     *
     * Tuned to what NGV actually is: a five-day-a-week programme with a 7:00 AM
     * resumption, a uniform, an ID card and real project equipment. These are
     * the things that go wrong there.
     */
    public const FINE_REASONS = [
        'late'      => 'Late arrival',
        'absent'    => 'Absent without notice',
        'uniform'   => 'Uniform or ID card',
        'equipment' => 'Equipment lost or damaged',
        'conduct'   => 'Conduct',
        'other'     => 'Other (say why)',
    ];

    private const SETTINGS_KEY = 'ngv_fees';
    private static ?array $cache = null;

    /** Fallbacks, used only when the public page states no figure this can
     *  parse. They match the page as written today. */
    public const MEMBERSHIP_FALLBACK = 10000;
    public const COMMITMENT_FALLBACK = 1000;

    /* ══ Settings ═══════════════════════════════════════════════════════════ */

    public static function defaults(): array
    {
        return [
            /* The ledger runs at all. Off means: nothing accrues, nothing is
               chased, and the dashboard says so plainly instead of showing a
               silent void that reads as broken. */
            'enabled'        => false,
            'currency'       => 'NGN',
            /* null = read the figure off the public page. An integer pins it. */
            'membershipYearly'  => null,
            'commitmentMonthly' => null,
            /* The training fee is raised by staff by default — see the header. */
            'trainingAuto'   => false,
            /* The default shape of a training-fee commitment, used by the staff
               form and by `trainingAuto`. Twelve because the flagship plan is
               priced per year; 1 means "in full". */
            'trainingInstalments' => 12,
            /* ── The rollout guard ────────────────────────────────────────
             * Switching accrual on for a programme that has been running for a
             * year would post twelve months of commitment charges to every
             * participant on the first tick, and the first anyone would hear of
             * it is a cohort of young people opening their dashboard to a debt
             * nobody discussed with them.
             *
             * So no charge is posted for a period beginning before this date. It
             * is stamped with the month accrual is first enabled unless an admin
             * sets it deliberately, which makes the safe thing the default and
             * back-dating an explicit act. */
            'accrueFrom'     => '',
            'balanceCap'     => self::CAP_DEFAULT,
            'remindEnabled'  => false,
            'remindEveryDays' => 21,
            'remindMinBalance' => 1,
            /* When a human last confirmed the amounts are still right, and when
               the nudge about it was last journalled. Bookkeeping, not policy —
               only `markReviewed()` and `noteReviewDue()` write them. */
            'reviewedAt'     => '',
            'reviewNotedAt'  => '',
        ];
    }

    public static function settings(): array
    {
        if (self::$cache !== null) return self::$cache;
        $raw = null;
        try { $raw = Database::metaGet(self::SETTINGS_KEY); } catch (Throwable $e) {}
        $over = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return self::$cache = (is_array($over) ? $over : []) + self::defaults();
    }

    /** The bounds a screen has to state, in the shape it reads them. */
    public static function bounds(): array
    {
        return [
            'amountMax'     => self::AMOUNT_MAX,
            'remindMinDays' => self::REMIND_MIN_DAYS,
            'remindMaxDays' => self::REMIND_MAX_DAYS,
            'remindBatch'   => self::REMIND_BATCH_MAX,
            'accrueBatch'   => self::ACCRUE_BATCH,
            'arrearsPage'   => self::ARREARS_PAGE,
            'reviewMonths'  => self::REVIEW_MONTHS,
            'capDefault'    => self::CAP_DEFAULT,
            'trainingMonthsMax' => self::TRAINING_MONTHS_MAX,
        ];
    }

    /**
     * Save the settings. Every field is clamped here rather than at the form,
     * and the two bookkeeping stamps are CARRIED rather than settable: a
     * `reviewedAt` an admin can type is a field that can be used to silence the
     * review nudge without reviewing anything.
     */
    public static function saveSettings(array $in, string $by = 'admin'): array
    {
        $cur = self::settings();
        $bool = static fn(string $k) => array_key_exists($k, $in) ? !empty($in[$k]) : !empty($cur[$k]);
        $pin = static function (string $k) use ($in, $cur) {
            if (!array_key_exists($k, $in)) return $cur[$k];
            $v = $in[$k];
            if ($v === null || $v === '' || $v === 'auto') return null;    // back to the page's figure
            return self::money($v);
        };
        $wasEnabled = !empty($cur['enabled']);
        $clean = [
            'enabled'           => $bool('enabled'),
            'currency'          => 'NGN',
            'membershipYearly'  => $pin('membershipYearly'),
            'commitmentMonthly' => $pin('commitmentMonthly'),
            'trainingAuto'      => $bool('trainingAuto'),
            'trainingInstalments' => max(1, min(self::TRAINING_MONTHS_MAX,
                                     (int) ($in['trainingInstalments'] ?? $cur['trainingInstalments']) ?: 1)),
            'accrueFrom'        => self::validDate((string) ($in['accrueFrom'] ?? $cur['accrueFrom'])),
            /* A negative figure KEEPS the current ceiling rather than clamping to
               zero, because zero here means "no ceiling at all" — so a slipped
               minus sign would silently remove the protection it was aimed at.
               Somebody who genuinely wants no ceiling types 0. */
            'balanceCap'        => (float) ($in['balanceCap'] ?? 0) < 0
                                     ? self::money($cur['balanceCap'])
                                     : self::money($in['balanceCap'] ?? $cur['balanceCap']),
            'remindEnabled'     => $bool('remindEnabled'),
            /* Floored, not just rejected. An admin who types 1 gets the floor and
               is told, rather than getting a daily chase nobody asked to defend. */
            'remindEveryDays'   => max(self::REMIND_MIN_DAYS, min(self::REMIND_MAX_DAYS,
                                     (int) ($in['remindEveryDays'] ?? $cur['remindEveryDays']) ?: (int) $cur['remindEveryDays'])),
            'remindMinBalance'  => self::money($in['remindMinBalance'] ?? $cur['remindMinBalance']),
            'reviewedAt'        => (string) ($cur['reviewedAt'] ?? ''),
            'reviewNotedAt'     => (string) ($cur['reviewNotedAt'] ?? ''),
        ];
        /* First time it is switched on, stamp the guard at this month. Nothing
           before it is ever charged, so enabling the ledger on a programme with
           history is safe by default and back-dating is a deliberate edit. */
        if ($clean['enabled'] && !$wasEnabled && $clean['accrueFrom'] === '') {
            $clean['accrueFrom'] = self::today('Y-m') . '-01';
        }
        self::write($clean);
        self::audit('ngv_fees_settings', 'ngv:fees',
            'Fees ' . ($clean['enabled'] ? 'on' : 'off')
            . ' · reminders ' . ($clean['remindEnabled'] ? 'on every ' . $clean['remindEveryDays'] . 'd' : 'off')
            . ' · training ' . ($clean['trainingAuto'] ? 'auto' : 'raised by staff')
            . ' · from ' . ($clean['accrueFrom'] ?: 'not set'), $by);
        return $clean;
    }

    /** Record that a human has looked at the amounts today. Clears the nudge. */
    public static function markReviewed(string $by = 'admin'): array
    {
        $cfg = self::settings();
        $cfg['reviewedAt'] = self::today('Y-m-d');
        $cfg['reviewNotedAt'] = '';
        self::write($cfg);
        self::audit('ngv_fees_reviewed', 'ngv:fees', 'Fee amounts confirmed as current', $by);
        return $cfg;
    }

    private static function write(array $cfg): void
    {
        try { Database::metaSet(self::SETTINGS_KEY, json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
        catch (Throwable $e) { error_log('[ngvledger] settings write: ' . $e->getMessage()); }
        self::$cache = null;
    }

    public static function enabled(): bool { return !empty(self::settings()['enabled']); }

    /* ══ Amounts, read off the public page ══════════════════════════════════ */

    /**
     * "₦10,000 / year" → 10000 a year. Returns [amount, cadence] where cadence
     * is one of year|month|once. Digits only: a label is a label, and anything
     * that is not a figure is not a price.
     */
    public static function parseAmount(string $label): array
    {
        $digits = preg_replace('/\D/', '', $label);
        $amount = $digits === '' ? 0 : min(self::AMOUNT_MAX, (int) $digits);
        $l = strtolower($label);
        $cadence = 'once';
        if (preg_match('/\b(month|monthly|mo|pm)\b|\/\s*month/', $l))            $cadence = 'month';
        elseif (preg_match('/\b(year|yearly|yr|annum|annual|pa)\b|\/\s*year/', $l)) $cadence = 'year';
        return ['amount' => $amount, 'cadence' => $cadence];
    }

    /**
     * The figures the ledger charges, and where each came from.
     *
     * Matching on the fee row's NAME rather than its position, because an admin
     * reordering the two rows on the page must not swap the yearly and the
     * monthly charge on everybody's account.
     */
    public static function amounts(): array
    {
        $cfg = self::settings();
        $rows = [];
        if (class_exists('Ngv')) {
            $c = Ngv::get();
            foreach (is_array($c['fees'] ?? null) ? $c['fees'] : [] as $f) $rows[] = $f;
        }
        $find = static function (string $needle) use ($rows): ?array {
            foreach ($rows as $f) {
                if (stripos((string) ($f['name'] ?? ''), $needle) !== false) return $f;
            }
            return null;
        };
        $line = static function (?array $row, string $wantCadence, int $fallback, $pinned) {
            if ($pinned !== null && $pinned !== '') {
                return ['amount' => (int) $pinned, 'cadence' => $wantCadence, 'source' => 'pinned',
                        'label' => '', 'desc' => ''];
            }
            if ($row !== null) {
                $p = self::parseAmount((string) ($row['amount'] ?? ''));
                if ($p['amount'] > 0) {
                    return ['amount' => $p['amount'],
                            /* Trust the page's own cadence when it states one; the
                               row's position in the list is not evidence. */
                            'cadence' => $p['cadence'] !== 'once' ? $p['cadence'] : $wantCadence,
                            'source' => 'page', 'label' => (string) ($row['amount'] ?? ''),
                            'desc' => (string) ($row['desc'] ?? '')];
                }
            }
            return ['amount' => $fallback, 'cadence' => $wantCadence, 'source' => 'fallback',
                    'label' => '', 'desc' => ''];
        };
        return [
            'membership' => $line($find('membership'), 'year', self::MEMBERSHIP_FALLBACK, $cfg['membershipYearly'] ?? null),
            'commitment' => $line($find('commitment'), 'month', self::COMMITMENT_FALLBACK, $cfg['commitmentMonthly'] ?? null),
            'plans'      => self::planCatalogue(),
            'note'       => class_exists('Ngv') ? (string) (Ngv::get()['fees_note'] ?? '') : '',
            'payTo'      => class_exists('Ngv') ? (string) (Ngv::get()['schedule']['payment'] ?? '') : '',
        ];
    }

    /**
     * The plans, with their fee parsed off the same page that advertises them.
     * "Training Only" and "Internship Only" are free; "Full Programme" carries
     * the tuition. Parsed rather than duplicated so the dashboard and the page
     * can never quote different money.
     */
    public static function planCatalogue(): array
    {
        $out = [];
        if (!class_exists('Ngv')) return $out;
        foreach ((Ngv::get()['plans'] ?? []) as $pl) {
            $name = trim((string) ($pl['name'] ?? ''));
            if ($name === '') continue;
            $p = self::parseAmount((string) ($pl['price'] ?? ''));
            $noteCadence = self::parseAmount((string) ($pl['price_note'] ?? ''))['cadence'];
            $out[$name] = [
                'name'       => $name,
                'fee'        => $p['amount'],
                /* A price of "₦240,000" with a note of "per year" is a yearly
                   fee; the cadence is on the note, not the figure. */
                'cadence'    => $p['cadence'] !== 'once' ? $p['cadence'] : $noteCadence,
                'priceLabel' => (string) ($pl['price'] ?? ''),
                'note'       => trim((string) ($pl['price_note'] ?? '')),
                'duration'   => (string) ($pl['duration'] ?? ''),
                'desc'       => (string) ($pl['desc'] ?? ''),
                'enabled'    => !empty($pl['enabled']),
            ];
        }
        return $out;
    }

    /** Is a review of the amounts overdue, and by how long? Says so; never acts. */
    public static function reviewDue(?string $asOf = null): array
    {
        $cfg = self::settings();
        $today = $asOf ?: self::today('Y-m-d');
        $last = self::validDate((string) ($cfg['reviewedAt'] ?? ''));
        /* Never reviewed: measure from the day fees were switched on, not from
           nothing. "Due now" on the morning somebody enables the ledger is a
           nag about a figure they have just finished setting, and a nudge that
           fires on day one is a nudge people learn to ignore. With no anchor at
           all — an older settings row that predates the stamp — nothing is due,
           because there is genuinely nothing to measure. */
        $anchor = $last !== '' ? $last : self::validDate((string) ($cfg['accrueFrom'] ?? ''));
        $months = $anchor === '' ? null : self::monthsBetween($anchor, $today);
        return [
            'due'        => !empty($cfg['enabled']) && $months !== null && $months >= self::REVIEW_MONTHS,
            'lastAt'     => $last,
            'anchoredOn' => $anchor,
            'months'     => $months,
            'everyMonths' => self::REVIEW_MONTHS,
        ];
    }

    /**
     * Say out loud that a review is due. Journals; never changes an amount.
     *
     * The gap this closes: figures that are only visible on the page where they
     * are set are figures nobody revisits, because nothing tells you to go and
     * look. Naira inflation erodes a fixed amount fast, and a programme that
     * never revisits its fees is quietly taking a pay cut.
     *
     * Two properties. It CANNOT change anything — a nudge that could raise
     * prices from a cron is exactly the automatic uprate this design refuses.
     * And it is bounded to once every REVIEW_NOTE_DAYS, because the cron ticks
     * often and an unbounded nudge is not a reminder, it is a log filling up.
     */
    public static function noteReviewDue(?string $asOf = null): array
    {
        $cfg = self::settings();
        $r = self::reviewDue($asOf);
        if (empty($r['due'])) return ['ok' => false, 'error' => 'not_due'];
        $today = $asOf ?: self::today('Y-m-d');
        $noted = self::validDate((string) ($cfg['reviewNotedAt'] ?? ''));
        if ($noted !== '' && self::daysBetween($noted, $today) < self::REVIEW_NOTE_DAYS) {
            return ['ok' => false, 'error' => 'noted_recently'];
        }
        $cfg['reviewNotedAt'] = $today;
        self::write($cfg);
        self::audit('ngv_fees_review_due', 'ngv:fees',
            'NGV fee amounts have not been reviewed'
            . ($r['lastAt'] === '' ? ' at all' : ' since ' . $r['lastAt'])
            . ' — check /academy/ngv/edit.php against what is being charged', 'cron');
        return ['ok' => true, 'lastAt' => $r['lastAt'], 'months' => $r['months']];
    }

    /* ══ Posting ════════════════════════════════════════════════════════════ */

    /**
     * Post a charge, or leave the existing one alone.
     *
     * Idempotent on (member_id, kind, period), so an accrual run that fires
     * twice — a cron that overlaps, a staff member who presses the button again
     * — cannot charge the same month twice. The unique index is the guarantee;
     * the SELECT is the courteous path that avoids burning an autoincrement id
     * on every no-op, and the INSERT-IGNORE closes the race between them.
     *
     * Returns the new row's id, or null when nothing was created. An id rather
     * than a bare true because a caller may need to point at the charge it just
     * made — a damage record links to the fine raised for it — and an int is
     * still truthy, so `if (postCharge(...))` reads the same as it always did.
     */
    public static function postCharge(int $memberId, string $kind, int $amount, string $period, array $opt = []): ?int
    {
        if ($memberId <= 0 || $amount <= 0) return null;
        if (!in_array($kind, self::CHARGE_KINDS, true)) return null;
        $amount = self::money($amount);
        if ($amount <= 0) return null;
        $pdo = NgvDb::pdo();
        $st = $pdo->prepare('SELECT id FROM ngv_charges WHERE member_id = ? AND kind = ? AND period = ?');
        $st->execute([$memberId, $kind, $period]);
        if ($st->fetchColumn() !== false) return null;
        $cols = ['member_id', 'kind', 'amount', 'currency', 'period', 'reason', 'note', 'source', 'created_by', 'created_at'];
        // `created_at` takes the portable now-expression rather than a bound
        // value, so both halves of the ledger are stamped by the same clock.
        $sql = NgvDb::insertIgnore('ngv_charges', $cols,
            implode(', ', array_fill(0, count($cols) - 1, '?')) . ', ' . NgvDb::nowExpr());
        try {
            $pdo->prepare($sql)->execute([
                $memberId, $kind, $amount, 'NGN', mb_substr($period, 0, 40),
                mb_substr((string) ($opt['reason'] ?? ''), 0, 32),
                mb_substr(trim((string) ($opt['note'] ?? '')), 0, 200),
                in_array((string) ($opt['source'] ?? ''), ['accrual', 'staff'], true) ? (string) $opt['source'] : 'accrual',
                max(0, (int) ($opt['by'] ?? 0)),
            ]);
        } catch (Throwable $e) {
            error_log('[ngvledger] postCharge: ' . $e->getMessage());
            return null;
        }
        /* Absent a moment ago and present now. In the rare lost race two callers
           both report having created it, which over-counts an accrual's report by
           one — the unique index has already done the job that matters, which is
           that only one row exists. */
        $st->execute([$memberId, $kind, $period]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Live (non-void) charges for a participant, oldest first. */
    public static function charges(int $memberId): array
    {
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_charges WHERE member_id = ? AND voided = 0 ORDER BY id');
        $st->execute([$memberId]);
        return $st->fetchAll() ?: [];
    }

    /** Live (non-void) credits for a participant, newest first. */
    public static function credits(int $memberId): array
    {
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_payments WHERE member_id = ? AND voided = 0 ORDER BY created_at DESC, id DESC');
        $st->execute([$memberId]);
        return $st->fetchAll() ?: [];
    }

    /* ══ Accrual ════════════════════════════════════════════════════════════ */

    /**
     * Bring one participant's charges up to date.
     *
     * Idempotent by construction (see `postCharge`), so it is safe to run from a
     * cron, from a staff button, and from both at once.
     *
     * The cap is enforced HERE rather than only displayed: at the ceiling,
     * accrual stops. A ledger that keeps charging past what anyone could pay is
     * generating a number for its own sake, and the arrears it records stop
     * being a fact about the participant and become a fact about the software.
     */
    public static function accrueParticipant(array $p, ?string $asOf = null): array
    {
        $out = ['membership' => 0, 'commitment' => 0, 'programme' => 0, 'skipped' => ''];
        $cfg = self::settings();
        if (empty($cfg['enabled'])) { $out['skipped'] = 'disabled'; return $out; }
        $memberId = (int) ($p['member_id'] ?? 0);
        if ($memberId <= 0) { $out['skipped'] = 'no_member'; return $out; }
        /* Only the enrolled accrue. An applicant has not started; someone paused,
           withdrawn or completed has stopped, and charging them for the months
           since is charging for a place they are not taking up. */
        if ((string) ($p['status'] ?? '') !== 'active') { $out['skipped'] = 'not_active'; return $out; }

        $start = self::startDate($p);
        if ($start === '') { $out['skipped'] = 'no_start_date'; return $out; }
        $floor = self::validDate((string) ($cfg['accrueFrom'] ?? ''));
        if ($floor !== '' && $floor > $start) $start = $floor;   // the rollout guard
        $today = $asOf ?: self::today('Y-m-d');
        if ($start > $today) { $out['skipped'] = 'starts_later'; return $out; }

        if (self::atCap($memberId, $cfg)) { $out['skipped'] = 'at_cap'; return $out; }

        $amt = self::amounts();

        $membership = (int) $amt['membership']['amount'];
        if ($membership > 0) {
            foreach (self::periods($start, $today, $amt['membership']['cadence']) as $k) {
                if (self::postCharge($memberId, 'membership', $membership, $k,
                        ['note' => 'Membership ' . $k, 'source' => 'accrual'])) $out['membership']++;
            }
        }
        $commitment = (int) $amt['commitment']['amount'];
        if ($commitment > 0) {
            foreach (self::periods($start, $today, $amt['commitment']['cadence']) as $k) {
                if (self::postCharge($memberId, 'commitment', $commitment, $k,
                        ['note' => 'Commitment ' . $k, 'source' => 'accrual'])) $out['commitment']++;
            }
        }
        /* The training fee follows the COMMITMENT agreed with this person, not
           the plan they clicked — see the training-fee section. With a schedule
           running, each instalment lands as its month arrives; with none, the
           accrual does not invent one.
           `trainingAuto` is the escape hatch for an organisation that wants the
           plan price charged without anybody agreeing it first. Off by default,
           and it starts a schedule rather than posting a lump sum. */
        if (self::trainingPlanOf($p) !== null) {
            $out['programme'] = self::accrueTraining($memberId, $asOf);
        } elseif (!empty($cfg['trainingAuto'])) {
            $plan = self::planFor($p);
            if ($plan !== null && (int) $plan['fee'] > 0) {
                $r = self::startTrainingFee($memberId, (int) ($cfg['trainingInstalments'] ?? 1), 0, null, $asOf);
                if (!empty($r['ok'])) $out['programme'] = (int) $r['posted'];
            }
        }
        return $out;
    }

    /** Run accrual across the enrolled roster, and report what it did per kind
     *  rather than as one total — "it charged 412 things" is not checkable. */
    public static function accrueAll(int $limit = self::ACCRUE_BATCH, ?string $asOf = null): array
    {
        $cfg = self::settings();
        if (empty($cfg['enabled'])) return ['ok' => false, 'error' => 'disabled'];
        $limit = max(1, min(self::SCAN_MAX, $limit));
        $st = NgvDb::pdo()->prepare("SELECT * FROM ngv_participants WHERE status = 'active' ORDER BY id LIMIT " . $limit);
        $st->execute();
        $tot = ['participants' => 0, 'membership' => 0, 'commitment' => 0, 'programme' => 0,
                'atCap' => 0, 'noStartDate' => 0];
        foreach ($st->fetchAll() ?: [] as $p) {
            $r = self::accrueParticipant($p, $asOf);
            $tot['participants']++;
            $tot['membership'] += $r['membership'];
            $tot['commitment'] += $r['commitment'];
            $tot['programme']  += $r['programme'];
            if ($r['skipped'] === 'at_cap')        $tot['atCap']++;
            if ($r['skipped'] === 'no_start_date') $tot['noStartDate']++;
        }
        return ['ok' => true, 'accrued' => $tot, 'limit' => $limit];
    }

    /* ══ Staff operations ═══════════════════════════════════════════════════ */

    /* ── The training fee ────────────────────────────────────────────────
     *
     * ₦240,000 is a year of tuition and, for a school leaver, roughly a year of
     * income. Posted as one charge it is a wall: the dashboard reads ₦240,000
     * outstanding from the first day to the last, the arrears list puts that
     * person permanently at the top, and a reminder quotes a figure nobody could
     * pay this month. None of that is information — it is the same fact, shouted
     * every fortnight.
     *
     * So the fee is a COMMITMENT with a shape: a total, a start month, and a
     * number of monthly instalments. Each instalment becomes an ordinary charge
     * as its month arrives, through the same idempotent accrual as everything
     * else, so "₦20,000 due this month, 3 of 12 settled" is what a participant
     * actually sees. Paying in full is the same machinery with one instalment.
     *
     * `training_total` is frozen when the commitment is made. A later edit to
     * the plan price on the public page changes what the NEXT person is quoted
     * and moves nothing for somebody already paying — the one property that
     * makes a schedule trustworthy.
     */

    /** How many monthly instalments a commitment may be split into. Two years:
     *  past that it is not a training fee, it is a loan. */
    public const TRAINING_MONTHS_MAX = 24;

    /**
     * The commitment stored against a participant, expanded into instalments.
     * Pure arithmetic — no database read — so the accrual, the staff console and
     * the member dashboard all describe the same schedule.
     *
     * The remainder rides on the LAST instalment. Putting it on the first would
     * make the opening bill the biggest one, which is exactly backwards for
     * somebody deciding whether they can start at all.
     */
    public static function trainingPlanOf(array $p): ?array
    {
        $months = max(0, (int) ($p['training_months'] ?? 0));
        $total  = max(0, (int) ($p['training_total'] ?? 0));
        $from   = trim((string) ($p['training_from'] ?? ''));
        if ($months < 1 || $total < 1 || !preg_match('/^\d{4}-\d{2}$/', $from)) return null;
        $each = max(0, (int) ($p['training_each'] ?? 0)) ?: (int) floor($total / $months);
        $ins = [];
        $t = strtotime($from . '-01 00:00:00 UTC');
        $running = 0;
        for ($i = 1; $i <= $months; $i++) {
            $amount = $i === $months ? max(0, $total - $running) : $each;
            $running += $amount;
            $ins[] = ['n' => $i, 'period' => gmdate('Y-m', (int) $t), 'amount' => $amount];
            $t = strtotime('+1 month', (int) $t);
            if ($t === false) break;
        }
        return ['from' => $from, 'months' => $months, 'each' => $each, 'total' => $total,
                'instalments' => $ins];
    }

    /**
     * The schedule with each instalment's state — what a member wants to see:
     * what each month costs, which have been charged, and what is still coming.
     */
    public static function trainingSchedule(int $memberId, ?string $asOf = null): array
    {
        $p = self::participantRow($memberId);
        if (!$p) return [];
        $plan = self::trainingPlanOf($p);
        if ($plan === null) return [];
        $posted = [];
        foreach (self::charges($memberId) as $c) {
            if ((string) $c['kind'] === 'programme') $posted[(string) $c['period']] = (int) $c['amount'];
        }
        $paid = (int) (self::balance($memberId)['paidBy']['programme'] ?? 0);
        $thisMonth = self::periodKey($asOf ?: self::today('Y-m-d'), 'month');
        $out = [];
        foreach ($plan['instalments'] as $ins) {
            $ins['state'] = isset($posted[$ins['period']])
                ? 'charged'
                : ($ins['period'] > $thisMonth ? 'upcoming' : 'due');
            /* The posted figure wins where one exists: a charge already on the
               account is the truth, whatever the schedule would recompute. */
            if (isset($posted[$ins['period']])) $ins['amount'] = $posted[$ins['period']];
            $out[] = $ins;
        }
        return ['from' => $plan['from'], 'months' => $plan['months'], 'total' => $plan['total'],
                'paid' => $paid, 'instalments' => $out,
                'settled' => count(array_filter($out, static fn($i) => $i['state'] === 'charged'))];
    }

    /**
     * Agree a training fee with a participant: a total, and how many months to
     * spread it over.
     *
     * This is the path the training fee normally takes, and the reason it is a
     * separate operation rather than a line in the accrual: the plan is
     * self-selected, so somebody has to confirm that this participant really is
     * on the paid programme — and agree the shape of it with them — before the
     * ledger will believe it.
     *
     * Refuses while a `programme` charge is already live, rather than stacking a
     * second commitment on top of an unfinished one. Voiding the old charges, or
     * stopping the old schedule, is a decision somebody makes on purpose.
     */
    public static function startTrainingFee(int $memberId, int $months, int $byUid, $amount = null, ?string $asOf = null): array
    {
        $p = self::participantRow($memberId);
        if (!$p) return ['ok' => false, 'error' => 'No such participant.'];
        $plan = self::planFor($p);
        $total = $amount === null || $amount === '' ? ($plan ? (int) $plan['fee'] : 0) : self::money($amount);
        if ($plan === null && $total <= 0) return ['ok' => false, 'error' => 'They have not chosen a plan yet.'];
        if ($total <= 0) {
            return ['ok' => false, 'error' => ($plan ? $plan['name'] : 'That plan') . ' is free — there is no training fee to agree.'];
        }
        foreach (self::charges($memberId) as $c) {
            if ((string) $c['kind'] === 'programme') {
                return ['ok' => false, 'error' => 'A training fee is already running on this account. '
                    . 'Stop the schedule, or void the charges, before agreeing a new one.'];
            }
        }
        $months = max(1, min(self::TRAINING_MONTHS_MAX, $months));
        $from = self::periodKey($asOf ?: self::today('Y-m-d'), 'month');
        $each = (int) floor($total / $months);
        NgvDb::pdo()->prepare('UPDATE ngv_participants SET training_from = ?, training_months = ?, training_each = ?,
                               training_total = ?, updated_at = ' . NgvDb::nowExpr() . ' WHERE member_id = ?')
            ->execute([$from, $months, $each, $total, $memberId]);
        self::audit('ngv_fee_training', 'ngv:member:' . $memberId,
            'Training fee agreed — ' . self::money_text($total)
            . ($months > 1 ? ' over ' . $months . ' months (' . self::money_text($each) . ' each)' : ' in full')
            . ' from ' . $from . ($plan ? ' · ' . $plan['name'] : ''));
        /* Post whatever is already due. Agreeing a schedule in March that starts
           in March should put March on the account now, not next cron tick. */
        $posted = self::accrueTraining($memberId, $asOf, $byUid);
        return ['ok' => true, 'total' => $total, 'months' => $months, 'each' => $each,
                'from' => $from, 'posted' => $posted,
                'schedule' => self::trainingSchedule($memberId, $asOf)];
    }

    /**
     * Stop future instalments. What a withdrawal, a switch to a free plan, or a
     * renegotiation leaves behind.
     *
     * Charges already posted STAY. Somebody who paid four instalments and left
     * paid four instalments; erasing them would be rewriting what happened.
     * Whether the unpaid ones should still be asked for is a separate decision,
     * with its own name: waive it, or write it off.
     */
    public static function stopTrainingFee(int $memberId): array
    {
        $p = self::participantRow($memberId);
        if (!$p) return ['ok' => false, 'error' => 'No such participant.'];
        if (self::trainingPlanOf($p) === null) return ['ok' => false, 'error' => 'No training schedule is running.'];
        NgvDb::pdo()->prepare('UPDATE ngv_participants SET training_months = 0, updated_at = '
            . NgvDb::nowExpr() . ' WHERE member_id = ?')->execute([$memberId]);
        self::audit('ngv_fee_training_stop', 'ngv:member:' . $memberId,
            'Training schedule stopped — no further instalments will be charged. '
            . 'Instalments already posted stay on the account.');
        return ['ok' => true];
    }

    /** Post every instalment whose month has arrived. Idempotent, like the rest. */
    private static function accrueTraining(int $memberId, ?string $asOf = null, int $byUid = 0): int
    {
        $p = self::participantRow($memberId);
        if (!$p) return 0;
        $plan = self::trainingPlanOf($p);
        if ($plan === null) return 0;
        $planName = ($cat = self::planFor($p)) ? $cat['name'] : 'Training';
        $thisMonth = self::periodKey($asOf ?: self::today('Y-m-d'), 'month');
        $n = 0;
        foreach ($plan['instalments'] as $ins) {
            if ($ins['period'] > $thisMonth) break;              // not yet
            if ((int) $ins['amount'] <= 0) continue;
            $note = $planName . ' — training fee'
                  . ($plan['months'] > 1 ? ' ' . $ins['n'] . ' of ' . $plan['months'] : '')
                  . ' · ' . $ins['period'];
            if (self::postCharge($memberId, 'programme', (int) $ins['amount'], $ins['period'],
                    ['note' => $note, 'source' => 'staff', 'by' => $byUid])) $n++;
        }
        return $n;
    }

    /**
     * Post a fine or an adjustment. Somebody's decision, on the record as one.
     *
     * Three things separate this from the accrual. A REASON is required and
     * stored on the row. It is audited under its own action with the reason in
     * it. And it is never idempotent: two fines in one month are two real
     * events, so each takes its own period token rather than a calendar key.
     *
     * ON THE CEILING. `balanceCap` stops the ACCRUAL — the machine, running
     * monthly, with nobody watching. A human posting a fine can push an account
     * past it, and the response SAYS SO rather than silently refusing: the cap
     * exists to stop an unattended process running someone into a number nobody
     * will pay, not to overrule the staff member standing in front of them.
     */
    public static function charge(int $memberId, string $kind, $amount, string $reason, string $note, int $byUid): array
    {
        $p = self::participantRow($memberId);
        if (!$p) return ['ok' => false, 'error' => 'No such participant.'];
        if (!in_array($kind, self::MANUAL_CHARGE_KINDS, true)) return ['ok' => false, 'error' => 'That is not a charge staff may post.'];
        $amount = self::money($amount);
        if ($amount <= 0) return ['ok' => false, 'error' => 'Enter an amount.'];
        $note = trim($note);
        if ($kind === 'fine') {
            if (!isset(self::FINE_REASONS[$reason])) return ['ok' => false, 'error' => 'Choose why the fine was issued.'];
            // `other` is the escape hatch, so it cannot also be the silent one.
            if ($reason === 'other' && $note === '') return ['ok' => false, 'error' => 'Say what the fine is for.'];
            $label = self::FINE_REASONS[$reason];
        } else {
            if ($note === '') return ['ok' => false, 'error' => 'An adjustment needs a reason.'];
            $reason = 'adjustment';
            $label = 'Adjustment';
        }
        $period = $kind . ':' . bin2hex(random_bytes(5));
        $entryId = self::postCharge($memberId, $kind, $amount, $period,
            ['reason' => $reason, 'note' => $note !== '' ? $label . ' — ' . $note : $label,
             'source' => 'staff', 'by' => $byUid]);
        if ($entryId === null) return ['ok' => false, 'error' => 'Could not post that charge.'];
        self::audit('ngv_fee_' . $kind, 'ngv:member:' . $memberId,
            ucfirst($kind) . ' ' . self::money_text($amount) . ' — ' . $label . ($note !== '' ? ': ' . $note : ''));
        $b = self::balance($memberId);
        return ['ok' => true, 'entryId' => $entryId, 'amount' => $amount, 'payable' => (int) $b['payable'],
                /* Surfaced, not enforced — see above. */
                'overCap' => !empty($b['atCap'])];
    }

    /** Record money received, allocated to the line it pays. */
    public static function payment(int $memberId, string $line, $amount, array $opt, int $byUid): array
    {
        return self::postCredit($memberId, 'payment', $line, $amount, $opt, $byUid);
    }

    /**
     * Set part of what is owed aside. A recorded mercy, not a deletion.
     *
     * This is what voiding gets used for when a ledger has no waiver, and it is
     * the wrong tool: voiding says "that charge should never have existed"; a
     * waiver says "it existed and we are not asking for it". "No one is turned
     * away for lack" is on the public page, so the ledger needs the second — and
     * a ledger that can only do the first loses the reason the moment somebody
     * edits history to be kind.
     */
    public static function waive(int $memberId, string $line, $amount, string $reason, int $byUid): array
    {
        return self::forgive($memberId, 'waiver', $line, $amount, $reason, $byUid);
    }

    /**
     * Write a balance off — the programme has stopped carrying it, which is
     * usually what a withdrawal leaves behind.
     *
     * Separate from a waiver because they are different sentences. A waiver says
     * "this is owed and we are not asking this person for it"; a write-off says
     * "we are no longer carrying it at all". Recording one as the other loses
     * the only part anybody reads back later.
     */
    public static function writeOff(int $memberId, string $line, $amount, string $reason, int $byUid): array
    {
        return self::forgive($memberId, 'writeoff', $line, $amount, $reason, $byUid);
    }

    /** The clamp and the reason rule both kinds share. */
    private static function forgive(int $memberId, string $kind, string $line, $amount, string $reason, int $byUid): array
    {
        $word = $kind === 'waiver' ? 'waiver' : 'write-off';
        $reason = trim($reason);
        // No vocabulary here: this is a judgement about one person's month, and a
        // dropdown would flatten the only part of it worth reading later.
        if ($reason === '') return ['ok' => false, 'error' => 'A ' . $word . ' needs a reason — it is the part worth reading later.'];
        $b = self::balance($memberId);
        $asked = self::money($amount);
        if ($asked <= 0) return ['ok' => false, 'error' => 'Enter an amount.'];
        /* Clamped to what is actually outstanding, because forgiving more than is
           owed would mint a credit out of nothing and the next month would look
           prepaid. */
        $room = in_array($line, self::CREDIT_LINES, true) && $line !== 'other'
            ? (int) ($b['due'][$line] ?? 0)
            : (int) $b['payable'];
        if ($room <= 0) return ['ok' => false, 'error' => 'There is nothing outstanding on that line.'];
        $give = min($asked, $room);
        $r = self::postCredit($memberId, $kind, $line, $give, ['note' => $reason], $byUid);
        if (empty($r['ok'])) return $r;
        /* Said out loud when less was given than was typed, rather than leaving
           somebody to wonder why the balance did not reach zero. */
        return $r + ['waived' => $give, 'clamped' => $give < $asked];
    }

    private static function postCredit(int $memberId, string $creditKind, string $line, $amount, array $opt, int $byUid): array
    {
        $p = self::participantRow($memberId);
        if (!$p) return ['ok' => false, 'error' => 'No such participant.'];
        if (!in_array($creditKind, self::CREDIT_KINDS, true)) return ['ok' => false, 'error' => 'Unknown credit.'];
        if (!in_array($line, self::CREDIT_LINES, true)) $line = 'other';
        $amount = self::money($amount);
        if ($amount <= 0) return ['ok' => false, 'error' => 'Enter an amount.'];
        $period = self::validPeriod((string) ($opt['period'] ?? ''));
        $now = NgvDb::nowExpr();
        try {
            NgvDb::pdo()->prepare(
                "INSERT INTO ngv_payments (member_id,kind,credit_kind,amount,currency,period,method,reference,note,recorded_by,created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,{$now})"
            )->execute([
                $memberId, $line, $creditKind, $amount, 'NGN', $period,
                mb_substr(trim((string) ($opt['method'] ?? '')), 0, 40),
                mb_substr(trim((string) ($opt['reference'] ?? '')), 0, 80),
                mb_substr(trim((string) ($opt['note'] ?? '')), 0, 200),
                max(0, $byUid),
            ]);
        } catch (Throwable $e) {
            error_log('[ngvledger] postCredit: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record that.'];
        }
        $id = (int) NgvDb::pdo()->lastInsertId();
        self::audit('ngv_fee_' . $creditKind, 'ngv:member:' . $memberId,
            ucfirst($creditKind) . ' ' . self::money_text($amount) . ' → ' . $line
            . ($period !== '' ? ' (' . $period . ')' : '')
            . (($opt['note'] ?? '') !== '' ? ' — ' . mb_substr((string) $opt['note'], 0, 120) : ''));
        /* A receipt goes out for money RECEIVED, immediately — the point is that
           somebody who handed over cash walks away with evidence, and evidence
           that arrives next week is not the same thing.
           Only for `payment`: a waiver is the programme deciding not to ask, and
           receipting it would tell somebody they had paid money they never
           handed over. `receipt => false` suppresses the send for the case that
           actually needs it — staff typing in a backlog of historic payments,
           where forty emails at once is a fault, not a feature. The receipt
           still EXISTS and can be sent later; only the letter is held. */
        $receipt = null;
        if ($creditKind === 'payment' && (!array_key_exists('receipt', $opt) || !empty($opt['receipt']))) {
            try { $receipt = self::sendReceipt($id); }
            catch (Throwable $e) { error_log('[ngvledger] receipt: ' . $e->getMessage()); }
        }
        return ['ok' => true, 'id' => $id, 'amount' => $amount,
                'payable' => (int) self::balance($memberId)['payable'],
                'receipt' => is_array($receipt) && !empty($receipt['ok'])
                    ? ['no' => $receipt['no'], 'delivered' => !empty($receipt['delivered'])] : null];
    }

    /**
     * Void an entry, on either side of the ledger.
     *
     * Corrections do not edit or delete — a mistake somebody fixed stays on the
     * account with its reason, because "why is this different from last month"
     * is the question an account has to be able to answer.
     */
    public static function void(string $side, int $id, string $reason, int $byUid): array
    {
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'error' => 'Say why — a void without a reason is a deletion.'];
        $table = $side === 'charge' ? 'ngv_charges' : 'ngv_payments';
        $pdo = NgvDb::pdo();
        $st = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) return ['ok' => false, 'error' => 'No such entry.'];
        if ((int) ($row['voided'] ?? 0) === 1) return ['ok' => false, 'error' => 'That entry is already void.'];
        $pdo->prepare("UPDATE {$table} SET voided = 1, voided_by = ?, voided_at = ?, void_reason = ? WHERE id = ?")
            ->execute([max(0, $byUid), self::nowStamp(), mb_substr($reason, 0, 200), $id]);
        self::audit('ngv_fee_void', 'ngv:member:' . (int) $row['member_id'],
            'Voided a ' . $side . ' of ' . self::money_text((int) $row['amount'])
            . ' (' . (string) $row['kind'] . ') — ' . mb_substr($reason, 0, 160));
        /* Voiding a receipted payment leaves somebody holding a receipt for
           money that is no longer on their account. Saying nothing is worse than
           never issuing one: they believe they have paid, and find out when a
           reminder arrives. */
        $cancelled = false;
        if ($side === 'credit' && (string) ($row['credit_kind'] ?? 'payment') === 'payment'
            && trim((string) ($row['receipt_at'] ?? '')) !== '') {
            try { $cancelled = !empty(self::sendReceipt($id, true)['ok']); }
            catch (Throwable $e) { error_log('[ngvledger] receipt void: ' . $e->getMessage()); }
        }
        return ['ok' => true, 'receiptCancelled' => $cancelled];
    }

    /** Turn reminders off (or back on) for one participant, without touching
     *  anybody else's. Someone who has asked not to be chased has asked for this. */
    public static function setRemindOff(int $memberId, bool $off): array
    {
        if (!self::participantRow($memberId)) return ['ok' => false, 'error' => 'No such participant.'];
        NgvDb::pdo()->prepare('UPDATE ngv_participants SET remind_off = ?, updated_at = ' . NgvDb::nowExpr() . ' WHERE member_id = ?')
            ->execute([$off ? 1 : 0, $memberId]);
        self::audit('ngv_fee_remind_' . ($off ? 'off' : 'on'), 'ngv:member:' . $memberId,
            'Fee reminders turned ' . ($off ? 'off' : 'on') . ' for this participant');
        return ['ok' => true, 'off' => $off];
    }

    /* ══ What a participant says about their own account ════════════════════
     *
     * "No one is turned away for lack. If you are truly committed and need
     * support, speak to your track lead or send a letter requesting
     * consideration" is on the public page. Until this existed the dashboard
     * could only repeat that sentence back, which makes a promise into a dead
     * end: the person who most needs it is the one least likely to walk up to
     * staff and start the conversation, and "send a letter" is a real barrier
     * to a nineteen-year-old who is already embarrassed.
     *
     * A request is a MESSAGE, never a decision. Nothing a participant writes
     * moves a figure. The outcome is a waiver, a correction or a conversation,
     * posted separately by staff under their own name — which is also why the
     * resolution is written back to them: a request into a void is worse than no
     * form at all, because it teaches somebody that asking does not work.
     */

    /** What a participant can raise. Two, because "this looks wrong" and "I
     *  cannot pay this month" need the same channel and different answers. */
    public const REQUEST_KINDS = [
        'consideration' => 'Asking for consideration',
        'query'         => 'Querying a figure',
    ];
    public const REQUEST_MAX = 1200;      // characters — a note, not an essay
    public const REQUESTS_PAGE = 100;

    /**
     * Raise one. At most one open at a time per participant — which is a rate
     * limit, but mostly it is the honest shape: a second unanswered request does
     * not get somebody helped faster, it just buries the first.
     */
    public static function raiseRequest(int $memberId, string $kind, string $message, $amount = 0): array
    {
        $p = self::participantRow($memberId);
        if (!$p) return ['ok' => false, 'error' => 'No such participant.'];
        if (!isset(self::REQUEST_KINDS[$kind])) $kind = 'consideration';
        $message = trim($message);
        if (mb_strlen($message) < 10) {
            return ['ok' => false, 'error' => 'Tell us a little about it — a sentence or two is plenty.'];
        }
        foreach (self::requestsFor($memberId) as $r) {
            if ($r['status'] === 'open') {
                return ['ok' => false, 'error' => 'You already have a request open. Your track lead will come back to you on it.'];
            }
        }
        $now = NgvDb::nowExpr();
        NgvDb::pdo()->prepare("INSERT INTO ngv_fee_requests (member_id, kind, amount, message, status, created_at)
                               VALUES (?,?,?,?,'open',{$now})")
            ->execute([$memberId, $kind, self::money($amount), mb_substr($message, 0, self::REQUEST_MAX)]);
        self::audit('ngv_fee_request', 'ngv:member:' . $memberId,
            self::REQUEST_KINDS[$kind] . ' — ' . mb_substr($message, 0, 200),
            'member:' . $memberId);
        $who = trim((string) ($p['name'] ?? '')) ?: ('member #' . $memberId);
        self::alertStaff(
            'NGV: ' . $who . ' has written about their account',
            $who . ' has raised a request — ' . self::REQUEST_KINDS[$kind] . '.',
            '/academy/ngv/members.php?m=' . $memberId);
        return ['ok' => true, 'id' => (int) NgvDb::pdo()->lastInsertId()];
    }

    /**
     * Tell staff somebody has written in.
     *
     * The gap this closes: the request queue sits on a console, and a console
     * nobody opened this week is not a queue — it is a drawer. Somebody who
     * wrote "I cannot pay this month" and heard nothing for nine days has been
     * taught that asking does not work, which is precisely the failure the
     * request channel exists to prevent.
     *
     * Goes to the ADMIN ROLE LIST rather than a configured address, so it
     * follows whoever actually administers the site instead of an inbox nobody
     * checks after a handover. Deliberately terse and deliberately does NOT
     * carry the message body: staff should answer on the console where the
     * account is in front of them, not by replying to an email with no context.
     *
     * Best-effort throughout. A mail failure must never lose the request — the
     * row is already committed before this runs.
     */
    private static function alertStaff(string $subject, string $line, string $url): void
    {
        if (!class_exists('Mailer') || !class_exists('AdminRoles')) return;
        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        $to = [];
        try {
            foreach (AdminRoles::list() as $a) {
                $email = trim((string) ($a['email'] ?? ''));
                /* Only the roles that can actually act on it. A content editor
                   getting paged about somebody's fees learns to filter the
                   sender, and then misses the one that mattered. */
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)
                    && in_array((string) ($a['role'] ?? ''), ['superadmin', 'admin'], true)) {
                    $to[] = $email;
                }
            }
        } catch (Throwable $e) { error_log('[ngvledger] staff alert list: ' . $e->getMessage()); }
        if (!$to) return;
        $html = Mailer::shell('NextGen Vanguard', [self::esc($line),
            'Open the console to answer it — the account is there alongside the message.'],
            ['url' => $site . $url, 'text' => 'Open the console'], $subject);
        foreach (array_slice(array_unique($to), 0, 10) as $addr) {
            try { @Mailer::send($addr, $subject, $html); }
            catch (Throwable $e) { error_log('[ngvledger] staff alert: ' . $e->getMessage()); }
        }
    }

    /** Public so NgvDamage can page staff on a self-reported incident too — the
     *  same "a queue nobody opened is a drawer" problem, same answer. */
    public static function notifyStaff(string $subject, string $line, string $url): void
    {
        self::alertStaff($subject, $line, $url);
    }

    /** One participant's own requests, newest first. */
    public static function requestsFor(int $memberId): array
    {
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_fee_requests WHERE member_id = ? ORDER BY id DESC LIMIT 20');
        $st->execute([$memberId]);
        return array_map([self::class, 'requestShape'], $st->fetchAll() ?: []);
    }

    /** The queue staff work from. Open first, because that is the whole point. */
    public static function requests(bool $openOnly = true, int $limit = self::REQUESTS_PAGE): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT r.*, p.name AS name, p.email AS email FROM ngv_fee_requests r
                LEFT JOIN ngv_participants p ON p.member_id = r.member_id';
        if ($openOnly) $sql .= " WHERE r.status = 'open'";
        $sql .= " ORDER BY CASE WHEN r.status = 'open' THEN 0 ELSE 1 END, r.id DESC LIMIT " . $limit;
        $rows = NgvDb::pdo()->query($sql)->fetchAll() ?: [];
        return array_map([self::class, 'requestShape'], $rows);
    }

    public static function openRequestCount(): int
    {
        try { return (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_fee_requests WHERE status = 'open'")->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    }

    private static function requestShape(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'member_id' => (int) $r['member_id'],
            'kind' => (string) $r['kind'],
            'kindLabel' => self::REQUEST_KINDS[(string) $r['kind']] ?? (string) $r['kind'],
            'amount' => (int) $r['amount'], 'message' => (string) $r['message'],
            'status' => (string) $r['status'], 'outcome' => (string) $r['outcome'],
            'created_at' => (string) $r['created_at'], 'handled_at' => (string) $r['handled_at'],
            'name' => (string) ($r['name'] ?? ''), 'email' => (string) ($r['email'] ?? ''),
        ];
    }

    /**
     * Answer one.
     *
     * The outcome is written back to the participant in-app, because a request
     * that disappears teaches somebody that asking does not work — and the next
     * time they will simply stop coming instead. `declined` is a real answer and
     * says so; it is not a failure state.
     *
     * This does NOT move money. Where the answer is a waiver, staff post the
     * waiver, which carries its own reason and its own name.
     */
    public static function resolveRequest(int $id, string $status, string $outcome, int $byUid): array
    {
        $status = $status === 'declined' ? 'declined' : 'resolved';
        $outcome = trim($outcome);
        if ($outcome === '') return ['ok' => false, 'error' => 'Say what you told them — they see this.'];
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_fee_requests WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return ['ok' => false, 'error' => 'No such request.'];
        if ((string) $r['status'] !== 'open') return ['ok' => false, 'error' => 'That request has already been answered.'];
        NgvDb::pdo()->prepare('UPDATE ngv_fee_requests SET status = ?, outcome = ?, handled_by = ?, handled_at = ? WHERE id = ?')
            ->execute([$status, mb_substr($outcome, 0, self::REQUEST_MAX), max(0, $byUid), self::nowStamp(), $id]);
        self::audit('ngv_fee_request_' . $status, 'ngv:member:' . (int) $r['member_id'],
            ucfirst($status) . ' — ' . mb_substr($outcome, 0, 200));
        if (class_exists('Notifications')) {
            try {
                Notifications::push((int) $r['member_id'], 'ngv_fees',
                    'Your track lead has replied',
                    mb_substr($outcome, 0, 240),
                    '/academy/ngv/dashboard.php#account',
                    'ngv_fee_request:' . $id);
            } catch (Throwable $e) { error_log('[ngvledger] request notify: ' . $e->getMessage()); }
        }
        return ['ok' => true, 'status' => $status];
    }

    /* ══ Reading an account ═════════════════════════════════════════════════ */

    /**
     * What one participant owes, as a figure: no plan catalogue, no entry list,
     * no prose. Every caller that needs the number and not the account reads
     * this — the accrual's cap check, and (in bulk, via `sweep()`) the arrears
     * and reminder passes.
     */
    public static function balance(int $memberId): array
    {
        return self::reduce(self::charges($memberId), self::credits($memberId));
    }

    /**
     * The arithmetic, in exactly one place.
     *
     * Takes rows that may be individual ledger entries or pre-summed groups —
     * it only ever adds them up, so both work — which is what lets the
     * single-participant read and the roster-wide sweep share it. Two copies of
     * this sum is how an arrears list comes to disagree with the account it
     * links to, and nobody can tell which one is lying.
     *
     * `n` on a row is its row count, for callers that summed before arriving.
     */
    private static function reduce(array $charges, array $credits, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::settings();
        $chargedBy = []; $paidBy = []; $countBy = [];
        $charged = 0; $credited = 0;
        foreach ($charges as $c) {
            $k = (string) $c['kind']; $a = (int) $c['amount'];
            $chargedBy[$k] = ($chargedBy[$k] ?? 0) + $a;
            $countBy[$k] = ($countBy[$k] ?? 0) + (int) ($c['n'] ?? 1);
            $charged += $a;
        }
        /* `credited` settles a line whatever kind of credit it was; `received`
           and `waived` split it, because "you have paid ₦12,000" when ₦2,000 of
           that was the programme deciding not to ask is a sentence that is not
           true of the person reading it. */
        $received = 0; $waived = 0;
        foreach ($credits as $c) {
            $k = (string) ($c['kind'] ?? 'other'); $a = (int) $c['amount'];
            $paidBy[$k] = ($paidBy[$k] ?? 0) + $a;
            $credited += $a;
            $ck = (string) ($c['credit_kind'] ?? 'payment');
            if ($ck === 'waiver') $waived += $a;
            elseif ($ck !== 'writeoff') $received += $a;
        }
        /* Credits recorded before this ledger existed carry no line, and neither
           does a payment nobody could allocate. They form a pool that reduces the
           ACCOUNT rather than a line — the honest treatment of money that arrived
           without a note saying what it was for. */
        $pool = (int) ($paidBy['other'] ?? 0);

        $due = []; $shortfall = 0; $ahead = 0;
        foreach (self::CHARGE_KINDS as $k) {
            $ch = (int) ($chargedBy[$k] ?? 0); $pd = (int) ($paidBy[$k] ?? 0);
            $due[$k] = max(0, $ch - $pd);
            $shortfall += $due[$k];
            $ahead += max(0, $pd - $ch);
        }
        $cap = (int) ($cfg['balanceCap'] ?? 0);
        return [
            'chargedBy' => $chargedBy, 'paidBy' => $paidBy, 'countBy' => $countBy,
            'charged' => $charged, 'credited' => $credited,
            'received' => $received, 'waived' => $waived,
            'due' => $due, 'shortfall' => $shortfall,
            /* Paying ahead on one line never cancels arrears on another — the
               surplus is REPORTED rather than netted, so "square on membership,
               two months behind on commitment" survives into the figure. */
            'payable' => max(0, $shortfall - $pool),
            'paidAhead' => $ahead, 'unallocated' => $pool,
            'cap' => $cap, 'atCap' => $cap > 0 && $shortfall >= $cap,
        ];
    }

    /**
     * Every account that has a charge against it, in four queries total.
     *
     * The sweeps — arrears, the reminder scan — used to build a full balance per
     * person, which is three round trips each: a roster of three hundred cost
     * nine hundred queries to answer "who is behind". The database can group;
     * asking it to is the difference between a page that loads and a page
     * somebody stops opening.
     *
     * Returns `[memberId => [balance, participant row]]`, keyed by member.
     */
    private static function sweep(): array
    {
        $pdo = NgvDb::pdo();
        $cfg = self::settings();
        $chargesBy = []; $creditsBy = [];
        foreach ($pdo->query('SELECT member_id, kind, SUM(amount) AS amount, COUNT(*) AS n
                              FROM ngv_charges WHERE voided = 0 GROUP BY member_id, kind')->fetchAll() ?: [] as $r) {
            $chargesBy[(int) $r['member_id']][] = $r;
        }
        if (!$chargesBy) return [];
        foreach ($pdo->query('SELECT member_id, kind, credit_kind, SUM(amount) AS amount, COUNT(*) AS n
                              FROM ngv_payments WHERE voided = 0 GROUP BY member_id, kind, credit_kind')->fetchAll() ?: [] as $r) {
            $creditsBy[(int) $r['member_id']][] = $r;
        }
        $people = [];
        foreach ($pdo->query('SELECT * FROM ngv_participants')->fetchAll() ?: [] as $p) {
            $people[(int) $p['member_id']] = $p;
        }
        $out = [];
        foreach ($chargesBy as $id => $rows) {
            /* A charge with no participant row is a data fault, not a person to
               chase — skipped rather than reported as a nameless debtor. */
            if (!isset($people[$id])) continue;
            $out[$id] = ['balance' => self::reduce($rows, $creditsBy[$id] ?? [], $cfg),
                         'person' => $people[$id]];
        }
        return $out;
    }

    /**
     * What a participant owes, what it is made of, and everything behind it.
     *
     * PER LINE, not one netted total. NGV's payments have always been allocated
     * to the fee they pay, and that is worth keeping: "you are square on
     * membership and two months behind on commitment" is actionable where "you
     * owe ₦2,000" is a number somebody has to come and ask about. `payable` is
     * the sum of the SHORTFALLS, so paying ahead on one line never hides arrears
     * on another; what is paid ahead is reported as `paidAhead` instead of
     * quietly cancelling something else out.
     */
    /**
     * What a participant owes, what it is made of, and everything behind it.
     *
     * PER LINE, not one netted total. NGV's payments have always been allocated
     * to the fee they pay, and that is worth keeping: "you are square on
     * membership and two months behind on commitment" is actionable where "you
     * owe ₦2,000" is a number somebody has to come and ask about.
     */
    public static function account(int $memberId): array
    {
        $cfg = self::settings();
        $p = self::participantRow($memberId) ?: [];
        $b = self::balance($memberId);
        $amt = self::amounts();
        $plan = self::planFor($p);
        $labels = [
            'membership' => 'Membership',
            'commitment' => 'Monthly commitment',
            'programme'  => 'Training fee',
            'fine'       => 'Fines',
            'adjustment' => 'Adjustments',
        ];
        /* The three standing charges are always listed, even at zero, because a
           line that vanishes when it is settled reads as a line that was never
           there. Fines and adjustments appear only once one exists — a permanent
           "Fines: none" row on every account is an accusation nobody made. */
        $always = ['membership', 'commitment', 'programme'];
        $training = self::trainingSchedule($memberId);
        $entries = self::entries($memberId);
        $lines = [];
        foreach ($labels as $k => $label) {
            $ch = (int) ($b['chargedBy'][$k] ?? 0);
            $pd = (int) ($b['paidBy'][$k] ?? 0);
            if ($ch === 0 && $pd === 0 && !in_array($k, $always, true)) continue;
            $lines[] = [
                'key' => $k, 'label' => $label,
                'charged' => $ch, 'paid' => $pd, 'due' => (int) $b['due'][$k],
                'count' => (int) ($b['countBy'][$k] ?? 0),
                'ok' => (int) $b['due'][$k] === 0,
                'free' => $k === 'programme' && $ch === 0 && ($plan === null || (int) $plan['fee'] === 0),
                'expected' => $ch,          // the shape older callers read
                'detail' => self::lineDetail($k, $ch, $pd, (int) $b['due'][$k], $plan, $amt, $training),
            ];
        }

        return [
            'enabled'    => !empty($cfg['enabled']),
            'currency'   => 'NGN',
            'memberId'   => $memberId,
            'payable'    => (int) $b['payable'],
            'shortfall'  => (int) $b['shortfall'],
            'paidAhead'  => (int) $b['paidAhead'],
            'unallocated' => (int) $b['unallocated'],
            'charged'    => (int) $b['charged'],
            'credited'   => (int) $b['credited'],
            'received'   => (int) $b['received'],
            'waived'     => (int) $b['waived'],
            'due'        => $b['due'],
            'cap'        => (int) $b['cap'],
            'atCap'      => !empty($b['atCap']),
            'plan'       => (string) ($p['plan'] ?? ''),
            'planLabel'  => $plan ? ($plan['name'] . ($plan['duration'] !== '' ? ' · ' . $plan['duration'] : '')) : '',
            'planFree'   => $plan ? ((int) $plan['fee'] === 0) : false,
            'planFee'    => $plan ? (int) $plan['fee'] : 0,
            /* Empty array when no commitment is running, so a screen can test it
               without also having to know what "no training fee" looks like. */
            'training'   => $training,
            'requests'   => self::requestsFor($memberId),
            'lines'      => $lines,
            'entries'    => $entries,
            /* Every credit, which is what the older shape called `total`. Screens
               that want "what this person actually paid" read `received`. */
            'total'      => (int) $b['credited'],
            'count'      => count($entries),
            'has_any'    => $entries !== [],
            'remindOff'  => (int) ($p['remind_off'] ?? 0) === 1,
            'startedOn'  => self::startDate($p),
            'payTo'      => (string) $amt['payTo'],
            'note'       => (string) $amt['note'],
        ];
    }

    /** One readable sentence per line, so a screen never has to compose money. */
    private static function lineDetail(string $k, int $charged, int $paid, int $due, ?array $plan, array $amt, array $sched = []): string
    {
        $m = fn(int $n) => self::money_text($n);
        if ($k === 'programme') {
            /* A schedule, where one is running, describes itself far better than
               the running total does: "instalment 3 of 12, ₦20,000 a month" is
               the sentence somebody can plan around. */
            if ($sched) {
                $left = max(0, (int) $sched['total'] - (int) $sched['paid']);
                return ($sched['months'] > 1
                        ? $sched['settled'] . ' of ' . $sched['months'] . ' instalments charged · '
                        : '')
                     . $m($paid) . ' of ' . $m((int) $sched['total']) . ' paid'
                     . ($left > 0 ? ' · ' . $m($left) . ' to go' : ' · settled');
            }
            if ($plan === null) return 'No plan chosen yet — pick one, or speak to your track lead.';
            if ($charged === 0 && (int) $plan['fee'] === 0) {
                return $plan['name'] . ' is free' . ($plan['note'] !== '' ? ' (' . $plan['note'] . ')' : '');
            }
            if ($charged === 0) return $plan['name'] . ' · ' . $plan['priceLabel'] . ' — nothing agreed on your account yet';
            return $m($paid) . ' of ' . $m($charged) . ' — ' . $plan['name'];
        }
        if ($k === 'fine')       return $due > 0 ? $m($due) . ' outstanding' : ($charged > 0 ? 'All settled' : 'None');
        if ($k === 'adjustment') return $m($paid) . ' of ' . $m($charged);
        if ($charged === 0) {
            $a = $amt[$k] ?? null;
            return $a && (int) $a['amount'] > 0
                ? 'Nothing charged yet · ' . $m((int) $a['amount']) . ' per ' . $a['cadence']
                : 'Nothing charged yet';
        }
        return $m($paid) . ' of ' . $m($charged);
    }

    /**
     * Both sides of the ledger in one list, newest first, in the shape a screen
     * reads. Void rows are INCLUDED and flagged — a corrected mistake staying
     * visible is the whole point of voiding rather than deleting.
     */
    public static function entries(int $memberId): array
    {
        $pdo = NgvDb::pdo();
        $st = $pdo->prepare('SELECT * FROM ngv_charges WHERE member_id = ? ORDER BY id');
        $st->execute([$memberId]); $charges = $st->fetchAll() ?: [];
        $st = $pdo->prepare('SELECT * FROM ngv_payments WHERE member_id = ? ORDER BY id');
        $st->execute([$memberId]); $credits = $st->fetchAll() ?: [];
        $out = [];
        foreach ($charges as $c) {
            $out[] = [
                'side' => 'charge', 'id' => (int) $c['id'], 'kind' => (string) $c['kind'],
                'creditKind' => '', 'amount' => (int) $c['amount'],
                /* A fine or an adjustment carries a random token as its period
                   so two in one month do not collide under the unique index.
                   That token is plumbing — the date is on the row — so it never
                   reaches a screen. */
                'period' => in_array((string) $c['kind'], self::MANUAL_CHARGE_KINDS, true) ? '' : (string) $c['period'],
                'note' => (string) $c['note'],
                'reason' => (string) ($c['reason'] ?? ''),
                'reasonLabel' => self::FINE_REASONS[(string) ($c['reason'] ?? '')] ?? '',
                'source' => (string) ($c['source'] ?? ''),
                'method' => '', 'reference' => '',
                'created_at' => (string) $c['created_at'],
                'void' => (int) ($c['voided'] ?? 0) === 1,
                'voidReason' => (string) ($c['void_reason'] ?? ''),
            ];
        }
        foreach ($credits as $c) {
            $out[] = [
                'side' => 'credit', 'id' => (int) $c['id'], 'kind' => (string) $c['kind'],
                'creditKind' => (string) ($c['credit_kind'] ?? 'payment'),
                'amount' => (int) $c['amount'],
                'period' => (string) $c['period'], 'note' => (string) $c['note'],
                'reason' => '', 'reasonLabel' => '', 'source' => 'staff',
                'method' => (string) $c['method'], 'reference' => (string) $c['reference'],
                'created_at' => (string) $c['created_at'],
                'void' => (int) ($c['voided'] ?? 0) === 1,
                'voidReason' => (string) ($c['void_reason'] ?? ''),
            ];
        }
        usort($out, static fn($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);
        return $out;
    }

    /** Cheap "is this account at the ceiling" without building the whole account. */
    private static function atCap(int $memberId, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::settings();
        $cap = (int) ($cfg['balanceCap'] ?? 0);
        if ($cap <= 0) return false;
        return (int) self::balance($memberId)['shortfall'] >= $cap;
    }

    /* ══ Arrears, lookup, totals ════════════════════════════════════════════ */

    /**
     * Who is behind, for the screen staff work from. Ranked by what is PAYABLE,
     * because the largest charge is not the same question as the largest debt.
     */
    public static function arrears(int $limit = self::ARREARS_PAGE): array
    {
        $limit = max(1, min(self::ARREARS_PAGE_MAX, $limit));
        $out = []; $totalPayable = 0;
        foreach (self::sweep() as $id => $row) {
            $b = $row['balance'];
            if ((int) $b['payable'] <= 0) continue;
            $p = $row['person'];
            $totalPayable += (int) $b['payable'];
            $out[] = [
                'member_id' => $id,
                'name'    => (string) ($p['name'] ?? ''),
                'email'   => (string) ($p['email'] ?? ''),
                'status'  => (string) ($p['status'] ?? ''),
                'cohort'  => (string) ($p['cohort'] ?? ''),
                'payable' => (int) $b['payable'],
                'due'     => $b['due'],
                'atCap'   => !empty($b['atCap']),
                'remindOff' => (int) ($p['remind_off'] ?? 0) === 1,
            ];
        }
        usort($out, static fn($x, $y) => $y['payable'] <=> $x['payable']);
        $matched = count($out);
        return ['rows' => array_slice($out, 0, $limit), 'matched' => $matched,
                'truncated' => $matched > $limit, 'totalPayable' => $totalPayable];
    }

    /**
     * Find a participant's account by name or email.
     *
     * The gap this fills: an arrears list only holds people who OWE, and on its
     * own it is the only way into an account. "Has Ada paid?" is then
     * unanswerable for everybody who has — the paid-up, the square and the
     * paid-ahead are all unreachable. A ledger you can only enter through the
     * debtors' list is a ledger nobody can check.
     */
    public static function lookup(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return ['rows' => [], 'q' => $q, 'tooShort' => true, 'truncated' => false];
        /* The wildcards are STRIPPED rather than escaped: `ESCAPE` wants a
           different literal on each of the three engines NGV can run on, and a
           name containing % or _ is not a thing anybody searches for. */
        $like = '%' . str_replace(['%', '_'], '', $q) . '%';
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_participants WHERE name LIKE ? OR email LIKE ? ORDER BY name LIMIT ' . (self::LOOKUP_MAX + 1));
        $st->execute([$like, $like]);
        $rows = $st->fetchAll() ?: [];
        $out = [];
        /* A balance per row here, not a sweep: this returns at most 25 people and
           the sweep reads the whole ledger, which is the more expensive answer
           for a search box. */
        foreach (array_slice($rows, 0, self::LOOKUP_MAX) as $p) {
            $b = self::balance((int) $p['member_id']);
            $out[] = ['member_id' => (int) $p['member_id'], 'name' => (string) $p['name'],
                      'email' => (string) $p['email'], 'status' => (string) $p['status'],
                      /* Both figures: `payable` is what to ask for, `charged`
                         proves an account exists at all for somebody who owes
                         nothing — which is the question the arrears list cannot
                         answer and this box exists for. */
                      'payable' => (int) $b['payable'], 'charged' => (int) $b['charged'],
                      'credited' => (int) $b['credited']];
        }
        return ['rows' => $out, 'q' => $q, 'tooShort' => false,
                'truncated' => count($rows) > self::LOOKUP_MAX, 'max' => self::LOOKUP_MAX];
    }

    /** Programme-wide money, for the staff console's tiles. */
    public static function totals(): array
    {
        $pdo = NgvDb::pdo();
        $q = static function (string $sql) use ($pdo): int {
            try { return (int) $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
        };
        $charged = $q('SELECT COALESCE(SUM(amount),0) FROM ngv_charges WHERE voided = 0');
        $received = $q("SELECT COALESCE(SUM(amount),0) FROM ngv_payments WHERE voided = 0 AND credit_kind = 'payment'");
        $waived  = $q("SELECT COALESCE(SUM(amount),0) FROM ngv_payments WHERE voided = 0 AND credit_kind = 'waiver'");
        $written = $q("SELECT COALESCE(SUM(amount),0) FROM ngv_payments WHERE voided = 0 AND credit_kind = 'writeoff'");
        $fines   = $q("SELECT COALESCE(SUM(amount),0) FROM ngv_charges WHERE voided = 0 AND kind = 'fine'");
        return ['charged' => $charged, 'received' => $received, 'waived' => $waived,
                'writtenOff' => $written, 'fines' => $fines,
                /* Not `charged - received`: a waiver settles a charge too, and an
                   arrears figure that ignores them overstates the debt. */
                'outstanding' => max(0, $charged - $received - $waived - $written)];
    }

    /* ══ Reminders ══════════════════════════════════════════════════════════ */

    /**
     * Who is due a reminder, and why each of the rest is not.
     *
     * The reasons come back with the list, because "it sent 4 of 60" is not a
     * number anybody can check, and the question staff get asked when somebody
     * says they were never told is "why not". Every exclusion is named.
     *
     * The bounds are all HERE rather than at the send site, so a caller cannot
     * skip them by calling something else.
     */
    public static function reminderCandidates(int $limit = self::CANDIDATE_MAX): array
    {
        $cfg = self::settings();
        $out = ['due' => [], 'everyDays' => 0,
                'skipped' => ['off' => 0, 'notCharged' => 0, 'optedOut' => 0, 'notActive' => 0,
                              'nothingPayable' => 0, 'underMinimum' => 0, 'tooSoon' => 0, 'noEmail' => 0]];
        if (empty($cfg['enabled']) || empty($cfg['remindEnabled'])) { $out['skipped']['off'] = 1; return $out; }
        $everyDays = max(self::REMIND_MIN_DAYS, (int) $cfg['remindEveryDays']);
        $out['everyDays'] = $everyDays;
        $minBal = max(1, (int) $cfg['remindMinBalance']);
        $now = time();
        /* One grouped pass over the whole ledger rather than a balance per head.
           Only people with a charge against them appear, which is also the only
           set that could be behind — a roster entry with nothing charged owes
           nothing by construction, and counting it as "nothing payable" would
           report the roster back as an exclusion list. */
        $swept = self::sweep();
        /* Everyone the sweep did not reach, counted so the preview adds up. A
           participant with nothing charged against them owes nothing by
           construction — but leaving them out of the tally silently makes "4 of
           60" a figure staff cannot reconcile against the roster in front of
           them, which is the whole reason the exclusions are named. */
        try {
            $roster = (int) NgvDb::pdo()->query('SELECT COUNT(*) FROM ngv_participants')->fetchColumn();
            $out['skipped']['notCharged'] = max(0, $roster - count($swept));
        } catch (Throwable $e) { /* a count is not worth failing a send over */ }
        /* Largest first. A bounded run has to spend its budget on the people
           furthest behind, not on whoever the roster happens to list first. */
        uasort($swept, static fn($a, $b) => (int) $b['balance']['payable'] <=> (int) $a['balance']['payable']);
        foreach ($swept as $id => $row) {
            if (count($out['due']) >= $limit) break;
            $p = $row['person']; $b = $row['balance'];
            if ((int) $b['payable'] <= 0)      { $out['skipped']['nothingPayable']++; continue; }
            if ((string) ($p['status'] ?? '') !== 'active') { $out['skipped']['notActive']++; continue; }
            if ((int) ($p['remind_off'] ?? 0) === 1)        { $out['skipped']['optedOut']++; continue; }
            if ((int) $b['payable'] < $minBal) { $out['skipped']['underMinimum']++; continue; }
            $last = strtotime((string) ($p['reminded_at'] ?? '')) ?: 0;
            if ($last > 0 && ($now - $last) < $everyDays * 86400) { $out['skipped']['tooSoon']++; continue; }
            $to = trim((string) ($p['email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { $out['skipped']['noEmail']++; continue; }
            $out['due'][] = ['member_id' => $id, 'name' => (string) $p['name'],
                             'email' => $to, 'payable' => (int) $b['payable'],
                             'lastRemindedAt' => (string) ($p['reminded_at'] ?? '')];
        }
        return $out;
    }

    /**
     * One reminder, written for the person who receives it.
     *
     * Deliberately plain: what is owed, what it is for, where to pay, and how to
     * raise a question. No deadline, no consequence, no capitals.
     *
     * These go to young people the programme exists to lift, most of whom are
     * behind because money is tight rather than because they forgot — and a
     * threatening note to that person does not produce a payment, it produces
     * somebody who stops coming, which costs the programme more than the arrears
     * were worth. The page's own promise is repeated verbatim at the end,
     * because a reminder that omits it is asking in a voice the programme does
     * not actually use.
     *
     * Goes in-app as well as by email: NGV participants have accounts on this
     * site, and a notification they see when they next sign in beats an email
     * that may never be opened.
     */
    public static function sendReminder(array $cand): bool
    {
        $a = self::account((int) $cand['member_id']);
        $first = trim(explode(' ', trim((string) ($cand['name'] ?? '')))[0] ?? '');
        if ($first === '') $first = 'there';
        $m = fn(int $n) => self::money_text($n);
        $payable = (int) $a['payable'];

        $rows = [];
        $rows[] = 'Hi ' . self::esc($first) . ' — this is a note about your NextGen Vanguard account. '
                . 'The amount outstanding is <b>' . $m($payable) . '</b>.';
        $bits = [];
        foreach ($a['lines'] as $ln) {
            if ((int) $ln['due'] <= 0) continue;
            $bits[] = self::esc((string) $ln['label']) . ' — ' . $m((int) $ln['due']);
        }
        if ($bits) $rows[] = implode('<br>', $bits);
        if (trim((string) $a['payTo']) !== '') $rows[] = self::esc((string) $a['payTo']);
        $rows[] = 'If any of this looks wrong, or if this is a difficult month, reply to this message or speak to '
                . 'your track lead — we would rather hear from you than not.';
        if (trim((string) $a['note']) !== '') {
            $rows[] = '<span style="color:#5f6874;font-size:14px">' . self::esc((string) $a['note']) . '</span>';
        }

        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        $url = $site . '/academy/ngv/dashboard.php#account';
        $ok = false;
        if (class_exists('Mailer')) {
            $html = Mailer::shell('Your NextGen Vanguard account', $rows,
                ['url' => $url, 'text' => 'See my account'],
                $m($payable) . ' outstanding on your NGV account');
            try { $ok = (bool) Mailer::send((string) $cand['email'], 'Your NextGen Vanguard account — ' . $m($payable) . ' outstanding', $html); }
            catch (Throwable $e) { error_log('[ngvledger] reminder mail: ' . $e->getMessage()); }
        }
        if (class_exists('Notifications')) {
            try {
                Notifications::push((int) $cand['member_id'], 'ngv_fees', 'Your NGV account',
                    $m($payable) . ' outstanding. Speak to your track lead if this month is difficult.',
                    '/academy/ngv/dashboard.php#account',
                    'ngv_fees:' . (int) $cand['member_id'] . ':' . self::today('Y-m-d'));
            } catch (Throwable $e) { error_log('[ngvledger] reminder notify: ' . $e->getMessage()); }
        }
        /* Stamped whether or not the transport succeeded. The alternative is a
           mailer failing quietly and somebody reminded on every single tick
           because nothing recorded the attempt. */
        try {
            NgvDb::pdo()->prepare('UPDATE ngv_participants SET reminded_at = ? WHERE member_id = ?')
                ->execute([self::nowStamp(), (int) $cand['member_id']]);
        } catch (Throwable $e) { error_log('[ngvledger] reminder stamp: ' . $e->getMessage()); }
        return $ok;
    }

    /* ══ Receipts ═══════════════════════════════════════════════════════════
     *
     * The gap: a coordinator takes ₦5,000 in cash, types it into the console,
     * and the participant walks away with nothing. A transfer at least leaves a
     * bank record on their side; cash leaves the payer with no evidence at all,
     * and the only copy of the fact lives in a database they cannot read. The
     * asymmetry is the whole problem — being asked to trust that a payment was
     * recorded is exactly the thing a ledger is supposed to remove.
     *
     * ── NO RECEIPTS TABLE ────────────────────────────────────────────────────
     * A receipt is not a new entity. It IS the payment row, viewed a certain
     * way, and a second table holding a copy of the amount and the date is a
     * second place for that amount and date to be wrong. So nothing is stored
     * that can be derived:
     *
     *   the number  from the row id and its year   NGV/2026/000042
     *   the code    an HMAC of the row id          NGVR-A1B2C3D4E5
     *   void        the payment row already says so
     *
     * The one thing that cannot be derived is WHEN a receipt was emailed, and
     * that is the one column added.
     *
     * ── THE NUMBER IS THE ROW ID ─────────────────────────────────────────────
     * Not a separate counter. A counter needs a table, a lock and a story about
     * gaps, and it can hand two staff members the same number on a Monday
     * morning. The autoincrement id is already unique and already monotonic on
     * every engine NGV runs on, and deriving from it means a receipt number can
     * never point at the wrong payment or at no payment.
     *
     * ── VOIDING IS THE CASE THAT MATTERS ─────────────────────────────────────
     * A payment entered twice gets voided, and somebody is holding a receipt for
     * money that is no longer on their account. Silence there is worse than
     * never issuing one: they believe they have paid. So a voided payment's
     * receipt says CANCELLED everywhere it appears, and voiding emails the
     * participant to say so.
     *
     * Only `payment` credits get one. A waiver is the programme deciding not to
     * ask; issuing a receipt for it would tell somebody they had paid money they
     * never handed over.
     */

    /** Receipts verify like certificates do (see NgvMember::certCode) — same
     *  HMAC, same constant-time comparison, same "reveals nothing" failure. */
    private const RECEIPT_PREFIX = 'NGVR';

    /** The number a participant quotes down the phone. Year plus the row id, so
     *  it sorts, reads as a reference, and cannot collide. */
    public static function receiptNo(array $pay): string
    {
        $year = substr((string) ($pay['created_at'] ?? ''), 0, 4);
        if (!preg_match('/^\d{4}$/', $year)) $year = self::today('Y');
        return 'NGV/' . $year . '/' . str_pad((string) (int) $pay['id'], 6, '0', STR_PAD_LEFT);
    }

    /** Short, unforgeable, storage-free. */
    public static function receiptCode(int $payId): string
    {
        $secret = function_exists('av_secret') ? (string) av_secret() : '';
        if ($secret === '') $secret = 'ngv-receipt-fallback';
        return self::RECEIPT_PREFIX . '-' . strtoupper(substr(hash_hmac('sha256', 'ngv-receipt:' . $payId, $secret), 0, 10));
    }

    /** One payment as a receipt, or null. Void rows are RETURNED, flagged — a
     *  cancelled receipt still has to be answerable when somebody produces it. */
    public static function receiptFor(int $payId): ?array
    {
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_payments WHERE id = ?');
        $st->execute([$payId]);
        $p = $st->fetch();
        if (!$p) return null;
        if ((string) ($p['credit_kind'] ?? 'payment') !== 'payment') return null;
        $who = self::participantRow((int) $p['member_id']) ?: [];
        return [
            'id' => (int) $p['id'],
            'no' => self::receiptNo($p),
            'code' => self::receiptCode((int) $p['id']),
            'memberId' => (int) $p['member_id'],
            'name' => (string) ($who['name'] ?? ''),
            'cohort' => (string) ($who['cohort'] ?? ''),
            'amount' => (int) $p['amount'],
            'line' => (string) $p['kind'],
            'lineLabel' => self::lineLabel((string) $p['kind']),
            'period' => (string) $p['period'],
            'method' => (string) $p['method'],
            'reference' => (string) $p['reference'],
            'note' => (string) $p['note'],
            'paidOn' => substr((string) $p['created_at'], 0, 10),
            'issuedAt' => (string) ($p['receipt_at'] ?? ''),
            'void' => (int) ($p['voided'] ?? 0) === 1,
            'voidReason' => (string) ($p['void_reason'] ?? ''),
            'email' => (string) ($who['email'] ?? ''),
        ];
    }

    /**
     * Verify a receipt from a link, for the public page.
     *
     * Constant-time, and a wrong code reveals nothing — not even whether that id
     * exists. Same posture as the certificate verifier.
     */
    public static function receiptForVerify(int $payId, string $code): ?array
    {
        if ($payId <= 0) return null;
        if (!hash_equals(self::receiptCode($payId), strtoupper(trim($code)))) return null;
        return self::receiptFor($payId);
    }

    /** Every receipt a participant has, newest first. */
    public static function receiptsFor(int $memberId): array
    {
        $st = NgvDb::pdo()->prepare("SELECT id FROM ngv_payments WHERE member_id = ? AND credit_kind = 'payment'
                                     ORDER BY created_at DESC, id DESC LIMIT 200");
        $st->execute([$memberId]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $r) {
            $rec = self::receiptFor((int) $r['id']);
            if ($rec) $out[] = $rec;
        }
        return $out;
    }

    /** The fee line, in the words a participant reads. */
    public static function lineLabel(string $kind): string
    {
        $map = ['membership' => 'Membership fee', 'commitment' => 'Monthly commitment',
                'programme' => 'Training fee', 'fine' => 'Fine', 'adjustment' => 'Adjustment',
                'other' => 'General payment'];
        return $map[$kind] ?? ucfirst($kind);
    }

    /**
     * Email a receipt.
     *
     * `$cancelled` sends the other letter — the one that says a receipt already
     * in somebody's inbox no longer stands. Both go through the same function so
     * they cannot drift apart in tone or in what they disclose.
     */
    public static function sendReceipt(int $payId, bool $cancelled = false): array
    {
        $r = self::receiptFor($payId);
        if (!$r) return ['ok' => false, 'error' => 'That payment has no receipt — only money received gets one.'];
        $to = trim((string) $r['email']);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'There is no email address on this record. The receipt can still be printed.'];
        }
        $first = trim(explode(' ', trim((string) $r['name']))[0] ?? '');
        if ($first === '') $first = 'there';
        $m = static fn(int $n) => self::money_text($n);
        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        $link = $site . '/academy/ngv/receipt.php?id=' . $r['id'] . '&c=' . rawurlencode($r['code']);

        $rows = [];
        if ($cancelled) {
            $subject = 'Cancelled: receipt ' . $r['no'];
            $rows[] = 'Hi ' . self::esc($first) . ' — receipt <b>' . self::esc($r['no']) . '</b> for '
                    . $m((int) $r['amount']) . ' has been cancelled.';
            /* Said plainly, because the alternative is somebody believing they
               have paid when their account says otherwise. */
            $rows[] = 'That payment is no longer on your account.'
                    . ($r['voidReason'] !== '' ? ' Reason given: ' . self::esc($r['voidReason']) . '.' : '')
                    . ' Usually this means it was entered twice or against the wrong person, and a corrected receipt follows.';
            $rows[] = 'If you did make this payment and it has not reappeared, reply to this message or speak to your '
                    . 'track lead — please do not assume it will sort itself out.';
        } else {
            $subject = 'Receipt ' . $r['no'] . ' — ' . $m((int) $r['amount']);
            $rows[] = 'Hi ' . self::esc($first) . ' — this confirms we have received <b>' . $m((int) $r['amount']) . '</b>.';
            $detail = [
                'Receipt' => $r['no'],
                'For'     => $r['lineLabel'] . ($r['period'] !== '' ? ' · ' . $r['period'] : ''),
                'Paid on' => $r['paidOn'],
            ];
            if ($r['method'] !== '')    $detail['How'] = $r['method'];
            if ($r['reference'] !== '') $detail['Reference'] = $r['reference'];
            $bits = [];
            foreach ($detail as $k => $v) $bits[] = self::esc((string) $k) . ': <b>' . self::esc((string) $v) . '</b>';
            $rows[] = implode('<br>', $bits);
            $rows[] = 'Keep this. You can view or print it any time at the link below, and anyone can check it is genuine '
                    . 'there without needing an account.';
            $rows[] = 'If anything here is wrong — the amount, what it was for, or the date — tell your track lead now '
                    . 'rather than later, while it is easy to correct.';
        }
        $ok = false;
        if (class_exists('Mailer')) {
            $html = Mailer::shell($cancelled ? 'Receipt cancelled' : 'Receipt', $rows,
                ['url' => $link, 'text' => $cancelled ? 'See the record' : 'View or print this receipt'], $subject);
            try { $ok = (bool) Mailer::send($to, $subject, $html); }
            catch (Throwable $e) { error_log('[ngvledger] receipt mail: ' . $e->getMessage()); }
        }
        if (class_exists('Notifications')) {
            try {
                Notifications::push((int) $r['memberId'], 'ngv_receipt',
                    $cancelled ? 'Receipt ' . $r['no'] . ' cancelled' : 'Receipt ' . $r['no'] . ' — ' . $m((int) $r['amount']),
                    $cancelled ? 'That payment is no longer on your account.' : $r['lineLabel'] . ' · paid ' . $r['paidOn'],
                    '/academy/ngv/dashboard.php#account',
                    'ngv_receipt:' . $payId . ($cancelled ? ':void' : ''));
            } catch (Throwable $e) { error_log('[ngvledger] receipt notify: ' . $e->getMessage()); }
        }
        /* Stamped either way — an unstamped row plus a mailer failing quietly is
           how a re-send button becomes a way to send somebody five receipts. */
        if (!$cancelled) {
            try {
                NgvDb::pdo()->prepare('UPDATE ngv_payments SET receipt_at = ? WHERE id = ?')
                    ->execute([self::nowStamp(), $payId]);
            } catch (Throwable $e) { error_log('[ngvledger] receipt stamp: ' . $e->getMessage()); }
        }
        self::audit($cancelled ? 'ngv_receipt_void' : 'ngv_receipt', 'ngv:member:' . (int) $r['memberId'],
            ($cancelled ? 'Cancelled receipt ' : 'Receipt ') . $r['no'] . ' — ' . $m((int) $r['amount'])
            . ($ok ? '' : ' (delivery failed)'));
        return ['ok' => true, 'delivered' => $ok, 'no' => $r['no'], 'to' => $to, 'link' => $link];
    }

    /**
     * Send receipts for payments recorded before receipts existed.
     *
     * ── ONE DIGEST PER PERSON, NOT ONE EMAIL PER PAYMENT ─────────────────────
     * This is the whole design. A participant eighteen months into the programme
     * has a membership payment and a dozen monthly commitments behind them, and
     * receipting those individually lands thirteen emails in their inbox inside
     * a second. That is the exact fault the per-payment suppress switch exists to
     * avoid, committed at roster scale — and the likely reading of thirteen
     * unexpected emails about money is not "how organised", it is "something has
     * gone wrong with my account".
     *
     * So the back-fill sends one message per participant, listing every receipt
     * with its number and link, and saying plainly why it is arriving now.
     *
     * ── THE QUEUE IS A COLUMN, NOT A CURSOR ──────────────────────────────────
     * "Un-receipted" is `receipt_at = ''`, which means the run is resumable by
     * construction: interrupt it, run it again, and it picks up exactly what it
     * did not finish. No offset to store, nothing to reset, and no way to skip
     * somebody by losing a position.
     *
     * Voided payments are excluded — back-filling a cancelled receipt to
     * somebody is pure confusion about money they no longer owe. Participants
     * with no address are counted separately and NOT stamped, so adding an
     * address later brings them back into the queue rather than losing them.
     */
    public static function backfillReceipts(int $limit = self::BACKFILL_BATCH, bool $preview = false): array
    {
        $limit = max(1, min(500, $limit));
        $pdo = NgvDb::pdo();
        $st = $pdo->query("SELECT p.id, p.member_id, p.amount, p.kind, p.period, p.method, p.created_at,
                                  m.name AS name, m.email AS email
                             FROM ngv_payments p
                             LEFT JOIN ngv_participants m ON m.member_id = p.member_id
                            WHERE p.credit_kind = 'payment' AND p.voided = 0
                              AND (p.receipt_at IS NULL OR p.receipt_at = '')
                            ORDER BY p.member_id, p.id");
        $byMember = []; $rows = 0; $oldest = '';
        foreach ($st->fetchAll() ?: [] as $r) {
            $byMember[(int) $r['member_id']][] = $r;
            $rows++;
            /* Tracked as the real minimum. The query groups by member so the
               first row is the first MEMBER's earliest payment, not the earliest
               overall — and "going back to" is the one figure on the panel that
               tells a coordinator how much history they are about to touch. */
            $when = substr((string) $r['created_at'], 0, 10);
            if ($when !== '' && ($oldest === '' || $when < $oldest)) $oldest = $when;
        }

        /* Split before doing anything, so the preview can distinguish "waiting to
           be sent" from "cannot be sent" — one is work, the other is a data
           problem, and reporting them as one number hides both. */
        $sendable = []; $noEmail = 0; $noEmailPayments = 0; $orphan = 0;
        foreach ($byMember as $mid => $pays) {
            $to = trim((string) ($pays[0]['email'] ?? ''));
            if (($pays[0]['name'] ?? null) === null) { $orphan++; continue; }   // payment with no participant row
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { $noEmail++; $noEmailPayments += count($pays); continue; }
            $sendable[$mid] = $pays;
        }
        $out = [
            'ok' => true,
            'pending' => $rows, 'people' => count($byMember),
            'sendable' => count($sendable), 'sendablePayments' => array_sum(array_map('count', $sendable)),
            'noEmail' => $noEmail, 'noEmailPayments' => $noEmailPayments,
            'orphan' => $orphan,
            'oldest' => $oldest,
            'limit' => $limit,
        ];
        if ($preview) {
            /* Named, so a coordinator pressing send knows who is about to hear
               from the programme about money after months of silence. */
            $out['who'] = [];
            foreach (array_slice($sendable, 0, 25, true) as $mid => $pays) {
                $out['who'][] = ['member_id' => $mid, 'name' => (string) $pays[0]['name'],
                                 'payments' => count($pays),
                                 'total' => array_sum(array_map(static fn($x) => (int) $x['amount'], $pays))];
            }
            return $out;
        }

        $sent = 0; $failed = 0; $stamped = 0;
        foreach (array_slice($sendable, 0, $limit, true) as $mid => $pays) {
            $r = self::sendReceiptDigest($mid, $pays);
            if (!empty($r['delivered'])) $sent++; else $failed++;
            $stamped += (int) ($r['stamped'] ?? 0);
        }
        $out['sent'] = $sent; $out['failed'] = $failed; $out['stamped'] = $stamped;
        $out['remaining'] = max(0, count($sendable) - $sent - $failed);
        self::audit('ngv_receipt_backfill', 'ngv:fees',
            'Back-filled receipts — ' . $stamped . ' payment(s) across ' . ($sent + $failed) . ' person(s), '
            . $sent . ' delivered, ' . $failed . ' failed'
            . ($out['remaining'] > 0 ? ', ' . $out['remaining'] . ' still to do' : '')
            . ($noEmail > 0 ? ' · ' . $noEmail . ' have no address' : ''));
        return $out;
    }

    /**
     * One message, every receipt somebody is owed.
     *
     * Says why it is arriving now, in the first line. An unexplained email
     * listing a year of payments reads as a demand or a mistake; "we have
     * switched receipts on and these are yours" reads as what it is.
     */
    private static function sendReceiptDigest(int $memberId, array $pays): array
    {
        $to = trim((string) ($pays[0]['email'] ?? ''));
        $first = trim(explode(' ', trim((string) ($pays[0]['name'] ?? '')))[0] ?? '');
        if ($first === '') $first = 'there';
        $m = static fn(int $n) => self::money_text($n);
        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        $total = 0; $lines = [];
        foreach ($pays as $p) {
            $id = (int) $p['id'];
            $total += (int) $p['amount'];
            $link = $site . '/academy/ngv/receipt.php?id=' . $id . '&c=' . rawurlencode(self::receiptCode($id));
            $lines[] = '<a href="' . self::esc($link) . '">' . self::esc(self::receiptNo($p)) . '</a> · '
                     . self::esc(substr((string) $p['created_at'], 0, 10)) . ' · '
                     . self::esc(self::lineLabel((string) $p['kind']))
                     . ((string) $p['period'] !== '' ? ' ' . self::esc((string) $p['period']) : '')
                     . ' · <b>' . $m((int) $p['amount']) . '</b>';
        }
        $n = count($pays);
        $rows = [];
        $rows[] = 'Hi ' . self::esc($first) . ' — we have switched on receipts for NextGen Vanguard, and '
                . ($n === 1 ? 'here is the one we owe you' : 'here are the ' . $n . ' we owe you')
                . ' for payments already on your account.';
        $rows[] = '<b>Nothing has changed about your account.</b> This is a record of what we have already received from '
                . 'you — ' . $m($total) . ' in total — not a request for anything.';
        $rows[] = implode('<br>', $lines);
        $rows[] = 'Each link opens a printable receipt that anyone can check without an account. Keep them; they are '
                . 'useful as proof of payment.';
        $rows[] = 'If any of these look wrong, or one you made is missing, tell your track lead — it is much easier to '
                . 'correct now than later.';

        $subject = $n === 1
            ? 'Your receipt — ' . self::receiptNo($pays[0])
            : 'Your ' . $n . ' NextGen Vanguard receipts';
        $ok = false;
        if (class_exists('Mailer')) {
            $html = Mailer::shell('Your receipts', $rows,
                ['url' => $site . '/academy/ngv/dashboard.php#account', 'text' => 'See my account'], $subject);
            try { $ok = (bool) Mailer::send($to, $subject, $html); }
            catch (Throwable $e) { error_log('[ngvledger] backfill mail: ' . $e->getMessage()); }
        }
        if (class_exists('Notifications')) {
            try {
                Notifications::push($memberId, 'ngv_receipt',
                    $n === 1 ? 'Your receipt is ready' : 'Your ' . $n . ' receipts are ready',
                    $m($total) . ' already received from you, now receipted.',
                    '/academy/ngv/dashboard.php#account', 'ngv_receipt_backfill:' . $memberId);
            } catch (Throwable $e) { error_log('[ngvledger] backfill notify: ' . $e->getMessage()); }
        }
        /* Stamped on ATTEMPT, like every other send here. An unstamped row plus a
           mailer failing quietly is how the next run sends the same digest again,
           and the one after that. The run reports the failure so staff can see it
           and re-send from the person's record. */
        $stamped = 0;
        $now = self::nowStamp();
        $upd = NgvDb::pdo()->prepare('UPDATE ngv_payments SET receipt_at = ? WHERE id = ?');
        foreach ($pays as $p) {
            try { $upd->execute([$now, (int) $p['id']]); $stamped++; }
            catch (Throwable $e) { error_log('[ngvledger] backfill stamp: ' . $e->getMessage()); }
        }
        self::audit('ngv_receipt_backfill_one', 'ngv:member:' . $memberId,
            $n . ' receipt(s) sent as one digest — ' . $m($total) . ($ok ? '' : ' (delivery failed)'));
        return ['delivered' => $ok, 'stamped' => $stamped, 'count' => $n];
    }

    /* ══ Statements ═════════════════════════════════════════════════════════
     *
     * A STATEMENT is not a REMINDER, and conflating them was the gap.
     *
     * A reminder chases money. It only goes to somebody who owes, it is gated on
     * a cadence, and it exists to produce a payment. Which means a participant
     * who is square with the programme — or who has been waived, or is three
     * instalments into a schedule and exactly on track — could never be told any
     * of that. The only letter the system could send was a demand.
     *
     * A statement says where you stand: every fee line, the training schedule
     * month by month, each fine with the reason it was issued, what has been set
     * aside for you, and any damage report and its status. It goes to anybody,
     * owing or not, and it is what somebody actually wants when they ask "what
     * is my position?". Staff send it; a participant can also ask for it from
     * their own dashboard, which is the whole point — the answer arrives without
     * anybody having to have a conversation about money in a corridor.
     */

    /** A participant may ask for their own statement once a day. Not a security
     *  bound — a courtesy one, so a nervous tap on a button four times does not
     *  send four letters. */
    public const STATEMENT_MIN_HOURS = 20;
    public const STATEMENT_BATCH = 50;
    /** People per back-fill press. Small on purpose: this is the one run that
     *  touches history rather than today, and a coordinator should be able to
     *  read what it did before pressing again. */
    public const BACKFILL_BATCH = 25;

    /**
     * The statement, as the rows an email is built from.
     *
     * Returned rather than sent, so the same text can be shown on a screen, and
     * so a test can read what a participant is told without a mail transport.
     */
    public static function statementRows(int $memberId): array
    {
        $a = self::account($memberId);
        $m = static fn(int $n) => self::money_text($n);
        $first = '';
        $p = self::participantRow($memberId);
        if ($p) $first = trim(explode(' ', trim((string) $p['name']))[0] ?? '');
        if ($first === '') $first = 'there';

        $rows = [];
        $rows[] = 'Hi ' . self::esc($first) . ' — here is where your NextGen Vanguard account stands today.';
        $rows[] = (int) $a['payable'] > 0
            ? 'Outstanding: <b>' . $m((int) $a['payable']) . '</b>.'
            : '<b>Nothing outstanding.</b> You are square with the programme.';

        /* Every line, including the settled ones. A statement that only listed
           arrears would be a reminder with a different subject line, and
           "membership: paid" is half the reason somebody asked. */
        $lines = [];
        foreach ($a['lines'] as $ln) {
            if ((int) $ln['charged'] === 0 && (int) $ln['paid'] === 0) continue;
            $state = (int) $ln['due'] > 0 ? $m((int) $ln['due']) . ' to pay' : 'settled';
            $lines[] = '<b>' . self::esc((string) $ln['label']) . '</b> — ' . $m((int) $ln['paid'])
                     . ' of ' . $m((int) $ln['charged']) . ' · ' . $state;
        }
        if ($lines) $rows[] = implode('<br>', $lines);
        else $rows[] = 'Nothing has been charged to your account yet.';

        /* The training schedule, month by month. The single most useful thing on
           here for anybody on a paid plan: what is on the account now versus
           what is still to come, which the running total cannot say. */
        $t = is_array($a['training'] ?? null) ? $a['training'] : [];
        if ($t && (int) ($t['months'] ?? 0) > 1) {
            $bits = [];
            foreach ($t['instalments'] as $ins) {
                $word = $ins['state'] === 'charged' ? 'on your account'
                      : ($ins['state'] === 'due' ? 'due' : 'to come');
                $bits[] = self::esc((string) $ins['period']) . ' — ' . $m((int) $ins['amount']) . ' · ' . $word;
            }
            $rows[] = '<b>Your training fee</b> — ' . $m((int) $t['paid']) . ' of ' . $m((int) $t['total'])
                    . ' paid, ' . (int) $t['settled'] . ' of ' . (int) $t['months'] . ' instalments charged.<br>'
                    . '<span style="color:#5f6874;font-size:13px">' . implode('<br>', $bits) . '</span>';
        }

        /* Fines, individually, with the reason each was issued and the date. A
           fines TOTAL is the one figure on an account nobody accepts — "₦4,500
           of fines" invites a dispute that "late arrival, 12 August" settles. */
        $fines = [];
        foreach ($a['entries'] as $en) {
            if ($en['side'] !== 'charge' || $en['kind'] !== 'fine' || $en['void']) continue;
            $fines[] = self::esc(substr((string) $en['created_at'], 0, 10)) . ' — ' . $m((int) $en['amount'])
                     . ' · ' . self::esc((string) ($en['note'] !== '' ? $en['note'] : ($en['reasonLabel'] ?: 'Fine')));
        }
        if ($fines) $rows[] = '<b>Fines</b><br><span style="color:#5f6874;font-size:13px">' . implode('<br>', $fines) . '</span>';

        /* Payments, each with its receipt number. A statement that says
           "₦10,000 received" and a receipt that says NGV/2026/000042 are the same
           event, and somebody reconciling the two should not have to guess. */
        $paid = [];
        foreach ($a['entries'] as $en) {
            if ($en['side'] !== 'credit' || $en['creditKind'] !== 'payment' || $en['void']) continue;
            $paid[] = self::esc(substr((string) $en['created_at'], 0, 10)) . ' — ' . $m((int) $en['amount'])
                    . ' · ' . self::esc(self::lineLabel((string) $en['kind']))
                    . ' · receipt ' . self::esc(self::receiptNo(['id' => (int) $en['id'], 'created_at' => (string) $en['created_at']]));
        }
        if ($paid) $rows[] = '<b>Payments received</b><br><span style="color:#5f6874;font-size:13px">' . implode('<br>', $paid) . '</span>';

        if ((int) $a['waived'] > 0) {
            $rows[] = 'Set aside for you: <b>' . $m((int) $a['waived']) . '</b>. That is money the programme has decided '
                    . 'not to ask you for — it is not owed, and it is not money you paid.';
        }
        if ((int) $a['paidAhead'] > 0) {
            $rows[] = 'Paid ahead: ' . $m((int) $a['paidAhead']) . ' on a fee that is already settled. It stays on your '
                    . 'record as paid ahead rather than being moved onto something else.';
        }

        /* Damage, with its status. Somebody who reported a cracked screen three
           weeks ago and has heard nothing is the person most in need of a line
           on this letter. */
        if (class_exists('NgvDamage')) {
            $dmg = [];
            foreach (NgvDamage::forMember($memberId) as $d) {
                $dmg[] = self::esc((string) $d['occurred_on']) . ' — ' . self::esc((string) $d['item'])
                       . ' · ' . self::esc((string) $d['statusLabel'])
                       . ((int) $d['charged'] > 0 ? ' (' . $m((int) $d['charged']) . ')' : '');
            }
            if ($dmg) $rows[] = '<b>Damage reports</b><br><span style="color:#5f6488;font-size:13px">' . implode('<br>', $dmg) . '</span>';
        }

        if (trim((string) $a['payTo']) !== '') $rows[] = self::esc((string) $a['payTo']);
        $rows[] = 'If any of this looks wrong, or this is a difficult month, reply to this message or speak to your track '
                . 'lead — we would rather hear from you than not.';
        if (trim((string) $a['note']) !== '') {
            $rows[] = '<span style="color:#5f6874;font-size:13px">' . self::esc((string) $a['note']) . '</span>';
        }
        return ['rows' => $rows, 'payable' => (int) $a['payable'], 'account' => $a];
    }

    /**
     * Send one.
     *
     * `$byMember` marks a participant asking for their own, which is the only
     * path that is rate-limited: staff sending a statement is a deliberate act
     * and does not need protecting from itself.
     */
    public static function sendStatement(int $memberId, bool $byMember = false): array
    {
        $p = self::participantRow($memberId);
        if (!$p) return ['ok' => false, 'error' => 'No such participant.'];
        $to = trim((string) ($p['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'There is no email address on this record.'];
        }
        if ($byMember) {
            $last = strtotime((string) ($p['statement_at'] ?? '')) ?: 0;
            if ($last > 0 && (time() - $last) < self::STATEMENT_MIN_HOURS * 3600) {
                return ['ok' => false, 'error' => 'We sent you one today — check your inbox, including spam.'];
            }
        }
        $st = self::statementRows($memberId);
        $subject = 'Your NextGen Vanguard account — '
                 . ((int) $st['payable'] > 0 ? self::money_text((int) $st['payable']) . ' outstanding' : 'nothing outstanding');
        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        $ok = false;
        if (class_exists('Mailer')) {
            $html = Mailer::shell('Your account', $st['rows'],
                ['url' => $site . '/academy/ngv/dashboard.php#account', 'text' => 'See my account'], $subject);
            try { $ok = (bool) Mailer::send($to, $subject, $html); }
            catch (Throwable $e) { error_log('[ngvledger] statement mail: ' . $e->getMessage()); }
        }
        /* Stamped either way, for the reason the reminder is: an unstamped record
           plus a mailer failing quietly is how somebody gets four letters. */
        try {
            NgvDb::pdo()->prepare('UPDATE ngv_participants SET statement_at = ? WHERE member_id = ?')
                ->execute([self::nowStamp(), $memberId]);
        } catch (Throwable $e) { error_log('[ngvledger] statement stamp: ' . $e->getMessage()); }
        self::audit('ngv_statement', 'ngv:member:' . $memberId,
            'Statement sent — ' . ((int) $st['payable'] > 0 ? self::money_text((int) $st['payable']) . ' outstanding' : 'nothing outstanding')
            . ($ok ? '' : ' (delivery failed)'),
            $byMember ? 'member:' . $memberId : 'admin');
        return ['ok' => true, 'delivered' => $ok, 'payable' => (int) $st['payable'],
                'to' => $to];
    }

    /**
     * Send statements to everybody with an account. Bounded per press.
     *
     * Deliberately NOT cadence-gated. A statement run is somebody choosing to
     * tell the cohort where they stand — end of term, start of a month — and a
     * "too soon" skip would silently drop people from a run staff believe went
     * out. The bound is on the batch, not on the person.
     */
    public static function sendStatements(int $limit = self::STATEMENT_BATCH): array
    {
        $limit = max(1, min(500, $limit));
        $out = ['sent' => 0, 'failed' => 0, 'noEmail' => 0, 'notActive' => 0, 'considered' => 0];
        foreach (self::sweep() as $id => $row) {
            if ($out['sent'] + $out['failed'] >= $limit) break;
            $out['considered']++;
            $p = $row['person'];
            if ((string) ($p['status'] ?? '') !== 'active') { $out['notActive']++; continue; }
            $to = trim((string) ($p['email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { $out['noEmail']++; continue; }
            $r = self::sendStatement($id, false);
            if (!empty($r['ok']) && !empty($r['delivered'])) $out['sent']++; else $out['failed']++;
        }
        self::audit('ngv_statement_run', 'ngv:fees',
            $out['sent'] . ' sent, ' . $out['failed'] . ' failed of ' . $out['considered'] . ' considered');
        return ['ok' => true] + $out;
    }

    /** Send them. Bounded per run, and safe to call as often as the cron fires —
     *  the per-participant `everyDays` gate decides, not the schedule. */
    public static function runReminders(int $limit = self::REMIND_BATCH): array
    {
        $cfg = self::settings();
        if (empty($cfg['enabled']) || empty($cfg['remindEnabled'])) return ['ok' => false, 'error' => 'reminders_off'];
        $limit = max(1, min(self::REMIND_BATCH_MAX, $limit));
        $c = self::reminderCandidates($limit);
        $sent = 0; $failed = 0;
        foreach ($c['due'] as $cand) { if (self::sendReminder($cand)) $sent++; else $failed++; }
        if ($sent + $failed > 0) {
            self::audit('ngv_fee_remind_run', 'ngv:fees',
                $sent . ' sent, ' . $failed . ' failed of ' . count($c['due']) . ' due', 'cron');
        }
        return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'due' => count($c['due']),
                'skipped' => $c['skipped'], 'everyDays' => $c['everyDays']];
    }

    /**
     * Everything the cron does here, in one call.
     *
     * Accrual runs FIRST: a reminder computed before the month's commitment has
     * been posted would quote a figure that is out of date by the time it lands.
     */
    public static function cronTick(): array
    {
        $out = [];
        if (!self::enabled()) return ['skipped' => 'disabled'];
        try { $out['accrued'] = self::accrueAll(self::ACCRUE_BATCH); }
        catch (Throwable $e) { error_log('[ngvledger] cron accrue: ' . $e->getMessage()); }
        try { $out['reminders'] = self::runReminders(self::REMIND_CRON_BATCH); }
        catch (Throwable $e) { error_log('[ngvledger] cron remind: ' . $e->getMessage()); }
        try { $out['review'] = self::noteReviewDue(); }
        catch (Throwable $e) { error_log('[ngvledger] cron review: ' . $e->getMessage()); }
        /* Nobody waits on a programme more patiently than somebody who was told
           "we are finding out what it costs" and has heard nothing since. They
           cannot chase it; this says so on their behalf. */
        if (class_exists('NgvDamage')) {
            try { $out['damage'] = NgvDamage::noteStale(); }
            catch (Throwable $e) { error_log('[ngvledger] cron damage: ' . $e->getMessage()); }
        }
        return $out;
    }

    /* ══ Small shared pieces ════════════════════════════════════════════════ */

    public static function money($v, int $fallback = 0): int
    {
        if ($v === null || $v === '') return $fallback;
        return max(0, min(self::AMOUNT_MAX, (int) round((float) $v)));
    }

    /** Money as a participant reads it. Whole units — nothing here is in kobo. */
    public static function money_text(int $n): string { return '₦' . number_format(max(0, $n)); }

    private static function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

    /**
     * A timestamp for a column that is COMPARED rather than displayed.
     *
     * UTC, because SQLite's `datetime('now')` — which stamps every row the DB
     * writes itself — is UTC, and because `reminded_at` is read back through
     * `strtotime()` against `time()`. An org-local wall clock here would put
     * every stamp an hour into the future and quietly skew the cadence gate.
     */
    private static function nowStamp(): string { return gmdate('Y-m-d H:i:s'); }

    private static function today(string $fmt = 'Y-m-d'): string
    {
        if ($fmt === 'Y-m-d' && function_exists('av_today_tz')) return av_today_tz();
        if (function_exists('av_now_tz')) return av_now_tz($fmt);
        return date($fmt);
    }

    /** The day a participant's obligations start: their enrolment date, falling
     *  back to when the row was created. A row imported without a start date
     *  must not be treated as joining today on every import. */
    public static function startDate(array $p): string
    {
        $s = self::validDate(substr((string) ($p['start_date'] ?? ''), 0, 10));
        if ($s !== '') return $s;
        return self::validDate(substr((string) ($p['created_at'] ?? ''), 0, 10));
    }

    private static function validDate(string $d): string
    {
        $d = substr(trim($d), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
    }

    /** A credit's period, when staff say which month or year it was for. */
    private static function validPeriod(string $p): string
    {
        $p = trim($p);
        return preg_match('/^\d{4}(-\d{2})?$/', $p) ? $p : '';
    }

    public static function periodKey(string $date, string $cadence): string
    {
        return $cadence === 'month' ? substr($date, 0, 7) : substr($date, 0, 4);
    }

    /**
     * Every period key from $start to $upto inclusive, bounded so an ancient or
     * malformed start date cannot generate ten thousand charges in one run.
     */
    public static function periods(string $start, string $upto, string $cadence, int $max = self::PERIODS_MAX): array
    {
        if ($cadence === 'once') return ['once'];
        $out = [];
        $step = $cadence === 'month' ? '+1 month' : '+1 year';
        $t = strtotime(($cadence === 'month' ? substr($start, 0, 8) . '01' : substr($start, 0, 4) . '-01-01') . ' 00:00:00 UTC');
        if ($t === false) return $out;
        $end = self::periodKey($upto, $cadence);
        for ($i = 0; $i < $max; $i++) {
            $k = self::periodKey(gmdate('Y-m-d', $t), $cadence);
            if ($k > $end) break;
            $out[] = $k;
            $t = strtotime($step, $t);
            if ($t === false) break;
        }
        return $out;
    }

    /** Whole calendar months from $a to $b, never negative. */
    public static function monthsBetween(string $a, string $b): int
    {
        $x = strtotime($a . ' 00:00:00 UTC'); $y = strtotime($b . ' 00:00:00 UTC');
        if ($x === false || $y === false || $y <= $x) return 0;
        $m = ((int) gmdate('Y', $y) - (int) gmdate('Y', $x)) * 12 + ((int) gmdate('n', $y) - (int) gmdate('n', $x));
        if ((int) gmdate('j', $y) < (int) gmdate('j', $x)) $m--;
        return max(0, $m);
    }

    private static function daysBetween(string $a, string $b): int
    {
        $x = strtotime($a . ' 00:00:00 UTC'); $y = strtotime($b . ' 00:00:00 UTC');
        if ($x === false || $y === false || $y <= $x) return 0;
        return (int) floor(($y - $x) / 86400);
    }

    private static function participantRow(int $memberId): ?array
    {
        if ($memberId <= 0) return null;
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_participants WHERE member_id = ?');
        $st->execute([$memberId]);
        return $st->fetch() ?: null;
    }

    /** The catalogue entry for a participant's chosen plan, or null. */
    private static function planFor(array $p): ?array
    {
        $name = trim((string) ($p['plan'] ?? ''));
        if ($name === '') return null;
        $cat = self::planCatalogue();
        return $cat[$name] ?? null;
    }

    /** Every money movement is on the trail, under the `ngv` area. Money that
     *  changed with nobody's name against it is the thing an audit is for. */
    private static function audit(string $action, string $target, string $detail, string $actor = 'admin'): void
    {
        if (!class_exists('AdminAudit')) return;
        try { AdminAudit::log('ngv', $action, $target, $detail, null, $actor); }
        catch (Throwable $e) { error_log('[ngvledger] audit: ' . $e->getMessage()); }
    }
}
