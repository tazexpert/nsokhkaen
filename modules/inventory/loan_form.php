<?php
/**
 * บันทึกขออนุมัติยืมพัสดุ (สร้างใหม่ / แก้ไขขณะรออนุมัติ)
 *   เจ้าหน้าที่: ขอยืมในนามตนเอง → สถานะ "รออนุมัติ" → ผู้ดูแลได้รับแจ้งเตือน
 *   ผู้ดูแล: บันทึกแทนผู้อื่นได้ และเลือก "อนุมัติและส่งมอบทันที" ได้
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(INV_MODULE);
$pdo = db();
$isAdmin = user_can(INV_MODULE, 'admin');
$id = int_param('id');

$loan = [
    'borrower_id' => $me['id'], 'borrower_name' => $me['full_name'], 'borrower_position' => $me['position'],
    'borrower_org' => ORG_NAME, 'borrower_phone' => $me['phone'], 'purpose' => '',
    'start_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+7 days')), 'status' => 'pending',
    'created_by' => $me['id'],
];
$selected = [];       // equipment ids
$extras = [];         // อุปกรณ์ประกอบ [item_name, brand_model, quantity]
if ($id) {
    $st = $pdo->prepare('SELECT * FROM inv_loans WHERE id = ?');
    $st->execute([$id]);
    $loan = $st->fetch();
    if (!$loan || !can_view_loan($loan)) {
        flash('ไม่พบใบยืม หรือไม่มีสิทธิ์', 'danger');
        redirect('loans.php');
    }
    if ($loan['status'] !== 'pending') {
        flash('แก้ไขได้เฉพาะใบยืมที่รออนุมัติ', 'warning');
        redirect('loan_view.php?id=' . $id);
    }
    $st = $pdo->prepare('SELECT * FROM inv_loan_items WHERE loan_id = ? ORDER BY sort_order, id');
    $st->execute([$id]);
    foreach ($st as $it) {
        if ($it['equipment_id']) {
            $selected[] = (int)$it['equipment_id'];
        } else {
            $extras[] = $it;
        }
    }
} elseif ($eq = int_param('equipment')) {
    $selected[] = $eq;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $borrowerId = $isAdmin ? int_param('borrower_id') : (int)$loan['borrower_id'];
    $st = $pdo->prepare('SELECT id, full_name FROM core_users WHERE id = ? AND is_active = 1');
    $st->execute([$borrowerId]);
    $borrower = $st->fetch();
    $loan = array_merge($loan, [
        'borrower_id' => $borrowerId, 'borrower_name' => $borrower['full_name'] ?? '',
        'borrower_position' => post('borrower_position'), 'borrower_org' => post('borrower_org'),
        'borrower_phone' => post('borrower_phone'), 'purpose' => post('purpose'),
        'start_date' => post('start_date'), 'due_date' => post('due_date'),
    ]);
    $selected = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['equipment_ids'] ?? [])))));
    $extras = [];
    foreach ((array)($_POST['extra_name'] ?? []) as $i => $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            $extras[] = ['item_name' => $name, 'brand_model' => trim((string)($_POST['extra_brand'][$i] ?? '')),
                         'quantity' => max(1, (int)($_POST['extra_qty'][$i] ?? 1))];
        }
    }
    $approveNow = $isAdmin && !empty($_POST['approve_now']);

    if (!$borrower) {
        $errors[] = 'กรุณาเลือกผู้ยืม';
    }
    if ($loan['purpose'] === '') {
        $errors[] = 'กรุณาระบุวัตถุประสงค์การยืม';
    }
    $d1 = DateTime::createFromFormat('!Y-m-d', $loan['start_date']);
    $d2 = DateTime::createFromFormat('!Y-m-d', $loan['due_date']);
    if (!$d1 || !$d2) {
        $errors[] = 'กรุณาระบุวันที่ยืมและวันที่ส่งคืน';
    } elseif ($d2 < $d1) {
        $errors[] = 'วันที่ส่งคืนต้องไม่ก่อนวันที่เริ่มยืม';
    }
    if (!$selected) {
        $errors[] = 'กรุณาเลือกครุภัณฑ์ที่ต้องการยืมอย่างน้อย 1 รายการ';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            // ล็อกครุภัณฑ์ที่เลือก แล้วตรวจว่ายังว่างอยู่ (กันคนสองคนยืมชิ้นเดียวกันพร้อมกัน)
            $in = implode(',', $selected);
            $eqRows = $pdo->query("SELECT e.*, t.name AS type_name FROM inv_equipment e LEFT JOIN inv_equipment_types t ON t.id = e.type_id
                WHERE e.id IN ($in) FOR UPDATE")->fetchAll(PDO::FETCH_UNIQUE);
            $busy = active_loans_by_equipment($selected);
            foreach ($selected as $eid) {
                $e = $eqRows[$eid] ?? null;
                if (!$e) {
                    throw new RuntimeException('ไม่พบครุภัณฑ์ที่เลือกบางรายการ');
                }
                if ($e['status'] !== 'normal') {
                    throw new RuntimeException(equipment_label($e) . ' ' . ($e['asset_no'] ?? '') . ' อยู่ในสภาพ "' . EQ_STATUS[$e['status']][0] . '" ให้ยืมไม่ได้');
                }
                if (isset($busy[$eid]) && (int)$busy[$eid]['loan_id'] !== $id) {
                    throw new RuntimeException(equipment_label($e) . ' ' . ($e['asset_no'] ?? '') . ' ถูกยืม/จองแล้ว (ใบยืม ' . $busy[$eid]['loan_no'] . ')');
                }
            }

            $data = [
                'borrower_id' => $loan['borrower_id'], 'borrower_name' => $loan['borrower_name'],
                'borrower_position' => $loan['borrower_position'] ?: null, 'borrower_org' => $loan['borrower_org'] ?: null,
                'borrower_phone' => $loan['borrower_phone'] ?: null, 'purpose' => $loan['purpose'],
                'start_date' => $loan['start_date'], 'due_date' => $loan['due_date'],
            ];
            if ($approveNow) {
                $ls = loan_settings();
                $data += ['status' => 'borrowed', 'lender_id' => $me['id'], 'lender_name' => $me['full_name'],
                          'lender_position' => $me['position'], 'approver_name' => $ls['loan_approver_name'] ?: null,
                          'approver_position' => $ls['loan_approver_position'] ?: null, 'lent_at' => date('Y-m-d H:i:s')];
            }
            $cols = array_keys($data);
            if ($id) {
                $pdo->prepare('UPDATE inv_loans SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')
                    ->execute([...array_values($data), $id]);
                $pdo->prepare('DELETE FROM inv_loan_items WHERE loan_id = ?')->execute([$id]);
            } else {
                $data['loan_no'] = generate_loan_no($pdo);
                $data['created_by'] = $me['id'];
                $cols = array_keys($data);
                $pdo->prepare('INSERT INTO inv_loans (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                    ->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO inv_loan_items (loan_id, equipment_id, item_name, brand_model, serial_number, asset_no, quantity, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $n = 0;
            foreach ($selected as $eid) {
                $e = $eqRows[$eid];
                $ins->execute([$id, $eid, $e['type_name'] ?? 'ครุภัณฑ์', trim(($e['brand'] ?? '') . ' ' . ($e['model'] ?? '')) ?: null,
                               $e['serial_number'], $e['asset_no'], 1, ++$n]);
            }
            foreach ($extras as $x) {
                $ins->execute([$id, null, $x['item_name'], $x['brand_model'] ?: null, null, null, $x['quantity'], ++$n]);
            }
            $pdo->commit();
            audit_log($approveNow ? 'loan_create_approved' : 'loan_save', 'loan #' . $id, INV_MODULE);
            flash($approveNow ? 'บันทึกการยืมและส่งมอบเรียบร้อยแล้ว' : 'บันทึกคำขอยืมเรียบร้อยแล้ว รอผู้ดูแลอนุมัติ');
            redirect('loan_view.php?id=' . $id);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : 'บันทึกไม่สำเร็จ: ' . $ex->getMessage();
        }
    }
}

// ครุภัณฑ์ที่ยืมได้ (สภาพปกติ และไม่อยู่ในใบยืมอื่นที่ยังไม่ปิด) + รายการที่เลือกไว้แล้วในใบนี้
$busy = active_loans_by_equipment();
$available = [];
foreach ($pdo->query("SELECT e.id, e.asset_no, e.brand, e.model, e.serial_number, e.fiscal_year, t.name AS type_name
    FROM inv_equipment e LEFT JOIN inv_equipment_types t ON t.id = e.type_id WHERE e.status = 'normal'
    ORDER BY t.sort_order IS NULL, t.sort_order, e.brand, e.model, e.asset_no") as $e) {
    $eid = (int)$e['id'];
    if (isset($busy[$eid]) && (int)$busy[$eid]['loan_id'] !== $id) {
        continue;
    }
    $available[] = ['id' => $eid, 'type' => $e['type_name'] ?? 'ไม่ระบุประเภท', 'label' => trim($e['brand'] . ' ' . $e['model']),
                    'asset_no' => $e['asset_no'] ?? '', 'serial' => $e['serial_number'] ?? '', 'fy' => $e['fiscal_year']];
}
$users = $isAdmin ? $pdo->query('SELECT id, full_name, position, phone FROM core_users WHERE is_active = 1 ORDER BY full_name')->fetchAll() : [];
if (!$extras) {
    $extras = [['item_name' => '', 'brand_model' => '', 'quantity' => 1]];
}

$pageTitle = $id ? 'แก้ไขใบยืม ' . $loan['loan_no'] : 'บันทึกขออนุมัติยืมพัสดุ';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<form method="post" class="row g-3" id="loanForm">
  <?= csrf_field() ?>
  <div class="col-xl-5">
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-person"></i> ผู้ยืม</strong></div>
      <div class="card-body row g-3">
        <div class="col-12"><label class="form-label">ชื่อผู้ยืม</label>
          <?php if ($isAdmin): ?>
            <select name="borrower_id" class="form-select" id="borrowerSel" required>
              <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" data-position="<?= h($u['position']) ?>" data-phone="<?= h($u['phone']) ?>"
                <?= (int)$loan['borrower_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['full_name']) ?></option><?php endforeach; ?>
            </select>
          <?php else: ?><input class="form-control" value="<?= h($loan['borrower_name']) ?>" disabled><?php endif; ?></div>
        <div class="col-md-7"><label class="form-label">ตำแหน่ง</label><input name="borrower_position" id="bPos" class="form-control" value="<?= h($loan['borrower_position']) ?>"></div>
        <div class="col-md-5"><label class="form-label">หมายเลขโทรศัพท์</label><input name="borrower_phone" id="bPhone" class="form-control" value="<?= h($loan['borrower_phone']) ?>"></div>
        <div class="col-12"><label class="form-label">หน่วยงาน</label><input name="borrower_org" class="form-control" value="<?= h($loan['borrower_org']) ?>"></div>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-calendar-range"></i> วัตถุประสงค์และระยะเวลา</strong></div>
      <div class="card-body row g-3">
        <div class="col-12"><label class="form-label">มีความประสงค์จะขอยืมพัสดุ เพื่อ <span class="text-danger">*</span></label>
          <textarea name="purpose" class="form-control" rows="2" required placeholder="เช่น ใช้ปฏิบัติงานตรวจสอบงานโครงการต่างๆ"><?= h($loan['purpose']) ?></textarea></div>
        <div class="col-6"><label class="form-label">ตั้งแต่วันที่</label><input type="date" name="start_date" class="form-control" required value="<?= h($loan['start_date']) ?>"></div>
        <div class="col-6"><label class="form-label">ถึง (ส่งคืนภายใน)</label><input type="date" name="due_date" class="form-control" required value="<?= h($loan['due_date']) ?>"></div>
      </div>
    </div>
  </div>

  <div class="col-xl-7">
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-laptop"></i> ครุภัณฑ์ที่ขอยืม</strong> <span class="small text-muted">(แสดงเฉพาะที่สภาพปกติและว่างอยู่)</span></div>
      <div class="card-body">
        <div class="input-group mb-2">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="search" id="eqSearch" class="form-control" placeholder="ค้นหายี่ห้อ / รุ่น / เลขครุภัณฑ์">
        </div>
        <div class="eq-pick border rounded" id="eqPick"></div>
        <div class="small text-muted mt-1">คลิกรายการเพื่อเลือก/ยกเลิก · เลือกแล้ว <strong id="pickCount">0</strong> รายการ</div>
      </div>
      <ul class="list-group list-group-flush" id="pickedList"></ul>
    </div>
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center"><strong><i class="bi bi-plug"></i> อุปกรณ์ประกอบ</strong> <span class="small text-muted ms-2">เช่น ปลั๊กเสียบชาร์จ กระเป๋า เมาส์</span>
        <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="addExtra"><i class="bi bi-plus"></i> เพิ่มแถว</button></div>
      <div class="card-body" id="extras">
        <?php foreach ($extras as $x): ?>
          <div class="row g-2 mb-2 extra-row">
            <div class="col-5"><input name="extra_name[]" class="form-control" placeholder="รายการ" value="<?= h($x['item_name']) ?>"></div>
            <div class="col-4"><input name="extra_brand[]" class="form-control" placeholder="ยี่ห้อ/รุ่น" value="<?= h($x['brand_model']) ?>"></div>
            <div class="col-2"><input name="extra_qty[]" type="number" min="1" class="form-control" value="<?= (int)$x['quantity'] ?>"></div>
            <div class="col-1"><button type="button" class="btn btn-light rm-extra" title="ลบ"><i class="bi bi-x-lg"></i></button></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-12 sticky-actions flex-wrap align-items-center">
    <button class="btn btn-primary btn-lg"><i class="bi bi-send"></i> <?= $id ? 'บันทึกการแก้ไข' : 'ส่งคำขอยืม' ?></button>
    <?php if ($isAdmin && $loan['status'] === 'pending'): ?>
      <div class="form-check ms-2"><input class="form-check-input" type="checkbox" name="approve_now" value="1" id="approveNow">
        <label class="form-check-label" for="approveNow">อนุมัติและส่งมอบทันที (ผู้ให้ยืม: <?= h($me['full_name']) ?>)</label></div>
    <?php endif; ?>
    <a href="<?= $id ? 'loan_view.php?id=' . $id : 'loans.php' ?>" class="btn btn-light btn-lg ms-auto">ยกเลิก</a>
  </div>
</form>
<style>
.eq-pick{max-height:320px;overflow-y:auto}
.eq-pick .grp{position:sticky;top:0;background:#eef1f6;font-size:.85rem;font-weight:600;padding:4px 10px}
.eq-pick .opt{display:flex;gap:10px;align-items:center;padding:6px 10px;border-bottom:1px solid #f1f4f8;cursor:pointer}
.eq-pick .opt:hover{background:#f5f8fc}
.eq-pick .opt.on{background:#e7f1ff}
.eq-pick .opt .bi-check-square-fill{color:var(--brand)}
</style>
<script>
(() => {
  const items = <?= json_encode($available, JSON_UNESCAPED_UNICODE) ?>;
  const picked = new Set(<?= json_encode($selected) ?>);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const box = document.getElementById('eqPick'), list = document.getElementById('pickedList');
  function render() {
    const q = document.getElementById('eqSearch').value.trim().toLowerCase();
    let html = '', grp = null;
    items.filter(e => !q || (e.type + ' ' + e.label + ' ' + e.asset_no + ' ' + e.serial).toLowerCase().includes(q)).forEach(e => {
      if (e.type !== grp) { grp = e.type; html += `<div class="grp">${esc(grp)}</div>`; }
      const on = picked.has(e.id);
      html += `<div class="opt${on ? ' on' : ''}" data-id="${e.id}"><i class="bi ${on ? 'bi-check-square-fill' : 'bi-square'}"></i>
        <span class="min-w-0"><span class="d-block">${esc(e.label)}${e.fy ? ` <span class="text-muted small">ปี ${e.fy}</span>` : ''}</span>
        <span class="d-block small text-muted font-monospace">${esc(e.asset_no || '(ยังไม่มีเลขครุภัณฑ์)')}${e.serial ? ' · S/N ' + esc(e.serial) : ''}</span></span></div>`;
    });
    box.innerHTML = html || '<div class="text-center text-muted py-4">ไม่พบครุภัณฑ์ที่ว่างให้ยืม</div>';
    list.innerHTML = [...picked].map(id => items.find(e => e.id === id)).filter(Boolean).map(e =>
      `<li class="list-group-item d-flex align-items-center gap-2"><input type="hidden" name="equipment_ids[]" value="${e.id}">
        <i class="bi bi-laptop text-primary"></i><span class="min-w-0"><span class="d-block">${esc(e.type)} · ${esc(e.label)}</span>
        <span class="small text-muted font-monospace">${esc(e.asset_no || '(ยังไม่มีเลข)')}</span></span>
        <button type="button" class="btn btn-sm btn-light ms-auto" data-unpick="${e.id}"><i class="bi bi-x-lg"></i></button></li>`).join('');
    document.getElementById('pickCount').textContent = picked.size;
  }
  box.addEventListener('click', ev => { const o = ev.target.closest('.opt'); if (!o) return; const id = +o.dataset.id; picked.has(id) ? picked.delete(id) : picked.add(id); render(); });
  list.addEventListener('click', ev => { const b = ev.target.closest('[data-unpick]'); if (b) { picked.delete(+b.dataset.unpick); render(); } });
  document.getElementById('eqSearch').addEventListener('input', render);
  render();

  const extras = document.getElementById('extras');
  document.getElementById('addExtra').addEventListener('click', () => {
    const row = extras.querySelector('.extra-row').cloneNode(true);
    row.querySelectorAll('input').forEach(i => i.value = i.type === 'number' ? 1 : '');
    extras.appendChild(row);
  });
  extras.addEventListener('click', ev => {
    const b = ev.target.closest('.rm-extra'); if (!b) return;
    const rows = extras.querySelectorAll('.extra-row');
    if (rows.length > 1) b.closest('.extra-row').remove(); else rows[0].querySelectorAll('input').forEach(i => i.value = i.type === 'number' ? 1 : '');
  });
  const sel = document.getElementById('borrowerSel');
  sel?.addEventListener('change', () => { const o = sel.selectedOptions[0]; document.getElementById('bPos').value = o.dataset.position || ''; document.getElementById('bPhone').value = o.dataset.phone || ''; });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
