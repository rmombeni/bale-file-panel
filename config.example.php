<?php
/**
 * تنظیمات سراسری برنامه — به‌صورت خودکار توسط install.php ساخته شد.
 * مقادیر بله در اینجا «پیش‌فرض» هستند؛ هر کاربر می‌تواند در تنظیمات خودش
 * توکن و شناسهٔ چت جداگانه بگذارد و آن مقدار بر این پیش‌فرض مقدم می‌شود.
 */

date_default_timezone_set('Asia/Tehran');

/* ---------- دیتابیس ---------- */
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'bale_panel';
const DB_USER = 'root';
const DB_PASS = '';

/* ---------- برنامه ---------- */
const APP_TITLE   = 'پنل فایل';
const APP_VERSION = '2.4';
const DATA_FILE   = __DIR__ . '/data.php';       // فقط برای مهاجرت اولیه
const STORAGE_DIR = __DIR__ . '/storage';

/* ---------- پیش‌فرض بله (قابل تغییر از تنظیمات مدیر) ---------- */
const BOT_TOKEN_DEFAULT = '';
const CHAT_ID_DEFAULT   = '';
const SEND_TO_CHAT_DEFAULT = '';

/* ---------- رمز حساب مدیر اولیه (فقط بار اول؛ نصاب رمز واقعی را در دیتابیس ذخیره می‌کند) ---------- */
const ADMIN_PASSWORD_SEED = 'change-me';

/* ---------- محدودیت‌ها ---------- */
const MAX_UP    = 50 * 1024 * 1024;
const MAX_DL    = 20 * 1024 * 1024;
const MAX_CHUNK = 15 * 1024 * 1024;
const MAX_LOGICAL_UPLOAD = 5 * 1024 * 1024 * 1024;
const THUMB_MAX = 3 * 1024 * 1024;

/* ---------- امنیت ---------- */
const MAX_LOGIN_FAILS = 8;          // تعداد تلاش ناموفق پیش از قفل کوتاه
const LOGIN_WINDOW    = 900;        // پنجرهٔ شمارش (ثانیه)
const REMEMBER_DAYS   = 30;
