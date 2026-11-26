<?php
/** ระบบงบประมาณ — รับเข้างบประมาณ (ได้รับ) / เบิกจ่ายงบประมาณ แดชบอร์ดและรายงานรายเดือน/รายปีงบ (ดู core/modules.php) */
return [
    'key'         => 'budget',
    'name'        => 'ระบบงบประมาณ',
    'short'       => 'งบประมาณ',
    'icon'        => 'bi-cash-coin',
    'logo'        => 'assets/img/logo.svg',
    'color'       => '#ed7922',
    'description' => 'บันทึกรับเข้างบประมาณ (ได้รับ) และเบิกจ่ายงบประมาณ แยกตามแผนงาน งานและโครงการ และหมวด — ทะเบียนคุมเงินประจำงวดส่วนจังหวัด แดชบอร์ดและรายงานรายเดือน / รายปีงบประมาณ',
    'order'       => 38,
    'default_role' => 'user',                         // บุคลากรทุกคนดูหน้าภาพรวมได้ (รายการ/รายงาน = ผู้ดูรายงานงบขึ้นไป)
    'roles' => [
        'user'   => 'ผู้ใช้งาน — ดูหน้าภาพรวมงบประมาณ',
        'viewer' => 'ผู้ดูรายงานงบ — ดูแดชบอร์ด รายการ และรายงานงบประมาณ',
        'editor' => 'ผู้จัดทำงบประมาณ — บันทึก แก้ไข ลบ รายการรับเข้าและเบิกจ่ายงบประมาณ',
        'admin'  => 'ผู้ดูแลระบบงบประมาณ — ทำได้ทั้งหมด และจัดการแผนงาน งานและโครงการ และหมวด',
    ],
    'menu' => [
        ['id' => 'dashboard', 'label' => 'ภาพรวม',            'href' => '',               'icon' => 'bi-speedometer2',   'role' => 'user'],
        ['id' => 'receive',   'label' => 'รับเข้างบประมาณ',     'href' => 'txns?type=receive',   'icon' => 'bi-box-arrow-in-down', 'role' => 'viewer'],
        ['id' => 'spend',     'label' => 'เบิกจ่ายงบประมาณ',    'href' => 'txns?type=spend',     'icon' => 'bi-box-arrow-up',   'role' => 'viewer'],
        ['id' => 'transfer',  'label' => 'ทะเบียนคุมเงินประจำงวด (ยอดโอน)', 'href' => 'transfer',            'icon' => 'bi-arrow-left-right', 'role' => 'viewer'],
        ['id' => 'ledger',    'label' => 'ทะเบียนคุมเงินประจำงวด (รายหมวด)', 'href' => 'ledger',             'icon' => 'bi-journal-text',   'role' => 'viewer'],
        ['id' => 'report',    'label' => 'รายงาน',             'href' => 'report',              'icon' => 'bi-file-earmark-bar-graph', 'role' => 'viewer'],
        ['id' => 'items',     'label' => 'แผนงานและหมวด',      'href' => 'items',               'icon' => 'bi-list-check',     'role' => 'admin'],
    ],
];
