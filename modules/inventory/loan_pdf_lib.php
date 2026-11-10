<?php
/**
 * อ่านใบขออนุมัติยืมพัสดุ (PDF จาก e-office) — ใช้ตรวจสอบข้อมูลก่อนบันทึกการยืม ไม่เก็บไฟล์
 *   ข้อความ + ตำแหน่งได้จาก pdf.js ฝั่งเบราว์เซอร์: แต่ละหน้า = [[ข้อความ, x, y จากขอบบน, ความกว้าง], ...]
 *   เลขไทย ๐–๙ แปลงเป็นเลขอารบิกก่อนแยกข้อมูล
 */

const LOAN_PDF_MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
                         'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

/** ปรับข้อความ: เลขไทย → อารบิก · ํา → ำ · ตัดอักขระควบคุม */
function loan_pdf_norm(string $s): string
{
    $s = strtr($s, ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4', '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9']);
    $s = str_replace("\u{0E4D}\u{0E32}", "\u{0E33}", $s);
    $s = preg_replace('/[\x00-\x1F]/u', '', $s);
    // ฟอนต์บางตัว (TH SarabunPSK จาก Word) ทำให้ "ำ" ออกมาเป็น " า" "า" หรือ "่า" — ไม่มีคำไทยขึ้นต้นด้วย า จึงตัดช่องว่างหน้า า
    // แล้วซ่อมคำที่พบบ่อยในใบยืม
    $s = preg_replace('/(?<=\p{Thai}) (?=\x{0E32})/u', '', $s);
    static $fix = null;
    if ($fix === null) {      // บางไฟล์ "ำ" ออกมาเป็น "่า" (ต่าแหน่ง ส่านักงาน) — สร้างคำผิดแบบนี้เพิ่มจากรายการเดิม
        $fix = LOAN_PDF_AM_FIX;
        foreach (LOAN_PDF_AM_FIX as $right) {
            $fix[str_replace("\u{0E33}", "\u{0E48}\u{0E32}", $right)] = $right;
        }
    }
    return strtr($s, $fix);
}

/** คำที่ "ำ" หายไปเหลือ "า" (ซ่อมเฉพาะคำที่ไม่กำกวม) */
const LOAN_PDF_AM_FIX = [
    'สานัก' => 'สำนัก', 'ตาแหน่ง' => 'ตำแหน่ง', 'ลาดับ' => 'ลำดับ', 'จานวน' => 'จำนวน', 'ชานาญ' => 'ชำนาญ',
    'ชารุด' => 'ชำรุด', 'กาหนด' => 'กำหนด', 'ประจาปี' => 'ประจำปี', 'ประจาเดือน' => 'ประจำเดือน', 'ดาเนิน' => 'ดำเนิน',
    'สาหรับ' => 'สำหรับ', 'สารวจ' => 'สำรวจ', 'อาเภอ' => 'อำเภอ', 'คาสั่ง' => 'คำสั่ง', 'ทางาน' => 'ทำงาน',
    'จาเป็น' => 'จำเป็น', 'ชาระ' => 'ชำระ', 'สาเนา' => 'สำเนา', 'สาคัญ' => 'สำคัญ', 'สาเร็จ' => 'สำเร็จ',
];

/** คีย์เทียบข้อความไทย: ตัดช่องว่าง สระบน/ล่าง วรรณยุกต์ (pdf.js อ่านบางตัวไม่ได้) */
function loan_pdf_key(string $s): string
{
    return preg_replace('/[\s\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}-\x{0E4E}]/u', '', loan_pdf_norm($s));
}

/** คีย์ชื่อคน: ตัดคำนำหน้า (นาย นาง นางสาว น.ส.) ออกก่อน */
function loan_pdf_name_key(string $s): string
{
    $s = preg_replace('/^\s*(นางสาว|น\.\s*ส\.|นาย|นาง)\s*/u', '', loan_pdf_norm($s));
    return loan_pdf_key($s);
}

/** จัดข้อความเป็นบรรทัด (y ใกล้กัน ±2.5) แล้วรวมชิ้นที่ติดกันเป็นช่วง (ห่าง > 1.5pt = ช่วงใหม่) */
function loan_pdf_lines(array $pages): array
{
    $out = [];
    foreach ($pages as $pi => $items) {
        if (!is_array($items)) {
            continue;
        }
        $lines = [];
        foreach ($items as $it) {
            if (!is_array($it) || count($it) < 4 || !is_string($it[0])) {
                continue;
            }
            $t = loan_pdf_norm($it[0]);
            if (trim($t) === '') {
                continue;
            }
            $y = (float)$it[2];
            $key = null;
            foreach (array_keys($lines) as $ly) {
                if (abs((float)$ly - $y) <= 2.5) {
                    $key = $ly;
                    break;
                }
            }
            $lines[$key ?? (string)$y][] = [(float)$it[1], $t, (float)$it[1] + (float)$it[3]];
        }
        ksort($lines, SORT_NUMERIC);
        foreach ($lines as $y => $parts) {
            usort($parts, fn($a, $b) => $a[0] <=> $b[0]);
            $segs = [];
            foreach ($parts as [$x, $t, $end]) {
                $n = count($segs);
                if ($n && $x - $segs[$n - 1]['end'] < 1.5) {
                    $segs[$n - 1]['t'] .= $t;
                    $segs[$n - 1]['end'] = max($segs[$n - 1]['end'], $end);
                } else {
                    $segs[] = ['x' => $x, 't' => $t, 'end' => $end];
                }
            }
            $segs = array_values(array_filter(array_map(function ($s) {
                $s['t'] = trim(preg_replace('/\s+/u', ' ', $s['t']));
                $s['c'] = ($s['x'] + $s['end']) / 2;
                return $s;
            }, $segs), fn($s) => $s['t'] !== ''));
            if ($segs) {
                $out[] = ['page' => $pi, 'y' => (float)$y, 'segs' => $segs, 'text' => implode(' ', array_column($segs, 't'))];
            }
        }
    }
    return $out;
}

/** "1 เดือน ตุลาคม พ.ศ. 2569" → 2026-10-01 (เดือนเทียบแบบไม่สนวรรณยุกต์) */
function loan_pdf_date(string $d, string $month, string $year): ?string
{
    $mk = loan_pdf_key($month);
    $m = null;
    foreach (LOAN_PDF_MONTHS as $i => $name) {
        if ($mk === loan_pdf_key($name)) {
            $m = $i + 1;
        }
    }
    $y = (int)$year;
    $y = $y > 2400 ? $y - 543 : ($y < 100 ? $y + 1957 : $y);   // พ.ศ. → ค.ศ. (ปีย่อ 69 = 2569)
    return $m && checkdate($m, (int)$d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, (int)$d) : null;
}

/**
 * แยกข้อมูลจากใบยืม
 * คืน ['borrower', 'position', 'org', 'phone', 'purpose', 'doc_no', 'start_date', 'due_date', 'items' => [[seq, name, brand, serial, asset, qty]], 'errors']
 */
function loan_pdf_parse(array $pages, array $annots = []): array
{
    $res = ['borrower' => '', 'position' => '', 'org' => '', 'phone' => '', 'purpose' => '', 'doc_no' => '',
            'start_date' => null, 'due_date' => null, 'items' => [], 'errors' => []];
    $lines = loan_pdf_lines($pages);
    // ข้อความต่อกันทั้งไฟล์ (บางแบบฟอร์มขึ้นบรรทัดใหม่กลางข้อความ) · เส้นจุด "......" ถือเป็นช่องว่าง
    $flat = preg_replace(['/\.{2,}/u', '/\s+/u'], ' ', implode(' ', array_column($lines, 'text')));
    $stop = '(?=\s*(?:ตำแหน่ง|หน่วยงาน|หมายเลขโทรศัพท์|โทรศัพท์|มีความประสงค์|ตั้งแต่|$))';
    $dateRe = '[\s.]*(\d{1,2})[\s.]*เดือน[\s.]*([^\s.\d]+)[\s.]*พ\s*\.?\s*ศ\s*\.?[\s.]*(\d{4})';

    if (preg_match('/ข้าพเจ้า\s*(.+?)' . $stop . '/u', $flat, $m)) {
        $res['borrower'] = trim($m[1]);
    }
    if (preg_match('/ข้าพเจ้า.*?ตำแหน่ง\s*(.+?)' . $stop . '/u', $flat, $m)) {
        $res['position'] = trim($m[1]);
    }
    if (preg_match('/หน่วยงาน\s*(.+?)' . $stop . '/u', $flat, $m)) {
        $res['org'] = trim(preg_replace('/(จังหวัด|สำนักงาน)\s+(?=\p{Thai})/u', '$1', $m[1]));   // ชื่อหน่วยงานที่ขึ้นบรรทัดใหม่
    }
    if (preg_match('/หมายเลขโทรศัพท์\s*([0-9][0-9\s\-]{7,})/u', $flat, $m)) {
        $digits = preg_replace('/\D/', '', $m[1]);
        $res['phone'] = strlen($digits) === 10 ? substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6)
            : (strlen($digits) === 9 ? substr($digits, 0, 2) . '-' . substr($digits, 2, 3) . '-' . substr($digits, 5) : $digits);
    }
    if (preg_match('/ขอยืมพัสดุ\s*(.+?)(?=\s*(?:ตั้งแต่|$))/u', $flat, $m)) {
        $res['purpose'] = trim($m[1]);
    }
    foreach ($lines as $l) {
        if (preg_match('/^ที่\s+([^.\s].*?)\s+วันที่/u', $l['text'], $m) && preg_match('/\d/', $m[1])) {
            $res['doc_no'] = trim($m[1]);
            break;
        }
    }
    // เลขที่หนังสือจากตราประทับ e-office: "doc_no: ขก ๐๐๑๓/๑๒๖๙"
    foreach ($annots as $a) {
        if (preg_match('/doc_no\s*:\s*([^\r\n]+)/iu', $a, $m)) {
            $res['doc_no'] = trim(preg_replace('/\s+/u', ' ', loan_pdf_norm($m[1])));
            break;
        }
    }
    if (preg_match('/ตั้งแต่\s*วันที่' . $dateRe . '.*?ถึง\s*วันที่' . $dateRe . '/u', $flat, $m)) {
        $res['start_date'] = loan_pdf_date($m[1], $m[2], $m[3]);
        $res['due_date'] = loan_pdf_date($m[4], $m[5], $m[6]);
    }
    if (!$res['due_date'] && preg_match('/ส่งคืน\S*\s*ภายในวันที่' . $dateRe . '/u', $flat, $m)) {
        $res['due_date'] = loan_pdf_date($m[1], $m[2], $m[3]);
    }

    $res['items'] = loan_pdf_items($lines);

    if ($res['borrower'] === '') {
        $res['errors'][] = 'ไม่พบชื่อผู้ยืม (ข้อความ "ข้าพเจ้า …")';
    }
    if (!$res['start_date'] || !$res['due_date']) {
        $res['errors'][] = 'ไม่พบระยะเวลาการยืม (ข้อความ "ตั้งแต่ วันที่ … ถึง วันที่ …")';
    }
    if (!$res['items']) {
        $res['errors'][] = 'ไม่พบตารางรายการพัสดุที่ยืม';
    }
    return $res;
}

