<?php
/**
 * تنظیمات سراسری — عمومی/برند، ربات پیش‌فرض بله با تست اتصال، امنیت ورود،
 * نگه‌داری دیتابیس و اطلاعات محیط اجرا.
 */
require_once __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $back = 'settings.php';
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        adm_flash('درخواست نامعتبر بود.', 'err');
    } else {
        $do = (string)($_POST['do'] ?? '');

        /* ---------- ذخیرهٔ تنظیمات (هر فرم با نشانگر خودش) ---------- */
        if ($do === 'global' && !empty($_POST['sec']) && $_POST['sec'] === 'general') {
            $site = trim((string)($_POST['site_name'] ?? ''));
            if ($site !== '') db_set('site_name', $site);
            db_set('allow_signup', !empty($_POST['allow_signup']) ? '1' : '0');
            db_set('allow_share', !empty($_POST['allow_share']) ? '1' : '0');
            db_set('maintenance', !empty($_POST['maintenance']) ? '1' : '0');
            $qmb = max(0, (int)($_POST['default_quota_mb'] ?? 0));
            db_set('default_quota', (string)($qmb * 1048576));
            auth_log('global_settings', 'general');
            adm_flash('تنظیمات عمومی ذخیره شد.');
        }

        if ($do === 'global' && !empty($_POST['sec']) && $_POST['sec'] === 'bot') {
            $token  = trim((string)($_POST['bot_token'] ?? ''));
            $chat   = trim((string)($_POST['chat_id'] ?? ''));
            $send   = trim((string)($_POST['send_chat_id'] ?? ''));
            $keepT  = !empty($_POST['keep_bot_token']);
            $clearT = !empty($_POST['clear_bot_token']);
            if (!$keepT && !$clearT && $token !== '' && !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)) {
                adm_flash('قالب توکن ربات درست نیست (شبیه ۱۲۳۴۵۶:ABC…).', 'err');
            } else {
                if ($clearT)      db_set('bot_token', '');
                elseif (!$keepT && $token !== '') db_set('bot_token', $token);
                if ($chat !== '') db_set('chat_id', $chat);
                db_set('send_chat_id', $send);
                auth_log('global_settings', 'bot');
                adm_flash('تنظیمات ربات پیش‌فرض ذخیره شد.');
            }
        }

        if ($do === 'global' && !empty($_POST['sec']) && $_POST['sec'] === 'security') {
            $fails = max(1, (int)($_POST['max_login_fails'] ?? MAX_LOGIN_FAILS));
            $win   = max(1, (int)($_POST['login_window_min'] ?? round(LOGIN_WINDOW / 60)));
            $rem   = max(1, (int)($_POST['remember_days'] ?? REMEMBER_DAYS));
            db_set('max_login_fails', (string)$fails);
            db_set('login_window_min', (string)$win);
            db_set('remember_days', (string)$rem);
            auth_log('global_settings', 'security');
            adm_flash('تنظیمات امنیتی ذخیره شد.');
        }

        if ($do === 'global' && !empty($_POST['sec']) && $_POST['sec'] === 'safir') {
            $key    = trim((string)($_POST['safir_key'] ?? ''));
            $bid    = trim((string)($_POST['safir_bot_id'] ?? ''));
            $ttl    = (int)($_POST['otp_ttl'] ?? 300);
            $keepK  = !empty($_POST['keep_safir_key']);
            $clearK = !empty($_POST['clear_safir_key']);
            db_set('otp_login', !empty($_POST['otp_login']) ? '1' : '0');
            if ($clearK)      db_set('safir_key', '');
            elseif (!$keepK && $key !== '') db_set('safir_key', $key);
            db_set('safir_bot_id', $bid === '' ? '' : (string)max(1, (int)$bid));
            db_set('otp_ttl', (string)max(120, $ttl));
            if ($key !== '' && $bid !== '' && !empty($_POST['otp_login'])) db_set('otp_login', '1');
            else db_set('otp_login', '0');
            auth_log('global_settings', 'safir');
            adm_flash('تنظیمات سفیر ذخیره شد.');
        }

        /* ---------- تست ارسال سفیر به یک شماره ---------- */
        if ($do === 'safir_test') {
            $phone = norm_phone((string)($_POST['safir_phone'] ?? ''));
            if ($phone === '') {
                adm_flash('شمارهٔ موبایل معتبر نیست (مثال: ۰۹۱۲۳۴۵۶۷۸۹).', 'err');
            } else {
                $code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                [$ok, $r] = safir_send_otp($phone, $code);
                if ($ok) adm_flash('کد تست ' . $code . ' به ' . pretty_phone($phone) . ' ارسال شد (فقط تست اتصال).');
                else     adm_flash('ارسال ناموفق: ' . (is_string($r) ? $r : 'خطای نامشخص'), 'err');
                auth_log('safir_test', $phone);
            }
        }

        /* ---------- تست اتصال ربات/چت (با مقادیر فرم یا ذخیره‌شده) ---------- */
        if ($do === 'bot_test') {
            $token = trim((string)($_POST['bot_token'] ?? ''));
            $chat  = trim((string)($_POST['chat_id'] ?? ''));
            if ($token === '') $token = (string)db_get('bot_token', '');
            if ($chat === '')  $chat  = (string)db_get('chat_id', '');

            if ($token === '') {
                adm_flash('توکنی برای تست وجود ندارد؛ نخست توکن را ذخیره یا در فرم وارد کنید.', 'err');
            } else {
                $r = adm_api('getMe', [], ['token' => $token]);
                if (!empty($r['ok'])) {
                    $msg = 'ربات متصل است: @' . ($r['result']['username'] ?? '?');
                    if ($chat !== '') {
                        $c = adm_api('getChat', ['chat_id' => $chat], ['token' => $token]);
                        if (!empty($c['ok'])) {
                            $title = $c['result']['title'] ?? ($c['result']['first_name'] ?? '') ?? '';
                            $msg .= ' — چت قابل دسترس: ' . ($title !== '' ? $title : $chat);
                        } else {
                            $msg .= ' — اما چت پیدا نشد: ' . ($c['description'] ?? 'نامشخص') . ' (احتمالاً ربات عضو چت نیست؛ پیام به ربات بدهید)';
                        }
                    }
                    adm_flash($msg);
                } else {
                    adm_flash('اتصال ناموفق: ' . ($r['description'] ?? 'نامشخص'), 'err');
                }
                auth_log('bot_test');
            }
        }

        /* ---------- نگه‌داری ---------- */
        if ($do === 'maint_tokens') {
            $st = db()->query('DELETE FROM remember_tokens WHERE expires_at < NOW()');
            adm_flash((int)$st->rowCount() . ' توکن «مرا به خاطر بسپار» منقضی‌شده پاک شد.');
            auth_log('maint_tokens');
        }

        if ($do === 'maint_attempts') {
            $st = db()->query('DELETE FROM login_attempts');
            adm_flash((int)$st->rowCount() . ' رکورد تلاش ورود پاک شد (قفل‌های موقت هم آزاد شدند).');
            auth_log('maint_attempts');
        }

        if ($do === 'maint_otp') {
            $st = db()->prepare('DELETE FROM otp_codes WHERE created_at < ?');
            $st->execute([date('Y-m-d H:i:s', time() - 3600)]);
            adm_flash((int)$st->rowCount() . ' کد یکبارمصرف منقضی (۱ ساعت گذشته) پاک شد.');
            auth_log('maint_otp');
        }

        if ($do === 'maint_optimize') {
            $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $t) db()->exec('OPTIMIZE TABLE `' . str_replace('`', '', (string)$t) . '`');
            adm_flash('بهینه‌سازی ' . count($tables) . ' جدول انجام شد.');
            auth_log('maint_optimize');
        }
    }
    header('Location: ' . $back);
    exit;
}

