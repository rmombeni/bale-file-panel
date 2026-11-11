<?php
/**
 * گزارش فعالیت — آمار کلی، فیلتر پیشرفته (رخداد/کاربر/جست‌وجو/بازهٔ زمانی)،
 * جزئیات هر رخداد در پنجره، خروجی CSV، پاک‌سازی کامل یا قدیمی‌ها و صفحه‌بندی عددی.
 */
require_once __DIR__ . '/bootstrap.php';

/* ---------- دسته‌بندی رنگ و آیکن هر رخداد ---------- */
function log_cat(string $a): array {
    static $m = [
        'login' => ['key','ok'], 'register' => ['user','ok'], 'upload' => ['upload','ok'],
        'mkdir' => ['folder','ok'], 'newlink' => ['link','ok'], 'send' => ['send','ok'],
        'delete' => ['trash','bad'], 'rmdir' => ['trash','bad'], 'user_delete' => ['trash','bad'],
        'admin_file_delete' => ['trash','bad'], 'logs_clear' => ['trash','bad'], 'attempts_clear' => ['trash','bad'],
        'settings' => ['gear','admin'], 'global_settings' => ['gear','admin'], 'bot_test' => ['zap','admin'],
        'otp_request' => ['key','admin'], 'safir_test' => ['zap','admin'],
        'user_create' => ['plus','admin'], 'user_toggle' => ['users','admin'], 'user_role' => ['users','admin'],
        'user_quota' => ['users','admin'], 'user_resetpass' => ['key','admin'], 'user_defaultbot' => ['cloud','admin'],
        'user_storage' => ['drive','admin'], 'user_profile' => ['edit','admin'], 'user_avatar' => ['image','admin'],
        'user_sessions_out' => ['logout','admin'],
    ];
    return $m[$a] ?? ['activity',''];
}

