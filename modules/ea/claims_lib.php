<?php
/**
 * ใบเบิกค่าใช้จ่ายในการเดินทางไปราชการ (ออกใบสำคัญ) — ฟังก์ชันคำนวณและจัดหน้า
 * ชุดเอกสาร: แบบ 8708 ส่วนที่ 1 (+ หน้า 2) · แบบประกอบใบเบิกค่าใช้จ่าย (5 วัน/หน้า) · ใบรับรองแทนใบเสร็จรับเงิน แบบ บก.111 (7 รายการ/หน้า)
 */

const CLAIM_PLACES = ['home' => 'บ้านพัก', 'office' => 'สำนักงาน', 'country' => 'ประเทศไทย'];
const CLAIM_VEHICLES = ['car' => ['รถยนต์ส่วนตัว', 4], 'moto' => ['รถจักรยานยนต์ส่วนตัว', 2], 'other' => ['อื่น ๆ', 0]];
const CLAIM_PER_DIEM = ['ก' => 240, 'ข' => 270];          // เบี้ยเลี้ยงเดินทางเหมาจ่ายต่อวัน (ในประเทศ)
const CLAIM_ANNEX_LINES = 32;                               // แบบประกอบ: บรรทัดต่อหน้า (วันละอย่างน้อย 6 บรรทัด = 5 วัน/หน้า)
const CLAIM_RECEIPT_LINES = 31;                             // บก.111: บรรทัดในตารางต่อหน้า
const CLAIM_RECEIPT_LAST_LINES = 25;                        // บก.111 หน้าสุดท้าย (เว้นที่ลงชื่อรับรอง)
const CLAIM_WRAP = ['origin' => 17, 'places' => 20, 'extra' => 36, 'detail' => 74];   // ความยาวบรรทัด (ตัวอักษร) ของแต่ละช่อง
const CLAIM_MONTHS = [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
const CLAIM_MONTHS_SHORT = [1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const CLAIM_LEVELS = ['ชำนาญการพิเศษ', 'ชำนาญการ', 'ปฏิบัติการ', 'เชี่ยวชาญ', 'ทรงคุณวุฒิ', 'อาวุโส', 'ชำนาญงาน', 'ปฏิบัติงาน', 'ทักษะพิเศษ'];

/** จำนวนเงินเป็นตัวอักษร (แบบ BAHTTEXT ของ Excel) เช่น 4455.56 → สี่พันสี่ร้อยห้าสิบห้าบาทห้าสิบหกสตางค์ */
function baht_text(float $amount): string
{
    $amount = round($amount, 2);
    if ($amount == 0) {
        return 'ศูนย์บาทถ้วน';
    }
    $neg = $amount < 0;
    $amount = abs($amount);
    $baht = (int)floor($amount);
    $satang = (int)round(($amount - $baht) * 100);
    if ($satang === 100) {
        $baht++;
        $satang = 0;
    }
    $read = function (int $n) use (&$read): string {
        $digits = ['', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
        $units = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];
        if ($n >= 1000000) {
            $r = $n % 1000000;
            return $read(intdiv($n, 1000000)) . 'ล้าน' . ($r === 1 ? 'เอ็ด' : ($r ? $read($r) : ''));
        }
        $s = '';
        $str = (string)$n;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $d = (int)$str[$i];
            $pos = $len - $i - 1;
            if ($d === 0) {
                continue;
            }
            if ($pos === 1 && $d === 1) {
                $s .= 'สิบ';
            } elseif ($pos === 1 && $d === 2) {
                $s .= 'ยี่สิบ';
            } elseif ($pos === 0 && $d === 1 && $len > 1) {
                $s .= 'เอ็ด';
            } else {
                $s .= $digits[$d] . $units[$pos];
            }
        }
        return $s;
    };
    $out = ($baht ? $read($baht) . 'บาท' : '') . ($satang ? $read($satang) . 'สตางค์' : 'ถ้วน');
    return ($neg ? 'ลบ' : '') . $out;
}

/** แยกตำแหน่งกับระดับ เช่น "นักวิชาการคอมพิวเตอร์ชำนาญการ" → ["นักวิชาการคอมพิวเตอร์", "ชำนาญการ"] */
function claim_split_position(string $position): array
{
    $position = trim(preg_replace('/\s+/u', ' ', $position));
    foreach (CLAIM_LEVELS as $lv) {
        if ($position !== $lv && mb_substr($position, -mb_strlen($lv)) === $lv) {
            return [trim(mb_substr($position, 0, -mb_strlen($lv))), $lv];
        }
    }
    return [$position, ''];
}

/**
 * สัดส่วนเบี้ยเลี้ยงของการเดินทาง 1 รายการ (ระเบียบค่าใช้จ่ายในการเดินทางไปราชการ):
 * นับ 24 ชม. = 1 วัน · เศษเกิน 12 ชม. = 1 วัน · ไม่พักแรมและไม่ถึง 24 ชม.: เกิน 12 ชม. = 1 วัน, เกิน 6 ชม. = ครึ่งวัน
 */
function claim_allowance_days(string $date, ?string $dtime, ?string $rdate, ?string $rtime): float
{
    if (!$dtime || !$rtime) {
        return 0;
    }
    $start = strtotime($date . ' ' . $dtime);
    $end = strtotime(($rdate ?: $date) . ' ' . $rtime);
    if (!$start || !$end || $end <= $start) {
        return 0;
    }
    $hours = ($end - $start) / 3600;
    $full = floor($hours / 24);
    $rem = $hours - $full * 24;
    if ($rem > 12) {
        $full += 1;
    } elseif ($rem > 6) {
        $full += $full ? 1 : 0.5;          // พักแรม: เศษเกิน 6 ชม. นับรวมเป็นวัน · ไม่พักแรม: ครึ่งวัน
    }
    return (float)$full;
}

/**
 * คำนวณยอดเงินของใบเบิก (เขียนกลับลงใน $c): ค่าพาหนะรายวัน = กม. x อัตรา · เบี้ยเลี้ยงจากเวลารายวัน (ถ้า $autoPerDiem)
 * รวมเงิน · ขอรับเงินเพียง = ปัดเศษสตางค์ทิ้ง
 */
function claim_calc(array &$c, array &$days, bool $autoPerDiem = true): void
{
    $rate = (float)$c['km_rate'];
    $vehicle = 0.0;
    $units = [];
    foreach ($days as &$d) {
        $d['amount'] = round((float)$d['km'] * $rate, 2);
        $vehicle += $d['amount'];
        $d['allow'] = claim_allowance_days($d['trip_date'], $d['depart_time'] ?? null, $d['return_date'] ?? null, $d['return_time'] ?? null);
        if ($d['allow'] > 0) {
            $units[] = $d['allow'];
        }
    }
    unset($d);
    $c['vehicle_amount'] = round($vehicle, 2);
    if ($autoPerDiem) {
        $full = (float)$c['per_diem_rate'];
        $c['per_diem_days'] = array_sum(array_map(fn($u) => ceil($u), $units));
        $c['per_diem_amount'] = round(array_sum($units) * $full, 2);
        // หมายเหตุอัตรา เช่น (120x8) หรือ (240x2 + 120x3)
        $groups = [];
        foreach ($units as $u) {
            $per = $u >= 1 ? $full * $u / ceil($u) : $full * $u;
            $key = rtrim(rtrim(number_format($per, 2, '.', ''), '0'), '.');
            $groups[$key] = ($groups[$key] ?? 0) + (int)ceil($u);
        }
        $c['per_diem_note'] = $groups ? '(' . implode(' + ', array_map(fn($k, $n) => $k . 'x' . $n, array_keys($groups), $groups)) . ')' : '';
    }
    $c['total_amount'] = round((float)$c['per_diem_amount'] + (float)$c['lodging_amount'] + $c['vehicle_amount'] + (float)$c['other_amount'], 2);
    $c['receive_amount'] = floor($c['total_amount']);
}

/** คำอธิบายค่าพาหนะบนแบบ 8708 (ว่าง = สร้างจากระยะทางรวม) */
function claim_vehicle_text(array $c, array $days): string
{
    if (trim((string)$c['vehicle_desc']) !== '') {
        return $c['vehicle_desc'];
    }
    $km = array_sum(array_map(fn($d) => (float)$d['km'], $days));
    if ($km <= 0) {
        return '';
    }
    $kind = CLAIM_VEHICLES[$c['vehicle_kind']][0] ?? 'รถยนต์ส่วนตัว';
    return 'เงินชดเชยค่าพาหนะ' . $kind . 'ในการเดินทางไปราชการ ' . claim_num($km) . ' กม.ๆละ ' . claim_num((float)$c['km_rate']) . ' บาท';
}

/** ตัวเลขแบบไม่มีทศนิยมเกิน (873.89 / 4) */
function claim_num(float $n): string
{
    return rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
}

/** เงิน 2 ตำแหน่ง (ว่างถ้า 0) */
function claim_money(float $n, bool $blankZero = true): string
{
    return $blankZero && round($n, 2) == 0 ? '' : number_format($n, 2);
}

/** วันที่แบบเต็ม [วัน, เดือน, ปี พ.ศ.] */
function claim_date_parts(?string $date): array
{
    if (!$date || !($ts = strtotime($date))) {
        return ['', '', ''];
    }
    return [(int)date('j', $ts), CLAIM_MONTHS[(int)date('n', $ts)], (int)date('Y', $ts) + 543];
}

/** วันที่แบบเต็มข้อความ เช่น 12 พฤษภาคม 2569 */
function claim_date_long(?string $date): string
{
    [$d, $m, $y] = claim_date_parts($date);
    return $d === '' ? '' : $d . ' ' . $m . ' ' . $y;
}

/** วันที่แบบย่อ เช่น 29 พ.ค.69 */
function claim_date_short(?string $date): string
{
    if (!$date || !($ts = strtotime($date))) {
        return '';
    }
    return (int)date('j', $ts) . ' ' . CLAIM_MONTHS_SHORT[(int)date('n', $ts)] . substr((string)((int)date('Y', $ts) + 543), -2);
}

/** เวลา เช่น 07.20 น. */
function claim_time(?string $t): string
{
    return $t ? substr($t, 0, 2) . '.' . substr($t, 3, 2) . ' น.' : '';
}

/** ตำแหน่ง (ตัวอักษร) ที่ตัดบรรทัดได้: หลังช่องว่าง/จุลภาค และขอบคำภาษาไทย (IntlBreakIterator ถ้ามี extension intl) */
function claim_breaks(string $text): array
{
    $pos = [];
    $len = mb_strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1);
        if ($ch === ' ' || $ch === ',') {
            $pos[$i + 1] = true;
        }
    }
    if (class_exists('IntlBreakIterator')) {
        $it = IntlBreakIterator::createWordInstance('th');
        $it->setText($text);
        foreach ($it as $byte) {
            if ($byte > 0) {
                $pos[mb_strlen(substr($text, 0, $byte))] = true;
            }
        }
    }
    unset($pos[0], $pos[$len]);
    $pos = array_keys($pos);
    sort($pos);
    return $pos;
}

