<?php
/**
 * นำเข้าข้อมูลสลิปจากไฟล์ Excel (.xlsx) / CSV — รูปแบบเดียวกับชีต "Payroll" ใน Google Sheet เดิม
 *   คอลัมน์: ชื่อ สกุล | เดือน | ปี | เลขที่ | อัตราเงินเดือน | ตกเบิก | รวมรายได้ | ภาษี | เงินประกันสังคม |
 *            เงินกู้สวัสดิการ | เงินกู้ ธอส. | เงินกู้ กยศ. | เงินกู้ธนาคาร(อิสลาม) | รวมรายจ่าย | เงินเดือนคงเหลือ | อนุมัติ
 * ขั้นตอน: อัปโหลด → ตรวจสอบ → ยืนยัน (สลิปที่นำเข้าจะ "รออนุมัติ" — ผู้อนุมัติเลือกใช้สถานะอนุมัติจากไฟล์ได้ สำหรับย้ายข้อมูลเดิม)
 */
require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/libs/simplexlsx/SimpleXLSX.php';
$me = require_module(PAY_MODULE, 'manager');
$pdo = db();
$sessKey = 'pay_import';

/** หัวคอลัมน์ → ฟิลด์ (ตรวจตามลำดับ; แต่ละฟิลด์ใช้คอลัมน์แรกที่ตรง) */
function pay_header_field(string $h): ?string
{
    $h = preg_replace('/\s+/u', '', $h);
    $rules = [
        'code' => fn($h) => in_array($h, ['เลขที่', 'รหัส', 'รหัสพนักงาน', 'username'], true),
        'name' => fn($h) => str_starts_with($h, 'ชื่อ'),
        'month' => fn($h) => $h === 'เดือน',
        'year' => fn($h) => in_array($h, ['ปี', 'ปีพ.ศ.', 'พ.ศ.'], true),
        'net_pay' => fn($h) => str_contains($h, 'คงเหลือ') || str_contains($h, 'สุทธิ'),
        'total_income' => fn($h) => str_contains($h, 'รวมรายได้') || str_contains($h, 'รวมรายรับ') || str_contains($h, 'รวมรายการรับ'),
        'total_deduct' => fn($h) => str_contains($h, 'รวมรายจ่าย') || str_contains($h, 'รวมรายการหัก'),
        'salary' => fn($h) => str_contains($h, 'อัตราเงินเดือน') || $h === 'เงินเดือน',
        'arrears' => fn($h) => str_contains($h, 'ตกเบิก'),
        'cola' => fn($h) => str_contains($h, 'ค่าครองชีพ'),
        'tax' => fn($h) => str_contains($h, 'ภาษี'),
        'social_security' => fn($h) => str_contains($h, 'ประกันสังคม'),
        'loan_welfare' => fn($h) => str_contains($h, 'สวัสดิการ'),
        'loan_ghb' => fn($h) => str_contains($h, 'ธอส'),
        'loan_slf' => fn($h) => str_contains($h, 'กยศ'),
        'loan_islamic' => fn($h) => str_contains($h, 'อิสลาม') || str_contains($h, 'ธนาคาร'),
        'approved' => fn($h) => str_starts_with($h, 'อนุมัติ'),
    ];
    foreach ($rules as $f => $test) {
        if ($test($h)) {
            return $f;
        }
    }
    return null;
}

