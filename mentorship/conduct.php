<?php
/**
 * mentorship/conduct.php — the one write endpoint for fines and awards
 * (lib/Conduct.php). Plain form posts, answered with a redirect, so it works
 * without JavaScript:
 *
 *   mentor     propose | withdraw                    → back to the mentor portal
 *   member     self_report | respond | appeal | self_withdraw → back to the portal (#bills)
 *   committee  approve | reject | bulk_* | kind | suggestions | promote | defer | appeal_hear
 *              journey | findings | brackets | money_apply  → the Quest desk
 *   superadmin member_add | member_remove | rule_item | rule_reset | rules_save
 *              advisor_ask | advisor_apply                    → the Quest desk
 *
 * The desk's script posts ajax=1 and gets JSON back, so a toggle or a bulk run
 * updates in place; without script every op still ends in a redirect.
 *
 * Who may do what is decided in Conduct, from the session — never from the
 * form. Same-origin and a CSRF token on every post.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit('POST only.'); }
require_same_origin();
$u = LmsAuth::user();
if (!$u) { header('Location: /login/?next=' . rawurlencode('/mentorship/')); exit; }
if (!av_csrf_valid((string) ($_POST['csrf'] ?? ''))) { http_response_code(403); exit('This page has been open a while — go back, reload and try again.'); }
/* A mentor proposes a handful; the desk toggles and decides many. */
$isMentorOp = in_array((string) ($_POST['op'] ?? ''), ['propose', 'withdraw', 'self_report', 'respond', 'appeal', 'self_withdraw'], true);
if (!av_rate_ok('conduct_' . (int) $u['id'], $isMentorOp ? 60 : 400, 600)) { http_response_code(429); exit('That is a lot at once. Give it a moment.'); }

$uid = (int) $u['id'];
$op = (string) ($_POST['op'] ?? '');
/* The desk's script asks for JSON (ajax=1) so a toggle or a bulk run updates
   in place; without script, every op still answers with a redirect. */
$ajax = (string) ($_POST['ajax'] ?? '') === '1';
$back = static function (string $url, array $q) use ($ajax): void {
    if ($ajax) {
        $ok = !isset($q['err']);
        json_out(['ok' => $ok] + ($ok ? $q : ['error' => (string) $q['err']]), $ok ? 200 : 422);
    }
    $hash = '';
    if (($p = strpos($url, '#')) !== false) { $hash = substr($url, $p); $url = substr($url, 0, $p); }
    /* The desk draws only the section it is asked for; name it. */
    if ($hash !== '' && str_starts_with($url, '/mentorship/compliance/')) $q = ['s' => substr($hash, 1)] + $q;
    header('Location: ' . $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($q) . $hash, true, 303);
    exit;
};
$res = static fn(array $r, string $done, array $extra = []): array => empty($r['ok']) ? ['err' => (string) ($r['error'] ?? 'Not saved.')] : ['done' => $done] + $extra;

if ($op === 'propose' || $op === 'withdraw') {
    $pid = (int) ($_POST['pairing_id'] ?? 0);
    $to = '/mentorship/mentor/?v=conduct&id=' . $pid . '&kind=' . rawurlencode((string) ($_POST['kind'] ?? 'award'));
    if (!Mentorship::isMentor($uid)) $back($to, ['err' => 'You do not have a mentor profile.']);
    $r = $op === 'propose'
        ? Conduct::propose($uid, $pid, $_POST)
        : Conduct::withdraw($uid, (int) ($_POST['case_id'] ?? 0));
    $back($to, empty($r['ok']) ? ['err' => (string) ($r['error'] ?? 'Not saved.')] : ['done' => $op === 'propose' ? 'proposed' : 'withdrawn']);
}

/* A member's own bills: report yourself, answer a bill, appeal a decision.
   Every check — whose case, how late, whether it is switched on — is Conduct's. */
if (in_array($op, ['self_report', 'respond', 'appeal', 'self_withdraw'], true)) {
    $to = '/portal/#bills';
    $r = match ($op) {
        'self_report'   => Conduct::selfReport($uid, $_POST),
        'respond'       => Conduct::respond($uid, (int) ($_POST['case_id'] ?? 0), (string) ($_POST['answer'] ?? ''), (string) ($_POST['note'] ?? '')),
        'appeal'        => Conduct::appeal($uid, (int) ($_POST['case_id'] ?? 0), (string) ($_POST['grounds'] ?? '')),
        'self_withdraw' => Conduct::withdraw($uid, (int) ($_POST['case_id'] ?? 0)),
    };
    $back($to, $res($r, $op));
}

