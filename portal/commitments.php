<?php
/**
 * portal/commitments.php — JSON API for a member's commitments (G-1).
 *
 *   GET  ?action=mine                        → { ok, open:[…], settled:[…], stats:{…} }
 *   GET  ?action=queue                       → { ok, queue:[…], members:[…] }
 *   POST ?action=done    {id}                → { ok }
 *   POST ?action=miss    {id, reason}        → { ok } | 422 with the reason rule
 *   POST ?action=assign  {id, member_id}     → { ok }
 *   POST ?action=cancel  {id}                → { ok }
 *
 * Two audiences, two authorisation rules:
 *
 *   • `mine`, `done`, `miss` are about the caller's OWN commitments. A member can
 *     close their own and nobody else's, and only their own miss reason is ever
 *     returned in full — every other row goes through Commitments::redactedFor().
 *
 *   • `queue`, `assign`, `cancel` are the chair's side, scoped to rooms the caller
 *     was actually in (Commitments::canManage()). Not any org member: reassigning a
 *     promise made in a meeting you were not part of is not an administrative
 *     convenience.
 *
 * Writes are same-origin + CSRF + rate-limited, like every other portal endpoint.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'mine');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

$writeGuard = function () use ($uid) { av_require_write($uid, 'commitments', 60); };

/** The caller's own commitment, or null — the gate for done/miss. */
$ownOrNull = function (int $id) use ($uid): ?array {
    $c = Commitments::get($id);
    return ($c && (int) $c['member_id'] === $uid) ? $c : null;
};

try {
    switch ($action) {
        case 'mine': {
            $open    = Commitments::forMember($uid, 'open', 100);
            $settled = array_merge(
                Commitments::forMember($uid, 'done', 40),
                Commitments::forMember($uid, 'missed', 40)
            );
            // The caller owns these, so their own miss reasons are theirs to read.
            $grace = Commitments::graceDays();
            $cut   = gmdate('Y-m-d', time() - $grace * 86400);
            foreach ($open as &$o) { $o['overdue'] = $o['due'] !== '' && $o['due'] < $cut; }
            unset($o);
            json_out(['ok' => true, 'open' => $open, 'settled' => $settled,
                      'stats' => Commitments::completion($uid),
                      'require_reason' => Commitments::requireMissReason()]);
        }

        case 'queue': {
            // Redacted: these belong to other people, or to nobody yet.
            $rows = array_map([Commitments::class, 'redactedFor'], Commitments::unassignedFor($uid, 60));
            // Names for the confirm control, so the chair picks rather than types.
            $members = [];
            try {
                foreach (Database::pdo()->query('SELECT id, name FROM lms_users ORDER BY name') as $m) {
                    $members[] = ['id' => (int) $m['id'], 'name' => (string) $m['name']];
                }
            } catch (Throwable $e) { /* the queue still works without the picker */ }
            json_out(['ok' => true, 'queue' => $rows, 'members' => $members]);
        }

        case 'done': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $id = (int) ($body['id'] ?? 0);
            if (!$ownOrNull($id)) json_out(['ok' => false, 'error' => 'That is not your commitment.'], 403);
            json_out(['ok' => Commitments::complete($id, $uid)]);
        }

        case 'miss': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $id = (int) ($body['id'] ?? 0);
            if (!$ownOrNull($id)) json_out(['ok' => false, 'error' => 'That is not your commitment.'], 403);
            // Reporting your own miss IS the self-report. Nobody else can set that
            // flag through this endpoint, which is what makes it mean anything.
            $res = Commitments::miss($id, (string) ($body['reason'] ?? ''), true);
            if (empty($res['ok'])) json_out(['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not record that.')], 422);
            json_out(['ok' => true]);
        }

        case 'assign': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $id = (int) ($body['id'] ?? 0);
            $c  = Commitments::get($id);
            if (!$c) json_out(['ok' => false, 'error' => 'Commitment not found.'], 404);
            if (!Commitments::canManage($uid, $c)) {
                json_out(['ok' => false, 'error' => 'Only someone who was in that meeting or session can confirm its owner.'], 403);
            }
            $member = (int) ($body['member_id'] ?? 0);
            if ($member <= 0) json_out(['ok' => false, 'error' => 'Pick who owns this.'], 422);
            json_out(['ok' => Commitments::assign($id, $member, $uid)]);
        }

        case 'cancel': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $id = (int) ($body['id'] ?? 0);
            $c  = Commitments::get($id);
            if (!$c) json_out(['ok' => false, 'error' => 'Commitment not found.'], 404);
            // Either the owner or somebody who was in the room may drop it — a
            // commitment the meeting decided against should not need a chair to hunt.
            if ((int) $c['member_id'] !== $uid && !Commitments::canManage($uid, $c)) {
                json_out(['ok' => false, 'error' => 'That is not yours to cancel.'], 403);
            }
            json_out(['ok' => Commitments::cancel($id)]);
        }

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[portal commitments] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Something went wrong.'], 500);
}
