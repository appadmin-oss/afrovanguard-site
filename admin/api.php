<?php
/**
 * admin/api.php — authenticated Studio API (Diary + Academy).
 *
 * Auth: a signed httpOnly session cookie (preferred) or a Bearer admin
 * token (break-glass). State-changing requests under cookie auth must also
 * send a valid X-CSRF-Token header.
 *
 *   POST ?action=login   {token}      → set session cookie, return CSRF
 *   POST ?action=logout                → clear session
 *   GET  ?action=session               → auth state + fresh CSRF + flags
 *
 *   GET  ?action=list|get|categories|articles|enrollments
 *   POST ?action=save|delete|upload
 *   GET  ?action=ac_list|ac_get|ac_categories
 *   POST ?action=ac_save|ac_delete
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Cloudinary.php';
require_once AV_ROOT . '/lib/Embeds.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');
$isUpload = $action === 'upload';
$body = [];
if ($method === 'POST' && !$isUpload) { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

try {
    // ---- Unauthenticated: login / session probe ----
    if ($action === 'login') {
        if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
        if (!av_rate_ok('admin_login', 8, 900)) json_out(['ok' => false, 'error' => 'Too many attempts. Try again later.'], 429);
        if (!defined('ADMIN_TOKEN') || strlen((string) ADMIN_TOKEN) < 8) json_out(['ok' => false, 'error' => 'Admin is not configured.'], 503);
        $tok = (string) ($body['token'] ?? '');
        if ($tok === '' || !hash_equals((string) ADMIN_TOKEN, $tok)) json_out(['ok' => false, 'error' => 'Invalid token.'], 401);
        av_admin_cookie_issue();
        json_out(['ok' => true, 'csrf' => av_csrf_token(), 'cloudinary' => Cloudinary::configured()]);
    }
    if ($action === 'logout') { av_admin_cookie_clear(); json_out(['ok' => true]); }
    if ($action === 'session') {
        $authed = av_admin_cookie_valid() || av_admin_bearer_ok();
        json_out(['ok' => $authed, 'csrf' => $authed ? av_csrf_token() : '', 'cloudinary' => Cloudinary::configured()]);
    }

    // ---- Everything else requires admin ----
    require_admin();
    // CSRF for state-changing requests under cookie auth (Bearer is itself a secret).
    $writing = in_array($action, ['save', 'delete', 'upload', 'ac_save', 'ac_delete', 'mod_save', 'mod_delete', 'lesson_save', 'lesson_delete'], true);
    if ($writing && !av_admin_bearer_ok()) av_csrf_require();

    $repo = new DiaryRepository();
    $ac   = new AcademyRepository();
    $lms  = new LmsRepository();

    switch ($action) {
        case 'ping':         json_out(['ok' => true, 'cloudinary' => Cloudinary::configured()]);
        case 'list':         json_out(['ok' => true, 'articles' => $repo->allForAdmin()]);
        case 'categories':   json_out(['ok' => true, 'categories' => $repo->categories()]);
        case 'articles':     json_out(['ok' => true, 'articles' => array_map(fn($a) => ['slug' => $a['slug'], 'title' => $a['title']], $repo->allForAdmin())]);
        case 'enrollments':  json_out(['ok' => true, 'enrollments' => Database::pdo()->query('SELECT * FROM enrollments ORDER BY created_at DESC LIMIT 200')->fetchAll()]);
        case 'subscribers':  json_out(['ok' => true, 'subscribers' => Database::pdo()->query('SELECT email, source, created_at FROM subscribers ORDER BY created_at DESC LIMIT 500')->fetchAll(), 'count' => (int) Database::pdo()->query('SELECT COUNT(*) FROM subscribers')->fetchColumn()]);

        case 'get':
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
            $a = $slug ? $repo->getRaw($slug) : null;
            if (!$a) json_out(['ok' => false, 'error' => 'Not found.'], 404);
            json_out(['ok' => true, 'article' => $a]);

        case 'upload':
            if ($method !== 'POST' || empty($_FILES['file'])) json_out(['ok' => false, 'error' => 'No file.'], 400);
            $f = $_FILES['file'];
            if ($f['error'] !== UPLOAD_ERR_OK) json_out(['ok' => false, 'error' => 'Upload error.'], 400);
            if ($f['size'] > 10 * 1024 * 1024) json_out(['ok' => false, 'error' => 'Max 10 MB.'], 413);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'], true)) json_out(['ok' => false, 'error' => 'Images only.'], 415);
            $res = Cloudinary::upload($f['tmp_name'], $f['name']);
            json_out(['ok' => true, 'url' => $res['url'], 'location' => $res['url'], 'provider' => $res['provider']]);

        case 'save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $title = trim((string) ($body['title'] ?? ''));
            if ($title === '') json_out(['ok' => false, 'error' => 'A title is required.'], 422);
            // Embed providers, then sanitize, then derive the TOC.
            $raw = (string) ($body['body_html'] ?? '');
            $clean = Embeds::sanitize(Embeds::embedify($raw));
            [$cleanBody, $sections] = extract_sections($clean);
            $words = str_word_count(strip_tags($cleanBody));
            $read = max(1, (int) ($body['read_minutes'] ?? 0)) ?: max(1, (int) round($words / 200));
            $pubTs = strtotime(trim((string) ($body['published_at'] ?? ''))) ?: time();
            $slug = $repo->save([
                'slug' => trim((string) ($body['slug'] ?? '')) ?: $title, 'title' => $title,
                'dek' => trim((string) ($body['dek'] ?? '')), 'category' => trim((string) ($body['category'] ?? 'Dispatch')),
                'authors_html' => trim((string) ($body['authors_html'] ?? 'The Afrovanguard Team')),
                'published' => date('M j, Y', $pubTs), 'published_at' => date('Y-m-d', $pubTs),
                'read_minutes' => $read, 'gradient' => trim((string) ($body['gradient'] ?? 'g-gold')),
                'mc_title' => trim((string) ($body['mc_title'] ?? $title)), 'cover_url' => trim((string) ($body['cover_url'] ?? '')),
                'og_image' => trim((string) ($body['og_image'] ?? '')), 'body_html' => $cleanBody,
                'featured' => !empty($body['featured']), 'status' => ($body['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
                'format' => (string) ($body['format'] ?? 'standard'),
                'sections' => $sections, 'related' => array_values(array_filter((array) ($body['related'] ?? []))),
            ]);
            Sitemap::rebuild();
            json_out(['ok' => true, 'slug' => $slug, 'url' => diary_url($slug . '/'), 'sections' => $sections]);

        case 'delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $okd = $repo->delete(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['slug'] ?? ''))));
            if ($okd) Sitemap::rebuild();
            json_out(['ok' => $okd]);

        /* ── Academy ── */
        case 'ac_list':       json_out(['ok' => true, 'courses' => $ac->allForAdmin()]);
        case 'ac_categories': json_out(['ok' => true, 'categories' => $ac->categories()]);
        case 'ac_get':
            $cs = $ac->bySlug(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? ''))), true);
            if (!$cs) json_out(['ok' => false, 'error' => 'Not found.'], 404);
            $cs['instructor_email'] = '';
            if (!empty($cs['instructor_id'])) {
                $iu = Database::pdo()->prepare('SELECT email FROM lms_users WHERE id = ?');
                $iu->execute([(int) $cs['instructor_id']]);
                $cs['instructor_email'] = (string) ($iu->fetchColumn() ?: '');
            }
            json_out(['ok' => true, 'course' => $cs]);
        case 'ac_save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $t = trim((string) ($body['title'] ?? ''));
            if ($t === '') json_out(['ok' => false, 'error' => 'A title is required.'], 422);
            $cbody = Embeds::sanitize(Embeds::embedify((string) ($body['body_html'] ?? '')));
            $fields = [
                'slug' => trim((string) ($body['slug'] ?? '')) ?: $t, 'title' => $t,
                'summary' => trim((string) ($body['summary'] ?? '')), 'body_html' => $cbody,
                'cover_url' => trim((string) ($body['cover_url'] ?? '')), 'og_image' => trim((string) ($body['og_image'] ?? '')),
                'category' => trim((string) ($body['category'] ?? 'Programme')), 'level' => trim((string) ($body['level'] ?? 'All levels')),
                'format' => trim((string) ($body['format'] ?? 'In-person')), 'duration' => trim((string) ($body['duration'] ?? '')),
                'price' => trim((string) ($body['price'] ?? 'Free')), 'location' => trim((string) ($body['location'] ?? 'Alimosho, Lagos')),
                'gradient' => trim((string) ($body['gradient'] ?? 'g-gold')), 'outcomes' => trim((string) ($body['outcomes'] ?? '')),
                'cta_url' => trim((string) ($body['cta_url'] ?? '')), 'featured' => !empty($body['featured']),
                'status' => ($body['status'] ?? 'draft') === 'published' ? 'published' : 'draft', 'sort' => (int) ($body['sort'] ?? 0),
                'access_type' => (string) ($body['access_type'] ?? 'open'), 'price_ngn' => (int) ($body['price_ngn'] ?? 0),
            ];
            // Resolve an instructor by email (must already have an Academy account).
            $instructorMsg = null;
            if (array_key_exists('instructor_email', $body)) {
                $iem = strtolower(trim((string) $body['instructor_email']));
                if ($iem === '') { $fields['instructor_id'] = null; }
                else {
                    $iu = $lms->findUserByEmail($iem);
                    if ($iu) { $lms->promoteToInstructor((int) $iu['id']); $fields['instructor_id'] = (int) $iu['id']; }
                    else { $instructorMsg = 'Saved, but no Academy account exists for ' . $iem . ' yet — ask them to create one, then re-save to assign.'; }
                }
            }
            $slug = $ac->save($fields);
            Sitemap::rebuild();
            json_out(['ok' => true, 'slug' => $slug, 'url' => rtrim(SITE_URL, '/') . '/academy/' . $slug . '/', 'notice' => $instructorMsg]);
        case 'ac_delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $okd = $ac->delete(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['slug'] ?? ''))));
            if ($okd) Sitemap::rebuild();
            json_out(['ok' => $okd]);

        /* ── Curriculum authoring (modules + lessons) ── */
        case 'ac_curriculum':
            $cs = $ac->bySlug(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? ''))), true);
            if (!$cs) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            json_out(['ok' => true, 'course' => ['slug' => $cs['slug'], 'title' => $cs['title']], 'modules' => $lms->curriculum((int) $cs['id'])]);
        case 'mod_save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $title = trim((string) ($body['title'] ?? '')); if ($title === '') json_out(['ok' => false, 'error' => 'Title required.'], 422);
            if (!empty($body['id'])) { $lms->renameModule((int) $body['id'], $title); json_out(['ok' => true, 'id' => (int) $body['id']]); }
            $cs = $ac->bySlug(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['course'] ?? ''))), true);
            if (!$cs) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            json_out(['ok' => true, 'id' => $lms->addModule((int) $cs['id'], $title)]);
        case 'mod_delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $lms->deleteModule((int) ($body['id'] ?? 0)); json_out(['ok' => true]);
        case 'lesson_get':
            $l = $lms->lessonById((int) ($_GET['id'] ?? 0));
            if (!$l) json_out(['ok' => false, 'error' => 'Not found.'], 404);
            json_out(['ok' => true, 'lesson' => $l]);
        case 'lesson_save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (trim((string) ($body['title'] ?? '')) === '') json_out(['ok' => false, 'error' => 'A title is required.'], 422);
            $lbody = Embeds::sanitize(Embeds::embedify((string) ($body['body_html'] ?? '')));
            // Validate + normalise the optional quiz
            $quizJson = null;
            if (!empty($body['quiz']) && is_array($body['quiz']) && !empty($body['quiz']['questions'])) {
                $qs = [];
                foreach ($body['quiz']['questions'] as $q) {
                    $prompt = trim((string) ($q['q'] ?? ''));
                    $opts = array_values(array_filter(array_map(fn($o) => trim((string) $o), (array) ($q['options'] ?? [])), fn($o) => $o !== ''));
                    if ($prompt === '' || count($opts) < 2) continue;
                    $ans = max(0, min(count($opts) - 1, (int) ($q['answer'] ?? 0)));
                    $qs[] = ['q' => $prompt, 'options' => $opts, 'answer' => $ans];
                }
                if ($qs) $quizJson = json_encode(['pass' => max(1, min(100, (int) ($body['quiz']['pass'] ?? 70))), 'questions' => $qs]);
            }
            $id = $lms->saveLesson([
                'id' => (int) ($body['id'] ?? 0), 'module_id' => (int) ($body['module_id'] ?? 0),
                'slug' => trim((string) ($body['slug'] ?? '')), 'title' => trim((string) $body['title']),
                'body_html' => $lbody, 'video_url' => trim((string) ($body['video_url'] ?? '')),
                'duration_min' => (int) ($body['duration_min'] ?? 0), 'is_preview' => !empty($body['is_preview']),
                'quiz_json' => $quizJson,
            ]);
            json_out(['ok' => true, 'id' => $id]);
        case 'lesson_delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $lms->deleteLesson((int) ($body['id'] ?? 0)); json_out(['ok' => true]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $ex) {
    error_log('[admin api] ' . $ex->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $ex->getMessage())], 500);
}
