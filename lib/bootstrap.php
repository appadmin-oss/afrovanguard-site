<?php
/**
 * lib/bootstrap.php — single entry point for the Diary backend.
 *
 * Loads shared config + the modular data layer. Include this from any
 * diary endpoint (index.php, article.php, api.php). Everything the Diary
 * needs flows from here, so the pieces stay connected and in sync.
 */
declare(strict_types=1);

if (!defined('AV_ROOT')) define('AV_ROOT', dirname(__DIR__));

// ── .env loader ─────────────────────────────────────────────────────────────
// Shared cPanel hosting has no Composer/dotenv, and a `.env` file is otherwise
// just inert text — PHP never reads it, so every getenv() below returns false
// and the whole app behaves as "unconfigured" (no email, no admin, no AI…).
// Parse a .env file ourselves and populate the environment so getenv() sees it.
//
// Precedence: a variable ALREADY in the real environment (Apache SetEnv / PHP-FPM
// / system env) always wins and is never overwritten — the file only fills gaps.
// Location search (first readable wins): $AV_ENV_FILE, then one level ABOVE the
// web root (recommended — not web-served), then the app root. Keep `.env` out of
// the web root when you can; if it must live there, ensure the server denies it.
//
// Supported syntax (a pragmatic subset of the dotenv conventions):
//   • blank lines and `#` / `;` comment lines
//   • `export KEY=…` prefixes (space- or tab-separated)
//   • a leading UTF-8 BOM, and CRLF / CR / LF line endings
//   • single-quoted values  → fully literal (no escapes, no expansion)
//   • double-quoted values  → `\n \r \t \" \\` escapes + `${VAR}` expansion
//   • MULTI-LINE quoted values — a quote left open continues onto later lines
//     until its match, so a pasted service-account JSON or PEM key parses whole
//   • unquoted values        → trailing ` # comment` stripped, `${VAR}` expanded
//   • `${VAR}` resolves to the real environment, else an earlier line in this
//     file, else is left untouched (a literal `$` survives unless it begins
//     a well-formed `${NAME}`, so secrets containing `$` are never mangled).
(static function (): void {
    $candidates = [];
    $explicit = getenv('AV_ENV_FILE');
    if (is_string($explicit) && $explicit !== '') $candidates[] = $explicit;
    $candidates[] = dirname(AV_ROOT) . '/.env'; // above the web root (preferred)
    $candidates[] = AV_ROOT . '/.env';          // inside the app root (convenient)

    $file = null;
    foreach ($candidates as $c) {
        if (is_string($c) && $c !== '' && @is_file($c) && @is_readable($c) && (@filesize($c) ?: 0) <= 262144) { $file = $c; break; }
    }
    if ($file === null) return;

    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') return;

    // Drop a leading UTF-8 BOM (it would otherwise become part of the first key
    // name, so that variable would silently fail the key regex and be lost),
    // then normalise CRLF / CR to LF so line handling is uniform.
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $raw));
    $n     = count($lines);

    // Index of the next *unescaped* closing quote in $s (the content AFTER the
    // opening quote). Backslash escapes only count inside double quotes. -1 = none.
    $closeAt = static function (string $s, string $q): int {
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            if ($q === '"' && $s[$i] === '\\') { $i++; continue; }
            if (($s[$i] ?? '') === $q) return $i;
        }
        return -1;
    };

    $loaded = [];   // values resolved so far, so later lines can reference them
    $expand = static function (string $s) use (&$loaded): string {
        return preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/', static function (array $m) use (&$loaded): string {
            $env = getenv($m[1]);
            if ($env !== false && $env !== '') return $env;   // real env wins
            return $loaded[$m[1]] ?? $m[0];                   // earlier var, else leave literal
        }, $s) ?? $s;
    };

    for ($i = 0; $i < $n; $i++) {
        $line = trim($lines[$i]);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue; // blank / comment
        $line = preg_replace('/^export[ \t]+/', '', $line, 1) ?? $line;     // optional `export `

        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) continue;      // ignore junk keys

        $rest  = ltrim(substr($line, $eq + 1));
        $quote = ($rest !== '' && ($rest[0] === '"' || $rest[0] === "'")) ? $rest[0] : '';

        if ($quote !== '') {
            // Quoted: take the content up to the matching closing quote, pulling
            // in further lines while the quote stays open (multi-line secrets).
            $body  = substr($rest, 1);
            $close = $closeAt($body, $quote);
            while ($close < 0 && $i + 1 < $n) {
                $body .= "\n" . $lines[++$i];
                $close = $closeAt($body, $quote);
            }
            $val = $close >= 0 ? substr($body, 0, $close) : $body; // unterminated ⇒ take the rest
            if ($quote === '"') {
                // strtr is single-pass, so an escaped backslash (`\\`) is not
                // re-interpreted — unlike a sequence of str_replace() calls.
                $val = strtr($val, ['\\\\' => '\\', '\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"']);
                $val = $expand($val);
            }
            // Single quotes are fully literal — no escapes, no expansion.
        } else {
            // Unquoted: strip a trailing inline comment introduced by whitespace +
            // '#' (so the heavily-commented .env.example works once values are
            // filled in), keep a '#' that's part of the value (e.g. p#ss), expand.
            $val = rtrim((string) preg_replace('/\s+#.*$/', '', $rest));
            $val = $expand($val);
        }

        // Real environment wins; the file only fills what isn't already set. We
        // still record the live value so later `${VAR}` references resolve to it.
        $cur = getenv($key);
        if ($cur !== false && $cur !== '') { $loaded[$key] = $cur; continue; }

        putenv("{$key}={$val}");
        $_ENV[$key]    = $val;
        $_SERVER[$key] = $val;
        $loaded[$key]  = $val;
    }
})();

