<?php
/** ส่วนหัวของหน้าในระบบปฏิทิน (ใช้ layout กลาง) — หน้าต้องตรวจสิทธิ์ก่อน require ไฟล์นี้ */
require_once __DIR__ . '/bootstrap.php';
$module = CAL_MODULE;
$headExtra = ($headExtra ?? '') . '<link href="' . h(asset_v('modules/calendar/assets/css/calendar.css')) . '" rel="stylesheet">';
$bodyClass = trim(($bodyClass ?? '') . ' cal-theme');          // โทนสีเดียวกับปฏิทินรายเดือนทุกหน้าในเมนูปฏิทินงาน
require APP_ROOT . '/core/layout/header.php';
