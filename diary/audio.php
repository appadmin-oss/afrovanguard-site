<?php
/**
 * diary/audio.php — the FULL narration of a Diary article as one file.
 *
 *   /diary/audio.php?slug=<article>          → article.mp3 (attachment)
 *   /diary/audio.php?slug=<article>&play=1   → the same file, inline, seekable
 *   /diary/audio.php?slug=<article>&meta=1   → {src, seconds, chapters} as JSON
 *
 * Only available when a reliable neural engine is configured (ElevenLabs /
 * OpenAI — not the built-in "mock" tone). The article is split into passages,
 * each synthesised via lib/Tts.php and cached on disk by the SAME content hash
 * the per-sentence reader uses (so anything already played is reused for free),
 * then the MP3 parts are concatenated into a single downloadable file — itself
 * cached by the article's content hash so the whole file is built only once.
 *
 * Anti-abuse: published articles only, rate-limited, and bounded in length.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('X-Content-Type-Options: nosniff');

function audio_fail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
if ($slug === '') audio_fail(400, 'Missing article.');

// Only reliable MP3 engines can export a single stitched file (the mock engine
// emits WAV tones, which can't be naively concatenated). Checked below rather
// than here, because an entry with author-uploaded narration needs none of it.
$ttsOk = Tts::available() && Tts::engine() !== 'mock' && Tts::ext() === 'mp3';

$article = (new DiaryRepository())->bySlug($slug);   // published only
if (!$article) audio_fail(404, 'Article not found.');

$play = !empty($_GET['play']);
$meta = !empty($_GET['meta']);

/**
 * Seconds in an MP3, by walking its frame headers. Exact for the files this
 * script builds, because it builds them by concatenating frames.
 *
 * Returns 0 when the file does not parse as MP3 frames, which the caller reads
 * as "I do not know" rather than "zero seconds long".
 */
function audio_mp3_seconds(string $path): float
{
    $fh = @fopen($path, 'rb');
    if (!$fh) return 0.0;
    $rates = [[44100, 48000, 32000], [22050, 24000, 16000], [11025, 12000, 8000]];
    $bits  = [
        3 => [0,32,40,48,56,64,80,96,112,128,160,192,224,256,320,0],   // MPEG1 Layer III
        2 => [0,8,16,24,32,40,48,56,64,80,96,112,128,144,160,0],       // MPEG2/2.5 Layer III
    ];
    $total = 0.0; $guard = 0;
    while (!feof($fh) && $guard++ < 500000) {
        $h = fread($fh, 4);
        if ($h === false || strlen($h) < 4) break;
        $b = array_values(unpack('C4', $h));
        if ($b[0] !== 0xFF || ($b[1] & 0xE0) !== 0xE0) {           // not a frame sync: resync by one byte
            fseek($fh, -3, SEEK_CUR);
            continue;
        }
        $verBits = ($b[1] >> 3) & 0x03;                             // 3 = MPEG1, 2 = MPEG2, 0 = MPEG2.5
        $layer   = ($b[1] >> 1) & 0x03;                             // 1 = Layer III
        $brIdx   = ($b[2] >> 4) & 0x0F;
        $srIdx   = ($b[2] >> 2) & 0x03;
        $pad     = ($b[2] >> 1) & 0x01;
        if ($layer !== 1 || $verBits === 1 || $srIdx === 3 || $brIdx === 0 || $brIdx === 15) { fseek($fh, -3, SEEK_CUR); continue; }
        $rateRow = $verBits === 3 ? 0 : ($verBits === 2 ? 1 : 2);
        $rate    = $rates[$rateRow][$srIdx];
        $kbps    = $bits[$verBits === 3 ? 3 : 2][$brIdx] * 1000;
        $samples = $verBits === 3 ? 1152 : 576;
        $len     = (int) floor(($samples / 8) * $kbps / $rate) + $pad;
        if ($len < 4) break;
        $total  += $samples / $rate;
        fseek($fh, $len - 4, SEEK_CUR);
    }
    fclose($fh);
    return $total;
}

