<?php
/**
 * lib/NgvCourses.php — the courses half of the learning record.
 *
 * Reading was the whole of it, and it is not: a vanguard takes **twelve
 * courses a year** alongside the twenty-four books, and most of those
 * courses are **somebody else's** — FreeCodeCamp, Google, a Coursera track,
 * a local tutor. So this is not the Academy's own course catalogue
 * (lib/AcademyRepository.php). It is a curated list of external courses the
 * programme wants people to take, and a record of who took them.
 *
 * ── WHY IT IS NOT JUST A BOOK WITH A DIFFERENT WORD ─────────────────────────
 * The machinery is deliberately the same shape as lib/NgvReading.php — a
 * curated list, a claim per course, sittings logged against it, a reflection
 * at the end, a human who checks it — because somebody who has learned one
 * should not have to learn the other.
 *
 * What differs is what a course IS:
 *
 *   a book has      chapters          a course has   modules
 *   a book has      an author         a course has   a provider and a link
 *   a book is       read              a course is    worked through, and
 *                                                    usually ends in a
 *                                                    certificate
 *
 * The certificate is attachable and is NOT what the claim rests on. A
 * certificate says a provider's system recorded a completion; the sittings
 * and the reflection say what the person did and what they made of it, and
 * that is what a checker reads. Resting the whole thing on an uploaded PDF
 * would make the record exactly as good as the easiest certificate on the
 * internet to obtain.
 *
 * ── THE PROGRESSION ─────────────────────────────────────────────────────────
 * The list is grouped, and the groups are an order, not a taxonomy:
 * Foundation first — everybody needs it — then the track groups. GROUPS is
 * that order; `ngv_courses.grp` holds which one a course is in.
 *
 * ── TARGETS ─────────────────────────────────────────────────────────────────
 *   a NextGen Vanguard   TARGET_NGV (12) a year, set by the office
 *   anybody else         NO target, and no clock. A member or a volunteer
 *                        logs what they do. They MAY set themselves a
 *                        number, and it is theirs — nothing chases them
 *                        about it and no screen calls them behind.
 *
 * That asymmetry is the point and not an oversight. A vanguard is on a
 * programme with a year and a shape; a member doing this alongside a job is
 * not, and a progress bar that says "3 of 12" to somebody who never agreed
 * to twelve is a reproach, not information.
 */
declare(strict_types=1);

final class NgvCourses
{
    /** The groups, in the order they are meant to be taken. */
    public const GROUPS = ['foundation' => 'Foundation', 'business' => 'Business',
                           'art' => 'Art', 'media' => 'Media', 'tech' => 'Tech'];

    /**
     * The one group every vanguard does. Everything else is a CHOICE.
     *
     * The progression is not a syllabus of eighteen compulsory courses. The
     * foundational concepts — communication, the workspace tools, working
     * with AI — are what the programme asks of everybody; Business, Art,
     * Media and Tech are a menu somebody picks from with their track in
     * mind. Drawing the whole list as if it were required would tell a
     * vanguard on the Art track that they are fourteen courses behind, which
     * is not true and is the kind of thing that makes somebody stop.
     *
     * A member or a volunteer is asked for none of it: they have no target
     * unless they set one (see targetFor).
     */
    public const REQUIRED_GROUP = 'foundation';

    /** What a vanguard is asked for in a year. The office may change it. */
    public const TARGET_NGV = 12;
    public const TARGET_MAX = 200;
    public const SETTINGS_KEY = 'ngv_course_settings';
    /** Set once the brainstorm shorthand has been corrected — see fixSeedNames(). */
    public const NAMES_FIXED_KEY = 'ngv_course_names_fixed';

    public const STATUSES = ['draft', 'submitted', 'verified', 'resubmit', 'rejected'];
    /** What somebody writes about the whole course, and about each sitting. */
    public const MIN_REFLECTION = 320;
    public const MIN_TAKEAWAY = 60;
    public const MIN_MODULE = 60;
    public const MAX_MODULES = 60;
    public const MAX_SESSION_MINUTES = 600;
    public const MIN_SESSION_NOTES = 120;
    public const MIN_SESSION_STANCE = 80;
    public const MIN_SESSION_APPLICATION = 60;
    private const MAX_TEXT = 6000;

    /**
     * The list a fresh deployment starts with — the programme's own
     * progression, as the office gave it. A starting point: the office
     * renames, re-links, adds and retires from the book-list screen.
     */
    public const SEED = [
        /* Foundation — asked of every vanguard. */
        ['grp' => 'foundation', 'title' => 'Communication Skills',             'provider' => 'At the centre',
         'note' => 'Speaking, writing and listening well enough to be understood and to understand.'],
        ['grp' => 'foundation', 'title' => 'Google Workspace Essentials',      'provider' => 'Google',
         'note' => 'Docs, Sheets, Drive, Gmail and Calendar — the tools the work is done in.'],
        ['grp' => 'foundation', 'title' => 'Working with AI and Automation',   'provider' => 'At the centre',
         'note' => 'Using AI tools and simple automation honestly, and knowing where they fail.'],

        /* Business */
        ['grp' => 'business',   'title' => 'Microsoft Excel',                  'provider' => 'Microsoft Learn',
         'note' => 'Formulas, tables and charts, to the point of doing real work in a spreadsheet.'],
        ['grp' => 'business',   'title' => 'Data Analysis Foundations',        'provider' => 'At the centre',
         'note' => 'Reading a dataset, asking a question of it, and saying what the answer does and does not show.'],
        ['grp' => 'business',   'title' => 'Reporting with Power BI',          'provider' => 'Microsoft Learn',
         'note' => 'Turning figures into a report somebody can act on.'],
        ['grp' => 'business',   'title' => 'Project Management Essentials',    'provider' => 'At the centre',
         'note' => 'Scope, schedule, people and risk, on projects the size we actually run.'],

        /* Art */
        ['grp' => 'art',        'title' => 'Event Planning and Management',    'provider' => 'At the centre',
         'note' => 'Running an event end to end — the plan, the day itself, and what is owed afterwards.'],
        ['grp' => 'art',        'title' => 'Music Theory Foundations',         'provider' => 'At the centre',
         'note' => 'Notation, rhythm, key and harmony — enough to read and to write music down.'],
        ['grp' => 'art',        'title' => 'Acting and Stagecraft',            'provider' => 'At the centre',
         'note' => 'Performance, voice and movement, and what happens around a stage.'],
        ['grp' => 'art',        'title' => 'Learning an Instrument',           'provider' => 'At the centre',
         'note' => 'One instrument, taken far enough to play a piece through.'],

        /* Media */
        ['grp' => 'media',      'title' => 'Graphic Design with Canva',        'provider' => 'Canva Design School',
         'note' => 'Layout, type and templates, to a standard the programme can publish.'],
        ['grp' => 'media',      'title' => 'Colour Theory for Design',         'provider' => 'At the centre',
         'note' => 'How colour carries meaning, and how to choose a palette that holds together.'],
        ['grp' => 'media',      'title' => 'Product Design Foundations',       'provider' => 'At the centre',
         'note' => 'Designing something for the person who has to use it, and testing whether it works.'],

        /* Tech — freeCodeCamp is the PROVIDER of these, not a course. */
        ['grp' => 'tech',       'title' => 'Web Pages with HTML',              'provider' => 'freeCodeCamp',
         'note' => 'The structure of a page, written by hand and written correctly.'],
        ['grp' => 'tech',       'title' => 'Styling and Layout with CSS',      'provider' => 'freeCodeCamp',
         'note' => 'Type, colour and layout, including how a page behaves on a phone.'],
        ['grp' => 'tech',       'title' => 'JavaScript Programming',           'provider' => 'freeCodeCamp',
         'note' => 'Programming from first principles, and making a page do something.'],
        ['grp' => 'tech',       'title' => 'Python Programming',               'provider' => 'freeCodeCamp',
         'note' => 'Programming from first principles, through to working with data and files.'],
    ];

