<?php
/**
 * پروفایل بله — نمایهٔ کامل حساب چت و ربات
 * از index.php داخل بلوکِ «page=bale» بارگذاری می‌شود و به توابع آن (api, h, ic, css, ...) دسترسی دارد.
 */

/* ------------------------------------------------------------------
 * عکس پروفایل از سرور خودمان پروکسی می‌شود؛
 * وگرنه آدرس مستقیم file/bot<TOKEN>/... توکن ربات را داخل HTML لو می‌داد.
 * ------------------------------------------------------------------ */
function bale_photo_bytes(string $size) {
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bale-panel-cache';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return null;

    $key  = substr(hash('sha256', storage_ctx()['token'] . '|' . storage_chat() . '|' . $size), 0, 24);
    $file = $dir . DIRECTORY_SEPARATOR . 'avatar-' . $key . '.img';
    if (is_file($file) && filesize($file) > 64 && filemtime($file) > time() - 21600) {
        $cached = @file_get_contents($file);
        if ($cached !== false && $cached !== '') return $cached;
    }

    $chat = api('getChat', ['chat_id' => storage_chat()]);
    $fid  = $chat['result']['photo'][$size . '_file_id'] ?? null;
    if (!$fid) return null;

    $meta = api('getFile', ['file_id' => $fid]);
    $path = $meta['result']['file_path'] ?? null;
    if (!$path) return null;

    $ch = curl_init('https://tapi.bale.ai/file/bot' . storage_ctx()['token'] . '/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $bytes = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($bytes === false || $code !== 200 || strlen($bytes) < 64) return null;

    @file_put_contents($file, $bytes);
    return $bytes;
}

if (isset($_GET['avatar'])) {
    session_write_close();                       // قفل نشست را آزاد کن؛ تصویر جداگانه درخواست می‌شود
    $size  = ($_GET['avatar'] === 'small') ? 'small' : 'big';
    $bytes = bale_photo_bytes($size);
    if ($bytes === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('avatar not found');
    }
    $info = @getimagesizefromstring($bytes);
    $mime = (!empty($info['mime'])) ? $info['mime'] : 'image/jpeg';
    $etag = '"' . substr(md5($bytes), 0, 20) . '"';
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=21600');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

/* ---------- قطعه‌های ظاهری ---------- */

/** یک ردیف «برچسب ← مقدار» می‌سازد. $html از قبل escape شده است. */
function pf_row(string $label, string $html, string $copy = '', string $dir = 'auto') {
    $out = '<div class="pf-row"><dt>' . h($label) . '</dt><dd><span dir="' . h($dir) . '">' . $html . '</span>';
    if ($copy !== '') {
        $out .= '<button type="button" class="pf-cp" data-cp="' . h($copy) . '" title="کپی" aria-label="کپی ' . h($label) . '">' . ic('copy') . '</button>';
    }
    return $out . '</dd></div>';
}

function pf_chip(string $text, string $tone = '') {
    return '<span class="pf-chip' . ($tone !== '' ? ' ' . $tone : '') . '"><i class="pf-dot"></i>' . $text . '</span>';
}

function bale_day($ts) { return fa_date((int)$ts, 'date'); }

/* ---------- دادهٔ زنده (۶۰ ثانیه کش، دکمهٔ به‌روزرسانی کش را می‌شکند) ---------- */
$pfFresh = isset($_GET['refresh']);
$pfKey   = 'bale_snap_' . md5(storage_ctx()['token'] . '|' . storage_chat());
$snap    = null;
if (!$pfFresh && !empty($_SESSION[$pfKey]['at']) && $_SESSION[$pfKey]['at'] > time() - 60) {
    $snap = $_SESSION[$pfKey]['d'];
}
if (!is_array($snap)) {
    $snap = [
        'at'      => time(),
        'me'      => api('getMe'),
        'chat'    => api('getChat', ['chat_id' => storage_chat()]),
        'webhook' => api('getWebhookInfo'),
    ];
    $snap['is_group'] = in_array($snap['chat']['result']['type'] ?? '', ['group', 'supergroup', 'channel'], true);
    if ($snap['is_group']) {
        $snap['members'] = api('getChatMembersCount', ['chat_id' => storage_chat()]);
        $snap['admins']  = api('getChatAdministrators', ['chat_id' => storage_chat()]);
        $botId = (int)($snap['me']['result']['id'] ?? 0);
        if ($botId) $snap['self'] = api('getChatMember', ['chat_id' => storage_chat(), 'user_id' => $botId]);
    }
    $_SESSION[$pfKey] = ['at' => time(), 'd' => $snap];
}
session_write_close();

$me      = is_array($snap['me']['result'] ?? null) ? $snap['me']['result'] : [];
$chat    = is_array($snap['chat']['result'] ?? null) ? $snap['chat']['result'] : [];
$wh      = is_array($snap['webhook']['result'] ?? null) ? $snap['webhook']['result'] : [];
$meOk    = !empty($snap['me']['ok']);
$chatOk  = !empty($snap['chat']['ok']);
$whOk    = !empty($snap['webhook']['ok']);
$isGroup = !empty($snap['is_group']);

$botId    = (int)($me['id'] ?? 0);
$type     = (string)($chat['type'] ?? '');
$userName = (string)($chat['username'] ?? '');
$fullName = trim((string)($chat['first_name'] ?? '') . ' ' . (string)($chat['last_name'] ?? ''));
$display  = $fullName !== '' ? $fullName : ($userName !== '' ? '@' . $userName : 'چت ' . storage_chat());
$hasPhoto = !empty($chat['photo']['big_file_id']);
$initial  = function_exists('mb_substr') ? mb_substr($display, 0, 1, 'UTF-8') : substr($display, 0, 1);
$hue      = (int)(abs((int)crc32(storage_chat() . '|' . $userName)) % 360);
$typeFa   = ['private' => 'خصوصی', 'group' => 'گروه', 'supergroup' => 'گروه', 'channel' => 'کانال'][$type] ?? ($type !== '' ? $type : 'نامشخص');

$svc = [['getMe', $meOk], ['getChat', $chatOk], ['getWebhookInfo', $whOk]];
if ($isGroup) {
    $svc[] = ['getChatMembersCount', !empty($snap['members']['ok'])];
    $svc[] = ['getChatAdministrators', !empty($snap['admins']['ok'])];
}
$okCount  = 0;
foreach ($svc as $s) if ($s[1]) $okCount++;
$svcTotal = count($svc);

/* ---------- آمار انبار ---------- */
$store   = load_data();
$fCount  = count($store['files']);
$fBytes  = 0;
$lastTs  = 0;
$chunked = 0;
foreach ($store['files'] as $f) {
    $fBytes += (int)($f['size'] ?? 0);
    $lastTs  = max($lastTs, (int)($f['date'] ?? 0));
    if (count($f['parts'] ?? []) > 1) $chunked++;
}
$folderCount = count($store['folders']);

/* ---------- ردیف‌ها ---------- */
$rowsChat  = pf_row('نام نمایشی', h($display));
$rowsChat .= pf_row(
    'نام کاربری',
    $userName !== ''
        ? '<a class="pf-link" href="https://ble.ir/' . h($userName) . '" target="_blank" rel="noopener">@' . h($userName) . '</a>'
        : '<span class="pf-mute">تنظیم نشده</span>',
    $userName !== '' ? '@' . $userName : ''
);
$rowsChat .= pf_row('شناسه چت', '<span class="pf-mono" dir="ltr">' . h((string)storage_chat()) . '</span>', (string)storage_chat());
$rowsChat .= pf_row('نوع', h($typeFa));
$rowsChat .= pf_row('عکس پروفایل', $hasPhoto ? 'دارد' : 'ندارد');
if ($isGroup && !empty($snap['members']['result'])) {
    $rowsChat .= pf_row('تعداد اعضا', h(number_format((int)$snap['members']['result'])));
}
if (!empty($chat['max_reaction_count'])) {
    $rowsChat .= pf_row('حداکثر واکنش', h((string)$chat['max_reaction_count']));
}
if (!$chatOk) {
    $rowsChat .= pf_row('خطای API', '<span class="pf-mute">' . h((string)($snap['chat']['description'] ?? 'پاسخ نامعتبر')) . '</span>');
}

$rowsBot  = pf_row('نام', h((string)($me['first_name'] ?? '—')));
$rowsBot .= pf_row(
    'نام کاربری',
    !empty($me['username'])
        ? '<a class="pf-link" href="https://ble.ir/' . h((string)$me['username']) . '" target="_blank" rel="noopener">@' . h((string)$me['username']) . '</a>'
        : '<span class="pf-mute">—</span>',
    !empty($me['username']) ? '@' . $me['username'] : ''
);
$rowsBot .= pf_row('شناسه ربات', $botId ? '<span class="pf-mono" dir="ltr">' . h((string)$botId) . '</span>' : '<span class="pf-mute">—</span>', $botId ? (string)$botId : '');
$rowsBot .= pf_row('نوع', 'ربات');
$rowsBot .= pf_row('وضعیت اتصال', $meOk ? pf_chip('متصل', 'ok') : pf_chip('قطع', 'bad'));
if (!$meOk) {
    $rowsBot .= pf_row('خطای API', '<span class="pf-mute">' . h((string)($snap['me']['description'] ?? 'پاسخ نامعتبر')) . '</span>');
}

$whUrl = (string)($wh['url'] ?? '');
$rowsWh  = pf_row('وضعیت', $whUrl !== '' ? pf_chip('فعال', 'ok') : pf_chip('غیرفعال'));
$rowsWh .= pf_row('آدرس', $whUrl !== '' ? '<span class="pf-mono" dir="ltr">' . h($whUrl) . '</span>' : '<span class="pf-mute">تنظیم نشده</span>', $whUrl);
$rowsWh .= pf_row('گواهی اختصاصی', !empty($wh['has_custom_certificate']) ? 'دارد' : 'ندارد');
$rowsWh .= pf_row('در انتظار ارسال', h(number_format((int)($wh['pending_update_count'] ?? 0))));

$rowsStore  = pf_row('تعداد فایل', h(number_format($fCount)));
$rowsStore .= pf_row('حجم کل', h(fmt_size($fBytes)));
$rowsStore .= pf_row('پوشه', h(number_format($folderCount)));
$rowsStore .= pf_row('چندبخشی', h(number_format($chunked)));
$rowsStore .= pf_row('آخرین بارگذاری', $lastTs ? h(bale_day($lastTs)) : '<span class="pf-mute">—</span>');

$rawBlocks = [['getMe', $snap['me']], ['getChat', $snap['chat']], ['getWebhookInfo', $snap['webhook']]];
if ($isGroup) {
    $rawBlocks[] = ['getChatMembersCount', $snap['members']];
    $rawBlocks[] = ['getChatAdministrators', $snap['admins']];
    if (!empty($snap['self'])) $rawBlocks[] = ['getChatMember', $snap['self']];
}
$pretty = function ($v) {
    return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
};
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>پروفایل بله — <?= h($display) ?></title>
<style><?= css() ?><?php
/* ================= توکن‌ها و ظاهر صفحهٔ پروفایل ================= */
echo <<<'CSS'
.pf{
  --pf-r:14px;
  --pf-t0:12px;--pf-t1:13px;--pf-t2:14px;--pf-t3:16px;--pf-t4:20px;--pf-t5:26px;--pf-t6:34px;
  --pf-s1:4px;--pf-s2:8px;--pf-s3:12px;--pf-s4:16px;--pf-s5:24px;--pf-s6:32px;--pf-s7:48px;
  --pf-sh:0 1px 2px #0f172a0d,0 24px 48px -32px #0f172a33;
  --pf-fast:140ms;--pf-dur:220ms;--pf-ease:cubic-bezier(.2,0,0,1);
  max-width:1120px;margin:0 auto;padding:var(--pf-s5) var(--pf-s4) var(--pf-s7);
}
.pf-hero{background:var(--surface);border:1px solid var(--border);border-radius:var(--pf-r);box-shadow:var(--pf-sh);overflow:hidden}
.pf-cover{height:148px;position:relative;background:linear-gradient(145deg,hsl(var(--h) 42% 45%),hsl(calc(var(--h) + 28) 48% 26%))}
.pf-cover::after{content:"";position:absolute;inset:0;background:radial-gradient(90% 150% at 84% -20%,#ffffff59,transparent 62%)}
.pf-body{position:relative;padding:0 var(--pf-s5) var(--pf-s5)}
.pf-idrow{display:flex;align-items:flex-start;gap:var(--pf-s4);flex-wrap:wrap}
.pf-avw{position:relative;flex:none;width:112px;height:112px;margin-top:-64px}
.pf-av{display:block;width:112px;height:112px;border-radius:50%;border:4px solid var(--surface);background:var(--surface2);object-fit:cover;box-shadow:0 10px 26px -14px #0f172a8c}
img.pf-av{position:absolute;inset:0}
.pf-avf{display:grid;place-items:center;font-size:var(--pf-t6);font-weight:800;color:#fff;background:linear-gradient(150deg,hsl(var(--h) 62% 48%),hsl(calc(var(--h) + 38) 66% 32%))}
.pf-ident{flex:1;min-width:220px;padding-top:var(--pf-s3)}
.pf-name{margin:0;font-size:var(--pf-t5);font-weight:800;line-height:1.3;display:flex;align-items:center;gap:var(--pf-s2);flex-wrap:wrap;color:var(--text)}
.pf-tick{display:inline-grid;place-items:center;width:21px;height:21px;border-radius:50%;background:#3bb273;color:#fff;flex:none}
.pf-tick .ic{width:12px;height:12px;stroke-width:3.2}
.pf-link{display:inline-block;font-weight:700;color:var(--primary);text-decoration:none;direction:ltr;unicode-bidi:isolate;font-size:var(--pf-t2)}
.pf-link:hover{text-decoration:underline}
.pf-chips{display:flex;gap:var(--pf-s2);flex-wrap:wrap;margin-top:var(--pf-s3)}
.pf-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;background:var(--surface2);color:var(--muted);font-size:var(--pf-t0);font-weight:700;line-height:1.7}
.pf-chip.ok{background:#0596691f;color:var(--ok)}
.pf-chip.bad{background:#e11d481f;color:var(--danger)}
.pf-dot{width:7px;height:7px;border-radius:50%;background:currentColor;flex:none}
.pf-btns{display:flex;gap:var(--pf-s2);flex-wrap:wrap;margin:var(--pf-s3) 0 0 auto;align-self:flex-start}
.pf-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 15px;border-radius:var(--pf-r);border:1px solid var(--border);background:var(--surface);color:var(--text);font:inherit;font-size:var(--pf-t1);font-weight:700;cursor:pointer;text-decoration:none;transition:background var(--pf-fast) var(--pf-ease),border-color var(--pf-fast) var(--pf-ease),transform var(--pf-fast) var(--pf-ease)}
.pf-btn .ic{width:16px;height:16px;color:var(--muted)}
.pf-btn:hover{background:var(--surface2);border-color:var(--primary)}
.pf-btn:active{transform:translateY(1px)}
.pf-btn:focus-visible{outline:2px solid var(--primary);outline-offset:2px}
.pf-btn.p{background:linear-gradient(135deg,var(--primary),var(--primary2));border-color:transparent;color:#fff}
.pf-btn.p .ic{color:#fff}
.pf-btn.p:hover{filter:brightness(1.06);border-color:transparent}
.pf-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));margin-top:var(--pf-s4);background:var(--surface);border:1px solid var(--border);border-radius:var(--pf-r);box-shadow:var(--pf-sh);overflow:hidden}
.pf-stat{display:flex;flex-direction:column;gap:1px;padding:var(--pf-s3) var(--pf-s4);min-width:0}
.pf-stat+.pf-stat{border-inline-start:1px solid var(--border)}
.pf-stat b{font-size:var(--pf-t4);font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.4}
.pf-stat span{font-size:var(--pf-t0);color:var(--muted);font-weight:600}
.pf-grid{display:grid;grid-template-columns:1.08fr .92fr;gap:var(--pf-s4);margin-top:var(--pf-s4)}
.pf-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--pf-r);box-shadow:var(--pf-sh);overflow:hidden}
.pf-card.wide{grid-column:1/-1}
.pf-card>header{display:flex;align-items:center;gap:var(--pf-s2);padding:var(--pf-s3) var(--pf-s4);border-bottom:1px solid var(--border)}
.pf-card>header .ic{width:17px;height:17px;color:var(--primary)}
.pf-card>header h2{margin:0;font-size:var(--pf-t3);font-weight:800;color:var(--text)}
.pf-card>header .sp{flex:1}
.pf-rows{margin:0;padding:0}
.pf-row{display:flex;align-items:center;gap:var(--pf-s3);padding:11px var(--pf-s4);min-height:44px}
.pf-row+.pf-row{border-top:1px solid var(--border)}
.pf-row dt{flex:none;min-width:104px;color:var(--muted);font-size:var(--pf-t1);font-weight:600}
.pf-row dd{margin:0;flex:1;min-width:0;display:flex;align-items:center;gap:var(--pf-s2);font-size:var(--pf-t2);font-weight:700;color:var(--text)}
.pf-row dd>span{min-width:0;overflow-wrap:anywhere}
.pf-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:var(--pf-t1);font-variant-numeric:tabular-nums;letter-spacing:.2px}
.pf-mute{color:var(--muted);font-weight:600;font-size:var(--pf-t1)}
.pf-cp{flex:none;display:grid;place-items:center;width:28px;height:28px;border:0;border-radius:8px;background:transparent;color:var(--muted);cursor:pointer;transition:background var(--pf-fast) var(--pf-ease),color var(--pf-fast) var(--pf-ease)}
.pf-cp .ic{width:15px;height:15px}
.pf-cp:hover{background:var(--surface2);color:var(--primary)}
.pf-cp:active{transform:translateY(1px)}
.pf-cp:focus-visible{outline:2px solid var(--primary);outline-offset:1px}
.pf-raw{border-top:1px solid var(--border)}
.pf-raw:first-of-type{border-top:0}
.pf-raw>summary{display:flex;align-items:center;gap:var(--pf-s2);padding:11px var(--pf-s4);cursor:pointer;list-style:none;font-size:var(--pf-t1);font-weight:700;color:var(--primary)}
.pf-raw>summary::-webkit-details-marker{display:none}
.pf-raw>summary::before{content:"+";display:grid;place-items:center;width:16px;height:16px;border-radius:5px;background:var(--surface2);color:var(--muted);font-weight:800;font-size:12px;line-height:1}
.pf-raw[open]>summary::before{content:"−"}
.pf-raw>summary:focus-visible{outline:2px solid var(--primary);outline-offset:-2px}
.pf-raw .pf-mono{color:var(--text)}
.pf-pre{margin:0;padding:var(--pf-s3) var(--pf-s4);background:var(--surface2);color:var(--text);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px;line-height:1.7;direction:ltr;text-align:left;max-height:320px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;border-top:1px solid var(--border)}
.pf-toast{position:fixed;bottom:22px;left:50%;transform:translate(-50%,24px);background:#0f172a;color:#fff;padding:9px 18px;border-radius:999px;font-size:var(--pf-t1);font-weight:700;opacity:0;pointer-events:none;z-index:90;transition:opacity var(--pf-fast) var(--pf-ease),transform var(--pf-fast) var(--pf-ease)}
.pf-toast.on{opacity:1;transform:translate(-50%,0)}
@media(max-width:900px){
  .pf-grid{grid-template-columns:1fr}
  .pf-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
  .pf-stat:nth-child(3){border-inline-start:0}
  .pf-stat:nth-child(n+3){border-top:1px solid var(--border)}
}
@media(max-width:560px){
  .pf{padding:var(--pf-s4) var(--pf-s3) var(--pf-s7)}
  .pf-body{padding:0 var(--pf-s4) var(--pf-s4)}
  .pf-avw,.pf-av,.pf-avf{width:88px;height:88px}
  .pf-avw{margin-top:-56px}
  .pf-ident{padding-top:var(--pf-s2);min-width:0}
  .pf-name{font-size:var(--pf-t4)}
  .pf-btns{width:100%;margin:0;padding-top:var(--pf-s3)}
  .pf-row{flex-direction:column;align-items:flex-start;gap:1px;padding:10px var(--pf-s4)}
  .pf-row dt{min-width:0}
  .pf-row dd{width:100%}
  .pf-btn{flex:1 1 0;white-space:nowrap}
  .pf-btn.p{flex:1 0 100%}
}
@media(prefers-reduced-motion:reduce){.pf *{transition:none!important}}

/* اصلاح کنتراست متن‌های کوچک (۴٫۵:۱ به بالا) */
.pf{--pf-sub:#4e5872;--pf-ok:#04724f;--pf-bad:#be123c}
@media(prefers-color-scheme:dark){.pf{--pf-sub:#aab5d0;--pf-ok:#34d399;--pf-bad:#fb7185}}
.pf-chip{color:var(--pf-sub)}
.pf-chip.ok{color:var(--pf-ok)}
.pf-chip.bad{color:var(--pf-bad)}
.pf-row dt,.pf-stat span,.pf-mute{color:var(--pf-sub)}
.pf-tick{background:#16a34a}
CSS;
?></style></head><body>
<?php sprite(); ?>

<div class="top"><div class="top-in">
  <div class="logo"><i><?= ic('cloud') ?></i><b>پنل فایل</b></div>
  <div style="flex:1"></div>
  <a class="btn" href="./"><?= ic('right') ?><span class="tx">بازگشت به فایل‌ها</span></a>
  <a class="btn" href="?logout=1"><?= ic('logout') ?><span class="tx">خروج</span></a>
</div></div>

<div class="pf" style="--h:<?= (int)$hue ?>">

  <section class="pf-hero" aria-labelledby="pfName">
    <div class="pf-cover"></div>
    <div class="pf-body">
      <div class="pf-idrow">
        <div class="pf-avw">
          <div class="pf-av pf-avf" aria-hidden="true"><?= h($initial) ?></div>
          <?php if ($hasPhoto): ?>
          <img class="pf-av" src="?page=bale&amp;avatar=1" width="112" height="112" alt="عکس پروفایل <?= h($display) ?>" onerror="this.remove()">
          <?php endif; ?>
        </div>

        <div class="pf-ident">
          <h1 class="pf-name" id="pfName">
            <?= h($display) ?>
            <?php if ($meOk): ?><span class="pf-tick" title="ربات متصل"><?= ic('check') ?></span><?php endif; ?>
          </h1>
          <?php if ($userName !== ''): ?>
            <a class="pf-link" href="https://ble.ir/<?= h($userName) ?>" target="_blank" rel="noopener">@<?= h($userName) ?></a>
          <?php endif; ?>

          <div class="pf-chips">
            <span class="pf-chip">چت <?= h($typeFa) ?></span>
            <span class="pf-chip <?= $meOk ? 'ok' : 'bad' ?>"><i class="pf-dot"></i>ربات <?= $meOk ? 'متصل' : 'قطع' ?></span>
            <span class="pf-chip <?= $okCount === $svcTotal ? 'ok' : 'bad' ?>"><i class="pf-dot"></i><?= (int)$okCount ?> از <?= (int)$svcTotal ?> سرویس</span>
          </div>
        </div>

        <div class="pf-btns">
          <button type="button" class="pf-btn p" data-cp="<?= h((string)storage_chat()) ?>"><?= ic('copy') ?>کپی شناسه</button>
          <?php if ($userName !== ''): ?>
          <a class="pf-btn" href="https://ble.ir/<?= h($userName) ?>" target="_blank" rel="noopener"><?= ic('eye') ?>مشاهده در بله</a>
          <?php endif; ?>
          <a class="pf-btn" href="?page=bale&amp;refresh=1"><?= ic('refresh') ?>به‌روزرسانی</a>
        </div>
      </div>
    </div>
  </section>

  <section class="pf-stats" aria-label="آمار انبار">
    <div class="pf-stat"><b><?= h(number_format($fCount)) ?></b><span>فایل ذخیره‌شده</span></div>
    <div class="pf-stat"><b><?= h(fmt_size($fBytes)) ?></b><span>حجم کل</span></div>
    <div class="pf-stat"><b><?= h(number_format($folderCount)) ?></b><span>پوشه</span></div>
    <div class="pf-stat"><b><?= $lastTs ? h(bale_day($lastTs)) : '—' ?></b><span>آخرین بارگذاری</span></div>
  </section>

  <div class="pf-grid">
    <section class="pf-card" aria-labelledby="pfChat">
      <header><?= ic('cloud') ?><h2 id="pfChat">پروفایل چت</h2><span class="sp"></span><?= $chatOk ? '' : pf_chip('خطا', 'bad') ?></header>
      <dl class="pf-rows"><?= $rowsChat ?></dl>
    </section>

    <section class="pf-card" aria-labelledby="pfBot">
      <header><?= ic('send') ?><h2 id="pfBot">ربات</h2><span class="sp"></span><?= $meOk ? pf_chip('فعال', 'ok') : pf_chip('قطع', 'bad') ?></header>
      <dl class="pf-rows"><?= $rowsBot ?></dl>
    </section>

    <section class="pf-card" aria-labelledby="pfWh">
      <header><?= ic('link') ?><h2 id="pfWh">وب‌هوک</h2><span class="sp"></span><?= $whUrl !== '' ? pf_chip('فعال', 'ok') : pf_chip('غیرفعال') ?></header>
      <dl class="pf-rows"><?= $rowsWh ?></dl>
    </section>

    <section class="pf-card" aria-labelledby="pfStore">
      <header><?= ic('archive') ?><h2 id="pfStore">فضای ذخیره‌سازی</h2><span class="sp"></span><span class="pf-chip">سرور بله</span></header>
      <dl class="pf-rows"><?= $rowsStore ?></dl>
    </section>

    <?php if ($isGroup): ?>
    <section class="pf-card wide" aria-labelledby="pfAdmins">
      <header><?= ic('folder') ?><h2 id="pfAdmins">اعضا و مدیران</h2><span class="sp"></span><span class="pf-chip"><?= h((string)($snap['members']['result'] ?? '—')) ?> عضو</span></header>
      <dl class="pf-rows">
        <?php $admins = $snap['admins']['result'] ?? []; ?>
        <?php if (is_array($admins) && $admins): foreach ($admins as $a):
            $u = is_array($a['user'] ?? null) ? $a['user'] : [];
            $an = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));
            if ($an === '') $an = (string)($u['username'] ?? 'مدیر'); ?>
          <div class="pf-row"><dt><?= h((string)($a['status'] ?? '—')) ?></dt><dd><span><?= h($an) ?></span><span class="pf-mute" dir="ltr"><?= h((string)($u['id'] ?? '')) ?></span></dd></div>
        <?php endforeach; else: ?>
          <div class="pf-row"><dt>وضعیت</dt><dd><span class="pf-mute">فهرست مدیران دریافت نشد.</span></dd></div>
        <?php endif; ?>
      </dl>
    </section>
    <?php endif; ?>

    <section class="pf-card wide" aria-labelledby="pfRaw">
      <header><?= ic('doc') ?><h2 id="pfRaw">پاسخ خام API</h2><span class="sp"></span><span class="pf-chip">آخرین بررسی: <?= h(fa_date((int)($snap['at'] ?? time()))) ?></span></header>
      <?php foreach ($rawBlocks as $rb): ?>
        <details class="pf-raw">
          <summary><span class="pf-mono"><?= h($rb[0]) ?></span></summary>
          <pre class="pf-pre" dir="ltr"><?= h($pretty($rb[1])) ?></pre>
        </details>
      <?php endforeach; ?>
    </section>
  </div>
</div>

<div class="pf-toast" id="pfToast" role="status" aria-live="polite"></div>

<script>
(function () {
  var t = document.getElementById('pfToast'), tm;
  function toast(m) { t.textContent = m; t.className = 'pf-toast on'; clearTimeout(tm); tm = setTimeout(function () { t.className = 'pf-toast'; }, 1800); }
  function copy(text) {
    var ok = function () { toast('کپی شد'); };
    var no = function () { window.prompt('برای کپی، متن را انتخاب کنید:', text); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(ok, no); return; }
    var a = document.createElement('textarea');
    a.value = text; a.setAttribute('readonly', ''); a.style.position = 'fixed'; a.style.top = '-1000px';
    document.body.appendChild(a); a.select();
    try { document.execCommand('copy') ? ok() : no(); } catch (e) { no(); }
    a.remove();
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-cp]') : null;
    if (!b) return;
    e.preventDefault();
    copy(b.getAttribute('data-cp'));
  });
})();
</script>
</body></html>
