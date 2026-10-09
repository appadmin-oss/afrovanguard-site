<?php
/**
 * lib/CardPhoto.php — the photograph on a member card, made print-ready.
 *
 * Ported from NextGen Genius's card photo pipeline (domain/photo-framing.php,
 * card-photo.jsx, image-tools.js, domain/photos.php), adapted to how this site
 * draws its card. NGG frames a photo at RENDER time from stored measurements;
 * here the card has one panel shape (47.2 × 35.2 mm), so the photo is framed
 * once, at upload, and stored already cut to that shape:
 *
 *   1. measure  Gemini finds the head, face, eyes and shoulders (NGG's prompt,
 *               schema and plausibility checks, a second look when the first
 *               answer is implausible). The browser's FaceDetector is the
 *               fallback, then the top-centre of the photo.
 *   2. frame    NGG's head-and-shoulders rule (cphFrameAi): the head fills a
 *               fixed share of the height, a set margin above the crown,
 *               centred between the eyes, never cutting the head or leaving
 *               the picture. Staff adjust it in the crop editor.
 *   3. resample pica (Lanczos) in the browser to 1114 × 832 — 600 dpi at the
 *               panel — never enlarged; checked for size and light.
 *   4. store    here: type from the bytes, re-encoded (no EXIF, no GPS),
 *               forced to the panel's shape, then Cloudinary or /uploads.
 *   5. enhance  with Cloudinary, delivered through e_improve, and — when the
 *               owner switches it on — background removal, so the member
 *               stands on the card's gold the way the design shows.
 */
declare(strict_types=1);

final class CardPhoto
{
    /** The panel, at 300 dpi and at the 600 dpi it is stored at. */
    public const MIN_W = 557, MIN_H = 416;
    public const SAVE_W = 1114, SAVE_H = 832;
    public const ASPECT = 47.2 / 35.2;
    private const MAX_BYTES = 12 * 1048576;
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    public const TOO_SMALL = 'Your photo is too small to print sharply. Upload one at least 800 px wide.';

    /* ══ 1. Measure ══════════════════════════════════════════════════════ */

    private const PROMPT = <<<'TXT'
You measure photographs for printed ID cards (a headshot: head and shoulders of one person).
Find the SUBJECT: the one person the photo is of — the largest, most central face turned towards the camera. Ignore people in the background, posters, screens and reflections.
Give every box as [ymin, xmin, ymax, xmax] and every point as [y, x], all normalised to 0-1000 over the whole image (0,0 is the top-left corner).
- head: the whole head INCLUDING all hair, a head-tie, cap or wrap: from the very top of the hair or headwear to the bottom of the chin, and from the outer edge of the hair or ear on one side to the other. Do not cut off hair.
- face: from the eyebrows to the bottom of the chin, cheek to cheek.
- leftEye and rightEye: the centre of each pupil, as they appear in the image (leftEye is the one nearer the left edge).
- shoulders: the y of the top of the shoulders, and the x of the outer edge of each shoulder; null if the shoulders are out of the picture.
- people: how many people's faces are visible at all.
- kind: "headshot" (head and shoulders fill the photo), "half" (to the waist), "full" (most of the body), "group" or "none".
- subjectFound: false when there is no clear single face to put on an ID card.
Be precise to the pixel. If the head touches or leaves the edge of the photo, give the box up to the edge.
TXT;

    private static function schema(): array
    {
        $box = ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER'], 'minItems' => 4, 'maxItems' => 4];
        $pt = ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER'], 'minItems' => 2, 'maxItems' => 2];
        return ['type' => 'OBJECT', 'properties' => [
            'subjectFound' => ['type' => 'BOOLEAN'], 'people' => ['type' => 'INTEGER'],
            'kind' => ['type' => 'STRING', 'enum' => ['headshot', 'half', 'full', 'group', 'none']],
            'head' => $box, 'face' => $box, 'leftEye' => $pt, 'rightEye' => $pt,
            'shoulders' => ['type' => 'OBJECT', 'nullable' => true, 'properties' => ['y' => ['type' => 'INTEGER'], 'left' => ['type' => 'INTEGER'], 'right' => ['type' => 'INTEGER']]],
        ], 'required' => ['subjectFound', 'people', 'kind', 'head', 'face', 'leftEye', 'rightEye'],
           'propertyOrdering' => ['subjectFound', 'people', 'kind', 'head', 'face', 'leftEye', 'rightEye', 'shoulders']];
    }

