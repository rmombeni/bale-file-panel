<?php
/**
 * دسترسی به داده‌ها: زمینهٔ ذخیره‌سازی بله و عملیات فایل/پوشه روی دیتابیس.
 * همهٔ عملیات فایل به کاربر جاری محدود است.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/* ---------- زمینهٔ ذخیره‌سازی ---------- */
function storage_ctx(): array {
    static $ctx = null;
    if ($ctx !== null) return $ctx;

    $u = auth_user();
    // استفاده از ربات پیش‌فرض سامانه فقط با اجازهٔ مدیر (use_default_bot) ممکن است
    $own = $u !== null && (int)($u['use_default_bot'] ?? 0) === 1;
    $token = trim((string)($u['bot_token'] ?? ''));
    $chat  = trim((string)($u['chat_id'] ?? ''));
    $send  = trim((string)($u['send_chat_id'] ?? ''));
    if ($send === '' && $own)  $send = trim((string)db_get('send_chat_id', SEND_TO_CHAT_DEFAULT));
    if ($token === '' && $own) $token = trim((string)db_get('bot_token', BOT_TOKEN_DEFAULT));
    if ($chat === '' && $own)  $chat = trim((string)db_get('chat_id', CHAT_ID_DEFAULT));

    return $ctx = ['token' => $token, 'chat' => $chat, 'send' => $send];
}
function api_base(): string  { return 'https://tapi.bale.ai/bot' . storage_ctx()['token'] . '/'; }
function file_base(): string { return 'https://tapi.bale.ai/file/bot' . storage_ctx()['token'] . '/'; }
function storage_ready(): bool { $c = storage_ctx(); return $c['token'] !== '' && $c['chat'] !== ''; }
function storage_chat(): string { return storage_ctx()['chat']; }

