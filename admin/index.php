<?php
/**
 * داشبورد مدیریت — آمار کلی، کاربران اخیر، فعالیت اخیر و بیشترین مصرف فضا.
 */
require_once __DIR__ . '/bootstrap.php';

$stats = [
    'users'  => (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'active' => (int)db()->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn(),
    'admins' => (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
    'files'  => (int)db()->query('SELECT COUNT(*) FROM files')->fetchColumn(),
    'size'   => (int)db()->query('SELECT COALESCE(SUM(size),0) FROM files')->fetchColumn(),
    'today'  => (int)db()->query("SELECT COUNT(*) FROM activity WHERE action = 'login' AND created_at >= CURDATE()")->fetchColumn(),
];

$topUsers = db()->query(
    'SELECT u.id, u.name, u.username, COUNT(f.id) AS fc, COALESCE(SUM(f.size),0) AS sz
     FROM users u LEFT JOIN files f ON f.user_id = u.id
     GROUP BY u.id, u.name, u.username ORDER BY sz DESC LIMIT 5'
)->fetchAll();
$maxSize = max(1, (int)($topUsers[0]['sz'] ?? 1));

$recentUsers = db()->query('SELECT * FROM users ORDER BY id DESC LIMIT 5')->fetchAll();

$recentLogs = db()->query(
    'SELECT a.*, u.username FROM activity a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8'
)->fetchAll();

adm_header('داشبورد', 'index');
?>
<div class="cards">
  <div class="stat-c"><span class="bx c1"><?= ic('users') ?></span><div><b><?= $stats['users'] ?></b><span>کاربر ثبت‌شده</span><small><?= $stats['active'] ?> فعال · <?= $stats['admins'] ?> مدیر</small></div></div>
  <div class="stat-c"><span class="bx c2"><?= ic('folder') ?></span><div><b><?= $stats['files'] ?></b><span>فایل ذخیره‌شده</span><small>روی سرور بله</small></div></div>
  <div class="stat-c"><span class="bx c3"><?= ic('drive') ?></span><div><b><?= h(fmt_size($stats['size'])) ?></b><span>حجم کل</span></div></div>
  <div class="stat-c"><span class="bx c4"><?= ic('zap') ?></span><div><b><?= $stats['today'] ?></b><span>ورود امروز</span></div></div>
  <div class="stat-c"><span class="bx c5"><?= ic('user') ?></span><div><b><?= db_get('allow_signup', '1') === '1' ? 'باز' : 'بسته' ?></b><span>وضعیت ثبت‌نام</span></div></div>
</div>

<div class="grid2">
  <div>
    <div class="card">
      <h2><?= ic('activity') ?>فعالیت اخیر <span class="sp"></span><a class="btn sm" href="logs.php">همه</a></h2>
      <div class="scrl">
        <table class="tbl">
          <thead><tr><th>زمان</th><th>کاربر</th><th>رخداد</th></tr></thead>
          <tbody>
          <?php if (!$recentLogs): ?><tr><td colspan="3" class="muted">فعالیتی ثبت نشده است.</td></tr><?php endif; ?>
          <?php foreach ($recentLogs as $l): ?>
            <tr>
              <td class="muted" style="font-size:11px"><?= h(fa_date((int)strtotime((string)$l['created_at']))) ?></td>
              <td><?= h((string)($l['username'] ?? 'مهمان')) ?></td>
              <td><?= h(adm_action_fa((string)$l['action'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h2><?= ic('drive') ?>بیشترین فضای مصرفی</h2>
      <?php if (!$topUsers): ?>
        <div class="empty">داده‌ای نیست.</div>
      <?php else: foreach ($topUsers as $u): $w = round(((int)$u['sz']) / $maxSize * 100); ?>
        <div class="kv">
          <span style="color:var(--text);font-weight:600"><?= h($u['name']) ?> <span class="muted ltr" style="font-size:11px">@<?= h($u['username']) ?></span></span>
          <span><?= (int)$u['fc'] ?> فایل · <b style="color:var(--text)"><?= h(fmt_size((int)$u['sz'])) ?></b></span>
        </div>
        <div class="bar" style="margin:2px 0 10px"><i style="width:<?= $w ?>%"></i></div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div>
    <div class="card">
      <h2><?= ic('users') ?>کاربران اخیر <span class="sp"></span><a class="btn sm" href="users.php">مدیریت</a></h2>
      <div class="scrl">
        <table class="tbl">
          <tbody>
          <?php foreach ($recentUsers as $u): ?>
            <tr>
              <td><b><?= h($u['name']) ?></b><div class="muted ltr" style="font-size:11px">@<?= h($u['username']) ?></div></td>
              <td>
                <span class="pill <?= $u['role'] === 'admin' ? 'admin' : '' ?>"><?= $u['role'] === 'admin' ? 'مدیر' : 'کاربر' ?></span>
                <?php if (!(int)$u['is_active']): ?><span class="pill bad">مسدود</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h2><?= ic('gear') ?>دسترسی سریع</h2>
      <div class="kv"><span>افزودن کاربر جدید</span><a class="btn sm" href="users.php">کاربران</a></div>
      <div class="kv"><span>تنظیم توکن و چت پیش‌فرض</span><a class="btn sm" href="settings.php">تنظیمات</a></div>
      <div class="kv"><span>مشاهده و حذف فایل‌ها</span><a class="btn sm" href="files.php">فایل‌ها</a></div>
    </div>
  </div>
</div>
<?php adm_footer();
