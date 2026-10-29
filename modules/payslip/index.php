<?php
/** สลิปของฉัน: พนักงานดู/พิมพ์สลิปเงินเดือนของตนเอง (เฉพาะที่อนุมัติแล้ว) */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(PAY_MODULE);
$pdo = db();
$emp = pay_employee_of($me['id']);

$slips = [];
$slip = null;
if ($emp) {
    $st = $pdo->prepare("SELECT * FROM pay_slips WHERE employee_id = ? AND status = 'approved' ORDER BY year DESC, month DESC");
    $st->execute([$emp['id']]);
    $slips = $st->fetchAll();
    $want = $_GET['p'] ?? '';                        // รูปแบบ ปี-เดือน เช่น 2569-10
    foreach ($slips as $s) {
        if ($want === '' || $want === $s['year'] . '-' . $s['month']) {
            $slip = $s;
            break;
        }
    }
}
if (!$emp && pay_is('approver')) {          // ไม่ใช่พนักงาน → ไปหน้าที่ตามหน้าที่
    redirect('slips.php?status=pending');
}
if (!$emp && pay_is('manager')) {
    redirect('import.php');
}

$active = 'my';
$pageTitle = 'สลิปของฉัน';
$pageActions = $slip ? '<button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์สลิป</button>' : '';
require __DIR__ . '/header.php';
?>
<?php if (!$emp): ?>
  <div class="alert alert-warning"><i class="bi bi-person-exclamation"></i> บัญชีของคุณยังไม่ได้ผูกกับรหัสพนักงานในรายชื่อพนักงาน
    จึงยังดูสลิปเงินเดือนไม่ได้ — กรุณาติดต่อผู้จัดการสลิป</div>
<?php elseif (!$slips): ?>
  <div class="alert alert-info">ยังไม่มีสลิปเงินเดือนที่อนุมัติแล้ว</div>
<?php else: ?>
  <div class="row g-3">
    <div class="col-lg-3 no-print">
      <div class="card">
        <div class="card-header"><strong><i class="bi bi-calendar3"></i> เลือกเดือน</strong></div>
        <div class="list-group list-group-flush" style="max-height:70vh;overflow-y:auto">
          <?php $yr = null; foreach ($slips as $s): if ($s['year'] !== $yr): $yr = $s['year']; ?>
            <div class="list-group-item bg-light small fw-semibold">พ.ศ. <?= (int)$yr ?></div>
          <?php endif; ?>
            <a href="?p=<?= (int)$s['year'] ?>-<?= (int)$s['month'] ?>" class="list-group-item list-group-item-action d-flex<?= (int)$s['id'] === (int)$slip['id'] ? ' active' : '' ?>">
              <?= h(pay_month_name((int)$s['month'])) ?><span class="ms-auto small"><?= pay_money($s['net_pay']) ?></span></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-9 ps-wrap"><?= pay_render_slip($slip, $emp['code']) ?></div>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
