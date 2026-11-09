<?php
/** บันทึก / แก้ไข รายการรับเข้างบประมาณ (ได้รับ) หรือเบิกจ่ายงบประมาณ — ผู้จัดทำงบประมาณ */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(BUD_MODULE, 'editor');
$id = int_param('id');
$t = null;
if ($id) {
    $st = db()->prepare('SELECT * FROM bud_txns WHERE id = ?');
    $st->execute([$id]);
    if (!$t = $st->fetch()) {
        flash('ไม่พบรายการ', 'danger');
        redirect('index.php');
    }
}
$type = $t['txn_type'] ?? (($_GET['type'] ?? $_POST['type'] ?? '') === 'spend' ? 'spend' : 'receive');
$sources = bud_sources();
$allSources = bud_sources(true);
$works = bud_works(false);
$allWorks = bud_works(true);
$errors = [];
$v = $t ? ['txn_date' => $t['txn_date'], 'fiscal_year' => $t['fiscal_year'], 'doc_no' => $t['doc_no'], 'description' => $t['description'], 'detail' => $t['detail'],
           'work_id' => $t['work_id'] ? (int)$t['work_id'] : null, 'src' => bud_source_key($t), 'amount' => $t['amount'], 'note' => $t['note']]
        : ['txn_date' => date('Y-m-d'), 'fiscal_year' => bud_fy(date('Y-m-d')), 'doc_no' => '', 'description' => '', 'detail' => '',
           'work_id' => int_param('work') ?: null, 'src' => (string)($_GET['src'] ?? ''), 'amount' => '', 'note' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $v = ['txn_date' => (string)post('txn_date'), 'fiscal_year' => (int)post('fiscal_year'), 'doc_no' => trim((string)post('doc_no')),
          'description' => trim((string)post('description')), 'detail' => trim((string)post('detail')),
          'work_id' => int_param('work_id') ?: null, 'src' => (string)post('src'),
          'amount' => str_replace(',', '', trim((string)post('amount'))), 'note' => trim((string)post('note'))];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['txn_date']) || !checkdate((int)substr($v['txn_date'], 5, 2), (int)substr($v['txn_date'], 8, 2), (int)substr($v['txn_date'], 0, 4))) {
        $errors[] = 'กรุณาระบุวันที่';
    }
    if ($v['fiscal_year'] < 2500 || $v['fiscal_year'] > 2700) {
        $errors[] = 'ปีงบประมาณไม่ถูกต้อง (พ.ศ.)';
    }
    if ($v['description'] === '') {
        $errors[] = 'กรุณาระบุรายการ';
    }
    if ($v['work_id'] !== null && !isset($allWorks[$v['work_id']])) {
        $v['work_id'] = null;
    }
    if ($v['work_id'] === null && $works) {
        $errors[] = 'กรุณาเลือกแผนงาน / งานและโครงการ';
    }
    $ids = bud_parse_source($v['src']);
    if (!$ids) {
        $errors[] = 'กรุณาเลือกหมวด';
    }
    if (!is_numeric($v['amount']) || (float)$v['amount'] <= 0 || (float)$v['amount'] >= 1e12) {
        $errors[] = 'จำนวนเงินต้องมากกว่า 0';
    }
    if (!$errors) {
        $data = [$type, $v['txn_date'], $v['fiscal_year'], mb_substr($v['doc_no'], 0, 100), mb_substr($v['description'], 0, 500), mb_substr($v['detail'], 0, 1000),
                 $ids[0], $ids[1], $v['work_id'], round((float)$v['amount'], 2), mb_substr($v['note'], 0, 500), (int)$me['id']];
        if ($t) {
            db()->prepare('UPDATE bud_txns SET txn_type = ?, txn_date = ?, fiscal_year = ?, doc_no = ?, description = ?, detail = ?, project_id = ?, item_id = ?, work_id = ?, amount = ?, note = ?, updated_by = ? WHERE id = ?')
                ->execute(array_merge($data, [$t['id']]));
        } else {
            db()->prepare('INSERT INTO bud_txns (txn_type, txn_date, fiscal_year, doc_no, description, detail, project_id, item_id, work_id, amount, note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute($data);
        }
        audit_log($t ? 'bud_update' : 'bud_create', ($t ? 'แก้ไข' : 'บันทึก') . 'รายการ' . BUD_TYPES[$type] . ': ' . $v['description'] . ' ' . bud_money($v['amount']) . ' บาท', BUD_MODULE);
        flash(($t ? 'แก้ไข' : 'บันทึก') . 'รายการ' . BUD_TYPES[$type] . ' ' . bud_money($v['amount']) . ' บาท แล้ว');
        if (!$t && post('again')) {
            redirect('txn_form.php?' . http_build_query(['type' => $type, 'src' => $v['src'], 'work' => $v['work_id']]));
        }
        redirect('txns.php?' . http_build_query(['type' => $type, 'fy' => $v['fiscal_year']]));
    }
}
if ($v['work_id'] !== null && !isset($works[$v['work_id']]) && isset($allWorks[$v['work_id']])) {
    $works[$v['work_id']] = $allWorks[$v['work_id']];
}
// งานและโครงการที่ใช้ล่าสุดของแต่ละหมวด (เลือกหมวดแล้วเติมให้อัตโนมัติ)
$lastWork = [];
foreach (db()->query('SELECT project_id, item_id, work_id FROM bud_txns WHERE work_id IS NOT NULL ORDER BY id') as $r) {
    $lastWork[bud_source_key($r)] = (int)$r['work_id'];
}
// รายการที่ปิดใช้งานแล้วแต่เป็นค่าเดิมของรายการนี้ ให้เลือกค้างไว้ได้
if ($v['src'] !== '' && !isset($sources[$v['src']]) && isset($allSources[$v['src']])) {
    $sources[$v['src']] = $allSources[$v['src']];
}

