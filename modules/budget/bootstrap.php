<?php
/**
 * จุดเริ่มต้นของระบบงบประมาณ (budget)
 * สิทธิ์: viewer = ผู้ดูรายงานงบ · editor = ผู้จัดทำงบประมาณ · admin = ผู้ดูแลระบบงบประมาณ
 * ตาราง: bud_items (หมวด) · bud_plans (แผนงาน) → bud_works (งานและโครงการ) · bud_txns (รับเข้า = เพิ่ม/ได้รับ · เบิกจ่าย = เบิก)
 * ตัวคลุมของรายการ: แผนงาน → งานและโครงการ → หมวด (ทะเบียนคุมเงินประจำงวดส่วนจังหวัด แยกตาม งานและโครงการ × หมวด)
 * "หมวด" = โครงการสำรวจ (core_projects ในหน้าโครงการที่รับผิดชอบ) + รายการงบประมาณ (bud_items)
 *   รายการงบประมาณที่ผูกกับโครงการ (เช่น รายจ่ายอื่น (โพล)) ใช้แทนโครงการนั้น — โครงการนั้นจะไม่แสดงในกลุ่มโครงการสำรวจ
 */
require_once __DIR__ . '/../../core/bootstrap.php';

const BUD_MODULE = 'budget';
const BUD_TYPES = ['receive' => 'ได้รับ', 'spend' => 'เบิกจ่าย'];
const BUD_TYPE_PAGES = ['receive' => 'รับเข้างบประมาณ', 'spend' => 'เบิกจ่ายงบประมาณ'];
const BUD_COLORS = ['receive' => '#2563eb', 'spend' => '#ea580c'];
const BUD_PER_PAGE = 100;

function bud_can(string $role): bool
{
    return user_can(BUD_MODULE, $role);
}

/** ปีงบประมาณ (พ.ศ.) ของวันที่: 1 ต.ค. – 30 ก.ย. */
function bud_fy(string $date): int
{
    $y = (int)substr($date, 0, 4) + 543;
    return (int)substr($date, 5, 2) >= 10 ? $y + 1 : $y;
}

/** ช่วงวันที่ของปีงบประมาณ [from, to] */
function bud_fy_range(int $fy): array
{
    $y = $fy - 543;
    return [($y - 1) . '-10-01', $y . '-09-30'];
}

/** เดือนในปีงบประมาณ ต.ค. → ก.ย. ['Y-m', ...] */
function bud_fy_months(int $fy): array
{
    $out = [];
    for ($i = 0, $t = strtotime(bud_fy_range($fy)[0]); $i < 12; $i++, $t = strtotime('+1 month', $t)) {
        $out[] = date('Y-m', $t);
    }
    return $out;
}

/** ปีงบประมาณที่มีข้อมูล + ปีปัจจุบัน (ใหม่ → เก่า) */
function bud_fy_list(): array
{
    $ys = array_map('intval', db()->query('SELECT DISTINCT fiscal_year FROM bud_txns')->fetchAll(PDO::FETCH_COLUMN));
    $ys[] = bud_fy(date('Y-m-d'));
    $ys = array_unique($ys);
    rsort($ys);
    return $ys;
}

/** ปีงบที่เลือกจาก ?fy= (ค่าเริ่มต้น = ปีงบปัจจุบัน) */
function bud_fy_param(): int
{
    $fy = (int)($_GET['fy'] ?? 0);
    return $fy >= 2500 && $fy <= 2700 ? $fy : bud_fy(date('Y-m-d'));
}

function bud_month_label(string $ym, bool $short = false): string
{
    $m = (int)substr($ym, 5, 2);
    $y = (int)substr($ym, 0, 4) + 543;
    $s = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return $short ? $s[$m] . ' ' . substr((string)$y, 2) : thai_month_long($m) . ' ' . $y;
}

function bud_money($n): string
{
    return number_format((float)$n, 2);
}

/**
 * หมวด ['p:<id>' | 'i:<id>' => ['key', 'label', 'group', 'color', 'is_other', 'active']]
 * เรียง: โครงการสำรวจ → รายการงบประมาณ → รายจ่ายอื่น · $all = รวมที่ปิดใช้งาน (สำหรับแสดงข้อมูลเดิม)
 */
