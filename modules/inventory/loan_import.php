<?php
/**
 * นำเข้ารายการยืมครุภัณฑ์จากไฟล์ CSV / Excel (ผู้ดูแล)
 *   คอลัมน์: เลขครุภัณฑ์ | ผู้ยืม | วันที่ยืม | กำหนดคืน | เลขที่หนังสือ | วัตถุประสงค์
 *   - 1 แถว = ครุภัณฑ์ 1 รายการ (หรือหลายเลขในช่องเดียว คั่นด้วย ,)
 *   - แถวที่ผู้ยืม + วันที่ยืม + กำหนดคืน + เลขที่หนังสือ เหมือนกัน รวมเป็นรายการยืมเดียวกัน
 * หลังนำเข้า → หน้าแนบไฟล์ใบยืมของรายการที่นำเข้าทันที (loan_attach.php)
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(INV_MODULE, 'admin');
$pdo = db();
$sessKey = 'inv_loan_import';
const LOAN_IMPORT_HEADERS = ['เลขครุภัณฑ์', 'ผู้ยืม', 'วันที่ยืม', 'กำหนดคืน', 'เลขที่หนังสือ', 'วัตถุประสงค์'];

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="loan_import_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, LOAN_IMPORT_HEADERS);
    $be = fn($d) => date('j/n/', strtotime($d)) . ((int)date('Y', strtotime($d)) + 543);
    $sample = $pdo->query("SELECT asset_no FROM inv_equipment WHERE status = 'normal' AND asset_no IS NOT NULL ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
    $sample += ['1105-0954-0293/68', '1105-0970-0625/68', '1105-0970-0624/68'];
    // แถว 2–3 ผู้ยืม/วันที่/เลขที่หนังสือเดียวกัน → รวมเป็นรายการยืมเดียว (2 ชิ้น)
    fputcsv($out, [$sample[0], 'ชื่อ-นามสกุล หรือชื่อผู้ใช้ของผู้ยืม', $be('today'), $be('+30 days'), 'ขก 0035/1234', 'ปฏิบัติงานตรวจสอบโครงการ']);
    fputcsv($out, [$sample[1], 'ชื่อ-นามสกุล หรือชื่อผู้ใช้ของผู้ยืม', $be('today'), $be('+7 days'), 'ขก 0035/1235', 'ลงพื้นที่สำรวจ']);
    fputcsv($out, [$sample[2], 'ชื่อ-นามสกุล หรือชื่อผู้ใช้ของผู้ยืม', $be('today'), $be('+7 days'), 'ขก 0035/1235', 'ลงพื้นที่สำรวจ']);
    fclose($out);
    exit;
}

function loan_header_field(string $h): ?string
{
    $h = preg_replace('/\s+/u', '', mb_strtolower($h));
    $map = ['asset_no' => ['เลขครุภัณฑ์', 'หมายเลขครุภัณฑ์', 'หมายเลขพัสดุ', 'เลขพัสดุ'], 'borrower' => ['ผู้ยืม', 'ชื่อผู้ยืม'],
            'start_date' => ['วันที่ยืม', 'วันที่', 'ตั้งแต่วันที่'], 'due_date' => ['กำหนดคืน', 'วันที่คืน', 'ถึงวันที่', 'กำหนดส่งคืน'],
            'doc_no' => ['เลขที่หนังสือ', 'เลขที่หนังสือe-office', 'เลขที่', 'เลขหนังสือ'], 'purpose' => ['วัตถุประสงค์', 'หมายเหตุ', 'เพื่อ']];
    foreach ($map as $f => $names) {
        if (in_array($h, $names, true)) {
            return $f;
        }
    }
    return null;
}

/** ผู้ยืมจากชื่อ-นามสกุล (ไม่ใส่คำนำหน้าได้) หรือชื่อผู้ใช้ → [user row|null, error] */
function loan_find_user(string $v): array
{
    static $byName = null, $byUser = null;
    if ($byName === null) {
        $byName = $byUser = [];
        foreach (db()->query('SELECT id, username, full_name, position, phone FROM core_users WHERE is_active = 1') as $u) {
            $n = preg_replace('/\s+/u', '', $u['full_name']);
            $byName[$n][] = $u;
            $byName[preg_replace('/^(นางสาว|นาง|นาย|ว่าที่ร้อยตรี|ดร\.)/u', '', $n)][] = $u;
            if ($u['username']) {
                $byUser[mb_strtolower($u['username'])] = $u;
            }
        }
    }
    $k = preg_replace('/\s+/u', '', $v);
    if (isset($byUser[mb_strtolower(trim($v))])) {
        return [$byUser[mb_strtolower(trim($v))], null];
    }
    $list = $byName[$k] ?? [];
    $uniq = array_unique(array_column($list, 'id'));
    if (count($uniq) === 1) {
        return [$list[0], null];
    }
    return [null, $list ? 'ชื่อผู้ยืม "' . $v . '" มีหลายบัญชี — ใช้ชื่อผู้ใช้แทน' : 'ไม่พบผู้ยืม "' . $v . '" ในรายชื่อผู้ใช้'];
}

