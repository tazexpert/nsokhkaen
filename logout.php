<?php
require_once __DIR__ . '/core/bootstrap.php';
$viaPin = !empty($_SESSION['via_pin']);
if (!empty($_SESSION['uid'])) {
    audit_log('logout');
}
logout_user();
redirect(url($viaPin ? 'pin.php' : 'index.php'));      // หน้าหลักดูได้โดยไม่ต้อง login (ผู้เยี่ยมชม)
