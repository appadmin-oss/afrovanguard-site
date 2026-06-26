<?php
/**
 * lib/DiaryExport.php — render a set of diary items as JSON, Markdown, a clean
 * standalone HTML reader, or a print-ready "Journal" book (Bible-style: title
 * page, contents, chaptered with drop caps + justified serif, page breaks —
 * Print → Save as PDF in the browser).
 *
 * Works on a normalised item shape so it serves both the public Diary (articles
 * with HTML bodies) and a member's own entries (plain-text bodies):
 *   ['title'=>, 'subtitle'=>, 'date'=>, 'html'=>, 'slug'=>?]
 * $meta: ['title'=>, 'subtitle'=>, 'site'=>, 'count'=>, 'exported'=>]
 */
declare(strict_types=1);

final class DiaryExport
{
    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

    /** Plain text from HTML (entities decoded, tags stripped, whitespace tidied). */
    public static function htmlToText(string $html): string
    {
        $t = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html);
        $t = preg_replace('#<br\s*/?>#i', "\n", $t);
        $t = preg_replace('#</(p|div|h[1-6]|li|blockquote)>#i', "\n\n", $t);
        $t = strip_tags((string) $t);
        $t = html_entity_decode((string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/\n{3,}/", "\n\n", (string) $t);
        return trim((string) $t);
    }

    /** Light HTML → Markdown for the common tags the editor emits. */
    public static function htmlToMarkdown(string $html): string
    {
        $s = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html);
        $s = preg_replace('#\s*<h1[^>]*>(.*?)</h1>#is', "\n\n# $1\n", (string) $s);
        $s = preg_replace('#\s*<h2[^>]*>(.*?)</h2>#is', "\n\n## $1\n", (string) $s);
        $s = preg_replace('#\s*<h3[^>]*>(.*?)</h3>#is', "\n\n### $1\n", (string) $s);
        $s = preg_replace('#<(strong|b)[^>]*>(.*?)</\1>#is', '**$2**', (string) $s);
        $s = preg_replace('#<(em|i)[^>]*>(.*?)</\1>#is', '*$2*', (string) $s);
        $s = preg_replace('#<a[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', '[$2]($1)', (string) $s);
        $s = preg_replace('#\s*<li[^>]*>(.*?)</li>#is', "\n- $1", (string) $s);
        $s = preg_replace('#\s*<blockquote[^>]*>(.*?)</blockquote>#is', "\n\n> $1\n", (string) $s);
        $s = preg_replace('#<br\s*/?>#i', "  \n", (string) $s);
        $s = preg_replace('#\s*</p>\s*#i', "\n\n", (string) $s);
        $s = strip_tags((string) $s);
        $s = html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace("/[ \t]+\n/", "\n", (string) $s);
        $s = preg_replace("/\n{3,}/", "\n\n", (string) $s);
        return trim((string) $s);
    }