$pageTitle = ($t ? 'แก้ไข' : 'บันทึก') . BUD_TYPE_PAGES[$type];
$active = $type;
$pageActions = '<a href="txns.php?type=' . $type . '" class="btn btn-light"><i class="bi bi-arrow-left"></i> กลับ</a>';
$headExtra = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt" crossorigin="anonymous">';
require __DIR__ . '/header.php';
?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i> <?= h($e) ?></div><?php endforeach; ?>
<form method="post" class="card" style="max-width:820px">
  <?= csrf_field() ?><input type="hidden" name="type" value="<?= $type ?>">
  <div class="card-header"><strong class="bud-<?= $type ?>"><i class="bi <?= $type === 'spend' ? 'bi-box-arrow-up' : 'bi-box-arrow-in-down' ?>"></i> <?= h(BUD_TYPE_PAGES[$type]) ?></strong>
    <span class="text-muted small">(แสดงในรายงานเป็น "<?= BUD_TYPES[$type] ?>")</span></div>
  <div class="card-body row g-3">
    <div class="col-md-4"><label class="form-label">วันที่ เดือน ปี <span class="text-danger">*</span></label>
      <input type="text" name="txn_date" id="txnDate" class="form-control thai-date" value="<?= h($v['txn_date']) ?>" required></div>
    <div class="col-md-3"><label class="form-label">ปีงบประมาณ <span class="text-danger">*</span></label>
      <input type="number" name="fiscal_year" id="txnFy" class="form-control" value="<?= h((string)$v['fiscal_year']) ?>" min="2500" max="2700" required>
      <div class="form-text">คำนวณจากวันที่อัตโนมัติ (1 ต.ค. – 30 ก.ย.)</div></div>
    <div class="col-md-5"><label class="form-label">ที่เอกสาร (เลขที่เอกสาร)</label>
      <input type="text" name="doc_no" class="form-control" value="<?= h($v['doc_no']) ?>" maxlength="100" placeholder="เช่น นศ 0412/123"></div>
    <div class="col-md-6"><label class="form-label">แผนงาน / งานและโครงการ<?= $works ? ' <span class="text-danger">*</span>' : '' ?></label>
      <select name="work_id" id="txnWork" class="form-select"<?= $works ? ' required' : '' ?>><?= bud_work_options($works, $v['work_id']) ?></select>
      <?php if (!$works): ?><div class="form-text text-warning">ยังไม่มีแผนงาน / งานและโครงการ<?= bud_can('admin') ? ' — <a href="items.php">เพิ่มที่เมนูแผนงานและหมวด</a>' : ' (ผู้ดูแลระบบงบประมาณเพิ่มที่เมนูแผนงานและหมวด)' ?></div><?php endif; ?></div>
    <div class="col-md-6"><label class="form-label">หมวด <span class="text-danger">*</span></label>
      <select name="src" id="txnSrc" class="form-select" required><option value="">— เลือก —</option>
        <?php $g = null; foreach ($sources as $k => $s): if ($s['group'] !== $g): ?><?= $g !== null ? '</optgroup>' : '' ?><optgroup label="<?= h($s['group']) ?>"><?php $g = $s['group']; endif; ?>
          <option value="<?= h($k) ?>"<?= $k === $v['src'] ? ' selected' : '' ?>><?= h($s['label']) ?></option>
        <?php endforeach; ?><?= $g !== null ? '</optgroup>' : '' ?>
      </select></div>
    <div class="col-12"><label class="form-label">รายการ <span class="text-danger">*</span></label>
      <input type="text" name="description" class="form-control" value="<?= h($v['description']) ?>" maxlength="500" required></div>
    <div class="col-12"><label class="form-label">รายละเอียด</label>
      <textarea name="detail" class="form-control" rows="2" maxlength="1000" placeholder="รายละเอียดเพิ่มเติมของรายการ (แสดงต่อจากรายการในทะเบียนคุมเงิน)"><?= h($v['detail']) ?></textarea></div>
    <div class="col-md-4"><label class="form-label"><?= $type === 'spend' ? 'จำนวนเงินเบิกจ่าย' : 'จำนวนเงินที่ได้รับ' ?> (บาท) <span class="text-danger">*</span></label>
      <input type="text" name="amount" class="form-control text-end" value="<?= h($v['amount'] !== '' ? bud_money($v['amount']) : '') ?>" inputmode="decimal" required></div>
    <div class="col-12"><label class="form-label">หมายเหตุ</label>
      <input type="text" name="note" class="form-control" value="<?= h($v['note']) ?>" maxlength="500"></div>
  </div>
  <div class="card-footer d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> บันทึก</button>
    <?php if (!$t): ?><button name="again" value="1" class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i> บันทึกและเพิ่มรายการต่อ</button><?php endif; ?>
    <a href="txns.php?type=<?= $type ?>" class="btn btn-light ms-auto">ยกเลิก</a>
  </div>
