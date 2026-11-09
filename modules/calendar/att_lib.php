<?php
/**
 * เวลาปฏิบัติราชการ (cal_attendance) — นำเข้าจาก PDF "รายงานข้อมูลเวลาปฏิบัติราชการ" และแสดงในแผนการปฏิบัติงาน
 *
 * รหัสในแผน (ตัดสินเฉพาะวันทำงานก่อนวันที่พิมพ์รายงาน):
 *   ลงเวลาเข้า ≤ 08:30 และออก ≥ 16:30 → ไม่แสดง · ช่อง "สาย" ในไฟล์มีค่า → ส
 *   เข้า 12:00–13:00 และออกหลัง 16:30 → ลาเช้า · เข้าก่อน 08:30 และออก 12:00–13:00 → ลาบ่าย
 *   ลงเวลาไม่ครบ (มีเข้าหรือออกอย่างเดียว) → ล สีแดง + แจ้งเตือนเจ้าตัว
 *   ไม่ลงเวลาเลย: มีรหัสโครงการในแผน → แสดงรหัสโครงการ (ไม่ขาด) · มีกิจกรรมในปฏิทินที่สถานที่ไม่ใช่ Zoom / ศาลากลางจังหวัดขอนแก่น /
 *     ห้องประชุมสำนักงานสถิติจังหวัดขอนแก่น → ร (ไปราชการ) · นอกนั้น → ข (ขาดราชการ)
 *   การลา / รับรองการมาในไฟล์ → แสดงรหัสตามไฟล์ · ผู้ดูแลแก้ ข → ล ได้อย่างเดียว
 */

const ATT_IN_LIMIT = '08:30';
const ATT_OUT_LIMIT = '16:30';
const ATT_LEAVE_NAMES = ['ก' => 'ลากิจ', 'ป' => 'ลาป่วย', 'พ' => 'ลาพักผ่อน', 'ค' => 'ลาคลอดบุตร', 'ด' => 'ลากิจเลี้ยงดูบุตร', 'ช' => 'ลาไปช่วยเหลือภริยาที่คลอดบุตร',
                          'บ' => 'ลาอุปสมบท', 'ฮ' => 'ลาประกอบพิธีฮัจญ์', 'ท' => 'ลาเพื่อรับการตรวจเลือกทหาร', 'ศ' => 'ลาไปศึกษา/ฝึกอบรม/ปฏิบัติงานต่างประเทศ',
                          'ต' => 'ลาติดตามคู่สมรส', 'ฟ' => 'ลาไปฟื้นฟูสมรรถภาพด้านอาชีพ', 'ร' => 'อบรม/สัมมนา/ประชุม/ไปราชการ', 'ษ' => 'กรณีพิเศษ',
                          'ล' => 'รับรองการมาปฏิบัติราชการ', 'ข' => 'ขาดราชการ', 'ซ' => 'เซ็นชื่อ', 'C' => 'COVID 19'];
/** สถานที่ที่ไม่นับเป็น "ไปราชการ" (ตัดช่องว่าง เทียบแบบมีคำนี้อยู่) */
const ATT_LOCAL_PLACES = ['zoom', 'ศาลากลางจังหวัดขอนแก่น', 'ห้องประชุมสำนักงานสถิติจังหวัดขอนแก่น'];

/** ข้อความจาก pdf.js: รวมสระอำที่แยก (ํ + า), ตัดอักขระควบคุม */
function att_norm_text(string $s): string
{
    $s = str_replace(["\u{0E4D}\u{0E32}", "\u{0E4D}\u{0E32}"], "\u{0E33}", $s);
    return preg_replace('/[\x00-\x1F]/u', '', $s);
}

/** คีย์เทียบชื่อ: ตัดช่องว่าง วรรณยุกต์ และเครื่องหมาย (pdf.js อ่านวรรณยุกต์บางตัวไม่ได้) */
function att_name_key(string $s): string
{
    $s = att_norm_text($s);
    return preg_replace('/[\s\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}-\x{0E4E}]/u', '', $s);
}

/** เวลา "07:51" → "07:51:00" (ไม่ใช่เวลา = null) */
function att_time(string $s): ?string
{
    return preg_match('/^(\d{1,2})[:.](\d{2})$/', trim($s), $m) && (int)$m[1] < 24 && (int)$m[2] < 60 ? sprintf('%02d:%02d:00', $m[1], $m[2]) : null;
}

