<?php
/**
 * lib/SitePages.php — the Studio's Pages editor (Content → Pages).
 *
 * Every public page's words, links and pictures, edited like a CMS: draft,
 * preview, publish, history. The pages stay what they are — static HTML or a
 * PHP template — because the host has no build step and no renderer to hand
 * a database-driven page to. What the Studio may change is marked in the page
 * source with data-cms="key" (tools/cms-annotate.php adds the marks; a mark
 * is only ever put on an element whose content is plain words, or words with
 * <em>/<strong>/<br>, so the first closing tag after it is its own).
 *
 * Publishing rewrites only the marked elements, atomically (temp file and
 * rename), and only the ones whose value changed: a page published with no
 * edits is byte-identical. Every value is escaped on the way in, and a write
 * that would change how many PHP tags a file holds is refused, so an edit can
 * never become code.
 *
 * Storage: app_meta 'site_page:<file>' = {"draft":{key:value},"hist":[…]}.
 */
declare(strict_types=1);

final class SitePages
{
    /** file (relative to the site root) => the name the Studio shows. One list; the annotator, the editor and the tests read it. */
    public const PAGES = [
        'index.html'              => 'Home',
        'about.html'              => 'About',
        'how-it-works.php'        => 'How it works',
        'ethos/index.php'         => 'Ethos',
        'contact.html'            => 'Contact',
        'donate.html'             => 'Donate',
        'privacy-policy/index.php' => 'Privacy policy',
        'terms/index.php'         => 'Terms',
    ];
    public const META = 'site_page:';
    public const HISTORY_MAX = 10;
    public const TEXT_MAX = 2000;
    /** Elements the annotator may mark. */
    public const TEXT_TAGS = ['h1', 'h2', 'h3', 'h4', 'p', 'li', 'figcaption', 'blockquote', 'dt', 'dd'];
    /** Inline tags a rich field may keep. Nothing else survives, and none keep attributes. */
    public const RICH_TAGS = ['em', 'strong', 'b', 'i', 'br'];

    public static ?string $root = null;
    public static function root(): string { return self::$root ?? AV_ROOT; }

    public static function known(string $file): bool { return isset(self::PAGES[$file]); }
    public static function path(string $file): string { return self::root() . '/' . $file; }
    /** The address a visitor opens. */
    public static function href(string $file): string
    {
        if ($file === 'index.html') return '/';
        if (str_ends_with($file, '/index.php') || str_ends_with($file, '/index.html')) return '/' . dirname($file) . '/';
        return '/' . preg_replace('/\.php$/', '', $file);
    }

    /* ── Reading the marks ───────────────────────────────────────────── */