/** อ่านไฟล์ → [rows, errors, hasApproval] */
function pay_parse_file(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $table = [];
    if ($ext === 'xlsx') {
        $x = \Shuchkin\SimpleXLSX::parse($path);
        if (!$x) {
            return [[], ['อ่านไฟล์ Excel ไม่ได้: ' . \Shuchkin\SimpleXLSX::parseError()], false];
        }
        $idx = array_search('Payroll', $x->sheetNames(), true);
        $table = $x->rows($idx === false ? 0 : $idx);
    } elseif ($ext === 'csv') {
        $raw = text_to_utf8(file_get_contents($path));     // ตัด BOM + แปลง Windows-874 (Excel ภาษาไทย)
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $table[] = $r;
        }
    } else {
        return [[], ['รองรับไฟล์ .xlsx หรือ .csv เท่านั้น'], false];
    }

    $map = null;
    $rows = [];
    $errors = [];
    foreach ($table as $i => $r) {
        if ($map === null) {
            $m = [];
            foreach ($r as $c => $h) {
                $f = pay_header_field((string)$h);
                if ($f && !isset($m[$f])) {
                    $m[$f] = $c;
                }
            }
            if (isset($m['code'], $m['month'], $m['year'], $m['name'])) {
                $map = $m;
            }
            continue;
        }
        $get = fn($f) => isset($map[$f]) ? ($r[$map[$f]] ?? '') : '';
        $code = trim((string)$get('code'));
        $nm = trim(preg_replace('/\s+/u', ' ', (string)$get('name')));
        if ($code === '' && $nm === '') {
            continue;
        }
        if (is_numeric($code) && !preg_match('/^0/', $code)) {
            $code = str_pad((string)(int)$code, 3, '0', STR_PAD_LEFT);      // 1 → 001
        }
        $month = (int)(float)$get('month');
        $year = (int)(float)$get('year');
        if ($year > 0 && $year < 2400) {
            $year += 543;
        }
        $line = $i + 1;
        if ($code === '' || $nm === '') {
            $errors[] = "แถว $line: ไม่มีเลขที่หรือชื่อ";
            continue;
        }
        if ($month < 1 || $month > 12 || $year < 2500 || $year > 2700) {
            $errors[] = "แถว $line ($code): เดือน/ปี ไม่ถูกต้อง";
            continue;
        }
        $row = ['line' => $line, 'code' => $code, 'full_name' => $nm, 'year' => $year, 'month' => $month,
                'approved' => isset($map['approved']) && (int)(float)$get('approved') === 1];
        foreach (array_keys(PAY_INCOME + PAY_DEDUCT + PAY_TOTALS) as $k) {
            $row[$k] = pay_num($get($k));
        }
        $rows[] = pay_fill_totals($row);
    }
    if ($map === null) {
        $errors[] = 'ไม่พบแถวหัวตาราง — ต้องมีคอลัมน์ "ชื่อ สกุล" "เดือน" "ปี" "เลขที่"';
    }
    return [$rows, $errors, isset($map['approved'])];
}

$step = 'upload';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('action') === 'preview') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $errors[] = 'กรุณาเลือกไฟล์';
        } else {
            [$rows, $errors, $hasApproval] = pay_parse_file($f['tmp_name'], $f['name']);
            if ($rows) {
                $_SESSION[$sessKey] = ['file' => $f['name'], 'rows' => $rows, 'errors' => $errors, 'has_approval' => $hasApproval];
                $step = 'preview';
                $errors = [];
            }
        }
    } elseif (post('action') === 'import' && !empty($_SESSION[$sessKey])) {
        $data = $_SESSION[$sessKey];
        $update = post('on_duplicate') === 'update';
        $useFileApproval = !empty($_POST['use_file_approval']) && pay_can('approver') && $data['has_approval'];
        $stats = ['insert' => 0, 'update' => 0, 'skip' => 0, 'employees' => 0];
        $amount = array_keys(PAY_INCOME + PAY_DEDUCT + PAY_TOTALS);
        try {
            $pdo->beginTransaction();
            $emps = $pdo->query('SELECT code, id FROM pay_employees')->fetchAll(PDO::FETCH_KEY_PAIR);
            $insEmp = $pdo->prepare('INSERT INTO pay_employees (code, full_name) VALUES (?, ?)');
            $find = $pdo->prepare('SELECT id FROM pay_slips WHERE employee_id = ? AND year = ? AND month = ?');
            $cols = ['employee_id', 'year', 'month', 'full_name', ...$amount, 'status', 'approved_by', 'approved_at'];
            $ins = $pdo->prepare('INSERT INTO pay_slips (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_fill(0, count($cols) + 1, '?')) . ')');
            $upd = $pdo->prepare('UPDATE pay_slips SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?');
            foreach ($data['rows'] as $r) {
                if (!isset($emps[$r['code']])) {
                    $insEmp->execute([$r['code'], $r['full_name']]);
                    $emps[$r['code']] = (int)$pdo->lastInsertId();
                    $stats['employees']++;
                }
                $approved = $useFileApproval && $r['approved'];
                $vals = [$emps[$r['code']], $r['year'], $r['month'], $r['full_name']];
                foreach ($amount as $k) {
                    $vals[] = $r[$k];
                }
                array_push($vals, $approved ? 'approved' : 'pending', $approved ? $me['id'] : null, $approved ? date('Y-m-d H:i:s') : null);
                $find->execute([$emps[$r['code']], $r['year'], $r['month']]);
                if ($sid = $find->fetchColumn()) {
                    if ($update) {
                        $upd->execute([...$vals, $sid]);
                        $stats['update']++;
                    } else {
                        $stats['skip']++;
                    }
                } else {
                    $ins->execute([...$vals, $me['id']]);
                    $stats['insert']++;
                }
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('นำเข้าไม่สำเร็จ: ' . $ex->getMessage(), 'danger');
            redirect('import.php');
        }
        $linked = pay_auto_link();
        unset($_SESSION[$sessKey]);
        audit_log('payslip_import', json_encode($stats + ['file_approval' => $useFileApproval]), PAY_MODULE);
        flash("นำเข้าเรียบร้อย: เพิ่ม {$stats['insert']} · ปรับปรุง {$stats['update']} · ข้าม {$stats['skip']} รายการ"
            . ($stats['employees'] ? " · พนักงานใหม่ {$stats['employees']} คน" : '') . ($linked ? " · ผูกบัญชีผู้ใช้อัตโนมัติ $linked คน" : '')
            . ($useFileApproval ? '' : ' — สลิปใหม่/ที่ปรับปรุงอยู่ในสถานะ "รออนุมัติ"'));
        redirect('slips.php' . ($useFileApproval ? '' : '?status=pending'));
    }
}

