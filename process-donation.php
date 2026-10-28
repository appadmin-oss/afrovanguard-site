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
    'DONATIONS_FROM_EMAIL' => 'donations@afrovanguard.org.ng', 'DONATIONS_FROM_NAME' => 'Afrovanguard',
] as $__k => $__v) { if (!defined($__k)) define($__k, $__v); }
// General site email defaults to the org's main mailbox (cacentre@); only
// donation receipts send from donations@ (handled in mail_send()).
if (!defined('FROM_EMAIL')) define('FROM_EMAIL', 'cacentre@afrovanguard.org.ng');

/*
 * OUR OWN PAYSTACK, WHERE WE HAVE IT.
 *
 * This used to be a hard 503 at file scope: no secret key, no donations
 * endpoint at all. It took the whole donate page down rather than the card
 * option — the donor wall, the thermometer and the in-kind form all answer
 * from this file and none of them touches Paystack. A site with no merchant
 * account showed a broken page where it should have shown a working one and
 * a different way to give.
 *
 * So the gate moved to the actions that genuinely need the key, and the
 * actions that do not are left alone. `av_ps_ready()` is the question;
 * `av_ps_require()` is the refusal, said once in one place.
 */
function av_ps_ready(): bool {
    return defined('PAYSTACK_SECRET_KEY') && (string) PAYSTACK_SECRET_KEY !== '';
}

function av_ps_require(string $what): void {
    if (av_ps_ready()) return;
    error_log('[AV] process-donation: PAYSTACK_SECRET_KEY missing — ' . $what . ' unavailable until env is set.');
    http_response_code(503);
    echo json_encode(['success'=>false,'message'=>'That payment method is temporarily unavailable. Please try again shortly, or use bank transfer.']);
    exit;
}

