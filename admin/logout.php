<?php
/**
 * خروج از بخش مدیریت.
 */
require_once __DIR__ . '/bootstrap.php';
auth_logout();
header('Location: ../?page=login');
exit;
