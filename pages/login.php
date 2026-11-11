<?php
/**
 * صفحهٔ ورود — چیدمان دوستونه حرفه‌ای: پنل معرفی برند + فرم ورود.
 * دو روش ورود: رمز عبور / کد یکبارمصرف بله (سفیر).
 */
if (!defined('APP_RUNNING')) { http_response_code(404); exit('Not found'); }

$err = '';
/* ---------- ورود با رمز عبور ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'login') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $err = 'درخواست نامعتبر بود؛ دوباره تلاش کنید.';
    } elseif (login_blocked(client_ip())) {
        $err = 'تلاش‌های ناموفق زیاد بوده است؛ چند دقیقه بعد دوباره تلاش کنید.';
    } else {
        $login = trim((string)($_POST['username'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');
        $st = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$login, $login]);
        $u = $st->fetch();

        if ($u && password_verify($pass, (string)$u['password_hash'])) {
            if (db_get('maintenance', '0') === '1' && $u['role'] !== 'admin') {
                $err = 'سامانه در حالت تعمیر است؛ ورود موقتاً غیرفعال می‌باشد.';
            } elseif (!(int)$u['is_active']) {
                $err = 'این حساب غیرفعال شده است. با مدیر تماس بگیرید.';
            } else {
                if (password_needs_rehash((string)$u['password_hash'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                        ->execute([password_hash($pass, PASSWORD_DEFAULT), (int)$u['id']]);
                }
                login_record(client_ip(), $login, true);
                auth_login($u, !empty($_POST['remember']));
                header('Location: ./');
                exit;
            }
        } else {
            login_record(client_ip(), $login, false);
            $err = 'نام کاربری یا رمز عبور اشتباه است.';
        }
    }
}

/* ---------- ورود با کد یکبارمصرف بله (سفیر) ---------- */
$otpEnabled   = db_get('otp_login', '0') === '1';
$otpSent      = false;
$otpPhoneMask = '';
$otpStep      = !empty($_SESSION['otp_phone']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $otpEnabled) {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $err = 'درخواست نامعتبر بود؛ دوباره تلاش کنید.';
    } elseif (($_POST['do'] ?? '') === 'otp_request') {
        /* مرحلهٔ ۱: درخواست ارسال کد به موبایل */
        if (login_blocked(client_ip())) {
            $err = 'تلاش‌های ناموفق زیاد بوده است؛ چند دقیقه بعد دوباره تلاش کنید.';
        } else {
            $phone = norm_phone((string)($_POST['phone'] ?? ''));
            if ($phone === '') {
                $err = 'شمارهٔ موبایل معتبر نیست (مثال: ۰۹۱۲۳۴۵۶۷۸۹).';
            } else {
                $st = db()->prepare('SELECT * FROM users WHERE phone IN (?, ?) AND is_active = 1 LIMIT 1');
                $st->execute([$phone, pretty_phone($phone)]);
                $u = $st->fetch();
                if (!$u) {
                    /* ثبت‌نام خودکار با شمارهٔ بله — نام خالی یعنی «نیاز به تکمیل اطلاعات» */
                    if (db_get('maintenance', '0') === '1') {
                        $err = 'سامانه در حالت تعمیر است؛ ورود موقتاً غیرفعال می‌باشد.';
                    } elseif (db_get('allow_signup', '1') !== '1') {
                        $err = 'این شماره در سامانه ثبت نشده است و ثبت‌نام آزاد نیز بسته است.';
                    } else {
                        $usern = 'u' . $phone;
                        for ($i = 0; $i < 20; $i++) {
                            $st = db()->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
                            $st->execute([$usern]);
                            if (!$st->fetchColumn()) break;
                            $usern = 'u' . $phone . ($i + 1);
                        }
                        try {
                            $st = db()->prepare('INSERT INTO users (name, username, phone, password_hash, role, is_active, created_at) VALUES (?,?,?,?,?,1,?)');
                            $st->execute(['', $usern, $phone, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'user', date('Y-m-d H:i:s')]);
                            $st = db()->prepare('SELECT * FROM users WHERE id = ?');
                            $st->execute([(int)db()->lastInsertId()]);
                            $u = $st->fetch();
                            auth_log('register', $usern, 'otp_auto');
                        } catch (PDOException $e) {
                            $err = 'ساخت خودکار حساب انجام نشد؛ دوباره تلاش کنید.';
                        }
                    }
                }
                if ($u && $err === '') {
                    if (db_get('maintenance', '0') === '1' && $u['role'] !== 'admin') {
                        $err = 'سامانه در حالت تعمیر است؛ ورود موقتاً غیرفعال می‌باشد.';
                    } else {
                        [$ok, $gen] = otp_issue($phone, (int)$u['id'], client_ip());
                        if (!$ok) {
                            $err = $gen;
                        } else {
                            [$sent, $safir] = safir_send_otp($phone, $gen);
                            if (!$sent) {
                                $err = 'ارسال کد در بله ناموفق بود: ' . (is_string($safir) ? $safir : 'خطای نامشخص');
                            } else {
                                $_SESSION['otp_phone'] = $phone;
                                $_SESSION['otp_issued_at'] = time();
                                $otpStep  = true;
                                $otpSent  = true;
                                $otpPhoneMask = '0' . substr($phone, 2, 4) . ' ••• ' . substr($phone, -3);
                                auth_log('otp_request', $phone);
                            }
                        }
                    }
                }
            }
        }
    } elseif (($_POST['do'] ?? '') === 'otp_verify') {
        /* مرحلهٔ ۲: تأیید کد و ورود */
        $phone = (string)($_SESSION['otp_phone'] ?? '');
        if ($phone === '') {
            $err = 'کد درخواست نشده است؛ نخست کد را درخواست کنید.';
        } elseif (login_blocked(client_ip())) {
            $err = 'تلاش‌های ناموفق زیاد بوده است؛ چند دقیقه بعد دوباره تلاش کنید.';
        } else {
            $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
            if ($code === '') {
                $err = 'کد را وارد کنید.';
            } else {
                [$ok, $res] = otp_verify($phone, $code);
                if (!$ok) {
                    login_record(client_ip(), pretty_phone($phone), false);
                    $err = $res;
                } else {
                    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
                    $st->execute([(int)$res]);
                    $u = $st->fetch();
                    if (!$u) {
                        $err = 'این حساب یافت نشد یا غیرفعال است.';
                    } else {
                        unset($_SESSION['otp_phone']);
                        login_record(client_ip(), (string)$u['username'], true);
                        auth_login($u, !empty($_POST['remember']));
                        header('Location: ./');
                        exit;
                    }
                }
            }
        }
    }
}

