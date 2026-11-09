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
// ความกว้างบรรทัด (px ที่ตัวอักษร 13px) ของแต่ละช่อง = ความกว้างด้านในของช่องในแบบพิมพ์ (เผื่อเล็กน้อย)
//   origin = ออกจาก/ถึง · places = สถานที่ · extra = รายการค่าใช้จ่ายที่ขอเบิก (แบบประกอบ) · detail = รายละเอียด (ใบรับรองการจ่ายเงิน)
const CLAIM_WRAP = ['origin' => 90, 'places' => 110, 'extra' => 174, 'detail' => 422];
/**
 * ความกว้างตัวอักษร (px) ของฟอนต์ Sarabun ขนาด 13px — ใช้ตัดบรรทัดในแบบประกอบ/ใบรับรอง ให้พอดีความกว้างช่องจริง
 * สระบน/ล่างและวรรณยุกต์ = 0 · ตัวอักษรที่ไม่มีในตาราง = 7.5
 */
const CLAIM_GLYPH_W = [
    0x0020 => 3.2, 0x0021 => 3.1, 0x0022 => 4.6, 0x0023 => 8.9, 0x0024 => 6.9, 0x0025 => 11.7, 0x0026 => 8.7, 0x0027 => 2.5, 0x0028 => 4.1, 0x0029 => 4.1,
    0x002A => 5.8, 0x002B => 7.9, 0x002C => 3.3, 0x002D => 4.4, 0x002E => 3.3, 0x002F => 5.4, 0x0030 => 7.2, 0x0031 => 7.2, 0x0032 => 7.2, 0x0033 => 7.2,
    0x0034 => 7.2, 0x0035 => 7.2, 0x0036 => 7.2, 0x0037 => 7.2, 0x0038 => 7.2, 0x0039 => 7.2, 0x003A => 3.3, 0x003B => 3.3, 0x003C => 7.9, 0x003D => 7.9,
    0x003E => 7.9, 0x003F => 5.9, 0x0040 => 10.3, 0x0041 => 7.8, 0x0042 => 7.7, 0x0043 => 7.6, 0x0044 => 8.1, 0x0045 => 7.2, 0x0046 => 7.2, 0x0047 => 8.3,
    0x0048 => 8.8, 0x0049 => 3.8, 0x004A => 5.7, 0x004B => 7.9, 0x004C => 7.1, 0x004D => 10.4, 0x004E => 8.9, 0x004F => 8.6, 0x0050 => 7.5, 0x0051 => 8.6,
    0x0052 => 7.5, 0x0053 => 6.9, 0x0054 => 7.2, 0x0055 => 8.5, 0x0056 => 7.8, 0x0057 => 10.8, 0x0058 => 7.9, 0x0059 => 7.5, 0x005A => 7.4, 0x005B => 4,
    0x005C => 5, 0x005D => 4, 0x005E => 7.6, 0x005F => 4.2, 0x0060 => 5.2, 0x0061 => 6.7, 0x0062 => 7, 0x0063 => 6.2, 0x0064 => 7, 0x0065 => 6.7,
    0x0066 => 4.3, 0x0067 => 7, 0x0068 => 7.2, 0x0069 => 3.2, 0x006A => 3.2, 0x006B => 6.3, 0x006C => 3.8, 0x006D => 10.9, 0x006E => 7.1, 0x006F => 7.2,
    0x0070 => 7.1, 0x0071 => 7.1, 0x0072 => 4.5, 0x0073 => 5.4, 0x0074 => 4.9, 0x0075 => 7.2, 0x0076 => 6.2, 0x0077 => 8.7, 0x0078 => 6, 0x0079 => 6,
    0x007A => 5.9, 0x007B => 4.2, 0x007C => 3.1, 0x007D => 4.2, 0x007E => 7.9, 0x0E01 => 7.5, 0x0E02 => 7.7, 0x0E03 => 7.9, 0x0E04 => 7.8, 0x0E05 => 7.9,
    0x0E06 => 8.3, 0x0E07 => 5.9, 0x0E08 => 7.2, 0x0E09 => 7.4, 0x0E0A => 7.9, 0x0E0B => 8, 0x0E0C => 10.5, 0x0E0D => 10.8, 0x0E0E => 8, 0x0E0F => 8,
    0x0E10 => 7, 0x0E11 => 9.2, 0x0E12 => 10.8, 0x0E13 => 10.8, 0x0E14 => 7.7, 0x0E15 => 7.9, 0x0E16 => 7.5, 0x0E17 => 8.5, 0x0E18 => 7, 0x0E19 => 8.3,
    0x0E1A => 8.4, 0x0E1B => 8.5, 0x0E1C => 8.1, 0x0E1D => 8.1, 0x0E1E => 9.4, 0x0E1F => 9.4, 0x0E20 => 8, 0x0E21 => 8, 0x0E22 => 7.4, 0x0E23 => 6.3,
    0x0E24 => 7.5, 0x0E25 => 7.5, 0x0E26 => 8, 0x0E27 => 6.7, 0x0E28 => 7.8, 0x0E29 => 8.8, 0x0E2A => 7.5, 0x0E2B => 8.3, 0x0E2C => 9.4, 0x0E2D => 7.6,
    0x0E2E => 7.3, 0x0E2F => 7.1, 0x0E30 => 6.9, 0x0E31 => 0, 0x0E32 => 6.3, 0x0E33 => 6.3, 0x0E34 => 0, 0x0E35 => 0, 0x0E36 => 0, 0x0E37 => 0,
    0x0E38 => 0, 0x0E39 => 0, 0x0E3A => 0, 0x0E3B => 13, 0x0E3C => 13, 0x0E3D => 13, 0x0E3E => 13, 0x0E3F => 7.7, 0x0E40 => 4, 0x0E41 => 7.5,
    0x0E42 => 4.9, 0x0E43 => 4.9, 0x0E44 => 5.1, 0x0E45 => 3.8, 0x0E46 => 8.1, 0x0E47 => 0, 0x0E48 => 0, 0x0E49 => 0, 0x0E4A => 0, 0x0E4B => 0,
    0x0E4C => 0, 0x0E4D => 0, 0x0E4E => 0, 0x0E4F => 8.1, 0x0E50 => 8.6, 0x0E51 => 8.8, 0x0E52 => 8.9, 0x0E53 => 9, 0x0E54 => 8.8, 0x0E55 => 8.8,
    0x0E56 => 8.8, 0x0E57 => 9.7, 0x0E58 => 8.8, 0x0E59 => 9, 0x0E5A => 10, 0x0E5B => 13.3,
];

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

