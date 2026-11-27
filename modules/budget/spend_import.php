<?php
/**
 * นำเข้ารายการเบิกจ่ายจากใบเบิก GFMIS (PDF "รายงานการขอเบิกเงินคงคลัง" เช่น ขบ02) — ผู้จัดทำงบประมาณ
 *   1) เลือกไฟล์ PDF (หลายไฟล์ได้) → เบราว์เซอร์อ่านข้อความด้วย pdf.js จัดเป็นบรรทัด → ส่งมาแยกข้อมูล (gfmis_lib.php)
 *   2) ตรวจสอบ: วันที่ (วันที่เอกสาร) · ที่เอกสาร (การอ้างอิง เช่น P700000005) · รายการ (ข้อความในรายการ) · หมวด/แผนงานที่แนะนำ · จำนวนเงิน
 *      ที่เอกสาร + จำนวนเงินซ้ำกับที่บันทึกแล้ว / ซ้ำกันเองในไฟล์ที่เลือก = ไม่เลือกให้ (กันนำเข้าซ้ำ)
 *   3) บันทึกเป็นรายการเบิกจ่ายงบประมาณ
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/gfmis_lib.php';
$me = require_module(BUD_MODULE, 'editor');
$pdo = db();
$sessKey = 'bud_spend_import';
$sources = array_filter(bud_sources(), fn($s) => empty($s['legacy']));
$plans = array_filter(bud_plans(), fn($p) => $p['is_active']);
$works = bud_works(false);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'preview') {
        $files = json_decode((string)post('files'), true);
        $rows = [];
        if (!is_array($files) || !$files) {
            $errors[] = 'กรุณาเลือกไฟล์ใบเบิก (PDF)';
        } else {
            $lastWork = [];                                   // แผนงาน/งานล่าสุดของแต่ละหมวด (เหมือนหน้าบันทึก)
            foreach ($pdo->query('SELECT project_id, item_id, plan_id, work_id FROM bud_txns WHERE plan_id IS NOT NULL OR work_id IS NOT NULL ORDER BY id') as $r) {
                $lastWork[bud_source_key($r)] = [bud_txn_plan($r), (int)$r['work_id']];
            }
            $dup = $pdo->prepare("SELECT id FROM bud_txns WHERE txn_type = 'spend' AND doc_no = ? AND amount = ? LIMIT 1");
            $seen = [];                                       // ซ้ำกันเองในไฟล์ที่เลือก: เลขที่เอกสาร GFMIS + บรรทัด + จำนวนเงิน → ไฟล์แรกที่พบ
            foreach (array_slice($files, 0, 100) as $f) {
                $name = mb_substr((string)($f['name'] ?? ''), 0, 200);
                $p = gfmis_parse(is_array($f['lines'] ?? null) ? $f['lines'] : []);
                if ($p['errors']) {
                    $errors[] = $name . ': ' . implode(' · ', $p['errors']);
                    continue;
                }
                foreach ($p['debits'] as $d) {
                    $desc = $d['text'] !== '' ? $d['text'] : $d['account_name'];
                    $sug = gfmis_suggest($d, $desc, $sources);
                    if ($sug['src'] !== '' && !$sug['plan_id'] && isset($lastWork[$sug['src']])) {
                        [$sug['plan_id'], $sug['work_id']] = $lastWork[$sug['src']];
                    }
                    if (!$sug['plan_id'] && count($plans) === 1) {
                        $sug['plan_id'] = (int)array_key_first($plans);
                    }
                    $docNo = $p['ref'] !== '' ? $p['ref'] : $p['doc_no'];     // ที่เอกสาร = การอ้างอิง (เช่น P700000005) · ไม่มี = เลขที่เอกสาร GFMIS
                    $dup->execute([$docNo, $d['amount']]);
                    $isDup = (bool)$dup->fetchColumn();
                    $fk = $p['doc_year'] . '-' . $p['doc_no'] . '|' . $d['line'] . '|' . $d['amount'];
                    $dupFile = $seen[$fk] ?? null;            // ชื่อไฟล์แรกที่มีรายการเดียวกัน
                    $seen[$fk] ??= $name;
                    $rows[] = ['file' => $name, 'doc_no' => $docNo, 'gfmis_no' => $p['doc_no'], 'doc_year' => $p['doc_year'], 'date' => $p['date'], 'post_date' => $p['post_date'],
                               'status' => $p['status'], 'form' => $p['form'], 'account' => $d['account'] . ' ' . $d['account_name'],
                               'source' => $d['source'], 'budget_name' => $d['budget_name'], 'activity' => $d['activity'],
                               'description' => $desc, 'amount' => $d['amount'], 'src' => $sug['src'], 'how' => $sug['how'],
                               'plan_id' => $sug['plan_id'] ?: null, 'work_id' => $sug['work_id'] ?: null, 'dup' => $isDup, 'dup_file' => $dupFile,
                               'pick' => !$isDup && $dupFile === null];
                }
            }
        }
        if ($rows) {
            $_SESSION[$sessKey] = $rows;
            redirect('spend_import?step=preview' . ($errors ? '&warn=' . urlencode(implode("\n", array_slice($errors, 0, 10))) : ''));
        }
    } elseif ($action === 'import' && !empty($_SESSION[$sessKey])) {
        $rows = $_SESSION[$sessKey];
        $ins = $pdo->prepare('INSERT INTO bud_txns (txn_type, txn_date, fiscal_year, doc_no, description, detail, project_id, item_id, plan_id, work_id, amount, note, created_by)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $n = 0;
        $sum = 0.0;
        $bad = [];
        $skipped = [];
        $done = [];
        $dupDb = $pdo->prepare("SELECT id FROM bud_txns WHERE txn_type = 'spend' AND doc_no = ? AND amount = ? LIMIT 1");
        $pdo->beginTransaction();
        foreach ($rows as $i => $r) {
            if (empty($_POST['pick'][$i])) {
                continue;
            }
            $desc = trim((string)($_POST['desc'][$i] ?? $r['description']));
            $ids = bud_parse_source((string)($_POST['src'][$i] ?? ''));
            $plan = (int)($_POST['plan'][$i] ?? 0) ?: null;
            $work = (int)($_POST['work'][$i] ?? 0) ?: null;
            if ($work && (!isset($works[$work]) || ($plan && (int)$works[$work]['plan_id'] !== $plan))) {
                $work = null;
            }
            if ($work && !$plan) {
                $plan = (int)$works[$work]['plan_id'];
            }
            if ($desc === '' || !$ids || !$r['date'] || !$r['amount'] || ($plans && (!$plan || !isset($plans[$plan])))) {
                $bad[] = 'แถว ' . ($i + 1) . ' (' . $r['doc_no'] . ')';
                continue;
            }
            $dk = $r['doc_no'] . '|' . round((float)$r['amount'], 2);
            $dupDb->execute([$r['doc_no'], $r['amount']]);
            if (isset($done[$dk]) || $dupDb->fetchColumn()) {   // ซ้ำกับที่บันทึกแล้ว / ซ้ำกันเองในรอบนี้ → ข้าม
                $skipped[] = $r['doc_no'];
                continue;
            }
            $done[$dk] = true;
            $note = 'GFMIS ' . trim(explode(' - ', $r['form'])[0]) . ' เลขที่ ' . $r['doc_year'] . '-' . $r['gfmis_no']
                . ($r['post_date'] ? ' · ผ่านรายการ ' . thai_date($r['post_date']) : '') . ($r['status'] ? ' · ' . $r['status'] : '');
            $ins->execute(['spend', $r['date'], bud_fy($r['date']), $r['doc_no'], mb_substr($desc, 0, 500), '', $ids[0], $ids[1], $plan, $work,
                           round((float)$r['amount'], 2), mb_substr($note, 0, 500), (int)$me['id']]);
            $n++;
            $sum += (float)$r['amount'];
        }
        if ($bad) {
            $pdo->rollBack();
            foreach ($rows as $i => &$r) {                    // คงค่าที่เลือก/แก้ไว้ เมื่อบันทึกไม่ผ่าน
                $r['pick'] = !empty($_POST['pick'][$i]);
                $r['description'] = trim((string)($_POST['desc'][$i] ?? $r['description']));
                $r['src'] = (string)($_POST['src'][$i] ?? $r['src']);
                $r['plan_id'] = (int)($_POST['plan'][$i] ?? 0) ?: null;
                $r['work_id'] = (int)($_POST['work'][$i] ?? 0) ?: null;
            }
            unset($r);
            $_SESSION[$sessKey] = $rows;
            $errors[] = 'ยังไม่ได้บันทึก — กรุณาเลือกหมวด/แผนงาน และตรวจรายการ: ' . implode(', ', $bad);
        } else {
            $pdo->commit();
            unset($_SESSION[$sessKey]);
            audit_log('bud_spend_import', 'นำเข้าเบิกจ่ายจากใบเบิก GFMIS ' . $n . ' รายการ ' . bud_money($sum) . ' บาท', BUD_MODULE);
            flash(($n ? 'นำเข้ารายการเบิกจ่าย ' . $n . ' รายการ รวม ' . bud_money($sum) . ' บาท แล้ว' : 'ไม่มีรายการที่บันทึก')
                . ($skipped ? ' · ข้ามรายการซ้ำ ' . count($skipped) . ' รายการ (' . implode(', ', array_unique($skipped)) . ')' : ''), $n ? 'success' : 'warning');
            redirect('txns?type=spend');
        }
    } elseif ($action === 'cancel') {
        unset($_SESSION[$sessKey]);
        redirect('spend_import');
    }
}

$step = ($_GET['step'] ?? '') === 'preview' && !empty($_SESSION[$sessKey]) ? 'preview' : 'upload';
$rows = $_SESSION[$sessKey] ?? [];
if (!empty($_GET['warn'])) {
    $errors = array_merge($errors, explode("\n", (string)$_GET['warn']));
}
$active = 'spend';
$pageTitle = 'นำเข้าเบิกจ่ายจากใบเบิก (GFMIS)';
$pageActions = '<a href="txns?type=spend" class="btn btn-light"><i class="bi bi-arrow-left"></i> เบิกจ่ายงบประมาณ</a>';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-warning"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<?php if ($step === 'upload'): ?>
<div class="row g-3" data-reveal>
  <div class="col-lg-6 reveal-item">
    <form method="post" class="card" id="giForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="preview"><input type="hidden" name="files" id="giFiles">
      <div class="card-header"><strong><i class="bi bi-file-earmark-pdf text-danger"></i> ไฟล์ใบเบิกจาก GFMIS (PDF)</strong></div>
      <div class="card-body">
        <input type="file" id="giPdf" class="form-control mb-3" accept="application/pdf,.pdf" multiple required>
        <button class="btn btn-primary" id="giBtn"><i class="bi bi-eye"></i> ตรวจสอบข้อมูลก่อนนำเข้า</button>
        <div class="small text-muted mt-2" id="giStatus">เลือกได้หลายไฟล์พร้อมกัน (ไม่เกิน 100 ไฟล์)</div>
      </div>
    </form>
  </div>
  <div class="col-lg-6 reveal-item" style="--d:70ms"><div class="card"><div class="card-body small">
    <strong>รูปแบบไฟล์</strong> — "รายงานการขอเบิกเงินคงคลัง" ที่พิมพ์จาก GFMIS เป็น PDF (เช่น ขบ02 - ขอเบิกเงินงบประมาณที่ไม่อ้างใบสั่งซื้อฯ)
    <ul class="mt-2 mb-0">
      <li>วันที่ = <b>วันที่เอกสาร</b> · ที่เอกสาร = <b>การอ้างอิง</b> (เช่น P700000005) · จำนวนเงิน = จำนวนเงินที่ขอเบิกของบรรทัด <b>เดบิต</b></li>
      <li>รายการ = <b>ข้อความในรายการ</b> (เช่น ค่าตอบแทนพนักงานราชการ เดือนตุลาคม 2569) — แก้ไขได้ก่อนบันทึก</li>
      <li>หมวด/แผนงาน: ระบบแนะนำจากรายการเดิมที่ชื่อเหมือนกัน หรือชื่อค่าใช้จ่ายของหมวด — ตรวจและเลือกให้ครบ</li>
      <li>กันนำเข้าซ้ำ: ที่เอกสาร (การอ้างอิง) และจำนวนเงินซ้ำกับที่บันทึกไว้แล้ว (<b>บันทึกแล้ว</b>) หรือซ้ำกันเองในไฟล์ที่เลือก เช่น เลือกไฟล์เดียวกันสองครั้ง (<b>ซ้ำในไฟล์ที่เลือก</b>) จะไม่ถูกเลือกให้ และตอนบันทึกระบบข้ามรายการซ้ำให้อีกชั้น</li>
      <li>ข้อมูลอื่น (เลขที่เอกสาร GFMIS วันที่ผ่านรายการ สถานะ) บันทึกไว้ในหมายเหตุ</li>
    </ul>
  </div></div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js" integrity="sha384-/1qUCSGwTur9vjf/z9lmu/eCUYbpOTgSjmpbMQZ1/CtX2v/WcAIKqRv+U1DUCG6e" crossorigin="anonymous"></script>
<script>
(() => {
  // จัดข้อความจาก pdf.js เป็นบรรทัด (หน้าแนวนอนของ GFMIS ข้อความหมุน 90° → ใช้แกน x เป็นบรรทัด)
  const groupLines = items => {
    const pts = items.filter(it => it.str && it.str.trim() !== '').map(it => {
      const t = it.transform, rot = Math.abs(t[1]) > Math.abs(t[0]);
      return { s: it.str, line: rot ? t[4] : -t[5], pos: rot ? t[5] : t[4] };
    }).sort((a, b) => a.line - b.line || a.pos - b.pos);
    const lines = [];
    for (const p of pts) {
      const L = lines[lines.length - 1];
      if (L && Math.abs(p.line - L.line) <= 3) L.items.push(p); else lines.push({ line: p.line, items: [p] });
    }
    return lines.map(L => L.items.sort((a, b) => a.pos - b.pos).map(p => p.s));
  };
  const form = document.getElementById('giForm'), status = document.getElementById('giStatus'), btn = document.getElementById('giBtn');
  form.addEventListener('submit', async e => {
    if (document.getElementById('giFiles').value) { return; }
    e.preventDefault();
    const files = [...document.getElementById('giPdf').files].slice(0, 100);
    if (!files.length) { return; }
    if (!window.pdfjsLib) { status.textContent = 'โหลดตัวอ่าน PDF ไม่ได้ — ตรวจการเชื่อมต่ออินเทอร์เน็ต'; return; }
    btn.disabled = true;
    try {
      pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
      const out = [];
      for (const [n, file] of files.entries()) {
        status.textContent = 'กำลังอ่าน ' + file.name + ' (' + (n + 1) + ' / ' + files.length + ') …';
        const doc = await pdfjsLib.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
        let lines = [];
        for (let i = 1; i <= doc.numPages; i++) lines = lines.concat(groupLines((await (await doc.getPage(i)).getTextContent()).items));
        out.push({ name: file.name, lines });
      }
      document.getElementById('giFiles').value = JSON.stringify(out);
      status.textContent = 'อ่านแล้ว ' + out.length + ' ไฟล์ — กำลังตรวจสอบ…';
      form.submit();
    } catch (err) {
      status.textContent = 'อ่านไฟล์ไม่ได้: ' + err.message;
      btn.disabled = false;
    }
  });
})();
</script>
<?php else: $total = array_sum(array_map(fn($r) => ($r['pick'] ?? !$r['dup']) ? (float)$r['amount'] : 0, $rows)); ?>
<form method="post">
  <?= csrf_field() ?>
  <div class="card mb-3">
    <div class="card-header d-flex flex-wrap align-items-center gap-2"><strong><i class="bi bi-list-check"></i> ตรวจสอบรายการเบิกจ่าย (<?= count($rows) ?> รายการ)</strong>
      <span class="small text-muted">เลือกหมวด/แผนงานให้ครบ แก้ "รายการ" ได้ — ชี้ที่ที่เอกสารเพื่อดูรายละเอียดจากใบเบิก</span></div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th class="text-center"><input type="checkbox" class="form-check-input" id="giAll" checked aria-label="เลือกทั้งหมด"></th><th>วันที่</th><th>ที่เอกสาร</th>
          <th style="min-width:260px">รายการ</th><th style="min-width:260px">หมวด</th><th style="min-width:220px">แผนงาน / งานและโครงการ</th><th class="text-end">จำนวนเงิน (บาท)</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
          <tr class="<?= $r['dup'] || !empty($r['dup_file']) ? 'table-secondary' : '' ?>">
            <td class="text-center"><input type="checkbox" class="form-check-input gi-pick" name="pick[<?= $i ?>]" value="1" <?= ($r['pick'] ?? !$r['dup']) ? 'checked' : '' ?> aria-label="เลือก"></td>
            <td class="text-nowrap"><?= $r['date'] ? h(thai_date($r['date'])) : '<span class="text-danger">-</span>' ?></td>
            <td class="text-nowrap" title="<?= h(implode("\n", array_filter([$r['file'], $r['form'], 'บัญชี: ' . $r['account'], 'แหล่งเงิน: ' . $r['source'], $r['budget_name'], $r['activity'], 'สถานะ: ' . $r['status']]))) ?>">
              <?= h($r['doc_no']) ?><?= $r['dup'] ? '<div><span class="badge text-bg-secondary">บันทึกแล้ว</span></div>' : '' ?>
              <?= !empty($r['dup_file']) ? '<div><span class="badge text-bg-warning" title="รายการเดียวกับไฟล์ ' . h($r['dup_file']) . '">ซ้ำในไฟล์ที่เลือก</span></div>' : '' ?>
              <div class="small text-muted">GFMIS <?= h($r['gfmis_no'] ?? '') ?> · <?= h($r['status']) ?></div></td>
            <td><input name="desc[<?= $i ?>]" class="form-control form-control-sm" maxlength="500" value="<?= h($r['description']) ?>">
              <div class="small text-muted text-truncate" style="max-width:360px"><?= h($r['account']) ?></div></td>
            <td><select name="src[<?= $i ?>]" class="form-select form-select-sm<?= $r['src'] ? '' : ' is-invalid' ?>"><option value="">— เลือกหมวด —</option>
              <?php foreach ($sources as $k => $s): ?><option value="<?= h($k) ?>" <?= $k === $r['src'] ? 'selected' : '' ?>><?= h($s['label']) ?></option><?php endforeach; ?></select>
              <?php if ($r['how']): ?><div class="small text-success"><i class="bi bi-magic"></i> <?= $r['how'] === 'history' ? 'ตามรายการเดิมที่ชื่อเหมือนกัน' : 'จากชื่อค่าใช้จ่าย' ?></div><?php endif; ?></td>
            <td><?php if ($plans): ?><select name="plan[<?= $i ?>]" class="form-select form-select-sm gi-plan<?= $r['plan_id'] ? '' : ' is-invalid' ?>"><option value="">— เลือกแผนงาน —</option>
              <?php foreach ($plans as $pid => $p): ?><option value="<?= $pid ?>" <?= (int)$r['plan_id'] === $pid ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
              <select name="work[<?= $i ?>]" class="form-select form-select-sm mt-1 gi-work"><option value="">— งานและโครงการ (ไม่ระบุ) —</option>
              <?php foreach ($works as $wid => $w): ?><option value="<?= $wid ?>" data-plan="<?= (int)$w['plan_id'] ?>" <?= (int)$r['work_id'] === $wid ? 'selected' : '' ?>><?= h($w['name']) ?></option><?php endforeach; ?></select></td>
            <td class="text-end text-nowrap fw-semibold"><?= bud_money($r['amount']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="6" class="text-end">รวมรายการที่เลือก</th><th class="text-end" id="giSum"><?= bud_money($total) ?></th></tr></tfoot>
      </table>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2">
      <button name="action" value="cancel" class="btn btn-light" formnovalidate>ยกเลิก</button>
      <button name="action" value="import" class="btn btn-warning ms-auto"><i class="bi bi-check2-circle"></i> บันทึกเป็นรายการเบิกจ่าย</button>
    </div>
  </div>
</form>
<script>
(() => {
  const amounts = <?= json_encode(array_map(fn($r) => (float)$r['amount'], $rows)) ?>;
  const sum = () => { let s = 0; document.querySelectorAll('.gi-pick').forEach((c, i) => { if (c.checked) s += amounts[i]; });
    document.getElementById('giSum').textContent = s.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  document.getElementById('giAll').addEventListener('change', e => { document.querySelectorAll('.gi-pick').forEach(c => { c.checked = e.target.checked; }); sum(); });
  document.addEventListener('change', e => {
    if (e.target.classList.contains('gi-pick')) sum();
    if (e.target.tagName === 'SELECT') e.target.classList.toggle('is-invalid', !e.target.value && !e.target.classList.contains('gi-work'));
    if (e.target.classList.contains('gi-plan')) {              // งานและโครงการ: เฉพาะของแผนงานที่เลือก
      const w = e.target.closest('td').querySelector('.gi-work');
      [...w.options].forEach(o => { if (o.value) o.hidden = !!e.target.value && o.dataset.plan !== e.target.value; });
      if (w.selectedOptions[0]?.hidden) w.value = '';
    }
  });
  document.querySelectorAll('.gi-plan').forEach(s => s.dispatchEvent(new Event('change', { bubbles: true })));
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
