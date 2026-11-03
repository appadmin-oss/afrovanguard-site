<?php
/**
 * tests/ngvcourses.test.php — courses count too (lib/NgvCourses.php).
 *
 *   • The office's list is a PROGRESSION, not a taxonomy: Foundation first,
 *     then the track groups, in that order.
 *   • A vanguard is asked for the programme's number. Everybody else has NO
 *     target and no clock — and may set one, which is theirs.
 *   • A course cannot be sent without a sitting behind it, the same floor
 *     reading has. A certificate is never what it rests on.
 *   • Nobody signs off their own, as on the reading and the ledger.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

/** The seed as declared, for the assertions that pin how it is WRITTEN. */
function self_seed_titles(): array { return NgvCourses::SEED; }

NgvCourses::ensure(); NgvReading::ensure(); MemberVerification::ensure();
$ncDb = NgvDb::pdo();
$ncWipe = static function () use ($ncDb) {
    $ncDb->exec("DELETE FROM ngv_course_sessions WHERE member_id BETWEEN 8800 AND 8899");
    $ncDb->exec("DELETE FROM ngv_course_claims WHERE member_id BETWEEN 8800 AND 8899");
    $ncDb->exec("DELETE FROM ngv_courses WHERE title LIKE 'NCTEST %'");
};
$ncWipe();

/* ── The list is a progression ───────────────────────────────────────── */
ck('courses: the groups are an order, Foundation first',
   array_keys(NgvCourses::GROUPS) === ['foundation', 'business', 'art', 'media', 'tech']);
$ncGroups = NgvCourses::byGroup();
ck('courses: every group is answered even when empty, so the screen never has a hole',
   count($ncGroups) === 5 && array_key_exists('courses', $ncGroups['tech']));
$ncTitles = array_column(NgvCourses::courses(), 'title');
ck('courses: the programme\'s own progression is seeded',
   in_array('Communication Skills', $ncTitles, true) && in_array('Reporting with Power BI', $ncTitles, true)
   && in_array('Web Pages with HTML', $ncTitles, true) && in_array('Acting and Stagecraft', $ncTitles, true));
ck('courses: …and each sits in the group it was given',
   in_array('Communication Skills', array_column($ncGroups['foundation']['courses'], 'title'), true)
   && in_array('Graphic Design with Canva', array_column($ncGroups['media']['courses'], 'title'), true)
   && in_array('Microsoft Excel', array_column($ncGroups['business']['courses'], 'title'), true));

/* ── The names are the programme's, not a brainstorm's ──────────────────
   The first version of this list shipped the shorthand the owner jotted
   down while thinking out loud — "Designs Colour", a slash inside a title,
   and a course called "FreeCodeCamp", which is a provider. It went on
   screen as the published curriculum. */
foreach (['Designs Colour', 'Acting / Music Instrument', 'FreeCodeCamp', 'Excel', 'HTML', 'CSS',
          'Canva', 'Power BI', 'Communication'] as $ncShorthand) {
    ck('courses: "' . $ncShorthand . '" is not what a member reads',
       !in_array($ncShorthand, array_column(NgvCourses::courses(), 'title'), true));
}
ck('courses: no title carries a slash, which is two courses written as one',
   !array_filter(NgvCourses::courses(), static fn($c) => str_contains((string) $c['title'], '/')));
ck('courses: every seeded course says what it covers',
   !array_filter(self_seed_titles(), static fn($t) => trim((string) ($t['note'] ?? '')) === ''));
ck('courses: a provider is named on every one, even the ones taught here',
   !array_filter(self_seed_titles(), static fn($t) => trim((string) ($t['provider'] ?? '')) === ''));
/* ── The correction, run against the state it was written for ──────────
   A fresh database is seeded with the NEW names, so asserting on one proves
   nothing about the installs that already carry the shorthand. This builds
   those rows exactly as seed() left them — created_by 0, created_at equal to
   updated_at — and runs the correction over them. */
