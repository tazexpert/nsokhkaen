<?php
/**
 * การแจ้งเตือนของระบบปฏิทิน (เรียกจาก core/notifications.php)
 *   ผู้รับผิดชอบ: ได้รับมอบหมาย/กิจกรรมมีการเปลี่ยนแปลง (14 วันล่าสุด) และเตือนกิจกรรมวันนี้/พรุ่งนี้
 *   เวลาปฏิบัติราชการ: ลงเวลาไม่ครบ (มีเข้าหรือออกอย่างเดียว) ใน 45 วันล่าสุด → ต้องรับรองการมาปฏิบัติราชการ
 */
return function (array $user): array {
    require_once __DIR__ . '/lib.php';
    $base = module_url('calendar');
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $out = [];
    $st = db()->prepare("SELECT e.*, eu.assigned_at FROM cal_event_users eu JOIN cal_events e ON e.id = eu.event_id
        WHERE eu.user_id = ? AND COALESCE(e.end_date, e.event_date) >= ?
          AND (eu.assigned_at >= NOW() - INTERVAL 14 DAY OR e.event_date <= ?)
        ORDER BY e.event_date, e.start_time LIMIT 30");
    $st->execute([$user['id'], $today, $tomorrow]);
    foreach ($st as $e) {
        $href = $base . 'event_view.php?id=' . $e['id'];
        $when = cal_is_shift($e) ? cal_range_text($e) : cal_range_text($e) . ' · ' . cal_time_text($e) . ($e['location'] ? ' · ' . $e['location'] : '');
        $soon = $e['event_date'] <= $tomorrow;
        if ($soon) {
            $isToday = $e['event_date'] <= $today;
            $out[] = ['title' => ($isToday ? 'กิจกรรมวันนี้: ' : 'กิจกรรมพรุ่งนี้: ') . $e['title'], 'icon' => 'bi-alarm',
                      'color' => $isToday ? 'danger' : 'warning', 'text' => $when, 'href' => $href,
                      'time' => max($e['assigned_at'], date('Y-m-d 06:00:00')), 'sticky' => true];
        } elseif (strtotime($e['assigned_at']) >= strtotime('-14 days')) {
            $out[] = ['title' => 'ได้รับมอบหมาย: ' . $e['title'], 'icon' => 'bi-calendar-check', 'color' => 'primary',
                      'text' => $when, 'href' => $href, 'time' => $e['assigned_at']];
        }
    }
    // ลงเวลาไม่ครบ (ยังไม่มีการลา / รับรอง) — แจ้งจนกว่าจะรับรองในระบบลงเวลาแล้วนำเข้าไฟล์ใหม่
    try {
        $st = db()->prepare("SELECT work_date, time_in, time_out, imported_at FROM cal_attendance
            WHERE user_id = ? AND holiday = 0 AND (time_in IS NULL) <> (time_out IS NULL) AND leave_code = '' AND certify = '' AND override_code = ''
              AND work_date < DATE(report_at) AND work_date >= CURDATE() - INTERVAL 45 DAY ORDER BY work_date DESC LIMIT 10");
        $st->execute([$user['id']]);
        foreach ($st as $a) {
            $out[] = ['title' => 'ลงเวลาไม่ครบ วันที่ ' . thai_date($a['work_date']), 'icon' => 'bi-clock-history', 'color' => 'danger',
                      'text' => 'เข้า ' . ($a['time_in'] ? substr($a['time_in'], 0, 5) : '-') . ' · ออก ' . ($a['time_out'] ? substr($a['time_out'], 0, 5) : '-')
                              . ' — กรุณายื่นรับรองการมาปฏิบัติราชการ',
                      'href' => $base . 'plan.php?m=' . substr($a['work_date'], 0, 7), 'time' => $a['imported_at'], 'sticky' => true];
        }
    } catch (PDOException $e) {
        // ยังไม่ได้รัน migration cal_attendance
    }
    return $out;
};
