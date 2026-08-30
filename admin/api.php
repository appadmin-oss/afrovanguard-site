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
        'admin_add', 'admin_remove', 'db_test', 'db_migrate', 'brand_save', 'ngv_save', 'ngv_reset', 'ngv_restore',
        'rules_save', 'rules_reset', 'kb_save', 'kb_delete', 'prompts_save', 'prompts_reset', 'level_recommend',
        'ai_run', 'ai_chat', 'ai_proposal_decide', 'setup_save', 'setup_test',
        'summit_resend', 'summit_resend_failed',
        'ac_grant', 'ac_revoke'], true);
    if ($writing && !av_admin_bearer_ok()) av_csrf_require();

    /* ── Structured admin levels (editor < admin < superadmin) ──
       superadmin: everything. admin: management + content + undo, but not roles,
       destructive purge or the security policy. editor: content only. */
    $role = function_exists('av_admin_role') ? av_admin_role() : 'superadmin';
    $superadminOnly = ['purge_demo', 'admins_list', 'admin_add', 'admin_remove', 'superadmin_reveal', 'auth_policy_save', 'auth_policy_get',
        'db_status', 'db_test', 'db_migrate', 'brand_get', 'brand_save',
        // The rules ARE the organisation's constitution — they decide promotions
        // and escalations movement-wide — and the prompts steer every AI reply.
        // Both stay with the Super Admin. The knowledge base is management-level.
        'rules_get', 'rules_save', 'rules_reset', 'prompts_list', 'prompts_save', 'prompts_reset',
        // Approving a proposal WRITES a rule or a prompt, so it is gated exactly
        // as editing one directly is — the AI having suggested it changes nothing.
        'ai_proposal_decide',
        // Provider credentials. Super Admin only — these are the organisation's keys.
        'setup_get', 'setup_save', 'setup_test'];
    $managementOnly = [ // not available to editors
        'mem_list', 'mem_save', 'mem_create', 'team_list', 'team_get', 'team_save', 'team_delete',
        'wh_list', 'wh_save', 'wh_delete', 'wh_test', 'wh_run', 'apptoken_list', 'apptoken_create', 'apptoken_revoke',
        'ngv_reset', 'ngv_restore',
        'sys_health', 'mail_test', 'subscribers', 'enrollments', 'audit_log',
        // Seat claims carry names, emails and phone numbers, and resending mail
        // on someone's behalf is a management action. Not for editors.
        'summit_list', 'summit_resend', 'summit_resend_failed', 'summit_export',
        'activity', 'activity_undo',
        'mentorship_stats', 'mentorship_mentors', 'mentorship_pairings', 'mentorship_inactive', 'mentorship_cohorts',
        // Health names individuals and their attendance record, so it sits with
        // the rest of mentorship rather than being readable by an editor.
        'mentorship_health',
        // The brief names individuals, their attendance and their promotion
        // readiness. Same reasoning as mentorship_health.
        'brief_latest', 'brief_run',
        // The promotion queue names individuals and their readiness evidence.
        'promotion_queue', 'promotion_review', 'promotion_defer', 'promotion_reopen',
        // AI Ops reports provider keys' liveness, model spend and the escalation
        // counts — infrastructure state, and it names no members but does expose
        // which providers this deployment pays for. Management.
        'aiops', 'aiops_cron',
        'mentorship_find_users', 'mentorship_approve', 'mentorship_decline', 'mentorship_add', 'mentorship_assign',
        'mentorship_reassign', 'mentorship_set_status', 'mentorship_cohort_create', 'mentorship_cohort_status', 'mentorship_export',
        'kb_list', 'kb_save', 'kb_delete', 'level_recommend',
        // Running the bench and talking to the assistant are management-level:
        // both can FILE a proposal, which is harmless until someone approves it.
        'ai_status', 'ai_run', 'ai_chat', 'ai_proposals',
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
        /**
         * The mail configuration, WITHOUT sending anything.
         *
         * `mail_test` already proves whether delivery works, and says so well
         * when it fails. What it cannot say is WHY, because by then the failure
         * is a transport error string. This reports the inputs: which constants
         * are present, which transports this host actually has, and which one a
         * send would reach for first.
         *
         * The password is reported only as set/not-set plus its LENGTH — enough
         * to catch the single most common misconfiguration on this stack (a
         * Gmail App Password pasted with the spaces Google displays, so 19
         * characters instead of 16) without putting a live credential in a JSON
         * response an admin might paste into a chat.
         */
        case 'mail_status': {
            $pass = defined('SMTP_PASSWORD') ? (string) SMTP_PASSWORD : '';
            // PHPMailer is the ONLY SMTP transport (lib/Smtp.php was retired), so
            // report whether it can actually be loaded rather than guessing from a
            // file path: "credentials are set" means nothing if the library that
            // uses them is missing. This used to also advertise a 'built-in SMTP
            // client' that no longer exists — a phantom third transport.
            $phpmailer = Mailer::phpMailerInfo();
            $transports = [
                'phpmailer' => $phpmailer['available'],
                'php_mail'  => function_exists('mail'),
            ];
            $would = Mailer::configured() && $transports['phpmailer']
                ? 'PHPMailer over authenticated SMTP'
                : (Mailer::resendConfigured()
                    ? 'the Resend HTTPS API'
                    : ($transports['php_mail'] ? 'PHP mail() — unauthenticated, and often filtered' : 'nothing'));

            json_out([
                'ok'         => true,
                'configured' => Mailer::configured(),
                'notifications_enabled' => !defined('ENABLE_EMAIL_NOTIFICATIONS') || (bool) ENABLE_EMAIL_NOTIFICATIONS,
                'from'       => defined('FROM_EMAIL') ? FROM_EMAIL : '(unset — falls back to SMTP_USERNAME)',
                'from_name'  => defined('FROM_NAME') ? FROM_NAME : 'Afrovanguard',
                'host'       => defined('SMTP_HOST') ? SMTP_HOST : '',
                'port'       => defined('SMTP_PORT') ? (int) SMTP_PORT : 587,
                'secure'     => defined('SMTP_SECURE') ? SMTP_SECURE : 'tls (default)',
                'username'   => defined('SMTP_USERNAME') ? SMTP_USERNAME : '',
                'password_set'    => $pass !== '',
                'password_length' => strlen($pass),
                'password_note'   => ($pass !== '' && strlen($pass) !== 16)
                    ? 'A Gmail App Password is exactly 16 characters; this one is ' . strlen($pass)
                      . '. If you pasted it with the spaces Google shows, remove them.'
                    : '',
                'transports'  => $transports,
                'phpmailer'   => $phpmailer,
                'would_use'   => $would,
                'admin_email' => defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '',
            ]);
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
            if (!AvRouter::available()) {
                json_out(['ok' => true, 'configured' => false, 'answer' => 'The AI guide isn’t enabled yet. Set an AI provider key (via .htaccess SetEnv or config.php) to turn on the assistant — any one of: ' . AvRouter::keyHint() . '. In the meantime, see the How-to sections on this page.']);
            }
            $sys = "You are the Afrovanguard Studio Assistant — a concise, friendly in-app guide for the administrator of the Afrovanguard nonprofit website (afrovanguard.org.ng). "
                . "Answer ONLY about operating this admin panel (\"the Studio\") and the public site. The Studio's sections are: "
                . "Overview (at-a-glance metrics + email/delivery health); Diary (create, edit, publish entries and import from WordPress); Moderation (approve member journal submissions); Inbox (enrolment messages + newsletter subscribers); Academy (courses, modules, lessons, rosters, certificates, payments); Members & People (member accounts, access levels, team profiles); Celebrations; Communities; Webhooks (outbound integrations + app tokens for bots/agents); Sign-in (passwordless OTP / password policy + the sign-in illustrations); System (configuration health, send a test email, database). "
                . "Give short, numbered, practical steps. Refer to the left sidebar tabs by name. If asked something off-topic, gently steer back to running the site. Never invent settings that don't exist; if unsure, say so and point to the System tab.";
            $hist = [];
            foreach ((array) ($body['history'] ?? []) as $h) {
                if (!is_array($h)) continue;
                $hist[] = ['role' => (($h['role'] ?? '') === 'bot' ? 'assistant' : 'user'), 'text' => (string) ($h['text'] ?? '')];
            }
            $ai = AvRouter::complete(AvRouter::JOB_BULK, $q, ['system' => $sys, 'history' => $hist, 'actor' => 'studio.guide']);
            json_out(['ok' => (bool) $ai['ok'], 'configured' => true,
                      'answer' => $ai['ok'] ? $ai['text'] : ('Sorry — the assistant couldn’t answer just now. ' . (string) ($ai['error'] ?? ''))]);
        }

        /* ════ Mentorship & mentor–mentee management ════ */
        case 'mentorship_stats':    json_out(['ok' => true, 'stats' => Mentorship::adminStats()]);
        case 'mentorship_mentors':  json_out(['ok' => true, 'mentors' => Mentorship::adminMentors((string) ($_GET['segment'] ?? ''), (string) ($_GET['approval'] ?? ''), (string) ($_GET['q'] ?? ''))]);
        case 'mentorship_pairings': json_out(['ok' => true, 'pairings' => Mentorship::adminPairings((string) ($_GET['segment'] ?? ''), (string) ($_GET['status'] ?? ''), isset($_GET['cohort']) && $_GET['cohort'] !== '' ? (int) $_GET['cohort'] : -1, (string) ($_GET['q'] ?? ''))]);
        case 'mentorship_inactive': json_out(['ok' => true, 'pairs' => Mentorship::inactivePairs((int) ($_GET['days'] ?? 0))]);

        /* ── Report §20 — the promotion queue. The AI writes the case; this
           endpoint never changes a level. mem_save remains the one path that
           does, so there is no second promote route to drift. ── */
        case 'promotion_queue':
            json_out(['ok' => true, 'queue' => Promotion::queue()]);

        case 'promotion_review': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!av_rate_ok('promotion_review', 20, 600)) json_out(['ok' => false, 'error' => 'Too many reviews — wait a moment.'], 429);
            $r = Promotion::review((int) ($body['user_id'] ?? 0), !empty($body['force']));
            json_out(['ok' => !empty($r['ok']), 'review' => $r['review'] ?? null, 'error' => $r['reason'] ?? null]);
        }

        case 'promotion_defer': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $actor = av_admin_role() ?: 'admin';
            $r = Promotion::defer((int) ($body['user_id'] ?? 0), (string) ($body['note'] ?? ''), $actor);
            if (!empty($r['ok'])) AdminAudit::log('rules', 'promotion_deferred', (string) ($body['user_id'] ?? 0), 'Set a promotion review aside');
            json_out($r, !empty($r['ok']) ? 200 : 422);
        }

        case 'promotion_reopen': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $r = Promotion::reopen((int) ($body['user_id'] ?? 0), av_admin_role() ?: 'admin');
            json_out($r, !empty($r['ok']) ? 200 : 422);
        }

        // Report §21/§31/§38 — the leadership brief. Read the latest, or write one
        // now. The figures are always counted, never inferred; see lib/Brief.php.
        /* ════ AI Ops — is the machinery running, and what is it costing ════ */
        case 'aiops': {
            $days = (int) ($_GET['days'] ?? 7);
            json_out(['ok' => true, 'ops' => AiOps::snapshot($days)]);
        }

        /* Run the scheduled tasks by hand. The board's most useful button: when
           the heartbeat says cron is dead, the next question is always whether
           the tasks themselves still work, and this answers it without shell
           access. Deliberately NOT a force — it runs the same self-limiting
           sweeps cron runs, so pressing it twice does nothing the second time. */
        case 'aiops_cron': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $out = [];
            $t0 = microtime(true);
            if (class_exists('Accountability')) $out['accountability'] = AiOps::run('accountability', static fn() => Accountability::sweep());
            if (class_exists('Agenda'))         $out['agendas']        = AiOps::run('agendas', static fn() => Agenda::sweep());
            if (class_exists('MeetingClock'))   $out['meeting_clock']  = AiOps::run('meeting_clock', static fn() => MeetingClock::sweep());
            if (class_exists('Promotion'))      $out['promotions']     = AiOps::run('promotions', static fn() => Promotion::sweep());
            if (class_exists('Brief')) {
                AiOps::run('brief', static function () use (&$out) {
                    $wrote = [];
                    foreach (['week', 'month'] as $bp) {
                        $g = Brief::generate($bp);
                        if (empty($g['skipped'])) $wrote[] = $bp === 'week' ? 'the weekly brief' : 'the monthly brief';
                    }
                    $out['brief'] = $wrote ? implode(' and ', $wrote) : 'nothing due';
                    return $wrote ? ['wrote' => implode(' and ', $wrote), 'skipped' => false]
                                  : ['skipped' => true, 'why' => 'no brief was due'];
                });
            }
            if (class_exists('AdminAudit')) { try { AdminAudit::log('rules', 'aiops_cron_run', '', 'Ran the scheduled AI tasks by hand from the Ops board'); } catch (Throwable $e) {} }
            json_out(['ok' => true, 'ran' => $out, 'ms' => (int) round((microtime(true) - $t0) * 1000)]);
        }

        case 'brief_latest': {
            $period = (string) ($_GET['period'] ?? 'week');
            if (!in_array($period, Brief::periods(), true)) $period = 'week';
            json_out(['ok' => true, 'period' => $period, 'brief' => Brief::latest($period)]);
        }

        case 'brief_run': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            // Each run is a model call and a full sweep of every pairing, so it is
            // rate limited like the other AI actions rather than left to a button.
            if (!av_rate_ok('brief_run', 6, 600)) json_out(['ok' => false, 'error' => 'Too many briefs — wait a moment.'], 429);
            $period = (string) ($body['period'] ?? 'week');
            if (!in_array($period, Brief::periods(), true)) json_out(['ok' => false, 'error' => 'Unknown period.'], 422);
            $r = Brief::generate($period, true);
            AdminAudit::log('rules', 'brief_generated', $period, 'Generated the ' . $period . ' leadership brief');
            json_out(['ok' => !empty($r['ok']), 'period' => $period, 'brief' => Brief::latest($period)]);
        }

        // Report §15 — every active pairing graded Green/Amber/Red, worst first,
        // each carrying the behaviour that produced the grade. §3A is explicit
        // that character must not collapse to a number, so the reasons travel
        // with the status and the UI shows them rather than a bare dot.
        case 'mentorship_health': {
            $rows = [];
            foreach (Accountability::activePairs() as $p) {
                $h = Accountability::health($p['id']);
                $rows[] = [
                    'id'      => $p['id'],
                    'mentor'  => $p['mentor'],
                    'mentee'  => $p['mentee'],
                    'segment' => $p['segment'],
                    'status'  => $h['status'],
                    'rate'    => $h['rate'],
                    'streak'  => $h['miss_streak'],
                    'quiet'   => $h['quiet_days'],
                    'reasons' => $h['reasons'],
                    'escalations' => count(Accountability::historyFor($p['id'])),
                ];
            }
            $rank = ['red' => 0, 'amber' => 1, 'green' => 2];
            usort($rows, fn($a, $b) => [$rank[$a['status']], -$a['streak']] <=> [$rank[$b['status']], -$b['streak']]);
            $counts = ['red' => 0, 'amber' => 0, 'green' => 0];
            foreach ($rows as $r) $counts[$r['status']]++;
            json_out(['ok' => true, 'pairs' => $rows, 'counts' => $counts, 'since' => Accountability::watermark()]);
        }
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

        /* ════ D'Vanguard National Summit — seat claims ════
           The public page at /academy/dns/ is an unauthenticated intake with no
           other surface: without these four actions a seat claim lands in the
           database and nobody ever sees it. `mail` on each row is the delivery
           the registrant actually got, so a misconfigured mailer shows up as a
           resendable queue instead of silence. */
        case 'summit_list': {
            $rows = Summit::search(
                (string) ($_GET['q'] ?? ''),
                (string) ($_GET['mail'] ?? '')
            );
            json_out([
                'ok'      => true,
                'rows'    => $rows,
                'stats'   => Summit::stats(),
                'edition' => Summit::EDITION,
                'summit'  => ['name' => Summit::facts()['name'], 'label' => Summit::facts()['edition']],
                // The list leads with delivery, so it needs to say whether mail
                // could work at all — an empty "sent" column means one thing when
                // SMTP is configured and quite another when it is not.
                'mail'    => ['configured' => Mailer::configured()],
            ]);
        }
        case 'summit_resend': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $id  = (int) ($body['id'] ?? 0);
            $row = Summit::find($id);
            if (!$row) json_out(['ok' => false, 'error' => 'No such registration.'], 404);
            $res = Summit::notify($id, $row);
            json_out([
                'ok'     => (bool) $res['ok'],
                'id'     => $id,
                'detail' => $res['ok']
                    ? ('Confirmation sent to ' . $row['email'] . '.')
                    : ('Send failed: ' . ($res['error'] ?: 'unknown error')
                       . (Mailer::configured() ? '' : ' — SMTP is not configured. Set SMTP_HOST, SMTP_USERNAME and AV_SMTP_PASSWORD, then try again.')),
            ]);
        }
        case 'summit_resend_failed': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            // Bounded per call: a backlog is cleared in batches rather than in one
            // request that a shared host will time out halfway through.
            $sent = 0; $failed = 0; $last = '';
            foreach (Summit::search('', 'failed', 25) as $row) {
                $res = Summit::notify((int) $row['id'], $row);
                if ($res['ok']) $sent++; else { $failed++; $last = (string) $res['error']; }
            }
            json_out(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'detail' => $failed
                ? ($sent . ' sent, ' . $failed . ' still failing — ' . ($last ?: 'unknown error'))
                : ($sent ? ($sent . ' confirmation(s) sent.') : 'Nothing was waiting to be sent.')]);
        }
        case 'summit_export': {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="summit-' . Summit::EDITION . '-registrations.csv"');
            $out = fopen('php://output', 'w');
            // 'Source' is how a seat arrived — 'dns-page' for the summit page
            // itself, 'sts' for a claim that started on the Street-To-Stardom
            // site. Without the column the referral is recorded and unreadable.
            fputcsv($out, ['ID', 'Name', 'Email', 'Phone', 'Location', 'Organisation',
                           'Pillar', 'Seats', 'Heard via', 'Source', 'Message', 'Emailed',
                           'Mail error', 'Registered']);
            foreach (Summit::search((string) ($_GET['q'] ?? ''), (string) ($_GET['mail'] ?? ''), 2000) as $r) {
                fputcsv($out, [$r['id'], $r['name'], $r['email'], $r['phone'], $r['location'],
                               $r['organisation'], $r['pillar'], $r['seats'], $r['heard'],
                               $r['source'] ?? '', $r['message'],
                               ($r['notified_at'] ?? '') !== '' ? 'yes' : 'no',
                               $r['notify_error'] ?? '', $r['created_at']]);
            }
            fclose($out); exit;
        }

        /* ════ Setting the AI up, from the Studio ════
           Credentials used to live only in .env or config.php — a file above the
           web root on shared hosting, which most administrators will never edit.
           Keys are encrypted at rest and NEVER returned to the browser: the
           Studio sees a masked preview and nothing more. */
        case 'setup_get':
            json_out(array_merge(['ok' => true], AvSettings::describe(), ['testable' => AvSettings::testable()]));

        case 'setup_save': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $vals = (array) ($body['values'] ?? []);
            $res = AvSettings::save($vals, av_admin_role() ?: 'admin');
            // Audit the KEYS that changed, never the values — an audit trail that
            // records a credential is a credential store nobody remembers exists.
            if (($res['saved'] ?? 0) > 0) {
                AdminAudit::log('rules', 'setup_saved', implode(',', array_keys($vals)),
                    'Updated ' . (int) $res['saved'] . ' AI setup value(s)');
            }
            json_out(array_merge($res, AvSettings::describe(), ['testable' => AvSettings::testable()]));
        }

        case 'setup_test': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!av_rate_ok('setup_test', 30, 300)) json_out(['ok' => false, 'error' => 'Too many tests — wait a moment.'], 429);
            json_out(array_merge(['ok' => true], AvSettings::test((string) ($body['what'] ?? ''))));
        }

        /* ════ The AI bench, the chat console, and the approval queue ════
           Testing a prompt used to mean waiting for a real meeting to end and
           reading what came out. These let an administrator exercise every
           capability on demand, talk to the assistant, and approve or reject
           anything it proposes for itself. */
        case 'ai_status':
            json_out([
                'ok'            => true,
                'capabilities'  => AvLab::capabilities(),
                'tools'         => AvTools::describe(),
                'agent'         => ['available' => AvAgent::available(), 'provider' => AvAgent::provider(), 'tiers' => AvAgent::tiersFor('admin')],
                'search'        => ['provider' => AvWeb::searchProvider(), 'why' => AvWeb::whyUnavailable('web_search')],
                'pending'       => AvTools::pendingCount(),
            ]);

        case 'ai_run': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            // Each run costs a model call, so this is rate-limited per admin
            // session rather than left open to a held-down button.
            if (!av_rate_ok('ai_run', 60, 300)) json_out(['ok' => false, 'error' => 'Too many runs — wait a moment.'], 429);
            $r = AvLab::run((string) ($body['capability'] ?? ''), [
                'text' => (string) ($body['text'] ?? ''),
                'tool' => (string) ($body['tool'] ?? ''),
                'args' => (array)  ($body['args'] ?? []),
            ], av_admin_role() ?: 'admin');
            json_out(array_merge(['ok' => !empty($r['ok'])], $r));
        }

        case 'ai_chat': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            if (!av_rate_ok('ai_chat', 90, 300)) json_out(['ok' => false, 'error' => 'Too many messages — wait a moment.'], 429);
            $history = [];
            foreach ((array) ($body['history'] ?? []) as $h) {
                if (!is_array($h)) continue;
                $history[] = ['role' => (string) ($h['role'] ?? 'user'), 'text' => (string) ($h['text'] ?? '')];
            }
            $r = AvAgent::run((string) ($body['message'] ?? ''), [
                'history' => $history,
                'tiers'   => AvAgent::tiersFor('admin'),
                'actor'   => av_admin_role() ?: 'admin',
            ]);
            json_out(array_merge(['ok' => !empty($r['ok'])], $r, ['pending' => AvTools::pendingCount()]));
        }

        case 'ai_proposals':
            json_out(['ok' => true, 'proposals' => AvTools::proposals((string) ($_GET['status'] ?? 'pending'))]);

        case 'ai_proposal_decide': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $id  = (int) ($body['id'] ?? 0);
            $act = (string) ($body['decision'] ?? '');
            $actor = av_admin_role() ?: 'admin';
            if ($act === 'approve') {
                $r = AvTools::approve($id, $actor);
                if (empty($r['ok'])) json_out($r, 422);
                AdminAudit::log('rules', 'ai_proposal_approved', (string) $id,
                    'Approved the AI\'s ' . (string) ($r['kind'] ?? '') . ' proposal for ' . (string) ($r['target'] ?? ''));
            } elseif ($act === 'reject') {
                if (!AvTools::reject($id, $actor, (string) ($body['note'] ?? ''))) {
                    json_out(['ok' => false, 'error' => 'Could not reject that proposal.'], 422);
                }
                AdminAudit::log('rules', 'ai_proposal_rejected', (string) $id, 'Rejected an AI proposal');
            } else {
                json_out(['ok' => false, 'error' => 'Decision must be approve or reject.'], 422);
            }
            json_out(['ok' => true, 'proposals' => AvTools::proposals('pending'), 'pending' => AvTools::pendingCount()]);
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

        // ---- NextGen Vanguard programme page (admin-editable content + plans) ----
        // The whole /academy/ngv/ page is DB-driven; these read/write the JSON
        // content document (lib/Ngv.php → app_meta). Available to any admin role.
        case 'ngv_get':
            json_out(['ok' => true, 'content' => Ngv::get(), 'defaults' => Ngv::defaults(), 'has_previous' => Ngv::hasPrevious()]);
        case 'ngv_save': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $patch = is_array($body['content'] ?? null) ? $body['content'] : $body;
            if (!$patch) json_out(['ok' => false, 'error' => 'Nothing to save.'], 422);
            $saved = Ngv::save($patch);
            AdminAudit::log('content', 'ngv_save', 'ngv', 'NextGen Vanguard page updated' . (empty($saved['enabled']) ? ' (hidden)' : ''));
            json_out(['ok' => true, 'content' => $saved]);
        }
        case 'ngv_reset': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            AdminAudit::log('content', 'ngv_reset', 'ngv', 'NextGen Vanguard page reset to defaults');
            json_out(['ok' => true, 'content' => Ngv::reset(), 'has_previous' => Ngv::hasPrevious()]);
        }
        case 'ngv_restore': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $restored = Ngv::restorePrevious();
            if ($restored === null) json_out(['ok' => false, 'error' => 'No previous version to restore.'], 404);
            AdminAudit::log('content', 'ngv_restore', 'ngv', 'NextGen Vanguard page restored to previous version');
            json_out(['ok' => true, 'content' => $restored, 'has_previous' => Ngv::hasPrevious()]);
        }

        // ---- Sign-in security policy (superadmin) ----
        case 'auth_policy_get':
            json_out(['ok' => true, 'policy' => AuthPolicy::get(), 'defaults' => AuthPolicy::defaults(), 'google_configured' => GoogleAuth::configured()]);
        case 'auth_policy_save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            json_out(['ok' => true, 'policy' => AuthPolicy::save(is_array($body['policy'] ?? null) ? $body['policy'] : $body)]);

        /* ---- The rules engine: Afrovanguard's constitution, as data ----
           Every threshold the accountability engine obeys. Superadmin-only:
           these govern promotions and escalations across the whole movement. */
        case 'rules_get':
            json_out(['ok' => true] + AvRules::describe());
        case 'rules_save': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $vals = is_array($body['rules'] ?? null) ? $body['rules'] : [];
            if (!$vals) json_out(['ok' => false, 'error' => 'Nothing to save.'], 422);
            // Undo must restore the previous OVERRIDE state, not the previous
            // resolved value: a rule that was running on its default or on an
            // AV_* env value has no override, and recording the resolved number
            // as "from" would make undo pin it into the database forever.
            $keys      = array_keys($vals);
            $rawBefore = AvRules::rawOverrides($keys);
            $before    = AvRules::all();

            $res = AvRules::save($vals, av_admin_actor());
            if (!$res['ok']) {
                json_out(['ok' => false, 'error' => $res['conflicts']
                    ? implode(' ', $res['conflicts'])
                    : 'Some values were rejected.', 'errors' => $res['errors'], 'conflicts' => $res['conflicts']], 422);
            }

            $after = AvRules::all();
            $changed = [];
            $undoValues = [];
            foreach ($keys as $k) {
                if (($before[$k] ?? null) === ($after[$k] ?? null)) continue;
                $changed[$k] = ['from' => $before[$k] ?? null, 'to' => $after[$k] ?? null];
                // null here means "there was no override" → undo removes it.
                $undoValues[$k] = $rawBefore[$k] ?? null;
            }
            AdminAudit::log('rules', 'rules_saved', implode(',', array_keys($changed)),
                $changed ? 'Changed ' . count($changed) . ' rule(s)' : 'Saved with no effective change',
                $changed ? ['class' => 'AvRules', 'op' => 'save', 'args' => ['values' => $undoValues], 'label' => 'Undo rule change'] : null);
            json_out(['ok' => true, 'changed' => $changed] + AvRules::describe());
        }
        case 'rules_reset': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $key = trim((string) ($body['key'] ?? ''));
            if ($key === '') {
                $prev = AvRules::rawOverrides();
                $r = AvRules::resetAll(av_admin_actor());
                if (!$r['ok']) json_out(['ok' => false, 'error' => 'Could not reset.'], 500);
                AdminAudit::log('rules', 'rules_reset_all', '', 'Reset every rule to its default',
                    $prev ? ['class' => 'AvRules', 'op' => 'save', 'args' => ['values' => $prev], 'label' => 'Restore previous rules'] : null);
            } else {
                $prevRaw = AvRules::rawOverride($key);
                // A reset shifts policy as much as a save, so it obeys the same
                // coherence gate rather than sneaking past it.
                $r = AvRules::resetChecked($key);
                if (!$r['ok']) {
                    json_out(['ok' => false, 'error' => $r['conflicts']
                        ? implode(' ', $r['conflicts'])
                        : 'Unknown rule.', 'conflicts' => $r['conflicts']], 422);
                }
                AdminAudit::log('rules', 'rules_reset', $key, 'Reset ' . $key . ' to its default',
                    $prevRaw !== null ? ['class' => 'AvRules', 'op' => 'save', 'args' => ['values' => [$key => $prevRaw]], 'label' => 'Restore previous value'] : null);
            }
            json_out(['ok' => true] + AvRules::describe());
        }

        /* ---- The knowledge base the assistants are fed ---- */
        case 'kb_list':
            json_out(['ok' => true, 'entries' => AvKnowledge::listAll((string) ($_GET['scope'] ?? '')), 'scopes' => AvKnowledge::SCOPES]);
        case 'kb_save': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $id = (int) ($body['id'] ?? 0);
            $res = AvKnowledge::save($id, $body, av_admin_actor());
            if (!$res['ok']) json_out(['ok' => false, 'error' => $res['error']], 422);
            AdminAudit::log('knowledge', $id > 0 ? 'kb_updated' : 'kb_created', (string) $res['id'],
                trim((string) ($body['title'] ?? '')));
            json_out(['ok' => true, 'id' => $res['id'], 'entries' => AvKnowledge::listAll()]);
        }
        case 'kb_delete': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $id = (int) ($body['id'] ?? 0);
            $prev = AvKnowledge::get($id);
            if (!$prev) json_out(['ok' => false, 'error' => 'That entry no longer exists.'], 404);
            AvKnowledge::remove($id);
            AdminAudit::log('knowledge', 'kb_deleted', (string) $id, $prev['title'],
                ['class' => 'AvKnowledge', 'op' => 'restore', 'args' => ['fields' => $prev], 'label' => 'Restore entry']);
            json_out(['ok' => true, 'entries' => AvKnowledge::listAll()]);
        }

        /* ---- AI prompt templates ---- */
        case 'prompts_list':
            json_out(['ok' => true, 'prompts' => AvPrompts::describe()]);
        case 'prompts_save': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $key = trim((string) ($body['key'] ?? ''));
            $res = AvPrompts::save($key, (string) ($body['text'] ?? ''), av_admin_actor());
            if (!$res['ok']) json_out(['ok' => false, 'error' => $res['error'] ?: 'Unknown prompt.'], 422);
            AdminAudit::log('prompts', $res['cleared'] ? 'prompt_reset' : 'prompt_saved', $key,
                $res['cleared'] ? 'Reverted to the built-in prompt' : 'Edited the prompt');
            json_out(['ok' => true, 'cleared' => $res['cleared'], 'prompts' => AvPrompts::describe()]);
        }
        case 'prompts_reset': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $key = trim((string) ($body['key'] ?? ''));
            if (!AvPrompts::reset($key)) json_out(['ok' => false, 'error' => 'Unknown prompt.'], 422);
            AdminAudit::log('prompts', 'prompt_reset', $key, 'Reverted to the built-in prompt');
            json_out(['ok' => true, 'prompts' => AvPrompts::describe()]);
        }
        /* ---- Promotion recommendations (evidence for leadership, never a decision) ----
           POST, and in the $writing list: assessing a member reconciles their
           mentorship sessions, which finalises stale ones and can call Google. A
           GET that mutates state for a caller-chosen user_id is not a read. */
        case 'level_recommend': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $uid = (int) ($body['user_id'] ?? 0);
            if ($uid <= 0) json_out(['ok' => false, 'error' => 'A member id is required.'], 422);
            json_out(['ok' => true, 'recommendation' => Levels::recommend($uid)]);
        }

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
            try {
                $slug = $repo->save([
                    // The reference code identifies the entry being edited, so
                    // correcting the slug renames it rather than creating a second copy.
                    'ref_code' => (string) ($body['ref_code'] ?? ''),
                    'slug' => trim((string) ($body['slug'] ?? '')) ?: $title, 'title' => $title,
                    'dek' => trim((string) ($body['dek'] ?? '')), 'category' => trim((string) ($body['category'] ?? 'Dispatch')),
                    // Rendered as markup on the public article page (a byline may carry a
                    // link), so it gets the same sanitizer the body does rather than
                    // being trusted because the field name ends in _html.
                    'authors_html' => Embeds::sanitize(trim((string) ($body['authors_html'] ?? 'The Afrovanguard Team'))),
                    'published' => date('M j, Y', $pubTs), 'published_at' => date('Y-m-d', $pubTs),
                    'read_minutes' => $read, 'gradient' => av_card_gradient((string) ($body['gradient'] ?? '')),
                    'mc_title' => trim((string) ($body['mc_title'] ?? $title)),
                    // Asset URLs are validated on the way in, not just escaped on the
                    // way out: these land in `style="background-image:url('…')"` and in
                    // `src`, and an editor can write them (audit finding H-2).
                    'cover_url' => av_safe_asset_url((string) ($body['cover_url'] ?? '')),
                    'og_image' => av_safe_asset_url((string) ($body['og_image'] ?? '')), 'body_html' => $cleanBody,
                    'audio_url' => av_safe_asset_url((string) ($body['audio_url'] ?? '')),
                    'featured' => !empty($body['featured']), 'status' => ($body['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
                    'format' => (string) ($body['format'] ?? 'standard'),
                    'series' => trim((string) ($body['series'] ?? '')), 'series_part' => (int) ($body['series_part'] ?? 0),
                    'sections' => $sections, 'related' => array_values(array_filter((array) ($body['related'] ?? []))),
                ]);
            } catch (RuntimeException $ex) {
                if (strncmp($ex->getMessage(), 'slug-taken:', 11) !== 0) throw $ex;
                json_out(['ok' => false, 'error' => trim(substr($ex->getMessage(), 11))], 422);
            }
            Sitemap::rebuild();
            $saved = $repo->bySlug($slug, true);
            json_out(['ok' => true, 'slug' => $slug, 'url' => diary_url($slug . '/'), 'sections' => $sections,
                      'ref_code' => (string) ($saved['ref_code'] ?? '')]);

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
                'cover_url' => av_safe_asset_url((string) ($body['cover_url'] ?? '')),
                'og_image' => av_safe_asset_url((string) ($body['og_image'] ?? '')),
                'category' => trim((string) ($body['category'] ?? 'Programme')), 'level' => trim((string) ($body['level'] ?? 'All levels')),
                'format' => trim((string) ($body['format'] ?? 'In-person')), 'duration' => trim((string) ($body['duration'] ?? '')),
                'price' => trim((string) ($body['price'] ?? 'Free')), 'location' => trim((string) ($body['location'] ?? 'Alimosho, Lagos')),
                'gradient' => av_card_gradient((string) ($body['gradient'] ?? '')), 'outcomes' => trim((string) ($body['outcomes'] ?? '')),
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
