<?php
/**
 * tests/audit-2026-11.test.php — the November audit of the Academy, the NGV
 * programme and the database layer. One case per finding, each pinned on the
 * behaviour, so a later change that reopens one fails here by name.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

$auRoot = dirname(__DIR__);
$auDb = Database::pdo();
$auAc = new AcademyRepository();
$auLms = new LmsRepository();

/* ══ 1. A restricted course's pass code is never public ═══════════════════ */
$auCourse = static fn(array $o = []): array => $o + [
    'title' => 'Audit Course', 'summary' => 's', 'body_html' => '<p>b</p>', 'category' => 'Programme', 'level' => 'All levels',
    'format' => 'Online', 'duration' => '1 week', 'price' => 'Free', 'location' => 'Online', 'gradient' => 'g-gold', 'outcomes' => '',
    'cta_url' => '', 'access_type' => 'open', 'price_ngn' => 0, 'pass_code' => '', 'featured' => false, 'status' => 'published', 'sort' => 0,
];
$auSlug = $auAc->save($auCourse(['title' => 'Audit Restricted', 'slug' => 'audit-restricted', 'access_type' => 'restricted', 'pass_code' => 'cohort-secret']));
$auPub = array_values(array_filter($auAc->all(), fn($c) => $c['slug'] === $auSlug))[0] ?? [];
ck('audit: the public catalogue does not carry a pass code', $auPub !== [] && !array_key_exists('pass_code', $auPub));
$auView = AcademyRepository::publicView($auAc->bySlug($auSlug));
ck('audit: a single course leaves for the public without its pass, saying only that it has one', !isset($auView['pass_code']) && $auView['has_pass'] === true);
$auAdm = array_values(array_filter($auAc->allForAdmin(), fn($c) => $c['slug'] === $auSlug))[0] ?? [];
ck('audit: the Studio still sees it', ($auAdm['pass_code'] ?? '') === 'cohort-secret');
$auApi = (string) file_get_contents("$auRoot/academy/api.php");
ck('audit: the course API answers through publicView', str_contains($auApi, "'course' => AcademyRepository::publicView(\$c)"));
ck('audit: learners act on published courses only (no draft reads in the learner API)', !str_contains($auApi, '$ac->bySlug($slug, true)'));

/* ══ Quizzes are passed, not skipped ══════════════════════════════════════ */
$auQslug = $auAc->save($auCourse(['title' => 'Audit Quiz Course', 'slug' => 'audit-quiz', 'access_type' => 'open']));
$auQc = $auAc->bySlug($auQslug);
$auMod = $auLms->addModule((int) $auQc['id'], 'One');
$auQuiz = json_encode(['pass' => 50, 'questions' => [['q' => 'Two plus two?', 'options' => ['3', '4'], 'answer' => 1]]]);
$auLid = $auLms->saveLesson(['module_id' => $auMod, 'title' => 'Quiz lesson', 'slug' => 'quiz-lesson', 'quiz_json' => $auQuiz]);
$auLesson = $auLms->lessonById($auLid);
$auDb->prepare("INSERT INTO lms_users (email, name, role, password_hash, created_at) VALUES ('audit.learner@example.test', 'Audit Learner', 'learner', '', '2026-11-04 00:00:00')")->execute();
$auUid = (int) $auDb->query("SELECT id FROM lms_users WHERE email = 'audit.learner@example.test'")->fetchColumn();
ck('audit: a quiz lesson says it needs its quiz', $auLms->needsQuiz($auLesson));
$auLms->markComplete($auUid, $auLesson);   // a tick written without a pass (an old row, a direct request)
ck('audit: a ticked quiz lesson with no pass earns no certificate', ($auLms->completeIfDone($auUid, (int) $auQc['id'])['complete'] ?? true) === false);
$auG = $auLms->gradeQuiz($auUid, $auLesson, [1]);
ck('audit: …passing the quiz does', !empty($auG['passed']) && !empty($auLms->completeIfDone($auUid, (int) $auQc['id'])['cert']));
ck('audit: the API refuses to complete a quiz lesson by ticking it', str_contains($auApi, "if (\$lms->needsQuiz(\$lesson)) json_out("));

