<?php
/** รายละเอียดการยืมครุภัณฑ์ + ไฟล์ใบยืม (พิมพ์) + บันทึกรับคืน / ลบรายการ */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(INV_MODULE);
$pdo = db();
$isAdmin = user_can(INV_MODULE, 'admin');
$id = int_param('id');

$st = $pdo->prepare('SELECT l.*, c.full_name AS created_by_name FROM inv_loans l JOIN core_users c ON c.id = l.created_by WHERE l.id = ?');
$st->execute([$id]);
$loan = $st->fetch();
if (!$loan || !can_view_loan($loan)) {
    flash('ไม่พบรายการยืม หรือไม่มีสิทธิ์', 'danger');
    redirect('loans.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_module(INV_MODULE, 'admin');
    check_csrf();
    $action = post('action');
    if ($action === 'return' && $loan['status'] === 'borrowed') {
        $dt = DateTime::createFromFormat('!Y-m-d', post('returned_date'));
        if (!$dt) {
            flash('กรุณาระบุวันที่รับคืน', 'danger');
            redirect('loan_view.php?id=' . $id);
        }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE inv_loans SET status = 'returned', returned_at = ?, receiver_id = ?, receiver_name = ?, receiver_position = ?, return_note = ?
            WHERE id = ? AND status = 'borrowed'")
            ->execute([$dt->format('Y-m-d') . ' ' . date('H:i:s'), $me['id'], $me['full_name'], $me['position'], post('return_note') ?: null, $id]);
        // อัปเดตสภาพครุภัณฑ์หลังรับคืน (เฉพาะรายการในใบนี้)
        $ids = $pdo->prepare('SELECT equipment_id FROM inv_loan_items WHERE loan_id = ? AND equipment_id IS NOT NULL');
        $ids->execute([$id]);
        $inLoan = array_flip($ids->fetchAll(PDO::FETCH_COLUMN));
        $upd = $pdo->prepare('UPDATE inv_equipment SET status = ? WHERE id = ?');
        foreach ((array)($_POST['eq_status'] ?? []) as $eid => $s) {
            if (isset(EQ_STATUS[$s], $inLoan[$eid])) {
                $upd->execute([$s, (int)$eid]);
            }
        }
        $pdo->commit();
        audit_log('loan_return', 'loan #' . $id . ' ' . $loan['loan_no'], INV_MODULE);
        flash('บันทึกรับคืนเรียบร้อยแล้ว — ครุภัณฑ์กลับเป็น "ว่าง"');
    } elseif ($action === 'undo_return' && $loan['status'] === 'returned') {
        // แก้กรณีกดรับคืนผิด: ต้องไม่มีครุภัณฑ์ในใบนี้ถูกยืมโดยใบอื่นไปแล้ว
        $ids = $pdo->prepare('SELECT equipment_id FROM inv_loan_items WHERE loan_id = ? AND equipment_id IS NOT NULL');
        $ids->execute([$id]);
        if (active_loans_by_equipment(array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN)))) {
            flash('ยกเลิกการรับคืนไม่ได้ — มีครุภัณฑ์ในรายการนี้ถูกยืมไปแล้ว', 'danger');
        } else {
            $pdo->prepare("UPDATE inv_loans SET status = 'borrowed', returned_at = NULL, receiver_id = NULL, receiver_name = NULL,
                receiver_position = NULL, return_note = NULL WHERE id = ?")->execute([$id]);
            audit_log('loan_undo_return', 'loan #' . $id . ' ' . $loan['loan_no'], INV_MODULE);
            flash('ยกเลิกการรับคืนแล้ว — กลับเป็น "กำลังยืม"');
        }
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM inv_loans WHERE id = ?')->execute([$id]);
        delete_upload($loan['file_path']);
        audit_log('loan_delete', 'loan #' . $id . ' ' . $loan['loan_no'] . ' ' . $loan['borrower_name'], INV_MODULE);
        flash('ลบรายการยืมเรียบร้อยแล้ว');
        redirect('loans.php');
    }
    redirect('loan_view.php?id=' . $id);
}

