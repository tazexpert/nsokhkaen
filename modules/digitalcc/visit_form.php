<?php
/**
 * แบบฟอร์มการตรวจเยี่ยมศูนย์ดิจิทัลชุมชน
 * บันทึก: ผลการตรวจ (visits) + กิจกรรมพร้อมภาพ (activities) + อัปเดตข้อมูลศูนย์/พิกัด/เจ้าหน้าที่
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(DCC_MODULE, 'inspector');
$pdo = db();

$id = int_param('id');
$visit = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM dcc_visits WHERE id = ?');
    $st->execute([$id]);
    $visit = $st->fetch();
    if (!$visit) {
        flash('ไม่พบบันทึกการตรวจเยี่ยม', 'danger');
        redirect('visits.php');
    }
}
$centerId = $visit ? (int)$visit['center_id'] : int_param('center_id');

// ยังไม่ได้เลือกศูนย์ → ไปหน้าค้นหาศูนย์ (กรองตามอำเภอได้)
if (!$centerId) {
    redirect('visits.php#centers');
}

$st = $pdo->prepare('SELECT * FROM dcc_centers WHERE id = ?');
$st->execute([$centerId]);
$c = $st->fetch();
if (!$c) {
    flash('ไม่พบศูนย์', 'danger');
    redirect('visits.php');
}
$st = $pdo->prepare('SELECT * FROM dcc_center_staff WHERE center_id = ? ORDER BY sort_order, id');
$st->execute([$centerId]);
$staffList = $st->fetchAll();
$staff = $staffList[0] ?? ['id' => 0, 'name' => '', 'phone' => '', 'line_id' => '', 'position' => ''];

// ค่าเริ่มต้นของฟอร์ม
$v = $visit ?: [
    'visit_date' => date('Y-m-d'), 'visit_time' => date('H:i'), 'problems' => '', 'suggestions' => '',
    'informant_name' => $staff['name'], 'informant_position' => $staff['position'] ?: 'เจ้าหน้าที่ประจำศูนย์',
    'inspector_name' => $me['full_name'], 'inspector_position' => '', 'activity_id' => null,
];
$d = $visit ? (json_decode((string)$visit['data'], true) ?: []) : [];

// การตรวจครั้งก่อน ๆ ของศูนย์นี้ (ตรวจได้หลายครั้ง)
$st = $pdo->prepare('SELECT id, visit_date, data FROM dcc_visits WHERE center_id = ? ORDER BY visit_date, id');
$st->execute([$centerId]);
$prevVisits = $st->fetchAll();
if ($visit) {
    $visitNo = 1 + count(array_filter($prevVisits, fn($p) => $p['visit_date'] < $visit['visit_date']
        || ($p['visit_date'] === $visit['visit_date'] && (int)$p['id'] < (int)$visit['id'])));
} else {
    $visitNo = count($prevVisits) + 1;
}
$lastVisit = $visit ? null : (end($prevVisits) ?: null);
// ตรวจครั้งใหม่: ใช้คำตอบส่วนที่ 1–3 จากครั้งล่าสุดเป็นค่าเริ่มต้น (เมื่อผู้ใช้เลือก)
$copied = false;
if (!$visit && $lastVisit && !empty($_GET['copy']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $d = json_decode((string)$lastVisit['data'], true) ?: [];
    unset($d['snapshot']);
    $copied = true;
}
$d += ['groups' => [], 'purposes' => [], 'safety' => []];
$val = fn(string $k) => h($d[$k] ?? '');
$is = fn(string $k, $opt) => (($d[$k] ?? '') === $opt) ? 'checked' : '';
$has = fn(string $k, string $opt) => in_array($opt, (array)($d[$k] ?? []), true) ? 'checked' : '';
$flagged = fn(string $k) => !empty($d[$k]) ? 'checked' : '';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $visitDate = post('visit_date');
    $visitTime = post('visit_time');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate)) {
        $errors[] = 'กรุณาระบุวันที่ตรวจเยี่ยม';
    }
    if ($visitTime !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $visitTime)) {
        $visitTime = '';
    }
    $lat = coord(post('lat'), 5, 21);
    $lng = coord(post('lng'), 97, 106);
    if ((post('lat') !== '' && $lat === null) || (post('lng') !== '' && $lng === null)) {
        $errors[] = 'พิกัด lat/long ไม่ถูกต้อง';
    }
    $centerName = post('c_name');
    if ($centerName === '') {
        $errors[] = 'กรุณากรอกชื่อศูนย์';
    }
    $data = visit_data_from_post();
    $typeOpt = post('c_type');
    $centerType = $typeOpt === 'other' ? post('c_type_other') : (isset(VISIT_LOCATION_TYPES[$typeOpt]) ? $typeOpt : $c['center_type']);

    // เก็บค่าที่กรอกไว้แสดงซ้ำถ้ามีข้อผิดพลาด
    $d = $data;
    $v = array_merge($v, [
        'visit_date' => $visitDate, 'visit_time' => $visitTime,
        'problems' => post('problems'), 'suggestions' => post('suggestions'),
        'informant_name' => post('informant_name'), 'informant_position' => post('informant_position'),
        'inspector_name' => post('inspector_name'), 'inspector_position' => post('inspector_position'),
    ]);
    foreach (['house_no', 'village', 'moo', 'address', 'subdistrict', 'district'] as $f) {
        $c[$f] = post('c_' . $f);
    }
    $c['name'] = $centerName;
    $c['center_type'] = $centerType;
    $c['lat'] = $lat;
    $c['lng'] = $lng;
    $staff = array_merge($staff, ['name' => post('staff_name'), 'phone' => post('staff_phone'), 'line_id' => post('staff_line')]);

    $newFiles = [];
    if (!$errors) {
        try {
            $photos = [];
            foreach (files_array('photos') as $f) {
                if ($p = save_upload($f, 'digitalcc/activities')) {
                    $photos[] = $p;
                    $newFiles[] = $p;
                }
            }
            $pdo->beginTransaction();

            // 1) อัปเดตข้อมูลศูนย์ (ที่อยู่ ประเภท พิกัด เวลาเปิด-ปิด)
            $upd = [
                'name' => $c['name'], 'center_type' => $c['center_type'], 'house_no' => $c['house_no'], 'moo' => $c['moo'],
                'village' => $c['village'], 'address' => $c['address'], 'subdistrict' => $c['subdistrict'], 'district' => $c['district'],
            ];
            if ($lat !== null && $lng !== null) {
                $upd['lat'] = $lat;
                $upd['lng'] = $lng;
            }
            if ($data['open_time'] && $data['close_time']) {
                $upd['open_hours'] = $data['open_time'] . '–' . $data['close_time'] . ' น.';
            }
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($upd)));
            $pdo->prepare("UPDATE dcc_centers SET $set WHERE id = ?")->execute([...array_values($upd), $centerId]);

            // 2) เจ้าหน้าที่ประจำศูนย์: อัปเดตคนเดิม หรือเพิ่มคนใหม่
            if ($staff['name'] !== '') {
                if (post('staff_mode') === 'update' && (int)$staff['id'] > 0) {
                    $pdo->prepare('UPDATE dcc_center_staff SET name = ?, phone = ?, line_id = ? WHERE id = ? AND center_id = ?')
                        ->execute([$staff['name'], $staff['phone'], $staff['line_id'], (int)$staff['id'], $centerId]);
                } else {
                    // เพิ่มคนใหม่เป็นลำดับแรก
                    $pdo->prepare('UPDATE dcc_center_staff SET sort_order = sort_order + 1 WHERE center_id = ?')->execute([$centerId]);
                    $pdo->prepare("INSERT INTO dcc_center_staff (center_id, name, position, phone, line_id, sort_order) VALUES (?, ?, 'เจ้าหน้าที่ประจำศูนย์', ?, ?, 0)")
                        ->execute([$centerId, $staff['name'], $staff['phone'], $staff['line_id']]);
                }
            }

            // 3) กิจกรรม (สำหรับแสดงหน้าเว็บพร้อมภาพ)
            $act = [
                'center_id' => $centerId,
                'title' => 'ตรวจเยี่ยมศูนย์ดิจิทัลชุมชน',
                'activity_date' => $visitDate,
                'organizer' => 'สำนักงานสถิติจังหวัดขอนแก่น',
                'participants' => 0,
                'description' => visit_public_summary($data, $v['inspector_name']),
            ];
            $activityId = (int)($visit['activity_id'] ?? 0);
            if ($activityId) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM dcc_activities WHERE id = ?');
                $st->execute([$activityId]);
                $activityId = $st->fetchColumn() ? $activityId : 0;
            }
            if ($activityId) {
                unset($act['organizer'], $act['participants']); // ไม่ทับค่าที่แก้ไขไว้ในหน้ากิจกรรม
                $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($act)));
                $pdo->prepare("UPDATE dcc_activities SET $set WHERE id = ?")->execute([...array_values($act), $activityId]);
            } else {
                $pdo->prepare('INSERT INTO dcc_activities (' . implode(', ', array_keys($act)) . ') VALUES (' . implode(', ', array_fill(0, count($act), '?')) . ')')
                    ->execute(array_values($act));
                $activityId = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO dcc_activity_images (activity_id, file) VALUES (?, ?)');
            foreach ($photos as $p) {
                $ins->execute([$activityId, $p]);
            }
            $removed = [];
            $del = array_filter(array_map('intval', (array)($_POST['delete_images'] ?? [])));
            if ($del) {
                $in = implode(',', array_fill(0, count($del), '?'));
                $st = $pdo->prepare("SELECT file FROM dcc_activity_images WHERE activity_id = ? AND id IN ($in)");
                $st->execute([$activityId, ...$del]);
                $removed = $st->fetchAll(PDO::FETCH_COLUMN);
                $pdo->prepare("DELETE FROM dcc_activity_images WHERE activity_id = ? AND id IN ($in)")->execute([$activityId, ...$del]);
            }

            // 4) บันทึกผลการตรวจเยี่ยม (เก็บสำเนาข้อมูลทั่วไป ณ วันที่ตรวจ ไว้สำหรับพิมพ์)
            $data['snapshot'] = [
                'name' => $c['name'], 'house_no' => $c['house_no'], 'village' => $c['village'], 'moo' => $c['moo'],
                'address' => $c['address'], 'subdistrict' => $c['subdistrict'], 'district' => $c['district'],
                'center_type' => $c['center_type'], 'staff_name' => $staff['name'], 'staff_phone' => $staff['phone'],
                'staff_line' => $staff['line_id'],
            ];
            $row = [
                'center_id' => $centerId, 'activity_id' => $activityId,
                'visit_date' => $visitDate, 'visit_time' => $visitTime ?: null,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'problems' => $v['problems'], 'suggestions' => $v['suggestions'],
                'informant_name' => $v['informant_name'], 'informant_position' => $v['informant_position'],
                'inspector_name' => $v['inspector_name'], 'inspector_position' => $v['inspector_position'],
                'lat' => $lat, 'lng' => $lng,
            ];
            if ($id) {
                $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
                $pdo->prepare("UPDATE dcc_visits SET $set WHERE id = ?")->execute([...array_values($row), $id]);
            } else {
                $row['created_by'] = $me['id'];
                $pdo->prepare('INSERT INTO dcc_visits (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')
                    ->execute(array_values($row));
                $id = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
            array_map('delete_upload', $removed);
            flash('บันทึกการตรวจเยี่ยมเรียบร้อยแล้ว' . ($photos ? ' (เพิ่มภาพ ' . count($photos) . ' ภาพ)' : ''));
            redirect("visit_view.php?id=$id");
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
if (!empty($v['activity_id'])) {
    $st = $pdo->prepare('SELECT * FROM dcc_activity_images WHERE activity_id = ? ORDER BY id');
    $st->execute([$v['activity_id']]);
    $images = $st->fetchAll();
}
$typeSel = visit_type_option((string)$c['center_type']);
$typeOther = $typeSel === '' ? (string)$c['center_type'] : '';

$pageTitle = ($visit ? 'แก้ไขการตรวจเยี่ยม' : 'ตรวจเยี่ยมศูนย์') . ' ครั้งที่ ' . $visitNo . ' · ' . $c['name'];
$active = 'visits';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="visit-form">
  <?= csrf_field() ?>

  <div class="card mb-3 form-head">
    <div class="card-body">
      <div class="text-center mb-3">
        <h2 class="h5 mb-0">แบบฟอร์มการตรวจเยี่ยมศูนย์ดิจิทัลชุมชน</h2>
        <div class="text-muted">จังหวัด<?= h(PROVINCE) ?></div>
        <span class="badge text-bg-success mt-1">การตรวจเยี่ยมครั้งที่ <?= $visitNo ?></span>
      </div>
      <?php if ($lastVisit): ?>
        <div class="alert alert-<?= $copied ? 'success' : 'info' ?> small py-2 d-flex flex-wrap gap-2 align-items-center">
          <span><i class="bi bi-clock-history"></i> ตรวจเยี่ยมครั้งล่าสุดเมื่อ <strong><?= thai_date($lastVisit['visit_date']) ?></strong>
            (<a href="visit_view.php?id=<?= (int)$lastVisit['id'] ?>" target="_blank">ดูผล</a>)</span>
          <?php if ($copied): ?>
            <span>— ใช้คำตอบส่วนที่ 1–3 จากครั้งล่าสุดเป็นค่าเริ่มต้นแล้ว ตรวจสอบและแก้ไขให้ตรงกับปัจจุบัน</span>
            <a href="visit_form.php?center_id=<?= $centerId ?>" class="ms-auto">เริ่มจากแบบฟอร์มว่าง</a>
          <?php else: ?>
            <a href="visit_form.php?center_id=<?= $centerId ?>&amp;copy=1" class="btn btn-sm btn-outline-primary ms-auto"><i class="bi bi-files"></i> ใช้คำตอบจากครั้งล่าสุดเป็นค่าเริ่มต้น</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="row g-2 justify-content-end">
        <div class="col-7 col-md-3"><label class="form-label">วันที่ตรวจเยี่ยม</label>
          <input type="date" name="visit_date" class="form-control" required value="<?= h($v['visit_date']) ?>"></div>
        <div class="col-5 col-md-2"><label class="form-label">เวลา (น.)</label>
          <input type="time" name="visit_time" class="form-control" value="<?= h(substr((string)$v['visit_time'], 0, 5)) ?>"></div>
      </div>
    </div>
  </div>

  <!-- ข้อมูลทั่วไป -->
  <div class="card mb-3">
    <div class="card-header"><strong>ข้อมูลทั่วไปของศูนย์ดิจิทัลชุมชน</strong> <span class="small text-muted">— แก้ไขได้ ระบบจะอัปเดตข้อมูลศูนย์ให้</span></div>
    <div class="card-body row g-3">
      <div class="col-12"><label class="form-label">ชื่อศูนย์ดิจิทัลชุมชน</label>
        <input name="c_name" class="form-control" required value="<?= h($c['name']) ?>"></div>
      <div class="col-4 col-md-2"><label class="form-label">เลขที่</label><input name="c_house_no" class="form-control" value="<?= h($c['house_no'] ?? '') ?>"></div>
      <div class="col-8 col-md-4"><label class="form-label">หมู่บ้าน</label><input name="c_village" class="form-control" value="<?= h($c['village']) ?>"></div>
      <div class="col-4 col-md-2"><label class="form-label">หมู่ที่</label><input name="c_moo" class="form-control" inputmode="numeric" value="<?= h($c['moo'] ?? '') ?>"></div>
      <div class="col-8 col-md-4"><label class="form-label">ตำบล</label><input name="c_subdistrict" class="form-control" value="<?= h($c['subdistrict']) ?>"></div>
      <div class="col-md-4"><label class="form-label">อำเภอ</label>
        <select name="c_district" class="form-select">
          <?php foreach (KK_DISTRICTS as $dname): ?><option <?= $dname === $c['district'] ? 'selected' : '' ?>><?= h($dname) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-8"><label class="form-label">ที่อยู่เพิ่มเติม / ถนน <small class="text-muted">(ข้อมูลเดิม — ปรับให้ไม่ซ้ำกับช่องด้านบน)</small></label>
        <input name="c_address" class="form-control" value="<?= h($c['address']) ?>"></div>

      <div class="col-12"><label class="form-label d-block">สถานที่ตั้งอยู่ใน</label>
        <div class="choice-row">
          <?php foreach (VISIT_LOCATION_TYPES as $val_ => $label): ?>
            <label class="choice"><input type="radio" name="c_type" value="<?= h($val_) ?>" <?= $typeSel === $val_ ? 'checked' : '' ?>> <?= h($label) ?></label>
          <?php endforeach; ?>
          <label class="choice"><input type="radio" name="c_type" value="other" <?= $typeSel === '' ? 'checked' : '' ?>> อื่น ๆ</label>
          <input name="c_type_other" class="form-control form-control-sm d-inline-block" style="max-width:220px" placeholder="ระบุ" value="<?= h($typeOther) ?>">
        </div></div>

      <div class="col-12">
        <label class="form-label">พิกัดที่ตั้งศูนย์ (lat, long)</label>
        <div id="pickerMap" class="picker-map" style="height:260px"></div>
        <div class="row g-2 mt-1 align-items-end">
          <div class="col-6 col-md-3"><input name="lat" id="lat" class="form-control" placeholder="Latitude" value="<?= h($c['lat']) ?>"></div>
          <div class="col-6 col-md-3"><input name="lng" id="lng" class="form-control" placeholder="Longitude" value="<?= h($c['lng']) ?>"></div>
          <div class="col-md-6 d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-success" id="btnMyLocation"><i class="bi bi-crosshair"></i> ใช้ตำแหน่ง GPS ปัจจุบัน</button>
            <button type="button" class="btn btn-outline-secondary" id="btnClearLoc"><i class="bi bi-x-circle"></i></button>
          </div>
        </div>
        <div class="small text-muted mt-1" id="gpsInfo">ยืนอยู่ที่ศูนย์แล้วกด "ใช้ตำแหน่ง GPS ปัจจุบัน" หรือคลิก/ลากหมุดบนแผนที่</div>
      </div>

      <div class="col-12"><hr class="my-1"><label class="form-label fw-semibold">เจ้าหน้าที่ประจำศูนย์ดิจิทัลชุมชน</label></div>
      <div class="col-md-5"><label class="form-label">ชื่อ-นามสกุล</label><input name="staff_name" class="form-control" value="<?= h($staff['name']) ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label">เบอร์โทรศัพท์</label><input name="staff_phone" type="tel" class="form-control" value="<?= h($staff['phone']) ?>"></div>
      <div class="col-6 col-md-4"><label class="form-label">Line ID</label><input name="staff_line" class="form-control" value="<?= h($staff['line_id']) ?>"></div>
      <input type="hidden" name="staff_id" value="<?= (int)$staff['id'] ?>">
      <div class="col-12">
        <?php if ((int)$staff['id'] > 0): ?>
          <label class="choice"><input type="radio" name="staff_mode" value="update" checked> แก้ไขข้อมูลเจ้าหน้าที่คนเดิม</label>
          <label class="choice"><input type="radio" name="staff_mode" value="new"> เป็นเจ้าหน้าที่คนใหม่ (เพิ่มรายชื่อ)</label>
        <?php else: ?>
          <input type="hidden" name="staff_mode" value="new"><span class="small text-muted">ศูนย์นี้ยังไม่มีข้อมูลเจ้าหน้าที่ — ระบบจะเพิ่มให้</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ส่วนที่ 1 -->
  <div class="card mb-3">
    <div class="card-header"><strong>ส่วนที่ 1 ด้านโครงสร้างพื้นฐานและอุปกรณ์</strong></div>
    <div class="card-body">
      <h3 class="q">1.1 สภาพเครื่องคอมพิวเตอร์</h3>
      <div class="row g-2 mb-2">
        <div class="col-4"><label class="form-label small">จำนวนทั้งหมด (เครื่อง)</label><input name="pc_total" type="number" min="0" inputmode="numeric" class="form-control" value="<?= $val('pc_total') ?>"></div>
        <div class="col-4"><label class="form-label small">ใช้งานได้ (เครื่อง)</label><input name="pc_ok" type="number" min="0" inputmode="numeric" class="form-control" value="<?= $val('pc_ok') ?>"></div>
        <div class="col-4"><label class="form-label small">ชำรุด/รอซ่อม (เครื่อง)</label><input name="pc_broken" type="number" min="0" inputmode="numeric" class="form-control" value="<?= $val('pc_broken') ?>"></div>
      </div>
      <div class="mb-3"><span class="small me-2">ความพร้อมใช้งาน:</span>
        <?php foreach (VISIT_PC_STATUS as $o): ?><label class="choice"><input type="radio" name="pc_status" value="<?= h($o) ?>" <?= $is('pc_status', $o) ?>> <?= h($o) ?></label><?php endforeach; ?>
      </div>

      <h3 class="q">1.2 ระบบเครือข่ายอินเทอร์เน็ต</h3>
      <div class="mb-2">
        <label class="choice"><input type="radio" name="net_status" value="ok" <?= $is('net_status', 'ok') ?>> ใช้งานได้ปกติ</label>
        <span class="d-inline-flex gap-2 align-items-center flex-wrap">
          <span class="input-group input-group-sm" style="width:170px"><span class="input-group-text">Download</span><input name="net_down" type="number" step="0.01" min="0" inputmode="decimal" class="form-control" value="<?= $val('net_down') ?>"><span class="input-group-text">Mbps</span></span>
          <span class="input-group input-group-sm" style="width:160px"><span class="input-group-text">Upload</span><input name="net_up" type="number" step="0.01" min="0" inputmode="decimal" class="form-control" value="<?= $val('net_up') ?>"><span class="input-group-text">Mbps</span></span>
          <a href="https://fast.com" target="_blank" rel="noopener" class="small">ทดสอบความเร็ว</a>
        </span>
      </div>
      <div class="mb-2 d-flex flex-wrap gap-2 align-items-center">
        <label class="choice"><input type="radio" name="net_status" value="problem" <?= $is('net_status', 'problem') ?>> มีปัญหา (ช้า/หลุดบ่อย/ใช้ไม่ได้)</label>
        <input name="net_problem" class="form-control form-control-sm" style="max-width:420px" placeholder="สาเหตุ" value="<?= $val('net_problem') ?>">
      </div>
      <label class="choice mb-3"><input type="checkbox" name="free_wifi" value="1" <?= $flagged('free_wifi') ?>> มีการปล่อยสัญญาณ Free Wi-Fi ภายในศูนย์</label>

      <?php foreach (VISIT_DEVICES as $title => $items): ?>
        <h3 class="q"><?= h($title) ?></h3>
        <div class="device-grid mb-3">
          <?php foreach ($items as $key => [$label, $opts]): ?>
            <div class="device-row"><span class="dev-name"><?= h($label) ?></span>
              <span class="dev-opts"><?php foreach ($opts as $o): ?><label class="choice"><input type="radio" name="dev_<?= $key ?>" value="<?= h($o) ?>" <?= $is('dev_' . $key, $o) ?>> <?= h($o) ?></label><?php endforeach; ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ส่วนที่ 2 -->
  <div class="card mb-3">
    <div class="card-header"><strong>ส่วนที่ 2 ด้านการบริหารจัดการและการให้บริการ</strong></div>
    <div class="card-body">
      <h3 class="q">2.1 การบริหารจัดการศูนย์</h3>
      <label class="choice d-block"><input type="checkbox" name="sign" value="1" <?= $flagged('sign') ?>> มีป้ายชื่อศูนย์ดิจิทัลชุมชนติดตั้งชัดเจน</label>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <label class="choice"><input type="checkbox" name="schedule" value="1" <?= $flagged('schedule') ?>> มีตารางเวลาเปิด-ปิดให้บริการชัดเจน</label>
        <span class="input-group input-group-sm" style="width:150px"><span class="input-group-text">เปิด</span><input name="open_time" type="time" class="form-control" value="<?= $val('open_time') ?>"></span>
        <span class="input-group input-group-sm" style="width:150px"><span class="input-group-text">ปิด</span><input name="close_time" type="time" class="form-control" value="<?= $val('close_time') ?>"></span>
      </div>
      <label class="choice d-block"><input type="checkbox" name="logbook" value="1" <?= $flagged('logbook') ?>> มีสมุดบันทึกผู้ใช้บริการ (Logbook) หรือระบบสถิติออนไลน์</label>
      <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <span class="small">จำนวนผู้เข้าใช้บริการเฉลี่ย</span>
        <input name="avg_users" type="number" min="0" inputmode="numeric" class="form-control form-control-sm" style="width:100px" value="<?= $val('avg_users') ?>">
        <span class="small">คน ต่อ</span>
        <?php foreach (VISIT_AVG_PER as $o): ?><label class="choice"><input type="radio" name="avg_per" value="<?= $o ?>" <?= $is('avg_per', $o) ?>> <?= $o ?></label><?php endforeach; ?>
      </div>

      <h3 class="q">2.2 กลุ่มผู้ใช้บริการหลัก <small class="text-muted">(เลือกได้มากกว่า 1 ข้อ)</small></h3>
      <div class="two-col mb-3">
        <?php foreach (VISIT_USER_GROUPS as $k => $label): ?><label class="choice"><input type="checkbox" name="groups[]" value="<?= $k ?>" <?= $has('groups', $k) ?>> <?= h($label) ?></label><?php endforeach; ?>
        <span class="d-flex gap-2 align-items-center"><span class="small text-nowrap">อื่น ๆ</span><input name="groups_other" class="form-control form-control-sm" value="<?= $val('groups_other') ?>"></span>
      </div>

      <h3 class="q">2.3 วัตถุประสงค์หลักในการเข้าใช้บริการ <small class="text-muted">(เลือกได้มากกว่า 1 ข้อ)</small></h3>
      <div class="two-col">
        <?php foreach (VISIT_PURPOSES as $k => $label): ?><label class="choice"><input type="checkbox" name="purposes[]" value="<?= $k ?>" <?= $has('purposes', $k) ?>> <?= h($label) ?></label><?php endforeach; ?>
        <span class="d-flex gap-2 align-items-center"><span class="small text-nowrap">อื่น ๆ</span><input name="purposes_other" class="form-control form-control-sm" value="<?= $val('purposes_other') ?>"></span>
      </div>
    </div>
  </div>

  <!-- ส่วนที่ 3 -->
  <div class="card mb-3">
    <div class="card-header"><strong>ส่วนที่ 3 ด้านความปลอดภัยและสภาพแวดล้อม</strong></div>
    <div class="card-body two-col">
      <?php foreach (VISIT_SAFETY as $k => $label): ?><label class="choice"><input type="checkbox" name="safety[]" value="<?= $k ?>" <?= $has('safety', $k) ?>> <?= h($label) ?></label><?php endforeach; ?>
    </div>
  </div>

  <!-- ส่วนที่ 4 -->
  <div class="card mb-3">
    <div class="card-header"><strong>ส่วนที่ 4 สรุปปัญหา อุปสรรค และข้อเสนอแนะ</strong></div>
    <div class="card-body">
      <label class="form-label">4.1 ปัญหาหรืออุปสรรคที่พบในการดำเนินงานของศูนย์</label>
      <textarea name="problems" rows="4" class="form-control mb-3"><?= h($v['problems']) ?></textarea>
      <label class="form-label">4.2 ความต้องการหรือข้อเสนอแนะที่ต้องการให้ส่วนกลางสนับสนุน</label>
      <textarea name="suggestions" rows="4" class="form-control"><?= h($v['suggestions']) ?></textarea>
      <p class="small text-muted mt-2 mb-0"><i class="bi bi-lock"></i> ส่วนที่ 4 ใช้ภายในเท่านั้น ไม่แสดงบนหน้าเว็บไซต์</p>
    </div>
  </div>

  <!-- ภาพ -->
  <div class="card mb-3">
    <div class="card-header"><strong><i class="bi bi-camera"></i> ภาพประกอบการตรวจเยี่ยม</strong> <span class="small text-muted">— บันทึกเป็นภาพกิจกรรมของศูนย์</span></div>
    <div class="card-body">
      <?php if ($images): ?>
        <div class="gallery-admin mb-3">
          <?php foreach ($images as $img): ?>
            <div class="gallery-item"><img src="<?= h(upload_url($img['file'])) ?>" alt="">
              <label class="del"><input type="checkbox" name="delete_images[]" value="<?= (int)$img['id'] ?>"> ลบ</label></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <input type="file" name="photos[]" accept="image/*" multiple class="form-control form-control-lg" data-preview="photosNew">
      <div class="small text-muted mt-1">ถ่ายจากกล้องมือถือหรือเลือกจากอัลบั้มได้หลายภาพ ระบบจะย่อขนาดภาพก่อนอัปโหลดให้อัตโนมัติ</div>
      <div id="photosNew" class="preview-grid mt-2"></div>
    </div>
  </div>

  <!-- ลงชื่อ -->
  <div class="card mb-3">
    <div class="card-body row g-3">
      <div class="col-md-6"><div class="fw-semibold mb-2">ผู้ให้ข้อมูล</div>
        <input name="informant_name" class="form-control mb-2" placeholder="ชื่อ-นามสกุล" value="<?= h($v['informant_name']) ?>">
        <input name="informant_position" class="form-control" placeholder="ตำแหน่ง" value="<?= h($v['informant_position']) ?>"></div>
      <div class="col-md-6"><div class="fw-semibold mb-2">ผู้ตรวจเยี่ยม</div>
        <input name="inspector_name" class="form-control mb-2" placeholder="ชื่อ-นามสกุล" value="<?= h($v['inspector_name']) ?>">
        <input name="inspector_position" class="form-control" placeholder="ตำแหน่ง" value="<?= h($v['inspector_position']) ?>"></div>
    </div>
  </div>

  <div class="sticky-actions">
    <button class="btn btn-primary btn-lg flex-grow-1 flex-md-grow-0"><i class="bi bi-save"></i> บันทึกการตรวจเยี่ยม</button>
    <a href="<?= $visit ? 'visit_view.php?id=' . (int)$visit['id'] : 'visits.php' ?>" class="btn btn-light btn-lg">ยกเลิก</a>
  </div>
</form>
<?php require __DIR__ . '/footer.php'; ?>
