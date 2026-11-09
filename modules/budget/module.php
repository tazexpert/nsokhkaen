<?php
/** ระบบงบประมาณ — รับเข้างบประมาณ (ได้รับ) / เบิกจ่ายงบประมาณ แดชบอร์ดและรายงานรายเดือน/รายปีงบ (ดู core/modules.php) */
return [
    'key'         => 'budget',
    'name'        => 'ระบบงบประมาณ',
    'short'       => 'งบประมาณ',
    'icon'        => 'bi-cash-coin',
    'logo'        => 'assets/img/logo.svg',
    'color'       => '#15803d',
    'description' => 'บันทึกรับเข้างบประมาณ (ได้รับ) และเบิกจ่ายงบประมาณ แยกตามโครงการสำรวจและรายการงบประมาณ — แดชบอร์ดและรายงานรายเดือน / รายปีงบประมาณ',
    'order'       => 38,
    // ไม่มีสิทธิ์อัตโนมัติ: ผู้ดูแลระบบกลางกำหนดรายคนที่ ผู้ใช้งานและสิทธิ์
    'roles' => [
        'viewer' => 'ผู้ดูรายงานงบ — ดูแดชบอร์ด รายการ และรายงานงบประมาณ',
        'editor' => 'ผู้จัดทำงบประมาณ — บันทึก แก้ไข ลบ รายการรับเข้าและเบิกจ่ายงบประมาณ',
        'admin'  => 'ผู้ดูแลระบบงบประมาณ — ทำได้ทั้งหมด และจัดการรายการงบประมาณ (ชื่อโครงการที่ได้รับ)',
    ],
    'menu' => [
        ['id' => 'dashboard', 'label' => 'ภาพรวม',            'href' => 'index.php',               'icon' => 'bi-speedometer2',   'role' => 'viewer'],
        ['id' => 'receive',   'label' => 'รับเข้างบประมาณ',     'href' => 'txns.php?type=receive',   'icon' => 'bi-box-arrow-in-down', 'role' => 'viewer'],
        ['id' => 'spend',     'label' => 'เบิกจ่ายงบประมาณ',    'href' => 'txns.php?type=spend',     'icon' => 'bi-box-arrow-up',   'role' => 'viewer'],
        ['id' => 'report',    'label' => 'รายงาน',             'href' => 'report.php',              'icon' => 'bi-file-earmark-bar-graph', 'role' => 'viewer'],
        ['id' => 'items',     'label' => 'รายการงบประมาณ',      'href' => 'items.php',               'icon' => 'bi-list-check',     'role' => 'admin'],
    ],
];
