<?php
/**
 * doc.php — /doc/<name>.pdf: one of the site's published documents, by name.
 *
 *   /doc/afrovanguard-ethos.pdf            read in the browser (inline)
 *   /doc/afrovanguard-ethos.pdf?dl=1       save it (attachment)
 *   /doc/afrovanguard-ethos.pdf?v=<hash>   cacheable for a year
 *
 * Names come from DocStream::DOCS only; anything else is a 404. Range, ETag,
 * 304 and the chunked copy live in lib/DocStream.php. Deliberately does not
 * load lib/bootstrap.php: serving a file needs no session or database.
 */
declare(strict_types=1);
if (!defined('AV_ROOT')) define('AV_ROOT', __DIR__);
require_once AV_ROOT . '/lib/DocStream.php';

DocStream::send(preg_replace('/\.pdf$/i', '', (string) ($_GET['name'] ?? '')) ?? '');
