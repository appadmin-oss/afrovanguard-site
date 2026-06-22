<?php
/**
 * db/seed.php — load db/content.php (the canonical source) into the database.
 *
 * Auto-invoked by lib/Database.php on a fresh database. Can also be run
 * directly to (re)seed:
 *
 *   php db/seed.php           # seed only if empty
 *   php db/seed.php --fresh   # wipe content tables and re-seed
 */
declare(strict_types=1);

/** Default Diary categories so the Studio editor always has options,
 *  even on a production install that ships with no demo articles. */
const AV_DEFAULT_CATEGORIES = [
    'dispatch'    => 'Dispatch',
    'field-notes' => 'Field Notes',
    'mission'     => 'Mission',
    'programmes'  => 'Programmes',
];

function av_seed(PDO $pdo, bool $fresh = false): void
{
    $content = require __DIR__ . '/content.php';

    $pdo->beginTransaction();
    try {
        if ($fresh) {
            // Keep engagement (reactions/subscribers); rebuild content tables.
            $pdo->exec('DELETE FROM sections');
            $pdo->exec('DELETE FROM related');
            $pdo->exec('DELETE FROM articles');
            $pdo->exec('DELETE FROM categories');
        }

        // Categories: the default set, plus any referenced by seeded content.
        $cats = AV_DEFAULT_CATEGORIES;
        foreach ($content as $a) { $cats[$a['category_slug']] = $a['category']; }
        $catId = [];
        $insCat = $pdo->prepare('INSERT OR IGNORE INTO categories (slug, name) VALUES (?, ?)');
        $findCat = $pdo->prepare('SELECT id FROM categories WHERE slug = ?');
        foreach ($cats as $slug => $name) {
            $insCat->execute([$slug, $name]);
            $findCat->execute([$slug]);
            $catId[$slug] = (int) $findCat->fetchColumn();
        }

        $insArt = $pdo->prepare(
            'INSERT INTO articles
               (slug, title, dek, category_id, authors_html, published, published_at,
                read_minutes, gradient, mc_session, mc_title, mc_tag, body_html, base_claps, featured)
             VALUES (:slug,:title,:dek,:cat,:authors,:published,:published_at,
                :read,:grad,:mcs,:mct,:mctag,:body,:claps,:featured)'
        );
        $insSec = $pdo->prepare('INSERT INTO sections (article_id, anchor, label, position) VALUES (?,?,?,?)');
        $insRel = $pdo->prepare('INSERT INTO related (article_id, related_slug, position) VALUES (?,?,?)');
        $insRx  = $pdo->prepare('INSERT OR IGNORE INTO reactions (article_id, claps) VALUES (?, 0)');

        foreach ($content as $a) {
            $insArt->execute([
                ':slug' => $a['slug'], ':title' => $a['title'], ':dek' => $a['dek'],
                ':cat' => $catId[$a['category_slug']], ':authors' => $a['authors_html'],
                ':published' => $a['published'], ':published_at' => $a['published_at'],
                ':read' => $a['read_minutes'], ':grad' => $a['gradient'],
                ':mcs' => $a['mc_session'], ':mct' => $a['mc_title'], ':mctag' => $a['mc_tag'],
                ':body' => $a['body_html'], ':claps' => $a['base_claps'], ':featured' => $a['featured'],
            ]);
            $aid = (int) $pdo->lastInsertId();
            foreach ($a['toc'] as $i => $sec) { $insSec->execute([$aid, $sec[0], $sec[1], $i]); }
            foreach ($a['related'] as $i => $rel) { $insRel->execute([$aid, $rel, $i]); }
            $insRx->execute([$aid]);
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

// ---- CLI entry point ----
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once dirname(__DIR__) . '/lib/bootstrap.php';
    $pdo = Database::pdo();                  // migrates + auto-seeds an empty DB
    if (in_array('--fresh', $argv, true)) { av_seed($pdo, true); }
    $n = (int) $pdo->query('SELECT COUNT(*) FROM articles')->fetchColumn();
    fwrite(STDOUT, "Seeded {$n} articles.\n");
}
