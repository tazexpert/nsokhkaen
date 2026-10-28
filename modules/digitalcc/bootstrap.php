<?php
/**
 * จุดเริ่มต้นของระบบศูนย์ดิจิทัลชุมชน (digitalcc)
 * สิทธิ์ในระบบนี้ (core_user_roles, module_key = 'digitalcc'):
 *   inspector = ผู้ตรวจเยี่ยม, editor = เจ้าหน้าที่, admin = ผู้ดูแลระบบศูนย์ฯ (ลบข้อมูลได้)
 * ไฟล์รูปเก็บที่ uploads/digitalcc/{centers,staff,activities}/ (ค่าในฐานข้อมูลเป็น path จาก uploads/)
 */
require_once __DIR__ . '/../../core/bootstrap.php';

const DCC_MODULE = 'digitalcc';
const PROVINCE = 'ขอนแก่น';
define('DCC_URL', module_url(DCC_MODULE));

require_once __DIR__ . '/districts.php';
require_once __DIR__ . '/visit_form_def.php';

/** ผู้ใช้ปัจจุบันมีสิทธิ์ในระบบนี้ตั้งแต่ระดับ $role ขึ้นไปหรือไม่ (inspector < editor < admin) */
function dcc_can(string $role): bool
{
    return user_can(DCC_MODULE, $role);
}

/** หยุดทำงานถ้าไม่มีสิทธิ์ (ใช้ตรวจก่อนทำรายการ เช่น ลบข้อมูล) */
function dcc_require(string $role): void
{
    if (!dcc_can($role)) {
        forbidden(DCC_MODULE, $role);
    }
}
