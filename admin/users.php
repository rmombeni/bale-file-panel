<?php
/**
 * مدیریت کاربران — نمای مدرن با آمار، جست‌وجو و عملیات سریع.
 * ایجاد کاربر در user-new.php و نمای کامل هر کاربر در user.php?id=…
 */
require_once __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        adm_flash('درخواست نامعتبر بود.', 'err');
    } else {
        $do   = (string)($_POST['do'] ?? '');
        $uid  = (int)($_POST['id'] ?? 0);
        $self = (int)$me['id'];

        if ($do === 'toggle' && $uid && $uid !== $self) {
            db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$uid]);
            auth_log('user_toggle', (string)$uid);
            adm_flash('وضعیت حساب تغییر کرد.');
        }

        if ($do === 'role' && $uid && $uid !== $self) {
            $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $uid]);
            auth_log('user_role', (string)$uid, $role);
            adm_flash('نقش کاربر تغییر کرد.');
        }

        if ($do === 'defaultbot' && $uid) {
            $on = !empty($_POST['on']) ? 1 : 0;
            db()->prepare('UPDATE users SET use_default_bot = ? WHERE id = ?')->execute([$on, $uid]);
            auth_log('user_defaultbot', (string)$uid, (string)$on);
            adm_flash($on ? 'دسترسی به ربات پیش‌فرض فعال شد.' : 'دسترسی به ربات پیش‌فرض غیرفعال شد.');
        }

        if ($do === 'quota' && $uid) {
            $mb = max(0, (int)($_POST['quota_mb'] ?? 0));
            db()->prepare('UPDATE users SET quota_bytes = ? WHERE id = ?')->execute([$mb > 0 ? $mb * 1048576 : null, $uid]);
            auth_log('user_quota', (string)$uid, (string)$mb);
            adm_flash('سهمیهٔ کاربر ذخیره شد.');
        }

        if ($do === 'resetpass' && $uid) {
            $new = bin2hex(random_bytes(6));
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$uid]);
            auth_log('user_resetpass', (string)$uid);
            adm_flash('رمز جدید کاربر: ' . $new . ' — آن را در جای امنی نگه دارید.');
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
                adm_flash('حساب و فایل‌های آن حذف شد.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                adm_flash('حذف انجام نشد.', 'err');
            }
        }
    }
    header('Location: users.php');
    exit;
}

