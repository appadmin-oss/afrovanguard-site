<?php
/**
 * lib/NgvRegistration.php — applying to NGV online, and finishing at a CACENTRE.
 *
 * The online form is the FIRST step, not the registration. Registration is
 * completed in person: the applicant comes to a CACENTRE with the MoU printed
 * and signed, their documents, and their guarantor, who must come too. Until
 * then nobody has an account, a card or a charge.
 *
 *   new / reviewing ─ invite ─▶ invited ─ book ─▶ booked ─ desk ─▶ attended ─ desk ─▶ enrolled
 *        │                         │  ▲              │ no-show ┘     (documents       (registered)
 *        └─ rejected               │  └──────────────┘               outstanding)
 *                                  └─ lapsed (not taken up in INVITE_LAPSE_DAYS, or NO_SHOWS_MAX no-shows)
 *
 * Booking is advised, not required: a walk-in with their reference is served
 * the same way at the desk. Everything the office decides — what to bring, the
 * MoU's words, the times each centre takes people, the questions an applicant
 * answers to show they understand — lives on the NGV page's content (Ngv) and is
 * edited in the Studio. Nothing here hard-codes it.
 *
 * The guarantor vouches for the applicant's CHARACTER and is contacted about
 * their conduct. They are not responsible for fees; the MoU says so. Anybody
 * who knows the applicant may be one — a parent or guardian, an employer, a
 * faith leader, a community leader — except that a child's guarantor must be
 * their parent or guardian.
 */
declare(strict_types=1);

final class NgvRegistration
{
    public const RELATIONSHIPS = [
        'parent' => 'Parent', 'guardian' => 'Guardian', 'employer' => 'Employer',
        'faith_leader' => 'Faith leader', 'community_leader' => 'Community leader', 'other' => 'Other',
    ];
    public const MINOR_RELATIONSHIPS = ['parent', 'guardian'];
    /** An invitation nobody books or walks in on is closed after this long. */
    public const INVITE_LAPSE_DAYS = 30;
    /** Missed bookings before an invitation lapses. */
    public const NO_SHOWS_MAX = 2;
    /** How far ahead a visit can be booked. */
    public const BOOK_AHEAD_DAYS = 28;
    /** Unambiguous letters and digits — no 0/O, 1/I/L — because it is read aloud and typed off paper. */
    private const REF_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /* ══ Reference ══════════════════════════════════════════════════════════ */