function fa_num($s): string {
    return strtr((string)$s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}

function rel_time(int $ts): string {
    $d = time() - $ts;
    if ($d < 60)     return 'لحظاتی پیش';
    if ($d < 3600)   return fa_num((int)floor($d / 60)) . ' دقیقه پیش';
    if ($d < 86400)  return fa_num((int)floor($d / 3600)) . ' ساعت پیش';
    if ($d < 604800) return fa_num((int)floor($d / 86400)) . ' روز پیش';
    return '';
}

/* ---------- عملیات‌ها ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        adm_flash('درخواست نامعتبر بود.', 'err');
    } elseif (($_POST['do'] ?? '') === 'clear') {
        db()->exec('TRUNCATE TABLE activity');
        auth_log('logs_clear');
        adm_flash('گزارش فعالیت کامل پاک شد.');
    } elseif (($_POST['do'] ?? '') === 'prune') {
        $days = (int)($_POST['days'] ?? 0);
        if (!in_array($days, [30, 90, 365], true)) $days = 90;
        $st = db()->prepare('DELETE FROM activity WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)');
        $st->execute();
        adm_flash(fa_num((int)$st->rowCount()) . ' رخداد قدیمی‌تر از ' . fa_num($days) . ' روز حذف شد.');
        auth_log('logs_clear', 'prune:' . $days);
    }
    header('Location: logs.php');
    exit;
}

/* ---------- فیلترها ---------- */
$a     = trim((string)($_GET['a'] ?? ''));
$uFlt  = (int)($_GET['u'] ?? 0);
$q     = trim((string)($_GET['q'] ?? ''));
$range = (string)($_GET['range'] ?? 'all');
$per   = (int)($_GET['per'] ?? 30);
if (!in_array($per, [30, 50, 100, 200], true)) $per = 30;
if (!in_array($range, ['all', 'today', '24h', '7d', '30d'], true)) $range = 'all';

$where = []; $params = [];
if ($a !== '')      { $where[] = 'a.action = ?'; $params[] = $a; }
if ($uFlt > 0)      { $where[] = 'a.user_id = ?'; $params[] = $uFlt; }
if ($q !== '') {
    $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\%', '_' => '\_']) . '%';
    $where[] = '(a.target LIKE ? OR a.meta LIKE ? OR a.ip LIKE ? OR a.action LIKE ? OR u.username LIKE ? OR u.name LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($range === 'today') { $where[] = 'a.created_at >= ?'; $params[] = date('Y-m-d 00:00:00'); }
elseif ($range === '24h') { $where[] = 'a.created_at >= ?'; $params[] = date('Y-m-d H:i:s', time() - 86400); }
elseif ($range === '7d')  { $where[] = 'a.created_at >= ?'; $params[] = date('Y-m-d H:i:s', time() - 7 * 86400); }
elseif ($range === '30d') { $where[] = 'a.created_at >= ?'; $params[] = date('Y-m-d H:i:s', time() - 30 * 86400); }
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

/* ---------- خروجی CSV (با همان فیلترها) ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    $st = db()->prepare('SELECT a.*, u.username, u.name FROM activity a LEFT JOIN users u ON u.id = a.user_id' . $whereSql . ' ORDER BY a.id DESC LIMIT 10000');
    $st->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=activity-' . date('Ymd-His') . '.csv');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'زمان', 'کاربر', 'نام', 'رخداد', 'عنوان رخداد', 'هدف', 'جزئیات', 'IP']);
    foreach ($st->fetchAll() as $l) {
        fputcsv($out, [
            $l['id'], (string)$l['created_at'], (string)($l['username'] ?? ''), (string)($l['name'] ?? ''),
            (string)$l['action'], adm_action_fa((string)$l['action']), (string)$l['target'], (string)$l['meta'], (string)$l['ip'],
        ]);
    }
    fclose($out);
    exit;
}

/* ---------- شمارش و داده‌ها ---------- */
$st = db()->prepare('SELECT COUNT(*) FROM activity a LEFT JOIN users u ON u.id = a.user_id' . $whereSql);
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$p = min(max(1, (int)($_GET['p'] ?? 1)), $pages);

$sql = 'SELECT a.*, u.username, u.name FROM activity a LEFT JOIN users u ON u.id = a.user_id'
     . $whereSql . ' ORDER BY a.id DESC LIMIT ' . $per . ' OFFSET ' . (($p - 1) * $per);
$st = db()->prepare($sql);
$st->execute($params);
$logs = $st->fetchAll();

$actionOpts = db()->query('SELECT action, COUNT(*) AS c FROM activity GROUP BY action ORDER BY c DESC')->fetchAll();
$userOpts = db()->query('SELECT DISTINCT a.user_id, u.username, u.name FROM activity a LEFT JOIN users u ON u.id = a.user_id WHERE a.user_id IS NOT NULL ORDER BY u.username')->fetchAll();

$today = (int)db()->query('SELECT COUNT(*) FROM activity WHERE created_at >= ' . db()->quote(date('Y-m-d 00:00:00')))->fetchColumn();
$week  = (int)db()->query('SELECT COUNT(*) FROM activity WHERE created_at >= ' . db()->quote(date('Y-m-d H:i:s', time() - 7 * 86400)))->fetchColumn();
$actUs = (int)db()->query('SELECT COUNT(DISTINCT user_id) FROM activity WHERE user_id IS NOT NULL AND created_at >= ' . db()->quote(date('Y-m-d H:i:s', time() - 7 * 86400)))->fetchColumn();

/* ---------- ساخت لینک با حفظ فیلترها ---------- */
$base = array_filter(['a' => $a, 'u' => $uFlt ?: '', 'q' => $q, 'range' => $range !== 'all' ? $range : '', 'per' => $per !== 30 ? $per : ''], fn($v) => $v !== '' && $v !== null);
function lg_url(array $base, array $over = []): string { $m = array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null); return '?' . http_build_query($m); }

adm_header('گزارش فعالیت', 'logs');
?>
<style>
.fltbar{display:grid;grid-template-columns:minmax(200px,1.4fr) repeat(3,minmax(130px,1fr));gap:10px;margin:14px 0}
.fltbar .sch{margin:0}
.fltbar select{width:100%;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:var(--surface);outline:none;font-size:12.5px;cursor:pointer}
.fltbar select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--weak)}
.rng{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px}
.lg-row{cursor:pointer}
.lg-row td{transition:.1s}
.who{display:flex;align-items:center;gap:9px}
.who .av{width:30px;height:30px;font-size:12px}
.who b{font-size:12.5px;display:block;line-height:1.4}
.who small{color:var(--muted);font-size:11px}
.tgt{max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tgt small{display:block;color:var(--muted);font-size:11px;max-width:300px;overflow:hidden;text-overflow:ellipsis}
.rel{color:var(--muted);font-size:10.5px}
.ipc{display:inline-flex;align-items:center;gap:4px}
.ipc .cpbtn{border:0;background:transparent;cursor:pointer;color:var(--muted);display:inline-grid;place-items:center;padding:3px;border-radius:7px}
.ipc .cpbtn:hover{background:var(--surface2);color:var(--primary)}
.ipc .cpbtn .ic{width:13px;height:13px}
.mkv{display:flex;gap:10px;padding:9px 2px;border-bottom:1px dashed var(--border);font-size:13px}
.mkv span{color:var(--muted);min-width:96px;flex:none}
.mkv b{word-break:break-word}
.modal{position:fixed;inset:0;background:#02061773;z-index:90;display:none;place-items:center;padding:16px}
.modal.on{display:grid}
.modal .mbox{width:min(480px,100%);background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:0 30px 80px #0007;overflow:hidden}
.modal .mhead{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border);font-weight:800;font-size:14px}
.modal .mhead .ib{margin-inline-start:auto}
.modal .mbody{padding:12px 18px 18px}
.dots{border:0;background:transparent;padding:7px 4px;color:var(--muted);font-weight:800}
@media(max-width:900px){.fltbar{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.fltbar{grid-template-columns:1fr}}
</style>

<div class="cards">
  <div class="stat-c"><span class="bx c1"><?= ic('activity') ?></span><div><b><?= fa_num($total) ?></b><span>کل رخدادها</span></div></div>
  <div class="stat-c"><span class="bx c3"><?= ic('clock') ?></span><div><b><?= fa_num($today) ?></b><span>رخدادهای امروز</span></div></div>
  <div class="stat-c"><span class="bx c2"><?= ic('activity') ?></span><div><b><?= fa_num($week) ?></b><span>۷ روز گذشته</span></div></div>
  <div class="stat-c"><span class="bx c4"><?= ic('users') ?></span><div><b><?= fa_num($actUs) ?></b><span>کاربران فعال (۷ روز)</span></div></div>
</div>

<div class="card">
  <h2>
    <?= ic('activity') ?>رخدادها <span class="pill"><?= fa_num($total) ?> مورد</span>
    <span class="sp"></span>
    <a class="btn sm" href="<?= h(lg_url($base, ['export' => 'csv'])) ?>" title="خروجی CSV با فیلترهای فعلی"><?= ic('download') ?>خروجی CSV</a>
    <form method="post" style="display:inline" onsubmit="return confirm('رخدادهای قدیمی‌تر از بازهٔ انتخاب‌شده حذف شود؟')">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="do" value="prune">
      <select name="days" style="padding:6px 10px;border-radius:10px;border:1px solid var(--border);background:var(--surface);font-size:12px;margin-inline-start:6px">
        <option value="30">قدیمی‌تر از ۳۰ روز</option>
        <option value="90">قدیمی‌تر از ۹۰ روز</option>
        <option value="365">قدیمی‌تر از ۱ سال</option>
      </select>
      <button class="btn sm"><?= ic('clock') ?>حذف قدیمی‌ها</button>
    </form>
    <form method="post" style="display:inline" onsubmit="return confirm('همهٔ گزارش فعالیت برای همیشه پاک شود؟')">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="do" value="clear">
      <button class="btn sm dg"><?= ic('trash') ?>پاک‌سازی کامل</button>
    </form>
  </h2>

  <div class="rng">
    <?php
    $ranges = ['all' => 'همهٔ زمان‌ها', 'today' => 'امروز', '24h' => '۲۴ ساعت', '7d' => '۷ روز', '30d' => '۳۰ روز'];
    foreach ($ranges as $k => $lbl): ?>
      <a class="chip <?= $range === $k ? 'on' : '' ?>" href="<?= h(lg_url($base, ['range' => $k === 'all' ? '' : $k, 'p' => ''])) ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>

  <form method="get" id="fltForm">
    <?php if ($range !== 'all'): ?><input type="hidden" name="range" value="<?= h($range) ?>"><?php endif; ?>
    <div class="fltbar">
      <div class="sch"><?= ic('search') ?><input name="q" value="<?= h($q) ?>" placeholder="جست‌وجو در هدف، جزئیات، IP، کاربر…"></div>
      <select name="a" onchange="this.form.submit()">
        <option value="">همهٔ رخدادها</option>
        <?php foreach ($actionOpts as $o): ?>
          <option value="<?= h($o['action']) ?>" <?= $a === $o['action'] ? 'selected' : '' ?>><?= h(adm_action_fa((string)$o['action'])) ?> (<?= fa_num((int)$o['c']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <select name="u" onchange="this.form.submit()">
        <option value="">همهٔ کاربران</option>
        <?php foreach ($userOpts as $o): ?>
          <option value="<?= (int)$o['user_id'] ?>" <?= $uFlt === (int)$o['user_id'] ? 'selected' : '' ?>><?= h((string)($o['name'] ?: $o['username'])) ?> (<?= h((string)$o['username']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <select name="per" onchange="this.form.submit()">
        <?php foreach ([30, 50, 100, 200] as $n): ?>
          <option value="<?= $n ?>" <?= $per === $n ? 'selected' : '' ?>><?= fa_num($n) ?> رخداد در صفحه</option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <div class="scrl">
    <table class="tbl">
      <thead><tr><th>زمان</th><th>کاربر</th><th>رخداد</th><th>هدف / جزئیات</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$logs): ?>
        <tr><td colspan="5"><div class="empty" style="border:0"><?= ic('activity') ?><h3>رخدادی یافت نشد</h3><p>فیلترها را تغییر دهید یا بازهٔ زمانی را بازتر کنید.</p></div></td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $l): $ts = (int)strtotime((string)$l['created_at']); [$ai, $cl] = log_cat((string)$l['action']); $rel = rel_time($ts); ?>
        <tr class="lg-row" data-row="<?= h(json_encode([
            'z' => fa_date($ts), 'u' => (string)($l['name'] ?: $l['username'] ?: 'مهمان'),
            'un' => (string)($l['username'] ?: '—'), 'a' => adm_action_fa((string)$l['action']),
            'raw' => (string)$l['action'], 't' => (string)$l['target'], 'm' => (string)$l['meta'], 'ip' => (string)$l['ip'],
        ], JSON_UNESCAPED_UNICODE)) ?>">
          <td style="font-size:11.5px;line-height:1.7"><?= h(fa_date($ts)) ?><?php if ($rel !== ''): ?><br><span class="rel"><?= $rel ?></span><?php endif; ?></td>
          <td><div class="who">
            <span class="av"><?= $l['username'] !== null && $l['username'] !== '' ? h(mb_strtoupper(mb_substr((string)$l['username'], 0, 1))) : '؟' ?></span>
            <div><b><?= h((string)($l['name'] ?: $l['username'] ?: 'مهمان')) ?></b><small dir="ltr"><?= $l['username'] ? '@' . h((string)$l['username']) : 'کاربر ناشناس' ?></small></div>
          </div></td>
          <td><span class="pill <?= $cl ?>"><?= ic($ai) ?><?= h(adm_action_fa((string)$l['action'])) ?></span></td>
          <td><div class="tgt" title="<?= h((string)$l['target']) ?>"><?= h((string)$l['target'] !== '' ? $l['target'] : '—') ?><?php if ((string)$l['meta'] !== ''): ?><small title="<?= h((string)$l['meta']) ?>">جزئیات: <?= h((string)$l['meta']) ?></small><?php endif; ?></div></td>
          <td class="ltr" style="font-size:11px"><span class="ipc"><?= h((string)($l['ip'] ?: '—')) ?><?php if ((string)$l['ip'] !== ''): ?><button type="button" class="cpbtn" title="کپی IP" onclick="event.stopPropagation();admCp(this,'<?= h((string)$l['ip']) ?>')"><?= ic('copy') ?></button><?php endif; ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1):
      $start = max(1, $p - 2); $end = min($pages, $p + 2); ?>
  <div class="pgn">
    <?php if ($p > 1): ?><a href="<?= h(lg_url($base, ['p' => $p - 1])) ?>">قبلی</a><?php else: ?><span class="muted">قبلی</span><?php endif; ?>
    <?php if ($start > 1): ?><a href="<?= h(lg_url($base, ['p' => 1])) ?>">۱</a><?php endif; ?>
    <?php if ($start > 2): ?><span class="dots">…</span><?php endif; ?>
    <?php for ($i = $start; $i <= $end; $i++): ?>
      <?php if ($i === $p): ?><span class="on"><?= fa_num($i) ?></span><?php else: ?><a href="<?= h(lg_url($base, ['p' => $i])) ?>"><?= fa_num($i) ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($end < $pages - 1): ?><span class="dots">…</span><?php endif; ?>
    <?php if ($end < $pages): ?><a href="<?= h(lg_url($base, ['p' => $pages])) ?>"><?= fa_num($pages) ?></a><?php endif; ?>
    <?php if ($p < $pages): ?><a href="<?= h(lg_url($base, ['p' => $p + 1])) ?>">بعدی</a><?php else: ?><span class="muted">بعدی</span><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="modal" id="lgModal">
  <div class="mbox">
    <div class="mhead"><?= ic('activity') ?><span id="mTitle">جزئیات رخداد</span><button type="button" class="ib" onclick="lgClose()" title="بستن"><?= ic('x') ?></button></div>
    <div class="mbody">
      <div class="mkv"><span>زمان</span><b id="mZ">—</b></div>
      <div class="mkv"><span>کاربر</span><b id="mU">—</b></div>
      <div class="mkv"><span>رخداد</span><b id="mA">—</b></div>
      <div class="mkv"><span>شناسهٔ خام</span><b class="ltr" id="mRaw">—</b></div>
      <div class="mkv"><span>هدف</span><b id="mT">—</b></div>
      <div class="mkv"><span>جزئیات</span><b id="mM">—</b></div>
      <div class="mkv" style="border:0"><span>IP</span><b class="ltr" id="mIp">—</b></div>
    </div>
  </div>
</div>

<script>
/* ---------- کپی ---------- */
function admCp(b, v){
  if (navigator.clipboard) navigator.clipboard.writeText(v).then(done, fb); else fb();
  function fb(){ var i = document.createElement('input'); i.value = v; document.body.appendChild(i); i.select(); try { document.execCommand('copy'); } catch(e){} i.remove(); done(); }
  function done(){ b.innerHTML = '<svg class="ic"><use href="#i-check"/></svg>'; setTimeout(function(){ b.innerHTML = '<svg class="ic"><use href="#i-copy"/></svg>'; }, 1400); }
}

/* ---------- جزئیات رخداد ---------- */
var md = document.getElementById('lgModal');
function lgClose(){ md.classList.remove('on'); }
document.querySelectorAll('.lg-row').forEach(function(r){
  r.onclick = function(){
    var d; try { d = JSON.parse(r.getAttribute('data-row')); } catch(e){ return; }
    document.getElementById('mZ').textContent  = d.z  || '—';
    document.getElementById('mU').textContent  = (d.u || '—') + ' (@' + d.un + ')';
    document.getElementById('mA').textContent  = d.a  || '—';
    document.getElementById('mRaw').textContent = d.raw || '—';
    document.getElementById('mT').textContent  = d.t || '—';
    document.getElementById('mM').textContent  = d.m || '—';
    document.getElementById('mIp').textContent = d.ip || '—';
    md.classList.add('on');
  };
});
md.onclick = function(e){ if (e.target === md) lgClose(); };
window.addEventListener('keydown', function(e){ if (e.key === 'Escape') lgClose(); });

/* ---------- جست‌وجو با Enter ---------- */
var fq = document.getElementById('fltForm');
fq.addEventListener('submit', function(){ var i = fq.querySelector('[name=p]'); if (i) i.remove(); });
</script>
<?php adm_footer();
