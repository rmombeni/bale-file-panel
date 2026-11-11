<?php
/**
 * قطعه‌های ظاهری مشترک صفحه‌ها (سربرگ، پوسته، پیام‌ها).
 */

function ui_extra_css(): string {
    return <<<'CSS'
.wrap{max-width:900px;margin:0 auto;padding:22px 18px 64px}
.wrap h1{font-size:22px;margin:0 0 2px}
.wrap .lead{color:var(--muted);font-size:13px;margin:0 0 18px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);padding:18px;margin-bottom:16px}
.card h2{margin:0 0 14px;font-size:16px}
.field{margin-bottom:12px}
.field label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px}
.field input,.field select,.field textarea{width:100%;padding:11px 13px;border-radius:12px;border:1px solid var(--border);background:var(--surface);color:var(--text);outline:none;font:inherit;font-size:13px}
.field textarea{resize:vertical;min-height:74px;line-height:1.8}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--weak)}
.field .hint{color:var(--muted);font-size:11px;margin-top:4px}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.alert{padding:10px 14px;border-radius:12px;margin-bottom:14px;font-weight:600;font-size:13px}
.alert.err{background:#fee2e2;color:#991b1b}
.alert.ok{background:#d1fae5;color:#065f46}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th,.tbl td{padding:9px 10px;border-bottom:1px solid var(--border);text-align:start;vertical-align:middle}
.tbl th{color:var(--muted);font-size:11px;font-weight:700}
.tbl tr:last-child td{border-bottom:0}
.pill{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;background:var(--surface2);color:var(--muted);font-size:11px;font-weight:700}
.pill.ok{background:#0596691f;color:#04724f}
.pill.bad{background:#e11d481f;color:#be123c}
.pill.admin{background:#4f46e51f;color:var(--primary)}
.inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.right{text-align:end}
@media(prefers-color-scheme:dark){.alert.err{background:#4c0519;color:#fecdd3}.alert.ok{background:#022c22;color:#a7f3d0}.pill.ok{color:#34d399}.pill.bad{color:#fb7185}}
@media(max-width:600px){.row2{grid-template-columns:1fr}}
.cardc input[type=checkbox]{width:auto;margin:0;padding:0}
.cardc input::placeholder{direction:rtl;text-align:right}
.remember{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--muted);margin:0 0 14px}
.auth-alt{text-align:center;font-size:12px;color:var(--muted);margin-top:14px}
.auth-alt a{color:var(--primary);font-weight:700}
input[type=number]{-moz-appearance:textfield;appearance:textfield}
input[type=number]::-webkit-outer-spin-button,input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.pwf{position:relative;width:100%}
.pwf input{padding-right:104px !important;padding-left:12px !important;text-align:left}
.pwf .acts{position:absolute;top:50%;transform:translateY(-50%);right:6px;display:flex;gap:2px}
.pwf .acts button{width:29px;height:29px;border:0;background:transparent;border-radius:8px;color:var(--muted);cursor:pointer;display:grid;place-items:center;padding:0}
.pwf .acts button:hover{background:var(--surface2);color:var(--text)}
.pwf .acts button.dl:hover{background:#e11d481f;color:var(--danger)}
.pwf .acts button .ic{width:15px;height:15px}
CSS;
}

function ui_secret_js(): void {
    ?>
<script>
/* ---------- فیلدهای محرمانه: نمایش/ویرایش/حذف ---------- */
(function(){
  document.querySelectorAll('.pwf .ey[data-pw]').forEach(function(b){
    b.onclick = function(){
      var i = document.getElementById(b.getAttribute('data-pw'));
      if (!i) return;
      var show = i.type === 'password';
      i.type = show ? 'text' : 'password';
      b.innerHTML = '<svg class="ic"><use href="#i-' + (show ? 'eye-off' : 'eye') + '"/></svg>';
      b.title = show ? 'پنهان کردن' : 'نمایش';
    };
  });
  document.querySelectorAll('.pwf .ed[data-pw]').forEach(function(b){
    b.onclick = function(){
      var i = document.getElementById(b.getAttribute('data-pw'));
      if (!i) return;
      i.readOnly = false; i.value = ''; i.type = 'password';
      i.placeholder = 'مقدار جدید را وارد کنید';
      i.focus();
    };
  });
  document.querySelectorAll('.pwf .dl[data-pw]').forEach(function(b){
    b.onclick = function(){
      if (!confirm('این مقدار حذف شود؟')) return;
      var f = b.closest('form');
      var i = f ? f.querySelector('#' + b.getAttribute('data-pw')) : null;
      if (!f || !i) return;
      i.value = ''; i.readOnly = false;
      var c = f.querySelector('input[name="' + b.getAttribute('data-clear') + '"]');
      if (c) c.value = '1';
      f.submit();
    };
  });
  document.querySelectorAll('form').forEach(function(f){
    f.addEventListener('submit', function(){
      f.querySelectorAll('.pwf[data-keep]').forEach(function(w){
        var i = w.querySelector('input:not([type=hidden])');
        if (i && (i.readOnly || i.value === '')) {
          var k = f.querySelector('input[name="' + w.getAttribute('data-keep') + '"]');
          if (k) k.value = '1';
        }
      });
    });
  });
})();
</script>
    <?php
}

function ui_start(string $title): void {
    ?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= h($title) ?></title><style><?= css() ?><?= ui_extra_css() ?></style></head><body>
<?php sprite();
}

function ui_end(): void {
    ?></body></html><?php
}

function ui_topbar(array $user, string $active = ''): void {
    $links = [
        ''         => ['فایل‌ها', 'folder'],
        'settings' => ['تنظیمات', 'edit'],
        'admin'    => ['مدیریت کاربران', 'doc'],
        'bale'     => ['اطلاعات بله', 'cloud'],
    ];
    if ($user['role'] !== 'admin') unset($links['admin']);
    ?>
<div class="top"><div class="top-in">
  <div class="logo"><i><?= ic('cloud') ?></i><b>پنل فایل</b></div>
  <div style="flex:1"></div>
  <?php foreach ($links as $k => [$label, $icon]):
      $href = $k === 'admin' ? 'admin/' : ($k === '' ? './' : '?page=' . $k);
      $cls  = ($active === $k) ? 'btn p' : 'btn'; ?>
    <a class="<?= $cls ?>" href="<?= h($href) ?>"><?= ic($icon) ?><span class="tx"><?= h($label) ?></span></a>
  <?php endforeach; ?>
  <a class="btn" href="?logout=1" title="خروج"><?= ic('logout') ?><span class="tx">خروج</span></a>
</div></div>
<?php
}

function ui_alert(?string $msg, string $type = 'err'): void {
    if ($msg === null || $msg === '') return;
    echo '<div class="alert ' . ($type === 'ok' ? 'ok' : 'err') . '">' . h($msg) . '</div>';
}
