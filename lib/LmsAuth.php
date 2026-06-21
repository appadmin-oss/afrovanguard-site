<?php
/**
 * lib/LmsAuth.php — learner / instructor accounts for the Academy.
 * DB-backed sessions (httpOnly cookie holding a random token; only its
 * SHA-256 hash is stored, so a DB leak ≠ session takeover).
 */
declare(strict_types=1);

final class LmsAuth
{
    const COOKIE = 'av_lms';
    const TTL = 2592000; // 30 days

    private static ?array $cache = null;
    private static bool $checked = false;

    public static function register(string $name, string $email, string $password, string $role = 'learner'): array
    {
        $name = trim($name); $email = strtolower(trim($email));
        if ($name === '') return ['ok' => false, 'error' => 'Please enter your name.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Enter a valid email address.'];
        if (strlen($password) < 8) return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        $db = Database::pdo();
        $ex = $db->prepare('SELECT id FROM lms_users WHERE email = ?'); $ex->execute([$email]);
        if ($ex->fetchColumn()) return ['ok' => false, 'error' => 'An account with that email already exists. Try signing in.'];
        $role = in_array($role, ['learner', 'instructor'], true) ? $role : 'learner';
        $db->prepare('INSERT INTO lms_users (name, email, password_hash, role) VALUES (?,?,?,?)')
           ->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT), $role]);
        $id = (int) $db->lastInsertId();
        self::startSession($id);
        return ['ok' => true, 'user' => self::publicUser(self::byId($id))];
    }

    public static function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $u = self::byEmail($email);
        if (!$u || $u['status'] !== 'active' || !password_verify($password, $u['password_hash'])) {
            return ['ok' => false, 'error' => 'Incorrect email or password.'];
        }
        Database::pdo()->prepare('UPDATE lms_users SET last_login = datetime(\'now\') WHERE id = ?')->execute([$u['id']]);
        self::startSession((int) $u['id']);
        return ['ok' => true, 'user' => self::publicUser($u)];
    }

    public static function logout(): void
    {
        $tok = $_COOKIE[self::COOKIE] ?? '';
        if ($tok && preg_match('/^[a-f0-9]{40}$/', $tok)) {
            Database::pdo()->prepare('DELETE FROM lms_sessions WHERE token_hash = ?')->execute([hash('sha256', $tok)]);
        }
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        unset($_COOKIE[self::COOKIE]); self::$cache = null; self::$checked = false;
    }

    public static function user(): ?array
    {
        if (self::$checked) return self::$cache;
        self::$checked = true;
        $tok = $_COOKIE[self::COOKIE] ?? '';
        if (!$tok || !preg_match('/^[a-f0-9]{40}$/', $tok)) return self::$cache = null;
        $st = Database::pdo()->prepare(
            'SELECT u.* FROM lms_sessions s JOIN lms_users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_at > datetime(\'now\') AND u.status = \'active\''
        );
        $st->execute([hash('sha256', $tok)]);
        $u = $st->fetch();
        return self::$cache = ($u ?: null);
    }

    public static function require(): array { $u = self::user(); if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401); return $u; }
    public static function publicUser(array $u): array { return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']]; }

    private static function byId(int $id): ?array { $s = Database::pdo()->prepare('SELECT * FROM lms_users WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
    private static function byEmail(string $e): ?array { $s = Database::pdo()->prepare('SELECT * FROM lms_users WHERE email = ?'); $s->execute([$e]); return $s->fetch() ?: null; }

    private static function startSession(int $userId): void
    {
        $token = bin2hex(random_bytes(20));
        Database::pdo()->prepare('INSERT INTO lms_sessions (token_hash, user_id, ip, ua, expires_at) VALUES (?,?,?,?,?)')
            ->execute([hash('sha256', $token), $userId, av_client_ip(), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240), date('Y-m-d H:i:s', time() + self::TTL)]);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie(self::COOKIE, $token, ['expires' => time() + self::TTL, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[self::COOKIE] = $token; self::$cache = null; self::$checked = false;
    }
}
