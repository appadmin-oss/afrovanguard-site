<?php
/**
 * db/lessons_seed.php — access model + a real starter curriculum for the Academy.
 *
 * Auto-runs once when the lessons table is empty (see Database::ensureAcademy).
 * Each course gets a navigable path built from its own catalogue copy
 * (db/academy_content.php): an open "Welcome" preview, one lesson per stated
 * outcome, and a short orientation quiz — so the full enrol → learn → complete →
 * certificate loop works out of the box. Instructors refine it in the Studio.
 *   php db/lessons_seed.php --fresh
 */
declare(strict_types=1);

function av_lesson_slug(string $t): string { $s = strtolower(trim($t)); $s = preg_replace('/[^a-z0-9]+/', '-', $s); return trim($s, '-'); }

function av_seed_lessons(PDO $pdo, bool $fresh = false): void
{
    if ($fresh) { $pdo->exec('DELETE FROM lessons'); $pdo->exec('DELETE FROM modules'); }

    // Access model per programme: open (all free) · tracked (free, account-gated)
    // · membership · paid. Unlisted courses keep the schema default ('open').
    $access = [
        'techome'                => ['tracked', 0],
        'africa-gates'           => ['membership', 0],
        'ngv-academy'            => ['paid', 15000],
        'mediapro'               => ['open', 0],
        'street-to-stardom'      => ['open', 0],
        'next-generation-genius' => ['open', 0],
    ];
    foreach ($access as $slug => [$type, $price]) {
        $pdo->prepare('UPDATE courses SET access_type = ?, price_ngn = ? WHERE slug = ?')->execute([$type, $price, $slug]);
    }

    // Build a starter curriculum from the canonical catalogue copy.
    $catalogue = is_file(__DIR__ . '/academy_content.php') ? (require __DIR__ . '/academy_content.php') : [];
    $now = gmdate('Y-m-d H:i:s');

    $insMod = $pdo->prepare('INSERT INTO modules (course_id, title, position) VALUES (?,?,?)');
    $insLes = $pdo->prepare(
        'INSERT INTO lessons (module_id, course_id, slug, title, body_html, duration_min, is_preview, position, created_at, updated_at, quiz_json)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );

    foreach ($catalogue as $c) {
        $slug = (string) ($c['slug'] ?? '');
        if ($slug === '') continue;
        $row = $pdo->prepare('SELECT id FROM courses WHERE slug = ?');
        $row->execute([$slug]);
        $courseId = (int) $row->fetchColumn();
        if (!$courseId) continue;
        // Skip if this course already has lessons (idempotent per course).
        $has = $pdo->prepare('SELECT COUNT(*) FROM lessons WHERE course_id = ?');
        $has->execute([$courseId]);
        if ((int) $has->fetchColumn() > 0) continue;

        $title    = (string) ($c['title'] ?? $slug);
        $summary  = trim((string) ($c['summary'] ?? ''));
        $intro    = trim((string) ($c['body_html'] ?? ''));
        $format   = (string) ($c['format'] ?? 'In-person');
        $duration = (string) ($c['duration'] ?? '');
        $level    = (string) ($c['level'] ?? 'All levels');
        $outcomes = array_values(array_filter(array_map('trim', explode("\n", (string) ($c['outcomes'] ?? '')))));

        $lessonSlugs = [];
        $uslug = function (string $t) use (&$lessonSlugs): string {
            $base = av_lesson_slug($t) ?: 'lesson'; $s = $base; $i = 2;
            while (isset($lessonSlugs[$s])) { $s = $base . '-' . $i++; }
            $lessonSlugs[$s] = true; return $s;
        };

        /* Module 1 — Orientation (an open preview so visitors can sample). */
        $insMod->execute([$courseId, 'Orientation', 0]);
        $mOrient = (int) $pdo->lastInsertId();
        $welcomeBody = ($intro !== '' ? $intro : '<p>' . htmlspecialchars($summary, ENT_QUOTES) . '</p>')
            . '<h2>How this works</h2><p>This is your home base for <strong>' . htmlspecialchars($title, ENT_QUOTES) . '</strong> ('
            . htmlspecialchars($level, ENT_QUOTES) . ' · ' . htmlspecialchars($format, ENT_QUOTES)
            . ($duration !== '' ? ' · ' . htmlspecialchars($duration, ENT_QUOTES) : '') . '). Work through each lesson, '
            . 'mark it complete as you go, and pass the short orientation quiz to earn your certificate. '
            . 'For in-person cohorts, use these lessons alongside your sessions with your mentor.</p>';
        $insLes->execute([$mOrient, $courseId, $uslug('Welcome to ' . $title), 'Welcome to ' . $title, $welcomeBody, 5, 1, 0, $now, $now, null]);

        /* Module 2 — The path: one lesson per stated outcome. */
        $insMod->execute([$courseId, 'The path', 1]);
        $mPath = (int) $pdo->lastInsertId();
        $pos = 0;
        foreach ($outcomes as $oc) {
            $body = '<p>By the end of this lesson you will be working toward: <strong>' . htmlspecialchars($oc, ENT_QUOTES) . '</strong>.</p>'
                . '<p>Practise it with your cohort and mentor, then mark this lesson complete when you have covered it.</p>';
            $insLes->execute([$mPath, $courseId, $uslug($oc), $oc, $body, 20, 0, $pos++, $now, $now, null]);
        }

        /* Module 3 — Wrap up: an orientation quiz that gates the certificate. */
        $insMod->execute([$courseId, 'Wrap up', 2]);
        $mWrap = (int) $pdo->lastInsertId();
        $quiz = [
            'pass' => 67,
            'questions' => [
                [
                    'q' => 'What is Afrovanguard’s goal by 2040?',
                    'options' => [
                        'To raise one million incorruptible African leaders',
                        'To build the largest tech company in Africa',
                        'To open one thousand cinemas',
                    ],
                    'answer' => 0,
                ],
                [
                    'q' => 'How is "' . $title . '" delivered?',
                    'options' => array_values(array_unique([
                        $format,
                        ($format === 'Online' ? 'In-person only' : 'Online only'),
                        'By post',
                    ])),
                    'answer' => 0,
                ],
                [
                    'q' => 'How do you complete a course in the Academy?',
                    'options' => [
                        'Finish every lesson and pass any quizzes',
                        'Just open the first lesson',
                        'Pay an exam fee',
                    ],
                    'answer' => 0,
                ],
            ],
        ];
        $insLes->execute([$mWrap, $courseId, $uslug('Check your understanding'), 'Check your understanding',
            '<p>A quick check before your certificate. You need ' . (int) $quiz['pass'] . '% to pass — you can retake it.</p>',
            10, 0, 0, $now, $now, json_encode($quiz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once dirname(__DIR__) . '/lib/bootstrap.php';
    $pdo = Database::pdo();
    av_seed_lessons($pdo, in_array('--fresh', $argv, true));
    fwrite(STDOUT, 'Seeded ' . (int) $pdo->query('SELECT COUNT(*) FROM lessons')->fetchColumn() . " lessons across "
        . (int) $pdo->query('SELECT COUNT(DISTINCT course_id) FROM lessons')->fetchColumn() . " courses.\n");
}
