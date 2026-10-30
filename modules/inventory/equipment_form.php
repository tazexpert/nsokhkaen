<?php
/** เพิ่ม/แก้ไข/ลบ ครุภัณฑ์ (ผู้ดูแลระบบวัสดุและครุภัณฑ์) */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(INV_MODULE, 'admin');
$pdo = db();
$id = int_param('id');

$e = ['asset_no' => '', 'type_id' => null, 'brand' => '', 'model' => '', 'serial_number' => '', 'fiscal_year' => '',
      'status' => 'normal', 'storage_location' => '', 'assigned_user_id' => null, 'image_path' => null, 'note' => ''];
if ($id) {
    $st = $pdo->prepare('SELECT * FROM inv_equipment WHERE id = ?');
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e) {
        flash('ไม่พบครุภัณฑ์', 'danger');
        redirect('equipment.php');
    }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('action') === 'delete' && $id) {
        if (active_loans_by_equipment([$id])) {
            flash('ลบไม่ได้ — ครุภัณฑ์นี้อยู่ในใบยืมที่ยังไม่ปิด', 'danger');
            redirect('equipment_view.php?id=' . $id);
        }
        $pdo->prepare('DELETE FROM inv_equipment WHERE id = ?')->execute([$id]);
        delete_upload($e['image_path']);
        audit_log('equipment_delete', '#' . $id . ' ' . ($e['asset_no'] ?? '') . ' ' . $e['model'], INV_MODULE);
        flash('ลบครุภัณฑ์เรียบร้อยแล้ว (ประวัติการยืมยังเก็บไว้ในใบยืม)');
        redirect('equipment.php');
    }

    $typeName = post('type_new');
    $e = array_merge($e, [
        'asset_no' => post('asset_no'), 'type_id' => int_param('type_id') ?: null,
        'brand' => post('brand'), 'model' => post('model'), 'serial_number' => post('serial_number'),
        'fiscal_year' => post('fiscal_year'), 'status' => post('status'),
        'storage_location' => post('storage_location'), 'note' => post('note'),
        'assigned_user_id' => int_param('assigned_user_id') ?: null,
    ]);
    if ($typeName !== '') {
        $e['type_id'] = equipment_type_id($pdo, $typeName);
    }
    if (!$e['type_id']) {
        $errors[] = 'กรุณาเลือกประเภทครุภัณฑ์';
    }
    if ($e['model'] === '' && $e['brand'] === '') {
        $errors[] = 'กรุณากรอกยี่ห้อหรือรุ่น';
    }
    if ($e['assigned_user_id']) {
        $chk = $pdo->prepare('SELECT 1 FROM core_users WHERE id = ?');
        $chk->execute([$e['assigned_user_id']]);
        if (!$chk->fetchColumn()) {
            $e['assigned_user_id'] = null;
        }
    }
    if (!isset(EQ_STATUS[$e['status']])) {
        $errors[] = 'สภาพการใช้งานไม่ถูกต้อง';
    }
    $fy = $e['fiscal_year'];
    if ($fy !== '' && (!ctype_digit($fy) || $fy < 2500 || $fy > 2700)) {
        $errors[] = 'ปีงบประมาณต้องเป็นปี พ.ศ. 4 หลัก เช่น 2568';
    }
    if ($e['asset_no'] !== '') {
        $st = $pdo->prepare('SELECT id FROM inv_equipment WHERE asset_no = ? AND id <> ?');
        $st->execute([$e['asset_no'], $id]);
        if ($st->fetchColumn()) {
            $errors[] = 'เลขครุภัณฑ์นี้มีอยู่ในระบบแล้ว';
        }
    }

    if (!$errors) {
        $newImage = null;
        if (!empty($_FILES['image']['name'])) {
            try {
                $newImage = save_upload($_FILES['image'], 'inventory/equipment');
            } catch (RuntimeException $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }
    if (!$errors) {
        $data = [
            'asset_no' => $e['asset_no'] ?: null, 'type_id' => $e['type_id'], 'brand' => $e['brand'] ?: null,
            'model' => $e['model'] ?: null, 'serial_number' => $e['serial_number'] ?: null,
            'fiscal_year' => $fy !== '' ? (int)$fy : null, 'status' => $e['status'],
            'storage_location' => $e['storage_location'] ?: null, 'note' => $e['note'] ?: null,
            'assigned_user_id' => $e['assigned_user_id'],
        ];
        if ($newImage || post('remove_image')) {
            delete_upload($e['image_path']);
            $data['image_path'] = $newImage;
        }
        $cols = array_keys($data);
        if ($id) {
            $pdo->prepare('UPDATE inv_equipment SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')
                ->execute([...array_values($data), $id]);
            audit_log('equipment_update', '#' . $id . ' ' . ($data['asset_no'] ?? ''), INV_MODULE);
        } else {
            $data['created_by'] = $me['id'];
            $cols = array_keys($data);
            $pdo->prepare('INSERT INTO inv_equipment (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($data));
            $id = (int)$pdo->lastInsertId();
            audit_log('equipment_create', '#' . $id . ' ' . ($data['asset_no'] ?? ''), INV_MODULE);
        }
        flash('บันทึกข้อมูลครุภัณฑ์เรียบร้อยแล้ว');
        redirect(post('save_new') ? 'equipment_form.php' : 'equipment_view.php?id=' . $id);
    }
}

$types = equipment_types();
$users = array_column(sort_staff($pdo->query('SELECT id, username, full_name, position FROM core_users WHERE is_active = 1 OR id = ' . (int)$e['assigned_user_id'])->fetchAll()), 'full_name', 'id');   // [id => ชื่อ] เรียงตามลำดับตำแหน่ง
$locations = $pdo->query('SELECT name FROM inv_storage_locations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$pageTitle = $id ? 'แก้ไขครุภัณฑ์' : 'เพิ่มครุภัณฑ์';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-xl-8">
    <div class="card">
      <div class="card-body row g-3">
        <div class="col-md-6"><label class="form-label">เลขครุภัณฑ์</label>
          <input name="asset_no" class="form-control font-monospace" value="<?= h($e['asset_no']) ?>" placeholder="เช่น 1105-0954-0293/68">
          <div class="form-text">เว้นว่างได้ กรณีรับเข้าใหม่ยังไม่ได้ออกเลข</div></div>
        <div class="col-md-6"><label class="form-label">ประเภทครุภัณฑ์ <span class="text-danger">*</span></label>
          <select name="type_id" class="form-select" id="typeSel">
            <option value="">— เลือก —</option>
            <?php foreach ($types as $tid => $tname): ?><option value="<?= (int)$tid ?>" <?= (int)$e['type_id'] === (int)$tid ? 'selected' : '' ?>><?= h($tname) ?></option><?php endforeach; ?>
          </select>
          <input name="type_new" class="form-control form-control-sm mt-1" placeholder="หรือพิมพ์ประเภทใหม่ เช่น เครื่องพิมพ์ (Printer)"></div>
        <div class="col-md-4"><label class="form-label">ยี่ห้อ</label><input name="brand" class="form-control" value="<?= h($e['brand']) ?>"></div>
        <div class="col-md-8"><label class="form-label">รุ่น (Model)</label><input name="model" class="form-control" value="<?= h($e['model']) ?>"></div>
        <div class="col-md-6"><label class="form-label">หมายเลขเครื่อง (Serial no.)</label><input name="serial_number" class="form-control" value="<?= h($e['serial_number']) ?>"></div>
        <div class="col-md-3"><label class="form-label">ปีงบประมาณ (พ.ศ.)</label>
          <input name="fiscal_year" class="form-control" inputmode="numeric" maxlength="4" value="<?= h($e['fiscal_year']) ?>" placeholder="<?= (int)date('Y') + 543 ?>"></div>
        <div class="col-md-3"><label class="form-label">อายุ (ปี)</label>
          <input class="form-control" id="ageOut" disabled value="<?= h(equipment_age($e['fiscal_year'] ? (int)$e['fiscal_year'] : null)) ?>"></div>
        <div class="col-12"><label class="form-label">สภาพการใช้งาน</label>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach (EQ_STATUS as $k => $s): ?>
              <input type="radio" class="btn-check" name="status" id="st_<?= $k ?>" value="<?= $k ?>" <?= $e['status'] === $k ? 'checked' : '' ?>>
              <label class="btn btn-outline-<?= $s[1] ?>" for="st_<?= $k ?>"><i class="bi <?= $s[2] ?>"></i> <?= h($s[0]) ?></label>
            <?php endforeach; ?>
          </div></div>
        <div class="col-md-6"><label class="form-label">ผู้ใช้งานประจำ</label>
          <select name="assigned_user_id" class="form-select">
            <option value="">— ไม่มี (ใช้ส่วนกลาง / ให้ยืม) —</option>
            <?php foreach ($users as $uid => $uname): ?><option value="<?= (int)$uid ?>" <?= (int)$e['assigned_user_id'] === (int)$uid ? 'selected' : '' ?>><?= h($uname) ?></option><?php endforeach; ?>
          </select>
          <div class="form-text">เช่น คอมพิวเตอร์ตั้งโต๊ะที่มีคนนั่งใช้ประจำ — แสดงในคอลัมน์ "ผู้ยืม/ผู้ใช้งาน"</div></div>
        <div class="col-md-6"><label class="form-label">สถานที่เก็บ / ตั้งอยู่ที่</label>
          <input name="storage_location" class="form-control" list="locList" value="<?= h($e['storage_location']) ?>">
          <datalist id="locList"><?php foreach ($locations as $l): ?><option value="<?= h($l) ?>"><?php endforeach; ?></datalist></div>
        <div class="col-12"><label class="form-label">หมายเหตุ</label><input name="note" class="form-control" value="<?= h($e['note']) ?>"></div>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card">
      <div class="card-header"><strong><i class="bi bi-image"></i> รูปภาพ</strong></div>
      <div class="card-body">
        <?php if ($e['image_path']): ?>
          <img src="<?= h(upload_url($e['image_path'])) ?>" class="img-fluid rounded mb-2" alt="">
          <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_image" value="1" id="rmImg"><label class="form-check-label" for="rmImg">ลบรูปเดิม</label></div>
        <?php endif; ?>
        <input type="file" name="image" accept="image/*" class="form-control" data-preview="imgPreview">
        <div id="imgPreview" class="preview-grid mt-2"></div>
      </div>
    </div>
  </div>
  <div class="col-12 sticky-actions">
    <button class="btn btn-primary btn-lg"><i class="bi bi-save"></i> บันทึก</button>
    <?php if (!$id): ?><button class="btn btn-outline-primary btn-lg" name="save_new" value="1">บันทึกและเพิ่มรายการต่อไป</button><?php endif; ?>
    <a href="<?= $id ? 'equipment_view.php?id=' . $id : 'equipment.php' ?>" class="btn btn-light btn-lg">ยกเลิก</a>
  </div>
</form>
<?php if ($id): ?>
  <form method="post" class="mt-3" data-confirm="ลบครุภัณฑ์นี้? (ประวัติการยืมในใบยืมเดิมยังอยู่)">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> ลบครุภัณฑ์นี้</button>
  </form>
<?php endif; ?>
<script>
document.querySelector('[name=fiscal_year]').addEventListener('input', e => {
  const y = parseInt(e.target.value, 10);
  document.getElementById('ageOut').value = y > 2500 ? Math.max(0, new Date().getFullYear() + 543 - y) : '';
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