/**
 * The entry's H2s as chapters, each with the second it starts at.
 *
 * Offsets come from the running word count at a narration pace, scaled to the
 * real duration once the file exists. Before then they are an estimate, and
 * the response says so in `estimated` rather than presenting a guess as a
 * measurement — a reader who taps chapter four lands near its start, not on it.
 */
function audio_chapters(string $html, float $realSeconds, int $estSeconds): array
{
    $parts = preg_split('~(?=<h2\b)~i', $html) ?: [];
    $chapters = []; $words = 0;
    foreach ($parts as $i => $part) {
        $text = trim(html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($i > 0 && preg_match('~<h2[^>]*>(.*?)</h2>~is', $part, $m)) {
            $chapters[] = ['t' => $words, 'title' => trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))];
        }
        $words += str_word_count($text);
    }
    if (!$chapters || $words < 1) return [];
    $seconds = $realSeconds > 0 ? $realSeconds : (float) $estSeconds;
    foreach ($chapters as &$c) $c['t'] = (int) round($c['t'] / $words * $seconds);
    unset($c);
    return $chapters;
}

// Plain reading text: title, then the body with tags/entities stripped.
/**
 * The article as ≤ ~450-character passages at sentence boundaries: small enough
 * for one API call each, and keyed the same way the per-sentence reader keys
 * them so anything already synthesised is reused for free.
 */
