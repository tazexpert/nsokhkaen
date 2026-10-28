<?php
/** ส่วนหัวของหน้าหลังบ้านระบบศูนย์ดิจิทัลชุมชน (ใช้ layout กลาง) — หน้าต้องตรวจสิทธิ์ก่อน require ไฟล์นี้ */
require_once __DIR__ . '/bootstrap.php';
$module = DCC_MODULE;
$__page = basename($_SERVER['PHP_SELF']);
$useLeaflet = in_array($__page, ['center_form.php', 'geocode.php', 'visit_form.php'], true);
$headExtra = '<link href="' . h(asset_v('modules/digitalcc/assets/css/dcc-admin.css')) . '" rel="stylesheet">'
    . '<script>const DCC_URL_JS = ' . json_encode(DCC_URL) . ';</script>';
$footerScripts = [asset_v('modules/digitalcc/assets/js/dcc-admin.js')];
if ($__page === 'geocode.php') {
    $footerScripts[] = asset_v('modules/digitalcc/assets/js/geocode.js');
}
unset($__page);
require APP_ROOT . '/core/layout/header.php';
