<?php
/** ครุภัณฑ์ (เจ้าหน้าที่เห็นเฉพาะสรุปการยืม + สรุปตามประเภท ไม่มีลิงก์ · ตารางครุภัณฑ์เฉพาะผู้ดูแล): สรุปการยืม 4 กลุ่ม (Samsung Galaxy Tab / โน้ตบุ๊ค / แท็บเล็ต Lenovo / Microsoft) + สรุปตามประเภท + รายการทั้งหมด พร้อมสภาพการใช้งาน และผู้ยืม/ผู้ถือครอง */
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

$link = fn(array $f) => 'equipment?' . http_build_query(array_filter($f)) . '#list';

// ---------- สรุปการยืม 4 กลุ่ม (คลิกการ์ด → equipment_group) ----------
$models = eq_loan_groups($activeLoans);

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

$pageActions = ($isAdmin ? '<a href="loan_form" class="btn btn-success"><i class="bi bi-box-arrow-up-right"></i> บันทึกการยืม</a>'
        . '<a href="equipment_form" class="btn btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มครุภัณฑ์</a>'
        . '<a href="equipment_import" class="btn btn-outline-primary"><i class="bi bi-filetype-csv"></i> นำเข้า CSV</a>' : '');
require __DIR__ . '/header.php';
?>
<?php if ($models): ?>
<div class="d-flex align-items-center mb-2"><h2 class="h6 mb-0"><i class="bi bi-box-arrow-up-right text-primary"></i> สรุปการยืม</h2>
  <?php if ($isAdmin): ?><span class="ms-auto small text-muted no-print">คลิกการ์ดเพื่อดูรายละเอียดและผู้ยืม</span><?php endif; ?></div>
<div class="row g-3 mb-4" data-reveal>
  <?php $tileColors = [   // สีเดียวกับการ์ดแดชบอร์ดระบบงบประมาณ [สีหลัก, ไล่สี]
      ['#2563eb', 'linear-gradient(135deg, #1d4ed8, #3b82f6)'], ['#ea580c', 'linear-gradient(135deg, #c2410c, #f97316)'],
      ['#059669', 'linear-gradient(135deg, #047857, #10b981)'], ['#0891b2', 'linear-gradient(135deg, #0e7490, #06b6d4)']];
  foreach ($models as $i => $m): $pct = $m['total'] ? round($m['borrowed'] / $m['total'] * 100) : 0; [$tc, $tg] = $tileColors[$i % 4]; ?>
  <div class="col-sm-6 col-xl-3 reveal-item" style="--d:<?= $i * 70 ?>ms">
    <<?= $isAdmin ? 'a href="equipment_group?g=' . $i . '"' : 'div' ?> class="eq-tile<?= $isAdmin ? ' eq-tile-link' : '' ?>" style="--tc:<?= $tc ?>;--tg:<?= $tg ?>">
      <div class="t-label"><span class="t-ico"><i class="bi <?= $m['label'] === 'โน้ตบุ๊ค' ? 'bi-laptop' : 'bi-tablet' ?>"></i></span>
        <span class="text-truncate"><?= h($m['label']) ?></span>
        <span class="ms-auto t-sub" title="<?= h(implode(', ', array_keys($m['models']))) ?>"><?= count($m['models']) ?> รุ่น</span></div>
      <div class="d-flex gap-3 mt-2 eq-tile-nums">
        <div><div class="n"><?= number_format($m['borrowed']) ?></div><div class="l">ยืมอยู่</div></div>
        <div><div class="n"><?= number_format($m['available']) ?></div><div class="l">คงเหลือ</div></div>
        <div><div class="n"><?= number_format($m['total']) ?></div><div class="l">ทั้งหมด</div></div>
        <div class="ms-auto align-self-end t-sub">ยืม <?= $pct ?>%</div>
      </div>
      <span class="eq-tile-bar"><span class="bar-fill" style="width:<?= $pct ?>%"></span></span>
    </<?= $isAdmin ? 'a' : 'div' ?>>
  </div>
  <?php endforeach; ?>
</div>
<style>
.eq-tile{display:block;height:100%;border-radius:16px;padding:1rem 1.15rem;color:#fff;text-decoration:none;background:var(--tg);
  box-shadow:0 6px 18px color-mix(in srgb,var(--tc) 30%,transparent);transition:transform .15s,box-shadow .15s}
.eq-tile-link:hover,.eq-tile-link:focus-visible{color:#fff;transform:translateY(-2px);box-shadow:0 10px 24px color-mix(in srgb,var(--tc) 40%,transparent)}
.eq-tile .t-label{font-size:.95rem;font-weight:600;display:flex;align-items:center;gap:.45rem;min-width:0}
.eq-tile .t-ico{flex:none;width:32px;height:32px;border-radius:10px;display:grid;place-items:center;background:rgba(255,255,255,.2)}
.eq-tile .t-sub{font-size:.78rem;color:rgba(255,255,255,.85);white-space:nowrap}
.eq-tile-nums .n{font-size:1.6rem;font-weight:800;line-height:1.15}
.eq-tile-nums .l{font-size:.75rem;color:rgba(255,255,255,.85)}
.eq-tile-bar{display:block;height:8px;margin-top:.5rem;border-radius:6px;background:rgba(255,255,255,.28);overflow:hidden}
.eq-tile-bar .bar-fill{background:#fff}
@media print{.eq-tile{transform:none!important;box-shadow:none!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
<?php endif; ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center"><strong><i class="bi bi-pie-chart"></i> สรุปครุภัณฑ์ตามประเภท</strong>
    <?php if ($isAdmin): ?><span class="ms-auto small text-muted no-print">คลิกตัวเลขเพื่อดูรายการ</span><?php endif; ?></div>
  <div class="table-responsive">
    <table class="table align-middle mb-0 text-center eq-summary">
      <thead><tr>
        <th class="text-start">ประเภทครุภัณฑ์</th><th>ทั้งหมด</th>
        <th class="text-success">ใช้งานตามปกติ</th><th class="text-warning-emphasis">เสียรอซ่อม</th><th class="text-secondary">รอจำหน่าย</th>
        <th class="text-primary">กำลังยืม</th><th class="text-success">ว่างให้ยืม</th>
      </tr></thead>
      <tbody>
      <?php
      $sumRow = function (string $label, array $s, ?int $typeId) use ($link, $isAdmin) {
          // เจ้าหน้าที่: เห็นเฉพาะตัวเลข (ไม่มีลิงก์ไปตารางครุภัณฑ์)
          $c = fn($k, $f) => !$s[$k] ? '<span class="text-muted">0</span>'
              : ($isAdmin ? '<a href="' . h($link(['type' => $typeId] + $f)) . '">' . number_format($s[$k]) . '</a>' : number_format($s[$k]));
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

<?php if ($isAdmin): /* ตารางครุภัณฑ์: เฉพาะผู้ดูแล */ ?>
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
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
