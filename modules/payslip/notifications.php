<?php
/**
 * การแจ้งเตือนของระบบสลิปเงินเดือน (เรียกจาก core/notifications.php)
 *   ผู้อนุมัติ: สลิปรออนุมัติ (แยกตามเดือน) + เรื่องแจ้งแก้ไขของตนที่ผู้จัดการสลิปปิดแล้ว (14 วัน)
 *   ผู้จัดการสลิป: เรื่องแจ้งแก้ไขจากผู้อนุมัติที่ยังค้าง
 *   พนักงาน: สลิปเดือนใหม่ที่อนุมัติแล้ว (30 วันล่าสุด)
 */
return function (array $user): array {
    require_once __DIR__ . '/lib.php';
    $pdo = db();
    $base = module_url('payslip');
    $out = [];
    if (in_array(module_role('payslip', $user), ['approver', 'admin'], true)) {
        foreach ($pdo->query("SELECT year, month, COUNT(*) n, MAX(updated_at) t FROM pay_slips WHERE status = 'pending'
            GROUP BY year, month ORDER BY year DESC, month DESC LIMIT 6") as $p) {
            $out[] = ['title' => 'สลิปเงินเดือนรออนุมัติ', 'icon' => 'bi-receipt-cutoff', 'color' => 'warning',
                      'text' => pay_period((int)$p['year'], (int)$p['month']) . ' · ' . $p['n'] . ' รายการ',
                      'href' => $base . 'slips.php?' . http_build_query(['status' => 'pending', 'year' => $p['year'], 'month' => $p['month']]),
                      'time' => $p['t'], 'sticky' => true];
        }
    }
    $role = module_role('payslip', $user);
    if (in_array($role, ['manager', 'admin'], true)) {
        foreach ($pdo->query("SELECT c.id, c.slip_id, c.message, c.changes, c.created_at, s.year, s.month, s.full_name, e.code, u.full_name AS by_name
            FROM pay_slip_comments c JOIN pay_slips s ON s.id = c.slip_id JOIN pay_employees e ON e.id = s.employee_id
            LEFT JOIN core_users u ON u.id = c.user_id WHERE c.status = 'open' ORDER BY c.id DESC LIMIT 10") as $c) {
            $n = count(json_decode((string)$c['changes'], true) ?: []);
            $out[] = ['title' => 'ผู้อนุมัติแจ้งแก้ไขสลิป', 'icon' => 'bi-chat-square-text', 'color' => 'danger',
                      'text' => $c['code'] . ' ' . $c['full_name'] . ' · ' . pay_period((int)$c['year'], (int)$c['month'])
                          . ($n ? " · เสนอแก้ $n รายการ" : '') . ($c['message'] ? ' — ' . mb_strimwidth($c['message'], 0, 60, '…') : '')
                          . ($c['by_name'] ? ' (' . $c['by_name'] . ')' : ''),
                      'href' => $base . 'slip_view.php?id=' . $c['slip_id'] . '#comments', 'time' => $c['created_at'], 'sticky' => true];
        }
    }
    if (in_array($role, ['approver', 'admin'], true)) {
        $st = $pdo->prepare("SELECT c.slip_id, c.resolve_note, c.resolved_at, s.year, s.month, s.full_name, e.code
            FROM pay_slip_comments c JOIN pay_slips s ON s.id = c.slip_id JOIN pay_employees e ON e.id = s.employee_id
            WHERE c.user_id = ? AND c.status = 'resolved' AND c.resolved_at >= NOW() - INTERVAL 14 DAY ORDER BY c.resolved_at DESC LIMIT 10");
        $st->execute([$user['id']]);
        foreach ($st as $c) {
            $out[] = ['title' => 'ผู้จัดการสลิปตอบเรื่องแจ้งแก้ไขแล้ว', 'icon' => 'bi-reply', 'color' => 'info',
                      'text' => $c['code'] . ' ' . $c['full_name'] . ' · ' . pay_period((int)$c['year'], (int)$c['month'])
                          . ($c['resolve_note'] ? ' — ' . mb_strimwidth($c['resolve_note'], 0, 60, '…') : ''),
                      'href' => $base . 'slip_view.php?id=' . $c['slip_id'] . '#comments', 'time' => $c['resolved_at']];
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