function audio_passages(array $article): array
{
    $plain = trim(html_entity_decode(strip_tags(
        ($article['title'] ?? '') . ". \n" . ($article['body_html'] ?? '')
    ), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $plain = preg_replace('/[ \t]+/u', ' ', preg_replace('/\R+/u', "\n", $plain));
    if ($plain === '') return [];

    $parts = [];
    foreach (preg_split('/(?<=[.!?])\s+/u', $plain) ?: [$plain] as $sentence) {
        $sentence = trim($sentence);
        if ($sentence === '') continue;
        if (mb_strlen($sentence) <= 450) { $parts[] = $sentence; continue; }
        foreach (str_split($sentence, 450) as $piece) { $piece = trim($piece); if ($piece !== '') $parts[] = $piece; }
    }
    // Bound total work so one request can't run away (≈ a long-form feature).
    return array_slice($parts, 0, 150);
}

/** Where the stitched file for this article lives — whether or not it exists. */
function audio_export_path(array $article, string $slug): string
{
    $parts = audio_passages($article);
    if (!$parts) return '';
    $key = hash('sha256', 'export|' . Tts::engine() . '|' . Tts::voice() . '|' . implode('␟', $parts));
    return AV_ROOT . '/data/tts/export-' . $key . '.mp3';
}

$narration = trim((string) ($article['audio_url'] ?? ''));

if ($meta) {
    // Cheap by construction: it reads the article row and, when the stitched
    // file already exists, that file's frame headers. It never synthesises.
    $words   = max(1, str_word_count(strip_tags((string) $article['title'] . ' ' . (string) $article['body_html'])));
    $estimate = (int) max(30, round($words / DiaryRepository::NARRATION_WPM * 60));

    if ($narration !== '') {
        /* A human recording. Chapter offsets are only worth offering when the
           real duration is known: a list timed against a word-count estimate
           sends a reader who taps "What a cohort costs" to 1:54 of a recording
           that reaches it at 4:20, which is worse than no list at all.
           The file is measurable when it is an MP3 on this server. Otherwise
           the bar shows the entry's title and no chapters, and takes the real
           duration from the audio element as it loads. */
        $local = (str_starts_with($narration, '/') && !str_starts_with($narration, '//'))
            ? realpath(AV_ROOT . parse_url($narration, PHP_URL_PATH)) : false;
        $inRoot = $local !== false && str_starts_with($local, realpath(AV_ROOT) . DIRECTORY_SEPARATOR);
        $real = ($inRoot && strtolower((string) pathinfo($local, PATHINFO_EXTENSION)) === 'mp3')
            ? audio_mp3_seconds($local) : 0.0;
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, max-age=600');
        echo json_encode([
            'ok'        => true,
            'src'       => $narration,
            'seconds'   => $real > 0 ? (int) round($real) : $estimate,
            'estimated' => $real <= 0,
            'chapters'  => $real > 0 ? audio_chapters((string) $article['body_html'], $real, $estimate) : [],
        ]);
        exit;
    }
    if (!$ttsOk) audio_fail(503, 'Audio isn’t available for this article.');

    $cached = audio_export_path($article, $slug);
    $real   = ($cached !== '' && is_file($cached)) ? audio_mp3_seconds($cached) : 0.0;
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=600');
    echo json_encode([
        'ok'        => true,
        'src'       => '/diary/audio.php?slug=' . rawurlencode($slug) . '&play=1',
        'seconds'   => $real > 0 ? (int) round($real) : $estimate,
        'estimated' => $real <= 0,
        'chapters'  => audio_chapters((string) $article['body_html'], $real, $estimate),
    ]);
    exit;
}

if (!$ttsOk) {
    if ($narration !== '') { header('Location: ' . $narration, true, 302); exit; }
    audio_fail(503, 'Audio download isn’t available for this article.');
}

// Building a full article is heavier than one sentence — a tighter limit.
if (!av_rate_ok('tts_export', 20, 600)) audio_fail(429, 'Too many downloads — try again shortly.');

$parts = audio_passages($article);
if (!$parts) audio_fail(422, 'Nothing to read.');

@set_time_limit(0);
$dir = AV_ROOT . '/data/tts';
if (!is_dir($dir)) @mkdir($dir, 0775, true);

$fullPath = audio_export_path($article, $slug);

if (!is_file($fullPath) || filesize($fullPath) === 0) {
    $norm = static fn(string $s): string => trim(preg_replace('/\s+/u', ' ', mb_strtolower($s)));
    $buf = '';
    foreach ($parts as $p) {
        // Same key scheme as diary/tts.php → reuse sentences already synthesised.
        $partPath = $dir . '/' . hash('sha256', Tts::engine() . '|' . Tts::voice() . '|' . $norm($p)) . '.mp3';
        $bytes = is_file($partPath) ? (string) file_get_contents($partPath) : '';
        if ($bytes === '') {
            $bytes = (string) (Tts::synthesize($p) ?? '');
            if ($bytes !== '') {
                $tmp = $partPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (@file_put_contents($tmp, $bytes) !== false) @rename($tmp, $partPath);
            }
        }
        if ($bytes !== '') $buf .= $bytes;   // MP3 frames concatenate cleanly
    }
    if ($buf === '') audio_fail(502, 'Could not generate the audio.' . (av_is_prod() ? '' : ' [' . Tts::lastError() . ']'));
    $tmp = $fullPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $buf) !== false) @rename($tmp, $fullPath);
    $data = $buf;
} else {
    $data = (string) file_get_contents($fullPath);
}

$fname = ($slug ?: 'afrovanguard-diary') . '.mp3';
header('Content-Type: audio/mpeg');
header('Cache-Control: public, max-age=86400');
header('Accept-Ranges: bytes');

if (!$play) {
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    echo $data;
    exit;
}

/* The docked player seeks, and seeking without byte ranges means the browser
   must hold the whole narration before the scrubber does anything — which on a
   phone on Nigerian mobile data is the difference between a player and a wait.
   One range, which is all any browser asks for. */
header('Content-Disposition: inline; filename="' . $fname . '"');
$len   = strlen($data);
$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
    $start = $m[1] === '' ? null : (int) $m[1];
    $end   = $m[2] === '' ? null : (int) $m[2];
    if ($start === null) {                       // bytes=-N → the last N bytes
        $start = max(0, $len - (int) $end);
        $end   = $len - 1;
    } else {
        $end = $end === null ? $len - 1 : min($end, $len - 1);
    }
    if ($start > $end || $start >= $len) {
        http_response_code(416);
        header('Content-Range: bytes */' . $len);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $len);
    header('Content-Length: ' . ($end - $start + 1));
    echo substr($data, $start, $end - $start + 1);
    exit;
}
header('Content-Length: ' . $len);
echo $data;
