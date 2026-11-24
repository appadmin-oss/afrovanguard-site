<?php
/**
 * ConstitutionDoc — the Constitution of Afrovanguard, on the web and as a PDF.
 *
 *   Source of truth:  docs/governance/constitution.md (never served directly)
 *   Web page:         /constitution/            (constitution/index.php)
 *   PDF download:     /constitution/download/   (constitution/download.php)
 *
 * One access rule covers both: ConstitutionDoc::PUBLIC. While it is false the
 * page and the PDF are for verified Afrovanguard members only (signed-out
 * visitors are sent to sign-in, other accounts get a 403) and neither is
 * indexed. Set it to true to publish both to everyone.
 *
 * The PDF is built server-side with Dompdf and cached under the private store
 * by a hash of the Markdown, so editing the text rebuilds it on the next
 * download and nothing else does.
 */
declare(strict_types=1);
require_once AV_ROOT . '/lib/partials.php'; // av_login_url(), render_head() for the gate

final class ConstitutionDoc
{
    /** false = members only; true = open to the public. */
    public const PUBLIC = false;

    public const TITLE    = 'The Constitution of Afrovanguard';
    public const EDITION  = '2026 Revised Edition';
    public const VERSION  = '24 November 2026';
    public const FILENAME = 'Afrovanguard-Constitution-2026.pdf';

    public static function source(): string
    {
        return AV_ROOT . '/docs/governance/constitution.md';
    }

    public static function markdown(): string
    {
        $f = self::source();
        return is_file($f) ? (string) file_get_contents($f) : '';
    }

    /**
     * Enforce the access rule. Returns the signed-in user (or null on a public
     * document). Ends the request with a redirect or a 403 page otherwise.
     */
    public static function gate(string $next): ?array
    {
        if (self::PUBLIC) return class_exists('LmsAuth') ? LmsAuth::user() : null;

        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: private, no-store');
        $u = LmsAuth::user();
        if (!$u) { header('Location: ' . av_login_url($next)); exit; }
        if (LmsAuth::isOrgMember($u)) return $u;

        http_response_code(403);
        render_head(['title' => 'Members only — Afrovanguard', 'robots' => 'noindex, nofollow', 'css' => ['/assets/legal.css']]);
        render_nav('');
        echo '<main id="main" tabindex="-1" class="legal-main"><header class="legal-hero"><div class="container">'
           . '<span class="legal-kicker">Internal</span><h1>Members only</h1>'
           . '<p class="legal-lede">The Constitution of Afrovanguard is available to verified Afrovanguard members. '
           . 'If you are a member and see this, ask the membership team to verify your account.</p>'
           . '</div></header></main>';
        render_footer();
        exit;
    }

    /**
     * Markdown body (everything after the first "---" rule) to HTML, with ids
     * on Parts, Articles, Schedules and Sections. Returns [html, toc] where toc
     * is [['part' => title, 'id' => id, 'items' => [[id, label], …]], …].
     */
    public static function render(string $md): array
    {
        $parts = preg_split('/^---\s*$/m', $md, 2);
        $body  = trim($parts[1] ?? $md);

        if (class_exists(\League\CommonMark\MarkdownConverter::class)) {
            $env = new \League\CommonMark\Environment\Environment([
                'html_input' => 'escape', 'allow_unsafe_links' => false,
            ]);
            $env->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());
            $env->addExtension(new \League\CommonMark\Extension\Table\TableExtension());
            $html = (string) (new \League\CommonMark\MarkdownConverter($env))->convert($body);
        } else {
            $html = '<pre>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</pre>';
        }

