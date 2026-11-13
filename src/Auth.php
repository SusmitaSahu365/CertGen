<?php
namespace CertGen;

class Auth
{
    private const MAX_FAILS = 5;       // failed logins allowed ...
    private const WINDOW_MIN = 10;     // ... within this many minutes

    private static ?array $user = null;

    public static function needsSetup(): bool
    {
        return (int) Db::val('SELECT COUNT(*) FROM users') === 0;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        if (empty($_SESSION['uid'])) {
            return null;
        }
        $u = Db::one('SELECT id, username, role FROM users WHERE id = ?', [(int) $_SESSION['uid']]);
        if (!$u) {
            unset($_SESSION['uid']);
            return null;
        }
        return self::$user = $u;
    }

    /** Call at the top of every protected page. */
    public static function require(): array
    {
        if (self::needsSetup()) {
            redirect('setup.php');
        }
        $u = self::user();
        if (!$u) {
            $next = basename($_SERVER['SCRIPT_NAME'] ?? '');
            redirect('login.php' . (preg_match('/^[a-z_]+\.php$/', $next) && $next !== 'index.php' ? '?next=' . urlencode($next) : ''));
        }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::require();
        if ($u['role'] !== 'admin') {
            http_response_code(403);
            layout_top('Not allowed');
            echo '<div class="alert alert-warning">Only administrators can open this page.</div>';
            layout_bottom();
            exit;
        }
        return $u;
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u && $u['role'] === 'admin';
    }

    /** @return true|string true on success, otherwise an error message */
    public static function attempt(string $username, string $password)
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_MIN * 60);
        Db::run('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        $fails = (int) Db::val(
            'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip = ? AND created_at >= ?',
            [$username, $ip, $since]
        );
        if ($fails >= self::MAX_FAILS) {
            return 'Too many failed attempts. Please wait ' . self::WINDOW_MIN . ' minutes and try again.';
        }

        $row = Db::one('SELECT id, password_hash FROM users WHERE username = ?', [$username]);
        // Always run a hash check so response time does not reveal whether the user exists.
        $hash = $row['password_hash'] ?? '$2y$10$usesomesillystringforsaltandhashabcdefghijklmnopqrstuv';
        $ok = password_verify($password, $hash) && $row;

        if (!$ok) {
            Db::run('INSERT INTO login_attempts (username, ip, created_at) VALUES (?, ?, ?)', [$username, $ip, now()]);
            return 'Incorrect username or password.';
        }

        Db::run('DELETE FROM login_attempts WHERE username = ? AND ip = ?', [$username, $ip]);
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $row['id'];
        self::$user = null;
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$user = null;
    }

    public static function createUser(string $username, string $password, string $role = 'staff'): int
    {
        return Db::insert(
            'INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), $role === 'admin' ? 'admin' : 'staff', now()]
        );
    }

    /** @return string|null error message, or null if the password is acceptable */
    public static function passwordProblem(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }
        return null;
    }
}