    /**
     * Every marked element in $html, in document order:
     * [key, tag, type (text|rich|link|image), open (offset), openLen, innerStart, innerEnd, value…]
     */
    public static function scan(string $html): array
    {
        $out = [];
        if (!preg_match_all('~<([a-z][a-z0-9]*)\b[^<>]*?\sdata-cms="([a-z0-9-]+)"[^<>]*>~i', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $out;
        foreach ($m as $hit) {
            [$openTag, $at] = $hit[0];
            $tag = strtolower($hit[1][0]); $key = $hit[2][0];
            $f = ['key' => $key, 'tag' => $tag, 'open' => $at, 'openLen' => strlen($openTag), 'openTag' => $openTag];
            if ($tag === 'img') {
                $f['type'] = 'image';
                $f['src'] = self::attr($openTag, 'src'); $f['alt'] = self::attr($openTag, 'alt');
                // Kept so a publish can be undone exactly (a picture's size goes with it).
                foreach (['width', 'height', 'srcset', 'sizes'] as $x) $f[$x] = self::attr($openTag, $x);
            } else {
                $close = stripos($html, '</' . $tag . '>', $at + strlen($openTag));
                if ($close === false) continue;
                $inner = substr($html, $at + strlen($openTag), $close - $at - strlen($openTag));
                $f['innerStart'] = $at + strlen($openTag); $f['innerEnd'] = $close;
                $f['type'] = $tag === 'a' ? 'link' : (preg_match('/<[a-z]/i', $inner) ? 'rich' : 'text');
                $f['value'] = $f['type'] === 'rich' ? self::rich($inner) : self::plain($inner);
                if ($tag === 'a') $f['href'] = self::attr($openTag, 'href');
            }
            $out[] = $f;
        }
        return $out;
    }

    private static function attr(string $tag, string $name): string
    {
        return preg_match('~\s' . preg_quote($name, '~') . '="([^"]*)"~i', $tag, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }

    private static function plain(string $inner): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($inner, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    /** Keep only <em>, <strong>, <b>, <i>, <br>, without attributes; everything else is words. */
    public static function rich(string $html): string
    {
        $html = preg_replace('/\s+/u', ' ', $html) ?? '';
        $parts = preg_split('~(<[^>]*>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = ''; $open = [];
        foreach ($parts as $p) {
            if ($p === '') continue;
            if ($p[0] !== '<') { $out .= htmlspecialchars(html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'); continue; }
            if (!preg_match('~^<(/?)([a-z]+)\b~i', $p, $t)) continue;
            $name = strtolower($t[2]);
            if (!in_array($name, self::RICH_TAGS, true)) continue;
            if ($name === 'br') { $out .= '<br>'; continue; }
            if ($t[1] === '') { $out .= '<' . $name . '>'; $open[] = $name; continue; }
            $i = array_search($name, array_reverse($open, true), true);
            if ($i === false) continue;
            while ($open) { $x = array_pop($open); $out .= '</' . $x . '>'; if ($x === $name) break; }
        }
        while ($open) $out .= '</' . array_pop($open) . '>';
        return trim($out);
    }

    /** Where each field sits, for the editor: the nearest heading above it (h1–h2), else "Top of the page". */
    public static function fields(string $file): array
    {
        $html = (string) @file_get_contents(self::path($file));
        $marks = self::scan($html);
        $heads = [];
        if (preg_match_all('~<h([12])\b[^>]*>(.*?)</h\1>~is', $html, $hm, PREG_OFFSET_CAPTURE)) {
            foreach ($hm[0] as $i => $h) $heads[] = [$h[1], trim(strip_tags(html_entity_decode($hm[2][$i][0], ENT_QUOTES | ENT_HTML5, 'UTF-8')))];
        }
        $out = []; $had = [];
        foreach ($marks as $f) {
            if (isset($had[$f['key']])) continue;   // a visual copy of a field already listed
            $had[$f['key']] = true;
            $group = 'Top of the page';
            foreach ($heads as [$at, $t]) { if ($at <= $f['open'] && $t !== '') $group = $t; }
            $row = ['key' => $f['key'], 'tag' => $f['tag'], 'type' => $f['type'], 'group' => $group];
            if ($f['type'] === 'image') { $row['src'] = $f['src']; $row['alt'] = $f['alt']; }
            else { $row['value'] = $f['value']; if ($f['type'] === 'link') $row['href'] = $f['href']; }
            $out[] = $row;
        }
        return $out;
    }

    /** <title>, the meta description and the share picture, when the page states them literally. */
    public static function seo(string $html): array
    {
        $s = [];
        if (preg_match('~<title>([^<]*)</title>~i', $html, $m) && !str_contains($m[1], '<?')) $s['title'] = self::plain($m[1]);
        if (preg_match('~<meta\s+name="description"\s+content="([^"]*)"~i', $html, $m)) $s['description'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('~<meta\s+property="og:image"\s+content="([^"]*)"~i', $html, $m)) $s['image'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $s;
    }

    /* ── Validating a draft ──────────────────────────────────────────── */

    /** A link the page may carry: a site path, #anchor, https, mailto: or tel:. */
    public static function link(string $u): string
    {
        $u = trim($u);
        if (preg_match('~^mailto:[^\s"<>]+@[^\s"<>]+$~i', $u) || preg_match('~^tel:\+?[0-9 ()-]{5,20}$~', $u)) return $u;
        return SiteHome::url($u);
    }

    /**
     * Keep the parts of $in that name a real field of $file, cleaned.
     * Shape: {"<key>": {"value"?, "href"?, "src"?, "alt"?}, "seo": {"title"?, "description"?, "image"?}}
     * @return array{ok:bool, draft?:array, error?:string}
     */
    public static function clean(string $file, array $in): array
    {
        if (!self::known($file)) return ['ok' => false, 'error' => 'That page is not one the Studio edits.'];
        $byKey = [];
        foreach (self::fields($file) as $f) $byKey[$f['key']] = $f;
        $out = [];
        foreach ($in as $key => $v) {
            if (!is_array($v)) continue;
            if ($key === 'seo') {
                $s = [];
                if (isset($v['title'])) { $t = self::plain((string) $v['title']); if ($t === '') return ['ok' => false, 'error' => 'A page needs a title.']; $s['title'] = mb_substr($t, 0, 120); }
                if (isset($v['description'])) $s['description'] = mb_substr(self::plain((string) $v['description']), 0, 300);
                if (isset($v['image'])) {
                    $img = SiteHome::url((string) $v['image'], true);
                    if ($img === '' && trim((string) $v['image']) !== '') return ['ok' => false, 'error' => 'The share picture must be a picture on this site or an https address.'];
                    if ($img !== '') $s['image'] = $img;
                }
                if ($s) $out['seo'] = $s;
                continue;
            }
            $f = $byKey[$key] ?? null;
            if (!$f) continue;   // a field that has gone from the page: drop it, don't fail
            $c = [];
            if ($f['type'] === 'image') {
                if (isset($v['src'])) {
                    $src = SiteHome::url((string) $v['src'], true);
                    if ($src === '') return ['ok' => false, 'error' => 'A picture must come from the library, this site, or an https address.'];
                    $c['src'] = $src;
                }
                if (isset($v['alt'])) $c['alt'] = mb_substr(self::plain((string) $v['alt']), 0, 300);
                // Size attributes only travel with a version being put back.
                foreach (['width', 'height'] as $x) if (isset($v[$x]) && preg_match('/^\d{0,5}$/', (string) $v[$x])) $c[$x] = (string) $v[$x];
                if (isset($v['srcset'])) {
                    $ok = true;
                    foreach (array_filter(array_map('trim', explode(',', (string) $v['srcset']))) as $cand) {
                        if (!preg_match('~^(\S+)(\s+\d+(\.\d+)?[wx])?$~', $cand, $cm) || SiteHome::url($cm[1], true) === '') $ok = false;
                    }
                    if ($ok) $c['srcset'] = trim((string) $v['srcset']);
                }
                if (isset($v['sizes']) && preg_match('~^[a-z0-9 (),:.%-]*$~i', (string) $v['sizes'])) $c['sizes'] = (string) $v['sizes'];
            } else {
                if (isset($v['value'])) {
                    $val = $f['type'] === 'rich' ? self::rich((string) $v['value']) : self::plain((string) $v['value']);
                    if (trim(strip_tags($val)) === '') return ['ok' => false, 'error' => 'A piece of text was left empty (' . $f['group'] . '). Write something, or undo the change.'];
                    $c['value'] = mb_substr($val, 0, self::TEXT_MAX);
                }
                if ($f['type'] === 'link' && isset($v['href'])) {
                    $h = self::link((string) $v['href']);
                    if ($h === '') return ['ok' => false, 'error' => 'A link must go to a page on this site, an https address, an email (mailto:) or a phone number (tel:).'];
                    $c['href'] = $h;
                }
            }
            if ($c) $out[$key] = $c;
        }
        return ['ok' => true, 'draft' => $out];
    }

    /* ── Applying values to a page ───────────────────────────────────── */

    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private static function setAttr(string $tag, string $name, string $value): string
    {
        $v = $name . '="' . self::e($value) . '"';
        if (preg_match('~\s' . $name . '="[^"]*"~i', $tag)) return (string) preg_replace('~(\s)' . $name . '="[^"]*"~i', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $v), $tag, 1);
        return (string) preg_replace('~\s*/?>$~', ' ' . $v . '$0', $tag, 1);
    }

    private static function dropAttr(string $tag, string $name): string
    {
        return (string) preg_replace('~\s' . $name . '="[^"]*"~i', '', $tag);
    }

    /**
     * $html with $draft's values in place. Untouched fields, and fields whose
     * value already matches, keep their exact bytes.
     */
    public static function apply(string $html, array $draft): string
    {
        $marks = self::scan($html);
        // Right to left, so earlier offsets stay true.
        usort($marks, fn($a, $b) => $b['open'] <=> $a['open']);
        foreach ($marks as $f) {
            $v = $draft[$f['key']] ?? null;
            if (!is_array($v)) continue;
            if ($f['type'] === 'image') {
                $tag = $f['openTag'];
                if (isset($v['src']) && $v['src'] !== $f['src']) {
                    $tag = self::setAttr($tag, 'src', $v['src']);
                    $tag = self::dropAttr(self::dropAttr($tag, 'srcset'), 'sizes');
                    // A library picture brings its size and its smaller copies. The size is
                    // changed where it stands (never dropped and re-added), so putting the old
                    // picture back gives the same bytes; a stale size only reserves the space
                    // until the picture loads.
                    $m = class_exists('Media') ? Media::byUrl($v['src']) : null;
                    if ($m && $m['width'] > 0) {
                        if (self::attr($tag, 'width') !== '' || self::attr($tag, 'height') !== '') {
                            $tag = self::setAttr(self::setAttr($tag, 'width', (string) $m['width']), 'height', (string) $m['height']);
                        }
                        $set = [];
                        foreach ($m['variants'] as $x) { $w = (int) ($x['w'] ?? $x['width'] ?? 0); if ($w > 0) $set[] = $x['url'] . ' ' . $w . 'w'; }
                        if ($set) { $set[] = $m['url'] . ' ' . $m['width'] . 'w'; $tag = self::setAttr($tag, 'srcset', implode(', ', $set)); }
                    }
                }
                if (isset($v['alt']) && $v['alt'] !== $f['alt']) $tag = self::setAttr($tag, 'alt', $v['alt']);
                foreach (['width', 'height', 'srcset', 'sizes'] as $x) {
                    if (!isset($v[$x]) || $v[$x] === self::attr($tag, $x)) continue;
                    $tag = $v[$x] === '' ? self::dropAttr($tag, $x) : self::setAttr($tag, $x, $v[$x]);
                }
                if ($tag !== $f['openTag']) $html = substr_replace($html, $tag, $f['open'], $f['openLen']);
                continue;
            }
            if (isset($v['value']) && $v['value'] !== $f['value']) {
                $inner = $f['type'] === 'rich' ? self::rich($v['value']) : htmlspecialchars($v['value'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html = substr_replace($html, $inner, $f['innerStart'], $f['innerEnd'] - $f['innerStart']);
            }
            if ($f['type'] === 'link' && isset($v['href']) && $v['href'] !== $f['href']) {
                $html = substr_replace($html, self::setAttr($f['openTag'], 'href', $v['href']), $f['open'], $f['openLen']);
            }
        }
        if (isset($draft['seo']) && is_array($draft['seo'])) $html = self::applySeo($html, $draft['seo']);
        return $html;
    }

    private static function applySeo(string $html, array $s): string
    {
        $now = self::seo($html);
        $sub = function (string $html, string $re, string $value) {
            return (string) preg_replace_callback($re, fn($m) => $m[1] . self::e($value) . $m[2], $html, 1);
        };
        if (isset($s['title'], $now['title']) && $s['title'] !== $now['title']) {
            $old = $now['title'];
            $html = $sub($html, '~(<title>)[^<]*(</title>)~i', $s['title']);
            // A share title that said the same as the page title follows it.
            foreach (['property="og:title"', 'name="twitter:title"'] as $m) {
                if (preg_match('~<meta\s+' . preg_quote($m, '~') . '\s+content="([^"]*)"~i', $html, $x) && html_entity_decode($x[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') === $old) {
                    $html = $sub($html, '~(<meta\s+' . preg_quote($m, '~') . '\s+content=")[^"]*(")~i', $s['title']);
                }
            }
        }
        if (isset($s['description'], $now['description']) && $s['description'] !== $now['description']) {
            $old = $now['description'];
            $html = $sub($html, '~(<meta\s+name="description"\s+content=")[^"]*(")~i', $s['description']);
            foreach (['property="og:description"', 'name="twitter:description"'] as $m) {
                if (preg_match('~<meta\s+' . preg_quote($m, '~') . '\s+content="([^"]*)"~i', $html, $x) && html_entity_decode($x[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') === $old) {
                    $html = $sub($html, '~(<meta\s+' . preg_quote($m, '~') . '\s+content=")[^"]*(")~i', $s['description']);
                }
            }
        }
        if (isset($s['image'], $now['image']) && $s['image'] !== $now['image']) {
            $abs = str_starts_with($s['image'], '/') ? 'https://afrovanguard.org.ng' . $s['image'] : $s['image'];
            $html = $sub($html, '~(<meta\s+property="og:image"\s+content=")[^"]*(")~i', $abs);
            $html = $sub($html, '~(<meta\s+name="twitter:image"\s+content=")[^"]*(")~i', $abs);
        }
        return $html;
    }

    /* ── Draft, publish, history ─────────────────────────────────────── */

    private static function state(string $file): array
    {
        $j = json_decode((string) Database::metaGet(self::META . $file), true);
        return is_array($j) ? $j + ['draft' => [], 'hist' => []] : ['draft' => [], 'hist' => []];
    }

    private static function put(string $file, array $s): void
    {
        Database::metaSet(self::META . $file, (string) json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Only what differs from the live page: a draft that says nothing new is no draft. */
    private static function changes(string $file, array $draft): array
    {
        $html = (string) @file_get_contents(self::path($file));
        $live = [];
        foreach (self::scan($html) as $f) $live[$f['key']] = $f;
        $out = [];
        foreach ($draft as $key => $v) {
            if ($key === 'seo') {
                $now = self::seo($html);
                $s = array_filter($v, fn($x, $k) => ($now[$k] ?? null) !== $x && isset($now[$k]), ARRAY_FILTER_USE_BOTH);
                if ($s) $out['seo'] = $s;
                continue;
            }
            $f = $live[$key] ?? null; if (!$f) continue;
            $c = array_filter($v, fn($x, $k) => ($f[$k] ?? null) !== $x, ARRAY_FILTER_USE_BOTH);
            if ($c) $out[$key] = $c;
        }
        return $out;
    }

    public static function draft(string $file): array { return self::state($file)['draft']; }
    public static function history(string $file): array { return self::state($file)['hist']; }

    public static function saveDraft(string $file, array $in): array
    {
        $c = self::clean($file, $in);
        if (!$c['ok']) return $c;
        $s = self::state($file);
        $s['draft'] = self::changes($file, $c['draft']);
        self::put($file, $s);
        return ['ok' => true, 'changed' => self::count($s['draft'])];
    }

    public static function count(array $draft): int
    {
        $n = 0; foreach ($draft as $k => $v) $n += $k === 'seo' ? count($v) : 1; return $n;
    }

    public static function discard(string $file): void { $s = self::state($file); $s['draft'] = []; self::put($file, $s); }

    /** The page as the draft would make it (for the preview). */
    public static function previewHtml(string $file, ?array $draft = null): ?string
    {
        $html = @file_get_contents(self::path($file));
        return $html === false ? null : self::apply($html, $draft ?? self::draft($file));
    }

    public static function publish(string $file, string $by): array
    {
        if (!self::known($file)) return ['ok' => false, 'error' => 'That page is not one the Studio edits.'];
        $s = self::state($file);
        $draft = self::changes($file, self::clean($file, $s['draft'])['draft'] ?? []);
        if (!$draft) return ['ok' => false, 'error' => 'Nothing to publish: the draft matches the live page.'];
        $path = self::path($file);
        $html = (string) @file_get_contents($path);
        // What it said before, so the change can be undone from the history.
        $before = [];
        foreach (self::scan($html) as $f) {
            if (!isset($draft[$f['key']])) continue;
            $ks = array_keys($draft[$f['key']]);
            if ($f['type'] === 'image' && in_array('src', $ks, true)) $ks = array_unique(array_merge($ks, ['width', 'height', 'srcset', 'sizes']));
            foreach ($ks as $k) $before[$f['key']][$k] = $f[$k] ?? '';
        }
        if (isset($draft['seo'])) { $now = self::seo($html); foreach (array_keys($draft['seo']) as $k) $before['seo'][$k] = $now[$k] ?? ''; }
        $new = self::apply($html, $draft);
        if (substr_count($new, '<?') !== substr_count($html, '<?')) return ['ok' => false, 'error' => 'The change was refused: it would have altered the page’s code.'];
        $w = self::write($path, $new, $file);
        if (!$w['ok']) return $w;
        array_unshift($s['hist'], ['at' => gmdate('Y-m-d H:i:s'), 'by' => mb_substr($by, 0, 120), 'n' => self::count($draft), 'before' => $before]);
        $s['hist'] = array_slice($s['hist'], 0, self::HISTORY_MAX);
        $s['draft'] = [];
        self::put($file, $s);
        return ['ok' => true, 'changed' => self::count($draft)];
    }

    /** Put what a publish replaced back into the draft (to check, then publish). */
    public static function restore(string $file, int $i): array
    {
        $h = self::history($file)[$i]['before'] ?? null;
        if (!is_array($h)) return ['ok' => false, 'error' => 'That version is not in the history.'];
        return self::saveDraft($file, $h);
    }

    private static function write(string $path, string $html, string $file): array
    {
        if (!is_writable($path) || !is_writable(dirname($path))) {
            return ['ok' => false, 'error' => 'The server cannot write ' . $file . '. In cPanel → File Manager, set it to 644 and its folder to 755, then publish again.'];
        }
        $tmp = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $html, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Could not write ' . $file . '.'];
        }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
        return ['ok' => true];
    }

    /** Pages that show, or are about to show, an address (so the library does not delete it from under them). */
    public static function usesUrl(array $urls): array
    {
        $where = [];
        foreach (self::PAGES as $file => $name) {
            $html = (string) @file_get_contents(self::path($file));
            $draft = json_encode(self::draft($file), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            foreach ($urls as $u) {
                if ($u === '') continue;
                if (str_contains($html, '"' . $u . '"') || str_contains($html, $u . ' ')) { $where[] = 'the ' . $name . ' page'; break; }
                if (str_contains((string) $draft, $u)) { $where[] = 'the ' . $name . ' page draft'; break; }
            }
        }
        return $where;
    }

    /** The Studio's list: each page, its address, how many fields, and whether a draft waits. */
    public static function index(): array
    {
        $out = [];
        foreach (self::PAGES as $file => $name) {
            if (!is_file(self::path($file))) continue;
            $html = (string) file_get_contents(self::path($file));
            $out[] = ['file' => $file, 'name' => $name, 'href' => self::href($file), 'fields' => count(self::scan($html)),
                'pending' => self::count(self::draft($file)), 'published' => self::history($file)[0]['at'] ?? ''];
        }
        return $out;
    }

    /* ── Marking a page (tools/cms-annotate.php) ─────────────────────── */

    /**
     * Add data-cms marks to the editable elements inside <main> of $html.
     * Skipped: the homepage hero (it has its own editor), anything in a
     * template/script/noscript/svg/form, anything a script fills (an id or a
     * data- attribute), decorative (aria-hidden) text, PHP, and any element
     * whose content is more than words and <em>/<strong>/<br>.
     * Keys are "<slug>-<tag>-<n>" and never reused: n continues from the
     * highest already on the page, so a mark survives later edits.
     */
    public static function annotate(string $html, string $slug): string
    {
        $mainA = stripos($html, '<main'); $mainB = strripos($html, '</main>');
        if ($mainA === false || $mainB === false) return $html;
        $skip = [];
        foreach (['template', 'script', 'noscript', 'svg', 'form', 'select', 'button'] as $t) {
            if (preg_match_all('~<' . $t . '\b.*?</' . $t . '>~is', $html, $m, PREG_OFFSET_CAPTURE)) foreach ($m[0] as $x) $skip[] = [$x[1], $x[1] + strlen($x[0])];
        }
        $a = strpos($html, '<!-- cms:hero -->'); $b = strpos($html, '<!-- /cms:hero -->');
        if ($a !== false && $b !== false) $skip[] = [$a, $b];
        if (preg_match_all('~<\?.*?(\?>|$)~s', $html, $m, PREG_OFFSET_CAPTURE)) foreach ($m[0] as $x) $skip[] = [$x[1], $x[1] + strlen($x[0])];
        $inSkip = function (int $at) use ($skip) { foreach ($skip as [$s, $e]) if ($at >= $s && $at < $e) return true; return false; };
        // A container hidden from screen readers is a visual copy (the ticker's
        // second loop): its elements share the original's key, so one edit
        // changes both; anything in one with no original is left alone.
        $copies = [];
        if (preg_match_all('~<([a-z][a-z0-9]*)\b[^<>]*\saria-hidden="true"[^<>]*>~i', $html, $hm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($hm as $h) {
                $end = self::closeOf($html, strtolower($h[1][0]), $h[0][1]);
                if ($end !== null && $end > $h[0][1] + strlen($h[0][0])) $copies[] = [$h[0][1], $end];
            }
        }
        $inCopy = function (int $at) use ($copies) { foreach ($copies as [$s, $e]) if ($at > $s && $at < $e) return true; return false; };
        $seen = [];   // tag|value => key, for the copies

        $next = [];
        if (preg_match_all('~data-cms="' . preg_quote($slug, '~') . '-([a-z0-9]+)-(\d+)"~', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $next[$x[1]] = max($next[$x[1]] ?? 0, (int) $x[2]);
        }
        $tags = array_merge(self::TEXT_TAGS, ['a', 'img']);
        $re = '~<(' . implode('|', $tags) . ')(\s[^<>]*)?>~i';
        preg_match_all($re, $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $adds = [];
        foreach ($m as $hit) {
            [$open, $at] = $hit[0];
            if ($at < $mainA || $at > $mainB || $inSkip($at)) continue;
            $tag = strtolower($hit[1][0]); $attrs = $hit[2][0] ?? '';
            if (str_contains($attrs, 'data-cms=') || preg_match('~\s(id|data-[a-z-]+|hidden|aria-hidden|contenteditable)\b~i', $attrs) || str_contains($attrs, '<?')) continue;
            $inner = '';
            if ($tag === 'img') {
                if (self::attr($open, 'src') === '' || str_starts_with(self::attr($open, 'src'), 'data:')) continue;
            } else {
                $close = stripos($html, '</' . $tag . '>', $at + strlen($open));
                if ($close === false) continue;
                $inner = substr($html, $at + strlen($open), $close - $at - strlen($open));
                if (trim(strip_tags($inner)) === '' || str_contains($inner, '<?')) continue;
                // Only words, or words with the inline tags a rich field keeps.
                if (preg_match_all('~<(/?)([a-z0-9]+)([^>]*)>~i', $inner, $tt, PREG_SET_ORDER)) {
                    $ok = true;
                    foreach ($tt as $t) if (!in_array(strtolower($t[2]), self::RICH_TAGS, true) || trim(rtrim($t[3], '/')) !== '') { $ok = false; break; }
                    if (!$ok) continue;
                    // A rich field is rewritten in a normal form; keep it only if that form is what is there now.
                    if (self::rich($inner) !== trim(preg_replace('/\s+/u', ' ', $inner) ?? '')) continue;
                }
                if ($tag === 'a') { $h = self::attr($open, 'href'); if ($h === '' || self::link($h) === '') continue; }
            }
            $sig = $tag . '|' . ($tag === 'img' ? self::attr($open, 'src') : trim(preg_replace('/\s+/u', ' ', $inner) ?? '')) . ($tag === 'a' ? '|' . self::attr($open, 'href') : '');
            if ($inCopy($at)) {
                if (!isset($seen[$sig])) continue;
                $mark = ' data-cms="' . $seen[$sig] . '"';
            } else {
                $n = ($next[$tag] ?? 0) + 1; $next[$tag] = $n;
                $key = $slug . '-' . $tag . '-' . $n;
                $seen[$sig] = $seen[$sig] ?? $key;
                $mark = ' data-cms="' . $key . '"';
            }
            $pos = str_ends_with($open, '/>') ? strlen($open) - 2 : strlen($open) - 1;
            if ($pos > 0 && $open[$pos - 1] === ' ') $pos--;
            $adds[] = [$at + $pos, $mark];
        }
        foreach (array_reverse($adds) as [$pos, $mark]) $html = substr_replace($html, $mark, $pos, 0);
        return $html;
    }

    /** Offset of the </tag> that closes the element opened at $at, counting nesting. */
    private static function closeOf(string $html, string $tag, int $at): ?int
    {
        $depth = 0;
        if (!preg_match_all('~<(/?)' . preg_quote($tag, '~') . '\b[^>]*>~i', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $at)) return null;
        foreach ($m as $x) {
            if ($x[1][0] === '') $depth++; else $depth--;
            if ($depth === 0) return $x[0][1];
        }
        return null;
    }

    public static function slug(string $file): string
    {
        return trim((string) preg_replace('~[^a-z0-9]+~', '-', strtolower(preg_replace('~(/index)?\.(html|php)$~', '', $file) ?? '')), '-') ?: 'home';
    }
}
