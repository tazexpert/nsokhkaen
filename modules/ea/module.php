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
        'editor' => 'เจ้าหน้าที่โครงการ — กำหนด EA ให้โครงการ ผู้รับผิดชอบ และสถานะการสำรวจ',
        'admin'  => 'ผู้ดูแลระบบบริหารโครงการ — จัดการโครงการที่รับผิดชอบ นำเข้า/แก้ไขบัญชี EA และทำได้ทั้งหมด',
    ],
    'menu' => [
        ['id' => 'dashboard', 'label' => 'ภาพรวม',           'href' => 'index.php',    'icon' => 'bi-speedometer2', 'role' => 'viewer'],
        ['id' => 'manage',    'label' => 'โครงการที่รับผิดชอบ', 'href' => 'manage_projects.php', 'icon' => 'bi-kanban', 'role' => 'viewer'],
        ['id' => 'eas',       'label' => 'บัญชี EA',          'href' => 'eas.php',      'icon' => 'bi-map',          'role' => 'viewer'],
        ['id' => 'projects',  'label' => 'EA ของโครงการ',     'href' => 'projects.php', 'icon' => 'bi-diagram-3',    'role' => 'viewer'],
        ['id' => 'assign',    'label' => 'มอบหมายผู้ปฏิบัติงาน', 'href' => 'assign.php',  'icon' => 'bi-shuffle',      'role' => 'editor'],
        ['id' => 'pimport',   'label' => 'นำเข้า EA โครงการ',  'href' => 'project_import.php', 'icon' => 'bi-filetype-csv', 'role' => 'editor'],
        ['id' => 'import',    'label' => 'นำเข้าบัญชี EA',     'href' => 'import.php',   'icon' => 'bi-file-earmark-arrow-up', 'role' => 'admin'],
    ],
];
