<?php
require_once __DIR__ . '/lib/security.php';

/**
 * process-contact.php — Afrovanguard Contact Form Processor
 *
 * POST action=submit_contact   — validate, store, auto-reply + admin notify
 * GET  action=get_messages      — admin: list contact submissions (token-gated)
 *
 * Security model mirrors process-donation.php:
 *   - config.php loaded server-side only (never sent to browser)
 *   - Rate-limited per IP (5 submissions / 15 min)
 *   - Honeypot anti-spam field
 *   - All input sanitised before storage or email
 *   - contacts.json blocked from web by .htaccess
 *   - Attachment: virus extension check + 10 MB cap + stored outside webroot
 */

/* ── Headers ────────────────────────────────────────────────── */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Powered-By: ');

/* ── CORS ───────────────────────────────────────────────────── */
// Fix M-03: include www. subdomain so Paystack/forms work on both origins
$allowedOrigins = ['https://afrovanguard.org.ng', 'https://www.afrovanguard.org.ng'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ── Config ─────────────────────────────────────────────────── */
// Self-bootstrap like process-donation.php: load .env, promote SMTP_*/FROM_*/
// ADMIN_EMAIL to constants, and pull in config.php only when it is present. This
// makes the contact form work on a pure-.env deployment (no config.php) instead
// of hard-500ing — the form only needs SMTP/recipient settings, which may come
// from the environment.
require_once __DIR__ . '/lib/bootstrap.php';

// Contact-specific settings that normally live in config.php — default them so
// the form runs even when config.php is absent (env-only deployments).
foreach ([
    'ENABLE_EMAIL_NOTIFICATIONS' => true, 'ENABLE_ADMIN_NOTIFICATIONS' => true,
    'FROM_NAME' => 'Afrovanguard', 'ADMIN_EMAIL' => 'cacentre@afrovanguard.org.ng',
] as $__k => $__v) { if (!defined($__k)) define($__k, $__v); }
if (!defined('FROM_EMAIL')) define('FROM_EMAIL', defined('SMTP_USERNAME') ? SMTP_USERNAME : 'donations@afrovanguard.org.ng');

/* ── PHPMailer — guarded load (Composer or manual) ─────────── */
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/PHPMailer-master/src/PHPMailer.php')) {
    require_once __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer-master/src/SMTP.php';
    require_once __DIR__ . '/PHPMailer-master/src/Exception.php';
} else {
    error_log('[AV-Contact] PHPMailer not installed. Email notifications disabled.');
    define('AV_NO_MAILER', true);
}
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ═══════════════════════════════════════════════════════════
   DATA STORE  —  contacts.json  (file-locked, not web-accessible)
   ═══════════════════════════════════════════════════════════ */
define('CONTACT_FILE', __DIR__ . '/contacts.json');

function defaultContactData(): array {
    return [
        'version'      => 1,
        'last_updated' => date('c'),
        'totals'       => ['total' => 0, 'unread' => 0],
        'contacts'     => [],
    ];
}

function readContacts(): array {
    if (!file_exists(CONTACT_FILE)) return defaultContactData();
    $fp = @fopen(CONTACT_FILE, 'r');
    if (!$fp) return defaultContactData();
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $d = json_decode($raw, true);
    return (is_array($d) && isset($d['contacts'])) ? $d : defaultContactData();
}

function writeContact(array $entry): bool {
    $isNew = !file_exists(CONTACT_FILE);
    // 'c+' = read+write (create, no truncate). Plain 'c' is write-only, so the
    // stream_get_contents() read below failed ("Bad file descriptor") and every
    // submission overwrote the file with only the newest entry — losing history.
    $fp = @fopen(CONTACT_FILE, 'c+');
    if (!$fp) { error_log('[AV-Contact] Cannot open contacts.json'); return false; }
    flock($fp, LOCK_EX);
    $raw  = stream_get_contents($fp) ?: '';
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data) || !isset($data['contacts'])) $data = defaultContactData();

    array_unshift($data['contacts'], $entry);
    if (count($data['contacts']) > 1000) {
        $data['contacts'] = array_slice($data['contacts'], 0, 1000);
    }
    $data['totals']['total']  = ($data['totals']['total']  ?? 0) + 1;
    $data['totals']['unread'] = ($data['totals']['unread'] ?? 0) + 1;
    $data['last_updated']     = date('c');

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    ftruncate($fp, 0); rewind($fp); fwrite($fp, $json); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    if ($isNew && file_exists(CONTACT_FILE)) @chmod(CONTACT_FILE, 0600);
    return true;
}

