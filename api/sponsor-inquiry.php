<?php
/**
 * STS sponsorship inquiry capture — the backend the sponsor wizard POSTs to.
 *
 * Captures intent (program, amount, cadence, contact), stores it, notifies the
 * team (email + the event/webhook bus), and returns a pre-filled handoff URL to
 * the main Afrovanguard payment page. No charge happens here — this is intent.
 *
 * POST (FormData): csrf_token, full_name, email, phone, organization,
 *                  intended_amount_ngn, frequency, selected_program
 * → { ok, ref, handoff_url }
 */
require_once __DIR__ . '/../lib/bootstrap.php';
if (function_exists('send_security_headers')) send_security_headers('public');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
if (!av_rate_ok('sts_sponsor', 12, 300)) json_out(['ok' => false, 'error' => 'Too many requests — please try again shortly.'], 429);

// Honeypot — a filled hidden field means a bot. Accept silently, store nothing.
if (trim((string) ($_POST['website'] ?? '')) !== '') json_out(['ok' => true, 'ref' => '', 'handoff_url' => '#']);

// Stateless HMAC CSRF token minted by /api/csrf.php.
if (!av_csrf_valid((string) ($_POST['csrf_token'] ?? ''))) {
    json_out(['ok' => false, 'error' => 'Your session expired — please reload the page and try again.'], 403);
}

$name    = trim((string) ($_POST['full_name'] ?? ''));
$email   = trim((string) ($_POST['email'] ?? ''));
$phone   = trim((string) ($_POST['phone'] ?? ''));
$org     = trim((string) ($_POST['organization'] ?? ''));
$amount  = (int) ($_POST['intended_amount_ngn'] ?? 0);
$freq    = (string) ($_POST['frequency'] ?? 'monthly');
$program = (string) ($_POST['selected_program'] ?? '');

if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['ok' => false, 'error' => 'Please add your name and a valid email.'], 422);
}
$freq    = in_array($freq, ['monthly', 'quarterly', 'annually', 'one_time'], true) ? $freq : 'monthly';
$program = in_array($program, ['next-gen', 'summer-school', 'lcasp', 'street-storm', 'any'], true) ? $program : 'any';
$amount  = max(0, min(100000000, $amount));

try {
    $pdo = Database::pdo();
    $st  = $pdo->prepare(
        "INSERT INTO sponsorships (ref, full_name, email, phone, organization, program, amount_ngn, frequency, ip)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $ref = '';
    for ($try = 0; $try < 5; $try++) {
        $ref = 'SPN-' . strtoupper(bin2hex(random_bytes(4)));
        try {
            $st->execute([$ref, mb_substr($name, 0, 160), mb_substr($email, 0, 190), mb_substr($phone, 0, 40),
                mb_substr($org, 0, 160), $program, $amount, $freq, av_client_ip()]);
            break;
        } catch (Throwable $e) {
            if ($try === 4) throw $e; // exhausted unique-ref retries
        }
    }
} catch (Throwable $e) {
    error_log('[sponsor-inquiry] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'We could not save that just now — please try again.'], 500);
}

$amountFmt  = '₦' . number_format($amount);
$adminEmail = defined('ADMIN_EMAIL') && ADMIN_EMAIL ? ADMIN_EMAIL : 'cacentre@afrovanguard.org.ng';

// Notify the team — email (branded) + the event/webhook bus. Best-effort.
try {
    if (class_exists('Mailer') && Mailer::configured()) {
        $rows = [
            'Sponsor'      => $name,
            'Email'        => $email,
            'Phone'        => $phone !== '' ? $phone : '—',
            'Organisation' => $org !== '' ? $org : '—',
            'Program'      => $program,
            'Amount'       => $amountFmt . ' / ' . str_replace('_', '-', $freq),
            'Reference'    => $ref,
        ];
        $html = Mailer::shell('New sponsorship inquiry', $rows, null, 'A sponsor submitted intent via the STS site.');
        Mailer::send($adminEmail, '[STS Sponsorship] ' . $name . ' — ' . $amountFmt, $html);
    }
} catch (Throwable $e) { error_log('[sponsor-inquiry] mail: ' . $e->getMessage()); }

av_emit_event('sponsor.inquiry', [
    'ref' => $ref, 'program' => $program, 'amount_ngn' => $amount, 'frequency' => $freq,
    'name' => $name, 'email' => $email,
]);

// Hand off to the main Afrovanguard payment page with everything pre-filled.
$base    = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://afrovanguard.org.ng';
$handoff = $base . '/donate?' . http_build_query([
    'ref' => $ref, 'amount' => $amount, 'freq' => $freq, 'program' => $program, 'src' => 'sts-sponsor',
]);

json_out(['ok' => true, 'ref' => $ref, 'handoff_url' => $handoff]);