    public static function json(array $items, array $meta): string
    {
        return (string) json_encode([
            'title'       => $meta['title'] ?? 'Diary',
            'site'        => $meta['site'] ?? '',
            'exported_at' => $meta['exported'] ?? gmdate('c'),
            'count'       => count($items),
            'entries'     => array_map(static function (array $i): array {
                return [
                    'title'    => (string) ($i['title'] ?? ''),
                    'subtitle' => (string) ($i['subtitle'] ?? ''),
                    'date'     => (string) ($i['date'] ?? ''),
                    'slug'     => (string) ($i['slug'] ?? ''),
                    'html'     => (string) ($i['html'] ?? ''),
                    'text'     => self::htmlToText((string) ($i['html'] ?? '')),
                ];
            }, array_values($items)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function markdown(array $items, array $meta): string
    {
        $out = '# ' . ($meta['title'] ?? 'Diary') . "\n";
        if (!empty($meta['subtitle'])) $out .= '_' . $meta['subtitle'] . "_\n";
        $out .= '_Exported ' . ($meta['exported'] ?? gmdate('Y-m-d')) . ' · ' . count($items) . " entries_\n";
        foreach ($items as $i) {
            $out .= "\n\n---\n\n## " . (string) ($i['title'] ?? '') . "\n";
            $sub = trim(((string) ($i['subtitle'] ?? '')) . (($i['date'] ?? '') ? ' · ' . $i['date'] : ''), ' ·');
            if ($sub !== '') $out .= '*' . $sub . "*\n\n";
            $out .= self::htmlToMarkdown((string) ($i['html'] ?? '')) . "\n";
        }
        return $out . "\n";
    }

    /** A clean, self-contained HTML reader (also fine to print). */
    public static function html(array $items, array $meta): string
    {
        $body = '';
        foreach ($items as $i) {
            $sub = trim(((string) ($i['subtitle'] ?? '')) . (($i['date'] ?? '') ? ' · ' . $i['date'] : ''), ' ·');
            $body .= '<article><h2>' . self::e((string) ($i['title'] ?? '')) . '</h2>'
                . ($sub !== '' ? '<p class="meta">' . self::e($sub) . '</p>' : '')
                . '<div class="body">' . ($i['html'] ?? '') . '</div></article>';
        }
        return self::shell((string) ($meta['title'] ?? 'Diary'), self::readerCss(),
            '<header class="doc-head"><h1>' . self::e((string) ($meta['title'] ?? 'Diary')) . '</h1>'
            . (!empty($meta['subtitle']) ? '<p>' . self::e((string) $meta['subtitle']) . '</p>' : '')
            . '<p class="muted">' . count($items) . ' entries · exported ' . self::e((string) ($meta['exported'] ?? gmdate('Y-m-d'))) . '</p></header>'
            . $body);
    }

    /** The Journal — a print-ready book (title page, contents, chapters). */
    public static function book(array $items, array $meta): string
    {
        $title = (string) ($meta['title'] ?? 'The Afrovanguard Diary');
        $toc = ''; $chapters = ''; $n = 0;
        foreach ($items as $i) {
            $n++;
            $id = 'ch' . $n;
            $ttl = self::e((string) ($i['title'] ?? ''));
            $sub = trim(((string) ($i['subtitle'] ?? '')) . (($i['date'] ?? '') ? ' · ' . $i['date'] : ''), ' ·');
            $toc .= '<li><a href="#' . $id . '"><span class="t">' . $ttl . '</span><span class="d">' . self::e((string) ($i['date'] ?? '')) . '</span></a></li>';
            $chapters .= '<section class="chapter" id="' . $id . '">'
                . '<p class="ch-num">' . self::roman($n) . '</p>'
                . '<h2>' . $ttl . '</h2>'
                . ($sub !== '' ? '<p class="ch-sub">' . self::e($sub) . '</p>' : '')
                . '<div class="ch-body">' . ($i['html'] ?? '') . '</div></section>';
        }
        $titlePage = '<section class="title-page">'
            . '<div class="tp-mark">AFROVANGUARD<span>.</span></div>'
            . '<h1>' . self::e($title) . '</h1>'
            . (!empty($meta['subtitle']) ? '<p class="tp-sub">' . self::e((string) $meta['subtitle']) . '</p>' : '')
            . '<p class="tp-meta">' . count($items) . ' entries · ' . self::e((string) ($meta['exported'] ?? gmdate('F j, Y'))) . '</p>'
            . '<div class="tp-rule"></div></section>';
        $tocPage = '<section class="toc"><h2>Contents</h2><ol>' . $toc . '</ol></section>';
        return self::shell($title, self::bookCss(),
            '<div class="print-hint">Tip: use your browser’s <b>Print → Save as PDF</b> for a bound copy.</div>'
            . $titlePage . $tocPage . $chapters);
    }

    private static function roman(int $n): string
    {
        $map = [1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'];
        $r = ''; foreach ($map as $v => $s) { while ($n >= $v) { $r .= $s; $n -= $v; } }
        return $r;
    }

    private static function shell(string $title, string $css, string $body): string
    {
        return "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"UTF-8\">"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($title) . '</title><style>' . $css . '</style></head><body>'
            . $body . '</body></html>';
    }

    private static function readerCss(): string
    {
        return 'body{font-family:Georgia,"Times New Roman",serif;max-width:720px;margin:0 auto;padding:48px 24px;color:#1a1a1a;line-height:1.7;}'
            . '.doc-head{text-align:center;border-bottom:3px solid #f3b416;padding-bottom:24px;margin-bottom:32px;}'
            . '.doc-head h1{font-size:34px;margin:0 0 8px;}.muted{color:#777;font-size:14px;}'
            . 'article{margin:0 0 40px;padding-bottom:32px;border-bottom:1px solid #eee;}'
            . 'article h2{font-size:26px;margin:0 0 6px;}.meta{color:#8d480e;font-size:14px;font-style:italic;margin:0 0 16px;}'
            . '.body img{max-width:100%;height:auto;}.body{font-size:17px;}a{color:#b45309;}';
    }

    private static function bookCss(): string
    {
        return '@page{margin:22mm 20mm;}'
            . 'body{font-family:"Cormorant Garamond",Georgia,"Times New Roman",serif;color:#171717;margin:0;background:#f6f4ee;}'
            . '.print-hint{background:#0d1220;color:#f3b416;text-align:center;font-family:system-ui,sans-serif;font-size:13px;padding:8px;}'
            . '@media print{.print-hint{display:none;}body{background:#fff;}}'
            . 'section{max-width:720px;margin:0 auto;background:#fff;padding:56px 60px;}'
            . '@media screen{section{margin:18px auto;box-shadow:0 8px 30px -16px rgba(0,0,0,.4);}}'
            . '.title-page{text-align:center;min-height:80vh;display:flex;flex-direction:column;justify-content:center;}'
            . '.tp-mark{font-family:system-ui,sans-serif;letter-spacing:3px;font-weight:800;color:#0d1220;font-size:15px;}'
            . '.tp-mark span{color:#f3b416;}.title-page h1{font-size:46px;line-height:1.1;margin:18px 0;}'
            . '.tp-sub{font-size:20px;font-style:italic;color:#555;margin:0 0 8px;}.tp-meta{font-family:system-ui,sans-serif;font-size:13px;color:#888;}'
            . '.tp-rule{width:80px;height:4px;background:#f3b416;margin:28px auto 0;}'
            . '.toc h2,.chapter h2{font-size:30px;margin:0 0 18px;}'
            . '.toc ol{list-style:none;padding:0;margin:0;}.toc li{margin:0 0 10px;}'
            . '.toc a{display:flex;justify-content:space-between;gap:14px;text-decoration:none;color:#222;border-bottom:1px dotted #ccc;padding-bottom:6px;}'
            . '.toc .d{color:#999;font-family:system-ui,sans-serif;font-size:13px;white-space:nowrap;}'
            . '.chapter{page-break-before:always;}'
            . '.ch-num{text-align:center;font-family:system-ui,sans-serif;letter-spacing:3px;color:#f3b416;font-weight:700;margin:0 0 4px;}'
            . '.ch-sub{font-style:italic;color:#8d480e;margin:0 0 22px;}'
            . '.ch-body{font-size:18px;line-height:1.75;text-align:justify;hyphens:auto;}'
            . '.ch-body p:first-of-type::first-letter{font-size:54px;float:left;line-height:.8;padding:6px 8px 0 0;color:#0d1220;font-weight:600;}'
            . '.ch-body img{max-width:100%;height:auto;}.ch-body a{color:#b45309;text-decoration:none;}';
    }
}
