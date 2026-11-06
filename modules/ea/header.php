<?php
/** ส่วนหัวของหน้าในระบบบริหารโครงการ (ใช้ layout กลาง) — หน้าต้องตรวจสิทธิ์ก่อน require ไฟล์นี้ */
require_once __DIR__ . '/bootstrap.php';
$module = EA_MODULE;
$headExtra = ($headExtra ?? '') . '<link href="' . h(asset_v('modules/ea/assets/css/ea.css')) . '" rel="stylesheet">';
$footerScripts = array_merge([asset_v('modules/ea/assets/js/ea.js')], $footerScripts ?? []);
require APP_ROOT . '/core/layout/header.php';
