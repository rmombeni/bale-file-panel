<?php
/**
 * فایل‌های سامانه — نمای مدرن مشابه سرویس‌های اشتراک فایل:
 * فیلتر نوع/مالک/جست‌وجو، مرتب‌سازی، صفحه‌بندی، کپی لینک، دانلود مستقیم و حذف.
 */
require_once __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        adm_flash('درخواست نامعتبر بود.', 'err');
    } elseif (($_POST['do'] ?? '') === 'delete') {
        $st = db()->prepare('SELECT * FROM files WHERE id = ? LIMIT 1');
        $st->execute([(string)($_POST['id'] ?? '')]);
        $file = $st->fetch();
        if ($file) {
            $ctx = storage_for_user((int)$file['user_id']);
            $parts = !empty($file['parts']) ? (json_decode((string)$file['parts'], true) ?: []) : [];
            $mids = [];
            if ($parts) foreach ($parts as $p) if (!empty($p['message_id'])) $mids[] = $p['message_id'];
            elseif ($file['message_id']) $mids[] = (int)$file['message_id'];
            foreach ($mids as $mid) adm_api('deleteMessage', ['chat_id' => $ctx['chat'], 'message_id' => $mid], $ctx);
            db()->prepare('DELETE FROM files WHERE id = ?')->execute([(string)$file['id']]);
            auth_log('admin_file_delete', (string)$file['name']);
            adm_flash('فایل «' . $file['name'] . '» حذف شد.');
        } else {
            adm_flash('فایل پیدا نشد.', 'err');
        }
    }
    header('Location: files.php');
    exit;
}

/* ---------- فیلترها ---------- */
$q    = trim((string)($_GET['q'] ?? ''));
$uSel = (int)($_GET['u'] ?? 0);
$kSel = (string)($_GET['k'] ?? 'all');
$sort = (string)($_GET['s'] ?? 'date');
$per  = 30;
$p    = max(1, (int)($_GET['p'] ?? 1));