$desk = '/mentorship/compliance/';
$ids = static fn(string $k): array => array_values(array_unique(array_filter(array_map('intval', (array) ($_POST[$k] ?? [])), static fn($i) => $i > 0)));
switch ($op) {
    case 'approve':
    case 'reject':
        $r = Conduct::decide((int) ($_POST['case_id'] ?? 0), $u, $op === 'approve', (string) ($_POST['note'] ?? ''));
        $back($desk . '#cases', empty($r['ok']) ? ['err' => (string) $r['error']] : ['done' => $op === 'approve' ? 'approved' : 'rejected']);

    /* Many at once, one verdict per case. The count was read on the page
       before the confirm; the cap is named; a case that cannot be decided is
       reported, never silently skipped; one audit line for the run. */
    case 'bulk_approve':
    case 'bulk_reject':
        $list = $ids('case_ids');
        $max = 200;
        if (!$list) $back($desk . '#cases', ['err' => 'Choose at least one case.']);
        if (count($list) > $max) $back($desk . '#cases', ['err' => 'At most ' . $max . ' cases at once.']);
        $approve = $op === 'bulk_approve';
        $note = (string) ($_POST['note'] ?? '');
        if (!$approve && trim($note) === '') $back($desk . '#cases', ['err' => 'Say why they are rejected — every mentor is told.']);
        $done = 0; $failed = [];
        foreach ($list as $cid) {
            $r = Conduct::decide($cid, $u, $approve, $note);
            if (!empty($r['ok'])) $done++; else $failed[] = ['id' => $cid, 'error' => (string) ($r['error'] ?? 'Not decided.')];
        }
        if (class_exists('AdminAudit')) {
            try { AdminAudit::log('quest', $approve ? 'bulk_approve' : 'bulk_reject', count($list) . ' case(s)', $done . ' decided, ' . count($failed) . ' refused', null, 'user#' . $uid); }
            catch (Throwable $e) { /* best-effort */ }
        }
        if ($ajax) json_out(['ok' => $failed === [], 'partial' => $failed !== [] && $done > 0, 'done' => $done, 'failed' => $failed]);
        $back($desk . '#cases', $failed ? ['err' => $done . ' decided; ' . count($failed) . ' could not be: ' . $failed[0]['error']] : ['done' => $approve ? 'approved' : 'rejected']);

    case 'kind':
        $r = Conduct::setPairingKind((int) ($_POST['pairing_id'] ?? 0), (string) ($_POST['kind'] ?? ''), $u);
        $back($desk . '#people', $res($r, 'kind'));
    case 'member_add':
        $r = Conduct::addMember((string) ($_POST['email'] ?? ''), $u);
        $back($desk . '#people', $res($r, 'added'));
    case 'member_remove':
        $r = Conduct::removeMember((int) ($_POST['user_id'] ?? 0), $u);
        $back($desk . '#people', $res($r, 'removed'));

    /* ── Catalogue and rules: superadmins (QuestRules::canEdit) ── */
    case 'rule_item':
        $change = [];
        if (isset($_POST['enabled'])) $change['enabled'] = (string) $_POST['enabled'] === '1';
        if (isset($_POST['value'])) {
            $v = trim((string) $_POST['value']);
            $change['value'] = $v === '' ? null : (int) preg_replace('/[^\d]/', '', $v);
        }
        $r = QuestRules::set((string) ($_POST['kind'] ?? ''), (string) ($_POST['id'] ?? ''), $change, $u, (string) ($_POST['note'] ?? ''));
        $back($desk . '#catalogue', $res($r, 'rule', ['item' => $r['item'] ?? null]));
    case 'rule_reset':
        $r = QuestRules::reset((string) ($_POST['kind'] ?? ''), (string) ($_POST['id'] ?? ''), $u);
        $back($desk . '#catalogue', $res($r, 'rule', ['item' => $r['item'] ?? null]));
    case 'rules_save':
        if (!QuestRules::canEdit($u)) $back($desk . '#rules', ['err' => 'Only a superadmin changes the Quest\'s rules.']);
        $vals = [];
        foreach (QuestDesk::ruleKeys() as $k) {
            $field = str_replace('.', '__', $k);
            $def = QuestDesk::ruleDef($k);
            if (($def['type'] ?? '') === 'bool') $vals[$k] = isset($_POST[$field]) && (string) $_POST[$field] === '1' ? '1' : '0';
            elseif (isset($_POST[$field])) $vals[$k] = (string) $_POST[$field];
        }
        $r = AvRules::save($vals, (string) ($u['email'] ?? 'desk'));
        $back($desk . '#rules', !empty($r['ok']) ? ['done' => 'rules'] : ['err' => implode(' ', array_map(static fn($k, $m) => $k . ': ' . $m, array_keys((array) ($r['errors'] ?? [])), (array) ($r['errors'] ?? []))) ?: 'Not saved.']);

    /* ── Suggestions and promotions: the committee ── */
    case 'suggest_accept':
        $r = QuestLadder::accept((int) ($_POST['id'] ?? 0), $u, (string) ($_POST['note'] ?? ''));
        $back($desk . '#suggestions', $res($r, 'accepted'));
    case 'suggest_dismiss':
        $r = QuestLadder::dismiss((int) ($_POST['id'] ?? 0), $u, (string) ($_POST['note'] ?? ''));
        $back($desk . '#suggestions', $res($r, 'dismissed'));
    case 'promote':
        $r = QuestDesk::promote((int) ($_POST['user_id'] ?? 0), (string) ($_POST['to'] ?? ''), $u);
        $back($desk . '#promotions', $res($r, 'promoted'));
    case 'defer':
        if (!Conduct::canDecide($u)) $back($desk . '#promotions', ['err' => 'Only the committee or a superadmin defers a promotion.']);
        $r = Promotion::defer((int) ($_POST['user_id'] ?? 0), (string) ($_POST['note'] ?? ''), (string) ($u['email'] ?? ''));
        $back($desk . '#promotions', $res($r, 'deferred'));

    /* ── A member's record ── */
    case 'journey':
        $r = QuestRecord::setJourney((int) ($_POST['member_id'] ?? 0), (string) ($_POST['level'] ?? ''), $u, (string) ($_POST['note'] ?? ''));
        $back($desk . '#members', $res($r, 'journey'));
    case 'finding_raise':
        $r = QuestRecord::raiseFinding((int) ($_POST['member_id'] ?? 0), (string) ($_POST['finding_kind'] ?? ''), (string) ($_POST['detail'] ?? ''), $u);
        $back($desk . '#members', $res($r, 'finding', ['id' => (int) ($r['id'] ?? 0)]));
    case 'finding_resolve':
        $r = QuestRecord::resolveFinding((int) ($_POST['id'] ?? 0), (string) ($_POST['note'] ?? ''), $u);
        $back($desk . '#members', $res($r, 'resolved'));
    case 'member_bracket':
        $r = QuestMoney::setMemberBracket((int) ($_POST['member_id'] ?? 0), (int) ($_POST['bracket_id'] ?? 0), $u);
        $back($desk . '#members', $res($r, 'bracket'));

    /* ── Money ── */
    case 'bracket_save':
        $r = QuestMoney::saveBracket($_POST, $u);
        $back($desk . '#money', $res($r, 'bracket'));
    case 'bracket_delete':
        $r = QuestMoney::deleteBracket((int) ($_POST['id'] ?? 0), $u);
        $back($desk . '#money', $res($r, 'bracket'));
    case 'money_apply':
        $r = QuestMoney::apply((string) ($_POST['month'] ?? ''), $u);
        $back($desk . '#money', empty($r['ok']) ? ['err' => (string) $r['error']] : ['done' => 'month', 'ran' => (int) $r['ran'], 'offset' => (int) $r['offset']]);

    /* ── Appeals: heard by somebody who had no part in the case ── */
    case 'appeal_hear':
        $r = Conduct::hearAppeal((int) ($_POST['appeal_id'] ?? 0), $u, (string) ($_POST['outcome'] ?? ''), (string) ($_POST['note'] ?? ''),
            (int) preg_replace('/\D+/', '', (string) ($_POST['new_value'] ?? '')));
        $back($desk . '#appeals', $res($r, 'heard'));

    /* ── The advisor ── */
    case 'advisor_ask':
        $r = QuestAdvisor::ask((string) ($_POST['mode'] ?? ''), $u);
        $back($desk . '#advisor', $res($r, 'advice'));
    case 'advisor_apply':
        $r = QuestAdvisor::apply((int) ($_POST['advice_id'] ?? 0), (int) ($_POST['index'] ?? -1), $u);
        $back($desk . '#advisor', $res($r, 'applied'));
}
http_response_code(400);
echo 'Unknown action.';
