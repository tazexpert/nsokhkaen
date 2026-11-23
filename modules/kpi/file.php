<?php
/** เปิด/ดาวน์โหลดไฟล์หลักฐานตัวชี้วัด — ผู้ใช้ระบบตัวชี้วัดที่ login (ไฟล์ใน uploads/kpi เปิดตรงจาก URL ไม่ได้) */
require_once __DIR__ . '/bootstrap.php';
require_module(KPI_MODULE);
$st = db()->prepare('SELECT * FROM kpi_files WHERE id = ?');
$st->execute([int_param('id')]);
$file = $st->fetch();
$path = $file && !str_contains($file['file_path'], '..') ? UPLOAD_DIR . '/' . $file['file_path'] : null;
if (!$file || !$path || !is_file($path)) {
    http_response_code(404);
    exit('ไม่พบไฟล์');
}
$ext = strtolower(pathinfo($file['file_path'], PATHINFO_EXTENSION));
$type = MSG_FILE_TYPES[$ext] ?? 'application/octet-stream';
// เปิดในเบราว์เซอร์ได้เฉพาะ PDF/รูปภาพที่เนื้อไฟล์ตรงกับนามสกุล — อื่น ๆ ดาวน์โหลดเสมอ
$inline = !isset($_GET['download']) && in_array($ext, MSG_INLINE_TYPES, true)
    && (new finfo(FILEINFO_MIME_TYPE))->file($path) === $type;
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . ($inline ? $type : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($file['file_name']));
header('Cache-Control: private, max-age=0');
readfile($path);
