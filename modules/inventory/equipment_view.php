<?php
/** รายละเอียดครุภัณฑ์ 1 รายการ + สถานะการยืม + ประวัติการยืม-คืน */
require_once __DIR__ . '/bootstrap.php';
require_module(INV_MODULE);
$pdo = db();
$id = int_param('id');
$isAdmin = user_can(INV_MODULE, 'admin');

$st = $pdo->prepare('SELECT e.*, t.name AS type_name, u.full_name AS created_by_name, au.full_name AS assigned_name FROM inv_equipment e
    LEFT JOIN core_users au ON au.id = e.assigned_user_id
    LEFT JOIN inv_equipment_types t ON t.id = e.type_id LEFT JOIN core_users u ON u.id = e.created_by WHERE e.id = ?');
$st->execute([$id]);
$e = $st->fetch();
if (!$e) {
    flash('ไม่พบครุภัณฑ์', 'danger');
    redirect('equipment.php');
}
$loan = active_loans_by_equipment([$id])[$id] ?? null;
$hist = $pdo->prepare('SELECT l.* FROM inv_loan_items li JOIN inv_loans l ON l.id = li.loan_id
    WHERE li.equipment_id = ? ORDER BY l.id DESC');
$hist->execute([$id]);
$history = $hist->fetchAll();
$age = equipment_age($e['fiscal_year'] ? (int)$e['fiscal_year'] : null);

$pageTitle = trim(($e['brand'] ?? '') . ' ' . ($e['model'] ?? '')) ?: 'รายละเอียดครุภัณฑ์';
$pageActions = '<a href="equipment.php" class="btn btn-light"><i class="bi bi-arrow-left"></i> รายการครุภัณฑ์</a>'
    . ($isAdmin && !$loan && $e['status'] === 'normal' ? '<a href="loan_form.php?equipment=' . $id . '" class="btn btn-success"><i class="bi bi-box-arrow-up-right"></i> บันทึกการยืมรายการนี้</a>' : '')
    . ($isAdmin ? '<a href="equipment_form.php?id=' . $id . '" class="btn btn-primary"><i class="bi bi-pencil"></i> แก้ไข</a>' : '');
require __DIR__ . '/header.php';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4">เลขครุภัณฑ์</dt><dd class="col-sm-8 font-monospace"><?= $e['asset_no'] ? h($e['asset_no']) : '<span class="text-muted fst-italic">(ยังไม่มีเลข)</span>' ?></dd>
          <dt class="col-sm-4">ประเภท</dt><dd class="col-sm-8"><?= h($e['type_name'] ?? '-') ?></dd>
          <dt class="col-sm-4">ยี่ห้อ</dt><dd class="col-sm-8"><?= h($e['brand'] ?: '-') ?></dd>
          <dt class="col-sm-4">รุ่น (Model)</dt><dd class="col-sm-8"><?= h($e['model'] ?: '-') ?></dd>
          <dt class="col-sm-4">หมายเลขเครื่อง</dt><dd class="col-sm-8"><?= h($e['serial_number'] ?: '-') ?></dd>
          <dt class="col-sm-4">ปีงบประมาณ / อายุ</dt><dd class="col-sm-8"><?= h($e['fiscal_year'] ?: '-') ?><?= $age !== null ? ' · ' . $age . ' ปี' : '' ?></dd>
          <dt class="col-sm-4">สภาพการใช้งาน</dt><dd class="col-sm-8"><?= eq_status_badge($e['status']) ?></dd>
          <dt class="col-sm-4">การยืม</dt><dd class="col-sm-8">
            <?php if ($loan): ?>
              <?= loan_status_badge($loan['status']) ?> <a href="loan_view.php?id=<?= (int)$loan['loan_id'] ?>">รายการยืม <?= h($loan['loan_no']) ?></a>
              — <?= h($loan['borrower_name']) ?><?= $loan['status'] === 'borrowed' ? ' (กำหนดคืน ' . h(thai_date($loan['due_date'])) . ')' : '' ?>
            <?php elseif ($e['status'] === 'normal'): ?><span class="badge text-bg-success">ว่าง ให้ยืมได้</span>
            <?php else: ?><span class="text-muted">ให้ยืมไม่ได้ (<?= h(EQ_STATUS[$e['status']][0]) ?>)</span><?php endif; ?></dd>
          <dt class="col-sm-4">ผู้ใช้งานประจำ</dt><dd class="col-sm-8"><?= h($e['assigned_name'] ?: '-') ?></dd>
          <dt class="col-sm-4">สถานที่เก็บ</dt><dd class="col-sm-8"><?= h($e['storage_location'] ?: '-') ?></dd>
          <dt class="col-sm-4">หมายเหตุ</dt><dd class="col-sm-8"><?= h($e['note'] ?: '-') ?></dd>
          <dt class="col-sm-4 text-muted small">บันทึกโดย</dt><dd class="col-sm-8 text-muted small"><?= h($e['created_by_name'] ?: '-') ?> · แก้ไขล่าสุด <?= h(thai_date($e['updated_at'])) ?></dd>
        </dl>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100"><div class="card-body d-flex align-items-center justify-content-center text-center">
      <?php if ($e['image_path']): ?><img src="<?= h(upload_url($e['image_path'])) ?>" class="img-fluid rounded" alt="">
      <?php else: ?><div class="text-muted py-5"><i class="bi bi-laptop display-4 d-block"></i>ไม่มีรูปภาพ</div><?php endif; ?>
    </div></div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header"><strong><i class="bi bi-clock-history"></i> ประวัติการยืม-คืน</strong></div>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>เลขที่</th><th>ผู้ยืม</th><th>ระยะเวลายืม</th><th>สถานะ</th><th>คืนเมื่อ</th></tr></thead>
      <tbody>
      <?php foreach ($history as $l): ?>
        <tr>
          <td><a href="loan_view.php?id=<?= (int)$l['id'] ?>"><?= h($l['loan_no']) ?></a></td>
          <td><?= h($l['borrower_name']) ?></td>
          <td class="small"><?= h(thai_date($l['start_date'])) ?> – <?= h(thai_date($l['due_date'])) ?></td>
          <td><?= loan_status_badge($l['status']) ?></td>
          <td class="small"><?= $l['returned_at'] ? h(thai_date($l['returned_at'])) : '-' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$history): ?><tr><td colspan="5" class="text-center text-muted py-4">ยังไม่เคยถูกยืม</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
