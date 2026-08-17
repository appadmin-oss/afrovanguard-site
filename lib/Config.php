<?php
/**
 * lib/Config.php — one place to READ configuration and to ANSWER "is this
 * configured?".
 *
 * Configuration on this site arrives from two compatible sources: constants
 * defined in config.php (deployment secrets) and AV_* environment variables
 * (set via SetEnv). Historically each consumer reached for `defined()`/`getenv()`
 * inline, which made the picture hard to see. Config centralises that:
 *
 *   Config::get('SITE_URL', 'https://…')   // constant first, then env, then default
 *   Config::str / Config::int / Config::bool
 *   Config::has('PAYSTACK_PUBLIC_KEY', 'PAYSTACK_SECRET_KEY')   // all present & non-empty?
 *   Config::diagnostics()                   // structured health of every integration
 *
 * It is additive and non-breaking — existing constants keep working; this just
 * gives new code a single accessor and powers the Studio → System page.
 */
declare(strict_types=1);

final class Config
{
    /**
     * Studio setting → constant (if defined & non-empty) → env var → default.
     *
     * The Studio comes first so an administrator who sets a key on the Setup
     * screen does not have to also discover that a stale variable somewhere is
     * quietly beating them. AvSettings only answers for keys in its own
     * registry and is fully guarded, so this stays safe on the early paths where
     * the database may not exist yet.
     */
    public static function get(string $key, $default = null)
    {
        if (class_exists('AvSettings')) {
            $s = AvSettings::get($key);
            if ($s !== null && $s !== '') return $s;
        }
        if (defined($key)) { $v = constant($key); if ($v !== '' && $v !== null) return $v; }
        $e = getenv($key);
        return ($e !== false && $e !== '') ? $e : $default;
    }

