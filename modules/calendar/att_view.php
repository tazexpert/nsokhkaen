<?php
/**
 * รายละเอียดเวลาปฏิบัติราชการ 1 วัน (เห็นเฉพาะเจ้าของ)
 * ขาดราชการ → เจ้าของกดยืนยันว่าทำใบรับรองแล้ว เพื่อเปลี่ยนเป็น "รับรองการมาปฏิบัติราชการ" (ผู้ดูแลระบบเปลี่ยนแทนไม่ได้)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/att_lib.php';
$me = require_module(CAL_MODULE);
$date = (string)($_POST['date'] ?? $_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
    redirect('index.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'certify') {
    check_csrf();
    if (att_certify((int)$me['id'], $date)) {
        flash('บันทึกรับรองการมาปฏิบัติราชการ วันที่ ' . thai_date($date) . ' แล้ว');
    } else {
        flash('แก้ได้เฉพาะวันที่เป็น "ขาดราชการ" เท่านั้น', 'warning');
    }
    redirect('att_view.php?date=' . $date);
}
$s = att_user_statuses((int)$me['id'], $date, $date)[$date] ?? null;
$row = $s['row'] ?? null;
if (!$row) {
    $st = db()->prepare('SELECT * FROM cal_attendance WHERE user_id = ? AND work_date = ?');
    $st->execute([(int)$me['id'], $date]);
    $row = $st->fetch() ?: null;
}
$t = fn($v) => $v ? substr($v, 0, 5) . ' น.' : '-';
$active = 'month';
$pageTitle = 'เวลาปฏิบัติราชการ ' . thai_date($date);
$pageActions = '<a href="index.php?m=' . substr($date, 0, 7) . '" class="btn btn-light"><i class="bi bi-calendar3"></i> ปฏิทิน</a>'
    . '<a href="plan.php?m=' . substr($date, 0, 7) . '" class="btn btn-outline-secondary"><i class="bi bi-table"></i> แผนการปฏิบัติงาน</a>';
require __DIR__ . '/header.php';
?>
<div class="card" style="max-width:640px">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-lock-fill text-muted" title="เห็นเฉพาะคุณ"></i>
    <b><?= $s ? h(att_item_title($s)) : 'ไม่มีรายการที่ต้องดำเนินการ' ?></b>
    <?php if ($s): ?><span class="att-chip <?= $s['cls'] ?> ms-auto"><?= h($s['code']) ?></span><?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!$row): ?>
      <p class="text-muted mb-0">ไม่มีข้อมูลการลงเวลาของวันนี้</p>
    <?php else: ?>
      <dl class="row mb-0">
        <dt class="col-4">วันที่</dt><dd class="col-8"><?= h(thai_date($date)) ?></dd>
        <dt class="col-4">เวลาเข้า</dt><dd class="col-8"><?= h($t($row['time_in'])) ?></dd>
        <dt class="col-4">เวลาออก</dt><dd class="col-8"><?= h($t($row['time_out'])) ?></dd>
        <?php if ($s): ?><dt class="col-4">สถานะ</dt><dd class="col-8"><?= h($s['title']) ?></dd><?php endif; ?>
        <?php if ($row['override_code'] === 'ล' && $row['override_at']): ?>
          <dt class="col-4">ยืนยันเมื่อ</dt><dd class="col-8"><?= h(thai_date(substr($row['override_at'], 0, 10)) . ' ' . substr($row['override_at'], 11, 5)) ?> น.</dd>
        <?php endif; ?>
        <dt class="col-4">ข้อมูล ณ</dt><dd class="col-8"><?= $row['report_at'] ? h(thai_date(substr($row['report_at'], 0, 10)) . ' ' . substr($row['report_at'], 11, 5)) . ' น.' : '-' ?></dd>
      </dl>
    <?php endif; ?>
    <?php if ($s && $s['cls'] === 'att-absent'): ?>
      <div class="alert alert-danger mt-3 mb-0">
        วันนี้ไม่มีการลงเวลา ไม่มีงานในแผนการปฏิบัติงาน และไม่มีกิจกรรมไปราชการ — <b>กรุณาทำใบรับรองการมาปฏิบัติราชการ</b>
        แล้วกดปุ่มด้านล่างเพื่อเปลี่ยนเป็น "รับรองการมาปฏิบัติราชการ"
        <form method="post" class="mt-2" onsubmit="return confirm('ยืนยันว่าทำใบรับรองการมาปฏิบัติราชการวันที่ <?= h(thai_date($date)) ?> แล้ว?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="certify"><input type="hidden" name="date" value="<?= $date ?>">
          <button class="btn btn-primary"><i class="bi bi-check2-circle"></i> ทำใบรับรองแล้ว — เปลี่ยนเป็นรับรองการมาปฏิบัติราชการ</button>
        </form>
      </div>
    <?php elseif ($s && $s['cls'] === 'att-pending'): ?>
      <div class="alert alert-warning mt-3 mb-0">ลงเวลาไม่ครบ — <b>กรุณายื่นรับรองการมาปฏิบัติราชการในระบบลงเวลา</b> รายการนี้จะหายไปเมื่อนำเข้าไฟล์เวลาปฏิบัติราชการรอบถัดไป</div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
