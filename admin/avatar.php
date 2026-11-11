<?php
/**
 * تحویل امن اوتار کاربران — نیازمند ورود مدیر؛ فایل‌ها از دسترسی مستقیم در بسته‌اند.
 */
require_once __DIR__ . '/bootstrap.php';

$uid = (int)($_GET['uid'] ?? 0);
$st = db()->prepare('SELECT avatar FROM users WHERE id = ? LIMIT 1');
$st->execute([$uid]);
$file = (string)$st->fetchColumn();

$path = $file !== '' ? avatar_dir() . '/' . basename($file) : '';
if ($path === '' || !is_file($path)) { http_response_code(404); exit('Not found'); }

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
session_write_close();
header('Content-Type: ' . avatar_mime($ext));
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=600');
readfile($path);
exit;