// Reuse the site's config.php if deployed; otherwise fall back to safe
// public defaults so the Diary runs standalone (and in local dev).
//
// config.php (the legacy donation/payments config) calls _av_require_env() for
// a handful of SECRETS and historically hard-exits the whole request if any is
// missing. The Diary / Academy / Portal / public team API don't need those
// secrets just to RENDER, so a missing payment/SMTP key must never take the
// public site down. Only load config.php when its required secrets are actually
// present; if any is missing we skip it and rely on the env-driven fallbacks
// below. (Endpoints that truly need a secret — donations, contact, admin —
// require config.php directly and validate their own prerequisites.)
$cfg = AV_ROOT . '/config.php';
if (is_file($cfg)) {
    $cfgSafe = true;
    foreach (['AV_SMTP_PASSWORD', 'AV_PAYSTACK_PK', 'AV_PAYSTACK_SK', 'AV_ADMIN_TOKEN'] as $__k) {
        $__v = getenv($__k);
        if ($__v === false || $__v === '') { $cfgSafe = false; break; }
    }
    if ($cfgSafe) {
        require_once $cfg;
    } else {
        error_log('[AV bootstrap] config.php present but a required secret env var is missing — '
            . 'serving the public site from env fallbacks. Set AV_SMTP_PASSWORD / AV_PAYSTACK_PK / '
            . 'AV_PAYSTACK_SK / AV_ADMIN_TOKEN (e.g. via .htaccess SetEnv) to restore '
            . 'donation/contact/admin features.');
    }
}
if (!defined('SITE_URL'))         define('SITE_URL', 'https://afrovanguard.org.ng');
if (!defined('AV_DB_PATH'))       define('AV_DB_PATH', getenv('AV_DB_PATH') ?: (AV_ROOT . '/db/diary.sqlite'));
// Organisation timezone. Data is stored UTC; this is the wall-clock members
// live in (Nigeria = WAT, UTC+1, no DST). Per-user overrides via Prefs.
if (!defined('AV_TZ'))            define('AV_TZ', getenv('AV_TZ') ?: 'Africa/Lagos');

