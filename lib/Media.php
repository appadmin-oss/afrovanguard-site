<?php
/**
 * lib/Media.php — the Studio's media library: upload, describe, edit, reuse.
 *
 * WHAT IT HOLDS
 *   Images (JPEG, PNG, WebP, GIF) and videos (MP4, WebM), one row each in
 *   media_items, with the file under /uploads/media/YYYY/MM/. Every image keeps
 *   its ORIGINAL untouched (/uploads/media/originals/) — edits are made from it,
 *   so cropping twice never loses pixels, and "Revert to original" always works.
 *
 * UPLOADS OF ANY SIZE, ON SHARED HOSTING
 *   Hosts cap a single upload (often 2–64 MB) and a homepage video is bigger.
 *   So the Studio sends files in CHUNK_BYTES pieces: start() → chunk() × n →
 *   finish(). Each piece is far under any cap; the file is assembled in
 *   /uploads/_scratch, checked by its CONTENT (not its name or the browser's
 *   word), and only then becomes a media item.
 *
 * WHAT AN IMAGE BECOMES
 *   Turned upright (camera EXIF), capped at MASTER_MAX px on its long edge,
 *   saved as the master (JPEG, or PNG when it has transparency), plus WebP
 *   copies at VARIANT_WIDTHS for fast pages. GD only — no Imagick needed.
 *
 * EDITING
 *   edit() applies crop / rotate / flip / max width to the ORIGINAL and writes a
 *   NEW file name, so no visitor's browser keeps showing the old picture. Places
 *   that used the old address (the homepage draft) are pointed at the new one.
 */
declare(strict_types=1);

