<?php
/** ระบบสลิปเงินเดือนพนักงาน — ทะเบียนระบบ (ดู core/modules.php) */
return [
    'key'         => 'payslip',
    'name'        => 'ระบบสลิปเงินเดือนพนักงาน',
    'short'       => 'สลิปเงินเดือน',
    'icon'        => 'bi-receipt-cutoff',
    'logo'        => 'assets/img/logo.svg',
    'color'       => '#5e4e95',
    'description' => 'พนักงานดูและพิมพ์สลิปเงินเดือนของตนเอง · ผู้จัดการสลิปนำเข้าข้อมูล · ผู้อนุมัติตรวจและอนุมัติสลิป',
    'order'       => 30,
    // ข้อมูลเงินเดือนเป็นข้อมูลส่วนบุคคล: ผู้ดูแลระบบกลางต้องได้รับสิทธิ์เป็นรายคน (ไม่ได้สิทธิ์อัตโนมัติ)
    'superadmin_access' => false,
    // สิทธิ์แยกหน้าที่ (ไม่ใช่ลำดับขั้น) — ผู้ดูแลระบบสลิปทำได้ทุกอย่าง
    'roles' => [
        'employee' => 'พนักงาน — ดูและพิมพ์สลิปเงินเดือนของตนเอง (ต้องผูกกับรหัสพนักงาน)',
        'manager'  => 'ผู้จัดการสลิป — นำเข้าข้อมูล จัดการสลิป (เพิ่ม/แก้ไข/ลบ) จัดการรายชื่อพนักงาน',
        'approver' => 'ผู้อนุมัติ — ดูและอนุมัติสลิป แจ้งแก้ไขให้ผู้จัดการสลิป (เมนูรออนุมัติ · ดูสลิปทั้งหมดในเมนูจัดการสลิปได้แต่แก้ไขไม่ได้)',
        'admin'    => 'ผู้ดูแลระบบสลิป — ทำได้ทุกอย่าง รวมถึงตั้งค่าสลิป (ผู้ลงนาม/ลายมือชื่อ)',
    ],
    'menu' => [
        // แสดงให้ผู้มีสิทธิ์ "พนักงาน" เสมอ (ยังไม่ผูกรหัส = หน้าแจ้งให้ติดต่อผู้จัดการสลิป) และผู้ที่ผูกกับรหัสพนักงาน (มีสลิป)
        ['id' => 'my',        'label' => 'สลิปของฉัน',       'href' => '',               'icon' => 'bi-receipt',            'role' => 'employee',
         'visible' => function (array $user): bool {
             if (module_role('payslip', $user) === 'employee') {
                 return true;
             }
             static $cache = [];
             if (!isset($cache[$user['id']])) {
                 $st = db()->prepare('SELECT 1 FROM pay_employees WHERE user_id = ? AND is_active = 1');
                 $st->execute([$user['id']]);
                 $cache[$user['id']] = (bool)$st->fetchColumn();
             }
             return $cache[$user['id']];
         }],
        ['id' => 'pending',   'label' => 'รออนุมัติ',         'href' => 'slips?status=pending', 'icon' => 'bi-hourglass-split',   'role' => 'employee',
         'visible' => fn(array $u) => in_array(module_role('payslip', $u), ['approver', 'admin'], true)],
        ['id' => 'slips',     'label' => 'จัดการสลิป',        'href' => 'slips',               'icon' => 'bi-table',              'role' => 'employee',
         'visible' => fn(array $u) => in_array(module_role('payslip', $u), ['manager', 'approver', 'admin'], true)],   // ผู้อนุมัติดูอย่างเดียว
        ['id' => 'import',    'label' => 'นำเข้าข้อมูล',       'href' => 'import',              'icon' => 'bi-file-earmark-excel', 'role' => 'employee',
         'visible' => fn(array $u) => in_array(module_role('payslip', $u), ['manager', 'admin'], true)],
        ['id' => 'employees', 'label' => 'รายชื่อพนักงาน',    'href' => 'employees',           'icon' => 'bi-person-vcard',       'role' => 'employee',
         'visible' => fn(array $u) => in_array(module_role('payslip', $u), ['manager', 'admin'], true)],
        ['id' => 'settings',  'label' => 'ตั้งค่าสลิป',        'href' => 'settings',            'icon' => 'bi-gear',               'role' => 'admin'],
    ],
];
