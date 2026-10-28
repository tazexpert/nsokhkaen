<?php
/** API ค่าที่ผู้ใช้ตั้งเอง: POST action=module_order&order[]=key... (ว่าง = คืนค่าลำดับเดิม) */
require_once __DIR__ . '/../core/bootstrap.php';
$user = current_user();
if (!$user) {
    json_out(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    json_out(['success' => false, 'message' => 'คำขอไม่ถูกต้อง'], 400);
}
if (($_POST['action'] ?? '') === 'module_order') {
    $valid = array_keys(modules());
    $order = array_values(array_unique(array_filter((array)($_POST['order'] ?? []), fn($k) => is_string($k) && in_array($k, $valid, true))));
    save_user_pref($user['id'], 'module_order', json_encode($order));
    json_out(['success' => true]);
}
json_out(['success' => false, 'message' => 'ไม่รู้จักคำสั่ง'], 400);
