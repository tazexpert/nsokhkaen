<?php
/** จัดการสลิป (ผู้จัดการสลิป) + อนุมัติ (ผู้อนุมัติ): รายการสลิปรายเดือน */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(PAY_MODULE, 'manager');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    $in = $ids ? implode(',', $ids) : '0';
    if (in_array($action, ['approve', 'approve_all', 'unapprove'], true) && !pay_can('approver')) {
        forbidden(PAY_MODULE, 'approver');
    }
    if ($action === 'approve' && $ids) {
        $n = $pdo->exec("UPDATE pay_slips SET status = 'approved', approved_by = {$me['id']}, approved_at = NOW()
            WHERE status = 'pending' AND id IN ($in)");
        audit_log('payslip_approve', $n . ' รายการ', PAY_MODULE);
        flash("อนุมัติสลิปแล้ว $n รายการ — พนักงานดูสลิปได้แล้ว");
    } elseif ($action === 'approve_all') {
        $where = "status = 'pending'";
        $params = [];
        if (int_param('year') && int_param('month')) {
            $where .= ' AND year = ? AND month = ?';
            $params = [int_param('year'), int_param('month')];
        }
        $st = $pdo->prepare("UPDATE pay_slips SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE $where");
        $st->execute([$me['id'], ...$params]);
        audit_log('payslip_approve_all', $st->rowCount() . ' รายการ ' . implode('/', $params), PAY_MODULE);
        flash('อนุมัติสลิปทั้งหมด ' . $st->rowCount() . ' รายการ');
    } elseif ($action === 'unapprove' && $ids) {
        $n = $pdo->exec("UPDATE pay_slips SET status = 'pending', approved_by = NULL, approved_at = NULL WHERE id IN ($in)");
        audit_log('payslip_unapprove', $n . ' รายการ', PAY_MODULE);
        flash("ยกเลิกการอนุมัติ $n รายการ (พนักงานจะมองไม่เห็นจนกว่าจะอนุมัติใหม่)", 'warning');
    } elseif ($action === 'delete' && $ids) {
        $n = $pdo->exec("DELETE FROM pay_slips WHERE id IN ($in)");
        audit_log('payslip_delete', $n . ' รายการ', PAY_MODULE);
        flash("ลบสลิป $n รายการ");
    } else {
        flash('กรุณาเลือกรายการ', 'warning');
    }
    $back = post('back');
    redirect(preg_match('/^(slips|slip_view)\.php(\?[\w=&%.-]*)?$/', $back) ? $back : 'slips.php');
}

