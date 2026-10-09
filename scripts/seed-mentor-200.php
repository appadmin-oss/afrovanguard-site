<?php
/**
 * scripts/seed-mentor-200.php — 200 pairings for one mentor, with history.
 *
 * MP-04 asks for a roster that paints in under 300 ms at 200 pairings, and a
 * number like that is only worth anything measured against data shaped like
 * the real thing: a long tail of pairings with dozens of sessions each, some
 * with no goals, some nobody has met for two months, some with a session sitting
 * unlogged. Seeding 200 empty rows would prove nothing.
 *
 *   php scripts/seed-mentor-200.php                 # seed, then time the roster
 *   php scripts/seed-mentor-200.php --pairs=50      # a smaller set
 *   php scripts/seed-mentor-200.php --clean         # remove what this seeded
 *
 * Everything it creates is marked (mentees are seeded with @seed.invalid
 * addresses) so --clean can take it all away again and leave real data alone.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$opt = static function (string $name, $default = null) use ($argv) {
    foreach ($argv as $a) {
        if ($a === '--' . $name) return true;
        if (str_starts_with($a, '--' . $name . '=')) return substr($a, strlen($name) + 3);
    }
    return $default;
};

const SEED_DOMAIN = '@seed.invalid';
$db = Database::pdo();
MentorPortal::ensure();

/* ── clean ─────────────────────────────────────────────────────────────── */
if ($opt('clean')) {
    $ids = $db->query("SELECT id FROM lms_users WHERE email LIKE '%" . SEED_DOMAIN . "'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!$ids) { echo "Nothing seeded.\n"; exit; }
    $in = implode(',', array_map('intval', $ids));
    $pairs = $db->query("SELECT id FROM mentorships WHERE mentee_id IN ($in) OR mentor_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($pairs) {
        $pin = implode(',', array_map('intval', $pairs));
        foreach (['mentor_sessions', 'mentor_values', 'mentor_checkins', 'mentor_messages', 'mentor_goals'] as $t)
            $db->exec("DELETE FROM {$t} WHERE mentorship_id IN ($pin)");
        $db->exec("DELETE FROM mentorships WHERE id IN ($pin)");
    }
    $db->exec("DELETE FROM mentor_profiles WHERE user_id IN ($in)");
    $db->exec("DELETE FROM lms_users WHERE id IN ($in)");
    echo 'Removed ', count($ids), " seeded accounts and ", count($pairs), " pairings.\n";
    exit;
}

$pairs = max(1, (int) ($opt('pairs', 200) ?: 200));

/* ── the mentor ────────────────────────────────────────────────────────── */
$mentorEmail = 'seed.mentor' . SEED_DOMAIN;
$st = $db->prepare('SELECT id FROM lms_users WHERE email = ?');
$st->execute([$mentorEmail]);
$mentorId = (int) ($st->fetchColumn() ?: 0);
if (!$mentorId) {
    $db->prepare('INSERT INTO lms_users (name, email, password_hash, role, status, created_at) VALUES (?,?,?,?,?,?)')
       ->execute(['Adaeze Okafor', $mentorEmail, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), 'learner', 'active', gmdate('Y-m-d H:i:s')]);
    $mentorId = (int) $db->lastInsertId();
}
Mentorship::becomeMentor($mentorId, [
    'headline' => 'Chapter lead, Alimosho', 'focus' => 'Leadership, public speaking, study habits',
    'bio' => 'Seeded mentor for performance work.', 'capacity' => 50, 'accepting' => true,
]);
$db->prepare("UPDATE mentor_academy SET passed = 0 WHERE user_id = ? AND module_key = 'safeguarding'")->execute([$mentorId]);
$db->prepare('INSERT INTO mentor_academy (user_id, module_key, score, passed, completed_at) VALUES (?,?,?,?,?)')
   ->execute([$mentorId, 'safeguarding', 100, 1, gmdate('Y-m-d H:i:s')]);

/* ── cohorts, so "chapter" is a real join and not a constant ───────────── */
$cohorts = [];
foreach (['Alimosho', 'Ikorodu', 'Surulere', 'Agege'] as $name) {
    $st = $db->prepare('SELECT id FROM mentor_cohorts WHERE name = ?');
    $st->execute([$name]);
    $id = (int) ($st->fetchColumn() ?: 0);
    if (!$id) {
        $db->prepare("INSERT INTO mentor_cohorts (name, segment, programme, starts, ends, status, created_at) VALUES (?,?,?,?,?,?,?)")
           ->execute([$name, 'org', 'Vanguard Quest', gmdate('Y-m-d', time() - 400 * 86400), '', 'open', gmdate('Y-m-d H:i:s')]);
        $id = (int) $db->lastInsertId();
    }
    $cohorts[] = $id;
}

