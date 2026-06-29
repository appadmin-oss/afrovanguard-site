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
        if ($tok === '' || !hash_equals((string) ADMIN_TOKEN, $tok)) {
            try { (new LmsRepository())->audit('admin_login_failed', '', 'bad token'); } catch (Throwable $e) {}
            json_out(['ok' => false, 'error' => 'Invalid token.'], 401);
        }
        av_admin_cookie_issue();
        try { (new LmsRepository())->audit('admin_login', '', 'token sign-in'); } catch (Throwable $e) {}
        json_out(['ok' => true, 'csrf' => av_csrf_token(), 'cloudinary' => Cloudinary::configured()]);
    }
    if ($action === 'logout') {
        if (av_admin_cookie_valid()) { try { (new LmsRepository())->audit('admin_logout'); } catch (Throwable $e) {} }
        av_admin_cookie_clear(); json_out(['ok' => true]);
    }
    if ($action === 'session') {
        $authed = av_admin_cookie_valid() || av_admin_bearer_ok();
        json_out(['ok' => $authed, 'csrf' => $authed ? av_csrf_token() : '', 'cloudinary' => Cloudinary::configured()]);
    }

    // ---- Everything else requires admin ----
    require_admin();
    // CSRF for state-changing requests under cookie auth (Bearer is itself a secret).
    $writing = in_array($action, ['save', 'delete', 'upload', 'ac_save', 'ac_delete', 'mod_save', 'mod_delete', 'mod_approve', 'mod_reject', 'lesson_save', 'lesson_delete', 'team_save', 'team_delete', 'cel_save', 'cel_delete', 'art_save', 'art_delete', 'mem_save', 'mem_create', 'comm_save', 'comm_delete', 'wh_save', 'wh_delete', 'wh_test', 'wh_run', 'auth_policy_save', 'apptoken_create', 'apptoken_revoke', 'mail_test', 'guide_ask', 'purge_demo',
        'mod_reorder', 'lesson_reorder', 'ac_duplicate', 'ac_status', 'roster_enrol', 'roster_unenrol', 'roster_reset', 'cert_issue', 'cert_revoke', 'diary_import_wp'], true);
    if ($writing && !av_admin_bearer_ok()) av_csrf_require();

    $repo = new DiaryRepository();
    $ac   = new AcademyRepository();
    $lms  = new LmsRepository();

    // Enterprise audit trail: record EVERY state-changing admin action centrally
    // (actor + proxy-validated client IP + action + best-effort target). This is
    // systemic — new write actions are covered automatically. mem_* self-audit
    // below with richer before/after detail, so they're excluded here.
    if ($writing && !in_array($action, ['mem_save', 'mem_create'], true)) {
        $auditTarget = (string) ($body['slug'] ?? $body['course'] ?? $body['email'] ?? $body['id'] ?? $_GET['slug'] ?? $_GET['id'] ?? '');
        if (isset($body['user_id'])) $auditTarget = trim($auditTarget . ' user#' . (int) $body['user_id']);
        $lms->audit($action, $auditTarget);
    }

    switch ($action) {
        case 'ping':         json_out(['ok' => true, 'cloudinary' => Cloudinary::configured()]);
        case 'list':         json_out(['ok' => true, 'articles' => $repo->allForAdmin()]);
        case 'categories':   json_out(['ok' => true, 'categories' => $repo->categories()]);
        case 'diary_import_wp': {
            // Browser-based WordPress (WXR) import — no SSH needed. Admin-gated +
            // CSRF (in $writing). Reuses the same engine as the CLI tool.
            if ($method !== 'POST' || empty($_FILES['wxr'])) json_out(['ok' => false, 'error' => 'No export file uploaded.'], 400);
            $f = $_FILES['wxr'];
            if ($f['error'] !== UPLOAD_ERR_OK) json_out(['ok' => false, 'error' => 'Upload failed (PHP error code ' . $f['error'] . ' — the file may exceed the server upload limit).'], 400);
            if ($f['size'] > 25 * 1024 * 1024) json_out(['ok' => false, 'error' => 'Export file exceeds 25 MB.'], 413);
            $xml = file_get_contents($f['tmp_name']);
            if ($xml === false || $xml === '') json_out(['ok' => false, 'error' => 'Could not read the uploaded file.'], 400);
            require_once AV_ROOT . '/lib/WordpressImport.php';
            json_out(av_wordpress_import($xml, [
                'dry_run'        => (string) ($_POST['dry_run'] ?? '') === '1',
                'status'         => (string) ($_POST['status'] ?? 'as-is'),
                'include_pages'  => (string) ($_POST['include_pages'] ?? '') === '1',
            ], $repo));
        }
        case 'articles':     json_out(['ok' => true, 'articles' => array_map(fn($a) => ['slug' => $a['slug'], 'title' => $a['title']], $repo->allForAdmin())]);
        case 'enrollments':  json_out(['ok' => true, 'enrollments' => Database::pdo()->query('SELECT * FROM enrollments ORDER BY created_at DESC LIMIT 200')->fetchAll()]);
        case 'audit_log':    json_out(['ok' => true, 'audit' => $lms->recentAudit(min(200, max(1, (int) ($_GET['limit'] ?? 120))))]);
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
        case 'wh_run':
            // Process due/failed deliveries now — gives no-cron hosts a manual
            // retry button (the same work db/webhooks_run.php or tasks/cron.php does).
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            json_out(['ok' => true, 'result' => Webhooks::runQueue(25)]);

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
        case 'dashboard': {
            // At-a-glance overview for the Studio landing view. Every count is
            // best-effort (try/catch → 0) so a missing table never 500s the page.
            $pdo = Database::pdo();
            $cnt = function (string $sql) use ($pdo): int { try { return (int) $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };
            try { $pending = count((new DiaryJournal())->pendingPublic()); } catch (Throwable $e) { $pending = 0; }
            // Email/delivery health, distilled from the same signals as the System page.
            $mailReady = Mailer::configured();
            $mailHost  = defined('SMTP_HOST') ? (string) SMTP_HOST : '';
            $health = ['ok' => 0, 'warn' => 0, 'off' => 0];
            foreach (Config::diagnostics() as $g) {
                foreach (($g['checks'] ?? []) as $c) {
                    $st = $c['state'] ?? 'info';
                    if (isset($health[$st])) $health[$st]++;
                }
            }
            json_out(['ok' => true, 'stats' => [
                'diary_published'   => $cnt("SELECT COUNT(*) FROM articles WHERE status='published'"),
                'diary_drafts'      => $cnt("SELECT COUNT(*) FROM articles WHERE status<>'published'"),
                'moderation'        => $pending,
                'inbox'             => $cnt("SELECT COUNT(*) FROM enrollments"),
                'subscribers'       => $cnt("SELECT COUNT(*) FROM subscribers"),
                'members'           => $cnt("SELECT COUNT(*) FROM memberships WHERE status='active'"),
                'courses_published' => $cnt("SELECT COUNT(*) FROM courses WHERE status='published'"),
                'enrolments'        => $cnt("SELECT COUNT(*) FROM course_enrolment"),
            ], 'email' => [
                'configured' => $mailReady,
                'host'       => $mailHost,
                'label'      => $mailReady ? ('SMTP ready · ' . ($mailHost ?: 'configured')) : 'SMTP not configured',
            ], 'health' => $health]);
        }
        case 'mail_test':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $to = trim((string) ($body['to'] ?? '')) ?: (string) (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : (defined('FROM_EMAIL') ? FROM_EMAIL : ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'error' => 'Enter a valid address (or set ADMIN_EMAIL).'], 422);
            // Never let a mailer hiccup become a raw 500 — always return clean JSON.
            try {
                $html = Mailer::shell('Email delivery test', ['This is a test message from the Afrovanguard Studio.', 'If it reached your inbox, email delivery is working. 🎉'], null, 'Afrovanguard email test');
                $sent = Mailer::send($to, 'Afrovanguard — email test', $html);
                $via  = method_exists('Mailer', 'lastTransport') ? Mailer::lastTransport() : '';
            } catch (\Throwable $e) {
                error_log('[mail_test] ' . $e->getMessage());
                json_out(['ok' => false, 'to' => $to, 'configured' => Mailer::configured(), 'transport' => '', 'detail' => 'Send failed: ' . $e->getMessage()]);
            }
            $vianote = $via === 'smtp' ? 'authenticated SMTP' : ($via === 'mail' ? 'PHP mail() — works, but set up SMTP (a Gmail App Password in AV_SMTP_PASSWORD) for reliable, non-spam delivery' : '');
            json_out(['ok' => $sent, 'to' => $to, 'configured' => Mailer::configured(), 'transport' => $via, 'detail' => $sent
                ? ('Sent via ' . $vianote . ' — check the inbox (and spam folder).')
                : ('Send failed: ' . (Mailer::lastError() ?: 'unknown error') . (Mailer::configured() ? '' : ' — SMTP isn’t configured. Set SMTP_HOST, SMTP_USERNAME and AV_SMTP_PASSWORD (a 16-char Gmail App Password) via .htaccess SetEnv or config.php.'))]);

        // ---- Studio AI guide: answer "how do I…" questions about running the site ----
        case 'guide_ask': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $q = trim((string) ($body['q'] ?? ''));
            if (mb_strlen($q) < 3) json_out(['ok' => false, 'error' => 'Ask a fuller question.'], 422);
            if (!AvBot::configured()) {
                json_out(['ok' => true, 'configured' => false, 'answer' => 'The AI guide isn’t enabled yet. Set ANTHROPIC_API_KEY (via .htaccess SetEnv or config.php) to turn on the assistant. In the meantime, see the How-to sections on this page.']);
            }
            $sys = "You are the Afrovanguard Studio Assistant — a concise, friendly in-app guide for the administrator of the Afrovanguard nonprofit website (afrovanguard.org.ng). "
                . "Answer ONLY about operating this admin panel (\"the Studio\") and the public site. The Studio's sections are: "
                . "Overview (at-a-glance metrics + email/delivery health); Diary (create, edit, publish entries and import from WordPress); Moderation (approve member journal submissions); Inbox (enrolment messages + newsletter subscribers); Academy (courses, modules, lessons, rosters, certificates, payments); Members & People (member accounts, access levels, team profiles); Celebrations; Communities; Webhooks (outbound integrations + app tokens for bots/agents); Sign-in (passwordless OTP / password policy + the sign-in illustrations); System (configuration health, send a test email, database). "
                . "Give short, numbered, practical steps. Refer to the left sidebar tabs by name. If asked something off-topic, gently steer back to running the site. Never invent settings that don't exist; if unsure, say so and point to the System tab.";
            $hist = [];
            foreach ((array) ($body['history'] ?? []) as $h) {
                if (!is_array($h)) continue;
                $hist[] = ['role' => (($h['role'] ?? '') === 'bot' ? 'bot' : 'member'), 'text' => (string) ($h['text'] ?? '')];
            }
            $ai = AvBot::reply($q, $hist, ['system' => $sys]);
            json_out(['ok' => (bool) $ai['ok'], 'configured' => true, 'answer' => $ai['ok'] ? $ai['text'] : ('Sorry — the assistant couldn’t answer just now. ' . (string) ($ai['__error'] ?? ''))]);
        }

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
        case 'purge_demo':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            json_out(['ok' => true, 'removed' => Database::purgeDemoContent()]);
        case 'ac_roster': {
            $cs = $ac->bySlug(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? ''))), true);
            if (!$cs) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            json_out(['ok' => true, 'course' => ['slug' => $cs['slug'], 'title' => $cs['title'], 'lessons' => $lms->lessonCount((int) $cs['id'])], 'roster' => $lms->roster((int) $cs['id'])]);
        }
        case 'ac_export': {
            $cs = $ac->bySlug(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? ''))), true);
            if (!$cs) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            $lms->audit('ac_export', (string) $cs['slug'], 'roster CSV');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="roster-' . preg_replace('/[^a-z0-9\-]/', '', (string) $cs['slug']) . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Email', 'Enrolled', 'Lessons done', 'Progress %', 'Certified', 'Last active']);
            foreach ($lms->roster((int) $cs['id'], 5000) as $r) {
                fputcsv($out, [$r['name'], $r['email'], $r['enrolled_at'] ?? '', $r['done'], $r['pct'], $r['certified'] ? 'yes' : 'no', $r['last_active'] ?? '']);
            }
            fclose($out); exit;
        }
        /* ── Curriculum reordering ── */
        case 'mod_reorder': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $cs = $ac->bySlug(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['course'] ?? ''))), true);
            if (!$cs) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            $lms->reorderModules((int) $cs['id'], array_map('intval', (array) ($body['ids'] ?? [])));
            json_out(['ok' => true]);
        }
        case 'lesson_reorder':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $lms->reorderLessons((int) ($body['module_id'] ?? 0), array_map('intval', (array) ($body['ids'] ?? [])));
            json_out(['ok' => true]);
        /* ── Per-learner management ── */
        case 'roster_enrol': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $cs = $ac->bySlug(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['course'] ?? ''))), true);
            if (!$cs) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            $u = $lms->findUserByEmail((string) ($body['email'] ?? ''));
            if (!$u) json_out(['ok' => false, 'error' => 'No Academy account exists for that email yet.'], 404);
            $lms->enrol((int) $u['id'], (int) $cs['id']);
            json_out(['ok' => true]);
        }
        case 'roster_unenrol': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $cs = $ac->bySlug(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['course'] ?? ''))), true);
            if (!$cs) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            $lms->unenrol((int) ($body['user_id'] ?? 0), (int) $cs['id']);
            json_out(['ok' => true]);
        }
        case 'roster_reset': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $cs = $ac->bySlug(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['course'] ?? ''))), true);
            if (!$cs) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            $lms->resetProgress((int) ($body['user_id'] ?? 0), (int) $cs['id']);
            json_out(['ok' => true]);
        }
        case 'cert_issue': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $cs = $ac->bySlug(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['course'] ?? ''))), true);
            if (!$cs) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            $cert = $lms->adminIssueCertificate((int) ($body['user_id'] ?? 0), (int) $cs['id']);
            json_out(['ok' => (bool) $cert, 'serial' => $cert['serial'] ?? null]);
        }
        case 'cert_revoke': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $cs = $ac->bySlug(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['course'] ?? ''))), true);
            if (!$cs) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            $lms->revokeCertificate((int) ($body['user_id'] ?? 0), (int) $cs['id']);
            json_out(['ok' => true]);
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
            $fields['_editing'] = trim((string) ($body['editing'] ?? ''));
            $slug = $ac->save($fields);
            Sitemap::rebuild();
            json_out(['ok' => true, 'slug' => $slug, 'url' => rtrim(SITE_URL, '/') . '/academy/' . $slug . '/', 'notice' => $instructorMsg]);
        case 'ac_delete': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $dslug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['slug'] ?? '')));
            $dc = $ac->bySlug($dslug, true);
            if (!$dc) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            $usage = $lms->courseUsage((int) $dc['id']);
            // Deleting hard-cascades away enrolments + certificates — refuse unless forced.
            if (($usage['enrolments'] || $usage['certificates']) && empty($body['force'])) {
                json_out(['ok' => false, 'needs_confirm' => true, 'usage' => $usage,
                    'error' => 'This course has ' . $usage['enrolments'] . ' enrolment(s) and ' . $usage['certificates'] . ' certificate(s) that would be permanently destroyed. Archive it instead, or re-confirm to force-delete.'], 409);
            }
            $okd = $ac->delete($dslug);
            if ($okd) Sitemap::rebuild();
            json_out(['ok' => (bool) $okd]);
        }
        case 'ac_duplicate': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $ns = $ac->duplicate(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['slug'] ?? ''))));
            if (!$ns) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            Sitemap::rebuild();
            json_out(['ok' => true, 'slug' => $ns]);
        }
        case 'ac_status': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $ok = $ac->setStatus(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['slug'] ?? ''))), (string) ($body['status'] ?? 'draft'));
            if ($ok) Sitemap::rebuild();
            json_out(['ok' => (bool) $ok]);
        }

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