/**
 * คำไทยที่พบบ่อยในใบเบิก (สถานที่ ที่อยู่ รายการ) — ใช้หาขอบคำเมื่อเซิร์ฟเวอร์ไม่มี extension intl
 * (มี intl ใช้ IntlBreakIterator ซึ่งแม่นกว่า) · คำที่ไม่อยู่ในรายการตัดตามกลุ่มอักษร (claim_tcc_ok)
 */
const CLAIM_THAI_WORDS = <<<'TXT'
    ประชาสัมพันธ์ สถานประกอบการ เมืองขอนแก่น มหาวิทยาลัย หนองสองห้อง เจ้าหน้าที่ เบี้ยเลี้ยง โคกโพธิ์ไชย ท่องเที่ยว ปฏิบัติงาน อุตสาหกรรม เขาสวนกวาง
    เชื้อเพลิง ข้าราชการ ครัวเรือน ฐานข้อมูล ประกอบการ ประสานงาน ภารดาเพลส มัญจาคีรี รายไตรมาส สถานศึกษา สวัสดิการ อุบลรัตน์
    เทคโนโลยี เปือยน้อย เวียงเก่า แบบสอบถาม โรงพยาบาล ก่อสร้าง ตะวันออก ตัวอย่าง ปรับปรุง ภูผาม่าน วิทยาลัย ศาลากลาง
    สนับสนุน สัมภาษณ์ สารสนเทศ สำนักงาน หนองนาคำ หนองเรือ หมู่บ้าน เศรษฐกิจ แห่งชาติ โรงเรียน กรุงเทพ ขอนแก่น
    จังหวัด จัดเก็บ ดิจิทัล ตรวจสอบ ตะวันตก ทะเบียน บ้านฝาง บ้านแฮด บ้านไผ่ ปฏิบัติ ประชากร ประเมิน
    พนักงาน พื้นที่ ภูเวียง ลูกจ้าง สมบูรณ์ สูงอายุ ส่วนตัว องค์การ เดินทาง แวงน้อย แวงใหญ่ โครงการ
    โนนศิลา กระนวน การค้า ข้อมูล ควบคุม คุณภาพ ชี้แจง ติดตาม ที่พัก ธุรกิจ น้ำพอง น้ำมัน
    บริการ บริษัท บริหาร ประกอบ ประชุม ประเทศ พระยืน มหานคร ยอดขาย รถยนต์ รวบรวม รับรู้
    ราชการ ราษฎร์ สอบถาม สัมมนา สำมะโน สีชมพู สุขภาพ เขาสวน เทศบาล แรงงาน ไตรมาส จำกัด
    ชุมชน ชุมแพ ซำสูง ตำรวจ ตําบล ทำงาน นิเทศ ประจำ พัฒนา พาหนะ มหาชน ระดับ
    ศูนย์ สถานี สถิติ สร้าง สังคม สำรวจ อำเภอ เกษตร เดือน เพื่อ เมือง เหนือ
    กลับ กลาง กวาง ชนบท ด้าน ตรวจ ตลาด ตำบล น้อย บ้าน ภาวะ ม่วง
    ยนต์ ร่วม ร้าน สถาน ส่วน หนอง หมู่ ห้วย ห้าง อบรม เก็บ เก่า
    เข้า เงิน แขวง ใหญ่ ใหม่ กับ การ ของ ค่า งาน จัด ซอย
    ฐาน ดอน ถนน ที่ นคร บัว ผู้ มอบ ราย วัด ศรี สอบ
    สุข ส่ง เขต แบบ และ โคก โนน ใต้ ให้ คำ ทำ นา
    ปี ผล พบ พล มา รถ ใน ไป