/* ═══════════════════════════════════════════════════════════
   HELPERS
   ═══════════════════════════════════════════════════════════ */
function esc(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clean(string $v, int $max = 255): string {
    return mb_substr(trim(strip_tags($v)), 0, $max);
}

/* ── Rate limiter (mirrors process-donation.php) ──────────── */
function rateLimit(string $key, int $max = 5, int $window = 900): bool {
    // 5 contact submissions per 15 minutes per IP — stricter than donations
    $dir  = sys_get_temp_dir() . '/av_contact_rl/';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . md5($key) . '.rl';
    $now  = time();
    $fp   = @fopen($file, 'c+');
    if (!$fp) return true; // fail open
    flock($fp, LOCK_EX);
    $raw  = stream_get_contents($fp) ?: '[]';
    $hits = array_filter(
        json_decode($raw, true) ?? [],
        fn($ts) => ($now - $ts) < $window
    );
    $allow = count($hits) < $max;
    if ($allow) $hits[] = $now;
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode(array_values($hits)));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return $allow;
}

/* ── mail_send_contact (mirrors process-donation.php) ──────── */
function mail_send_contact(string $to, string $subject, string $html, ?array $attachment = null): bool {
    if (!ENABLE_EMAIL_NOTIFICATIONS) return true;
    if (defined('AV_NO_MAILER')) {
        error_log("[AV-Contact] mail_send_contact skipped — PHPMailer not installed. To: {$to}");
        return false;
    }
    // SMTP may be unconfigured on a pure-.env deployment. Skip cleanly rather than
    // dereferencing undefined SMTP_* constants (a fatal in PHP 8) — the submission
    // is already stored, so the form still succeeds without email.
    if (!defined('SMTP_HOST') || (string) SMTP_HOST === '' || !defined('SMTP_USERNAME') || !defined('SMTP_PASSWORD') || !defined('SMTP_PORT')) {
        error_log("[AV-Contact] mail_send_contact skipped — SMTP not configured. To: {$to}");
        return false;
    }
    $m = new PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host       = SMTP_HOST;
        $m->SMTPAuth   = true;
        $m->Username   = SMTP_USERNAME;
        $m->Password   = SMTP_PASSWORD;
        $m->Port       = SMTP_PORT;
        $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $m->CharSet    = 'UTF-8';
        $m->Timeout    = 20;
        $m->setFrom(FROM_EMAIL, FROM_NAME);
        $m->addAddress($to);
        $m->addReplyTo(FROM_EMAIL, FROM_NAME);
        $m->isHTML(true);
        $m->Subject  = $subject;
        $m->Body     = $html;
        $m->AltBody  = strip_tags(str_replace(['<br>', '<br/>', '<br />'], PHP_EOL, $html));
        if ($attachment && !empty($attachment['path']) && file_exists($attachment['path'])) {
            $m->addAttachment($attachment['path'], $attachment['name'] ?? basename($attachment['path']));
        }
        $m->send();
        return true;
    } catch (Exception $e) {
        error_log('[AV-Contact] Mail to ' . $to . ': ' . $m->ErrorInfo);
        return false;
    }
}

/* ═══════════════════════════════════════════════════════════
   EMAIL TEMPLATES  (same base CSS / wrap as process-donation.php)
   ═══════════════════════════════════════════════════════════ */
