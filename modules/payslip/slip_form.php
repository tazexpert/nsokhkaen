<?php
/** เพิ่ม/แก้ไขสลิป 1 ใบ (ผู้จัดการสลิป) — แก้สลิปที่อนุมัติแล้ว จะกลับเป็น "รออนุมัติ" */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(PAY_MODULE, 'manager');
$pdo = db();
$id = int_param('id');
$amountKeys = array_keys(PAY_INCOME + PAY_DEDUCT + PAY_TOTALS);

$s = array_fill_keys($amountKeys, null) + ['employee_id' => null, 'year' => (int)date('Y') + 543, 'month' => (int)date('n'), 'status' => 'pending'];
if ($id) {
    $st = $pdo->prepare('SELECT * FROM pay_slips WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) {
        flash('ไม่พบสลิป', 'danger');
        redirect('slips.php');
    }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $before = $s;
    $s['employee_id'] = int_param('employee_id');
    $s['year'] = int_param('year');
    $s['month'] = int_param('month');
    foreach ($amountKeys as $k) {
        $s[$k] = pay_num($_POST[$k] ?? '');
    }
    $s = pay_fill_totals($s);
    $st = $pdo->prepare('SELECT * FROM pay_employees WHERE id = ?');
    $st->execute([$s['employee_id']]);
    $emp = $st->fetch();
    if (!$emp) {
        $errors[] = 'กรุณาเลือกพนักงาน';
    }
    if ($s['year'] < 2500 || $s['year'] > 2700 || $s['month'] < 1 || $s['month'] > 12) {
        $errors[] = 'เดือน/ปี ไม่ถูกต้อง (ปี พ.ศ.)';
    }
    if (!$errors) {
        $dup = $pdo->prepare('SELECT id FROM pay_slips WHERE employee_id = ? AND year = ? AND month = ? AND id <> ?');
        $dup->execute([$s['employee_id'], $s['year'], $s['month'], $id]);
        if ($dup->fetchColumn()) {
            $errors[] = 'มีสลิปของพนักงานคนนี้ในเดือนนี้อยู่แล้ว';
        }
    }
    if (!$errors) {
        $data = ['employee_id' => $s['employee_id'], 'year' => $s['year'], 'month' => $s['month'],
                 'full_name' => $id ? ($before['employee_id'] == $s['employee_id'] ? $before['full_name'] : $emp['full_name']) : $emp['full_name']];
        foreach ($amountKeys as $k) {
            $data[$k] = $s[$k];
        }
        $same = fn($a, $b) => (is_numeric($a) && is_numeric($b)) ? abs((float)$a - (float)$b) < 0.005 : (string)$a === (string)$b;
        $changed = !$id || array_filter(array_keys($data), fn($k) => !$same($data[$k], $before[$k]));
        if ($id && $changed && $before['status'] === 'approved') {
            $data += ['status' => 'pending', 'approved_by' => null, 'approved_at' => null];
        }
        $cols = array_keys($data);
        if ($id) {
            $pdo->prepare('UPDATE pay_slips SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')
                ->execute([...array_values($data), $id]);
        } else {
            $data['created_by'] = $me['id'];
            $cols = array_keys($data);
            $pdo->prepare('INSERT INTO pay_slips (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($data));
            $id = (int)$pdo->lastInsertId();
        }
        audit_log('payslip_save', 'slip #' . $id . ' ' . $emp['code'] . ' ' . $s['month'] . '/' . $s['year'], PAY_MODULE);
        flash(isset($data['status']) ? 'บันทึกแล้ว — สลิปกลับเป็น "รออนุมัติ" เพราะมีการแก้ไข' : 'บันทึกสลิปเรียบร้อยแล้ว', isset($data['status']) ? 'warning' : 'success');
        redirect('slip_view.php?id=' . $id);
    }
}

$employees = $pdo->query('SELECT id, code, full_name, is_active FROM pay_employees ORDER BY code')->fetchAll();
$active = 'slips';
$pageTitle = $id ? 'แก้ไขสลิปเงินเดือน' : 'เพิ่มสลิปเงินเดือน';
require __DIR__ . '/header.php';
$field = function (string $k, string $label) use ($s) {
    $v = $s[$k] === null ? '' : rtrim(rtrim(number_format((float)$s[$k], 2, '.', ''), '0'), '.');
    return '<div class="col-sm-6 col-lg-4"><label class="form-label">' . h($label) . '</label>'
        . '<input name="' . $k . '" class="form-control text-end amount" data-k="' . $k . '" inputmode="decimal" value="' . h($v) . '" placeholder="-"></div>';
};
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<?php if ($id && $s['status'] === 'approved'): ?><div class="alert alert-warning small"><i class="bi bi-info-circle"></i> สลิปนี้อนุมัติแล้ว ถ้าแก้ไขตัวเลข จะกลับเป็น "รออนุมัติ" และพนักงานจะมองไม่เห็นจนกว่าจะอนุมัติใหม่</div><?php endif; ?>
<form method="post" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-12"><div class="card"><div class="card-body row g-3">
    <div class="col-md-6"><label class="form-label">พนักงาน</label>
      <select name="employee_id" class="form-select" required>
        <option value="">— เลือก —</option>
        <?php foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>" <?= (int)$s['employee_id'] === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['code'] . ' · ' . $e['full_name']) ?><?= $e['is_active'] ? '' : ' (ปิดใช้งาน)' ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-3"><label class="form-label">เดือน</label>
      <select name="month" class="form-select"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= (int)$s['month'] === $m ? 'selected' : '' ?>><?= pay_month_name($m) ?></option><?php endfor; ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label">ปี (พ.ศ.)</label><input name="year" type="number" class="form-control" value="<?= (int)$s['year'] ?>" required></div>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header"><strong>รายการรับ</strong></div><div class="card-body row g-3">
    <?php foreach (PAY_INCOME as $k => $l) echo $field($k, $l); ?>
    <?= $field('total_income', 'รวมรายได้') ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header"><strong>รายการหัก</strong></div><div class="card-body row g-3">
    <?php foreach (PAY_DEDUCT as $k => $l) echo $field($k, $l); ?>
    <?= $field('total_deduct', 'รวมรายจ่าย') ?>
    <?= $field('net_pay', 'เงินเดือนคงเหลือ') ?>
  </div></div></div>
  <div class="col-12 sticky-actions">
    <button class="btn btn-primary btn-lg"><i class="bi bi-save"></i> บันทึก</button>
    <button type="button" class="btn btn-outline-secondary btn-lg" id="calc"><i class="bi bi-calculator"></i> คำนวณยอดรวม</button>
    <a href="<?= $id ? 'slip_view.php?id=' . $id : 'slips.php' ?>" class="btn btn-light btn-lg">ยกเลิก</a>
  </div>
</form>
<script>
document.getElementById('calc').addEventListener('click', () => {
  const v = k => parseFloat((document.querySelector(`[data-k="${k}"]`).value || '0').replace(/,/g, '')) || 0;
  const set = (k, n) => document.querySelector(`[data-k="${k}"]`).value = n ? n.toFixed(2).replace(/\.00$/, '') : '';
  const inc = <?= json_encode(array_keys(PAY_INCOME)) ?>.reduce((a, k) => a + v(k), 0);
  const ded = <?= json_encode(array_keys(PAY_DEDUCT)) ?>.reduce((a, k) => a + v(k), 0);
  set('total_income', inc); set('total_deduct', ded); set('net_pay', inc - ded);
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
