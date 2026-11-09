<?php
/**
 * แผนงาน → งานและโครงการ (ตัวคลุม) และหมวด (นอกจากโครงการสำรวจ) — ผู้ดูแลระบบงบประมาณ
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
    // แผนงาน / งานและโครงการ
    if (in_array($action, ['plan_add', 'plan_save', 'plan_delete', 'work_add', 'work_save', 'work_delete'], true)) {
        $id = int_param('id');
        $isPlan = str_starts_with($action, 'plan_');
        $label = $isPlan ? 'แผนงาน' : 'งานและโครงการ';
        $planId = int_param('plan_id');
        if (!$isPlan && $action !== 'work_delete' && !isset(bud_plans()[$planId])) {
            flash('กรุณาเลือกแผนงาน', 'warning');
            redirect('items.php');
        }
        if (str_ends_with($action, '_delete')) {
            $used = $pdo->prepare($isPlan ? 'SELECT COUNT(*) FROM bud_works WHERE plan_id = ?' : 'SELECT COUNT(*) FROM bud_txns WHERE work_id = ?');
            $used->execute([$id]);
            if ($used->fetchColumn()) {
                flash($isPlan ? 'แผนงานนี้ยังมีงานและโครงการอยู่ ลบไม่ได้' : 'งานและโครงการนี้มีรายการรับเข้า/เบิกจ่ายแล้ว ลบไม่ได้ — ใช้ "ปิดใช้งาน" แทน', 'warning');
            } else {
                $pdo->prepare('DELETE FROM ' . ($isPlan ? 'bud_plans' : 'bud_works') . ' WHERE id = ?')->execute([$id]);
                audit_log('bud_' . $action, 'ลบ' . $label . ' #' . $id, BUD_MODULE);
                flash('ลบ' . $label . 'แล้ว');
            }
            redirect('items.php');
        }
        if ($name === '') {
            flash('กรุณาระบุชื่อ' . $label, 'warning');
            redirect('items.php');
        }
        $dup = $pdo->prepare($isPlan ? 'SELECT id FROM bud_plans WHERE name = ? AND id <> ?' : 'SELECT id FROM bud_works WHERE name = ? AND id <> ? AND plan_id = ' . $planId);
        $dup->execute([$name, str_ends_with($action, '_save') ? $id : 0]);
        if ($dup->fetch()) {
            flash('มี' . $label . 'ชื่อ "' . $name . '" แล้ว', 'warning');
            redirect('items.php');
        }
        $name = mb_substr($name, 0, 255);
        if ($action === 'plan_add') {
            $pdo->prepare('INSERT INTO bud_plans (name, sort_order) SELECT ?, COALESCE(MAX(sort_order), 0) + 10 FROM bud_plans')->execute([$name]);
        } elseif ($action === 'plan_save') {
            $pdo->prepare('UPDATE bud_plans SET name = ?, sort_order = ?, is_active = ? WHERE id = ?')->execute([$name, (int)post('sort_order'), post('is_active') ? 1 : 0, $id]);
        } elseif ($action === 'work_add') {
            $pdo->prepare('INSERT INTO bud_works (plan_id, name, sort_order) SELECT ?, ?, COALESCE(MAX(sort_order), 0) + 10 FROM bud_works WHERE plan_id = ?')->execute([$planId, $name, $planId]);
        } else {
            $pdo->prepare('UPDATE bud_works SET plan_id = ?, name = ?, sort_order = ?, is_active = ? WHERE id = ?')->execute([$planId, $name, (int)post('sort_order'), post('is_active') ? 1 : 0, $id]);
        }
        audit_log('bud_' . $action, (str_ends_with($action, '_add') ? 'เพิ่ม' : 'แก้ไข') . $label . ': ' . $name, BUD_MODULE);
        flash((str_ends_with($action, '_add') ? 'เพิ่ม' : 'บันทึก') . $label . ' "' . $name . '" แล้ว');
        redirect('items.php');
    }
    $pid = int_param('project_id') ?: null;
    if ($pid && !isset(projects_all()[$pid])) {
        $pid = null;
    }
    if ($action === 'add' || $action === 'save') {
        if ($name === '') {
            flash('กรุณาระบุชื่อหมวด', 'warning');
            redirect('items.php');
        }
        $dup = $pdo->prepare('SELECT id FROM bud_items WHERE name = ? AND id <> ?');
        $dup->execute([$name, $action === 'save' ? int_param('id') : 0]);
        if ($dup->fetch()) {
            flash('มีหมวดชื่อ "' . $name . '" แล้ว', 'warning');
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
        audit_log('bud_item_add', 'เพิ่มหมวด: ' . $name, BUD_MODULE);
        flash('เพิ่มรายการ "' . $name . '" แล้ว');
    } elseif ($action === 'save') {
        $pdo->prepare('UPDATE bud_items SET name = ?, is_other = ?, project_id = ?, sort_order = ?, is_active = ? WHERE id = ?')
            ->execute([mb_substr($name, 0, 150), post('is_other') ? 1 : 0, $pid, (int)post('sort_order'), post('is_active') ? 1 : 0, int_param('id')]);
        audit_log('bud_item_save', 'แก้ไขหมวด: ' . $name, BUD_MODULE);
        flash('บันทึก "' . $name . '" แล้ว');
    } elseif ($action === 'delete') {
        $id = int_param('id');
        $used = $pdo->prepare('SELECT COUNT(*) FROM bud_txns WHERE item_id = ?');
        $used->execute([$id]);
        if ($used->fetchColumn()) {
            flash('รายการนี้มีข้อมูลรับเข้า/เบิกจ่ายแล้ว ลบไม่ได้ — ใช้ "ปิดใช้งาน" แทน', 'warning');
        } else {
            $pdo->prepare('DELETE FROM bud_items WHERE id = ?')->execute([$id]);
            audit_log('bud_item_delete', 'ลบหมวด #' . $id, BUD_MODULE);
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
$plans = bud_plans();
$works = bud_works();
$planOptions = function (?int $sel) use ($plans): string {
    $o = '';
    foreach ($plans as $p) {
        $o .= '<option value="' . (int)$p['id'] . '"' . ((int)$p['id'] === $sel ? ' selected' : '') . '>' . h($p['name']) . '</option>';
    }
    return $o;
};
$workUsed = [];
foreach ($pdo->query('SELECT work_id, COUNT(*) n FROM bud_txns WHERE work_id IS NOT NULL GROUP BY work_id') as $r) {
    $workUsed[(int)$r['work_id']] = (int)$r['n'];
}
$pageTitle = 'แผนงานและหมวด';
$active = 'items';
require __DIR__ . '/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-xl-8">
    <div class="card">
      <div class="card-header"><strong>แผนงาน → งานและโครงการ</strong> <span class="small text-muted">— ตัวคลุมของหมวด ใช้ในทะเบียนคุมเงินประจำงวดส่วนจังหวัด</span></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th style="width:5rem">ลำดับ</th><th>ชื่อ</th><th style="width:14rem">อยู่ในแผนงาน</th><th class="text-center">ใช้งาน</th><th class="text-end">ข้อมูล</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($plans as $p): $f = 'p' . $p['id']; $pw = array_filter($works, fn($w) => (int)$w['plan_id'] === (int)$p['id']); ?>
            <tr class="table-light<?= $p['is_active'] ? '' : ' text-muted' ?>">
              <td><input form="<?= $f ?>" type="number" name="sort_order" value="<?= (int)$p['sort_order'] ?>" class="form-control form-control-sm"></td>
              <td colspan="2"><div class="input-group input-group-sm"><span class="input-group-text">แผนงาน</span><input form="<?= $f ?>" type="text" name="name" value="<?= h($p['name']) ?>" class="form-control fw-semibold" maxlength="255" required></div></td>
              <td class="text-center"><input form="<?= $f ?>" type="checkbox" name="is_active" value="1" class="form-check-input"<?= $p['is_active'] ? ' checked' : '' ?>></td>
              <td class="text-end small"><?= count($pw) ?> งาน</td>
              <td class="text-end text-nowrap">
                <form method="post" id="<?= $f ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="plan_save"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm btn-primary" title="บันทึก"><i class="bi bi-check-lg"></i></button></form>
                <?php if (!$pw): ?><form method="post" class="d-inline" data-confirm="<?= h('ลบแผนงาน "' . $p['name'] . '"?') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="plan_delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm btn-light text-danger" title="ลบ"><i class="bi bi-trash"></i></button></form><?php endif; ?>
              </td>
            </tr>
            <?php foreach ($pw as $w): $f = 'w' . $w['id']; ?>
            <tr class="<?= $w['is_active'] ? '' : 'text-muted' ?>">
              <td class="ps-3"><input form="<?= $f ?>" type="number" name="sort_order" value="<?= (int)$w['sort_order'] ?>" class="form-control form-control-sm"></td>
              <td class="ps-4"><input form="<?= $f ?>" type="text" name="name" value="<?= h($w['name']) ?>" class="form-control form-control-sm" maxlength="255" required></td>
              <td><select form="<?= $f ?>" name="plan_id" class="form-select form-select-sm"><?= $planOptions((int)$w['plan_id']) ?></select></td>
              <td class="text-center"><input form="<?= $f ?>" type="checkbox" name="is_active" value="1" class="form-check-input"<?= $w['is_active'] ? ' checked' : '' ?>></td>
              <td class="text-end small"><?= !empty($workUsed[(int)$w['id']]) ? number_format($workUsed[(int)$w['id']]) . ' รายการ' : '-' ?></td>
              <td class="text-end text-nowrap">
                <form method="post" id="<?= $f ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="work_save"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                  <button class="btn btn-sm btn-primary" title="บันทึก"><i class="bi bi-check-lg"></i></button></form>
                <?php if (empty($workUsed[(int)$w['id']])): ?><form method="post" class="d-inline" data-confirm="<?= h('ลบงานและโครงการ "' . $w['name'] . '"?') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="work_delete"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                  <button class="btn btn-sm btn-light text-danger" title="ลบ"><i class="bi bi-trash"></i></button></form><?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php if (!$plans): ?><tr><td colspan="6" class="text-center text-muted py-3">ยังไม่มีแผนงาน — เพิ่มแผนงาน แล้วเพิ่มงานและโครงการภายใต้แผนงาน</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <form method="post" class="card mb-3">
      <?= csrf_field() ?><input type="hidden" name="action" value="plan_add">
      <div class="card-header"><strong><i class="bi bi-plus-lg"></i> เพิ่มแผนงาน</strong></div>
      <div class="card-body d-flex gap-2"><input type="text" name="name" class="form-control" placeholder="ชื่อแผนงาน" maxlength="255" required><button class="btn btn-primary text-nowrap">เพิ่ม</button></div>
    </form>
    <?php if ($plans): ?>
    <form method="post" class="card">
      <?= csrf_field() ?><input type="hidden" name="action" value="work_add">
      <div class="card-header"><strong><i class="bi bi-plus-lg"></i> เพิ่มงานและโครงการ</strong></div>
      <div class="card-body">
        <label class="form-label small mb-0">ภายใต้แผนงาน</label>
        <select name="plan_id" class="form-select mb-2" required><?= $planOptions(array_key_last($plans)) ?></select>
        <div class="d-flex gap-2"><input type="text" name="name" class="form-control" placeholder="ชื่องานและโครงการ" maxlength="255" required><button class="btn btn-primary text-nowrap">เพิ่ม</button></div>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card">
      <div class="card-header"><strong>หมวด</strong> <span class="small text-muted">— หมวดรายจ่าย / รายจ่ายอื่น ใช้ร่วมกับหมวดโครงการสำรวจ</span></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th style="width:5rem">ลำดับ</th><th>ชื่อหมวด</th><th class="text-center">รายจ่ายอื่น</th><th>ผูกโครงการ</th><th class="text-center">ใช้งาน</th><th class="text-end">ข้อมูล</th><th></th></tr></thead>
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
      <div class="card-header"><strong><i class="bi bi-plus-lg"></i> เพิ่มหมวด</strong></div>
      <div class="card-body">
        <input type="text" name="name" class="form-control mb-2" placeholder="ชื่อหมวด เช่น ค่าซ่อมแซมครุภัณฑ์" maxlength="150" required>
        <div class="form-check mb-2"><input type="checkbox" name="is_other" value="1" id="newOther" class="form-check-input"><label for="newOther" class="form-check-label">รายจ่ายอื่น (โครงการนอกสำนักงานสถิติแห่งชาติ)</label></div>
        <label class="form-label small mb-0">ผูกกับโครงการสำรวจ (ไม่บังคับ)</label>
        <select name="project_id" class="form-select mb-3"><?= $projOptions(null) ?></select>
        <button class="btn btn-primary"><i class="bi bi-plus-lg"></i> เพิ่ม</button>
      </div>
    </form>
    <div class="card">
      <div class="card-header"><strong>หมวดโครงการสำรวจ</strong> <span class="small text-muted">(จากหน้าโครงการที่รับผิดชอบ)</span></div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($survey as $p): ?><li class="list-group-item"><span class="bud-dot" style="background:<?= h($p['color']) ?>"></span><b><?= h($p['code']) ?></b> <?= h($p['name'] !== $p['code'] ? $p['name'] : '') ?></li><?php endforeach; ?>
        <?php if (!$survey): ?><li class="list-group-item text-muted">ยังไม่มีโครงการ</li><?php endif; ?>
      </ul>
      <div class="card-footer small"><a href="<?= h(module_url('ea', 'projects.php')) ?>">จัดการโครงการที่รับผิดชอบ <i class="bi bi-box-arrow-up-right"></i></a></div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