/* ---------- مقادیر فعلی ---------- */
$gToken = (string)db_get('bot_token', '');
$maskToken = $gToken !== '' ? (substr($gToken, 0, 6) . '…' . substr($gToken, -4)) : '';
$safirKey = (string)db_get('safir_key', '');
$maskSafir = $safirKey !== '' ? (substr($safirKey, 0, 6) . '…' . substr($safirKey, -4)) : '';
$otpOn = db_get('otp_login', '0') === '1';
$otpTtl = (int)db_get('otp_ttl', '300');
$defaultQuotaMb = (int)round(((int)db_get('default_quota', '0')) / 1048576);

$free = @disk_free_space(STORAGE_DIR);
$totalDisk = @disk_total_space(STORAGE_DIR);
$avSize = 0;
foreach (glob(avatar_dir() . '/*') ?: [] as $p) if (is_file($p)) $avSize += filesize($p);

$info = [
    'نسخهٔ برنامه'                => APP_VERSION . ' (اسکیمای دیتابیس: ' . (string)db_get('schema_version', '—') . ')',
    'نسخهٔ PHP'                   => PHP_VERSION,
    'نسخهٔ پایگاه‌دادهٔ MySQL'     => (string)db()->getAttribute(PDO::ATTR_SERVER_VERSION),
    'وب‌سرور'                     => (string)($_SERVER['SERVER_SOFTWARE'] ?? '—'),
    'سامانهٔ عامل'                => PHP_OS_FAMILY,
    'منطقهٔ زمانی / زمان فعلی'    => date_default_timezone_get() . ' — ' . date('H:i'),
    'سقف آپلود PHP (file/post)'   => ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size'),
    'فضای دیسک'                   => ($totalDisk ? fmt_size((int)$totalDisk) . ' کل' : '—') . ($free !== false ? ' / ' . fmt_size((int)$free) . ' آزاد' : ''),
    'حجم تصاویر پروفایل'          => fmt_size($avSize) . ' در ' . count((glob(avatar_dir() . '/*') ?: [])) . ' فایل',
    'حالت ذخیره‌سازی'             => 'API ربات بله (self-host)',
];

$otpCodes = 0; try { $otpCodes = (int)db()->query('SELECT COUNT(*) FROM otp_codes WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)')->fetchColumn(); } catch (Throwable $e) {}