    /**
     * The names the first version of this list shipped with, and what each
     * should have said.
     *
     * Those came out of a brainstorm — shorthand for a conversation, never
     * meant to be read by anybody. They went in verbatim and went on screen,
     * where "Designs Colour" and a course called "FreeCodeCamp" (which is a
     * provider, not a course) were the programme's published curriculum.
     *
     * A list already seeded cannot be reseeded — seed() only fills an empty
     * table — so the correction has to find those rows and rename them. It
     * runs ONCE and touches only a row that still carries the shorthand
     * exactly AND that nobody has edited since it was created: an office that
     * has already named a course keeps its name, which is the whole point of
     * the list being theirs.
     */
    private const SEED_FIXES = [
        'Communication'              => 'Communication Skills',
        'Google Workspace'           => 'Google Workspace Essentials',
        'AI and Automation'          => 'Working with AI and Automation',
        'Excel'                      => 'Microsoft Excel',
        'Data Analysis'              => 'Data Analysis Foundations',
        'Power BI'                   => 'Reporting with Power BI',
        'Project Management'         => 'Project Management Essentials',
        'Event Management'           => 'Event Planning and Management',
        'Music Theory'               => 'Music Theory Foundations',
        'Acting / Music Instrument'  => 'Acting and Stagecraft',
        'Canva'                      => 'Graphic Design with Canva',
        'Designs Colour'             => 'Colour Theory for Design',
        'Product Design'             => 'Product Design Foundations',
        'HTML'                       => 'Web Pages with HTML',
        'CSS'                        => 'Styling and Layout with CSS',
        'JavaScript'                 => 'JavaScript Programming',
        'Python'                     => 'Python Programming',
    ];

    /** The shorthand row that was never a course at all. */
    private const SEED_RETIRE = ['FreeCodeCamp'];

    /** What the correction adds, because the shorthand held two in one line. */
    private const SEED_ADD = [
        ['grp' => 'art', 'title' => 'Learning an Instrument', 'provider' => 'At the centre',
         'note' => 'One instrument, taken far enough to play a piece through.', 'after' => 'Acting and Stagecraft'],
    ];

    private static bool $ready = false;

    /* ══ Schema ═══════════════════════════════════════════════════════════ */

