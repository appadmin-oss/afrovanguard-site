<?php
/**
 * tests/cacmail.test.php — CACENTRE's bulk mail, sent from this site.
 *
 * Two things are being protected.
 *
 * ONE: THE ALLOWANCE IS REALLY SHARED. The whole feature is worth building
 * only if this site takes what it can afford and says — by address — which
 * people it did not take, so CACENTRE sends those itself. A silent partial
 * acceptance loses people off the end of a list with nothing to show for it,
 * and the way that is discovered is a supporter saying they heard nothing.
 *
 * TWO: SOMEBODY ELSE'S CONTACT LIST IS NOT KEPT. These are CACENTRE's
 * people. The row holds an address because there is no way to send without
 * one, and loses it the moment it has been used.
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/CacMail.php';

CacMail::ensure();
$pdo = Database::pdo();
$pdo->exec('DELETE FROM av_cac_mail');

$people = static fn(int $n, string $tag = 'p'): array => array_map(
    static fn(int $i): array => ['email' => $tag . $i . '@example.org', 'name' => 'Person ' . $i],
    range(1, $n)
);

/* ── It takes what it can afford, and names the rest ──────────────────── */
putenv('AV_CAC_MAIL_DAILY=5');
ck('CacMail: the allowance is read from configuration', CacMail::dailyCap() === 5);
ck('CacMail: and an empty queue means the whole allowance is free', CacMail::headroom() === 5);

$r = CacMail::accept('batch-1', 'A letter', '<p>Hello</p>', $people(8));
ck('CacMail: it takes only what it can afford', count($r['accepted']) === 5);
ck('CacMail: and declines the rest', count($r['declined']) === 3);
ck('CacMail: BY ADDRESS, so CACENTRE knows exactly who it still owes a letter — a count would leave it guessing',
   $r['declined'] === ['p6@example.org', 'p7@example.org', 'p8@example.org']);
ck('CacMail: every offered address is accounted for in exactly one list',
   count($r['accepted']) + count($r['declined']) + count($r['duplicates']) === 8);
ck('CacMail: and the allowance is spent', CacMail::headroom() === 0);
ck('CacMail: so a second offer is declined whole', count(CacMail::accept('batch-2', 'S', '<p>x</p>', $people(2, 'q'))['declined']) === 2);

/* ── A repeated offer does not write to somebody twice ────────────────── */
$pdo->exec('DELETE FROM av_cac_mail');
putenv('AV_CAC_MAIL_DAILY=50');
CacMail::accept('batch-3', 'A letter', '<p>Hello</p>', $people(3));
$again = CacMail::accept('batch-3', 'A letter', '<p>Hello</p>', $people(3));
/* CACENTRE retries a batch it did not hear an answer to. The UNIQUE key is
   what refuses the second copy — not a SELECT followed by an INSERT, which
   two copies arriving together would both walk straight past. */
ck('CacMail: the same person in the same batch is not queued twice', count($again['accepted']) === 0);
ck('CacMail: they are reported as duplicates', count($again['duplicates']) === 3);
ck('CacMail: and NOT as declined — handing them back is how somebody gets the letter twice',
   $again['declined'] === []);
ck('CacMail: the queue still holds one each', (int) $pdo->query('SELECT COUNT(*) FROM av_cac_mail')->fetchColumn() === 3);

/* A different batch to the same people is a different letter, and goes. */
ck('CacMail: a different batch to the same people is accepted',
   count(CacMail::accept('batch-4', 'Another', '<p>Hi</p>', $people(3))['accepted']) === 3);

/* ── An address nobody could send to is not handed back ───────────────── */
$pdo->exec('DELETE FROM av_cac_mail');
$r = CacMail::accept('batch-5', 'S', '<p>x</p>', [
    ['email' => 'good@example.org'], ['email' => 'not an address'], ['email' => ''],
]);
ck('CacMail: a valid address is taken', $r['accepted'] === ['good@example.org']);
ck('CacMail: and an unsendable one is not declined back — CACENTRE cannot send it either, and handing it over would just queue a bounce there',
   $r['declined'] === []);

/* ── Sending erases the address ───────────────────────────────────────── */
$pdo->exec('DELETE FROM av_cac_mail');
CacMail::accept('batch-6', 'S', '<p>x</p>', [['email' => 'keep@example.org', 'name' => 'Keep']]);
ck('CacMail: the address is held while it is still needed',
   (string) $pdo->query("SELECT to_email FROM av_cac_mail LIMIT 1")->fetchColumn() === 'keep@example.org');

/* Mail is disabled in the suite, so the send fails and the row is retried —
   which is the behaviour to check on this path. */
$res = CacMail::drain(10);
ck('CacMail: a failed send is counted', (int) $res['failed'] === 1);
ck('CacMail: and retried rather than dropped',
   (string) $pdo->query("SELECT status FROM av_cac_mail LIMIT 1")->fetchColumn() === 'queued');
ck('CacMail: with the attempt recorded', (int) $pdo->query("SELECT attempts FROM av_cac_mail LIMIT 1")->fetchColumn() === 1);

/* Three failures and it stops, rather than spending the allowance on one
   address for ever. */
CacMail::drain(10); CacMail::drain(10);
ck('CacMail: after three attempts it gives up', (string) $pdo->query("SELECT status FROM av_cac_mail LIMIT 1")->fetchColumn() === 'failed');

/* THE SUCCESS PATH, through the real writer. The suite cannot send mail —
   it is disabled there on purpose — so drain()'s success branch is never
   walked here. An earlier version of this test re-created the erase with
   its OWN update and then asserted the column, which asserted its own
   statement: a mutation that stopped erasing anything sailed through it. */
