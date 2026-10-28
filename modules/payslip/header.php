<?php
/** ส่วนหัวของหน้าในระบบสลิปเงินเดือน (ใช้ layout กลาง) — หน้าต้องตรวจสิทธิ์ก่อน require ไฟล์นี้ */
require_once __DIR__ . '/bootstrap.php';
$module = PAY_MODULE;
$headExtra = ($headExtra ?? '') . '<link href="' . h(asset_v('modules/payslip/assets/css/payslip.css')) . '" rel="stylesheet">';
require APP_ROOT . '/core/layout/header.php';
