<?php
/**
 * จุดเริ่มต้นของระบบบริหารจัดการ EA (ea)
 * สิทธิ์ (core_user_roles, module_key = 'ea'): viewer = ดูข้อมูล (ทุกคนได้อัตโนมัติ),
 *   editor = กำหนด EA ให้โครงการ/ผู้รับผิดชอบ/สถานะ, admin = นำเข้าและแก้ไขบัญชี EA
 * ตาราง: ea_areas (บัญชีเขตแจงนับ) · ea_project_eas (EA ที่โครงการใน core_projects ใช้สำรวจ แยกตามรอบ/งวด)
 */
require_once __DIR__ . '/../../core/bootstrap.php';

const EA_MODULE = 'ea';
const EA_AREA_TYPES = [1 => 'ในเขตเทศบาล', 2 => 'นอกเขตเทศบาล'];
const EA_STATUSES = [
    'pending'     => ['รอดำเนินการ', 'secondary'],
    'in_progress' => ['กำลังสำรวจ', 'warning'],
    'done'        => ['สำรวจเสร็จ', 'success'],
];
const EA_PER_PAGE = 100;

function ea_can(string $role): bool
{
    return user_can(EA_MODULE, $role);
}

function ea_require(string $role): void
{
    if (!ea_can($role)) {
        forbidden(EA_MODULE, $role);
    }
}

function ea_status_badge(string $s): string
{
    [$label, $color] = EA_STATUSES[$s] ?? [$s, 'secondary'];
    return '<span class="badge text-bg-' . $color . '">' . h($label) . '</span>';
}

/** ชื่อพื้นที่ของ EA เช่น "อ.เมืองขอนแก่น ต.ในเมือง (ในเขตเทศบาล)" */
function ea_place(array $r): string
{
    return 'อ.' . $r['district_name'] . ' ต.' . $r['tambon_name']
        . ($r['village_name'] !== '' ? ' ' . ($r['village_code'] !== '' ? 'ม.' . (int)$r['village_code'] . ' ' : '') . $r['village_name'] : '');
}

