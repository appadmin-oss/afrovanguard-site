<?php
/**
 * lib/CacGoals.php — CACENTRE's goals, worked on here.
 *
 * Goals are set at CACENTRE; the people who do the work live here. So, from
 * the cron, this site reads CACENTRE's goals (api/afrovanguard-goals.php over
 * the signed bridge, a token for that door only), and for each:
 *
 *   · mirrors it as a goal here (team_goals.cac_goal_id), owned by the goal
 *     owner's portal account, closed when CACENTRE closes it;
 *   · lets GoalPlanner draw its steps — AI when configured, each step to
 *     whoever fits it, the owner otherwise — as ordinary portal tasks;
 *   · attaches every open step to the owner's commitment to that goal this
 *     week (lib/CacWeek.php), so ticking a step here counts there, and a new
 *     week's commitment picks the open steps up.
 *
 * The owner is the member CACENTRE has linked, or else the one member here
 * that any of the owner's addresses there names (owner_emails). A goal whose
 * owner is neither still comes across, held by the portal's steward (the
 * first administrator) with the owner's name on it: its steps go to whoever
 * fits, and the rest wait in the pool. Only a site with no administrator at
 * all leaves a goal out. Fails soft: CACENTRE down is a
 * tick that does nothing.
 */
declare(strict_types=1);

require_once __DIR__ . '/Goals.php';

final class CacGoals
{
    public const PATH = '/api/afrovanguard-goals.php';
    private const EVERY = 3600;
    /* Bumped when what a sync does changes, so a new version reads CACENTRE at
       once rather than an hour after the old one last did (and dropped what
       the new one would keep). */
    public const VERSION = 5;
    private const RETRY = 600;
    /* Work still to do: what CACENTRE's console calls open (a goal starts there
       as a draft), and missed goals — past their date, not done. Only achieved
       and cancelled goals are finished. */
    private const OPEN = ['draft', 'active', 'at_risk', 'behind', 'missed'];
    /* How a goal stands at CACENTRE, said on its line here. */
    private const STANDING = ['at_risk' => 'at risk at CACENTRE', 'behind' => 'behind at CACENTRE', 'missed' => 'missed its date at CACENTRE — still to finish'];
    private const PLAN_PER_TICK = 3;
    private const ATTACH_PER_TICK = 25;

    public static function ensure(): void
    {
        static $done = false; if ($done) return; $done = true;
        if (class_exists('GoalPlanner')) GoalPlanner::ensure(); else Goals::ensure();
        $pdo = Database::pdo();
        foreach (['cac_goal_id' => 'INTEGER NOT NULL DEFAULT 0', 'cac_commitment_id' => 'INTEGER NOT NULL DEFAULT 0', 'cac_code' => "VARCHAR(32) NOT NULL DEFAULT ''"] as $c => $def) {
            try { if (!Database::columnExists('team_goals', $c)) $pdo->exec("ALTER TABLE team_goals ADD COLUMN $c $def"); } catch (Throwable $e) {}
        }
        if (class_exists('CacWeek')) CacWeek::ensure();
    }

    public static function ready(): bool { return class_exists('CacSso') && CacSso::ready() && class_exists('CacWeek'); }

    /** From the cron: at most hourly, unless forced. */
    public static function syncIfDue(?int $now = null): ?array
    {
        if (!self::ready()) return null;
        $now = $now ?? time();
        $last = (int) (Database::metaGet('cac_goals_synced_at') ?? 0);
        $fresh = (string) (Database::metaGet('cac_goals_ver') ?? '') === (string) self::VERSION;
        if ($fresh && $last > $now - self::EVERY) return null;
        Database::metaSet('cac_goals_ver', (string) self::VERSION);
        Database::metaSet('cac_goals_synced_at', (string) $now);
        $r = self::sync($now);
        /* CACENTRE down or refusing: try again in ten minutes, not an hour. */
        if (empty($r['ok'])) Database::metaSet('cac_goals_synced_at', (string) ($now - self::EVERY + self::RETRY));
        return $r;
    }

