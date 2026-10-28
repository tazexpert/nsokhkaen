<?php
/** ค่าตั้งค่าเฉพาะระบบวัสดุและครุภัณฑ์ (inv_settings): ผู้ลงนามในใบเบิก/ใบยืม และเกณฑ์แจ้งเตือนวัสดุใกล้หมด */
require_once __DIR__ . '/../bootstrap.php';

requireAdminApi();
$pdo = getDbConnection();
$action = $_REQUEST['action'] ?? '';
const INV_SETTING_KEYS = ['issuer_name', 'issuer_position', 'approver_name', 'approver_position',
    'loan_org_phone', 'loan_org_fax', 'loan_to', 'loan_approver_name', 'loan_approver_position', 'low_stock_default'];

switch ($action) {
    case 'get':
        $settings = array_merge(array_fill_keys(INV_SETTING_KEYS, ''), LOAN_SETTING_DEFAULTS);
        foreach ($pdo->query('SELECT `key`, `value` FROM inv_settings') as $row) {
            $settings[$row['key']] = $row['value'];
        }
        jsonResponse(['success' => true, 'data' => $settings]);
        break;
    case 'update':
        $stmt = $pdo->prepare('INSERT INTO inv_settings (`key`, `value`) VALUES (:key, :value)
            ON DUPLICATE KEY UPDATE `value` = :value2');
        foreach (INV_SETTING_KEYS as $key) {
            if (!isset($_POST[$key])) {
                continue;                      // บันทึกเฉพาะช่องที่ส่งมา (แต่ละแท็บบันทึกแยกกัน)
            }
            $value = mb_substr(sanitizeString($_POST[$key]), 0, 255);
            if ($key === 'low_stock_default') {
                $value = (string)max(0, (int)$value);
            }
            $stmt->execute(['key' => $key, 'value' => $value, 'value2' => $value]);
        }
        jsonResponse(['success' => true, 'message' => 'บันทึกค่าระบบเรียบร้อยแล้ว']);
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'ไม่พบคำสั่งที่ร้องขอ'], 400);
}
