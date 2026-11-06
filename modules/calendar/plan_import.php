<?php
/**
 * นำเข้าแผนการปฏิบัติงานจากไฟล์ Excel รูปแบบเดิม (ผู้ดูแลปฏิทิน)
 * 1) อัปโหลด .xlsx → อ่านเดือนจากหัวตาราง (เลือกเองได้) · จับคู่ชื่อกับบัญชีผู้ใช้ · ตรวจรหัสงาน
 * 2) ตรวจสอบ/แก้การจับคู่ชื่อ → บันทึก (แทนที่แผนเดิมของเดือนนั้น) + สร้างเวรศาลากลางในปฏิทิน
 * ช่อง "เวร" ในไฟล์ไม่นำเข้า (เวรสำนักงานใช้ข้อมูลในปฏิทิน) — แสดงรายการที่ไม่ตรงกับปฏิทินให้ตรวจ
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/plan_lib.php';
$me = require_module(CAL_MODULE, 'admin');
$sessKey = 'cal_plan_import';
$codes = plan_codes();
[$y0, $m0] = plan_ym($_GET['m'] ?? date('Y-m', strtotime('first day of next month')));
$errors = [];
$step = 'upload';
$data = null;

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
            $res = plan_parse_xlsx($f['tmp_name'], $codes);
            $errors = $res['errors'];
            $month = post('month') === 'auto' ? $res['month'] : plan_ym(post('month'));
            if (!$errors && !$month) {
                $errors[] = 'อ่านเดือนจากหัวตารางไม่ได้ — กรุณาเลือกเดือนของแผนเอง';
            }
            if (!$errors) {
                $_SESSION[$sessKey] = ['file' => $f['name'], 'month' => $month, 'res' => $res];
                $step = 'preview';
            }
        }
    } elseif ($action === 'import' && !empty($_SESSION[$sessKey])) {
        $data = $_SESSION[$sessKey];
        [$y, $m] = $data['month'];
        $valid = array_map('intval', db()->query('SELECT id FROM core_users WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN));
        $people = [];
        $usedUsers = [];
        foreach ($data['res']['people'] as $i => $p) {
            $sel = $_POST['map'][$i] ?? '';
            if ($sel === 'skip') {
                continue;
            }
            $uid = ctype_digit((string)$sel) && in_array((int)$sel, $valid, true) ? (int)$sel : null;
            if ($uid && isset($usedUsers[$uid])) {
                $errors[] = 'เลือกบัญชีผู้ใช้ซ้ำกัน: "' . $p['name'] . '" และ "' . $usedUsers[$uid] . '"';
            }
            if ($uid) {
                $usedUsers[$uid] = $p['name'];
            }
            $people[] = ['user_id' => $uid, 'name' => $p['name'], 'cells' => $p['cells']];
        }
        if (!$errors) {
            $r = plan_save($y, $m, $people, plan_get($y, $m)['note'] ?? null, (int)$me['id']);
            unset($_SESSION[$sessKey]);
            audit_log('cal_plan_import', 'นำเข้าแผนการปฏิบัติงาน ' . plan_month_text($y, $m) . ' จาก ' . $data['file'] . ' (' . count($people) . ' คน)', CAL_MODULE);
            [$gc, $gu, $gd] = $r['guard'];
            flash('นำเข้าแผนการปฏิบัติงานเดือน' . plan_month_text($y, $m) . ' แล้ว ' . count($people) . ' คน'
                . ($gc + $gu + $gd ? ' · เวรศาลากลางในปฏิทิน: เพิ่ม ' . $gc . ' วัน ปรับ ' . $gu . ' วัน ลบ ' . $gd . ' วัน' : ''));
            redirect('plan.php?m=' . sprintf('%04d-%02d', $y, $m));
        }
        $step = 'preview';
    } elseif ($action === 'cancel') {
        unset($_SESSION[$sessKey]);
        redirect('plan_import.php');
    }
}

if ($step === 'preview') {
    $data ??= $_SESSION[$sessKey];
    [$y, $m] = $data['month'];
    $res = $data['res'];
    $existing = plan_get($y, $m);
    $users = db()->query('SELECT id, username, sort_order, full_name, position FROM core_users WHERE is_active = 1')->fetchAll();
    $users = array_values(sort_staff($users));
    // เวรสำนักงาน: เทียบไฟล์กับปฏิทิน (แสดงเพื่อตรวจ ไม่นำเข้า)
    $cal = plan_duty_map($y, $m);
    $fileDuty = [];
    foreach ($res['duty'] as [$uid, $name, $d]) {
        $fileDuty[$uid ? 'u' . $uid : 'n' . $name][$d] = $name;
    }
    $dutyDiff = [];
    foreach ($fileDuty as $k => $byDay) {
        foreach ($byDay as $d => $name) {
            if ($k[0] !== 'u' || empty($cal[(int)substr($k, 1)][$d])) {
                $dutyDiff[] = [$name, $d, 'มีในไฟล์ แต่ไม่มีในปฏิทิน'];
            }
        }
    }
    $nameOf = array_column($users, 'full_name', 'id');
    foreach ($cal as $uid => $byDay) {
        foreach ($byDay as $d => $_) {
            if (empty($fileDuty['u' . $uid][$d])) {
                $dutyDiff[] = [$nameOf[$uid] ?? ('#' . $uid), $d, 'มีในปฏิทิน แต่ไม่มีในไฟล์'];
            }
        }
    }
    usort($dutyDiff, fn($a, $b) => $a[1] <=> $b[1]);
}

$pageTitle = 'นำเข้าแผนการปฏิบัติงาน';
$active = 'plan';
$pageActions = '<a href="plan.php?m=' . sprintf('%04d-%02d', $y0, $m0) . '" class="btn btn-light"><i class="bi bi-grid-3x3"></i> แผนการปฏิบัติงาน</a>';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<?php if ($step === 'upload'): ?>
<div class="row g-3">
  <div class="col-lg-6">
    <form method="post" enctype="multipart/form-data" class="card">
      <?= csrf_field() ?><input type="hidden" name="action" value="preview">
      <div class="card-header"><strong><i class="bi bi-file-earmark-excel"></i> เลือกไฟล์แผนการปฏิบัติงาน</strong></div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label" for="planFile">ไฟล์ Excel (.xlsx)</label>
          <input type="file" name="file" id="planFile" class="form-control" accept=".xlsx" required>
        </div>
        <div>
          <label class="form-label" for="planMonth">เดือนของแผน</label>
          <select name="month" id="planMonth" class="form-select">
            <option value="auto">อ่านจากหัวตารางในไฟล์ (อัตโนมัติ)</option>
            <?php for ($i = -2; $i <= 6; $i++): $t = strtotime(date('Y-m-01') . " $i month"); ?>
              <option value="<?= date('Y-m', $t) ?>"><?= h(plan_month_text((int)date('Y', $t), (int)date('n', $t))) ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
      <div class="card-footer"><button class="btn btn-primary"><i class="bi bi-search"></i> ตรวจสอบข้อมูล</button></div>
    </form>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-body small">
      <strong><i class="bi bi-info-circle"></i> รูปแบบไฟล์</strong>
      <ul class="mt-2 mb-0">
        <li>ใช้ไฟล์ "แผนการปฏิบัติงานสนามโครงการต่าง ๆ ประจำเดือน …" รูปแบบเดิมได้เลย (หัวตารางมีวันที่ 1–31 และชื่อ-นามสกุลอยู่ทางซ้าย)</li>
        <li>รหัสในช่อง: <?php foreach ($codes as $c): if ($c['kind'] === 'field' && $c['is_active']): ?><code><?= h($c['code']) ?></code> <?php endif; endforeach; ?> · <code>*</code> = เวรศาลากลาง (เช่น <code>สพค*</code>)</li>
        <li><code>เวร</code> (เวรสำนักงาน) ไม่นำเข้า — ระบบใช้เวรสำนักงานจากปฏิทิน และจะแสดงจุดที่ไม่ตรงกันให้ตรวจ</li>
        <li>คอลัมน์สรุปจำนวนวันในไฟล์ไม่ใช้ — ระบบคำนวณให้เอง</li>
        <li>นำเข้าเดือนที่มีแผนอยู่แล้ว = แทนที่แผนเดิมของเดือนนั้นทั้งหมด</li>
      </ul>
    </div></div>
  </div>
</div>

<?php else: ?>
<form method="post">
  <?= csrf_field() ?>
  <div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-3 align-items-center">
      <div><div class="small text-muted">ไฟล์</div><b><?= h($data['file']) ?></b></div>
      <div><div class="small text-muted">เดือนของแผน</div><b><?= h(plan_month_text($y, $m)) ?></b></div>
      <div><div class="small text-muted">รายชื่อ</div><b><?= count($res['people']) ?> คน</b></div>
      <?php if ($existing): ?><div class="alert alert-warning py-2 mb-0 ms-lg-auto"><i class="bi bi-exclamation-triangle"></i> มีแผนเดือนนี้อยู่แล้ว — นำเข้าแล้วจะ<b>แทนที่แผนเดิม</b></div><?php endif; ?>
    </div>
  </div>

  <?php if ($res['unknown']): ?>
    <div class="alert alert-warning">
      <b><i class="bi bi-question-circle"></i> รหัสที่ไม่รู้จัก (จะไม่ถูกนำเข้า):</b>
      <?php foreach ($res['unknown'] as [$n, $d, $code]): ?><span class="badge text-bg-light border me-1"><?= h(preg_replace('/\s+/u', ' ', $n)) ?> · วันที่ <?= $d ?> · "<?= h($code) ?>"</span><?php endforeach; ?>
      <div class="small mt-1">ถ้าเป็นรหัสโครงการใหม่ ให้เพิ่มที่ <a href="<?= h(module_url('ea', 'manage_projects.php')) ?>" target="_blank">โครงการที่รับผิดชอบ</a> แล้วนำเข้าใหม่ · ถ้าพิมพ์ผิด แก้ได้ที่หน้าแก้ไขแผนหลังนำเข้า</div>
    </div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-header"><strong><i class="bi bi-people"></i> จับคู่รายชื่อกับบัญชีผู้ใช้</strong>
      <span class="small text-muted">— ตรวจรายการ "ใกล้เคียง" / "ไม่พบ" แล้วเลือกให้ถูกต้อง</span></div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th class="text-center">ที่</th><th>ชื่อในไฟล์</th><th>สถานะ</th><th style="min-width:280px">บัญชีผู้ใช้ในระบบ</th><th class="text-center">งานสนาม</th><th class="text-center">เวรศาลากลาง</th></tr></thead>
        <tbody>
        <?php foreach ($res['people'] as $i => $p):
          $field = 0; $guard = 0;
          foreach ($p['cells'] as $ids) {
              foreach ($ids as $cid) { $codes[$cid]['kind'] === 'guard' ? $guard++ : $field++; }
          }
          $sel = $_POST['map'][$i] ?? ($p['user_id'] ? (string)$p['user_id'] : ''); ?>
          <tr class="<?= $p['match'] === 'near' ? 'table-warning' : ($p['match'] === '' ? 'table-light' : '') ?>">
            <td class="text-center text-muted"><?= $i + 1 ?></td>
            <td><?= h($p['name']) ?></td>
            <td class="small text-nowrap"><?= match ($p['match']) {
                'exact' => '<span class="text-success"><i class="bi bi-check-circle-fill"></i> ตรงกัน</span>',
                'near'  => '<span class="text-warning-emphasis"><i class="bi bi-exclamation-circle-fill"></i> ใกล้เคียง — ตรวจสอบ</span>',
                default => '<span class="text-muted"><i class="bi bi-person-dash"></i> ไม่พบในระบบ</span>' } ?></td>
            <td>
              <select name="map[<?= $i ?>]" class="form-select form-select-sm">
                <option value=""<?= $sel === '' ? ' selected' : '' ?>>— ไม่มีบัญชี (ใช้ชื่อตามไฟล์) —</option>
                <option value="skip"<?= $sel === 'skip' ? ' selected' : '' ?>>✕ ไม่นำเข้าคนนี้</option>
                <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $sel === (string)$u['id'] ? ' selected' : '' ?>><?= h(preg_replace('/\s+/u', ' ', $u['full_name'])) ?></option><?php endforeach; ?>
              </select>
            </td>
            <td class="text-center"><?= $field ?: '-' ?></td>
            <td class="text-center"><?= $guard ?: '-' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($dutyDiff): ?>
    <details class="card mb-3">
      <summary class="card-header"><strong><i class="bi bi-shield-check text-success"></i> เวรสำนักงาน: ไฟล์กับปฏิทินไม่ตรงกัน <?= count($dutyDiff) ?> รายการ</strong>
        <span class="small text-muted">(ไม่นำเข้า — ระบบใช้ข้อมูลในปฏิทิน แก้ได้ที่ "นำเข้าเวรสำนักงาน")</span></summary>
      <div class="card-body small">
        <?php foreach ($dutyDiff as [$n, $d, $why]): ?><div>วันที่ <?= $d ?> · <?= h(preg_replace('/\s+/u', ' ', $n)) ?> — <span class="text-muted"><?= h($why) ?></span></div><?php endforeach; ?>
      </div>
    </details>
  <?php else: ?>
    <div class="alert alert-success py-2 small"><i class="bi bi-check2-circle"></i> เวรสำนักงานในไฟล์ตรงกับปฏิทิน</div>
  <?php endif; ?>

  <div class="d-flex gap-2">
    <button name="action" value="cancel" class="btn btn-outline-secondary" formnovalidate>ยกเลิก</button>
    <button name="action" value="import" class="btn btn-primary ms-auto"><i class="bi bi-check2"></i> นำเข้าแผนเดือน<?= h(plan_month_text($y, $m)) ?></button>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