/** อำเภอทั้งหมดในบัญชี [code => name] */
function ea_districts(): array
{
    static $d = null;
    if ($d === null) {
        $d = db()->query('SELECT district_code, MAX(district_name) FROM ea_areas GROUP BY district_code ORDER BY district_code')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $d;
}

/** ตำบลของอำเภอ [code => name] */
function ea_tambons(string $district): array
{
    if ($district === '') {
        return [];
    }
    $st = db()->prepare('SELECT tambon_code, MAX(tambon_name) FROM ea_areas WHERE district_code = ? GROUP BY tambon_code ORDER BY tambon_code');
    $st->execute([$district]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** บุคลากรที่ใช้งานอยู่ (เรียงตามลำดับตำแหน่ง) [id => row] */
function ea_staff(): array
{
    static $s = null;
    if ($s === null) {
        $s = [];
        foreach (db()->query('SELECT id, username, sort_order, position, full_name FROM core_users WHERE is_active = 1') as $u) {
            $s[(int)$u['id']] = $u;
        }
        $s = sort_staff($s);
    }
    return $s;
}

/** <option> รายชื่อบุคลากร */
function ea_staff_options(?int $selected = null): string
{
    $out = '';
    foreach (ea_staff() as $id => $u) {
        $out .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>' . h($u['full_name']) . '</option>';
    }
    return $out;
}

/** แยกรหัส EA 11 หลักจากข้อความ (คั่นด้วยช่องว่าง/จุลภาค/ขึ้นบรรทัด) */
function ea_parse_codes(string $text): array
{
    preg_match_all('/\d{11}/', $text, $m);
    return array_values(array_unique($m[0]));
}

/** เงื่อนไขกรอง EA จาก $_GET (ใช้ร่วมหลายหน้า) — คืน [where[], params[]] ; ตารางต้องมี alias a */
function ea_filter_sql(array $f): array
{
    $where = [];
    $params = [];
    if (($f['district'] ?? '') !== '') {
        $where[] = 'a.district_code = ?';
        $params[] = $f['district'];
    }
    if (($f['tambon'] ?? '') !== '') {
        $where[] = 'a.tambon_code = ?';
        $params[] = $f['tambon'];
    }
    if (isset(EA_AREA_TYPES[(int)($f['area'] ?? 0)])) {
        $where[] = 'a.area_type = ?';
        $params[] = (int)$f['area'];
    }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $codes = ea_parse_codes($q);
        if ($codes) {
            $where[] = 'a.ea_code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
            array_push($params, ...$codes);
        } else {
            $where[] = '(a.ea_code LIKE ? OR a.village_name LIKE ? OR a.tambon_name LIKE ? OR a.district_name LIKE ? OR a.note LIKE ?)';
            array_push($params, "$q%", "%$q%", "%$q%", "%$q%", "%$q%");
        }
    }
    return [$where, $params];
}

/** ตัวกรองจาก $_GET ที่ผ่านการตรวจรูปแบบแล้ว */
function ea_filter_input(): array
{
    $d = preg_match('/^\d{2}$/', $_GET['district'] ?? '') ? $_GET['district'] : '';
    return [
        'district' => $d,
        'tambon'   => $d !== '' && preg_match('/^\d{2}$/', $_GET['tambon'] ?? '') ? $_GET['tambon'] : '',
        'area'     => isset(EA_AREA_TYPES[(int)($_GET['area'] ?? 0)]) ? (string)(int)$_GET['area'] : '',
        'q'        => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 2000),
    ];
}

/** ส่วนเลือกอำเภอ/ตำบล/เขตการปกครอง (ในฟอร์ม GET) — เปลี่ยนอำเภอแล้วส่งฟอร์มเพื่อโหลดตำบล */
function ea_filter_selects(array $f, string $prefix = ''): string
{
    $out = '<div class="col-md-3 col-6"><select name="' . $prefix . 'district" class="form-select" data-autosubmit>'
         . '<option value="">ทุกอำเภอ</option>';
    foreach (ea_districts() as $c => $n) {
        $out .= '<option value="' . h($c) . '"' . ($f['district'] === (string)$c ? ' selected' : '') . '>' . h($c . ' ' . $n) . '</option>';
    }
    $out .= '</select></div><div class="col-md-3 col-6"><select name="' . $prefix . 'tambon" class="form-select"' . ($f['district'] === '' ? ' disabled' : '') . '>'
          . '<option value="">ทุกตำบล</option>';
    foreach (ea_tambons($f['district']) as $c => $n) {
        $out .= '<option value="' . h($c) . '"' . ($f['tambon'] === (string)$c ? ' selected' : '') . '>' . h($c . ' ' . $n) . '</option>';
    }
    $out .= '</select></div><div class="col-md-2 col-6"><select name="' . $prefix . 'area" class="form-select"><option value="">ทุกเขตการปกครอง</option>';
    foreach (EA_AREA_TYPES as $k => $label) {
        $out .= '<option value="' . $k . '"' . ($f['area'] === (string)$k ? ' selected' : '') . '>' . $k . ' ' . h($label) . '</option>';
    }
    return $out . '</select></div>';
}

/** แถบแบ่งหน้า */
function ea_pager(int $total, int $page, int $per, array $query): string
{
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) {
        return '';
    }
    $link = fn(int $p, string $label, bool $active = false, bool $disabled = false) =>
        '<li class="page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '') . '"><a class="page-link" href="?'
        . h(http_build_query(array_filter(['page' => $p] + $query, fn($v, $k) => $v !== null && ($v !== '' || $k === 'round'), ARRAY_FILTER_USE_BOTH))) . '">' . $label . '</a></li>';
    $out = '<nav><ul class="pagination pagination-sm mb-0 flex-wrap">' . $link(max(1, $page - 1), '&laquo;', false, $page <= 1);
    $last = 0;
    for ($p = 1; $p <= $pages; $p++) {
        if ($p === 1 || $p === $pages || abs($p - $page) <= 2) {
            if ($last && $p - $last > 1) {
                $out .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            $out .= $link($p, (string)$p, $p === $page);
            $last = $p;
        }
    }
    return $out . $link(min($pages, $page + 1), '&raquo;', false, $page >= $pages) . '</ul></nav>';
}

/** ส่ง CSV (UTF-8 BOM ให้ Excel อ่านภาษาไทยได้) */
function ea_csv_start(string $filename)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    return $out;
}

/** หัวคอลัมน์ CSV ของบัญชี EA (รูปแบบเดียวกับไฟล์ "บัญชีเขตแจงนับ") */
const EA_CSV_HEAD = ['รหัส EA', 'รหัสภาค', 'ภาค', 'รหัสจังหวัด', 'จังหวัด', 'รหัสอำเภอ', 'อำเภอ', 'รหัสตำบล', 'ตำบล', 'เขตการปกครอง', 'EA',
                     'EA สำหรับเสนอผลล่วงหน้า', 'รหัสหมู่บ้าน', 'หมู่บ้าน/ชุมชน', 'จำนวนบ้าน/อาคาร/สิ่งปลูกสร้าง', 'จำนวนครัวเรือน', 'จำนวนประชากร', 'หมายเหตุ'];

