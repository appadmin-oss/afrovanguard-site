<?php
/**
 * diary/audio.php — download the FULL narration of a Diary article as one file.
 *
 *   /diary/audio.php?slug=<article>       → article.mp3 (attachment)
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

// Only reliable MP3 engines can export a single stitched file (the mock engine
// emits WAV tones, which can't be naively concatenated).
if (!Tts::available() || Tts::engine() === 'mock' || Tts::ext() !== 'mp3') {
    audio_fail(503, 'Audio download isn’t available for this article.');
}

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
if ($slug === '') audio_fail(400, 'Missing article.');

// Building a full article is heavier than one sentence — a tighter limit.
if (!av_rate_ok('tts_export', 20, 600)) audio_fail(429, 'Too many downloads — try again shortly.');

$article = (new DiaryRepository())->bySlug($slug);   // published only
if (!$article) audio_fail(404, 'Article not found.');

// Plain reading text: title, then the body with tags/entities stripped.
$plain = trim(html_entity_decode(strip_tags(
    ($article['title'] ?? '') . ". \n" . ($article['body_html'] ?? '')
), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$plain = preg_replace('/[ \t]+/u', ' ', preg_replace('/\R+/u', "\n", $plain));
if ($plain === '') audio_fail(422, 'Nothing to read.');

// Split into ≤ ~450-char passages at sentence boundaries (keeps each API call
// small and lines up with the per-sentence cache used by the on-page reader).
$parts = [];
foreach (preg_split('/(?<=[.!?])\s+/u', $plain) ?: [$plain] as $sentence) {
    $sentence = trim($sentence);
    if ($sentence === '') continue;
    if (mb_strlen($sentence) <= 450) { $parts[] = $sentence; continue; }
    foreach (str_split($sentence, 450) as $piece) { $piece = trim($piece); if ($piece !== '') $parts[] = $piece; }
}
// Bound total work so one request can't run away (≈ a long-form feature).
$MAX_PARTS = 150;
$truncated = count($parts) > $MAX_PARTS;
if ($truncated) $parts = array_slice($parts, 0, $MAX_PARTS);
if (!$parts) audio_fail(422, 'Nothing to read.');

@set_time_limit(0);
$dir = AV_ROOT . '/data/tts';
if (!is_dir($dir)) @mkdir($dir, 0775, true);

// Whole-file cache, keyed by engine + voice + the exact passage set.
$fullKey = hash('sha256', 'export|' . Tts::engine() . '|' . Tts::voice() . '|' . implode('␟', $parts));
$fullPath = $dir . '/export-' . $fullKey . '.mp3';

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
header('Content-Length: ' . strlen($data));
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: public, max-age=86400');
echo $data;
