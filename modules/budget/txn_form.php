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
        redirect('./');
    }
}
$type = $t['txn_type'] ?? (($_GET['type'] ?? $_POST['type'] ?? '') === 'spend' ? 'spend' : 'receive');
$sources = array_filter(bud_sources(), fn($s) => empty($s['legacy']));   // เลือกได้เฉพาะหมวด
$allSources = bud_sources(true);
$works = bud_works(false);
$allWorks = bud_works(true);
$plans = array_filter(bud_plans(), fn($p) => $p['is_active']);
$allPlans = bud_plans();
$errors = [];
$v = $t ? ['txn_date' => $t['txn_date'], 'fiscal_year' => $t['fiscal_year'], 'doc_no' => $t['doc_no'], 'description' => $t['description'], 'detail' => $t['detail'],
           'plan_id' => bud_txn_plan($t) ?: null, 'work_id' => $t['work_id'] ? (int)$t['work_id'] : null, 'src' => bud_source_key($t), 'amount' => $t['amount'], 'note' => $t['note']]
        : ['txn_date' => date('Y-m-d'), 'fiscal_year' => bud_fy(date('Y-m-d')), 'doc_no' => '', 'description' => '', 'detail' => '',
           'plan_id' => int_param('plan') ?: null, 'work_id' => int_param('work') ?: null, 'src' => (string)($_GET['src'] ?? ''), 'amount' => '', 'note' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $v = ['txn_date' => (string)post('txn_date'), 'fiscal_year' => (int)post('fiscal_year'), 'doc_no' => trim((string)post('doc_no')),
          'description' => trim((string)post('description')), 'detail' => trim((string)post('detail')),
          'plan_id' => int_param('plan_id') ?: null, 'work_id' => int_param('work_id') ?: null, 'src' => (string)post('src'),
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
    // แผนงาน (บังคับเมื่อมีแผนงาน) · งานและโครงการ ไม่บังคับ แต่ต้องอยู่ภายใต้แผนงานที่เลือก
    if ($v['plan_id'] !== null && !isset($allPlans[$v['plan_id']])) {
        $v['plan_id'] = null;
    }
    if ($v['work_id'] !== null && !isset($allWorks[$v['work_id']])) {
        $v['work_id'] = null;
    }
    if ($v['work_id'] !== null) {
        $v['plan_id'] ??= (int)$allWorks[$v['work_id']]['plan_id'];
        if ((int)$allWorks[$v['work_id']]['plan_id'] !== $v['plan_id']) {
            $errors[] = 'งานและโครงการที่เลือกไม่ได้อยู่ภายใต้แผนงานนี้';
        }
    }
    if ($v['plan_id'] === null && $plans) {
        $errors[] = 'กรุณาเลือกแผนงาน';
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
                 $ids[0], $ids[1], $v['plan_id'], $v['work_id'], round((float)$v['amount'], 2), mb_substr($v['note'], 0, 500), (int)$me['id']];
        if ($t) {
            db()->prepare('UPDATE bud_txns SET txn_type = ?, txn_date = ?, fiscal_year = ?, doc_no = ?, description = ?, detail = ?, project_id = ?, item_id = ?, plan_id = ?, work_id = ?, amount = ?, note = ?, updated_by = ? WHERE id = ?')
                ->execute(array_merge($data, [$t['id']]));
        } else {
            db()->prepare('INSERT INTO bud_txns (txn_type, txn_date, fiscal_year, doc_no, description, detail, project_id, item_id, plan_id, work_id, amount, note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute($data);
        }
        audit_log($t ? 'bud_update' : 'bud_create', ($t ? 'แก้ไข' : 'บันทึก') . 'รายการ' . BUD_TYPES[$type] . ': ' . $v['description'] . ' ' . bud_money($v['amount']) . ' บาท', BUD_MODULE);
        flash(($t ? 'แก้ไข' : 'บันทึก') . 'รายการ' . BUD_TYPES[$type] . ' ' . bud_money($v['amount']) . ' บาท แล้ว');
        if (!$t && post('again')) {
            redirect('txn_form?' . http_build_query(['type' => $type, 'src' => $v['src'], 'plan' => $v['plan_id'], 'work' => $v['work_id']]));
        }
        redirect('txns?' . http_build_query(['type' => $type, 'fy' => $v['fiscal_year']]));
    }
}
if ($v['work_id'] !== null && !isset($works[$v['work_id']]) && isset($allWorks[$v['work_id']])) {
    $works[$v['work_id']] = $allWorks[$v['work_id']];
}
if ($v['plan_id'] !== null && !isset($plans[$v['plan_id']]) && isset($allPlans[$v['plan_id']])) {
    $plans[$v['plan_id']] = $allPlans[$v['plan_id']];
}
// แผนงาน / งานและโครงการที่ใช้ล่าสุดของแต่ละหมวด (เลือกหมวดแล้วเติมให้อัตโนมัติ)
$lastWork = [];
foreach (db()->query('SELECT project_id, item_id, plan_id, work_id FROM bud_txns WHERE plan_id IS NOT NULL OR work_id IS NOT NULL ORDER BY id') as $r) {
    $lastWork[bud_source_key($r)] = [bud_txn_plan($r), (int)$r['work_id']];
}
// รายการที่ปิดใช้งานแล้วแต่เป็นค่าเดิมของรายการนี้ ให้เลือกค้างไว้ได้
if ($v['src'] !== '' && !isset($sources[$v['src']]) && isset($allSources[$v['src']])) {
    $sources[$v['src']] = $allSources[$v['src']];
}
// หมวดสำหรับตัวเลือก 3 ชั้น เรียงตามประเภทงบ (เหมือนรายงาน) แล้วตามลำดับหมวด
$catItems = [];
foreach ($sources as $k => $x) {
    if (!empty($x['legacy'])) {
        continue;
    }
    $catItems[] = ['k' => $k, 'b' => bud_budget_type($x), 'e' => $x['expense'] ?? '',
                   'a' => ($x['budget'] ?? '') !== '' ? $x['activity'] : $x['label'], 'n' => $x['label'], 'r' => bud_budget_rank($x)];
}
usort($catItems, fn($p, $q) => $p['r'] <=> $q['r']);   // usort คงลำดับเดิมเมื่อเท่ากัน (PHP 8)

$pageTitle = ($t ? 'แก้ไข' : 'บันทึก') . BUD_TYPE_PAGES[$type];
$active = $type;
$pageActions = '<a href="txns?type=' . $type . '" class="btn btn-light"><i class="bi bi-arrow-left"></i> กลับ</a>';
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
    <div class="col-md-6"><label class="form-label">แผนงาน<?= $plans ? ' <span class="text-danger">*</span>' : '' ?></label>
      <select name="plan_id" id="txnPlan" class="form-select"<?= $plans ? ' required' : '' ?>><option value="">— เลือก —</option>
        <?php foreach ($plans as $pid => $p): ?><option value="<?= $pid ?>"<?= $pid === $v['plan_id'] ? ' selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?></select>
      <?php if (!$plans): ?><div class="form-text text-warning">ยังไม่มีแผนงาน<?= bud_can('admin') ? ' — <a href="items">เพิ่มที่เมนูแผนงานและหมวด</a>' : ' (ผู้ดูแลระบบงบประมาณเพิ่มที่เมนูแผนงานและหมวด)' ?></div><?php endif; ?></div>
    <div class="col-md-6"><label class="form-label">งานและโครงการ <span class="text-muted small">(ถ้ามี)</span></label>
      <select name="work_id" id="txnWork" class="form-select"><option value="">— ไม่ระบุ —</option>
        <?php foreach ($works as $wid => $w): ?><option value="<?= $wid ?>" data-plan="<?= (int)$w['plan_id'] ?>"<?= $wid === $v['work_id'] ? ' selected' : '' ?>><?= h($w['name']) ?></option><?php endforeach; ?></select></div>
    <!-- หมวด = งบรายจ่าย → รายการ → ค่าใช้จ่าย (เลือกครบแล้วได้หมวดเดียว ส่งเป็น src) -->
    <input type="hidden" name="src" id="txnSrc" value="<?= h($v['src']) ?>">
    <div class="col-md-4"><label class="form-label">งบรายจ่าย <span class="text-danger">*</span></label>
      <select id="bBudget" class="form-select" required></select></div>
    <div class="col-md-4"><label class="form-label">รายการ</label>
      <select id="bExpense" class="form-select"></select></div>
    <div class="col-md-4"><label class="form-label">ค่าใช้จ่าย</label>
      <select id="bActivity" class="form-select"></select></div>
    <div class="col-12 small mt-1" id="bPreview"><?php if (str_starts_with($v['src'], 'p:') && isset($allSources[$v['src']])): ?><span class="text-warning">หมวดเดิม: <?= h($allSources[$v['src']]['label']) ?> — เลือกใหม่เพื่อเปลี่ยน</span><?php endif; ?></div>
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
    <a href="txns?type=<?= $type ?>" class="btn btn-light ms-auto">ยกเลิก</a>
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
  // งานและโครงการ: แสดงเฉพาะของแผนงานที่เลือก
  const plan = document.getElementById('txnPlan'), work = document.getElementById('txnWork'), src = document.getElementById('txnSrc');
  // หมวด: งบรายจ่าย → รายการ → ค่าใช้จ่าย (ค่าที่ว่างแสดงเป็น "— ไม่มี —") · ค่าใน select นำหน้าด้วย "=" แยกจาก "ยังไม่เลือก"
  const items = <?= json_encode($catItems, JSON_UNESCAPED_UNICODE) ?>;
  const selB = document.getElementById('bBudget'), selE = document.getElementById('bExpense'), selA = document.getElementById('bActivity'), preview = document.getElementById('bPreview');
  const val = sel => sel.value === '' ? null : sel.value.slice(1);
  const fill = (sel, values, keep) => {
    const cur = keep ? sel.value : '';
    sel.innerHTML = '';
    const ph = new Option(values.length ? '— เลือก —' : '—', ''); sel.add(ph);
    values.forEach(v => sel.add(new Option(v === '' ? '— ไม่มี —' : v, '=' + v)));
    if (values.length === 1) sel.value = '=' + values[0];
    else if (cur && values.includes(cur.slice(1))) sel.value = cur;
    sel.disabled = !values.length;
  };
  const uniq = arr => [...new Set(arr)];
  const pick = () => items.filter(x => (val(selB) === null || x.b === val(selB)) && (val(selE) === null || x.e === val(selE)) && (val(selA) === null || x.a === val(selA)));
  const resolve = () => {
    const m = val(selB) !== null && val(selE) !== null && val(selA) !== null ? pick() : [];
    const before = src.value;
    if (m.length === 1) { src.value = m[0].k; preview.innerHTML = ''; preview.append('หมวด: ', Object.assign(document.createElement('b'), { textContent: m[0].n })); }
    else if (!src.value.startsWith('p:') || val(selB) !== null) { src.value = ''; preview.textContent = ''; }
    if (src.value !== before) src.dispatchEvent(new Event('change'));
  };
  const onB = () => { fill(selE, val(selB) === null ? [] : uniq(items.filter(x => x.b === val(selB)).map(x => x.e))); onE(); };
  const onE = () => { fill(selA, val(selE) === null ? [] : uniq(items.filter(x => x.b === val(selB) && x.e === val(selE)).map(x => x.a))); resolve(); };
  selB.addEventListener('change', onB); selE.addEventListener('change', onE); selA.addEventListener('change', resolve);
  fill(selB, uniq(items.map(x => x.b)));
  const init = items.find(x => x.k === src.value);
  if (init) { selB.value = '=' + init.b; onB(); selE.value = '=' + init.e; onE(); selA.value = '=' + init.a; resolve(); } else { onB(); }
  document.querySelector('form.card').addEventListener('submit', e => {
    if (!src.value) { e.preventDefault(); (selB.value ? (selE.value ? selA : selE) : selB).focus(); alert('กรุณาเลือกหมวด (งบรายจ่าย / รายการ / ค่าใช้จ่าย) ให้ครบ'); }
  });
  const filterWork = () => {
    work.querySelectorAll('option[data-plan]').forEach(o => { o.hidden = o.disabled = o.dataset.plan !== plan.value; });
    if (work.selectedOptions[0] && work.selectedOptions[0].disabled) work.value = '';
  };
  plan.addEventListener('change', filterWork);
  filterWork();
  // เลือกหมวด → เติมแผนงาน / งานและโครงการที่ใช้ล่าสุดของหมวดนั้น (ถ้ายังไม่ได้เลือกแผนงาน)
  const last = <?= json_encode($lastWork) ?>;
  src.addEventListener('change', () => {
    const l = last[src.value];
    if (plan.value || !l || !plan.querySelector('option[value="' + l[0] + '"]')) return;
    plan.value = l[0]; filterWork();
    if (l[1] && work.querySelector('option[value="' + l[1] + '"]:not([disabled])')) work.value = l[1];
  });
  const amt = document.querySelector('input[name=amount]');
  amt.addEventListener('blur', () => { const n = parseFloat(amt.value.replace(/,/g, '')); if (!isNaN(n)) amt.value = n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
