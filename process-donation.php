<?php
require_once __DIR__ . '/lib/security.php';

/**
 * Afrovanguard Donation Processor — production, zero silent fails
 *
 * GET  action=get_stats              — live campaign totals + donor count
 * GET  action=get_donors&limit=N     — recent donors for donor wall
 * GET  action=verify_payment&ref=X   — poll Paystack VA status
 * POST action=record_donation        — server-verify Paystack + store + receipt
 * POST action=generate_virtual_account — Paystack DVA for bank transfer
 * POST action=record_bank_transfer   — admin: confirm manual Zenith transfer
 * POST action=submit_contribute      — non-monetary contribution
 * POST action=bank_transfer_copy     — email static bank details to donor
 * POST action=webhook                — Paystack charge.success webhook
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Powered-By: ');

// Fix M-03: allow both apex and www. origins
$allowedOrigins = ['https://afrovanguard.org.ng', 'https://www.afrovanguard.org.ng'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Paystack-Signature');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ── Config ─────────────────────────────────────────────────── */
// Self-bootstrap: loads the .env file, promotes SMTP_*/FROM_*/ADMIN_EMAIL and
// PAYSTACK_* (from AV_PAYSTACK_PK/SK) to constants, and pulls in the
// dependency-free Smtp + Mailer. This makes donations work on a pure-.env
// deployment (no config.php) as well as the legacy config.php setup — bootstrap
// loads config.php itself when it is present and the secrets are in the env.
require_once __DIR__ . '/lib/bootstrap.php';

// Donation-specific settings that normally live in config.php — default them so
// the money path runs even when config.php is absent (env-only deployments).
foreach ([
    'ENABLE_EMAIL_NOTIFICATIONS' => true, 'ENABLE_ADMIN_NOTIFICATIONS' => true,
    'ENABLE_BANK_TRANSFER_EMAIL' => true, 'ENABLE_MONTHLY_RECURRING' => true,
    'CURRENCY_DEFAULT' => 'NGN', 'MIN_DONATION_AMOUNT' => 1000,
    'TAX_RECEIPT_THRESHOLD_NGN' => 5000, 'TAX_RECEIPT_THRESHOLD_USD' => 5, 'TAX_RECEIPT_THRESHOLD_GBP' => 5,
    'BANK_NAME' => 'Zenith Bank', 'BANK_CODE' => '057', 'ACCOUNT_NUMBER' => '1229629683',
    'ACCOUNT_NAME' => 'AMBASSADORS FOR COMMUNITY, TECH AND CULTURAL ADVANCEMENTS',
    'FROM_NAME' => 'Afrovanguard', 'ADMIN_EMAIL' => 'cacentre@afrovanguard.org.ng',
] as $__k => $__v) { if (!defined($__k)) define($__k, $__v); }
if (!defined('FROM_EMAIL')) define('FROM_EMAIL', defined('SMTP_USERNAME') ? SMTP_USERNAME : 'donations@afrovanguard.org.ng');

// Donations genuinely need the Paystack secret (charge + webhook verification),
// so fail this one request cleanly rather than attempting to charge with an empty key.
if (!defined('PAYSTACK_SECRET_KEY') || (string) PAYSTACK_SECRET_KEY === '') {
    error_log('[AV] process-donation: PAYSTACK_SECRET_KEY missing — donations disabled until env is set.');
    http_response_code(503);
    echo json_encode(['success'=>false,'message'=>'Donations are temporarily unavailable. Please try again shortly.']);
    exit;
}

/* ── PHPMailer ──────────────────────────────────────────────── */
// Supports both Composer (vendor/autoload.php) and manual install (PHPMailer-master/)
// Install: composer require phpmailer/phpmailer  OR  unzip PHPMailer-master/ here.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/PHPMailer-master/src/PHPMailer.php')) {
    require_once __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer-master/src/SMTP.php';
    require_once __DIR__ . '/PHPMailer-master/src/Exception.php';
} else {
    // PHPMailer not installed — email will silently log but not send.
    // Donations are still stored and Paystack webhooks still work.
    error_log('[AV] PHPMailer not installed. Email notifications disabled. Run: composer require phpmailer/phpmailer');
    define('AV_NO_MAILER', true);
}
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ═══════════════════════════════════════════════════════════
   DATA STORE  —  donations.json  (file-locked, not web-accessible)
   ═══════════════════════════════════════════════════════════ */
define('DATA_FILE', __DIR__ . '/donations.json');

function defaultData(): array {
    return [
        'version'      => 2,
        'last_updated' => date('c'),
        'totals'       => ['donors'=>0,'raised_ngn'=>0,'inkind'=>0],
        'campaigns'    => [
            'general'     => ['raised'=>0,'goal'=>50000000, 'donors'=>0],
            'sts'         => ['raised'=>0,'goal'=>25000000, 'donors'=>0],
            'techhome'    => ['raised'=> 0,'goal'=>15000000, 'donors'=>0],
        ],
        'donations' => [],
    ];
}

