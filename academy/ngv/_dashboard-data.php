<?php
/**
 * academy/ngv/_dashboard-data.php — what the NGV dashboard shows, read once.
 *
 * Shared by the member portal's NGV section (/portal/#ngv) and the standalone
 * dashboard, so the two cannot disagree. Expects $u (the signed-in member) and
 * $c (Ngv::get()). Enrols the member as a participant on first sight — so it
 * is only included for somebody who IS a NextGen Vanguard (NgvMember::isVanguard).
 */
declare(strict_types=1);
$uid   = (int) $u['id'];
$first = trim(explode(' ', trim((string) ($u['name'] ?? 'Vanguard')))[0]) ?: 'Vanguard';
$csrf  = function_exists('av_csrf_token') ? av_csrf_token(43200) : ''; // 12h — matches "leave the tab open" use

/* Member state — from the SEPARATE NGV database. On first visit we enrol the
 * member and migrate any progress they saved before this lived in its own DB
 * (the old Prefs keys), so nobody loses what they'd tracked. */
$seed = ['name' => (string) ($u['name'] ?? ''), 'email' => (string) ($u['email'] ?? '')];
if (!NgvMember::participant($uid)) {
    $seed['track']      = Prefs::get($uid, 'ngv_track', '');
    $seed['phase']      = Prefs::get($uid, 'ngv_phase', '');
    $seed['books']      = Prefs::get($uid, 'ngv_books', '');
    $seed['focus_note'] = Prefs::get($uid, 'ngv_note', '');
}
$p = NgvMember::ensureParticipant($uid, $seed);

$myTrack = (string) ($p['track'] ?? '');
$myPhase = (string) ($p['phase'] ?? '');
$myBooks = str_pad(substr((string) ($p['books'] ?? ''), 0, 24), 24, '0');
$myNote  = (string) ($p['focus_note'] ?? '');
$booksRead = substr_count($myBooks, '1');
$BOOKS_TOTAL = 24;

/* Real account (training fee + membership + monthly commitment + any fines),
 * certifications and the full ledger — all from the NGV DB. Read-only: there is
 * no member-side action here that moves money, and there never should be. */
$account   = NgvLedger::account($uid);
$myPlan    = (string) ($p['plan'] ?? '');
$planOpts  = NgvMember::planOptions();
$myCerts   = NgvMember::certifications($uid);
$myEntries = $account['entries'];
/* Their own damage records. Read-only apart from reporting a new one: a
 * participant can say what happened, and only staff can attach money to it. */
$myDamage  = NgvDamage::forMember($uid);
/* Receipts. Derived from the payment rows, so this is not a second list that
 * can disagree with the ledger above it — it is the same rows, addressable. */
$myReceipts = NgvLedger::receiptsFor($uid);

/* Content slices */
$g       = static fn(array $a, string $k, string $d = ''): string => (string) ($a[$k] ?? $d);
$enabled = Ngv::isEnabled();
$phases  = is_array($c['phases'] ?? null) ? $c['phases'] : [];
$tracks  = is_array($c['tracks'] ?? null) ? $c['tracks'] : [];
$fees    = is_array($c['fees'] ?? null) ? $c['fees'] : [];
$sched   = is_array($c['schedule'] ?? null) ? $c['schedule'] : [];
$offices = is_array($c['offices'] ?? null) ? $c['offices'] : [];
$marquee = is_array($c['marquee'] ?? null) ? $c['marquee'] : [];
$why     = is_array($c['why'] ?? null) ? $c['why'] : [];
$ct      = is_array($c['contact'] ?? null) ? $c['contact'] : [];
$stats   = is_array($c['stats'] ?? null) ? $c['stats'] : [];

/* Phase labels for the "current phase" tile */
$phaseLabel = 'Not set';
if ($myPhase === 'done')      $phaseLabel = 'Completed 🎓';
elseif ($myPhase === '1' && isset($phases[0])) $phaseLabel = (string) ($phases[0]['title'] ?? 'Phase 1');
elseif ($myPhase === '2' && isset($phases[1])) $phaseLabel = (string) ($phases[1]['title'] ?? 'Phase 2');

$myTrackDesc = '';
foreach ($tracks as $t) { if (($t['name'] ?? '') === $myTrack) { $myTrackDesc = (string) ($t['desc'] ?? ''); break; } }

$e = 'e'; // htmlspecialchars helper name
/* Same theme source as the member portal, so a member who set dark there keeps
 * it here. */
$ptheme  = (($_COOKIE['av_portal_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$initial = strtoupper(mb_substr($first, 0, 1));
$owed    = (int) $account['payable'];
$feesOn  = !empty($account['enabled']);
$kindLabel = ['membership' => 'Membership fee', 'commitment' => 'Monthly commitment',
              'programme' => 'Training fee', 'fine' => 'Fine', 'adjustment' => 'Adjustment',
              'other' => 'Payment'];
/* A credit is not just "a payment": a waiver is the programme saying it is not
 * asking, and somebody reading their own account deserves to see which it was. */
$creditWord = ['payment' => 'Payment received', 'waiver' => 'Waived', 'writeoff' => 'Written off'];
