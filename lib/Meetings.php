<?php
/**
 * lib/Meetings.php — the standardized Afrovanguard meeting system.
 *
 * One consistent way to schedule a meeting anywhere in the platform (the
 * portal workspace and mentorship both use this), so every meeting behaves the
 * same:
 *
 *   • A Google Meet link created via a real Google Calendar event, so the
 *     meeting syncs to everyone's calendar and an invite is sent. Google Meet
 *     is the only provider (no fallback).
 *   • A frequency / cadence (one-off, daily, weekdays, weekly, fortnightly,
 *     monthly) carried as a real Google recurrence rule when Google is on.
 *   • Otter-style structured minutes AFTER the meeting: paste (or auto-pull
 *     from the Google Meet transcript on Drive) the raw transcript and the
 *     Afrovanguard bot turns it into a summary, key points, decisions and
 *     assigned action items.
 *
 * Storage is portable (SQLite default; MySQL/Postgres via Database::translateDDL).
 */
declare(strict_types=1);

final class Meetings
{
    /** Cadence options → [human label, RRULE tail or '' for one-off]. */
    const FREQ = [
        'once'     => ['One-off',        ''],
        'daily'    => ['Every day',      'FREQ=DAILY'],
        'weekdays' => ['Every weekday',  'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR'],
        'weekly'   => ['Every week',     'FREQ=WEEKLY'],
        'biweekly' => ['Every 2 weeks',  'FREQ=WEEKLY;INTERVAL=2'],
        'monthly'  => ['Every month',    'FREQ=MONTHLY'],
    ];

    private static bool $ready = false;