    /** No semicolon inside any comment here — execSchema splits on ';'. */
    private static function ddl(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS ngv_courses (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  title       VARCHAR(200) NOT NULL DEFAULT '',
  provider    VARCHAR(120) NOT NULL DEFAULT '',
  url         VARCHAR(300) NOT NULL DEFAULT '',
  grp         VARCHAR(20) NOT NULL DEFAULT 'foundation',
  modules     INTEGER NOT NULL DEFAULT 0,
  hours       INTEGER NOT NULL DEFAULT 0,
  note        VARCHAR(300) NOT NULL DEFAULT '',
  cover_url   VARCHAR(300) NOT NULL DEFAULT '',
  position    INTEGER NOT NULL DEFAULT 0,
  active      INTEGER NOT NULL DEFAULT 1,
  created_by  INTEGER NOT NULL DEFAULT 0,
  created_at  VARCHAR(32) NOT NULL DEFAULT '',
  updated_at  VARCHAR(32) NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_courses_active ON ngv_courses (active, grp, position);
CREATE TABLE IF NOT EXISTS ngv_course_claims (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  member_id     INTEGER NOT NULL DEFAULT 0,
  course_id     INTEGER NOT NULL DEFAULT 0,
  title         VARCHAR(200) NOT NULL DEFAULT '',
  provider      VARCHAR(120) NOT NULL DEFAULT '',
  modules       INTEGER NOT NULL DEFAULT 0,
  module_notes  TEXT,
  started_on    VARCHAR(10) NOT NULL DEFAULT '',
  finished_on   VARCHAR(10) NOT NULL DEFAULT '',
  reflection    TEXT,
  takeaway      TEXT,
  certificate_url VARCHAR(300) NOT NULL DEFAULT '',
  status        VARCHAR(16) NOT NULL DEFAULT 'draft',
  reviewed_by   INTEGER NOT NULL DEFAULT 0,
  reviewed_at   VARCHAR(32) NOT NULL DEFAULT '',
  review_note   VARCHAR(500) NOT NULL DEFAULT '',
  created_at    VARCHAR(32) NOT NULL DEFAULT '',
  updated_at    VARCHAR(32) NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ccl_one ON ngv_course_claims (member_id, course_id);
CREATE INDEX IF NOT EXISTS idx_ccl_status ON ngv_course_claims (status, id);
CREATE TABLE IF NOT EXISTS ngv_course_sessions (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  claim_id    INTEGER NOT NULL DEFAULT 0,
  member_id   INTEGER NOT NULL DEFAULT 0,
  course_id   INTEGER NOT NULL DEFAULT 0,
  day         VARCHAR(10) NOT NULL DEFAULT '',
  minutes     INTEGER NOT NULL DEFAULT 0,
  module_from INTEGER NOT NULL DEFAULT 0,
  module_to   INTEGER NOT NULL DEFAULT 0,
  place       VARCHAR(80) NOT NULL DEFAULT '',
  mood        VARCHAR(20) NOT NULL DEFAULT '',
  notes       TEXT,
  stance      TEXT,
  application TEXT,
  created_at  VARCHAR(32) NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_csess_claim ON ngv_course_sessions (claim_id, day);
CREATE INDEX IF NOT EXISTS idx_csess_member ON ngv_course_sessions (member_id, day);
SQL;
    }

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            $pdo = NgvDb::pdo();
            Database::execSchema($pdo, self::ddl());
            Database::syncTablesFromDdl($pdo, self::ddl(), null, 'ngvcourses');
            self::seed();
            /* A list seeded before the names were written properly is
               corrected here, once — seed() cannot do it, because it only
               ever fills an empty table. */
            if (Database::metaGet(self::NAMES_FIXED_KEY) === null) {
                /* Marked AFTER it runs, so a correction that failed is tried
                   again rather than silently left undone. It is idempotent:
                   a row it has already renamed no longer matches. */
                self::fixSeedNames();
                Database::metaSet(self::NAMES_FIXED_KEY, self::now());
            }
        } catch (Throwable $e) { error_log('[ngvcourses] ensure: ' . $e->getMessage()); }
    }

    /** The programme's own progression, once, and only into an empty list. */
    private static function seed(): void
    {
        try {
            $pdo = NgvDb::pdo();
            if ((int) $pdo->query('SELECT COUNT(*) FROM ngv_courses')->fetchColumn() > 0) return;
            $now = self::now();
            $st = $pdo->prepare('INSERT INTO ngv_courses (title, provider, url, grp, modules, hours, note, position, active, created_by, created_at, updated_at)
                                 VALUES (?, ?, \'\', ?, 0, 0, ?, ?, 1, 0, ?, ?)');
            $i = 0;
            foreach (self::SEED as $c) {
                $st->execute([$c['title'], $c['provider'], $c['grp'], (string) ($c['note'] ?? ''), $i += 10, $now, $now]);
            }
        } catch (Throwable $e) { error_log('[ngvcourses] seed: ' . $e->getMessage()); }
    }

    /**
     * Put the brainstorm shorthand right on a list that was already seeded.
     *
     * Runs once (NGV_COURSE_NAMES_FIXED in the settings blob) and only on a
     * row that still carries the shorthand exactly and has not been edited
     * since it was created — `updated_at` still equal to `created_at`. An
     * office that has renamed a course keeps its name.
     *
     * What a member already CLAIMED is not rewritten: a verified course keeps
     * the title it was verified under, because that is the record. A draft
     * picks the new title up by itself, since save() re-reads it from the
     * course row every time.
     */
    public static function fixSeedNames(): array
    {
        self::ensure();
        $done = 0; $added = 0; $retired = 0; $skipped = [];
        try {
            $pdo = NgvDb::pdo();
            $now = self::now();
            /* created_by = 0 is OURS: seed() inserts with 0, saveCourse()
               with the admin's id. Without it, a course the office added
               under one of these names a minute ago looks "unedited" —
               created_at and updated_at are equal on any fresh row — and
               the correction renamed it under them. */
            $sel = $pdo->prepare('SELECT id, title, note, provider, created_at, updated_at FROM ngv_courses WHERE title = ? AND created_by = 0');
            $upd = $pdo->prepare('UPDATE ngv_courses SET title = ?, provider = ?, note = ?, position = ?, updated_at = ? WHERE id = ?');
            /* The new wording AND the new place in the order, by the new
               title, so the correction and the seed cannot say different
               things. Position matters because the order changed with the
               names: a row split in two, and one was never a course. */
            $byTitle = []; $pos = [];
            $i = 0;
            foreach (self::SEED as $c) { $byTitle[$c['title']] = $c; $pos[$c['title']] = $i += 10; }

            foreach (self::SEED_FIXES as $was => $now_title) {
                $sel->execute([$was]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$row) continue;
                if ((string) $row['updated_at'] !== (string) $row['created_at']) { $skipped[] = $was; continue; }
                $c = $byTitle[$now_title] ?? ['provider' => (string) $row['provider'], 'note' => ''];
                $upd->execute([$now_title, (string) ($c['provider'] ?? ''), (string) ($c['note'] ?? ''),
                               $pos[$now_title] ?? 900, $now, (int) $row['id']]);
                $done++;
            }

            /* "FreeCodeCamp" was a provider on a row of its own. Retired, not
               deleted: somebody may already have claimed it, and a claim must
               keep resolving. */
            foreach (self::SEED_RETIRE as $gone) {
                $sel->execute([$gone]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$row || (string) $row['updated_at'] !== (string) $row['created_at']) continue;
                $pdo->prepare('UPDATE ngv_courses SET active = 0, updated_at = ? WHERE id = ?')
                    ->execute([$now, (int) $row['id']]);
                $retired++;
            }

            /* "Acting / Music Instrument" was two courses in one line. */
            foreach (self::SEED_ADD as $add) {
                $sel->execute([$add['title']]);
                if ($sel->fetch(PDO::FETCH_ASSOC)) continue;
                /* Only when the course it was split from is here — on a
                   list that never had the shorthand there is nothing to
                   split, and adding it would be inventing a course. */
                $after = $pdo->prepare('SELECT 1 FROM ngv_courses WHERE title = ?');
                $after->execute([(string) $add['after']]);
                if (!$after->fetchColumn()) continue;
                $pdo->prepare('INSERT INTO ngv_courses (title, provider, url, grp, modules, hours, note, position, active, created_by, created_at, updated_at)
                               VALUES (?, ?, \'\', ?, 0, 0, ?, ?, 1, 0, ?, ?)')
                    ->execute([$add['title'], $add['provider'], $add['grp'], $add['note'],
                               $pos[$add['title']] ?? 900, $now, $now]);
                $added++;
            }
        } catch (Throwable $e) {
            error_log('[ngvcourses] fixSeedNames: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => true, 'renamed' => $done, 'added' => $added, 'retired' => $retired, 'kept' => $skipped];
    }

    private static function now(): string { return function_exists('av_now_tz') ? av_now_tz('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s'); }
    private static function today(): string { return function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d'); }

    /* ══ Targets ══════════════════════════════════════════════════════════ */

    /** The office's figure for a vanguard's year. */
    public static function targetNgv(): int
    {
        try {
            $raw = Database::metaGet(self::SETTINGS_KEY);
            $s = is_string($raw) ? json_decode($raw, true) : null;
            $n = (int) ($s['target_ngv'] ?? 0);
            if ($n > 0 && $n <= self::TARGET_MAX) return $n;
        } catch (Throwable $e) {}
        return self::TARGET_NGV;
    }

    public static function setTargetNgv(int $n, string $by): array
    {
        if ($n < 1 || $n > self::TARGET_MAX) return ['ok' => false, 'error' => 'A year\'s target is between 1 and ' . self::TARGET_MAX . '.'];
        try { Database::metaSet(self::SETTINGS_KEY, (string) json_encode(['target_ngv' => $n, 'by' => $by, 'at' => self::now()])); }
        catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not save that.']; }
        return ['ok' => true, 'target' => $n];
    }

    /**
     * What THIS person is working towards.
     *
     * A vanguard: the programme's figure. Anybody else: nothing, unless they
     * have set themselves one — and theirs is a choice, so it is returned
     * with `own` true and no screen is entitled to call them behind on it.
     *
     * @return array{target:int, own:bool, fixed:bool}
     */
    public static function targetFor(int $memberId): array
    {
        $isNgv = false;
        try { $isNgv = class_exists('NgvMember') && NgvMember::isVanguard($memberId); } catch (Throwable $e) {}
        if ($isNgv) return ['target' => self::targetNgv(), 'own' => false, 'fixed' => true];
        $own = 0;
        try { $own = class_exists('Prefs') ? (int) Prefs::get($memberId, 'ngv_course_target', '0') : 0; } catch (Throwable $e) {}
        return ['target' => max(0, min(self::TARGET_MAX, $own)), 'own' => $own > 0, 'fixed' => false];
    }

    /** A member or volunteer setting their own. 0 clears it — the default is none. */
    public static function setOwnTarget(int $memberId, int $n): array
    {
        if ($memberId <= 0) return ['ok' => false, 'error' => 'No such member.'];
        try { if (class_exists('NgvMember') && NgvMember::isVanguard($memberId)) {
            return ['ok' => false, 'code' => 'ngv', 'error' => 'Your target is the programme\'s — ' . self::targetNgv() . ' courses this year.'];
        } } catch (Throwable $e) {}
        if ($n < 0 || $n > self::TARGET_MAX) return ['ok' => false, 'error' => 'Pick a number between 1 and ' . self::TARGET_MAX . ', or 0 for no target.'];
        try { Prefs::set($memberId, 'ngv_course_target', (string) $n); }
        catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not save that.']; }
        return ['ok' => true, 'target' => $n, 'said' => $n > 0 ? 'Your target is ' . $n . ' course' . ($n === 1 ? '' : 's') . '.' : 'Target cleared — log what you do.'];
    }

    /* ══ The list ═════════════════════════════════════════════════════════ */

    public static function courses(bool $activeOnly = true): array
    {
        self::ensure();
        try {
            $sql = 'SELECT * FROM ngv_courses' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY position, title';
            return array_map([self::class, 'shapeCourse'], NgvDb::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { error_log('[ngvcourses] courses: ' . $e->getMessage()); return []; }
    }

    /** The list as the progression it is: group key => [label, courses]. */
    public static function byGroup(bool $activeOnly = true): array
    {
        $out = [];
        foreach (self::GROUPS as $k => $label) {
            $out[$k] = ['label' => $label, 'required' => $k === self::REQUIRED_GROUP, 'courses' => []];
        }
        foreach (self::courses($activeOnly) as $c) {
            $k = isset(self::GROUPS[$c['grp']]) ? $c['grp'] : 'foundation';
            $out[$k]['courses'][] = $c;
        }
        return $out;
    }

    public static function course(int $id): ?array
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_courses WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? self::shapeCourse($r) : null;
        } catch (Throwable $e) { return null; }
    }

    private static function shapeCourse(array $c): array
    {
        foreach (['id', 'modules', 'hours', 'position', 'active', 'created_by'] as $k) $c[$k] = (int) ($c[$k] ?? 0);
        $c['grp'] = isset(self::GROUPS[(string) ($c['grp'] ?? '')]) ? (string) $c['grp'] : 'foundation';
        $c['group_label'] = self::GROUPS[$c['grp']];
        $c['required'] = $c['grp'] === self::REQUIRED_GROUP;
        $c['cover_url'] = (string) ($c['cover_url'] ?? '');
        return $c;
    }

    /** Add a course, or change one. */
    public static function saveCourse(array $in, int $by): array
    {
        self::ensure();
        $id       = (int) ($in['id'] ?? 0);
        $title    = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in['title'] ?? '')) ?? ''), 0, 200);
        $provider = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in['provider'] ?? '')) ?? ''), 0, 120);
        $url      = mb_substr(trim((string) ($in['url'] ?? '')), 0, 300);
        $grp      = isset(self::GROUPS[(string) ($in['grp'] ?? '')]) ? (string) $in['grp'] : 'foundation';
        $modules  = max(0, min(self::MAX_MODULES, (int) ($in['modules'] ?? 0)));
        $hours    = max(0, min(2000, (int) ($in['hours'] ?? 0)));
        $note     = mb_substr(trim((string) ($in['note'] ?? '')), 0, 300);
        if ($title === '') return ['ok' => false, 'error' => 'Give the course its name.'];
        /* Most of these are external, so the link is how somebody finds it.
           It is not required — a course taught at the centre has none — but a
           link that is there must be a real one. */
        if ($url !== '' && !preg_match('~^https://~i', $url)) {
            return ['ok' => false, 'error' => 'A course link must start with https://.'];
        }
        $pdo = NgvDb::pdo();
        try {
            $st = $pdo->prepare('SELECT id FROM ngv_courses WHERE LOWER(title) = ? AND LOWER(provider) = ? AND id <> ?');
            $st->execute([mb_strtolower($title), mb_strtolower($provider), $id]);
            if ($st->fetchColumn()) return ['ok' => false, 'code' => 'exists', 'error' => 'That course is already on the list.'];
            $now = self::now();
            if ($id > 0) {
                $pdo->prepare('UPDATE ngv_courses SET title = ?, provider = ?, url = ?, grp = ?, modules = ?, hours = ?, note = ?, updated_at = ? WHERE id = ?')
                    ->execute([$title, $provider, $url, $grp, $modules, $hours, $note, $now, $id]);
            } else {
                $pos = (int) ($pdo->query('SELECT COALESCE(MAX(position), 0) + 10 FROM ngv_courses')->fetchColumn() ?: 10);
                $pdo->prepare('INSERT INTO ngv_courses (title, provider, url, grp, modules, hours, note, position, active, created_by, created_at, updated_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)')
                    ->execute([$title, $provider, $url, $grp, $modules, $hours, $note, $pos, $by, $now, $now]);
                $id = (int) $pdo->lastInsertId();
            }
        } catch (Throwable $e) {
            error_log('[ngvcourses] saveCourse: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save that.'];
        }
        self::audit($id > 0 ? 'ngv_course_save' : 'ngv_course_add', $id, $title, $by);
        return ['ok' => true, 'course' => self::course($id)];
    }

    /** Retire a course, or put it back. A course in use is never deleted. */
    public static function setCourseActive(int $id, bool $on): array
    {
        self::ensure();
        if (!self::course($id)) return ['ok' => false, 'error' => 'No such course.'];
        try {
            NgvDb::pdo()->prepare('UPDATE ngv_courses SET active = ?, updated_at = ? WHERE id = ?')
                ->execute([$on ? 1 : 0, self::now(), $id]);
        } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not change that.']; }
        return ['ok' => true, 'active' => $on];
    }

    /** A cover for a course, exactly as a book's (lib/NgvReading::setBookCover). */
    public static function setCourseCover(int $id, ?array $file, int $by, bool $remove = false): array
    {
        self::ensure();
        $c = self::course($id);
        if (!$c) return ['ok' => false, 'error' => 'No such course.'];
        if ($remove) {
            try { NgvDb::pdo()->prepare('UPDATE ngv_courses SET cover_url = ?, updated_at = ? WHERE id = ?')->execute(['', self::now(), $id]); }
            catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not remove the cover.']; }
            return ['ok' => true, 'cover_url' => '', 'said' => 'Cover removed.'];
        }
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return ['ok' => false, 'error' => 'No image arrived. Choose a file and try again.'];
        }
        if ((int) ($file['size'] ?? 0) > NgvReading::COVER_MAX_BYTES) {
            return ['ok' => false, 'error' => 'That image is larger than ' . (int) (NgvReading::COVER_MAX_BYTES / 1048576) . 'MB.'];
        }
        $tmp = (string) $file['tmp_name'];
        $mime = class_exists('Storage') ? Storage::mime($tmp) : '';
        if (!in_array($mime, NgvReading::COVER_TYPES, true)) {
            return ['ok' => false, 'error' => 'A cover must be a JPEG, PNG, WebP or GIF image.'];
        }
        try {
            $put = Storage::put($tmp, 'course-' . $id . '-cover', 'image', 'courses');
            $url = (string) ($put['url'] ?? '');
        } catch (Throwable $e) {
            error_log('[ngvcourses] cover: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The cover could not be stored: ' . $e->getMessage()];
        }
        if ($url === '' || mb_strlen($url) > 300) return ['ok' => false, 'error' => 'The cover could not be stored.'];
        try { NgvDb::pdo()->prepare('UPDATE ngv_courses SET cover_url = ?, updated_at = ? WHERE id = ?')->execute([$url, self::now(), $id]); }
        catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not record the cover.']; }
        self::audit('ngv_course_cover', $id, (string) $c['title'], $by);
        return ['ok' => true, 'cover_url' => $url, 'said' => 'Cover saved.'];
    }

    /** How many claims name a course — the list shows it before anybody retires one. */
    public static function courseUse(int $id): int
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare('SELECT COUNT(*) FROM ngv_course_claims WHERE course_id = ?');
            $st->execute([$id]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /* ══ Taking a course ══════════════════════════════════════════════════ */

    public static function claim(int $memberId, int $courseId): ?array
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare('SELECT c.*, k.cover_url, k.url AS course_url, k.grp
                                           FROM ngv_course_claims c LEFT JOIN ngv_courses k ON k.id = c.course_id
                                          WHERE c.member_id = ? AND c.course_id = ?');
            $st->execute([$memberId, $courseId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? self::shapeClaim($r) : null;
        } catch (Throwable $e) { return null; }
    }

    public static function claimById(int $id): ?array
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_course_claims WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? self::shapeClaim($r) : null;
        } catch (Throwable $e) { return null; }
    }

    /** Everything this person has taken or started, newest first. */
    public static function mine(int $memberId): array
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare('SELECT c.*, k.cover_url, k.url AS course_url, k.grp
                                           FROM ngv_course_claims c LEFT JOIN ngv_courses k ON k.id = c.course_id
                                          WHERE c.member_id = ? ORDER BY c.id DESC');
            $st->execute([$memberId]);
            return array_map([self::class, 'shapeClaim'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { return []; }
    }

    private static function shapeClaim(array $r): array
    {
        foreach (['id', 'member_id', 'course_id', 'modules', 'reviewed_by'] as $k) $r[$k] = (int) ($r[$k] ?? 0);
        $notes = json_decode((string) ($r['module_notes'] ?? '[]'), true);
        $r['module_notes_list'] = is_array($notes) ? array_values(array_map('strval', $notes)) : [];
        $r['cover_url'] = (string) ($r['cover_url'] ?? '');
        $r['course_url'] = (string) ($r['course_url'] ?? '');
        $r['sessions'] = self::sessions((int) $r['id']);
        $r['session_minutes'] = array_sum(array_map(static fn($s) => (int) $s['minutes'], $r['sessions']));
        return $r;
    }

    /**
     * Save a course claim, or send it to be checked.
     *
     * The floor for sending is the same promise reading makes: at least one
     * sitting logged, a reflection on the whole course, and one thing they
     * will do because of it. A certificate may be attached and is never
     * enough on its own — see the note at the top of this file.
     */
    public static function save(int $memberId, array $in, bool $submit): array
    {
        self::ensure();
        $courseId = (int) ($in['course_id'] ?? 0);
        $course = self::course($courseId);
        if (!$course) return ['ok' => false, 'error' => 'Choose a course from the list.'];
        $have = self::claim($memberId, $courseId);
        if ($have && in_array((string) $have['status'], ['submitted', 'verified', 'rejected'], true)) {
            return ['ok' => false, 'code' => 'closed', 'error' => 'This course is no longer yours to change.'];
        }
        $started  = self::day($in['started_on'] ?? '');
        $finished = self::day($in['finished_on'] ?? '');
        $reflection = mb_substr(trim((string) ($in['reflection'] ?? '')), 0, self::MAX_TEXT);
        $takeaway   = mb_substr(trim((string) ($in['takeaway'] ?? '')), 0, self::MAX_TEXT);
        if (mb_strlen((string) ($in['reflection'] ?? '')) > self::MAX_TEXT || mb_strlen((string) ($in['takeaway'] ?? '')) > self::MAX_TEXT) {
            return ['ok' => false, 'code' => 'too_long', 'error' => 'That is longer than ' . self::MAX_TEXT . ' characters. Shorten it rather than losing it.'];
        }
        $modules = max(0, min(self::MAX_MODULES, (int) ($in['modules'] ?? $course['modules'])));
        $raw = $in['module_notes'] ?? [];
        $moduleNotes = array_values(array_map(static fn($x) => mb_substr(trim((string) $x), 0, self::MAX_TEXT), is_array($raw) ? $raw : []));

        if ($submit) {
            if ($started === '') return ['ok' => false, 'error' => 'When did you start it?'];
            if ($finished === '') return ['ok' => false, 'error' => 'When did you finish it?'];
            if ($finished < $started) return ['ok' => false, 'error' => 'It cannot have finished before it started.'];
            if (mb_strlen($reflection) < self::MIN_REFLECTION) {
                return ['ok' => false, 'error' => 'Say what the course taught and what you made of it — at least ' . self::MIN_REFLECTION . ' characters.'];
            }
            if (mb_strlen($takeaway) < self::MIN_TAKEAWAY) {
                return ['ok' => false, 'error' => 'One thing you have done, or will do, because of it — at least ' . self::MIN_TAKEAWAY . ' characters.'];
            }
            foreach ($moduleNotes as $i => $n) {
                if ($n !== '' && mb_strlen($n) < self::MIN_MODULE) {
                    return ['ok' => false, 'error' => 'Module ' . ($i + 1) . ' needs at least ' . self::MIN_MODULE . ' characters, or leave it blank.'];
                }
            }
            if (!$have) return ['ok' => false, 'code' => 'no_sessions', 'error' => 'Save this as a draft first, log the time you put in, then send it.'];
            if (self::sessionCount((int) $have['id']) < 1) {
                return ['ok' => false, 'code' => 'no_sessions',
                        'error' => 'Log at least one sitting before you send this — when you worked on it, how far you got, and what you made of it.'];
            }
        }
        $status = $submit ? 'submitted' : 'draft';
        $now = self::now();
        try {
            $pdo = NgvDb::pdo();
            if ($have) {
                $pdo->prepare('UPDATE ngv_course_claims SET title = ?, provider = ?, modules = ?, module_notes = ?, started_on = ?, finished_on = ?,
                                      reflection = ?, takeaway = ?, status = ?, updated_at = ? WHERE id = ?')
                    ->execute([$course['title'], $course['provider'], $modules, json_encode($moduleNotes, JSON_UNESCAPED_UNICODE),
                               $started, $finished, $reflection, $takeaway, $status, $now, (int) $have['id']]);
                $id = (int) $have['id'];
            } else {
                $pdo->prepare('INSERT INTO ngv_course_claims (member_id, course_id, title, provider, modules, module_notes, started_on, finished_on,
                                      reflection, takeaway, status, created_at, updated_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$memberId, $courseId, $course['title'], $course['provider'], $modules,
                               json_encode($moduleNotes, JSON_UNESCAPED_UNICODE), $started, $finished, $reflection, $takeaway, $status, $now, $now]);
                $id = (int) $pdo->lastInsertId();
            }
        } catch (Throwable $e) {
            error_log('[ngvcourses] save: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save that.'];
        }
        return ['ok' => true, 'id' => $id, 'status' => $status, 'claim' => self::claim($memberId, $courseId)];
    }

    private static function day($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    /* ══ Sittings ═════════════════════════════════════════════════════════ */

    public static function sessions(int $claimId): array
    {
        if ($claimId <= 0) return [];
        try {
            $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_course_sessions WHERE claim_id = ? ORDER BY day DESC, id DESC');
            $st->execute([$claimId]);
            return array_map([self::class, 'shapeSession'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { return []; }
    }

    public static function sessionCount(int $claimId): int
    {
        try {
            $st = NgvDb::pdo()->prepare('SELECT COUNT(*) FROM ngv_course_sessions WHERE claim_id = ?');
            $st->execute([$claimId]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /** One person's course sittings, for their diary. */
    public static function sessionsFor(int $memberId, int $limit = 500): array
    {
        self::ensure();
        $limit = max(1, min(2000, $limit));
        try {
            $st = NgvDb::pdo()->prepare('SELECT s.*, c.title, c.provider, c.status AS claim_status
                                           FROM ngv_course_sessions s LEFT JOIN ngv_course_claims c ON c.id = s.claim_id
                                          WHERE s.member_id = ? ORDER BY s.day DESC, s.id DESC LIMIT ' . $limit);
            $st->execute([$memberId]);
            return array_map([self::class, 'shapeSession'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { return []; }
    }

    private static function shapeSession(array $r): array
    {
        foreach (['id', 'member_id', 'claim_id', 'course_id', 'minutes', 'module_from', 'module_to'] as $k) $r[$k] = (int) ($r[$k] ?? 0);
        $r['mood_label'] = (string) (NgvReading::MOODS[(string) ($r['mood'] ?? '')] ?? '');
        $r['covered'] = $r['module_from'] > 0
            ? ($r['module_from'] === $r['module_to'] ? 'Module ' . $r['module_from'] : 'Modules ' . $r['module_from'] . '–' . $r['module_to'])
            : '';
        return $r;
    }

    /** Log a sitting against a course the member has started. */
    public static function logSession(int $memberId, array $in): array
    {
        self::ensure();
        $courseId = (int) ($in['course_id'] ?? 0);
        $claim = self::claim($memberId, $courseId);
        /* LOGGING A SITTING IS STARTING THE COURSE — see the same note in
           NgvReading::logSession. The sheet offers "Log a sitting" as soon as
           it opens, so refusing one for want of a draft the member was never
           asked for threw away what they had just written. */
        if (!$claim) {
            if ($courseId <= 0 || !self::course($courseId)) {
                return ['ok' => false, 'error' => 'Choose a course from the list, then log your time against it.'];
            }
            $made = self::save($memberId, ['course_id' => $courseId,
                                           'started_on' => (string) ($in['started_on'] ?? '')], false);
            if (empty($made['ok'])) return $made;
            $claim = self::claim($memberId, $courseId);
            if (!$claim) return ['ok' => false, 'error' => 'Could not start that course.'];
        }
        if (in_array((string) $claim['status'], ['submitted', 'verified', 'rejected'], true)) {
            return ['ok' => false, 'code' => 'closed', 'error' => 'This course is no longer yours to change.'];
        }
        $id = (int) ($in['id'] ?? 0);
        $day = self::day($in['day'] ?? '');
        if ($day === '') return ['ok' => false, 'error' => 'Which day was this?'];
        if ($day > self::today()) return ['ok' => false, 'error' => 'That day has not come yet.'];
        if ((string) $claim['started_on'] !== '' && $day < (string) $claim['started_on']) {
            return ['ok' => false, 'error' => 'That is before the day you started the course.'];
        }
        $minutes = (int) ($in['minutes'] ?? 0);
        if ($minutes < 1 || $minutes > self::MAX_SESSION_MINUTES) {
            return ['ok' => false, 'error' => 'How long did you work on it? Between 1 and ' . self::MAX_SESSION_MINUTES . ' minutes.'];
        }
        $from = max(0, min(self::MAX_MODULES, (int) ($in['module_from'] ?? 0)));
        $to   = max(0, min(self::MAX_MODULES, (int) ($in['module_to'] ?? 0)));
        if ($from > 0 && $to > 0 && $to < $from) return ['ok' => false, 'error' => 'The last module cannot come before the first.'];
        if ($from > 0 && $to === 0) $to = $from;
        $place = mb_substr(trim((string) ($in['place'] ?? '')), 0, 80);
        $mood  = isset(NgvReading::MOODS[(string) ($in['mood'] ?? '')]) ? (string) $in['mood'] : '';
        $notes = mb_substr(trim((string) ($in['notes'] ?? '')), 0, self::MAX_TEXT);
        $stance = mb_substr(trim((string) ($in['stance'] ?? '')), 0, self::MAX_TEXT);
        $app   = mb_substr(trim((string) ($in['application'] ?? '')), 0, self::MAX_TEXT);
        if (mb_strlen($notes) < self::MIN_SESSION_NOTES) return ['ok' => false, 'error' => 'What did this sitting cover? At least ' . self::MIN_SESSION_NOTES . ' characters.'];
        if (mb_strlen($stance) < self::MIN_SESSION_STANCE) return ['ok' => false, 'error' => 'What do you agree with, or argue against, and why? At least ' . self::MIN_SESSION_STANCE . ' characters.'];
        if (mb_strlen($app) < self::MIN_SESSION_APPLICATION) return ['ok' => false, 'error' => 'What will you do differently? At least ' . self::MIN_SESSION_APPLICATION . ' characters.'];
        try {
            $pdo = NgvDb::pdo();
            if ($id > 0) {
                $own = $pdo->prepare('SELECT claim_id FROM ngv_course_sessions WHERE id = ? AND member_id = ?');
                $own->execute([$id, $memberId]);
                if ((int) ($own->fetchColumn() ?: 0) !== (int) $claim['id']) return ['ok' => false, 'error' => 'No such sitting.'];
                $pdo->prepare('UPDATE ngv_course_sessions SET day = ?, minutes = ?, module_from = ?, module_to = ?, place = ?, mood = ?, notes = ?, stance = ?, application = ? WHERE id = ?')
                    ->execute([$day, $minutes, $from, $to, $place, $mood, $notes, $stance, $app, $id]);
            } else {
                $pdo->prepare('INSERT INTO ngv_course_sessions (claim_id, member_id, course_id, day, minutes, module_from, module_to, place, mood, notes, stance, application, created_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([(int) $claim['id'], $memberId, $courseId, $day, $minutes, $from, $to, $place, $mood, $notes, $stance, $app, self::now()]);
            }
        } catch (Throwable $e) {
            error_log('[ngvcourses] logSession: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record that.'];
        }
        return ['ok' => true, 'sessions' => self::sessions((int) $claim['id'])];
    }

    public static function dropSession(int $memberId, int $id): array
    {
        self::ensure();
        try {
            $st = NgvDb::pdo()->prepare('SELECT * FROM ngv_course_sessions WHERE id = ? AND member_id = ?');
            $st->execute([$id, $memberId]);
            $s = $st->fetch(PDO::FETCH_ASSOC);
            if (!$s) return ['ok' => false, 'error' => 'No such sitting.'];
            $claim = self::claimById((int) $s['claim_id']);
            if ($claim && in_array((string) $claim['status'], ['submitted', 'verified', 'rejected'], true)) {
                return ['ok' => false, 'code' => 'closed', 'error' => 'This course is no longer yours to change.'];
            }
            NgvDb::pdo()->prepare('DELETE FROM ngv_course_sessions WHERE id = ?')->execute([$id]);
            return ['ok' => true, 'sessions' => self::sessions((int) $s['claim_id'])];
        } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not remove that.']; }
    }

    /* ══ Where somebody has got to ════════════════════════════════════════ */

    /**
     * @return array{verified:int, waiting:int, needs_work:int, drafts:int,
     *               target:int, own_target:bool, fixed:bool}
     */
    public static function progress(int $memberId): array
    {
        $v = 0; $w = 0; $back = 0; $draft = 0; $fd = 0;
        foreach (self::mine($memberId) as $c) {
            switch ((string) $c['status']) {
                case 'verified':
                    $v++;
                    if ((string) ($c['grp'] ?? '') === self::REQUIRED_GROUP) $fd++;
                    break;
                case 'submitted': $w++;     break;
                case 'resubmit':
                case 'rejected':  $back++;  break;
                case 'draft':     $draft++; break;
            }
        }
        /* The foundation is counted on its own because it is the only part
           that is asked for by name. The rest of the target is met with
           whatever somebody chooses from the track groups. */
        $ft = 0;
        foreach (self::courses() as $c) if ((string) $c['grp'] === self::REQUIRED_GROUP) $ft++;
        $t = self::targetFor($memberId);
        return ['verified' => $v, 'waiting' => $w, 'needs_work' => $back, 'drafts' => $draft,
                'foundation' => $fd, 'foundation_total' => $ft,
                'target' => $t['target'], 'own_target' => $t['own'], 'fixed' => $t['fixed']];
    }

    /**
     * Attach the completion certificate, or take it off.
     *
     * It is EVIDENCE BESIDE the claim, never the claim itself. A certificate
     * says a provider's system recorded a completion; the sittings and the
     * reflection say what the person did. Nothing here changes the status,
     * and a claim with a certificate and no sittings still cannot be sent.
     */
    public static function setCertificate(int $memberId, int $courseId, ?array $file, bool $remove = false): array
    {
        self::ensure();
        $claim = self::claim($memberId, $courseId);
        if (!$claim) return ['ok' => false, 'error' => 'Start the course first.'];
        if (in_array((string) $claim['status'], ['submitted', 'verified', 'rejected'], true)) {
            return ['ok' => false, 'code' => 'closed', 'error' => 'This course is no longer yours to change.'];
        }
        if ($remove) {
            try { NgvDb::pdo()->prepare('UPDATE ngv_course_claims SET certificate_url = ?, updated_at = ? WHERE id = ?')
                    ->execute(['', self::now(), (int) $claim['id']]); }
            catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not remove it.']; }
            return ['ok' => true, 'certificate_url' => ''];
        }
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return ['ok' => false, 'error' => 'No file arrived. Choose your certificate and try again.'];
        }
        if ((int) ($file['size'] ?? 0) > NgvReading::COVER_MAX_BYTES) {
            return ['ok' => false, 'error' => 'That file is larger than ' . (int) (NgvReading::COVER_MAX_BYTES / 1048576) . 'MB.'];
        }
        $tmp = (string) $file['tmp_name'];
        $mime = class_exists('Storage') ? Storage::mime($tmp) : '';
        $okTypes = array_merge(NgvReading::COVER_TYPES, ['application/pdf']);
        if (!in_array($mime, $okTypes, true)) {
            return ['ok' => false, 'error' => 'A certificate must be a PDF or an image.'];
        }
        try {
            $put = Storage::put($tmp, 'cert-' . $memberId . '-' . $courseId, $mime === 'application/pdf' ? 'document' : 'image', 'certificates');
            $url = (string) ($put['url'] ?? '');
        } catch (Throwable $e) {
            error_log('[ngvcourses] certificate: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'It could not be stored: ' . $e->getMessage()];
        }
        if ($url === '' || mb_strlen($url) > 300) return ['ok' => false, 'error' => 'It could not be stored.'];
        try { NgvDb::pdo()->prepare('UPDATE ngv_course_claims SET certificate_url = ?, updated_at = ? WHERE id = ?')
                ->execute([$url, self::now(), (int) $claim['id']]); }
        catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not record it.']; }
        return ['ok' => true, 'certificate_url' => $url, 'said' => 'Certificate attached. It sits beside your account of the work; it is not what the course rests on.'];
    }

    /* ══ Who may take courses, and the one handler both doors use ═════════ */

    /** The same people who may read may take courses: one learning record. */
    public static function mayTake(int $memberId): bool
    {
        return class_exists('NgvReading') && NgvReading::mayRead($memberId);
    }

    /**
     * Every course action in ONE place, so the NGV dashboard and the
     * portal's Reading page cannot drift into doing them differently.
     * Returns null when `course_action` is not one of ours.
     */
    public static function action(int $uid, array $in): ?array
    {
        $act = (string) ($in['course_action'] ?? '');
        if ($act === '') return null;
        if (!self::mayTake($uid)) {
            return ['ok' => false, 'code' => 'not_learning', 'status' => 403,
                    'error' => 'Courses are for NextGen Vanguards, members and volunteers. Ask the office if this looks wrong.'];
        }
        if ($act === 'save' || $act === 'submit') {
            $r = self::save($uid, is_array($in['course'] ?? null) ? $in['course'] : [], $act === 'submit');
            if (!empty($r['ok'])) $r['progress'] = self::progress($uid);
            return $r;
        }
        if ($act === 'session') {
            $r = self::logSession($uid, is_array($in['session'] ?? null) ? $in['session'] : []);
            if (!empty($r['ok'])) $r['progress'] = self::progress($uid);
            return $r;
        }
        if ($act === 'session_drop') return self::dropSession($uid, (int) ($in['id'] ?? 0));
        if ($act === 'target') {
            $r = self::setOwnTarget($uid, (int) ($in['target'] ?? 0));
            if (!empty($r['ok'])) $r['progress'] = self::progress($uid);
            return $r;
        }
        if ($act === 'get') {
            $courseId = (int) ($in['course_id'] ?? 0);
            return ['ok' => true, 'claim' => $courseId > 0 ? self::claim($uid, $courseId) : null,
                    'groups' => self::byGroup(), 'progress' => self::progress($uid),
                    'min' => self::MIN_REFLECTION, 'minTake' => self::MIN_TAKEAWAY, 'minModule' => self::MIN_MODULE,
                    'moods' => NgvReading::MOODS, 'today' => self::today(),
                    'minSit' => ['notes' => self::MIN_SESSION_NOTES, 'stance' => self::MIN_SESSION_STANCE,
                                 'application' => self::MIN_SESSION_APPLICATION, 'minutes' => self::MAX_SESSION_MINUTES]];
        }
        return null;
    }

    /* ══ Checking a course ════════════════════════════════════════════════ */

    /**
     * The queue, oldest first. Courses are checked by the same people who
     * check the reading (NgvReading::canReview) and on the same desk — one
     * grant, one screen, because "who may check learning" is one question.
     */
    public static function queue(int $limit = 50): array
    {
        self::ensure();
        $limit = max(1, min(200, $limit));
        try {
            $st = NgvDb::pdo()->query("SELECT c.*, k.cover_url, k.url AS course_url, k.grp, p.name AS member_name, p.email AS member_email
                                         FROM ngv_course_claims c
                                         LEFT JOIN ngv_courses k ON k.id = c.course_id
                                         LEFT JOIN ngv_participants p ON p.member_id = c.member_id
                                        WHERE c.status = 'submitted' ORDER BY c.updated_at, c.id LIMIT " . $limit);
            return NgvReading::nameRows(array_map([self::class, 'shapeClaim'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []));
        } catch (Throwable $e) { error_log('[ngvcourses] queue: ' . $e->getMessage()); return []; }
    }

    public static function queueCount(): int
    {
        self::ensure();
        try { return (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_course_claims WHERE status = 'submitted'")->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    }

    /** Courses somebody may reopen — the same door reading has. */
    public static function rejected(int $limit = 50): array
    {
        self::ensure();
        $limit = max(1, min(200, $limit));
        try {
            $st = NgvDb::pdo()->query("SELECT c.*, p.name AS member_name FROM ngv_course_claims c
                                         LEFT JOIN ngv_participants p ON p.member_id = c.member_id
                                        WHERE c.status = 'rejected' ORDER BY c.reviewed_at DESC, c.id DESC LIMIT " . $limit);
            return NgvReading::nameRows(array_map([self::class, 'shapeClaim'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []));
        } catch (Throwable $e) { return []; }
    }

    /**
     * Verify, send back, or refuse a course.
     *
     * Nobody signs off their own, exactly as on the reading and the ledger
     * — NgvReading::selfDealing() is the one sentence all three use.
     */
    public static function review(int $claimId, string $verdict, int $byUid, string $note = ''): array
    {
        self::ensure();
        if (!in_array($verdict, ['verified', 'resubmit', 'rejected'], true)) {
            return ['ok' => false, 'error' => 'Verify it, send it back, or refuse it.'];
        }
        $c = self::claimById($claimId);
        if (!$c) return ['ok' => false, 'error' => 'No such course record.'];
        if ($msg = NgvReading::selfDealing((int) $c['member_id'], $byUid, 'checks it')) {
            return ['ok' => false, 'code' => 'own_record', 'error' => $msg];
        }
        $note = mb_substr(trim($note), 0, 500);
        if ($verdict !== 'verified' && $note === '') {
            return ['ok' => false, 'error' => 'Say why, so they know what to put right.'];
        }
        try {
            NgvDb::pdo()->prepare('UPDATE ngv_course_claims SET status = ?, reviewed_by = ?, reviewed_at = ?, review_note = ?, updated_at = ? WHERE id = ?')
                ->execute([$verdict, $byUid, self::now(), $note, self::now(), $claimId]);
        } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not record that.']; }
        self::audit('ngv_course_' . $verdict, (int) $c['course_id'], (string) $c['title'] . ' · member ' . (int) $c['member_id'], $byUid);
        return ['ok' => true, 'status' => $verdict];
    }

    /** Put a refused course back in somebody's hands, with a reason. */
    public static function reopen(int $claimId, int $byUid, string $why): array
    {
        self::ensure();
        $c = self::claimById($claimId);
        if (!$c) return ['ok' => false, 'error' => 'No such course record.'];
        if ((string) $c['status'] !== 'rejected') return ['ok' => false, 'error' => 'Only a refused course is reopened.'];
        $why = mb_substr(trim($why), 0, 500);
        if ($why === '') return ['ok' => false, 'error' => 'Say why it is being reopened.'];
        try {
            NgvDb::pdo()->prepare("UPDATE ngv_course_claims SET status = 'resubmit', review_note = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ?")
                ->execute([$why, $byUid, self::now(), self::now(), $claimId]);
        } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not reopen that.']; }
        self::audit('ngv_course_reopen', (int) $c['course_id'], (string) $c['title'], $byUid);
        return ['ok' => true];
    }

    private static function audit(string $what, int $id, string $title, int $by): void
    {
        if (!class_exists('AdminAudit')) return;
        try { AdminAudit::log('ngv', $what, 'ngv:course:' . $id, $title, null, $by > 0 ? 'admin' : 'system'); } catch (Throwable $e) {}
    }
}