TXT;

/**
 * ตัดก่อนตัวอักษรที่ตำแหน่ง $i ได้หรือไม่ (ไม่แยกกลุ่มอักษรไทย):
 * ห้ามขึ้นบรรทัดด้วยสระหลัง/สระบน-ล่าง/วรรณยุกต์ (ะ ั า ำ ิ-ฺ ็-๎ ๅ ฯ ๆ) · ห้ามจบบรรทัดด้วยสระหน้า (เ แ โ ใ ไ)
 * ห้ามแยกตัวอักษรที่มีการันต์ตามหลัง (เช่น "ค์") ออกจากพยางค์ก่อนหน้า
 */
function claim_tcc_ok(array $chars, int $i): bool
{
    if ($i <= 0 || $i >= count($chars)) {
        return false;
    }
    $cur = mb_ord($chars[$i]);
    $prev = mb_ord($chars[$i - 1]);
    if ($cur === 0x0E30 || ($cur >= 0x0E31 && $cur <= 0x0E3A) || ($cur >= 0x0E45 && $cur <= 0x0E4E) || $cur === 0x0E2F) {
        return false;
    }
    if ($prev >= 0x0E40 && $prev <= 0x0E44) {
        return false;
    }
    for ($j = $i + 1; $j < count($chars) && $j <= $i + 2; $j++) {      // ค์ / ตร์ (อักษร + วรรณยุกต์/สระ + การันต์)
        $o = mb_ord($chars[$j]);
        if ($o === 0x0E4C) {
            return false;
        }
        if (!($o >= 0x0E34 && $o <= 0x0E3A) && !($o >= 0x0E47 && $o <= 0x0E4B)) {
            break;
        }
    }
    return true;
}

