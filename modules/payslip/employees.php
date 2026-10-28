<?php
/**
 * รายชื่อพนักงาน (ผู้จัดการสลิป): รหัสพนักงาน ↔ บัญชีผู้ใช้
 * พนักงานจะดูสลิปได้เมื่อผูกบัญชีแล้วเท่านั้น (ผูกแล้วระบบให้สิทธิ์ "พนักงาน" ในระบบนี้ให้อัตโนมัติ)
 */
require_once __DIR__ . '/bootstrap.php';
require_module(PAY_MODULE, 'manager');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $id = int_param('id');
    if ($action === 'link') {
        $uid = int_param('user_id') ?: null;
        $st = $pdo->prepare('SELECT user_id FROM pay_employees WHERE id = ?');
        $st->execute([$id]);
        $old = $st->fetchColumn();
        pay_link_user($id, $uid);
        if ($old && (int)$old !== (int)$uid) {        // ถอนสิทธิ์พนักงานจากบัญชีเดิม (ถ้ามีแค่สิทธิ์พนักงาน)
            $pdo->prepare("DELETE FROM core_user_roles WHERE user_id = ? AND module_key = ? AND role = 'employee'")->execute([$old, PAY_MODULE]);
        }
        audit_log('payslip_link', "employee #$id → user #" . ($uid ?: '-'), PAY_MODULE);
        flash($uid ? 'ผูกบัญชีผู้ใช้เรียบร้อย (ให้สิทธิ์ "พนักงาน" แล้ว)' : 'ยกเลิกการผูกบัญชีแล้ว');
    } elseif ($action === 'auto') {
        $n = pay_auto_link();
        flash($n ? "ผูกบัญชีอัตโนมัติ $n คน" : 'ไม่พบชื่อที่ตรงกันเพิ่มเติม', $n ? 'success' : 'info');
    } elseif ($action === 'save') {
        $code = post('code');
        $name = post('full_name');
        if ($code === '' || $name === '' || !preg_match('/^[\w.-]{1,20}$/u', $code)) {
            flash('กรุณากรอกเลขที่ (ตัวอักษร/ตัวเลข ไม่เกิน 20) และชื่อ-นามสกุล', 'danger');
        } else {
            try {
                if ($id) {
                    $pdo->prepare('UPDATE pay_employees SET code = ?, full_name = ? WHERE id = ?')->execute([$code, $name, $id]);
                } else {
                    $pdo->prepare('INSERT INTO pay_employees (code, full_name) VALUES (?, ?)')->execute([$code, $name]);
                }
                flash('บันทึกรายชื่อพนักงานแล้ว');
            } catch (PDOException $e) {
                flash('เลขที่นี้มีอยู่แล้ว', 'danger');
            }
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE pay_employees SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        flash('เปลี่ยนสถานะแล้ว');
    } elseif ($action === 'delete') {
        try {
            $pdo->prepare('DELETE FROM pay_employees WHERE id = ?')->execute([$id]);
            flash('ลบพนักงานแล้ว');
        } catch (PDOException $e) {
            flash('ลบไม่ได้เพราะมีสลิปของพนักงานคนนี้อยู่ — ใช้ "ปิดใช้งาน" แทน', 'danger');
        }
    }
    redirect('employees.php');
}

