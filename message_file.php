<?php
/** เปิด/ดาวน์โหลดไฟล์แนบของข้อความ (เฉพาะผู้ส่ง/ผู้รับ — ไฟล์ใน uploads/messages เปิดตรงจาก URL ไม่ได้) */
require_once __DIR__ . '/core/bootstrap.php';
$user = require_login();
$st = db()->prepare('SELECT * FROM core_message_files WHERE id = ?');
$st->execute([int_param('id')]);
$file = $st->fetch();
$path = $file && !str_contains($file['file_path'], '..') ? UPLOAD_DIR . '/' . $file['file_path'] : null;
if (!$file || !msg_get((int)$file['message_id'], (int)$user['id']) || !$path || !is_file($path)) {
    http_response_code(404);
    exit('ไม่พบไฟล์');
}
$ext = strtolower(pathinfo($file['file_path'], PATHINFO_EXTENSION));
$type = MSG_FILE_TYPES[$ext] ?? 'application/octet-stream';
// เปิดในเบราว์เซอร์ได้เฉพาะ PDF/รูปภาพที่เนื้อไฟล์ตรงกับนามสกุล — อื่น ๆ ให้ดาวน์โหลดเสมอ
$inline = !isset($_GET['download']) && in_array($ext, MSG_INLINE_TYPES, true)
    && (new finfo(FILEINFO_MIME_TYPE))->file($path) === $type;
header('Content-Type: ' . ($inline ? $type : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($file['file_name']));
header('Cache-Control: private, max-age=0');
readfile($path);
