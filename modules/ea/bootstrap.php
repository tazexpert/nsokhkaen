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

/**
 * รหัส EA 15 หลัก = ภาค(1) + จังหวัด(2) + อำเภอ(2) + ตำบล(2) + เขตการปกครอง(1) + หมู่บ้าน(3; ไม่มี = 999) + EA(4)
 * เช่น 6 40 01 01 1 999 0001 = 640010119990001
 */
function ea_make_code(string $region, string $province, string $district, string $tambon, $area, string $village, string $eaNo): string
{
    $v = $village === '' ? '999' : (ctype_digit($village) ? str_pad((string)(int)$village, 3, '0', STR_PAD_LEFT) : '999');
    return substr($region, 0, 1) . $province . $district . $tambon . $area . $v . $eaNo;
}

/** คีย์ไม่ซ้ำของ EA (ใช้จับคู่) = จังหวัด + อำเภอ + ตำบล + เขตการปกครอง + EA (11 หลัก) */
function ea_key(array $r): string
{
    return $r['province_code'] . $r['district_code'] . $r['tambon_code'] . $r['area_type'] . $r['ea_no'];
}

/** SQL ของคีย์ EA (ตาราง alias a) */
const EA_KEY_SQL = 'CONCAT(a.province_code, a.district_code, a.tambon_code, a.area_type, a.ea_no)';

/** แยกรหัส EA จากข้อความ (คั่นด้วยช่องว่าง/จุลภาค/ขึ้นบรรทัด) — รับรหัส 15 หลัก หรือรหัสเดิม 11 หลัก */
function ea_parse_codes(string $text): array
{
    preg_match_all('/(?<!\d)(\d{15}|\d{11})(?!\d)/', $text, $m);
    return array_values(array_unique($m[1]));
}

