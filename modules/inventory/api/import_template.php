<?php
// Generates a blank Excel template with the exact column headers import_api.php expects.
require_once __DIR__ . '/../bootstrap.php';

requireAdminApi();

use Shuchkin\SimpleXLSXGen;

// category_code (รหัสหมวดวัสดุ ตามระบบของสำนักงาน เช่น 14111500) กำหนด
// ทั้งหมวดหมู่และรหัสวัสดุ (material_code = category_code + ลำดับ 2 หลัก)
$rows = [
    ['name', 'category_code', 'unit', 'unit_cost', 'stock_qty', 'min_stock', 'storage_location', 'note'],
    ['ตัวอย่าง: ปากกาลูกลื่น', '44121706', 'ด้าม', '5.00', '100', '10', 'ห้องพัสดุ ชั้น 1', ''],
];

SimpleXLSXGen::fromArray($rows, 'template')->downloadAs('material_import_template.xlsx');
