<?php
/**
 * ทะเบียนระบบย่อย (modules)
 * แต่ละระบบอยู่ในโฟลเดอร์ modules/<key>/ และมีไฟล์ module.php คืนค่า array:
 *   key, name, short, icon, color, description, order
 *   default_role => 'viewer'  (ไม่บังคับ) ผู้ใช้ทุกคนที่ login ได้ role นี้อัตโนมัติ ถ้าไม่ได้กำหนดสิทธิ์ไว้
 *   guest_role => 'viewer'   (ไม่บังคับ) ผู้เยี่ยมชมที่ไม่ได้ login ได้ role นี้ — ใช้ได้เฉพาะหน้าที่เรียก require_module_or_guest()
 *                        (เช่น ปฏิทินการปฏิบัติงาน ดูได้อย่างเดียว) · เมนูที่ใส่ 'guest' => false ไม่แสดงให้ผู้เยี่ยมชม
 *   superadmin_access => false  (ไม่บังคับ) ผู้ดูแลระบบกลางไม่ได้สิทธิ์สูงสุดอัตโนมัติ ต้องกำหนดสิทธิ์รายคน
 *                        (ใช้กับระบบที่มีข้อมูลส่วนบุคคล เช่น สลิปเงินเดือน)
 *   logo   => 'assets/img/logo.png'   (ไม่บังคับ) รูปโลโก้ระบบ สัมพัทธ์จากโฟลเดอร์ระบบ — ไม่มีจะใช้ icon แทน
 *   roles  => ['role' => 'คำอธิบาย', ...]   เรียงจากสิทธิ์ต่ำ → สูง (ตัวสุดท้าย = สูงสุด)
 *   menu   => [['id','label','href','icon','role'], ...]  href สัมพัทธ์จากโฟลเดอร์ระบบ
 *             'visible' => fn(array $user): bool  (ไม่บังคับ) เงื่อนไขเพิ่มเติมนอกจาก role เช่น ซ่อน "สลิปของฉัน" ถ้าไม่ใช่พนักงาน
 *   links  => [['label','href','icon'], ...]  ลิงก์เพิ่มเติม เช่น หน้าเว็บสาธารณะ
 * เพิ่มระบบใหม่ = สร้างโฟลเดอร์ + module.php แล้วกำหนดสิทธิ์ผู้ใช้ที่เมนูผู้ใช้งาน
 */

function modules(): array
{
    static $mods = null;
    if ($mods === null) {
        $mods = [];
        foreach (glob(APP_ROOT . '/modules/*/module.php') ?: [] as $file) {
            $m = require $file;
            if (!is_array($m) || empty($m['key']) || empty($m['roles'])) {
                continue;
            }
            $m += ['short' => $m['name'], 'icon' => 'bi-grid', 'color' => '#0f6cbd', 'description' => '',
                   'order' => 100, 'menu' => [], 'links' => [], 'enabled' => true, 'logo' => null,
                   'superadmin_access' => true, 'default_role' => null, 'guest_role' => null];
            if ($m['enabled']) {
                $mods[$m['key']] = $m;
            }
        }
        uasort($mods, fn($a, $b) => $a['order'] <=> $b['order']);
    }
    return $mods;
}

function module_def(string $key): ?array
{
    return modules()[$key] ?? null;
}

/** URL ภายในโฟลเดอร์ของระบบ เช่น module_url('inventory', 'scan.php') */
function module_url(string $key, string $path = ''): string
{
    return url('modules/' . $key . '/' . ltrim($path, '/'));
}

/** ลำดับสิทธิ์ของ role ในระบบนั้น (เริ่มที่ 1) — 0 = ไม่มีสิทธิ์/ไม่รู้จัก */
function role_level(string $module, ?string $role): int
{
    $m = module_def($module);
    if (!$m || $role === null) {
        return 0;
    }
    $pos = array_search($role, array_keys($m['roles']), true);
    return $pos === false ? 0 : $pos + 1;
}

function top_role(string $module): ?string
{
    $m = module_def($module);
    return $m ? array_key_last($m['roles']) : null;
}

function role_label(string $module, ?string $role): string
{
    return $role ? (module_def($module)['roles'][$role] ?? $role) : '-';
}

/** ชื่อบทบาทแบบสั้น (ข้อความก่อน " — ") สำหรับป้ายแสดงผล */
function role_short(string $module, ?string $role): string
{
    return explode(' — ', role_label($module, $role))[0];
}

/**
 * โลโก้ของระบบ (รูปภาพถ้ามี ไม่มีใช้ไอคอน Bootstrap) สำหรับเมนู/หน้าหลัก
 * $size = ความสูงเป็น px
 */
function module_logo(array $m, int $size = 22, string $class = ''): string
{
    if (!empty($m['logo'])) {
        // กรอบสี่เหลี่ยมจัตุรัสเท่ากันทุกระบบ (โลโก้ที่ไม่ใช่สี่เหลี่ยมจัตุรัส เช่น นกฮูก ย่อให้พอดีกรอบ)
        return '<img src="' . h(module_url($m['key'], $m['logo'])) . '" alt="" class="mod-logo ' . h($class) . '" style="width:' . $size . 'px;height:' . $size . 'px">';
    }
    return '<i class="bi ' . h($m['icon']) . ' ' . h($class) . '" style="color:' . h($m['color']) . ';font-size:' . round($size * .85) . 'px"></i>';
}

/** แสดงเมนูนี้กับผู้ใช้หรือไม่: มี role ถึง และผ่านเงื่อนไข visible (ถ้ามี) */
function menu_item_visible(string $module, array $item, ?array $user = null): bool
{
    $user ??= current_user();
    if (!$user || !empty($user['is_guest'])) {            // ผู้เยี่ยมชม: เฉพาะเมนูที่ role ผู้เยี่ยมชมถึง และไม่ได้ปิดไว้
        return ($item['guest'] ?? true) && empty($item['visible']) && user_can($module, $item['role'] ?? '', guest_user());
    }
    if (!user_can($module, $item['role'] ?? '', $user)) {
        return false;
    }
    return !isset($item['visible']) || !is_callable($item['visible']) || (bool)($item['visible'])($user);
}
