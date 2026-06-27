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
        if (!defined('ADMIN_TOKEN') || strlen((string) ADMIN_TOKEN) < 8) json_out(['ok' => false, 'error' => 'Admin isn’t configured. Set AV_ADMIN_TOKEN (a random string, 8+ characters) via .htaccess SetEnv or config.php, then reload.'], 503);
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
    $writing = in_array($action, ['save', 'delete', 'upload', 'ac_save', 'ac_delete', 'mod_save', 'mod_delete', 'mod_approve', 'mod_reject', 'lesson_save', 'lesson_delete', 'team_save', 'team_delete', 'cel_save', 'cel_delete', 'art_save', 'art_delete', 'mem_save', 'mem_create', 'comm_save', 'comm_delete', 'wh_save', 'wh_delete', 'wh_test', 'auth_policy_save', 'apptoken_create', 'apptoken_revoke', 'mail_test'], true);
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

        // ---- People / Team directory ----
        case 'team_list':
            require_once AV_ROOT . '/lib/people.php';
            json_out(['ok' => true, 'team' => array_map('av_team_member_dict', av_team_rows(Database::pdo(), false))]);
        case 'team_get':
            require_once AV_ROOT . '/lib/people.php';
            $tp = av_team_one(Database::pdo(), (int) ($_GET['id'] ?? 0));
            if (($tp['status'] ?? '') !== 'ok') json_out(['ok' => false, 'error' => 'Not found.'], 404);
            json_out(['ok' => true, 'member' => $tp['member']]);
        case 'team_save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_once AV_ROOT . '/lib/people.php';
            if (trim((string) ($body['name'] ?? '')) === '') json_out(['ok' => false, 'error' => 'A name is required.'], 422);
            $tid = av_team_save(Database::pdo(), $body);
            json_out(['ok' => true, 'id' => $tid]);
        case 'team_delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_once AV_ROOT . '/lib/people.php';
            av_team_delete(Database::pdo(), (int) ($body['id'] ?? 0));
            json_out(['ok' => true]);

        // ---- Celebrations (custom dates + uploaded doodle art) ----
        case 'cel_list':
            require_once AV_ROOT . '/lib/celebrations.php';
            json_out(['ok' => true, 'celebrations' => av_celebrations_all(Database::pdo()), 'builtins' => av_celebration_calendar()]);
        case 'cel_save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_once AV_ROOT . '/lib/celebrations.php';
            if (trim((string) ($body['name'] ?? '')) === '' || !preg_match('/^\d{2}-\d{2}$/', (string) ($body['md'] ?? ''))) {
                json_out(['ok' => false, 'error' => 'A name and a date (MM-DD) are required.'], 422);
            }
            json_out(['ok' => true, 'id' => av_celebrations_save(Database::pdo(), $body)]);
        case 'cel_delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_once AV_ROOT . '/lib/celebrations.php';
            av_celebrations_delete(Database::pdo(), (int) ($body['id'] ?? 0));
            json_out(['ok' => true]);

        // ---- Communities (Google Chat Spaces / Groups shown in the member portal) ----
        case 'comm_list':
            require_once AV_ROOT . '/lib/workspace.php';
            json_out(['ok' => true, 'communities' => av_communities_all(Database::pdo())]);
        case 'comm_save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_once AV_ROOT . '/lib/workspace.php';
            if (trim((string) ($body['name'] ?? '')) === '') json_out(['ok' => false, 'error' => 'A name is required.'], 422);
            if (!preg_match('#^https://[^\s]+$#i', trim((string) ($body['url'] ?? '')))) json_out(['ok' => false, 'error' => 'A valid https:// link is required.'], 422);
            json_out(['ok' => true, 'id' => av_communities_save(Database::pdo(), $body)]);
        case 'comm_delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_once AV_ROOT . '/lib/workspace.php';
            av_communities_delete(Database::pdo(), (int) ($body['id'] ?? 0));
            json_out(['ok' => true]);

        // ---- Webhooks (outbound integrations) ----
        case 'wh_list':
            json_out(['ok' => true, 'endpoints' => Webhooks::endpointsAll(), 'deliveries' => Webhooks::recentDeliveries(25), 'events' => Events::catalog()]);
        case 'wh_save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!preg_match('#^https?://[^\s]+$#i', trim((string) ($body['url'] ?? '')))) json_out(['ok' => false, 'error' => 'A valid http(s):// URL is required.'], 422);
            json_out(['ok' => true, 'id' => Webhooks::endpointSave($body)]);
        case 'wh_delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            Webhooks::endpointDelete((int) ($body['id'] ?? 0));
            json_out(['ok' => true]);
        case 'wh_test':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            json_out(['ok' => true, 'result' => Webhooks::sendTest((int) ($body['id'] ?? 0))]);

        // ---- API tokens for integrations (inbound) ----
        case 'apptoken_list':
            json_out(['ok' => true, 'tokens' => AppTokens::all(), 'scopes' => AppTokens::SCOPES]);
        case 'apptoken_create':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            json_out(['ok' => true] + AppTokens::issue((string) ($body['name'] ?? ''), is_array($body['scopes'] ?? null) ? $body['scopes'] : []));
        case 'apptoken_revoke':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            AppTokens::revoke((int) ($body['id'] ?? 0));
            json_out(['ok' => true]);

        // ---- System / configuration health ----
        case 'sys_health':
            json_out(['ok' => true, 'groups' => Config::diagnostics()]);
        case 'mail_test':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $to = trim((string) ($body['to'] ?? '')) ?: (string) (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : (defined('FROM_EMAIL') ? FROM_EMAIL : ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'error' => 'Enter a valid address (or set ADMIN_EMAIL).'], 422);
            if (!Mailer::configured()) json_out(['ok' => false, 'error' => 'SMTP isn’t configured. Set SMTP_HOST, SMTP_USERNAME and SMTP_PASSWORD (or AV_SMTP_PASSWORD) via SetEnv or config.php.']);
            $html = Mailer::shell('SMTP test', ['This is a test message from the Afrovanguard Studio.', 'If it reached your inbox, authenticated email delivery is working. 🎉'], null, 'Afrovanguard SMTP test');
            $sent = Mailer::send($to, 'Afrovanguard — SMTP test', $html);
            json_out(['ok' => $sent, 'to' => $to, 'detail' => $sent ? 'Sent — check the inbox (and spam folder).' : ('Send failed: ' . (Mailer::lastError() ?: 'unknown error'))]);

        // ---- Sign-in security policy (superadmin) ----
        case 'auth_policy_get':
            json_out(['ok' => true, 'policy' => AuthPolicy::get(), 'defaults' => AuthPolicy::defaults(), 'google_configured' => GoogleAuth::configured()]);
        case 'auth_policy_save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            json_out(['ok' => true, 'policy' => AuthPolicy::save(is_array($body['policy'] ?? null) ? $body['policy'] : $body)]);

        // ---- Sign-in illustrations (admin-managed + schedulable) ----
        case 'art_list':
            json_out(['ok' => true, 'art' => av_auth_art_all(Database::pdo()), 'today' => array_map(fn($r) => (int) $r['id'], av_auth_art_active_today(Database::pdo()))]);
        case 'art_save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (trim((string) ($body['image_url'] ?? '')) === '') json_out(['ok' => false, 'error' => 'Upload an image first.'], 422);
            if (($body['schedule_kind'] ?? '') === 'annual' && !(preg_match('/^\d{2}-\d{2}$/', (string) ($body['start_md'] ?? '')) && preg_match('/^\d{2}-\d{2}$/', (string) ($body['end_md'] ?? '')))) {
                json_out(['ok' => false, 'error' => 'A holiday window needs a start and end date (MM-DD).'], 422);
            }
            if (($body['schedule_kind'] ?? '') === 'range' && !(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($body['start_date'] ?? '')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($body['end_date'] ?? '')))) {
                json_out(['ok' => false, 'error' => 'A date range needs a start and end date.'], 422);
            }
            json_out(['ok' => true, 'id' => av_auth_art_save(Database::pdo(), $body)]);
        case 'art_delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            av_auth_art_delete(Database::pdo(), (int) ($body['id'] ?? 0));
            json_out(['ok' => true]);

        // ---- Member management (RBAC console + audit) ----
        case 'mem_list':
            json_out(['ok' => true,
                'members' => $lms->membersForAdmin((string) ($_GET['q'] ?? ''), (string) ($_GET['role'] ?? ''), (string) ($_GET['status'] ?? '')),
                'counts'  => $lms->memberCounts(),
                'roles'   => array_keys(LmsAuth::ROLE_RANK),
                'audit'   => $lms->recentAudit(30)]);
        case 'mem_save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $mid = (int) ($body['id'] ?? 0);
            $m = $lms->memberById($mid);
            if (!$m) json_out(['ok' => false, 'error' => 'Member not found.'], 404);
            $changed = [];
            if (isset($body['role']) && (string) $body['role'] !== $m['role']) {
                if (!$lms->setMemberRole($mid, (string) $body['role'])) json_out(['ok' => false, 'error' => 'Unknown access level.'], 422);
                $lms->audit('role_change', $m['email'], $m['role'] . ' → ' . $body['role']);
                $changed[] = 'role';
            }
            if (isset($body['status']) && (string) $body['status'] !== $m['status']) {
                if (!$lms->setMemberStatus($mid, (string) $body['status'])) json_out(['ok' => false, 'error' => 'Invalid status.'], 422);
                $lms->audit($body['status'] === 'suspended' ? 'suspend' : 'reactivate', $m['email']);
                $changed[] = 'status';
            }
            json_out(['ok' => true, 'changed' => $changed, 'member' => $lms->memberById($mid)]);
        case 'mem_create':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $res = $lms->createMember((string) ($body['name'] ?? ''), (string) ($body['email'] ?? ''), (string) ($body['role'] ?? 'member'));
            if (!empty($res['ok'])) $lms->audit('create_member', strtolower(trim((string) ($body['email'] ?? ''))), 'role ' . ($body['role'] ?? 'member'));
            json_out($res, !empty($res['ok']) ? 200 : 422);

        case 'get':
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
            $a = $slug ? $repo->getRaw($slug) : null;
            if (!$a) json_out(['ok' => false, 'error' => 'Not found.'], 404);
            json_out(['ok' => true, 'article' => $a]);

        case 'upload':
            if ($method !== 'POST' || empty($_FILES['file'])) json_out(['ok' => false, 'error' => 'No file.'], 400);
            $f = $_FILES['file'];
            if ($f['error'] !== UPLOAD_ERR_OK) json_out(['ok' => false, 'error' => 'Upload error.'], 400);
            if ($f['size'] > 25 * 1024 * 1024) json_out(['ok' => false, 'error' => 'Max 25 MB.'], 413);
            $mime = Storage::mime($f['tmp_name']);
            $imageOk = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
            $docOk   = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                        'text/plain', 'text/csv'];
            if (!in_array($mime, array_merge($imageOk, $docOk), true)) json_out(['ok' => false, 'error' => 'Unsupported file type.'], 415);
            // images → Cloudinary, documents → Drive (each with a local fallback)
            $res = Storage::put($f['tmp_name'], $f['name'], 'auto');
            json_out(['ok' => true, 'url' => $res['url'], 'location' => $res['url'], 'provider' => $res['provider'], 'kind' => $res['kind'] ?? 'image']);

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

        /* ── Vanguard Diary — member-submission moderation ──
           Only public submissions surface here; private/event entries never do. */
        case 'mod_queue':
            json_out(['ok' => true, 'entries' => (new DiaryJournal())->pendingPublic()]);
        case 'mod_approve':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $res = (new DiaryJournal())->approve((int) ($body['id'] ?? 0), $repo); // promotes into the feed
            if (!empty($res['ok'])) { Sitemap::rebuild(); $res['url'] = diary_url($res['slug'] . '/'); }
            json_out($res, !empty($res['ok']) ? 200 : 404);
        case 'mod_reject':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $okr = (new DiaryJournal())->reject((int) ($body['id'] ?? 0), (string) ($body['note'] ?? ''));
            json_out($okr ? ['ok' => true] : ['ok' => false, 'error' => 'Entry not found or already handled.'], $okr ? 200 : 404);

        /* ── Academy ── */
        case 'ac_list':       json_out(['ok' => true, 'courses' => $ac->allForAdmin()]);
        case 'ac_overview': {
            $pdo = Database::pdo();
            $cnt = function (string $sql) use ($pdo): int { try { return (int) $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };
            json_out(['ok' => true, 'stats' => [
                'courses_total'     => $cnt("SELECT COUNT(*) FROM courses"),
                'courses_published' => $cnt("SELECT COUNT(*) FROM courses WHERE status='published'"),
                'courses_draft'     => $cnt("SELECT COUNT(*) FROM courses WHERE status<>'published'"),
                'modules'           => $cnt("SELECT COUNT(*) FROM modules"),
                'lessons'           => $cnt("SELECT COUNT(*) FROM lessons"),
                'enrolments'        => $cnt("SELECT COUNT(*) FROM course_enrolment"),
                'applications'      => $cnt("SELECT COUNT(*) FROM enrollments"),
                'certificates'      => $cnt("SELECT COUNT(*) FROM certificates"),
                'members'           => $cnt("SELECT COUNT(*) FROM memberships WHERE status='active'"),
            ]]);
        }
        case 'ac_roster': {
            $cs = $ac->bySlug(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? ''))), true);
            if (!$cs) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            json_out(['ok' => true, 'course' => ['slug' => $cs['slug'], 'title' => $cs['title'], 'lessons' => $lms->lessonCount((int) $cs['id'])], 'roster' => $lms->roster((int) $cs['id'])]);
        }
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