/** ชื่อคอลัมน์จากข้อความหัวตาราง (ไม่ใช่หัวคอลัมน์ = null) */
function loan_pdf_col_name(string $t): ?string
{
    $k = loan_pdf_key($t);
    if (str_contains($k, loan_pdf_key('หมายเลขพัสดุ')) || str_contains($k, loan_pdf_key('เลขครุภัณฑ์'))) {
        return 'asset';
    }
    if (str_starts_with($k, loan_pdf_key('ลำดั'))) {
        return 'seq';
    }
    if (str_starts_with($k, loan_pdf_key('ยี่ห้อ'))) {
        return 'brand';
    }
    if (stripos($t, 'serial') !== false || $k === loan_pdf_key('เครื่อง') || $k === loan_pdf_key('หมายเลขเครื่อง')) {
        return 'serial';
    }
    if ($k === loan_pdf_key('รายการ')) {
        return 'name';
    }
    if (str_starts_with($k, loan_pdf_key('จำน'))) {
        return 'qty';
    }
    return null;
}

/**
 * ตารางรายการ: หาหัวตาราง (คอลัมน์ "หมายเลขพัสดุ") แล้วแบ่งคอลัมน์ตามตำแหน่ง x ของหัวคอลัมน์
 *   แถวเริ่มที่บรรทัดที่มีเลขลำดับ — ถ้าตารางจัดกึ่งกลางแนวตั้ง (มีข้อมูลก่อนเลขลำดับแรก)
 *   ให้แต่ละบรรทัดไปอยู่กับเลขลำดับที่ใกล้ที่สุดแทน
 */