    /**
     * Where the subject is, as fractions of the image (x across, y down).
     *
     * @return array{ok:bool, geom?:array, error?:string, detail?:string}
     */
    public static function measure(string $bytes): array
    {
        $mock = isset($GLOBALS['__card_photo_mock']) && is_callable($GLOBALS['__card_photo_mock']) ? $GLOBALS['__card_photo_mock'] : null;
        if (!$mock && (!class_exists('Gemini') || !Gemini::configured())) return ['ok' => false, 'error' => 'ai_unavailable'];
        $img = self::forModel($bytes);
        if ($img === null) return ['ok' => false, 'error' => 'unreadable'];
        $extra = '';
        $last = ['ok' => false, 'error' => 'ai_failed'];
        for ($try = 0; $try < 2; $try++) {
            $r = $mock ? $mock($extra)
                : Gemini::generate('', ['temperature' => 0.0, 'max_tokens' => 600, 'system' => self::PROMPT, 'json_schema' => self::schema(),
                      'parts' => [['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($img)]],
                                  ['text' => 'Measure the subject of this photograph.' . ($extra !== '' ? ' ' . $extra : '')]]]);
            if (empty($r['ok'])) { $last = ['ok' => false, 'error' => (string) ($r['error'] ?? 'ai_failed')]; continue; }
            $text = (string) $r['text'];
            if (preg_match('/\{.*\}/s', $text, $m)) $text = $m[0];
            $data = json_decode($text, true);
            if (!is_array($data)) { $last = ['ok' => false, 'error' => 'ai_bad_json']; continue; }
            $c = self::clean($data);
            if ($c['ok']) return $c;
            $extra = 'A previous measurement was rejected: ' . $c['why'] . ' Measure again carefully.';
            $last = ['ok' => false, 'error' => 'implausible', 'detail' => $c['why']];
        }
        return $last;
    }

    /** The model's answer made safe and checked (NGG photo_frame_clean). */
    public static function clean(array $d): array
    {
        $f = static fn($v) => max(0.0, min(1.0, ((float) $v) / 1000));
        $box = static function ($b) use ($f) {
            if (!is_array($b) || count($b) !== 4) return null;
            [$y0, $x0, $y1, $x1] = array_map($f, array_values($b));
            if ($y1 < $y0) [$y0, $y1] = [$y1, $y0];
            if ($x1 < $x0) [$x0, $x1] = [$x1, $x0];
            return ($x1 - $x0) > 0.01 && ($y1 - $y0) > 0.01 ? ['x0' => $x0, 'y0' => $y0, 'x1' => $x1, 'y1' => $y1] : null;
        };
        $pt = static fn($p) => is_array($p) && count($p) === 2 ? ['y' => $f(array_values($p)[0]), 'x' => $f(array_values($p)[1])] : null;
        if (empty($d['subjectFound'])) return ['ok' => true, 'geom' => ['subject' => false, 'people' => (int) ($d['people'] ?? 0), 'kind' => (string) ($d['kind'] ?? 'none')]];
        $head = $box($d['head'] ?? null); $face = $box($d['face'] ?? null);
        $le = $pt($d['leftEye'] ?? null); $re = $pt($d['rightEye'] ?? null);
        if (!$head || !$face) return ['ok' => false, 'why' => 'The head or face box was missing.'];
        $tol = 0.03;
        if ($face['x0'] < $head['x0'] - $tol || $face['x1'] > $head['x1'] + $tol || $face['y0'] < $head['y0'] - $tol || $face['y1'] > $head['y1'] + $tol)
            return ['ok' => false, 'why' => 'The face box was not inside the head box; the head box must include all the hair and the chin.'];
        $hw = $head['x1'] - $head['x0']; $hh = $head['y1'] - $head['y0'];
        if ($hh <= 0 || $hw / $hh > 4 || $hw / $hh < 0.2) return ['ok' => false, 'why' => 'The head box was not shaped like a head.'];
        if ($face['y1'] < $head['y1'] - 0.35 * $hh) return ['ok' => false, 'why' => 'The chin (bottom of the face box) must be at the bottom of the head box.'];
        if ($le && $re) {
            foreach ([$le, $re] as $e) if ($e['x'] < $face['x0'] - $tol || $e['x'] > $face['x1'] + $tol || $e['y'] < $face['y0'] - $tol || $e['y'] > $face['y1'] + $tol)
                return ['ok' => false, 'why' => 'An eye was outside the face box.'];
            if ($le['x'] > $re['x']) [$le, $re] = [$re, $le];
        }
        $sh = null;
        if (is_array($d['shoulders'] ?? null) && isset($d['shoulders']['y'])) {
            $sh = ['y' => $f($d['shoulders']['y']), 'x0' => $f($d['shoulders']['left'] ?? 0), 'x1' => $f($d['shoulders']['right'] ?? 1000)];
            if ($sh['y'] < $face['y1'] - 0.02) $sh = null;
        }
        return ['ok' => true, 'geom' => ['subject' => true, 'people' => max(1, (int) ($d['people'] ?? 1)), 'kind' => (string) ($d['kind'] ?? 'headshot'),
            'head' => $head, 'face' => $face, 'eyes' => $le && $re ? [$le, $re] : null, 'shoulders' => $sh]];
    }

    /** The photo as the model is sent it: at most 1024 px, JPEG. */
    private static function forModel(string $bytes): ?string
    {
        if (!function_exists('imagecreatefromstring')) return $bytes;
        $im = @imagecreatefromstring($bytes);
        if (!$im) return null;
        $w = imagesx($im); $h = imagesy($im); $s = min(1, 1024 / max($w, $h));
        $dst = imagecreatetruecolor(max(1, (int) round($w * $s)), max(1, (int) round($h * $s)));
        imagecopyresampled($dst, $im, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        ob_start(); imagejpeg($dst, null, 88); $out = (string) ob_get_clean();
        imagedestroy($im); imagedestroy($dst);
        return $out;
    }

    /* ══ 4–5. Store and enhance ══════════════════════════════════════════ */

    /**
     * Put a member's card photo on file.
     *
     * @param array $file a $_FILES entry
     * @return array{ok:bool, url?:string, error?:string, w?:int, h?:int}
     */
    public static function save(int $memberId, array $file, string $actor): array
    {
        if ($memberId <= 0 || !class_exists('Prefs')) return ['ok' => false, 'error' => 'No such member.'];
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'error' => 'No photo was chosen.'];
        if ($err !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'The photo did not upload.'];
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') return ['ok' => false, 'error' => 'That was not an uploaded file.'];
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) return ['ok' => false, 'error' => 'That photo is over 12 MB.'];
        $mime = Storage::mime($tmp);
        if (!in_array($mime, self::TYPES, true)) return ['ok' => false, 'error' => 'That has to be a photo — JPEG, PNG or WebP.'];

        $src = @imagecreatefromstring((string) file_get_contents($tmp));
        if (!$src) return ['ok' => false, 'error' => 'That photo could not be read.'];
        $sw = imagesx($src); $sh = imagesy($src);

        /* Forced to the panel's shape (cover, focus 50% / 30%) — the crop editor
           already sends it so, this is for anything that did not come through it. */
        $cw = $sw; $ch = (int) round($sw / self::ASPECT);
        if ($ch > $sh) { $ch = $sh; $cw = (int) round($sh * self::ASPECT); }
        if ($cw < self::MIN_W || $ch < self::MIN_H) { imagedestroy($src); return ['ok' => false, 'error' => self::TOO_SMALL, 'w' => $cw, 'h' => $ch]; }
        $sx = (int) round(($sw - $cw) * 0.5); $sy = (int) round(($sh - $ch) * 0.3);
        return self::store($memberId, $src, ['x' => $sx, 'y' => $sy, 'w' => $cw, 'h' => $ch], $actor, 'upload');
    }

