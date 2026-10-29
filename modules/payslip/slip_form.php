<?php
/**
 * เพิ่ม/แก้ไขสลิป 1 ใบ
 *   ผู้จัดการสลิป/ผู้ดูแล = แก้ไขจริง (แก้สลิปที่อนุมัติแล้ว จะกลับเป็น "รออนุมัติ")
 *   ผู้อนุมัติ = "แจ้งแก้ไข": แก้ตัวเลข/เขียนหมายเหตุได้ แต่บันทึกเป็นข้อเสนอ (ไม่แก้สลิป) แล้วแจ้งเตือนผู้จัดการสลิป
 */
require_once __DIR__ . '/bootstrap.php';
$me = pay_require('approver', 'manager');
$suggest = !pay_is('manager');            // ผู้อนุมัติ (ไม่ใช่ผู้จัดการสลิป) → โหมดแจ้งแก้ไข
$pdo = db();
$id = int_param('id');
$amountKeys = array_keys(PAY_INCOME + PAY_DEDUCT + PAY_TOTALS);

$s = array_fill_keys($amountKeys, null) + ['employee_id' => null, 'year' => (int)date('Y') + 543, 'month' => (int)date('n'), 'status' => 'pending'];
if ($id) {
    $st = $pdo->prepare('SELECT s.*, e.code FROM pay_slips s JOIN pay_employees e ON e.id = s.employee_id WHERE s.id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) {
        flash('ไม่พบสลิป', 'danger');
        redirect('slips.php');
    }
} elseif ($suggest) {
    forbidden(PAY_MODULE, 'manager');
}
$orig = $s;
$openComments = $id ? pay_slip_comments($id, true) : [];

$errors = [];
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $suggest) {
    // ---------- ผู้อนุมัติ: บันทึกเป็นข้อเสนอแก้ไข ----------
    check_csrf();
    $message = trim(post('message'));
    $changes = [];
    foreach ($amountKeys as $k) {
        $new = pay_num($_POST[$k] ?? '');
        if (!pay_same_amount($new, $orig[$k])) {
            $changes[$k] = [$orig[$k] === null ? null : (float)$orig[$k], $new];
        }
        $s[$k] = $new;
    }
    if (!$changes && $message === '') {
        $errors[] = 'กรุณาแก้ตัวเลขที่ไม่ถูกต้อง หรือเขียนหมายเหตุอย่างน้อย 1 อย่าง';
    } else {
        $pdo->prepare('INSERT INTO pay_slip_comments (slip_id, user_id, message, changes) VALUES (?, ?, ?, ?)')
            ->execute([$id, $me['id'], $message !== '' ? $message : null, $changes ? json_encode($changes) : null]);
        audit_log('payslip_comment', 'slip #' . $id . ' ' . $s['code'] . ' ' . $s['month'] . '/' . $s['year'] . ' (' . count($changes) . ' รายการ)', PAY_MODULE);
        flash('ส่งแจ้งแก้ไขให้ผู้จัดการสลิปแล้ว — สลิปยังไม่ถูกแก้จนกว่าผู้จัดการสลิปจะตรวจสอบ');
        redirect('slip_view.php?id=' . $id);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ---------- ผู้จัดการสลิป: แก้ไขจริง ----------
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
        $resolved = ($openComments && !empty($_POST['resolve_comments']))
            ? pay_resolve_comments($id, (int)$me['id'], trim(post('resolve_note')) ?: 'แก้ไขสลิปแล้ว') : 0;
        audit_log('payslip_save', 'slip #' . $id . ' ' . $emp['code'] . ' ' . $s['month'] . '/' . $s['year'] . ($resolved ? " (ปิดแจ้งแก้ไข $resolved เรื่อง)" : ''), PAY_MODULE);
        $msg = isset($data['status']) ? 'บันทึกแล้ว — สลิปกลับเป็น "รออนุมัติ" เพราะมีการแก้ไข' : 'บันทึกสลิปเรียบร้อยแล้ว';
        flash($msg . ($resolved ? ' · ปิดเรื่องแจ้งแก้ไขแล้ว (แจ้งผู้อนุมัติให้ตรวจอีกครั้ง)' : ''), isset($data['status']) ? 'warning' : 'success');
        redirect('slip_view.php?id=' . $id);
    }
}

// ใช้ค่าที่ผู้อนุมัติเสนอ (slip_form.php?id=..&apply=<comment id>) — เติมในฟอร์มให้ตรวจก่อนบันทึก
$applied = null;
if (!$suggest && $_SERVER['REQUEST_METHOD'] !== 'POST' && int_param('apply')) {
    foreach ($openComments as $c) {
        if ((int)$c['id'] === int_param('apply')) {
            $applied = $c;
            foreach ($c['changes'] as $k => [, $new]) {
                if (in_array($k, $amountKeys, true)) {
                    $s[$k] = $new;
                }
            }
        }
    }
}

$employees = $suggest ? [] : $pdo->query('SELECT id, code, full_name, is_active FROM pay_employees ORDER BY code')->fetchAll();
$active = $suggest ? 'pending' : 'slips';
$pageTitle = $suggest ? 'แจ้งแก้ไขสลิป ' . $s['code'] . ' · ' . pay_period((int)$s['year'], (int)$s['month'])
    : ($id ? 'แก้ไขสลิปเงินเดือน' : 'เพิ่มสลิปเงินเดือน');
