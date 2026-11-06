<?php
/**
 * นำเข้า EA ของโครงการจากไฟล์ EA_Report (.csv / .xlsx) — เจ้าหน้าที่โครงการ (editor)
 * 1 แถว = EA ที่โครงการใช้แจงนับในเดือนนั้น: psu_no, รหัสอำเภอ, อำเภอ, รหัสตำบล, ตำบล, ea, รหัสหมู่บ้าน, หมู่บ้าน,
 *   latitude, longitude, enumerate_month, enumerate_type, enumerate_year, project, เขตเทศบาล, ปีงบประมาณ
 * รหัส EA 11 หลัก = จังหวัด + รหัสอำเภอ + รหัสตำบล + เขตเทศบาล (1 ใน / 2 นอก) + ea · รอบ/งวด = เดือน + ปีที่แจงนับ
 * นำเข้าซ้ำได้: ปรับปรุง PSU/ประเภท/ปีงบประมาณ/พิกัด ของรายการเดิม — ผู้รับผิดชอบและสถานะคงเดิม
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(EA_MODULE, 'editor');
$pdo = db();
$sessKey = 'ea_project_import';
$tmpDir = APP_ROOT . '/storage/tmp';
$errors = [];

$tmpFile = fn(?string $token) => $token && preg_match('/^[a-f0-9]{32}$/', $token) ? $tmpDir . '/ea_pimport_' . $token . '.json' : null;
$clear = function () use ($sessKey, $tmpFile) {
    $f = $tmpFile($_SESSION[$sessKey]['token'] ?? null);
    if ($f && is_file($f)) {
        @unlink($f);
    }
    unset($_SESSION[$sessKey]);
};
$load = function () use ($sessKey, $tmpFile) {
    $f = $tmpFile($_SESSION[$sessKey]['token'] ?? null);
    return $f && is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
};
$projects = projects_all();
// จับคู่รหัสโครงการในไฟล์กับโครงการในระบบ (ตัดจุด/ช่องว่าง)
$autoMap = function (array $fileProjects) use ($projects): array {
    $byKey = [];
    foreach ($projects as $p) {
        $byKey[ea_project_key($p['code'])] = (int)$p['id'];
    }
    $map = [];
    foreach ($fileProjects as $k => $raw) {
        $map[$k] = $byKey[$k] ?? null;
    }
    return $map;
};
$eaIds = fn() => $pdo->query('SELECT ea_code, id FROM ea_areas')->fetchAll(PDO::FETCH_KEY_PAIR);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'preview') {
        $f = $_FILES['file'] ?? null;
        $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $errors[] = 'กรุณาเลือกไฟล์ (.csv หรือ .xlsx)';
        } elseif (!in_array($ext, ['csv', 'xlsx'], true)) {
            $errors[] = 'รองรับเฉพาะไฟล์ .csv และ .xlsx';
        } else {
            @set_time_limit(120);
            $province = (string)($pdo->query('SELECT province_code FROM ea_areas GROUP BY province_code ORDER BY COUNT(*) DESC LIMIT 1')->fetchColumn() ?: '40');
            $res = ea_parse_project_file($f['tmp_name'], $ext, $province);
            $errors = $res['errors'];
            if (!$errors) {
                $clear();
                if (!is_dir($tmpDir)) {
                    @mkdir($tmpDir, 0770, true);
                }
                $token = bin2hex(random_bytes(16));
                file_put_contents($tmpFile($token), json_encode($res, JSON_UNESCAPED_UNICODE));
                $_SESSION[$sessKey] = ['token' => $token, 'file' => mb_substr($f['name'], 0, 150), 'province' => $province];
                redirect('project_import.php?step=preview');
            }
        }
    } elseif ($action === 'import') {
        $res = $load();
        if (!$res) {
            flash('ไม่พบข้อมูลที่อ่านไว้ กรุณาอัปโหลดไฟล์อีกครั้ง', 'warning');
            redirect('project_import.php');
        }
        @set_time_limit(300);
        // โครงการที่เลือกสำหรับรหัสในไฟล์ (ไม่เลือก = ข้าม)
        $map = [];
        foreach ($res['projects'] as $k => $raw) {
            $v = (string)($_POST['map'][$k] ?? '');
            $map[$k] = ctype_digit($v) && isset($projects[(int)$v]) ? (int)$v : null;
        }
        $createMissing = post('create_missing') === '1';
        $sync = post('sync') === '1';
        $province = $_SESSION[$sessKey]['province'] ?? '40';
        $source = 'เพิ่มจากไฟล์ EA ของโครงการ ' . ($_SESSION[$sessKey]['file'] ?? '');
        $n = ['new' => 0, 'upd' => 0, 'same' => 0, 'created' => 0, 'skipped' => 0, 'removed' => 0];
        $pdo->beginTransaction();
        try {
            $ids = $eaIds();
            $insEa = $pdo->prepare('INSERT INTO ea_areas (ea_code, region_code, region_name, province_code, province_name, district_code, district_name, tambon_code, tambon_name,
                                    area_type, ea_no, village_code, village_name, note, source)
                                    SELECT ?, region_code, region_name, province_code, province_name, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? FROM ea_areas WHERE province_code = ? LIMIT 1');
            $insEa0 = $pdo->prepare('INSERT INTO ea_areas (ea_code, province_code, district_code, district_name, tambon_code, tambon_name, area_type, ea_no, village_code, village_name, note, source)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $old = [];
            $st = $pdo->query('SELECT id, project_id, round_label, ea_id, psu_no, enum_type, enum_month, enum_year, fiscal_year, lat, lng FROM ea_project_eas');
            foreach ($st as $r) {
                $old[$r['project_id'] . '|' . $r['round_label'] . '|' . $r['ea_id']] = $r;
            }
            $ins = $pdo->prepare('INSERT INTO ea_project_eas (project_id, ea_id, round_label, psu_no, enum_type, enum_month, enum_year, fiscal_year, lat, lng, created_by)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $upd = $pdo->prepare('UPDATE ea_project_eas SET psu_no = ?, enum_type = ?, enum_month = ?, enum_year = ?, fiscal_year = ?, lat = ?, lng = ? WHERE id = ?');
            $touched = [];      // [project_id|round_label => [pe_id => true]]
            foreach ($res['rows'] as $r) {
                $pid = $map[$r['project_key']] ?? null;
                if (!$pid) {
                    $n['skipped']++;
                    continue;
                }
                $eid = $ids[$r['ea_code']] ?? null;
                if (!$eid) {
                    if (!$createMissing) {
                        $n['skipped']++;
                        continue;
                    }
                    $args = [$r['district_code'], $r['district_name'], $r['tambon_code'], $r['tambon_name'], $r['area_type'], $r['ea_no'], $r['village_code'], $r['village_name'],
                             'EA ที่ไม่มีในบัญชีเขตแจงนับ — เพิ่มจากไฟล์ EA ของโครงการ', mb_substr($source, 0, 255)];
                    $insEa->execute(array_merge([$r['ea_code']], $args, [$province]));
                    if (!$insEa->rowCount()) {
                        $insEa0->execute(array_merge([$r['ea_code'], $province], $args));
                    }
                    $eid = (int)$pdo->lastInsertId();
                    $ids[$r['ea_code']] = $eid;
                    $n['created']++;
                }
                $vals = [$r['psu_no'], $r['enum_type'], $r['enum_month'], $r['enum_year'], $r['fiscal_year'], $r['lat'], $r['lng']];
                $k = $pid . '|' . $r['round_label'] . '|' . $eid;
                $o = $old[$k] ?? null;
                if (!$o) {
                    $ins->execute(array_merge([$pid, $eid, $r['round_label']], $vals, [(int)$me['id']]));
                    $peId = (int)$pdo->lastInsertId();
                    $old[$k] = ['id' => $peId];
                    $n['new']++;
                } else {
                    $peId = (int)$o['id'];
                    $cur = [$o['psu_no'], $o['enum_type'], $o['enum_month'], $o['enum_year'], $o['fiscal_year'],
                            $o['lat'] === null ? null : round((float)$o['lat'], 7), $o['lng'] === null ? null : round((float)$o['lng'], 7)];
                    $norm = fn($a) => array_map(fn($v) => $v === null ? null : (string)$v, $a);
                    if ($norm($cur) !== $norm($vals)) {
                        $upd->execute(array_merge($vals, [$peId]));
                        $n['upd']++;
                    } else {
                        $n['same']++;
                    }
                }
                $touched[$pid . '|' . $r['round_label']][$peId] = true;
            }
            // นำ EA ที่ไม่อยู่ในไฟล์ออก (เฉพาะโครงการ + รอบที่มีในไฟล์)
            if ($sync && $touched) {
                $sel = $pdo->prepare('SELECT id FROM ea_project_eas WHERE project_id = ? AND round_label = ?');
                $del = $pdo->prepare('DELETE FROM ea_project_eas WHERE id = ?');
                foreach ($touched as $pk => $keep) {
                    [$pid, $rl] = explode('|', $pk, 2);
                    $sel->execute([(int)$pid, $rl]);
                    foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $id) {
                        if (!isset($keep[(int)$id])) {
                            $del->execute([$id]);
                            $n['removed']++;
                        }
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $file = $_SESSION[$sessKey]['file'] ?? '';
        $clear();
        $msg = 'นำเข้า EA ของโครงการแล้ว: เพิ่ม ' . number_format($n['new']) . ' · ปรับปรุง ' . number_format($n['upd']) . ' · ไม่เปลี่ยนแปลง ' . number_format($n['same'])
             . ($n['created'] ? ' · เพิ่ม EA ใหม่ในบัญชี ' . number_format($n['created']) : '')
             . ($n['removed'] ? ' · นำออก ' . number_format($n['removed']) : '')
             . ($n['skipped'] ? ' · ข้าม ' . number_format($n['skipped']) . ' แถว' : '');
        audit_log('ea_project_import', $msg . ' (ไฟล์ ' . $file . ')', EA_MODULE);
        flash($msg, $n['skipped'] ? 'warning' : 'success');
        $pids = array_values(array_unique(array_filter($map)));
        redirect(count($pids) === 1 ? 'project_eas.php?id=' . $pids[0] : 'projects.php');
    } elseif ($action === 'cancel') {
        $clear();
        redirect('project_import.php');
    }
}

$res = ($_GET['step'] ?? '') === 'preview' ? $load() : null;
if ($res) {
    $map = $autoMap($res['projects']);
    $ids = $eaIds();
    $old = [];
    foreach ($pdo->query('SELECT project_id, round_label, ea_id, psu_no, enum_type, enum_month, enum_year, fiscal_year, lat, lng FROM ea_project_eas') as $r) {
        $old[$r['project_id'] . '|' . $r['round_label'] . '|' . $r['ea_id']] = $r;
    }
    $sum = [];          // [project_key][round_label] => [n, new, upd, sort]
    $missing = [];
    foreach ($res['rows'] as $r) {
        $eid = $ids[$r['ea_code']] ?? null;
        if (!$eid) {
            $missing[$r['ea_code']] = $r;
        }
        $s = &$sum[$r['project_key']][$r['round_label']];
        $s ??= ['n' => 0, 'new' => 0, 'upd' => 0, 'type' => [], 'sort' => [$r['enum_year'] ?? 9999, $r['enum_month'] ?? 99]];
        $s['n']++;
        $s['type'][$r['enum_type']] = true;
        $pid = $map[$r['project_key']];
        $o = $pid && $eid ? ($old[$pid . '|' . $r['round_label'] . '|' . $eid] ?? null) : null;
        if (!$o) {
            $s['new']++;
        } elseif ((string)$o['psu_no'] !== $r['psu_no'] || (string)$o['enum_type'] !== $r['enum_type'] || (string)$o['fiscal_year'] !== (string)$r['fiscal_year']
                  || ($o['lat'] === null) !== ($r['lat'] === null) || abs((float)$o['lat'] - (float)$r['lat']) > 1e-7 || abs((float)$o['lng'] - (float)$r['lng']) > 1e-7) {
            $s['upd']++;
        }
        unset($s);
    }
    foreach ($sum as &$rounds) {
        uasort($rounds, fn($a, $b) => $a['sort'] <=> $b['sort']);
    }
    unset($rounds);
    // รายการเดิมในรอบเดียวกันที่ไม่อยู่ในไฟล์ (ถ้าเลือกนำออก)
    $inFile = [];
    foreach ($res['rows'] as $r) {
        if ($map[$r['project_key']] && isset($ids[$r['ea_code']])) {
            $inFile[$map[$r['project_key']] . '|' . $r['round_label']][$ids[$r['ea_code']]] = true;
        }
    }
    $removable = 0;
    foreach ($old as $k => $o) {
        $pk = $o['project_id'] . '|' . $o['round_label'];
        if (isset($inFile[$pk]) && !isset($inFile[$pk][(int)$o['ea_id']])) {
            $removable++;
        }
    }
}

$pageTitle = 'นำเข้า EA ของโครงการ';
$active = 'pimport';
require __DIR__ . '/header.php';
?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= h($e) ?></div><?php endforeach; ?>

<?php if (!$res): ?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><strong><i class="bi bi-filetype-csv text-success"></i> อัปโหลดไฟล์ EA ของโครงการ</strong></div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="action" value="preview">
          <input type="file" name="file" class="form-control mb-3" accept=".csv,.xlsx" required>
          <button class="btn btn-primary"><i class="bi bi-search"></i> อ่านไฟล์และตรวจสอบ</button>
          <span class="small text-muted ms-2">ยังไม่บันทึกจนกว่าจะกดยืนยัน</span>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><strong>รูปแบบไฟล์ (EA_Report.csv)</strong></div>
      <div class="card-body small">
        <p class="mb-2">ไฟล์ .csv (UTF-8 หรือ Windows-874) หรือ .xlsx แถวแรกเป็นหัวคอลัมน์ — 1 แถว = EA ที่โครงการใช้แจงนับในเดือนนั้น:</p>
        <div class="table-responsive mb-2"><table class="table table-sm table-bordered mb-0 small">
          <tr><?php foreach (EA_REPORT_HEAD as $c): ?><th class="text-nowrap"><?= h($c) ?></th><?php endforeach; ?></tr>
          <tr><?php foreach (['0077', '16', 'ภูเวียง', '05', 'กุดขอนแก่น', '0012', '13', 'แก่นพัฒนา', '16.5761', '102.5040', 'มกราคม', 'Q1', '2569', 'สรง.', 'นอกเขตเทศบาล', '2569'] as $v): ?><td class="text-nowrap"><?= h($v) ?></td><?php endforeach; ?></tr>
        </table></div>
        <ul class="mb-0">
          <li>รหัส EA 11 หลัก = จังหวัด + รหัสอำเภอ + รหัสตำบล + เขตเทศบาล (ในเขต = 1, นอกเขต = 2) + ea</li>
          <li>รอบ/งวด = เดือน + ปีที่แจงนับ เช่น "ม.ค. 2569" · enumerate_type: Q1–Q4 (รายไตรมาส) หรือ Month (รายเดือน)</li>
          <li>project จับคู่กับรหัสโครงการในระบบ (ไม่สนใจจุด เช่น "สรง." = สรง) — เลือกเองได้ในขั้นตรวจสอบ</li>
          <li>นำเข้าซ้ำได้: ปรับปรุง PSU ประเภท ปีงบประมาณ และพิกัด — ผู้รับผิดชอบและสถานะการสำรวจคงเดิม</li>
          <li>ไฟล์ที่ "Export CSV" จากหน้า EA ของโครงการ ใช้รูปแบบเดียวกันนี้ นำเข้ากลับได้</li>
        </ul>
      </div>
    </div>
  </div>
</div>
<?php else: ?>
<form method="post">
  <?= csrf_field() ?>
  <div class="row g-3 mb-3" data-reveal>
    <?php $newTotal = array_sum(array_map(fn($rs) => array_sum(array_column($rs, 'new')), $sum));
          $updTotal = array_sum(array_map(fn($rs) => array_sum(array_column($rs, 'upd')), $sum)); ?>
    <?php foreach ([
        ['แถวในไฟล์', count($res['rows']), 'bi-list-ol', 'primary'],
        ['เพิ่มใหม่', $newTotal, 'bi-plus-circle', 'success'],
        ['ปรับปรุง', $updTotal, 'bi-pencil-square', 'warning'],
        ['EA ที่ไม่มีในบัญชี', count($missing), 'bi-question-circle', count($missing) ? 'danger' : 'secondary'],
    ] as [$label, $v, $icon, $color]): ?>
    <div class="col-6 col-md-3 reveal-item">
      <div class="stat-card"><span class="stat-icon text-bg-<?= $color ?>"><i class="bi <?= $icon ?>"></i></span>
        <div><div class="stat-num"><?= number_format($v) ?></div><div class="stat-label"><?= $label ?></div></div></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="card mb-3">
    <div class="card-header"><strong>โครงการในไฟล์</strong> <span class="small text-muted">— ไฟล์ <?= h($_SESSION[$sessKey]['file'] ?? '') ?></span></div>
    <div class="table-responsive">
      <table class="table mb-0 ea-table">
        <thead><tr><th>project ในไฟล์</th><th style="width:320px">นำเข้าเป็นโครงการ</th><th>รอบ/งวด (จำนวน EA · เพิ่มใหม่ · ปรับปรุง)</th></tr></thead>
        <tbody>
        <?php foreach ($res['projects'] as $k => $raw): ?>
          <tr>
            <td class="fw-semibold"><?= h($raw) ?></td>
            <td><select name="map[<?= h($k) ?>]" class="form-select form-select-sm<?= $map[$k] ? '' : ' is-invalid' ?>">
              <option value="">— ข้าม ไม่นำเข้า —</option>
              <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $map[$k] === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['code'] . ' — ' . $p['name']) ?></option><?php endforeach; ?>
            </select>
            <?php if (!$map[$k]): ?><div class="small text-danger">ไม่พบโครงการรหัสนี้ — เลือกโครงการ หรือ<?= projects_can_manage() ? ' <a href="' . h(url('projects.php')) . '" target="_blank">เพิ่มโครงการ</a> แล้วอัปโหลดใหม่' : 'แจ้งผู้ดูแลเพิ่มโครงการ' ?></div><?php endif; ?></td>
            <td class="small"><?php foreach ($sum[$k] as $rl => $s): ?>
              <span class="d-inline-block me-3 text-nowrap"><strong><?= h($rl ?: 'ไม่ระบุรอบ') ?></strong>
                <span class="text-muted"><?= h(implode('/', array_filter(array_keys($s['type'])))) ?></span>
                <?= $s['n'] ?><?= $s['new'] ? ' · <span class="text-success">+' . $s['new'] . '</span>' : '' ?><?= $s['upd'] ? ' · <span class="text-warning">~' . $s['upd'] . '</span>' : '' ?></span>
            <?php endforeach; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($missing): ?>
  <div class="card mb-3 border-danger">
    <div class="card-header"><strong class="text-danger">EA ที่ไม่มีในบัญชีเขตแจงนับ (<?= count($missing) ?> EA)</strong></div>
    <div class="card-body">
      <div class="small mb-2"><?php foreach (array_slice($missing, 0, 40, true) as $c => $r): ?>
        <span class="d-inline-block me-3"><span class="ea-code"><?= h($r['ea_code']) ?></span> <span class="text-muted"><?= h(ea_place($r)) ?></span></span><?php endforeach; ?><?= count($missing) > 40 ? ' …' : '' ?></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="create_missing" value="1" id="cm" checked>
        <label class="form-check-label" for="cm">เพิ่ม EA เหล่านี้ในบัญชี EA (ข้อมูลพื้นที่จากไฟล์ จำนวนบ้าน/ครัวเรือน/ประชากร = 0) — ไม่เลือก = ข้ามแถวของ EA เหล่านี้</label></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <div class="form-check"><input class="form-check-input" type="checkbox" name="sync" value="1" id="sync">
        <label class="form-check-label" for="sync">นำ EA ออกจากโครงการ ในรอบ/งวดที่มีในไฟล์ ถ้าไม่อยู่ในไฟล์นี้ (<?= number_format($removable) ?> รายการ) — ใช้เมื่อไฟล์นี้เป็นรายชื่อ EA ฉบับล่าสุดที่แทนของเดิม</label></div>
    </div>
    <div class="card-footer d-flex gap-2">
      <button name="action" value="import" class="btn btn-success"><i class="bi bi-check-lg"></i> ยืนยันนำเข้า</button>
      <button name="action" value="cancel" class="btn btn-light">ยกเลิก</button>
    </div>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
