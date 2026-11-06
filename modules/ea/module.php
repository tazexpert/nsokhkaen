<?php
/** ระบบบริหารจัดการ EA (เขตแจงนับ) จังหวัดขอนแก่น — ทะเบียนระบบ (ดู core/modules.php) */
return [
    'key'         => 'ea',
    'name'        => 'ระบบบริหารจัดการ EA',
    'short'       => 'บริหารจัดการ EA',
    'icon'        => 'bi-map',
    'logo'        => 'assets/img/logo.svg',
    'color'       => '#0f766e',
    'description' => 'บัญชีเขตแจงนับ (EA) จังหวัดขอนแก่น และ EA ที่แต่ละโครงการใช้ในการสำรวจ — ผู้รับผิดชอบ และความก้าวหน้า',
    'order'       => 35,
    'default_role' => 'viewer',                       // บุคลากรทุกคนดูบัญชี EA และ EA ของโครงการได้
    'roles' => [
        'viewer' => 'ผู้ดูข้อมูล — ดูบัญชี EA และ EA ที่โครงการใช้สำรวจ',
        'editor' => 'เจ้าหน้าที่โครงการ — กำหนด EA ให้โครงการ ผู้รับผิดชอบ และสถานะการสำรวจ',
        'admin'  => 'ผู้ดูแลระบบ EA — นำเข้า/แก้ไขบัญชี EA และทำได้ทั้งหมด',
    ],
    'menu' => [
        ['id' => 'dashboard', 'label' => 'ภาพรวม',           'href' => 'index.php',    'icon' => 'bi-speedometer2', 'role' => 'viewer'],
        ['id' => 'eas',       'label' => 'บัญชี EA',          'href' => 'eas.php',      'icon' => 'bi-map',          'role' => 'viewer'],
        ['id' => 'projects',  'label' => 'EA ของโครงการ',     'href' => 'projects.php', 'icon' => 'bi-diagram-3',    'role' => 'viewer'],
        ['id' => 'pimport',   'label' => 'นำเข้า EA โครงการ',  'href' => 'project_import.php', 'icon' => 'bi-filetype-csv', 'role' => 'editor'],
        ['id' => 'import',    'label' => 'นำเข้าบัญชี EA',     'href' => 'import.php',   'icon' => 'bi-file-earmark-arrow-up', 'role' => 'admin'],
    ],
];