function readData(): array {
    if (!file_exists(DATA_FILE)) return defaultData();
    $fp = @fopen(DATA_FILE,'r');
    if (!$fp) return defaultData();
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $d = json_decode($raw, true);
    if (!is_array($d) || empty($d['campaigns'])) return defaultData();
    return $d;
}

function writeData(array $data): bool {
    $isNew = !file_exists(DATA_FILE);
    $data['last_updated'] = date('c');
    $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    $fp = @fopen(DATA_FILE,'c');
    if (!$fp) { error_log('[AV] Cannot write donations.json'); return false; }
    flock($fp, LOCK_EX);
    ftruncate($fp,0); rewind($fp); fwrite($fp,$json); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    // FIX I-08: Restrict permissions on first creation (owner read/write only)
    if ($isNew && file_exists(DATA_FILE)) @chmod(DATA_FILE, 0600);
    return true;
}

/* storeDonationIfNew() handles both idempotency and atomic write in one lock */

/**
 * FIX C-06 · Rate limiter (file-based, per-IP, sliding window)
 */
function rateLimit(string $key, int $max = 30, int $window = 60): bool {
    $dir = sys_get_temp_dir() . '/av_rl/';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . md5($key) . '.rl';
    $now  = time();
    $fp   = @fopen($file, 'c+');
    if (!$fp) return true;   // fail open — can't write temp, allow request
    flock($fp, LOCK_EX);
    $raw  = stream_get_contents($fp) ?: '[]';
    $hits = array_filter(json_decode($raw, true) ?? [], fn($ts) => ($now - $ts) < $window);
    $allow = count($hits) < $max;
    if ($allow) $hits[] = $now;
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode(array_values($hits)));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return $allow;
}

/**
 * FIX C-01 · Atomic idempotency-check-and-write inside one LOCK_EX.
 * Eliminates TOCTOU races — single exclusive lock covers check and write.
 * Returns true if stored (new), false if already recorded (duplicate).
 */
function storeDonationIfNew(string $ref, array $entry, float $amount, string $campaign, string $currency): bool {
    $fp = @fopen(DATA_FILE, 'c');
    if (!$fp) { error_log('[AV] Cannot open donations.json for write'); return false; }
    flock($fp, LOCK_EX);

    // Re-read INSIDE the lock — no concurrent write can slip through now
    $raw  = stream_get_contents($fp) ?: '';
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data) || empty($data['campaigns'])) $data = defaultData();

    // Idempotency check while holding the exclusive lock
    foreach ($data['donations'] as $d) {
        if (($d['reference'] ?? '') === $ref) {
            flock($fp, LOCK_UN); fclose($fp);
            return false;  // duplicate — already recorded
        }
    }

    // Mutate in memory
    array_unshift($data['donations'], $entry);
    if (count($data['donations']) > 500) {
        $data['donations'] = array_slice($data['donations'], 0, 500);
    }
    $key = isset($data['campaigns'][$campaign]) ? $campaign : 'general';
    $data['campaigns'][$key]['donors'] = ($data['campaigns'][$key]['donors'] ?? 0) + 1;
    if ($currency === 'NGN') {
        $data['campaigns'][$key]['raised'] = ($data['campaigns'][$key]['raised'] ?? 0) + $amount;
        $data['totals']['raised_ngn']      = ($data['totals']['raised_ngn']      ?? 0) + $amount;
    }
    $data['totals']['donors'] = ($data['totals']['donors'] ?? 0) + 1;
    $data['last_updated']     = date('c');

    // Write — still inside LOCK_EX
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    ftruncate($fp, 0); rewind($fp); fwrite($fp, $json); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    // Fire once per unique donation (idempotent by reference). Best-effort.
    if (function_exists('av_emit_event')) {
        av_emit_event('donation.completed', [
            'reference' => $ref, 'amount' => $amount, 'currency' => $currency, 'campaign' => $campaign,
            'name' => (string) ($entry['name'] ?? ''), 'email' => (string) ($entry['email'] ?? ''),
        ]);
    }
    return true;
}

function timeAgo(string $iso): string {
    $d = max(0, time()-strtotime($iso));
    if ($d < 60)    return 'Just now';
    if ($d < 3600)  return floor($d/60).' min ago';
    if ($d < 86400) return floor($d/3600).' hr ago';
    if ($d < 172800)return 'Yesterday';
    if ($d < 604800)return floor($d/86400).' days ago';
    return date('M j', strtotime($iso));
}

/* ═══════════════════════════════════════════════════════════
   PAYSTACK
   ═══════════════════════════════════════════════════════════ */