/**
 * แยกข้อมูลจากข้อความในไฟล์ PDF (pdf.js: แต่ละหน้า = [[ข้อความ, x, y จากขอบบน, ความกว้าง], ...])
 * คืน ['people' => [['name', 'position', 'rows' => [work_date => row]], ...], 'report_at', 'errors']
 */
function att_parse_pages(array $pages): array
{
    $res = ['people' => [], 'report_at' => null, 'errors' => []];
    $cur = null;
    foreach ($pages as $pi => $items) {
        // จัดเป็นบรรทัดตามแกน y แล้วรวมชิ้นข้อความที่ติดกัน (ช่องว่าง > 1.5pt = คำใหม่)
        $lines = [];
        foreach ($items as $it) {
            if (!is_array($it) || count($it) < 4 || !is_string($it[0])) {
                continue;
            }
            $t = att_norm_text($it[0]);
            if (trim($t) === '') {
                continue;
            }
            $y = (float)$it[2];
            $key = null;
            foreach (array_keys($lines) as $ly) {
                if (abs($ly - $y) <= 2.5) {
                    $key = $ly;
                    break;
                }
            }
            $lines[$key ?? (string)$y][] = [(float)$it[1], $t, (float)$it[1] + (float)$it[3]];
        }
        ksort($lines, SORT_NUMERIC);
        $segLines = [];
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
            foreach ($segs as &$s) {
                $s['t'] = trim($s['t']);
                $s['c'] = ($s['x'] + $s['end']) / 2;
            }
            unset($s);
            $segLines[] = array_values(array_filter($segs, fn($s) => $s['t'] !== ''));
        }
        // หัวรายงาน: ชื่อ (บรรทัดถัดจาก "รายงานข้อมูลเวลาปฏิบัติราชการ") · ตำแหน่ง · เวลาที่พิมพ์ · หัวคอลัมน์
        $cols = [];
        $name = null;
        $position = '';
        foreach ($segLines as $li => $segs) {
            $text = implode(' ', array_column($segs, 't'));
            if (str_contains(att_name_key($text), att_name_key('รายงานข้อมูลเวลาปฏิบัติราชการ')) && isset($segLines[$li + 1])) {
                $nameSegs = array_filter($segLines[$li + 1], fn($s) => !in_array($s['t'], ['QR', 'Code'], true));
                $name = trim(implode(' ', array_column($nameSegs, 't')));
            }
            if (str_starts_with(att_name_key($text), att_name_key('ตำแหน่ง:'))) {
                $position = trim(preg_replace('/^ตำแหน่ง:\s*/u', '', $text));
            }
            foreach ($segs as $s) {
                if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $s['t'])) {
                    $res['report_at'] = max((string)$res['report_at'], strlen($s['t']) === 16 ? $s['t'] . ':00' : $s['t']);
                }
                $k = att_name_key($s['t']);
                $col = match (true) {
                    str_contains($k, att_name_key('รอบเวลา')) => 'shift',
                    $k === att_name_key('เวลาเข้า') => 'in',
                    $k === att_name_key('เวลาออก') => 'out',
                    $k === att_name_key('สาย') => 'late',
                    str_contains($k, att_name_key('รับรองการมา')) => 'certify',
                    $k === att_name_key('ลา') => 'leave',
                    str_contains($k, att_name_key('วันเช้า')) => 'am',
                    str_contains($k, att_name_key('วันบ่าย')) => 'pm',
                    str_contains($k, att_name_key('รายละเอียด')) => 'detail',
                    default => null,
                };
                if ($col && !isset($cols[$col])) {
                    $cols[$col] = $s['c'];
                }
            }
        }
        if ($name !== null) {
            $res['people'][] = ['name' => $name, 'position' => $position, 'rows' => []];
            $cur = count($res['people']) - 1;
        }
        if ($cur === null) {
            $res['errors'][] = 'หน้า ' . ($pi + 1) . ': ไม่พบหัวรายงาน "รายงานข้อมูลเวลาปฏิบัติราชการ" และชื่อผู้ปฏิบัติงาน';
            continue;
        }
        if (count($cols) < 4) {
            if ($name !== null) {
                $res['errors'][] = 'หน้า ' . ($pi + 1) . ': ไม่พบหัวตาราง (เวลาเข้า เวลาออก สาย ลา ...)';
            }
            continue;
        }
        // แถวข้อมูล: ขึ้นต้นด้วยวันที่ d/m/yyyy (พ.ศ.) · ค่าในแถวเข้าคอลัมน์ที่หัวคอลัมน์ใกล้ที่สุด
        foreach ($segLines as $segs) {
            if (!$segs || !preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $segs[0]['t'], $m)) {
                continue;
            }
            $year = (int)$m[3] > 2400 ? (int)$m[3] - 543 : (int)$m[3];
            if (!checkdate((int)$m[2], (int)$m[1], $year)) {
                continue;
            }
            $date = sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]);
            $row = ['work_date' => $date, 'holiday' => 0, 'shift' => '', 'time_in' => null, 'time_out' => null, 'late' => '', 'certify' => '',
                    'leave_code' => '', 'absent_am' => 0, 'absent_pm' => 0, 'detail' => ''];
            foreach (array_slice($segs, 1) as $s) {
                if (str_contains(att_name_key($s['t']), att_name_key('วันหยุด'))) {
                    $row['holiday'] = 1;
                    $row['shift'] = mb_substr($s['t'], 0, 30);
                    continue;
                }
                $best = null;
                $dist = PHP_FLOAT_MAX;
                foreach ($cols as $col => $cx) {
                    if (abs($cx - $s['c']) < $dist) {
                        $dist = abs($cx - $s['c']);
                        $best = $col;
                    }
                }
                $v = mb_substr($s['t'], 0, 255);
                match ($best) {
                    'shift' => $row['shift'] = mb_substr($v, 0, 30),
                    'in' => $row['time_in'] = att_time($v),
                    'out' => $row['time_out'] = att_time($v),
                    'late' => $row['late'] = mb_substr($v, 0, 20),
                    'certify' => $row['certify'] = mb_substr($v, 0, 20),
                    'leave' => $row['leave_code'] = mb_substr($v, 0, 20),
                    'am' => $row['absent_am'] = $v === 'ข' ? 1 : $row['absent_am'],
                    'pm' => $row['absent_pm'] = $v === 'ข' ? 1 : $row['absent_pm'],
                    default => $row['detail'] = trim($row['detail'] . ' ' . $v),
                };
            }
            $res['people'][$cur]['rows'][$date] = $row;
        }
    }
    if (!$res['people'] && !$res['errors']) {
        $res['errors'][] = 'ไม่พบข้อมูลเวลาปฏิบัติราชการในไฟล์ — ตรวจว่าเป็นไฟล์ PDF ที่พิมพ์จากระบบลงเวลา (ไม่ใช่ภาพสแกน)';
    }
    return $res;
}

