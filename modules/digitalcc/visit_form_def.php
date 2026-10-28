<?php
/**
 * โครงสร้างแบบฟอร์มการตรวจเยี่ยมศูนย์ดิจิทัลชุมชน จังหวัดขอนแก่น
 * ใช้ร่วมกันทั้งหน้ากรอกข้อมูล (admin/visit_form.php) และหน้าพิมพ์ (admin/visit_view.php)
 */

// สถานที่ตั้งอยู่ใน  (value = ค่าที่เก็บใน centers.center_type)
const VISIT_LOCATION_TYPES = [
    'สถานศึกษา'               => 'โรงเรียน',
    'องค์กรปกครองส่วนท้องถิ่น' => 'อบต./เทศบาล',
    'สกร.'                    => 'สกร.',
    'วัด/ชุมชน'               => 'วัด/ชุมชน',
];
// ค่าเดิมในระบบที่ถือว่าเป็นตัวเลือกเดียวกัน
const VISIT_TYPE_ALIASES = ['กศน.' => 'สกร.', 'วัด' => 'วัด/ชุมชน', 'ชุมชน/หมู่บ้าน' => 'วัด/ชุมชน'];

const VISIT_PC_STATUS = ['ดีมาก', 'พอใช้ (ช้า/ค้างบ้าง)', 'ปรับปรุงเร่งด่วน'];

// อุปกรณ์: key => [ชื่อ, ตัวเลือก]
const VISIT_DEVICES = [
    '1.3 อุปกรณ์ต่อพ่วง' => [
        'printer' => ['เครื่องพิมพ์', ['พร้อมใช้', 'ชำรุด/หมึกหมด', 'ไม่มี']],
        'ups'     => ['เครื่องสำรองไฟ (UPS)', ['พร้อมใช้', 'แบตเตอรี่เสื่อม/ชำรุด', 'ไม่มี']],
    ],
    '1.4 อุปกรณ์อื่น ๆ' => [
        'tv'     => ['โทรทัศน์ LED TV/Smart Board', ['พร้อมใช้', 'ชำรุด', 'ไม่มี']],
        'camera' => ['กล้องถ่ายรูปดิจิทัล', ['พร้อมใช้', 'ชำรุด', 'ไม่มี']],
        'cctv'   => ['กล้องโทรทัศน์วงจรปิดชนิดเครือข่าย', ['พร้อมใช้', 'ชำรุด', 'ไม่มี']],
        'studio' => ['ชุดสตูดิโอ', ['พร้อมใช้', 'ชำรุด', 'ไม่มี']],
    ],
];

const VISIT_AVG_PER = ['วัน', 'สัปดาห์', 'เดือน'];

const VISIT_USER_GROUPS = [
    'student' => 'นักเรียน / นักศึกษา',
    'public'  => 'ประชาชนทั่วไป',
    'seller'  => 'พ่อค้า / แม่ค้าออนไลน์',
    'farmer'  => 'เกษตรกร / กลุ่มอาชีพ',
    'elderly' => 'ผู้สูงอายุ',
];

const VISIT_PURPOSES = [
    'study'  => 'การศึกษาค้นคว้าข้อมูล ทำรายงาน เรียนออนไลน์',
    'gov'    => 'ติดต่อหน่วยงานรัฐ แอปภาครัฐ เช็กสิทธิ',
    'sell'   => 'ขายของออนไลน์ สร้างสื่อโปรโมตสินค้า',
    'social' => 'บันเทิงและการติดต่อสื่อสาร โซเชียลมีเดีย ยูทูบ',
];

const VISIT_SAFETY = [
    'clean'    => 'ภายในศูนย์สะอาด เป็นระเบียบ แสงสว่างเพียงพอ',
    'electric' => 'ไฟฟ้า ปลั๊ก สายไฟ สาย LAN ปลอดภัยไม่ชำรุด',
    'updated'  => 'อัปเดต Windows และโปรแกรมสแกนไวรัสเป็นปัจจุบัน',
    'webblock' => 'ตั้งค่าระบบบล็อกเว็บไซต์ไม่เหมาะสม',
];