    public static function newRef(): string
    {
        for ($try = 0; $try < 8; $try++) {
            $r = 'NGV-';
            for ($i = 0; $i < 6; $i++) $r .= self::REF_CHARS[random_int(0, strlen(self::REF_CHARS) - 1)];
            $st = NgvDb::pdo()->prepare('SELECT 1 FROM ngv_applications WHERE ref = ?');
            $st->execute([$r]);
            if (!$st->fetchColumn()) return $r;
        }
        return 'NGV-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /** The application's reference, minting one for a row from before references existed. */
    public static function ensureRef(int $id): string
    {
        $app = NgvMember::application($id);
        if (!$app) return '';
        if ((string) ($app['ref'] ?? '') !== '') return (string) $app['ref'];
        $ref = self::newRef();
        NgvDb::pdo()->prepare("UPDATE ngv_applications SET ref = ? WHERE id = ? AND ref = ''")->execute([$ref, $id]);
        return (string) (NgvMember::application($id)['ref'] ?? $ref);
    }

    public static function cleanRef(string $ref): string
    {
        $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $ref) ?? '');
        if (str_starts_with($ref, 'NGV')) $ref = substr($ref, 3);
        return $ref === '' ? '' : 'NGV-' . $ref;
    }

    /**
     * An applicant's own application, by reference AND email. The reference is
     * printed on paper that travels; on its own it would let whoever holds a
     * photocopy read somebody's details.
     */
    public static function forApplicant(string $ref, string $email): ?array
    {
        $ref = self::cleanRef($ref);
        $email = mb_strtolower(trim($email));
        if ($ref === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_applications WHERE ref = ?');
        $st->execute([$ref]);
        $app = $st->fetch() ?: null;
        return ($app && hash_equals(mb_strtolower((string) $app['email']), $email)) ? $app : null;
    }

    /**
     * The key that opens an application's page from an emailed link, so the
     * applicant does not have to type their email again. Signed with the
     * site's secret under its own purpose label; it is as private as the email
     * it was sent to, and no more.
     */
    public static function key(string $ref): string
    {
        return function_exists('av_mac') ? substr(av_mac('ngv-application', self::cleanRef($ref)), 0, 24) : '';
    }

    /** An application by reference and emailed key. */
    public static function forKey(string $ref, string $key): ?array
    {
        $ref = self::cleanRef($ref);
        $want = self::key($ref);
        if ($ref === '' || $want === '' || !hash_equals($want, $key)) return null;
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_applications WHERE ref = ?');
        $st->execute([$ref]);
        return $st->fetch() ?: null;
    }

    /** The applicant's own link to a page of their application. */
    public static function link(string $ref, string $page = 'status.php'): string
    {
        return self::site() . '/academy/ngv/' . $page . '?ref=' . rawurlencode(self::cleanRef($ref)) . '&k=' . rawurlencode(self::key($ref));
    }

    /** Staff: by reference alone (the desk has the person standing there). */
    public static function forDesk(string $ref): ?array
    {
        $ref = self::cleanRef($ref);
        if ($ref === '') return null;
        $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_applications WHERE ref = ?');
        $st->execute([$ref]);
        return $st->fetch() ?: null;
    }

    public static function isNgg(array $app): bool { return (string) ($app['source'] ?? '') === 'ngg'; }

    /* ══ Understanding ══════════════════════════════════════════════════════ */

    /** @return list<array{q:string, options:list<string>, answer:int, explain:string}> */
    public static function questions(): array
    {
        $out = [];
        foreach ((array) (Ngv::get()['reg_understand'] ?? []) as $q) {
            $opts = array_values(array_filter(array_map('strval', (array) ($q['options'] ?? [])), static fn($o) => trim($o) !== ''));
            $ans = (int) ($q['answer'] ?? 0);
            if (trim((string) ($q['q'] ?? '')) === '' || count($opts) < 2 || $ans < 1 || $ans > count($opts)) continue;
            $out[] = ['q' => (string) $q['q'], 'options' => $opts, 'answer' => $ans, 'explain' => (string) ($q['explain'] ?? '')];
        }
        return $out;
    }

    /**
     * Mark the answers. Not a gate anybody fails for good: what comes back is
     * which were wrong and the explanation, and they answer again.
     *
     * @param array<int|string, int|string> $answers question index => chosen option (1-based)
     * @return array{ok:bool, wrong:array<int,string>}
     */
    public static function checkUnderstanding(array $answers): array
    {
        $wrong = [];
        foreach (self::questions() as $i => $q) {
            if ((int) ($answers[$i] ?? 0) !== $q['answer']) $wrong[$i] = $q['explain'] !== '' ? $q['explain'] : $q['options'][$q['answer'] - 1];
        }
        return ['ok' => $wrong === [], 'wrong' => $wrong];
    }

    /* ══ Guarantor ══════════════════════════════════════════════════════════ */

    /**
     * The guarantor as given on the form, or an error saying what is missing.
     *
     * @return array{ok:bool, error?:string, g?:array}
     */
    public static function cleanGuarantor(array $d, bool $minor, string $applicantEmail): array
    {
        $g = [
            'g_name' => mb_substr(trim((string) ($d['g_name'] ?? '')), 0, 120),
            'g_relationship' => (string) ($d['g_relationship'] ?? ''),
            'g_relationship_other' => mb_substr(trim((string) ($d['g_relationship_other'] ?? '')), 0, 80),
            'g_phone' => mb_substr(trim((string) ($d['g_phone'] ?? '')), 0, 40),
            'g_email' => mb_strtolower(mb_substr(trim((string) ($d['g_email'] ?? '')), 0, 160)),
        ];
        if ($g['g_name'] === '' || $g['g_phone'] === '') return ['ok' => false, 'error' => 'Give your guarantor’s name and phone number.'];
        if (!isset(self::RELATIONSHIPS[$g['g_relationship']])) return ['ok' => false, 'error' => 'Say how your guarantor knows you.'];
        if ($g['g_relationship'] === 'other' && $g['g_relationship_other'] === '') return ['ok' => false, 'error' => 'Say how your guarantor knows you.'];
        if ($minor && !in_array($g['g_relationship'], self::MINOR_RELATIONSHIPS, true)) {
            return ['ok' => false, 'error' => 'You are under 18, so your guarantor must be your parent or guardian.'];
        }
        if ($g['g_email'] !== '' && !filter_var($g['g_email'], FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Your guarantor’s email address does not look right.'];
        if ($g['g_email'] !== '' && $g['g_email'] === mb_strtolower(trim($applicantEmail))) {
            return ['ok' => false, 'error' => 'Your guarantor’s email has to be their own, not yours.'];
        }
        if ($minor && $g['g_email'] === '') return ['ok' => false, 'error' => 'You are under 18: give your parent or guardian’s email, so we can tell them about your application.'];
        return ['ok' => true, 'g' => $g];
    }

    public static function relationshipLabel(array $app): string
    {
        $r = (string) ($app['g_relationship'] ?? '');
        if ($r === 'other') return trim((string) ($app['g_relationship_other'] ?? '')) ?: 'Other';
        return self::RELATIONSHIPS[$r] ?? '';
    }

    /* ══ What to bring ══════════════════════════════════════════════════════ */

    /** The checklist items that apply to this application, keyed by a hash of the label. */
    public static function checklistFor(array $app): array
    {
        $minor = NgvMember::applicantIsMinor($app);
        $ngg = self::isNgg($app);
        $out = [];
        foreach ((array) (Ngv::get()['reg_checklist'] ?? []) as $it) {
            $label = trim((string) ($it['label'] ?? ''));
            if ($label === '') continue;
            $applies = (string) ($it['applies'] ?? 'all');
            if (($applies === 'minor' && !$minor) || ($applies === 'adult' && $minor) || ($applies === 'ngg' && !$ngg)) continue;
            $out[substr(sha1($label), 0, 10)] = ['label' => $label, 'desc' => (string) ($it['desc'] ?? ''), 'required' => !empty($it['required'])];
        }
        return $out;
    }

    /* ══ Invitation ═════════════════════════════════════════════════════════ */

    /**
     * Invite an applicant to come in and finish registering. Emails them the
     * link to their application page (pack, MoU, booking), and tells the
     * guarantor they have been named. Nothing else is created.
     */
    public static function invite(int $id, int $byUid): array
    {
        $app = NgvMember::application($id);
        if (!$app) return ['ok' => false, 'error' => 'Application not found.'];
        if (!in_array((string) $app['status'], ['new', 'reviewing', 'accepted', 'lapsed'], true)) {
            return ['ok' => false, 'error' => 'This application is ' . $app['status'] . ' — it cannot be invited now.'];
        }
        $ref = self::ensureRef($id);
        NgvDb::pdo()->prepare("UPDATE ngv_applications SET status = 'invited', invited_at = ?, no_shows = 0, reviewed_by = ? WHERE id = ?")
            ->execute([self::now(), max(0, $byUid), $id]);
        self::mail((string) $app['email'], 'You are invited to register for NextGen Vanguard', 'Come in and finish registering',
            ['Your application has been reviewed, and you are invited to register.',
             'To finish, come to a CACENTRE with: the MoU printed and signed by you and your guarantor, your ID, and your guarantor in person. Your application page has the MoU to print, the full list of what to bring, and the times you can book.',
             'Your reference is <b>' . self::esc($ref) . '</b>. Bring it with you.',
             'The invitation stays open for ' . self::INVITE_LAPSE_DAYS . ' days.'],
            ['text' => 'Open your application', 'url' => self::link($ref)]);
        $g = mb_strtolower(trim((string) ($app['g_email'] ?? '')));
        if (filter_var($g, FILTER_VALIDATE_EMAIL)) {
            self::mail($g, (trim((string) $app['name']) ?: 'An applicant') . ' named you as their guarantor', 'NextGen Vanguard',
                ['<b>' . self::esc((string) $app['name']) . '</b> has been invited to register for NextGen Vanguard, Afrovanguard Academy’s one-year skills and leadership programme, and named you as their guarantor.',
                 'A guarantor vouches for the applicant’s character, and may be contacted about their conduct. A guarantor is <b>not</b> responsible for their fees.',
                 'The guarantor has to come to the CACENTRE in person, with ID, when they register. If you did not agree to this, reply to this email and tell us.'],
                null);
        }
        return ['ok' => true, 'ref' => $ref];
    }

    /* ══ Booking ════════════════════════════════════════════════════════════ */

    /** The centres taking registrations, as the office set them. */
    public static function centres(): array
    {
        $out = [];
        foreach ((array) (Ngv::get()['reg_slots'] ?? []) as $c) {
            $name = trim((string) ($c['centre'] ?? ''));
            if ($name === '') continue;
            $days = [];
            foreach (preg_split('/[^A-Za-z]+/', strtolower((string) ($c['days'] ?? ''))) ?: [] as $d) {
                $n = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7][substr($d, 0, 3)] ?? 0;
                if ($n) $days[$n] = true;
            }
            $times = [];
            foreach ((array) ($c['times'] ?? []) as $t) if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim((string) $t))) $times[] = trim((string) $t);
            sort($times);
            $out[$name] = ['name' => $name, 'weekdays' => array_keys($days), 'times' => array_values(array_unique($times)),
                           'capacity' => max(1, (int) ($c['capacity'] ?? 1))];
        }
        return $out;
    }

    /**
     * Times that can still be booked at a centre, from tomorrow, with how many
     * places are left. A day the centre is closed for (a portal holiday) is
     * not offered.
     *
     * @return list<array{date:string, time:string, left:int}>
     */
    public static function slots(string $centre, ?string $from = null, int $days = self::BOOK_AHEAD_DAYS): array
    {
        $c = self::centres()[$centre] ?? null;
        if (!$c || !$c['weekdays'] || !$c['times']) return [];
        $from = $from ?: self::addDays(self::today(), 1);
        $to = self::addDays($from, max(1, min(self::BOOK_AHEAD_DAYS, $days)) - 1);
        $taken = [];
        $st = NgvDb::pdo()->prepare("SELECT slot_date, slot_time, COUNT(*) AS n FROM ngv_bookings
                                     WHERE centre = ? AND status = 'booked' AND slot_date >= ? AND slot_date <= ? GROUP BY slot_date, slot_time");
        $st->execute([$centre, $from, $to]);
        foreach ($st->fetchAll() ?: [] as $r) $taken[$r['slot_date'] . ' ' . $r['slot_time']] = (int) $r['n'];
        $holidays = class_exists('GateAttendance') ? GateAttendance::holidays() : [];
        $out = [];
        for ($d = $from; $d <= $to; $d = self::addDays($d, 1)) {
            if (!in_array((int) gmdate('N', (int) strtotime($d . ' 12:00:00 UTC')), $c['weekdays'], true) || in_array($d, $holidays, true)) continue;
            foreach ($c['times'] as $t) {
                $left = $c['capacity'] - ($taken[$d . ' ' . $t] ?? 0);
                if ($left > 0) $out[] = ['date' => $d, 'time' => $t, 'left' => $left];
            }
        }
        return $out;
    }

    /** The application's live booking, if any. */
    public static function bookingFor(int $id): ?array
    {
        $st = NgvDb::pdo()->prepare("SELECT * FROM ngv_bookings WHERE application_id = ? AND status = 'booked' ORDER BY id DESC LIMIT 1");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Book (or move) a visit. One live booking per application; booking again
     * cancels the last. Capacity is checked inside the write, so two people
     * pressing at once for the last place cannot both get it.
     */
    public static function book(int $id, string $centre, string $date, string $time): array
    {
        $app = NgvMember::application($id);
        if (!$app) return ['ok' => false, 'error' => 'Application not found.'];
        if (!in_array((string) $app['status'], ['invited', 'booked'], true)) {
            return ['ok' => false, 'error' => 'Booking opens once your application has been reviewed and you have been invited.'];
        }
        $open = false;
        foreach (self::slots($centre) as $s) if ($s['date'] === $date && $s['time'] === $time) { $open = true; break; }
        if (!$open) return ['ok' => false, 'error' => 'That time is not available. Choose another.'];
        $cap = self::centres()[$centre]['capacity'];
        $pdo = NgvDb::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE ngv_bookings SET status = 'cancelled', updated_at = " . NgvDb::nowExpr() . " WHERE application_id = ? AND status = 'booked'")->execute([$id]);
            $st = $pdo->prepare("SELECT COUNT(*) FROM ngv_bookings WHERE centre = ? AND slot_date = ? AND slot_time = ? AND status = 'booked'");
            $st->execute([$centre, $date, $time]);
            if ((int) $st->fetchColumn() >= $cap) { $pdo->rollBack(); return ['ok' => false, 'error' => 'That time has just filled up. Choose another.']; }
            $pdo->prepare('INSERT INTO ngv_bookings (application_id, centre, slot_date, slot_time, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, '
                . NgvDb::nowExpr() . ', ' . NgvDb::nowExpr() . ')')->execute([$id, $centre, $date, $time, 'booked']);
            $pdo->prepare("UPDATE ngv_applications SET status = 'booked', centre = ? WHERE id = ?")->execute([$centre, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[ngv] book: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not book that. Try again.'];
        }
        self::mail((string) $app['email'], 'Your NextGen Vanguard registration visit', 'Your visit is booked',
            ['You are booked at <b>' . self::esc($centre) . '</b> on <b>' . self::esc(self::longDate($date)) . '</b> at <b>' . self::esc($time) . '</b>.',
             'Bring the MoU, printed and signed by you and your guarantor, your ID, and your guarantor in person. Your reference is <b>' . self::esc(self::ensureRef($id)) . '</b>.',
             'If you cannot come, change the time from your application page.'],
            ['text' => 'Open your application', 'url' => self::link(self::ensureRef($id))]);
        return ['ok' => true];
    }

    /** Cancel the live booking; the invitation stands. */
    public static function cancel(int $id): array
    {
        $b = self::bookingFor($id);
        if (!$b) return ['ok' => false, 'error' => 'There is no booking to cancel.'];
        NgvDb::pdo()->prepare("UPDATE ngv_bookings SET status = 'cancelled', updated_at = " . NgvDb::nowExpr() . ' WHERE id = ?')->execute([(int) $b['id']]);
        NgvDb::pdo()->prepare("UPDATE ngv_applications SET status = 'invited' WHERE id = ? AND status = 'booked'")->execute([$id]);
        return ['ok' => true];
    }

    /**
     * A booking nobody came to. After NO_SHOWS_MAX the invitation lapses: the
     * place is not held for ever for somebody who does not come, and a lapsed
     * application can be invited again.
     */
    public static function noShow(int $bookingId): array
    {
        $st = NgvDb::pdo()->prepare("SELECT * FROM ngv_bookings WHERE id = ? AND status = 'booked'");
        $st->execute([$bookingId]);
        $b = $st->fetch();
        if (!$b) return ['ok' => false, 'error' => 'No such booking.'];
        NgvDb::pdo()->prepare("UPDATE ngv_bookings SET status = 'no_show', updated_at = " . NgvDb::nowExpr() . ' WHERE id = ?')->execute([$bookingId]);
        $id = (int) $b['application_id'];
        NgvDb::pdo()->prepare('UPDATE ngv_applications SET no_shows = no_shows + 1 WHERE id = ?')->execute([$id]);
        $app = NgvMember::application($id);
        $lapsed = (int) ($app['no_shows'] ?? 0) >= self::NO_SHOWS_MAX;
        NgvDb::pdo()->prepare("UPDATE ngv_applications SET status = ? WHERE id = ? AND status = 'booked'")->execute([$lapsed ? 'lapsed' : 'invited', $id]);
        return ['ok' => true, 'lapsed' => $lapsed];
    }

    /**
     * The cron's part: a booking whose day has passed with nobody coming is a
     * no-show; an invitation nobody acted on for INVITE_LAPSE_DAYS lapses.
     */
    public static function sweep(?string $asOf = null): array
    {
        $today = $asOf ?: self::today();
        $out = ['noShows' => 0, 'lapsed' => 0];
        $st = NgvDb::pdo()->prepare("SELECT id FROM ngv_bookings WHERE status = 'booked' AND slot_date < ? LIMIT 200");
        $st->execute([$today]);
        foreach ($st->fetchAll() ?: [] as $b) if (!empty(self::noShow((int) $b['id'])['ok'])) $out['noShows']++;
        $cut = self::addDays($today, -self::INVITE_LAPSE_DAYS);
        $st = NgvDb::pdo()->prepare("UPDATE ngv_applications SET status = 'lapsed' WHERE status = 'invited' AND invited_at <> '' AND invited_at < ?");
        $st->execute([$cut . ' 00:00:00']);
        $out['lapsed'] = $st->rowCount();
        return $out;
    }

    /* ══ At the desk ════════════════════════════════════════════════════════ */

    public const SCAN_BYTES_MAX = 10485760;
    public const SCAN_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    /** The statuses somebody can be served at the desk in. */
    public const DESK_STATUSES = ['invited', 'accepted', 'booked', 'attended'];

    /**
     * Everything the desk needs to know about one application, and what still
     * stands between it and registration. `missing` is what the Complete
     * button names when it is off — a control that cannot act says why.
     */
    public static function deskState(array $app): array
    {
        $items = self::checklistFor($app);
        $ticks = (array) json_decode((string) ($app['checklist_json'] ?? ''), true);
        $missing = [];
        foreach ($items as $k => $it) if ($it['required'] && empty($ticks[$k]['ok'])) $missing[] = $it['label'];
        if (trim((string) ($app['g_present_at'] ?? '')) === '') $missing[] = 'Guarantor seen in person, with their ID';
        if (trim((string) ($app['mou_version'] ?? '')) === '') $missing[] = 'Which MoU version they signed';
        return [
            'items' => $items, 'ticks' => $ticks, 'missing' => $missing,
            'minor' => NgvMember::applicantIsMinor($app), 'ngg' => self::isNgg($app),
            'booking' => self::bookingFor((int) $app['id']),
            'servable' => in_array((string) $app['status'], self::DESK_STATUSES, true),
            'scan' => trim((string) ($app['mou_scan'] ?? '')) !== '',
        ];
    }

    /** They are here: the application is `attended`, and today's booking is kept. */
    public static function arrive(int $id): array
    {
        $app = NgvMember::application($id);
        if (!$app || !in_array((string) $app['status'], self::DESK_STATUSES, true)) return ['ok' => false, 'error' => 'This application cannot be served at the desk.'];
        NgvDb::pdo()->prepare("UPDATE ngv_applications SET status = 'attended' WHERE id = ?")->execute([$id]);
        NgvDb::pdo()->prepare("UPDATE ngv_bookings SET status = 'attended', updated_at = " . NgvDb::nowExpr() . " WHERE application_id = ? AND status = 'booked'")->execute([$id]);
        return ['ok' => true];
    }

    /** Tick (or untick) one item. The label is kept as it read when ticked. */
    public static function tick(int $id, array $keys, int $byUid): array
    {
        $app = NgvMember::application($id);
        if (!$app || !in_array((string) $app['status'], self::DESK_STATUSES, true)) return ['ok' => false, 'error' => 'This application cannot be served at the desk.'];
        $items = self::checklistFor($app);
        $ticks = (array) json_decode((string) ($app['checklist_json'] ?? ''), true);
        $now = self::now();
        foreach ($items as $k => $it) {
            $on = in_array($k, $keys, true);
            $was = !empty($ticks[$k]['ok']);
            if ($on && !$was) $ticks[$k] = ['ok' => true, 'label' => $it['label'], 'by' => $byUid, 'at' => $now];
            elseif (!$on && $was) $ticks[$k] = ['ok' => false, 'label' => $it['label'], 'by' => $byUid, 'at' => $now];
        }
        NgvDb::pdo()->prepare("UPDATE ngv_applications SET checklist_json = ? WHERE id = ?")->execute([json_encode($ticks, JSON_UNESCAPED_UNICODE), $id]);
        if ((string) $app['status'] !== 'attended') self::arrive($id);
        return ['ok' => true];
    }

    /**
     * The guarantor is here, in person: who they are (corrected if the form
     * had it wrong) and which ID was seen. A child's must be their parent or
     * guardian, as on the form.
     */
    public static function guarantorSeen(int $id, array $g, string $idSeen, int $byUid): array
    {
        $app = NgvMember::application($id);
        if (!$app || !in_array((string) $app['status'], self::DESK_STATUSES, true)) return ['ok' => false, 'error' => 'This application cannot be served at the desk.'];
        $idSeen = mb_substr(trim($idSeen), 0, 120);
        if ($idSeen === '') return ['ok' => false, 'error' => 'Say which ID of the guarantor’s you saw.'];
        $minor = NgvMember::applicantIsMinor($app);
        $c = self::cleanGuarantor($g + ['g_email' => (string) ($app['g_email'] ?? '')], $minor, (string) $app['email']);
        if (empty($c['ok'])) return $c;
        $set = $c['g'] + ['g_present_at' => self::now(), 'g_id_seen' => $idSeen];
        if ($minor) $set += ['guardian_name' => $c['g']['g_name'], 'guardian_phone' => $c['g']['g_phone'], 'guardian_email' => $c['g']['g_email']];
        $cols = implode(', ', array_map(static fn($k) => $k . ' = ?', array_keys($set)));
        NgvDb::pdo()->prepare("UPDATE ngv_applications SET {$cols} WHERE id = ?")->execute([...array_values($set), $id]);
        if ((string) $app['status'] !== 'attended') self::arrive($id);
        return ['ok' => true];
    }

    /** The MoU version printed on the paper they signed. */
    public static function setMouVersion(int $id, string $version): array
    {
        $version = mb_substr(trim($version), 0, 40);
        if ($version === '') return ['ok' => false, 'error' => 'Enter the version printed at the top of their MoU.'];
        $app = NgvMember::application($id);
        if (!$app || !in_array((string) $app['status'], self::DESK_STATUSES, true)) return ['ok' => false, 'error' => 'This application cannot be served at the desk.'];
        NgvDb::pdo()->prepare('UPDATE ngv_applications SET mou_version = ? WHERE id = ?')->execute([$version, $id]);
        return ['ok' => true];
    }

    /**
     * Keep a scan or photo of the signed MoU. Stored under data/ — closed to
     * the web — because it carries a date of birth and two people's names and
     * signatures; it is only ever served through the desk, to staff.
     */
    public static function attachScan(int $id, array $f): array
    {
        $app = NgvMember::application($id);
        if (!$app) return ['ok' => false, 'error' => 'Application not found.'];
        if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'No file arrived. It may be too large for the server.'];
        if ((int) ($f['size'] ?? 0) > self::SCAN_BYTES_MAX) return ['ok' => false, 'error' => 'That file is over 10MB.'];
        $tmp = (string) ($f['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') return ['ok' => false, 'error' => 'That file was not an upload.'];
        $mime = class_exists('Storage') ? Storage::mime($tmp) : (string) (mime_content_type($tmp) ?: '');
        if (!isset(self::SCAN_TYPES[$mime])) return ['ok' => false, 'error' => 'Attach a photo (JPEG or PNG) or a PDF of the signed MoU.'];
        $dir = AV_ROOT . '/data/ngv-mou';
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        $name = preg_replace('/[^A-Z0-9-]/', '', self::ensureRef($id)) . '-' . bin2hex(random_bytes(6)) . '.' . self::SCAN_TYPES[$mime];
        $ok = PHP_SAPI === 'cli' ? @copy($tmp, $dir . '/' . $name) : @move_uploaded_file($tmp, $dir . '/' . $name);
        if (!$ok) return ['ok' => false, 'error' => 'The file could not be saved.'];
        NgvDb::pdo()->prepare('UPDATE ngv_applications SET mou_scan = ? WHERE id = ?')->execute([$name, $id]);
        return ['ok' => true, 'file' => $name];
    }

    /** The stored scan's path on disk, or null. Never a URL. */
    public static function scanPath(array $app): ?string
    {
        $n = basename((string) ($app['mou_scan'] ?? ''));
        if ($n === '' || !preg_match('/^NGV-[A-Z0-9]+-[a-f0-9]{12}\.(jpg|png|pdf)$/', $n)) return null;
        $p = AV_ROOT . '/data/ngv-mou/' . $n;
        return is_file($p) ? $p : null;
    }

    /**
     * Complete registration. Refused while anything required is missing, and
     * the refusal names it. Then, in this order:
     *   1. a child's parent or guardian has consented — in person, here;
     *   2. the account, the participant and the NGV card (enrollApplication);
     *   3. the programme fee is frozen on them, as the MoU they signed states;
     *   4. the membership fee is charged and recorded as paid, or waived.
     *
     * @param array{how?:string, amount?:mixed, method?:string, reference?:string, reason?:string} $fee
     */
    public static function complete(int $id, int $byUid, array $fee): array
    {
        $app = NgvMember::application($id);
        if (!$app || !in_array((string) $app['status'], self::DESK_STATUSES, true)) return ['ok' => false, 'error' => 'This application cannot be completed at the desk.'];
        $state = self::deskState($app);
        if ($state['missing']) return ['ok' => false, 'error' => 'Still needed: ' . implode('; ', $state['missing']) . '.', 'missing' => $state['missing']];
        $how = (string) ($fee['how'] ?? '');
        $fees = NgvLedger::enabled();
        if ($fees && !in_array($how, ['paid', 'waived'], true)) return ['ok' => false, 'error' => 'Record the membership fee as paid, or waived.'];
        if ($fees && $how === 'paid' && NgvLedger::money($fee['amount'] ?? 0) <= 0) return ['ok' => false, 'error' => 'Enter the amount of the membership fee received.'];
        if ($fees && $how === 'waived' && trim((string) ($fee['reason'] ?? '')) === '') return ['ok' => false, 'error' => 'A waiver needs a reason.'];

        if ($state['minor'] && !NgvMember::guardianConfirmed($app)) {
            $r = NgvMember::recordGuardianConsent($id, 'in_person', $byUid);
            if (empty($r['ok'])) return $r;
        }
        $en = NgvMember::enrollApplication($id, $byUid);
        if (empty($en['ok'])) return $en;
        $mid = (int) $en['member_id'];
        $p = NgvMember::participant($mid) ?? [];
        if (class_exists('NgvProgrammeFee') && NgvProgrammeFee::snapshot($p) === null) {
            $start = self::today();
            NgvMember::setAdmin($mid, ['start_date' => $start]);
            NgvProgrammeFee::freeze($mid, $start, (string) $app['mou_version']);
        }
        $money = null;
        if ($fees) {
            NgvLedger::accrueParticipant(NgvMember::participant($mid) ?? []);
            $due = (int) (NgvLedger::balance($mid)['due']['membership'] ?? 0);
            $money = $how === 'paid'
                ? NgvLedger::payment($mid, 'membership', $fee['amount'], ['method' => (string) ($fee['method'] ?? 'cash'),
                      'reference' => (string) ($fee['reference'] ?? ''), 'note' => 'Paid on registration day'], $byUid)
                : ($due > 0 ? NgvLedger::waive($mid, 'membership', $due, (string) $fee['reason'], $byUid) : ['ok' => true]);
        }
        NgvDb::pdo()->prepare('UPDATE ngv_applications SET registered_at = ?, registered_by = ? WHERE id = ?')->execute([self::now(), max(0, $byUid), $id]);
        if (self::isNgg($app) && class_exists('NgvIntake')) NgvIntake::registered((string) $app['email'], $mid);
        return ['ok' => true, 'member_id' => $mid, 'account_created' => !empty($en['account_created']),
                'membership' => $money === null ? 'ledger_off' : (!empty($money['ok']) ? $how : 'failed'),
                'membership_error' => $money !== null && empty($money['ok']) ? (string) ($money['error'] ?? '') : ''];
    }

    /* ══ The MoU ════════════════════════════════════════════════════════════ */

    /** The MoU for one application, with its details in. Plain text; the page escapes it. */
    public static function mou(array $app): array
    {
        $m = (array) (Ngv::get()['mou'] ?? []);
        $t = NgvProgrammeFee::terms();
        $amt = NgvLedger::amounts();
        $money = static fn($n) => '₦' . number_format((int) $n);
        $vars = [
            '{name}' => (string) $app['name'], '{ref}' => (string) ($app['ref'] ?? ''), '{dob}' => (string) ($app['dob'] ?? ''),
            '{track}' => (string) ($app['track'] ?: 'to be agreed'), '{centre}' => (string) ($app['centre'] ?: 'a CACENTRE'),
            '{fee}' => $t['rate'] !== null ? $money($t['rate']) : '(to be set)', '{basis}' => NgvProgrammeFee::BASIS_LABEL[$t['basis']],
            '{training_months}' => (string) $t['trainingMonths'], '{internship_months}' => (string) $t['internshipMonths'],
            '{membership}' => $money($amt['membership']['amount']) . ' a ' . $amt['membership']['cadence'],
            '{commitment}' => $money($amt['commitment']['amount']) . ' a ' . $amt['commitment']['cadence'],
            '{guarantor}' => (string) ($app['g_name'] ?: '(your guarantor)'), '{relationship}' => self::relationshipLabel($app) ?: 'relationship',
            '{date}' => self::longDate(self::today()),
        ];
        return ['version' => (string) ($m['version'] ?? ''), 'title' => (string) ($m['title'] ?? 'Memorandum of Understanding'),
                'body' => strtr((string) ($m['body'] ?? ''), $vars)];
    }

    /* ══ Helpers ════════════════════════════════════════════════════════════ */

    private static function mail(string $to, string $subject, string $heading, array $rows, ?array $cta): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !class_exists('Mailer')) return;
        try { @Mailer::send($to, $subject, Mailer::shell($heading, $rows, $cta, $subject, ['eyebrow' => 'NextGen Vanguard'])); }
        catch (Throwable $e) { error_log('[ngv] registration mail: ' . $e->getMessage()); }
    }
    private static function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
    private static function site(): string { return defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : ''; }
    private static function today(): string { return function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d'); }
    private static function now(): string { return self::today() . ' ' . date('H:i:s'); }
    private static function addDays(string $d, int $n): string { return gmdate('Y-m-d', (int) strtotime($d . ' 00:00:00 UTC ' . ($n >= 0 ? '+' : '') . $n . ' days')); }
    public static function longDate(string $d): string { $t = strtotime($d . ' 12:00:00 UTC'); return $t === false ? $d : gmdate('l j F Y', $t); }
}
