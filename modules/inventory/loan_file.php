<?php
/** เปิด/ดาวน์โหลดไฟล์ใบยืมที่แนบ (ตรวจสิทธิ์ก่อน — ไฟล์ใน uploads/inventory/loans เปิดตรงจาก URL ไม่ได้) */
require_once __DIR__ . '/bootstrap.php';
require_module(INV_MODULE);
$st = db()->prepare('SELECT * FROM inv_loans WHERE id = ?');
$st->execute([int_param('id')]);
$loan = $st->fetch();
$path = $loan && $loan['file_path'] && !str_contains($loan['file_path'], '..') ? UPLOAD_DIR . '/' . $loan['file_path'] : null;
if (!$loan || !can_view_loan($loan) || !$path || !is_file($path)) {
    http_response_code(404);
    exit('ไม่พบไฟล์ใบยืม');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!isset(LOAN_FILE_TYPES[$mime])) {
    http_response_code(415);
    exit('ชนิดไฟล์ไม่ถูกต้อง');
}
$name = $loan['file_name'] ?: basename($path);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Disposition: " . (isset($_GET['download']) ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode($name));
header('Cache-Control: private, max-age=0');
readfile($path);