function loan_pdf_items(array $lines): array
{
    $head = null;
    foreach ($lines as $li => $line) {
        foreach ($line['segs'] as $s) {
            if (loan_pdf_col_name($s['t']) === 'asset') {
                $head = $li;
                break 2;
            }
        }
    }
    if ($head === null) {
        return [];
    }
    // หัวตารางอาจแยกหลายบรรทัด (เช่น "ลำดั" / "บ", "(Serial – no.)") — บรรทัดใกล้ ๆ ที่ยังไม่มีตัวเลข
    $cols = [];
    $page = $lines[$head]['page'];
    $headY = $lines[$head]['y'];
    $start = $head;
    while ($start > 0 && $lines[$start - 1]['page'] === $page && $headY - $lines[$start - 1]['y'] <= 30 && !preg_match('/\d/', $lines[$start - 1]['text'])) {
        $start--;
    }
    $li = $start;
    for (; $li < count($lines); $li++) {
        $l = $lines[$li];
        if ($l['page'] !== $page || $l['y'] - $headY > 30 || ($li > $head && preg_match('/\d/', $l['text']))) {
            break;
        }
        foreach ($l['segs'] as $s) {
            $name = loan_pdf_col_name($s['t']);
            if ($name && !isset($cols[$name])) {
                $cols[$name] = $s['c'];
            }
        }
    }
    if (!isset($cols['seq'])) {
        return [];
    }

    // บรรทัดข้อมูล → ช่องตามคอลัมน์ที่ใกล้ที่สุด
    $rows = [];
    for (; $li < count($lines); $li++) {
        $l = $lines[$li];
        if (str_contains(loan_pdf_key($l['text']), loan_pdf_key('ตามรายการที่ยืม'))) {
            break;
        }
        $cells = [];
        foreach ($l['segs'] as $s) {
            $best = null;
            $dist = INF;
            $isNum = preg_match('/^\d{1,3}\.?$/', $s['t']);
            foreach ($cols as $name => $cx) {
                if ($name === 'seq' && !$isNum) {
                    continue;                               // ช่องลำดับรับเฉพาะตัวเลข (คำสั้น ๆ ในช่องรายการมักอยู่ใกล้ช่องลำดับ)
                }
                if (abs($s['c'] - $cx) < $dist) {
                    $best = $name;
                    $dist = abs($s['c'] - $cx);
                }
            }
            $cells[$best][] = $s['t'];
        }
        $seq = implode(' ', $cells['seq'] ?? []);
        $rows[] = ['y' => $l['y'] + 10000 * $l['page'], 'cells' => $cells, 'seq' => preg_match('/^\d{1,3}\.?$/', $seq) ? (int)$seq : null];
    }
    $seqRows = array_keys(array_filter($rows, fn($r) => $r['seq'] !== null));
    if (!$seqRows) {
        return [];
    }
    $centered = $seqRows[0] > 0;     // มีข้อมูลก่อนเลขลำดับแรก = ตารางจัดกึ่งกลางแนวตั้ง

    $items = [];
    foreach ($seqRows as $ri) {
        $items[$ri] = ['seq' => $rows[$ri]['seq'], 'name' => '', 'brand' => '', 'serial' => '', 'asset' => '', 'qty' => ''];
    }
    $owner = null;
    foreach ($rows as $ri => $r) {
        if ($centered) {
            $owner = null;
            $dist = INF;
            foreach ($seqRows as $si) {
                if (abs($rows[$si]['y'] - $r['y']) < $dist) {
                    $owner = $si;
                    $dist = abs($rows[$si]['y'] - $r['y']);
                }
            }
        } elseif ($r['seq'] !== null) {
            $owner = $ri;
        }
        if ($owner === null) {
            continue;
        }
        foreach (['name', 'brand', 'serial', 'asset', 'qty'] as $c) {
            if (!empty($r['cells'][$c])) {
                $cur = $items[$owner][$c];
                $add = implode(' ', $r['cells'][$c]);
                // ข้อความไทยที่ขึ้นบรรทัดใหม่ต่อกันโดยไม่เว้นวรรค ("…พร้อม" + "ติดฟิล์ม")
                // และเลขที่ขึ้นบรรทัดใหม่หลัง "-" หรือ "/" ("1105-0970-" + "01198/62")
                $glue = (preg_match('/\p{Thai}$/u', $cur) && preg_match('/^\p{Thai}/u', $add)) || preg_match('/[-\/]$/', $cur) ? '' : ' ';
                $items[$owner][$c] = trim($cur . $glue . $add);
            }
        }
    }
    $items = array_values($items);
    foreach ($items as &$it) {
        $it['qty'] = preg_match('/\d+/', $it['qty'], $m) ? (int)$m[0] : 1;
        $it['name'] = trim($it['name'], " /");
    }
    unset($it);
    return $items;
}