$periods = $pdo->query('SELECT year, month, COUNT(*) n, SUM(status = \'pending\') pending FROM pay_slips
    GROUP BY year, month ORDER BY year DESC, month DESC')->fetchAll();
$status = $_GET['status'] ?? '';
$year = int_param('year');
$month = int_param('month');
$q = trim($_GET['q'] ?? '');
if (!$year && !isset($_GET['period']) && $status !== 'pending' && $periods) {       // ค่าเริ่มต้น = เดือนล่าสุด
    $year = (int)$periods[0]['year'];
    $month = (int)$periods[0]['month'];
}
$where = [];
$params = [];
if ($year) {
    $where[] = 's.year = ?';
    $params[] = $year;
}
if ($month) {
    $where[] = 's.month = ?';
    $params[] = $month;
}
if (in_array($status, ['pending', 'approved'], true)) {
    $where[] = 's.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(s.full_name LIKE ? OR e.code LIKE ?)';
    array_push($params, "%$q%", "%$q%");
}
$st = $pdo->prepare('SELECT s.*, e.code FROM pay_slips s JOIN pay_employees e ON e.id = s.employee_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY s.year DESC, s.month DESC, e.code');
$st->execute($params);
$slips = $st->fetchAll();
$pendingTotal = (int)$pdo->query("SELECT COUNT(*) FROM pay_slips WHERE status = 'pending'")->fetchColumn();
$cols = PAY_INCOME + ['total_income' => 'รวมรายได้'] + PAY_DEDUCT + ['total_deduct' => 'รวมรายจ่าย', 'net_pay' => 'คงเหลือ'];
$sum = array_fill_keys(array_keys($cols), 0.0);
foreach ($slips as $s) {
    foreach ($cols as $k => $_) {
        $sum[$k] += (float)$s[$k];
    }
}
$cols = array_filter($cols, fn($k) => $k !== 'cola' || $sum['cola'] != 0.0, ARRAY_FILTER_USE_KEY);   // ซ่อนคอลัมน์ที่ไม่มีข้อมูล
$backQs = http_build_query(array_filter(['status' => $status, 'year' => $year ?: null, 'month' => $month ?: null, 'q' => $q]));

$active = $status === 'pending' ? 'pending' : 'slips';
$pageTitle = $status === 'pending' ? 'สลิปรออนุมัติ' : 'จัดการสลิปเงินเดือน';
$pageActions = '<a href="import.php" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> นำเข้าข้อมูล</a>'
    . '<a href="slip_form.php" class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i> เพิ่มสลิป</a>';
require __DIR__ . '/header.php';
?>
<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2 align-items-center">
      <div class="col-md-3"><select name="period" class="form-select" onchange="const [y,m]=this.value.split('-');this.form.year.value=y||'';this.form.month.value=m||'';this.form.submit()">
        <option value="">ทุกเดือน</option>
        <?php foreach ($periods as $p): ?><option value="<?= (int)$p['year'] ?>-<?= (int)$p['month'] ?>" <?= $year === (int)$p['year'] && $month === (int)$p['month'] ? 'selected' : '' ?>>
          <?= h(pay_period((int)$p['year'], (int)$p['month'])) ?> (<?= (int)$p['n'] ?><?= $p['pending'] ? ' · รอ ' . (int)$p['pending'] : '' ?>)</option><?php endforeach; ?>
      </select><input type="hidden" name="year" value="<?= $year ?: '' ?>"><input type="hidden" name="month" value="<?= $month ?: '' ?>"></div>
      <div class="col-md-2"><select name="status" class="form-select" onchange="this.form.submit()">
        <option value="">ทุกสถานะ</option>
        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>รออนุมัติ</option>
        <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>อนุมัติแล้ว</option>
      </select></div>
      <div class="col-md-3"><input name="q" class="form-control" placeholder="ค้นหาชื่อ / เลขที่" value="<?= h($q) ?>"></div>
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></div>
      <?php if (pay_can('approver') && $pendingTotal): ?>
        <div class="col-auto ms-auto">
          <button form="approveAllForm" class="btn btn-success"><i class="bi bi-check2-all"></i>
            อนุมัติทั้งหมด<?= $year && $month ? 'ของเดือนนี้' : '' ?> (<?= $year && $month ? (int)array_sum(array_map(fn($s) => $s['status'] === 'pending', $slips)) : $pendingTotal ?>)</button>
        </div>
      <?php endif; ?>
    </form>
    <form method="post" id="approveAllForm" data-confirm="อนุมัติสลิปที่รออนุมัติทั้งหมด<?= $year && $month ? 'ของ ' . h(pay_period($year, $month)) : '' ?>?">
      <?= csrf_field() ?><input type="hidden" name="action" value="approve_all"><input type="hidden" name="back" value="slips.php?<?= h($backQs) ?>">
      <input type="hidden" name="year" value="<?= $year && $month ? $year : '' ?>"><input type="hidden" name="month" value="<?= $year && $month ? $month : '' ?>">
    </form>
  </div>
</div>

<form method="post" id="bulkForm">
  <?= csrf_field() ?><input type="hidden" name="back" value="slips.php?<?= h($backQs) ?>">
  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0 pay-table">
        <thead><tr>
          <th style="width:32px"><input type="checkbox" class="form-check-input" id="checkAll"></th>
          <th>เลขที่</th><th>ชื่อ สกุล</th><?php if (!$month): ?><th>เดือน</th><?php endif; ?>
          <?php foreach ($cols as $k => $label): ?><th class="num<?= in_array($k, ['total_income', 'total_deduct', 'net_pay'], true) ? ' table-light' : '' ?>"><?= h($label) ?></th><?php endforeach; ?>
          <th>สถานะ</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($slips as $s): ?>
          <tr>
            <td><input type="checkbox" class="form-check-input row-check" name="ids[]" value="<?= (int)$s['id'] ?>"></td>
            <td class="font-monospace"><?= h($s['code']) ?></td>
            <td><?= h($s['full_name']) ?></td>
            <?php if (!$month): ?><td class="small"><?= h(pay_period((int)$s['year'], (int)$s['month'])) ?></td><?php endif; ?>
            <?php foreach ($cols as $k => $_): ?><td class="num<?= $k === 'net_pay' ? ' fw-semibold' : '' ?>"><?= pay_money($s[$k]) ?></td><?php endforeach; ?>
            <td><?= $s['status'] === 'approved' ? '<span class="badge text-bg-success">อนุมัติแล้ว</span>' : '<span class="badge text-bg-warning">รออนุมัติ</span>' ?></td>
            <td class="text-nowrap">
              <a href="slip_view.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-light" title="ดูสลิป"><i class="bi bi-search"></i></a>
              <a href="slip_form.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-light" title="แก้ไข"><i class="bi bi-pencil"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$slips): ?><tr><td colspan="<?= count($cols) + 6 ?>" class="text-center text-muted py-5">ไม่มีข้อมูล<?= $periods ? '' : ' — เริ่มจากเมนู "นำเข้าข้อมูล"' ?></td></tr><?php endif; ?>
        </tbody>
        <?php if (count($slips) > 1): ?>
          <tfoot class="table-light fw-semibold"><tr><td></td><td colspan="<?= $month ? 2 : 3 ?>">รวม <?= count($slips) ?> รายการ</td>
            <?php foreach ($cols as $k => $_): ?><td class="num"><?= pay_money($sum[$k]) ?></td><?php endforeach; ?><td colspan="2"></td></tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
      <span class="small">เลือก <strong id="checkedCount">0</strong> รายการ →</span>
      <?php if (pay_can('approver')): ?>
        <button name="action" value="approve" class="btn btn-sm btn-success"><i class="bi bi-check2"></i> อนุมัติ</button>
        <button name="action" value="unapprove" class="btn btn-sm btn-outline-secondary" onclick="return confirm('ยกเลิกการอนุมัติรายการที่เลือก?')">ยกเลิกอนุมัติ</button>
      <?php endif; ?>
      <button name="action" value="delete" class="btn btn-sm btn-outline-danger ms-auto" onclick="return confirm('ลบสลิปที่เลือก?')"><i class="bi bi-trash"></i> ลบ</button>
    </div>
  </div>
</form>
<script>
(() => {
  const all = document.getElementById('checkAll');
  const count = () => document.getElementById('checkedCount').textContent = document.querySelectorAll('.row-check:checked').length;
  all.addEventListener('change', () => { document.querySelectorAll('.row-check').forEach(b => b.checked = all.checked); count(); });
  document.addEventListener('change', e => { if (e.target.classList.contains('row-check')) count(); });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