if ($step === 'preview') {
    $d = $_SESSION[$sessKey];
    $emps = $pdo->query('SELECT code, id FROM pay_employees')->fetchAll(PDO::FETCH_KEY_PAIR);
    $existing = [];
    foreach ($pdo->query('SELECT e.code, s.year, s.month FROM pay_slips s JOIN pay_employees e ON e.id = s.employee_id') as $x) {
        $existing[$x['code'] . '|' . $x['year'] . '|' . $x['month']] = true;
    }
    $periods = [];
    $newCodes = [];
    $dups = 0;
    $approvedInFile = 0;
    foreach ($d['rows'] as $r) {
        $k = $r['year'] * 100 + $r['month'];
        $periods[$k] = ($periods[$k] ?? 0) + 1;
        if (!isset($emps[$r['code']])) {
            $newCodes[$r['code']] = $r['full_name'];
        }
        $dups += isset($existing[$r['code'] . '|' . $r['year'] . '|' . $r['month']]) ? 1 : 0;
        $approvedInFile += $r['approved'] ? 1 : 0;
    }
    krsort($periods);
}

$active = 'import';
$pageTitle = 'นำเข้าข้อมูลสลิปเงินเดือน';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', array_slice($errors, 0, 20))) ?></div><?php endif; ?>

<?php if ($step === 'upload'): ?>
<div class="row g-3">
  <div class="col-lg-6">
    <form method="post" enctype="multipart/form-data" class="card">
      <div class="card-header"><strong><i class="bi bi-upload"></i> เลือกไฟล์</strong></div>
      <div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="preview">
        <input type="file" name="file" accept=".xlsx,.csv" class="form-control mb-3" required>
        <button class="btn btn-primary"><i class="bi bi-eye"></i> ตรวจสอบข้อมูลก่อนนำเข้า</button>
      </div>
    </form>
  </div>
  <div class="col-lg-6"><div class="card"><div class="card-body small">
    <strong>รูปแบบไฟล์</strong> — ใช้ไฟล์ Google Sheet เดิมได้ทันที: <em>ไฟล์ → ดาวน์โหลด → Microsoft Excel (.xlsx)</em>
    (ระบบอ่านชีต "Payroll" ให้อัตโนมัติ) หรือบันทึกเป็น CSV
    <ul class="mt-2 mb-0">
      <li>คอลัมน์ที่ต้องมี: <strong>ชื่อ สกุล · เดือน · ปี (พ.ศ.) · เลขที่</strong> (รหัสพนักงาน เช่น 001)</li>
      <li>คอลัมน์เงิน: อัตราเงินเดือน, ตกเบิก, ค่าครองชีพชั่วคราว, รวมรายได้, ภาษี, เงินประกันสังคม, เงินกู้สวัสดิการ, เงินกู้ ธอส.,
        เงินกู้ กยศ., เงินกู้ธนาคารอิสลาม, รวมรายจ่าย, เงินเดือนคงเหลือ — ช่องว่างหรือ "-" = 0, ยอดรวมว่างจะคำนวณให้</li>
      <li>เลขที่ใหม่จะเพิ่มเป็นพนักงานใหม่ และผูกกับบัญชีผู้ใช้ที่ชื่อ-นามสกุลตรงกันให้อัตโนมัติ</li>
      <li>สลิปที่นำเข้าจะอยู่ในสถานะ <strong>รออนุมัติ</strong> — พนักงานจะเห็นหลังผู้อนุมัติกดอนุมัติ</li>
      <li>ระบบไม่นำเข้ารหัสผ่านจากชีต UserData — พนักงานใช้บัญชีเดียวกับระบบบริหารจัดการ</li>
    </ul>
  </div></div></div>
