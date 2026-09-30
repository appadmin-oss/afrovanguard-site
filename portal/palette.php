<?php
/**
 * portal/palette.php — records for the command palette.
 *
 *   GET ?q=  → { ok, items:[{kind,label,sub,href}] }
 *
 * ── WHAT IT SEARCHES, AND WHY ONLY THAT ─────────────────────────────────────
 * Two things a member looks UP: another member, and something the centre owns.
 * Both are "where is X" questions, and both were previously answered by
 * opening a screen and typing into it a second time.
 *
 * Follow-ups are deliberately not here. They are a list somebody works down,
 * not a thing they look up, and they already sit on the Tasks pane with the
 * log attached to each row — finding one in a palette would only take them to
 * the same card.
 *
 * ── ONE CROSS-SITE CALL, NOT THREE ──────────────────────────────────────────
 * The directory is local and answers immediately. The register is CACENTRE's,
 * behind a three-second timeout, and it is the only remote call this endpoint
 * makes. Adding the second and third would put nine seconds of worst case
 * behind a keystroke.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/MemberDirectory.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);

$uid = (int) $u['id'];
$q   = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) json_out(['ok' => true, 'items' => [], 'q' => $q]);

if (!av_rate_ok('portal_palette', 90, 60)) {
    json_out(['ok' => false, 'error' => 'Too many searches — give it a moment.'], 429);
}

$items = [];

try {
    /* ── People ──────────────────────────────────────────────────────────
       The directory is already scoped to what this member may see; the
       palette does not widen it. */
    if (LmsAuth::isOrgMember($u)) {
        foreach (array_slice(MemberDirectory::listMembers($uid, $q, 6), 0, 6) as $m) {
            $name = trim((string) ($m['name'] ?? ''));
            if ($name === '') continue;
            $items[] = [
                'kind'  => 'Member',
                'label' => $name,
                'sub'   => trim((string) ($m['role'] ?? '')),
                'href'  => '/portal/#directory',
            ];
        }
    }

    /* ── Things ──────────────────────────────────────────────────────────
       The register, over the one signed door. Org members only, the same
       rule the Inventory pane uses — and the far side checks again. */
    if (LmsAuth::isOrgMember($u) && class_exists('CacInventory')) {
        $r = CacInventory::search($uid, ['q' => $q]);
        if ($r['ok']) {
            foreach (array_slice($r['items'], 0, 6) as $it) {
                $where = trim(implode(' · ', array_filter([
                    (string) ($it['site'] ?? ''), (string) ($it['location'] ?? ''),
                ])));
                $items[] = [
                    'kind'  => 'Item',
                    'label' => (string) $it['name'] . ((string) $it['tag'] !== '' ? ' · ' . $it['tag'] : ''),
                    'sub'   => trim($where . (($it['holder'] ?? '') !== '' ? ' — ' . $it['holder'] : '')),
                    'href'  => '/portal/#inventory',
                ];
            }
        }
    }

    json_out(['ok' => true, 'q' => $q, 'items' => array_slice($items, 0, 12)]);
} catch (Throwable $e) {
    error_log('[portal palette] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Server error.'], 500);
}
