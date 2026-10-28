<?php
require_once __DIR__ . '/bootstrap.php';
require_module(INV_MODULE);

$pdo = getDbConnection();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT r.*, u.full_name AS created_by_name, au.full_name AS approved_by_name
    FROM inv_requisitions r
    JOIN core_users u ON u.id = r.created_by
    LEFT JOIN core_users au ON au.id = r.approved_by
    WHERE r.id = :id');
$stmt->execute(['id' => $id]);
$requisition = $stmt->fetch();

// ตรวจสิทธิ์ก่อนแสดงผล (เพื่อส่งรหัส 403/404 ได้ถูกต้อง)
$denied = null;
if (!$requisition) {
    http_response_code(404);
    $denied = 'ไม่พบใบเบิกที่ร้องขอ';
} elseif (!isAdmin() && (int) $requisition['created_by'] !== (int) currentUser()['id']) {
    // Staff only see their own requisitions; admin sees everything.
    http_response_code(403);
    $denied = 'คุณไม่มีสิทธิ์เข้าถึงใบเบิกนี้';
}
require_once __DIR__ . '/header.php';
if ($denied) {
    echo '<div class="alert alert-danger">' . htmlspecialchars($denied) . '</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}


$itemsStmt = $pdo->prepare('SELECT * FROM inv_requisition_items WHERE requisition_id = :id ORDER BY id');
$itemsStmt->execute(['id' => $id]);
$items = $itemsStmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3>ใบเบิก เลขที่ <?= htmlspecialchars($requisition['requisition_no']) ?></h3>
    <div>
        <a href="<?= INV_URL ?>requisitions.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> กลับไปหน้ารายการใบเบิก</a>
        <a href="<?= INV_URL ?>requisition_print.php?id=<?= (int) $requisition['id'] ?>" target="_blank" class="btn btn-primary"><i class="bi bi-printer"></i> พิมพ์ใบเบิก</a>
        <?php if (isAdmin()): ?>
            <?php if ($requisition['approved_at']): ?>
                <button type="button" class="btn btn-outline-danger" disabled title="ใบเบิกนี้อนุมัติแล้ว ไม่สามารถยกเลิกได้">
                    <i class="bi bi-x-circle"></i> ยกเลิกใบเบิก
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-outline-success" onclick="approveRequisitionView(<?= (int) $requisition['id'] ?>, '<?= htmlspecialchars($requisition['requisition_no'], ENT_QUOTES) ?>')">
                    <i class="bi bi-check-circle"></i> อนุมัติใบเบิก
                </button>
                <button type="button" class="btn btn-outline-danger" onclick="cancelRequisitionView(<?= (int) $requisition['id'] ?>, '<?= htmlspecialchars($requisition['requisition_no'], ENT_QUOTES) ?>')">
                    <i class="bi bi-x-circle"></i> ยกเลิกใบเบิก
                </button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($requisition['approved_at']): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle"></i> อนุมัติแล้วโดย <?= htmlspecialchars($requisition['approved_by_name'] ?? '-') ?>
        เมื่อ <?= htmlspecialchars(formatThaiDateTime($requisition['approved_at'])) ?>
    </div>
<?php else: ?>
    <div class="alert alert-secondary">
        <i class="bi bi-hourglass-split"></i> รออนุมัติ
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="text-muted small">เลขที่ใบเบิก</div>
                <div class="fw-bold"><?= htmlspecialchars($requisition['requisition_no']) ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">วันที่</div>
                <div><?= htmlspecialchars(formatThaiDateTime($requisition['created_at'])) ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">ผู้เบิก</div>
                <div><?= htmlspecialchars($requisition['requester_name'] ?: '-') ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">ตำแหน่ง</div>
                <div><?= htmlspecialchars($requisition['requester_position'] ?: '-') ?></div>
            </div>
            <div class="col-md-6">
                <div class="text-muted small">ใช้งานเพื่อ</div>
                <div><?= htmlspecialchars($requisition['purpose'] ?: '-') ?></div>
            </div>
            <div class="col-md-6">
                <div class="text-muted small">ผู้บันทึก (ผู้จ่าย)</div>
                <div><?= htmlspecialchars($requisition['created_by_name']) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-striped align-middle">
        <thead>
        <tr>
            <th>ลำดับที่</th><th>รายการ</th><th>หน่วยนับ</th><th>จำนวนเบิก</th><th>จำนวนจ่าย</th><th>หมายเหตุ</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $i => $item): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($item['item_name']) ?></td>
                <td><?= htmlspecialchars($item['unit'] ?? '-') ?></td>
                <td><?= (int) $item['quantity_requested'] ?></td>
                <td><?= (int) $item['quantity_issued'] ?></td>
                <td><?= htmlspecialchars($item['note'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?>
            <tr><td colspan="6" class="text-center text-muted">ไม่มีรายการ</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (isAdmin()): ?>
<script>
// Same destructive action as the "ยกเลิก" button on the ใบเบิก list page
// (assets/js/requisitions.js) - deletes the requisition and restores stock -
// just redirects back to the list afterwards since this requisition won't
// exist anymore for this page to show.
function cancelRequisitionView(id, requisitionNo) {
    if (!confirm(`ยืนยันยกเลิกใบเบิกเลขที่ ${requisitionNo}?\n\nระบบจะลบใบเบิกนี้ออกอย่างถาวร และคืนจำนวนวัสดุที่เบิกไปทั้งหมดกลับเข้าสต๊อก ไม่สามารถย้อนกลับได้`)) {
        return;
    }
    $.post(INV_URL_JS + 'api/requisition_api.php', { action: 'cancel', id }, function (res) {
        alert(res.message);
        if (res.success) {
            window.location.href = INV_URL_JS + 'requisitions.php';
        }
    }, 'json').fail(function (xhr) {
        alert((xhr.responseJSON && xhr.responseJSON.message) || 'เกิดข้อผิดพลาดในการยกเลิกใบเบิก');
    });
}

// Same as the "อนุมัติ" button on the ใบเบิก list page - once approved,
// cancelRequisitionView() above is blocked (server-side too). Reloads the
// page afterwards so the alert box / buttons here reflect the new state.
function approveRequisitionView(id, requisitionNo) {
    if (!confirm(`ยืนยันอนุมัติใบเบิกเลขที่ ${requisitionNo}?\n\nหลังอนุมัติแล้วจะไม่สามารถยกเลิกใบเบิกนี้ได้อีก`)) {
        return;
    }
    $.post(INV_URL_JS + 'api/requisition_api.php', { action: 'approve', id }, function (res) {
        alert(res.message);
        if (res.success) {
            window.location.reload();
        }
    }, 'json').fail(function (xhr) {
        alert((xhr.responseJSON && xhr.responseJSON.message) || 'เกิดข้อผิดพลาดในการอนุมัติใบเบิก');
    });
}
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
