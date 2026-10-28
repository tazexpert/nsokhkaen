<?php
/**
 * การแจ้งเตือนของระบบวัสดุและครุภัณฑ์ (เรียกจาก core/notifications.php)
 *   ผู้ดูแล: คำขอยืมรออนุมัติ, เกินกำหนดคืน, การยืมล่าสุด, ใบเบิกวัสดุล่าสุด/รออนุมัติ,
 *            วัสดุที่ถูกเบิกล่าสุดซึ่งหมดหรือใกล้หมด
 *   เจ้าหน้าที่: ผลการอนุมัติคำขอยืม, ใกล้ถึง/เกินกำหนดคืน, ใบเบิกของตนที่อนุมัติแล้ว
 */
return function (array $user): array {
    require_once __DIR__ . '/equipment_lib.php';
    $pdo = db();
    $base = module_url('inventory');
    $out = [];
    $today = date('Y-m-d');
    $itemCount = 'SELECT COUNT(*) FROM inv_loan_items i WHERE i.loan_id = l.id';

    if (user_can('inventory', 'admin', $user)) {
        // คำขอยืมรออนุมัติ
        foreach ($pdo->query("SELECT l.*, ($itemCount) AS n FROM inv_loans l WHERE l.status = 'pending' ORDER BY l.id DESC LIMIT 20") as $l) {
            $out[] = ['title' => 'คำขอยืมครุภัณฑ์ รออนุมัติ', 'icon' => 'bi-hourglass-split', 'color' => 'warning',
                      'text' => 'เลขที่ ' . $l['loan_no'] . ' · ' . $l['borrower_name'] . ' · ' . $l['n'] . ' รายการ',
                      'href' => $base . 'loan_view.php?id=' . $l['id'], 'time' => $l['created_at']];
        }
        // เกินกำหนดคืน
        $st = $pdo->prepare("SELECT l.*, ($itemCount) AS n FROM inv_loans l WHERE l.status = 'borrowed' AND l.due_date < ? ORDER BY l.due_date LIMIT 20");
        $st->execute([$today]);
        foreach ($st as $l) {
            $out[] = ['title' => 'เกินกำหนดคืนครุภัณฑ์', 'icon' => 'bi-alarm', 'color' => 'danger',
                      'text' => $l['loan_no'] . ' · ' . $l['borrower_name'] . ' · กำหนดคืน ' . thai_date($l['due_date']),
                      'href' => $base . 'loan_view.php?id=' . $l['id'], 'time' => date('Y-m-d 08:00:00', strtotime($l['due_date'] . ' +1 day'))];
        }
        // ส่งมอบ (ยืมออกไป) ใน 7 วันล่าสุด
        foreach ($pdo->query("SELECT l.*, ($itemCount) AS n FROM inv_loans l WHERE l.status = 'borrowed' AND l.lent_at >= NOW() - INTERVAL 7 DAY ORDER BY l.lent_at DESC LIMIT 10") as $l) {
            $out[] = ['title' => 'มีการยืมครุภัณฑ์', 'icon' => 'bi-box-arrow-up-right', 'color' => 'primary',
                      'text' => $l['borrower_name'] . ' ยืม ' . $l['n'] . ' รายการ ถึง ' . thai_date($l['due_date']),
                      'href' => $base . 'loan_view.php?id=' . $l['id'], 'time' => $l['lent_at']];
        }
        // ใบเบิกวัสดุ: รออนุมัติทั้งหมด + ที่อนุมัติแล้วใน 3 วันล่าสุด
        foreach ($pdo->query("SELECT r.*, u.full_name, (SELECT COUNT(*) FROM inv_requisition_items i WHERE i.requisition_id = r.id) AS n
            FROM inv_requisitions r JOIN core_users u ON u.id = r.created_by
            WHERE r.approved_by IS NULL OR r.created_at >= NOW() - INTERVAL 3 DAY ORDER BY r.id DESC LIMIT 15") as $r) {
            $pending = $r['approved_by'] === null;
            $out[] = ['title' => $pending ? 'ใบเบิกวัสดุ รออนุมัติ' : 'มีการเบิกวัสดุ', 'icon' => 'bi-receipt',
                      'color' => $pending ? 'warning' : 'info',
                      'text' => 'เลขที่ ' . $r['requisition_no'] . ' · ' . ($r['requester_name'] ?: $r['full_name']) . ' · ' . $r['n'] . ' รายการ',
                      'href' => $base . 'requisition_view.php?id=' . $r['id'], 'time' => $r['created_at']];
        }
        // วัสดุจากการเบิกใน 30 วันล่าสุด ที่หมด/ใกล้หมด
        $threshold = max(0, (int)(loan_settings()['low_stock_default'] ?? 2));
        $st = $pdo->prepare("SELECT m.id, m.name, m.unit, m.stock_qty, m.min_stock, MAX(r.created_at) AS last_req
            FROM inv_materials m JOIN inv_requisition_items i ON i.material_id = m.id JOIN inv_requisitions r ON r.id = i.requisition_id
            WHERE r.created_at >= NOW() - INTERVAL 30 DAY
              AND m.stock_qty <= CASE WHEN m.min_stock > 0 THEN m.min_stock ELSE ? END
            GROUP BY m.id ORDER BY m.stock_qty, last_req DESC LIMIT 15");
        $st->execute([$threshold]);
        foreach ($st as $m) {
            $empty = (int)$m['stock_qty'] <= 0;
            $out[] = ['title' => $empty ? 'วัสดุหมดสต๊อก' : 'วัสดุใกล้หมด', 'icon' => $empty ? 'bi-exclamation-octagon' : 'bi-exclamation-triangle',
                      'color' => $empty ? 'danger' : 'warning',
                      'text' => $m['name'] . ' · คงเหลือ ' . (int)$m['stock_qty'] . ' ' . $m['unit'],
                      'href' => $base . 'materials.php?filter=low_stock', 'time' => $m['last_req']];
        }
        return $out;
    }

    // ---------- เจ้าหน้าที่: เฉพาะรายการของตนเอง ----------
    $st = $pdo->prepare("SELECT l.* FROM inv_loans l WHERE (l.borrower_id = ? OR l.created_by = ?)
        AND (l.status = 'borrowed' OR (l.status = 'rejected' AND l.updated_at >= NOW() - INTERVAL 14 DAY))
        ORDER BY l.id DESC LIMIT 20");
    $st->execute([$user['id'], $user['id']]);
    $soon = date('Y-m-d', strtotime('+2 days'));
    foreach ($st as $l) {
        $href = $base . 'loan_view.php?id=' . $l['id'];
        if ($l['status'] === 'rejected') {
            $out[] = ['title' => 'คำขอยืมไม่ได้รับอนุมัติ', 'icon' => 'bi-x-circle', 'color' => 'danger',
                      'text' => $l['loan_no'] . ($l['reject_reason'] ? ' · ' . $l['reject_reason'] : ''), 'href' => $href, 'time' => $l['updated_at']];
            continue;
        }
        if ($l['due_date'] < $today) {
            $out[] = ['title' => 'เกินกำหนดคืนครุภัณฑ์', 'icon' => 'bi-alarm', 'color' => 'danger',
                      'text' => $l['loan_no'] . ' · กำหนดคืน ' . thai_date($l['due_date']) . ' กรุณาส่งคืน', 'href' => $href,
                      'time' => date('Y-m-d 08:00:00', strtotime($l['due_date'] . ' +1 day'))];
        } elseif ($l['due_date'] <= $soon) {
            $out[] = ['title' => 'ใกล้ถึงกำหนดคืนครุภัณฑ์', 'icon' => 'bi-calendar-event', 'color' => 'warning',
                      'text' => $l['loan_no'] . ' · กำหนดคืน ' . thai_date($l['due_date']), 'href' => $href,
                      'time' => date('Y-m-d 08:00:00', strtotime($l['due_date'] . ' -2 days'))];
        }
        if ($l['lent_at'] && strtotime($l['lent_at']) >= strtotime('-14 days')) {
            $out[] = ['title' => 'คำขอยืมได้รับอนุมัติแล้ว', 'icon' => 'bi-check2-circle', 'color' => 'success',
                      'text' => $l['loan_no'] . ' · รับพัสดุได้ที่ ' . ($l['lender_name'] ?: 'ผู้ดูแล'), 'href' => $href, 'time' => $l['lent_at']];
        }
    }
    $st = $pdo->prepare('SELECT * FROM inv_requisitions WHERE created_by = ? AND approved_at >= NOW() - INTERVAL 7 DAY ORDER BY id DESC LIMIT 10');
    $st->execute([$user['id']]);
    foreach ($st as $r) {
        $out[] = ['title' => 'ใบเบิกวัสดุได้รับอนุมัติแล้ว', 'icon' => 'bi-receipt', 'color' => 'success',
                  'text' => 'เลขที่ ' . $r['requisition_no'], 'href' => $base . 'requisition_view.php?id=' . $r['id'], 'time' => $r['approved_at']];
    }
    return $out;
};
