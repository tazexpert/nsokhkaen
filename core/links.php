<?php
/**
 * ลิงก์และเครื่องมือ (หน้าแรก) — ตาราง core_links จัดการที่ admin/links.php (ผู้ดูแลระบบกลาง)
 * url: http(s)://… (เว็บภายนอก) หรือ path ภายในระบบ เช่น modules/digitalcc/public/
 * ยังไม่ได้รัน migration → ใช้ลิงก์เริ่มต้นชุดเดิม
 */

/** ไอคอนที่เลือกได้ (Bootstrap Icons) => คำอธิบาย */
const LINK_ICONS = [
    'bi-link-45deg' => 'ลิงก์', 'bi-globe' => 'เว็บไซต์', 'bi-globe2' => 'ลูกโลก', 'bi-building' => 'หน่วยงาน',
    'bi-envelope-paper' => 'สารบรรณ', 'bi-envelope' => 'อีเมล', 'bi-cloud' => 'คลาวด์', 'bi-folder2-open' => 'โฟลเดอร์',
    'bi-file-earmark-text' => 'เอกสาร', 'bi-file-earmark-spreadsheet' => 'ตาราง', 'bi-bar-chart' => 'สถิติ', 'bi-graph-up' => 'กราฟ',
    'bi-calendar3' => 'ปฏิทิน', 'bi-people' => 'บุคลากร', 'bi-person-badge' => 'บุคคล', 'bi-house' => 'หน้าหลัก',
    'bi-map' => 'แผนที่', 'bi-geo-alt' => 'พิกัด', 'bi-camera-video' => 'ประชุมออนไลน์', 'bi-chat-dots' => 'แชต',
    'bi-youtube' => 'YouTube', 'bi-facebook' => 'Facebook', 'bi-line' => 'LINE', 'bi-google' => 'Google',
    'bi-microsoft' => 'Microsoft', 'bi-book' => 'คู่มือ', 'bi-mortarboard' => 'อบรม', 'bi-tools' => 'เครื่องมือ',
    'bi-database' => 'ฐานข้อมูล', 'bi-shield-check' => 'ความปลอดภัย', 'bi-receipt' => 'การเงิน', 'bi-cart' => 'จัดซื้อ',
];

const LINK_DEFAULTS = [
    ['label' => 'สำนักงานสถิติจังหวัดขอนแก่น', 'url' => 'https://khonkaen.nso.go.th/', 'icon' => 'bi-building'],
    ['label' => 'ระบบสารบรรณสำนักงานสถิติจังหวัดขอนแก่น', 'url' => 'https://nso.eoffice.go.th/', 'icon' => 'bi-envelope-paper'],
    ['label' => 'ระบบสารบรรณจังหวัดขอนแก่น', 'url' => 'https://khonkaen.eoffice.go.th/', 'icon' => 'bi-envelope-paper'],
    ['label' => 'Drive Province (ขอนแก่น)', 'url' => 'https://driveprovince.nso.go.th/', 'icon' => 'bi-cloud'],
    ['label' => 'เว็บไซต์ศูนย์ดิจิทัลชุมชน', 'url' => 'modules/digitalcc/public/', 'icon' => 'bi-globe'],
];

/** ลิงก์ที่แสดงในหน้าแรก ($member = login แล้ว → เห็นลิงก์ "เฉพาะผู้ใช้ในระบบ" ด้วย) */
function home_links(bool $member): array
{
    try {
        $sql = 'SELECT label, url, icon FROM core_links WHERE is_active = 1' . ($member ? '' : ' AND members_only = 0') . ' ORDER BY sort_order, id';
        return db()->query($sql)->fetchAll();
    } catch (PDOException $e) {        // ยังไม่ได้รัน migration 20261112_core_links
        return LINK_DEFAULTS;
    }
}

/** URL สำหรับใส่ใน href: เว็บภายนอกใช้ตามเดิม · path ภายในระบบต่อจาก BASE_URL */
function link_href(string $url): string
{
    return preg_match('~^https?://~i', $url) ? $url : url(ltrim($url, '/'));
}

/** ตรวจ URL ที่ผู้ดูแลกรอก — คืนข้อความผิดพลาด หรือ null ถ้าถูกต้อง */
function link_url_error(string $url): ?string
{
    if ($url === '') {
        return 'กรุณาระบุลิงก์';
    }
    if (preg_match('~^https?://~i', $url)) {
        return filter_var($url, FILTER_VALIDATE_URL) ? null : 'รูปแบบลิงก์ไม่ถูกต้อง';
    }
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) || str_starts_with($url, '//') || str_contains($url, '..')
        || !preg_match('@^[A-Za-z0-9/_.~%?=&#+-]+$@', $url)) {          // path ภายใน: เฉพาะอักขระที่ใช้ใน URL ได้
        return 'ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https:// หรือเป็น path ภายในระบบ (เช่น modules/digitalcc/public/)';
    }
    return null;
}
