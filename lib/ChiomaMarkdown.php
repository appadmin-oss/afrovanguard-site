<?php
/**
 * lib/ChiomaMarkdown.php — Chioma's words, rendered safely.
 *
 * A language model writes Markdown whether or not you ask it to: lists, bold,
 * links, the occasional heading. The widget used to render that with a regex
 * that escaped the text and then linkified anything beginning with a slash,
 * which got bold and lists wrong and, more to the point, put the safety of every
 * reply in a client-side replace() chain.
 *
 * So the rendering moved here, onto league/commonmark, and the widget receives
 * HTML that is already safe. Three settings do the actual work:
 *
 *   html_input: 'strip'      A model can be talked into emitting raw HTML — by a
 *                            visitor, or by text on a page it fetched. Stripping
 *                            tags at the parser means a <script> in a reply is
 *                            never markup in the first place, rather than
 *                            something a sanitiser downstream has to catch.
 *   allow_unsafe_links       Off, so javascript: and data: URIs do not survive.
 *   max_nesting_level        A bound, so a pathological reply cannot spend the
 *                            request in the parser.
 *
 * After parsing, links are post-processed: external ones get rel="noopener
 * noreferrer" and open in a new tab, internal ones stay in place. Images are
 * dropped entirely — Chioma has no reason to embed one, and an <img> from a
 * fetched page is a tracking pixel with extra steps.
 *
 * If the library is missing (a tree deployed without vendor/), render() falls
 * back to escaped plain text with line breaks. Degraded, never unsafe.
 */
declare(strict_types=1);

final class ChiomaMarkdown
{
    private static ?object $converter = null;

    /** True when the real renderer is available. */
    public static function available(): bool
    {
        return class_exists(\League\CommonMark\MarkdownConverter::class);
    }

    /** Markdown → safe HTML for the chat bubble. */
    public static function render(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') return '';
        if (!self::available()) return self::plain($markdown);

        try {
            if (self::$converter === null) {
                $env = new \League\CommonMark\Environment\Environment([
                    'html_input'         => 'strip',
                    'allow_unsafe_links' => false,
                    'max_nesting_level'  => 8,
                ]);
                $env->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());
                // Chioma writes "see https://…" far more often than she writes a
                // Markdown link, and an unlinked URL in a chat bubble is a URL
                // the visitor has to select and copy.
                $env->addExtension(new \League\CommonMark\Extension\Autolink\AutolinkExtension());
                self::$converter = new \League\CommonMark\MarkdownConverter($env);
            }
            $html = (string) self::$converter->convert($markdown);
        } catch (\Throwable $e) {
            error_log('[chioma-md] ' . $e->getMessage());
            return self::plain($markdown);
        }

        return self::postProcess($html);
    }

    /**
     * Mark external links and drop images.
     *
     * Done with DOMDocument rather than a regex: the input here is parser output,
     * so it is well-formed, and a regex over anchors is the kind of thing that
     * works until a title attribute contains a '>'.
     */
    private static function postProcess(string $html): string
    {
        if ($html === '' || !class_exists('DOMDocument')) return $html;
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // The meta forces UTF-8; without it DOMDocument assumes Latin-1 and every
        // accented character and emoji in a reply comes back mangled.
        $ok = $doc->loadHTML('<?xml encoding="UTF-8"><div id="ch-md-root">' . $html . '</div>',
                             LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) return $html;

        foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
            $img->parentNode?->removeChild($img);
        }
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = (string) $a->getAttribute('href');
            if ($href === '') continue;
            $isExternal = (bool) preg_match('~^[a-z][a-z0-9+.\-]*://~i', $href);
            if ($isExternal) {
                $a->setAttribute('target', '_blank');
                $a->setAttribute('rel', 'noopener noreferrer');
            }
        }
        $root = $doc->getElementById('ch-md-root');
        if (!$root) return $html;
        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return trim($out);
    }

    /** Escaped plain text with paragraph breaks — the no-library floor. */
    private static function plain(string $text): string
    {
        $esc = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $paras = preg_split('/\n{2,}/', $esc) ?: [$esc];
        $out = '';
        foreach ($paras as $p) {
            $p = trim($p);
            if ($p !== '') $out .= '<p>' . nl2br($p, false) . '</p>';
        }
        return $out;
    }
}
