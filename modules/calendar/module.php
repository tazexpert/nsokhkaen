<?php
/** ระบบปฏิทินการปฏิบัติงาน — ทะเบียนระบบ (ดู core/modules.php) */
return [
    'key'         => 'calendar',
    'name'        => 'ระบบปฏิทินการปฏิบัติงาน',
    'short'       => 'ปฏิทินงาน',
    'icon'        => 'bi-calendar-week',
    'logo'        => 'assets/img/logo.svg',
    'color'       => '#0284c7',
    'description' => 'ตารางการปฏิบัติงานของทุกคนในสำนักงาน กิจกรรม สถานที่ และผู้รับผิดชอบ',
    'order'       => 5,
    // ทุกคนที่ login ดูปฏิทินได้ (ไม่ต้องกำหนดสิทธิ์) — ผู้เพิ่มกิจกรรมต้องได้สิทธิ์ "ผู้บันทึกกิจกรรม" ขึ้นไป
    'default_role' => 'viewer',
    // ผู้เยี่ยมชม (ไม่ login) ดูตารางการปฏิบัติงาน/ปฏิทินได้อย่างเดียว
    'guest_role' => 'viewer',
    'roles' => [
        'viewer' => 'ผู้ใช้งาน — ดูตารางการปฏิบัติงาน และกิจกรรมที่ตนรับผิดชอบ',
        'editor' => 'ผู้บันทึกกิจกรรม — เพิ่มกิจกรรม แก้ไข/ลบกิจกรรมที่ตนบันทึก และแก้ไขแผนการปฏิบัติงานของตนเอง',
        'admin'  => 'ผู้ดูแลปฏิทิน — แก้ไข/ลบกิจกรรมได้ทุกรายการ นำเข้ากิจกรรม CSV / เวรสำนักงาน และจัดทำแผนการปฏิบัติงาน',
    ],
    'menu' => [
        ['id' => 'month', 'label' => 'ปฏิทินรายเดือน',   'href' => '',          'icon' => 'bi-calendar3',      'role' => 'viewer'],
        ['id' => 'list',  'label' => 'ตารางกิจกรรม',      'href' => 'list',           'icon' => 'bi-list-task',      'role' => 'viewer'],
        ['id' => 'plan',  'label' => 'แผนการปฏิบัติงาน',  'href' => 'plan',           'icon' => 'bi-grid-3x3',       'role' => 'viewer', 'guest' => false],
        ['id' => 'mine',  'label' => 'งานของฉัน',         'href' => 'list?mine=1',    'icon' => 'bi-person-check',   'role' => 'viewer', 'guest' => false],
        ['id' => 'add',   'label' => 'เพิ่มกิจกรรม',       'href' => 'event_form',     'icon' => 'bi-plus-circle',    'role' => 'editor'],
        ['id' => 'import', 'label' => 'นำเข้า CSV',        'href' => 'import',         'icon' => 'bi-filetype-csv',   'role' => 'admin'],
        ['id' => 'duty',  'label' => 'นำเข้าเวรสำนักงาน',  'href' => 'duty_import',    'icon' => 'bi-shield-check',   'role' => 'admin'],
        ['id' => 'att',   'label' => 'นำเข้าเวลาปฏิบัติราชการ', 'href' => 'att_import', 'icon' => 'bi-clock-history', 'role' => 'admin'],
    ],
];