/** อ่านคำตอบส่วนที่ 1–3 จาก $_POST แล้วคืนเป็น array สำหรับเก็บ JSON (กรองเฉพาะค่าที่อนุญาต) */
function visit_data_from_post(): array
{
    $num = fn(string $k) => ($v = post($k)) === '' ? '' : (string)max(0, (float)$v);
    $pick = fn(string $k, array $allowed) => in_array(post($k), $allowed, true) ? post($k) : '';
    $flag = fn(string $k) => !empty($_POST[$k]);
    $multi = fn(string $k, array $allowed) => array_values(array_intersect((array)($_POST[$k] ?? []), array_keys($allowed)));
    $time = fn(string $k) => preg_match('/^\d{1,2}:\d{2}$/', post($k)) ? post($k) : '';

    $d = [
        'pc_total'      => $num('pc_total'),
        'pc_ok'         => $num('pc_ok'),
        'pc_broken'     => $num('pc_broken'),
        'pc_status'     => $pick('pc_status', VISIT_PC_STATUS),
        'net_status'    => $pick('net_status', ['ok', 'problem']),
        'net_down'      => $num('net_down'),
        'net_up'        => $num('net_up'),
        'net_problem'   => mb_substr(post('net_problem'), 0, 500),
        'free_wifi'     => $flag('free_wifi'),
        'sign'          => $flag('sign'),
        'schedule'      => $flag('schedule'),
        'open_time'     => $time('open_time'),
        'close_time'    => $time('close_time'),
        'logbook'       => $flag('logbook'),
        'avg_users'     => $num('avg_users'),
        'avg_per'       => $pick('avg_per', VISIT_AVG_PER),
        'groups'        => $multi('groups', VISIT_USER_GROUPS),
        'groups_other'  => mb_substr(post('groups_other'), 0, 255),
        'purposes'      => $multi('purposes', VISIT_PURPOSES),
        'purposes_other' => mb_substr(post('purposes_other'), 0, 255),
        'safety'        => $multi('safety', VISIT_SAFETY),
    ];
    foreach (VISIT_DEVICES as $items) {
        foreach ($items as $key => [, $opts]) {
            $d['dev_' . $key] = $pick('dev_' . $key, $opts);
        }
    }
    return $d;
}

/** ค่า center_type → ค่าตัวเลือกในแบบฟอร์ม (หรือ '' ถ้าเป็น "อื่น ๆ") */
function visit_type_option(string $type): string
{
    $type = VISIT_TYPE_ALIASES[$type] ?? $type;
    return isset(VISIT_LOCATION_TYPES[$type]) ? $type : '';
}

/** ข้อความสรุปสำหรับแสดงในกิจกรรมหน้าเว็บ (ไม่รวมปัญหา/ข้อเสนอแนะ) */
function visit_public_summary(array $d, string $inspector): string
{
    $lines = ['ติดตามและตรวจเยี่ยมการดำเนินงานศูนย์ดิจิทัลชุมชน'];
    if ($d['pc_total'] !== '') {
        $lines[] = "คอมพิวเตอร์ {$d['pc_total']} เครื่อง ใช้งานได้ " . ($d['pc_ok'] !== '' ? $d['pc_ok'] : '-') . ' เครื่อง';
    }
    if ($d['net_status'] === 'ok' && $d['net_down'] !== '') {
        $lines[] = "อินเทอร์เน็ต Download {$d['net_down']} / Upload " . ($d['net_up'] ?: '-') . ' Mbps';
    }
    if ($d['free_wifi']) {
        $lines[] = 'มีบริการ Free Wi-Fi ภายในศูนย์';
    }
    if ($inspector !== '') {
        $lines[] = 'ผู้ตรวจเยี่ยม: ' . $inspector;
    }
    return implode("\n", $lines);
}