/** จับคู่ชื่อในรายงาน ("นางเยาวภา มณีฉาย สถิติจังหวัดขอนแก่น") กับบัญชีผู้ใช้ — เลือกชื่อที่ยาวที่สุดที่ตรงส่วนต้น */
function att_match_user(string $name, array $users): ?int
{
    $key = att_name_key($name);
    $best = null;
    $len = 0;
    foreach ($users as $u) {
        $k = att_name_key((string)$u['full_name']);
        if ($k !== '' && str_starts_with($key, $k) && mb_strlen($k) > $len) {
            $best = (int)$u['id'];
            $len = mb_strlen($k);
        }
    }
    return $best;
}

/** กิจกรรมไปราชการในเดือน [user_id][day] => ชื่อกิจกรรม (สถานที่ระบุและไม่ใช่ Zoom / ศาลากลาง / ห้องประชุมสำนักงาน) */
function att_trip_map(int $y, int $m): array
{
    $from = sprintf('%04d-%02d-01', $y, $m);
    $to = date('Y-m-t', strtotime($from));
    $st = db()->prepare("SELECT eu.user_id, e.event_date, COALESCE(e.end_date, e.event_date) AS end_date, e.title, e.location
                         FROM cal_events e JOIN cal_event_users eu ON eu.event_id = e.id
                         WHERE e.kind = 'event' AND e.event_date <= ? AND COALESCE(e.end_date, e.event_date) >= ?");
    $st->execute([$to, $from]);
    $out = [];
    foreach ($st as $r) {
        $loc = mb_strtolower(preg_replace('/\s+/u', '', (string)$r['location']));
        if ($loc === '') {
            continue;
        }
        foreach (ATT_LOCAL_PLACES as $p) {
            if (str_contains($loc, mb_strtolower(preg_replace('/\s+/u', '', $p)))) {
                continue 2;
            }
        }
        for ($d = max($r['event_date'], $from); $d <= min($r['end_date'], $to); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $out[(int)$r['user_id']][(int)substr($d, 8, 2)] = $r['title'] . ' (' . $r['location'] . ')';
        }
    }
    return $out;
}

/** เวลาปฏิบัติราชการของเดือน [user_id][day] => row */
function att_month(int $y, int $m): array
{
    $from = sprintf('%04d-%02d-01', $y, $m);
    $st = db()->prepare('SELECT * FROM cal_attendance WHERE work_date BETWEEN ? AND ?');
    $st->execute([$from, date('Y-m-t', strtotime($from))]);
    $out = [];
    foreach ($st as $r) {
        $out[(int)$r['user_id']][(int)substr($r['work_date'], 8, 2)] = $r;
    }
    return $out;
}

/**
 * รหัสที่แสดงในแผน: null = ไม่แสดง · ['code', 'cls', 'title', 'certifiable' => ผู้ดูแลแก้เป็น ล ได้]
 * $hasWork = มีรหัสโครงการในแผนวันนั้น · $trip = ชื่อกิจกรรมไปราชการ (หรือ null)
 */
function att_status(?array $a, bool $hasWork, ?string $trip): ?array
{
    if (!$a || $a['holiday']) {
        return null;
    }
    if ($a['report_at'] && $a['work_date'] >= substr($a['report_at'], 0, 10)) {
        return null;                                  // วันที่พิมพ์รายงานและหลังจากนั้น ยังไม่ตัดสิน
    }
    $in = $a['time_in'] ? substr($a['time_in'], 0, 5) : null;
    $out = $a['time_out'] ? substr($a['time_out'], 0, 5) : null;
    $times = 'เข้า ' . ($in ?? '-') . ' · ออก ' . ($out ?? '-');
    if ($a['override_code'] === 'ล') {
        return ['code' => 'ล', 'cls' => 'att-cert', 'title' => 'รับรองการมาปฏิบัติราชการ (แก้จากขาดราชการ)'];
    }
    $leave = trim((string)$a['leave_code']);
    if ($leave !== '') {
        $c = mb_substr($leave, 0, 1);
        return ['code' => $c, 'cls' => $c === 'ร' ? 'att-trip' : 'att-leave', 'title' => (ATT_LEAVE_NAMES[$c] ?? 'ลา') . ' (' . $leave . ')' . ($a['detail'] ? ' ' . $a['detail'] : '')];
    }
    if (trim((string)$a['certify']) !== '') {
        return ['code' => 'ล', 'cls' => 'att-cert', 'title' => 'รับรองการมาปฏิบัติราชการ ' . $a['certify'] . ' · ' . $times];
    }
    if ($in && $out) {
        if ($in >= '12:00' && $in <= '13:00' && $out >= ATT_OUT_LIMIT) {
            return ['code' => 'ลาเช้า', 'cls' => 'att-half', 'title' => 'ลาครึ่งวันเช้า · ' . $times];
        }
        if ($in <= ATT_IN_LIMIT && $out >= '12:00' && $out <= '13:00') {
            return ['code' => 'ลาบ่าย', 'cls' => 'att-half', 'title' => 'ลาครึ่งวันบ่าย · ' . $times];
        }
        if (trim((string)$a['late']) !== '') {
            return ['code' => 'ส', 'cls' => 'att-late', 'title' => 'สาย ' . $a['late'] . ' · ' . $times];
        }
        return null;                                  // ปกติ — ไม่แสดง
    }
    if ($in || $out) {
        return ['code' => 'ล', 'cls' => 'att-pending', 'title' => 'ลงเวลาไม่ครบ (' . $times . ') — ต้องรับรองการมาปฏิบัติราชการ'];
    }
    if ($hasWork) {
        return null;                                  // ปฏิบัติงานตามแผน (แสดงรหัสโครงการอยู่แล้ว)
    }
    if ($trip !== null) {
        return ['code' => 'ร', 'cls' => 'att-trip', 'title' => 'ไปราชการ: ' . $trip];
    }
    return ['code' => 'ข', 'cls' => 'att-absent', 'title' => 'ขาดราชการ (ไม่มีการลงเวลา ไม่มีงานในแผน และไม่มีกิจกรรมไปราชการ)', 'certifiable' => true];
}
