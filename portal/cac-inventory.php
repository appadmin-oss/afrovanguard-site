<?php
/**
 * portal/cac-inventory.php — the CACENTRE register, for the portal pane.
 *
 *   GET ?q=&category=&site=&status=&page=  → { ok, items:[…], total, page, pages, facets }
 *
 * ── WHY IT IS FETCHED RATHER THAN RENDERED WITH THE PAGE ────────────────────
 * The portal dashboard is the first thing a member sees, and reading the
 * register means an HTTP call to the other site with a three-second timeout.
 * Putting that in the page load would make every visit to the portal wait on
 * CACENTRE — including the visits that never open the register, which is most
 * of them. The pane asks for itself the first time somebody looks at it.
 *
 * ── IT IS A WINDOW, NOT A PROXY ─────────────────────────────────────────────
 * Only the five filters the far side reads are forwarded, and the member id
 * comes from this site's session and travels inside the signature — never
 * from the query string. Rate-limited, because an endpoint that makes a
 * request to another site on demand is an endpoint that can be used to make
 * a lot of them.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
/* The same rule the CACENTRE workspace link uses: this is for org members.
   The far side checks its own grants again on arrival — this is the filter,
   not the gate. */
if (!LmsAuth::isOrgMember($u)) {
    json_out(['ok' => false, 'error' => 'The register is for Afrovanguard members.'], 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_out(['ok' => false, 'error' => 'Items are changed in the console.'], 405);
}

$uid = (int) $u['id'];
if (!av_rate_ok('cac_inventory', 60, 60)) {
    json_out(['ok' => false, 'error' => 'Too many searches — give it a moment.'], 429);
}

if (!class_exists('CacInventory')) {
    json_out(['ok' => false, 'error' => 'The console is not linked from here.'], 503);
}

try {
    $r = CacInventory::search($uid, [
        'q'        => (string) ($_GET['q'] ?? ''),
        'category' => (string) ($_GET['category'] ?? ''),
        'site'     => (string) ($_GET['site'] ?? ''),
        'status'   => (string) ($_GET['status'] ?? ''),
        'page'     => (string) ($_GET['page'] ?? '1'),
    ]);

    if (!$r['ok']) {
        /* The far side's word, turned into this site's sentence. A member
           reading "unreachable" learns nothing; a member reading that the
           console did not answer knows to try again in a minute. */
        json_out(['ok' => false, 'error' => match ($r['error']) {
            'not-configured' => 'The link to the console is not set up here yet.',
            'unreachable'    => 'The console did not answer. Nothing is wrong with your search — try again in a moment.',
            'off'            => 'The centre does not keep its register in the console.',
            default          => 'The console refused that.',
        }, 'url' => $r['url']], 502);
    }

    json_out([
        'ok'     => true,
        'items'  => $r['items'],
        'total'  => $r['total'],
        'page'   => $r['page'],
        'pages'  => $r['pages'],
        'facets' => $r['facets'],
        'url'    => $r['url'],
    ]);
} catch (Throwable $e) {
    error_log('[portal cac-inventory] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Server error.'], 500);
}