/* Can a card be taken at all — by us, or by CACENTRE on our behalf? */
function av_card_route(): string {
    if (av_ps_ready()) return 'paystack';
    if (class_exists('CfisCheckout') && CfisCheckout::configured()) return 'cfis';
    return '';
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
// Donor PII must not sit in the web root behind only a by-name .htaccess deny.
// av_private_path() resolves a defense-in-depth location (AV_PRIVATE_DIR above
// the web root, else <root>/db/private which the server already denies) and
// migrates any legacy web-root donations.json into it on first use.
define('DATA_FILE', av_private_path('donations.json'));

/* The donation store lives in lib/Donations.php. It moved out of this file so
   the OTHER webhook address can reach it: a gateway posts to one URL and this
   site has two money handlers, so each has to be able to hand a charge to the
   other (lib/PaymentWebhook.php). These stay as the names the rest of this
   999-line script calls. */
function defaultData(): array { return Donations::defaultData(); }
function readData(): array { return Donations::read(); }
function writeData(array $data): bool { return Donations::write($data); }


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
 * One donation, recorded once. The reference is claimed in a table that never
 * forgets — the file keeps only the newest 500, so a webhook redelivered for
 * an older reference used to be counted a second time, moving the campaign
 * total and the donor count with it.
 */
function storeDonationIfNew(string $ref, array $entry, float $amount, string $campaign, string $currency): bool {
    return Donations::storeIfNew($ref, $entry, $amount, $campaign, $currency);
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
/* The designed templates own their own subject line, so this is mail_send
   with the subject taken out and the template put in. Same donations@ sender,
   same Mailer roads. */
function mail_send_tpl(string $to, array $spec): bool {
    if (!ENABLE_EMAIL_NOTIFICATIONS) return true;
    if (!class_exists('Mailer') || !method_exists('Mailer', 'sendTemplate')) return false;
    $dfrom = defined('DONATIONS_FROM_EMAIL') && DONATIONS_FROM_EMAIL !== '' ? DONATIONS_FROM_EMAIL : 'donations@afrovanguard.org.ng';
    $dname = defined('DONATIONS_FROM_NAME') && DONATIONS_FROM_NAME !== '' ? DONATIONS_FROM_NAME : (defined('FROM_NAME') ? FROM_NAME : 'Afrovanguard');
    [$tpl, $vars] = $spec;
    $ok = Mailer::sendTemplate($to, $tpl, $vars, ['from' => $dfrom, 'fromName' => $dname, 'replyTo' => $dfrom]);
    if (!$ok) error_log('[AV] donation mail to ' . $to . ': ' . (method_exists('Mailer', 'lastError') ? Mailer::lastError() : 'send failed'));
    return $ok;
}

function mail_send(string $to, string $sub, string $html): bool {
    if (!ENABLE_EMAIL_NOTIFICATIONS) return true;
    // Deliver through the one shared Mailer: PHPMailer over authenticated SMTP
    // (with the 587→465 fallback), then Resend/HTTPS, then mail(). Donation mail
    // sends FROM the donations@ mailbox (everything else defaults to cacentre@).
    if (class_exists('Mailer') && method_exists('Mailer', 'send')) {
        $dfrom = defined('DONATIONS_FROM_EMAIL') && DONATIONS_FROM_EMAIL !== '' ? DONATIONS_FROM_EMAIL : 'donations@afrovanguard.org.ng';
        $dname = defined('DONATIONS_FROM_NAME') && DONATIONS_FROM_NAME !== '' ? DONATIONS_FROM_NAME : (defined('FROM_NAME') ? FROM_NAME : 'Afrovanguard');
        $ok = Mailer::send($to, $sub, $html, ['from' => $dfrom, 'fromName' => $dname, 'replyTo' => $dfrom]);
        if (!$ok) error_log('[AV] donation mail to ' . $to . ': ' . (method_exists('Mailer', 'lastError') ? Mailer::lastError() : 'send failed'));
        return $ok;
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

/* These used to carry their own <style> block and class names. Gmail strips
   <style>, so for most recipients a receipt arrived as unstyled default text.
   They use the shared template now — one look across every message the site
   sends, inline styles, the mark at the foot. */

/* modules/EMAILS.md: donation_receipt. Display-ready strings only — the
   template escapes, so nothing here is pre-escaped. */
function receiptHtml(array $d): array {
    $sym = (string) ($d['currency'] ?? 'NGN');
    return [
        'donation_receipt',
        [
            'first_name'  => trim((string) ($d['firstName'] ?? '')) ?: 'friend',
            'amount'      => ($sym === 'NGN' ? '₦' : $sym . ' ') . number_format((float) ($d['amount'] ?? 0), 2),
            'receipt_no'  => (string) ($d['reference'] ?? '—'),
            'paid_on'     => date('j M Y'),
            'method'      => (string) ($d['method'] ?? 'Card · Paystack'),
            'designation' => (string) ($d['campaign'] ?? 'Where most needed'),
            'receipt_url' => 'https://afrovanguard.org.ng/give/receipt/' . rawurlencode((string) ($d['reference'] ?? '')),
        ],
    ];
}

function bankHtml(array $d): string {
    return Mailer::shell(
        'Complete your donation',
        [
            '<strong>' . esc($d['name'] ?? 'Dear Supporter') . ',</strong>',
            'Thank you for choosing to support Afrovanguard. Please use the details below to complete your transfer.',
            Mailer::facts([
                ['Bank',           BANK_NAME],
                ['Account name',   ACCOUNT_NAME],
                ['Account number', ACCOUNT_NUMBER],
                ['Sort code',      BANK_CODE],
            ]),
            Mailer::panel('After transferring',
                'Send your name, amount and date to '
                . '<a href="mailto:donations@afrovanguard.org.ng" style="color:#8A6406;text-decoration:underline">donations@afrovanguard.org.ng</a>'
                . ' to receive your impact receipt.'),
        ],
        null,
        'Bank transfer details for your donation.',
        ['eyebrow' => 'Bank transfer']
    );
}

function contributeHtml(array $d): string {
    $type = $d['type_label'] ?? 'Contribution';
    return Mailer::shell(
        'Thank you for your ' . $type,
        [
            '<strong>Vanguard ' . esc($d['name'] ?? 'Supporter') . ',</strong>',
            "Your non-monetary contribution to Afrovanguard has been received. Every form of giving — time, skills, and resources — powers our mission.",
            Mailer::facts([
                ['Contribution type', $type],
                ['Description',       $d['description'] ?? ''],
            ]),
            "Our team will reach out within 48 hours to coordinate. Thank you for being part of Africa's transformation.",
            'Warm regards,<br><strong>The Afrovanguard Team</strong>',
        ],
        null,
        'We have your contribution.',
        ['eyebrow' => 'Contribution received', 'note' => 'You submitted a contribution to Afrovanguard.']
    );
}

function adminHtml(array $d, string $label): string {
    $cur = $d['currency'] ?? 'NGN';
    $amt = number_format((float) ($d['amount'] ?? 0), 2);
    return Mailer::shell(
        $label . ' — ' . $cur . ' ' . $amt,
        [
            Mailer::facts([
                ['Name',     trim(($d['firstName'] ?? $d['name'] ?? 'Unknown') . ' ' . ($d['lastName'] ?? ''))],
                ['Email',    $d['email'] ?? '—'],
                ['Campaign', $d['campaign'] ?? '—'],
                ['Ref',      $d['reference'] ?? '—'],
                ['Date',     date('Y-m-d H:i:s T')],
            ]),
        ],
        ['text' => 'Open donor dashboard', 'url' => 'https://afrovanguard.org.ng/donor-dashboard'],
        $label . ' — ' . $cur . ' ' . $amt,
        ['eyebrow' => 'Internal', 'note' => 'Internal notification — do not forward.']
    );
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
        /* The virtual-account poll, which is OUR transaction at Paystack.
           A hosted charge is asked about through record_donation instead. */
        if (!av_ps_ready()) { echo json_encode(['paid'=>false,'status'=>'unavailable']); exit; }
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

/* Paystack webhook. Nothing signed can be ours without the key that signs
   it, so a site with no key refuses rather than reading an undefined
   constant and comparing against a hash of the empty string. */
if ($sig && !av_ps_ready()) {
    http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unsigned.']); exit;
}
if ($sig && hash_equals(hash_hmac('sha512',$raw,PAYSTACK_SECRET_KEY),$sig)) {
    /* ONE FRONT DOOR. A gateway posts to one address, and this site has two
       money handlers; whichever address is in the dashboard, the other kinds
       used to be mis-recorded or lost — a dues or course charge filed as a
       donation here, a donation granting somebody a month of membership at
       /academy/pay.php. lib/PaymentWebhook routes by kind, and both addresses
       hand it the same event. The subscription events, the NGV fee branch and
       the donation itself all moved there intact. */
    $r = PaymentWebhook::handle(json_decode($raw, true) ?: []);
    http_response_code((int) ($r['status'] ?? 200));
    echo json_encode([
        'received' => true,                              // the signature was ours
        'recorded' => !empty($r['ok']),                  // and the money landed where it belongs
        'kind'     => (string) ($r['kind'] ?? ''),
        'reason'   => (string) ($r['error'] ?? ''),
    ]);
    exit;
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
/* These two send an email to whatever address the form gives, with text the
   form chose — a mail relay at 60 a minute. A real donor sends one or two. */
if (in_array($action, ['submit_contribute','bank_transfer_copy'], true) && !rateLimit('mail_' . $clientIp, 5, 900)) {
    http_response_code(429);
    echo json_encode(['success'=>false,'message'=>'Too many requests. Please wait a few minutes.']);
    exit;
}
if (in_array($action, ['init_payment','generate_virtual_account','record_donation','record_bank_transfer'])) {
    if (!rateLimit('pay_' . $clientIp, 10, 60)) {
        http_response_code(429);
        echo json_encode(['success'=>false,'message'=>'Too many payment requests. Please wait one minute.']);
        exit;
    }
}

/* ── init_payment — start a hosted Paystack checkout (redirect flow) ──────
 * The card donation uses Paystack's hosted checkout (server-side initialize →
 * redirect to checkout.paystack.com), NOT the inline popup. This avoids loading
 * js.paystack.co in the browser (which the site CSP blocks) and mirrors the
 * working portal dues flow. On return Paystack redirects to /donate?reference=…
 * where the page calls record_donation to server-verify + store + receipt. */
if ($action==='init_payment') {
    $email = trim($input['email']??'');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success'=>false,'message'=>'A valid email is required.']); exit; }
    $amount = (float)($input['amount']??0);
    $cur = strtoupper(preg_replace('/[^A-Za-z]/','',$input['currency']??'NGN'));
    // Paystack supports these; anything else (e.g. GBP) can't be charged by card —
    // don't silently convert it to NGN, tell the donor to use NGN or bank transfer.
    if (!in_array($cur, ['NGN','USD','GHS','ZAR','KES'], true)) {
        echo json_encode(['success'=>false,'message'=>'Card payment isn’t available in '.$cur.'. Please choose NGN, or use bank transfer.']); exit;
    }
    if ($amount <= 0) { echo json_encode(['success'=>false,'message'=>'Choose a valid donation amount.']); exit; }
    $fn = mb_substr(trim($input['firstName']??''),0,80);
    $ln = mb_substr(trim($input['lastName']??''),0,80);
    $camp = preg_replace('/[^a-z0-9_-]/','',strtolower($input['campaign']??'general'));
    $allowed_freq = ['One-time','Monthly','Annual'];
    $freq = in_array($input['frequency']??'', $allowed_freq, true) ? $input['frequency'] : 'One-time';
    $anon = !empty($input['anonymous']);
    $msg  = mb_substr(trim($input['message']??''),0,500);
    $ref  = 'AV_'.time().'_'.strtoupper(bin2hex(random_bytes(4)));
    $callback = rtrim(SITE_URL,'/').'/donate';

    /*
     * CACENTRE COLLECTS WHEN WE CANNOT.
     *
     * Without this, a site with no merchant account could take no card
     * donation at all — the one page whose entire purpose is to accept
     * money. Our own keys still win whenever we have them: the money
     * reaches our bank the same day and nobody holds it for us.
     *
     * NGN ONLY on this route, said plainly rather than converted. The
     * hosted checkout's amounts are kobo and its charges are naira; silently
     * treating a donor's $50 as ₦50 would be the worst kind of helpful.
     */
    if (av_card_route() === 'cfis') {
        if ($cur !== 'NGN') {
            echo json_encode(['success'=>false,'message'=>'Card payment is only available in NGN at the moment. Please choose NGN, or use bank transfer.']); exit;
        }
        $hosted = CfisCheckout::open(
            $ref, 'donation', (int) round($amount * 100), $email,
            'Donation to Afrovanguard' . ($camp && $camp !== 'general' ? ' — ' . $camp : ''),
            trim("$fn $ln"),
            $callback,
            ['campaign'=>$camp,'frequency'=>$freq,'anonymous'=>$anon?'Yes':'No',
             'first_name'=>$fn,'last_name'=>$ln,'message'=>$msg ?: '—','email'=>$email]
        );
        if (empty($hosted['ok']) || ($hosted['url'] ?? '') === '') {
            error_log('[AV] init_payment (hosted) failed: '.json_encode($hosted['error'] ?? ''));
            echo json_encode(['success'=>false,'message'=>(string) ($hosted['error'] ?? 'Could not start the payment. Please try again in a moment.')]); exit;
        }
        /* No access_code: the inline modal resumes a transaction on OUR
           Paystack, and this one is not ours to resume. The caller
           redirects when there is none.

           KNOWN GAP, named rather than hidden. Our own Paystack also sends
           a webhook, retried until acknowledged, so a donor who closes the
           tab is still recorded here. CACENTRE's checkout pushes nothing:
           the gift IS recorded at CACENTRE and the money is accounted for,
           but this site's donor wall and thermometer will not show it and
           no receipt goes out until somebody asks. Closing that needs a
           pending record and a sweep, as the academy and NGV flows have —
           it is not done here because nothing stores a started donation. */
        echo json_encode([
            'success'           => true,
            'authorization_url' => (string) $hosted['url'],
            'access_code'       => '',
            'reference'         => $ref,
        ]); exit;
    }

    av_ps_require('card donation');
    $r = ps('POST','/transaction/initialize',[
        'email'        => $email,
        'amount'       => (int) round($amount * 100),
        'currency'     => $cur,
        'reference'    => $ref,
        'callback_url' => $callback,
        'metadata'     => [
            'campaign' => $camp, 'frequency' => $freq, 'anonymous' => $anon ? 'Yes' : 'No',
            'first_name' => $fn, 'last_name' => $ln, 'message' => $msg ?: '—',
            'custom_fields' => [
                ['display_name'=>'Campaign','variable_name'=>'campaign','value'=>$camp],
                ['display_name'=>'Frequency','variable_name'=>'frequency','value'=>$freq],
                ['display_name'=>'Anonymous','variable_name'=>'anonymous','value'=>$anon?'Yes':'No'],
            ],
        ],
    ]);
    if (!($r['status']??false) || empty($r['data']['authorization_url'])) {
        error_log('[AV] init_payment failed: '.json_encode($r['message']??''));
        echo json_encode(['success'=>false,'message'=>'Could not start the payment. Please try again in a moment.']); exit;
    }
    /* access_code rides along so a caller can open Paystack INLINE — the
       modal on the page they are already on — instead of navigating to
       checkout.paystack.com and back. The transaction is still initialised
       here, server-side, so the amount, the reference and the metadata are
       ours and not the browser's; the code only resumes what we started.
       authorization_url stays for callers that still redirect, and as the
       fallback when the inline script cannot load. */
    echo json_encode([
        'success'           => true,
        'authorization_url' => $r['data']['authorization_url'],
        'access_code'       => (string) ($r['data']['access_code'] ?? ''),
        'reference'         => $ref,
    ]); exit;
}

/* ── record_donation ─────────────────────────────────────── */
if ($action==='record_donation') {
    $ref=trim($input['reference']??'');
    if (!preg_match('/^[A-Za-z0-9_\-]{5,100}$/',$ref)) {
        echo json_encode(['success'=>false,'message'=>'Invalid reference']); exit;
    }
    /*
     * Server-side verification — the amount comes from whoever took the
     * money, never from the client.
     *
     * ASKED OF THE ROUTE THAT TOOK IT. A charge CACENTRE collected does not
     * exist in our Paystack account, so asking Paystack about it finds
     * nothing and tells a donor who has just given that they have not. Our
     * own account is asked first where we have one; CACENTRE only if that
     * finds nothing, which is a read either way and can never turn an
     * unpaid reference into a paid one.
     */
    $amount = 0.0; $currency = 'NGN'; $email = trim($input['email']??''); $paid = false;

    if (av_ps_ready()) {
        $r=ps('GET','/transaction/verify/'.urlencode($ref));
        $tx=$r['data']??[];
        if (($tx['status']??'')==='success') {
            $paid    = true;
            $amount  = (float)($tx['amount']??0)/100;
            $currency= strtoupper($tx['currency']??'NGN');
            $email   = $tx['customer']['email'] ?? $email;
        }
    }

    if (!$paid && class_exists('CfisCheckout') && CfisCheckout::configured()) {
        $h = CfisCheckout::status($ref);
        if (!empty($h['paid'])) {
            $paid    = true;
            $amount  = ((int) ($h['amount_minor'] ?? 0)) / 100;
            $currency= ($h['currency'] ?? '') ?: 'NGN';
            /* The donor's address as WE sent it. CACENTRE hands a property
               its own metadata back for exactly this; the fallback is the
               form field, which is also what the direct route falls back
               to when Paystack returns no customer. */
            $email   = (string) ($h['metadata']['email'] ?? '') ?: $email;
        }
    }

    if (!$paid) {
        echo json_encode(['success'=>false,'message'=>'Payment not yet verified. Your receipt will arrive once confirmed.']); exit;
    }
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
            mail_send_tpl($email,receiptHtml([
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
    /* A dedicated virtual account is minted on OUR Paystack business and
       belongs to it. There is no hosted equivalent — CACENTRE's checkout
       takes a card, not a bank transfer into an account in our name — so
       this one says so rather than pretending. The static bank details
       below are the answer for a site without keys. */
    av_ps_require('virtual-account bank transfer');
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
    if (!av_admin_token_matches($token)){
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
    mail_send_tpl($email,receiptHtml([
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
    if (!av_admin_token_matches($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    } else {
        echo json_encode(['success' => true]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success'=>false,'message'=>'Unknown action']);