function baseCss(): string {
    return 'body,table,td,a{-webkit-text-size-adjust:100%;}table{border-collapse:collapse;}'
        . 'body{margin:0;padding:0;background:#f4f4f4;font-family:\'Montserrat\',-apple-system,BlinkMacSystemFont,sans-serif;color:#111;}'
        . '.wrap{max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;}'
        . '.hd{background:#0d1220;border-bottom:4px solid #f3b416;padding:20px 40px;text-align:center;}'
        . '.brand{font-family:\'Cormorant\',Georgia,serif;font-size:26px;font-weight:700;color:#fff;}'
        . '.gold{color:#f3b416;}.tag{font-size:10px;letter-spacing:2px;font-weight:700;color:#f3b416;text-transform:uppercase;margin-top:4px;}'
        . '.sec{padding:32px 40px;}.ft{background:#fafafa;border-top:1px solid #eee;padding:24px 32px;text-align:center;}'
        . 'h1{font-family:\'Cormorant\',Georgia,serif;font-size:28px;margin:0 0 20px;color:#111;line-height:1.2;}'
        . 'p{font-size:15px;line-height:1.65;margin:0 0 16px;color:#4b5563;}strong{color:#111;}'
        . '.badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;background:#fffbf0;color:#8d480e;padding:6px 10px;border-radius:999px;margin-bottom:16px;}'
        . '.card{background:#fffbf0;border:1px solid #ffe688;border-radius:8px;padding:20px;margin-bottom:24px;}'
        . '.lbl{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#8d480e;margin-bottom:4px;}'
        . '.val{font-size:15px;font-weight:600;color:#111;}.row{margin-top:14px;}'
        . '.btn{display:inline-block;padding:14px 24px;background:#f3b416;color:#0d1220!important;text-decoration:none;border-radius:6px;font-weight:700;font-size:15px;}'
        . '.ft p{font-size:11px;color:#6b7280;margin:0 0 8px;}.ft a{color:#6b7280;text-decoration:underline;}'
        . '@media(max-width:600px){.sec,.hd{padding:24px 20px!important;}h1{font-size:22px!important;}.btn{width:100%!important;display:block;text-align:center;box-sizing:border-box;}}';
}

function wrap(string $badge, string $body, string $note = ''): string {
    $css  = baseCss();
    $year = date('Y');
    return "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><style>{$css}</style></head><body>"
        . "<center><table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr><td style=\"padding:40px 10px;\">"
        . "<table class=\"wrap\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">"
        . "<tr><td class=\"hd\" align=\"center\"><div class=\"brand\">AFROVANGUARD<span class=\"gold\">.</span></div>"
        . "<div class=\"tag\">The Force for Good</div></td></tr>"
        . "<tr><td class=\"sec\"><span class=\"badge\">{$badge}</span><br>{$body}</td></tr>"
        . "<tr><td class=\"ft\">"
        . ($note ? "<p>{$note}</p>" : '')
        . "<p>Afrovanguard &middot; CACENTRE, Alimosho, Lagos</p>"
        . "<p><a href=\"mailto:contact@afrovanguard.org.ng\">contact@afrovanguard.org.ng</a> &middot; <a href=\"https://afrovanguard.org.ng/privacy-policy/\">Privacy Policy</a></p>"
        . "<p>&copy; {$year} Afrovanguard. All rights reserved.</p>"
        . "</td></tr></table></td></tr></table></center></body></html>";
}

