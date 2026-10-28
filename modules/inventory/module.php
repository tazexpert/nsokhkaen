<?php
/** ระบบบริหารจัดการวัสดุและครุภัณฑ์ — ทะเบียนระบบ (ดู core/modules.php) */
return [
    'key'         => 'inventory',
    'name'        => 'ระบบบริหารจัดการวัสดุและครุภัณฑ์',
    'short'       => 'วัสดุและครุภัณฑ์',
    'icon'        => 'bi-box-seam',
    'logo'        => 'assets/img/logo.svg',              // รูปโลโก้ระบบ (สัมพัทธ์จากโฟลเดอร์ระบบ)
    'color'       => '#0d6efd',
    'description' => 'เบิกวัสดุด้วย QR Code สต๊อกวัสดุ ทะเบียนครุภัณฑ์ และการยืม-คืนครุภัณฑ์',
    'order'       => 10,
    // เรียงจากสิทธิ์ต่ำ → สูง
    'roles' => [
        'staff' => 'เจ้าหน้าที่ — สแกนเบิกวัสดุ ขอยืมครุภัณฑ์ ดูรายการของตนเอง',
        'admin' => 'ผู้ดูแลระบบวัสดุและครุภัณฑ์ — จัดการวัสดุ/ครุภัณฑ์ อนุมัติใบเบิกและการยืม-คืน รายงาน ตั้งค่า',
    ],
    // บทบาทที่ระบบจะชวนให้ตั้ง PIN หลัง login (ใช้หน้าสแกนโดยไม่ต้องพิมพ์รหัสผ่าน)
    'prompt_pin_roles' => ['staff'],
    'menu' => [
        ['id' => 'dashboard',    'label' => 'แดชบอร์ด',        'href' => 'index.php',        'icon' => 'bi-speedometer2',   'role' => 'staff'],
        ['id' => 'scan',         'label' => 'สแกน QR Code',    'href' => 'scan.php',         'icon' => 'bi-qr-code-scan',   'role' => 'staff'],
        ['id' => 'requisitions', 'label' => 'ใบเบิกวัสดุ',      'href' => 'requisitions.php', 'icon' => 'bi-receipt',        'role' => 'staff'],
        ['id' => 'materials',    'label' => 'วัสดุสิ้นเปลือง',   'href' => 'materials.php',    'icon' => 'bi-boxes',          'role' => 'staff'],
        ['id' => 'equipment',    'label' => 'ครุภัณฑ์',          'href' => 'equipment.php',    'icon' => 'bi-laptop',         'role' => 'staff'],
        ['id' => 'loans',        'label' => 'ยืม-คืนครุภัณฑ์',   'href' => 'loans.php',        'icon' => 'bi-arrow-left-right', 'role' => 'staff'],
        ['id' => 'reports',      'label' => 'รายงาน',           'href' => 'reports.php',      'icon' => 'bi-bar-chart',      'role' => 'admin'],
        ['id' => 'settings',     'label' => 'ตั้งค่าระบบ',       'href' => 'settings.php',     'icon' => 'bi-gear',           'role' => 'admin'],
    ],
];
