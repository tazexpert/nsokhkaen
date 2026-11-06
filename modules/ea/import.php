<?php
/**
 * นำเข้าบัญชีเขตแจงนับ (EA) จากไฟล์ Excel รูปแบบ "บัญชีเขตแจงนับ" (ผู้ดูแลระบบ EA)
 * 1) อัปโหลด .xlsx → อ่านและตรวจรหัส → เก็บผลอ่านไว้ชั่วคราวที่ storage/tmp
 * 2) ตรวจสรุป (เพิ่มใหม่ / ปรับปรุง / ไม่เปลี่ยน / ไม่อยู่ในไฟล์) → ยืนยันบันทึก
 *    (จับคู่ EA เดิมด้วย จังหวัด+อำเภอ+ตำบล+เขตการปกครอง+EA — รหัส 15 หลักปรับตามหมู่บ้านในไฟล์ใหม่)
 * ผู้ดูแลล้างบัญชี EA แล้วนำเข้าใหม่ได้ (ยืนยันด้วย PIN)
 * EA ที่โครงการใช้อยู่ไม่ถูกลบ — EA ที่ไม่อยู่ในไฟล์เลือกทำเครื่องหมาย "เลิกใช้" ได้
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(EA_MODULE, 'admin');
$pdo = db();
$sessKey = 'ea_import';
$tmpDir = APP_ROOT . '/storage/tmp';
$errors = [];
$step = 'upload';

$tmpFile = function (?string $token) use ($tmpDir): ?string {
    return $token && preg_match('/^[a-f0-9]{32}$/', $token) ? $tmpDir . '/ea_import_' . $token . '.json' : null;
};
$clear = function () use ($sessKey, $tmpFile) {
    $f = $tmpFile($_SESSION[$sessKey]['token'] ?? null);
    if ($f && is_file($f)) {
        @unlink($f);
    }
    unset($_SESSION[$sessKey]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'preview') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $errors[] = 'กรุณาเลือกไฟล์ Excel (.xlsx)';
        } elseif (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            $errors[] = 'รองรับเฉพาะไฟล์ .xlsx (ถ้าเป็น .xls ให้เปิดใน Excel แล้ว "บันทึกเป็น" .xlsx)';
        } else {
            @set_time_limit(120);
            $res = ea_parse_xlsx($f['tmp_name']);
            $errors = $res['errors'];
            if (!$errors) {
                $clear();
                if (!is_dir($tmpDir)) {
                    @mkdir($tmpDir, 0770, true);
                }
                $token = bin2hex(random_bytes(16));
                file_put_contents($tmpFile($token), json_encode($res, JSON_UNESCAPED_UNICODE));
                $_SESSION[$sessKey] = ['token' => $token, 'file' => mb_substr($f['name'], 0, 150)];
                redirect('import.php?step=preview');
            }
        }
    } elseif ($action === 'import') {
        $file = $tmpFile($_SESSION[$sessKey]['token'] ?? null);
        $res = $file && is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (!$res) {
            flash('ไม่พบข้อมูลที่อ่านไว้ กรุณาอัปโหลดไฟล์อีกครั้ง', 'warning');
            redirect('import.php');
        }
        @set_time_limit(300);
        $source = mb_substr(trim(post('source')), 0, 255);
        $existing = ea_existing_by_key();
        ea_resolve_codes($res['rows'], $existing);
        $n = ['new' => 0, 'upd' => 0, 'same' => 0, 'off' => 0];
        $stmts = [];
        $prep = function (string $sql) use (&$stmts, $pdo) {
            return $stmts[$sql] ??= $pdo->prepare($sql);
        };
        $pdo->beginTransaction();
        try {
            $inFile = [];
            foreach ($res['rows'] as $r) {
                $r['source'] = $source;
                $inFile[$r['ea_key']] = true;
                $old = $existing[$r['ea_key']] ?? null;
                // เฉพาะคอลัมน์ที่ไฟล์มีข้อมูล (SurveyEaHome ไม่มีหมู่บ้าน/ประชากร/หมายเหตุ → คงค่าเดิม)
                $cols = ea_row_cols($r);
                $vals = array_map(fn($c) => $r[$c], $cols);
                if (!$old) {
                    $prep('INSERT INTO ea_areas (' . implode(',', $cols) . ', is_active) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ', 1)')->execute($vals);
                    $n['new']++;
                } elseif (array_map('strval', $vals) !== array_map(fn($c) => (string)$old[$c], $cols) || !$old['is_active']) {
                    $prep('UPDATE ea_areas SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ', is_active = 1 WHERE id = ?')->execute(array_merge($vals, [$old['id']]));
                    $n['upd']++;
                } else {
                    $n['same']++;
                }
            }
            if (post('deactivate') === '1') {
                $off = $pdo->prepare('UPDATE ea_areas SET is_active = 0 WHERE id = ?');
                foreach ($existing as $key => $old) {
                    if (!isset($inFile[$key]) && $old['is_active']) {
                        $off->execute([$old['id']]);
                        $n['off']++;
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $fileName = $_SESSION[$sessKey]['file'] ?? '';
        $clear();
        $msg = 'นำเข้าบัญชี EA แล้ว: เพิ่มใหม่ ' . number_format($n['new']) . ' · ปรับปรุง ' . number_format($n['upd'])
             . ' · ไม่เปลี่ยนแปลง ' . number_format($n['same']) . ($n['off'] ? ' · ทำเครื่องหมายเลิกใช้ ' . number_format($n['off']) : '');
        audit_log('ea_import', $msg . ' (ไฟล์ ' . $fileName . ')', EA_MODULE);
        flash($msg);
        redirect('eas.php');
    } elseif ($action === 'clear') {
        // ล้างบัญชี EA (ยืนยันด้วย PIN — ผิด 5 ครั้ง พัก 15 นาที)
        $scope = post('scope') === 'all' ? 'all' : 'list';
        $st = $pdo->prepare('SELECT pin_hash FROM core_users WHERE id = ?');
        $st->execute([(int)$me['id']]);
        $hash = (string)$st->fetchColumn();
        $lock = $_SESSION['ea_clear_lock'] ?? ['n' => 0, 'until' => 0];
        if ($lock['until'] > time()) {
            $errors[] = 'ใส่ PIN ผิดหลายครั้ง กรุณารอ ' . ceil(($lock['until'] - time()) / 60) . ' นาทีแล้วลองใหม่';
        } elseif ($hash === '') {
            $errors[] = 'บัญชีของคุณยังไม่ได้ตั้ง PIN — กรุณาตั้ง PIN ที่ "บัญชีของฉัน" ก่อน';
        } elseif (!preg_match('/^\d{6}$/', post('pin')) || !password_verify(post('pin'), $hash)) {
            $lock['n']++;
            if ($lock['n'] >= 5) {
                $lock = ['n' => 0, 'until' => time() + 900];
            }
            $_SESSION['ea_clear_lock'] = $lock;
            $errors[] = 'PIN ไม่ถูกต้อง';
        }
        if (!$errors) {
            unset($_SESSION['ea_clear_lock']);
            $pdo->beginTransaction();
            if ($scope === 'all') {
                $pe = $pdo->exec('DELETE FROM ea_project_eas');
                $n = $pdo->exec('DELETE FROM ea_areas');
                $msg = 'ล้างบัญชี EA ทั้งหมดแล้ว: EA ' . number_format($n) . ' · EA ของโครงการและการมอบหมาย ' . number_format($pe) . ' รายการ';
            } else {
                // EA ที่โครงการใช้อยู่เก็บไว้ (ทำเครื่องหมายเลิกใช้) — นำเข้าใหม่แล้วกลับมาใช้งานตามคีย์เดิม
                $n = $pdo->exec('DELETE FROM ea_areas WHERE id NOT IN (SELECT ea_id FROM ea_project_eas)');
                $kept = $pdo->exec('UPDATE ea_areas SET is_active = 0');
                $msg = 'ล้างบัญชี EA แล้ว: ลบ ' . number_format($n) . ' EA' . ($kept ? ' · เก็บ ' . number_format($kept) . ' EA ที่โครงการใช้อยู่ (เลิกใช้จนกว่าจะนำเข้าใหม่)' : '');
            }
            $pdo->commit();
            if ($scope === 'all') {
                $pdo->exec('ALTER TABLE ea_project_eas AUTO_INCREMENT = 1');
                $pdo->exec('ALTER TABLE ea_areas AUTO_INCREMENT = 1');
            }
            audit_log('ea_clear', $msg, EA_MODULE);
            flash($msg . ' — นำเข้าบัญชี EA ใหม่ได้เลย');
            redirect('import.php');
        }
    } elseif ($action === 'cancel') {
        $clear();
        redirect('import.php');
    }
}

$res = null;
if (($_GET['step'] ?? '') === 'preview') {
    $file = $tmpFile($_SESSION[$sessKey]['token'] ?? null);
    $res = $file && is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    $step = $res ? 'preview' : 'upload';
}

if ($step === 'preview') {
    $existing = ea_existing_by_key();
    ea_resolve_codes($res['rows'], $existing);
    $sum = ['new' => 0, 'upd' => 0, 'same' => 0, 'houses' => 0, 'households' => 0, 'population' => 0, 'area1' => 0, 'area2' => 0, 'code' => 0];
    $changes = [];
    $districts = [];
    $tambons = [];
    $inFile = [];
    foreach ($res['rows'] as $r) {
        $inFile[$r['ea_key']] = true;
        $old = $existing[$r['ea_key']] ?? null;
        foreach (['houses', 'households', 'population'] as $c) {     // ไฟล์ไม่มีค่า = ใช้ค่าเดิม
            $sum[$c] += (int)($r[$c] ?? $old[$c] ?? 0);
        }
        $sum['area' . $r['area_type']]++;
        $districts[$r['district_code']] = true;
        $tambons[$r['district_code'] . $r['tambon_code']] = true;
        if (!$old) {
            $sum['new']++;
            continue;
        }
        if ($r['ea_code'] !== $old['ea_code']) {
            $sum['code']++;
        }
        $diff = [];
        foreach (array_diff(ea_row_cols($r), ['source']) as $c) {
            if ((string)$r[$c] !== (string)$old[$c]) {
                $diff[] = $c;
            }
        }
        if ($diff || !$old['is_active']) {
            $sum['upd']++;
            if (count($changes) < 50) {
                $changes[] = [$r, $old, $diff];
            }
        } else {
            $sum['same']++;
        }
    }
    $missing = array_values(array_filter($existing, fn($o) => !isset($inFile[ea_key($o)]) && $o['is_active']));
    $usedMissing = 0;
    if ($missing) {
        $usedSet = array_flip($pdo->query('SELECT DISTINCT ea_id FROM ea_project_eas')->fetchAll(PDO::FETCH_COLUMN));
        $usedMissing = count(array_filter($missing, fn($o) => isset($usedSet[$o['id']])));
    }
    $colLabel = ['ea_code' => 'รหัส EA', 'local_code' => 'รหัสเทศบาล/อบต.', 'local_name' => 'เทศบาล / อบต.', 'seq' => 'ลำดับที่', 'region_code' => 'รหัสภาค', 'region_name' => 'ภาค', 'province_code' => 'รหัสจังหวัด', 'province_name' => 'จังหวัด',
                 'district_code' => 'รหัสอำเภอ', 'district_name' => 'อำเภอ', 'tambon_code' => 'รหัสตำบล', 'tambon_name' => 'ตำบล',
                 'area_type' => 'เขตการปกครอง', 'ea_no' => 'EA', 'advance_ea' => 'EA เสนอผลล่วงหน้า', 'village_code' => 'รหัสหมู่บ้าน',
                 'village_name' => 'หมู่บ้าน', 'houses' => 'บ้าน', 'households' => 'ครัวเรือน', 'population' => 'ประชากร', 'note' => 'หมายเหตุ'];
}

$pageTitle = 'นำเข้าบัญชี EA';
$active = 'import';
require __DIR__ . '/header.php';
?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= h($e) ?></div><?php endforeach; ?>

<?php if ($step === 'upload'): ?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><strong><i class="bi bi-file-earmark-excel text-success"></i> อัปโหลดไฟล์บัญชีเขตแจงนับ</strong></div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="action" value="preview">
          <input type="file" name="file" class="form-control mb-3" accept=".xlsx" required>
          <button class="btn btn-primary"><i class="bi bi-search"></i> อ่านไฟล์และตรวจสอบ</button>
          <span class="small text-muted ms-2">ยังไม่บันทึกจนกว่าจะกดยืนยัน</span>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><strong>รูปแบบไฟล์ที่รองรับ</strong></div>
      <div class="card-body small">
        <p class="mb-1"><strong>ไฟล์ EaDetail.xlsx</strong> (บัญชี EA ล่าสุด) แถวแรกเป็นหัวคอลัมน์:</p>
        <div class="table-responsive mb-2"><table class="table table-sm table-bordered mb-0 small">
          <tr><?php foreach (['ลำดับที่', 'EA CODE', 'ภูมิภาค', 'จังหวัด', 'เขต/อำเภอ', 'แขวง/ตำบล', 'จำนวนบ้าน', 'ครัวเรือนนับจดได้', 'บ้านว่าง', 'จำนวนประชากร'] as $c): ?><th class="text-nowrap"><?= h($c) ?></th><?php endforeach; ?></tr>
          <tr><?php foreach (['1', '640210119720010', 'ตะวันออกเฉียงเหนือ', 'ขอนแก่น', 'ซำสูง', 'กระนวน', '188', '186', '', ''] as $v): ?><td class="text-nowrap"><?= h($v) ?></td><?php endforeach; ?></tr>
        </table></div>
        <p class="mb-2 text-muted">รหัสภาค จังหวัด อำเภอ ตำบล เขตการปกครอง เทศบาล/อบต. และ EA เติมจาก EA CODE อัตโนมัติ · ช่องว่าง = คงค่าเดิม
          · ยังรับไฟล์รูปแบบเดิม (SurveyEaHome, บัญชีเขตแจงนับ) ได้</p>
        <ul class="mb-0">
          <li>รหัส EA 15 หลัก = ภาค (1) + จังหวัด (2) + อำเภอ (2) + ตำบล (2) + เขตการปกครอง (1) + เทศบาล/อบต. (3) + EA (4)
            เช่น <span class="ea-code">6 40 01 01 1 999 0001</span> = <span class="ea-code">640010119990001</span> (999 = เทศบาลนครขอนแก่น)</li>
          <li>เขตการปกครอง: 1 = ในเขตเทศบาล · 2 = นอกเขตเทศบาล</li>
          <li>นำเข้าซ้ำ/สลับไฟล์ได้: EA เดิมถูกปรับปรุงตามไฟล์ใหม่ (จับคู่ด้วย จังหวัด+อำเภอ+ตำบล+เขตการปกครอง+EA) — ข้อมูล EA ของโครงการไม่ถูกลบ</li>
        </ul>
      </div>
    </div>
  </div>
  <?php
    $cnt = $pdo->query('SELECT (SELECT COUNT(*) FROM ea_areas) n, (SELECT COUNT(DISTINCT ea_id) FROM ea_project_eas) used, (SELECT COUNT(*) FROM ea_project_eas) pe')->fetch();
    $st = $pdo->prepare("SELECT pin_hash IS NOT NULL AND pin_hash <> '' FROM core_users WHERE id = ?");
    $st->execute([(int)$me['id']]);
    $hasPin = (bool)$st->fetchColumn();
  ?>
  <?php if ((int)$cnt['n']): ?>
  <div class="col-lg-6" id="clear">
    <form method="post" class="card border-danger" data-confirm="ยืนยันล้างบัญชี EA?">
      <?= csrf_field() ?><input type="hidden" name="action" value="clear">
      <div class="card-header text-danger"><strong><i class="bi bi-trash3"></i> ล้างบัญชี EA เพื่อนำเข้าใหม่</strong></div>
      <div class="card-body">
        <p class="small mb-2">บัญชีปัจจุบัน <strong><?= number_format((int)$cnt['n']) ?></strong> EA · โครงการใช้อยู่ <?= number_format((int)$cnt['used']) ?> EA (<?= number_format((int)$cnt['pe']) ?> รายการ)</p>
        <div class="form-check mb-1"><input class="form-check-input" type="radio" name="scope" value="list" id="cl1" checked>
          <label class="form-check-label" for="cl1">ล้างบัญชี EA — <strong>เก็บ EA ของโครงการและการมอบหมายไว้</strong>
            <div class="small text-muted">ลบ EA ที่ไม่มีโครงการใช้ · EA ที่โครงการใช้อยู่ทำเครื่องหมายเลิกใช้ แล้วกลับมาใช้งานเมื่อนำเข้าไฟล์ใหม่ที่มี EA นั้น</div></label></div>
        <div class="form-check mb-3"><input class="form-check-input" type="radio" name="scope" value="all" id="cl2">
          <label class="form-check-label" for="cl2">ล้างทั้งหมด — <strong class="text-danger">บัญชี EA + EA ของโครงการ + ผู้รับผิดชอบ/สถานะการสำรวจ</strong>
            <div class="small text-muted">เริ่มต้นใหม่ทั้งหมด กู้คืนไม่ได้</div></label></div>
        <?php if ($hasPin): ?>
        <div class="d-flex gap-2 align-items-center">
          <input type="password" name="pin" class="form-control" style="max-width:160px" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="PIN 6 หลัก" required autocomplete="off">
          <button class="btn btn-danger"><i class="bi bi-trash3"></i> ล้างบัญชี EA</button>
        </div>
        <?php else: ?>
        <div class="alert alert-warning py-2 mb-0 small">ต้องตั้ง PIN ที่ <a href="<?= h(url('account.php')) ?>">บัญชีของฉัน</a> ก่อน จึงจะล้างบัญชี EA ได้</div>
        <?php endif; ?>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="row g-3 mb-3" data-reveal>
  <?php foreach ([
      ['EA ในไฟล์', count($res['rows']), 'bi-map', 'primary'],
      ['เพิ่มใหม่', $sum['new'], 'bi-plus-circle', 'success'],
      ['ปรับปรุง', $sum['upd'], 'bi-pencil-square', 'warning'],
      ['ไม่เปลี่ยนแปลง', $sum['same'], 'bi-check2-circle', 'secondary'],
  ] as [$label, $v, $icon, $color]): ?>
  <div class="col-6 col-md-3 reveal-item">
    <div class="stat-card"><span class="stat-icon text-bg-<?= $color ?>"><i class="bi <?= $icon ?>"></i></span>
      <div><div class="stat-num"><?= number_format($v) ?></div><div class="stat-label"><?= $label ?></div></div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-3">
  <div class="card-body">
    <div class="row small g-2">
      <div class="col-md-6">ไฟล์: <strong><?= h($_SESSION[$sessKey]['file'] ?? '') ?></strong>
        <span class="text-muted">· รูปแบบ <?= ['detail' => 'EaDetail (บัญชี EA ล่าสุด)', 'survey' => 'SurveyEaHome', 'census' => 'บัญชีเขตแจงนับ'][$res['format'] ?? 'census'] ?? '' ?></span></div>
      <div class="col-md-6"><?= count($districts) ?> อำเภอ · <?= count($tambons) ?> ตำบล ·
        ในเขตเทศบาล <?= number_format($sum['area1']) ?> EA · นอกเขตเทศบาล <?= number_format($sum['area2']) ?> EA</div>
      <div class="col-md-6">บ้าน <?= number_format($sum['houses']) ?> · ครัวเรือน <?= number_format($sum['households']) ?> · ประชากร <?= number_format($sum['population']) ?> คน</div>
      <div class="col-md-6"><?= $sum['code'] ? 'รหัส EA เปลี่ยน ' . number_format($sum['code']) . ' EA (ตามรหัสเทศบาล/อบต.) · ' : '' ?><?= $res['skipped'] ? 'ข้ามแถวว่าง ' . number_format($res['skipped']) . ' แถว' : '' ?></div>
      <?php if (($res['format'] ?? '') === 'detail'): ?>
      <div class="col-12 text-muted">เติมรหัส ภาค จังหวัด อำเภอ ตำบล เขตการปกครอง เทศบาล/อบต. และ EA จากรหัส EA 15 หลัก ·
        ช่องว่างในไฟล์ (เช่น ประชากร) และข้อมูลที่ไฟล์ไม่มี (ชื่อเทศบาล/อบต. หมู่บ้าน หมายเหตุ) คงค่าเดิมในระบบ</div>
      <?php elseif (($res['format'] ?? '') === 'survey'): ?>
      <div class="col-12 text-muted">เติมรหัส ภาค จังหวัด อำเภอ ตำบล เขตการปกครอง เทศบาล/อบต. และ EA จากรหัส EA 15 หลัก ·
        "จำนวนบ้าน/อาคารฯ" → จำนวนครัวเรือน · หมู่บ้าน ประชากร หมายเหตุ คงค่าเดิมในระบบ</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($changes): ?>
<div class="card mb-3">
  <div class="card-header"><strong>ตัวอย่าง EA ที่จะปรับปรุง</strong> <span class="small text-muted">(แสดงไม่เกิน 50 รายการ)</span></div>
  <div class="table-responsive" style="max-height:360px">
    <table class="table table-sm mb-0 ea-table">
      <thead><tr><th>รหัส EA</th><th>รายการที่เปลี่ยน</th></tr></thead>
      <tbody>
      <?php foreach ($changes as [$r, $old, $diff]): ?>
        <tr><td class="ea-code"><?= h($r['ea_code']) ?></td>
          <td class="small"><?php if (!$old['is_active']): ?><div>กลับมาใช้งาน (เดิมเลิกใช้)</div><?php endif; ?>
            <?php foreach (array_diff($diff, ['seq']) as $c): ?><div><span class="text-muted"><?= h($colLabel[$c] ?? $c) ?>:</span>
              <del class="text-danger"><?= h(mb_strimwidth((string)$old[$c], 0, 80, '…')) ?></del> → <span class="text-success"><?= h(mb_strimwidth((string)$r[$c], 0, 80, '…')) ?></span></div><?php endforeach; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="card-body">
    <label class="form-label">ที่มาของบัญชี EA</label>
    <input name="source" class="form-control mb-3" maxlength="255" value="<?= h($res['source']) ?>" placeholder="เช่น โครงการสำมะโนประชากรและเคหะ พ.ศ.2568">
    <?php if ($missing): ?>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="deactivate" value="1" id="deact">
      <label class="form-check-label" for="deact">ทำเครื่องหมาย "เลิกใช้" EA ที่มีในระบบแต่ไม่อยู่ในไฟล์นี้ (<?= number_format(count($missing)) ?> EA<?= $usedMissing ? ' — ในจำนวนนี้มี ' . number_format($usedMissing) . ' EA ที่โครงการใช้อยู่ ข้อมูลโครงการยังคงอยู่' : '' ?>)</label>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-footer d-flex gap-2">
    <button name="action" value="import" class="btn btn-success"><i class="bi bi-check-lg"></i> ยืนยันนำเข้า <?= number_format(count($res['rows'])) ?> EA</button>
    <button name="action" value="cancel" class="btn btn-light" formnovalidate>ยกเลิก</button>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
