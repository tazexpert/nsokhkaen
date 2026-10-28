<?php
require_once __DIR__ . '/bootstrap.php';
require_module(DCC_MODULE, 'editor');
$pdo = db();
$id = int_param('id');
$fields = ['name', 'position', 'phone', 'email', 'line_id', 'bio'];
$s = array_fill_keys($fields, '') + ['photo' => '', 'sort_order' => 0, 'center_id' => int_param('center_id')];

if ($id) {
    $st = $pdo->prepare('SELECT * FROM dcc_center_staff WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch() ?: redirect('centers.php');
}
$st = $pdo->prepare('SELECT id, name FROM dcc_centers WHERE id = ?');
$st->execute([$s['center_id']]);
$center = $st->fetch();
if (!$center) {
    flash('ไม่พบศูนย์', 'danger');
    redirect('centers.php');
}
$centerId = (int)$center['id'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    foreach ($fields as $f) {
        $s[$f] = post($f);
    }
    $s['sort_order'] = (int)post('sort_order', '0');
    if ($s['name'] === '') {
        $errors[] = 'กรุณากรอกชื่อผู้ดูแล';
    }
    if (!$errors) {
        $photo = null;
        try {
            $photo = save_upload($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'digitalcc/staff');
            $old = $s['photo'];
            $data = array_intersect_key($s, array_flip($fields)) + ['sort_order' => $s['sort_order']];
            if ($photo) {
                $data['photo'] = $photo;
            } elseif (isset($_POST['remove_photo'])) {
                $data['photo'] = '';
            }
            $cols = array_keys($data);
            if ($id) {
                $set = implode(', ', array_map(fn($k) => "$k = ?", $cols));
                $pdo->prepare("UPDATE dcc_center_staff SET $set WHERE id = ?")->execute([...array_values($data), $id]);
            } else {
                $cols[] = 'center_id';
                $vals = [...array_values($data), $centerId];
                $pdo->prepare('INSERT INTO dcc_center_staff (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute($vals);
            }
            if (($photo || isset($_POST['remove_photo'])) && $old) {
                delete_upload($old);
            }
            flash('บันทึกข้อมูลผู้ดูแลเรียบร้อยแล้ว');
            redirect("center_form.php?id=$centerId#staff");
        } catch (Throwable $e) {
            delete_upload($photo);
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

$pageTitle = ($id ? 'แก้ไขผู้ดูแล' : 'เพิ่มผู้ดูแล') . ' · ' . $center['name'];
$active = 'centers';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card" style="max-width:860px">
  <?= csrf_field() ?>
  <div class="card-body row g-3">
    <div class="col-md-4 text-center">
      <?php if ($s['photo']): ?>
        <img src="<?= h(upload_url($s['photo'])) ?>" class="staff-photo-lg mb-2" alt="">
        <div class="form-check d-inline-block mb-2"><input class="form-check-input" type="checkbox" name="remove_photo" id="rp">
          <label class="form-check-label small" for="rp">ลบรูป</label></div>
      <?php else: ?>
        <span class="staff-photo-lg avatar-empty mb-2"><i class="bi bi-person"></i></span>
      <?php endif; ?>
      <input type="file" name="photo" accept="image/*" class="form-control form-control-sm" data-preview="photoNew">
      <div id="photoNew" class="preview-grid mt-2 justify-content-center"></div>
    </div>
    <div class="col-md-8 row g-3 align-content-start">
      <div class="col-12"><label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
        <input name="name" class="form-control" required value="<?= h($s['name']) ?>"></div>
      <div class="col-md-8"><label class="form-label">ตำแหน่ง / บทบาท</label>
        <input name="position" class="form-control" value="<?= h($s['position']) ?>" placeholder="เช่น ผู้ดูแลศูนย์, อาสาสมัคร"></div>
      <div class="col-md-4"><label class="form-label">ลำดับการแสดง</label>
        <input name="sort_order" type="number" class="form-control" value="<?= (int)$s['sort_order'] ?>"></div>
      <div class="col-md-4"><label class="form-label">โทรศัพท์</label>
        <input name="phone" class="form-control" value="<?= h($s['phone']) ?>"></div>
      <div class="col-md-4"><label class="form-label">อีเมล</label>
        <input name="email" type="email" class="form-control" value="<?= h($s['email']) ?>"></div>
      <div class="col-md-4"><label class="form-label">LINE ID</label>
        <input name="line_id" class="form-control" value="<?= h($s['line_id']) ?>"></div>
      <div class="col-12"><label class="form-label">รายละเอียด / ความเชี่ยวชาญ</label>
        <textarea name="bio" rows="4" class="form-control"><?= h($s['bio']) ?></textarea></div>
    </div>
  </div>
  <div class="card-footer">
    <button class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button>
    <a href="center_form.php?id=<?= $centerId ?>#staff" class="btn btn-light">กลับ</a>
  </div>
</form>
<?php require __DIR__ . '/footer.php'; ?>