        $toc = [];
        $article = '';
        $slug = static fn(string $t): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-');
        $html = preg_replace_callback('~<h([234])>(.*?)</h\1>~s', static function ($m) use (&$toc, &$article, $slug) {
            $level = (int) $m[1];
            $text  = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
            if ($level === 2) {
                $id = 'part-' . $slug($text);
                $toc[] = ['part' => $text, 'id' => $id, 'items' => []];
                $article = '';
                return '<h2 class="const-part" id="' . $id . '">' . $m[2] . '</h2>';
            }
            if ($level === 3) {
                if (preg_match('/^Article\s+([IVXLC]+)/', $text, $a))   $id = 'article-' . strtolower($a[1]);
                elseif (preg_match('/^Schedule\s+([A-Z])/', $text, $a)) $id = 'schedule-' . strtolower($a[1]);
                else $id = $slug($text);
                $article = $id;
                if ($toc) $toc[count($toc) - 1]['items'][] = [$id, $text];
                return '<h3 class="const-article" id="' . $id . '">' . $m[2] . '</h3>';
            }
            $id = ($article !== '' && preg_match('/^Section\s+(\d+)/', $text, $s)) ? $article . '-section-' . $s[1] : '';
            return $id !== '' ? '<h4 id="' . $id . '">' . $m[2] . '</h4>' : '<h4>' . $m[2] . '</h4>';
        }, $html) ?? $html;

