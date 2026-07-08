<?php
/**
 * db/seed_admin.php — provision (or repair) the default Super Admin and print
 * the sign-in credentials. Safe to run any number of times.
 *
 *   php db/seed_admin.php                       # ensure + reveal credentials
 *   AV_SUPERADMIN_EMAIL=me@org php db/seed_admin.php
 *   AV_SUPERADMIN_PASSWORD='S3cret!' php db/seed_admin.php   # set an exact password
 *   php db/seed_admin.php --reveal              # just show a pending generated password
 *
 * The account it creates:
 *   • admin_users(email → superadmin)  → full Studio access (roles, security,
 *     DB tools, destructive actions) and, through it, every other admin.
 *   • lms_users(role='admin', active, verified, password set) → top of the
 *     member RBAC ladder → manage every manager/member and mentorship.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/lib/bootstrap.php';
Database::pdo(); // ensure the schema/tables exist

$revealOnly = in_array('--reveal', $argv, true);

if ($revealOnly) {
    $pw = SuperAdmin::pendingPassword();
    if ($pw === '') { fwrite(STDOUT, "No pending generated password (an explicit AV_SUPERADMIN_PASSWORD is in use, or it was already revealed).\n"); exit(0); }
    fwrite(STDOUT, "Super Admin email:    " . SuperAdmin::defaultEmail() . "\n");
    fwrite(STDOUT, "Super Admin password: " . $pw . "\n");
    exit(0);
}

$res   = SuperAdmin::ensure();
$email = $res['email'];
$envPw = (string) (getenv('AV_SUPERADMIN_PASSWORD') ?: '');
$pw    = $res['password'] ?? SuperAdmin::pendingPassword();

$line = str_repeat('─', 60);
fwrite(STDOUT, "\n{$line}\n  AFROVANGUARD — Default Super Admin\n{$line}\n");
fwrite(STDOUT, "  Status:   " . ($res['created'] ? 'created' : 'ensured (already present)') . "\n");
fwrite(STDOUT, "  Studio:   superadmin (full access to all admins & managers)\n");
fwrite(STDOUT, "  Email:    {$email}\n");
if ($envPw !== '') {
    fwrite(STDOUT, "  Password: (using AV_SUPERADMIN_PASSWORD from your environment)\n");
} elseif ($pw !== '') {
    fwrite(STDOUT, "  Password: {$pw}\n");
    fwrite(STDOUT, "            ↑ generated once. Save it now, then rotate it in\n");
    fwrite(STDOUT, "              Studio → Admins, or set AV_SUPERADMIN_PASSWORD.\n");
} else {
    fwrite(STDOUT, "  Password: (already set on a previous run — reset via Studio → Admins\n");
    fwrite(STDOUT, "              or by setting AV_SUPERADMIN_PASSWORD and re-running)\n");
}
fwrite(STDOUT, "{$line}\n");
fwrite(STDOUT, "  Sign in:  /login   (or the break-glass token at /admin)\n");
fwrite(STDOUT, "  Note:     if Google sign-in is configured, use \"Continue with\n");
fwrite(STDOUT, "            Google\" with your org account — you'll land as superadmin.\n");
fwrite(STDOUT, "{$line}\n\n");
