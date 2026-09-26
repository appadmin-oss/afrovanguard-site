<?php
/**
 * portal/crm.php — the way into the CACENTRE CRM from the portal.
 *
 *     GET /portal/crm.php            → the CRM home
 *     GET /portal/crm.php?next=/crm/leads.php
 *
 * Mints a fresh assertion for whoever is signed in and redirects. Nothing is
 * rendered on a success; this is a turnstile, not a page.
 *
 * ── WHY A REDIRECT AND NOT A LINK WITH THE TOKEN IN IT ──────────────────────
 * An assertion lives sixty seconds. Baking one into the dashboard's HTML
 * would start it expiring the moment the page rendered, so a member who read
 * the page for a minute before clicking would arrive with a dead token and no
 * idea why. It would also put a credential into the page source, the browser
 * cache and the back button. Minting on the click means the token is always
 * one redirect old.
 *
 * ── WHAT THIS DOES NOT DECIDE ───────────────────────────────────────────────
 * Whether the member may actually use the CRM. That is CACENTRE's to answer,
 * against its own grants, and it answers it on every arrival. This endpoint
 * will happily hand a learner a valid assertion; the other side will tell
 * them, in words, that they have no CRM role. Deciding here as well would put
 * the same rule in two places, and the copy that drifts is always the one
 * further from the data.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';

$u = LmsAuth::user();
if (!$u) {
    /* Sign in first, then come straight back here so the handoff continues
       rather than dumping them on the dashboard. */
    $self = '/portal/crm.php' . (isset($_GET['next']) ? '?next=' . rawurlencode((string) $_GET['next']) : '');
    header('Location: ' . av_login_url($self));
    exit;
}

if (!CacSso::ready()) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>CRM unavailable</title>'
       . '<div style="max-width:34rem;margin:12vh auto;font:16px/1.6 system-ui,sans-serif;padding:0 1rem">'
       . '<h1 style="font-size:1.3rem">The CRM link is not set up yet.</h1>'
       . '<p>AV_SSO_SECRET is missing or too short in this site\'s .env, so no sign-in can be '
       . 'handed across. An administrator needs to set the same secret here and on CACENTRE.</p>'
       . '<p><a href="/portal/">Back to the portal</a></p></div>';
    exit;
}

$next = (string) ($_GET['next'] ?? '/crm/');
header('Location: ' . CacSso::linkFor($u, $next));
exit;