// Admin token (config.php or AV_ADMIN_TOKEN env). Absent ⇒ admin disabled.
if (!defined('ADMIN_TOKEN')) {
    $t = getenv('AV_ADMIN_TOKEN');
    if ($t !== false && $t !== '') define('ADMIN_TOKEN', $t);
}
// Dedicated signing key for admin cookies + CSRF tokens (config.php or
// APP_KEY / AV_APP_KEY env). Keeping this separate from ADMIN_TOKEN lets the
// break-glass credential be rotated without logging every admin out. Absent ⇒
// av_secret() falls back to ADMIN_TOKEN (legacy single-secret behaviour).
if (!defined('APP_KEY')) {
    $k = getenv('APP_KEY') ?: getenv('AV_APP_KEY');
    if ($k !== false && $k !== '') define('APP_KEY', $k);
}
// Whether the scoped integrations API may share mentor EMAIL addresses with a
// trusted sister site (mentors.directory). Defaults to on for back-compat with
// the existing NextGenGen mirror; set AV_MENTORS_SHARE_EMAIL=0 to share only a
// stable cross-site `ref` (the mirror can dedupe on that) and omit the PII.
if (!defined('AV_MENTORS_SHARE_EMAIL')) {
    $v = getenv('AV_MENTORS_SHARE_EMAIL');
    define('AV_MENTORS_SHARE_EMAIL', $v === false || $v === '' ? true : !in_array(strtolower((string) $v), ['0', 'false', 'no', 'off'], true));
}
// Cloudinary (config.php or env). Absent ⇒ uploads fall back to local /uploads.
foreach (['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET'] as $k) {
    if (!defined($k)) { $v = getenv($k); if ($v !== false && $v !== '') define($k, $v); }
}
// Payments (Paystack primary — already used by donations — + optional Flutterwave).
// config.php normally defines PAYSTACK_PUBLIC_KEY / PAYSTACK_SECRET_KEY directly;
// also accept the donation system's AV_PAYSTACK_PK/SK env aliases as a fallback.
if (!defined('PAYSTACK_PUBLIC_KEY')) { $v = getenv('PAYSTACK_PUBLIC_KEY') ?: getenv('AV_PAYSTACK_PK'); if ($v) define('PAYSTACK_PUBLIC_KEY', $v); }
if (!defined('PAYSTACK_SECRET_KEY')) { $v = getenv('PAYSTACK_SECRET_KEY') ?: getenv('AV_PAYSTACK_SK'); if ($v) define('PAYSTACK_SECRET_KEY', $v); }
foreach (['FLW_PUBLIC_KEY', 'FLW_SECRET_KEY'] as $k) {
    if (!defined($k)) { $v = getenv($k); if ($v !== false && $v !== '') define($k, $v); }
}
if (!defined('AV_MEMBERSHIP_NGN')) { $v = getenv('AV_MEMBERSHIP_NGN'); define('AV_MEMBERSHIP_NGN', $v !== false && $v !== '' ? (int) $v : 5000); }
// Membership DUES (the "How Afrovanguard Works" framework contribution) — distinct
// from the Academy membership price above. Voluntary from Level A: ₦1,000/month
// or ₦12,000/year (mandatory from Level C).
if (!defined('AV_DUES_MONTHLY_NGN')) { $v = getenv('AV_DUES_MONTHLY_NGN'); define('AV_DUES_MONTHLY_NGN', $v !== false && $v !== '' ? (int) $v : 1000); }
if (!defined('AV_DUES_ANNUAL_NGN'))  { $v = getenv('AV_DUES_ANNUAL_NGN');  define('AV_DUES_ANNUAL_NGN',  $v !== false && $v !== '' ? (int) $v : 12000); }
// Optional Paystack Plan code for AUTO-RENEWING monthly dues. Create a monthly
// plan in the Paystack dashboard (amount = AV_DUES_MONTHLY_NGN) and put its
// plan_code (PLN_…) here. When set, the "Pay a month" button starts a recurring
// subscription; when empty, it falls back to a one-time monthly charge.
if (!defined('AV_DUES_PLAN_CODE')) { $v = getenv('AV_DUES_PLAN_CODE'); if ($v !== false && $v !== '') define('AV_DUES_PLAN_CODE', $v); }
// Google sign-in (config.php or env). Absent ⇒ the "Continue with Google" button stays disabled.
foreach (['AV_GOOGLE_CLIENT_ID', 'AV_GOOGLE_CLIENT_SECRET'] as $k) {
    if (!defined($k)) { $v = getenv($k); if ($v !== false && $v !== '') define($k, $v); }
}
// Document storage on Google Drive (service account). Absent ⇒ docs fall back to local /uploads.
foreach (['AV_GDRIVE_SERVICE_ACCOUNT', 'AV_GDRIVE_FOLDER_ID'] as $k) {
    if (!defined($k)) { $v = getenv($k); if ($v !== false && $v !== '') define($k, $v); }
}
// SMTP / email. config.php normally defines these; ALSO accept env so the
// documented SetEnv-based setup actually works. Without them, Mailer reports
// "not configured" and silently skips EVERY email — including sign-in codes
// and verification links. SMTP_PASSWORD falls back to the AV_SMTP_PASSWORD
// alias the donation config already uses.
foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_SECURE', 'FROM_EMAIL', 'FROM_NAME', 'ADMIN_EMAIL'] as $k) {
    if (!defined($k)) { $v = getenv($k); if ($v !== false && $v !== '') define($k, $v); }
}
// Accept the Africa GATES / NextGenGen (Brevo) env naming too, so one .env style
// works across the sister apps: SMTP_USER, SMTP_PASS, MAIL_FROM_ADDRESS/NAME.
$__envfirst = static function (array $keys) { foreach ($keys as $k) { $v = getenv($k); if ($v !== false && $v !== '') return (string) $v; } return null; };
if (!defined('SMTP_USERNAME')) { $v = $__envfirst(['SMTP_USERNAME', 'SMTP_USER']); if ($v !== null) define('SMTP_USERNAME', $v); }
if (!defined('SMTP_PASSWORD')) { $v = $__envfirst(['SMTP_PASSWORD', 'SMTP_PASS', 'AV_SMTP_PASSWORD']); if ($v !== null) define('SMTP_PASSWORD', $v); }
if (!defined('FROM_EMAIL'))    { $v = $__envfirst(['FROM_EMAIL', 'MAIL_FROM_ADDRESS']); if ($v !== null) define('FROM_EMAIL', $v); }
if (!defined('FROM_NAME'))     { $v = $__envfirst(['FROM_NAME', 'MAIL_FROM_NAME']); if ($v !== null) define('FROM_NAME', $v); }
if (!defined('SMTP_VERIFY')) { $v = getenv('SMTP_VERIFY'); if ($v !== false && $v !== '') define('SMTP_VERIFY', !in_array(strtolower((string) $v), ['0', 'false', 'no', 'off'], true)); }

