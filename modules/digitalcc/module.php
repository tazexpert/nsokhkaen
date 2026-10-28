<?php
/** ระบบบริหารจัดการศูนย์ดิจิทัลชุมชน — ทะเบียนระบบ (ดู core/modules.php) */
return [
    'key'         => 'digitalcc',
    'name'        => 'ระบบบริหารจัดการศูนย์ดิจิทัลชุมชน',
    'short'       => 'ศูนย์ดิจิทัลชุมชน',
    'icon'        => 'bi-wifi',
    'logo'        => 'assets/img/logo.png',              // โลโก้นกฮูก (สัมพัทธ์จากโฟลเดอร์ระบบ)
    'color'       => '#00a561',
    'description' => 'ข้อมูลศูนย์ ผู้ดูแล กิจกรรม และการตรวจเยี่ยมศูนย์ดิจิทัลชุมชน 26 อำเภอ',
    'order'       => 20,
    'roles' => [
        'inspector' => 'ผู้ตรวจเยี่ยม — ดูข้อมูลศูนย์ และบันทึกการตรวจเยี่ยม',
        'editor'    => 'เจ้าหน้าที่ — จัดการศูนย์ ผู้ดูแล กิจกรรม พิกัด และการตรวจเยี่ยม',
        'admin'     => 'ผู้ดูแลระบบศูนย์ฯ — ทำได้ทั้งหมด รวมถึงการลบข้อมูล',
    ],
    'menu' => [
        ['id' => 'dashboard',  'label' => 'ภาพรวม',            'href' => 'index.php',      'icon' => 'bi-speedometer2',    'role' => 'inspector'],
        ['id' => 'centers',    'label' => 'ศูนย์ดิจิทัลชุมชน',   'href' => 'centers.php',    'icon' => 'bi-building',        'role' => 'inspector'],
        ['id' => 'visits',     'label' => 'ตรวจเยี่ยมศูนย์',     'href' => 'visits.php',     'icon' => 'bi-clipboard-check', 'role' => 'inspector'],
        ['id' => 'activities', 'label' => 'กิจกรรม',            'href' => 'activities.php', 'icon' => 'bi-calendar-event',  'role' => 'editor'],
        ['id' => 'geocode',    'label' => 'ค้นหาพิกัดอัตโนมัติ', 'href' => 'geocode.php',    'icon' => 'bi-geo-alt',         'role' => 'editor'],
        ['id' => 'site',       'label' => 'ดูหน้าเว็บไซต์',      'href' => 'public/',        'icon' => 'bi-box-arrow-up-right', 'role' => 'inspector', 'target' => '_blank'],
    ],
    'links' => [
        ['label' => 'เว็บไซต์ศูนย์ดิจิทัลชุมชน (สาธารณะ)', 'href' => 'public/', 'icon' => 'bi-globe'],
    ],
];