$otpStep   = $otpStep && !empty($_SESSION['otp_phone']);
$otpPhoneMask = $otpStep ? '0' . substr((string)$_SESSION['otp_phone'], 2, 4) . ' ••• ' . substr((string)$_SESSION['otp_phone'], -3) : '';
$otpTtl      = $otpStep ? max(120, (int)db_get('otp_ttl', '300')) : 0;
$otpIssuedAt = $otpStep ? (int)($_SESSION['otp_issued_at'] ?? time()) : 0;
$fillUser  = ($err !== '' && ($_POST['do'] ?? '') === 'login') ? (string)($_POST['username'] ?? '') : '';
$fillPhone = ($err !== '' && ($_POST['do'] ?? '') === 'otp_request') ? (string)($_POST['phone'] ?? '') : '';
$maintenance = db_get('maintenance', '0') === '1';

$allowSignup = db_get('allow_signup', '1') === '1';
$siteName    = (string)db_get('site_name', APP_TITLE);

require_once __DIR__ . '/../lib/authui.php';
auth_head('ورود — ' . $siteName);
?>
<?php sprite(); ?>
<div class="lg" data-site="<?= h($siteName) ?>">
  <aside class="brand">
    <div class="brand-top"><span class="mark"><?= ic('cloud') ?></span><b><?= h($siteName) ?></b></div>
    <div class="brand-mid">
      <h2>ذخیره‌سازی امن و اشتراک‌گذاری آسان فایل‌ها روی بله</h2>
      <p>فایل‌های خود را با چند کلیک آپلود کنید؛ همه‌چیز روی سرورهای بله نگه‌داری می‌شود و فقط با لینک اشتراک به اشتراک گذاشته می‌شود.</p>
      <ul class="feats">
        <li><?= ic('upload') ?>آپلود چندبخشی فایل‌های بزرگ تا ۵ گیگابایت</li>
        <li><?= ic('link') ?>لینک اشتراک اختصاصی و قابل ابطال برای هر فایل</li>
        <li><?= ic('cloud') ?>ذخیره‌سازی مستقیم روی ربات بله، بدون دیسک اضافه</li>
        <li><?= ic('check') ?>ورود با کد یکبارمصرف بله — بدون رمز عبور</li>
      </ul>
    </div>
    <div class="brand-foot"><?= h($siteName) ?> · نسخه <?= h(APP_VERSION) ?> · مالکیت کامل داده‌ها با شماست</div>
  </aside>

  <main class="formside">
    <div class="fcard<?= $err !== '' ? ' shake' : '' ?>" id="fcard">
      <div class="mark-sm"><?= ic('cloud') ?></div>
      <h1>ورود به <?= h($siteName) ?></h1>
      <p class="lead">برای ادامه وارد حساب کاربری خود شوید.</p>

      <?php if ($maintenance && !$otpStep): ?><div class="banner m"><?= ic('gear') ?>سامانه در حالت تعمیر است؛ فقط مدیران می‌توانند وارد شوند.</div><?php endif; ?>
      <?php if ($err !== ''): ?><div class="banner e"><?= ic('x') ?><?= h($err) ?></div><?php endif; ?>
      <?php if ($otpSent): ?><div class="banner m"><?= ic('check') ?>کد یکبارمصرف به بلهٔ شما (<?= h($otpPhoneMask) ?>) ارسال شد؛ در بازوی «رمز یکبارمصرف بله» ببینید.</div><?php endif; ?>

      <?php if (!$otpStep): ?>
        <?php if ($otpEnabled): ?>
          <div class="modes" role="tablist" aria-label="روش ورود">
            <button type="button" class="mode-btn on" data-m="pw" role="tab" aria-selected="true"><?= ic('key') ?>ورود با رمز</button>
            <button type="button" class="mode-btn" data-m="otp" role="tab" aria-selected="false"><?= ic('send') ?>ورود با کد بله</button>
          </div>
        <?php endif; ?>

        <form method="post" autocomplete="on" id="fPw" class="ml on">
          <input type="hidden" name="do" value="login">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <div class="fld">
            <?= ic('user') ?><input name="username" value="<?= h($fillUser) ?>" placeholder="نام کاربری یا ایمیل" required autocomplete="username" dir="ltr" style="text-align:left">
          </div>
          <div class="fld">
            <?= ic('key') ?>
            <input type="password" name="password" id="pw" placeholder="رمز عبور" required autocomplete="current-password" dir="ltr" style="text-align:left">
            <button type="button" class="pw-toggle" id="pwBtn" title="نمایش رمز"><?= ic('eye') ?></button>
          </div>
          <div class="frow">
            <label><input type="checkbox" name="remember" value="1"> مرا به خاطر بسپار</label>
            <span style="color:var(--muted)">ورود با نام کاربری یا ایمیل</span>
          </div>
          <button class="lgbtn" id="lgBtn"><?= ic('logout') ?>ورود به حساب</button>
        </form>

        <?php if ($otpEnabled): ?>
          <form method="post" autocomplete="off" id="fOtp" class="ml">
            <input type="hidden" name="do" value="otp_request">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <div class="fld">
              <?= ic('user') ?><input name="phone" value="<?= h($fillPhone) ?>" placeholder="۰۹۱۲۳۴۵۶۷۸۹" required autocomplete="tel" inputmode="tel" dir="ltr" style="text-align:left">
            </div>
            <div class="frow" style="justify-content:flex-start">
              <span style="color:var(--muted)">کد یکبارمصرف در بازوی «رمز یکبارمصرف بله» به این شماره ارسال می‌شود؛ اگر حساب نداشته باشید، حساب شما خودکار ساخته می‌شود.</span>
            </div>
            <button class="lgbtn" id="otpBtn"><?= ic('send') ?>ارسال کد ورود</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="do" value="otp_verify">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <div class="fld">
            <label for="ob0">کد ۶ رقمی ارسال‌شده به <?= h($otpPhoneMask) ?></label>
            <div class="otpwrap" dir="ltr">
              <?php for ($i = 0; $i < 6; $i++): ?>
                <input class="otpbox" <?= $i === 0 ? 'id="ob0" autocomplete="one-time-code"' : 'id="ob' . $i . '"' ?> maxlength="1" inputmode="numeric" pattern="[0-9]" <?= $i === 0 ? 'autofocus' : '' ?>>
              <?php endfor; ?>
              <input type="hidden" name="code" id="otpCode">
            </div>
          </div>
          <div class="frow">
            <label><input type="checkbox" name="remember" value="1"> مرا به خاطر بسپار</label>
          </div>
          <div class="otp-actions">
            <span class="otp-timer" id="otpTimer"></span>
            <button type="button" class="resend" id="resendBtn" disabled><?= ic('refresh') ?><span>ارسال دوبارهٔ کد</span></button>
          </div>
          <button class="lgbtn" id="otpBtn2"><?= ic('check') ?>تأیید کد و ورود</button>
        </form>
        <form method="post" id="chgReq">
          <input type="hidden" name="do" value="otp_request">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="phone" value="<?= h(pretty_phone((string)$_SESSION['otp_phone'])) ?>">
        </form>
        <div class="alt"><a href="?page=login" id="backLink">بازگشت به روش‌های دیگر</a></div>
      <?php endif; ?>

      <?php if (!$otpStep): ?>
        <div class="alt">
          <?php if ($allowSignup): ?>
            حساب ندارید؟ <a href="?page=register">ثبت‌نام کنید</a>
          <?php else: ?>
            ثبت‌نام بسته است؛ برای ساخت حساب با مدیر تماس بگیرید.
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<script>
/* ---------- نمایش/پنهان رمز ---------- */
var pw = document.getElementById('pw'), pb = document.getElementById('pwBtn');
if (pb) pb.onclick = function(){
  var show = pw.type === 'password';
  pw.type = show ? 'text' : 'password';
  pb.innerHTML = '<svg class="ic"><use href="#i-' + (show ? 'eye-off' : 'eye') + '"/></svg>';
  pb.title = show ? 'پنهان کردن رمز' : 'نمایش رمز';
  pw.focus();
};

