<?php
/** ระบบปฏิทินการปฏิบัติงาน — ทะเบียนระบบ (ดู core/modules.php) */
return [
    'key'         => 'calendar',
    'name'        => 'ระบบปฏิทินการปฏิบัติงาน',
    'short'       => 'ปฏิทินงาน',
    'icon'        => 'bi-calendar-week',
    'logo'        => 'assets/img/logo.svg',
    'color'       => '#0ea5c9',
    'description' => 'ตารางการปฏิบัติงานของทุกคนในสำนักงาน กิจกรรม สถานที่ และผู้รับผิดชอบ',
    'order'       => 5,
    // ทุกคนที่ login ดูปฏิทินได้ (ไม่ต้องกำหนดสิทธิ์) — ผู้เพิ่มกิจกรรมต้องได้สิทธิ์ "ผู้บันทึกกิจกรรม" ขึ้นไป
    'default_role' => 'viewer',
    // ผู้เยี่ยมชม (ไม่ login) ดูตารางการปฏิบัติงาน/ปฏิทินได้อย่างเดียว
    'guest_role' => 'viewer',
    'roles' => [
        'viewer' => 'ผู้ใช้งาน — ดูตารางการปฏิบัติงาน กิจกรรมที่ตนรับผิดชอบ และแก้ไขแผนการปฏิบัติงานของตนเอง',
        'editor' => 'ผู้บันทึกกิจกรรม — เพิ่มกิจกรรม แก้ไข/ลบกิจกรรมที่ตนบันทึก และจัดเวรกิจกรรม',
        'admin'  => 'ผู้ดูแลปฏิทิน — แก้ไข/ลบกิจกรรมได้ทุกรายการ นำเข้าเวรสำนักงาน / เวรศาลากลาง / เวลาปฏิบัติราชการ กำหนดวันหยุด จัดทำแผนการปฏิบัติงาน จัดเวรกิจกรรม และแก้ไขรายชื่อ/กลุ่มเวรกิจกรรม',
    ],
    'menu' => [
        ['id' => 'month', 'label' => 'ปฏิทินรายเดือน',   'href' => '',          'icon' => 'bi-calendar3',      'role' => 'viewer'],
        ['id' => 'list',  'label' => 'ตารางกิจกรรม',      'href' => 'list',           'icon' => 'bi-list-task',      'role' => 'viewer'],
        ['id' => 'plan',  'label' => 'แผนการปฏิบัติงาน',  'href' => 'plan',           'icon' => 'bi-grid-3x3',       'role' => 'viewer', 'guest' => false],
        ['id' => 'mine',  'label' => 'งานของฉัน',         'href' => 'list?mine=1',    'icon' => 'bi-person-check',   'role' => 'viewer', 'guest' => false],
        ['id' => 'add',   'label' => 'เพิ่มกิจกรรม',       'href' => 'event_form',     'icon' => 'bi-plus-circle',    'role' => 'editor'],
        ['id' => 'duty',  'label' => 'นำเข้าเวรสนง./ศาลากลาง',  'href' => 'duty_import',    'icon' => 'bi-shield-check',   'role' => 'admin'],
        ['id' => 'holiday', 'label' => 'วันหยุด',          'href' => 'holidays',       'icon' => 'bi-flag',           'role' => 'admin'],
        ['id' => 'att',   'label' => 'นำเข้าเวลาปฏิบัติราชการ', 'href' => 'att_import', 'icon' => 'bi-clock-history', 'role' => 'admin'],
    ],
];