$ncStamp = '2026-01-01 00:00:00';
$ncIns = NgvDb::pdo()->prepare("INSERT INTO ngv_courses (title, provider, url, grp, modules, hours, note, position, active, created_by, created_at, updated_at)
                                VALUES (?, '', '', ?, 0, 0, '', 900, 1, 0, ?, ?)");
/* A fresh database is already seeded with the CORRECTED names, so each
   row's new title goes first — otherwise the correction renames the planted
   row onto one that is already there and the list carries it twice. */
foreach ([['Designs Colour', 'media', 'Colour Theory for Design'],
          ['Acting / Music Instrument', 'art', 'Acting and Stagecraft'],
          ['FreeCodeCamp', 'tech', ''],
          ['Excel', 'business', 'Microsoft Excel'],
          ['HTML', 'tech', 'Web Pages with HTML'],
          ['Canva', 'media', 'Graphic Design with Canva']] as [$ncT, $ncG, $ncNew]) {
    foreach (array_filter([$ncT, $ncNew]) as $ncDel) {
        NgvDb::pdo()->prepare('DELETE FROM ngv_courses WHERE title = ?')->execute([$ncDel]);
    }
    $ncIns->execute([$ncT, $ncG, $ncStamp, $ncStamp]);
}
/* One of them the office has since edited. It must be left exactly as it is. */
NgvDb::pdo()->prepare("UPDATE ngv_courses SET updated_at = '2026-02-02 00:00:00' WHERE title = 'Canva'")->execute();
NgvDb::pdo()->prepare('DELETE FROM ngv_courses WHERE title = ?')->execute(['Learning an Instrument']);

$ncFix = NgvCourses::fixSeedNames();
$ncAfter = array_column(NgvCourses::courses(false), 'title');
ck('courses: the correction renames the shorthand on a list already seeded',
   !empty($ncFix['ok'])
   && !in_array('Designs Colour', $ncAfter, true) && in_array('Colour Theory for Design', $ncAfter, true)
   && !in_array('Excel', $ncAfter, true) && in_array('Microsoft Excel', $ncAfter, true)
   && !in_array('HTML', $ncAfter, true) && in_array('Web Pages with HTML', $ncAfter, true));
$ncExcel = array_values(array_filter(NgvCourses::courses(false), static fn($c) => (string) $c['title'] === 'Microsoft Excel'));
ck('courses: …and gives each the wording the seed would have given it',
   count($ncExcel) === 1 && (string) $ncExcel[0]['provider'] === 'Microsoft Learn'
   && trim((string) $ncExcel[0]['note']) !== '');
/* THE ROW THAT WAS A PROVIDER. Retired, never deleted: somebody may already
   have claimed it, and a claim has to keep resolving to a course. */
$ncGone = array_values(array_filter(NgvCourses::courses(false), static fn($c) => (string) $c['title'] === 'FreeCodeCamp'));
ck('courses: the shorthand row that was really a provider is retired, never deleted',
   count($ncGone) === 1 && (int) $ncGone[0]['active'] === 0
   && !in_array('FreeCodeCamp', array_column(NgvCourses::courses(), 'title'), true));
/* The order is the programme's, not wherever the old row happened to sit:
   the list changed shape with the names — one row split in two, and one was
   never a course. */
$ncOrder = array_column(NgvCourses::byGroup()['business']['courses'], 'title');
ck('courses: a corrected list reads in the order the programme teaches it',
   array_slice($ncOrder, 0, 2) === ['Microsoft Excel', 'Data Analysis Foundations']);
ck('courses: the two that shared one line become two, in the same group',
   in_array('Acting and Stagecraft', $ncAfter, true) && in_array('Learning an Instrument', $ncAfter, true)
   && count(array_filter(NgvCourses::byGroup()['art']['courses'],
        static fn($c) => in_array((string) $c['title'], ['Acting and Stagecraft', 'Learning an Instrument'], true))) === 2);
/* A course the OFFICE edited, or added under one of these names, is theirs.
   Every fresh row has created_at equal to updated_at, so "unedited" on its
   own would have renamed a course a minute after they typed it. */
ck('courses: a course the office has edited is left exactly as they left it',
   in_array('Canva', $ncAfter, true) && in_array('Canva', ($ncFix['kept'] ?? []), true));
$ncOwn = NgvCourses::saveCourse(['title' => 'Designs Colour', 'grp' => 'media'], 7);
ck('courses: and one the office added itself is never renamed under them',
   !empty($ncOwn['ok']) && !empty(NgvCourses::fixSeedNames()['ok'])
   && (string) NgvCourses::course((int) $ncOwn['course']['id'])['title'] === 'Designs Colour');
NgvDb::pdo()->prepare('DELETE FROM ngv_courses WHERE id = ?')->execute([(int) ($ncOwn['course']['id'] ?? 0)]);
NgvDb::pdo()->prepare('DELETE FROM ngv_courses WHERE title = ?')->execute(['Canva']);

ck('courses: the correction runs once, and is safe to run again',
   !empty(NgvCourses::fixSeedNames()['ok'])
   && count(array_filter(NgvCourses::courses(), static fn($c) => (string) $c['title'] === 'Learning an Instrument')) === 1);

/* ── Required vs chosen: the part the user corrected ─────────────────── */
ck('courses: only the Foundation group is asked of everybody',
   NgvCourses::REQUIRED_GROUP === 'foundation'
   && $ncGroups['foundation']['required'] === true
   && $ncGroups['business']['required'] === false && $ncGroups['art']['required'] === false
   && $ncGroups['media']['required'] === false && $ncGroups['tech']['required'] === false);
ck('courses: …and a course says so on its own row, so no screen has to infer it',
   (NgvCourses::byGroup()['foundation']['courses'][0]['required'] ?? null) === true
   && (NgvCourses::byGroup()['tech']['courses'][0]['required'] ?? null) === false);

/* ── Adding one ──────────────────────────────────────────────────────── */
$ncAdd = NgvCourses::saveCourse(['title' => 'NCTEST Responsive Design', 'provider' => 'freeCodeCamp',
    'url' => 'https://www.freecodecamp.org/learn', 'grp' => 'tech', 'modules' => 5, 'hours' => 30], 1);
ck('courses: the office can add a course, with its provider and link', !empty($ncAdd['ok']));
$ncId = (int) ($ncAdd['course']['id'] ?? 0);
ck('courses: a link that is not https is refused — most of these are external',
   !NgvCourses::saveCourse(['title' => 'NCTEST Bad Link', 'url' => 'javascript:alert(1)'], 1)['ok']
   && !NgvCourses::saveCourse(['title' => 'NCTEST Bad Link 2', 'url' => 'http://insecure.test'], 1)['ok']);
ck('courses: a course taught at the centre needs no link',
   !empty(NgvCourses::saveCourse(['title' => 'NCTEST At The Centre', 'grp' => 'art'], 1)['ok']));
ck('courses: the same course twice is refused',
   (NgvCourses::saveCourse(['title' => 'NCTEST Responsive Design', 'provider' => 'freeCodeCamp'], 1)['code'] ?? '') === 'exists');
ck('courses: a course with no name is refused', !NgvCourses::saveCourse(['title' => '  '], 1)['ok']);

/* ── Targets: the asymmetry is the point ─────────────────────────────── */
$ncVan = 8801; $ncMem = 8802;
NgvDb::pdo()->prepare("INSERT INTO ngv_participants (member_id, name, email, status, start_date, books) VALUES (?,?,?,?,?,?)")
    ->execute([$ncVan, 'NC Vanguard', 'nc.van@nc.test', 'active', '2026-01-01', str_repeat('0', 24)]);
ck('courses: a vanguard is asked for the programme\'s number, and cannot move it',
   NgvCourses::targetFor($ncVan) === ['target' => NgvCourses::targetNgv(), 'own' => false, 'fixed' => true]
   && (NgvCourses::setOwnTarget($ncVan, 3)['code'] ?? '') === 'ngv');
ck('courses: …and the office can change the programme\'s number',
   !empty(NgvCourses::setTargetNgv(14, 'test')['ok']) && NgvCourses::targetNgv() === 14
   && !NgvCourses::setTargetNgv(0, 'test')['ok'] && !NgvCourses::setTargetNgv(9999, 'test')['ok']);
NgvCourses::setTargetNgv(12, 'test');
ck('courses: a member has NO target and no clock — they log what they do',
   NgvCourses::targetFor($ncMem) === ['target' => 0, 'own' => false, 'fixed' => false]);
ck('courses: …they may set one, and it is marked as THEIRS',
   !empty(NgvCourses::setOwnTarget($ncMem, 4)['ok'])
   && NgvCourses::targetFor($ncMem) === ['target' => 4, 'own' => true, 'fixed' => false]);
ck('courses: …and may clear it again, which is the default',
   !empty(NgvCourses::setOwnTarget($ncMem, 0)['ok']) && NgvCourses::targetFor($ncMem)['own'] === false);
ck('courses: an absurd self-target is refused', !NgvCourses::setOwnTarget($ncMem, 5000)['ok']);

/* ── Taking one: a sitting is the floor, not a certificate ───────────── */
$ncClaim = ['course_id' => $ncId, 'started_on' => '2026-03-01', 'finished_on' => '2026-03-20',
    'reflection' => str_repeat('This course walked through responsive layout from first principles, and the part that stayed with me was how much of it is deciding what may wrap. ', 3),
    'takeaway' => 'I rebuilt the centre\'s notice page so it reads properly on a phone, which it did not before.'];
ck('courses: a course can be saved as a draft', !empty(NgvCourses::save($ncVan, $ncClaim, false)['ok']));
$ncNo = NgvCourses::save($ncVan, $ncClaim, true);
ck('courses: …but not SENT with no sitting behind it, and it says so',
   empty($ncNo['ok']) && ($ncNo['code'] ?? '') === 'no_sessions');
$ncSit = ['course_id' => $ncId, 'day' => '2026-03-02', 'minutes' => 55, 'module_from' => 1, 'module_to' => 2,
    'place' => 'At the centre',
    'notes' => 'Worked through the first two modules on flexbox, and the exercises where the container has to decide rather than the child.',
    'stance' => 'I agree that most layout problems are really content problems, though the course underplays how often the content is out of your hands.',
    'application' => 'I am going to stop fixing widths on anything that holds text.'];
ck('courses: a sitting is logged against it', !empty(NgvCourses::logSession($ncVan, $ncSit)['ok']));
/* THE FIRST SITTING OPENS THE DRAFT. The sheet offers "Log a sitting" the
   moment it opens, so refusing one for want of a draft nobody asked for threw
   away what the person had just written. $ncMem has no claim on this course. */
$ncFirst = NgvCourses::logSession($ncMem, ['course_id' => $ncId, 'day' => '2026-03-05', 'minutes' => 40,
    'place' => 'At home',
    'notes' => 'Went through the first module cold, with no claim saved anywhere, which is exactly what somebody does the first time they sit down with a course.',
    'stance' => 'The pacing assumes you already know what a breakpoint is, which I did not, so the first half took twice as long as it says.',
    'application' => 'I will do the next module before Friday rather than leaving it.']);
ck('courses: the FIRST sitting starts the course by itself — nobody loses what they wrote',
   !empty($ncFirst['ok']) && NgvCourses::claim($ncMem, $ncId) !== null
   && (string) NgvCourses::claim($ncMem, $ncId)['status'] === 'draft');
ck('courses: …but a sitting against no course at all is still refused',
   !NgvCourses::logSession($ncMem, ['course_id' => 999999, 'day' => '2026-03-05', 'minutes' => 40,
       'notes' => str_repeat('x', 200), 'stance' => str_repeat('y', 120), 'application' => str_repeat('z', 80)])['ok']);
ck('courses: a sitting with nothing said about it is refused',
   !NgvCourses::logSession($ncVan, ['course_id' => $ncId, 'day' => '2026-03-03', 'minutes' => 30, 'notes' => 'short'])['ok']);
ck('courses: a sitting in the future is refused',
   !NgvCourses::logSession($ncVan, ['day' => gmdate('Y-m-d', time() + 86400)] + $ncSit)['ok']);
ck('courses: a sitting before the course began is refused',
   !NgvCourses::logSession($ncVan, ['day' => '2026-01-01'] + $ncSit)['ok']);
$ncSent = NgvCourses::save($ncVan, $ncClaim, true);
ck('courses: with a sitting behind it, it sends', !empty($ncSent['ok']) && $ncSent['status'] === 'submitted');
ck('courses: once sent, neither the course nor its sittings move',
   (NgvCourses::save($ncVan, $ncClaim, false)['code'] ?? '') === 'closed'
   && (NgvCourses::logSession($ncVan, $ncSit)['code'] ?? '') === 'closed');
ck('courses: a reflection shorter than the floor is refused, in words with the number in them',
   str_contains((string) NgvCourses::save($ncMem, ['course_id' => $ncId, 'started_on' => '2026-03-01',
       'finished_on' => '2026-03-02', 'reflection' => 'too short', 'takeaway' => 'x'], true)['error'],
       (string) NgvCourses::MIN_REFLECTION));

/* ── Checking it ─────────────────────────────────────────────────────── */
$ncQ = NgvCourses::queue();
ck('courses: it is in the queue, with the member\'s name on the row',
   NgvCourses::queueCount() >= 1 && count($ncQ) >= 1
   && array_key_exists('member_name', $ncQ[0]));
$ncCid = 0;
foreach ($ncQ as $q) if ((int) $q['member_id'] === $ncVan) $ncCid = (int) $q['id'];
ck('courses: nobody signs off their own',
   ($ncSelf = NgvCourses::review($ncCid, 'verified', $ncVan))
   && empty($ncSelf['ok']) && ($ncSelf['code'] ?? '') === 'own_record');
ck('courses: sending one back without a reason is refused',
   !NgvCourses::review($ncCid, 'resubmit', 7, '')['ok']);
ck('courses: a checker verifies it, and it counts',
   !empty(NgvCourses::review($ncCid, 'verified', 7)['ok'])
   && NgvCourses::progress($ncVan)['verified'] === 1);
$ncP = NgvCourses::progress($ncVan);
ck('courses: a vanguard\'s progress carries the programme\'s target',
   $ncP['target'] === NgvCourses::targetNgv() && $ncP['fixed'] === true && $ncP['own_target'] === false);
ck('courses: a member\'s progress carries no target at all',
   NgvCourses::progress($ncMem)['target'] === 0 && NgvCourses::progress($ncMem)['fixed'] === false);
/* The verified course above is in `tech`, so the foundation count must NOT
   move: a vanguard who has done three Tech courses has still done none of
   the three the programme actually asks for. */
ck('courses: the Foundation is counted on its own, and a Tech course does not fill it',
   $ncP['foundation'] === 0 && $ncP['foundation_total'] === count(array_filter(NgvCourses::courses(),
       static fn($c) => (string) $c['grp'] === NgvCourses::REQUIRED_GROUP)));

/* ── A name on every row of the desk ─────────────────────────────────────
   The queue joins ngv_participants, which answers for a vanguard and for
   nobody else — and courses are a member's and a volunteer's too. A desk
   that says "Member #412" is a desk nobody can act on. */
reset_users();   // Ada is member 1, and has no ngv_participants row
ck('courses: a reader with no participant row still gets their NAME on the desk',
   NgvReading::nameRows([['member_id' => 1, 'member_name' => '']])[0]['member_name'] === 'Ada');
ck('courses: …and a name the join already found is not overwritten',
   NgvReading::nameRows([['member_id' => 1, 'member_name' => 'From the join']])[0]['member_name'] === 'From the join');
ck('courses: …and a desk of forty rows is ONE query, never one per row',
   count(NgvReading::nameRows([['member_id' => 1, 'member_name' => ''], ['member_id' => 2, 'member_name' => ''],
                               ['member_id' => 3, 'member_name' => '']])) === 3
   && NgvReading::nameRows([['member_id' => 2, 'member_name' => '']])[0]['member_name'] === 'Bode'
   && substr_count((string) file_get_contents(dirname(__DIR__) . '/lib/NgvReading.php'),
                   'FROM lms_users WHERE id IN') === 1);

/* ── The diary reads the same rows ───────────────────────────────────── */
$ncFor = NgvCourses::sessionsFor($ncVan);
ck('courses: the sittings read back with the course on them',
   count($ncFor) === 1 && (string) $ncFor[0]['title'] === 'NCTEST Responsive Design'
   && (string) $ncFor[0]['covered'] === 'Modules 1–2');

/* ── The screens are wired to it ──────────────────────────────────────── */
$ncRoot = dirname(__DIR__);
$ncCard = (string) file_get_contents($ncRoot . '/portal/_courses-card.php');
$ncJs   = (string) file_get_contents($ncRoot . '/academy/ngv/courses-take.js');
$ncMod  = (string) file_get_contents($ncRoot . '/academy/ngv/_course-modal.php');
ck('courses: the card is drawn on BOTH screens somebody learns from, from one partial',
   str_contains((string) file_get_contents($ncRoot . '/portal/views/ngv.php'), "_courses-card.php")
   && str_contains((string) file_get_contents($ncRoot . '/portal/views/reading.php'), "_courses-card.php"));
ck('courses: the card draws the sheet once, and the sheet has a script to drive it',
   str_contains($ncCard, '_course-modal.php')
   && str_contains((string) file_get_contents($ncRoot . '/portal/index.php'), 'courses-take.js'));
ck('courses: the sheet is driven with the page\'s CSRF token, or every save says "Bad token"',
   str_contains($ncJs, 'window.NGV_CSRF')
   && str_contains($ncJs, "'/academy/ngv/dashboard.php'"));
ck('courses: the self-target form is drawn ONLY where it can act',
   str_contains($ncCard, "<?php if (!\$rcProg['fixed']): ?>") && str_contains($ncCard, 'data-course-target'));
ck('courses: the certificate is a file the server takes, not a field the page trusts',
   str_contains($ncJs, "fd.append('action', 'course_certificate')")
   && str_contains((string) file_get_contents($ncRoot . '/academy/ngv/dashboard.php'), "=== 'course_certificate'"));
ck('courses: the sheet says a certificate is not what the course rests on',
   stripos($ncMod, 'not what the course rests on') !== false);
ck('courses: the checker can act on them, on the desk that already checks the reading',
   str_contains((string) file_get_contents($ncRoot . '/academy/ngv/_reading-review.php'), "action:'course_review'")
   && str_contains((string) file_get_contents($ncRoot . '/academy/ngv/reading-review.php'), "\$act === 'course_review'")
   && str_contains((string) file_get_contents($ncRoot . '/academy/ngv/members.php'), "\$act === 'course_review'"));
ck('courses: the sittings reach the diary and the export, read from the one place that owns them',
   str_contains((string) file_get_contents($ncRoot . '/portal/notebooks.php'), 'NgvCourses::sessionsFor(')
   && str_contains((string) file_get_contents($ncRoot . '/diary/export.php'), 'NgvCourses::sessionsFor('));
ck('courses css: no hex colours on the card',
   !preg_match('/#[0-9a-fA-F]{3,8}\b/', (string) file_get_contents($ncRoot . '/portal/avng.css')));

/* ── Clean up ────────────────────────────────────────────────────────── */
$ncWipe();
NgvDb::pdo()->prepare('DELETE FROM ngv_participants WHERE member_id = ?')->execute([$ncVan]);
try { Prefs::set($ncMem, 'ngv_course_target', '0'); } catch (Throwable $e) {}
