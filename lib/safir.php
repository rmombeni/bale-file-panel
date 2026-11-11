<?php
/**
 * سرویس سفیر بله (Safir) — ارسال پیام/کد یکبارمصرف به شمارهٔ موبایل.
 * مستندات: https://docs.bale.ai/safir
 *   POST https://safir.bale.ai/api/v3/send_message
 *   هدر: api-access-key
 *   بدنه: request_id، bot_id (عددی)، phone_number (شروع با 98)، message_data
 *   پیام OTP: message_data.otp_message.otp (در بازوی «رمز یکبارمصرف بله» تحویل می‌شود)
 */
require_once __DIR__ . '/db.php';

const SAFIR_API = 'https://safir.bale.ai/api/v3/';

/* ---------- نرمال‌سازی شمارهٔ موبایل ایران ---------- */
function norm_phone(string $in): string {
    $d = preg_replace('/\D+/', '', $in);
    if ($d === '') return '';
    if (str_starts_with($d, '0098')) $d = '98' . substr($d, 4);
    elseif (str_starts_with($d, '9' . '8')) { $d = $d; } // شکل 98…
    elseif (str_starts_with($d, '0')) $d = '98' . substr($d, 1);
    elseif (strlen($d) === 10 && str_starts_with($d, '9')) $d = '98' . $d;
    // باید ۹۸ + ۱۰ رقم باشد و پیش‌شمارهٔ موبایل (۹۸۹×)
    return (preg_match('/^989\d{9}$/', $d) === 1) ? $d : '';
}

/** تبدیل 98xxxx به 09xxxx برای نمایش کاربرپسند */
function pretty_phone(string $p98): string {
    return str_starts_with($p98, '989') ? '0' . substr($p98, 2) : $p98;
}

/* ---------- فراخوانی کلی سرویس ---------- */
function safir_call(string $path, array $payload): array {
    $key = trim((string)db_get('safir_key', ''));
    if ($key === '') return [false, 'کلید دسترسی سفیر (api-access-key) تنظیم نشده است.'];
    $ch = curl_init(SAFIR_API . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'api-access-key: ' . $key],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false)  return [false, 'خطای شبکه: ' . ($err !== '' ? $err : 'نامشخص')];
    $j = json_decode((string)$res, true);
    if (!is_array($j)) {
        $tip = ((string)$res !== '' && preg_match('/^[\"\[]/', (string)$res) === 1) ? trim(json_decode((string)$res, true) ?: '') : '';
        return [false, "پاسخ نامعتبر از سفیر (HTTP $http)" . ($tip !== '' && !is_array($tip) ? ": $tip" : '')];
    }

    // خطاها در error_data (آرایه) یا error (تکی) برمی‌گردند
    $errs = $j['error_data'] ?? null;
    if (is_array($errs) && $errs) {
        $e = $errs[0];
        return [false, 'کد خطای سفیر ' . (string)($e['code'] ?? '?') . ': ' . safir_err_text((int)($e['code'] ?? 0), (string)($e['description'] ?? ''))];
    }
    if (is_array($errs)) {
        return [false, 'کد خطای سفیر ' . (string)($errs['code'] ?? '?') . ': ' . safir_err_text((int)($errs['code'] ?? 0), (string)($errs['description'] ?? ''))];
    }
    if (isset($j['error']) && is_array($j['error'])) {
        $e = $j['error'];
        return [false, 'کد خطای سفیر ' . (string)($e['code'] ?? '?') . ': ' . safir_err_text((int)($e['code'] ?? 0), (string)($e['description'] ?? ''))];
    }
    return [true, $j];
}