/** زمینهٔ ذخیره‌سازی یک کاربر مشخص (برای لینک‌های عمومی که کاربری وارد نشده). */
function storage_for_user(int $uid): array {
    $token = '';
    $chat  = '';
    $own = false;
    if ($uid > 0) {
        $st = db()->prepare('SELECT bot_token, chat_id, use_default_bot FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $r = $st->fetch();
        if ($r) {
            $token = trim((string)$r['bot_token']);
            $chat  = trim((string)$r['chat_id']);
            $own   = (int)($r['use_default_bot'] ?? 0) === 1;
        }
    }
    if ($token === '' && $own) $token = trim((string)db_get('bot_token', BOT_TOKEN_DEFAULT));
    if ($chat === '' && $own)  $chat = trim((string)db_get('chat_id', CHAT_ID_DEFAULT));
    return ['token' => $token, 'chat' => $chat];
}

/* ---------- کاربر جاری ---------- */
function current_user_id(): int {
    $u = auth_user();
    return $u ? (int)$u['id'] : 0;
}

function user_usage(int $uid): int {
    $st = db()->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE user_id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

function user_quota(int $uid): int {
    $st = db()->prepare('SELECT quota_bytes FROM users WHERE id = ?');
    $st->execute([$uid]);
    $q = $st->fetchColumn();
    if ($q === null || $q === false || (int)$q === 0) return (int)db_get('default_quota', 0);
    return (int)$q;
}

/* ---------- تبدیل ردیف دیتابیس به شکل قدیمی ---------- */
function row_to_file(array $r): array {
    return [
        'id'         => (string)$r['id'],
        'user_id'    => (int)($r['user_id'] ?? 0),
        'share'      => (string)$r['share'],
        'name'       => (string)$r['name'],
        'folder'     => (string)$r['folder'],
        'size'       => (int)$r['size'],
        'file_id'    => (string)$r['file_id'],
        'message_id' => $r['message_id'] !== null ? (int)$r['message_id'] : null,
        'parts'      => !empty($r['parts']) ? (json_decode((string)$r['parts'], true) ?: null) : null,
        'date'       => (int)strtotime((string)$r['created_at']),
    ];
}

function load_data(): array {
    $out = ['folders' => [], 'files' => []];
    $uid = current_user_id();
    if (!$uid) return $out;

    $st = db()->prepare('SELECT name FROM folders WHERE user_id = ? ORDER BY name');
    $st->execute([$uid]);
    $out['folders'] = $st->fetchAll(PDO::FETCH_COLUMN);

    $st = db()->prepare('SELECT * FROM files WHERE user_id = ? ORDER BY created_at DESC');
    $st->execute([$uid]);
    foreach ($st->fetchAll() as $r) $out['files'][] = row_to_file($r);

    return $out;
}

/** برای id فقط فایل‌های کاربر جاری؛ برای share جست‌وجوی عمومی (لینک اشتراک). */
function find_by(string $key, $val) {    if ($val === '' || $val === null) return null;
    if ($key === 'share') {
        $st = db()->prepare('SELECT * FROM files WHERE share = ? LIMIT 1');
        $st->execute([(string)$val]);
    } else {
        $uid = current_user_id();
        if (!$uid) return null;
        $st = db()->prepare('SELECT * FROM files WHERE id = ? AND user_id = ? LIMIT 1');
        $st->execute([(string)$val, $uid]);
    }
    $r = $st->fetch();
    return $r ? row_to_file($r) : null;
}

/** فقط مدیر: یافتن یک فایل بدون محدودیت مالک (برای مدیریت/دانلود از پنل مدیریت). */
function find_file_any(string $id) {
    if ($id === '' || $id === null) return null;
    $st = db()->prepare('SELECT * FROM files WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? row_to_file($r) : null;
}

/* ---------- نوشتن ---------- */
function db_add_file(array $rec): bool {
    $uid = current_user_id();
    if (!$uid) return false;
    $st = db()->prepare('INSERT INTO files (id, user_id, folder, name, size, file_id, message_id, parts, share, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $st->execute([
        (string)$rec['id'], $uid, (string)($rec['folder'] ?? ''), (string)$rec['name'],
        (int)($rec['size'] ?? 0), (string)$rec['file_id'],
        isset($rec['message_id']) ? (int)$rec['message_id'] : null,
        !empty($rec['parts']) ? json_encode($rec['parts'], JSON_UNESCAPED_UNICODE) : null,
        (string)$rec['share'], date('Y-m-d H:i:s', (int)($rec['date'] ?? time())),
    ]);
    return true;
}

function db_delete_file(string $id): ?array {
    $file = find_by('id', $id);
    if (!$file) return null;
    db()->prepare('DELETE FROM files WHERE id = ? AND user_id = ?')->execute([$id, current_user_id()]);
    return $file;
}

function db_rename_file(string $id, string $name): void {
    db()->prepare('UPDATE files SET name = ? WHERE id = ? AND user_id = ?')->execute([$name, $id, current_user_id()]);
}

function db_move_file(string $id, string $folder): void {
    db()->prepare('UPDATE files SET folder = ? WHERE id = ? AND user_id = ?')->execute([$folder, $id, current_user_id()]);
}

function db_new_share(string $id): void {
    db()->prepare('UPDATE files SET share = ? WHERE id = ? AND user_id = ?')
        ->execute([bin2hex(random_bytes(12)), $id, current_user_id()]);
}

/* ---------- پوشه ---------- */
function db_add_folder(string $name): bool {
    $uid = current_user_id();
    if (!$uid) return false;
    $st = db()->prepare('INSERT IGNORE INTO folders (user_id, name, created_at) VALUES (?,?,?)');
    $st->execute([$uid, $name, date('Y-m-d H:i:s')]);
    return $st->rowCount() > 0;
}

function db_delete_folder(string $name): bool {
    $uid = current_user_id();
    $st = db()->prepare('SELECT COUNT(*) FROM files WHERE user_id = ? AND folder = ?');
    $st->execute([$uid, $name]);
    if ((int)$st->fetchColumn() > 0) return false;
    db()->prepare('DELETE FROM folders WHERE user_id = ? AND name = ?')->execute([$uid, $name]);
    return true;
}

/** ok | exists | missing */
function db_rename_folder(string $old, string $new): string {
    $uid = current_user_id();
    $st = db()->prepare('SELECT id FROM folders WHERE user_id = ? AND name = ?');
    $st->execute([$uid, $old]);
    $fid = $st->fetchColumn();
    if ($fid === false) return 'missing';

    $st = db()->prepare('SELECT 1 FROM folders WHERE user_id = ? AND name = ?');
    $st->execute([$uid, $new]);
    if ($st->fetchColumn()) return 'exists';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE folders SET name = ? WHERE id = ?')->execute([$new, $fid]);
        $pdo->prepare('UPDATE files SET folder = ? WHERE user_id = ? AND folder = ?')->execute([$new, $uid, $old]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return 'ok';
}

function db_folder_exists(string $name): bool {
    $st = db()->prepare('SELECT 1 FROM folders WHERE user_id = ? AND name = ?');
    $st->execute([current_user_id(), $name]);
    return (bool)$st->fetchColumn();
}
