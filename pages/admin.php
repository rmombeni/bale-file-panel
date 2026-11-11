<?php
if (!defined('APP_RUNNING')) { http_response_code(404); exit('Not found'); }
if (!auth_is_admin()) { header('Location: ./'); exit; }

$me = auth_user(true);
$msg = null;
$msgType = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $msg = 'درخواست نامعتبر بود.';
        $msgType = 'err';
    } else {
        $do = (string)($_POST['do'] ?? '');
        $uid = (int)($_POST['id'] ?? 0);
        $self = (int)$me['id'];

        if ($do === 'toggle' && $uid && $uid !== $self) {
            db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$uid]);
            auth_log('user_toggle', (string)$uid);
            $msg = 'وضعیت حساب تغییر کرد.';
        }

        if ($do === 'role' && $uid && $uid !== $self) {
            $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $uid]);
            auth_log('user_role', (string)$uid, $role);
            $msg = 'نقش کاربر تغییر کرد.';
        }

        if ($do === 'quota' && $uid) {
            $mb = max(0, (int)($_POST['quota_mb'] ?? 0));
            $bytes = $mb > 0 ? $mb * 1024 * 1024 : null;
            db()->prepare('UPDATE users SET quota_bytes = ? WHERE id = ?')->execute([$bytes, $uid]);
            auth_log('user_quota', (string)$uid, (string)$mb);
            $msg = 'سهمیهٔ کاربر ذخیره شد.';
        }

        if ($do === 'delete' && $uid && $uid !== $self) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM files WHERE user_id = ?')->execute([$uid]);
                $pdo->prepare('DELETE FROM folders WHERE user_id = ?')->execute([$uid]);
                $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$uid]);
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                $pdo->commit();
                auth_log('user_delete', (string)$uid);
                $msg = 'حساب و فایل‌های آن حذف شد.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $msg = 'حذف انجام نشد.';
                $msgType = 'err';
            }
        }

        if ($do === 'global') {
            $site  = trim((string)($_POST['site_name'] ?? ''));
            $allow = !empty($_POST['allow_signup']) ? '1' : '0';
            $qmb   = max(0, (int)($_POST['default_quota_mb'] ?? 0));
            $token = trim((string)($_POST['bot_token'] ?? ''));
            $chat  = trim((string)($_POST['chat_id'] ?? ''));
            $send  = trim((string)($_POST['send_chat_id'] ?? ''));

            if ($site !== '') db_set('site_name', $site);
            db_set('allow_signup', $allow);
            db_set('default_quota', (string)($qmb * 1024 * 1024));
            if (!empty($_POST['clear_bot_token']))      db_set('bot_token', '');
            elseif (empty($_POST['keep_bot_token']) && $token !== '') db_set('bot_token', $token);
            if ($chat !== '') db_set('chat_id', $chat);
            db_set('send_chat_id', $send);
            auth_log('global_settings');
            $msg = 'تنظیمات سراسری ذخیره شد.';
        }
    }
}

$users = db()->query(
    'SELECT u.*,
            (SELECT COUNT(*) FROM files f WHERE f.user_id = u.id) AS files_count,
            (SELECT COALESCE(SUM(size),0) FROM files f WHERE f.user_id = u.id) AS usage_bytes
     FROM users u ORDER BY u.id'
)->fetchAll();

$logs = db()->query(
    'SELECT a.*, u.username FROM activity a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 40'
)->fetchAll();

$gToken = (string)db_get('bot_token', '');
$maskToken = $gToken !== '' ? (substr($gToken, 0, 6) . '…' . substr($gToken, -4)) : '—';
$defaultQuotaMb = (int)round(((int)db_get('default_quota', '0')) / 1048576);