    public static function sync(?int $now = null): array
    {
        self::ensure();
        $now = $now ?? time();
        $out = ['ok' => false, 'goals' => 0, 'mirrored' => 0, 'closed' => 0, 'stewarded' => 0, 'no_owner' => 0, 'planned' => 0, 'attached' => 0, 'error' => ''];
        $steward = self::steward();
        if (!self::ready()) { $out['error'] = 'not-configured'; return $out; }
        $raw = self::get(CacSso::base() . self::PATH . '?t=' . rawurlencode(CacSso::mint(['id' => 0], 'goals')));
        $d = $raw === null ? null : json_decode($raw, true);
        if (!is_array($d) || empty($d['ok'])) {
            $out['error'] = is_array($d) ? (string) ($d['error'] ?? 'refused') : 'unreachable';
            Database::metaSet('cac_goals_last', (string) json_encode(['at' => gmdate('Y-m-d H:i:s', $now)] + $out));
            return $out;
        }
        $out['ok'] = true;
        $pdo = Database::pdo();
        $mirrors = [];
        foreach (array_slice((array) ($d['goals'] ?? []), 0, 300) as $g) {
            if (!is_array($g) || (int) ($g['id'] ?? 0) <= 0) continue;
            $out['goals']++;
            /* The owner: by the link CACENTRE holds, else by an address CACENTRE
               has for them that names exactly one member here. */
            $owner = self::user((int) ($g['owner_av'] ?? 0)) ?? self::byEmails((array) ($g['owner_emails'] ?? []));
            /* An owner who is not on the portal does not keep the goal off it: the
               portal's steward holds it, its steps still go to whoever fits (and
               the rest wait in the pool), and the owner's name stays on it. */
            $linked = $owner !== null;
            if (!$owner && $steward) { $owner = $steward; $out['stewarded']++; }
            $st = $pdo->prepare('SELECT * FROM team_goals WHERE cac_goal_id = ? LIMIT 1');
            $st->execute([(int) $g['id']]);
            $had = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            $open = in_array((string) ($g['status'] ?? ''), self::OPEN, true);
            if (!$owner) {
                $out['no_owner']++;
                if ($had && !$open) { $pdo->prepare('UPDATE team_goals SET closed = 1 WHERE id = ?')->execute([(int) $had['id']]); $out['closed']++; }
                continue;
            }
            $title = mb_substr(trim(((string) ($g['code'] ?? '') !== '' ? $g['code'] . ' · ' : '') . (string) ($g['title'] ?? '')), 0, 300);
            /* Held for somebody (or nobody) there: say who, so the steward is not read as the owner. */
            $held = $linked ? '' : ((string) ($g['owner_name'] ?? '') !== '' ? ' · owner at CACENTRE: ' . $g['owner_name'] : ' · no owner at CACENTRE yet');
            $standing = self::STANDING[(string) ($g['status'] ?? '')] ?? '';
            $target = mb_substr(trim(self::targetText($g) . ($standing !== '' ? ' · ' . $standing : '') . $held, ' ·'), 0, 200);
            /* A commitment is the real owner's: a goal the steward holds counts toward none. */
            $cid = $linked ? max(0, (int) ($g['commitment_id'] ?? 0)) : 0;
            if ($had) {
                $pdo->prepare('UPDATE team_goals SET title = ?, target = ?, author_id = ?, closed = ?, cac_commitment_id = ?, cac_code = ?, updated_at = ? WHERE id = ?')
                    ->execute([$title, $target, (int) $owner['id'], $open ? 0 : 1, $cid, (string) ($g['code'] ?? ''), gmdate('Y-m-d H:i:s', $now), (int) $had['id']]);
                $gid = (int) $had['id'];
                if (!$open && (int) $had['closed'] === 0) $out['closed']++;
            } else {
                if (!$open) continue;
                $gid = Goals::create((int) $owner['id'], $title, $target);
                if ($gid <= 0) continue;
                $pdo->prepare('UPDATE team_goals SET cac_goal_id = ?, cac_commitment_id = ?, cac_code = ? WHERE id = ?')->execute([(int) $g['id'], $cid, (string) ($g['code'] ?? ''), $gid]);
            }
            self::mirrorMeasure($gid, $g);
            $out['mirrored']++;
            /* Steps count toward a commitment only when it is the real owner's. */
            if ($open) $mirrors[$gid] = ['owner' => $owner, 'cid' => $cid];
        }

        /* Their steps: drawn now for a goal that needs them (GoalPlanner's rules). */
        if (class_exists('GoalPlanner') && GoalPlanner::enabled()) {
            $due = array_filter(GoalPlanner::due($now), static fn($g) => isset($mirrors[(int) $g['id']]));
            foreach (array_slice($due, 0, self::PLAN_PER_TICK) as $g) {
                try { $r = GoalPlanner::plan((int) $g['id'], $now); if (!empty($r['ok'])) $out['planned']++; } catch (Throwable $e) { error_log('[cac-goals] plan: ' . $e->getMessage()); }
            }
        }

        /* …and every open step counts toward this week's commitment to the goal. */
        $left = self::ATTACH_PER_TICK;
        foreach ($mirrors as $gid => $m) {
            if ($m['cid'] <= 0 || $left <= 0) continue;
            $st = $pdo->prepare('SELECT id FROM collab_tasks WHERE goal_id = ? AND done = 0 AND cac_commitment_id <> ? AND (creator_id = ? OR assignee_id = ?) ORDER BY id LIMIT ' . $left);
            $st->execute([$gid, $m['cid'], (int) $m['owner']['id'], (int) $m['owner']['id']]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                $r = CacWeek::attach($m['owner'], (int) $tid, $m['cid']);
                $left--;
                if (!empty($r['ok'])) $out['attached']++;
                elseif (($r['error'] ?? '') === 'unreachable') break 2;
            }
        }
        Database::metaSet('cac_goals_last', (string) json_encode(['at' => gmdate('Y-m-d H:i:s', $now)] + $out));
        return $out;
    }

