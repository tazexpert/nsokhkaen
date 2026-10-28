<?php
/** ค่าตั้งค่าเฉพาะระบบวัสดุ (inv_settings): ผู้จ่าย/ผู้อนุมัติที่พิมพ์ในใบเบิก */
require_once __DIR__ . '/../bootstrap.php';

requireAdminApi();
$pdo = getDbConnection();
$action = $_REQUEST['action'] ?? '';
const INV_SETTING_KEYS = ['issuer_name', 'issuer_position', 'approver_name', 'approver_position'];

switch ($action) {
    case 'get':
        $settings = array_fill_keys(INV_SETTING_KEYS, '');
        foreach ($pdo->query('SELECT `key`, `value` FROM inv_settings') as $row) {
            $settings[$row['key']] = $row['value'];
        }
        jsonResponse(['success' => true, 'data' => $settings]);
        break;
    case 'update':
        $stmt = $pdo->prepare('INSERT INTO inv_settings (`key`, `value`) VALUES (:key, :value)
            ON DUPLICATE KEY UPDATE `value` = :value2');
        foreach (INV_SETTING_KEYS as $key) {
            $value = sanitizeString($_POST[$key] ?? '');
            $stmt->execute(['key' => $key, 'value' => $value, 'value2' => $value]);
        }
        jsonResponse(['success' => true, 'message' => 'บันทึกค่าระบบเรียบร้อยแล้ว']);
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'ไม่พบคำสั่งที่ร้องขอ'], 400);
}
