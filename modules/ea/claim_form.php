<?php
/**
 * สร้าง/แก้ไขใบเบิกค่าใช้จ่ายในการเดินทางไปราชการ (แบบ 8708 + แบบประกอบ + บก.111)
 * - ค่าพาหนะรายวัน = ระยะทางไปกลับ x อัตรา (รถยนต์ กม.ละ 4 บาท) · เบี้ยเลี้ยงคำนวณจากเวลาออก/กลับรายวัน (แก้เองได้)
 * - รวมเงิน · ขอรับเงินเพียง = ปัดเศษสตางค์ทิ้ง · จำนวนเงินตัวอักษรแบบ BAHTTEXT
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/claims_lib.php';
$me = require_module(EA_MODULE, 'viewer');
$pdo = db();
$isAdmin = ea_can('admin');
$id = int_param('id');
$errors = [];

if ($id) {
    $st = $pdo->prepare('SELECT * FROM ea_claims WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c || !claim_can_view($c)) {
        flash('ไม่พบใบเบิก', 'warning');
        redirect('claims');
    }
    if (!claim_can_edit($c)) {
        flash('ใบเบิกนี้ออกใบสำคัญแล้ว แก้ไขได้เฉพาะผู้ดูแลระบบบริหารโครงการ', 'warning');
        redirect('claim_print?id=' . $id);
    }
    $st = $pdo->prepare('SELECT * FROM ea_claim_days WHERE claim_id = ? ORDER BY seq, trip_date, id');
    $st->execute([$id]);
    $days = $st->fetchAll();
} else {
    // ผู้ดูแลสร้างให้ผู้อื่นได้ (?user=)
    $owner = $me;
    if ($isAdmin && int_param('user')) {
        $st = $pdo->prepare('SELECT * FROM core_users WHERE id = ? AND is_active = 1');
        $st->execute([int_param('user')]);
        $owner = $st->fetch() ?: $me;
    }
    $c = claim_defaults($owner);
    $days = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $date = fn(string $v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
    $time = function (string $v) {
        $v = trim(str_replace(['น.', '.'], ['', ':'], $v));
        return preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m) && (int)$m[1] < 24 && (int)$m[2] < 60 ? sprintf('%02d:%02d:00', $m[1], $m[2]) : null;
    };
    $num = fn(string $k) => max(0, round((float)str_replace(',', '', post($k)), 2));
    $txt = fn(string $k, int $len) => mb_substr(trim(post($k)), 0, $len);
    if ($isAdmin && !$id && int_param('user_id')) {
        $c['user_id'] = int_param('user_id');
    }
    $projects = projects_all();
    $c = array_merge($c, [
        'project_id' => isset($projects[int_param('project_id')]) ? int_param('project_id') : null,
        // อื่น ๆ: พิมพ์ชื่อโครงการเอง (ไม่ผูกกับโครงการในระบบ)
        'project_other' => post('project_id') === 'other' ? $txt('project_other', 255) : '',
        'claim_date' => $date(post('claim_date')) ?? date('Y-m-d'),
        'office' => $txt('office', 150), 'addressee' => $txt('addressee', 150), 'order_no' => $txt('order_no', 100), 'order_date' => $date(post('order_date')),
        'full_name' => $txt('full_name', 150), 'position' => $txt('position', 150), 'level' => $txt('level', 100), 'department' => $txt('department', 150),
        'companions' => $txt('companions', 2000), 'purpose' => $txt('purpose', 2000),
        'depart_from' => isset(CLAIM_PLACES[post('depart_from')]) ? post('depart_from') : 'home',
        'return_to' => isset(CLAIM_PLACES[post('return_to')]) ? post('return_to') : 'home',
        'origin_address' => $txt('origin_address', 1000), 'claim_for' => post('claim_for') === 'group' ? 'group' : 'self',
        'per_diem_type' => isset(CLAIM_PER_DIEM[post('per_diem_type')]) ? post('per_diem_type') : 'ก', 'per_diem_rate' => $num('per_diem_rate'),
        'per_diem_days' => $num('per_diem_days'), 'per_diem_amount' => $num('per_diem_amount'), 'per_diem_note' => $txt('per_diem_note', 100),
        'lodging_type' => $txt('lodging_type', 100), 'lodging_days' => $num('lodging_days'), 'lodging_amount' => $num('lodging_amount'),
        'vehicle_kind' => isset(CLAIM_VEHICLES[post('vehicle_kind')]) ? post('vehicle_kind') : 'car', 'km_rate' => $num('km_rate'),
        'vehicle_desc' => $txt('vehicle_desc', 255), 'other_desc' => $txt('other_desc', 255), 'other_amount' => $num('other_amount'),
        'loan_no' => $txt('loan_no', 50), 'loan_date' => $date(post('loan_date')), 'loan_amount' => $num('loan_amount'), 'note' => $txt('note', 2000),
    ]);
    // รายวัน
    $days = [];
    foreach ((array)($_POST['d_date'] ?? []) as $i => $dv) {
        $d = $date((string)$dv);
        $places = mb_substr(trim((string)($_POST['d_places'][$i] ?? '')), 0, 2000);
        $km = max(0, round((float)str_replace(',', '', (string)($_POST['d_km'][$i] ?? '0')), 2));
        if (!$d && $places === '' && !$km) {
            continue;                                // แถวว่าง
        }
        if (!$d) {
            $errors[] = 'รายวันแถวที่ ' . ($i + 1) . ': กรุณาระบุวันที่';
            continue;
        }
        $days[] = ['seq' => count($days) + 1, 'trip_date' => $d, 'depart_time' => $time((string)($_POST['d_dtime'][$i] ?? '')),
                   'return_date' => $date((string)($_POST['d_rdate'][$i] ?? '')), 'return_time' => $time((string)($_POST['d_rtime'][$i] ?? '')),
                   'places' => $places, 'km' => $km, 'extra' => mb_substr(trim((string)($_POST['d_extra'][$i] ?? '')), 0, 2000)];
    }
    usort($days, fn($a, $b) => [$a['trip_date'], $a['depart_time']] <=> [$b['trip_date'], $b['depart_time']]);
    foreach ($days as $i => &$d) {
        $d['seq'] = $i + 1;
    }
    unset($d);
    if ($c['full_name'] === '') {
        $errors[] = 'กรุณาระบุชื่อผู้ขอเบิก';
    }
    claim_calc($c, $days, post('auto_per_diem') === '1');
    if (!$errors) {
        if (post('project_id') === 'other' && $c['project_other'] === '') {
            $errors[] = 'เลือกโครงการ "อื่น ๆ" กรุณาพิมพ์ชื่อโครงการ';
        }
    }
    if (!$errors) {
        $cols = ['user_id', 'project_id', 'project_other', 'claim_date', 'office', 'addressee', 'order_no', 'order_date', 'full_name', 'position', 'level', 'department', 'companions',
                 'purpose', 'depart_from', 'return_to', 'origin_address', 'claim_for', 'per_diem_type', 'per_diem_rate', 'per_diem_days', 'per_diem_amount',
                 'per_diem_note', 'lodging_type', 'lodging_days', 'lodging_amount', 'vehicle_kind', 'km_rate', 'vehicle_desc', 'vehicle_amount', 'other_desc',
                 'other_amount', 'total_amount', 'receive_amount', 'loan_no', 'loan_date', 'loan_amount', 'note'];
        $vals = array_map(fn($k) => $c[$k], $cols);
        $pdo->beginTransaction();
        if ($id) {
            $pdo->prepare('UPDATE ea_claims SET ' . implode(', ', array_map(fn($k) => "$k = ?", $cols)) . ' WHERE id = ?')->execute(array_merge($vals, [$id]));
            $pdo->prepare('DELETE FROM ea_claim_days WHERE claim_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO ea_claims (' . implode(',', $cols) . ', created_by) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ', ?)')
                ->execute(array_merge($vals, [(int)$me['id']]));
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO ea_claim_days (claim_id, seq, trip_date, depart_time, return_date, return_time, places, km, amount, extra) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($days as $d) {
            $ins->execute([$id, $d['seq'], $d['trip_date'], $d['depart_time'], $d['return_date'], $d['return_time'], $d['places'], $d['km'], $d['amount'], $d['extra']]);
        }
        $pdo->commit();
        audit_log('ea_claim_save', 'บันทึกใบเบิกค่าเดินทาง #' . $id . ' ' . $c['full_name'] . ' ' . number_format($c['total_amount'], 2) . ' บาท', EA_MODULE);
        flash('บันทึกใบเบิกแล้ว — รวม ' . number_format($c['total_amount'], 2) . ' บาท');
        redirect(post('go') === 'print' ? 'claim_print?id=' . $id : 'claim_form?id=' . $id);
    }
}

// เบี้ยเลี้ยงคำนวณอัตโนมัติ: ใบใหม่ / ค่าที่บันทึกตรงกับที่คำนวณ (ไม่ได้แก้เอง) · ส่งฟอร์มไม่ผ่าน = ตามที่เลือกไว้
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $autoPerDiem = post('auto_per_diem') === '1';
} else {
    $cc = $c;
    $dd = $days;
    claim_calc($cc, $dd, true);
    $autoPerDiem = !$c['id'] || (abs((float)$cc['per_diem_amount'] - (float)$c['per_diem_amount']) < 0.01 && (float)$cc['per_diem_days'] === (float)$c['per_diem_days']);
}
if (!$days) {
    $days = [['trip_date' => '', 'depart_time' => '', 'return_date' => '', 'return_time' => '', 'places' => '', 'km' => '', 'extra' => '']];
}
// อปท. (เทศบาล / อบต.) จากบัญชี EA — ช่วยกรอกสถานที่
$locals = $pdo->query("SELECT DISTINCT local_name, tambon_name, district_name, MAX(province_name) prov FROM ea_areas
                       WHERE local_name <> '' GROUP BY local_name, tambon_name, district_name ORDER BY district_name, local_name")->fetchAll();
$hm = fn(?string $t) => $t ? substr($t, 0, 5) : '';


$pageTitle = $c['id'] ? 'แก้ไขใบเบิกค่าเดินทาง' : 'สร้างใบเบิกค่าเดินทาง';
$active = 'claims';
$headExtra = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt" crossorigin="anonymous">'
    . '<link href="' . h(asset_v('modules/calendar/assets/css/calendar.css')) . '" rel="stylesheet">';
$pageActions = '<a href="claims" class="btn btn-light"><i class="bi bi-arrow-left"></i> ใบเบิกทั้งหมด</a>'
    . ($c['id'] ? ' <a href="claim_print?id=' . (int)$c['id'] . '" target="_blank" class="btn btn-outline-success"><i class="bi bi-printer"></i> พิมพ์</a>' : '');
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<form method="post" id="claimForm">
  <?= csrf_field() ?>
  <input type="hidden" name="user_id" value="<?= (int)$c['user_id'] ?>">
  <div class="row g-3">
    <div class="col-xl-8">
      <div class="card mb-3">
        <div class="card-header"><strong>1. ใบเบิก (แบบ 8708 ส่วนที่ 1)</strong></div>
        <div class="card-body row g-2">
          <div class="col-md-4"><label class="form-label small">วันที่เขียนใบเบิก</label><input type="text" name="claim_date" class="form-control thai-date" value="<?= h($c['claim_date']) ?>"></div>
          <div class="col-md-8"><label class="form-label small">ที่ทำการ</label><input name="office" class="form-control" maxlength="150" value="<?= h($c['office']) ?>"></div>
          <div class="col-md-4"><label class="form-label small">เรียน</label><input name="addressee" class="form-control" maxlength="150" value="<?= h($c['addressee']) ?>"></div>
          <div class="col-md-4"><label class="form-label small">ตามคำสั่ง/บันทึก ที่</label><input name="order_no" class="form-control" maxlength="100" value="<?= h($c['order_no']) ?>" placeholder="เช่น ขก 0013/549"></div>
          <div class="col-md-4"><label class="form-label small">ลงวันที่</label><input type="text" name="order_date" class="form-control thai-date" value="<?= h((string)$c['order_date']) ?>"></div>
          <?php $isOther = !$c['project_id'] && ($c['project_other'] ?? '') !== '' || post('project_id') === 'other'; ?>
          <div class="col-md-<?= $isOther ? '5' : '12' ?>" id="projCol"><label class="form-label small">โครงการ</label>
            <select name="project_id" id="projSel" class="form-select"><option value="">— ไม่ระบุ —</option>
              <?php foreach (projects_all() as $p): if (!$p['is_active'] && (int)$p['id'] !== (int)$c['project_id']) { continue; } ?>
              <option value="<?= (int)$p['id'] ?>" <?= (int)$c['project_id'] === (int)$p['id'] ? 'selected' : '' ?> data-name="<?= h($p['name']) ?>"><?= h($p['code'] . ' — ' . $p['name']) ?></option><?php endforeach; ?>
              <option value="other" <?= $isOther ? 'selected' : '' ?>>อื่น ๆ (พิมพ์เอง)</option>
            </select></div>
          <div class="col-md-7<?= $isOther ? '' : ' d-none' ?>" id="projOtherCol"><label class="form-label small">ชื่อโครงการ (อื่น ๆ)</label>
            <input name="project_other" id="projOther" class="form-control" maxlength="255" value="<?= h($c['project_other'] ?? '') ?>" placeholder="พิมพ์ชื่อโครงการ"></div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><strong>2. ผู้เดินทาง</strong></div>
        <div class="card-body row g-2">
          <div class="col-md-5"><label class="form-label small">ข้าพเจ้า <span class="text-danger">*</span></label><input name="full_name" class="form-control" maxlength="150" required value="<?= h($c['full_name']) ?>"></div>
          <div class="col-md-4"><label class="form-label small">ตำแหน่ง</label><input name="position" class="form-control" maxlength="150" value="<?= h($c['position']) ?>"></div>
          <div class="col-md-3"><label class="form-label small">ระดับ</label><input name="level" class="form-control" maxlength="100" list="levelList" value="<?= h($c['level']) ?>">
            <datalist id="levelList"><?php foreach (CLAIM_LEVELS as $lv): ?><option value="<?= h($lv) ?>"><?php endforeach; ?></datalist></div>
          <div class="col-md-6"><label class="form-label small">สังกัด</label><input name="department" class="form-control" maxlength="150" value="<?= h($c['department']) ?>"></div>
          <div class="col-md-6"><label class="form-label small">พร้อมด้วย</label><input name="companions" class="form-control" maxlength="2000" value="<?= h((string)$c['companions']) ?>" placeholder="ไม่มี = เว้นว่าง"></div>
          <div class="col-12 small"><span class="me-3">ขอเบิกค่าใช้จ่ายสำหรับ</span>
            <label class="me-3"><input type="radio" name="claim_for" value="self" <?= $c['claim_for'] !== 'group' ? 'checked' : '' ?>> ข้าพเจ้า</label>
            <label><input type="radio" name="claim_for" value="group" <?= $c['claim_for'] === 'group' ? 'checked' : '' ?>> คณะเดินทาง</label></div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><strong>3. การเดินทาง</strong></div>
        <div class="card-body row g-2">
          <div class="col-12"><label class="form-label small">เดินทางไปปฏิบัติราชการ</label>
            <textarea name="purpose" id="purpose" class="form-control" rows="2" maxlength="2000" placeholder="เช่น ปฏิบัติงานโครงการ... ในการเข้าพบ อบต/เทศบาล เพื่อ..."><?= h((string)$c['purpose']) ?></textarea></div>
          <div class="col-md-6 small"><div class="form-label small mb-1">ออกเดินทางจาก</div>
            <?php foreach (CLAIM_PLACES as $k => $l): ?><label class="me-3"><input type="radio" name="depart_from" value="<?= $k ?>" <?= $c['depart_from'] === $k ? 'checked' : '' ?>> <?= h($l) ?></label><?php endforeach; ?></div>
          <div class="col-md-6 small"><div class="form-label small mb-1">กลับถึง</div>
            <?php foreach (CLAIM_PLACES as $k => $l): ?><label class="me-3"><input type="radio" name="return_to" value="<?= $k ?>" <?= $c['return_to'] === $k ? 'checked' : '' ?>> <?= h($l) ?></label><?php endforeach; ?></div>
          <div class="col-12"><label class="form-label small">ที่อยู่สถานที่ออกเดินทาง <span class="text-muted">(แสดงในแบบประกอบ "ออกจาก/ถึง" และ บก.111 — ขึ้นบรรทัดใหม่ได้)</span></label>
            <textarea name="origin_address" class="form-control" rows="6" maxlength="1000"><?= h((string)$c['origin_address']) ?></textarea></div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex align-items-center"><strong>4. รายวันที่เดินทาง</strong>
          <span class="small text-muted ms-2">(แบบประกอบใบเบิก + ใบรับรองแทนใบเสร็จรับเงิน)</span>
          <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="addDay"><i class="bi bi-plus-lg"></i> เพิ่มวัน</button></div>
        <div class="card-body p-2">
          <div class="input-group input-group-sm mb-2">
            <span class="input-group-text"><i class="bi bi-building"></i> เพิ่มสถานที่ (อปท.)</span>
            <input class="form-control" list="localList" id="localPick" placeholder="พิมพ์ชื่อเทศบาล / อบต. แล้วกด เพิ่ม — เพิ่มลงในแถวที่เลือกล่าสุด">
            <button type="button" class="btn btn-outline-secondary" id="localAdd">เพิ่ม</button>
            <datalist id="localList"><?php foreach ($locals as $l): ?><option value="<?= h($l['local_name'] . ' ต.' . $l['tambon_name'] . ' อ.' . $l['district_name'] . ' จ.' . $l['prov']) ?>"><?php endforeach; ?></datalist>
          </div>
          <div id="dayList">
          <?php foreach ($days as $i => $d): ?>
            <div class="claim-day border rounded p-2 mb-2">
              <div class="row g-2 align-items-end">
                <div class="col-auto fw-semibold day-no pt-2"><?= $i + 1 ?></div>
                <div class="col-md-3 col-6"><label class="form-label small mb-0">วันที่ออกเดินทาง</label><input type="text" name="d_date[]" class="form-control form-control-sm thai-date d-date" value="<?= h((string)$d['trip_date']) ?>"></div>
                <div class="col-md-2 col-6"><label class="form-label small mb-0">เวลาออก</label><input type="time" name="d_dtime[]" class="form-control form-control-sm d-calc" value="<?= h($hm($d['depart_time'] ?? '')) ?>"></div>
                <div class="col-md-3 col-6"><label class="form-label small mb-0">วันที่กลับ <span class="text-muted">(ว่าง = วันเดียวกัน)</span></label><input type="text" name="d_rdate[]" class="form-control form-control-sm thai-date d-rdate" value="<?= h((string)($d['return_date'] ?? '')) ?>"></div>
                <div class="col-md-2 col-6"><label class="form-label small mb-0">เวลากลับถึง</label><input type="time" name="d_rtime[]" class="form-control form-control-sm d-calc" value="<?= h($hm($d['return_time'] ?? '')) ?>"></div>
                <div class="col-auto ms-auto"><button type="button" class="btn btn-sm btn-light text-danger d-remove" title="ลบวันนี้"><i class="bi bi-trash"></i></button></div>
                <div class="col-md-7"><label class="form-label small mb-0">สถานที่ที่ไปปฏิบัติงาน</label><textarea name="d_places[]" class="form-control form-control-sm d-places" rows="2" maxlength="2000"><?= h((string)$d['places']) ?></textarea></div>
                <div class="col-md-2 col-6"><label class="form-label small mb-0">ระยะทางไปกลับ (กม.)</label><input type="number" step="0.01" min="0" name="d_km[]" class="form-control form-control-sm d-km d-calc" value="<?= h((string)$d['km']) ?>"></div>
                <div class="col-md-3 col-6 small"><div class="text-muted">ค่าพาหนะ / เบี้ยเลี้ยง</div><div><span class="d-amount fw-semibold">-</span> · <span class="d-allow text-muted">-</span></div></div>
                <div class="col-12"><input name="d_extra[]" class="form-control form-control-sm" maxlength="2000" value="<?= h((string)($d['extra'] ?? '')) ?>" placeholder="รายการค่าใช้จ่ายที่ขอเบิกนอกเหนือจากเบี้ยเลี้ยง ที่พัก ค่าพาหนะ (แบบประกอบ) — ว่าง = ใช้ข้อความ &quot;เดินทางไปปฏิบัติราชการ&quot;"></div>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><strong>5. สัญญายืมเงิน / หมายเหตุ</strong> <span class="small text-muted">(ไม่มีเว้นว่าง)</span></div>
        <div class="card-body row g-2">
          <div class="col-md-4"><label class="form-label small">สัญญายืมเงินเลขที่</label><input name="loan_no" class="form-control" maxlength="50" value="<?= h($c['loan_no']) ?>"></div>
          <div class="col-md-4"><label class="form-label small">วันที่</label><input type="text" name="loan_date" class="form-control thai-date" value="<?= h((string)$c['loan_date']) ?>"></div>
          <div class="col-md-4"><label class="form-label small">จำนวนเงินยืม</label><input type="number" step="0.01" min="0" name="loan_amount" class="form-control" value="<?= (float)$c['loan_amount'] ?: '' ?>"></div>
          <div class="col-12"><label class="form-label small">หมายเหตุ (แบบ 8708 หน้า 2)</label><textarea name="note" class="form-control" rows="2" maxlength="2000"><?= h((string)$c['note']) ?></textarea></div>
        </div>
      </div>
    </div>

    <div class="col-xl-4">
      <div class="card claim-sum">
        <div class="card-header"><strong>ค่าใช้จ่ายที่ขอเบิก</strong></div>
        <div class="card-body">
          <div class="fw-semibold small mb-1">ค่าเบี้ยเลี้ยงเดินทาง</div>
          <div class="row g-2 mb-1">
            <div class="col-4"><label class="form-label small mb-0">ประเภท</label><select name="per_diem_type" id="pdType" class="form-select form-select-sm">
              <?php foreach (CLAIM_PER_DIEM as $k => $r): ?><option value="<?= h($k) ?>" data-rate="<?= $r ?>" <?= $c['per_diem_type'] === $k ? 'selected' : '' ?>><?= h($k) ?></option><?php endforeach; ?></select></div>
            <div class="col-8"><label class="form-label small mb-0">อัตราเต็มวัน (บาท)</label><input type="number" step="0.01" min="0" name="per_diem_rate" id="pdRate" class="form-control form-control-sm" value="<?= (float)$c['per_diem_rate'] ?>"></div>
          </div>
          <div class="form-check form-switch small mb-1"><input class="form-check-input" type="checkbox" name="auto_per_diem" value="1" id="pdAuto" <?= $autoPerDiem ? 'checked' : '' ?>>
            <label class="form-check-label" for="pdAuto">คำนวณจากเวลารายวัน (เกิน 12 ชม. = 1 วัน · เกิน 6 ชม. = ครึ่งวัน)</label></div>
          <div class="row g-2 mb-3">
            <div class="col-3"><label class="form-label small mb-0">วัน</label><input type="number" step="0.5" min="0" name="per_diem_days" id="pdDays" class="form-control form-control-sm" value="<?= (float)$c['per_diem_days'] ?>"></div>
            <div class="col-4"><label class="form-label small mb-0">หมายเหตุอัตรา</label><input name="per_diem_note" id="pdNote" class="form-control form-control-sm" value="<?= h($c['per_diem_note']) ?>"></div>
            <div class="col-5"><label class="form-label small mb-0">รวม (บาท)</label><input type="number" step="0.01" min="0" name="per_diem_amount" id="pdAmount" class="form-control form-control-sm" value="<?= (float)$c['per_diem_amount'] ?>"></div>
          </div>
          <div class="fw-semibold small mb-1">ค่าเช่าที่พัก</div>
          <div class="row g-2 mb-3">
            <div class="col-5"><input name="lodging_type" class="form-control form-control-sm" maxlength="100" value="<?= h($c['lodging_type']) ?>" placeholder="ประเภท"></div>
            <div class="col-3"><input type="number" step="0.5" min="0" name="lodging_days" class="form-control form-control-sm" value="<?= (float)$c['lodging_days'] ?: '' ?>" placeholder="วัน"></div>
            <div class="col-4"><input type="number" step="0.01" min="0" name="lodging_amount" id="lgAmount" class="form-control form-control-sm c-calc" value="<?= (float)$c['lodging_amount'] ?: '' ?>" placeholder="บาท"></div>
          </div>
          <div class="fw-semibold small mb-1">ค่าพาหนะ</div>
          <div class="row g-2 mb-1">
            <div class="col-7"><select name="vehicle_kind" id="vhKind" class="form-select form-select-sm">
              <?php foreach (CLAIM_VEHICLES as $k => [$l, $r]): ?><option value="<?= $k ?>" data-rate="<?= $r ?>" <?= $c['vehicle_kind'] === $k ? 'selected' : '' ?>><?= h($l) ?><?= $r ? ' (กม.ละ ' . $r . ')' : '' ?></option><?php endforeach; ?></select></div>
            <div class="col-5"><input type="number" step="0.01" min="0" name="km_rate" id="kmRate" class="form-control form-control-sm d-calc" value="<?= (float)$c['km_rate'] ?>" title="บาท/กม."></div>
          </div>
          <input name="vehicle_desc" class="form-control form-control-sm mb-1" maxlength="255" value="<?= h($c['vehicle_desc']) ?>" placeholder="คำอธิบาย (ว่าง = สร้างจากระยะทางรวมอัตโนมัติ)">
          <div class="small mb-3">รวมระยะทาง <strong id="sumKm">0</strong> กม. = <strong id="sumVehicle">0.00</strong> บาท</div>
          <div class="fw-semibold small mb-1">ค่าใช้จ่ายอื่น</div>
          <div class="row g-2 mb-3">
            <div class="col-7"><input name="other_desc" class="form-control form-control-sm" maxlength="255" value="<?= h($c['other_desc']) ?>" placeholder="รายการ"></div>
            <div class="col-5"><input type="number" step="0.01" min="0" name="other_amount" id="otAmount" class="form-control form-control-sm c-calc" value="<?= (float)$c['other_amount'] ?: '' ?>" placeholder="บาท"></div>
          </div>
          <div class="border-top pt-2 d-flex"><span>รวมเงินทั้งสิ้น</span><strong class="ms-auto fs-5" id="sumTotal">0.00</strong></div>
          <div class="d-flex small text-muted"><span>ขอรับเงินเพียง (ปัดเศษสตางค์)</span><span class="ms-auto" id="sumReceive">0</span></div>
          <div class="small text-muted mt-1">จำนวนเงินตัวอักษร แสดงในเอกสารที่พิมพ์</div>
        </div>
        <div class="card-footer d-flex gap-2 flex-wrap">
          <button class="btn btn-primary" name="go" value="edit"><i class="bi bi-save"></i> บันทึก</button>
          <button class="btn btn-success" name="go" value="print"><i class="bi bi-printer"></i> บันทึกและพิมพ์</button>
        </div>
      </div>
    </div>
  </div>
</form>

<template id="dayTpl">
  <div class="claim-day border rounded p-2 mb-2">
    <div class="row g-2 align-items-end">
      <div class="col-auto fw-semibold day-no pt-2"></div>
      <div class="col-md-3 col-6"><label class="form-label small mb-0">วันที่ออกเดินทาง</label><input type="text" name="d_date[]" class="form-control form-control-sm thai-date-new d-date"></div>
      <div class="col-md-2 col-6"><label class="form-label small mb-0">เวลาออก</label><input type="time" name="d_dtime[]" class="form-control form-control-sm d-calc"></div>
      <div class="col-md-3 col-6"><label class="form-label small mb-0">วันที่กลับ <span class="text-muted">(ว่าง = วันเดียวกัน)</span></label><input type="text" name="d_rdate[]" class="form-control form-control-sm thai-date-new d-rdate"></div>
      <div class="col-md-2 col-6"><label class="form-label small mb-0">เวลากลับถึง</label><input type="time" name="d_rtime[]" class="form-control form-control-sm d-calc"></div>
      <div class="col-auto ms-auto"><button type="button" class="btn btn-sm btn-light text-danger d-remove" title="ลบวันนี้"><i class="bi bi-trash"></i></button></div>
      <div class="col-md-7"><label class="form-label small mb-0">สถานที่ที่ไปปฏิบัติงาน</label><textarea name="d_places[]" class="form-control form-control-sm d-places" rows="2" maxlength="2000"></textarea></div>
      <div class="col-md-2 col-6"><label class="form-label small mb-0">ระยะทางไปกลับ (กม.)</label><input type="number" step="0.01" min="0" name="d_km[]" class="form-control form-control-sm d-km d-calc"></div>
      <div class="col-md-3 col-6 small"><div class="text-muted">ค่าพาหนะ / เบี้ยเลี้ยง</div><div><span class="d-amount fw-semibold">-</span> · <span class="d-allow text-muted">-</span></div></div>
      <div class="col-12"><input name="d_extra[]" class="form-control form-control-sm" maxlength="2000" placeholder="รายการค่าใช้จ่ายที่ขอเบิกนอกเหนือจากเบี้ยเลี้ยง ที่พัก ค่าพาหนะ (แบบประกอบ) — ว่าง = ใช้ข้อความ &quot;เดินทางไปปฏิบัติราชการ&quot;"></div>
    </div>
  </div>
</template>
<style>
  @media (min-width: 1200px) { .claim-sum { position: sticky; top: 76px; } }
  .claim-day.is-target { border-color: #0d6efd !important; box-shadow: 0 0 0 .15rem rgba(13, 110, 253, .15); }
</style>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/th.js" integrity="sha384-E6OVFg1zUc/pckGxry0YZ6VeZ1E17l7Jl8ZQx58jbEfxxkz9LqknQhiIrA9zg/8b" crossorigin="anonymous"></script>
<script src="<?= h(asset_v('modules/calendar/assets/js/thai-picker.js')) ?>"></script>
<script>
(() => {
  const list = document.getElementById('dayList');
  const $ = id => document.getElementById(id);
  const num = v => parseFloat(String(v || '').replace(/,/g, '')) || 0;
  const money = n => n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  let target = null;                                   // แถวที่เลือกล่าสุด (เพิ่มสถานที่ อปท.)
  // เบี้ยเลี้ยงของ 1 แถว: เหมือน claim_allowance_days() ฝั่งเซิร์ฟเวอร์
  const allowance = row => {
    const d = row.querySelector('.d-date').value, rd = row.querySelector('.d-rdate').value || d;
    const [t1, t2] = [...row.querySelectorAll('input[type=time]')].map(i => i.value);
    if (!d || !t1 || !t2) return 0;
    const s = new Date(d + 'T' + t1), e = new Date(rd + 'T' + t2);
    if (!(e > s)) return 0;
    const h = (e - s) / 3600000; let full = Math.floor(h / 24); const rem = h - full * 24;
    if (rem > 12) full += 1; else if (rem > 6) full += full ? 1 : 0.5;
    return full;
  };
  const renumber = () => list.querySelectorAll('.claim-day').forEach((r, i) => { r.querySelector('.day-no').textContent = i + 1; });
  const recalc = () => {
    const rate = num($('kmRate').value), full = num($('pdRate').value);
    let km = 0, veh = 0; const units = [];
    list.querySelectorAll('.claim-day').forEach(r => {
      const k = num(r.querySelector('.d-km').value), amt = Math.round(k * rate * 100) / 100;
      km += k; veh += amt;
      const a = allowance(r);
      if (a > 0) units.push(a);
      r.querySelector('.d-amount').textContent = k ? money(amt) + ' บาท' : '-';
      r.querySelector('.d-allow').textContent = a ? (a === 0.5 ? 'ครึ่งวัน' : a + ' วัน') : '-';
    });
    $('sumKm').textContent = km.toLocaleString('th-TH', { maximumFractionDigits: 2 });
    $('sumVehicle').textContent = money(veh);
    if ($('pdAuto').checked) {
      const groups = {};
      units.forEach(u => { const n = Math.ceil(u), per = u >= 1 ? full * u / n : full * u; groups[per] = (groups[per] || 0) + n; });
      $('pdDays').value = units.reduce((s, u) => s + Math.ceil(u), 0);
      $('pdAmount').value = (Math.round(units.reduce((s, u) => s + u, 0) * full * 100) / 100).toFixed(2);
      $('pdNote').value = Object.keys(groups).length ? '(' + Object.entries(groups).map(([p, n]) => (+p) + 'x' + n).join(' + ') + ')' : '';
    }
    ['pdDays', 'pdAmount', 'pdNote'].forEach(i => { $(i).readOnly = $('pdAuto').checked; });
    const total = Math.round((num($('pdAmount').value) + num($('lgAmount').value) + veh + num($('otAmount').value)) * 100) / 100;
    $('sumTotal').textContent = money(total);
    $('sumReceive').textContent = Math.floor(total).toLocaleString('th-TH') + ' บาท';
  };
  const bind = row => {
    row.addEventListener('focusin', () => { list.querySelectorAll('.is-target').forEach(r => r.classList.remove('is-target')); row.classList.add('is-target'); target = row; });
    row.querySelector('.d-remove').addEventListener('click', () => {
      if (list.querySelectorAll('.claim-day').length > 1) { row.remove(); } else { row.querySelectorAll('input, textarea').forEach(i => { i._flatpickr ? i._flatpickr.clear() : i.value = ''; }); }
      renumber(); recalc();
    });
  };
  list.querySelectorAll('.claim-day').forEach(bind);
  $('addDay').addEventListener('click', () => {
    const rows = list.querySelectorAll('.claim-day'), last = rows[rows.length - 1];
    const row = $('dayTpl').content.firstElementChild.cloneNode(true);
    list.appendChild(row);
    row.querySelectorAll('.thai-date-new').forEach(el => { el.classList.add('thai-date'); if (window.thaiDatePicker) window.thaiDatePicker(el); });
    // ค่าตั้งต้นจากแถวก่อนหน้า: เวลาออก/กลับ (วันที่ = วันถัดไป)
    if (last) {
      const t = [...last.querySelectorAll('input[type=time]')].map(i => i.value);
      [...row.querySelectorAll('input[type=time]')].forEach((i, k) => { i.value = t[k] || ''; });
      const d = last.querySelector('.d-date').value;
      if (d) { const nd = new Date(d + 'T00:00'); nd.setDate(nd.getDate() + 1); const v = nd.getFullYear() + '-' + String(nd.getMonth() + 1).padStart(2, '0') + '-' + String(nd.getDate()).padStart(2, '0'); const fp = row.querySelector('.d-date')._flatpickr; fp ? fp.setDate(v, true) : row.querySelector('.d-date').value = v; }
    }
    bind(row); renumber(); recalc();
    row.querySelector('.d-places').focus();
  });
  $('localAdd').addEventListener('click', () => {
    const v = $('localPick').value.trim();
    const row = target || list.querySelector('.claim-day:last-child');
    if (!v || !row) return;
    const ta = row.querySelector('.d-places');
    ta.value = ta.value.trim() ? ta.value.trim() + ', ' + v : v;
    $('localPick').value = '';
  });
  // โครงการ "อื่น ๆ" → แสดงช่องพิมพ์ชื่อโครงการ
  const projSync = () => {
    const other = $('projSel').value === 'other';
    $('projOtherCol').classList.toggle('d-none', !other);
    $('projCol').className = other ? 'col-md-5' : 'col-12';
    $('projOther').required = other;
    if (other && document.activeElement === $('projSel')) { $('projOther').focus(); }
  };
  $('projSel').addEventListener('change', projSync);
  $('vhKind').addEventListener('change', () => { const r = $('vhKind').selectedOptions[0].dataset.rate; if (+r) { $('kmRate').value = r; } recalc(); });
  $('pdType').addEventListener('change', () => { $('pdRate').value = $('pdType').selectedOptions[0].dataset.rate; recalc(); });
  document.getElementById('claimForm').addEventListener('input', recalc);
  document.getElementById('claimForm').addEventListener('change', recalc);
  recalc();
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
