<?php
/**
 * จุดเริ่มต้นของทุกหน้า/ทุก API:  require_once __DIR__ . '/../core/bootstrap.php';
 * กำหนดค่าคงที่:
 *   APP_ROOT   โฟลเดอร์โปรเจกต์ (filesystem)
 *   BASE_URL   path ของระบบบนเว็บ เช่น /nsokhkaen/ (ตรวจจากตำแหน่งไฟล์จริง ไม่ขึ้นกับค่าตั้งค่า)
 *   APP_URL    URL เต็มสำหรับลิงก์ที่ใช้นอกเบราว์เซอร์นี้ เช่น QR Code (ตั้งค่าได้ที่เมนูตั้งค่าระบบ)
 *   APP_NAME, ORG_NAME, UPLOAD_DIR
 */
define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', APP_ROOT . '/uploads');

require_once APP_ROOT . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/modules.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/messages.php';

/** path ของโปรเจกต์บนเว็บ คำนวณจากไฟล์ที่ถูกเรียกเทียบกับ APP_ROOT */
function detect_base_url(): string
{
    if (PHP_SAPI === 'cli') {
        return '/';
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = str_replace('\\', '/', APP_ROOT);
    $name = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $realScript = str_replace('\\', '/', (string)(realpath($script) ?: $script));
    $realRoot = str_replace('\\', '/', (string)(realpath($root) ?: $root));
    if ($realScript !== '' && str_starts_with($realScript, $realRoot . '/')) {
        $rel = substr($realScript, strlen($realRoot));          // เช่น /modules/inventory/scan.php
        if (str_ends_with($name, $rel)) {
            return rtrim(substr($name, 0, -strlen($rel)), '/') . '/';
        }
    }
    return rtrim(parse_url(DEFAULT_APP_URL, PHP_URL_PATH) ?: '/', '/') . '/';
}

define('BASE_URL', detect_base_url());

$__appUrl = setting('app_url');
if (!$__appUrl && PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
    $__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__appUrl = $__scheme . '://' . $_SERVER['HTTP_HOST'] . BASE_URL;
}
define('APP_URL', rtrim($__appUrl ?: DEFAULT_APP_URL, '/') . '/');
define('APP_NAME', setting('app_name', 'ระบบบริหารจัดการ สำนักงานสถิติจังหวัดขอนแก่น'));
define('ORG_NAME', setting('org_name', 'สำนักงานสถิติจังหวัดขอนแก่น'));
unset($__appUrl, $__scheme);

start_session();