</form>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/th.js" integrity="sha384-E6OVFg1zUc/pckGxry0YZ6VeZ1E17l7Jl8ZQx58jbEfxxkz9LqknQhiIrA9zg/8b" crossorigin="anonymous"></script>
<script src="<?= h(asset_v('modules/calendar/assets/js/thai-picker.js')) ?>"></script>
<script>
// ปีงบประมาณตามวันที่: ต.ค.–ธ.ค. = ปี พ.ศ. + 1
(() => {
  const d = document.getElementById('txnDate'), fy = document.getElementById('txnFy');
  const calc = () => { const m = /^(\d{4})-(\d{2})/.exec(d.value); if (m) fy.value = +m[1] + 543 + (+m[2] >= 10 ? 1 : 0); };
  d.addEventListener('change', calc);
  // เลือกหมวด → เติมงานและโครงการที่ใช้ล่าสุดของหมวดนั้น (ถ้ายังไม่ได้เลือก)
  const last = <?= json_encode($lastWork) ?>, src = document.getElementById('txnSrc'), work = document.getElementById('txnWork');
  src.addEventListener('change', () => { if (!work.value && last[src.value] && work.querySelector('option[value="' + last[src.value] + '"]')) work.value = last[src.value]; });
  const amt = document.querySelector('input[name=amount]');
  amt.addEventListener('blur', () => { const n = parseFloat(amt.value.replace(/,/g, '')); if (!isNaN(n)) amt.value = n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