/* ══ An instructor teaches their own courses, not every course ════════════ */
/* Staff count as organisation members, which opens members' and paid courses
   by policy; what the role must not open is a RESTRICTED course somebody else
   teaches, or a lesson before its date. */
$auPaid = $auAc->bySlug($auAc->save($auCourse(['title' => 'Audit Locked', 'slug' => 'audit-locked', 'access_type' => 'restricted', 'pass_code' => 'locked-pass'])));
$auInst = ['id' => 999001, 'role' => 'instructor'];
ck('audit: an instructor has no free pass to somebody else\'s restricted course', $auLms->teaches($auInst, $auPaid) === false
   && $auLms->canAccess($auInst, $auPaid, ['is_preview' => 0, 'available_at' => '']) === false);
ck('audit: …but does to their own, and a coordinator to every one', $auLms->teaches($auInst, ['instructor_id' => 999001] + $auPaid)
   && $auLms->teaches(['id' => 5, 'role' => 'coordinator'], $auPaid));

/* ══ Script an editor could plant ═════════════════════════════════════════ */
require_once "$auRoot/lib/Embeds.php";
$auS = Embeds::sanitize('<a href=" javascript:alert(1)">a</a><a href="java&#9;script:alert(2)">b</a><a href="https://ok.example/">c</a>');
ck('audit: a javascript: link is stripped with a leading space or a tab in it', !str_contains(strtolower($auS), 'javascript') && str_contains($auS, 'https://ok.example/'));
ck('audit: a lesson video plays only from a player we embed, over https', Embeds::iframeSrc('javascript:alert(1)') === null
   && Embeds::iframeSrc(' javascript:alert(1)') === null && Embeds::iframeSrc('https://evil.example/x') === null && Embeds::iframeSrc('http://player.vimeo.com/video/1') === null);
ck('audit: …and a pasted YouTube or Vimeo page becomes its player', Embeds::iframeSrc('https://youtu.be/abcdefgh') === 'https://www.youtube-nocookie.com/embed/abcdefgh'
   && Embeds::iframeSrc('https://vimeo.com/123') === 'https://player.vimeo.com/video/123');
$auL = $auLms->lessonById($auLms->saveLesson(['module_id' => $auMod, 'title' => 'Bad video', 'slug' => 'bad-video', 'video_url' => 'javascript:alert(1)']));
ck('audit: a bad video link is not stored', ($auL['video_url'] ?? null) === null);
ck('audit: the learn page frames only a checked video', str_contains((string) file_get_contents("$auRoot/academy/learn.php"), 'Embeds::iframeSrc((string) $lesson[\'video_url\'])'));
foreach (['academy/_avac.php', 'lib/partials.php'] as $auF) {
    ck("audit: $auF's JSON-LD cannot be closed by a </script> in a title", str_contains((string) file_get_contents("$auRoot/$auF"), 'JSON_HEX_TAG'));
}

/* ══ A live class's join link reaches its learners only ═══════════════════ */
$auCls = (string) file_get_contents("$auRoot/academy/class.php");
ck('audit: the calendar entry needs the recipient\'s signature or a learner on the course', str_contains($auCls, 'LmsDrip::classSig($id, $uid)') && str_contains($auCls, 'if (!$c || !$ok)'));
ck('audit: the reminder links with that signature', LmsDrip::classSig(7, 3) !== LmsDrip::classSig(7, 4) && str_contains(LmsDrip::classPath(7, 3), '&t=' . LmsDrip::classSig(7, 3)));

