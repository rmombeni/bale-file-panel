<?php
/**
 * نصاب «پنل فایل بله» — install.php
 * ─────────────────────────────────
 * یک‌بار اجرا شود؛ پس از نصب موفق، فایل قفل (storage/install.lock) ساخته می‌شود
 * و اجرای مجدد نصاب دیگر ممکن نیست. برای نصب مجدد، فایل قفل را حذف کنید.
 *
 * مراحل: ۱) بررسی پیش‌نیازها  ۲) اتصال و ساخت دیتابیس  ۳) حساب مدیر  ۴) پایان
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

define('IS_INSTALLER', true);
$ROOT = __DIR__;
$LOCK = $ROOT . '/storage/install.lock';

mb_internal_encoding('UTF-8');
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function post(string $k, string $d = ''): string { return trim((string)($_POST[$k] ?? $d)); }
function e_die(string $m) { http_response_code(500); exit('<pre dir="ltr">' . h($m) . '</pre>'); }

set_exception_handler(function (Throwable $e) use ($ROOT) {
    @file_put_contents($ROOT . '/storage/install-error.log', date('c') . '  ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL, FILE_APPEND);
    e_die('خطای نصب: ' . $e->getMessage());
});

/* ---------- خواندن مقادیر فعلی config.php (بدون اجرا) ---------- */
function cfg_read(string $key, string $default): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $txt = @file_get_contents(__DIR__ . '/config.php');
        if ($txt !== false && preg_match_all("/const\s+([A-Z0-9_]+)\s*=\s*(?:'((?:[^'\\\\]|\\\\.)*)'|(\d+))/u", $txt, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $cache[$x[1]] = isset($x[3]) ? $x[3] : stripcslashes($x[2]);
        }
    }
    return $cache[$key] ?? $default;
}

/* ---------- قالب config.php ---------- */
function cfg_render(array $v): string {
    $q = fn(string $s): string => "'" . addslashes($s) . "'";
    return <<<PHP
<?php
/**
 * تنظیمات سراسری برنامه — به‌صورت خودکار توسط install.php ساخته شد.
 * مقادیر بله در اینجا «پیش‌فرض» هستند؛ هر کاربر می‌تواند در تنظیمات خودش
 * توکن و شناسهٔ چت جداگانه بگذارد و آن مقدار بر این پیش‌فرض مقدم می‌شود.
 */

date_default_timezone_set('Asia/Tehran');

/* ---------- دیتابیس ---------- */
const DB_HOST = {$q($v['DB_HOST'])};
const DB_PORT = {$v['DB_PORT']};
const DB_NAME = {$q($v['DB_NAME'])};
const DB_USER = {$q($v['DB_USER'])};
const DB_PASS = {$q($v['DB_PASS'])};

/* ---------- برنامه ---------- */
const APP_TITLE   = {$q($v['APP_TITLE'])};
const APP_VERSION = '2.4';
const DATA_FILE   = __DIR__ . '/data.php';       // فقط برای مهاجرت اولیه
const STORAGE_DIR = __DIR__ . '/storage';

/* ---------- پیش‌فرض بله (قابل تغییر از تنظیمات مدیر) ---------- */
const BOT_TOKEN_DEFAULT = {$q($v['BOT_TOKEN_DEFAULT'])};
const CHAT_ID_DEFAULT   = {$q($v['CHAT_ID_DEFAULT'])};
const SEND_TO_CHAT_DEFAULT = '';

/* ---------- رمز حساب مدیر اولیه (فقط بار اول؛ نصاب رمز واقعی را در دیتابیس ذخیره می‌کند) ---------- */
const ADMIN_PASSWORD_SEED = 'change-me';

/* ---------- محدودیت‌ها ---------- */
const MAX_UP    = 50 * 1024 * 1024;
const MAX_DL    = 20 * 1024 * 1024;
const MAX_CHUNK = 15 * 1024 * 1024;
const MAX_LOGICAL_UPLOAD = 5 * 1024 * 1024 * 1024;
const THUMB_MAX = 3 * 1024 * 1024;

/* ---------- امنیت ---------- */
const MAX_LOGIN_FAILS = 8;          // تعداد تلاش ناموفق پیش از قفل کوتاه
const LOGIN_WINDOW    = 900;        // پنجرهٔ شمارش (ثانیه)
const REMEMBER_DAYS   = 30;

PHP;
}