/* ---------- سوییچ روش ورود ---------- */
document.querySelectorAll('.mode-btn').forEach(function(b){
  b.onclick = function(){
    document.querySelectorAll('.mode-btn').forEach(function(x){ x.classList.remove('on'); x.setAttribute('aria-selected','false'); });
    b.classList.add('on'); b.setAttribute('aria-selected','true');
    document.querySelectorAll('form.ml').forEach(function(f){ f.classList.toggle('on', f.id.toLowerCase() === ('f' + b.getAttribute('data-m')).toLowerCase()); });
  };
});

/* ---------- حالت بارگذاری دکمه‌ها ---------- */
document.querySelectorAll('form').forEach(function(f){
  f.addEventListener('submit', function(){
    var btn = f.querySelector('button.lgbtn, button[type=submit]');
    if (btn) { btn.disabled = true; btn.textContent = 'در حال انجام…'; }
  });
});

/* ---------- جعبه‌های کد یکبارمصرف + تایمر ---------- */
var boxes = Array.prototype.slice.call(document.querySelectorAll('.otpbox'));
var codeField = document.getElementById('otpCode');
if (boxes.length && codeField) {
  var sync = function () {
    codeField.value = boxes.map(function (b) { return b.value.replace(/\D/g, ''); }).join('');
    boxes.forEach(function (b) { b.classList.toggle('filled', b.value !== ''); });
  };
  boxes.forEach(function (b, i) {
    b.addEventListener('input', function () {
      b.value = b.value.replace(/\D/g, '').slice(-1);
      if (b.value !== '' && i < boxes.length - 1) boxes[i + 1].focus();
      sync();
      if (boxes.every(function (x) { return x.value !== ''; })) document.getElementById('otpBtn2').focus();
    });
    b.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && b.value === '' && i > 0) { boxes[i - 1].focus(); boxes[i - 1].value = ''; sync(); e.preventDefault(); }
      if (e.key === 'ArrowLeft' && i > 0) boxes[i - 1].focus();
      if (e.key === 'ArrowRight' && i < boxes.length - 1) boxes[i + 1].focus();
    });
    b.addEventListener('paste', function (e) {
      var t = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, boxes.length);
      if (!t) return;
      e.preventDefault();
      boxes.forEach(function (x, j) { x.value = t[j] || ''; });
      boxes[Math.min(t.length, boxes.length - 1)].focus();
      sync();
    });
  });
  boxes[0].focus();

  /* تایمر اعتبار کد + فعال‌سازی ارسال دوباره */
  var TTL = <?= (int)$otpTtl ?>, ISSUED = <?= (int)$otpIssuedAt ?> * 1000, WAIT = 60;
  var timerEl = document.getElementById('otpTimer'), rbtn = document.getElementById('resendBtn');
  var fa = function (n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
  var tick = function () {
    var left = Math.max(0, TTL - Math.floor((Date.now() - ISSUED) / 1000));
    var waitLeft = Math.max(0, WAIT - Math.floor((Date.now() - ISSUED) / 1000));
    if (left > 0) {
      var m = Math.floor(left / 60), s = left % 60;
      timerEl.className = 'otp-timer' + (left <= 30 ? ' warn' : '');
      timerEl.innerHTML = '<svg class="ic"><use href="#i-refresh"/></svg>کد تا ' + fa(m) + ':' + fa(s < 10 ? '0' + s : s) + ' دیگر معتبر است';
    } else {
      timerEl.className = 'otp-timer warn';
      timerEl.innerHTML = '<svg class="ic"><use href="#i-x"/></svg>کد منقضی شده است؛ دوباره ارسال کنید';
    }
    rbtn.disabled = waitLeft > 0;
    if (waitLeft > 0) rbtn.querySelector('span').textContent = 'ارسال دوبارهٔ کد (' + fa(waitLeft) + ' ثانیه)';
    else rbtn.querySelector('span').textContent = 'ارسال دوبارهٔ کد';
  };
  tick();
  setInterval(tick, 1000);
}
if (rb) rb.onclick = function(){ document.getElementById('chgReq').submit(); };

/* ---------- فوکوس خودکار روی کد ---------- */
var oc = document.getElementById('otpCode');
if (oc) oc.focus();
</script>
</body></html>
