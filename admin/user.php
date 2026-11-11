<?php
/**
 * نمای کامل کاربر — ویرایش کامل اطلاعات، تصویر پروفایل، موبایل، آمار،
 * دسترسی ربات پیش‌فرض، تنظیمات ذخیره‌سازی، نشست‌ها، فعالیت و فایل‌ها.
 */
require_once __DIR__ . '/bootstrap.php';

$uid = (int)($_GET['id'] ?? 0);
$self = (int)$me['id'];
$st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$st->execute([$uid]);
$u = $st->fetch();
if (!$u) { http_response_code(404); adm_header('کاربر یافت نشد', 'users'); echo '<div class="empty"><h3>کاربر یافت نشد</h3><a class="btn" href="users.php">بازگشت به کاربران</a></div>'; adm_footer(); exit; }
$u['use_default_bot'] = (int)($u['use_default_bot'] ?? 0);
$isSelf = $uid === $self;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $back = 'user.php?id=' . $uid;
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        adm_flash('درخواست نامعتبر بود.', 'err');
    } else {
        $do = (string)($_POST['do'] ?? '');

        /* ---------- ویرایش کامل اطلاعات ---------- */
        if ($do === 'profile') {
            $name    = trim((string)($_POST['name'] ?? ''));
            $usern   = trim((string)($_POST['username'] ?? ''));
            $email   = trim((string)($_POST['email'] ?? ''));
            $phone   = trim((string)($_POST['phone'] ?? ''));
            $bio     = trim((string)($_POST['bio'] ?? ''));
            $newpass = (string)($_POST['new_password'] ?? '');
            $quotaMb = max(0, (int)($_POST['quota_mb'] ?? 0));
            $role    = ($_POST['role'] ?? $u['role']) === 'admin' ? 'admin' : 'user';
            $active  = isset($_POST['is_active']) ? 1 : 0;

            $err = null;
            if (!preg_match('/^[a-zA-Z0-9_.]{3,32}$/', $usern)) $err = 'نام کاربری باید ۳ تا ۳۲ نویسهٔ لاتین، عدد، نقطه یا زیرخط باشد.';
            if (!$err && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'ایمیل معتبر نیست.';
            $phone98 = $phone !== '' ? norm_phone($phone) : '';
            if (!$err && $phone !== '' && $phone98 === '') $err = 'شمارهٔ موبایل معتبر نیست (مثال: 09121234567).';
            if (!$err && $newpass !== '' && auth_validate_password($newpass)) $err = auth_validate_password($newpass);
            if (!$err && mb_strlen($bio) > 280) $err = 'بیوگرافی بیش از حد بلند است (حداکثر ۲۸۰ نویسه).';
            if (!$err) {
                $st = db()->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ? LIMIT 1');
                $st->execute([$usern, $uid]);
                if ($st->fetchColumn()) $err = 'این نام کاربری برای حساب دیگری استفاده شده است.';
            }
            if (!$err && $email !== '') {
                $st = db()->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ? LIMIT 1');
                $st->execute([$email, $uid]);
                if ($st->fetchColumn()) $err = 'این ایمیل برای حساب دیگری ثبت شده است.';
            }
            if (!$err && $phone98 !== '') {
                $st = db()->prepare('SELECT 1 FROM users WHERE phone = ? AND id <> ? LIMIT 1');
                $st->execute([$phone98, $uid]);
                if ($st->fetchColumn()) $err = 'این شمارهٔ موبایل برای حساب دیگری ثبت شده است.';
            }

            if (!$err) {
                if (!empty($_POST['remove_avatar'])) {
                    $oldAv = (string)$u['avatar'];
                    if ($oldAv !== '' && is_file(avatar_dir() . '/' . $oldAv)) @unlink(avatar_dir() . '/' . $oldAv);
                    db()->prepare('UPDATE users SET avatar = NULL WHERE id = ?')->execute([$uid]);
                }
                $params = [$name !== '' ? $name : $usern, $usern, $email !== '' ? $email : null, $phone98 !== '' ? $phone98 : null, $bio !== '' ? $bio : null, $quotaMb > 0 ? $quotaMb * 1048576 : null];
                $sql = 'UPDATE users SET name = ?, username = ?, email = ?, phone = ?, bio = ?, quota_bytes = ?';
                if ($newpass !== '') {
                    $sql .= ', password_hash = ?';
                    $params[] = password_hash($newpass, PASSWORD_DEFAULT);
                }
                if (!$isSelf) {
                    $sql .= ', role = ?, is_active = ?';
                    $params[] = $role;
                    $params[] = !$isSelf ? $active : 1;
                }
                $sql .= ' WHERE id = ?';
                $params[] = $uid;
                db()->prepare($sql)->execute($params);
                if ($newpass !== '') db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$uid]);
                auth_log('user_profile', $usern);
                adm_flash('اطلاعات کاربر ذخیره شد.');
            } else {
                adm_flash($err, 'err');
            }
        }

        /* ---------- آپلود تصویر پروفایل ---------- */
        if ($do === 'avatar') {
            $fl = $_FILES['avatar_file'] ?? null;
            if (!$fl || $fl['error'] !== UPLOAD_ERR_OK) {
                adm_flash('فایلی دریافت نشد یا آپلود خطا داشت.', 'err');
            } elseif ($fl['size'] > 2 * 1024 * 1024) {
                adm_flash('حجم تصویر باید حداکثر ۲ مگابایت باشد.', 'err');
            } else {
                $mimeMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                $info = @getimagesize($fl['tmp_name']);
                if (!$info || !isset($mimeMap[$info['mime']])) {
                    adm_flash('فقط تصویر JPG، PNG، GIF یا WEBP مجاز است.', 'err');
                } else {
                    $ext  = $mimeMap[$info['mime']];
                    $fname = 'avatar-' . $uid . '.' . $ext;
                    $old = (string)$u['avatar'];
                    if (move_uploaded_file($fl['tmp_name'], avatar_dir() . '/' . $fname)) {
                        if ($old !== '' && $old !== $fname && is_file(avatar_dir() . '/' . $old)) @unlink(avatar_dir() . '/' . $old);
                        db()->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$fname, $uid]);
                        auth_log('user_avatar', (string)$uid);
                        adm_flash('تصویر پروفایل به‌روزرسانی شد.');
                    } else {
                        adm_flash('ذخیرهٔ تصویر ممکن نشد.', 'err');
                    }
                }
            }
        }

        if ($do === 'avatar_remove') {
            $old = (string)$u['avatar'];
            if ($old !== '' && is_file(avatar_dir() . '/' . $old)) @unlink(avatar_dir() . '/' . $old);
            db()->prepare('UPDATE users SET avatar = NULL WHERE id = ?')->execute([$uid]);
            auth_log('user_avatar', (string)$uid, 'removed');
            adm_flash('تصویر پروفایل حذف شد.');
        }

        if ($do === 'defaultbot') {
            $on = !empty($_POST['on']) ? 1 : 0;
            db()->prepare('UPDATE users SET use_default_bot = ? WHERE id = ?')->execute([$on, $uid]);
            auth_log('user_defaultbot', (string)$uid, (string)$on);
            adm_flash($on ? 'دسترسی به ربات پیش‌فرض فعال شد.' : 'دسترسی به ربات پیش‌فرض غیرفعال شد.');
        }

        if ($do === 'storage') {
            $token = trim((string)($_POST['bot_token'] ?? ''));
            $clear = !empty($_POST['clear_token']);
            $chat  = trim((string)($_POST['chat_id'] ?? ''));
            $send  = trim((string)($_POST['send_chat_id'] ?? ''));
            if ($token !== '' && !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)) {
                adm_flash('قالب توکن ربات درست نیست (شبیه ۱۲۳۴۵۶:ABC…).', 'err');
            } else {
                $newToken = $clear || $token === '' ? null : $token;
                db()->prepare('UPDATE users SET bot_token = ?, chat_id = ?, send_chat_id = ? WHERE id = ?')
                    ->execute([$newToken, $chat !== '' ? $chat : null, $send !== '' ? $send : null, $uid]);
                auth_log('user_storage', (string)$uid);
                adm_flash('تنظیمات ذخیره‌سازی کاربر ذخیره شد.');
            }
        }

        if ($do === 'toggle' && !$isSelf) {
            db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$uid]);
            auth_log('user_toggle', (string)$uid);
            adm_flash('وضعیت حساب تغییر کرد.');
        }

        if ($do === 'resetpass') {
            $new = bin2hex(random_bytes(6));
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$uid]);
            auth_log('user_resetpass', (string)$uid);
            adm_flash('رمز جدید کاربر: ' . $new . ' — آن را در جای امنی نگه دارید.');
        }

        if ($do === 'sessions_out') {
            db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$uid]);
            auth_log('user_sessions_out', (string)$uid);
            adm_flash('«مرا به خاطر بسپار» همهٔ دستگاه‌ها باطل شد.');
        }

        if ($do === 'attempts_clear') {
            db()->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([(string)$u['username']]);
            auth_log('attempts_clear', (string)$u['username']);
            adm_flash('تلاش‌های ناموفق پاک شد.');
        }

        if ($do === 'delete' && !$isSelf) {
            $old = (string)$u['avatar'];
            if ($old !== '' && is_file(avatar_dir() . '/' . $old)) @unlink(avatar_dir() . '/' . $old);
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM files WHERE user_id = ?')->execute([$uid]);
                $pdo->prepare('DELETE FROM folders WHERE user_id = ?')->execute([$uid]);
                $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$uid]);
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                $pdo->commit();
                auth_log('user_delete', (string)$uid);
                adm_flash('حساب و فایل‌های آن حذف شد.');
                header('Location: users.php');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                adm_flash('حذف انجام نشد.', 'err');
            }
        }
    }
    header('Location: ' . $back);
    exit;
}

