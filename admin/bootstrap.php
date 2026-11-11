<?php
/**
 * راه‌انداز بخش مدیریت — همهٔ صفحات پوشهٔ admin این فایل را require می‌کنند.
 * نشست، محافظت دسترسی (فقط مدیر)، ابزارهای مشترک و پوستهٔ سایدباردار.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/store.php';
require_once dirname(__DIR__) . '/lib/fonts.php';
require_once dirname(__DIR__) . '/lib/safir.php';

db_boot();

/* ---------- نشست، هم‌سان با پنل اصلی ---------- */
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

/* ---------- محافظت دسترسی: فقط مدیر واردشده ---------- */
if (!auth_user()) { header('Location: ../?page=login'); exit; }
if (!auth_is_admin()) { header('Location: ../'); exit; }

$me = auth_user(true);

/* ---------- ابزارهای مشترک ---------- */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_size($b) {
    foreach (['B', 'KB', 'MB', 'GB'] as $u) { if ($b < 1024) return round($b, 1) . " $u"; $b /= 1024; }
    return round($b, 1) . ' TB';
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

function fa_date($ts, $mode = 'datetime') {
    $pattern = ($mode === 'date') ? 'yyyy/MM/dd' : 'yyyy/MM/dd  HH:mm';
    try {
        if (class_exists('IntlDateFormatter')) {
            $f = new IntlDateFormatter('fa_IR@calendar=persian', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Asia/Tehran', IntlDateFormatter::TRADITIONAL, $pattern);
            $s = $f->format($ts);
            if ($s) return $s;
        }
    } catch (Throwable $e) {}
    try {
        $dt = new DateTime('@' . (int)$ts);
        $dt->setTimezone(new DateTimeZone('Asia/Tehran'));
        list($jy, $jm, $jd) = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('n'), (int)$dt->format('j'));
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd) . ($mode === 'date' ? '' : '  ' . $dt->format('H:i'));
    } catch (Throwable $e) {}
    return ($mode === 'date') ? date('Y-m-d', $ts) : date('Y-m-d H:i', $ts);
}

/** فراخوانی API بله با زمینهٔ دلخواه (مثلاً توکن مالک فایل) */
function adm_api(string $method, array $params = [], array $ctx = []) {
    $ch = curl_init('https://tapi.bale.ai/bot' . ($ctx['token'] ?? '') . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res === false) return ['ok' => false, 'description' => 'اتصال برقرار نشد'];
    return json_decode($res, true) ?: ['ok' => false, 'description' => 'پاسخ نامعتبر از بله'];
}

/** نشانی ریشهٔ برنامه (پوشهٔ بالای admin) برای ساخت لینک به پنل اصلی */
function adm_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    $parent = ($dir !== '' && $dir !== '/') ? str_replace('\\', '/', dirname($dir)) : '';
    $parent = ($parent === '/' || $parent === '.' || $parent === '\\') ? '' : $parent;
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $parent . '/';
}

