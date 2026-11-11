<?php
/**
 * احراز هویت: نشست، «مرا به خاطر بسپار»، CSRF، محدودیت تلاش ورود.
 */
require_once __DIR__ . '/db.php';

const REMEMBER_COOKIE = 'bale_rm';

function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function auth_user(bool $fresh = false): ?array {
    static $cached = null;
    if ($cached !== null && !$fresh) return $cached;
    if (empty($_SESSION['uid'])) { $cached = null; return null; }
    $st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int)$_SESSION['uid']]);
    $row = $st->fetch();
    if (!$row || !(int)$row['is_active']) { $cached = null; return null; }
    return $cached = $row;
}

function auth_is_admin(): bool {
    $u = auth_user();
    return $u && $u['role'] === 'admin';
}

/* ---------- «مرا به خاطر بسپار» ---------- */
function auth_issue_remember(int $userId): void {
    $days     = (int)db_get('remember_days', (string)REMEMBER_DAYS);
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $st = db()->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at, created_at) VALUES (?,?,?,?,?)');
    $st->execute([
        $userId,
        $selector,
        hash('sha256', $validator),
        date('Y-m-d H:i:s', time() + $days * 86400),
        date('Y-m-d H:i:s'),
    ]);
    setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires'  => time() + $days * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

function auth_clear_remember(): void {
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        $parts = explode(':', (string)$_COOKIE[REMEMBER_COOKIE], 2);
        if ($parts[0] !== '') {
            $st = db()->prepare('DELETE FROM remember_tokens WHERE selector = ?');
            $st->execute([$parts[0]]);
        }
    }
    setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}

function auth_try_remember(): void {
    if (!empty($_SESSION['uid']) || empty($_COOKIE[REMEMBER_COOKIE])) return;
    $parts = explode(':', (string)$_COOKIE[REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) return;
    [$selector, $validator] = $parts;

    $st = db()->prepare('SELECT * FROM remember_tokens WHERE selector = ? LIMIT 1');
    $st->execute([$selector]);
    $row = $st->fetch();
    if (!$row) return;
    if (strtotime((string)$row['expires_at']) < time()) {
        db()->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([(int)$row['id']]);
        return;
    }
    if (!hash_equals((string)$row['validator_hash'], hash('sha256', $validator))) {
        // توکن دزدی احتمالی: همهٔ توکن‌های این کاربر را باطل کن
        db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([(int)$row['user_id']]);
        auth_clear_remember();
        return;
    }

    $ust = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $ust->execute([(int)$row['user_id']]);
    $user = $ust->fetch();
    if (!$user) return;

    db()->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([(int)$row['id']]);
    auth_login($user, true);
}

/* ---------- ورود / خروج ---------- */
function auth_login(array $user, bool $remember = false): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['uid']  = (int)$user['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $_SESSION['auth'] = true;                 // سازگاری با کدهای قدیمی
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), (int)$user['id']]);
    if ($remember) auth_issue_remember((int)$user['id']);
    auth_log('login', (string)$user['username']);
}

function auth_logout(): void {
    auth_clear_remember();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'] ?: '/', 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
    }
}

/* ---------- CSRF ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return (string)$_SESSION['csrf'];
}

function csrf_ok($token): bool {
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals((string)$_SESSION['csrf'], $token);
}

/* ---------- محدودیت تلاش ورود (قابل تنظیم از پنل مدیریت) ---------- */
function login_blocked(string $ip): bool {
    $maxF = (int)db_get('max_login_fails', (string)MAX_LOGIN_FAILS);
    $win  = max(60, ((int)db_get('login_window_min', (string)round(LOGIN_WINDOW / 60))) * 60);
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND ok = 0 AND created_at > ?');
    $st->execute([$ip, date('Y-m-d H:i:s', time() - $win)]);
    return (int)$st->fetchColumn() >= max(1, $maxF);
}

function login_record(string $ip, string $username, bool $ok): void {
    if ($ok) {
        db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
        return;
    }
    db()->prepare('INSERT INTO login_attempts (ip, username, ok, created_at) VALUES (?,?,0,?)')
        ->execute([$ip, mb_substr($username, 0, 80), date('Y-m-d H:i:s')]);
}

/* ---------- گزارش فعالیت ---------- */
function auth_log(string $action, string $target = '', string $meta = ''): void {
    $u = auth_user();
    db()->prepare('INSERT INTO activity (user_id, action, target, meta, ip, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$u ? (int)$u['id'] : null, mb_substr($action, 0, 32), mb_substr($target, 0, 255), mb_substr($meta, 0, 255), client_ip(), date('Y-m-d H:i:s')]);
}

/* ---------- اعتبارسنجی ثبت‌نام ---------- */
function auth_validate_username(string $u): ?string {
    if (!preg_match('/^[a-zA-Z0-9_.]{3,32}$/', $u)) return 'نام کاربری باید ۳ تا ۳۲ نویسهٔ لاتین، عدد، نقطه یا زیرخط باشد.';
    return null;
}

function auth_validate_password(string $p): ?string {
    if (strlen($p) < 8) return 'رمز عبور باید حداقل ۸ نویسه باشد.';
    if (strlen($p) > 72) return 'رمز عبور بیش از حد بلند است.';
    return null;
}
