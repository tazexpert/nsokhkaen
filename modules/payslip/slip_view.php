<?php
/** ดูสลิป 1 ใบ (ผู้จัดการ/ผู้อนุมัติ ดูได้ทุกใบ รวมที่ยังไม่อนุมัติ) */
require_once __DIR__ . '/bootstrap.php';
require_module(PAY_MODULE);
$st = db()->prepare('SELECT s.*, e.code, u.full_name AS approver FROM pay_slips s JOIN pay_employees e ON e.id = s.employee_id
    LEFT JOIN core_users u ON u.id = s.approved_by WHERE s.id = ?');
$st->execute([int_param('id')]);
$slip = $st->fetch();
if (!$slip || !pay_can_view($slip)) {
    forbidden(PAY_MODULE, 'approver');
}
$active = pay_is('approver') ? 'slips' : 'my';
$pageTitle = 'สลิปเงินเดือน ' . $slip['code'] . ' · ' . pay_period((int)$slip['year'], (int)$slip['month']);
$back = 'slips.php?' . http_build_query(['year' => $slip['year'], 'month' => $slip['month']]);
$pageActions = (pay_is('approver') ? '<a href="' . h($back) . '" class="btn btn-light"><i class="bi bi-arrow-left"></i> กลับ</a>'
        . '<a href="slip_form.php?id=' . (int)$slip['id'] . '" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> แก้ไข</a>' : '')
    . '<button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์</button>';
require __DIR__ . '/header.php';
?>
<?php if (pay_is('approver')): ?>
  <div class="text-center mb-3 no-print">
    <?php if ($slip['status'] === 'approved'): ?>
      <span class="badge text-bg-success"><i class="bi bi-check2-circle"></i> อนุมัติแล้ว</span>
      <span class="small text-muted">โดย <?= h($slip['approver'] ?: '-') ?> · <?= h(thai_date($slip['approved_at'])) ?></span>
    <?php else: ?>
      <span class="badge text-bg-warning">รออนุมัติ</span> <span class="small text-muted">พนักงานยังมองไม่เห็นสลิปนี้</span>
      <?php if (pay_is('approver')): ?>
        <form method="post" action="slips.php" class="d-inline ms-2"><?= csrf_field() ?>
          <input type="hidden" name="action" value="approve"><input type="hidden" name="ids[]" value="<?= (int)$slip['id'] ?>">
          <input type="hidden" name="back" value="slip_view.php?id=<?= (int)$slip['id'] ?>">
          <button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> อนุมัติ</button></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?= pay_render_slip($slip, $slip['code']) ?>
<?php require __DIR__ . '/footer.php'; ?>