/* انواع فایل مشابه پنل اصلی */
$kindExts = [
    'image'   => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg', 'ico'],
    'video'   => ['mp4', 'm4v', 'webm', 'mov', 'ogv', 'mkv', 'avi', 'wmv', 'flv', '3gp'],
    'audio'   => ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac', 'wma'],
    'pdf'     => ['pdf'],
    'doc'     => ['txt', 'md', 'log', 'csv', 'json', 'xml', 'ini', 'yml', 'yaml', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'],
    'archive' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'],
];
$kinds = $kSel !== 'all' && isset($kindExts[$kSel]) ? [$kSel => $kindExts[$kSel]] : [];

$where = []; $params = [];
if ($q !== '') { $where[] = 'f.name LIKE ?'; $params[] = '%' . $q . '%'; }
if ($uSel > 0) { $where[] = 'f.user_id = ?'; $params[] = $uSel; }
foreach ($kinds as $exts) {
    $ors = [];
    foreach ($exts as $i) { $ors[] = 'LOWER(SUBSTRING_INDEX(f.name, ".", -1)) = ?'; $params[] = $i; }
    if ($ors) $where[] = '(' . implode(' OR ', $ors) . ')';
}
$W = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$st = db()->prepare('SELECT COUNT(*) FROM files f' . $W);
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$p = min($p, $pages);

$order = ['date' => 'f.created_at DESC', 'size' => 'f.size DESC', 'name' => 'f.name ASC', 'owner' => 'u.username ASC'][$sort] ?? 'f.created_at DESC';
$sql = 'SELECT f.*, u.username AS owner, u.name AS owner_name, u.avatar AS owner_avatar FROM files f LEFT JOIN users u ON u.id = f.user_id'
     . $W . ' ORDER BY ' . $order . ' LIMIT ' . $per . ' OFFSET ' . (($p - 1) * $per);
$st = db()->prepare($sql);
$st->execute($params);
$files = $st->fetchAll();

/* آمار کلی */
$S = db()->query("SELECT COUNT(*) AS n, COALESCE(SUM(size),0) AS sz, COALESCE(AVG(size),0) AS avg,
    SUM(parts IS NOT NULL AND parts <> '') AS chunked, MAX(created_at) AS last
    FROM files")->fetch();
$totalSize = (int)$S['sz'];

$allUsers = db()->query('SELECT id, name, username, avatar FROM users ORDER BY name')->fetchAll();

$kindIcon = ['image' => 'image', 'video' => 'zap', 'audio' => 'clock', 'pdf' => 'doc', 'doc' => 'doc', 'archive' => 'drive', 'other' => 'file'];
$kindColor = ['image' => 'c5', 'video' => 'c1', 'audio' => 'c4', 'pdf' => 'c6', 'doc' => 'c2', 'archive' => 'c3', 'other' => 'c6'];
$kindOf = function (string $name): string {
    $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($e, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg', 'ico'], true)) return 'image';
    if (in_array($e, ['mp4', 'm4v', 'webm', 'mov', 'ogv', 'mkv', 'avi', 'wmv', 'flv', '3gp'], true)) return 'video';
    if (in_array($e, ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac', 'wma'], true)) return 'audio';
    if ($e === 'pdf') return 'pdf';
    if (in_array($e, ['txt', 'md', 'log', 'csv', 'json', 'xml', 'ini', 'yml', 'yaml', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'], true)) return 'doc';
    if (in_array($e, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'], true)) return 'archive';
    return 'other';
};

/* ساخت لینک فیلتر حفظ‌شده */
$qs = function (array $over = []) use ($q, $uSel, $kSel, $sort): string {
    $a = array_filter(['q' => $q, 'u' => $uSel ?: null, 'k' => $kSel !== 'all' ? $kSel : null, 's' => $sort !== 'date' ? $sort : null], fn($v) => $v !== null && $v !== '');
    $a = array_merge($a, $over);
    return $a ? '?' . http_build_query($a) : '';
};

adm_header('فایل‌های سامانه', 'files');
$rootUrl = adm_base_url();
?>
<script>
function admCopy(t){
  var done = function(){
    var d = document.createElement('div');
    d.className = 'alert ok'; d.textContent = 'لینک کپی شد';
    var c = document.querySelector('.ct'); c.prepend(d);
    setTimeout(function(){ d.remove(); }, 1800);
  };
  if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(done, function(){});
  else {
    var a = document.createElement('textarea'); a.value = t; a.style.position = 'fixed'; a.style.opacity = '0';
    document.body.appendChild(a); a.select();
    try { document.execCommand('copy'); done(); } catch (e) {}
    a.remove();
  }
}
</script>

<div class="cards">
  <div class="stat-c"><span class="bx c2"><?= ic('folder') ?></span><div><b><?= (int)$S['n'] ?></b><span>کل فایل‌ها</span></div></div>
  <div class="stat-c"><span class="bx c3"><?= ic('drive') ?></span><div><b><?= h(fmt_size($totalSize)) ?></b><span>حجم کل</span></div></div>
  <div class="stat-c"><span class="bx c1"><?= ic('doc') ?></span><div><b><?= h(fmt_size((int)$S['avg'])) ?></b><span>میانگین حجم</span></div></div>
  <div class="stat-c"><span class="bx c4"><?= ic('drive') ?></span><div><b><?= (int)$S['chunked'] ?></b><span>فایل چندبخشی</span></div></div>
  <div class="stat-c"><span class="bx c5"><?= ic('clock') ?></span><div><b><?= $S['last'] ? h(fa_date((int)strtotime((string)$S['last']), 'date')) : '—' ?></b><span>آخرین آپلود</span></div></div>
</div>

<form method="get" id="flt">
  <input type="hidden" name="k" value="<?= h($kSel) ?>" id="hk">
  <input type="hidden" name="s" value="<?= h($sort) ?>" id="hs">
  <div class="card" style="padding:14px">
    <div class="row2" style="grid-template-columns:2fr 1fr">
      <div class="sch" style="margin:0"><?= ic('search') ?><input name="q" value="<?= h($q) ?>" placeholder="جست‌وجوی نام فایل در سراسر سامانه…"></div>
      <div class="field" style="margin:0">
        <select name="u" onchange="this.form.submit()">
          <option value="0">همهٔ کاربران</option>
          <?php foreach ($allUsers as $ou): ?>
            <option value="<?= (int)$ou['id'] ?>" <?= $uSel === (int)$ou['id'] ? 'selected' : '' ?>><?= h($ou['name']) ?> (@<?= h($ou['username']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="chips" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px">
      <?php foreach (['all' => 'همه', 'image' => 'تصاویر', 'video' => 'ویدیو', 'audio' => 'صوت', 'pdf' => 'PDF', 'doc' => 'اسناد', 'archive' => 'آرشیو'] as $k => $label): ?>
        <button type="button" class="chip<?= $kSel === $k ? ' on' : '' ?>" onclick="document.getElementById('hk').value='<?= $k ?>';document.getElementById('flt').submit()"><?= $label ?></button>
      <?php endforeach; ?>
      <span style="flex:1"></span>
      <select onchange="document.getElementById('hs').value=this.value;document.getElementById('flt').submit()" style="padding:8px 12px;border-radius:10px;border:1px solid var(--border);background:var(--surface)">
        <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>جدیدترین</option>
        <option value="size" <?= $sort === 'size' ? 'selected' : '' ?>>بزرگ‌ترین</option>
        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>نام (الفبا)</option>
        <option value="owner" <?= $sort === 'owner' ? 'selected' : '' ?>>مالک</option>
      </select>
    </div>
  </div>
</form>

<div class="card">
  <h2><?= ic('folder') ?>نتیجه‌ها <span class="muted" style="font-size:12px;font-weight:600"><?= $total ?> فایل</span></h2>
  <?php if (!$files): ?>
    <div class="empty"><?= ic('search') ?><h3 style="margin:8px 0 2px">فایلی یافت نشد</h3><div>فیلترها را تغییر دهید.</div></div>
  <?php else: ?>
  <div class="scrl">
    <table class="tbl">
      <thead><tr><th>فایل</th><th>مالک</th><th>پوشه</th><th>حجم</th><th>تاریخ</th><th class="right">عملیات</th></tr></thead>
      <tbody>
      <?php foreach ($files as $f):
          $k = $kindOf((string)$f['name']);
          $chunked = !empty($f['parts']);
          $share = $rootUrl . '?s=' . urlencode((string)$f['share']);
          $dl = $rootUrl . '?dl=' . urlencode((string)$f['id']);
      ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <span class="bx-k <?= $kindColor[$k] ?>" style="display:grid;place-items:center;width:38px;height:38px;border-radius:12px;color:#fff;flex:none"><svg class="ic"><use href="#i-<?= $kindIcon[$k] ?>"/></svg></span>
              <div style="min-width:0">
                <div class="nm" title="<?= h($f['name']) ?>"><?= h($f['name']) ?></div>
                <div style="font-size:11px">
                  <span class="muted ltr"><?= strtoupper(pathinfo((string)$f['name'], PATHINFO_EXTENSION) ?: '—') ?></span>
                  <?php if ($chunked): ?><span class="pill" style="margin-inline-start:4px">چندبخشی</span><?php endif; ?>
                </div>
              </div>
            </div>
          </td>
          <td>
            <a href="user.php?id=<?= (int)$f['user_id'] ?>" style="display:flex;align-items:center;gap:8px">
              <span class="av" style="width:28px;height:28px;font-size:11px"><?= $f['owner_avatar'] ? '<img src="' . h(avatar_url((int)$f['user_id'], (string)$f['owner_avatar'])) . '">' : h($f['owner_name'] !== null ? substr((string)$f['owner_name'], 0, 1) : '?') ?></span>
              <div><b style="font-size:12px"><?= h((string)($f['owner_name'] ?? '—')) ?></b><div class="muted ltr" style="font-size:10px">@<?= h((string)($f['owner'] ?? '?')) ?></div></div>
            </a>
          </td>
          <td class="muted" style="font-size:12px"><?= $f['folder'] !== '' ? h($f['folder']) : '—' ?></td>
          <td style="font-size:12px"><b><?= h(fmt_size((int)$f['size'])) ?></b></td>
          <td class="muted" style="font-size:11px;white-space:nowrap"><?= h(fa_date((int)strtotime((string)$f['created_at']))) ?></td>
          <td class="right" style="white-space:nowrap">
            <button class="btn sm" title="کپی لینک اشتراک" onclick="admCopy('<?= h($share) ?>')"><?= ic('copy') ?></button>
            <a class="btn sm" href="<?= h($share) ?>" target="_blank" title="صفحهٔ اشتراک"><?= ic('external') ?></a>
            <a class="btn sm" href="<?= h($dl) ?>" title="دانلود مستقیم"><?= ic('download') ?></a>
            <form method="post" style="display:inline" onsubmit="return confirm('فایل «<?= h($f['name']) ?>» حذف شود؟')">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="do" value="delete">
              <input type="hidden" name="id" value="<?= h($f['id']) ?>">
              <button class="btn sm dg" title="حذف قطعی"><?= ic('trash') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pgn">
    <?php if ($p > 1): ?><a href="<?= h($qs(['p' => $p - 1])) ?>">قبلی</a><?php else: ?><span class="muted">قبلی</span><?php endif; ?>
    <span class="on"><?= $p ?> از <?= $pages ?></span>
    <?php if ($p < $pages): ?><a href="<?= h($qs(['p' => $p + 1])) ?>">بعدی</a><?php else: ?><span class="muted">بعدی</span><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php adm_footer();
