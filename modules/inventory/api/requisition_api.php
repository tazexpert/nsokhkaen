<?php
/**
 * Requisition slips (ใบเบิกวัสดุ) - a single slip groups several materials
 * withdrawn in one visit, matching the office's paper form. Every line is
 * still recorded into material_transactions as before (with a
 * requisition_id back-reference) so existing reports keep working.
 *
 * The in-progress slip (draft) is kept in the PHP session, not just in
 * JavaScript memory. A phone's own QR scanner app (not our in-browser
 * camera, which many mobile browsers block on plain http://) opens each
 * scanned sticker's link as a brand-new page load of scan.php?qr=... -
 * a JS-only cart would be wiped out by that. Session storage survives
 * across those separate page loads as long as the login session does.
 */
require_once __DIR__ . '/../bootstrap.php';

$pdo = getDbConnection();
$action = $_REQUEST['action'] ?? '';

// Every action here needs a login - either the usual username+password one,
// or a 6-digit PIN entered on the scan page (public/scan_login.php), which
// logs the user in exactly the same way (see includes/auth.php). So a PIN
// is as good as a full login for all of these, cart/create included.
switch ($action) {
    case 'cart_get':
        requireLoginApi();
        jsonResponse(['success' => true, 'data' => getDraft()]);
        break;
    case 'cart_add_material':
        requireLoginApi();
        cartAddMaterial($pdo);
        break;
    case 'cart_remove':
        requireLoginApi();
        cartRemove();
        break;
    case 'cart_clear':
        requireLoginApi();
        clearDraft();
        jsonResponse(['success' => true, 'data' => getDraft()]);
        break;
    case 'create':
        $user = requireLoginApi();
        createRequisition($pdo, $user);
        break;
    case 'list':
        $user = requireLoginApi();
        listRequisitions($pdo, $user);
        break;
    case 'get':
        $user = requireLoginApi();
        getRequisition($pdo, $user);
        break;
    case 'cancel':
        $user = requireAdminApi();
        cancelRequisition($pdo, $user);
        break;
    case 'approve':
        $user = requireAdminApi();
        approveRequisition($pdo, $user);
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'ไม่พบคำสั่งที่ร้องขอ'], 400);
}

function getDraft(): array
{
    return $_SESSION['inv_requisition_draft'] ?? ['purpose' => '', 'items' => []];
}

function saveDraftMeta(): void
{
    $draft = getDraft();
    $draft['purpose'] = sanitizeString($_POST['purpose'] ?? $draft['purpose']);
    $_SESSION['inv_requisition_draft'] = $draft;
}

function clearDraft(): void
{
    unset($_SESSION['inv_requisition_draft']);
}