    public static function str(string $key, string $default = ''): string { return (string) self::get($key, $default); }
    public static function int(string $key, int $default = 0): int { return (int) self::get($key, $default); }
    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default);
        if (is_bool($v)) return $v;
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** True only if every named key resolves to a non-empty value. */
    public static function has(string ...$keys): bool
    {
        foreach ($keys as $k) { $v = self::get($k); if ($v === null || $v === '') return false; }
        return true;
    }

    /* ── Health / diagnostics ── */
    private static function chk(string $label, string $state, string $detail = ''): array
    {
        return ['label' => $label, 'state' => $state, 'detail' => $detail];   // state: ok|warn|off|info
    }

    /** Structured status of every integration + the runtime. Resilient: each probe is guarded. */
    public static function diagnostics(): array
    {
        $groups = [];

        // Runtime
        $rt = [];
        $rt[] = self::chk('PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.0', '>=') ? 'ok' : 'warn', PHP_VERSION);
        foreach (['curl', 'gd', 'pdo_sqlite', 'pdo_mysql', 'pdo_pgsql', 'mbstring', 'openssl'] as $ext) {
            $rt[] = self::chk('ext: ' . $ext, extension_loaded($ext) ? 'ok' : ($ext === 'pdo_sqlite' ? 'off' : 'info'), extension_loaded($ext) ? 'loaded' : 'not loaded');
        }
        $rt[] = self::chk('fastcgi_finish_request', function_exists('fastcgi_finish_request') ? 'ok' : 'info', function_exists('fastcgi_finish_request') ? 'available (webhooks flush after response)' : 'not available (webhooks flush at shutdown / cron)');
        $groups[] = ['group' => 'Runtime', 'checks' => $rt];

        // Database
        $db = [];
        try {
            $pdo = Database::pdo();
            $drv = Database::driver();
            $fell = Database::fellBack();
            $db[] = $fell
                ? self::chk('Driver', 'warn', $drv . ' — requested ' . strtoupper($fell) . ' but it was unreachable; check AV_DB_HOST/NAME/USER/PASS')
                : self::chk('Driver', 'ok', $drv);
            $db[] = self::chk('Connection', 'ok', 'connected');
            $tables = $drv === 'sqlite'
                ? (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn()
                : (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables' . ($drv === 'mysql' ? ' WHERE table_schema = DATABASE()' : " WHERE table_schema='public'"))->fetchColumn();
            $db[] = self::chk('Tables', 'info', (string) $tables);
            $arts = (int) $pdo->query('SELECT COUNT(*) FROM articles')->fetchColumn();
            $users = (int) $pdo->query('SELECT COUNT(*) FROM lms_users')->fetchColumn();
            $db[] = self::chk('Content', 'info', $arts . ' articles · ' . $users . ' accounts');
        } catch (Throwable $e) {
            $db[] = self::chk('Connection', 'off', $e->getMessage());
        }
        $groups[] = ['group' => 'Database', 'checks' => $db];

        // Email (required for verification + notifications)
        $mail = self::has('SMTP_HOST', 'SMTP_USERNAME') && (self::has('SMTP_PASSWORD') || self::has('AV_SMTP_PASSWORD'));
        $groups[] = ['group' => 'Email (SMTP)', 'checks' => [
            self::chk('SMTP credentials', $mail ? 'ok' : 'off', $mail ? self::str('SMTP_HOST') : 'missing — email verification & notifications are disabled'),
            self::chk('From address', self::has('FROM_EMAIL') ? 'ok' : 'info', self::str('FROM_EMAIL', '—')),
        ]];

        // Payments (required for donations / paid courses)
        $pay = self::has('PAYSTACK_PUBLIC_KEY', 'PAYSTACK_SECRET_KEY');
        $groups[] = ['group' => 'Payments', 'checks' => [
            self::chk('Paystack keys', $pay ? 'ok' : 'off', $pay ? 'configured' : 'missing — donations & paid enrolment disabled'),
            self::chk('Flutterwave (optional)', self::has('FLW_SECRET_KEY') ? 'ok' : 'info', self::has('FLW_SECRET_KEY') ? 'configured' : 'not set'),
        ]];

        // Identity / Google
        $oauth = class_exists('GoogleAuth') && GoogleAuth::configured();
        $wsApi = class_exists('GoogleWorkspace') && GoogleWorkspace::configured();
        $wsSub = (string) (self::get('AV_WS_SUBJECT', '') ?: self::get('ADMIN_EMAIL', ''));
        $groups[] = ['group' => 'Identity & Workspace', 'checks' => [
            self::chk('Admin token', (defined('ADMIN_TOKEN') && strlen((string) ADMIN_TOKEN) >= 8) ? 'ok' : 'off', 'Studio access'),
            self::chk('Google sign-in (OAuth)', $oauth ? 'ok' : 'warn', $oauth ? 'enabled' : 'not configured — password sign-in still works'),
            self::chk('Workspace API (service account)', $wsApi ? 'ok' : 'warn', $wsApi ? 'configured — live Calendar/Drive reads in the portal' : 'not set — portal uses the launchpad + embeds'),
            self::chk('Directory delegation', ($wsApi && $wsSub !== '') ? 'ok' : 'info', $wsApi ? ($wsSub !== '' ? 'impersonates ' . $wsSub : 'no AV_WS_SUBJECT — directory read disabled') : '—'),
            self::chk('Org domain', 'info', self::str('AV_ORG_DOMAIN', 'afrovanguard.org.ng')),
            self::chk('Afrovanguard bot (AI)', (class_exists('AvBot') && AvBot::configured()) ? 'ok' : 'warn',
                (class_exists('AvBot') && AvBot::configured()) ? ('Claude · ' . AvBot::model()) : 'set ANTHROPIC_API_KEY to enable AI replies'),
        ]];

        // Storage
        $cloud = class_exists('Cloudinary') && Cloudinary::configured();
        $drive = self::has('AV_GDRIVE_SERVICE_ACCOUNT', 'AV_GDRIVE_FOLDER_ID');
        $groups[] = ['group' => 'Storage', 'checks' => [
            self::chk('Cloudinary (images)', $cloud ? 'ok' : 'warn', $cloud ? 'configured' : 'falls back to local /uploads'),
            self::chk('Google Drive (docs)', $drive ? 'ok' : 'warn', $drive ? 'configured' : 'falls back to local /uploads'),
        ]];

        // Integrations / webhooks
        $wh = [];
        try {
            $eps = class_exists('Webhooks') ? Webhooks::endpointsAll() : [];
            $enabled = count(array_filter($eps, fn($e) => (int) $e['enabled'] === 1));
            $wh[] = self::chk('Webhook endpoints', 'info', count($eps) . ' total · ' . $enabled . ' enabled');
            if (class_exists('Database') && Database::tableExists('webhook_deliveries')) {
                $pdo = Database::pdo();
                $pending = (int) $pdo->query("SELECT COUNT(*) FROM webhook_deliveries WHERE status IN ('pending','failed')")->fetchColumn();
                $dead = (int) $pdo->query("SELECT COUNT(*) FROM webhook_deliveries WHERE status='dead'")->fetchColumn();
                $wh[] = self::chk('Deliveries pending/failed', $pending > 0 ? 'warn' : 'ok', (string) $pending . ($dead ? " · {$dead} dead" : ''));
            }
        } catch (Throwable $e) { $wh[] = self::chk('Webhooks', 'info', $e->getMessage()); }
        $groups[] = ['group' => 'Webhooks', 'checks' => $wh];

        // Filesystem + portability
        $fs = [];
        $dbDir = defined('AV_DB_PATH') ? dirname((string) AV_DB_PATH) : (defined('AV_ROOT') ? AV_ROOT . '/db' : '');
        $uploads = defined('AV_ROOT') ? AV_ROOT . '/uploads' : '';
        $fs[] = self::chk('DB directory writable', ($dbDir && is_writable($dbDir)) ? 'ok' : 'warn', $dbDir);
        $fs[] = self::chk('Uploads directory', ($uploads && is_dir($uploads)) ? (is_writable($uploads) ? 'ok' : 'warn') : 'info', $uploads ?: '—');
        foreach (['schema.mysql.sql', 'schema.pgsql.sql'] as $sf) {
            $p = defined('AV_ROOT') ? AV_ROOT . '/db/' . $sf : '';
            $fs[] = self::chk('Migration: ' . $sf, ($p && is_file($p)) ? 'ok' : 'info', ($p && is_file($p)) ? 'present' : 'missing');
        }
        $groups[] = ['group' => 'Filesystem & portability', 'checks' => $fs];

        return $groups;
    }
}