/** ตำแหน่ง (ตัวอักษร) ที่ตัดบรรทัดได้: หลังช่องว่าง/จุลภาค และขอบคำภาษาไทย (IntlBreakIterator หรือรายการคำ CLAIM_THAI_WORDS) */
function claim_breaks(string $text): array
{
    $pos = [];
    $chars = mb_str_split($text);
    $len = count($chars);
    foreach ($chars as $i => $ch) {
        if ($ch === ' ' || $ch === ',' || $ch === '/') {
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
    } else {
        // ไม่มี intl: แบ่งคำแบบยาวที่สุดก่อนด้วยรายการคำ (ส่วนที่ไม่รู้จักไม่ตัดภายใน แต่ตัดที่ขอบได้)
        static $dict = null, $maxLen = 0;
        if ($dict === null) {
            $dict = array_fill_keys(preg_split('/\s+/u', trim(CLAIM_THAI_WORDS)), true);
            foreach (array_keys($dict) as $w) {
                $maxLen = max($maxLen, mb_strlen((string)$w));
            }
        }
        $isThai = fn($c) => ($o = mb_ord($c)) >= 0x0E01 && $o <= 0x0E5B;
        $i = 0;
        while ($i < $len) {
            if (!$isThai($chars[$i])) {
                $i++;
                continue;
            }
            $found = 0;
            for ($l = min($maxLen, $len - $i); $l >= 2; $l--) {
                if (isset($dict[implode('', array_slice($chars, $i, $l))]) && ($i + $l >= $len || claim_tcc_ok($chars, $i + $l))) {
                    $found = $l;
                    break;
                }
            }
            if ($found) {
                if (claim_tcc_ok($chars, $i)) {
                    $pos[$i] = true;
                }
                $pos[$i + $found] = true;
                $i += $found;
            } else {
                $i++;                                 // ส่วนที่ไม่รู้จัก: เดินต่อจนเจอคำในรายการ
            }
        }
    }
    unset($pos[0], $pos[$len]);
    $pos = array_keys(array_filter($pos));
    // ขอบคำระหว่างอักษรไทยต้องไม่แยกกลุ่มอักษร (กันกรณีรายการคำ/ICU ตัดผิด)
    $pos = array_values(array_filter($pos, fn($p) => $p >= $len || mb_ord($chars[$p]) < 0x0E01 || mb_ord($chars[$p]) > 0x0E5B || claim_tcc_ok($chars, $p)));
    // คำย่อ ต. อ. จ. ถ. ม. ให้อยู่กับคำถัดไป (ไม่ตัดหลังจุด)
    $pos = array_values(array_filter($pos, fn($p) => !($chars[$p - 1] === '.' && $p >= 2 && ($p < 3 || $chars[$p - 3] === ' ' || $chars[$p - 3] === ','))));
    sort($pos);
    return $pos;
}

/** ความกว้างข้อความ (px ที่ 13px ฟอนต์ Sarabun) */
function claim_text_width(string $text): float
{
    $w = 0.0;
    foreach (mb_str_split($text) as $ch) {
        $w += CLAIM_GLYPH_W[mb_ord($ch)] ?? 7.5;
    }
    return $w;
}

/**
 * แบ่งข้อความเป็นบรรทัดตามความกว้างจริง (px) — ตัดที่ขอบคำ/ช่องว่างใกล้ที่สุด
 * ยอมเกินได้ไม่เกิน 10% เมื่อไม่มีจุดตัดที่เหมาะ (หน้าพิมพ์ย่อตัวอักษรทั้งตารางให้พอดีเอง)
 */
function claim_wrap(string $text, float $width): array
{
    $out = [];
    foreach (preg_split('/\R/u', trim($text)) as $para) {
        $para = trim(preg_replace('/[ \t]+/u', ' ', $para));
        while ($para !== '' && claim_text_width($para) > $width) {
            // ความกว้างสะสมถึงตำแหน่ง i (ตัวอักษร)
            $cum = [0.0];
            foreach (mb_str_split($para) as $i => $ch) {
                $cum[$i + 1] = $cum[$i] + (CLAIM_GLYPH_W[mb_ord($ch)] ?? 7.5);
            }
            $breaks = claim_breaks($para);
            $chars = mb_str_split($para);
            $before = array_values(array_filter($breaks, fn($p) => $cum[$p] <= $width));
            // จุดตัดที่ช่องว่าง/จุลภาค (ขอบวลี) มาก่อนขอบคำ ถ้าได้ความยาวบรรทัด ≥ 60%
            $spaces = array_values(array_filter($before, fn($p) => in_array($chars[$p - 1], [' ', ','], true) && $cum[$p] >= $width * 0.6));
            $after = array_values(array_filter($breaks, fn($p) => $cum[$p] > $width && $cum[$p] <= $width * 1.1));
            if ($spaces) {
                $cut = end($spaces);
            } elseif ($before && $cum[end($before)] >= $width * 0.6) {
                $cut = end($before);
            } elseif ($after) {
                $cut = $after[0];
            } elseif ($before) {
                $cut = end($before);
            } else {
                $cut = 1;
                while ($cut < count($cum) - 1 && $cum[$cut + 1] <= $width) {
                    $cut++;
                }
                $c0 = $cut;
                while ($cut > 1 && !claim_tcc_ok($chars, $cut)) {
                    $cut--;                          // ไม่แยกกลุ่มอักษรไทย (ต|ำ, เ|ก, ค|์ ...)
                }
                if ($cut <= 1) {
                    $cut = $c0;
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
