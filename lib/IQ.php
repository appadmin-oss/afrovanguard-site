<?php
/**
 * lib/IQ.php — Incorruptible Quiz (IQ): quizzes, games, scoring & leaderboards.
 *
 * A public quizzes-and-games engine served at /IQ. Anyone can play; signing in
 * saves scores to the leaderboard and earns "Incorruptible" badges. Quizzes are
 * authored by admins, graded server-side (correct answers never reach the
 * browser), and can be embedded inside Diary blog posts via a [iq slug]
 * shortcode.
 *
 * Portable storage (SQLite default; MySQL/Postgres via Database::translateDDL).
 */
declare(strict_types=1);

final class IQ
{
    const DIFFICULTY = ['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard'];
    const PASS_PCT   = 60;
    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        $db  = Database::pdo();
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ddl = "
        CREATE TABLE IF NOT EXISTS iq_quizzes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug VARCHAR(80) NOT NULL DEFAULT '',
            title VARCHAR(200) NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            category VARCHAR(60) NOT NULL DEFAULT 'General',
            difficulty VARCHAR(10) NOT NULL DEFAULT 'easy',
            time_limit_sec INTEGER NOT NULL DEFAULT 0,
            blog_slug VARCHAR(120) NOT NULL DEFAULT '',
            published INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_iq_slug ON iq_quizzes(slug);
        CREATE TABLE IF NOT EXISTS iq_questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quiz_id INTEGER NOT NULL,
            prompt TEXT NOT NULL DEFAULT '',
            image_url VARCHAR(400) NOT NULL DEFAULT '',
            options TEXT NOT NULL DEFAULT '[]',
            points INTEGER NOT NULL DEFAULT 10,
            sort INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_iq_q ON iq_questions(quiz_id, sort);
        CREATE TABLE IF NOT EXISTS iq_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quiz_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL DEFAULT 0,
            name VARCHAR(120) NOT NULL DEFAULT '',
            score INTEGER NOT NULL DEFAULT 0,
            max_score INTEGER NOT NULL DEFAULT 0,
            correct INTEGER NOT NULL DEFAULT 0,
            total INTEGER NOT NULL DEFAULT 0,
            duration_sec INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_iq_att_quiz ON iq_attempts(quiz_id, score);
        CREATE INDEX IF NOT EXISTS idx_iq_att_user ON iq_attempts(user_id);";
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        self::$ready = true;
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
    public static function difficultyLabel(string $d): string { return self::DIFFICULTY[$d] ?? 'Easy'; }