/** แบ่งข้อความเป็นบรรทัดตามความยาว — ตัดที่ขอบคำ/ช่องว่างใกล้ที่สุด (ยอมยาวเกินได้ 35% เพื่อไม่ตัดกลางคำ ช่องพิมพ์ย่อตัวอักษรให้พอดีเอง) */
function claim_wrap(string $text, int $width): array
{
    $out = [];
    foreach (preg_split('/\R/u', trim($text)) as $para) {
        $para = trim(preg_replace('/[ \t]+/u', ' ', $para));
        while (mb_strlen($para) > $width) {
            $breaks = claim_breaks($para);
            $before = array_values(array_filter($breaks, fn($p) => $p <= $width));
            $after = array_values(array_filter($breaks, fn($p) => $p > $width && $p <= $width * 1.35));
            if ($before && end($before) >= $width * 0.6) {
                $cut = end($before);
            } elseif ($after) {
                $cut = $after[0];
            } elseif ($before) {
                $cut = end($before);
            } else {
                $cut = $width;
                while ($cut > 1 && preg_match('/^[\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}-\x{0E4E}]/u', mb_substr($para, $cut, 1))) {
                    $cut--;                          // ไม่ตัดกลางสระ/วรรณยุกต์
                }
            }
            $out[] = trim(mb_substr($para, 0, $cut));
            $para = trim(mb_substr($para, $cut));
        }
        if ($para !== '') {
            $out[] = $para;
        }
    }
    return $out;
}

