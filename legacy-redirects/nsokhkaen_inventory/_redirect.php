<?php
/** ส่งต่อ URL ของระบบวัสดุเดิมไปยังหน้าที่ตรงกันในระบบใหม่ (คง query string เช่น ?qr= และ ?id=) */
require __DIR__ . '/redirect-config.php';

function legacy_redirect(string $newPath): never
{
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . NEW_APP_URL . $newPath . ($qs !== '' ? '?' . $qs : ''), true, 301);
    exit;
}