/** อ่านไฟล์ → [loans (จัดกลุ่มแล้ว), errors] */
function loan_parse_file(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $table = [];
    if ($ext === 'xlsx') {
        $x = \Shuchkin\SimpleXLSX::parse($path);
        if (!$x) {
            return [[], ['อ่านไฟล์ Excel ไม่ได้: ' . \Shuchkin\SimpleXLSX::parseError()]];
        }
        $table = $x->rows(0);
    } elseif (in_array($ext, ['csv', 'txt'], true)) {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, text_to_utf8(file_get_contents($path)));
        rewind($fh);
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $table[] = $r;
        }
    } else {
        return [[], ['รองรับไฟล์ .csv หรือ .xlsx เท่านั้น']];
    }

    $pdo = db();
    $eqStmt = $pdo->prepare('SELECT e.*, t.name AS type_name FROM inv_equipment e LEFT JOIN inv_equipment_types t ON t.id = e.type_id
        WHERE REPLACE(e.asset_no, \' \', \'\') = ?');
    $map = null;
    $loans = [];
    $errors = [];
    $seenEq = [];
    foreach ($table as $i => $r) {
        $line = $i + 1;
        if ($map === null) {
            $mm = [];
            foreach ($r as $c => $hd) {
                $f = loan_header_field((string)$hd);
                if ($f && !isset($mm[$f])) {
                    $mm[$f] = $c;
                }
            }
            if (isset($mm['asset_no'], $mm['borrower'], $mm['start_date'])) {
                $map = $mm;
            }
            continue;
        }
        $get = fn($f) => isset($map[$f]) ? trim((string)($r[$map[$f]] ?? '')) : '';
        if ($get('asset_no') === '' && $get('borrower') === '') {
            continue;
        }
        $rowErr = [];
        [$user, $uErr] = loan_find_user($get('borrower'));
        if ($uErr) {
            $rowErr[] = $uErr;
        }
        $start = parse_date_flexible($get('start_date'));
        $due = $get('due_date') !== '' ? parse_date_flexible($get('due_date')) : null;
        if (!$start) {
            $rowErr[] = 'วันที่ยืมไม่ถูกต้อง';
        }
        if (!$due) {
            $rowErr[] = 'กำหนดคืนไม่ถูกต้องหรือว่าง';
        } elseif ($start && $due < $start) {
            $rowErr[] = 'กำหนดคืนก่อนวันที่ยืม';
        }
        $equip = [];
        foreach (preg_split('/[,;\n]+/u', $get('asset_no')) as $no) {
            $no = preg_replace('/\s+/u', '', $no);
            if ($no === '') {
                continue;
            }
            $eqStmt->execute([$no]);
            $e = $eqStmt->fetch();
            if (!$e) {
                $rowErr[] = "ไม่พบเลขครุภัณฑ์ $no";
                continue;
            }
            if (isset($seenEq[$e['id']])) {
                $rowErr[] = "$no ซ้ำในไฟล์ (แถว {$seenEq[$e['id']]})";
                continue;
            }
            $seenEq[$e['id']] = $line;
            if ($e['status'] !== 'normal') {
                $rowErr[] = "$no สภาพ \"" . EQ_STATUS[$e['status']][0] . '" ให้ยืมไม่ได้';
            }
            $equip[] = $e;
        }
        if (!$equip && !$rowErr) {
            $rowErr[] = 'ไม่มีเลขครุภัณฑ์';
        }
        $busy = active_loans_by_equipment(array_column($equip, 'id'));
        foreach ($busy as $b) {
            $rowErr[] = 'ครุภัณฑ์ถูกยืมอยู่แล้ว (' . $b['borrower_name'] . ' รายการ ' . $b['loan_no'] . ')';
        }
        $key = $rowErr ? 'err' . $line : implode('|', [$user['id'], $start, $due, $get('doc_no')]);
        $loans[$key] ??= ['lines' => [], 'user' => $user, 'borrower_text' => $get('borrower'), 'start_date' => $start, 'due_date' => $due,
                          'doc_no' => mb_substr($get('doc_no'), 0, 100), 'purpose' => mb_substr($get('purpose'), 0, 500),
                          'equipment' => [], 'errors' => [], 'asset_text' => ''];
        $loans[$key]['lines'][] = $line;
        $loans[$key]['equipment'] = array_merge($loans[$key]['equipment'], $equip);
        $loans[$key]['errors'] = array_merge($loans[$key]['errors'], $rowErr);
        $loans[$key]['asset_text'] = trim($loans[$key]['asset_text'] . ', ' . $get('asset_no'), ', ');
        if ($loans[$key]['purpose'] === '' && $get('purpose') !== '') {
            $loans[$key]['purpose'] = mb_substr($get('purpose'), 0, 500);
        }
    }
    if ($map === null) {
        $errors[] = 'ไม่พบแถวหัวตาราง — ต้องมีคอลัมน์ "เลขครุภัณฑ์" "ผู้ยืม" "วันที่ยืม" (ดาวน์โหลดไฟล์ตัวอย่าง)';
    }
    return [array_values($loans), $errors];
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
            [$loans, $errors] = loan_parse_file($f['tmp_name'], $f['name']);
            if ($loans) {
                $_SESSION[$sessKey] = ['file' => $f['name'], 'loans' => $loans];
                $step = 'preview';
            }
        }
    } elseif (post('action') === 'import' && !empty($_SESSION[$sessKey])) {
        $created = [];
        try {
            $pdo->beginTransaction();
            $insL = $pdo->prepare('INSERT INTO inv_loans (loan_no, borrower_id, borrower_name, borrower_position, borrower_org, borrower_phone,
                doc_no, purpose, start_date, due_date, status, lender_id, lender_name, lender_position, lent_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'borrowed\', ?, ?, ?, ?, ?)');
            $insI = $pdo->prepare('INSERT INTO inv_loan_items (loan_id, equipment_id, item_name, brand_model, serial_number, asset_no, quantity, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
            foreach ($_SESSION[$sessKey]['loans'] as $l) {
                if ($l['errors']) {
                    continue;
                }
                // ตรวจซ้ำตอนบันทึก (กันกรณีมีคนบันทึกการยืมระหว่างดูตัวอย่าง)
                $ids = array_map(fn($e) => (int)$e['id'], $l['equipment']);
                if (active_loans_by_equipment($ids)) {
                    throw new RuntimeException('มีครุภัณฑ์ในไฟล์ถูกยืมไปแล้วระหว่างนี้ — กรุณาตรวจสอบไฟล์ใหม่');
                }
                $u = $l['user'];
                $insL->execute([generate_loan_no($pdo), $u['id'], $u['full_name'], $u['position'], ORG_NAME, $u['phone'],
                    $l['doc_no'] ?: null, $l['purpose'] ?: null, $l['start_date'], $l['due_date'],
                    $me['id'], $me['full_name'], $me['position'], $l['start_date'] . ' ' . date('H:i:s'), $me['id']]);
                $lid = (int)$pdo->lastInsertId();
                foreach ($l['equipment'] as $n => $e) {
                    $insI->execute([$lid, $e['id'], $e['type_name'] ?? 'ครุภัณฑ์', trim(($e['brand'] ?? '') . ' ' . ($e['model'] ?? '')) ?: null,
                                    $e['serial_number'], $e['asset_no'], $n + 1]);
                }
                $created[] = $lid;
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('นำเข้าไม่สำเร็จ: ' . $ex->getMessage(), 'danger');
            redirect('loan_import.php');
        }
        unset($_SESSION[$sessKey]);
        audit_log('loan_import', count($created) . ' รายการ', INV_MODULE);
        if (!$created) {
            flash('ไม่มีรายการที่นำเข้าได้', 'warning');
            redirect('loan_import.php');
        }
        flash('นำเข้ารายการยืม ' . count($created) . ' รายการ — สถานะครุภัณฑ์เป็น "กำลังยืม" แล้ว · แนบไฟล์ใบยืมด้านล่างได้เลย');
        redirect('loan_attach.php?ids=' . implode(',', $created));
    }
}

$pageActions = '<a href="?template=1" class="btn btn-outline-success"><i class="bi bi-download"></i> ดาวน์โหลดไฟล์ตัวอย่าง</a>'
    . '<a href="loans.php" class="btn btn-light"><i class="bi bi-arrow-left"></i> รายการยืม-คืน</a>';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<?php if ($step === 'upload'): ?>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" enctype="multipart/form-data" class="card">
      <div class="card-header"><strong><i class="bi bi-upload"></i> เลือกไฟล์</strong></div>
      <div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="preview">
        <input type="file" name="file" accept=".csv,.xlsx" class="form-control mb-3" required>
        <button class="btn btn-primary"><i class="bi bi-eye"></i> ตรวจสอบข้อมูลก่อนนำเข้า</button>
      </div>
    </form>
  </div>
  <div class="col-lg-7"><div class="card"><div class="card-body small">
    <strong>รูปแบบไฟล์</strong> (แถวแรกเป็นหัวตาราง)
    <div class="table-responsive"><table class="table table-sm table-bordered my-2 text-nowrap"><tr class="table-light"><?php foreach (LOAN_IMPORT_HEADERS as $hd): ?><td><?= h($hd) ?></td><?php endforeach; ?></tr>
      <tr><td>1105-0954-0293/68</td><td>นายณชพัฒน์ ไชยคำ</td><td>1/11/2569</td><td>31/12/2569</td><td>ขก 0035/1234</td><td>ตรวจสอบโครงการ</td></tr></table></div>
    <ul class="mb-0">
      <li>1 แถว = ครุภัณฑ์ 1 รายการ หรือใส่หลายเลขในช่องเดียวคั่นด้วย <code>,</code></li>
      <li>แถวที่ <strong>ผู้ยืม + วันที่ยืม + กำหนดคืน + เลขที่หนังสือ</strong> เหมือนกัน จะรวมเป็นรายการยืมเดียวกัน</li>
      <li><strong>ผู้ยืม</strong>: ชื่อ-นามสกุล (ไม่ใส่คำนำหน้าก็ได้) หรือชื่อผู้ใช้ · <strong>วันที่</strong>: 1/11/2569, 2026-11-01, 1 พ.ย. 2569</li>
      <li>ครุภัณฑ์ต้องมีในทะเบียน สภาพ "ใช้งานตามปกติ" และไม่ถูกยืมอยู่</li>
      <li>หลังนำเข้า ระบบพาไปหน้า <strong>แนบไฟล์ใบยืม</strong> ของรายการที่นำเข้าทันที</li>
    </ul>
  </div></div></div>
</div>
<?php else: $d = $_SESSION[$sessKey]; $ok = array_filter($d['loans'], fn($l) => !$l['errors']); ?>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="import">
  <div class="card mb-3"><div class="card-body">
    <strong><?= h($d['file']) ?></strong> · รายการยืม <?= count($d['loans']) ?> รายการ · พร้อมนำเข้า <strong class="text-success"><?= count($ok) ?></strong>
    (ครุภัณฑ์ <?= array_sum(array_map(fn($l) => count($l['equipment']), $ok)) ?> ชิ้น)
    <?= count($d['loans']) - count($ok) ? '· มีปัญหา <strong class="text-danger">' . (count($d['loans']) - count($ok)) . '</strong> (จะข้าม)' : '' ?>
  </div></div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>แถว</th><th>ผู้ยืม</th><th>ครุภัณฑ์</th><th>ระยะเวลายืม</th><th>เลขที่หนังสือ</th><th>สถานะ</th></tr></thead>
        <tbody>
        <?php foreach ($d['loans'] as $l): $bad = (bool)$l['errors']; ?>
          <tr class="<?= $bad ? 'table-danger' : '' ?>">
            <td class="small text-muted"><?= h(implode(', ', $l['lines'])) ?></td>
            <td><?= $l['user'] ? h($l['user']['full_name']) : h($l['borrower_text']) ?></td>
            <td class="small"><?php if ($l['equipment']): foreach ($l['equipment'] as $e): ?>
                <div><span class="font-monospace"><?= h($e['asset_no']) ?></span> <span class="text-muted"><?= h(trim($e['brand'] . ' ' . $e['model'])) ?></span></div>
              <?php endforeach; else: ?><span class="font-monospace"><?= h($l['asset_text']) ?></span><?php endif; ?></td>
            <td class="small text-nowrap"><?= $l['start_date'] ? h(thai_date($l['start_date'])) : '-' ?> – <?= $l['due_date'] ? h(thai_date($l['due_date'])) : '-' ?></td>
            <td class="small"><?= h($l['doc_no'] ?: '-') ?></td>
            <td class="small"><?= $bad ? '<span class="text-danger">' . h(implode(' · ', array_unique($l['errors']))) . '</span>' : '<span class="badge text-bg-success">นำเข้า</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="sticky-actions">
    <button class="btn btn-primary btn-lg" <?= $ok ? '' : 'disabled' ?>><i class="bi bi-check2-circle"></i> ยืนยันนำเข้า แล้วแนบไฟล์ใบยืม</button>
    <a href="loan_import.php" class="btn btn-light btn-lg">เลือกไฟล์ใหม่</a>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