$actionFa = [
    'login' => 'ورود', 'register' => 'ثبت‌نام', 'profile' => 'پروفایل', 'password' => 'تغییر رمز',
    'settings' => 'تنظیمات', 'mkdir' => 'پوشه جدید', 'rmdir' => 'حذف پوشه', 'renamedir' => 'تغییر نام پوشه',
    'delete' => 'حذف فایل', 'rename' => 'تغییر نام فایل', 'move' => 'انتقال', 'newlink' => 'لینک جدید',
    'send' => 'ارسال به بله', 'user_toggle' => 'تغییر وضعیت کاربر', 'user_role' => 'تغییر نقش',
    'user_quota' => 'سهمیه', 'user_delete' => 'حذف کاربر', 'global_settings' => 'تنظیمات سراسری',
];

ui_start('مدیریت — ' . APP_TITLE);
ui_topbar($me, 'admin');
?>
<div class="wrap">
  <h1>مدیریت کاربران</h1>
  <p class="lead"><?= count($users) ?> حساب ثبت‌شده · ثبت‌نام <?= db_get('allow_signup', '1') === '1' ? 'باز' : 'بسته' ?> است.</p>
  <?php ui_alert($msg, $msgType); ?>

  <div class="card">
    <h2>کاربران</h2>
    <div style="overflow:auto">
    <table class="tbl">
      <thead><tr>
        <th>#</th><th>کاربر</th><th>نقش</th><th>وضعیت</th><th>فایل</th><th>حجم</th><th>آخرین ورود</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($users as $u): $uid = (int)$u['id']; $isSelf = $uid === (int)$me['id']; ?>
        <tr>
          <td class="muted"><?= $uid ?></td>
          <td>
            <b><?= h($u['name']) ?></b>
            <div class="muted" dir="ltr" style="font-size:11px">@<?= h($u['username']) ?><?= $u['email'] ? ' · ' . h($u['email']) : '' ?></div>
          </td>
          <td>
            <?php if ($isSelf): ?><span class="pill admin">مدیر (شما)</span>
            <?php else: ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="do" value="role">
                <input type="hidden" name="id" value="<?= $uid ?>">
                <input type="hidden" name="role" value="<?= $u['role'] === 'admin' ? 'user' : 'admin' ?>">
                <button class="pill <?= $u['role'] === 'admin' ? 'admin' : '' ?>" style="border:0;cursor:pointer" title="تغییر نقش"><?= $u['role'] === 'admin' ? 'مدیر' : 'کاربر' ?></button>
              </form>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($isSelf): ?><span class="pill ok">فعال</span>
            <?php else: ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="do" value="toggle">
                <input type="hidden" name="id" value="<?= $uid ?>">
                <button class="pill <?= (int)$u['is_active'] ? 'ok' : 'bad' ?>" style="border:0;cursor:pointer"><?= (int)$u['is_active'] ? 'فعال' : 'مسدود' ?></button>
              </form>
            <?php endif; ?>
          </td>
          <td><?= (int)$u['files_count'] ?></td>
          <td>
            <div><?= h(fmt_size((int)$u['usage_bytes'])) ?></div>
            <form method="post" style="display:flex;gap:4px;margin-top:4px">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="do" value="quota">
              <input type="hidden" name="id" value="<?= $uid ?>">
              <input name="quota_mb" type="number" min="0" value="<?= (int)round(((int)$u['quota_bytes']) / 1048576) ?>" title="سهمیه به مگابایت (۰ = پیش‌فرض)"
                     style="width:66px;padding:3px 6px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:11px">
              <button class="btn sm" style="padding:3px 7px;font-size:11px" title="ذخیرهٔ سهمیه">✓</button>
            </form>
          </td>
          <td class="muted" style="font-size:11px"><?= $u['last_login_at'] ? h(fa_date((int)strtotime((string)$u['last_login_at']))) : '—' ?></td>
          <td class="right">
            <?php if (!$isSelf): ?>
            <form method="post" onsubmit="return confirm('حساب «<?= h($u['name']) ?>» و فایل‌هایش حذف شود؟')">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="do" value="delete">
              <input type="hidden" name="id" value="<?= $uid ?>">
              <button class="btn sm" style="color:var(--danger)">حذف</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="card">
    <h2>تنظیمات سراسری</h2>
    <form method="post">
      <input type="hidden" name="do" value="global">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <div class="row2">
        <div class="field">
          <label for="site_name">نام سامانه</label>
          <input id="site_name" name="site_name" value="<?= h((string)db_get('site_name', APP_TITLE)) ?>">
        </div>
        <div class="field">
          <label for="default_quota_mb">سهمیهٔ پیش‌فرض هر کاربر (مگابایت — ۰ = نامحدود)</label>
          <input id="default_quota_mb" name="default_quota_mb" type="number" min="0" value="<?= $defaultQuotaMb ?>">
        </div>
      </div>
      <div class="field">
        <label><input type="checkbox" name="allow_signup" value="1" <?= db_get('allow_signup', '1') === '1' ? 'checked' : '' ?> style="width:auto;margin-inline-end:6px"> اجازهٔ ثبت‌نام آزاد</label>
      </div>
      <div class="field">
        <label for="bot_token">توکن ربات پیش‌فرض</label>
        <div class="pwf" data-keep="keep_bot_token">
          <input id="bot_token" name="bot_token" type="password" dir="ltr" autocomplete="off" spellcheck="false" value="<?= h($gToken) ?>" <?= $gToken !== '' ? 'readonly' : '' ?> placeholder="<?= $gToken !== '' ? '••••••••••••' : '123456:ABC-DEF…' ?>">
          <span class="acts">
            <button type="button" class="ey" data-pw="bot_token" title="نمایش توکن"><?= ic('eye') ?></button>
            <?php if ($gToken !== ''): ?><button type="button" class="ed" data-pw="bot_token" title="ویرایش توکن"><?= ic('edit') ?></button>
            <button type="button" class="dl" data-pw="bot_token" data-clear="clear_bot_token" title="حذف توکن"><?= ic('trash') ?></button><?php endif; ?>
          </span>
          <input type="hidden" name="keep_bot_token" value=""><input type="hidden" name="clear_bot_token" value="">
        </div>
        <?php if ($gToken !== ''): ?>
          <div class="hint">فعلی: <span dir="ltr"><?= h($maskToken) ?></span> — چشم: نمایش کامل · مداد: تغییر · سطل: حذف.</div>
        <?php endif; ?>
      </div>
      <div class="row2">
        <div class="field">
          <label for="chat_id">شناسهٔ چت پیش‌فرض</label>
          <input id="chat_id" name="chat_id" value="<?= h((string)db_get('chat_id', '')) ?>" dir="ltr" style="text-align:left">
        </div>
        <div class="field">
          <label for="send_chat_id">چت ارسال پیش‌فرض (اختیاری)</label>
          <input id="send_chat_id" name="send_chat_id" value="<?= h((string)db_get('send_chat_id', '')) ?>" dir="ltr" style="text-align:left">
        </div>
      </div>
      <button class="btn p">ذخیرهٔ تنظیمات سراسری</button>
    </form>
  </div>

  <div class="card">
    <h2>گزارش فعالیت</h2>
    <table class="tbl">
      <thead><tr><th>زمان</th><th>کاربر</th><th>کار</th><th>هدف</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$logs): ?>
        <tr><td colspan="5" class="muted">فعالیتی ثبت نشده است.</td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td class="muted" style="font-size:11px;white-space:nowrap"><?= h(fa_date((int)strtotime((string)$l['created_at']))) ?></td>
          <td><?= h((string)($l['username'] ?? '—')) ?></td>
          <td><?= h($actionFa[$l['action']] ?? $l['action']) ?></td>
          <td class="muted" style="font-size:11px"><?= h((string)$l['target']) ?></td>
          <td class="muted" dir="ltr" style="font-size:11px"><?= h((string)$l['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php ui_secret_js(); ui_end();
