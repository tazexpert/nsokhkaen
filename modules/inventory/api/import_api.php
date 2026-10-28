<?php
require_once __DIR__ . '/../bootstrap.php';

requireAdminApi();
$pdo = getDbConnection();

use Shuchkin\SimpleXLSX;

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['success' => false, 'message' => 'กรุณาเลือกไฟล์ Excel ที่ต้องการนำเข้า'], 422);
}

$xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
if (!$xlsx) {
    jsonResponse(['success' => false, 'message' => 'ไม่สามารถอ่านไฟล์ Excel ได้: ' . SimpleXLSX::parseError()], 422);
}
$rows = $xlsx->rows();

if (count($rows) < 2) {
    jsonResponse(['success' => false, 'message' => 'ไฟล์ไม่มีข้อมูล'], 422);
}

/**
 * Three supported layouts for materials (all keyed to category_code, the
 * office's own รหัสหมวดวัสดุ - material_code and category are derived from
 * it; records with no category_code fall back to the generic MAT-0001
 * scheme and no category):
 *  1. Our own simple template (import_template.php): one header row with
 *     English column keys (name, category_code, unit, unit_cost, stock_qty, ...).
 *  2. The office's own "รายงานวัสดุคงเหลือ" stock report (ลำดับที่ / รหัสหมวดวัสดุ /
 *     รายการ / หน่วยนับ / ยอดตามบัญชี (จำนวน)) - detected by the distinctive
 *     "รหัสหมวดวัสดุ" header cell, since the real header spans several
 *     merged/sub-header rows above the data.
 *  3. The office's older "ใบสืบราคา/รายละเอียดพัสดุ" form: a multi-row merged
 *     header (ลำดับที่ / รายละเอียดของพัสดุ / ราคาที่ได้มาจากการสืบราคา(หน่วยละ) /
 *     จำนวน(หน่วย) / จำนวนเงิน), with each item row starting with its running
 *     number in column A. Detected by that running-number column instead of
 *     by header text, since the header spans several merged rows.
 */
function extractMaterialRecords(array $rows): array
{
    $header = array_map(fn($h) => strtolower(trim((string) $h)), $rows[0]);
    if (in_array('name', $header, true)) {
        $records = [];
        foreach (array_slice($rows, 1) as $row) {
            $record = array_combine($header, array_pad($row, count($header), null));
            if (trim((string) ($record['name'] ?? '')) === '') {
                continue;
            }
            $records[] = [
                'name' => trim((string) $record['name']),
                'category_code' => trim((string) ($record['category_code'] ?? '')) ?: null,
                'unit' => trim((string) ($record['unit'] ?? 'ชิ้น')) ?: 'ชิ้น',
                'unit_cost' => (float) ($record['unit_cost'] ?? 0),
                'stock_qty' => (int) ($record['stock_qty'] ?? 0),
                'min_stock' => (int) ($record['min_stock'] ?? 0),
                'storage_location' => trim((string) ($record['storage_location'] ?? '')) ?: null,
                'note' => trim((string) ($record['note'] ?? '')) ?: null,
            ];
        }
        return $records;
    }

    if (sheetHasHeaderCell($rows, 'รหัสหมวดวัสดุ')) {
        return extractStocktakeRecords($rows);
    }

    // Office form: column A = running number, B = item name, D = unit price,
    // E = quantity being purchased/received into stock.
    $records = [];
    foreach ($rows as $row) {
        $no = $row[0] ?? null;
        $name = trim((string) ($row[1] ?? ''));
        if ($name === '' || !is_numeric($no) || (int) $no <= 0) {
            continue;
        }
        $records[] = [
            'name' => $name,
            'category_code' => null,
            'unit' => 'ชิ้น',
            'unit_cost' => (float) ($row[3] ?? 0),
            'stock_qty' => (int) ($row[4] ?? 0),
            'min_stock' => 0,
            'storage_location' => null,
            'note' => null,
        ];
    }
    return $records;
}

// Looks for $needle as a trimmed cell value anywhere in the first few rows
// (the real header of these office forms spans several merged/sub-header
// rows, so checking only rows[0] isn't enough).
function sheetHasHeaderCell(array $rows, string $needle): bool
{
    foreach (array_slice($rows, 0, 8) as $row) {
        foreach ($row as $cell) {
            if (trim((string) $cell) === $needle) {
                return true;
            }
        }
    }
    return false;
}

// รายงานวัสดุคงเหลือ (office stock report): column A = running number,
// B = รหัสหมวดวัสดุ, C = รายการ, D = หน่วยนับ, E = ยอดตามบัญชี (จำนวนคงเหลือ).
function extractStocktakeRecords(array $rows): array
{
    $records = [];
    foreach ($rows as $row) {
        $no = $row[0] ?? null;
        $name = trim((string) ($row[2] ?? ''));
        if ($name === '' || !is_numeric($no) || (int) $no <= 0) {
            continue;
        }
        $categoryCode = trim((string) ($row[1] ?? ''));
        $records[] = [
            'name' => $name,
            'category_code' => $categoryCode !== '' ? $categoryCode : null,
            'unit' => trim((string) ($row[3] ?? '')) ?: 'ชิ้น',
            'unit_cost' => 0.0,
            'stock_qty' => is_numeric($row[4] ?? null) ? (int) $row[4] : 0,
            'min_stock' => 0,
            'storage_location' => null,
            'note' => null,
        ];
    }
    return $records;
}

$imported = 0;
$skipped = 0;

$pdo->beginTransaction();
try {
    $records = extractMaterialRecords($rows);

    foreach ($records as $record) {
        $code = generateNextMaterialCode($pdo, $record['category_code'] ?? null);
        $qr = generateQrPayload($code);
        $categoryId = resolveMaterialCategoryId($pdo, $record['category_code'] ?? null);

        // category_code only comes from the stocktake-report format;
        // the other two formats leave it null.
        $stmt = $pdo->prepare("INSERT INTO inv_materials
            (material_code, qr_code, name, category_id, category_code, unit, unit_cost,
             stock_qty, min_stock, storage_location, note, created_by)
            VALUES (:code, :qr, :name, :category_id, :category_code, :unit, :unit_cost,
             :stock_qty, :min_stock, :location, :note, :created_by)");
        $stmt->execute([
            'code' => $code, 'qr' => $qr, 'name' => $record['name'], 'category_id' => $categoryId,
            'category_code' => $record['category_code'] ?? null,
            'unit' => $record['unit'], 'unit_cost' => $record['unit_cost'], 'stock_qty' => $record['stock_qty'],
            'min_stock' => $record['min_stock'], 'location' => $record['storage_location'], 'note' => $record['note'],
            'created_by' => currentUser()['id'],
        ]);
        $imported++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'message' => 'เกิดข้อผิดพลาดระหว่างนำเข้าข้อมูล: ' . $e->getMessage()], 500);
}

jsonResponse(['success' => true, 'message' => "นำเข้าข้อมูลสำเร็จ {$imported} รายการ (ข้าม {$skipped} รายการที่ไม่มีชื่อ)"]);
