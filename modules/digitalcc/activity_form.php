<?php
require_once __DIR__ . '/bootstrap.php';
require_module(DCC_MODULE, 'editor');
$pdo = db();
$id = int_param('id');
$fields = ['title', 'activity_date', 'organizer', 'description'];
$a = array_fill_keys($fields, '') + ['center_id' => int_param('center_id'), 'participants' => 0, 'cover_image' => ''];

if ($id) {
    $st = $pdo->prepare('SELECT * FROM dcc_activities WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) {
        flash('ไม่พบกิจกรรม', 'danger');
        redirect('activities.php');
    }
}
$centers = $pdo->query('SELECT id, name, district FROM dcc_centers ORDER BY district, name')->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    foreach ($fields as $f) {
        $a[$f] = post($f);
    }
    $a['center_id'] = (int)post('center_id');
    $a['participants'] = max(0, (int)post('participants'));
    if ($a['title'] === '') {
        $errors[] = 'กรุณากรอกชื่อกิจกรรม';
    }
    if (!in_array($a['center_id'], array_map('intval', array_column($centers, 'id')), true)) {
        $errors[] = 'กรุณาเลือกศูนย์ดิจิทัลชุมชน';
    }
    if ($a['activity_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['activity_date'])) {
        $errors[] = 'รูปแบบวันที่ไม่ถูกต้อง';
    }

    $newFiles = [];
    if (!$errors) {
        try {
            $cover = save_upload($_FILES['cover_image'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'digitalcc/activities');
            if ($cover) {
                $newFiles[] = $cover;
            }
            $gallery = [];
            foreach (files_array('images') as $f) {
                if ($p = save_upload($f, 'digitalcc/activities')) {
                    $gallery[] = $p;
                    $newFiles[] = $p;
                }
            }
            $pdo->beginTransaction();
            $data = [
                'center_id'     => $a['center_id'],
                'title'         => $a['title'],
                'activity_date' => $a['activity_date'] ?: null,
                'organizer'     => $a['organizer'],
                'participants'  => $a['participants'],
                'description'   => $a['description'],
            ];
            $oldCover = $a['cover_image'];
            if ($cover) {
                $data['cover_image'] = $cover;
            } elseif (isset($_POST['remove_cover'])) {
                $data['cover_image'] = '';
            }
            $cols = array_keys($data);
            if ($id) {
                $set = implode(', ', array_map(fn($k) => "$k = ?", $cols));
                $pdo->prepare("UPDATE dcc_activities SET $set WHERE id = ?")->execute([...array_values($data), $id]);
            } else {
                $pdo->prepare('INSERT INTO dcc_activities (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO dcc_activity_images (activity_id, file) VALUES (?, ?)');
            foreach ($gallery as $g) {
                $ins->execute([$id, $g]);
            }
            $removed = [];
            $del = array_filter(array_map('intval', (array)($_POST['delete_images'] ?? [])));
            if ($del) {
                $in = implode(',', array_fill(0, count($del), '?'));
                $st = $pdo->prepare("SELECT file FROM dcc_activity_images WHERE activity_id = ? AND id IN ($in)");
                $st->execute([$id, ...$del]);
                $removed = $st->fetchAll(PDO::FETCH_COLUMN);
                $pdo->prepare("DELETE FROM dcc_activity_images WHERE activity_id = ? AND id IN ($in)")->execute([$id, ...$del]);
            }
            $cap = $pdo->prepare('UPDATE dcc_activity_images SET caption = ? WHERE id = ? AND activity_id = ?');
            foreach ((array)($_POST['captions'] ?? []) as $imgId => $text) {
                $cap->execute([mb_substr(trim((string)$text), 0, 255), (int)$imgId, $id]);
            }
            $pdo->commit();
            if (($cover || isset($_POST['remove_cover'])) && $oldCover) {
                delete_upload($oldCover);
            }
            array_map('delete_upload', $removed);
            flash('บันทึกกิจกรรมเรียบร้อยแล้ว');
            redirect("activity_form.php?id=$id");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            array_map('delete_upload', $newFiles);
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

$images = [];
if ($id) {
    $st = $pdo->prepare('SELECT * FROM dcc_activity_images WHERE activity_id = ? ORDER BY id');
    $st->execute([$id]);
    $images = $st->fetchAll();
}

$pageTitle = $id ? 'แก้ไขกิจกรรม' : 'เพิ่มกิจกรรม';
$active = 'activities';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<?php if (!$centers): ?>
  <div class="alert alert-info">ยังไม่มีศูนย์ในระบบ — <a href="center_form.php">เพิ่มศูนย์ก่อน</a></div>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-xl-7">
    <div class="card">
      <div class="card-header"><strong><i class="bi bi-calendar-event"></i> รายละเอียดกิจกรรม</strong></div>
      <div class="card-body row g-3">
        <div class="col-12"><label class="form-label">ศูนย์ดิจิทัลชุมชน <span class="text-danger">*</span></label>
          <select name="center_id" class="form-select" required>
            <option value="">— เลือกศูนย์ —</option>
            <?php foreach ($centers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $c['id'] == $a['center_id'] ? 'selected' : '' ?>><?= h($c['name']) ?> (อ.<?= h($c['district']) ?>)</option><?php endforeach; ?>
          </select></div>
        <div class="col-12"><label class="form-label">ชื่อกิจกรรม <span class="text-danger">*</span></label>
          <input name="title" class="form-control" required value="<?= h($a['title']) ?>"></div>
        <div class="col-md-4"><label class="form-label">วันที่จัด</label>
          <input name="activity_date" type="date" class="form-control" value="<?= h($a['activity_date']) ?>"></div>
        <div class="col-md-4"><label class="form-label">จำนวนผู้เข้าร่วม (คน)</label>
          <input name="participants" type="number" min="0" class="form-control" value="<?= (int)$a['participants'] ?>"></div>
        <div class="col-md-4"><label class="form-label">หน่วยงานผู้จัด / ร่วมจัด</label>
          <input name="organizer" class="form-control" value="<?= h($a['organizer']) ?>"></div>
        <div class="col-12"><label class="form-label">รายละเอียดกิจกรรม</label>
          <textarea name="description" rows="7" class="form-control"><?= h($a['description']) ?></textarea></div>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card">
      <div class="card-header"><strong><i class="bi bi-image"></i> ภาพหน้าปกกิจกรรม</strong></div>
      <div class="card-body">
        <?php if ($a['cover_image']): ?>
          <img src="<?= h(upload_url($a['cover_image'])) ?>" class="img-fluid rounded mb-2 cover-preview" alt="">
          <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_cover" id="rc">
            <label class="form-check-label small" for="rc">ลบภาพหน้าปก</label></div>
        <?php endif; ?>
        <input type="file" name="cover_image" accept="image/*" class="form-control" data-preview="coverNew">
        <div id="coverNew" class="preview-grid mt-2"></div>
        <p class="small text-muted mt-2 mb-0">ถ้าไม่ใส่ภาพหน้าปก ระบบจะใช้ภาพแรกของกิจกรรมแทน</p>
      </div>
    </div>
  </div>
  <div class="col-12">
    <div class="card">
      <div class="card-header"><strong><i class="bi bi-images"></i> ภาพกิจกรรม</strong></div>
      <div class="card-body">
        <?php if ($images): ?>
          <div class="gallery-admin mb-3">
            <?php foreach ($images as $img): ?>
              <div class="gallery-item">
                <img src="<?= h(upload_url($img['file'])) ?>" alt="">
                <input name="captions[<?= (int)$img['id'] ?>]" class="form-control form-control-sm" placeholder="คำบรรยายภาพ" value="<?= h($img['caption']) ?>">
                <label class="del"><input type="checkbox" name="delete_images[]" value="<?= (int)$img['id'] ?>"> ลบ</label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <label class="form-label">เพิ่มภาพ (เลือกได้หลายไฟล์)</label>
        <input type="file" name="images[]" accept="image/*" multiple class="form-control" data-preview="imagesNew">
        <div id="imagesNew" class="preview-grid mt-2"></div>
      </div>
    </div>
  </div>
  <div class="col-12 sticky-actions">
    <button class="btn btn-primary btn-lg"><i class="bi bi-save"></i> บันทึกกิจกรรม</button>
    <a href="activities.php" class="btn btn-light btn-lg">กลับ</a>
    <?php if ($id): ?><a href="public/center.html?id=<?= (int)$a['center_id'] ?>#activities" target="_blank" class="btn btn-outline-secondary btn-lg ms-auto"><i class="bi bi-eye"></i> ดูหน้าเว็บ</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