/* ══ 2. A new training fee after a voided one charges ═════════════════════ */
$auN = NgvDb::pdo();
foreach (['ngv_charges', 'ngv_payments', 'ngv_participants'] as $auT) { try { $auN->exec("DELETE FROM $auT WHERE member_id = 7101"); } catch (Throwable $e) {} }
$auFeesWas = Database::metaGet('ngv_fees');
NgvLedger::saveSettings(['enabled' => true, 'accrueFrom' => '2026-01-01'], 'test');
$auN->prepare("INSERT INTO ngv_participants (member_id,name,email,plan,status,start_date,created_at,updated_at) VALUES (7101,'Audit Fee','auditfee@example.test','Full Programme','active','2026-03-10','2026-03-10','2026-03-10')")->execute();
$auT1 = NgvLedger::startTrainingFee(7101, 12, 1, 120000, '2026-09-07');
$auCid = (int) $auN->query("SELECT id FROM ngv_charges WHERE member_id = 7101 AND kind = 'programme'")->fetchColumn();
NgvLedger::stopTrainingFee(7101);
NgvLedger::void('charge', $auCid, 'Agreed in error.', 2);
$auT2 = NgvLedger::startTrainingFee(7101, 6, 1, 60000, '2026-09-07');
ck('audit: an agreement in the month of a voided one posts its charge', !empty($auT2['ok']) && (int) $auT2['posted'] === 1
   && (int) (NgvLedger::balance(7101)['due']['programme'] ?? 0) === 10000);
ck('audit: …and the void stays on the record, with its month', (int) $auN->query("SELECT COUNT(*) FROM ngv_charges WHERE member_id = 7101 AND voided = 1 AND period LIKE '2026-09%'")->fetchColumn() === 1);

/* ══ NGV: a declined request is not notice; nobody settles their own account ══ */
ck('audit: an absence with a declined request is fined', str_contains((string) file_get_contents("$auRoot/lib/GateAttendance.php"), "if (!\$ex || (string) \$ex['status'] === 'declined') self::fineAbsent"));
$auM = (string) file_get_contents("$auRoot/academy/ngv/members.php");
ck('audit: an admin cannot change their own enrolment, training fee or fines', str_contains($auM, "in_array(\$act, ['admin', 'training', 'training_stop', 'remind_off', 'charge', 'damage'], true)")
   && str_contains($auM, "NgvLedger::selfDealing(\$mid, \$adminUid, 'makes this change')"));
ck('audit: the registration desk records the membership fee, not any sum', str_contains((string) file_get_contents("$auRoot/lib/NgvRegistration.php"), 'That is more than the membership fee'));

/* ══ Rate limits that hold; an Origin that names nobody ═══════════════════ */
foreach ((array) @glob(AV_ROOT . '/db/cache/rl/audit_rl_*.json') as $auF) @unlink($auF);
for ($i = 0; $i < 3; $i++) av_rate_ok('audit_rl', 3, 600);
ck('audit: a spent limit is seen without spending more', av_rate_spent('audit_rl', 3, 600) && !av_rate_spent('audit_rl', 4, 600));
foreach ((array) @glob(AV_ROOT . '/db/cache/rl/audit_rl_*.json') as $auF) @unlink($auF);
ck('audit: the summit ticket obeys its limit', str_contains((string) file_get_contents("$auRoot/academy/dns/ticket.php"), "!av_rate_ok('summit_ticket', 60, 600)"));
$auProbe = static function (string $origin, string $site = '') use ($auRoot): string {
    $code = 'require ' . var_export("$auRoot/lib/helpers.php", true) . '; $_SERVER["HTTP_HOST"] = "afrovanguard.org.ng"; $_SERVER["HTTP_ORIGIN"] = ' . var_export($origin, true) . ';'
          . ($site !== '' ? ' $_SERVER["HTTP_SEC_FETCH_SITE"] = ' . var_export($site, true) . ';' : '') . ' require_same_origin(); echo "ALLOWED";';
    return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1'));
};
ck('audit: Origin: null is refused', str_contains($auProbe('null'), 'Cross-origin request rejected.'));
ck('audit: a browser saying cross-site is believed', str_contains($auProbe('https://afrovanguard.org.ng', 'cross-site'), 'Cross-origin request rejected.'));
ck('audit: our own origin still passes', str_ends_with($auProbe('https://afrovanguard.org.ng', 'same-origin'), 'ALLOWED'));

