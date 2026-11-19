<?php
/** ส่วนหัวของหน้าในระบบงบประมาณ (ใช้ layout กลาง) — หน้าต้องตรวจสิทธิ์ก่อน require ไฟล์นี้ */
require_once __DIR__ . '/bootstrap.php';
$module = BUD_MODULE;
$headExtra = ($headExtra ?? '') . '<link href="' . h(asset_v('modules/budget/assets/css/budget.css')) . '" rel="stylesheet">';
$bodyClass = trim(($bodyClass ?? '') . ' mod-theme theme-budget');   // โทนสีของระบบ (assets/css/app.css)
require APP_ROOT . '/core/layout/header.php';
