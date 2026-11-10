<?php
/** ครุภัณฑ์: ยอดยืม/คงเหลือของรุ่นใหม่ 6 รุ่น + สรุปตามประเภท + รายการทั้งหมด พร้อมสภาพการใช้งาน และผู้ยืม/ผู้ถือครอง */
require_once __DIR__ . '/bootstrap.php';
require_module(INV_MODULE);
$pdo = db();
$isAdmin = user_can(INV_MODULE, 'admin');

// ผู้ดูแล: เปลี่ยนสภาพหลายรายการพร้อมกัน
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_module(INV_MODULE, 'admin');
    check_csrf();
    $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
    $status = post('bulk_status');
    if ($ids && isset(EQ_STATUS[$status])) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE inv_equipment SET status = ? WHERE id IN ($in)")->execute([$status, ...$ids]);
        audit_log('equipment_bulk_status', count($ids) . ' รายการ → ' . $status, INV_MODULE);
        flash('เปลี่ยนสภาพเป็น "' . EQ_STATUS[$status][0] . '" จำนวน ' . count($ids) . ' รายการ');
    } else {
        flash('กรุณาเลือกรายการและสภาพที่ต้องการ', 'warning');
    }
    redirect('equipment?' . ($_POST['back'] ?? ''));
}

$types = equipment_types();
$activeLoans = active_loans_by_equipment();

// ---------- สรุปตามประเภท ----------
$summary = [];
foreach ($pdo->query('SELECT id, type_id, status FROM inv_equipment') as $r) {
    $t = (int)$r['type_id'];
    $summary[$t] ??= ['total' => 0, 'normal' => 0, 'repair' => 0, 'dispose' => 0, 'borrowed' => 0, 'available' => 0];
    $s = &$summary[$t];
    $s['total']++;
    $s[$r['status']]++;
    $loan = $activeLoans[(int)$r["id"]] ?? null;
    if ($loan) {
        $s['borrowed']++;
    } elseif ($r['status'] === 'normal') {
        $s['available']++;
    }
    unset($s);
}
$totals = array_fill_keys(['total', 'normal', 'repair', 'dispose', 'borrowed', 'available'], 0);
foreach ($summary as $s) {
    foreach ($s as $k => $v) {
        $totals[$k] += $v;
    }
}

