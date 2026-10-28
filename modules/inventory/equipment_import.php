<?php
/**
 * นำเข้า/ส่งออกครุภัณฑ์ด้วยไฟล์ CSV ตามรูปแบบ "รายงานครุภัณฑ์คอมพิวเตอร์ และอุปกรณ์อิเล็กทรอนิกส์"
 *   หัวตาราง: ที่, เลขครุภัณฑ์, ประเภทครุภัณฑ์, ยี่ห้อ, รุ่น (Model), ปีงบ, อายุ, หมายเหตุ
 *   (มีแถวหัวเรื่อง/หัวกลุ่ม เช่น "Notebook (จำนวน 9 เครื่อง)" แทรกได้ ระบบจะข้ามให้)
 * ขั้นตอน: อัปโหลด → ตรวจสอบตัวอย่าง + เลือกสภาพตามหมายเหตุ → ยืนยันนำเข้า
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(INV_MODULE, 'admin');
$pdo = db();

// ---------- ส่งออก CSV (รูปแบบเดียวกับไฟล์นำเข้า แยกกลุ่มตามประเภท) ----------
if (isset($_GET['export'])) {
    $rows = $pdo->query('SELECT e.*, t.name AS type_name FROM inv_equipment e LEFT JOIN inv_equipment_types t ON t.id = e.type_id
        ORDER BY t.sort_order IS NULL, t.sort_order, e.fiscal_year DESC, e.asset_no IS NULL, e.asset_no, e.id')->fetchAll();
    $loans = active_loans_by_equipment();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="equipment_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM ให้ Excel อ่านภาษาไทยถูก
    fputcsv($out, ['รายงานครุภัณฑ์ พ.ศ ' . ((int)date('Y') + 543)]);
    fputcsv($out, [ORG_NAME]);
    $groups = [];
    foreach ($rows as $r) {
        $groups[$r['type_name'] ?? 'ไม่ระบุประเภท'][] = $r;
    }
    foreach ($groups as $type => $items) {
        fputcsv($out, [$type . ' (จำนวน ' . count($items) . ' เครื่อง)']);
        fputcsv($out, ['ที่', 'เลขครุภัณฑ์', 'ประเภทครุภัณฑ์', 'ยี่ห้อ', 'รุ่น (Model)', 'ปีงบ', 'อายุ', 'หมายเหตุ', 'หมายเลขเครื่อง', 'สภาพการใช้งาน', 'ผู้ยืม']);
        foreach ($items as $i => $r) {
            $l = $loans[(int)$r['id']] ?? null;
            fputcsv($out, [$i + 1, $r['asset_no'], $r['type_name'], $r['brand'], $r['model'], $r['fiscal_year'],
                equipment_age($r['fiscal_year'] ? (int)$r['fiscal_year'] : null), $r['note'], $r['serial_number'],
                EQ_STATUS[$r['status']][0], $l && $l['status'] === 'borrowed' ? $l['borrower_name'] : '']);
        }
    }
    fclose($out);
    exit;
}

/** เดาสภาพการใช้งานจากข้อความหมายเหตุ */
function guess_status(string $note): string
{
    if (preg_match('/จำหน่าย/u', $note)) {
        return 'dispose';
    }
    if (preg_match('/ซ่อม|เสีย|ชำรุด|ใช้ไม่ได้|ใช้งานไม่ได้/u', $note)) {
        return 'repair';
    }
    return 'normal';
}

