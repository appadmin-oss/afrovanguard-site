<?php
/**
 * cacentre.php — the door from Afrovanguard into CACENTRE.
 *
 *     GET /cacentre                    → the CRM workspace
 *     GET /cacentre/crm/leads.php      → straight to the leads screen
 *     GET /cacentre?to=/crm/tasks.php  → the same, said the other way
 *
 * One address, from anywhere on the site, that does the right thing for
 * whoever clicks it:
 *
 *   signed in here        → minted an assertion and sent across
 *   not signed in         → sent to sign in, and brought back here after
 *   bridge not configured → told so, in words, with what to set
 *
 * Nothing is rendered on a success. This is a turnstile, not a page.
 *
 * ── WHY NOT A LINK WITH THE TOKEN IN IT ─────────────────────────────────────
 * An assertion lives sixty seconds. One baked into a page starts expiring the
 * moment the page renders, so a member who read for a minute before clicking
 * would arrive with a dead token and no idea why. It would also put a
 * credential into the page source, the browser cache and the back button.
 * Minting on the click means the token is always one redirect old.
 *
 * ── WHAT THIS DOES NOT DECIDE ───────────────────────────────────────────────
 * Whether they may actually use the CRM. That is CACENTRE's to answer, against
 * its own grants, and it answers it on every arrival. This endpoint will
 * happily hand a learner a valid assertion; the other side tells them, in
 * words, that they have no CRM role yet. Deciding here as well would put one
 * rule in two places, and the copy that drifts is always the one further from
 * the data.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
/* av_login_url() lives in partials.php, which bootstrap does not pull in.
   The version of this handoff that lived in portal/crm.php called it without
   loading it, so the one path it existed for — somebody arriving signed
   out — was a fatal error rather than a redirect to the sign-in page. */
require_once __DIR__ . '/lib/partials.php';

/* Both spellings reach the same place: the path after /cacentre, or ?to=.
   The path form is what a link in prose wants; the query form is what a
   redirect that already has a path to carry wants. */
$to = (string) ($_GET['to'] ?? '');
if ($to === '' && isset($_GET['p']) && is_string($_GET['p'])) $to = '/' . ltrim((string) $_GET['p'], '/');
$to = CacSso::path($to);

$u = LmsAuth::user();
if (!$u) {
    /* Sign in first, then come straight back here so the handoff continues
       rather than dropping them on the dashboard having forgotten why they
       were going anywhere. */
    header('Location: ' . av_login_url(CacSso::door($to)));
    exit;
}

if (!CacSso::ready()) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><title>CACENTRE sign-in unavailable</title>'
       . '<div style="max-width:34rem;margin:12vh auto;font:16px/1.6 system-ui,sans-serif;padding:0 1rem">'
       . '<h1 style="font-size:1.3rem">The CACENTRE sign-in is not set up yet.</h1>'
       . '<p>AV_SSO_SECRET is missing or too short in this site\'s .env, so no sign-in can be '
       . 'handed across. An administrator needs to set the same secret here and on CACENTRE.</p>'
       . '<p><a href="/portal/">Back to the portal</a></p></div>';
    exit;
}

header('Cache-Control: no-store');
header('Location: ' . CacSso::linkFor($u, $to));
exit;