/* ---------- قفل نصب ---------- */
if (is_file($LOCK)) {
    $when = @file_get_contents($LOCK) ?: '';
    ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نصب انجام شده است</title><style><?= install_css() ?></style></head><body>
<div class="center"><div class="card ok-state">
  <div class="ic-big">✓</div>
  <h1>سامانه از قبل نصب شده است</h1>
  <p><?= $when !== '' ? 'زمان نصب: ' . h($when) : '' ?></p>
  <a class="btn p" href="./?page=login">ورود به پنل</a>
  <div class="hint">برای نصب مجدد، فایل <code dir="ltr">storage/install.lock</code> را حذف کنید.</div>
</div></div></body></html><?php
    exit;
}

/* ---------- فونت و استایل ---------- */
function install_css(): string {
    $f = @file_exists(__DIR__ . '/lib/fonts.php');
    $font = '';
    if ($f) { require_once __DIR__ . '/lib/fonts.php'; $font = vazir_font_css('fonts/'); }
    return $font . <<<'CSS'
:root{--bg:#f3f5fb;--surface:#fff;--surface2:#f1f3fa;--text:#0b1526;--muted:#64748b;--border:#e6e9f2;--primary:#4f46e5;--danger:#e11d48;--ok:#0a9d6c;--weak:#eef0ff}
@media(prefers-color-scheme:dark){:root{--bg:#070b17;--surface:#0e1526;--surface2:#16203a;--text:#e9edf8;--muted:#8b97b8;--border:#1e2a4c;--primary:#8b93ff;--danger:#fb7185;--ok:#34d399;--weak:#1a2148}}
*{box-sizing:border-box}body{margin:0;font-family:'Vazirmatn FD',Vazirmatn,Tahoma,system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;line-height:1.8}
a{color:var(--primary)}
.center{min-height:100vh;display:grid;place-items:center;padding:20px}
.card{width:100%;max-width:560px;background:var(--surface);border:1px solid var(--border);border-radius:22px;box-shadow:0 10px 40px #0f172a1c;padding:28px}
.logo{display:flex;align-items:center;gap:10px;font-weight:800;font-size:16px;margin-bottom:6px}
.logo i{display:grid;place-items:center;width:40px;height:40px;border-radius:13px;background:linear-gradient(135deg,#4f46e5,#7c6cf6);color:#fff;font-style:normal}
.steps{display:flex;gap:6px;margin:14px 0 20px}
.steps span{flex:1;height:5px;border-radius:99px;background:var(--surface2)}
.steps span.on{background:linear-gradient(135deg,#4f46e5,#7c6cf6)}
h1{font-size:19px;margin:6px 0 4px}
p{margin:0 0 14px;color:var(--muted);font-size:13px}
.field{margin-bottom:12px}
.field label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin-bottom:5px}
.field input{width:100%;padding:11px 13px;border-radius:12px;border:1px solid var(--border);background:var(--surface);color:var(--text);outline:none;font:inherit;font-size:13px}
.field input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--weak)}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:520px){.row2{grid-template-columns:1fr}}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:12px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;font:inherit;font-weight:700;font-size:13.5px}
.btn.p{background:linear-gradient(135deg,#4f46e5,#7c6cf6);color:#fff;border-color:transparent}
.btn:hover{transform:translateY(-1px)}
.req{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px}
.req td{padding:8px 6px;border-bottom:1px solid var(--border)}
.req td:last-child{text-align:left}
.yes{color:var(--ok);font-weight:800}.no{color:var(--danger);font-weight:800}
.alert{padding:11px 14px;border-radius:12px;margin-bottom:14px;font-weight:600;font-size:13px}
.alert.err{background:#e11d4815;color:var(--danger);border:1px solid #e11d4835}
.alert.ok{background:#0a9d6c15;color:var(--ok);border:1px solid #0a9d6c35}
.hint{color:var(--muted);font-size:11.5px;margin-top:10px}
.ic-big{font-size:42px;color:var(--ok);font-weight:800}
code{background:var(--surface2);padding:1px 7px;border-radius:7px;font-size:12px}
.ltr{direction:ltr;text-align:left}
CSS;
}

$step = (string)($_GET['step'] ?? 'check');
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>نصب — پنل فایل بله</title><style><?= install_css() ?></style></head><body>
<div class="center"><div class="card">
  <div class="logo"><i>📦</i><div><b>نصب «پنل فایل بله»</b><div style="font-size:11px;color:var(--muted);font-weight:400">ذخیره‌سازی فایل‌ها روی سرورهای بله</div></div></div>
  <div class="steps"><span class="<?= in_array($step, ['check'], true) ? 'on' : '' ?>"></span><span class="<?= in_array($step, ['db', 'dbok'], true) ? 'on' : '' ?>"></span><span class="<?= in_array($step, ['admin'], true) ? 'on' : '' ?>"></span><span class="<?= $step === 'done' ? 'on' : '' ?>"></span></div>
<?php /* ============================================================ گام ۱: پیش‌نیازها ============================================================ */
if ($step === 'check'):
    $phpOk = PHP_VERSION_ID >= 80000;
    $exts = [
        'pdo_mysql' => ['پیش‌نیاز', true],
        'mbstring'  => ['پیش‌نیاز', true],
        'curl'      => ['پیش‌نیاز (اتصال به بله)', true],
        'fileinfo'  => ['پیش‌نیاز', true],
        'json'      => ['پیش‌نیاز', true],
        'intl'      => ['اختیاری (تاریخ شمسی دقیق‌تر)', false],
        'gd'        => ['اختیاری (تصاویر)', false],
    ];
    if (!is_dir($ROOT . '/storage')) @mkdir($ROOT . '/storage', 0770, true);
    $storageW = is_writable($ROOT . '/storage');
    $cfgW = !is_file($ROOT . '/config.php') || is_writable($ROOT . '/config.php');
    $missingReq = [];
    foreach ($exts as $ex => [$label, $req]) { if ($req && !extension_loaded($ex)) $missingReq[] = $ex; }
    $hardFail = !$phpOk || !$storageW || !$cfgW || $missingReq;
?>
  <h1>بررسی پیش‌نیازها</h1>
  <p>مطمئن شوید همهٔ موارد ضروری سبز باشند؛ سپس ادامه دهید.</p>
  <?php if ($hardFail): ?><div class="alert err">موارد ضروری کامل نیست؛ پس از رفع، این صفحه را دوباره باز کنید.</div><?php endif; ?>
  <table class="req">
    <tr><td>نسخهٔ PHP <span class="ltr">(حداقل 8.0)</span></td><td class="<?= $phpOk ? 'yes' : 'no' ?>"><?= $phpOk ? 'دارد — ' . PHP_VERSION : 'ندارد — ' . PHP_VERSION ?></td></tr>
    <?php foreach ($exts as $ex => [$label, $req]): $ok = extension_loaded($ex); ?>
      <tr><td>افزونهٔ <code dir="ltr"><?= h($ex) ?></code> <small style="color:var(--muted)"><?= h($label) ?></small></td><td class="<?= $ok || !$req ? ($ok ? 'yes' : '') : 'no' ?>"><?= $ok ? 'دارد' : ($req ? 'ندارد' : 'نصب نیست (اختیاری)') ?></td></tr>
    <?php endforeach; ?>
    <tr><td>قابل‌نوشتن: پوشهٔ <code dir="ltr">storage</code></td><td class="<?= $storageW ? 'yes' : 'no' ?>"><?= $storageW ? 'دارد' : 'ندارد' ?></td></tr>
    <tr><td>قابل‌نوشتن: <code dir="ltr">config.php</code></td><td class="<?= $cfgW ? 'yes' : 'no' ?>"><?= $cfgW ? 'دارد' : 'ندارد' ?></td></tr>
  </table>
  <?php if (!$hardFail): ?>
    <a class="btn p" href="?step=db">ادامه ← اتصال دیتابیس</a>
  <?php endif; ?>

<?php /* ============================================================ گام ۲: دیتابیس و پیکربندی ============================================================ */
elseif ($step === 'db'):
    $err = '';
    $f = [
        'DB_HOST' => post('DB_HOST', cfg_read('DB_HOST', '127.0.0.1')),
        'DB_PORT' => post('DB_PORT', cfg_read('DB_PORT', '3306')),
        'DB_NAME' => post('DB_NAME', cfg_read('DB_NAME', 'bale_panel')),
        'DB_USER' => post('DB_USER', cfg_read('DB_USER', 'root')),
        'DB_PASS' => (string)($_POST['DB_PASS'] ?? ''),
        'APP_TITLE' => post('APP_TITLE', cfg_read('APP_TITLE', 'پنل فایل')),
        'BOT_TOKEN_DEFAULT' => post('BOT_TOKEN_DEFAULT', cfg_read('BOT_TOKEN_DEFAULT', '')),
        'CHAT_ID_DEFAULT' => post('CHAT_ID_DEFAULT', cfg_read('CHAT_ID_DEFAULT', '')),
    ];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'):
        try {
            if ($f['DB_HOST'] === '' || $f['DB_NAME'] === '' || $f['DB_USER'] === '') throw new RuntimeException('میزبان، نام دیتابیس و نام کاربری الزامی است.');
            if (!preg_match('/^\d+$/', $f['DB_PORT'])) throw new RuntimeException('پورت باید عدد باشد.');
            if ($f['BOT_TOKEN_DEFAULT'] !== '' && !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $f['BOT_TOKEN_DEFAULT'])) throw new RuntimeException('قالب توکن ربات پیش‌فرض درست نیست (اختیاری است؛ می‌توانید خالی بگذارید).');

            $pdo = new PDO('mysql:host=' . $f['DB_HOST'] . ';port=' . (int)$f['DB_PORT'] . ';charset=utf8mb4', $f['DB_USER'], $f['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
            $safeName = str_replace('`', '', $f['DB_NAME']);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $safeName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            new PDO('mysql:host=' . $f['DB_HOST'] . ';port=' . (int)$f['DB_PORT'] . ';dbname=' . $safeName . ';charset=utf8mb4', $f['DB_USER'], $f['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $old = $ROOT . '/config.php';
            if (is_file($old)) @copy($old, $old . '.bak');
            $ok = @file_put_contents($old, cfg_render($f));
            if ($ok === false) throw new RuntimeException('نوشتن config.php ممکن نشد؛ سطح دسترسی فایل را بررسی کنید.');
            header('Location: ?step=admin'); exit;
        } catch (Throwable $e) {
            $err = 'خطا: ' . $e->getMessage();
        }
    endif;
?>
  <h1>اتصال دیتابیس و پیکربندی</h1>
  <p>دیتابیس در صورت نبود ساخته می‌شود و <code dir="ltr">config.php</code> به‌روزرسانی خواهد شد (نسخهٔ قبلی با پسوند <code dir="ltr">.bak</code> ذخیره می‌شود).</p>
  <?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
  <form method="post" action="?step=db" autocomplete="off">
    <div class="row2">
      <div class="field"><label>میزبان دیتابیس</label><input name="DB_HOST" value="<?= h($f['DB_HOST']) ?>" dir="ltr" style="text-align:left" required></div>
      <div class="field"><label>پورت</label><input name="DB_PORT" value="<?= h($f['DB_PORT']) ?>" dir="ltr" style="text-align:left" inputmode="numeric" required></div>
    </div>
    <div class="row2">
      <div class="field"><label>نام دیتابیس</label><input name="DB_NAME" value="<?= h($f['DB_NAME']) ?>" dir="ltr" style="text-align:left" required></div>
      <div class="field"><label>نام کاربری دیتابیس</label><input name="DB_USER" value="<?= h($f['DB_USER']) ?>" dir="ltr" style="text-align:left" required></div>
    </div>
    <div class="field"><label>رمز دیتابیس</label><input name="DB_PASS" type="password" value="<?= h($f['DB_PASS']) ?>" dir="ltr" style="text-align:left" placeholder="معمولاً خالی در XAMPP"></div>
    <div class="field"><label>عنوان سامانه</label><input name="APP_TITLE" value="<?= h($f['APP_TITLE']) ?>" required></div>
    <div class="field"><label>توکن ربات پیش‌فرض بله (اختیاری)</label><input name="BOT_TOKEN_DEFAULT" value="<?= h($f['BOT_TOKEN_DEFAULT']) ?>" dir="ltr" style="text-align:left" placeholder="۱۲۳۴۵۶:ABC… — بعداً هم از تنظیمات مدیر قابل تغییر است"></div>
    <div class="field"><label>شناسهٔ چت پیش‌فرض (اختیاری)</label><input name="CHAT_ID_DEFAULT" value="<?= h($f['CHAT_ID_DEFAULT']) ?>" dir="ltr" style="text-align:left" placeholder="شناسهٔ عددی چت/کانال ذخیرهٔ فایل‌ها"></div>
    <button class="btn p">تست و ذخیرهٔ اتصال ←</button>
  </form>

<?php /* ============================================================ گام ۳: حساب مدیر ============================================================ */
elseif ($step === 'admin'):
    $err = '';
    $f = ['auser' => 'admin', 'aname' => 'مدیر سامانه', 'apass' => '', 'apass2' => ''];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'):
        try {
            $f['auser'] = post('auser', 'admin');
            $f['aname'] = post('aname', 'مدیر سامانه');
            $f['apass'] = (string)($_POST['apass'] ?? '');
            $f['apass2'] = (string)($_POST['apass2'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_\.]{3,32}$/', $f['auser'])) throw new RuntimeException('نام کاربری مدیر: ۳ تا ۳۲ نویسه لاتین.');
            if (mb_strlen($f['aname']) < 2) throw new RuntimeException('نام نمایشی حداقل ۲ نویسه است.');
            if (strlen($f['apass']) < 8) throw new RuntimeException('رمز مدیر حداقل ۸ نویسه است.');
            if ($f['apass'] !== $f['apass2']) throw new RuntimeException('رمز و تکرار آن یکسان نیستند.');

            require_once $ROOT . '/lib/db.php';          // config.php تازه را بار می‌کند
            if (!db_installed()) { db_install(); @file_put_contents(STORAGE_DIR . '/.installed', date('c')); }
            else { db_upgrade(); }

            // ساخت/به‌روزرسانی حساب مدیر با اطلاعات انتخابی نصاب
            $st = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $st->execute([$f['auser']]);
            $row = $st->fetch();
            if ($row) {
                db()->prepare('UPDATE users SET name = ?, password_hash = ?, role = "admin", is_active = 1 WHERE id = ?')
                    ->execute([$f['aname'], password_hash($f['apass'], PASSWORD_DEFAULT), (int)$row['id']]);
            } else {
                // اگر «admin» از قبل هست ولی نام کاربری دیگری انتخاب شده، همان «admin» ارتقا می‌یابد
                $st->execute(['admin']);
                $row = $st->fetch();
                if ($row) {
                    db()->prepare('UPDATE users SET username = ?, name = ?, password_hash = ?, role = "admin", is_active = 1 WHERE id = ?')
                        ->execute([$f['auser'], $f['aname'], password_hash($f['apass'], PASSWORD_DEFAULT), (int)$row['id']]);
                } else {
                    db()->prepare('INSERT INTO users (name, username, password_hash, role, is_active, use_default_bot, created_at) VALUES (?,?,?,?,1,1,?)')
                        ->execute([$f['aname'], $f['auser'], password_hash($f['apass'], PASSWORD_DEFAULT), 'admin', date('Y-m-d H:i:s')]);
                }
            }
            @file_put_contents($LOCK, date('Y-m-d H:i:s'));
            header('Location: ?step=done'); exit;
        } catch (Throwable $e) {
            $err = 'خطا: ' . $e->getMessage();
        }
    endif;
?>
  <h1>حساب مدیر</h1>
  <p>این حساب کامل‌ترین دسترسی را دارد؛ رمز قوی انتخاب کنید.</p>
  <?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
  <form method="post" action="?step=admin" autocomplete="off">
    <div class="row2">
      <div class="field"><label>نام کاربری مدیر</label><input name="auser" value="<?= h($f['auser']) ?>" dir="ltr" style="text-align:left" required></div>
      <div class="field"><label>نام نمایشی</label><input name="aname" value="<?= h($f['aname']) ?>" required></div>
    </div>
    <div class="row2">
      <div class="field"><label>رمز عبور (حداقل ۸ نویسه)</label><input name="apass" type="password" dir="ltr" style="text-align:left" required minlength="8"></div>
      <div class="field"><label>تکرار رمز</label><input name="apass2" type="password" dir="ltr" style="text-align:left" required minlength="8"></div>
    </div>
    <button class="btn p">نصب نهایی و قفل نصاب ←</button>
  </form>

<?php /* ============================================================ گام ۴: پایان ============================================================ */
elseif ($step === 'done'):
    if (!is_file($LOCK)) { header('Location: ?step=check'); exit; }
?>
  <div class="ic-big">✓</div>
  <h1>نصب با موفقیت کامل شد</h1>
  <p>سامانه آماده بهره‌برداری است. نصاب قفل شده و اجرای مجدد آن ممکن نیست.</p>
  <div class="alert ok">
    توکن و شناسهٔ چت هر کاربر از «حساب کاربری ← تب ربات بله» تنظیم می‌شود؛ توکن پیش‌فرض سامانه هم از پنل مدیریت قابل تغییر است.
  </div>
  <a class="btn p" href="./?page=login">ورود به پنل</a>
  <div class="hint">پیشنهاد امنیتی: برای اطمینان بیشتر، فایل <code dir="ltr">install.php</code> را از سرور حذف کنید.</div>

<?php else:
    header('Location: ?step=check'); exit;
endif; ?>
</div></div></body></html>
