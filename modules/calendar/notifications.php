<?php
/**
 * การแจ้งเตือนของระบบปฏิทิน (เรียกจาก core/notifications.php)
 *   ผู้รับผิดชอบ: ได้รับมอบหมาย/กิจกรรมมีการเปลี่ยนแปลง (14 วันล่าสุด) และเตือนกิจกรรมวันนี้/พรุ่งนี้
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
        $when = cal_range_text($e) . ' · ' . cal_time_text($e) . ($e['location'] ? ' · ' . $e['location'] : '');
        $soon = $e['event_date'] <= $tomorrow;
        if ($soon) {
            $isToday = $e['event_date'] <= $today;
            $out[] = ['title' => ($isToday ? 'กิจกรรมวันนี้: ' : 'กิจกรรมพรุ่งนี้: ') . $e['title'], 'icon' => 'bi-alarm',
                      'color' => $isToday ? 'danger' : 'warning', 'text' => $when, 'href' => $href,
                      'time' => max($e['assigned_at'], date('Y-m-d 06:00:00'))];
        } elseif (strtotime($e['assigned_at']) >= strtotime('-14 days')) {
            $out[] = ['title' => 'ได้รับมอบหมาย: ' . $e['title'], 'icon' => 'bi-calendar-check', 'color' => 'primary',
                      'text' => $when, 'href' => $href, 'time' => $e['assigned_at']];
        }
    }
    return $out;
};
