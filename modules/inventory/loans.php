<?php
/** รายการยืม-คืนครุภัณฑ์ (ผู้ดูแลเห็นทุกใบ / เจ้าหน้าที่เห็นของตนเอง) */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(INV_MODULE);
$pdo = db();
$isAdmin = user_can(INV_MODULE, 'admin');

$tab = $_GET['tab'] ?? ($isAdmin ? 'pending' : 'all');
$q = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if (!$isAdmin) {
    $where[] = '(l.borrower_id = ? OR l.created_by = ?)';
    array_push($params, $me['id'], $me['id']);
}
$base = $where;
$baseParams = $params;
if ($q !== '') {
    $where[] = '(l.loan_no LIKE ? OR l.borrower_name LIKE ? OR l.purpose LIKE ?
        OR EXISTS (SELECT 1 FROM inv_loan_items i WHERE i.loan_id = l.id AND (i.asset_no LIKE ? OR i.brand_model LIKE ?)))';
    array_push($params, ...array_fill(0, 5, "%$q%"));
}
$today = date('Y-m-d');
if ($tab === 'overdue') {
    $where[] = "l.status = 'borrowed' AND l.due_date < ?";
    $params[] = $today;
} elseif (isset(LOAN_STATUS[$tab])) {
    $where[] = 'l.status = ?';
    $params[] = $tab;
}

// จำนวนในแต่ละแท็บ
$cst = $pdo->prepare('SELECT l.status, COUNT(*) n, SUM(l.status = \'borrowed\' AND l.due_date < ?) od FROM inv_loans l'
    . ($base ? ' WHERE ' . implode(' AND ', $base) : '') . ' GROUP BY l.status');
$cst->execute([$today, ...$baseParams]);
$counts = ['all' => 0, 'overdue' => 0];
foreach ($cst as $r) {
    $counts[$r['status']] = (int)$r['n'];
    $counts['all'] += (int)$r['n'];
    $counts['overdue'] += (int)$r['od'];
}

$st = $pdo->prepare('SELECT l.*, (SELECT COUNT(*) FROM inv_loan_items i WHERE i.loan_id = l.id) AS item_count,
    (SELECT GROUP_CONCAT(COALESCE(i.brand_model, i.item_name) ORDER BY i.sort_order SEPARATOR ", ") FROM inv_loan_items i WHERE i.loan_id = l.id) AS item_list
    FROM inv_loans l' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY l.id DESC LIMIT 500');
$st->execute($params);
$loans = $st->fetchAll();

$tabs = ['pending' => 'รออนุมัติ', 'borrowed' => 'กำลังยืม', 'overdue' => 'เกินกำหนดคืน', 'returned' => 'คืนแล้ว',
         'rejected' => 'ไม่อนุมัติ', 'cancelled' => 'ยกเลิก', 'all' => 'ทั้งหมด'];
$pageActions = '<a href="loan_form.php" class="btn btn-success"><i class="bi bi-plus-lg"></i> ขอยืมครุภัณฑ์</a>';
require __DIR__ . '/header.php';
?>
<ul class="nav nav-pills mb-3 flex-wrap gap-1">
  <?php foreach ($tabs as $k => $label): $n = $counts[$k] ?? 0; ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= $k ?>&q=<?= urlencode($q) ?>"><?= h($label) ?>
      <?php if ($n): ?><span class="badge <?= $k === 'overdue' ? 'text-bg-danger' : ($tab === $k ? 'text-bg-light' : 'text-bg-secondary') ?>"><?= $n ?></span><?php endif; ?></a></li>
  <?php endforeach; ?>
</ul>
<div class="card">
  <div class="card-header">
    <form class="row g-2"><input type="hidden" name="tab" value="<?= h($tab) ?>">
      <div class="col-md-5"><input name="q" class="form-control" placeholder="ค้นหาเลขที่ / ผู้ยืม / เลขครุภัณฑ์ / รุ่น" value="<?= h($q) ?>"></div>
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>เลขที่</th><th>ผู้ยืม</th><th>รายการ</th><th>ระยะเวลายืม</th><th>สถานะ</th><th class="text-end"></th></tr></thead>
      <tbody>
      <?php foreach ($loans as $l): $od = $l['status'] === 'borrowed' && $l['due_date'] < $today; ?>
        <tr class="<?= $od ? 'table-danger' : '' ?>">
          <td><a href="loan_view.php?id=<?= (int)$l['id'] ?>" class="fw-semibold text-decoration-none"><?= h($l['loan_no']) ?></a>
            <div class="small text-muted"><?= h(thai_date($l['created_at'])) ?></div></td>
          <td><?= h($l['borrower_name']) ?><div class="small text-muted"><?= h($l['borrower_position']) ?></div></td>
          <td class="small" style="max-width:320px"><span class="badge text-bg-light border"><?= (int)$l['item_count'] ?> รายการ</span> <?= h(mb_strimwidth((string)$l['item_list'], 0, 90, '…')) ?></td>
          <td class="small text-nowrap"><?= h(thai_date($l['start_date'])) ?> – <?= h(thai_date($l['due_date'])) ?>
            <?php if ($l['returned_at']): ?><div class="text-success">คืน <?= h(thai_date($l['returned_at'])) ?></div><?php endif; ?></td>
          <td><?= loan_status_badge($l['status']) ?><?= $od ? ' <span class="badge text-bg-danger">เกินกำหนด</span>' : '' ?></td>
          <td class="text-end text-nowrap">
            <a href="loan_view.php?id=<?= (int)$l['id'] ?>" class="btn btn-sm btn-light" title="ดู"><i class="bi bi-eye"></i></a>
            <a href="loan_print.php?id=<?= (int)$l['id'] ?>" target="_blank" class="btn btn-sm btn-light" title="พิมพ์"><i class="bi bi-printer"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$loans): ?><tr><td colspan="6" class="text-center text-muted py-5">ไม่มีรายการ</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