function bud_sources(bool $all = false): array
{
    static $cache = [];
    if (isset($cache[$all])) {
        return $cache[$all];
    }
    $items = db()->query('SELECT * FROM bud_items ORDER BY is_other, sort_order, id')->fetchAll();
    $linked = array_filter(array_map(fn($i) => (int)$i['project_id'], $items));
    $out = [];
    foreach (projects_all() as $p) {
        if (in_array((int)$p['id'], $linked, true) || (!$all && !$p['is_active'])) {
            continue;
        }
        $out['p:' . $p['id']] = ['key' => 'p:' . $p['id'], 'label' => $p['name'] !== $p['code'] ? $p['code'] . ' ' . $p['name'] : $p['code'],
                                 'group' => 'โครงการสำรวจ', 'color' => $p['color'], 'is_other' => false, 'active' => (bool)$p['is_active']];
    }
    foreach ($items as $i) {
        if (!$all && !$i['is_active']) {
            continue;
        }
        $out['i:' . $i['id']] = ['key' => 'i:' . $i['id'], 'label' => $i['name'], 'group' => $i['is_other'] ? 'รายจ่ายอื่น' : 'หมวดรายจ่าย',
                                 'color' => $i['is_other'] ? '#64748b' : '#15803d', 'is_other' => (bool)$i['is_other'], 'active' => (bool)$i['is_active'],
                                 'project_id' => $i['project_id'] ? (int)$i['project_id'] : null];
    }
    return $cache[$all] = $out;
}

/** คีย์หมวดของรายการ (โครงการที่ผูกกับรายการงบประมาณ → รายการนั้น) */
function bud_source_key(array $t): string
{
    if ($t['item_id']) {
        return 'i:' . $t['item_id'];
    }
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (bud_sources(true) as $k => $s) {
            if (!empty($s['project_id'])) {
                $map[$s['project_id']] = $k;
            }
        }
    }
    return $map[(int)$t['project_id']] ?? 'p:' . $t['project_id'];
}

/** แปลงคีย์ 'p:3' / 'i:5' → [project_id, item_id] หรือ null ถ้าไม่ถูกต้อง */
function bud_parse_source(string $key): ?array
{
    $src = bud_sources(true)[$key] ?? null;
    if (!$src) {
        return null;
    }
    $id = (int)substr($key, 2);
    return $key[0] === 'p' ? [$id, null] : [null, $id];
}

/**
 * ยอดรวมตามหมวด × เดือน ของปีงบ: [source_key][ym][type] => amount
 */
function bud_totals(int $fy): array
{
    $st = db()->prepare("SELECT project_id, item_id, DATE_FORMAT(txn_date, '%Y-%m') ym, txn_type, SUM(amount) amt
                         FROM bud_txns WHERE fiscal_year = ? GROUP BY project_id, item_id, ym, txn_type");
    $st->execute([$fy]);
    $out = [];
    foreach ($st as $r) {
        $k = bud_source_key($r);
        $out[$k][$r['ym']][$r['txn_type']] = ($out[$k][$r['ym']][$r['txn_type']] ?? 0) + (float)$r['amt'];
    }
    return $out;
}

/** ส่งออก CSV (UTF-8 BOM เปิดใน Excel ได้) */
function bud_csv(string $filename, array $head, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, $head);
    foreach ($rows as $r) {
        fputcsv($f, $r);
    }
    fclose($f);
    exit;
}

/** แผนงาน [id => row] เรียงตามลำดับ · $all = รวมที่ปิดใช้งาน */
function bud_plans(bool $all = true): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM bud_plans ORDER BY sort_order, id') as $r) {
        if ($all || $r['is_active']) {
            $out[(int)$r['id']] = $r;
        }
    }
    return $out;
}

/** งานและโครงการ [id => row + plan_name] เรียงตามแผนงาน · $all = รวมที่ปิดใช้งาน */
function bud_works(bool $all = true): array
{
    static $cache = null;
    $cache ??= db()->query('SELECT w.*, p.name AS plan_name, p.is_active AS plan_active FROM bud_works w JOIN bud_plans p ON p.id = w.plan_id
                            ORDER BY p.sort_order, p.id, w.sort_order, w.id')->fetchAll();
    $out = [];
    foreach ($cache as $r) {
        if ($all || ($r['is_active'] && $r['plan_active'])) {
            $out[(int)$r['id']] = $r;
        }
    }
    return $out;
}

/** <option> งานและโครงการ จัดกลุ่มตามแผนงาน */
function bud_work_options(array $works, ?int $sel, string $empty = '— เลือก —'): string
{
    $o = $empty !== '' ? '<option value="">' . h($empty) . '</option>' : '';
    $g = null;
    foreach ($works as $id => $w) {
        if ($w['plan_id'] !== $g) {
            $o .= ($g !== null ? '</optgroup>' : '') . '<optgroup label="แผนงาน: ' . h($w['plan_name']) . '">';
            $g = $w['plan_id'];
        }
        $o .= '<option value="' . $id . '"' . ($id === $sel ? ' selected' : '') . '>' . h($w['name']) . '</option>';
    }
    return $o . ($g !== null ? '</optgroup>' : '');
}
