<?php
/**
 * รายการงบประมาณ (ชื่อโครงการที่ได้รับ นอกจากโครงการสำรวจ) — ผู้ดูแลระบบงบประมาณ
 * โครงการสำรวจมาจากหน้า "โครงการที่รับผิดชอบ" (ระบบบริหารโครงการ) อัตโนมัติ
 * ผูกโครงการสำรวจกับรายการ (เช่น โพล → รายจ่ายอื่น (โพล)) = นับเป็นรายการนั้นแทน
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(BUD_MODULE, 'admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $name = trim((string)post('name'));
    $pid = int_param('project_id') ?: null;
    if ($pid && !isset(projects_all()[$pid])) {
        $pid = null;
    }
    if ($action === 'add' || $action === 'save') {
        if ($name === '') {
            flash('กรุณาระบุชื่อรายการ', 'warning');
            redirect('items.php');
        }
        $dup = $pdo->prepare('SELECT id FROM bud_items WHERE name = ? AND id <> ?');
        $dup->execute([$name, $action === 'save' ? int_param('id') : 0]);
        if ($dup->fetch()) {
            flash('มีรายการชื่อ "' . $name . '" แล้ว', 'warning');
            redirect('items.php');
        }
        if ($pid) {
            // โครงการหนึ่งผูกได้กับรายการเดียว
            $pdo->prepare('UPDATE bud_items SET project_id = NULL WHERE project_id = ? AND id <> ?')->execute([$pid, $action === 'save' ? int_param('id') : 0]);
        }
    }
    if ($action === 'add') {
        $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM bud_items WHERE is_other = ' . (post('is_other') ? 1 : 0))->fetchColumn();
        $pdo->prepare('INSERT INTO bud_items (name, is_other, project_id, sort_order) VALUES (?, ?, ?, ?)')
            ->execute([mb_substr($name, 0, 150), post('is_other') ? 1 : 0, $pid, $sort]);
        audit_log('bud_item_add', 'เพิ่มรายการงบประมาณ: ' . $name, BUD_MODULE);
        flash('เพิ่มรายการ "' . $name . '" แล้ว');
    } elseif ($action === 'save') {
        $pdo->prepare('UPDATE bud_items SET name = ?, is_other = ?, project_id = ?, sort_order = ?, is_active = ? WHERE id = ?')
            ->execute([mb_substr($name, 0, 150), post('is_other') ? 1 : 0, $pid, (int)post('sort_order'), post('is_active') ? 1 : 0, int_param('id')]);
        audit_log('bud_item_save', 'แก้ไขรายการงบประมาณ: ' . $name, BUD_MODULE);
        flash('บันทึก "' . $name . '" แล้ว');
    } elseif ($action === 'delete') {
        $id = int_param('id');
        $used = $pdo->prepare('SELECT COUNT(*) FROM bud_txns WHERE item_id = ?');
        $used->execute([$id]);
        if ($used->fetchColumn()) {
            flash('รายการนี้มีข้อมูลรับเข้า/เบิกจ่ายแล้ว ลบไม่ได้ — ใช้ "ปิดใช้งาน" แทน', 'warning');
        } else {
            $pdo->prepare('DELETE FROM bud_items WHERE id = ?')->execute([$id]);
            audit_log('bud_item_delete', 'ลบรายการงบประมาณ #' . $id, BUD_MODULE);
            flash('ลบรายการแล้ว');
        }
    }
    redirect('items.php');
}

$items = $pdo->query('SELECT i.*, (SELECT COUNT(*) FROM bud_txns t WHERE t.item_id = i.id) used FROM bud_items i ORDER BY is_other, sort_order, id')->fetchAll();
$projects = projects_all();
$linked = array_filter(array_column($items, 'project_id'));
$survey = array_filter($projects, fn($p) => $p['is_active'] && !in_array($p['id'], $linked));
$projOptions = function (?int $sel) use ($projects): string {
    $o = '<option value="">— ไม่ผูก —</option>';
    foreach ($projects as $p) {
        $o .= '<option value="' . (int)$p['id'] . '"' . ((int)$p['id'] === $sel ? ' selected' : '') . '>' . h($p['code'] . ($p['name'] !== $p['code'] ? ' ' . $p['name'] : '')) . '</option>';
    }
    return $o;
};
$pageTitle = 'รายการงบประมาณ';
$active = 'items';
require __DIR__ . '/header.php';
?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card">
      <div class="card-header"><strong>รายการงบประมาณ</strong> <span class="small text-muted">— ใช้เป็น "ชื่อโครงการที่ได้รับ" ร่วมกับโครงการสำรวจ</span></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th style="width:5rem">ลำดับ</th><th>ชื่อรายการ</th><th class="text-center">รายจ่ายอื่น</th><th>ผูกโครงการ</th><th class="text-center">ใช้งาน</th><th class="text-end">ข้อมูล</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($items as $i): $f = 'f' . $i['id']; ?>
            <tr class="<?= $i['is_active'] ? '' : 'text-muted' ?>">
              <td><input form="<?= $f ?>" type="number" name="sort_order" value="<?= (int)$i['sort_order'] ?>" class="form-control form-control-sm"></td>
              <td><input form="<?= $f ?>" type="text" name="name" value="<?= h($i['name']) ?>" class="form-control form-control-sm" maxlength="150" required></td>
              <td class="text-center"><input form="<?= $f ?>" type="checkbox" name="is_other" value="1" class="form-check-input"<?= $i['is_other'] ? ' checked' : '' ?>></td>
              <td><select form="<?= $f ?>" name="project_id" class="form-select form-select-sm" style="min-width:8rem"><?= $projOptions($i['project_id'] ? (int)$i['project_id'] : null) ?></select></td>
              <td class="text-center"><input form="<?= $f ?>" type="checkbox" name="is_active" value="1" class="form-check-input"<?= $i['is_active'] ? ' checked' : '' ?>></td>
              <td class="text-end small"><?= (int)$i['used'] ? number_format($i['used']) . ' รายการ' : '-' ?></td>
              <td class="text-end text-nowrap">
                <form method="post" id="<?= $f ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                  <button class="btn btn-sm btn-primary" title="บันทึก"><i class="bi bi-check-lg"></i></button></form>
                <?php if (!(int)$i['used']): ?>
                <form method="post" class="d-inline" data-confirm="<?= h('ลบรายการ "' . $i['name'] . '"?') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                  <button class="btn btn-sm btn-light text-danger" title="ลบ"><i class="bi bi-trash"></i></button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small text-muted">"รายจ่ายอื่น" = โครงการนอกสำนักงานสถิติแห่งชาติ (เช่น โพล) แสดงเป็นกลุ่มแยกในรายงาน ·
        "ผูกโครงการ" = โครงการสำรวจนั้นนับเป็นรายการนี้แทน และไม่แสดงในกลุ่มโครงการสำรวจ · รายการที่มีข้อมูลแล้วลบไม่ได้ ให้ปิดใช้งานแทน</div>
    </div>
  </div>
  <div class="col-xl-4">
    <form method="post" class="card mb-3">
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <div class="card-header"><strong><i class="bi bi-plus-lg"></i> เพิ่มรายการงบประมาณ</strong></div>
      <div class="card-body">
        <input type="text" name="name" class="form-control mb-2" placeholder="ชื่อรายการ เช่น ค่าซ่อมแซมครุภัณฑ์" maxlength="150" required>
        <div class="form-check mb-2"><input type="checkbox" name="is_other" value="1" id="newOther" class="form-check-input"><label for="newOther" class="form-check-label">รายจ่ายอื่น (โครงการนอกสำนักงานสถิติแห่งชาติ)</label></div>
        <label class="form-label small mb-0">ผูกกับโครงการสำรวจ (ไม่บังคับ)</label>
        <select name="project_id" class="form-select mb-3"><?= $projOptions(null) ?></select>
        <button class="btn btn-primary"><i class="bi bi-plus-lg"></i> เพิ่ม</button>
      </div>
    </form>
    <div class="card">
      <div class="card-header"><strong>โครงการสำรวจ</strong> <span class="small text-muted">(จากหน้าโครงการที่รับผิดชอบ)</span></div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($survey as $p): ?><li class="list-group-item"><span class="bud-dot" style="background:<?= h($p['color']) ?>"></span><b><?= h($p['code']) ?></b> <?= h($p['name'] !== $p['code'] ? $p['name'] : '') ?></li><?php endforeach; ?>
        <?php if (!$survey): ?><li class="list-group-item text-muted">ยังไม่มีโครงการ</li><?php endif; ?>
      </ul>
      <div class="card-footer small"><a href="<?= h(module_url('ea', 'projects.php')) ?>">จัดการโครงการที่รับผิดชอบ <i class="bi bi-box-arrow-up-right"></i></a></div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