function ea_csv_row(array $r): array
{
    // ใส่ ="..." ให้รหัสที่ขึ้นต้นด้วย 0 ไม่ถูก Excel ตัดเลขศูนย์
    $t = fn($v) => $v === '' ? '' : '="' . $v . '"';
    return [$t($r['ea_code']), $r['region_code'], $r['region_name'], $t($r['province_code']), $r['province_name'], $t($r['district_code']), $r['district_name'],
            $t($r['tambon_code']), $r['tambon_name'], $r['area_type'], $t($r['ea_no']), $r['advance_ea'], $t($r['village_code']), $r['village_name'],
            (int)$r['houses'], (int)$r['households'], (int)$r['population'], (string)$r['note']];
}

/* ------------------------------------------------------------------------
 * นำเข้าบัญชีเขตแจงนับจาก Excel (รูปแบบไฟล์ "บัญชีเขตแจงนับ" ของ สสช.)
 * หัวตาราง 2 แถว: ลำดับที่ | ภาค (รหัส ชื่อ) | จังหวัด | อำเภอ/เขต | ตำบล/แขวง | เขตการปกครอง | EA |
 *   EA สำหรับเสนอผลล่วงหน้า | หมู่บ้าน/ชุมชน (รหัส ชื่อ) | ข้อมูลเบื้องต้น (บ้าน ครัวเรือน ประชากร) | หมายเหตุ
 * ------------------------------------------------------------------------ */
