<?php
/**
 * lib/AvAutomation.php — Workspace automation: turn events into actions.
 *
 * The site emits domain events (lib/Events.php). This layer subscribes to the
 * Workspace ones and DOES things — the "automation" half of enterprise
 * integration:
 *
 *   workspace.connected        → provision the member: a welcome/onboarding
 *                                event on THEIR calendar + start real-time
 *                                watch channels (one-shot, guarded)
 *   workspace.disconnected     → tear down their watch channels
 *   workspace.calendar_changed → (real-time) re-arm / hook point for sync
 *   workspace.drive_changed    → (real-time) hook point
 *   mentorship.session_scheduled → notify (Meet event already created upstream)
 *
 * Every action is best-effort and wrapped: automation can never break the flow
 * that triggered it. register() is idempotent and called once from bootstrap.
 */
declare(strict_types=1);

final class AvAutomation
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) return;
        self::$registered = true;
        if (!class_exists('Events')) return;

        Events::on('workspace.connected', [self::class, 'onConnected']);
        Events::on('workspace.disconnected', [self::class, 'onDisconnected']);
        Events::on('workspace.calendar_changed', [self::class, 'onCalendarChanged']);
        Events::on('workspace.drive_changed', [self::class, 'onDriveChanged']);
        Events::on('mentorship.session_scheduled', [self::class, 'onSessionScheduled']);
    }

    /* ── one-shot run guard ─────────────────────────────────────────────── */
    private static function ensureRuns(): void
    {
        static $done = false; if ($done) return; $done = true;
        $pdo = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS automation_runs (
            user_id INTEGER NOT NULL DEFAULT 0,
            akey VARCHAR(64) NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (user_id, akey)
        )";
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pdo->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
    }

    /** True the FIRST time (uid, key) is seen; false thereafter. */
    private static function once(int $uid, string $key): bool
    {
        self::ensureRuns();
        $sql = Database::insertIgnore('automation_runs', ['user_id', 'akey', 'created_at']);
        $st = Database::pdo()->prepare($sql);
        $st->execute([$uid, $key, gmdate('Y-m-d H:i:s')]);
        return $st->rowCount() > 0;
    }

    /* ── handlers ───────────────────────────────────────────────────────── */

    /** A member just connected their Google account. Provision them. */
    public static function onConnected(array $p): void
    {
        $uid = (int) ($p['user_id'] ?? 0);
        if ($uid <= 0) return;

        // Real-time: start watch channels so their changes stream in (best-effort,
        // inert until a public webhook + verified push domain are configured).
        if (class_exists('GoogleWatch') && self::enabled('AV_WS_WATCH', false)) {
            try { GoogleWatch::startAll($uid); } catch (Throwable $e) { error_log('[automation] watch start: ' . $e->getMessage()); }
        }

        // Onboarding: drop a welcome event on their own calendar, exactly once.
        if (self::enabled('AV_WS_ONBOARDING', true) && self::once($uid, 'onboarding_event')) {
            try {
                $when = gmdate('c', strtotime('tomorrow 10:00') ?: time() + 86400);
                GoogleWorkspaceUser::createEvent(
                    $uid,
                    'Welcome to Afrovanguard Workspace',
                    $when,
                    30,
                    "You're connected. This hub now shows your Gmail, Calendar and Drive in one place — and schedules mentorship straight to your calendar with a Meet link.\n\nStart here: " . rtrim(SITE_URL, '/') . '/workspace',
                    false
                );
            } catch (Throwable $e) { error_log('[automation] onboarding event: ' . $e->getMessage()); }
        }
    }

    /** A member disconnected — retire their real-time channels. */
    public static function onDisconnected(array $p): void
    {
        $uid = (int) ($p['user_id'] ?? 0);
        if ($uid > 0 && class_exists('GoogleWatch')) {
            try { GoogleWatch::stopForUser($uid); } catch (Throwable $e) { error_log('[automation] watch stop: ' . $e->getMessage()); }
        }
        // Let them re-onboard if they reconnect later.
        if ($uid > 0) {
            try { self::ensureRuns(); Database::pdo()->prepare('DELETE FROM automation_runs WHERE user_id = ?')->execute([$uid]); }
            catch (Throwable $e) { /* non-fatal */ }
        }
    }

    /** Real-time calendar change. Hook point for downstream sync/notify. */
    public static function onCalendarChanged(array $p): void
    {
        error_log('[automation] calendar changed for user ' . (int) ($p['user_id'] ?? 0));
        // The hub reads live on load, so there is no server cache to bust yet.
        // This seam is where a future digest/notify or targeted re-sync hangs.
    }

    public static function onDriveChanged(array $p): void
    {
        error_log('[automation] drive changed for user ' . (int) ($p['user_id'] ?? 0));
    }

    /** A mentorship session was scheduled (Meet event already created upstream). */
    public static function onSessionScheduled(array $p): void
    {
        // Notification is best-effort and optional; the calendar invite (with
        // sendUpdates=all) is the primary signal to both parties.
        if (!self::enabled('AV_WS_SESSION_NOTIFY', false)) return;
        // Reserved: hook a Notify::* call here when a template is added.
    }

    /* ── config ── */
    private static function enabled(string $env, bool $default): bool
    {
        $v = getenv($env);
        if ($v === false || trim((string) $v) === '') return $default;
        return !in_array(strtolower(trim((string) $v)), ['0', 'off', 'false', 'no'], true);
    }
}
