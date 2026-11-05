<?php
/**
 * llms.php — /llms.txt: who Afrovanguard is and where things are, in plain
 * Markdown, for AI assistants and answer engines (the llms.txt convention).
 *
 * The sheet is lib/SiteFacts.php — the same one Chioma is briefed with, so
 * the site, its assistant and outside AI tools say the same things.
 *
 * .htaccess: ^llms\.txt$ → llms.php (router.php mirrors it).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');
echo SiteFacts::markdown();