function ps(string $method, string $path, array $body=[]): array {
    $ch = curl_init('https://api.paystack.co'.$path);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.PAYSTACK_SECRET_KEY,'Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_TIMEOUT=>30,
    ]);
    if ($method==='POST'){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));}
    $r=curl_exec($ch); $e=curl_error($ch); curl_close($ch);
    if ($e){error_log('[AV] cURL: '.$e);return['status'=>false,'message'=>'Network error'];}
    $d=json_decode($r,true);
    return is_array($d)?$d:['status'=>false,'message'=>'Bad API response'];
}

function esc($v): string {
    return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}

/* ═══════════════════════════════════════════════════════════
   EMAIL
   ═══════════════════════════════════════════════════════════ */
function mail_send(string $to, string $sub, string $html): bool {
    if (!ENABLE_EMAIL_NOTIFICATIONS) return true;
    // Preferred path: the dependency-free Mailer (authenticated SMTP via lib/Smtp,
    // falling back to mail()), loaded by bootstrap. This host has no PHPMailer, so
    // this is what actually delivers receipts and pledge notifications.
    if (class_exists('Mailer') && method_exists('Mailer', 'send')) {
        return Mailer::send($to, $sub, $html);
    }
    if (defined('AV_NO_MAILER')) {
        error_log("[AV] mail_send skipped — no mailer available. To: {$to}, Subject: {$sub}");
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
        $m->Subject  = $sub;
        $m->Body     = $html;
        $m->AltBody  = strip_tags(str_replace(['<br>','<br/>','<br />'], PHP_EOL, $html));
        $m->send();
        return true;
    } catch (Exception $e) {
        error_log('[AV] Mail to ' . $to . ': ' . $m->ErrorInfo);
        return false;
    }
}

function baseCss(): string {
    return 'body,table,td,a{-webkit-text-size-adjust:100%;}table{border-collapse:collapse;}'
    .'body{margin:0;padding:0;background:#f4f4f4;font-family:\'Montserrat\',-apple-system,BlinkMacSystemFont,sans-serif;color:#111;}'
    .'.wrap{max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;}'
    .'.hd{background:#0d1220;border-bottom:4px solid #f3b416;padding:20px 40px;text-align:center;}'
    .'.brand{font-family:\'Cormorant\',Georgia,serif;font-size:26px;font-weight:700;color:#fff;}'
    .'.gold{color:#f3b416;}.tag{font-size:10px;letter-spacing:2px;font-weight:700;color:#f3b416;text-transform:uppercase;margin-top:4px;}'
    .'.sec{padding:32px 40px;}.ft{background:#fafafa;border-top:1px solid #eee;padding:24px 32px;text-align:center;}'
    .'h1{font-family:\'Cormorant\',Georgia,serif;font-size:28px;margin:0 0 20px;color:#111;line-height:1.2;}'
    .'p{font-size:15px;line-height:1.65;margin:0 0 16px;color:#4b5563;}strong{color:#111;}'
    .'.badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;background:#fffbf0;color:#8d480e;padding:6px 10px;border-radius:999px;margin-bottom:16px;}'
    .'.card{background:#fffbf0;border:1px solid #ffe688;border-radius:8px;padding:20px;margin-bottom:24px;}'
    .'.lbl{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#8d480e;margin-bottom:4px;}'
    .'.val{font-size:16px;font-weight:600;color:#111;}.row{margin-top:14px;}'
    .'.btn{display:inline-block;padding:14px 24px;background:#f3b416;color:#0d1220!important;text-decoration:none;border-radius:6px;font-weight:700;font-size:15px;}'
    .'.ft p{font-size:11px;color:#6b7280;margin:0 0 8px;}.ft a{color:#6b7280;text-decoration:underline;}'
    .'@media(max-width:600px){.sec,.hd{padding:24px 20px!important;}h1{font-size:22px!important;}.btn{width:100%!important;display:block;text-align:center;box-sizing:border-box;}}';
}

function wrap(string $badge, string $body, string $note=''): string {
    $css = baseCss(); $year=date('Y');
    return "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><style>{$css}</style></head><body>"
    ."<center><table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr><td style=\"padding:40px 10px;\">"
    ."<table class=\"wrap\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">"
    ."<tr><td class=\"hd\" align=\"center\"><div class=\"brand\">AFROVANGUARD<span class=\"gold\">.</span></div>"
    ."<div class=\"tag\">The Force for Good</div></td></tr>"
    ."<tr><td class=\"sec\"><span class=\"badge\">{$badge}</span><br>{$body}</td></tr>"
    ."<tr><td class=\"ft\">"
    .($note?"<p>{$note}</p>":'')
    ."<p>Afrovanguard · CACENTRE, 2 Abolude/Oremeji Street, Alimosho, Lagos</p>"
    ."<p><a href=\"mailto:cacentre@afrovanguard.org.ng\">Support</a> · <a href=\"https://afrovanguard.org.ng/privacy-policy/\">Privacy</a></p>"
    ."<p>&copy; {$year} Afrovanguard. All rights reserved.</p>"
    ."</td></tr></table></td></tr></table></center></body></html>";
}

