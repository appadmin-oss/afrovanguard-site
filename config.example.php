<?php
/**
 * Afrovanguard Donation System — Configuration EXAMPLE
 *
 * Copy this file to config.php and fill in real values,
 * OR (preferred for shared hosting) set the env vars below
 * in your .htaccess (which is excluded from git):
 *
 *   SetEnv AV_SMTP_PASSWORD   your_gmail_app_password
 *   SetEnv AV_PAYSTACK_PK     pk_live_...
 *   SetEnv AV_PAYSTACK_SK     sk_live_...
 *   SetEnv AV_ADMIN_TOKEN     <output of: php -r "echo bin2hex(random_bytes(32));">
 *
 * NEVER commit config.php or .htaccess to git.
 */

function _av_require_env(string $name): string {
    $v = getenv($name);
    if ($v === false || $v === '') {
        http_response_code(500);
        error_log("[AV] Missing required environment variable: {$name}");
        echo json_encode(['success' => false, 'message' => 'Server configuration error.']);
        exit;
    }
    return $v;
}

/* ─── Email (SMTP) ──────────────────────────────────────────── */
define('SMTP_HOST',     'smtp.gmail.com');
define('SMTP_PORT',      587);
define('SMTP_USERNAME', 'donations@afrovanguard.org.ng');
define('SMTP_PASSWORD', _av_require_env('AV_SMTP_PASSWORD'));
define('FROM_EMAIL',    'donations@afrovanguard.org.ng');
define('FROM_NAME',     'Afrovanguard');
define('ADMIN_EMAIL',   'cacentre@afrovanguard.org.ng');

/* ─── Paystack ──────────────────────────────────────────────── */
define('PAYSTACK_PUBLIC_KEY', _av_require_env('AV_PAYSTACK_PK'));
define('PAYSTACK_SECRET_KEY', _av_require_env('AV_PAYSTACK_SK'));

/* ─── Static Bank Account (Zenith) ─────────────────────────── */
define('BANK_NAME',      'Zenith Bank');
define('ACCOUNT_NAME',   'AMBASSADORS FOR COMMUNITY, TECH AND CULTURAL ADVANCEMENTS');
define('ACCOUNT_NUMBER', '1229629683');
define('BANK_CODE',      '057');

/* ─── Admin ─────────────────────────────────────────────────── */
define('ADMIN_TOKEN', _av_require_env('AV_ADMIN_TOKEN'));

/* ─── Application ───────────────────────────────────────────── */
define('SITE_URL',            'https://afrovanguard.org.ng');
define('CURRENCY_DEFAULT',    'NGN');
define('MIN_DONATION_AMOUNT',  1000);

define('TAX_RECEIPT_THRESHOLD_NGN', 5000);
define('TAX_RECEIPT_THRESHOLD_USD',    5);
define('TAX_RECEIPT_THRESHOLD_GBP',    5);

define('ENABLE_EMAIL_NOTIFICATIONS',  true);
define('ENABLE_ADMIN_NOTIFICATIONS',  true);
define('ENABLE_MONTHLY_RECURRING',    true);
define('ENABLE_BANK_TRANSFER_EMAIL',  true);

return [
    'smtp'     => ['host'=>SMTP_HOST,'port'=>SMTP_PORT,'username'=>SMTP_USERNAME,'password'=>SMTP_PASSWORD],
    'email'    => ['from'=>FROM_EMAIL,'from_name'=>FROM_NAME,'admin'=>ADMIN_EMAIL],
    'paystack' => ['public_key'=>PAYSTACK_PUBLIC_KEY,'secret_key'=>PAYSTACK_SECRET_KEY],
    'bank'     => ['name'=>BANK_NAME,'account_name'=>ACCOUNT_NAME,'account_number'=>ACCOUNT_NUMBER,'code'=>BANK_CODE],
];