final class Media
{
    public const CHUNK_BYTES = 1_572_864;            // 1.5 MB — under every host's cap
    public const IMAGE_MAX = 40 * 1024 * 1024;
    public const VIDEO_MAX = 400 * 1024 * 1024;
    public const MASTER_MAX = 2560;
    public const VARIANT_WIDTHS = [640, 1280, 1920];
    public const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    public const VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/x-m4v' => 'm4v', 'video/quicktime' => 'mov'];
    public const PER_PAGE = 48;

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        $db = Database::pdo();
        Database::execSchema($db, "CREATE TABLE IF NOT EXISTS media_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind VARCHAR(8) NOT NULL,
            url VARCHAR(255) NOT NULL,
            mime VARCHAR(40) NOT NULL DEFAULT '',
            width INTEGER NOT NULL DEFAULT 0,
            height INTEGER NOT NULL DEFAULT 0,
            bytes INTEGER NOT NULL DEFAULT 0,
            name VARCHAR(190) NOT NULL DEFAULT '',
            alt VARCHAR(255) NOT NULL DEFAULT '',
            original VARCHAR(255) NOT NULL DEFAULT '',
            variants TEXT,
            edits TEXT,
            created_by VARCHAR(190) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL,
            updated_at VARCHAR(32) NOT NULL
        )");
        Database::ensureIndex($db, 'idx_media_kind', 'media_items', 'kind, id');
        Database::ensureIndex($db, 'idx_media_url', 'media_items', 'url');
    }

    /** Overridable for tests. */
    public static ?string $root = null;
    private static function root(): string { return self::$root ?? AV_ROOT; }
    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
    private static function fsPath(string $url): string { return self::root() . $url; }

    /* ── Chunked upload ───────────────────────────────────────────────── */

    private static function scratch(): string
    {
        if (class_exists('Storage') && self::$root === null) Storage::guardUploads();
        $d = self::root() . '/uploads/_scratch';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    private static function token(string $t): ?string
    {
        return preg_match('/^[a-f0-9]{32}$/', $t) ? $t : null;
    }

    /** Begin an upload. The browser's name and size are only used to plan it. */
    public static function start(string $name, int $size, string $by): array
    {
        self::ensure();
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $isVideo = in_array($ext, ['mp4', 'webm', 'm4v', 'mov'], true);
        if (!$isVideo && !in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return ['ok' => false, 'error' => 'Upload a picture (JPG, PNG, WebP, GIF) or a video (MP4, WebM).'];
        }
        $max = $isVideo ? self::VIDEO_MAX : self::IMAGE_MAX;
        if ($size <= 0 || $size > $max) return ['ok' => false, 'error' => 'That file is over ' . ($max >> 20) . ' MB.'];
        self::sweepScratch();
        $t = bin2hex(random_bytes(16));
        $dir = self::scratch();
        file_put_contents("$dir/$t.json", json_encode(['name' => mb_substr($name, 0, 190), 'size' => $size, 'next' => 0, 'by' => $by, 'at' => time()]));
        file_put_contents("$dir/$t.part", '');
        return ['ok' => true, 'token' => $t, 'chunk' => self::CHUNK_BYTES];
    }

    /** One piece, in order. A repeated piece (a retry) is accepted once. */
    public static function chunk(string $token, int $index, string $bytes): array
    {
        $t = self::token($token); if ($t === null) return ['ok' => false, 'error' => 'Unknown upload.'];
        $dir = self::scratch();
        $meta = json_decode((string) @file_get_contents("$dir/$t.json"), true);
        if (!is_array($meta)) return ['ok' => false, 'error' => 'That upload has expired. Start it again.'];
        if ($index < $meta['next']) return ['ok' => true, 'next' => $meta['next']];          // a retried piece
        if ($index !== $meta['next']) return ['ok' => false, 'error' => 'A piece went missing. Start the upload again.'];
        if (strlen($bytes) > self::CHUNK_BYTES || strlen($bytes) === 0) return ['ok' => false, 'error' => 'A piece was the wrong size.'];
        $have = (int) @filesize("$dir/$t.part");
        if ($have + strlen($bytes) > $meta['size']) return ['ok' => false, 'error' => 'The file is bigger than it said it was.'];
        file_put_contents("$dir/$t.part", $bytes, FILE_APPEND | LOCK_EX);
        $meta['next'] = $index + 1;
        file_put_contents("$dir/$t.json", json_encode($meta));
        return ['ok' => true, 'next' => $meta['next']];
    }

    /** Assemble, check by content, and file it. */
    public static function finish(string $token, string $alt = ''): array
    {
        $t = self::token($token); if ($t === null) return ['ok' => false, 'error' => 'Unknown upload.'];
        $dir = self::scratch();
        $meta = json_decode((string) @file_get_contents("$dir/$t.json"), true);
        $part = "$dir/$t.part";
        if (!is_array($meta) || !is_file($part)) return ['ok' => false, 'error' => 'That upload has expired. Start it again.'];
        clearstatcache(true, $part);
        if ((int) filesize($part) !== (int) $meta['size']) { self::drop($t); return ['ok' => false, 'error' => 'The upload did not arrive whole. Try again.']; }
        try { return self::import($part, (string) $meta['name'], (string) $meta['by'], $alt); }
        finally { self::drop($t); }
    }

    private static function drop(string $t): void
    {
        $dir = self::scratch();
        @unlink("$dir/$t.part"); @unlink("$dir/$t.json");
    }

    /** Uploads abandoned for a day are removed, so the scratch folder cannot fill a disk. */
    private static function sweepScratch(): void
    {
        foreach (glob(self::scratch() . '/*.{json,part}', GLOB_BRACE) ?: [] as $f) {
            if (@filemtime($f) < time() - 86400) @unlink($f);
        }
    }

    private static function mimeOf(string $path): string
    {
        $m = '';
        if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $m = (string) finfo_file($fi, $path); finfo_close($fi); }
        if ($m === 'application/octet-stream' || $m === '') {
            $head = (string) @file_get_contents($path, false, null, 0, 16);
            if (substr($head, 4, 4) === 'ftyp') $m = 'video/mp4';
            elseif (substr($head, 0, 4) === "\x1A\x45\xDF\xA3") $m = 'video/webm';
        }
        return $m;
    }

    /** File a local file as a media item. Used by finish() and by tests. */
    public static function import(string $path, string $name, string $by, string $alt = ''): array
    {
        self::ensure();
        $mime = self::mimeOf($path);
        $sub = '/uploads/media/' . gmdate('Y/m');
        $base = self::slug($name) . '-' . bin2hex(random_bytes(3));
        if (!is_dir(self::root() . $sub)) @mkdir(self::root() . $sub, 0775, true);

        if (isset(self::VIDEO_TYPES[$mime])) {
            if (filesize($path) > self::VIDEO_MAX) return ['ok' => false, 'error' => 'That video is over ' . (self::VIDEO_MAX >> 20) . ' MB.'];
            $ext = self::VIDEO_TYPES[$mime] === 'mov' ? 'mp4' : self::VIDEO_TYPES[$mime];   // QuickTime H.264 plays as MP4
            $url = "$sub/$base.$ext";
            if (!@copy($path, self::fsPath($url))) return ['ok' => false, 'error' => 'Could not store the video.'];
            return self::insert(['kind' => 'video', 'url' => $url, 'mime' => $mime, 'bytes' => (int) filesize($path), 'name' => $name, 'alt' => $alt, 'by' => $by]);
        }
        if (!isset(self::IMAGE_TYPES[$mime])) return ['ok' => false, 'error' => 'That is not a picture or video this site can show.'];
        if (filesize($path) > self::IMAGE_MAX) return ['ok' => false, 'error' => 'That picture is over ' . (self::IMAGE_MAX >> 20) . ' MB.'];

        /* Keep the original byte for byte; every edit starts from it. */
        $origDir = '/uploads/media/originals';
        if (!is_dir(self::root() . $origDir)) @mkdir(self::root() . $origDir, 0775, true);
        $orig = "$origDir/$base." . self::IMAGE_TYPES[$mime];
        if (!@copy($path, self::fsPath($orig))) return ['ok' => false, 'error' => 'Could not store the picture.'];

        $r = self::render($orig, [], $sub, $base);
        if (!$r['ok']) { @unlink(self::fsPath($orig)); return $r; }
        return self::insert(['kind' => 'image', 'url' => $r['url'], 'mime' => $r['mime'], 'width' => $r['w'], 'height' => $r['h'],
            'bytes' => $r['bytes'], 'name' => $name, 'alt' => $alt, 'original' => $orig, 'variants' => $r['variants'], 'by' => $by]);
    }

    private static function insert(array $m): array
    {
        $db = Database::pdo(); $now = self::now();
        $db->prepare('INSERT INTO media_items (kind, url, mime, width, height, bytes, name, alt, original, variants, edits, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$m['kind'], $m['url'], $m['mime'], (int) ($m['width'] ?? 0), (int) ($m['height'] ?? 0), (int) $m['bytes'],
                      mb_substr((string) $m['name'], 0, 190), mb_substr(trim((string) $m['alt']), 0, 255), (string) ($m['original'] ?? ''),
                      json_encode($m['variants'] ?? []), json_encode([]), mb_substr((string) $m['by'], 0, 190), $now, $now]);
        return ['ok' => true, 'item' => self::get((int) $db->lastInsertId())];
    }

    private static function slug(string $name): string
    {
        $s = strtolower(preg_replace('/[^a-z0-9]+/i', '-', pathinfo($name, PATHINFO_FILENAME)) ?? '');
        return substr(trim($s, '-'), 0, 40) ?: 'media';
    }

    /* ── Images ───────────────────────────────────────────────────────── */

    private static function load(string $file)
    {
        $data = @file_get_contents($file);
        $im = $data !== false ? @imagecreatefromstring($data) : false;
        if (!$im) return false;
        /* Phones save pictures sideways and record which way is up in EXIF. */
        if (function_exists('exif_read_data') && str_ends_with(strtolower($file), 'jpg')) {
            $o = (int) (@exif_read_data($file)['Orientation'] ?? 1);
            $im = match ($o) {
                3 => imagerotate($im, 180, 0), 6 => imagerotate($im, -90, 0), 8 => imagerotate($im, 90, 0), default => $im,
            };
        }
        return $im;
    }

    private static function hasAlpha($im): bool
    {
        if (imageistruecolor($im) === false) return imagecolortransparent($im) >= 0;
        $w = imagesx($im); $h = imagesy($im);
        $step = max(1, intdiv(max($w, $h), 64));
        for ($x = 0; $x < $w; $x += $step) for ($y = 0; $y < $h; $y += $step) {
            if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 0) return true;
        }
        return false;
    }

    private static function resized($im, int $maxW)
    {
        $w = imagesx($im); $h = imagesy($im);
        if ($w <= $maxW) return $im;
        $nh = (int) round($h * $maxW / $w);
        $out = imagecreatetruecolor($maxW, $nh);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $maxW, $nh, $w, $h);
        return $out;
    }

    /**
     * Build the master and its WebP copies from the original, applying edits.
     * @param array{crop?:array{x:int,y:int,w:int,h:int}, rotate?:int, flip?:string, max_width?:int} $ops
     */
    private static function render(string $origUrl, array $ops, string $sub, string $base): array
    {
        $im = self::load(self::fsPath($origUrl));
        if (!$im) return ['ok' => false, 'error' => 'That picture could not be read. Save it again as a JPG or PNG and re-upload it.'];
        imagealphablending($im, false); imagesavealpha($im, true);

        $rot = (int) ($ops['rotate'] ?? 0);
        if (in_array($rot, [90, 180, 270], true)) $im = imagerotate($im, -$rot, 0);    // clockwise, as people say it
        if (($ops['flip'] ?? '') === 'h') imageflip($im, IMG_FLIP_HORIZONTAL);
        if (($ops['flip'] ?? '') === 'v') imageflip($im, IMG_FLIP_VERTICAL);
        if (!empty($ops['crop'])) {
            $c = $ops['crop']; $W = imagesx($im); $H = imagesy($im);
            $x = max(0, min($W - 1, (int) $c['x'])); $y = max(0, min($H - 1, (int) $c['y']));
            $w = max(1, min($W - $x, (int) $c['w'])); $h = max(1, min($H - $y, (int) $c['h']));
            $cr = imagecrop($im, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
            if ($cr) $im = $cr;
        }
        $max = min(self::MASTER_MAX, max(200, (int) ($ops['max_width'] ?? self::MASTER_MAX)));
        if (imagesx($im) > $max || imagesy($im) > self::MASTER_MAX) {
            $im = self::resized($im, imagesx($im) >= imagesy($im) ? $max : (int) floor($max * imagesx($im) / max(1, imagesy($im))));
        }

        $alpha = self::hasAlpha($im);
        $ext = $alpha ? 'png' : 'jpg';
        $url = "$sub/$base.$ext";
        $ok = $alpha ? imagepng($im, self::fsPath($url), 7) : imagejpeg(self::flatten($im), self::fsPath($url), 84);
        if (!$ok) return ['ok' => false, 'error' => 'Could not save the picture.'];
        $variants = [];
        if (function_exists('imagewebp')) {
            foreach (self::VARIANT_WIDTHS as $vw) {
                if ($vw >= imagesx($im) && $variants) break;
                $v = self::resized($im, min($vw, imagesx($im)));
                $vu = "$sub/$base-" . min($vw, imagesx($im)) . '.webp';
                if (@imagewebp($v, self::fsPath($vu), 80)) $variants[] = ['w' => min($vw, imagesx($im)), 'url' => $vu];
            }
        }
        return ['ok' => true, 'url' => $url, 'mime' => $alpha ? 'image/png' : 'image/jpeg', 'w' => imagesx($im), 'h' => imagesy($im),
                'bytes' => (int) filesize(self::fsPath($url)), 'variants' => $variants];
    }

    /** JPEG has no transparency: paint it on white rather than black. */
    private static function flatten($im)
    {
        $w = imagesx($im); $h = imagesy($im);
        $bg = imagecreatetruecolor($w, $h);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagealphablending($bg, true);
        imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
        return $bg;
    }

    /**
     * Edit a picture: from the original, with these operations. The result is a
     * new file; the homepage draft is pointed at it.
     */
    public static function edit(int $id, array $ops, string $by): array
    {
        $m = self::get($id);
        if (!$m || $m['kind'] !== 'image') return ['ok' => false, 'error' => 'Only pictures can be edited here.'];
        if ($m['original'] === '' || !is_file(self::fsPath($m['original']))) return ['ok' => false, 'error' => 'The original of this picture is missing, so it cannot be edited.'];
        $clean = [];
        if (isset($ops['rotate']) && in_array((int) $ops['rotate'], [0, 90, 180, 270], true)) $clean['rotate'] = (int) $ops['rotate'];
        if (in_array($ops['flip'] ?? '', ['h', 'v'], true)) $clean['flip'] = $ops['flip'];
        if (!empty($ops['crop']) && is_array($ops['crop'])) {
            $c = array_map('intval', array_intersect_key($ops['crop'], array_flip(['x', 'y', 'w', 'h'])));
            if (count($c) === 4 && $c['w'] >= 16 && $c['h'] >= 16) $clean['crop'] = $c;
        }
        if (!empty($ops['max_width'])) $clean['max_width'] = max(200, min(self::MASTER_MAX, (int) $ops['max_width']));
        $sub = dirname($m['url']);
        $base = self::slug($m['name']) . '-' . bin2hex(random_bytes(3));
        $r = self::render($m['original'], $clean, $sub, $base);
        if (!$r['ok']) return $r;
        self::removeFiles($m, false);
        Database::pdo()->prepare('UPDATE media_items SET url = ?, mime = ?, width = ?, height = ?, bytes = ?, variants = ?, edits = ?, updated_at = ? WHERE id = ?')
            ->execute([$r['url'], $r['mime'], $r['w'], $r['h'], $r['bytes'], json_encode($r['variants']), json_encode($clean), self::now(), $id]);
        $moved = self::repoint($m['url'], $r['url']);
        return ['ok' => true, 'item' => self::get($id), 'repointed' => $moved];
    }

    public static function revert(int $id, string $by): array { return self::edit($id, [], $by); }

    /** Point the homepage draft at a file's new address. → how many places changed. */
    private static function repoint(string $old, string $new): int
    {
        if (!class_exists('SiteHome')) return 0;
        $n = 0;
        $doc = SiteHome::draft();
        $walk = function (&$v) use ($old, $new, &$n, &$walk) {
            if (is_array($v)) { foreach ($v as &$x) $walk($x); return; }
            if ($v === $old) { $v = $new; $n++; }
        };
        $walk($doc);
        if ($n > 0) SiteHome::saveDraft($doc);
        return $n;
    }

    /* ── Describing, finding, removing ────────────────────────────────── */

    public static function get(int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM media_items WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['variants'] = json_decode((string) $r['variants'], true) ?: [];
        $r['edits'] = json_decode((string) $r['edits'], true) ?: [];
        $r['id'] = (int) $r['id']; $r['width'] = (int) $r['width']; $r['height'] = (int) $r['height']; $r['bytes'] = (int) $r['bytes'];
        $r['thumb'] = $r['variants'][0]['url'] ?? $r['url'];
        return $r;
    }

    public static function describe(int $id, string $alt, string $name): array
    {
        if (!self::get($id)) return ['ok' => false, 'error' => 'That item is not in the library.'];
        Database::pdo()->prepare('UPDATE media_items SET alt = ?, name = ?, updated_at = ? WHERE id = ?')
            ->execute([mb_substr(trim($alt), 0, 255), mb_substr(trim($name), 0, 190), self::now(), $id]);
        return ['ok' => true, 'item' => self::get($id)];
    }

    /** @return array{items:list<array>, total:int} */
    public static function list(string $kind = '', string $q = '', int $page = 1): array
    {
        self::ensure();
        $where = []; $args = [];
        if (in_array($kind, ['image', 'video'], true)) { $where[] = 'kind = ?'; $args[] = $kind; }
        if (trim($q) !== '') { $where[] = '(name LIKE ? OR alt LIKE ?)'; $like = '%' . trim($q) . '%'; $args[] = $like; $args[] = $like; }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $db = Database::pdo();
        $c = $db->prepare('SELECT COUNT(*) FROM media_items' . $w); $c->execute($args);
        $st = $db->prepare('SELECT id FROM media_items' . $w . ' ORDER BY id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (max(1, $page) - 1) * self::PER_PAGE);
        $st->execute($args);
        return ['items' => array_values(array_filter(array_map(fn($id) => self::get((int) $id), $st->fetchAll(PDO::FETCH_COLUMN) ?: []))), 'total' => (int) $c->fetchColumn()];
    }

    /** Where an address is in use. A used file is not deleted from under a live page. */
    public static function usedBy(array $m): array
    {
        $urls = array_merge([$m['url']], array_column($m['variants'], 'url'));
        $where = [];
        if (class_exists('SiteHome')) {
            $live = json_encode(SiteHome::live()); $draft = json_encode(SiteHome::draft());
            foreach ($urls as $u) {
                $j = trim((string) json_encode($u), '"');
                if (str_contains($live, $j)) $where['live'] = 'the published homepage';
                if (str_contains($draft, $j)) $where['draft'] = 'the homepage draft';
            }
        }
        return array_values($where);
    }

    public static function delete(int $id): array
    {
        $m = self::get($id);
        if (!$m) return ['ok' => false, 'error' => 'That item is not in the library.'];
        $used = self::usedBy($m);
        if ($used) return ['ok' => false, 'error' => 'It is used on ' . implode(' and ', $used) . '. Replace it there first.'];
        self::removeFiles($m, true);
        Database::pdo()->prepare('DELETE FROM media_items WHERE id = ?')->execute([$id]);
        return ['ok' => true];
    }

    private static function removeFiles(array $m, bool $withOriginal): void
    {
        foreach (array_merge([$m['url']], array_column($m['variants'], 'url')) as $u) {
            if (str_starts_with($u, '/uploads/media/')) @unlink(self::fsPath($u));
        }
        if ($withOriginal && str_starts_with($m['original'], '/uploads/media/originals/')) @unlink(self::fsPath($m['original']));
    }
}
