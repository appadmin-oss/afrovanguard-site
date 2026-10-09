<?php
/**
 * card/photo.php — a member puts their own photo on their card (owner, 2026-10-09).
 *
 * The same pipeline as the member desk (lib/CardPhoto.php, assets/site/avc-photo.js):
 * Gemini finds the head, NGG's head-and-shoulders rule frames it, the member
 * adjusts it, and the server re-checks, re-encodes and stores it. A member can
 * only ever touch THEIR card: the id is the session's, never the request's.
 *
 *   POST ?action=measure   file      → {ok, geom}
 *   POST ?action=save      file      → {ok, url} | {ok:false, error}
 *   POST ?action=clear               → {ok}
 * Header X-CSRF-Token: av_csrf_token(). Twenty changes an hour.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
$me = LmsAuth::user();
if (!$me) json_out(['ok' => false, 'error' => 'Sign in to change your card photo.'], 401);
if (LmsAuth::rank((string) ($me['role'] ?? 'learner')) < LmsAuth::rank('member')) json_out(['ok' => false, 'error' => 'Card photos are for members.'], 403);
av_csrf_require();

$id = (int) $me['id'];
$action = (string) ($_GET['action'] ?? '');
if (!av_rate_ok('card_photo_self_' . $id, $action === 'measure' ? 40 : 20, 3600)) json_out(['ok' => false, 'error' => 'That is a lot of changes for one hour. Try again later.'], 429);

switch ($action) {
    case 'measure':
        if (empty($_FILES['file']) || (int) $_FILES['file']['size'] > 4 * 1048576) json_out(['ok' => false, 'error' => 'No photo.'], 400);
        json_out(CardPhoto::measure((string) file_get_contents((string) $_FILES['file']['tmp_name'])));
    case 'save':
        if (empty($_FILES['file'])) json_out(['ok' => false, 'error' => 'No photo.'], 400);
        json_out(CardPhoto::save($id, $_FILES['file'], 'self:' . (string) ($me['email'] ?? $id)));
    case 'clear':
        json_out(CardPhoto::clear($id, 'self:' . (string) ($me['email'] ?? $id)));
}
json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
