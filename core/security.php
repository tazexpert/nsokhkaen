<?php
/**
 * ความปลอดภัยระดับแอปพลิเคชัน (เรียกจาก core/bootstrap.php ทุก request) — อ้างอิง docs/SECURITY.md
 * - HTTP security headers (CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy) + ซ่อน X-Powered-By
 * - ไม่แสดงรายละเอียดข้อผิดพลาดบนหน้าเว็บ (APP_DEBUG = false) · บันทึกลง storage/logs/
 *     php_error.log  = PHP error/warning ทั้งหมด
 *     app_error.log  = exception ที่ไม่ถูกจัดการ (พร้อม URL / ผู้ใช้ / IP) — ผู้ใช้เห็นหน้า "เกิดข้อผิดพลาด" แบบทั่วไป
 */

const APP_LOG_DIR = APP_ROOT . '/storage/logs';

/** เขียน log ของแอปพลิเคชัน (ไฟล์รายเดือน) */
function app_log(string $file, string $message): void
{
    if (!is_dir(APP_LOG_DIR)) {
        @mkdir(APP_LOG_DIR, 0750, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(APP_LOG_DIR . '/' . $file, $line, FILE_APPEND | LOCK_EX);
}

/** CSP: อนุญาตเฉพาะโดเมนที่ระบบใช้จริง (CDN ไลบรารี, ฟอนต์, แผนที่, Power BI) */
function security_csp(): string
{
    return implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com",
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com",
        "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net",
        "img-src 'self' data: blob: https://cdn.jsdelivr.net https://*.tile.openstreetmap.org https://server.arcgisonline.com",
        "connect-src 'self' https://cdn.jsdelivr.net",
        "frame-src 'self' https://app.powerbi.com",
        "media-src 'self' blob:",
        "worker-src 'self' blob:",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ]);
}

function security_init(): void
{
    // ข้อผิดพลาด: ไม่แสดงบนหน้าเว็บ (ยกเว้นตั้ง APP_DEBUG) แต่บันทึกลงไฟล์เสมอ
    ini_set('display_errors', APP_DEBUG ? '1' : '0');
    ini_set('log_errors', '1');
    if (is_dir(APP_LOG_DIR) || @mkdir(APP_LOG_DIR, 0750, true)) {
        ini_set('error_log', APP_LOG_DIR . '/php_error.log');
    }
    error_reporting(E_ALL);

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(self), geolocation=(self), microphone=(), payment=(), usb=()');
        header('Content-Security-Policy: ' . security_csp());
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    // exception ที่ไม่ถูกจัดการ → บันทึก log แล้วแสดงหน้าข้อผิดพลาดแบบทั่วไป (ไม่เปิดเผยรายละเอียด)
    set_exception_handler(function (Throwable $e) {
        $ref = strtoupper(bin2hex(random_bytes(4)));
        app_log('app_error.log', "#$ref " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
            . ' | ' . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . ' | uid=' . ($_SESSION['uid'] ?? '-')
            . ' | ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, (string)$e . PHP_EOL);
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo APP_DEBUG ? '<pre>' . htmlspecialchars((string)$e) . '</pre>'
            : '<!doctype html><meta charset="utf-8"><title>เกิดข้อผิดพลาด</title><div style="font-family:sans-serif;max-width:560px;margin:15vh auto;text-align:center">'
            . '<h2>ขออภัย เกิดข้อผิดพลาดในระบบ</h2><p>กรุณาลองใหม่อีกครั้ง หากยังพบปัญหาโปรดแจ้งผู้ดูแลระบบ พร้อมรหัสอ้างอิง <b>' . $ref . '</b></p>'
            . '<p><a href="javascript:history.back()">ย้อนกลับ</a></p></div>';
    });
}
