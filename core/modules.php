<?php
/**
 * ทะเบียนระบบย่อย (modules)
 * แต่ละระบบอยู่ในโฟลเดอร์ modules/<key>/ และมีไฟล์ module.php คืนค่า array:
 *   key, name, short, icon, color, description, order
 *   roles  => ['role' => 'คำอธิบาย', ...]   เรียงจากสิทธิ์ต่ำ → สูง (ตัวสุดท้าย = สูงสุด)
 *   menu   => [['id','label','href','icon','role'], ...]  href สัมพัทธ์จากโฟลเดอร์ระบบ
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
                   'order' => 100, 'menu' => [], 'links' => [], 'enabled' => true];
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
