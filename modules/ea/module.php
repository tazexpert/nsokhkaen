<?php
/** ระบบบริหารโครงการ (โครงการที่รับผิดชอบ + EA เขตแจงนับ จังหวัดขอนแก่น) — ทะเบียนระบบ (ดู core/modules.php) */
return [
    'key'         => 'ea',
    'name'        => 'ระบบบริหารโครงการ',
    'short'       => 'บริหารโครงการ',
    'icon'        => 'bi-map',
    'logo'        => 'assets/img/logo.svg',
    'color'       => '#0f766e',
    'description' => 'โครงการที่รับผิดชอบของสำนักงาน บัญชีเขตแจงนับ (EA) จังหวัดขอนแก่น และ EA ที่แต่ละโครงการใช้สำรวจ — มอบหมายผู้ปฏิบัติงาน และติดตามความก้าวหน้า',
    'order'       => 35,
    'default_role' => 'viewer',                       // บุคลากรทุกคนดูโครงการ บัญชี EA และ EA ของโครงการได้
    'roles' => [
        'viewer' => 'ผู้ดูข้อมูล — ดูโครงการ บัญชี EA และ EA ที่โครงการใช้สำรวจ',
        'editor' => 'เจ้าหน้าที่โครงการ — เห็นเฉพาะโครงการและ EA ที่ตนรับผิดชอบ และอัปเดตสถานะการสำรวจ',
        'admin'  => 'ผู้ดูแลระบบบริหารโครงการ — จัดการโครงการ กำหนด EA ผู้ปฏิบัติงาน นำเข้าไฟล์ และทำได้ทั้งหมด',
    ],
    'menu' => [
        ['id' => 'dashboard', 'label' => 'ภาพรวม',           'href' => 'index.php',    'icon' => 'bi-speedometer2', 'role' => 'viewer'],
        ['id' => 'projects',  'label' => 'โครงการที่รับผิดชอบ', 'href' => 'projects.php', 'icon' => 'bi-kanban',       'role' => 'viewer'],
        ['id' => 'assign',    'label' => 'มอบหมายผู้ปฏิบัติงาน', 'href' => 'assign.php',  'icon' => 'bi-shuffle',      'role' => 'admin'],
        ['id' => 'eas',       'label' => 'บัญชี EA',          'href' => 'eas.php',      'icon' => 'bi-map',          'role' => 'viewer'],
        ['id' => 'pimport',   'label' => 'นำเข้า EA โครงการ',  'href' => 'project_import.php', 'icon' => 'bi-filetype-csv', 'role' => 'admin'],
        ['id' => 'import',    'label' => 'นำเข้าบัญชี EA',     'href' => 'import.php',   'icon' => 'bi-file-earmark-arrow-up', 'role' => 'admin'],
    ],
];