$pdo->exec('DELETE FROM av_cac_mail');
CacMail::accept('batch-9', 'S', '<p>x</p>', [['email' => 'gone@example.org', 'name' => 'Gone']]);
$id = (int) $pdo->query('SELECT id FROM av_cac_mail LIMIT 1')->fetchColumn();
CacMail::markSent($id);
$row = $pdo->query('SELECT status, to_email, to_name, body_html, email_key FROM av_cac_mail LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ck('CacMail: a sent message is recorded as sent', (string) $row['status'] === 'sent');
ck('CacMail: and the address this site never owned is gone with it', (string) $row['to_email'] === '');
ck('CacMail: the name too', (string) $row['to_name'] === '');
ck('CacMail: and the letter itself, which was CACENTRE\'s to keep', $row['body_html'] === null);
ck('CacMail: the hashed key stays, so a re-offer still cannot write to them twice',
   strlen((string) $row['email_key']) === 64);
ck('CacMail: and it is a hash, not the address', !str_contains((string) $row['email_key'], '@'));

/* ── Nothing is held for a letter that will never be sent ─────────────── */
$pdo->exec('DELETE FROM av_cac_mail');
CacMail::accept('batch-7', 'S', '<p>x</p>', [['email' => 'stale@example.org']]);
$pdo->exec("UPDATE av_cac_mail SET created_at = '" . gmdate('Y-m-d\TH:i:s\Z', time() - 10 * 86400) . "'");
CacMail::drain(10);
$row = $pdo->query("SELECT status, to_email FROM av_cac_mail LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ck('CacMail: a letter too old to be worth sending is given up on', (string) $row['status'] === 'expired');
ck('CacMail: and its address goes with it — there is no reason to keep somebody else\'s contact details for a message that will never be sent',
   (string) $row['to_email'] === '');

/* ── Switched off is switched off ─────────────────────────────────────── */
putenv('AV_CAC_MAIL_DAILY=0');
ck('CacMail: an allowance of nothing takes nothing', CacMail::headroom() === 0);
ck('CacMail: so the whole list goes back to CACENTRE, which could always send it',
   count(CacMail::accept('batch-8', 'S', '<p>x</p>', $people(3, 'z'))['declined']) === 3);
putenv('AV_CAC_MAIL_DAILY=');

/* ── The door is the one the two sites already share ──────────────────── */
$door = (string) @file_get_contents(AV_ROOT . '/integrations/cacentre-mail.php');
ck('CacMail: the door verifies with the SSO secret the task bridge already uses, not a second one',
   str_contains($door, 'CacSso::secret()') && !str_contains($door, 'AV_CAC_MAIL_SECRET'));
ck('CacMail: in constant time', str_contains($door, 'hash_equals'));
ck('CacMail: a token minted for the tasks door cannot be spent here',
   str_contains($door, "'cac-mail'") && str_contains($door, 'wrong-purpose'));
ck('CacMail: and the door only takes — it never sends inside the request CACENTRE is waiting on',
   !str_contains($door, 'Mailer::send'));

/* ══ "What are you working on?" — the prompt on Today ═══════════════════
 *
 * The centre asked for a to-do prompt on the member's screen. What is
 * checked here is the two decisions behind it; the rendering itself is a
 * template and is checked by eye.
 */
$portal = (string) @file_get_contents(AV_ROOT . '/portal/index.php');

/* IT USES THE CONNECTION THAT ALREADY EXISTS. The answer becomes an
   ordinary task, written through the same endpoint the task list uses, and
   CACENTRE reads it over the bridge the two sites have shared since the
   task feature shipped. No new door, no second writer, and one list rather
   than two. */
ck('AskTask: the answer is written through the existing task endpoint, not a door of its own',
   str_contains($portal, "fetch('/portal/collab.php?action=task_add'"));
ck('AskTask: carrying the CSRF token the rest of the portal uses',
   str_contains($portal, "'X-CSRF-Token': csrf"));
ck('AskTask: and CACENTRE sees it over the bridge it already has, rather than a copy',
   str_contains($portal, 'CacTasks::openFor'));

/* IT ASKS ONLY WHEN THERE IS NOTHING TO SHOW. A member who has already
   written down what they are doing has answered the question; asking again
   teaches them to dismiss prompts without reading, and the next one that
   matters goes with it. */
ck('AskTask: it is rendered only when nothing is open for today',
   str_contains($portal, 'if ($isOrg && !$hasPlan):'));
ck('AskTask: counting tasks from BOTH sides of the bridge, so the console\'s own task silences it too',
   str_contains($portal, 'array_merge($myTasks, $cacTasks)'));
ck('AskTask: an undated task counts as a plan', str_contains($portal, "\$due === '' || \$due <= \$todayKey"));

/* "Next" carries no date on purpose: a made-up due date is overdue by
   tomorrow, and an overdue list nobody meant is what makes people stop
   reading the list at all. */
ck('AskTask: "next" is saved with no due date rather than an invented one',
   str_contains($portal, "due: when === 'today' ? today : ''"));

/* A placeholder is not a label — it disappears the moment somebody types,
   and was never announced to a screen reader in the first place. */
ck('AskTask: the field has a real label', str_contains($portal, 'for="askTaskTitle"'));
ck('AskTask: and the prompt reports success or failure in a live region',
   str_contains($portal, 'id="askTaskMsg"') && str_contains($portal, 'aria-live="polite"'));

$css = (string) @file_get_contents(AV_ROOT . '/portal/portal.css');
ck('AskTask: its layout is in the stylesheet, where it can be corrected, not inline',
   str_contains($css, '.today-ask .ask-row'));
ck('AskTask: and it does not squeeze the field to nothing on a phone',
   str_contains($css, '@media (max-width:30rem)') && str_contains($css, '.today-ask .ask-row { flex-direction:column'));