/* ══ Changes show at once: an API answer is never cached ══════════════════ */
ck('audit: every JSON answer says no-store', str_contains((string) file_get_contents("$auRoot/lib/helpers.php"), "header('Cache-Control: no-store, private');"));
ck('audit: the admin and the Studio ask past any copy the browser already holds', substr_count((string) file_get_contents("$auRoot/admin/app.js"), "cache: 'no-store'") >= 10
   && str_contains((string) file_get_contents("$auRoot/academy/studio/studio.js"), "cache: 'no-store'"));
ck('audit: pages and JSON are not given the host\'s week-long default', str_contains((string) file_get_contents("$auRoot/.htaccess"), 'ExpiresByType application/json "access plus 0 seconds"'));

/* ══ 3. MySQL 8: TEXT defaults as expressions, foreign keys MySQL enforces ══ */
$auDdl = "CREATE TABLE IF NOT EXISTS t (\n  id INTEGER PRIMARY KEY AUTO_INCREMENT,\n  a TEXT NOT NULL DEFAULT 'x',\n  course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,\n  b TEXT NOT NULL DEFAULT ''\n);";
$auMy = Database::mysqlDdl($auDdl);
ck('audit: a TEXT default is written as an expression, the last column too', str_contains($auMy, "a TEXT NOT NULL DEFAULT ('x')") && str_contains($auMy, "b TEXT NOT NULL DEFAULT ('')"));
ck('audit: …and an already-wrapped one is left alone', Database::mysqlTextDefaults("c TEXT DEFAULT ('y')") === "c TEXT DEFAULT ('y')");
ck('audit: an inline REFERENCES becomes a table-level FOREIGN KEY', str_contains($auMy, 'FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE')
   && !preg_match('/course_id INTEGER NOT NULL REFERENCES/', $auMy));
ck('audit: translateDDL applies both for MySQL', str_contains(Database::translateDDL($auDdl, 'mysql'), "DEFAULT ('x')") && str_contains(Database::translateDDL($auDdl, 'mysql'), 'FOREIGN KEY (`course_id`)'));
ck('audit: the MySQL schema file has no literal TEXT default left', !preg_match("/\\bTEXT(\\s+NOT\\s+NULL)?\\s+DEFAULT\\s+'/i", (string) file_get_contents("$auRoot/db/schema.mysql.sql")));

/* ══ 4. A migration names every table it cannot move ═════════════════════ */
require_once "$auRoot/lib/Migrator.php"; require_once "$auRoot/lib/DbMigrateJob.php";
$auSrc = new PDO('sqlite::memory:'); $auDst = new PDO('sqlite::memory:');
foreach ([$auSrc, $auDst] as $auP) $auP->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$auSrc->exec('CREATE TABLE keep (id INTEGER PRIMARY KEY, v TEXT)'); $auSrc->exec("INSERT INTO keep (v) VALUES ('a')");
$auSrc->exec('CREATE TABLE secrets (id INTEGER PRIMARY KEY, v TEXT)'); $auSrc->exec("INSERT INTO secrets (v) VALUES ('s')");
$auDst->exec('CREATE TABLE keep (id INTEGER PRIMARY KEY, v TEXT)');
$auRep = Migrator::migrate($auSrc, $auDst, []);
ck('audit: a table missing on the target is a failure in the report, not left out', isset($auRep['secrets']) && $auRep['secrets']['ok'] === false && $auRep['keep']['ok'] === true);
$auThrew = '';
try { DbMigrateJob::reset(); DbMigrateJob::begin($auSrc, $auDst, ['driver' => 'sqlite', 'host' => 'x', 'name' => 'y'], true); } catch (Throwable $e) { $auThrew = $e->getMessage(); }
DbMigrateJob::reset();
ck('audit: the resumable job will not start while a table has no home', str_contains($auThrew, 'The target has no table for: secrets'));
$auJob = (string) file_get_contents("$auRoot/lib/DbMigrateJob.php");
ck('audit: progress is saved after every slice, one step at a time, under a server cap', str_contains($auJob, 'self::put($s);   // after EVERY slice')
   && str_contains($auJob, 'flock($lock, LOCK_EX | LOCK_NB)') && str_contains($auJob, 'min(self::BUDGET_MAX'));
