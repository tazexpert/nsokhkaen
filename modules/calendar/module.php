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
    'roles' => [
        'viewer' => 'ผู้ใช้งาน — ดูตารางการปฏิบัติงาน และกิจกรรมที่ตนรับผิดชอบ',
        'editor' => 'ผู้บันทึกกิจกรรม — เพิ่มกิจกรรม แก้ไข/ลบกิจกรรมที่ตนบันทึก',
        'admin'  => 'ผู้ดูแลปฏิทิน — แก้ไข/ลบกิจกรรมได้ทุกรายการ',
    ],
    'menu' => [
        ['id' => 'month', 'label' => 'ปฏิทินรายเดือน',   'href' => 'index.php',          'icon' => 'bi-calendar3',      'role' => 'viewer'],
        ['id' => 'list',  'label' => 'ตารางกิจกรรม',      'href' => 'list.php',           'icon' => 'bi-list-task',      'role' => 'viewer'],
        ['id' => 'mine',  'label' => 'งานของฉัน',         'href' => 'list.php?mine=1',    'icon' => 'bi-person-check',   'role' => 'viewer'],
        ['id' => 'add',   'label' => 'เพิ่มกิจกรรม',       'href' => 'event_form.php',     'icon' => 'bi-plus-circle',    'role' => 'editor'],
        ['id' => 'import', 'label' => 'นำเข้า CSV',        'href' => 'import.php',         'icon' => 'bi-filetype-csv',   'role' => 'editor'],
        ['id' => 'duty',  'label' => 'นำเข้าเวรสำนักงาน',  'href' => 'duty_import.php',    'icon' => 'bi-shield-check',   'role' => 'editor'],
    ],
];
