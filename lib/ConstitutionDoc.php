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
    public const VERSION  = '23 November 2026';
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

        $cache = av_private_path('constitution-' . substr(hash('sha256', $md . self::VERSION . '|v3'), 0, 16) . '.pdf');
        if (is_file($cache) && filesize($cache) > 0) return (string) file_get_contents($cache);

        [$html, $toc] = self::render($md);
        $opt = new \Dompdf\Options([
            'isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true,
            'defaultFont' => 'DejaVu Serif', 'chroot' => AV_ROOT, 'tempDir' => sys_get_temp_dir(),
        ]);
        $pdf = new \Dompdf\Dompdf($opt);
        $pdf->loadHtml(self::pdfHtml($html, $toc), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        // Running footer on every page after the cover.
        $canvas = $pdf->getCanvas();
        $font   = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $label  = self::PUBLIC ? self::TITLE . ' · ' . self::EDITION : self::TITLE . ' · Internal — members only';
        $canvas->page_script(static function ($pageNumber, $pageCount, $c) use ($font, $label) {
            if ($pageNumber === 1) return;
            $c->line(56, 800, 539, 800, [0.66, 0.51, 0.10], 0.5);
            $c->text(56, 806, $label, $font, 7.5, [0.36, 0.39, 0.45]);
            $c->text(500, 806, "Page $pageNumber of $pageCount", $font, 7.5, [0.36, 0.39, 0.45]);
        });

        $out = (string) $pdf->output();
        if ($out !== '') @file_put_contents($cache, $out, LOCK_EX);
        return $out;
    }

    private static function pdfHtml(string $body, array $toc): string
    {
        $e    = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $seal = is_file(AV_ROOT . '/assets/site/av-seal-256.png')
            ? '<img class="seal" src="' . $e(AV_ROOT . '/assets/site/av-seal-256.png') . '" alt="">' : '';
        $class = self::PUBLIC ? '' : '<div class="classif">Internal &middot; Afrovanguard members only</div>';

        $contents = '';
        foreach ($toc as $p) {
            $contents .= '<div class="toc-part"><a href="#' . $e($p['id']) . '">' . $e($p['part']) . '</a></div>';
            foreach ($p['items'] as [$id, $label]) {
                $contents .= '<div class="toc-item"><a href="#' . $e($id) . '">' . $e($label) . '</a></div>';
            }
        }

        $css = <<<'CSS'
@page { margin: 62pt 56pt 66pt 56pt; }
@page :first { margin: 0; }
body { font-family: 'DejaVu Serif', serif; font-size: 9.6pt; line-height: 1.5; color: #1d222b; }
a { color: #1d222b; text-decoration: none; }

.cover { position: relative; height: 841pt; background: #0A0F1A; color: #fff; page-break-after: always; }
.cover .band { position: absolute; left: 0; right: 0; top: 0; height: 6pt; background: #a8821a; }
.cover .inner { position: absolute; left: 64pt; right: 64pt; top: 150pt; text-align: center; }
.cover .seal { width: 110pt; height: 110pt; }
.cover .kicker { margin-top: 26pt; font: bold 8.5pt 'DejaVu Sans', sans-serif; letter-spacing: 3pt; color: #f3b416; text-transform: uppercase; }
.cover h1 { font-size: 30pt; line-height: 1.15; margin: 14pt 0 10pt; color: #fff; font-weight: bold; }
.cover p { text-align: center; }
.cover .org { font-size: 11pt; color: #d9dde4; margin: 0 0 4pt; }
.cover .motto { font-size: 12pt; font-style: italic; color: #f3b416; margin: 0 0 30pt; }
.cover .rule { width: 90pt; height: 1.5pt; background: #a8821a; margin: 0 auto 24pt; }
.cover .meta { font: 9pt 'DejaVu Sans', sans-serif; color: #b9c0cb; line-height: 1.7; }
.cover .classif { position: absolute; left: 0; right: 0; bottom: 64pt; text-align: center;
  font: bold 8pt 'DejaVu Sans', sans-serif; letter-spacing: 2pt; text-transform: uppercase; color: #f3b416; }

.contents { page-break-after: always; }
.contents h2 { font-size: 18pt; margin: 0 0 14pt; padding-bottom: 6pt; border-bottom: 1pt solid #a8821a; }
.toc-part { font: bold 8.5pt 'DejaVu Sans', sans-serif; text-transform: uppercase; letter-spacing: 1pt; color: #8a6a10; margin: 9pt 0 3pt; }
.toc-part a { color: #8a6a10; }
.toc-item { font-size: 9.2pt; margin: 0 0 1.5pt 12pt; }

h2.const-part { font: bold 9pt 'DejaVu Sans', sans-serif; text-transform: uppercase; letter-spacing: 2pt;
  color: #8a6a10; border-bottom: 1pt solid #a8821a; padding-bottom: 4pt; margin: 0 0 14pt; page-break-before: always; }
h2.const-part.first { page-break-before: auto; }
h3.const-article { font-size: 14pt; line-height: 1.25; margin: 18pt 0 8pt; color: #0A0F1A; page-break-after: avoid; }
h4 { font: bold 9.8pt 'DejaVu Sans', sans-serif; margin: 12pt 0 4pt; color: #0A0F1A; page-break-after: avoid; }
p { margin: 0 0 6pt; text-align: justify; }
ul, ol { margin: 0 0 8pt 0; padding-left: 16pt; }
ul.const-clauses { list-style: none; padding-left: 14pt; }
li { margin: 0 0 3pt; }
strong { color: #0A0F1A; }
blockquote { margin: 6pt 0 10pt; padding: 8pt 14pt; border-left: 2.5pt solid #a8821a; background: #f7f5ef; font-style: italic; }
blockquote p { margin: 0 0 5pt; }
hr { border: 0; border-top: 0.6pt solid #d8dce3; margin: 14pt 0; }
table { width: 100%; border-collapse: collapse; margin: 4pt 0 10pt; font: 8.6pt 'DejaVu Sans', sans-serif; page-break-inside: auto; }
tr { page-break-inside: avoid; }
th { text-align: left; background: #0A0F1A; color: #fff; padding: 5pt 7pt; font-weight: bold; }
td { padding: 4.5pt 7pt; border-bottom: 0.6pt solid #e2e5ea; vertical-align: top; }
tr:nth-child(even) td { background: #faf8f3; }
CSS;

        // The first Part follows the contents page, so it needs no extra break.
        $body = preg_replace('~<h2 class="const-part"~', '<h2 class="const-part first"', $body, 1) ?? $body;

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . $e(self::TITLE) . '</title><style>' . $css . '</style></head><body>'
            . '<div class="cover"><div class="band"></div><div class="inner">' . $seal
            . '<div class="kicker">Foundational Constitution</div>'
            . '<h1>' . $e(self::TITLE) . '</h1>'
            . '<p class="org">Ambassadors for Community, Tech and Cultural Advancements</p>'
            . '<p class="motto">The Force for Good</p>'
            . '<div class="rule"></div>'
            . '<div class="meta">' . $e(self::EDITION) . '<br>Version date: ' . $e(self::VERSION)
            . '<br>Founded as Okun Alimosho, 9 November 2017<br>Alimosho, Lagos, Nigeria</div>'
            . '</div>' . $class . '</div>'
            . '<div class="contents"><h2>Contents</h2>' . $contents . '</div>'
            . $body
            . '</body></html>';
    }
}