// ---------- รุ่นใหม่ 6 รุ่น (ตามปีงบล่าสุด): ยอดยืม / คงเหลือ / ใครยืม — ไม่รวมประเภทถือครอง (PC Desktop) ----------
$models = [];
foreach ($pdo->query("SELECT e.id, e.type_id, e.brand, e.model, e.fiscal_year, e.status, e.asset_no, t.name AS type_name
    FROM inv_equipment e LEFT JOIN inv_equipment_types t ON t.id = e.type_id ORDER BY e.asset_no") as $r) {
    if (eq_is_holding_type($r['type_name']) || trim($r['brand'] . $r['model']) === '') {
        continue;
    }
    $key = $r['type_id'] . '|' . $r['brand'] . '|' . $r['model'];
    $m = &$models[$key];
    $m ??= ['type' => $r['type_name'] ?? '', 'brand' => (string)$r['brand'], 'model' => (string)$r['model'], 'fy' => 0,
            'total' => 0, 'borrowed' => 0, 'available' => 0, 'loans' => [], 'free' => []];
    $m['fy'] = max($m['fy'], (int)$r['fiscal_year']);
    $m['total']++;
    if ($loan = $activeLoans[(int)$r['id']] ?? null) {
        $m['borrowed']++;
        $m['loans'][] = ['name' => $loan['borrower_name'], 'loan_no' => $loan['loan_no'], 'loan_id' => (int)$loan['loan_id'],
                         'due' => $loan['due_date'], 'due_th' => thai_date($loan['due_date']), 'over' => $loan['due_date'] < date('Y-m-d'),
                         'asset' => $r['asset_no'] ?: '-'];
    } elseif ($r['status'] === 'normal') {
        $m['available']++;
        $m['free'][] = $r['asset_no'] ?: '(ยังไม่มีเลข)';
    }
    unset($m);
}
uasort($models, fn($a, $b) => [$b['fy'], $b['total']] <=> [$a['fy'], $a['total']]);
$models = array_slice(array_values($models), 0, 6);
foreach ($models as &$m) {
    usort($m['loans'], fn($a, $b) => [$a['due'], $a['name']] <=> [$b['due'], $b['name']]);   // ใกล้ครบกำหนดก่อน
}
unset($m);

// ---------- รายการ ----------
$q = trim($_GET['q'] ?? '');
$fType = int_param('type');
$fStatus = $_GET['status'] ?? '';
$fLoan = $_GET['loan'] ?? '';
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(e.asset_no LIKE ? OR e.brand LIKE ? OR e.model LIKE ? OR e.serial_number LIKE ? OR e.note LIKE ?
        OR e.assigned_user_id IN (SELECT id FROM core_users WHERE full_name LIKE ?)
        OR e.id IN (SELECT li.equipment_id FROM inv_loan_items li JOIN inv_loans l ON l.id = li.loan_id WHERE l.status = \'borrowed\' AND l.borrower_name LIKE ?))';
    array_push($params, ...array_fill(0, 7, "%$q%"));
}
if ($fType) {
    $where[] = 'e.type_id = ?';
    $params[] = $fType;
}
if (isset(EQ_STATUS[$fStatus])) {
    $where[] = 'e.status = ?';
    $params[] = $fStatus;
}
$st = $pdo->prepare('SELECT e.*, t.name AS type_name, au.full_name AS assigned_name FROM inv_equipment e
    LEFT JOIN inv_equipment_types t ON t.id = e.type_id LEFT JOIN core_users au ON au.id = e.assigned_user_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY t.sort_order IS NULL, t.sort_order, e.fiscal_year DESC, e.asset_no IS NULL, e.asset_no, e.id');
$st->execute($params);
$rows = array_values(array_filter($st->fetchAll(), function ($e) use ($fLoan, $activeLoans) {
    $loan = $activeLoans[(int)$e["id"]] ?? null;
    return match ($fLoan) {
        'borrowed'  => (bool)$loan,
        'available' => !$loan && $e['status'] === 'normal',
        default     => true,
    };
}));
$filterQs = http_build_query(array_filter(['q' => $q, 'type' => $fType ?: null, 'status' => $fStatus, 'loan' => $fLoan]));
$link = fn(array $f) => 'equipment?' . http_build_query(array_filter($f)) . '#list';

$pageActions = ($isAdmin ? '<a href="loan_form" class="btn btn-success"><i class="bi bi-box-arrow-up-right"></i> บันทึกการยืม</a>'
        . '<a href="equipment_form" class="btn btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มครุภัณฑ์</a>'
        . '<a href="equipment_import" class="btn btn-outline-primary"><i class="bi bi-filetype-csv"></i> นำเข้า CSV</a>' : '');
require __DIR__ . '/header.php';
?>
<?php if ($models): ?>
<div class="d-flex align-items-center mb-2"><h2 class="h6 mb-0"><i class="bi bi-stars text-warning"></i> การยืมรุ่นใหม่ล่าสุด</h2>
  <span class="ms-auto small text-muted no-print">คลิกการ์ดเพื่อดูว่าใครยืมบ้าง</span></div>
<div class="row g-3 mb-4" data-reveal>
  <?php foreach ($models as $i => $m): $pct = $m['total'] ? round($m['borrowed'] / $m['total'] * 100) : 0; ?>
  <div class="col-sm-6 col-xl-4 reveal-item" style="--d:<?= $i * 70 ?>ms">
    <button type="button" class="card eq-model h-100 w-100 text-start" data-model="<?= $i ?>">
      <div class="card-body p-3">
        <div class="small text-muted text-truncate"><?= h($m['type']) ?><?= $m['fy'] ? ' · ปีงบ ' . $m['fy'] : '' ?></div>
        <div class="fw-semibold text-truncate" title="<?= h($m['brand'] . ' ' . $m['model']) ?>"><?= h($m['brand']) ?> <span class="text-primary"><?= h($m['model']) ?></span></div>
        <div class="d-flex gap-3 mt-2 eq-model-nums">
          <div><div class="n text-primary"><?= number_format($m['borrowed']) ?></div><div class="l">ยืมอยู่</div></div>
          <div><div class="n text-success"><?= number_format($m['available']) ?></div><div class="l">คงเหลือ</div></div>
          <div><div class="n"><?= number_format($m['total']) ?></div><div class="l">ทั้งหมด</div></div>
          <div class="ms-auto align-self-end small text-muted"><?= $pct ?>%</div>
        </div>
        <span class="bar-track d-block mt-1"><span class="bar-fill" style="width:<?= $pct ?>%"></span></span>
      </div>
    </button>
  </div>
  <?php endforeach; ?>
</div>
<div class="modal fade" id="eqModelModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="eqmTitle"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="eqmBody"></div>
  </div></div>
</div>
<style>
.eq-model{border:1px solid var(--bs-border-color);background:var(--bs-body-bg);transition:box-shadow .15s,transform .15s}
.eq-model:hover,.eq-model:focus-visible{box-shadow:0 .4rem 1rem rgba(30,60,120,.12);transform:translateY(-2px)}
.eq-model-nums .n{font-size:1.45rem;font-weight:700;line-height:1.1}
.eq-model-nums .l{font-size:.75rem;color:var(--bs-secondary-color)}
@media print{.eq-model{transform:none!important;box-shadow:none!important}}
</style>
<script>
(() => {
  const models = <?= json_encode($models, JSON_UNESCAPED_UNICODE) ?>;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  document.querySelectorAll('.eq-model').forEach(btn => btn.addEventListener('click', () => {
    const m = models[+btn.dataset.model];
    document.getElementById('eqmTitle').innerHTML = esc(m.brand) + ' <span class="text-primary">' + esc(m.model) + '</span>'
      + '<div class="small text-muted fw-normal">' + esc(m.type) + (m.fy ? ' · ปีงบ ' + m.fy : '') + ' · ยืมอยู่ ' + m.borrowed + ' / คงเหลือ ' + m.available + ' / ทั้งหมด ' + m.total + '</div>';
    document.getElementById('eqmBody').innerHTML =
      '<h6 class="mb-2"><i class="bi bi-person-fill text-primary"></i> ผู้ยืม (' + m.loans.length + ')</h6>'
      + (m.loans.length ? '<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>#</th><th>ผู้ยืม</th><th>เลขครุภัณฑ์</th><th>รายการยืม</th><th>กำหนดคืน</th></tr></thead><tbody>'
        + m.loans.map((l, i) => `<tr><td>${i + 1}</td><td>${esc(l.name)}</td><td class="font-monospace small">${esc(l.asset)}</td>
            <td><a href="loan_view?id=${l.loan_id}">${esc(l.loan_no)}</a></td>
            <td class="text-nowrap">${esc(l.due_th)}${l.over ? ' <span class="badge text-bg-danger">เกินกำหนด</span>' : ''}</td></tr>`).join('')
        + '</tbody></table></div>' : '<p class="text-muted">ยังไม่มีผู้ยืม</p>')
      + '<h6 class="mb-2 mt-3"><i class="bi bi-check-circle text-success"></i> คงเหลือให้ยืม (' + m.free.length + ')</h6>'
      + (m.free.length ? '<div class="d-flex flex-wrap gap-1">' + m.free.map(a => '<span class="badge text-bg-light border font-monospace fw-normal">' + esc(a) + '</span>').join('') + '</div>' : '<p class="text-muted mb-0">ไม่มี</p>')
      + '<div class="mt-3"><a class="small" href="equipment?q=' + encodeURIComponent(m.model) + '#list"><i class="bi bi-list-ul"></i> ดูรายการครุภัณฑ์รุ่นนี้ทั้งหมด</a></div>';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('eqModelModal')).show();
  }));
})();
</script>
<?php endif; ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center"><strong><i class="bi bi-pie-chart"></i> สรุปครุภัณฑ์ตามประเภท</strong>
    <span class="ms-auto small text-muted no-print">คลิกตัวเลขเพื่อดูรายการ</span></div>
  <div class="table-responsive">
    <table class="table align-middle mb-0 text-center eq-summary">
      <thead><tr>
        <th class="text-start">ประเภทครุภัณฑ์</th><th>ทั้งหมด</th>
        <th class="text-success">ใช้งานตามปกติ</th><th class="text-warning-emphasis">เสียรอซ่อม</th><th class="text-secondary">รอจำหน่าย</th>
        <th class="text-primary">กำลังยืม</th><th class="text-success">ว่างให้ยืม</th>
      </tr></thead>
      <tbody>
      <?php
      $sumRow = function (string $label, array $s, ?int $typeId) use ($link) {
          $c = fn($k, $f) => $s[$k] ? '<a href="' . h($link(['type' => $typeId] + $f)) . '">' . number_format($s[$k]) . '</a>' : '<span class="text-muted">0</span>';
          echo '<tr' . ($typeId === null ? ' class="table-light fw-semibold"' : '') . '><td class="text-start">' . h($label) . '</td>'
              . '<td class="fw-semibold">' . $c('total', []) . '</td>'
              . '<td>' . $c('normal', ['status' => 'normal']) . '</td>'
              . '<td>' . $c('repair', ['status' => 'repair']) . '</td>'
              . '<td>' . $c('dispose', ['status' => 'dispose']) . '</td>'
              . '<td>' . $c('borrowed', ['loan' => 'borrowed']) . '</td>'
              . '<td>' . $c('available', ['loan' => 'available']) . '</td></tr>';
      };
      foreach ($types as $tid => $tname) {
          if (isset($summary[$tid])) {
              $sumRow($tname, $summary[$tid], $tid);
          }
      }
      if (isset($summary[0])) {
          $sumRow('ไม่ระบุประเภท', $summary[0], null);
      }
      if ($summary) {
          $sumRow('รวมทั้งหมด', $totals, null);
      } else {
          echo '<tr><td colspan="7" class="text-muted py-4">ยังไม่มีข้อมูลครุภัณฑ์' . ($isAdmin ? ' — เพิ่มทีละรายการ หรือนำเข้าจากไฟล์ CSV' : '') . '</td></tr>';
      }
      ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" id="list">
  <div class="card-header no-print">
    <form class="row g-2 align-items-center" action="equipment#list">
      <div class="col-lg-3 col-md-6"><input name="q" class="form-control" placeholder="ค้นหาเลขครุภัณฑ์ / ยี่ห้อ / รุ่น / ผู้ยืม/ผู้ถือครอง" value="<?= h($q) ?>"></div>
      <div class="col-lg-3 col-md-6"><select name="type" class="form-select" onchange="this.form.submit()">
        <option value="">ทุกประเภท</option>
        <?php foreach ($types as $tid => $tname): ?><option value="<?= (int)$tid ?>" <?= $fType === (int)$tid ? 'selected' : '' ?>><?= h($tname) ?></option><?php endforeach; ?>
      </select></div>
      <div class="col-lg-2 col-md-4"><select name="status" class="form-select" onchange="this.form.submit()">
        <option value="">ทุกสภาพ</option>
        <?php foreach (EQ_STATUS as $k => $s): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= h($s[0]) ?></option><?php endforeach; ?>
      </select></div>
      <div class="col-lg-2 col-md-4"><select name="loan" class="form-select" onchange="this.form.submit()">
        <option value="">การยืมทั้งหมด</option>
        <option value="borrowed" <?= $fLoan === 'borrowed' ? 'selected' : '' ?>>กำลังยืม</option>
        <option value="available" <?= $fLoan === 'available' ? 'selected' : '' ?>>ว่างให้ยืม</option>
      </select></div>
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
        <?php if ($filterQs): ?><a href="equipment#list" class="btn btn-link">ล้างตัวกรอง</a><?php endif; ?></div>
      <div class="col-auto ms-auto"><button type="button" class="btn btn-light" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์</button></div>
    </form>
  </div>
  <form method="post" id="bulkForm">
    <?= csrf_field() ?><input type="hidden" name="back" value="<?= h($filterQs) ?>">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 eq-table">
        <thead><tr>
          <?php if ($isAdmin): ?><th class="no-print" style="width:32px"><input type="checkbox" class="form-check-input" id="checkAll"></th><?php endif; ?>
          <th class="text-center">ที่</th><th>เลขครุภัณฑ์</th><th>ประเภท</th><th>ยี่ห้อ</th><th>รุ่น (Model)</th>
          <th class="text-center">ปีงบ</th><th class="text-center">อายุ</th><th>สภาพการใช้งาน</th><th>ผู้ยืม/ผู้ถือครอง</th><th>หมายเหตุ</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $e): $loan = $activeLoans[(int)$e["id"]] ?? null; $age = equipment_age($e['fiscal_year'] ? (int)$e['fiscal_year'] : null); ?>
          <tr>
            <?php if ($isAdmin): ?><td class="no-print"><input type="checkbox" class="form-check-input row-check" name="ids[]" value="<?= (int)$e['id'] ?>"></td><?php endif; ?>
            <td class="text-center text-muted"><?= $i + 1 ?></td>
            <td class="text-nowrap"><a href="equipment_view?id=<?= (int)$e['id'] ?>" class="text-decoration-none font-monospace small"><?= $e['asset_no'] ? h($e['asset_no']) : '<span class="text-muted fst-italic">(ยังไม่มีเลข)</span>' ?></a></td>
            <td class="small"><?= h($e['type_name'] ?? '-') ?></td>
            <td><?= h($e['brand']) ?></td>
            <td><a href="equipment_view?id=<?= (int)$e['id'] ?>" class="text-decoration-none text-reset"><?= h($e['model']) ?></a></td>
            <td class="text-center"><?= h($e['fiscal_year']) ?></td>
            <td class="text-center"><?= $age === null ? '-' : $age ?></td>
            <td class="text-nowrap"><?= eq_status_badge($e['status']) ?></td>
            <td class="small eq-person"><?php if ($loan): ?>
              <a href="loan_view?id=<?= (int)$loan['loan_id'] ?>" class="text-decoration-none">
                <i class="bi bi-person-fill text-primary"></i> <?= h($loan['borrower_name']) ?></a>
              <div class="text-muted">คืน <?= h(thai_date($loan['due_date'])) ?>
                <?php if ($loan['due_date'] < date('Y-m-d')): ?><span class="badge text-bg-danger">เกินกำหนด</span><?php endif; ?></div>
            <?php elseif ($e['assigned_name']): ?>
              <i class="bi bi-person-workspace text-success"></i> <?= h($e['assigned_name']) ?><div class="text-muted">ผู้ถือครอง</div>
            <?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
            <td class="small text-muted"><?= h($e['note']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-5">ไม่พบรายการ</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
      <span class="small text-muted">แสดง <?= count($rows) ?> รายการ</span>
      <?php if ($isAdmin && $rows): ?>
        <div class="ms-auto d-flex gap-2 align-items-center no-print">
          <span class="small">รายการที่เลือก <strong id="checkedCount">0</strong> →</span>
          <select name="bulk_status" class="form-select form-select-sm" style="width:auto">
            <option value="">เปลี่ยนสภาพเป็น…</option>
            <?php foreach (EQ_STATUS as $k => $s): ?><option value="<?= $k ?>"><?= h($s[0]) ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-primary">บันทึก</button>
        </div>
      <?php endif; ?>
    </div>
  </form>
</div>
<script>
(() => {
  const all = document.getElementById('checkAll');
  const boxes = () => document.querySelectorAll('.row-check');
  const count = () => { const n = document.querySelectorAll('.row-check:checked').length; const el = document.getElementById('checkedCount'); if (el) el.textContent = n; };
  all?.addEventListener('change', () => { boxes().forEach(b => b.checked = all.checked); count(); });
  document.addEventListener('change', e => { if (e.target.classList.contains('row-check')) count(); });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
