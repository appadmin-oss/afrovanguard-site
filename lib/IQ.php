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
        CREATE TABLE IF NOT EXISTS iq_questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quiz_id INTEGER NOT NULL,
            prompt TEXT NOT NULL DEFAULT '',
            image_url VARCHAR(400) NOT NULL DEFAULT '',
            options TEXT NOT NULL DEFAULT '[]',
            points INTEGER NOT NULL DEFAULT 10,
            sort INTEGER NOT NULL DEFAULT 0
        );
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
        );";
        Database::execSchema($db, $ddl);
        // Indexes created separately + idempotently (MySQL lacks CREATE [UNIQUE] INDEX IF NOT EXISTS).
        Database::ensureIndex($db, 'idx_iq_slug', 'iq_quizzes', 'slug', true);
        Database::ensureIndex($db, 'idx_iq_q', 'iq_questions', 'quiz_id, sort');
        Database::ensureIndex($db, 'idx_iq_att_quiz', 'iq_attempts', 'quiz_id, score');
        Database::ensureIndex($db, 'idx_iq_att_user', 'iq_attempts', 'user_id');
        // Profile ("personality") quizzes: tally option-tagged profiles → an outcome.
        self::addCol('iq_quizzes', 'type', "VARCHAR(12) NOT NULL DEFAULT 'scored'");   // scored | profile
        self::addCol('iq_quizzes', 'profiles', "TEXT NOT NULL DEFAULT '{}'");           // {KEY: {tag,title,…}}
        self::$ready = true;
        // Seed the flagship profile quiz (self-guards by slug, so it's a no-op
        // once present — a single cheap SELECT per process).
        try { self::seedGrassroots(); } catch (Throwable $e) { /* best-effort */ }
    }

    private static function addCol(string $table, string $col, string $decl): void
    {
        try { if (!Database::columnExists($table, $col)) Database::pdo()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $col . ' ' . $decl); }
        catch (Throwable $e) { /* exists / race */ }
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
        $name = mb_substr(trim($name), 0, 120);
        if ($name === '' && $uid > 0) $name = self::userName($uid);
        if ($name === '') $name = 'Anonymous';

        // Profile ("personality") quiz: tally each chosen option's profile key.
        if ((string) ($q['type'] ?? 'scored') === 'profile') {
            $tally = [];
            foreach ($rows as $row) {
                $opts = json_decode((string) $row['options'], true) ?: [];
                $chosen = self::answerFor($answers, (int) $row['id']);
                $key = isset($opts[$chosen]['p']) ? (string) $opts[$chosen]['p'] : '';
                if ($key !== '') $tally[$key] = ($tally[$key] ?? 0) + 1;
            }
            $profiles = json_decode((string) ($q['profiles'] ?? '{}'), true) ?: [];
            $winner = self::pickProfile($tally, array_keys($profiles));
            $db->prepare('INSERT INTO iq_attempts (quiz_id, user_id, name, score, max_score, correct, total, duration_sec, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([$qid, max(0, $uid), $name, 0, 0, 0, count($rows), max(0, $durationSec), self::now()]);
            $prof = $profiles[$winner] ?? [];
            return ['ok' => true, 'type' => 'profile', 'tally' => $tally, 'winner' => $winner,
                'profile' => ['key' => $winner] + (is_array($prof) ? $prof : [])];
        }

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
        return self::shapeQuiz($q) + ['questions' => $questions, 'profiles' => json_decode((string) ($q['profiles'] ?? '{}'), true) ?: []];
    }

    /** Create or update a quiz plus its questions (admin only — caller gates). */
    public static function saveQuiz(int $adminUid, array $in): array
    {
        self::ensure();
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 200);
        if ($title === '') return ['ok' => false, 'error' => 'Give the quiz a title.'];
        $id = (int) ($in['id'] ?? 0);
        $difficulty = in_array($in['difficulty'] ?? 'easy', array_keys(self::DIFFICULTY), true) ? (string) $in['difficulty'] : 'easy';
        $type = (($in['type'] ?? 'scored') === 'profile') ? 'profile' : 'scored';
        $profiles = is_array($in['profiles'] ?? null) ? $in['profiles'] : [];
        $fields = [
            'title' => $title,
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 2000),
            'category' => mb_substr(trim((string) ($in['category'] ?? 'General')) ?: 'General', 0, 60),
            'difficulty' => $difficulty,
            'type' => $type,
            'profiles' => json_encode($profiles, JSON_UNESCAPED_UNICODE),
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
            $fields['slug'] = self::uniqueSlug((string) ($in['slug'] ?? '') !== '' ? (string) $in['slug'] : $title);
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
                    $opt = ['t' => $t, 'c' => !empty($o['c']) || !empty($o['correct']) ? 1 : 0];
                    $pk = trim((string) ($o['p'] ?? ''));       // profile key (profile quizzes)
                    if ($pk !== '') $opt['p'] = mb_substr($pk, 0, 12);
                    $opts[] = $opt;
                }
                if ($prompt === '' || count($opts) < 2) continue;
                // Scored quizzes need a correct option; profile quizzes need none.
                if ($type === 'scored' && !array_filter($opts, fn($o) => $o['c'])) $opts[0]['c'] = 1;
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
            'type' => (string) ($r['type'] ?? 'scored'),
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

    private static function answerFor(array $answers, int $qId): int
    {
        if (array_key_exists((string) $qId, $answers)) return (int) $answers[(string) $qId];
        if (array_key_exists($qId, $answers)) return (int) $answers[$qId];
        return -1;
    }

    /** Winner profile from a tally. Ties break toward later keys (higher integrity). */
    private static function pickProfile(array $tally, array $order): string
    {
        if (!$order) return (string) (array_keys($tally)[0] ?? '');
        $best = $order[0]; $bestN = -1;
        foreach ($order as $k) {
            $n = (int) ($tally[$k] ?? 0);
            if ($n >= $bestN) { $bestN = $n; $best = $k; } // >= so later keys win ties
        }
        return (string) $best;
    }

    /**
     * Seed "The Grassroots Incorruptible Test" — a profile quiz of six real-world
     * dilemmas that maps A/B/C choices to an integrity profile. Idempotent.
     */
    public static function seedGrassroots(): void
    {
        $slug = 'grassroots-incorruptible-test';
        if ((int) (Database::pdo()->query("SELECT COUNT(*) FROM iq_quizzes WHERE slug = " . Database::pdo()->quote($slug))->fetchColumn())) return;

        $profiles = [
            'A' => ['tag' => 'High vulnerability', 'tag_class' => 'a', 'title' => 'The Rationalized Integrity',
                'reality' => "You believe you're a good person, but you've accepted that the end justifies the means. You rationalize shortcuts, lying, and murmuring as “survival tactics” against a broken system.",
                'warning' => "If you're given a parliament, a platform, or a palace today, you will become the very corrupt leader you currently complain about. Power won't change you — it will scale up the rationalizations you're already using.",
                'prescription' => "Break the habit of self-justification. Start seeing small compromises as poison, not survival."],
            'B' => ['tag' => 'Moderate vulnerability', 'tag_class' => 'b', 'title' => 'The Passive Bystander',
                'reality' => "You don't initiate corruption, but you yield to it. You use silence, evasiveness, and self-preservation to navigate uncomfortable situations — surviving toxic environments, but leaving them just as toxic as you found them.",
                'warning' => "In a corrupt system, neutrality is compliance. If placed on a platform, you won't lead the corruption, but you'll sign off on it out of fear, exhaustion, or peer pressure.",
                'prescription' => "Move from passive survival to active courage. Integrity isn't the absence of wrongdoing — it's the active presence of responsibility."],
            'C' => ['tag' => 'A force for good', 'tag_class' => 'c', 'title' => 'The Incorruptible Vanguard',
                'reality' => "You've done the hard work of killing murmuring with responsibility, conquering deception with radical accountability, and slaying mediocrity with diligence. You don't absorb the toxicity of your environment — you transform it.",
                'warning' => "You are immune to systemic corruption because your standards aren't dictated by who's watching, who's paying, or how broken the system is. You're ready to hold platforms because you've already mastered the grassroots.",
                'prescription' => "Build others. Your mission now is to mentor the “B's” around you and show them how to stand firm without fear."],
        ];
        $Q = [
            ['The group project / workplace crisis',
             "You're part of a team working on a tight deadline. The team leader is disorganized, abrasive, and dropped the ball on a critical section. The project is about to fail, costing everyone their grade or bonus. You saw this coming weeks ago and tried to warn them, but they shut you down.",
             ["You document every warning you sent, let the project fail, and present the evidence so you don't take the fall.",
              "You quietly fix just your own section so your work looks clean, then keep your head down.",
              "You step up quietly, take on the extra workload, and coordinate the team to fix the gap for the collective outcome."]],
            ['The toxic boss / instructor',
             "You report to someone notoriously unfair and volatile, who takes credit for your ideas while humiliating junior staff. You're exhausted, underpaid, and can't afford to quit right now.",
             ["You adapt: stop putting in extra effort, badmouth them behind closed doors, occasionally sabotage minor tasks.",
              "You fake a smile, agree with everything, do exactly what you're told — no more, no less.",
              "You refuse to let their toxicity degrade your standard, protect junior colleagues, and build quiet excellence around you."]],
            ['The “harmless” system shortcut',
             "You're applying for an urgent document, stuck in bureaucracy for weeks. A friend introduces you to an insider who offers to process it in ten minutes for a small “fee” everyone pays. Skip it, and you miss a critical deadline.",
             ["You pay immediately. It's not your fault the system is broken.",
              "You wait as long as you can, then give in as the deadline nears, telling yourself you had no choice.",
              "You refuse to pay and exhaust every official escalation route, even risking the deadline."]],
            ["The friend's mistake",
             "A close friend made a serious record-keeping error that cost the team resources. It was an honest accident, but if discovered, they'll be fired. Your supervisor asks you directly if you know what happened.",
             ["You cover for your friend, or lie directly to the supervisor. Loyalty comes first.",
              "You give a vague, evasive answer to protect them without technically lying.",
              "You tell your friend they must come clean, offer to stand with them, but won't lie if asked directly."]],
            ['Unearned credit & the easy win',
             "Due to a mix-up, your superior praises you for solving a problem actually fixed by a shy junior colleague. The praise comes with a possible promotion or scholarship.",
             ["You accept the praise and the opportunity. They'll get their turn eventually.",
              "You say “it was a team effort” without naming them, keeping the lion's share of credit.",
              "You immediately correct the supervisor and name the colleague clearly, in that exact moment."]],
            ['The unsupervised standard',
             "You managed a budget for an event with zero oversight, working twenty extra unpaid hours. There's a surplus that will simply vanish back into a general pool if unused.",
             ["You write off part of the surplus as a “stipend” for yourself. You earned it.",
              "You spend the surplus on unnecessary extras just so it doesn't look like you under-spent.",
              "You meticulously report the exact surplus back, down to the last unit."]],
        ];
        $questions = [];
        foreach ($Q as $q) {
            $opts = [];
            $keys = ['A', 'B', 'C'];
            foreach ($q[2] as $i => $text) $opts[] = ['t' => $text, 'p' => $keys[$i], 'c' => 0];
            $questions[] = ['prompt' => $q[0] . ' — ' . $q[1], 'options' => $opts, 'points' => 0];
        }
        self::saveQuiz(0, [
            'slug' => $slug,
            'title' => 'The Grassroots Incorruptible Test',
            'description' => "Six real-world dilemmas. Pick what you'd actually do — not who you hope you are. Discover your integrity profile.",
            'category' => 'Integrity', 'difficulty' => 'medium', 'type' => 'profile',
            'profiles' => $profiles, 'questions' => $questions, 'published' => 1,
        ]);
    }
}
