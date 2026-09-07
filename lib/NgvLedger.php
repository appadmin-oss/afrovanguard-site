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
            'accrueFrom'        => self::validDate((string) ($in['accrueFrom'] ?? $cur['accrueFrom'])),
            'balanceCap'        => self::money($in['balanceCap'] ?? $cur['balanceCap']),
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
     * Returns true only when a row was actually created.
     */
    public static function postCharge(int $memberId, string $kind, int $amount, string $period, array $opt = []): bool
    {
        if ($memberId <= 0 || $amount <= 0) return false;
        if (!in_array($kind, self::CHARGE_KINDS, true)) return false;
        $amount = self::money($amount);
        if ($amount <= 0) return false;
        $pdo = NgvDb::pdo();
        $st = $pdo->prepare('SELECT id FROM ngv_charges WHERE member_id = ? AND kind = ? AND period = ?');
        $st->execute([$memberId, $kind, $period]);
        if ($st->fetchColumn() !== false) return false;
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
            return false;
        }
        /* Absent a moment ago and present now. In the rare lost race two callers
           both report having created it, which over-counts an accrual's report by
           one — the unique index has already done the job that matters, which is
           that only one row exists. */
        $st->execute([$memberId, $kind, $period]);
        return $st->fetchColumn() !== false;
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
        /* Off by default — see the header. On, it behaves exactly like the other
           two, which is precisely why it is not on by default: the participant
           chooses the plan it is priced from. */
        if (!empty($cfg['trainingAuto'])) {
            $plan = self::planFor($p);
            if ($plan !== null && (int) $plan['fee'] > 0) {
                foreach (self::periods($start, $today, $plan['cadence']) as $k) {
                    if (self::postCharge($memberId, 'programme', (int) $plan['fee'], $k,
                            ['note' => $plan['name'] . ' — training fee ' . $k, 'source' => 'accrual'])) $out['programme']++;
                }
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

    /**
     * Raise the training fee for a participant's plan. One press, prefilled from
     * the catalogue, idempotent for the period.
     *
     * This is the path the training fee normally takes, and the reason it is a
     * separate operation rather than a line in the accrual: the plan is
     * self-selected, so somebody has to confirm that this participant really is
     * on the paid programme before the ledger will believe it.
     */
    public static function raiseTrainingFee(int $memberId, int $byUid, ?string $asOf = null): array
    {
        $p = self::participantRow($memberId);
        if (!$p) return ['ok' => false, 'error' => 'No such participant.'];
        $plan = self::planFor($p);
        if ($plan === null) return ['ok' => false, 'error' => 'They have not chosen a plan yet.'];
        if ((int) $plan['fee'] <= 0) return ['ok' => false, 'error' => $plan['name'] . ' is free — there is no training fee to raise.'];
        $period = $plan['cadence'] === 'once'
            ? 'plan'
            : self::periodKey($asOf ?: self::today('Y-m-d'), $plan['cadence']);
        $note = $plan['name'] . ' — training fee' . ($period !== 'plan' ? ' ' . $period : '');
        if (!self::postCharge($memberId, 'programme', (int) $plan['fee'], $period,
                ['note' => $note, 'source' => 'staff', 'by' => $byUid])) {
            return ['ok' => false, 'error' => 'Already raised for ' . ($period === 'plan' ? 'this plan' : $period) . '.'];
        }
        self::audit('ngv_fee_training', 'ngv:member:' . $memberId,
            'Training fee raised — ' . self::money_text((int) $plan['fee']) . ' · ' . $note);
        return ['ok' => true, 'amount' => (int) $plan['fee'], 'period' => $period, 'plan' => $plan['name']];
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
        if (!self::postCharge($memberId, $kind, $amount, $period,
                ['reason' => $reason, 'note' => $note !== '' ? $label . ' — ' . $note : $label,
                 'source' => 'staff', 'by' => $byUid])) {
            return ['ok' => false, 'error' => 'Could not post that charge.'];
        }
        self::audit('ngv_fee_' . $kind, 'ngv:member:' . $memberId,
            ucfirst($kind) . ' ' . self::money_text($amount) . ' — ' . $label . ($note !== '' ? ': ' . $note : ''));
        $b = self::balance($memberId);
        return ['ok' => true, 'amount' => $amount, 'payable' => (int) $b['payable'],
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
        return ['ok' => true, 'id' => $id, 'amount' => $amount, 'payable' => (int) self::balance($memberId)['payable']];
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
        return ['ok' => true];
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

    /* ══ Reading an account ═════════════════════════════════════════════════ */

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
     * The arithmetic, and nothing else: two queries, no plan catalogue, no entry
     * list. Split out from `account()` because the arrears sweep, the reminder
     * sweep and the accrual's cap check all need the FIGURE for hundreds of
     * people and none of them need the prose — building a full account per row
     * turned a roster scan into four queries a head.
     */
    public static function balance(int $memberId): array
    {
        $cfg = self::settings();
        $chargedBy = []; $paidBy = []; $countBy = [];
        $charged = 0; $credited = 0;
        foreach (self::charges($memberId) as $c) {
            $k = (string) $c['kind']; $a = (int) $c['amount'];
            $chargedBy[$k] = ($chargedBy[$k] ?? 0) + $a;
            $countBy[$k] = ($countBy[$k] ?? 0) + 1;
            $charged += $a;
        }
        /* `credited` settles a line whatever kind of credit it was; `received`
           and `waived` split it, because "you have paid ₦12,000" when ₦2,000 of
           that was the programme deciding not to ask is a sentence that is not
           true of the person reading it. */
        $received = 0; $waived = 0;
        foreach (self::credits($memberId) as $c) {
            $k = (string) ($c['kind'] ?? 'other'); $a = (int) $c['amount'];
            $paidBy[$k] = ($paidBy[$k] ?? 0) + $a;
            $credited += $a;
            if ((string) ($c['credit_kind'] ?? 'payment') === 'waiver') $waived += $a;
            elseif ((string) ($c['credit_kind'] ?? 'payment') !== 'writeoff') $received += $a;
        }
        /* Credits recorded before this ledger existed carry no line, and neither
           does a payment nobody could allocate. They form a pool that reduces the
           ACCOUNT rather than a line — the honest treatment of money that arrived
           without a note saying what it was for. */
        $pool = (int) ($paidBy['other'] ?? 0);

        $due = []; $shortfall = 0; $ahead = 0;
        foreach (self::CHARGE_KINDS as $k) {
            $d = max(0, (int) ($chargedBy[$k] ?? 0) - (int) ($paidBy[$k] ?? 0));
            $due[$k] = $d;
            $shortfall += $d;
            $ahead += max(0, (int) ($paidBy[$k] ?? 0) - (int) ($chargedBy[$k] ?? 0));
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
                'detail' => self::lineDetail($k, $ch, $pd, (int) $b['due'][$k], $plan, $amt),
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
    private static function lineDetail(string $k, int $charged, int $paid, int $due, ?array $plan, array $amt): string
    {
        $m = fn(int $n) => self::money_text($n);
        if ($k === 'programme') {
            if ($plan === null) return 'No plan chosen yet — pick one, or speak to your track lead.';
            if ($charged === 0 && (int) $plan['fee'] === 0) {
                return $plan['name'] . ' is free' . ($plan['note'] !== '' ? ' (' . $plan['note'] . ')' : '');
            }
            if ($charged === 0) return $plan['name'] . ' · ' . $plan['priceLabel'] . ' — not yet raised on your account';
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
        $pdo = NgvDb::pdo();
        /* Only look at people with at least one charge — the roster is bigger
           than the ledger and the difference is people who owe nothing. */
        $ids = [];
        foreach ($pdo->query('SELECT DISTINCT member_id FROM ngv_charges WHERE voided = 0 LIMIT ' . self::SCAN_MAX)->fetchAll() ?: [] as $r) {
            $ids[] = (int) $r['member_id'];
        }
        $out = []; $totalPayable = 0;
        foreach ($ids as $id) {
            $b = self::balance($id);
            if ((int) $b['payable'] <= 0) continue;
            $p = self::participantRow($id) ?: [];
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
                'skipped' => ['off' => 0, 'optedOut' => 0, 'notActive' => 0, 'nothingPayable' => 0,
                              'underMinimum' => 0, 'tooSoon' => 0, 'noEmail' => 0]];
        if (empty($cfg['enabled']) || empty($cfg['remindEnabled'])) { $out['skipped']['off'] = 1; return $out; }
        $everyDays = max(self::REMIND_MIN_DAYS, (int) $cfg['remindEveryDays']);
        $out['everyDays'] = $everyDays;
        $minBal = max(1, (int) $cfg['remindMinBalance']);
        $now = time();
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_participants ORDER BY id LIMIT ' . max(1, min(self::SCAN_MAX, $limit * 5)));
        $st->execute();
        foreach ($st->fetchAll() ?: [] as $p) {
            if (count($out['due']) >= $limit) break;
            if ((string) ($p['status'] ?? '') !== 'active') { $out['skipped']['notActive']++; continue; }
            if ((int) ($p['remind_off'] ?? 0) === 1)        { $out['skipped']['optedOut']++; continue; }
            $b = self::balance((int) $p['member_id']);
            if ((int) $b['payable'] <= 0)      { $out['skipped']['nothingPayable']++; continue; }
            if ((int) $b['payable'] < $minBal) { $out['skipped']['underMinimum']++; continue; }
            $last = strtotime((string) ($p['reminded_at'] ?? '')) ?: 0;
            if ($last > 0 && ($now - $last) < $everyDays * 86400) { $out['skipped']['tooSoon']++; continue; }
            $to = trim((string) ($p['email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { $out['skipped']['noEmail']++; continue; }
            $out['due'][] = ['member_id' => (int) $p['member_id'], 'name' => (string) $p['name'],
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
