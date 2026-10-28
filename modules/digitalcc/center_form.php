<?php
require_once __DIR__ . '/bootstrap.php';
require_module(DCC_MODULE, 'editor');
$pdo = db();
$id = int_param('id');

$fields = ['code', 'name', 'center_type', 'house_no', 'moo', 'address', 'village', 'subdistrict', 'district', 'postcode',
           'phone', 'email', 'facebook', 'open_hours', 'services', 'description'];
$c = array_fill_keys($fields, '') + ['lat' => null, 'lng' => null, 'cover_image' => '', 'is_active' => 1, 'province' => PROVINCE];

if ($id) {
    $st = $pdo->prepare('SELECT * FROM dcc_centers WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) {
        flash('ไม่พบศูนย์ที่ต้องการแก้ไข', 'danger');
        redirect('centers.php');
    }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action', 'save');

    if ($action === 'delete_staff') {
        $sid = int_param('staff_id');
        $st = $pdo->prepare('SELECT photo FROM dcc_center_staff WHERE id = ? AND center_id = ?');
        $st->execute([$sid, $id]);
        if (($photo = $st->fetchColumn()) !== false) {
            $pdo->prepare('DELETE FROM dcc_center_staff WHERE id = ?')->execute([$sid]);
            delete_upload($photo);
            flash('ลบผู้ดูแลเรียบร้อยแล้ว');
        }
        redirect("center_form.php?id=$id#staff");
    }

    foreach ($fields as $f) {
        $c[$f] = post($f);
    }
    $c['lat'] = coord(post('lat'), -90, 90);
    $c['lng'] = coord(post('lng'), -180, 180);
    $c['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    if ($c['name'] === '') {
        $errors[] = 'กรุณากรอกชื่อศูนย์';
    }
    if ($c['code'] !== '') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM dcc_centers WHERE code = ? AND id <> ?');
        $st->execute([$c['code'], $id]);
        if ($st->fetchColumn()) {
            $errors[] = 'รหัสศูนย์ ' . $c['code'] . ' มีอยู่แล้ว';
        }
    }
    if (post('lat') !== '' && $c['lat'] === null || post('lng') !== '' && $c['lng'] === null) {
        $errors[] = 'พิกัด lat/long ไม่ถูกต้อง';
    }

    $newFiles = [];
    if (!$errors) {
        try {
            $cover = save_upload($_FILES['cover_image'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'digitalcc/centers');
            if ($cover) {
                $newFiles[] = $cover;
            }
            $gallery = [];
            foreach (files_array('gallery') as $f) {
                if ($p = save_upload($f, 'digitalcc/centers')) {
                    $gallery[] = $p;
                    $newFiles[] = $p;
                }
            }

            $pdo->beginTransaction();
            $data = array_intersect_key($c, array_flip($fields));
            $data['code'] = $data['code'] !== '' ? $data['code'] : null; // รหัสว่าง = NULL (ไม่ชน UNIQUE)
            $data += ['lat' => $c['lat'], 'lng' => $c['lng'], 'is_active' => $c['is_active']];
            $oldCover = $c['cover_image'];
            if ($cover) {
                $data['cover_image'] = $cover;
            } elseif (isset($_POST['remove_cover'])) {
                $data['cover_image'] = '';
            }
            $cols = array_keys($data);
            if ($id) {
                $set = implode(', ', array_map(fn($k) => "$k = ?", $cols));
                $pdo->prepare("UPDATE dcc_centers SET $set WHERE id = ?")->execute([...array_values($data), $id]);
            } else {
                $ph = implode(', ', array_fill(0, count($cols), '?'));
                $pdo->prepare('INSERT INTO dcc_centers (' . implode(', ', $cols) . ") VALUES ($ph)")->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
            }

            $ins = $pdo->prepare('INSERT INTO dcc_center_images (center_id, file) VALUES (?, ?)');
            foreach ($gallery as $g) {
                $ins->execute([$id, $g]);
            }

            // ลบภาพในแกลเลอรีที่เลือก
            $removed = [];
            $del = array_filter(array_map('intval', (array)($_POST['delete_images'] ?? [])));
            if ($del) {
                $in = implode(',', array_fill(0, count($del), '?'));
                $st = $pdo->prepare("SELECT file FROM dcc_center_images WHERE center_id = ? AND id IN ($in)");
                $st->execute([$id, ...$del]);
                $removed = $st->fetchAll(PDO::FETCH_COLUMN);
                $pdo->prepare("DELETE FROM dcc_center_images WHERE center_id = ? AND id IN ($in)")->execute([$id, ...$del]);
            }
            // แก้ไขคำบรรยายภาพ
            $cap = $pdo->prepare('UPDATE dcc_center_images SET caption = ? WHERE id = ? AND center_id = ?');
            foreach ((array)($_POST['captions'] ?? []) as $imgId => $text) {
                $cap->execute([mb_substr(trim((string)$text), 0, 255), (int)$imgId, $id]);
            }
            $pdo->commit();

            if (($cover || isset($_POST['remove_cover'])) && $oldCover) {
                delete_upload($oldCover);
            }
            array_map('delete_upload', $removed);
            flash('บันทึกข้อมูลศูนย์เรียบร้อยแล้ว');
            redirect("center_form.php?id=$id");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            array_map('delete_upload', $newFiles);
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

$images = $staff = [];
if ($id) {
    $st = $pdo->prepare('SELECT * FROM dcc_center_images WHERE center_id = ? ORDER BY id');
    $st->execute([$id]);
    $images = $st->fetchAll();
    $st = $pdo->prepare('SELECT * FROM dcc_center_staff WHERE center_id = ? ORDER BY sort_order, id');
    $st->execute([$id]);
    $staff = $st->fetchAll();
}

$pageTitle = $id ? 'แก้ไขศูนย์: ' . $c['name'] : 'เพิ่มศูนย์ดิจิทัลชุมชน';
$active = 'centers';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-xl-7">
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-info-circle"></i> ข้อมูลศูนย์</strong></div>
      <div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label">รหัสศูนย์</label>
          <input name="code" class="form-control" maxlength="30" value="<?= h($c['code']) ?>" placeholder="เช่น ONDE0728"></div>
        <div class="col-md-8"><label class="form-label">ชื่อศูนย์ <span class="text-danger">*</span></label>
          <input name="name" class="form-control" required value="<?= h($c['name']) ?>" placeholder="เช่น ศูนย์ดิจิทัลชุมชน อบต.บ้านค้อ"></div>
        <div class="col-md-6"><label class="form-label">ประเภทหน่วยงานที่ตั้ง</label>
          <input name="center_type" class="form-control" list="types" value="<?= h($c['center_type']) ?>">
          <datalist id="types"><option>องค์กรปกครองส่วนท้องถิ่น</option><option>สถานศึกษา</option><option>สกร.</option><option>วัด/ชุมชน</option><option>โรงพยาบาลส่งเสริมสุขภาพตำบล</option></datalist></div>
        <div class="col-md-6"><label class="form-label">เวลาเปิดให้บริการ</label>
          <input name="open_hours" class="form-control" value="<?= h($c['open_hours']) ?>" placeholder="จันทร์–ศุกร์ 08:30–16:30"></div>
        <div class="col-4 col-md-3"><label class="form-label">เลขที่</label>
          <input name="house_no" class="form-control" value="<?= h($c['house_no'] ?? '') ?>"></div>
        <div class="col-4 col-md-3"><label class="form-label">หมู่ที่</label>
          <input name="moo" class="form-control" value="<?= h($c['moo'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">หมู่บ้าน</label>
          <input name="village" class="form-control" value="<?= h($c['village']) ?>"></div>
        <div class="col-12"><label class="form-label">ที่อยู่เพิ่มเติม / ถนน</label>
          <input name="address" class="form-control" value="<?= h($c['address']) ?>"></div>
        <div class="col-md-6"><label class="form-label">ตำบล</label>
          <input name="subdistrict" class="form-control" value="<?= h($c['subdistrict']) ?>"></div>
        <div class="col-md-6"><label class="form-label">อำเภอ</label>
          <select name="district" class="form-select">
            <option value="">— เลือกอำเภอ —</option>
            <?php foreach (KK_DISTRICTS as $d): ?><option <?= $d === $c['district'] ? 'selected' : '' ?>><?= h($d) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-6"><label class="form-label">รหัสไปรษณีย์</label>
          <input name="postcode" class="form-control" maxlength="5" value="<?= h($c['postcode']) ?>"></div>
        <div class="col-md-4"><label class="form-label">โทรศัพท์</label>
          <input name="phone" class="form-control" value="<?= h($c['phone']) ?>"></div>
        <div class="col-md-4"><label class="form-label">อีเมล</label>
          <input name="email" type="email" class="form-control" value="<?= h($c['email']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Facebook (URL)</label>
          <input name="facebook" class="form-control" value="<?= h($c['facebook']) ?>"></div>
        <div class="col-12"><label class="form-label">บริการ / อุปกรณ์ในศูนย์ <small class="text-muted">(1 บรรทัดต่อ 1 รายการ)</small></label>
          <textarea name="services" class="form-control" rows="3"><?= h($c['services']) ?></textarea></div>
        <div class="col-12"><label class="form-label">รายละเอียดศูนย์</label>
          <textarea name="description" class="form-control" rows="4"><?= h($c['description']) ?></textarea></div>
        <div class="col-12 form-check form-switch ms-2">
          <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= $c['is_active'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="is_active">แสดงบนหน้าเว็บไซต์</label></div>
      </div>
    </div>
  </div>

  <div class="col-xl-5">
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-geo-alt"></i> พิกัดที่ตั้ง</strong></div>
      <div class="card-body">
        <p class="small text-muted mb-2">คลิกบนแผนที่หรือลากหมุดเพื่อกำหนดตำแหน่ง หรือกรอกค่า lat/long เอง</p>
        <div id="pickerMap" class="picker-map" data-lat="<?= h($c['lat']) ?>" data-lng="<?= h($c['lng']) ?>"></div>
        <div class="row g-2 mt-2">
          <div class="col-6"><label class="form-label small mb-1">Latitude</label>
            <input name="lat" id="lat" class="form-control" value="<?= h($c['lat']) ?>" placeholder="16.4419"></div>
          <div class="col-6"><label class="form-label small mb-1">Longitude</label>
            <input name="lng" id="lng" class="form-control" value="<?= h($c['lng']) ?>" placeholder="102.8360"></div>
        </div>
        <div class="d-flex gap-2 mt-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" id="btnMyLocation"><i class="bi bi-crosshair"></i> ใช้ตำแหน่งปัจจุบัน</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearLoc"><i class="bi bi-x-circle"></i> ล้างพิกัด</button>
        </div>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-image"></i> ภาพหน้าปกศูนย์</strong></div>
      <div class="card-body">
        <?php if ($c['cover_image']): ?>
          <img src="<?= h(upload_url($c['cover_image'])) ?>" class="img-fluid rounded mb-2 cover-preview" alt="">
          <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_cover" id="remove_cover">
            <label class="form-check-label small" for="remove_cover">ลบภาพหน้าปก</label></div>
        <?php endif; ?>
        <input type="file" name="cover_image" accept="image/*" class="form-control" data-preview="coverNew">
        <div id="coverNew" class="preview-grid mt-2"></div>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-images"></i> ภาพศูนย์ฯ (แกลเลอรี)</strong></div>
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
        <input type="file" name="gallery[]" accept="image/*" multiple class="form-control" data-preview="galleryNew">
        <div id="galleryNew" class="preview-grid mt-2"></div>
      </div>
    </div>
  </div>

  <div class="col-12 sticky-actions">
    <button class="btn btn-primary btn-lg"><i class="bi bi-save"></i> บันทึกข้อมูลศูนย์</button>
    <a href="centers.php" class="btn btn-light btn-lg">กลับ</a>
    <?php if ($id): ?><a href="visit_form.php?center_id=<?= $id ?>" class="btn btn-outline-success btn-lg ms-auto"><i class="bi bi-clipboard-check"></i> ตรวจเยี่ยม</a>
    <a href="public/center.html?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary btn-lg"><i class="bi bi-eye"></i> ดูหน้าเว็บ</a><?php endif; ?>
  </div>
</form>

<div class="card mt-4" id="staff">
  <div class="card-header d-flex align-items-center">
    <strong><i class="bi bi-person-badge"></i> ผู้ดูแลศูนย์</strong>
    <?php if ($id): ?><a href="staff_form.php?center_id=<?= $id ?>" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-person-plus"></i> เพิ่มผู้ดูแล</a><?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!$id): ?>
      <p class="text-muted mb-0">บันทึกข้อมูลศูนย์ก่อน จึงจะเพิ่มผู้ดูแลได้</p>
    <?php elseif (!$staff): ?>
      <p class="text-muted mb-0">ยังไม่มีข้อมูลผู้ดูแล</p>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($staff as $s): ?>
          <div class="col-md-6 col-xl-4">
            <div class="staff-admin">
              <?php if ($s['photo']): ?><img src="<?= h(upload_url($s['photo'])) ?>" alt=""><?php else: ?><span class="avatar-empty"><i class="bi bi-person"></i></span><?php endif; ?>
              <div class="flex-grow-1">
                <strong><?= h($s['name']) ?></strong>
                <div class="small text-muted"><?= h($s['position']) ?></div>
                <div class="small"><?= h($s['phone']) ?></div>
              </div>
              <div class="d-flex flex-column gap-1">
                <a href="staff_form.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                <form method="post" data-confirm="ลบผู้ดูแล <?= h($s['name']) ?>?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete_staff"><input type="hidden" name="staff_id" value="<?= (int)$s['id'] ?>">
                  <button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