/* ---------- اوتار (تصویر پروفایل) ---------- */
function avatar_dir(): string {
    $dir = STORAGE_DIR . '/avatars';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
        // دسترسی مستقیم به فایل‌های اوتار از مرورگر بسته شود؛ تحویل فقط از avatar.php
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

function avatar_mime(string $ext): string {
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
}

function avatar_url(int $uid, string $file = ''): string {
    $v = ($file !== '' && is_file(avatar_dir() . '/' . $file)) ? (int)filemtime(avatar_dir() . '/' . $file) : time();
    return 'avatar.php?uid=' . $uid . '&v=' . $v;
}

/* ---------- پیام یک‌بارمصرف (PRG) ---------- */
function adm_flash(string $msg, string $type = 'ok'): void { $_SESSION['adm_flash'] = [$msg, $type]; }
function adm_take_flash(): ?array {
    $f = $_SESSION['adm_flash'] ?? null;
    unset($_SESSION['adm_flash']);
    return $f;
}

/* ---------- برچسب فارسی رخدادهای گزارش ---------- */
function adm_action_fa(string $a): string {
    $m = [
        'login' => 'ورود', 'register' => 'ثبت‌نام', 'profile' => 'پروفایل', 'password' => 'تغییر رمز',
        'settings' => 'تنظیمات', 'mkdir' => 'پوشه جدید', 'rmdir' => 'حذف پوشه', 'renamedir' => 'تغییر نام پوشه',
        'delete' => 'حذف فایل', 'rename' => 'تغییر نام فایل', 'move' => 'انتقال', 'newlink' => 'لینک جدید',
        'send' => 'ارسال به بله', 'upload' => 'آپلود',
        'user_create' => 'ایجاد کاربر', 'user_toggle' => 'تغییر وضعیت کاربر', 'user_role' => 'تغییر نقش',
        'user_quota' => 'سهمیه', 'user_delete' => 'حذف کاربر', 'user_resetpass' => 'بازنشانی رمز',
        'user_defaultbot' => 'دسترسی ربات پیش‌فرض', 'user_storage' => 'ذخیره‌سازی کاربر',
        'user_profile' => 'ویرایش اطلاعات کاربر', 'user_avatar' => 'تصویر پروفایل',
        'user_sessions_out' => 'خروج از همهٔ دستگاه‌ها', 'attempts_clear' => 'پاک‌سازی تلاش‌های ناموفق',
        'global_settings' => 'تنظیمات سراسری', 'admin_file_delete' => 'حذف فایل (مدیر)', 'logs_clear' => 'پاک‌سازی گزارش',
        'bot_test' => 'تست اتصال ربات', 'maint_tokens' => 'پاک‌سازی توکن‌های منقضی',
        'maint_attempts' => 'پاک‌سازی تلاش‌های ورود', 'maint_optimize' => 'بهینه‌سازی جداول',
    ];
    return $m[$a] ?? $a;
}

/* ---------- آیکن‌ها ---------- */
function adm_sprite(): void {
    $i = [
        'grid'     => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'folder'   => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
        'cloud'    => '<path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'menu'     => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'plus'     => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'trash'    => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'x'        => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'check'    => '<polyline points="20 6 9 17 4 12"/>',
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'file'     => '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/>',
        'doc'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'drive'    => '<line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" y1="16" x2="6.01" y2="16"/><line x1="10" y1="16" x2="10.01" y2="16"/>',
        'link'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'key'      => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        'eye'      => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'  => '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
        'copy'     => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'image'    => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'zap'      => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    ];
    echo '<svg width="0" height="0" style="position:absolute" aria-hidden="true">';
    foreach ($i as $n => $p)
        echo '<symbol id="i-' . $n . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</symbol>';
    echo '</svg>';
}
function ic(string $n, string $c = ''): string { return '<svg class="ic ' . $c . '"><use href="#i-' . $n . '"/></svg>'; }

/* ---------- استایل اختصاصی پنل مدیریت ---------- */
function adm_css(): string { return vazir_font_css('../fonts/')."\n" . <<<'CSS'
:root{--bg:#f2f4f9;--surface:#fff;--surface2:#eef1f7;--text:#0f172a;--muted:#64748b;--border:#e4e7ef;--primary:#4f46e5;--primary2:#6366f1;--weak:#eef0ff;--danger:#e11d48;--ok:#059669;--shadow:0 1px 2px #0f172a0a,0 10px 30px #0f172a10;--r:14px;--sbw:262px}
@media(prefers-color-scheme:dark){:root{--bg:#0a0f1f;--surface:#121a2f;--surface2:#1a2342;--text:#e8ecf8;--muted:#8b97b8;--border:#232d4d;--primary:#818cf8;--primary2:#a5b4fc;--weak:#1b2250;--danger:#fb7185;--ok:#34d399;--shadow:0 1px 2px #0007,0 10px 30px #0006}}
*{box-sizing:border-box}
html,body{margin:0}
input[type=number]{-moz-appearance:textfield;appearance:textfield}
input[type=number]::-webkit-outer-spin-button,input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
body{font-family:'Vazirmatn FD',Vazirmatn,"IRANSans",Tahoma,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--text);font-size:14px;line-height:1.6}
a{color:inherit;text-decoration:none}
button,input,select{font:inherit;color:inherit}
.ic{width:18px;height:18px;flex:none}
.adm{display:flex;min-height:100vh}
.sb{width:var(--sbw);flex:none;position:sticky;top:0;height:100vh;overflow-y:auto;background:var(--surface);border-inline-end:1px solid var(--border);display:flex;flex-direction:column;padding:16px 12px 12px;gap:2px;z-index:40}
.sb-logo{display:flex;align-items:center;gap:11px;padding:6px 10px 12px}
.sb-logo i{display:grid;place-items:center;width:42px;height:42px;border-radius:13px;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;font-style:normal;box-shadow:0 8px 18px #4f46e540;flex:none}
.sb-logo b{display:block;font-size:15px;line-height:1.3}
.sb-logo small{color:var(--muted);font-size:11px;font-weight:600}
.sb-cap{font-size:10px;font-weight:800;letter-spacing:1.5px;color:var(--muted);padding:14px 12px 5px}
.sb a{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:11px;font-weight:600;color:var(--muted);font-size:13.5px;transition:.15s}
.sb a:hover{background:var(--surface2);color:var(--text)}
.sb a.on{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;box-shadow:0 8px 18px #4f46e53d}
.sb a.on:hover{color:#fff}
.sb .bdg{margin-inline-start:auto;background:#0001;color:inherit;font-size:11px;padding:1px 8px;border-radius:999px;font-weight:700}
.sb a.on .bdg{background:#fff2;color:#fff}
.sb-foot{margin-top:auto;border-top:1px solid var(--border);padding-top:10px}
.sb-user{display:flex;align-items:center;gap:10px;padding:8px 10px}
.sb-user .av{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;display:grid;place-items:center;font-weight:800;flex:none}
.sb-user b{display:block;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user small{color:var(--muted);font-size:11px}
.sb-out{color:var(--danger)!important}
.scrim{position:fixed;inset:0;background:#02061773;z-index:35;display:none}
.main{flex:1;min-width:0;display:flex;flex-direction:column}
.tp{position:sticky;top:0;z-index:30;background:var(--bg);border-bottom:1px solid var(--border)}
.tp-in{display:flex;align-items:center;gap:12px;padding:13px 26px}
.tp h1{margin:0;font-size:18px}
.tp .sp{flex:1}
.burger{display:inline-grid}
.tx{display:inline}
.ct{padding:24px 26px 56px;width:100%;max-width:1220px;margin:0 auto}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 15px;border-radius:11px;border:1px solid var(--border);background:var(--surface);cursor:pointer;font-weight:600;font-size:13px;transition:.15s}
.btn:hover{background:var(--surface2)}
.btn.p{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;border-color:transparent;box-shadow:0 6px 16px #4f46e540}
.btn.dg{color:var(--danger)}
.btn.sm{padding:6px 11px;font-size:12px}
.ib{display:inline-grid;place-items:center;width:36px;height:36px;border-radius:10px;border:0;background:transparent;color:var(--muted);cursor:pointer;transition:.15s}
.ib:hover{background:var(--surface2);color:var(--text)}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-bottom:20px}
.stat-c{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);padding:16px;display:flex;align-items:center;gap:13px}
.stat-c .bx{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;color:#fff;flex:none}
.stat-c .bx .ic{width:22px;height:22px}
.stat-c b{display:block;font-size:20px;line-height:1.3}
.stat-c span{color:var(--muted);font-size:12px;font-weight:600}
.stat-c small{display:block;color:var(--muted);font-size:11px;margin-top:1px}
.c1{background:linear-gradient(135deg,#6366f1,#8b5cf6)}
.c2{background:linear-gradient(135deg,#0ea5e9,#22d3ee)}
.c3{background:linear-gradient(135deg,#10b981,#34d399)}
.c4{background:linear-gradient(135deg,#f59e0b,#fbbf24)}
.c5{background:linear-gradient(135deg,#ec4899,#f472b6)}
.c6{background:linear-gradient(135deg,#64748b,#94a3b8)}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);padding:18px;margin-bottom:18px}
.card h2{margin:0 0 14px;font-size:15px;display:flex;align-items:center;gap:9px}
.card h2 .sp{flex:1}
.grid2{display:grid;grid-template-columns:1.4fr 1fr;gap:18px;align-items:start}
.field{margin-bottom:12px}
.field label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px}
.field input,.field select{width:100%;padding:11px 13px;border-radius:12px;border:1px solid var(--border);background:var(--surface);color:var(--text);outline:none;font-size:13px}
.field input:focus,.field select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--weak)}
.field .hint{color:var(--muted);font-size:11px;margin-top:4px}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.alert{padding:10px 14px;border-radius:12px;margin-bottom:16px;font-weight:600;font-size:13px}
.alert.err{background:#fee2e2;color:#991b1b}
.alert.ok{background:#d1fae5;color:#065f46}
@media(prefers-color-scheme:dark){.alert.err{background:#4c0519;color:#fecdd3}.alert.ok{background:#022c22;color:#a7f3d0}}
.sch{position:relative;margin-bottom:14px}
.sch .ic{position:absolute;top:50%;inset-inline-start:12px;transform:translateY(-50%);color:var(--muted)}
.sch input{width:100%;padding:10px 40px;border-radius:12px;border:1px solid var(--border);background:var(--surface);outline:none}
.sch input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--weak)}
.scrl{overflow:auto;border:1px solid var(--border);border-radius:12px}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th,.tbl td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:start;vertical-align:middle;white-space:nowrap}
.tbl th{color:var(--muted);font-size:11px;font-weight:700;background:var(--surface2)}
.tbl tbody tr:hover td{background:var(--surface2)}
.tbl tr:last-child td{border-bottom:0}
.pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;background:var(--surface2);color:var(--muted);font-size:11px;font-weight:700}
.pill.ok{background:#0596691f;color:#04724f}
.pill.bad{background:#e11d481f;color:#be123c}
.pill.admin{background:#4f46e51f;color:var(--primary)}
.pill button,.pill.pick{border:0;background:transparent;cursor:pointer;font:inherit;color:inherit;padding:0}
.right{text-align:end}
.muted{color:var(--muted)}
.ltr{direction:ltr;text-align:left}
.nm{font-weight:700;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bar{height:6px;background:var(--surface2);border-radius:4px;overflow:hidden;min-width:90px}
.bar i{display:block;height:100%;background:linear-gradient(90deg,var(--primary),var(--primary2))}
.pgn{display:flex;gap:6px;justify-content:center;align-items:center;padding:16px 0 0}
.pgn a,.pgn span{padding:7px 12px;border-radius:10px;border:1px solid var(--border);background:var(--surface);font-size:12px;font-weight:700}
.pgn .on{background:var(--primary);border-color:var(--primary);color:#fff}
.kv{display:flex;justify-content:space-between;gap:12px;padding:8px 2px;border-bottom:1px dashed var(--border);font-size:13px}
.kv:last-child{border:0}
.kv span{color:var(--muted)}
.empty{text-align:center;padding:44px 16px;color:var(--muted);background:var(--surface);border:2px dashed var(--border);border-radius:var(--r)}
.av{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;display:grid;place-items:center;font-weight:800;flex:none;font-size:13px;overflow:hidden}
.av img{width:100%;height:100%;object-fit:cover;display:block}
.pcard{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.pcard .av-lg{width:74px;height:74px;border-radius:24px;font-size:26px}
.pcard .pv-pills{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
.pcard .pv-meta{color:var(--muted);font-size:12px;margin-top:4px}
.prg{display:flex;align-items:center;gap:10px}
.prg .bar{flex:1}
.chips{display:flex;gap:6px;flex-wrap:wrap}
.chip{padding:6px 13px;border-radius:999px;border:1px solid var(--border);background:var(--surface);cursor:pointer;font-size:13px;font-weight:600;color:var(--muted)}
.chip.on{background:var(--primary);border-color:var(--primary);color:#fff}
/* ---------- موبایل/ریسپانسیو ---------- */
@media(max-width:980px){
  /* سایدبار به صورت کشو از سمت راست (شروع در RTL) بیرون می‌آید */
  .sb{position:fixed;inset-inline-start:0;top:0;bottom:0;height:auto;transform:translateX(101%);transition:transform .25s ease;z-index:50;box-shadow:0 20px 60px #0006}
  html.sb-open .sb{transform:none}
  html.sb-open .scrim{display:block}
  .tp-in{padding:12px 14px}
  .ct{padding:16px 14px 48px}
  .grid2{grid-template-columns:1fr}
  .row2,.row3{grid-template-columns:1fr}
  .cards{grid-template-columns:repeat(auto-fit,minmax(160px,1fr))}
}
@media(max-width:640px){
  .tp h1{font-size:15px}
  .tp-in{gap:8px}
  .tx{display:none}       /* برچسب دکمه‌های نوار بالا فقط آیکن در موبایل */
  .scrl{white-space:nowrap}
}
/* دسکتاپ: جمع‌کردن سایدبار با دکمه همبرگر */
@media(min-width:981px){
  .sb{transition:transform .25s ease}
  html.sb-away .sb{transform:translateX(101%);position:fixed;top:0;bottom:0;inset-inline-start:0;z-index:50}
}

CSS;
}

/* ---------- پوسته: سایدبار + نوار بالا ---------- */
function adm_header(string $title, string $active = ''): void {
    $me = auth_user();
    $nav = [
        'index'    => ['داشبورد', 'grid'],
        'users'    => ['کاربران', 'users'],
        'files'    => ['فایل‌ها', 'folder'],
        'logs'     => ['گزارش فعالیت', 'activity'],
        'settings' => ['تنظیمات', 'gear'],
    ];
    $site = (string)db_get('site_name', APP_TITLE);
    $initial = (string)$me['name'];
    $initial = function_exists('mb_substr') ? mb_substr($initial, 0, 1) : substr($initial, 0, 1);
    ?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= h($title) ?> — مدیریت <?= h($site) ?></title><style><?= adm_css() ?></style>
<script>try{if(localStorage.getItem('adm_sb')==='away')document.documentElement.classList.add('sb-away')}catch(e){}</script></head><body>
<?php adm_sprite(); ?>
<div class="adm">
  <aside class="sb">
    <div class="sb-logo"><i><?= ic('shield') ?></i><div><b>پنل مدیریت</b><small><?= h($site) ?></small></div></div>
    <div class="sb-cap">مدیریت</div>
    <?php foreach ($nav as $k => [$label, $icon]): ?>
      <a href="<?= $k === 'index' ? 'index.php' : $k . '.php' ?>" class="<?= $active === $k ? 'on' : '' ?>"><?= ic($icon) ?><?= h($label) ?></a>
    <?php endforeach; ?>
    <div class="sb-cap">میانبرها</div>
    <a href="../"><?= ic('cloud') ?>پنل فایل‌ها</a>
    <a href="../?page=settings"><?= ic('edit') ?>تنظیمات من</a>
    <div class="sb-foot">
      <div class="sb-user">
        <span class="av"><?= h($initial !== '' ? $initial : '?') ?></span>
        <div style="min-width:0"><b><?= h($me['name']) ?></b><small>مدیر سامانه</small></div>
      </div>
      <a href="logout.php" class="sb-out"><?= ic('logout') ?>خروج از حساب</a>
    </div>
  </aside>
  <div class="scrim" onclick="document.documentElement.classList.remove('sb-open')"></div>
  <div class="main">
    <header class="tp"><div class="tp-in">
      <button class="ib burger" type="button" aria-label="نمایش/جمع کردن منو" title="نمایش/جمع کردن منو"><?= ic('menu') ?></button>
      <h1><?= h($title) ?></h1>
      <span class="sp"></span>
      <a class="btn" href="../"><?= ic('cloud') ?><span class="tx">پنل فایل‌ها</span></a>
      <a class="btn" href="logout.php"><?= ic('logout') ?><span class="tx">خروج</span></a>
    </div></header>
    <main class="ct">
    <?php
    $f = adm_take_flash();
    if ($f) echo '<div class="alert ' . ($f[1] === 'ok' ? 'ok' : 'err') . '">' . h($f[0]) . '</div>';
}

function adm_footer(): void {
    ?></main></div></div>
<script>
(function(){
  var doc = document.documentElement;
  var mq = window.matchMedia('(max-width:980px)');
  var b = document.querySelector('.burger');
  function upd(){ if(b){ var a = doc.classList.contains('sb-away'), o = doc.classList.contains('sb-open'); b.title = (a || !o) ? 'نمایش منو' : 'جمع کردن منو'; } }
  if (b) b.onclick = function(){
    if (mq.matches) { doc.classList.toggle('sb-open'); }
    else { doc.classList.toggle('sb-away'); try{ localStorage.setItem('adm_sb', doc.classList.contains('sb-away') ? 'away' : 'open'); }catch(e){} }
    upd();
  };
  window.addEventListener('keydown', function(e){ if (e.key === 'Escape') doc.classList.remove('sb-open'); });
  if (mq.addEventListener) mq.addEventListener('change', function(){ doc.classList.remove('sb-open'); });
  upd();
})();
</script>
</body></html><?php
}