$emps = $pdo->query('SELECT e.*, u.full_name AS user_name, u.username, u.is_active AS user_active,
        (SELECT COUNT(*) FROM pay_slips s WHERE s.employee_id = e.id) AS slip_count,
        (SELECT CONCAT(MAX(year * 100 + month)) FROM pay_slips s WHERE s.employee_id = e.id) AS last_period
    FROM pay_employees e LEFT JOIN core_users u ON u.id = e.user_id ORDER BY e.is_active DESC, e.code')->fetchAll();
$users = $pdo->query('SELECT id, full_name, username FROM core_users WHERE is_active = 1 ORDER BY full_name')->fetchAll();
$unlinked = count(array_filter($emps, fn($e) => !$e['user_id'] && $e['is_active']));

$active = 'employees';
$pageTitle = 'รายชื่อพนักงาน';
$pageActions = '<form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="auto">'
    . '<button class="btn btn-outline-primary"><i class="bi bi-link-45deg"></i> ผูกบัญชีอัตโนมัติตามชื่อ</button></form>';
require __DIR__ . '/header.php';
?>
<div class="alert alert-info small"><i class="bi bi-shield-lock"></i> พนักงานดูได้เฉพาะสลิปของ <strong>เลขที่ที่ผูกกับบัญชีตนเอง</strong> และเฉพาะที่อนุมัติแล้ว
  · ผู้ใช้ที่ไม่อยู่ในรายชื่อนี้ดูสลิปไม่ได้<?= $unlinked ? ' · <strong class="text-danger">ยังไม่ผูกบัญชี ' . $unlinked . ' คน</strong> (ถ้ายังไม่มีบัญชีผู้ใช้ ให้ผู้ดูแลระบบกลางเพิ่มที่ "ผู้ใช้งานและสิทธิ์" ก่อน)' : '' ?></div>
<div class="card">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>เลขที่</th><th>ชื่อ-นามสกุล (ในสลิป)</th><th>บัญชีผู้ใช้ที่ผูก</th><th class="text-center">สลิป</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($emps as $e): ?>
        <tr class="<?= $e['is_active'] ? '' : 'table-secondary text-muted' ?>">
          <td class="font-monospace fw-semibold"><?= h($e['code']) ?></td>
          <td><?= h($e['full_name']) ?><?= $e['is_active'] ? '' : ' <span class="badge text-bg-secondary">ปิดใช้งาน</span>' ?></td>
          <td style="min-width:280px">
            <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
              <select name="user_id" class="form-select form-select-sm <?= $e['user_id'] ? '' : 'border-danger' ?>" onchange="this.form.submit()">
                <option value="">— ยังไม่ผูก —</option>
                <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$e['user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['full_name']) ?><?= $u['username'] ? ' (' . h($u['username']) . ')' : '' ?></option><?php endforeach; ?>
              </select></form></td>
          <td class="text-center"><?= (int)$e['slip_count'] ?><?= $e['last_period'] ? '<div class="small text-muted">ล่าสุด ' . h(pay_period(intdiv((int)$e['last_period'], 100), (int)$e['last_period'] % 100)) . '</div>' : '' ?></td>
          <td class="text-end text-nowrap">
            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#empModal" data-id="<?= (int)$e['id'] ?>" data-code="<?= h($e['code']) ?>" data-name="<?= h($e['full_name']) ?>" title="แก้ไข"><i class="bi bi-pencil"></i></button>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
              <button class="btn btn-sm btn-light" title="<?= $e['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?>"><i class="bi <?= $e['is_active'] ? 'bi-toggle-on text-success' : 'bi-toggle-off' ?>"></i></button></form>
            <?php if (!$e['slip_count']): ?>
              <form method="post" class="d-inline" data-confirm="ลบพนักงาน <?= h($e['code']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                <button class="btn btn-sm btn-light text-danger" title="ลบ"><i class="bi bi-trash"></i></button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$emps): ?><tr><td colspan="5" class="text-center text-muted py-5">ยังไม่มีรายชื่อ — นำเข้าข้อมูลสลิปแล้วระบบจะเพิ่มรายชื่อให้อัตโนมัติ</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer"><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#empModal" data-id="" data-code="" data-name=""><i class="bi bi-plus"></i> เพิ่มพนักงาน</button></div>
</div>

<div class="modal fade" id="empModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
  <div class="modal-header"><h5 class="modal-title">พนักงาน</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="empId">
    <label class="form-label">เลขที่ (รหัสพนักงาน)</label><input name="code" id="empCode" class="form-control mb-3 font-monospace" required maxlength="20">
    <label class="form-label">ชื่อ-นามสกุล</label><input name="full_name" id="empName" class="form-control" required>
  </div>
  <div class="modal-footer"><button class="btn btn-primary">บันทึก</button></div>
</form></div></div>
<script>
document.getElementById('empModal').addEventListener('show.bs.modal', e => {
  const b = e.relatedTarget;
  document.getElementById('empId').value = b.dataset.id; document.getElementById('empCode').value = b.dataset.code; document.getElementById('empName').value = b.dataset.name;
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
