<?php
if (!defined('APP_RUNNING')) { http_response_code(404); exit('Not found'); }

$user = auth_user(true);
$msg = null;
$msgType = 'ok';

/* ---------- تست اتصال ربات (AJAX) ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'bot_test') {
    header('Content-Type: application/json; charset=utf-8');
    $ctx = storage_ctx();
    if ($ctx['token'] === '') { echo json_encode(['ok' => false, 'msg' => 'توکن ربات تنظیم نشده است'], JSON_UNESCAPED_UNICODE); exit; }
    $me = api('getMe');
    if (empty($me['ok'])) { echo json_encode(['ok' => false, 'msg' => 'توکن نامعتبر است یا سرور به tapi.bale.ai دسترسی ندارد: ' . (string)($me['description'] ?? '')], JSON_UNESCAPED_UNICODE); exit; }
    $b = $me['result'];
    $out = ['ok' => true, 'bot' => ['username' => (string)($b['username'] ?? ''), 'id' => (string)($b['id'] ?? ''), 'name' => trim(((string)($b['first_name'] ?? '')) . ' ' . ((string)($b['last_name'] ?? '')))]];
    if ($ctx['chat'] !== '') {
        $ch = api('getChat', ['chat_id' => $ctx['chat']]);
        if (!empty($ch['ok'])) {
            $c = $ch['result'];
            $out['chat'] = ['type' => (string)($c['type'] ?? ''), 'title' => (string)($c['title'] ?? ''), 'username' => (string)($c['username'] ?? ''), 'id' => (string)($c['id'] ?? '')];
            $mid = api('getChatMember', ['chat_id' => $ctx['chat'], 'user_id' => (int)($b['id'] ?? 0)]);
            $out['member'] = !empty($mid['ok']) ? (string)($mid['result']['status'] ?? '') : '';
        } else {
            $out['chat_err'] = (string)($ch['description'] ?? 'دسترسی به چت ناموفق بود');
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $msg = 'درخواست نامعتبر بود؛ دوباره تلاش کنید.';
        $msgType = 'err';
    } else {
        $do = (string)($_POST['do'] ?? '');

        /* ---------- پروفایل: نام، نام کاربری، ایمیل، موبایل، بایو ---------- */
        if ($do === 'profile') {
            $name  = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $uname = trim((string)($_POST['username'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $bio   = trim((string)($_POST['bio'] ?? ''));

            $uerr = auth_validate_username($uname);
            $phone98 = norm_phone($phone);

            if (mb_strlen($name) < 2) {
                $msg = 'نام باید حداقل ۲ نویسه باشد.';
                $msgType = 'err';
            } elseif ($uerr !== null) {
                $msg = $uerr;
                $msgType = 'err';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $msg = 'ایمیل معتبر نیست.';
                $msgType = 'err';
            } elseif ($phone !== '' && !preg_match('/^98\d{10}$/', $phone98)) {
                $msg = 'شمارهٔ موبایل معتبر نیست؛ مثال: ۰۹۱۲۳۴۵۶۷۸۹';
                $msgType = 'err';
            } elseif (mb_strlen($bio) > 300) {
                $msg = 'دربارهٔ من حداکثر ۳۰۰ نویسه است.';
                $msgType = 'err';
            } else {
                try {
                    db()->prepare('UPDATE users SET name = ?, email = ?, username = ?, phone = ?, bio = ? WHERE id = ?')
                        ->execute([$name, $email !== '' ? $email : null, $uname, $phone98 !== '' ? $phone98 : null, $bio !== '' ? $bio : null, (int)$user['id']]);
                    auth_log('profile', $uname);
                    $_SESSION['flash'] = ['پروفایل ذخیره شد.', 'ok'];
                    header('Location: ./?page=settings');
                    exit;
                } catch (PDOException $e) {
                    $m = (string)$e->getMessage();
                    if (strpos($m, 'username') !== false) $msg = 'این نام کاربری قبلاً گرفته شده است.';
                    elseif (strpos($m, 'email') !== false) $msg = 'این ایمیل قبلاً برای حساب دیگری ثبت شده است.';
                    elseif (strpos($m, 'phone') !== false) $msg = 'این شمارهٔ موبایل قبلاً برای حساب دیگری ثبت شده است.';
                    else $msg = 'ذخیرهٔ اطلاعات ممکن نشد؛ مقدارهای یکتا را بررسی کنید.';
                    $msgType = 'err';
                }
            }
        }

        /* ---------- تصویر پروفایل ---------- */
        if ($do === 'avatar') {
            $fl = $_FILES['avatar_file'] ?? null;
            if (!$fl || $fl['error'] !== UPLOAD_ERR_OK) {
                $msg = 'انتخاب فایل اجباری است یا آپلود ناموفق بود.';
                $msgType = 'err';
            } elseif ($fl['size'] > 2 * 1048576) {
                $msg = 'حجم تصویر حداکثر ۲ مگابایت است.';
                $msgType = 'err';
            } else {
                $info = @getimagesize($fl['tmp_name']);
                $okMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if (!$info || !isset($okMimes[$info['mime']])) {
                    $msg = 'فقط تصاویر JPG، PNG، GIF یا WebP مجاز است.';
                    $msgType = 'err';
                } else {
                    if (!is_dir(av_dir())) @mkdir(av_dir(), 0770, true);
                    $fname = 'avatar-' . (int)$user['id'] . '.' . $okMimes[$info['mime']];
                    $old = (string)($user['avatar'] ?? '');
                    if (move_uploaded_file($fl['tmp_name'], av_dir() . '/' . $fname)) {
                        if ($old !== '' && $old !== $fname && is_file(av_dir() . '/' . $old)) @unlink(av_dir() . '/' . $old);
                        db()->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$fname, (int)$user['id']]);
                        auth_log('profile', 'avatar');
                        $_SESSION['flash'] = ['تصویر پروفایل به‌روزرسانی شد.', 'ok'];
                        header('Location: ./?page=settings');
                        exit;
                    }
                    $msg = 'ذخیرهٔ تصویر ممکن نشد.';
                    $msgType = 'err';
                }
            }
        }

        if ($do === 'avatar_remove') {
            $old = (string)($user['avatar'] ?? '');
            if ($old !== '' && is_file(av_dir() . '/' . $old)) @unlink(av_dir() . '/' . $old);
            db()->prepare('UPDATE users SET avatar = NULL WHERE id = ?')->execute([(int)$user['id']]);
            auth_log('profile', 'avatar-removed');
            $_SESSION['flash'] = ['تصویر پروفایل حذف شد.', 'ok'];
            header('Location: ./?page=settings');
            exit;
        }

        /* ---------- تغییر رمز ---------- */
        if ($do === 'password') {
            $cur  = (string)($_POST['current'] ?? '');
            $new  = (string)($_POST['new'] ?? '');
            $new2 = (string)($_POST['new2'] ?? '');
            $perr = auth_validate_password($new);
            if (!password_verify($cur, (string)$user['password_hash'])) {
                $msg = 'رمز فعلی درست نیست.';
                $msgType = 'err';
            } elseif ($perr !== null) {
                $msg = $perr;
                $msgType = 'err';
            } elseif ($new !== $new2) {
                $msg = 'رمز جدید و تکرار آن یکسان نیستند.';
                $msgType = 'err';
            } else {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
                auth_log('password', $user['username']);
                $_SESSION['flash'] = ['رمز عبور تغییر کرد.', 'ok'];
                header('Location: ./?page=settings&tab=security');
                exit;
            }
        }

        /* ---------- ذخیره‌سازی بله ---------- */
        if ($do === 'storage') {
            $token  = trim((string)($_POST['bot_token'] ?? ''));
            $chat   = trim((string)($_POST['chat_id'] ?? ''));
            $send   = trim((string)($_POST['send_chat_id'] ?? ''));
            $keepT  = !empty($_POST['keep_bot_token']);
            $clearT = !empty($_POST['clear_bot_token']);

            if (!$keepT && !$clearT && $token !== '' && !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)) {
                $msg = 'قالب توکن ربات درست نیست (باید شبیه ۱۲۳۴۵۶:ABC… باشد).';
                $msgType = 'err';
            } else {
                $sets = ['chat_id = ?', 'send_chat_id = ?'];
                $args = [$chat !== '' ? $chat : null, $send !== '' ? $send : null];
                if ($clearT)      { $sets[] = 'bot_token = ?'; $args[] = null; }
                elseif (!$keepT && $token !== '') { $sets[] = 'bot_token = ?'; $args[] = $token; }
                $args[] = (int)$user['id'];
                db()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
                auth_log('settings', 'storage');
                $_SESSION['flash'] = ['تنظیمات ذخیره‌سازی ذخیره شد.', 'ok'];
                header('Location: ./?page=settings&tab=bale');
                exit;
            }
        }
    }
}

