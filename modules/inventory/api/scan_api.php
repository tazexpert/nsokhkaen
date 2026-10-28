<?php
/**
 * Backend for the QR Code scan workflow.
 * Handles: lookup (identify material by QR), withdraw (material).
 * Every transaction records the acting user and timestamp.
 */
require_once __DIR__ . '/../bootstrap.php';

$user = requireLoginApi();
$pdo = getDbConnection();
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'lookup':
        lookupQrCode($pdo);
        break;
    case 'withdraw':
        withdrawMaterial($pdo, $user);
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'ไม่พบคำสั่งที่ร้องขอ'], 400);
}

/**
 * Step 1: identify the material the scanned QR code belongs to - also
 * doubles as a manual search by name/code, since the same box ("กรอกรหัส
 * QR ด้วยตนเอง") is used for both. Resolution order:
 *   1. Exact QR code match (what an actual scan sends).
 *   2. Otherwise, search by name/code - if exactly one material matches,
 *      resolve straight to it like a scan would; if several match, return
 *      the list for the user to pick from.
 */
function lookupQrCode(PDO $pdo): void
{
    $qr = sanitizeString($_GET['qr_code'] ?? $_POST['qr_code'] ?? '');
    if ($qr === '') {
        jsonResponse(['success' => false, 'message' => 'ไม่พบข้อมูล QR Code'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM inv_materials WHERE qr_code = :qr');
    $stmt->execute(['qr' => $qr]);
    $material = $stmt->fetch();

    if ($material) {
        jsonResponse(['success' => true, 'item_type' => 'material', 'data' => $material]);
    }

    // Two distinct placeholders: PDO with ATTR_EMULATE_PREPARES=false (native
    // prepared statements) does not allow the same named parameter twice.
    $stmt = $pdo->prepare('SELECT * FROM inv_materials WHERE name LIKE :kw1 OR material_code LIKE :kw2 ORDER BY name LIMIT 20');
    $stmt->execute(['kw1' => "%$qr%", 'kw2' => "%$qr%"]);
    $matches = $stmt->fetchAll();

    if (count($matches) === 1) {
        jsonResponse(['success' => true, 'item_type' => 'material', 'data' => $matches[0]]);
    }
    if (count($matches) > 1) {
        jsonResponse(['success' => true, 'item_type' => 'search_results', 'data' => $matches]);
    }

    jsonResponse(['success' => false, 'message' => 'ไม่พบรายการที่ตรงกับ QR Code หรือชื่อวัสดุนี้ในระบบ'], 404);
}

/**
 * Step 2: withdraw a quantity from stock and record the transaction.
 * Uses SELECT ... FOR UPDATE inside a DB transaction to avoid race conditions
 * when multiple staff scan the same item concurrently.
 */
function withdrawMaterial(PDO $pdo, array $user): void
{
    $materialId = (int) ($_POST['material_id'] ?? 0);
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $note = sanitizeString($_POST['note'] ?? '');

    if (!$materialId || $quantity <= 0) {
        jsonResponse(['success' => false, 'message' => 'กรุณาระบุจำนวนที่ต้องการเบิกให้ถูกต้อง'], 422);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM inv_materials WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $materialId]);
        $material = $stmt->fetch();

        if (!$material) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'ไม่พบข้อมูลวัสดุ'], 404);
        }

        if ($material['stock_qty'] < $quantity) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => "สต๊อกคงเหลือไม่เพียงพอ (คงเหลือ {$material['stock_qty']} {$material['unit']})"], 422);
        }

        $newBalance = $material['stock_qty'] - $quantity;

        $update = $pdo->prepare('UPDATE inv_materials SET stock_qty = :qty WHERE id = :id');
        $update->execute(['qty' => $newBalance, 'id' => $materialId]);

        $insert = $pdo->prepare("INSERT INTO inv_material_transactions
            (material_id, user_id, transaction_type, quantity, balance_after, note)
            VALUES (:material_id, :user_id, 'withdraw', :quantity, :balance_after, :note)");
        $insert->execute([
            'material_id' => $materialId,
            'user_id' => $user['id'],
            'quantity' => $quantity,
            'balance_after' => $newBalance,
            'note' => $note ?: null,
        ]);

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'message' => "เบิก {$material['name']} จำนวน {$quantity} {$material['unit']} เรียบร้อยแล้ว",
            'balance_after' => $newBalance,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล'], 500);
    }
}
