<?php
/** ระบบพิกัด EA ตัวอย่างโครงการ — แสดงแดชบอร์ด Power BI (iframe) เฉพาะผู้ที่ได้รับสิทธิ์ (ดู core/modules.php) */
return [
    'key'         => 'powerbi',
    'name'        => 'พิกัด EA ตัวอย่างโครงการ',
    'short'       => 'พิกัด EA',
    'icon'        => 'bi-geo-alt',
    'logo'        => 'assets/img/logo.svg',
    'color'       => '#d97706',
    'description' => 'แผนที่พิกัดเขตแจงนับ (EA) ตัวอย่างโครงการ (Power BI) — เห็นเฉพาะผู้ที่ได้รับสิทธิ์',
    'order'       => 40,
    // ไม่มีสิทธิ์อัตโนมัติ: ผู้ดูแลระบบกลางกำหนดรายคนที่ ผู้ใช้งานและสิทธิ์ (ผู้ดูแลระบบกลางเห็นได้เสมอ)
    'roles' => [
        'viewer' => 'ผู้ดูข้อมูล — เห็นเมนูและเปิดดูพิกัด EA ตัวอย่างโครงการ',
        'admin'  => 'ผู้ดูแล — ดูได้ และแก้ไขลิงก์ Power BI / ชื่อที่แสดง',
    ],
    'menu' => [
        ['id' => 'view',     'label' => 'พิกัด EA ตัวอย่าง', 'href' => 'index.php',    'icon' => 'bi-geo-alt', 'role' => 'viewer'],
        ['id' => 'settings', 'label' => 'ตั้งค่า',         'href' => 'settings.php', 'icon' => 'bi-gear',           'role' => 'admin'],
    ],
];
