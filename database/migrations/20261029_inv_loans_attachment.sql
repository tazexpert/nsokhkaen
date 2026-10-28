-- ============================================================================
-- ปรับโครงสร้าง 2026-10-29: การยืมครุภัณฑ์ทำผ่าน e-office — ระบบนี้แนบไฟล์ใบยืมและติดตามสถานะ
-- ใช้กับฐานข้อมูลที่รัน 20261028_core_workgroup_inv_equipment.sql ไปแล้ว (รันซ้ำได้)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261029_inv_loans_attachment.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE inv_loans
  ADD COLUMN IF NOT EXISTS doc_no    VARCHAR(100) NULL AFTER borrower_phone,
  ADD COLUMN IF NOT EXISTS file_path VARCHAR(255) NULL AFTER doc_no,
  ADD COLUMN IF NOT EXISTS file_name VARCHAR(255) NULL AFTER file_path,
  MODIFY purpose VARCHAR(500) NULL;

-- ค่าตั้งค่าผู้ลงนามในใบยืมไม่ใช้แล้ว (พิมพ์จากไฟล์ที่แนบแทน)
DELETE FROM inv_settings WHERE `key` IN ('loan_lender_name', 'loan_lender_position', 'loan_approver_name',
  'loan_approver_position', 'loan_org_phone', 'loan_org_fax', 'loan_to', 'low_stock_default');
