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
// Contact + newsletter PII must not sit in the web root behind only a by-name
// .htaccess deny. av_private_path() resolves a defense-in-depth location
// (AV_PRIVATE_DIR above the web root, else <root>/db/private which the server
// already denies) and migrates any legacy web-root contacts.json on first use.
define('CONTACT_FILE', av_private_path('contacts.json'));

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
    // Deliver through the one shared, battle-tested Mailer (PHPMailer over
    // authenticated SMTP, with the 587→465 fallback and Resend/HTTPS backstop) —
    // never a bespoke SMTP block. The submission is already stored, so a mail
    // failure never breaks the form.
    if (class_exists('Mailer') && method_exists('Mailer', 'send')) {
        $opt = [];
        if ($attachment && !empty($attachment['path'])) $opt['attachment'] = $attachment;
        $ok = Mailer::send($to, $subject, $html, $opt);
        if (!$ok) error_log('[AV-Contact] Mailer to ' . $to . ': ' . (method_exists('Mailer', 'lastError') ? Mailer::lastError() : 'send failed'));
        return $ok;
    }
    error_log("[AV-Contact] mail_send_contact skipped — Mailer unavailable. To: {$to}");
    return false;
}

/* ═══════════════════════════════════════════════════════════
   EMAIL TEMPLATES  (same base CSS / wrap as process-donation.php)
   ═══════════════════════════════════════════════════════════ */
/* The emails here used to carry their own <style> block and class names.
   Gmail strips <style>, so for most recipients they arrived as unstyled
   browser-default text. They use the shared template now: one look, inline
   styles, the mark at the foot. */

/* ── Auto-reply sent to the person who contacted us ────────── */
function autoReplyHtml(array $d): string {
    $facts = Mailer::facts([
        ['Your enquiry', $d['purpose_label']],
        ['Reference',    $d['ref']],
        ['Received',     date('F j, Y \a\t g:i A T')],
    ]);
    $link = static fn(string $url, string $text): string =>
        '<a href="' . $url . '" style="color:#8A6406;text-decoration:underline">' . $text . '</a>';
    return Mailer::shell(
        'We received your message',
        [
            'Hello <strong>' . esc($d['name']) . '</strong>,',
            'Thank you for reaching out to Afrovanguard. Your message has been received and a member of our team will respond within <strong>24&ndash;48 hours</strong> on business days (Monday&ndash;Friday, 9 am&ndash;5 pm WAT).',
            $facts,
            'In the meantime you can explore our ' . $link('https://afrovanguard.org.ng/projects/', 'projects')
                . ', support the work at ' . $link('https://afrovanguard.org.ng/donate.html', 'afrovanguard.org.ng/donate')
                . ', or follow @afrovanguard on Instagram and YouTube for impact stories.',
            'For urgent matters, reach us on WhatsApp: <strong>+234 903 777 6318</strong>',
            'Warm regards,<br><strong>The Afrovanguard Team</strong>',
        ],
        null,
        'We have your message — we reply within 24–48 hours.',
        [
            'eyebrow' => 'Message received',
            'note'    => 'You received this because you submitted a message at afrovanguard.org.ng/contact.',
        ]
    );
}

/* ── Internal admin notification ───────────────────────────── */
function adminNotifyHtml(array $d): string {
    $purposeMap = [
        'volunteer' => 'Volunteering',
        'business'  => 'Business / Partnership',
        'tech'      => 'Technology (Techome)',
        'media'     => 'Media (Mediapro)',
        'career'    => 'Career Development',
        'creative'  => 'Creative / Cultural (Africa GATES)',
        'kingdom'   => 'Kingdom Advancement',
        'donation'  => 'Donation Enquiry',
        'general'   => 'General Enquiry',
    ];
    $facts = Mailer::facts([
        ['Purpose',   $purposeMap[$d['purpose'] ?? 'general'] ?? 'General Enquiry'],
        ['Name',      $d['name']],
        ['Email',     $d['email']],
        ['Phone',     $d['phone'] ?: '—'],
        ['Location',  $d['location'] ?: '—'],
        ['Subject',   $d['subject'] ?: '—'],
        ['Reference', $d['ref']],
        ['Submitted', date('Y-m-d H:i:s T')],
    ]);
    $rows = [$facts, Mailer::panel('Message', nl2br(esc($d['message'])))];
    if (!empty($d['attachment_name'])) {
        $rows[] = 'Attachment: <strong>' . esc($d['attachment_name']) . '</strong>';
    }
    return Mailer::shell(
        'Contact form submission',
        $rows,
        ['text' => 'Reply to ' . $d['name'],
         'url'  => 'mailto:' . $d['email'] . '?subject=' . rawurlencode('Re: Your Afrovanguard Enquiry (' . $d['ref'] . ')')],
        $d['name'] . ' — ' . ($d['subject'] ?: 'new enquiry'),
        ['eyebrow' => 'New message', 'note' => 'Internal notification — do not forward.']
    );
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
        if (!av_admin_token_matches($token)) {
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
    // 'c+' (read+write), not 'c' (write-only): the dedup/merge below reads the
    // existing store via stream_get_contents(), which returns '' on a write-only
    // handle — so 'c' would wipe every prior contact & subscriber on each signup.
    $fp = @fopen(CONTACT_FILE, 'c+');
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
    $welcomeHtml = Mailer::shell(
        'Welcome to the Afrovanguard community',
        [
            "You've been added to our newsletter. You'll receive weekly updates on programs, events, and impact stories from across the movement.",
            'Follow us: <a href="https://www.instagram.com/afrovanguard/" style="color:#8A6406;text-decoration:underline">@afrovanguard</a>',
            'Warm regards,<br><strong>The Afrovanguard Team</strong>',
        ],
        ['text' => 'See what we are building', 'url' => 'https://afrovanguard.org.ng/'],
        "You're in — weekly updates from the movement.",
        ['eyebrow' => "You're in", 'note' => 'You received this because you subscribed at afrovanguard.org.ng.']
    );
    mail_send_contact($email, 'Welcome to Afrovanguard — You\'re In!', $welcomeHtml);

    echo json_encode(['success' => true, 'message' => "You're in! Welcome to the Afrovanguard community."]);
    exit;
}

/* ── Unknown action ───────────────────────────────────────── */
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);