    private static function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-') ?: 'quiz';
    }

    private static function uniqueSlug(string $base, int $ignoreId = 0): string
    {
        $slug = self::slugify($base); $try = $slug; $n = 2;
        $db = Database::pdo();
        while (true) {
            $st = $db->prepare('SELECT id FROM iq_quizzes WHERE slug = ? AND id <> ?');
            $st->execute([$try, $ignoreId]);
            if (!$st->fetchColumn()) return $try;
            $try = $slug . '-' . $n++;
        }
    }

    /* ── Public read ──────────────────────────────────────────────────── */

    /** Published quizzes with question counts and (optionally) a viewer's best %. */
    public static function listQuizzes(int $uid = 0, string $category = ''): array
    {
        self::ensure();
        $db = Database::pdo();
        $sql = 'SELECT q.*, (SELECT COUNT(*) FROM iq_questions x WHERE x.quiz_id = q.id) AS q_count,
                (SELECT COUNT(*) FROM iq_attempts a WHERE a.quiz_id = q.id) AS plays
                FROM iq_quizzes q WHERE q.published = 1';
        $args = [];
        if ($category !== '') { $sql .= ' AND q.category = ?'; $args[] = $category; }
        $sql .= ' ORDER BY q.created_at DESC';
        $st = $db->prepare($sql); $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $best = null;
            if ($uid > 0) {
                $b = $db->prepare('SELECT MAX(score) s, MAX(max_score) m FROM iq_attempts WHERE quiz_id = ? AND user_id = ?');
                $b->execute([(int) $r['id'], $uid]);
                $bd = $b->fetch(PDO::FETCH_ASSOC);
                if ($bd && $bd['m'] > 0) $best = (int) round(100 * (int) $bd['s'] / (int) $bd['m']);
            }
            $out[] = self::shapeQuiz($r) + ['q_count' => (int) $r['q_count'], 'plays' => (int) $r['plays'], 'best_pct' => $best];
        }
        return $out;
    }

    public static function categories(): array
    {
        self::ensure();
        $rows = Database::pdo()->query('SELECT DISTINCT category FROM iq_quizzes WHERE published = 1 ORDER BY category')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_filter(array_map('strval', $rows)));
    }

    /** A quiz ready to PLAY — options shuffled, correct flags stripped. */
    public static function getForPlay(string $slug): ?array
    {
        self::ensure();
        $db = Database::pdo();
        $st = $db->prepare('SELECT * FROM iq_quizzes WHERE slug = ? AND published = 1');
        $st->execute([$slug]);
        $q = $st->fetch(PDO::FETCH_ASSOC);
        if (!$q) return null;
        $qs = $db->prepare('SELECT id, prompt, image_url, options, points FROM iq_questions WHERE quiz_id = ? ORDER BY sort, id');
        $qs->execute([(int) $q['id']]);
        $questions = [];
        foreach ($qs->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $opts = json_decode((string) $row['options'], true) ?: [];
            // Present options with a stable index but NO correctness info.
            $safe = [];
            foreach ($opts as $i => $o) $safe[] = ['i' => $i, 'text' => (string) ($o['t'] ?? '')];
            shuffle($safe);
            $questions[] = [
                'id' => (int) $row['id'], 'prompt' => (string) $row['prompt'],
                'image_url' => (string) $row['image_url'], 'points' => (int) $row['points'],
                'options' => $safe,
            ];
        }
        return self::shapeQuiz($q) + ['questions' => $questions];
    }

    /**
     * Grade an attempt server-side. $answers maps question id → chosen option
     * index (the original index, as sent in getForPlay). Records the attempt and
     * returns the result + per-question correctness + earned badges + rank.
     */
    public static function grade(string $slug, array $answers, int $uid, string $name, int $durationSec): array
    {
        self::ensure();
        $db = Database::pdo();
        $st = $db->prepare('SELECT * FROM iq_quizzes WHERE slug = ? AND published = 1');
        $st->execute([$slug]);
        $q = $st->fetch(PDO::FETCH_ASSOC);
        if (!$q) return ['ok' => false, 'error' => 'Quiz not found.'];
        $qid = (int) $q['id'];
        $qs = $db->prepare('SELECT id, options, points FROM iq_questions WHERE quiz_id = ? ORDER BY sort, id');
        $qs->execute([$qid]);
        $rows = $qs->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return ['ok' => false, 'error' => 'This quiz has no questions yet.'];

        $score = 0; $max = 0; $correct = 0; $review = [];
        foreach ($rows as $row) {
            $qId = (int) $row['id']; $pts = (int) $row['points'];
            $opts = json_decode((string) $row['options'], true) ?: [];
            $max += $pts;
            $correctIdx = -1;
            foreach ($opts as $i => $o) if (!empty($o['c'])) { $correctIdx = $i; break; }
            $chosen = array_key_exists((string) $qId, $answers) ? (int) $answers[(string) $qId]
                    : (array_key_exists($qId, $answers) ? (int) $answers[$qId] : -1);
            $isRight = ($chosen === $correctIdx && $correctIdx >= 0);
            if ($isRight) { $score += $pts; $correct++; }
            $review[] = ['id' => $qId, 'correct_index' => $correctIdx, 'chosen' => $chosen, 'right' => $isRight];
        }
        $total = count($rows);
        $pct = $max > 0 ? (int) round(100 * $score / $max) : 0;
        $name = mb_substr(trim($name), 0, 120);
        if ($name === '' && $uid > 0) $name = self::userName($uid);
        if ($name === '') $name = 'Anonymous';

        $db->prepare('INSERT INTO iq_attempts (quiz_id, user_id, name, score, max_score, correct, total, duration_sec, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$qid, max(0, $uid), $name, $score, $max, $correct, $total, max(0, $durationSec), self::now()]);

        if (class_exists('Events')) { try { Events::emit('iq.attempt', ['quiz' => $slug, 'uid' => $uid, 'pct' => $pct]); } catch (Throwable $e) {} }

        return [
            'ok' => true, 'score' => $score, 'max' => $max, 'correct' => $correct, 'total' => $total,
            'pct' => $pct, 'passed' => $pct >= self::PASS_PCT, 'review' => $review,
            'rank' => self::rankInQuiz($qid, $score), 'badges' => $uid > 0 ? self::badges($uid) : [],
        ];
    }

    /** Best-attempt-per-user ranking for one quiz. */
    public static function quizLeaderboard(string $slug, int $limit = 20): array
    {
        self::ensure();
        $db = Database::pdo();
        $qid = (int) ($db->query('SELECT id FROM iq_quizzes WHERE slug = ' . $db->quote($slug))->fetchColumn() ?: 0);
        if ($qid <= 0) return [];
        // Best score per identity (user_id when signed in, else name).
        $sql = "SELECT name, MAX(score) AS score, MAX(max_score) AS max_score, MIN(duration_sec) AS best_time
                FROM iq_attempts WHERE quiz_id = ?
                GROUP BY CASE WHEN user_id > 0 THEN 'u' || user_id ELSE 'n' || name END
                ORDER BY score DESC, best_time ASC LIMIT ?";
        $st = $db->prepare($sql); $st->execute([$qid, max(1, min(100, $limit))]);
        $out = []; $rank = 1;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pct = (int) $r['max_score'] > 0 ? (int) round(100 * (int) $r['score'] / (int) $r['max_score']) : 0;
            $out[] = ['rank' => $rank++, 'name' => (string) $r['name'], 'score' => (int) $r['score'], 'pct' => $pct, 'time' => (int) $r['best_time']];
        }
        return $out;
    }

    /** Global leaderboard — total points (best score per quiz) per signed-in user. */
    public static function globalLeaderboard(int $limit = 20): array
    {
        self::ensure();
        $sql = "SELECT name, SUM(best) AS points, COUNT(*) AS quizzes FROM (
                    SELECT user_id, MAX(name) AS name, MAX(score) AS best
                    FROM iq_attempts WHERE user_id > 0
                    GROUP BY user_id, quiz_id
                ) t GROUP BY user_id ORDER BY points DESC LIMIT ?";
        $st = Database::pdo()->prepare($sql); $st->execute([max(1, min(100, $limit))]);
        $out = []; $rank = 1;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['rank' => $rank++, 'name' => (string) $r['name'], 'points' => (int) $r['points'], 'quizzes' => (int) $r['quizzes']];
        }
        return $out;
    }

    /** Computed "Incorruptible" badges for a signed-in user. */
    public static function badges(int $uid): array
    {
        self::ensure();
        if ($uid <= 0) return [];
        $db = Database::pdo();
        $st = $db->prepare('SELECT score, max_score, correct, total, duration_sec, quiz_id FROM iq_attempts WHERE user_id = ?');
        $st->execute([$uid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return [];
        $quizzes = []; $perfect = false; $speed = false; $sumPct = 0; $n = 0;
        foreach ($rows as $r) {
            $quizzes[(int) $r['quiz_id']] = true;
            $pct = (int) $r['max_score'] > 0 ? 100 * (int) $r['score'] / (int) $r['max_score'] : 0;
            $sumPct += $pct; $n++;
            if ($pct >= 100) $perfect = true;
            if ($pct >= 100 && (int) $r['duration_sec'] > 0 && (int) $r['duration_sec'] <= max(1, (int) $r['total']) * 8) $speed = true;
        }
        $distinct = count($quizzes); $avg = $n ? $sumPct / $n : 0;
        $badges = [];
        $badges[] = ['key' => 'starter', 'label' => 'First Steps', 'icon' => '🌱', 'earned' => true];
        $badges[] = ['key' => 'perfect', 'label' => 'Flawless', 'icon' => '💯', 'earned' => $perfect];
        $badges[] = ['key' => 'speed', 'label' => 'Speedster', 'icon' => '⚡', 'earned' => $speed];
        $badges[] = ['key' => 'explorer', 'label' => 'Explorer (5 quizzes)', 'icon' => '🧭', 'earned' => $distinct >= 5];
        $badges[] = ['key' => 'incorruptible', 'label' => 'Incorruptible (90%+ avg)', 'icon' => '🛡️', 'earned' => $n >= 3 && $avg >= 90];
        return $badges;
    }

    private static function rankInQuiz(int $qid, int $score): int
    {
        $st = Database::pdo()->prepare('SELECT COUNT(DISTINCT CASE WHEN user_id>0 THEN user_id ELSE id END) FROM iq_attempts WHERE quiz_id = ? AND score > ?');
        $st->execute([$qid, $score]);
        return (int) $st->fetchColumn() + 1;
    }

    /* ── Admin authoring ──────────────────────────────────────────────── */

    public static function adminList(): array
    {
        self::ensure();
        $rows = Database::pdo()->query('SELECT q.*, (SELECT COUNT(*) FROM iq_questions x WHERE x.quiz_id=q.id) qc FROM iq_quizzes q ORDER BY q.updated_at DESC, q.id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn($r) => self::shapeQuiz($r) + ['q_count' => (int) $r['qc']], $rows);
    }

    public static function getForEdit(int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM iq_quizzes WHERE id = ?');
        $st->execute([$id]);
        $q = $st->fetch(PDO::FETCH_ASSOC);
        if (!$q) return null;
        $qs = Database::pdo()->prepare('SELECT id, prompt, image_url, options, points, sort FROM iq_questions WHERE quiz_id = ? ORDER BY sort, id');
        $qs->execute([$id]);
        $questions = [];
        foreach ($qs->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $questions[] = ['id' => (int) $r['id'], 'prompt' => (string) $r['prompt'], 'image_url' => (string) $r['image_url'],
                'points' => (int) $r['points'], 'options' => json_decode((string) $r['options'], true) ?: []];
        }
        return self::shapeQuiz($q) + ['questions' => $questions];
    }

    /** Create or update a quiz plus its questions (admin only — caller gates). */
    public static function saveQuiz(int $adminUid, array $in): array
    {
        self::ensure();
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 200);
        if ($title === '') return ['ok' => false, 'error' => 'Give the quiz a title.'];
        $id = (int) ($in['id'] ?? 0);
        $difficulty = in_array($in['difficulty'] ?? 'easy', array_keys(self::DIFFICULTY), true) ? (string) $in['difficulty'] : 'easy';
        $fields = [
            'title' => $title,
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 2000),
            'category' => mb_substr(trim((string) ($in['category'] ?? 'General')) ?: 'General', 0, 60),
            'difficulty' => $difficulty,
            'time_limit_sec' => max(0, min(7200, (int) ($in['time_limit_sec'] ?? 0))),
            'blog_slug' => preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($in['blog_slug'] ?? ''))),
            'published' => !empty($in['published']) ? 1 : 0,
            'updated_at' => self::now(),
        ];
        $db = Database::pdo();
        if ($id > 0) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $db->prepare("UPDATE iq_quizzes SET $set WHERE id = ?")->execute([...array_values($fields), $id]);
        } else {
            $fields['slug'] = self::uniqueSlug($title);
            $fields['created_by'] = $adminUid;
            $fields['created_at'] = self::now();
            $cols = implode(',', array_keys($fields));
            $ph = implode(',', array_fill(0, count($fields), '?'));
            $db->prepare("INSERT INTO iq_quizzes ($cols) VALUES ($ph)")->execute(array_values($fields));
            $id = (int) $db->lastInsertId();
        }
        // Replace questions if provided.
        if (isset($in['questions']) && is_array($in['questions'])) {
            $db->prepare('DELETE FROM iq_questions WHERE quiz_id = ?')->execute([$id]);
            $ins = $db->prepare('INSERT INTO iq_questions (quiz_id, prompt, image_url, options, points, sort) VALUES (?,?,?,?,?,?)');
            $sort = 0;
            foreach ($in['questions'] as $q) {
                $prompt = mb_substr(trim((string) ($q['prompt'] ?? '')), 0, 1000);
                $opts = [];
                foreach (($q['options'] ?? []) as $o) {
                    $t = mb_substr(trim((string) ($o['t'] ?? ($o['text'] ?? ''))), 0, 400);
                    if ($t === '') continue;
                    $opts[] = ['t' => $t, 'c' => !empty($o['c']) || !empty($o['correct']) ? 1 : 0];
                }
                if ($prompt === '' || count($opts) < 2) continue;
                if (!array_filter($opts, fn($o) => $o['c'])) $opts[0]['c'] = 1; // ensure a correct answer
                $ins->execute([$id, $prompt, mb_substr((string) ($q['image_url'] ?? ''), 0, 400),
                    json_encode($opts, JSON_UNESCAPED_UNICODE), max(1, (int) ($q['points'] ?? 10)), $sort++]);
            }
        }
        return ['ok' => true, 'id' => $id, 'slug' => (string) ($db->query('SELECT slug FROM iq_quizzes WHERE id=' . $id)->fetchColumn())];
    }

    public static function deleteQuiz(int $id): bool
    {
        self::ensure();
        $db = Database::pdo();
        $db->prepare('DELETE FROM iq_questions WHERE quiz_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM iq_attempts WHERE quiz_id = ?')->execute([$id]);
        return $db->prepare('DELETE FROM iq_quizzes WHERE id = ?')->execute([$id]);
    }

    /* ── Blog integration ─────────────────────────────────────────────── */

    /** Replace [iq slug] / [iq quiz="slug"] shortcodes in HTML with an embed iframe. */
    public static function embedShortcodes(string $html): string
    {
        return preg_replace_callback('/\[iq(?:\s+quiz=)?[:\s]?["\']?([a-z0-9-]{2,80})["\']?\s*\]/i', function ($m) {
            $slug = strtolower($m[1]);
            $src = '/IQ/embed.php?quiz=' . rawurlencode($slug);
            return '<div class="iq-embed-wrap"><iframe class="iq-embed-frame" src="' . $src . '" loading="lazy" title="Afrovanguard quiz" style="width:100%;min-height:520px;border:1px solid #e5e7eb;border-radius:14px"></iframe></div>';
        }, $html) ?? $html;
    }

    /* ── helpers ──────────────────────────────────────────────────────── */

    private static function shapeQuiz(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'title' => (string) $r['title'],
            'description' => (string) $r['description'], 'category' => (string) $r['category'],
            'difficulty' => (string) $r['difficulty'], 'difficulty_label' => self::difficultyLabel((string) $r['difficulty']),
            'time_limit_sec' => (int) $r['time_limit_sec'], 'blog_slug' => (string) ($r['blog_slug'] ?? ''),
            'published' => (int) $r['published'] === 1,
        ];
    }

    private static function userName(int $uid): string
    {
        try {
            $st = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?');
            $st->execute([$uid]);
            return (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) { return ''; }
    }
}
