<?php
/**
 * لایهٔ دیتابیس: اتصال PDO، ساخت اسکیما، تنظیمات، و مهاجرت داده‌های قدیمی JSON.
 */
require_once __DIR__ . '/../config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

function db_installed(): bool {
    return is_file(STORAGE_DIR . '/.installed');
}

/** یک بار در هر نصب اجرا می‌شود (وجود فایل قفل) */
function db_boot(): void {
    if (!is_dir(STORAGE_DIR)) @mkdir(STORAGE_DIR, 0770, true);
    if (!db_installed()) {
        db_install();
        @file_put_contents(STORAGE_DIR . '/.installed', date('c'));
        return;
    }
    db_upgrade();
}

/** ارتقای اسکیما برای نصب‌های موجود */
function db_upgrade(): void {
    if (db_get('schema_version', '0') === APP_VERSION) return;
    $col = db()->query("SHOW COLUMNS FROM users LIKE 'send_chat_id'")->fetch();
    if (!$col) db()->exec('ALTER TABLE users ADD COLUMN send_chat_id VARCHAR(64) NULL AFTER chat_id');
    $col = db()->query("SHOW COLUMNS FROM users LIKE 'use_default_bot'")->fetch();
    if (!$col) {
        db()->exec('ALTER TABLE users ADD COLUMN use_default_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER send_chat_id');
        // حساب‌های موجود به رفتار قبلی (دسترسی به ربات پیش‌فرض) ادامه می‌دهند
        db()->exec('UPDATE users SET use_default_bot = 1');
    }
    $col = db()->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();
    if (!$col) {
        db()->exec("ALTER TABLE users
            ADD COLUMN phone VARCHAR(24) NULL AFTER email,
            ADD COLUMN bio VARCHAR(280) NULL AFTER phone,
            ADD COLUMN avatar VARCHAR(120) NULL AFTER bio");
    }
    // یکتایی شمارهٔ موبایل (ورود با کد بله باید فقط به یک حساب برسد)
    $ix = db()->query("SHOW INDEX FROM users WHERE Key_name = 'uq_phone'")->fetch();
    if (!$ix) {
        try { db()->exec('ALTER TABLE users ADD UNIQUE KEY uq_phone (phone)'); }
        catch (Throwable $e) { /* در صورت وجود مقدار تکراری، ایندکس اضافه نمی‌شود */ }
    }

    // جدول کدهای یکبارمصرف (ورود با کد بله — سفیر)
    db()->exec("CREATE TABLE IF NOT EXISTS otp_codes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        phone VARCHAR(16) NOT NULL,
        user_id INT UNSIGNED NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        code_hash CHAR(64) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY k_phone_time (phone, created_at),
        KEY k_ip_time (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_set_default('otp_login', '0');
    db_set_default('safir_key', '');
    db_set_default('safir_bot_id', '');
    db_set_default('otp_ttl', '300');
    db_set('schema_version', APP_VERSION);
}

function db_install(): void {
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(80) NOT NULL,
        username VARCHAR(40) NOT NULL,
        email VARCHAR(190) NULL,
        phone VARCHAR(24) NULL,
        bio VARCHAR(280) NULL,
        avatar VARCHAR(120) NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','user') NOT NULL DEFAULT 'user',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        quota_bytes BIGINT UNSIGNED NULL,
        bot_token VARCHAR(255) NULL,
        chat_id VARCHAR(64) NULL,
        send_chat_id VARCHAR(64) NULL,
        use_default_bot TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        last_login_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_username (username),
        UNIQUE KEY uq_email (email),
        UNIQUE KEY uq_phone (phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS folders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        name VARCHAR(190) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_user_folder (user_id, name),
        KEY k_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS files (
        id CHAR(12) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        folder VARCHAR(190) NOT NULL DEFAULT '',
        name VARCHAR(255) NOT NULL,
        size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        file_id VARCHAR(255) NOT NULL,
        message_id BIGINT NULL,
        parts LONGTEXT NULL,
        share CHAR(24) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_share (share),
        KEY k_user_folder (user_id, folder),
        KEY k_user_date (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(64) NOT NULL,
        v TEXT NULL,
        PRIMARY KEY (k)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        selector CHAR(24) NOT NULL,
        validator_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_selector (selector),
        KEY k_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip VARCHAR(45) NOT NULL,
        username VARCHAR(80) NOT NULL DEFAULT '',
        ok TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY k_ip_time (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS activity (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NULL,
        action VARCHAR(32) NOT NULL,
        target VARCHAR(255) NOT NULL DEFAULT '',
        meta VARCHAR(255) NOT NULL DEFAULT '',
        ip VARCHAR(45) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY k_user_time (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS otp_codes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        phone VARCHAR(16) NOT NULL,
        user_id INT UNSIGNED NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        code_hash CHAR(64) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY k_phone_time (phone, created_at),
        KEY k_ip_time (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // حساب مدیر اولیه
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $st = $pdo->prepare('INSERT INTO users (name, username, password_hash, role, is_active, use_default_bot, created_at) VALUES (?,?,?,?,?,1,?)');
        $st->execute(['مدیر', 'admin', password_hash(ADMIN_PASSWORD_SEED, PASSWORD_DEFAULT), 'admin', 1, date('Y-m-d H:i:s')]);
    }

    // تنظیمات پیش‌فرض
    db_set_default('bot_token', BOT_TOKEN_DEFAULT);
    db_set_default('chat_id', CHAT_ID_DEFAULT);
    db_set_default('send_chat_id', SEND_TO_CHAT_DEFAULT);
    db_set_default('site_name', APP_TITLE);
    db_set_default('allow_signup', '1');
    db_set_default('default_quota', '0');
    db_set_default('legacy_migrated', '0');
    db_set_default('otp_login', '0');
    db_set_default('safir_key', '');
    db_set_default('safir_bot_id', '');
    db_set_default('otp_ttl', '300');

    if (db_get('legacy_migrated') !== '1') db_migrate_legacy();

    db_set('schema_version', APP_VERSION);
}

/* ---------- تنظیمات ---------- */
function db_get(string $k, $default = null) {
    try {
        $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return ($v === false) ? $default : $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function db_set(string $k, string $v): void {
    $st = db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $st->execute([$k, $v]);
}

function db_set_default(string $k, string $v): void {
    $st = db()->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)');
    $st->execute([$k, $v]);
}

/* ---------- مهاجرت data.php به دیتابیس ---------- */
function db_migrate_legacy(): void {
    $pdo = db();
    $adminId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();

    $data = null;
    if (is_file(DATA_FILE)) {
        $raw = (string)@file_get_contents(DATA_FILE);
        $pos = strpos($raw, '?>');
        if ($pos !== false) $raw = substr($raw, $pos + 2);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $data = $decoded;
    }

    if ($data && $adminId) {
        $pdo->beginTransaction();
        try {
            $folderSt = $pdo->prepare('INSERT IGNORE INTO folders (user_id, name, created_at) VALUES (?,?,?)');
            foreach (($data['folders'] ?? []) as $f) {
                if (!is_string($f) || trim($f) === '') continue;
                $folderSt->execute([$adminId, $f, date('Y-m-d H:i:s')]);
            }

            $fileSt = $pdo->prepare('INSERT IGNORE INTO files (id, user_id, folder, name, size, file_id, message_id, parts, share, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach (($data['files'] ?? []) as $x) {
                if (empty($x['id']) || empty($x['file_id'])) continue;
                $share = (string)($x['share'] ?? '');
                if ($share === '') $share = bin2hex(random_bytes(12));
                $fileSt->execute([
                    substr((string)$x['id'], 0, 12),
                    $adminId,
                    (string)($x['folder'] ?? ''),
                    (string)($x['name'] ?? 'file'),
                    (int)($x['size'] ?? 0),
                    (string)$x['file_id'],
                    isset($x['message_id']) ? (int)$x['message_id'] : null,
                    !empty($x['parts']) ? json_encode($x['parts'], JSON_UNESCAPED_UNICODE) : null,
                    $share,
                    date('Y-m-d H:i:s', (int)($x['date'] ?? time())),
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    db_set('legacy_migrated', '1');
}
