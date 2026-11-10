<?php
/**
 * บันทึกการยืมครุภัณฑ์ (ผู้ดูแลระบบวัสดุและครุภัณฑ์)
 * การขออนุมัติยืมทำในระบบ e-office ตามเดิม — หน้านี้อ่านใบยืม (PDF) ที่อนุมัติแล้วเพื่อตรวจสอบ
 * ผู้ยืม ระยะเวลา และหมายเลขพัสดุ (loan_pdf.php) แล้วยืนยันกรอกลงฟอร์ม — ไม่เก็บไฟล์ใบยืม
 * เลือกครุภัณฑ์ (ค้นจากเลขครุภัณฑ์) เพื่ออัปเดตสถานะเป็น "กำลังยืม"
 * รายการที่ไม่อยู่ในทะเบียนครุภัณฑ์ (สายชาร์จ ชุดโปรแกรม ฯลฯ) บันทึกเฉพาะรายการ (equipment_id = NULL)
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
$accessories = [];      // รายการที่ไม่อยู่ในทะเบียนครุภัณฑ์ [['name', 'brand', 'serial', 'asset', 'qty']]
if ($id) {
    $st = $pdo->prepare('SELECT * FROM inv_loans WHERE id = ?');
    $st->execute([$id]);
    $loan = $st->fetch();
    if (!$loan) {
        flash('ไม่พบรายการยืม', 'danger');
        redirect('loans');
    }
    if ($loan['status'] !== 'borrowed') {
        flash('แก้ไขได้เฉพาะรายการที่กำลังยืม', 'warning');
        redirect('loan_view?id=' . $id);
    }
    $st = $pdo->prepare('SELECT equipment_id FROM inv_loan_items WHERE loan_id = ? AND equipment_id IS NOT NULL ORDER BY sort_order, id');
    $st->execute([$id]);
    $selected = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $st = $pdo->prepare('SELECT item_name AS name, brand_model AS brand, serial_number AS serial, asset_no AS asset, quantity AS qty FROM inv_loan_items WHERE loan_id = ? AND equipment_id IS NULL ORDER BY sort_order, id');
    $st->execute([$id]);
    $accessories = $st->fetchAll();
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
    $accessories = [];
    foreach ((array)($_POST['acc_name'] ?? []) as $i => $nm) {
        $nm = trim((string)$nm);
        if ($nm !== '') {
            $f = fn($k, $len) => mb_substr(trim((string)($_POST[$k][$i] ?? '')), 0, $len);
            $accessories[] = ['name' => mb_substr($nm, 0, 200), 'brand' => $f('acc_brand', 200), 'serial' => $f('acc_serial', 100),
                              'asset' => $f('acc_asset', 100), 'qty' => max(1, (int)($_POST['acc_qty'][$i] ?? 1))];
        }
    }

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
    if (!$selected && !$accessories) {
        $errors[] = 'กรุณาเลือกครุภัณฑ์หรือรายการที่ยืมอย่างน้อย 1 รายการ';
    }
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $in = implode(',', $selected) ?: '0';
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
                if (eq_is_holding_type($e['type_name']) && !in_array($eid, $before, true)) {
                    throw new RuntimeException($label . ' เป็น' . $e['type_name'] . ' — เป็นการถือครอง ไม่ใช่การยืม (กำหนดผู้ถือครองที่ข้อมูลครุภัณฑ์)');
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
            $insA = $pdo->prepare('INSERT INTO inv_loan_items (loan_id, equipment_id, item_name, brand_model, serial_number, asset_no, quantity, sort_order)
                VALUES (?, NULL, ?, ?, ?, ?, ?, ?)');
            foreach ($accessories as $n => $a) {
                $insA->execute([$id, $a['name'], $a['brand'] ?: null, $a['serial'] ?: null, $a['asset'] ?: null, $a['qty'], count($selected) + $n + 1]);
            }
            $pdo->commit();
            audit_log(isset($data['loan_no']) ? 'loan_create' : 'loan_update', 'loan #' . $id, INV_MODULE);
            flash('บันทึกการยืมเรียบร้อยแล้ว — สถานะครุภัณฑ์เปลี่ยนเป็น "กำลังยืม"');
            redirect('loan_view?id=' . $id);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
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
    if (!$mine && ($e['status'] !== 'normal' || isset($busy[$eid]) || eq_is_holding_type($e['type_name']))) {   // ถือครอง ไม่ใช่การยืม
        continue;
    }
    $choices[] = ['id' => $eid, 'asset_no' => $e['asset_no'] ?? '', 'type' => $e['type_name'] ?? '',
                  'label' => trim($e['brand'] . ' ' . $e['model']), 'serial' => $e['serial_number'] ?? '', 'fy' => $e['fiscal_year']];
}
$users = array_values(sort_staff($pdo->query('SELECT id, username, sort_order, full_name, position, phone FROM core_users WHERE is_active = 1 OR id = ' . (int)$loan['borrower_id'])->fetchAll()));   // เรียงตามลำดับตำแหน่ง

$pageTitle = $id ? 'แก้ไขรายการยืม ' . $loan['loan_no'] : 'บันทึกการยืมครุภัณฑ์';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<form method="post" class="row g-3" id="loanForm">
  <?= csrf_field() ?>
  <div class="col-xl-5">
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-file-earmark-check"></i> ตรวจสอบจากใบยืม (PDF จาก e-office)</strong></div>
      <div class="card-body row g-3">
        <?php if ($loan['file_path']): ?>
          <div class="col-12"><a href="loan_file?id=<?= (int)$id ?>" target="_blank"><i class="bi bi-paperclip"></i> <?= h($loan['file_name']) ?></a> <span class="small text-muted">(ไฟล์เดิม)</span></div>
        <?php endif; ?>
        <div class="col-12"><input type="file" id="pdfFile" class="form-control" accept=".pdf,application/pdf">
          <div class="form-text" id="pdfStatus">เลือกไฟล์ใบยืม ระบบจะอ่านชื่อผู้ยืม ระยะเวลา และหมายเลขพัสดุให้ตรวจสอบ — ใช้ตรวจสอบเท่านั้น ไม่เก็บไฟล์</div></div>
        <div class="col-12 d-none" id="pdfResult"></div>
        <div class="col-12"><label class="form-label">เลขที่หนังสือ e-office</label><input name="doc_no" id="docNo" class="form-control" value="<?= h($loan['doc_no']) ?>" placeholder="ไม่บังคับ"></div>
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
        <div class="col-12"><label class="form-label">หน่วยงาน</label><input name="borrower_org" id="bOrg" class="form-control" value="<?= h($loan['borrower_org']) ?>"></div>
        <div class="col-12"><label class="form-label">วัตถุประสงค์</label><input name="purpose" id="bPurpose" class="form-control" value="<?= h($loan['purpose']) ?>" placeholder="ไม่บังคับ"></div>
        <div class="col-6"><label class="form-label">วันที่ยืม</label><input type="date" name="start_date" id="dStart" class="form-control" required value="<?= h($loan['start_date']) ?>"></div>
        <div class="col-6"><label class="form-label">กำหนดส่งคืน</label><input type="date" name="due_date" id="dDue" class="form-control" required value="<?= h($loan['due_date']) ?>"></div>
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
    <a href="<?= $id ? 'loan_view?id=' . $id : 'loans' ?>" class="btn btn-light btn-lg">ยกเลิก</a>
  </div>
</form>
<style>
.eq-pick{max-height:300px;overflow-y:auto}
.eq-pick .opt{display:flex;gap:10px;align-items:center;padding:6px 10px;border-bottom:1px solid #f1f4f8;cursor:pointer}
.eq-pick .opt:hover,.eq-pick .opt.first{background:#f5f8fc}
.eq-pick mark{padding:0;background:#fff3a3}
</style>
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js" integrity="sha384-/1qUCSGwTur9vjf/z9lmu/eCUYbpOTgSjmpbMQZ1/CtX2v/WcAIKqRv+U1DUCG6e" crossorigin="anonymous"></script>
<script>
(() => {
  const items = <?= json_encode($choices, JSON_UNESCAPED_UNICODE) ?>;
  const picked = <?= json_encode($selected) ?>;
  const accs = <?= json_encode(array_map(fn($a) => ['name' => $a['name'], 'brand' => (string)$a['brand'], 'serial' => (string)$a['serial'],
      'asset' => (string)$a['asset'], 'qty' => (int)$a['qty']], $accessories), JSON_UNESCAPED_UNICODE) ?>;
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
      + accs.map((a, i) => `<li class="list-group-item d-flex align-items-center gap-2"><input type="hidden" name="acc_name[]" value="${esc(a.name)}"><input type="hidden" name="acc_qty[]" value="${a.qty}">
        <input type="hidden" name="acc_brand[]" value="${esc(a.brand)}"><input type="hidden" name="acc_serial[]" value="${esc(a.serial)}"><input type="hidden" name="acc_asset[]" value="${esc(a.asset)}">
        <i class="bi bi-box text-secondary"></i><span class="min-w-0"><span class="d-block">${esc(a.name)}</span><span class="small text-muted">${[a.brand, a.serial ? 'S/N ' + a.serial : '', a.asset].filter(Boolean).map(esc).join(' · ')}${a.brand || a.serial || a.asset ? ' · ' : ''}ไม่อยู่ในทะเบียนครุภัณฑ์ · จำนวน ${a.qty}</span></span>
        <button type="button" class="btn btn-sm btn-light ms-auto" data-unacc="${i}" title="นำออก"><i class="bi bi-x-lg"></i></button></li>`).join('')
      || '<li class="list-group-item text-muted small">ยังไม่ได้เลือก</li>';
    document.getElementById('pickCount').textContent = picked.length + accs.length;
  }
  const add = id => { if (!picked.includes(id)) picked.push(id); search.value = ''; render(); search.focus(); };
  box.addEventListener('click', ev => { const o = ev.target.closest('.opt'); if (o) add(+o.dataset.id); });
  list.addEventListener('click', ev => {
    const b = ev.target.closest('[data-unpick]'); if (b) { picked.splice(picked.indexOf(+b.dataset.unpick), 1); render(); }
    const a = ev.target.closest('[data-unacc]'); if (a) { accs.splice(+a.dataset.unacc, 1); render(); }
  });
  search.addEventListener('input', render);
  search.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); if (search.value.trim() && matches[0]) add(matches[0].id); } });
  render();
  const sel = document.getElementById('borrowerSel');
  sel.addEventListener('change', () => { const o = sel.selectedOptions[0]; document.getElementById('bPos').value = o.dataset.position || ''; document.getElementById('bPhone').value = o.dataset.phone || ''; });

  // ---- อ่านใบยืม PDF (pdf.js) → ตรวจสอบที่ loan_pdf → ผู้ใช้ยืนยันแล้วกรอกลงฟอร์ม (ไม่อัปโหลดไฟล์) ----
  const pdfFile = document.getElementById('pdfFile'), pdfStatus = document.getElementById('pdfStatus'), pdfResult = document.getElementById('pdfResult');
  const TH_M = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
  const thDate = d => { if (!d) return '<span class="text-danger">ไม่พบ</span>'; const [y, m, dd] = d.split('-').map(Number); return dd + ' ' + TH_M[m - 1] + ' ' + (y + 543); };
  const ok = t => `<i class="bi bi-check-circle-fill text-success"></i> ${t}`, warn = t => `<i class="bi bi-exclamation-triangle-fill text-warning"></i> ${t}`, bad = t => `<i class="bi bi-x-circle-fill text-danger"></i> ${t}`;
  let parsed = null;
  // ข้อความในตราประทับ e-office (/Contents ของ annotation) อ่านจากไฟล์ตรง ๆ — ตราถูกตั้งเป็นซ่อน pdf.js จึงไม่ส่งให้
  function stampTexts(bytes) {
    let raw = '';
    for (let i = 0; i < bytes.length; i += 32768) raw += String.fromCharCode.apply(null, bytes.subarray(i, i + 32768));
    const out = [], re = /\/Contents\s*([(<])/g;
    let m;
    while ((m = re.exec(raw)) && out.length < 200) {
      let i = re.lastIndex, b = '';
      if (m[1] === '<') {
        const end = raw.indexOf('>', i); if (end < 0) break;
        const hex = raw.slice(i, end).replace(/\s/g, '');
        for (let k = 0; k + 1 < hex.length; k += 2) b += String.fromCharCode(parseInt(hex.substr(k, 2), 16));
      } else {
        for (let depth = 1; i < raw.length; i++) {
          const ch = raw[i];
          if (ch === '\\') {
            const n = raw[++i], esc = { n: '\n', r: '\r', t: '\t', b: '\b', f: '\f' };
            if (esc[n]) b += esc[n];
            else if (/[0-7]/.test(n)) { let o = n; while (o.length < 3 && /[0-7]/.test(raw[i + 1])) o += raw[++i]; b += String.fromCharCode(parseInt(o, 8) & 255); }
            else if (n !== '\n' && n !== '\r') b += n;
          } else if (ch === '(') { depth++; b += ch; }
          else if (ch === ')') { if (--depth === 0) break; b += ch; }
          else b += ch;
        }
      }
      let t = '';
      if (b.charCodeAt(0) === 0xFE && b.charCodeAt(1) === 0xFF) { for (let k = 2; k + 1 < b.length; k += 2) t += String.fromCharCode(b.charCodeAt(k) << 8 | b.charCodeAt(k + 1)); }
      else { try { t = decodeURIComponent(escape(b)); } catch (e) { t = b; } }
      if (/doc_no|add_date/i.test(t)) out.push(t);
    }
    return out;
  }
  pdfFile.addEventListener('change', async () => {
    const file = pdfFile.files[0];
    pdfResult.classList.add('d-none');
    if (!file) return;
    if (!window.pdfjsLib) { pdfStatus.textContent = 'โหลดตัวอ่าน PDF ไม่ได้ — ตรวจการเชื่อมต่ออินเทอร์เน็ต'; return; }
    try {
      pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
      pdfStatus.textContent = 'กำลังอ่านไฟล์…';
      const bytes = new Uint8Array(await file.arrayBuffer());
      const annots = stampTexts(bytes);
      const doc = await pdfjsLib.getDocument({ data: bytes.slice() }).promise;
      const pages = [];
      for (let i = 1; i <= Math.min(doc.numPages, 10); i++) {
        const pg = await doc.getPage(i), vp = pg.getViewport({ scale: 1 }), tc = await pg.getTextContent();
        pages.push(tc.items.filter(it => it.str && it.str.trim() !== '').map(it => [it.str, Math.round(it.transform[4] * 10) / 10,
          Math.round((vp.height - it.transform[5]) * 10) / 10, Math.round(it.width * 10) / 10]));
      }
      if (!pages.some(p => p.length)) { pdfStatus.textContent = 'ไม่พบข้อความในไฟล์ (อาจเป็นภาพสแกน) — กรุณากรอกข้อมูลเอง'; return; }
      pdfStatus.textContent = 'กำลังตรวจสอบกับทะเบียนครุภัณฑ์…';
      const fd = new FormData();
      fd.append('csrf', document.querySelector('#loanForm input[name=csrf]').value);
      fd.append('pages', JSON.stringify(pages));
      fd.append('annots', JSON.stringify(annots.slice(0, 200)));
      fd.append('loan_id', <?= (int)$id ?>);
      const r = await fetch('loan_pdf', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await r.json();
      if (!r.ok || data.error) throw new Error(data.error || ('HTTP ' + r.status));
      parsed = data;
      showResult(file.name);
    } catch (err) {
      pdfStatus.textContent = 'อ่านไฟล์ไม่ได้: ' + err.message;
    }
  });
  function showResult(name) {
    const d = parsed, u = d.borrower_id ? [...sel.options].find(o => +o.value === d.borrower_id) : null;
    const days = d.start_date && d.due_date ? Math.round((new Date(d.due_date) - new Date(d.start_date)) / 864e5) + 1 : 0;
    const rows = d.items.map((it, i) => {
      let res;
      const cands = it.candidates.filter(c => items.some(e => e.id === +c.id) || picked.includes(+c.id));
      if (it.match === 'accessory') {
        res = `<label class="form-check mb-0"><input type="checkbox" class="form-check-input" data-acc="${i}" checked> <span class="text-muted">ไม่มีเลขพัสดุ — บันทึกเฉพาะรายการ</span></label>`;
      } else if (!it.candidates.length) {
        res = `<label class="form-check mb-0"><input type="checkbox" class="form-check-input" data-acc="${i}" checked> ${warn('ไม่พบในทะเบียนครุภัณฑ์ — บันทึกเฉพาะรายการ')}</label>`;
      } else {
        res = it.candidates.map((c, k) => {
          const usable = !c.problem && cands.some(x => x.id === c.id);
          const icon = c.problem ? bad : (it.match === 'exact' || it.match === 'serial' ? ok : warn);
          return `<label class="form-check mb-0"><input type="${it.match === 'partial' ? 'radio' : 'checkbox'}" class="form-check-input" name="pdfPick${i}" data-eq="${c.id}"
            ${usable && it.match !== 'partial' ? 'checked' : ''} ${usable ? '' : 'disabled'}>
            ${icon(`<span class="font-monospace">${esc(c.asset_no || '-')}</span>`)} <span class="small text-muted">${esc(c.label)}</span>
            ${c.problem ? `<div class="small text-danger">${esc(c.problem)}</div>` : ''}</label>`;
        }).join('') + (it.match === 'partial' ? `<label class="form-check mb-0"><input type="radio" class="form-check-input" name="pdfPick${i}" data-acc="${i}" checked>
            <span class="text-muted">ไม่ใช่รายการข้างบน — บันทึกเฉพาะรายการ</span></label>
            <div class="small text-warning-emphasis">ตรงเฉพาะท้ายเลข — ถ้าเป็นเครื่องเดียวกันให้เลือกเลขในทะเบียน</div>` : '')
          + (it.match === 'serial' ? `<div class="small text-muted">ตรงจากหมายเลขเครื่อง</div>` : '');
      }
      return `<tr><td class="text-center">${it.seq}</td><td>${esc(it.name)}${it.brand ? `<div class="small text-muted">${esc(it.brand)}</div>` : ''}
        <div class="small">ในไฟล์: <span class="font-monospace">${esc(it.asset || '-')}</span>${it.serial ? ` · S/N <span class="font-monospace">${esc(it.serial)}</span>` : ''}${it.qty > 1 ? ' · จำนวน ' + it.qty : ''}</div></td><td>${res}</td></tr>`;
    }).join('');
    pdfResult.innerHTML = `<div class="border rounded p-2 bg-body-tertiary small">
      <div class="fw-semibold mb-1"><i class="bi bi-file-earmark-pdf text-danger"></i> ${esc(name)}</div>
      ${d.errors.map(e => `<div class="text-danger">${esc(e)}</div>`).join('')}
      <table class="table table-sm mb-2 bg-transparent"><tbody>
        <tr><th class="text-nowrap" style="width:1%">ผู้ยืม</th><td>${esc(d.borrower || '-')}<br>${u ? ok('ตรงกับผู้ใช้ ' + esc(u.textContent)) : bad('ไม่พบในรายชื่อผู้ใช้ — เลือกผู้ยืมเอง')}</td></tr>
        <tr><th>ตำแหน่ง</th><td>${esc(d.position || '-')}${d.phone ? ' · โทร ' + esc(d.phone) : ''}</td></tr>
        <tr><th class="text-nowrap">เลขที่หนังสือ</th><td>${d.doc_no ? esc(d.doc_no) : '<span class="text-muted">ไม่พบ</span>'}</td></tr>
        <tr><th>ระยะเวลา</th><td>${thDate(d.start_date)} – ${thDate(d.due_date)}${days > 0 ? ' (' + days + ' วัน)' : (days ? ' ' + bad('วันคืนก่อนวันยืม') : '')}</td></tr>
        ${d.purpose ? `<tr><th>วัตถุประสงค์</th><td>${esc(d.purpose)}</td></tr>` : ''}
      </tbody></table>
      <div class="fw-semibold">รายการพัสดุที่ยืม (${d.items.length})</div>
      <table class="table table-sm align-top mb-2"><thead><tr><th>ที่</th><th>ในใบยืม</th><th>ทะเบียนครุภัณฑ์</th></tr></thead><tbody>${rows || '<tr><td colspan="3" class="text-muted">ไม่พบรายการ</td></tr>'}</tbody></table>
      <button type="button" class="btn btn-success btn-sm" id="pdfConfirm"><i class="bi bi-check2-square"></i> ยืนยันข้อมูล กรอกลงฟอร์ม</button>
      <span class="text-muted ms-1">ตรวจข้อมูลด้านบนก่อนยืนยัน แล้วกด "บันทึกการยืม"</span></div>`;
    pdfResult.classList.remove('d-none');
    pdfStatus.textContent = 'อ่านแล้ว — ตรวจสอบข้อมูลด้านล่าง (ไฟล์ไม่ถูกบันทึก)';
  }
  pdfResult.addEventListener('click', ev => {
    if (!ev.target.closest('#pdfConfirm') || !parsed) return;
    const d = parsed;
    if (d.borrower_id) { sel.value = d.borrower_id; sel.dispatchEvent(new Event('change')); }
    if (d.position) document.getElementById('bPos').value = d.position;
    if (d.phone) document.getElementById('bPhone').value = d.phone;
    if (d.org) document.getElementById('bOrg').value = d.org;
    if (d.purpose) document.getElementById('bPurpose').value = d.purpose;
    if (d.doc_no) document.getElementById('docNo').value = d.doc_no;
    if (d.start_date) document.getElementById('dStart').value = d.start_date;
    if (d.due_date) document.getElementById('dDue').value = d.due_date;
    pdfResult.querySelectorAll('input[data-eq]:checked').forEach(c => { if (!picked.includes(+c.dataset.eq)) picked.push(+c.dataset.eq); });
    pdfResult.querySelectorAll('input[data-acc]:checked').forEach(c => {
      const it = d.items[+c.dataset.acc];
      const a = { name: it.name || it.brand || '-', brand: it.name ? it.brand : '', serial: it.serial, asset: it.asset, qty: it.qty || 1 };
      if (!accs.some(x => x.name === a.name && x.brand === a.brand && x.serial === a.serial && x.asset === a.asset)) accs.push(a);
    });
    render();
    pdfStatus.innerHTML = ok('กรอกข้อมูลจากใบยืมแล้ว — ตรวจสอบอีกครั้งแล้วกด "บันทึกการยืม"');
    pdfResult.classList.add('d-none');
  });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