$first = ['Ada','Chidi','Tunde','Ngozi','Emeka','Bola','Ifeoma','Segun','Amara','Kunle','Zainab','Obi','Yetunde','Musa','Nneka','Femi','Halima','Uche','Dami','Rita'];
$last  = ['Okonkwo','Afolabi','Nwosu','Eze','Balogun','Adeyemi','Okeke','Ibrahim','Ogundipe','Chukwu','Lawal','Udo','Bello','Onyeka','Ajayi'];
$tracks = ['Leadership', 'Public speaking', 'Study habits', 'Enterprise', 'Creative', 'Civic'];

/* Goals, as a mentor actually writes them: one thing, how you will both know
   it happened, and a date — or no date, which is also real. A pairing gets one
   to three. */
$goalBank = [
    ['Lead one community project before the year ends', 'The project runs and she reports back to the chapter'],
    ['Speak at a chapter meeting without notes', 'He gets through it and we talk about it afterwards'],
    ['Hand in every assignment on the day it is due', 'A full term with nothing late'],
    ['Open a savings account and keep it for six months', 'The account is still open and has money in it'],
    ['Apply to three sixth forms, with the applications checked first', 'All three are in before the deadline'],
    ['Run the chapter stall at the anniversary', 'The stall happens and she tells me how it went'],
    ['Read one book a month and tell me about it', 'Six books, six conversations'],
    ['Ask for help once before the week it is due', 'He messages me early instead of the night before'],
];

mt_srand(20261009);
$now = time();
$made = 0; $sessions = 0;
$insUser = $db->prepare('INSERT INTO lms_users (name, email, password_hash, role, status, created_at) VALUES (?,?,?,?,?,?)');
$insPair = $db->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, message, created_at, updated_at, segment, cohort_id, programme, origin, goals, stage, track, started_at, ends_at, close_steps)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'')");
$insGoal = $db->prepare("INSERT INTO mentor_goals (mentorship_id, title, measure, due_on, status, sort_no, created_at, updated_at, closed_at)
                         VALUES (?,?,?,?,?,?,?,?,?)");
$insSess = $db->prepare("INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, notes, status, created_at, attendance, duration_min, session_type, outcome, attended_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)");
$hash = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);

