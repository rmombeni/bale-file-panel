<?php
/**
 * ایجاد کاربر جدید — صفحهٔ مجزا.
 */
require_once __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        adm_flash('درخواست نامعتبر بود.', 'err');
        header('Location: user-new.php');
        exit;
    }

    $name  = trim((string)($_POST['name'] ?? ''));
    $user  = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $role  = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
    $bot   = !empty($_POST['use_default_bot']) ? 1 : 0;

    $err = auth_validate_username($user) ?: auth_validate_password($pass);
    if (!$err && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'ایمیل معتبر نیست.';
    if (!$err) {
        $st = db()->prepare('SELECT 1 FROM users WHERE username = ? OR (email IS NOT NULL AND email <> "" AND email = ?) LIMIT 1');
        $st->execute([$user, $email]);
        if ($st->fetchColumn()) $err = 'نام کاربری یا ایمیل قبلاً ثبت شده است.';
    }

    if ($err) {
        adm_flash($err, 'err');
        header('Location: user-new.php');
        exit;
    }

    db()->prepare('INSERT INTO users (name, username, email, password_hash, role, is_active, use_default_bot, created_at) VALUES (?,?,?,?,?,1,?,?)')
        ->execute([$name !== '' ? $name : $user, $user, $email !== '' ? $email : null, password_hash($pass, PASSWORD_DEFAULT), $role, $bot, date('Y-m-d H:i:s')]);
    $newId = (int)db()->lastInsertId();
    auth_log('user_create', $user, $role);
    adm_flash('حساب «' . $user . '» ساخته شد.');
    header('Location: user.php?id=' . $newId);
    exit;
}

adm_header('کاربر جدید', 'users');
?>
<div class="grid2">
  <div>
    <div class="card">
      <h2><?= ic('plus') ?>ایجاد حساب کاربری</h2>
      <form method="post" autocomplete="off">
        <input type="hidden" name="do" value="create">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="row2">
          <div class="field">
            <label for="n-name">نام و نام خانوادگی</label>
            <input id="n-name" name="name" placeholder="اختیاری — پیش‌فرض نام کاربری">
          </div>
          <div class="field">
            <label for="n-username">نام کاربری</label>
            <input id="n-username" name="username" required dir="ltr" style="text-align:left" placeholder="example_user">
            <div class="hint">۳ تا ۳۲ نویسهٔ لاتین، عدد، نقطه یا زیرخط.</div>
          </div>
        </div>
        <div class="row2">
          <div class="field">
            <label for="n-email">ایمیل</label>
            <input id="n-email" name="email" type="email" dir="ltr" style="text-align:left" placeholder="اختیاری — برای ورود با ایمیل">
          </div>
          <div class="field">
            <label for="n-pass">رمز عبور</label>
            <input id="n-pass" name="password" required dir="ltr" style="text-align:left" autocomplete="new-password" placeholder="حداقل ۸ نویسه">
          </div>
        </div>
        <div class="row2">
          <div class="field">
            <label for="n-role">نقش</label>
            <select id="n-role" name="role">
              <option value="user">کاربر عادی</option>
              <option value="admin">مدیر</option>
            </select>
          </div>
          <div class="field">
            <label>دسترسی به ربات پیش‌فرض</label>
            <label style="display:flex;align-items:center;gap:8px;font-weight:600;color:var(--text)">
              <input type="checkbox" name="use_default_bot" value="1" style="width:auto">
              اجازهٔ استفاده از ربات پیش‌فرض سامانه
            </label>
            <div class="hint">در صورت عدم فعال‌سازی، کاربر باید توکن اختصاصی خود را در تنظیمات وارد کند. هر زمان از نمای کاربر قابل تغییر است.</div>
          </div>
        </div>
        <div class="inline">
          <button class="btn p"><?= ic('check') ?>ایجاد حساب</button>
          <a class="btn" href="users.php">انصراف</a>
        </div>
      </form>
    </div>
  </div>

  <div>
    <div class="card">
      <h2><?= ic('shield') ?>راهنما</h2>
      <div class="kv"><span>نقش مدیر</span><b>دسترسی کامل به پنل مدیریت</b></div>
      <div class="kv"><span>ربات پیش‌فرض</span><b>ذخیره‌سازی روی ربات سامانه</b></div>
      <div class="kv"><span>بدون ربات پیش‌فرض</span><b>نیاز به توکن اختصاصی کاربر</b></div>
      <div class="kv"><span>سهمیهٔ فضای</span><b>بعد از ایجاد، از نمای کاربر</b></div>
    </div>
  </div>
</div>
<?php adm_footer();