// The Workspace domain whose VERIFIED accounts are recognised as real org members.
if (!defined('AV_ORG_DOMAIN')) define('AV_ORG_DOMAIN', getenv('AV_ORG_DOMAIN') ?: 'afrovanguard.org.ng');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DiaryRepository.php';
require_once __DIR__ . '/DiaryJournal.php';
// Notebooks, entry tabs and the finding half (tags / pin / archive / search).
// Loaded beside DiaryJournal because all three reach into diary_entries and
// have to agree with it about the columns they each add.
require_once __DIR__ . '/DiaryNotebooks.php';
require_once __DIR__ . '/DiaryTabs.php';
require_once __DIR__ . '/DiaryOrganise.php';
require_once __DIR__ . '/AcademyRepository.php';
require_once __DIR__ . '/Ngv.php';
require_once __DIR__ . '/NgvDb.php';       // separate NextGen Vanguard database (isolated connection)
require_once __DIR__ . '/NgvMember.php';   // NGV participant/fees/certifications domain
require_once __DIR__ . '/AuthPolicy.php';
require_once __DIR__ . '/Otp.php';
require_once __DIR__ . '/LmsAuth.php';
require_once __DIR__ . '/LmsRepository.php';
require_once __DIR__ . '/GoogleAuth.php';
require_once __DIR__ . '/Payments.php';
// Email is sent exclusively through PHPMailer (see lib/Mailer.php). The former
// hand-rolled SMTP client (lib/Smtp.php) is retired and no longer loaded.
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Notify.php';
require_once __DIR__ . '/Events.php';
require_once __DIR__ . '/Webhooks.php';
require_once __DIR__ . '/AppTokens.php';
require_once __DIR__ . '/AvBot.php';
require_once __DIR__ . '/Gemini.php';
require_once __DIR__ . '/OpenAi.php';
require_once __DIR__ . '/AvEvents.php';
require_once __DIR__ . '/Mentorship.php';
require_once __DIR__ . '/Prefs.php';
require_once __DIR__ . '/Notifications.php';
require_once __DIR__ . '/AdminAudit.php';
require_once __DIR__ . '/AdminRoles.php';
require_once __DIR__ . '/SuperAdmin.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Sitemap.php';
require_once __DIR__ . '/AuthArt.php';
require_once __DIR__ . '/Cloudinary.php';
require_once __DIR__ . '/Drive.php';
require_once __DIR__ . '/GoogleWorkspace.php';
require_once __DIR__ . '/GoogleWorkspaceUser.php';
require_once __DIR__ . '/GoogleWatch.php';
require_once __DIR__ . '/AvAutomation.php';
require_once __DIR__ . '/Collab.php';
require_once __DIR__ . '/RecallBot.php';
require_once __DIR__ . '/AttendeeBot.php';
require_once __DIR__ . '/Meetings.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/Tts.php';
require_once __DIR__ . '/Chioma.php';
require_once __DIR__ . '/AvRules.php';
require_once __DIR__ . '/AvKnowledge.php';
require_once __DIR__ . '/AvPrompts.php';
// The AI's tools, its window onto the web, and the loop that lets it use them.
require_once __DIR__ . '/Commitments.php';
require_once __DIR__ . '/AvWeb.php';
require_once __DIR__ . '/AvTools.php';
require_once __DIR__ . '/AvAgent.php';
require_once __DIR__ . '/AvLab.php';
// The engine that acts on the rules: meeting cadence, the escalation ladder and
// relationship health (report §13, §14, §15). Loads after Mentorship, AvRules,
// AvPrompts and Notifications, all of which it reads.
require_once __DIR__ . '/Accountability.php';
require_once __DIR__ . '/AvSettings.php';
// Publish Studio-managed credentials into the process environment, so classes
// that read a bare getenv() (Meetings::botProvider() among them) see them without
// any change of their own. Config::get() consults the same store first.
AvSettings::apply();
require_once __DIR__ . '/AiKnowledge.php';
require_once __DIR__ . '/Levels.php';
require_once __DIR__ . '/IQ.php';
require_once __DIR__ . '/ErrorPoem.php';