$db->beginTransaction();
for ($i = 0; $i < $pairs; $i++) {
    $name = $first[$i % count($first)] . ' ' . $last[($i * 7) % count($last)];
    $email = 'seed.mentee' . $i . SEED_DOMAIN;
    $insUser->execute([$name, $email, $hash, 'learner', 'active', gmdate('Y-m-d H:i:s')]);
    $menteeId = (int) $db->lastInsertId();

    $ageDays  = 30 + ($i * 13) % 400;                       // 1–14 months in
    $began    = gmdate('Y-m-d H:i:s', $now - $ageDays * 86400);
    $hasGoals = $i % 7 !== 0;                               // ~14% with none
    $stage    = $hasGoals ? min(6, 3 + (int) ($ageDays / 110)) : (1 + $i % 2);
    $insPair->execute([$mentorId, $menteeId, 'active', '', $began, $began, 'org',
                       $cohorts[$i % count($cohorts)], 'Vanguard Quest', 'admin',
                       $hasGoals ? 'Lead one community project and speak at the chapter meeting by December.' : '',
                       $stage, $tracks[$i % count($tracks)], $began,
                       $stage >= 6 ? gmdate('Y-m-d H:i:s', $now + 20 * 86400) : '']);
    $pairId = (int) $db->lastInsertId();

    /* One to three goals per pairing, some met, some overdue, some with no
       date. mentorships.goals keeps the live titles so the roster filter and
       the mentee's own portal read the same thing the case file shows. */
    if ($hasGoals) {
        $live = [];
        $howMany = 1 + $i % 3;
        for ($g = 0; $g < $howMany; $g++) {
            [$title, $measure] = $goalBank[($i * 3 + $g) % count($goalBank)];
            $met   = ($i + $g) % 4 === 0;
            $aside = !$met && ($i + $g) % 11 === 0;
            $due   = ($i + $g) % 3 === 0 ? '' : gmdate('Y-m-d', $now + (($i * 17 + $g * 29) % 220 - 60) * 86400);
            $status = $met ? 'met' : ($aside ? 'dropped' : 'open');
            $closed = $status === 'open' ? '' : gmdate('Y-m-d H:i:s', $now - (($i + $g) % 40) * 86400);
            $insGoal->execute([$pairId, $title, $measure, $due, $status, $g, $began, $began, $closed]);
            if (!$aside) $live[] = $title;
        }
        $db->prepare('UPDATE mentorships SET goals = ? WHERE id = ?')
           ->execute([mb_substr(implode('; ', $live), 0, 2000), $pairId]);
    }

    // A session every fortnight since the pairing began, mostly attended.
    $gapDays = 14;
    $count = max(1, (int) floor($ageDays / $gapDays));
    // A quarter of pairings have gone quiet: their last weeks have nothing.
    $quiet = $i % 4 === 0 ? (int) ceil(28 / $gapDays) : 0;
    for ($k = 0; $k < $count - $quiet; $k++) {
        $at = gmdate('Y-m-d H:i:s', $now - ($ageDays - ($k + 1) * $gapDays) * 86400);
        $missed = ($i + $k) % 9 === 0;
        $insSess->execute([$pairId, 'Progress check-in', $at, 'Agenda from the goals', $missed ? 'missed' : 'attended',
                           $at, $missed ? 'missed' : 'attended', $missed ? 0 : [30, 45, 60, 90][($i + $k) % 4],
                           'checkin', $missed ? '' : 'Reviewed the goal and agreed one step.', $missed ? '' : $at]);
        $sessions++;
    }
    // One in six has a session that happened and has not been logged.
    if ($i % 6 === 0) {
        $at = gmdate('Y-m-d H:i:s', $now - 3 * 86400);
        $insSess->execute([$pairId, 'Progress check-in', $at, 'Agenda from the goals', 'scheduled', $at, 'scheduled', 60, 'checkin', '', '']);
        $sessions++;
    }
    // Half have the next one in the diary.
    if ($i % 2 === 0) {
        $at = gmdate('Y-m-d H:i:s', $now + (2 + $i % 12) * 86400);
        $insSess->execute([$pairId, 'Progress check-in', $at, 'Agenda from the goals', 'scheduled', $at, 'scheduled', 60, 'checkin', '', '']);
        $sessions++;
    }
    $made++;
}
$db->commit();
echo "Seeded {$made} pairings and {$sessions} sessions for mentor #{$mentorId} ({$mentorEmail}).\n\n";

/* ── timings and query counts (MP-04) ──────────────────────────────────── */
/* Counting statements rather than trusting the code to have no N+1: a
   correlated sub-select added to a SELECT list a year from now looks like one
   query in the source and is one per row at the database. */
final class CountingStatement extends PDOStatement
{
    public static int $n = 0;
    protected function __construct() {}
    #[\ReturnTypeWillChange]
    public function execute(?array $params = null): bool { self::$n++; return parent::execute($params); }
}
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class, []]);
$count = static function (callable $fn): int {
    CountingStatement::$n = 0; $fn(); return CountingStatement::$n;
};

$portal = new MentorPortal($mentorId);
$time = static function (string $label, callable $fn) {
    $t = microtime(true);
    $r = $fn();
    printf("  %-46s %6.1f ms\n", $label, (microtime(true) - $t) * 1000);
    return $r;
};
echo "Server time, warm (the page's own work, not the HTTP round trip):\n";
$time('roster · all · longest wait · page 1', fn() => $portal->roster('', 'all', 'wait', 1));
$time('roster · needs attention · page 1', fn() => $portal->roster('', 'attn', 'wait', 1));
$time('roster · search "ada" · page 1', fn() => $portal->roster('ada', 'all', 'wait', 1));
$time('roster · page 4 (100 rows rendered)', fn() => $portal->roster('', 'all', 'wait', 4));
$time('nav badges (one query)', fn() => $portal->navBadges());
$time('today', fn() => $portal->today());
$r = $portal->roster('', 'all', 'wait', 1);
echo "\n  rows on page 1: ", count($r['rows']), " of ", $r['total'], " · counts: ", json_encode($r['counts']), "\n";

echo "\nQueries per call (MP-04 allows 4 for a roster page):\n";
printf("  %-46s %d\n", 'roster · page 1', $count(fn() => $portal->roster('', 'all', 'wait', 1)));
printf("  %-46s %d\n", 'roster · filtered + searched', $count(fn() => $portal->roster('ada', 'attn', 'kept', 2)));
printf("  %-46s %d\n", 'nav badges', $count(fn() => $portal->navBadges()));