function receiptHtml(array $d): string {
    $sym  = $d['currency']??'NGN';
    $amt  = esc($sym).' '.number_format((float)($d['amount']??0),2);
    $name = esc(trim(($d['firstName']??'').' '.($d['lastName']??'')));
    $camp = esc($d['campaign']??'General Fund');
    $freq = esc($d['frequency']??'One-time');
    $ref  = esc($d['reference']??'—');
    $dt   = date('F j, Y \a\t g:i A T');
    $body = "<h1>Thank you for your gift! &#127881;</h1>"
    ."<p><strong>Vanguard {$name},</strong></p>"
    ."<p>Your donation to Afrovanguard is confirmed. You're directly empowering young African leaders through mentorship, creative arts, technology training, and community projects across Nigeria and 14 nations.</p>"
    ."<div class=\"card\">"
    ."<div class=\"lbl\">Amount Donated</div><div class=\"val\" style=\"font-size:30px;color:#f3b416;\">{$amt}</div>"
    ."<div class=\"row\"><div class=\"lbl\">Reference</div><div class=\"val\" style=\"font-size:13px;word-break:break-all;\">{$ref}</div></div>"
    ."<div class=\"row\"><div class=\"lbl\">Campaign</div><div class=\"val\">{$camp}</div></div>"
    ."<div class=\"row\"><div class=\"lbl\">Frequency</div><div class=\"val\">{$freq}</div></div>"
    ."<div class=\"row\"><div class=\"lbl\">Date &amp; Time</div><div class=\"val\">{$dt}</div></div>"
    ."</div>"
    ."<p><strong>&#128161; Your Impact</strong></p>"
    ."<p>100% of your gift funds programs — zero admin fees. You'll receive quarterly impact reports showing your donation in action: School Storm campus tours, Street-To-Stardom creative arts, Techome equipment drives, and more.</p>"
    ."<a href=\"https://afrovanguard.org.ng/projects/\" class=\"btn\">See Our Projects &rarr;</a>"
    ."<p style=\"margin-top:24px;font-size:13px;color:#6b7280;\">Questions? <a href=\"mailto:donations@afrovanguard.org.ng\" style=\"color:#f3b416;\">donations@afrovanguard.org.ng</a></p>"
    ."<p style=\"margin-top:28px;\">With gratitude,<br><strong>The Afrovanguard Team</strong><br>"
    ."<span style=\"font-size:12px;color:#f3b416;text-transform:uppercase;\">The Force for Good</span></p>";
    return wrap('&#10003; Donation Receipt', $body, 'You received this because you donated to Afrovanguard.');
}

function bankHtml(array $d): string {
    $name=$d['name']??'Dear Supporter';
    $body="<h1>Complete your donation</h1>"
    ."<p><strong>".esc($name).",</strong></p>"
    ."<p>Thank you for choosing to support Afrovanguard. Please use the details below to complete your transfer.</p>"
    ."<div class=\"card\" style=\"border:2px solid #f3b416;\">"
    ."<div class=\"lbl\">Bank</div><div class=\"val\">".esc(BANK_NAME)."</div>"
    ."<div class=\"row\"><div class=\"lbl\">Account Name</div><div class=\"val\" style=\"font-size:13px;\">".esc(ACCOUNT_NAME)."</div></div>"
    ."<div class=\"row\"><div class=\"lbl\">Account Number</div><div class=\"val\" style=\"font-size:28px;letter-spacing:.06em;color:#f3b416;\">".esc(ACCOUNT_NUMBER)."</div></div>"
    ."<div class=\"row\"><div class=\"lbl\">Sort Code</div><div class=\"val\">".esc(BANK_CODE)."</div></div>"
    ."</div>"
    ."<div style=\"font-size:13px;background:#fffbf0;border-left:4px solid #f3b416;padding:16px;border-radius:0 6px 6px 0;margin:20px 0;\">"
    ."<strong>After transferring:</strong> Send your name, amount &amp; date to "
    ."<a href=\"mailto:donations@afrovanguard.org.ng\" style=\"color:#f3b416;\">donations@afrovanguard.org.ng</a> to receive your impact receipt."
    ."</div>";
    return wrap('&#127970; Bank Transfer Details', $body);
}

