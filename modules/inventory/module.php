<?php
/** ระบบบริหารจัดการวัสดุ — ทะเบียนระบบ (ดู core/modules.php) */
return [
    'key'         => 'inventory',
    'name'        => 'ระบบบริหารจัดการวัสดุ',
    'short'       => 'วัสดุสิ้นเปลือง',
    'icon'        => 'bi-box-seam',
    'color'       => '#0d6efd',
    'description' => 'สแกน QR Code เบิกวัสดุ ใบเบิก สต๊อกวัสดุ และรายงาน',
    'order'       => 10,
    // เรียงจากสิทธิ์ต่ำ → สูง
    'roles' => [
        'staff' => 'เจ้าหน้าที่ — สแกนเบิกวัสดุ ดูวัสดุ และใบเบิกของตนเอง',
        'admin' => 'ผู้ดูแลระบบวัสดุ — จัดการวัสดุ อนุมัติ/ยกเลิกใบเบิก รายงาน ตั้งค่า',
    ],
    // บทบาทที่ระบบจะชวนให้ตั้ง PIN หลัง login (ใช้หน้าสแกนโดยไม่ต้องพิมพ์รหัสผ่าน)
    'prompt_pin_roles' => ['staff'],
    'menu' => [
        ['id' => 'dashboard',    'label' => 'แดชบอร์ด',        'href' => 'index.php',        'icon' => 'bi-speedometer2',   'role' => 'staff'],
        ['id' => 'scan',         'label' => 'สแกน QR Code',    'href' => 'scan.php',         'icon' => 'bi-qr-code-scan',   'role' => 'staff'],
        ['id' => 'requisitions', 'label' => 'ใบเบิก',           'href' => 'requisitions.php', 'icon' => 'bi-receipt',        'role' => 'staff'],
        ['id' => 'materials',    'label' => 'วัสดุสิ้นเปลือง',   'href' => 'materials.php',    'icon' => 'bi-boxes',          'role' => 'staff'],
        ['id' => 'reports',      'label' => 'รายงาน',           'href' => 'reports.php',      'icon' => 'bi-bar-chart',      'role' => 'admin'],
        ['id' => 'settings',     'label' => 'ตั้งค่าระบบวัสดุ',  'href' => 'settings.php',     'icon' => 'bi-gear',           'role' => 'admin'],
    ],
];
