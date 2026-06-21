<?php
/**
 * db/lessons_seed.php — demo curriculum + access types for the Academy LMS.
 * Auto-runs once when the lessons table is empty.
 *   php db/lessons_seed.php --fresh
 */
declare(strict_types=1);

function av_seed_lessons(PDO $pdo, bool $fresh = false): void
{
    if ($fresh) { $pdo->exec('DELETE FROM lessons'); $pdo->exec('DELETE FROM modules'); }

    // Access types: open (all free), tracked (free, account-gated), membership, paid.
    $access = [
        'techome' => ['tracked', 0],
        'africa-gates' => ['membership', 0],
        'ngv-academy' => ['paid', 15000],
        'mediapro' => ['open', 0],
    ];
    foreach ($access as $slug => [$type, $price]) {
        $pdo->prepare('UPDATE courses SET access_type = ?, price_ngn = ? WHERE slug = ?')->execute([$type, $price, $slug]);
    }

    $curricula = [
        'techome' => [
            ['Foundations', [
                ['Welcome to Techome', 'What this programme is, how it runs, and what you will build.', 6, 1],
                ['How to learn any tool', 'Reading docs, breaking problems down, and not panicking at new interfaces.', 12, 1],
                ['Thinking in systems', 'Inputs, outputs, and what happens when something fails.', 14, 0],
            ]],
            ['Building', [
                ['Your first small project', 'Scope something tiny and real — and finish it.', 18, 0],
                ['Shipping & feedback', 'Put it in front of someone and iterate.', 12, 0],
            ]],
            ['Becoming a mentor', [
                ['Teaching the next cohort', 'How graduates close the loop by coming back to teach.', 10, 0],
            ]],
        ],
        'africa-gates' => [
            ['Orientation', [
                ['The five gates', 'Governance, Advocacy, Tech, Entrepreneurship, Service.', 8, 1],
                ['Leading with integrity', 'The standard behind "incorruptible".', 16, 0],
            ]],
            ['Practicum', [
                ['Design your initiative', 'From idea to a pitch you can defend.', 20, 0],
            ]],
        ],
    ];

    $modIns = $pdo->prepare('INSERT INTO modules (course_id, title, position) VALUES (?,?,?)');
    $lesIns = $pdo->prepare('INSERT INTO lessons (module_id, course_id, slug, title, body_html, duration_min, is_preview, position) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($curricula as $slug => $modules) {
        $cid = (int) ($pdo->query("SELECT id FROM courses WHERE slug = " . $pdo->quote($slug))->fetchColumn() ?: 0);
        if (!$cid) continue;
        foreach ($modules as $mi => [$mtitle, $lessons]) {
            $modIns->execute([$cid, $mtitle, $mi]);
            $mid = (int) $pdo->lastInsertId();
            foreach ($lessons as $li => [$ltitle, $sub, $dur, $preview]) {
                $body = '<p>' . htmlspecialchars($sub) . '</p>'
                      . '<p>This lesson is part of the ' . htmlspecialchars($slug) . ' curriculum. Full lesson content is authored in the Studio.</p>';
                $lesIns->execute([$mid, $cid, av_lesson_slug($ltitle), $ltitle, $body, $dur, $preview, $li]);
            }
        }
    }
}

function av_lesson_slug(string $t): string { $s = strtolower(trim($t)); $s = preg_replace('/[^a-z0-9]+/', '-', $s); return trim($s, '-'); }

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once dirname(__DIR__) . '/lib/bootstrap.php';
    $pdo = Database::pdo();
    av_seed_lessons($pdo, in_array('--fresh', $argv, true));
    fwrite(STDOUT, 'Seeded ' . (int) $pdo->query('SELECT COUNT(*) FROM lessons')->fetchColumn() . " lessons.\n");
}