require __DIR__ . '/header.php';
$field = function (string $k, string $label) use ($s, $orig, $suggest, $applied) {
    $v = $s[$k] === null ? '' : rtrim(rtrim(number_format((float)$s[$k], 2, '.', ''), '0'), '.');
    $hint = '';
    if (($suggest || $applied) && !pay_same_amount($s[$k], $orig[$k])) {
        $hint = '<div class="form-text text-primary">เดิม ' . pay_money($orig[$k]) . '</div>';
    }
    return '<div class="col-sm-6 col-lg-4"><label class="form-label">' . h($label) . '</label>'
        . '<input name="' . $k . '" class="form-control text-end amount' . ($hint ? ' border-primary' : '') . '" data-k="' . $k . '" inputmode="decimal" value="' . h($v) . '" placeholder="-">' . $hint . '</div>';
};
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<?php if ($suggest): ?>
  <div class="alert alert-info small"><i class="bi bi-chat-square-text"></i> <strong>แจ้งแก้ไข:</strong> แก้ตัวเลขที่เห็นว่าผิด และ/หรือเขียนหมายเหตุ
    — ระบบจะ<strong>ไม่แก้สลิป</strong> แต่ส่งเป็นข้อเสนอพร้อมแจ้งเตือนผู้จัดการสลิปให้ตรวจสอบและแก้ไข (อนุมัติสลิปนี้ได้เมื่อผู้จัดการสลิปปิดเรื่องแล้ว)</div>
<?php elseif ($id && $s['status'] === 'approved'): ?>
  <div class="alert alert-warning small"><i class="bi bi-info-circle"></i> สลิปนี้อนุมัติแล้ว ถ้าแก้ไขตัวเลข จะกลับเป็น "รออนุมัติ" และพนักงานจะมองไม่เห็นจนกว่าจะอนุมัติใหม่</div>
<?php endif; ?>
<?php if (!$suggest && $openComments): ?>
  <div class="card border-warning mb-3">
    <div class="card-header bg-warning-subtle"><i class="bi bi-chat-square-text"></i> <strong>ผู้อนุมัติแจ้งแก้ไข <?= count($openComments) ?> เรื่อง</strong></div>
    <div class="card-body">
      <?php foreach ($openComments as $c): ?>
        <div class="mb-3 pb-2 border-bottom">
          <div class="small text-muted mb-1"><?= h($c['user_name'] ?: '-') ?> · <?= h(pay_when($c['created_at'])) ?></div>
          <?php if ($c['message']): ?><div class="mb-2" style="white-space:pre-line"><?= h($c['message']) ?></div><?php endif; ?>
          <?= pay_changes_table($c['changes']) ?>
          <?php if ($c['changes']): ?>
            <?php if ($applied && (int)$applied['id'] === (int)$c['id']): ?><span class="badge text-bg-primary"><i class="bi bi-check2"></i> เติมค่าที่เสนอในฟอร์มแล้ว — ตรวจแล้วกดบันทึก</span>
            <?php else: ?><a href="slip_form.php?id=<?= (int)$id ?>&apply=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-magic"></i> ใช้ค่าที่เสนอ</a><?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
<form method="post" class="row g-3">
  <?= csrf_field() ?>
  <?php if (!$suggest): ?>
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
  <?php else: ?>
  <div class="col-12"><div class="card"><div class="card-body">
    <strong class="font-monospace"><?= h($s['code']) ?></strong> · <?= h($s['full_name']) ?> · ประจำเดือน <?= h(pay_period((int)$s['year'], (int)$s['month'])) ?>
  </div></div></div>
  <?php endif; ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header"><strong>รายการรับ</strong></div><div class="card-body row g-3">
    <?php foreach (PAY_INCOME as $k => $l) echo $field($k, $l); ?>
    <?= $field('total_income', 'รวมรายได้') ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header"><strong>รายการหัก</strong></div><div class="card-body row g-3">
    <?php foreach (PAY_DEDUCT as $k => $l) echo $field($k, $l); ?>
    <?= $field('total_deduct', 'รวมรายจ่าย') ?>
    <?= $field('net_pay', 'เงินเดือนคงเหลือ') ?>
  </div></div></div>
  <?php if ($suggest): ?>
    <div class="col-12"><label class="form-label">หมายเหตุถึงผู้จัดการสลิป</label>
      <textarea name="message" class="form-control" rows="3" placeholder="เช่น เงินตกเบิกเดือนนี้ไม่ถูกต้อง ตรวจสอบกับฝ่ายการเงินอีกครั้ง"><?= h($message) ?></textarea></div>
  <?php elseif ($openComments): ?>
    <div class="col-12"><div class="card border-warning"><div class="card-body row g-2 align-items-center">
      <div class="col-md-5"><div class="form-check"><input class="form-check-input" type="checkbox" name="resolve_comments" value="1" id="resolveC" checked>
        <label class="form-check-label" for="resolveC">ปิดเรื่องแจ้งแก้ไข (แก้ไขแล้ว) และแจ้งผู้อนุมัติให้ตรวจอีกครั้ง</label></div></div>
      <div class="col-md-7"><input name="resolve_note" class="form-control" placeholder="ตอบผู้อนุมัติ (ถ้ามี) เช่น แก้ไขตามที่แจ้งแล้ว"></div>
    </div></div></div>
  <?php endif; ?>
  <div class="col-12 sticky-actions">
    <button class="btn btn-primary btn-lg"><i class="bi <?= $suggest ? 'bi-send' : 'bi-save' ?>"></i> <?= $suggest ? 'ส่งแจ้งแก้ไข' : 'บันทึก' ?></button>
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
