<?php
/**
 * give/share.php — count a share. POST slug, via.
 *
 * A counter and nothing else: it stores no identifier, sets no cookie and
 * returns no content. Rate-limited so it cannot be used to inflate a number,
 * and it answers 204 either way — a share that was not counted is not worth
 * telling the visitor about, and a differing response would make the limit
 * itself a thing to probe.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit; }
if (function_exists('require_same_origin')) require_same_origin();

http_response_code(204);
if (function_exists('av_rate_ok') && !av_rate_ok('give_share', 60, 600)) exit;

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_POST['slug'] ?? '')));
if ($slug === '') exit;
$a = Appeals::bySlug($slug);
if (Appeals::isPublic($a)) Appeals::countShare((int) $a['id']);