$ctx   = storage_ctx();
$ready = storage_ready();
$usage = user_usage((int)$user['id']);
$quota = user_quota((int)$user['id']);
$upct  = ($quota > 0) ? min(100, (int)round($usage * 100 / $quota)) : 0;
$gToken = (string)db_get('bot_token', '');
$gChat  = (string)db_get('chat_id', '');
$maskToken = $gToken !== '' ? (substr($gToken, 0, 6) . '…' . substr($gToken, -4)) : 'تنظیم نشده';
$myToken = (string)($user['bot_token'] ?? '');
$myMask  = $myToken !== '' ? (substr($myToken, 0, 6) . '…' . substr($myToken, -4)) : '';
$myAv    = (string)($user['avatar'] ?? '');
$hasAv   = $myAv !== '' && is_file(av_dir() . '/' . basename($myAv));
$initial = trim((string)$user['name']) !== '' ? mb_substr(trim((string)$user['name']), 0, 1) : '?';
$phoneIn = !empty($user['phone']) ? pretty_phone((string)$user['phone']) : '';

$tab = in_array((string)($_GET['tab'] ?? ''), ['profile', 'security', 'bale', 'usage'], true) ? (string)$_GET['tab'] : 'profile';

ui_start('تنظیمات — ' . APP_TITLE);
ui_topbar($user, 'settings');
?>
<style>
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:5px;width:max-content;max-width:100%}
.tabs button{display:flex;align-items:center;gap:7px;padding:8px 16px;border:0;background:transparent;border-radius:10px;cursor:pointer;font-weight:700;font-size:13px;color:var(--muted);transition:.15s}
.tabs button:hover{color:var(--text);background:var(--surface2)}
.tabs button.on{background:var(--grad);color:#fff;box-shadow:0 4px 12px #4f46e53d}
.tabpane{display:none}
.tabpane.on{display:block;animation:stFade .18s ease}
@keyframes stFade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
.pcard{display:flex;gap:20px;align-items:center;flex-wrap:wrap;margin-bottom:18px}
.pav{width:88px;height:88px;border-radius:28px;background:var(--grad);color:#fff;display:grid;place-items:center;font-size:34px;font-weight:800;overflow:hidden;box-shadow:0 10px 26px #4f46e540;flex:none}
.pav img{width:100%;height:100%;object-fit:cover;display:block}
.pmeta{flex:1;min-width:200px}
.pmeta .big{font-size:18px;font-weight:800}
.pmeta .mut{color:var(--muted);font-size:12.5px;margin-top:2px}
.avbtns{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}
.avbtns input[type=file]{display:none}
.meter{height:10px;border-radius:99px;background:var(--surface3);overflow:hidden;margin:10px 0 6px}
.meter i{display:block;height:100%;background:var(--grad);border-radius:99px;transition:width .4s}
.meter i.full{background:linear-gradient(90deg,#f59e0b,#e11d48)}
.btres{margin-top:12px;padding:12px 14px;border-radius:12px;display:none;font-size:13px;line-height:2}
.btres.ok{display:block;background:color-mix(in srgb,var(--ok) 12%,var(--surface));border:1px solid color-mix(in srgb,var(--ok) 35%,transparent)}
.btres.bad{display:block;background:color-mix(in srgb,var(--danger) 10%,var(--surface));border:1px solid color-mix(in srgb,var(--danger) 35%,transparent);color:var(--danger)}
.kv{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-top:10px}
.kv .k{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:10px 12px}
.kv .k span{display:block;font-size:11px;color:var(--muted);margin-bottom:3px}
.kv .k b{font-size:13px;word-break:break-word}
</style>
<div class="wrap">
  <h1>حساب کاربری</h1>
  <p class="lead">مدیریت پروفایل، امنیت، تصویر و اتصال ربات بله.</p>
  <?php ui_alert($msg, $msgType); ?>

  <div class="tabs" id="tabs">
    <button data-tab="profile" class="<?= $tab === 'profile' ? 'on' : '' ?>"><?= ic('user') ?>پروفایل</button>
    <button data-tab="security" class="<?= $tab === 'security' ? 'on' : '' ?>"><?= ic('key') ?>امنیت</button>
    <button data-tab="bale" class="<?= $tab === 'bale' ? 'on' : '' ?>"><?= ic('cloud') ?>ربات بله</button>
    <button data-tab="usage" class="<?= $tab === 'usage' ? 'on' : '' ?>"><?= ic('grid') ?>فضای مصرفی</button>
  </div>

  <!-- ================= پروفایل ================= -->
  <div class="tabpane <?= $tab === 'profile' ? 'on' : '' ?>" data-pane="profile">
    <div class="card">
      <div class="pcard">
        <div class="pav" id="pavPrev"><?= $hasAv ? '<img src="' . h(av_url((int)$user['id'], $myAv)) . '" alt="">' : h($initial) ?></div>
        <div class="pmeta">
          <div class="big"><?= h($user['name'] !== '' ? $user['name'] : $user['username']) ?></div>
          <div class="mut" dir="ltr" style="text-align:end">@<?= h($user['username']) ?></div>
          <div class="mut">عضو از <?= h(fa_date((int)strtotime((string)$user['created_at']), 'date')) ?> · <span class="pill <?= $user['role'] === 'admin' ? 'admin' : '' ?>"><?= $user['role'] === 'admin' ? 'مدیر سامانه' : 'کاربر' ?></span></div>
          <div class="avbtns">
            <form method="post" enctype="multipart/form-data" id="avForm">
              <input type="hidden" name="do" value="avatar">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="file" name="avatar_file" id="avFile" accept="image/jpeg,image/png,image/gif,image/webp">
              <button type="button" class="btn sm" onclick="document.getElementById('avFile').click()"><?= ic('image') ?><span class="tx">انتخاب تصویر</span></button>
              <button type="button" class="btn sm" id="avUp" style="display:none"><?= ic('upload') ?><span class="tx">بارگذاری تصویر</span></button>
            </form>
            <?php if ($hasAv): ?>
            <form method="post" onsubmit="return confirm('تصویر پروفایل حذف شود؟')">
              <input type="hidden" name="do" value="avatar_remove">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <button class="btn sm" style="color:var(--danger)"><?= ic('trash') ?><span class="tx">حذف تصویر</span></button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <form method="post">
        <input type="hidden" name="do" value="profile">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="row2">
          <div class="field">
            <label for="name">نام نمایشی</label>
            <input id="name" name="name" value="<?= h($user['name']) ?>" required>
          </div>
          <div class="field">
            <label for="username">نام کاربری</label>
            <input id="username" name="username" value="<?= h($user['username']) ?>" required dir="ltr" style="text-align:left" pattern="[A-Za-z0-9_\.]{3,32}">
            <div class="hint">حروف انگلیسی، عدد، نقطه و زیرخط؛ ۳ تا ۳۲ نویسه. یکتا در کل سامانه.</div>
          </div>
        </div>
        <div class="row2">
          <div class="field">
            <label for="email">ایمیل</label>
            <input id="email" name="email" type="email" value="<?= h((string)$user['email']) ?>" placeholder="اختیاری" dir="ltr" style="text-align:left">
          </div>
          <div class="field">
            <label for="phone">شمارهٔ موبایل (بله)</label>
            <input id="phone" name="phone" value="<?= h($phoneIn) ?>" placeholder="۰۹۱۲۳۴۵۶۷۸۹" dir="ltr" style="text-align:left" inputmode="tel">
            <div class="hint">همان شماره‌ای که با آن وارد می‌شوید؛ برای ورود با کد بله استفاده می‌شود.</div>
          </div>
        </div>
        <div class="field">
          <label for="bio">دربارهٔ من</label>
          <textarea id="bio" name="bio" rows="3" maxlength="300" placeholder="چند کلمه دربارهٔ خودتان (اختیاری)…"><?= h((string)($user['bio'] ?? '')) ?></textarea>
        </div>
        <button class="btn p">ذخیرهٔ پروفایل</button>
      </form>
    </div>
  </div>

  <!-- ================= امنیت ================= -->
  <div class="tabpane <?= $tab === 'security' ? 'on' : '' ?>" data-pane="security">
    <div class="card">
      <h2>تغییر رمز عبور</h2>
      <form method="post">
        <input type="hidden" name="do" value="password">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="row2">
          <div class="field">
            <label for="cur">رمز فعلی</label>
            <input id="cur" name="current" type="password" required autocomplete="current-password" dir="ltr">
          </div>
          <div class="field">
            <label for="new">رمز جدید</label>
            <input id="new" name="new" type="password" required autocomplete="new-password" dir="ltr">
          </div>
        </div>
        <div class="field">
          <label for="new2">تکرار رمز جدید</label>
          <input id="new2" name="new2" type="password" required autocomplete="new-password" dir="ltr">
          <div class="hint">حداقل ۸ نویسه. توصیه: ترکیب حروف بزرگ و کوچک، عدد و نماد.</div>
        </div>
        <button class="btn p">تغییر رمز</button>
      </form>
    </div>
    <div class="card">
      <h2>ورود با کد بله</h2>
      <p class="lead" style="margin-top:-6px">اگر شمارهٔ موبایل در پروفایل ثبت شده باشد، می‌توانید بدون رمز و فقط با کد یکبارمصرف بله وارد شوید.</p>
      <span class="pill <?= !empty($user['phone']) ? 'ok' : 'bad' ?>"><?= !empty($user['phone']) ? 'فعال — شماره ثبت است' : 'غیرفعال — ابتدا شمارهٔ موبایل را در تب پروفایل ذخیره کنید' ?></span>
    </div>
  </div>

  <!-- ================= ربات بله ================= -->
  <div class="tabpane <?= $tab === 'bale' ? 'on' : '' ?>" data-pane="bale">
    <div class="card">
      <h2>اتصال به بله</h2>
      <p class="lead" style="margin-top:-8px">
        پیش‌فرض سامانه: توکن <code dir="ltr"><?= h($maskToken) ?></code> و چت <code dir="ltr"><?= h($gChat !== '' ? $gChat : '—') ?></code>.
        <?= (int)($user['use_default_bot'] ?? 0) === 1
            ? 'حساب شما به ربات پیش‌فرض سامانه دسترسی دارد؛ هر فیلدی را خالی بگذارید تا از پیش‌فرض استفاده شود.'
            : 'استفاده از ربات پیش‌فرض سامانه برای حساب شما غیرفعال است؛ توکن اختصاصی خود را وارد کنید یا از مدیر بخواهید دسترسی را فعال کند.' ?>
      </p>
      <?php if (!$ready): ?>
        <div class="alert err">توکن یا شناسهٔ چت تنظیم نشده است؛ آپلود و دانلود کار نمی‌کند.</div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="do" value="storage">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="field">
          <label for="bot_token">توکن ربات اختصاصی شما</label>
          <div class="pwf" data-keep="keep_bot_token">
            <input id="bot_token" name="bot_token" type="password" dir="ltr" autocomplete="off" spellcheck="false" value="<?= h($myToken) ?>" <?= $myToken !== '' ? 'readonly' : '' ?> placeholder="<?= $myToken !== '' ? '••••••••••••' : (((int)($user['use_default_bot'] ?? 0) === 1) ? 'خالی = استفاده از پیش‌فرض سامانه' : 'توکن اختصاصی خود را وارد کنید') ?>">
            <span class="acts">
              <button type="button" class="ey" data-pw="bot_token" title="نمایش توکن"><?= ic('eye') ?></button>
              <?php if ($myToken !== ''): ?><button type="button" class="ed" data-pw="bot_token" title="ویرایش توکن"><?= ic('edit') ?></button>
              <button type="button" class="dl" data-pw="bot_token" data-clear="clear_bot_token" title="حذف توکن و بازگشت به پیش‌فرض"><?= ic('trash') ?></button><?php endif; ?>
            </span>
            <input type="hidden" name="keep_bot_token" value=""><input type="hidden" name="clear_bot_token" value="">
          </div>
          <?php if ($myToken !== ''): ?>
            <div class="hint">فعلی: <span dir="ltr"><?= h($myMask) ?></span> — چشم: نمایش کامل · مداد: تغییر · سطل: حذف و بازگشت به پیش‌فرض سامانه.</div>
          <?php elseif ((int)($user['use_default_bot'] ?? 0) !== 1): ?>
            <div class="hint">استفاده از ربات پیش‌فرض سامانه برای حساب شما غیرفعال است؛ توکن اختصاصی خود را وارد کنید.</div>
          <?php endif; ?>
          <div class="hint">توکن را از <b dir="ltr">@BotFather</b> در بله بگیرید. ربات باید <b>عضو چت/کانال هدف</b> باشد.</div>
        </div>
        <div class="row2">
          <div class="field">
            <label for="chat_id">شناسهٔ چت / کانال (محل ذخیرهٔ فایل‌ها)</label>
            <input id="chat_id" name="chat_id" value="<?= h((string)$user['chat_id']) ?>" placeholder="<?= h($gChat !== '' ? 'پیش‌فرض: ' . $gChat : 'مثلاً 1945714647') ?>" dir="ltr" style="text-align:left">
            <div class="hint">شناسهٔ عددی چت خصوصی با ربات یا کانال. پیام «/start» به ربات بدهید و شناسه را از آن بگیرید.</div>
          </div>
          <div class="field">
            <label for="send_chat_id">چت ارسال فایل (اختیاری)</label>
            <input id="send_chat_id" name="send_chat_id" value="<?= h((string)($user['send_chat_id'] ?? '')) ?>" placeholder="برای دکمهٔ «ارسال به چت بله»" dir="ltr" style="text-align:left">
          </div>
        </div>
        <div class="inline">
          <button class="btn p">ذخیرهٔ تنظیمات</button>
          <button type="button" class="btn" id="botTest"><?= ic('cloud') ?><span class="tx">تست اتصال</span></button>
          <span class="pill <?= $ready ? 'ok' : 'bad' ?>" id="readyPill"><?= $ready ? 'اتصال بله تنظیم است' : 'تنظیم نشده' ?></span>
        </div>
      </form>
      <div class="btres" id="btRes"></div>
    </div>
  </div>

  <!-- ================= فضای مصرفی ================= -->
  <div class="tabpane <?= $tab === 'usage' ? 'on' : '' ?>" data-pane="usage">
    <div class="card">
      <h2>فضای مصرفی شما</h2>
      <?php if ($quota > 0): ?>
        <div class="meter"><i class="<?= $upct >= 90 ? 'full' : '' ?>" style="width:<?= $upct ?>%"></i></div>
      <?php endif; ?>
      <div class="inline" style="justify-content:space-between;flex-wrap:wrap">
        <span>مصرف‌شده: <b><?= h(fmt_size($usage)) ?></b></span>
        <span>سهمیه: <b><?= $quota > 0 ? h(fmt_size($quota)) : 'نامحدود' ?></b></span>
        <?php if ($quota > 0): ?><span>باقی‌مانده: <b><?= h(fmt_size(max(0, $quota - $usage))) ?></b></span><?php endif; ?>
        <span class="pill <?= $user['role'] === 'admin' ? 'admin' : '' ?>"><?= $user['role'] === 'admin' ? 'مدیر' : 'کاربر' ?></span>
      </div>
      <div class="hint">فایل‌ها روی سرورهای بله ذخیره می‌شوند؛ این عدد مجموع حجم منطقی فایل‌های شماست.</div>
    </div>
  </div>
</div>
<script>
(function () {
  /* تب‌ها */
  var tabs = document.querySelectorAll('#tabs button');
  var panes = document.querySelectorAll('.tabpane');
  tabs.forEach(function (b) {
    b.addEventListener('click', function () {
      tabs.forEach(function (x) { x.classList.remove('on'); });
      panes.forEach(function (p) { p.classList.remove('on'); });
      b.classList.add('on');
      var p = document.querySelector('.tabpane[data-pane="' + b.dataset.tab + '"]');
      if (p) p.classList.add('on');
      try { history.replaceState(null, '', './?page=settings&tab=' + b.dataset.tab); } catch (e) {}
    });
  });
  /* آواتار: پیش‌نمایش + ارسال خودکار */
  var avFile = document.getElementById('avFile'), avUp = document.getElementById('avUp'), pav = document.getElementById('pavPrev');
  if (avFile && avUp) {
    avFile.addEventListener('change', function () {
      if (!avFile.files || !avFile.files[0]) return;
      var f = avFile.files[0];
      if (pav) { var r = new FileReader(); r.onload = function (e) { pav.innerHTML = '<img src="' + e.target.result + '" alt="">'; }; r.readAsDataURL(f); }
      avUp.style.display = 'inline-flex';
      document.getElementById('avForm').submit();
    });
  }
  /* تست اتصال ربات */
  var bt = document.getElementById('botTest'), res = document.getElementById('btRes');
  if (bt && res) {
    bt.addEventListener('click', function () {
      var old = bt.innerHTML;
      bt.disabled = true; bt.innerHTML = '... در حال تست';
      res.className = 'btres'; res.style.display = 'block'; res.textContent = 'در حال بررسی اتصال به tapi.bale.ai …';
      fetch('./?page=settings&ajax=bot_test', { headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { res.className = 'btres bad'; res.textContent = '✗ ' + (j.msg || 'اتصال برقرار نشد'); return; }
          var h = '✓ توکن معتبر است — ربات: <b dir="ltr">@' + (j.bot.username || '—') + '</b>' + (j.bot.name ? ' (' + j.bot.name + ')' : '');
          if (j.chat) {
            h += '<br>✓ دسترسی به چت هدف: <b dir="ltr">' + (j.chat.title || j.chat.username || j.chat.id) + '</b>';
            h += ' <span style="opacity:.75">(' + (j.chat.type || '') + (j.member ? ' · عضویت ربات: ' + j.member : '') + ')</span>';
          } else if (j.chat_err) {
            h += '<br>⚠ توکن درست است اما دسترسی به چت هدف ناموفق بود: ' + j.chat_err;
          } else {
            h += '<br>⚠ شناسهٔ چت تنظیم نشده است؛ فایل‌ها جایی ذخیره نمی‌شوند.';
          }
          res.className = 'btres ok'; res.innerHTML = h;
        })
        .catch(function () { res.className = 'btres bad'; res.textContent = '✗ خطای شبکه در تست اتصال'; })
        .finally(function () { bt.disabled = false; bt.innerHTML = old; });
    });
  }
})();
</script>
<?php ui_secret_js(); ui_end();
