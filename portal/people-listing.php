<?php
/**
 * portal/people-listing.php — am I on the public People page?
 *
 *   GET                 → { ok, known, listed, at, wording }
 *   POST ?action=on     → { ok, listed:true }   consent recorded
 *   POST ?action=off    → { ok, listed:false }  consent cleared
 *
 * Their own listing only: the address comes from the session, never from
 * the request, so nobody can publish or unpublish anybody else.
 *
 * WHY THIS EXISTS. The directory sync used to insert every organisation
 * account into the public team list as ACTIVE, so holding a work address
 * published your name and photograph on /people with nobody asked and
 * nobody told. The sync inserts hidden now; this is the door somebody
 * walks through to say yes, and the one they walk back through.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/people.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);

$email = strtolower(trim((string) ($u['email'] ?? '')));
if ($email === '') json_out(['ok' => false, 'error' => 'Your account has no email address.'], 422);

$pdo = Database::pdo();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method !== 'POST') {
    json_out(['ok' => true] + av_team_listing_state($pdo, $email));
}

av_csrf_require();

$action = (string) ($_GET['action'] ?? '');
if (!in_array($action, ['on', 'off'], true)) json_out(['ok' => false, 'error' => 'on or off.'], 422);

if ($action === 'on') {
    if (!av_team_opt_in($pdo, $email)) {
        /* No row means the directory sync has not seen them. Said plainly
           rather than as a failure: there is nothing wrong with them. */
        json_out(['ok' => false, 'error' => 'You are not in the team directory yet. It fills in overnight — try tomorrow.'], 404);
    }
    AdminAudit::log('people', 'listing_opt_in', $email, 'Agreed to appear on the public People page');
    json_out(['ok' => true, 'listed' => true] + av_team_listing_state($pdo, $email));
}

av_team_opt_out($pdo, $email);
AdminAudit::log('people', 'listing_opt_out', $email, 'Taken off the public People page at their request');
json_out(['ok' => true, 'listed' => false] + av_team_listing_state($pdo, $email));