    /** ── Schema (idempotent) ─────────────────────────────────────────── */
    public static function ensure(): void
    {
        if (self::$ready) return;
        $db  = Database::pdo();
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ddl = "
        CREATE TABLE IF NOT EXISTS meetings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            creator_id INTEGER NOT NULL,
            title VARCHAR(200) NOT NULL DEFAULT '',
            agenda TEXT NOT NULL DEFAULT '',
            scheduled_at VARCHAR(32) NOT NULL DEFAULT '',
            duration_min INTEGER NOT NULL DEFAULT 30,
            frequency VARCHAR(16) NOT NULL DEFAULT 'once',
            context VARCHAR(16) NOT NULL DEFAULT 'workspace',
            context_id INTEGER NOT NULL DEFAULT 0,
            provider VARCHAR(12) NOT NULL DEFAULT '',
            meet_url VARCHAR(500) NOT NULL DEFAULT '',
            meet_code VARCHAR(60) NOT NULL DEFAULT '',
            google_event_id VARCHAR(128) NOT NULL DEFAULT '',
            auto_record INTEGER NOT NULL DEFAULT 0,
            bot_state VARCHAR(16) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'scheduled',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_meetings_when ON meetings(scheduled_at);
        CREATE INDEX IF NOT EXISTS idx_meetings_creator ON meetings(creator_id, status);
        CREATE TABLE IF NOT EXISTS meeting_attendees (
            meeting_id INTEGER NOT NULL,
            email VARCHAR(200) NOT NULL DEFAULT '',
            user_id INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_mtg_att ON meeting_attendees(meeting_id);
        CREATE TABLE IF NOT EXISTS meeting_transcripts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id INTEGER NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'paste',
            raw_text TEXT NOT NULL DEFAULT '',
            summary TEXT NOT NULL DEFAULT '',
            highlights TEXT NOT NULL DEFAULT '',
            decisions TEXT NOT NULL DEFAULT '',
            action_items TEXT NOT NULL DEFAULT '',
            structured INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_mtg_tr ON meeting_transcripts(meeting_id);";
        Database::execSchema($db, $ddl);
        // Idempotent column adds for databases created before these fields existed.
        self::addCol('meetings', 'meet_code', "VARCHAR(60) NOT NULL DEFAULT ''");
        self::addCol('meetings', 'auto_record', 'INTEGER NOT NULL DEFAULT 0');
        self::addCol('meetings', 'bot_state', "VARCHAR(16) NOT NULL DEFAULT ''");
        self::addCol('meetings', 'bot_provider', "VARCHAR(16) NOT NULL DEFAULT ''");
        self::addCol('meetings', 'bot_ref', "VARCHAR(128) NOT NULL DEFAULT ''");
        /* The Meet space Google made for it, and what Google captures there on
           its own (all | transcript | off | '' = not switched on). */
        self::addCol('meetings', 'meet_space', "VARCHAR(80) NOT NULL DEFAULT ''");
        self::addCol('meetings', 'meet_capture', "VARCHAR(12) NOT NULL DEFAULT ''");
        /* Why Google would not switch recording on, in words — shown on the meeting, not only in a log. */
        self::addCol('meetings', 'capture_note', "VARCHAR(500) NOT NULL DEFAULT ''");
        /* How it repeats, as lib/Recurrence.php's rule (JSON); '' means the
           one-word `frequency` still says it (meetings made before rules). */
        self::addCol('meetings', 'recur', "VARCHAR(2000) NOT NULL DEFAULT ''");
        /* Every member and staff member sees every meeting; the organiser
           decides whether its MINUTES are for everyone (0) or only for the
           people in it (1). See canSee() / canReadMinutes(). */
        self::addCol('meetings', 'minutes_private', 'INTEGER NOT NULL DEFAULT 0');
        /* Who organised it when that is not an account here: a CACENTRE staff
           member scheduling from the staff portal (integrations/cacentre-meetings.php).
           creator_id is their account here when they have one, else 0. */
        self::addCol('meetings', 'organiser_name', "VARCHAR(160) NOT NULL DEFAULT ''");
        self::addCol('meetings', 'organiser_email', "VARCHAR(200) NOT NULL DEFAULT ''");
        self::addCol('meetings', 'origin', "VARCHAR(16) NOT NULL DEFAULT ''");
        self::addCol('meetings', 'origin_ref', "VARCHAR(64) NOT NULL DEFAULT ''");
        self::$ready = true;
    }

    private static function addCol(string $table, string $col, string $decl): void
    {
        try {
            if (!Database::columnExists($table, $col)) {
                Database::pdo()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $col . ' ' . $decl);
            }
        } catch (Throwable $e) { /* already exists / race — fine */ }
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    public static function freqLabel(string $f): string { return self::FREQ[$f][0] ?? self::FREQ['once'][0]; }
    private static function freqKey(string $f): string { return isset(self::FREQ[$f]) || $f === 'custom' ? $f : 'once'; }

    /* ── How a meeting repeats (lib/Recurrence.php) ────────────────────────
       Everything that asks "when does this happen" asks here: the list, the
       clock, the minutes, the invite, the calendar file and Google. */

    /** The series start, on the meeting clock (the timezone Google expands in). */
    public static function seriesStart(array $m): DateTimeImmutable
    {
        return Recurrence::local((string) ($m['scheduled_at'] ?? '') ?: gmdate('Y-m-d H:i:s'));
    }

    /** The meeting's rule; null for a one-off. Old meetings read their one word. */
    public static function rule(array $m): ?array
    {
        $start = self::seriesStart($m);
        $j = (string) ($m['recur'] ?? '');
        return $j !== '' ? Recurrence::normalise($j, $start) : Recurrence::fromPreset((string) ($m['frequency'] ?? 'once'), $start);
    }

    /** "Every 2 weeks on Mon and Thu, until 30 Jun 2027" — or "One-off". */
    public static function cadence(array $m): string { return Recurrence::label(self::rule($m), self::seriesStart($m)); }

    /** Google's recurrence lines for the meeting (empty for a one-off). */
    public static function recurrenceLines(array $m): array { return Recurrence::lines(self::rule($m), self::seriesStart($m)); }

    /** UTC starts of the next $limit occurrences not over by $from (plus $grace seconds). */
    public static function upcomingStarts(array $m, int $from, int $limit = 1, int $grace = 0): array
    {
        $dur = max(1, (int) ($m['duration_min'] ?? 30));
        return array_map(static fn(DateTimeImmutable $d) => $d->getTimestamp(),
            Recurrence::upcoming(self::rule($m), self::seriesStart($m), $from - $grace, $limit, $dur));
    }

    /** UTC starts between two moments, oldest first. */
    public static function startsBetween(array $m, int $from, int $to): array
    {
        $rule = self::rule($m); $start = self::seriesStart($m);
        if ($rule === null) { $t = $start->getTimestamp(); return $t >= $from && $t <= $to ? [$t] : []; }
        $out = [];
        foreach (Recurrence::each($rule, $start) as $d) {
            $t = $d->getTimestamp();
            if ($t > $to) break;
            if ($t >= $from) $out[] = $t;
            if (count($out) >= 1500) break;
        }
        return $out;
    }

    /**
     * The rule a form asked for, against a start: `repeat` (a rule, or
     * {freq:'once'}), else the one-word `frequency`. [rule|null, preset word].
     */
    private static function askedRule(array $in, DateTimeImmutable $start): array
    {
        $rule = array_key_exists('repeat', $in)
            ? Recurrence::normalise(is_string($in['repeat']) ? json_decode($in['repeat'], true) : $in['repeat'], $start)
            : Recurrence::fromPreset(self::freqKey((string) ($in['frequency'] ?? 'once')), $start);
        return [$rule, Recurrence::presetOf($rule, $start)];
    }

    /**
     * What a rule would do, before anybody saves it: its words, and the next
     * dates on the viewer's clock. For the form's preview.
     */
    public static function previewRepeat(int $uid, string $when, $repeat, int $limit = 6): array
    {
        $tz = function_exists('av_user_tz') ? av_user_tz($uid) : 'Africa/Lagos';
        $ts = self::localToTs(trim($when), $tz);
        if ($ts <= 0) return ['ok' => false, 'error' => 'Pick a date and time first.'];
        $start = Recurrence::local(gmdate('Y-m-d H:i:s', $ts));
        $rule = Recurrence::normalise(is_string($repeat) ? json_decode($repeat, true) : $repeat, $start);
        $first = Recurrence::first($rule, $start);
        if ($first === null) return ['ok' => true, 'label' => Recurrence::label($rule, $start), 'dates' => [], 'never' => true];
        $view = new DateTimeZone($tz);
        $dates = array_map(static fn(DateTimeImmutable $d) => $d->setTimezone($view)->format('D j M Y, H:i'),
            Recurrence::upcoming($rule, $first, $first->getTimestamp(), $limit));
        return ['ok' => true, 'label' => Recurrence::label($rule, $first), 'dates' => $dates, 'never' => false,
                'moved' => $first->getTimestamp() !== $start->getTimestamp() ? $first->setTimezone($view)->format('D j M Y, H:i') : ''];
    }

    /**
     * Provision a Google Meet link by creating a real Google Calendar event —
     * this is what syncs the meeting to everyone's calendar and sends the
     * invite (sendUpdates=all) to the organiser + attendees, with the cadence
     * as an RRULE. Google Meet is the ONLY provider. Returns
     * ['url','provider','google_event_id']; url is '' when Google Workspace
     * Calendar isn't connected (caller surfaces a "connect Google" warning).
     */
    public static function provisionLink(string $title, string $startIso, int $durationMin, array $emails, string $agenda, array|string $recurrence): array
    {
        /* The rule's lines (Recurrence::lines), or an old one-word cadence. */
        if (is_string($recurrence)) {
            $start = Recurrence::local(gmdate('Y-m-d H:i:s', strtotime($startIso) ?: time()));
            $recurrence = Recurrence::lines(Recurrence::fromPreset(self::freqKey($recurrence), $start), $start);
        }
        if (class_exists('GoogleWorkspace') && GoogleWorkspace::calendarWriteEnabled()) {
            try {
                $ev = GoogleWorkspace::createMeetEvent(
                    $title, $startIso, $durationMin, $emails,
                    $agenda !== '' ? $agenda : 'Afrovanguard meeting.',
                    null, true,
                    $recurrence
                );
                if ($ev && !empty($ev['meet_url'])) {
                    return ['url' => (string) $ev['meet_url'], 'provider' => 'google', 'google_event_id' => (string) ($ev['id'] ?? '')];
                }
            } catch (Throwable $e) { error_log('[meetings] google: ' . $e->getMessage()); }
        }
        return ['url' => '', 'provider' => '', 'google_event_id' => '', 'error' => class_exists('GoogleWorkspace') ? GoogleWorkspace::lastError() : ''];
    }

    /**
     * Switch on Google's own capture for a meeting's Meet (GoogleWorkspace::
     * configureMeetSpace) and remember the space. A meeting whose capture is
     * on needs no notetaker: its bot state is 'native'. Best-effort; a failure
     * leaves the meeting as it was, and the sweep tries again.
     */
    /**
     * Will a meeting scheduled now transcribe itself? ['ready', 'mode' (all |
     * transcript | off | ''), 'why']. When the last meeting made could not
     * have capture switched on, that reason is given: the next will likely
     * meet the same refusal.
     */
    public static function captureReadiness(): array
    {
        if (!class_exists('GoogleWorkspace') || !GoogleWorkspace::meetEnabled()) {
            return ['ready' => false, 'mode' => '', 'why' => class_exists('GoogleWorkspace') ? GoogleWorkspace::explain() : 'Google Workspace is not set up on this site.'];
        }
        $mode = GoogleWorkspace::artifactMode();
        if ($mode === 'off') return ['ready' => false, 'mode' => 'off', 'why' => 'Automatic recording and transcripts are switched off: Studio → Rules & AI → Setup → Google Meet → capture.'];
        try {
            self::ensure();
            $r = Database::pdo()->query("SELECT meet_capture, capture_note FROM meetings WHERE meet_url <> '' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($r && (string) $r['meet_capture'] === '' && (string) $r['capture_note'] !== '') {
                return ['ready' => false, 'mode' => $mode, 'why' => 'Google refused to switch it on for the last meeting: ' . (string) $r['capture_note']];
            }
        } catch (Throwable $e) {}
        return ['ready' => true, 'mode' => $mode, 'why' => ''];
    }

    public static function configureCapture(int $id, string $code): bool
    {
        if (!class_exists('GoogleWorkspace') || !GoogleWorkspace::meetEnabled()) return false;
        try {
            $r = GoogleWorkspace::configureMeetSpace($code);
            $db = Database::pdo();
            if (!$r) {
                $db->prepare('UPDATE meetings SET capture_note = ? WHERE id = ?')->execute([mb_substr(GoogleWorkspace::explain(), 0, 500), $id]);
                return false;
            }
            $db->prepare("UPDATE meetings SET meet_space = ?, meet_capture = ?, capture_note = '' WHERE id = ?")->execute([$r['space'], $r['mode'], $id]);
            if ($r['mode'] !== 'off') $db->prepare("UPDATE meetings SET bot_state = 'native', bot_provider = 'google' WHERE id = ? AND bot_state IN ('', 'pending', 'unconfigured', 'error')")->execute([$id]);
            return true;
        } catch (Throwable $e) { error_log('[meetings] capture: ' . $e->getMessage()); return false; }
    }

    /**
     * The cron's Meet upkeep: a Meet link Calendar had not made yet is read
     * again; a meeting whose capture could not be switched on is tried again.
     * Upcoming meetings only, a handful a tick.
     */
    public static function meetUpkeep(): array
    {
        self::ensure();
        $out = ['links' => 0, 'captured' => 0];
        if (!class_exists('GoogleWorkspace') || !GoogleWorkspace::meetEnabled()) return $out;
        $db = Database::pdo();
        $soon = gmdate('Y-m-d H:i:s', time() - 6 * 3600);
        $st = $db->prepare("SELECT id, google_event_id FROM meetings WHERE status = 'scheduled' AND meet_url = '' AND google_event_id <> '' AND scheduled_at >= ? ORDER BY scheduled_at LIMIT 5");
        $st->execute([$soon]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $url = GoogleWorkspace::eventMeetUrl((string) $m['google_event_id']);
            if ($url === '') continue;
            $code = GoogleWorkspace::meetCodeFromUrl($url);
            $db->prepare("UPDATE meetings SET meet_url = ?, meet_code = ?, provider = 'google' WHERE id = ?")->execute([$url, $code, (int) $m['id']]);
            $out['links']++;
        }
        if (GoogleWorkspace::artifactMode() !== 'off') {
            $st = $db->prepare("SELECT id, meet_code FROM meetings WHERE status = 'scheduled' AND meet_code <> '' AND meet_capture = '' AND (scheduled_at >= ? OR frequency <> 'once') ORDER BY scheduled_at LIMIT 5");
            $st->execute([$soon]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) if (self::configureCapture((int) $m['id'], (string) $m['meet_code'])) $out['captured']++;
        }
        return $out;
    }

    /** ── Schedule a meeting (workspace or mentorship) ─────────────────── */
    /**
     * @param array|null $as  who organised it when that is not (only) $uid: a
     *   CACENTRE staff member — ['name','email','origin'=>'cacentre','ref'=>staff id,'tz'].
     *   $uid is then their account here, or 0 when they have none.
     */
    public static function schedule(int $uid, array $in, ?array $as = null): array
    {
        self::ensure();
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 200);
        if ($title === '') return ['ok' => false, 'error' => 'Give the meeting a title.'];
        $when = trim((string) ($in['when'] ?? ''));
        if ($when === '') return ['ok' => false, 'error' => 'Pick a date and time.'];
        // The datetime-local value is the user's wall-clock in their timezone.
        $tz   = $as !== null && !empty($as['tz']) ? (string) $as['tz'] : (function_exists('av_user_tz') && $uid > 0 ? av_user_tz($uid) : 'Africa/Lagos');
        $ts   = self::localToTs($when, $tz);
        $whenUtc = gmdate('Y-m-d H:i:s', $ts);
        $dur  = max(5, min(720, (int) ($in['duration'] ?? 30)));
        /* The rule, and the first time it really happens: a series asked for
           "Mondays and Thursdays" from a Tuesday starts on the Thursday, here
           and at Google alike. */
        $start = Recurrence::local($whenUtc);
        [$rule, $freq] = self::askedRule($in, $start);
        $first = Recurrence::first($rule, $start);
        if ($first === null) return ['ok' => false, 'error' => 'That repeat never happens: its end comes before its first date.'];
        $ts = $first->getTimestamp(); $whenUtc = gmdate('Y-m-d H:i:s', $ts);
        $recur = $rule !== null && $freq === 'custom' ? (string) json_encode($rule) : '';
        $agenda = mb_substr(trim((string) ($in['agenda'] ?? '')), 0, 2000);
        $context = (string) ($in['context'] ?? 'workspace');
        if (!in_array($context, ['workspace', 'mentorship', 'team'], true)) $context = 'workspace';
        // Invite the organiser too, so everyone involved gets the calendar invite.
        $emails = self::cleanEmails($in['attendees'] ?? []);
        $me = $as !== null ? strtolower(trim((string) ($as['email'] ?? ''))) : self::userEmail($uid);
        if ($me !== '' && filter_var($me, FILTER_VALIDATE_EMAIL)) { array_unshift($emails, $me); $emails = array_values(array_unique($emails)); }
        $autoRec = !empty($in['auto_record']) ? 1 : 0;
        $private = !empty($in['minutes_private']) ? 1 : 0;

        $db = Database::pdo();
        $db->prepare('INSERT INTO meetings (creator_id, title, agenda, scheduled_at, duration_min, frequency, recur, context, context_id, auto_record, minutes_private, organiser_name, organiser_email, origin, origin_ref, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$uid, $title, $agenda, $whenUtc, $dur, $freq, $recur, $context, (int) ($in['context_id'] ?? 0), $autoRec, $private,
                      mb_substr(trim((string) ($as['name'] ?? '')), 0, 160), $as !== null ? $me : '',
                      $as !== null ? mb_substr((string) ($as['origin'] ?? ''), 0, 16) : '', $as !== null ? mb_substr((string) ($as['ref'] ?? ''), 0, 64) : '',
                      'scheduled', self::now()]);
        $id = (int) $db->lastInsertId();
        $row = ['scheduled_at' => $whenUtc, 'frequency' => $freq, 'recur' => $recur, 'duration_min' => $dur];

        // Google Meet is the only provider: create the Calendar event (which
        // syncs calendars + sends the invite) and take its Meet link.
        $link = self::provisionLink($title, gmdate('c', $ts), $dur, $emails, $agenda, self::recurrenceLines($row));
        $code = class_exists('GoogleWorkspace') ? GoogleWorkspace::meetCodeFromUrl($link['url']) : '';
        $db->prepare('UPDATE meetings SET meet_url = ?, provider = ?, google_event_id = ?, meet_code = ? WHERE id = ?')
           ->execute([$link['url'], $link['provider'], $link['google_event_id'], $code, $id]);
        /* Google records, transcribes and takes notes on its own from here. */
        if ($code !== '') self::configureCapture($id, $code);

