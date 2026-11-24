<?php
/**
 * constitution/download.php — /constitution/download/: the Constitution as a
 * PDF, built server-side (lib/ConstitutionDoc.php). Same access rule as the
 * page. ?view=1 opens it in the browser instead of saving it.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
require_once AV_ROOT . '/lib/ConstitutionDoc.php';

ConstitutionDoc::gate('/constitution/download/');

$pdf = ConstitutionDoc::pdf();
if ($pdf === null || $pdf === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The PDF is not available right now. Please read the constitution at /constitution/.\n";
    exit;
}

$inline = isset($_GET['view']);
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . ConstitutionDoc::FILENAME . '"');
if (ConstitutionDoc::PUBLIC) header('Cache-Control: public, max-age=3600');
echo $pdf;
