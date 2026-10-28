<?php
/** การเชื่อมต่อฐานข้อมูล (PDO) ใช้ร่วมกันทุกระบบ */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('[nsokhkaen] DB connect failed: ' . $e->getMessage());
            http_response_code(500);
            exit('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบการตั้งค่าใน config.php');
        }
    }
    return $pdo;
}

/** ค่าตั้งค่าระบบกลาง (core_settings) — โหลดครั้งเดียวต่อ request */
function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT `key`, `value` FROM core_settings') as $r) {
                $cache[$r['key']] = $r['value'];
            }
        } catch (Throwable $e) {
            // ยังไม่ได้สร้างตาราง — ใช้ค่าเริ่มต้น
        }
    }
    return isset($cache[$key]) && $cache[$key] !== '' ? $cache[$key] : $default;
}

function save_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO core_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
        ->execute([$key, $value]);
}