/** บรรทัดของที่อยู่ออกเดินทาง (แยกบรรทัดตามที่กรอก แล้วตัดบรรทัดยาว) */
function claim_origin_lines(array $c): array
{
    $lines = [];
    foreach (preg_split('/\R/u', (string)$c['origin_address']) as $l) {
        if (trim($l) !== '') {
            array_push($lines, ...claim_wrap($l, CLAIM_WRAP['origin']));
        }
    }
    return $lines;
}

/**
 * แบบประกอบ: แต่ละวัน → บล็อกบรรทัด {origin, places, extra, rows} · จัดหน้าตามจำนวนบรรทัด (อย่างน้อย 1 วัน/หน้า)
 * คืน [[block, ...], ...] (หน้าละรายการบล็อก)
 */
function claim_annex_pages(array $c, array $days): array
{
    $origin = claim_origin_lines($c);
    $purpose = trim(preg_replace('/\s+/u', ' ', (string)$c['purpose']));
    $pages = [[]];
    $used = 0;
    foreach (array_values($days) as $i => $d) {
        $b = ['seq' => $i + 1, 'day' => $d, 'origin' => $origin,
              'places' => claim_wrap((string)$d['places'], CLAIM_WRAP['places']),
              'extra' => claim_wrap(trim((string)($d['extra'] ?? '')) !== '' ? (string)$d['extra'] : $purpose, CLAIM_WRAP['extra'])];
        $b['rows'] = max(6, count($b['origin']), count($b['places']), count($b['extra']));
        if ($used > 0 && $used + $b['rows'] > CLAIM_ANNEX_LINES) {
            $pages[] = [];
            $used = 0;
        }
        $pages[count($pages) - 1][] = $b;
        $used += $b['rows'];
    }
    return $pages;
}

