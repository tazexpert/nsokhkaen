<?php
/** API การแจ้งเตือน: POST action=seen → บันทึกว่าเปิดดูแล้ว (ล้างตัวเลขบนกระดิ่ง) */
require_once __DIR__ . '/../core/bootstrap.php';
$user = current_user();
if (!$user) {
    json_out(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    json_out(['success' => false, 'message' => 'คำขอไม่ถูกต้อง'], 400);
}
if (($_POST['action'] ?? '') === 'seen') {
    db()->prepare('UPDATE core_users SET notif_seen_at = NOW() WHERE id = ?')->execute([$user['id']]);
}
json_out(['success' => true]);
