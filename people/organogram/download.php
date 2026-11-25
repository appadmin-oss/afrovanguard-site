<?php
/**
 * people/organogram/download.php — /people/organogram/download/: the organogram
 * as a one-page A4 landscape PDF, built server-side with Dompdf
 * (Organogram::pdf) and cached by the structure and the listed names, so it
 * rebuilds when either changes and at no other time. Public, like the page,
 * and carries only names the public People page shows. ?view=1 opens it in
 * the browser instead of saving it.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/Organogram.php';

$pdf = Organogram::pdf(Organogram::people(Database::pdo()));
if ($pdf === null || $pdf === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The PDF is not available right now. Please use the Print button at /people/organogram/.\n";
    return;
}
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . (isset($_GET['view']) ? 'inline' : 'attachment') . '; filename="' . Organogram::FILENAME . '"');
header('Cache-Control: public, max-age=600');
echo $pdf;
