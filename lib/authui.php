<?php
/**
 * قالب مشترک صفحات ورود/ثبت‌نام — چیدمان دوستونه: پنل برند + فرم.
 * فونت وزیرمتن به صورت لوکال از fonts/ بارگذاری می‌شود.
 */

require_once __DIR__ . '/fonts.php';

function auth_head(string $title): void {
    ?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= h($title) ?></title>
<style>
<?= vazir_font_css('fonts/') ?>
:root{
  --bg:#f5f6fa;--surface:#fff;--text:#0f172a;--muted:#64748b;--border:#e4e7ef;
  --primary:#4f46e5;--primary2:#6366f1;--weak:#eef0ff;--danger:#e11d48;--ok:#059669;
  --shadow:0 1px 2px #0f172a0a,0 24px 60px #0f172a14;
}
@media(prefers-color-scheme:dark){:root{
  --bg:#0a0f1f;--surface:#121a2f;--text:#e8ecf8;--muted:#8b97b8;--border:#232d4d;
  --primary:#818cf8;--primary2:#a5b4fc;--weak:#1b2250;--danger:#fb7185;--ok:#34d399;
  --shadow:0 1px 2px #0007,0 24px 60px #0008;
}}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%}
body{font-family:'Vazirmatn FD',Vazirmatn,'IRANSans',Tahoma,system-ui,-apple-system,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);font-size:14px;line-height:1.7}
.lg{min-height:100vh;display:flex}
.ic{width:18px;height:18px;flex:none}

