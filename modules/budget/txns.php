<?php
/** รายการรับเข้างบประมาณ (ได้รับ) / เบิกจ่ายงบประมาณ — ค้นหา กรอง ส่งออก CSV · ผู้จัดทำงบประมาณ เพิ่ม/แก้ไข/ลบ */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(BUD_MODULE, 'viewer');
$type = ($_GET['type'] ?? $_POST['type'] ?? '') === 'spend' ? 'spend' : 'receive';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    check_csrf();
    if (!bud_can('editor')) {
        forbidden(BUD_MODULE, 'editor');
    }
    $st = db()->prepare('SELECT * FROM bud_txns WHERE id = ?');
    $st->execute([int_param('id')]);
    if ($t = $st->fetch()) {
        db()->prepare('DELETE FROM bud_txns WHERE id = ?')->execute([$t['id']]);
        audit_log('bud_delete', 'ลบรายการ' . BUD_TYPES[$t['txn_type']] . ': ' . $t['description'] . ' ' . bud_money($t['amount']) . ' บาท', BUD_MODULE);
        flash('ลบรายการแล้ว');
    }
    redirect('txns?' . http_build_query(['type' => $type, 'fy' => $_POST['fy'] ?? null]));
}

$fy = bud_fy_param();
$months = bud_fy_months($fy);
$month = in_array($_GET['month'] ?? '', $months, true) ? $_GET['month'] : '';
$sources = bud_sources(true);
$src = isset($sources[$_GET['src'] ?? '']) ? $_GET['src'] : '';
$q = trim((string)($_GET['q'] ?? ''));
$works = bud_works(true);
$plans = bud_plans();
$scope = (string)($_GET['scope'] ?? '');
$scopeWhere = bud_scope_where($scope);
$scope = $scopeWhere ? $scope : '';

