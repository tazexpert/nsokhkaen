<?php
/**
 * จุดเริ่มต้นของระบบวัสดุ (inventory)
 *
 * โค้ดของระบบนี้ย้ายมาจากโปรเจกต์ nsokhkaen_inventory เดิม ไฟล์นี้จึงมี "ชั้นเชื่อมต่อ"
 * ที่คงชื่อฟังก์ชันเดิม (currentUser, isAdmin, requireLoginApi, getDbConnection ...)
 * แต่ทำงานบนระบบ login กลาง และสิทธิ์รายระบบ (core_user_roles, module_key = 'inventory')
 *   role 'admin' = ผู้ดูแลระบบวัสดุ, 'staff' = เจ้าหน้าที่
 */
require_once __DIR__ . '/../../core/bootstrap.php';

// ไลบรารีที่ใช้ (ไฟล์ PHP ล้วน ไม่ต้องใช้ Composer)
require_once APP_ROOT . '/libs/simplexlsx/SimpleXLSX.php';
require_once APP_ROOT . '/libs/simplexlsx/SimpleXLSXGen.php';
require_once APP_ROOT . '/libs/phpqrcode/qrlib.php';

const INV_MODULE = 'inventory';
define('INV_URL', module_url(INV_MODULE));           // path ของโฟลเดอร์ระบบ เช่น /nsokhkaen/modules/inventory/
define('INV_DIR', __DIR__);

require_once __DIR__ . '/functions.php';

function getDbConnection(): PDO
{
    return db();
}

/** ผู้ใช้ปัจจุบันในรูปแบบเดิมของระบบวัสดุ: role = สิทธิ์ในระบบวัสดุ ('admin' | 'staff') */
function currentUser(): ?array
{
    $u = current_user();
    if (!$u) {
        return null;
    }
    return [
        'id'        => $u['id'],
        'username'  => $u['username'],
        'full_name' => $u['full_name'],
        'position'  => $u['position'],
        'role'      => module_role(INV_MODULE, $u),
        'has_pin'   => $u['has_pin'],
        'via_pin'   => $u['via_pin'],
    ];
}

function isLoggedIn(): bool
{
    return current_user() !== null;
}

function isAdmin(): bool
{
    return user_can(INV_MODULE, 'admin');
}

function requireLogin(): void
{
    require_module(INV_MODULE);
}

function requireAdmin(): void
{
    require_module(INV_MODULE, 'admin');
}

/** หน้าสแกน QR: ผู้ที่ยังไม่ได้ login ไปหน้ากรอก PIN แทนหน้ารหัสผ่าน */
function requireScanAccess(): void
{
    require_module(INV_MODULE, '', 'pin.php');
}

/** API: ต้อง login และมีสิทธิ์ในระบบวัสดุ (POST ตรวจ CSRF ด้วย) */
function requireLoginApi(): array
{
    require_module_api(INV_MODULE);
    return currentUser();
}

function requireAdminApi(): array
{
    require_module_api(INV_MODULE, 'admin');
    return currentUser();
}