</div>
<?php else: ?>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="import">
  <div class="row g-3 mb-3">
    <div class="col-lg-6"><div class="card h-100">
      <div class="card-header"><strong>สรุปไฟล์</strong> <span class="small text-muted"><?= h($d['file']) ?></span></div>
      <div class="card-body">
        <p class="mb-2">พบสลิป <strong><?= count($d['rows']) ?></strong> รายการ ใน <?= count($periods) ?> เดือน
          <?= $d['errors'] ? '· <span class="text-danger">ข้าม ' . count($d['errors']) . ' แถวที่ข้อมูลไม่ครบ</span>' : '' ?></p>
        <div class="small mb-2" style="max-height:160px;overflow:auto">
          <?php foreach ($periods as $k => $n): ?><span class="badge text-bg-light border me-1 mb-1"><?= h(pay_period(intdiv($k, 100), $k % 100)) ?> · <?= $n ?></span><?php endforeach; ?>
        </div>
        <?php if ($newCodes): ?><p class="small mb-0">พนักงานใหม่ <?= count($newCodes) ?> คน: <?= h(implode(', ', array_map(fn($c, $n) => "$c $n", array_keys($newCodes), $newCodes))) ?></p><?php endif; ?>
        <?php if ($d['errors']): ?><details class="small mt-2"><summary class="text-danger">แถวที่ข้าม</summary><?= implode('<br>', array_map('h', array_slice($d['errors'], 0, 50))) ?></details><?php endif; ?>
      </div>
    </div></div>
    <div class="col-lg-6"><div class="card h-100">
      <div class="card-header"><strong>ตัวเลือก</strong></div>
      <div class="card-body">
        <p class="mb-1">สลิปที่มีอยู่แล้ว (เลขที่+เดือน+ปี เดียวกัน): <strong><?= $dups ?></strong> รายการ</p>
        <div class="form-check"><input class="form-check-input" type="radio" name="on_duplicate" value="update" id="dupU" checked><label class="form-check-label" for="dupU">ปรับปรุงตามไฟล์ (กลับเป็นรออนุมัติ)</label></div>
        <div class="form-check mb-3"><input class="form-check-input" type="radio" name="on_duplicate" value="skip" id="dupS"><label class="form-check-label" for="dupS">ข้าม (คงข้อมูลเดิม)</label></div>
        <?php if ($d['has_approval']): ?>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="use_file_approval" value="1" id="useAp" <?= pay_can('approver') ? '' : 'disabled' ?>>
            <label class="form-check-label" for="useAp">ใช้สถานะจากคอลัมน์ "อนุมัติ" ในไฟล์ (1 = อนุมัติแล้ว, <?= $approvedInFile ?> รายการ)
              <span class="d-block small text-muted">สำหรับย้ายข้อมูลเดิมที่อนุมัติไปแล้ว<?= pay_can('approver') ? '' : ' — เฉพาะผู้อนุมัติ' ?></span></label></div>
        <?php endif; ?>
      </div>
    </div></div>
  </div>
  <div class="card">
    <div class="card-header"><strong>ตัวอย่างข้อมูล</strong> <span class="small text-muted">(50 แถวแรก)</span></div>
    <div class="table-responsive"><table class="table table-sm mb-0 pay-table">
      <thead><tr><th>เลขที่</th><th>ชื่อ</th><th>เดือน</th><th class="num">เงินเดือน</th><th class="num">รวมรายได้</th><th class="num">รวมรายจ่าย</th><th class="num">คงเหลือ</th><th></th></tr></thead>
      <tbody>
      <?php foreach (array_slice($d['rows'], 0, 50) as $r): ?>
        <tr><td class="font-monospace"><?= h($r['code']) ?></td><td><?= h($r['full_name']) ?></td><td><?= h(pay_period($r['year'], $r['month'])) ?></td>
          <td class="num"><?= pay_money($r['salary']) ?></td><td class="num"><?= pay_money($r['total_income']) ?></td>
          <td class="num"><?= pay_money($r['total_deduct']) ?></td><td class="num fw-semibold"><?= pay_money($r['net_pay']) ?></td>
          <td><?= isset($existing[$r['code'] . '|' . $r['year'] . '|' . $r['month']]) ? '<span class="badge text-bg-info">มีแล้ว</span>' : '<span class="badge text-bg-success">ใหม่</span>' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="sticky-actions">
    <button class="btn btn-primary btn-lg"><i class="bi bi-check2-circle"></i> ยืนยันนำเข้า <?= count($d['rows']) ?> รายการ</button>
    <a href="import.php" class="btn btn-light btn-lg">เลือกไฟล์ใหม่</a>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
