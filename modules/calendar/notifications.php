<?php
/**
 * การแจ้งเตือนของระบบปฏิทิน (เรียกจาก core/notifications.php)
 *   ผู้รับผิดชอบ: ได้รับมอบหมาย/กิจกรรมมีการเปลี่ยนแปลง (14 วันล่าสุด) และเตือนกิจกรรมวันนี้/พรุ่งนี้
 *   เวลาปฏิบัติราชการ: ขาดราชการ / ลงเวลาไม่ครบ ใน 45 วันล่าสุด → เตือนเจ้าของให้ทำใบรับรองการมาปฏิบัติราชการ
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
        $href = $base . 'event_view?id=' . $e['id'];
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
    // ขาดราชการ / ลงเวลาไม่ครบ ใน 45 วันล่าสุด — เตือนเจ้าของให้ทำใบรับรองการมาปฏิบัติราชการ
    try {
        require_once __DIR__ . '/att_lib.php';
        $items = att_user_statuses((int)$user['id'], date('Y-m-d', strtotime('-45 days')), $today);
        $n = 0;
        foreach (array_reverse($items, true) as $d => $a) {
            if (!in_array($a['cls'], ['att-absent', 'att-pending'], true) || ++$n > 10) {
                continue;
            }
            $r = $a['row'];
            $absent = $a['cls'] === 'att-absent';
            $out[] = ['title' => ($absent ? 'ขาดราชการ' : 'ลงเวลาไม่ครบ') . ' วันที่ ' . thai_date($d), 'icon' => 'bi-clock-history', 'color' => 'danger',
                      'text' => ($absent ? 'ไม่ได้ลงเวลา' : 'เข้า ' . ($r['time_in'] ? substr($r['time_in'], 0, 5) : '-') . ' · ออก ' . ($r['time_out'] ? substr($r['time_out'], 0, 5) : '-'))
                              . ' — กรุณาทำใบรับรองการมาปฏิบัติราชการ',
                      'href' => $base . 'att_view?date=' . $d, 'time' => $r['imported_at'], 'sticky' => true];
        }
    } catch (PDOException $e) {
        // ยังไม่ได้รัน migration cal_attendance
    }
    return $out;
};
