<?php
/** ดูสลิป 1 ใบ (ผู้จัดการสลิป/ผู้อนุมัติ ดูได้ทุกใบ รวมที่ยังไม่อนุมัติ — แก้ไขได้เฉพาะผู้จัดการสลิป อนุมัติได้เฉพาะผู้อนุมัติ) */
require_once __DIR__ . '/bootstrap.php';
require_module(PAY_MODULE);
$st = db()->prepare('SELECT s.*, e.code, u.full_name AS approver FROM pay_slips s JOIN pay_employees e ON e.id = s.employee_id
    LEFT JOIN core_users u ON u.id = s.approved_by WHERE s.id = ?');
$st->execute([int_param('id')]);
$slip = $st->fetch();
if (!$slip || !pay_can_view($slip)) {
    forbidden(PAY_MODULE, 'approver');
}
$staff = pay_is('approver', 'manager');
$canManage = pay_is('manager');

// ผู้จัดการสลิป: ปิดเรื่องแจ้งแก้ไขโดยไม่ต้องแก้สลิป (พร้อมเหตุผล)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!$canManage) {
        forbidden(PAY_MODULE, 'manager');
    }
    if (post('action') === 'resolve') {
        $n = pay_resolve_comments((int)$slip['id'], (int)current_user()['id'], trim(post('resolve_note')) ?: 'ตรวจสอบแล้ว ไม่ต้องแก้ไข', int_param('comment_id') ?: null);
        audit_log('payslip_comment_resolve', 'slip #' . $slip['id'] . " ($n เรื่อง)", PAY_MODULE);
        flash($n ? 'ปิดเรื่องแจ้งแก้ไขแล้ว — แจ้งผู้อนุมัติให้ตรวจอีกครั้ง' : 'เรื่องนี้ปิดไปแล้ว', $n ? 'success' : 'info');
    }
    redirect('slip_view.php?id=' . (int)$slip['id'] . '#comments');
}
$comments = $staff ? pay_slip_comments((int)$slip['id']) : [];
$openCount = count(array_filter($comments, fn($c) => $c['status'] === 'open'));
$active = pay_is('manager') ? 'slips' : ($staff ? 'pending' : 'my');
$pageTitle = 'สลิปเงินเดือน ' . $slip['code'] . ' · ' . pay_period((int)$slip['year'], (int)$slip['month']);
$back = pay_is('manager') ? 'slips.php?' . http_build_query(['year' => $slip['year'], 'month' => $slip['month']]) : 'slips.php?status=pending';
$pageActions = ($staff ? '<a href="' . h($back) . '" class="btn btn-light"><i class="bi bi-arrow-left"></i> กลับ</a>' : '')
    . ($canManage ? '<a href="slip_form.php?id=' . (int)$slip['id'] . '" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> แก้ไข</a>'
        : ($staff ? '<a href="slip_form.php?id=' . (int)$slip['id'] . '" class="btn btn-outline-warning"><i class="bi bi-chat-square-text"></i> แจ้งแก้ไข</a>' : ''))
    . '<button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์</button>';
require __DIR__ . '/header.php';
?>
<?php if ($staff): ?>
  <div class="text-center mb-3 no-print">
    <?php if ($slip['status'] === 'approved'): ?>
      <span class="badge text-bg-success"><i class="bi bi-check2-circle"></i> อนุมัติแล้ว</span>
      <span class="small text-muted">โดย <?= h($slip['approver'] ?: '-') ?> · <?= h(thai_date($slip['approved_at'])) ?></span>
    <?php else: ?>
      <span class="badge text-bg-warning">รออนุมัติ</span> <span class="small text-muted">พนักงานยังมองไม่เห็นสลิปนี้</span>
      <?php if (pay_is('approver') && $openCount): ?>
        <span class="btn btn-sm btn-outline-secondary disabled ms-2"><i class="bi bi-hourglass"></i> รอผู้จัดการสลิปแก้ไขตามที่แจ้ง</span>
      <?php elseif (pay_is('approver')): ?>
        <form method="post" action="slips.php" class="d-inline ms-2"><?= csrf_field() ?>
          <input type="hidden" name="action" value="approve"><input type="hidden" name="ids[]" value="<?= (int)$slip['id'] ?>">
          <input type="hidden" name="back" value="slip_view.php?id=<?= (int)$slip['id'] ?>">
          <button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> อนุมัติ</button></form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($openCount): ?>
      <div class="mt-2"><a href="#comments" class="badge text-bg-danger text-decoration-none"><i class="bi bi-chat-square-text"></i> แจ้งแก้ไขรอผู้จัดการสลิป <?= $openCount ?> เรื่อง</a></div>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?= pay_render_slip($slip, $slip['code']) ?>
<?php if ($comments): ?>
  <div class="card mt-4 no-print mx-auto" id="comments" style="max-width:820px">
    <div class="card-header"><i class="bi bi-chat-square-text"></i> <strong>ประวัติการแจ้งแก้ไข</strong></div>
    <div class="list-group list-group-flush">
      <?php foreach ($comments as $c): $open = $c['status'] === 'open'; ?>
        <div class="list-group-item <?= $open ? 'list-group-item-warning' : '' ?>">
          <div class="d-flex justify-content-between flex-wrap gap-2 mb-1">
            <span class="small"><strong><?= h($c['user_name'] ?: '-') ?></strong> <span class="text-muted">แจ้งเมื่อ <?= h(pay_when($c['created_at'])) ?></span></span>
            <?= $open ? '<span class="badge text-bg-warning">รอผู้จัดการสลิป</span>' : '<span class="badge text-bg-success">ปิดเรื่องแล้ว</span>' ?>
          </div>
          <?php if ($c['message']): ?><div class="mb-2" style="white-space:pre-line"><?= h($c['message']) ?></div><?php endif; ?>
          <?= pay_changes_table($c['changes']) ?>
          <?php if (!$open): ?>
            <div class="small text-success"><i class="bi bi-reply"></i> <?= h($c['resolver_name'] ?: '-') ?> · <?= h(pay_when($c['resolved_at'])) ?><?= $c['resolve_note'] ? ' — ' . h($c['resolve_note']) : '' ?></div>
          <?php elseif ($canManage): ?>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <a href="slip_form.php?id=<?= (int)$slip['id'] ?><?= $c['changes'] ? '&apply=' . (int)$c['id'] : '' ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil"></i> <?= $c['changes'] ? 'แก้ไขตามที่เสนอ' : 'แก้ไขสลิป' ?></a>
              <form method="post" class="d-flex gap-1 flex-grow-1"><?= csrf_field() ?><input type="hidden" name="action" value="resolve"><input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                <input name="resolve_note" class="form-control form-control-sm" placeholder="ตอบผู้อนุมัติ เช่น ตรวจสอบแล้ว ตัวเลขถูกต้อง">
                <button class="btn btn-sm btn-outline-success text-nowrap"><i class="bi bi-check2"></i> ปิดเรื่อง (ไม่แก้สลิป)</button></form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
