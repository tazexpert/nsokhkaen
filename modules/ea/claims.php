<?php
/**
 * ใบเบิกค่าใช้จ่ายในการเดินทางไปราชการ (ออกใบสำคัญ) — รายการ
 * - ทุกคนในระบบบริหารโครงการ: สร้าง/แก้ไข/พิมพ์ใบเบิกของตนเอง · ผู้ดูแลระบบบริหารโครงการ: เห็นและแก้ไขได้ทุกใบ
 * - ร่าง → "ออกใบสำคัญ" (ล็อกไม่ให้เจ้าของแก้ไข ผู้ดูแลยกเลิกการออกได้) · คัดลอกเป็นใบใหม่ได้
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/claims_lib.php';
$me = require_module(EA_MODULE, 'viewer');
$pdo = db();
$isAdmin = ea_can('admin');

$find = function (int $id) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT * FROM ea_claims WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $c = $find(int_param('id'));
    if (!$c || !claim_can_view($c)) {
        flash('ไม่พบใบเบิก', 'warning');
        redirect('claims');
    }
    $action = post('action');
    if ($action === 'delete' && claim_can_edit($c)) {
        $pdo->prepare('DELETE FROM ea_claims WHERE id = ?')->execute([$c['id']]);
        audit_log('ea_claim_delete', 'ลบใบเบิกค่าเดินทาง #' . $c['id'] . ' ' . $c['full_name'], EA_MODULE);
        flash('ลบใบเบิกแล้ว');
    } elseif ($action === 'issue' && $c['status'] === 'draft') {
        $pdo->prepare("UPDATE ea_claims SET status = 'issued', issued_at = NOW() WHERE id = ?")->execute([$c['id']]);
        audit_log('ea_claim_issue', 'ออกใบสำคัญใบเบิกค่าเดินทาง #' . $c['id'] . ' ' . $c['full_name'] . ' ' . number_format((float)$c['total_amount'], 2) . ' บาท', EA_MODULE);
        flash('ออกใบสำคัญแล้ว — พิมพ์ชุดเอกสารได้ที่ปุ่มพิมพ์');
    } elseif ($action === 'unissue' && $isAdmin && $c['status'] === 'issued') {
        $pdo->prepare("UPDATE ea_claims SET status = 'draft', issued_at = NULL WHERE id = ?")->execute([$c['id']]);
        audit_log('ea_claim_unissue', 'ยกเลิกการออกใบสำคัญ #' . $c['id'], EA_MODULE);
        flash('ยกเลิกการออกใบสำคัญแล้ว (กลับเป็นร่าง)');
    } elseif ($action === 'copy') {
        // คัดลอกเป็นร่างใบใหม่ (ไม่รวมรายวัน สัญญายืมเงิน) — ใช้กับการเดินทางรอบถัดไป
        $cols = ['user_id', 'project_id', 'project_other', 'office', 'addressee', 'order_no', 'order_date', 'full_name', 'position', 'level', 'department', 'companions', 'purpose',
                 'depart_from', 'return_to', 'origin_address', 'claim_for', 'per_diem_type', 'per_diem_rate', 'lodging_type', 'vehicle_kind', 'km_rate', 'other_desc'];
        $pdo->prepare('INSERT INTO ea_claims (' . implode(',', $cols) . ', claim_date, created_by) SELECT ' . implode(',', $cols) . ', CURDATE(), ? FROM ea_claims WHERE id = ?')
            ->execute([(int)$me['id'], $c['id']]);
        $new = (int)$pdo->lastInsertId();
        flash('คัดลอกเป็นใบเบิกใหม่แล้ว — เพิ่มรายวันที่เดินทาง');
        redirect('claim_form?id=' . $new);
    }
    redirect('claims');
}

// ---------- รายการ ----------
$fUser = $isAdmin && ctype_digit((string)($_GET['user'] ?? '')) ? (int)$_GET['user'] : '';
$fProject = int_param('project');
$fStatus = in_array($_GET['status'] ?? '', ['draft', 'issued'], true) ? $_GET['status'] : '';
$where = [];
$params = [];
if (!$isAdmin) {
    $where[] = 'c.user_id = ?';
    $params[] = (int)$me['id'];
} elseif ($fUser !== '') {
    $where[] = 'c.user_id = ?';
    $params[] = $fUser;
}
if ($fProject) {
    $where[] = 'c.project_id = ?';
    $params[] = $fProject;
}
if ($fStatus !== '') {
    $where[] = 'c.status = ?';
    $params[] = $fStatus;
}
$st = $pdo->prepare('SELECT c.*, (SELECT MIN(trip_date) FROM ea_claim_days d WHERE d.claim_id = c.id) d1,
                            (SELECT MAX(COALESCE(return_date, trip_date)) FROM ea_claim_days d WHERE d.claim_id = c.id) d2,
                            (SELECT COUNT(*) FROM ea_claim_days d WHERE d.claim_id = c.id) ndays
                     FROM ea_claims c' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.claim_date DESC, c.id DESC LIMIT 500');
$st->execute($params);
$rows = $st->fetchAll();
$projects = projects_all();
$sum = array_sum(array_map(fn($r) => (float)$r['total_amount'], $rows));

$pageTitle = 'ใบเบิกค่าเดินทาง';
$active = 'claims';
$pageActions = '<a href="claim_form" class="btn btn-primary"><i class="bi bi-plus-lg"></i> สร้างใบเบิก</a>';
require __DIR__ . '/header.php';
?>
<p class="text-muted small mb-3">ใบเบิกค่าใช้จ่ายในการเดินทางไปราชการ — พิมพ์ชุดเอกสาร แบบ 8708 · แบบประกอบใบเบิกค่าใช้จ่าย · ใบรับรองแทนใบเสร็จรับเงิน (แบบ บก.111)
  <?= $isAdmin ? '· ผู้ดูแลเห็นใบเบิกของทุกคน' : '· แสดงเฉพาะใบเบิกของคุณ' ?></p>
<div class="card" data-reveal>
  <div class="card-header">
    <form class="row g-2 align-items-center">
      <?php if ($isAdmin): ?>
      <div class="col-md-3"><select name="user" class="form-select form-select-sm"><option value="">ผู้เบิกทุกคน</option><?= ea_staff_options($fUser !== '' ? $fUser : null) ?></select></div>
      <?php endif; ?>
      <div class="col-md-3"><select name="project" class="form-select form-select-sm"><option value="">ทุกโครงการ</option>
        <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $fProject === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['code'] . ' — ' . $p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">ทุกสถานะ</option>
        <option value="draft" <?= $fStatus === 'draft' ? 'selected' : '' ?>>ร่าง</option><option value="issued" <?= $fStatus === 'issued' ? 'selected' : '' ?>>ออกใบสำคัญแล้ว</option></select></div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i> ค้นหา</button></div>
      <div class="col-auto ms-auto small text-muted"><?= number_format(count($rows)) ?> ใบ · รวม <?= number_format($sum, 2) ?> บาท</div>
    </form>
  </div>
  <div class="table-responsive reveal-item">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>วันที่</th><?php if ($isAdmin): ?><th>ผู้เบิก</th><?php endif; ?><th>โครงการ / ไปราชการ</th><th>ช่วงเดินทาง</th>
        <th class="text-end">จำนวนเงิน</th><th>สถานะ</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $p = $projects[(int)$r['project_id']] ?? null; $editable = claim_can_edit($r); ?>
        <tr>
          <td class="text-nowrap small"><?= h(thai_date($r['claim_date'])) ?></td>
          <?php if ($isAdmin): ?><td class="small"><?= h($r['full_name']) ?></td><?php endif; ?>
          <td><?php if ($p): ?><span class="fw-semibold" style="color:<?= h($p['color']) ?>"><?= h($p['code']) ?></span> <?php elseif ($r['project_other'] !== ''): ?><span class="fw-semibold"><?= h($r['project_other']) ?></span><br><?php endif; ?>
            <span class="small"><?= h(mb_strimwidth((string)$r['purpose'], 0, 110, '…')) ?: '<span class="text-muted">-</span>' ?></span>
            <?= $r['order_no'] !== '' ? '<div class="small text-muted">คำสั่ง ' . h($r['order_no']) . '</div>' : '' ?></td>
          <td class="small text-nowrap"><?= $r['d1'] ? h(claim_date_short($r['d1']) . ($r['d2'] !== $r['d1'] ? ' – ' . claim_date_short($r['d2']) : '')) . '<div class="text-muted">' . (int)$r['ndays'] . ' วัน</div>' : '<span class="text-muted">ยังไม่มีรายวัน</span>' ?></td>
          <td class="text-end text-nowrap fw-semibold"><?= number_format((float)$r['total_amount'], 2) ?></td>
          <td><?= $r['status'] === 'issued' ? '<span class="badge text-bg-success">ออกใบสำคัญแล้ว</span>' : '<span class="badge text-bg-secondary">ร่าง</span>' ?></td>
          <td class="text-end text-nowrap">
            <a href="claim_print?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success" title="พิมพ์ชุดเอกสาร"><i class="bi bi-printer"></i></a>
            <?php if ($editable): ?><a href="claim_form?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary" title="แก้ไข"><i class="bi bi-pencil"></i></a><?php endif; ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <?php if ($r['status'] === 'draft'): ?>
              <button name="action" value="issue" class="btn btn-sm btn-outline-success" title="ออกใบสำคัญ" onclick="return confirm('ออกใบสำคัญใบเบิกนี้? (หลังออกแล้วแก้ไขได้เฉพาะผู้ดูแล)')"><i class="bi bi-check2-square"></i></button>
              <?php elseif ($isAdmin): ?>
              <button name="action" value="unissue" class="btn btn-sm btn-outline-warning" title="ยกเลิกการออกใบสำคัญ" onclick="return confirm('ยกเลิกการออกใบสำคัญ (กลับเป็นร่าง)?')"><i class="bi bi-arrow-counterclockwise"></i></button>
              <?php endif; ?>
              <button name="action" value="copy" class="btn btn-sm btn-light" title="คัดลอกเป็นใบใหม่"><i class="bi bi-copy"></i></button>
              <?php if ($editable): ?><button name="action" value="delete" class="btn btn-sm btn-light text-danger" title="ลบ" onclick="return confirm('ลบใบเบิกนี้?')"><i class="bi bi-trash"></i></button><?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-5">ยังไม่มีใบเบิก — <a href="claim_form">สร้างใบเบิกค่าเดินทาง</a></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
