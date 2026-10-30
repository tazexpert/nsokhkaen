<?php
/**
 * การแจ้งเตือน (กระดิ่งบนเมนูด้านบน)
 *
 * แต่ละระบบเพิ่มการแจ้งเตือนของตัวเองได้ด้วยไฟล์ modules/<key>/notifications.php
 * ซึ่ง return ฟังก์ชัน fn(array $user): array คืนรายการแจ้งเตือน แต่ละรายการ:
 *   'title' => หัวข้อ, 'text' => รายละเอียด, 'href' => ลิงก์, 'time' => 'Y-m-d H:i:s'
 *   'sticky' => true สำหรับเรื่องที่ยังรอดำเนินการ (รออนุมัติ เกินกำหนด ฯลฯ) — ไม่ถูกซ่อนเมื่อผู้ดูแล "ล้างการแจ้งเตือน"
 *   'icon'  => ไอคอน Bootstrap เช่น 'bi-box-seam', 'color' => success|warning|danger|primary|info|secondary
 * ข้อความใหม่ในกล่องจดหมาย (core/messages.php) รวมอยู่ด้วยเสมอ
 * ระบบกลางเรียกเฉพาะระบบที่ผู้ใช้มีสิทธิ์ และนับ "ยังไม่อ่าน" จากเวลาที่ใหม่กว่า core_users.notif_seen_at
 */

function user_notifications(?array $user = null): array
{
    static $cache = [];
    $user = $user ?? current_user();
    if (!$user || $user['via_pin']) {          // หน้า PIN/สแกนบนมือถือ ไม่แสดงการแจ้งเตือน
        return [];
    }
    if (isset($cache[$user['id']])) {
        return $cache[$user['id']];
    }
    $items = [];
    try {                                      // ระบบกลาง: ข้อความใหม่ในกล่องจดหมาย
        foreach (msg_notifications($user) as $n) {
            $items[] = $n + ['icon' => 'bi-bell', 'color' => 'primary', 'text' => '', 'href' => '#'];
        }
    } catch (Throwable $e) {
        error_log('notifications messages: ' . $e->getMessage());
    }
    foreach (accessible_modules($user) as $m) {
        $file = APP_ROOT . '/modules/' . $m['key'] . '/notifications.php';
        if (!is_file($file)) {
            continue;
        }
        $fn = require $file;
        if (!is_callable($fn)) {
            continue;
        }
        try {
            foreach ($fn($user) as $n) {
                $items[] = $n + ['module' => $m['key'], 'icon' => 'bi-bell', 'color' => 'primary', 'text' => '', 'href' => '#'];
            }
        } catch (Throwable $e) {
            error_log('notifications ' . $m['key'] . ': ' . $e->getMessage());
        }
    }
    // ผู้ดูแลล้างการแจ้งเตือน (ตั้งค่าระบบ): ซ่อนรายการก่อนเวลานั้น ยกเว้นเรื่องที่ยังรอดำเนินการ
    if ($cut = setting('notif_reset_at')) {
        $items = array_values(array_filter($items, fn($n) => !empty($n['sticky']) || (string)$n['time'] > $cut));
    }
    usort($items, fn($a, $b) => strcmp($b['time'], $a['time']));
    return $cache[$user['id']] = array_slice($items, 0, 40);
}

/** จำนวนรายการที่ใหม่กว่าการเปิดดูครั้งล่าสุด */
function unread_notification_count(array $items, ?array $user = null): int
{
    $user = $user ?? current_user();
    $seen = $user['notif_seen_at'] ?? null;
    return count(array_filter($items, fn($n) => !$seen || $n['time'] > $seen));
}

/** เวลาแบบอ่านง่าย เช่น "5 นาทีที่แล้ว", "เมื่อวาน", "3 ต.ค. 69" */
function time_ago(string $datetime): string
{
    $t = strtotime($datetime);
    $d = time() - $t;
    if ($d < 0) {
        return thai_date(date('Y-m-d', $t));
    }
    if ($d < 60) {
        return 'เมื่อสักครู่';
    }
    if ($d < 3600) {
        return floor($d / 60) . ' นาทีที่แล้ว';
    }
    if ($d < 86400) {
        return floor($d / 3600) . ' ชั่วโมงที่แล้ว';
    }
    if ($d < 172800) {
        return 'เมื่อวาน';
    }
    if ($d < 7 * 86400) {
        return floor($d / 86400) . ' วันที่แล้ว';
    }
    return thai_date(date('Y-m-d', $t));
}