/** บก.111: รายการ (วันที่มีค่าพาหนะ) → บล็อก {day, lines} · จัดหน้าตามบรรทัด (ยอดยกมา 1 บรรทัด · หน้าสุดท้ายเว้นที่ลงชื่อ) */
function claim_receipt_pages(array $c, array $days): array
{
    $origin = implode(' ', array_map('trim', array_filter(preg_split('/\R/u', (string)$c['origin_address']), fn($l) => trim($l) !== '')));
    $blocks = [];
    foreach ($days as $d) {
        if ((float)$d['km'] <= 0 && (float)$d['amount'] <= 0) {
            continue;
        }
        $text = 'เงินชดเชยในการใช้พาหนะส่วนตัวเดินทางไปราชการจาก ' . $origin . ' ' . trim(preg_replace('/\s+/u', ' ', (string)$d['places']))
              . ' ระยะทางไปกลับ ' . claim_num((float)$d['km']) . ' กม. (กม.ละ ' . claim_num((float)$c['km_rate']) . ' บาท)';
        $lines = claim_wrap($text, CLAIM_WRAP['detail']);
        $blocks[] = ['day' => $d, 'lines' => $lines, 'rows' => count($lines) + 1];
    }
    if (!$blocks) {
        return [];
    }
    $pages = [[]];
    $used = 0;
    foreach ($blocks as $b) {
        $cap = CLAIM_RECEIPT_LINES - (count($pages) > 1 ? 1 : 0);
        if ($used > 0 && $used + $b['rows'] > $cap) {
            $pages[] = [];
            $used = 0;
        }
        $pages[count($pages) - 1][] = $b;
        $used += $b['rows'];
    }
    // หน้าสุดท้ายต้องเหลือที่สำหรับข้อความรับรองและลงชื่อ: ย้ายรายการสุดท้ายไปหน้าใหม่ (หรือขึ้นหน้าใหม่สำหรับลงชื่อ)
    $last = array_pop($pages);
    $rows = array_sum(array_column($last, 'rows')) + ($pages ? 1 : 0);
    if ($rows > CLAIM_RECEIPT_LAST_LINES) {
        $tail = count($last) > 1 ? [array_pop($last)] : [];
        $pages[] = $last;
        $pages[] = $tail;
    } else {
        $pages[] = $last;
    }
    return $pages;
}