/** متن فارسی خطاهای رایج سفیر */
function safir_err_text(int $code, string $desc): string {
    // در پاسخ سفیر، توضیح خطا گاهی با پیشوند text:/text_m: ارسال می‌شود
    foreach (['text:', 'text_m:'] as $pre) {
        if (str_starts_with($desc, $pre)) { $desc = substr($desc, strlen($pre)); break; }
    }
    $map = [
        2  => 'خطای داخلی سرور بله',
        3  => 'بیش از حد مجاز پیام ارسال شده (Rate limit)',
        4  => 'ورودی JSON نامعتبر',
        8  => 'شمارهٔ موبایل نامعتبر',
        17 => 'این شماره حساب بله ندارد',
        20 => 'اعتبار کافی وجود ندارد',
        21 => 'به سقف تعداد مخاطبین بازو رسیده‌اید',
    ];
    return $map[$code] ?? ($desc !== '' ? $desc : 'خطای نامشخص');
}

/* ---------- ارسال OTP به یک شماره ---------- */
function safir_send_otp(string $phone98, string $code): array {
    $botId = (string)db_get('safir_bot_id', '');
    if (!preg_match('/^\d{4,}$/', $botId)) return [false, 'شناسهٔ عددی بازوی سفیر (bot_id) تنظیم نشده است.'];
    [$ok, $r] = safir_call('send_message', [
        'request_id'   => bin2hex(random_bytes(12)),
        'bot_id'       => (int)$botId,
        'phone_number' => $phone98,
        'message_data' => ['otp_message' => ['otp' => $code]],
    ]);
    return [$ok, $r];
}

/* ---------- صدور/بررسی کد یکبارمصرف (ذخیرهٔ هش)؛ محدودیت ارسال و تلاش ---------- */
function otp_issue(string $phone98, int $userId, string $ip, int $len = 6): array {
    // حداکثر یک درخواست هر ۶۰ ثانیه برای همین IP
    $st = db()->prepare('SELECT created_at FROM otp_codes WHERE ip = ? AND created_at > ? ORDER BY id DESC LIMIT 1');
    $st->execute([$ip, date('Y-m-d H:i:s', time() - 60)]);
    if ($row = $st->fetch()) {
        $wait = max(1, 60 - (time() - strtotime((string)$row['created_at'])));
        return [false, "درخواست قبلی تازه ثبت شده؛ $wait ثانیه بعد دوباره تلاش کنید."];
    }
    $code = str_pad((string)random_int(0, 10 ** $len - 1), $len, '0', STR_PAD_LEFT);
    db()->prepare('DELETE FROM otp_codes WHERE phone = ?')->execute([$phone98]);
    db()->prepare('INSERT INTO otp_codes (phone, user_id, ip, code_hash, created_at) VALUES (?,?,?,?,?)')
        ->execute([$phone98, $userId, $ip, hash('sha256', $code), date('Y-m-d H:i:s')]);
    return [true, $code];
}

/** بررسی کد: آخرین ردیف تلفن — خطا/موفقیت؛ در موفقیت پاک‌سازی */
function otp_verify(string $phone98, string $code): array {
    $ttl = max(120, (int)db_get('otp_ttl', '300'));
    $st = db()->prepare('SELECT * FROM otp_codes WHERE phone = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$phone98]);
    $row = $st->fetch();
    if (!$row) return [false, 'کد درخواست نشده یا منقضی شده است؛ کد جدید بگیرید.'];
    if (strtotime((string)$row['created_at']) < time() - $ttl) {
        db()->prepare('DELETE FROM otp_codes WHERE phone = ?')->execute([$phone98]);
        return [false, 'کد منقضی شده است؛ دوباره درخواست بدهید.'];
    }
    if ((int)$row['attempts'] >= 5) {
        db()->prepare('DELETE FROM otp_codes WHERE phone = ?')->execute([$phone98]);
        return [false, 'تلاش‌های ناموفق زیاد بوده است؛ کد جدید بگیرید.'];
    }
    if (!hash_equals((string)$row['code_hash'], hash('sha256', $code))) {
        db()->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([(int)$row['id']]);
        return [false, 'کد واردشده درست نیست.'];
    }
    db()->prepare('DELETE FROM otp_codes WHERE phone = ?')->execute([$phone98]);
    return [true, (int)$row['user_id']];
}
