<?php
/**
 * db/content.php — canonical Afrovanguard Diary content.
 *
 * Single source of truth, seeded into the SQLite database by db/seed.php.
 * Ships EMPTY for production — real entries are written in the Studio at
 * /admin/. A default set of categories is still created by db/seed.php so
 * the editor has something to choose from.
 *
 * To pre-load entries from code instead, add article arrays here and run:
 *   php db/seed.php --fresh
 */
return [];