/* ── Auto-reply sent to the person who contacted us ────────── */
function autoReplyHtml(array $d): string {
    $name    = esc($d['name']);
    $purpose = esc($d['purpose_label']);
    $ref     = esc($d['ref']);
    $body    = "<h1>We received your message &#128338;</h1>"
        . "<p><strong>Hello {$name},</strong></p>"
        . "<p>Thank you for reaching out to Afrovanguard. Your message has been received and a member of our team will respond within <strong>24&ndash;48 hours</strong> on business days (Monday&ndash;Friday, 9 am&ndash;5 pm WAT).</p>"
        . "<div class=\"card\">"
        . "<div class=\"lbl\">Your Enquiry</div><div class=\"val\">{$purpose}</div>"
        . "<div class=\"row\"><div class=\"lbl\">Reference</div>"
        . "<div class=\"val\" style=\"font-size:13px;word-break:break-all;\">{$ref}</div></div>"
        . "<div class=\"row\"><div class=\"lbl\">Received</div>"
        . "<div class=\"val\" style=\"font-size:13px;\">" . date('F j, Y \a\t g:i A T') . "</div></div>"
        . "</div>"
        . "<p>In the meantime, you can:</p>"
        . "<ul style=\"padding-left:20px;margin-bottom:20px;\">"
        . "<li style=\"margin-bottom:8px;\">Explore our <a href=\"https://afrovanguard.org.ng/projects/\" style=\"color:#f3b416;\">Projects</a></li>"
        . "<li style=\"margin-bottom:8px;\">Support our work at <a href=\"https://afrovanguard.org.ng/donate/\" style=\"color:#f3b416;\">afrovanguard.org.ng/donate</a></li>"
        . "<li>Follow us on Instagram &amp; YouTube @afrovanguard for impact stories</li>"
        . "</ul>"
        . "<p>For urgent matters, reach us directly via WhatsApp: <strong>+234 903 777 6318</strong></p>"
        . "<p style=\"margin-top:28px;\">Warm regards,<br><strong>The Afrovanguard Team</strong><br>"
        . "<span style=\"font-size:12px;color:#f3b416;text-transform:uppercase;\">The Force for Good</span></p>";
    return wrap('&#9993; Message Received', $body, 'You received this because you submitted a message at afrovanguard.org.ng/contact.');
}

/* ── Internal admin notification ───────────────────────────── */
function adminNotifyHtml(array $d): string {
    $year = date('Y');
    $css  = baseCss();
    $purposeMap = [
        'volunteer' => '&#128101; Volunteering',
        'business'  => '&#128188; Business / Partnership',
        'tech'      => '&#128187; Technology (Techome)',
        'media'     => '&#127909; Media (Mediapro)',
        'career'    => '&#127891; Career Development',
        'creative'  => '&#127912; Creative / Cultural (Africa GATES)',
        'kingdom'   => '&#9733; Kingdom Advancement',
        'donation'  => '&#128176; Donation Enquiry',
        'general'   => '&#128172; General Enquiry',
    ];
    $purposeLabel = $purposeMap[$d['purpose'] ?? 'general'] ?? '&#128172; General Enquiry';

    $rows = [
        ['Purpose',   $purposeLabel],
        ['Name',      esc($d['name'])],
        ['Email',     esc($d['email'])],
        ['Phone',     esc($d['phone'] ?: '—')],
        ['Location',  esc($d['location'] ?: '—')],
        ['Subject',   esc($d['subject'] ?: '—')],
        ['Reference', esc($d['ref'])],
        ['Submitted', date('Y-m-d H:i:s T')],
    ];

    $tableRows = '';
    foreach ($rows as [$label, $value]) {
        $tableRows .= "<tr>"
            . "<td style=\"padding:8px 14px 8px 0;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8d480e;white-space:nowrap;vertical-align:top;\">{$label}</td>"
            . "<td style=\"padding:8px 0;font-size:14px;color:#111;\">{$value}</td>"
            . "</tr>";
    }

    $msgBody = nl2br(esc($d['message']));
    $hasAttachment = !empty($d['attachment_name'])
        ? "<p style=\"font-size:13px;color:#4b5563;\">&#128206; Attachment: <strong>" . esc($d['attachment_name']) . "</strong></p>"
        : '';

    $body = "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><style>{$css}</style></head><body>"
        . "<center><table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr><td style=\"padding:40px 10px;\">"
        . "<table class=\"wrap\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">"
        . "<tr><td class=\"hd\" align=\"center\">"
        . "<div class=\"brand\">AFROVANGUARD<span class=\"gold\">.</span></div>"
        . "<div class=\"tag\">New Contact Submission</div></td></tr>"
        . "<tr><td class=\"sec\">"
        . "<span class=\"badge\">&#128395; New Message</span><br>"
        . "<h1 style=\"font-size:22px;\">Contact Form Submission</h1>"
        . "<div class=\"card\"><table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">{$tableRows}</table></div>"
        . "<div style=\"background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;\">"
        . "<div style=\"font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#8d480e;margin-bottom:10px;\">Message</div>"
        . "<div style=\"font-size:14px;color:#374151;line-height:1.7;\">{$msgBody}</div>"
        . "</div>"
        . $hasAttachment
        . "<a href=\"mailto:" . esc($d['email']) . "?subject=Re:%20Your%20Afrovanguard%20Enquiry%20(" . urlencode($d['ref']) . ")\" class=\"btn\" style=\"margin-bottom:20px;\">Reply to {$d['name']} &rarr;</a>"
        . "</td></tr>"
        . "<tr><td class=\"ft\"><p>&copy; {$year} Afrovanguard. Internal notification &mdash; do not forward.</p></td></tr>"
        . "</table></td></tr></table></center></body></html>";

    return $body;
}

