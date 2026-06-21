<?php
/**
 * db/academy_seed.php — load db/academy_content.php into `courses`.
 * Auto-invoked on a fresh DB; also runnable:
 *   php db/academy_seed.php           # seed if empty
 *   php db/academy_seed.php --fresh   # wipe courses and re-seed
 */
declare(strict_types=1);

function av_seed_courses(PDO $pdo, bool $fresh = false): void
{
    $courses = require __DIR__ . '/academy_content.php';
    $pdo->beginTransaction();
    try {
        if ($fresh) $pdo->exec('DELETE FROM courses');
        $cols = ['slug','title','summary','body_html','cover_url','category','level','format',
                 'duration','price','location','gradient','outcomes','cta_url','featured','status','sort'];
        $ph = implode(',', array_map(fn($c) => ":$c", $cols));
        $st = $pdo->prepare('INSERT OR IGNORE INTO courses (' . implode(',', $cols) . ') VALUES (' . $ph . ')');
        foreach ($courses as $c) {
            $st->execute([
                ':slug' => $c['slug'], ':title' => $c['title'], ':summary' => $c['summary'] ?? '',
                ':body_html' => $c['body_html'] ?? '', ':cover_url' => $c['cover_url'] ?? null,
                ':category' => $c['category'] ?? 'Programme', ':level' => $c['level'] ?? 'All levels',
                ':format' => $c['format'] ?? 'In-person', ':duration' => $c['duration'] ?? '',
                ':price' => $c['price'] ?? 'Free', ':location' => $c['location'] ?? 'Alimosho, Lagos',
                ':gradient' => $c['gradient'] ?? 'g-gold', ':outcomes' => $c['outcomes'] ?? '',
                ':cta_url' => $c['cta_url'] ?? null, ':featured' => !empty($c['featured']) ? 1 : 0,
                ':status' => $c['status'] ?? 'published', ':sort' => $c['sort'] ?? 0,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once dirname(__DIR__) . '/lib/bootstrap.php';
    $pdo = Database::pdo();
    av_seed_courses($pdo, in_array('--fresh', $argv, true));
    fwrite(STDOUT, 'Seeded ' . (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn() . " courses.\n");
}
