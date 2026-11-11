<?php
/**
 * پنل فایل — ذخیره‌سازی روی سرورهای بله (Bale Bot API)
 * خود فایل‌ها روی بله هستند؛ فقط لیست فایل‌ها (file_id) در data.php به صورت JSON ذخیره می‌شود.
 *
 * فایل‌های بزرگ به قطعات ۱۵MB تقسیم می‌شوند؛ هر قطعه جداگانه در بله ذخیره و هنگام دانلود یکپارچه می‌شود.
 * سقف دریافت API برای هر قطعه ۲۰MB در نظر گرفته شده است؛ فایل منطقی در این نسخه تا ۵GB پشتیبانی می‌شود.
 */

/* ============ راه‌اندازی ============ */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/fonts.php';
require_once __DIR__ . '/lib/safir.php';
require_once __DIR__ . '/lib/ui.php';

define('APP_RUNNING', true);
db_boot();
/* ================================= */

const MIME = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'avif' => 'image/avif', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'ogv' => 'video/ogg',
    'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo',
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
    'opus' => 'audio/ogg', 'wav' => 'audio/wav', 'flac' => 'audio/flac',
    'pdf' => 'application/pdf',
    'txt' => 'text/plain', 'md' => 'text/plain', 'log' => 'text/plain', 'csv' => 'text/plain', 'json' => 'text/plain',
    'xml' => 'text/plain', 'ini' => 'text/plain', 'yml' => 'text/plain', 'yaml' => 'text/plain',
    'zip' => 'application/zip', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
];

/* ---------- ابزارها ---------- */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- اوتار (تحویل فقط برای خود کاربر از مسیر امن) ---------- */
function av_dir(): string {
    $dir = STORAGE_DIR . '/avatars';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    return $dir;
}
function av_url(int $uid, string $file = ''): string {
    $v = ($file !== '' && is_file(av_dir() . '/' . $file)) ? (int)filemtime(av_dir() . '/' . $file) : time();
    return './?av=1&v=' . $v;
}