ck('audit: Postgres serials are moved past the copied ids', str_contains($auJob, "pg_get_serial_sequence('\" . \$t . \"','id')"));
ck('audit: nothing is moved out of a fallback database', substr_count((string) file_get_contents("$auRoot/admin/api.php"), 'Nothing is moved from a fallback') === 2);

/* ══ 5/6. Schema: kept current, retried when it fails; data off the code ═══ */
$auDb2 = (string) file_get_contents("$auRoot/lib/Database.php");
ck('audit: a server database is synced from schema.sql itself', str_contains($auDb2, "self::syncTablesFromDdl(self::\$pdo, (string) \$ddl, self::driver(), 'db')"));
ck('audit: a failed schema step is not stamped as done', str_contains($auDb2, "if (\$failed) { self::metaSet('schema_failed_at'") && substr_count($auDb2, 'self::stampOrRetry($stamp, $failed);') === 2);
ck('audit: the verification backfill is finished on a later run if it failed', str_contains($auDb2, "lms_verify_backfill_upto"));
ck('audit: busy_timeout no longer rides on WAL', str_contains($auDb2, "try { \$pdo->exec('PRAGMA busy_timeout = 5000'); } catch"));
$auComp = (string) file_get_contents("$auRoot/docker-compose.yml");
ck('audit: Docker never mounts a volume over db/', !preg_match('~-\s*app-db:/var/www/html/db\s~', $auComp) && str_contains($auComp, 'AV_DB_PATH: "/var/lib/afrovanguard/diary.sqlite"'));
ck('audit: Docker needs a real APP_KEY and runs PHP 8.4', str_contains($auComp, 'APP_KEY: "${APP_KEY:?') && str_contains((string) file_get_contents("$auRoot/Dockerfile"), 'FROM php:8.4-apache'));
ck('audit: the nginx guide denies db/', str_contains((string) file_get_contents("$auRoot/DEPLOY.md"), 'location ~ ^/(lib|db|data|'));
ck('audit: Postgres backups have their own dump', str_contains((string) file_get_contents("$auRoot/lib/Backups.php"), "if (\$drv === 'pgsql') return [self::dumpPg(\$pdo), 'sql'];"));
ck('audit: every stored timestamp is UTC', date_default_timezone_get() === 'UTC');

/* ══ The one-time superadmin password is sealed, and expires ══════════════ */
$auPwWas = Database::metaGet('superadmin_initial_password');
Database::metaSet('superadmin_initial_password', 'legacy-plain-pw'); Database::metaSet('superadmin_initial_password_at', (string) time());
ck('audit: a password stored in the clear by an older version is still revealed once', SuperAdmin::pendingPassword() === 'legacy-plain-pw');
ck('audit: …and is sealed the moment it is read', str_starts_with((string) Database::metaGet('superadmin_initial_password'), 'sp1:') && SuperAdmin::pendingPassword() === 'legacy-plain-pw');
Database::metaSet('superadmin_initial_password_at', (string) (time() - 8 * 86400));
ck('audit: …and it is gone after a week', SuperAdmin::pendingPassword() === '');
Database::metaSet('superadmin_initial_password', (string) ($auPwWas ?? '')); Database::metaSet('superadmin_initial_password_at', '');
ck('audit: seed_admin checks the account exists before saying it was made', str_contains((string) file_get_contents("$auRoot/db/seed_admin.php"), 'could NOT be created'));

/* ══ Deeper pass ══════════════════════════════════════════════════════════ */
// The sign-in code: its tries are claimed, not counted after the fact, and it is spent once.
$auMail = 'audit.otp@example.test';
Otp::ensure();
$auDb->prepare('DELETE FROM lms_otps WHERE email = ?')->execute([$auMail]);
$auDb->prepare('INSERT INTO lms_otps (email, purpose, code_hash, ip, expires_at, created_at) VALUES (?,?,?,?,?,?)')
     ->execute([$auMail, 'login', (new ReflectionMethod('Otp', 'hash'))->invoke(null, $auMail, 'login', '123456'), '', gmdate('Y-m-d H:i:s', time() + 600), gmdate('Y-m-d H:i:s')]);