av_harden_errors();

// Keep the AI assistants' knowledge fresh: every domain event invalidates the
// cached "site brief" so Chioma / AvBot are re-fed on the next reply.
AiKnowledge::boot();
// Membership progression: capture referrals on sign-up.
Levels::boot();
// Workspace automation: subscribe event handlers (onboarding, real-time, etc.).
AvAutomation::register();
// Collaboration: feed domain events into the team activity stream.
Collab::bootEvents();

// Referral links (/…?ref=<memberId>) drop a short-lived cookie that is consumed
// when the invited person creates their account (see Levels::boot()).
if (isset($_GET['ref']) && ctype_digit((string) $_GET['ref']) && empty($_COOKIE['av_ref']) && !headers_sent()) {
    @setcookie('av_ref', (string) (int) $_GET['ref'], ['expires' => time() + 2592000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}

/** Academy base URL (subdomain-ready). Defaults to the /academy path. */
if (!defined('ACADEMY_URL')) {
    $env = getenv('AV_ACADEMY_URL');
    define('ACADEMY_URL', $env ?: (rtrim(SITE_URL, '/') . '/academy'));
}
function academy_url(string $path = ''): string { return rtrim(ACADEMY_URL, '/') . '/' . ltrim($path, '/'); }

/** Member portal base URL (subdomain-ready). Set AV_PORTAL_URL to e.g.
 *  https://members.afrovanguard.org.ng to serve the portal on its own host
 *  (same docroot); defaults to the /portal path on the main domain. */
if (!defined('PORTAL_URL')) {
    $env = getenv('AV_PORTAL_URL');
    define('PORTAL_URL', $env ?: (rtrim(SITE_URL, '/') . '/portal'));
}
function portal_url(string $path = ''): string { return rtrim(PORTAL_URL, '/') . '/' . ltrim($path, '/'); }

/**
 * Gate an endpoint behind admin auth: a valid signed session cookie OR a
 * Bearer admin token (break-glass). State-changing cookie requests must
 * also carry a valid CSRF header (call av_csrf_require() in the route).
 */
function require_admin(): void {
    if (!av_admin_token_configured()) {
        json_out(['ok' => false, 'error' => 'Admin is not configured on this server.'], 503);
    }
    // Role-aware: break-glass token = superadmin; member-admins (admin_users) get
    // their level. av_admin_role() returns '' when not an admin at all.
    if (function_exists('av_admin_role') ? av_admin_role() !== '' : (av_admin_cookie_valid() || av_admin_bearer_ok())) return;
    json_out(['ok' => false, 'error' => 'Unauthorized.'], 401);
}
