<?php
/**
 * card/_render.php — turning the card partial into a file.
 *
 * Separate from card/print.php so it can be tested. print.php is an endpoint:
 * including it runs auth, reads $_GET and exits, which a test cannot do
 * anything with. CARD-07 asserts on the PDF's page box, page count and
 * metadata, so the thing that makes the PDF has to be callable on its own.
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/** The print sheet's markup, with every asset inlined. */
function card_html(array $card, string $layout, bool $bleed): string
{
    ob_start();
    if ($layout === 'a4') {
        include __DIR__ . '/print-a4.php';
    } else {
        include __DIR__ . '/print-template.php';
    }
    $html = (string) ob_get_clean();

    /* dompdf fetches <link> and <img> over HTTP, which on a server that
       cannot reach itself silently yields an unstyled card. Everything is
       inlined instead, so the render depends on the filesystem only. */
    return card_inline_assets($html);
}

/** Replace stylesheet links and local images with their contents. */
function card_inline_assets(string $html): string
{
    $root = dirname(__DIR__);

    $html = preg_replace_callback('~<link[^>]+href="(/[^"]+\.css)"[^>]*>~i',
        static function (array $m) use ($root): string {
            $p = $root . $m[1];
            return is_file($p) ? '<style>' . file_get_contents($p) . '</style>' : '';
        }, $html) ?? $html;

    /* Font files get an absolute filesystem path. dompdf resolves a relative
       url() against the document's base, and HTML passed as a string has no
       base — so '/assets/site/fonts/X.ttf' resolves to nothing, dompdf falls
       back to a core font, and a core font is NEVER embedded. That is exactly
       how a card comes out of the press in Helvetica. */
    $html = preg_replace_callback("~url\\(['\"]?(/assets/site/fonts/[^'\")]+)['\"]?\\)~i",
        static function (array $m) use ($root): string {
            $p = $root . $m[1];
            return is_file($p) ? "url('" . $p . "')" : 'url()';
        }, $html) ?? $html;

    $html = preg_replace_callback('~src="(/[^"]+\.(png|jpe?g|gif|svg))"~i',
        static function (array $m) use ($root): string {
            $p = $root . $m[1];
            if (!is_file($p)) return 'src=""';
            $mime = match (strtolower($m[2])) {
                'png' => 'image/png', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
                default => 'image/jpeg',
            };
            return 'src="data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($p)) . '"';
        }, $html) ?? $html;

    return $html;
}

/** dompdf, configured for a card rather than a document. */
function card_pdf(string $html, string $layout): string
{
    $opt = new \Dompdf\Options();
    $opt->set('isRemoteEnabled', false);        // everything is inlined already
    $opt->set('isHtml5ParserEnabled', true);
    $opt->set('defaultMediaType', 'print');
    $opt->set('isFontSubsettingEnabled', true); // §5: fonts embedded AND subset
    $opt->set('dpi', 96);
    $opt->set('chroot', dirname(__DIR__));

    $dompdf = new \Dompdf\Dompdf($opt);
    $dompdf->loadHtml($html, 'UTF-8');
    /* The @page rule in the template sets the real size. This is the fallback
       for a renderer that cannot read it, and it is the SAME size — a
       mismatch here is a card trimmed to the wrong box. */
    $dompdf->setPaper($layout === 'a4' ? 'a4' : [0, 0, 192.76, 282.33], 'portrait');
    $dompdf->render();

    /* §5, verbatim. The printer needs to be told; RGB sent to a press without
       this note comes back with the gold wrong. */
    $canvas = $dompdf->getCanvas();
    if (method_exists($canvas, 'get_cpdf')) {
        $cpdf = $canvas->get_cpdf();
        if (method_exists($cpdf, 'addInfo')) {
            $cpdf->addInfo('Subject', 'CR80 54×85.6 mm, 3 mm bleed, RGB. Ask the printer to convert to CMYK.');
            $cpdf->addInfo('Title', 'Afrovanguard member card');
            $cpdf->addInfo('Creator', 'Afrovanguard');
        }
    }

    return (string) $dompdf->output();
}

function card_cache_path(string $key, string $ext): string
{
    $dir = dirname(__DIR__) . '/data/card-cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/' . preg_replace('/[^a-z0-9-]/i', '', $key) . '.' . $ext;
}

/**
 * Twenty an hour per user, in a file beside the cache.
 *
 * A fixed window rather than a rolling one: this guards against a script, not
 * against a determined person, and a rolling log of every request would be a
 * record of who printed what and when for no benefit.
 */
function card_rate_ok(int $uid): bool
{
    $dir = dirname(__DIR__) . '/data/card-cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $f = $dir . '/rate-' . $uid . '.json';

    $hour = (int) date('YmdH');
    $n = 0;
    if (is_file($f)) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (is_array($j) && (int) ($j['hour'] ?? 0) === $hour) $n = (int) ($j['n'] ?? 0);
    }
    if ($n >= 20) return false;

    @file_put_contents($f, json_encode(['hour' => $hour, 'n' => $n + 1]));
    return true;
}

function card_send(string $path, string $name, string $mime): never
{
    card_send_body((string) file_get_contents($path), $name, $mime);
}

function card_send_body(string $body, string $name, string $mime): never
{
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $name) . '"');
    header('Content-Length: ' . strlen($body));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $body;
    exit;
}

function card_refuse(int $status, string $why): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $why], JSON_UNESCAPED_UNICODE);
    exit;
}