/* داده‌ها از lib/store.php روی دیتابیس خوانده و نوشته می‌شوند. */
function api($method, $params = [], ?array $ctx = null) {
    $token = $ctx !== null ? (string)$ctx['token'] : storage_ctx()['token'];
    $ch = curl_init('https://tapi.bale.ai/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 900,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return ['ok' => false, 'description' => $err];
    return json_decode($res, true) ?: ['ok' => false, 'description' => 'پاسخ نامعتبر از بله'];
}
function fmt_size($b) {
    foreach (['B', 'KB', 'MB', 'GB'] as $u) { if ($b < 1024) return round($b, 1) . " $u"; $b /= 1024; }
    return round($b, 1) . ' TB';
}

/* ---------- پیام لینک فایل در چت بله (بعد از هر آپلود موفق ارسال می‌شود) ---------- */
function bale_notify_upload(array $rec): void {
    try {
        $ctx = storage_ctx();
        if ($ctx['token'] === '' || $ctx['chat'] === '' || empty($rec['share'])) return;
        $view = base_url() . '?s=' . urlencode((string)$rec['share']);
        $dl   = $view . '&dl=1';
        $txt  = '✅ <b>فایل جدید در پنل ذخیره شد</b>' . "\n\n"
              . '📄 نام: <b>' . h((string)$rec['name']) . '</b>' . "\n"
              . '📦 حجم: ' . fmt_size((int)$rec['size']) . "\n";
        if (!empty($rec['parts']) && is_array($rec['parts'])) {
            $txt .= '🧩 فایل بزرگ: ' . fa_num(count($rec['parts'])) . " قطعه در بله — هنگام دانلود یکپارچه می‌شود\n";
        }
        if ((string)($rec['folder'] ?? '') !== '') $txt .= '📁 پوشه: ' . h((string)$rec['folder']) . "\n";
        $txt .= '🗓 تاریخ: ' . fa_date((int)$rec['date']) . "\n\n"
              . '🔗 لینک اشتراک‌گذاری:' . "\n" . $view . "\n\n"
              . '⬇️ دانلود مستقیم:' . "\n" . $dl;
        $kb = json_encode(['inline_keyboard' => [[
            ['text' => '⬇️ دانلود مستقیم', 'url' => $dl, 'style' => 'primary'],
            ['text' => '👁 مشاهده در پنل', 'url' => $view],
        ]]], JSON_UNESCAPED_UNICODE);
        $targets = [$ctx['chat']];
        if (!empty($ctx['send']) && $ctx['send'] !== $ctx['chat']) $targets[] = $ctx['send'];
        foreach ($targets as $t) {
            $r = api('sendMessage', [
                'chat_id' => $t, 'text' => $txt, 'parse_mode' => 'HTML',
                'disable_web_page_preview' => true, 'reply_markup' => $kb,
            ]);
            if (empty($r['ok'])) {   // اگر بله دکمه‌های inline را نپذیرفت، پیام ساده بفرست
                api('sendMessage', ['chat_id' => $t, 'text' => $txt, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
            }
        }
    } catch (Throwable $e) { /* ارسال پیام هرگز نباید آپلود را خطا دهد */ }
}
function ini_bytes($v) {
    $v = trim((string)$v); $n = (float)$v;
    switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return (int)$n;
}
function gregorian_to_jalali($gy, $gm, $gd) {
    $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + ($days % 31); }
    else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + (($days - 186) % 30); }
    return [$jy, $jm, $jd];
}
function fa_num($n) { return strtr((string)$n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }
function fa_date($ts, $mode = 'datetime') {
    $pattern = ($mode === 'date') ? 'yyyy/MM/dd' : 'yyyy/MM/dd  HH:mm';
    try {
        if (class_exists('IntlDateFormatter')) {
            $f = new IntlDateFormatter('fa_IR@calendar=persian', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Asia/Tehran', IntlDateFormatter::TRADITIONAL, $pattern);
            $s = $f->format($ts);
            if ($s) return $s;
        }
    } catch (Throwable $e) {}
    try {                                   // بدون intl: تبدیل دستی میلادی ← جلالی
        $dt = new DateTime('@' . (int)$ts);
        $dt->setTimezone(new DateTimeZone('Asia/Tehran'));
        list($jy, $jm, $jd) = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('n'), (int)$dt->format('j'));
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd) . ($mode === 'date' ? '' : '  ' . $dt->format('H:i'));
    } catch (Throwable $e) {}
    return ($mode === 'date') ? date('Y-m-d', $ts) : date('Y-m-d H:i', $ts);
}
function ext_of($name) { return strtolower(pathinfo((string)$name, PATHINFO_EXTENSION)); }
function kind_of($e) {
    if (in_array($e, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg', 'ico'], true)) return 'image';
    if (in_array($e, ['mp4', 'm4v', 'webm', 'mov', 'ogv', 'mkv', 'avi', 'wmv', 'flv', '3gp'], true)) return 'video';
    if (in_array($e, ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac', 'wma'], true)) return 'audio';
    if ($e === 'pdf') return 'pdf';
    if (in_array($e, ['txt', 'md', 'log', 'csv', 'json', 'xml', 'ini', 'yml', 'yaml'], true)) return 'text';
    if (in_array($e, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'], true)) return 'doc';
    if (in_array($e, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'], true)) return 'archive';
    return 'other';
}
function cat_of($k) {
    if (in_array($k, ['pdf', 'text', 'doc'], true)) return 'doc';
    if ($k === 'archive') return 'other';
    return $k;
}
function can_preview($k, $e) {
    if ($k === 'video') return in_array($e, ['mp4', 'm4v', 'webm', 'mov', 'ogv'], true);
    return in_array($k, ['image', 'audio', 'pdf', 'text'], true);
}
function base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $path;
}
function new_token() { return bin2hex(random_bytes(12)); }
function json_out($arr, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function text_exit($code, $msg) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
}
function extract_file_id($msg) {
    foreach (['document', 'video', 'audio', 'animation', 'voice'] as $k)
        if (!empty($msg[$k]['file_id'])) return $msg[$k]['file_id'];
    if (!empty($msg['photo']) && is_array($msg['photo'])) { $p = end($msg['photo']); return $p['file_id'] ?? null; }
    return null;
}
/* find_by در lib/store.php تعریف شده است. */

/* ---------- استریم فایل از بله؛ فایل‌های چندبخشی به صورت یک فایل واحد ---------- */
function stream_file(array $file, $inline, $public = false) {
    $ctx  = storage_for_user((int)($file['user_id'] ?? 0));
    $ext  = ext_of($file['name']);
    $kind = kind_of($ext);
    $mime = MIME[$ext] ?? 'application/octet-stream';
    $inline = $inline && in_array($kind, ['image', 'video', 'audio', 'pdf', 'text'], true);

    // فایل‌های قدیمی همچنان با همان file_id دریافت می‌شوند؛ فایل‌های جدید parts دارند.
    $parts = !empty($file['parts']) && is_array($file['parts']) ? array_values($file['parts']) : [[
        'file_id' => $file['file_id'] ?? '', 'size' => (int)($file['size'] ?? 0)
    ]];
    if (!$parts || empty($parts[0]['file_id'])) text_exit(404, 'اطلاعات قطعات فایل پیدا نشد');

    // ابتدا مسیر تمام قطعات را می‌گیریم تا قبل از شروع پاسخ، خطاهای API مشخص شوند.
    // اندازه‌ها از سابقه خودمان خوانده می‌شود: file_size بله برای این فایل‌ها مقدار نادرست (۸۵) برمی‌گرداند
    // و اگر مبنای Content-Length شود، دانلود ناقص می‌ماند.
    $paths = []; $total = 0; $sizesKnown = true;
    foreach ($parts as $part) {
        if (empty($part['file_id'])) text_exit(502, 'شناسه یکی از قطعات فایل موجود نیست');
        $r = api('getFile', ['file_id' => $part['file_id']], $ctx);
        if (empty($r['ok']) || empty($r['result']['file_path']))
            text_exit(502, 'خطا در دریافت قطعه از بله: ' . ($r['description'] ?? 'نامشخص'));
        $partSize = (int)($part['size'] ?? 0);
        if ($partSize <= 0) { $partSize = 0; $sizesKnown = false; }
        if ($partSize > MAX_DL) text_exit(413, 'یکی از قطعات از سقف دریافت API بله بزرگ‌تر است');
        $paths[] = $r['result']['file_path'];
        $total += $partSize;
    }

    set_time_limit(0);
    while (ob_get_level()) ob_end_clean();
    http_response_code(200);
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Content-Type: ' . (($inline && $kind === 'text') ? 'text/plain; charset=utf-8' : $mime));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($file['name']));
    if ($inline && $ext === 'svg') header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
    if ($inline) header('Cache-Control: ' . ($public ? 'public' : 'private') . ', max-age=3600');
    if ($sizesKnown && $total > 0) header('Content-Length: ' . $total);
    // برای فایل چندبخشی Range اعلام نمی‌کنیم؛ دانلود کامل و پیوسته انجام می‌شود.

    $sentBytes = 0;
    foreach ($paths as $path) {
        $ch = curl_init('https://tapi.bale.ai/file/bot' . $ctx['token'] . '/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FAILONERROR => true,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$sentBytes) {
                echo $chunk; $sentBytes += strlen($chunk); flush();
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($ok === false) {
            if ($sentBytes === 0) text_exit(502, 'دریافت قطعه از بله ناموفق بود: ' . $err);
            error_log('Bale chunk stream failed after ' . $sentBytes . ' bytes: ' . $err);
            exit;
        }
    }
    exit;
}

/* ---------- فضای موقت وضعیت آپلود چندبخشی ---------- */
function upload_state_dir() {
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bale-file-manager-' . substr(hash('sha256', storage_ctx()['token']), 0, 12);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) json_out(['ok' => false, 'msg' => 'ساخت فضای موقت آپلود ممکن نشد'], 500);
    @chmod($dir, 0700);
    return $dir;
}
function clean_upload_states($dir) {
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path)
        if (@filemtime($path) && filemtime($path) < time() - 86400) @unlink($path);
}

/* ---------- ظاهر مشترک ---------- */
function sprite() {
    $i = [
        'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'link'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'trash'    => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
        'folder'   => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'grid'     => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'list'     => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'x'        => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'file'     => '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/>',
        'doc'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'play'     => '<polygon points="6 3 20 12 6 21 6 3"/>',
        'music'    => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'image'    => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'archive'  => '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
        'eye'      => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'more'     => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'plus'     => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'send'     => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'left'     => '<polyline points="15 18 9 12 15 6"/>',
        'right'    => '<polyline points="9 18 15 12 9 6"/>',
        'refresh'  => '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
        'copy'     => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'cloud'    => '<path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>',
        'check'    => '<polyline points="20 6 9 17 4 12"/>',
        'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'key'      => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
        'eye-off'  => '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
        'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    ];
    echo '<svg width="0" height="0" style="position:absolute" aria-hidden="true">';
    foreach ($i as $n => $p)
        echo '<symbol id="i-' . $n . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</symbol>';
    echo '</svg>';
}
function ic($n, $c = '') { return '<svg class="ic ' . $c . '"><use href="#i-' . $n . '"/></svg>'; }
function icon_for($k) {
    return ['image' => 'image', 'video' => 'play', 'audio' => 'music', 'pdf' => 'doc', 'text' => 'doc', 'doc' => 'doc', 'archive' => 'archive'][$k] ?? 'file';
}

function css() { return vazir_font_css('fonts/')."\n" . <<<'CSS'
:root{--bg:#f3f5fb;--surface:#ffffff;--surface2:#f1f3fa;--surface3:#e9edf7;--text:#0b1526;--muted:#64748b;--border:#e6e9f2;--primary:#4f46e5;--primary2:#7c6cf6;--weak:#eef0ff;--danger:#e11d48;--ok:#0a9d6c;--shadow-sm:0 1px 2px #0f172a0d;--shadow:0 1px 2px #0f172a08,0 8px 28px #0f172a12;--shadow-lg:0 24px 70px #0f172a24;--r:16px;--r-lg:20px;--top-h:64px;--grad:linear-gradient(135deg,#4f46e5,#7c6cf6)}
@media(prefers-color-scheme:dark){:root{--bg:#070b17;--surface:#0e1526;--surface2:#16203a;--surface3:#1d2a4a;--text:#e9edf8;--muted:#8b97b8;--border:#1e2a4c;--primary:#8b93ff;--primary2:#a5b0ff;--weak:#1a2148;--danger:#fb7185;--ok:#34d399;--shadow-sm:0 1px 2px #00000059;--shadow:0 1px 2px #00000042,0 10px 34px #00000059;--shadow-lg:0 24px 70px #000000a6;--grad:linear-gradient(135deg,#5b54e8,#8474ff)}}
*{box-sizing:border-box}
html,body{margin:0}
body{font-family:'Vazirmatn FD',Vazirmatn,"IRANSans",Tahoma,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--text);font-size:14px;line-height:1.65;-webkit-font-smoothing:antialiased}
::selection{background:#4f46e533}
a{color:inherit;text-decoration:none}
button,input,select{font:inherit;color:inherit}
:focus-visible{outline:2px solid var(--primary);outline-offset:2px;border-radius:6px}
.ic{width:18px;height:18px;flex:none}
h1,h2,h3,h4{letter-spacing:-.2px}
/* ---------- دکمه‌ها ---------- */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 15px;border-radius:12px;border:1px solid var(--border);background:var(--surface);cursor:pointer;font-weight:650;font-size:13px;transition:transform .15s,box-shadow .15s,background .15s,border-color .15s;box-shadow:var(--shadow-sm)}
.btn:hover{background:var(--surface2);border-color:var(--border);transform:translateY(-1px)}
.btn:active{transform:translateY(0)}
.btn.p{background:var(--grad);color:#fff;border-color:transparent;box-shadow:0 6px 18px #4f46e547}
.btn.p:hover{filter:brightness(1.07)}
.btn.off{opacity:.45;pointer-events:none}
.btn.sm{padding:7px 12px;font-size:12px;border-radius:10px}
.ib{display:inline-grid;place-items:center;width:34px;height:34px;border-radius:10px;border:0;background:transparent;color:var(--muted);cursor:pointer;transition:.15s}
.ib:hover{background:var(--surface3);color:var(--text)}
.ib.off{opacity:.35;pointer-events:none}
/* ---------- نوار بالا شیشه‌ای ---------- */
.top{position:sticky;top:0;z-index:40;background:color-mix(in srgb,var(--bg) 72%,transparent);backdrop-filter:blur(14px) saturate(1.4);-webkit-backdrop-filter:blur(14px) saturate(1.4);border-bottom:1px solid var(--border)}
.top-in{max-width:1360px;margin:0 auto;padding:11px 22px;display:flex;align-items:center;gap:12px;min-height:var(--top-h)}
.logo{display:flex;align-items:center;gap:10px;font-weight:800;font-size:16px;white-space:nowrap}
.logo i{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:var(--grad);color:#fff;font-style:normal;box-shadow:0 8px 18px #4f46e545}
.logo b{background:linear-gradient(90deg,var(--text),var(--primary));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.search{flex:1;position:relative;max-width:560px;margin-inline:auto}
.search .ic{position:absolute;top:50%;inset-inline-start:13px;transform:translateY(-50%);color:var(--muted)}
.search input{width:100%;padding:10px 42px;border-radius:13px;border:1px solid transparent;background:var(--surface2);outline:none;transition:.15s}
.search input:focus{background:var(--surface);border-color:var(--primary);box-shadow:0 0 0 4px var(--weak)}
/* ---------- چهرهٔ کاربر + منو ---------- */
.uchip{position:relative}
.uchip>button{display:flex;align-items:center;gap:9px;padding:5px 9px 5px 5px;border-radius:999px;border:1px solid var(--border);background:var(--surface);cursor:pointer;transition:.15s}
.uchip>button:hover{background:var(--surface2)}
.uchip .av{width:32px;height:32px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-weight:800;font-size:13px;flex:none;overflow:hidden}
.uchip .av img{width:100%;height:100%;object-fit:cover;display:block}
.uchip .un{display:block;font-size:12.5px;font-weight:750;line-height:1.25;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.uchip .uro{display:block;font-size:10px;color:var(--muted);line-height:1.2}
.umenu{position:absolute;top:calc(100% + 8px);inset-inline-end:0;min-width:190px;background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-lg);padding:6px;display:none;z-index:60}
.umenu.on{display:block;animation:pop .14s ease}
@keyframes pop{from{opacity:0;transform:translateY(-5px) scale(.98)}to{opacity:1;transform:none}}
.umenu a,.umenu button{display:flex;align-items:center;gap:10px;width:100%;padding:9px 11px;border:0;background:transparent;border-radius:9px;cursor:pointer;text-align:start;font-size:13px;font-weight:600;color:var(--text)}
.umenu a:hover,.umenu button:hover{background:var(--surface2)}
.umenu hr{border:0;border-top:1px solid var(--border);margin:5px 4px}
.umenu .dg{color:var(--danger)}
/* ---------- چیدمان ---------- */
.app{max-width:1360px;margin:0 auto;padding:22px;display:grid;grid-template-columns:262px 1fr;gap:22px;align-items:start}
.side{position:sticky;top:calc(var(--top-h) + 18px);display:flex;flex-direction:column;gap:14px}
.box{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--shadow);padding:14px}
.box h4{margin:0 0 10px;font-size:11px;color:var(--muted);font-weight:800;display:flex;justify-content:space-between;align-items:center;text-transform:uppercase;letter-spacing:.8px}
.fl a{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:11px;color:var(--text);font-weight:550;transition:.14s;border:1px solid transparent}
.fl a .ic{color:var(--muted);transition:.14s}
.fl a:hover{background:var(--surface2)}
.fl a.on{background:var(--weak);color:var(--primary);font-weight:750;border-color:color-mix(in srgb,var(--primary) 22%,transparent)}
.fl a.on .ic{color:var(--primary)}
.fl a span{margin-inline-start:auto;font-size:11px;color:var(--muted);background:var(--surface2);padding:1px 8px;border-radius:999px;min-width:24px;text-align:center}
.fl a.on span{background:color-mix(in srgb,var(--primary) 15%,transparent);color:var(--primary)}
/* ---------- متر فضای ذخیره‌سازی ---------- */
.meter{height:9px;border-radius:99px;background:var(--surface3);overflow:hidden;margin:10px 0 6px}
.meter i{display:block;height:100%;width:0;background:var(--grad);border-radius:99px;transition:width .4s}
.meter i.full{background:linear-gradient(90deg,#f59e0b,#e11d48)}
.stat{display:flex;justify-content:space-between;align-items:center;color:var(--muted);font-size:12.5px;padding:5px 0}
.stat b{color:var(--text);font-weight:700}
/* ---------- سربرگ محتوا ---------- */
.head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.head h2{margin:0;font-size:21px;display:flex;align-items:center;gap:10px;font-weight:800}
.head h2 .ic{color:var(--primary);background:var(--weak);padding:7px;border-radius:11px;width:37px;height:37px}
.head .sp{flex:1}
.chips{display:flex;gap:6px;flex-wrap:wrap}
.chip{padding:6px 14px;border-radius:999px;border:1px solid var(--border);background:var(--surface);cursor:pointer;font-size:12.5px;font-weight:650;color:var(--muted);transition:.14s}
.chip:hover{color:var(--text);border-color:var(--muted)}
.chip.on{background:var(--grad);border-color:transparent;color:#fff;box-shadow:0 4px 12px #4f46e53d}
select.sel{padding:8px 12px;border-radius:11px;border:1px solid var(--border);background:var(--surface);outline:none;cursor:pointer;font-size:12.5px}
.seg{display:inline-flex;border:1px solid var(--border);border-radius:11px;overflow:hidden;background:var(--surface)}
.seg button{border:0;background:transparent;padding:8px 11px;cursor:pointer;color:var(--muted);display:grid;place-items:center;transition:.14s}
.seg button:hover{background:var(--surface2);color:var(--text)}
.seg button.on{background:var(--weak);color:var(--primary)}
/* ---------- کارت فایل‌ها ---------- */
.files{display:grid;grid-template-columns:repeat(auto-fill,minmax(196px,1fr));gap:14px}
.cf{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--shadow-sm);transition:transform .16s,box-shadow .16s,border-color .16s;display:flex;flex-direction:column;animation:fadeUp .25s ease both}
.cf:hover{transform:translateY(-3px);border-color:color-mix(in srgb,var(--primary) 35%,var(--border));box-shadow:var(--shadow)}
@keyframes fadeUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.thumb{position:relative;aspect-ratio:4/3;background:var(--surface2);display:grid;place-items:center;cursor:pointer;overflow:hidden}
.thumb img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .3s}
.cf:hover .thumb img{transform:scale(1.045)}
.fi{display:grid;place-items:center;gap:7px;color:var(--k,#64748b)}
.fi .bx{width:60px;height:60px;border-radius:19px;display:grid;place-items:center;background:color-mix(in srgb,var(--k,#64748b) 13%,var(--surface));box-shadow:var(--shadow-sm);transition:.2s}
.cf:hover .fi .bx{transform:scale(1.07)}
.fi .bx .ic{width:27px;height:27px}
.fi em{font-style:normal;font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
.k-image{--k:#8b5cf6}.k-video{--k:#ec4899}.k-audio{--k:#f59e0b}.k-pdf{--k:#ef4444}.k-text{--k:#0ea5e9}.k-doc{--k:#2563eb}.k-archive{--k:#14b8a6}.k-other{--k:#64748b}
.badge-play{position:absolute;inset-inline-end:10px;bottom:10px;width:32px;height:32px;border-radius:50%;background:#000b;color:#fff;display:grid;place-items:center;backdrop-filter:blur(4px)}
.badge-play .ic{width:14px;height:14px}
.meta{padding:10px 12px 4px;min-width:0}
.nm{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;direction:auto}
.sub{color:var(--muted);font-size:11.5px}
.acts{display:flex;gap:2px;padding:4px 8px 8px;margin-top:auto}
.acts .sp{flex:1}
.files.list{grid-template-columns:1fr;gap:8px}
.files.list .cf{flex-direction:row;align-items:center;padding:8px 12px;gap:12px;box-shadow:none}
.files.list .cf:hover{transform:none}
.files.list .thumb{width:52px;height:52px;aspect-ratio:auto;border-radius:13px;flex:none}
.files.list .fi .bx{width:38px;height:38px;border-radius:12px;box-shadow:none}
.files.list .fi .bx .ic{width:20px;height:20px}
.files.list .fi em,.files.list .badge-play{display:none}
.files.list .meta{flex:1;padding:0}
.files.list .acts{padding:0;margin:0}
/* ---------- حالت خالی ---------- */
.empty{text-align:center;padding:58px 20px;color:var(--muted);background:var(--surface);border:1.5px dashed var(--border);border-radius:var(--r-lg)}
.empty .ic{width:48px;height:48px;color:var(--primary);background:var(--weak);padding:11px;border-radius:16px;margin-bottom:12px;box-sizing:content-box}
.empty h3{margin:0 0 4px;color:var(--text);font-size:16px}
/* ---------- پیش‌نمایش و مودال‌ها ---------- */
.ov{position:fixed;inset:0;background:#050a18cf;display:none;z-index:60;padding:16px;backdrop-filter:blur(6px)}
.ov.on{display:flex;animation:pop .15s ease}
.pv{margin:auto;width:min(1100px,100%);max-height:94vh;display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--border);border-radius:22px;overflow:hidden;box-shadow:var(--shadow-lg);position:relative}
.pv-h{display:flex;align-items:center;gap:10px;padding:13px 16px;border-bottom:1px solid var(--border)}
.pv-h .nm{flex:1}
.pv-b{position:relative;flex:1;min-height:240px;display:grid;place-items:center;background:#0b0f1a;overflow:auto}
.pv-b img,.pv-b video{max-width:100%;max-height:72vh;display:block;border-radius:6px}
.pv-b iframe{width:100%;height:72vh;border:0;background:#fff}
.pv-b audio{width:min(520px,90%)}
.pv-b pre{margin:0;padding:18px;width:100%;max-height:72vh;overflow:auto;background:var(--surface);color:var(--text);font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;white-space:pre-wrap;word-break:break-word;direction:auto;text-align:start}
.nopv{color:#cbd5e1;text-align:center;padding:40px;line-height:2}
.nav{position:absolute;top:50%;transform:translateY(-50%);width:44px;height:44px;border-radius:50%;border:0;background:#0009;color:#fff;cursor:pointer;display:grid;place-items:center;z-index:2;backdrop-filter:blur(4px)}
.nav:hover{background:#000d}
.nav.l{left:12px}.nav.r{right:12px}
.pv-f{display:flex;gap:10px;align-items:center;padding:12px 16px;border-top:1px solid var(--border);flex-wrap:wrap}
.pv-f input{flex:1;min-width:180px;padding:9px 12px;border-radius:11px;border:1px solid var(--border);background:var(--surface2);direction:ltr;text-align:left;font-size:12px}
/* ---------- منوی راست‌کلیک ---------- */
.menu{position:absolute;z-index:70;min-width:230px;background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-lg);padding:6px;display:none}
.menu button{display:flex;align-items:center;gap:10px;width:100%;padding:9px 11px;border:0;background:transparent;border-radius:9px;cursor:pointer;text-align:start;font-size:13px}
.menu button:hover{background:var(--surface2)}
.menu button.dg{color:var(--danger)}
.menu hr{border:0;border-top:1px solid var(--border);margin:5px 0}
/* ---------- آپلود ---------- */
.uq{position:fixed;bottom:16px;inset-inline-start:16px;width:335px;max-width:calc(100vw - 32px);max-height:50vh;overflow:auto;background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow-lg);z-index:55;display:none}
.uq.on{display:block;animation:pop .15s ease}
.uq-h{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid var(--border);font-weight:750}
.up{padding:10px 14px;border-bottom:1px solid var(--border);font-size:12px}
.up:last-child{border:0}
.up .t{display:flex;justify-content:space-between;gap:8px}
.up .t b{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;direction:auto}
.up .t span{color:var(--muted);white-space:nowrap}
.bar{height:6px;background:var(--surface3);border-radius:4px;overflow:hidden;margin-top:6px}
.bar i{display:block;height:100%;width:0;background:var(--grad);transition:width .12s}
.up.d .bar i{background:var(--ok)}.up.e .bar i{background:var(--danger)}.up.e .t span{color:var(--danger)}
.drop{position:fixed;inset:0;background:#4f46e5e0;color:#fff;display:none;place-items:center;font-size:24px;font-weight:800;z-index:80;pointer-events:none;backdrop-filter:blur(5px)}
.drop.on{display:grid}
.drop .ic{width:60px;height:60px;display:block;margin:0 auto 12px}
#toast{position:fixed;bottom:22px;left:50%;transform:translate(-50%,30px);background:#0f172a;color:#fff;padding:10px 18px;border-radius:12px;opacity:0;transition:.25s;z-index:90;pointer-events:none;font-weight:650;box-shadow:var(--shadow-lg)}
#toast.on{opacity:1;transform:translate(-50%,0)}
#toast.err{background:#be123c}
.warn{background:color-mix(in srgb,#f59e0b 13%,var(--surface));border:1px solid color-mix(in srgb,#f59e0b 35%,transparent);color:#92400e;padding:11px 15px;border-radius:13px;margin-bottom:14px;font-size:13px;font-weight:600}
@media(prefers-color-scheme:dark){.warn{color:#fcd34d}}
/* ---------- ورود/ثبت‌نام/عمومی ---------- */
.center{min-height:100vh;display:grid;place-items:center;padding:20px}
.cardc{width:100%;max-width:380px;background:var(--surface);border:1px solid var(--border);border-radius:22px;box-shadow:var(--shadow);padding:30px}
.cardc h3{margin:14px 0 4px;font-size:20px}
.cardc p{margin:0 0 18px;color:var(--muted)}
.cardc input{width:100%;padding:12px 14px;border-radius:13px;border:1px solid var(--border);background:var(--surface);outline:none;margin-bottom:12px;transition:.15s}
.cardc input:focus{border-color:var(--primary);box-shadow:0 0 0 4px var(--weak)}
.cardc .btn{width:100%;justify-content:center;padding:12px}
.err-t{color:var(--danger);font-weight:600;margin-bottom:10px}
.pubwrap{max-width:900px;margin:0 auto;padding:24px 16px}
.pubcard{background:var(--surface);border:1px solid var(--border);border-radius:22px;box-shadow:var(--shadow);overflow:hidden}
.pubinfo{padding:18px 20px;display:flex;gap:14px;align-items:center;flex-wrap:wrap}
.pubinfo .nm{font-size:16px;white-space:normal;word-break:break-word}
/* ---------- اسکرول‌بار ---------- */
::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-thumb{background:var(--surface3);border-radius:99px;border:2px solid var(--bg)}
::-webkit-scrollbar-thumb:hover{background:var(--muted)}
@media(max-width:900px){
 .app{grid-template-columns:1fr;padding:14px}
 .side{position:static}
 .fl{display:flex;overflow-x:auto;gap:6px}
 .fl a{white-space:nowrap}
 .fl a span{display:none}
 .logo b{display:none}
 .files{grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}
 .btn .tx{display:none}
 .pv-b iframe,.pv-b img,.pv-b video{max-height:60vh;height:60vh}
 .pv-b img,.pv-b video{height:auto}
 .uchip .un,.uchip .uro{display:none}
}
CSS;
}

function preview_js() { return <<<'JS'
function mountPreview(box, kind, url, size){
  box.innerHTML = '';
  var el;
  if (kind === 'image') { el = new Image(); el.src = url; el.alt = ''; }
  else if (kind === 'video') { el = document.createElement('video'); el.src = url; el.controls = true; el.autoplay = true; el.playsInline = true; }
  else if (kind === 'audio') { el = document.createElement('audio'); el.src = url; el.controls = true; el.autoplay = true; }
  else if (kind === 'pdf') { el = document.createElement('iframe'); el.src = url; }
  else if (kind === 'text') {
    el = document.createElement('pre');
    if (size > 1048576) el.textContent = 'فایل متنی برای پیش‌نمایش بزرگ است؛ آن را دانلود کنید.';
    else { el.textContent = 'در حال بارگذاری…'; fetch(url).then(function(r){return r.text();}).then(function(t){el.textContent = t;}).catch(function(){el.textContent = 'خطا در بارگذاری';}); }
  }
  else { el = document.createElement('div'); el.className = 'nopv'; el.textContent = kind === 'big' ? 'این فایل بیش از ۲۰ مگابایت است و API بله اجازه پیش‌نمایش/دانلود آن را نمی‌دهد.' : 'پیش‌نمایشی برای این نوع فایل وجود ندارد.'; }
  box.appendChild(el);
}
function copyText(t){
  if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(t);
  return new Promise(function(res, rej){
    var a = document.createElement('textarea'); a.value = t; a.style.position = 'fixed'; a.style.opacity = '0';
    document.body.appendChild(a); a.select();
    try { document.execCommand('copy') ? res() : rej(); } catch (e) { rej(e); }
    a.remove();
  });
}
JS;
}

/* ================================================================
 *  مسیر عمومی: لینک اشتراک فایل (بدون نیاز به ورود)
 * ================================================================ */
if (isset($_GET['s'])) {
    $file = (db_get('allow_share', '1') === '1') ? find_by('share', (string)$_GET['s']) : null;
    if (!$file) {
        http_response_code(404);
        ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>لینک نامعتبر</title><style><?= css() ?></style></head><body>
<?php sprite(); ?><div class="center"><div class="cardc" style="text-align:center"><?= ic('link', '') ?><h3>لینک نامعتبر است</h3><p>این لینک وجود ندارد یا ابطال شده است.</p></div></div></body></html><?php
        exit;
    }
    if (isset($_GET['raw'])) stream_file($file, true, true);
    if (isset($_GET['dl']))  stream_file($file, false, true);

    $e = ext_of($file['name']); $k = kind_of($e);
    $big = ($file['size'] > MAX_DL && empty($file['parts']));
    $pk = (!$big && $file['size'] <= MAX_DL && can_preview($k, $e)) ? $k : ($big ? 'big' : 'none');
    $self = base_url() . '?s=' . urlencode($file['share']);
    header('Referrer-Policy: no-referrer');
    ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?= h($file['name']) ?></title><style><?= css() ?></style></head><body>
<?php sprite(); ?>
<div class="pubwrap"><div class="pubcard">
  <div class="pv-b" id="box"></div>
  <div class="pubinfo">
    <div class="fi k-<?= $k ?>"><div class="bx"><?= ic(icon_for($k)) ?></div></div>
    <div style="flex:1;min-width:180px"><div class="nm"><?= h($file['name']) ?></div><div class="sub"><?= fmt_size($file['size']) ?> · <?= fa_date($file['date']) ?></div></div>
    <button class="btn" id="cp"><?= ic('copy') ?><span class="tx">کپی لینک</span></button>
    <a class="btn p <?= $big ? 'off' : '' ?>" href="?s=<?= h(urlencode($file['share'])) ?>&dl=1"><?= ic('download') ?><span class="tx">دانلود</span></a>
  </div>
</div></div>
<div id="toast"></div>
<script>
<?= preview_js() ?>
mountPreview(document.getElementById('box'), <?= json_encode($pk) ?>, '?s=<?= h(urlencode($file['share'])) ?>&raw=1', <?= (int)$file['size'] ?>);
document.getElementById('cp').onclick = function(){
  var t = document.getElementById('toast');
  copyText(<?= json_encode($self) ?>).then(function(){ t.textContent = 'لینک کپی شد'; t.className = 'on'; setTimeout(function(){ t.className = ''; }, 1800); });
};
</script></body></html><?php
    exit;
}

/* ================================================================
 *  از اینجا به بعد: پنل (نیازمند ورود)
 * ================================================================ */
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();
auth_try_remember();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function flash($msg, $type = 'ok') { $_SESSION['flash'] = [$msg, $type]; }
function back($folder = '') { header('Location: ?f=' . urlencode($folder)); exit; }

$page = (string)($_GET['page'] ?? '');

/* ---------- خروج ---------- */
if (isset($_GET['logout'])) { auth_logout(); header('Location: ./'); exit; }

/* ---------- صفحات مهمان: ورود و ثبت‌نام ---------- */
if ($page === 'login' || $page === 'register') {
    require __DIR__ . '/pages/' . $page . '.php';
    exit;
}

/* ---------- نیاز به ورود ---------- */
if (!auth_user()) {
    if (in_array(($_POST['action'] ?? ''), ['upload', 'upload_chunk'], true)) json_out(['ok' => false, 'msg' => 'نشست منقضی شده؛ دوباره وارد شوید'], 401);
    if (isset($_GET['dl']) || isset($_GET['raw'])) text_exit(403, 'ابتدا وارد پنل شوید');
    header('Location: ./?page=login');
    exit;
}

/* ---------- حساب‌های ساخته‌شده با کد بله: تا تکمیل اطلاعات، همه‌جا به صفحهٔ تکمیل هدایت می‌شود ---------- */
$meRow = auth_user();

/* ---------- تصویر پروفایل کاربر فعلی ---------- */
if (isset($_GET['av'])) {
    $avFile = (string)($meRow['avatar'] ?? '');
    $avPath = ($avFile !== '' && is_file(av_dir() . '/' . basename($avFile))) ? av_dir() . '/' . basename($avFile) : '';
    if ($avPath === '') { http_response_code(404); exit('Not found'); }
    $avMimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $avExt = strtolower(pathinfo($avPath, PATHINFO_EXTENSION));
    session_write_close();
    header('Content-Type: ' . ($avMimes[$avExt] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string)filesize($avPath));
    header('Cache-Control: private, max-age=600');
    readfile($avPath);
    exit;
}

if ($meRow !== null && trim((string)$meRow['name']) === '') {
    if ($page !== 'complete') {
        header('Location: ./?page=complete');
        exit;
    }
}

/* ---------- تکمیل اطلاعات حساب ---------- */
if ($page === 'complete') { require __DIR__ . '/pages/complete.php'; exit; }

/* ---------- تنظیمات و مدیریت ---------- */
if ($page === 'settings') { require __DIR__ . '/pages/settings.php'; exit; }
if ($page === 'admin') {
    if (!auth_is_admin()) { header('Location: ./'); exit; }
    require __DIR__ . '/pages/admin.php';
    exit;
}

/* ---------- صفحهٔ پروفایل بله ---------- */
if (isset($_GET['page']) && $_GET['page'] === 'bale') {
    require __DIR__ . '/bale.php';
    exit;
}

/* ---------- دانلود / پیش‌نمایش داخل پنل ---------- */
if (isset($_GET['dl']) || isset($_GET['raw'])) {
    session_write_close();              // قفل نشست باز نماند تا درخواست‌های موازی (تصاویر) بلاک نشوند
    $isRaw = isset($_GET['raw']);
    $id = (string)($isRaw ? $_GET['raw'] : $_GET['dl']);
    // مدیر به فایل‌های همهٔ کاربران دسترسی دارد (پنل مدیریت)
    $file = auth_is_admin() ? find_file_any($id) : find_by('id', $id);
    if (!$file) text_exit(404, 'فایل پیدا نشد');
    stream_file($file, $isRaw, false);
}

/* ---------- آپلود چندبخشی؛ هر قطعه جداگانه در بله ذخیره می‌شود ---------- */
if (($_POST['action'] ?? '') === 'upload_chunk') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) json_out(['ok' => false, 'msg' => 'CSRF'], 403);
    session_write_close();
    set_time_limit(0);

    $fl = $_FILES['chunk'] ?? null;
    if (!$fl) json_out(['ok' => false, 'msg' => 'قطعه دریافت نشد؛ محدودیت post_max_size سرور را بررسی کنید']);
    if ($fl['error'] !== UPLOAD_ERR_OK) json_out(['ok' => false, 'msg' => 'خطای آپلود PHP، کد: ' . $fl['error']]);

    $uploadId = (string)($_POST['upload_id'] ?? '');
    $index = filter_var($_POST['part_index'] ?? null, FILTER_VALIDATE_INT);
    $totalParts = filter_var($_POST['total_parts'] ?? null, FILTER_VALIDATE_INT);
    $chunkSize = filter_var($_POST['chunk_size'] ?? null, FILTER_VALIDATE_INT);
    $originalSize = filter_var($_POST['original_size'] ?? null, FILTER_VALIDATE_INT);
    $name = basename((string)($_POST['original_name'] ?? 'file'));
    $folder = (string)($_POST['folder'] ?? '');

    if (!preg_match('/^[a-f0-9]{32}$/', $uploadId) || $index === false || $totalParts === false || $chunkSize === false || $originalSize === false)
        json_out(['ok' => false, 'msg' => 'مشخصات آپلود معتبر نیست']);
    if ($originalSize < 1 || $originalSize > MAX_LOGICAL_UPLOAD)
        json_out(['ok' => false, 'msg' => 'حداکثر اندازه فایل در این نسخه ۵ گیگابایت است']);
    $quota = user_quota(current_user_id());
    if ($quota > 0 && user_usage(current_user_id()) + $originalSize > $quota)
        json_out(['ok' => false, 'msg' => 'سهمیهٔ فضای شما تکمیل شده است'], 413);
    if ($chunkSize < 256 * 1024 || $chunkSize > MAX_CHUNK)
        json_out(['ok' => false, 'msg' => 'اندازه قطعه معتبر نیست؛ تنظیمات PHP را بررسی کنید']);
    $expectedParts = (int)ceil($originalSize / $chunkSize);
    if ($totalParts !== $expectedParts || $index < 0 || $index >= $totalParts)
        json_out(['ok' => false, 'msg' => 'شماره یا تعداد قطعات با اندازه فایل هم‌خوانی ندارد']);

    $expectedBytes = min($chunkSize, $originalSize - ($index * $chunkSize));
    if ((int)$fl['size'] !== $expectedBytes)
        json_out(['ok' => false, 'msg' => 'حجم قطعه با مقدار مورد انتظار برابر نیست']);

    $dir = upload_state_dir();
    clean_upload_states($dir);
    $statePath = $dir . DIRECTORY_SEPARATOR . $uploadId . '.json';
    $fp = @fopen($statePath, 'c+');
    if (!$fp) json_out(['ok' => false, 'msg' => 'ساخت وضعیت آپلود ممکن نشد'], 500);
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $state = $raw ? json_decode($raw, true) : null;

    if (!is_array($state)) {
        if ($index !== 0) { flock($fp, LOCK_UN); fclose($fp); json_out(['ok' => false, 'msg' => 'وضعیت آپلود پیدا نشد؛ دوباره از ابتدا تلاش کنید']); }
        $state = [
            'upload_id' => $uploadId, 'name' => $name, 'folder' => $folder,
            'original_size' => $originalSize, 'chunk_size' => $chunkSize,
            'total_parts' => $totalParts, 'parts' => [], 'created' => time(), 'completed' => false,
        ];
    }
    if (($state['name'] ?? '') !== $name || (int)($state['original_size'] ?? 0) !== $originalSize ||
        (int)($state['chunk_size'] ?? 0) !== $chunkSize || (int)($state['total_parts'] ?? 0) !== $totalParts) {
        flock($fp, LOCK_UN); fclose($fp); json_out(['ok' => false, 'msg' => 'اطلاعات این آپلود با درخواست قبلی متفاوت است']);
    }

    if (!empty($state['completed'])) {
        $recordId = $state['record_id'] ?? '';
        flock($fp, LOCK_UN); fclose($fp);
        json_out(['ok' => true, 'completed' => true, 'record_id' => $recordId, 'duplicate' => true]);
    }

    if (!isset($state['parts'][(string)$index])) {
        $partName = $name . '.part-' . str_pad((string)($index + 1), 4, '0', STR_PAD_LEFT);
        $caption = 'قطعه ' . ($index + 1) . ' از ' . $totalParts . ' | ' . $name;
        $r = api('sendDocument', [
            'chat_id' => storage_chat(),
            'document' => new CURLFile($fl['tmp_name'], $fl['type'] ?: 'application/octet-stream', $partName),
            'caption' => function_exists('mb_substr') ? mb_substr($caption, 0, 1000) : substr($caption, 0, 300),
        ]);
        $fid = !empty($r['ok']) ? extract_file_id($r['result']) : null;
        if (!$fid) {
            flock($fp, LOCK_UN); fclose($fp);
            json_out(['ok' => false, 'msg' => 'بله در ذخیره قطعه ' . ($index + 1) . ' خطا داد: ' . ($r['description'] ?? 'نامشخص')]);
        }
        $state['parts'][(string)$index] = [
            'index' => $index, 'file_id' => $fid, 'message_id' => $r['result']['message_id'] ?? null,
            'size' => (int)$fl['size'],
        ];
    }

    $completed = count($state['parts']) === $totalParts;
    $recordId = $state['record_id'] ?? '';
    if ($completed && empty($state['completed'])) {
        ksort($state['parts'], SORT_NUMERIC);
        $parts = array_values($state['parts']);
        $rec = [
            'id' => bin2hex(random_bytes(6)), 'share' => new_token(), 'name' => $name,
            'folder' => $folder, 'size' => $originalSize,
            'file_id' => $parts[0]['file_id'], 'message_id' => $parts[0]['message_id'] ?? null,
            'parts' => $parts, 'date' => time(),
        ];
        if ($rec['folder'] !== '' && !db_folder_exists($rec['folder'])) $rec['folder'] = '';
        db_add_file($rec);
        bale_notify_upload($rec);
        $recordId = $rec['id'];
        auth_log('upload', $name, fmt_size($originalSize));
        $state['record_id'] = $recordId;
        $state['completed'] = true;
        $state['completed_at'] = time();
    }

    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE)); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    json_out(['ok' => true, 'completed' => $completed, 'record_id' => $recordId, 'parts_uploaded' => count($state['parts']), 'total_parts' => $totalParts]);
}

/* ---------- آپلود (AJAX، هر فایل یک درخواست) ---------- */
if (($_POST['action'] ?? '') === 'upload') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) json_out(['ok' => false, 'msg' => 'CSRF'], 403);
    session_write_close();
    set_time_limit(0);

    $fl = $_FILES['file'] ?? null;
    if (!$fl) json_out(['ok' => false, 'msg' => 'فایلی دریافت نشد (احتمالاً بزرگ‌تر از post_max_size سرور است)']);
    if ($fl['error'] !== UPLOAD_ERR_OK) json_out(['ok' => false, 'msg' => 'خطای آپلود PHP، کد: ' . $fl['error']]);
    if ($fl['size'] > MAX_UP) json_out(['ok' => false, 'msg' => 'بیشتر از ۵۰ مگابایت است']);

    $name = basename((string)$fl['name']);
    $folder = (string)($_POST['folder'] ?? '');
    $r = api('sendDocument', [
        'chat_id'  => storage_chat(),
        'document' => new CURLFile($fl['tmp_name'], $fl['type'] ?: 'application/octet-stream', $name),
        'caption'  => function_exists('mb_substr') ? mb_substr($name, 0, 1000) : substr($name, 0, 300),
    ]);
    $fid = !empty($r['ok']) ? extract_file_id($r['result']) : null;
    if (!$fid) json_out(['ok' => false, 'msg' => 'بله: ' . ($r['description'] ?? 'نامشخص')]);

    $rec = [
        'id'         => bin2hex(random_bytes(6)),
        'share'      => new_token(),
        'name'       => $name,
        'folder'     => $folder,
        'size'       => (int)$fl['size'],
        'file_id'    => $fid,
        'message_id' => $r['result']['message_id'] ?? null,
        'date'       => time(),
    ];
    if ($rec['folder'] !== '' && !db_folder_exists($rec['folder'])) $rec['folder'] = '';
    db_add_file($rec);
    bale_notify_upload($rec);
    json_out(['ok' => true]);
}

/* ---------- عملیات POST دیگر ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) { http_response_code(403); exit('CSRF'); }
    $folder = (string)($_POST['folder'] ?? '');
    $act = (string)($_POST['action'] ?? '');
    $id  = (string)($_POST['id'] ?? '');
    $clean = fn($s) => trim(str_replace(['/', '\\'], '', (string)$s));

    if ($act === 'mkdir') {
        $n = $clean($_POST['name'] ?? '');
        if ($n === '') { flash('نام پوشه خالی است', 'err'); }
        elseif (db_add_folder($n)) { flash('پوشه ساخته شد'); $folder = $n; auth_log('mkdir', $n); }
        else { flash('این پوشه قبلاً وجود دارد', 'err'); }
    }

    if ($act === 'rmdir') {
        $n = (string)($_POST['name'] ?? '');
        if (db_delete_folder($n)) { flash('پوشه حذف شد'); $folder = ''; auth_log('rmdir', $n); }
        else { flash('پوشه خالی نیست', 'err'); }
    }

    if ($act === 'renamedir') {
        $old = (string)($_POST['name'] ?? '');
        $new = $clean($_POST['to'] ?? '');
        if ($new === '') { flash('نام جدید خالی است', 'err'); }
        elseif ($new === $old) { flash('نام تغییری نکرده است', 'err'); }
        else {
            $res = db_rename_folder($old, $new);
            if ($res === 'ok') { flash('نام پوشه تغییر کرد'); $folder = $new; auth_log('renamedir', $old, $new); }
            elseif ($res === 'exists') { flash('پوشه‌ای با این نام وجود دارد', 'err'); }
            else { flash('پوشه پیدا نشد', 'err'); }
        }
    }

    if ($act === 'delete') {
        $file = db_delete_file($id);
        if ($file) {
            $mids = [];
            if (!empty($file['parts']) && is_array($file['parts'])) {
                foreach ($file['parts'] as $part) if (!empty($part['message_id'])) $mids[] = $part['message_id'];
            } elseif (!empty($file['message_id'])) {
                $mids[] = $file['message_id'];
            }
            foreach ($mids as $mid) api('deleteMessage', ['chat_id' => storage_chat(), 'message_id' => $mid]);
            auth_log('delete', $file['name']);
            flash('فایل از فایل‌منیجر حذف شد');
        } else {
            flash('فایل پیدا نشد', 'err');
        }
    }

    if ($act === 'rename') {
        $n = $clean($_POST['name'] ?? '');
        if ($n !== '') { db_rename_file($id, $n); auth_log('rename', $id, $n); }
        flash('نام فایل تغییر کرد');
    }

    if ($act === 'move') {
        $to = (string)($_POST['to'] ?? '');
        if ($to !== '' && !db_folder_exists($to)) { flash('پوشه مقصد پیدا نشد', 'err'); }
        else { db_move_file($id, $to); auth_log('move', $id, $to); flash('فایل منتقل شد'); }
    }

    if ($act === 'newlink') {
        db_new_share($id);
        auth_log('newlink', $id);
        flash('لینک جدید ساخته شد؛ لینک قبلی دیگر کار نمی‌کند');
    }

    if ($act === 'send') {
        $sendTo = storage_ctx()['send'];
        if ($sendTo === '') {
            flash('شناسهٔ چت ارسال در تنظیمات مشخص نشده است', 'err');
        } else {
            $file = find_by('id', $id);
            if ($file) {
                $parts = !empty($file['parts']) && is_array($file['parts']) ? array_values($file['parts']) : [['file_id' => $file['file_id']]];
                $sent = 0; $error = '';
                foreach ($parts as $i => $part) {
                    $caption = count($parts) > 1 ? ($file['name'] . ' - قطعه ' . ($i + 1) . ' از ' . count($parts)) : $file['name'];
                    $r = api('sendDocument', ['chat_id' => $sendTo, 'document' => $part['file_id'], 'caption' => $caption]);
                    if (!empty($r['ok'])) $sent++; else { $error = $r['description'] ?? 'خطا'; break; }
                }
                if ($sent === count($parts)) {
                    flash(count($parts) > 1 ? 'تمام قطعات فایل به چت بله ارسال شدند' : 'فایل به چت بله شما ارسال شد');
                    // لینک اشتراک هم در همان چت نمایش داده شود
                    $linkTxt = '🔗 <b>' . h((string)$file['name']) . "</b>\n"
                             . '👁 مشاهده: ' . base_url() . '?s=' . urlencode((string)$file['share']) . "\n"
                             . '⬇️ دانلود: ' . base_url() . '?s=' . urlencode((string)$file['share']) . '&dl=1';
                    api('sendMessage', ['chat_id' => $sendTo, 'text' => $linkTxt, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
                }
                else flash('ارسال ناقص بود (' . $sent . ' از ' . count($parts) . ' قطعه): ' . $error, 'err');
                auth_log('send', $file['name']);
            }
        }
    }

    back($folder);
}

/* ---------- نمایش پنل ---------- */
$d = load_data();


$cur = (string)($_GET['f'] ?? '');
if ($cur !== '' && !in_array($cur, $d['folders'], true)) $cur = '';
$files = array_values(array_filter($d['files'], fn($x) => $x['folder'] === $cur));
usort($files, fn($a, $b) => $b['date'] <=> $a['date']);

$counts = ['' => 0]; foreach ($d['folders'] as $fo) $counts[$fo] = 0;
$total = 0;
foreach ($d['files'] as $x) { $counts[$x['folder']] = ($counts[$x['folder']] ?? 0) + 1; $total += $x['size']; }

$fl = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$csrf = $_SESSION['csrf'];
$base = base_url();

$not_configured = !storage_ready();
$no_default_access = $not_configured && (int)(auth_user()['use_default_bot'] ?? 0) !== 1 && trim((string)(auth_user()['bot_token'] ?? '')) === '';
if (!$not_configured && !isset($_SESSION['bot'])) {
    $me = api('getMe');
    $_SESSION['bot_ok'] = !empty($me['ok']);
    $_SESSION['bot'] = $_SESSION['bot_ok'] ? ('@' . ($me['result']['username'] ?? '?')) : '';
}
$php_upload_limit = ini_bytes(ini_get('upload_max_filesize')) ?: MAX_CHUNK;
$php_post_limit = ini_bytes(ini_get('post_max_size')) ?: MAX_CHUNK;
$php_limit = max(0, min(MAX_CHUNK, $php_upload_limit, $php_post_limit) - (512 * 1024));

$jsFiles = [];
foreach ($files as $x) {
    $e = ext_of($x['name']); $k = kind_of($e); $big = ($x['size'] > MAX_DL && empty($x['parts']));
    $jsFiles[] = [
        'id' => $x['id'], 'name' => $x['name'], 'size' => (int)$x['size'], 'kind' => $k,
        'pv' => (!$big && $x['size'] <= MAX_DL && can_preview($k, $e)), 'big' => $big, 'chunked' => !empty($x['parts']),
        'raw' => '?raw=' . $x['id'], 'dl' => '?dl=' . $x['id'],
        'share'  => $base . '?s=' . $x['share'],
        'direct' => $base . '?s=' . $x['share'] . '&raw=1',
        'dlink'  => $base . '?s=' . $x['share'] . '&dl=1',
    ];
}
$JF = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>پنل فایل</title><style><?= css() ?></style></head><body>
<?php sprite(); ?>

<div class="top"><div class="top-in">
  <div class="logo"><i><?= ic('cloud') ?></i><b>پنل فایل</b></div>
  <div class="search"><?= ic('search') ?><input id="q" placeholder="جستجو در فایل‌های این پوشه…"></div>
  <a class="btn" href="?page=bale" title="اطلاعات بله"><?= ic('cloud') ?><span class="tx">اطلاعات بله</span></a>
  <?php if (auth_is_admin()): ?><a class="btn" href="admin/" title="پنل مدیریت"><?= ic('doc') ?><span class="tx">مدیریت</span></a><?php endif; ?>
  <button class="btn p" id="upBtn"><?= ic('upload') ?><span class="tx">آپلود</span></button>
  <?php
    $meAv = (string)($meRow['avatar'] ?? '');
    $initial = trim((string)$meRow['name']) !== '' ? mb_substr(trim((string)$meRow['name']), 0, 1) : '?';
    $roleTxt = auth_is_admin() ? 'مدیر سامانه' : 'کاربر';
  ?>
  <div class="uchip" id="uchip">
    <button type="button" id="uchipBtn" title="حساب کاربری">
      <span class="av"><?= $meAv !== '' && is_file(av_dir() . '/' . basename($meAv)) ? '<img src="' . h(av_url((int)$meRow['id'], $meAv)) . '" alt="">' : h($initial) ?></span>
      <span><span class="un"><?= h($meRow['name'] !== '' ? $meRow['name'] : $meRow['username']) ?></span><span class="uro"><?= $roleTxt ?></span></span>
    </button>
    <div class="umenu" id="umenu">
      <a href="?page=settings"><?= ic('user') ?>پروفایل و تنظیمات</a>
      <a href="?page=bale"><?= ic('cloud') ?>اطلاعات ربات بله</a>
      <?php if (auth_is_admin()): ?><a href="admin/"><?= ic('doc') ?>پنل مدیریت</a><?php endif; ?>
      <hr>
      <a class="dg" href="?logout=1"><?= ic('logout') ?>خروج از حساب</a>
    </div>
  </div>
</div></div>

<div class="app">
  <aside class="side">
    <div class="box">
      <h4>پوشه‌ها <button class="ib" id="mkBtn" title="پوشه جدید" style="width:28px;height:28px"><?= ic('plus') ?></button></h4>
      <div class="fl">
        <a href="?f=" class="<?= $cur === '' ? 'on' : '' ?>"><?= ic('cloud') ?>همه فایل‌ها (ریشه)<span><?= (int)$counts[''] ?></span></a>
        <?php foreach ($d['folders'] as $fo): ?>
          <a href="?f=<?= urlencode($fo) ?>" class="<?= $cur === $fo ? 'on' : '' ?>"><?= ic('folder') ?><?= h($fo) ?><span><?= (int)($counts[$fo] ?? 0) ?></span></a>
        <?php endforeach; ?>
      </div>
      <a href="?page=bale" style="display:flex;align-items:center;gap:9px;padding:10px 8px;margin-top:8px;border-radius:10px;color:var(--primary);font-weight:700"><?= ic('cloud') ?>اطلاعات ربات و چت بله</a>
    </div>
    <div class="box">
      <h4>فضای ذخیره‌سازی</h4>
      <?php
        $uu = user_usage(current_user_id());
        $uq = user_quota(current_user_id());
        $upct = ($uq > 0) ? min(100, (int)round($uu * 100 / $uq)) : 0;
      ?>
      <?php if ($uq > 0): ?>
        <div class="meter"><i class="<?= $upct >= 90 ? 'full' : '' ?>" style="width:<?= $upct ?>%"></i></div>
        <div class="stat"><span><?= fa_num($upct) ?>٪ از سهمیه</span><b><?= fmt_size($uu) ?> / <?= fmt_size($uq) ?></b></div>
      <?php else: ?>
        <div class="meter"><i style="width:<?= min(100, max(4, (int)min(100, $uu / (2 * 1073741824) * 100))) ?>%"></i></div>
        <div class="stat"><span>مصرف</span><b><?= fmt_size($uu) ?></b></div>
      <?php endif; ?>
      <div class="stat"><span>تعداد فایل</span><b><?= count($d['files']) ?></b></div>
      <div class="stat"><span>محل ذخیره</span><b>سرور بله</b></div>
      <?php if (!empty($_SESSION['bot'])): ?><div class="stat"><span>بازو</span><b dir="ltr"><?= h($_SESSION['bot']) ?></b></div><?php endif; ?>
      <a class="btn sm" href="?page=settings" style="width:100%;justify-content:center;margin-top:8px"><?= ic('user') ?><span class="tx">مدیریت حساب</span></a>
    </div>
  </aside>

  <main>
    <?php if (db_get('maintenance', '0') === '1'): ?><div class="warn">سامانه در حالت تعمیر است؛ ورود کاربران عادی و لینک‌های اشتراک موقتاً از دسترس خارج‌اند.</div><?php endif; ?>
    <?php if ($not_configured): ?><div class="warn"><?= $no_default_access ? 'دسترسی شما به ربات پیش‌فرض سامانه فعال نشده است؛ از مدیر بخواهید آن را فعال کند یا توکن اختصاصی خود را در «تنظیمات» وارد کنید.' : 'توکن ربات و شناسهٔ چت در «تنظیمات» مشخص نشده است.' ?></div><?php endif; ?>
    <?php if ($php_limit < 256 * 1024): ?><div class="warn">محدودیت upload_max_filesize یا post_max_size در PHP بسیار پایین است؛ برای فعال شدن آپلود قطعه‌ای، این دو مقدار را در تنظیمات PHP افزایش دهید.</div><?php endif; ?>
    <?php if (isset($_SESSION['bot_ok']) && !$_SESSION['bot_ok']): ?><div class="warn">توکن بازو معتبر نیست یا سرور به tapi.bale.ai دسترسی ندارد.</div><?php endif; ?>

    <div class="head">
      <h2><?= ic($cur === '' ? 'cloud' : 'folder') ?><?= $cur === '' ? 'همه فایل‌ها' : h($cur) ?></h2>
      <?php if ($cur !== ''): ?><button class="btn sm" id="rnBtn"><?= ic('edit') ?><span class="tx">تغییر نام پوشه</span></button><button class="btn sm" id="rmBtn"><?= ic('trash') ?><span class="tx">حذف پوشه</span></button><?php endif; ?>
      <div class="sp"></div>
      <select class="sel" id="sort">
        <option value="date">جدیدترین</option>
        <option value="name">نام</option>
        <option value="size">حجم</option>
      </select>
      <div class="seg"><button id="vGrid" title="نمای کارتی"><?= ic('grid') ?></button><button id="vList" title="نمای لیستی"><?= ic('list') ?></button></div>
    </div>

    <div class="chips" style="margin-bottom:14px" id="cats">
      <button class="chip on" data-cat="all">همه</button>
      <button class="chip" data-cat="image">تصاویر</button>
      <button class="chip" data-cat="video">ویدیو</button>
      <button class="chip" data-cat="audio">صوت</button>
      <button class="chip" data-cat="doc">اسناد</button>
      <button class="chip" data-cat="other">سایر</button>
    </div>

    <?php if (!$files): ?>
      <div class="empty"><?= ic('upload') ?><h3>اینجا هنوز فایلی نیست</h3><div>فایل‌ها را بکشید و رها کنید یا دکمه آپلود را بزنید.</div></div>
    <?php else: ?>
    <div class="files" id="files">
      <?php foreach ($files as $x):
        $e = ext_of($x['name']); $k = kind_of($e); $big = ($x['size'] > MAX_DL && empty($x['parts']));
        $hasThumb = ($k === 'image' && $x['size'] <= THUMB_MAX);
        $pvable = (!$big && $x['size'] <= MAX_DL && can_preview($k, $e)); ?>
      <article class="cf k-<?= $k ?>" data-id="<?= h($x['id']) ?>" data-cat="<?= cat_of($k) ?>" data-name="<?= h($x['name']) ?>" data-size="<?= (int)$x['size'] ?>" data-date="<?= (int)$x['date'] ?>">
        <div class="thumb" data-open="<?= h($x['id']) ?>">
          <?php if ($hasThumb): ?>
            <img loading="lazy" decoding="async" src="?raw=<?= h($x['id']) ?>" alt="">
          <?php else: ?>
            <div class="fi"><div class="bx"><?= ic(icon_for($k)) ?></div><em><?= h($e !== '' ? substr($e, 0, 5) : 'file') ?></em></div>
          <?php endif; ?>
          <?php if ($k === 'video' && $pvable): ?><span class="badge-play"><?= ic('play') ?></span><?php endif; ?>
        </div>
        <div class="meta">
          <div class="nm" title="<?= h($x['name']) ?>"><?= h($x['name']) ?></div>
          <div class="sub"><?= fmt_size($x['size']) ?><?= !empty($x['parts']) ? ' · چندبخشی' : ($big ? ' · محدودیت دانلود قدیمی' : '') ?> · <?= fa_date($x['date']) ?></div>
        </div>
        <div class="acts">
          <a class="ib <?= $big ? 'off' : '' ?>" href="?dl=<?= h($x['id']) ?>" title="دانلود"><?= ic('download') ?></a>
          <button class="ib" data-copy="<?= h($x['id']) ?>" title="کپی لینک اشتراک"><?= ic('link') ?></button>
          <span class="sp"></span>
          <button class="ib" data-menu="<?= h($x['id']) ?>" title="بیشتر"><?= ic('more') ?></button>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <div class="empty" id="noRes" style="display:none"><?= ic('search') ?><h3>موردی پیدا نشد</h3></div>
    <?php endif; ?>

    <div class="sub" style="margin-top:16px">
      اندازه هر قطعه آپلود: <?= fmt_size($php_limit) ?> · حداکثر اندازه فایل: ۵GB · فایل‌های بزرگ به چند سند در بله تقسیم و هنگام دانلود یکپارچه می‌شوند
    </div>
  </main>
</div>

<!-- پیش‌نمایش -->
<div class="ov" id="pvOv"><div class="pv">
  <div class="pv-h"><div class="nm" id="pvName"></div><span class="sub" id="pvInfo"></span><button class="ib" id="pvX"><?= ic('x') ?></button></div>
  <div class="pv-b" id="pvBox"></div>
  <button class="nav r" id="pvPrev" title="قبلی"><?= ic('right') ?></button>
  <button class="nav l" id="pvNext" title="بعدی"><?= ic('left') ?></button>
  <div class="pv-f">
    <input id="pvLink" readonly>
    <button class="btn sm" id="pvCopy"><?= ic('copy') ?><span class="tx">کپی لینک</span></button>
    <a class="btn sm p" id="pvDl"><?= ic('download') ?><span class="tx">دانلود</span></a>
  </div>
</div></div>

<!-- انتقال -->
<div class="ov" id="mvOv"><div class="pv" style="max-width:380px">
  <div class="pv-h"><div class="nm">انتقال به پوشه</div><button class="ib" id="mvX"><?= ic('x') ?></button></div>
  <div class="fl" id="mvList" style="padding:10px;display:block"></div>
</div></div>

<div class="menu" id="menu"></div>
<div class="uq" id="uq"><div class="uq-h"><span>آپلودها</span><button class="ib" id="uqX" style="width:28px;height:28px"><?= ic('x') ?></button></div><div id="uqList"></div></div>
<div class="drop" id="drop"><div style="text-align:center"><?= ic('upload') ?>برای آپلود رها کنید</div></div>
<input type="file" id="fi" multiple hidden>
<div id="toast"></div>

<script>
<?= preview_js() ?>
var FILES = <?= json_encode($jsFiles, $JF) ?>;
var FOLDERS = <?= json_encode($d['folders'], $JF) ?>;
var CUR = <?= json_encode($cur, $JF) ?>, CSRF = <?= json_encode($csrf) ?>, CHUNK_SIZE = <?= (int)$php_limit ?>, MAX_FILE_SIZE = <?= (int)MAX_LOGICAL_UPLOAD ?>;
var CAN_SEND = <?= storage_ctx()['send'] !== '' ? 'true' : 'false' ?>, FLASH = <?= json_encode($fl, $JF) ?>;
var BYID = {}; FILES.forEach(function(f){ BYID[f.id] = f; });
var $ = function(id){ return document.getElementById(id); };
var IC = function(n){ return '<svg class="ic"><use href="#i-' + n + '"/></svg>'; };

/* پیام */
var tt;
function toast(m, err){ var t = $('toast'); t.textContent = m; t.className = 'on' + (err ? ' err' : ''); clearTimeout(tt); tt = setTimeout(function(){ t.className = ''; }, 2200); }
function copy(t){ copyText(t).then(function(){ toast('لینک کپی شد'); }).catch(function(){ prompt('لینک را کپی کنید:', t); }); }
if (FLASH) toast(FLASH[0], FLASH[1] === 'err');

/* ارسال فرم POST */
function post(action, id, extra){
  var f = document.createElement('form'); f.method = 'post'; f.action = location.pathname;
  var add = function(k, v){ var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); };
  add('csrf', CSRF); add('action', action); add('id', id || ''); add('folder', CUR);
  for (var k in (extra || {})) add(k, extra[k]);
  document.body.appendChild(f); f.submit();
}
$('mkBtn').onclick = function(){ var n = prompt('نام پوشه جدید:'); if (n && n.trim()) post('mkdir', '', {name: n.trim()}); };
if ($('rnBtn')) $('rnBtn').onclick = function(){ var n = prompt('نام جدید پوشه:', CUR); if (n && n.trim() && n.trim() !== CUR) post('renamedir', '', {name: CUR, to: n.trim()}); };
if ($('rmBtn')) $('rmBtn').onclick = function(){ if (confirm('پوشه «' + CUR + '» حذف شود؟ (فقط اگر خالی باشد)')) post('rmdir', '', {name: CUR}); };

/* فیلتر، جستجو، مرتب‌سازی، نما */
var st = {cat: 'all', q: '', sort: 'date'};
function cards(){ return Array.prototype.slice.call(document.querySelectorAll('.cf')); }
function apply(){
  var cs = cards(), box = $('files'); if (!box) return;
  var shown = 0;
  cs.forEach(function(c){
    var ok = (st.cat === 'all' || c.dataset.cat === st.cat) && c.dataset.name.toLowerCase().indexOf(st.q) > -1;
    c.style.display = ok ? '' : 'none'; if (ok) shown++;
  });
  cs.sort(function(a, b){
    if (st.sort === 'name') return a.dataset.name.localeCompare(b.dataset.name, 'fa');
    if (st.sort === 'size') return b.dataset.size - a.dataset.size;
    return b.dataset.date - a.dataset.date;
  }).forEach(function(c){ box.appendChild(c); });
  $('noRes').style.display = shown ? 'none' : '';
}
$('q').oninput = function(){ st.q = this.value.toLowerCase().trim(); apply(); };
$('sort').onchange = function(){ st.sort = this.value; apply(); };
$('cats').onclick = function(e){
  var b = e.target.closest('.chip'); if (!b) return;
  Array.prototype.forEach.call(document.querySelectorAll('.chip'), function(x){ x.classList.remove('on'); });
  b.classList.add('on'); st.cat = b.dataset.cat; apply();
};
function setView(v){
  var box = $('files'); if (box) box.classList.toggle('list', v === 'list');
  $('vGrid').classList.toggle('on', v !== 'list'); $('vList').classList.toggle('on', v === 'list');
  try { localStorage.setItem('view', v); } catch (e) {}
}
$('vGrid').onclick = function(){ setView('grid'); };
$('vList').onclick = function(){ setView('list'); };
var sv = 'grid'; try { sv = localStorage.getItem('view') || 'grid'; } catch (e) {}
setView(sv);

/* پیش‌نمایش */
var pvList = [], pvIdx = -1;
function openPv(id){
  pvList = cards().filter(function(c){ return c.style.display !== 'none'; }).map(function(c){ return BYID[c.dataset.id]; });
  pvIdx = pvList.findIndex(function(f){ return f.id === id; });
  if (pvIdx < 0) return;
  showPv(); $('pvOv').classList.add('on'); document.body.style.overflow = 'hidden';
}
function showPv(){
  var f = pvList[pvIdx];
  $('pvName').textContent = f.name;
  $('pvInfo').textContent = (pvIdx + 1) + ' / ' + pvList.length;
  mountPreview($('pvBox'), f.pv ? f.kind : (f.big ? 'big' : 'none'), f.raw, f.size);
  $('pvLink').value = f.share;
  var d = $('pvDl'); d.href = f.dl; d.classList.toggle('off', f.big);
  $('pvPrev').style.display = $('pvNext').style.display = pvList.length > 1 ? '' : 'none';
}
function closePv(){ $('pvOv').classList.remove('on'); $('pvBox').innerHTML = ''; document.body.style.overflow = ''; }
function stepPv(n){ if (pvList.length < 2) return; pvIdx = (pvIdx + n + pvList.length) % pvList.length; showPv(); }
$('pvX').onclick = closePv;
$('pvOv').onclick = function(e){ if (e.target === this) closePv(); };
$('pvPrev').onclick = function(){ stepPv(-1); };
$('pvNext').onclick = function(){ stepPv(1); };
$('pvCopy').onclick = function(){ copy($('pvLink').value); };
document.addEventListener('keydown', function(e){
  if ($('pvOv').classList.contains('on')) {
    if (e.key === 'Escape') closePv();
    if (e.key === 'ArrowLeft') stepPv(1);
    if (e.key === 'ArrowRight') stepPv(-1);
  }
  if (e.key === 'Escape') { $('mvOv').classList.remove('on'); hideMenu(); }
});

/* انتقال */
function openMove(id){
  var l = $('mvList'); l.innerHTML = '';
  var opts = [['', 'ریشه']].concat(FOLDERS.map(function(x){ return [x, x]; }));
  opts.forEach(function(o){
    var a = document.createElement('a'); a.href = '#'; a.innerHTML = IC(o[0] === '' ? 'cloud' : 'folder');
    a.appendChild(document.createTextNode(o[1]));
    if (o[0] === CUR) a.className = 'on';
    a.onclick = function(e){ e.preventDefault(); post('move', id, {to: o[0]}); };
    l.appendChild(a);
  });
  $('mvOv').classList.add('on');
}
$('mvX').onclick = function(){ $('mvOv').classList.remove('on'); };
$('mvOv').onclick = function(e){ if (e.target === this) this.classList.remove('on'); };

/* منوی بیشتر */
function hideMenu(){ $('menu').style.display = 'none'; }
function openMenu(btn, id){
  var f = BYID[id], m = $('menu'); m.innerHTML = '';
  var items = [
    f.pv ? ['eye', 'پیش‌نمایش', function(){ openPv(id); }] : null,
    ['link', 'کپی لینک اشتراک (صفحه)', function(){ copy(f.share); }],
    ['link', 'کپی لینک مستقیم (نمایش)', function(){ copy(f.direct); }],
    ['download', 'کپی لینک دانلود', function(){ copy(f.dlink); }],
    'hr',
    ['edit', 'تغییر نام', function(){ var n = prompt('نام جدید:', f.name); if (n && n.trim()) post('rename', id, {name: n.trim()}); }],
    ['folder', 'انتقال به پوشه…', function(){ openMove(id); }],
    CAN_SEND ? ['send', 'ارسال به چت بله من', function(){ post('send', id); }] : null,
    ['refresh', 'ساخت لینک جدید (ابطال قبلی)', function(){ if (confirm('لینک قبلی از کار می‌افتد. ادامه؟')) post('newlink', id); }],
    'hr',
    ['trash', 'حذف', function(){ if (confirm('«' + f.name + '» حذف شود؟')) post('delete', id); }, true]
  ].filter(Boolean);
  items.forEach(function(it){
    if (it === 'hr') { m.appendChild(document.createElement('hr')); return; }
    var b = document.createElement('button'); b.innerHTML = IC(it[0]);
    b.appendChild(document.createTextNode(it[1]));
    if (it[3]) b.className = 'dg';
    b.onclick = function(){ hideMenu(); it[2](); };
    m.appendChild(b);
  });
  m.style.display = 'block';
  var r = btn.getBoundingClientRect(), w = m.offsetWidth, h = m.offsetHeight;
  var left = Math.min(Math.max(8, r.right - w), window.innerWidth - w - 8);
  var top = r.bottom + 6; if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
  m.style.left = (left + window.scrollX) + 'px'; m.style.top = (top + window.scrollY) + 'px';
}

/* کلیک‌ها (event delegation) */
document.addEventListener('click', function(e){
  var t;
  if ((t = e.target.closest('[data-menu]'))) { e.stopPropagation(); openMenu(t, t.dataset.menu); return; }
  if ((t = e.target.closest('[data-copy]'))) { copy(BYID[t.dataset.copy].share); return; }
  if ((t = e.target.closest('[data-open]'))) { openPv(t.dataset.open); return; }
  if (!e.target.closest('#menu')) hideMenu();
});
window.addEventListener('scroll', hideMenu, true);

/* آپلود */
var fi = $('fi');
$('upBtn').onclick = function(){ fi.click(); };
fi.onchange = function(){ startUp(fi.files); };
var dc = 0;
function hasFiles(e){ return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') > -1; }
window.addEventListener('dragenter', function(e){ if (hasFiles(e)) { e.preventDefault(); dc++; $('drop').classList.add('on'); } });
window.addEventListener('dragover', function(e){ if (hasFiles(e)) e.preventDefault(); });
window.addEventListener('dragleave', function(e){ if (hasFiles(e) && --dc <= 0) { dc = 0; $('drop').classList.remove('on'); } });
window.addEventListener('drop', function(e){ if (hasFiles(e)) { e.preventDefault(); dc = 0; $('drop').classList.remove('on'); startUp(e.dataTransfer.files); } });
$('uqX').onclick = function(){ $('uq').classList.remove('on'); };

var busy = false, queue = [], okCount = 0;
function startUp(files){
  Array.prototype.forEach.call(files, function(f){ queue.push(f); });
  fi.value = '';
  if (!busy) runQueue();
}
function runQueue(){
  busy = true;
  (function next(){
    var f = queue.shift();
    if (!f) { busy = false; if (okCount) setTimeout(function(){ location.reload(); }, 800); return; }
    sendOne(f).then(function(ok){ if (ok) okCount++; next(); });
  })();
}
function sendOne(f){
  return new Promise(function(res){
    $('uq').classList.add('on');
    var row = document.createElement('div'); row.className = 'up';
    var t = document.createElement('div'); t.className = 't';
    var nm = document.createElement('b'); nm.textContent = f.name;
    var sp = document.createElement('span'); sp.textContent = 'آماده‌سازی…';
    t.appendChild(nm); t.appendChild(sp);
    var bar = document.createElement('div'); bar.className = 'bar'; var fill = document.createElement('i'); bar.appendChild(fill);
    row.appendChild(t); row.appendChild(bar); $('uqList').appendChild(row);
    $('uq').scrollTop = $('uq').scrollHeight;

    if (f.size > MAX_FILE_SIZE) { row.className = 'up e'; sp.textContent = 'حداکثر حجم فایل ۵ گیگابایت است'; fill.style.width = '100%'; return res(false); }
    if (!CHUNK_SIZE || CHUNK_SIZE < 256 * 1024) { row.className = 'up e'; sp.textContent = 'محدودیت PHP هاست برای آپلود قطعه‌ای کافی نیست'; return res(false); }
    if (f.size < 1) { row.className = 'up e'; sp.textContent = 'فایل خالی قابل آپلود نیست'; return res(false); }

    var total = Math.ceil(f.size / CHUNK_SIZE);
    var uploadId = '';
    if (window.crypto && crypto.getRandomValues) {
      var bytes = new Uint8Array(16); crypto.getRandomValues(bytes);
      uploadId = Array.prototype.map.call(bytes, function(b){ return b.toString(16).padStart(2, '0'); }).join('');
    } else uploadId = (Date.now().toString(16) + Math.random().toString(16).slice(2)).padEnd(32, '0').slice(0, 32);

    function sendPart(index){
      var start = index * CHUNK_SIZE, end = Math.min(start + CHUNK_SIZE, f.size);
      var blob = f.slice(start, end);
      var fd = new FormData();
      fd.append('action', 'upload_chunk'); fd.append('csrf', CSRF); fd.append('folder', CUR);
      fd.append('upload_id', uploadId); fd.append('part_index', index); fd.append('total_parts', total);
      fd.append('chunk_size', CHUNK_SIZE); fd.append('original_size', f.size); fd.append('original_name', f.name);
      fd.append('chunk', blob, f.name + '.part-' + (index + 1));
      sp.textContent = 'ارسال قطعه ' + (index + 1) + ' از ' + total + '…';
      var x = new XMLHttpRequest(); x.open('POST', location.pathname);
      x.upload.onprogress = function(e){
        if (e.lengthComputable) {
          var sent = start + Math.min(e.loaded, blob.size);
          var percent = Math.min(100, sent / f.size * 100);
          fill.style.width = percent + '%';
          sp.textContent = 'قطعه ' + (index + 1) + ' از ' + total + ' · ' + Math.round(percent) + '٪';
        }
      };
      x.onload = function(){
        var j; try { j = JSON.parse(x.responseText); } catch (e) { j = {ok:false, msg:'پاسخ نامعتبر از سرور'}; }
        if (!j.ok) { row.className = 'up e'; sp.textContent = '✗ قطعه ' + (index + 1) + ': ' + (j.msg || 'خطا'); res(false); return; }
        if (j.completed || index + 1 === total) {
          fill.style.width = '100%'; row.className = 'up d'; sp.textContent = '✓ فایل کامل در بله ذخیره شد (' + total + ' قطعه)'; res(true); return;
        }
        sendPart(index + 1);
      };
      x.onerror = function(){ row.className = 'up e'; sp.textContent = '✗ خطای شبکه در قطعه ' + (index + 1) + '؛ دوباره آپلود کنید'; res(false); };
      x.send(fd);
    }
    sendPart(0);
  });
}

/* ---------- منوی حساب کاربری ---------- */
(function () {
  var chip = $('uchip'), btn = $('uchipBtn'), menu = $('umenu');
  if (!chip || !btn || !menu) return;
  btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('on'); });
  document.addEventListener('click', function (e) { if (!chip.contains(e.target)) menu.classList.remove('on'); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') menu.classList.remove('on'); });
})();
</script>
</body></html>