/* ---------- پنل برند ---------- */
.brand{width:44%;flex:none;position:relative;overflow:hidden;color:#fff;display:flex;flex-direction:column;justify-content:space-between;padding:46px 46px 34px;
  background:linear-gradient(160deg,#312e81 0%,#4f46e5 55%,#6d28d9 100%)}
.brand::before,.brand::after{content:'';position:absolute;border-radius:50%;filter:blur(70px);opacity:.35;pointer-events:none}
.brand::before{width:420px;height:420px;background:#a5b4fc;top:-140px;inset-inline-end:-120px}
.brand::after{width:360px;height:360px;background:#22d3ee;bottom:-160px;inset-inline-start:-100px}
.brand > *{position:relative;z-index:1}
.brand-top{display:flex;align-items:center;gap:12px}
.brand-top b{font-size:18px;font-weight:800}
.mark{display:grid;place-items:center;width:44px;height:44px;border-radius:13px;background:#ffffff1f;border:1px solid #ffffff2e;color:#fff;flex:none}
.mark .ic{width:22px;height:22px}
.brand-mid{max-width:400px}
.brand-mid h2{font-size:26px;font-weight:800;line-height:1.5;margin:0 0 10px}
.brand-mid p{margin:0 0 26px;color:#e0e7ff;font-size:14px}
.feats{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:12px}
.feats li{display:flex;align-items:center;gap:11px;font-size:13.5px;font-weight:600;color:#eef2ff}
.feats li .ic{width:19px;height:19px;color:#c7d2fe}
.brand-foot{font-size:12px;color:#c7d2fe;opacity:.9}

/* ---------- فرم ---------- */
.formside{flex:1;display:grid;place-items:center;padding:40px 22px}
.fcard{width:100%;max-width:410px;background:var(--surface);border:1px solid var(--border);border-radius:22px;box-shadow:var(--shadow);padding:34px 34px 26px;
  animation:pop .4s ease both}
@keyframes pop{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.fcard.wide{max-width:460px}
.mark-sm{display:grid;place-items:center;width:52px;height:52px;border-radius:16px;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;margin-bottom:16px;box-shadow:0 10px 24px #4f46e540}
.mark-sm .ic{width:26px;height:26px}
.fcard h1{font-size:21px;margin:0 0 4px}
.fcard .lead{color:var(--muted);font-size:13px;margin:0 0 22px}
.banner{display:flex;align-items:center;gap:9px;padding:10px 13px;border-radius:12px;font-size:12.5px;font-weight:700;margin-bottom:14px}
.banner.m{background:#4f46e514;color:var(--primary)}
.banner.e{background:#e11d4812;color:var(--danger)}
@media(prefers-color-scheme:dark){.banner.m{background:#1b2250;color:#a5b4fc}.banner.e{background:#4c0519;color:#fecdd3}}
.fcard.shake{animation:shake .35s ease}
@keyframes shake{0%,100%{transform:none}25%{transform:translateX(6px)}50%{transform:translateX(-5px)}75%{transform:translateX(3px)}}
.fld{position:relative;margin-bottom:13px}
.fld > .ic{position:absolute;top:50%;transform:translateY(-50%);inset-inline-start:13px;color:var(--muted);pointer-events:none}
.fld input{width:100%;padding:13px 42px;border-radius:13px;border:1px solid var(--border);background:var(--surface);color:var(--text);outline:none;font-size:13.5px;transition:.15s}
.fld > label{display:block;font-size:11.5px;font-weight:800;color:var(--muted);margin-bottom:6px}
.fld input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--weak)}
.fld input::placeholder{color:var(--muted);opacity:.8}
.pw-toggle{position:absolute;bottom:8px;inset-inline-end:6px;width:32px;height:32px;border:0;background:transparent;border-radius:9px;color:var(--muted);cursor:pointer;display:grid;place-items:center}
.pw-toggle:hover{background:var(--weak);color:var(--primary)}
/* ---------- جعبه‌های کد یکبارمصرف ---------- */
.otpwrap{display:flex;gap:8px;direction:ltr;justify-content:center;margin-top:4px;max-width:100%}
.otpwrap .otpbox{flex:1 1 0;min-width:0;max-width:44px;width:auto;padding:0;height:48px;text-align:center;font-size:19px;font-weight:800;border-radius:12px;border:1px solid var(--border);background:var(--surface);color:var(--text);outline:none;transition:.15s;font-variant-numeric:tabular-nums;caret-color:var(--primary)}
.otpwrap .otpbox:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--weak);transform:translateY(-1px)}
.otpwrap .otpbox.filled{border-color:color-mix(in srgb,var(--primary) 45%,var(--border))}
@media(max-width:400px){.otpwrap{gap:6px}.otpwrap .otpbox{max-width:none;height:46px;font-size:18px;border-radius:11px}}
/* ---------- تایمر و ارسال دوباره ---------- */
.otp-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:4px 0 16px;flex-wrap:wrap}
.otp-timer{font-size:12px;color:var(--muted);font-weight:750;display:inline-flex;align-items:center;gap:6px}
.otp-timer .ic{width:14px;height:14px}
.otp-timer.warn{color:var(--danger)}
.resend{display:inline-flex;align-items:center;gap:7px;padding:9px 15px;border-radius:11px;border:1px solid var(--border);background:var(--surface);color:var(--primary);font:inherit;font-weight:800;font-size:12.5px;cursor:pointer;transition:.15s}
.resend .ic{width:15px;height:15px}
.resend:hover:not([disabled]){background:var(--weak);border-color:var(--primary)}
.resend[disabled]{opacity:.55;cursor:not-allowed;color:var(--muted)}
.frow{display:flex;align-items:center;justify-content:space-between;margin:2px 0 18px;font-size:12.5px}
.frow label{display:flex;align-items:center;gap:7px;color:var(--muted);cursor:pointer;font-weight:600}
.frow input{accent-color:var(--primary);width:15px;height:15px}
.lgbtn{width:100%;display:flex;align-items:center;justify-content:center;gap:9px;padding:13px;border:0;border-radius:13px;cursor:pointer;
  background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;font-weight:800;font-size:14px;
  box-shadow:0 10px 24px #4f46e540;transition:.15s}
.lgbtn:hover{filter:brightness(1.07)}
.lgbtn[disabled]{opacity:.6;pointer-events:none}
/* نشانگر قدرت رمز */
.pw-meter{height:5px;border-radius:99px;background:var(--border);margin:-6px 0 14px;overflow:hidden}
.pw-meter i{display:block;height:100%;width:0;border-radius:99px;transition:.25s}
.pw-hint{font-size:11px;color:var(--muted);margin:-8px 0 12px;text-align:start}
.alt{text-align:center;font-size:12.5px;color:var(--muted);margin-top:22px;padding-top:18px;border-top:1px dashed var(--border)}
.alt a{color:var(--primary);font-weight:800}

/* ---------- تب روش ورود ---------- */
.modes{display:grid;grid-template-columns:1fr 1fr;gap:6px;background:var(--weak);border-radius:14px;padding:5px;margin-bottom:18px}
.mode-btn{display:flex;align-items:center;justify-content:center;gap:7px;border:0;border-radius:10px;padding:10px;cursor:pointer;background:transparent;color:var(--muted);font-weight:800;font-size:12.5px;transition:.15s}
.mode-btn .ic{width:16px;height:16px}
.mode-btn:hover{color:var(--text)}
.mode-btn.on{background:var(--surface);color:var(--primary);box-shadow:var(--shadow)}
.ml{display:none}
.ml.on{display:block}

/* ---------- موبایل ---------- */
@media(max-width:880px){
  .brand{display:none}
  .formside{padding:76px 16px 24px}
  .lg::before{content:attr(data-site);display:block;position:fixed;top:0;inset-inline:0;padding:14px 22px;font-weight:800;color:#fff;font-size:15px;z-index:0;
    background:linear-gradient(135deg,#312e81,#6d28d9)}
}
</style></head><body>
<?php
}