function contributeHtml(array $d): string {
    $name=esc($d['name']??'Supporter');
    $type=esc($d['type_label']??'Contribution');
    $desc=esc($d['description']??'');
    $body="<h1>Thank you for your {$type}! &#128588;</h1>"
    ."<p><strong>Vanguard {$name},</strong></p>"
    ."<p>Your non-monetary contribution to Afrovanguard has been received. Every form of giving — time, skills, and resources — powers our mission.</p>"
    ."<div class=\"card\">"
    ."<div class=\"lbl\">Contribution Type</div><div class=\"val\">{$type}</div>"
    ."<div class=\"row\"><div class=\"lbl\">Description</div><div class=\"val\" style=\"font-size:14px;\">{$desc}</div></div>"
    ."</div>"
    ."<p>Our team will reach out within 48 hours to coordinate. Thank you for being part of Africa's transformation.</p>"
    ."<p>Warm regards,<br><strong>The Afrovanguard Team</strong></p>";
    return wrap('&#129309; Contribution Received', $body, 'You submitted a contribution to Afrovanguard.');
}

function adminHtml(array $d, string $label): string {
    $amt=number_format((float)($d['amount']??0),2);
    $cur=esc($d['currency']??'NGN');
    $name=esc(trim(($d['firstName']??$d['name']??'Unknown').' '.($d['lastName']??'')));
    $email=esc($d['email']??'—');
    $camp=esc($d['campaign']??'—');
    $ref=esc($d['reference']??'—');
    return "<h3>[Afrovanguard] {$label} — {$cur} {$amt}</h3>"
    ."<table style=\"font-size:14px;\"><tr><td style=\"padding:4px 10px 4px 0;\"><b>Name</b></td><td>{$name}</td></tr>"
    ."<tr><td><b>Email</b></td><td>{$email}</td></tr><tr><td><b>Campaign</b></td><td>{$camp}</td></tr>"
    ."<tr><td><b>Ref</b></td><td>{$ref}</td></tr><tr><td><b>Date</b></td><td>".date('Y-m-d H:i:s T')."</td></tr></table>"
    ."<p><a href=\"https://afrovanguard.org.ng/donor-dashboard\">Open Donor Dashboard</a></p>";
}

/* ═══════════════════════════════════════════════════════════
   ROUTING
   ═══════════════════════════════════════════════════════════ */
$method = $_SERVER['REQUEST_METHOD'];

