<?php
/** ใบยืมพัสดุ: รายละเอียด + การอนุมัติ / ไม่อนุมัติ / รับคืน / ยกเลิก */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(INV_MODULE);
$pdo = db();
$isAdmin = user_can(INV_MODULE, 'admin');
$id = int_param('id');

$load = function () use ($pdo, $id) {
    $st = $pdo->prepare('SELECT l.*, c.full_name AS created_by_name FROM inv_loans l JOIN core_users c ON c.id = l.created_by WHERE l.id = ?');
    $st->execute([$id]);
    return $st->fetch();
};
$loan = $load();
if (!$loan || !can_view_loan($loan)) {
    flash('ไม่พบใบยืม หรือไม่มีสิทธิ์', 'danger');
    redirect('loans.php');
}
$isOwner = (int)$loan['borrower_id'] === $me['id'] || (int)$loan['created_by'] === $me['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $ok = false;
    if ($action === 'approve' && $isAdmin && $loan['status'] === 'pending') {
        // ตรวจอีกครั้งว่าครุภัณฑ์ยังยืมได้ (อาจถูกเปลี่ยนสภาพหลังส่งคำขอ)
        $bad = $pdo->prepare("SELECT COUNT(*) FROM inv_loan_items li JOIN inv_equipment e ON e.id = li.equipment_id
            WHERE li.loan_id = ? AND e.status <> 'normal'");
        $bad->execute([$id]);
        if ($bad->fetchColumn()) {
            flash('มีครุภัณฑ์ในใบนี้ที่สภาพไม่ใช่ "ใช้งานตามปกติ" แล้ว กรุณาแก้ไขใบยืมก่อนอนุมัติ', 'danger');
        } else {
            $ls = loan_settings();
            $pdo->prepare("UPDATE inv_loans SET status = 'borrowed', lender_id = ?, lender_name = ?, lender_position = ?,
                approver_name = ?, approver_position = ?, lent_at = NOW() WHERE id = ? AND status = 'pending'")
                ->execute([$me['id'], $me['full_name'], $me['position'], $ls['loan_approver_name'] ?: null, $ls['loan_approver_position'] ?: null, $id]);
            flash('อนุมัติและส่งมอบพัสดุเรียบร้อยแล้ว');
            $ok = true;
        }
    } elseif ($action === 'reject' && $isAdmin && $loan['status'] === 'pending') {
        $pdo->prepare("UPDATE inv_loans SET status = 'rejected', reject_reason = ?, lender_id = ?, lender_name = ? WHERE id = ? AND status = 'pending'")
            ->execute([post('reason') ?: null, $me['id'], $me['full_name'], $id]);
        flash('บันทึก "ไม่อนุมัติ" เรียบร้อยแล้ว');
        $ok = true;
    } elseif ($action === 'cancel' && ($isOwner || $isAdmin) && $loan['status'] === 'pending') {
        $pdo->prepare("UPDATE inv_loans SET status = 'cancelled' WHERE id = ? AND status = 'pending'")->execute([$id]);
        flash('ยกเลิกคำขอยืมเรียบร้อยแล้ว');
        $ok = true;
    } elseif ($action === 'return' && $isAdmin && $loan['status'] === 'borrowed') {
        $date = post('returned_date');
        $dt = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$dt) {
            flash('กรุณาระบุวันที่รับคืน', 'danger');
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE inv_loans SET status = 'returned', returned_at = ?, receiver_id = ?, receiver_name = ?, receiver_position = ?, return_note = ?
                WHERE id = ? AND status = 'borrowed'")
                ->execute([$dt->format('Y-m-d') . ' ' . date('H:i:s'), $me['id'], $me['full_name'], $me['position'], post('return_note') ?: null, $id]);
            // สภาพครุภัณฑ์หลังรับคืน (เช่น ชำรุด → เสียรอซ่อม)
            $upd = $pdo->prepare('UPDATE inv_equipment SET status = ? WHERE id = ?');
            $ids = $pdo->prepare('SELECT equipment_id FROM inv_loan_items WHERE loan_id = ? AND equipment_id IS NOT NULL');
            $ids->execute([$id]);
            $inLoan = array_flip($ids->fetchAll(PDO::FETCH_COLUMN));
            foreach ((array)($_POST['eq_status'] ?? []) as $eid => $s) {
                if (isset(EQ_STATUS[$s], $inLoan[$eid])) {
                    $upd->execute([$s, (int)$eid]);
                }
            }
            $pdo->commit();
            flash('บันทึกรับคืนพัสดุเรียบร้อยแล้ว');
            $ok = true;
        }
    } elseif ($action === 'delete' && $isAdmin && in_array($loan['status'], ['rejected', 'cancelled'], true)) {
        $pdo->prepare('DELETE FROM inv_loans WHERE id = ?')->execute([$id]);
        audit_log('loan_delete', 'loan #' . $id . ' ' . $loan['loan_no'], INV_MODULE);
        flash('ลบใบยืมเรียบร้อยแล้ว');
        redirect('loans.php');
    } else {
        flash('ไม่สามารถทำรายการนี้ได้', 'danger');
    }
    if ($ok) {
        audit_log('loan_' . $action, 'loan #' . $id . ' ' . $loan['loan_no'], INV_MODULE);
    }
    redirect('loan_view.php?id=' . $id);
}

$st = $pdo->prepare('SELECT li.*, e.status AS eq_status FROM inv_loan_items li LEFT JOIN inv_equipment e ON e.id = li.equipment_id
    WHERE li.loan_id = ? ORDER BY li.sort_order, li.id');
$st->execute([$id]);
$items = $st->fetchAll();
$overdue = $loan['status'] === 'borrowed' && $loan['due_date'] < date('Y-m-d');

$pageTitle = 'ใบยืมพัสดุ เลขที่ ' . $loan['loan_no'];
$pageActions = '<a href="loans.php" class="btn btn-light"><i class="bi bi-arrow-left"></i> รายการยืม-คืน</a>'
    . '<a href="loan_print.php?id=' . $id . '" target="_blank" class="btn btn-outline-dark"><i class="bi bi-printer"></i> พิมพ์บันทึกข้อความ</a>'
    . ($loan['status'] === 'pending' && ($isOwner || $isAdmin) ? '<a href="loan_form.php?id=' . $id . '" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> แก้ไข</a>' : '');
require __DIR__ . '/header.php';
?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center gap-2"><strong>สถานะ</strong> <?= loan_status_badge($loan['status']) ?>
        <?php if ($overdue): ?><span class="badge text-bg-danger"><i class="bi bi-alarm"></i> เกินกำหนดคืน <?= (new DateTime($loan['due_date']))->diff(new DateTime('today'))->days ?> วัน</span><?php endif; ?>
        <span class="ms-auto small text-muted">บันทึกโดย <?= h($loan['created_by_name']) ?> · <?= h(thai_date($loan['created_at'])) ?></span></div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-3">ผู้ยืม</dt><dd class="col-sm-9"><?= h($loan['borrower_name']) ?><?= $loan['borrower_position'] ? ' · ' . h($loan['borrower_position']) : '' ?></dd>
          <dt class="col-sm-3">หน่วยงาน / โทร</dt><dd class="col-sm-9"><?= h($loan['borrower_org'] ?: '-') ?> · <?= h($loan['borrower_phone'] ?: '-') ?></dd>
          <dt class="col-sm-3">วัตถุประสงค์</dt><dd class="col-sm-9"><?= h($loan['purpose']) ?></dd>
          <dt class="col-sm-3">ระยะเวลายืม</dt><dd class="col-sm-9"><?= h(thai_date_full($loan['start_date'])) ?> ถึง <?= h(thai_date_full($loan['due_date'])) ?></dd>
          <?php if ($loan['lent_at']): ?><dt class="col-sm-3">ผู้ให้ยืม</dt><dd class="col-sm-9"><?= h($loan['lender_name']) ?> · ส่งมอบ <?= h(thai_date($loan['lent_at'])) ?></dd><?php endif; ?>
          <?php if ($loan['approver_name']): ?><dt class="col-sm-3">ผู้อนุมัติ</dt><dd class="col-sm-9"><?= h($loan['approver_name']) ?></dd><?php endif; ?>
          <?php if ($loan['returned_at']): ?><dt class="col-sm-3">รับคืน</dt><dd class="col-sm-9"><?= h(thai_date_full($loan['returned_at'])) ?> โดย <?= h($loan['receiver_name']) ?><?= $loan['return_note'] ? '<div class="small text-muted">' . h($loan['return_note']) . '</div>' : '' ?></dd><?php endif; ?>
          <?php if ($loan['status'] === 'rejected'): ?><dt class="col-sm-3 text-danger">เหตุผลที่ไม่อนุมัติ</dt><dd class="col-sm-9"><?= h($loan['reject_reason'] ?: '-') ?> <span class="text-muted small">(<?= h($loan['lender_name']) ?>)</span></dd><?php endif; ?>
        </dl>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><strong>รายการพัสดุ</strong></div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th class="text-center">ลำดับ</th><th>รายการ</th><th>ยี่ห้อ/รุ่น</th><th>หมายเลขเครื่อง</th><th>หมายเลขพัสดุ</th><th class="text-center">จำนวน</th></tr></thead>
          <tbody>
          <?php foreach ($items as $i => $it): ?>
            <tr>
              <td class="text-center"><?= $i + 1 ?></td>
              <td><?= $it['equipment_id'] ? '<a href="equipment_view.php?id=' . (int)$it['equipment_id'] . '">' . h($it['item_name']) . '</a>' : h($it['item_name']) ?></td>
              <td><?= h($it['brand_model']) ?></td>
              <td class="small"><?= h($it['serial_number']) ?></td>
              <td class="font-monospace small"><?= h($it['asset_no']) ?></td>
              <td class="text-center"><?= (int)$it['quantity'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <?php if ($isAdmin && $loan['status'] === 'pending'): ?>
      <div class="card mb-3 border-warning">
        <div class="card-header bg-warning-subtle"><strong><i class="bi bi-hourglass-split"></i> รออนุมัติ</strong></div>
        <div class="card-body">
          <form method="post" class="mb-3" data-confirm="อนุมัติและส่งมอบพัสดุตามใบยืมนี้?">
            <?= csrf_field() ?><input type="hidden" name="action" value="approve">
            <button class="btn btn-success w-100"><i class="bi bi-check2-circle"></i> อนุมัติและส่งมอบพัสดุ</button>
            <div class="form-text">ผู้ให้ยืม: <?= h($me['full_name']) ?> · ผู้อนุมัติตามที่ตั้งค่าไว้</div>
          </form>
          <form method="post" data-confirm="ไม่อนุมัติใบยืมนี้?">
            <?= csrf_field() ?><input type="hidden" name="action" value="reject">
            <input name="reason" class="form-control form-control-sm mb-2" placeholder="เหตุผลที่ไม่อนุมัติ (ไม่บังคับ)">
            <button class="btn btn-outline-danger w-100 btn-sm"><i class="bi bi-x-circle"></i> ไม่อนุมัติ</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($isAdmin && $loan['status'] === 'borrowed'): ?>
      <form method="post" class="card mb-3 border-primary" data-confirm="บันทึกรับคืนพัสดุ?">
        <div class="card-header bg-primary-subtle"><strong><i class="bi bi-box-arrow-in-down"></i> บันทึกรับคืนพัสดุ</strong></div>
        <div class="card-body">
          <?= csrf_field() ?><input type="hidden" name="action" value="return">
          <label class="form-label">วันที่รับคืน</label>
          <input type="date" name="returned_date" class="form-control mb-2" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
          <?php foreach ($items as $it): if (!$it['equipment_id'] || $it['eq_status'] === null) continue; ?>
            <label class="form-label small mb-1 mt-1">สภาพ: <?= h($it['brand_model']) ?> <span class="font-monospace text-muted"><?= h($it['asset_no']) ?></span></label>
            <select name="eq_status[<?= (int)$it['equipment_id'] ?>]" class="form-select form-select-sm">
              <?php foreach (EQ_STATUS as $k => $s): ?><option value="<?= $k ?>" <?= $it['eq_status'] === $k ? 'selected' : '' ?>><?= h($s[0]) ?></option><?php endforeach; ?>
            </select>
          <?php endforeach; ?>
          <label class="form-label mt-2">หมายเหตุการรับคืน</label>
          <input name="return_note" class="form-control mb-3" placeholder="เช่น ครบถ้วน สมบูรณ์">
          <button class="btn btn-primary w-100"><i class="bi bi-check2-all"></i> ได้รับพัสดุคืนครบถ้วน</button>
          <div class="form-text">ผู้รับคืน: <?= h($me['full_name']) ?></div>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($loan['status'] === 'pending' && ($isOwner || $isAdmin)): ?>
      <form method="post" class="mb-3" data-confirm="ยกเลิกคำขอยืมนี้?">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
        <button class="btn btn-outline-secondary w-100"><i class="bi bi-x-lg"></i> ยกเลิกคำขอยืม</button>
      </form>
    <?php endif; ?>
    <?php if ($isAdmin && in_array($loan['status'], ['rejected', 'cancelled'], true)): ?>
      <form method="post" data-confirm="ลบใบยืมนี้ถาวร?">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete">
        <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> ลบใบยืม</button>
      </form>
    <?php endif; ?>

    <div class="card"><div class="card-body small text-muted">
      <strong class="text-body">ข้อกำหนดการยืม-คืน (สรุป)</strong>
      <ul class="mb-0 ps-3 mt-1">
        <li>ยืมเพื่อประโยชน์ทางราชการเท่านั้น และระบุเหตุผลทุกครั้ง</li>
        <li>ผู้ยืมรับผิดชอบดูแล หากชำรุด/สูญหายจากความประมาท ต้องชดใช้</li>
        <li>ห้ามให้ผู้อื่นยืมต่อ เว้นแต่ได้รับอนุมัติเป็นลายลักษณ์อักษร</li>
        <li>ส่งคืนภายในกำหนด หากต้องใช้ต่อ ให้คืนก่อนแล้วขอยืมใหม่</li>
      </ul>
    </div></div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