/* ═══════════════════════════════════════════════════════════
   PURPOSE LABELS
   ═══════════════════════════════════════════════════════════ */
$PURPOSE_LABELS = [
    'volunteer' => 'Volunteering — Street-To-Stardom / Summer School',
    'business'  => 'Business — BEC / Partnerships',
    'tech'      => 'Technology — Techome',
    'media'     => 'Media — Mediapro',
    'career'    => 'Career Development — Career Hub',
    'creative'  => 'Creative / Cultural — Africa GATES',
    'kingdom'   => 'Kingdom Advancement Project',
    'donation'  => 'Donation Enquiry',
    'general'   => 'General Enquiry',
];

/* ═══════════════════════════════════════════════════════════
   ROUTING
   ═══════════════════════════════════════════════════════════ */
$method = $_SERVER['REQUEST_METHOD'];

/* ── GET: admin message viewer (token-gated) ─────────────── */
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'get_messages') {
        $token = trim($_GET['admin_token'] ?? '');
        if (!defined('ADMIN_TOKEN') || !hash_equals(ADMIN_TOKEN, $token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        $limit  = min((int)($_GET['limit']  ?? 20), 100);
        $page   = max((int)($_GET['page']   ??  0),   0);
        $filter = strtolower(trim($_GET['purpose'] ?? 'all'));
        $data   = readContacts();
        $all    = array_filter($data['contacts'], function ($c) use ($filter) {
            return $filter === 'all' || ($c['purpose'] ?? '') === $filter;
        });
        $all     = array_values($all);
        $total   = count($all);
        $paged   = array_slice($all, $page * $limit, $limit);
        // Strip attachment paths from response (server-side only)
        $safe = array_map(function ($c) {
            unset($c['attachment_path']);
            return $c;
        }, $paged);
        echo json_encode(['success' => true, 'messages' => $safe, 'total' => $total, 'unread' => $data['totals']['unread'] ?? 0]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

/* ── POST ─────────────────────────────────────────────────── */
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = '';
$input  = [];

// Accept both JSON body and multipart/form-data (for file uploads)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $raw    = file_get_contents('php://input');
    $input  = json_decode($raw, true) ?? [];
    $action = $input['action'] ?? '';
} else {
    // multipart/form-data or application/x-www-form-urlencoded
    $input  = $_POST;
    $action = $input['action'] ?? '';
}

/* ── submit_contact ───────────────────────────────────────── */
if ($action === 'submit_contact') {

    /* 1. Rate limit ────────────────────────────────────────── */
    $ip = preg_replace('/[^0-9a-fA-F.:]/', '', av_client_ip());
    if (!rateLimit('contact_' . $ip, 5, 900)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many submissions. Please wait 15 minutes before trying again.']);
        exit;
    }

    /* 2. Honeypot anti-spam (hidden field website_url should be empty) */
    if (!empty($input['website_url'])) {
        // Silent success — spambots think it worked
        echo json_encode(['success' => true]);
        exit;
    }

    /* 3. Required field validation ─────────────────────────── */
    $required = ['purpose' => 'purpose', 'name' => 'full name', 'email' => 'email address', 'message' => 'message'];
    foreach ($required as $field => $label) {
        if (empty(trim($input[$field] ?? ''))) {
            echo json_encode(['success' => false, 'message' => "Please provide your {$label}.", 'field' => $field]);
            exit;
        }
    }

    /* 4. Consent check ─────────────────────────────────────── */
    $consent = filter_var($input['consent'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$consent) {
        echo json_encode(['success' => false, 'message' => 'Please accept the privacy notice before submitting.', 'field' => 'consent']);
        exit;
    }

    /* 5. Email validation ──────────────────────────────────── */
    $email = trim($input['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.', 'field' => 'email']);
        exit;
    }
    if (strlen($email) > 254) {
        echo json_encode(['success' => false, 'message' => 'Email address is too long.', 'field' => 'email']);
        exit;
    }

    /* 6. Sanitise all fields ────────────────────────────────── */
    $purpose  = preg_replace('/[^a-z_]/', '', strtolower(trim($input['purpose'] ?? 'general')));
    if (!array_key_exists($purpose, $PURPOSE_LABELS)) $purpose = 'general';
    $name     = clean($input['name']    ?? '', 100);
    $location = clean($input['location'] ?? '', 100);
    $phone    = clean($input['phone']   ?? '', 30);
    $subject  = clean($input['subject'] ?? '', 200);
    $message  = mb_substr(trim(strip_tags($input['message'] ?? '')), 0, 2000);

    if (strlen($name) < 2) {
        echo json_encode(['success' => false, 'message' => 'Please enter your full name.', 'field' => 'name']);
        exit;
    }
    if (strlen($message) < 20) {
        echo json_encode(['success' => false, 'message' => 'Your message is too short — please tell us more (at least 20 characters).', 'field' => 'message']);
        exit;
    }

    /* 7. File attachment (optional) ───────────────────────── */
    $attachmentMeta = null;
    $attachmentPath = null;
    if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['attachment'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'File upload error. Please try again without the attachment.']);
            exit;
        }
        // 10 MB cap
        if ($file['size'] > 10 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Attachment exceeds the 10 MB limit.']);
            exit;
        }
        // Extension allow-list
        $allowed_exts = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts, true)) {
            echo json_encode(['success' => false, 'message' => 'File type not allowed. Accepted: PDF, DOC, DOCX, PPT, PPTX, JPG, PNG.']);
            exit;
        }
        // MIME check via finfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $allowed_mimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'image/jpeg', 'image/png',
        ];
        if (!in_array($mime, $allowed_mimes, true)) {
            echo json_encode(['success' => false, 'message' => 'File content does not match its extension. Please check the file.']);
            exit;
        }
        // Store safely outside webroot if possible, otherwise in uploads/ with renamed file
        $safeDir = sys_get_temp_dir() . '/av_contact_uploads/';
        if (!is_dir($safeDir)) @mkdir($safeDir, 0700, true);
        $safeName    = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $attachmentPath = $safeDir . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $attachmentPath)) {
            error_log('[AV-Contact] Failed to move uploaded file');
            // Non-fatal: proceed without attachment
            $attachmentPath = null;
        } else {
            $attachmentMeta = ['path' => $attachmentPath, 'name' => basename($file['name'])];
        }
    }

    /* 8. Generate reference ─────────────────────────────────── */
    $purposeCode = strtoupper(substr($purpose, 0, 3));
    $ref = 'CTQ_' . $purposeCode . '_' . date('Ymd_His') . '_' . strtoupper(bin2hex(random_bytes(3)));

    /* 9. Build entry ────────────────────────────────────────── */
    $entry = [
        'id'              => $ref,
        'ref'             => $ref,
        'purpose'         => $purpose,
        'purpose_label'   => $PURPOSE_LABELS[$purpose],
        'name'            => $name,
        'email'           => $email,
        'phone'           => $phone,
        'location'        => $location,
        'subject'         => $subject,
        'message'         => $message,
        'attachment_name' => $attachmentMeta ? $attachmentMeta['name'] : null,
        'attachment_path' => $attachmentPath,  // server-side only — never returned to browser
        'ip_hash'         => hash('sha256', $ip . 'av_salt_2026'),  // hashed — GDPR compliant
        'status'          => 'unread',
        'created_at'      => date('c'),
    ];

    /* 10. Store ─────────────────────────────────────────────── */
    writeContact($entry);

    /* 11. Auto-reply to sender ──────────────────────────────── */
    $autoReplyData = [
        'name'          => $name,
        'purpose_label' => $PURPOSE_LABELS[$purpose],
        'ref'           => $ref,
    ];
    mail_send_contact(
        $email,
        'We received your message — Afrovanguard',
        autoReplyHtml($autoReplyData)
    );

    /* 12. Admin notification ────────────────────────────────── */
    if (ENABLE_ADMIN_NOTIFICATIONS) {
        $adminData = [
            'purpose'         => $purpose,
            'name'            => $name,
            'email'           => $email,
            'phone'           => $phone,
            'location'        => $location,
            'subject'         => $subject,
            'message'         => $message,
            'ref'             => $ref,
            'attachment_name' => $attachmentMeta ? $attachmentMeta['name'] : null,
        ];
        $subjectLine = '[Afrovanguard Contact] ' . ($PURPOSE_LABELS[$purpose] ?? 'New Enquiry') . ' — ' . $name;
        mail_send_contact(
            ADMIN_EMAIL,
            $subjectLine,
            adminNotifyHtml($adminData),
            $attachmentMeta  // forward attachment to admin
        );
    }

    /* 13. Respond ───────────────────────────────────────────── */
    if (function_exists('av_emit_event')) {
        av_emit_event('contact.received', ['reference' => $ref, 'name' => $name, 'email' => $email, 'purpose' => $purpose, 'subject' => $subject]);
    }
    echo json_encode([
        'success'   => true,
        'message'   => "Thank you {$name}! Your message has been received. We'll reply within 24–48 hours.",
        'reference' => $ref,
    ]);
    exit;
}

