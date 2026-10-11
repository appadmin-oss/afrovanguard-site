<?php
/**
 * admin/preview.php — the homepage as it WOULD look with the Studio's draft.
 * Studio users only; never indexed; nothing is written.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$role = function_exists('av_admin_role') ? av_admin_role() : '';
if ($role === '') { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo 'Sign in to the Studio to preview.'; exit; }

$html = SiteHome::previewHtml(SiteHome::draft());
if ($html === null) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'The draft could not be previewed. Open the Studio and check it for errors.'; exit; }

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$bar = '<div class="cms-preview-bar" role="status">Preview of your draft — not published. <a href="/admin/#homepage">Back to the Studio</a></div>';
echo preg_replace('/<body([^>]*)>/i', '<body$1>' . $bar, $html, 1);
