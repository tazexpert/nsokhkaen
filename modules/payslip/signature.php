<?php
/** รูปลายมือชื่อในสลิป (เก็บในโฟลเดอร์ที่ปิดการเข้าถึงตรง — ส่งให้เฉพาะผู้มีสิทธิ์ในระบบนี้) */
require_once __DIR__ . '/bootstrap.php';
require_module(PAY_MODULE);
$rel = pay_settings()['signature_path'];
$path = $rel !== '' && !str_contains($rel, '..') ? UPLOAD_DIR . '/' . $rel : '';
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit;
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
    http_response_code(415);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($path);