function cartAddMaterial(PDO $pdo): void
{
    $materialId = (int) ($_POST['material_id'] ?? 0);
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $note = sanitizeString($_POST['note'] ?? '');

    if (!$materialId || $quantity <= 0) {
        jsonResponse(['success' => false, 'message' => 'กรุณาระบุจำนวนที่ต้องการเบิกให้ถูกต้อง'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM inv_materials WHERE id = :id');
    $stmt->execute(['id' => $materialId]);
    $material = $stmt->fetch();
    if (!$material) {
        jsonResponse(['success' => false, 'message' => 'ไม่พบข้อมูลวัสดุ'], 404);
    }

    saveDraftMeta();
    $draft = getDraft();

    $alreadyInCart = 0;
    foreach ($draft['items'] as $item) {
        if ($item['item_type'] === 'material' && $item['id'] === $materialId) {
            $alreadyInCart += $item['quantity'];
        }
    }

    if ($alreadyInCart + $quantity > $material['stock_qty']) {
        jsonResponse(['success' => false, 'message' => "สต๊อก \"{$material['name']}\" คงเหลือไม่เพียงพอ (คงเหลือ {$material['stock_qty']} {$material['unit']}, อยู่ในใบเบิกแล้ว {$alreadyInCart})"], 422);
    }

    $draft['items'][] = [
        'item_type' => 'material',
        'id' => $materialId,
        'name' => $material['name'],
        'unit' => $material['unit'],
        'quantity' => $quantity,
        'note' => $note,
        'image_path' => $material['image_path'],
        'display' => "{$quantity} {$material['unit']}",
    ];
    $_SESSION['inv_requisition_draft'] = $draft;

    jsonResponse(['success' => true, 'data' => $draft]);
}

function cartRemove(): void
{
    $index = (int) ($_POST['index'] ?? -1);
    $draft = getDraft();

    if (!isset($draft['items'][$index])) {
        jsonResponse(['success' => false, 'message' => 'ไม่พบรายการที่ต้องการลบ'], 422);
    }

    array_splice($draft['items'], $index, 1);
    $_SESSION['inv_requisition_draft'] = $draft;

    jsonResponse(['success' => true, 'data' => $draft]);
}

function createRequisition(PDO $pdo, array $user): void
{
    saveDraftMeta();
    $draft = getDraft();
    $purpose = $draft['purpose'];
    $items = $draft['items'];

    if (count($items) === 0) {
        jsonResponse(['success' => false, 'message' => 'กรุณาสแกนหรือเพิ่มรายการอย่างน้อย 1 รายการ'], 422);
    }
    // Required on the scan page's form too (public/scan.php) - repeated
    // here since that field isn't inside a real <form>, so nothing stops a
    // direct API call from skipping it.
    if (trim($purpose ?? '') === '') {
        jsonResponse(['success' => false, 'message' => 'กรุณากรอกวัตถุประสงค์ที่ขอเบิกวัสดุเพื่อใช้งาน'], 422);
    }

    // ผู้เบิก is always the logged-in account making the requisition (not a
    // free-typed name) - the printed/viewed slip already pulls name/position
    // straight from the users table, so there's no separate field to fill in.
    $stmt = $pdo->prepare('SELECT full_name, position FROM core_users WHERE id = :id');
    $stmt->execute(['id' => $user['id']]);
    $requester = $stmt->fetch();

    try {
        $pdo->beginTransaction();

        $requisitionNo = generateRequisitionNo($pdo);
        $insertReq = $pdo->prepare('INSERT INTO inv_requisitions (requisition_no, purpose, requester_name, requester_position, created_by)
            VALUES (:no, :purpose, :requester_name, :requester_position, :created_by)');
        $insertReq->execute([
            'no' => $requisitionNo,
            'purpose' => $purpose ?: null,
            'requester_name' => $requester['full_name'] ?? null,
            'requester_position' => $requester['position'] ?? null,
            'created_by' => $user['id'],
        ]);
        $requisitionId = (int) $pdo->lastInsertId();

        $insertItem = $pdo->prepare('INSERT INTO inv_requisition_items
            (requisition_id, item_type, material_id, item_name, unit, quantity_requested, quantity_issued, note)
            VALUES (:requisition_id, :item_type, :material_id, :item_name, :unit, :qty_requested, :qty_issued, :note)');

        $insertMaterialTx = $pdo->prepare("INSERT INTO inv_material_transactions
            (material_id, user_id, requisition_id, transaction_type, quantity, balance_after, note)
            VALUES (:material_id, :user_id, :requisition_id, 'withdraw', :quantity, :balance_after, :note)");

        foreach ($items as $item) {
            $itemType = $item['item_type'] ?? '';
            $note = sanitizeString($item['note'] ?? '');

            if ($itemType !== 'material') {
                throw new InvalidArgumentException('ประเภทรายการไม่ถูกต้อง');
            }

            $materialId = (int) ($item['id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if (!$materialId || $quantity <= 0) {
                throw new InvalidArgumentException('ข้อมูลวัสดุในรายการไม่ถูกต้อง');
            }

            $stmt = $pdo->prepare('SELECT * FROM inv_materials WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $materialId]);
            $material = $stmt->fetch();

            if (!$material) {
                throw new InvalidArgumentException('ไม่พบข้อมูลวัสดุรายการหนึ่งในใบเบิก');
            }
            if ($material['stock_qty'] < $quantity) {
                throw new InvalidArgumentException("สต๊อก \"{$material['name']}\" คงเหลือไม่เพียงพอ (คงเหลือ {$material['stock_qty']} {$material['unit']})");
            }

            $newBalance = $material['stock_qty'] - $quantity;
            $pdo->prepare('UPDATE inv_materials SET stock_qty = :qty WHERE id = :id')
                ->execute(['qty' => $newBalance, 'id' => $materialId]);

            $insertMaterialTx->execute([
                'material_id' => $materialId,
                'user_id' => $user['id'],
                'requisition_id' => $requisitionId,
                'quantity' => $quantity,
                'balance_after' => $newBalance,
                'note' => $note ?: null,
            ]);

            $insertItem->execute([
                'requisition_id' => $requisitionId,
                'item_type' => 'material',
                'material_id' => $materialId,
                'item_name' => $material['name'],
                'unit' => $material['unit'],
                'qty_requested' => $quantity,
                'qty_issued' => $quantity,
                'note' => $note ?: null,
            ]);
        }

        $pdo->commit();
        clearDraft();

        jsonResponse([
            'success' => true,
            'message' => "บันทึกใบเบิกเลขที่ {$requisitionNo} เรียบร้อยแล้ว",
            'requisition_id' => $requisitionId,
            'requisition_no' => $requisitionNo,
        ]);
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึกใบเบิก'], 500);
    }
}

// Admin only. Cancelling a requisition fully deletes it (and its line
// items, via ON DELETE CASCADE) and restores every withdrawn quantity back
// to materials.stock_qty - there's no "cancelled" status to keep around,
// this is meant to undo a requisition as if it never happened. The
// original 'withdraw' material_transactions rows are deleted too (not just
// left with requisition_id nulled out), otherwise reports.php would still
// count a cancelled requisition's quantities as withdrawn even though the
// stock was given back.
function cancelRequisition(PDO $pdo, array $user): void
{
    $id = (int) ($_POST['id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM inv_requisitions WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $requisition = $stmt->fetch();

    if (!$requisition) {
        jsonResponse(['success' => false, 'message' => 'ไม่พบใบเบิก'], 404);
    }

    if ($requisition['approved_at']) {
        jsonResponse(['success' => false, 'message' => 'ใบเบิกนี้อนุมัติแล้ว ไม่สามารถยกเลิกได้'], 422);
    }

    try {
        $pdo->beginTransaction();

        $itemsStmt = $pdo->prepare('SELECT * FROM inv_requisition_items WHERE requisition_id = :id');
        $itemsStmt->execute(['id' => $id]);
        $items = $itemsStmt->fetchAll();

        foreach ($items as $item) {
            if ($item['item_type'] !== 'material' || !$item['material_id']) {
                continue;
            }

            // Lock the row so a concurrent withdrawal can't read a stale
            // stock_qty while we're adding the cancelled quantity back.
            $pdo->prepare('SELECT id FROM inv_materials WHERE id = :id FOR UPDATE')
                ->execute(['id' => $item['material_id']]);

            $pdo->prepare('UPDATE inv_materials SET stock_qty = stock_qty + :qty WHERE id = :id')
                ->execute(['qty' => (int) $item['quantity_issued'], 'id' => $item['material_id']]);
        }

        $pdo->prepare("DELETE FROM inv_material_transactions WHERE requisition_id = :id AND transaction_type = 'withdraw'")
            ->execute(['id' => $id]);

        $pdo->prepare('DELETE FROM inv_requisitions WHERE id = :id')->execute(['id' => $id]);

        $pdo->commit();

        jsonResponse(['success' => true, 'message' => "ยกเลิกใบเบิกเลขที่ {$requisition['requisition_no']} และคืนจำนวนวัสดุเรียบร้อยแล้ว"]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการยกเลิกใบเบิก'], 500);
    }
}

// Admin only. A no-op audit mark, not a stock-affecting action (stock is
// already deducted at creation time, same as before this feature) - it
// just records who signed off on the requisition and, from that point on,
// blocks cancelRequisition() above from undoing it.
function approveRequisition(PDO $pdo, array $user): void
{
    $id = (int) ($_POST['id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM inv_requisitions WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $requisition = $stmt->fetch();

    if (!$requisition) {
        jsonResponse(['success' => false, 'message' => 'ไม่พบใบเบิก'], 404);
    }

    if ($requisition['approved_at']) {
        jsonResponse(['success' => false, 'message' => 'ใบเบิกนี้อนุมัติไปแล้ว'], 422);
    }

    $pdo->prepare('UPDATE inv_requisitions SET approved_by = :approved_by, approved_at = NOW() WHERE id = :id')
        ->execute(['approved_by' => $user['id'], 'id' => $id]);

    jsonResponse(['success' => true, 'message' => "อนุมัติใบเบิกเลขที่ {$requisition['requisition_no']} เรียบร้อยแล้ว"]);
}

// Staff only ever see their own requisitions (the ones they personally
// created/submitted); admin sees everything.
function listRequisitions(PDO $pdo, array $user): void
{
    $keyword = sanitizeString($_GET['keyword'] ?? '');
    $startDate = sanitizeString($_GET['start_date'] ?? '');
    $endDate = sanitizeString($_GET['end_date'] ?? '');

    $sql = "SELECT r.*, u.full_name AS created_by_name, au.full_name AS approved_by_name,
                (SELECT COUNT(*) FROM inv_requisition_items ri WHERE ri.requisition_id = r.id) AS item_count
            FROM inv_requisitions r
            JOIN core_users u ON u.id = r.created_by
            LEFT JOIN core_users au ON au.id = r.approved_by
            WHERE 1=1";
    $params = [];

    if ($user['role'] !== 'admin') {
        $sql .= ' AND r.created_by = :uid';
        $params['uid'] = $user['id'];
    }

    if ($keyword !== '') {
        $sql .= ' AND (r.requisition_no LIKE :kw1 OR r.requester_name LIKE :kw2 OR r.purpose LIKE :kw3)';
        $params['kw1'] = "%$keyword%";
        $params['kw2'] = "%$keyword%";
        $params['kw3'] = "%$keyword%";
    }
    if ($startDate !== '') {
        $sql .= ' AND r.created_at >= :start_date';
        $params['start_date'] = $startDate . ' 00:00:00';
    }
    if ($endDate !== '') {
        $sql .= ' AND r.created_at <= :end_date';
        $params['end_date'] = $endDate . ' 23:59:59';
    }

    $sql .= ' ORDER BY r.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

function getRequisition(PDO $pdo, array $user): void
{
    $id = (int) ($_GET['id'] ?? 0);

    $stmt = $pdo->prepare('SELECT r.*, u.full_name AS created_by_name, au.full_name AS approved_by_name
        FROM inv_requisitions r
        JOIN core_users u ON u.id = r.created_by
        LEFT JOIN core_users au ON au.id = r.approved_by
        WHERE r.id = :id');
    $stmt->execute(['id' => $id]);
    $requisition = $stmt->fetch();

    if (!$requisition) {
        jsonResponse(['success' => false, 'message' => 'ไม่พบใบเบิก'], 404);
    }

    if ($user['role'] !== 'admin' && (int) $requisition['created_by'] !== (int) $user['id']) {
        jsonResponse(['success' => false, 'message' => 'คุณไม่มีสิทธิ์เข้าถึงใบเบิกนี้'], 403);
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM inv_requisition_items WHERE requisition_id = :id ORDER BY id');
    $itemsStmt->execute(['id' => $id]);
    $requisition['items'] = $itemsStmt->fetchAll();

    jsonResponse(['success' => true, 'data' => $requisition]);
}