        // Lettered clauses — "(a) …" — carry their own marker.
        $html = preg_replace('~<ul>(\s*<li>\([a-z]\))~', '<ul class="const-clauses">$1', $html) ?? $html;
        return [$html, $toc];
    }

    /** Web flavour: tables scroll inside their own box on narrow screens. */
    public static function webHtml(string $html): string
    {
        $html = str_replace('<table>', '<div class="legal-table-wrap" tabindex="0"><table class="legal-table">', $html);
        return str_replace('</table>', '</table></div>', $html);
    }

    /** The PDF bytes, built once per version of the text. Null if Dompdf is missing. */
    public static function pdf(): ?string
    {
        $md = self::markdown();
        if ($md === '' || !class_exists(\Dompdf\Dompdf::class)) return null;

        $cache = av_private_path('constitution-' . substr(hash('sha256', $md . self::VERSION . '|v7'), 0, 16) . '.pdf');
        if (is_file($cache) && filesize($cache) > 0) return (string) file_get_contents($cache);

        [$html, $toc] = self::render($md);
        $opt = new \Dompdf\Options([
            'isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true,
            'defaultFont' => 'DejaVu Sans', 'fontDir' => sys_get_temp_dir() . '/av-dompdf-fonts', 'fontCache' => sys_get_temp_dir() . '/av-dompdf-fonts', 'chroot' => AV_ROOT, 'tempDir' => sys_get_temp_dir(),
        ]);
        @mkdir(sys_get_temp_dir() . '/av-dompdf-fonts', 0700, true);
        $pdf = new \Dompdf\Dompdf($opt);
        $pdf->loadHtml(self::pdfHtml($html, $toc), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        // Running footer: a quiet centred folio on every page after the cover.
        $canvas = $pdf->getCanvas();
        $metrics = $pdf->getFontMetrics();
        $serif  = $metrics->getFont('AV Display', 'normal') ?: $metrics->getFont('DejaVu Serif', 'normal');
        $sans   = $metrics->getFont('AV Text', 'normal') ?: $metrics->getFont('DejaVu Sans', 'normal');
        $label  = self::PUBLIC ? strtoupper(self::TITLE) : strtoupper(self::TITLE) . '  ·  INTERNAL';
        $canvas->page_script(static function ($pageNumber, $pageCount, $c, $fm) use ($serif, $sans, $label) {
            if ($pageNumber === 1) return;
            $w = 595.28;
            $lw = $fm->getTextWidth($label, $sans, 6.5, 1.2);
            $c->text(($w - $lw) / 2, 30, $label, $sans, 6.5, [0.55, 0.42, 0.10], 1.2);
            $n = (string) $pageNumber;
            $nw = $fm->getTextWidth($n, $serif, 11);
            $c->text(($w - $nw) / 2, 806, $n, $serif, 11, [0.25, 0.27, 0.31]);
        });

        $out = (string) $pdf->output();
        if ($out !== '') @file_put_contents($cache, $out, LOCK_EX);
        return $out;
    }

    /** Shape the rendered body for print: two-line Part and Article headings, hanging clause numbers. */
    private static function printBody(string $body): string
    {
        // "Part One — Foundations" → PART ONE / Foundations, each Part on a fresh page.
        $body = preg_replace_callback('~<h2 class="const-part" id="([^"]+)">(.*?)</h2>~s', static function ($m) {
            $parts = preg_split('/\s+—\s+/u', html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'), 2);
            $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
            if (count($parts) === 2) {
                return '<div class="part" id="' . $m[1] . '"><div class="part-no">' . $e($parts[0]) . '</div>'
                     . '<div class="part-rule"></div><div class="part-title">' . $e($parts[1]) . '</div></div>';
            }
            return '<div class="part part-solo" id="' . $m[1] . '"><div class="part-title">' . $e($parts[0]) . '</div><div class="part-rule"></div></div>';
        }, $body) ?? $body;

        // "Article I — The Institution" → ARTICLE I / The Institution, centred.
        $body = preg_replace_callback('~<h3 class="const-article" id="([^"]+)">(.*?)</h3>~s', static function ($m) {
            $parts = preg_split('/\s+—\s+/u', html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'), 2);
            $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
            if (count($parts) === 2) {
                return '<div class="art" id="' . $m[1] . '"><div class="art-no">' . $e($parts[0]) . '</div>'
                     . '<div class="art-title">' . $e($parts[1]) . '</div></div>';
            }
            return '<div class="art" id="' . $m[1] . '"><div class="art-title">' . $e($parts[0]) . '</div></div>';
        }, $body) ?? $body;

        // "Section 4. Vision" → small-caps label with the number set apart.
        $body = preg_replace('~<h4([^>]*)>Section (\d+)\.\s*(.*?)</h4>~s',
            '<h4$1><span class="sec-no">Section $2</span> $3</h4>', $body) ?? $body;

        // Numbered clauses "(1) …" hang their number in the margin.
        $body = preg_replace('~<p>\((\d+)\)\s*~', '<p class="cl"><span class="cl-no">($1)</span>', $body) ?? $body;
        $body = preg_replace('~<li>\(([a-z])\)\s*~', '<li><span class="li-no">($1)</span>', $body) ?? $body;

        // The Preamble reads as a declaration.
        $body = preg_replace('~(<div class="part part-solo" id="part-preamble">.*?</div></div>)(.*?)(?=<hr />|<hr>)~s',
            '$1<div class="preamble">$2</div>', $body, 1) ?? $body;

        return str_replace(['<hr />', '<hr>'], '', $body);
    }

    private static function pdfHtml(string $body, array $toc): string
    {
        $e    = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $seal = is_file(AV_ROOT . '/assets/site/av-seal-256.png')
            ? '<img class="seal" src="' . $e(AV_ROOT . '/assets/site/av-seal-256.png') . '" alt="">' : '';
        $class = self::PUBLIC ? '' : '<div class="classif">Internal &nbsp;·&nbsp; For Afrovanguard members</div>';

        $contents = '';
        foreach ($toc as $p) {
            $contents .= '<div class="toc-part"><a href="#' . $e($p['id']) . '">' . $e($p['part']) . '</a></div>';
            foreach ($p['items'] as [$id, $label]) {
                $bits = preg_split('/\s+—\s+/u', $label, 2);
                $contents .= '<div class="toc-item"><a href="#' . $e($id) . '">'
                    . (count($bits) === 2 ? '<span class="toc-no">' . $e($bits[0]) . '</span>' . $e($bits[1]) : $e($label))
                    . '</a></div>';
            }
        }

        $css = <<<'CSS'
@font-face { font-family: 'AV Display'; font-style: normal; font-weight: normal; src: url('{ROOT}/assets/site/fonts/CormorantGaramond-500.ttf') format('truetype'); }
@font-face { font-family: 'AV Display'; font-style: normal; font-weight: bold; src: url('{ROOT}/assets/site/fonts/CormorantGaramond-700.ttf') format('truetype'); }
@font-face { font-family: 'AV Display'; font-style: italic; font-weight: normal; src: url('{ROOT}/assets/site/fonts/CormorantGaramond-500-italic.ttf') format('truetype'); }
@font-face { font-family: 'AV Display'; font-style: italic; font-weight: bold; src: url('{ROOT}/assets/site/fonts/CormorantGaramond-500-italic.ttf') format('truetype'); }
@font-face { font-family: 'AV Text'; font-style: normal; font-weight: normal; src: url('{ROOT}/assets/site/fonts/SourceSans3-400.ttf') format('truetype'); }
@font-face { font-family: 'AV Text'; font-style: normal; font-weight: bold; src: url('{ROOT}/assets/site/fonts/SourceSans3-700.ttf') format('truetype'); }
@font-face { font-family: 'AV Text'; font-style: italic; font-weight: normal; src: url('{ROOT}/assets/site/fonts/SourceSans3-400.ttf') format('truetype'); }
@font-face { font-family: 'AV Text'; font-style: italic; font-weight: bold; src: url('{ROOT}/assets/site/fonts/SourceSans3-700.ttf') format('truetype'); }
@page { margin: 70pt 78pt 72pt 78pt; }
@page :first { margin: 0; }
body { font-family: 'AV Text', sans-serif; font-size: 10.5pt; line-height: 1.22; color: #22262D; }
a { color: inherit; text-decoration: none; }

/* Cover — ivory, centred, restrained */
.cover { position: relative; height: 841pt; background: #F6F4EF; page-break-after: always; }
.cover .frame { position: absolute; left: 36pt; right: 36pt; top: 36pt; bottom: 36pt; border: 0.6pt solid #b8953a; }
.cover .inner { position: absolute; left: 80pt; right: 80pt; top: 170pt; text-align: center; }
.cover .seal { width: 84pt; height: 84pt; }
.cover .pre { margin-top: 34pt; font: 8pt 'AV Text', sans-serif; letter-spacing: 4pt; color: #8A6406; text-transform: uppercase; }
.cover h1 { font-family: 'AV Display', serif; font-size: 44pt; line-height: 1.02; margin: 14pt 0 0; color: #111827; font-weight: normal; }
.cover .rule { width: 60pt; height: 0.8pt; background: #b8953a; margin: 22pt auto; }
.cover p { text-align: center; margin: 0 0 4pt; }
.cover .org { font-size: 10.5pt; color: #3a404b; letter-spacing: .3pt; }
.cover .motto { font-family: 'AV Display', serif; font-size: 16pt; font-style: italic; color: #8A6406; margin-top: 6pt; }
.cover .meta { position: absolute; left: 0; right: 0; bottom: 118pt; text-align: center;
  font: 7.5pt 'AV Text', sans-serif; letter-spacing: 1.5pt; text-transform: uppercase; color: #6b717c; line-height: 2; }
.cover .classif { position: absolute; left: 0; right: 0; bottom: 70pt; text-align: center;
  font: 7pt 'AV Text', sans-serif; letter-spacing: 2.5pt; text-transform: uppercase; color: #8A6406; }

/* Contents */
.contents { page-break-after: always; }
.contents h2 { font-family: 'AV Display', serif; text-align: center; font-weight: normal; font-size: 30pt; margin: 0 0 4pt; color: #111827; }
.contents .rule { width: 40pt; height: 0.8pt; background: #b8953a; margin: 0 auto 20pt; }
.toc-part { font: 7.5pt 'AV Text', sans-serif; letter-spacing: 2pt; text-transform: uppercase; color: #8A6406; margin: 11pt 0 4pt; }
.toc-item { font-size: 10pt; margin: 0 0 2.5pt; }
.toc-no { display: inline-block; width: 86pt; color: #6b717c; font-size: 8.5pt; letter-spacing: .4pt; }

/* Parts */
.part { page-break-before: always; text-align: center; padding: 150pt 0 0; }
.part.first { page-break-before: auto; padding-top: 120pt; }
.part-no { font: 8pt 'AV Text', sans-serif; letter-spacing: 4pt; text-transform: uppercase; color: #8A6406; }
.part-rule { width: 40pt; height: 0.8pt; background: #b8953a; margin: 12pt auto; }
.part-title { font-family: 'AV Display', serif; font-size: 34pt; line-height: 1.1; color: #111827; margin-bottom: 36pt; }
.part-solo .part-title { margin-bottom: 0; }
.part-solo .part-rule { margin-bottom: 28pt; }

/* Articles */
.art { text-align: center; margin: 30pt 0 14pt; page-break-after: avoid; }
.art-no { font: 7.5pt 'AV Text', sans-serif; letter-spacing: 3pt; text-transform: uppercase; color: #8A6406; margin-bottom: 4pt; }
.art-title { font-family: 'AV Display', serif; font-size: 21pt; line-height: 1.15; color: #111827; }

/* Sections and clauses */
h4 { font-size: 10.5pt; font-weight: bold; color: #111827; margin: 14pt 0 5pt; page-break-after: avoid; }
.sec-no { font: 7.5pt 'AV Text', sans-serif; letter-spacing: 1.2pt; text-transform: uppercase; color: #8A6406; font-weight: bold; padding-right: 4pt; }
p { margin: 0 0 7pt; }
p.cl { padding-left: 22pt; }
.cl-no { float: left; margin-left: -22pt; width: 22pt; color: #6b717c; }
ul, ol { margin: 0 0 8pt; padding-left: 22pt; }
ul.const-clauses { list-style: none; padding-left: 44pt; }
ul.const-clauses { padding-left: 42pt; }
ul.const-clauses li { list-style: none; }
.li-no { float: left; margin-left: -20pt; width: 20pt; color: #6b717c; }
li { margin: 0 0 2.5pt; }
strong { color: #111827; }
em { color: #3a404b; }

.preamble { font-family: 'AV Display', serif; text-align: center; font-size: 13.5pt; line-height: 1.55; padding: 0 12pt; color: #22262D; }
.preamble p { margin: 0 0 9pt; }
.preamble strong { font-weight: normal; letter-spacing: 1.5pt; text-transform: uppercase; font-size: 9.5pt; color: #8A6406; }

blockquote { font-family: 'AV Display', serif; font-size: 12.5pt; margin: 10pt 18pt; padding: 0; font-style: italic; text-align: center; color: #2c313a; }
blockquote p { margin: 0 0 7pt; }

table { width: 100%; border-collapse: collapse; margin: 6pt 0 12pt; font: 8.4pt 'AV Text', sans-serif; }
tr { page-break-inside: avoid; }
th { text-align: left; color: #8A6406; font-weight: bold; font-size: 7pt; letter-spacing: 1.2pt; text-transform: uppercase;
  padding: 0 6pt 5pt; border-bottom: 0.8pt solid #b8953a; }
td { padding: 5pt 6pt; border-bottom: 0.4pt solid #e3e0d8; vertical-align: top; color: #2c313a; }
CSS;

        $css  = str_replace('{ROOT}', AV_ROOT, $css);
        $body = self::printBody($body);
        $body = preg_replace('~<div class="part~', '<div class="first part', $body, 1) ?? $body;
        $body = str_replace('class="first part', 'class="part first', $body);

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . $e(self::TITLE) . '</title><style>' . $css . '</style></head><body>'
            . '<div class="cover"><div class="frame"></div><div class="inner">' . $seal
            . '<div class="pre">Foundational Constitution</div>'
            . '<h1>The Constitution<br>of Afrovanguard</h1>'
            . '<div class="rule"></div>'
            . '<p class="org">Ambassadors for Community, Tech and Cultural Advancements</p>'
            . '<p class="motto">The Force for Good</p>'
            . '</div>'
            . '<div class="meta">' . $e(self::EDITION) . '<br>Founded as Okun Alimosho · 9 November 2017<br>Alimosho, Lagos, Nigeria</div>'
            . $class . '</div>'
            . '<div class="contents"><h2>Contents</h2><div class="rule"></div>' . $contents . '</div>'
            . $body
            . '</body></html>';
    }
}