/** อ่านไฟล์ CSV → รายการครุภัณฑ์ */
function parse_equipment_csv(string $path): array
{
    $raw = file_get_contents($path);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {                 // Excel ภาษาไทยบันทึก CSV เป็น Windows-874
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-874');
    }
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $map = null;
    $items = [];
    $aliases = [
        'asset_no' => ['เลขครุภัณฑ์', 'หมายเลขครุภัณฑ์', 'หมายเลขพัสดุ'], 'type' => ['ประเภทครุภัณฑ์', 'ประเภท'],
        'brand' => ['ยี่ห้อ'], 'model' => ['รุ่น'], 'fiscal_year' => ['ปีงบ'], 'note' => ['หมายเหตุ'],
        'serial' => ['หมายเลขเครื่อง', 'serial'], 'status' => ['สภาพ', 'สถานะ'],
    ];
    $line = 0;
    while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $line++;
        $row = array_map(fn($v) => trim(preg_replace('/\s+/u', ' ', (string)$v)), $row);
        $hdr = array_map(fn($v) => mb_strtolower($v), $row);
        if (in_array('เลขครุภัณฑ์', $row, true) || (in_array('ที่', $row, true) && preg_grep('/^ยี่ห้อ/u', $row))) {
            $map = [];
            foreach ($aliases as $key => $names) {
                foreach ($hdr as $i => $h) {
                    foreach ($names as $n) {
                        if ($h !== '' && str_starts_with($h, mb_strtolower($n)) && !isset($map[$key])) {
                            $map[$key] = $i;
                        }
                    }
                }
            }
            continue;
        }
        if ($map === null) {
            continue;
        }
        $get = fn($k) => isset($map[$k]) ? ($row[$map[$k]] ?? '') : '';
        if ($get('brand') === '' && $get('model') === '') {
            continue;                                         // แถวหัวกลุ่ม / แถวว่าง
        }
        $fy = preg_replace('/\D/', '', $get('fiscal_year'));
        $items[] = [
            'line' => $line, 'asset_no' => $get('asset_no'), 'type' => $get('type'), 'brand' => $get('brand'),
            'model' => rtrim($get('model'), '"'), 'serial' => $get('serial'),
            'fiscal_year' => ($fy !== '' && $fy >= 2400 && $fy <= 2700) ? (int)$fy : null,
            'note' => $get('note'), 'status_text' => $get('status'),
        ];
    }
    fclose($fh);
    return $items;
}

$step = 'upload';
$errors = [];
$sessKey = 'inv_equipment_import';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'preview') {
        $f = $_FILES['csv'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $errors[] = 'กรุณาเลือกไฟล์ CSV';
        } else {
            $items = parse_equipment_csv($f['tmp_name']);
            if (!$items) {
                $errors[] = 'ไม่พบรายการครุภัณฑ์ในไฟล์ — ต้องมีแถวหัวตารางที่มีคำว่า "เลขครุภัณฑ์" "ประเภทครุภัณฑ์" "ยี่ห้อ" "รุ่น"';
            } else {
                $_SESSION[$sessKey] = ['file' => $f['name'], 'items' => $items];
                $step = 'preview';
            }
        }
    } elseif ($action === 'import' && !empty($_SESSION[$sessKey])) {
        $items = $_SESSION[$sessKey]['items'];
        $noteMap = (array)($_POST['note_status'] ?? []);
        $updateExisting = !empty($_POST['update_existing']);
        $skipSameNoNumber = !empty($_POST['skip_same_nonumber']);
        $stats = ['insert' => 0, 'update' => 0, 'skip' => 0];
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT id FROM inv_equipment WHERE asset_no = ?');
            $ins = $pdo->prepare('INSERT INTO inv_equipment (asset_no, type_id, brand, model, serial_number, fiscal_year, status, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $upd = $pdo->prepare('UPDATE inv_equipment SET type_id = ?, brand = ?, model = ?, serial_number = COALESCE(?, serial_number),
                fiscal_year = ?, status = ?, note = ? WHERE id = ?');
            $cntNoNum = $pdo->prepare('SELECT COUNT(*) FROM inv_equipment WHERE asset_no IS NULL AND type_id <=> ? AND brand <=> ? AND model <=> ? AND fiscal_year <=> ?');
            $seen = [];
            $noNumQuota = [];
            foreach ($items as $it) {
                $typeId = equipment_type_id($pdo, $it['type']);
                $status = $noteMap[md5($it['note'])] ?? guess_status($it['note']);
                $status = isset(EQ_STATUS[$status]) ? $status : 'normal';
                $vals = [$typeId, $it['brand'] ?: null, $it['model'] ?: null, $it['serial'] ?: null, $it['fiscal_year'], $status, $it['note'] ?: null];
                if ($it['asset_no'] !== '') {
                    if (isset($seen[$it['asset_no']])) {         // เลขซ้ำในไฟล์เดียวกัน
                        $stats['skip']++;
                        continue;
                    }
                    $seen[$it['asset_no']] = true;
                    $find->execute([$it['asset_no']]);
                    if ($eid = $find->fetchColumn()) {
                        if ($updateExisting) {
                            $upd->execute([...$vals, $eid]);
                            $stats['update']++;
                        } else {
                            $stats['skip']++;
                        }
                        continue;
                    }
                } elseif ($skipSameNoNumber) {
                    // ไม่มีเลข: นำเข้าเฉพาะจำนวนที่เกินกว่าที่มีอยู่แล้ว (กันซ้ำเมื่อนำเข้าไฟล์เดิมอีกครั้ง)
                    $k = implode('|', [$typeId, $it['brand'], $it['model'], $it['fiscal_year']]);
                    if (!isset($noNumQuota[$k])) {
                        $cntNoNum->execute([$typeId, $it['brand'] ?: null, $it['model'] ?: null, $it['fiscal_year']]);
                        $noNumQuota[$k] = (int)$cntNoNum->fetchColumn();
                    }
                    if ($noNumQuota[$k] > 0) {
                        $noNumQuota[$k]--;
                        $stats['skip']++;
                        continue;
                    }
                }
                $ins->execute([$it['asset_no'] ?: null, ...$vals, $me['id']]);
                $stats['insert']++;
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('นำเข้าไม่สำเร็จ: ' . $ex->getMessage(), 'danger');
            redirect('equipment_import.php');
        }
        unset($_SESSION[$sessKey]);
        audit_log('equipment_import', json_encode($stats), INV_MODULE);
        flash("นำเข้าเรียบร้อย: เพิ่มใหม่ {$stats['insert']} รายการ · ปรับปรุง {$stats['update']} รายการ · ข้าม {$stats['skip']} รายการ");
        redirect('equipment.php');
    }
}

