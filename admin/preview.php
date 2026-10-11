<?php
/**
 * admin/preview.php — a page as it WOULD look with the Studio's draft.
 *   /admin/preview.php              the homepage hero draft (Content → Homepage)
 *   /admin/preview.php?page=<file>  a page's draft (Content → Pages)
 * Studio users only; never indexed; nothing is written.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$role = function_exists('av_admin_role') ? av_admin_role() : '';
if ($role === '') { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo 'Sign in to the Studio to preview.'; exit; }

$page = (string) ($_GET['page'] ?? '');
if ($page !== '') {
    if (!SitePages::known($page)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'That page is not one the Studio edits.'; exit; }
    $path = SitePages::path($page);
    if (str_ends_with($page, '.php')) {
        // A template: render it as a visitor would get it, then put the draft
        // over the marked elements (the marks are literal in the output too).
        $previewPath = $path;
        ob_start();
        try { (static function () use ($previewPath) { chdir(dirname($previewPath)); include $previewPath; })(); }
        catch (Throwable $e) { ob_end_clean(); http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'The page could not be rendered for a preview.'; exit; }
        $html = SitePages::apply((string) ob_get_clean(), SitePages::draft($page));
    } else {
        $html = SitePages::previewHtml($page);
    }
    $back = '/admin/#pages';
} else {
    $html = SiteHome::previewHtml(SiteHome::draft());
    $back = '/admin/#homepage';
}
if ($html === null) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'The draft could not be previewed. Open the Studio and check it for errors.'; exit; }

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$bar = '<div class="cms-preview-bar" role="status">Preview of your draft — not published. <a href="' . $back . '">Back to the Studio</a></div>';
echo preg_replace('/<body([^>]*)>/i', '<body$1>' . $bar, $html, 1);