/* ---------- داده‌های نمایش ---------- */
$st = db()->prepare('SELECT COUNT(*) AS fc, COALESCE(SUM(size),0) AS sz FROM files WHERE user_id = ?');
$st->execute([$uid]);
$sum = $st->fetch();
$fc = (int)$sum['fc']; $used = (int)$sum['sz'];
$quota = (int)$u['quota_bytes'] > 0 ? (int)$u['quota_bytes'] : (int)db_get('default_quota', 0);
$percent = $quota > 0 ? min(100, round($used / $quota * 100)) : 0;

$lastIp = '';
$st = db()->prepare("SELECT ip FROM activity WHERE user_id = ? AND ip <> '' ORDER BY id DESC LIMIT 1");
$st->execute([$uid]);
$lastIp = (string)$st->fetchColumn();

$st = db()->prepare('SELECT a.*, u.username FROM activity a LEFT JOIN users u ON u.id = a.user_id WHERE a.user_id = ? ORDER BY a.id DESC LIMIT 10');
$st->execute([$uid]);
$logs = $st->fetchAll();

$st = db()->prepare('SELECT * FROM files WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
$st->execute([$uid]);
$files = $st->fetchAll();

$st = db()->prepare('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ? AND expires_at > NOW()');
$st->execute([$uid]);
$tokenCount = (int)$st->fetchColumn();

$st = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ok = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
$st->execute([(string)$u['username']]);
$failCount = (int)$st->fetchColumn();

$hasOwnToken = trim((string)$u['bot_token']) !== '';
$effToken = $hasOwnToken || $u['use_default_bot'];
$initial = (string)$u['name'];
$initial = function_exists('mb_substr') ? mb_substr($initial, 0, 1) : substr($initial, 0, 1);

adm_header($u['name'] . ' (@' . $u['username'] . ')', 'users');
?>
<div class="card">
  <div class="pcard">
    <span class="av av-lg"><?= $u['avatar'] ? '<img src="' . h(avatar_url($uid, (string)$u['avatar'])) . '" alt="">' : h($initial !== '' ? $initial : '?') ?></span>
    <div style="flex:1;min-width:220px">
      <div style="font-size:18px;font-weight:800;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <?= h($u['name']) ?>
        <span class="pill admin" title="شناسهٔ عددی کاربر" dir="ltr">#<?= $uid ?></span>
      </div>
      <div class="muted ltr" style="font-size:13px">@<?= h($u['username']) ?></div>
      <div class="pv-pills">
        <span class="pill <?= $u['role'] === 'admin' ? 'admin' : '' ?>"><?= $u['role'] === 'admin' ? 'مدیر' : 'کاربر' ?></span>
        <span class="pill <?= (int)$u['is_active'] ? 'ok' : 'bad' ?>"><?= (int)$u['is_active'] ? 'فعال' : 'مسدود' ?></span>
        <span class="pill <?= $u['use_default_bot'] ? 'ok' : '' ?>">ربات پیش‌فرض: <?= $u['use_default_bot'] ? 'فعال' : 'غیرفعال' ?></span>
        <?php if (!empty($u['phone'])): ?><span class="pill ltr"><?= ic('user') . h($u['phone']) ?></span><?php endif; ?>
      </div>
      <?php if (!empty($u['bio'])): ?><div class="pv-meta" style="max-width:520px"><?= h($u['bio']) ?></div><?php endif; ?>
    </div>
    <div style="text-align:end">
      <div class="pv-meta">عضویت: <?= h(fa_date((int)strtotime((string)$u['created_at']), 'date')) ?></div>
      <div class="pv-meta">آخرین ورود: <?= $u['last_login_at'] ? h(fa_date((int)strtotime((string)$u['last_login_at']))) : '—' ?></div>
      <div class="pv-meta">آخرین IP: <span class="ltr"><?= $lastIp !== '' ? h($lastIp) : '—' ?></span></div>
      <div class="pv-meta">نشست فعال: <?= $tokenCount ?> دستگاه</div>
    </div>
  </div>
</div>

<div class="cards">
  <div class="stat-c"><span class="bx c2"><?= ic('folder') ?></span><div><b><?= $fc ?></b><span>فایل</span></div></div>
  <div class="stat-c"><span class="bx c3"><?= ic('drive') ?></span><div><b><?= h(fmt_size($used)) ?></b><span>حجم مصرفی</span></div></div>
  <div class="stat-c" style="display:block">
    <div class="prg"><b style="font-size:16px"><?= $quota > 0 ? h(fmt_size($quota)) : 'نامحدود' ?></b><span class="sp" style="flex:1"></span><span>سهمیه</span></div>
    <?php if ($quota > 0): ?><div class="prg" style="margin-top:8px"><div class="bar"><i style="width:<?= $percent ?>%"></i></div><span class="muted" style="font-size:11px"><?= $percent ?>٪</span></div><?php endif; ?>
  </div>
  <div class="stat-c"><span class="bx <?= $failCount > 0 ? 'c5' : 'c6' ?>"><?= ic('key') ?></span><div><b><?= $failCount ?></b><span>تلاش ناموفق ورود (۲۴ ساعت)</span></div></div>
  <div class="stat-c"><span class="bx <?= $effToken ? 'c3' : 'c6' ?>"><?= ic('zap') ?></span><div><b><?= $effToken ? 'متصل' : 'قطع' ?></b><span>وضعیت ذخیره‌سازی بله</span></div></div>
</div>

<div class="grid2">
  <div>
    <div class="card">
      <h2><?= ic('edit') ?>ویرایش اطلاعات کاربر</h2>
      <form method="post" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="do" value="profile">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

        <div class="pcenter" style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
          <span class="av av-lg"><?= $u['avatar'] ? '<img src="' . h(avatar_url($uid, (string)$u['avatar'])) . '" alt="">' : h($initial !== '' ? $initial : '?') ?></span>
          <div style="flex:1">
            <input type="file" name="avatar_file" accept="image/jpeg,image/png,image/gif,image/webp" style="font-size:12px">
            <?php if ($u['avatar']): ?>
              <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--danger);margin-top:6px">
                <input type="checkbox" name="remove_avatar" value="1" style="width:auto"> حذف تصویر پروفایل
              </label>
            <?php endif; ?>
          </div>
        </div>

        <div class="row2">
          <div class="field"><label>نام نمایشی</label><input name="name" value="<?= h($u['name']) ?>"></div>
          <div class="field"><label>نام کاربری</label><input name="username" required value="<?= h($u['username']) ?>" dir="ltr" style="text-align:left"></div>
        </div>
        <div class="row2">
          <div class="field"><label>ایمیل</label><input name="email" type="email" value="<?= h((string)$u['email']) ?>" dir="ltr" style="text-align:left"></div>
          <div class="field"><label>شمارهٔ موبایل</label><input name="phone" value="<?= $u['phone'] ? h(pretty_phone((string)$u['phone'])) : '' ?>" dir="ltr" style="text-align:left" placeholder="09121234567"><div class="hint">برای «ورود با کد بله» استفاده می‌شود؛ با ۰۹ وارد کنید.</div></div>
        </div>
        <div class="field"><label>بیوگرافی کوتاه</label><input name="bio" value="<?= h((string)($u['bio'] ?? '')) ?>" maxlength="280" placeholder="اختیاری"></div>
        <div class="row2">
          <div class="field"><label>سهمیهٔ فضا (مگابایت — ۰ = پیش‌فرض سامانه)</label><input name="quota_mb" type="number" min="0" value="<?= (int)$u['quota_bytes'] > 0 ? (int)round(((int)$u['quota_bytes']) / 1048576) : 0 ?>"></div>
          <div class="field"><label>رمز عبور جدید</label><input name="new_password" type="password" dir="ltr" style="text-align:left" autocomplete="new-password" placeholder="خالی = بدون تغییر"></div>
        </div>
        <?php if (!$isSelf): ?>
        <div class="row2">
          <div class="field"><label>نقش</label>
            <select name="role">
              <option value="user" <?= $u['role'] !== 'admin' ? 'selected' : '' ?>>کاربر عادی</option>
              <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>مدیر</option>
            </select>
          </div>
          <div class="field"><label>وضعیت حساب</label>
            <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted);margin-top:6px"><input type="checkbox" name="is_active" value="1" <?= (int)$u['is_active'] ? 'checked' : '' ?> style="width:auto"> حساب فعال باشد</label>
          </div>
        </div>
        <?php endif; ?>
        <div class="inline">
          <button class="btn p"><?= ic('check') ?>ذخیرهٔ تغییرات</button>
          <a class="btn" href="users.php">بازگشت</a>
        </div>
      </form>
    </div>

    <div class="card">
      <h2><?= ic('gear') ?>تنظیمات ذخیره‌سازی کاربر</h2>
      <form method="post">
        <input type="hidden" name="do" value="storage">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="field">
          <label for="u-token">توکن ربات اختصاصی</label>
          <input id="u-token" name="bot_token" type="password" placeholder="<?= $hasOwnToken ? 'تنظیم شده (' . h(substr((string)$u['bot_token'], 0, 6)) . '…) — برای تغییر مقدار جدید را وارد کنید' : 'وارد نشده' ?>" dir="ltr" autocomplete="off">
          <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted);margin-top:6px">
            <input type="checkbox" name="clear_token" value="1" style="width:auto"> حذف توکن اختصاصی
          </label>
        </div>
        <div class="row2">
          <div class="field"><label for="u-chat">شناسهٔ چت ذخیره‌سازی</label>
            <input id="u-chat" name="chat_id" value="<?= h((string)$u['chat_id']) ?>" dir="ltr" style="text-align:left"></div>
          <div class="field"><label for="u-send">چت ارسال (اختیاری)</label>
            <input id="u-send" name="send_chat_id" value="<?= h((string)($u['send_chat_id'] ?? '')) ?>" dir="ltr" style="text-align:left"></div>
        </div>
        <button class="btn"><?= ic('check') ?>ذخیره</button>
      </form>
    </div>
  </div>

  <div>
    <div class="card">
      <h2><?= ic('zap') ?>دسترسی به ربات پیش‌فرض</h2>
      <p class="muted" style="font-size:12px;margin:0 0 12px">
        اگر غیرفعال باشد، کاربر فقط با توکن اختصاصی خودش می‌تواند فایل ذخیره کند و پیام‌های او روی ربات سامانه نمی‌رود.
      </p>
      <div class="inline">
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="do" value="defaultbot">
          <input type="hidden" name="on" value="<?= $u['use_default_bot'] ? 0 : 1 ?>">
          <?php if ($u['use_default_bot']): ?>
            <button class="btn dg" onclick="return confirm('دسترسی این کاربر به ربات پیش‌فرض قطع شود؟')"><?= ic('x') ?>غیرفعال کردن</button>
          <?php else: ?>
            <button class="btn p"><?= ic('check') ?>فعال کردن دسترسی</button>
          <?php endif; ?>
        </form>
        <span class="pill <?= $u['use_default_bot'] ? 'ok' : '' ?>"><?= $u['use_default_bot'] ? 'دسترسی فعال است' : 'دسترسی غیرفعال است' ?></span>
      </div>
    </div>

    <div class="card">
      <h2><?= ic('shield') ?>امنیت و نشست‌ها</h2>
      <div class="kv"><span>دستگاه‌های «مرا به خاطر بسپار»</span><b><?= $tokenCount ?></b></div>
      <div class="kv"><span>تلاش ناموفق ورود (۲۴ ساعت، این نام کاربری)</span><b style="<?= $failCount > 0 ? 'color:var(--danger)' : '' ?>"><?= $failCount ?></b></div>
      <div class="inline" style="margin-top:12px">
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="do" value="sessions_out">
          <button class="btn" onclick="return confirm('همهٔ «مرا به خاطر بسپار» این کاربر باطل شود؟')" <?= $tokenCount === 0 ? 'disabled style="opacity:.5;pointer-events:none"' : '' ?>><?= ic('logout') ?>خروج از همهٔ دستگاه‌ها</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="do" value="attempts_clear">
          <button class="btn" <?= $failCount === 0 ? 'disabled style="opacity:.5;pointer-events:none"' : '' ?>><?= ic('refresh') ?>پاک‌سازی تلاش‌های ناموفق</button>
        </form>
      </div>
      <div class="inline" style="margin-top:10px">
        <form method="post" style="display:inline" onsubmit="return confirm('رمز این کاربر بازنشانی شود؟')">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="do" value="resetpass">
          <button class="btn"><?= ic('key') ?>بازنشانی رمز (خودکار)</button>
        </form>
        <?php if (!$isSelf): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="do" value="toggle">
          <button class="btn <?= (int)$u['is_active'] ? 'dg' : 'p' ?>"><?= (int)$u['is_active'] ? ic('x') . 'مسدودسازی' : ic('check') . 'فعال‌سازی' ?></button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h2><?= ic('activity') ?>فعالیت اخیر</h2>
      <div class="scrl">
        <table class="tbl">
          <tbody>
          <?php if (!$logs): ?><tr><td class="muted">فعالیتی ثبت نشده است.</td></tr><?php endif; ?>
          <?php foreach ($logs as $l): ?>
            <tr>
              <td class="muted" style="font-size:11px;white-space:nowrap"><?= h(fa_date((int)strtotime((string)$l['created_at']))) ?></td>
              <td><?= h(adm_action_fa((string)$l['action'])) ?></td>
              <td class="muted ltr" style="font-size:11px"><?= h((string)$l['ip']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h2><?= ic('folder') ?>فایل‌های اخیر</h2>
      <?php if (!$files): ?>
        <div class="empty">این کاربر هنوز فایلی ندارد.</div>
      <?php else: ?>
      <div class="scrl">
        <table class="tbl">
          <tbody>
          <?php foreach ($files as $f): ?>
            <tr>
              <td><div class="nm" style="max-width:180px" title="<?= h($f['name']) ?>"><?= ic('file') ?> <?= h($f['name']) ?></div></td>
              <td class="muted" style="font-size:11px"><?= h(fmt_size((int)$f['size'])) ?></td>
              <td class="right"><a class="btn sm" href="<?= h(adm_base_url() . '?s=' . urlencode((string)$f['share'])) ?>" target="_blank" title="لینک اشتراک"><?= ic('external') ?></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <a class="btn sm" style="margin-top:10px" href="files.php?u=<?= $uid ?>"><?= ic('folder') ?>همهٔ فایل‌های این کاربر</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card" style="border-color:var(--danger)">
  <h2 style="color:var(--danger)"><?= ic('trash') ?>حذف حساب</h2>
  <?php if ($isSelf): ?>
    <div class="muted">حساب فعال شماست و حذف خود گزینه ندارد.</div>
  <?php else: ?>
    <form method="post" onsubmit="return confirm('حذف قطعی حساب «<?= h($u['username']) ?>» و همهٔ فایل‌هایش؟ این کار برگشت‌پذیر نیست.')">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="do" value="delete">
      <button class="btn dg"><?= ic('trash') ?>حذف قطعی حساب و فایل‌ها</button>
    </form>
  <?php endif; ?>
</div>
<?php adm_footer();
