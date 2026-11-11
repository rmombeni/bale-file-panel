<?php
/**
 * صفحهٔ ثبت‌نام — هم‌قالب با ورود: پنل برند + فرم ساخت حساب.
 */
if (!defined('APP_RUNNING')) { http_response_code(404); exit('Not found'); }

$allowSignup = db_get('allow_signup', '1') === '1';
$err = '';
$old = ['name' => '', 'username' => '', 'email' => '', 'phone' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'register' && $allowSignup) {
    $old['name']     = trim((string)($_POST['name'] ?? ''));
    $old['username'] = trim((string)($_POST['username'] ?? ''));
    $old['email']    = trim((string)($_POST['email'] ?? ''));
    $old['phone']    = trim((string)($_POST['phone'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $err = 'درخواست نامعتبر بود؛ دوباره تلاش کنید.';
    } elseif (mb_strlen($old['name']) < 2 || mb_strlen($old['name']) > 80) {
        $err = 'نام را بین ۲ تا ۸۰ نویسه وارد کنید.';
    } elseif (($uerr = auth_validate_username($old['username'])) !== null) {
        $err = $uerr;
    } elseif ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $err = 'ایمیل معتبر نیست.';
    } elseif ($old['phone'] !== '' && norm_phone($old['phone']) === '') {
        $err = 'شمارهٔ موبایل معتبر نیست (مثال: ۰۹۱۲۳۴۵۶۷۸۹).';
    } elseif (($perr = auth_validate_password($pass)) !== null) {
        $err = $perr;
    } elseif ($pass !== $pass2) {
        $err = 'رمز عبور و تکرار آن یکسان نیستند.';
    } else {
        $phone98 = $old['phone'] !== '' ? norm_phone($old['phone']) : '';
        if ($phone98 !== '') {
            $ch = db()->prepare('SELECT COUNT(*) FROM users WHERE phone = ?');
            $ch->execute([$phone98]);
            if ((int)$ch->fetchColumn() > 0) $err = 'این شمارهٔ موبایل قبلاً در سامانه ثبت شده است.';
        }
        if ($err === '') {
        try {
            $st = db()->prepare('INSERT INTO users (name, username, email, phone, password_hash, role, is_active, created_at) VALUES (?,?,?,?,?,?,1,?)');
            $st->execute([
                $old['name'],
                $old['username'],
                $old['email'] !== '' ? $old['email'] : null,
                $phone98 !== '' ? $phone98 : null,
                password_hash($pass, PASSWORD_DEFAULT),
                'user',
                date('Y-m-d H:i:s'),
            ]);
            $st = db()->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([(int)db()->lastInsertId()]);
            $user = $st->fetch();
            login_record(client_ip(), $old['username'], true);
            auth_login($user, false);
            auth_log('register', $old['username']);
            header('Location: ./');
            exit;
        } catch (PDOException $e) {
            $err = ((int)$e->getCode() === 23000) ? 'این نام کاربری یا ایمیل قبلاً ثبت شده است.' : 'ثبت‌نام انجام نشد؛ دوباره تلاش کنید.';
        }
        }
    }
}

$siteName = (string)db_get('site_name', APP_TITLE);
require_once __DIR__ . '/../lib/authui.php';
auth_head('ثبت‌نام — ' . $siteName);
?>
<?php sprite(); ?>
<div class="lg" data-site="<?= h($siteName) ?>">
  <aside class="brand">
    <div class="brand-top"><span class="mark"><?= ic('cloud') ?></span><b><?= h($siteName) ?></b></div>
    <div class="brand-mid">
      <h2>در چند ثانیه حساب کاربری بسازید</h2>
      <p>کافی است نام و نام کاربری انتخاب کنید؛ بلافاصله فضای اختصاصی خود را روی سامانه دریافت می‌کنید.</p>
      <ul class="feats">
        <li><?= ic('folder') ?>فضای شخصی بر اساس سهمیهٔ اعلامی مدیر</li>
        <li><?= ic('upload') ?>آپلود فایل‌های حجیم تا ۵ گیگابایت به صورت چندبخشی</li>
        <li><?= ic('link') ?>لینک عمومی اختصاصی برای هر فایل</li>
        <li><?= ic('check') ?>منوی مدیریت فایل با پیش‌نمایش و تاریخچه</li>
      </ul>
    </div>
    <div class="brand-foot">حساب دارید؟ <a href="?page=login" style="color:#fff;font-weight:800">وارد شوید</a></div>
  </aside>

  <main class="formside">
    <?php if (!$allowSignup): ?>
      <div class="fcard">
        <div class="mark-sm"><?= ic('cloud') ?></div>
        <h1>ثبت‌نام بسته است</h1>
        <p class="lead">در حال حاضر امکان ساخت حساب جدید وجود ندارد.</p>
        <div class="banner m"><?= ic('gear') ?>برای ساخت حساب با مدیر سامانه تماس بگیرید.</div>
        <button type="button" class="lgbtn" onclick="location.href='?page=login'"><?= ic('logout') ?>بازگشت به ورود</button>
      </div>
    <?php else: ?>
      <div class="fcard wide<?= $err !== '' ? ' shake' : '' ?>" id="fcard">
        <div class="mark-sm"><?= ic('cloud') ?></div>
        <h1>ساخت حساب کاربری</h1>
        <p class="lead">برای شروع، حساب خود را در <?= h($siteName) ?> بسازید.</p>

        <?php if ($err !== ''): ?><div class="banner e"><?= ic('x') ?><?= h($err) ?></div><?php endif; ?>

        <form method="post" autocomplete="on" id="fForm">
          <input type="hidden" name="do" value="register">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

          <div class="fld">
            <label>نام و نام خانوادگی</label>
            <?= ic('user') ?><input name="name" value="<?= h($old['name']) ?>" placeholder="مثلاً علی محمدی" required autocomplete="name">
          </div>

          <div class="fld">
            <label>نام کاربری</label>
            <?= ic('user') ?><input name="username" value="<?= h($old['username']) ?>" placeholder="username" required autocomplete="username" dir="ltr" style="text-align:left">
          </div>

          <div class="fld">
            <label>ایمیل (اختیاری)</label>
            <?= ic('file') ?><input name="email" type="email" value="<?= h($old['email']) ?>" placeholder="you@example.com" autocomplete="email" dir="ltr" style="text-align:left">
          </div>

          <div class="fld">
            <label>شمارهٔ موبایل بله (برای ورود با کد یکبارمصرف)</label>
            <?= ic('key') ?><input name="phone" value="<?= h($old['phone']) ?>" placeholder="۰۹۱۲۳۴۵۶۷۸۹" autocomplete="tel" inputmode="tel" dir="ltr" style="text-align:left">
          </div>

          <div class="fld">
            <label>رمز عبور</label>
            <?= ic('key') ?>
            <input type="password" name="password" id="pw1" placeholder="حداقل ۸ نویسه" required autocomplete="new-password" dir="ltr" style="text-align:left">
            <button type="button" class="pw-toggle" data-pw="pw1" title="نمایش رمز"><?= ic('eye') ?></button>
          </div>
          <div class="pw-meter"><i id="pwBar"></i></div>
          <div class="pw-hint" id="pwHint">قدرت رمز عبور</div>

          <div class="fld">
            <label>تکرار رمز عبور</label>
            <?= ic('key') ?>
            <input type="password" name="password2" id="pw2" placeholder="تکرار رمز عبور" required autocomplete="new-password" dir="ltr" style="text-align:left">
            <button type="button" class="pw-toggle" data-pw="pw2" title="نمایش رمز"><?= ic('eye') ?></button>
          </div>

          <button class="lgbtn" id="rgBtn"><?= ic('plus') ?>ساخت حساب و ورود</button>
        </form>

        <div class="alt">حساب دارید؟ <a href="?page=login">وارد شوید</a></div>
      </div>
    <?php endif; ?>
  </main>
</div>

<script>
// نمایش/پنهان کردن هر دو فیلد رمز
document.querySelectorAll('.pw-toggle').forEach(function(b){
  b.onclick = function(){
    var inp = document.getElementById(b.getAttribute('data-pw'));
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    b.innerHTML = '<svg class="ic"><use href="#i-' + (show ? 'eye-off' : 'eye') + '"/></svg>';
    b.title = show ? 'پنهان کردن رمز' : 'نمایش رمز';
  };
});

// نشانگر قدرت رمز (فقط محاسبهٔ کلاینت‌ساید؛ اعتبارسنجی نهایی سمت سرور است)
var pw1 = document.getElementById('pw1'), bar = document.getElementById('pwBar'), hint = document.getElementById('pwHint');
if (pw1) {
  pw1.oninput = function(){
    var v = pw1.value, s = 0;
    if (v.length >= 8)  s += 34;
    if (v.length >= 12) s += 22;
    if (/[A-Za-z]/.test(v) && /\d/.test(v)) s += 22;
    if (/[^A-Za-z0-9]/.test(v)) s += 22;
    if (s > 100) s = 100;
    var col = s < 40 ? '#e11d48' : (s < 75 ? '#f59e0b' : '#059669');
    var txt = s === 0 ? 'قدرت رمز عبور' : (s < 40 ? 'ضعیف' : (s < 75 ? 'متوسط' : 'قوی'));
    if (document.getElementById('pw2') && document.getElementById('pw2').value && v !== document.getElementById('pw2').value) {
      txt = 'خطا: رمز و تکرار آن یکسان نیست'; col = '#e11d48';
    }
    bar.style.width = s + '%';
    bar.style.background = col;
    hint.textContent = txt;
    hint.style.color = col;
  };
  var pw2 = document.getElementById('pw2');
  pw2.oninput = function(){ pw1.oninput(); };
}

// حالت بارگذاری دکمهٔ ثبت‌نام
var form = document.getElementById('fForm'), btn = document.getElementById('rgBtn');
if (form) form.onsubmit = function(){
  btn.disabled = true;
  btn.innerHTML = 'در حال ساخت حساب…';
};
</script>
</body></html>
