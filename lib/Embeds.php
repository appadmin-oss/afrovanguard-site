<?php
/**
 * lib/Embeds.php — turn pasted URLs / raw iframes into branded, responsive
 * embeds, and sanitize editor HTML so stored content is XSS-safe while still
 * allowing rich media from trusted providers.
 *
 * Pipeline used on save:  embedify()  →  sanitize_html()
 */
declare(strict_types=1);

final class Embeds
{
    /** Convert provider URLs (on their own line / in <p>) into iframes. */
    public static function embedify(string $html): string
    {
        // Standalone URLs inside their own <p>…</p>
        $html = preg_replace_callback(
            '~<p>\s*(https?://[^\s<]+)\s*</p>~i',
            function ($m) {
                $frag = self::fromUrl(html_entity_decode($m[1]));
                return $frag ?: $m[0];
            },
            $html
        );
        // Bare <oembed url="…"> (TinyMCE) → iframe
        $html = preg_replace_callback(
            '~<oembed[^>]*url="([^"]+)"[^>]*>\s*</oembed>~i',
            fn($m) => self::fromUrl(html_entity_decode($m[1])) ?: '',
            $html
        );
        // Wrap any bare provider <iframe> not already wrapped in .embed
        $html = preg_replace_callback(
            '~(?<!class="embed-frame")<iframe\b[^>]*\bsrc="([^"]+)"[^>]*></iframe>~i',
            function ($m) {
                $host = parse_url(html_entity_decode($m[1]), PHP_URL_HOST) ?: '';
                if (!in_array(strtolower($host), av_embed_hosts(), true)) return $m[0];
                return self::wrap($m[0], self::ratioFor($host));
            },
            $html
        );
        return $html;
    }