/** เงื่อนไข SQL หา EA จากรายการรหัส (15 หลัก = ea_code, 11 หลัก = คีย์) → [sql, params] */
function ea_codes_sql(array $codes): array
{
    $c15 = array_values(array_filter($codes, fn($c) => strlen($c) === 15));
    $c11 = array_values(array_filter($codes, fn($c) => strlen($c) === 11));
    $or = [];
    if ($c15) {
        $or[] = 'a.ea_code IN (' . implode(',', array_fill(0, count($c15), '?')) . ')';
    }
    if ($c11) {
        $or[] = EA_KEY_SQL . ' IN (' . implode(',', array_fill(0, count($c11), '?')) . ')';
    }
    return ['(' . ($or ? implode(' OR ', $or) : '0') . ')', array_merge($c15, $c11)];
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
            [$cw, $cp] = ea_codes_sql($codes);
            $where[] = $cw;
            array_push($params, ...$cp);
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
        $ea = $p . $d . $t . $a . $e;                  // คีย์ไม่ซ้ำ (ไม่รวมภาค/หมู่บ้าน)
        if (isset($seen[$ea])) {
            if (count($res['errors']) < 30) {
                $res['errors'][] = 'แถวที่ ' . $line . ': EA ' . $p . '-' . $d . '-' . $t . '-' . $a . '-' . $e . ' ซ้ำกับแถวที่ ' . $seen[$ea];
            }
            continue;
        }
        $seen[$ea] = $line;
        $vc = $get($r, $col['village'] ?? null);
        $vcode = $vc === '' ? '' : ($code($vc, 2) ?? mb_substr($vc, 0, 3));
        $rc = mb_substr($get($r, $col['region']), 0, 2);
        $res['rows'][] = [
            'ea_key' => $ea, 'ea_code' => ea_make_code($rc, $p, $d, $t, $a, $vcode, $e), 'seq' => (int)$get($r, 0),
            'region_code' => $rc, 'region_name' => mb_substr($get($r, $col['region'] === null ? null : $col['region'] + 1), 0, 50),
            'province_code' => $p, 'province_name' => mb_substr($get($r, $col['province'] + 1), 0, 50),
            'district_code' => $d, 'district_name' => mb_substr($get($r, $col['district'] + 1), 0, 100),
            'tambon_code' => $t, 'tambon_name' => mb_substr($get($r, $col['tambon'] + 1), 0, 100),
            'area_type' => (int)$a, 'ea_no' => $e,
            'advance_ea' => mb_substr($get($r, $col['advance']), 0, 20),
            'village_code' => $vcode,
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

/* ------------------------------------------------------------------------
 * EA ของโครงการ: ไฟล์ EA_Report (CSV/xlsx) — 1 แถว = EA ที่โครงการใช้ในเดือนที่แจงนับนั้น
 * psu_no, รหัสอำเภอ, อำเภอ, รหัสตำบล, ตำบล, ea, รหัสหมู่บ้าน, หมู่บ้าน, latitude, longitude,
 * enumerate_month, enumerate_type, enumerate_year, project, เขตเทศบาล, ปีงบประมาณ
 * รอบ/งวด = เดือน + ปีที่แจงนับ เช่น "ม.ค. 2569" (ไม่ระบุเดือน = "ปี 2569")
 * ------------------------------------------------------------------------ */
const EA_REPORT_HEAD = ['psu_no', 'รหัสอำเภอ', 'อำเภอ', 'รหัสตำบล', 'ตำบล', 'ea', 'รหัสหมู่บ้าน', 'หมู่บ้าน', 'latitude', 'longitude',
                       'enumerate_month', 'enumerate_type', 'enumerate_year', 'project', 'เขตเทศบาล', 'ปีงบประมาณ'];
const EA_MONTHS_SHORT = [1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const EA_MONTHS_LONG = [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

/** ชื่อรอบ/งวดจากเดือน-ปีที่แจงนับ */
function ea_round_label(?int $month, ?int $year): string
{
    if ($month && $year) {
        return EA_MONTHS_SHORT[$month] . ' ' . $year;
    }
    return $year ? 'ปี ' . $year : '';
}

/** เดือนจากข้อความ (มกราคม / ม.ค. / 1) → 1–12 หรือ null */
function ea_month_num(string $v): ?int
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (ctype_digit($v)) {
        return (int)$v >= 1 && (int)$v <= 12 ? (int)$v : null;
    }
    $n = str_replace([' ', '.'], '', $v);
    foreach (EA_MONTHS_LONG as $i => $m) {
        if ($n === $m || $n === str_replace('.', '', EA_MONTHS_SHORT[$i])) {
            return $i;
        }
    }
    return null;
}

/** ปี พ.ศ. (รับ ค.ศ. ได้) */
function ea_year(string $v): ?int
{
    $y = (int)preg_replace('/\D/', '', $v);
    if ($y > 1900 && $y < 2400) {
        $y += 543;
    }
    return $y >= 2400 && $y <= 2700 ? $y : null;
}

/** รหัสโครงการเพื่อจับคู่ (ตัดจุด/ช่องว่าง) เช่น "สรง." → "สรง" */
function ea_project_key(string $code): string
{
    return mb_strtolower(preg_replace('/[\s.]+/u', '', $code));
}

/** อ่านแถวจากไฟล์ CSV หรือ xlsx → array ของ array (ค่าเป็นข้อความ) */
function ea_read_table(string $path, string $ext): array
{
    if ($ext === 'xlsx') {
        require_once APP_ROOT . '/libs/simplexlsx/SimpleXLSX.php';
        $x = \Shuchkin\SimpleXLSX::parse($path);
        if (!$x) {
            throw new RuntimeException('อ่านไฟล์ Excel ไม่ได้: ' . \Shuchkin\SimpleXLSX::parseError());
        }
        return array_map(fn($r) => array_map('strval', array_values($r)), $x->rows(0));
    }
    $text = text_to_utf8((string)file_get_contents($path));
    $first = strtok($text, "\n");
    $delim = substr_count((string)$first, "\t") > substr_count((string)$first, ',') ? "\t" : (substr_count((string)$first, ';') > substr_count((string)$first, ',') ? ';' : ',');
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
        $rows[] = array_map(fn($v) => (string)$v, $r);
    }
    fclose($fh);
    return $rows;
}

/**
 * อ่านไฟล์ EA ของโครงการ → ['rows' => [...], 'errors' => [], 'projects' => [key => code ในไฟล์]]
 * แต่ละแถว: ea_key (11 หลัก ใช้จับคู่), ea_code (15 หลัก), project_key, project_raw, psu_no, enum_type, enum_month, enum_year, fiscal_year, lat, lng, round_label
 *           + ข้อมูลพื้นที่ (ใช้สร้าง EA ใหม่ถ้าไม่มีในบัญชี)
 */
function ea_parse_project_file(string $path, string $ext, string $province = '40', string $region = '6'): array
{
    $res = ['rows' => [], 'errors' => [], 'projects' => [], 'skipped' => 0];
    try {
        $rows = ea_read_table($path, $ext);
    } catch (RuntimeException $e) {
        $res['errors'][] = $e->getMessage();
        return $res;
    }
    $norm = fn($v) => mb_strtolower(preg_replace('/\s+/u', '', (string)$v));
    // แถวหัวตาราง: มีคอลัมน์ ea และ project
    $head = null;
    foreach (array_slice($rows, 0, 10, true) as $i => $r) {
        $cells = array_map($norm, $r);
        if (in_array('ea', $cells, true) && in_array('project', $cells, true)) {
            $head = $i;
            break;
        }
    }
    if ($head === null) {
        $res['errors'][] = 'ไม่พบหัวตาราง (ต้องมีคอลัมน์ ' . implode(', ', EA_REPORT_HEAD) . ')';
        return $res;
    }
    $col = [];
    foreach ($rows[$head] as $i => $c) {
        $col[$norm($c)] ??= $i;
    }
    $need = ['รหัสอำเภอ', 'รหัสตำบล', 'ea', 'project', 'เขตเทศบาล'];
    foreach ($need as $k) {
        if (!isset($col[$k])) {
            $res['errors'][] = 'ไม่พบคอลัมน์ "' . $k . '"';
        }
    }
    if ($res['errors']) {
        return $res;
    }
    // ค่าในเซลล์: ตัด ="..." ที่ใส่กันเลขศูนย์หน้าหาย (ไฟล์ที่ส่งออกจากระบบนี้)
    $get = function (array $r, string $k) use ($col) {
        if (!isset($col[$k])) {
            return '';
        }
        $v = trim((string)($r[$col[$k]] ?? ''));
        return preg_match('/^="(.*)"$/s', $v, $m) ? trim($m[1]) : $v;
    };
    $code = function (string $v, int $len) {
        if ($v === '' || !preg_match('/^\d+(\.0+)?$/', $v)) {
            return null;
        }
        $v = (string)(int)$v;
        return strlen($v) <= $len ? str_pad($v, $len, '0', STR_PAD_LEFT) : null;
    };
    $areaOf = function (string $v) {
        $v = preg_replace('/\s+/u', '', $v);
        if ($v === '1' || str_starts_with($v, 'ใน')) {
            return 1;
        }
        return $v === '2' || str_starts_with($v, 'นอก') ? 2 : null;
    };
    $coord = function (string $v, float $min, float $max) {
        return is_numeric($v) && (float)$v >= $min && (float)$v <= $max ? round((float)$v, 7) : null;
    };
    $seen = [];
    $err = function (string $msg) use (&$res) {
        if (count($res['errors']) < 30) {
            $res['errors'][] = $msg;
        }
    };
    foreach (array_slice($rows, $head + 1, null, true) as $i => $r) {
        $line = $i + 1;
        if (!array_filter($r, fn($v) => trim((string)$v) !== '')) {
            $res['skipped']++;
            continue;
        }
        $d = $code($get($r, 'รหัสอำเภอ'), 2);
        $t = $code($get($r, 'รหัสตำบล'), 2);
        $e = $code($get($r, 'ea'), 4);
        $a = $areaOf($get($r, 'เขตเทศบาล'));
        $proj = $get($r, 'project');
        if (!$d || !$t || !$e || !$a) {
            $err('แถวที่ ' . $line . ': รหัสอำเภอ/ตำบล (2 หลัก) EA (4 หลัก) หรือเขตเทศบาล (ในเขต/นอกเขตเทศบาล) ไม่ถูกต้อง');
            continue;
        }
        if (ea_project_key($proj) === '') {
            $err('แถวที่ ' . $line . ': ไม่ระบุโครงการ (project)');
            continue;
        }
        $rawMonth = $get($r, 'enumerate_month');
        $month = ea_month_num($rawMonth);
        if ($rawMonth !== '' && !$month) {
            $err('แถวที่ ' . $line . ': เดือนไม่ถูกต้อง "' . $rawMonth . '"');
            continue;
        }
        $year = ea_year($get($r, 'enumerate_year'));
        $fy = ea_year($get($r, 'ปีงบประมาณ'));
        $ea = $province . $d . $t . $a . $e;
        $label = ea_round_label($month, $year);
        $pk = ea_project_key($proj);
        $key = $pk . '|' . $label . '|' . $ea;
        if (isset($seen[$key])) {
            $err('แถวที่ ' . $line . ': EA ' . $ea . ' ของ ' . $proj . ' รอบ ' . ($label ?: '-') . ' ซ้ำกับแถวที่ ' . $seen[$key]);
            continue;
        }
        $seen[$key] = $line;
        $res['projects'][$pk] ??= $proj;
        $psu = $get($r, 'psu_no');
        $type = $get($r, 'enumerate_type');
        $vcode = ($vc = $get($r, 'รหัสหมู่บ้าน')) === '' ? '' : ($code($vc, 2) ?? mb_substr($vc, 0, 3));
        $res['rows'][] = [
            'ea_key' => $ea, 'ea_code' => ea_make_code($region, $province, $d, $t, $a, $vcode, $e), 'project_key' => $pk, 'round_label' => $label,
            'psu_no' => mb_substr($psu, 0, 10), 'enum_type' => mb_substr($type, 0, 10),
            'enum_month' => $month, 'enum_year' => $year, 'fiscal_year' => $fy,
            'lat' => $coord($get($r, 'latitude'), -90, 90), 'lng' => $coord($get($r, 'longitude'), -180, 180),
            'district_code' => $d, 'district_name' => mb_substr($get($r, 'อำเภอ'), 0, 100),
            'tambon_code' => $t, 'tambon_name' => mb_substr($get($r, 'ตำบล'), 0, 100),
            'area_type' => $a, 'ea_no' => $e,
            'village_code' => $vcode,
            'village_name' => mb_substr($get($r, 'หมู่บ้าน'), 0, 150),
        ];
    }
    if (!$res['rows'] && !$res['errors']) {
        $res['errors'][] = 'ไม่พบข้อมูล EA ในไฟล์';
    }
    return $res;
}

/** แถวสำหรับส่งออก EA ของโครงการในรูปแบบเดียวกับไฟล์ EA_Report (นำเข้ากลับได้) */
function ea_report_row(array $r, string $projectCode): array
{
    $t = fn($v) => $v === '' || $v === null ? '' : '="' . $v . '"';
    return [$t($r['psu_no']), $t($r['district_code']), $r['district_name'], $t($r['tambon_code']), $r['tambon_name'], $t($r['ea_no']),
            $t($r['village_code']), $r['village_name'], $r['lat'] !== null ? (float)$r['lat'] : '', $r['lng'] !== null ? (float)$r['lng'] : '',
            $r['enum_month'] ? EA_MONTHS_LONG[(int)$r['enum_month']] : '', $r['enum_type'], $r['enum_year'] ?? '', $projectCode,
            EA_AREA_TYPES[(int)$r['area_type']] ?? '', $r['fiscal_year'] ?? ''];
}

/** ลำดับรอบ/งวด: ตามปี-เดือนที่แจงนับ แล้วตามชื่อรอบ */
const EA_ROUND_ORDER = 'pe.enum_year IS NULL, pe.enum_year, pe.enum_month IS NULL, pe.enum_month, pe.round_label';