        $ins = $db->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?,?)');
        foreach ($emails as $e) $ins->execute([$id, $e]);

        // ── Tell the humans. ─────────────────────────────────────────────
        //
        // Until now the ONLY notification was Google Calendar's own invite, sent
        // as a side effect of creating the event. So on any deployment where
        // Google Workspace Calendar was not connected — which is the default —
        // a meeting was scheduled and NOBODY WAS TOLD. The row existed, the
        // portal listed it for people who thought to look, and that was the
        // whole of it. That is the bug behind "the issues with meetings".
        //
        // The invite now goes out from us as well, always. When Google did
        // create the event this is a second touch rather than the only one,
        // which is the correct trade: a duplicate invite is a minor annoyance,
        // a missed meeting is not.
        self::sendInvites($id, $title, $ts, $dur, self::cadence($row), $agenda, $emails, $link['url'], $uid, (bool) $autoRec && self::botAllowed(), false, (string) ($as['name'] ?? ''));

        // The AI notetaker (optional). Google transcribes the space natively, so
        // it can be told about a meeting well ahead. Attendee has no dependable
        // join-later field across versions, so a distant meeting is left PENDING
        // and the cron sweep sends the bot when it is actually about to start.
        // With no notetaker at all, nothing here makes a network call.
        if ($autoRec && self::botAllowed() && $link['url'] !== '') {
            $provider = self::botProvider();
            if ($provider === 'attendee' && $ts > time() + 3600) {
                $db->prepare("UPDATE meetings SET bot_state = 'pending', bot_provider = ? WHERE id = ?")->execute([$provider, $id]);
            } else {
                self::requestBot($id, $link['url'], $provider, self::botJoinAtIso($whenUtc));
            }
        } elseif ($autoRec) {
            // Asked for, but not possible yet (no Meet link, or the notetaker is
            // switched off). Record that rather than leaving the state blank.
            $db->prepare('UPDATE meetings SET bot_state = ? WHERE id = ?')
               ->execute([self::botAllowed() ? 'pending' : 'unconfigured', $id]);
        }

        if (class_exists('Events')) { try { Events::emit('meeting.scheduled', ['id' => $id, 'by' => $uid, 'at' => $whenUtc]); } catch (Throwable $e) {} }
        $out = ['ok' => true, 'id' => $id, 'meeting' => $uid > 0 ? self::get($uid, $id) : self::view($id, null)];
        if ($link['url'] === '') $out['warning'] = 'Meeting saved and everyone was emailed, but Google did not make a Meet link. '
            . (class_exists('GoogleWorkspace') ? GoogleWorkspace::explain($link['error'] ?? '') : '') . ' Edit the meeting once that is fixed and the link is added.';
        return $out;
    }

    /**
     * ── Start a call now ─────────────────────────────────────────────────
     *
     * An instant Meet room: no Calendar event, so nothing to wait for and no
     * calendar to clutter. It is still a meeting here — a row starting now, its
     * space remembered — so the cron reads its attendance, transcript and notes
     * exactly as it does a scheduled one. Anybody named is sent the link.
     */
    public static function startNow(int $uid, array $in): array
    {
        self::ensure();
        if (!class_exists('GoogleWorkspace') || !GoogleWorkspace::meetEnabled()) {
            return ['ok' => false, 'error' => 'A Meet room cannot be opened yet. ' . (class_exists('GoogleWorkspace') ? GoogleWorkspace::explain() : 'Google Workspace is not set up on this site.')];
        }
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 200);
        if ($title === '') $title = 'Quick call';
        $dur = max(5, min(600, (int) ($in['duration'] ?? 60)));
        $agenda = mb_substr(trim((string) ($in['agenda'] ?? '')), 0, 2000);
        $emails = self::cleanEmails($in['attendees'] ?? []);
        $me = self::userEmail($uid);
        if ($me !== '' && filter_var($me, FILTER_VALIDATE_EMAIL)) { array_unshift($emails, $me); $emails = array_values(array_unique($emails)); }

        $space = GoogleWorkspace::createMeetSpace();
        if (!$space) {
            $why = GoogleWorkspace::lastError();
            return ['ok' => false, 'error' => 'Google did not open a Meet room. ' . GoogleWorkspace::explain($why)];
        }

        $now = time();
        $db = Database::pdo();
        $db->prepare('INSERT INTO meetings (creator_id, title, agenda, scheduled_at, duration_min, frequency, context, context_id, auto_record, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$uid, $title, $agenda, gmdate('Y-m-d H:i:s', $now), $dur, 'once', 'workspace', 0, 0, 'scheduled', self::now()]);
        $id = (int) $db->lastInsertId();
        $db->prepare("UPDATE meetings SET meet_url = ?, provider = 'google', meet_code = ?, meet_space = ?, meet_capture = ? WHERE id = ?")
           ->execute([$space['uri'], $space['code'], $space['space'], $space['mode'], $id]);
        if ($space['mode'] !== 'off') $db->prepare("UPDATE meetings SET bot_state = 'native', bot_provider = 'google' WHERE id = ?")->execute([$id]);

        $ins = $db->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?,?)');
        foreach ($emails as $e) $ins->execute([$id, $e]);
        // The link goes to whoever was named — the starter has it on screen already.
        $others = array_values(array_filter($emails, static fn($e) => strcasecmp($e, $me) !== 0));
        if ($others) self::sendInvites($id, $title, $now, $dur, 'once', $agenda, $emails, $space['uri'], $uid, false);

        if (class_exists('Events')) { try { Events::emit('meeting.scheduled', ['id' => $id, 'by' => $uid, 'at' => gmdate('Y-m-d H:i:s', $now), 'instant' => true]); } catch (Throwable $e) {} }
        return ['ok' => true, 'id' => $id, 'url' => $space['uri'], 'meeting' => self::get($uid, $id)];
    }

    /** Meetings the user created or is invited to, upcoming first then recent. */
    public static function listFor(int $uid, int $limit = 120): array
    {
        self::ensure();
        $email = self::userEmail($uid);
        $db = Database::pdo();
        // The limit is interpolated, not bound. It is clamped to an int on the
        // line above, so this is not an injection surface — and it has to be:
        // the connection runs with ATTR_EMULATE_PREPARES = false, where a bound
        // parameter in LIMIT is sent as a STRING and MySQL/Postgres reject
        // `LIMIT '60'` outright. On SQLite it happens to work, which is why the
        // whole meetings list would have died the day this moved to MySQL and
        // not one moment sooner.
        $lim = max(1, min(200, $limit));
        /* Every meeting, for a member or staff member: meetings are open to
           everybody in the organisation (only their minutes can be kept to
           the people in them). An Academy learner sees the ones they are in. */
        if (self::seesAll($uid)) {
            $st = $db->prepare('SELECT m.* FROM meetings m WHERE m.status <> \'cancelled\' ORDER BY m.scheduled_at DESC LIMIT ' . $lim);
            $st->execute();
        } else {
            $st = $db->prepare(
                'SELECT DISTINCT m.* FROM meetings m
                 LEFT JOIN meeting_attendees a ON a.meeting_id = m.id
                 WHERE m.status <> \'cancelled\' AND (m.creator_id = ? OR a.email = ?)
                 ORDER BY m.scheduled_at DESC LIMIT ' . $lim
            );
            $st->execute([$uid, $email]);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return self::ordered(self::decorate(array_map(fn($r) => self::shape($r, $uid), $rows), $email, $uid));
    }

    /**
     * Every meeting, for somebody who is not an account here — a CACENTRE
     * staff member, by their email (integrations/cacentre-meetings.php).
     */
    public static function listAll(string $viewerEmail, int $viewerId = 0, int $limit = 120): array
    {
        self::ensure();
        $lim = max(1, min(200, $limit));
        $rows = Database::pdo()->query('SELECT m.* FROM meetings m WHERE m.status <> \'cancelled\' ORDER BY m.scheduled_at DESC LIMIT ' . $lim)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return self::ordered(self::decorate(array_map(fn($r) => self::shape($r, $viewerId), $rows), strtolower($viewerEmail), $viewerId));
    }

    /** Upcoming first, soonest at the top; then the past, most recent first. */
    private static function ordered(array $out): array
    {

        // ── Upcoming first, soonest at the top; then the past, most recent
        // first. The SQL cannot express this in one ORDER BY without dialect-
        // specific tricks, and the docblock has always claimed it: a plain
        // `scheduled_at DESC` put next month's catch-up above this afternoon's
        // stand-up, so the list was least useful exactly when it mattered.
        $now = time();
        // Sorted on next_at, not scheduled_at — otherwise a weekly meeting sorts
        // by the day the series began and sinks into the past forever.
        $ts  = static fn (array $m): int => strtotime($m['next_at'] . ' UTC') ?: 0;
        usort($out, static function (array $a, array $b) use ($now, $ts): int {
            $fa = $ts($a) >= $now; $fb = $ts($b) >= $now;
            if ($fa !== $fb) return $fa ? -1 : 1;          // future block before past block
            return $fa ? $ts($a) <=> $ts($b)               // soonest upcoming first
                       : $ts($b) <=> $ts($a);              // most recent past first
        });
        return $out;
    }

    /**
     * One meeting, for somebody entitled to see it.
     *
     * ── THIS TOOK $uid AND IGNORED IT ────────────────────────────────────
     *
     * The signature always promised an authorisation check and the body never
     * performed one, so `portal/meetings.php?action=get&id=N` handed ANY signed-in
     * member ANY meeting by guessing an integer: the agenda, the whole attendee
     * list, and — the part that matters — the transcript, with its summary,
     * decisions and assigned action items. A private conversation about somebody's
     * performance was one URL away from everyone with a login.
     *
     * `isParticipant()` already existed and {@see saveTranscript} already called
     * it. This path simply never did. Returning null rather than a distinct error
     * keeps the endpoint's 404 honest: a member who is not on a meeting should not
     * even be able to learn that it exists.
     */
    public static function get(int $uid, int $id): ?array
    {
        self::ensure();
        if (!self::canSee($uid, $id)) return null;
        return self::view($id, $uid);
    }

    /**
     * One meeting as a viewer sees it. $uid null is a CACENTRE staff member,
     * read through integrations/cacentre-meetings.php: they see every
     * meeting, and the minutes of those not kept private — or of those they
     * are in, by $staffEmail.
     */
    public static function view(int $id, ?int $uid, string $staffEmail = ''): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM meetings WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $m = self::decorate([self::shape($r, (int) $uid)], $uid !== null ? self::userEmail($uid) : strtolower($staffEmail), (int) $uid)[0];
        $m['attendees'] = self::attendees($id);
        $read = $uid !== null ? self::canReadMinutes($uid, $id)
              : ((int) $r['minutes_private'] === 0 || ($staffEmail !== '' && in_array(strtolower($staffEmail), array_merge($m['attendees'], [strtolower((string) $r['organiser_email'])]), true)));
        $t = self::transcript($id);
        $m['transcript'] = $read ? $t : null;
        /* Said, rather than looking like a meeting nobody took notes in. */
        $m['minutes_hidden'] = !$read && $t !== null;
        return $m;
    }

    /** A member or staff member: sees every meeting. Learners see their own. */
    public static function seesAll(int $uid): bool
    {
        if ($uid <= 0) return false;
        try {
            $st = Database::pdo()->prepare('SELECT * FROM lms_users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            return $u && (string) ($u['status'] ?? 'active') === 'active' && class_exists('LmsAuth') && LmsAuth::isOrgMember($u);
        } catch (Throwable $e) { return false; }
    }

    /** May this person see the meeting — when, where, who, the agenda? */
    public static function canSee(int $uid, int $id): bool
    {
        return self::isParticipant($uid, $id) || self::seesAll($uid);
    }

    /**
     * May they read its minutes and transcript? Everybody who can see it,
     * unless the organiser kept them private: then the people in it, and
     * whoever may manage it.
     */
    public static function canReadMinutes(int $uid, int $id): bool
    {
        if (self::isParticipant($uid, $id)) return true;
        $st = Database::pdo()->prepare('SELECT minutes_private FROM meetings WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetchColumn();
        if ($p === false) return false;
        if ((int) $p === 0) return self::seesAll($uid);
        return self::mayManage($uid, $id);
    }

    /**
     * Who organised each meeting, and whether the viewer is in it — in one
     * query for the list, not one per card.
     *
     * @param list<array> $out shaped meetings
     */
    private static function decorate(array $out, string $viewerEmail, int $viewerId): array
    {
        if (!$out) return $out;
        $ids = array_map(static fn($m) => (int) $m['id'], $out);
        $in = implode(',', $ids);
        $db = Database::pdo();
        $rows = $db->query("SELECT m.id, m.creator_id, m.minutes_private, m.organiser_name, m.organiser_email, m.origin, u.name AS uname, u.email AS uemail
                            FROM meetings m LEFT JOIN lms_users u ON u.id = m.creator_id WHERE m.id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        $by = [];
        foreach ($rows as $r) $by[(int) $r['id']] = $r;
        $mine = [];
        if ($viewerEmail !== '') {
            $st = $db->prepare("SELECT meeting_id FROM meeting_attendees WHERE email = ? AND meeting_id IN ($in)");
            $st->execute([strtolower($viewerEmail)]);
            $mine = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        }
        foreach ($out as $i => $m) {
            $r = $by[(int) $m['id']] ?? [];
            $out[$i]['organiser'] = trim((string) ($r['organiser_name'] ?? '')) !== '' ? (string) $r['organiser_name'] : (string) ($r['uname'] ?? '');
            $out[$i]['organiser_email'] = (string) (($r['organiser_email'] ?? '') !== '' ? $r['organiser_email'] : ($r['uemail'] ?? ''));
            $out[$i]['from_cacentre'] = (string) ($r['origin'] ?? '') === 'cacentre';
            $out[$i]['minutes_private'] = (int) ($r['minutes_private'] ?? 0) === 1;
            $out[$i]['invited'] = isset($mine[(int) $m['id']]) || ($viewerId > 0 && (int) ($r['creator_id'] ?? 0) === $viewerId);
        }
        return $out;
    }

    public static function cancel(int $uid, int $id, bool $mayManage = false): array
    {
        self::ensure();
        $db = Database::pdo();
        $st = $db->prepare('SELECT creator_id, google_event_id FROM meetings WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'error' => 'Meeting not found.'];
        if ((int) $r['creator_id'] !== $uid && !$mayManage) return ['ok' => false, 'error' => 'Only the organiser can cancel.'];
        $db->prepare('UPDATE meetings SET status = \'cancelled\' WHERE id = ?')->execute([$id]);
        if (!empty($r['google_event_id']) && class_exists('GoogleWorkspace')) {
            try { GoogleWorkspace::deleteCalendarEvent((string) $r['google_event_id']); } catch (Throwable $e) {}
        }
        return ['ok' => true];
    }

    /**
     * ── Change a meeting ──────────────────────────────────────────────────
     *
     * There was no way to: a wrong time or a missing person meant cancelling
     * and scheduling again, which sent everybody a cancellation and a second
     * invite and lost the minutes' thread. Now the organiser (or, for a team
     * meeting, whoever runs the team — $mayManage) changes what is wrong:
     * title, time, length, cadence, agenda, who is invited, recording.
     *
     * The Calendar event is patched (Google tells its invitees), and our own
     * email goes out too, as it does on scheduling: "Updated" to everyone when
     * the time, length, cadence or link changed; the invite to anybody newly
     * added. A meeting Google never gave a link — Workspace was not working
     * when it was made — is given one now, if Google will.
     */
    /**
     * May this person change or cancel the meeting? Its organiser; an
     * administrator; and for a team's meeting whoever runs teams, or the
     * team's lead or secretary (MeetingMinutes::mayEdit) — so a meeting is
     * not stuck as it was made when its organiser is away.
     */
    public static function mayManage(int $uid, int $id): bool
    {
        if ($uid <= 0 || $id <= 0) return false;
        try {
            $st = Database::pdo()->prepare('SELECT * FROM meetings WHERE id = ?');
            $st->execute([$id]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
            if (!$m) return false;
            if ((int) $m['creator_id'] === $uid) return true;
            $st = Database::pdo()->prepare('SELECT * FROM lms_users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u || (string) ($u['status'] ?? 'active') !== 'active') return false;
            if (class_exists('LmsAuth') && LmsAuth::rank((string) $u['role']) >= LmsAuth::ROLE_RANK['admin']) return true;
            return class_exists('MeetingMinutes') && class_exists('Volunteers') && MeetingMinutes::mayEdit($u, $m);
        } catch (Throwable $e) { return false; }
    }

    public static function update(int $uid, int $id, array $in, bool $mayManage = false): array
    {
        self::ensure();
        $db = Database::pdo();
        $st = $db->prepare('SELECT * FROM meetings WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'error' => 'Meeting not found.'];
        if ((int) $r['creator_id'] !== $uid && !$mayManage) return ['ok' => false, 'error' => 'Only the organiser can change this meeting.'];
        if ((string) $r['status'] !== 'scheduled') return ['ok' => false, 'error' => 'A cancelled meeting cannot be changed. Schedule a new one.'];

        $set = [];
        if (array_key_exists('title', $in)) {
            $t = mb_substr(trim((string) $in['title']), 0, 200);
            if ($t === '') return ['ok' => false, 'error' => 'Give the meeting a title.'];
            if ($t !== (string) $r['title']) $set['title'] = $t;
        }
        $ts = strtotime((string) $r['scheduled_at'] . ' UTC') ?: time();
        if (array_key_exists('when', $in) && trim((string) $in['when']) !== '') {
            $tz = function_exists('av_user_tz') ? av_user_tz($uid) : 'Africa/Lagos';
            $new = self::localToTs(trim((string) $in['when']), $tz);
            if ($new <= 0) return ['ok' => false, 'error' => 'Pick a date and time.'];
            if (gmdate('Y-m-d H:i:s', $new) !== (string) $r['scheduled_at']) { $set['scheduled_at'] = gmdate('Y-m-d H:i:s', $new); $ts = $new; }
        }
        if (array_key_exists('duration', $in)) { $d = max(5, min(720, (int) $in['duration'])); if ($d !== (int) $r['duration_min']) $set['duration_min'] = $d; }
        /* The rule: asked for, or kept — and either way read against the start
           as it now is, which moves to the rule's first date when they differ. */
        $start = Recurrence::local(gmdate('Y-m-d H:i:s', $ts));
        $oldRule = self::rule($r);
        if (array_key_exists('repeat', $in) || array_key_exists('frequency', $in)) {
            [$rule, $f] = self::askedRule($in, $start);
        } elseif ((string) ($r['recur'] ?? '') === '') {
            /* A simple cadence follows the date: weekly on Tuesdays moved to a
               Wednesday is weekly on Wednesdays. */
            $rule = Recurrence::fromPreset(self::freqKey((string) $r['frequency']), $start);
            $f = Recurrence::presetOf($rule, $start);
        } else {
            /* A rule somebody wrote keeps its days; the date moves to its first. */
            $rule = $oldRule === null ? null : Recurrence::normalise($oldRule, $start);
            $f = Recurrence::presetOf($rule, $start);
        }
        $first = Recurrence::first($rule, $start);
        if ($first === null) return ['ok' => false, 'error' => 'That repeat never happens: its end comes before its first date.'];
        if ($first->getTimestamp() !== $ts) { $ts = $first->getTimestamp(); $set['scheduled_at'] = gmdate('Y-m-d H:i:s', $ts); }
        if (($set['scheduled_at'] ?? null) === (string) $r['scheduled_at']) unset($set['scheduled_at']);
        $recur = $rule !== null && $f === 'custom' ? (string) json_encode($rule) : '';
        if ($f !== self::freqKey((string) $r['frequency']) || $recur !== (string) ($r['recur'] ?? '')) { $set['frequency'] = $f; $set['recur'] = $recur; }
        if (array_key_exists('agenda', $in)) { $a = mb_substr(trim((string) $in['agenda']), 0, 2000); if ($a !== (string) $r['agenda']) $set['agenda'] = $a; }
        if (array_key_exists('minutes_private', $in)) { $mp = !empty($in['minutes_private']) ? 1 : 0; if ($mp !== (int) ($r['minutes_private'] ?? 0)) $set['minutes_private'] = $mp; }
        if (array_key_exists('auto_record', $in)) { $ar = !empty($in['auto_record']) ? 1 : 0; if ($ar !== (int) ($r['auto_record'] ?? 0)) $set['auto_record'] = $ar; }

        /* Who is invited: the list given, the organiser always on it. */
        $before = self::attendees($id);
        $emails = $before;
        if (array_key_exists('attendees', $in)) {
            $emails = self::cleanEmails($in['attendees']);
            $org = self::userEmail((int) $r['creator_id']);
            if ($org !== '' && filter_var($org, FILTER_VALIDATE_EMAIL)) { array_unshift($emails, $org); $emails = array_values(array_unique($emails)); }
        }
        $added = array_values(array_udiff($emails, $before, 'strcasecmp'));
        $removed = array_values(array_udiff($before, $emails, 'strcasecmp'));
        /* Nothing changed, but a link is missing: asking again is the point. */
        $needLink = (string) $r['meet_url'] === '' && (string) $r['google_event_id'] === '';
        if (!$set && !$added && !$removed && !$needLink) return ['ok' => true, 'changed' => [], 'meeting' => self::get($uid, $id)];

        if ($set) {
            $cols = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($set)));
            $db->prepare("UPDATE meetings SET $cols WHERE id = ?")->execute([...array_values($set), $id]);
        }
        if ($added || $removed) {
            $db->prepare('DELETE FROM meeting_attendees WHERE meeting_id = ?')->execute([$id]);
            $ins = $db->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?,?)');
            foreach ($emails as $e) $ins->execute([$id, $e]);
        }
        $m = array_merge($r, $set);
        $title = (string) $m['title']; $dur = (int) $m['duration_min']; $cad = self::cadence($m); $agenda = (string) $m['agenda'];
        $warning = '';

        /* Google: patch the event, or — never given one — make it now. */
        $url = (string) $r['meet_url'];
        if (class_exists('GoogleWorkspace')) {
            if ((string) $r['google_event_id'] !== '') {
                $patch = [];
                if (isset($set['title'])) $patch['summary'] = $title;
                if (isset($set['agenda'])) $patch['description'] = $agenda !== '' ? $agenda : 'Afrovanguard meeting.';
                if (isset($set['scheduled_at']) || isset($set['duration_min'])) {
                    $tzG = (string) Config::get('AV_WS_TZ', 'Africa/Lagos');
                    $patch['start'] = ['dateTime' => gmdate('c', $ts), 'timeZone' => $tzG];
                    $patch['end'] = ['dateTime' => gmdate('c', $ts + $dur * 60), 'timeZone' => $tzG];
                }
                /* The rule's lines hang off the start (BYDAY, BYMONTHDAY, the skipped dates' times): either moving sends them again. */
                if (isset($set['frequency']) || isset($set['scheduled_at'])) $patch['recurrence'] = self::recurrenceLines($m);
                if ($added || $removed) $patch['attendees'] = array_map(static fn($e) => ['email' => $e], $emails);
                if ($patch && !GoogleWorkspace::updateCalendarEvent((string) $r['google_event_id'], $patch)) {
                    $warning = 'Saved, and everyone was emailed, but Google Calendar was not updated. ' . GoogleWorkspace::explain();
                }
            } elseif ($url === '') {
                $link = self::provisionLink($title, gmdate('c', $ts), $dur, $emails, $agenda, self::recurrenceLines($m));
                if ($link['url'] !== '') {
                    $url = $link['url'];
                    $code = GoogleWorkspace::meetCodeFromUrl($url);
                    $db->prepare('UPDATE meetings SET meet_url = ?, provider = ?, google_event_id = ?, meet_code = ? WHERE id = ?')
                       ->execute([$url, $link['provider'], $link['google_event_id'], $code, $id]);
                    if ($code !== '') self::configureCapture($id, $code);
                } else {
                    $warning = 'Saved, but there is still no Meet link. ' . GoogleWorkspace::explain($link['error'] ?? '');
                }
            }
        }

        /* Tell people: everyone when when/how changed; the newly invited either way. */
        $big = isset($set['scheduled_at']) || isset($set['duration_min']) || isset($set['frequency']) || $url !== (string) $r['meet_url'];
        $rec = (bool) ($m['auto_record'] ?? 0) && self::botAllowed();
        if ($big) self::sendInvites($id, $title, $ts, $dur, $cad, $agenda, $emails, $url, (int) $r['creator_id'], $rec, true);
        elseif ($added) self::sendInvites($id, $title, $ts, $dur, $cad, $agenda, $added, $url, (int) $r['creator_id'], $rec);

        /* The notetaker follows the meeting: a moved one is sent when it is about to start. */
        if (isset($set['auto_record'])) {
            $db->prepare('UPDATE meetings SET bot_state = ? WHERE id = ? AND bot_state NOT IN (\'native\', \'in_call\', \'done\')')
               ->execute([$set['auto_record'] ? ($url !== '' && self::botAllowed() ? 'pending' : 'unconfigured') : '', $id]);
        } elseif (isset($set['scheduled_at'])) {
            $db->prepare("UPDATE meetings SET bot_state = 'pending' WHERE id = ? AND bot_state = 'requested'")->execute([$id]);
        }
        if (class_exists('Events')) { try { Events::emit('meeting.updated', ['id' => $id, 'by' => $uid, 'changed' => array_keys($set)]); } catch (Throwable $e) {} }
        $out = ['ok' => true, 'changed' => array_merge(array_keys($set), $added || $removed ? ['attendees'] : []), 'meeting' => self::get($uid, $id) ?? self::shape(array_merge($r, $set, ['meet_url' => $url]), $uid)];
        if ($warning !== '') $out['warning'] = $warning;
        return $out;
    }

    /** ── Transcripts: store raw + AI-structure Otter-style ────────────── */
    public static function saveTranscript(int $uid, int $id, string $rawText, string $source = 'paste'): array
    {
        self::ensure();
        if (!self::isParticipant($uid, $id)) return ['ok' => false, 'error' => 'Not your meeting.'];
        $rawText = trim($rawText);
        if ($rawText === '') return ['ok' => false, 'error' => 'Paste the transcript text first.'];
        $rawText = mb_substr($rawText, 0, 60000);
        $source  = in_array($source, ['paste', 'upload', 'google', 'bot'], true) ? $source : 'paste';
        $db = Database::pdo();
        $db->prepare('DELETE FROM meeting_transcripts WHERE meeting_id = ?')->execute([$id]);
        $db->prepare('INSERT INTO meeting_transcripts (meeting_id, source, raw_text, structured, created_at) VALUES (?,?,?,0,?)')
           ->execute([$id, $source, $rawText, self::now()]);
        $db->prepare('UPDATE meetings SET status = \'done\' WHERE id = ? AND status = \'scheduled\'')->execute([$id]);

        $st = self::structure($rawText);
        if ($st['ok']) {
            $db->prepare('UPDATE meeting_transcripts SET summary=?, highlights=?, decisions=?, action_items=?, structured=1 WHERE meeting_id=?')
               ->execute([
                   $st['summary'],
                   json_encode($st['highlights'], JSON_UNESCAPED_UNICODE),
                   json_encode($st['decisions'], JSON_UNESCAPED_UNICODE),
                   json_encode($st['action_items'], JSON_UNESCAPED_UNICODE),
                   $id,
               ]);
            // G-1: the action items become tracked commitments rather than ending
            // here as JSON nobody reads again. Owners are NOT assigned — each one
            // arrives with the name the model heard and waits for a human to
            // confirm it (commitments.auto_assign_owner ships off).
            self::fileCommitments($id, (array) $st['action_items']);
        }
        return ['ok' => true, 'structured' => $st['ok'], 'note' => $st['ok'] ? '' : ($st['error'] ?? ''), 'transcript' => self::transcript($id)];
    }

    /**
     * Turn a raw transcript into Otter-style structured minutes via the
     * Afrovanguard bot (Anthropic). Returns summary + highlights + decisions +
     * action_items (each action = {task, owner}). Best-effort: if AI isn't
     * configured or the JSON is unparseable, ok=false and the raw text still
     * stands.
     */
    /**
     * File a meeting's action items as commitments (G-1).
     *
     * Wrapped and swallowed: the minutes are the valuable artefact and are already
     * saved by the time this runs. A commitments failure must never cost somebody
     * their transcript.
     */
    private static function fileCommitments(int $meetingId, array $actionItems): void
    {
        if (!$actionItems || !class_exists('Commitments')) return;
        try { Commitments::fileMany(Commitments::SRC_MEETING, $meetingId, $actionItems); }
        catch (Throwable $e) { error_log('[meetings] file commitments: ' . $e->getMessage()); }
    }

    public static function structure(string $rawText): array
    {
        $rawText = trim($rawText);
        if ($rawText === '') return ['ok' => false, 'error' => 'Empty transcript.'];
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) {
            return ['ok' => false, 'error' => 'AI assistance is switched off in the Studio rules.'];
        }
        // The instructions, the organisation's rules and its own knowledge all
        // come from the Studio (AvPrompts → AvRules + AvKnowledge), so leadership
        // can change how minutes are written without a deployment.
        $sys = class_exists('AvPrompts')
            ? AvPrompts::render('meeting.minutes')
            : 'You are a meeting-minutes assistant for the Afrovanguard organisation. '
              . 'Read the raw meeting transcript and return STRICT JSON only — no prose, no markdown fences. '
              . 'Schema: {"summary": string, "highlights": string[], "decisions": string[], '
              . '"action_items": [{"task": string, "owner": string, "due_days": integer|null}]}. '
              . 'Keep it faithful to the transcript; do not invent facts.';
        // Both numbers come from the rules, exactly as Mentorship::structureSession()
        // reads them. They used to be hardcoded here and read from AvRules there,
        // which is how the two copies of this job silently diverged.
        $chars  = class_exists('AvRules') ? AvRules::int('meetings.transcript_char_limit') : 20000;
        $maxTok = class_exists('AvRules') ? AvRules::int('ai.max_tokens') : 2048;
        // Fail loudly rather than summarise an empty transcript — see the same
        // guard in Mentorship::structureSession().
        if ($chars <= 0) return ['ok' => false, 'error' => 'meetings.transcript_char_limit is misconfigured (' . $chars . ').'];
        $prompt = "Transcript:\n\n" . mb_substr($rawText, 0, $chars);

        // Reason, not bulk: minutes decide who owes what by when, and the action
        // items filed from here become tracked commitments against real names.
        $res = class_exists('AvAgent')
            ? AvAgent::complete($sys, $prompt, ['max_tokens' => $maxTok, 'temperature' => 0.1,
                                                'actor' => 'meetings.structure'])
            : ['ok' => false, 'error' => 'Router unavailable.'];
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => ($res['error'] ?? null) ? (string) $res['error'] : 'No AI provider is configured.'];
        }
        $json = self::extractJson((string) $res['text']);
        if (!is_array($json)) return ['ok' => false, 'error' => 'Could not parse the AI summary.'];
        // due_days is optional and advisory: the model only sets it when the
        // transcript actually stated a deadline. Anything out of range is dropped
        // rather than clamped — an invented "due in 900 days" is not a deadline.
        $acts = [];
        foreach (($json['action_items'] ?? []) as $a) {
            if (is_array($a)) {
                $days = $a['due_days'] ?? null;
                $days = (is_int($days) || (is_string($days) && ctype_digit($days))) ? (int) $days : null;
                if ($days !== null && ($days < 1 || $days > 365)) $days = null;
                $acts[] = ['task' => (string) ($a['task'] ?? ''), 'owner' => (string) ($a['owner'] ?? ''), 'due_days' => $days];
            } elseif (is_string($a)) {
                $acts[] = ['task' => $a, 'owner' => '', 'due_days' => null];
            }
        }
        return [
            'ok'           => true,
            'summary'      => (string) ($json['summary'] ?? ''),
            'highlights'   => array_values(array_filter(array_map('strval', (array) ($json['highlights'] ?? [])))),
            'decisions'    => array_values(array_filter(array_map('strval', (array) ($json['decisions'] ?? [])))),
            'action_items' => array_values(array_filter($acts, fn($a) => $a['task'] !== '')),
        ];
    }

    /**
     * Best-effort auto-pull of a Google Meet transcript. Meet writes transcripts
     * to the host's Drive as a Doc named "<title> - Transcript"; find the most
     * recent match and read its text. Needs the Workspace service account +
     * Drive read scope; otherwise returns null and the user pastes manually.
     */
    public static function pullGoogleTranscript(int $uid, int $id): array
    {
        self::ensure();
        if (!self::isParticipant($uid, $id)) return ['ok' => false, 'error' => 'Not your meeting.'];
        $m = self::get($uid, $id);
        if (!$m) return ['ok' => false, 'error' => 'Meeting not found.'];
        if (!class_exists('GoogleWorkspace') || !GoogleWorkspace::configured()) {
            return ['ok' => false, 'error' => GoogleWorkspace::explain() . ' Until then, paste the transcript here.'];
        }
        try {
            // 1) The official Meet transcript via the Meet REST API (best source).
            $code = (string) ($m['meet_code'] ?? '');
            $held = false;
            if ($code !== '') {
                /* As the organiser, who owns the space; the creator as a fallback. */
                $afterTs = strtotime((string) $m['scheduled_at'] . ' UTC') ?: 0;
                foreach (array_unique(array_filter([GoogleWorkspace::organiser(), self::userEmail((int) $m['creator_id'])])) as $who) {
                    $conf = GoogleWorkspace::meetConference($code, $who, $afterTs);
                    if (!$conf) continue;
                    $held = true;
                    $t = GoogleWorkspace::conferenceTranscript($conf['name'], $who);
                    if ($t['state'] === 'ready') return self::saveTranscript($uid, $id, $t['text'], 'google');
                    if ($t['state'] === 'pending') return ['ok' => false, 'pending' => true, 'error' => 'Google is still writing the transcript. It arrives here on its own.'];
                }
            }
            // 2) Fallback: the transcript Doc Meet writes to the host's Drive.
            $files = GoogleWorkspace::driveFiles(null, 60);
            $needle = mb_strtolower($m['title']);
            $best = null;
            foreach ($files as $f) {
                $name = mb_strtolower((string) ($f['name'] ?? ''));
                if ($name === '') continue;
                if (mb_strpos($name, 'transcript') !== false && ($needle === '' || mb_strpos($name, mb_substr($needle, 0, 24)) !== false)) { $best = $f; break; }
            }
            if (!$best || empty($best['id'])) {
                $why = GoogleWorkspace::lastError();
                return ['ok' => false, 'error' => $why !== '' ? 'Google would not give the transcript. ' . GoogleWorkspace::explain($why)
                    : ($code !== '' && !$held ? 'Nobody has joined this meeting’s Meet yet, so there is no transcript.'
                    : 'Google has no transcript for this call — transcription may have been off. It can also take a few minutes after the call; or paste it here.')];
            }
            $text = GoogleWorkspace::driveText((string) $best['id']);
            if (!$text) return ['ok' => false, 'error' => 'Found the transcript file but couldn’t read it.'];
            return self::saveTranscript($uid, $id, $text, 'google');
        } catch (Throwable $e) {
            error_log('[meetings] pull transcript: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not fetch the transcript.'];
        }
    }

    /**
     * Transcribe an uploaded recording with Gemini Flash, then structure it.
     * $mime e.g. audio/mpeg, audio/webm, audio/wav.
     */
    public static function transcribeAudio(int $uid, int $id, string $bytes, string $mime): array
    {
        self::ensure();
        if (!self::isParticipant($uid, $id)) return ['ok' => false, 'error' => 'Not your meeting.'];

        // Whisper first when it is available: it is a real multipart upload, so
        // it takes the ~25MB the API allows where Gemini's base64 inlining stops
        // around 19MB — which is the difference between "most meetings" and
        // "short ones". Gemini remains the fallback and the cheaper option.
        $via = ''; $res = null;
        if (class_exists('OpenAi') && OpenAi::configured()) {
            $res = OpenAi::transcribeAudio($bytes, $mime);
            $via = 'whisper';
        }
        if ((!$res || empty($res['ok'])) && class_exists('Gemini') && Gemini::configured()) {
            $g = Gemini::transcribeAudio($bytes, $mime);
            // Only let Gemini's error replace Whisper's if Gemini was actually tried.
            if (!empty($g['ok']) || !$res) { $res = $g; $via = 'gemini'; }
        }
        if (!$res) {
            return ['ok' => false, 'error' => 'Audio transcription isn’t configured (set OPENAI_API_KEY for Whisper, or AV_GEMINI_API_KEY).'];
        }
        if (empty($res['ok'])) return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Transcription failed.')];
        return self::saveTranscript($uid, $id, (string) $res['text'], $via);
    }

    /** ── The AI notetaker ─────────────────────────────────────────────── */

    /** Can an uploaded recording be transcribed here? Whisper or Gemini. */
    public static function audioTranscription(): bool
    {
        return (class_exists('OpenAi') && OpenAi::configured()) || (class_exists('Gemini') && Gemini::configured());
    }

    /** A recording bot is available if ANY provider is wired. */
    public static function botConfigured(): bool { return self::botProvider() !== ''; }

    /** Leadership's master switch (rules) AND a wired provider. Both must hold. */
    public static function botAllowed(): bool
    {
        if (class_exists('AvRules') && !AvRules::bool('meetings.ai_notetaker')) return false;
        return self::botConfigured();
    }

    /** Whether a participant may add the notetaker to a meeting after the fact. */
    public static function botOnDemandAllowed(): bool
    {
        if (!self::botAllowed()) return false;
        return !class_exists('AvRules') || AvRules::bool('meetings.bot_on_demand');
    }

    /**
     * When the bot should join: the meeting start, pulled forward by the
     * configured lead so it is already in the room when the first person
     * arrives. Never in the past — a join_at that has already passed is
     * rejected by the provider, so a meeting that has already started gets
     * "now" instead.
     */
    private static function botJoinAtIso(string $scheduledUtc): string
    {
        $lead = class_exists('AvRules') ? AvRules::int('meetings.bot_join_lead_min') : 2;
        $ts   = strtotime($scheduledUtc . ' UTC') ?: time();
        return gmdate('c', max(time() + 10, $ts - $lead * 60));
    }

    /**
     * Send the notetaker into a meeting on demand.
     *
     * Until now a bot could only be requested at schedule time, by ticking
     * auto-record before the meeting existed. That covers the meeting you
     * planned and none of the ones you are actually in — the call that ran long,
     * the one someone else scheduled, the one where minutes turned out to matter
     * after it began. This is that path.
     *
     * Idempotent: a meeting that already has a live bot returns ok without
     * sending a second one, so a double-tap in the portal cannot put two
     * notetakers in the room.
     */
    public static function inviteBot(int $uid, int $id): array
    {
        self::ensure();
        if (!self::isParticipant($uid, $id)) return ['ok' => false, 'error' => 'Not your meeting.'];
        if (!self::botOnDemandAllowed()) {
            return ['ok' => false, 'error' => self::botAllowed()
                ? 'Adding the notetaker mid-meeting is switched off for this organisation.'
                : 'The AI notetaker is not available. Use Google\'s transcript or paste one afterwards.'];
        }
        $m = self::get($uid, $id);
        if (!$m) return ['ok' => false, 'error' => 'Meeting not found.'];
        if ((string) $m['meet_url'] === '') {
            return ['ok' => false, 'error' => 'This meeting has no Google Meet link for the notetaker to join.'];
        }
        if (in_array((string) $m['bot_state'], ['requested', 'joining', 'in_call', 'native'], true)) {
            return ['ok' => true, 'already' => true, 'meeting' => $m];
        }

        // Joining now — this is an explicit "add it to the call I'm in".
        $provider = self::botProvider();
        self::requestBot($id, (string) $m['meet_url'], $provider, gmdate('c', time() + 10));

        $db = Database::pdo();
        $db->prepare('UPDATE meetings SET auto_record = 1 WHERE id = ?')->execute([$id]);

        $fresh = self::get($uid, $id);
        $state = (string) ($fresh['bot_state'] ?? '');
        if ($state === 'error' || $state === 'unconfigured') {
            return ['ok' => false, 'error' => 'The notetaker could not join. Google\'s own transcript or a pasted one still works.', 'meeting' => $fresh];
        }
        if (class_exists('Events')) { try { Events::emit('meeting.bot_invited', ['id' => $id, 'by' => $uid]); } catch (Throwable $e) {} }
        return ['ok' => true, 'meeting' => $fresh];
    }

    /**
     * Take the notetaker back out. Anyone in the meeting can do this — a person
     * who wants a conversation off the record should not have to find the
     * organiser first.
     */
    public static function removeBot(int $uid, int $id): array
    {
        self::ensure();
        if (!self::isParticipant($uid, $id)) return ['ok' => false, 'error' => 'Not your meeting.'];
        $m = self::get($uid, $id);
        if (!$m) return ['ok' => false, 'error' => 'Meeting not found.'];

        $ref = (string) $m['bot_ref'];
        $prov = (string) $m['bot_provider'];
        if ($ref !== '') {
            $r = null;
            if ($prov === 'attendee' && class_exists('AttendeeBot')) $r = AttendeeBot::removeBot($ref);
            if ($r !== null && empty($r['ok'])) {
                error_log('[meetings] removeBot: ' . (string) ($r['error'] ?? ''));
                return ['ok' => false, 'error' => 'Could not remove the notetaker — try again in a moment.'];
            }
        }
        // Clear auto_record too, or the cron sweep would send it straight back in.
        Database::pdo()->prepare("UPDATE meetings SET bot_state = 'removed', auto_record = 0 WHERE id = ?")->execute([$id]);
        if (class_exists('Events')) { try { Events::emit('meeting.bot_removed', ['id' => $id, 'by' => $uid]); } catch (Throwable $e) {} }
        return ['ok' => true, 'meeting' => self::get($uid, $id)];
    }

    /**
     * Dispatch bots for meetings that are about to start.
     *
     * Two jobs. A notetaker that cannot be told to join later (Attendee) needs
     * sending at the right moment rather than at schedule time. And
     * any dispatch that failed — the provider was down, the key was missing, the
     * Meet link had not been provisioned yet — gets another chance here instead
     * of being lost silently.
     *
     * Called from tasks/cron.php. Returns how many bots were dispatched.
     */
    public static function dispatchDueBots(int $withinMin = 0): int
    {
        self::ensure();
        if (!self::botAllowed()) return 0;

        $lead = class_exists('AvRules') ? AvRules::int('meetings.bot_join_lead_min') : 2;
        $withinMin = $withinMin > 0 ? $withinMin : max(5, $lead + 3);

        $now = time();
        $until = gmdate('Y-m-d H:i:s', $now + $withinMin * 60);
        // Look a little way back too, so a meeting that started while the cron
        // was between runs still gets its notetaker.
        $from  = gmdate('Y-m-d H:i:s', $now - 30 * 60);

        $n = 0;
        try {
            $db = Database::pdo();
            $st = $db->prepare(
                "SELECT id, meet_url, scheduled_at, bot_state FROM meetings
                  WHERE auto_record = 1 AND status = 'scheduled' AND meet_url <> ''
                    AND scheduled_at >= ? AND scheduled_at <= ?
                    AND bot_state IN ('', 'error', 'pending')
                  ORDER BY scheduled_at ASC"
            );
            $st->execute([$from, $until]);
            $provider = self::botProvider();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                self::requestBot((int) $r['id'], (string) $r['meet_url'], $provider, self::botJoinAtIso((string) $r['scheduled_at']));
                $n++;
            }
        } catch (Throwable $e) { error_log('[meetings] dispatchDueBots: ' . $e->getMessage()); }
        return $n;
    }

    /**
     * Which notetaker to use: 'attendee', 'google' or '' for none.
     *
     * Attendee is the only third-party meeting bot, and it is optional — the site
     * works without it. AV_MEET_BOT_PROVIDER can force 'attendee' or switch the
     * notetaker off with 'none'; anything else is auto-detected. Google's own Meet
     * transcription is not a bot at all, but it is how transcripts arrive without
     * one, so it counts as the fallback.
     */
    public static function botProvider(): string
    {
        $p = strtolower(trim((string) getenv('AV_MEET_BOT_PROVIDER')));
        if ($p === 'none') return '';
        if ($p === 'attendee') return 'attendee';
        if (class_exists('AttendeeBot') && AttendeeBot::configured()) return 'attendee';
        if (class_exists('GoogleWorkspace') && GoogleWorkspace::meetEnabled()) return 'google';
        return '';
    }

    /**
     * Dispatch the notetaker for a meeting to the chosen provider:
     *   • attendee — send the Attendee bot to join, record and transcribe; the
     *                cron sweep polls it for the transcript afterwards.
     *   • google   — Google records/transcribes the space natively; nothing to
     *                request now (we pull the transcript afterwards).
     * Anything else records 'unconfigured' without a network call. Best-effort;
     * on failure the manual paste / Google-transcript paths remain.
     */
    private static function requestBot(int $id, string $meetUrl, string $provider, string $joinAtIso = ''): void
    {
        $db = Database::pdo();
        $state = 'unconfigured'; $ref = '';

        if ($provider === 'attendee' && class_exists('AttendeeBot') && AttendeeBot::configured()) {
            // No webhook is passed: the cron sweep polls for the transcript, so a
            // site on shared hosting needs no publicly reachable callback at all.
            //
            // The metadata rides along so a transcript can say which meeting it
            // belongs to without this table being consulted, and the dedup key lets
            // Attendee refuse a second bot for a meeting that already has a live one
            // — the sweep and a hand-pressed button arriving together used to make two.
            // With a webhook secret set, Attendee also pushes the meeting LIVE —
            // joins, leaves and each utterance — to MeetingLive; the sweep's
            // polling stays the fallback.
            $hook = AttendeeBot::webhookSecret() !== '' ? self::siteUrl('/portal/meetings.php?action=attendee_webhook') : '';
            $res = AttendeeBot::createBot($meetUrl, $hook, $joinAtIso, [
                'metadata' => ['av_meeting_id' => (string) $id, 'av_source' => 'meetings'],
                'dedup'    => 'av-meeting-' . $id,
            ]);
            if (!empty($res['ok'])) { $state = 'requested'; $ref = (string) $res['bot_id']; }
            elseif (!empty($res['duplicate'])) {
                // Attendee already has a live bot under this key, which means an
                // earlier tick got one. Writing 'error' and a blank ref here would
                // throw away the reference to a bot that is on its way.
                error_log('[meetings] attendee: bot already live for meeting ' . $id . ', leaving it alone');
                return;
            }
            else { $state = 'error'; error_log('[meetings] attendee: ' . (string) ($res['error'] ?? '')); }
        } elseif ($provider === 'google') {
            // Google Meet is recording/transcribing natively (space created with
            // auto-transcription ON) — pull the transcript after the call.
            $state = 'native';
        }
        $db->prepare('UPDATE meetings SET bot_state = ?, bot_provider = ?, bot_ref = ? WHERE id = ?')
           ->execute([$state, $provider, $ref, $id]);
    }

    /**
     * Collect transcripts for bots that have finished.
     *
     * A push callback requires the site to be publicly reachable at a stable
     * URL — which a shared-hosting deployment behind a staging domain, or one
     * that has just moved, often is not. Polling removes
     * that requirement entirely: the cron asks each bot in flight whether it is
     * done, and ingests when it is. A self-hosted notetaker therefore needs no
     * inbound callback at all.
     *
     * Only bots that are actually in flight are polled, and each is tried a
     * bounded number of times, so a bot that dies mid-call cannot be polled
     * forever. Returns how many transcripts were ingested.
     */
    public static function pollBotTranscripts(int $limit = 20): int
    {
        self::ensure();
        if (!self::botAllowed()) return 0;
        $n = 0;
        try {
            $db = Database::pdo();
            // Look back a day: a meeting that ran long or a transcription queue
            // that was slow should still be collected, but yesterday's is stale.
            $since = gmdate('Y-m-d H:i:s', time() - 86400);
            $st = $db->prepare(
                "SELECT id, bot_provider, bot_ref FROM meetings
                  WHERE bot_ref <> '' AND bot_provider = 'attendee'
                    AND bot_state IN ('requested','joining','in_call')
                    AND scheduled_at >= ?
                  ORDER BY scheduled_at ASC"
            );
            $st->execute([$since]);
            foreach (array_slice($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 0, max(1, $limit)) as $r) {
                $id   = (int) $r['id'];
                $ref  = (string) $r['bot_ref'];

                $state = class_exists('AttendeeBot') ? AttendeeBot::botStatus($ref) : '';

                // Keep the stored state honest even before the transcript lands,
                // so the portal shows "in the meeting" rather than "will join".
                if ($state !== '' && $state !== 'done') {
                    $db->prepare('UPDATE meetings SET bot_state = ? WHERE id = ?')->execute([$state, $id]);
                    continue;
                }
                if ($state === 'error') {
                    $db->prepare("UPDATE meetings SET bot_state = 'error' WHERE id = ?")->execute([$id]);
                    continue;
                }
                if ($state !== 'done') continue;   // unknown: leave it for the next tick

                $text = class_exists('AttendeeBot') ? AttendeeBot::fetchTranscript($ref) : '';
                if ($text === '') continue;        // ended but still transcribing

                $res = self::botIngest($id, self::botToken($id), $text);
                if (!empty($res['ok'])) $n++;
            }
        } catch (Throwable $e) { error_log('[meetings] pollBotTranscripts: ' . $e->getMessage()); }
        return $n;
    }

    private static function siteUrl(string $path): string
    {
        return rtrim((string) (defined('SITE_URL') ? SITE_URL : ''), '/') . $path;
    }

    /** HMAC token that authorises writing a bot transcript into a meeting. */
    public static function botToken(int $id): string
    {
        $secret = function_exists('av_secret') ? (string) av_secret() : (defined('APP_KEY') ? (string) APP_KEY : 'av');
        return hash_hmac('sha256', 'bot|' . $id, $secret);
    }

    /**
     * Store a notetaker's transcript (and structure it) once the meeting ends.
     * Called by the poll sweep, which has no user session, so the per-meeting
     * HMAC token stands in for one. Returns the API shape.
     */
    public static function botIngest(int $id, string $token, string $transcript): array
    {
        self::ensure();
        if (!hash_equals(self::botToken($id), (string) $token)) return ['ok' => false, 'error' => 'Bad token.'];
        $transcript = trim($transcript);
        if ($transcript === '') return ['ok' => false, 'error' => 'Empty transcript.'];
        $db = Database::pdo();
        // Save raw + structured minutes. Ownership check is bypassed (service),
        // so write directly rather than via saveTranscript (which is user-scoped).
        $db->prepare('DELETE FROM meeting_transcripts WHERE meeting_id = ?')->execute([$id]);
        $db->prepare('INSERT INTO meeting_transcripts (meeting_id, source, raw_text, structured, created_at) VALUES (?,?,?,0,?)')
           ->execute([$id, 'bot', mb_substr($transcript, 0, 60000), self::now()]);
        $db->prepare('UPDATE meetings SET status = \'done\', bot_state = \'done\' WHERE id = ?')->execute([$id]);
        $st = self::structure($transcript);
        if ($st['ok']) {
            $db->prepare('UPDATE meeting_transcripts SET summary=?, highlights=?, decisions=?, action_items=?, structured=1 WHERE meeting_id=?')
               ->execute([$st['summary'], json_encode($st['highlights'], JSON_UNESCAPED_UNICODE), json_encode($st['decisions'], JSON_UNESCAPED_UNICODE), json_encode($st['action_items'], JSON_UNESCAPED_UNICODE), $id]);
            self::fileCommitments($id, (array) $st['action_items']);   // G-1, same as the manual path
        }
        return ['ok' => true, 'structured' => $st['ok']];
    }

    /** ── helpers ──────────────────────────────────────────────────────── */

    /**
     * A row → the API shape.
     *
     * `$viewerId` is what makes `is_owner` true. It used to be hardcoded `false`
     * for every meeting and every viewer, so the field was not a fact about the
     * meeting, it was a constant — an organiser reading their own meeting was
     * told they did not own it. Nothing in the current UI trusted it (the JS
     * compares `creator_id` itself), which is precisely why it stayed wrong: a
     * lie nobody consults is a lie waiting for the next caller.
     */
    /**
     * For a repeating meeting, the next occurrence at or after now.
     *
     * ── WHY THIS IS COMPUTED AND NOT STORED ──────────────────────────────
     *
     * `scheduled_at` is the FIRST occurrence and never moves; the cadence goes
     * to Google as an RRULE and Google expands it on its side. Nothing expanded
     * it on ours, so a weekly stand-up set up in March showed "12 March" for the
     * rest of the year and sorted into the past the week after it started —
     * the recurring meetings, the ones people actually depend on, were the ones
     * the list handled worst.
     *
     * Deriving it keeps a single source of truth (the series start + the rule)
     * instead of a stored "next" that drifts whenever a job fails to run.
     * The rule is expanded on the meeting clock (lib/Recurrence.php), the way
     * Google expands it, so both say the same date.
     */
    private static function nextOccurrence(array $r, ?int $now = null): string
    {
        $n = Recurrence::upcoming(self::rule($r), self::seriesStart($r), $now ?? time(), 1);
        /* A series that has ended reads as where it started, as before. */
        return $n ? gmdate('Y-m-d H:i:s', $n[0]->getTimestamp()) : (string) $r['scheduled_at'];
    }

    private static function shape(array $r, int $viewerId = 0): array
    {
        $freq = self::freqKey((string) ($r['frequency'] ?? 'once'));
        $rule = self::rule($r);
        $next = self::nextOccurrence($r);
        return [
            'id'          => (int) $r['id'],
            'title'       => (string) $r['title'],
            'agenda'      => (string) $r['agenda'],
            'scheduled_at'=> (string) $r['scheduled_at'],
            // `next_at` is what a reader wants: for a one-off it is the meeting,
            // for a series it is the occurrence that has not happened yet.
            // `scheduled_at` stays as the series start so nothing downstream
            // that already relies on it changes meaning.
            'next_at'     => $next,
            'is_series'   => $rule !== null,
            'when_iso'    => gmdate('c', strtotime($next . ' UTC') ?: time()),
            /* For the edit form: the start, on the viewer's own clock, as a datetime-local value. */
            'when_local'  => (static function () use ($r, $viewerId): string {
                try { return (new DateTime((string) $r['scheduled_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(function_exists('av_user_tz') ? av_user_tz($viewerId) : 'Africa/Lagos'))->format('Y-m-d\\TH:i'); }
                catch (Throwable $e) { return ''; }
            })(),
            'duration_min'=> (int) $r['duration_min'],
            'frequency'   => $freq,
            'frequency_label' => Recurrence::label($rule, self::seriesStart($r)),
            /* The rule itself, for the edit form; and the next few dates, so a
               reader sees "Thu 14, Mon 25, Thu 28…" rather than working it out. */
            'repeat'      => $rule,
            'upcoming'    => $rule === null ? [] : array_map(static fn(DateTimeImmutable $d) => gmdate('c', $d->getTimestamp()),
                                Recurrence::upcoming($rule, self::seriesStart($r), time(), 4, (int) $r['duration_min'])),
            'context'     => (string) $r['context'],
            'context_id'  => (int) $r['context_id'],
            'provider'    => (string) $r['provider'],
            'meet_url'    => (string) $r['meet_url'],
            'meet_code'   => (string) ($r['meet_code'] ?? ''),
            'auto_record' => (int) ($r['auto_record'] ?? 0) === 1,
            /* What Google captures in this meeting's Meet: all (recording, transcript, notes),
               transcript (the edition has no recording), off, or '' with the reason why not. */
            'capture'     => (string) ($r['meet_capture'] ?? ''),
            'capture_note'=> (string) ($r['capture_note'] ?? ''),
            'bot_state'   => (string) ($r['bot_state'] ?? ''),
            'bot_provider'=> (string) ($r['bot_provider'] ?? ''),
            'bot_ref'     => (string) ($r['bot_ref'] ?? ''),
            'status'      => (string) $r['status'],
            'is_owner'    => $viewerId > 0 && (int) $r['creator_id'] === $viewerId,
            'creator_id'  => (int) $r['creator_id'],
        ];
    }

    /**
     * Email everyone the meeting details. Best-effort, never fatal.
     *
     * ── WHY IT SENDS EVEN WHEN GOOGLE ALREADY DID ────────────────────────
     *
     * Google's invite only reaches people whose address Calendar accepted, and
     * only while Workspace stays connected. Making our own send conditional on
     * Google having failed would mean the notification path that matters most is
     * the one that is never exercised — it would sit untested until the day
     * Calendar was disconnected, and then be discovered by someone missing a
     * meeting. Sending both ways means the path is warm.
     *
     * Times are rendered in each recipient's own timezone where the platform
     * knows it, because "14:00" without a zone is how a Lagos meeting gets
     * missed by somebody in Nairobi.
     *
     * @param list<string> $emails
     */
    private static function sendInvites(
        int $id, string $title, int $ts, int $durationMin, string $freq,
        string $agenda, array $emails, string $meetUrl, int $organiserId,
        bool $recorded = false, bool $updated = false, string $organiserName = ''
    ): void {
        if (!class_exists('Mailer') || $emails === []) return;

        // People should be told a meeting will be transcribed in the invite, not
        // discover a notetaker in the participant list once they have joined.
        $announce = $recorded && (!class_exists('AvRules') || AvRules::bool('meetings.bot_announce'));

        $organiser = trim($organiserName);
        if ($organiser === '') try {
            $st = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?');
            $st->execute([$organiserId]);
            $organiser = trim((string) ($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {}

        $site  = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        /* A one-word cadence, or the rule's own words (Meetings::cadence). */
        $cad   = isset(self::FREQ[$freq]) ? self::freqLabel($freq) : $freq;
        $subj  = ($updated ? 'Updated: ' : 'Meeting: ') . $title;

        foreach ($emails as $to) {
            try {
                // Per-recipient timezone when we know the member; the organiser's
                // otherwise. av_user_tz() falls back to Africa/Lagos itself.
                $tz = 'Africa/Lagos';
                try {
                    $u = Database::pdo()->prepare('SELECT id FROM lms_users WHERE LOWER(email) = ?');
                    $u->execute([strtolower($to)]);
                    $rid = (int) ($u->fetchColumn() ?: 0);
                    if ($rid > 0 && function_exists('av_user_tz')) $tz = av_user_tz($rid);
                } catch (Throwable $e) {}

                $when = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone($tz));
                $whenLine = $when->format('l j F Y, H:i') . ' (' . $tz . ')';

                $lines = [
                    $updated
                        ? '<strong>' . htmlspecialchars($title) . '</strong> has changed' . ($organiser !== '' ? ' — ' . htmlspecialchars($organiser) . ' updated it' : '') . '. Here is how it stands now.'
                        : ($organiser !== '' ? htmlspecialchars($organiser) . ' has' : 'You have been') . ' invited you to <strong>' . htmlspecialchars($title) . '</strong>.',
                    '<strong>When:</strong> ' . htmlspecialchars($whenLine) . '<br>'
                        . '<strong>Length:</strong> ' . $durationMin . ' minutes<br>'
                        . '<strong>Repeats:</strong> ' . htmlspecialchars($cad),
                ];
                if ($agenda !== '') $lines[] = '<strong>Agenda</strong><br>' . nl2br(htmlspecialchars($agenda));
                if ($meetUrl === '') {
                    $lines[] = 'A video link has not been attached to this meeting yet — the organiser will share one before it starts.';
                }
                if ($announce) {
                    $lines[] = '<strong>Note:</strong> an Afrovanguard notetaker will join this meeting to capture a transcript, '
                        . 'which is turned into minutes and action items afterwards. It appears in the participant list, '
                        . 'and anyone in the meeting can remove it from the portal.';
                }

                $cta = $meetUrl !== ''
                    ? ['text' => 'Join the meeting', 'url' => $meetUrl]
                    : ['text' => 'Open it in the portal', 'url' => $site . '/portal/#meetings'];

                $html = Mailer::shell(($updated ? 'Changed: ' : 'You’re invited: ') . $title, $lines, $cta,
                    'Meeting invitation from Afrovanguard.');
                Mailer::send($to, $subj, $html);
            } catch (Throwable $e) {
                error_log('[meetings] invite to ' . $to . ': ' . $e->getMessage());
            }
        }
    }

    private static function attendees(int $id): array
    {
        $st = Database::pdo()->prepare('SELECT email FROM meeting_attendees WHERE meeting_id = ?');
        $st->execute([$id]);
        return array_values(array_filter(array_map(fn($r) => (string) $r['email'], $st->fetchAll(PDO::FETCH_ASSOC) ?: [])));
    }

    private static function transcript(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM meeting_transcripts WHERE meeting_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return [
            'source'       => (string) $r['source'],
            'structured'   => (int) $r['structured'] === 1,
            'summary'      => (string) $r['summary'],
            'highlights'   => json_decode((string) ($r['highlights'] ?: '[]'), true) ?: [],
            'decisions'    => json_decode((string) ($r['decisions'] ?: '[]'), true) ?: [],
            'action_items' => json_decode((string) ($r['action_items'] ?: '[]'), true) ?: [],
            'has_raw'      => trim((string) $r['raw_text']) !== '',
            'created_at'   => (string) $r['created_at'],
        ];
    }

    public static function isParticipant(int $uid, int $id): bool
    {
        $st = Database::pdo()->prepare('SELECT creator_id FROM meetings WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return false;
        if ((int) $r['creator_id'] === $uid) return true;
        $email = self::userEmail($uid);
        if ($email === '') return false;
        $a = Database::pdo()->prepare('SELECT 1 FROM meeting_attendees WHERE meeting_id = ? AND email = ?');
        $a->execute([$id, $email]);
        return (bool) $a->fetchColumn();
    }

    private static function cleanEmails($raw): array
    {
        $list = is_array($raw) ? $raw : preg_split('/[,;\s]+/', (string) $raw);
        $out = [];
        foreach ((array) $list as $e) {
            $e = trim((string) $e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[strtolower($e)] = strtolower($e);
        }
        return array_values(array_slice($out, 0, 50));
    }

    private static function userEmail(int $uid): string
    {
        try {
            $st = Database::pdo()->prepare('SELECT email FROM lms_users WHERE id = ?');
            $st->execute([$uid]);
            return strtolower((string) ($st->fetchColumn() ?: ''));
        } catch (Throwable $e) { return ''; }
    }

    /** Interpret a "Y-m-dTH:i" wall-clock in the user's tz as a UTC timestamp. */
    private static function localToTs(string $local, string $tz): int
    {
        $local = str_replace('T', ' ', trim($local));
        try {
            $dt = new DateTime($local, new DateTimeZone($tz));
            return $dt->getTimestamp();
        } catch (Throwable $e) {
            return strtotime($local) ?: time();
        }
    }

    /** Pull the first JSON object out of a model response (tolerates fences). */
    private static function extractJson(string $s): ?array
    {
        $s = trim($s);
        $s = preg_replace('/^```(?:json)?|```$/m', '', $s) ?? $s;
        $a = strpos($s, '{');
        $b = strrpos($s, '}');
        if ($a === false || $b === false || $b <= $a) return null;
        $j = json_decode(substr($s, $a, $b - $a + 1), true);
        return is_array($j) ? $j : null;
    }
}
