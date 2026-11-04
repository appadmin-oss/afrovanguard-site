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
    /**
     * A URL that may be an iframe's src: https, on a host we embed, nothing
     * else. A lesson's video_url used to go into the frame as typed, so
     * `javascript:` ran in the learner's page and any site could be framed
     * inside ours. Returns null for anything that is not one.
     */
    public static function iframeSrc(string $url): ?string
    {
        $url = trim($url, "\x00..\x20\x7f");
        if ($url === '' || preg_match('/[\x00-\x1f\x7f]/', $url)) return null;
        // A page link to a video is turned into its player, as a pasted link in an article is.
        if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $url, $m)) return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        if (preg_match('~^https?://(?:www\.)?vimeo\.com/(?:video/)?(\d+)~', $url, $m)) return 'https://player.vimeo.com/video/' . $m[1];
        if ($p = self::providerPlayer($url)) return $p;
        $p = parse_url($url);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https') return null;
        $host = strtolower((string) ($p['host'] ?? ''));
        return $host !== '' && in_array($host, av_embed_hosts(), true) ? $url : null;
    }

    /**
     * The player URL for a share link from a course-media provider, or null.
     * Loom and Wistia share pages, a Google Drive file, Google Slides/Docs and
     * an Office document (through Microsoft's viewer) each become the URL their
     * own embed uses — always rebuilt from the id, never the pasted URL.
     */
    public static function providerPlayer(string $url): ?string
    {
        if (preg_match('~^https://(?:www\.)?loom\.com/(?:share|embed)/([a-f0-9]{16,40})~i', $url, $m)) return 'https://www.loom.com/embed/' . strtolower($m[1]);
        if (preg_match('~^https://[a-z0-9-]+\.wistia\.(?:com|net)/(?:medias|embed/iframe)/([a-z0-9]{6,20})~i', $url, $m)) return 'https://fast.wistia.net/embed/iframe/' . $m[1];
        if (preg_match('~^https://drive\.google\.com/file/d/([A-Za-z0-9_-]{10,})~', $url, $m)) return 'https://drive.google.com/file/d/' . $m[1] . '/preview';
        if (preg_match('~^https://docs\.google\.com/presentation/d/([A-Za-z0-9_-]{10,})~', $url, $m)) return 'https://docs.google.com/presentation/d/' . $m[1] . '/embed';
        if (preg_match('~^https://docs\.google\.com/(document|spreadsheets)/d/([A-Za-z0-9_-]{10,})~', $url, $m)) return 'https://docs.google.com/' . $m[1] . '/d/' . $m[2] . '/preview';
        if (preg_match('~^https://[^\s?#]+\.(?:pptx?|docx?|xlsx?)(?:[?#]|$)~i', $url) && filter_var($url, FILTER_VALIDATE_URL))
            return 'https://view.officeapps.live.com/op/embed.aspx?src=' . rawurlencode($url);
        return null;
    }

    /**
     * A video FILE a <video> element may play: an upload of ours (/uploads/…),
     * a Cloudinary video, or any https URL ending in a video extension.
     * Returns the URL to use, or null.
     */
    public static function videoFile(string $url): ?string
    {
        $url = trim($url, "\x00..\x20\x7f");
        if ($url === '' || preg_match('/[\x00-\x1f\x7f\s"\'<>]/', $url)) return null;
        if (preg_match('~^/uploads/[a-z0-9_-]+/[A-Za-z0-9._-]+\.(?:mp4|webm|mov|m4v|ogv)$~', $url)) return $url;
        $p = parse_url($url);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host'])) return null;
        if (strtolower((string) $p['host']) === 'res.cloudinary.com' && str_contains((string) ($p['path'] ?? ''), '/video/upload/')) return $url;
        return preg_match('~\.(?:mp4|webm|mov|m4v|ogv)$~i', (string) ($p['path'] ?? '')) ? $url : null;
    }

    /** A WebVTT captions URL: ours, or https ending in .vtt. */
    public static function captionsFile(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f"\'<>]/', $url)) return null;
        if (preg_match('~^/uploads/[a-z0-9_-]+/[A-Za-z0-9._-]+\.vtt$~', $url)) return $url;
        $p = parse_url($url);
        return is_array($p) && strtolower((string) ($p['scheme'] ?? '')) === 'https' && !empty($p['host'])
            && preg_match('~\.vtt$~i', (string) ($p['path'] ?? '')) ? $url : null;
    }

    /**
     * A lesson's video, resolved once: ['kind' => 'file'|'frame', 'src' => …],
     * or null when the URL is neither a playable file nor an embed we allow.
     * The one reader the Studio's save and the player both use.
     */
    public static function lessonVideo(string $url): ?array
    {
        if (($f = self::videoFile($url)) !== null) return ['kind' => 'file', 'src' => $f];
        if (($i = self::iframeSrc($url)) !== null) return ['kind' => 'frame', 'src' => $i];
        return null;
    }

    /** A lesson resource's link: https/http, or one of our uploads. */
    public static function resourceUrl(string $url): ?string
    {
        $url = trim($url, "\x00..\x20\x7f");
        if ($url === '' || preg_match('/[\x00-\x1f\x7f]/', $url)) return null;
        if (preg_match('~^/uploads/[a-z0-9_-]+/[A-Za-z0-9._-]+$~', $url)) return $url;
        $p = parse_url($url);
        return is_array($p) && in_array(strtolower((string) ($p['scheme'] ?? '')), ['https', 'http'], true) && !empty($p['host'])
            && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

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
        // Loom, Wistia, Google Drive / Slides / Docs, Office documents
        if ($pp = self::providerPlayer($u)) {
            $ratio = str_contains($pp, 'officeapps') || str_contains($pp, 'docs.google.com/document') || str_contains($pp, '/spreadsheets/') ? 'doc' : '16x9';
            return self::wrap('<iframe class="embed-frame" src="' . htmlspecialchars($pp, ENT_QUOTES) . '" title="Embedded media" loading="lazy" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>', $ratio);
        }
        // A video file on its own line plays inline
        if ($vf = self::videoFile($u)) {
            return self::wrap('<video class="embed-video" src="' . htmlspecialchars($vf, ENT_QUOTES) . '" controls preload="metadata" playsinline></video>', '16x9');
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
        'h2','h3','h4','figure','figcaption','img','iframe','hr','code','pre','video','audio','source','track',
        'table','thead','tbody','tr','th','td','span','div',
    ];
    private const ALLOWED_ATTR = [
        'a' => ['href','title','target','rel'],
        'img' => ['src','alt','width','height','loading','class'],
        'iframe' => ['src','title','width','height','allow','allowfullscreen','loading','style','frameborder','scrolling'],
        'figure' => ['class'], 'figcaption' => [], 'span' => ['class'], 'div' => ['class'],
        'video' => ['src','controls','preload','playsinline','poster','class','width','height'],
        'audio' => ['src','controls','preload','class'],
        'source' => ['src','type'], 'track' => ['src','kind','srclang','label','default'],
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
                    /* Read the URL as a browser does: leading spaces and control
                       characters are stripped and a tab or newline inside the
                       scheme is dropped, so " javascript:" and "java\tscript:"
                       both run. parse_url() sees no scheme in either. */
                    $val = trim($val, "\x00..\x20\x7f");
                    $child->setAttribute($attr->name, $val);
                    $probe = preg_replace('/[\x00-\x20\x7f]+/', '', $val);
                    $scheme = preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $sm) ? strtolower($sm[1]) : '';
                    if ($name === 'src' && in_array($tag, ['video', 'source'], true) && self::videoFile($val) === null && !preg_match('~^/uploads/~', $val) && $scheme !== 'https') {
                        $child->removeAttribute($attr->name); continue;
                    }
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