/* ── GET ─────────────────────────────────────────────────── */
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'get_stats') {
        $data = readData();
        echo json_encode(['success'=>true,'campaigns'=>$data['campaigns'],'totals'=>$data['totals']]);
        exit;
    }

    if ($action === 'get_donors') {
        $limit = min((int)($_GET['limit']??8),50);
        $page  = max((int)($_GET['page']??0),0);
        $type  = $_GET['type']??'all';
        $data  = readData();
        $all   = array_values(array_filter($data['donations'], function($d) use($type){
            if ($type==='monetary') return ($d['type']??'')==='card'||($d['type']??'')==='bank_static'||($d['type']??'')==='bank_va';
            if ($type==='inkind')   return ($d['type']??'')==='inkind';
            return true;
        }));
        $total  = count($all);
        $donors = array_map(function($d){
            $isink = ($d['type']??'')==='inkind';
            if ($isink) $display = ucfirst($d['inkind_type']??'Contribution');
            else {
                $sym=['NGN'=>'₦','USD'=>'$','GBP'=>'£'][$d['currency']??'NGN']??'₦';
                $display = $sym.number_format((float)($d['amount']??0));
            }
            return [
                'name'           => $d['anonymous']?'Anonymous':($d['name']??'Donor'),
                'initials'       => $d['anonymous']?'AN':($d['initials']??'??'),
                'amount_display' => $display,
                'campaign'       => $d['campaign']??'general',
                'type'           => $d['type']??'card',
                'time_ago'       => timeAgo($d['created_at']??date('c')),
            ];
        }, array_slice($all,$page*$limit,$limit));
        echo json_encode(['success'=>true,'donors'=>$donors,'total'=>$total]);
        exit;
    }

    if ($action === 'verify_payment') {
        $ref = trim($_GET['reference']??'');
        if (!preg_match('/^[A-Za-z0-9_\-]{5,100}$/',$ref)) {
            echo json_encode(['paid'=>false,'status'=>'invalid']); exit;
        }
        $r = ps('GET','/transaction/verify/'.urlencode($ref));
        $tx= $r['data']??[];
        echo json_encode(['paid'=>($tx['status']??'')==='success','status'=>$tx['status']??'unknown']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

/* ── POST ────────────────────────────────────────────────── */
if ($method !== 'POST') {
    http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
}

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE']??'';

/* Paystack webhook */
if ($sig && hash_equals(hash_hmac('sha512',$raw,PAYSTACK_SECRET_KEY),$sig)) {
    $event = json_decode($raw,true);
    if (($event['event']??'')==='charge.success') {
        $tx=$event['data']??[];
        $ref=$tx['reference']??'';
        if ($ref) {
            $amount   = (float)($tx['amount']??0)/100;
            $currency = strtoupper($tx['currency']??'NGN');
            $email    = $tx['customer']['email']??'';
            $meta     = $tx['metadata']??[];
            $fields   = [];
            foreach (($meta['custom_fields']??[]) as $cf) {
                $fields[$cf['variable_name']??''] = $cf['value']??'';
            }
            $campaign = preg_replace('/[^a-z0-9_-]/','',strtolower($fields['campaign']??'general'));
            $anon     = ($fields['anonymous']??'No') === 'Yes';
            $fn       = trim($fields['first_name'] ?? '');
            $ln       = trim($fields['last_name']  ?? '');
            $dispName = $anon ? 'Anonymous' : esc(trim("$fn $ln") ?: $email);
            $initials = $anon ? 'AN' : strtoupper(substr($fn?:$email, 0, 1) . substr($ln, 0, 1));
            $entry = [
                'id'         => $ref,
                'type'       => 'card',
                'reference'  => $ref,
                'name'       => $dispName,
                'initials'   => $initials,
                'email'      => $email,
                'anonymous'  => $anon,
                'amount'     => $amount,
                'currency'   => $currency,
                'campaign'   => $campaign,
                'frequency'  => esc($fields['frequency']??'One-time'),
                'status'     => 'confirmed',
                'created_at' => date('c'),
            ];
            // storeDonationIfNew handles idempotency — safe to call on retried webhooks
            storeDonationIfNew($ref, $entry, $amount, $campaign, $currency);
        }
    }
    http_response_code(200); echo json_encode(['received'=>true]); exit;
}

$input = json_decode($raw,true);
if (!is_array($input)||empty($input['action'])) {
    http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid request']); exit;
}
$action=$input['action'];

/* ── FIX C-06: Rate limiting per IP ─────────────────────────────── */
$clientIp = preg_replace('/[^0-9a-fA-F:.]/', '', av_client_ip());
$clientIp = substr($clientIp, 0, 45);
if (!rateLimit('all_' . $clientIp, 60, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['success'=>false,'message'=>'Too many requests. Please wait a moment.']);
    exit;
}
if (in_array($action, ['generate_virtual_account','record_donation','record_bank_transfer'])) {
    if (!rateLimit('pay_' . $clientIp, 10, 60)) {
        http_response_code(429);
        echo json_encode(['success'=>false,'message'=>'Too many payment requests. Please wait one minute.']);
        exit;
    }
}

/* ── record_donation ─────────────────────────────────────── */
if ($action==='record_donation') {
    $ref=trim($input['reference']??'');
    if (!preg_match('/^[A-Za-z0-9_\-]{5,100}$/',$ref)) {
        echo json_encode(['success'=>false,'message'=>'Invalid reference']); exit;
    }
    // Server-side verification — amount from Paystack, not client
    $r=ps('GET','/transaction/verify/'.urlencode($ref));
    $tx=$r['data']??[];
    if (($tx['status']??'')!=='success') {
        echo json_encode(['success'=>false,'message'=>'Payment not yet verified. Your receipt will arrive once confirmed.']); exit;
    }
    $amount  =(float)($tx['amount']??0)/100;
    $currency=strtoupper($tx['currency']??'NGN');
    $email   =$tx['customer']['email']??trim($input['email']??'');
    $fn      =trim($input['firstName']??'');
    $ln      =trim($input['lastName']??'');
    $camp    =preg_replace('/[^a-z0-9_-]/','',strtolower($input['campaign']??'general'));
    $allowed_freq = ['One-time','Monthly','Annual'];
    $freq = in_array($input['frequency']??'', $allowed_freq, true) ? $input['frequency'] : 'One-time';
    $anon    =!empty($input['anonymous']);
    $initials=strtoupper(($fn[0]??'?').($ln[0]??''));
    $entry = [
        'id'=>$ref,'type'=>'card','reference'=>$ref,
        'name'=>$anon?'Anonymous':mb_substr(trim("$fn $ln"),0,100),
        'initials'=>$anon?'AN':$initials,
        'email'=>$email,'anonymous'=>$anon,
        'amount'=>$amount,'currency'=>$currency,
        'campaign'=>$camp,'frequency'=>$freq,
        'status'=>'confirmed','created_at'=>date('c'),
    ];
    $stored = storeDonationIfNew($ref,$entry,$amount,$camp,$currency);
    $done   = !$stored;
    if (!$done) {
        if ($email) {
            mail_send($email,'Thank You for Your Donation to Afrovanguard 🎉',receiptHtml([
                'firstName'=>$fn,'lastName'=>$ln,'email'=>$email,
                'amount'=>$amount,'currency'=>$currency,
                'campaign'=>ucwords(str_replace(['-','_'],' ',$camp)),
                'frequency'=>$freq,'reference'=>$ref,
            ]));
            if (ENABLE_ADMIN_NOTIFICATIONS) {
                mail_send(ADMIN_EMAIL,'[Afrovanguard] New Donation: '.$currency.' '.number_format($amount,2),
                    adminHtml(['firstName'=>$fn,'lastName'=>$ln,'email'=>$email,'amount'=>$amount,'currency'=>$currency,'campaign'=>$camp,'reference'=>$ref],'New Donation'));
            }
        }
    }
    $fresh=readData();
    echo json_encode(['success'=>true,'amount'=>$amount,'currency'=>$currency,'campaigns'=>$fresh['campaigns'],'totals'=>$fresh['totals']]);
    exit;
}

/* ── generate_virtual_account ────────────────────────────── */
if ($action==='generate_virtual_account') {
    foreach(['email','name','amount'] as $f) {
        if (empty($input[$f])){echo json_encode(['success'=>false,'message'=>"Missing: $f"]);exit;}
    }
    if (!filter_var($input['email'],FILTER_VALIDATE_EMAIL)){echo json_encode(['success'=>false,'message'=>'Invalid email']);exit;}
    $amount=(int)round((float)$input['amount']);
    if ($amount < MIN_DONATION_AMOUNT) { echo json_encode(['success'=>false,'message'=>'Minimum donation is ₦'.number_format(MIN_DONATION_AMOUNT)]); exit; }
    if ($amount > 10000000)            { echo json_encode(['success'=>false,'message'=>'Maximum ₦10,000,000 per transaction. For larger gifts email donations@afrovanguard.org.ng']); exit; }
    $r=ps('POST','/charge',[
        'email'=>$input['email'],'amount'=>$amount*100,'currency'=>'NGN',
        'bank_transfer'=>['account_expires_at'=>date('Y-m-d\TH:i:s',strtotime('+30 minutes'))],
        'metadata'=>['donor_name'=>$input['name'],'campaign'=>$input['campaign']??'General Fund','source'=>'afrovanguard_donate'],
    ]);
    if (empty($r['status'])||!$r['status']){
        error_log('[AV] VA fail: '.json_encode($r));
        echo json_encode(['success'=>false,'message'=>'Could not generate a virtual account. Please try card payment or the static bank transfer.']);exit;
    }
    $data=$r['data']??[];$bank=$data['bank']??[];
    $accNum=$bank['account_number']??($data['account_number']??null);
    if (!$accNum){
        error_log('[AV] VA missing account: '.json_encode($data));
        echo json_encode(['success'=>false,'message'=>'Virtual account unavailable. Use card or static bank transfer.']);exit;
    }
    echo json_encode([
        'success'=>true,
        'reference'=>$data['reference']??('AV_'.time().'_'.bin2hex(random_bytes(3))),
        'account_number'=>$accNum,
        'account_name'=>$bank['account_name']??($data['account_name']??'Paystack / Afrovanguard'),
        'bank_name'=>$bank['bank_name']??($data['bank_name']??'Wema Bank'),
        'expires_mins'=>30,'amount'=>$amount,
    ]);
    exit;
}

/* ── record_bank_transfer (admin confirms Zenith manual transfer) */
if ($action==='record_bank_transfer') {
    $token=trim($input['admin_token']??'');
    if (!defined('ADMIN_TOKEN')||!hash_equals(ADMIN_TOKEN,$token)){
        http_response_code(403);echo json_encode(['success'=>false,'message'=>'Unauthorized']);exit;
    }
    foreach(['name','email','amount','campaign'] as $f){
        if (empty($input[$f])){echo json_encode(['success'=>false,'message'=>"Missing: $f"]);exit;}
    }
    if (!filter_var($input['email'],FILTER_VALIDATE_EMAIL)){echo json_encode(['success'=>false,'message'=>'Invalid email']);exit;}
    $amount=(float)$input['amount'];
    if ($amount < 100)     { echo json_encode(['success'=>false,'message'=>'Minimum amount is ₦100']); exit; }
    if ($amount > 10000000){ echo json_encode(['success'=>false,'message'=>'Maximum ₦10,000,000 per transaction. For larger gifts email donations@afrovanguard.org.ng']); exit; }
    $email=trim($input['email']);
    $name=mb_substr(trim($input['name']),0,100);
    $camp=preg_replace('/[^a-z0-9_-]/','',strtolower($input['campaign']));
    $ref='ZB_'.date('Ymd_His').'_'.strtoupper(substr(bin2hex(random_bytes(3)),0,6));
    $parts=explode(' ',$name,2);
    $initials=strtoupper(($parts[0][0]??'?').($parts[1][0]??''));
    $anon=!empty($input['anonymous']);
    $bt_entry = [
        'id'=>$ref,'type'=>'bank_static','reference'=>$ref,
        'name'=>$anon?'Anonymous':$name,'initials'=>$anon?'AN':$initials,
        'email'=>$email,'anonymous'=>$anon,'amount'=>$amount,
        'currency'=>'NGN','campaign'=>$camp,
        'frequency'=>in_array($input['frequency']??'',['One-time','Monthly','Annual'],true)?$input['frequency']:'One-time',
        'status'=>'confirmed','created_at'=>date('c'),'confirmed_by'=>'admin',
    ];
    storeDonationIfNew($ref,$bt_entry,$amount,$camp,'NGN');
    $p=explode(' ',$name,2);
    mail_send($email,'Your Afrovanguard Donation is Confirmed 🎉',receiptHtml([
        'firstName'=>$p[0],'lastName'=>$p[1]??'','email'=>$email,
        'amount'=>$amount,'currency'=>'NGN',
        'campaign'=>ucwords(str_replace(['-','_'],' ',$camp)),
        'frequency'=>$input['frequency']??'One-time','reference'=>$ref,
    ]));
    $fresh=readData();
    echo json_encode(['success'=>true,'reference'=>$ref,'campaigns'=>$fresh['campaigns'],'totals'=>$fresh['totals']]);
    exit;
}

/* ── submit_contribute (non-monetary) ────────────────────── */
if ($action==='submit_contribute') {
    foreach(['name','email','contribute_type','description'] as $f){
        if (empty($input[$f])){echo json_encode(['success'=>false,'message'=>"Missing: $f"]);exit;}
    }
    if (!filter_var($input['email'],FILTER_VALIDATE_EMAIL)){echo json_encode(['success'=>false,'message'=>'Invalid email']);exit;}
    $typeMap=['volunteer'=>'Volunteer Time','skills'=>'Professional Skills','equipment'=>'Equipment / Materials','mentorship'=>'Mentorship','other'=>'Other Contribution'];
    $ctype=$input['contribute_type'];
    $tlabel=$typeMap[$ctype]??'Contribution';
    $ref='INKIND_'.strtoupper($ctype[0]??'X').'_'.date('Ymd_His');
    $name =mb_substr(trim($input['name']),0,100);
    $email=trim($input['email']);
    $desc =mb_substr(trim($input['description']),0,2000);
    $camp=preg_replace('/[^a-z0-9_-]/','',strtolower($input['campaign']??'general'));
    $parts=explode(' ',$name,2);
    $initials=strtoupper(($parts[0][0]??'?').($parts[1][0]??''));
    $data=readData();
    array_unshift($data['donations'],[
        'id'=>$ref,'type'=>'inkind','reference'=>$ref,
        'name'=>$name,'initials'=>$initials,'email'=>$email,
        'anonymous'=>!empty($input['anonymous']),'amount'=>0,'currency'=>'NGN',
        'campaign'=>$camp,'frequency'=>'One-time','status'=>'confirmed',
        'inkind_type'=>$tlabel,'inkind_desc'=>$desc,
        'inkind_hours'=>(int)($input['hours_per_month']??0),
        'created_at'=>date('c'),
    ]);
    if (count($data['donations'])>500) $data['donations']=array_slice($data['donations'],0,500);
    $data['totals']['inkind']=($data['totals']['inkind']??0)+1;
    writeData($data);
    mail_send($email,'Thank You for Contributing to Afrovanguard 🙌',contributeHtml(['name'=>$name,'type_label'=>$tlabel,'description'=>$desc]));
    if (ENABLE_ADMIN_NOTIFICATIONS) {
        mail_send(ADMIN_EMAIL,"[Afrovanguard] New {$tlabel}: {$name}",
            "<h3>New {$tlabel}</h3><p><b>Name:</b> ".esc($name)."<br><b>Email:</b> ".esc($email)."<br><b>Campaign:</b> ".esc($camp)."<br><b>Details:</b> ".esc($desc)."</p>");
    }
    echo json_encode(['success'=>true,'message'=>"Thank you! We'll be in touch within 48 hours."]);
    exit;
}

/* ── bank_transfer_copy ──────────────────────────────────── */
if ($action==='bank_transfer_copy') {
    if (empty($input['email'])||empty($input['name'])){echo json_encode(['success'=>false,'message'=>'Name and email required']);exit;}
    if (!filter_var($input['email'],FILTER_VALIDATE_EMAIL)){echo json_encode(['success'=>false,'message'=>'Invalid email']);exit;}
    if (!ENABLE_BANK_TRANSFER_EMAIL){echo json_encode(['success'=>true]);exit;}
    $sent=mail_send($input['email'],'Bank Transfer Details — Afrovanguard Donation',bankHtml($input));
    echo json_encode(['success'=>$sent]);
    exit;
}

/* ── FIX C-02: verify_admin_token (dashboard auth probe) ─────────── */
if ($action === 'verify_admin_token') {
    $token = trim($input['admin_token'] ?? '');
    if (!defined('ADMIN_TOKEN') || !hash_equals(ADMIN_TOKEN, $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    } else {
        echo json_encode(['success' => true]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success'=>false,'message'=>'Unknown action']);