/* ── Newsletter subscription ────────────────────────────────── */
if ($action === 'newsletter') {
    /* Accept email from either JSON body (action already parsed above) or form-data */
    $email = trim((string)($input['email'] ?? $_POST['email'] ?? ''));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit;
    }
    $email = mb_strtolower($email, 'UTF-8');

    /* Rate limit newsletter sign-ups per IP */
    $nlIp = preg_replace('/[^0-9a-fA-F.:]/', '', av_client_ip());
    if (!rateLimit('nl_' . $nlIp, 10, 3600)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
        exit;
    }

    $entry = [
        'id'       => uniqid('nl_', true),
        'type'     => 'newsletter',
        'email'    => $email,
        'created'  => date('c'),
        'ip_hash'  => hash('sha256', ($nlIp) . 'av_nl_salt'),
    ];

    /* Atomic deduplication + write inside a single file lock */
    $isNew = !file_exists(CONTACT_FILE);
    $fp = @fopen(CONTACT_FILE, 'c');
    if ($fp) {
        flock($fp, LOCK_EX);
        $raw  = stream_get_contents($fp) ?: '';
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['contacts'])) $data = defaultContactData();

        /* Deduplication */
        foreach ($data['contacts'] as $c) {
            if (($c['type'] ?? '') === 'newsletter' && ($c['email'] ?? '') === $email) {
                flock($fp, LOCK_UN);
                fclose($fp);
                echo json_encode(['success' => true, 'message' => 'You are already subscribed. Welcome back!']);
                exit;
            }
        }

        $data['contacts'][] = $entry;
        $data['totals']['total'] = count($data['contacts']);
        $data['last_updated'] = date('c');
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        if ($isNew && file_exists(CONTACT_FILE)) @chmod(CONTACT_FILE, 0600);
    }

    /* Optional welcome email */
    $welcomeHtml = wrap(
        '&#127881; You\'re In!',
        "<h1>Welcome to the Afrovanguard community!</h1>"
        . "<p>You've been added to our newsletter. You'll receive weekly updates on programs, events, and impact stories from across the movement.</p>"
        . "<p>Follow us: <a href=\"https://www.instagram.com/afrovanguard/\" style=\"color:#f3b416;\">@afrovanguard</a></p>"
        . "<p>Warm regards,<br><strong>The Afrovanguard Team</strong></p>",
        'You received this because you subscribed at afrovanguard.org.ng.'
    );
    mail_send_contact($email, 'Welcome to Afrovanguard — You\'re In!', $welcomeHtml);

    echo json_encode(['success' => true, 'message' => "You're in! Welcome to the Afrovanguard community."]);
    exit;
}

/* ── Unknown action ───────────────────────────────────────── */
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);