adm_header('تنظیمات سراسری', 'settings');
$chipMaint = db_get('maintenance', '0') === '1';
$csrf = h(csrf_token());
$siteName = (string)db_get('site_name', APP_TITLE);
$allowSignup = db_get('allow_signup', '1') === '1';
$allowShare = db_get('allow_share', '1') === '1';
?>
<style>
:root{--snav:216px}
.st{position:relative;display:flex;gap:22px;align-items:flex-start}

/* ---------- ستون ناوبری چسبنده ---------- */
.snav{position:sticky;top:74px;width:var(--snav);flex:none;display:flex;flex-direction:column;gap:4px}
.snav .s-title{font-size:11px;font-weight:800;color:var(--muted);padding:0 10px 6px;letter-spacing:.5px}
.snav a.sn{display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:11px;font-size:13px;font-weight:600;color:var(--muted);transition:.15s}
.snav a.sn .ic{width:16px;height:16px}
.snav a.sn:hover{background:var(--surface2);color:var(--text)}
.snav a.sn.on{background:var(--weak);color:var(--primary);font-weight:800}
.snav .dot{margin-inline-start:auto;width:7px;height:7px;border-radius:50%;background:var(--border)}
.snav a[data-live="1"] .dot{background:var(--ok)}
.snav .s-search{margin-bottom:8px;position:relative}
.snav .s-search .ic{position:absolute;top:50%;transform:translateY(-50%);inset-inline-start:11px;color:var(--muted);width:15px;height:15px}
.snav .s-search input{width:100%;padding:9px 30px 9px 11px;border-radius:11px;border:1px solid var(--border);background:var(--surface);outline:none;font-size:12.5px}
.snav .s-search input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--weak)}