    /**
     * Cut $src to $box (image pixels, the panel's shape), resample to at most
     * 600 dpi, re-encode and put it on file as the member's card photo.
     */
    private static function store(int $memberId, $src, array $box, string $actor, string $source): array
    {
        $W = min(self::SAVE_W, (int) $box['w']); $H = (int) round($W / self::ASPECT);
        $dst = imagecreatetruecolor($W, $H);
        imagecopyresampled($dst, $src, 0, 0, (int) $box['x'], (int) $box['y'], $W, $H, (int) $box['w'], (int) $box['h']);
        $out = tempnam(sys_get_temp_dir(), 'avcp');
        imagejpeg($dst, $out, 90);               // re-encoded: no EXIF, no location, no colour profile tricks
        imagedestroy($src); imagedestroy($dst);

        try {
            $put = Storage::put($out, 'card-' . $memberId . '.jpg', 'image', 'cards');
        } catch (Throwable $e) {
            @unlink($out);
            error_log('[card-photo] store: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The photo could not be stored.'];
        }
        @unlink($out);
        $url = self::deliver((string) ($put['url'] ?? ''));
        if ($url === '') return ['ok' => false, 'error' => 'The photo could not be stored.'];
        Prefs::set($memberId, 'card_photo', $url);
        Prefs::set($memberId, 'card_photo_source', $source);
        self::audit('card_photo_set', $memberId, $url . ' (' . $source . ')', $actor);
        return ['ok' => true, 'url' => $url, 'w' => $W, 'h' => $H];
    }

