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
    // Guarantee the default Super Admin exists before the login/session probe
    // (idempotent + fingerprint-guarded → one cheap lookup once provisioned).
    if (in_array($action, ['login', 'session'], true) && class_exists('SuperAdmin')) {
        try { SuperAdmin::ensure(); } catch (Throwable $e) {}
    }

    // ---- Unauthenticated: login / session probe ----
    if ($action === 'login') {
        if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
        if (!av_rate_ok('admin_login', 8, 900)) json_out(['ok' => false, 'error' => 'Too many attempts. Try again later.'], 429);
        if (!av_admin_token_configured()) json_out(['ok' => false, 'error' => 'Admin isn’t configured. Set AV_ADMIN_TOKEN (a random string, 32+ characters — e.g. php -r "echo bin2hex(random_bytes(32));") via .htaccess SetEnv or config.php, then reload.'], 503);
        $tok = (string) ($body['token'] ?? '');
        if ($tok === '' || !hash_equals((string) ADMIN_TOKEN, $tok)) {
            try { (new LmsRepository())->audit('admin_login_failed', '', 'bad token'); } catch (Throwable $e) {}
            json_out(['ok' => false, 'error' => 'Invalid token.'], 401);
        }
        av_admin_cookie_issue();   // token sign-in → superadmin
        try { (new LmsRepository())->audit('admin_login', '', 'token sign-in'); } catch (Throwable $e) {}
        json_out(['ok' => true, 'csrf' => av_csrf_token(), 'cloudinary' => Cloudinary::configured(), 'role' => 'superadmin']);
    }
    if ($action === 'logout') {
        if (av_admin_cookie_valid()) { try { (new LmsRepository())->audit('admin_logout'); } catch (Throwable $e) {} }
        av_admin_cookie_clear(); json_out(['ok' => true]);
    }
    if ($action === 'session') {
        $role = av_admin_role();           // '' | editor | admin | superadmin (bridges member-admins)
        $authed = $role !== '';
        json_out(['ok' => $authed, 'csrf' => $authed ? av_csrf_token() : '', 'cloudinary' => Cloudinary::configured(), 'role' => $role]);
    }

    // ---- Everything else requires admin ----
    require_admin();
    // CSRF for state-changing requests under cookie auth (Bearer is itself a secret).
    $writing = in_array($action, ['save', 'delete', 'upload', 'ac_save', 'ac_delete', 'mod_save', 'mod_delete', 'mod_approve', 'mod_reject', 'lesson_save', 'lesson_delete', 'team_save', 'team_delete', 'cel_save', 'cel_delete', 'art_save', 'art_delete', 'mem_save', 'mem_create', 'comm_save', 'comm_delete', 'wh_save', 'wh_delete', 'wh_test', 'wh_run', 'auth_policy_save', 'apptoken_create', 'apptoken_revoke', 'mail_test', 'guide_ask', 'purge_demo',
        'mod_reorder', 'lesson_reorder', 'ac_duplicate', 'ac_status', 'roster_enrol', 'roster_unenrol', 'roster_reset', 'cert_issue', 'cert_revoke', 'diary_import_wp',
        'mentorship_approve', 'mentorship_decline', 'mentorship_add', 'mentorship_assign', 'mentorship_reassign', 'mentorship_set_status', 'mentorship_cohort_create', 'mentorship_cohort_status', 'activity_undo',
        'admin_add', 'admin_remove', 'db_test', 'db_migrate', 'brand_save'], true);
    if ($writing && !av_admin_bearer_ok()) av_csrf_require();

    /* ── Structured admin levels (editor < admin < superadmin) ──
       superadmin: everything. admin: management + content + undo, but not roles,
       destructive purge or the security policy. editor: content only. */
    $role = function_exists('av_admin_role') ? av_admin_role() : 'superadmin';
    $superadminOnly = ['purge_demo', 'admins_list', 'admin_add', 'admin_remove', 'superadmin_reveal', 'auth_policy_save', 'auth_policy_get',
        'db_status', 'db_test', 'db_migrate', 'brand_get', 'brand_save'];
    $managementOnly = [ // not available to editors
        'mem_list', 'mem_save', 'mem_create', 'team_list', 'team_get', 'team_save', 'team_delete',
        'wh_list', 'wh_save', 'wh_delete', 'wh_test', 'wh_run', 'apptoken_list', 'apptoken_create', 'apptoken_revoke',
        'sys_health', 'mail_test', 'subscribers', 'enrollments', 'audit_log',
        'activity', 'activity_undo',
        'mentorship_stats', 'mentorship_mentors', 'mentorship_pairings', 'mentorship_inactive', 'mentorship_cohorts',
        'mentorship_find_users', 'mentorship_approve', 'mentorship_decline', 'mentorship_add', 'mentorship_assign',
        'mentorship_reassign', 'mentorship_set_status', 'mentorship_cohort_create', 'mentorship_cohort_status', 'mentorship_export',
    ];
    if (in_array($action, $superadminOnly, true) && $role !== 'superadmin') {
        json_out(['ok' => false, 'error' => 'That action needs a Super Admin.'], 403);
    }
    if ($role === 'editor' && in_array($action, $managementOnly, true)) {
        json_out(['ok' => false, 'error' => 'Editors can manage content only.'], 403);
    }

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
        case 'diary_series': json_out(['ok' => true, 'series' => $repo->seriesList()]);
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

        /* ════ Mentorship & mentor–mentee management ════ */
        case 'mentorship_stats':    json_out(['ok' => true, 'stats' => Mentorship::adminStats()]);
        case 'mentorship_mentors':  json_out(['ok' => true, 'mentors' => Mentorship::adminMentors((string) ($_GET['segment'] ?? ''), (string) ($_GET['approval'] ?? ''), (string) ($_GET['q'] ?? ''))]);
        case 'mentorship_pairings': json_out(['ok' => true, 'pairings' => Mentorship::adminPairings((string) ($_GET['segment'] ?? ''), (string) ($_GET['status'] ?? ''), isset($_GET['cohort']) && $_GET['cohort'] !== '' ? (int) $_GET['cohort'] : -1, (string) ($_GET['q'] ?? ''))]);
        case 'mentorship_inactive': json_out(['ok' => true, 'pairs' => Mentorship::inactivePairs((int) ($_GET['days'] ?? 21))]);
        case 'mentorship_cohorts':  json_out(['ok' => true, 'cohorts' => Mentorship::listCohorts((string) ($_GET['segment'] ?? ''))]);
        case 'mentorship_find_users': json_out(['ok' => true, 'users' => Mentorship::findUsers((string) ($_GET['q'] ?? ''), (string) ($_GET['segment'] ?? ''))]);
        case 'mentorship_approve': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $uid = (int) ($body['user_id'] ?? 0); $prev = Mentorship::approvalOf($uid);
            if ($prev === '') json_out(['ok' => false, 'error' => 'No mentor profile.'], 404);
            Mentorship::setMentorApproval($uid, 'approved');
            AdminAudit::log('mentorship', 'mentor_approved', (string) $uid, 'Approved mentor', ['class' => 'Mentorship', 'op' => 'mentor_approval', 'args' => ['uid' => $uid, 'to' => $prev], 'label' => 'Undo approval']);
            json_out(['ok' => true]);
        }
        case 'mentorship_decline': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $uid = (int) ($body['user_id'] ?? 0); $prev = Mentorship::approvalOf($uid);
            if ($prev === '') json_out(['ok' => false, 'error' => 'No mentor profile.'], 404);
            Mentorship::setMentorApproval($uid, 'declined');
            AdminAudit::log('mentorship', 'mentor_declined', (string) $uid, 'Declined mentor', ['class' => 'Mentorship', 'op' => 'mentor_approval', 'args' => ['uid' => $uid, 'to' => $prev], 'label' => 'Undo decline']);
            json_out(['ok' => true]);
        }
        case 'mentorship_add': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $res = Mentorship::adminAddMentor((string) ($body['email'] ?? ''), $body);
            if (!empty($res['ok'])) AdminAudit::log('mentorship', 'mentor_added', (string) ($body['email'] ?? ''), 'Added mentor directly (' . ($res['segment'] ?? '') . ')');
            json_out($res, !empty($res['ok']) ? 200 : 422);
        }
        case 'mentorship_assign': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $res = Mentorship::adminAssign((int) ($body['mentor_id'] ?? 0), (int) ($body['mentee_id'] ?? 0), (int) ($body['cohort_id'] ?? 0), (string) ($body['programme'] ?? ''));
            if (!empty($res['ok'])) AdminAudit::log('mentorship', 'pair_assigned', (string) $res['id'], 'Assigned mentee to mentor', ['class' => 'Mentorship', 'op' => 'pair_delete', 'args' => ['id' => (int) $res['id']], 'label' => 'Undo assignment']);
            json_out($res, !empty($res['ok']) ? 200 : 422);
        }
        case 'mentorship_reassign': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $id = (int) ($body['id'] ?? 0);
            $res = Mentorship::adminReassign($id, (int) ($body['mentor_id'] ?? 0));
            if (!empty($res['ok'])) AdminAudit::log('mentorship', 'pair_reassigned', (string) $id, 'Reassigned pairing to a new mentor', ['class' => 'Mentorship', 'op' => 'pair_mentor', 'args' => ['id' => $id, 'to' => (int) $res['prev_mentor']], 'label' => 'Undo reassign']);
            json_out($res, !empty($res['ok']) ? 200 : 422);
        }
        case 'mentorship_set_status': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $id = (int) ($body['id'] ?? 0); $to = (string) ($body['status'] ?? '');
            $row = Mentorship::pairRow($id);
            if (!$row) json_out(['ok' => false, 'error' => 'Pairing not found.'], 404);
            if (!Mentorship::setPairStatus($id, $to)) json_out(['ok' => false, 'error' => 'Bad status.'], 422);
            AdminAudit::log('mentorship', 'pair_' . $to, (string) $id, 'Set pairing to ' . $to, ['class' => 'Mentorship', 'op' => 'pair_status', 'args' => ['id' => $id, 'to' => (string) $row['status']], 'label' => 'Undo (back to ' . $row['status'] . ')']);
            json_out(['ok' => true]);
        }
        case 'mentorship_cohort_create': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $res = Mentorship::createCohort($body);
            if (!empty($res['ok'])) AdminAudit::log('mentorship', 'cohort_created', (string) ($res['id'] ?? ''), 'Created cohort ' . (string) ($body['name'] ?? ''));
            json_out($res, !empty($res['ok']) ? 200 : 422);
        }
        case 'mentorship_cohort_status': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            json_out(Mentorship::setCohortStatus((int) ($body['id'] ?? 0), (string) ($body['status'] ?? '')));
        }
        case 'mentorship_export': {
            $rows = Mentorship::exportPairings((string) ($_GET['segment'] ?? ''));
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="mentorship-pairings.csv"');
            $out = fopen('php://output', 'w');
            foreach ($rows as $r) fputcsv($out, $r);
            fclose($out); exit;
        }

        /* ════ Activity trail (per-area) + undo ════ */
        case 'activity':       json_out(['ok' => true, 'entries' => AdminAudit::recent((string) ($_GET['area'] ?? ''), (int) ($_GET['limit'] ?? 80)), 'areas' => AdminAudit::areas()]);
        case 'activity_undo':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            json_out(AdminAudit::undo((int) ($body['id'] ?? 0)));

        /* ════ Admin team & roles (Super Admin only — gated above) ════ */
        case 'admins_list':
            json_out(['ok' => true, 'admins' => AdminRoles::list(), 'me' => $role, 'roles' => array_keys(AdminRoles::RANK),
                'default_superadmin'   => class_exists('SuperAdmin') ? SuperAdmin::defaultEmail() : '',
                'has_initial_password' => class_exists('SuperAdmin') && SuperAdmin::pendingPassword() !== '']);
        case 'superadmin_reveal': {
            // One-time reveal of the auto-generated default super-admin password,
            // then it's forgotten. (Nothing to show if an explicit
            // AV_SUPERADMIN_PASSWORD is in use.) Superadmin-gated above.
            $pw = class_exists('SuperAdmin') ? SuperAdmin::pendingPassword() : '';
            if ($pw !== '') { SuperAdmin::forgetPassword(); AdminAudit::log('admins', 'superadmin_reveal', SuperAdmin::defaultEmail(), 'Revealed the one-time default super-admin password'); }
            json_out(['ok' => true, 'email' => class_exists('SuperAdmin') ? SuperAdmin::defaultEmail() : '', 'password' => $pw]);
        }
        case 'admin_add': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $res = AdminRoles::add((string) ($body['email'] ?? ''), (string) ($body['role'] ?? 'editor'), 'token');
            if (!empty($res['ok'])) AdminAudit::log('admins', 'admin_added', (string) ($body['email'] ?? ''), 'Granted ' . ($body['role'] ?? '') . ' access');
            json_out($res, !empty($res['ok']) ? 200 : 422);
        }
        case 'admin_remove': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $res = AdminRoles::remove((string) ($body['email'] ?? ''));
            if (!empty($res['ok'])) AdminAudit::log('admins', 'admin_removed', (string) ($body['email'] ?? ''), 'Revoked admin access');
            json_out($res, !empty($res['ok']) ? 200 : 422);
        }

        // ---- Database: status + browser-based migration (superadmin) ----
        // Shared cPanel has no SSH/cron, so the SQLite → MySQL/Postgres cutover
        // (normally db/migrate.php on the CLI) is exposed here. Superadmin-only,
        // CSRF-protected; credentials are used for this request and shown back as
        // an .env snippet to paste — never written to disk from the browser.
        case 'db_status': {
            require_once AV_ROOT . '/lib/Migrator.php';
            $src = Database::pdo();
            $counts = []; $total = 0;
            foreach (Migrator::ORDER as $t) {
                if (Migrator::tableExists($src, $t)) { $n = Migrator::count($src, $t); $counts[$t] = $n; $total += $n; }
            }
            $driver = Database::driver();
            json_out([
                'ok'         => true,
                'driver'     => $driver,
                'is_sqlite'  => $driver === 'sqlite',
                'db'         => $driver === 'sqlite' ? basename((string) (defined('AV_DB_PATH') ? AV_DB_PATH : '')) : (string) (getenv('AV_DB_NAME') ?: ''),
                'tables'     => $counts,
                'total_rows' => $total,
            ]);
        }
        case 'db_test':
        case 'db_migrate': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_once AV_ROOT . '/lib/Migrator.php';
            $to   = strtolower(trim((string) ($body['driver'] ?? '')));
            if (!in_array($to, ['mysql', 'pgsql'], true)) json_out(['ok' => false, 'error' => 'Choose a MySQL or PostgreSQL target.'], 422);
            $host = trim((string) ($body['host'] ?? '')) ?: '127.0.0.1';
            $port = trim((string) ($body['port'] ?? '')) ?: ($to === 'pgsql' ? '5432' : '3306');
            $name = trim((string) ($body['name'] ?? ''));
            $user = trim((string) ($body['user'] ?? ''));
            $pass = (string) ($body['pass'] ?? '');
            if ($name === '') json_out(['ok' => false, 'error' => 'A target database name is required.'], 422);
            $dsn = $to === 'pgsql'
                ? "pgsql:host=$host;port=$port;dbname=$name"
                : "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
            try {
                $target = new PDO($dsn, $user ?: null, $pass !== '' ? $pass : null,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 8]);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'error' => 'Could not connect: ' . $e->getMessage()], 502);
            }
            $version = '';
            try { $version = (string) $target->query('SELECT version()')->fetchColumn(); } catch (Throwable $e) {}

            if ($action === 'db_test') {
                json_out(['ok' => true, 'connected' => true, 'driver' => $to, 'server' => $version]);
            }

            // ---- db_migrate: optional schema apply, then verified copy ----
            $applySchema = !empty($body['apply_schema']);
            $truncate    = !empty($body['truncate']);
            $dryRun      = !empty($body['dry_run']);
            $log = '';
            $append = function (string $m) use (&$log) { $log .= $m; };

            $appliedStmts = 0;
            if ($applySchema && !$dryRun) {
                $file = AV_ROOT . "/db/schema.$to.sql";
                if (!is_file($file)) json_out(['ok' => false, 'error' => "Schema file missing: db/schema.$to.sql"], 500);
                $sql = preg_replace('/--[^\n]*/', '', (string) file_get_contents($file));
                foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $stmt) {
                    try { $target->exec($stmt); $appliedStmts++; }
                    catch (Throwable $e) { json_out(['ok' => false, 'error' => 'Schema step failed: ' . $e->getMessage(), 'log' => $log], 500); }
                }
                $append("Applied db/schema.$to.sql ($appliedStmts statements)\n");
                require_once AV_ROOT . '/lib/workspace.php';
                require_once AV_ROOT . '/lib/celebrations.php';
                require_once AV_ROOT . '/lib/people.php';
                try {
                    Database::ensureMetaOn($target);
                    av_communities_ensure($target);
                    av_celebrations_ensure($target);
                    av_team_ensure($target);
                } catch (Throwable $e) { json_out(['ok' => false, 'error' => 'Auxiliary tables: ' . $e->getMessage(), 'log' => $log], 500); }
            }

            $src = Database::pdo();
            try {
                $report = Migrator::migrate($src, $target, ['dryRun' => $dryRun, 'truncate' => $truncate, 'log' => $append]);
            } catch (Throwable $e) {
                json_out(['ok' => false, 'error' => $e->getMessage(), 'log' => $log], 422);
            }
            $rows = array_sum(array_map(fn($r) => (int) $r['copied'], $report));
            $bad  = array_keys(array_filter($report, fn($r) => empty($r['ok'])));

            if (!$dryRun) {
                AdminAudit::log('database', 'db_migrate', $to . ':' . $name,
                    $rows . ' rows → ' . strtoupper($to) . ' @ ' . $host . ($bad ? ' (mismatch: ' . implode(',', $bad) . ')' : ' ✓'));
            }
            $env = "AV_DB_DRIVER=$to\nAV_DB_HOST=$host\nAV_DB_PORT=$port\nAV_DB_NAME=$name\nAV_DB_USER=$user\nAV_DB_PASS=" . ($pass !== '' ? 'your-password' : '');
            json_out([
                'ok'       => empty($bad),
                'dry_run'  => $dryRun,
                'report'   => $report,
                'rows'     => $rows,
                'tables'   => count($report),
                'mismatch' => $bad,
                'applied'  => $appliedStmts,
                'server'   => $version,
                'env'      => $env,
                'log'      => $log,
                'note'     => $dryRun
                    ? 'Dry run — nothing was written. Uncheck “Dry run”, then Migrate, to copy the data.'
                    : (empty($bad)
                        ? 'Migration complete — every table’s row count was verified. Paste the settings below into your .env (set AV_DB_PASS to the real password) to switch the site to ' . strtoupper($to) . '.'
                        : 'Some tables did not match. Review the log; you can re-run with “Replace target tables” to overwrite.'),
            ]);
        }

        // ---- Design Studio: site brand / accent colours (superadmin) ----
        // Stored as JSON in app_meta; render_head() injects a validated :root
        // override so every dynamic surface (portal, diary, academy, community,
        // admin) follows the brand. Additive + reversible — clearing restores
        // the built-in gold.
        case 'brand_get': {
            $raw = Database::metaGet('brand_theme');
            $b = $raw ? json_decode($raw, true) : null;
            json_out([
                'ok'       => true,
                'brand'    => is_array($b) ? $b : null,
                'defaults' => ['accent' => '#f3b416', 'accent_deep' => '#b07e08'],
            ]);
        }
        case 'brand_save': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $hex = static fn($v) => (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) ? strtolower($v) : null;
            if (!empty($body['reset'])) {
                Database::metaSet('brand_theme', '');
                AdminAudit::log('design', 'brand_reset', 'brand', 'Reset to the default gold palette');
                json_out(['ok' => true, 'brand' => null]);
            }
            $a = $hex($body['accent'] ?? null);
            $d = $hex($body['accent_deep'] ?? null);
            if (!$a) json_out(['ok' => false, 'error' => 'Pick a valid accent colour (#rrggbb).'], 422);
            $brand = ['accent' => $a, 'accent_deep' => $d ?: $a];
            Database::metaSet('brand_theme', json_encode($brand));
            AdminAudit::log('design', 'brand_save', 'brand', 'Accent ' . $a . ' / ' . $brand['accent_deep']);
            json_out(['ok' => true, 'brand' => $brand]);
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
            if (isset($body['level']) && class_exists('Levels')) {
                $newLevel = (string) $body['level'];
                if (Levels::of($mid) !== $newLevel) {
                    if (!Levels::set($mid, $newLevel)) json_out(['ok' => false, 'error' => 'Unknown level.'], 422);
                    $lms->audit('level_change', $m['email'], 'Level → ' . $newLevel);
                    $changed[] = 'level';
                }
            }
            json_out(['ok' => true, 'changed' => $changed, 'member' => $lms->memberById($mid), 'level' => class_exists('Levels') ? Levels::of($mid) : null]);
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
            $mime = Storage::mime($f['tmp_name']);
            $imageOk = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
            $docOk   = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                        'text/plain', 'text/csv'];
            $audioOk = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/webm'];
            // finfo sometimes reports a headerless MP3/M4A as octet-stream — allow it
            // through only when the extension is a known audio type (extension-gated).
            $ext      = strtolower((string) pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            $isAudio  = in_array($mime, $audioOk, true)
                     || ($mime === 'application/octet-stream' && in_array($ext, ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'wav'], true));
            // Audio narrations can be large; everything else stays at 25 MB.
            $cap = $isAudio ? 60 : 25;
            if ($f['size'] > $cap * 1024 * 1024) json_out(['ok' => false, 'error' => 'Max ' . $cap . ' MB.'], 413);
            if (!$isAudio && !in_array($mime, array_merge($imageOk, $docOk), true)) json_out(['ok' => false, 'error' => 'Unsupported file type.'], 415);
            // images/audio → Cloudinary (resource_type auto), documents → Drive (each with a local fallback)
            $res = Storage::put($f['tmp_name'], $f['name'], 'auto');
            json_out(['ok' => true, 'url' => $res['url'], 'location' => $res['url'], 'provider' => $res['provider'], 'kind' => $res['kind'] ?? ($isAudio ? 'audio' : 'image')]);

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
                'audio_url' => trim((string) ($body['audio_url'] ?? '')),
                'featured' => !empty($body['featured']), 'status' => ($body['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
                'format' => (string) ($body['format'] ?? 'standard'),
                'series' => trim((string) ($body['series'] ?? '')), 'series_part' => (int) ($body['series_part'] ?? 0),
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
                'pass_code' => (string) ($body['pass_code'] ?? ''),
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
        case 'ac_grants': {
            // List members explicitly granted access to a restricted course.
            $gslug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? $body['slug'] ?? '')));
            $gc = $gslug ? $ac->bySlug($gslug, true) : null;
            if (!$gc) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            json_out(['ok' => true, 'grants' => $lms->courseAccessList((int) $gc['id'])]);
        }
        case 'ac_grant': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $gslug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['slug'] ?? '')));
            $gc = $gslug ? $ac->bySlug($gslug, true) : null;
            if (!$gc) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            $gu = $lms->userByEmail((string) ($body['email'] ?? ''));
            if (!$gu) json_out(['ok' => false, 'error' => 'No account exists for that email yet — ask them to sign in once, then grant access.'], 404);
            $lms->grantCourseAccess((int) $gc['id'], (int) $gu['id'], 0);
            json_out(['ok' => true, 'grants' => $lms->courseAccessList((int) $gc['id'])]);
        }
        case 'ac_revoke': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $gslug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['slug'] ?? '')));
            $gc = $gslug ? $ac->bySlug($gslug, true) : null;
            if (!$gc) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            $lms->revokeCourseAccess((int) $gc['id'], (int) ($body['user_id'] ?? 0));
            json_out(['ok' => true, 'grants' => $lms->courseAccessList((int) $gc['id'])]);
        }
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
