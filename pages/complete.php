<?php
/**
 * تکمیل اطلاعات حساب — برای حساب‌هایی که خودکار با «ورود با کد بله» ساخته شده‌اند
 * (نام خالی است). تا تکمیل نشود، دسترسی به پنل ممکن نیست.
 */
if (!defined('APP_RUNNING')) { http_response_code(404); exit('Not found'); }

$me = auth_user();
if (!$me) { header('Location: ./?page=login'); exit; }
if (trim((string)$me['name']) !== '') { header('Location: ./'); exit; }

$err = '';
$old = ['name' => '', 'username' => '', 'email' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'complete') {
    $old['name']     = trim((string)($_POST['name'] ?? ''));
    $old['username'] = trim((string)($_POST['username'] ?? ''));
    $old['email']    = trim((string)($_POST['email'] ?? ''));
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
    } elseif ($pass !== '' && ($perr = auth_validate_password($pass)) !== null) {
        $err = $perr;
    } elseif ($pass !== '' && $pass !== $pass2) {
        $err = 'رمز عبور و تکرار آن یکسان نیستند.';
    } else {
        $st = db()->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ? LIMIT 1');
        $st->execute([$old['username'], (int)$me['id']]);
        if ($st->fetchColumn()) {
            $err = 'این نام کاربری برای حساب دیگری استفاده شده است.';
        } else {
            try {
                if ($old['email'] !== '') {
                    $st = db()->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ? LIMIT 1');
                    $st->execute([$old['email'], (int)$me['id']]);
                    if ($st->fetchColumn()) $err = 'این ایمیل برای حساب دیگری ثبت شده است.';
                }
                if ($err === '') {
                    $sql = 'UPDATE users SET name = ?, username = ?, email = ?';
                    $args = [$old['name'], $old['username'], $old['email'] !== '' ? $old['email'] : null];
                    if ($pass !== '') {
                        $sql .= ', password_hash = ?';
                        $args[] = password_hash($pass, PASSWORD_DEFAULT);
                    }
                    $sql .= ' WHERE id = ?';
                    $args[] = (int)$me['id'];
                    db()->prepare($sql)->execute($args);
                    auth_log('profile', $old['username'], 'completed');
                    header('Location: ./');
                    exit;
                }
            } catch (PDOException $e) {
                $err = ((int)$e->getCode() === 23000) ? 'این نام کاربری یا ایمیل قبلاً ثبت شده است.' : 'ذخیره انجام نشد؛ دوباره تلاش کنید.';
            }
        }
    }
}

$siteName = (string)db_get('site_name', APP_TITLE);
require_once __DIR__ . '/../lib/authui.php';
auth_head('تکمیل اطلاعات — ' . $siteName);
?>
<?php sprite(); ?>
<div class="lg" data-site="<?= h($siteName) ?>">
  <aside class="brand">
    <div class="brand-top"><span class="mark"><?= ic('cloud') ?></span><b><?= h($siteName) ?></b></div>
    <div class="brand-mid">
      <h2>حساب شما ساخته شد؛ فقط چند قدم تا شروع</h2>
      <p>برای استفاده از پنل، نام و نام کاربری خود را مشخص کنید. این اطلاعات در پنل و اشتراک فایل‌ها نمایش داده می‌شود.</p>
      <ul class="feats">
        <li><?= ic('check') ?>ورود بعدی فقط با کد یکبارمصرف بله، بدون رمز</li>
        <li><?= ic('key') ?>رمز عبور اختیاری است — برای ورود کلاسیک می‌توانید تعیین کنید</li>
        <li><?= ic('edit') ?>بقیهٔ اطلاعات بعداً از «تنظیمات» قابل ویرایش است</li>
      </ul>
    </div>
    <div class="brand-foot"><?= h($siteName) ?> · نسخه <?= h(APP_VERSION) ?></div>
  </aside>

  <main class="formside">
    <div class="fcard<?= $err !== '' ? ' shake' : '' ?>" id="fcard">
      <div class="mark-sm"><?= ic('cloud') ?></div>
      <h1>تکمیل اطلاعات حساب</h1>
      <p class="lead">حساب شما با شمارهٔ بله <?= $me['phone'] ? h(pretty_phone((string)$me['phone'])) : '—' ?> ساخته شده است.</p>

      <?php if ($err !== ''): ?><div class="banner e"><?= ic('x') ?><?= h($err) ?></div><?php endif; ?>

      <form method="post" autocomplete="on" id="fForm">
        <input type="hidden" name="do" value="complete">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

        <div class="fld">
          <label>نام و نام خانوادگی</label>
          <?= ic('user') ?><input name="name" value="<?= h($old['name']) ?>" placeholder="مثلاً علی محمدی" required autocomplete="name">
        </div>

        <div class="fld">
          <label>نام کاربری</label>
          <?= ic('user') ?><input name="username" value="<?= h($old['username']) ?>" placeholder="نام کاربری دلخواه (لاتین)" required autocomplete="username" dir="ltr" style="text-align:left">
        </div>

        <div class="fld">
          <label>ایمیل (اختیاری)</label>
          <?= ic('file') ?><input name="email" type="email" value="<?= h($old['email']) ?>" placeholder="you@example.com" autocomplete="email" dir="ltr" style="text-align:left">
        </div>

        <div class="fld">
          <label>رمز عبور (اختیاری — برای ورود با نام کاربری)</label>
          <?= ic('key') ?>
          <input type="password" name="password" id="pw1" placeholder="حداقل ۸ نویسه — خالی بگذارید اگر نمی‌خواهید" autocomplete="new-password" dir="ltr" style="text-align:left">
          <button type="button" class="pw-toggle" data-pw="pw1" title="نمایش رمز"><?= ic('eye') ?></button>
        </div>
        <div class="fld">
          <label>تکرار رمز عبور</label>
          <?= ic('key') ?>
          <input type="password" name="password2" id="pw2" placeholder="تکرار رمز عبور" autocomplete="new-password" dir="ltr" style="text-align:left">
          <button type="button" class="pw-toggle" data-pw="pw2" title="نمایش رمز"><?= ic('eye') ?></button>
        </div>

        <button class="lgbtn" id="cpBtn"><?= ic('check') ?>ذخیره و ورود به پنل</button>
      </form>

      <div class="alt"><a href="?logout=1">خروج از حساب</a></div>
    </div>
  </main>
</div>

<script>
document.querySelectorAll('.pw-toggle').forEach(function(b){
  b.onclick = function(){
    var inp = document.getElementById(b.getAttribute('data-pw'));
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    b.innerHTML = '<svg class="ic"><use href="#i-' + (show ? 'eye-off' : 'eye') + '"/></svg>';
    b.title = show ? 'پنهان کردن رمز' : 'نمایش رمز';
  };
});
var form = document.getElementById('fForm'), btn = document.getElementById('cpBtn');
if (form) form.onsubmit = function(){
  var p1 = document.getElementById('pw1').value, p2 = document.getElementById('pw2').value;
  if (p1 !== '' && p1 !== p2) { alert('رمز عبور و تکرار آن یکسان نیستند.'); return false; }
  btn.disabled = true;
  btn.innerHTML = 'در حال ذخیره…';
};
</script>
</body></html>