$st = $pdo->prepare('SELECT li.*, e.status AS eq_status FROM inv_loan_items li LEFT JOIN inv_equipment e ON e.id = li.equipment_id
    WHERE li.loan_id = ? ORDER BY li.sort_order, li.id');
$st->execute([$id]);
$items = $st->fetchAll();
$overdue = $loan['status'] === 'borrowed' && $loan['due_date'] < date('Y-m-d');
$isPdf = $loan['file_path'] && str_ends_with(strtolower($loan['file_path']), '.pdf');

$pageTitle = 'รายการยืมเลขที่ ' . $loan['loan_no'];
$pageActions = '<a href="loans.php" class="btn btn-light"><i class="bi bi-arrow-left"></i> รายการยืม-คืน</a>'
    . ($loan['file_path'] ? '<a href="loan_file.php?id=' . $id . '" target="_blank" class="btn btn-outline-dark"><i class="bi bi-printer"></i> เปิด/พิมพ์ใบยืม</a>' : '')
    . ($isAdmin && $loan['status'] === 'borrowed' ? '<a href="loan_form.php?id=' . $id . '" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> แก้ไข</a>' : '');
require __DIR__ . '/header.php';
?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center gap-2 flex-wrap"><strong>สถานะ</strong> <?= loan_status_badge($loan['status']) ?>
        <?php if ($overdue): ?><span class="badge text-bg-danger"><i class="bi bi-alarm"></i> เกินกำหนดคืน <?= (new DateTime($loan['due_date']))->diff(new DateTime('today'))->days ?> วัน</span><?php endif; ?>
        <span class="ms-auto small text-muted">บันทึกโดย <?= h($loan['created_by_name']) ?> · <?= h(thai_date($loan['created_at'])) ?></span></div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-3">ผู้ยืม</dt><dd class="col-sm-9"><?= h($loan['borrower_name']) ?><?= $loan['borrower_position'] ? ' · ' . h($loan['borrower_position']) : '' ?></dd>
          <dt class="col-sm-3">หน่วยงาน / โทร</dt><dd class="col-sm-9"><?= h($loan['borrower_org'] ?: '-') ?> · <?= h($loan['borrower_phone'] ?: '-') ?></dd>
          <dt class="col-sm-3">เลขที่หนังสือ e-office</dt><dd class="col-sm-9"><?= h($loan['doc_no'] ?: '-') ?></dd>
          <?php if ($loan['purpose']): ?><dt class="col-sm-3">วัตถุประสงค์</dt><dd class="col-sm-9"><?= h($loan['purpose']) ?></dd><?php endif; ?>
          <dt class="col-sm-3">ระยะเวลายืม</dt><dd class="col-sm-9"><?= h(thai_date_full($loan['start_date'])) ?> ถึง <?= h(thai_date_full($loan['due_date'])) ?></dd>
          <dt class="col-sm-3">ไฟล์ใบยืม</dt><dd class="col-sm-9"><?php if ($loan['file_path']): ?>
            <a href="loan_file.php?id=<?= $id ?>" target="_blank"><i class="bi <?= $isPdf ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image' ?>"></i> <?= h($loan['file_name']) ?></a>
            · <a href="loan_file.php?id=<?= $id ?>&download=1" class="small">ดาวน์โหลด</a>
            <?php else: ?><span class="text-muted">ไม่มี</span><?php endif; ?></dd>
          <?php if ($loan['returned_at']): ?><dt class="col-sm-3">รับคืน</dt><dd class="col-sm-9"><?= h(thai_date_full($loan['returned_at'])) ?> โดย <?= h($loan['receiver_name']) ?><?= $loan['return_note'] ? '<div class="small text-muted">' . h($loan['return_note']) . '</div>' : '' ?></dd><?php endif; ?>
        </dl>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header"><strong>ครุภัณฑ์ที่ยืม</strong></div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th class="text-center">ลำดับ</th><th>เลขครุภัณฑ์</th><th>รายการ</th><th>ยี่ห้อ/รุ่น</th><th>สภาพปัจจุบัน</th></tr></thead>
          <tbody>
          <?php foreach ($items as $i => $it): ?>
            <tr>
              <td class="text-center"><?= $i + 1 ?></td>
              <td class="font-monospace small"><?= $it['equipment_id'] ? '<a href="equipment_view.php?id=' . (int)$it['equipment_id'] . '">' . h($it['asset_no'] ?: '(ไม่มีเลข)') . '</a>' : h($it['asset_no']) ?></td>
              <td><?= h($it['item_name']) ?><?= (int)$it['quantity'] > 1 ? ' × ' . (int)$it['quantity'] : '' ?></td>
              <td><?= h($it['brand_model']) ?></td>
              <td><?= $it['eq_status'] ? eq_status_badge($it['eq_status']) : '<span class="text-muted small">-</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php if ($isPdf): ?>
      <div class="card"><div class="card-header"><strong><i class="bi bi-file-earmark-pdf"></i> ใบยืม</strong></div>
        <iframe src="loan_file.php?id=<?= $id ?>" style="width:100%;height:75vh;border:0" title="ใบยืม"></iframe></div>
    <?php elseif ($loan['file_path']): ?>
      <div class="card"><div class="card-body text-center"><img src="loan_file.php?id=<?= $id ?>" class="img-fluid" alt="ใบยืม"></div></div>
    <?php endif; ?>
  </div>

  <div class="col-xl-4">
    <?php if ($isAdmin && $loan['status'] === 'borrowed'): ?>
      <form method="post" class="card mb-3 border-primary" data-confirm="บันทึกรับคืนครุภัณฑ์?">
        <div class="card-header bg-primary-subtle"><strong><i class="bi bi-box-arrow-in-down"></i> บันทึกรับคืน</strong></div>
        <div class="card-body">
          <?= csrf_field() ?><input type="hidden" name="action" value="return">
          <label class="form-label">วันที่รับคืน</label>
          <input type="date" name="returned_date" class="form-control mb-2" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
          <?php foreach ($items as $it): if (!$it['equipment_id'] || $it['eq_status'] === null) continue; ?>
            <label class="form-label small mb-1 mt-1">สภาพ: <span class="font-monospace"><?= h($it['asset_no'] ?: $it['brand_model']) ?></span></label>
            <select name="eq_status[<?= (int)$it['equipment_id'] ?>]" class="form-select form-select-sm">
              <?php foreach (EQ_STATUS as $k => $s): ?><option value="<?= $k ?>" <?= $it['eq_status'] === $k ? 'selected' : '' ?>><?= h($s[0]) ?></option><?php endforeach; ?>
            </select>
          <?php endforeach; ?>
          <label class="form-label mt-2">หมายเหตุการรับคืน</label>
          <input name="return_note" class="form-control mb-3" placeholder="เช่น ครบถ้วน สมบูรณ์">
          <button class="btn btn-primary w-100"><i class="bi bi-check2-all"></i> ได้รับครุภัณฑ์คืนแล้ว</button>
        </div>
      </form>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($loan['status'] === 'returned'): ?>
          <form method="post" data-confirm="ยกเลิกการรับคืน (กลับเป็นกำลังยืม)?"><?= csrf_field() ?><input type="hidden" name="action" value="undo_return">
            <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise"></i> ยกเลิกการรับคืน</button></form>
        <?php endif; ?>
        <form method="post" data-confirm="ลบรายการยืมนี้ถาวร (รวมไฟล์ใบยืม)?"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
          <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> ลบรายการยืม</button></form>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