    /* ══ From Google ═════════════════════════════════════════════════════ */

    /**
     * The Google profile photo as the card photo, when the member has none
     * (owner, 2026-10-09: "speed the process"). Never replaces a photo
     * somebody chose. Only with Gemini: a Google photo is often the default
     * letter avatar, a logo or a group, and only a measurement that finds one
     * face can tell — without it, nothing is guessed onto an ID card.
     */
    public static function fromGoogle(int $memberId, string $picture): array
    {
        if ($memberId <= 0 || !class_exists('Prefs')) return ['ok' => false, 'why' => 'no_member'];
        if (trim(Prefs::get($memberId, 'card_photo', '')) !== '') return ['ok' => false, 'why' => 'has_photo'];
        /* Cards are members'. An Academy learner's Google photo is left alone. */
        $role = (string) (Database::pdo()->query('SELECT role FROM lms_users WHERE id = ' . $memberId)->fetchColumn() ?: '');
        if (!class_exists('LmsAuth') || LmsAuth::rank($role) < LmsAuth::rank('member')) return ['ok' => false, 'why' => 'not_member'];
        if (!preg_match('~^https://[a-z0-9.-]+\.googleusercontent\.com/~i', $picture)) return ['ok' => false, 'why' => 'not_google'];
        $mock = isset($GLOBALS['__card_photo_mock']) && is_callable($GLOBALS['__card_photo_mock']);
        if (!$mock && (!class_exists('Gemini') || !Gemini::configured())) return ['ok' => false, 'why' => 'ai_unavailable'];

        /* Google serves the photo at any size: "=s96-c" becomes "=s1200". */
        $big = preg_match('~=s\d+(-c)?$~', $picture) ? (string) preg_replace('~=s\d+(-c)?$~', '=s1200', $picture) : $picture . '=s1200';
        $bytes = isset($GLOBALS['__card_photo_fetch']) && is_callable($GLOBALS['__card_photo_fetch'])
            ? (string) ($GLOBALS['__card_photo_fetch'])($big)
            : (string) @file_get_contents($big, false, stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 1, 'max_redirects' => 2]]), 0, 6 * 1048576);
        if ($bytes === '') return ['ok' => false, 'why' => 'fetch_failed'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (!in_array($mime, self::TYPES, true)) return ['ok' => false, 'why' => 'not_a_photo'];

        $m = self::measure($bytes);
        $g = $m['geom'] ?? null;
        if (empty($m['ok']) || empty($g['subject']) || (int) ($g['people'] ?? 1) > 1 || !in_array((string) ($g['kind'] ?? ''), ['headshot', 'half'], true)) {
            return ['ok' => false, 'why' => 'no_single_face'];
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) return ['ok' => false, 'why' => 'unreadable'];
        $box = self::frame($g, imagesx($src), imagesy($src));
        if (!$box || $box['w'] < self::MIN_W || $box['h'] < self::MIN_H) { imagedestroy($src); return ['ok' => false, 'why' => 'too_small']; }
        /* Checked again just before writing: a photo the member uploaded while
           this ran is theirs, and wins. */
        if (trim(Prefs::get($memberId, 'card_photo', '')) !== '') { imagedestroy($src); return ['ok' => false, 'why' => 'has_photo']; }
        return self::store($memberId, $src, $box, 'google', 'google');
    }

    /**
     * NGG's head-and-shoulders rule (card-photo.jsx cphFrameAi; the same as
     * assets/site/avc-photo.js frameFor), in image pixels, at the panel's shape.
     */
    public static function frame(array $g, int $W, int $H): ?array
    {
        if (empty($g['subject']) || empty($g['head'])) return null;
        $hx0 = $g['head']['x0'] * $W; $hx1 = $g['head']['x1'] * $W; $hy0 = $g['head']['y0'] * $H; $hy1 = $g['head']['y1'] * $H;
        $headW = $hx1 - $hx0; $headH = $hy1 - $hy0;
        if ($headW <= 0 || $headH <= 0) return null;
        $fh = $headH / 0.56; $fw = $fh * self::ASPECT;
        $minW = max($headW * 1.05, $headW * 1.45); $minH = $headH * 1.1;
        if ($fw < $minW) { $fw = $minW; $fh = $fw / self::ASPECT; }
        if ($fh < $minH) { $fh = $minH; $fw = $fh * self::ASPECT; }
        $s = min(1, $W / $fw, $H / $fh); $fw *= $s; $fh *= $s;
        $cx = !empty($g['eyes']) && count($g['eyes']) === 2 ? ($g['eyes'][0]['x'] + $g['eyes'][1]['x']) / 2 * $W : ($hx0 + $hx1) / 2;
        $x = $cx - $fw / 2; $y = $hy0 - 0.11 * $fh;
        if ($fw >= $headW) $x = min($hx0, max($hx1 - $fw, $x));
        if ($fh >= $headH) $y = min($hy0, max($hy1 - $fh, $y));
        $x = min(max(0, $x), $W - $fw); $y = min(max(0, $y), $H - $fh);
        return ['x' => (int) round($x), 'y' => (int) round($y), 'w' => (int) floor($fw), 'h' => (int) floor($fh)];
    }

    /**
     * The URL the card uses. A Cloudinary photo is delivered improved
     * (e_improve, as NGG's cloudinary_edit) and, when the owner switches it on,
     * with its background removed — a Cloudinary add-on that costs credits, so
     * off by default. A local photo is delivered as stored.
     */
    public static function deliver(string $url): string
    {
        if (!preg_match('~^https://res\.cloudinary\.com/[^/]+/image/upload/~', $url)) return $url;
        $t = [];
        if (self::rule('cards.photo_enhance', true)) $t[] = 'e_improve:30';
        if (self::rule('cards.photo_bg_removal', false)) $t[] = 'e_background_removal';
        if (!$t) return $url;
        $t[] = 'f_png';   // keeps a removed background transparent; harmless otherwise
        return (string) preg_replace('~/image/upload/~', '/image/upload/' . implode('/', $t) . '/', $url, 1);
    }

    public static function clear(int $memberId, string $actor): array
    {
        if ($memberId <= 0 || !class_exists('Prefs')) return ['ok' => false, 'error' => 'No such member.'];
        Prefs::set($memberId, 'card_photo', '');
        self::audit('card_photo_cleared', $memberId, '', $actor);
        return ['ok' => true];
    }

    /** The line under the name ("Chief Servant"). Never their system role. */
    public static function setRole(int $memberId, string $role, string $actor): array
    {
        if ($memberId <= 0 || !class_exists('Prefs')) return ['ok' => false, 'error' => 'No such member.'];
        $role = trim((string) preg_replace('/\s+/', ' ', $role));
        if (mb_strlen($role) > 40) return ['ok' => false, 'error' => 'Keep the title to 40 characters — it is one line on the card.'];
        Prefs::set($memberId, 'card_role', $role);
        self::audit('card_role_set', $memberId, $role, $actor);
        return ['ok' => true, 'role' => $role];
    }

    public static function of(int $memberId): array
    {
        if (!class_exists('Prefs')) return ['photo' => '', 'role' => ''];
        return ['photo' => trim(Prefs::get($memberId, 'card_photo', '')), 'role' => trim(Prefs::get($memberId, 'card_role', ''))];
    }

    private static function rule(string $k, bool $default): bool
    {
        try { return class_exists('AvRules') ? AvRules::bool($k) : $default; } catch (Throwable $e) { return $default; }
    }

    private static function audit(string $action, int $memberId, string $detail, string $actor): void
    {
        if (!class_exists('AdminAudit')) return;
        try { AdminAudit::log('members', $action, (string) $memberId, mb_substr($detail, 0, 200), null, $actor); } catch (Throwable $e) {}
    }
}