$auMax = AuthPolicy::otpMaxAttempts();
$auDb->prepare('UPDATE lms_otps SET attempts = ? WHERE email = ?')->execute([$auMax, $auMail]);   // as if the last tries had just been claimed by guesses in flight
ck('audit: a code whose tries are spent refuses even the right code', Otp::check($auMail, '123456', 'login') === false);
$auDb->prepare('UPDATE lms_otps SET attempts = 0, consumed = 0 WHERE email = ?')->execute([$auMail]);
ck('audit: the right code signs in once', Otp::check($auMail, '123456', 'login') === true && Otp::check($auMail, '123456', 'login') === false);
$auOtp = (string) file_get_contents("$auRoot/lib/Otp.php");
ck('audit: the claim carries the cap in its WHERE', str_contains($auOtp, 'WHERE id = ? AND consumed = 0 AND attempts < ?') && str_contains($auOtp, 'WHERE id = ? AND consumed = 0'));
$auDb->prepare('DELETE FROM lms_otps WHERE email = ?')->execute([$auMail]);

// A reset ends every way in through the inbox, is spent once, and is announced afterwards.
$auA = (string) file_get_contents("$auRoot/lib/LmsAuth.php");
ck('audit: a password reset retires live sign-in codes', str_contains($auA, "UPDATE lms_otps SET consumed = 1 WHERE email = ? AND consumed = 0"));
ck('audit: a reset link is spent before the password is set, and only once', str_contains($auA, "UPDATE lms_password_resets SET used_at = ? WHERE id = ? AND used_at = ''") && strpos($auA, "AND used_at = ''\");\n        \$spend->execute") < strpos($auA, "SET password_hash = ?, has_password = 1, email_verified = 1"));
ck('audit: the owner is told after a reset', str_contains($auA, "'Your Afrovanguard password was changed'"));
ck('audit: a verification link does not sign in a suspended account', str_contains($auA, "if ((\$u['status'] ?? 'active') !== 'active') return null;\n        self::startSession"));

// Money is in naira.
$auLmsSrc = (string) file_get_contents("$auRoot/lib/LmsRepository.php");
ck('audit: a course or membership is granted only for a naira payment', str_contains($auLmsSrc, "if (\$currency !== null && !in_array(strtoupper(\$currency), ['NGN', ''], true))")
   && str_contains((string) file_get_contents("$auRoot/academy/pay.php"), "(string) (\$v['currency'] ?? ''))")
   && substr_count((string) file_get_contents("$auRoot/lib/PaymentWebhook.php"), 'finalizePayment($ref,') === 2 && !str_contains((string) file_get_contents("$auRoot/lib/PaymentWebhook.php"), "finalizePayment(\$ref, \$paidKobo);"));

// The assistant's fetcher connects to the address it checked.
$auG = AvWeb::guard('https://8.8.8.8/');
ck('audit: an IP address needs no pin; the fetch is pinned for a name', ($auG['pin'] ?? 'x') === '' && str_contains((string) file_get_contents("$auRoot/lib/AvWeb.php"), 'CURLOPT_RESOLVE        => $pin !== \'\' ? [$pin] : []'));
ck('audit: a private address is still refused', isset(AvWeb::guard('http://127.0.0.1/')['error']) && isset(AvWeb::guard('http://[::1]/')['error']));

/* tidy */
$auDb->exec("DELETE FROM lms_users WHERE email = 'audit.learner@example.test'");
foreach (['ngv_charges', 'ngv_participants'] as $auT) { try { $auN->exec("DELETE FROM $auT WHERE member_id = 7101"); } catch (Throwable $e) {} }
// Fees back the way they were: later files read the switch.
Database::metaSet('ngv_fees', (string) ($auFeesWas ?? ''));
$auC = new ReflectionProperty('NgvLedger', 'cache'); $auC->setAccessible(true); $auC->setValue(null, null);