    /**
     * The goal's measure as CACENTRE holds it — unit, baseline, target,
     * direction, where the number stands, start and due — so GoalTrack judges
     * the mirror by the same number, pace and thresholds CACENTRE does. A
     * mirror is measured there; nobody checks in on it here.
     */
    private static function mirrorMeasure(int $gid, array $g): void
    {
        if (class_exists('GoalTrack')) GoalTrack::ensure();
        $n = static fn($v) => $v === null || $v === '' || !is_numeric($v) ? null : (float) $v;
        $dir = in_array($g['direction'] ?? '', ['increase', 'decrease', 'maintain'], true) ? $g['direction'] : 'increase';
        $d = static fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
        Database::pdo()->prepare('UPDATE team_goals SET unit = ?, baseline = ?, target_value = ?, current_value = ?, direction = ?, start_on = ?, due_on = ? WHERE id = ?')
            ->execute([mb_substr((string) ($g['unit'] ?? ''), 0, 24), $n($g['baseline'] ?? null), $n($g['target'] ?? null), $n($g['progress'] ?? null), $dir,
                       $d($g['started_on'] ?? ''), $d($g['due_on'] ?? ''), $gid]);
        if (class_exists('GoalTrack')) GoalTrack::refresh($gid);
    }

    /** "12 count by 31 Oct · MAM Academy" — what the goal is measured by, in a line. */
    private static function targetText(array $g): string
    {
        $n = static fn($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
        $parts = [];
        if (isset($g['target']) && $g['target'] !== null) $parts[] = $n($g['target']) . ' ' . (string) ($g['unit'] ?? '');
        if ((string) ($g['due_on'] ?? '') !== '') $parts[] = 'by ' . gmdate('j M Y', strtotime($g['due_on'] . ' 12:00 UTC') ?: time());
        $line = trim(implode(' ', $parts));
        return trim($line . ((string) ($g['entity'] ?? '') !== '' ? ' · ' . $g['entity'] : ''), ' ·');
    }

    /**
     * Where the goals stand, for the Tasks page: whether the link is set up,
     * and the last read — when, whether it worked, how many open goals
     * CACENTRE had and how many came across.
     */
    public static function status(): array
    {
        $l = json_decode((string) (Database::metaGet('cac_goals_last') ?? ''), true);
        return ['ready' => self::ready(), 'last' => is_array($l) ? [
            'at' => (string) ($l['at'] ?? ''), 'ok' => !empty($l['ok']), 'goals' => (int) ($l['goals'] ?? 0),
            'mirrored' => (int) ($l['mirrored'] ?? 0), 'stewarded' => (int) ($l['stewarded'] ?? 0), 'error' => (string) ($l['error'] ?? ''),
        ] : null];
    }

    /** Who holds a goal whose owner is not on the portal: the first active administrator. */
    private static function steward(): ?array
    {
        try {
            $rows = Database::pdo()->query("SELECT id, name, email, role FROM lms_users WHERE status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return null; }
        foreach ($rows as $r) if (LmsAuth::rank((string) $r['role']) >= LmsAuth::ROLE_RANK['admin']) return ['id' => $r['id'], 'name' => $r['name'], 'email' => $r['email']];
        return null;
    }

    private static function user(int $id): ?array
    {
        if ($id <= 0) return null;
        $st = Database::pdo()->prepare("SELECT id, name, email FROM lms_users WHERE id = ? AND status = 'active'");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The one active member any of these addresses names; null for none, or for two (never a guess). */
    private static function byEmails(array $emails): ?array
    {
        $emails = array_values(array_unique(array_filter(array_map(static fn($e) => strtolower(trim((string) $e)), array_slice($emails, 0, 10)),
            static fn($e) => (bool) filter_var($e, FILTER_VALIDATE_EMAIL))));
        if (!$emails) return null;
        $st = Database::pdo()->prepare("SELECT id, name, email FROM lms_users WHERE LOWER(email) IN (" . implode(',', array_fill(0, count($emails), '?')) . ") AND status = 'active' LIMIT 2");
        $st->execute($emails);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 ? $rows[0] : null;
    }

    private static function get(string $url): ?string
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Afrovanguard/1.0 (+goals)'];
        if (str_starts_with($url, 'https://')) { $o[CURLOPT_SSL_VERIFYPEER] = true; $o[CURLOPT_SSL_VERIFYHOST] = 2; }
        curl_setopt_array($ch, $o);
        $body = curl_exec($ch);
        if (class_exists('CacBridge')) CacBridge::see($ch, $body, 'goals');
        curl_close($ch);
        return $body === false || $body === '' ? null : (string) $body;
    }
}
