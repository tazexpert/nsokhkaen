<?php
/**
 * ตั้งค่าการเชื่อมต่อฐานข้อมูล (แก้ไขให้ตรงกับเซิร์ฟเวอร์)
 * ค่าอื่น ๆ เช่น ชื่อระบบ และ URL ของระบบ (APP_URL) แก้ได้ที่เมนู "ผู้ดูแลระบบกลาง → ตั้งค่าระบบ"
 *
 * ถ้าไม่อยากแก้ไฟล์นี้ (เช่น ตอน git pull) ให้สร้างไฟล์ config.local.php แล้ว define ค่าที่ต้องการแทน
 * ไฟล์ config.local.php ไม่ถูกเก็บใน git
 */
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
defined('DB_PORT') || define('DB_PORT', getenv('DB_PORT') ?: '3306');
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: 'nsokhkaen');
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: 'root');
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

// ใช้เมื่อยังไม่ได้ตั้งค่า app_url ในฐานข้อมูล (ต้องลงท้ายด้วย /)
defined('DEFAULT_APP_URL') || define('DEFAULT_APP_URL', getenv('APP_URL') ?: 'http://localhost/nsokhkaen/');

// ความปลอดภัย / ความน่าเชื่อถือ (ดู docs/SECURITY.md) — เปลี่ยนค่าได้ใน config.local.php
defined('APP_DEBUG') || define('APP_DEBUG', false);                 // true = แสดงรายละเอียดข้อผิดพลาดบนหน้าเว็บ (ใช้ตอนพัฒนาเท่านั้น)
defined('SESSION_IDLE_MINUTES') || define('SESSION_IDLE_MINUTES', 120);   // ไม่มีการใช้งานเกินนี้ → ออกจากระบบอัตโนมัติ (0 = ไม่จำกัด)
defined('DB_PERSISTENT') || define('DB_PERSISTENT', false);         // true = connection pooling (PDO persistent) — ดู docs/SECURITY.md ก่อนเปิด

date_default_timezone_set('Asia/Bangkok');