/* ---------- بخش‌ها ---------- */
.smain{flex:1;min-width:0}
.ssec{background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow);margin-bottom:18px;overflow:hidden;scroll-margin-top:86px}
.ssec > .shead{display:flex;align-items:center;gap:13px;padding:16px 20px 12px}
.ssec > .shead .bx{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;color:#fff;flex:none}
.ssec > .shead .bx .ic{width:20px;height:20px}
.ssec > .shead h2{margin:0;font-size:15.5px;display:flex;align-items:center;gap:8px}
.ssec > .shead .sub{color:var(--muted);font-size:12px;margin-top:1px}
.ssec > .shead .sp{flex:1}
.ssec > .sbody{padding:6px 20px 20px}
.frow{display:grid;grid-template-columns:minmax(180px,1.1fr) minmax(220px,1fr);gap:18px;padding:14px 0;border-top:1px dashed var(--border)}
.frow:first-child{border-top:0}
.frow .flbl b{display:block;font-size:13.5px;font-weight:700}
.frow .flbl small{color:var(--muted);font-size:11.5px;line-height:1.8;display:block;margin-top:3px}
.frow .fctl{display:flex;align-items:center;justify-content:stretch}
.frow .fctl input,.frow .fctl select{width:100%;padding:11px 13px;border-radius:12px;border:1px solid var(--border);background:var(--surface);color:var(--text);outline:none;font-size:13px}
.frow .fctl input:focus,.frow .fctl select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--weak)}
.frow .fctl .unit{display:flex;align-items:center;gap:0}
.frow .fctl .unit{position:relative}
.frow .fctl .unit input{padding-inline-end:44px !important}
.frow .fctl .unit em{position:absolute;inset-inline-end:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:12px;font-style:normal;pointer-events:none}
.pwf{position:relative;width:100%}
.pwf input{padding-right:104px !important;padding-left:12px !important;text-align:left}
.pwf .acts{position:absolute;top:50%;transform:translateY(-50%);right:6px;display:flex;gap:2px}
.pwf .acts button{width:29px;height:29px;border:0;background:transparent;border-radius:8px;color:var(--muted);cursor:pointer;display:grid;place-items:center;padding:0}
.pwf .acts button:hover{background:var(--surface2);color:var(--text)}
.pwf .acts button.dl:hover{background:#e11d481f;color:var(--danger)}
.pwf .acts button .ic{width:15px;height:15px}
.sw{position:relative;display:inline-flex;width:44px;height:25px;flex:none;cursor:pointer}
.sw input{position:absolute;opacity:0;width:0;height:0}
.sw i{position:absolute;inset:0;border-radius:99px;background:var(--border);transition:.2s}
.sw i::before{content:'';position:absolute;top:3px;inset-inline-start:3px;width:19px;height:19px;border-radius:50%;background:#fff;box-shadow:0 2px 6px #0004;transition:.2s}
.sw input:checked + i{background:linear-gradient(135deg,var(--primary),var(--primary2))}
.sw input:checked + i::before{transform:translateX(-19px)}
.sw:has(input:focus-visible) i{box-shadow:0 0 0 3px var(--weak)}
.seg{display:inline-flex;gap:0;border:1px solid var(--border);border-radius:12px;overflow:hidden;background:var(--surface)}
.seg label{position:relative;cursor:pointer}
.seg input{position:absolute;opacity:0}
.seg label span{display:inline-block;padding:9px 14px;font-size:12.5px;font-weight:700;color:var(--muted);border-inline-end:1px solid var(--border);transition:.15s}
.seg label:last-child span{border-inline-end:0}
.seg input:checked + span{background:var(--weak);color:var(--primary)}
.seg label:hover span{background:var(--surface2)}
.btn.save{min-width:150px;justify-content:center}
.actrow{display:flex;align-items:center;gap:13px;padding:13px 0;border-top:1px dashed var(--border)}
.actrow:first-child{border-top:0}
.actrow .bx{width:38px;height:38px;border-radius:11px;background:var(--weak);color:var(--primary);display:grid;place-items:center;flex:none}
.actrow .bx .ic{width:18px;height:18px}
.actrow .tx{flex:1;min-width:0}
.actrow .tx b{font-size:13.5px;display:block}
.actrow .tx small{color:var(--muted);font-size:11.5px}
.info-g{display:grid;grid-template-columns:1fr 1fr;gap:0 26px}
.ikv{display:flex;justify-content:space-between;gap:12px;padding:10px 2px;border-bottom:1px dashed var(--border);font-size:13px}
.ikv span{color:var(--muted)}
.ikv b{font-weight:700;text-align:end;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.statchip{display:inline-flex;align-items:center;gap:6px;padding:3px 12px;border-radius:999px;font-size:11.5px;font-weight:800}
.statchip.ok{background:#0596691f;color:#04724f}
.statchip.no{background:#64748b1f;color:#64748b}
.statchip.warn{background:#e11d481f;color:#be123c}
.statusok .ic{width:11px;height:11px}
.cpbtn{border:0;background:transparent;cursor:pointer;color:var(--muted);display:inline-grid;place-items:center;padding:4px;border-radius:8px}
.cpbtn:hover{background:var(--surface2);color:var(--primary)}
.cpbtn .ic{width:15px;height:15px}
@media(max-width:980px){
  .st{display:block}
  .snav{position:static;width:auto;flex-direction:row;flex-wrap:wrap;margin-bottom:14px}
  .snav .s-search{display:none}
  .snav a.sn{background:var(--surface);border:1px solid var(--border)}
  .frow{grid-template-columns:1fr}
  .info-g{grid-template-columns:1fr}
}
@media(prefers-color-scheme:dark){
  .statchip.ok{background:#022c22;color:#a7f3d0}
  .statchip.no{background:#1a2342;color:#8b97b8}
  .statchip.warn{background:#4c0519;color:#fecdd3}
}
</style>

<!-- ---------- راهنمای بخش‌ها + جستجو ---------- -->
<div class="st">
  <aside class="snav">
    <div class="s-search"><?= ic('search') ?><input id="sFind" placeholder="جستجو در تنظیمات…"></div>
    <div class="s-title">بخش‌های تنظیمات</div>
    <a class="sn on" href="#sec-general" data-live="1"><?= ic('gear') ?>عمومی و برند <i class="dot"></i></a>
    <a class="sn" href="#sec-safir" data-live="<?= $otpOn ? '1' : '0' ?>"><?= ic('key') ?>ورود با کد بله <i class="dot"></i></a>
    <a class="sn" href="#sec-bot" data-live="1"><?= ic('cloud') ?>ربات پیش‌فرض <i class="dot"></i></a>
    <a class="sn" href="#sec-security" data-live="1"><?= ic('shield') ?>امنیت ورود <i class="dot"></i></a>
    <a class="sn" href="#sec-maint" data-live="1"><?= ic('drive') ?>نگه‌داری <i class="dot"></i></a>
    <a class="sn" href="#sec-info" data-live="1"><?= ic('doc') ?>اطلاعات سامانه <i class="dot"></i></a>
  </aside>

  <div class="smain">

    <!-- ========== عمومی و برند ========== -->
    <section class="ssec" id="sec-general">
      <div class="shead">
        <span class="bx c2"><?= ic('gear') ?></span>
        <div><h2>عمومی و برند <?= $allowSignup ? '<span class="statchip ok statusok">'.ic('check').'ثبت‌نام باز</span>' : '<span class="statchip no">ثبت‌نام بسته</span>' ?></h2>
        <div class="sub">نام سامانه، سهمیهٔ پیش‌فرض و کلیدهای اجازهٔ دسترسی</div></div>
        <span class="sp"></span>
      </div>
      <div class="sbody"><form method="post">
        <input type="hidden" name="do" value="global"><input type="hidden" name="sec" value="general"><input type="hidden" name="csrf" value="<?= $csrf ?>">
        <div class="frow">
          <div class="flbl"><b>نام سامانه</b><small>در عنوان پنل، صفحهٔ ورود، لینک اشتراک و سایدبار مدیریت نمایش داده می‌شود.</small></div>
          <div class="fctl"><input name="site_name" value="<?= h($siteName) ?>" maxlength="60"></div>
        </div>
        <div class="frow">
          <div class="flbl"><b>سهمیهٔ پیش‌فرض هر کاربر</b><small>فضای اولیهٔ کاربران جدید — صفر به معنی نامحدود است.</small></div>
          <div class="fctl"><span class="unit"><input name="default_quota_mb" type="number" min="0" value="<?= $defaultQuotaMb ?>"><em class="ltr">MB</em></span></div>
        </div>
        <div class="frow">
          <div class="flbl"><b>اجازهٔ ثبت‌نام آزاد</b><small>در صورت بستن، فقط مدیر از «کاربر جدید» حساب می‌سازد.</small></div>
          <div class="fctl" style="justify-content:flex-end"><label class="sw"><input type="checkbox" name="allow_signup" value="1" <?= $allowSignup ? 'checked' : '' ?>><i></i></label></div>
        </div>
        <div class="frow">
          <div class="flbl"><b>لینک‌های اشتراک عمومی</b><small>در صورت قطع، همهٔ لینک‌های ؟s= بلافاصله غیرقابل‌دسترس می‌شوند (بدون حذف فایل).</small></div>
          <div class="fctl" style="justify-content:flex-end"><label class="sw"><input type="checkbox" name="allow_share" value="1" <?= $allowShare ? 'checked' : '' ?>><i></i></label></div>
        </div>
        <div class="frow">
          <div class="flbl"><b>حالت تعمیر</b><small>در تعمیر، ورود فقط برای مدیران باز است و پیام اطلاع‌رسانی در ورود/پنل نمایش داده می‌شود.</small></div>
          <div class="fctl" style="justify-content:flex-end"><label class="sw"><input type="checkbox" name="maintenance" value="1" <?= $chipMaint ? 'checked' : '' ?>><i></i></label></div>
        </div>
        <div class="frow" style="border-top:0;padding-top:4px">
          <div></div>
          <div class="fctl" style="justify-content:flex-end"><button class="btn p save"><?= ic('check') ?>ذخیرهٔ تنظیمات عمومی</button></div>
        </div>
      </form></div>
    </section>

    <!-- ========== ورود با کد بله (سفیر) ========== -->
    <section class="ssec" id="sec-safir">
      <div class="shead">
        <span class="bx c1"><?= ic('key') ?></span>
        <div><h2>ورود با کد یکبارمصرف <?= $otpOn ? '<span class="statchip ok statusok">'.ic('check').'فعال</span>' : '<span class="statchip no">غیرفعال</span>' ?></h2>
        <div class="sub">سرویس سفیر بله — کد ۶ رقمی در بازوی «رمز یکبارمصرف بله» به موبایل کاربر می‌رسد · <span class="ltr" style="font-size:11px">docs.bale.ai/safir</span></div></div>
        <span class="sp"></span>
      </div>
      <div class="sbody"><form method="post">
        <input type="hidden" name="do" value="global"><input type="hidden" name="sec" value="safir"><input type="hidden" name="csrf" value="<?= $csrf ?>">
        <div class="frow">
          <div class="flbl"><b>فعال بودن ورود با کد بله</b><small>تب «ورود با کد بله» در صفحهٔ ورود ظاهر می‌شود؛ شرط لازم: کلید سفیر و شمارهٔ موبایل کاربر.</small></div>
          <div class="fctl" style="justify-content:flex-end"><label class="sw"><input type="checkbox" name="otp_login" value="1" <?= $otpOn ? 'checked' : '' ?>><i></i></label></div>
        </div>
        <div class="frow">
          <div class="flbl"><b>کلید دسترسی (api-access-key)</b><small><?= $maskSafir !== '' ? 'فعلی: <span class="ltr">'.h($maskSafir).'</span> — چشم: نمایش کامل · مداد: تغییر · سطل: حذف' : 'از <span class="ltr">business.bale.ai</span> گرفته می‌شود؛ کلید را وارد و ذخیره کنید.' ?></small></div>
          <div class="fctl"><div class="pwf" data-keep="keep_safir_key">
            <input type="password" name="safir_key" id="sk" dir="ltr" autocomplete="off" spellcheck="false" value="<?= $safirKey !== '' ? h($safirKey) : '' ?>" <?= $safirKey !== '' ? 'readonly' : '' ?> placeholder="<?= $safirKey !== '' ? '••••••••••••' : 'کلید دسترسی سفیر' ?>">
            <span class="acts">
              <button type="button" class="ey" data-pw="sk" title="نمایش کلید"><?= ic('eye') ?></button>
              <?php if ($safirKey !== ''): ?><button type="button" class="ed" data-pw="sk" title="ویرایش کلید"><?= ic('edit') ?></button>
              <button type="button" class="dl" data-pw="sk" data-clear="clear_safir_key" title="حذف کلید"><?= ic('trash') ?></button><?php endif; ?>
            </span>
            <input type="hidden" name="keep_safir_key" value=""><input type="hidden" name="clear_safir_key" value="">
          </div></div>
        </div>
        <div class="frow">
          <div class="flbl"><b>شناسهٔ عددی بازوی سفیر (bot_id)</b><small>شناسهٔ عددی بازویی که در پیام OTP نمایش داده می‌شود.</small></div>
          <div class="fctl"><input name="safir_bot_id" type="number" min="1" value="<?= h((string)db_get('safir_bot_id', '')) ?>" dir="ltr" style="text-align:left"></div>
        </div>
        <div class="frow">
          <div class="flbl"><b>انقضای کد</b><small>پس از این مدت کد بی‌اعتبار می‌شود و باید کد جدید بگیرید.</small></div>
          <div class="fctl">
            <span class="seg">
              <label><input type="radio" name="otp_ttl" value="120" <?= $otpTtl === 120 ? 'checked' : '' ?>><span>۲ دقیقه</span></label>
              <label><input type="radio" name="otp_ttl" value="180" <?= $otpTtl === 180 ? 'checked' : '' ?>><span>۳ دقیقه</span></label>
              <label><input type="radio" name="otp_ttl" value="300" <?= $otpTtl === 300 ? 'checked' : '' ?>><span>۵ دقیقه</span></label>
              <label><input type="radio" name="otp_ttl" value="600" <?= $otpTtl === 600 ? 'checked' : '' ?>><span>۱۰ دقیقه</span></label>
            </span>
          </div>
        </div>
        <div class="frow" style="border-top:0;padding-top:0">
          <div></div>
          <div class="fctl" style="justify-content:flex-end">
            <button class="btn p save"><?= ic('check') ?>ذخیرهٔ تنظیمات</button>
          </div>
        </div>
      </form>
      <form method="post" style="margin-top:-6px">
        <input type="hidden" name="do" value="safir_test"><input type="hidden" name="csrf" value="<?= $csrf ?>">
        <div class="frow" style="border-top:0;padding-top:8px">
          <div class="flbl"><b>تست اتصال سفیر</b><small>کد آزمایشی ۶ رقمی به شمارهٔ زیر ارسال می‌شود (هیچ تغییری ذخیره نمی‌کند).</small></div>
          <div class="fctl" style="justify-content:flex-end;gap:8px">
            <input name="safir_phone" placeholder="۰۹۱۲۳۴۵۶۷۸۹" dir="ltr" style="text-align:left;max-width:220px" inputmode="tel">
            <button class="btn"><?= ic('zap') ?>تست ارسال</button>
          </div>
        </div>
      </form></div>
    </section>

    <!-- ========== ربات پیش‌فرض بله ========== -->
    <section class="ssec" id="sec-bot">
      <div class="shead">
        <span class="bx c3"><?= ic('cloud') ?></span>
        <div><h2>ربات پیش‌فرض بله</h2>
        <div class="sub">برای کاربرانی که توکن اختصاصی نداشته باشند و به دسترسی پیش‌فرض اجازه داده باشند</div></div>
        <span class="sp"></span>
      </div>
      <div class="sbody"><form method="post">
        <input type="hidden" name="do" value="global"><input type="hidden" name="sec" value="bot"><input type="hidden" name="csrf" value="<?= $csrf ?>">
        <div class="frow">
          <div class="flbl"><b>توکن ربات</b><small><?= $maskToken !== '' ? 'فعلی: <span class="ltr">'.h($maskToken).'</span> — چشم: نمایش کامل · مداد: تغییر · سطل: حذف' : 'قالب صحیح: ۱۲۳۴۵۶:ABC-DEF… — توکن را وارد و ذخیره کنید.' ?></small></div>
          <div class="fctl"><div class="pwf" data-keep="keep_bot_token">
            <input type="password" name="bot_token" id="bt" dir="ltr" autocomplete="off" spellcheck="false" value="<?= $gToken !== '' ? h($gToken) : '' ?>" <?= $gToken !== '' ? 'readonly' : '' ?> placeholder="<?= $gToken !== '' ? '••••••••••••' : '123456:ABC-DEF…' ?>">
            <span class="acts">
              <button type="button" class="ey" data-pw="bt" title="نمایش توکن"><?= ic('eye') ?></button>
              <?php if ($gToken !== ''): ?><button type="button" class="ed" data-pw="bt" title="ویرایش توکن"><?= ic('edit') ?></button>
              <button type="button" class="dl" data-pw="bt" data-clear="clear_bot_token" title="حذف توکن"><?= ic('trash') ?></button><?php endif; ?>
            </span>
            <input type="hidden" name="keep_bot_token" value=""><input type="hidden" name="clear_bot_token" value="">
          </div></div>
        </div>
        <div class="frow">
          <div class="flbl"><b>شناسهٔ چت ذخیره‌سازی</b><small>چت مقصد آپلود قطعات — ربات باید عضو چت باشد یا به آن پیام داده باشد.</small></div>
          <div class="fctl"><input name="chat_id" value="<?= h((string)db_get('chat_id', '')) ?>" dir="ltr" style="text-align:left"></div>
        </div>
        <div class="frow">
          <div class="flbl"><b>چت ارسال پیش‌فرض (اختیاری)</b><small>برای اعلان/ارسال جداگانه — خالی بمانید از چت ذخیره‌سازی استفاده می‌شود.</small></div>
          <div class="fctl"><input name="send_chat_id" value="<?= h((string)db_get('send_chat_id', '')) ?>" dir="ltr" style="text-align:left"></div>
        </div>
        <div class="frow" style="border-top:0;padding-top:4px">
          <div></div>
          <div class="fctl" style="justify-content:flex-end;gap:8px">
            <button class="btn" name="do" value="bot_test"><?= ic('zap') ?>تست اتصال</button>
            <button class="btn p save"><?= ic('check') ?>ذخیره</button>
          </div>
        </div>
      </form></div>
    </section>

    <!-- ========== امنیت ورود ========== -->
    <section class="ssec" id="sec-security">
      <div class="shead">
        <span class="bx c5"><?= ic('shield') ?></span>
        <div><h2>امنیت ورود</h2>
        <div class="sub">قفل تدریجی IP و اعتبار نشست long-lived</div></div>
        <span class="sp"></span>
      </div>
      <div class="sbody"><form method="post">
        <input type="hidden" name="do" value="global"><input type="hidden" name="sec" value="security"><input type="hidden" name="csrf" value="<?= $csrf ?>">
        <div class="frow">
          <div class="flbl"><b>حد تلاش ناموفق</b><small>پس از رسیدن به این تعداد، همان IP تا پایان پنجره قفل می‌شود.</small></div>
          <div class="fctl"><input name="max_login_fails" type="number" min="1" value="<?= (int)db_get('max_login_fails', (string)MAX_LOGIN_FAILS) ?>" dir="ltr" style="text-align:left;max-width:160px"></div>
        </div>
        <div class="frow">
          <div class="flbl"><b>پنجرهٔ قفل (دقیقه)</b><small>مدت شمارش تلاش‌های ناموفق و همچنین زمان آزادسازی قفل.</small></div>
          <div class="fctl"><input name="login_window_min" type="number" min="1" value="<?= (int)db_get('login_window_min', (string)round(LOGIN_WINDOW / 60)) ?>" dir="ltr" style="text-align:left;max-width:160px"></div>
        </div>
        <div class="frow">
          <div class="flbl"><b>اعتبار «مرا به خاطر بسپار» (روز)</b><small>دامنهٔ عمر توکن Remember-Me در مرورگر کاربر.</small></div>
          <div class="fctl"><input name="remember_days" type="number" min="1" value="<?= (int)db_get('remember_days', (string)REMEMBER_DAYS) ?>" dir="ltr" style="text-align:left;max-width:160px"></div>
        </div>
        <div class="frow" style="border-top:0;padding-top:4px">
          <div></div>
          <div class="fctl" style="justify-content:flex-end"><button class="btn p save"><?= ic('check') ?>ذخیرهٔ تنظیمات امنیتی</button></div>
        </div>
      </form></div>
    </section>

    <!-- ========== نگه‌داری دیتابیس ========== -->
    <section class="ssec" id="sec-maint">
      <div class="shead">
        <span class="bx c4"><?= ic('drive') ?></span>
        <div><h2>نگه‌داری دیتابیس</h2>
        <div class="sub">پاک‌سازی و بهینه‌سازی دوره‌ای — عملیات که ایمن است و برگشت‌پذیر</div></div>
        <span class="sp"></span>
      </div>
      <div class="sbody">
        <form method="post"><div class="actrow">
          <span class="bx"><?= ic('clock') ?></span>
          <div class="tx"><b>پاک‌سازی توکن‌های «مرا به خاطر بسپار» منقضی</b><small>توکن‌های قدیمی مرورگرهایی که دیگر وارد نمی‌شوند حذف می‌شوند.</small></div>
          <input type="hidden" name="do" value="maint_tokens"><input type="hidden" name="csrf" value="<?= $csrf ?>">
          <button class="btn sm"><?= ic('refresh') ?>اجرا</button>
        </div></form>
        <form method="post" onsubmit="return confirm('همهٔ تلاش‌های ناموفق ورود پاک شوند و قفل‌های موقت برطرف شود؟')"><div class="actrow">
          <span class="bx"><?= ic('users') ?></span>
          <div class="tx"><b>خالی کردن تلاش‌های ناموفق ورود</b><small>رفع قفل همهٔ IPها — آمار تلاش‌های ورود صفر می‌شود.</small></div>
          <input type="hidden" name="do" value="maint_attempts"><input type="hidden" name="csrf" value="<?= $csrf ?>">
          <button class="btn sm"><?= ic('refresh') ?>اجرا</button>
        </div></form>
        <form method="post"><div class="actrow">
          <span class="bx"><?= ic('key') ?></span>
          <div class="tx"><b>پاک‌سازی کدهای یکبارمصرف منقضی</b><small><?= $otpCodes ?> کد فعال در ۱ ساعت اخیر — قدیمی‌ترها حذف می‌شوند.</small></div>
          <input type="hidden" name="do" value="maint_otp"><input type="hidden" name="csrf" value="<?= $csrf ?>">
          <button class="btn sm"><?= ic('refresh') ?>اجرا</button>
        </div></form>
        <form method="post"><div class="actrow">
          <span class="bx"><?= ic('zap') ?></span>
          <div class="tx"><b>بهینه‌سازی (OPTIMIZE) همهٔ جدول‌ها</b><small>فشرده‌سازی و قفل‌گیری متادیتا — سرویس چند ثانیه کند می‌شود.</small></div>
          <input type="hidden" name="do" value="maint_optimize"><input type="hidden" name="csrf" value="<?= $csrf ?>">
          <button class="btn sm"><?= ic('zap') ?>اجرا</button>
        </div></form>
      </div>
    </section>

    <!-- ========== اطلاعات سامانه ========== -->
    <section class="ssec" id="sec-info">
      <div class="shead">
        <span class="bx c6"><?= ic('doc') ?></span>
        <div><h2>اطلاعات سامانه</h2>
        <div class="sub">نسخه‌ها، محیط اجرا و فضای ذخیره‌سازی</div></div>
        <span class="sp"></span>
      </div>
      <div class="sbody"><div class="info-g">
        <?php foreach ($info as $k => $v): ?>
          <div class="ikv"><span><?= h($k) ?></span><b class="ltr"><?= h($v) ?></b>
            <button type="button" class="cpbtn" title="کپی مقدار" onclick="admCp(this, '<?= h($v) ?>')"><?= ic('copy') ?></button>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="ikv"><span>مسیر ذخیرهٔ تصاویر پروفایل</span><b class="ltr" title="<?= h(STORAGE_DIR . '/avatars') ?>"><?= h(STORAGE_DIR . '/avatars') ?><button type="button" class="cpbtn" onclick="admCp(this, '<?= h(STORAGE_DIR . '/avatars') ?>')" style="display:inline"><?= ic('copy') ?></button></b></div>
      </div>
    </section>

</div>

<script>
/* ---------- کپی ---------- */
function admCp(b, v){
  if (navigator.clipboard) navigator.clipboard.writeText(v).then(done, fb); else fb();
  function fb(){ var i = document.createElement('input'); i.value = v; document.body.appendChild(i); i.select(); try { document.execCommand('copy'); } catch(e){} i.remove(); done(); }
  function done(){ b.innerHTML = '<svg class="ic"><use href="#i-check"/></svg>'; setTimeout(function(){ b.innerHTML = '<svg class="ic"><use href="#i-copy"/></svg>'; }, 1400); }
}

/* ---------- ناوبری فعال با اسکرول + کلیک نرم ---------- */
var sn = document.querySelectorAll('.snav a.sn'), secs = document.querySelectorAll('.ssec');
sn.forEach(function(a){
  a.onclick = function(e){
    e.preventDefault();
    var t = document.querySelector(a.getAttribute('href'));
    if (t) t.scrollIntoView({behavior:'smooth', block:'start'});
  };
});
function markActive(){
  var y = window.scrollY + 120, cur = secs[0];
  secs.forEach(function(s){ if (s.offsetTop <= y) cur = s; });
  sn.forEach(function(a){ a.classList.toggle('on', cur && a.getAttribute('href') === ('#' + cur.id)); });
}
window.addEventListener('scroll', markActive, {passive:true}); markActive();

/* ---------- جستجو در تنظیمات ---------- */
var f = document.getElementById('sFind');
if (f) f.oninput = function(){
  var q = f.value.trim().toLowerCase();
  secs.forEach(function(s){
    var hit = q === '' || s.textContent.toLowerCase().includes(q);
    s.style.display = hit ? '' : 'none';
  });
};

/* ---------- فیلدهای محرمانه: نمایش/ویرایش/حذف ---------- */
document.querySelectorAll('.pwf .ey[data-pw]').forEach(function(b){
  b.onclick = function(){
    var i = document.getElementById(b.getAttribute('data-pw'));
    if (!i) return;
    var show = i.type === 'password';
    i.type = show ? 'text' : 'password';
    b.innerHTML = '<svg class="ic"><use href="#i-' + (show ? 'eye-off' : 'eye') + '"/></svg>';
    b.title = show ? 'پنهان کردن' : 'نمایش';
  };
});
document.querySelectorAll('.pwf .ed[data-pw]').forEach(function(b){
  b.onclick = function(){
    var i = document.getElementById(b.getAttribute('data-pw'));
    if (!i) return;
    i.readOnly = false; i.value = ''; i.type = 'password';
    i.placeholder = 'مقدار جدید را وارد کنید';
    i.focus();
  };
});
document.querySelectorAll('.pwf .dl[data-pw]').forEach(function(b){
  b.onclick = function(){
    if (!confirm('این مقدار حذف شود؟')) return;
    var f = b.closest('form');
    var i = f ? f.querySelector('#' + b.getAttribute('data-pw')) : null;
    if (!f || !i) return;
    i.value = ''; i.readOnly = false;
    var c = f.querySelector('input[name="' + b.getAttribute('data-clear') + '"]');
    if (c) c.value = '1';
    f.submit();
  };
});
document.querySelectorAll('form').forEach(function(f){
  f.addEventListener('submit', function(){
    f.querySelectorAll('.pwf[data-keep]').forEach(function(w){
      var i = w.querySelector('input:not([type=hidden])');
      if (i && (i.readOnly || i.value === '')) {
        var k = f.querySelector('input[name="' + w.getAttribute('data-keep') + '"]');
        if (k) k.value = '1';
      }
    });
  });
});
</script>
<?php adm_footer();