/** กลุ่มตัวเลขในข้อความ (เทียบเป็นจำนวน: 0519 = 519) */
function loan_pdf_num_groups(string $s): array
{
    preg_match_all('/\d+/', $s, $m);
    return array_map('intval', $m[0]);
}

/** ตัวเลขทุกกลุ่มของ $need ปรากฏใน $have ตามลำดับหรือไม่ (ข้ามกลุ่มเกินได้ เช่นเลขที่ขึ้นบรรทัดใหม่) */
function loan_pdf_subseq(array $need, array $have): bool
{
    $i = 0;
    foreach ($have as $v) {
        if ($i < count($need) && $need[$i] === $v) {
            $i++;
        }
    }
    return $need && $i === count($need);
}

/**
 * จับคู่รายการในใบยืมกับทะเบียนครุภัณฑ์
 *   1) เลขครุภัณฑ์: ทุกกลุ่มตัวเลขของเลขในทะเบียนอยู่ในช่อง "หมายเลขพัสดุ" ตามลำดับ (เลือกที่ตรงยาวที่สุด)
 *   2) หมายเลขเครื่อง (Serial) ตรงกัน
 *   3) ตรงเฉพาะท้ายเลข (ลำดับ/ปี เช่น 0293/68) — แสดงเป็นตัวเลือกให้ตรวจ
 * คืน match = 'exact' | 'serial' | 'partial' | 'none' | 'accessory' (ไม่มีเลขพัสดุ เช่น สายชาร์จ)
 */
