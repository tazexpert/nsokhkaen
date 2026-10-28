<?php
/**
 * การแจ้งเตือนของระบบสลิปเงินเดือน (เรียกจาก core/notifications.php)
 *   ผู้อนุมัติ: สลิปรออนุมัติ (แยกตามเดือน) · พนักงาน: สลิปเดือนใหม่ที่อนุมัติแล้ว (30 วันล่าสุด)
 */
return function (array $user): array {
    require_once __DIR__ . '/lib.php';
    $pdo = db();
    $base = module_url('payslip');
    $out = [];
    if (user_can('payslip', 'approver', $user)) {
        foreach ($pdo->query("SELECT year, month, COUNT(*) n, MAX(updated_at) t FROM pay_slips WHERE status = 'pending'
            GROUP BY year, month ORDER BY year DESC, month DESC LIMIT 6") as $p) {
            $out[] = ['title' => 'สลิปเงินเดือนรออนุมัติ', 'icon' => 'bi-receipt-cutoff', 'color' => 'warning',
                      'text' => pay_period((int)$p['year'], (int)$p['month']) . ' · ' . $p['n'] . ' รายการ',
                      'href' => $base . 'slips.php?' . http_build_query(['status' => 'pending', 'year' => $p['year'], 'month' => $p['month']]),
                      'time' => $p['t']];
        }
    }
    $st = $pdo->prepare("SELECT s.id, s.year, s.month, s.approved_at FROM pay_slips s JOIN pay_employees e ON e.id = s.employee_id
        WHERE e.user_id = ? AND e.is_active = 1 AND s.status = 'approved' AND s.approved_at >= NOW() - INTERVAL 30 DAY
        ORDER BY s.year DESC, s.month DESC LIMIT 1");   // เฉพาะเดือนล่าสุด
    $st->execute([$user['id']]);
    foreach ($st as $s) {
        $out[] = ['title' => 'สลิปเงินเดือนพร้อมแล้ว', 'icon' => 'bi-receipt', 'color' => 'success',
                  'text' => 'ประจำเดือน ' . pay_period((int)$s['year'], (int)$s['month']),
                  'href' => $base . 'index.php?p=' . $s['year'] . '-' . $s['month'], 'time' => $s['approved_at']];
    }
    return $out;
};
