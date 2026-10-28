<?php
/**
 * บันทึกการยืมครุภัณฑ์ (ผู้ดูแลระบบวัสดุและครุภัณฑ์)
 * การขออนุมัติยืมทำในระบบ e-office ตามเดิม — หน้านี้นำเข้าไฟล์ใบยืมที่อนุมัติแล้ว
 * และเลือกครุภัณฑ์ (ค้นจากเลขครุภัณฑ์) เพื่ออัปเดตสถานะเป็น "กำลังยืม"
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(INV_MODULE, 'admin');
$pdo = db();
$id = int_param('id');

$loan = [
    'borrower_id' => null, 'borrower_name' => '', 'borrower_position' => '', 'borrower_org' => ORG_NAME,
    'borrower_phone' => '', 'doc_no' => '', 'purpose' => '', 'file_path' => null, 'file_name' => null,
    'start_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+7 days')), 'status' => 'borrowed',
];
$selected = [];
if ($id) {
    $st = $pdo->prepare('SELECT * FROM inv_loans WHERE id = ?');
    $st->execute([$id]);
    $loan = $st->fetch();
    if (!$loan) {
        flash('ไม่พบรายการยืม', 'danger');
        redirect('loans.php');
    }
    if ($loan['status'] !== 'borrowed') {
        flash('แก้ไขได้เฉพาะรายการที่กำลังยืม', 'warning');
        redirect('loan_view.php?id=' . $id);
    }
    $st = $pdo->prepare('SELECT equipment_id FROM inv_loan_items WHERE loan_id = ? AND equipment_id IS NOT NULL ORDER BY sort_order, id');
    $st->execute([$id]);
    $selected = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
} elseif ($eq = int_param('equipment')) {
    $selected[] = $eq;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $st = $pdo->prepare('SELECT id, full_name FROM core_users WHERE id = ?');
    $st->execute([int_param('borrower_id')]);
    $borrower = $st->fetch();
    $loan = array_merge($loan, [
        'borrower_id' => $borrower['id'] ?? null, 'borrower_name' => $borrower['full_name'] ?? '',
        'borrower_position' => post('borrower_position'), 'borrower_org' => post('borrower_org'),
        'borrower_phone' => post('borrower_phone'), 'doc_no' => post('doc_no'), 'purpose' => post('purpose'),
        'start_date' => post('start_date'), 'due_date' => post('due_date'),
    ]);
    $selected = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['equipment_ids'] ?? [])))));

    if (!$borrower) {
        $errors[] = 'กรุณาเลือกผู้ยืม';
    }
    $d1 = DateTime::createFromFormat('!Y-m-d', $loan['start_date']);
    $d2 = DateTime::createFromFormat('!Y-m-d', $loan['due_date']);
    if (!$d1 || !$d2) {
        $errors[] = 'กรุณาระบุวันที่ยืมและกำหนดส่งคืน';
    } elseif ($d2 < $d1) {
        $errors[] = 'กำหนดส่งคืนต้องไม่ก่อนวันที่ยืม';
    }
    if (!$selected) {
        $errors[] = 'กรุณาเลือกครุภัณฑ์ที่ยืมอย่างน้อย 1 รายการ';
    }
    $hasNewFile = ($_FILES['loan_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!$id && !$hasNewFile) {
        $errors[] = 'กรุณาแนบไฟล์ใบยืม (PDF หรือรูปภาพ) จากระบบ e-office';
    }

    $newFile = null;
    if (!$errors) {
        try {
            $newFile = save_loan_file($_FILES['loan_file'] ?? []);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $in = implode(',', $selected);
            $eqRows = $pdo->query("SELECT e.*, t.name AS type_name FROM inv_equipment e LEFT JOIN inv_equipment_types t ON t.id = e.type_id
                WHERE e.id IN ($in) FOR UPDATE")->fetchAll(PDO::FETCH_UNIQUE);
            $busy = active_loans_by_equipment($selected);
            $before = [];
            if ($id) {
                $b = $pdo->prepare('SELECT equipment_id FROM inv_loan_items WHERE loan_id = ? AND equipment_id IS NOT NULL');
                $b->execute([$id]);
                $before = array_map('intval', $b->fetchAll(PDO::FETCH_COLUMN));
            }
            foreach ($selected as $eid) {
                $e = $eqRows[$eid] ?? null;
                if (!$e) {
                    throw new RuntimeException('ไม่พบครุภัณฑ์ที่เลือกบางรายการ');
                }
                $label = ($e['asset_no'] ?: equipment_label($e));
                if (isset($busy[$eid]) && (int)$busy[$eid]['loan_id'] !== $id) {
                    throw new RuntimeException($label . ' ถูกยืมอยู่แล้ว (' . $busy[$eid]['borrower_name'] . ' รายการยืม ' . $busy[$eid]['loan_no'] . ')');
                }
                if ($e['status'] !== 'normal' && !in_array($eid, $before, true)) {   // รายการเดิมของใบนี้ยอมให้คงไว้
                    throw new RuntimeException($label . ' อยู่ในสภาพ "' . EQ_STATUS[$e['status']][0] . '" ให้ยืมไม่ได้');
                }
            }

            $data = [
                'borrower_id' => $loan['borrower_id'], 'borrower_name' => $loan['borrower_name'],
                'borrower_position' => $loan['borrower_position'] ?: null, 'borrower_org' => $loan['borrower_org'] ?: null,
                'borrower_phone' => $loan['borrower_phone'] ?: null, 'doc_no' => $loan['doc_no'] ?: null,
                'purpose' => $loan['purpose'] ?: null, 'start_date' => $loan['start_date'], 'due_date' => $loan['due_date'],
            ];
            if ($newFile) {
                [$data['file_path'], $data['file_name']] = $newFile;
            }
            if ($id) {
                $cols = array_keys($data);
                $pdo->prepare('UPDATE inv_loans SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')
                    ->execute([...array_values($data), $id]);
                $pdo->prepare('DELETE FROM inv_loan_items WHERE loan_id = ?')->execute([$id]);
            } else {
                $data += ['loan_no' => generate_loan_no($pdo), 'status' => 'borrowed', 'lender_id' => $me['id'],
                          'lender_name' => $me['full_name'], 'lender_position' => $me['position'],
                          'lent_at' => $loan['start_date'] . ' ' . date('H:i:s'), 'created_by' => $me['id']];
                $cols = array_keys($data);
                $pdo->prepare('INSERT INTO inv_loans (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                    ->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO inv_loan_items (loan_id, equipment_id, item_name, brand_model, serial_number, asset_no, quantity, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
            foreach ($selected as $n => $eid) {
                $e = $eqRows[$eid];
                $ins->execute([$id, $eid, $e['type_name'] ?? 'ครุภัณฑ์', trim(($e['brand'] ?? '') . ' ' . ($e['model'] ?? '')) ?: null,
                               $e['serial_number'], $e['asset_no'], $n + 1]);
            }
            $pdo->commit();
            if ($newFile && !empty($loan['file_path'])) {
                delete_upload($loan['file_path']);          // แทนที่ไฟล์เดิม
            }
            audit_log(isset($data['loan_no']) ? 'loan_create' : 'loan_update', 'loan #' . $id, INV_MODULE);
            flash('บันทึกการยืมเรียบร้อยแล้ว — สถานะครุภัณฑ์เปลี่ยนเป็น "กำลังยืม"');
            redirect('loan_view.php?id=' . $id);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newFile) {
                delete_upload($newFile[0]);
            }
            $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : 'บันทึกไม่สำเร็จ: ' . $ex->getMessage();
        }
    }
}

// ครุภัณฑ์ที่ยืมได้: สภาพปกติและไม่ได้ถูกยืมอยู่ (+ รายการเดิมของใบนี้)
$busy = active_loans_by_equipment();
$choices = [];
foreach ($pdo->query("SELECT e.id, e.asset_no, e.brand, e.model, e.serial_number, e.fiscal_year, e.status, t.name AS type_name
    FROM inv_equipment e LEFT JOIN inv_equipment_types t ON t.id = e.type_id
    ORDER BY e.asset_no IS NULL, e.asset_no, e.id") as $e) {
    $eid = (int)$e['id'];
    $mine = in_array($eid, $selected, true);
    if (!$mine && ($e['status'] !== 'normal' || isset($busy[$eid]))) {
        continue;
    }
    $choices[] = ['id' => $eid, 'asset_no' => $e['asset_no'] ?? '', 'type' => $e['type_name'] ?? '',
                  'label' => trim($e['brand'] . ' ' . $e['model']), 'serial' => $e['serial_number'] ?? '', 'fy' => $e['fiscal_year']];
}
$users = $pdo->query('SELECT id, full_name, position, phone FROM core_users WHERE is_active = 1 OR id = ' . (int)$loan['borrower_id'] . ' ORDER BY full_name')->fetchAll();

$pageTitle = $id ? 'แก้ไขรายการยืม ' . $loan['loan_no'] : 'บันทึกการยืมครุภัณฑ์';
require __DIR__ . '/header.php';
?>
<div class="alert alert-info small"><i class="bi bi-info-circle"></i>
  การขออนุมัติยืมทำผ่านระบบ <strong>e-office</strong> ตามเดิม — เมื่ออนุมัติแล้ว ให้แนบไฟล์ใบยืมและเลือกครุภัณฑ์ที่นี่ เพื่ออัปเดตสถานะเป็น "กำลังยืม"</div>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-xl-5">
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-file-earmark-arrow-up"></i> ไฟล์ใบยืม (จาก e-office)</strong></div>
      <div class="card-body row g-3">
        <?php if ($loan['file_path']): ?>
          <div class="col-12"><a href="loan_file.php?id=<?= (int)$id ?>" target="_blank"><i class="bi bi-paperclip"></i> <?= h($loan['file_name']) ?></a>
            <div class="form-text">เลือกไฟล์ใหม่ด้านล่างเพื่อแทนที่</div></div>
        <?php endif; ?>
        <div class="col-12"><input type="file" name="loan_file" class="form-control" accept=".pdf,image/jpeg,image/png,image/webp" <?= $id ? '' : 'required' ?>>
          <div class="form-text">PDF หรือรูปภาพ ไม่เกิน 10 MB</div></div>
        <div class="col-12"><label class="form-label">เลขที่หนังสือ e-office</label><input name="doc_no" class="form-control" value="<?= h($loan['doc_no']) ?>" placeholder="ไม่บังคับ"></div>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-person"></i> ผู้ยืมและระยะเวลา</strong></div>
      <div class="card-body row g-3">
        <div class="col-12"><label class="form-label">ผู้ยืม <span class="text-danger">*</span></label>
          <select name="borrower_id" class="form-select" id="borrowerSel" required>
            <option value="">— เลือกผู้ยืม —</option>
            <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" data-position="<?= h($u['position']) ?>" data-phone="<?= h($u['phone']) ?>"
              <?= (int)$loan['borrower_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['full_name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-7"><label class="form-label">ตำแหน่ง</label><input name="borrower_position" id="bPos" class="form-control" value="<?= h($loan['borrower_position']) ?>"></div>
        <div class="col-md-5"><label class="form-label">โทรศัพท์</label><input name="borrower_phone" id="bPhone" class="form-control" value="<?= h($loan['borrower_phone']) ?>"></div>
        <div class="col-12"><label class="form-label">หน่วยงาน</label><input name="borrower_org" class="form-control" value="<?= h($loan['borrower_org']) ?>"></div>
        <div class="col-12"><label class="form-label">วัตถุประสงค์</label><input name="purpose" class="form-control" value="<?= h($loan['purpose']) ?>" placeholder="ไม่บังคับ"></div>
        <div class="col-6"><label class="form-label">วันที่ยืม</label><input type="date" name="start_date" class="form-control" required value="<?= h($loan['start_date']) ?>"></div>
        <div class="col-6"><label class="form-label">กำหนดส่งคืน</label><input type="date" name="due_date" class="form-control" required value="<?= h($loan['due_date']) ?>"></div>
      </div>
    </div>
  </div>

  <div class="col-xl-7">
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-upc-scan"></i> ครุภัณฑ์ที่ยืม</strong> <span class="small text-muted">ค้นหาจากเลขครุภัณฑ์ (แสดงเฉพาะที่สภาพปกติและว่างอยู่)</span></div>
      <div class="card-body">
        <div class="input-group mb-2">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="search" id="eqSearch" class="form-control font-monospace" placeholder="พิมพ์เลขครุภัณฑ์ เช่น 0954-0293 แล้วกด Enter" autocomplete="off">
        </div>
        <div class="eq-pick border rounded" id="eqPick"></div>
        <div class="small text-muted mt-1">คลิกรายการเพื่อเพิ่ม · กด Enter เพื่อเพิ่มรายการแรกที่ตรง · ค้นด้วยยี่ห้อ/รุ่นได้เช่นกัน</div>
      </div>
      <div class="card-header border-top"><strong>รายการที่เลือก</strong> <span class="badge text-bg-primary" id="pickCount">0</span></div>
      <ul class="list-group list-group-flush" id="pickedList"></ul>
    </div>
  </div>

  <div class="col-12 sticky-actions">
    <button class="btn btn-primary btn-lg"><i class="bi bi-save"></i> บันทึกการยืม</button>
    <a href="<?= $id ? 'loan_view.php?id=' . $id : 'loans.php' ?>" class="btn btn-light btn-lg">ยกเลิก</a>
  </div>
</form>
<style>
.eq-pick{max-height:300px;overflow-y:auto}
.eq-pick .opt{display:flex;gap:10px;align-items:center;padding:6px 10px;border-bottom:1px solid #f1f4f8;cursor:pointer}
.eq-pick .opt:hover,.eq-pick .opt.first{background:#f5f8fc}
.eq-pick mark{padding:0;background:#fff3a3}
</style>
<script>
(() => {
  const items = <?= json_encode($choices, JSON_UNESCAPED_UNICODE) ?>;
  const picked = <?= json_encode($selected) ?>;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const norm = s => String(s ?? '').toLowerCase().replace(/[\s\-\/]/g, '');
  const mark = (text, q) => { if (!q) return esc(text); const i = String(text).toLowerCase().indexOf(q.toLowerCase()); return i < 0 ? esc(text) : esc(text.slice(0, i)) + '<mark>' + esc(text.slice(i, i + q.length)) + '</mark>' + esc(text.slice(i + q.length)); };
  const box = document.getElementById('eqPick'), list = document.getElementById('pickedList'), search = document.getElementById('eqSearch');
  let matches = [];
  function render() {
    const q = search.value.trim(), nq = norm(q);
    matches = items.filter(e => !picked.includes(e.id) && (!nq || norm(e.asset_no).includes(nq) || norm(e.label + e.type + e.serial).includes(nq))).slice(0, 100);
    box.innerHTML = matches.map((e, i) => `<div class="opt${i === 0 && q ? ' first' : ''}" data-id="${e.id}"><i class="bi bi-plus-circle text-primary"></i>
        <span class="min-w-0"><span class="d-block font-monospace">${e.asset_no ? mark(e.asset_no, q) : '<span class="text-muted fst-italic">(ยังไม่มีเลขครุภัณฑ์)</span>'}</span>
        <span class="d-block small text-muted">${esc(e.type)} · ${esc(e.label)}${e.fy ? ' · ปี ' + e.fy : ''}</span></span></div>`).join('')
      || '<div class="text-center text-muted py-4">ไม่พบครุภัณฑ์ที่ว่างให้ยืม</div>';
    list.innerHTML = picked.map(id => items.find(e => e.id === id)).filter(Boolean).map(e =>
      `<li class="list-group-item d-flex align-items-center gap-2"><input type="hidden" name="equipment_ids[]" value="${e.id}">
        <i class="bi bi-laptop text-primary"></i><span class="min-w-0"><span class="d-block font-monospace">${esc(e.asset_no || '(ยังไม่มีเลข)')}</span>
        <span class="small text-muted">${esc(e.type)} · ${esc(e.label)}</span></span>
        <button type="button" class="btn btn-sm btn-light ms-auto" data-unpick="${e.id}" title="นำออก"><i class="bi bi-x-lg"></i></button></li>`).join('')
      || '<li class="list-group-item text-muted small">ยังไม่ได้เลือก</li>';
    document.getElementById('pickCount').textContent = picked.length;
  }
  const add = id => { if (!picked.includes(id)) picked.push(id); search.value = ''; render(); search.focus(); };
  box.addEventListener('click', ev => { const o = ev.target.closest('.opt'); if (o) add(+o.dataset.id); });
  list.addEventListener('click', ev => { const b = ev.target.closest('[data-unpick]'); if (b) { picked.splice(picked.indexOf(+b.dataset.unpick), 1); render(); } });
  search.addEventListener('input', render);
  search.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); if (search.value.trim() && matches[0]) add(matches[0].id); } });
  render();
  const sel = document.getElementById('borrowerSel');
  sel.addEventListener('change', () => { const o = sel.selectedOptions[0]; document.getElementById('bPos').value = o.dataset.position || ''; document.getElementById('bPhone').value = o.dataset.phone || ''; });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