if ($step === 'preview') {
    $items = $_SESSION[$sessKey]['items'];
    $existing = $pdo->query('SELECT asset_no FROM inv_equipment WHERE asset_no IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    $existing = array_flip($existing);
    $notes = [];
    $byType = [];
    $dupCount = 0;
    $noNumber = 0;
    foreach ($items as $it) {
        $notes[$it['note']] = ($notes[$it['note']] ?? 0) + 1;
        $byType[$it['type'] ?: '(ไม่ระบุ)'] = ($byType[$it['type'] ?: '(ไม่ระบุ)'] ?? 0) + 1;
        if ($it['asset_no'] !== '' && isset($existing[$it['asset_no']])) {
            $dupCount++;
        }
        if ($it['asset_no'] === '') {
            $noNumber++;
        }
    }
}

$pageActions = '<a href="equipment.php" class="btn btn-light"><i class="bi bi-arrow-left"></i> รายการครุภัณฑ์</a>'
    . '<a href="equipment_import.php?export=1" class="btn btn-outline-success"><i class="bi bi-download"></i> ส่งออก CSV</a>';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<?php if ($step === 'upload'): ?>
<div class="row g-3">
  <div class="col-lg-6">
    <form method="post" enctype="multipart/form-data" class="card">
      <div class="card-header"><strong><i class="bi bi-upload"></i> 1. เลือกไฟล์ CSV</strong></div>
      <div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="preview">
        <input type="file" name="csv" accept=".csv,text/csv" class="form-control mb-3" required>
        <button class="btn btn-primary"><i class="bi bi-eye"></i> ตรวจสอบข้อมูลก่อนนำเข้า</button>
      </div>
    </form>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-body small">
      <strong>รูปแบบไฟล์</strong> — ใช้ไฟล์ "รายงานครุภัณฑ์คอมพิวเตอร์ และอุปกรณ์อิเล็กทรอนิกส์" ที่บันทึกจาก Excel เป็น CSV ได้ทันที
      <table class="table table-sm table-bordered my-2 text-nowrap"><tr class="table-light"><td>ที่</td><td>เลขครุภัณฑ์</td><td>ประเภทครุภัณฑ์</td><td>ยี่ห้อ</td><td>รุ่น (Model)</td><td>ปีงบ</td><td>อายุ</td><td>หมายเหตุ</td></tr>
        <tr><td>1</td><td>1105-0954-0293/68</td><td>โน้ตบุ๊ค (Notebook)</td><td>HP</td><td>ProBook 450</td><td>2568</td><td>1</td><td>ใช้งานได้</td></tr></table>
      <ul class="mb-0">
        <li>แถวหัวเรื่องและหัวกลุ่ม (เช่น "Notebook (จำนวน 9 เครื่อง)") ข้ามให้อัตโนมัติ มีหัวตารางหลายชุดในไฟล์เดียวได้</li>
        <li>เลขครุภัณฑ์เว้นว่างได้ (รับเข้าใหม่) · อายุคำนวณจากปีงบให้อัตโนมัติ</li>
        <li>เพิ่มคอลัมน์ "หมายเลขเครื่อง" ได้ (ไม่บังคับ)</li>
        <li>สภาพการใช้งาน (ใช้งานตามปกติ/เสียรอซ่อม/รอจำหน่าย) เลือกตามข้อความหมายเหตุได้ในขั้นถัดไป</li>
      </ul>
    </div></div>
  </div>
</div>

<?php else: ?>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="import">
  <div class="row g-3 mb-3">
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header"><strong>2. สรุปไฟล์</strong> <span class="small text-muted"><?= h($_SESSION[$sessKey]['file']) ?></span></div>
        <div class="card-body">
          <p class="mb-2">พบ <strong><?= count($items) ?></strong> รายการ</p>
          <ul class="small mb-2"><?php foreach ($byType as $t => $n): ?><li><?= h($t) ?>: <?= $n ?></li><?php endforeach; ?></ul>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="update_existing" value="1" id="updEx" <?= $dupCount ? 'checked' : '' ?>>
            <label class="form-check-label" for="updEx">เลขครุภัณฑ์ที่มีในระบบแล้ว (<?= $dupCount ?> รายการ) → ปรับปรุงข้อมูลตามไฟล์ <span class="text-muted">(ไม่เลือก = ข้าม)</span></label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="skip_same_nonumber" value="1" id="skipNo" checked>
            <label class="form-check-label" for="skipNo">รายการไม่มีเลขครุภัณฑ์ (<?= $noNumber ?> รายการ): ไม่เพิ่มซ้ำถ้ามีรุ่นเดียวกันที่ไม่มีเลขอยู่แล้ว <span class="text-muted">(กันข้อมูลซ้ำเมื่อนำเข้าไฟล์เดิมอีกครั้ง)</span></label></div>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card h-100">
        <div class="card-header"><strong>3. กำหนดสภาพการใช้งานตามหมายเหตุ</strong></div>
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead><tr><th>หมายเหตุในไฟล์</th><th class="text-center">จำนวน</th><th>สภาพการใช้งาน</th></tr></thead>
            <tbody>
            <?php foreach ($notes as $note => $n): $g = guess_status($note); ?>
              <tr><td><?= $note !== '' ? h($note) : '<span class="text-muted">(ว่าง)</span>' ?></td><td class="text-center"><?= $n ?></td>
                <td><select name="note_status[<?= md5($note) ?>]" class="form-select form-select-sm">
                  <?php foreach (EQ_STATUS as $k => $s): ?><option value="<?= $k ?>" <?= $g === $k ? 'selected' : '' ?>><?= h($s[0]) ?></option><?php endforeach; ?>
                </select></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><strong>ตัวอย่างข้อมูล</strong></div>
    <div class="table-responsive" style="max-height:480px">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="sticky-top"><tr><th>แถว</th><th>เลขครุภัณฑ์</th><th>ประเภท</th><th>ยี่ห้อ</th><th>รุ่น</th><th>ปีงบ</th><th>หมายเหตุ</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td class="text-muted small"><?= (int)$it['line'] ?></td>
            <td class="font-monospace small"><?= $it['asset_no'] !== '' ? h($it['asset_no']) : '<span class="text-muted fst-italic">(ไม่มีเลข)</span>' ?></td>
            <td class="small"><?= h($it['type']) ?></td><td><?= h($it['brand']) ?></td><td><?= h($it['model']) ?></td>
            <td><?= h($it['fiscal_year']) ?></td><td class="small"><?= h($it['note']) ?></td>
            <td><?= $it['asset_no'] !== '' && isset($existing[$it['asset_no']]) ? '<span class="badge text-bg-info">มีในระบบแล้ว</span>' : '<span class="badge text-bg-success">ใหม่</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="sticky-actions">
    <button class="btn btn-primary btn-lg"><i class="bi bi-check2-circle"></i> ยืนยันนำเข้า <?= count($items) ?> รายการ</button>
    <a href="equipment_import.php" class="btn btn-light btn-lg">เลือกไฟล์ใหม่</a>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