$where = ['t.txn_type = ?', 't.fiscal_year = ?'];
$args = [$type, $fy];
if ($month) {
    $where[] = "DATE_FORMAT(t.txn_date, '%Y-%m') = ?";
    $args[] = $month;
}
if ($src) {
    // รายการงบประมาณที่ผูกโครงการ: รวมรายการที่บันทึกด้วยโครงการนั้นด้วย
    $pid = $src[0] === 'p' ? (int)substr($src, 2) : ($sources[$src]['project_id'] ?? 0);
    $iid = $src[0] === 'i' ? (int)substr($src, 2) : 0;
    $where[] = '(t.item_id = ? OR t.project_id = ?)';
    array_push($args, $iid, $pid);
}
if ($scopeWhere) {
    $where[] = $scopeWhere[0];
    array_push($args, ...$scopeWhere[1]);
}
if ($q !== '') {
    $where[] = '(t.description LIKE ? OR t.detail LIKE ? OR t.doc_no LIKE ? OR t.note LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
}
$st = db()->prepare('SELECT t.*, u.full_name AS by_name FROM bud_txns t LEFT JOIN core_users u ON u.id = COALESCE(t.updated_by, t.created_by)
                     WHERE ' . implode(' AND ', $where) . ' ORDER BY t.txn_date DESC, t.id DESC');
$st->execute($args);
$rows = $st->fetchAll();
$total = array_sum(array_column($rows, 'amount'));

if (($_GET['export'] ?? '') === 'csv') {
    bud_csv('budget_' . $type . '_' . $fy . ($month ? '_' . $month : '') . '.csv',
        ['วันที่', 'ปีงบประมาณ', 'ที่เอกสาร', 'รายการ', 'รายละเอียด', 'แผนงาน', 'งานและโครงการ', 'หมวด', BUD_TYPES[$type] . ' (บาท)', 'หมายเหตุ'],
        array_merge(array_map(fn($t) => [thai_date($t['txn_date']), $t['fiscal_year'], $t['doc_no'], $t['description'], $t['detail'],
            $plans[bud_txn_plan($t)]['name'] ?? '', $works[(int)$t['work_id']]['name'] ?? '', $sources[bud_source_key($t)]['label'] ?? '-', number_format((float)$t['amount'], 2, '.', ''), $t['note']], array_reverse($rows)),
            [['', '', '', 'รวม', '', '', '', '', number_format($total, 2, '.', ''), '']]));
}

$qs = fn(array $p) => '?' . http_build_query(array_filter($p + ['type' => $type, 'fy' => $fy, 'month' => $month, 'src' => $src, 'scope' => $scope, 'q' => $q], fn($v) => $v !== '' && $v !== null));
$pageTitle = BUD_TYPE_PAGES[$type];
$active = $type;
$pageActions = (bud_can('editor') ? '<a href="txn_form?type=' . $type . '" class="btn btn-' . ($type === 'spend' ? 'warning' : 'primary') . '"><i class="bi bi-plus-lg"></i> บันทึก' . BUD_TYPE_PAGES[$type] . '</a>' : '')
    . '<a href="' . h($qs(['export' => 'csv'])) . '" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> ส่งออก CSV</a>';
require __DIR__ . '/header.php';
?>
<form class="card card-body mb-3 py-2" method="get">
  <input type="hidden" name="type" value="<?= $type ?>">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-1"><label class="form-label small mb-0">ปีงบประมาณ</label>
      <select name="fy" class="form-select form-select-sm" onchange="this.form.month.value='';this.form.submit()">
        <?php foreach (bud_fy_list() as $y): ?><option value="<?= $y ?>"<?= $y === $fy ? ' selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">เดือน</label>
      <select name="month" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">ทั้งปีงบ</option>
        <?php foreach ($months as $ym): ?><option value="<?= $ym ?>"<?= $ym === $month ? ' selected' : '' ?>><?= h(bud_month_label($ym)) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-3"><label class="form-label small mb-0">แผนงาน / งานและโครงการ</label>
      <select name="scope" class="form-select form-select-sm" onchange="this.form.submit()"><?= bud_scope_options($scope) ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-0">หมวด</label>
      <select name="src" class="form-select form-select-sm" onchange="this.form.submit()"><?= bud_source_options($sources, $src) ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-0">ค้นหา</label><input type="search" name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="รายการ / ที่เอกสาร / หมายเหตุ"></div>
    <div class="col-md-1 d-grid"><button class="btn btn-sm btn-secondary"><i class="bi bi-search"></i></button></div>
  </div>
</form>

<div class="card">
  <div class="card-header d-flex align-items-center flex-wrap gap-2">
    <strong><?= h(BUD_TYPE_PAGES[$type]) ?> ปีงบประมาณ <?= $fy ?><?= $month ? ' · ' . h(bud_month_label($month)) : '' ?></strong>
    <span class="ms-auto">รวม <?= number_format(count($rows)) ?> รายการ · <b class="bud-<?= $type ?>"><?= bud_money($total) ?></b> บาท</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0 bud-table">
      <thead class="table-light"><tr>
        <th class="text-nowrap">วันที่</th><th class="text-nowrap">ที่เอกสาร</th><th>รายการ</th><th>หมวด</th>
        <th class="text-end text-nowrap"><?= BUD_TYPES[$type] ?> (บาท)</th><?php if (bud_can('editor')): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $t): $s = $sources[bud_source_key($t)] ?? null; ?>
        <tr>
          <td class="text-nowrap"><?= h(thai_date($t['txn_date'])) ?></td>
          <td class="text-nowrap"><?= h($t['doc_no']) ?></td>
          <td><?= h($t['description']) ?><?php if ($t['detail'] !== ''): ?><div class="small"><?= nl2br(h($t['detail'])) ?></div><?php endif; ?><?php if ($t['note'] !== ''): ?><div class="small text-muted"><?= h($t['note']) ?></div><?php endif; ?></td>
          <td><?php if ($s): ?><span class="bud-src" style="--c:<?= h($s['color']) ?>"><?= h($s['label']) ?></span><?php endif; ?>
            <?php $pl = $plans[bud_txn_plan($t)] ?? null; $w = $works[(int)$t['work_id']] ?? null; if ($pl): ?><div class="small text-muted"><?= h($pl['name']) ?><?= $w ? ' › ' . h($w['name']) : '' ?></div><?php endif; ?></td>
          <td class="text-end fw-semibold text-nowrap"><?= bud_money($t['amount']) ?></td>
          <?php if (bud_can('editor')): ?>
          <td class="text-end text-nowrap">
            <a href="txn_form?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-light" title="แก้ไข"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" data-confirm="<?= h('ลบรายการ "' . $t['description'] . '" ' . bud_money($t['amount']) . ' บาท?') ?>">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <input type="hidden" name="type" value="<?= $type ?>"><input type="hidden" name="fy" value="<?= $fy ?>">
              <button class="btn btn-sm btn-light text-danger" title="ลบ"><i class="bi bi-trash"></i></button></form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีรายการ</td></tr><?php endif; ?>
      </tbody>
      <?php if ($rows): ?><tfoot><tr class="table-light fw-bold"><td colspan="4" class="text-end">รวม</td><td class="text-end"><?= bud_money($total) ?></td><?php if (bud_can('editor')): ?><td></td><?php endif; ?></tr></tfoot><?php endif; ?>
    </table>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