function ea_parse_xlsx(string $path): array
{
    require_once APP_ROOT . '/libs/simplexlsx/SimpleXLSX.php';
    $res = ['source' => '', 'rows' => [], 'errors' => [], 'skipped' => 0];
    $x = \Shuchkin\SimpleXLSX::parse($path);
    if (!$x) {
        $res['errors'][] = 'อ่านไฟล์ Excel ไม่ได้: ' . \Shuchkin\SimpleXLSX::parseError();
        return $res;
    }
    $rows = $x->rows(0);
    $norm = fn($v) => preg_replace('/\s+/u', '', (string)$v);
    // หาแถวหัวตาราง: มีช่อง "EA" และ "อำเภอ..."
    $head = null;
    foreach (array_slice($rows, 0, 15, true) as $ri => $r) {
        $cells = array_map($norm, array_values($r));
        if (in_array('EA', $cells, true) && array_filter($cells, fn($c) => str_starts_with($c, 'อำเภอ'))) {
            $head = $ri;
            break;
        }
    }
    if ($head === null) {
        $res['errors'][] = 'ไม่พบหัวตาราง (ต้องมีคอลัมน์ "อำเภอ/เขต" และ "EA") — ตรวจว่าเป็นไฟล์บัญชีเขตแจงนับรูปแบบเดิม';
        return $res;
    }
    foreach (array_slice($rows, 0, $head) as $r) {
        foreach ($r as $v) {
            $v = trim((string)$v);
            if ($v !== '' && $res['source'] === '' && $norm($v) !== 'บัญชีเขตแจงนับ') {
                $res['source'] = mb_substr(preg_replace('/\s+/u', ' ', $v), 0, 255);
            }
        }
    }
    $h1 = array_map($norm, array_values($rows[$head]));
    $h2 = array_map($norm, array_values($rows[$head + 1] ?? []));
    $find = function (callable $test) use ($h1) {
        foreach ($h1 as $i => $c) {
            if ($test($c)) {
                return $i;
            }
        }
        return null;
    };
    $col = [
        'region'   => $find(fn($c) => $c === 'ภาค'),
        'province' => $find(fn($c) => $c === 'จังหวัด'),
        'district' => $find(fn($c) => str_starts_with($c, 'อำเภอ')),
        'tambon'   => $find(fn($c) => str_starts_with($c, 'ตำบล')),
        'area'     => $find(fn($c) => str_contains($c, 'ปกครอง')),
        'ea'       => $find(fn($c) => $c === 'EA'),
        'advance'  => $find(fn($c) => str_starts_with($c, 'EA') && str_contains($c, 'ล่วงหน้า')),
        'village'  => $find(fn($c) => str_starts_with($c, 'หมู่บ้าน')),
        'note'     => $find(fn($c) => str_starts_with($c, 'หมายเหตุ')),
    ];
    foreach ($h2 as $i => $c) {
        if (str_contains($c, 'ครัวเรือน')) {
            $col['households'] = $i;
        } elseif (str_contains($c, 'ประชากร')) {
            $col['population'] = $i;
        } elseif (str_contains($c, 'บ้าน') || str_contains($c, 'อาคาร')) {
            $col['houses'] = $i;
        }
    }
    foreach (['province' => 'จังหวัด', 'district' => 'อำเภอ/เขต', 'tambon' => 'ตำบล/แขวง', 'area' => 'เขตการปกครอง', 'ea' => 'EA'] as $k => $label) {
        if ($col[$k] === null) {
            $res['errors'][] = 'ไม่พบคอลัมน์ "' . $label . '" ในหัวตาราง';
        }
    }
    if ($res['errors']) {
        return $res;
    }
    $get = fn(array $r, ?int $i) => $i === null ? '' : trim(preg_replace('/\s+/u', ' ', (string)($r[$i] ?? '')));
    $code = function (string $v, int $len) {
        if ($v === '' || !preg_match('/^\d+(\.0+)?$/', $v)) {
            return null;
        }
        $v = (string)(int)$v;
        return strlen($v) <= $len ? str_pad($v, $len, '0', STR_PAD_LEFT) : null;
    };
    $num = fn(string $v) => $v === '' ? 0 : max(0, (int)round((float)str_replace(',', '', $v)));
    $seen = [];
    foreach (array_slice($rows, $head + 2, null, true) as $ri => $r) {
        $r = array_values($r);
        $line = $ri + 1;
        $rawEa = $get($r, $col['ea']);
        if ($rawEa === '' && $get($r, $col['district']) === '') {
            $res['skipped']++;
            continue;                                   // แถวว่าง / แถวรวม
        }
        $p = $code($get($r, $col['province']), 2);
        $d = $code($get($r, $col['district']), 2);
        $t = $code($get($r, $col['tambon']), 2);
        $a = $get($r, $col['area']);
        $e = $code($rawEa, 4);
        if (!$p || !$d || !$t || !in_array($a, ['1', '2'], true) || !$e) {
            if (count($res['errors']) < 30) {
                $res['errors'][] = 'แถวที่ ' . $line . ': รหัสไม่ถูกต้อง (จังหวัด/อำเภอ/ตำบล 2 หลัก · เขตการปกครอง 1 หรือ 2 · EA 4 หลัก)';
            }
            continue;
        }
        $ea = $p . $d . $t . $a . $e;
        if (isset($seen[$ea])) {
            if (count($res['errors']) < 30) {
                $res['errors'][] = 'แถวที่ ' . $line . ': รหัส EA ' . $ea . ' ซ้ำกับแถวที่ ' . $seen[$ea];
            }
            continue;
        }
        $seen[$ea] = $line;
        $vc = $get($r, $col['village'] ?? null);
        $res['rows'][] = [
            'ea_code' => $ea, 'seq' => (int)$get($r, 0),
            'region_code' => mb_substr($get($r, $col['region']), 0, 2), 'region_name' => mb_substr($get($r, $col['region'] === null ? null : $col['region'] + 1), 0, 50),
            'province_code' => $p, 'province_name' => mb_substr($get($r, $col['province'] + 1), 0, 50),
            'district_code' => $d, 'district_name' => mb_substr($get($r, $col['district'] + 1), 0, 100),
            'tambon_code' => $t, 'tambon_name' => mb_substr($get($r, $col['tambon'] + 1), 0, 100),
            'area_type' => (int)$a, 'ea_no' => $e,
            'advance_ea' => mb_substr($get($r, $col['advance']), 0, 20),
            'village_code' => $vc === '' ? '' : ($code($vc, 2) ?? mb_substr($vc, 0, 3)),
            'village_name' => mb_substr($get($r, $col['village'] === null ? null : $col['village'] + 1), 0, 150),
            'houses' => $num($get($r, $col['houses'] ?? null)), 'households' => $num($get($r, $col['households'] ?? null)),
            'population' => $num($get($r, $col['population'] ?? null)),
            'note' => mb_substr($get($r, $col['note']), 0, 2000),
        ];
    }
    if (!$res['rows'] && !$res['errors']) {
        $res['errors'][] = 'ไม่พบข้อมูล EA ในไฟล์';
    }
    return $res;
}

/** คอลัมน์ข้อมูลที่นำเข้า (ใช้เทียบว่ามีการเปลี่ยนแปลง) */
const EA_IMPORT_COLS = ['seq', 'region_code', 'region_name', 'province_code', 'province_name', 'district_code', 'district_name', 'tambon_code', 'tambon_name',
                        'area_type', 'ea_no', 'advance_ea', 'village_code', 'village_name', 'houses', 'households', 'population', 'note'];
