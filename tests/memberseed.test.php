<?php
/**
 * tests/memberseed.test.php — the founding members (lib/MemberSeed.php), and
 * the card reading their printed IDs (lib/IdCard.php).
 *
 * What must hold: somebody who already signed in is LINKED, never given a
 * second account; staff are never demoted; each person ends up with exactly
 * the number in the list — at the gate for an NGV, as a printed member ID
 * for an AVM — and the card prints it, with the level letter in the chip.
 * And all of it twice over without change.
 */
declare(strict_types=1);

$msPdo = Database::pdo();
$msEmails = array_map(static fn($r) => $r[2], MemberSeed::MEMBERS);
$msClean = static function () use ($msPdo, $msEmails): void {
    $in = implode(',', array_fill(0, count($msEmails), '?'));
    $st = $msPdo->prepare("SELECT id FROM lms_users WHERE LOWER(email) IN ($in)");
    $st->execute($msEmails);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $id = (int) $id;
        foreach (['av_member_cards', 'gate_member_cards', 'member_profiles'] as $t) {
            try { $msPdo->exec("DELETE FROM {$t} WHERE member_id = {$id}"); } catch (Throwable $e) {}
        }
        try { NgvDb::pdo()->exec("DELETE FROM ngv_participants WHERE member_id = {$id}"); } catch (Throwable $e) {}
        $msPdo->exec("DELETE FROM lms_users WHERE id = {$id}");
    }
};
$msClean();

/* Two of them signed in before the seed: one through the Academy (a learner,
   email typed in capitals), one who is staff. */
$msPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')
      ->execute(['Anu O.', 'ANU@afrovanguard.org.ng', 'x', 'learner', 'active']);
$anuId = (int) $msPdo->lastInsertId();
$msPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')
      ->execute(['Chioma Nwazi', 'chioma@afrovanguard.org.ng', 'x', 'admin', 'active']);
$chiId = (int) $msPdo->lastInsertId();

$r = MemberSeed::run('test');
$rows = [];
foreach ($r['rows'] as $row) $rows[$row['code']] = $row;

ck('seed: every one of the nine is on the system', $r['ok'] && count($rows) === 9);
ck('seed: seven new accounts, two linked to the accounts they already had', $r['created'] === 7 && $r['linked'] === 2);
ck('seed: Anuoluwapo, who signed in already, is THAT account — matched by email whatever its case',
   ($rows['D-AVM-17-0002']['id'] ?? 0) === $anuId);
ck('seed: …and a learner who is a member becomes a member', $msPdo->query("SELECT role FROM lms_users WHERE id = {$anuId}")->fetchColumn() === 'member');
ck('seed: staff are never demoted — Chioma is still an admin, and still her own account',
   ($rows['A-NGV-25-0007']['id'] ?? 0) === $chiId && $msPdo->query("SELECT role FROM lms_users WHERE id = {$chiId}")->fetchColumn() === 'admin');
ck('seed: one account per email, no duplicates',
   (int) $msPdo->query("SELECT COUNT(*) FROM lms_users WHERE LOWER(email) LIKE '%@afrovanguard.org.ng' AND LOWER(email) IN ('" . implode("','", $msEmails) . "')")->fetchColumn() === 9);

$boja = (int) $rows['E-AVM-17-0001']['id'];
$divine = (int) $rows['B-NGV-25-0005']['id'];
ck('seed: the ID’s first letter is the level', Levels::of($boja) === 'E' && Levels::of($divine) === 'B' && Levels::of($anuId) === 'D');
ck('seed: an NGV has exactly the number in the list at the gate — not the next one minted',
   GateAttendance::cardFor($divine) === 'B-NGV-25-0005' && GateAttendance::cardFor($chiId) === 'A-NGV-25-0007');
ck('seed: …and is enrolled as a participant', NgvMember::participant($divine) !== null);
ck('seed: an AVM’s ID is recorded as their printed member ID, and the gate can read it',
   (int) (MemberCards::lookup(null, 'id-afrovanguard-member-ids', 'E-AVM-17-0001')['member_id'] ?? 0) === $boja);
ck('seed: an AVM is not mistaken for a vanguard — no NGV number', GateAttendance::cardFor($boja) === null);
ck('seed: everybody has a secure card for the QR', MemberCards::secure($boja) !== null && MemberCards::secure($divine) !== null);

/* ── The card prints it ─────────────────────────────────────────────────── */
$cb = IdCard::forMember($boja);
ck('card: the AVM number is printed apart from its letter — “E” in the chip, “AVM-17-0001” beside it',
   $cb['tier_letter'] === 'E' && $cb['number'] === 'AVM-17-0001');
ck('card: member since is the ID’s year', $cb['member_since'] === '2017');
ck('card: category is the level and its name', $cb['category'] === 'E · Senior Multiplier');
$cd = IdCard::forMember($divine);
ck('card: an NGV prints its gate number — it used to print no number at all',
   $cd['tier_letter'] === 'B' && $cd['number'] === 'NGV-25-0005' && $cd['member_since'] === '2025');
ck('card: …and says what they are', $cd['category'] === 'B · NextGen Vanguard');

/* ── Twice over ─────────────────────────────────────────────────────────── */
$users = (int) $msPdo->query('SELECT COUNT(*) FROM lms_users')->fetchColumn();
$cards = (int) $msPdo->query('SELECT COUNT(*) FROM av_member_cards')->fetchColumn();
$again = MemberSeed::run('test');
ck('seed: run again, nothing is created and everybody links', $again['ok'] && $again['created'] === 0 && $again['linked'] === 9);
ck('seed: …no new accounts and no new cards',
   (int) $msPdo->query('SELECT COUNT(*) FROM lms_users')->fetchColumn() === $users
   && (int) $msPdo->query('SELECT COUNT(*) FROM av_member_cards')->fetchColumn() === $cards);
ck('seed: …and every number reads “already”',
   count(array_filter($again['rows'], static fn($x) => $x['number'] === 'already')) === 9);

/* ── It runs itself once ────────────────────────────────────────────────── */
Database::metaSet(MemberSeed::META, 'done earlier');
$msClean();
MemberSeed::boot();
ck('seed: once marked done, the bootstrap hook does nothing', (int) $msPdo->query('SELECT COUNT(*) FROM lms_users WHERE LOWER(email) = \'boja@afrovanguard.org.ng\'')->fetchColumn() === 0);
Database::metaSet(MemberSeed::META, '');
MemberSeed::boot();
ck('seed: on a fresh site the hook seeds, and marks itself done',
   (int) $msPdo->query('SELECT COUNT(*) FROM lms_users WHERE LOWER(email) = \'boja@afrovanguard.org.ng\'')->fetchColumn() === 1
   && str_starts_with((string) Database::metaGet(MemberSeed::META), 'done'));
ck('bootstrap: the hook is wired, web requests only', str_contains((string) file_get_contents(AV_ROOT . '/lib/bootstrap.php'), "if (PHP_SAPI !== 'cli') MemberSeed::boot();"));

$msClean();
Database::metaSet(MemberSeed::META, '');
