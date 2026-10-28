<?php
require_once __DIR__ . '/header.php';

$pdo = getDbConnection();
$materialCount = $pdo->query('SELECT COUNT(*) FROM inv_materials')->fetchColumn();
$lowStockCount = $pdo->query('SELECT COUNT(*) FROM inv_materials WHERE stock_qty <= min_stock')->fetchColumn();
$equipmentCount = $pdo->query('SELECT COUNT(*) FROM inv_equipment')->fetchColumn();
$loanCounts = $pdo->query("SELECT SUM(status = 'borrowed') AS borrowed,
    SUM(status = 'borrowed' AND due_date < CURDATE()) AS overdue FROM inv_loans")->fetch();

// Admin sees the 5 most recent requisitions system-wide; staff only ever
// see their own (matches the same restriction enforced in requisition_api.php).
$reqSql = "SELECT r.*, u.full_name AS created_by_name,
        (SELECT COUNT(*) FROM inv_requisition_items ri WHERE ri.requisition_id = r.id) AS item_count
    FROM inv_requisitions r
    JOIN core_users u ON u.id = r.created_by";
$reqParams = [];
if (!isAdmin()) {
    $reqSql .= ' WHERE r.created_by = :uid';
    $reqParams['uid'] = $user['id'];
}
$reqSql .= ' ORDER BY r.created_at DESC LIMIT 5';
$reqStmt = $pdo->prepare($reqSql);
$reqStmt->execute($reqParams);
$recentRequisitions = $reqStmt->fetchAll();

// Every logged-in user can open materials.php now (read-only for staff -
// the page itself hides the add/edit/delete/import controls for non-admin).
function dashboardCard(string $colorClass, int $value, string $label, string $href): void
{
    $inner = '<div class="card ' . $colorClass . ' h-100 dashboard-clickable"><div class="card-body">'
        . '<div class="fs-2 fw-bold">' . $value . '</div><div>' . htmlspecialchars($label) . '</div></div></div>';
    echo '<a href="' . htmlspecialchars($href) . '" class="text-decoration-none">' . $inner . '</a>';
}
?>


<div class="row g-3 mb-4">
    <div class="col-6 col-lg">
        <?php dashboardCard('text-bg-primary', (int) $materialCount, 'วัสดุสิ้นเปลืองทั้งหมด', INV_URL . 'materials.php') ?>
    </div>
    <div class="col-6 col-lg">
        <?php dashboardCard('text-bg-warning', (int) $lowStockCount, 'วัสดุใกล้หมดสต๊อก', INV_URL . 'materials.php?filter=low_stock') ?>
    </div>
    <div class="col-6 col-lg">
        <?php dashboardCard('text-bg-dark', (int) $equipmentCount, 'ครุภัณฑ์ทั้งหมด', INV_URL . 'equipment.php') ?>
    </div>
    <div class="col-6 col-lg">
        <?php dashboardCard('text-bg-info', (int) $loanCounts['borrowed'], 'ครุภัณฑ์ที่กำลังยืม', INV_URL . 'loans.php?tab=borrowed') ?>
    </div>
    <div class="col-6 col-lg">
        <?php dashboardCard((int) $loanCounts['overdue'] ? 'text-bg-danger' : 'text-bg-light border', (int) $loanCounts['overdue'], 'เกินกำหนดคืน', INV_URL . 'loans.php?tab=overdue') ?>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>ใบเบิกล่าสุด</span>
        <a href="<?= INV_URL ?>requisitions.php" class="small">ดูทั้งหมด &rarr;</a>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>เลขที่ใบเบิก</th><th>ผู้เบิก</th><th>จำนวนรายการ</th><th>เวลา</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recentRequisitions as $req): ?>
                <tr>
                    <td><a href="<?= INV_URL ?>requisition_view.php?id=<?= (int) $req['id'] ?>"><?= htmlspecialchars($req['requisition_no']) ?></a></td>
                    <td><?= htmlspecialchars($req['requester_name'] ?: '-') ?></td>
                    <td><?= (int) $req['item_count'] ?></td>
                    <td><?= htmlspecialchars(formatThaiDateTime($req['created_at'])) ?></td>
                    <td>
                        <a href="<?= INV_URL ?>requisition_print.php?id=<?= (int) $req['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">พิมพ์</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentRequisitions): ?>
                <tr><td colspan="5" class="text-center text-muted">ไม่มีข้อมูล</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
