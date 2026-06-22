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

    // Real access-model configuration for the programmes (no demo curriculum
    // is seeded — modules and lessons are authored in the Studio at /admin/).
    // open (all free) · tracked (free, account-gated) · membership · paid.
    $access = [
        'techome'      => ['tracked', 0],
        'africa-gates' => ['membership', 0],
        'ngv-academy'  => ['paid', 15000],
        'mediapro'     => ['open', 0],
    ];
    foreach ($access as $slug => [$type, $price]) {
        $pdo->prepare('UPDATE courses SET access_type = ?, price_ngn = ? WHERE slug = ?')->execute([$type, $price, $slug]);
    }
}

function av_lesson_slug(string $t): string { $s = strtolower(trim($t)); $s = preg_replace('/[^a-z0-9]+/', '-', $s); return trim($s, '-'); }

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once dirname(__DIR__) . '/lib/bootstrap.php';
    $pdo = Database::pdo();
    av_seed_lessons($pdo, in_array('--fresh', $argv, true));
    fwrite(STDOUT, 'Seeded ' . (int) $pdo->query('SELECT COUNT(*) FROM lessons')->fetchColumn() . " lessons.\n");
}
