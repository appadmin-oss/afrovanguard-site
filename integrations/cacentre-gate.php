<?php
/**
 * integrations/cacentre-gate.php — the CACENTRE gate talking to this site.
 *
 * The gate is a Cloudflare Worker at CACENTRE's door. It keeps no copy of
 * Afrovanguard's members; it asks here, and tells here. Every request is
 * signed by the gate with the shared GATE_PASS_SECRET (the Worker's
 * AV_PASS_SECRET), under a key for its purpose:
 *
 *   X-Gate-Timestamp: unix seconds (±300 s)
 *   X-Gate-Signature: sha256=hex(HMAC_SHA256(key, ts + "." + body))
 *   key = hex(HMAC_SHA256(secret, "cacentre-gate/v1/<purpose>"))
 *
 *   resolve   {op:"resolve", token}   a printed member card, X-NGV-YY-NNNN
 *             {op:"resolve", ref}     a member picked from search
 *             {op:"search", q}        find by name; nobody who may not come in
 *   report    {passages:[…]}          what happened at the door — see
 *                                     GateAttendance::report() for the answers
 *
 * A lookup signed with the report key is refused, and the other way round,
 * so a leaked delivery log cannot be replayed into a member search.
 *
 * Member passes are not looked up here at all: the gate checks those by
 * their signature. This answers for the card, and records the passage.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = static function (array $body, int $code = 200): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $out(['ok' => false, 'error' => 'POST only.'], 405);
if (!GatePass::ready()) $out(['ok' => false, 'error' => 'not-configured'], 404);

$raw = (string) file_get_contents('php://input', false, null, 0, 256 * 1024);
$ts  = (string) ($_SERVER['HTTP_X_GATE_TIMESTAMP'] ?? '');
$sig = (string) ($_SERVER['HTTP_X_GATE_SIGNATURE'] ?? '');
$in  = json_decode($raw, true);
if (!is_array($in)) $in = [];
$purpose = isset($in['op']) ? 'resolve' : 'report';
if (!GatePass::verify($purpose, $ts, $raw, $sig)) $out(['ok' => false, 'code' => 'bad_signature'], 401);

if ($purpose === 'report') {
    $passages = is_array($in['passages'] ?? null) ? $in['passages'] : [];
    if (!$passages) $out(['ok' => false, 'error' => 'no passages'], 400);
    $out(['ok' => true, 'results' => GateAttendance::report($passages)]);
}

$op = (string) $in['op'];
if ($op === 'formats') {
    /* The shapes of the portal's older printed cards (MemberCards). The NGV
       number is not one of them: the gate reads that itself. */
    $out(['ok' => true, 'formats' => MemberCards::gateFormats()]);
}
if ($op === 'resolve') {
    $token = strtoupper(trim((string) ($in['token'] ?? '')));
    $format = (string) ($in['format'] ?? '');
    if (str_starts_with($token, MemberCards::PREFIX) || $format !== '') {
        /* A secure card printed by this system, or an older printed card the
           gate decoded by one of our formats. */
        $hit = $format !== '' ? MemberCards::lookup(null, $format, (string) ($in['value'] ?? '')) : MemberCards::lookup($token);
        if (!$hit) $out(['ok' => false, 'code' => 'unknown', 'error' => 'Afrovanguard has no member card ' . ($format !== '' ? strtoupper(trim((string) ($in['value'] ?? ''))) : $token) . '.'], 404);
        if ($hit['void']) $out(['ok' => false, 'code' => 'void_card', 'error' => 'This card has been replaced. Use the newer card or the gate pass.'], 400);
        $r = GateAttendance::resolveRef((string) $hit['member_id']);
        if ($r['ok'] && $hit['kind'] === 'printed') $r['person']['detail'] = trim(($r['person']['detail'] ?? '') . ' · old printed card', ' ·');
        $out($r, $r['ok'] ? 200 : 404);
    }
    $r = $token !== '' ? GateAttendance::resolveCard($token) : GateAttendance::resolveRef((string) ($in['ref'] ?? ''));
    $out($r, $r['ok'] ? 200 : 404);
}
if ($op === 'search') {
    $out(['ok' => true, 'people' => GateAttendance::search((string) ($in['q'] ?? ''))]);
}
$out(['ok' => false, 'error' => 'unknown op'], 400);