/* ---------- آمار و لیست ---------- */
$S = db()->query("SELECT
    COUNT(*) AS total,
    SUM(is_active = 1) AS active,
    SUM(role = 'admin') AS admins,
    SUM(is_active = 0) AS blocked,
    SUM(use_default_bot = 1) AS withbot
    FROM users")->fetch();

$q = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT u.*,
        (SELECT COUNT(*) FROM files f WHERE f.user_id = u.id) AS files_count,
        (SELECT COALESCE(SUM(size),0) FROM files f WHERE f.user_id = u.id) AS usage_bytes
        FROM users u';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE u.name LIKE ? OR u.username LIKE ? OR (u.email IS NOT NULL AND u.email LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}
$sql .= ' ORDER BY u.id';
$st = db()->prepare($sql);
$st->execute($params);
$users = $st->fetchAll();

$initial = function (string $name): string {
    $c = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
    return $c !== '' ? $c : '?';
};

adm_header('مدیریت کاربران', 'users');
?>
<div class="cards">
  <div class="stat-c"><span class="bx c1"><?= ic('users') ?></span><div><b><?= (int)$S['total'] ?></b><span>کل حساب‌ها</span></div></div>
  <div class="stat-c"><span class="bx c3"><?= ic('check') ?></span><div><b><?= (int)$S['active'] ?></b><span>فعال</span></div></div>
  <div class="stat-c"><span class="bx c2"><?= ic('shield') ?></span><div><b><?= (int)$S['admins'] ?></b><span>مدیر</span></div></div>
  <div class="stat-c"><span class="bx c6"><?= ic('x') ?></span><div><b><?= (int)$S['blocked'] ?></b><span>مسدود</span></div></div>
  <div class="stat-c"><span class="bx c4"><?= ic('zap') ?></span><div><b><?= (int)$S['withbot'] ?></b><span>دسترسی ربات پیش‌فرض</span></div></div>
</div>

<div class="card">
  <h2>
    <?= ic('users') ?>کاربران <span class="muted" style="font-size:12px;font-weight:600"><?= count($users) ?> حساب</span>
    <span class="sp"></span>
    <a class="btn p" href="user-new.php"><?= ic('plus') ?>کاربر جدید</a>
  </h2>
  <form method="get">
    <div class="sch"><?= ic('search') ?><input name="q" value="<?= h($q) ?>" placeholder="جست‌وجوی نام، نام کاربری یا ایمیل…"></div>
  </form>
  <div class="scrl">
    <table class="tbl">
      <thead><tr>
        <th>#</th><th>کاربر</th><th>نقش</th><th>وضعیت</th><th>ربات پیش‌فرض</th><th>فایل‌ها</th><th>مصرف / سهمیه</th><th>آخرین ورود</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$users): ?><tr><td colspan="9" class="muted" style="text-align:center;padding:24px">کاربری یافت نشد.</td></tr><?php endif; ?>
      <?php foreach ($users as $u): $uid = (int)$u['id']; $isSelf = $uid === (int)$me['id'];
            $qb = (int)$u['quota_bytes']; $used = (int)$u['usage_bytes']; ?>
        <tr>
          <td class="muted ltr" style="font-size:12px"><a href="user.php?id=<?= $uid ?>"><b>#<?= $uid ?></b></a></td>
          <td>
            <a href="user.php?id=<?= $uid ?>" style="display:flex;align-items:center;gap:10px">
              <span class="av"><?= $u['avatar'] ? '<img src="' . h(avatar_url($uid, (string)$u['avatar'])) . '" alt="">' : h($initial((string)$u['name'])) ?></span>
              <span style="min-width:0">
                <b style="display:block"><?= h($u['name']) ?></b>
                <span class="muted ltr" style="font-size:11px">@<?= h($u['username']) ?></span>
                <?php if (!empty($u['phone'])): ?><span class="muted ltr" style="font-size:11px;display:block"><?= h($u['phone']) ?></span>
                <?php elseif ($u['email']): ?><span class="muted ltr" style="font-size:11px;display:block"><?= h($u['email']) ?></span><?php endif; ?>
              </span>
            </a>
          </td>
          <td>
            <?php if ($isSelf): ?><span class="pill admin">مدیر (شما)</span>
            <?php else: ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="do" value="role">
                <input type="hidden" name="id" value="<?= $uid ?>">
                <input type="hidden" name="role" value="<?= $u['role'] === 'admin' ? 'user' : 'admin' ?>">
                <button class="pill <?= $u['role'] === 'admin' ? 'admin' : 'pick' ?>" title="تغییر نقش"><?= $u['role'] === 'admin' ? 'مدیر' : 'کاربر' ?></button>
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
                <button class="pill <?= (int)$u['is_active'] ? 'ok' : 'bad' ?> pick"><?= (int)$u['is_active'] ? 'فعال' : 'مسدود' ?></button>
              </form>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="do" value="defaultbot">
              <input type="hidden" name="id" value="<?= $uid ?>">
              <input type="hidden" name="on" value="<?= (int)$u['use_default_bot'] ? 0 : 1 ?>">
              <button class="pill <?= (int)$u['use_default_bot'] ? 'ok' : '' ?> pick" title="فعال/غیرفعال کردن ربات پیش‌فرض">
                <?= (int)$u['use_default_bot'] ? ic('check') . 'فعال' : ic('x') . 'غیرفعال' ?>
              </button>
            </form>
          </td>
          <td><?= (int)$u['files_count'] ?></td>
          <td>
            <div style="font-size:12px"><?= h(fmt_size($used)) ?><?= $qb > 0 ? ' <span class="muted">از ' . h(fmt_size($qb)) . '</span>' : '' ?></div>
            <?php if ($qb > 0): ?>
              <div class="prg"><div class="bar"><i style="width:<?= min(100, round($used / $qb * 100)) ?>%"></i></div><span class="muted" style="font-size:11px"><?= round($used / $qb * 100) ?>٪</span></div>
            <?php else: ?>
              <div class="prg"><div class="bar"><i style="width:<?= $used > 0 ? 100 : 0 ?>%"></i></div><span class="muted" style="font-size:11px">نامحدود</span></div>
            <?php endif; ?>
            <form method="post" style="display:flex;gap:4px;align-items:center;margin-top:6px">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="do" value="quota">
              <input type="hidden" name="id" value="<?= $uid ?>">
              <input name="quota_mb" type="number" min="0" value="<?= $qb > 0 ? (int)round($qb / 1048576) : 0 ?>" title="سهمیه به مگابایت (۰ = پیش‌فرض)"
                     style="width:70px;padding:4px 6px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:11px">
              <button class="btn sm" style="padding:3px 7px;font-size:11px" title="ذخیرهٔ سهمیه"><?= ic('check') ?></button>
            </form>
          </td>
          <td class="muted" style="font-size:11px"><?= $u['last_login_at'] ? h(fa_date((int)strtotime((string)$u['last_login_at']))) : '—' ?></td>
          <td class="right" style="white-space:nowrap">
            <a class="btn sm" href="user.php?id=<?= $uid ?>" title="نمای کامل"><?= ic('eye') ?></a>
            <form method="post" style="display:inline" onsubmit="return confirm('رمز «<?= h($u['username']) ?>» بازنشانی شود؟')">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="do" value="resetpass">
              <input type="hidden" name="id" value="<?= $uid ?>">
              <button class="btn sm" title="بازنشانی رمز"><?= ic('key') ?></button>
            </form>
            <?php if (!$isSelf): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('حساب «<?= h($u['name']) ?>» و فایل‌هایش حذف شود؟')">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="do" value="delete">
              <input type="hidden" name="id" value="<?= $uid ?>">
              <button class="btn sm dg" title="حذف"><?= ic('trash') ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php adm_footer();