/** จำนวนหน้าเอกสาร: [แบบประกอบ, บก.111, รวม (นับแบบ 8708 เป็น 1)] */
function claim_pages(array $c, array $days): array
{
    $annex = count(claim_annex_pages($c, $days));
    $receipt = count(claim_receipt_pages($c, $days));
    return [$annex, $receipt, 1 + $annex + $receipt];
}

/** ค่าเริ่มต้นของใบเบิกใหม่: จากใบเบิกล่าสุดของผู้ใช้ (ที่อยู่ ตำแหน่ง อัตรา) หรือข้อมูลผู้ใช้ */
function claim_defaults(array $user): array
{
    [$pos, $lv] = claim_split_position((string)($user['position'] ?? ''));
    $c = [
        'id' => 0, 'user_id' => (int)$user['id'], 'project_id' => null, 'project_other' => '', 'claim_date' => date('Y-m-d'),
        'office' => ORG_NAME, 'addressee' => 'สถิติจังหวัดขอนแก่น', 'order_no' => '', 'order_date' => null,
        'full_name' => preg_replace('/\s+/u', ' ', (string)$user['full_name']), 'position' => $pos, 'level' => $lv, 'department' => ORG_NAME,
        'companions' => '', 'purpose' => '', 'depart_from' => 'home', 'return_to' => 'home', 'origin_address' => '', 'claim_for' => 'self',
        'per_diem_type' => 'ก', 'per_diem_rate' => 240, 'per_diem_days' => 0, 'per_diem_amount' => 0, 'per_diem_note' => '',
        'lodging_type' => '', 'lodging_days' => 0, 'lodging_amount' => 0, 'vehicle_kind' => 'car', 'km_rate' => 4, 'vehicle_desc' => '',
        'vehicle_amount' => 0, 'other_desc' => '', 'other_amount' => 0, 'total_amount' => 0, 'receive_amount' => 0,
        'loan_no' => '', 'loan_date' => null, 'loan_amount' => 0, 'note' => '', 'status' => 'draft',
    ];
    $st = db()->prepare('SELECT * FROM ea_claims WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([(int)$user['id']]);
    if ($last = $st->fetch()) {
        foreach (['office', 'addressee', 'position', 'level', 'department', 'origin_address', 'depart_from', 'return_to', 'per_diem_type', 'per_diem_rate',
                  'vehicle_kind', 'km_rate', 'project_id', 'project_other', 'purpose'] as $k) {
            $c[$k] = $last[$k];
        }
    }
    return $c;
}

/** สิทธิ์: เจ้าของใบเบิก หรือผู้ดูแลระบบบริหารโครงการ */
function claim_can_view(array $c): bool
{
    return ea_can('admin') || (int)$c['user_id'] === (int)current_user()['id'];
}

/** แก้ไขได้: ร่าง (เจ้าของ/ผู้ดูแล) · ออกใบสำคัญแล้ว แก้ได้เฉพาะผู้ดูแล */
function claim_can_edit(array $c): bool
{
    return claim_can_view($c) && ($c['status'] !== 'issued' || ea_can('admin'));
}
