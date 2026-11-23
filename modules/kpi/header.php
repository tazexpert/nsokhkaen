<?php
/** ส่วนหัวของหน้าในระบบตัวชี้วัด (ใช้ layout กลาง) — หน้าต้องตรวจสิทธิ์ก่อน require ไฟล์นี้ */
require_once __DIR__ . '/bootstrap.php';
$module = KPI_MODULE;
$headExtra = ($headExtra ?? '') . '<link href="' . h(asset_v('modules/calendar/assets/css/calendar.css')) . '" rel="stylesheet">'   // ช่องเลือกผู้ปฏิบัติแบบปฏิทิน
    . '<link href="' . h(asset_v('modules/kpi/assets/css/kpi.css')) . '" rel="stylesheet">';
$bodyClass = trim(($bodyClass ?? '') . ' mod-theme theme-kpi');   // โทนสีของระบบ (assets/css/app.css)
require APP_ROOT . '/core/layout/header.php';
