<?php
/**
 * ส่วนหัวของหน้าในระบบวัสดุ: ตรวจสิทธิ์ตามหน้า แล้วใช้ layout กลาง
 * ตั้งตัวแปร $user (รูปแบบเดิม มี role ของระบบวัสดุ) ให้หน้าที่เรียกใช้
 */
require_once __DIR__ . '/bootstrap.php';

$currentPage = basename($_SERVER['PHP_SELF']);
// หน้า => [เมนูที่เลือก, หัวข้อ, สิทธิ์ขั้นต่ำ]
$__invPages = [
    'index.php'            => ['dashboard', 'แดชบอร์ด', ''],
    'scan.php'             => ['scan', 'สแกน QR Code เพื่อเบิกวัสดุ', ''],
    'requisitions.php'     => ['requisitions', 'ใบเบิกวัสดุ', ''],
    'requisition_view.php' => ['requisitions', 'รายละเอียดใบเบิก', ''],
    'materials.php'        => ['materials', 'วัสดุสิ้นเปลือง', ''],
    'reports.php'          => ['reports', 'รายงานการเบิกวัสดุ', 'admin'],
    'settings.php'         => ['settings', 'ตั้งค่าระบบวัสดุ', 'admin'],
];
[$active, $pageTitle, $__minRole] = $__invPages[$currentPage] ?? ['', 'ระบบบริหารจัดการวัสดุ', ''];

if ($currentPage === 'scan.php') {
    requireScanAccess();               // ยังไม่ login → หน้ากรอก PIN
} else {
    require_module(INV_MODULE, $__minRole);
}

$module = INV_MODULE;
$headExtra = '<link href="' . h(INV_URL . 'assets/css/inventory.css' . assetVersion('assets/css/inventory.css')) . '" rel="stylesheet">'
    . '<script>const INV_URL_JS = ' . json_encode(INV_URL) . ';</script>';
require APP_ROOT . '/core/layout/header.php';

$user = currentUser();
$loggedInViaPin = $user['via_pin'] ?? false;
unset($__invPages, $__minRole);