function loan_pdf_match_items(array $items, array $equipment): array
{
    $eq = [];
    foreach ($equipment as $e) {
        $eq[] = $e + ['groups' => loan_pdf_num_groups((string)$e['asset_no']),
                      'skey' => strtolower(preg_replace('/[^0-9a-z]/i', '', (string)$e['serial_number']))];
    }
    foreach ($items as &$it) {
        $have = loan_pdf_num_groups($it['asset']);
        $skey = strtolower(preg_replace('/[^0-9a-z]/i', '', $it['serial']));
        $it['match'] = 'none';
        $it['candidates'] = [];
        if (!$have && $skey === '') {
            $it['match'] = 'accessory';
            continue;
        }
        $best = [];
        $len = 0;
        foreach ($eq as $e) {
            if (count($e['groups']) >= 2 && loan_pdf_subseq($e['groups'], $have)) {
                if (count($e['groups']) > $len) {
                    $best = [$e];
                    $len = count($e['groups']);
                } elseif (count($e['groups']) === $len) {
                    $best[] = $e;
                }
            }
        }
        if (count($best) === 1) {
            $it['match'] = 'exact';
        } elseif (!$best && strlen($skey) >= 4) {
            $best = array_values(array_filter($eq, fn($e) => $e['skey'] !== '' && $e['skey'] === $skey));
            $it['match'] = $best ? 'serial' : 'none';
        }
        if (!$best && count($have) >= 2) {
            $tail = array_slice($have, -2);
            $best = array_values(array_filter($eq, fn($e) => count($e['groups']) >= 2 && array_slice($e['groups'], -2) === $tail));
            $it['match'] = $best ? 'partial' : 'none';
        } elseif (count($best) > 1) {
            $it['match'] = 'partial';
        }
        $it['candidates'] = array_map(fn($e) => array_diff_key($e, ['groups' => 1, 'skey' => 1]), array_slice($best, 0, 10));
    }
    unset($it);
    return $items;
}

/** หาผู้ใช้จากชื่อในใบยืม (ไม่สนคำนำหน้า ช่องว่าง วรรณยุกต์) */
function loan_pdf_match_user(string $name, array $users): ?int
{
    $key = loan_pdf_name_key($name);
    if ($key === '') {
        return null;
    }
    foreach ($users as $u) {
        if (loan_pdf_name_key((string)$u['full_name']) === $key) {
            return (int)$u['id'];
        }
    }
    return null;
}
