<?php
/**
 * iCalendar "งานของฉัน"
 *   ?t=<รหัสลับ>   ลิงก์สมัครรับสำหรับ Google Calendar / ปฏิทิน iOS (ไม่ต้องเข้าสู่ระบบ)
 *   ?download=1    ดาวน์โหลดไฟล์ .ics ของผู้ที่เข้าสู่ระบบอยู่ (นำเข้าครั้งเดียว)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ical_lib.php';

$token = (string)($_GET['t'] ?? '');
if ($token !== '') {
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        http_response_code(404);
        exit('Not found');
    }
    $st = db()->prepare('SELECT u.id, u.full_name FROM cal_ical_tokens t JOIN core_users u ON u.id = t.user_id WHERE t.token = ? AND u.is_active = 1');
    $st->execute([$token]);
    $u = $st->fetch();
    if (!$u) {
        http_response_code(404);
        exit('Not found');
    }
    db()->prepare('UPDATE cal_ical_tokens SET last_used_at = NOW() WHERE user_id = ?')->execute([$u['id']]);
    cal_ical_send(cal_ical_build(cal_ical_events((int)$u['id']), 'งานของฉัน — ปฏิทินการปฏิบัติงาน'), false);
}

$me = require_module(CAL_MODULE);
cal_ical_send(cal_ical_build(cal_ical_events((int)$me['id']), 'งานของฉัน — ปฏิทินการปฏิบัติงาน'), true);
