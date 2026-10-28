<?php
require_once __DIR__ . '/../bootstrap.php';

$user = requireLoginApi();
$pdo = getDbConnection();
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'list':
        listStorageLocations($pdo);
        break;
    case 'create':
        requireAdminApi();
        createStorageLocation($pdo);
        break;
    case 'delete':
        requireAdminApi();
        deleteStorageLocation($pdo);
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'ไม่พบคำสั่งที่ร้องขอ'], 400);
}

function listStorageLocations(PDO $pdo): void
{
    $rows = $pdo->query('SELECT * FROM inv_storage_locations ORDER BY name')->fetchAll();
    jsonResponse(['success' => true, 'data' => $rows]);
}

function createStorageLocation(PDO $pdo): void
{
    $name = sanitizeString($_POST['name'] ?? '');
    if ($name === '') {
        jsonResponse(['success' => false, 'message' => 'กรุณาระบุชื่อสถานที่จัดเก็บ'], 422);
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO inv_storage_locations (name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            jsonResponse(['success' => false, 'message' => 'มีสถานที่จัดเก็บนี้อยู่แล้ว'], 422);
        }
        throw $e;
    }

    jsonResponse(['success' => true, 'message' => 'เพิ่มสถานที่จัดเก็บเรียบร้อยแล้ว', 'id' => $pdo->lastInsertId()]);
}

function deleteStorageLocation(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง'], 422);
    }
    $stmt = $pdo->prepare('DELETE FROM inv_storage_locations WHERE id = :id');
    $stmt->execute(['id' => $id]);
    jsonResponse(['success' => true, 'message' => 'ลบสถานที่จัดเก็บเรียบร้อยแล้ว']);
}