    /** Build a branded embed from a known provider URL. Returns '' if unknown. */
    public static function fromUrl(string $url): string
    {
        $u = trim($url);
        // YouTube
        if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)([A-Za-z0-9_-]{6,})~', $u, $m)) {
            return self::wrap('<iframe class="embed-frame" src="https://www.youtube-nocookie.com/embed/' . $m[1] . '" title="YouTube video" loading="lazy" allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>', '16x9');
        }
        // Vimeo
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $u, $m)) {
            return self::wrap('<iframe class="embed-frame" src="https://player.vimeo.com/video/' . $m[1] . '" title="Vimeo video" loading="lazy" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>', '16x9');
        }
        // Spotify
        if (preg_match('~open\.spotify\.com/(track|album|playlist|episode|show)/([A-Za-z0-9]+)~', $u, $m)) {
            $h = $m[1] === 'track' ? 152 : 352;
            return self::wrap('<iframe class="embed-frame" style="height:' . $h . 'px" src="https://open.spotify.com/embed/' . $m[1] . '/' . $m[2] . '" title="Spotify" loading="lazy" allow="encrypted-media"></iframe>', 'fixed');
        }
        // SoundCloud
        if (preg_match('~soundcloud\.com/~', $u)) {
            return self::wrap('<iframe class="embed-frame" style="height:166px" src="https://w.soundcloud.com/player/?url=' . rawurlencode($u) . '&color=%23f3b416" title="SoundCloud" loading="lazy"></iframe>', 'fixed');
        }
        // Google Maps
        if (preg_match('~(?:google\.[a-z.]+/maps|maps\.google\.)~', $u)) {
            return self::wrap('<iframe class="embed-frame" src="' . htmlspecialchars($u, ENT_QUOTES) . '" title="Map" loading="lazy"></iframe>', '16x9');
        }
        // Twitter / X, Instagram, TikTok → blockquote that their JS upgrades,
        // but provide a branded link card fallback so it always renders.
        if (preg_match('~(?:twitter\.com|x\.com)/\w+/status/(\d+)~', $u)) {
            return self::linkCard($u, 'View this post on X', 'X / Twitter');
        }
        if (preg_match('~instagram\.com/(p|reel)/~', $u)) {
            return self::linkCard($u, 'View this post on Instagram', 'Instagram');
        }
        if (preg_match('~tiktok\.com/~', $u)) {
            return self::linkCard($u, 'Watch on TikTok', 'TikTok');
        }
        // Generic allow-listed iframe-able page (Canva, Flourish, Datawrapper, Docs…)
        $host = strtolower(parse_url($u, PHP_URL_HOST) ?: '');
        if ($host && in_array($host, av_embed_hosts(), true)) {
            return self::wrap('<iframe class="embed-frame" src="' . htmlspecialchars($u, ENT_QUOTES) . '" title="Embedded content" loading="lazy" allowfullscreen></iframe>', '16x9');
        }
        return '';
    }

    private static function ratioFor(string $host): string
    {
        if (str_contains($host, 'spotify') || str_contains($host, 'soundcloud')) return 'fixed';
        return '16x9';
    }

    private static function wrap(string $inner, string $ratio): string
    {
        return '<figure class="embed embed-' . $ratio . '">' . $inner . '</figure>';
    }

    private static function linkCard(string $url, string $label, string $provider): string
    {
        $safe = htmlspecialchars($url, ENT_QUOTES);
        return '<figure class="embed embed-card"><a class="embed-card-link" href="' . $safe . '" target="_blank" rel="noopener">'
            . '<span class="embed-card-provider">' . htmlspecialchars($provider) . '</span>'
            . '<span class="embed-card-label">' . htmlspecialchars($label) . ' ↗</span></a></figure>';
    }

    /* ── Sanitizer ─────────────────────────────────────────────── */
    private const ALLOWED = [
        'p','br','strong','em','b','i','u','s','a','ul','ol','li','blockquote','cite',
        'h2','h3','h4','figure','figcaption','img','iframe','hr','code','pre',
        'table','thead','tbody','tr','th','td','span','div',
    ];
    private const ALLOWED_ATTR = [
        'a' => ['href','title','target','rel'],
        'img' => ['src','alt','width','height','loading','class'],
        'iframe' => ['src','title','width','height','allow','allowfullscreen','loading','style','frameborder','scrolling'],
        'figure' => ['class'], 'figcaption' => [], 'span' => ['class'], 'div' => ['class'],
        'blockquote' => ['class','cite'], 'code' => ['class'], 'pre' => ['class'],
        'th' => ['colspan','rowspan'], 'td' => ['colspan','rowspan'],
    ];

    public static function sanitize(string $html): string
    {
        if (trim($html) === '') return '';
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $root = $doc->getElementById('__root');
        if ($root) self::clean($root);
        $out = '';
        if ($root) foreach ($root->childNodes as $n) { $out .= $doc->saveHTML($n); }
        return $out;
    }

    private static function clean(DOMNode $node): void
    {
        // iterate over a static copy because we mutate the tree
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) { $child->parentNode->removeChild($child); continue; }
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
            /** @var DOMElement $child */
            $tag = strtolower($child->tagName);
            if (!in_array($tag, self::ALLOWED, true)) {
                // unwrap unknown tags (keep their text/children), drop dangerous ones
                if (in_array($tag, ['script','style','object','embed','link','meta','form','input','svg'], true)) {
                    $child->parentNode->removeChild($child); continue;
                }
                self::clean($child);
                while ($child->firstChild) { $child->parentNode->insertBefore($child->firstChild, $child); }
                $child->parentNode->removeChild($child); continue;
            }
            // scrub attributes
            $allowed = self::ALLOWED_ATTR[$tag] ?? [];
            foreach (iterator_to_array($child->attributes ?? []) as $attr) {
                $name = strtolower($attr->name); $val = $attr->value;
                if (str_starts_with($name, 'on') || !in_array($name, $allowed, true)) { $child->removeAttribute($attr->name); continue; }
                if (in_array($name, ['href','src'], true)) {
                    $scheme = strtolower((string) parse_url($val, PHP_URL_SCHEME));
                    if ($name === 'src' && $tag === 'iframe') {
                        $host = strtolower((string) parse_url($val, PHP_URL_HOST));
                        if (!in_array($host, av_embed_hosts(), true)) { $child->parentNode->removeChild($child); continue 2; }
                    } elseif ($scheme !== '' && !in_array($scheme, ['http','https','mailto','tel'], true)) {
                        $child->removeAttribute($attr->name); continue;
                    }
                }
            }
            if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                $child->setAttribute('rel', 'noopener');
            }
            self::clean($child);
        }
    }
}
