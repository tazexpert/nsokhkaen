<?php
/**
 * การแจ้งเตือนของระบบตัวชี้วัด (เรียกจาก core/notifications.php)
 *   ผู้ปฏิบัติ: กิจกรรมที่ยังไม่ส่งและครบกำหนดภายใน 15 วัน (KPI_WARN_DAYS) หรือเกินกำหนดไม่เกิน 30 วัน
 *   ผู้ควบคุมตัวชี้วัด (ผู้ควบคุม/ผู้สร้างตัวชี้วัด): กิจกรรมที่ส่งหลักฐานแล้ว รอตรวจ
 */
return function (array $user): array {
    require_once __DIR__ . '/lib.php';
    $base = module_url('kpi');
    $uid = (int)$user['id'];
    $today = date('Y-m-d');
    $out = [];
    try {
        // ผู้ปฏิบัติของกิจกรรม = ที่ระบุรายกิจกรรม หรือ (ไม่ระบุ) ผู้ปฏิบัติของตัวชี้วัด
        $st = db()->prepare("SELECT a.id, a.indicator_id, a.title, a.due_date, i.name AS ind_name, i.code
            FROM kpi_activities a JOIN kpi_indicators i ON i.id = a.indicator_id
            WHERE a.status = 'pending' AND a.due_date IS NOT NULL AND a.due_date BETWEEN ? AND ?
              AND (EXISTS (SELECT 1 FROM kpi_activity_users au WHERE au.activity_id = a.id AND au.user_id = ?)
                   OR (NOT EXISTS (SELECT 1 FROM kpi_activity_users au WHERE au.activity_id = a.id)
                       AND EXISTS (SELECT 1 FROM kpi_indicator_users iu WHERE iu.indicator_id = a.indicator_id AND iu.user_id = ?)))
            ORDER BY a.due_date LIMIT 20");
        $st->execute([date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('+' . KPI_WARN_DAYS . ' days')), $uid, $uid]);
        foreach ($st as $a) {
            $days = (int)floor((strtotime($a['due_date']) - strtotime($today)) / 86400);
            $out[] = ['title' => ($days < 0 ? 'ตัวชี้วัดเกินกำหนดส่ง ' . -$days . ' วัน: ' : ($days === 0 ? 'ตัวชี้วัดครบกำหนดส่งวันนี้: ' : 'ตัวชี้วัดครบกำหนดส่งในอีก ' . $days . ' วัน: '))
                          . mb_strimwidth(preg_replace('/\s+/u', ' ', $a['title']), 0, 80, '…'),
                      'icon' => 'bi-bullseye', 'color' => $days < 0 ? 'danger' : ($days <= 3 ? 'warning' : 'info'),
                      'text' => 'ส่งภายใน ' . thai_date($a['due_date']) . ' · ตัวชี้วัด' . ($a['code'] ? 'ที่ ' . $a['code'] . ' ' : ' ') . mb_strimwidth($a['ind_name'], 0, 70, '…'),
                      'href' => $base . 'indicator?id=' . $a['indicator_id'] . '#act-' . $a['id'],
                      'time' => max(date('Y-m-d 06:00:00', strtotime($a['due_date'] . ' -' . KPI_WARN_DAYS . ' days')), date('Y-m-d 00:00:00', strtotime('-30 days'))),
                      'sticky' => true];
        }
        // ผู้ควบคุมตัวชี้วัด: ส่งหลักฐานแล้ว รอตรวจ
        $st = db()->prepare("SELECT a.id, a.indicator_id, a.title, a.submitted_at, i.name AS ind_name, i.code
            FROM kpi_activities a JOIN kpi_indicators i ON i.id = a.indicator_id
            WHERE a.status = 'submitted' AND (i.controller_id = ? OR (i.controller_id IS NULL AND i.created_by = ?))
            ORDER BY a.submitted_at DESC LIMIT 20");
        $st->execute([$uid, $uid]);
        foreach ($st as $a) {
            $out[] = ['title' => 'ส่งหลักฐานตัวชี้วัดแล้ว รอตรวจ: ' . mb_strimwidth(preg_replace('/\s+/u', ' ', $a['title']), 0, 80, '…'),
                      'icon' => 'bi-clipboard-check', 'color' => 'success',
                      'text' => 'ตัวชี้วัด' . ($a['code'] ? 'ที่ ' . $a['code'] . ' ' : ' ') . mb_strimwidth($a['ind_name'], 0, 70, '…'),
                      'href' => $base . 'indicator?id=' . $a['indicator_id'] . '#act-' . $a['id'], 'time' => $a['submitted_at'] ?: date('Y-m-d H:i:s')];
        }
    } catch (PDOException $e) {
        // ยังไม่ได้รัน migration ระบบตัวชี้วัด
    }
    return $out;
};
