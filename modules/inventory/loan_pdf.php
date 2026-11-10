<?php
/**
 * ตรวจสอบใบยืมจากไฟล์ PDF (เรียกจากหน้า loan_form) — ไม่บันทึกไฟล์และไม่แก้ข้อมูลใด ๆ
 *   รับ: pages = JSON ข้อความจาก pdf.js · annots = ข้อความในตราประทับ e-office · loan_id = รายการยืมที่กำลังแก้ไข (ถ้ามี)
 *   คืน: ข้อมูลผู้ยืม ระยะเวลา และรายการพัสดุพร้อมผลจับคู่กับทะเบียนครุภัณฑ์
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/loan_pdf_lib.php';
require_module(INV_MODULE, 'admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    json_out(['error' => 'คำขอไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่'], 400);
}
$pages = json_decode((string)($_POST['pages'] ?? ''), true);
if (!is_array($pages) || !$pages) {
    json_out(['error' => 'ไม่พบข้อความในไฟล์ — ไฟล์อาจเป็นภาพสแกน ให้กรอกข้อมูลเอง'], 422);
}
$pdo = db();
$loanId = int_param('loan_id');
$annots = json_decode((string)($_POST['annots'] ?? '[]'), true);
$res = loan_pdf_parse($pages, is_array($annots) ? array_filter($annots, 'is_string') : []);

$users = $pdo->query('SELECT id, full_name FROM core_users')->fetchAll();
$res['borrower_id'] = loan_pdf_match_user($res['borrower'], $users);

$equipment = $pdo->query('SELECT e.id, e.asset_no, e.serial_number, e.status, e.brand, e.model, t.name AS type_name
    FROM inv_equipment e LEFT JOIN inv_equipment_types t ON t.id = e.type_id WHERE e.asset_no IS NOT NULL OR e.serial_number IS NOT NULL')->fetchAll();
$res['items'] = loan_pdf_match_items($res['items'], $equipment);

// สถานะของครุภัณฑ์ที่ตรง: ถูกยืมอยู่ (ใบอื่น) / สภาพไม่ปกติ
$busy = active_loans_by_equipment();
foreach ($res['items'] as &$it) {
    foreach ($it['candidates'] as &$c) {
        $b = $busy[(int)$c['id']] ?? null;
        $c['problem'] = eq_is_holding_type($c['type_name']) ? 'เป็นการถือครอง ไม่ใช่การยืม — กำหนดผู้ถือครองที่ข้อมูลครุภัณฑ์'
            : ($b && (int)$b['loan_id'] !== $loanId ? 'ถูกยืมอยู่ (' . $b['borrower_name'] . ' หนังสือ ' . loan_ref($b) . ')'
            : ($c['status'] !== 'normal' ? 'สภาพ "' . (EQ_STATUS[$c['status']][0] ?? $c['status']) . '" ให้ยืมไม่ได้' : ''));
        $c['label'] = trim(($c['type_name'] ?? '') . ' ' . trim($c['brand'] . ' ' . $c['model']));
    }
    unset($c);
}
unset($it);
json_out($res);
