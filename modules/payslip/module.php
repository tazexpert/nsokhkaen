<?php
/** ระบบสลิปเงินเดือนพนักงาน — ทะเบียนระบบ (ดู core/modules.php) */
return [
    'key'         => 'payslip',
    'name'        => 'ระบบสลิปเงินเดือนพนักงาน',
    'short'       => 'สลิปเงินเดือน',
    'icon'        => 'bi-receipt-cutoff',
    'logo'        => 'assets/img/logo.svg',
    'color'       => '#7c3aed',
    'description' => 'พนักงานดูและพิมพ์สลิปเงินเดือนของตนเอง · ผู้จัดการสลิปนำเข้าข้อมูล · ผู้อนุมัติอนุมัติสลิป',
    'order'       => 30,
    // ข้อมูลเงินเดือนเป็นข้อมูลส่วนบุคคล: ผู้ดูแลระบบกลางต้องได้รับสิทธิ์เป็นรายคน (ไม่ได้สิทธิ์อัตโนมัติ)
    'superadmin_access' => false,
    'roles' => [
        'employee' => 'พนักงาน — ดูและพิมพ์สลิปเงินเดือนของตนเอง (ต้องผูกกับรหัสพนักงาน)',
        'manager'  => 'ผู้จัดการสลิป — นำเข้า/แก้ไขข้อมูลสลิป จัดการรายชื่อพนักงาน',
        'approver' => 'ผู้อนุมัติ — อนุมัติสลิปให้พนักงานเห็น และทำได้ทุกอย่างของผู้จัดการสลิป',
    ],
    'menu' => [
        ['id' => 'my',        'label' => 'สลิปของฉัน',       'href' => 'index.php',               'icon' => 'bi-receipt',            'role' => 'employee'],
        ['id' => 'pending',   'label' => 'รออนุมัติ',         'href' => 'slips.php?status=pending', 'icon' => 'bi-hourglass-split',   'role' => 'approver'],
        ['id' => 'slips',     'label' => 'จัดการสลิป',        'href' => 'slips.php',               'icon' => 'bi-table',              'role' => 'manager'],
        ['id' => 'import',    'label' => 'นำเข้าข้อมูล',       'href' => 'import.php',              'icon' => 'bi-file-earmark-excel', 'role' => 'manager'],
        ['id' => 'employees', 'label' => 'รายชื่อพนักงาน',    'href' => 'employees.php',           'icon' => 'bi-person-vcard',       'role' => 'manager'],
        ['id' => 'settings',  'label' => 'ตั้งค่าสลิป',        'href' => 'settings.php',            'icon' => 'bi-gear',               'role' => 'manager'],
    ],
];
