<?php
/**
 * tests/peoplelisting.test.php — H12: published without being asked.
 *
 * Every load of /people inserted any @afrovanguard.org.ng account into
 * the public team list as ACTIVE. Holding a work address published your
 * name and your photograph on a public page, with nobody asked and
 * nobody told — and the page is where most people would first find out.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/people.php';

$pdo = Database::pdo();
av_team_ensure($pdo);

$dom = defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng';
$email = 'listing.test@' . $dom;

$pdo->prepare('DELETE FROM team WHERE LOWER(email) = ?')->execute([$email]);
$pdo->prepare('DELETE FROM lms_users WHERE email = ?')->execute([$email]);
$pdo->prepare("INSERT INTO lms_users (name, email, password_hash, status, email_verified) VALUES (?,?,'','active',1)")
    ->execute(['Listing Test', $email]);

/* ══ The sync no longer publishes anybody ══════════════════════════════ */

av_team_sync_org_members($pdo);
$row = $pdo->prepare('SELECT active, synced, listed_consent_at, listed_consent_text FROM team WHERE LOWER(email) = ?');
$row->execute([$email]);
$r = $row->fetch(PDO::FETCH_ASSOC);

ck('people: the directory sync still creates the row', (bool) $r && (int) $r['synced'] === 1);
ck('people: …but HIDDEN — holding a work address is not consent to be published',
   $r && (int) $r['active'] === 0);
ck('people: …and with no consent recorded, because none was given',
   $r && (string) $r['listed_consent_at'] === '');

/* The public page reads active = 1, so this is the assertion that
   actually matters: they are not on it. */
$publicEmails = array_map(
    static fn($t) => strtolower((string) ($t['email'] ?? '')),
    av_team_rows($pdo, true),
);
ck('people: and they do not appear on the public list', !in_array($email, $publicEmails, true));

/* ══ Saying yes ════════════════════════════════════════════════════════ */

ck('people: opting in publishes them', av_team_opt_in($pdo, $email));
$row->execute([$email]); $r = $row->fetch(PDO::FETCH_ASSOC);
ck('people: …and they are on the public list now',
   (int) $r['active'] === 1 && in_array($email, array_map(
       static fn($t) => strtolower((string) ($t['email'] ?? '')), av_team_rows($pdo, true)), true));

/* "Who said yes, when, and to what" has to have an answer. */
ck('people: the consent records when', (string) $r['listed_consent_at'] !== '');
ck('people: …and the exact words they agreed to, not a flag',
   (string) $r['listed_consent_text'] === AV_TEAM_LISTING_CONSENT
   && str_contains(AV_TEAM_LISTING_CONSENT, 'public People page'));

/* ══ Changing their mind ═══════════════════════════════════════════════ */

ck('people: opting out takes them down', av_team_opt_out($pdo, $email));
$row->execute([$email]); $r = $row->fetch(PDO::FETCH_ASSOC);
ck('people: …and clears the consent rather than leaving a stale yes beside a no',
   (int) $r['active'] === 0 && (string) $r['listed_consent_at'] === '' && (string) $r['listed_consent_text'] === '');

/* ══ A second sync does not quietly republish ══════════════════════════ */

av_team_sync_org_members($pdo);
$row->execute([$email]); $r = $row->fetch(PDO::FETCH_ASSOC);
ck('people: a later sync does not put somebody back who took themselves down',
   (int) $r['active'] === 0);

/* ══ The door the member uses ══════════════════════════════════════════ */

$src = (string) file_get_contents(AV_ROOT . '/portal/people-listing.php');
ck('people: the portal endpoint takes the address from the session, never the request',
   str_contains($src, "LmsAuth::user()") && !str_contains($src, "\$_POST['email']") && !str_contains($src, "\$_GET['email']"));
ck('people: …and a change of listing is CSRF-protected', str_contains($src, 'av_csrf_require()'));
ck('people: …and audited both ways',
   str_contains($src, "'listing_opt_in'") && str_contains($src, "'listing_opt_out'"));

$pdo->prepare('DELETE FROM team WHERE LOWER(email) = ?')->execute([$email]);
$pdo->prepare('DELETE FROM lms_users WHERE email = ?')->execute([$email]);
