<?php
/**
 * tests/auditexports.test.php — M1, M2 and M3 from the 27 October audit.
 *
 * Three findings, one theme: a record that cannot answer who, and a file
 * that can run code on the machine of the person who opens it.
 *
 *   M1  the trail said "admin" did it, whichever admin it was
 *   M2  two exports of personal data left no row at all
 *   M3  three exports wrote user-typed text raw to CSV
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

/* ══ M3 · a cell a spreadsheet would execute ═══════════════════════════ */

ck('csv: a formula is written as text, not as a formula',
   Csv::cell('=HYPERLINK("https://evil.example","agenda")') === '\'=HYPERLINK("https://evil.example","agenda")');

foreach (['=cmd', '+1+1', '-2+3', '@SUM(A1)'] as $bad) {
    ck('csv: a cell starting ' . $bad[0] . ' is neutralised', str_starts_with(Csv::cell($bad), "'"));
}

/* The two the obvious version of this guard walks past. A leading tab or
   carriage return is read as a formula just as = is. */
ck('csv: a leading tab is neutralised too', str_starts_with(Csv::cell("\t=1+1"), "'"));
ck('csv: and a leading carriage return', str_starts_with(Csv::cell("\r=1+1"), "'"));

/* …without breaking the columns the export exists to add up. A negative
   amount is a value, and prefixing it turns a numeric column into text. */
ck('csv: a negative number is left alone', Csv::cell('-5') === '-5');
ck('csv: a phone number with a plus is left alone', Csv::cell('+234 801 234 5678') === '+234 801 234 5678');
ck('csv: ordinary text is left alone', Csv::cell('Ada Obi') === 'Ada Obi');
ck('csv: an empty cell stays empty', Csv::cell('') === '' && Csv::cell(null) === '');
ck('csv: a boolean reads as a person would write it', Csv::cell(true) === 'yes' && Csv::cell(false) === '');

ck('csv: a whole row is guarded, cell by cell',
   Csv::row(['=a', 'b', '-1']) === ["'=a", 'b', '-1']);

/* ══ …and every export actually goes through it ════════════════════════
   The guard existed and was correct, inline in MemberDesk, which is
   exactly why the other exports did not have it. A control that lives
   inside one caller is one the next caller does not know about. */

$exportFiles = [
    'admin/api.php'        => 'summit, mentorship and academy roster',
    'lib/MemberDesk.php'   => 'the member desk',
    'lib/Volunteers.php'   => 'a volunteer\'s hours',
    'academy/teach.php'    => 'the instructor\'s roster',
];
foreach ($exportFiles as $file => $what) {
    $src = (string) file_get_contents(AV_ROOT . '/' . $file);
    /* fputcsv anywhere but inside Csv itself means an export that skipped
       the guard — which is how three of these came to be unescaped. */
    ck('csv: ' . $what . ' writes through Csv::, never raw fputcsv',
       !str_contains($src, 'fputcsv(') && str_contains($src, 'Csv::'));
}

ck('csv: Csv:: is the only place fputcsv is called',
   str_contains((string) file_get_contents(AV_ROOT . '/lib/Csv.php'), 'fputcsv('));

/* ══ M2 · an export of personal data leaves a row ══════════════════════ */

$api = (string) file_get_contents(AV_ROOT . '/admin/api.php');
foreach (['summit_export' => 'summit', 'mentorship_export' => 'mentorship'] as $case => $area) {
    ck('audit: ' . $case . ' is logged', preg_match(
        "~case '" . $case . "':.*?AdminAudit::log\\('" . $area . "'~s", $api) === 1);
}
/* Logged BEFORE the bytes: the CSV handlers exit() when they are done, so
   anything after the stream never runs. */
ck('audit: the export is logged before the file is streamed, because the handler exits',
   preg_match("~case 'summit_export':.*?AdminAudit::log\\(.*?header\\('Content-Type: text/csv~s", $api) === 1);
ck('audit: and the row says how many records left',
   preg_match("~AdminAudit::log\\('summit'.*?count\\(\\\$rows\\)~s", $api) === 1);

/* ══ M1 · the trail says who ═══════════════════════════════════════════ */

ck('audit: the actor is resolved, not defaulted to the string "admin"',
   !str_contains((string) file_get_contents(AV_ROOT . '/lib/AdminAudit.php'), "string \$actor = 'admin'")
   && !str_contains((string) file_get_contents(AV_ROOT . '/lib/LmsRepository.php'), "string \$actor = 'admin'"));

/* The BEHAVIOUR, not just the signature — and with somebody actually
   signed in, which is the whole point. The first version of this test
   asserted `$row['actor'] === av_admin_actor()` with nobody signed in,
   so both sides were the string "admin" and it passed whether the fix
   was there or not. Mutation testing caught it: putting the old
   `$actor ?: 'admin'` back broke nothing. A test that passes for the
   wrong reason is worse than no test. */
$pdo = Database::pdo();
$pdo->prepare("INSERT INTO lms_users (name, email, password_hash, status, email_verified) VALUES (?,?,?,'active',1)")
    ->execute(['Ada Auditor', 'ada.audit@exp.test', '']);
$uid = (int) $pdo->lastInsertId();
$tok = str_repeat('b', 40);
$pdo->prepare('INSERT INTO lms_sessions (user_id, token_hash, expires_at) VALUES (?,?,?)')
    ->execute([$uid, hash('sha256', $tok), gmdate('Y-m-d H:i:s', time() + 3600)]);
$_COOKIE[LmsAuth::COOKIE] = $tok;
/* user() memoises per request; the suite is one request. */
(new ReflectionProperty('LmsAuth', 'checked'))->setValue(null, false);
(new ReflectionProperty('LmsAuth', 'cache'))->setValue(null, null);

ck('audit: with somebody signed in, av_admin_actor() names them', av_admin_actor() === 'ada.audit@exp.test');

AdminAudit::log('tests', 'who_did_it', 'T-1', 'actor resolution');
$row = $pdo->query("SELECT actor FROM lms_audit WHERE action = 'who_did_it' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ck('audit: a row written with no actor names the person, not "admin"',
   $row && (string) $row['actor'] === 'ada.audit@exp.test');

/* The same table through the other door. A trail that names the person
   one way and "admin" the other is no better than one naming nobody. */
(new LmsRepository())->audit('who_did_it_lms', 'T-3', 'through LmsRepository');
$row3 = $pdo->query("SELECT actor FROM lms_audit WHERE action = 'who_did_it_lms' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ck('audit: …and so does a row written through LmsRepository',
   $row3 && (string) $row3['actor'] === 'ada.audit@exp.test');

/* An explicit actor is still honoured — a caller that knows better wins. */
AdminAudit::log('tests', 'who_did_it_2', 'T-2', 'explicit', null, 'someone.else@exp.test');
$row2 = $pdo->query("SELECT actor FROM lms_audit WHERE action = 'who_did_it_2' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ck('audit: an actor the caller names is kept', $row2 && (string) $row2['actor'] === 'someone.else@exp.test');

unset($_COOKIE[LmsAuth::COOKIE]);
(new ReflectionProperty('LmsAuth', 'checked'))->setValue(null, false);
(new ReflectionProperty('LmsAuth', 'cache'))->setValue(null, null);
$pdo->exec('DELETE FROM lms_sessions WHERE user_id = ' . $uid);
$pdo->exec('DELETE FROM lms_users WHERE id = ' . $uid);
$pdo->exec("DELETE FROM lms_audit WHERE action = 'who_did_it_lms'");

Database::pdo()->exec("DELETE FROM lms_audit WHERE action IN ('who_did_it','who_did_it_2')");
